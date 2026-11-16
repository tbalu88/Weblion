/* Crazy panel – Raktár (v2.9.259): cikkek (fogyóanyag), egyedi eszközök sorozatszámmal, készlet helyenként, mozgások.
 * Adat: cp_wh_data (minden egyben), cp_wh_item / cp_wh_unit (mentés), cp_wh_move (mozgás), cp_wh_moves (napló), cp_wh_locs, cp_wh_dealq. */
(function () {
	'use strict';
	const C = window.CPX || {};
	if (!C.wh) return;
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const S = { main: null, d: null, sub: 'stock', q: '', cat: '', err: '', moves: null, mf: null };
	try { const o = JSON.parse(localStorage.getItem('cpx_wh') || '{}'); if (o.sub) S.sub = o.sub; } catch (e) { /* privát mód */ }
	const keep = () => { try { localStorage.setItem('cpx_wh', JSON.stringify({ sub: S.sub })); } catch (e) { /* privát mód */ } };
	async function post(action, params) {
		const fd = new FormData(); fd.append('action', action); fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k] == null ? '' : params[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j = null; try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j && j.data && j.data.msg) || 'Hiba'));
		return j.data;
	}
	function toast(t, bad) {
		let d = document.getElementById('cpx-toast');
		if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); }
		d.textContent = t; d.classList.toggle('bad', !!bad); d.classList.add('on'); clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), bad ? 6000 : 2600);
	}
	const nf = n => { const v = Math.round(Number(n || 0) * 100) / 100; return v.toLocaleString('hu-HU', { maximumFractionDigits: 2 }); };
	const ft = n => Number(n || 0).toLocaleString('hu-HU') + ' Ft';
	const huD = s => (s ? String(s).slice(0, 10).replace(/-/g, '.') + '.' : '');
	const SUBS = [['stock', 'Készlet'], ['assets', 'Eszközök'], ['kits', 'Csomagok'], ['moves', 'Mozgások']];

	function status(s) { if (!s) return; s.textContent = S.err || ''; s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function load() {
		try { S.d = await post('cp_wh_data', {}); S.err = ''; } catch (e) { S.err = e.message; }
		draw(); deepOpen();
	}
	function view(main) {
		css(); S.main = main;
		main.innerHTML = '<div class="rk"><div class="rk-h"><div><h2>Raktár</h2><p class="rk-lead">Fogyóanyag és eszközök: mi van, hol van, mi ment ki melyik rendezvényre.</p></div>' +
			'<div class="rk-ha"><button type="button" class="cpx-btn sm" data-rnew="stock">+ Új cikk</button><button type="button" class="cpx-btn ghost sm" data-rnew="asset">+ Új eszköz</button><button type="button" class="cpx-btn ghost sm" data-rscan aria-label="QR-kód beolvasása kamerával">📷 Beolvasás</button><button type="button" class="cpx-btn ghost sm" data-rlocs>Helyek</button></div></div>' +
			'<div class="rk-tb"><div class="rk-seg" role="tablist" aria-label="Nézet">' + SUBS.map(s => '<button type="button" role="tab" data-rsub="' + s[0] + '" aria-selected="' + (S.sub === s[0]) + '">' + s[1] + '</button>').join('') + '</div>' +
			'<input type="search" data-rq placeholder="Keresés: név, cikkszám, sorozatszám, hely…" aria-label="Keresés a raktárban" value="' + esc(S.q) + '"><select data-rcat aria-label="Kategória"><option value="">Minden kategória</option></select></div>' +
			'<div data-rbody><div class="cpx-empty">Betöltés…</div></div></div>';
		let qt = 0;
		main.querySelector('[data-rq]').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { S.q = e.target.value.trim().toLowerCase(); draw(); }, 150); });
		main.querySelector('[data-rcat]').addEventListener('change', e => { S.cat = e.target.value; draw(); });
		main.querySelectorAll('[data-rsub]').forEach(b => b.addEventListener('click', () => { S.sub = b.dataset.rsub; keep(); main.querySelectorAll('[data-rsub]').forEach(x => x.setAttribute('aria-selected', String(x === b))); draw(); }));
		main.querySelectorAll('[data-rnew]').forEach(b => b.addEventListener('click', () => b.dataset.rnew === 'asset' ? addAssets(null) : itemForm(null, 'stock')));
		main.querySelector('[data-rlocs]').addEventListener('click', locsForm);
		main.querySelector('[data-rscan]').addEventListener('click', scan);
		main.querySelector('[data-rbody]').addEventListener('click', act);
		if (S.d) draw();
		load();
	}
	const item = id => (S.d.items || []).find(i => i.id === Number(id));
	const unit = id => (S.d.units || []).find(u => u.id === Number(id));
	/* készlet = ami nálunk van (a „Rendezvényen” lévő mennyiség kint van / elfogyott, nem számít bele) */
	const total = id => Object.entries((S.d.stock || {})[id] || {}).filter(e => e[0] !== S.d.ev).reduce((a, e) => a + e[1], 0);
	const hit = (...v) => !S.q || v.some(x => String(x || '').toLowerCase().includes(S.q));
	function draw() {
		const m = S.main; if (!m || !m.isConnected) return;
		const body = m.querySelector('[data-rbody]');
		if (!S.d) { body.innerHTML = S.err ? '<div class="cpx-empty err">' + esc(S.err) + '</div>' : '<div class="cpx-empty">Betöltés…</div>'; return; }
		const cs = m.querySelector('[data-rcat]'), cats = Array.from(new Set((S.d.items || []).map(i => i.cat).filter(Boolean))).sort((a, b) => a.localeCompare(b, 'hu'));
		cs.innerHTML = '<option value="">Minden kategória</option>' + cats.map(c => '<option' + (c === S.cat ? ' selected' : '') + '>' + esc(c) + '</option>').join('');
		if (S.sub === 'kits') body.innerHTML = drawKits(); else if (S.sub === 'assets') body.innerHTML = drawAssets(); else if (S.sub === 'moves') { body.innerHTML = '<div class="cpx-empty">Betöltés…</div>'; drawMoves(body); } else body.innerHTML = drawStock();
	}
	/* ---- Készlet: fogyóanyag */
	function drawStock() {
		const L = (S.d.items || []).filter(i => i.kind === 'stock' && (!S.cat || i.cat === S.cat) && hit(i.name, i.sku, i.supplier, i.cat, Object.keys((S.d.stock || {})[i.id] || {}).join(' ')));
		if (!(S.d.items || []).some(i => i.kind === 'stock')) return '<div class="rk-empty"><p><b>Még nincs fogyóanyag.</b> Vedd fel, amit rendezvényre viszel és fogy: DNP-papír, festékszalag, kellékek, elemek.</p><button type="button" class="cpx-btn sm" data-rnew2="stock">+ Első cikk felvétele</button></div>';
		if (!L.length) return '<p class="cpx-empty">Nincs találat.</p>';
		return '<table class="rk-t"><thead><tr><th>Cikk</th><th class="rk-n">Készlet</th><th>Hol</th><th class="rk-n">Minimum</th><th class="rk-a"><span class="rk-sr">Műveletek</span></th></tr></thead><tbody>' + L.map(i => {
			const t = total(i.id), low = i.min_qty > 0 && t <= i.min_qty, out = t <= 0, loc = Object.entries((S.d.stock || {})[i.id] || {}).filter(e => e[1]).sort((a, b) => (a[0] === S.d.ev) - (b[0] === S.d.ev));
			return '<tr' + (i.active ? '' : ' class="rk-off"') + '><td><button type="button" class="rk-nm" data-ritem="' + i.id + '">' + esc(i.name) + '</button><small>' + esc([i.cat, i.sku].filter(Boolean).join(' · ')) + '</small></td>' +
				'<td class="rk-n"><b>' + nf(t) + '</b> ' + esc(i.unit) + (out ? ' <span class="rk-chip bad">elfogyott</span>' : low ? ' <span class="rk-chip mid">kevés</span>' : '') + '</td>' +
				'<td class="rk-loc">' + (loc.length ? loc.map(e => '<span>' + esc(e[0]) + ': ' + nf(e[1]) + '</span>').join('') : '<span class="cpx-muted">–</span>') + '</td>' +
				'<td class="rk-n" data-l="Minimum">' + (i.min_qty ? nf(i.min_qty) : '–') + '</td>' +
				'<td class="rk-a"><button type="button" class="rk-lnk" data-ritem="' + i.id + '">✎ Szerkesztés</button><button type="button" class="rk-lnk" data-rmv="in" data-rid="' + i.id + '">Beérkezés</button><button type="button" class="rk-lnk" data-rmv="out" data-rid="' + i.id + '">Kiadás</button><button type="button" class="rk-lnk" data-rmore="' + i.id + '" aria-label="További műveletek: ' + esc(i.name) + '">Több…</button></td></tr>';
		}).join('') + '</tbody></table>';
	}
	/* ---- Eszközök: típusonként a darabok */
	function drawAssets() {
		const types = (S.d.items || []).filter(i => i.kind === 'asset' && (!S.cat || i.cat === S.cat)).sort((a, b) => (a.cat || 'zzz').localeCompare(b.cat || 'zzz', 'hu') || a.name.localeCompare(b.name, 'hu'));
		let lastCat = null; /* v2.9.275: kategóriánként csoportosítva (Kamera, Nyomtató, Játék…) */
		if (!types.length) return '<div class="rk-empty"><p><b>Még nincs eszköz.</b> Vedd fel a gépeket, kamerákat, nyomtatókat sorozatszámmal – így látszik, melyik hol van, és melyik rendezvényen volt.</p><button type="button" class="cpx-btn sm" data-rnew2="asset">+ Első eszköz felvétele</button></div>';
		const ST = S.d.status || {}, D = S.d.deals || {};
		const bulk = (S.d.units || []).length ? '<div class="rk-bar"><button type="button" class="cpx-btn ghost sm" data-rqrall>QR-címkék nyomtatása…</button><span>A kód telefonnal beolvasva a panelben ezt az eszközt nyitja meg.</span></div>' : '';
		const out = types.map(t => {
			const U = (S.d.units || []).filter(u => u.item_id === t.id && hit(t.name, u.label, u.serial, u.model, u.loc, u.qr));
			if (!U.length && S.q) return '';
			const ok = U.filter(u => u.status === 'ok').length;
			const ch = t.cat !== lastCat ? '<h2 class="rk-cat">' + esc(t.cat || 'Kategória nélkül') + '</h2>' : ''; lastCat = t.cat;
			return ch + '<section class="rk-g"><h3><span class="rk-gn">' + esc(t.name) + '</span><small>' + U.length + ' db' + (U.length !== ok ? ' · ' + ok + ' használható' : '') + '</small><span class="rk-ga"><button type="button" class="rk-lnk" data-raddu="' + t.id + '">+ Darab</button><button type="button" class="rk-lnk" data-ritem="' + t.id + '">✎ Szerkesztés</button></span></h3>' +
				(U.length ? '<table class="rk-t"><thead><tr><th>Darab</th><th>Sorozatszám</th><th>Hol</th><th>Állapot</th><th class="rk-a"><span class="rk-sr">Műveletek</span></th></tr></thead><tbody>' + U.map(u => {
					const svc = u.svc_limit > 0 && u.counter - u.svc_base >= u.svc_limit;
					const sn = u.label && u.label.indexOf(t.name + ' ') === 0 ? u.label.slice(t.name.length + 1) : (u.label || '#' + u.id); /* v2.9.276: a csoport neve nem ismétlődik a darabnál */
					return '<tr><td><button type="button" class="rk-nm" data-rcard="' + u.id + '" title="' + esc(u.label || t.name) + '">' + esc(sn) + '</button><small>' + esc([u.tag, u.model, 'QR ' + u.qr].filter(Boolean).join(' · ')) + '</small></td>' +
						'<td class="rk-sn" data-l="Sorozatszám">' + (u.serial ? esc(u.serial) : '<span class="cpx-muted">–</span>') + '</td>' +
						'<td>' + esc(u.loc || '–') + (u.deal_id && D[u.deal_id] ? '<small><a href="#deals:' + u.deal_id + '">' + esc(D[u.deal_id].t) + '</a></small>' : '') + '</td>' +
						'<td><span class="rk-chip ' + (u.status === 'ok' ? (svc ? 'mid' : 'ok') : u.status === 'svc' || u.status === 'bad' ? 'mid' : 'bad') + '">' + esc(svc && u.status === 'ok' ? 'szerviz esedékes' : ST[u.status] || u.status) + '</span></td>' +
						'<td class="rk-a"><button type="button" class="rk-lnk" data-runit="' + u.id + '">✎ Szerkesztés</button><button type="button" class="rk-lnk" data-rqr="' + u.id + '">QR</button><button type="button" class="rk-lnk" data-rumv="' + u.id + '">' + (u.loc === S.d.ev ? 'Visszavétel' : 'Kiadás / áthelyezés') + '</button><button type="button" class="rk-lnk" data-rhist="' + u.id + '">Előzmények</button></td></tr>';
				}).join('') + '</tbody></table>' : '<p class="cpx-muted rk-pad">Ebből még nincs darab. <button type="button" class="rk-lnk" data-raddu="' + t.id + '">+ Darab felvétele</button></p>') + '</section>';
		}).join('');
		return bulk + (out || '<p class="cpx-empty">Nincs találat.</p>');
	}
	/* ---- Csomagok (v2.9.283): előre összeállított eszközök + fogyóanyag, egy lépésben ki / vissza */
	const kitsOf = () => Object.entries(S.d.kits || {}).map(e => Object.assign({ id: e[0] }, e[1])).sort((a, b) => a.name.localeCompare(b.name, 'hu'));
	function drawKits() {
		const K = kitsOf().filter(k => hit(k.name, k.note)), ST = S.d.status || {}, D = S.d.deals || {}, ev = S.d.ev;
		const top = '<div class="rk-bar"><button type="button" class="cpx-btn sm" data-rkit="new">+ Új csomag</button><span>Egy csomag egy lépésben kiadható egy rendezvényre és visszavehető – visszavételkor pipálva látszik, mi jött vissza.</span></div>';
		if (!kitsOf().length) return top + '<div class="rk-empty"><p><b>Még nincs csomag.</b> Állíts össze egyet a gyakran együtt kimenő eszközökből, pl. „Szelfigép 1. csomag”: kamera, nyomtató, laptop, állvány + 2 tekercs papír.</p></div>';
		return top + (K.length ? K.map(k => {
			const U = (k.units || []).map(unit).filter(Boolean), out = U.filter(u => u.loc === ev), bad = U.filter(u => u.status !== 'ok');
			const st = out.length === U.length && U.length ? '<span class="rk-chip mid">kint: ' + esc(D[out[0].deal_id] ? D[out[0].deal_id].t : 'rendezvényen') + '</span>' : out.length ? '<span class="rk-chip mid">' + out.length + ' / ' + U.length + ' kint</span>' : '<span class="rk-chip ok">raktárban</span>';
			return '<section class="rk-kit"><div class="rk-kh"><h3>' + esc(k.name) + '</h3>' + st + (bad.length ? '<span class="rk-chip bad">' + bad.length + ' nem használható</span>' : '') + '</div>' + (k.note ? '<p class="rk-kn">' + esc(k.note) + '</p>' : '') +
				'<ul class="rk-kl">' + U.map(u => { const t = item(u.item_id); return '<li><span>' + esc(u.label || (t && t.name)) + '</span><small>' + esc(u.loc === ev ? 'kint' : u.loc) + (u.status !== 'ok' ? ' · ' + esc(ST[u.status] || u.status) : '') + '</small></li>'; }).join('') +
				(k.stock || []).map(x => { const i = item(x[0]); return i ? '<li><span>' + esc(i.name) + '</span><small>' + nf(x[1]) + ' ' + esc(i.unit) + '</small></li>' : ''; }).join('') + '</ul>' +
				'<div class="rk-ka"><button type="button" class="cpx-btn sm" data-rkmv="out" data-rkid="' + esc(k.id) + '">Kiadás rendezvényre</button><button type="button" class="cpx-btn ghost sm" data-rkmv="back" data-rkid="' + esc(k.id) + '"' + (out.length ? '' : ' disabled') + '>↩ Visszavétel</button><button type="button" class="rk-lnk" data-rkit="' + esc(k.id) + '">✎ Szerkesztés</button></div></section>';
		}).join('') : '<p class="cpx-empty">Nincs találat.</p>');
	}
	function kitForm(id) {
		const k = id ? (S.d.kits || {})[id] : null, x = k || { name: '', note: '', units: [], stock: [] };
		const taken = {}; kitsOf().forEach(o => { if (o.id !== id) (o.units || []).forEach(u => { taken[u] = o.name; }); });
		const types = (S.d.items || []).filter(i => i.kind === 'asset'), stockItems = (S.d.items || []).filter(i => i.kind === 'stock' && i.active);
		const uRows = types.map(t => { const U = (S.d.units || []).filter(u => u.item_id === t.id && u.status !== 'gone'); return U.length ? '<fieldset><legend>' + esc(t.name) + '</legend>' + U.map(u => '<label class="rk-ck"' + (taken[u.id] ? ' title="Már a(z) „' + esc(taken[u.id]) + '” csomagban van"' : '') + '><input type="checkbox" name="u" value="' + u.id + '"' + ((x.units || []).includes(u.id) ? ' checked' : '') + (taken[u.id] ? ' disabled' : '') + '> ' + esc(u.label || t.name) + (u.serial ? ' <small>S/N ' + esc(u.serial) + '</small>' : '') + (taken[u.id] ? ' <small>(' + esc(taken[u.id]) + ')</small>' : '') + '</label>').join('') + '</fieldset>' : ''; }).join('');
		const sRow = (iid, q) => '<div class="rk-ks"><select name="si"><option value="">– fogyóanyag –</option>' + stockItems.map(i => '<option value="' + i.id + '"' + (i.id === iid ? ' selected' : '') + '>' + esc(i.name) + ' (' + esc(i.unit) + ')</option>').join('') + '</select><input name="sq" inputmode="decimal" value="' + (q || '') + '" placeholder="menny."><button type="button" class="rk-lnk" data-srm aria-label="Sor törlése">×</button></div>';
		const f = dlg(k ? k.name : 'Új csomag', '<div class="rk-g2">' + fld('Csomag neve', inp('name', x.name, ' required maxlength="120" placeholder="pl. Szelfigép 1. csomag"'), 1) + fld('Megjegyzés', inp('note', x.note, ' maxlength="300" placeholder="pl. fekete Peli-láda"'), 1) + '</div>' +
			'<h4 class="rk-h4">Eszközök a csomagban</h4><input type="search" class="rk-kq" data-kq placeholder="Szűrés név vagy sorozatszám szerint" aria-label="Eszközök szűrése"><div class="rk-qpl" data-kul>' + (uRows || '<p class="cpx-muted">Még nincs eszköz a raktárban.</p>') + '</div>' +
			'<h4 class="rk-h4">Fogyóanyag (kiadáskor ennyi megy ki)</h4><div data-ksl>' + ((x.stock || []).map(s2 => sRow(s2[0], s2[1])).join('') || '') + '</div>' + (stockItems.length ? '<button type="button" class="rk-lnk" data-sadd>+ Fogyóanyag sor</button>' : '<p class="cpx-muted">Nincs felvett fogyóanyag.</p>'),
			async f2 => {
				const units = Array.from(f2.querySelectorAll('[name=u]:checked')).map(c => c.value).join(',');
				const stock = Array.from(f2.querySelectorAll('.rk-ks')).map(r => [Number(r.querySelector('[name=si]').value), String(r.querySelector('[name=sq]').value).replace(',', '.')]).filter(r => r[0] && Number(r[1]) > 0);
				await post('cp_wh_kit', { op: 'save', id: id || '', name: val(f2, 'name'), note: val(f2, 'note'), units, stock: JSON.stringify(stock) }); S.sub = 'kits'; keep(); return 'Csomag mentve.';
			}, 'Mentés', id ? async () => { const ok = await (window.CPXConfirm ? window.CPXConfirm('Törlöd a(z) „' + k.name + '” csomagot?\n\nAz eszközök és a készlet megmaradnak, csak a csomag-összeállítás törlődik.') : Promise.resolve(window.confirm('Törlöd a csomagot?'))); if (!ok) return false; await post('cp_wh_kit', { op: 'del', id }); return 'Csomag törölve.'; } : null);
		const sl = f.querySelector('[data-ksl]'), ad = f.querySelector('[data-sadd]');
		if (ad) ad.addEventListener('click', () => sl.insertAdjacentHTML('beforeend', sRow(0, '')));
		sl.addEventListener('click', e => { const b = e.target.closest('[data-srm]'); if (b) b.closest('.rk-ks').remove(); });
		f.querySelector('[data-kq]').addEventListener('input', e => { const q = e.target.value.trim().toLowerCase(); f.querySelectorAll('[data-kul] .rk-ck').forEach(l => { l.hidden = q && !l.textContent.toLowerCase().includes(q); }); });
	}
	function kitMove(id, type) {
		const k = (S.d.kits || {})[id]; if (!k) return;
		const ev = S.d.ev, ST = S.d.status || {}, out = type === 'out';
		const U = (k.units || []).map(unit).filter(Boolean);
		const urow = u => { const t = item(u.item_id), can = out ? u.loc !== ev : u.loc === ev, warn = out && can && u.status !== 'ok'; return '<label class="rk-ck' + (can ? '' : ' off') + '"><input type="checkbox" name="u" value="' + u.id + '"' + (can && !warn ? ' checked' : '') + (can ? '' : ' disabled') + '> <span>' + esc(u.label || (t && t.name)) + (u.serial ? ' <small>S/N ' + esc(u.serial) + '</small>' : '') + (!can ? ' <small>(' + (out ? 'már kint van' : 'nincs kint') + ')</small>' : warn ? ' <small class="w">(' + esc(ST[u.status] || u.status) + ')</small>' : '') + '</span></label>'; };
		const srow = x => { const i = item(x[0]); return i ? '<label class="rk-ks2"><span>' + esc(i.name) + '</span><input name="sq" data-si="' + i.id + '" inputmode="decimal" value="' + (out ? nf(x[1]) : '') + '" placeholder="' + (out ? '' : 'maradék, ha van') + '"><small>' + esc(i.unit) + '</small></label>' : ''; };
		const f = dlg((out ? 'Kiadás: ' : 'Visszavétel: ') + k.name,
			(out ? '<div class="rk-f w"><span>Rendezvény (deal)</span><input data-dq placeholder="Keresés: név vagy deal-szám (üresen: a következő 2 hét)" autocomplete="off"><input type="hidden" name="deal"><div class="rk-dl" data-dl></div></div>' : '<p class="rk-hint" style="margin-top:0">Pipáld ki, ami visszajött. Ami nincs kipipálva, kint marad (hiányzóként látszik).</p>') +
			'<h4 class="rk-h4">Eszközök</h4><div class="rk-qpl">' + (U.map(urow).join('') || '<p class="cpx-muted">Nincs eszköz a csomagban.</p>') + '</div>' +
			((k.stock || []).length ? '<h4 class="rk-h4">' + (out ? 'Fogyóanyag' : 'Visszahozott fogyóanyag') + '</h4>' + k.stock.map(srow).join('') : ''),
			async f2 => {
				const units = Array.from(f2.querySelectorAll('[name=u]:checked')).map(c => c.value);
				const stock = Array.from(f2.querySelectorAll('[name=sq]')).map(x => [Number(x.dataset.si), String(x.value).replace(',', '.')]).filter(r => Number(r[1]) > 0);
				if (!units.length && !stock.length) throw new Error('Nincs kijelölt tétel.');
				const r = await post('cp_wh_kit', { op: 'move', id, type, deal: val(f2, 'deal'), units: units.join(','), stock: JSON.stringify(stock) });
				const miss = !out ? U.filter(u => u.loc === ev && !units.includes(String(u.id))).length : 0;
				return (out ? 'Kiadva: ' : 'Visszavéve: ') + r.n + ' tétel.' + (miss ? ' ' + miss + ' eszköz kint maradt.' : '');
			}, out ? 'Kiadás' : 'Visszavétel');
		if (out) {
			const dl = f.querySelector('[data-dl]'); let qt = 0;
			const dq = async q => { try { const r = await post('cp_wh_dealq', { q }); dl.innerHTML = (r.rows || []).map(d => '<button type="button" data-did="' + d.id + '"><b>' + esc(d.title) + '</b><small>#' + d.id + (d.event_d ? ' · ' + esc(huD(d.event_d)) : '') + '</small></button>').join('') || '<p class="cpx-muted">Nincs találat.</p>'; } catch (x) { dl.innerHTML = '<p class="rk-err">' + esc(x.message) + '</p>'; } };
			f.querySelector('[data-dq]').addEventListener('input', e => { clearTimeout(qt); f.elements.deal.value = ''; qt = setTimeout(() => dq(e.target.value.trim()), 200); });
			dl.addEventListener('click', e => { const b = e.target.closest('[data-did]'); if (!b) return; f.elements.deal.value = b.dataset.did; f.querySelector('[data-dq]').value = b.querySelector('b').textContent + ' (#' + b.dataset.did + ')'; dl.innerHTML = ''; });
			dq('');
		}
	}
	/* ---- Mozgások */
	async function drawMoves(body) {
		let r; try { r = await post('cp_wh_moves', S.mf || {}); } catch (e) { body.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		if (S.sub !== 'moves' || !body.isConnected) return;
		const T = S.d.types || {}, D = r.deals || {};
		const L = (r.rows || []).filter(x => hit(x.name, x.label, x.serial, x.loc_from, x.loc_to, x.by_name, x.note, D[x.deal_id] && D[x.deal_id].t));
		body.innerHTML = (S.mf ? '<p class="rk-flt">Szűrve: ' + esc(S.mf.label || '') + ' <button type="button" class="rk-lnk" data-rmfx>Szűrés törlése</button></p>' : '') + (!L.length ? '<p class="cpx-empty">Még nincs mozgás.</p>' :
			'<table class="rk-t"><thead><tr><th>Mikor</th><th>Mi</th><th>Mozgás</th><th class="rk-n">Mennyiség</th><th>Ki</th></tr></thead><tbody>' + L.map(x => '<tr><td class="rk-nw">' + esc(huD(x.at) + ' ' + String(x.at).slice(11, 16)) + '</td>' +
				'<td>' + esc(x.name || '–') + (x.unit_id ? '<small>' + esc(x.label || x.serial || '') + '</small>' : '') + '</td>' +
				'<td>' + esc(T[x.type] || x.type) + (Number(x.auto) ? ' <span class="rk-chip ok">automatikus</span>' : '') + '<small>' + esc([x.loc_from, x.loc_to].filter(Boolean).join(' → ')) + (x.deal_id && D[x.deal_id] ? ' · <a href="#deals:' + x.deal_id + '">' + esc(D[x.deal_id].t) + '</a>' : '') + (x.note ? ' · ' + esc(x.note) : '') + '</small></td>' +
				'<td class="rk-n">' + (x.unit_id ? '1 db' : (x.type === 'out' || x.type === 'scrap' ? '−' : x.type === 'adj' && Number(x.qty) > 0 ? '+' : x.type === 'in' || x.type === 'back' ? '+' : '') + nf(Math.abs(x.qty)) + ' ' + esc(x.unit || '')) + '</td>' +
				'<td>' + esc(x.by_name || '–') + '</td></tr>').join('') + '</tbody></table>');
	}
	/* ---- kattintások */
	function act(e) {
		const b = e.target.closest('button'); if (!b) return;
		if (b.dataset.ritem) return itemForm(item(b.dataset.ritem));
		if (b.dataset.runit) return unitForm(unit(b.dataset.runit));
		if (b.dataset.rcard) return unitCard(unit(b.dataset.rcard));
		if (b.dataset.rkit) return kitForm(b.dataset.rkit === 'new' ? null : b.dataset.rkit);
		if (b.dataset.rkmv) return kitMove(b.dataset.rkid, b.dataset.rkmv);
		if (b.dataset.rnew2) return b.dataset.rnew2 === 'asset' ? addAssets(null) : itemForm(null, 'stock');
		if (b.dataset.raddu) return addAssets(item(b.dataset.raddu));
		if (b.dataset.rmv) return moveForm(item(b.dataset.rid), null, b.dataset.rmv);
		if (b.dataset.rmore) return moveForm(item(b.dataset.rmore), null, 'more');
		if (b.dataset.rumv) { const u = unit(b.dataset.rumv); return moveForm(item(u.item_id), u, u.loc === S.d.ev ? 'back' : 'move'); }
		if (b.dataset.rhist) { const u = unit(b.dataset.rhist); S.mf = { unit_id: u.id, label: (u.label || '') + (u.serial ? ' (' + u.serial + ')' : '') }; S.sub = 'moves'; view(S.main); return; }
		if (b.hasAttribute('data-rmfx')) { S.mf = null; draw(); }
		if (b.dataset.rqr) return qrOne(unit(b.dataset.rqr));
		if (b.hasAttribute('data-rqrall')) return qrPick();
	}
	/* ---- párbeszédablak */
	function dlg(title, html, onSave, saveTxt, onDel) {
		const ov = document.createElement('div'); ov.className = 'rk-ov';
		ov.innerHTML = '<form class="rk-dlg" role="dialog" aria-modal="true" aria-label="' + esc(title) + '"><h3>' + esc(title) + '</h3>' + html + '<p class="rk-err" data-derr role="alert"></p><div class="rk-db">' + (onDel ? '<button type="button" class="rk-del" data-ddel>Törlés…</button>' : '') + '<button type="button" class="cpx-btn ghost sm" data-dx>Mégse</button><button class="cpx-btn sm" data-dok>' + esc(saveTxt || 'Mentés') + '</button></div></form>';
		document.body.appendChild(ov);
		const f = ov.querySelector('form'), close = () => { ov.remove(); document.removeEventListener('keydown', ek); };
		const ek = ev => { if (ev.key === 'Escape') close(); };
		document.addEventListener('keydown', ek);
		ov.addEventListener('mousedown', ev => { if (ev.target === ov) close(); });
		ov.querySelector('[data-dx]').addEventListener('click', close);
		const db = ov.querySelector('[data-ddel]'); if (db) db.addEventListener('click', async () => { try { const m = await onDel(); if (m === false) return; close(); toast(m || 'Törölve.'); await load(); } catch (x) { f.querySelector('[data-derr]').textContent = x.message; } });
		f.addEventListener('submit', async ev => {
			ev.preventDefault(); const ok = f.querySelector('[data-dok]'); ok.disabled = true; f.querySelector('[data-derr]').textContent = '';
			try { const m = await onSave(f); close(); if (m) toast(m); await load(); } catch (x) { f.querySelector('[data-derr]').textContent = x.message; ok.disabled = false; }
		});
		const first = f.querySelector('input:not([type=hidden]),select,textarea'); if (first) setTimeout(() => first.focus(), 30);
		return f;
	}
	const fld = (lbl, inner, wide) => '<label class="rk-f' + (wide ? ' w' : '') + '"><span>' + lbl + '</span>' + inner + '</label>';
	const inp = (n, v, x) => '<input name="' + n + '" value="' + esc(v == null ? '' : v) + '"' + (x || '') + '>';
	const val = (f, n) => (f.elements[n] ? f.elements[n].value : '');
	function itemForm(i, kind) {
		const x = i || { kind: kind || 'stock', unit: 'db', active: 1 }, asset = x.kind === 'asset';
		const cats = (S.d && S.d.cats) || [];
		dlg(i ? x.name : asset ? 'Új eszköztípus' : 'Új cikk', '<div class="rk-g2">' +
			fld('Megnevezés', inp('name', x.name, ' required maxlength="160" placeholder="' + (asset ? 'pl. Nikon D5300 váz' : 'pl. DNP DS620 papír 4×6 (400 lap)') + '"'), 1) +
			(i ? '' : fld('Fajta', '<select name="kind"><option value="stock"' + (asset ? '' : ' selected') + '>Fogyóanyag (darabszámmal)</option><option value="asset"' + (asset ? ' selected' : '') + '>Eszköztípus (egyedi darabok sorozatszámmal)</option></select>', 1)) +
			fld('Kategória', '<input name="cat" list="rk-cats" value="' + esc(x.cat || '') + '" maxlength="60"><datalist id="rk-cats">' + cats.map(c => '<option value="' + esc(c) + '">').join('') + '</datalist>') +
			fld('Mértékegység', inp('unit', x.unit, ' maxlength="20" placeholder="db, tekercs, csomag"')) +
			fld('Minimumkészlet', inp('min_qty', x.min_qty || '', ' inputmode="decimal" placeholder="0 = nincs figyelés"')) +
			fld('Beszerzési ár (Ft / egység)', inp('price', x.price || '', ' inputmode="numeric"')) +
			fld('Szállító', inp('supplier', x.supplier, ' maxlength="160"')) + fld('Cikkszám', inp('sku', x.sku, ' maxlength="80"')) +
			fld('Fogyás nyomatonként', inp('per_print', x.per_print || '', ' inputmode="decimal" placeholder="pl. papírnál 0,0025 = 1/400 tekercs"'), 1) +
			fld('Megjegyzés', '<textarea name="note" rows="2" maxlength="500">' + esc(x.note || '') + '</textarea>', 1) +
			(i ? '<label class="rk-ck w"><input type="checkbox" name="inactive"' + (x.active ? '' : ' checked') + '> Kivezetve (a listák végére kerül)</label>' : '') + '</div>' +
			'<p class="rk-hint">A „Fogyás nyomatonként” alapján a rendezvény után a gépek nyomatszámából automatikusan lejön a készlet (papír, festékszalag).</p>',
			async f => { await post('cp_wh_item', { id: x.id || 0, kind: i ? x.kind : val(f, 'kind'), name: val(f, 'name'), cat: val(f, 'cat'), unit: val(f, 'unit'), min_qty: val(f, 'min_qty'), price: val(f, 'price'), supplier: val(f, 'supplier'), sku: val(f, 'sku'), per_print: val(f, 'per_print'), note: val(f, 'note'), inactive: f.elements.inactive && f.elements.inactive.checked ? 1 : '' }); return 'Mentve.'; }, null, x.id ? async () => {
				const U = (S.d.units || []).filter(u => u.item_id === x.id).length;
				const ok = await (window.CPXConfirm ? window.CPXConfirm('Törlöd: ' + x.name + '?' + (U ? '\n\nA hozzá tartozó ' + U + ' darab eszköz is törlődik.' : '') + '\nA mozgásnaplója is törlődik. Ez nem vonható vissza.\n\nHa csak nem használjátok tovább, a „Kivezetve” pipa elég.') : Promise.resolve(window.confirm('Törlöd: ' + x.name + '?')));
				if (!ok) return false; await post('cp_wh_del', { what: 'item', id: x.id }); return x.name + ' törölve.';
			} : null);
	}
	/* v2.9.272: eszköz felvétele egy lépésben – megnevezés (meglévőhöz is), darabszám, sorozatszámok soronként (nem kötelező) */
	function addAssets(t) {
		const types = (S.d.items || []).filter(i => i.kind === 'asset'), cats = S.d.cats || [];
		const f = dlg(t ? t.name + ' – új darab' : 'Új eszköz', (t ? '' : '<p class="rk-tree"><b>Kategória</b> (pl. Kamera, Játék) <span>→</span> <b>eszközfajta</b> (pl. Nikon D5300 váz) <span>→</span> <b>darabok</b> (#1, #2… – mindegyik saját QR-kóddal)</p>') + '<div class="rk-g2">' +
			(t ? '' : fld('Kategória', '<input name="cat" list="rk-cats2" maxlength="60" placeholder="pl. Kamera, Játék, Nyomtató"><datalist id="rk-cats2">' + cats.map(c => '<option value="' + esc(c) + '">').join('') + '</datalist>')) +
			fld(t ? 'Eszközfajta' : 'Eszközfajta neve', '<input name="name" required maxlength="160" list="rk-atypes" value="' + esc(t ? t.name : '') + '"' + (t ? ' readonly' : '') + ' placeholder="pl. Nikon D5300 váz, Gran Turismo 7 lemez"><datalist id="rk-atypes">' + types.map(x => '<option value="' + esc(x.name) + '">').join('') + '</datalist><small class="rk-ex" data-ex hidden></small>', t ? 1 : 0) +
			fld('Darabszám', inp('qty', '1', ' type="number" min="1" max="100" inputmode="numeric" required')) +
			fld('Sorozatszámok (nem kötelező, soronként egy)', '<textarea name="serials" rows="3" placeholder="ha a darabokon van gyári szám – a sorrend a darabok sorrendje"></textarea>', 1) +
			fld('Gyári típus (nem kötelező)', inp('model', '', ' maxlength="160" placeholder="pl. Nikon D5300"')) + fld('Szervizhatár (nem kötelező)', inp('svc_limit', '', ' inputmode="numeric" placeholder="pl. 100000 exponálás"')) + '</div>' +
			'<p class="rk-hint">A darabok a fajta alatt „#1, #2…” néven jelennek meg (a címkén a teljes név áll), később átnevezhetők. Az új darabok a fő raktárba kerülnek (' + esc(S.d.locs[0]) + ').</p>',
			async f2 => { const r = await post('cp_wh_assets_add', { name: val(f2, 'name'), qty: val(f2, 'qty'), cat: val(f2, 'cat'), serials: val(f2, 'serials'), model: val(f2, 'model'), svc_limit: val(f2, 'svc_limit') }); S.sub = 'assets'; keep(); return r.n + ' db felvéve.'; }, 'Felvétel');
		const ser = f.elements.serials, qty = f.elements.qty, ex = f.querySelector('[data-ex]');
		if (!t) f.elements.name.addEventListener('input', () => { const x = types.find(y => y.name.toLowerCase() === f.elements.name.value.trim().toLowerCase()); ex.hidden = !x; if (x) { ex.textContent = 'Már létezik (' + (S.d.units || []).filter(u => u.item_id === x.id).length + ' db) – az új darabok ehhez kerülnek.'; if (f.elements.cat && !f.elements.cat.value && x.cat) f.elements.cat.value = x.cat; } });
		ser.addEventListener('input', () => { const n = ser.value.split(/\n/).filter(x => x.trim()).length; if (n > Number(qty.value || 0)) qty.value = n; });
	}
	/* v2.9.282: eszköz áttekintő lapja (beolvasás után ez nyílik): csak a kitöltött adatok + gyors műveletek; a teljes űrlap a Szerkesztés mögött */
	async function unitCard(u) {
		if (!u) return;
		const t = item(u.item_id), ST = S.d.status || {}, D = S.d.deals || {}, ev = S.d.ev, out = u.loc === ev;
		const svc = u.svc_limit > 0 && u.counter - u.svc_base >= u.svc_limit;
		const row = (l, v) => v ? '<div class="rk-kv"><span>' + l + '</span><b>' + v + '</b></div>' : '';
		const ov = document.createElement('div'); ov.className = 'rk-ov';
		ov.innerHTML = '<div class="rk-dlg rk-card" role="dialog" aria-modal="true" aria-label="' + esc(u.label || (t && t.name) || 'Eszköz') + '">' +
			'<div class="rk-ch"><div><h3>' + esc(u.label || (t && t.name) || 'Eszköz') + '</h3><p>' + esc([t && t.name !== u.label ? t.name : '', t && t.cat].filter(Boolean).join(' · ')) + '</p></div><button type="button" class="rk-x" data-cx aria-label="Bezárás">×</button></div>' +
			'<div class="rk-where ' + (out ? 'out' : '') + '"><span class="rk-chip ' + (u.status === 'ok' ? (svc ? 'mid' : 'ok') : u.status === 'svc' || u.status === 'bad' ? 'mid' : 'bad') + '">' + esc(svc && u.status === 'ok' ? 'szerviz esedékes' : ST[u.status] || u.status) + '</span>' +
			'<b>' + esc(u.loc || '–') + '</b>' + (out && u.deal_id && D[u.deal_id] ? '<a href="#deals:' + u.deal_id + '" data-cdeal>' + esc(D[u.deal_id].t) + '</a>' : '') + '</div>' +
			(u.tag ? '<p class="rk-ctag">' + esc(u.tag) + '</p>' : '') +
			'<div class="rk-kvs">' + row('Sorozatszám', esc(u.serial)) + row('Gyári típus', esc(u.model)) + (u.counter || u.svc_limit ? row('Számláló', esc(String(u.counter)) + (u.svc_limit ? ' / szerviz ' + esc(String(u.svc_base + u.svc_limit)) : '')) : '') + row('Megjegyzés', esc(u.note)) + row('QR-kód', esc(u.qr)) + '</div>' +
			'<div class="rk-acts">' + (out ? '<button type="button" class="cpx-btn" data-ca="back">↩ Visszavétel a raktárba</button>' : '<button type="button" class="cpx-btn" data-ca="out">Kiadás rendezvényre</button><button type="button" class="cpx-btn ghost" data-ca="move">Áthelyezés</button>') + '</div>' +
			'<label class="rk-cst"><span>Állapot</span><select data-cst>' + Object.entries(ST).map(s => '<option value="' + s[0] + '"' + (u.status === s[0] ? ' selected' : '') + '>' + esc(s[1]) + '</option>').join('') + '</select></label>' +
			'<div class="rk-chist" data-chist><p class="cpx-muted">Előzmények betöltése…</p></div>' +
			'<div class="rk-db"><button type="button" class="cpx-btn ghost sm" data-cqr>QR / címke</button><button type="button" class="cpx-btn ghost sm" data-ced>✎ Szerkesztés</button><button type="button" class="cpx-btn sm" data-cx>Kész</button></div></div>';
		document.body.appendChild(ov);
		const close = () => { ov.remove(); document.removeEventListener('keydown', k); }, k = e => { if (e.key === 'Escape') close(); };
		document.addEventListener('keydown', k);
		ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
		ov.addEventListener('click', e => {
			const b = e.target.closest('button,a'); if (!b) return;
			if (b.hasAttribute('data-cx')) close();
			else if (b.dataset.ca) { close(); moveForm(t, u, b.dataset.ca); }
			else if (b.hasAttribute('data-ced')) { close(); unitForm(u); }
			else if (b.hasAttribute('data-cqr')) { close(); qrOne(u); }
			else if (b.hasAttribute('data-cdeal')) close();
		});
		ov.querySelector('[data-cst]').addEventListener('change', async e => { const v = e.target.value; try { await post('cp_wh_ustat', { id: u.id, status: v }); u.status = v; toast('Állapot: ' + (ST[v] || v)); await load(); close(); const nu = unit(u.id); if (nu) unitCard(nu); } catch (x) { toast(x.message, true); e.target.value = u.status; } });
		try {
			const r = await post('cp_wh_moves', { unit_id: u.id }), T = S.d.types || {}, RD = r.deals || {};
			const L = (r.rows || []).slice(0, 4);
			const h = ov.querySelector('[data-chist]'); if (!h.isConnected) return;
			h.innerHTML = L.length ? '<h4>Legutóbbi mozgások</h4><ul>' + L.map(x => '<li><time>' + esc(String(x.at).slice(0, 16).replace(/-/g, '.').replace(' ', ' ')) + '</time><span>' + esc(x.note && x.type === 'adj' && !Number(x.qty) ? x.note : (T[x.type] || x.type)) + (x.deal_id && RD[x.deal_id] ? ' – ' + esc(RD[x.deal_id].t) : (x.loc_to ? ' → ' + esc(x.loc_to) : '')) + '</span><small>' + esc(x.by_name || '') + '</small></li>').join('') + '</ul>' : '';
		} catch (x) { const h = ov.querySelector('[data-chist]'); if (h) h.innerHTML = ''; }
	}
	function unitForm(u) {
		const types = (S.d && S.d.items || []).filter(i => i.kind === 'asset' && i.active);
		const x = u || {}, ST = (S.d && S.d.status) || {};
		if (!types.length) { toast('Előbb vegyél fel egy eszköztípust (pl. „Nikon D5300 váz”) – utána jöhetnek a darabok.'); return itemForm(null, 'asset'); }
		dlg(x.id ? (x.label || 'Eszköz') : 'Új eszköz', '<div class="rk-g2">' +
			fld('Eszközfajta', '<select name="item_id">' + types.map(t => '<option value="' + t.id + '"' + (t.id === x.item_id ? ' selected' : '') + '>' + esc(t.name) + '</option>').join('') + '</select>', 1) +
			fld('Egyedi név', inp('label', x.label, ' maxlength="120" placeholder="pl. Szelfigép 3 kamerája"')) + fld('Sorozatszám', inp('serial', x.serial, ' maxlength="120" autocomplete="off"')) +
			fld('Gyári típus', inp('model', x.model, ' maxlength="160" placeholder="pl. Nikon D5300"')) +
			fld('Állapot', '<select name="status">' + Object.entries(ST).map(s => '<option value="' + s[0] + '"' + ((x.status || 'ok') === s[0] ? ' selected' : '') + '>' + esc(s[1]) + '</option>').join('') + '</select>') +
			fld('Számláló (exponálás / nyomat)', inp('counter', x.counter || '', ' inputmode="numeric"')) + fld('Szervizhatár (0 = nincs)', inp('svc_limit', x.svc_limit || '', ' inputmode="numeric" placeholder="pl. 100000"')) +
			fld('Felirat a QR-címkén (nem kötelező)', inp('tag', x.tag, ' maxlength="120" placeholder="pl. Tartalék kamera – töltő a dobozban"'), 1) +
			fld('Megjegyzés', '<textarea name="note" rows="2" maxlength="500">' + esc(x.note || '') + '</textarea>', 1) +
			(x.id && x.svc_limit ? '<label class="rk-ck w"><input type="checkbox" name="svc_done"> Szerviz megtörtént (a számláló innen indul újra)</label>' : '') + '</div>' +
			(x.id ? '<p class="rk-hint">Hely: <b>' + esc(x.loc || '–') + '</b> · QR-kód: <b>' + esc(x.qr) + '</b> · A helyet a „Kiadás / áthelyezés” változtatja.</p>' : '<p class="rk-hint">Az új eszköz a fő raktárba kerül (' + esc(S.d.locs[0]) + ').</p>'),
			async f => { await post('cp_wh_unit', { id: x.id || 0, item_id: val(f, 'item_id'), label: val(f, 'label'), serial: val(f, 'serial'), model: val(f, 'model'), status: val(f, 'status'), counter: val(f, 'counter'), svc_limit: val(f, 'svc_limit'), note: val(f, 'note'), tag: val(f, 'tag'), svc_done: f.elements.svc_done && f.elements.svc_done.checked ? 1 : '' }); return 'Mentve.'; }, null, x.id ? async () => {
				const nm = x.label || 'eszköz';
				const ok = await (window.CPXConfirm ? window.CPXConfirm('Törlöd: ' + nm + (x.serial ? ' (S/N ' + x.serial + ')' : '') + '?\n\nA mozgásnaplója is törlődik, a QR-címkéje nem fog működni. Ez nem vonható vissza.\n\nHa elveszett vagy selejtes, az Állapot („Elveszett”, „Kivezetve”) megőrzi az előzményeit.') : Promise.resolve(window.confirm('Törlöd: ' + nm + '?')));
				if (!ok) return false; await post('cp_wh_del', { what: 'unit', id: x.id }); return nm + ' törölve.';
			} : null);
	}
	function moveForm(i, u, type) {
		if (!i) return;
		const T = S.d.types, locs = S.d.locs, ev = S.d.ev, st = (S.d.stock || {})[i.id] || {};
		const opts = (L, sel) => L.map(l => '<option' + (l === sel ? ' selected' : '') + '>' + esc(l) + '</option>').join('');
		const types = u ? (u.loc === ev ? ['back'] : ['out', 'move', 'scrap']) : type === 'more' ? ['back', 'move', 'scrap', 'adj', 'in', 'out'] : [type];
		const fromL = Object.keys(st).filter(l => st[l] > 0);
		const f = dlg((u ? (u.label || i.name) : i.name), '<div class="rk-g2">' +
			fld('Mozgás', '<select name="type">' + types.map(t => '<option value="' + t + '">' + esc(T[t]) + '</option>').join('') + '</select>', 1) +
			(u ? '<p class="rk-hint w">Most: <b>' + esc(u.loc || '–') + '</b></p>' : fld('Mennyiség (' + esc(i.unit) + ')', inp('qty', '', ' inputmode="decimal" required placeholder="' + (type === 'adj' ? 'pl. -3 vagy 5' : '') + '"'))) +
			'<div data-mf class="rk-f"><span>Honnan</span><select name="from">' + opts(fromL.length ? fromL : locs, '') + '</select></div>' +
			'<div data-mt class="rk-f"><span>Hová</span><select name="to">' + opts(locs, locs[0]) + '</select></div>' +
			'<div data-md class="rk-f w"><span>Rendezvény (deal)</span><input data-dq placeholder="Keresés: név vagy deal-szám (üresen: a következő 2 hét)" autocomplete="off"><input type="hidden" name="deal"><div class="rk-dl" data-dl></div></div>' +
			'<div data-mp class="rk-f"><span>Összesen fizetve (Ft)</span><input name="price" inputmode="numeric" placeholder="nem kötelező"></div>' +
			fld('Megjegyzés', inp('note', '', ' maxlength="255"'), 1) + '</div>' +
			(!u ? '<p class="rk-hint">Készlet most: ' + (Object.keys(st).length ? Object.entries(st).map(e => esc(e[0]) + ' ' + nf(e[1])).join(' · ') : 'nincs') + '</p>' : ''),
			async f2 => { const t = val(f2, 'type'); await post('cp_wh_move', { type: t, item_id: i.id, unit_id: u ? u.id : 0, qty: val(f2, 'qty'), from: val(f2, 'from'), to: val(f2, 'to'), deal: val(f2, 'deal'), price: val(f2, 'price'), note: val(f2, 'note') }); return T[t] + ' rögzítve.'; }, 'Rögzítés');
		const sh = () => { const t = val(f, 'type'); f.querySelector('[data-mf]').hidden = !!u || !['out', 'move', 'scrap'].includes(t); f.querySelector('[data-mt]').hidden = !['in', 'back', 'move', 'adj'].includes(t) || (t === 'move' && false); f.querySelector('[data-md]').hidden = t !== 'out'; f.querySelector('[data-mp]').hidden = t !== 'in'; if (t === 'out') dq(''); };
		if (u && types.includes(type)) f.elements.type.value = type; /* v2.9.282: az áttekintő lapról a választott mozgással nyílik */
		f.elements.type.addEventListener('change', sh);
		let qt = 0; const dl = f.querySelector('[data-dl]');
		async function dq(q) { try { const r = await post('cp_wh_dealq', { q }); dl.innerHTML = (r.rows || []).map(d => '<button type="button" data-did="' + d.id + '"><b>' + esc(d.title) + '</b><small>#' + d.id + (d.event_d ? ' · ' + esc(huD(d.event_d)) : '') + '</small></button>').join('') || '<p class="cpx-muted">Nincs találat.</p>'; } catch (x) { dl.innerHTML = '<p class="rk-err">' + esc(x.message) + '</p>'; } }
		f.querySelector('[data-dq]').addEventListener('input', e => { clearTimeout(qt); f.elements.deal.value = ''; qt = setTimeout(() => dq(e.target.value.trim()), 200); });
		dl.addEventListener('click', e => { const b = e.target.closest('[data-did]'); if (!b) return; f.elements.deal.value = b.dataset.did; f.querySelector('[data-dq]').value = b.querySelector('b').textContent + ' (#' + b.dataset.did + ')'; dl.innerHTML = ''; });
		sh();
	}
	function locsForm() {
		dlg('Helyek', '<p class="rk-hint">Soronként egy hely. Az <b>első</b> a fő raktár: ide kerül a beérkezés és az új eszköz. A „' + esc(S.d.ev) + '” hely automatikus (a kiadott tételeké).</p><textarea name="locs" rows="7" maxlength="1500">' + esc((S.d.locs || []).join('\n')) + '</textarea>',
			async f => { await post('cp_wh_locs', { locs: val(f, 'locs') }); return 'Helyek mentve.'; });
	}
	/* ---- QR-kódok (v2.9.260): eszközönként címke; beolvasva a panel ezt az eszközt nyitja meg (#raktar:e:<kód>) */
	const SRC = (document.currentScript && document.currentScript.src) || '';
	let qrP = null;
	function qrLib() {
		if (window.qrcode) return Promise.resolve();
		if (!qrP) qrP = new Promise((res, rej) => { const sc = document.createElement('script'); sc.src = SRC.replace(/raktar\.js(\?.*)?$/, 'qrcode.min.js$1'); sc.onload = res; sc.onerror = () => { qrP = null; rej(new Error('A QR-kód készítője nem töltődött be.')); }; document.head.appendChild(sc); });
		return qrP;
	}
	const uUrl = u => location.origin + location.pathname + '#raktar:e:' + u.qr;
	const uName = u => { const t = item(u.item_id); return u.label || (t ? t.name : 'Eszköz'); };
	function qrMake(u) { const q = window.qrcode(0, 'M'); q.addData(uUrl(u)); q.make(); return q; }
	const qrSvg = (q, size) => q.createSvgTag({ cellSize: Math.max(1, Math.floor(size / (q.getModuleCount() + 4))), margin: 2 }).replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ');
	function deepOpen() {
		const m = /^#raktar:e:([A-Z0-9]{4,12})$/i.exec(location.hash); if (!m || !S.d) return;
		history.replaceState(history.state, '', '#raktar');
		const u = (S.d.units || []).find(x => String(x.qr).toUpperCase() === m[1].toUpperCase());
		if (!u) { toast('Ez a QR-kód nem tartozik egyik eszközhöz sem (' + m[1] + ').', true); return; }
		S.sub = 'assets'; keep(); S.q = String(u.serial || u.qr).toLowerCase(); view(S.main); setTimeout(() => unitCard(u), 50);
	}
	async function qrOne(u) {
		if (!u) return; try { await qrLib(); } catch (e) { toast(e.message, true); return; }
		const q = qrMake(u), t = item(u.item_id);
		const ov = document.createElement('div'); ov.className = 'rk-ov';
		/* v2.9.277: élő címke-előnézet – pontosan az, ami a nyomtatott címkére kerül; a felirat gépelés közben frissül */
		ov.innerHTML = '<div class="rk-dlg rk-qrd" role="dialog" aria-modal="true" aria-label="QR-kód"><h3>Címke előnézete</h3>' +
			'<div class="rk-lab" aria-label="A nyomtatandó címke"><div class="rk-lq" role="img" aria-label="QR-kód: ' + esc(u.qr) + '">' + qrSvg(q, 160) + '</div><div class="rk-lt"><b>' + esc(uName(u)) + '</b>' + (t && t.name !== uName(u) ? '<span>' + esc(t.name) + '</span>' : '') +
			'<i data-qprev' + (u.tag ? '' : ' hidden') + '>' + esc(u.tag || '') + '</i>' + (u.serial ? '<span>S/N ' + esc(u.serial) + '</span>' : '') + '<small>Kód: ' + esc(u.qr) + '</small><em>eventcrazy.hu</em></div></div>' +
			'<label class="rk-f rk-tagf"><span>Egyedi felirat a címkén (nem kötelező)</span><input data-qtag maxlength="120" value="' + esc(u.tag || '') + '" placeholder="pl. Tartalék kamera – töltő a dobozban"></label>' +
			'<div class="rk-db"><button type="button" class="cpx-btn ghost sm" data-qx>Bezárás</button><button type="button" class="cpx-btn ghost sm" data-qcopy>Kép másolása</button><button type="button" class="cpx-btn ghost sm" data-qpng>PNG letöltése</button><button type="button" class="cpx-btn sm" data-qprint>' + (canShareImg() ? 'Nyomtatás / megosztás' : 'Címke nyomtatása') + '</button></div></div>';
		document.body.appendChild(ov);
		const close = () => { ov.remove(); document.removeEventListener('keydown', k); }, k = e => { if (e.key === 'Escape') close(); };
		document.addEventListener('keydown', k);
		const tg = ov.querySelector('[data-qtag]'), pv = ov.querySelector('[data-qprev]');
		tg.addEventListener('input', () => { const v = tg.value.trim(); pv.textContent = v; pv.hidden = !v; });
		const saveTag = async () => { const v = tg.value.trim(); if (v === (u.tag || '')) return; try { await post('cp_wh_tag', { id: u.id, tag: v }); u.tag = v; } catch (x) { toast(x.message, true); } };
		tg.addEventListener('change', saveTag);
		ov.addEventListener('click', async e => { if (e.target === ov || e.target.closest('[data-qx]')) { await saveTag(); close(); } else if (e.target.closest('[data-qcopy]')) { qrCopy(u, q, tg.value.trim()); saveTag(); } else if (e.target.closest('[data-qpng]')) { await saveTag(); qrPng(u, q); } else if (e.target.closest('[data-qprint]')) { qrPrint([Object.assign({}, u, { tag: tg.value.trim() })]); saveTag(); } });
		ov.querySelector('[data-qprint]').focus();
	}
	/* v2.9.278: a címkekép vászonra rajzolva – letöltéshez és vágólapra másoláshoz is */
	function qrCanvas(u, q) {
		/* v2.9.279: ugyanaz a fekvő elrendezés, mint az előnézet és a nyomtatott címke (70 × 37 mm, 20 px/mm): QR balra, szöveg jobbra */
		const W = 1400, H = 740, P = 48, n = q.getModuleCount(), qa = H - 2 * P, cell = Math.floor(qa / n), qs = cell * n, qx = P + Math.round((qa - qs) / 2), qy = Math.round((H - qs) / 2), t = item(u.item_id);
		const c = document.createElement('canvas'); c.width = W; c.height = H; const g = c.getContext('2d');
		g.fillStyle = '#fff'; g.fillRect(0, 0, W, H); g.fillStyle = '#111';
		for (let r = 0; r < n; r++) for (let k = 0; k < n; k++) if (q.isDark(r, k)) g.fillRect(qx + k * cell, qy + r * cell, cell, cell);
		const x0 = P + qa + 44, tw = W - x0 - P, F = '"Inter", "Segoe UI", Arial, sans-serif';
		/* sortörés szóhatáron, legfeljebb max sor (az utolsó „…”-tal rövidül) */
		const wrap = (txt, font, max) => { g.font = font; const w = String(txt).split(/\s+/), L = []; let cur = ''; w.forEach(x => { const tr = cur ? cur + ' ' + x : x; if (g.measureText(tr).width <= tw || !cur) cur = tr; else { L.push(cur); cur = x; } }); if (cur) L.push(cur); if (L.length > max) { L.length = max; let l = L[max - 1]; while (g.measureText(l + '…').width > tw && l.length > 1) l = l.slice(0, -1); L[max - 1] = l + '…'; } return L.map(l => { while (g.measureText(l).width > tw && l.length > 1) l = l.slice(0, -1); return l; }); };
		const nm = uName(u), rows = [];
		wrap(nm, '700 54px ' + F, 2).forEach(l => rows.push([l, '700 54px ' + F, '#141C33', 64]));
		if (t && t.name !== nm) wrap(t.name, '600 44px ' + F, 1).forEach(l => rows.push([l, '600 44px ' + F, '#141C33', 54])); /* v2.9.281: a fajta, a S/N és a kód is olvasható méretű (a címkenyomtató felbontása miatt) */
		if (u.tag) wrap(u.tag, '700 44px ' + F, 2).forEach(l => rows.push([l, '700 44px ' + F, '#141C33', 54]));
		if (u.serial) wrap('S/N ' + u.serial, '600 44px ' + F, 1).forEach(l => rows.push([l, '600 44px ' + F, '#141C33', 54]));
		rows.push(['Kód: ' + u.qr, '600 44px ' + F, '#141C33', 54]);
		rows.push(['eventcrazy.hu', '800 46px ' + F, '#141C33', 60]);
		const th = rows.reduce((a, r) => a + r[3], 0); let y = Math.round((H - th) / 2);
		g.textAlign = 'left'; g.textBaseline = 'top';
		rows.forEach(r => { g.font = r[1]; g.fillStyle = r[2]; g.fillText(r[0], x0, y + Math.round((r[3] - parseInt(r[1].split(' ')[1], 10)) / 2)); y += r[3]; });
		return c;
	}
	/* vágólapra: a ClipboardItem-nek ígéretet adunk át még a kattintás alatt (Safari ezt kéri) */
	function qrCopy(u, q, tag) {
		const uu = Object.assign({}, u, { tag: tag });
		if (!navigator.clipboard || !window.ClipboardItem) { toast('Ez a böngésző nem tud képet másolni – használd a „PNG letöltése” gombot.', true); return; }
		const blob = new Promise((res, rej) => qrCanvas(uu, q).toBlob(b => b ? res(b) : rej(new Error('A kép nem készült el.')), 'image/png'));
		navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(() => toast('A címkekép a vágólapon – illeszd be a nyomtató alkalmazásába (Ctrl+V / ⌘V).'), e => toast('A másolás nem sikerült (' + (e && e.message || 'a böngésző nem engedte') + ') – használd a „PNG letöltése” gombot.', true));
	}
	function qrPng(u, q) {
		const c = qrCanvas(u, q);
		c.toBlob(b => { if (!b) { toast('A PNG nem készült el.', true); return; } const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'eszkoz-qr-' + u.qr + '.png'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000); }, 'image/png');
	}
	/* A4-es címkelap: 3 × 8 címke (70 × 37 mm), a kiválasztott eszközökről */
	/* v2.9.284: mobilon nincs külön nyomtatási lap (iPhone-on beragadt) – a címkeképek a megosztás menübe mennek:
	 * onnan AirPrint („Nyomtatás”) vagy a címkenyomtató alkalmazása (pl. Open Label) választható. A fájl szinkron készül, hogy a koppintás „élő” maradjon. */
	function canShareImg() { try { return !!(navigator.share && navigator.canShare && window.matchMedia && matchMedia('(pointer:coarse)').matches && navigator.canShare({ files: [new File([new Uint8Array(1)], 'a.png', { type: 'image/png' })] })); } catch (e) { return false; } }
	function labelFile(u) { const d = qrCanvas(u, qrMake(u)).toDataURL('image/png'), bin = atob(d.split(',')[1]), a = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i); return new File([a], 'cimke-' + u.qr + '.png', { type: 'image/png' }); }
	function shareLabels(U) {
		let files; try { files = U.map(labelFile); } catch (e) { toast('A címkekép nem készült el.', true); return; }
		navigator.share({ files, title: U.length > 1 ? U.length + ' QR-címke' : 'QR-címke' }).then(() => {}, e => { if (!e || e.name !== 'AbortError') toast('A megosztás nem sikerült – használd a „Kép másolása” gombot.', true); });
	}
	async function qrPrint(U) {
		if (!U.length) return;
		if (window.qrcode && canShareImg()) { shareLabels(U); return; }
		try { await qrLib(); } catch (e) { toast(e.message, true); return; }
		const w = window.open('', '_blank'); if (!w) { toast('A böngésző letiltotta az új ablakot – engedélyezd a felugró ablakot a panelnek.', true); return; }
		const lab = u => { const t = item(u.item_id); return '<div class="l"><div class="q">' + qrSvg(qrMake(u), 120) + '</div><div class="t"><b>' + esc(uName(u)) + '</b>' + (t && t.name !== uName(u) ? '<span>' + esc(t.name) + '</span>' : '') + (u.tag ? '<i>' + esc(u.tag) + '</i>' : '') + (u.serial ? '<span>S/N ' + esc(u.serial) + '</span>' : '') + '<small>Kód: ' + esc(u.qr) + '</small><em>eventcrazy.hu</em></div></div>'; };
		w.document.write('<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Eszköz QR-címkék (' + U.length + ' db)</title><style>@page{size:A4;margin:12mm 0 0 0}body{margin:0;font-family:Inter,"Segoe UI",Arial,sans-serif;color:#141C33}' +
			'.p{display:grid;grid-template-columns:repeat(3,70mm);grid-auto-rows:37mm;justify-content:center}.l{box-sizing:border-box;display:flex;align-items:center;gap:2.5mm;padding:2.5mm 3mm;overflow:hidden;break-inside:avoid;outline:.2mm dashed #ccc}' +
			'.q{flex:none;width:28mm;height:28mm}.q svg{width:100%;height:100%;display:block}.t{display:flex;flex-direction:column;gap:.6mm;min-width:0}.t b{font-size:9.5pt;line-height:1.15;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}' +
			'.t span{font-size:9pt;font-weight:600;color:#141C33;overflow-wrap:anywhere}.t small{font-size:9pt;font-weight:600;color:#141C33;letter-spacing:.02em}.t i{font-style:normal;font-weight:700;font-size:9pt;color:#141C33;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.t em{font-style:normal;font-weight:800;font-size:9pt;color:#141C33;margin-top:.4mm}@media print{.l{outline:0}.no{display:none}}.no{text-align:center;padding:10px;font-size:14px}.nb{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;padding:calc(env(safe-area-inset-top,0px) + 56px) 12px 0}.nb button{font:600 16px Inter,Arial,sans-serif;padding:12px 18px;border-radius:999px;border:1.5px solid #141C33;background:#fff;color:#141C33;cursor:pointer}.nb .pr{background:#141C33;color:#fff}</style></head><body>' +
			'<div class="no nb"><button type="button" onclick="back()">← Vissza a panelbe</button><button type="button" class="pr" onclick="window.print()">Nyomtatás</button></div><p class="no">' + U.length + ' címke · A4, 3 × 8 címke (70 × 37 mm) – nyomtatás: Ctrl+P / ⌘P, méretezés: 100% („Tényleges méret”).</p><div class="p">' + U.map(lab).join('') + '</div><script>var P=' + JSON.stringify(location.href.split('#')[0] + '#raktar') + ';function back(){try{window.close()}catch(e){}setTimeout(function(){location.href=P},250)}window.addEventListener("afterprint",function(){setTimeout(back,300)});setTimeout(function(){window.print()},400)<\/script></body></html>');
		w.document.close();
	}
	function qrPick() {
		qrLib().catch(() => {}); /* előre betöltve, hogy mobilon a megosztás a koppintáskor azonnal indulhasson */
		const types = (S.d.items || []).filter(i => i.kind === 'asset'), U = S.d.units || [];
		const f = dlg('QR-címkék nyomtatása', '<p class="rk-hint" style="margin-top:0">Jelöld ki, melyik eszközökről kérsz címkét (A4-es lap, 3 × 8 címke).</p><label class="rk-ck"><input type="checkbox" data-qa checked> Mind</label><div class="rk-qpl">' +
			types.map(t => { const L = U.filter(u => u.item_id === t.id && u.status !== 'gone'); return L.length ? '<fieldset><legend>' + esc(t.name) + '</legend>' + L.map(u => '<label class="rk-ck"><input type="checkbox" name="u" value="' + u.id + '" checked> ' + esc(u.label || t.name) + (u.serial ? ' <small>S/N ' + esc(u.serial) + '</small>' : '') + '</label>').join('') + '</fieldset>' : ''; }).join('') + '</div>',
			async f2 => { const ids = Array.from(f2.querySelectorAll('[name=u]:checked')).map(x => Number(x.value)); if (!ids.length) throw new Error('Jelölj ki legalább egy eszközt.'); await qrPrint(U.filter(u => ids.includes(u.id))); return ''; }, 'Címkelap megnyitása');
		f.querySelector('[data-qa]').addEventListener('change', e => f.querySelectorAll('[name=u]').forEach(x => { x.checked = e.target.checked; }));
	}
	/* v2.9.274: QR-olvasó a panelben – kamerával beolvasva rögtön a cikk / eszköz nyílik meg.
	 * Beépített felismerő (BarcodeDetector), ha a böngésző tudja; különben a jsQR (assets/jsqr.js, igény szerint töltődik). */
	let jqP = null;
	function jsqrLib() {
		if (window.jsQR) return Promise.resolve();
		if (!jqP) jqP = new Promise((res, rej) => { const sc = document.createElement('script'); sc.src = SRC.replace(/raktar\.js(\?.*)?$/, 'jsqr.js$1'); sc.onload = res; sc.onerror = () => { jqP = null; rej(new Error('A QR-felismerő nem töltődött be.')); }; document.head.appendChild(sc); });
		return jqP;
	}
	const codeOf = txt => { const t = String(txt || '').trim(), m = /#raktar:e:([A-Z0-9]{4,12})\b/i.exec(t) || /^([A-Z0-9]{4,12})$/i.exec(t); return m ? m[1].toUpperCase() : ''; };
	function openCode(code) {
		const u = (S.d.units || []).find(x => String(x.qr).toUpperCase() === code);
		if (!u) return false;
		S.sub = 'assets'; keep(); S.q = String(u.serial || u.qr).toLowerCase(); view(S.main); setTimeout(() => unitCard(u), 50);
		toast('Beolvasva: ' + (u.label || code));
		return true;
	}
	async function scan() {
		const ov = document.createElement('div'); ov.className = 'rk-ov rk-scan';
		ov.innerHTML = '<div class="rk-dlg" role="dialog" aria-modal="true" aria-label="QR-kód beolvasása"><h3>QR-kód beolvasása</h3><div class="rk-cam"><video playsinline muted autoplay></video><i aria-hidden="true"></i></div><p class="rk-hint" data-sm>Tartsd a címkét a keretbe – magától felismeri.</p>' +
			'<form class="rk-man" data-man><input name="c" maxlength="12" placeholder="vagy írd be a kódot (pl. HO980XE)" autocomplete="off" autocapitalize="characters"><button class="cpx-btn ghost sm">Megnyitás</button></form><div class="rk-db"><button type="button" class="cpx-btn sm" data-sx>Bezárás</button></div></div>';
		document.body.appendChild(ov);
		const v = ov.querySelector('video'), msg = ov.querySelector('[data-sm]');
		let stream = null, done = false, tm = 0;
		const close = () => { done = true; clearTimeout(tm); if (stream) stream.getTracks().forEach(t => t.stop()); ov.remove(); document.removeEventListener('keydown', k); };
		const k = e => { if (e.key === 'Escape') close(); }; document.addEventListener('keydown', k);
		ov.querySelector('[data-sx]').addEventListener('click', close);
		ov.addEventListener('mousedown', e => { if (e.target === ov) close(); });
		const hit = txt => { const c = codeOf(txt); if (!c) { msg.textContent = 'Ez nem raktári QR-kód.'; return false; } if (!S.d) return false; if (openCode(c)) { close(); return true; } msg.textContent = 'Ismeretlen kód: ' + c + ' – nincs ilyen eszköz a raktárban.'; return false; };
		ov.querySelector('[data-man]').addEventListener('submit', e => { e.preventDefault(); hit(e.target.elements.c.value); });
		try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } }, audio: false }); }
		catch (e) { msg.textContent = e && e.name === 'NotAllowedError' ? 'Nincs engedély a kamerához – engedélyezd a böngésző beállításaiban, vagy írd be a kódot.' : 'A kamera nem indul – írd be a kódot.'; ov.querySelector('.rk-cam').hidden = true; return; }
		if (done) { stream.getTracks().forEach(t => t.stop()); return; }
		v.srcObject = stream; try { await v.play(); } catch (e) { /* autoplay */ }
		let det = null;
		if ('BarcodeDetector' in window) { try { if ((await window.BarcodeDetector.getSupportedFormats()).includes('qr_code')) det = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { det = null; } }
		if (!det) { try { await jsqrLib(); } catch (e) { msg.textContent = e.message + ' Írd be a kódot.'; return; } }
		const cv = document.createElement('canvas'), g = cv.getContext('2d', { willReadFrequently: true });
		let last = '';
		const tick = async () => {
			if (done) return;
			if (v.readyState >= 2 && v.videoWidth) {
				let txt = '';
				try {
					if (det) { const r = await det.detect(v); txt = r && r[0] ? r[0].rawValue : ''; }
					else { const s = Math.min(1, 640 / v.videoWidth); cv.width = Math.round(v.videoWidth * s); cv.height = Math.round(v.videoHeight * s); g.drawImage(v, 0, 0, cv.width, cv.height); const im = g.getImageData(0, 0, cv.width, cv.height); const r = window.jsQR(im.data, im.width, im.height, { inversionAttempts: 'dontInvert' }); txt = r ? r.data : ''; }
				} catch (e) { txt = ''; }
				if (txt && txt !== last) { last = txt; if (navigator.vibrate) navigator.vibrate(40); if (hit(txt)) return; }
			}
			tm = setTimeout(tick, 160);
		};
		tick();
	}
	let cssDone = false;
	function css() {
		if (cssDone) return; cssDone = true;
		const st = document.createElement('style');
		st.textContent = '.rk-h{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:10px}.rk-h h2{margin:0;font:800 20px "Plus Jakarta Sans",sans-serif}.rk-lead{margin:4px 0 0;font-size:13px;color:var(--mu);max-width:70ch}.rk-ha{display:flex;flex-wrap:wrap;gap:6px}' +
			'.rk-tb{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:14px 0 12px}.rk-tb input[type=search]{flex:1 1 240px;min-width:0;height:38px;border:1.5px solid var(--li);border-radius:10px;padding:0 12px;background:#fff;font:inherit}.rk-tb input[type=search]:focus,.rk-tb select:focus{outline:0;border-color:var(--o)}' +
			'.rk-tb select{height:38px;border:1.5px solid var(--li);border-radius:10px;padding:0 8px;background:#fff;font:inherit;color:var(--ink);max-width:100%}' +
			'.rk-seg{display:flex;gap:4px;background:#F0ECE6;border-radius:999px;padding:3px}.rk-seg button{border:0;background:transparent;border-radius:999px;padding:0 14px;height:32px;font:600 13px Inter,sans-serif;color:var(--mu);cursor:pointer}.rk-seg button[aria-selected=true]{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(20,28,51,.12)}.rk-seg button:focus-visible,.rk-lnk:focus-visible,.rk-nm:focus-visible{outline:2px solid var(--o);outline-offset:1px}' +
			'.rk-t{width:100%;border-collapse:collapse;font-size:14px;background:#fff;border:1px solid var(--li);border-radius:12px;overflow:hidden}.rk-t th{text-align:left;font:600 12px Inter,sans-serif;color:var(--mu);padding:8px 10px;background:#FAF8F5;border-bottom:1px solid var(--li)}.rk-t td{padding:8px 10px;border-bottom:1px solid #F0EBE3;vertical-align:top}.rk-t tr:last-child td{border-bottom:0}' +
			'.rk-t small{display:block;font-size:12px;color:var(--mu);margin-top:2px;overflow-wrap:anywhere}.rk-t small a{color:inherit}.rk-n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.rk-t th.rk-n{text-align:right}.rk-nw{white-space:nowrap;font-variant-numeric:tabular-nums}.rk-sn{font-variant-numeric:tabular-nums;overflow-wrap:anywhere}' +
			'.rk-nm{border:0;background:none;padding:0;font:600 14px Inter,sans-serif;color:var(--ink);text-align:left;cursor:pointer;overflow-wrap:anywhere}.rk-nm:hover{text-decoration:underline}' +
			'.rk-a{text-align:right;white-space:nowrap}.rk-lnk{border:0;background:none;padding:4px 6px;font:600 12.5px Inter,sans-serif;color:var(--n);text-decoration:underline;text-underline-offset:2px;cursor:pointer}' +
			'.rk-loc span{display:inline-block;margin:0 10px 2px 0;font-size:13px;white-space:nowrap;font-variant-numeric:tabular-nums}' +
			'.rk-chip{display:inline-block;font:600 11px Inter,sans-serif;border-radius:999px;padding:2px 8px;white-space:nowrap;vertical-align:1px}.rk-chip.ok{background:#E3F4E8;color:#1E6B39}.rk-chip.mid{background:#FCF0DC;color:#7A4E12}.rk-chip.bad{background:#FBE6E4;color:#9B2A1F}' +
			'.rk-off td{opacity:.55}.rk-g{margin:0 0 18px}.rk-g h3{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 10px;margin:0 0 6px;font-size:15px}.rk-gn{overflow-wrap:anywhere}.rk-ga{margin-left:auto;display:inline-flex;gap:2px}.rk-g h3 small{font:600 12.5px Inter,sans-serif;color:var(--mu)}.rk-pad{margin:4px 0 0}' +
			'.rk-empty{background:#fff;border:1px dashed var(--li);border-radius:14px;padding:22px;max-width:60ch}.rk-empty p{margin:0 0 12px}.rk-flt{font-size:13px;color:var(--mu);margin:0 0 8px}.rk-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}' +
			'.rk-ov{position:fixed;inset:0;z-index:10070;background:rgba(20,28,51,.4);display:flex;align-items:center;justify-content:center;padding:16px}' +
			'.rk-dlg{background:#fff;border-radius:18px;padding:22px;width:min(560px,100%);max-height:92vh;overflow:auto;box-shadow:0 24px 60px rgba(20,28,51,.3)}.rk-dlg h3{margin:0 0 14px;font:700 17px/1.3 Inter,sans-serif;overflow-wrap:anywhere}' +
			'.rk-g2{display:grid;grid-template-columns:1fr 1fr;gap:10px 12px}.rk-f{display:flex;flex-direction:column;gap:4px;min-width:0}.rk-f.w,.rk-g2 .w{grid-column:1/-1}.rk-f>span{font:600 12.5px Inter,sans-serif;color:var(--mu)}' +
			'.rk-f input,.rk-f select,.rk-f textarea,.rk-dlg>textarea{width:100%;box-sizing:border-box;border:1.5px solid var(--li);border-radius:10px;padding:8px 10px;font:inherit;background:#fff;color:var(--ink)}.rk-f input,.rk-f select{height:40px}.rk-f input:focus,.rk-f select:focus,.rk-f textarea:focus,.rk-dlg>textarea:focus{outline:0;border-color:var(--o)}' +
			'.rk-ck{display:flex;align-items:center;gap:8px;font-size:14px}.rk-hint{font-size:12.5px;color:var(--mu);margin:10px 0 0}.rk-err{color:#B3261E;font-size:13px;margin:8px 0 0;min-height:1em}.rk-db{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}' +
			'.rk-dl{display:flex;flex-direction:column;max-height:180px;overflow:auto}.rk-dl button{border:0;border-bottom:1px solid #F0EBE3;background:#fff;text-align:left;padding:7px 4px;cursor:pointer;font:inherit}.rk-dl button:hover{background:#FAF8F5}.rk-dl b{display:block;font-weight:600;font-size:13.5px;overflow-wrap:anywhere}.rk-dl small{color:var(--mu);font-size:12px}' +
			'[data-rbody] [hidden],.rk-dlg [hidden]{display:none!important}' +
			'.rk-del{margin-right:auto;border:0;background:none;color:#B3261E;font:600 13px Inter,sans-serif;cursor:pointer;padding:6px 4px;text-decoration:underline;text-underline-offset:2px}.rk-del:focus-visible{outline:2px solid #B3261E;outline-offset:2px}' +
			'.rk-cat{font:800 13px Inter,sans-serif;text-transform:uppercase;letter-spacing:.05em;color:var(--mu);margin:18px 0 8px;padding-bottom:4px;border-bottom:1px solid var(--li)}.rk-cat:first-of-type{margin-top:4px}' +
			'.rk-tree{margin:-4px 0 12px;font-size:13px;color:var(--mu);line-height:1.5}.rk-tree b{color:var(--ink)}.rk-tree span{margin:0 4px}.rk-ex{display:block;color:#1E6B39;font-size:12px;margin-top:3px}.rk-ex[hidden]{display:none}' +
			'.rk-lab{display:flex;align-items:center;gap:14px;margin:4px auto 0;width:min(100%,390px);aspect-ratio:70/37;box-sizing:border-box;padding:12px 14px;border:1.5px dashed #C9C4BB;border-radius:10px;background:#fff;text-align:left}.rk-lq{flex:none;width:44%;aspect-ratio:1}.rk-lq svg{width:100%;height:100%;display:block}' +
			'.rk-lt{display:flex;flex-direction:column;gap:2px;min-width:0;color:#141C33}.rk-lt b{font-size:15px;line-height:1.2;overflow-wrap:anywhere}.rk-lt span{font-size:13px;font-weight:600;color:#141C33;overflow-wrap:anywhere}.rk-lt i{font-style:normal;font-weight:700;font-size:13px;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.rk-lt i[hidden]{display:none}.rk-lt small{font-size:13px;font-weight:600;color:#141C33;letter-spacing:.02em}.rk-lt em{font-style:normal;font-weight:800;font-size:13px;margin-top:2px}' +
			'.rk-card{width:min(480px,100%)}.rk-ch{display:flex;align-items:flex-start;gap:10px}.rk-ch>div{flex:1;min-width:0}.rk-ch h3{margin:0;font:800 20px/1.25 Inter,sans-serif;overflow-wrap:anywhere}.rk-ch p{margin:3px 0 0;color:var(--mu);font-size:13.5px}.rk-x{border:0;background:#F1EFEB;width:34px;height:34px;border-radius:50%;font-size:20px;cursor:pointer;flex:none}' +
			'.rk-where{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;margin:14px 0 4px;padding:12px;border-radius:12px;background:#F3FAF5;font-size:15px}.rk-where.out{background:#FFF6E6}.rk-where a{flex-basis:100%;font-size:13px;color:var(--ink);overflow-wrap:anywhere}.rk-ctag{margin:8px 0 0;font-weight:700;font-size:15px;overflow-wrap:anywhere}' +
			'.rk-kvs{margin:10px 0 0}.rk-kv{display:flex;gap:10px;padding:6px 0;border-bottom:1px solid #F0EBE3;font-size:14px}.rk-kv span{color:var(--mu);min-width:7.5em}.rk-kv b{font-weight:600;overflow-wrap:anywhere;min-width:0}' +
			'.rk-acts{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0 6px}.rk-acts .cpx-btn{flex:1 1 180px;min-height:46px;font-size:15px}.rk-cst{display:flex;align-items:center;gap:10px;margin:8px 0;font-size:14px;color:var(--mu)}.rk-cst select{flex:1;height:40px;border:1.5px solid var(--li);border-radius:10px;padding:0 8px;font:inherit;color:var(--ink);background:#fff}' +
			'.rk-chist h4{margin:14px 0 4px;font:700 12px Inter,sans-serif;text-transform:uppercase;letter-spacing:.05em;color:var(--mu)}.rk-chist ul{list-style:none;margin:0;padding:0}.rk-chist li{display:grid;grid-template-columns:auto 1fr;gap:0 10px;padding:5px 0;font-size:13px;border-bottom:1px solid #F0EBE3}.rk-chist time{color:var(--mu);font-variant-numeric:tabular-nums}.rk-chist span{overflow-wrap:anywhere}.rk-chist small{grid-column:2;color:var(--mu)}.rk-chist .cpx-muted{font-size:13px}' +
			'.rk-kit{background:#fff;border:1px solid var(--li);border-radius:14px;padding:14px 16px;margin:0 0 12px}.rk-kh{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px}.rk-kh h3{margin:0;font:800 16px Inter,sans-serif;overflow-wrap:anywhere;margin-right:auto}.rk-kn{margin:4px 0 0;font-size:13px;color:var(--mu)}' +
			'.rk-kl{list-style:none;margin:10px 0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:4px 14px}.rk-kl li{display:flex;justify-content:space-between;gap:8px;font-size:13.5px;padding:4px 0;border-bottom:1px solid #F0EBE3;min-width:0}.rk-kl li span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rk-kl small{color:var(--mu);white-space:nowrap}.rk-ka{display:flex;flex-wrap:wrap;align-items:center;gap:8px}' +
			'.rk-h4{margin:14px 0 6px;font:700 12px Inter,sans-serif;text-transform:uppercase;letter-spacing:.05em;color:var(--mu)}.rk-kq{width:100%;box-sizing:border-box;height:38px;border:1.5px solid var(--li);border-radius:10px;padding:0 10px;font:inherit;margin-bottom:6px}.rk-ck[hidden]{display:none}.rk-ck.off{opacity:.5}.rk-ck small.w{color:#9B2A1F}' +
			'.rk-ks{display:flex;gap:6px;align-items:center;margin:0 0 6px}.rk-ks select{flex:1;min-width:0;height:38px;border:1.5px solid var(--li);border-radius:10px;padding:0 6px;font:inherit}.rk-ks input{width:80px;height:38px;border:1.5px solid var(--li);border-radius:10px;padding:0 8px;font:inherit}.rk-ks2{display:flex;align-items:center;gap:8px;margin:0 0 6px;font-size:14px}.rk-ks2 span{flex:1;min-width:0;overflow-wrap:anywhere}.rk-ks2 input{width:90px;height:36px;border:1.5px solid var(--li);border-radius:10px;padding:0 8px;font:inherit}.rk-ks2 small{color:var(--mu);min-width:3em}' +
			'.rk-tagf{margin-top:12px;text-align:left}' +
			'.rk-scan .rk-dlg{width:min(460px,100%)}.rk-cam{position:relative;background:#000;border-radius:12px;overflow:hidden;aspect-ratio:1/1}.rk-cam video{width:100%;height:100%;object-fit:cover;display:block}.rk-cam i{position:absolute;inset:18%;border:3px solid rgba(255,255,255,.9);border-radius:16px;box-shadow:0 0 0 999px rgba(0,0,0,.35)}.rk-cam[hidden]{display:none}.rk-man{display:flex;gap:8px;margin-top:10px}.rk-man input{flex:1;min-width:0;height:38px;border:1.5px solid var(--li);border-radius:10px;padding:0 10px;font:inherit;text-transform:uppercase}' +
			'.rk-bar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 14px;font-size:13px;color:var(--mu)}.rk-qrd{width:min(440px,100%);text-align:center}.rk-qrd .rk-db{justify-content:center;flex-wrap:wrap}.rk-qr{width:240px;height:240px;margin:0 auto}.rk-qr svg{width:100%;height:100%;display:block}' +
			'.rk-qpl{max-height:46vh;overflow:auto;margin-top:8px}.rk-qpl fieldset{border:0;padding:0;margin:0 0 10px}.rk-qpl legend{font:600 12.5px Inter,sans-serif;color:var(--mu);padding:0;margin-bottom:4px}.rk-qpl .rk-ck{padding:3px 0}.rk-qpl small{color:var(--mu)}' +
			'@media (max-width:640px){.rk-g2{grid-template-columns:1fr}.rk-t thead{display:none}.rk-t,.rk-t tbody,.rk-t tr,.rk-t td{display:block;width:auto}.rk-t tr{padding:8px 4px;border-bottom:1px solid #F0EBE3}.rk-t td{border:0;padding:2px 8px;text-align:left}.rk-n,.rk-a{text-align:left}.rk-a{white-space:normal}.rk-a .rk-lnk{padding-left:0;margin-right:12px}.rk-t td[data-l]::before{content:attr(data-l) ": ";color:var(--mu);font-size:12.5px}.rk-seg{width:100%}.rk-seg button{flex:1}}';
		document.head.appendChild(st);
	}
	window.CPRaktar = { view, status, refresh: load };
})();
