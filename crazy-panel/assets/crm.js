/* Crazy panel – Deal-ek fül (v1.25, csak olvasás: a saját CRM-táblákból) */
(function () {
	'use strict';
	const C = window.CPX || {};
	const R = C.crm || {};
	const STAGES = R.stages || [];
	const USERS = R.users || {};
	const W = !!R.write;
	const ST_HU = { Open: 'Nyitott', Won: 'Megnyert', Lost: 'Elvesztett' };
	const HU_MS = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
	const DOW = ['V', 'H', 'K', 'Sze', 'Cs', 'P', 'Szo'];

	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const pad = n => (n < 10 ? '0' : '') + n;
	const num = n => n == null || isNaN(n) ? '–' : Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
	const money = (v, cur) => v == null ? '–' : num(v) + (cur && cur !== 'HUF' ? ' ' + cur : ' Ft');
	const huD = s => { if (!s) return ''; const p = s.slice(0, 10).split('-').map(Number); const d = new Date(p[0], p[1] - 1, p[2]); return p[0] + '. ' + HU_MS[p[1] - 1] + ' ' + p[2] + '. (' + DOW[d.getDay()] + ')'; };
	const huDT = s => s ? s.slice(0, 4) + '.' + s.slice(5, 7) + '.' + s.slice(8, 10) + '. ' + s.slice(11, 16) : '';
	const uname = id => (USERS[String(id)] || {}).n || (id ? '#' + id : '');
	const safeUrl = u => /^https?:\/\//i.test(String(u || '').trim()) ? String(u).trim() : '';
	/* a cím végén álló egynapos dátum („| 2026-11-27”) elhagyható, mert a rendezvény dátuma külön is látszik; a többnaposat („2026-10-29/30”) meghagyjuk */
	const shortTitle = r => { const t = r.title || 'Deal #' + r.id; if (!r.ev) return t; const m = t.match(/\s*\|\s*(\d{4}-\d{2}-\d{2})\.?$/); return m && m[1] === r.ev && m.index > 0 ? t.slice(0, m.index) : t; };
	const today = () => { const d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };

	const SEL = new Map(); /* kijelölt deal-ek a listában: id → sor (lapozáskor megmarad, szűréskor törlődik) */
	const S = { mode: 'list', q: '', st: 'Open', stage: '', own: '', ev: '', so: 'mod', dir: 'desc', p: 0, won: false, list: null, board: null, err: '', loading: false, main: null, sync: R.sync || 0, seq: 0 };
	try {
		const k = JSON.parse(localStorage.getItem('cpcrm') || '{}');
		['mode', 'st', 'stage', 'own', 'ev', 'so', 'dir'].forEach(x => { if (typeof k[x] === 'string') S[x] = k[x]; });
		S.won = !!k.won;
	} catch (e) { /* privát mód */ }
	function save() { try { localStorage.setItem('cpcrm', JSON.stringify({ mode: S.mode, st: S.st, stage: S.stage, own: S.own, ev: S.ev, so: S.so, dir: S.dir, won: S.won })); } catch (e) { /* privát mód */ } }

	async function post(action, params) {
		const fd = new FormData();
		fd.append('action', action);
		fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j = null;
		try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j && j.data && j.data.msg) || 'Hiba'));
		if (j.data && j.data.sync) S.sync = j.data.sync;
		return j.data;
	}

	const live = () => S.main && S.main.isConnected && S.main.querySelector('.crm');
	function setStatus() { const s = document.getElementById('cpx-status'); if (s && live()) status(s); }
	function status(s) {
		if (S.loading) { s.textContent = 'Betöltés…'; s.className = 'cpx-status'; return; }
		if (S.err) { s.textContent = S.err; s.className = 'cpx-status err'; return; }
		if (S.sync) { const d = new Date(S.sync * 1000); s.textContent = 'Szinkron: ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); s.title = 'Utolsó szinkron a Salesmate-tel'; s.className = 'cpx-status'; }
		else { s.textContent = ''; s.className = 'cpx-status'; }
	}

	/* ------------------------------------------------------------ fej: nézetváltó, kereső, szűrők */
	function opts(list, cur) { return list.map(x => '<option value="' + esc(x[0]) + '"' + (x[0] === cur ? ' selected' : '') + '>' + esc(x[1]) + '</option>').join(''); }
	function ownerOpts() {
		const ids = Object.keys(USERS).sort((a, b) => (USERS[b].a - USERS[a].a) || USERS[a].n.localeCompare(USERS[b].n, 'hu'));
		return [['', 'Minden felelős']].concat(ids.map(i => [i, USERS[i].n + (USERS[i].a ? '' : ' (inaktív)')]));
	}
	function head() {
		const seg = '<div class="crm-seg">' + [['list', 'Lista'], ['board', 'Pipeline']].map(x => '<button type="button" data-mode="' + x[0] + '" class="' + (S.mode === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div>';
		const own = '<select data-f="own" aria-label="Felelős">' + opts(ownerOpts(), S.own) + '</select>';
		let f = '';
		if (S.mode === 'list') {
			f = '<input type="search" data-f="q" placeholder="Keresés: név, e-mail, telefon, helyszín, #szám" value="' + esc(S.q) + '" aria-label="Keresés">' +
				'<select data-f="st" aria-label="Állapot">' + opts([['Open', 'Nyitott'], ['Won', 'Megnyert'], ['Lost', 'Elvesztett'], ['', 'Mind']], S.st) + '</select>' +
				'<select data-f="stage" aria-label="Szakasz">' + opts([['', 'Minden szakasz']].concat(STAGES.map(x => [x, x])), S.stage) + '</select>' + own +
				'<select data-f="ev" aria-label="Rendezvény">' + opts([['', 'Minden rendezvény'], ['up', 'Közelgő rendezvény'], ['past', 'Lezajlott rendezvény'], ['none', 'Nincs dátum']], S.ev) + '</select>' +
				'<select data-f="sort" class="crm-sortsel" aria-label="Rendezés">' + opts([['mod:desc', 'Legutóbb módosított'], ['cr:desc', 'Legújabb'], ['ev:asc', 'Rendezvény dátuma ▲'], ['ev:desc', 'Rendezvény dátuma ▼'], ['val:desc', 'Legnagyobb érték'], ['title:asc', 'Név (A–Z)']], S.so + ':' + S.dir) + '</select>';
		} else {
			f = '<input type="search" data-f="q" placeholder="Keresés a táblán: név, e-mail, telefon, helyszín, #szám" value="' + esc(S.q) + '" aria-label="Keresés">' + own + '<label class="crm-chk"><input type="checkbox" data-f="won"' + (S.won ? ' checked' : '') + '> a megnyertek is (közelgő rendezvény)</label>';
		}
		return '<div class="crm-head">' + seg + '<div class="crm-filters">' + f + '</div></div>' +
			'<p class="crm-ro">' + (W ? 'A szakaszt itt is átállíthatod (a pipeline-on húzással, vagy a deal adatlapján) – ez a Salesmate-be is beíródik. A többi adatot egyelőre a Salesmate-ben módosítsd; a változás 10 percen belül (vagy a Frissítés gombbal) ide is átjön.' : 'Csak olvasás – a deal-eket egyelőre a Salesmate-ben módosítsd, a változás 10 percen belül (vagy a Frissítés gombbal) ide is átjön.') + '</p>';
	}

	function view(main) {
		S.main = main;
		main.innerHTML = '<div class="crm">' + head() + '<div class="crm-body" id="crm-body"><div class="cpx-empty">Betöltés…</div></div></div>';
		bindHead(main);
		load();
	}
	function bindHead(main) {
		main.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { if (S.mode === b.dataset.mode) return; S.mode = b.dataset.mode; save(); view(main); }));
		let tq = null;
		main.querySelectorAll('[data-f]').forEach(inp => {
			const k = inp.dataset.f;
			if (k === 'q') { inp.addEventListener('input', () => { clearTimeout(tq); tq = setTimeout(() => { S.q = inp.value.trim(); S.p = 0; SEL.clear(); if (S.mode === 'board' && S.board) redrawBoard(); else load(); }, S.mode === 'board' ? 150 : 300); }); return; }
			inp.addEventListener('change', () => { if (k === 'sort') { const x = inp.value.split(':'); S.so = x[0]; S.dir = x[1]; } else { S[k] = k === 'won' ? inp.checked : inp.value; SEL.clear(); } S.p = 0; save(); load(); });
		});
	}

	async function load() {
		const my = ++S.seq;
		S.loading = true; S.err = ''; setStatus();
		const body = () => S.main && S.main.querySelector('#crm-body');
		try {
			if (S.mode === 'list') {
				const d = await post('cp_crm_list', { q: S.q, st: S.st, stage: S.stage, own: S.own, ev: S.ev, so: S.so, dir: S.dir, p: S.p });
				if (my !== S.seq) return;
				S.list = d;
				S.loading = false;
				if (body()) drawList(body());
			} else {
				const d = await post('cp_crm_board', { own: S.own, won: S.won ? 1 : '' });
				if (my !== S.seq) return;
				S.board = d;
				S.loading = false;
				if (body()) drawBoard(body());
			}
		} catch (e) {
			if (my !== S.seq) return;
			S.loading = false; S.err = e.message;
			if (body()) { body().innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '<br><button class="cpx-btn" type="button" id="crm-retry">Újra</button></div>'; body().querySelector('#crm-retry').onclick = load; }
		}
		setStatus();
	}

	/* ------------------------------------------------------------ lista */
	function stBadge(r) { return '<span class="crm-st ' + esc(String(r.status).toLowerCase()) + '">' + esc(ST_HU[r.status] || r.status) + '</span>'; }
	function evCell(r) {
		if (!r.ev) return '<span class="cpx-muted">–</span>';
		const past = r.ev < today();
		return '<span class="' + (past ? 'cpx-muted' : '') + '">' + esc(huD(r.ev)) + '</span>';
	}
	function contactCell(r) {
		const a = [];
		if (r.cn) a.push('<b>' + esc(r.cn) + '</b>');
		if (r.co) a.push('<small>' + esc(r.co) + '</small>');
		if (r.ce) a.push('<small>' + esc(r.ce) + '</small>');
		if (r.cp) a.push('<small>' + esc(r.cp) + '</small>');
		return a.join('') || '<span class="cpx-muted">–</span>';
	}
	function sortTh(key, label) {
		const on = S.so === key;
		return '<th><button type="button" class="crm-sort' + (on ? ' on' : '') + '" data-so="' + key + '">' + label + (on ? (S.dir === 'asc' ? ' ▲' : ' ▼') : '') + '</button></th>';
	}
	function drawList(box) {
		const d = S.list;
		const by = d.by || {};
		const sum = Object.keys(by).map(k => (ST_HU[k] || k) + ': ' + num(by[k].n) + (by[k].v ? ' · ' + money(by[k].v) : '')).join(' · ');
		let h = '<div class="crm-sum"><b>' + num(d.total) + ' deal</b>' + (sum ? '<span class="cpx-muted"> – ' + esc(sum) + '</span>' : '') + '</div>';
		if (!d.rows.length) { box.innerHTML = h + '<div class="cpx-empty">Nincs a szűrésnek megfelelő deal.</div>'; return; }
		h += '<div class="crm-bulk" id="crm-bulk" hidden></div>';
		h += '<div class="cpx-trk-wrap crm-wrap"><table class="cpx-trk crm-tbl"><thead><tr><th class="crm-cbh"><input type="checkbox" class="crm-cb" data-all aria-label="Az oldal összes deal-jének kijelölése"></th>' + sortTh('title', 'Deal') + '<th>Kapcsolat</th>' + sortTh('ev', 'Rendezvény') + '<th>Csomag</th><th>Szakasz</th>' + sortTh('val', 'Érték') + '<th>Felelős</th>' + sortTh('mod', 'Módosítva') + '</tr></thead><tbody>';
		d.rows.forEach((r, i) => {
			h += '<tr class="crm-row' + (i % 2 ? ' g1' : '') + (SEL.has(r.id) ? ' sel' : '') + '" data-id="' + r.id + '" tabindex="0">' +
				'<td class="crm-cbc"><input type="checkbox" class="crm-cb" data-cb="' + r.id + '"' + (SEL.has(r.id) ? ' checked' : '') + ' aria-label="Kijelölés"></td>' +
				'<td data-l="Deal"><b>' + esc(shortTitle(r)) + '</b><small class="cpx-muted">#' + r.id + '</small></td>' +
				'<td data-l="Kapcsolat">' + contactCell(r) + '</td>' +
				'<td data-l="Rendezvény">' + evCell(r) + (r.venue ? '<small>' + esc(r.venue) + '</small>' : '') + '</td>' +
				'<td data-l="Csomag">' + (r.pkg ? esc(r.pkg) : '<span class="cpx-muted">–</span>') + (r.svc ? '<small>' + esc(r.svc) + '</small>' : '') + '</td>' +
				'<td data-l="Szakasz">' + esc(r.stage) + '<br>' + stBadge(r) + '</td>' +
				'<td data-l="Érték" class="crm-num">' + esc(money(r.value, r.cur)) + '</td>' +
				'<td data-l="Felelős">' + esc(uname(r.own)) + '</td>' +
				'<td data-l="Módosítva"><small>' + esc(huDT(r.mod)) + '</small></td></tr>';
		});
		h += '</tbody></table></div>';
		const pages = Math.ceil(d.total / d.n);
		if (pages > 1) {
			h += '<div class="crm-pager"><button class="cpx-btn ghost" type="button" data-pg="-1"' + (d.p <= 0 ? ' disabled' : '') + '>‹ Előző</button><span>' + (d.p + 1) + ' / ' + pages + ' oldal</span><button class="cpx-btn ghost" type="button" data-pg="1"' + (d.p + 1 >= pages ? ' disabled' : '') + '>Következő ›</button></div>';
		}
		box.innerHTML = h;
		box.querySelectorAll('[data-so]').forEach(b => b.addEventListener('click', () => {
			const k = b.dataset.so;
			if (S.so === k) S.dir = S.dir === 'asc' ? 'desc' : 'asc'; else { S.so = k; S.dir = k === 'title' || k === 'ev' ? 'asc' : 'desc'; }
			S.p = 0; save(); const ss = S.main.querySelector('.crm-sortsel'); if (ss) ss.value = S.so + ':' + S.dir; load();
		}));
		box.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => { S.p = Math.max(0, S.p + Number(b.dataset.pg)); load(); box.scrollIntoView({ block: 'start' }); }));
		bindOpen(box);
		bindSel(box);
	}
	function bindOpen(box) {
		box.querySelectorAll('[data-id]').forEach(r => {
			r.addEventListener('click', e => { if (e.target.closest('a, .crm-cbc, .crm-cb')) return; openDeal(Number(r.dataset.id)); });
			r.addEventListener('keydown', e => { if (e.key === 'Enter') openDeal(Number(r.dataset.id)); });
		});
	}

	/* ------------------------------------------------------------ kijelölés és tömeges műveletek (lista) */
	function bindSel(box) {
		const rows = (S.list && S.list.rows) || [];
		const all = box.querySelector('[data-all]');
		const sync = () => {
			const on = rows.filter(r => SEL.has(r.id)).length;
			if (all) { all.checked = on > 0 && on === rows.length; all.indeterminate = on > 0 && on < rows.length; }
			box.querySelectorAll('[data-cb]').forEach(c => { const id = Number(c.dataset.cb); c.checked = SEL.has(id); c.closest('tr').classList.toggle('sel', SEL.has(id)); });
			drawBulk(box);
		};
		box.querySelectorAll('.crm-cbc').forEach(td => td.addEventListener('click', e => { if (e.target === td) { const c = td.querySelector('.crm-cb'); c.click(); } }));
		box.querySelectorAll('[data-cb]').forEach(c => c.addEventListener('change', () => { const id = Number(c.dataset.cb); const r = rows.find(x => x.id === id); if (c.checked && r) SEL.set(id, r); else SEL.delete(id); sync(); }));
		if (all) all.addEventListener('change', () => { rows.forEach(r => { if (all.checked) SEL.set(r.id, r); else SEL.delete(r.id); }); sync(); });
		sync();
	}
	function drawBulk(box) {
		const bar = box.querySelector('#crm-bulk');
		if (!bar) return;
		const n = SEL.size;
		bar.hidden = !n;
		if (!n) { bar.innerHTML = ''; return; }
		const onPage = ((S.list && S.list.rows) || []).filter(r => SEL.has(r.id)).length;
		bar.innerHTML = '<button type="button" class="crm-bx" data-b="clear" aria-label="Kijelölés törlése">×</button><b>' + n + ' kijelölve</b>' + (onPage < n ? '<small class="cpx-muted">(' + (n - onPage) + ' másik oldalon)</small>' : '') +
			'<span class="crm-bgap"></span>' +
			'<button type="button" class="cpx-btn ghost" data-b="mail">E-mail</button>' +
			(W ? '<button type="button" class="cpx-btn ghost" data-b="stage">Szakasz</button><button type="button" class="cpx-btn ghost" data-b="set">Módosítás</button><button type="button" class="cpx-btn ghost" data-b="won">Megnyert</button><button type="button" class="cpx-btn ghost bad" data-b="lost">Elvesztett</button>' : '') +
			'<button type="button" class="cpx-btn ghost" data-b="csv">Export</button>' +
			(W ? '<button type="button" class="cpx-btn crm-del" data-b="del">Törlés</button>' : '');
		bar.querySelectorAll('[data-b]').forEach(b => b.addEventListener('click', () => bulk(b.dataset.b)));
	}
	const selRows = () => Array.from(SEL.values());
	async function bulk(act) {
		const rows = selRows();
		if (act === 'clear') { SEL.clear(); if (S.list) drawList(S.main.querySelector('#crm-body')); return; }
		if (act === 'csv') return exportCsv(rows);
		if (act === 'mail') {
			const em = Array.from(new Set(rows.map(r => r.ce).filter(Boolean)));
			if (!em.length) { toast('A kijelölt deal-ek kontaktjainak nincs e-mail-címe.', true); return; }
			if (em.length < rows.length) toast((rows.length - em.length) + ' kijelölt deal-nél nincs e-mail-cím – kimaradnak.');
			location.href = 'mailto:?bcc=' + em.map(encodeURIComponent).join(',');
			return;
		}
		if (act === 'stage') return dialog('Szakasz – ' + rows.length + ' deal', '<select data-v>' + opts(STAGES.map(x => [x, x]), '') + '</select>', v => runBulk(rows, 'a(z) „' + v + '” szakaszba helyezés', r => post('cp_crm_move', { id: r.id, to: v })));
		if (act === 'won') return confirmRun(rows, 'megnyertnek jelölés', r => post('cp_crm_move', { id: r.id, st: 'Won' }));
		if (act === 'lost') {
			const w = window.prompt('Az elvesztés oka (nem kötelező) – mind a ' + rows.length + ' deal-hez:', '');
			if (w === null) return;
			return confirmRun(rows, 'elvesztettnek jelölés', r => post('cp_crm_move', { id: r.id, st: 'Lost', why: w }));
		}
		if (act === 'set') {
			const F = R.bulk || {};
			const keys = Object.keys(F);
			if (!keys.length) { toast('Nincs tömegesen írható mező.', true); return; }
			return dialog('Módosítás – ' + rows.length + ' deal', '<label>Mező<select data-f>' + opts(keys.map(k => [k, F[k][0]]), keys[0]) + '</select></label><label>Új érték<span data-vbox></span></label>', (v, f) => runBulk(rows, '„' + F[f][0] + '” → „' + (F[f][1] === 'user' ? uname(v) : v) + '”', r => post('cp_crm_set', { id: r.id, f: f, v: v })), async (sh) => {
				const fs = sh.querySelector('[data-f]'), vb = sh.querySelector('[data-vbox]');
				const fill = async () => {
					const f = fs.value;
					if (F[f][1] === 'user') { vb.innerHTML = '<select data-v>' + opts(Object.keys(USERS).filter(i => USERS[i].a).map(i => [i, USERS[i].n]), '') + '</select>'; return; }
					vb.innerHTML = '<input data-v list="crm-vals" placeholder="írd be, vagy válassz"><datalist id="crm-vals"></datalist>';
					try { const x = await post('cp_crm_values', { f: f }); vb.querySelector('datalist').innerHTML = (x.vals || []).map(v => '<option value="' + esc(v) + '">').join(''); } catch (e) { /* nem kötelező */ }
				};
				fs.addEventListener('change', fill); await fill();
			});
		}
		if (act === 'del') {
			const w = window.prompt('Végleges törlés a Salesmate-ből is: ' + rows.length + ' deal.\nNem vonható vissza. A megerősítéshez írd be: TÖRLÉS', '');
			if (w === null) return;
			if (w.trim() !== 'TÖRLÉS') { toast('Nem írtad be pontosan, hogy TÖRLÉS – nem töröltem semmit.', true); return; }
			return runBulk(rows, 'törlés', r => post('cp_crm_delete', { id: r.id, ok: 'TÖRLÉS' }));
		}
	}
	function confirmRun(rows, what, fn) {
		if (!window.confirm(rows.length + ' deal: ' + what + '?\n\nA Salesmate-ben is átírom, és a Salesmate automatizmusai mindegyiknél lefutnak (pl. e-mail az ügyfélnek).')) return;
		return runBulk(rows, what, fn);
	}
	/* kis párbeszédablak a műveletsáv alatt; ok(v, f) a kiválasztott értékkel */
	function dialog(title, body, ok, init) {
		const box = S.main.querySelector('#crm-bulk');
		let dl = S.main.querySelector('.crm-dlg');
		if (dl) dl.remove();
		dl = document.createElement('div'); dl.className = 'crm-dlg';
		dl.innerHTML = '<b>' + esc(title) + '</b>' + body + '<span class="crm-dlg-b"><button type="button" class="cpx-btn" data-ok>Alkalmaz</button><button type="button" class="cpx-btn ghost" data-no>Mégse</button></span>';
		box.after(dl);
		if (init) init(dl);
		dl.querySelector('[data-no]').onclick = () => dl.remove();
		dl.querySelector('[data-ok]').onclick = () => {
			const v = (dl.querySelector('[data-v]') || {}).value, f = (dl.querySelector('[data-f]') || {}).value;
			if (v == null || String(v).trim() === '') { toast('Adj meg egy értéket.', true); return; }
			const fa = (R.bulk || {})[f];
			if (f && !window.confirm(SEL.size + ' deal: ' + (fa ? '„' + fa[0] + '” mező' : 'módosítás') + ' → „' + (fa && fa[1] === 'user' ? uname(v) : v) + '”?\n\nA Salesmate-be is beírom (a Salesmate automatizmusai lefuthatnak).')) return;
			if (!f && !window.confirm(SEL.size + ' deal áthelyezése: „' + v + '”?\n\nA Salesmate-ben is átírom, és a Salesmate automatizmusai mindegyiknél lefutnak (pl. e-mail az ügyfélnek).')) return;
			dl.remove(); ok(String(v).trim(), f);
		};
	}
	/* deal-enként egymás után; haladás a műveletsávban, a végén összegzés */
	async function runBulk(rows, what, fn) {
		const bar = S.main.querySelector('#crm-bulk');
		let ok = 0; const bad = [];
		for (let i = 0; i < rows.length; i++) {
			if (bar) bar.innerHTML = '<b>' + esc(what) + ': ' + (i + 1) + ' / ' + rows.length + '…</b><span class="crm-prog"><i style="width:' + Math.round(i / rows.length * 100) + '%"></i></span>';
			try { await fn(rows[i]); ok++; SEL.delete(rows[i].id); }
			catch (e) { bad.push(shortTitle(rows[i]) + ': ' + e.message); }
		}
		toast(bad.length ? ok + ' sikerült, ' + bad.length + ' nem – az utóbbiak kijelölve maradtak. ' + bad[0] : 'Kész: ' + ok + ' deal (' + what + ').', !!bad.length);
		load();
	}
	function exportCsv(rows) {
		const H = ['Azonosító', 'Deal', 'Kapcsolat', 'E-mail', 'Telefon', 'Cég', 'Rendezvény dátuma', 'Helyszín', 'Csomag', 'Szolgáltatás', 'Szakasz', 'Állapot', 'Érték', 'Pénznem', 'Felelős', 'Létrehozva', 'Módosítva'];
		const q = v => { const t = v == null ? '' : String(v); return /[";\n]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t; };
		const L = rows.map(r => [r.id, r.title, r.cn, r.ce, r.cp, r.co, r.ev, r.venue, r.pkg, r.svc, r.stage, ST_HU[r.status] || r.status, r.value == null ? '' : String(r.value).replace('.', ','), r.cur, uname(r.own), r.cr, r.mod].map(q).join(';'));
		const blob = new Blob(['\ufeff' + [H.join(';')].concat(L).join('\r\n')], { type: 'text/csv;charset=utf-8' });
		const a = document.createElement('a');
		a.href = URL.createObjectURL(blob); a.download = 'dealek-' + today() + '.csv';
		document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
		toast(rows.length + ' deal exportálva.');
	}

	/* ------------------------------------------------------------ pipeline-tábla */
	function drawBoard(box) {
		const all = S.board.rows || [];
		const rows = S.q ? all.filter(r => hit(r, S.q)) : all;
		const col = {};
		STAGES.forEach(s => { col[s] = []; });
		rows.forEach(r => { (col[r.stage] = col[r.stage] || []).push(r); });
		const names = STAGES.concat(Object.keys(col).filter(s => STAGES.indexOf(s) < 0));
		const t = today();
		let h = '<div class="crm-sumrow"><div class="crm-sum"><b>' + num(rows.length) + ' deal</b>' + (S.q ? '<span class="cpx-muted"> (keresés: „' + esc(S.q) + '”, összesen ' + num(all.length) + ')</span>' : '') + '<span class="cpx-muted"> – ' + esc(money(rows.reduce((a, r) => a + (r.value || 0), 0))) + '</span></div>' +
			'<div class="crm-arr"><button type="button" data-arr="-1" aria-label="Balra">‹</button><button type="button" data-arr="1" aria-label="Jobbra">›</button></div></div><div class="crm-board">';
		names.forEach(s => {
			const l = col[s] || [];
			const v = l.reduce((a, r) => a + (r.value || 0), 0);
			h += '<section class="crm-col" data-stage="' + esc(s) + '"><header><b>' + esc(s) + '</b><small>' + l.length + ' deal' + (v ? ' · ' + esc(money(v)) : '') + '</small></header><div class="crm-cards">';
			l.forEach(r => {
				const soon = r.ev && r.ev >= t && r.ev <= addDays(t, 14);
				h += '<article class="crm-card' + (r.status === 'Won' ? ' won' : '') + (soon ? ' soon' : '') + (r.ev && r.ev < t ? ' past' : '') + (r._busy ? ' busy' : '') + '" data-id="' + r.id + '" tabindex="0"' + (W ? ' draggable="true"' : '') + '>' +
					'<b>' + esc(shortTitle(r)) + '</b>' +
					(r.ev ? '<span class="crm-ev">' + esc(huD(r.ev)) + '</span>' : '<span class="crm-ev cpx-muted">nincs dátum</span>') +
					(r.cn ? '<small>' + esc(r.cn) + (r.co ? ' · ' + esc(r.co) : '') + '</small>' : '') +
					(r.pkg ? '<small class="cpx-muted">' + esc(r.pkg) + '</small>' : '') +
					'<span class="crm-cf"><span>' + esc(money(r.value, r.cur)) + '</span><span class="cpx-muted">' + esc(uname(r.own)) + '</span></span>' +
					(r.status === 'Won' ? stBadge(r) : '') + '</article>';
			});
			if (!l.length) h += '<div class="crm-none">–</div>';
			h += '</div></section>';
		});
		h += '</div>';
		if (W) h += '<div class="crm-drop" aria-hidden="true"><div data-st="Won">Megnyert</div><div data-st="Lost">Elvesztett</div></div>';
		box.innerHTML = h;
		bindOpen(box);
		bindDrag(box);
		const bd = box.querySelector('.crm-board');
		const arr = () => { const a = box.querySelectorAll('[data-arr]'); a[0].disabled = bd.scrollLeft <= 2; a[1].disabled = bd.scrollLeft + bd.clientWidth >= bd.scrollWidth - 2; };
		box.querySelectorAll('[data-arr]').forEach(b => b.addEventListener('click', () => bd.scrollBy({ left: Number(b.dataset.arr) * 250, behavior: 'smooth' })));
		bd.addEventListener('scroll', arr, { passive: true });
		fitBoard(); arr();
	}
	/* húzás a szakaszok között, illetve a Megnyert / Elvesztett mezőre */
	function bindDrag(box) {
		if (!W) return;
		const bd = box.querySelector('.crm-board');
		let dragId = 0;
		box.querySelectorAll('.crm-card[draggable]').forEach(c => {
			c.addEventListener('dragstart', e => { dragId = Number(c.dataset.id); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(dragId)); c.classList.add('drag'); document.body.classList.add('crm-dragging'); });
			c.addEventListener('dragend', () => { dragId = 0; c.classList.remove('drag'); document.body.classList.remove('crm-dragging'); box.querySelectorAll('.over').forEach(x => x.classList.remove('over')); });
		});
		box.querySelectorAll('.crm-col[data-stage], .crm-drop [data-st]').forEach(z => {
			z.addEventListener('dragover', e => { if (!dragId) return; e.preventDefault(); e.dataTransfer.dropEffect = 'move'; z.classList.add('over'); });
			z.addEventListener('dragleave', e => { if (!z.contains(e.relatedTarget)) z.classList.remove('over'); });
			z.addEventListener('drop', e => {
				e.preventDefault(); z.classList.remove('over');
				const id = dragId; dragId = 0;
				document.body.classList.remove('crm-dragging');
				if (id) doMove(id, z.dataset.stage ? { to: z.dataset.stage } : { st: z.dataset.st });
			});
		});
		/* a tábla szélénél vízszintesen görget húzás közben */
		bd.addEventListener('dragover', e => { if (!dragId) return; const r = bd.getBoundingClientRect(); if (e.clientX < r.left + 70) bd.scrollLeft -= 20; else if (e.clientX > r.right - 70) bd.scrollLeft += 20; });
	}
	function redrawBoard() {
		const box = S.main && S.main.querySelector('#crm-body');
		if (!box || S.mode !== 'board' || !S.board) return;
		const bd = box.querySelector('.crm-board');
		const keep = { x: bd ? bd.scrollLeft : 0, y: window.scrollY, cols: {} };
		box.querySelectorAll('.crm-col').forEach(c => { keep.cols[c.dataset.stage] = c.querySelector('.crm-cards').scrollTop; });
		drawBoard(box);
		const nb = box.querySelector('.crm-board');
		if (nb) nb.scrollLeft = keep.x;
		box.querySelectorAll('.crm-col').forEach(c => { if (keep.cols[c.dataset.stage]) c.querySelector('.crm-cards').scrollTop = keep.cols[c.dataset.stage]; });
		window.scrollTo(0, keep.y);
	}
	function toast(t, bad) {
		let d = document.getElementById('cpx-toast');
		if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); }
		d.textContent = t; d.classList.toggle('bad', !!bad); d.classList.add('on');
		clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), bad ? 6000 : 3200);
	}
	/* áthelyezés: p = { to: szakasz } vagy { st: 'Won' | 'Lost' | 'Open' }; a Salesmate-be is ír */
	async function doMove(id, p, title) {
		const rows = S.board ? S.board.rows : [];
		const r = rows.find(x => x.id === id);
		const name = title || (r ? shortTitle(r) : 'Deal #' + id);
		if (p.to && r && r.stage === p.to) return false;
		const what = p.to ? 'a(z) „' + p.to + '” szakaszba' : (p.st === 'Won' ? 'a megnyertek közé' : p.st === 'Lost' ? 'az elvesztettek közé' : 'újra nyitottnak');
		if (!window.confirm('Áthelyezed: ' + name + ' → ' + what + '?\n\nA Salesmate-ben is átírom, és a Salesmate automatizmusai lefutnak (pl. e-mail az ügyfélnek).')) return false;
		if (p.st === 'Lost') { const w = window.prompt('Az elvesztés oka (nem kötelező):', ''); if (w === null) return false; p.why = w; }
		const old = r ? Object.assign({}, r) : null;
		if (r) { if (p.to) r.stage = p.to; if (p.st) r.status = p.st; r._busy = 1; redrawBoard(); }
		let ok = false;
		try {
			const x = await post('cp_crm_move', Object.assign({ id: id }, p));
			if (x.row && S.board) { const i = S.board.rows.findIndex(y => y.id === id); if (i >= 0) S.board.rows[i] = x.row; }
			toast(x.changed ? 'Kész – a Salesmate-ben is átírva.' : 'Nem kellett változtatni, már ott volt.');
			ok = true;
		} catch (e) {
			if (r && old) Object.assign(r, old);
			toast('Nem sikerült: ' + e.message, true);
		}
		if (S.board) {
			S.board.rows.forEach(y => { delete y._busy; });
			S.board.rows = S.board.rows.filter(y => y.status === 'Open' || (S.won && y.status === 'Won'));
			redrawBoard();
		}
		return ok;
	}
	/* az oszlopok a képernyő aljáig érjenek (a saját görgetésük így mindig elérhető) */
	function fitBoard() {
		const bd = S.main && S.main.querySelector('.crm-board');
		if (!bd) return;
		const top = bd.getBoundingClientRect().top + window.scrollY;
		bd.style.setProperty('--crm-h', Math.max(360, window.innerHeight - Math.max(0, top - window.scrollY) - 26) + 'px');
	}
	window.addEventListener('resize', () => { if (live() && S.mode === 'board') fitBoard(); });
	/* keresés a táblán (ékezet- és kisbetű-független; telefonszámnál csak a számjegyek) */
	const fold = s => String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
	function hit(r, q) {
		const t = fold(q).trim();
		if (!t) return true;
		if (/^#?\d{3,7}$/.test(t)) return String(r.id) === t.replace('#', '') || String(r.cp || '').replace(/\D/g, '').includes(t.replace('#', ''));
		const dq = t.replace(/[^\d]/g, '');
		if (dq.length >= 6 && String(r.cp || '').replace(/\D/g, '').includes(dq.slice(-8))) return true;
		const hay = fold([r.title, r.cn, r.ce, r.co, r.venue, r.pkg, r.svc, uname(r.own)].join(' '));
		return t.split(/\s+/).every(w => hay.includes(w));
	}
	function addDays(s, n) { const p = s.split('-').map(Number); const d = new Date(p[0], p[1] - 1, p[2] + n); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

	/* ------------------------------------------------------------ deal-adatlap */
	const LBL = {
		title: 'Név', stage: 'Szakasz', status: 'Állapot', owner_id: 'Felelős', value: 'Érték', currency: 'Pénznem', contact_id: 'Kontakt', company_id: 'Cég',
		source: 'Forrás', tags: 'Címkék', lost_reason: 'Elvesztés oka', est_close_d: 'Várható lezárás', closed_at: 'Lezárva', closed_stage: 'Lezárási szakasz',
		last_note: 'Utolsó jegyzet', event_d: 'Rendezvény dátuma', event_end_d: 'Rendezvény vége', start_at: 'Kezdés', venue: 'Helyszín', pkg_old: 'Választott csomag (régi)',
		note: 'Megjegyzés', pay_method: 'Fizetési mód', background: 'Fotófal / háttér', reminder: 'Menjen emlékeztető', paid: 'Bejött az utalás', operator: 'Gépkezelő asszisztens',
		album: 'Fotóalbum', template_st: 'Template', ctype: 'Szerződés (EV/KFT)', cal_check: 'Naptár', service: 'Szolgáltatás', spinner_pkg: 'Spinner csomag',
		template_paid: 'Fizetős template', melange_pkg: 'Melange csomag', pkg_v2: 'Választott csomag', pkg_fix: 'Választott csomag 2', template_link: 'Sablon kép',
		portal_link: 'Ügyfélportál', guests: 'Tervezett létszám', km_fee: 'Kiszállási díj', hours: 'Rendelkezésre állás (óra)', extension: 'Hosszabbítás',
		coupon: 'Kuponkód', services_more: 'További szolgáltatások', extras: 'Választott extra', services_ec: 'EventCrazy szolgáltatás(ok)', services_cp: 'Választott szolgáltatás',
		package: 'Csomag'
	};
	function fmtVal(k, v, d) {
		if (v == null || v === '') return '';
		if (k === 'event_d' || k === 'event_end_d' || k === 'est_close_d') return esc(huD(v));
		if (k === 'start_at' || k === 'closed_at') return esc(huDT(v));
		if (k === 'value') return esc(money(v, d && d.currency));
		if (k === 'km_fee') return esc(money(v));
		if (k === 'status') return esc(ST_HU[v] || v);
		if (k === 'owner_id') return esc(uname(v));
		if (k === 'template_link' || k === 'portal_link') { const u = safeUrl(v); return u ? '<a href="' + esc(u) + '" target="_blank" rel="noopener">Megnyitás ↗</a>' : esc(v); }
		if (/^(services_|extras|tags)/.test(k)) return String(v).split(' | ').map(x => '<span class="crm-tag">' + esc(x) + '</span>').join(' ');
		return esc(v).replace(/\n/g, '<br>');
	}
	function dl(d, keys) {
		const rows = keys.map(k => { const v = fmtVal(k, d[k], d); return v ? '<dt>' + esc(LBL[k] || k) + '</dt><dd>' + v + '</dd>' : ''; }).join('');
		return rows ? '<dl class="crm-dl">' + rows + '</dl>' : '';
	}
	function sheet(html) {
		const m = document.getElementById('cpx-modal');
		if (!m) return null;
		m.innerHTML = '<div class="cpx-sheet wide crm-sheet" role="dialog" aria-modal="true">' + html + '</div>';
		m.hidden = false;
		return m.querySelector('.crm-sheet');
	}
	const sheetHead = t => '<div class="cpx-sheet-h"><h3>' + t + '</h3><button class="cpx-x" type="button" data-close aria-label="Bezárás">×</button></div>';

	async function openDeal(id) {
		const sh = sheet(sheetHead('Deal #' + id) + '<div class="cpx-empty">Betöltés…</div>');
		if (!sh) return;
		let x;
		try { x = await post('cp_crm_deal', { id: id }); }
		catch (e) { if (sh.isConnected) sh.innerHTML = sheetHead('Deal #' + id) + '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		if (!sh.isConnected) return;
		const d = x.deal, c = x.contact, co = x.company;
		const smUrl = C.sm ? C.sm + d.id : '';
		let h = sheetHead(esc(d.title || 'Deal #' + d.id)) +
			'<div class="crm-top"><span>' + esc(d.stage) + '</span> ' + stBadge(d) + '<b>' + esc(money(d.value, d.currency)) + '</b>' +
			(smUrl ? '<a class="cpx-btn ghost" href="' + esc(smUrl) + '" target="_blank" rel="noopener">Salesmate ↗</a>' : '') + '</div>' +
			(d.deleted_at ? '<p class="crm-warn">Ez a deal a Salesmate-ben már nem található (törölték).</p>' : '') +
			(W && !d.deleted_at ? '<div class="crm-act">' + (d.status === 'Open' ? '<select data-mv aria-label="Szakasz">' + opts(STAGES.map(x => [x, x]), d.stage) + '</select><button class="cpx-btn ghost" type="button" data-mvst="Won">Megnyert</button><button class="cpx-btn ghost bad" type="button" data-mvst="Lost">Elvesztett</button>' : '<button class="cpx-btn ghost" type="button" data-mvst="Open">Újranyitás</button>') + '</div>' : '');
		h += '<h4>Rendezvény</h4>' + (dl(d, ['event_d', 'event_end_d', 'start_at', 'venue', 'guests', 'hours', 'service', 'package', 'services_cp', 'services_ec', 'services_more', 'extras', 'background', 'km_fee', 'extension', 'operator', 'album', 'template_st', 'template_paid', 'template_link', 'portal_link', 'coupon']) || '<p class="cpx-muted">Nincs megadva.</p>');
		h += '<h4>Kapcsolat</h4>';
		if (c) {
			const ph = c.mobile || c.phone;
			h += '<dl class="crm-dl"><dt>Név</dt><dd>' + esc(c.name) + '</dd>' +
				(c.email ? '<dt>E-mail</dt><dd><a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a></dd>' : '') +
				(ph ? '<dt>Telefon</dt><dd><a href="tel:' + esc(String(ph).replace(/[^\d+]/g, '')) + '">' + esc(ph) + '</a>' + (c.mobile && c.phone && c.phone !== c.mobile ? ' · ' + esc(c.phone) : '') + '</dd>' : '') +
				(co ? '<dt>Cég</dt><dd>' + esc(co.name) + (safeUrl(co.website) ? ' · <a href="' + esc(safeUrl(co.website)) + '" target="_blank" rel="noopener">weboldal ↗</a>' : '') + '</dd>' : '') +
				(c.city ? '<dt>Város</dt><dd>' + esc(c.city) + '</dd>' : '') +
				(c.utm_source || c.utm_campaign ? '<dt>Honnan jött</dt><dd>' + esc([c.utm_source, c.utm_medium, c.utm_campaign].filter(Boolean).join(' / ')) + '</dd>' : '') +
				'</dl>';
		} else h += '<p class="cpx-muted">Nincs kontakt rendelve a deal-hez.</p>';
		h += '<h4>Pénzügy és szerződés</h4>' + (dl(d, ['value', 'pay_method', 'paid', 'ctype']) || '<p class="cpx-muted">Nincs megadva.</p>');
		const notes = dl(d, ['note', 'last_note']) + (d.description ? '<div class="crm-desc">' + esc(d.description).replace(/\n/g, '<br>') + '</div>' : '');
		if (notes) h += '<h4>Megjegyzések</h4>' + notes;
		h += '<h4>Állapot</h4>' + dl(d, ['stage', 'status', 'owner_id', 'source', 'tags', 'lost_reason', 'closed_at', 'reminder', 'cal_check']) +
			'<p class="crm-meta">Létrehozva: ' + esc(huDT(d.created_at)) + (d.created_by_n ? ' (' + esc(d.created_by_n) + ')' : '') + ' · Utolsó módosítás: ' + esc(huDT(d.modified_at)) + (d.modified_by_n ? ' (' + esc(d.modified_by_n) + ')' : '') + '</p>';
		if (x.others && x.others.length) {
			h += '<h4>Ugyanennek a kontaktnak további deal-jei</h4><ul class="crm-list">' + x.others.map(o => '<li><button type="button" class="crm-link" data-open="' + o.id + '">' + esc(o.title || 'Deal #' + o.id) + '</button> <small class="cpx-muted">' + esc([o.event_d ? huD(o.event_d) : '', o.stage, ST_HU[o.status] || o.status].filter(Boolean).join(' · ')) + '</small></li>').join('') + '</ul>';
		}
		if (x.acts && x.acts.length) {
			h += '<h4>Tevékenységek</h4><ul class="crm-list">' + x.acts.map(a => '<li class="' + (Number(a.completed) ? 'done' : '') + '"><b>' + esc(a.title || a.type) + '</b> <small class="cpx-muted">' + esc([a.type, huDT(a.due_at), a.owner, Number(a.completed) ? 'kész' : ''].filter(Boolean).join(' · ')) + '</small>' + (a.description ? '<div class="crm-desc">' + esc(a.description).replace(/\n/g, '<br>') + '</div>' : '') + '</li>').join('') + '</ul>';
		}
		h += '<h4>Változások</h4>';
		if (x.hist && x.hist.length) {
			h += '<ul class="crm-list crm-hist">' + x.hist.map(r => '<li><small class="cpx-muted">' + esc(huDT(r.at)) + (r.by_user ? ' · ' + esc(r.by_user) : '') + '</small><div><b>' + esc(LBL[r.field] || r.field) + ':</b> ' + (r.old_val ? '<s>' + esc(histVal(r.field, r.old_val)) + '</s> → ' : '') + esc(histVal(r.field, r.new_val) || '(üres)') + '</div></li>').join('') + '</ul>';
		} else h += '<p class="cpx-muted">A panel mostantól rögzít minden változást – ennél a deal-nél eddig nem volt.</p>';
		sh.innerHTML = h;
		const after = async ok => { if (!ok) return; if (S.mode === 'list') load(); openDeal(id); };
		const mv = sh.querySelector('[data-mv]');
		if (mv) mv.addEventListener('change', async () => { const ok = await doMove(id, { to: mv.value }, d.title); if (!ok) mv.value = d.stage; after(ok); });
		sh.querySelectorAll('[data-mvst]').forEach(b => b.addEventListener('click', async () => after(await doMove(id, { st: b.dataset.mvst }, d.title))));
		sh.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => openDeal(Number(b.dataset.open))));
	}
	function histVal(k, v) {
		if (v == null || v === '') return '';
		if (k === 'owner_id') return uname(v);
		if (k === 'status') return ST_HU[v] || v;
		if (k === 'event_d') return huD(v);
		if (k === 'value') return money(Number(v));
		return String(v).length > 300 ? String(v).slice(0, 300) + '…' : String(v);
	}

	/* ------------------------------------------------------------ Frissítés gomb: szinkron a Salesmate-tel, majd újratöltés */
	async function refresh() {
		S.loading = true; S.err = ''; setStatus();
		try { await post('cp_crm_sync', {}); } catch (e) { S.err = e.message; }
		S.loading = false;
		if (live()) load(); else setStatus();
	}

	window.CPCrm = { view, status, refresh, open: openDeal };
})();
