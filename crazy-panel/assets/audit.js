/* Crazy panel – Eszközök → Napló (v1.46): ki, mikor, mit csinált a panelben. Csak az adminok látják. */
(function () {
	'use strict';
	const C = window.CPX || {};
	if (!C.au || !C.au.on) return;
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const S = { main: null, f: { who: '', area: '', from: '', to: '', q: '' }, p: 0, data: null, err: '' };
	const AREAS = [['', 'Minden terület'], ['deal', 'Deal-ek'], ['levelezes', 'Levelezés'], ['feladatok', 'Feladatok'], ['elszamolas', 'Elszámolás'], ['tarca', 'Tárca'], ['naptar', 'Naptár'], ['kovetes', 'Követés'], ['stat', 'Statisztikák'], ['rendszer', 'Belépés és beállítások']];
	const AN = {}; AREAS.forEach(a => { AN[a[0]] = a[1]; });

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
	function qs() {
		const o = Object.assign({}, S.f);
		Object.keys(o).forEach(k => { if (o[k] === '') delete o[k]; });
		return o;
	}
	function csvUrl() {
		const u = new URLSearchParams(Object.assign({ action: 'cp_audit_csv', t: C.t }, qs()));
		return C.ajax + '?' + u.toString();
	}
	/* a bejegyzés tárgya: deal / levélszál / kártya megnyitása */
	function objLink(o) {
		const m = /^(deal|mail|card):(\d+)$/.exec(o || '');
		if (!m) return '';
		const t = { deal: 'deal megnyitása', mail: 'levél megnyitása', card: 'kártya megnyitása' }[m[1]];
		return ' <a href="#" class="au-go" data-k="' + m[1] + '" data-id="' + m[2] + '">' + t + ' →</a>';
	}
	function go(k, id) {
		if (k === 'deal' && window.CPCrm && window.CPCrm.open) { window.CPCrm.open(id); return; }
		if (k === 'mail' && window.CPMail && window.CPMail.open) { window.CPMail.open(id); return; }
		if (k === 'card' && window.CPNotify && window.CPNotify.go) { window.CPNotify.go('feladatok:' + id); return; }
		if (k === 'card' && window.CPKanban) { if (window.CPXGo) window.CPXGo('feladatok'); setTimeout(() => window.CPKanban.deep(String(id)), 60); }
	}
	function when(s) { return s ? String(s).slice(0, 16).replace(/-/g, '.').replace(' ', '. ') : ''; }

	async function view(main) {
		S.main = main;
		main.innerHTML = '<div class="au"><div class="au-head"><h2>Napló</h2><p>Ki, mikor, mit csinált a panelben: deal-ek, levelezés, feladatok, elszámolás, tárca, be- és kilépés. Csak az adminok látják; a bejegyzéseket nem lehet módosítani, 2 év után törlődnek.</p></div>' +
			'<div class="au-f"><select data-f="who"><option value="">Mindenki</option></select><select data-f="area">' + AREAS.map(a => '<option value="' + a[0] + '">' + a[1] + '</option>').join('') + '</select>' +
			'<label>Ettől <input type="date" data-f="from"></label><label>Eddig <input type="date" data-f="to"></label><input type="search" data-f="q" placeholder="Keresés (pl. deal neve)…">' +
			'<a class="cpx-btn ghost au-csv" href="#" download>CSV letöltése</a></div><div class="au-box"><div class="cpx-muted">Betöltés…</div></div></div>';
		main.querySelectorAll('[data-f]').forEach(el => {
			el.value = S.f[el.dataset.f];
			let tm = null;
			el.addEventListener(el.tagName === 'INPUT' && el.type === 'search' ? 'input' : 'change', () => {
				clearTimeout(tm);
				tm = setTimeout(() => { S.f[el.dataset.f] = el.value.trim(); S.p = 0; load(); }, el.type === 'search' ? 350 : 0);
			});
		});
		main.querySelector('.au-csv').addEventListener('click', e => { e.currentTarget.href = csvUrl(); });
		await load();
	}
	async function load() {
		const main = S.main;
		if (!main || !main.isConnected) return;
		const box = main.querySelector('.au-box');
		let r;
		try { r = await post('cp_audit_list', Object.assign({ p: S.p }, qs())); S.err = ''; } catch (e) { S.err = e.message; box.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		S.data = r;
		const sel = main.querySelector('[data-f="who"]');
		sel.innerHTML = '<option value="">Mindenki</option>' + r.whos.map(w => '<option' + (w === S.f.who ? ' selected' : '') + '>' + esc(w) + '</option>').join('');
		const pages = Math.max(1, Math.ceil(r.total / 100));
		box.innerHTML = r.rows.length ?
			'<div class="au-n">' + r.total + ' bejegyzés' + (pages > 1 ? ' · ' + (r.p + 1) + '. oldal / ' + pages : '') + '</div><div class="au-tw"><table class="au-t"><thead><tr><th>Időpont</th><th>Ki</th><th>Terület</th><th>Mit csinált</th></tr></thead><tbody>' +
			r.rows.map(l => '<tr class="a-' + esc(l.action) + '"><td class="au-at">' + esc(when(l.at)) + '</td><td class="au-who">' + esc(l.who || '–') + (l.role ? '<small>' + esc(l.role) + '</small>' : '') + '</td><td class="au-ar">' + esc(AN[l.area] || l.area) + '</td><td><b>' + esc(l.label) + '</b>' +
				(l.summary ? '<div class="au-s">' + esc(l.summary) + '</div>' : '') + objLink(l.obj) + '</td></tr>').join('') + '</tbody></table></div>' +
			(pages > 1 ? '<div class="au-pg"><button type="button" class="cpx-btn ghost" data-pg="-1"' + (r.p ? '' : ' disabled') + '>← Újabbak</button><button type="button" class="cpx-btn ghost" data-pg="1"' + (r.p + 1 < pages ? '' : ' disabled') + '>Régebbiek →</button></div>' : '') :
			'<p class="cpx-muted">' + (Object.keys(qs()).length ? 'Nincs a szűrésnek megfelelő bejegyzés.' : 'Még nincs bejegyzés. Az első belépéstől vagy módosítástól itt látod, ki mit csinált.') + '</p>';
		box.querySelectorAll('.au-go').forEach(a => a.addEventListener('click', e => { e.preventDefault(); go(a.dataset.k, Number(a.dataset.id)); }));
		box.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => { S.p = Math.max(0, S.p + Number(b.dataset.pg)); load(); main.scrollIntoView && window.scrollTo(0, 0); }));
	}
	function status(s) { s.textContent = S.err || (S.data ? S.data.total + ' naplóbejegyzés' : ''); s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function refresh() { if (S.main && S.main.isConnected) await load(); }
	window.CPAudit = { view, status, refresh };

	const css = document.createElement('style');
	css.textContent = '.au{max-width:1100px}.au-head h2{margin:0 0 4px;font:800 20px "Plus Jakarta Sans",sans-serif}.au-head p{margin:0 0 10px;font-size:13.5px;color:var(--ink)}' +
		'.au-f{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}.au-f select,.au-f input{border:1px solid var(--li);border-radius:8px;padding:6px 8px;font:13px Inter,sans-serif;background:#fff;color:var(--ink)}.au-f label{font-size:12.5px;color:var(--mu);display:inline-flex;gap:4px;align-items:center}' +
		'.au-f input[type=search]{flex:1;min-width:180px}.au-csv{margin-left:auto;text-decoration:none}.au-n{font-size:12.5px;color:var(--mu);margin-bottom:6px}' +
		'.au-tw{background:#fff;border:1px solid var(--li);border-radius:12px;overflow:auto}.au-t{width:100%;border-collapse:collapse;font-size:13px}.au-t th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--mu);padding:8px 10px;border-bottom:1px solid var(--li);background:#FAF8F5}' +
		'.au-t td{padding:7px 10px;border-bottom:1px solid #F0ECE6;vertical-align:top}.au-at{white-space:nowrap;color:var(--mu);font-variant-numeric:tabular-nums}.au-who{white-space:nowrap;font-weight:600}.au-who small{display:block;font-weight:400;color:var(--mu);font-size:11.5px}.au-ar{white-space:nowrap;color:var(--mu)}' +
		'.au-s{color:var(--ink);opacity:.85;word-break:break-word;margin-top:1px}.au-go{color:#2F5BD8;font-weight:600;text-decoration:none;font-size:12.5px}.a-login_fail td{background:#FDECEA}.au-pg{display:flex;gap:8px;justify-content:center;margin-top:10px}' +
		'@media (max-width:760px){.au-csv{margin-left:0}.au-t th:nth-child(3),.au-t td:nth-child(3){display:none}}';
	document.head.appendChild(css);
})();
