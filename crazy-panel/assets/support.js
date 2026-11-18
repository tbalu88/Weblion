/* Crazy panel – Segítség (v2.9.312): AI-chat hibaelhárításhoz és használati kérdésekhez (fejlesztésre nem).
 * A szerver (support.php) csak olvas; a javasolt javítást a kolléga gombbal hagyja jóvá, és ekkor a panel meglévő,
 * jogosultság-ellenőrzött végpontja fut (cp_mail_bulk / cp_mail_thread_set / cp_mail_fetch_now). */
(function () {
	'use strict';
	const C = window.CPX || {};
	if (!C.sup || !C.sup.on) return;
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const S = { main: null, cid: '', rows: [], left: null, busy: false, err: '', owner: '', ro: false, run: {} };
	try { S.cid = localStorage.getItem('cpx_sup_cid') || ''; } catch (e) { /* privát mód */ }
	async function post(action, params) {
		const fd = new FormData(); fd.append('action', action); fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j = null; try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j && j.data && j.data.msg) || 'Hiba'));
		return j.data;
	}
	const saveCid = c => { S.cid = c || ''; try { if (S.cid) localStorage.setItem('cpx_sup_cid', S.cid); else localStorage.removeItem('cpx_sup_cid'); } catch (e) { /* privát mód */ } };
	const IC = {
		send: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l16-8-6 16-2.5-6.5z"/></svg>',
		plus: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
		fwd: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 5l7 7-7 7M21 12H9a6 6 0 0 0-6 6"/></svg>',
		fix: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L4 16.8V20h3.2l5.3-5.3a4 4 0 0 0 5.2-5.4l-2.4 2.4-2.3-.6-.6-2.3z"/></svg>',
		ok: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>'
	};
	const SUGG = ['Nem látszik egy levél a dealnél – miért?', 'Hogyan küldök sablonból levelet?', 'Egy ügyfél levele nem jött be a panelbe', 'Hol állítom a kezelőbeosztást?'];
	const CSS = '.sp{max-width:760px;margin:0 auto;display:flex;flex-direction:column;min-height:calc(100vh - 140px)}' +
		'.sp-h{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px}.sp-h h2{margin:0;font:700 22px/1.2 Inter,sans-serif;color:#141C33}.sp-h p{margin:4px 0 0;color:#5F5B55;font-size:14px;line-height:1.45;max-width:52ch}' +
		'.sp-ha{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}.sp-ha .cpx-btn{display:inline-flex;align-items:center;gap:6px;min-height:38px}.sp-ha svg,.sp-fx svg,.sp-send svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}' +
		'.sp-log{flex:1;display:flex;flex-direction:column;gap:14px;padding:4px 0 18px}' +
		'.sp-m{max-width:88%;font-size:14.5px;line-height:1.55;color:#1F1D1A;overflow-wrap:anywhere}.sp-m.u{align-self:flex-end;background:#141C33;color:#fff;padding:10px 14px;border-radius:14px 14px 4px 14px;white-space:pre-wrap}' +
		'.sp-m.a{align-self:flex-start;background:#fff;border:1px solid #E6E2DC;padding:12px 16px;border-radius:14px 14px 14px 4px;box-shadow:0 1px 2px rgba(20,28,51,.05)}.sp-m.a p{margin:0 0 8px}.sp-m.a p:last-child{margin:0}.sp-m.a ol,.sp-m.a ul{margin:4px 0 8px;padding-left:20px}.sp-m.a li{margin:2px 0}' +
		'.sp-m.n{align-self:center;font-size:12.5px;color:#5F5B55;background:#EFEBE5;padding:5px 12px;border-radius:999px}.sp-t{display:block;font-size:11.5px;color:#8A857D;margin-top:6px}.sp-m.u .sp-t{color:#B9BED0}' +
		'.sp-fx{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid #F0ECE6}.sp-fx button{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:0 14px;border-radius:10px;border:1px solid #141C33;background:#fff;color:#141C33;font:600 13.5px Inter,sans-serif;cursor:pointer}' +
		'.sp-fx button:hover:not(:disabled){background:#141C33;color:#fff}.sp-fx button:disabled{cursor:default;opacity:.75}.sp-fx .done{border-color:#2E7D4F;color:#1E5E3A;background:#EAF5EE}.sp-fx .bad{border-color:#B3261E;color:#8C1D18;background:#FBEBEB}' +
		'.sp-wait{align-self:flex-start;display:flex;gap:5px;padding:14px 16px;background:#fff;border:1px solid #E6E2DC;border-radius:14px}.sp-wait i{width:7px;height:7px;border-radius:50%;background:#9A958D;animation:spd 1.2s infinite ease-in-out}.sp-wait i:nth-child(2){animation-delay:.15s}.sp-wait i:nth-child(3){animation-delay:.3s}' +
		'@keyframes spd{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}@media (prefers-reduced-motion:reduce){.sp-wait i{animation:none;opacity:.6}}' +
		'.sp-e{padding:18px;background:#fff;border:1px solid #E6E2DC;border-radius:16px}.sp-e b{display:block;font-size:15px;color:#141C33;margin-bottom:4px}.sp-e span{font-size:13.5px;color:#5F5B55}.sp-sg{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}' +
		'.sp-sg button{border:1px solid #E6E2DC;background:#FBFAF8;border-radius:999px;padding:8px 14px;font:500 13.5px Inter,sans-serif;color:#1F1D1A;cursor:pointer;text-align:left}.sp-sg button:hover{border-color:#141C33}' +
		'.sp-c{position:sticky;bottom:0;background:linear-gradient(to top,var(--bg,#F5F2EC) 75%,rgba(245,242,236,0));padding:10px 0 6px}.sp-box{display:flex;gap:8px;align-items:flex-end;background:#fff;border:1px solid #D9D4CC;border-radius:16px;padding:8px 8px 8px 14px;box-shadow:0 2px 8px rgba(20,28,51,.06)}' +
		'.sp-box:focus-within{border-color:#141C33}.sp-box textarea{flex:1;border:0;outline:0;resize:none;font:15px/1.5 Inter,sans-serif;color:#1F1D1A;background:none;max-height:180px;min-height:24px;padding:6px 0}' +
		'.sp-send{width:40px;height:40px;flex-shrink:0;border:0;border-radius:12px;background:#141C33;color:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}.sp-send:disabled{background:#C9C4BC;cursor:default}' +
		'.sp-f{display:flex;justify-content:space-between;gap:10px;font-size:12px;color:#8A857D;margin-top:6px;padding:0 4px}.sp-err{color:#8C1D18;background:#FBEBEB;border-radius:10px;padding:8px 12px;font-size:13.5px;margin-bottom:8px}' +
		'@media (max-width:640px){.sp-h h2{font-size:20px}.sp-ha{margin-left:0;width:100%}.sp-ha .cpx-btn{flex:1;justify-content:center}.sp-m{max-width:94%}.sp-box textarea{font-size:16px}}';
	function css() { if (document.getElementById('sp-css')) return; const st = document.createElement('style'); st.id = 'sp-css'; st.textContent = CSS; document.head.appendChild(st); }
	/* egyszerű, biztonságos jelölés: **félkövér**, számozott és felsorolt lista, bekezdés */
	function md(t) {
		const lines = String(t || '').split('\n'); let h = '', list = '';
		const inl = s => esc(s).replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
		const close = () => { if (list) { h += '</' + list + '>'; list = ''; } };
		let para = [];
		const flush = () => { if (para.length) { h += '<p>' + para.map(inl).join('<br>') + '</p>'; para = []; } };
		lines.forEach(l => {
			const n = l.match(/^\s*\d+[.)]\s+(.*)$/), b = l.match(/^\s*[-•*]\s+(.*)$/);
			if (n || b) { flush(); const k = n ? 'ol' : 'ul'; if (list !== k) { close(); h += '<' + k + '>'; list = k; } h += '<li>' + inl((n || b)[1]) + '</li>'; return; }
			close();
			if (!l.trim()) { flush(); return; }
			para.push(l);
		});
		flush(); close();
		return h;
	}
	function fixBtn(f, k, last, done) {
		const st = done && done[k], ok = st === 'ok', bad = st && !ok, run = S.run[k];
		const lab = ok ? 'Elvégezve' : bad ? 'Nem sikerült' : run ? 'Folyamatban…' : f.label;
		return '<button type="button" data-fx="' + k + '" class="' + (ok ? 'done' : bad ? 'bad' : '') + '"' + (st || run || !last || S.ro ? ' disabled' : '') + ' title="' + esc(bad ? st : 'A panel elvégzi: ' + f.label) + '">' + (ok ? IC.ok : IC.fix) + esc(lab) + '</button>';
	}
	function draw() {
		const m = S.main; if (!m || !m.isConnected) return;
		const lastA = (() => { for (let i = S.rows.length - 1; i >= 0; i--) if (S.rows[i].role === 'assistant') return i; return -1; })();
		const log = S.rows.map((r, i) => {
			if (r.role === 'user') return '<div class="sp-m u">' + esc(r.text) + '<span class="sp-t">' + esc(r.at) + '</span></div>';
			if (r.role === 'note') return '<div class="sp-m n">' + esc(r.text) + '</div>';
			return '<div class="sp-m a">' + md(r.text) + (r.fixes && r.fixes.length ? '<div class="sp-fx">' + r.fixes.map((f, k) => fixBtn(f, k, i === lastA, r.done)).join('') + '</div>' : '') + '<span class="sp-t">' + esc(r.at) + '</span></div>';
		}).join('');
		const empty = !S.rows.length ? '<div class="sp-e"><b>Miben segíthetek?</b><span>Írd le, mit csináltál és mi történt (pl. melyik deal, melyik levél). Megnézem, és ha a panel tud rajta javítani, felajánlom egy gombbal. Fejlesztést, Salesmate-módosítást és számlázást nem intézek – azt Balázsnak továbbítom.</span>' +
			'<div class="sp-sg">' + SUGG.map(s => '<button type="button" data-sg>' + esc(s) + '</button>').join('') + '</div></div>' : '';
		m.innerHTML = '<div class="sp"><div class="sp-h"><div><h2>Segítség</h2><p>' + (S.ro ? esc(S.owner) + ' beszélgetése (csak olvasható).' : 'Kérdezz a panel használatáról, vagy írd le a hibát.') + '</p></div><div class="sp-ha">' +
			(S.ro ? '' : '<button type="button" class="cpx-btn ghost" data-esc' + (S.rows.length && !S.busy ? '' : ' disabled') + ' title="A beszélgetés linkjét Balázs megkapja értesítésben">' + IC.fwd + 'Küldés Balázsnak</button>') +
			'<button type="button" class="cpx-btn ghost" data-new' + (S.busy ? ' disabled' : '') + '>' + IC.plus + 'Új beszélgetés</button></div></div>' +
			'<div class="sp-log" aria-live="polite">' + empty + log + (S.busy ? '<div class="sp-wait" role="status" aria-label="Válasz készül"><i></i><i></i><i></i></div>' : '') + '</div>' +
			(S.ro ? '' : '<div class="sp-c">' + (S.err ? '<div class="sp-err" role="alert">' + esc(S.err) + '</div>' : '') +
			'<form class="sp-box" data-f><textarea rows="1" maxlength="2000" placeholder="Írd le a kérdést vagy a hibát…" aria-label="Üzenet" data-in></textarea><button type="submit" class="sp-send" aria-label="Küldés"' + (S.busy || S.left === 0 ? ' disabled' : '') + '>' + IC.send + '</button></form>' +
			'<div class="sp-f"><span>Enter: küldés · Shift+Enter: új sor</span><span>' + (S.left == null ? '' : 'Ma még ' + S.left + ' kérdés') + '</span></div></div>') + '</div>';
		bind();
		const lg = m.querySelector('.sp-log'); if (lg && S.rows.length) { const last = lg.lastElementChild; if (last) last.scrollIntoView({ block: 'end' }); }
	}
	function bind() {
		const m = S.main;
		const ta = m.querySelector('[data-in]');
		if (ta) {
			const fit = () => { ta.style.height = 'auto'; ta.style.height = Math.min(180, ta.scrollHeight) + 'px'; };
			ta.addEventListener('input', fit);
			ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(ta.value); } });
			if (!S.busy && window.matchMedia('(min-width:641px)').matches) ta.focus();
		}
		const f = m.querySelector('[data-f]'); if (f) f.addEventListener('submit', e => { e.preventDefault(); send(ta.value); });
		m.querySelectorAll('[data-sg]').forEach(b => b.addEventListener('click', () => { if (ta) { ta.value = b.textContent; ta.focus(); } }));
		const nw = m.querySelector('[data-new]'); if (nw) nw.addEventListener('click', () => { saveCid(''); S.rows = []; S.err = ''; S.ro = false; S.run = {}; if (location.hash.indexOf('#sugo:') === 0) history.replaceState(history.state, '', '#sugo'); draw(); });
		const es = m.querySelector('[data-esc]'); if (es) es.addEventListener('click', escalate);
		m.querySelectorAll('[data-fx]').forEach(b => b.addEventListener('click', () => runFix(Number(b.dataset.fx))));
	}
	async function send(txt) {
		const msg = String(txt || '').trim(); if (!msg || S.busy || S.ro) return;
		S.busy = true; S.err = ''; S.rows.push({ role: 'user', text: msg, at: 'most' }); S.run = {}; draw();
		try { const r = await post('cp_sup_send', { cid: S.cid, msg }); saveCid(r.cid); S.rows = r.rows; S.left = r.left; }
		catch (e) { S.rows.pop(); S.err = e.message; S.busy = false; draw(); const ta = S.main && S.main.querySelector('[data-in]'); if (ta) ta.value = msg; return; }
		S.busy = false; draw();
	}
	async function escalate() {
		const note = window.prompt('Szeretnél még valamit hozzáírni Balázsnak? (nem kötelező)', '');
		if (note === null) return;
		try { const r = await post('cp_sup_escalate', { cid: S.cid, note }); S.rows = r.rows; S.err = ''; } catch (e) { S.err = e.message; }
		draw();
	}
	/* a jóváhagyott javítás: a panel meglévő végpontja fut, a kolléga jogaival */
	async function runFix(k) {
		const r = S.rows.filter(x => x.role === 'assistant').pop(); if (!r || !r.fixes || !r.fixes[k] || S.run[k]) return;
		const f = r.fixes[k]; S.run[k] = 1; draw();
		let ok = true, note = '';
		try {
			if (f.action === 'reopen_thread') await post('cp_mail_bulk', { ids: f.thread_id, op: 'open' });
			else if (f.action === 'restore_thread') await post('cp_mail_bulk', { ids: f.thread_id, op: 'restore' });
			else if (f.action === 'link_thread_deal') await post('cp_mail_thread_set', { id: f.thread_id, f: 'deal', v: f.deal_id });
			else if (f.action === 'unlink_thread_deal') await post('cp_mail_thread_set', { id: f.thread_id, f: 'deal', v: 'none' });
			else if (f.action === 'fetch_mail_now') await post('cp_mail_fetch_now', {});
			else throw new Error('Ismeretlen művelet');
		} catch (e) { ok = false; note = e.message; }
		try { await post('cp_sup_fixed', { cid: S.cid, k, ok: ok ? 1 : '', note }); } catch (e) { /* a jelölés nem kritikus */ }
		r.done = r.done || {}; r.done[k] = ok ? 'ok' : 'hiba: ' + note; delete S.run[k];
		if (!ok) S.err = 'A javítás nem sikerült: ' + note;
		draw();
	}
	async function load(cid) {
		try { const r = await post('cp_sup_hist', { cid: cid || '' }); S.rows = r.rows || []; S.left = r.left; S.owner = r.owner || ''; S.err = ''; return true; }
		catch (e) { S.err = e.message; S.rows = []; return false; }
	}
	async function view(main) {
		css(); S.main = main;
		const h = location.hash.match(/^#sugo:([a-z0-9]{8,24})$/);
		S.ro = false;
		if (h && h[1] !== S.cid) { await load(h[1]); S.ro = !!S.rows.length && S.owner !== (C.user || ''); if (!S.ro) saveCid(h[1]); }
		else await load(S.cid);
		if (!S.rows.length && !S.ro) saveCid('');
		draw();
	}
	window.CPSup = { view };
})();
