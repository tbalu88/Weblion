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
		main.innerHTML = '<div class="ml">' + (M.connected ? '' : '<p class="ml-note">Előkészítés alatt: a postafiókok bekötéséig a leveleket a Salesmate Team Inboxban kezeld.</p>') +
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
	const timeline = x => (x.msgs || []).map(m => ({ at: String(m.sent_at || ''), m: m })).concat((x.events || []).map(e => ({ at: String(e.created_at || ''), ev: e }))).sort((a, b) => (a.at < b.at ? -1 : a.at > b.at ? 1 : 0));

	/* minta (a postafiókok bekötéséig): csak a böngészőben, nem mentődik */
	function demoData() {
		const now = Date.now(), at = m => { const d = new Date(now - m * 60000); const p = n => (n < 10 ? '0' : '') + n; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()); };
		const me = PEOPLE[0] || { id: 1, n: 'Andrea Gyarmati' };
		return [
			{ id: -1, box: 'eventcrazy', subj: 'Ajánlatkérés – Konferencia fotózás, egyedi fotófal', cn: 'Minta Mónika', ce: 'monika@example.com', deal: 0, as: me.id, st: 'open', folder: 'inbox', snip: 'Szia Andi, van már esetleg frissített ár a 2 méter magas fotófalra?', cnt: 14, att: 0, at: at(1), fol: [], tags: '', note: { b: 'Fólia: Szerkezet: LED-es dekorvilágítás: 450k', by: me.n, at: at(3) },
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

	async function drawInbox(box) {
		const F = [['inbox', 'Beérkezett'], ['follow', 'Követett'], ['outbox', 'Kimenő'], ['sent', 'Elküldött'], ['draft', 'Piszkozatok'], ['archive', 'Archív'], ['trash', 'Kuka'], ['spam', 'Spam']];
		S.tab = S.tab || 'open';
		let rows = [], counts = {};
		if (S.demo) { rows = (S.demoRows = S.demoRows || demoData()).filter(t => (S.tab !== 'open' || t.st === 'open') && (S.tab !== 'unassigned' || !t.as)); counts = { inbox: S.demoRows.filter(t => t.st === 'open').length, follow: S.demoRows.filter(t => t.fol.length).length }; }
		else { try { const r = await post('cp_mail_threads', { folder: S.folder, tab: S.tab, box: S.box, q: S.mq || '' }); rows = r.rows; counts = r.counts || {}; } catch (e) { rows = []; S.err = e.message; } }
		const L = cols();
		box.innerHTML = '<div class="ml-inbox' + (L.fold ? ' fold' : '') + '" style="--c1:' + (L.fold ? 40 : L.c1) + 'px;--c2:' + L.c2 + 'px">' +
			'<aside class="ml-side"><div class="ml-sideh"><button type="button" class="cpx-btn ml-new" data-compose>Új levél</button><button type="button" class="ml-fold" data-fold title="' + (L.fold ? 'Mappák kinyitása' : 'Mappák összecsukása') + '">' + (L.fold ? '»' : '«') + '</button></div>' +
			'<select class="ml-box" aria-label="Postafiók"><option value="">Minden postafiók</option>' + BOXES.map(b => '<option value="' + esc(b.id) + '"' + (S.box === b.id ? ' selected' : '') + '>' + esc(b.n) + '</option>').join('') + '</select>' +
			'<nav class="ml-folders">' + F.map(f => '<button type="button" data-f="' + f[0] + '" class="' + (S.folder === f[0] ? 'on' : '') + '"><span>' + f[1] + '</span><b>' + (counts[f[0]] || '') + '</b></button>').join('') + '</nav>' +
			'<label class="ml-demo"><input type="checkbox" data-demo' + (S.demo ? ' checked' : '') + '> Minta megtekintése</label></aside><div class="ml-split" data-split="c1" title="Húzd az oszlop méretezéséhez (dupla kattintás: alapméret)"></div>' +
			'<section class="ml-list"><input type="search" class="ml-q" placeholder="Keresés a levelek között" value="' + esc(S.mq || '') + '">' +
			'<div class="ml-tabs">' + [['open', 'Nyitott'], ['unassigned', 'Kiosztatlan'], ['all', 'Mind']].map(x => '<button type="button" data-tab="' + x[0] + '" class="' + (S.tab === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div>' +
			'<div class="ml-items">' + (rows.length ? rows.map(t => '<button type="button" class="ml-item' + (S.th === t.id ? ' on' : '') + (t.st === 'closed' ? ' closed' : '') + '" data-th="' + t.id + '">' +
				'<span class="ml-av" style="background:' + avc(t.cn) + '">' + esc(ini(t.cn)) + '</span><span class="ml-ib">' +
				'<span class="ml-i1"><b>' + esc(t.cn || t.ce) + '</b>' + (t.as ? '<span class="ml-as">› ' + esc(pname(t.as) || 'kolléga') + '</span>' : '') + '<small>' + esc(ago(t.at)) + '</small></span>' +
				'<span class="ml-i2">' + (t.att ? CLIP_I : '') + (t.st === 'closed' ? '✓ ' : '') + esc(t.subj) + '</span>' +
				'<span class="ml-i3">' + (t.note ? '<span class="ml-inote">' + NOTE_I + esc(t.note.b.replace(/\s+/g, ' ')) + '</span>' : '<span>' + esc(t.snip) + '</span>') + (t.cnt ? '<i>' + t.cnt + '</i>' : '') + '</span></span></button>').join('') :
				'<div class="ml-empty"><b>' + (M.connected ? 'Nincs levél' : 'Még nincsenek levelek') + '</b><span>' + (M.connected ? '' : 'A postafiókok bekötése után itt jelennek meg a beérkező levelek, deal-ekhez és kollégákhoz rendelve. Addig a „Minta megtekintése” kapcsolóval kipróbálhatod a nézetet.') + '</span></div>') + '</div></section><div class="ml-split" data-split="c2" title="Húzd az oszlop méretezéséhez (dupla kattintás: alapméret)"></div>' +
			'<section class="ml-read" data-read></section></div>';
		layout(box);
		box.querySelectorAll('[data-f]').forEach(b => b.addEventListener('click', () => { S.folder = b.dataset.f; S.th = null; drawInbox(box); }));
		box.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => { S.tab = b.dataset.tab; drawInbox(box); }));
		box.querySelector('.ml-box').addEventListener('change', e => { S.box = e.target.value; drawInbox(box); });
		box.querySelector('[data-demo]').addEventListener('change', e => { S.demo = e.target.checked; S.th = null; drawInbox(box); });
		let qt = null;
		box.querySelector('.ml-q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { S.mq = e.target.value; drawInbox(box).then(() => { const i = box.querySelector('.ml-q'); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }); }, 350); });
		box.querySelector('[data-compose]').addEventListener('click', () => { S.view = 'compose'; view(S.main); });
		box.querySelectorAll('[data-th]').forEach(b => b.addEventListener('click', () => { S.th = Number(b.dataset.th); box.querySelectorAll('.ml-item').forEach(x => x.classList.toggle('on', x === b)); openThread(box, S.th); }));
		const rd = box.querySelector('[data-read]');
		if (S.th && (rows.some(t => t.id === S.th) || S.forceTh === S.th)) { S.forceTh = 0; openThread(box, S.th); }
		else rd.innerHTML = '<div class="ml-empty"><b>Válassz egy levelet</b><span>Itt jelenik meg a levélváltás, fölötte a hozzá tartozó deal kártyája és a kollégák jegyzetei; alul írhatsz jegyzetet, @ jellel megemlítve a kollégákat.</span></div>';
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
		rd.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
		let x;
		try { x = id > 0 ? await post('cp_mail_thread', { id: id }) : await thAct(id, 'view', {}); } catch (e) { rd.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		drawThread(box, rd, x);
	}

	function drawThread(box, rd, x) {
		const t = x.t, d = x.deal;
		const me = M.me || (PEOPLE[0] || {}).id || 0;
		const following = (t.fol || []).includes(me);
		const money = v => (v == null ? '–' : Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft');
		rd.innerHTML = '<div class="ml-rtool"><button type="button" data-st>' + (t.st === 'closed' ? '↺ Újranyitás' : '✓ Lezárás') + '</button><button type="button" data-tag>🏷 ' + (t.tags ? esc(t.tags) : 'Címke') + '</button>' + (M.ai && t.id > 0 ? '<button type="button" class="ml-aibtn" data-ai title="Válaszpiszkozat az AI-val (átnézed, mielőtt elküldöd)">✨ AI válasz</button>' : '') + (window.CPKanban && t.id > 0 ? '<button type="button" data-todo title="Teendő felvétele a Feladatokba">☑ Teendő</button>' : '') +
			'<span class="crm-bgap"></span><span class="ml-fols">' + (t.fol || []).slice(0, 3).map(f => '<i style="background:' + avc(pname(f)) + '" title="' + esc(pname(f)) + '">' + esc(ini(pname(f))) + '</i>').join('') + ((t.fol || []).length ? '' : '<small>Nincs követő</small>') + '</span>' +
			'<select data-as aria-label="Felelős"><option value="">Kiosztatlan</option>' + PEOPLE.map(p => '<option value="' + p.id + '"' + (t.as === p.id ? ' selected' : '') + '>' + esc(p.n) + '</option>').join('') + '</select>' +
			'<label class="ml-sw"><input type="checkbox" data-fol' + (following ? ' checked' : '') + '><span></span> Követés</label></div>' +
			'<div class="ml-subjh"><h3>' + esc(t.subj) + '</h3>' + (d ? '' : '<button type="button" class="ml-link" data-attach>$ Deal csatolása</button>') + '</div><div data-dpat hidden class="ml-dpat"></div><div data-kbq hidden class="ml-dpat"></div><div data-aip hidden class="ml-aip"></div><div data-kbl class="ml-kbl"></div>' +
			'<div class="ml-thread">' +
			(d ? '<div class="ml-dcard">' + DEAL_I + '<div><a href="#deals" data-opendeal="' + (d.id || '') + '">' + esc(d.title) + '</a><small>' + [money(d.value), d.cn, d.co, d.stage].filter(Boolean).map(esc).join('<i>•</i>') + '</small></div><button type="button" class="ml-dx" data-detach aria-label="Deal leválasztása" title="Deal leválasztása">' + X_I + '</button></div>' : '') +
			(x.notes || []).map(n => '<div class="ml-ncard"><div class="ml-nh"><span class="ml-av" style="background:' + avc(n.author) + '">' + esc(ini(n.author)) + '</span><b>Jegyzet</b> – ' + esc(n.author) + '<small>' + esc(ago(n.created_at)) + '</small>' + (n.author_uid === me || M.admin ? '<button type="button" class="ml-ndel" data-ndel="' + n.id + '" title="Jegyzet törlése">×</button>' : '') + '</div><div class="ml-nb">' + nl2br(n.body) + '</div></div>').join('') +
			timeline(x).map(it => it.ev ? '<div class="ml-ev"><span class="ml-av ml-av1 ev" style="background:' + avc(it.ev.author) + '">' + esc(ini(it.ev.author).slice(0, 1)) + '</span><span><b>' + esc(it.ev.text) + '</b>' + (it.ev.author ? ' – ' + esc(it.ev.author) : '') + '</span><small>' + esc(ago(it.ev.created_at)) + '</small></div>' : (m => '<article class="ml-msg"><div class="ml-mh"><span class="ml-av lg ml-av1' + (m.dir === 'out' ? ' out' : '') + '"' + (m.dir === 'out' ? '' : ' style="background:' + avc(m.from_name) + '"') + '>' + esc(ini(m.from_name).slice(0, 1)) + '</span><div><div class="ml-from"><span>' + esc(m.from_name) + '</span> <span class="ml-fe">&lt;' + esc(m.from_email) + '&gt;</span>' + (m.status ? '<span class="ml-dlv">' + esc(m.status) + ' ▾</span>' : '') + '</div>' +
				'<div class="ml-to">Címzett: ' + addr(m.to_list) + (m.cc_list ? '<br>Másolat: ' + addr(m.cc_list) : '') + '</div></div><span class="ml-mt"><span class="ml-ma"><button type="button" disabled title="Válasz">' + RE_I + '</button><button type="button" disabled title="Válasz mindenkinek">' + REA_I + '</button><button type="button" disabled title="Törlés">' + DEL_I + '</button><button type="button" disabled title="Továbbiak">' + MORE_I + '</button></span>' + esc(hdate(m.sent_at)) + '</span></div>' +
				'<div class="ml-mb">' + (m.body || '') + '</div>' + ((m.attachments || []).length ? '<div class="ml-atts">' + CLIP_I + ' <b>Mellékletek:</b> ' + m.attachments.map(a => '<span>' + esc(a.n) + '</span>').join(', ') + '</div>' : '') + '</article>')(it.m)).join('') +
			'</div>' +
			'<div class="ml-ncomp"><textarea rows="1" data-note placeholder="Jegyzetek – @ jellel megemlítheted a kollégáidat. (Ctrl+Enter: mentés)"></textarea><div class="ml-mpop" hidden></div><button type="button" class="cpx-btn" data-nsave>Jegyzet</button></div>';
		const act = async (a, p) => { try { const r = await thAct(t.id, a, p); drawThread(box, rd, r); refreshItem(box, r.t); } catch (e) { toast(e.message, true); } };
		rd.querySelector('[data-st]').addEventListener('click', () => act('cp_mail_thread_set', { f: 'status', v: t.st === 'closed' ? 'open' : 'closed' }));
		rd.querySelector('[data-tag]').addEventListener('click', () => { const v = window.prompt('Címkék (vesszővel elválasztva):', t.tags || ''); if (v !== null) act('cp_mail_thread_set', { f: 'tags', v: v }); });
		rd.querySelector('[data-as]').addEventListener('change', e => act('cp_mail_thread_set', { f: 'assignee', v: e.target.value }));
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
					'<div class="ml-aia"><button type="button" class="cpx-btn" data-take>Átvétel a levélíróba</button><button type="button" class="cpx-btn ghost" data-again>Újraírás</button><small class="cpx-muted">Új kérést fent írhatsz, majd Újraírás.</small></div>';
				out.querySelector('[data-take]').addEventListener('click', () => {
					try { localStorage.setItem('cpmail_draft', JSON.stringify({ to: r.to ? [r.to] : [], subject: r.subject, body: r.html })); localStorage.setItem('cpmail_view', 'compose'); } catch (e) { toast('A piszkozatot nem sikerült átvinni.', true); return; }
					S.view = 'compose'; view(S.main); toast('A piszkozat a levélíróban – nézd át, és töltsd ki a jelölt részeket.');
				});
				out.querySelector('[data-again]').addEventListener('click', run);
			} catch (e) { out.innerHTML = '<div class="ml-aierr">' + esc(e.message) + '</div>'; }
			gen.disabled = false; wish.disabled = false;
		};
		gen.addEventListener('click', run);
		wish.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); run(); } });
		wish.focus();
	}

	/* v1.35: egy levélszál megnyitása más fülről (pl. a Feladatok kártyájáról) */
	function open(id) { S.view = 'inbox'; S.th = id; S.forceTh = id; if (window.CPXGo) window.CPXGo('mail'); else if (S.main) view(S.main); }
	window.CPMail = { view, status, open, refresh: async () => { S.tpls = null; if (S.main && S.main.isConnected) view(S.main); } };
})();
