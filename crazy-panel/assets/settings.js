/* Crazy panel – Beállítások (v1.55): saját beállítások mindenkinek, adminisztráció az adminoknak */
(function () {
	'use strict';
	const C = window.CPX || {};
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const S = { main: null, d: null, sec: 'me', err: '' };
	try { const v = localStorage.getItem('cpx_set_sec'); if (v) S.sec = v; } catch (e) { /* privát mód */ }

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
		clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), bad ? 6000 : 3500);
	}
	const SECS = [['me', 'Saját beállításaim', 0], ['users', 'Felhasználók', 1], ['vis', 'Láthatóság', 1], ['notif', 'Értesítések', 1], ['mail', 'Levelezés', 1], ['ai', 'AI', 1], ['panel', 'Salesmate és hátterek', 1], ['cut', 'Átállás', 1], ['wp', 'A WordPressben maradt', 1]];
	const secret = (name, has, ph) => '<input type="password" data-k="' + name + '" autocomplete="new-password" placeholder="' + esc(has ? 'beállítva – csak cseréhez írd be az újat' : ph) + '">' + (has ? '<label class="cs-chk"><input type="checkbox" data-k="' + name + '_clear"> törlés</label>' : '');
	const sw = (k, on, label) => '<label class="cs-sw"><input type="checkbox" data-k="' + k + '"' + (on ? ' checked' : '') + '><span></span>' + label + '</label>';
	const val = (box, k) => { const el = box.querySelector('[data-k="' + k + '"]'); if (!el) return ''; return el.type === 'checkbox' ? (el.checked ? 1 : '') : el.value; };

	async function view(main) {
		S.main = main;
		main.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
		try { S.d = await post('cp_set_get', {}); S.err = ''; } catch (e) { S.err = e.message; main.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		draw();
	}
	function draw() {
		const d = S.d, secs = SECS.filter(s => !s[2] || d.admin);
		if (!secs.some(s => s[0] === S.sec)) S.sec = 'me';
		S.main.innerHTML = '<div class="cs"><nav class="cs-nav">' + (d.admin ? '' : '') + secs.map((s, i) => (i === 1 ? '<div class="cs-navh">Adminisztráció</div>' : '') + '<button type="button" data-sec="' + s[0] + '" class="' + (S.sec === s[0] ? 'on' : '') + '">' + s[1] + '</button>').join('') + '</nav><section class="cs-body" data-body></section></div>';
		S.main.querySelectorAll('[data-sec]').forEach(b => b.addEventListener('click', () => { S.sec = b.dataset.sec; try { localStorage.setItem('cpx_set_sec', S.sec); } catch (e) { /* privát mód */ } draw(); }));
		const box = S.main.querySelector('[data-body]');
		({ me: secMe, users: secUsers, vis: secVis, notif: secNotif, mail: secMail, ai: secAi, panel: secPanel, cut: secCut, wp: secWp })[S.sec](box, d);
	}
	/* mentés: a válasz friss adataival újrarajzol; a meghívó-linket megmutatja */
	async function save(btn, action, params, after) {
		btn.disabled = true; const old = btn.textContent; btn.textContent = 'Mentés…';
		try { const r = await post(action, params); S.d = r.data; draw(); toast(r.msg || 'Mentve.'); if (r.link) showLink(r.msg, r.link); if (after) after(r); }
		catch (e) { btn.disabled = false; btn.textContent = old; toast(e.message, true); }
	}
	function showLink(msg, link) {
		const box = S.main.querySelector('[data-body]');
		box.insertAdjacentHTML('afterbegin', '<div class="cs-link"><p>' + esc(msg) + '</p><input type="text" readonly value="' + esc(link) + '"><button type="button" class="cpx-btn ghost" data-cplink>Link másolása</button></div>');
		const i = box.querySelector('.cs-link input'); i.addEventListener('click', () => i.select());
		box.querySelector('[data-cplink]').addEventListener('click', async () => { try { await navigator.clipboard.writeText(link); toast('Link kimásolva.'); } catch (e) { i.select(); } });
	}

	/* ------------------------------------------------------------ saját beállítások (aláírás-szerkesztővel) */
	function sigEditor(host, html) {
		const FONTS = [['', 'Betűtípus'], ['Arial, Helvetica, sans-serif', 'Arial'], ['Helvetica, Arial, sans-serif', 'Helvetica'], ['Verdana, Geneva, sans-serif', 'Verdana'], ['Tahoma, Geneva, sans-serif', 'Tahoma'], ['"Trebuchet MS", sans-serif', 'Trebuchet MS'], ['Georgia, serif', 'Georgia'], ['"Times New Roman", Times, serif', 'Times New Roman'], ['"Courier New", monospace', 'Courier New']];
		const SIZES = [['', 'Méret'], ['10px', '10'], ['11px', '11'], ['12px', '12'], ['13px', '13'], ['14px', '14'], ['16px', '16'], ['18px', '18'], ['20px', '20'], ['24px', '24'], ['28px', '28']];
		const B = (c, t, l) => '<button type="button" data-c="' + c + '" title="' + l + '" aria-label="' + l + '">' + t + '</button>';
		host.innerHTML = '<div class="cs-ed"><div class="cs-tb">' +
			'<select data-font aria-label="Betűtípus">' + FONTS.map(f => '<option value="' + esc(f[0]) + '">' + f[1] + '</option>').join('') + '</select>' +
			'<select data-size aria-label="Betűméret">' + SIZES.map(f => '<option value="' + f[0] + '">' + f[1] + '</option>').join('') + '</select><i></i>' +
			B('bold', '<b>B</b>', 'Félkövér') + B('italic', '<i>I</i>', 'Dőlt') + B('underline', '<u>U</u>', 'Aláhúzott') + B('strikeThrough', '<s>S</s>', 'Áthúzott') +
			'<label class="cs-col" title="Betűszín"><span>A</span><input type="color" data-color value="#1a1f2b"></label><i></i>' +
			B('justifyLeft', '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 10h10M4 14h16M4 18h10"/></svg>', 'Balra') + B('justifyCenter', '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M7 10h10M4 14h16M7 18h10"/></svg>', 'Középre') + B('justifyRight', '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M10 10h10M4 14h16M10 18h10"/></svg>', 'Jobbra') + B('insertUnorderedList', '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></svg>', 'Felsorolás') + B('insertOrderedList', '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 6h10M10 12h10M10 18h10M4 5v3M3.5 12h2l-2 3h2"/></svg>', 'Számozás') + '<i></i>' +
			B('link', '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>', 'Link') + B('unlink', '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7M4 4l16 16"/></svg>', 'Link eltávolítása') + B('img', '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 5h16v14H4zM4 15l4-4 5 5M14 13l2-2 4 4M15 9h.01"/></svg>', 'Kép webcímről') + B('upload', '<span class="cs-up">Logó feltöltése</span>', 'Kép feltöltése a gépről (pl. logó)') + B('insertHorizontalRule', '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 12h16"/></svg>', 'Vízszintes vonal') + B('removeFormat', '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6h12M10 6l-3 12M14 18l6-6M20 18l-6-6"/></svg>', 'Formázás törlése') +
			'<span></span>' + B('html', '&lt;/&gt;', 'HTML-forrás (pl. a Salesmate-aláírás bemásolásához)') + '</div>' +
			'<div class="cs-area" contenteditable="true"></div><textarea class="cs-code" hidden spellcheck="false"></textarea><input type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden data-file></div>';
		const area = host.querySelector('.cs-area'), code = host.querySelector('.cs-code'), file = host.querySelector('[data-file]');
		area.innerHTML = html || '';
		let saved = null;
		const keep = () => { const s = window.getSelection(); if (s.rangeCount && area.contains(s.anchorNode)) saved = s.getRangeAt(0).cloneRange(); };
		const restore = () => { area.focus(); if (saved) { const s = window.getSelection(); s.removeAllRanges(); s.addRange(saved); } };
		area.addEventListener('keyup', keep); area.addEventListener('mouseup', keep); area.addEventListener('blur', keep);
		const fire = () => host.dispatchEvent(new Event('input', { bubbles: true }));
		const cmd = (c, v) => { restore(); try { document.execCommand('styleWithCSS', false, true); } catch (e) { /* régi böngésző */ } document.execCommand(c, false, v == null ? null : v); keep(); fire(); };
		/* betűméret: a böngésző csak 1–7 méretet ismer – ideiglenes 7-esre állítjuk, majd pontos pixelre cseréljük */
		const size = px => {
			cmd('fontSize', '7');
			area.querySelectorAll('font[size="7"]').forEach(f => { const s = document.createElement('span'); s.style.fontSize = px; s.innerHTML = f.innerHTML; f.replaceWith(s); });
			area.querySelectorAll('span').forEach(s => { if (/xxx-large|-webkit-xxx-large/.test(s.style.fontSize)) s.style.fontSize = px; });
			fire();
		};
		host.querySelectorAll('.cs-tb button, .cs-tb select, .cs-tb label').forEach(b => b.addEventListener('mousedown', e => { if (b.tagName === 'BUTTON') e.preventDefault(); keep(); }));
		host.querySelector('[data-font]').addEventListener('change', e => { if (e.target.value) cmd('fontName', e.target.value); e.target.value = ''; });
		host.querySelector('[data-size]').addEventListener('change', e => { if (e.target.value) size(e.target.value); e.target.value = ''; });
		host.querySelector('[data-color]').addEventListener('input', e => cmd('foreColor', e.target.value));
		host.querySelectorAll('[data-c]').forEach(b => b.addEventListener('click', async () => {
			const c = b.dataset.c;
			if (c === 'html') { const on = code.hidden; if (on) code.value = area.innerHTML; else { area.innerHTML = code.value; fire(); } code.hidden = !on; area.hidden = on; b.classList.toggle('on', on); return; }
			if (c === 'link') { const u = window.prompt('Link címe (https://…, mailto:…, tel:…):', 'https://'); if (u && /^(https?:|mailto:|tel:)/i.test(u)) cmd('createLink', u); return; }
			if (c === 'img') { const u = window.prompt('A kép webcíme (https://…):', 'https://'); if (u && /^https:\/\//i.test(u)) cmd('insertImage', u); return; }
			if (c === 'upload') { file.click(); return; }
			cmd(c);
		}));
		file.addEventListener('change', async () => {
			const f = file.files && file.files[0]; file.value = '';
			if (!f) return;
			if (f.size > 2 * 1048576) { toast('A kép legfeljebb 2 MB lehet.', true); return; }
			try {
				const fd = new FormData(); fd.append('action', 'cp_set_img'); fd.append('t', C.t); fd.append('f', f);
				const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }); const j = await r.json();
				if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || 'A feltöltés nem sikerült.');
				cmd('insertImage', j.data.url); toast('Kép beszúrva.');
			} catch (e) { toast(e.message, true); }
		});
		area.addEventListener('input', fire);
		/* a linkek színét és aláhúzását beírja a HTML-be (ahogy most látszik), hogy minden levelezőprogramban így jelenjen meg */
		const norm = () => {
			if (!code.hidden) area.innerHTML = code.value;
			area.querySelectorAll('a').forEach(a => {
				const par = a.parentElement ? getComputedStyle(a.parentElement) : null;
				if (!a.style.color && par) a.style.color = par.color;
				if (!a.style.textDecoration) a.style.textDecoration = 'none';
			});
			return area.innerHTML;
		};
		return { get: () => (code.hidden ? area.innerHTML : code.value), norm: norm };
	}
	/* az aláírás szerkesztése előnézettel (saját vagy – adminnak – egy kollégáé) */
	function sigBlock(box, html) {
		box.innerHTML = '<div data-sig></div><div class="cs-prev"><small>Így néz ki a levél alján (ahogy a levelezőprogram mutatja):</small><iframe data-sigprev sandbox="allow-same-origin" title="Az aláírás előnézete"></iframe></div>';
		const ed = sigEditor(box.querySelector('[data-sig]'), html);
		const fr = box.querySelector('[data-sigprev]');
		/* elszigetelt előnézet: a panel stílusa nem hat rá, csak a levél saját formázása */
		const prev = () => {
			const h = ed.norm();
			fr.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:8px 2px;font:14px/1.5 Arial,Helvetica,sans-serif;color:#000}</style></head><body>' + (h || '<span style="color:#8A8E96">(üres – a közös aláírás megy)</span>') + '<p style="margin-top:18px;color:#8A8E96;font-size:12px">— a levél többi része itt lenne —</p></body></html>';
		};
		fr.addEventListener('load', () => { try { fr.style.height = Math.min(600, fr.contentDocument.documentElement.scrollHeight + 4) + 'px'; } catch (e) { /* zárt keret */ } });
		prev(); box.querySelector('[data-sig]').addEventListener('input', prev);
		return ed;
	}
	function secMe(box, d) {
		const m = d.me;
		if (!m.uid) { box.innerHTML = '<h2>Saját beállításaim</h2><p class="cs-note">Most közös jelszóval vagy belépve. A saját aláíráshoz, értesítésekhez és a naplóhoz lépj be a saját fiókoddal (e-mail-cím + jelszó).</p>'; return; }
		box.innerHTML = '<h2>Saját beállításaim</h2><div class="cs-card"><div class="cs-grid"><label>Név<input type="text" data-k="n" value="' + esc(m.n) + '"></label><label>E-mail (belépés)<input type="text" value="' + esc(m.e) + '" disabled></label><label>Szerepkör<input type="text" value="' + esc(m.r === 'admin' ? 'Admin' : 'Iroda') + '" disabled></label></div></div>' +
			'<div class="cs-card"><h3>E-mail-aláírás</h3><p class="cs-note">A panelből küldött leveleid végére ez kerül („Saját aláírás hozzáadása”). Ha üresen hagyod, a közös aláírás megy. A Salesmate-aláírásod HTML-ként is bemásolható (&lt;/&gt; gomb).</p><div data-sigb></div></div>' +
			'<div class="cs-card"><h3>Saját Pumble-értesítés</h3><p class="cs-note">Ha megadod, a megemlítéseket és a rád osztott feladatokat ide, közvetlenül neked küldi a panel (Pumble: Incoming Webhook a saját csatornádra).</p>' + secret('hook', m.hook, 'https://api.pumble.com/…') + '</div>' +
			'<div class="cs-bar"><button type="button" class="cpx-btn" data-save>Mentés</button><button type="button" class="cpx-btn ghost" data-pw>Jelszó-módosító link küldése magamnak</button></div>';
		const ed = sigBlock(box.querySelector('[data-sigb]'), m.sig);
		box.querySelector('[data-save]').addEventListener('click', e => save(e.currentTarget, 'cp_set_me', { op: 'save', n: val(box, 'n'), sig: ed.norm(), hook: val(box, 'hook'), hook_clear: val(box, 'hook_clear') }));
		box.querySelector('[data-pw]').addEventListener('click', e => save(e.currentTarget, 'cp_set_me', { op: 'pwlink' }));
	}

	/* ------------------------------------------------------------ felhasználók */
	function secUsers(box, d) {
		const roleSel = (cur, k) => '<select data-k="' + k + '">' + Object.keys(d.roles).map(r => '<option value="' + r + '"' + (r === cur ? ' selected' : '') + '>' + esc(String(d.roles[r]).split(' –')[0]) + '</option>').join('') + '</select>';
		const smSel = (cur, k) => '<select data-k="' + k + '"><option value="0">–</option>' + d.sm_users.filter(s => s.a || s.id === cur).map(s => '<option value="' + s.id + '"' + (s.id === cur ? ' selected' : '') + '>' + esc(s.n) + '</option>').join('') + '</select>';
		box.innerHTML = '<h2>Felhasználók</h2><p class="cs-note">Mindenki saját e-mail-címmel és jelszóval lép be. Az <b>admin</b> mindent lát (Statisztikák, Tárca, Napló, Beállítások), az <b>iroda</b> a napi munkát. A „Salesmate-felhasználó” az „Az enyém” szűrőhöz és a tevékenységek felelőséhez kell.</p>' +
			'<div class="cs-tw"><table class="cs-t cs-ut"><thead><tr><th>Név / e-mail</th><th>Szerepkör</th><th>Salesmate-felhasználó</th><th>Állapot</th><th></th></tr></thead><tbody>' +
			d.users.map(u => '<tr data-u="' + u.id + '"><td><input type="text" data-k="n" value="' + esc(u.n) + '"><small>' + esc(u.e) + '</small></td><td>' + roleSel(u.r, 'r') + '</td><td>' + smSel(u.sm, 'sm') + '</td><td><span class="cs-st ' + (u.st === 'aktív' ? 'ok' : u.st === 'letiltva' ? 'bad' : '') + '">' + u.st + '</span><small>' + (u.last ? 'belépett: ' + esc(u.last) : 'még nem lépett be') + '</small></td>' +
				'<td class="cs-act"><button type="button" class="cpx-btn ghost" data-op="save">Mentés</button><button type="button" class="cpx-btn ghost" data-sigu="' + u.id + '" title="A kolléga e-mail-aláírása">✍ Aláírás' + (u.sig ? ' ✓' : '') + '</button><button type="button" class="cpx-btn ghost" data-op="invite">' + (u.st === 'meghívva' ? 'Meghívó újra' : 'Jelszó-link') + '</button>' + (u.a ? '<button type="button" class="cpx-btn ghost bad" data-op="off">Letiltás</button>' : '<button type="button" class="cpx-btn ghost" data-op="on">Engedélyezés</button><button type="button" class="cpx-btn ghost bad" data-op="del">Törlés</button>') + '</td></tr>').join('') +
			(d.users.length ? '' : '<tr><td colspan="5" class="cs-muted">Még nincs felhasználó.</td></tr>') + '</tbody></table></div><div data-sigcard></div>' +
			'<div class="cs-card"><h3>Új felhasználó</h3><div class="cs-grid"><label>Név<input type="text" data-k="nn"></label><label>E-mail<input type="email" data-k="ne"></label><label>Szerepkör' + roleSel('iroda', 'nr') + '</label><label>Salesmate-felhasználó' + smSel(0, 'nsm') + '</label></div><label class="cs-chk"><input type="checkbox" data-k="nsend" checked> Meghívó levél küldése (a jelszó beállításához)</label><div class="cs-bar"><button type="button" class="cpx-btn" data-add>Felhasználó létrehozása</button></div></div>' +
			'<div class="cs-card"><h3>Közös jelszavak (átmenet)</h3><p class="cs-note">A régi közös admin- és irodai jelszó most <b>' + (d.shared_on ? 'működik' : 'ki van kapcsolva') + '</b>. Ha már mindenkinek van saját fiókja, kapcsold ki – így a Naplóban mindig látszik, ki mit csinált.</p><div class="cs-bar"><button type="button" class="cpx-btn ' + (d.shared_on ? '' : 'ghost') + '" data-shared="' + (d.shared_on ? 1 : 0) + '">' + (d.shared_on ? 'Közös jelszavak kikapcsolása' : 'Közös jelszavak visszakapcsolása') + '</button></div></div>';
		box.querySelectorAll('tr[data-u]').forEach(tr => tr.querySelectorAll('[data-op]').forEach(b => b.addEventListener('click', () => {
			const op = b.dataset.op, n = tr.querySelector('[data-k="n"]').value;
			if (op === 'off' && !window.confirm('Letiltod ' + n + ' fiókját? Azonnal kilépteti.')) return;
			if (op === 'del' && !window.confirm('Végleg törlöd ' + n + ' fiókját?')) return;
			save(b, 'cp_set_admin', { sec: 'user', op: op, uid: tr.dataset.u, n: n, r: tr.querySelector('[data-k="r"]').value, sm: tr.querySelector('[data-k="sm"]').value });
		})));
		box.querySelectorAll('[data-sigu]').forEach(b => b.addEventListener('click', () => {
			const u = d.users.find(x => String(x.id) === b.dataset.sigu), host = box.querySelector('[data-sigcard]');
			host.innerHTML = '<div class="cs-card"><h3>E-mail-aláírás: ' + esc(u.n) + '</h3><p class="cs-note">Ez kerül ' + esc(u.n) + ' panelből küldött leveleinek a végére. A Salesmate-ben használt aláírás a &lt;/&gt; gombbal HTML-ként bemásolható.</p><div data-sigb></div><div class="cs-bar"><button type="button" class="cpx-btn" data-sigsave>Aláírás mentése</button><button type="button" class="cpx-btn ghost" data-sigx>Bezárás</button></div></div>';
			const ed = sigBlock(host.querySelector('[data-sigb]'), u.sig_html || '');
			host.querySelector('[data-sigx]').addEventListener('click', () => { host.innerHTML = ''; });
			host.querySelector('[data-sigsave]').addEventListener('click', e => save(e.currentTarget, 'cp_set_admin', { sec: 'sig', uid: u.id, sig: ed.norm() }));
			host.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}));
		box.querySelector('[data-add]').addEventListener('click', e => save(e.currentTarget, 'cp_set_admin', { sec: 'user', op: 'add', n: val(box, 'nn'), e: val(box, 'ne'), r: val(box, 'nr'), sm: val(box, 'nsm'), send: val(box, 'nsend') }));
		box.querySelector('[data-shared]').addEventListener('click', e => { const off = e.currentTarget.dataset.shared === '1'; if (off && !window.confirm('Kikapcsolod a közös jelszavakat? Ezután csak személyes fiókkal lehet belépni.')) return; save(e.currentTarget, 'cp_set_admin', { sec: 'shared', off: off ? 1 : '' }); });
	}
	/* ------------------------------------------------------------ láthatóság */
	function secVis(box, d) {
		const us = d.users.filter(u => u.a), tabs = Object.keys(d.tabs);
		box.innerHTML = '<h2>Láthatóság</h2><p class="cs-note">Kinek milyen fülek és eszközök látszanak. Alapból mindenki mindent lát, kivéve a Statisztikákat, a Tárcát és az Automatizmusokat (ezeket csak az adminok). A Napló és a Beállítások adminisztrációja mindig csak adminnak érhető el.</p>' +
			(us.length ? '<div class="cs-tw"><table class="cs-t cs-vis"><thead><tr><th></th>' + us.map(u => '<th>' + esc(u.n) + '</th>').join('') + '</tr></thead><tbody>' + tabs.map(k => '<tr><td>' + esc(d.tabs[k]) + '</td>' + us.map(u => '<td><input type="checkbox" data-v="' + u.id + '|' + k + '"' + (d.vis[u.id] && d.vis[u.id][k] ? ' checked' : '') + ' aria-label="' + esc(u.n + ' – ' + d.tabs[k]) + '"></td>').join('') + '</tr>').join('') + '</tbody></table></div><div class="cs-bar"><button type="button" class="cpx-btn" data-save>Mentés</button></div>' : '<p class="cs-muted">Még nincs aktív személyes fiók.</p>');
		const b = box.querySelector('[data-save]');
		if (b) b.addEventListener('click', () => { const v = {}; box.querySelectorAll('[data-v]').forEach(c => { const [u, k] = c.dataset.v.split('|'); (v[u] = v[u] || {})[k] = c.checked ? 1 : 0; }); save(b, 'cp_set_admin', { sec: 'vis', vis: JSON.stringify(v) }); });
	}
	/* ------------------------------------------------------------ értesítések */
	function secNotif(box, d) {
		box.innerHTML = '<h2>Értesítések (Pumble)</h2><p class="cs-note">Megemlítéskor (@Név) és kiosztáskor a csengőn túl Pumble-üzenet is megy, a linkkel, ami egyenesen oda visz. Pumble: Workspace settings → Incoming Webhooks. A címeket a panel titkosítva tárolja, és nem mutatja vissza.</p>' +
			'<div class="cs-card"><label class="cs-lbl">Közös értesítési csatorna</label>' + secret('common', d.notif.common, 'https://api.pumble.com/workspaces/…/incomingWebhooks/postMessage/…') + '<label class="cs-chk"><input type="checkbox" data-k="test"> teszt üzenet a közös csatornára</label></div>' +
			'<div class="cs-card"><h3>Kollégánként (nem kötelező)</h3>' + d.users.filter(u => u.a).map(u => '<div class="cs-row"><span>' + esc(u.n) + '</span>' + secret('u' + u.id, d.notif.users[u.id], 'saját csatorna webhookja') + '</div>').join('') + '</div><div class="cs-bar"><button type="button" class="cpx-btn" data-save>Mentés</button></div>';
		box.querySelector('[data-save]').addEventListener('click', e => { const us = {}; d.users.forEach(u => { const v = val(box, 'u' + u.id), c = val(box, 'u' + u.id + '_clear'); if (v || c) us[u.id] = { v: v, clear: c ? 1 : 0 }; }); save(e.currentTarget, 'cp_set_admin', { sec: 'notif', common: val(box, 'common'), common_clear: val(box, 'common_clear'), users: JSON.stringify(us), test: val(box, 'test') }); });
	}
	/* ------------------------------------------------------------ levelezés */
	function secMail(box, d) {
		const m = d.mail, s = m.send || {};
		box.innerHTML = '<h2>Levelezés</h2>' +
			'<div class="cs-card"><h3>Postafiók</h3><p class="cs-note">' + esc(m.imap.user) + ' · ' + esc(m.imap.host) + ' · jelszó: ' + (m.imap.pw ? 'beállítva' : '<b>nincs megadva</b>') + ' (a jelszót és a régi levelek importját a WordPressben, a Crazy levelezés oldalon állítod).</p>' +
			sw('sync_on', m.sync_on, 'Folyamatos beérkezés (5 percenként, csak olvasva)') + '<p class="cs-muted">' + (m.sync_last ? 'Utolsó ellenőrzés: ' + esc(m.sync_last) + ' · eddig átvéve: ' + m.sync_n + (m.sync_err ? ' · hiba: ' + esc(m.sync_err) : '') : 'Még nem futott.') + '</p></div>' +
			(m.send ? '<div class="cs-card"><h3>Küldés a panelből (próbaüzem)</h3>' + sw('send_on', s.on, 'Küldés bekapcsolva a kijelölt kollégáknak') +
				'<label class="cs-lbl">Kik küldhetnek</label><div class="cs-users">' + d.users.filter(u => u.a).map(u => '<label class="cs-chk"><input type="checkbox" data-su="' + u.id + '"' + (s.users.includes(u.id) ? ' checked' : '') + '> ' + esc(u.n) + (u.sig ? ' <small>(saját aláírás ✓)</small>' : '') + '</label>').join('') + '</div>' +
				'<div class="cs-grid"><label>Feladó neve<input type="text" data-k="from_name" value="' + esc(s.from_name) + '"></label><label>Kimenő szerver<input type="text" data-k="host" value="' + esc(s.host) + '" placeholder="' + esc(m.imap.host) + '" readonly title="Csak a WordPress adminban módosítható (biztonság)"></label><label>Port<input type="number" data-k="port" value="' + s.port + '" readonly title="Csak a WordPress adminban módosítható (biztonság)"></label><label>Titkosítás<select data-k="secure" disabled title="Csak a WordPress adminban módosítható (biztonság)"><option value="ssl"' + (s.secure === 'ssl' ? ' selected' : '') + '>SSL (465)</option><option value="tls"' + (s.secure === 'tls' ? ' selected' : '') + '>STARTTLS (587)</option></select></label></div>' +
				sw('append', s.append, 'Másolat a postafiók Elküldött mappájába' + (s.sent_folder ? ' (' + esc(s.sent_folder) + ')' : '')) +
				'<label class="cs-lbl">Közös aláírás (ha a kollégának nincs saját)</label><textarea data-k="sign" rows="4">' + esc(s.sign) + '</textarea>' +
				'<div class="cs-bar"><button type="button" class="cpx-btn ghost" data-smtp>SMTP-kapcsolat ellenőrzése (nem küld levelet)</button></div></div>' : '') +
			'<div class="cs-bar"><button type="button" class="cpx-btn" data-save>Mentés</button></div>';
		box.querySelector('[data-save]').addEventListener('click', e => {
			const su = Array.from(box.querySelectorAll('[data-su]')).filter(c => c.checked).map(c => Number(c.dataset.su));
			if (val(box, 'send_on') && !su.length) { toast('Jelölj ki legalább egy kollégát, aki küldhet.', true); return; }
			save(e.currentTarget, 'cp_set_admin', { sec: 'mail', sync_on: val(box, 'sync_on'), send_on: val(box, 'send_on'), send_users: JSON.stringify(su), from_name: val(box, 'from_name'), host: val(box, 'host'), port: val(box, 'port'), secure: val(box, 'secure'), append: val(box, 'append'), sign: val(box, 'sign') });
		});
		const t = box.querySelector('[data-smtp]');
		if (t) t.addEventListener('click', () => save(t, 'cp_set_admin', { sec: 'smtp' }));
	}
	/* ------------------------------------------------------------ AI */
	function secAi(box, d) {
		const a = d.ai;
		if (!a) { box.innerHTML = '<h2>AI</h2><p class="cs-muted">Az AI-modul nincs betöltve.</p>'; return; }
		box.innerHTML = '<h2>AI válaszvarázsló</h2><p class="cs-note">A levelekre az AI válaszpiszkozatot ír a korábbi levelek és a deal adatai alapján. A nevek, e-mail-címek és telefonszámok álnevesítve mennek ki. A kulcsot a console.anthropic.com oldalon hozod létre; a panel titkosítva tárolja és nem mutatja vissza.</p>' +
			'<div class="cs-card">' + sw('on', a.on, 'AI válasz bekapcsolva') + '<label class="cs-lbl">API-kulcs ' + (a.key ? '<span class="cs-st ok">beállítva</span>' : '<span class="cs-st bad">nincs</span>') + '</label>' + secret('key', a.key, 'sk-ant-…') +
			'<div class="cs-grid"><label>Modell<select data-k="model">' + Object.keys(a.models).map(k => '<option value="' + esc(k) + '"' + (k === a.model ? ' selected' : '') + '>' + esc(a.models[k]) + '</option>').join('') + '</select></label><label>Alaposság<select data-k="effort">' + [['low', 'gyors'], ['medium', 'kiegyensúlyozott'], ['high', 'alapos']].map(x => '<option value="' + x[0] + '"' + (a.effort === x[0] ? ' selected' : '') + '>' + x[1] + '</option>').join('') + '</select></label></div>' +
			'<label class="cs-lbl">Stílus, szabályok (nem kötelező)</label><textarea data-k="style" rows="4" placeholder="pl. tegeződünk, rövid bekezdések, a végén mindig kérdezzünk rá a dátumra">' + esc(a.style) + '</textarea></div><div class="cs-bar"><button type="button" class="cpx-btn" data-save>Mentés</button></div>';
		box.querySelector('[data-save]').addEventListener('click', e => save(e.currentTarget, 'cp_set_admin', { sec: 'ai', on: val(box, 'on'), key: val(box, 'key'), key_clear: val(box, 'key_clear'), model: val(box, 'model'), effort: val(box, 'effort'), style: val(box, 'style') }));
	}
	/* ------------------------------------------------------------ Salesmate-be írás, hátterek */
	function secPanel(box, d) {
		const p = d.panel;
		box.innerHTML = '<h2>Salesmate és hátterek</h2>' +
			'<div class="cs-card"><h3>Írás a Salesmate-be</h3>' + sw('stage_write', p.stage_write, 'A panel módosításai a Salesmate-be is beíródnak') + '<p class="cs-note">Kikapcsolva a panel semmit nem ír a Salesmate-be: nem lehet deal-t szerkeszteni, szakaszt váltani, jegyzetet és tevékenységet felvenni. Párhuzamos működéshez maradjon bekapcsolva.</p></div>' +
			'<div class="cs-card"><h3>Hátterek</h3><p class="cs-note">A háttérlista a crazyphoto.hu „Választható hátterek” oldaláról jön. Ide azokat írd, amik ott nincsenek, vagy amiből több darab van – soronként: <code>Név</code> vagy <code>Név|darab</code>.</p><textarea data-k="backgrounds" rows="6">' + esc(p.backgrounds) + '</textarea>' +
			'<h3>Kétoldalas hátterek (párok)</h3><p class="cs-note">Soronként egy fizikai háttér két oldala: <code>Arany - Levelek 2</code>. Az egyik foglalása a párját is foglalja aznapra.</p>' + (p.unknown.length ? '<p class="cs-warn">A háttérlistában nem szerepel: <b>' + p.unknown.map(esc).join(', ') + '</b></p>' : '') + '<textarea data-k="bg_pairs" rows="8">' + esc(p.bg_pairs) + '</textarea></div><div class="cs-bar"><button type="button" class="cpx-btn" data-save>Mentés</button></div>';
		box.querySelector('[data-save]').addEventListener('click', e => { if (!val(box, 'stage_write') && p.stage_write && !window.confirm('Kikapcsolod a Salesmate-be írást? Ezután a panelből nem lehet módosítani.')) return; save(e.currentTarget, 'cp_set_admin', { sec: 'panel', stage_write: val(box, 'stage_write'), backgrounds: val(box, 'backgrounds'), bg_pairs: val(box, 'bg_pairs') }); });
	}
	/* ------------------------------------------------------------ v1.70: átállás a Salesmate-ről */
	async function secCut(box) {
		box.innerHTML = '<h2>Átállás a Salesmate-ről</h2><div class="cpx-empty">Betöltés…</div>';
		let x;
		try { x = await post('cp_cut_get', { check: 1 }); } catch (e) { box.innerHTML = '<h2>Átállás a Salesmate-ről</h2><div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		const draw = () => {
			const M = [['sm', 'Salesmate'], ['shadow', 'Árnyék'], ['live', 'Panel + tükör'], ['only', 'Csak panel']];
			const ST = { match: ['egyezik', 'ok'], diff: ['eltér', 'bad'], missing: ['hiányzik', 'bad'], pending: ['vár', ''] };
			const FN = {}; x.feats.forEach(f => { FN[f.k] = f.n; });
			box.innerHTML = '<h2>Átállás a Salesmate-ről</h2><p class="cs-note">Funkciónként állítható, ki csinálja: <b>Salesmate</b> (ma) · <b>Árnyék</b> (a Salesmate csinálja, a panel kiszámolja, mit tenne, és összeveti – semmit nem ír és nem küld) · <b>Panel + tükör</b> (a panel csinálja, és visszaírja a Salesmate-be) · <b>Csak panel</b> (a lemondás után). Egy funkció akkor állhat át, ha 10 egymást követő munkanapon nincs eltérés.</p>' +
				'<div class="cs-card"><table class="cs-cut"><thead><tr><th>Funkció</th><th>Ki csinálja</th><th>Elmúlt 3 hét</th><th>Hibátlan munkanapok</th></tr></thead><tbody>' +
				x.feats.map(f => '<tr><td><b>' + esc(f.n) + '</b><small>' + esc(f.d) + '</small></td><td><select data-cut="' + f.k + '">' + M.map(m => '<option value="' + m[0] + '"' + (f.mode === m[0] ? ' selected' : '') + ((m[0] === 'live' || m[0] === 'only') && !f.live_ok ? ' disabled' : '') + '>' + m[1] + ((m[0] === 'live' || m[0] === 'only') && !f.live_ok ? ' (fejlesztés alatt)' : '') + '</option>').join('') + '</select></td>' +
					'<td>' + (f.tot.match || f.tot.diff || f.tot.missing || f.tot.pending ? '<span class="cs-ok">' + (f.tot.match || 0) + ' egyezik</span> · <span class="' + (f.tot.diff ? 'cs-bad' : '') + '">' + (f.tot.diff || 0) + ' eltér</span> · <span class="' + (f.tot.missing ? 'cs-bad' : '') + '">' + (f.tot.missing || 0) + ' hiányzik</span>' + (f.tot.pending ? ' · ' + f.tot.pending + ' vár' : '') : '<span class="cs-note">még nincs eset</span>') + '</td>' +
					'<td><b class="' + (f.streak >= 10 ? 'cs-ok' : '') + '">' + Math.min(f.streak, 10) + ' / 10</b></td></tr>').join('') + '</tbody></table></div>' +
				'<div class="cs-card"><h3>Felelős-kiosztás az űrlapos dealeknél</h3><p class="cs-note">Körbeforgó kiosztás a kiválasztott kollégák között (a Salesmate-ben ma Andrea és Dorka kapja őket). Árnyékban ez is összevetésre kerül.</p><div class="cs-rr">' +
					Object.keys(x.users).map(id => '<label class="cs-chk"><input type="checkbox" data-rr="' + id + '"' + (x.rr.includes(Number(id)) ? ' checked' : '') + '> ' + esc(x.users[id]) + '</label>').join('') + '</div><div class="cs-bar"><button type="button" class="cpx-btn" data-rrsave>Mentés</button></div></div>' +
				'<div class="cs-card"><h3>A crazyphoto.hu űrlap figyelése</h3><p class="cs-note">A crazyphoto.hu főoldala nem ezen a weboldalon van, ezért az árnyék-üzemhez oda is be kell tenni ezt a kis kódot (a Salesmate-űrlap mellé, a főoldal aljára). Nem küld semmit a Salesmate-nek, csak a beküldést jelzi a panelnek.</p><textarea readonly rows="3" class="cs-code" data-snip></textarea><div class="cs-bar"><button type="button" class="cpx-btn ghost" data-snipcopy>Kód másolása</button></div></div>' +
				'<div class="cs-card"><h3>Legutóbbi esetek</h3>' + (x.recent.length ? '<ul class="cs-cutl">' + x.recent.map(r => '<li><span class="cs-st ' + (ST[r.st] || ['', ''])[1] + '">' + esc((ST[r.st] || [r.st])[0]) + '</span><div><b>' + esc(r.title || '–') + '</b><small>' + esc(FN[r.feat] || r.feat) + ' · ' + esc(r.at) + (r.deal ? ' · <a href="#deals:' + r.deal + '">#' + r.deal + '</a>' : '') + '</small>' + (r.diff ? '<pre>' + esc(r.diff) + '</pre>' : '') + '</div></li>').join('') + '</ul>' : '<p class="cs-note">Még nincs eset – az első webes ajánlatkéréssel indul.</p>') + '<div class="cs-bar"><button type="button" class="cpx-btn ghost" data-recheck>Egyeztetés most</button></div></div>';
			box.querySelector('[data-snip]').value = '<script>(function(){window.addEventListener(\'SM_FORM_SUBMITTED\',function(e){try{var d=e.detail||{},b=new FormData();b.append(\'action\',\'cp_lead_in\');b.append(\'form\',d.formId||\'\');b.append(\'fname\',d.formName||\'\');b.append(\'page\',location.href);b.append(\'data\',JSON.stringify(d.formData||d));navigator.sendBeacon(\'' + new URL(C.ajax, location.href).href + '\',b);}catch(x){}});})();<\/script>';
			box.querySelector('[data-snipcopy]').addEventListener('click', async () => { try { await navigator.clipboard.writeText(box.querySelector('[data-snip]').value); toast('A kód a vágólapon.'); } catch (e) { box.querySelector('[data-snip]').select(); } });
			box.querySelectorAll('[data-cut]').forEach(sel => sel.addEventListener('change', async () => {
				const f = x.feats.find(y => y.k === sel.dataset.cut);
				if ((sel.value === 'live' || sel.value === 'only') && !window.confirm('Átkapcsolod: ' + f.n + ' → ' + sel.options[sel.selectedIndex].text + '?\n\nEzután a panel csinálja; a Salesmate megfelelő automatizmusát kapcsold ki, különben duplán történik meg.')) { sel.value = f.mode; return; }
				try { x = await post('cp_cut_set', { feat: sel.dataset.cut, mode: sel.value }); toast('Mentve.'); draw(); } catch (e) { toast(e.message, true); sel.value = f.mode; }
			}));
			box.querySelector('[data-rrsave]').addEventListener('click', async () => { const ids = Array.from(box.querySelectorAll('[data-rr]:checked')).map(c => c.dataset.rr).join(','); try { x = await post('cp_cut_set', { rr: ids }); toast('Mentve.'); draw(); } catch (e) { toast(e.message, true); } });
			box.querySelector('[data-recheck]').addEventListener('click', async () => { try { x = await post('cp_cut_get', { check: 1 }); draw(); toast('Egyeztetve.'); } catch (e) { toast(e.message, true); } });
		};
		draw();
	}
	function secWp(box, d) {
		box.innerHTML = '<h2>A WordPressben maradt beállítások</h2><p class="cs-note">Ezek ritkán változnak, vagy titkos kulcsot / tömeges műveletet tartalmaznak, ezért egyelőre a WordPress-adminban vannak (WordPress-belépés kell).</p><ul class="cs-wp">' + d.wp.map(w => '<li><a href="' + esc(w.u) + '" target="_blank" rel="noopener">' + esc(w.n) + ' ↗</a></li>').join('') + '</ul>';
	}
	function status(s) { s.textContent = S.err || ''; s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function refresh() { if (S.main && S.main.isConnected) await view(S.main); }
	window.CPSettings = { view, status, refresh };

	const css = document.createElement('style');
	css.textContent = '.cs{display:grid;grid-template-columns:190px minmax(0,1fr);gap:18px;max-width:1200px;align-items:start}.cs-body{min-width:0}.cs-nav{display:flex;flex-direction:column;align-items:stretch;gap:2px;position:sticky;top:12px}.cs-nav button{display:block;width:100%;border:0;background:none;text-align:left;padding:8px 12px;border-radius:9px;font:600 13.5px Inter,sans-serif;color:#141C33;cursor:pointer}.cs-nav button:hover{background:#EFECE7}.cs-nav button.on{background:#fff;box-shadow:0 1px 2px rgba(20,28,51,.12);color:#C2621A}' +
		'.cs-navh{margin:14px 12px 4px;font:700 11px Inter,sans-serif;text-transform:uppercase;letter-spacing:.05em;color:#8A8E96}.cs-body h2{margin:0 0 6px;font:800 20px "Plus Jakarta Sans",sans-serif}.cs-body h3{margin:0 0 8px;font:800 15px "Plus Jakarta Sans",sans-serif}' +
		'.cs-cut{width:100%;border-collapse:collapse;font-size:13px}.cs-cut th{text-align:left;font:700 11.5px Inter,sans-serif;color:#8A8E96;text-transform:uppercase;letter-spacing:.04em;padding:6px 8px;border-bottom:1px solid #EEEAE4}.cs-cut td{padding:9px 8px;border-bottom:1px solid #F2EFEA;vertical-align:top}.cs-cut td small{display:block;color:#6B6F78;font-size:12px;margin-top:2px}.cs-ok{color:#2F7D4F;font-weight:600}.cs-bad{color:#C0392B;font-weight:600}.cs-rr{display:flex;flex-wrap:wrap}.cs-code{width:100%;font:12px ui-monospace,monospace}' +
		'.cs-cutl{list-style:none;margin:0;padding:0}.cs-cutl li{display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px solid #F2EFEA}.cs-cutl b{font-size:13.5px}.cs-cutl small{display:block;color:#6B6F78;font-size:12px}.cs-cutl pre{white-space:pre-wrap;font:12px Inter,sans-serif;color:#7A3B2E;background:#FFF4F1;border-radius:6px;padding:4px 8px;margin:4px 0 0}.cs-st{flex:none;font:700 11.5px Inter,sans-serif;border-radius:6px;padding:2px 8px;background:#F1EDE7;color:#6B6F78;min-width:64px;text-align:center}.cs-st.ok{background:#EEF6F0;color:#2F7D4F}.cs-st.bad{background:#FDECEA;color:#C0392B}' +
		'@media (max-width:760px){.cs-cut thead{display:none}.cs-cut,.cs-cut tbody,.cs-cut tr,.cs-cut td{display:block;width:100%}.cs-cut tr{border-bottom:1px solid #EEEAE4;padding:6px 0}.cs-cut td{border:0;padding:3px 0}}' +
		'.cs-card{background:#fff;border:1px solid #E6E3DE;border-radius:12px;padding:14px 16px;margin:12px 0}.cs-note{font-size:13px;color:#4A4F5A;margin:0 0 10px;max-width:820px}.cs-muted{color:#8A8E96;font-size:12.5px}.cs-warn{background:#FFF7E0;color:#7F5F01;border-radius:8px;padding:6px 10px;font-size:13px}' +
		'.cs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px 14px;margin:6px 0 10px}.cs-grid label,.cs-lbl{display:flex;flex-direction:column;gap:3px;font:600 12px Inter,sans-serif;color:#6B6F78;margin-top:6px;min-width:0}.cs-grid label input,.cs-grid label select{width:100%}' +
		'.cs input[type=text],.cs input[type=email],.cs input[type=number],.cs input[type=password],.cs select,.cs textarea{border:1px solid #E6E3DE;border-radius:8px;padding:7px 9px;font:13.5px Inter,sans-serif;background:#fff;color:#141C33;box-sizing:border-box;max-width:100%}.cs textarea{width:100%;resize:vertical}.cs input[type=password]{width:min(560px,100%)}.cs input:disabled{background:#F6F4F0;color:#6B6F78}' +
		'.cs-chk{display:inline-flex;align-items:center;gap:6px;font-size:13px;margin:6px 12px 0 0}.cs-sw{display:flex;align-items:center;gap:10px;font:600 13.5px Inter,sans-serif;margin:6px 0;cursor:pointer}.cs-sw input{display:none}.cs-sw span{width:36px;height:20px;border-radius:999px;background:#D7D3CC;position:relative;transition:.15s;flex:none}.cs-sw span::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;transition:.15s}.cs-sw input:checked+span{background:#2E9E62}.cs-sw input:checked+span::after{left:18px}' +
		'.cs-bar{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.cs-tw{overflow:auto;background:#fff;border:1px solid #E6E3DE;border-radius:12px}.cs-t{width:100%;border-collapse:collapse;font-size:13px}.cs-t th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#8A8E96;padding:8px 10px;background:#FAF8F5;border-bottom:1px solid #E6E3DE;white-space:nowrap}.cs-t td{padding:6px 10px;border-bottom:1px solid #F0ECE6;vertical-align:middle}' +
		'.cs-t td input[type=text]{width:125px}.cs-t td select{max-width:125px}.cs-act{white-space:normal}.cs-act .cpx-btn{padding:4px 9px;font-size:12px;margin:2px 4px 2px 0}.cs-ut td small{display:block;color:#8A8E96;font-size:11.5px;margin-top:3px}.cs-ut td:last-child{min-width:170px}.cs-ut td,.cs-ut th{padding:6px 8px}.cs-st{display:inline-block;border-radius:999px;padding:1px 8px;font:600 11.5px Inter,sans-serif;background:#EEF0F4}.cs-st.ok{background:#DFF3E8;color:#216E4E}.cs-st.bad{background:#FDECEA;color:#AE2E24}' +
		'.cs-vis td:not(:first-child),.cs-vis th:not(:first-child){text-align:center}.cs-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:6px 0}.cs-row>span{min-width:160px;font-weight:600;font-size:13px}.cs-users{display:flex;flex-wrap:wrap;margin-bottom:6px}' +
		'.cs-ed{border:1px solid #E6E3DE;border-radius:10px;overflow:hidden;background:#fff}.cs-tb{display:flex;flex-wrap:wrap;align-items:center;gap:2px;padding:5px;background:#FAF8F5;border-bottom:1px solid #E6E3DE}.cs-tb span{flex:1}.cs-tb i{width:1px;height:20px;background:#E1DDD6;margin:0 4px}.cs-tb button svg{display:block}.cs-up{font:600 12px Inter,sans-serif}.cs-tb button{border:0;background:none;border-radius:6px;padding:4px 8px;cursor:pointer;font:13px Inter,sans-serif;min-width:28px;color:#141C33}.cs-tb button:hover,.cs-tb button.on{background:#EFECE7}' +
		'.cs .cs-tb select{padding:3px 6px;font-size:12.5px;max-width:140px}.cs-col{position:relative;display:inline-flex;align-items:center;justify-content:center;width:30px;height:28px;border-radius:6px;cursor:pointer}.cs-col:hover{background:#EFECE7}.cs-col span{font:700 14px Inter,sans-serif;text-decoration:underline;text-decoration-thickness:3px}.cs-col input{position:absolute;inset:0;opacity:0;cursor:pointer}' +
		'.cs-area{min-height:110px;padding:10px 12px;font:14px/1.5 Arial,sans-serif;outline:0;color:#000}.cs-area a{color:inherit}.cs-area img{max-width:none}.cs-area *{box-sizing:content-box}.cs-code{width:100%;min-height:140px;border:0;font:12px/1.45 ui-monospace,Menlo,monospace;padding:10px}.cs-prev{margin-top:10px;border-top:1px dashed #E6E3DE;padding-top:8px}.cs-prev iframe{display:block;width:100%;border:0;min-height:80px;margin-top:4px;background:#fff}' +
		'.cs-link{background:#EEF3FD;border-radius:10px;padding:10px 12px;margin-bottom:10px}.cs-link p{margin:0 0 6px;font-size:13px}.cs-link input{width:100%;margin-bottom:6px}.cs-wp li{margin:6px 0}.cs-wp a{color:#2F5BD8;font-weight:600;text-decoration:none}' +
		'@media (max-width:1250px){.cs-ut thead{display:none}.cs-ut,.cs-ut tbody{display:block}.cs-ut tr{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px 10px;padding:10px 12px;border-bottom:1px solid #F0ECE6}.cs-ut td{border:0;padding:0!important;min-width:0!important}.cs-ut td:last-child{grid-column:1/-1}.cs-ut td input[type=text],.cs-ut td select{width:100%;max-width:none}}' +
		'@media (max-width:600px){.cs-ut tr{grid-template-columns:1fr}}' +
		'@media (max-width:820px){.cs{grid-template-columns:1fr}.cs-nav{flex-direction:row;flex-wrap:wrap;position:static}.cs-navh{width:100%;margin:6px 4px 0}}';
	document.head.appendChild(css);
})();
