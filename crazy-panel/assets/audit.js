/* Crazy panel – Eszközök → Napló (v1.46): ki, mikor, mit csinált a panelben. Csak az adminok látják. */
(function () {
	'use strict';
	const C = window.CPX || {};
	if (!C.au || !C.au.on) return;
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const S = { seq: 0, main: null, f: { who: '', area: '', from: '', to: '', q: '' }, p: 0, data: null, err: '' };
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
		/* v2.9.60 (P3): a Feladatok modul igény szerint töltődik */
		if (k === 'card' && (window.CPKanban || (window.CPXHas && window.CPXHas('kanban')))) { if (window.CPXGo) window.CPXGo('feladatok'); (window.CPXNeed ? window.CPXNeed('kanban') : Promise.resolve()).then(() => setTimeout(() => { if (window.CPKanban) window.CPKanban.deep(String(id)); }, 60), () => { /* a fül maga mutatja a hibát */ }); }
	}
	function when(s) { return s ? String(s).slice(0, 16).replace(/-/g, '.').replace(' ', '. ') : ''; }

	/* v2.9.88: nézetváltó – Műveletek (a napló) | Panelhasználat (ki mennyi időt töltött a panelben, mikor) */
	const segH = () => '<div class="crm-seg au-seg" role="tablist"><button type="button" role="tab" data-mode="log" aria-selected="' + (S.mode !== 'use') + '" class="' + (S.mode !== 'use' ? 'on' : '') + '">Műveletek</button><button type="button" role="tab" data-mode="use" aria-selected="' + (S.mode === 'use') + '" class="' + (S.mode === 'use' ? 'on' : '') + '">Panelhasználat</button></div>';
	function bindSeg(main) { main.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { if (S.mode === b.dataset.mode) return; S.mode = b.dataset.mode; try { localStorage.setItem('cpx_au_mode', S.mode); } catch (e) { /* privát mód */ } view(main); })); }
	async function view(main) {
		S.main = main;
		if (!S.mode) { try { S.mode = localStorage.getItem('cpx_au_mode') || 'log'; } catch (e) { S.mode = 'log'; } }
		if (S.mode === 'use') { useView(main); return; }
		main.innerHTML = '<div class="au"><div class="au-head"><div class="au-ht"><h2>Napló</h2>' + segH() + '</div><p>Ki, mikor, mit csinált a panelben: deal-ek, levelezés, feladatok, elszámolás, tárca, be- és kilépés. Csak az adminok látják; a bejegyzéseket nem lehet módosítani, 2 év után törlődnek.</p></div>' +
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
		bindSeg(main);
		await load();
	}

	/* ------------------------------------------------------------ v2.9.88: Panelhasználat */
	const pad2 = n => String(n).padStart(2, '0');
	const ymd = d => d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
	const hm = m => pad2(Math.floor(m / 60)) + ':' + pad2(m % 60);
	const dur = m => { m = Number(m) || 0; if (!m) return '–'; const h = Math.floor(m / 60); return (h ? h + ' ó ' : '') + pad2(m % 60) + ' p'; };
	const DOW = ['V', 'H', 'K', 'Sze', 'Cs', 'P', 'Szo'];
	const dLabel = s => { const d = new Date(s + 'T12:00:00'); return s.slice(5).replace('-', '.') + '. ' + DOW[d.getDay()]; };
	function presets() {
		const t = new Date(); t.setHours(12, 0, 0, 0);
		const mon = new Date(t); mon.setDate(t.getDate() - ((t.getDay() + 6) % 7));
		const pm = new Date(mon); pm.setDate(mon.getDate() - 7); const ps = new Date(mon); ps.setDate(mon.getDate() - 1);
		const m1 = new Date(t.getFullYear(), t.getMonth(), 1, 12), lm1 = new Date(t.getFullYear(), t.getMonth() - 1, 1, 12), lme = new Date(t.getFullYear(), t.getMonth(), 0, 12);
		return [['ma', 'Ma', ymd(t), ymd(t)], ['het', 'Ez a hét', ymd(mon), ymd(t)], ['eh', 'Előző hét', ymd(pm), ymd(ps)], ['ho', 'Ez a hónap', ymd(m1), ymd(t)], ['eho', 'Előző hónap', ymd(lm1), ymd(lme)]];
	}
	const U = { pre: 'het', from: '', to: '', uid: 0, d: null, seq: 0, err: '' };
	function useView(main) {
		if (!U.from) { const p = presets().find(x => x[0] === U.pre); U.from = p[2]; U.to = p[3]; }
		main.innerHTML = '<div class="au"><div class="au-head"><div class="au-ht"><h2>Napló</h2>' + segH() + '</div><p>Ki mennyi időt töltött a panelben, és mikor. <b>Aktív</b>: dolgozott a panelben (egér, billentyű, görgetés, érintés). <b>Nyitva</b>: a panel látható ablakban volt. Csak a személyes fiókokat méri, és csak az adminok látják; a percenkénti részletek 90 napig, a napi összesítők 2 évig maradnak meg.</p></div>' +
			'<div class="au-f"><div class="crm-seg au-pre">' + presets().map(p => '<button type="button" data-pre="' + p[0] + '" class="' + (U.pre === p[0] ? 'on' : '') + '">' + p[1] + '</button>').join('') + '</div>' +
			'<label>Ettől <input type="date" data-uf="from" value="' + U.from + '"></label><label>Eddig <input type="date" data-uf="to" value="' + U.to + '"></label></div><div class="au-box"><div class="cpx-muted">Betöltés…</div></div></div>';
		bindSeg(main);
		main.querySelectorAll('[data-pre]').forEach(b => b.addEventListener('click', () => { const p = presets().find(x => x[0] === b.dataset.pre); U.pre = p[0]; U.from = p[2]; U.to = p[3]; useView(main); }));
		main.querySelectorAll('[data-uf]').forEach(i => i.addEventListener('change', () => { if (!i.value) return; U[i.dataset.uf] = i.value; U.pre = ''; main.querySelectorAll('[data-pre]').forEach(b => b.classList.remove('on')); useLoad(); }));
		useLoad();
	}
	async function useLoad() {
		const main = S.main; if (!main || !main.isConnected || S.mode !== 'use') return;
		const box = main.querySelector('.au-box'); const my = ++U.seq;
		let r;
		try { r = await post('cp_us_stats', { from: U.from, to: U.to, uid: U.uid || '' }); } catch (e) { if (my !== U.seq) return; U.err = e.message; box.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		if (my !== U.seq || !main.isConnected) return;
		U.err = ''; U.d = r; U.uid = r.uid;
		const any = r.users.some(u => u.open);
		const sel = r.users.find(u => u.id === r.uid);
		box.innerHTML = '<div class="au-tw"><table class="au-t au-ut"><thead><tr><th>Ki</th><th class="r">Aktív</th><th class="r">Nyitva</th><th class="r">Napok</th><th class="r">Aktív / nap</th><th>Utoljára</th></tr></thead><tbody>' +
			r.users.map(u => '<tr data-u="' + u.id + '" class="' + (u.id === r.uid ? 'on' : '') + (u.open ? '' : ' nil') + '" tabindex="0"><td class="au-who">' + (u.now ? '<i class="au-live" title="Most a panelben"></i>' : '') + esc(u.n) + '</td><td class="r"><b>' + dur(u.act) + '</b></td><td class="r">' + dur(u.open) + '</td><td class="r">' + (u.days || '–') + '</td><td class="r">' + (u.days ? dur(Math.round(u.act / u.days)) : '–') + '</td><td class="au-at">' + (u.now ? 'most a panelben' : esc(when(u.last))) + '</td></tr>').join('') + '</tbody></table></div>' +
			(!any ? '<p class="cpx-muted">Ebben az időszakban még nincs adat. A mérés a 2.9.88-as frissítéssel indult; ettől kezdve minden személyes fiók panelhasználata ide kerül.</p>' : '') +
			(sel ? '<h3 class="au-h3">' + esc(sel.n) + ' – napi idővonal' + (U.from < r.detail_from ? ' <small>(a 90 napnál régebbi napoknál csak az összesítő van meg)</small>' : '') + '</h3>' +
				(r.days.length ? '<div class="au-tl"><div class="au-tlh"><span></span><div class="au-ax">' + [0, 6, 12, 18, 24].map(h => '<i style="left:' + (h / 24 * 100) + '%">' + h + '</i>').join('') + '</div><span></span></div>' +
					r.days.map(d => '<div class="au-tlr"><span class="au-dl">' + dLabel(d.d) + '<small>' + hm(d.first) + '–' + hm(d.last) + '</small></span><div class="au-bar">' + bar(d.bar) + '</div><span class="au-tn"><b>' + dur(d.act) + '</b><small>' + dur(d.open) + ' nyitva</small></span></div>').join('') +
					'<div class="au-lg"><span><i class="a2"></i>aktív</span><span><i class="a1"></i>nyitva, de nem dolgozott benne</span></div></div>' : '<p class="cpx-muted">Ebben az időszakban nem használta a panelt.</p>') : '');
		box.querySelectorAll('[data-u]').forEach(tr => { const go = () => { U.uid = Number(tr.dataset.u); useLoad(); }; tr.addEventListener('click', go); tr.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } }); });
	}
	/* 288 db 5 perces sáv → összefüggő szakaszok (0 nincs, 1 nyitva, 2 aktív) */
	function bar(s) {
		if (!s) return '';
		let out = '', i = 0;
		while (i < s.length) { const c = s[i]; let j = i; while (j < s.length && s[j] === c) j++; if (c !== '0') out += '<i class="a' + c + '" style="left:' + (i / 2.88) + '%;width:' + ((j - i) / 2.88) + '%" title="' + hm(i * 5) + '–' + hm(Math.min(1440, j * 5)) + (c === '2' ? ' aktív' : ' nyitva') + '"></i>'; i = j; }
		return out;
	}
	async function load() {
		const main = S.main;
		if (!main || !main.isConnected) return;
		const box = main.querySelector('.au-box');
		let r;
		/* v2.9.58: kérés-sorszám – gyors szűrő-/lapváltásnál a régebbi válasz nem írja felül az újat */
		const my = ++S.seq;
		try { r = await post('cp_audit_list', Object.assign({ p: S.p }, qs())); } catch (e) { if (my !== S.seq) return; S.err = e.message; box.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		if (my !== S.seq || !main.isConnected) return;
		S.err = '';
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
	function status(s) { if (S.mode === 'use') { s.textContent = U.err || ''; s.className = 'cpx-status' + (U.err ? ' err' : ''); return; } s.textContent = S.err || (S.data ? S.data.total + ' naplóbejegyzés' : ''); s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function refresh() { if (S.main && S.main.isConnected) { if (S.mode === 'use') await useLoad(); else await load(); } }
	window.CPAudit = { view, status, refresh };

	const css = document.createElement('style');
	css.textContent = '.au{max-width:1100px}.au-head h2{margin:0 0 4px;font:800 20px "Plus Jakarta Sans",sans-serif}.au-head p{margin:0 0 10px;font-size:13.5px;color:var(--ink)}' +
		'.au-f{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}.au-f select,.au-f input{border:1px solid var(--li);border-radius:8px;padding:6px 8px;font:13px Inter,sans-serif;background:#fff;color:var(--ink)}.au-f label{font-size:12.5px;color:var(--mu);display:inline-flex;gap:4px;align-items:center}' +
		'.au-f input[type=search]{flex:1;min-width:180px}.au-csv{margin-left:auto;text-decoration:none}.au-n{font-size:12.5px;color:var(--mu);margin-bottom:6px}' +
		'.au-tw{background:#fff;border:1px solid var(--li);border-radius:12px;overflow:auto}.au-t{width:100%;border-collapse:collapse;font-size:13px}.au-t th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--mu);padding:8px 10px;border-bottom:1px solid var(--li);background:#FAF8F5}' +
		'.au-t td{padding:7px 10px;border-bottom:1px solid #F0ECE6;vertical-align:top}.au-at{white-space:nowrap;color:var(--mu);font-variant-numeric:tabular-nums}.au-who{white-space:nowrap;font-weight:600}.au-who small{display:block;font-weight:400;color:var(--mu);font-size:11.5px}.au-ar{white-space:nowrap;color:var(--mu)}' +
		'.au-s{color:var(--ink);opacity:.85;word-break:break-word;margin-top:1px}.au-go{color:#2F5BD8;font-weight:600;text-decoration:none;font-size:12.5px}.a-login_fail td{background:#FDECEA}.au-pg{display:flex;gap:8px;justify-content:center;margin-top:10px}' +
		'.au-ht{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:4px}.au-ht h2{margin:0}.au-pre button{white-space:nowrap}.au-t .r{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}' +
		'.au-ut tbody tr{cursor:pointer}.au-ut tbody tr:hover td{background:#FAF8F5}.au-ut tr.on td{background:#EEF3FD}.au-ut tr.nil td{color:var(--mu)}.au-ut tr:focus-visible{outline:2px solid #2F5BD8;outline-offset:-2px}' +
		'.au-live{display:inline-block;width:8px;height:8px;border-radius:50%;background:#17A34A;margin-right:6px;box-shadow:0 0 0 3px #DDF3E6;vertical-align:1px}.au-h3{font:700 15px "Plus Jakarta Sans",sans-serif;margin:18px 0 8px}.au-h3 small{font:400 12px Inter,sans-serif;color:var(--mu)}' +
		'.au-tl{background:#fff;border:1px solid var(--li);border-radius:12px;padding:10px 12px}.au-tlh,.au-tlr{display:grid;grid-template-columns:96px minmax(0,1fr) 104px;gap:10px;align-items:center}.au-tlr{padding:5px 0;border-top:1px solid #F0ECE6}.au-ax{position:relative;height:16px;font-size:10.5px;color:var(--mu)}.au-ax i{position:absolute;transform:translateX(-50%);font-style:normal}.au-ax i:first-child{transform:none}.au-ax i:last-child{transform:translateX(-100%)}' +
		'.au-dl{font-size:12.5px;font-weight:600;font-variant-numeric:tabular-nums}.au-dl small,.au-tn small{display:block;font-weight:400;color:var(--mu);font-size:11px}.au-tn{text-align:right;font-size:12.5px;font-variant-numeric:tabular-nums}' +
		'.au-bar{position:relative;height:16px;border-radius:4px;background:repeating-linear-gradient(90deg,#F5F3EF 0,#F5F3EF calc(25% - 1px),#E6E3DE calc(25% - 1px),#E6E3DE 25%)}.au-bar i{position:absolute;top:0;bottom:0;min-width:2px}.au-bar i.a1,.au-lg i.a1{background:#BFD0F6}.au-bar i.a2,.au-lg i.a2{background:#2F5BD8}' +
		'.au-lg{display:flex;gap:14px;margin-top:8px;font-size:11.5px;color:var(--mu)}.au-lg i{display:inline-block;width:12px;height:8px;border-radius:2px;margin-right:5px}' +
		'@media (max-width:760px){.au-tlh,.au-tlr{grid-template-columns:70px minmax(0,1fr) 70px;gap:6px}.au-ut th:nth-child(3),.au-ut td:nth-child(3),.au-ut th:nth-child(4),.au-ut td:nth-child(4),.au-ut th:nth-child(5),.au-ut td:nth-child(5){display:none}.au-ut td.au-who{white-space:normal;overflow-wrap:anywhere}.au-ut .au-at{white-space:normal}}' +
		'@media (max-width:760px){.au-csv{margin-left:0}.au-t th:nth-child(3),.au-t td:nth-child(3){display:none}}';
	document.head.appendChild(css);
})();
