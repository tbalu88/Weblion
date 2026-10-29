/* Crazy panel – Levelezés fül (v1.33): postafiók-nézet, levélíró, sablonszerkesztő.
 * Levelet még nem küld és a Salesmate-hez nem nyúl: a sablonok a panel saját táblájában vannak. */
(function () {
	'use strict';
	const C = window.CPX || {};
	const M = C.mail || {};
	const VARS = M.vars || {};
	const BOXES = M.boxes || [];

	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const fold = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
	const S = { view: 'inbox', folder: 'inbox', box: '', tpls: null, cur: null, main: null, err: '', loading: false };
	try { const v = localStorage.getItem('cpmail_view'); if (['inbox', 'compose', 'tpl'].includes(v)) S.view = v; } catch (e) { /* privát mód */ }

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
	function status(s) { s.textContent = M.connected ? '' : 'Levelezés: a postafiókok még nincsenek bekötve'; s.className = 'cpx-status'; }

	/* ------------------------------------------------------------ szövegszerkesztő (közös a levélíróhoz és a sablonokhoz) */
	const ICON = {
		bold: '<b>B</b>', italic: '<i>I</i>', underline: '<u>U</u>', strike: '<s>S</s>',
		h2: 'H', p: '¶', ul: '<svg viewBox="0 0 24 24"><path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/></svg>',
		ol: '<svg viewBox="0 0 24 24"><path d="M10 6h10M10 12h10M10 18h10M4 5h1v4M4 9h2M4 14h2l-2 3h2"/></svg>',
		quote: '❝', link: '<svg viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>',
		img: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/></svg>',
		table: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M10 4v16"/></svg>',
		attach: '<svg viewBox="0 0 24 24"><path d="M20 11.5l-8.5 8.5a5 5 0 0 1-7-7L13 4.5a3.5 3.5 0 0 1 5 5L9.5 18a2 2 0 0 1-3-3L14 7.5"/></svg>',
		sign: '<svg viewBox="0 0 24 24"><path d="M3 17c3-6 5-9 6-8s-2 7 0 7 3-5 5-5-1 5 1 5 3-2 6-3"/></svg>',
		clear: '<svg viewBox="0 0 24 24"><path d="M6 5h12M12 5l-4 14M5 19h6M16 14l5 5M21 14l-5 5"/></svg>',
		code: '<svg viewBox="0 0 24 24"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5"/></svg>',
		undo: '<svg viewBox="0 0 24 24"><path d="M9 14L4 9l5-5M4 9h10a6 6 0 0 1 0 12h-3"/></svg>',
		redo: '<svg viewBox="0 0 24 24"><path d="M15 14l5-5-5-5M20 9H10a6 6 0 0 0 0 12h3"/></svg>'
	};
	const TOOLS = [['bold', 'Félkövér'], ['italic', 'Dőlt'], ['underline', 'Aláhúzott'], ['strike', 'Áthúzott'], ['|'], ['h2', 'Címsor'], ['p', 'Normál bekezdés'], ['ul', 'Felsorolás'], ['ol', 'Számozott lista'], ['quote', 'Idézet'], ['|'],
		['link', 'Link beszúrása'], ['img', 'Kép beszúrása (webcímről)'], ['table', 'Táblázat beszúrása'], ['var', 'Változó beszúrása'], ['attach', 'Melléklet (a levelezés bekötése után)'], ['sign', 'Aláírás beszúrása'], ['clear', 'Formázás törlése'], ['|R'], ['code', 'HTML-nézet'], ['undo', 'Visszavonás'], ['redo', 'Újra']];
	function editor(host, html) {
		const vopts = Object.keys(VARS).map(k => '<button type="button" data-vk="' + esc(k) + '"><b>{{' + esc(k) + '}}</b><small>' + esc(VARS[k][0]) + '</small></button>').join('');
		host.innerHTML = '<div class="ml-ed"><div class="ml-area" contenteditable="true" spellcheck="true" data-ph="Kezdj el gépelni…"></div><textarea class="ml-code" hidden spellcheck="false"></textarea>' +
			'<div class="ml-tb">' + TOOLS.map(t => t[0] === '|' ? '<span class="ml-sep"></span>' : t[0] === '|R' ? '<span class="ml-gap"></span>' : '<button type="button" data-cmd="' + t[0] + '" title="' + esc(t[1]) + '" aria-label="' + esc(t[1]) + '"' + (t[0] === 'attach' ? ' disabled' : '') + '>' + (t[0] === 'var' ? '#' : ICON[t[0]]) + '</button>').join('') + '</div>' +
			'<div class="ml-pop ml-vpop" hidden>' + vopts + '</div></div>';
		const area = host.querySelector('.ml-area'), code = host.querySelector('.ml-code'), vpop = host.querySelector('.ml-vpop');
		area.innerHTML = html || '';
		let range = null;
		const keep = () => { const s = window.getSelection(); if (s.rangeCount && area.contains(s.anchorNode)) range = s.getRangeAt(0).cloneRange(); };
		area.addEventListener('keyup', keep); area.addEventListener('mouseup', keep); area.addEventListener('blur', keep);
		const restore = () => { area.focus(); if (range) { const s = window.getSelection(); s.removeAllRanges(); s.addRange(range); } };
		const exec = (c, v) => { restore(); document.execCommand(c, false, v == null ? null : v); keep(); };
		host.querySelectorAll('[data-cmd]').forEach(b => b.addEventListener('mousedown', e => e.preventDefault()));
		host.querySelectorAll('[data-cmd]').forEach(b => b.addEventListener('click', () => {
			const c = b.dataset.cmd;
			if (c === 'code') { const on = code.hidden; if (on) { code.value = area.innerHTML; } else { area.innerHTML = code.value; } code.hidden = !on; area.hidden = on; b.classList.toggle('on', on); return; }
			if (!code.hidden) return;
			if (c === 'var') { vpop.hidden = !vpop.hidden; return; }
			const M2 = { bold: 'bold', italic: 'italic', underline: 'underline', strike: 'strikeThrough', ul: 'insertUnorderedList', ol: 'insertOrderedList', clear: 'removeFormat', undo: 'undo', redo: 'redo' };
			if (M2[c]) return exec(M2[c]);
			if (c === 'h2' || c === 'p') return exec('formatBlock', c);
			if (c === 'quote') return exec('formatBlock', 'blockquote');
			if (c === 'link') { const u = window.prompt('A link címe (https://…):', 'https://'); if (u && /^(https?:|mailto:|tel:)/i.test(u.trim())) exec('createLink', u.trim()); return; }
			if (c === 'img') { const u = window.prompt('A kép webcíme (https://…):', 'https://'); if (u && /^https:\/\//i.test(u.trim())) exec('insertImage', u.trim()); return; }
			if (c === 'table') return exec('insertHTML', '<table style="border-collapse:collapse;width:100%"><tbody><tr><td style="border:1px solid #ccc;padding:6px">&nbsp;</td><td style="border:1px solid #ccc;padding:6px">&nbsp;</td></tr><tr><td style="border:1px solid #ccc;padding:6px">&nbsp;</td><td style="border:1px solid #ccc;padding:6px">&nbsp;</td></tr></tbody></table><p><br></p>');
			if (c === 'sign') {
				let sig = '';
				try { sig = localStorage.getItem('cpmail_sig') || ''; } catch (e) { /* privát mód */ }
				if (!sig) { sig = window.prompt('Az aláírásod (egyszer kell megadni, a gép megjegyzi):', 'Üdvözlettel:\n{{sajat.nev}}\nEventCrazy'); if (!sig) return; try { localStorage.setItem('cpmail_sig', sig); } catch (e) { /* privát mód */ } }
				return exec('insertHTML', '<p>' + esc(sig).replace(/\n/g, '<br>') + '</p>');
			}
		}));
		vpop.querySelectorAll('[data-vk]').forEach(b => b.addEventListener('mousedown', e => e.preventDefault()));
		vpop.querySelectorAll('[data-vk]').forEach(b => b.addEventListener('click', () => { exec('insertText', '{{' + b.dataset.vk + '}}'); vpop.hidden = true; }));
		document.addEventListener('click', e => { if (!vpop.hidden && e.target.isConnected && !e.target.closest('.ml-vpop') && !e.target.closest('[data-cmd="var"]')) vpop.hidden = true; });
		return {
			get: () => (code.hidden ? area.innerHTML : code.value),
			set: h => { area.innerHTML = h || ''; code.value = area.innerHTML; },
			insert: text => exec('insertText', text),
			isEmpty: () => !(code.hidden ? area.innerText : code.value.replace(/<[^>]+>/g, '')).trim()
		};
	}

	/* „Sablon beszúrása” lenyíló: mappák (kategóriák) → sablonok, keresővel */
	function tplMenu(btn, tpls, onPick) {
		const pop = document.createElement('div');
		pop.className = 'ml-pop ml-tpop';
		btn.after(pop);
		let cat = null;
		const draw = () => {
			const q = fold(pop.querySelector('input') ? pop.querySelector('input').value : '');
			const cats = Array.from(new Set(tpls.map(t => t.category || 'Egyéb'))).sort((a, b) => a.localeCompare(b, 'hu'));
			const list = tpls.filter(t => (q ? fold(t.name + ' ' + t.subject).includes(q) : (cat ? (t.category || 'Egyéb') === cat : false)));
			pop.innerHTML = '<div class="ml-tpop-h">' + (cat && !q ? '<button type="button" data-back>‹ Mappák</button> \\ ' + esc(cat) : 'Mappák') + '</div><input type="search" placeholder="Keresés a sablonok között">' +
				'<div class="ml-tpop-l">' + (!q && !cat ? (cats.length ? cats.map(c => '<button type="button" data-cat="' + esc(c) + '">📁 ' + esc(c) + ' <small>' + tpls.filter(t => (t.category || 'Egyéb') === c).length + '</small></button>').join('') : '<div class="ml-dp-n">Még nincs sablon – a Sablonok fülön hozhatsz létre.</div>') :
				(list.length ? list.map(t => '<button type="button" data-tid="' + t.id + '">' + esc(t.name) + '</button>').join('') : '<div class="ml-dp-n">Nincs találat.</div>')) + '</div>';
			const inp = pop.querySelector('input'); inp.value = q ? inp.value : ''; 
			inp.addEventListener('input', () => { const v = inp.value; draw(); const i2 = pop.querySelector('input'); i2.value = v; i2.focus(); });
			pop.querySelectorAll('[data-cat]').forEach(b => b.addEventListener('click', () => { cat = b.dataset.cat; draw(); }));
			const bk = pop.querySelector('[data-back]'); if (bk) bk.addEventListener('click', () => { cat = null; draw(); });
			pop.querySelectorAll('[data-tid]').forEach(b => b.addEventListener('click', () => { pop.remove(); onPick(tpls.find(t => String(t.id) === b.dataset.tid)); }));
		};
		draw();
		pop.querySelector('input').focus();
		setTimeout(() => document.addEventListener('click', function off(e) { if (!pop.isConnected) { document.removeEventListener('click', off); return; } if (e.target.isConnected && !pop.contains(e.target) && e.target !== btn) { pop.remove(); document.removeEventListener('click', off); } }), 0);
	}

	/* ------------------------------------------------------------ fő nézet */
	function view(main) {
		S.main = main;
		const seg = [['inbox', 'Postafiók'], ['compose', 'Új levél'], ['tpl', 'Sablonok']].map(x => '<button type="button" data-v="' + x[0] + '" class="' + (S.view === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('');
		main.innerHTML = '<div class="ml">' + (M.connected ? '' : '<p class="ml-note">A levelezés még előkészítés alatt van: a postafiókok bekötéséig a leveleket továbbra is a Salesmate Team Inboxban kezeld. Itt már elkészítheted és szerkesztheted a sablonokat.</p>') +
			'<div class="ml-head"><div class="crm-seg ml-seg">' + seg + '</div></div><div class="ml-body"></div></div>';
		main.querySelectorAll('[data-v]').forEach(b => b.addEventListener('click', () => { S.view = b.dataset.v; try { localStorage.setItem('cpmail_view', S.view); } catch (e) { /* privát mód */ } view(main); }));
		const body = main.querySelector('.ml-body');
		if (S.view === 'inbox') drawInbox(body);
		else if (S.view === 'compose') drawCompose(body, null);
		else drawTpls(body);
	}

	/* ------------------------------------------------------------ postafiók (üres, amíg nincs bekötve) */
	function drawInbox(box) {
		const F = [['inbox', 'Beérkezett'], ['follow', 'Követett'], ['outbox', 'Kimenő'], ['sent', 'Elküldött'], ['draft', 'Piszkozatok'], ['archive', 'Archív'], ['trash', 'Kuka'], ['spam', 'Spam']];
		box.innerHTML = '<div class="ml-inbox">' +
			'<aside class="ml-side"><button type="button" class="cpx-btn ml-new" data-compose>Új levél</button>' +
			'<select class="ml-box" aria-label="Postafiók"><option value="">Minden postafiók</option>' + BOXES.map(b => '<option value="' + esc(b.id) + '"' + (S.box === b.id ? ' selected' : '') + '>' + esc(b.n) + '</option>').join('') + '</select>' +
			'<nav class="ml-folders">' + F.map(f => '<button type="button" data-f="' + f[0] + '" class="' + (S.folder === f[0] ? 'on' : '') + '"><span>' + f[1] + '</span><b>0</b></button>').join('') + '</nav></aside>' +
			'<section class="ml-list"><input type="search" class="ml-q" placeholder="Keresés a levelek között" disabled>' +
			'<div class="ml-tabs"><button type="button" class="on">Nyitott</button><button type="button">Kiosztatlan</button><button type="button">Mind</button></div>' +
			'<div class="ml-empty"><b>Még nincsenek levelek</b><span>A postafiókok bekötése után itt jelennek meg a beérkező levelek, deal-ekhez és kollégákhoz rendelve.</span></div></section>' +
			'<section class="ml-read"><div class="ml-rtool"><button type="button" disabled>✓ Lezárás</button><button type="button" disabled>🏷 Címke</button><button type="button" disabled>⋯ Több</button><span class="crm-bgap"></span><button type="button" disabled>Felelős ▾</button><label class="ml-track"><input type="checkbox" disabled> Követés</label></div>' +
			'<div class="ml-empty"><b>Válassz egy levelet</b><span>Itt jelenik meg a levélváltás, fölötte a hozzá tartozó deal kártyája és a kollégák jegyzetei; innen lehet válaszolni sablonnal.</span></div></section></div>';
		box.querySelectorAll('[data-f]').forEach(b => b.addEventListener('click', () => { S.folder = b.dataset.f; drawInbox(box); }));
		box.querySelector('.ml-box').addEventListener('change', e => { S.box = e.target.value; });
		box.querySelector('[data-compose]').addEventListener('click', () => { S.view = 'compose'; view(S.main); });
	}

	/* ------------------------------------------------------------ deal-választó (címzett és változók) */
	function dealPicker(host, onPick) {
		host.innerHTML = '<div class="ml-dp"><input type="search" placeholder="Deal keresése (név, e-mail, #szám) – kitölti a címzettet és a változókat" aria-label="Deal"><div class="ml-dp-l" hidden></div></div>';
		const inp = host.querySelector('input'), list = host.querySelector('.ml-dp-l');
		let t = null;
		inp.addEventListener('input', () => {
			clearTimeout(t);
			t = setTimeout(async () => {
				const q = inp.value.trim();
				if (q.length < 2) { list.hidden = true; return; }
				try {
					const r = await post('cp_mail_deals', { q: q });
					list.innerHTML = r.rows.length ? r.rows.map(x => '<button type="button" data-id="' + x.id + '"><b>' + esc(x.title) + '</b><small>' + esc([x.cn, x.ce, x.ev].filter(Boolean).join(' · ')) + '</small></button>').join('') : '<div class="ml-dp-n">Nincs találat.</div>';
					list.hidden = false;
					list.querySelectorAll('[data-id]').forEach(b => b.addEventListener('click', () => { const x = r.rows.find(y => String(y.id) === b.dataset.id); inp.value = x.title; list.hidden = true; onPick(x); }));
				} catch (e) { list.innerHTML = '<div class="ml-dp-n">' + esc(e.message) + '</div>'; list.hidden = false; }
			}, 250);
		});
	}

	async function loadTpls() {
		if (S.tpls) return S.tpls;
		const r = await post('cp_mail_tpls', {});
		S.tpls = r.tpls || [];
		return S.tpls;
	}

	/* ------------------------------------------------------------ levélíró */
	function chips(host, init) {
		const list = (init || []).slice();
		const draw = () => {
			host.innerHTML = list.map((x, i) => '<span class="ml-chip"><i>' + esc((x[0] || '?').toUpperCase()) + '</i>' + esc(x) + '<button type="button" data-rm="' + i + '" aria-label="Eltávolítás">×</button></span>').join('') + '<input type="text" placeholder="' + (list.length ? '' : 'nev@example.com') + '">';
			const inp = host.querySelector('input');
			const add = () => { inp.value.split(/[,;\s]+/).map(x => x.trim()).filter(x => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(x)).forEach(x => { if (!list.includes(x)) list.push(x); }); inp.value = ''; draw(); host.querySelector('input').focus(); };
			inp.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ',' || e.key === ';') { e.preventDefault(); add(); } if (e.key === 'Backspace' && !inp.value && list.length) { list.pop(); draw(); host.querySelector('input').focus(); } });
			inp.addEventListener('blur', () => { if (inp.value.trim()) add(); });
			host.querySelectorAll('[data-rm]').forEach(b => b.addEventListener('click', () => { list.splice(Number(b.dataset.rm), 1); draw(); }));
		};
		draw();
		return { get: () => list.slice(), add: x => { if (x && !list.includes(x)) { list.push(x); draw(); } } };
	}
	async function drawCompose(box) {
		let tpls = [];
		try { tpls = await loadTpls(); } catch (e) { /* sablon nélkül is írható */ }
		let deal = null;
		const draft = (() => { try { return JSON.parse(localStorage.getItem('cpmail_draft') || 'null'); } catch (e) { return null; } })();
		box.innerHTML = '<div class="ml-compose">' +
			'<div class="ml-ctop"><span class="ml-rel"><button type="button" class="ml-tbtn" data-tplbtn>🔖 Sablon beszúrása ▾</button></span><div data-dp class="ml-grow"></div></div>' +
			'<div class="ml-row"><label>Feladó</label><select data-from>' + BOXES.map(b => '<option value="' + esc(b.id) + '">' + esc(b.n + (b.e ? ' (' + b.e + ')' : '')) + '</option>').join('') + '</select></div>' +
			'<div class="ml-row"><label>Címzett</label><div class="ml-chips" data-to></div><button type="button" class="ml-link" data-cc>Másolat / Rejtett</button></div>' +
			'<div class="ml-row" data-ccrow hidden><label>Másolat</label><div class="ml-chips" data-ccv></div></div>' +
			'<div class="ml-row" data-ccrow hidden><label>Rejtett</label><div class="ml-chips" data-bcc></div></div>' +
			'<div class="ml-row"><label>Tárgy</label><input type="text" data-subj value="' + esc(draft ? draft.subject : '') + '"></div>' +
			'<div data-ed></div>' +
			'<div class="ml-send"><button type="button" class="cpx-btn" disabled title="A postafiókok bekötése után">Küldés</button><button type="button" class="cpx-btn ghost" disabled title="A postafiókok bekötése után">Ütemezés</button><button type="button" class="cpx-btn ghost" disabled title="A postafiókok bekötése után">Emlékeztető</button>' +
			'<button type="button" class="cpx-btn ghost" data-prev>Előnézet</button><button type="button" class="cpx-btn ghost" data-save>Piszkozat mentése</button><button type="button" class="ml-link" data-clear>Új, üres levél</button>' +
			'<label class="ml-track"><input type="checkbox" checked disabled> Megnyitás követése</label></div>' +
			'<div class="ml-prev" hidden></div></div>';
		const q = sel => box.querySelector(sel);
		const ed = editor(q('[data-ed]'), draft ? draft.body : '');
		const to = chips(q('[data-to]'), draft ? draft.to : []), cc = chips(q('[data-ccv]'), []), bcc = chips(q('[data-bcc]'), []);
		dealPicker(q('[data-dp]'), x => { deal = x; if (x.ce) to.add(x.ce); });
		q('[data-cc]').addEventListener('click', () => box.querySelectorAll('[data-ccrow]').forEach(r => { r.hidden = !r.hidden; }));
		q('[data-tplbtn]').addEventListener('click', e => {
			e.stopPropagation();
			if (box.querySelector('.ml-tpop')) { box.querySelector('.ml-tpop').remove(); return; }
			tplMenu(e.currentTarget, tpls, t => {
				if (!ed.isEmpty() && !window.confirm('A sablon felülírja a levél jelenlegi szövegét. Mehet?')) return;
				if (t.subject) q('[data-subj]').value = t.subject;
				ed.set(t.body || '');
			});
		});
		q('[data-save]').addEventListener('click', () => {
			try { localStorage.setItem('cpmail_draft', JSON.stringify({ to: to.get(), subject: q('[data-subj]').value, body: ed.get() })); toast('Piszkozat elmentve ezen a gépen.'); } catch (e) { toast('A piszkozatot nem sikerült menteni.', true); }
		});
		q('[data-clear]').addEventListener('click', () => { if (!window.confirm('Törlöd a levél tartalmát?')) return; try { localStorage.removeItem('cpmail_draft'); } catch (e) { /* privát mód */ } drawCompose(box); });
		q('[data-prev]').addEventListener('click', async () => {
			const pv = q('.ml-prev');
			try {
				const r = await post('cp_mail_preview', { deal: deal ? deal.id : '', subject: q('[data-subj]').value, body: ed.get() });
				pv.innerHTML = '<div class="ml-prev-h"><b>Előnézet</b>' + (deal ? '<small>' + esc(deal.title) + ' adataival</small>' : '<small>mintaadatokkal – válassz deal-t a valódi adatokhoz</small>') + '</div><div class="ml-prev-s"><span>Tárgy:</span> ' + esc(r.subject) + '</div><div class="ml-prev-b">' + r.body + '</div>';
				pv.hidden = false; pv.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			} catch (e) { toast(e.message, true); }
		});
	}

	/* ------------------------------------------------------------ sablonok */
	async function drawTpls(box, selId) {
		box.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
		let tpls;
		try { S.tpls = null; tpls = await loadTpls(); } catch (e) { box.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		box.innerHTML = '<div class="ml-tpls"><aside class="ml-tl"><button type="button" class="cpx-btn" data-new>+ Új sablon</button><input type="search" class="ml-tq" placeholder="Keresés a sablonok között"><div class="ml-tll"></div></aside><section class="ml-te"></section></div>';
		const list = box.querySelector('.ml-tll'), q = box.querySelector('.ml-tq');
		const drawList = () => {
			const f = fold(q.value);
			const vis = tpls.filter(t => !f || fold(t.name + ' ' + t.category + ' ' + t.subject).includes(f));
			const cats = {};
			vis.forEach(t => { (cats[t.category || 'Egyéb'] = cats[t.category || 'Egyéb'] || []).push(t); });
			list.innerHTML = vis.length ? Object.keys(cats).sort((a, b) => a.localeCompare(b, 'hu')).map(c => '<div class="ml-tcat">' + esc(c) + '</div>' + cats[c].map(t => '<button type="button" data-id="' + t.id + '" class="' + (S.cur && S.cur.id === t.id ? 'on' : '') + '"><b>' + esc(t.name) + '</b><small>' + esc(t.subject || '(nincs tárgy)') + '</small></button>').join('')).join('') :
				'<div class="ml-empty sm"><b>' + (tpls.length ? 'Nincs találat' : 'Még nincs sablon') + '</b><span>' + (tpls.length ? '' : 'Hozd létre az elsőt az „+ Új sablon” gombbal.') + '</span></div>';
			list.querySelectorAll('[data-id]').forEach(b => b.addEventListener('click', () => { S.cur = tpls.find(t => String(t.id) === b.dataset.id); drawList(); edit(S.cur); }));
		};
		q.addEventListener('input', drawList);
		box.querySelector('[data-new]').addEventListener('click', () => { S.cur = { id: 0, name: '', category: '', subject: '', body: '' }; drawList(); edit(S.cur); });
		const cats = Array.from(new Set(tpls.map(t => t.category).filter(Boolean)));
		const te = box.querySelector('.ml-te');
		function edit(t) {
			if (!t) { te.innerHTML = '<div class="ml-empty"><b>Válassz egy sablont</b><span>vagy hozz létre újat. A változók (pl. {{kontakt.nev}}) küldéskor a deal adataival töltődnek ki.</span></div>'; return; }
			te.innerHTML = '<div class="crm-eg"><label class="crm-ef"><span>Sablon neve *</span><input type="text" data-n value="' + esc(t.name) + '"></label>' +
				'<label class="crm-ef"><span>Kategória</span><input type="text" data-c list="ml-cats" value="' + esc(t.category) + '" placeholder="pl. Ajánlat, Emlékeztető"><datalist id="ml-cats">' + cats.map(c => '<option value="' + esc(c) + '">').join('') + '</datalist></label></div>' +
				'<label class="crm-ef ml-subj"><span>Tárgy</span><input type="text" data-s value="' + esc(t.subject) + '"></label><div data-ed></div>' +
				'<div class="ml-vars"><span>Változók (kattints a beszúráshoz):</span>' + Object.keys(VARS).map(k => '<button type="button" data-vchip="' + esc(k) + '" title="' + esc(VARS[k][0] + ' – pl. ' + VARS[k][1]) + '">{{' + esc(k) + '}}</button>').join('') + '</div>' +
				'<div class="ml-row ml-prow"><label>Előnézet</label><div data-dp class="ml-grow"></div><button type="button" class="cpx-btn ghost" data-prev>Előnézet</button></div><div class="ml-prev" hidden></div>' +
				'<div class="crm-ebar"><button type="button" class="cpx-btn" data-save>Mentés</button>' + (t.id ? '<button type="button" class="cpx-btn ghost" data-dup>Másolat</button><button type="button" class="cpx-btn ghost bad" data-del>Törlés</button>' : '') +
				'<span class="crm-emsg">' + (t.updated_at ? 'Utoljára módosította: ' + esc(t.updated_by || '–') + ', ' + esc(t.updated_at) : '') + '</span></div>';
			const ed = editor(te.querySelector('[data-ed]'), t.body);
			te.querySelectorAll('[data-vchip]').forEach(b => { b.addEventListener('mousedown', e => e.preventDefault()); b.addEventListener('click', () => ed.insert('{{' + b.dataset.vchip + '}}')); });
			let deal = null;
			dealPicker(te.querySelector('[data-dp]'), x => { deal = x; });
			te.querySelector('[data-prev]').addEventListener('click', async () => {
				const pv = te.querySelector('.ml-prev');
				try {
					const r = await post('cp_mail_preview', { deal: deal ? deal.id : '', subject: te.querySelector('[data-s]').value, body: ed.get() });
					pv.innerHTML = '<div class="ml-prev-h"><b>Előnézet</b><small>' + (deal ? esc(deal.title) + ' adataival' : 'mintaadatokkal') + '</small></div><div class="ml-prev-s"><span>Tárgy:</span> ' + esc(r.subject) + '</div><div class="ml-prev-b">' + r.body + '</div>';
					pv.hidden = false;
				} catch (e) { toast(e.message, true); }
			});
			const saveAs = async id => {
				const name = te.querySelector('[data-n]').value.trim();
				if (!name) { toast('Add meg a sablon nevét.', true); return; }
				try {
					const r = await post('cp_mail_tpl_save', { id: id, name: name, category: te.querySelector('[data-c]').value.trim(), subject: te.querySelector('[data-s]').value, body: ed.get() });
					toast('Sablon elmentve.');
					S.cur = { id: r.id };
					drawTpls(box, r.id);
				} catch (e) { toast(e.message, true); }
			};
			te.querySelector('[data-save]').addEventListener('click', () => saveAs(t.id));
			const dup = te.querySelector('[data-dup]');
			if (dup) dup.addEventListener('click', () => { te.querySelector('[data-n]').value = te.querySelector('[data-n]').value + ' (másolat)'; saveAs(0); });
			const del = te.querySelector('[data-del]');
			if (del) del.addEventListener('click', async () => {
				if (!window.confirm('Törlöd a „' + t.name + '” sablont?')) return;
				try { await post('cp_mail_tpl_del', { id: t.id }); toast('Sablon törölve.'); S.cur = null; drawTpls(box); } catch (e) { toast(e.message, true); }
			});
		}
		if (selId) S.cur = tpls.find(t => t.id === selId) || null;
		else if (S.cur && S.cur.id) S.cur = tpls.find(t => t.id === S.cur.id) || null;
		drawList();
		edit(S.cur);
	}

	window.CPMail = { view, status, refresh: async () => { S.tpls = null; if (S.main && S.main.isConnected) view(S.main); } };
})();
