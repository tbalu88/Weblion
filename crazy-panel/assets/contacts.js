/* Crazy panel – Kapcsolatok fül (v1.49): kontaktok és cégek, a deal-jeik számával és a megnyert összeggel; v2.4: duplikátum-javaslatok és összevonás */
(function () {
	'use strict';
	const C = window.CPX || {};
	if (!C.crm || !C.crm.on) return;
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const HU_MS = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
	const huD = s => { if (!s) return ''; const p = String(s).slice(0, 10).split('-').map(Number); return p[0] + '. ' + HU_MS[p[1] - 1] + ' ' + p[2] + '.'; };
	const money = v => !v ? '–' : Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft';
	const ini = n => String(n || '').split(/[\s@]+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?';
	const AVC = ['#F6B48F', '#93D5B4', '#A9C1F6', '#E3B5E6', '#F3D27F', '#9FD8E5', '#F2A5A5'];
	const S = { kind: 'ct', q: '', so: 'recent', only: true, p: 0, data: null, err: '', main: null, seq: 0, sel: new Map(), dup: false, dupN: { ct: null, co: null } };
	/* v2.2: kijelölés és törlés – csak admin, ha a Salesmate-be írás be van kapcsolva */
	const DEL = C.role === 'admin' && !!(C.crm && C.crm.write);
	/* v2.4: összevonás – ugyanígy csak admin; a javaslatokat mindenki látja és jelölheti „Nem ugyanaz”-nak */
	const MRG = DEL;
	function toast(t, bad) { let d = document.getElementById('cpx-toast'); if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); } d.textContent = t; d.classList.toggle('bad', !!bad); d.classList.add('on'); clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), bad ? 8000 : 4000); }
	try { const k = JSON.parse(localStorage.getItem('cpx_people') || '{}'); if (k.kind === 'co' || k.kind === 'ct') S.kind = k.kind; if (typeof k.so === 'string') S.so = k.so; if (typeof k.only === 'boolean') S.only = k.only; } catch (e) { /* privát mód */ }
	const save = () => { try { localStorage.setItem('cpx_people', JSON.stringify({ kind: S.kind, so: S.so, only: S.only })); } catch (e) { /* privát mód */ } };

	async function post(action, params) {
		const fd = new FormData();
		fd.append('action', action);
		fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j = null;
		try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j && j.data && j.data.msg) || 'Hiba'));
		return j.data;
	}
	const SORTS = [['recent', 'Legutóbbi deal'], ['event', 'Legutóbbi rendezvény'], ['deals', 'Legtöbb deal'], ['won', 'Legtöbb bevétel'], ['name', 'Név (A–Z)']];
	function view(main) {
		S.main = main;
		main.innerHTML = '<div class="pp"><div class="pp-h"><div class="crm-seg"><button type="button" data-k="ct" class="' + (S.kind === 'ct' ? 'on' : '') + '">Kontaktok</button><button type="button" data-k="co" class="' + (S.kind === 'co' ? 'on' : '') + '">Cégek</button></div>' +
			'<input type="search" data-q placeholder="' + (S.kind === 'ct' ? 'Keresés: név, e-mail, telefon, város, cég' : 'Keresés: cégnév, weboldal') + '" value="' + esc(S.q) + '" aria-label="Keresés">' +
			'<select data-so aria-label="Rendezés"' + (S.dup ? ' style="display:none"' : '') + '>' + SORTS.map(x => '<option value="' + x[0] + '"' + (S.so === x[0] ? ' selected' : '') + '>' + x[1] + '</option>').join('') + '</select>' +
			'<label class="crm-chk" title="Keresés közben mindenkit mutat"' + (S.dup ? ' style="display:none"' : '') + '><input type="checkbox" data-only' + (S.only ? ' checked' : '') + '> Csak akiknek van deal-je</label>' +
			'<button type="button" class="cpx-btn ghost pp-dupb' + (S.dup ? ' on' : '') + '" data-dup title="A rendszer által talált lehetséges duplikátumok">⧉ Duplikátumok<span class="pp-dupn" data-dupn></span></button></div><div class="pp-body"><div class="cpx-empty">Betöltés…</div></div></div>';
		main.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', () => { if (S.kind === b.dataset.k) return; S.kind = b.dataset.k; S.p = 0; S.sel.clear(); save(); view(main); }));
		let t = null;
		main.querySelector('[data-q]').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => { S.q = e.target.value.trim(); S.p = 0; load(); }, 300); });
		main.querySelector('[data-so]').addEventListener('change', e => { S.so = e.target.value; S.p = 0; save(); load(); });
		main.querySelector('[data-only]').addEventListener('change', e => { S.only = e.target.checked; S.p = 0; save(); load(); });
		main.querySelector('[data-dup]').addEventListener('click', () => { S.dup = !S.dup; view(main); });
		if (S.dup) { main.querySelector('[data-q]').placeholder = 'Szűrés a javaslatok között (név, e-mail, cég)'; }
		dupBadge();
		load();
	}
	async function load() {
		if (S.dup) return loadDup();
		const my = ++S.seq, main = S.main;
		if (!main || !main.isConnected) return;
		const body = main.querySelector('.pp-body');
		let r;
		/* v2.2.1: keresés közben a „Csak akiknek van deal-je” szűrő nem rejti el a találatokat (deal nélküli kontaktot is megtalál) */
		try { r = await post('cp_crm_people', { kind: S.kind, q: S.q, so: S.so, only: S.only && !S.q ? 1 : '', p: S.p }); S.err = ''; } catch (e) { if (my === S.seq) { S.err = e.message; body.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; } return; }
		if (my !== S.seq) return;
		S.data = r;
		const pages = Math.max(1, Math.ceil(r.total / r.n)), ct = r.kind === 'ct';
		const allOn = r.rows.length && r.rows.every(x => S.sel.has(x.id));
		body.innerHTML = '<div class="pp-n">' + r.total.toLocaleString('hu-HU') + (ct ? ' kontakt' : ' cég') + (pages > 1 ? ' · ' + (r.p + 1) + '. oldal / ' + pages : '') + (S.q && S.only ? ' · keresésnél a deal nélküliek is látszanak' : '') + '</div>' +
			(DEL ? '<div data-bulk>' + bulkHtml() + '</div>' : '') +
			(r.rows.length ? '<div class="pp-tw"><table class="pp-t"><thead><tr>' + (DEL ? '<th class="pp-ck"><input type="checkbox" data-all aria-label="Az oldal összes sorának kijelölése"' + (allOn ? ' checked' : '') + '></th>' : '') + '<th>' + (ct ? 'Kontakt' : 'Cég') + '</th><th class="pp-hm">' + (ct ? 'Cég' : 'Kontaktok') + '</th><th class="r">Deal</th><th class="r pp-hm">Megnyert</th><th class="r">Bevétel</th><th class="pp-hm">Legutóbbi rendezvény</th></tr></thead><tbody>' +
				r.rows.map(x => '<tr tabindex="0" data-id="' + x.id + '"' + (S.sel.has(x.id) ? ' class="ck"' : '') + '>' + (DEL ? '<td class="pp-ck"><input type="checkbox" data-ck="' + x.id + '" aria-label="Kijelölés"' + (S.sel.has(x.id) ? ' checked' : '') + '></td>' : '') + '<td class="pp-nc"><span class="pp-av' + (ct ? '' : ' sq') + '" style="background:' + AVC[x.id % AVC.length] + '">' + esc(ini(x.name || x.email)) + '</span><span class="pp-nm"><a class="pp-ln" href="#kapcsolatok:' + (ct ? 'ct' : 'co') + ':' + x.id + '" data-pl><b>' + esc(x.name || x.email || '#' + x.id) + '</b></a><small>' + esc(ct ? [x.name && x.email !== x.name ? x.email : '', x.mobile || x.phone, x.city].filter(Boolean).join(' · ') : (x.website || '')) + '</small></span></td>' +
					'<td class="pp-hm">' + (ct ? (x.co ? '<a href="#" data-co="' + x.co_id + '">' + esc(x.co) + '</a>' : '<span class="cpx-muted">–</span>') : x.cts) + '</td><td class="r">' + x.n + (x.open ? ' <small class="pp-open">' + x.open + ' nyitott</small>' : '') + '</td><td class="r pp-hm">' + x.won + '</td><td class="r">' + esc(money(x.won_v)) + '</td><td class="pp-hm">' + esc(huD(x.last_ev)) + '</td></tr>').join('') + '</tbody></table></div>' +
				(pages > 1 ? '<div class="pp-pg"><button type="button" class="cpx-btn ghost" data-pg="-1"' + (r.p ? '' : ' disabled') + '>← Előző</button><button type="button" class="cpx-btn ghost" data-pg="1"' + (r.p + 1 < pages ? '' : ' disabled') + '>Következő →</button></div>' : '') :
				'<p class="cpx-muted">' + (S.q ? 'Nincs találat.' : 'Még nincs ' + (ct ? 'kontakt' : 'cég') + '.') + '</p>');
		body.querySelectorAll('tr[data-id]').forEach(tr => {
			const go = () => { const id = Number(tr.dataset.id); if (ct) window.CPCrm.contact(id); else window.CPCrm.company(id); };
			/* v2.9.104: a név valódi link – Ctrl/Cmd/középső kattintással új lapon nyílik, sima kattintásra itt */
			tr.addEventListener('click', e => { if (e.target.closest('[data-co]') || e.target.closest('.pp-ck')) return; const a = e.target.closest('[data-pl]'); if (a && (e.metaKey || e.ctrlKey || e.shiftKey || e.button)) return; e.preventDefault(); go(); });
			tr.addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
		});
		body.querySelectorAll('[data-co]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); window.CPCrm.company(Number(a.dataset.co)); }));
		body.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => { S.p = Math.max(0, S.p + Number(b.dataset.pg)); load(); window.scrollTo(0, 0); }));
		if (DEL) {
			/* v2.9.58: a pipálás helyben frissít (sorjelölés, „mind” pipa, műveletsáv) – nem tölti újra a listát */
			const sync = () => {
				body.querySelectorAll('tr[data-id]').forEach(tr => { const on = S.sel.has(Number(tr.dataset.id)); tr.classList.toggle('ck', on); const c = tr.querySelector('[data-ck]'); if (c) c.checked = on; });
				const all = body.querySelector('[data-all]'); if (all) all.checked = !!(r.rows.length && r.rows.every(x => S.sel.has(x.id)));
				const bk = body.querySelector('[data-bulk]'); if (!bk) return;
				bk.innerHTML = bulkHtml();
				const clr = bk.querySelector('[data-clr]'); if (clr) clr.addEventListener('click', () => { S.sel.clear(); sync(); });
				const del = bk.querySelector('[data-del]'); if (del) del.addEventListener('click', () => delSel(ct));
				const mb = bk.querySelector('[data-mrg]'); if (mb) mb.addEventListener('click', () => mergeDlg(ct ? 'ct' : 'co', Array.from(S.sel.keys())));
			};
			body.querySelectorAll('[data-ck]').forEach(c => c.addEventListener('change', () => { const x = r.rows.find(z => z.id === Number(c.dataset.ck)); if (c.checked) S.sel.set(x.id, x); else S.sel.delete(x.id); sync(); }));
			const all = body.querySelector('[data-all]');
			if (all) all.addEventListener('change', () => { r.rows.forEach(x => { if (all.checked) S.sel.set(x.id, x); else S.sel.delete(x.id); }); sync(); });
			sync();
		}
		if (window.CPXStatus) window.CPXStatus();
	}
	/* a kijelölés műveletsávja (üres, ha nincs kijelölés) */
	function bulkHtml() {
		return S.sel.size ? '<div class="pp-bulk"><b>' + S.sel.size + ' kijelölve</b><button type="button" class="cpx-btn ghost" data-clr>Kijelölés törlése</button>' + (MRG ? '<button type="button" class="cpx-btn" data-mrg' + (S.sel.size < 2 || S.sel.size > 6 ? ' disabled title="2–6 rekordot jelölj ki"' : '') + '>⇄ Összevonás</button>' : '') + '<button type="button" class="cpx-btn danger" data-del>🗑 Kijelöltek törlése</button></div>' : '';
	}
	async function delSel(ct) {
		const L = Array.from(S.sel.values()), n = L.length, what = ct ? 'kontakt' : 'cég';
		if (!n) return;
		const withD = ct ? L.filter(x => Number(x.n) > 0) : [], dn = withD.reduce((a, x) => a + Number(x.n), 0);
		const names = L.slice(0, 8).map(x => '• ' + (x.name || x.email || '#' + x.id)).join('\n') + (n > 8 ? '\n… és még ' + (n - 8) : '');
		if (!window.confirm('Törlöd a kijelölt ' + n + ' ' + what + 'ot?\n\n' + names + '\n\nA Salesmate-ből is törlődnek (ott a Lomtárból 30 napig visszaállíthatók).' + (!ct ? '\nA hozzájuk tartozó kontaktok és deal-ek megmaradnak, csak cég nélkül.' : ''))) return;
		let deals = false;
		if (withD.length) {
			deals = window.confirm(withD.length + ' kijelölt kontakthoz összesen ' + dn + ' deal tartozik.\n\nOK = a deal-jeik is törlődnek.\nMégse = ezek a kontaktok kimaradnak (a deal nélküliek törlődnek).');
		}
		const typed = window.prompt('A törlés végleges. A megerősítéshez írd be: TÖRLÉS', '');
		if (typed === null) return;
		if (typed.trim().toUpperCase() !== 'TÖRLÉS') { toast('Nem a „TÖRLÉS” szót írtad be – nem töröltem semmit.', true); return; }
		toast('Törlés folyamatban… (' + n + ' ' + what + ')');
		try {
			const r = await post('cp_crm_people_delete', { kind: ct ? 'ct' : 'co', ids: L.map(x => x.id).join(','), deals: deals ? 1 : '', ok: 'TÖRLÉS' });
			let m = r.ok + ' ' + what + ' törölve' + (r.deals ? ', ' + r.deals + ' deal is' : '') + '.';
			if (r.skip && r.skip.length) m += ' Kimaradt: ' + r.skip.slice(0, 5).join(', ') + (r.skip.length > 5 ? '…' : '') + '.';
			if (r.err && r.err.length) m += ' Hiba: ' + r.err.slice(0, 3).join('; ');
			toast(m, !!(r.err && r.err.length));
			S.sel.clear();
		} catch (e) { toast(e.message, true); }
		load();
	}

	/* ------------------------------------------------------------ v2.4: lehetséges duplikátumok és összevonás */
	const LVL = { 'erős': 'hi', 'közepes': 'md', 'gyenge': 'lo' };
	async function dupBadge(force) {
		const k = S.kind, el = () => S.main && S.main.querySelector('[data-dupn]');
		if (S.dupN[k] == null || force) { try { const r = await post('cp_crm_dups', { kind: k }); S.dupN[k] = r.n; } catch (e) { return; } }
		const b = el(); if (b && S.kind === k) b.textContent = S.dupN[k] ? String(S.dupN[k]) : '';
	}
	function recHtml(x, ct) {
		const sub = ct ? [x.email, x.mobile || x.phone, x.co, x.city].filter(Boolean) : [x.website, x.phone, x.cts ? x.cts + ' kontakt' : ''].filter(Boolean);
		return '<div class="pd-r"><b>' + esc(x.name || x.email || '#' + x.id) + '</b><small>' + esc(sub.join(' · ')) + '</small><small class="pd-m">' + x.n + ' deal' + (x.last_ev ? ' · utolsó rendezvény: ' + esc(huD(x.last_ev)) : '') + ' · létrehozva: ' + esc(huD(x.created_at)) + '</small></div>';
	}
	async function loadDup(fresh) {
		const my = ++S.seq, main = S.main;
		if (!main || !main.isConnected) return;
		const body = main.querySelector('.pp-body'), ct = S.kind === 'ct';
		let r;
		try { r = await post('cp_crm_dups', { kind: S.kind, fresh: fresh ? 1 : '' }); S.err = ''; } catch (e) { if (my === S.seq) { S.err = e.message; body.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; } return; }
		if (my !== S.seq) return;
		S.dupN[S.kind] = r.n; dupBadge();
		const q = S.q.toLowerCase();
		const L = !q ? r.pairs : r.pairs.filter(p => [p.A, p.B].some(x => [x.name, x.email, x.co, x.website].filter(Boolean).join(' ').toLowerCase().includes(q)));
		body.innerHTML = '<div class="pp-n">' + (r.n ? r.n + ' lehetséges duplikátum-pár' + (q ? ' · ' + L.length + ' illik a szűrésre' : '') : 'Nincs lehetséges duplikátum') + ' · <a href="#" data-fresh>Újrakeresés</a>' + (MRG ? '' : ' · összevonni admin fiókkal lehet') + '</div>' +
			'<p class="pd-help">A rendszer azonos e-mail-cím, telefonszám, név' + (ct ? '' : ', cégnév, weboldal vagy céges e-mail-domain') + ' alapján javasol. Amelyik nem ugyanaz, jelöld „Nem ugyanaz”-nak – többet nem jön elő.</p>' +
			L.map(p => '<div class="pd" data-a="' + p.a + '" data-b="' + p.b + '"><div class="pd-h"><span class="pd-l ' + (LVL[p.lvl] || 'lo') + '">' + esc(p.lvl) + '</span><span class="pd-w">' + esc(p.why.join(' · ')) + '</span></div>' +
				'<div class="pd-g"><a href="#" data-open="' + p.A.id + '">' + recHtml(p.A, ct) + '</a><span class="pd-x" aria-hidden="true">⇄</span><a href="#" data-open="' + p.B.id + '">' + recHtml(p.B, ct) + '</a></div>' +
				'<div class="pd-b"><button type="button" class="cpx-btn ghost" data-ign>Nem ugyanaz</button>' + (MRG ? '<button type="button" class="cpx-btn" data-m>⇄ Összevonás…</button>' : '') + '</div></div>').join('');
		body.querySelector('[data-fresh]').addEventListener('click', e => { e.preventDefault(); body.innerHTML = '<div class="cpx-empty">Keresés…</div>'; loadDup(true); });
		body.querySelectorAll('[data-open]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); const id = Number(a.dataset.open); if (ct) window.CPCrm.contact(id); else window.CPCrm.company(id); }));
		body.querySelectorAll('.pd').forEach(d => {
			const a = Number(d.dataset.a), b = Number(d.dataset.b);
			d.querySelector('[data-ign]').addEventListener('click', async () => {
				try { await post('cp_crm_dup_ignore', { kind: S.kind, a, b }); d.remove(); S.dupN[S.kind] = Math.max(0, (S.dupN[S.kind] || 1) - 1); dupBadge(); toast('Megjegyeztem: ez a kettő nem ugyanaz.'); } catch (e) { toast(e.message, true); }
			});
			const m = d.querySelector('[data-m]'); if (m) m.addEventListener('click', () => mergeDlg(S.kind, [a, b]));
		});
		if (window.CPXStatus) window.CPXStatus();
	}
	function closeDlg() { const m = document.getElementById('cpx-modal'); if (m) { if (window.cpxSheetMo) window.cpxSheetMo.close(m); else { m.hidden = true; m.innerHTML = ''; } } }
	async function mergeDlg(kind, ids) {
		const ct = kind === 'ct', m = document.getElementById('cpx-modal');
		if (!m) return;
		let rows;
		try { rows = (await post('cp_crm_merge_info', { kind, ids: ids.join(',') })).rows; } catch (e) { toast(e.message, true); return; }
		if (rows.length < 2) { toast('Ezek közül legalább az egyik közben megszűnt – frissítem a listát.', true); load(); return; }
		/* alapból a több deal-es, egyenlőségnél a régebbi marad meg */
		let keep = rows.slice().sort((x, y) => (y.n - x.n) || (x.id - y.id))[0].id;
		const what = ct ? 'kontakt' : 'cég', whatHoz = ct ? 'kontakthoz' : 'céghez';
		const draw = () => {
			const others = rows.filter(x => x.id !== keep), moved = others.reduce((a, x) => a + x.n, 0) + (ct ? 0 : others.reduce((a, x) => a + (x.cts || 0), 0));
			m.innerHTML = '<div class="cpx-sheet pd-dlg" role="dialog" aria-modal="true" aria-label="' + what + 'ok összevonása"><div class="cpx-sheet-h"><h3>' + (ct ? 'Kontaktok' : 'Cégek') + ' összevonása</h3><button class="cpx-x" type="button" data-x aria-label="Bezárás">×</button></div>' +
				'<p class="pd-help">Válaszd ki, melyik ' + what + ' maradjon meg. A többi adatai ebbe kerülnek, utána a többi törlődik.</p>' +
				rows.map(x => '<label class="pd-opt' + (x.id === keep ? ' on' : '') + '"><input type="radio" name="pdk" value="' + x.id + '"' + (x.id === keep ? ' checked' : '') + '>' + recHtml(x, ct) + (x.id === keep ? '<span class="pd-keep">Megmarad</span>' : '<span class="pd-gone">Beleolvad</span>') + '</label>').join('') +
				'<div class="pd-what"><b>Mi történik?</b><ul>' +
				'<li>' + (moved ? (ct ? moved + ' deal' : (others.reduce((a, x) => a + x.n, 0) + ' deal és ' + others.reduce((a, x) => a + (x.cts || 0), 0) + ' kontakt')) + ' átkerül a megmaradó ' + whatHoz + '.' : 'A beleolvadó ' + whatHoz + ' nem tartozik deal' + (ct ? '' : ' vagy kontakt') + '.') + '</li>' +
				'<li>A megmaradó ' + what + ' üres mezőit (' + (ct ? 'e-mail, telefon, cég, város' : 'weboldal, telefon') + ') a többiből tölti ki; az eltérő ' + (ct ? 'e-mail-cím és telefonszám' : 'weboldal és telefon') + ' a leírásba kerül, nem vész el.</li>' +
				'<li>A beleolvadó ' + what + ' törlődik (a Salesmate-ben a Lomtárból 30 napig visszaállítható). Ha valamit nem sikerül áthelyezni, nem törlöm.</li></ul></div>' +
				'<div class="pd-b"><button type="button" class="cpx-btn ghost" data-x>Mégse</button><button type="button" class="cpx-btn" data-go>⇄ Összevonás</button></div></div>';
			m.hidden = false;
			m.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', closeDlg));
			m.querySelectorAll('input[name=pdk]').forEach(r => r.addEventListener('change', () => { keep = Number(r.value); draw(); }));
			m.querySelector('[data-go]').addEventListener('click', async ev => {
				const b = ev.currentTarget; b.disabled = true; b.textContent = 'Összevonás folyamatban…';
				try {
					const r = await post('cp_crm_merge', { kind, keep, ids: rows.map(x => x.id).filter(i => i !== keep).join(',') });
					let t = r.ok ? r.ok + ' ' + what + ' összevonva' + (r.moved ? ', ' + r.moved + ' deal áthelyezve' : '') + '.' : 'Nem történt összevonás.';
					if (r.err && r.err.length) t += ' ' + r.err.join(' ');
					if (r.warn && r.warn.length) t += ' (' + r.warn.slice(0, 3).join('; ') + ')';
					toast(t, !!(r.err && r.err.length));
					closeDlg(); S.sel.clear(); S.dupN = { ct: null, co: null }; load(); dupBadge();
				} catch (e) { b.disabled = false; b.textContent = '⇄ Összevonás'; toast(e.message, true); }
			});
		};
		draw();
		m.onclick = e => { if (e.target === m) closeDlg(); };
	}
	function status(s) { s.textContent = S.err || ''; s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function refresh() { if (S.main && S.main.isConnected) await load(); }
	window.CPContacts = { view, status, refresh };

	const css = document.createElement('style');
	css.textContent = '.pp{max-width:1300px}.pp-h{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}.pp-h input[type=search]{flex:1;min-width:200px}.pp-h input[type=search],.pp-h select{border:1px solid #E6E3DE;border-radius:8px;padding:7px 9px;font:13px Inter,sans-serif;background:#fff;color:#141C33}' +
		'.pp-n{font-size:12.5px;color:#656973;margin-bottom:6px}.pp-tw{background:#fff;border:1px solid #E6E3DE;border-radius:12px;overflow:auto}.pp-t{width:100%;border-collapse:collapse;font-size:13px}' +
		'.pp-t th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#656973;padding:8px 10px;border-bottom:1px solid #E6E3DE;background:#FAF8F5;white-space:nowrap}.pp-t th.r,.pp-t td.r{text-align:right;white-space:nowrap}' +
		'.pp-t td{padding:7px 10px;border-bottom:1px solid #F0ECE6;vertical-align:middle}.pp-t tbody tr{cursor:pointer}.pp-t tbody tr:hover{background:#FAF8F5}.pp-t tbody tr:focus-visible{outline:2px solid #2F5BD8;outline-offset:-2px}' +
		'.pp-t td.pp-nc{display:flex;align-items:center;gap:10px;min-width:240px}.pp-av{flex:none;width:30px;height:30px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font:700 11.5px Inter,sans-serif;color:#1A1F2B}.pp-av.sq{border-radius:8px}' +
		'.pp-ln{color:inherit;text-decoration:none}.pp-ln:hover b{color:#2F5BD8;text-decoration:underline}.pp-nm{min-width:0}.pp-nm b{display:block;font-weight:600}.pp-nm small{display:block;color:#656973;font-size:12px}.pp-t a{color:#2F5BD8;text-decoration:none;font-weight:600}.pp-open{color:#2F5BD8;font-size:11.5px}.pp-pg{display:flex;gap:8px;justify-content:center;margin-top:10px}' +
		'.pp-t td.pp-ck,.pp-t th.pp-ck{width:34px;padding-right:0;min-width:0;display:table-cell}.pp-ck input{width:17px;height:17px;cursor:pointer;accent-color:#2F5BD8}.pp-t tbody tr.ck{background:#EEF3FD}' +
		'.pp-bulk{display:flex;flex-wrap:wrap;align-items:center;gap:8px;background:#EEF3FD;border:1px solid #C9D6FB;border-radius:10px;padding:8px 10px;margin-bottom:8px;font-size:13px}.pp-bulk b{margin-right:auto}.pp-bulk .cpx-btn{height:32px;font-size:12.5px}' +
		'.pp-dupb.on{background:#141C33;color:#fff;border-color:#141C33}.pp-dupn{font-weight:700;color:#C2410C;margin-left:5px}.pp-dupn:empty{display:none}.pp-dupb.on .pp-dupn{color:#FDBA74}.pd-help{font-size:12.5px;color:#656973;margin:0 0 10px}' +
		'.pd{background:#fff;border:1px solid #E6E3DE;border-radius:12px;padding:10px 12px;margin-bottom:8px}.pd-h{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px;font-size:12.5px;color:#4A4F5A}' +
		'.pd-l{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;border-radius:6px;padding:2px 7px}.pd-l.hi{background:#FDE7DA;color:#B4400E}.pd-l.md{background:#FDF3D2;color:#8A6200}.pd-l.lo{background:#EEF0F3;color:#5B6170}' +
		'.pd-g{display:grid;grid-template-columns:1fr auto 1fr;gap:10px;align-items:stretch}.pd-g>a{text-decoration:none;color:inherit;border:1px solid #F0ECE6;border-radius:9px;padding:8px 10px;background:#FAF8F5}.pd-g>a:hover{border-color:#C9D6FB}.pd-x{align-self:center;color:#656973;font-size:18px}' +
		'.pd-r b{display:block;font-weight:600;font-size:13.5px}.pd-r small{display:block;color:#656973;font-size:12px;overflow-wrap:anywhere}.pd-r small.pd-m{color:#656973;margin-top:2px}.pd-b{display:flex;gap:8px;justify-content:flex-end;margin-top:8px}.pd-b .cpx-btn{height:32px;font-size:12.5px}' +
		'.pd-dlg{max-width:620px}.pd-opt{display:flex;gap:10px;align-items:flex-start;border:1.5px solid #E6E3DE;border-radius:10px;padding:9px 11px;margin-bottom:8px;cursor:pointer}.pd-opt.on{border-color:#2F5BD8;background:#F3F6FE}.pd-opt input{margin-top:3px;accent-color:#2F5BD8}.pd-opt .pd-r{flex:1;min-width:0}' +
		'.pd-keep,.pd-gone{flex:none;font-size:11px;font-weight:700;border-radius:6px;padding:2px 7px;align-self:center}.pd-keep{background:#DDF3E6;color:#17663A}.pd-gone{background:#EEF0F3;color:#5B6170}.pd-what{font-size:13px;background:#FAF8F5;border-radius:10px;padding:9px 12px;margin:10px 0}.pd-what ul{margin:6px 0 0;padding-left:18px}.pd-what li{margin:3px 0}' +
		'@media (max-width:760px){.pd-g{grid-template-columns:1fr}.pd-x{justify-self:center;transform:rotate(90deg)}.pp-t .pp-hm{display:none}.pp-t td.pp-nc{min-width:0}.pp-ck input{width:20px;height:20px}}';
	document.head.appendChild(css);
})();
