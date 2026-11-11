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
	/* v2.9.89: saját címek – #naplo = Műveletek, #naplo:hasznalat = Panelhasználat (könyvjelzőzhető, küldhető) */
	/* v2.9.145: #naplo:elemzes = Elemzés */
	const auUrl = m => m === 'use' ? '#naplo:hasznalat' : m === 'an' ? '#naplo:elemzes' : '#naplo';
	function auHash() { const m = /^#naplo(?::(hasznalat|elemzes))?$/.exec(location.hash); return m ? (m[1] === 'hasznalat' ? 'use' : m[1] === 'elemzes' ? 'an' : 'log') : null; }
	const segH = () => '<nav class="crm-seg au-seg" aria-label="Nézet"><a href="#naplo" data-mode="log"' + (S.mode !== 'use' && S.mode !== 'an' ? ' class="on" aria-current="page"' : '') + '>Műveletek</a><a href="#naplo:hasznalat" data-mode="use"' + (S.mode === 'use' ? ' class="on" aria-current="page"' : '') + '>Panelhasználat</a><a href="#naplo:elemzes" data-mode="an"' + (S.mode === 'an' ? ' class="on" aria-current="page"' : '') + '>Elemzés</a></nav>';
	function nav(mode) {
		S.mode = mode;
		try { localStorage.setItem('cpx_au_mode', mode); } catch (e) { /* privát mód */ }
		if (location.hash !== auUrl(mode)) history.pushState({ cpAu: mode }, '', auUrl(mode));
		if (S.main && S.main.isConnected) view(S.main);
	}
	function bindSeg(main) { main.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', e => { if (e.metaKey || e.ctrlKey || e.shiftKey || e.button) return; e.preventDefault(); if (S.mode !== b.dataset.mode) nav(b.dataset.mode); })); }
	window.addEventListener('popstate', () => {
		const h = auHash(); if (!h) return;
		if (!(S.main && S.main.isConnected && S.main.querySelector('.au'))) { if (window.CPXGo) { S.force = h; window.CPXGo('naplo'); } return; }
		if (h !== S.mode) { S.mode = h; view(S.main); }
	});
	async function view(main) {
		S.main = main;
		const h = S.force || auHash(); S.force = null;
		if (h) S.mode = h;
		if (!S.mode) { try { S.mode = localStorage.getItem('cpx_au_mode') || 'log'; } catch (e) { S.mode = 'log'; } }
		if (location.hash !== auUrl(S.mode) && /^#naplo(:|$)/.test(location.hash || '#naplo')) history.replaceState(history.state, '', auUrl(S.mode));
		if (S.mode === 'use') { useView(main); return; }
		if (S.mode === 'an') { anView(main); return; }
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
	/* v2.9.93: fülnév a panel menüjéből (ami ott nincs, annak a kulcsa) */
	const TABN = { mail: 'Levelezés', deals: 'Deal-ek', feladatok: 'Feladatok', naptar: 'Naptár', kapcsolatok: 'Kapcsolatok', elszamolas: 'Elszámolás', tarca: 'Tárca', naplo: 'Napló', stat: 'Statisztikák', kezdolap: 'Kezdőlap', kezelok: 'Kezelők', munkanaplo: 'Munkanapló' };
	const tabName = k => { if (!k) return 'Fül nélkül (frissítés előtt)'; const b = document.querySelector('.cpx-tabs [data-tab="' + k + '"] span'); return (b && b.textContent.trim()) || TABN[k] || k; };
	const doingH = u => { const g = u.doing; if (!g) return ''; return '<span class="au-do">' + (g.st === 'reply' ? '✍ levelet ír · ' : '') + esc(tabName(g.tab)) + (g.o ? ' · ' + objA(g) : '') + '</span>'; };
	const objA = g => { const m = /^(mail|deal):(\d+)$/.exec(g.o || ''); return m ? '<a href="#" class="au-go" data-k="' + m[1] + '" data-id="' + m[2] + '">' + esc(g.ol) + '</a>' : esc(g.ol || ''); };
	const U = { pre: 'het', from: '', to: '', uid: 0, d: null, seq: 0, err: '', ms: [] };
	/* v2.9.135: több ember összevetése (a kijelölés megmarad ezen a gépen) */
	try { U.ms = JSON.parse(localStorage.getItem('cpx_au_ms') || '[]').map(Number).filter(Boolean).slice(0, 8); } catch (e) { U.ms = []; }
	const msSave = () => { try { localStorage.setItem('cpx_au_ms', JSON.stringify(U.ms)); } catch (e) { /* privát mód */ } };
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
		try { r = await post('cp_us_stats', { from: U.from, to: U.to, uid: U.uid || '', uids: U.ms.join(',') }); } catch (e) { if (my !== U.seq) return; U.err = e.message; box.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		if (my !== U.seq || !main.isConnected) return;
		U.err = ''; U.d = r; U.uid = r.uid;
		const any = r.users.some(u => u.open);
		const sel = r.users.find(u => u.id === r.uid);
		box.innerHTML = '<div class="au-tw"><table class="au-t au-ut"><thead><tr><th>Ki</th><th class="r">Aktív</th><th class="r">Nyitva</th><th class="r">Napok</th><th class="r">Aktív / nap</th><th>Utoljára</th><th>Most</th></tr></thead><tbody>' +
			r.users.map(u => '<tr data-u="' + u.id + '" class="' + (u.id === r.uid ? 'on' : '') + (u.open ? '' : ' nil') + '" tabindex="0"><td class="au-who"><input type="checkbox" class="au-ck" data-ck="' + u.id + '"' + (U.ms.includes(u.id) ? ' checked' : '') + ' title="Kijelölés az összevetéshez (ki mikor kezdett és fejezett be)" aria-label="' + esc(u.n) + ' kijelölése az összevetéshez">' + (u.now ? '<i class="au-live" title="Most a panelben"></i>' : '') + esc(u.n) + (u.now && u.doing ? '<small class="au-dom">' + doingH(u) + '</small>' : '') + '</td><td class="r"><b>' + dur(u.act) + '</b></td><td class="r">' + dur(u.open) + '</td><td class="r">' + (u.days || '–') + '</td><td class="r">' + (u.days ? dur(Math.round(u.act / u.days)) : '–') + '</td><td class="au-at">' + (u.now ? 'most a panelben' : esc(when(u.last))) + '</td><td class="au-dc">' + (u.now ? doingH(u) : '') + '</td></tr>').join('') + '</tbody></table></div>' +
			(!any ? '<p class="cpx-muted">Ebben az időszakban még nincs adat. A mérés a 2.9.88-as frissítéssel indult; ettől kezdve minden személyes fiók panelhasználata ide kerül.</p>' : '') +
			cmpH(r) +
			(sel ? '<h3 class="au-h3">' + esc(sel.n) + ' – napi idővonal' + (U.from < r.detail_from ? ' <small>(a 90 napnál régebbi napoknál csak az összesítő van meg)</small>' : '') + '</h3>' +
				(r.days.length ? (R1 => '<div class="au-tl"><div class="au-tlh"><span></span>' + axH(R1) + '<span></span></div>' +
					r.days.map(d => '<div class="au-tlr"><span class="au-dl">' + dLabel(d.d) + '<small>' + hm(d.first) + '–' + hm(d.last) + '</small></span>' + barH(d, R1) + '<span class="au-tn"><b>' + dur(d.act) + '</b><small>' + dur(d.open) + ' nyitva</small></span></div>').join('') +
					'<div class="au-lg"><span><i class="a2"></i>aktív</span><span><i class="a1"></i>nyitva, de nem dolgozott benne</span><span class="au-lgh">Vidd az egeret a sávra: mikor, mit csinált</span></div></div>')(rng(r.days)) : '<p class="cpx-muted">Ebben az időszakban nem használta a panelt.</p>') +
				/* v2.9.93: mire ment az idő – fülenként, és a legtöbb időt kapott levélszálak / deal-ek */
				(r.tabs && r.tabs.some(x => x.tab) ? (() => { const mx = Math.max(...r.tabs.map(x => x.n)); return '<h3 class="au-h3">Mire ment az idő</h3><div class="au-tl au-br">' + r.tabs.map(x => '<div class="au-brr"><span class="au-brl">' + esc(tabName(x.tab)) + '</span><div class="au-brb"><i class="a1" style="width:' + (x.n / mx * 100) + '%"></i><i class="a2" style="width:' + (x.a / mx * 100) + '%"></i></div><span class="au-tn"><b>' + dur(x.a) + '</b><small>' + dur(x.n) + ' nyitva</small></span></div>').join('') +
					(r.objs && r.objs.length ? '<div class="au-obj"><b>Legtöbbet ezekkel foglalkozott</b>' + r.objs.map(o => '<div class="au-objr"><span>' + (o.o.indexOf('mail:') === 0 ? '✉ ' : '$ ') + objA(o) + '</span><span class="au-tn">' + dur(o.a || o.n) + '</span></div>').join('') + '</div>' : '') +
					'<p class="au-cpn">A fülenkénti bontás a 2.9.93-as frissítéstől mért percekből készül (a percek 90 napig maradnak meg).</p></div>'; })() : '') : '');
		box.querySelectorAll('.au-go').forEach(a => a.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); go(a.dataset.k, Number(a.dataset.id)); }));
		box.querySelectorAll('[data-ck]').forEach(c => { c.addEventListener('click', e => e.stopPropagation()); c.addEventListener('keydown', e => e.stopPropagation()); c.addEventListener('change', () => { const id = Number(c.dataset.ck); U.ms = U.ms.filter(x => x !== id); if (c.checked) U.ms.push(id); U.ms = U.ms.slice(-8); msSave(); useLoad(); }); });
		const cx = box.querySelector('[data-cmpx]'); if (cx) cx.addEventListener('click', () => { U.ms = []; msSave(); useLoad(); });
		box.querySelectorAll('[data-u]').forEach(tr => { const go = () => { U.uid = Number(tr.dataset.u); useLoad(); }; tr.addEventListener('click', go); tr.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } }); });
	}
	/* v2.9.135: összevetés – naponként egymás alatt a kijelöltek: kezdés–befejezés, idővonal, aktív idő */
	function cmpH(r) {
		const M = r.multi || [];
		if (U.ms.length === 1) return '<p class="au-cmph">Jelölj ki még legalább egy embert (☐ a nevük előtt) az összevetéshez.</p>';
		if (M.length < 2) return '';
		const dd = Array.from(new Set([].concat(...M.map(p => p.days.map(d => d.d))))).sort().reverse();
		const tot = p => p.days.reduce((a, d) => a + d.act, 0);
		const RC = rng([].concat(...M.map(p => p.days)));
		return '<h3 class="au-h3">Összevetés – ' + M.map(p => esc(p.n)).join(', ') + ' <button type="button" class="au-cmpx" data-cmpx>Kijelölés törlése</button></h3>' +
			'<div class="au-tl au-cmp"><div class="au-tlh"><span></span>' + axH(RC) + '<span></span></div>' +
			(dd.length ? dd.map(day => '<div class="au-cday">' + dLabel(day) + '</div>' + M.map(p => { const d = p.days.find(x => x.d === day); return '<div class="au-tlr au-cr' + (d ? '' : ' nil') + '"><span class="au-dl">' + esc(p.n) + '<small>' + (d ? hm(d.first) + '–' + hm(d.last) : 'nem dolgozott') + '</small></span>' + (d ? barH(d, RC) : '<div class="au-bar" style="--hw:' + (6000 / (RC.b - RC.a)) + '%"></div>') + '<span class="au-tn">' + (d ? '<b>' + dur(d.act) + '</b><small>' + dur(d.open) + ' nyitva</small>' : '') + '</span></div>'; }).join('')).join('') : '<p class="cpx-muted">Ebben az időszakban egyikük sem használta a panelt.</p>') +
			'<div class="au-csum">' + M.map(p => '<span><b>' + esc(p.n) + '</b> ' + dur(tot(p)) + ' aktív · ' + p.days.length + ' nap</span>').join('') + '</div>' +
			'<div class="au-lg"><span><i class="a2"></i>aktív</span><span><i class="a1"></i>nyitva, de nem dolgozott benne</span><span class="au-lgh">Vidd az egeret a sávra: mikor, mit csinált</span></div></div>';
	}
	/* ------------------------------------------------------------ v2.9.145: Elemzés – üzleti és működési számok */
	const A = { pre: 'n90', from: '', to: '', d: null, seq: 0, err: '' };
	const nf = n => String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
	const pct = (a, b) => b ? Math.round(a / b * 100) + '%' : '–';
	const mins = m => m == null ? '–' : m < 60 ? Math.round(m) + ' perc' : m < 1440 ? (Math.round(m / 6) / 10).toString().replace('.', ',') + ' óra' : (Math.round(m / 144) / 10).toString().replace('.', ',') + ' nap';
	function anPresets() {
		const t = new Date(); t.setHours(12, 0, 0, 0); const back = n => { const d = new Date(t); d.setDate(d.getDate() - n); return ymd(d); };
		return [['n30', 'Utolsó 30 nap', back(29), ymd(t)], ['n90', 'Utolsó 90 nap', back(89), ymd(t)], ['n180', 'Fél év', back(179), ymd(t)], ['n365', 'Egy év', back(364), ymd(t)]];
	}
	function anView(main) {
		if (!A.from) { const p = anPresets().find(x => x[0] === A.pre); A.from = p[2]; A.to = p[3]; }
		main.innerHTML = '<div class="au an"><div class="au-head"><div class="au-ht"><h2>Napló</h2>' + segH() + '</div><p>Üzleti és működési számok a deal-ekből, a levelekből és a panelhasználatból – hol nyerünk, hol veszítünk, hol megy el az idő. Csak az adminok látják.</p></div>' +
			'<div class="au-f"><div class="crm-seg au-pre">' + anPresets().map(p => '<button type="button" data-apre="' + p[0] + '" class="' + (A.pre === p[0] ? 'on' : '') + '">' + p[1] + '</button>').join('') + '</div>' +
			'<label>Ettől <input type="date" data-af="from" value="' + A.from + '"></label><label>Eddig <input type="date" data-af="to" value="' + A.to + '"></label></div><div class="au-box"><div class="cpx-muted">Számolás…</div></div></div>';
		bindSeg(main);
		main.querySelectorAll('[data-apre]').forEach(b => b.addEventListener('click', () => { const p = anPresets().find(x => x[0] === b.dataset.apre); A.pre = p[0]; A.from = p[2]; A.to = p[3]; anView(main); }));
		main.querySelectorAll('[data-af]').forEach(i => i.addEventListener('change', () => { if (!i.value) return; A[i.dataset.af] = i.value; A.pre = ''; main.querySelectorAll('[data-apre]').forEach(b => b.classList.remove('on')); anLoad(); }));
		anLoad();
	}
	async function anLoad(fresh) {
		const main = S.main; if (!main || !main.isConnected || S.mode !== 'an') return;
		const box = main.querySelector('.au-box'); const my = ++A.seq;
		let r;
		try { r = await post('cp_an_stats', { from: A.from, to: A.to, fresh: fresh ? 1 : '' }); } catch (e) { if (my !== A.seq) return; A.err = e.message; box.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		if (my !== A.seq || !main.isConnected) return;
		A.err = ''; A.d = r;
		box.innerHTML = anWeek(r.week) + anResp(r.resp) + anFunnel(r.funnel) + anUnans(r.unans) + anLoadH(r.load) + anUse(r.use) + anEff(r.eff) + anLost(r.lost) + anRot(r.rot) + anRep(r.rep) + '<p class="an-ft">Számolva: ' + esc(r.at) + ' · 10 percig tárolt eredmény; a ↻ frissítés újraszámol.</p>';
		box.querySelectorAll('.au-go').forEach(a => a.addEventListener('click', e => { e.preventDefault(); go(a.dataset.k, Number(a.dataset.id)); }));
	}
	const card = (t, sub, body) => '<section class="an-c"><h3 class="au-h3">' + t + '</h3>' + (sub ? '<p class="an-sub">' + sub + '</p>' : '') + body + '</section>';
	/* S7 */
	function anResp(x) {
		if (!x || !x.n) return card('Válaszsebesség és nyerés', '', '<p class="cpx-muted">Ebben az időszakban nincs deal-hez kötött, ügyfél által indított levélszál.</p>');
		const L = x.b.filter(b => b.n), mx = Math.max(...L.map(b => b.n));
		const rows = L.map(b => { const cl = b.won + b.lost; return '<tr><td>' + esc(b.l) + '</td><td class="r">' + b.n + '</td><td><div class="an-bar"><i class="w" style="width:' + (b.won / mx * 100) + '%"></i><i class="l" style="width:' + (b.lost / mx * 100) + '%"></i><i class="o" style="width:' + (b.open / mx * 100) + '%"></i></div></td><td class="r"><b>' + pct(b.won, cl) + '</b><small>' + b.won + ' / ' + cl + ' lezárt</small></td></tr>'; }).join('');
		const best = L.filter(b => b.won + b.lost >= 5 && !/nem ment/.test(b.l)).sort((a, c) => c.won / (c.won + c.lost) - a.won / (a.won + a.lost))[0];
		return card('Válaszsebesség és nyerés', 'Az ügyfél első levelétől az első válaszlevelünkig eltelt idő, és mi lett a deal sorsa (' + x.n + ' deal). A „nyerés” a lezártak (megnyert + elvesztett) közül a megnyertek aránya.' + (best ? ' Legjobb arány: <b>' + esc(best.l) + '</b>.' : ''),
			'<div class="au-tw"><table class="au-t an-t"><thead><tr><th>Első válasz</th><th class="r">Deal</th><th>Megnyert · elvesztett · nyitott</th><th class="r">Nyerés</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
			'<div class="au-lg an-lg"><span><i class="w"></i>megnyert</span><span><i class="l"></i>elvesztett</span><span><i class="o"></i>még nyitott</span></div>' +
			(x.own.length ? '<div class="an-own">' + x.own.map(o => '<span><b>' + esc(o.who) + '</b> medián első válasz: ' + mins(o.med) + ' · 1 órán belül: ' + pct(o.fast, o.n) + '</span>').join('') + '</div>' : '') +
			'<p class="an-note">A „nem ment válasz a levélből” csoportban telefonon vagy a Salesmate-ből intézett ügyek is lehetnek. Az időt naptári órákban mérjük (éjszaka és hétvége is beleszámít).</p>');
	}
	/* S6 */
	function anFunnel(f) {
		if (!f || !f.tot) return card('Tölcsér', '', '<p class="cpx-muted">Ebben az időszakban nem jött létre deal.</p>');
		const row = (who, x) => '<tr><td class="au-who">' + esc(who) + '</td><td class="r">' + x.n + '</td><td class="r">' + x.offer + '<small>' + pct(x.offer, x.n) + '</small></td><td class="r">' + x.contract + '<small>' + pct(x.contract, x.offer) + '</small></td><td class="r"><b>' + x.won + '</b><small>' + pct(x.won, x.won + x.lost) + ' a lezártakból</small></td><td class="r">' + x.lost + '</td><td class="r">' + x.open + '</td><td class="r">' + nf(x.won_v) + ' Ft</td><td class="r">' + (x.med_days == null ? '–' : String(x.med_days).replace('.', ',') + ' nap') + '</td></tr>';
		return card('Tölcsér kollégánként', 'Az időszakban létrejött deal-ek: hány jutott el ajánlatig, szerződésig, megnyerésig (a deal felelőse szerint). Az átfutás a létrehozástól a megnyerésig eltelt idő mediánja.',
			'<div class="au-tw"><table class="au-t an-t"><thead><tr><th>Felelős</th><th class="r">Új deal</th><th class="r">Ajánlatig</th><th class="r">Szerződésig</th><th class="r">Megnyert</th><th class="r">Elvesztett</th><th class="r">Nyitott</th><th class="r">Megnyert érték</th><th class="r">Átfutás</th></tr></thead><tbody>' +
			f.rows.map(x => row(x.who, x)).join('') + (f.rows.length > 1 ? row('Összesen', f.tot).replace('<tr>', '<tr class="an-tot">') : '') + '</tbody></table></div>');
	}
	/* S13 */
	function anUnans(u) {
		if (!u) return '';
		const B = [['lt4', '4 órán belüli'], ['d1', '4–24 órás'], ['d3', '1–3 napos'], ['gt3', '3 napnál régebbi']];
		return card('Megválaszolatlan levelek – most', 'A Beérkezett mappa nyitott szálai, ahol az utolsó levél az ügyfélé (az elmúlt 30 napból). A lezárt szálak nem számítanak.',
			'<div class="an-kpi">' + B.map(b => '<div class="' + (b[0] === 'd3' || b[0] === 'gt3' ? (u.b[b[0]] ? 'bad' : '') : '') + '"><b>' + (u.b[b[0]] || 0) + '</b><span>' + b[1] + '</span></div>').join('') + '</div>' +
			(u.who.length ? '<div class="an-own">' + u.who.map(w => '<span><b>' + esc(w.who) + '</b> ' + w.n + (w.old ? ' · ebből ' + w.old + ' egy napnál régebbi' : '') + '</span>').join('') + '</div>' : '') +
			(u.list.length ? '<div class="au-tw"><table class="au-t an-t"><thead><tr><th>Mióta vár</th><th>Levél</th><th>Felelős</th></tr></thead><tbody>' + u.list.map(l => '<tr><td class="r">' + mins(l.h * 60) + '</td><td><a href="#" class="au-go" data-k="mail" data-id="' + l.id + '">' + esc(l.s || '(nincs tárgy)') + '</a>' + (l.c ? '<small>' + esc(l.c) + '</small>' : '') + '</td><td>' + esc(l.as) + '</td></tr>').join('') + '</tbody></table></div>' : '<p class="cpx-muted">Nincs megválaszolatlan levél. 🎉</p>'));
	}
	/* v2.9.146: S1, S2, S4, S5 – hol megy el az idő */
	function anUse(u) {
		if (!u) return '';
		const note = u.from > A.from ? ' <small>(a panelhasználat percenkénti adata ' + esc(u.from.replace(/-/g, '.')) + '-tól van meg)</small>' : '';
		const objT = (u.obj || []).length ? '<div class="au-tw"><table class="au-t an-t"><thead><tr><th>Ügy</th><th class="r">Panelidő</th><th class="r">Ebből aktív</th><th class="r">Kollégák</th><th>Állapot</th></tr></thead><tbody>' +
			u.obj.map(o => { const m = /^(mail|deal):(\d+)$/.exec(o.o || ''); return '<tr><td>' + (m ? (m[1] === 'mail' ? '✉ ' : '$ ') + '<a href="#" class="au-go" data-k="' + m[1] + '" data-id="' + m[2] + '">' + esc(o.ol) + '</a>' : esc(o.ol)) + '</td><td class="r"><b>' + dur(o.n) + '</b></td><td class="r">' + dur(o.a) + '</td><td class="r">' + o.ppl + '</td><td>' + (o.ds ? esc(o.ds === 'Won' ? 'Megnyert' : o.ds === 'Lost' ? 'Elvesztett' : o.dst || 'Nyitott') + (o.dv ? '<small>' + nf(o.dv) + ' Ft</small>' : '') : '') + '</td></tr>'; }).join('') + '</tbody></table></div>' : '<p class="cpx-muted">Nincs adat.</p>';
		const rep = (u.rep || []).length ? '<div class="an-own">' + u.rep.map(x => '<span><b>' + esc(x.who) + '</b> ' + x.ses + ' válaszírás · átlag ' + String(x.avg).replace('.', ',') + ' perc / válasz · összesen ' + dur(x.min) + '</span>').join('') + '</div>' : '<p class="cpx-muted">Még nincs mért válaszírás.</p>';
		const sw = (u.sw || []).length ? '<div class="an-own">' + u.sw.map(x => '<span><b>' + esc(x.who) + '</b> ' + String(x.perhr).replace('.', ',') + ' fülváltás / óra</span>').join('') + '</div>' + ((u.pairs || []).length ? '<div class="an-pairs">' + u.pairs.map(x => { const [f, t] = x.k.split('→'); return '<span>' + esc(tabName(f)) + ' → ' + esc(tabName(t)) + ' <b>' + x.n + '×</b></span>'; }).join('') + '</div>' : '') : '';
		const ih = (u.idle || []).slice(6, 23), mx = Math.max(1, ...ih.map(x => x.o));
		const idle = '<div class="an-two an-one"><div><b class="an-l">Nyitott panel óránként – ebből aktív (6–22 óra)</b><div class="an-cols">' + ih.map((x, i) => '<div class="an-col" title="' + esc((i + 6) + ' óra: ' + dur(x.o) + ' nyitva, ebből ' + dur(x.a) + ' aktív (' + pct(x.a, x.o) + ')') + '"><div class="an-cb"><i class="op" style="height:' + (x.o / mx * 100) + '%;width:60%;position:relative"><i class="ac" style="position:absolute;left:0;right:0;bottom:0;height:' + (x.o ? x.a / x.o * 100 : 0) + '%"></i></i></div><small>' + (i + 6) + 'h</small><small class="an-pc">' + pct(x.a, x.o) + '</small></div>').join('') + '</div></div></div>' +
			'<div class="au-lg an-lg"><span><i class="op"></i>nyitva, de nem dolgozott benne</span><span><i class="ac"></i>aktív</span></div>';
		return card('Hol megy el az idő' + note, 'A panelben töltött percekből: mely ügyek vitték a legtöbb időt, mennyi idő egy válasz megírása, mennyit ugrálunk a fülek között, és mikor áll üresen a nyitott panel.',
			'<h4 class="an-h4">Legtöbb időt vivő ügyek</h4><p class="an-sub">Ha egy ügytípus rendszeresen sok időt visz el, oda érdemes sablon, csomagár vagy szabály.</p>' + objT +
			'<h4 class="an-h4">Válaszírás ráfordítása</h4><p class="an-sub">Egy levélválasz megírására fordított percek (a válaszíró megnyitásától a küldésig vagy bezárásig).</p>' + rep +
			'<h4 class="an-h4">Fülváltások</h4><p class="an-sub">Sok ugrálás két fül között azt jelzi, hogy egy adat rossz helyen van – ide kell egy gomb vagy előnézet.</p>' + sw +
			'<h4 class="an-h4">Üresjárat napszak szerint</h4>' + idle);
	}
	/* v2.9.147: S8 ráfordítás és érték, S9 elvesztés okai, S10 rohadás */
	function anEff(e) {
		if (!e || !(e.rows || []).length) return '';
		return card('Ráfordítás és érték szolgáltatásonként', 'A deal-ekre és a hozzájuk kötött levelekre fordított panelidő (' + esc(e.from.replace(/-/g, '.')) + '-tól), a deal szolgáltatása szerint. A „megnyert érték / munkaóra” mutatja, mely ügyek fizetnek jól a ráfordított időhöz képest.',
			'<div class="au-tw"><table class="au-t an-t"><thead><tr><th>Szolgáltatás</th><th class="r">Deal</th><th class="r">Panelidő</th><th class="r">Átlag / deal</th><th class="r">Megnyert</th><th class="r">Megnyert érték</th><th class="r">Érték / munkaóra</th></tr></thead><tbody>' +
			e.rows.map(x => '<tr><td>' + esc(x.svc) + '</td><td class="r">' + x.n + '</td><td class="r"><b>' + dur(x.min) + '</b></td><td class="r">' + dur(Math.round(x.avg)) + '</td><td class="r">' + x.won + '<small>' + x.lost + ' elvesztett</small></td><td class="r">' + nf(x.won_v) + ' Ft</td><td class="r">' + (x.per_h ? nf(x.per_h) + ' Ft' : '–') + '</td></tr>').join('') + '</tbody></table></div>' +
			'<p class="an-note">Az új deal-eknél a megnyerés még hátravan, ezért az érték az idő múlásával nő. A telefonon és a Salesmate-ben töltött idő nem látszik.</p>');
	}
	function anLost(l) {
		if (!l || !l.n) return '';
		const mx = Math.max(...l.why.map(x => x.n));
		return card('Miért veszítünk', 'Az időszakban elvesztettnek jelölt ' + l.n + ' deal: az ok, és hogy melyik szakaszban estek ki.',
			'<div class="an-two an-lw"><div>' + l.why.map(x => '<div class="an-hr"><span>' + esc(x.k) + '</span><div class="an-bar"><i class="l" style="width:' + (x.n / mx * 100) + '%"></i></div><b>' + x.n + '</b></div>').join('') + '</div>' +
			'<div><b class="an-l">Melyik szakaszban estek ki</b>' + l.stage.map(x => '<div class="an-hr"><span>' + esc(x.k) + '</span><b>' + x.n + '</b><small>' + pct(x.n, l.n) + '</small></div>').join('') + '</div></div>' +
			(l.why[0] && /Nincs megadva/.test(l.why[0].k) ? '<p class="an-note">A legtöbb elvesztésnél nincs ok – ha elvesztéskor kötelezővé tennénk az ok kiválasztását (pl. ár, dátum foglalt, nem válaszolt, más szolgáltató), ez a táblázat sokkal többet mondana.</p>' : ''));
	}
	function anRot(r) {
		if (!r) return '';
		const H = Object.keys(r.hist || {}), hv = H.map(k => r.hist[k]), mx = Math.max(1, ...hv);
		return card('Rohadó deal-ek – most', 'Nyitott deal-ek, amelyeknél lejárt a szakasz türelmi ideje (Beállítások → Rohadás szerint).',
			'<div class="an-kpi"><div class="' + (r.n ? 'bad' : '') + '"><b>' + r.n + '</b><span>rohadó deal (' + pct(r.n, r.open) + ' a nyitottakból)</span></div><div><b>' + nf(r.v) + ' Ft</b><span>összértékük</span></div><div><b>' + (r.med == null ? '–' : String(r.med).replace('.', ',') + ' nap') + '</b><span>medián késés</span></div><div><b>' + r.open + '</b><span>nyitott deal összesen</span></div></div>' +
			'<div class="au-tw"><table class="au-t an-t"><thead><tr><th>Szakasz</th><th class="r">Rohadó</th><th class="r">Átlagos késés</th></tr></thead><tbody>' + r.stage.map(x => '<tr><td>' + esc(x.k) + '</td><td class="r"><b>' + x.n + '</b></td><td class="r">' + String(x.d).replace('.', ',') + ' nap</td></tr>').join('') + '</tbody></table></div>' +
			(r.own.length ? '<div class="an-own">' + r.own.map(o => '<span><b>' + esc(o.k) + '</b> ' + o.n + ' rohadó</span>').join('') + '</div>' : '') +
			(H.length > 1 ? '<div class="an-two an-one"><div><b class="an-l">Rohadó deal-ek száma naponként</b><div class="an-cols an-sm">' + H.map((k, i) => '<div class="an-col" title="' + esc(k.replace(/-/g, '.') + ': ' + hv[i]) + '"><div class="an-cb"><i class="l" style="height:' + (hv[i] / mx * 100) + '%;width:70%;background:#D9534F"></i></div></div>').join('') + '</div></div></div>' : '<p class="an-note">A napi számot mostantól minden nap eltároljuk, így pár nap múlva itt látszik a trend is.</p>'));
	}
	/* v2.9.148: S11 heti összefoglaló, S3 ismétlődő szövegek */
	function anWeek(w) {
		if (!w) return '';
		const c = w.cur, p = w.prev, d = (a, b, inv) => { if (a == null || b == null || a === b) return ''; const up = a > b; return '<i class="' + ((up !== !!inv) ? 'up' : 'dn') + '">' + (up ? '▲' : '▼') + '</i>'; };
		const K = [['Új deal', c.new, p.new, nf], ['Megnyert', c.won, p.won, nf], ['Megnyert érték', c.won_v, p.won_v, v => nf(v) + ' Ft'], ['Elvesztett', c.lost, p.lost, nf, 1], ['Beérkező levél', c.in, p.in, nf], ['Kimenő levél', c.out, p.out, nf], ['Első válasz (medián)', c.resp_med, p.resp_med, mins, 1], ['Aktív panelidő', c.act, p.act, dur]];
		return card('Ez a hét', 'Hétfőtől máig (' + esc(c.from.slice(5).replace('-', '.')) + '–' + esc(c.to.slice(5).replace('-', '.')) + '), összevetve az előző hét ugyanazon napjaival. A nyíl zöld, ha jó irányba változott.',
			'<div class="an-kpi an-wk">' + K.map(k => '<div><b>' + k[3](k[1]) + d(k[1], k[2], k[4]) + '</b><span>' + k[0] + '</span><small>előző hét: ' + k[3](k[2]) + '</small></div>').join('') + '</div>');
	}
	function anRep(r) {
		if (!r || !r.n) return '';
		return card('Újra és újra begépelt szövegek', 'A legutóbbi ' + r.n + ' kimenő levél kézzel írt bekezdései közül azok, amelyek sok különböző levélben visszatérnek, de egyik sablonban sincsenek benne (a számokat # jelöli). Ezek sablon- vagy gyorsszöveg-jelöltek.',
			(r.list.length ? '<div class="au-tw"><table class="au-t an-t"><thead><tr><th class="r">Levél</th><th>Szöveg</th></tr></thead><tbody>' + r.list.map(x => '<tr><td class="r"><b>' + x.n + '×</b></td><td>' + esc(x.t) + '</td></tr>').join('') + '</tbody></table></div>' : '<p class="cpx-muted">Nincs gyakran ismételt, sablonon kívüli szöveg.</p>') +
			'<p class="an-note">' + r.tpl + ' bekezdés sablonból jött (ezek nem szerepelnek a listában).</p>');
	}
	/* S12 */
	function anLoadH(l) {
		if (!l) return '';
		const DN = ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];
		const col = (L, lab) => { const mi = Math.max(1, ...L.map(x => Math.max(x.in, x.out))), ma = Math.max(1, ...L.map(x => x.act)); return '<div class="an-cols">' + L.map((x, i) => '<div class="an-col" title="' + esc(lab(i) + ': ' + x.in + ' beérkező, ' + x.out + ' kimenő levél, ' + dur(x.act) + ' aktív panelidő') + '"><div class="an-cb"><i class="in" style="height:' + (x.in / mi * 100) + '%"></i><i class="out" style="height:' + (x.out / mi * 100) + '%"></i><i class="act" style="height:' + (x.act / ma * 100) + '%"></i></div><small>' + lab(i) + '</small></div>').join('') + '</div>'; };
		const hrs = l.h.slice(6, 23);
		return card('Terhelés: mikor jönnek a levelek, és mikor dolgozunk', 'Beérkező és kimenő levelek, mellettük a panelben töltött aktív idő (a panelidő az elmúlt 90 napból van meg). Ha sok levél jön olyankor, amikor kevés az aktív idő, ott nő a válaszidő.',
			'<div class="an-two"><div><b class="an-l">Napszak (6–22 óra)</b>' + col(hrs, i => (i + 6) + 'h') + '</div><div><b class="an-l">A hét napjai</b>' + col(l.w, i => DN[i]) + '</div></div>' +
			'<div class="au-lg an-lg"><span><i class="in"></i>beérkező levél</span><span><i class="out"></i>kimenő levél</span><span><i class="act"></i>aktív panelidő</span></div>');
	}
	/* v2.9.140: a megjelenített órák (a használt időszak ±1 óra, legalább 4 óra), tengely, sáv, egérkövető buborék */
	function rng(days) {
		const f = (days || []).filter(d => d && (d.act || d.open));
		if (!f.length) return { a: 0, b: 1440 };
		let a = Math.min(...f.map(d => d.first)), b = Math.max(...f.map(d => d.last));
		a = Math.max(0, (Math.floor(a / 60) - 1) * 60); b = Math.min(1440, (Math.ceil(b / 60) + 1) * 60);
		while (b - a < 240) { if (a > 0) a -= 60; if (b - a < 240 && b < 1440) b += 60; if (a === 0 && b === 1440) break; }
		return { a, b };
	}
	function axH(R) {
		const st = R.b - R.a <= 720 ? 60 : 120, t = [];
		for (let m = R.a; m <= R.b; m += st) t.push(m);
		return '<div class="au-ax">' + t.map(m => '<i style="left:' + ((m - R.a) / (R.b - R.a) * 100) + '%">' + (m / 60) + '</i>').join('') + '</div>';
	}
	const BARS = [];
	function barH(d, R) {
		const i = BARS.push({ d, R }) - 1, w = R.b - R.a; let out = '';
		const s = d.bar || '';
		let k = 0;
		while (k < s.length) { const c = s[k]; let j = k; while (j < s.length && s[j] === c) j++; if (c !== '0') { const x0 = Math.max(R.a, k * 5), x1 = Math.min(R.b, j * 5); if (x1 > x0) out += '<i class="a' + c + '" style="left:' + ((x0 - R.a) / w * 100) + '%;width:' + ((x1 - x0) / w * 100) + '%"></i>'; } k = j; }
		return '<div class="au-bar" data-bi="' + i + '" style="--hw:' + (6000 / w) + '%">' + out + '<b class="au-hl"></b></div>';
	}
	let tip = null;
	const tipHide = () => { if (tip) tip.hidden = true; document.querySelectorAll('.au-hl.on').forEach(x => x.classList.remove('on')); };
	document.addEventListener('mousemove', e => {
		const el = e.target.closest && e.target.closest('.au-bar[data-bi]');
		if (!el) { tipHide(); return; }
		const B = BARS[Number(el.dataset.bi)]; if (!B) return;
		const rc = el.getBoundingClientRect(), fx = Math.max(0, Math.min(1, (e.clientX - rc.left) / rc.width)), m = Math.round(B.R.a + fx * (B.R.b - B.R.a));
		const sg = (B.d.seg || []).find(x => m >= x.a && m <= x.b + 1);
		if (!tip) { tip = document.createElement('div'); tip.className = 'au-tip'; document.body.appendChild(tip); }
		const kod = B.d.bar ? B.d.bar[Math.min(287, Math.floor(m / 5))] : '0';
		tip.innerHTML = '<b>' + hm(Math.min(1439, m)) + '</b>' + (sg ? ' · <span class="' + (sg.x ? 'ta' : 'tn') + '">' + (sg.x ? 'aktív' : 'nyitva, nem dolgozott') + '</span><div>' + (sg.s === 'reply' ? '✍ levelet ír · ' : '') + esc(tabName(sg.t)) + (sg.ol ? ' · ' + esc(sg.ol) : '') + '</div>' + '<small>' + hm(sg.a) + '–' + hm(Math.min(1439, sg.b + 1)) + '</small>' : kod !== '0' && kod ? ' · ' + (kod === '2' ? 'aktív' : 'nyitva') + (B.d.seg ? '' : '<div><small>(a részletek csak 31 napnál rövidebb időszaknál)</small></div>') : ' · <span class="tn">nem volt a panelben</span>');
		tip.hidden = false;
		const tw = tip.offsetWidth, th = tip.offsetHeight;
		tip.style.left = Math.max(8, Math.min(window.innerWidth - tw - 8, e.clientX - tw / 2)) + 'px';
		tip.style.top = (rc.top - th - 8 < 4 ? rc.bottom + 8 : rc.top - th - 8) + 'px';
		const hl = el.querySelector('.au-hl'); document.querySelectorAll('.au-hl.on').forEach(x => { if (x !== hl) x.classList.remove('on'); });
		if (hl) { hl.classList.add('on'); hl.style.left = (fx * 100) + '%'; }
	});
	window.addEventListener('scroll', tipHide, true);
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
	function status(s) { if (S.mode === 'an') { s.textContent = A.err || (A.d ? 'Számolva: ' + A.d.at : ''); s.className = 'cpx-status' + (A.err ? ' err' : ''); return; } if (S.mode === 'use') { s.textContent = U.err || ''; s.className = 'cpx-status' + (U.err ? ' err' : ''); return; } s.textContent = S.err || (S.data ? S.data.total + ' naplóbejegyzés' : ''); s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function refresh() { if (S.main && S.main.isConnected) { if (S.mode === 'an') await anLoad(true); else if (S.mode === 'use') await useLoad(); else await load(); } }
	/* értesítésből vagy linkből: „naplo:hasznalat” */
	function deep(arg) { if (String(arg || '') === 'hasznalat') nav('use'); if (String(arg || '') === 'elemzes') nav('an'); }
	window.CPAudit = { view, status, refresh, deep };

	const css = document.createElement('style');
	css.textContent = '.au{max-width:1100px}.au-head h2{margin:0 0 4px;font:800 20px "Plus Jakarta Sans",sans-serif}.au-head p{margin:0 0 10px;font-size:13.5px;color:var(--ink)}' +
		'.au-f{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}.au-f select,.au-f input{border:1px solid var(--li);border-radius:8px;padding:6px 8px;font:13px Inter,sans-serif;background:#fff;color:var(--ink)}.au-f label{font-size:12.5px;color:var(--mu);display:inline-flex;gap:4px;align-items:center}' +
		'.au-f input[type=search]{flex:1;min-width:180px}.au-csv{margin-left:auto;text-decoration:none}.au-n{font-size:12.5px;color:var(--mu);margin-bottom:6px}' +
		'.au-tw{background:#fff;border:1px solid var(--li);border-radius:12px;overflow:auto}.au-t{width:100%;border-collapse:collapse;font-size:13px}.au-t th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--mu);padding:8px 10px;border-bottom:1px solid var(--li);background:#FAF8F5}' +
		'.au-t td{padding:7px 10px;border-bottom:1px solid #F0ECE6;vertical-align:top}.au-at{white-space:nowrap;color:var(--mu);font-variant-numeric:tabular-nums}.au-who{white-space:nowrap;font-weight:600}.au-who small{display:block;font-weight:400;color:var(--mu);font-size:11.5px}.au-ar{white-space:nowrap;color:var(--mu)}' +
		'.au-s{color:var(--ink);opacity:.85;word-break:break-word;margin-top:1px}.au-go{color:#2F5BD8;font-weight:600;text-decoration:none;font-size:12.5px}.a-login_fail td{background:#FDECEA}.au-pg{display:flex;gap:8px;justify-content:center;margin-top:10px}' +
		'.au{min-width:0;max-width:min(1100px,100%)}.au-pre{max-width:100%;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch}.au-pre::-webkit-scrollbar{display:none}.au-pre button{flex:0 0 auto}' +
		'@media (max-width:760px){.au{overflow-x:clip}.au-f select,.au-f input{width:100%;min-width:0;max-width:100%;box-sizing:border-box;font-size:16px}.au-f input[type=date]{-webkit-appearance:none;appearance:none;display:block;min-height:38px}.au-f input[type=search]{grid-column:1 / -1}.au-f .au-csv{grid-column:1 / -1;text-align:center}' +
		'.au-t:not(.au-ut):not(.an-t) thead{display:none}.au-t:not(.au-ut):not(.an-t),.au-t:not(.au-ut):not(.an-t) tbody,.au-t:not(.au-ut):not(.an-t) tr,.au-t:not(.au-ut):not(.an-t) td{display:block;width:auto}.au-t:not(.au-ut):not(.an-t) tr{padding:9px 12px;border-bottom:1px solid #F0ECE6}.au-t:not(.au-ut):not(.an-t) td{padding:0;border:0}.au-t:not(.au-ut):not(.an-t) td.au-at,.au-t:not(.au-ut):not(.an-t) td.au-who{display:inline;font-size:12.5px}.au-t:not(.au-ut):not(.an-t) td.au-who small{display:inline;margin-left:4px}.au-t:not(.au-ut):not(.an-t) td.au-at:after{content:" · "}.au-t:not(.au-ut):not(.an-t) td:last-child{margin-top:3px;overflow-wrap:anywhere}}' +
		'@media (max-width:760px){.au-f{display:grid;grid-template-columns:1fr 1fr;gap:8px}.au-f .au-pre{grid-column:1 / -1;display:flex}.au-f label{display:flex;flex-direction:column;align-items:stretch;gap:2px;min-width:0}.au-f label input{width:100%;min-width:0;box-sizing:border-box;font-size:16px}.au-seg{max-width:100%;overflow-x:auto}}' +
		'.au-seg a{border-radius:999px;padding:6px 14px;font:700 13px "Plus Jakarta Sans",sans-serif;color:var(--mu);text-decoration:none;white-space:nowrap}.au-seg a.on{background:var(--n);color:#fff}.au-seg a:not(.on):hover{color:var(--n)}.au-seg a:focus-visible{outline:2px solid #2F5BD8;outline-offset:2px}.au-ht{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:4px}.au-ht h2{margin:0}.au-pre button{white-space:nowrap}.au-t .r{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}' +
		'.au-dc{min-width:160px;max-width:320px}.au-do{font-size:12.5px;color:var(--ink);overflow-wrap:anywhere}.au-do a{color:#2F5BD8;text-decoration:none;font-weight:600}' +
		'.au-br{display:flex;flex-direction:column;gap:6px}.au-brr{display:grid;grid-template-columns:120px minmax(0,1fr) 104px;gap:10px;align-items:center}.au-brl{font-size:12.5px;font-weight:600;overflow-wrap:anywhere}.au-brb{position:relative;height:14px;background:#F5F3EF;border-radius:4px;overflow:hidden}.au-brb i{position:absolute;left:0;top:0;bottom:0;border-radius:4px}.au-brb i.a1{background:#BFD0F6}.au-brb i.a2{background:#2F5BD8}' +
		'.au-obj{margin-top:10px;border-top:1px solid #F0ECE6;padding-top:8px;font-size:12.5px}.au-obj > b{display:block;margin-bottom:4px}.au-objr{display:flex;justify-content:space-between;gap:10px;padding:3px 0}.au-objr a{color:#2F5BD8;text-decoration:none}.au-objr > span:first-child{min-width:0;overflow-wrap:anywhere}.au-cpn{font-size:11.5px;color:var(--mu);margin:8px 0 0}' +
		'.au-dom{display:none}@media (max-width:760px){.au-dom{display:block;font-weight:400;margin-top:2px}}.au-ut tbody tr{cursor:pointer}.au-ut tbody tr:hover td{background:#FAF8F5}.au-ut tr.on td{background:#EEF3FD}.au-ut tr.nil td{color:var(--mu)}.au-ut tr:focus-visible{outline:2px solid #2F5BD8;outline-offset:-2px}' +
		'.au-live{display:inline-block;width:8px;height:8px;border-radius:50%;background:#17A34A;margin-right:6px;box-shadow:0 0 0 3px #DDF3E6;vertical-align:1px}.au-h3{font:700 15px "Plus Jakarta Sans",sans-serif;margin:18px 0 8px}.au-h3 small{font:400 12px Inter,sans-serif;color:var(--mu)}' +
		'.au-tl{background:#fff;border:1px solid var(--li);border-radius:12px;padding:10px 12px}.au-tlh,.au-tlr{display:grid;grid-template-columns:96px minmax(0,1fr) 104px;gap:10px;align-items:center}.au-tlr{padding:5px 0;border-top:1px solid #F0ECE6}.au-ax{position:relative;height:16px;font-size:10.5px;color:var(--mu)}.au-ax i{position:absolute;transform:translateX(-50%);font-style:normal}.au-ax i:first-child{transform:none}.au-ax i:last-child{transform:translateX(-100%)}' +
		'.au-dl{font-size:12.5px;font-weight:600;font-variant-numeric:tabular-nums}.au-dl small,.au-tn small{display:block;font-weight:400;color:var(--mu);font-size:11px}.au-tn{text-align:right;font-size:12.5px;font-variant-numeric:tabular-nums}' +
		'.au-bar{position:relative;height:16px;border-radius:4px;background:repeating-linear-gradient(90deg,#F5F3EF 0,#F5F3EF calc(25% - 1px),#E6E3DE calc(25% - 1px),#E6E3DE 25%)}.au-bar i{position:absolute;top:0;bottom:0;min-width:2px}.au-bar i.a1,.au-lg i.a1{background:#BFD0F6}.au-bar i.a2,.au-lg i.a2{background:#2F5BD8}' +
		'.au-lg{display:flex;gap:14px;margin-top:8px;font-size:11.5px;color:var(--mu)}.au-lg i{display:inline-block;width:12px;height:8px;border-radius:2px;margin-right:5px}' +
		'@media (max-width:760px){.au-tlh,.au-tlr{grid-template-columns:70px minmax(0,1fr) 70px;gap:6px}.au-ut th:nth-child(3),.au-ut td:nth-child(3),.au-ut th:nth-child(4),.au-ut td:nth-child(4),.au-ut th:nth-child(5),.au-ut td:nth-child(5){display:none}.au-ut td.au-who{white-space:normal;overflow-wrap:anywhere}.au-ut th:nth-child(7){display:none}.au-ut td.au-dc{display:none}.au-brr{grid-template-columns:84px minmax(0,1fr) 70px;gap:6px}.au-ut .au-at{white-space:normal}}' +
		'@media (max-width:760px){.au-csv{margin-left:0}.au-t th:nth-child(3),.au-t td:nth-child(3){display:none}}';
	css.textContent += '.au-ck{width:15px;height:15px;margin:0 7px 0 0;vertical-align:-2px;accent-color:#2F5BD8;cursor:pointer}.au-cmph{font-size:12.5px;color:var(--mu);margin:12px 0 0}' +
		'.au-cmpx{border:1px solid var(--li);background:#fff;border-radius:999px;padding:2px 10px;font:500 12px Inter,sans-serif;color:var(--mu);cursor:pointer;margin-left:8px;vertical-align:2px}.au-cmpx:hover{color:var(--ink)}' +
		'.au-cday{font:700 12.5px Inter,sans-serif;margin:10px 0 2px;padding-top:8px;border-top:2px solid #E6E3DE;font-variant-numeric:tabular-nums}.au-cmp .au-tlh + .au-cday{border-top:0;padding-top:2px}.au-cmp .au-cr{border-top:0;padding:3px 0}.au-cmp .au-cr .au-dl{font-weight:600;overflow-wrap:anywhere}.au-cmp .au-cr.nil .au-dl{color:var(--mu);font-weight:400}' +
		'.au-csum{display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:10px;padding-top:8px;border-top:1px solid #F0ECE6;font-size:12.5px;font-variant-numeric:tabular-nums}' +
		'@media (max-width:760px){.au-ck{width:18px;height:18px}}' +
		/* v2.9.140: nagyobb, egérrel bejárható idővonal */
		'.au-bar{height:30px;border-radius:6px;background:repeating-linear-gradient(90deg,transparent 0,transparent calc(var(--hw,25%) - 1px),#E6E3DE calc(var(--hw,25%) - 1px),#E6E3DE var(--hw,25%)),#F5F3EF;cursor:crosshair}.au-bar i{border-radius:3px}.au-tlr{padding:7px 0}.au-cmp .au-cr{padding:5px 0}' +
		'.au-hl{position:absolute;top:-4px;bottom:-4px;width:2px;margin-left:-1px;background:#141C33;border-radius:1px;display:none;pointer-events:none}.au-hl.on{display:block}' +
		'.au-tip{position:fixed;z-index:10070;background:#141C33;color:#fff;border-radius:8px;padding:7px 10px;font:12.5px/1.4 Inter,sans-serif;box-shadow:0 8px 24px rgba(20,28,51,.25);pointer-events:none;max-width:320px}.au-tip b{font-variant-numeric:tabular-nums}.au-tip div{margin-top:2px;overflow-wrap:anywhere}.au-tip small{display:block;color:#AEB4C2;font-size:11px;font-variant-numeric:tabular-nums}.au-tip .ta{color:#9DB8FF;font-weight:600}.au-tip .tn{color:#C9CDD6}' +
		'.au-lgh{margin-left:auto;font-style:italic}@media (hover:none){.au-lgh{display:none}}' +
		/* v2.9.145: Elemzés */
		'.an .au-box{display:flex;flex-direction:column;gap:6px}.an-c{margin-bottom:6px}.an-sub{margin:-4px 0 8px;font-size:12.5px;color:var(--mu);max-width:760px}.an-note,.an-ft{font-size:11.5px;color:var(--mu);margin:6px 0 0}' +
		'.an-t td small{display:block;color:var(--mu);font-size:11px;font-weight:400}.an-t tr.an-tot td{font-weight:700;background:#FAF8F5}.an-bar{display:flex;height:14px;min-width:120px;background:#F5F3EF;border-radius:4px;overflow:hidden}.an-bar i{display:block;height:100%}' +
		'.an-bar .w,.an-lg .w{background:#1F9D55}.an-bar .l,.an-lg .l{background:#D9534F}.an-bar .o,.an-lg .o{background:#C9CFDA}.an-lg i{display:inline-block;width:12px;height:8px;border-radius:2px;margin-right:5px}' +
		'.an-own{display:flex;flex-wrap:wrap;gap:4px 18px;margin:8px 0;font-size:12.5px}.an-kpi{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:8px}.an-kpi div{background:#fff;border:1px solid var(--li);border-radius:12px;padding:10px 12px}.an-kpi b{display:block;font:800 22px "Plus Jakarta Sans",sans-serif}.an-kpi span{font-size:12px;color:var(--mu)}.an-kpi .bad{border-color:#F3C2BF;background:#FDF1F0}.an-kpi .bad b{color:#B42318}' +
		'.an-two{display:grid;grid-template-columns:3fr 2fr;gap:14px;background:#fff;border:1px solid var(--li);border-radius:12px;padding:12px}.an-l{font-size:12px;color:var(--mu)}.an-cols{display:flex;gap:4px;align-items:flex-end;height:150px;margin-top:6px}.an-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;min-width:0;height:100%}.an-cb{flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:1px}.an-cb i{display:block;width:30%;border-radius:2px 2px 0 0;min-height:1px}.an-cb .in,.an-lg .in{background:#2F5BD8}.an-cb .out,.an-lg .out{background:#7FA2F0}.an-cb .act,.an-lg .act{background:#F2A33A}.an-col small{font-size:10.5px;color:var(--mu)}' +
		'.an-h4{font:700 13px Inter,sans-serif;margin:12px 0 2px}.an-pairs{display:flex;flex-wrap:wrap;gap:6px;margin:4px 0}.an-pairs span{background:#fff;border:1px solid var(--li);border-radius:999px;padding:3px 10px;font-size:12px}.an-one{grid-template-columns:1fr}.an-cb .op,.an-lg .op{background:#BFD0F6}.an-cb .ac,.an-lg .ac{background:#2F5BD8}.an-col .an-pc{font-size:9.5px}.an-cb .op .ac{width:auto;border-radius:0}.an-hr{display:grid;grid-template-columns:minmax(0,180px) minmax(0,1fr) auto auto;gap:8px;align-items:center;font-size:12.5px;padding:3px 0}.an-hr span{overflow-wrap:anywhere}.an-hr small{color:var(--mu)}.an-lw{grid-template-columns:3fr 2fr}.an-lw>div:last-child .an-hr{grid-template-columns:minmax(0,1fr) auto auto}.an-sm{height:70px}.cpx-main:has(.au-ut),.cpx-main:has(.au.an){max-width:none}.au:has(.au-ut),.au.an{max-width:min(1600px,100%)}.au-ut .au-dc{max-width:520px}.au-ut td.au-who small.au-dom{display:none}@media (max-width:760px){.au-ut td.au-who small.au-dom{display:block}}.an-wk{grid-template-columns:repeat(4,minmax(0,1fr))}.an-wk b{font-size:19px}.an-wk b i{font-style:normal;font-size:11px;margin-left:6px;vertical-align:3px}.an-wk i.up{color:#1F9D55}.an-wk i.dn{color:#B42318}.an-wk small{display:block;font-size:11px;color:var(--mu);margin-top:2px}' +
		'@media (max-width:760px){.an-kpi{grid-template-columns:repeat(2,minmax(0,1fr))}.an-two{grid-template-columns:1fr}.an-t th:nth-child(n+6),.an-t td:nth-child(n+6){display:none}}';
	document.head.appendChild(css);
})();
