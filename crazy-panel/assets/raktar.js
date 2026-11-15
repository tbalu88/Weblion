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
	const SUBS = [['stock', 'Készlet'], ['assets', 'Eszközök'], ['moves', 'Mozgások']];

	function status(s) { if (!s) return; s.textContent = S.err || ''; s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function load() {
		try { S.d = await post('cp_wh_data', {}); S.err = ''; } catch (e) { S.err = e.message; }
		draw(); deepOpen();
	}
	function view(main) {
		css(); S.main = main;
		main.innerHTML = '<div class="rk"><div class="rk-h"><div><h2>Raktár</h2><p class="rk-lead">Fogyóanyag és eszközök: mi van, hol van, mi ment ki melyik rendezvényre.</p></div>' +
			'<div class="rk-ha"><button type="button" class="cpx-btn sm" data-rnew="stock">+ Új cikk</button><button type="button" class="cpx-btn ghost sm" data-rnew="asset">+ Új eszköz</button><button type="button" class="cpx-btn ghost sm" data-rlocs>Helyek</button></div></div>' +
			'<div class="rk-tb"><div class="rk-seg" role="tablist" aria-label="Nézet">' + SUBS.map(s => '<button type="button" role="tab" data-rsub="' + s[0] + '" aria-selected="' + (S.sub === s[0]) + '">' + s[1] + '</button>').join('') + '</div>' +
			'<input type="search" data-rq placeholder="Keresés: név, cikkszám, sorozatszám, hely…" aria-label="Keresés a raktárban" value="' + esc(S.q) + '"><select data-rcat aria-label="Kategória"><option value="">Minden kategória</option></select></div>' +
			'<div data-rbody><div class="cpx-empty">Betöltés…</div></div></div>';
		let qt = 0;
		main.querySelector('[data-rq]').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { S.q = e.target.value.trim().toLowerCase(); draw(); }, 150); });
		main.querySelector('[data-rcat]').addEventListener('change', e => { S.cat = e.target.value; draw(); });
		main.querySelectorAll('[data-rsub]').forEach(b => b.addEventListener('click', () => { S.sub = b.dataset.rsub; keep(); main.querySelectorAll('[data-rsub]').forEach(x => x.setAttribute('aria-selected', String(x === b))); draw(); }));
		main.querySelectorAll('[data-rnew]').forEach(b => b.addEventListener('click', () => b.dataset.rnew === 'asset' ? unitForm(null) : itemForm(null, 'stock')));
		main.querySelector('[data-rlocs]').addEventListener('click', locsForm);
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
		if (S.sub === 'assets') body.innerHTML = drawAssets(); else if (S.sub === 'moves') { body.innerHTML = '<div class="cpx-empty">Betöltés…</div>'; drawMoves(body); } else body.innerHTML = drawStock();
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
				'<td class="rk-a"><button type="button" class="rk-lnk" data-rmv="in" data-rid="' + i.id + '">Beérkezés</button><button type="button" class="rk-lnk" data-rmv="out" data-rid="' + i.id + '">Kiadás</button><button type="button" class="rk-lnk" data-rmore="' + i.id + '" aria-label="További műveletek: ' + esc(i.name) + '">Több…</button></td></tr>';
		}).join('') + '</tbody></table>';
	}
	/* ---- Eszközök: típusonként a darabok */
	function drawAssets() {
		const types = (S.d.items || []).filter(i => i.kind === 'asset' && (!S.cat || i.cat === S.cat));
		if (!types.length) return '<div class="rk-empty"><p><b>Még nincs eszköz.</b> Vedd fel a gépeket, kamerákat, nyomtatókat sorozatszámmal – így látszik, melyik hol van, és melyik rendezvényen volt.</p><button type="button" class="cpx-btn sm" data-rnew2="asset">+ Első eszköz felvétele</button></div>';
		const ST = S.d.status || {}, D = S.d.deals || {};
		const bulk = (S.d.units || []).length ? '<div class="rk-bar"><button type="button" class="cpx-btn ghost sm" data-rqrall>QR-címkék nyomtatása…</button><span>A kód telefonnal beolvasva a panelben ezt az eszközt nyitja meg.</span></div>' : '';
		const out = types.map(t => {
			const U = (S.d.units || []).filter(u => u.item_id === t.id && hit(t.name, u.label, u.serial, u.model, u.loc, u.qr));
			if (!U.length && S.q) return '';
			const ok = U.filter(u => u.status === 'ok').length;
			return '<section class="rk-g"><h3><button type="button" class="rk-nm" data-ritem="' + t.id + '">' + esc(t.name) + '</button><small>' + U.length + ' db' + (U.length !== ok ? ' · ' + ok + ' használható' : '') + '</small></h3>' +
				(U.length ? '<table class="rk-t"><thead><tr><th>Eszköz</th><th>Sorozatszám</th><th>Hol</th><th>Állapot</th><th class="rk-a"><span class="rk-sr">Műveletek</span></th></tr></thead><tbody>' + U.map(u => {
					const svc = u.svc_limit > 0 && u.counter - u.svc_base >= u.svc_limit;
					return '<tr><td><button type="button" class="rk-nm" data-runit="' + u.id + '">' + esc(u.label || t.name) + '</button><small>' + esc([u.model, 'QR ' + u.qr].filter(Boolean).join(' · ')) + '</small></td>' +
						'<td class="rk-sn" data-l="Sorozatszám">' + (u.serial ? esc(u.serial) : '<span class="cpx-muted">–</span>') + '</td>' +
						'<td>' + esc(u.loc || '–') + (u.deal_id && D[u.deal_id] ? '<small><a href="#deals:' + u.deal_id + '">' + esc(D[u.deal_id].t) + '</a></small>' : '') + '</td>' +
						'<td><span class="rk-chip ' + (u.status === 'ok' ? (svc ? 'mid' : 'ok') : u.status === 'svc' || u.status === 'bad' ? 'mid' : 'bad') + '">' + esc(svc && u.status === 'ok' ? 'szerviz esedékes' : ST[u.status] || u.status) + '</span></td>' +
						'<td class="rk-a"><button type="button" class="rk-lnk" data-rqr="' + u.id + '">QR</button><button type="button" class="rk-lnk" data-rumv="' + u.id + '">' + (u.loc === S.d.ev ? 'Visszavétel' : 'Kiadás / áthelyezés') + '</button><button type="button" class="rk-lnk" data-rhist="' + u.id + '">Előzmények</button></td></tr>';
				}).join('') + '</tbody></table>' : '<p class="cpx-muted rk-pad">Ebből a típusból még nincs darab. <button type="button" class="rk-lnk" data-raddu="' + t.id + '">+ Darab felvétele</button></p>') + '</section>';
		}).join('');
		return bulk + (out || '<p class="cpx-empty">Nincs találat.</p>');
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
		if (b.dataset.rnew2) return b.dataset.rnew2 === 'asset' ? unitForm(null) : itemForm(null, 'stock');
		if (b.dataset.raddu) return unitForm({ item_id: Number(b.dataset.raddu) });
		if (b.dataset.rmv) return moveForm(item(b.dataset.rid), null, b.dataset.rmv);
		if (b.dataset.rmore) return moveForm(item(b.dataset.rmore), null, 'more');
		if (b.dataset.rumv) { const u = unit(b.dataset.rumv); return moveForm(item(u.item_id), u, u.loc === S.d.ev ? 'back' : 'move'); }
		if (b.dataset.rhist) { const u = unit(b.dataset.rhist); S.mf = { unit_id: u.id, label: (u.label || '') + (u.serial ? ' (' + u.serial + ')' : '') }; S.sub = 'moves'; view(S.main); return; }
		if (b.hasAttribute('data-rmfx')) { S.mf = null; draw(); }
		if (b.dataset.rqr) return qrOne(unit(b.dataset.rqr));
		if (b.hasAttribute('data-rqrall')) return qrPick();
	}
	/* ---- párbeszédablak */
	function dlg(title, html, onSave, saveTxt) {
		const ov = document.createElement('div'); ov.className = 'rk-ov';
		ov.innerHTML = '<form class="rk-dlg" role="dialog" aria-modal="true" aria-label="' + esc(title) + '"><h3>' + esc(title) + '</h3>' + html + '<p class="rk-err" data-derr role="alert"></p><div class="rk-db"><button type="button" class="cpx-btn ghost sm" data-dx>Mégse</button><button class="cpx-btn sm" data-dok>' + esc(saveTxt || 'Mentés') + '</button></div></form>';
		document.body.appendChild(ov);
		const f = ov.querySelector('form'), close = () => { ov.remove(); document.removeEventListener('keydown', ek); };
		const ek = ev => { if (ev.key === 'Escape') close(); };
		document.addEventListener('keydown', ek);
		ov.addEventListener('mousedown', ev => { if (ev.target === ov) close(); });
		ov.querySelector('[data-dx]').addEventListener('click', close);
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
			async f => { await post('cp_wh_item', { id: x.id || 0, kind: i ? x.kind : val(f, 'kind'), name: val(f, 'name'), cat: val(f, 'cat'), unit: val(f, 'unit'), min_qty: val(f, 'min_qty'), price: val(f, 'price'), supplier: val(f, 'supplier'), sku: val(f, 'sku'), per_print: val(f, 'per_print'), note: val(f, 'note'), inactive: f.elements.inactive && f.elements.inactive.checked ? 1 : '' }); return 'Mentve.'; });
	}
	function unitForm(u) {
		const types = (S.d && S.d.items || []).filter(i => i.kind === 'asset' && i.active);
		const x = u || {}, ST = (S.d && S.d.status) || {};
		if (!types.length) { toast('Előbb vegyél fel egy eszköztípust (pl. „Nikon D5300 váz”) – utána jöhetnek a darabok.'); return itemForm(null, 'asset'); }
		dlg(x.id ? (x.label || 'Eszköz') : 'Új eszköz', '<div class="rk-g2">' +
			fld('Típus', '<select name="item_id">' + types.map(t => '<option value="' + t.id + '"' + (t.id === x.item_id ? ' selected' : '') + '>' + esc(t.name) + '</option>').join('') + '</select>', 1) +
			fld('Név / címke', inp('label', x.label, ' maxlength="120" placeholder="pl. Szelfigép 3 kamerája"')) + fld('Sorozatszám', inp('serial', x.serial, ' maxlength="120" autocomplete="off"')) +
			fld('Gyári típus', inp('model', x.model, ' maxlength="160" placeholder="pl. Nikon D5300"')) +
			fld('Állapot', '<select name="status">' + Object.entries(ST).map(s => '<option value="' + s[0] + '"' + ((x.status || 'ok') === s[0] ? ' selected' : '') + '>' + esc(s[1]) + '</option>').join('') + '</select>') +
			fld('Számláló (exponálás / nyomat)', inp('counter', x.counter || '', ' inputmode="numeric"')) + fld('Szervizhatár (0 = nincs)', inp('svc_limit', x.svc_limit || '', ' inputmode="numeric" placeholder="pl. 100000"')) +
			fld('Megjegyzés', '<textarea name="note" rows="2" maxlength="500">' + esc(x.note || '') + '</textarea>', 1) +
			(x.id && x.svc_limit ? '<label class="rk-ck w"><input type="checkbox" name="svc_done"> Szerviz megtörtént (a számláló innen indul újra)</label>' : '') + '</div>' +
			(x.id ? '<p class="rk-hint">Hely: <b>' + esc(x.loc || '–') + '</b> · QR-kód: <b>' + esc(x.qr) + '</b> · A helyet a „Kiadás / áthelyezés” változtatja.</p>' : '<p class="rk-hint">Az új eszköz a fő raktárba kerül (' + esc(S.d.locs[0]) + ').</p>'),
			async f => { await post('cp_wh_unit', { id: x.id || 0, item_id: val(f, 'item_id'), label: val(f, 'label'), serial: val(f, 'serial'), model: val(f, 'model'), status: val(f, 'status'), counter: val(f, 'counter'), svc_limit: val(f, 'svc_limit'), note: val(f, 'note'), svc_done: f.elements.svc_done && f.elements.svc_done.checked ? 1 : '' }); return 'Mentve.'; });
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
		S.sub = 'assets'; keep(); S.q = String(u.serial || u.qr).toLowerCase(); view(S.main); setTimeout(() => unitForm(u), 50);
	}
	async function qrOne(u) {
		if (!u) return; try { await qrLib(); } catch (e) { toast(e.message, true); return; }
		const q = qrMake(u), t = item(u.item_id);
		const ov = document.createElement('div'); ov.className = 'rk-ov';
		ov.innerHTML = '<div class="rk-dlg rk-qrd" role="dialog" aria-modal="true" aria-label="QR-kód"><h3>' + esc(uName(u)) + '</h3><div class="rk-qr" role="img" aria-label="QR-kód: ' + esc(u.qr) + '">' + qrSvg(q, 240) + '</div>' +
			'<p class="rk-hint">' + esc([t && t.name !== uName(u) ? t.name : '', u.serial ? 'S/N ' + u.serial : '', 'Kód: ' + u.qr].filter(Boolean).join(' · ')) + '</p>' +
			'<div class="rk-db"><button type="button" class="cpx-btn ghost sm" data-qx>Bezárás</button><button type="button" class="cpx-btn ghost sm" data-qpng>PNG letöltése</button><button type="button" class="cpx-btn sm" data-qprint>Címke nyomtatása</button></div></div>';
		document.body.appendChild(ov);
		const close = () => { ov.remove(); document.removeEventListener('keydown', k); }, k = e => { if (e.key === 'Escape') close(); };
		document.addEventListener('keydown', k);
		ov.addEventListener('click', e => { if (e.target === ov || e.target.closest('[data-qx]')) close(); else if (e.target.closest('[data-qpng]')) qrPng(u, q); else if (e.target.closest('[data-qprint]')) qrPrint([u]); });
		ov.querySelector('[data-qprint]').focus();
	}
	function qrPng(u, q) {
		const n = q.getModuleCount(), W = 900, cell = Math.floor(560 / n), qs = cell * n, ox = Math.round((W - qs) / 2), oy = 50, t = item(u.item_id);
		const c = document.createElement('canvas'); c.width = W; c.height = oy + qs + 210; const g = c.getContext('2d');
		g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.fillStyle = '#111';
		for (let r = 0; r < n; r++) for (let k = 0; k < n; k++) if (q.isDark(r, k)) g.fillRect(ox + k * cell, oy + r * cell, cell, cell);
		g.textAlign = 'center'; g.fillStyle = '#141C33';
		let fs = 44; const nm = uName(u); do { g.font = '700 ' + fs + 'px Inter, "Segoe UI", sans-serif'; fs -= 2; } while (g.measureText(nm).width > W - 80 && fs > 18);
		g.fillText(nm, W / 2, oy + qs + 70);
		g.font = '500 28px Inter, "Segoe UI", sans-serif'; g.fillStyle = '#4A5060'; g.fillText([u.serial ? 'S/N ' + u.serial : (t ? t.name : ''), 'Kód: ' + u.qr].filter(Boolean).join('  ·  '), W / 2, oy + qs + 125);
		c.toBlob(b => { if (!b) { toast('A PNG nem készült el.', true); return; } const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'eszkoz-qr-' + u.qr + '.png'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000); }, 'image/png');
	}
	/* A4-es címkelap: 3 × 8 címke (70 × 37 mm), a kiválasztott eszközökről */
	async function qrPrint(U) {
		if (!U.length) return; try { await qrLib(); } catch (e) { toast(e.message, true); return; }
		const w = window.open('', '_blank'); if (!w) { toast('A böngésző letiltotta az új ablakot – engedélyezd a felugró ablakot a panelnek.', true); return; }
		const lab = u => { const t = item(u.item_id); return '<div class="l"><div class="q">' + qrSvg(qrMake(u), 120) + '</div><div class="t"><b>' + esc(uName(u)) + '</b>' + (t && t.name !== uName(u) ? '<span>' + esc(t.name) + '</span>' : '') + (u.serial ? '<span>S/N ' + esc(u.serial) + '</span>' : '') + '<small>' + esc(u.qr) + ' · EventCrazy</small></div></div>'; };
		w.document.write('<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Eszköz QR-címkék (' + U.length + ' db)</title><style>@page{size:A4;margin:12mm 0 0 0}body{margin:0;font-family:Inter,"Segoe UI",Arial,sans-serif;color:#141C33}' +
			'.p{display:grid;grid-template-columns:repeat(3,70mm);grid-auto-rows:37mm;justify-content:center}.l{box-sizing:border-box;display:flex;align-items:center;gap:2.5mm;padding:2.5mm 3mm;overflow:hidden;break-inside:avoid;outline:.2mm dashed #ccc}' +
			'.q{flex:none;width:28mm;height:28mm}.q svg{width:100%;height:100%;display:block}.t{display:flex;flex-direction:column;gap:.6mm;min-width:0}.t b{font-size:9.5pt;line-height:1.15;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}' +
			'.t span{font-size:7.5pt;color:#333;overflow-wrap:anywhere}.t small{font-size:7pt;color:#666;letter-spacing:.04em}@media print{.l{outline:0}.no{display:none}}.no{text-align:center;padding:10px;font-size:14px}</style></head><body>' +
			'<p class="no">' + U.length + ' címke · A4, 3 × 8 címke (70 × 37 mm) – nyomtatás: Ctrl+P / ⌘P, méretezés: 100% („Tényleges méret”).</p><div class="p">' + U.map(lab).join('') + '</div><script>setTimeout(function(){window.print()},400)<\/script></body></html>');
		w.document.close();
	}
	function qrPick() {
		const types = (S.d.items || []).filter(i => i.kind === 'asset'), U = S.d.units || [];
		const f = dlg('QR-címkék nyomtatása', '<p class="rk-hint" style="margin-top:0">Jelöld ki, melyik eszközökről kérsz címkét (A4-es lap, 3 × 8 címke).</p><label class="rk-ck"><input type="checkbox" data-qa checked> Mind</label><div class="rk-qpl">' +
			types.map(t => { const L = U.filter(u => u.item_id === t.id && u.status !== 'gone'); return L.length ? '<fieldset><legend>' + esc(t.name) + '</legend>' + L.map(u => '<label class="rk-ck"><input type="checkbox" name="u" value="' + u.id + '" checked> ' + esc(u.label || t.name) + (u.serial ? ' <small>S/N ' + esc(u.serial) + '</small>' : '') + '</label>').join('') + '</fieldset>' : ''; }).join('') + '</div>',
			async f2 => { const ids = Array.from(f2.querySelectorAll('[name=u]:checked')).map(x => Number(x.value)); if (!ids.length) throw new Error('Jelölj ki legalább egy eszközt.'); await qrPrint(U.filter(u => ids.includes(u.id))); return ''; }, 'Címkelap megnyitása');
		f.querySelector('[data-qa]').addEventListener('change', e => f.querySelectorAll('[name=u]').forEach(x => { x.checked = e.target.checked; }));
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
			'.rk-off td{opacity:.55}.rk-g{margin:0 0 18px}.rk-g h3{display:flex;align-items:baseline;gap:10px;margin:0 0 6px;font-size:15px}.rk-g h3 small{font:600 12.5px Inter,sans-serif;color:var(--mu)}.rk-pad{margin:4px 0 0}' +
			'.rk-empty{background:#fff;border:1px dashed var(--li);border-radius:14px;padding:22px;max-width:60ch}.rk-empty p{margin:0 0 12px}.rk-flt{font-size:13px;color:var(--mu);margin:0 0 8px}.rk-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}' +
			'.rk-ov{position:fixed;inset:0;z-index:10070;background:rgba(20,28,51,.4);display:flex;align-items:center;justify-content:center;padding:16px}' +
			'.rk-dlg{background:#fff;border-radius:18px;padding:22px;width:min(560px,100%);max-height:92vh;overflow:auto;box-shadow:0 24px 60px rgba(20,28,51,.3)}.rk-dlg h3{margin:0 0 14px;font:700 17px/1.3 Inter,sans-serif;overflow-wrap:anywhere}' +
			'.rk-g2{display:grid;grid-template-columns:1fr 1fr;gap:10px 12px}.rk-f{display:flex;flex-direction:column;gap:4px;min-width:0}.rk-f.w,.rk-g2 .w{grid-column:1/-1}.rk-f>span{font:600 12.5px Inter,sans-serif;color:var(--mu)}' +
			'.rk-f input,.rk-f select,.rk-f textarea,.rk-dlg>textarea{width:100%;box-sizing:border-box;border:1.5px solid var(--li);border-radius:10px;padding:8px 10px;font:inherit;background:#fff;color:var(--ink)}.rk-f input,.rk-f select{height:40px}.rk-f input:focus,.rk-f select:focus,.rk-f textarea:focus,.rk-dlg>textarea:focus{outline:0;border-color:var(--o)}' +
			'.rk-ck{display:flex;align-items:center;gap:8px;font-size:14px}.rk-hint{font-size:12.5px;color:var(--mu);margin:10px 0 0}.rk-err{color:#B3261E;font-size:13px;margin:8px 0 0;min-height:1em}.rk-db{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}' +
			'.rk-dl{display:flex;flex-direction:column;max-height:180px;overflow:auto}.rk-dl button{border:0;border-bottom:1px solid #F0EBE3;background:#fff;text-align:left;padding:7px 4px;cursor:pointer;font:inherit}.rk-dl button:hover{background:#FAF8F5}.rk-dl b{display:block;font-weight:600;font-size:13.5px;overflow-wrap:anywhere}.rk-dl small{color:var(--mu);font-size:12px}' +
			'[data-rbody] [hidden],.rk-dlg [hidden]{display:none!important}' +
			'.rk-bar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 14px;font-size:13px;color:var(--mu)}.rk-qrd{width:min(380px,100%);text-align:center}.rk-qrd .rk-db{justify-content:center;flex-wrap:wrap}.rk-qr{width:240px;height:240px;margin:0 auto}.rk-qr svg{width:100%;height:100%;display:block}' +
			'.rk-qpl{max-height:46vh;overflow:auto;margin-top:8px}.rk-qpl fieldset{border:0;padding:0;margin:0 0 10px}.rk-qpl legend{font:600 12.5px Inter,sans-serif;color:var(--mu);padding:0;margin-bottom:4px}.rk-qpl .rk-ck{padding:3px 0}.rk-qpl small{color:var(--mu)}' +
			'@media (max-width:640px){.rk-g2{grid-template-columns:1fr}.rk-t thead{display:none}.rk-t,.rk-t tbody,.rk-t tr,.rk-t td{display:block;width:auto}.rk-t tr{padding:8px 4px;border-bottom:1px solid #F0EBE3}.rk-t td{border:0;padding:2px 8px;text-align:left}.rk-n,.rk-a{text-align:left}.rk-a{white-space:normal}.rk-a .rk-lnk{padding-left:0;margin-right:12px}.rk-t td[data-l]::before{content:attr(data-l) ": ";color:var(--mu);font-size:12.5px}.rk-seg{width:100%}.rk-seg button{flex:1}}';
		document.head.appendChild(st);
	}
	window.CPRaktar = { view, status, refresh: load };
})();
