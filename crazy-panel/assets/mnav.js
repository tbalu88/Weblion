/* =====================================================================
 * Mobil alsó menüsor (v1.64) – a Salesmate alkalmazás mintájára
 * ☰ Menü (felhasználó, Értesítések / Frissítés / Beállítások / Kilépés + minden további fül) · Deal-ek · Levelezés ·
 * Kapcsolatok · Keresés. Csak keskeny kijelzőn látszik; a fülek a panel saját jogosultságai szerint (a felső menüből).
 * ===================================================================== */
(function () {
	'use strict';
	const C = window.CPX || {};
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const ico = id => (window.CPXIco ? window.CPXIco(id) : '');
	const SVG = p => '<svg class="cpx-ico" viewBox="0 0 24 24" aria-hidden="true">' + p + '</svg>';
	const I_MENU = SVG('<path d="M4 7h16M4 12h16M4 17h16"/>');
	const I_BELL = SVG('<path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>');
	const MAIN = ['deals', 'mail', 'kapcsolatok'];
	const S = { bar: null, sheet: null };
	const tabs = () => Array.from(document.querySelectorAll('.cpx-tabs [data-tab]')).map(b => ({ id: b.dataset.tab, label: (b.querySelector('span') || b).textContent.trim() }));
	const cur = () => { const b = document.querySelector('.cpx-tabs [data-tab].on'); return b ? b.dataset.tab : location.hash.slice(1).split(':')[0]; };
	const go = id => { closeSheet(); if (window.CPXGo) window.CPXGo(id); window.scrollTo(0, 0); mark(); };

	function mark() {
		if (!S.bar) return;
		const c = cur();
		S.bar.querySelectorAll('[data-mt]').forEach(b => b.classList.toggle('on', b.dataset.mt === c || (b.dataset.mt === '__menu' && !MAIN.includes(c) && !!S.sheet)));
		const n = document.querySelector('.cpx-bell-n');
		const dot = S.bar.querySelector('.mn-dot');
		if (dot) dot.hidden = !(n && !n.hidden);
	}

	function closeSheet() { if (S.sheet) { S.sheet.remove(); S.sheet = null; mark(); } }
	function openSheet() {
		if (S.sheet) { closeSheet(); return; }
		const all = tabs().filter(t => !MAIN.includes(t.id) && t.id !== 'beallitasok');
		const has = id => tabs().some(t => t.id === id);
		const bell = document.querySelector('.cpx-bell'), bn = document.querySelector('.cpx-bell-n');
		const who = C.user || (C.role === 'iroda' ? 'Iroda' : 'Admin');
		const sh = document.createElement('div');
		sh.className = 'mn-sheet';
		sh.innerHTML = '<div class="mn-bg" data-x></div><div class="mn-card"><div class="mn-me"><span class="mn-av">' + esc(who.trim().split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase()) + '</span><div><b>' + esc(who) + '</b><small>' + (C.role === 'iroda' ? 'Iroda' : 'Admin') + ' · Crazy panel</small></div></div>' +
			'<div class="mn-acts">' + (has('beallitasok') ? '<button type="button" data-a="set" title="Beállítások">' + ico('beallitasok') + '</button>' : '') +
			(bell ? '<button type="button" data-a="bell" title="Értesítések">' + I_BELL + (bn && !bn.hidden ? '<i></i>' : '') + '</button>' : '') +
			'<button type="button" data-a="ref" title="Frissítés a Salesmate-ből">' + ico('refresh') + '</button>' +
			(C.logout ? '<a href="' + esc(C.logout) + '" title="Kilépés">' + ico('logout') + '</a>' : '') + '</div>' +
			'<div class="mn-list">' + all.map(t => '<button type="button" data-go="' + esc(t.id) + '" class="' + (cur() === t.id ? 'on' : '') + '">' + ico(t.id) + '<span>' + esc(t.label) + '</span></button>').join('') + '</div></div>';
		document.body.appendChild(sh);
		S.sheet = sh;
		sh.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', closeSheet));
		sh.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => go(b.dataset.go)));
		const a = k => sh.querySelector('[data-a="' + k + '"]');
		if (a('set')) a('set').addEventListener('click', () => go('beallitasok'));
		if (a('bell')) a('bell').addEventListener('click', e => { e.stopPropagation(); closeSheet(); const b = document.querySelector('.cpx-bell'); if (b) b.click(); });
		a('ref').addEventListener('click', () => { closeSheet(); const r = document.getElementById('cpx-refresh'); if (r) r.click(); });
		mark();
	}

	function mount() {
		if (!document.querySelector('.cpx-tabs')) return false;
		if (S.bar) return true;
		const have = tabs().map(t => t.id);
		const item = (id, label, icon) => '<button type="button" data-mt="' + id + '" aria-label="' + esc(label) + '">' + icon + '<span>' + esc(label) + '</span></button>';
		const bar = document.createElement('nav');
		bar.className = 'mn-bar';
		bar.innerHTML = item('__menu', 'Menü', I_MENU + '<b class="mn-dot" hidden></b>') +
			(have.includes('deals') ? item('deals', 'Deal-ek', ico('deals')) : '') +
			(have.includes('mail') ? item('mail', 'Levelezés', ico('mail')) : '') +
			(have.includes('kapcsolatok') ? item('kapcsolatok', 'Kapcsolatok', ico('kapcsolatok')) : '') +
			(window.CPSearch ? item('__search', 'Keresés', ico('search')) : '');
		document.body.appendChild(bar);
		document.body.classList.add('mn-on');
		S.bar = bar;
		bar.querySelectorAll('[data-mt]').forEach(b => b.addEventListener('click', e => {
			e.stopPropagation();
			const id = b.dataset.mt;
			if (id === '__menu') openSheet();
			else if (id === '__search') { closeSheet(); window.CPSearch.open(''); }
			else go(id);
		}));
		new MutationObserver(mark).observe(document.querySelector('.cpx-tabs'), { attributes: true, subtree: true, attributeFilter: ['class'] });
		/* a levélírás és a megnyitott levél teljes képernyős – ott (a Salesmate-hez hasonlóan) nincs alsó sáv */
		setInterval(() => { document.body.classList.toggle('mn-hide', !!document.querySelector('.ml-compose, .ml-inbox.reading, #cpx-modal:not([hidden])')); mark(); }, 400);
		mark();
		return true;
	}

	const css = document.createElement('style');
	css.textContent = '.mn-bar,.mn-sheet{display:none}' +
		'@media (max-width:760px){' +
		'body.mn-on{padding-bottom:calc(64px + env(safe-area-inset-bottom))}' +
		'body.mn-on #cpx-burger,body.mn-on .sr-mob{display:none!important}' +
		'.mn-bar{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:45;background:#fff;border-top:1px solid #E6E3DE;padding:0 4px env(safe-area-inset-bottom);box-shadow:0 -6px 18px -14px rgba(20,28,51,.35)}' +
		'body.mn-hide .mn-bar{display:none}body.mn-hide.mn-on{padding-bottom:0}' +
		'.mn-bar button{flex:1;position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;height:60px;border:0;background:transparent;color:#1A1F2B;font:500 10.5px Inter,sans-serif;cursor:pointer;-webkit-tap-highlight-color:transparent}' +
		'.mn-bar button .cpx-ico{width:25px;height:25px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}' +
		'.mn-bar button.on{color:#2F6FDB}.mn-bar button.on::before{content:"";position:absolute;top:-1px;left:18%;right:18%;height:3px;border-radius:0 0 3px 3px;background:#2F6FDB}' +
		'.mn-dot{position:absolute;top:10px;left:calc(50% + 8px);width:8px;height:8px;border-radius:50%;background:#E8553E}' +
		'.mn-sheet{display:block;position:fixed;inset:0;z-index:44}' +
		'.mn-bg{position:absolute;inset:0;background:rgba(20,24,33,.45)}' +
		'.mn-card{position:absolute;left:10px;right:10px;bottom:calc(70px + env(safe-area-inset-bottom));background:#fff;border-radius:18px;box-shadow:0 20px 40px -16px rgba(0,0,0,.45);max-height:calc(100vh - 140px);overflow:auto;color:#141C33}' +
		'.mn-me{display:flex;align-items:center;gap:14px;padding:18px 18px 12px}.mn-me b{display:block;font:700 19px Inter,sans-serif}.mn-me small{color:#6B6F78;font-size:13px}' +
		'.mn-av{width:48px;height:48px;border-radius:50%;background:#1F3F99;color:#fff;display:flex;align-items:center;justify-content:center;font:700 17px Inter,sans-serif;flex:none}' +
		'.mn-acts{display:flex;gap:10px;padding:4px 18px 16px;border-bottom:1px solid #EEEAE4}' +
		'.mn-acts>*{flex:1;position:relative;display:flex;align-items:center;justify-content:center;height:50px;border:1px solid #DDD9D2;border-radius:10px;background:#fff;color:#4A5163;cursor:pointer}' +
		'.mn-acts .cpx-ico{width:24px;height:24px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}' +
		'.mn-acts i{position:absolute;top:10px;right:calc(50% - 14px);width:8px;height:8px;border-radius:50%;background:#E8553E}' +
		'.mn-list{padding:6px 0 8px}.mn-list button{display:flex;align-items:center;gap:16px;width:100%;border:0;background:transparent;padding:13px 22px;font:500 16.5px Inter,sans-serif;color:#141C33;text-align:left;cursor:pointer}' +
		'.mn-list button.on{color:#2F6FDB}.mn-list button:active{background:#F6F4F0}' +
		'.mn-list .cpx-ico{width:23px;height:23px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;color:#6B6F78}.mn-list button.on .cpx-ico{color:#2F6FDB}' +
		'body.mn-on .ml-fab{bottom:calc(84px + env(safe-area-inset-bottom))}' +
		'}';
	document.head.appendChild(css);
	let tries = 0;
	const t = setInterval(() => { if (mount() || ++tries > 60) clearInterval(t); }, 200);
	window.CPMNav = { open: openSheet, close: closeSheet };
})();
