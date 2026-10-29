/* Crazy panel – Eszközök → Automatizmusok (v1.44): a Salesmate Smart Flow-k mása, folyamatonként Ki / Árnyék / Élő */
(function () {
	'use strict';
	const C = window.CPX || {};
	const FL = C.fl || {};
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const S = { main: null, data: null, open: {}, err: '' };

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

	/* ------------------------------------------------------------ a folyamat leírása olvasható formában */
	const FN = { stage: 'Szakasz', status: 'Állapot', source: 'Forrás', ctype: 'Szerződés EV/KFT', portal_link: 'Ügyfélportál link', last_comm_at: 'Utolsó kommunikáció' };
	const ST = { Open: 'nyitott', Won: 'megnyert', Lost: 'elvesztett' };
	function trig(t) {
		switch (t.type) {
			case 'deal_created': return 'Új deal jön létre';
			case 'stage_enter': return 'A deal a „' + t.stage + '” szakaszba kerül';
			case 'date': return 'A rendezvény dátuma ' + (t.days < 0 ? 'előtt ' + (-t.days) : 'után ' + t.days) + ' nappal, ' + t.hour + ':00-kor';
			case 'contact_tag': return 'Új kontakt jön létre „' + t.tag + '” címkével';
			case 'manual': return 'Csak kézi indítás';
			default: return 'Nincs indító';
		}
	}
	function cond(c) {
		const f = FN[c.f] || c.f, v = Array.isArray(c.v) ? c.v.map(x => '„' + (ST[x] || x) + '”').join(' vagy ') : '„' + (ST[c.v] || c.v) + '”';
		return { eq: f + ' = ' + v, neq: f + ' ≠ ' + v, in: f + ': ' + v, empty: f + ' üres', not_empty: f + ' nem üres', this_week: f + ' ezen a héten' }[c.op] || f;
	}
	function step(s) {
		switch (s.a) {
			case 'set_title': return 'Deal címe: „' + esc(s.v) + '”';
			case 'set_stage': return 'Szakasz: „' + esc(s.v) + '”';
			case 'pumble': return 'Pumble-üzenet: <pre>' + esc(s.v) + '</pre>';
			case 'activity': return 'Tevékenység: „' + esc(s.v) + '” (' + esc(s.type) + ', ' + esc(s.owner) + ', határidő: a rendezvény napja)';
			case 'email': return s.tpl ? 'Levél a kontaktnak: „' + esc(s.tpl) + '”' : 'Levél a kontaktnak a(z) ' + esc(FN[s.by] || s.by) + ' szerint: ' + Object.keys(s.map || {}).map(k => esc(k) + ' → „' + esc(s.map[k]) + '”').join(', ') + ' (üres mezőnél nincs levél)';
			case 'branch': return 'Ha ' + esc(cond(s.if)) + ':<ol>' + s.then.map(x => '<li>' + step(x) + '</li>').join('') + '</ol>különben:<ol>' + s.else.map(x => '<li>' + step(x) + '</li>').join('') + '</ol>';
			case 'note': return '<span class="fl-note">' + esc(s.t) + '</span>';
			default: return esc(s.a);
		}
	}
	const MODES = [['off', 'Ki'], ['shadow', 'Árnyék'], ['live', 'Élő']];

	/* ------------------------------------------------------------ nézet */
	async function view(main) {
		S.main = main;
		main.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
		try { S.data = await post('cp_flow_list', {}); S.err = ''; } catch (e) { S.err = e.message; main.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		draw();
	}
	function draw() {
		const main = S.main, x = S.data;
		main.innerHTML = '<div class="fl"><div class="fl-head"><h2>Automatizmusok</h2><p>A Salesmate Smart Flow-k a panelben. Folyamatonként kapcsolható: <b>Ki</b> · <b>Árnyék</b> – csak feljegyzi, mit csinálna, és utólag megnézi, hogy a Salesmate ugyanezt tette-e · <b>Élő</b> – a panel végzi el (a Salesmate-be ír). A tesztidőszakban a Salesmate folyamatai tovább futnak; élőre csak akkor kapcsolj, ha a Salesmate-ben ugyanazt kikapcsoltad.</p>' +
			'<small class="cpx-muted">Az engine 10 percenként fut' + (x.last ? ', utoljára: ' + esc(x.last) : '') + '. A szerkesztés a következő lépésben jön.</small></div>' +
			'<div class="fl-list">' + x.flows.map(f => '<section class="fl-card m-' + f.mode + '" data-flow="' + esc(f.id) + '">' +
				'<header><div><h3>' + esc(f.n) + '</h3><small>Salesmate: <b>' + esc(f.sm) + '</b>' + (f.folder ? ' · ' + esc(f.folder) : '') + (f.st.n ? ' · az elmúlt 7 napban: ' + f.st.n + ' lépés, ' + f.st.d + ' deal' : '') + '</small></div>' +
				'<div class="fl-seg" role="group" aria-label="Mód">' + MODES.map(m => '<button type="button" data-mode="' + m[0] + '" class="' + (f.mode === m[0] ? 'on' : '') + '"' + (m[0] === 'live' && !f.live_ok ? ' disabled title="' + esc(f.why || 'Élőben még nem kapcsolható') + '"' : '') + (m[0] === 'shadow' && ['contact_tag', 'manual', 'none'].includes(f.def.trigger.type) ? ' disabled title="Ennek az indítója (Facebook-kontakt, kézi indítás) a panelben még nem figyelhető"' : '') + (!FL.admin ? ' disabled' : '') + '>' + m[1] + '</button>').join('') + '</div></header>' +
				'<div class="fl-def"><div><span class="fl-k">Indító</span>' + esc(trig(f.def.trigger)) + '</div>' +
				(f.def.conds.length ? '<div><span class="fl-k">Feltétel</span>' + f.def.conds.map(c => esc(cond(c))).join(' <i>és</i> ') + '</div>' : '') +
				(f.def.steps.length ? '<div><span class="fl-k">Lépések</span><ol>' + f.def.steps.map(s => '<li>' + step(s) + '</li>').join('') + '</ol></div>' : '') +
				(!f.live_ok && f.why ? '<p class="fl-why">' + esc(f.why) + '</p>' : '') + '</div>' +
				'<footer><button type="button" class="fl-logb" data-log>' + (S.open[f.id] ? 'Napló elrejtése' : 'Napló') + '</button><button type="button" class="fl-logb" disabled title="Hamarosan">Szerkesztés</button></footer>' +
				'<div class="fl-log" data-logbox' + (S.open[f.id] ? '' : ' hidden') + '></div></section>').join('') + '</div></div>';
		main.querySelectorAll('[data-flow]').forEach(card => {
			const id = card.dataset.flow, f = x.flows.find(z => z.id === id);
			card.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', async () => {
				const m = b.dataset.mode;
				if (m === f.mode) return;
				if (m === 'live' && !window.confirm('Élő módban a panel maga végzi el ezt a folyamatot, és a Salesmate-be is ír (pl. átnevez, szakaszt vált, Pumble-üzenetet küld).\n\nHa a Salesmate-ben ugyanez a folyamat még fut, kétszer történhet meg (pl. két Pumble-üzenet). Bekapcsolod?')) return;
				try { await post('cp_flow_set', { id: id, mode: m }); f.mode = m; toast(f.n + ': ' + MODES.find(z => z[0] === m)[1]); draw(); } catch (e) { toast(e.message, true); }
			}));
			card.querySelector('[data-log]').addEventListener('click', () => { S.open[id] = !S.open[id]; draw(); });
			if (S.open[id]) loadLog(card.querySelector('[data-logbox]'), id);
		});
	}
	async function loadLog(box, id) {
		box.innerHTML = '<div class="cpx-muted">Betöltés…</div>';
		let r;
		try { r = await post('cp_flow_log', { flow: id }); } catch (e) { box.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		const CHK = { ok: '<span class="fl-ok" title="A Salesmate-ben is így lett">✓ egyezik</span>', diff: '<span class="fl-diff" title="A Salesmate-ben most más az érték">✗ eltér</span>', wait: '<span class="cpx-muted" title="Még nem jött át a szinkronnal">… ellenőrzésre vár</span>' };
		const RES = { done: 'elvégezve', error: 'hiba', same: 'már így volt', skipped: '', logged: '' };
		box.innerHTML = r.rows.length ? '<table class="fl-t"><tbody>' + r.rows.map(l => '<tr class="r-' + esc(l.res) + '"><td class="fl-at">' + esc(l.at.slice(5, 16).replace(/-/g, '.')) + '</td><td>' + (l.deal ? '<a href="#deals" data-deal="' + l.deal + '">' + esc(l.title || '#' + l.deal) + '</a>' : '') + '<div>' + esc(l.detail).replace(/\n/g, '<br>') + '</div>' +
			(l.chk === 'diff' ? '<small class="cpx-muted">Most: „' + esc(l.cur) + '”</small>' : '') + '</td><td class="fl-r">' + (l.mode === 'live' ? '<b>élő</b> ' : '') + esc(RES[l.res] || '') + ' ' + (CHK[l.chk] || '') + '</td></tr>').join('') + '</tbody></table>' :
			'<p class="cpx-muted">Még nincs bejegyzés. Árnyék vagy élő módban az első futástól (10 percenként) itt jelenik meg, mit csinált volna, illetve mit csinált.</p>';
		box.querySelectorAll('[data-deal]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); if (window.CPCrm && window.CPCrm.open) window.CPCrm.open(Number(a.dataset.deal)); }));
	}
	function status(s) { s.textContent = S.err || ''; s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function refresh() { if (S.main && S.main.isConnected) await view(S.main); }
	window.CPFlows = { view, status, refresh };

	const css = document.createElement('style');
	css.textContent = '.fl{max-width:1000px}.fl-head h2{margin:0 0 4px;font:800 20px "Plus Jakarta Sans",sans-serif}.fl-head p{margin:0 0 4px;font-size:13.5px;color:var(--ink)}' +
		'.fl-list{display:flex;flex-direction:column;gap:10px;margin-top:12px}.fl-card{background:#fff;border:1px solid var(--li);border-radius:12px;padding:12px 14px;box-shadow:0 1px 2px rgba(20,28,51,.05)}' +
		'.fl-card.m-shadow{border-color:#B9A7EE;box-shadow:inset 3px 0 0 #8E75E0}.fl-card.m-live{border-color:#7CCB9F;box-shadow:inset 3px 0 0 #2E9E62}' +
		'.fl-card header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.fl-card h3{margin:0;font:700 15px Inter,sans-serif}.fl-card header small{color:var(--mu);font-size:12px}' +
		'.fl-seg{display:inline-flex;border:1px solid var(--li);border-radius:999px;padding:2px;background:#F6F3EE;flex:none}.fl-seg button{border:0;background:transparent;border-radius:999px;padding:5px 12px;font:600 12.5px Inter,sans-serif;cursor:pointer;color:var(--mu)}' +
		'.fl-seg button.on{background:#fff;color:var(--ink);box-shadow:0 1px 2px rgba(0,0,0,.12)}.m-shadow .fl-seg button.on{background:#8E75E0;color:#fff}.m-live .fl-seg button.on{background:#2E9E62;color:#fff}.fl-seg button[disabled]{opacity:.45;cursor:not-allowed}' +
		'.fl-def{margin-top:8px;font-size:13px;display:flex;flex-direction:column;gap:4px}.fl-k{display:inline-block;min-width:78px;color:var(--mu);font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;font-weight:700;margin-right:6px}.fl-def ol{margin:2px 0 0 18px;padding:0}.fl-def pre{margin:4px 0;font:12px/1.45 ui-monospace,Menlo,monospace;background:#F6F5F2;border-radius:6px;padding:6px 8px;white-space:pre-wrap}' +
		'.fl-def i{font-style:normal;color:var(--mu)}.fl-note{color:var(--mu)}.fl-why{margin:4px 0 0;font-size:12.5px;color:#7F5F01;background:#FFF7E0;border-radius:6px;padding:4px 8px}' +
		'.fl-card footer{display:flex;gap:6px;margin-top:8px}.fl-logb{border:0;background:#F1EFEB;border-radius:8px;padding:5px 10px;font:500 12.5px Inter,sans-serif;cursor:pointer;color:var(--ink)}.fl-logb[disabled]{opacity:.5;cursor:default}' +
		'.fl-log{margin-top:8px;max-height:360px;overflow:auto;border-top:1px solid var(--li);padding-top:6px}.fl-t{width:100%;border-collapse:collapse;font-size:12.5px}.fl-t td{border-bottom:1px solid #F0ECE6;padding:5px 6px;vertical-align:top}.fl-at{white-space:nowrap;color:var(--mu)}.fl-r{white-space:nowrap;text-align:right}' +
		'.fl-t a{color:#2F5BD8;text-decoration:none;font-weight:600}.fl-ok{color:#216E4E;font-weight:600}.fl-diff{color:#AE2E24;font-weight:600}.r-error td{background:#FDECEA}' +
		'@media (max-width:760px){.fl-card header{flex-direction:column}}';
	document.head.appendChild(css);
})();
