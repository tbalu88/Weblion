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
	const S = { bso: 'ev', qf: [], dense: false, fold: [], mode: 'list', q: '', st: 'Open', stage: '', own: '', ev: '', so: 'mod', dir: 'desc', p: 0, won: false, list: null, board: null, err: '', loading: false, main: null, sync: R.sync || 0, seq: 0 };
	try {
		const k = JSON.parse(localStorage.getItem('cpcrm') || '{}');
		['mode', 'st', 'stage', 'own', 'ev', 'so', 'dir', 'bso'].forEach(x => { if (typeof k[x] === 'string') S[x] = k[x]; });
		S.won = !!k.won;
		S.dense = !!k.dense;
		if (Array.isArray(k.qf)) S.qf = k.qf.filter(x => typeof x === 'string');
		if (Array.isArray(k.fold)) S.fold = k.fold.filter(x => typeof x === 'string');
		if (S.mode === 'leads') S.mode = 'board';
	} catch (e) { /* privát mód */ }
	function save() { try { localStorage.setItem('cpcrm', JSON.stringify({ mode: S.mode, st: S.st, stage: S.stage, own: S.own, ev: S.ev, so: S.so, dir: S.dir, bso: S.bso, won: S.won, dense: S.dense, qf: S.qf, fold: S.fold })); } catch (e) { /* privát mód */ } }

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
		if (S.mode === 'leads') {
			f = '<input type="search" data-f="q" placeholder="Keresés a megkeresések között: név, e-mail, telefon" value="' + esc(S.q) + '" aria-label="Keresés">' +
				'<select data-f="lst" aria-label="Szűrés">' + opts([['', 'Minden megkeresés'], ['spam', 'Spamnek jelöltek']], S.lst || '') + '</select>';
		} else if (S.mode === 'list') {
			f = '<input type="search" data-f="q" placeholder="Keresés: név, e-mail, telefon, helyszín, #szám" value="' + esc(S.q) + '" aria-label="Keresés">' +
				'<select data-f="st" aria-label="Állapot">' + opts([['Open', 'Nyitott'], ['Won', 'Megnyert'], ['Lost', 'Elvesztett'], ['', 'Mind']], S.st) + '</select>' +
				'<select data-f="stage" aria-label="Szakasz">' + opts([['', 'Minden szakasz']].concat(STAGES.map(x => [x, x])), S.stage) + '</select>' + own +
				'<select data-f="ev" aria-label="Rendezvény">' + opts([['', 'Minden rendezvény'], ['up', 'Közelgő rendezvény'], ['past', 'Lezajlott rendezvény'], ['none', 'Nincs dátum']], S.ev) + '</select>' +
				(S.who ? '<span class="d3-chip">' + (S.who.k === 'co' ? '🏢 ' : '👤 ') + esc(S.who.n) + '<button type="button" data-whox aria-label="Szűrés törlése" title="Szűrés törlése">×</button></span>' : '<button type="button" class="crm-qfb" data-who>+ Cég / kontakt</button>') +
				'<select data-f="sort" class="crm-sortsel" aria-label="Rendezés">' + opts([['mod:desc', 'Legutóbb módosított'], ['cr:desc', 'Legújabb'], ['ev:asc', 'Rendezvény dátuma ▲'], ['ev:desc', 'Rendezvény dátuma ▼'], ['val:desc', 'Legnagyobb érték'], ['title:asc', 'Név (A–Z)']], S.so + ':' + S.dir) + '</select>';
		} else {
			f = '<input type="search" data-f="q" placeholder="Keresés a táblán: név, e-mail, telefon, helyszín, #szám" value="' + esc(S.q) + '" aria-label="Keresés">' + own +
				'<select data-f="bso" aria-label="Rendezés">' + opts([['ev', 'Rendezvény dátuma szerint'], ['val', 'Érték szerint (legnagyobb elöl)'], ['title', 'Név szerint (A–Z)'], ['mod', 'Legutóbb módosított elöl'], ['rot', 'Legrégebben rohadó elöl']], S.bso) + '</select>' + '<label class="crm-chk"><input type="checkbox" data-f="won"' + (S.won ? ' checked' : '') + '> a megnyertek is (közelgő rendezvény)</label>';
		}
		if (S.mode === 'board') {
			const QF = [['mine', 'Az enyém'], ['late', 'Lejárt teendő'], ['noact', 'Nincs teendő'], ['rot', 'Rohad'], ['d30', '30 napon belül'], ['nodate', 'Nincs dátum']].filter(x => x[0] !== 'mine' || R.me_sm);
			f += '</div><div class="crm-qf">' + QF.map(x => '<button type="button" class="crm-qfb' + (S.qf.includes(x[0]) ? ' on' : '') + '" data-qf="' + x[0] + '">' + x[1] + '</button>').join('') +
				'<span class="crm-bgap"></span><span class="crm-seg crm-dens"><button type="button" data-dense="0" class="' + (S.dense ? '' : 'on') + '">Részletes</button><button type="button" data-dense="1" class="' + (S.dense ? 'on' : '') + '">Kompakt</button></span>';
		}
		return '<div class="crm-head"><div class="crm-hl">' + seg + (R.edit ? '<button class="cpx-btn" type="button" data-new>+ Új deal</button>' : '') + '</div><div class="crm-filters">' + f + '</div></div>' +
			(S.mode === 'leads' ? '<p class="crm-ro">A weboldal ajánlatkérő űrlapjairól beérkező megkeresések – azonnal, a beküldés pillanatában. A Salesmate ugyanúgy megkapja őket; amikor a szinkron áthozza a deal-t, itt össze is kapcsolódnak.</p>' : '<p class="crm-ro">' + (W ? 'Minden módosítás a Salesmate-be is beíródik; a Salesmate-ben végzett változás 10 percen belül (vagy a Frissítés gombbal) itt is megjelenik.' : 'Csak olvasás – a deal-eket egyelőre a Salesmate-ben módosítsd, a változás 10 percen belül (vagy a Frissítés gombbal) ide is átjön.') + '</p>');
	}

	function view(main) {
		S.main = main;
		main.innerHTML = '<div class="crm">' + head() + '<div class="crm-body" id="crm-body"><div class="cpx-empty">Betöltés…</div></div></div>';
		bindHead(main);
		load();
	}
	function bindHead(main) {
		main.querySelectorAll('[data-qf]').forEach(b => b.addEventListener('click', () => { const k = b.dataset.qf; S.qf = S.qf.includes(k) ? S.qf.filter(x => x !== k) : S.qf.concat([k]); b.classList.toggle('on'); save(); redrawBoard(); }));
		main.querySelectorAll('[data-dense]').forEach(b => b.addEventListener('click', () => { S.dense = b.dataset.dense === '1'; main.querySelectorAll('[data-dense]').forEach(x => x.classList.toggle('on', x === b)); save(); redrawBoard(); }));
		const nb = main.querySelector('[data-new]');
		if (nb) nb.addEventListener('click', newDeal);
		const wb = main.querySelector('[data-who]');
		if (wb) wb.addEventListener('click', e => { e.stopPropagation(); if (!wb.nextElementSibling || !wb.nextElementSibling.classList.contains('d3-wpop')) whoPicker(wb); });
		const wx = main.querySelector('[data-whox]');
		if (wx) wx.addEventListener('click', () => { S.who = null; S.p = 0; SEL.clear(); view(main); });
		main.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { if (S.mode === b.dataset.mode) return; S.mode = b.dataset.mode; save(); view(main); }));
		let tq = null;
		main.querySelectorAll('[data-f]').forEach(inp => {
			const k = inp.dataset.f;
			if (k === 'q') { inp.addEventListener('input', () => { clearTimeout(tq); tq = setTimeout(() => { S.q = inp.value.trim(); S.p = 0; SEL.clear(); if (S.mode === 'board' && S.board) redrawBoard(); else load(); }, S.mode === 'board' ? 150 : 300); }); return; }
			if (k === 'lst') { inp.addEventListener('change', () => { S.lst = inp.value; load(); }); return; }
			inp.addEventListener('change', () => { if (k === 'sort') { const x = inp.value.split(':'); S.so = x[0]; S.dir = x[1]; } else { S[k] = k === 'won' ? inp.checked : inp.value; SEL.clear(); } S.p = 0; save(); if (k === 'bso' && S.board) { redrawBoard(); return; } load(); });
		});
	}

	async function load() {
		const my = ++S.seq;
		S.loading = true; S.err = ''; setStatus();
		const body = () => S.main && S.main.querySelector('#crm-body');
		try {
			if (S.mode === 'leads') {
				const d = await post('cp_lead_list', { q: S.q, st: S.lst || '' });
				if (my !== S.seq) return;
				S.leads = d; S.loading = false;
				if (body()) drawLeads(body());
			} else if (S.mode === 'list') {
				const d = await post('cp_crm_list', Object.assign({ q: S.q, st: S.st, stage: S.stage, own: S.own, ev: S.ev, so: S.so, dir: S.dir, p: S.p }, S.who ? { [S.who.k]: S.who.id } : {}));
				if (my !== S.seq) return;
				S.list = d;
				S.loading = false;
				if (body()) drawList(body());
			} else {
				const [d, lp] = await Promise.all([post('cp_crm_board', { own: S.own, won: S.won ? 1 : '' }), post('cp_lead_list', { pending: 1 }).catch(() => null)]);
				if (my !== S.seq) return;
				S.board = d;
				S.pend = lp || { rows: [], fresh: {} };
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

	/* ------------------------------------------------------------ v1.42: megkeresések (a weboldal űrlapjairól, párhuzamosan a Salesmate-tel) */
	const agoLead = s => { const d = (Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 60000; return d < 2 ? 'most' : d < 60 ? Math.round(d) + ' perce' : d < 1440 ? Math.round(d / 60) + ' órája' : huDT(s); };
	/* a pipeline-on várakozó megkeresés adatlapja (amíg a Salesmate-deal át nem jön) */
	function openLead(id) {
		const x = ((S.pend && S.pend.rows) || []).find(r => r.id === id);
		if (!x) return;
		const L = S.pend.labels || {}, COLS = S.pend.cols || {};
		const lab = k => L[k] || (COLS[k] && LBL[COLS[k]]) || k.replace(/^(Deal|Contact|Company)\./, '');
		const sh = sheet(sheetHead(esc(x.n || x.e || 'Új megkeresés')) + '<p class="crm-warn" style="background:#FFF7E0;color:#7F5F01">A weboldalról érkezett ' + esc(agoLead(x.at)) + ' (' + esc(x.form || 'űrlap') + '). A Salesmate-ben létrejött deal pár percen belül átjön, és ez a kártya a helyére kerül.</p>' +
			'<dl class="crm-dl">' + (x.e ? '<dt>E-mail</dt><dd><a href="mailto:' + esc(x.e) + '">' + esc(x.e) + '</a></dd>' : '') + (x.p ? '<dt>Telefon</dt><dd><a href="tel:' + esc(String(x.p).replace(/[^\d+]/g, '')) + '">' + esc(x.p) + '</a></dd>' : '') +
			Object.keys(x.f).filter(k => x.f[k] !== '' && !/^Contact\.(firstName|lastName|name|email|mobile|phone)$/.test(k)).map(k => '<dt>' + esc(lab(k)) + '</dt><dd>' + esc(x.f[k]) + '</dd>').join('') + '</dl>');
		return sh;
	}
	function drawLeads(box) {
		const x = S.leads || { rows: [] }, L = x.labels || {}, COLS = x.cols || {};
		const lab = k => L[k] || (COLS[k] && LBL[COLS[k]]) || k.replace(/^(Deal|Contact|Company)\./, '');
		const agoS = s => { const d = (Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 60000; return d < 2 ? 'most' : d < 60 ? Math.round(d) + ' perce' : d < 1440 ? Math.round(d / 60) + ' órája' : huDT(s); };
		if (!x.rows.length) { box.innerHTML = '<div class="cpx-empty"><b>Még nincs megkeresés</b><span>Az első beküldött ajánlatkérés pár másodpercen belül itt jelenik meg. A korábbiak a Salesmate-ből a Lista nézetben vannak.</span></div>'; return; }
		box.innerHTML = '<div class="crm-leads">' + x.rows.map(r => {
			const f = Object.keys(r.f).filter(k => r.f[k] !== '' && !/^(Contact\.(firstName|lastName|name|email|mobile|phone))$/.test(k));
			return '<article class="crm-lead' + (r.st === 'new' ? ' new' : '') + '" data-lead="' + r.id + '"><div class="crm-lh"><div><b>' + esc(r.n || r.e || 'Névtelen') + '</b>' + (r.st === 'new' ? ' <span class="crm-lnew">új</span>' : '') +
				'<small>' + esc([r.e, r.p].filter(Boolean).join(' · ')) + '</small></div><div class="crm-lt"><small>' + esc(agoS(r.at)) + '</small><small class="cpx-muted">' + esc(r.form || 'űrlap') + '</small></div></div>' +
				(f.length ? '<dl class="crm-dl crm-ldl">' + f.map(k => '<dt>' + esc(lab(k)) + '</dt><dd>' + esc(r.f[k]) + '</dd>').join('') + '</dl>' : '') +
				'<div class="crm-la">' + (r.deal ? '<button type="button" class="cpx-btn ghost" data-ldeal="' + r.deal.id + '">Deal: ' + esc(r.deal.t) + ' <small>(' + esc(r.deal.stage) + ')</small></button>' : '<span class="cpx-muted crm-lwait">A Salesmate-deal még nem jött át – a szinkron pár percen belül hozza, utána itt összekapcsolódik.</span>') +
				'<span class="crm-bgap"></span>' + (r.st === 'new' ? '<button type="button" class="cpx-btn ghost" data-lst="seen">Láttam</button>' : '') +
				(r.st === 'spam' ? '<button type="button" class="cpx-btn ghost" data-lst="seen">Nem spam</button>' : '<button type="button" class="cpx-btn ghost bad" data-lst="spam">Spam</button>') + '</div></article>';
		}).join('') + '</div>';
		box.querySelectorAll('[data-ldeal]').forEach(b => b.addEventListener('click', () => openDeal(Number(b.dataset.ldeal))));
		box.querySelectorAll('[data-lst]').forEach(b => b.addEventListener('click', async () => {
			const id = Number(b.closest('[data-lead]').dataset.lead);
			try { await post('cp_lead_set', { id: id, st: b.dataset.lst }); load(); } catch (e) { alert(e.message); }
		}));
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
		const t0 = today(), now = (S.board && S.board.now) || '';
		const QFN = {
			mine: r => String(r.own) === String(R.me_sm),
			late: r => r.na && r.na < now,
			noact: r => !r.na,
			rot: r => r.rot && r.rot <= t0,
			d30: r => r.ev && r.ev >= t0 && r.ev <= addDays(t0, 30),
			nodate: r => !r.ev
		};
		const rows = all.filter(r => (!S.q || hit(r, S.q)) && S.qf.every(k => !QFN[k] || QFN[k](r)));
		const col = {};
		STAGES.forEach(s => { col[s] = []; });
		rows.forEach(r => { (col[r.stage] = col[r.stage] || []).push(r); });
		const SORT = {
			ev: (a, b) => (a.ev || '9999') .localeCompare(b.ev || '9999') || b.id - a.id,
			val: (a, b) => (b.value || 0) - (a.value || 0) || b.id - a.id,
			title: (a, b) => shortTitle(a).replace(/^\[[^\]]*\]\s*/, '').localeCompare(shortTitle(b).replace(/^\[[^\]]*\]\s*/, ''), 'hu'),
			mod: (a, b) => (b.mod || '').localeCompare(a.mod || ''),
			rot: (a, b) => (a.rot || '9999').localeCompare(b.rot || '9999')
		};
		Object.keys(col).forEach(k => col[k].sort(SORT[S.bso] || SORT.ev));
		const names = STAGES.concat(Object.keys(col).filter(s => STAGES.indexOf(s) < 0));
		const t = today();
		/* v1.66: mobilon a Salesmate mintájára – oszloponként lapozható, nincs összecsukás, részletes kártya */
		const MOB = window.matchMedia && window.matchMedia('(max-width:760px)').matches;
		const prog = i => '<span class="crm-prog" aria-hidden="true">' + names.map((x, j) => '<i class="' + (j === i ? 'on' : '') + '"></i>').join('') + '</span>';
		let h = '<div class="crm-sumrow"><div class="crm-sum"><b>' + num(rows.length) + ' deal</b>' + (S.q || S.qf.length ? '<span class="cpx-muted"> (szűrve, összesen ' + num(all.length) + ')</span>' : '') + '<span class="cpx-muted"> – ' + esc(money(rows.reduce((a, r) => a + (r.value || 0), 0))) + '</span></div>' +
			'<div class="crm-arr"><button type="button" data-arr="-1" aria-label="Balra">‹</button><button type="button" data-arr="1" aria-label="Jobbra">›</button></div></div><div class="crm-board' + (S.dense ? ' dense' : '') + '">';
		names.forEach(s => {
			const l = col[s] || [];
			const v = l.reduce((a, r) => a + (r.value || 0), 0);
			if (S.fold.includes(s) && !MOB) {
				h += '<section class="crm-col folded" data-stage="' + esc(s) + '"><button type="button" class="crm-unfold" data-fold="' + esc(s) + '" title="Oszlop kinyitása"><span>' + esc(s) + '</span><b>' + l.length + '</b></button></section>';
				return;
			}
			const pend = s === STAGES[0] && S.pend && !S.qf.length ? S.pend.rows.filter(x => !S.q || hit({ title: x.n, cn: x.n, ce: x.e, cp: x.p }, S.q)) : [];
			h += '<section class="crm-col" data-stage="' + esc(s) + '"><header><div class="crm-chh"><span class="crm-chip">' + esc(s) + '</span><span class="crm-chb">' + (R.edit ? '<button type="button" class="crm-add" data-add="' + esc(s) + '" title="Új deal ebbe a szakaszba" aria-label="Új deal: ' + esc(s) + '">+</button>' : '') + '<button type="button" class="crm-add crm-fold" data-fold="' + esc(s) + '" title="Oszlop összecsukása" aria-label="Összecsukás: ' + esc(s) + '">‹</button></span></div>' +
				'<small><span># ' + l.length + '</span>' + (v ? '<span>' + esc(money(v)) + '</span>' : '') + '</small>' +
				'<div class="crm-mh"><b>' + esc(s) + '</b><small>' + esc(money(v)) + ' • ' + l.length + ' deal</small>' + prog(names.indexOf(s)) + '</div></header><div class="crm-cards">' +
				pend.map(x => { const ev = x.f['Deal.dateCustomField1'] || '', sv = x.f['Deal.multiSelectCustomField3'] || ''; return '<article class="crm-card crm-pend" data-lead="' + x.id + '" tabindex="0"><div class="crm-ct"><span class="crm-lnew">új</span> ' + esc(x.n || x.e || 'Névtelen') + '</div><div class="crm-cl">' + (ev ? '<span>' + esc(shortD(ev)) + '</span><i>•</i>' : '') + '<span class="cpx-muted">' + esc(sv || x.form || 'weboldal') + '</span></div><div class="crm-pw">⏳ Salesmate-szinkronra vár · ' + esc(agoLead(x.at)) + '<button type="button" class="crm-px" data-pdel="' + x.id + '" title="Elvetés (spam / teszt)">×</button></div></article>'; }).join('');
			l.forEach(r => {
				const soon = r.ev && r.ev >= t && r.ev <= addDays(t, 14), past = r.ev && r.ev < t;
				const rot = r.rot && r.rot <= t ? dayDiff(r.rot, t) : 0;
				const act = r.na ? (r.na < now ? '<span class="crm-na late" title="Lejárt teendő: ' + esc(huDT(r.na)) + '">⚑ lejárt</span>' : '<span class="crm-na" title="Következő teendő: ' + esc(huDT(r.na)) + '">⚑ ' + esc(shortD(r.na.slice(0, 10))) + '</span>') : '<span class="crm-na none" title="Nincs ütemezett teendő">⚑ nincs teendő</span>';
				const rotB = rot ? '<span class="crm-rot' + (rot > 7 ? ' hot' : '') + '" title="' + rot + ' napja nem mozdult (rohad)"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>' + rot + ' nap</span>' : '';
				const fresh = S.pend && S.pend.fresh && S.pend.fresh[r.id];
				const open = '<article class="crm-card' + (fresh ? ' fresh' : '') + (r.status === 'Won' ? ' won' : '') + (r._busy ? ' busy' : '') + '" data-id="' + r.id + '" tabindex="0"' + (W ? ' draggable="true"' : '') + '><div class="crm-ct" title="' + esc(r.title) + '">' + (fresh ? '<span class="crm-lnew">új</span> ' : '') + esc(shortTitle(r)) + '</div>';
				if (S.dense && !MOB) {
					h += open + '<div class="crm-cl">' + (r.ev ? '<span class="' + (past ? 'crm-past' : soon ? 'crm-soon' : '') + '">' + esc(shortD(r.ev)) + '</span>' : '<span class="cpx-muted">nincs dátum</span>') + '<i>•</i><span>' + esc(money(r.value, r.cur)) + '</span>' + (r.na && r.na < now ? '<span class="crm-na late" title="Lejárt teendő">⚑</span>' : '') + rotB + '</div></article>';
					return;
				}
				h += open +
					'<div class="crm-cl">' + (r.cn || r.co ? esc([r.cn, r.co].filter(Boolean).join(' • ')) : '<span class="cpx-muted">nincs kontakt</span>') + '</div>' +
					(r.venue ? '<div class="crm-cl crm-cv" title="' + esc(r.venue) + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg><span>' + esc(r.venue) + '</span></div>' : '') +
					'<div class="crm-cl"><span>' + esc(money(r.value, r.cur)) + '</span><i>•</i>' + (r.ev ? '<span class="' + (past ? 'crm-past' : soon ? 'crm-soon' : '') + '">' + esc(shortD(r.ev)) + '</span>' : '<span class="cpx-muted">nincs dátum</span>') + '</div>' +
					'<div class="crm-cfoot"><span class="crm-av" style="background:' + avColor(r.own) + '" title="' + esc(uname(r.own)) + '">' + esc(initials(uname(r.own))) + '</span>' + (r.status === 'Won' ? stBadge(r) : '') + act + rotB + '</div></article>';
			});
			if (!l.length) h += '<div class="crm-none">Nincs deal</div>';
			h += '</div></section>';
		});
		h += '</div>';
		if (R.edit) h += '<button type="button" class="crm-fab" data-fab aria-label="Új deal" title="Új deal ebbe a szakaszba">+</button>';
		if (W) h += '<div class="crm-drop" aria-hidden="true"><div data-st="Won">Megnyert</div><div data-st="Lost">Elvesztett</div></div>';
		box.innerHTML = h;
		bindOpen(box);
		bindDrag(box);
		box.querySelectorAll('[data-fold]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); const s = b.dataset.fold; S.fold = S.fold.includes(s) ? S.fold.filter(x => x !== s) : S.fold.concat([s]); save(); redrawBoard(); }));
		box.querySelectorAll('[data-add]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); newDeal(b.dataset.add); }));
		const fab = box.querySelector('[data-fab]');
		if (fab) fab.addEventListener('click', () => {
			const b = box.querySelector('.crm-board'), cs = Array.from(box.querySelectorAll('.crm-col[data-stage]'));
			const c = cs.reduce((best, x) => Math.abs(x.offsetLeft - b.scrollLeft) < Math.abs(best.offsetLeft - b.scrollLeft) ? x : best, cs[0]);
			newDeal(c ? c.dataset.stage : undefined);
		});
		box.querySelectorAll('[data-lead]').forEach(c => {
			c.addEventListener('click', e => { if (e.target.closest('[data-pdel]')) return; openLead(Number(c.dataset.lead)); });
			c.addEventListener('keydown', e => { if (e.key === 'Enter') openLead(Number(c.dataset.lead)); });
		});
		box.querySelectorAll('[data-pdel]').forEach(b => b.addEventListener('click', async e => {
			e.stopPropagation();
			if (!window.confirm('Elveted ezt a megkeresést a pipeline-ról (spam vagy teszt)? A Salesmate-et nem érinti.')) return;
			try { await post('cp_lead_set', { id: b.dataset.pdel, st: 'spam' }); S.pend.rows = S.pend.rows.filter(x => x.id !== Number(b.dataset.pdel)); redrawBoard(); } catch (er) { alert(er.message); }
		}));
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
		box.querySelectorAll('.crm-col').forEach(c => { const k = c.querySelector('.crm-cards'); if (k) keep.cols[c.dataset.stage] = k.scrollTop; });
		drawBoard(box);
		const nb = box.querySelector('.crm-board');
		if (nb) nb.scrollLeft = keep.x;
		box.querySelectorAll('.crm-col').forEach(c => { const k = c.querySelector('.crm-cards'); if (k && keep.cols[c.dataset.stage]) k.scrollTop = keep.cols[c.dataset.stage]; });
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
		const mn = document.body.classList.contains('mn-on') && window.innerWidth <= 760 ? 70 : 0; /* mobilon az alsó menüsor */
		bd.style.setProperty('--crm-h', Math.max(360, window.innerHeight - Math.max(0, top - window.scrollY) - 26 - mn) + 'px');
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
	const shortD = s => { const p = s.split('-').map(Number); return p[0] + '. ' + HU_MS[p[1] - 1] + ' ' + p[2] + '.'; };
	const dayDiff = (a, b) => { const x = a.split('-').map(Number), y = b.split('-').map(Number); return Math.round((Date.UTC(y[0], y[1] - 1, y[2]) - Date.UTC(x[0], x[1] - 1, x[2])) / 864e5); };
	const initials = n => String(n || '').replace(/^#.*/, '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?';
	const AVC = ['#F6B48F', '#93D5B4', '#A9C1F6', '#E3B5E6', '#F3D27F', '#9FD8E5', '#F2A5A5'];
	const avColor = id => AVC[Math.abs(Number(id) || 0) % AVC.length];
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

	/* ------------------------------------------------------------ v1.48: Deal 360° – minden egy helyen (a Salesmate deal-oldalához hasonlóan) */
	const PEOPLE = (C.mail && C.mail.people) || (C.kb && C.kb.people) || [];
	const pn = id => (PEOPLE.find(p => p.id === Number(id)) || {}).n || '';
	const HU_MF = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
	const daysAgo = s => { if (!s) return null; return Math.floor((Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 864e5); };
	const agoTxt = s => { const n = daysAgo(s); if (n == null) return ''; return n <= 0 ? 'ma' : n === 1 ? 'tegnap' : n + ' napja'; };
	const nl = s => esc(s).replace(/\n/g, '<br>');
	const D3 = { tab: 'all' };
	const I = {
		mail_in: '<svg viewBox="0 0 24 24"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>', mail_out: '<svg viewBox="0 0 24 24"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/><path d="M15 3l3 3-3 3" /></svg>',
		note: '<svg viewBox="0 0 24 24"><path d="M5 4h14v12l-4 4H5z"/><path d="M15 20v-4h4"/></svg>', act: '<svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>',
		hist: '<svg viewBox="0 0 24 24"><path d="M12 7v5l3 2"/><circle cx="12" cy="12" r="8"/></svg>', card: '<svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="m8 12 3 3 5-6"/></svg>',
		lead: '<svg viewBox="0 0 24 24"><path d="M12 3l2.6 5.6 6 .6-4.5 4 1.3 6L12 16l-5.4 3.2 1.3-6-4.5-4 6-.6z"/></svg>', flow: '<svg viewBox="0 0 24 24"><path d="M4 6h6v4H4zM14 14h6v4h-6zM7 10v4a2 2 0 0 0 2 2h5"/></svg>',
		plus: '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>', portal: '<svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>', file: '<svg viewBox="0 0 24 24"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/></svg>'
	};
	function sheet360(html) {
		const m = document.getElementById('cpx-modal');
		if (!m) return null;
		m.innerHTML = '<div class="cpx-sheet crm-sheet crm-360" role="dialog" aria-modal="true">' + html + '</div>';
		m.hidden = false;
		return m.querySelector('.crm-360');
	}
	const head360 = (t, back) => '<div class="cpx-sheet-h d3-h">' + (back ? '<button type="button" class="d3-back" data-back>← ' + esc(back.label) + '</button>' : '') + '<h3>' + t + '</h3><button class="cpx-x" type="button" data-close aria-label="Bezárás">×</button></div>';
	function bindBack(sh, back) { const b = sh.querySelector('[data-back]'); if (b && back) b.addEventListener('click', back.fn); }
	const goMail = id => { const m = document.getElementById('cpx-modal'); if (m) { m.hidden = true; m.innerHTML = ''; } if (window.CPMail && window.CPMail.open) window.CPMail.open(id); };
	function dealRows(list, curId, back) {
		if (!list.length) return '<p class="cpx-muted">Nincs.</p>';
		return '<ul class="d3-deals">' + list.map(o => '<li class="' + (o.id === curId ? 'cur' : '') + '"><button type="button" data-open="' + o.id + '"><b>' + esc(o.title || 'Deal #' + o.id) + '</b><small>' +
			esc([o.event_d ? huD(o.event_d) : '', o.stage].filter(Boolean).join(' · ')) + '</small></button><span>' + stBadge(o) + '<em>' + esc(o.value != null ? money(o.value, o.currency) : '') + '</em></span></li>').join('') + '</ul>';
	}
	function statLine(s) {
		if (!s || !s.n) return '';
		return '<div class="d3-stat"><span><b>' + s.n + '</b> deal</span><span><b>' + s.won + '</b> megnyert</span><span><b>' + esc(money(s.won_v)) + '</b> bevétel</span>' + (s.open ? '<span><b>' + s.open + '</b> nyitott · ' + esc(money(s.open_v)) + '</span>' : '') + '</div>' +
			(s.first ? '<p class="d3-sub">Első megkeresés: ' + esc(huD(s.first.slice(0, 10))) + (s.last_ev ? ' · legutóbbi rendezvény: ' + esc(huD(s.last_ev)) : '') + '</p>' : '');
	}
	function threadList(m) {
		if (m.off) return '<p class="cpx-muted">A Levelezés füled nincs bekapcsolva.</p>';
		if (!m.threads.length) return '<p class="cpx-muted">Nincs levélszál.</p>';
		return '<ul class="d3-ths">' + m.threads.map(t => '<li><button type="button" data-th="' + t.id + '"><b>' + esc(t.subj || '(nincs tárgy)') + '</b><small>' + esc([huDT(t.at), t.cnt + ' levél', t.st === 'closed' ? 'lezárva' : 'nyitott', t.as ? pn(t.as) : ''].filter(Boolean).join(' · ')) + '</small></button></li>').join('') + '</ul>';
	}
	function bindCommon(sh, back) {
		sh.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => openDeal(Number(b.dataset.open), back)));
		sh.querySelectorAll('[data-th]').forEach(b => b.addEventListener('click', () => goMail(Number(b.dataset.th))));
		sh.querySelectorAll('[data-ct]').forEach(b => b.addEventListener('click', e => { e.preventDefault(); openContact(Number(b.dataset.ct), back); }));
		sh.querySelectorAll('[data-co]').forEach(b => b.addEventListener('click', e => { e.preventDefault(); openCompany(Number(b.dataset.co), back); }));
	}
	/* a deal teljes idővonala: levelek, jegyzetek, tevékenységek, feladatok, megkeresések, automatizmusok, változások */
	function timeline(x) {
		const d = x.deal, m = x.mail || { msgs: [], notes: [] }, it = [];
		(m.msgs || []).forEach(g => it.push({ k: 'mail', at: g.at, h: '<div class="d3-ti ' + (g.dir === 'out' ? 'out' : 'in') + '" data-th="' + g.th + '" role="button" tabindex="0"><i>' + (g.dir === 'out' ? I.mail_out : I.mail_in) + '</i><div><div class="d3-tt"><b>' + (g.dir === 'out' ? 'Levél tőlünk' : 'Levél érkezett') + '</b> – ' + esc(g.from) + (g.q ? ' <span class="d3-q" title="Az ügyfél válaszában idézett részből">idézetből</span>' : '') + (g.att ? ' <span class="d3-q">📎 ' + g.att + '</span>' : '') + '<small>' + esc(huDT(g.at)) + '</small></div><div class="d3-ts">' + esc(g.subj) + '</div><div class="d3-tx">' + esc(g.snip) + '</div></div></div>' }));
		(x.dnotes || []).forEach(n => it.push({ k: 'note', at: n.at, h: '<div class="d3-ti note dnote"><i>' + I.note + '</i><div><div class="d3-tt"><b>Jegyzet a dealen</b> – ' + esc(n.author || '') + (n.src === 'sm' ? ' <span class="d3-q" title="A Salesmate-ben írták">Salesmate</span>' : '') + '<small>' + esc(huDT(n.at)) + '</small>' + (W && (n.mine || (C.au && C.au.on)) ? '<button type="button" class="d3-x" data-ndel="' + n.id + '" title="Jegyzet törlése (a Salesmate-ből is)">×</button>' : '') + '</div><div class="d3-tx">' + nl(n.body) + '</div></div></div>' }));
		(m.notes || []).forEach(n => it.push({ k: 'note', at: n.at, h: '<div class="d3-ti note" data-th="' + n.th + '" role="button" tabindex="0"><i>' + I.note + '</i><div><div class="d3-tt"><b>Jegyzet</b> – ' + esc(n.by) + '<small>' + esc(huDT(n.at)) + '</small></div><div class="d3-tx">' + nl(n.b) + '</div></div></div>' }));
		(x.acts || []).filter(a => Number(a.completed)).forEach(a => it.push({ k: 'act', at: a.completed_at || a.due_at, h: '<div class="d3-ti act"><i>' + I.act + '</i><div><div class="d3-tt"><b>' + esc(a.title || a.type) + '</b> <span class="d3-q">kész</span><small>' + esc(huDT(a.completed_at || a.due_at)) + '</small>' + (W && Number(a.related_id) === d.id ? '<button type="button" class="d3-ab" data-aundo="' + a.id + '" title="Visszanyitás">↺</button>' : '') + '</div><div class="d3-ts">' + esc([a.type, a.owner].filter(Boolean).join(' · ')) + '</div>' + (a.description ? '<div class="d3-tx">' + nl(a.description) + '</div>' : '') + '</div></div>' }));
		(x.cards || []).forEach(c => it.push({ k: 'card', at: c.at, h: '<div class="d3-ti card" data-card="' + c.id + '" role="button" tabindex="0"><i>' + I.card + '</i><div><div class="d3-tt"><b>Feladat</b> – ' + esc(c.by) + '<small>' + esc(huDT(c.at)) + '</small></div><div class="d3-ts">' + (c.done ? '✓ ' : '') + esc(c.title) + '</div><div class="d3-tx">' + esc([c.list, c.as.join(', '), c.due ? 'határidő: ' + huD(c.due) : ''].filter(Boolean).join(' · ')) + '</div></div></div>' }));
		(x.leads || []).forEach(l => it.push({ k: 'lead', at: l.at, h: '<div class="d3-ti lead"><i>' + I.lead + '</i><div><div class="d3-tt"><b>Webes megkeresés</b> – ' + esc(l.form) + '<small>' + esc(huDT(l.at)) + '</small></div><dl class="d3-kv">' + l.kv.map(p => '<dt>' + esc(p[0]) + '</dt><dd>' + nl(p[1]) + '</dd>').join('') + '</dl></div></div>' }));
		(x.flows || []).forEach(f => it.push({ k: 'hist', at: f.at, h: '<div class="d3-ti flow"><i>' + I.flow + '</i><div><div class="d3-tt"><b>Automatizmus</b> – ' + esc(f.n) + (f.mode === 'shadow' ? ' <span class="d3-q">árnyék</span>' : '') + '<small>' + esc(huDT(f.at)) + '</small></div><div class="d3-tx">' + nl(f.detail) + '</div></div></div>' }));
		/* a változások: az egyszerre (ugyanakkor, ugyanattól) történtek egy bejegyzésben */
		const hs = [];
		(x.hist || []).forEach(r => { const l = hs[hs.length - 1]; if (l && l.at === r.at && l.by === r.by_user) l.rows.push(r); else hs.push({ at: r.at, by: r.by_user, rows: [r] }); });
		hs.forEach(g => it.push({ k: 'hist', at: g.at, h: '<div class="d3-ti hist"><i>' + I.hist + '</i><div><div class="d3-tt"><b>' + (g.rows.length === 1 ? esc(LBL[g.rows[0].field] || g.rows[0].field) + ' módosítva' : g.rows.length + ' mező módosítva') + '</b>' + (g.by ? ' – ' + esc(g.by) : '') + '<small>' + esc(huDT(g.at)) + '</small></div>' +
			g.rows.map(r => '<div class="d3-hv">' + (g.rows.length > 1 ? '<span>' + esc(LBL[r.field] || r.field) + ':</span> ' : '') + (r.old_val ? '<s>' + esc(histVal(r.field, r.old_val)) + '</s> → ' : '') + '<b>' + esc(histVal(r.field, r.new_val) || '–') + '</b></div>').join('') + '</div></div>' }));
		(x.portal_ev || []).forEach(p => it.push({ k: 'portal', at: p.at, h: '<div class="d3-ti portal' + (p.ok ? '' : ' late') + '"><i>' + I.portal + '</i><div><div class="d3-tt"><b>' + esc(p.text) + '</b>' + (p.ok ? '' : ' <span class="d3-q">hiba</span>') + '<small>' + esc(huDT(p.at)) + '</small></div></div></div>' }));
		if (d.created_at) it.push({ k: 'hist', at: d.created_at, h: '<div class="d3-ti hist"><i>' + I.plus + '</i><div><div class="d3-tt"><b>Deal létrehozva</b>' + (d.created_by_n ? ' – ' + esc(d.created_by_n) : '') + '<small>' + esc(huDT(d.created_at)) + '</small></div>' + (d.source ? '<div class="d3-tx">Forrás: ' + esc(d.source) + '</div>' : '') + '</div></div>' });
		return it.filter(i => i.at).sort((a, b) => (a.at < b.at ? 1 : a.at > b.at ? -1 : 0));
	}
	function drawTimeline(box, x) {
		const all = timeline(x);
		const TABS = [['all', 'Mind'], ['mail', 'Levelek'], ['note', 'Jegyzetek'], ['act', 'Tevékenységek'], ['card', 'Feladatok'], ['lead', 'Megkeresés'], ['portal', 'Portál'], ['hist', 'Változások']];
		const cnt = k => k === 'all' ? all.length : all.filter(i => i.k === k).length;
		const up = (x.acts || []).filter(a => !Number(a.completed)).sort((a, b) => (a.due_at || '') < (b.due_at || '') ? -1 : 1);
		const cardsOpen = (x.cards || []).filter(c => !c.done && c.due);
		const list = all.filter(i => D3.tab === 'all' || i.k === D3.tab);
		let h = '<div class="d3-tabs">' + TABS.filter(t => t[0] === 'all' || cnt(t[0])).map(t => '<button type="button" data-t3="' + t[0] + '" class="' + (D3.tab === t[0] ? 'on' : '') + '">' + t[1] + ' <small>' + cnt(t[0]) + '</small></button>').join('') + '</div>';
		if ((up.length || cardsOpen.length) && (D3.tab === 'all' || D3.tab === 'act' || D3.tab === 'card')) {
			h += '<h5 class="d3-mh">Közelgő (' + (up.length + cardsOpen.length) + ')</h5>' + up.map(a => { const late = a.due_at && a.due_at < today(); const mine = W && Number(a.related_id) === x.deal.id; return '<div class="d3-ti act up' + (late ? ' late' : '') + '"><i>' + I.act + '</i><div><div class="d3-tt"><b>' + esc(a.title || a.type) + '</b>' + (a.owner ? ' – ' + esc(a.owner) : '') + '<small>' + esc(huDT(a.due_at)) + (late ? ' · lejárt' : '') + '</small>' + (mine ? '<button type="button" class="d3-ab ok" data-adone="' + a.id + '" title="Késznek jelölés (a Salesmate-ben is)">✓ Kész</button><button type="button" class="d3-ab" data-aedit="' + a.id + '" title="Szerkesztés">✎</button>' : '') + '</div><div class="d3-ts">' + esc(a.type || '') + '</div>' + (a.description ? '<div class="d3-tx">' + nl(a.description) + '</div>' : '') + '</div></div>'; }).join('') +
				cardsOpen.map(c => '<div class="d3-ti card up" data-card="' + c.id + '" role="button" tabindex="0"><i>' + I.card + '</i><div><div class="d3-tt"><b>' + esc(c.title) + '</b>' + (c.as.length ? ' – ' + esc(c.as.join(', ')) : '') + '<small>határidő: ' + esc(huD(c.due)) + '</small></div><div class="d3-ts">' + esc(c.list) + '</div></div></div>').join('');
		}
		let mon = '';
		h += list.map(i => { const k = i.at.slice(0, 7); const mh = k !== mon ? '<h5 class="d3-mh">' + k.slice(0, 4) + '. ' + HU_MF[Number(k.slice(5, 7)) - 1] + '</h5>' : ''; mon = k; return mh + i.h; }).join('') || '<p class="cpx-muted">Ebben a nézetben nincs bejegyzés.</p>';
		box.innerHTML = h;
		box.querySelectorAll('[data-t3]').forEach(b => b.addEventListener('click', () => { D3.tab = b.dataset.t3; drawTimeline(box, x); }));
		const re = () => D3.ctx && openDeal(D3.ctx.id, D3.ctx.back);
		const run = async (btn, action, p, msg) => { btn.disabled = true; try { await post(action, p); toast(msg); re(); } catch (e) { btn.disabled = false; toast(e.message, true); } };
		box.querySelectorAll('[data-adone]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); run(b, 'cp_crm_act_done', { id: b.dataset.adone, done: 1 }, 'Kész – a Salesmate-ben is.'); }));
		box.querySelectorAll('[data-aundo]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); run(b, 'cp_crm_act_done', { id: b.dataset.aundo, done: '' }, 'Visszanyitva – a Salesmate-ben is.'); }));
		box.querySelectorAll('[data-aedit]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); const a = (x.acts || []).find(z => String(z.id) === b.dataset.aedit); const c = document.querySelector('.crm-360 [data-comp]'); if (a && c) compose(c, x, 'act', a); }));
		box.querySelectorAll('[data-ndel]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); if (window.confirm('Törlöd a jegyzetet? A Salesmate-ből is törlődik.')) run(b, 'cp_crm_note_del', { id: b.dataset.ndel }, 'Jegyzet törölve.'); }));
		box.querySelectorAll('.d3-ti[data-th]').forEach(b => { const go = () => goMail(Number(b.dataset.th)); b.addEventListener('click', go); b.addEventListener('keydown', e => { if (e.key === 'Enter') go(); }); });
		box.querySelectorAll('.d3-ti[data-card]').forEach(b => { const go = () => { const m = document.getElementById('cpx-modal'); if (m) { m.hidden = true; m.innerHTML = ''; } if (window.CPNotify && window.CPNotify.go) window.CPNotify.go('feladatok:' + b.dataset.card); }; b.addEventListener('click', go); b.addEventListener('keydown', e => { if (e.key === 'Enter') go(); }); });
	}
	/* v1.59: az ügyfélportál kártyája a deal-adatlapon */
	const PST = { hianyos: 'Hiányos', kesz: 'Kész', modositva: 'Módosítva' };
	function portalCard(x) {
		const P = C.portal || {}, s = x.portal;
		if (!P.on || !s) return '';
		if (s.none) {
			return '<section class="d3-c d3-portal"><h4>Ügyfélportál</h4>' + (s.mode !== 'on' ? '<p class="cpx-muted">Az ügyfélportál ki van kapcsolva.</p>' :
				'<p class="d3-sub">Még nincs portál. Kiküldéskor létrejön, az ügyfél meghívó levelet kap a belépőlinkkel' + (P.sentStage ? ', és a deal az „' + esc(P.sentStage) + '” szakaszba kerül' : '') + '.</p>' +
				'<div class="d3-pact"><select data-ptype aria-label="Megrendelő"><option value="">az ügyfél választja</option><option value="magan">magánszemély</option><option value="ceg">cég</option></select><button type="button" class="cpx-btn" data-psend>Ügyfélportál kiküldése</button></div>') + '</section>';
		}
		const pct = s.total ? Math.round(s.done / s.total * 100) : 0;
		return '<section class="d3-c d3-portal"><h4>Ügyfélportál <span class="d3-pst ' + esc(s.st) + '">' + esc(PST[s.st] || s.st) + '</span></h4>' +
			'<div class="d3-pbar"><i style="width:' + pct + '%"></i></div><p class="d3-sub"><b>' + s.done + '/' + s.total + '</b> kötelező kérdés · szerződéshez: ' + s.cd + '/' + s.ct + (s.deadline ? ' · határidő: <b>' + esc(s.deadline) + '</b>' + (s.passed ? ' (lezárva)' : '') : '') + '</p>' +
			(s.flags && s.flags.length ? '<div class="d3-pflags">' + s.flags.map(f => '<span class="d3-q ' + esc(f.tone) + '">' + esc(f.t) + '</span>').join('') + '</div>' : '') +
			(s.missing && s.missing.length ? '<p class="d3-sub">Hiányzik: ' + esc(s.missing.join(', ')) + '</p>' : '') +
			'<p class="d3-sub">' + (s.last_open ? 'Az ügyfél utoljára megnyitotta: ' + esc(huDT(s.last_open)) : 'Az ügyfél még nem nyitotta meg.') + '</p>' +
			'<div class="d3-pact"><button type="button" class="cpx-btn" data-popen>Adatlap megnyitása</button><button type="button" class="cpx-btn ghost" data-plink>Link másolása</button><button type="button" class="cpx-btn ghost" data-pmail>Új link e-mailben</button></div></section>';
	}
	function bindPortal(sh, x, reopen) {
		const id = x.deal.id, title = x.deal.title || 'Deal #' + id;
		const call = async (action, p) => post(action, Object.assign({ id: id }, p || {}));
		const ps = sh.querySelector('[data-psend]');
		if (ps) ps.addEventListener('click', async () => {
			if (!window.confirm('Kiküldöd az ügyfélportált?\n\nAz ügyfél meghívó levelet kap a belépőlinkkel' + ((C.portal || {}).sentStage ? ', és a deal a Salesmate-ben az „' + C.portal.sentStage + '” szakaszba kerül.' : '.'))) return;
			ps.disabled = true;
			try { const r = await call('cp_portal_p_send', { type: sh.querySelector('[data-ptype]').value }); toast(r.msg || 'Kiküldve.'); reopen(); } catch (e) { ps.disabled = false; toast(e.message, true); }
		});
		const po = sh.querySelector('[data-popen]');
		if (po) po.addEventListener('click', () => { if (window.CPPortal && window.CPPortal.open) window.CPPortal.open(id, title); else toast('Az ügyfélportál felülete nem töltődött be.', true); });
		const pl = sh.querySelector('[data-plink]');
		if (pl) pl.addEventListener('click', async () => {
			pl.disabled = true;
			try { const r = await call('cp_portal_p_link', { op: 'new' }); try { await navigator.clipboard.writeText(r.url); toast('Új személyes link kimásolva – csak az ügyfélnek küldd el.'); } catch (e) { window.prompt('A személyes link (másold ki):', r.url); } }
			catch (e) { toast(e.message, true); }
			pl.disabled = false;
		});
		const pm = sh.querySelector('[data-pmail]');
		if (pm) pm.addEventListener('click', async () => { if (!window.confirm('Új belépőlinket küldök e-mailben az ügyfélnek. Mehet?')) return; pm.disabled = true; try { const r = await call('cp_portal_p_link', { op: 'send' }); toast(r.msg || 'Elküldve.'); reopen(); } catch (e) { pm.disabled = false; toast(e.message, true); } });
	}
	/* jegyzet vagy tevékenység felvétele a dealre (a Salesmate-be is) */
	function compose(box, x, mode, a) {
		const d = x.deal, types = x.act_types || ['Task', 'Call'];
		const me = R.me_sm && USERS[String(R.me_sm)] ? String(R.me_sm) : String(d.owner_id || '');
		const due = a && a.due_at ? a.due_at.slice(0, 10) : '', tm = a && a.due_at && a.due_at.slice(11, 16) !== '01:00' && a.due_at.slice(11, 16) !== '00:00' ? a.due_at.slice(11, 16) : '';
		const owners = Object.keys(USERS).filter(i => USERS[i].a || String(i) === String(a ? a.owner_id : me)).map(i => [i, USERS[i].n]);
		box.innerHTML = '<div class="d3-ctabs"><button type="button" data-cm="note" class="' + (mode === 'note' ? 'on' : '') + '">📝 Jegyzet</button><button type="button" data-cm="act" class="' + (mode === 'act' ? 'on' : '') + '">📅 ' + (a ? 'Tevékenység szerkesztése' : 'Tevékenység') + '</button></div>' +
			(mode === 'note' ? '<textarea data-nb rows="2" placeholder="Jegyzet a dealhez – @Név: megemlítés. (Ctrl+Enter: mentés)"></textarea><div class="d3-cbar"><small>A Salesmate-be is beíródik.</small><button type="button" class="cpx-btn" data-save>Jegyzet mentése</button></div>' :
			'<div class="d3-af"><input data-at type="text" placeholder="Mi a teendő? (pl. Visszahívni az árajánlat miatt)" value="' + esc(a ? a.title : '') + '">' +
				'<label>Típus <select data-ty>' + types.concat(a && a.type && !types.includes(a.type) ? [a.type] : []).map(t => '<option' + ((a ? a.type : types[0]) === t ? ' selected' : '') + '>' + esc(t) + '</option>').join('') + '</select></label>' +
				'<label>Határidő <input data-ad type="date" value="' + esc(due || today()) + '"></label><label>Időpont <input data-am type="time" value="' + esc(tm) + '"></label>' +
				'<label>Hossz <select data-du>' + [[15, '15 perc'], [30, '30 perc'], [60, '1 óra'], [120, '2 óra'], [240, '4 óra'], [480, 'egész nap']].map(o => '<option value="' + o[0] + '"' + (Number(a ? a.duration || 30 : 30) === o[0] ? ' selected' : '') + '>' + o[1] + '</option>').join('') + '</select></label>' +
				'<label>Felelős <select data-ow>' + opts(owners, String(a ? a.owner_id : me)) + '</select></label>' +
				'<textarea data-ds rows="2" placeholder="Leírás (nem kötelező)">' + esc(a ? a.description || '' : '') + '</textarea></div>' +
				'<div class="d3-cbar"><small>A Salesmate-be is beíródik' + (a ? '' : ', a naptárszinkron ugyanúgy látja') + '. Időpont nélkül egész napos.</small>' + (a ? '<button type="button" class="cpx-btn ghost" data-cancel>Mégse</button>' : '') + '<button type="button" class="cpx-btn" data-save>' + (a ? 'Mentés' : 'Tevékenység felvétele') + '</button></div>');
		box.querySelectorAll('[data-cm]').forEach(b => b.addEventListener('click', () => compose(box, x, b.dataset.cm)));
		const cancel = box.querySelector('[data-cancel]');
		if (cancel) cancel.addEventListener('click', () => compose(box, x, 'act'));
		const btn = box.querySelector('[data-save]');
		const done = msg => { toast(msg); if (D3.ctx) openDeal(D3.ctx.id, D3.ctx.back); };
		const save = async () => {
			let p, action;
			if (mode === 'note') {
				const v = box.querySelector('[data-nb]').value.trim();
				if (!v) { box.querySelector('[data-nb]').focus(); return; }
				action = 'cp_crm_note_add'; p = { deal: d.id, body: v };
			} else {
				const t = box.querySelector('[data-at]').value.trim();
				if (!t) { box.querySelector('[data-at]').focus(); return; }
				action = 'cp_crm_act_save';
				p = { deal: d.id, id: a ? a.id : '', title: t, type: box.querySelector('[data-ty]').value, due: box.querySelector('[data-ad]').value, time: box.querySelector('[data-am]').value, dur: box.querySelector('[data-du]').value, owner: box.querySelector('[data-ow]').value, descr: box.querySelector('[data-ds]').value };
			}
			btn.disabled = true; btn.textContent = 'Mentés…';
			try { await post(action, p); done(mode === 'note' ? 'Jegyzet mentve – a Salesmate-ben is.' : (a ? 'Tevékenység módosítva – a Salesmate-ben is.' : 'Tevékenység felvéve – a Salesmate-ben is.')); }
			catch (e) { btn.disabled = false; btn.textContent = mode === 'note' ? 'Jegyzet mentése' : (a ? 'Mentés' : 'Tevékenység felvétele'); toast(e.message, true); }
		};
		btn.addEventListener('click', save);
		const nb = box.querySelector('[data-nb]');
		if (nb) nb.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); } });
		if (a) box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
	}
	/* a deal mezői csoportosítva; ami egyik csoportba sem került és van értéke, az „Egyéb” alá */
	const GROUPS = [
		['Rendezvény', ['event_d', 'event_end_d', 'start_at', 'venue', 'guests', 'hours', 'service', 'package', 'services_cp', 'services_ec', 'services_more', 'extras', 'background', 'km_fee', 'extension', 'operator', 'album', 'coupon']],
		['Sablon és portál', ['template_st', 'template_paid', 'template_link', 'portal_link']],
		['Pénzügy és szerződés', ['value', 'pay_method', 'paid', 'ctype']],
		['Állapot', ['stage', 'status', 'owner_id', 'source', 'tags', 'lost_reason', 'est_close_d', 'closed_at', 'reminder', 'cal_check']],
		['Megjegyzés', ['note', 'last_note']]
	];
	const NOSHOW = ['id', 'title', 'currency', 'contact_id', 'company_id', 'raw', 'via', 'synced_at', 'deleted_at', 'created_at', 'modified_at', 'created_by', 'modified_by', 'created_by_n', 'modified_by_n', 'owner', 'last_comm_at', 'last_comm_mode', 'last_note_at', 'rotten_d', 'win_prob', 'description', 'closed_stage', 'pkg_old', 'pkg_v2', 'pkg_fix', 'spinner_pkg', 'melange_pkg'];
	function fieldGroups(d) {
		const used = new Set(NOSHOW);
		let h = GROUPS.map(g => { g[1].forEach(k => used.add(k)); const b = dl(d, g[1]); return b ? '<details class="d3-g" open><summary>' + esc(g[0]) + '</summary>' + b + '</details>' : ''; }).join('');
		const rest = Object.keys(d).filter(k => !used.has(k) && d[k] != null && d[k] !== '' && LBL[k]);
		const pk = ['pkg_v2', 'pkg_fix', 'spinner_pkg', 'melange_pkg', 'pkg_old'].filter(k => d[k]);
		if (rest.length || pk.length) h += '<details class="d3-g"><summary>Egyéb</summary>' + dl(d, rest.concat(pk)) + '</details>';
		if (d.description) h += '<details class="d3-g"><summary>Leírás</summary><div class="crm-desc">' + nl(d.description) + '</div></details>';
		return h;
	}
	function stageBar(d) {
		if (!STAGES.length) return '';
		const i = STAGES.indexOf(d.stage);
		return '<div class="d3-stages' + (d.status === 'Won' ? ' won' : d.status === 'Lost' ? ' lost' : '') + '">' + STAGES.map((s, k) => '<button type="button" data-stg="' + esc(s) + '" class="' + (k < i ? 'done' : k === i ? 'cur' : '') + '"' + (W && d.status === 'Open' && !d.deleted_at && k !== i ? '' : ' disabled') + ' title="' + esc(s) + (W && d.status === 'Open' && k !== i ? ' – kattints az áthelyezéshez' : '') + '"><span>' + esc(s) + '</span></button>').join('') + '</div>';
	}
	async function openDeal(id, back) {
		if (S.pend && S.pend.fresh && S.pend.fresh[id]) { const lid = S.pend.fresh[id]; delete S.pend.fresh[id]; post('cp_lead_set', { id: lid, st: 'seen' }).catch(() => {}); if (S.mode === 'board' && S.board) setTimeout(redrawBoard, 0); }
		const sh = sheet360(head360('Deal #' + id, back) + '<div class="cpx-empty">Betöltés…</div>');
		if (!sh) return;
		bindBack(sh, back);
		let x;
		try { x = await post('cp_crm_deal', { id: id }); }
		catch (e) { if (sh.isConnected) { sh.innerHTML = head360('Deal #' + id, back) + '<div class="cpx-empty err">' + esc(e.message) + '</div>'; bindBack(sh, back); } return; }
		if (!sh.isConnected) return;
		const d = x.deal, c = x.contact, co = x.company, m = x.mail || { threads: [], msgs: [], notes: [], files: [] };
		const smUrl = C.sm ? C.sm + d.id : '';
		const self = { label: shortTitle({ id: d.id, title: d.title, ev: d.event_d }).slice(0, 40), fn: () => openDeal(id, back) };
		/* összkép */
		const lastIn = (m.msgs || []).find(g => g.dir === 'in'), lastOut = (m.msgs || []).find(g => g.dir === 'out');
		const toEv = d.event_d ? dayDiff(today(), d.event_d) : null;
		const team = new Map();
		if (d.owner) team.set(d.owner, 'Felelős');
		(m.threads || []).forEach(t => { if (t.as && pn(t.as) && !team.has(pn(t.as))) team.set(pn(t.as), 'Levelezés'); });
		(x.cards || []).forEach(k => k.as.forEach(n => { if (!team.has(n)) team.set(n, 'Feladat'); }));
		const otherCt = (x.ct_deals || []).filter(o => o.id !== d.id);
		const otherCo = (x.co_deals || []).filter(o => o.id !== d.id && !otherCt.some(z => z.id === o.id));
		const ph = c ? (c.mobile || c.phone) : '';
		let h = head360(esc(d.title || 'Deal #' + d.id), back) +
			'<div class="d3-top">' + stageBar(d) + '<div class="d3-acts">' + stBadge(d) +
			(W && !d.deleted_at ? (d.status === 'Open' ? '<button class="cpx-btn ghost d3-won" type="button" data-mvst="Won">✓ Megnyert</button><button class="cpx-btn ghost bad" type="button" data-mvst="Lost">✗ Elvesztett</button>' : '<button class="cpx-btn ghost" type="button" data-mvst="Open">Újranyitás</button>') : '') +
			(R.edit && !d.deleted_at ? '<button class="cpx-btn" type="button" data-edit>Szerkesztés</button>' : '') +
			(window.CPKanban ? '<button class="cpx-btn ghost" type="button" data-todo title="Teendő felvétele a Feladatokba">☑ Teendő</button>' : '') +
			(smUrl ? '<a class="cpx-btn ghost" href="' + esc(smUrl) + '" target="_blank" rel="noopener">Salesmate ↗</a>' : '') + '</div></div>' +
			(d.deleted_at ? '<p class="crm-warn">Ez a deal a Salesmate-ben már nem található (törölték).</p>' : '') +
			'<div class="d3">' +
			/* bal: összefoglaló, kontakt, cég, mezők */
			'<aside class="d3-l"><section class="d3-c d3-sum"><div class="d3-val">' + esc(money(d.value, d.currency)) + '</div><dl class="crm-dl">' +
				(d.event_d ? '<dt>Rendezvény</dt><dd>' + esc(huD(d.event_d)) + (d.event_end_d ? ' – ' + esc(huD(d.event_end_d)) : '') + '</dd>' : '') + (d.venue ? '<dt>Helyszín</dt><dd>' + esc(d.venue) + '</dd>' : '') +
				'<dt>Felelős</dt><dd>' + esc(d.owner || '–') + '</dd><dt>Szakasz</dt><dd>' + esc(d.stage) + '</dd>' + (d.source ? '<dt>Forrás</dt><dd>' + esc(d.source) + '</dd>' : '') + (d.service ? '<dt>Szolgáltatás</dt><dd>' + esc(d.service) + '</dd>' : '') + '</dl></section>' +
			'<section class="d3-c"><h4>Kontakt</h4>' + (c ? '<a href="#" class="d3-who" data-ct="' + c.id + '"><span class="d3-av" style="background:' + avColor(c.id) + '">' + esc(initials(c.name)) + '</span><span><b>' + esc(c.name || c.email) + '</b><small>' + (otherCt.length ? (otherCt.length + 1) + ' deal · ' + (x.ct_stats ? x.ct_stats.won : 0) + ' megnyert' : 'első deal') + '</small></span></a><dl class="crm-dl">' +
				(c.email ? '<dt>E-mail</dt><dd><a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a></dd>' : '') + (ph ? '<dt>Telefon</dt><dd><a href="tel:' + esc(String(ph).replace(/[^\d+]/g, '')) + '">' + esc(ph) + '</a></dd>' : '') +
				(c.city ? '<dt>Város</dt><dd>' + esc(c.city) + '</dd>' : '') + (c.utm_source || c.utm_campaign ? '<dt>Honnan jött</dt><dd>' + esc([c.utm_source, c.utm_medium, c.utm_campaign].filter(Boolean).join(' / ')) + '</dd>' : '') + '</dl>' : '<p class="cpx-muted">Nincs kontakt rendelve a deal-hez.</p>') + '</section>' +
			'<section class="d3-c"><h4>Cég</h4>' + (co ? '<a href="#" class="d3-who" data-co="' + co.id + '"><span class="d3-av sq" style="background:' + avColor(co.id + 3) + '">' + esc(initials(co.name)) + '</span><span><b>' + esc(co.name) + '</b><small>' + ((x.co_deals || []).length) + ' deal · ' + ((x.co_contacts || []).length) + ' kontakt</small></span></a>' + (safeUrl(co.website) ? '<p class="d3-sub"><a href="' + esc(safeUrl(co.website)) + '" target="_blank" rel="noopener">' + esc(co.website) + ' ↗</a></p>' : '') : '<p class="cpx-muted">Nincs cég rendelve.</p>') + '</section>' +
			'<section class="d3-c"><h4>Adatok</h4>' + fieldGroups(d) + '<p class="crm-meta">Létrehozva: ' + esc(huDT(d.created_at)) + (d.created_by_n ? ' (' + esc(d.created_by_n) + ')' : '') + '<br>Utolsó módosítás: ' + esc(huDT(d.modified_at)) + (d.modified_by_n ? ' (' + esc(d.modified_by_n) + ')' : '') + '</p></section></aside>' +
			/* közép: teendők és idővonal */
			'<main class="d3-m">' + (W && !d.deleted_at ? '<div class="d3-comp" data-comp></div>' : '') + '<div data-kbq hidden class="crm-kbq"></div><div data-kbl class="crm-kbl"></div><div data-tl></div></main>' +
			/* jobb: összkép, korábbi munkák, csapat, fájlok, levélszálak */
			'<aside class="d3-r">' + portalCard(x) + '<section class="d3-c"><h4>Összkép</h4><dl class="crm-dl d3-ins">' +
				(toEv != null ? '<dt>Rendezvényig</dt><dd>' + (toEv > 0 ? '<b>' + toEv + ' nap</b>' : toEv === 0 ? '<b>ma</b>' : 'lezajlott (' + (-toEv) + ' napja)') + '</dd>' : '') +
				(x.stage_since ? '<dt>Ebben a szakaszban</dt><dd>' + esc(agoTxt(x.stage_since)) + ' óta</dd>' : '') +
				(d.last_comm_at ? '<dt>Utolsó kommunikáció</dt><dd>' + esc(huDT(d.last_comm_at)) + (d.last_comm_mode ? ' · ' + esc(d.last_comm_mode) : '') + '</dd>' : '') +
				(lastIn ? '<dt>Ügyfél utoljára írt</dt><dd>' + esc(agoTxt(lastIn.at)) + '</dd>' : '') + (lastOut ? '<dt>Mi utoljára írtunk</dt><dd>' + esc(agoTxt(lastOut.at)) + '</dd>' : '') +
				(Number(d.rotten_d) > 0 && d.status === 'Open' ? '<dt>Rohad</dt><dd class="d3-bad">' + Number(d.rotten_d) + ' napja nincs vele teendő</dd>' : '') + '</dl></section>' +
			'<section class="d3-c"><h4>Korábbi munkák – kontakt</h4>' + statLine(x.ct_stats) + (otherCt.length ? dealRows(otherCt, d.id) : '<p class="cpx-muted">' + (c ? 'Ez az első deal ezzel a kontakttal.' : 'Nincs kontakt.') + '</p>') + '</section>' +
			(co ? '<section class="d3-c"><h4>Korábbi munkák – ' + esc(co.name) + '</h4>' + statLine(x.co_stats) + (otherCo.length ? dealRows(otherCo, d.id) : '<p class="cpx-muted">A cégnek nincs más (más kontakthoz tartozó) deal-je.</p>') + '</section>' : '') +
			'<section class="d3-c"><h4>Csapat</h4>' + (team.size ? '<ul class="d3-team">' + Array.from(team).map(p => '<li><span class="d3-av" style="background:' + avColor(p[0].length) + '">' + esc(initials(p[0])) + '</span>' + esc(p[0]) + '<small>' + esc(p[1]) + '</small></li>').join('') + '</ul>' : '<p class="cpx-muted">Nincs.</p>') + '</section>' +
			'<section class="d3-c"><h4>Levélszálak <small>' + (m.threads || []).length + '</small></h4>' + threadList(m) + '</section>' +
			'<section class="d3-c"><h4>Fájlok <small>' + (m.files || []).length + '</small></h4>' + ((m.files || []).length ? '<ul class="d3-files">' + m.files.map(f => '<li>' + (f.m && window.CPMailAtt ? '<a class="d3-fl" href="' + esc(window.CPMailAtt(f.m, f.i, f.n)) + '" target="_blank" rel="noopener" title="Megnyitás / letöltés">' : '<button type="button" data-th="' + f.th + '">') + I.file + '<span><b>' + esc(f.n) + '</b><small>' + esc([f.from, huDT(f.at)].filter(Boolean).join(' · ')) + '</small></span>' + (f.m && window.CPMailAtt ? '</a>' : '</button>') + '</li>').join('') + '</ul><p class="d3-sub">Kattintásra megnyílik vagy letöltődik (a postafiókból).</p>' : '<p class="cpx-muted">A levelekben nem volt melléklet.</p>') + '</section>' +
			'</aside></div>';
		sh.innerHTML = h;
		D3.ctx = { id: id, back: back };
		const cmp = sh.querySelector('[data-comp]');
		if (cmp) compose(cmp, x, 'note');
		drawTimeline(sh.querySelector('[data-tl]'), x);
		bindPortal(sh, x, () => openDeal(id, back));
		bindCommon(sh, self);
		bindBack(sh, back);
		const eb = sh.querySelector('[data-edit]');
		if (eb) eb.addEventListener('click', () => editDeal(sh, x));
		const kbl = sh.querySelector('[data-kbl]');
		if (window.CPKanban) window.CPKanban.linked(kbl, { deal: d.id });
		const todo = sh.querySelector('[data-todo]');
		if (todo) todo.addEventListener('click', () => window.CPKanban.quickForm(sh.querySelector('[data-kbq]'), { title: d.title || 'Deal #' + d.id, deal: d.id, onDone: () => window.CPKanban.linked(kbl, { deal: d.id }) }));
		const after = async ok => { if (!ok) return; if (S.mode === 'list') load(); openDeal(id, back); };
		sh.querySelectorAll('[data-stg]').forEach(b => b.addEventListener('click', async () => after(await doMove(id, { to: b.dataset.stg }, d.title))));
		sh.querySelectorAll('[data-mvst]').forEach(b => b.addEventListener('click', async () => after(await doMove(id, { st: b.dataset.mvst }, d.title))));
	}

	/* ------------------------------------------------------------ kontakt- és cégnézet: minden munkájuk, levelezésük */
	function filterTo(k, id, n) {
		S.who = { k: k, id: id, n: n }; S.mode = 'list'; S.st = ''; S.p = 0; SEL.clear(); save();
		const m = document.getElementById('cpx-modal'); if (m) { m.hidden = true; m.innerHTML = ''; }
		if (window.CPXGo) window.CPXGo('deals');
		setTimeout(() => { if (S.main && S.main.isConnected) view(S.main); }, 30);
	}
	function entityView(sh, title, back, info, x, k, id, name, extra) {
		const s = x.stats || {};
		sh.innerHTML = head360(title, back) + '<div class="d3 d3-2"><aside class="d3-l"><section class="d3-c">' + info + '</section>' + (extra || '') + '</aside><main class="d3-m">' +
			'<div class="d3-tiles"><div><b>' + (s.n || 0) + '</b><span>deal</span></div><div><b>' + (s.won || 0) + '</b><span>megnyert</span></div><div><b>' + esc(money(s.won_v || 0)) + '</b><span>megnyert érték</span></div><div><b>' + esc(money(s.open_v || 0)) + '</b><span>nyitott érték (' + (s.open || 0) + ')</span></div></div>' +
			(s.first ? '<p class="d3-sub">Első deal: ' + esc(huD(s.first.slice(0, 10))) + (s.last_ev ? ' · legutóbbi rendezvény: ' + esc(huD(s.last_ev)) : '') + '</p>' : '') +
			'<div class="d3-mrow"><h4>Deal-ek</h4><button type="button" class="cpx-btn ghost" data-filt>Deal-lista szűrése erre ' + (k === 'co' ? 'a cégre' : 'a kontaktra') + '</button></div>' + dealRows(x.deals || [], 0) +
			'<h4>Levélszálak <small class="cpx-muted">' + ((x.mail || {}).threads || []).length + '</small></h4>' + threadList(x.mail || { threads: [] }) + '</main></div>';
		bindCommon(sh, { label: String(name || '').slice(0, 40), fn: () => (k === 'co' ? openCompany : openContact)(id, back) });
		bindBack(sh, back);
		sh.querySelector('[data-filt]').addEventListener('click', () => filterTo(k, id, name));
	}
	async function openContact(id, back) {
		const sh = sheet360(head360('Kontakt', back) + '<div class="cpx-empty">Betöltés…</div>');
		if (!sh) return;
		bindBack(sh, back);
		let x;
		try { x = await post('cp_crm_contact', { id: id }); } catch (e) { sh.innerHTML = head360('Kontakt', back) + '<div class="cpx-empty err">' + esc(e.message) + '</div>'; bindBack(sh, back); return; }
		const c = x.contact, co = x.company, ph = c.mobile || c.phone;
		const info = '<div class="d3-who big"><span class="d3-av" style="background:' + avColor(c.id) + '">' + esc(initials(c.name)) + '</span><span><b>' + esc(c.name || c.email) + '</b><small>Kontakt' + (c.type ? ' · ' + esc(c.type) : '') + '</small></span></div><dl class="crm-dl">' +
			(c.email ? '<dt>E-mail</dt><dd><a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a></dd>' : '') + (ph ? '<dt>Telefon</dt><dd><a href="tel:' + esc(String(ph).replace(/[^\d+]/g, '')) + '">' + esc(ph) + '</a>' + (c.mobile && c.phone && c.phone !== c.mobile ? ' · ' + esc(c.phone) : '') + '</dd>' : '') +
			(co ? '<dt>Cég</dt><dd><a href="#" data-co="' + co.id + '">' + esc(co.name) + '</a></dd>' : '') + (c.city ? '<dt>Város</dt><dd>' + esc(c.city) + '</dd>' : '') + (c.tags ? '<dt>Címkék</dt><dd>' + esc(c.tags) + '</dd>' : '') +
			(c.utm_source || c.utm_campaign ? '<dt>Honnan jött</dt><dd>' + esc([c.utm_source, c.utm_medium, c.utm_campaign].filter(Boolean).join(' / ')) + '</dd>' : '') + '<dt>Felvéve</dt><dd>' + esc(huDT(c.created_at)) + '</dd></dl>' +
			(c.description ? '<div class="crm-desc">' + nl(c.description) + '</div>' : '');
		entityView(sh, esc(c.name || c.email), back, info, x, 'ct', id, c.name || c.email, '');
	}
	async function openCompany(id, back) {
		const sh = sheet360(head360('Cég', back) + '<div class="cpx-empty">Betöltés…</div>');
		if (!sh) return;
		bindBack(sh, back);
		let x;
		try { x = await post('cp_crm_company', { id: id }); } catch (e) { sh.innerHTML = head360('Cég', back) + '<div class="cpx-empty err">' + esc(e.message) + '</div>'; bindBack(sh, back); return; }
		const co = x.company;
		const info = '<div class="d3-who big"><span class="d3-av sq" style="background:' + avColor(co.id + 3) + '">' + esc(initials(co.name)) + '</span><span><b>' + esc(co.name) + '</b><small>Cég' + (co.type ? ' · ' + esc(co.type) : '') + '</small></span></div><dl class="crm-dl">' +
			(safeUrl(co.website) ? '<dt>Weboldal</dt><dd><a href="' + esc(safeUrl(co.website)) + '" target="_blank" rel="noopener">' + esc(co.website) + ' ↗</a></dd>' : '') + (co.phone ? '<dt>Telefon</dt><dd>' + esc(co.phone) + '</dd>' : '') + '</dl>';
		const extra = '<section class="d3-c"><h4>Kontaktok <small>' + x.contacts.length + '</small></h4>' + (x.contacts.length ? '<ul class="d3-team">' + x.contacts.map(c => '<li><a href="#" data-ct="' + c.id + '"><span class="d3-av" style="background:' + avColor(c.id) + '">' + esc(initials(c.name)) + '</span>' + esc(c.name || c.email) + '</a><small>' + esc(c.email || '') + '</small></li>').join('') + '</ul>' : '<p class="cpx-muted">Nincs.</p>') + '</section>';
		entityView(sh, esc(co.name), back, info, x, 'co', id, co.name, extra);
	}
	/* a deal-listában: cég / kontakt szerinti szűrés kereséssel */
	function whoPicker(btn) {
		const pop = document.createElement('div');
		pop.className = 'd3-wpop';
		pop.innerHTML = '<input type="search" placeholder="Cég vagy kontakt neve, e-mail…" aria-label="Cég vagy kontakt"><div class="d3-wl"><p class="cpx-muted">Írj be legalább 2 betűt.</p></div>';
		btn.after(pop);
		const inp = pop.querySelector('input'), l = pop.querySelector('.d3-wl');
		inp.focus();
		let t = null;
		inp.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => {
			const q = inp.value.trim(); if (q.length < 2) { l.innerHTML = '<p class="cpx-muted">Írj be legalább 2 betűt.</p>'; return; }
			let r; try { r = await post('cp_crm_who', { q: q }); } catch (e) { l.innerHTML = '<p class="cpx-muted">' + esc(e.message) + '</p>'; return; }
			l.innerHTML = r.rows.length ? r.rows.map(w => '<button type="button" data-wk="' + w.k + '" data-wid="' + w.id + '" data-wn="' + esc(w.n) + '"><span>' + (w.k === 'co' ? '🏢 ' : '👤 ') + esc(w.n) + (w.e && w.e !== w.n ? ' <small>' + esc(w.e) + '</small>' : '') + '</span><small>' + w.c + ' deal</small></button>').join('') : '<p class="cpx-muted">Nincs találat.</p>';
			l.querySelectorAll('[data-wk]').forEach(b => b.addEventListener('click', () => { pop.remove(); S.who = { k: b.dataset.wk, id: Number(b.dataset.wid), n: b.dataset.wn }; S.st = ''; S.p = 0; SEL.clear(); save(); view(S.main); }));
		}, 250); });
		setTimeout(() => document.addEventListener('click', function off(e) { if (!pop.isConnected) { document.removeEventListener('click', off); return; } if (!pop.contains(e.target) && e.target !== btn) { pop.remove(); document.removeEventListener('click', off); } }), 0);
	}
	/* ------------------------------------------------------------ deal szerkesztése (az adatlapon) */
	const EDIT = [
		['Deal', [['title', 'Név', 'text'], ['value', 'Érték (Ft)', 'num'], ['owner_id', 'Felelős', 'user'], ['source', 'Forrás', 'text']]],
		['Rendezvény', [['event_d', 'Rendezvény dátuma', 'date'], ['start_at', 'Kezdés (óra:perc)', 'time'], ['venue', 'Helyszín', 'text'], ['guests', 'Tervezett létszám', 'int'], ['hours', 'Rendelkezésre állás (óra)', 'int'],
			['service', 'Szolgáltatás', 'text'], ['pkg_fix', 'Választott csomag', 'text'], ['services_ec', 'EventCrazy szolgáltatás(ok)', 'multi'], ['services_cp', 'Választott szolgáltatás', 'multi'], ['services_more', 'További szolgáltatások', 'multi'], ['extras', 'Választott extra', 'multi'],
			['background', 'Fotófal / háttér', 'text'], ['km_fee', 'Kiszállási díj (Ft)', 'int'], ['extension', 'Hosszabbítás (óra)', 'num'], ['operator', 'Gépkezelő asszisztens', 'text'], ['album', 'Fotóalbum', 'text'], ['template_st', 'Template', 'text'], ['coupon', 'Kuponkód', 'area']]],
		['Pénzügy és szerződés', [['pay_method', 'Fizetési mód', 'text'], ['paid', 'Bejött az utalás?', 'text'], ['ctype', 'Szerződés EV/KFT', 'text']]],
		['Megjegyzés', [['note', 'Megjegyzés', 'area']]]
	];
	const CEDIT = [['name', 'Név', 'text'], ['email', 'E-mail', 'email'], ['mobile', 'Mobil', 'tel'], ['phone', 'Telefon', 'tel']];
	const timeOf = v => (v && /\d{2}:\d{2}/.test(v) ? v.match(/\d{2}:\d{2}/)[0] : '');
	function editVal(d, k) { const v = d[k]; if (v == null) return ''; if (k === 'start_at') return timeOf(v); if (k === 'value' || k === 'extension') return String(Number(v)); return String(v); }
	async function editDeal(sh, x) {
		const d = x.deal, c = x.contact || {};
		let vals = {};
		try { vals = (await post('cp_crm_opts', {})).vals || {}; } catch (e) { /* választólista nélkül is szerkeszthető */ }
		const field = (k, label, type, cur) => {
			const nm = ' data-k="' + k + '"';
			let inp;
			if (type === 'user') inp = '<select' + nm + '>' + opts([['', '–']].concat(Object.keys(USERS).filter(i => USERS[i].a || String(i) === String(cur)).map(i => [i, USERS[i].n])), String(cur || '')) + '</select>';
			else if (type === 'area') inp = '<textarea rows="3"' + nm + '>' + esc(cur) + '</textarea>';
			else if (type === 'multi') {
				const on = String(cur || '').split(' | ').filter(Boolean);
				const all = Array.from(new Set(((vals[k] || [])).concat(on)));
				inp = '<span class="crm-multi"' + nm + ' data-multi>' + all.map(v => '<label><input type="checkbox" value="' + esc(v) + '"' + (on.includes(v) ? ' checked' : '') + '> ' + esc(v) + '</label>').join('') + (all.length ? '' : '<small class="cpx-muted">Nincs választható érték.</small>') + '</span>';
			} else {
				const t = { date: 'date', time: 'time', int: 'number', num: 'number', email: 'email', tel: 'tel' }[type] || 'text';
				inp = '<input type="' + t + '"' + (type === 'num' ? ' step="0.5"' : '') + nm + ' value="' + esc(cur) + '"' + (vals[k] && type === 'text' ? ' list="crm-l-' + k + '"' : '') + '>' + (vals[k] && type === 'text' ? '<datalist id="crm-l-' + k + '">' + vals[k].map(v => '<option value="' + esc(v) + '">').join('') + '</datalist>' : '');
			}
			return '<label class="crm-ef"><span>' + esc(label) + '</span>' + inp + '</label>';
		};
		let h = sheetHead('Szerkesztés: ' + esc(d.title || 'Deal #' + d.id)) + '<form class="crm-edit" novalidate>';
		EDIT.forEach(sec => { h += '<h4>' + esc(sec[0]) + '</h4><div class="crm-eg">' + sec[1].map(f => field(f[0], f[1], f[2], editVal(d, f[0]))).join('') + '</div>'; });
		if (x.contact) h += '<h4>Kapcsolat</h4><div class="crm-eg" data-contact>' + CEDIT.map(f => field('c_' + f[0], f[1], f[2], c[f[0]] || '')).join('') + '</div>';
		h += '<p class="crm-ro">Mentéskor csak a megváltozott mezők íródnak be, a Salesmate-be is (a Salesmate automatizmusai lefuthatnak). A változás a deal előzményeiben a neveddel jelenik meg.</p>';
		h += '<div class="crm-ebar"><button class="cpx-btn" type="submit">Mentés</button><button class="cpx-btn ghost" type="button" data-cancel>Mégse</button><span class="crm-emsg" aria-live="polite"></span></div></form>';
		sh.innerHTML = h;
		sh.scrollTop = 0;
		const form = sh.querySelector('form');
		const read = el => el.dataset.multi !== undefined ? Array.from(el.querySelectorAll('input:checked')).map(i => i.value).join(' | ') : el.value.trim();
		const orig = {};
		form.querySelectorAll('[data-k]').forEach(el => { orig[el.dataset.k] = read(el); });
		sh.querySelector('[data-cancel]').onclick = () => openDeal(d.id);
		form.addEventListener('submit', async e => {
			e.preventDefault();
			const ch = {}, cc = {};
			form.querySelectorAll('[data-k]').forEach(el => { const k = el.dataset.k, v = read(el); if (v !== orig[k]) { if (k.indexOf('c_') === 0) cc[k.slice(2)] = v; else ch[k] = v; } });
			const msg = sh.querySelector('.crm-emsg');
			if (!Object.keys(ch).length && !Object.keys(cc).length) { msg.textContent = 'Nem változott semmi.'; return; }
			if (ch.title !== undefined && !ch.title) { msg.textContent = 'A név nem lehet üres.'; return; }
			const btn = form.querySelector('[type=submit]'); btn.disabled = true; msg.textContent = 'Mentés…'; msg.className = 'crm-emsg';
			try {
				const r = await post('cp_crm_save', { id: d.id, f: JSON.stringify(ch), c: JSON.stringify(cc) });
				toast(r.warn ? 'Mentve, de: ' + r.warn : 'Mentve – a Salesmate-ben is.', !!r.warn);
				if (S.mode === 'list') load(); else if (S.board) { const i = S.board.rows.findIndex(y => y.id === d.id); if (i >= 0 && r.row) { S.board.rows[i] = r.row; redrawBoard(); } }
				openDeal(d.id);
			} catch (err) { btn.disabled = false; msg.textContent = err.message; msg.className = 'crm-emsg err'; }
		});
	}

	/* ------------------------------------------------------------ új deal */
	async function newDeal(pre) {
		pre = typeof pre === 'string' && STAGES.includes(pre) ? pre : STAGES[0];
		let vals = {};
		const sh0 = sheet(sheetHead('Új deal') + '<div class="cpx-empty">Betöltés…</div>');
		if (!sh0) return;
		try { vals = (await post('cp_crm_opts', {})).vals || {}; } catch (e) { /* választólista nélkül is */ }
		if (!sh0.isConnected) return;
		const me = R.me_sm && USERS[String(R.me_sm)] ? String(R.me_sm) : '';
		const inp = (k, label, type, extra) => '<label class="crm-ef"><span>' + esc(label) + '</span>' + (type === 'area' ? '<textarea rows="3" data-k="' + k + '"></textarea>' : '<input type="' + type + '" data-k="' + k + '"' + (extra || '') + '>') + '</label>';
		const multi = k => '<span class="crm-multi" data-k="' + k + '" data-multi>' + (vals[k] || []).map(v => '<label><input type="checkbox" value="' + esc(v) + '"> ' + esc(v) + '</label>').join('') + '</span>';
		let h = sheetHead('Új deal') + '<form class="crm-edit" novalidate>' +
			'<h4>Kapcsolat</h4><div class="crm-eg">' + inp('c_name', 'Név *', 'text', ' required') + inp('c_email', 'E-mail', 'email') + inp('c_mobile', 'Telefon', 'tel') + '</div>' +
			'<p class="crm-ro" style="margin-top:6px">Ha az e-mail-cím már szerepel egy kontaktnál, a deal ahhoz kerül.</p>' +
			'<h4>Rendezvény</h4><div class="crm-eg">' + inp('event_d', 'Rendezvény dátuma', 'date') + inp('start_at', 'Kezdés (óra:perc)', 'time') + inp('venue', 'Helyszín', 'text') + inp('guests', 'Tervezett létszám', 'number') + inp('hours', 'Rendelkezésre állás (óra)', 'number') +
			'<label class="crm-ef"><span>Szolgáltatás</span><input type="text" data-k="service" list="crm-n-svc"><datalist id="crm-n-svc">' + (vals.service || []).map(v => '<option value="' + esc(v) + '">').join('') + '</datalist></label>' +
			'<label class="crm-ef"><span>EventCrazy szolgáltatás(ok)</span>' + multi('services_ec') + '</label></div>' +
			'<h4>Deal</h4><div class="crm-eg">' + inp('title', 'Deal neve *', 'text', ' required') +
			'<label class="crm-ef"><span>Szakasz</span><select data-k="stage">' + opts(STAGES.map(x => [x, x]), pre) + '</select></label>' +
			'<label class="crm-ef"><span>Felelős</span><select data-k="owner_id">' + opts([['', '–']].concat(Object.keys(USERS).filter(i => USERS[i].a).map(i => [i, USERS[i].n])), me) + '</select></label>' +
			inp('value', 'Érték (Ft)', 'number') + inp('note', 'Megjegyzés', 'area') + '</div>' +
			'<p class="crm-ro">A deal a Salesmate-ben is létrejön, és a Salesmate automatizmusai lefutnak (pl. Pumble-értesítés, átnevezés).</p>' +
			'<div class="crm-ebar"><button class="cpx-btn" type="submit">Deal létrehozása</button><button class="cpx-btn ghost" type="button" data-close>Mégse</button><span class="crm-emsg" aria-live="polite"></span></div></form>';
		sh0.innerHTML = h;
		const form = sh0.querySelector('form');
		const q = k => form.querySelector('[data-k="' + k + '"]');
		let titleTouched = false;
		q('title').addEventListener('input', () => { titleTouched = true; });
		/* a deal neve magától: „[Crazyphoto] Név | dátum” (amíg kézzel át nem írod) */
		const auto = () => { if (titleTouched) return; const n = q('c_name').value.trim(), dt = q('event_d').value; q('title').value = n ? '[Crazyphoto] ' + n + (dt ? ' | ' + dt : '') : ''; };
		q('c_name').addEventListener('input', auto); q('event_d').addEventListener('input', auto); q('event_d').addEventListener('change', auto);
		form.addEventListener('submit', async e => {
			e.preventDefault();
			const msg = sh0.querySelector('.crm-emsg');
			const d = {};
			form.querySelectorAll('[data-k]').forEach(el => { d[el.dataset.k] = el.dataset.multi !== undefined ? Array.from(el.querySelectorAll('input:checked')).map(i => i.value).join(' | ') : el.value.trim(); });
			if (!d.c_name) { msg.textContent = 'Add meg a kontakt nevét.'; msg.className = 'crm-emsg err'; return; }
			if (!d.title) { msg.textContent = 'Add meg a deal nevét.'; msg.className = 'crm-emsg err'; return; }
			if (!window.confirm('Létrehozod a deal-t: ' + d.title + '?\n\nA Salesmate-ben is létrejön, és az automatizmusok lefutnak.')) return;
			const btn = form.querySelector('[type=submit]'); btn.disabled = true; msg.textContent = 'Létrehozás…'; msg.className = 'crm-emsg';
			try {
				const r = await post('cp_crm_create', { d: JSON.stringify(d) });
				toast('A deal létrejött (#' + r.id + ') – a Salesmate-ben is.');
				load();
				openDeal(r.id);
			} catch (err) { btn.disabled = false; msg.textContent = err.message; msg.className = 'crm-emsg err'; }
		});
		q('c_name').focus();
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

	/* mélylink: #deals:leads = a Megkeresések nézet */
	function deep(arg) { if (arg === 'leads') { S.mode = 'board'; save(); if (S.main && S.main.isConnected) view(S.main); } else if (Number(arg) > 0) openDeal(Number(arg)); }
	window.CPCrm = { view, status, refresh, open: id => openDeal(id), contact: id => openContact(id), company: id => openCompany(id), deep };
})();
