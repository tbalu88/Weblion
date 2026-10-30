/* =====================================================================
 * Kezdőlap (v1.65) – mai teendők, új ajánlatkérések, levelek, közelgő rendezvények, figyelmet igénylő dealek, a hónap számai
 * ===================================================================== */
(function () {
	'use strict';
	const C = window.CPX || {};
	const S = { main: null, x: null, err: '', at: 0, mine: false, timer: 0, tsel: new Set(), oldOpen: false, groups: {} };
	try { S.mine = localStorage.getItem('cpdash_mine') === '1'; } catch (e) { /* privát mód */ }
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const pad = n => String(n).padStart(2, '0');
	const HU_M = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
	const HU_D = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
	const ico = id => (window.CPXIco ? window.CPXIco(id) : '');
	const ft = v => Math.round(Number(v) || 0).toLocaleString('hu-HU').replace(/ /g, ' ') + ' Ft';
	async function post(action, data) {
		const fd = new FormData(); fd.append('action', action); fd.append('t', C.t);
		Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j; try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || 'Hiba történt.');
		return j.data;
	}
	function toast(m, bad) {
		let d = document.getElementById('cpx-toast');
		if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); }
		d.textContent = m; d.className = 'cpx-toast on' + (bad ? ' err' : ''); clearTimeout(d._t); d._t = setTimeout(() => { d.className = 'cpx-toast'; }, 3500);
	}
	const go = tab => { if (window.CPXGo) window.CPXGo(tab); };
	const dOnly = s => String(s || '').slice(0, 10);
	const dayDiff = d => { const a = new Date(dOnly(d) + 'T00:00:00'), b = new Date(S.x.today + 'T00:00:00'); return Math.round((a - b) / 86400000); };
	const dayLabel = d => { const n = dayDiff(d); if (n === 0) return 'Ma'; if (n === 1) return 'Holnap'; const x = new Date(dOnly(d) + 'T00:00:00'); return (x.getMonth() + 1) + '. ' + x.getDate() + '. ' + HU_D[x.getDay()]; };
	const dueTxt = s => { if (!s) return ''; const n = dayDiff(s), tm = String(s).length > 10 ? String(s).slice(11, 16) : ''; if (n < 0) return '<b class="dh-late">' + (-n) + ' napja lejárt</b>'; if (n === 0) return 'ma' + (tm && tm !== '00:00' ? ' ' + tm : ''); if (n === 1) return 'holnap'; return dayLabel(s); };

	/* v1.66.1: csak akkor rajzol, ha a Kezdőlap a látható fül (a #cpx-main a többi fülé is!) */
	const active = () => { const b = document.querySelector('.cpx-tabs [data-tab].on'); return !!(S.main && S.main.isConnected && b && b.dataset.tab === 'kezdolap'); };
	async function load(quiet) {
		if (!quiet) { S.err = ''; }
		try { S.x = await post('cp_dash_data', {}); S.at = Date.now(); S.err = ''; }
		catch (e) { S.err = e.message; }
		if (active()) { draw(); status(document.getElementById('cpx-status')); }
	}

	function card(cls, title, icon, body, extra) {
		return '<section class="dh-card ' + cls + '"><header><h3>' + icon + '<span>' + title + '</span></h3>' + (extra || '') + '</header>' + body + '</section>';
	}
	const empty = t => '<div class="dh-empty">' + t + '</div>';

	function draw() {
		const m = S.main;
		if (!S.x) { m.innerHTML = S.err ? '<div class="cpx-empty err">' + esc(S.err) + '</div>' : '<div class="cpx-empty">Betöltés…</div>'; return; }
		const x = S.x, now = new Date();
		const h = now.getHours(), hi = h < 9 ? 'Jó reggelt' : (h < 18 ? 'Szia' : 'Jó estét');
		const first = (x.me || '').trim().split(/\s+/).slice(-1)[0] || '';
		let html = '<div class="dh"><div class="dh-top"><div><h2>' + hi + (first ? ', ' + esc(first) : '') + '!</h2><p>' + now.getFullYear() + '. ' + HU_M[now.getMonth()] + ' ' + now.getDate() + '., ' + HU_D[now.getDay()] + '</p></div>' +
			'<button type="button" class="cpx-btn ghost" data-ref title="Frissítés">' + ico('refresh') + '<span>Frissítés</span></button></div>';
		/* a hónap számai */
		if (x.kpi) {
			const k = x.kpi, tile = (n, l, s, tab) => '<button type="button" class="dh-kpi" data-go="' + tab + '"><b>' + n + '</b><span>' + l + '</span>' + (s ? '<small>' + s + '</small>' : '') + '</button>';
			html += '<div class="dh-kpis">' + tile(k.new_n, 'Új deal ebben a hónapban', k.lost_n ? k.lost_n + ' elvesztett' : '', 'deals') + tile(k.won_n, 'Megnyert ebben a hónapban', k.won_v ? ft(k.won_v) : '', 'deals') +
				tile(k.open_n, 'Nyitott deal', k.open_v ? ft(k.open_v) : '', 'deals') + tile(k.ev_n, 'Rendezvény ebben a hónapban', '', x.can.gnaptar ? 'gnaptar' : x.can.naptar ? 'naptar' : 'deals') + '</div>';
		}
		html += '<div class="dh-grid">';
		/* figyelmet igényel – javaslatok */
		const att = [];
		(x.events || []).forEach(e => {
			const n = dayDiff(e.d);
			if (e.p && e.p.total && e.p.done < e.p.total && n <= 5) att.push({ k: 'deal', id: e.id, t: e.t, w: 'Ügyfélportál hiányos (' + e.p.done + '/' + e.p.total + ') – rendezvény: ' + dayLabel(e.d).toLowerCase() });
			if (!e.op && n <= 7 && e.st === 'Won') att.push({ k: 'deal', id: e.id, t: e.t, w: 'Nincs kezelő megadva – rendezvény: ' + dayLabel(e.d).toLowerCase() });
		});
		(x.stale || []).filter(d => !S.mine || !x.sm || d.own === x.sm).forEach(d => att.push({ k: 'deal', id: d.id, t: d.t, w: 'Elakadt a(z) „' + d.st + '” szakaszban' + (d.lc ? ' – utolsó kapcsolat: ' + dOnly(d.lc) : '') + (d.on ? ' · ' + d.on : '') }));
		if (x.mail && x.mail.unassigned > 0) att.push({ k: 'tab', tab: 'mail', t: x.mail.unassigned + ' kiosztatlan levélszál', w: 'Oszd ki valakinek, hogy ne maradjon válasz nélkül' });
		if (x.leads) att.push({ k: 'leads', t: x.leads + ' webes megkeresés vár párosításra', w: 'Az elmúlt 7 napból – Deal-ek → Megkeresések' });
		if (x.kpi || x.mail) {
			html += card('dh-att', 'Figyelmet igényel', '<i class="dh-dot"></i>', att.length ? '<ul class="dh-list">' + att.slice(0, 10).map(a => '<li><button type="button" data-att="' + esc(JSON.stringify({ k: a.k, id: a.id || 0, tab: a.tab || '' })) + '"><b>' + esc(a.t) + '</b><small>' + esc(a.w) + '</small></button></li>').join('') + '</ul>' + (att.length > 10 ? '<p class="dh-more">+ ' + (att.length - 10) + ' további</p>' : '') : empty('Minden rendben – nincs elakadt deal vagy sürgős hiány. 🎉'), x.stale_n ? '<span class="dh-n">' + att.length + '</span>' : '');
		}
		/* mai teendőim */
		if (x.todo) {
			/* v1.68: csoportok (aktuális / lejárt 30 napon belül / régi lejárt – összecsukva), tömeges kijelölés */
			const t = x.todo.slice().sort((a, b) => String(a.due || '9999').localeCompare(String(b.due || '9999')));
			const G = [['now', 'Ma és a következő napok', t.filter(i => !i.due || dayDiff(i.due) >= 0).reverse().reverse()], ['late', 'Lejárt (30 napon belül)', t.filter(i => i.due && dayDiff(i.due) < 0 && dayDiff(i.due) >= -30).reverse()], ['old', 'Régi lejárt (30+ napja)', t.filter(i => i.due && dayDiff(i.due) < -30).reverse()]].filter(g => g[2].length);
			const li = i => '<li class="dh-ti' + (S.tsel.has(i.k + ':' + i.id) ? ' sel' : '') + '"><label class="dh-ck" title="Kijelölés"><input type="checkbox" data-tsel="' + i.k + ':' + i.id + '"' + (S.tsel.has(i.k + ':' + i.id) ? ' checked' : '') + '></label><button type="button" data-todo="' + i.k + ':' + i.id + ':' + (i.deal || 0) + '"><b>' + esc(i.t) + '</b><small>' + (i.k === 'act' ? esc(i.ty || 'Tevékenység') + (i.dt ? ' · ' + esc(i.dt) : '') : 'Feladat · ' + esc(i.bn)) + (i.due ? ' · ' + dueTxt(i.due) : '') + '</small></button></li>';
			const grp = g => { const open = g[0] !== 'old' || S.oldOpen; return '<li class="dh-tg"><label><input type="checkbox" data-tall="' + g[0] + '"' + (g[2].every(i => S.tsel.has(i.k + ':' + i.id)) ? ' checked' : '') + ' title="Mind kijelölése"></label><button type="button" data-tgo="' + g[0] + '">' + esc(g[1]) + ' <em>' + g[2].length + '</em>' + (g[0] === 'old' ? ' <span>' + (open ? '▾' : '▸') + '</span>' : '') + '</button></li>' + (open ? g[2].slice(0, g[0] === 'old' ? 300 : 100).map(li).join('') : ''); };
			S.groups = {}; G.forEach(g => { S.groups[g[0]] = g[2].map(i => i.k + ':' + i.id); });
			const bar = '<div class="dh-bulk"' + (S.tsel.size ? '' : ' hidden') + '><b>' + S.tsel.size + ' kijelölve</b><button type="button" data-bop="done">✓ Kész</button><span class="dh-later"><button type="button" data-blater>Későbbre ▾</button><span class="dh-lpop" hidden><button type="button" data-bop="later" data-days="1">holnapra</button><button type="button" data-bop="later" data-days="7">1 héttel később</button><button type="button" data-bop="later" data-days="30">1 hónappal később</button></span></span><button type="button" data-bop="hide" title="Csak a Kezdőlapról tűnik el – a Salesmate-ben / a Feladatokban megmarad">Elrejtés</button><button type="button" class="dh-x" data-bclr>Mégse</button></div>';
			html += card('dh-todo', 'Mai teendőim', ico('kovetes'), bar + (t.length ? '<ul class="dh-list">' + G.map(grp).join('') + '</ul>' : empty('Mára nincs nyitott teendőd.')) +
				(x.todo_need_sm ? '<p class="dh-hint">A Salesmate-tevékenységeid akkor jelennek meg itt, ha a fiókodhoz hozzá van rendelve a Salesmate-felhasználód (WordPress admin → Panel-felhasználók).</p>' : ''), '<span class="dh-n">' + t.length + '</span>');
		}
		/* új ajánlatkérések */
		if (x.new) {
			const l = x.new.filter(d => !S.mine || !x.sm || d.own === x.sm);
			html += card('dh-new', 'Új ajánlatkérések', ico('deals'), (x.leads ? '<button type="button" class="dh-banner" data-leads>' + x.leads + ' webes megkeresés vár párosításra →</button>' : '') +
				(l.length ? '<ul class="dh-list">' + l.slice(0, 8).map(d => '<li><button type="button" data-deal="' + d.id + '"><b>' + esc(d.t) + '</b><small>' + esc(d.st) + (d.on ? ' · ' + esc(d.on) : '') + ' · ' + esc(dueTxtPast(d.at)) + (d.ev ? ' · rendezvény: ' + esc(d.ev) : '') + '</small></button></li>').join('') + '</ul>' + (l.length > 8 ? '<button type="button" class="dh-more" data-go="deals">Mind a ' + l.length + ' megnyitása →</button>' : '') : empty('Az elmúlt 7 napban nem jött új ajánlatkérés' + (S.mine ? ' neked' : '') + '.')),
				(x.sm ? '<label class="dh-mine"><input type="checkbox" data-mine' + (S.mine ? ' checked' : '') + '> csak az enyém</label>' : '') + '<span class="dh-n">' + l.length + '</span>');
		}
		/* levelek */
		if (x.mail) {
			const M = x.mail, row = t => '<li><button type="button" data-th="' + t.id + '" class="' + (t.un ? 'un' : '') + '"><b>' + esc(t.c || '–') + '<em>' + esc(dueTxtPast(t.at)) + '</em></b><span>' + esc(t.s) + '</span><small>' + esc(t.sn) + '</small></button></li>';
			html += card('dh-mail', 'Levelek', ico('mail'), '<div class="dh-mc"><button type="button" data-go="mail"><b>' + M.unread + '</b>olvasatlan</button><button type="button" data-go="mail"><b>' + M.unassigned + '</b>kiosztatlan</button><button type="button" data-go="mail"><b>' + M.mine_n + '</b>nálam</button></div>' +
				(M.mine.length ? '<h4>Nálam</h4><ul class="dh-list dh-ml">' + M.mine.map(row).join('') + '</ul>' : '') +
				(M.waiting.length ? '<h4>Kiosztásra vár</h4><ul class="dh-list dh-ml">' + M.waiting.map(row).join('') + '</ul>' : (M.mine.length ? '' : empty('Nincs nyitott levél.'))));
		}
		/* közelgő rendezvények */
		if (x.events) {
			let last = '';
			const ev = x.events.map(e => { const dl = dayLabel(e.d), hd = dl !== last ? '<li class="dh-day">' + esc(dl) + '</li>' : ''; last = dl; return hd + '<li><button type="button" data-deal="' + e.id + '"><b>' + (e.tm ? '<em class="dh-tm">' + esc(e.tm) + '</em>' : '') + esc(e.t) + '</b><small>' + [e.v, e.svc, e.op ? 'kezelő: ' + e.op : ''].filter(Boolean).map(esc).join(' · ') + '</small><span class="dh-tags">' + (e.st === 'Open' ? '<i class="dh-tag warn">még nyitott: ' + esc(e.stage) + '</i>' : '') + (e.p && e.p.total ? '<i class="dh-tag ' + (e.p.done >= e.p.total ? 'ok' : 'warn') + '">portál ' + e.p.done + '/' + e.p.total + '</i>' : '') + '</span></button></li>'; }).join('');
			html += card('dh-ev', 'Közelgő rendezvények', ico('naptar'), x.events.length ? '<ul class="dh-list">' + ev + '</ul>' : empty('A következő 14 napban nincs rendezvény.'), (x.can.gnaptar ? '<button type="button" class="dh-link" data-go="gnaptar">Naptár →</button>' : x.can.naptar ? '<button type="button" class="dh-link" data-go="naptar">Kapacitás →</button>' : ''));
		}
		html += '</div></div>';
		m.innerHTML = html;
		bind();
	}
	function dueTxtPast(s) {
		if (!s) return '';
		const n = -dayDiff(s), tm = String(s).slice(11, 16);
		if (n <= 0) return 'ma ' + tm; if (n === 1) return 'tegnap ' + tm; return n + ' napja';
	}

	function bind() {
		const m = S.main;
		m.querySelector('[data-ref]').addEventListener('click', () => load());
		m.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => go(b.dataset.go)));
		m.querySelectorAll('[data-deal]').forEach(b => b.addEventListener('click', () => { if (window.CPCrm) window.CPCrm.open(Number(b.dataset.deal)); }));
		m.querySelectorAll('[data-th]').forEach(b => b.addEventListener('click', () => { if (window.CPMail) window.CPMail.open(Number(b.dataset.th)); }));
		const leads = () => { go('deals'); setTimeout(() => { if (window.CPCrm && window.CPCrm.deep) window.CPCrm.deep('leads'); }, 60); };
		m.querySelectorAll('[data-leads]').forEach(b => b.addEventListener('click', leads));
		m.querySelectorAll('[data-att]').forEach(b => b.addEventListener('click', () => {
			const a = JSON.parse(b.dataset.att);
			if (a.k === 'deal' && window.CPCrm) window.CPCrm.open(a.id); else if (a.k === 'tab') go(a.tab); else if (a.k === 'leads') leads();
		}));
		m.querySelectorAll('[data-todo]').forEach(b => b.addEventListener('click', () => {
			const [k, id, deal] = b.dataset.todo.split(':');
			if (k === 'card' && window.CPKanban) window.CPKanban.openCard(Number(id), () => load(true));
			else if (k === 'act' && Number(deal) && window.CPCrm) window.CPCrm.open(Number(deal));
			else if (k === 'act') toast('Ez a tevékenység nincs dealhez kötve – a Salesmate-ben látod.');
		}));
		/* v1.68: kijelölés és tömeges műveletek (15-ösével a szerverre) */
		const upBar = () => { const b = m.querySelector('.dh-bulk'); if (!b) return; b.hidden = !S.tsel.size; b.querySelector('b').textContent = S.tsel.size + ' kijelölve'; };
		m.querySelectorAll('[data-tsel]').forEach(c => c.addEventListener('change', () => { const k = c.dataset.tsel; if (c.checked) S.tsel.add(k); else S.tsel.delete(k); c.closest('li').classList.toggle('sel', c.checked); upBar(); }));
		m.querySelectorAll('[data-tall]').forEach(c => c.addEventListener('change', () => { (S.groups[c.dataset.tall] || []).forEach(k => { if (c.checked) S.tsel.add(k); else S.tsel.delete(k); }); draw(); }));
		m.querySelectorAll('[data-tgo]').forEach(b => b.addEventListener('click', () => { if (b.dataset.tgo === 'old') { S.oldOpen = !S.oldOpen; draw(); } }));
		const lb = m.querySelector('[data-blater]');
		if (lb) lb.addEventListener('click', e => { e.stopPropagation(); const p = m.querySelector('.dh-lpop'); p.hidden = !p.hidden; });
		const clr = m.querySelector('[data-bclr]');
		if (clr) clr.addEventListener('click', () => { S.tsel.clear(); draw(); });
		m.querySelectorAll('[data-bop]').forEach(b => b.addEventListener('click', async () => {
			const op = b.dataset.bop, days = b.dataset.days || '', all = Array.from(S.tsel), acts = all.filter(k => k.indexOf('act:') === 0).length;
			const what = op === 'done' ? 'Késznek jelölsz' : op === 'later' ? 'Későbbre teszel' : 'Elrejtesz a Kezdőlapról';
			if (!window.confirm(what + ' ' + all.length + ' teendőt?' + (op !== 'hide' && acts ? '\n\n' + acts + ' Salesmate-tevékenység a Salesmate-ben is ' + (op === 'done' ? 'lezárul' : 'új határidőt kap') + '.' : '\n\nA Salesmate-ben és a Feladatokban megmaradnak.'))) return;
			const bar = m.querySelector('.dh-bulk'); bar.querySelectorAll('button').forEach(x => { x.disabled = true; });
			let ok = 0, bad = 0;
			for (let i = 0; i < all.length; i += 15) {
				bar.querySelector('b').textContent = 'Feldolgozás: ' + Math.min(i + 15, all.length) + ' / ' + all.length + '…';
				try { const r = await post('cp_dash_bulk', { op: op, days: days, items: all.slice(i, i + 15).join(',') }); ok += r.ok; bad += r.err.length; } catch (e) { bad += Math.min(15, all.length - i); toast(e.message, true); }
			}
			S.tsel.clear();
			toast(ok + ' teendő kész.' + (bad ? ' ' + bad + ' nem sikerült.' : ''), !!bad);
			load(true);
		}));
		const mi = m.querySelector('[data-mine]');
		if (mi) mi.addEventListener('change', () => { S.mine = mi.checked; try { localStorage.setItem('cpdash_mine', S.mine ? '1' : '0'); } catch (e) { /* privát mód */ } draw(); });
	}

	function view(main) {
		S.main = main;
		if (S.x) draw(); else main.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
		if (!S.x || Date.now() - S.at > 60000) load(!!S.x);
		clearInterval(S.timer);
		S.timer = setInterval(() => { if (!active()) { clearInterval(S.timer); return; } if (!document.hidden) load(true); }, 120000);
	}
	function status(s) {
		if (!s || !document.querySelector('.dh')) { if (s && S.err) { s.textContent = S.err; s.className = 'cpx-status err'; } return; }
		if (S.err) { s.textContent = S.err; s.className = 'cpx-status err'; return; }
		if (S.at) { const d = new Date(S.at); s.textContent = 'Kezdőlap: ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); s.className = 'cpx-status'; }
	}

	const css = document.createElement('style');
	css.textContent = '.dh{max-width:1400px;color:#141C33}.dh-top{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin:4px 0 16px}.dh-top h2{margin:0;font:700 24px Inter,sans-serif}.dh-top p{margin:4px 0 0;color:#6B6F78;font-size:14px}' +
		'.dh-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.dh-kpi{display:flex;flex-direction:column;align-items:flex-start;gap:2px;background:#fff;border:1px solid #E6E3DE;border-radius:14px;padding:14px 16px;cursor:pointer;text-align:left;font:inherit;color:inherit}.dh-kpi:hover{border-color:#C9C3B8}.dh-kpi b{font:700 26px Inter,sans-serif}.dh-kpi span{color:#4A5163;font-size:13px}.dh-kpi small{color:#2F7D4F;font-weight:600;font-size:12.5px}' +
		'.dh-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:14px;align-items:start}.dh-card{background:#fff;border:1px solid #E6E3DE;border-radius:16px;padding:6px 0 10px;min-width:0}' +
		'.dh-card>header{display:flex;align-items:center;gap:8px;padding:10px 16px 8px}.dh-card h3{display:flex;align-items:center;gap:8px;margin:0;flex:1;font:700 15.5px Inter,sans-serif}.dh-card h3 .cpx-ico{width:19px;height:19px;fill:none;stroke:#E8833A;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}' +
		'.dh-n{background:#F1EDE7;border-radius:999px;padding:1px 9px;font-size:12.5px;font-weight:700;color:#4A5163}.dh-dot{width:10px;height:10px;border-radius:50%;background:#E8553E;display:inline-block}.dh-att{border-color:#F3C9BE}' +
		'.dh-list{list-style:none;margin:0;padding:0}.dh-list li{display:flex;align-items:flex-start;border-top:1px solid #F2EFEA}.dh-list li.gone{opacity:.35;text-decoration:line-through}.dh-list button{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;border:0;background:transparent;text-align:left;padding:9px 16px;cursor:pointer;font:13.5px/1.35 Inter,sans-serif;color:inherit}.dh-list button:hover{background:#FAF8F5}' +
		'.dh-list b{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}.dh-list small{color:#6B6F78;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.dh-list span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
		'.dh-ck{display:flex;align-items:center;padding:10px 0 0 16px}.dh-ck input{width:18px;height:18px;accent-color:#2F7D4F;cursor:pointer}.dh-ti button{padding-left:10px}.dh-late{color:#C0392B}' +
		'.dh-day{padding:8px 16px 4px!important;font:700 12px Inter,sans-serif;text-transform:uppercase;letter-spacing:.04em;color:#8A8F99;border-top:0!important}.dh-tm{font-style:normal;color:#2F6FDB;margin-right:6px}.dh-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:3px}.dh-tag{font-style:normal;font-size:11.5px;font-weight:600;border-radius:6px;padding:1px 7px;background:#EEF6F0;color:#2F7D4F}.dh-tag.warn{background:#FFF4E0;color:#8A5A00}' +
		'.dh-mc{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:2px 16px 10px}.dh-mc button{border:1px solid #E6E3DE;border-radius:12px;background:#FAF8F5;padding:8px;cursor:pointer;font:12.5px Inter,sans-serif;color:#4A5163}.dh-mc b{display:block;font:700 20px Inter,sans-serif;color:#141C33}' +
		'.dh-card h4{margin:8px 16px 2px;font:700 12px Inter,sans-serif;text-transform:uppercase;letter-spacing:.04em;color:#8A8F99}.dh-ml b{display:flex;justify-content:space-between;gap:8px}.dh-ml em{font-style:normal;font-weight:500;color:#8A8F99;font-size:12px;flex:none}.dh-ml .un b{color:#1F3F99}.dh-ml .un span{font-weight:600}' +
		'.dh-tg{display:flex;align-items:center;background:#FAF8F5}.dh-tg label{display:flex;align-items:center;padding:0 0 0 16px}.dh-tg input{width:16px;height:16px;accent-color:#2F6FDB}.dh-tg button{flex-direction:row!important;gap:6px!important;align-items:center;font:700 12px Inter,sans-serif!important;text-transform:uppercase;letter-spacing:.04em;color:#6B6F78;padding:8px 10px!important}.dh-tg em{font-style:normal;background:#EEE9E2;border-radius:999px;padding:0 7px;color:#4A5163}.dh-ti.sel{background:#EEF3FD}' +
		'.dh-bulk{position:sticky;top:0;z-index:2;display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:0 12px 8px;padding:8px 10px;background:#1F3F99;color:#fff;border-radius:10px;font-size:13px}.dh-bulk b{margin-right:4px}.dh-bulk button{border:0;border-radius:7px;background:rgba(255,255,255,.16);color:#fff;font:600 12.5px Inter,sans-serif;padding:5px 10px;cursor:pointer}.dh-bulk button:hover{background:rgba(255,255,255,.28)}.dh-bulk .dh-x{background:transparent;text-decoration:underline}.dh-later{position:relative}.dh-lpop{position:absolute;top:110%;left:0;z-index:5;background:#fff;border-radius:10px;box-shadow:0 12px 30px -10px rgba(0,0,0,.4);display:flex;flex-direction:column;padding:4px;min-width:170px}.dh-lpop button{background:transparent!important;color:#1A1F2B!important;text-align:left}.dh-lpop button:hover{background:#F6F4F0!important}' +
		'.dh-empty{padding:12px 16px;color:#6B6F78;font-size:13.5px}.dh-hint{margin:8px 16px 0;font-size:12px;color:#8A5A00;background:#FFF8EC;border-radius:8px;padding:6px 10px}.dh-banner{display:block;width:calc(100% - 32px);margin:0 16px 8px;border:0;border-radius:10px;background:#EEF3FD;color:#1F3F99;font:600 13px Inter,sans-serif;padding:8px 10px;text-align:left;cursor:pointer}' +
		'.dh-more{display:block;border:0;background:none;color:#2F6FDB;font:600 13px Inter,sans-serif;padding:8px 16px 0;cursor:pointer;margin:0}.dh-link{border:0;background:none;color:#2F6FDB;font:600 13px Inter,sans-serif;cursor:pointer}.dh-mine{font-size:12.5px;color:#4A5163;display:flex;align-items:center;gap:4px;cursor:pointer}' +
		'@media (max-width:1100px){.dh-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}' +
		'@media (max-width:760px){.dh-top h2{font-size:21px}.dh-top .cpx-btn span{display:none}.dh-grid{grid-template-columns:1fr}.dh-kpi{padding:10px 12px}.dh-kpi b{font-size:22px}.dh-list button{font-size:14.5px;padding:11px 14px}.dh-list small{font-size:13px}.dh-card>header{padding:10px 14px 8px}}';
	document.head.appendChild(css);
	window.CPDash = { view, status, refresh: () => load() };
})();
