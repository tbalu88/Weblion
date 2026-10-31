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
	try { const v = localStorage.getItem('cpmail_view'); if (['inbox', 'compose', 'tpl'].includes(v)) S.view = v; } catch (e) { /* privát mód */ }

	/* v1.67: a mappák / fülek listája gyorsítótárban – váltáskor azonnal megjelenik, a friss adat a háttérben jön */
	const RO = ['cp_mail_threads', 'cp_mail_thread', 'cp_mail_html', 'cp_mail_tpls', 'cp_mail_deals', 'cp_mail_preview'];
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
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j && j.data && j.data.msg) || 'Hiba'));
		return j.data;
	}
	function toast(t, bad) {
		let d = document.getElementById('cpx-toast');
		if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); }
		d.textContent = t; d.classList.toggle('bad', !!bad); d.classList.add('on');
		clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), bad ? 6000 : 3200);
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
		return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data: ' + o + (imgs ? ' https: http:' : '') + '; font-src data:' + (imgs ? ' https:' : '') + '">' +
			'<meta name="referrer" content="no-referrer"><base target="_blank"><style>html,body{margin:0;padding:0}body{font:14px/1.5 Arial,Helvetica,sans-serif;color:#1A1F2B;overflow-wrap:anywhere;-webkit-text-size-adjust:100%}img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}blockquote{margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex}blockquote blockquote,.cpq blockquote{margin-left:0!important;padding-left:8px!important;border-left:2px solid #ddd!important}blockquote blockquote blockquote,.cpq blockquote blockquote{border-left:0!important;padding-left:0!important;margin-left:0!important}details.cpq>summary{cursor:pointer;display:inline-block;margin:8px 0;padding:2px 10px;border-radius:6px;background:#F1EDE7;color:#6B6F78;font:12.5px Arial,sans-serif;list-style:none}details.cpq>summary::-webkit-details-marker{display:none}</style></head><body>' +
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
					const d = f.contentDocument, fit = () => { f.style.height = (d.documentElement.scrollHeight + 2) + 'px'; };
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
	function loadHtml(rd) {
		rd.querySelectorAll('.ml-mb[data-hm]').forEach(el => {
			const mid = el.dataset.hm;
			if (HC[mid]) { if (HC[mid].html) mountHtml(el, mid, false); return; }
			post('cp_mail_html', { id: mid }).then(x => { HC[mid] = x; if (x.html && el.isConnected) mountHtml(el, mid, false); }).catch(() => { /* marad a szöveges nézet */ });
		});
	}
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
		const F = [['inbox', 'Beérkezett'], ['follow', 'Követett'], ['outbox', 'Kimenő'], ['sent', 'Elküldött'], ['draft', 'Piszkozatok'], ['archive', 'Archív'], ['trash', 'Kuka'], ['spam', 'Spam']];
		S.tab = S.tab || 'open';
		let rows = [], counts = {}, un = {};
		if (S.demo) { rows = (S.demoRows = S.demoRows || demoData()).filter(t => (S.folder === 'follow' ? t.fol.length : (t.folder || 'inbox') === S.folder) && (S.tab !== 'open' || t.st === 'open') && (S.tab !== 'unassigned' || !t.as)); un = { inbox: S.demoRows.filter(t => t.un && t.st === 'open' && (t.folder || 'inbox') === 'inbox').length }; counts = { inbox: S.demoRows.filter(t => t.st === 'open').length, follow: S.demoRows.filter(t => t.fol.length).length }; }
		else {
			const P = { folder: S.folder, tab: S.tab, box: S.box, q: S.mq || '', so: S.so }, key = JSON.stringify(P), c = S.lc[key];
			let r = c;
			if (!c) { box.querySelectorAll('.ml-items, .ml-list').forEach(x => x.classList.add('ml-busy')); try { r = await post('cp_mail_threads', P); S.lc[key] = r; S.lcAt[key] = Date.now(); } catch (e) { r = null; S.err = e.message; } }
			/* a szomszédos fülek (Nyitott / Kiosztatlan / Mind) előre betöltve, hogy a váltás azonnali legyen */
			if (!c && r) setTimeout(() => ['open', 'unassigned', 'all'].filter(x => x !== P.tab).forEach(x => { const Q = Object.assign({}, P, { tab: x }), k = JSON.stringify(Q); if (!S.lc[k]) post('cp_mail_threads', Q).then(n => { S.lc[k] = n; S.lcAt[k] = Date.now(); }).catch(() => {}); }), 300);
			else if (Date.now() - (S.lcAt[key] || 0) > 4000) {
				/* háttérfrissítés: ha változott és még ugyanezt nézed (nyitott levél nélkül), újrarajzol */
				S.lcAt[key] = Date.now();
				post('cp_mail_threads', P).then(n => { const same = JSON.stringify(n) === JSON.stringify(S.lc[key]); S.lc[key] = n; if (!same && box.isConnected && !S.th && JSON.stringify({ folder: S.folder, tab: S.tab, box: S.box, q: S.mq || '', so: S.so }) === key) drawInbox(box); }).catch(() => {});
			}
			if (!box.isConnected) return;
			if (r) { rows = r.rows; counts = r.counts || {}; un = r.un || {}; }
		}
		if (S.demo && S.so === 'old') rows = rows.slice().reverse();
		S.rows = rows;
		const ids = new Set(rows.map(t => t.id));
		S.sel.forEach(id => { if (!ids.has(id)) S.sel.delete(id); });
		const L = cols();
		box.innerHTML = '<div class="ml-inbox' + (L.fold ? ' fold' : '') + (S.sel.size ? ' sel' : '') + (S.reading && S.th ? ' reading' : '') + '" style="--c1:' + (L.fold ? 40 : L.c1) + 'px;--c2:' + L.c2 + 'px">' +
			'<aside class="ml-side"><div class="ml-sideh"><button type="button" class="cpx-btn ml-new" data-compose>Új levél</button><button type="button" class="ml-fold" data-fold title="' + (L.fold ? 'Mappák kinyitása' : 'Mappák összecsukása') + '">' + (L.fold ? '»' : '«') + '</button></div>' +
			'<select class="ml-box" aria-label="Postafiók"><option value="">Minden postafiók</option>' + BOXES.map(b => '<option value="' + esc(b.id) + '"' + (S.box === b.id ? ' selected' : '') + '>' + esc(b.n) + '</option>').join('') + '</select>' +
			'<nav class="ml-folders">' + F.map(f => '<button type="button" data-f="' + f[0] + '" class="' + (S.folder === f[0] ? 'on' : '') + '"><span>' + f[1] + '</span><b' + (un[f[0]] ? ' class="u" title="' + un[f[0]] + ' olvasatlan"' : '') + '>' + (counts[f[0]] || '') + '</b></button>').join('') + '</nav>' +
			/* a „Minta megtekintése” csak a postafiókok bekötése előtt kell (kitalált levelekkel próbálható ki a felület) */
			(demoOk() ? '<label class="ml-demo"><input type="checkbox" data-demo' + (S.demo ? ' checked' : '') + '> Minta megtekintése</label>' : '') + '</aside><div class="ml-split" data-split="c1" title="Húzd az oszlop méretezéséhez (dupla kattintás: alapméret)"></div>' +
			'<section class="ml-list"><input type="search" class="ml-q" placeholder="Keresés a levelek között" value="' + esc(S.mq || '') + '">' +
			/* v1.60: mobilon mappa-választó (a mappaoszlop helyett) és rendezés */
			'<div class="ml-mbar"><select class="ml-fsel" data-fsel aria-label="Mappa">' + F.map(f => '<option value="' + f[0] + '"' + (S.folder === f[0] ? ' selected' : '') + '>' + f[1] + (counts[f[0]] ? ' (' + counts[f[0]] + ')' : '') + '</option>').join('') + '' + (demoOk() ? '<option value="__demo">' + (S.demo ? '✓ ' : '') + 'Minta megtekintése</option>' : '') + '</select>' +
			'<select class="ml-ssel" data-sort aria-label="Rendezés"><option value="new"' + (S.so === 'new' ? ' selected' : '') + '>Legújabb</option><option value="old"' + (S.so === 'old' ? ' selected' : '') + '>Legrégebbi</option></select></div>' +
			'<div class="ml-tabs">' + [['open', 'Nyitott'], ['unassigned', 'Kiosztatlan'], ['all', 'Mind']].map(x => '<button type="button" data-tab="' + x[0] + '" class="' + (S.tab === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div><div class="ml-selbar" data-selbar></div>' +
			'<div class="ml-items">' + (rows.length ? rows.map(t => '<div role="button" tabindex="0" class="ml-item' + (S.th === t.id ? ' on' : '') + (t.st === 'closed' ? ' closed' : '') + (t.un ? ' un' : '') + (S.sel.has(t.id) ? ' ck' : '') + '" data-th="' + t.id + '">' +
				'<span class="ml-ckw" data-ck role="checkbox" aria-checked="' + S.sel.has(t.id) + '" aria-label="Kijelölés" title="Kijelölés (Shift: tartomány)"><span class="ml-av" style="background:' + avc(t.cn) + '">' + esc(ini(t.cn)) + '</span><i class="ml-cb"></i></span><span class="ml-ib">' +
				'<span class="ml-i1"><b>' + esc(t.cn || t.ce) + '</b>' + (t.as ? '<span class="ml-as">› ' + esc(pname(t.as) || 'kolléga') + '</span>' : '') + '<small>' + esc(ago(t.at)) + '</small></span>' +
				'<span class="ml-i2">' + (t.att ? CLIP_I : '') + (t.st === 'closed' ? '✓ ' : '') + esc(t.subj) + '</span>' +
				'<span class="ml-i3">' + (t.note ? '<span class="ml-inote">' + NOTE_I + esc(t.note.b.replace(/\s+/g, ' ')) + '</span>' : '<span>' + esc(t.snip) + '</span>') + (t.cnt ? '<i>' + t.cnt + '</i>' : '') + '</span></span></div>').join('') :
				'<div class="ml-empty"><b>' + (M.connected ? 'Nincs levél' : 'Még nincsenek levelek') + '</b><span>' + (M.connected ? '' : 'A postafiókok bekötése után itt jelennek meg a beérkező levelek, deal-ekhez és kollégákhoz rendelve. Addig a „Minta megtekintése” kapcsolóval kipróbálhatod a nézetet.') + '</span></div>') + '</div></section><div class="ml-split" data-split="c2" title="Húzd az oszlop méretezéséhez (dupla kattintás: alapméret)"></div>' +
			'<section class="ml-read" data-read></section><button type="button" class="ml-fab" data-fab aria-label="Új levél" title="Új levél"><svg viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 6l4 4"/></svg></button></div>';
		layout(box);
		const pick = (sel, b) => { box.querySelectorAll(sel).forEach(x => x.classList.toggle('on', x === b)); };
		box.querySelectorAll('[data-f]').forEach(b => b.addEventListener('click', () => { pick('[data-f]', b); S.folder = b.dataset.f; S.th = null; drawInbox(box); }));
		box.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => { pick('[data-tab]', b); S.tab = b.dataset.tab; drawInbox(box); }));
		box.querySelector('.ml-box').addEventListener('change', e => { S.box = e.target.value; drawInbox(box); });
		if (box.querySelector('[data-demo]')) box.querySelector('[data-demo]').addEventListener('change', e => { S.demo = e.target.checked; S.th = null; drawInbox(box); });
		let qt = null;
		box.querySelector('.ml-q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { S.mq = e.target.value; drawInbox(box).then(() => { const i = box.querySelector('.ml-q'); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }); }, 350); });
		box.querySelector('[data-compose]').addEventListener('click', () => { S.view = 'compose'; view(S.main); });
		box.querySelector('[data-fab]').addEventListener('click', () => { S.view = 'compose'; view(S.main); });
		box.querySelector('[data-fsel]').addEventListener('change', e => { const v = e.target.value; if (v === '__demo') { S.demo = !S.demo; } else { S.folder = v; } S.th = null; S.reading = false; drawInbox(box); });
		box.querySelector('[data-sort]').addEventListener('change', e => { S.so = e.target.value; try { localStorage.setItem('cpmail_so', S.so); } catch (x) { /* privát mód */ } drawInbox(box); });
		box.querySelectorAll('[data-th]').forEach(b => {
			const go = () => { S.th = Number(b.dataset.th); box.querySelectorAll('.ml-item').forEach(x => x.classList.toggle('on', x === b)); b.classList.remove('un'); const r = S.rows.find(x => x.id === S.th); if (r) r.un = 0; if (S.sel.size) { S.sel.clear(); selUI(box); } openThread(box, S.th); };
			b.addEventListener('click', e => { if (e.target.closest('[data-ck]')) return; if (e.ctrlKey || e.metaKey) { toggleSel(box, Number(b.dataset.th), false); return; } go(); });
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
		const inBin = ['trash', 'spam', 'archive'].includes(S.folder);
		const B = (op, t, cls) => '<button type="button" class="ml-sb' + (cls ? ' ' + cls : '') + '" data-op="' + op + '">' + t + '</button>';
		rd.innerHTML = '<div class="ml-selp"><div class="ml-selico">✉<i>✓</i></div><h3>' + n + ' levélszál kijelölve</h3>' +
			'<div class="ml-sbs">' + B('read', '✉ Olvasottnak jelölés') + B('unread', '● Olvasatlannak jelölés') + B('tag', '🏷 Címke') +
			(sel.some(t => t.st !== 'closed') ? B('close', '✓ Lezárás') : '') + (sel.some(t => t.st === 'closed') ? B('open', '↺ Újranyitás') : '') +
			'<label class="ml-sb ml-sbas">👤 Kiosztás <select data-sas><option value="">– válassz –</option><option value="0">Kiosztatlan</option>' + PEOPLE.map(p => '<option value="' + p.id + '">' + esc(p.n) + '</option>').join('') + '</select></label>' +
			(inBin ? B('restore', '↩ Visszaállítás') : B('archive', '🗄 Archiválás')) + (S.folder !== 'spam' ? B('spam', '⚠ Spam') : '') + (S.folder !== 'trash' ? B('trash', '🗑 Kukába', 'bad') : '') + '</div>' +
			'<p class="cpx-muted">Ezek csak a panelben hatnak: a postafiókban és a Salesmate-ben semmi nem változik, a Kukából bármikor visszaállítható.</p>' +
			'<button type="button" class="ml-link" data-scancel>Mégse</button></div>';
		rd.querySelector('[data-scancel]').addEventListener('click', () => { S.sel.clear(); drawInbox(box); });
		rd.querySelectorAll('[data-op]').forEach(b => b.addEventListener('click', () => {
			const op = b.dataset.op;
			if (op === 'tag') { const v = window.prompt('Melyik címkét adjam a ' + n + ' levélszálhoz?', ''); if (v && v.trim()) bulk(box, Array.from(S.sel), op, v.trim()); return; }
			bulk(box, Array.from(S.sel), op, '');
		}));
		rd.querySelector('[data-sas]').addEventListener('change', e => { if (e.target.value !== '') bulk(box, Array.from(S.sel), 'assign', e.target.value); });
	}
	const OPMSG = { read: 'olvasottnak jelölve', unread: 'olvasatlannak jelölve', tag: 'címkézve', close: 'lezárva', open: 'újranyitva', assign: 'kiosztva', archive: 'archiválva', trash: 'a Kukába téve', spam: 'a Spambe téve', restore: 'visszaállítva' };
	async function bulk(box, ids, op, v) {
		let n = 0;
		try {
			const live = ids.filter(id => id > 0);
			if (live.length) n += (await post('cp_mail_bulk', { ids: live.join(','), op: op, v: v })).n;
			ids.filter(id => id < 0).forEach(id => {
				const t = S.demoRows.find(x => x.id === id); if (!t) return; n++;
				if (op === 'read') t.un = 0; if (op === 'unread') t.un = 1; if (op === 'close') t.st = 'closed'; if (op === 'open') t.st = 'open'; if (op === 'assign') t.as = Number(v) || 0;
				if (op === 'tag') t.tags = (t.tags ? t.tags + ', ' : '') + v;
				if (['archive', 'trash', 'spam'].includes(op)) { t.prev = t.folder || 'inbox'; t.folder = op; }
				if (op === 'restore') { t.folder = t.prev && !['archive', 'trash', 'spam'].includes(t.prev) ? t.prev : 'inbox'; t.st = 'open'; }
			});
		} catch (e) { toast(e.message, true); return; }
		toast(ids.length === 1 ? 'Levélszál ' + OPMSG[op] + '.' : n + ' levélszál ' + OPMSG[op] + '.');
		S.sel.clear();
		if (['archive', 'trash', 'spam', 'restore', 'unread'].includes(op) && ids.includes(S.th)) S.th = null;
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

	async function openThread(box, id) {
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
		const me = M.me || (PEOPLE[0] || {}).id || 0;
		const following = (t.fol || []).includes(me);
		const money = v => (v == null ? '–' : Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft');
		rd.innerHTML = '<div class="ml-backbar"><button type="button" data-back>‹ Vissza</button><span>' + esc(t.subj) + '</span></div><button type="button" class="ml-tfab" data-tfab aria-label="Gyors műveletek" title="Gyors műveletek"><svg viewBox="0 0 24 24"><path d="M5 12h14M12 5v14"/></svg></button><div class="ml-qa" data-qa hidden><div class="ml-qab" data-qax></div><div class="ml-qas"><div class="ml-qah"><b>Gyors műveletek</b><button type="button" data-qax aria-label="Bezárás">✕</button></div><button type="button" data-qn>' + NOTE_I + 'Jegyzet hozzáadása</button><button type="button" data-qr' + ((M.send && M.send.on) ? '' : ' disabled') + '>' + RE_I + 'Válasz</button><button type="button" data-qra' + ((M.send && M.send.on) ? '' : ' disabled') + '>' + REA_I + 'Válasz mindenkinek</button>' + ((M.send && M.send.on) ? '' : '<small>A panelből küldés neked még nincs bekapcsolva.</small>') + '</div></div><div class="ml-rtool"><button type="button" data-st>' + (t.st === 'closed' ? '↺ Újranyitás' : '✓ Lezárás') + '</button><button type="button" data-tag>🏷 ' + (t.tags ? esc(t.tags) : 'Címke') + '</button>' + (M.ai && t.id > 0 ? '<button type="button" class="ml-aibtn" data-ai title="Válaszpiszkozat az AI-val (átnézed, mielőtt elküldöd)">✨ AI válasz</button>' : '') + (window.CPKanban && t.id > 0 ? '<button type="button" data-todo title="Teendő felvétele a Feladatokba">☑ Teendő</button>' : '') +
			'<button type="button" data-one="unread" title="Olvasatlannak jelölés">●</button>' + (['trash', 'spam', 'archive'].includes(t.folder) ? '<button type="button" data-one="restore" title="Visszaállítás a Beérkezettbe">↩</button>' : '<button type="button" data-one="archive" title="Archiválás (csak a panelben)">🗄</button><button type="button" data-one="trash" title="Kukába (csak a panelben; visszaállítható)">🗑</button>') +
			'<span class="crm-bgap"></span><span class="ml-fols">' + (t.fol || []).slice(0, 3).map(f => '<i style="background:' + avc(pname(f)) + '" title="' + esc(pname(f)) + '">' + esc(ini(pname(f))) + '</i>').join('') + ((t.fol || []).length ? '' : '<small>Nincs követő</small>') + '</span>' +
			'<select data-as aria-label="Felelős"><option value="">Kiosztatlan</option>' + PEOPLE.map(p => '<option value="' + p.id + '"' + (t.as === p.id ? ' selected' : '') + '>' + esc(p.n) + '</option>').join('') + '</select>' +
			'<label class="ml-sw"><input type="checkbox" data-fol' + (following ? ' checked' : '') + '><span></span> Követés</label></div>' +
			(S.fromDeal && S.fromDeal.th === S.th ? '<div class="ml-fromdeal"><button type="button" class="cpx-btn ghost" data-fromdeal style="margin:0 0 8px">← Vissza a dealhez (#' + Number(S.fromDeal.deal) + ')</button></div>' : '') +
			'<div class="ml-subjh"><h3>' + esc(t.subj) + '</h3><button type="button" class="ml-link" data-ord title="A levelek sorrendje a szálban">' + (newFirst() ? '↓ Legújabb felül' : '↑ Legrégebbi felül') + '</button>' + (d ? '' : '<button type="button" class="ml-link" data-attach>$ Deal csatolása</button>') + '</div><div data-dpat hidden class="ml-dpat"></div><div data-kbq hidden class="ml-dpat"></div><div data-aip hidden class="ml-aip"></div><div data-kbl class="ml-kbl"></div>' +
			'<div class="ml-thread">' +
			(d ? '<div class="ml-dcard">' + DEAL_I + '<div><a href="#deals" data-opendeal="' + (d.id || '') + '">' + esc(d.title) + '</a><small>' + [money(d.value), d.cn, d.co, d.stage].filter(Boolean).map(esc).join('<i>•</i>') + '</small>' + (d.portal ? '<span class="ml-pchip ' + esc(d.portal.st) + '" title="Ügyfélportál' + (d.portal.deadline ? ' · határidő: ' + esc(d.portal.deadline) : '') + '">Portál ' + d.portal.done + '/' + d.portal.total + '</span>' : '') + '</div><button type="button" class="ml-dx" data-detach aria-label="Deal leválasztása" title="Deal leválasztása">' + X_I + '</button></div>' : '') +
			(x.notes || []).map(n => '<div class="ml-ncard"><div class="ml-nh"><span class="ml-av" style="background:' + avc(n.author) + '">' + esc(ini(n.author)) + '</span><b>Jegyzet</b> – ' + esc(n.author) + '<small>' + esc(ago(n.created_at)) + '</small>' + (n.author_uid === me || M.admin ? '<button type="button" class="ml-ndel" data-ndel="' + n.id + '" title="Jegyzet törlése">×</button>' : '') + '</div><div class="ml-nb">' + nl2br(n.body) + '</div></div>').join('') +
			timeline(x).map((it, ix) => it.ev ? '<div class="ml-ev"><span class="ml-av ml-av1 ev" style="background:' + avc(it.ev.author) + '">' + esc(ini(it.ev.author).slice(0, 1)) + '</span><span><b>' + esc(it.ev.text) + '</b>' + (it.ev.author ? ' – ' + esc(it.ev.author) : '') + '</span><small>' + esc(ago(it.ev.created_at)) + '</small></div>' : (m => '<article class="ml-msg"><div class="ml-mh"><span class="ml-av lg ml-av1' + (m.dir === 'out' ? ' out' : '') + '"' + (m.dir === 'out' ? '' : ' style="background:' + avc(m.from_name) + '"') + '>' + esc(ini(m.from_name).slice(0, 1)) + '</span><div><div class="ml-from"><span>' + esc(m.from_name) + '</span> <span class="ml-fe">&lt;' + esc(m.from_email) + '&gt;</span>' + /* v1.78.6: a nem ellenőrizhető saját levélnél egyetlen sárga jelvény (a magyarázat rámutatva); a többi állapot kék címke */
				(m.status && m.status !== 'idézetből' && m.status !== 'ellenőrizetlen' ? '<span class="ml-dlv">' + esc(m.status) + '</span>' : '') + '</div>' +
				(m.status === 'idézetből' ? '<div class="ml-unv" title="A levél nincs meg a postafiók Elküldött mappájában – a panel az ügyfél válaszában idézett szövegből állította vissza, ezért a tartalma nem ellenőrizhető.">⚠ Válaszból visszaállítva · ellenőrizetlen</div>' : m.status === 'ellenőrizetlen' ? '<div class="ml-unv" title="A levél a saját címünkről a Beérkezett mappába jött, nem az Elküldött mappából vagy a panelből – a tartalma hamis is lehet.">⚠ Ellenőrizetlen saját levél</div>' : '') + '<div class="ml-to">Címzett: ' + addr(m.to_list) + (m.cc_list ? '<br>Másolat: ' + addr(m.cc_list) : '') + '</div></div><span class="ml-mt"><span class="ml-ma"><button type="button"' + ((M.send && M.send.on) ? ' data-reply="' + ix + '"' : ' disabled') + ' title="Válasz">' + RE_I + '</button><button type="button"' + ((M.send && M.send.on) ? ' data-replyall="' + ix + '"' : ' disabled') + ' title="Válasz mindenkinek">' + REA_I + '</button><button type="button"' + (t.id > 0 ? ' data-mdel' : ' disabled') + ' title="Törlés (a levélszál a Kukába kerül)">' + DEL_I + '</button><button type="button"' + (t.id > 0 ? ' data-mmore="' + ix + '"' : ' disabled') + ' title="Továbbiak">' + MORE_I + '</button></span><span class="ml-md">' + esc(hdate(m.sent_at)) + '</span></span></div>' +
				'<div class="ml-mb"' + (m.id > 0 && m.hh !== 0 && m.status !== 'Elküldve a panelből' && m.status !== 'idézetből' ? ' data-hm="' + m.id + '"' : '') + '>' + (m.status === 'Elküldve a panelből' ? (m.body || '') : tidyBody(m.body)) + '</div>' + ((m.attachments || []).length ? '<div class="ml-atts">' + CLIP_I + ' <b>Mellékletek:</b> ' + m.attachments.map((a, ai) => m.id > 0 ? '<a href="' + esc(attUrl(m.id, ai, a.n)) + '" target="_blank" rel="noopener" title="Megnyitás / letöltés">' + esc(a.n) + '</a>' : '<span>' + esc(a.n) + '</span>').join(', ') + '</div>' : '') + '</article>')(it.m)).join('') +
			'</div>' +
			'<div class="ml-ncomp"><textarea rows="1" data-note placeholder="Jegyzetek – @ jellel megemlítheted a kollégáidat. (Ctrl+Enter: mentés)"></textarea><div class="ml-mpop" hidden></div><button type="button" class="cpx-btn" data-nsave>Jegyzet</button></div>';
		const act = async (a, p) => { try { const r = await thAct(t.id, a, p); drawThread(box, rd, r); refreshItem(box, r.t); } catch (e) { toast(e.message, true); } };
		rd.querySelector('[data-st]').addEventListener('click', () => act('cp_mail_thread_set', { f: 'status', v: t.st === 'closed' ? 'open' : 'closed' }));
		rd.querySelector('[data-tag]').addEventListener('click', () => { const v = window.prompt('Címkék (vesszővel elválasztva):', t.tags || ''); if (v !== null) act('cp_mail_thread_set', { f: 'tags', v: v }); });
		rd.querySelector('[data-as]').addEventListener('change', e => act('cp_mail_thread_set', { f: 'assignee', v: e.target.value }));
		/* v1.52: válasz a panelből (próbaüzem) */
		const reply = (m, all) => {
			const mine = (M.send.from || '').toLowerCase();
			const pick = s => (String(s || '').match(/[^\s<>,;"']+@[^\s<>,;"']+/g) || []).map(x => x.toLowerCase()).filter(x => x !== mine);
			const toL = m.dir === 'out' ? pick(m.to_list) : [String(m.from_email || '').toLowerCase()].filter(Boolean);
			const ccL = all ? Array.from(new Set(pick(m.to_list).concat(pick(m.cc_list)))).filter(x => !toL.includes(x)) : [];
			const subj = /^(re|vá|va|aw)\s*:/i.test(t.subj || '') ? t.subj : 'Re: ' + (t.subj || '');
			const quote = '<p><br></p><p>' + esc(hdate(m.sent_at)) + ', ' + esc(m.from_name || m.from_email) + ' ezt írta:</p><blockquote style="margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex">' + (m.body || '') + '</blockquote>';
			S.reply = { to: toL, cc: ccL, subject: subj, body: quote, quote: quote, thread: t.id > 0 ? t.id : 0, tsubj: t.subj, deal: d && d.id ? d.id : 0, dealTitle: d ? d.title : '' };
			S.view = 'compose'; view(S.main);
		};
		loadHtml(rd);
		const tl = timeline(x);
		rd.querySelectorAll('[data-reply]').forEach(b => b.addEventListener('click', () => { const it = tl[Number(b.dataset.reply)]; if (it && it.m) reply(it.m, false); }));
		rd.querySelectorAll('[data-replyall]').forEach(b => b.addEventListener('click', () => { const it = tl[Number(b.dataset.replyall)]; if (it && it.m) reply(it.m, true); }));
		/* v1.61: mobilon kék gyorsgomb a szál alján (Salesmate Quick Actions mintájára) */
		const qa = rd.querySelector('[data-qa]'), lastM = () => { let r = null; tl.forEach(i => { if (i.m && (!r || String(i.m.sent_at || '') >= String(r.sent_at || ''))) r = i.m; }); return r; };
		const qaShow = on => { qa.hidden = !on; };
		rd.querySelector('[data-tfab]').addEventListener('click', () => qaShow(true));
		qa.querySelectorAll('[data-qax]').forEach(b => b.addEventListener('click', () => qaShow(false)));
		qa.querySelector('[data-qn]').addEventListener('click', () => { qaShow(false); const n = rd.querySelector('[data-note]'); if (n) { n.scrollIntoView({ block: 'center', behavior: 'smooth' }); n.focus(); } });
		qa.querySelector('[data-qr]').addEventListener('click', () => { const m = lastM(); qaShow(false); if (m) reply(m, false); });
		qa.querySelector('[data-qra]').addEventListener('click', () => { const m = lastM(); qaShow(false); if (m) reply(m, true); });
		const bk = rd.querySelector('[data-back]');
		if (bk) bk.addEventListener('click', () => { S.reading = false; S.th = null; box.querySelectorAll('.ml-item.on').forEach(x => x.classList.remove('on')); const ib = box.querySelector('.ml-inbox'); if (ib) ib.classList.remove('reading'); const it = box.querySelector('.ml-item[data-th="' + t.id + '"]'); if (it) it.scrollIntoView({ block: 'center' }); });
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
		rd.querySelectorAll('[data-one]').forEach(b => b.addEventListener('click', () => { if (b.dataset.one === 'unread') S.th = null; bulk(box, [t.id], b.dataset.one, ''); }));
		rd.querySelector('[data-fol]').addEventListener('change', e => act('cp_mail_thread_set', { f: 'follow', v: e.target.checked ? '1' : '0' }));
		const det = rd.querySelector('[data-detach]');
		if (det) det.addEventListener('click', () => { if (window.confirm('Leválasztod a dealt erről a levélszálról?')) act('cp_mail_thread_set', { f: 'deal', v: '' }); });
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
		i3.innerHTML = (t.note ? '<span class="ml-inote">' + NOTE_I + esc(t.note.b.replace(/\s+/g, ' ')) + '</span>' : '<span>' + esc(t.snip) + '</span>') + (t.cnt ? '<i>' + t.cnt + '</i>' : '');
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
		let tpls = [];
		try { tpls = await loadTpls(); } catch (e) { /* sablon nélkül is írható */ }
		let deal = null;
		const draft = S.reply || (() => { try { return JSON.parse(localStorage.getItem('cpmail_draft') || 'null'); } catch (e) { return null; } })();
		S.reply = null;
		const ctx = { thread: draft && draft.thread ? Number(draft.thread) : 0, subj: draft && draft.tsubj ? draft.tsubj : '', quote: draft && draft.quote ? draft.quote : '' };
		const SON = !!(M.send && M.send.on);
		box.innerHTML = '<div class="ml-compose">' +
			/* v1.62: mobilon a Salesmate „Compose” fejléce: bezárás, cím, melléklet, küldés, továbbiak */
			'<div class="ml-ctb"><button type="button" data-mx aria-label="Bezárás" title="Bezárás (a piszkozat megmarad)">✕</button><b>' + (ctx.thread ? 'Válasz' : 'Új levél') + '</b><button type="button" data-matt aria-label="Melléklet"' + (SON ? '' : ' disabled') + '><svg viewBox="0 0 24 24"><path d="M20 11.5l-8.2 8.2a5 5 0 0 1-7.1-7.1l8.6-8.6a3.4 3.4 0 0 1 4.8 4.8l-8.6 8.6a1.7 1.7 0 0 1-2.4-2.4l7.9-7.9"/></svg></button><button type="button" class="ml-ctb-send" data-msend aria-label="Küldés"' + (SON ? '' : ' disabled') + '><svg viewBox="0 0 24 24"><path d="M3 20l18-8L3 4l2 8z"/><path d="M5 12h8"/></svg></button><button type="button" data-mmore aria-label="Továbbiak">⋮</button></div>' +
			'<div class="ml-qa" data-cqa hidden><div class="ml-qab" data-cqax></div><div class="ml-qas"><div class="ml-qah"><b>Továbbiak</b><button type="button" data-cqax aria-label="Bezárás">✕</button></div>' +
			(ctx.thread && M.ai ? '<button type="button" data-mq="ai">✨ AI válasz</button>' : '') + '<button type="button" data-mq="deal">🔗 Deal csatolása</button>' + (C.portal && C.portal.on ? '<button type="button" data-mq="portal">📝 Ügyfélportál-link beszúrása</button>' : '') +
			(SON ? '<button type="button" data-mq="test">🧪 Próbalevél magamnak</button>' : '') + '<button type="button" data-mq="prev">👁 Előnézet</button><button type="button" data-mq="save">💾 Piszkozat mentése</button>' +
			(ctx.thread ? '<button type="button" data-mq="unthread">✉ Új levélként küldöm</button>' : '') + '<button type="button" data-mq="clear">🗑 Új, üres levél</button>' + (SON ? '' : '<small>A panelből küldés neked még nincs bekapcsolva.</small>') + '</div></div>' +
			(ctx.thread ? '<div class="ml-rctx">↩ Válasz a levélszálra: <b>' + esc(ctx.subj || '#' + ctx.thread) + '</b>' + (M.ai ? '<button type="button" class="ml-aibtn" data-cai title="Válaszpiszkozat az AI-val a levélszál alapján">✨ AI válasz</button>' : '<button type="button" class="ml-aibtn" disabled title="Az AI-válasz az AI-kulcs megadása után érhető el (WordPress → Beállítások → Crazy AI)">✨ AI válasz</button>') + '<button type="button" class="ml-link" data-unthread>Új levélként küldöm</button></div><div data-caip hidden class="ml-aip"></div>' : '') +
			'<div class="ml-ctop"><span class="ml-rel"><button type="button" class="ml-tbtn" data-tplbtn><i class="ml-tpli"><svg viewBox="0 0 24 24"><path d="M6 3h12v18l-6-4.5L6 21z"/></svg></i>🔖 Sablon beszúrása ▾</button></span><div data-dp class="ml-grow"></div></div>' +
			'<div class="ml-row"><label>Feladó</label><select data-from>' + BOXES.map(b => '<option value="' + esc(b.id) + '">' + esc(b.n + (b.e ? ' (' + b.e + ')' : '')) + '</option>').join('') + '</select></div>' +
			'<div class="ml-row"><label>Címzett</label><div class="ml-chips" data-to></div><button type="button" class="ml-link" data-cc>Másolat / Rejtett</button></div>' +
			'<div class="ml-row" data-ccrow hidden><label>Másolat</label><div class="ml-chips" data-ccv></div></div>' +
			'<div class="ml-row" data-ccrow hidden><label>Rejtett</label><div class="ml-chips" data-bcc></div></div>' +
			'<div class="ml-row ml-srow"><label>Tárgy</label><input type="text" data-subj placeholder="Tárgy" value="' + esc(draft ? draft.subject : '') + '"></div>' +
			'<div data-ed></div>' +
			'<div class="ml-send">' + ((M.send && M.send.on) ? '<button type="button" class="cpx-btn" data-send>Küldés</button><button type="button" class="cpx-btn ghost" data-test title="Ugyanez a levél csak neked, [PRÓBA] tárggyal – a szálba nem kerül be">Próbalevél magamnak</button>' : '<button type="button" class="cpx-btn" disabled title="A panelből küldés neked nincs bekapcsolva (próbaüzem) – küldj a Salesmate-ből">Küldés</button>') + '<button type="button" class="cpx-btn ghost" disabled title="Később">Ütemezés</button>' +
			'<button type="button" class="cpx-btn ghost" data-prev>Előnézet</button><button type="button" class="cpx-btn ghost" data-save>Piszkozat mentése</button><button type="button" class="ml-link" data-clear>Új, üres levél</button>' +
			'<label class="ml-track" title="A megnyitás követése később jön"><input type="checkbox" disabled> Megnyitás követése (később)</label></div>' +
			'<div class="ml-prev" hidden></div></div>';
		const q = sel => box.querySelector(sel);
		/* v1.58: az aláírás írás közben a levélben van (válasznál az idézett levél fölött), mint a Salesmate-ben */
		const SIG = M.sig ? '<div class="cp-sign">' + M.sig + '</div>' : '';
		const withSig = b => { b = b || ''; if (!SIG || /cp-sign/.test(b)) return b; if (ctx.quote && b.indexOf(ctx.quote) >= 0) return b.replace(ctx.quote, SIG + ctx.quote); return b + SIG; };
		const ed = editor(q('[data-ed]'), withSig(draft ? (draft.quote && draft.body === draft.quote ? '<p><br></p>' + draft.body : draft.body) : '<p><br></p>'));
		const to = chips(q('[data-to]'), draft ? draft.to : []), cc = chips(q('[data-ccv]'), draft && draft.cc ? draft.cc : []), bcc = chips(q('[data-bcc]'), []);
		if (draft && draft.cc && draft.cc.length) box.querySelectorAll('[data-ccrow]').forEach(r => { r.hidden = false; });
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
		const attBox = document.createElement('div'); attBox.className = 'ml-attl'; q('[data-ed]').after(attBox);
		const kb = n => n > 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
		const drawAtt = () => { attBox.innerHTML = atts.map((a, i) => '<span class="ml-attc' + (a.err ? ' err' : a.tok ? '' : ' busy') + '">' + CLIP_I + esc(a.n) + ' <small>' + (a.err ? esc(a.err) : a.tok ? kb(a.size) : 'feltöltés…') + '</small><button type="button" data-arm="' + i + '" aria-label="Eltávolítás">×</button></span>').join(''); attBox.querySelectorAll('[data-arm]').forEach(b => b.addEventListener('click', () => { atts.splice(Number(b.dataset.arm), 1); drawAtt(); })); };
		const upload = async file => {
			const a = { n: file.name, size: file.size, tok: '', err: '' }; atts.push(a); drawAtt();
			if (file.size > 15 * 1048576) { a.err = 'túl nagy (max. 15 MB)'; drawAtt(); return; }
			try { const fd = new FormData(); fd.append('action', 'cp_mail_upload'); fd.append('t', C.t); fd.append('f', file); const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }); const j = await r.json(); if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || 'hiba'); a.tok = j.data.tok; a.n = j.data.n; }
			catch (e) { a.err = e.message || 'hiba'; }
			drawAtt();
		};
		/* v1.59: friss, személyes ügyfélportál-link a dealhez – gombként a levélbe */
		const pb = box.querySelector('[data-cmd="portal"]');
		if (pb) {
			if (!(C.portal && C.portal.on)) pb.hidden = true;
			pb.addEventListener('click', async () => {
				const did = deal && deal.id ? deal.id : 0;
				if (!did) { toast('Előbb válassz deal-t a levélhez (fent a deal-keresőben).', true); return; }
				try {
					let r = await post('cp_portal_mail_link', { deal: did });
					if (r.none) {
						if (!window.confirm('Ehhez a dealhez még nincs ügyfélportál. Létrehozzam most (külön meghívó levél nélkül), és a linkjét ebbe a levélbe teszem?')) return;
						r = await post('cp_portal_mail_link', { deal: did, create: 1 });
					}
					ed.html('<p><a href="' + esc(r.url) + '" target="_blank" style="display:inline-block;background:#F2A33A;color:#ffffff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:bold;font-family:Arial,sans-serif">Rendezvény-adatlap kitöltése</a></p><p style="font-size:12px;color:#666666">Ha a gomb nem működik, ezt a linket másold a böngészőbe: ' + esc(r.url) + '</p>');
					toast('A személyes portál-link a levélben (csak ennek az ügyfélnek szól).');
				} catch (e) { toast(e.message, true); }
			});
		}
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
		const doSend = async test => {
			const tl = to.get(), sj = q('[data-subj]').value.trim(), body = ed.get();
			if (!test && !tl.length) { toast('Adj meg címzettet.', true); return; }
			if (!sj) { toast('Add meg a tárgyat.', true); q('[data-subj]').focus(); return; }
			if (ed.isEmpty()) { toast('Üres a levél.', true); return; }
			if (!test && !window.confirm('Elküldöd a levelet?\n\nCímzett: ' + tl.join(', ') + (cc.get().length ? '\nMásolat: ' + cc.get().join(', ') : '') + (bcc.get().length ? '\nRejtett: ' + bcc.get().join(', ') : '') + '\nTárgy: ' + sj + (atts.length ? '\nMelléklet: ' + atts.map(a => a.n).join(', ') : '') + '\n\nA levél a postafiókból megy ki, és az Elküldött mappába is bekerül.')) return;
			if (atts.some(a => !a.tok && !a.err)) { toast('Várd meg, amíg a mellékletek feltöltődnek.', true); return; }
			if (atts.some(a => a.err)) { toast('Egy melléklet nem töltődött fel – vedd ki (×), vagy csatold újra.', true); return; }
			const b = test ? testBtn : sendBtn; b.disabled = true; const old = b.textContent; b.textContent = 'Küldés…';
			try {
				const r = await post('cp_mail_send', { to: tl.join(','), cc: cc.get().join(','), bcc: bcc.get().join(','), subject: sj, body: body, deal: deal ? deal.id : '', thread: ctx.thread || '', sign: '', test: test ? 1 : '', att: atts.map(a => a.tok).join(',') });
				if (r.test) { toast('Próbalevél elküldve ide: ' + r.to); b.disabled = false; b.textContent = old; return; }
				try { localStorage.removeItem('cpmail_draft'); } catch (e) { /* privát mód */ }
				toast('Levél elküldve.' + (r.warn ? ' ' + r.warn : ''), !!r.warn);
				/* v1.84: a hívó (pl. „Ajánlat küldése” a dealen) értesítése a sikeres küldésről */
				if (S.onSent) { const f = S.onSent; S.onSent = null; try { f(r); } catch (x) { /* a levél elment */ } }
				open(r.thread);
			} catch (e) { b.disabled = false; b.textContent = old; toast(e.message, true); }
		};
		if (sendBtn) sendBtn.addEventListener('click', () => doSend(false));
		if (testBtn) testBtn.addEventListener('click', () => doSend(true));
		dealPicker(q('[data-dp]'), x => { deal = x; if (x.ce) to.add(x.ce); });
		q('[data-cc]').addEventListener('click', () => box.querySelectorAll('[data-ccrow]').forEach(r => { r.hidden = !r.hidden; }));
		q('[data-tplbtn]').addEventListener('click', e => {
			e.stopPropagation();
			if (box.querySelector('.ml-tpop')) { box.querySelector('.ml-tpop').remove(); return; }
			tplMenu(e.currentTarget, tpls, t => {
				if (!ed.isEmpty() && !window.confirm('A sablon felülírja a levél jelenlegi szövegét. Mehet?')) return;
				if (t.subject) q('[data-subj]').value = t.subject;
				ed.set(withSig(t.body || ''));
			});
		});
		q('[data-save]').addEventListener('click', () => {
			try { localStorage.setItem('cpmail_draft', JSON.stringify({ to: to.get(), cc: cc.get(), subject: q('[data-subj]').value, body: ed.get(), thread: ctx.thread || 0, tsubj: ctx.subj, quote: ctx.quote || '', deal: deal ? deal.id : 0, dealTitle: deal ? deal.title : '' })); toast('Piszkozat elmentve ezen a gépen.'); } catch (e) { toast('A piszkozatot nem sikerült menteni.', true); }
		});
		q('[data-clear]').addEventListener('click', () => { if (!window.confirm('Törlöd a levél tartalmát?')) return; try { localStorage.removeItem('cpmail_draft'); } catch (e) { /* privát mód */ } drawCompose(box); });
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
					try { localStorage.setItem('cpmail_draft', JSON.stringify({ to: r.to ? [r.to] : [], subject: r.subject, body: r.html, thread: t.id > 0 ? t.id : 0, tsubj: t.subj, deal: t.deal || 0 })); localStorage.setItem('cpmail_view', 'compose'); } catch (e) { toast('A piszkozatot nem sikerült átvinni.', true); return; }
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
	window.CPMail = { view, status, open, compose, canSend: () => !!(M.send && M.send.on), refresh: async () => { S.tpls = null; if (S.main && S.main.isConnected) view(S.main); } };
})();
