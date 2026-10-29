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
	const SECS = [['me', 'Saját beállításaim', 0], ['users', 'Felhasználók', 1], ['vis', 'Láthatóság', 1], ['notif', 'Értesítések', 1], ['mail', 'Levelezés', 1], ['ai', 'AI', 1], ['panel', 'Salesmate és hátterek', 1], ['wp', 'A WordPressben maradt', 1]];
	const secret = (name, has, ph) => '<input type="password" data-k="' + name + '" autocomplete="new-password" placeholder="' + esc(has ? 'beállítva – csak cseréhez írd be az újat' : ph) + '">' + (has ? '<label class="st-chk"><input type="checkbox" data-k="' + name + '_clear"> törlés</label>' : '');
	const sw = (k, on, label) => '<label class="st-sw"><input type="checkbox" data-k="' + k + '"' + (on ? ' checked' : '') + '><span></span>' + label + '</label>';
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
		S.main.innerHTML = '<div class="st"><nav class="st-nav">' + (d.admin ? '' : '') + secs.map((s, i) => (i === 1 ? '<div class="st-navh">Adminisztráció</div>' : '') + '<button type="button" data-sec="' + s[0] + '" class="' + (S.sec === s[0] ? 'on' : '') + '">' + s[1] + '</button>').join('') + '</nav><section class="st-body" data-body></section></div>';
		S.main.querySelectorAll('[data-sec]').forEach(b => b.addEventListener('click', () => { S.sec = b.dataset.sec; try { localStorage.setItem('cpx_set_sec', S.sec); } catch (e) { /* privát mód */ } draw(); }));
		const box = S.main.querySelector('[data-body]');
		({ me: secMe, users: secUsers, vis: secVis, notif: secNotif, mail: secMail, ai: secAi, panel: secPanel, wp: secWp })[S.sec](box, d);
	}
	/* mentés: a válasz friss adataival újrarajzol; a meghívó-linket megmutatja */
	async function save(btn, action, params, after) {
		btn.disabled = true; const old = btn.textContent; btn.textContent = 'Mentés…';
		try { const r = await post(action, params); S.d = r.data; draw(); toast(r.msg || 'Mentve.'); if (r.link) showLink(r.msg, r.link); if (after) after(r); }
		catch (e) { btn.disabled = false; btn.textContent = old; toast(e.message, true); }
	}
	function showLink(msg, link) {
		const box = S.main.querySelector('[data-body]');
		box.insertAdjacentHTML('afterbegin', '<div class="st-link"><p>' + esc(msg) + '</p><input type="text" readonly value="' + esc(link) + '"><button type="button" class="cpx-btn ghost" data-cplink>Link másolása</button></div>');
		const i = box.querySelector('.st-link input'); i.addEventListener('click', () => i.select());
		box.querySelector('[data-cplink]').addEventListener('click', async () => { try { await navigator.clipboard.writeText(link); toast('Link kimásolva.'); } catch (e) { i.select(); } });
	}

	/* ------------------------------------------------------------ saját beállítások (aláírás-szerkesztővel) */
	function sigEditor(host, html) {
		host.innerHTML = '<div class="st-ed"><div class="st-tb"><button type="button" data-c="bold" title="Félkövér"><b>B</b></button><button type="button" data-c="italic" title="Dőlt"><i>I</i></button><button type="button" data-c="underline" title="Aláhúzott"><u>U</u></button>' +
			'<button type="button" data-c="link" title="Link">🔗</button><button type="button" data-c="img" title="Kép (webcímről, pl. logó)">🖼</button><button type="button" data-c="removeFormat" title="Formázás törlése">⨯</button><span></span><button type="button" data-c="html" title="HTML (pl. a Salesmate-aláírás bemásolásához)">&lt;/&gt;</button></div>' +
			'<div class="st-area" contenteditable="true"></div><textarea class="st-code" hidden spellcheck="false"></textarea></div>';
		const area = host.querySelector('.st-area'), code = host.querySelector('.st-code');
		area.innerHTML = html || '';
		host.querySelectorAll('[data-c]').forEach(b => { b.addEventListener('mousedown', e => e.preventDefault()); b.addEventListener('click', () => {
			const c = b.dataset.c;
			if (c === 'html') { const on = code.hidden; if (on) code.value = area.innerHTML; else area.innerHTML = code.value; code.hidden = !on; area.hidden = on; b.classList.toggle('on', on); return; }
			area.focus();
			if (c === 'link') { const u = window.prompt('Link címe (https://…):', 'https://'); if (u && /^(https?:|mailto:|tel:)/i.test(u)) document.execCommand('createLink', false, u); return; }
			if (c === 'img') { const u = window.prompt('A kép webcíme (https://…), pl. a logó:', 'https://'); if (u && /^https:\/\//i.test(u)) document.execCommand('insertImage', false, u); return; }
			document.execCommand(c, false, null);
		}); });
		return { get: () => (code.hidden ? area.innerHTML : code.value) };
	}
	function secMe(box, d) {
		const m = d.me;
		if (!m.uid) { box.innerHTML = '<h2>Saját beállításaim</h2><p class="st-note">Most közös jelszóval vagy belépve. A saját aláíráshoz, értesítésekhez és a naplóhoz lépj be a saját fiókoddal (e-mail-cím + jelszó).</p>'; return; }
		box.innerHTML = '<h2>Saját beállításaim</h2><div class="st-card"><div class="st-grid"><label>Név<input type="text" data-k="n" value="' + esc(m.n) + '"></label><label>E-mail (belépés)<input type="text" value="' + esc(m.e) + '" disabled></label><label>Szerepkör<input type="text" value="' + esc(m.r === 'admin' ? 'Admin' : 'Iroda') + '" disabled></label></div></div>' +
			'<div class="st-card"><h3>E-mail-aláírás</h3><p class="st-note">A panelből küldött leveleid végére ez kerül („Saját aláírás hozzáadása”). Ha üresen hagyod, a közös aláírás megy. A Salesmate-aláírásod HTML-ként is bemásolható (&lt;/&gt; gomb).</p><div data-sig></div><div class="st-prev"><small>Így néz ki a levél alján:</small><div data-sigprev></div></div></div>' +
			'<div class="st-card"><h3>Saját Pumble-értesítés</h3><p class="st-note">Ha megadod, a megemlítéseket és a rád osztott feladatokat ide, közvetlenül neked küldi a panel (Pumble: Incoming Webhook a saját csatornádra).</p>' + secret('hook', m.hook, 'https://api.pumble.com/…') + '</div>' +
			'<div class="st-bar"><button type="button" class="cpx-btn" data-save>Mentés</button><button type="button" class="cpx-btn ghost" data-pw>Jelszó-módosító link küldése magamnak</button></div>';
		const ed = sigEditor(box.querySelector('[data-sig]'), m.sig);
		const prev = () => { box.querySelector('[data-sigprev]').innerHTML = ed.get() || '<span class="st-muted">(üres – a közös aláírás megy)</span>'; };
		prev(); box.querySelector('[data-sig]').addEventListener('input', prev); box.querySelector('[data-sig]').addEventListener('click', () => setTimeout(prev, 50));
		box.querySelector('[data-save]').addEventListener('click', e => save(e.currentTarget, 'cp_set_me', { op: 'save', n: val(box, 'n'), sig: ed.get(), hook: val(box, 'hook'), hook_clear: val(box, 'hook_clear') }));
		box.querySelector('[data-pw]').addEventListener('click', e => save(e.currentTarget, 'cp_set_me', { op: 'pwlink' }));
	}

	/* ------------------------------------------------------------ felhasználók */
	function secUsers(box, d) {
		const roleSel = (cur, k) => '<select data-k="' + k + '">' + Object.keys(d.roles).map(r => '<option value="' + r + '"' + (r === cur ? ' selected' : '') + '>' + esc(String(d.roles[r]).split(' –')[0]) + '</option>').join('') + '</select>';
		const smSel = (cur, k) => '<select data-k="' + k + '"><option value="0">–</option>' + d.sm_users.filter(s => s.a || s.id === cur).map(s => '<option value="' + s.id + '"' + (s.id === cur ? ' selected' : '') + '>' + esc(s.n) + '</option>').join('') + '</select>';
		box.innerHTML = '<h2>Felhasználók</h2><p class="st-note">Mindenki saját e-mail-címmel és jelszóval lép be. Az <b>admin</b> mindent lát (Statisztikák, Tárca, Napló, Beállítások), az <b>iroda</b> a napi munkát. A „Salesmate-felhasználó” az „Az enyém” szűrőhöz és a tevékenységek felelőséhez kell.</p>' +
			'<div class="st-tw"><table class="st-t"><thead><tr><th>Név</th><th>E-mail</th><th>Szerepkör</th><th>Salesmate-felhasználó</th><th>Állapot</th><th>Utolsó belépés</th><th></th></tr></thead><tbody>' +
			d.users.map(u => '<tr data-u="' + u.id + '"><td><input type="text" data-k="n" value="' + esc(u.n) + '"></td><td>' + esc(u.e) + '</td><td>' + roleSel(u.r, 'r') + '</td><td>' + smSel(u.sm, 'sm') + '</td><td><span class="st-st ' + (u.st === 'aktív' ? 'ok' : u.st === 'letiltva' ? 'bad' : '') + '">' + u.st + '</span></td><td>' + esc(u.last || '–') + '</td>' +
				'<td class="st-act"><button type="button" class="cpx-btn ghost" data-op="save">Mentés</button><button type="button" class="cpx-btn ghost" data-op="invite">' + (u.st === 'meghívva' ? 'Meghívó újra' : 'Jelszó-link') + '</button>' + (u.a ? '<button type="button" class="cpx-btn ghost bad" data-op="off">Letiltás</button>' : '<button type="button" class="cpx-btn ghost" data-op="on">Engedélyezés</button><button type="button" class="cpx-btn ghost bad" data-op="del">Törlés</button>') + '</td></tr>').join('') +
			(d.users.length ? '' : '<tr><td colspan="7" class="st-muted">Még nincs felhasználó.</td></tr>') + '</tbody></table></div>' +
			'<div class="st-card"><h3>Új felhasználó</h3><div class="st-grid"><label>Név<input type="text" data-k="nn"></label><label>E-mail<input type="email" data-k="ne"></label><label>Szerepkör' + roleSel('iroda', 'nr') + '</label><label>Salesmate-felhasználó' + smSel(0, 'nsm') + '</label></div><label class="st-chk"><input type="checkbox" data-k="nsend" checked> Meghívó levél küldése (a jelszó beállításához)</label><div class="st-bar"><button type="button" class="cpx-btn" data-add>Felhasználó létrehozása</button></div></div>' +
			'<div class="st-card"><h3>Közös jelszavak (átmenet)</h3><p class="st-note">A régi közös admin- és irodai jelszó most <b>' + (d.shared_on ? 'működik' : 'ki van kapcsolva') + '</b>. Ha már mindenkinek van saját fiókja, kapcsold ki – így a Naplóban mindig látszik, ki mit csinált.</p><div class="st-bar"><button type="button" class="cpx-btn ' + (d.shared_on ? '' : 'ghost') + '" data-shared="' + (d.shared_on ? 1 : 0) + '">' + (d.shared_on ? 'Közös jelszavak kikapcsolása' : 'Közös jelszavak visszakapcsolása') + '</button></div></div>';
		box.querySelectorAll('tr[data-u]').forEach(tr => tr.querySelectorAll('[data-op]').forEach(b => b.addEventListener('click', () => {
			const op = b.dataset.op, n = tr.querySelector('[data-k="n"]').value;
			if (op === 'off' && !window.confirm('Letiltod ' + n + ' fiókját? Azonnal kilépteti.')) return;
			if (op === 'del' && !window.confirm('Végleg törlöd ' + n + ' fiókját?')) return;
			save(b, 'cp_set_admin', { sec: 'user', op: op, uid: tr.dataset.u, n: n, r: tr.querySelector('[data-k="r"]').value, sm: tr.querySelector('[data-k="sm"]').value });
		})));
		box.querySelector('[data-add]').addEventListener('click', e => save(e.currentTarget, 'cp_set_admin', { sec: 'user', op: 'add', n: val(box, 'nn'), e: val(box, 'ne'), r: val(box, 'nr'), sm: val(box, 'nsm'), send: val(box, 'nsend') }));
		box.querySelector('[data-shared]').addEventListener('click', e => { const off = e.currentTarget.dataset.shared === '1'; if (off && !window.confirm('Kikapcsolod a közös jelszavakat? Ezután csak személyes fiókkal lehet belépni.')) return; save(e.currentTarget, 'cp_set_admin', { sec: 'shared', off: off ? 1 : '' }); });
	}
	/* ------------------------------------------------------------ láthatóság */
	function secVis(box, d) {
		const us = d.users.filter(u => u.a), tabs = Object.keys(d.tabs);
		box.innerHTML = '<h2>Láthatóság</h2><p class="st-note">Kinek milyen fülek és eszközök látszanak. Alapból mindenki mindent lát, kivéve a Statisztikákat, a Tárcát és az Automatizmusokat (ezeket csak az adminok). A Napló és a Beállítások adminisztrációja mindig csak adminnak érhető el.</p>' +
			(us.length ? '<div class="st-tw"><table class="st-t st-vis"><thead><tr><th></th>' + us.map(u => '<th>' + esc(u.n) + '</th>').join('') + '</tr></thead><tbody>' + tabs.map(k => '<tr><td>' + esc(d.tabs[k]) + '</td>' + us.map(u => '<td><input type="checkbox" data-v="' + u.id + '|' + k + '"' + (d.vis[u.id] && d.vis[u.id][k] ? ' checked' : '') + ' aria-label="' + esc(u.n + ' – ' + d.tabs[k]) + '"></td>').join('') + '</tr>').join('') + '</tbody></table></div><div class="st-bar"><button type="button" class="cpx-btn" data-save>Mentés</button></div>' : '<p class="st-muted">Még nincs aktív személyes fiók.</p>');
		const b = box.querySelector('[data-save]');
		if (b) b.addEventListener('click', () => { const v = {}; box.querySelectorAll('[data-v]').forEach(c => { const [u, k] = c.dataset.v.split('|'); (v[u] = v[u] || {})[k] = c.checked ? 1 : 0; }); save(b, 'cp_set_admin', { sec: 'vis', vis: JSON.stringify(v) }); });
	}
	/* ------------------------------------------------------------ értesítések */
	function secNotif(box, d) {
		box.innerHTML = '<h2>Értesítések (Pumble)</h2><p class="st-note">Megemlítéskor (@Név) és kiosztáskor a csengőn túl Pumble-üzenet is megy, a linkkel, ami egyenesen oda visz. Pumble: Workspace settings → Incoming Webhooks. A címeket a panel titkosítva tárolja, és nem mutatja vissza.</p>' +
			'<div class="st-card"><label class="st-lbl">Közös értesítési csatorna</label>' + secret('common', d.notif.common, 'https://api.pumble.com/workspaces/…/incomingWebhooks/postMessage/…') + '<label class="st-chk"><input type="checkbox" data-k="test"> teszt üzenet a közös csatornára</label></div>' +
			'<div class="st-card"><h3>Kollégánként (nem kötelező)</h3>' + d.users.filter(u => u.a).map(u => '<div class="st-row"><span>' + esc(u.n) + '</span>' + secret('u' + u.id, d.notif.users[u.id], 'saját csatorna webhookja') + '</div>').join('') + '</div><div class="st-bar"><button type="button" class="cpx-btn" data-save>Mentés</button></div>';
		box.querySelector('[data-save]').addEventListener('click', e => { const us = {}; d.users.forEach(u => { const v = val(box, 'u' + u.id), c = val(box, 'u' + u.id + '_clear'); if (v || c) us[u.id] = { v: v, clear: c ? 1 : 0 }; }); save(e.currentTarget, 'cp_set_admin', { sec: 'notif', common: val(box, 'common'), common_clear: val(box, 'common_clear'), users: JSON.stringify(us), test: val(box, 'test') }); });
	}
	/* ------------------------------------------------------------ levelezés */
	function secMail(box, d) {
		const m = d.mail, s = m.send || {};
		box.innerHTML = '<h2>Levelezés</h2>' +
			'<div class="st-card"><h3>Postafiók</h3><p class="st-note">' + esc(m.imap.user) + ' · ' + esc(m.imap.host) + ' · jelszó: ' + (m.imap.pw ? 'beállítva' : '<b>nincs megadva</b>') + ' (a jelszót és a régi levelek importját a WordPressben, a Crazy levelezés oldalon állítod).</p>' +
			sw('sync_on', m.sync_on, 'Folyamatos beérkezés (5 percenként, csak olvasva)') + '<p class="st-muted">' + (m.sync_last ? 'Utolsó ellenőrzés: ' + esc(m.sync_last) + ' · eddig átvéve: ' + m.sync_n + (m.sync_err ? ' · hiba: ' + esc(m.sync_err) : '') : 'Még nem futott.') + '</p></div>' +
			(m.send ? '<div class="st-card"><h3>Küldés a panelből (próbaüzem)</h3>' + sw('send_on', s.on, 'Küldés bekapcsolva a kijelölt kollégáknak') +
				'<label class="st-lbl">Kik küldhetnek</label><div class="st-users">' + d.users.filter(u => u.a).map(u => '<label class="st-chk"><input type="checkbox" data-su="' + u.id + '"' + (s.users.includes(u.id) ? ' checked' : '') + '> ' + esc(u.n) + (u.sig ? ' <small>(saját aláírás ✓)</small>' : '') + '</label>').join('') + '</div>' +
				'<div class="st-grid"><label>Feladó neve<input type="text" data-k="from_name" value="' + esc(s.from_name) + '"></label><label>Kimenő szerver (üresen: ' + esc(m.imap.host) + ')<input type="text" data-k="host" value="' + esc(s.host) + '"></label><label>Port<input type="number" data-k="port" value="' + s.port + '"></label><label>Titkosítás<select data-k="secure"><option value="ssl"' + (s.secure === 'ssl' ? ' selected' : '') + '>SSL (465)</option><option value="tls"' + (s.secure === 'tls' ? ' selected' : '') + '>STARTTLS (587)</option></select></label></div>' +
				sw('append', s.append, 'Másolat a postafiók Elküldött mappájába' + (s.sent_folder ? ' (' + esc(s.sent_folder) + ')' : '')) +
				'<label class="st-lbl">Közös aláírás (ha a kollégának nincs saját)</label><textarea data-k="sign" rows="4">' + esc(s.sign) + '</textarea>' +
				'<div class="st-bar"><button type="button" class="cpx-btn ghost" data-smtp>SMTP-kapcsolat ellenőrzése (nem küld levelet)</button></div></div>' : '') +
			'<div class="st-bar"><button type="button" class="cpx-btn" data-save>Mentés</button></div>';
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
		if (!a) { box.innerHTML = '<h2>AI</h2><p class="st-muted">Az AI-modul nincs betöltve.</p>'; return; }
		box.innerHTML = '<h2>AI válaszvarázsló</h2><p class="st-note">A levelekre az AI válaszpiszkozatot ír a korábbi levelek és a deal adatai alapján. A nevek, e-mail-címek és telefonszámok álnevesítve mennek ki. A kulcsot a console.anthropic.com oldalon hozod létre; a panel titkosítva tárolja és nem mutatja vissza.</p>' +
			'<div class="st-card">' + sw('on', a.on, 'AI válasz bekapcsolva') + '<label class="st-lbl">API-kulcs ' + (a.key ? '<span class="st-st ok">beállítva</span>' : '<span class="st-st bad">nincs</span>') + '</label>' + secret('key', a.key, 'sk-ant-…') +
			'<div class="st-grid"><label>Modell<select data-k="model">' + Object.keys(a.models).map(k => '<option value="' + esc(k) + '"' + (k === a.model ? ' selected' : '') + '>' + esc(a.models[k]) + '</option>').join('') + '</select></label><label>Alaposság<select data-k="effort">' + [['low', 'gyors'], ['medium', 'kiegyensúlyozott'], ['high', 'alapos']].map(x => '<option value="' + x[0] + '"' + (a.effort === x[0] ? ' selected' : '') + '>' + x[1] + '</option>').join('') + '</select></label></div>' +
			'<label class="st-lbl">Stílus, szabályok (nem kötelező)</label><textarea data-k="style" rows="4" placeholder="pl. tegeződünk, rövid bekezdések, a végén mindig kérdezzünk rá a dátumra">' + esc(a.style) + '</textarea></div><div class="st-bar"><button type="button" class="cpx-btn" data-save>Mentés</button></div>';
		box.querySelector('[data-save]').addEventListener('click', e => save(e.currentTarget, 'cp_set_admin', { sec: 'ai', on: val(box, 'on'), key: val(box, 'key'), key_clear: val(box, 'key_clear'), model: val(box, 'model'), effort: val(box, 'effort'), style: val(box, 'style') }));
	}
	/* ------------------------------------------------------------ Salesmate-be írás, hátterek */
	function secPanel(box, d) {
		const p = d.panel;
		box.innerHTML = '<h2>Salesmate és hátterek</h2>' +
			'<div class="st-card"><h3>Írás a Salesmate-be</h3>' + sw('stage_write', p.stage_write, 'A panel módosításai a Salesmate-be is beíródnak') + '<p class="st-note">Kikapcsolva a panel semmit nem ír a Salesmate-be: nem lehet deal-t szerkeszteni, szakaszt váltani, jegyzetet és tevékenységet felvenni. Párhuzamos működéshez maradjon bekapcsolva.</p></div>' +
			'<div class="st-card"><h3>Hátterek</h3><p class="st-note">A háttérlista a crazyphoto.hu „Választható hátterek” oldaláról jön. Ide azokat írd, amik ott nincsenek, vagy amiből több darab van – soronként: <code>Név</code> vagy <code>Név|darab</code>.</p><textarea data-k="backgrounds" rows="6">' + esc(p.backgrounds) + '</textarea>' +
			'<h3>Kétoldalas hátterek (párok)</h3><p class="st-note">Soronként egy fizikai háttér két oldala: <code>Arany - Levelek 2</code>. Az egyik foglalása a párját is foglalja aznapra.</p>' + (p.unknown.length ? '<p class="st-warn">A háttérlistában nem szerepel: <b>' + p.unknown.map(esc).join(', ') + '</b></p>' : '') + '<textarea data-k="bg_pairs" rows="8">' + esc(p.bg_pairs) + '</textarea></div><div class="st-bar"><button type="button" class="cpx-btn" data-save>Mentés</button></div>';
		box.querySelector('[data-save]').addEventListener('click', e => { if (!val(box, 'stage_write') && p.stage_write && !window.confirm('Kikapcsolod a Salesmate-be írást? Ezután a panelből nem lehet módosítani.')) return; save(e.currentTarget, 'cp_set_admin', { sec: 'panel', stage_write: val(box, 'stage_write'), backgrounds: val(box, 'backgrounds'), bg_pairs: val(box, 'bg_pairs') }); });
	}
	function secWp(box, d) {
		box.innerHTML = '<h2>A WordPressben maradt beállítások</h2><p class="st-note">Ezek ritkán változnak, vagy titkos kulcsot / tömeges műveletet tartalmaznak, ezért egyelőre a WordPress-adminban vannak (WordPress-belépés kell).</p><ul class="st-wp">' + d.wp.map(w => '<li><a href="' + esc(w.u) + '" target="_blank" rel="noopener">' + esc(w.n) + ' ↗</a></li>').join('') + '</ul>';
	}
	function status(s) { s.textContent = S.err || ''; s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function refresh() { if (S.main && S.main.isConnected) await view(S.main); }
	window.CPSettings = { view, status, refresh };

	const css = document.createElement('style');
	css.textContent = '.st{display:grid;grid-template-columns:220px minmax(0,1fr);gap:18px;max-width:1200px;align-items:start}.st-nav{display:flex;flex-direction:column;gap:2px;position:sticky;top:12px}.st-nav button{border:0;background:none;text-align:left;padding:8px 12px;border-radius:9px;font:600 13.5px Inter,sans-serif;color:#141C33;cursor:pointer}.st-nav button:hover{background:#EFECE7}.st-nav button.on{background:#fff;box-shadow:0 1px 2px rgba(20,28,51,.12);color:#C2621A}' +
		'.st-navh{margin:14px 12px 4px;font:700 11px Inter,sans-serif;text-transform:uppercase;letter-spacing:.05em;color:#8A8E96}.st-body h2{margin:0 0 6px;font:800 20px "Plus Jakarta Sans",sans-serif}.st-body h3{margin:0 0 8px;font:800 15px "Plus Jakarta Sans",sans-serif}' +
		'.st-card{background:#fff;border:1px solid #E6E3DE;border-radius:12px;padding:14px 16px;margin:12px 0}.st-note{font-size:13px;color:#4A4F5A;margin:0 0 10px;max-width:820px}.st-muted{color:#8A8E96;font-size:12.5px}.st-warn{background:#FFF7E0;color:#7F5F01;border-radius:8px;padding:6px 10px;font-size:13px}' +
		'.st-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px 14px;margin:6px 0 10px}.st-grid label,.st-lbl{display:flex;flex-direction:column;gap:3px;font:600 12px Inter,sans-serif;color:#6B6F78;margin-top:6px}' +
		'.st input[type=text],.st input[type=email],.st input[type=number],.st input[type=password],.st select,.st textarea{border:1px solid #E6E3DE;border-radius:8px;padding:7px 9px;font:13.5px Inter,sans-serif;background:#fff;color:#141C33;box-sizing:border-box;max-width:100%}.st textarea{width:100%;resize:vertical}.st input[type=password]{width:min(560px,100%)}.st input:disabled{background:#F6F4F0;color:#6B6F78}' +
		'.st-chk{display:inline-flex;align-items:center;gap:6px;font-size:13px;margin:6px 12px 0 0}.st-sw{display:flex;align-items:center;gap:10px;font:600 13.5px Inter,sans-serif;margin:6px 0;cursor:pointer}.st-sw input{display:none}.st-sw span{width:36px;height:20px;border-radius:999px;background:#D7D3CC;position:relative;transition:.15s;flex:none}.st-sw span::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;transition:.15s}.st-sw input:checked+span{background:#2E9E62}.st-sw input:checked+span::after{left:18px}' +
		'.st-bar{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.st-tw{overflow:auto;background:#fff;border:1px solid #E6E3DE;border-radius:12px}.st-t{width:100%;border-collapse:collapse;font-size:13px}.st-t th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#8A8E96;padding:8px 10px;background:#FAF8F5;border-bottom:1px solid #E6E3DE;white-space:nowrap}.st-t td{padding:6px 10px;border-bottom:1px solid #F0ECE6;vertical-align:middle}' +
		'.st-t td input[type=text]{width:130px}.st-t td select{max-width:150px}.st-act{white-space:nowrap}.st-act .cpx-btn{padding:4px 9px;font-size:12px;margin-right:4px}.st-st{display:inline-block;border-radius:999px;padding:1px 8px;font:600 11.5px Inter,sans-serif;background:#EEF0F4}.st-st.ok{background:#DFF3E8;color:#216E4E}.st-st.bad{background:#FDECEA;color:#AE2E24}' +
		'.st-vis td:not(:first-child),.st-vis th:not(:first-child){text-align:center}.st-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:6px 0}.st-row>span{min-width:160px;font-weight:600;font-size:13px}.st-users{display:flex;flex-wrap:wrap;margin-bottom:6px}' +
		'.st-ed{border:1px solid #E6E3DE;border-radius:10px;overflow:hidden}.st-tb{display:flex;gap:2px;padding:4px;background:#FAF8F5;border-bottom:1px solid #E6E3DE}.st-tb span{flex:1}.st-tb button{border:0;background:none;border-radius:6px;padding:4px 8px;cursor:pointer;font:13px Inter,sans-serif}.st-tb button:hover,.st-tb button.on{background:#EFECE7}' +
		'.st-area{min-height:110px;padding:10px 12px;font:14px/1.5 Arial,sans-serif;outline:0}.st-area img{max-width:240px}.st-code{width:100%;min-height:140px;border:0;font:12px/1.45 ui-monospace,Menlo,monospace;padding:10px}.st-prev{margin-top:10px;border-top:1px dashed #E6E3DE;padding-top:8px}.st-prev>div{font:14px/1.5 Arial,sans-serif;margin-top:4px}.st-prev img{max-width:240px}' +
		'.st-link{background:#EEF3FD;border-radius:10px;padding:10px 12px;margin-bottom:10px}.st-link p{margin:0 0 6px;font-size:13px}.st-link input{width:100%;margin-bottom:6px}.st-wp li{margin:6px 0}.st-wp a{color:#2F5BD8;font-weight:600;text-decoration:none}' +
		'@media (max-width:820px){.st{grid-template-columns:1fr}.st-nav{flex-direction:row;flex-wrap:wrap;position:static}.st-navh{width:100%;margin:6px 4px 0}}';
	document.head.appendChild(css);
})();
