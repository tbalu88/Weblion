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
	const S = { view: 'inbox', folder: 'inbox', box: '', tpls: null, cur: null, main: null, err: '', loading: false, sel: new Set(), last: 0, rows: [], so: 'new', reading: false };
	try { if (localStorage.getItem('cpmail_so') === 'old') S.so = 'old'; } catch (e) { /* privát mód */ }
	try { const v = localStorage.getItem('cpmail_view'); if (['inbox', 'tpl'].includes(v)) S.view = v; } catch (e) { /* privát mód */ }
	/* v2.9: a böngészőben tárolt régi piszkozat átkerül a Piszkozatok közé (a szerverre) */
	setTimeout(() => { try { const o = localStorage.getItem('cpmail_draft'); if (o) { post('cp_mail_draft_save', { d: o }).then(() => { localStorage.removeItem('cpmail_draft'); }).catch(() => {}); } } catch (e) { /* privát mód */ } }, 1500);

	/* v1.67: a mappák / fülek listája gyorsítótárban – váltáskor azonnal megjelenik, a friss adat a háttérben jön */
	const RO = ['cp_mail_threads', 'cp_mail_tags', 'cp_mail_thread', 'cp_mail_html', 'cp_mail_tpls', 'cp_mail_deals', 'cp_mail_preview', 'cp_mail_presence', 'cp_mail_draft_list', 'cp_mail_draft_get', 'cp_mail_draft_save'];
	S.lc = {}; S.lcAt = {};
	async function post(action, params) {
		if (!RO.includes(action)) { S.lc = {}; S.lcAt = {}; } /* bármilyen módosítás után friss lista */
		const fd = new FormData();
		fd.append('action', action);
		fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j = null;
		try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) { const er = new Error((j && j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j && j.data && j.data.msg) || 'Hiba')); er.code = j && j.data && j.data.code; throw er; }
		return j.data;
	}
	/* v2.7.13: értesítő sáv művelettel („Visszavonás”) */
	function toastAct(text, label, fn, ms) {
		let d = document.getElementById('cpx-toast');
		if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); }
		d.innerHTML = '<span>' + esc(text) + '</span> <button type="button" class="cpx-tact">' + esc(label) + '</button>';
		d.classList.remove('bad'); d.classList.add('on');
		d.querySelector('.cpx-tact').addEventListener('click', () => { d.classList.remove('on'); clearTimeout(d._t); fn(); });
		clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), ms || 7000);
	}
	/* v2.7.13: visszavonható küldés – a levél 20 mp múlva megy ki, addig visszavonható */
	function undoWait(sec, who) {
		return new Promise(res => {
			let d = document.getElementById('cpx-toast');
			if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); }
			let n = sec, done = false;
			const fin = ok => { if (done) return; done = true; clearInterval(iv); window.removeEventListener('beforeunload', bu); d.classList.remove('on'); res(ok); };
			const bu = e => { e.preventDefault(); e.returnValue = ''; };
			const draw = () => { d.innerHTML = '<span>' + (who ? 'Küldés ide: <b>' + esc(who) + '</b> – ' + n + ' mp múlva…' : 'Küldés ' + n + ' mp múlva…') + '</span> <button type="button" class="cpx-tact">Visszavonás</button> <button type="button" class="cpx-tact ghost">Küldés most</button>'; const b = d.querySelectorAll('.cpx-tact'); b[0].onclick = () => fin(false); b[1].onclick = () => fin(true); };
			clearTimeout(d._t); d.classList.remove('bad'); d.classList.add('on'); draw();
			window.addEventListener('beforeunload', bu);
			const iv = setInterval(() => { n--; if (n <= 0) fin(true); else draw(); }, 1000);
		});
	}
	/* v2.7.13: ütközésjelzés – ki nézi / ki válaszol épp ugyanabban a szálban; új levél érkezése */
	const PR = { th: 0, known: {}, t: null };
	function presCtx() {
		if (!S.main || !S.main.isConnected) return null;
		if (S.view === 'compose' && S.cctx && S.cctx.thread) return { th: S.cctx.thread, st: 'reply' };
		if (S.view === 'inbox' && S.th > 0) return { th: S.th, st: 'view' };
		return null;
	}
	async function presPing() {
		const c = presCtx();
		if (PR.th && (!c || c.th !== PR.th)) { const old = PR.th; PR.th = 0; post('cp_mail_presence', { th: old, leave: 1 }).catch(() => {}); }
		if (!c || document.hidden) return;
		PR.th = c.th;
		let r; try { r = await post('cp_mail_presence', { th: c.th, st: c.st }); } catch (e) { return; }
		const c2 = presCtx(); if (!c2 || c2.th !== c.th) return;
		const w = r.who || [], rep = w.filter(x => x.s === 'reply').map(x => x.n), vw = w.filter(x => x.s !== 'reply').map(x => x.n);
		const kn = PR.known[c.th] || 0;
		let h = '';
		if (rep.length) h += '<span class="r">✍ <b>' + esc(rep.join(', ')) + '</b> épp válaszol erre a levélre</span>';
		if (vw.length) h += '<span>👀 ' + esc(vw.join(', ')) + ' is nézi</span>';
		if (kn && r.last > kn) h += '<span class="n">✉ <b>Új levél jött a szálba</b>' + (c.st === 'view' ? ' <button type="button" data-presrf>Frissítés</button>' : ' – küldés előtt nézd meg') + '</span>';
		document.querySelectorAll('[data-pres]').forEach(el => {
			el.innerHTML = h; el.hidden = !h;
			const b = el.querySelector('[data-presrf]'); if (b) b.addEventListener('click', () => open(c.th));
		});
	}
	PR.t = setInterval(presPing, 15000);
	/* v2.8.2: billentyűparancsok a Levelezésben (ha nem szövegmezőben gépelsz) */
	const KEYS = [['j / ↓', 'Következő levélszál'], ['k / ↑', 'Előző levélszál'], ['r', 'Válasz'], ['a', 'Válasz mindenkinek'], ['e', 'Archiválás (Archívból: vissza)'], ['# / Delete', 'Kukába'], ['s', 'Szundi'], ['u', 'Olvasatlannak jelölés'], ['x', 'Kijelölés'], ['c', 'Új levél'], ['/', 'Keresés'], ['Esc', 'Szál bezárása'], ['?', 'Ez a súgó']];
	function keyHelp() {
		const o = document.querySelector('.ml-keys'); if (o) { o.remove(); return; }
		const d = document.createElement('div'); d.className = 'ml-keys';
		d.innerHTML = '<div class="ml-keysc"><b>⌨ Billentyűparancsok – Levelezés</b>' + KEYS.map(k => '<div><kbd>' + esc(k[0]) + '</kbd><span>' + esc(k[1]) + '</span></div>').join('') + '<button type="button">Bezárás</button></div>';
		d.addEventListener('click', e => { if (e.target === d || e.target.tagName === 'BUTTON') d.remove(); });
		document.body.appendChild(d);
	}
	document.addEventListener('keydown', e => {
		if (e.isComposing || e.metaKey || ((e.ctrlKey || e.altKey) && e.key !== '#')) return; /* a magyar billentyűzeten a # AltGr-rel jön */
		if (!S.main || !S.main.isConnected || S.view !== 'inbox') return;
		const tg = e.target, tn = tg && tg.tagName;
		if (tn === 'INPUT' || tn === 'TEXTAREA' || tn === 'SELECT' || (tg && tg.isContentEditable)) { if (e.key === 'Escape' && tg.blur) tg.blur(); return; }
		if (document.querySelector('.ml-snzp') || Array.from(document.querySelectorAll('.cpx-modal, .crm-page')).some(x => x.offsetParent !== null || getComputedStyle(x).position === 'fixed' && getComputedStyle(x).display !== 'none' && x.childElementCount)) return;
		const box = S.main.querySelector('.ml-body') || S.main;
		const k = e.key;
		const items = Array.from(box.querySelectorAll('.ml-item[data-th]'));
		const cur = items.findIndex(b => Number(b.dataset.th) === S.th);
		const pick = i => { const b = items[Math.max(0, Math.min(items.length - 1, i))]; if (b) { b.click(); b.scrollIntoView({ block: 'nearest' }); } };
		const rd = box.querySelector('[data-read]');
		const tb = sel => { const b = rd && rd.querySelector(sel); if (b) { b.click(); return true; } return false; };
		let ok = true;
		if (k === 'j' || k === 'ArrowDown') pick(cur < 0 ? 0 : cur + 1);
		else if (k === 'k' || k === 'ArrowUp') pick(cur < 0 ? 0 : cur - 1);
		else if (k === 'r' || k === 'a') { const bs = rd ? Array.from(rd.querySelectorAll(k === 'r' ? '[data-reply]' : '[data-replyall]')) : []; const b = newFirst() ? bs[0] : bs[bs.length - 1]; if (b) b.click(); else ok = false; }
		else if (k === 'e') ok = tb('[data-one="archive"]') || tb('[data-one="restore"]');
		else if (k === '#' || k === 'Delete') ok = tb('[data-one="trash"]');
		else if (k === 's') ok = tb('[data-snz]');
		else if (k === 'u') ok = tb('[data-one="unread"]');
		else if (k === 'x') { const b = items[cur]; const c = b && b.querySelector('[data-ck]'); if (c) c.click(); else ok = false; }
		else if (k === 'c') { const b = box.querySelector('[data-compose]'); if (b) b.click(); else ok = false; }
		else if (k === '/') { const q = box.querySelector('.ml-q'); if (q) q.focus(); else ok = false; }
		else if (k === 'Escape') { if (S.closeTh && S.th) S.closeTh(); else ok = false; }
		else if (k === '?') keyHelp();
		else ok = false;
		if (ok) e.preventDefault();
	});
	document.addEventListener('visibilitychange', () => { if (!document.hidden) presPing(); });
	window.addEventListener('pagehide', () => { if (PR.th && navigator.sendBeacon) { const fd = new FormData(); fd.append('action', 'cp_mail_presence'); fd.append('t', C.t); fd.append('th', PR.th); fd.append('leave', '1'); navigator.sendBeacon(C.ajax, fd); } });
	function toast(t, bad) {
		let d = document.getElementById('cpx-toast');
		if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); }
		d.textContent = t; d.classList.toggle('bad', !!bad); d.classList.add('on');
		clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), bad ? 6000 : 3200);
	}
	/* v1.95: „Link másolása” – a levélszál közvetlen linkje (#mail:ID), mint a Salesmate-ben */
	const LINK_I = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/></svg>';
	async function copyLink(id) {
		const u = location.origin + location.pathname + '#mail:' + id;
		try { await navigator.clipboard.writeText(u); toast('A levél linkje kimásolva.'); }
		catch (e) { window.prompt('A levél linkje:', u); }
	}
	function status(s) { s.textContent = M.connected ? '' : (M.sync ? ((M.send && M.send.on) ? 'Levelezés: beérkezés 5 percenként · küldés: próbaüzem' : 'Levelezés: beérkezés 5 percenként, csak olvasva') : 'Levelezés: a postafiókok még nincsenek bekötve'); s.className = 'cpx-status'; }

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
		portal: '<svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
		clear: '<svg viewBox="0 0 24 24"><path d="M6 5h12M12 5l-4 14M5 19h6M16 14l5 5M21 14l-5 5"/></svg>',
		code: '<svg viewBox="0 0 24 24"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5"/></svg>',
		undo: '<svg viewBox="0 0 24 24"><path d="M9 14L4 9l5-5M4 9h10a6 6 0 0 1 0 12h-3"/></svg>',
		redo: '<svg viewBox="0 0 24 24"><path d="M15 14l5-5-5-5M20 9H10a6 6 0 0 0 0 12h3"/></svg>'
	};
	const TOOLS = [['bold', 'Félkövér'], ['italic', 'Dőlt'], ['underline', 'Aláhúzott'], ['strike', 'Áthúzott'], ['|'], ['h2', 'Címsor'], ['p', 'Normál bekezdés'], ['ul', 'Felsorolás'], ['ol', 'Számozott lista'], ['quote', 'Idézet'], ['|'],
		['link', 'Link beszúrása'], ['img', 'Kép beszúrása (webcímről)'], ['table', 'Táblázat beszúrása'], ['var', 'Változó beszúrása'], ['attach', 'Melléklet csatolása'], ['portal', 'Ügyfélportál-link beszúrása (a dealhez)'], ['sign', 'Aláírás beszúrása'], ['clear', 'Formázás törlése'], ['|R'], ['code', 'HTML-nézet'], ['undo', 'Visszavonás'], ['redo', 'Újra']];
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
			if (c === 'attach' || c === 'portal') return; /* a levélíró kezeli (v1.54, v1.59) */
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
				if (M.sig) { if (area.innerHTML.indexOf('cp-sign') >= 0) { toast('Az aláírás már a levélben van.'); return; } return exec('insertHTML', '<div class="cp-sign">' + M.sig + '</div>'); }
				if (M.send && M.send.on) { toast('Még nincs aláírásod – az admin állítja be (Beállítások → Felhasználók → Aláírás).', true); return; }
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
			/* beszúrás a kurzorhoz – de ha a kurzor az aláírásban vagy utána van, az aláírás elé */
			html: h => {
				const sig = area.querySelector('.cp-sign'), sel = window.getSelection();
				const at = sel && sel.rangeCount && area.contains(sel.anchorNode) ? sel.anchorNode : null;
				if (sig && (!at || sig.contains(at) || (sig.compareDocumentPosition(at) & Node.DOCUMENT_POSITION_FOLLOWING))) { sig.insertAdjacentHTML('beforebegin', h); area.dispatchEvent(new Event('input', { bubbles: true })); return; }
				area.focus(); exec('insertHTML', h);
			},
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
	/* v1.97: a lista percenként magától frissül (ha a fül látható); nyitott levél vagy kijelölés mellett nem rajzol újra,
	 * csak a lista tetején jelzi: „Új levél érkezett – frissítés” */
	const sig = r => JSON.stringify(((r && r.rows) || []).map(t => [t.id, t.at, t.cnt, t.un, t.st, t.as]));
	function autoTick() {
		if (document.hidden || S.demo || S.view !== 'inbox' || !S.main || !S.main.isConnected) return;
		const box = S.main.querySelector('.ml-body');
		if (!box || !box.querySelector('.ml-items')) return;
		const P = { folder: S.folder, tab: S.tab, box: S.box, q: S.mq || '', so: S.so, un: S.unf ? '1' : '', lim: S.lim || 100, tg: tgP() }, key = JSON.stringify(P);
		post('cp_mail_threads', P).then(n => {
			if (JSON.stringify(P) !== JSON.stringify({ folder: S.folder, tab: S.tab, box: S.box, q: S.mq || '', so: S.so, un: S.unf ? '1' : '', lim: S.lim || 100, tg: tgP() })) return;
			const old = S.lc[key];
			if (old && sig(old) === sig(n)) return;
			S.lc[key] = n; S.lcAt[key] = Date.now();
			if (!box.isConnected) return;
			const ae = document.activeElement, typing = !!ae && box.contains(ae) && (ae.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName));
			if (!S.th && !S.sel.size && !typing) { drawInbox(box); return; }
			const it = box.querySelector('.ml-items');
			if (it && !it.querySelector('[data-newp]')) {
				it.insertAdjacentHTML('afterbegin', '<button type="button" class="ml-newp" data-newp>↑ Új levél érkezett – frissítés</button>');
				it.querySelector('[data-newp]').addEventListener('click', () => drawInbox(box));
			}
		}).catch(() => {});
	}
	if (M.sync) {
		setInterval(autoTick, 60000);
		document.addEventListener('visibilitychange', () => { if (!document.hidden) setTimeout(autoTick, 800); });
	}
	function view(main) {
		S.main = main;
		const seg = [['inbox', 'Postafiók'], ['compose', 'Új levél'], ['tpl', 'Sablonok']].map(x => '<button type="button" data-v="' + x[0] + '" class="' + (S.view === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('');
		main.innerHTML = '<div class="ml v-' + S.view + '">' + (M.connected ? '' : (M.sync ? ((M.send && M.send.on) ? '<p class="ml-note ok"><b>Próbaüzem:</b> a panelből is küldhetsz és válaszolhatsz – a levél a postafiókból megy, és az Elküldött mappába is bekerül, így a Salesmate is látja.</p>' : '<p class="ml-note">A beérkező levelek 5 percenként ide is megérkeznek (csak olvasva). Válaszolni továbbra is a Salesmate Team Inboxban kell – az AI-piszkozatot onnan küldd el.</p>') : '<p class="ml-note">Előkészítés alatt: a postafiókok bekötéséig a leveleket a Salesmate Team Inboxban kezeld.</p>')) +
			'<div class="ml-head"><div class="crm-seg ml-seg">' + seg + '</div></div><div class="ml-body"></div></div>';
		main.querySelectorAll('[data-v]').forEach(b => b.addEventListener('click', () => { S.view = b.dataset.v; try { localStorage.setItem('cpmail_view', S.view); } catch (e) { /* privát mód */ } view(main); }));
		const body = main.querySelector('.ml-body');
		if (S.view === 'inbox') drawInbox(body);
		else if (S.view === 'compose') drawCompose(body, null);
		else drawTpls(body);
	}

	/* ------------------------------------------------------------ postafiók: levéllista, olvasópanel, jegyzetek */
	const PEOPLE = M.people || [];
	const pname = id => (PEOPLE.find(p => p.id === id) || {}).n || '';
	const ini = n => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?';
	const AVC = ['#C9D6FB', '#F9D4B8', '#FBE3A6', '#D6CCFA', '#C5EBD6', '#F6C6C6', '#BFE3EE'];
	const avc = s => AVC[Math.abs(String(s || '').split('').reduce((a, c) => a + c.charCodeAt(0), 0)) % AVC.length];
	function ago(s) {
		if (!s) return '';
		const t = new Date(s.replace(' ', 'T')), d = (Date.now() - t.getTime()) / 1000;
		if (d < 90) return 'pár másodperce';
		if (d < 3600) return Math.round(d / 60) + ' perce';
		if (d < 86400) return Math.round(d / 3600) + ' órája';
		if (d < 172800) return 'tegnap';
		return s.slice(0, 10).replace(/-/g, '.') + '.';
	}
	const nl2br = s => esc(s).replace(/\n/g, '<br>').replace(/@([A-ZÁÉÍÓÖŐÚÜŰ][\wáéíóöőúüű]+(?: [A-ZÁÉÍÓÖŐÚÜŰ][\wáéíóöőúüű]+)?)/g, '<b class="ml-men">@$1</b>');
	const NOTE_I = '<svg class="ml-ni" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v10l-6 6H5z"/><path d="M13 20v-6h6"/></svg>';
	/* v1.54: a melléklet letöltése a postafiókból (képnél / PDF-nél megnyitás a böngészőben) */
	/* v1.67.1: sima szöveges (importált / idézetből kinyert) levél olvashatóbban: a levelezőprogramok szöveges
	 * jelölései ([kép-cím], szöveg<link>, <tel:…>, <mailto:…>) helyett kattintható link, a régebbi idézett levelek lenyithatók */
	function tidyBody(h) {
		h = String(h || '');
		h = h.replace(/\[(?:cid:[^\]]*|https?:\/\/[^\]\s]+?\.(?:png|jpe?g|gif|webp|bmp)(?:\?[^\]\s]*)?)\]/gi, '');
		h = h.replace(/&lt;(?:mailto|tel):[^\s]*?&gt;/gi, '');
		const wrap = /^\s*<p>([\s\S]*)<\/p>\s*$/i.exec(h);
		if (wrap) h = wrap[1];
		/* a szöveg nélküli (képhez tartozó) linkek ki – egymás után többször is */
		for (let i = 0, p = ''; i < 5 && p !== h; i++) { p = h; h = h.replace(/(^|<br>|\s|&gt;)&lt;https?:\/\/[^\s<>"]+?&gt;/g, '$1'); }
		h = h.replace(/([^\s<>;]+?)&lt;(https?:\/\/[^\s<>"]+?)&gt;/g, (x, t, u) => '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + t + '</a>');
		h = h.replace(/(<br>\s*){3,}/g, '<br><br>');
		const lines = h.split(/<br\s*\/?>/i);
		const qi = lines.findIndex((l, i) => i > 0 && /^\s*(?:<\/?p>)?\s*(On .{6,120} wrote:|.{6,160} (?:ezt )?írta:|-{2,}\s*(?:Original Message|Eredeti üzenet)|From:\s|Feladó:\s)/i.test(l.replace(/<[^>]+>/g, '')));
		if (qi > 0) {
			const rest = lines.slice(qi).join('<br>');
			h = lines.slice(0, qi).join('<br>').replace(/(<br>\s*)+$/, '') + '<details class="ml-qd"><summary>… korábbi levelek</summary><div>' + rest + '</div></details>';
		}
		return '<p>' + h + '</p>';
	}
	/* v1.67: a bejövő levél eredeti (HTML) nézete – elzárt keretben: szkript nem fut, külső kép csak kérésre */
	const HC = {};
	let HV = 'html';
	try { if (localStorage.getItem('cpmail_hv') === 'txt') HV = 'txt'; } catch (e) { /* privát mód */ }
	function frameDoc(mid, html, imgs) {
		const o = location.origin, att = i => (C.ajax + '?action=cp_mail_att&m=' + mid + '&i=' + i + '&view=1&t=' + encodeURIComponent(C.t)).replace(/&/g, '&amp;');
		return '<!doctype html><html' + (document.documentElement.getAttribute('data-theme') === 'dark' ? ' class="cpdark"' : '') + '><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data: ' + o + (imgs ? ' https: http:' : '') + '; font-src data:' + (imgs ? ' https:' : '') + '">' +
			'<meta name="referrer" content="no-referrer"><base target="_blank"><style>html,body{margin:0;padding:0}html{overflow-y:hidden;overflow-x:auto}div,table,p,img,center{max-width:100%!important;box-sizing:border-box}body{font:14px/1.5 Arial,Helvetica,sans-serif;color:#1A1F2B;overflow-wrap:anywhere;-webkit-text-size-adjust:100%}img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}blockquote{margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex}blockquote blockquote,.cpq blockquote{margin-left:0!important;padding-left:8px!important;border-left:2px solid #ddd!important}blockquote blockquote blockquote,.cpq blockquote blockquote{border-left:0!important;padding-left:0!important;margin-left:0!important}details.cpq>summary{cursor:pointer;display:inline-block;margin:8px 0;padding:2px 10px;border-radius:6px;background:#F1EDE7;color:#656973;font:12.5px Arial,sans-serif;list-style:none}details.cpq>summary::-webkit-details-marker{display:none}html.cpdark img,html.cpdark video,html.cpdark [background],html.cpdark [style*="background-image"]{filter:invert(1) hue-rotate(180deg)}@media (max-width:760px){body{font-size:13px}}</style></head><body>' +
			String(html).replace(/cpatt:(\d+)/g, (x, i) => att(i)) + '</body></html>';
	}
	function mountHtml(el, mid, imgs) {
		const x = HC[mid];
		if (el._txt === undefined) el._txt = el.innerHTML;
		const bar = '<div class="ml-hv"><button type="button" data-hv="html" class="' + (HV === 'html' ? 'on' : '') + '">Eredeti</button><button type="button" data-hv="txt" class="' + (HV === 'txt' ? 'on' : '') + '">Szöveg</button></div>';
		if (HV === 'txt') { el.innerHTML = bar + el._txt; }
		else {
			el.innerHTML = bar + (x.ext && !imgs ? '<div class="ml-extbar">🖼 A külső képek a biztonságod érdekében nincsenek betöltve. <button type="button" data-imgs>Képek megjelenítése</button></div>' : '') +
				'<iframe class="ml-hf" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" title="A levél eredeti formájában"></iframe>';
			const f = el.querySelector('iframe');
			f.addEventListener('load', () => {
				try {
					const d = f.contentDocument;
					/* v2.7.3: magasság a tartalomhoz – a vízszintes csúszka magasságával együtt, és csak valódi változásra
					   (különben a csúszka megjelenése/eltűnése és az újraméretezés egymást váltogatta, a levél „remegett”) */
					let last = 0, n = 0, t0 = Date.now();
					const fit = () => {
						const de = d.documentElement, hs = de.scrollWidth > de.clientWidth + 1 ? 18 : 0, h = de.scrollHeight + hs + 2;
						if (Math.abs(h - last) < 4) return;
						if (Date.now() - t0 < 2000) { if (++n > 25) return; } else { t0 = Date.now(); n = 0; }
						last = h; f.style.height = h + 'px';
					};
					/* v1.69.2: a korábbi levelek idézete (egymásba ágyazott blockquote / Gmail / Outlook előzmény) összecsukva */
					const quotes = Array.from(d.querySelectorAll('blockquote, .gmail_quote, #divRplyFwdMsg, #appendonsend')).filter(q => !q.parentElement.closest('blockquote, .gmail_quote, details.cpq'));
					quotes.forEach(q => { if (!q.isConnected || (q.textContent || '').trim().length < 40) return; const dt = d.createElement('details'); dt.className = 'cpq'; const sm = d.createElement('summary'); sm.textContent = '… korábbi levelek'; q.parentNode.insertBefore(dt, q); dt.appendChild(sm); let n = q; const tail = []; while (n) { tail.push(n); n = n.nextSibling; } if (q.id === 'divRplyFwdMsg' || q.id === 'appendonsend') tail.forEach(x => dt.appendChild(x)); else dt.appendChild(q); dt.addEventListener('toggle', fit); });
					fit(); d.querySelectorAll('img').forEach(i => i.addEventListener('load', fit));
					if (window.ResizeObserver) new ResizeObserver(fit).observe(d.body);
				} catch (e) { f.style.height = '480px'; }
			});
			f.srcdoc = frameDoc(mid, x.html, imgs);
			const ib = el.querySelector('[data-imgs]');
			if (ib) ib.addEventListener('click', () => mountHtml(el, mid, true));
		}
		el.querySelectorAll('[data-hv]').forEach(b => b.addEventListener('click', () => { HV = b.dataset.hv; try { localStorage.setItem('cpmail_hv', HV); } catch (e) { /* privát mód */ } document.querySelectorAll('.ml-mb[data-hm]').forEach(m2 => { if (HC[m2.dataset.hm] && HC[m2.dataset.hm].html) mountHtml(m2, m2.dataset.hm, false); }); }));
	}
	/* v2.7.12: éjszakai mód váltásakor a már megnyitott levelek is átváltanak */
	new MutationObserver(() => { const dk = document.documentElement.getAttribute('data-theme') === 'dark'; document.querySelectorAll('iframe.ml-hf').forEach(f => { try { f.contentDocument.documentElement.classList.toggle('cpdark', dk); } catch (e) { /* még töltődik */ } }); }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
	function loadHtml(rd) {
		rd.querySelectorAll('.ml-msg:not(.cl) .ml-mb[data-hm]:not([data-hl])').forEach(el => {
			el.dataset.hl = '1'; /* v2.7.8: csak a kinyitott levelek eredeti nézete töltődik be (egyszer) */
			const mid = el.dataset.hm;
			if (HC[mid]) { if (HC[mid].html) mountHtml(el, mid, false); return; }
			post('cp_mail_html', { id: mid }).then(x => { HC[mid] = x; if (x.html && el.isConnected) mountHtml(el, mid, false); }).catch(() => { /* marad a szöveges nézet */ });
		});
	}
	/* v2.9.27: kockázatos csatolmány jelzése – a panel nem víruskereső, de a veszélyes típusokra figyelmeztet, és letöltés előtt rákérdez */
	const ATT_HIGH = /\.(exe|scr|bat|cmd|com|pif|msi|msp|js|jse|vbs|vbe|wsf|wsh|ps1|psm1|hta|jar|lnk|iso|img|vhdx?|reg|cpl|dll|html?|svg|xhtml|mht|url|appx|msix)$/i;
	const ATT_WARN = /\.(docm|dotm|xlsm|xltm|xlam|pptm|potm|ppsm|zip|rar|7z|gz|tgz|tar|cab|ace|arj|bz2|xz|rtf|one)$/i;
	const attRisk = n => {
		n = String(n || '');
		if (/[‪-‮⁦-⁩]/.test(n) || /\.(pdf|docx?|xlsx?|jpe?g|png)\s{2,}/i.test(n) || /\.(pdf|docx?|xlsx?|jpe?g|png)\.(exe|scr|js|vbs|bat|cmd|com|hta|lnk)$/i.test(n)) return { lvl: 'high', why: 'a fájlnév álcázott (más típusnak mutatja magát, mint ami valójában)' };
		if (ATT_HIGH.test(n)) return { lvl: 'high', why: /\.(html?|svg|xhtml|mht|url)$/i.test(n) ? 'weboldal- vagy hivatkozásfájl – gyakori adathalász-trükk' : 'futtatható / programfájl – megnyitva kárt okozhat' };
		if (ATT_WARN.test(n)) return { lvl: 'warn', why: /\.(zip|rar|7z|gz|tgz|tar|cab|ace|arj|bz2|xz)$/i.test(n) ? 'tömörített fájl – a belsejét nem látjuk, ismeretlen feladótól gyanús' : 'makrót vagy beágyazott tartalmat hordozhat – makrót ne engedélyezz' };
		return null;
	};
	window.CPAttRisk = attRisk;
	if (!window.__cpAttGuard) {
		window.__cpAttGuard = true;
		document.addEventListener('click', e => {
			const a = e.target.closest && e.target.closest('a[data-risk]');
			if (!a) return;
			const r = attRisk(a.dataset.rn || a.textContent);
			if (!r) return;
			const msg = (r.lvl === 'high' ? '⚠ Veszélyes csatolmány!\n\n' : 'Figyelem – kockázatos csatolmány.\n\n') + '„' + (a.dataset.rn || a.textContent.trim()) + '”\nOk: ' + r.why + '.\n\n' + (r.lvl === 'high' ? 'Csak akkor töltsd le, ha biztosan tudod, mi ez és ki küldte. Ismeretlen feladótól (pl. álláspályázat) ne nyisd meg.' : 'Ismeretlen feladótól óvatosan: tömörített fájlt ne bonts ki, Office-fájlban ne engedélyezz makrót / szerkesztést.') + '\n\nLetöltöd mégis?';
			if (!window.confirm(msg)) { e.preventDefault(); e.stopPropagation(); }
		}, true);
	}
	const attRiskAttr = n => { const r = attRisk(n); return r ? ' data-risk="' + r.lvl + '" data-rn="' + esc(n) + '" class="ml-arisk ' + r.lvl + '" title="' + esc((r.lvl === 'high' ? 'Veszélyes: ' : 'Kockázatos: ') + r.why) + '"' : ''; };
	const attUrl = (mid, i, n) => C.ajax + '?action=cp_mail_att&m=' + mid + '&i=' + i + '&t=' + encodeURIComponent(C.t) + (/\.(png|jpe?g|gif|webp|pdf)$/i.test(n || '') ? '&view=1' : '');
	window.CPMailAtt = attUrl;
	const CLIP_I = '<svg class="ml-ni" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5l-8.5 8.5a5 5 0 0 1-7-7L13 4.5a3.5 3.5 0 0 1 5 5L9.5 18a2 2 0 0 1-3-3L14 7.5"/></svg>';

	/* hasábszélességek: húzással méretezhetők, a böngésző megjegyzi */
	const COLS = { c1: 150, c2: 320, fold: false };
	function cols() {
		let v = {};
		try { v = JSON.parse(localStorage.getItem('cpmail_cols') || '{}') || {}; } catch (e) { /* privát mód */ }
		return { c1: Math.min(260, Math.max(110, Number(v.c1) || COLS.c1)), c2: Math.min(640, Math.max(220, Number(v.c2) || COLS.c2)), fold: !!v.fold };
	}
	function saveCols(v) { try { localStorage.setItem('cpmail_cols', JSON.stringify(v)); } catch (e) { /* privát mód */ } }
	function fit(g) {
		if (!g.isConnected) return;
		if (window.innerWidth <= 1100) { g.style.height = ''; return; }
		g.style.height = Math.max(420, window.innerHeight - (g.getBoundingClientRect().top + window.scrollY) - 12) + 'px';
	}
	function layout(box) {
		const g = box.querySelector('.ml-inbox');
		fit(g);
		if (!S.fitOn) { S.fitOn = true; window.addEventListener('resize', () => { const x = document.querySelector('.ml-inbox'); if (x) fit(x); }); }
		box.querySelector('[data-fold]').addEventListener('click', () => { const v = cols(); v.fold = !v.fold; saveCols(v); drawInbox(box); });
		g.querySelectorAll('[data-split]').forEach(h => {
			const k = h.dataset.split;
			h.addEventListener('dblclick', () => { const v = cols(); v[k] = COLS[k]; if (k === 'c1') v.fold = false; saveCols(v); g.classList.remove('fold'); g.style.setProperty('--' + k, v[k] + 'px'); });
			h.addEventListener('pointerdown', e => {
				e.preventDefault();
				const v = cols(), x0 = e.clientX, w0 = k === 'c1' && v.fold ? 40 : v[k];
				const lim = k === 'c1' ? [110, 260] : [220, 640];
				h.setPointerCapture(e.pointerId); g.classList.add('drag');
				const mv = ev => { v[k] = Math.round(Math.min(lim[1], Math.max(lim[0], w0 + ev.clientX - x0))); if (k === 'c1') { v.fold = false; g.classList.remove('fold'); } g.style.setProperty('--' + k, v[k] + 'px'); };
				const up = () => { h.removeEventListener('pointermove', mv); h.removeEventListener('pointerup', up); h.removeEventListener('pointercancel', up); g.classList.remove('drag'); saveCols(v); if (k === 'c1' && box.querySelector('[data-fold]').textContent === '»') drawInbox(box); };
				h.addEventListener('pointermove', mv); h.addEventListener('pointerup', up); h.addEventListener('pointercancel', up);
			});
		});
	}

	const svg = (d, c) => '<svg class="' + (c || 'ml-ic') + '" viewBox="0 0 24 24" aria-hidden="true">' + d + '</svg>';
	const DEAL_I = svg('<circle cx="12" cy="12" r="9"/><path d="M14.8 9.2c-.4-.9-1.5-1.5-2.8-1.5-1.6 0-2.8.8-2.8 2s1.2 1.7 2.8 2.1 2.9.9 2.9 2.2-1.3 2.1-2.9 2.1c-1.4 0-2.5-.6-2.9-1.6M12 6v1.7M12 16.3V18"/>', 'ml-dc-i');
	const X_I = svg('<path d="M6 6l12 12M18 6L6 18"/>');
	/* v2.9.22: a levélszál eszköztárának vonalas ikonjai (emoji helyett) */
	const TI = {
		ok: svg('<path d="M5 12.5l4.5 4.5L19 7.5"/>'), re: svg('<path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4.5h4.5"/>'),
		tag: svg('<path d="M3.5 12.2V4.5a1 1 0 0 1 1-1h7.7l8.3 8.3a1.4 1.4 0 0 1 0 2l-6.2 6.2a1.4 1.4 0 0 1-2 0z"/><circle cx="8" cy="8" r="1.3"/>'),
		ai: svg('<path d="M12 3.5l1.9 5 5 1.9-5 1.9-1.9 5-1.9-5-5-1.9 5-1.9z"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>'),
		todo: svg('<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8.5 12.2l2.4 2.4 4.6-4.9"/>'),
		unread: svg('<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/><circle cx="19" cy="6" r="2.6" fill="currentColor" stroke="none"/>'),
		clock: svg('<circle cx="12" cy="13" r="7.5"/><path d="M12 9.2V13l2.6 1.8"/><path d="M5 4.5L3 6.5M19 4.5l2 2"/>'),
		arch: svg('<rect x="3.5" y="4.5" width="17" height="4" rx="1"/><path d="M5 8.5v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-10"/><path d="M10 12.5h4"/>'),
		trash: svg('<path d="M4.5 7h15"/><path d="M9.5 7V4.5h5V7"/><path d="M6.5 7l.9 12.1a1 1 0 0 0 1 .9h7.2a1 1 0 0 0 1-.9L17.5 7"/>'),
		back: svg('<path d="M9 14L4.5 9.5 9 5"/><path d="M5 9.5h9a5.5 5.5 0 0 1 0 11h-2"/>')
	};
	const RE_I = svg('<path d="M10 8L5 12.5l5 4.5"/><path d="M5.5 12.5H14a5 5 0 0 1 5 5V19"/>');
	const REA_I = svg('<path d="M8 8l-5 4.5L8 17"/><path d="M13 8l-5 4.5 5 4.5"/><path d="M8.5 12.5H15a5 5 0 0 1 5 5V19"/>');
	const DEL_I = svg('<path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13M10 11v5.5M14 11v5.5"/>');
	const MORE_I = svg('<circle cx="5.5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="18.5" cy="12" r="1.3"/>', 'ml-ic dots');
	const HMON = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
	const hdate = s => { const m = String(s || '').match(/^(\d{4})-(\d\d)-(\d\d)[ T](\d\d:\d\d)/); return m ? m[1] + '. ' + HMON[Number(m[2]) - 1] + ' ' + Number(m[3]) + '. ' + m[4] : String(s || ''); };
	/* „Név <cím>” lista: a név kékkel, a cím alatta */
	const addr = l => String(l || '').split(/,(?![^<]*>)/).map(x => x.trim()).filter(Boolean).map(x => { const m = x.match(/^"?(.*?)"?\s*<([^>]+)>$/); return m && m[1] ? '<span class="ml-tn">' + esc(m[1]) + '</span> <span class="ml-fe">&lt;' + esc(m[2]) + '&gt;</span>' : '<span class="ml-tn">' + esc(x) + '</span>'; }).join(', ');

	/* a levelek és a szál eseményei időrendben */
	/* v1.78.3: alapból a legfrissebb levél van felül (a szál fejlécében átállítható, a böngésző megjegyzi) */
	const newFirst = () => { try { return localStorage.getItem('cpmail_ord') !== 'old'; } catch (e) { return true; } };
	const timeline = x => (x.msgs || []).map(m => ({ at: String(m.sent_at || ''), m: m })).concat((x.events || []).map(e => ({ at: String(e.created_at || ''), ev: e }))).sort((a, b) => (a.at < b.at ? -1 : a.at > b.at ? 1 : 0) * (newFirst() ? -1 : 1));

	/* minta (a postafiókok bekötéséig): csak a böngészőben, nem mentődik */
	function demoData() {
		const now = Date.now(), at = m => { const d = new Date(now - m * 60000); const p = n => (n < 10 ? '0' : '') + n; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()); };
		const me = PEOPLE[0] || { id: 1, n: 'Andrea Gyarmati' };
		return [
			{ id: -1, un: 1, box: 'eventcrazy', subj: 'Ajánlatkérés – Konferencia fotózás, egyedi fotófal', cn: 'Minta Mónika', ce: 'monika@example.com', deal: 0, as: me.id, st: 'open', folder: 'inbox', snip: 'Szia Andi, van már esetleg frissített ár a 2 méter magas fotófalra?', cnt: 14, att: 0, at: at(1), fol: [], tags: '', note: { b: 'Fólia: Szerkezet: LED-es dekorvilágítás: 450k', by: me.n, at: at(3) },
				_deal: { id: 0, title: '[EventCrazy] Minta Mónika (Példa Agency) | 2027-01-14', value: 0, cur: 'HUF', cn: 'Minta Mónika', co: 'Példa Agency Zrt.', stage: 'Ajánlat kiküldve' },
				_notes: [{ id: -11, body: 'Fólia:\nSzerkezet:\nLED-es dekorvilágítás:\n450k', author: me.n, author_uid: me.id, created_at: at(3) }],
				_msgs: [{ dir: 'in', from_name: 'Minta Mónika', from_email: 'monika@example.com', to_list: 'EventCrazy', cc_list: '', sent_at: at(1), body: '<p>Szia Andi,</p><p>Van már esetleg frissített ár a 2 méter magas, kb. 3 méter széles fotófalra?</p><p>Ma mindenképp le kell adnunk az ügyfélnek.</p>', attachments: [] }] },
			{ id: -2, box: 'eventcrazy', subj: 'Árajánlat háttérfal dekorációra', cn: 'Minta Dekor', ce: 'dekor@example.com', deal: 0, as: 0, st: 'open', folder: 'inbox', snip: 'Kedves EventCrazy Csapata! Mellékelten küldöm a kért dekorációs árajánlatot.', cnt: 1, att: 1, at: at(2), fol: [], tags: '', note: { b: 'na nem lőttem én annyira mellé 🥳', by: me.n, at: at(2) },
				_deal: null, _notes: [{ id: -21, body: 'na nem lőttem én annyira mellé 🥳', author: me.n, author_uid: me.id, created_at: at(2) }],
				_msgs: [{ dir: 'in', from_name: 'Minta Dekor', from_email: 'dekor@example.com', to_list: 'info@eventcrazy.hu', cc_list: me.n, sent_at: at(2), body: '<p>Kedves EventCrazy Csapata!</p><p>Mellékelten küldöm a kért dekorációs árajánlatot.</p><p>Üdvözlettel:<br>Minta Dekor</p>', attachments: [{ n: 'Háttérfal dekoráció árajánlat.pdf' }] }] },
			{ id: -3, box: 'crazyphoto', subj: 'Ajánlatkérés | 2026.11.20. vagy 27.', cn: 'Minta Csilla', ce: 'csilla@example.com', deal: 0, as: me.id, st: 'open', folder: 'inbox', snip: 'Köszönöm, a szerződést aláírva csatolom.', cnt: 19, att: 0, at: at(60 * 26), fol: [me.id], tags: 'szerződés', note: { b: 'szerződés', by: me.n, at: at(60 * 23) },
				_deal: { id: 0, title: '[EC_360 Spinner] Minta Csilla | 2026-12-04', value: 190000, cur: 'HUF', cn: 'Minta Csilla', co: '', stage: 'Szerződés kiküldve' },
				_notes: [{ id: -31, body: 'szerződés', author: me.n, author_uid: me.id, created_at: at(60 * 23) }],
				_msgs: [{ dir: 'out', from_name: 'EventCrazy', from_email: 'info@eventcrazy.hu', to_list: 'Minta Csilla <csilla@example.com>', cc_list: '', sent_at: at(60 * 28), status: 'Kézbesítve', body: '<p>Kedves Csilla!</p><p>Csatoltan küldjük a szerződést.</p>', attachments: [] }] }
		];
	}

	const demoOk = () => !(M.connected || M.sync);
	async function drawInbox(box) {
		if (!demoOk()) S.demo = false;
		const F = [['inbox', 'Beérkezett'], ['follow', 'Követett'], ['snooze', 'Szundi'], ['promo', 'Promóciók'], ['outbox', 'Kimenő'], ['sent', 'Elküldött'], ['draft', 'Piszkozatok'], ['archive', 'Archív'], ['trash', 'Kuka'], ['spam', 'Spam']];
		S.tab = S.tab || 'open';
		let rows = [], counts = {}, un = {};
		if (S.demo) { rows = (S.demoRows = S.demoRows || demoData()).filter(t => (S.folder === 'follow' ? t.fol.length : (t.folder || 'inbox') === S.folder) && (S.tab !== 'open' || t.st === 'open') && (S.tab !== 'unassigned' || !t.as) && (!S.unf || t.un) && (!(S.tg || []).length || tgList(t.tags).some(n => S.tg.some(x => x.toLowerCase() === n.toLowerCase())))); S.tags = demoTags(); un = { inbox: S.demoRows.filter(t => t.un && t.st === 'open' && (t.folder || 'inbox') === 'inbox').length }; counts = { inbox: S.demoRows.filter(t => t.st === 'open').length, follow: S.demoRows.filter(t => t.fol.length).length }; }
		else {
			const P = { folder: S.folder, tab: S.tab, box: S.box, q: S.mq || '', so: S.so, un: S.unf ? '1' : '', lim: S.lim || 100, tg: tgP() }, key = JSON.stringify(P), c = S.lc[key];
			let r = c;
			if (!c) { box.querySelectorAll('.ml-items, .ml-list').forEach(x => x.classList.add('ml-busy')); try { r = await post('cp_mail_threads', P); S.lc[key] = r; S.lcAt[key] = Date.now(); } catch (e) { r = null; S.err = e.message; } }
			/* a szomszédos fülek (Nyitott / Kiosztatlan / Mind) előre betöltve, hogy a váltás azonnali legyen */
			if (!c && r) setTimeout(() => ['open', 'unassigned', 'all'].filter(x => x !== P.tab).forEach(x => { const Q = Object.assign({}, P, { tab: x }), k = JSON.stringify(Q); if (!S.lc[k]) post('cp_mail_threads', Q).then(n => { S.lc[k] = n; S.lcAt[k] = Date.now(); }).catch(() => {}); }), 300);
			else if (Date.now() - (S.lcAt[key] || 0) > 4000) {
				/* háttérfrissítés: ha változott és még ugyanezt nézed (nyitott levél nélkül), újrarajzol */
				S.lcAt[key] = Date.now();
				post('cp_mail_threads', P).then(n => { const same = JSON.stringify(n) === JSON.stringify(S.lc[key]); S.lc[key] = n; if (!same && box.isConnected && !S.th && JSON.stringify({ folder: S.folder, tab: S.tab, box: S.box, q: S.mq || '', so: S.so, un: S.unf ? '1' : '', lim: S.lim || 100, tg: tgP() }) === key) drawInbox(box); }).catch(() => {});
			}
			if (!box.isConnected) return;
			if (r) { rows = r.rows; counts = r.counts || {}; un = r.un || {}; if (r.tags) S.tags = r.tags; }
			S.more = !!(r && r.more);
		}
		if (S.demo && S.so === 'old') rows = rows.slice().reverse();
		S.rows = rows;
		const ids = new Set(rows.map(t => t.id));
		S.sel.forEach(id => { if (!ids.has(id)) S.sel.delete(id); });
		const L = cols();
		box.innerHTML = '<div class="ml-inbox' + (L.fold ? ' fold' : '') + (S.sel.size ? ' sel' : '') + (S.reading && S.th ? ' reading' : '') + '" style="--c1:' + (L.fold ? 40 : L.c1) + 'px;--c2:' + L.c2 + 'px">' +
			'<aside class="ml-side"><div class="ml-sideh"><button type="button" class="cpx-btn ml-new" data-compose>Új levél</button><button type="button" class="ml-fold" data-fold title="' + (L.fold ? 'Mappák kinyitása' : 'Mappák összecsukása') + '">' + (L.fold ? '»' : '«') + '</button></div>' +
			'<select class="ml-box" aria-label="Postafiók"><option value="">Minden postafiók</option>' + BOXES.map(b => '<option value="' + esc(b.id) + '"' + (S.box === b.id ? ' selected' : '') + '>' + esc(b.n) + '</option>').join('') + '</select>' +
			'<nav class="ml-folders">' + F.map(f => '<button type="button" data-f="' + f[0] + '" class="' + (S.folder === f[0] ? 'on' : '') + '"><span>' + f[1] + '</span><b' + (un[f[0]] ? ' class="u" title="' + un[f[0]] + ' olvasatlan"' : '') + '>' + (counts[f[0]] || '') + '</b></button>').join('') + '</nav>' + tagSide() +
			/* a „Minta megtekintése” csak a postafiókok bekötése előtt kell (kitalált levelekkel próbálható ki a felület) */
			(demoOk() ? '<label class="ml-demo"><input type="checkbox" data-demo' + (S.demo ? ' checked' : '') + '> Minta megtekintése</label>' : '') + '</aside><div class="ml-split" data-split="c1" title="Húzd az oszlop méretezéséhez (dupla kattintás: alapméret)"></div>' +
			'<section class="ml-list"><div class="ml-qw' + (M.sync && !S.demo ? ' rf' : '') + '"><input type="search" class="ml-q" placeholder="Keresés a levelek között" value="' + esc(S.mq || '') + '">' + (M.sync && !S.demo ? '<button type="button" class="ml-rf" data-rf aria-label="Levelek letöltése most" title="Levelek letöltése most (magától is frissül: a postafiókból 5 percenként, a lista percenként)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.3-4.9L4 8"/><path d="M4 3v5h5"/><path d="M4 13a8 8 0 0 0 14.3 4.9L20 16"/><path d="M20 21v-5h-5"/></svg></button>' : '') + '</div>' +
			/* v1.60: mobilon mappa-választó (a mappaoszlop helyett) és rendezés */
			'<div class="ml-mbar"><select class="ml-fsel" data-fsel aria-label="Mappa">' + F.map(f => '<option value="' + f[0] + '"' + (S.folder === f[0] ? ' selected' : '') + '>' + f[1] + (counts[f[0]] ? ' (' + counts[f[0]] + ')' : '') + '</option>').join('') + '' + (demoOk() ? '<option value="__demo">' + (S.demo ? '✓ ' : '') + 'Minta megtekintése</option>' : '') + '</select>' +
			((S.tags || []).length ? '<select class="ml-tsel" data-tsel aria-label="Címke"><option value="">Minden címke</option>' + S.tags.map(t => '<option value="' + esc(t.n) + '"' + ((S.tg || []).length === 1 && S.tg[0].toLowerCase() === t.n.toLowerCase() ? ' selected' : '') + '>' + esc(t.n) + (t.cnt ? ' (' + t.cnt + ')' : '') + '</option>').join('') + '</select>' : '') + '<select class="ml-ssel" data-sort aria-label="Rendezés"><option value="new"' + (S.so === 'new' ? ' selected' : '') + '>Legújabb</option><option value="old"' + (S.so === 'old' ? ' selected' : '') + '>Legrégebbi</option></select></div>' +
			'<div class="ml-tabs">' + [['open', 'Nyitott'], ['unassigned', 'Kiosztatlan'], ['all', 'Mind']].map(x => '<button type="button" data-tab="' + x[0] + '" class="' + (S.tab === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') +
				/* v1.88: „Csak olvasatlan” gyorsszűrő – bármelyik füllel kombinálható */
				'<button type="button" class="ml-unf' + (S.unf ? ' on' : '') + '" data-unf aria-pressed="' + !!S.unf + '" title="Csak az olvasatlan levelek">Olvasatlan' + (un[S.folder] ? ' <b>' + un[S.folder] + '</b>' : '') + '</button></div>' + tagBar() + '<div class="ml-selbar" data-selbar></div>' +
			'<div class="ml-items">' + (rows.length ? rows.map(t => '<div role="button" tabindex="0" class="ml-item' + (S.th === t.id ? ' on' : '') + (t.st === 'closed' ? ' closed' : '') + (t.un ? ' un' : '') + (S.sel.has(t.id) ? ' ck' : '') + '" data-th="' + t.id + '">' +
				'<span class="ml-ckw" data-ck role="checkbox" aria-checked="' + S.sel.has(t.id) + '" aria-label="Kijelölés" title="Kijelölés (Shift: tartomány)"><span class="ml-av" style="background:' + avc(t.cn) + '">' + esc(ini(t.cn)) + '</span><i class="ml-cb"></i></span><span class="ml-ib">' +
				'<span class="ml-i1"><b>' + esc(t.cn || t.ce) + '</b>' + (t.as ? '<span class="ml-as">› ' + esc(pname(t.as) || 'kolléga') + '</span>' : '') + '<small>' + esc(ago(t.at)) + '</small></span>' +
				'<span class="ml-i2">' + tgChips(t.tags, 2) + (t.dr ? '<span class="ml-drc" title="Van félbehagyott válaszod">✎ Piszkozat</span>' : '') + (t.sz ? '<span class="ml-szc" title="Szundi eddig">⏰ ' + esc(szLabel(t.sz)) + '</span>' : '') + (t.att ? CLIP_I : '') + (t.st === 'closed' ? '✓ ' : '') + esc(t.subj) + '</span>' +
				'<span class="ml-i3">' + (t.note ? '<span class="ml-inote">' + NOTE_I + esc(t.note.b.replace(/\s+/g, ' ')) + '</span>' : '<span>' + esc(t.snip) + '</span>') + (t.cnt ? '<i>' + t.cnt + '</i>' : '') + '</span></span>' + (t.id > 0 ? '<span class="ml-cl" data-cl role="button" tabindex="-1" title="Link másolása" aria-label="Link másolása">' + LINK_I + '</span>' : '') + '</div>').join('') :
				'<div class="ml-empty"><b>' + (S.unf ? 'Nincs olvasatlan levél' : M.connected ? 'Nincs levél' : 'Még nincsenek levelek') + '</b><span>' + (M.connected || S.unf ? '' : 'A postafiókok bekötése után itt jelennek meg a beérkező levelek, deal-ekhez és kollégákhoz rendelve. Addig a „Minta megtekintése” kapcsolóval kipróbálhatod a nézetet.') + '</span></div>') + (S.more && !S.demo ? '<button type="button" class="ml-more" data-more>Régebbi levelek betöltése</button>' : '') + '</div></section><div class="ml-split" data-split="c2" title="Húzd az oszlop méretezéséhez (dupla kattintás: alapméret)"></div>' +
			'<section class="ml-read" data-read></section><button type="button" class="ml-fab" data-fab aria-label="Új levél" title="Új levél"><svg viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 6l4 4"/></svg></button></div>';
		layout(box);
		if (S.folder === 'draft' && !S.demo) drawDrafts(box);
		const pick = (sel, b) => { box.querySelectorAll(sel).forEach(x => x.classList.toggle('on', x === b)); };
		box.querySelectorAll('[data-f]').forEach(b => b.addEventListener('click', () => { pick('[data-f]', b); S.folder = b.dataset.f; S.th = null; S.lim = 100; drawInbox(box); }));
		const mb = box.querySelector('[data-more]');
		if (mb) mb.addEventListener('click', () => { const it = box.querySelector('.ml-items'), y = it ? it.scrollTop : 0; mb.disabled = true; mb.textContent = 'Betöltés…'; S.lim = (S.lim || 100) + 200; drawInbox(box).then(() => { const n = box.querySelector('.ml-items'); if (n) n.scrollTop = y; }); });
		box.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => { pick('[data-tab]', b); S.tab = b.dataset.tab; drawInbox(box); }));
		box.querySelector('.ml-box').addEventListener('change', e => { S.box = e.target.value; drawInbox(box); });
		box.querySelector('[data-unf]').addEventListener('click', () => { S.unf = !S.unf; S.th = null; drawInbox(box); });
		/* v2.9.25: címke-szűrő (oldalsáv, szűrőcsík, mobil lenyíló) és kezelő */
		const tgGo = l => { S.tg = l; S.th = null; S.lim = 100; drawInbox(box); };
		box.querySelectorAll('[data-tg]').forEach(b => b.addEventListener('click', () => { const n = b.dataset.tg, on = (S.tg || []).some(x => x.toLowerCase() === n.toLowerCase()); tgGo(on ? S.tg.filter(x => x.toLowerCase() !== n.toLowerCase()) : (S.tg || []).concat([n])); }));
		box.querySelectorAll('[data-tgx]').forEach(b => b.addEventListener('click', () => tgGo(S.tg.filter(x => x !== b.dataset.tgx))));
		const tclr = box.querySelector('[data-tgclr]'); if (tclr) tclr.addEventListener('click', () => tgGo([]));
		const tmore = box.querySelector('[data-tgmore]'); if (tmore) tmore.addEventListener('click', () => { S.tgAll = !S.tgAll; drawInbox(box); });
		const tsel = box.querySelector('[data-tsel]'); if (tsel) tsel.addEventListener('change', () => tgGo(tsel.value ? [tsel.value] : []));
		box.querySelector('[data-tgman]').addEventListener('click', () => tagManager(box));
		const rf = box.querySelector('[data-rf]');
		if (rf) rf.addEventListener('click', async () => {
			if (rf.classList.contains('spin')) return;
			rf.classList.add('spin');
			try { const r = await post('cp_mail_fetch_now', {}); S.lc = {}; S.lcAt = {}; toast(r.new ? r.new + ' új levél érkezett.' : 'Nincs új levél – minden letöltve.'); }
			catch (e) { toast(e.message, true); }
			if (box.isConnected) drawInbox(box);
		});
		/* v1.88: legyintés a listában (mobil): jobbra olvasott/olvasatlan, balra archiválás (Archív/Kuka/Spam mappában: visszaállítás) */
		if (isMob()) box.querySelectorAll('.ml-item[data-th]').forEach(b => swipeRow(box, b));
		if (box.querySelector('[data-demo]')) box.querySelector('[data-demo]').addEventListener('change', e => { S.demo = e.target.checked; S.th = null; drawInbox(box); });
		let qt = null;
		box.querySelector('.ml-q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { S.mq = e.target.value; drawInbox(box).then(() => { const i = box.querySelector('.ml-q'); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }); }, 350); });
		box.querySelector('[data-compose]').addEventListener('click', () => { S.view = 'compose'; view(S.main); });
		box.querySelector('[data-fab]').addEventListener('click', () => { S.view = 'compose'; view(S.main); });
		box.querySelector('[data-fsel]').addEventListener('change', e => { const v = e.target.value; if (v === '__demo') { S.demo = !S.demo; } else { S.folder = v; } S.th = null; S.reading = false; S.lim = 100; drawInbox(box); });
		box.querySelector('[data-sort]').addEventListener('change', e => { S.so = e.target.value; try { localStorage.setItem('cpmail_so', S.so); } catch (x) { /* privát mód */ } drawInbox(box); });
		box.querySelectorAll('[data-th]').forEach(b => {
			const go = () => { S.th = Number(b.dataset.th); box.querySelectorAll('.ml-item').forEach(x => x.classList.toggle('on', x === b)); b.classList.remove('un'); const r = S.rows.find(x => x.id === S.th); if (r) r.un = 0; if (S.sel.size) { S.sel.clear(); selUI(box); } openThread(box, S.th); };
			const cl = b.querySelector('[data-cl]');
			if (cl) cl.addEventListener('click', e => { e.stopPropagation(); copyLink(Number(b.dataset.th)); });
			b.addEventListener('click', e => { if (e.target.closest('[data-ck]') || e.target.closest('[data-cl]')) return; if (e.ctrlKey || e.metaKey) { toggleSel(box, Number(b.dataset.th), false); return; } go(); });
			b.addEventListener('keydown', e => { if (e.target !== b) return; if (e.key === 'Enter') { e.preventDefault(); go(); } if (e.key === ' ') { e.preventDefault(); toggleSel(box, Number(b.dataset.th), e.shiftKey); } });
			b.querySelector('[data-ck]').addEventListener('click', e => { e.stopPropagation(); toggleSel(box, Number(b.dataset.th), e.shiftKey); });
		});
		selUI(box);
		const rd = box.querySelector('[data-read]');
		if (S.sel.size) return;
		if (S.th && (rows.some(t => t.id === S.th) || S.forceTh === S.th)) { S.forceTh = 0; openThread(box, S.th); }
		else rd.innerHTML = '<div class="ml-empty"><b>Válassz egy levelet</b><span>Itt jelenik meg a levélváltás, fölötte a hozzá tartozó deal kártyája és a kollégák jegyzetei; alul írhatsz jegyzetet, @ jellel megemlítve a kollégákat.</span></div>';
	}

	/* ------------------------------------------------------------ v1.47: kijelölés és tömeges műveletek (csak a panelben hat) */
	function toggleSel(box, id, range) {
		const ids = S.rows.map(t => t.id);
		if (range && S.last && ids.includes(S.last)) {
			const a = ids.indexOf(S.last), b = ids.indexOf(id), on = !S.sel.has(id);
			ids.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(x => { if (on) S.sel.add(x); else S.sel.delete(x); });
		} else if (S.sel.has(id)) S.sel.delete(id); else S.sel.add(id);
		S.last = id;
		if (!S.sel.size) { drawInbox(box); return; }
		selUI(box);
	}
	function selUI(box) {
		const n = S.sel.size, inb = box.querySelector('.ml-inbox');
		if (!inb) return;
		inb.classList.toggle('sel', n > 0);
		box.querySelectorAll('.ml-item').forEach(b => { const on = S.sel.has(Number(b.dataset.th)); b.classList.toggle('ck', on); const c = b.querySelector('[data-ck]'); if (c) c.setAttribute('aria-checked', String(on)); });
		const bar = box.querySelector('[data-selbar]');
		bar.innerHTML = n ? '<button type="button" class="ml-sx" data-sclr title="Kijelölés megszüntetése">×</button><b>' + n + ' kijelölve</b><button type="button" class="ml-link" data-sall>' + (n === S.rows.length ? 'Egyik sem' : 'Mind kijelölése (' + S.rows.length + ')') + '</button>' : '';
		if (!n) return;
		bar.querySelector('[data-sclr]').addEventListener('click', () => { S.sel.clear(); drawInbox(box); });
		bar.querySelector('[data-sall]').addEventListener('click', () => { if (S.sel.size === S.rows.length) { S.sel.clear(); drawInbox(box); return; } S.rows.forEach(t => S.sel.add(t.id)); selUI(box); });
		const rd = box.querySelector('[data-read]');
		const sel = S.rows.filter(t => S.sel.has(t.id));
		const inBin = ['trash', 'spam', 'archive', 'promo', 'snooze'].includes(S.folder);
		const B = (op, t, cls) => '<button type="button" class="ml-sb' + (cls ? ' ' + cls : '') + '" data-op="' + op + '">' + t + '</button>';
		rd.innerHTML = '<div class="ml-selp"><div class="ml-selico">✉<i>✓</i></div><h3>' + n + ' levélszál kijelölve</h3>' +
			'<div class="ml-sbs">' + B('read', '✉ Olvasottnak jelölés') + B('unread', '● Olvasatlannak jelölés') + B('tag', '🏷 Címke') +
			(sel.some(t => t.st !== 'closed') ? B('close', '✓ Lezárás') : '') + (sel.some(t => t.st === 'closed') ? B('open', '↺ Újranyitás') : '') +
			'<label class="ml-sb ml-sbas">👤 Kiosztás <select data-sas><option value="">– válassz –</option><option value="0">Kiosztatlan</option>' + PEOPLE.map(p => '<option value="' + p.id + '">' + esc(p.n) + '</option>').join('') + '</select></label>' +
			B('snooze', '⏰ Szundi') + (inBin ? B('restore', S.folder === 'promo' ? '📥 Beérkezettbe' : '↩ Visszaállítás') : B('archive', '🗄 Archiválás')) + (S.folder !== 'spam' ? B('spam', '⚠ Spam') : '') + (S.folder !== 'trash' ? B('trash', '🗑 Kukába', 'bad') : '') + '</div>' +
			'<p class="cpx-muted">Ezek csak a panelben hatnak: a postafiókban és a Salesmate-ben semmi nem változik, a Kukából bármikor visszaállítható.</p>' +
			'<button type="button" class="ml-link" data-scancel>Mégse</button></div>';
		rd.querySelector('[data-scancel]').addEventListener('click', () => { S.sel.clear(); drawInbox(box); });
		rd.querySelectorAll('[data-op]').forEach(b => b.addEventListener('click', () => {
			const op = b.dataset.op;
			if (op === 'tag') { tagPicker(b, '', nm => bulk(box, Array.from(S.sel), 'tag', nm), true); return; }
			if (op === 'snooze') { snzMenu(b).then(ts => { if (ts) bulk(box, Array.from(S.sel), 'snooze', String(ts)); }); return; }
			bulk(box, Array.from(S.sel), op, '');
		}));
		rd.querySelector('[data-sas]').addEventListener('change', e => { if (e.target.value !== '') bulk(box, Array.from(S.sel), 'assign', e.target.value); });
	}
	const OPMSG = { snooze: 'szundira téve', read: 'olvasottnak jelölve', unread: 'olvasatlannak jelölve', tag: 'címkézve', untag: 'címke nélkül', close: 'lezárva', open: 'újranyitva', assign: 'kiosztva', archive: 'archiválva', trash: 'a Kukába téve', spam: 'a Spambe téve', restore: 'visszaállítva' };
	function swipeRow(box, b) {
		const id = Number(b.dataset.th), t = (S.rows || []).find(x => x.id === id);
		if (!t) return;
		const back = ['archive', 'trash', 'spam', 'promo', 'snooze'].includes(S.folder), LIM = 90;
		let x0 = 0, y0 = 0, dx = 0, mode = 0, bg = null, swiped = 0;
		const R = '<span>' + (t.un ? '✓ Olvasott' : '● Olvasatlan') + '</span>', Lt = '<span>' + (back ? (S.folder === 'promo' ? '📥 Beérkezettbe' : '↺ Visszaállítás') : '🗄 Archiválás') + '</span>';
		const reset = () => { b.style.transition = 'transform .18s'; b.style.transform = ''; setTimeout(() => { if (bg) { bg.remove(); bg = null; } b.classList.remove('sw'); b.style.transition = ''; }, 190); };
		b.addEventListener('touchstart', e => { if (e.touches.length !== 1) return; x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; dx = 0; mode = 0; b.style.transition = 'none'; }, { passive: true });
		b.addEventListener('touchmove', e => {
			if (mode === -1 || e.touches.length !== 1) return;
			const X = e.touches[0].clientX - x0, Y = e.touches[0].clientY - y0;
			if (!mode) {
				if (Math.abs(X) < 10 && Math.abs(Y) < 10) return;
				mode = Math.abs(X) > Math.abs(Y) * 1.3 ? 1 : -1;
				if (mode === -1) return;
				bg = document.createElement('div'); bg.className = 'ml-swbg'; bg.style.top = b.offsetTop + 'px'; bg.style.height = b.offsetHeight + 'px';
				b.parentNode.insertBefore(bg, b); b.classList.add('sw');
			}
			e.preventDefault();
			dx = X;
			b.style.transform = 'translateX(' + dx + 'px)';
			bg.className = 'ml-swbg ' + (dx > 0 ? 'r' : 'l' + (back ? ' bk' : '')) + (Math.abs(dx) > LIM ? ' go' : '');
			bg.innerHTML = dx > 0 ? R : Lt;
		}, { passive: false });
		const end = () => {
			if (mode !== 1) return;
			mode = 0; swiped = Date.now();
			if (Math.abs(dx) <= LIM) { reset(); return; }
			if (dx > 0) { reset(); bulk(box, [id], t.un ? 'read' : 'unread', ''); return; }
			b.style.transition = 'transform .2s'; b.style.transform = 'translateX(-110%)';
			setTimeout(() => bulk(box, [id], back ? 'restore' : 'archive', ''), 200);
		};
		b.addEventListener('touchend', end); b.addEventListener('touchcancel', () => { if (mode === 1) { mode = 0; reset(); } });
		/* a legyintés után ne nyíljon meg a levél */
		b.addEventListener('click', e => { if (Date.now() - swiped < 400) { e.stopImmediatePropagation(); e.preventDefault(); } }, true);
	}
	/* v2.9: Piszkozatok mappa – a saját, szerveren tárolt piszkozatok */
	async function openDraft(id, thread) {
		try { const r = await post('cp_mail_draft_get', id ? { id: id } : { thread: thread }); S.reply = r.d; S.draftId = r.id; S.view = 'compose'; if (window.CPXGo) window.CPXGo('mail'); else view(S.main); } catch (e) { toast(e.message, true); }
	}
	async function drawDrafts(box) {
		const it = box.querySelector('.ml-items'); if (!it) return;
		it.innerHTML = '<div class="ml-empty"><span>Betöltés…</span></div>';
		let r; try { r = await post('cp_mail_draft_list', {}); } catch (e) { it.innerHTML = '<div class="ml-empty err">' + esc(e.message) + '</div>'; return; }
		if (!it.isConnected || S.folder !== 'draft') return;
		const rows = r.rows || [];
		it.innerHTML = rows.length ? rows.map(d => '<div role="button" tabindex="0" class="ml-item ml-ditem" data-dr="' + esc(d.id) + '"><span class="ml-ib"><span class="ml-i1"><b>' + esc(d.to.length ? d.to.join(', ') : '(nincs címzett)') + '</b><small>' + esc(ago(d.at)) + '</small></span><span class="ml-i2"><span class="ml-drc">✎ Piszkozat</span>' + esc(d.subject || '(nincs tárgy)') + '</span><span class="ml-i3"><span>' + esc(d.snip || '') + '</span></span></span><button type="button" class="ml-ddel" data-drdel title="Piszkozat elvetése">🗑</button></div>').join('')
			: '<div class="ml-empty"><b>Nincs piszkozatod</b><span>A félbehagyott levelek maguktól ide kerülnek (gépelés közben a panel menti őket).</span></div>';
		it.querySelectorAll('[data-dr]').forEach(el => el.addEventListener('click', e => {
			if (e.target.closest('[data-drdel]')) { e.stopPropagation(); if (!window.confirm('Elveted ezt a piszkozatot?')) return; post('cp_mail_draft_del', { id: el.dataset.dr }).then(() => { el.remove(); toast('Piszkozat elvetve.'); }).catch(x => toast(x.message, true)); return; }
			openDraft(el.dataset.dr);
		}));
	}
	/* v2.8.2: szundi – időpont-választó */
	const pad2 = n => String(n).padStart(2, '0');
	function szLabel(ts) {
		const d = new Date(ts * 1000), n = new Date(), t0 = new Date(n.getFullYear(), n.getMonth(), n.getDate()).getTime(), dd = Math.round((new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime() - t0) / 86400000);
		const hm = pad2(d.getHours()) + ':' + pad2(d.getMinutes());
		return dd === 0 ? 'ma ' + hm : dd === 1 ? 'holnap ' + hm : (dd > 1 && dd < 7 ? ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'][d.getDay()] + ' ' + hm : d.getFullYear() + '.' + pad2(d.getMonth() + 1) + '.' + pad2(d.getDate()) + '. ' + hm);
	}
	function snzMenu(anchor) {
		return new Promise(res => {
			document.querySelectorAll('.ml-snzp').forEach(x => x.remove());
			const n = new Date(), at = (days, h, m) => { const d = new Date(n.getFullYear(), n.getMonth(), n.getDate() + days, h, m || 0); return Math.floor(d.getTime() / 1000); };
			const dow = n.getDay(), toMon = ((8 - dow) % 7) || 7;
			const opts = [];
			if (n.getHours() < 16) opts.push(['Ma délután', at(0, 17)]);
			opts.push(['Holnap reggel', at(1, 9)]);
			if (dow >= 1 && dow <= 3) opts.push(['Péntek reggel', at(5 - dow, 9)]);
			opts.push(['Jövő hétfő', at(toMon, 9)]);
			opts.push(['1 hét múlva', at(7, 9)]);
			opts.push(['1 hónap múlva', Math.floor(new Date(n.getFullYear(), n.getMonth() + 1, n.getDate(), 9).getTime() / 1000)]);
			const p = document.createElement('div');
			p.className = 'ml-snzp';
			p.innerHTML = '<b>⏰ Szundi eddig:</b>' + opts.map((o, i) => '<button type="button" data-i="' + i + '"><span>' + esc(o[0]) + '</span><small>' + esc(szLabel(o[1])) + '</small></button>').join('') +
				'<label>Egyéni:<input type="datetime-local" data-dt></label><button type="button" data-ok class="ok">Szundi</button>';
			document.body.appendChild(p);
			const r = anchor.getBoundingClientRect(), w = 250;
			if (!r.width) { p.style.left = Math.max(8, (window.innerWidth - w) / 2) + 'px'; p.style.top = Math.max(60, window.innerHeight * 0.25) + 'px'; }
			else { p.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.left)) + 'px'; p.style.top = Math.max(8, Math.min(window.innerHeight - 330, r.bottom + 6)) + 'px'; }
			let done = false;
			const fin = v => { if (done) return; done = true; p.remove(); document.removeEventListener('mousedown', off, true); res(v); };
			const off = e => { if (!p.contains(e.target)) fin(null); };
			setTimeout(() => document.addEventListener('mousedown', off, true), 0);
			p.querySelectorAll('[data-i]').forEach(b => b.addEventListener('click', () => fin(opts[Number(b.dataset.i)][1])));
			p.querySelector('[data-ok]').addEventListener('click', () => { const v = p.querySelector('[data-dt]').value; if (!v) { p.querySelector('[data-dt]').focus(); return; } const ts = Math.floor(new Date(v).getTime() / 1000); if (ts > Date.now() / 1000 + 60) fin(ts); else toast('Jövőbeli időpontot válassz.', true); });
			p.addEventListener('keydown', e => { if (e.key === 'Escape') fin(null); });
		});
	}
	async function bulk(box, ids, op, v) {
		let n = 0;
		try {
			const live = ids.filter(id => id > 0);
			if (live.length) n += (await post('cp_mail_bulk', { ids: live.join(','), op: op, v: v })).n;
			ids.filter(id => id < 0).forEach(id => {
				const t = S.demoRows.find(x => x.id === id); if (!t) return; n++;
				if (op === 'read') t.un = 0; if (op === 'unread') t.un = 1; if (op === 'close') t.st = 'closed'; if (op === 'open') t.st = 'open'; if (op === 'assign') t.as = Number(v) || 0;
				if (op === 'tag' && !tgList(t.tags).some(x => x.toLowerCase() === v.toLowerCase())) t.tags = (t.tags ? t.tags + ', ' : '') + v;
				if (['archive', 'trash', 'spam'].includes(op)) { t.prev = t.folder || 'inbox'; t.folder = op; }
				if (op === 'restore') { t.folder = t.prev && !['archive', 'trash', 'spam'].includes(t.prev) ? t.prev : 'inbox'; t.st = 'open'; }
			});
		} catch (e) { toast(e.message, true); return; }
		const msg = ids.length === 1 ? 'Levélszál ' + OPMSG[op] + '.' : n + ' levélszál ' + OPMSG[op] + '.';
		/* v2.7.13: archiválás / Kuka / Spam visszavonható */
		if (['archive', 'trash', 'spam', 'snooze'].includes(op)) toastAct(msg + (op === 'snooze' ? ' (' + szLabel(Number(v)) + ')' : ''), 'Visszavonás', () => bulk(box, ids, 'restore', '')); else toast(msg);
		S.sel.clear();
		if (['archive', 'trash', 'spam', 'restore', 'unread', 'snooze'].includes(op) && ids.includes(S.th)) S.th = null;
		drawInbox(box);
	}

	/* a szál műveletei: élesben a szerverre, mintában helyben */
	async function thAct(id, act, p) {
		if (id > 0) return post(act, Object.assign({ id: id, thread: id }, p));
		const t = S.demoRows.find(x => x.id === id);
		if (act === 'cp_mail_note_add') { const me = PEOPLE.find(x => x.id === M.me) || PEOPLE[0] || { id: 0, n: 'Te' }; const n = { id: -Date.now(), body: p.body, author: me.n, author_uid: me.id, created_at: new Date().toISOString().slice(0, 16).replace('T', ' ') }; t._notes.push(n); t.note = { b: n.body, by: n.author, at: n.created_at }; }
		if (act === 'cp_mail_note_del') { t._notes = t._notes.filter(n => n.id !== Number(p.nid)); const l = t._notes[t._notes.length - 1]; t.note = l ? { b: l.body, by: l.author, at: l.created_at } : null; }
		const evt = txt => { const me = PEOPLE.find(x => x.id === M.me) || PEOPLE[0] || { n: 'Te' }; t._ev = (t._ev || []).concat([{ id: -Date.now(), text: txt, author: me.n, created_at: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16).replace('T', ' ') }]); };
		if (act === 'cp_mail_thread_set') {
			if (p.f === 'status' && p.v !== t.st) evt(p.v === 'closed' ? 'Levélszál lezárva' : 'Levélszál újranyitva');
			if (p.f === 'assignee' && (Number(p.v) || 0) !== t.as) evt(Number(p.v) ? 'Kiosztva: ' + pname(Number(p.v)) : 'Felelős eltávolítva');
			if (p.f === 'deal') evt(p.v ? 'Deal csatolva: ' + ((p._deal || {}).title || '') : 'Deal leválasztva');
			if (p.f === 'tags' && p.v !== t.tags) evt(p.v ? 'Címkék: ' + p.v : 'Címkék törölve');
			if (p.f === 'status') t.st = p.v; if (p.f === 'assignee') t.as = Number(p.v) || 0; if (p.f === 'tags') t.tags = p.v;
			if (p.f === 'follow') t.fol = p.v === '1' ? Array.from(new Set(t.fol.concat([M.me || 1]))) : t.fol.filter(x => x !== (M.me || 1));
			if (p.f === 'deal') t._deal = p.v ? p._deal : null;
		}
		return { t: t, msgs: t._msgs, notes: t._notes, events: t._ev || [], deal: t._deal };
	}

	/* v1.86.1: mobilon a megnyitott levél előzménylépést kap, így a visszalegyintés / Android vissza gomb a listára visz, nem lép ki */
	const isMob = () => !!(window.matchMedia && window.matchMedia('(max-width:760px)').matches);
	window.addEventListener('popstate', () => {
		if (S.reading && !(history.state && history.state.cpMailTh) && typeof S.closeTh === 'function' && document.querySelector('.ml-inbox.reading')) S.closeTh();
	});
	async function openThread(box, id) {
		if (isMob() && !(history.state && history.state.cpMailTh)) history.pushState(Object.assign({}, history.state || {}, { cpMailTh: id }), '', location.href);
		const rd = box.querySelector('[data-read]');
		S.reading = true;
		const ib = box.querySelector('.ml-inbox'); if (ib) ib.classList.add('reading');
		if (window.matchMedia && window.matchMedia('(max-width:760px)').matches) window.scrollTo(0, 0);
		rd.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
		let x;
		try { x = id > 0 ? await post('cp_mail_thread', { id: id }) : await thAct(id, 'view', {}); } catch (e) { if (rd.isConnected && S.th === id) rd.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		/* közben másik levelet nyitottak meg, vagy újrarajzolódott a fül: ez a válasz már elavult */
		if (!rd.isConnected || S.th !== id) return;
		drawThread(box, rd, x);
	}

	function drawThread(box, rd, x) {
		const t = x.t, d = x.deal;
		/* v2.7.8: összecsukható levelek – alapból csak a legutóbbi nyitott; kattintásra nyílik / csukódik, „Összes kinyitása” gomb */
		const MX = (S.mx = S.mx || {}), mk = m => String(m.id || m.sent_at || ''), msgs0 = (x.msgs || []).slice();
		const newest = msgs0.reduce((a, b) => (!a || String(b.sent_at || '') >= String(a.sent_at || '') ? b : a), null);
		const XS = MX[t.id] || (MX[t.id] = { def: null, o: {} });
		const isOpen = m => msgs0.length <= 1 ? true : (Object.prototype.hasOwnProperty.call(XS.o, mk(m)) ? XS.o[mk(m)] : (XS.def !== null ? XS.def : m === newest));
		const snip = h => { try { return (new DOMParser().parseFromString(String(h || '').replace(/<(style|script)[\s\S]*?<\/\1>/gi, ''), 'text/html').body.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 180); } catch (e) { return ''; } };
		const allOpen = () => msgs0.every(isOpen);
		PR.known[t.id] = msgs0.reduce((a, m) => Math.max(a, Number(m.id) || 0), 0);
		setTimeout(presPing, 50);
		const me = M.me || (PEOPLE[0] || {}).id || 0;
		const following = (t.fol || []).includes(me);
		const money = v => (v == null ? '–' : Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft');
		/* v1.85: mobilon a Salesmate-hez hasonló fejléc – vissza, kiosztás, követés, „⋮” lebegő menü (a régi eszköztár rejtve) */
		const IC = p => '<svg viewBox="0 0 24 24" aria-hidden="true">' + p + '</svg>';
		rd.innerHTML = '<div class="ml-backbar"><button type="button" data-back aria-label="Vissza">‹ Vissza</button><span>' + esc(t.subj) + '</span>' +
			'<button type="button" class="ml-bi' + (t.as ? ' on' : '') + '" data-mas aria-label="Kiosztás" title="Kiosztás: ' + esc(pname(t.as) || 'kiosztatlan') + '">' + IC('<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M17 8h5M19.5 5.5v5"/>') + '</button>' +
			'<button type="button" class="ml-bi' + (following ? ' on' : '') + '" data-mfo aria-label="Követés" title="' + (following ? 'Követed: értesítést kapsz az új levelekről – koppints a leállításhoz' : 'Követés: értesítést kapsz, ha új levél jön ebbe a szálba') + '">' + IC('<path d="M6 9a6 6 0 0 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9z"/><path d="M10 20a2 2 0 0 0 4 0"/>') + '</button>' +
			'<button type="button" class="ml-bi" data-mmn aria-label="Továbbiak" title="Továbbiak">' + IC('<circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/>') + '</button></div>' +
			'<div class="ml-qa ml-mm" data-mm hidden><div class="ml-qab" data-mmx></div><div class="ml-qas" data-mms></div></div><button type="button" class="ml-tfab" data-tfab aria-label="Gyors műveletek" title="Gyors műveletek"><svg viewBox="0 0 24 24"><path d="M5 12h14M12 5v14"/></svg></button><div class="ml-qa" data-qa hidden><div class="ml-qab" data-qax></div><div class="ml-qas"><div class="ml-qah"><b>Gyors műveletek</b><button type="button" data-qax aria-label="Bezárás">✕</button></div><button type="button" data-qn>' + NOTE_I + 'Jegyzet hozzáadása</button><button type="button" data-qr' + ((M.send && M.send.on) ? '' : ' disabled') + '>' + RE_I + 'Válasz</button><button type="button" data-qra' + ((M.send && M.send.on) ? '' : ' disabled') + '>' + REA_I + 'Válasz mindenkinek</button><button type="button" data-qf' + ((M.send && M.send.on) ? '' : ' disabled') + '><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 6l6 6-6 6"/><path d="M20 12H9a5 5 0 0 0-5 5v1"/></svg>Továbbítás</button>' + ((M.send && M.send.on) ? '' : '<small>A panelből küldés neked még nincs bekapcsolva.</small>') + '</div></div><div class="ml-rtool"><button type="button" data-st>' + (t.st === 'closed' ? TI.re + 'Újranyitás' : TI.ok + 'Lezárás') + '</button><button type="button" data-tag>' + TI.tag + 'Címke' + '</button>' + (M.ai && t.id > 0 ? '<button type="button" class="ml-aibtn" data-ai title="Válaszpiszkozat az AI-val (átnézed, mielőtt elküldöd)">' + TI.ai + 'AI válasz</button>' : '') + (window.CPKanban && t.id > 0 ? '<button type="button" data-todo title="Teendő felvétele a Feladatokba">' + TI.todo + 'Teendő</button>' : '') +
			(t.id > 0 ? '<button type="button" class="ml-clb" data-cl title="Link másolása (a levél közvetlen linkje)">' + LINK_I + '</button>' : '') + '<button type="button" data-one="unread" title="Olvasatlannak jelölés" aria-label="Olvasatlannak jelölés">' + TI.unread + '</button>' + '<button type="button" data-snz title="Szundi – a megadott időpontban visszajön a Beérkezettbe (billentyű: s)" aria-label="Szundi">' + TI.clock + '</button>' + (['trash', 'spam', 'archive', 'promo', 'snooze'].includes(t.folder) ? '<button type="button" data-one="restore" title="Visszaállítás a Beérkezettbe" aria-label="Visszaállítás">' + TI.back + '</button>' : '<button type="button" data-one="archive" title="Archiválás (csak a panelben)" aria-label="Archiválás">' + TI.arch + '</button><button type="button" data-one="trash" title="Kukába (csak a panelben; visszaállítható)" aria-label="Kukába">' + TI.trash + '</button>') +
			'<span class="crm-bgap"></span><span class="ml-fols">' + (t.fol || []).slice(0, 3).map(f => '<i style="background:' + avc(pname(f)) + '" title="' + esc(pname(f)) + '">' + esc(ini(pname(f))) + '</i>').join('') + ((t.fol || []).length ? '' : '<small>Nincs követő</small>') + '</span>' +
			'<select data-as aria-label="Felelős"><option value="">Kiosztatlan</option>' + PEOPLE.map(p => '<option value="' + p.id + '"' + (t.as === p.id ? ' selected' : '') + '>' + esc(p.n) + '</option>').join('') + '</select>' +
			'<label class="ml-sw"><input type="checkbox" data-fol' + (following ? ' checked' : '') + '><span></span> Követés</label></div>' +
			(S.fromDeal && S.fromDeal.th === S.th ? '<div class="ml-fromdeal"><button type="button" class="cpx-btn ghost" data-fromdeal style="margin:0 0 8px">← Vissza a dealhez (#' + Number(S.fromDeal.deal) + ')</button></div>' : '') +
			'<div class="ml-subjh"><h3>' + esc(t.subj) + '</h3><button type="button" class="ml-link" data-ord title="A levelek sorrendje a szálban">' + (newFirst() ? '↓ Legújabb felül' : '↑ Legrégebbi felül') + '</button>' + (d ? '' : '<button type="button" class="ml-link" data-attach>$ Deal csatolása</button>') + (msgs0.length > 1 ? '<button type="button" class="ml-link" data-xall title="A szál összes levelének kinyitása / összecsukása">' + (allOpen() ? '⇡ Összes összecsukása' : '⇣ Összes kinyitása') + '</button>' : '') + '</div>' + (tgList(t.tags).length ? '<div class="ml-ttags">' + tgList(t.tags).map(n => tgChip(n, 'data-ttx')).join('') + '<button type="button" class="ml-tadd" data-tag2 aria-label="Címke hozzáadása">+ Címke</button></div>' : '') + '<div class="ml-pres" data-pres hidden></div><div data-dpat hidden class="ml-dpat"></div><div data-kbq hidden class="ml-dpat"></div><div data-aip hidden class="ml-aip"></div><div data-kbl class="ml-kbl"></div>' +
			'<div class="ml-thread">' +
			(d ? '<div class="ml-dcard">' + DEAL_I + '<div><a href="#deals" data-opendeal="' + (d.id || '') + '">' + esc(d.title) + '</a><small>' + [money(d.value), d.cn, d.co, d.stage].filter(Boolean).map(esc).join('<i>•</i>') + '</small>' + (d.portal ? '<span class="ml-pchip ' + esc(d.portal.st) + '" title="Ügyfélportál' + (d.portal.deadline ? ' · határidő: ' + esc(d.portal.deadline) : '') + '">Portál ' + d.portal.done + '/' + d.portal.total + '</span>' : '') + '</div><button type="button" class="ml-dx" data-detach aria-label="Deal leválasztása" title="Deal leválasztása">' + X_I + '</button></div>' +
				/* v1.98: lezárt / elvesztett / törölt deal figyelmeztetés, ajánlott nyitott deal */
				(d.warn ? '<div class="ml-dwarn">⚠ ' + esc(d.warn) + (d.alt ? '<button type="button" class="cpx-btn sm" data-dalt="' + Number(d.alt.id) + '">Áthelyezés ide: ' + esc(d.alt.title) + '</button>' : '') + '</div>' : '') : '') +
			/* v2.5: visszatérő ügyfél – a feladó korábbi rendezvényei */
			((S.rows || []).some(r => r.id === t.id && r.dr) ? '<div class="ml-drb">✎ Van félbehagyott válaszod ehhez a szálhoz. <button type="button" data-drcont>Folytatás</button></div>' : '') +
			(x.ret ? '<button type="button" class="ml-ret' + (x.ret.won ? '' : ' lo') + '" data-ret="' + Number(x.ret.ct || 0) + '" title="A feladó kontaktjának megnyitása – minden korábbi deal-je">' + (x.ret.won ? '🔁 <b>Visszatérő ügyfél</b> · ' + x.ret.won + ' megnyert rendezvény' + (x.ret.won_v ? ' (' + esc(money(x.ret.won_v)) + ')' : '') + (x.ret.last_ev ? ' · utoljára ' + esc(x.ret.last_ev.replace(/-/g, '.') + '.') : '') : '↺ Korábban már érdeklődött (' + x.ret.n + ' deal)') + '</button>' : '') +
			(x.notes || []).map(n => '<div class="ml-ncard"><div class="ml-nh"><span class="ml-av" style="background:' + avc(n.author) + '">' + esc(ini(n.author)) + '</span><b>Jegyzet</b> – ' + esc(n.author) + '<small>' + esc(ago(n.created_at)) + '</small>' + (n.author_uid === me || M.admin ? '<button type="button" class="ml-ndel" data-ndel="' + n.id + '" title="Jegyzet törlése">×</button>' : '') + '</div><div class="ml-nb">' + nl2br(n.body) + '</div></div>').join('') +
			timeline(x).map((it, ix) => it.ev ? '<div class="ml-ev"><span class="ml-av ml-av1 ev" style="background:' + avc(it.ev.author) + '">' + esc(ini(it.ev.author).slice(0, 1)) + '</span><span><b>' + esc(it.ev.text) + '</b>' + (it.ev.author ? ' – ' + esc(it.ev.author) : '') + '</span><small>' + esc(ago(it.ev.created_at)) + '</small></div>' : (m => '<article class="ml-msg' + (isOpen(m) ? '' : ' cl') + '" data-mk="' + esc(mk(m)) + '"><div class="ml-mh"><span class="ml-av lg ml-av1' + (m.dir === 'out' ? ' out' : '') + '"' + (m.dir === 'out' ? '' : ' style="background:' + avc(m.from_name) + '"') + '>' + esc(ini(m.from_name).slice(0, 1)) + '</span><div><div class="ml-from"><span>' + esc(m.from_name) + '</span> <span class="ml-fe">&lt;' + esc(m.from_email) + '&gt;</span>' + /* v1.78.6: a nem ellenőrizhető saját levélnél egyetlen sárga jelvény (a magyarázat rámutatva); a többi állapot kék címke */
				(m.status && m.status !== 'idézetből' && m.status !== 'ellenőrizetlen' ? '<span class="ml-dlv">' + esc(m.status) + '</span>' : '') + '</div>' + '<div class="ml-snip">' + esc(snip(m.body)) + '</div>' +
				(m.status === 'idézetből' ? '<div class="ml-unv" title="A levél nincs meg a postafiók Elküldött mappájában – a panel az ügyfél válaszában idézett szövegből állította vissza, ezért a tartalma nem ellenőrizhető.">⚠ Válaszból visszaállítva · ellenőrizetlen</div>' : m.status === 'ellenőrizetlen' ? '<div class="ml-unv" title="A levél a saját címünkről a Beérkezett mappába jött, nem az Elküldött mappából vagy a panelből – a tartalma hamis is lehet.">⚠ Ellenőrizetlen saját levél</div>' : '') + '<div class="ml-to">Címzett: ' + addr(m.to_list) + (m.cc_list ? '<br>Másolat: ' + addr(m.cc_list) : '') + '</div></div><span class="ml-mt"><span class="ml-ma"><button type="button"' + ((M.send && M.send.on) ? ' data-reply="' + ix + '"' : ' disabled') + ' title="Válasz">' + RE_I + '</button><button type="button"' + ((M.send && M.send.on) ? ' data-replyall="' + ix + '"' : ' disabled') + ' title="Válasz mindenkinek">' + REA_I + '</button><button type="button"' + (t.id > 0 ? ' data-mdel' : ' disabled') + ' title="Törlés (a levélszál a Kukába kerül)">' + DEL_I + '</button><button type="button"' + (t.id > 0 ? ' data-mmore="' + ix + '"' : ' disabled') + ' title="Továbbiak">' + MORE_I + '</button></span><span class="ml-md">' + esc(hdate(m.sent_at)) + '</span></span></div>' +
				'<div class="ml-mb"' + (m.id > 0 && m.hh !== 0 && m.status !== 'Elküldve a panelből' && m.status !== 'idézetből' ? ' data-hm="' + m.id + '"' : '') + '>' + (m.status === 'Elküldve a panelből' ? (m.body || '') : tidyBody(m.body)) + '</div>' + ((m.attachments || []).length ? '<div class="ml-atts">' + CLIP_I + ' <b>Mellékletek:</b> ' + m.attachments.map((a, ai) => m.id > 0 ? '<a href="' + esc(attUrl(m.id, ai, a.n)) + '" target="_blank" rel="noopener"' + (attRisk(a.n) ? attRiskAttr(a.n) : ' title="Megnyitás / letöltés"') + '>' + (attRisk(a.n) ? '<span class="ml-ariskb" aria-hidden="true">⚠</span>' : '') + esc(a.n) + '</a>' : '<span>' + esc(a.n) + '</span>').join(', ') + '</div>' : '') + '</article>')(it.m)).join('') +
			'</div>' +
			/* v1.85: mobilon a szál alján válasz-gombok (a lebegő „+” helyett) */
			'<div class="ml-ncomp"><textarea rows="1" data-note placeholder="Jegyzetek – @ jellel megemlítheted a kollégáidat. (Ctrl+Enter: mentés)"></textarea><div class="ml-mpop" hidden></div><button type="button" class="cpx-btn" data-nsave>Jegyzet</button></div>';
		const act = async (a, p) => { try { const r = await thAct(t.id, a, p); drawThread(box, rd, r); refreshItem(box, r.t); } catch (e) { toast(e.message, true); } };
		rd.querySelector('[data-st]').addEventListener('click', () => act('cp_mail_thread_set', { f: 'status', v: t.st === 'closed' ? 'open' : 'closed' }));
		let tcur = tgList(t.tags);
		const tgSet = () => act('cp_mail_thread_set', { f: 'tags', v: tcur.join(', ') });
		const tgOpen = btn => tagPicker(btn, tcur.join(', '), (n, on) => { tcur = on ? tcur.filter(x => x.toLowerCase() !== n.toLowerCase()).concat([n]) : tcur.filter(x => x.toLowerCase() !== n.toLowerCase()); tgSet(); });
		rd.querySelector('[data-tag]').addEventListener('click', e => tgOpen(isMob() ? rd : e.currentTarget));
		const tg2 = rd.querySelector('[data-tag2]'); if (tg2) tg2.addEventListener('click', e => tgOpen(e.currentTarget));
		rd.querySelectorAll('[data-ttx]').forEach(b => b.addEventListener('click', () => { tcur = tcur.filter(x => x !== b.dataset.ttx); tgSet(); }));
		rd.querySelector('[data-as]').addEventListener('change', e => act('cp_mail_thread_set', { f: 'assignee', v: e.target.value }));
		/* v1.52: válasz a panelből (próbaüzem) */
		const reply = (m, all) => {
			const mine = (M.send.from || '').toLowerCase();
			const pick = s => (String(s || '').match(/[^\s<>,;"']+@[^\s<>,;"']+/g) || []).map(x => x.toLowerCase()).filter(x => x !== mine);
			/* v1.98: bejövőnél a Reply-To / továbbított levél eredeti feladója; saját (kolléga) feladónál a szál ügyfele */
			const ours = e => /@(eventcrazy\.hu|crazyphoto\.hu|studioframes\.hu)$/i.test(String(e || ''));
			const inTo = String(m.reply_to || (ours(m.from_email) && t.ce && !ours(t.ce) ? t.ce : m.from_email) || '').toLowerCase();
			const outs = pick(m.to_list), ext = outs.filter(x => !ours(x));
			const toL = m.dir === 'out' ? (ext.length ? ext : outs) : [inTo].filter(Boolean);
			const ccL = all ? Array.from(new Set(pick(m.to_list).concat(pick(m.cc_list)))).filter(x => !toL.includes(x)) : [];
			const subj = /^(re|vá|va|aw)\s*:/i.test(t.subj || '') ? t.subj : 'Re: ' + (t.subj || '');
			const quote = '<p><br></p><p>' + esc(hdate(m.sent_at)) + ', ' + esc(m.from_name || m.from_email) + ' ezt írta:</p><blockquote style="margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex">' + (m.body || '') + '</blockquote>';
			S.reply = { to: toL, cc: ccL, subject: subj, body: quote, quote: quote, thread: t.id > 0 ? t.id : 0, tsubj: t.subj, deal: d && d.id ? d.id : 0, dealTitle: d ? d.title : '', known: PR.known[t.id] || 0 };
			S.view = 'compose'; view(S.main);
		};
		const xb = rd.querySelector('[data-xall]');
		const xlab = () => { if (xb) xb.textContent = allOpen() ? '⇡ Összes összecsukása' : '⇣ Összes kinyitása'; };
		rd.querySelectorAll('.ml-msg[data-mk]').forEach(a => a.addEventListener('click', e => {
			if (e.target.closest('button, a, select, input, textarea, .ml-mb, .ml-atts')) return;
			const cl = a.classList.contains('cl');
			if (!cl && !e.target.closest('.ml-mh')) return;
			if (!cl && window.getSelection && String(window.getSelection())) return;
			XS.o[a.dataset.mk] = cl; a.classList.toggle('cl', !cl); xlab();
			if (cl) loadHtml(rd);
		}));
		if (xb) xb.addEventListener('click', () => {
			const open = !allOpen(); XS.def = open; XS.o = {};
			rd.querySelectorAll('.ml-msg[data-mk]').forEach(a => a.classList.toggle('cl', !open));
			xlab(); if (open) loadHtml(rd);
		});
		loadHtml(rd);
		const tl = timeline(x);
		rd.querySelectorAll('[data-reply]').forEach(b => b.addEventListener('click', () => { const it = tl[Number(b.dataset.reply)]; if (it && it.m) reply(it.m, false); }));
		rd.querySelectorAll('[data-replyall]').forEach(b => b.addEventListener('click', () => { const it = tl[Number(b.dataset.replyall)]; if (it && it.m) reply(it.m, true); }));
		/* v1.61: mobilon kék gyorsgomb a szál alján (Salesmate Quick Actions mintájára) */
		const qa = rd.querySelector('[data-qa]'), lastM = () => { let r = null; tl.forEach(i => { if (i.m && (!r || String(i.m.sent_at || '') >= String(r.sent_at || ''))) r = i.m; }); return r; };
		const qaShow = on => { qa.hidden = !on; };
		rd.querySelector('[data-tfab]').addEventListener('click', () => qaShow(true));
		/* v1.85: mobil fejléc – lebegő menük (a régi eszköztár rejtett gombjait használja, így a működés ugyanaz) */
		const mm = rd.querySelector('[data-mm]'), mms = rd.querySelector('[data-mms]');
		const sheet = (title, items) => {
			mms.innerHTML = '<div class="ml-qah"><b>' + esc(title) + '</b><button type="button" data-mmx aria-label="Bezárás">✕</button></div>' + items.map((it, i) => '<button type="button" data-mi="' + i + '"' + (it.dis ? ' disabled' : '') + (it.on ? ' class="on"' : '') + '>' + it.l + '</button>').join('');
			mm.hidden = false;
			mms.querySelectorAll('[data-mi]').forEach(b => b.addEventListener('click', () => { mm.hidden = true; items[Number(b.dataset.mi)].fn(); }));
			mms.querySelector('[data-mmx]').addEventListener('click', () => { mm.hidden = true; });
		};
		mm.querySelector('[data-mmx]').addEventListener('click', () => { mm.hidden = true; });
		const px = sel => () => { const b = rd.querySelector('.ml-rtool ' + sel); if (b) b.click(); };
		const has = sel => !!rd.querySelector('.ml-rtool ' + sel);
		rd.querySelector('[data-mmn]').addEventListener('click', () => sheet('Műveletek', [
			{ l: t.st === 'closed' ? '↺ Újranyitás' : '✓ Lezárás', fn: px('[data-st]') },
			{ l: '🏷 Címke' + (t.tags ? ': ' + esc(t.tags) : ''), fn: () => tgOpen(rd) },
		].concat(has('[data-ai]') ? [{ l: '✨ AI válasz', fn: px('[data-ai]') }] : [], has('[data-todo]') ? [{ l: '☑ Teendő felvétele', fn: px('[data-todo]') }] : [], [
			{ l: '● Olvasatlannak jelölés', fn: px('[data-one="unread"]') },
			{ l: '⏰ Szundi', fn: () => { const b = rd.querySelector('[data-snz]'); if (b) b.click(); } },
		], t.id > 0 ? [{ l: '🔗 Link másolása', fn: () => copyLink(t.id) }] : [], has('[data-one="restore"]') ? [{ l: '↩ Visszaállítás a Beérkezettbe', fn: px('[data-one="restore"]') }] : [{ l: '🗄 Archiválás', fn: px('[data-one="archive"]') }, { l: '🗑 Kukába', fn: px('[data-one="trash"]') }])));
		rd.querySelector('[data-mas]').addEventListener('click', () => {
			const setAs = v => { const sel = rd.querySelector('[data-as]'); if (!sel) return; sel.value = v; sel.dispatchEvent(new Event('change')); };
			sheet('Kiosztás', [{ l: 'Kiosztatlan', on: !t.as, fn: () => setAs('') }].concat(PEOPLE.map(p => ({ l: esc(p.n), on: t.as === p.id, fn: () => setAs(String(p.id)) }))));
		});
		rd.querySelector('[data-mfo]').addEventListener('click', () => { const f = rd.querySelector('[data-fol]'); if (f) f.click(); });
		qa.querySelectorAll('[data-qax]').forEach(b => b.addEventListener('click', () => qaShow(false)));
		qa.querySelector('[data-qf]').addEventListener('click', () => { const m = lastM(); qaShow(false); if (m) forward(m); });
		qa.querySelector('[data-qn]').addEventListener('click', () => { qaShow(false); const n = rd.querySelector('[data-note]'); const nc = rd.querySelector('.ml-ncomp'); if (nc) nc.classList.add('open'); if (n) { n.scrollIntoView({ block: 'center', behavior: 'smooth' }); n.focus(); } });
		qa.querySelector('[data-qr]').addEventListener('click', () => { const m = lastM(); qaShow(false); if (m) reply(m, false); });
		qa.querySelector('[data-qra]').addEventListener('click', () => { const m = lastM(); qaShow(false); if (m) reply(m, true); });
		const bk = rd.querySelector('[data-back]');
		/* v1.86.1: a „Vissza” gomb és a böngésző visszalépése (mobilon visszalegyintés) ugyanazt csinálja: vissza a levéllistára */
		S.closeTh = () => { S.reading = false; S.th = null; box.querySelectorAll('.ml-item.on').forEach(x => x.classList.remove('on')); const ib = box.querySelector('.ml-inbox'); if (ib) ib.classList.remove('reading'); const it = box.querySelector('.ml-item[data-th="' + t.id + '"]'); if (it) it.scrollIntoView({ block: 'center' }); };
		if (bk) bk.addEventListener('click', () => { if (history.state && history.state.cpMailTh) history.back(); else S.closeTh(); });
		/* v1.67.1: üzenetenkénti gombok – törlés (a szál a Kukába) és Továbbiak (továbbítás, olvasatlan, másolás, nyomtatás) */
		rd.querySelectorAll('[data-mdel]').forEach(b => b.addEventListener('click', () => {
			if (!window.confirm((tl.filter(i => i.m).length > 1 ? 'Ez a levél egy levélszál része – az egész szálat a Kukába teszed?' : 'A levelet a Kukába teszed?') + '\n\n(Csak a panelben – a postafiókban és a Salesmate-ben megmarad; a Kukából visszaállítható.)')) return;
			S.th = null; S.reading = false; bulk(box, [t.id], 'trash', '');
		}));
		const forward = m => {
			const hdr = '<p><br></p><p>---------- Továbbított üzenet ----------<br>Feladó: ' + esc(m.from_name || '') + ' &lt;' + esc(m.from_email || '') + '&gt;<br>Dátum: ' + esc(hdate(m.sent_at)) + '<br>Tárgy: ' + esc(m.subject || t.subj || '') + '<br>Címzett: ' + esc(m.to_list || '') + '</p>';
			const q = hdr + (m.status === 'Elküldve a panelből' ? (m.body || '') : tidyBody(m.body)) + ((m.attachments || []).length ? '<p><i>(A mellékleteket – ' + m.attachments.map(a => esc(a.n)).join(', ') + ' – szükség esetén töltsd le és csatold újra.)</i></p>' : '');
			S.reply = { to: [], cc: [], subject: /^(fw|fwd|továbbítva)\s*:/i.test(t.subj || '') ? t.subj : 'Fw: ' + (t.subj || ''), body: q, quote: q, thread: 0, tsubj: '', deal: d && d.id ? d.id : 0, dealTitle: d ? d.title : '' };
			S.view = 'compose'; view(S.main);
		};
		const printMsg = m => {
			const w = window.open('', '_blank', 'noopener=no'); if (!w) { toast('A böngésző letiltotta az új ablakot.', true); return; }
			const hc = HC[m.id] && HC[m.id].html && HV === 'html' ? HC[m.id].html.replace(/cpatt:\d+/g, '') : (m.status === 'Elküldve a panelből' ? (m.body || '') : tidyBody(m.body));
			w.document.write('<!doctype html><meta charset="utf-8"><title>' + esc(m.subject || t.subj || 'Levél') + '</title><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:"><style>body{font:14px/1.5 Arial,sans-serif;color:#111;margin:24px}h3{margin:0 0 4px}small{color:#555}hr{border:0;border-top:1px solid #ccc;margin:12px 0}img{max-width:100%}</style><h3>' + esc(m.subject || t.subj || '') + '</h3><small>' + esc((m.from_name || '') + ' <' + (m.from_email || '') + '>') + ' · ' + esc(hdate(m.sent_at)) + '<br>Címzett: ' + esc(m.to_list || '') + '</small><hr>' + hc);
			w.document.close(); setTimeout(() => { try { w.focus(); w.print(); } catch (e) { /* kézzel */ } }, 300);
		};
		rd.querySelectorAll('[data-mmore]').forEach(b => b.addEventListener('click', e => {
			e.stopPropagation();
			const old = rd.querySelector('.ml-mmenu'); if (old) { old.remove(); if (old._b === b) return; }
			const it = tl[Number(b.dataset.mmore)], m = it && it.m; if (!m) return;
			const pop = document.createElement('div'); pop.className = 'ml-mmenu'; pop._b = b;
			pop.innerHTML = (window.CPCrm && window.CPCrm.newDeal && C.crm && C.crm.on ? '<button type="button" data-k="deal">💼 Deal létrehozása ebből a levélből</button>' : '') + ((M.send && M.send.on) ? '<button type="button" data-k="fw">↪ Továbbítás</button>' : '') + '<button type="button" data-k="unread">● Olvasatlannak jelölés</button><button type="button" data-k="copy">⧉ Szöveg másolása</button><button type="button" data-k="print">🖨 Nyomtatás</button>' + (t.folder === 'spam' ? '' : '<button type="button" data-k="spam">⛔ Spamnek jelölés</button>');
			b.parentNode.style.position = 'relative'; b.parentNode.appendChild(pop);
			pop.querySelectorAll('[data-k]').forEach(x => x.addEventListener('click', async () => {
				pop.remove();
				const k = x.dataset.k;
				if (k === 'deal') {
					/* v1.69: új deal a levélből (ismert adatok + AI), a szál kérésre átkerül alá */
					window.CPCrm.newDeal({ thread: t.id, msg: m.id, onCreated: async (id, row, move) => {
						if (move) await act('cp_mail_thread_set', { f: 'deal', v: String(id), _deal: { id: id, title: (row && row.title) || ('Deal #' + id), value: null, cn: (row && row.cn) || '', co: '', stage: (row && row.stage) || '' } });
						post('cp_crm_note_add', { deal: id, body: 'Levélből létrehozva: „' + (t.subj || '') + '”' + (d && d.id && d.id !== id ? ' (előzmény: #' + d.id + ' ' + (d.title || '') + ')' : '') + '. A levelezés a panel Levelezés fülén' + (move ? ' ennél a dealnél' : '') + ' látható.' }).catch(() => {});
					} });
				}
				else if (k === 'fw') forward(m);
				else if (k === 'unread') { S.th = null; S.reading = false; bulk(box, [t.id], 'unread', ''); }
				else if (k === 'copy') { const tmp = document.createElement('div'); tmp.innerHTML = m.body || ''; try { await navigator.clipboard.writeText(tmp.innerText.trim()); toast('A levél szövege a vágólapon.'); } catch (er) { toast('A másolás nem sikerült.', true); } }
				else if (k === 'print') printMsg(m);
				else if (k === 'spam') { if (!window.confirm('Spamnek jelölöd ezt a levélszálat? A Spam mappába kerül (csak a panelben – onnan visszaállítható).')) return; S.th = null; S.reading = false; bulk(box, [t.id], 'spam', ''); }
			}));
			setTimeout(() => document.addEventListener('click', function off(ev) { if (!pop.isConnected || !pop.contains(ev.target)) { pop.remove(); document.removeEventListener('click', off); } }), 0);
		}));
		const clb = rd.querySelector('.ml-rtool [data-cl]'); if (clb) clb.addEventListener('click', () => copyLink(t.id));
		rd.querySelectorAll('[data-one]').forEach(b => b.addEventListener('click', () => { if (b.dataset.one === 'unread') S.th = null; bulk(box, [t.id], b.dataset.one, ''); }));
		const drc = rd.querySelector('[data-drcont]'); if (drc) drc.addEventListener('click', () => openDraft('', t.id));
		rd.querySelectorAll('[data-snz]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); snzMenu(b).then(ts => { if (ts) bulk(box, [t.id], 'snooze', String(ts)); }); }));
		rd.querySelector('[data-fol]').addEventListener('change', e => act('cp_mail_thread_set', { f: 'follow', v: e.target.checked ? '1' : '0' }));
		const det = rd.querySelector('[data-detach]');
		if (det) det.addEventListener('click', () => { if (window.confirm('Leválasztod a dealt erről a levélszálról?')) act('cp_mail_thread_set', { f: 'deal', v: '' }); });
		const dalt = rd.querySelector('[data-dalt]');
		if (dalt) dalt.addEventListener('click', () => act('cp_mail_thread_set', { f: 'deal', v: dalt.dataset.dalt }));
		const retb = rd.querySelector('[data-ret]');
		if (retb) retb.addEventListener('click', () => { const c = Number(retb.dataset.ret); if (c && window.CPCrm && window.CPCrm.contact) window.CPCrm.contact(c); });
		const att = rd.querySelector('[data-attach]');
		if (att) att.addEventListener('click', () => {
			const h = rd.querySelector('[data-dpat]'); h.hidden = !h.hidden;
			if (!h.hidden) dealPicker(h, dd => act('cp_mail_thread_set', { f: 'deal', v: String(dd.id), _deal: { id: dd.id, title: dd.title, value: null, cn: dd.cn, co: '', stage: '' } }));
		});
		/* v1.38: AI válaszvarázsló – piszkozat, amit a levélíróban nézel át */
		const aib = rd.querySelector('[data-ai]');
		if (aib) aib.addEventListener('click', () => aiPanel(rd.querySelector('[data-aip]'), t));
		/* v1.35: teendő a Feladatokba (kanban), és a levélszálhoz kapcsolt kártyák */
		const kbl = rd.querySelector('[data-kbl]');
		if (window.CPKanban && t.id > 0) window.CPKanban.linked(kbl, { th: t.id });
		const todo = rd.querySelector('[data-todo]');
		if (todo) todo.addEventListener('click', () => window.CPKanban.quickForm(rd.querySelector('[data-kbq]'), { title: t.subj, th: t.id, deal: d && d.id ? d.id : 0, onDone: () => window.CPKanban.linked(kbl, { th: t.id }) }));
		rd.querySelector('[data-ord]').addEventListener('click', () => { try { localStorage.setItem('cpmail_ord', newFirst() ? 'old' : 'new'); } catch (e) { /* privát mód */ } drawThread(box, rd, x); });
		const fd = rd.querySelector('[data-fromdeal]');
		if (fd) fd.addEventListener('click', () => { const id = S.fromDeal.deal; S.fromDeal = null; if (/^#mail$/.test(location.hash) && history.length > 1) history.back(); else if (window.CPCrm && window.CPCrm.open) window.CPCrm.open(id); });
		const od = rd.querySelector('[data-opendeal]');
		if (od) od.addEventListener('click', e => { e.preventDefault(); if (od.dataset.opendeal && window.CPCrm && window.CPCrm.open) window.CPCrm.open(Number(od.dataset.opendeal)); });
		rd.querySelectorAll('[data-ndel]').forEach(b => b.addEventListener('click', () => { if (window.confirm('Törlöd a jegyzetet?')) act('cp_mail_note_del', { id: b.dataset.ndel, nid: b.dataset.ndel }); }));
		/* jegyzetíró: @ megemlítés-ajánló, automatikus magasság */
		const ta = rd.querySelector('[data-note]'), mp = rd.querySelector('.ml-mpop');
		const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(220, ta.scrollHeight) + 'px'; };
		const at = () => { const v = ta.value.slice(0, ta.selectionStart); const m = v.match(/@([\wáéíóöőúüű]*)$/i); return m ? m[1] : null; };
		const pick = n => { const pos = ta.selectionStart, v = ta.value, st = v.slice(0, pos).replace(/@[\wáéíóöőúüű]*$/i, '@' + n + ' '); ta.value = st + v.slice(pos); ta.setSelectionRange(st.length, st.length); mp.hidden = true; ta.focus(); };
		ta.addEventListener('input', () => {
			grow();
			const q = at();
			if (q === null) { mp.hidden = true; return; }
			const l = PEOPLE.filter(p => fold(p.n).includes(fold(q))).slice(0, 6);
			mp.innerHTML = l.length ? l.map(p => '<button type="button" data-pn="' + esc(p.n) + '"><span class="ml-av" style="background:' + avc(p.n) + '">' + esc(ini(p.n)) + '</span>' + esc(p.n) + '</button>').join('') : '<div class="ml-dp-n">Nincs ilyen kolléga a panel felhasználói között.</div>';
			mp.hidden = false;
			mp.querySelectorAll('[data-pn]').forEach(b => { b.addEventListener('mousedown', e => e.preventDefault()); b.addEventListener('click', () => pick(b.dataset.pn)); });
		});
		const save = () => { const v = ta.value.trim(); if (!v) return; act('cp_mail_note_add', { body: v }); };
		ta.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); } if (e.key === 'Escape') mp.hidden = true; });
		rd.querySelector('[data-nsave]').addEventListener('click', save);
	}
	/* a levéllista egy sorának frissítése (jegyzet, felelős, lezárás) újratöltés nélkül */
	function refreshItem(box, t) {
		const b = box.querySelector('.ml-item[data-th="' + t.id + '"]');
		if (!b) return;
		b.classList.toggle('closed', t.st === 'closed');
		const i1 = b.querySelector('.ml-i1'), i3 = b.querySelector('.ml-i3'), as = i1.querySelector('.ml-as');
		if (as) as.remove();
		if (t.as) i1.querySelector('b').insertAdjacentHTML('afterend', '<span class="ml-as">› ' + esc(pname(t.as) || 'kolléga') + '</span>');
		const i2 = b.querySelector('.ml-i2'), og = i2 && i2.querySelector('.ml-tgs'); if (og) og.remove(); if (i2) i2.insertAdjacentHTML('afterbegin', tgChips(t.tags, 2));
		i3.innerHTML = (t.note ? '<span class="ml-inote">' + NOTE_I + esc(t.note.b.replace(/\s+/g, ' ')) + '</span>' : '<span>' + esc(t.snip) + '</span>') + (t.cnt ? '<i>' + t.cnt + '</i>' : '');
	}

	/* ------------------------------------------------------------ v2.9.25: címkék – szűrő, csipek, választó, kezelő */
	const TGC = [['#FBE3E1', '#9B2C22'], ['#FCEBDD', '#A85617'], ['#FFF3C4', '#7A5A00'], ['#E3F4EB', '#1F7A50'], ['#DDF3F2', '#1C6B67'], ['#E3EDFB', '#1E4F99'], ['#EEE7FB', '#5B3BA8'], ['#EEEDEA', '#4F535C']];
	const TGN = ['Piros', 'Narancs', 'Sárga', 'Zöld', 'Türkiz', 'Kék', 'Lila', 'Szürke'];
	const tgList = s => String(s || '').split(',').map(x => x.trim()).filter(Boolean);
	const tgCol = n => { const k = String(n).toLowerCase(), t = (S.tags || []).find(x => x.n.toLowerCase() === k); if (t) return TGC[t.c] || TGC[7]; let h = 0; for (const ch of k) h = (h * 31 + ch.charCodeAt(0)) >>> 0; return TGC[h % 8]; };
	const tgChip = (n, x) => { const c = tgCol(n); return '<span class="ml-tg" style="--tb:' + c[0] + ';--tf:' + c[1] + '" title="' + esc(n) + '"><span>' + esc(n) + '</span>' + (x ? '<button type="button" ' + x + '="' + esc(n) + '" aria-label="' + esc(n) + ' levétele" title="Levétel">×</button>' : '') + '</span>'; };
	const tgChips = (s, max) => { const l = tgList(s); if (!l.length) return ''; const sh = max ? l.slice(0, max) : l; return '<span class="ml-tgs">' + sh.map(n => tgChip(n)).join('') + (l.length > sh.length ? '<span class="ml-tg more" title="' + esc(l.slice(sh.length).join(', ')) + '">+' + (l.length - sh.length) + '</span>' : '') + '</span>'; };
	const tgP = () => (S.tg && S.tg.length ? JSON.stringify(S.tg) : '');
	function demoTags() { const m = {}; (S.demoRows || []).forEach(t => tgList(t.tags).forEach(n => { m[n] = (m[n] || 0) + 1; })); return Object.keys(m).map((n, i) => ({ n: n, c: i % 8, cnt: m[n] })); }
	function tagSide() {
		const L = S.tags || [], all = S.tgAll || L.length <= 8, sh = all ? L : L.slice(0, 8);
		return '<div class="ml-tside"><div class="ml-tsh"><span>Címkék</span><button type="button" class="ml-link" data-tgman>Kezelés</button></div>' +
			(L.length ? '<nav class="ml-tlist" aria-label="Szűrés címkére">' + sh.map(t => { const c = TGC[t.c] || TGC[7], on = (S.tg || []).some(x => x.toLowerCase() === t.n.toLowerCase()); return '<button type="button" data-tg="' + esc(t.n) + '" class="' + (on ? 'on' : '') + '" aria-pressed="' + on + '" title="' + esc(t.n) + '"><i style="background:' + c[1] + '"></i><span>' + esc(t.n) + '</span><b>' + (t.cnt || '') + '</b></button>'; }).join('') + '</nav>' +
				(L.length > 8 ? '<button type="button" class="ml-tmore" data-tgmore>' + (all ? 'Kevesebb' : 'Továbbiak (' + (L.length - 8) + ')') + '</button>' : '')
				: '<p class="ml-tnone">Még nincs címke – a levél fejlécében a Címke gombbal adhatsz hozzá.</p>') + '</div>';
	}
	function tagBar() {
		if (!S.tg || !S.tg.length) return '';
		return '<div class="ml-tgbar"><span>Címke:</span>' + S.tg.map(n => tgChip(n, 'data-tgx')).join('') + (S.tg.length > 1 ? '<small>bármelyik</small>' : '') + '<button type="button" class="ml-link" data-tgclr>Szűrő törlése</button></div>';
	}
	/* címkeválasztó: keresés, pipálás, új címke; kijelölt szálaknál (bulkMode) egy választás = hozzáadás mindegyikhez */
	function tagPicker(btn, cur, onToggle, bulkMode) {
		document.querySelectorAll('.ml-tgpop').forEach(x => x.remove());
		const pop = document.createElement('div');
		pop.className = 'ml-pop ml-tgpop';
		pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'Címkék');
		let sel = tgList(cur).map(x => x.toLowerCase()), act = 0, q = '';
		const items = () => {
			const qq = q.trim(), L = (S.tags || []).filter(t => !qq || fold(t.n).includes(fold(qq)));
			const ex = (S.tags || []).some(t => t.n.toLowerCase() === qq.toLowerCase());
			return L.concat(qq && !ex ? [{ n: qq.replace(/[,;]/g, ' ').replace(/\s+/g, ' ').slice(0, 40), nw: 1 }] : []);
		};
		const draw = () => {
			const it = items(); act = Math.max(0, Math.min(act, it.length - 1));
			pop.querySelector('.ml-tgl').innerHTML = it.length ? it.map((t, i) => { const on = sel.includes(t.n.toLowerCase()), c = TGC[t.c] || TGC[7];
				return '<button type="button" role="option" data-i="' + i + '" class="' + (i === act ? 'act' : '') + (t.nw ? ' nw' : '') + '" aria-selected="' + on + '">' +
					(t.nw ? '<span class="ml-tgplus" aria-hidden="true">+</span><span>Új címke: „' + esc(t.n) + '”</span>' : (bulkMode ? '' : '<span class="ml-tgck' + (on ? ' on' : '') + '" aria-hidden="true"></span>') + '<i style="background:' + c[1] + '"></i><span>' + esc(t.n) + '</span><small>' + (t.cnt || '') + '</small>') + '</button>'; }).join('')
				: '<div class="ml-dp-n">Még nincs címke – írd be az elsőt.</div>';
			pop.querySelectorAll('[data-i]').forEach(b => b.addEventListener('click', () => pick(Number(b.dataset.i))));
			const a = pop.querySelector('.act'); if (a) a.scrollIntoView({ block: 'nearest' });
		};
		const pick = i => {
			const t = items()[i]; if (!t) return;
			const k = t.n.toLowerCase(), on = !sel.includes(k);
			if (t.nw) { S.tags = (S.tags || []).concat([{ n: t.n, c: (S.tags || []).length % 8, cnt: 0 }]); q = ''; inp.value = ''; }
			if (bulkMode) { close(); onToggle(t.n, true); return; }
			sel = on ? sel.concat([k]) : sel.filter(x => x !== k);
			onToggle(t.n, on);
			draw(); inp.focus();
		};
		pop.innerHTML = '<input type="search" placeholder="' + (bulkMode ? 'Címke a kijelölt szálakhoz…' : 'Keresés vagy új címke…') + '" aria-label="Címke keresése" autocomplete="off"><div class="ml-tgl" role="listbox"></div>';
		if (isMob()) pop.insertAdjacentHTML('afterbegin', '<div class="ml-tgh"><b>' + (bulkMode ? 'Címke a kijelöltekhez' : 'Címkék') + '</b><button type="button" class="ml-link" data-tgdone>Kész</button></div>');
		document.body.appendChild(pop);
		const inp = pop.querySelector('input');
		const dn = pop.querySelector('[data-tgdone]'); if (dn) dn.addEventListener('click', () => close());
		inp.addEventListener('input', () => { q = inp.value; act = 0; draw(); });
		inp.addEventListener('keydown', e => {
			const n = Math.max(1, items().length);
			if (e.key === 'ArrowDown') { e.preventDefault(); act = (act + 1) % n; draw(); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); act = (act - 1 + n) % n; draw(); }
			else if (e.key === 'Enter') { e.preventDefault(); pick(act); }
			else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
		});
		const off = e => { if (!pop.contains(e.target) && !btn.contains(e.target)) close(); };
		function close() { pop.remove(); document.removeEventListener('pointerdown', off, true); window.removeEventListener('resize', close); }
		draw();
		if (isMob()) pop.classList.add('sheet');
		else {
			const r = btn.getBoundingClientRect(), w = Math.min(300, innerWidth - 24);
			pop.style.width = w + 'px';
			pop.style.left = Math.min(Math.max(12, r.left), innerWidth - w - 12) + 'px';
			const h = pop.offsetHeight, below = r.bottom + 6 + h <= innerHeight - 12;
			if (!below && r.top - 6 - h > 12) { pop.style.top = (r.top - 6 - h) + 'px'; pop.style.transformOrigin = 'bottom left'; } else pop.style.top = Math.min(r.bottom + 6, innerHeight - h - 12) + 'px';
		}
		inp.focus();
		setTimeout(() => document.addEventListener('pointerdown', off, true), 0);
		window.addEventListener('resize', close);
	}
	/* kis felugró (szín, összevonás célja) a kezelőben */
	function miniPop(btn, html, bind) {
		document.querySelectorAll('.ml-tgmini').forEach(x => x.remove());
		const pop = document.createElement('div');
		pop.className = 'ml-pop ml-tgmini'; pop.innerHTML = html;
		document.body.appendChild(pop);
		const r = btn.getBoundingClientRect();
		pop.style.left = Math.min(Math.max(12, r.left), innerWidth - pop.offsetWidth - 12) + 'px';
		pop.style.top = Math.max(12, Math.min(r.bottom + 6, innerHeight - pop.offsetHeight - 12)) + 'px';
		const off = e => { if (!pop.contains(e.target) && !btn.contains(e.target)) close(); };
		const close = () => { pop.remove(); document.removeEventListener('pointerdown', off, true); };
		setTimeout(() => document.addEventListener('pointerdown', off, true), 0);
		bind(pop, close);
		const f = pop.querySelector('button'); if (f) f.focus();
	}
	const colPick = (btn, cur, cb) => miniPop(btn, '<div class="ml-tcols">' + TGC.map((c, i) => '<button type="button" data-c="' + i + '" class="' + (i === cur ? 'on' : '') + '" style="--tf:' + c[1] + '" aria-label="' + TGN[i] + '" title="' + TGN[i] + '"></button>').join('') + '</div>',
		(pop, close) => pop.querySelectorAll('[data-c]').forEach(b => b.addEventListener('click', () => { close(); cb(Number(b.dataset.c)); })));
	const mergePick = (btn, list, cb) => miniPop(btn, '<div class="ml-tpop-h">Melyik címkébe olvadjon bele?</div><div class="ml-tgl">' + list.map(t => '<button type="button" data-n="' + esc(t.n) + '"><i style="background:' + (TGC[t.c] || TGC[7])[1] + '"></i><span>' + esc(t.n) + '</span><small>' + (t.cnt || '') + '</small></button>').join('') + '</div>',
		(pop, close) => pop.querySelectorAll('[data-n]').forEach(b => b.addEventListener('click', () => { close(); cb(b.dataset.n); })));
	/* címkekezelő: új címke, szín, átnevezés, összevonás, törlés – mindenkinek, minden szálon naplózva */
	function tagManager(box) {
		const m = document.getElementById('cpx-modal'); if (!m) return;
		if (window.cpxSheetMo) window.cpxSheetMo.watch();
		const close = () => { if (window.cpxSheetMo) window.cpxSheetMo.close(m); else { m.hidden = true; m.innerHTML = ''; } };
		const run = async (p, msg) => {
			if (S.demo) { toast('A mintában a címkék nem kezelhetők.', true); return; }
			try { const r = await post('cp_mail_tag_op', p); S.tags = r.tags; if (msg) toast(msg.replace('{n}', r.n || 0)); draw(); drawInbox(box); }
			catch (e) { toast(e.message, true); draw(); }
		};
		const draw = () => {
			const L = S.tags || [];
			m.innerHTML = '<div class="cpx-sheet ml-tman" role="dialog" aria-modal="true" aria-label="Címkék kezelése"><div class="cpx-sheet-h"><h3>Címkék kezelése</h3><button class="cpx-x" type="button" data-close aria-label="Bezárás">×</button></div>' +
				'<p class="cpx-muted">Az átnevezés, az összevonás és a törlés minden levélszálon érvényes, és a szálak idővonalán is látszik. A darabszámba a Kuka és a Spam nem számít bele.</p>' +
				'<form class="ml-tnew" data-tnew><input type="text" maxlength="40" placeholder="Új címke neve" aria-label="Új címke neve"><button type="submit" class="cpx-btn">Hozzáadás</button></form>' +
				(L.length ? '<ul class="ml-trows">' + L.map((t, i) => '<li data-ti="' + i + '"><button type="button" class="ml-tsw" data-tcol style="--tf:' + (TGC[t.c] || TGC[7])[1] + '" aria-label="' + esc(t.n) + ' színe" title="Szín"></button>' +
					'<input type="text" value="' + esc(t.n) + '" maxlength="40" data-tren aria-label="' + esc(t.n) + ' neve" title="Kattints az átnevezéshez">' +
					'<span class="ml-tcnt">' + (t.cnt ? t.cnt + ' szál' : 'nincs használva') + '</span>' +
					'<button type="button" class="ml-link" data-tmrg>Összevonás</button><button type="button" class="ml-link ml-tdel" data-tdel>Törlés</button></li>').join('') + '</ul>'
					: '<p class="ml-tnone">Még nincs címke.</p>') + '</div>';
			m.querySelector('[data-close]').addEventListener('click', close);
			const nf = m.querySelector('[data-tnew]');
			nf.addEventListener('submit', e => { e.preventDefault(); const v = nf.querySelector('input').value.trim(); if (v) run({ op: 'create', n: v }, 'Címke létrehozva.'); });
			m.querySelectorAll('[data-ti]').forEach(li => {
				const t = L[Number(li.dataset.ti)], inp = li.querySelector('[data-tren]');
				li.querySelector('[data-tcol]').addEventListener('click', e => colPick(e.currentTarget, t.c, c => run({ op: 'color', n: t.n, c: c })));
				const ren = () => {
					const v = inp.value.replace(/[,;]/g, ' ').replace(/\s+/g, ' ').trim();
					if (!v || v === t.n) { inp.value = t.n; return; }
					const ex = L.find(x => x !== t && x.n.toLowerCase() === v.toLowerCase());
					if (ex) { if (confirm('Már van „' + ex.n + '” címke. Összevonod vele a(z) „' + t.n + '” címkét (' + t.cnt + ' szál)?')) run({ op: 'merge', n: t.n, to: ex.n }, 'Összevonva – {n} szál.'); else inp.value = t.n; return; }
					run({ op: 'rename', n: t.n, to: v }, 'Átnevezve – {n} szál.');
				};
				inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); inp.value = t.n; inp.blur(); } });
				inp.addEventListener('change', ren);
				li.querySelector('[data-tmrg]').addEventListener('click', e => {
					const others = L.filter(x => x !== t);
					if (!others.length) { toast('Nincs másik címke, amibe beolvadhatna.', true); return; }
					mergePick(e.currentTarget, others, to => { if (confirm('A(z) „' + t.n + '” címke (' + t.cnt + ' szál) beolvad ebbe: „' + to + '”. Folytatod?')) run({ op: 'merge', n: t.n, to: to }, 'Összevonva – {n} szál.'); });
				});
				li.querySelector('[data-tdel]').addEventListener('click', () => { if (confirm('Törlöd a(z) „' + t.n + '” címkét' + (t.cnt ? ' ' + t.cnt + ' levélszálról' : '') + '? A levelek megmaradnak, csak a címke kerül le róluk.')) run({ op: 'delete', n: t.n }, 'Címke törölve – {n} szálról.'); });
			});
		};
		draw();
		m.hidden = false;
		if (!S.demo) post('cp_mail_tags', {}).then(r => { S.tags = r.tags; if (!m.hidden && m.querySelector('.ml-tman') && !m.contains(document.activeElement)) draw(); }).catch(() => {});
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
		host.addEventListener('click', e => { if (e.target === host) { const i = host.querySelector('input'); if (i) i.focus(); } });
		return { get: () => list.slice(), add: x => { if (x && !list.includes(x)) { list.push(x); draw(); } } };
	}
	async function drawCompose(box) {
		/* v2.0.1: egy megírt levél = egy küldési azonosító; ugyanazzal a szerver nem küld kétszer (dupla kattintás, újrapróbálás hiba után) */
		const sendId = 'sx' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
		let tpls = [];
		try { tpls = await loadTpls(); } catch (e) { /* sablon nélkül is írható */ }
		let deal = null;
		const draft = S.reply || null;
		S.reply = null;
		/* v2.9: a szerveren tárolt piszkozat azonosítója (a Piszkozatok mappából vagy a visszavont küldésből) */
		let draftId = S.draftId || ''; S.draftId = '';
		const ctx = { thread: draft && draft.thread ? Number(draft.thread) : 0, subj: draft && draft.tsubj ? draft.tsubj : '', quote: draft && draft.quote ? draft.quote : '', known: draft && draft.known ? Number(draft.known) : 0 };
		S.cctx = ctx;
		const SON = !!(M.send && M.send.on);
		box.innerHTML = '<div class="ml-compose">' +
			/* v1.62: mobilon a Salesmate „Compose” fejléce: bezárás, cím, melléklet, küldés, továbbiak */
			'<div class="ml-ctb"><button type="button" data-mx aria-label="Bezárás" title="Bezárás (a piszkozat megmarad)">✕</button><b>' + (ctx.thread ? 'Válasz' : 'Új levél') + '</b><button type="button" data-matt aria-label="Melléklet"' + (SON ? '' : ' disabled') + '><svg viewBox="0 0 24 24"><path d="M20 11.5l-8.2 8.2a5 5 0 0 1-7.1-7.1l8.6-8.6a3.4 3.4 0 0 1 4.8 4.8l-8.6 8.6a1.7 1.7 0 0 1-2.4-2.4l7.9-7.9"/></svg></button><button type="button" class="ml-ctb-send" data-msend aria-label="Küldés"' + (SON ? '' : ' disabled') + '><svg viewBox="0 0 24 24"><path d="M3 20l18-8L3 4l2 8z"/><path d="M5 12h8"/></svg></button><button type="button" data-mmore aria-label="Továbbiak">⋮</button></div>' +
			'<div class="ml-qa" data-cqa hidden><div class="ml-qab" data-cqax></div><div class="ml-qas"><div class="ml-qah"><b>Továbbiak</b><button type="button" data-cqax aria-label="Bezárás">✕</button></div>' +
			(ctx.thread && M.ai ? '<button type="button" data-mq="ai">✨ AI válasz</button>' : '') + '<button type="button" data-mq="deal">🔗 Deal csatolása</button>' + (C.portal && C.portal.on ? '<button type="button" data-mq="portal">📝 Ügyfélportál-link beszúrása</button>' : '') +
			(SON ? '<button type="button" data-mq="test">🧪 Próbalevél magamnak</button>' : '') + '<button type="button" data-mq="prev">👁 Előnézet</button><button type="button" data-mq="save">💾 Piszkozat mentése</button>' +
			(ctx.thread ? '<button type="button" data-mq="unthread">✉ Új levélként küldöm</button>' : '') + '<button type="button" data-mq="clear">🗑 Új, üres levél</button>' + (SON ? '' : '<small>A panelből küldés neked még nincs bekapcsolva.</small>') + '</div></div>' +
			(ctx.thread ? '<div class="ml-rctx">↩ Válasz a levélszálra: <b>' + esc(ctx.subj || '#' + ctx.thread) + '</b>' + (M.ai ? '<button type="button" class="ml-aibtn" data-cai title="Válaszpiszkozat az AI-val a levélszál alapján">✨ AI válasz</button>' : '<button type="button" class="ml-aibtn" disabled title="Az AI-válasz az AI-kulcs megadása után érhető el (WordPress → Beállítások → Crazy AI)">✨ AI válasz</button>') + '<button type="button" class="ml-link" data-unthread>Új levélként küldöm</button></div><div data-caip hidden class="ml-aip"></div>' : '') +
			'<div class="ml-ctop"><span class="ml-rel"><button type="button" class="ml-tbtn" data-tplbtn><i class="ml-tpli"><svg viewBox="0 0 24 24"><path d="M6 3h12v18l-6-4.5L6 21z"/></svg></i>🔖 Sablon beszúrása ▾</button></span>' + (C.portal && C.portal.on ? '<span class="ml-rel ml-pbw" data-pbw hidden><button type="button" class="ml-tbtn ml-pbtn" data-pins title="A deal személyes ügyfélportál-linkjének beszúrása">📝 Ügyfélportál<small data-pst></small></button><button type="button" class="ml-tbtn ml-pdd" data-pmode aria-label="Beszúrási mód" title="Beszúrási mód">▾</button><div class="ml-pop ml-ppop" data-ppop hidden></div></span>' : '') + '<div data-dp class="ml-grow"></div></div>' +
			'<div class="ml-row"><label>Feladó</label><select data-from>' + BOXES.map(b => '<option value="' + esc(b.id) + '">' + esc(b.n + (b.e ? ' (' + b.e + ')' : '')) + '</option>').join('') + '</select></div>' +
			'<div class="ml-row"><label>Címzett</label><div class="ml-chips" data-to></div><button type="button" class="ml-link" data-cc>Másolat / Rejtett</button></div>' +
			'<div class="ml-row" data-ccrow hidden><label>Másolat</label><div class="ml-chips" data-ccv></div></div>' +
			'<div class="ml-row" data-ccrow hidden><label>Rejtett</label><div class="ml-chips" data-bcc></div></div>' +
			'<div class="ml-row ml-srow"><label>Tárgy</label><input type="text" data-subj placeholder="Tárgy" value="' + esc(draft ? draft.subject : '') + '"></div>' +
			'<div data-ed></div>' +
			'<div class="ml-send">' + ((M.send && M.send.on) ? '<button type="button" class="cpx-btn" data-send>Küldés</button><button type="button" class="cpx-btn ghost" data-test title="Ugyanez a levél csak neked, [PRÓBA] tárggyal – a szálba nem kerül be">Próbalevél magamnak</button>' : '<button type="button" class="cpx-btn" disabled title="A panelből küldés neked nincs bekapcsolva (próbaüzem) – küldj a Salesmate-ből">Küldés</button>') + '<button type="button" class="cpx-btn ghost" disabled title="Később">Ütemezés</button>' +
			'<button type="button" class="cpx-btn ghost" data-prev>Előnézet</button><button type="button" class="cpx-btn ghost" data-save>Piszkozat mentése</button><button type="button" class="ml-link" data-clear>Elvetés</button><small class="ml-dst" data-dst></small>' +
			'<label class="ml-track" title="A megnyitás követése később jön"><input type="checkbox" disabled> Megnyitás követése (később)</label></div>' +
			'<div class="ml-prev" hidden></div></div>';
		const q = sel => box.querySelector(sel);
		/* v2.7.13: ütközésjelzés a válaszíróban is */
		if (ctx.thread) { const cmp0 = box.querySelector('.ml-compose'); if (cmp0) cmp0.insertAdjacentHTML('afterbegin', '<div class="ml-pres" data-pres hidden></div>'); if (!PR.known[ctx.thread] && ctx.known) PR.known[ctx.thread] = ctx.known; setTimeout(presPing, 50); }
		/* v1.58: az aláírás írás közben a levélben van (válasznál az idézett levél fölött), mint a Salesmate-ben */
		const SIG = M.sig ? '<div class="cp-sign">' + M.sig + '</div>' : '';
		const withSig = b => { b = b || ''; if (!SIG || /cp-sign/.test(b)) return b; if (ctx.quote && b.indexOf(ctx.quote) >= 0) return b.replace(ctx.quote, SIG + ctx.quote); return b + SIG; };
		const ed = editor(q('[data-ed]'), withSig(draft ? (draft.quote && draft.body === draft.quote ? '<p><br></p>' + draft.body : draft.body) : '<p><br></p>'));
		const to = chips(q('[data-to]'), draft ? draft.to : []), cc = chips(q('[data-ccv]'), draft && draft.cc ? draft.cc : []), bcc = chips(q('[data-bcc]'), draft && draft.bcc ? draft.bcc : []);
		if (draft && ((draft.cc && draft.cc.length) || (draft.bcc && draft.bcc.length))) box.querySelectorAll('[data-ccrow]').forEach(r => { r.hidden = false; });
		if (draft && draft.deal) deal = { id: Number(draft.deal), title: draft.dealTitle || ('Deal #' + draft.deal) };
		/* v1.54: AI-válasz a válaszíróban is – a piszkozat az idézett levél fölé kerül */
		const cai = q('[data-cai]');
		if (cai) cai.addEventListener('click', () => {
			const host = q('[data-caip]');
			if (!host.hidden) { host.hidden = true; host.innerHTML = ''; return; }
			host.hidden = false;
			host.innerHTML = '<div class="ml-ai"><div class="ml-aiw"><input type="text" data-cwish maxlength="500" placeholder="Mit írjon? (nem kötelező – pl. küldj árat a 360 spinnerre)"><button type="button" class="cpx-btn" data-cgen>Piszkozat írása</button></div><div data-caio></div><p class="ml-aif">A nevek, e-mail-címek és telefonszámok álnevesítve mennek az AI-hoz. A piszkozatot mindig nézd át.</p></div>';
			const w = host.querySelector('[data-cwish]'), g = host.querySelector('[data-cgen]'), o = host.querySelector('[data-caio]');
			const run = async () => {
				const cur = ed.get().replace(/\s+/g, '');
				if (cur && cur !== (ctx.quote || '').replace(/\s+/g, '') && !window.confirm('Az AI-piszkozat lecseréli az eddig beírt választ (az idézett levél megmarad). Mehet?')) return;
				g.disabled = true; w.disabled = true;
				o.innerHTML = '<div class="ml-aild"><span class="ml-spin"></span>Az AI írja a választ… (általában 10–40 másodperc)</div>';
				try {
					const r = await post('cp_ai_draft', { thread: ctx.thread, wish: w.value.trim() });
					ed.set(withSig(r.html + (ctx.quote || '')));
					if (!q('[data-subj]').value.trim() && r.subject) q('[data-subj]').value = r.subject;
					o.innerHTML = (r.missing.length || r.notes.length ? '<div class="ml-ain">' + (r.missing.length ? '<b>Kitöltendő:</b><ul>' + r.missing.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '') + (r.notes.length ? '<b>Mire figyelj:</b><ul>' + r.notes.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '') + '</div>' : '') + '<p class="ml-aif">✓ A piszkozat a levélben van – nézd át, a [KITÖLTENDŐ] részeket írd át. <button type="button" class="ml-link" data-cagain>Újraírás</button></p>';
					o.querySelector('[data-cagain]').addEventListener('click', run);
				} catch (e) { o.innerHTML = '<div class="ml-aierr">' + esc(e.message) + '</div>'; }
				g.disabled = false; w.disabled = false;
			};
			g.addEventListener('click', run);
			w.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); run(); } });
			w.focus();
		});
		const unt = q('[data-unthread]');
		if (unt) unt.addEventListener('click', () => { ctx.thread = 0; unt.parentNode.remove(); });
		const fromSel = q('[data-from]');
		if ((M.send && M.send.on)) { Array.from(fromSel.options).forEach(o => { if (o.value !== M.send.box) o.disabled = true; }); fromSel.value = M.send.box; }
		const sendBtn = q('[data-send]'), testBtn = q('[data-test]');
		/* v1.54: mellékletek csatolása (feltöltés a szerverre, küldéskor a levélhez) */
		const atts = [];
		/* v2.8.7: visszavont küldésből visszatérve a már feltöltött mellékletek is megmaradnak */
		if (draft && Array.isArray(draft.atts)) draft.atts.filter(a => a && a.tok).forEach(a => atts.push({ n: a.n, size: a.size || 0, tok: a.tok, err: '' }));
		const attBox = document.createElement('div'); attBox.className = 'ml-attl'; q('[data-ed]').after(attBox);
		const kb = n => n > 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
		const drawAtt = () => { attBox.innerHTML = atts.map((a, i) => '<span class="ml-attc' + (a.err ? ' err' : a.tok ? '' : ' busy') + '">' + CLIP_I + esc(a.n) + ' <small>' + (a.err ? esc(a.err) : a.tok ? kb(a.size) : 'feltöltés…') + '</small><button type="button" data-arm="' + i + '" aria-label="Eltávolítás">×</button></span>').join(''); attBox.querySelectorAll('[data-arm]').forEach(b => b.addEventListener('click', () => { atts.splice(Number(b.dataset.arm), 1); drawAtt(); })); };
		if (atts.length) setTimeout(drawAtt, 0); /* v2.8.7: visszavont küldésből megmaradt mellékletek */
		const upload = async file => {
			const a = { n: file.name, size: file.size, tok: '', err: '' }; atts.push(a); drawAtt();
			if (file.size > 15 * 1048576) { a.err = 'túl nagy (max. 15 MB)'; drawAtt(); return; }
			try { const fd = new FormData(); fd.append('action', 'cp_mail_upload'); fd.append('t', C.t); fd.append('f', file); const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }); const j = await r.json(); if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || 'hiba'); a.tok = j.data.tok; a.n = j.data.n; }
			catch (e) { a.err = e.message || 'hiba'; }
			drawAtt();
		};
		/* v1.59 / v2.9.7: friss, személyes ügyfélportál-link a dealhez – gombként, mondatban vagy puszta linkként; a levélíró tetején látható gomb a portál állapotával */
		const PM = [['btn', 'Gombként', 'narancs gomb, alatta a sima link'], ['txt', 'Szövegként', '„Az adatlapot itt töltheted ki: …”'], ['url', 'Csak a link', 'a puszta cím a kurzor helyére']];
		let pmode = 'btn'; try { pmode = localStorage.getItem('cpmail_pmode') || 'btn'; } catch (e) { /* nincs tár */ }
		const portalHtml = (u, m) => m === 'url' ? '<a href="' + esc(u) + '" target="_blank">' + esc(u) + '</a>&nbsp;' : m === 'txt' ? '<p>A rendezvény adatlapját itt töltheted ki: <a href="' + esc(u) + '" target="_blank">' + esc(u) + '</a></p>' :
			'<p><a href="' + esc(u) + '" target="_blank" style="display:inline-block;background:#F2A33A;color:#ffffff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:bold;font-family:Arial,sans-serif">Rendezvény-adatlap kitöltése</a></p><p style="font-size:12px;color:#666666">Ha a gomb nem működik, ezt a linket másold a böngészőbe: ' + esc(u) + '</p>';
		const pstText = sm => !sm || sm.none ? 'még nincs portál' : (sm.st === 'kesz' ? 'kitöltve ✓' : sm.done + '/' + sm.total + ' kitöltve') + (sm.deadline && sm.st !== 'kesz' ? ' · határidő: ' + sm.deadline : '');
		const pstDraw = sm => { const el = q('[data-pst]'); if (el) { el.textContent = pstText(sm); el.parentNode.title = 'A deal személyes ügyfélportál-linkjének beszúrása · Portál: ' + pstText(sm); } };
		let pstFor = 0, pHide = false;
		/* a látható gomb csak akkor, ha a levél dealhez kötött (válasznál a szál dealje) */
		const pbSync = async () => {
			const w = q('[data-pbw]'); if (!w) return;
			const did = deal && deal.id ? Number(deal.id) : 0;
			if (pstFor !== did) pHide = false;
			w.hidden = !did || pHide;
			if (!did || pstFor === did) return;
			pstFor = did; pstDraw(null); const el = q('[data-pst]'); if (el) el.textContent = '…';
			/* v2.9.15: aláírt szerződésnél és ha a portál már ki lett küldve, a gomb nem kell (a szerkesztő-eszköztár ikonja megmarad) */
			try { const r = await post('cp_portal_mail_link', { deal: did, peek: 1 }); if (pstFor === did) { pstDraw(r.none ? null : r.summary); if (r.hide) { pHide = true; w.hidden = true; } } } catch (e) { if (el) el.textContent = ''; }
		};
		const insertPortal = async (m) => {
			const did = deal && deal.id ? deal.id : 0;
			if (!did) { toast('Előbb válassz deal-t a levélhez (fent a deal-keresőben).', true); return; }
			try {
				let r = await post('cp_portal_mail_link', { deal: did });
				if (r.none) {
					if (!window.confirm('Ehhez a dealhez még nincs ügyfélportál. Létrehozzam most (külön meghívó levél nélkül), és a linkjét ebbe a levélbe teszem?')) return;
					r = await post('cp_portal_mail_link', { deal: did, create: 1 });
				}
				ed.html(portalHtml(r.url, m || pmode));
				if (r.summary) { pstFor = Number(did); pstDraw(r.summary); }
				toast('A személyes portál-link a levélben (csak ennek az ügyfélnek szól). Küldés után a deal – ha még ajánlati szakaszban van – magától az „Adatlap kiküldve” szakaszba lép.');
			} catch (e) { toast(e.message, true); }
		};
		const pb = box.querySelector('[data-cmd="portal"]');
		if (pb) {
			if (!(C.portal && C.portal.on)) pb.hidden = true;
			pb.addEventListener('click', () => insertPortal());
		}
		const pins = q('[data-pins]'), pdd = q('[data-pmode]'), ppop = q('[data-ppop]');
		if (pins) {
			pins.addEventListener('click', () => insertPortal());
			const pdraw = () => { ppop.innerHTML = '<div class="ml-tpop-l">' + PM.map(x => '<button type="button" data-pm="' + x[0] + '"' + (x[0] === pmode ? ' class="on"' : '') + '><b>' + (x[0] === pmode ? '✓ ' : '') + esc(x[1]) + '</b><small>' + esc(x[2]) + '</small></button>').join('') + '</div>'; };
			pdd.addEventListener('click', e => { e.stopPropagation(); pdraw(); ppop.hidden = !ppop.hidden; });
			ppop.addEventListener('click', e => { const b = e.target.closest('[data-pm]'); if (!b) return; e.stopPropagation(); pmode = b.dataset.pm; try { localStorage.setItem('cpmail_pmode', pmode); } catch (x) { /* nincs tár */ } ppop.hidden = true; insertPortal(pmode); });
			document.addEventListener('click', e => { if (!ppop.hidden && !e.target.closest('[data-pbw]')) ppop.hidden = true; });
		}
		pbSync();
		if (S.autoPortal) { S.autoPortal = 0; if (deal && deal.id) setTimeout(() => insertPortal(), 50); }
		const ab = box.querySelector('[data-cmd="attach"]');
		if (ab) {
			if ((M.send && M.send.on)) {
				ab.disabled = false; ab.title = 'Melléklet csatolása (fájlonként max. 15 MB, összesen 25 MB) – vagy húzd a fájlt a levélre';
				const fi = document.createElement('input'); fi.type = 'file'; fi.multiple = true; fi.hidden = true; box.appendChild(fi);
				ab.addEventListener('click', () => fi.click());
				fi.addEventListener('change', () => { Array.from(fi.files || []).forEach(upload); fi.value = ''; });
				const cmp = box.querySelector('.ml-compose');
				cmp.addEventListener('dragover', e => { if (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) { e.preventDefault(); cmp.classList.add('drop'); } });
				cmp.addEventListener('dragleave', e => { if (!cmp.contains(e.relatedTarget)) cmp.classList.remove('drop'); });
				cmp.addEventListener('drop', e => { if (!e.dataTransfer || !e.dataTransfer.files.length) return; e.preventDefault(); cmp.classList.remove('drop'); Array.from(e.dataTransfer.files).forEach(upload); });
			} else ab.title = 'Melléklet – a panelből küldés bekapcsolása után';
		}
		/* v2.9.14: sablon-helytartók kitöltése a deal adataival már a levélíróban; a hiányzók sárgán jelölve (title: az eredeti helytartó) */
		const unph = h => { const t = document.createElement('div'); t.innerHTML = h; t.querySelectorAll('span.cp-ph[title]').forEach(x => x.replaceWith(document.createTextNode(x.getAttribute('title')))); return t.innerHTML; };
		const unfilled = (sj, h) => {
			const t = document.createElement('div'); t.innerHTML = h; t.querySelectorAll('blockquote').forEach(x => x.remove());
			const out = new Set();
			t.querySelectorAll('span.cp-ph').forEach(x => out.add(x.textContent.replace(/^⚠\s*/, '').trim()));
			((sj + ' ' + t.textContent).match(/\{\{[^}]*\}\}/g) || []).forEach(x => out.add(x.replace(/^\{\{\s*|\s*(\|[^}]*)?\}\}$/g, '')));
			return Array.from(out);
		};
		let filling = null;
		const fillNow = async (why) => {
			if (!deal || !deal.id) return;
			const sj0 = q('[data-subj]').value, b0 = ed.get();
			if (!/\{\{/.test(sj0) && !/\{\{|class="cp-ph"/.test(b0)) return;
			if (filling) return filling;
			filling = (async () => {
				try {
					const r = await post('cp_mail_fill', { deal: deal.id, subject: sj0, body: unph(b0) });
					if (ed.get() !== b0 || q('[data-subj]').value !== sj0) return; /* közben írtál – nem írom felül */
					q('[data-subj]').value = r.subject; ed.set(r.body);
					if (r.miss && r.miss.length) toast('Kitöltve a deal adataival. Hiányzik a dealből: ' + r.miss.join(', ') + ' – sárgával jelölve, írd be kézzel, vagy töltsd ki a dealen.', true);
					else if (why) toast('Kitöltve a deal adataival.');
				} catch (e) { toast(e.message, true); }
				finally { filling = null; }
			})();
			return filling;
		};
		const doSend = async (test, force) => {
			const tl = to.get(), sj = q('[data-subj]').value.trim(), body = ed.get();
			if (!test && !tl.length) { toast('Adj meg címzettet.', true); return; }
			if (!sj) { toast('Add meg a tárgyat.', true); q('[data-subj]').focus(); return; }
			if (ed.isEmpty()) { toast('Üres a levél.', true); return; }
			/* v2.9.14: kitöltetlen sablon-helytartó nem mehet ki (próbalevélnél mehet, hogy lásd) */
			if (!test) { const um = unfilled(sj, body); if (um.length) { toast('Kitöltetlen adat maradt a levélben: ' + um.join(', ') + '. ' + (deal ? 'Írd be a sárga helyekre, vagy töltsd ki a dealen.' : 'Válaszd ki a dealt fent, és kitöltöm.'), true); const f = q('.ml-compose .cp-ph'); if (f) f.scrollIntoView({ block: 'center', behavior: 'smooth' }); return; } }
			/* v2.9.2: nincs külön megerősítő ablak – a 20 mp-es visszavonás véd az elsietett küldés ellen */
			if (atts.some(a => !a.tok && !a.err)) { toast('Várd meg, amíg a mellékletek feltöltődnek.', true); return; }
			if (atts.some(a => a.err)) { toast('Egy melléklet nem töltődött fel – vedd ki (×), vagy csatold újra.', true); return; }
			const b = test ? testBtn : sendBtn; b.disabled = true; const old = b.textContent; b.textContent = 'Küldés…';
			if (!test && !force) {
				/* v2.8.7: a levél tartalma a visszaszámlálás előtt félretéve – ha közben máshová lépsz a panelben, a visszavonás piszkozatként visszahozza a levélírót */
				const snap = { to: tl, cc: cc.get(), bcc: bcc.get(), subject: sj, body: body, thread: ctx.thread || 0, tsubj: ctx.subj, quote: ctx.quote || '', deal: deal ? deal.id : 0, dealTitle: deal ? deal.title : '', known: ctx.known || 0, atts: atts.filter(a => a.tok).map(a => ({ n: a.n, size: a.size, tok: a.tok })) };
				await dsave(true);
				const ok = await undoWait(20, tl.length > 1 ? tl[0] + ' +' + (tl.length - 1) : tl[0]);
				if (!ok) {
					if (box.isConnected && S.view === 'compose') { b.disabled = false; b.textContent = old; toast('Küldés visszavonva – a levél itt maradt, szerkesztheted.'); return; }
					S.reply = snap; S.draftId = draftId; S.view = 'compose';
					if (window.CPXGo) window.CPXGo('mail'); else if (S.main) view(S.main);
					setTimeout(() => toast('Küldés visszavonva – a levél a Piszkozatok között is megvan, itt szerkesztheted.'), 300);
					return;
				}
			}
			try {
				const r = await post('cp_mail_send', { to: tl.join(','), cc: cc.get().join(','), bcc: bcc.get().join(','), subject: sj, body: body, deal: deal ? deal.id : '', thread: ctx.thread || '', sign: '', test: test ? 1 : '', att: atts.map(a => a.tok).join(','), sid: test ? '' : sendId, known: ctx.known || '', force: force ? 1 : '', draft: test ? '' : draftId });
				if (r.test) { toast('Próbalevél elküldve ide: ' + r.to); b.disabled = false; b.textContent = old; return; }
				if (!r.test) { dsent = true; clearInterval(dtimer); }
				toast('Levél elküldve.' + (r.warn ? ' ' + r.warn : '') + (r.pst ? ' ' + r.pst : ''), !!r.warn);
				/* v1.84: a hívó (pl. „Ajánlat küldése” a dealen) értesítése a sikeres küldésről */
				if (S.onSent) { const f = S.onSent; S.onSent = null; try { f(r); } catch (x) { /* a levél elment */ } }
				/* v2.8.7: ha közben máshová léptél a panelben, nem ránt vissza a Levelezésbe */
				if (box.isConnected) open(r.thread);
			} catch (e) {
				b.disabled = false; b.textContent = old;
				/* v2.7.13: közben válasz / új levél jött a szálba – rákérdez, és csak megerősítés után küld */
				if (e.code === 'newer') { if (window.confirm(e.message + '\n\nMégis elküldöd a válaszodat? (Mégse: megnézem előbb a szálat – a levél itt marad.)')) { doSend(false, true); } else { toast('A levél itt maradt – a szál a Beérkezettben megnyitható.'); } return; }
				toast(e.message, true);
			}
		};
		if (sendBtn) sendBtn.addEventListener('click', () => doSend(false));
		if (testBtn) testBtn.addEventListener('click', () => doSend(true));
		dealPicker(q('[data-dp]'), x => { deal = x; if (x.ce) to.add(x.ce); pbSync(); fillNow(true); });
		if (deal && deal.id) fillNow(false);
		q('[data-cc]').addEventListener('click', () => box.querySelectorAll('[data-ccrow]').forEach(r => { r.hidden = !r.hidden; }));
		q('[data-tplbtn]').addEventListener('click', e => {
			e.stopPropagation();
			if (box.querySelector('.ml-tpop')) { box.querySelector('.ml-tpop').remove(); return; }
			tplMenu(e.currentTarget, tpls, t => {
				if (!ed.isEmpty() && !window.confirm('A sablon felülírja a levél jelenlegi szövegét. Mehet?')) return;
				if (t.subject) q('[data-subj]').value = t.subject;
				ed.set(withSig(t.body || ''));
				/* v2.9.14: azonnali kitöltés; deal nélkül rákérdez */
				if (deal && deal.id) fillNow(true);
				else if (/\{\{/.test((t.subject || '') + (t.body || ''))) { toast('Melyik dealhez írod? Válaszd ki fent a deal-keresőben, és a sablon adatait rögtön kitöltöm.'); const c = q('.ml-compose'); c.classList.add('dp-on'); const i = q('[data-dp] input'); if (i) { i.focus(); i.scrollIntoView({ block: 'center' }); } }
			});
		});
		/* v2.9: automatikus mentés a szerverre (4 mp-enként, ha változott; a levélíró elhagyásakor is) */
		const dstate = () => JSON.stringify({ to: to.get(), cc: cc.get(), bcc: bcc.get(), subject: q('[data-subj]').value, body: ed.get(), thread: ctx.thread || 0, tsubj: ctx.subj, quote: ctx.quote || '', deal: deal ? deal.id : 0, dealTitle: deal ? deal.title : '', known: ctx.known || 0, atts: atts.filter(a => a.tok).map(a => ({ n: a.n, size: a.size, tok: a.tok })) });
		let dlast = dstate(), dsent = false, dbusy = null;
		const dsave = async (force) => {
			if (dsent) return draftId;
			const st = dstate();
			if (!force && st === dlast) return draftId;
			if (dbusy) { try { await dbusy; } catch (e) { /* tovább */ } }
			dbusy = post('cp_mail_draft_save', { id: draftId, d: st });
			try { const r = await dbusy; dlast = st; draftId = r.id || ''; const el = box.querySelector('[data-dst]'); if (el) el.textContent = r.id ? 'Piszkozat mentve ' + r.at : ''; } catch (e) { /* a következő körben újra */ } finally { dbusy = null; }
			return draftId;
		};
		const dtimer = setInterval(() => { if (!box.isConnected || S.view !== 'compose') { clearInterval(dtimer); window.removeEventListener('pagehide', dhide); dsave(false); return; } dsave(false); }, 4000);
		const dhide = () => { if (dsent || dstate() === dlast || !navigator.sendBeacon) return; const fd = new FormData(); fd.append('action', 'cp_mail_draft_save'); fd.append('t', C.t); fd.append('id', draftId); fd.append('d', dstate()); navigator.sendBeacon(C.ajax, fd); };
		window.addEventListener('pagehide', dhide);
		q('[data-save]').addEventListener('click', async () => { const id = await dsave(true); toast(id ? 'Piszkozat mentve a Piszkozatok közé.' : 'Üres levél – nincs mit menteni.'); });
		q('[data-clear]').addEventListener('click', async () => { if (!window.confirm('Elveted ezt a levelet? (A piszkozat is törlődik.)')) return; dsent = true; clearInterval(dtimer); if (draftId) { try { await post('cp_mail_draft_del', { id: draftId }); } catch (e) { /* később */ } } toast('Piszkozat elvetve.'); drawCompose(box); });
		/* v1.62: mobil fejléc és a „Továbbiak” lap – a meglévő gombokat hívja */
		const cqa = q('[data-cqa]'), cqaShow = on => { cqa.hidden = !on; };
		const clk = sel => { const el = q(sel); if (el) el.click(); };
		q('[data-mx]').addEventListener('click', () => { clk('[data-save]'); S.view = 'inbox'; if (ctx.thread) { S.th = ctx.thread; S.reading = true; } view(S.main); });
		q('[data-matt]').addEventListener('click', () => clk('[data-cmd="attach"]'));
		q('[data-msend]').addEventListener('click', () => clk('[data-send]'));
		q('[data-mmore]').addEventListener('click', () => cqaShow(true));
		cqa.querySelectorAll('[data-cqax]').forEach(b => b.addEventListener('click', () => cqaShow(false)));
		cqa.querySelectorAll('[data-mq]').forEach(b => b.addEventListener('click', () => {
			cqaShow(false);
			const k = b.dataset.mq;
			if (k === 'ai') { clk('[data-cai]'); const h = q('[data-caip]'); if (h && !h.hidden) h.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
			else if (k === 'deal') { const c = q('.ml-compose'); c.classList.add('dp-on'); const i = q('[data-dp] input'); if (i) { i.scrollIntoView({ block: 'center' }); i.focus(); } }
			else if (k === 'portal') clk('[data-cmd="portal"]');
			else if (k === 'test') clk('[data-test]');
			else if (k === 'prev') clk('[data-prev]');
			else if (k === 'save') clk('[data-save]');
			else if (k === 'unthread') { clk('[data-unthread]'); b.remove(); q('.ml-ctb b').textContent = 'Új levél'; }
			else if (k === 'clear') clk('[data-clear]');
		}));
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
				'<div class="ml-vars"><span>Változók (kattints a beszúráshoz):</span>' + Object.keys(VARS).map(k => '<button type="button" data-vchip="' + esc(k) + '" title="' + esc('{{' + k + '}} – pl. ' + VARS[k][1]) + '">' + esc(VARS[k][0]) + '</button>').join('') + '</div>' +
				'<div class="ml-row ml-prow"><label>Előnézet</label><div data-dp class="ml-grow"></div><button type="button" class="cpx-btn ghost" data-prev>Előnézet</button></div><div class="ml-prev" hidden></div>' +
				'<div class="crm-ebar"><button type="button" class="cpx-btn" data-save>Mentés</button>' + (t.id ? '<button type="button" class="cpx-btn ghost" data-dup>Másolat</button><button type="button" class="cpx-btn ghost bad" data-del>Törlés</button>' : '') +
				'<span class="crm-emsg">' + (t.updated_at ? 'Utoljára módosította: ' + esc(t.updated_by || '–') + ', ' + esc(t.updated_at) : '') + '</span></div>';
			/* v2.9.15: a változók a szerkesztőben címkeként, magyar néven (mentéskor visszaalakul {{…}}-ra) */
			const vlab = k => (VARS[k] ? VARS[k][0].replace(/\s*\(.*\)$/, '') : k);
			const vchip = (k, raw) => '<span class="cp-var" contenteditable="false" data-l="' + esc(vlab(k)) + '" title="' + esc(raw) + '">' + esc(raw) + '</span>';
			const wrapV = h => { const t0 = document.createElement('div'); t0.innerHTML = h || ''; const w = document.createTreeWalker(t0, NodeFilter.SHOW_TEXT); const ns = []; while (w.nextNode()) { if (/\{\{[^}]*\}\}/.test(w.currentNode.nodeValue) && !(w.currentNode.parentNode && w.currentNode.parentNode.closest && w.currentNode.parentNode.closest('.cp-var'))) ns.push(w.currentNode); } ns.forEach(n => { const f = document.createElement('span'); f.innerHTML = esc(n.nodeValue).replace(/\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)[^}]*\}\}/g, (m0, k) => vchip(k, m0.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'"))); n.replaceWith(...f.childNodes); }); return t0.innerHTML; };
			const unwrapV = h => { const t0 = document.createElement('div'); t0.innerHTML = h || ''; t0.querySelectorAll('span.cp-var').forEach(x => x.replaceWith(document.createTextNode(x.getAttribute('title') || x.textContent))); return t0.innerHTML; };
			const ed0 = editor(te.querySelector('[data-ed]'), wrapV(t.body));
			const ed = Object.assign({}, ed0, { get: () => unwrapV(ed0.get()) });
			te.querySelectorAll('[data-vchip]').forEach(b => { b.addEventListener('mousedown', e => e.preventDefault()); b.addEventListener('click', () => ed0.html(vchip(b.dataset.vchip, '{{' + b.dataset.vchip + '}}') + '&nbsp;')); });
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

	function aiPanel(host, t) {
		if (!host.hidden && host.dataset.open) { host.hidden = true; host.innerHTML = ''; delete host.dataset.open; return; }
		host.hidden = false; host.dataset.open = '1';
		host.innerHTML = '<div class="ml-ai"><div class="ml-aih"><b>✨ AI válaszpiszkozat</b><button type="button" class="ml-dx" data-aix title="Bezárás">' + X_I + '</button></div>' +
			'<div class="ml-aiw"><input type="text" data-wish maxlength="500" placeholder="Mit írjon? (nem kötelező – pl. küldj árat a 360 spinnerre, kérdezd meg a létszámot)"><button type="button" class="cpx-btn" data-gen>Piszkozat írása</button></div><div data-aio></div>' +
			'<p class="ml-aif">A nevek, e-mail-címek és telefonszámok álnevesítve mennek az AI-hoz. A piszkozatot mindig nézd át, mielőtt elküldöd.</p></div>';
		const out = host.querySelector('[data-aio]'), wish = host.querySelector('[data-wish]'), gen = host.querySelector('[data-gen]');
		host.querySelector('[data-aix]').addEventListener('click', () => { host.hidden = true; host.innerHTML = ''; delete host.dataset.open; });
		const run = async () => {
			gen.disabled = true; wish.disabled = true;
			out.innerHTML = '<div class="ml-aild"><span class="ml-spin"></span>Az AI írja a választ… (általában 10–40 másodperc)</div>';
			try {
				const r = await post('cp_ai_draft', { thread: t.id, wish: wish.value.trim() });
				out.innerHTML = (r.missing.length || r.notes.length ? '<div class="ml-ain">' + (r.missing.length ? '<b>Kitöltendő:</b><ul>' + r.missing.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '') + (r.notes.length ? '<b>Mire figyelj:</b><ul>' + r.notes.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '') + '</div>' : '') +
					'<div class="ml-aisub"><small>Tárgy:</small> ' + esc(r.subject) + '</div><div class="ml-aibody ml-mb">' + r.html.replace(/\[KITÖLTENDŐ:([^\]]*)\]/g, '<mark>[KITÖLTENDŐ:$1]</mark>') + '</div>' +
					'<div class="ml-aia"><button type="button" class="cpx-btn" data-copy title="A Salesmate-be beilleszthető formában">Szöveg másolása</button><button type="button" class="cpx-btn ghost" data-take>Átvétel a levélíróba</button><button type="button" class="cpx-btn ghost" data-again>Újraírás</button><small class="cpx-muted">Új kérést fent írhatsz, majd Újraírás.</small></div>';
				out.querySelector('[data-take]').addEventListener('click', () => {
					S.reply = { to: r.to ? [r.to] : [], subject: r.subject, body: r.html, thread: t.id > 0 ? t.id : 0, tsubj: t.subj, deal: t.deal || 0 };
					S.view = 'compose'; view(S.main); toast('A piszkozat a levélíróban – nézd át, és töltsd ki a jelölt részeket.');
				});
				out.querySelector('[data-again]').addEventListener('click', run);
				/* a piszkozat vágólapra – formázva (HTML) és sima szövegként is, hogy a Salesmate szerkesztőjébe beilleszthető legyen */
				out.querySelector('[data-copy]').addEventListener('click', async () => {
					const tmp = document.createElement('div'); tmp.innerHTML = r.html; const plain = tmp.innerText.replace(/\n{3,}/g, '\n\n').trim();
					try {
						if (window.ClipboardItem && navigator.clipboard.write) await navigator.clipboard.write([new ClipboardItem({ 'text/html': new Blob([r.html], { type: 'text/html' }), 'text/plain': new Blob([plain], { type: 'text/plain' }) })]);
						else await navigator.clipboard.writeText(plain);
						toast('Kimásolva – illeszd be a Salesmate válaszába (és nézd át a sárga [KITÖLTENDŐ] részeket).');
					} catch (e) { toast('A másolás nem sikerült – jelöld ki a szöveget kézzel.', true); }
				});
			} catch (e) { out.innerHTML = '<div class="ml-aierr">' + esc(e.message) + '</div>'; }
			gen.disabled = false; wish.disabled = false;
		};
		gen.addEventListener('click', run);
		wish.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); run(); } });
		wish.focus();
	}

	/* v1.35: egy levélszál megnyitása más fülről (pl. a Feladatok kártyájáról) */
	function open(id, o) { S.view = 'inbox'; S.th = id; S.forceTh = id; S.fromDeal = o && o.deal ? { th: id, deal: o.deal } : null; if (window.CPXGo) window.CPXGo('mail'); else if (S.main) view(S.main); }
	/* v1.84: új levél előtöltve más fülről (címzett, tárgy, törzs, deal), küldés utáni visszahívással */
	function compose(o) { S.reply = { to: o.to || [], cc: [], subject: o.subject || '', body: o.body || '', quote: '', thread: 0, tsubj: '', deal: o.deal || 0, dealTitle: o.dealTitle || '' }; S.onSent = o.onSent || null; S.view = 'compose'; if (window.CPXGo) window.CPXGo('mail'); else if (S.main) view(S.main); }
	/* v2.9: másik fülről visszatérve a Levelezés a postafiókkal nyílik (a félbehagyott levél a Piszkozatok között van) */
	const tabView = main => { if (S.view === 'compose' && !S.reply && !S.draftId && !(main && main.querySelector('.ml-compose'))) S.view = 'inbox'; view(main); };
	window.CPMail = { view: tabView, status, open, compose, canSend: () => !!(M.send && M.send.on), refresh: async () => { S.tpls = null; if (S.main && S.main.isConnected) view(S.main); } };
})();
