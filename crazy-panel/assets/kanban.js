/* Crazy panel – Feladatok fül (v1.35): Trello-szerű kanban – csapat- és saját táblák, kártyák felelőssel, határidővel,
 * címkékkel, ellenőrzőlistával, hozzászólásokkal (@ megemlítés); levélből és deal-ből egy kattintással teendő.
 * A Salesmate-hez nem nyúl: minden a panel saját tábláiban van. */
(function () {
	'use strict';
	const C = window.CPX || {};
	const K = C.kb || {};
	const PEOPLE = K.people || [];
	const LABELS = K.labels || [];
	const ME = K.me || 0;

	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const fold = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
	const S = { boards: null, mineN: 0, cur: null, data: null, q: '', onlyMine: false, arch: false, main: null, err: '', loading: false, pending: null };
	try { S.hideActs = localStorage.getItem('cpkb_hideacts') === '1'; } catch (e) { /* privát mód */ }
	try { const v = localStorage.getItem('cpkb_cur'); if (v) S.cur = v === 'mine' || v === 'people' ? v : Number(v); } catch (e) { /* privát mód */ }

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
	function toast(t, bad) {
		let d = document.getElementById('cpx-toast');
		if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); }
		d.textContent = t; d.classList.toggle('bad', !!bad); d.classList.add('on');
		clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), bad ? 6000 : 3200);
	}

	const pname = id => (PEOPLE.find(p => p.id === id) || {}).n || '';
	const ini = n => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?';
	const AVC = ['#C9D6FB', '#F9D4B8', '#FBE3A6', '#D6CCFA', '#C5EBD6', '#F6C6C6', '#BFE3EE'];
	const avc = s => AVC[Math.abs(String(s || '').split('').reduce((a, c) => a + c.charCodeAt(0), 0)) % AVC.length];
	const av = (id, cls) => { const n = pname(id) || '?'; return '<span class="kb-av' + (cls ? ' ' + cls : '') + '" style="background:' + avc(n) + '" title="' + esc(n) + '">' + esc(ini(n)) + '</span>'; };
	const lname = k => (LABELS.find(l => l.k === k) || {}).n || k;
	const pad = n => (n < 10 ? '0' : '') + n;
	const today = () => { const d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
	const addDays = (s, n) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
	const HMON = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
	const hd = s => { const m = String(s || '').match(/^(\d{4})-(\d\d)-(\d\d)/); return m ? HMON[Number(m[2]) - 1] + ' ' + Number(m[3]) + '.' : ''; };
	function ago(s) {
		if (!s) return '';
		const t = new Date(String(s).replace(' ', 'T')), d = (Date.now() - t.getTime()) / 1000;
		if (d < 90) return 'pár másodperce';
		if (d < 3600) return Math.round(d / 60) + ' perce';
		if (d < 86400) return Math.round(d / 3600) + ' órája';
		if (d < 172800) return 'tegnap';
		return String(s).slice(0, 10).replace(/-/g, '.') + '.';
	}
	const nl2br = s => esc(s).replace(/\n/g, '<br>').replace(/@([A-ZÁÉÍÓÖŐÚÜŰ][\wáéíóöőúüű]+(?: [A-ZÁÉÍÓÖŐÚÜŰ][\wáéíóöőúüű]+)?)/g, '<b class="kb-men">@$1</b>');
	const svg = (d, c) => '<svg class="' + (c || 'kb-ic') + '" viewBox="0 0 24 24" aria-hidden="true">' + d + '</svg>';
	const I = {
		clock: svg('<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>'),
		desc: svg('<path d="M5 7h14M5 12h14M5 17h9"/>'),
		com: svg('<path d="M5 5h14v10H9l-4 4z"/>'),
		check: svg('<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8.5 12.5l2.5 2.5 4.5-5.5"/>'),
		deal: svg('<circle cx="12" cy="12" r="8.5"/><path d="M14.6 9.4c-.4-.9-1.4-1.4-2.6-1.4-1.5 0-2.6.7-2.6 1.8s1.1 1.5 2.6 1.8 2.7.8 2.7 2-1.2 1.9-2.7 1.9c-1.3 0-2.3-.6-2.7-1.5M12 6.3v1.6M12 16.2v1.5"/>'),
		mail: svg('<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/>'),
		lock: svg('<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>'),
		team: svg('<circle cx="9" cy="9" r="3"/><circle cx="17" cy="10" r="2.3"/><path d="M3.5 19c.5-3 2.8-4.5 5.5-4.5s5 1.5 5.5 4.5M14.5 15c2.6-.4 5 .8 5.5 3.5"/>'),
		dots: svg('<circle cx="5.5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="18.5" cy="12" r="1.4"/>', 'kb-ic fill'),
		plus: svg('<path d="M12 5v14M5 12h14"/>'),
		x: svg('<path d="M6 6l12 12M18 6L6 18"/>'),
		people: svg('<circle cx="8" cy="8.5" r="2.8"/><circle cx="16.5" cy="8.5" r="2.8"/><path d="M2.5 19c.5-3 2.6-4.6 5.5-4.6s5 1.6 5.5 4.6M12.5 15.2c1-.5 2.4-.8 4-.8 2.9 0 5 1.6 5.5 4.6"/>'),
		user: svg('<circle cx="12" cy="8.5" r="3.5"/><path d="M5 19.5c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5"/>'),
		tag: svg('<path d="M4 4h7l9 9-7 7-9-9z"/><circle cx="8.5" cy="8.5" r="1.3"/>'),
		arch: svg('<rect x="3.5" y="4.5" width="17" height="4" rx="1"/><path d="M5 8.5V19h14V8.5M10 12.5h4"/>')
	};

	function dueCls(c) {
		if (!c.due) return '';
		if (c.done) return 'done';
		const t = today();
		if (c.due < t) return 'late';
		if (c.due <= addDays(t, 1)) return 'soon';
		return '';
	}

	/* ------------------------------------------------------------ kártya a listában */
	function tile(c, links, ctx) {
		const d = c.deal ? (links.deals || {})[c.deal] : null, th = c.th ? (links.threads || {})[c.th] : null;
		const b = [];
		if (c.due) b.push('<span class="kb-b due ' + dueCls(c) + '" title="Határidő">' + I.clock + esc(hd(c.due)) + '</span>');
		if (c.hasd) b.push('<span class="kb-b" title="Van leírása">' + I.desc + '</span>');
		if (c.nc) b.push('<span class="kb-b" title="Hozzászólások">' + I.com + c.nc + '</span>');
		if (c.cl[1]) b.push('<span class="kb-b' + (c.cl[0] === c.cl[1] ? ' ok' : '') + '" title="Ellenőrzőlista">' + I.check + c.cl[0] + '/' + c.cl[1] + '</span>');
		if (c.deal) b.push('<span class="kb-b lnk" title="' + esc(d ? d.t : 'Deal #' + c.deal) + '">' + I.deal + esc(d ? short(d.t, 26) : '#' + c.deal) + '</span>');
		if (c.th) b.push('<span class="kb-b lnk" title="' + esc(th ? th.t : 'Levél') + '">' + I.mail + esc(th ? short(th.cn || th.t, 18) : 'levél') + '</span>');
		return '<div class="kb-card' + (c.done ? ' isdone' : '') + '" draggable="' + (ctx === 'board' ? 'true' : 'false') + '" data-card="' + c.id + '">' +
			(c.lb.length ? '<div class="kb-lbs">' + c.lb.map(l => '<span class="kb-lb ' + esc(l) + '" title="' + esc(lname(l)) + '"></span>').join('') + '</div>' : '') +
			(ctx === 'mine' || ctx === 'people' ? '<div class="kb-ctx">' + esc(c.bn) + ' › ' + esc(c.ln) + '</div>' : '') +
			'<div class="kb-ct">' + esc(c.title) + '</div>' +
			(b.length || c.as.length ? '<div class="kb-bs">' + b.join('') + '<span class="kb-avs">' + c.as.slice(0, 4).map(id => av(id)).join('') + '</span></div>' : '') + '</div>';
	}
	const short = (s, n) => { s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; };

	/* ------------------------------------------------------------ fő nézet */
	async function view(main) {
		S.main = main;
		if (!S.boards) {
			main.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
			try { await loadBoards(); } catch (e) { main.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		}
		if (S.cur !== 'mine' && S.cur !== 'people' && !S.boards.some(b => b.id === S.cur)) S.cur = S.boards.length ? S.boards[0].id : 'mine';
		const tab = (id, label, n, icon) => '<button type="button" data-b="' + id + '" class="' + (String(S.cur) === String(id) ? 'on' : '') + '">' + (icon || '') + '<span>' + esc(label) + '</span>' + (n ? '<b>' + n + '</b>' : '') + '</button>';
		const cb = S.boards.find(b => b.id === S.cur);
		main.innerHTML = '<div class="kb">' +
			'<div class="kb-bar"><nav class="kb-tabs">' + tab('mine', 'Nekem', S.mineN, I.user) + tab('people', 'Kollégák', 0, I.people) +
			S.boards.filter(b => b.k === 'team').map(b => tab(b.id, b.n, 0, I.team)).join('') +
			S.boards.filter(b => b.k === 'personal').map(b => tab(b.id, b.n, 0, I.lock)).join('') +
			'<button type="button" class="kb-addb" data-newb>' + I.plus + '<span>Új tábla</span></button></nav>' +
			'<div class="kb-tools">' + (S.cur === 'mine' ? '' : S.cur === 'people' ? '<input type="search" class="kb-q" placeholder="Keresés a teendők között" value="' + esc(S.q) + '"><label class="kb-chk"><input type="checkbox" data-pdone' + (S.pDone ? ' checked' : '') + '> Készek is</label>' :
				'<input type="search" class="kb-q" placeholder="Keresés a kártyák között" value="' + esc(S.q) + '">' +
				'<label class="kb-chk"><input type="checkbox" data-onlymine' + (S.onlyMine ? ' checked' : '') + '> Csak az enyém</label>' +
				'<button type="button" class="kb-tb' + (S.arch ? ' on' : '') + '" data-arch title="Archivált kártyák">' + I.arch + '<span>Archív</span></button>' +
				(cb ? '<button type="button" class="kb-tb" data-bmenu title="Tábla műveletei">' + I.dots + '</button>' : '')) + '</div></div>' +
			'<div class="kb-body"></div></div>';
		main.querySelectorAll('[data-b]').forEach(b => b.addEventListener('click', () => { S.cur = b.dataset.b === 'mine' || b.dataset.b === 'people' ? b.dataset.b : Number(b.dataset.b); S.arch = false; try { localStorage.setItem('cpkb_cur', String(S.cur)); } catch (e) { /* privát mód */ } view(main); }));
		main.querySelector('[data-newb]').addEventListener('click', newBoard);
		const q = main.querySelector('.kb-q');
		if (q) { let t = null; q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { S.q = q.value; if (S.cur === 'people') drawPeople(); else drawBoard(); }, 200); }); }
		const pd = main.querySelector('[data-pdone]');
		if (pd) pd.addEventListener('change', () => { S.pDone = pd.checked; S.pData = null; drawPeople(true); });
		const om = main.querySelector('[data-onlymine]');
		if (om) om.addEventListener('change', () => { S.onlyMine = om.checked; drawBoard(); });
		const ar = main.querySelector('[data-arch]');
		if (ar) ar.addEventListener('click', () => { S.arch = !S.arch; ar.classList.toggle('on', S.arch); S.data = null; drawBoard(true); });
		const bm = main.querySelector('[data-bmenu]');
		if (bm) bm.addEventListener('click', () => boardMenu(bm, cb));
		if (S.cur === 'mine') drawMine(); else if (S.cur === 'people') drawPeople(true); else drawBoard(true);
	}

	async function loadBoards() {
		const r = await post('cp_kb_boards', {});
		S.boards = r.boards || [];
		S.mineN = r.mine || 0;
	}

	function popMenu(btn, items) {
		document.querySelectorAll('.kb-pop').forEach(p => p.remove());
		const pop = document.createElement('div');
		pop.className = 'kb-pop';
		pop.innerHTML = items.map((it, i) => it ? '<button type="button" data-i="' + i + '" class="' + (it.bad ? 'bad' : '') + '"' + (it.off ? ' disabled' : '') + '>' + esc(it.t) + '</button>' : '<hr>').join('');
		document.body.appendChild(pop);
		const r = btn.getBoundingClientRect();
		pop.style.top = (r.bottom + window.scrollY + 4) + 'px';
		pop.style.left = Math.max(8, Math.min(window.innerWidth - pop.offsetWidth - 8, r.right - pop.offsetWidth)) + 'px';
		pop.querySelectorAll('[data-i]').forEach(b => b.addEventListener('click', () => { pop.remove(); items[Number(b.dataset.i)].fn(); }));
		setTimeout(() => document.addEventListener('click', function off(e) { if (!pop.contains(e.target)) { pop.remove(); document.removeEventListener('click', off); } }), 0);
	}

	async function newBoard() {
		const n = window.prompt('Az új tábla neve:');
		if (!n || !n.trim()) return;
		const own = ME ? window.confirm('Saját (csak te látod) tábla legyen?\n\nOK = saját tábla · Mégse = csapattábla (mindenki látja)') : false;
		try { const r = await post('cp_kb_board_save', { name: n.trim(), kind: own ? 'personal' : 'team' }); await loadBoards(); S.cur = r.id; S.data = null; view(S.main); toast('Tábla létrehozva.'); } catch (e) { toast(e.message, true); }
	}
	function boardMenu(btn, b) {
		popMenu(btn, [
			{ t: 'Tábla átnevezése', fn: async () => { const n = window.prompt('A tábla új neve:', b.n); if (!n || !n.trim()) return; try { await post('cp_kb_board_save', { id: b.id, name: n.trim() }); await loadBoards(); view(S.main); } catch (e) { toast(e.message, true); } } },
			{ t: 'Új lista', fn: () => addList() },
			null,
			{ t: 'Tábla törlése', bad: true, fn: async () => { if (!window.confirm('Biztosan törlöd a(z) „' + b.n + '” táblát a kártyáival együtt?')) return; try { await post('cp_kb_board_save', { id: b.id, del: 1 }); await loadBoards(); S.cur = null; view(S.main); toast('Tábla törölve.'); } catch (e) { toast(e.message, true); } } }
		]);
	}

	/* ------------------------------------------------------------ tábla */
	async function drawBoard(reload) {
		const body = S.main && S.main.querySelector('.kb-body');
		if (!body) return;
		if (reload || !S.data || S.data.board.id !== S.cur) {
			if (!S.data || S.data.board.id !== S.cur) body.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
			try { S.data = await post('cp_kb_board', { id: S.cur, arch: S.arch ? 1 : 0 }); S.err = ''; } catch (e) { S.err = e.message; body.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		}
		const x = S.data, q = fold(S.q);
		const vis = c => (!q || fold(c.title).includes(q) || c.lb.some(l => fold(lname(l)).includes(q)) || c.as.some(id => fold(pname(id)).includes(q))) && (!S.onlyMine || c.as.includes(ME));
		if (S.arch) {
			const cs = x.cards.filter(vis);
			body.innerHTML = '<div class="kb-archl"><p class="cpx-muted">Archivált kártyák – nyisd meg, és állítsd vissza, ha mégis kell.</p>' + (cs.length ? '<div class="kb-grid">' + cs.map(c => tile(c, x.links, 'arch')).join('') + '</div>' : '<div class="cpx-empty">Nincs archivált kártya.</div>') + '</div>';
			body.querySelectorAll('[data-card]').forEach(el => el.addEventListener('click', () => openCard(Number(el.dataset.card))));
			return;
		}
		body.innerHTML = '<div class="kb-lists">' + x.lists.map(l => {
			const cs = x.cards.filter(c => c.list === l.id);
			const shown = cs.filter(vis);
			return '<section class="kb-list" data-list="' + l.id + '"><header><h3 data-lname>' + esc(l.n) + '</h3><small>' + cs.length + '</small><button type="button" class="kb-tb sm" data-lmenu title="Lista műveletei">' + I.dots + '</button></header>' +
				'<div class="kb-cards" data-drop="' + l.id + '">' + shown.map(c => tile(c, x.links, 'board')).join('') + '</div>' +
				'<footer><button type="button" class="kb-addc" data-addc>' + I.plus + 'Kártya hozzáadása</button></footer></section>';
		}).join('') + '<button type="button" class="kb-addl" data-addl>' + I.plus + 'Új lista</button></div>';
		body.querySelectorAll('[data-card]').forEach(el => el.addEventListener('click', () => { if (!el._dragged) openCard(Number(el.dataset.card)); }));
		body.querySelectorAll('[data-addc]').forEach(b => b.addEventListener('click', () => addCardForm(b.closest('.kb-list'))));
		body.querySelector('[data-addl]').addEventListener('click', () => addList());
		body.querySelectorAll('[data-lmenu]').forEach(b => b.addEventListener('click', () => listMenu(b, Number(b.closest('.kb-list').dataset.list))));
		body.querySelectorAll('[data-lname]').forEach(h => h.addEventListener('dblclick', () => renameList(Number(h.closest('.kb-list').dataset.list))));
		dnd(body);
		if (S.pending) { const id = S.pending; S.pending = null; openCard(id); }
	}

	function addCardForm(sec) {
		const f = sec.querySelector('footer');
		f.innerHTML = '<div class="kb-newc"><textarea rows="2" placeholder="A kártya címe… (Enter: mentés, Esc: mégse)"></textarea><div><button type="button" class="cpx-btn" data-save>Hozzáadás</button><button type="button" class="kb-tb" data-cancel>' + I.x + '</button></div></div>';
		const ta = f.querySelector('textarea');
		ta.focus();
		const close = () => drawBoard();
		const save = async () => {
			const t = ta.value.trim();
			if (!t) { close(); return; }
			ta.disabled = true;
			try { await post('cp_kb_card_save', { board: S.cur, list: sec.dataset.list, title: t }); await drawBoard(true); const s2 = S.main.querySelector('.kb-list[data-list="' + sec.dataset.list + '"]'); if (s2) addCardForm(s2); } catch (e) { toast(e.message, true); ta.disabled = false; }
		};
		ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); save(); } if (e.key === 'Escape') { e.stopPropagation(); close(); } });
		f.querySelector('[data-save]').addEventListener('click', save);
		f.querySelector('[data-cancel]').addEventListener('click', close);
	}
	async function addList() {
		const n = window.prompt('Az új lista neve:');
		if (!n || !n.trim()) return;
		try { await post('cp_kb_list_save', { board: S.cur, name: n.trim() }); drawBoard(true); } catch (e) { toast(e.message, true); }
	}
	async function renameList(id) {
		const l = S.data.lists.find(x => x.id === id);
		const n = window.prompt('A lista új neve:', l.n);
		if (!n || !n.trim()) return;
		try { await post('cp_kb_list_save', { board: S.cur, id: id, name: n.trim() }); drawBoard(true); } catch (e) { toast(e.message, true); }
	}
	function listMenu(btn, id) {
		const ids = S.data.lists.map(l => l.id), i = ids.indexOf(id);
		const move = async d => { const o = ids.slice(); o.splice(i, 1); o.splice(i + d, 0, id); try { await post('cp_kb_list_save', { board: S.cur, order: o.join(',') }); drawBoard(true); } catch (e) { toast(e.message, true); } };
		popMenu(btn, [
			{ t: 'Kártya hozzáadása', fn: () => addCardForm(S.main.querySelector('.kb-list[data-list="' + id + '"]')) },
			{ t: 'Átnevezés', fn: () => renameList(id) },
			{ t: '← Mozgatás balra', off: i === 0, fn: () => move(-1) },
			{ t: 'Mozgatás jobbra →', off: i === ids.length - 1, fn: () => move(1) },
			null,
			{ t: 'Lista törlése', bad: true, fn: async () => { if (!window.confirm('Törlöd a listát? (Csak üres lista törölhető.)')) return; try { await post('cp_kb_list_save', { board: S.cur, id: id, del: 1 }); drawBoard(true); } catch (e) { toast(e.message, true); } } }
		]);
	}

	/* húzd és ejtsd: kártyák a listák között és listán belül */
	function dnd(body) {
		let drag = null;
		const ph = document.createElement('div');
		ph.className = 'kb-ph';
		body.querySelectorAll('.kb-card[draggable="true"]').forEach(el => {
			el.addEventListener('dragstart', e => { drag = el; el._dragged = true; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', el.dataset.card); } catch (x) { /* régi böngésző */ } ph.style.height = el.offsetHeight + 'px'; setTimeout(() => el.classList.add('drag'), 0); });
			el.addEventListener('dragend', () => { el.classList.remove('drag'); ph.remove(); setTimeout(() => { el._dragged = false; }, 50); drag = null; body.querySelectorAll('.kb-cards.over').forEach(z => z.classList.remove('over')); });
		});
		body.querySelectorAll('[data-drop]').forEach(z => {
			z.addEventListener('dragover', e => {
				if (!drag) return;
				e.preventDefault();
				z.classList.add('over');
				const after = Array.from(z.querySelectorAll('.kb-card:not(.drag)')).find(c => { const r = c.getBoundingClientRect(); return e.clientY < r.top + r.height / 2; });
				if (after) z.insertBefore(ph, after); else z.appendChild(ph);
			});
			z.addEventListener('dragleave', e => { if (!z.contains(e.relatedTarget)) z.classList.remove('over'); });
			z.addEventListener('drop', async e => {
				if (!drag) return;
				e.preventDefault();
				z.classList.remove('over');
				const id = Number(drag.dataset.card), lid = Number(z.dataset.drop);
				z.insertBefore(drag, ph); ph.remove();
				/* a teljes sorrend: a szűrés miatt elrejtett kártyák a lista végére kerülnek */
				const seen = Array.from(z.querySelectorAll('.kb-card')).map(c => Number(c.dataset.card));
				const rest = S.data.cards.filter(c => c.list === lid && !seen.includes(c.id)).map(c => c.id);
				const order = seen.concat(rest);
				const c = S.data.cards.find(x => x.id === id);
				const from = c.list;
				c.list = lid;
				order.forEach((cid, i) => { const k = S.data.cards.find(x => x.id === cid); if (k) k.pos = i; });
				S.data.cards.sort((a, b) => a.pos - b.pos);
				drawBoard();
				try { await post('cp_kb_card_move', { id: id, list: lid, order: order.join(',') }); }
				catch (err) { toast(err.message, true); c.list = from; drawBoard(true); }
			});
		});
	}

	/* ------------------------------------------------------------ „Nekem”: rám osztott és engem megemlítő kártyák + levéljegyzetek */
	async function drawMine() {
		const body = S.main && S.main.querySelector('.kb-body');
		if (!body) return;
		body.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
		let x;
		try { x = await post('cp_kb_mine', {}); } catch (e) { body.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		if (!ME) { body.innerHTML = '<div class="cpx-empty"><b>Ehhez személyes fiókkal kell belépned</b><span>A közös jelszavas belépésnél nincs „Nekem” nézet és saját tábla.</span></div>'; return; }
		const t = today(), wk = addDays(t, 7);
		const G = [['Lejárt', c => c.due && c.due < t], ['Ma és holnap', c => c.due && c.due >= t && c.due <= addDays(t, 1)], ['A héten', c => c.due && c.due > addDays(t, 1) && c.due <= wk], ['Később', c => c.due && c.due > wk], ['Határidő nélkül', c => !c.due]];
		const cols = G.map(g => { const cs = x.cards.filter(g[1]); return '<section class="kb-list mine"><header><h3>' + g[0] + '</h3><small>' + cs.length + '</small></header><div class="kb-cards">' + (cs.length ? cs.map(c => tile(c, x.links, 'mine')).join('') : '<p class="kb-none">–</p>') + '</div></section>'; }).join('');
		const notes = '<section class="kb-list mine notes"><header><h3>Megemlítések a levelekben</h3><small>' + x.notes.length + '</small></header><div class="kb-cards">' + (x.notes.length ? x.notes.map(n => '<button type="button" class="kb-note" data-th="' + n.th + '"><span class="kb-ctx">' + I.mail + esc(short(n.subj, 60)) + '</span><span class="kb-nb">' + nl2br(short(n.b, 180)) + '</span><small>' + esc(n.by) + ' · ' + esc(ago(n.at)) + '</small></button>').join('') : '<p class="kb-none">Az elmúlt 30 napban senki nem említett meg levéljegyzetben.</p>') + '</div></section>';
		body.innerHTML = '<p class="kb-hint">Itt látod a rád osztott és a hozzászólásban téged megemlítő, még nem kész kártyákat minden tábláról, határidő szerint, és a levéljegyzeteket, amikben megemlítettek.</p><div class="kb-lists">' + cols + notes + '</div>';
		body.querySelectorAll('[data-card]').forEach(el => el.addEventListener('click', () => openCard(Number(el.dataset.card), drawMine)));
		body.querySelectorAll('[data-th]').forEach(el => el.addEventListener('click', () => openThread(Number(el.dataset.th))));
		S.mineN = x.cards.length;
		const b = S.main.querySelector('[data-b="mine"] b');
		if (b) b.textContent = S.mineN || '';
	}

	/* ------------------------------------------------------------ „Kollégák”: mindenki teendői, felhasználónként (publikus) */
	async function drawPeople(reload) {
		const body = S.main && S.main.querySelector('.kb-body');
		if (!body) return;
		if (reload || !S.pData) {
			if (!S.pData) body.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
			try { S.pData = await post('cp_kb_people', { done: S.pDone ? 1 : 0 }); S.err = ''; } catch (e) { body.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		}
		const x = S.pData, q = fold(S.q);
		const vis = c => !q || fold(c.title + ' ' + c.bn + ' ' + c.ln).includes(q);
		const order = PEOPLE.slice().sort((a, b) => (a.id === ME ? -1 : b.id === ME ? 1 : a.n.localeCompare(b.n, 'hu')));
		body.innerHTML = '<p class="kb-hint">Itt mindenki látja, kinek mi a teendője (a csapattáblákról). Új teendőt bárki adhat bárkinek az oszlop alján; a kártyát másik kolléga oszlopába húzva átadod neki. A saját (lakatos) táblák kártyái itt nem látszanak.</p>' +
			'<div class="kb-lists">' + order.map(p => {
				const cs = x.cards.filter(c => c.as.includes(p.id));
				return '<section class="kb-list person' + (p.id === ME ? ' me' : '') + '" data-person="' + p.id + '"><header>' + av(p.id) + '<h3>' + esc(p.n) + (p.id === ME ? ' (te)' : '') + '</h3><small>' + cs.filter(c => !c.done).length + '</small></header>' +
					'<div class="kb-cards" data-pdrop="' + p.id + '">' + cs.filter(vis).map(c => tile(c, x.links, 'people').replace('draggable="false"', 'draggable="true"')).join('') + '</div>' +
					'<footer><button type="button" class="kb-addc" data-padd>' + I.plus + 'Teendő ' + esc(p.id === ME ? 'magamnak' : p.n.split(' ').slice(-1)[0] + ' részére') + '</button></footer></section>';
			}).join('') + '</div>';
		body.querySelectorAll('[data-card]').forEach(el => el.addEventListener('click', () => { if (!el._dragged) openCard(Number(el.dataset.card)); }));
		body.querySelectorAll('[data-padd]').forEach(b => b.addEventListener('click', () => personAdd(b.closest('.kb-list'))));
		/* húzás másik kolléga oszlopába = átadás (a felelősök között a régi helyére az új kerül) */
		let drag = null, from = 0;
		body.querySelectorAll('.kb-card[draggable="true"]').forEach(el => {
			el.addEventListener('dragstart', e => { drag = el; from = Number(el.closest('[data-person]').dataset.person); el._dragged = true; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', el.dataset.card); } catch (er) { /* régi böngésző */ } setTimeout(() => el.classList.add('drag'), 0); });
			el.addEventListener('dragend', () => { el.classList.remove('drag'); setTimeout(() => { el._dragged = false; }, 50); drag = null; body.querySelectorAll('.over').forEach(z => z.classList.remove('over')); });
		});
		body.querySelectorAll('[data-pdrop]').forEach(z => {
			z.addEventListener('dragover', e => { if (drag && Number(z.dataset.pdrop) !== from) { e.preventDefault(); z.classList.add('over'); } });
			z.addEventListener('dragleave', e => { if (!z.contains(e.relatedTarget)) z.classList.remove('over'); });
			z.addEventListener('drop', async e => {
				if (!drag) return;
				e.preventDefault(); z.classList.remove('over');
				const to = Number(z.dataset.pdrop), c = x.cards.find(k => k.id === Number(drag.dataset.card));
				if (!c || to === from) return;
				const as = c.as.filter(u => u !== from && u !== to).concat([to]);
				const old = c.as; c.as = as; drawPeople();
				try { await post('cp_kb_card_save', { id: c.id, as: as.join(',') }); toast('Átadva: ' + pname(to) + '.'); }
				catch (er) { c.as = old; drawPeople(); toast(er.message, true); }
			});
		});
	}
	function personAdd(sec) {
		const uid = Number(sec.dataset.person), f = sec.querySelector('footer');
		f.innerHTML = '<div class="kb-newc"><textarea rows="2" placeholder="Mi a teendő? (Enter: mentés, Esc: mégse)"></textarea><div class="kb-pdue"><input type="date" title="Határidő (nem kötelező)"><button type="button" class="cpx-btn" data-save>Kiosztás</button><button type="button" class="kb-tb" data-cancel>' + I.x + '</button></div></div>';
		const ta = f.querySelector('textarea'), du = f.querySelector('input[type=date]');
		ta.focus();
		const save = async () => {
			const t = ta.value.trim();
			if (!t) { drawPeople(); return; }
			ta.disabled = true;
			try {
				const p = { board: S.pData.todo, list: (S.pData.lists[0] || {}).id || 0, title: t, as: String(uid), top: 1 };
				if (du.value) p.due = du.value;
				await post('cp_kb_card_save', p);
				await drawPeople(true);
				toast(uid === ME ? 'Teendő felvéve.' : 'Kiosztva ' + pname(uid) + ' részére – értesítést kap róla.');
			} catch (e) { toast(e.message, true); ta.disabled = false; }
		};
		ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); save(); } if (e.key === 'Escape') { e.stopPropagation(); drawPeople(); } });
		f.querySelector('[data-save]').addEventListener('click', save);
		f.querySelector('[data-cancel]').addEventListener('click', () => drawPeople());
	}

	/* átlépés más fülre */
	function openThread(id) { if (window.CPMail && window.CPMail.open) window.CPMail.open(id); else toast('A Levelezés nem érhető el.', true); }
	function openDeal(id) { if (window.CPCrm && window.CPCrm.open) window.CPCrm.open(id); else toast('A Deal-ek fül nem érhető el.', true); }

	/* ------------------------------------------------------------ kártya adatlap */
	function sheet(html) {
		const m = document.getElementById('cpx-modal');
		if (!m) return null;
		m.innerHTML = '<div class="cpx-sheet wide kb-sheet" role="dialog" aria-modal="true">' + html + '</div>';
		m.hidden = false;
		return m.querySelector('.kb-sheet');
	}
	async function openCard(id, after) {
		const sh = sheet('<div class="cpx-empty">Betöltés…</div>');
		if (!sh) return;
		sh._after = after || null;
		let x;
		try { x = await post('cp_kb_card', { id: id }); } catch (e) { sh.innerHTML = '<div class="cpx-sheet-h"><h3>Kártya</h3><button class="cpx-x" type="button" data-close aria-label="Bezárás">×</button></div><div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		drawCard(sh, x);
	}
	function changed(sh) {
		if (sh && sh._after) sh._after();
		if (S.main && S.main.isConnected && S.main.querySelector('.kb')) { if (S.cur === 'mine') drawMine(); else if (S.cur === 'people') drawPeople(true); else drawBoard(true); }
	}

	function drawCard(sh, x) {
		const c = x.card, L = x.links || {};
		const d = c.deal ? (L.deals || {})[c.deal] : null, th = c.th ? (L.threads || {})[c.th] : null;
		const lists = S.data && S.data.board.id === c.board ? S.data.lists : null;
		const cl = c.checklist || [];
		const pct = cl.length ? Math.round(cl.filter(i => i.d).length / cl.length * 100) : 0;
		sh.innerHTML = '<div class="kb-sh">' +
			'<div class="kb-shh"><textarea class="kb-title" rows="1" data-title maxlength="255">' + esc(c.title) + '</textarea><button class="cpx-x" type="button" data-close aria-label="Bezárás">×</button></div>' +
			'<p class="kb-where">' + esc(c.bn) + (lists ? ' › <select data-list aria-label="Lista">' + lists.map(l => '<option value="' + l.id + '"' + (l.id === c.list ? ' selected' : '') + '>' + esc(l.n) + '</option>').join('') + '</select>' : '') + (c.arch ? ' · <b class="kb-archtag">archivált</b>' : '') + '</p>' +
			'<div class="kb-row"><div class="kb-fld"><h5>Felelősök</h5><div class="kb-people">' + PEOPLE.filter(p => c.bk !== 'personal' || p.id === ME || c.as.includes(p.id)).map(p => '<button type="button" class="kb-pp' + (c.as.includes(p.id) ? ' on' : '') + '" data-as="' + p.id + '">' + av(p.id) + '<span>' + esc(p.n) + '</span></button>').join('') + (PEOPLE.length ? '' : '<span class="cpx-muted">Nincsenek személyes fiókok.</span>') + '</div>' + (c.bk === 'personal' ? '<small class="cpx-muted">Saját tábla: csak te látod. Kollégának szóló teendőt a Kollégák nézetben vagy egy csapattáblán vegyél fel.</small>' : '') + '</div>' +
			'<div class="kb-fld"><h5>Határidő</h5><div class="kb-due"><input type="date" data-due value="' + esc(c.due) + '"><label class="kb-chk"><input type="checkbox" data-done' + (c.done ? ' checked' : '') + '> Kész</label>' + (c.due ? '<span class="kb-b due ' + dueCls(c) + '">' + (dueCls(c) === 'late' ? 'lejárt' : dueCls(c) === 'soon' ? 'hamarosan' : dueCls(c) === 'done' ? 'kész' : '') + '</span>' : '') + '</div></div></div>' +
			'<div class="kb-fld"><h5>Címkék</h5><div class="kb-lbsel">' + LABELS.map(l => '<button type="button" class="kb-lbb ' + esc(l.k) + (c.lb.includes(l.k) ? ' on' : '') + '" data-lb="' + esc(l.k) + '">' + esc(l.n) + '</button>').join('') + '</div></div>' +
			'<div class="kb-fld"><h5>Kapcsolódik</h5><div class="kb-links">' +
				(c.deal ? '<span class="kb-link">' + I.deal + '<a href="#deals" data-odeal>' + esc(d ? d.t : 'Deal #' + c.deal) + '</a>' + (d && d.st ? '<small>' + esc(d.st) + '</small>' : '') + '<button type="button" class="kb-tb sm" data-undeal title="Leválasztás">' + I.x + '</button></span>' : '<button type="button" class="kb-tb" data-adeal>' + I.deal + '<span>Deal csatolása</span></button>') +
				(c.th ? '<span class="kb-link">' + I.mail + '<a href="#mail" data-oth>' + esc(th ? th.t : 'Levélszál') + '</a>' + (th && th.cn ? '<small>' + esc(th.cn) + '</small>' : '') + '<button type="button" class="kb-tb sm" data-unth title="Leválasztás">' + I.x + '</button></span>' : '') +
				'</div><div data-dpick hidden></div></div>' +
			'<div class="kb-fld"><h5>Leírás</h5><textarea class="kb-desc" data-desc rows="' + Math.min(12, Math.max(3, (c.descr.match(/\n/g) || []).length + 2)) + '" placeholder="Részletek, teendők, linkek…">' + esc(c.descr) + '</textarea><div class="kb-dsave" hidden><button type="button" class="cpx-btn" data-dsave>Mentés</button><button type="button" class="kb-tb" data-dcancel>Mégse</button></div></div>' +
			'<div class="kb-fld"><h5>Ellenőrzőlista' + (cl.length ? ' <small>' + pct + '%</small>' : '') + '</h5>' + (cl.length ? '<div class="kb-prog"><span style="width:' + pct + '%"></span></div>' : '') +
				'<ul class="kb-cl">' + cl.map((it, i) => '<li class="' + (it.d ? 'd' : '') + '"><label><input type="checkbox" data-cli="' + i + '"' + (it.d ? ' checked' : '') + '><span>' + esc(it.t) + '</span></label><button type="button" class="kb-tb sm" data-cldel="' + i + '" title="Törlés">' + I.x + '</button></li>').join('') + '</ul>' +
				'<div class="kb-cladd"><input type="text" data-clnew placeholder="Új elem… (Enter)" maxlength="200"></div></div>' +
			'<div class="kb-fld"><h5>Hozzászólások és változásnapló <label class="kb-chk kb-actt"><input type="checkbox" data-acts' + (S.hideActs ? '' : ' checked') + '> változások</label></h5><div class="kb-cmp"><textarea rows="2" data-com placeholder="Írj hozzászólást – @ jellel megemlítheted a kollégáidat. (Ctrl+Enter: küldés)"></textarea><div class="kb-mpop" hidden></div><button type="button" class="cpx-btn" data-csend>Küldés</button></div>' +
				'<ul class="kb-coms">' + feed(x).map(it => it.a ? (S.hideActs ? '' : '<li class="kb-act"><span class="kb-dot"></span><div><b>' + esc(it.a.author || '–') + '</b> ' + esc(it.a.text) + ' <small>' + esc(ago(it.a.created_at)) + '</small></div></li>') : (m => '<li><span class="kb-av" style="background:' + avc(m.author) + '">' + esc(ini(m.author)) + '</span><div><b>' + esc(m.author || '–') + '</b> <small>' + esc(ago(m.created_at)) + '</small>' + (m.author_uid === ME || K.admin ? '<button type="button" class="kb-tb sm" data-cdel="' + m.id + '" title="Törlés">' + I.x + '</button>' : '') + '<p>' + nl2br(m.body) + '</p></div></li>')(it.m)).join('') + '</ul></div>' +
			'<div class="kb-shf"><small class="cpx-muted">Létrehozta: ' + esc(c.by || '–') + ' · ' + esc(ago(c.at)) + '</small><span class="crm-bgap"></span>' +
				'<button type="button" class="cpx-btn ghost' + (c.arch ? '' : ' bad') + '" data-arch>' + (c.arch ? 'Visszaállítás' : 'Archiválás') + '</button></div></div>';
		const save = async p => { try { const r = await post('cp_kb_card_save', Object.assign({ id: c.id }, p)); if (sh.isConnected) drawCard(sh, r); changed(sh); return r; } catch (e) { toast(e.message, true); return null; } };
		const ti = sh.querySelector('[data-title]');
		const fit = () => { ti.style.height = 'auto'; ti.style.height = ti.scrollHeight + 'px'; };
		fit(); ti.addEventListener('input', fit);
		ti.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); ti.blur(); } });
		ti.addEventListener('blur', () => { const v = ti.value.trim(); if (v && v !== c.title) save({ title: v }); else ti.value = c.title; });
		const ls = sh.querySelector('[data-list]');
		if (ls) ls.addEventListener('change', () => save({ list: ls.value }));
		sh.querySelectorAll('[data-as]').forEach(b => b.addEventListener('click', () => { const id = Number(b.dataset.as); const a = c.as.includes(id) ? c.as.filter(z => z !== id) : c.as.concat([id]); save({ as: a.join(',') }); }));
		sh.querySelectorAll('[data-lb]').forEach(b => b.addEventListener('click', () => { const k = b.dataset.lb; const a = c.lb.includes(k) ? c.lb.filter(z => z !== k) : c.lb.concat([k]); save({ lb: a.join(',') }); }));
		sh.querySelector('[data-due]').addEventListener('change', e => save({ due: e.target.value }));
		sh.querySelector('[data-done]').addEventListener('change', e => save({ done: e.target.checked ? 1 : 0 }));
		const ds = sh.querySelector('[data-desc]'), dbar = sh.querySelector('.kb-dsave');
		ds.addEventListener('input', () => { dbar.hidden = ds.value === c.descr; });
		sh.querySelector('[data-dsave]').addEventListener('click', () => save({ descr: ds.value }));
		sh.querySelector('[data-dcancel]').addEventListener('click', () => { ds.value = c.descr; dbar.hidden = true; });
		const setCl = l => save({ checklist: JSON.stringify(l) });
		sh.querySelectorAll('[data-cli]').forEach(b => b.addEventListener('change', () => { const l = cl.map(z => Object.assign({}, z)); l[Number(b.dataset.cli)].d = b.checked ? 1 : 0; setCl(l); }));
		sh.querySelectorAll('[data-cldel]').forEach(b => b.addEventListener('click', () => { const l = cl.slice(); l.splice(Number(b.dataset.cldel), 1); setCl(l); }));
		const cn = sh.querySelector('[data-clnew]');
		cn.addEventListener('keydown', async e => { if (e.key === 'Enter' && cn.value.trim()) { e.preventDefault(); const r = await setCl(cl.concat([{ t: cn.value.trim(), d: 0 }])); if (r) { const n2 = document.querySelector('.kb-sheet [data-clnew]'); if (n2) n2.focus(); } } });
		const od = sh.querySelector('[data-odeal]');
		if (od) od.addEventListener('click', e => { e.preventDefault(); openDeal(c.deal); });
		const ot = sh.querySelector('[data-oth]');
		if (ot) ot.addEventListener('click', e => { e.preventDefault(); closeSheet(); openThread(c.th); });
		const ud = sh.querySelector('[data-undeal]');
		if (ud) ud.addEventListener('click', () => save({ deal: 0 }));
		const ut = sh.querySelector('[data-unth]');
		if (ut) ut.addEventListener('click', () => save({ th: 0 }));
		const ad = sh.querySelector('[data-adeal]');
		if (ad) ad.addEventListener('click', () => { const h = sh.querySelector('[data-dpick]'); h.hidden = false; dealPicker(h, dx => save({ deal: dx.id })); h.querySelector('input').focus(); });
		sh.querySelector('[data-acts]').addEventListener('change', e => { S.hideActs = !e.target.checked; try { localStorage.setItem('cpkb_hideacts', S.hideActs ? '1' : '0'); } catch (er) { /* privát mód */ } drawCard(sh, x); });
		const ta = sh.querySelector('[data-com]');
		mention(ta, sh.querySelector('.kb-mpop'));
		const send = async () => { const b = ta.value.trim(); if (!b) return; ta.disabled = true; try { const r = await post('cp_kb_comment', { card: c.id, body: b }); if (sh.isConnected) drawCard(sh, r); changed(sh); } catch (e) { toast(e.message, true); ta.disabled = false; } };
		sh.querySelector('[data-csend]').addEventListener('click', send);
		ta.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); } });
		sh.querySelectorAll('[data-cdel]').forEach(b => b.addEventListener('click', async () => { if (!window.confirm('Törlöd a hozzászólást?')) return; try { const r = await post('cp_kb_comment', { del: b.dataset.cdel }); drawCard(sh, r); changed(sh); } catch (e) { toast(e.message, true); } }));
		sh.querySelector('[data-arch]').addEventListener('click', async () => { if (!c.arch && !window.confirm('Archiválod a kártyát? (Az Archív nézetből visszaállítható.)')) return; const r = await save({ arch: c.arch ? 0 : 1 }); if (r && !c.arch) { closeSheet(); toast('Kártya archiválva.'); } });
	}
	/* hozzászólások és változások időrendben, a legfrissebb felül */
	const feed = x => (x.comments || []).map(m => ({ at: m.created_at, id: m.id, m: m })).concat((x.acts || []).map(a => ({ at: a.created_at, id: a.id, a: a }))).sort((p, q) => (p.at < q.at ? 1 : p.at > q.at ? -1 : 0));
	function closeSheet() { const m = document.getElementById('cpx-modal'); if (m) { m.hidden = true; m.innerHTML = ''; } }

	/* @ megemlítés a hozzászólásban */
	function mention(ta, pop) {
		const upd = () => {
			const v = ta.value.slice(0, ta.selectionStart), m = v.match(/@([\wáéíóöőúüű]*)$/i);
			if (!m || !PEOPLE.length) { pop.hidden = true; return; }
			const q = fold(m[1]), hits = PEOPLE.filter(p => fold(p.n).includes(q)).slice(0, 6);
			if (!hits.length) { pop.hidden = true; return; }
			pop.innerHTML = hits.map(p => '<button type="button" data-p="' + p.id + '">' + av(p.id) + esc(p.n) + '</button>').join('');
			pop.hidden = false;
			pop.querySelectorAll('[data-p]').forEach(b => b.addEventListener('mousedown', e => {
				e.preventDefault();
				const n = pname(Number(b.dataset.p)), pos = ta.selectionStart, before = ta.value.slice(0, pos).replace(/@([\wáéíóöőúüű]*)$/i, '@' + n + ' ');
				ta.value = before + ta.value.slice(pos); ta.selectionStart = ta.selectionEnd = before.length; pop.hidden = true; ta.focus();
			}));
		};
		ta.addEventListener('input', upd);
		ta.addEventListener('click', upd);
		ta.addEventListener('blur', () => setTimeout(() => { pop.hidden = true; }, 150));
	}

	function dealPicker(host, onPick) {
		host.innerHTML = '<div class="kb-dp"><input type="search" placeholder="Deal keresése (név, e-mail, #szám)" aria-label="Deal"><div class="kb-dpl" hidden></div></div>';
		const inp = host.querySelector('input'), list = host.querySelector('.kb-dpl');
		let t = null;
		inp.addEventListener('input', () => {
			clearTimeout(t);
			t = setTimeout(async () => {
				const q = inp.value.trim();
				if (q.length < 2) { list.hidden = true; return; }
				try {
					const r = await post('cp_mail_deals', { q: q });
					list.innerHTML = r.rows.length ? r.rows.map(x => '<button type="button" data-id="' + x.id + '"><b>' + esc(x.title) + '</b><small>' + esc([x.cn, x.ce, x.ev].filter(Boolean).join(' · ')) + '</small></button>').join('') : '<div class="kb-none">Nincs találat.</div>';
					list.hidden = false;
					list.querySelectorAll('[data-id]').forEach(b => b.addEventListener('click', () => { list.hidden = true; onPick(r.rows.find(y => String(y.id) === b.dataset.id)); }));
				} catch (e) { list.innerHTML = '<div class="kb-none">' + esc(e.message) + '</div>'; list.hidden = false; }
			}, 250);
		});
	}

	/* ------------------------------------------------------------ más fülekről: gyors felvétel és a kapcsolt kártyák */
	let TARGETS = null;
	async function quickForm(host, o) {
		o = o || {};
		host.hidden = false;
		host.innerHTML = '<div class="kb-quick"><div class="cpx-empty">Betöltés…</div></div>';
		try { if (!TARGETS) TARGETS = (await post('cp_kb_targets', {})).boards; } catch (e) { host.innerHTML = '<div class="kb-quick"><p class="cpx-empty err">' + esc(e.message) + '</p></div>'; return; }
		let last = {};
		try { last = JSON.parse(localStorage.getItem('cpkb_quick') || '{}') || {}; } catch (e) { /* privát mód */ }
		const bsel = TARGETS.some(b => b.id === last.b) ? last.b : (TARGETS[0] || {}).id;
		host.innerHTML = '<div class="kb-quick"><div class="kb-qh"><b>' + I.check + ' Teendő felvétele a Feladatokba</b><button type="button" class="kb-tb sm" data-qx title="Mégse">' + I.x + '</button></div>' +
			'<input type="text" data-qt maxlength="255" value="' + esc(o.title || '') + '" placeholder="Mi a teendő?">' +
			'<div class="kb-qr"><label>Tábla<select data-qb>' + TARGETS.map(b => '<option value="' + b.id + '"' + (b.id === bsel ? ' selected' : '') + '>' + esc(b.n) + (b.k === 'personal' ? ' (saját)' : '') + '</option>').join('') + '</select></label>' +
			'<label>Lista<select data-ql></select></label>' +
			'<label>Határidő<input type="date" data-qd></label></div>' +
			'<div class="kb-qp"><span>Kinek:</span>' + PEOPLE.map(p => '<button type="button" class="kb-pp' + (p.id === ME ? ' on' : '') + '" data-qp="' + p.id + '">' + av(p.id) + '<span>' + esc(p.n) + (p.id === ME ? ' (én)' : '') + '</span></button>').join('') + '</div><p class="kb-qhint" hidden></p>' +
			'<div class="kb-qf"><small class="cpx-muted">' + [o.th ? 'a levélszálhoz' : '', o.deal ? 'a deal-hez' : ''].filter(Boolean).join(' és ') + (o.th || o.deal ? ' kapcsolva' : '') + '</small><span class="crm-bgap"></span><button type="button" class="cpx-btn" data-qs>Felvétel</button></div></div>';
		const bs = host.querySelector('[data-qb]'), lsel = host.querySelector('[data-ql]');
		const fill = () => { const b = TARGETS.find(z => z.id === Number(bs.value)) || { lists: [] }; lsel.innerHTML = b.lists.map(l => '<option value="' + l.id + '"' + (l.id === last.l ? ' selected' : '') + '>' + esc(l.n) + '</option>').join(''); };
		fill(); bs.addEventListener('change', () => { fill(); guard(); });
		/* felelősök: több is lehet; kollégának szóló teendő nem kerülhet saját (privát) táblára – azt csak te látod */
		const sel = new Set(ME ? [ME] : []), hint = host.querySelector('.kb-qhint');
		const team = () => (TARGETS.find(b => b.k === 'team' && /kiosztott/i.test(b.n)) || TARGETS.find(b => b.k === 'team') || {}).id;
		const guard = () => {
			const b = TARGETS.find(z => z.id === Number(bs.value)) || {};
			const others = Array.from(sel).some(u => u !== ME);
			if (b.k === 'personal' && others && team()) { bs.value = String(team()); fill(); hint.textContent = 'Kollégának szóló teendő a közös táblára kerül – a saját táblát csak te látod.'; hint.hidden = false; }
			else hint.hidden = true;
		};
		host.querySelectorAll('[data-qp]').forEach(b => b.addEventListener('click', () => { const id = Number(b.dataset.qp); if (sel.has(id)) sel.delete(id); else sel.add(id); b.classList.toggle('on', sel.has(id)); guard(); }));
		const tt = host.querySelector('[data-qt]');
		tt.focus(); tt.select();
		host.querySelector('[data-qx]').addEventListener('click', () => { host.hidden = true; host.innerHTML = ''; });
		const go = async () => {
			const t = tt.value.trim();
			if (!t) { tt.focus(); return; }
			const btn = host.querySelector('[data-qs]'); btn.disabled = true;
			try {
				const p = { board: bs.value, list: lsel.value, title: t, top: 1 };
				if (sel.size) p.as = Array.from(sel).join(',');
				if (host.querySelector('[data-qd]').value) p.due = host.querySelector('[data-qd]').value;
				if (o.th) p.th = o.th;
				if (o.deal) p.deal = o.deal;
				const r = await post('cp_kb_card_save', p);
				try { localStorage.setItem('cpkb_quick', JSON.stringify({ b: Number(bs.value), l: Number(lsel.value) })); } catch (e) { /* privát mód */ }
				host.hidden = true; host.innerHTML = '';
				toast('Teendő felvéve: ' + r.card.bn + '.');
				S.data = null;
				if (o.onDone) o.onDone(r.card);
			} catch (e) { toast(e.message, true); btn.disabled = false; }
		};
		host.querySelector('[data-qs]').addEventListener('click', go);
		tt.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); go(); } if (e.key === 'Escape') { e.stopPropagation(); host.hidden = true; host.innerHTML = ''; } });
	}

	async function linked(host, o) {
		if (!host) return;
		let r;
		try { r = await post('cp_kb_linked', o); } catch (e) { host.innerHTML = ''; return; }
		if (!host.isConnected) return;
		host.innerHTML = r.cards.length ? '<div class="kb-lnk"><span class="kb-lnkh">' + I.check + 'Teendők</span>' + r.cards.map(c => '<button type="button" class="kb-chip' + (c.done ? ' isdone' : '') + '" data-kc="' + c.id + '" title="' + esc(c.bn + ' › ' + c.ln) + '"><span>' + esc(short(c.title, 48)) + '</span><small>' + esc(c.ln) + (c.due ? ' · ' + esc(hd(c.due)) : '') + '</small>' + c.as.slice(0, 2).map(id => av(id, 'xs')).join('') + '</button>').join('') + '</div>' : '';
		host.querySelectorAll('[data-kc]').forEach(b => b.addEventListener('click', () => openCard(Number(b.dataset.kc), () => linked(host, o))));
	}

	function status(s) { s.textContent = S.err || ''; s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function refresh() { S.boards = null; S.data = null; TARGETS = null; if (S.main && S.main.isConnected) await view(S.main); }

	/* mélylink (értesítésből, Pumble-ből): #feladatok:123 = a kártya, #feladatok:mine = Nekem nézet */
	function deep(arg) {
		if (arg === 'mine') { S.cur = 'mine'; if (S.main && S.main.isConnected) view(S.main); return; }
		const id = Number(arg);
		if (id > 0) openCard(id);
	}

	window.CPKanban = { view, status, refresh, quickForm, linked, openCard, deep };
})();
