/* =====================================================================
 * Naptár fül (v1.71) – az EventCrazy Google Naptár a panelben
 * Havi és listanézet; a bejegyzések dealhez párosítva (✏️ = ceruzás: szerződés kiküldve); figyelmeztetés a naptárból
 * hiányzó, szerződéses szakaszban lévő dealekre; a bejegyzés adatlapja (helyszín, kezelők, leírás, deal, „Mit írna a panel”).
 * ===================================================================== */
(function () {
	'use strict';
	const C = window.CPX || {};
	const MOB = () => window.matchMedia('(max-width: 760px)').matches;
	const S = { main: null, view: '', month: '', x: null, key: '', err: '', at: 0, req: 0, timer: 0, focus: '', missOpen: false };
	try { S.view = localStorage.getItem('cpgc_view') || ''; } catch (e) { /* privát mód */ }
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const pad = n => String(n).padStart(2, '0');
	const HU_M = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
	const HU_D = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
	const HU_DS = ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];
	const ico = id => (window.CPXIco ? window.CPXIco(id) : '');
	async function post(action, data) {
		const fd = new FormData(); fd.append('action', action); fd.append('t', C.t);
		Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j; try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || 'Hiba történt.');
		return j.data;
	}
	const ymd = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	const D = s => new Date(s.slice(0, 10) + 'T12:00:00');
	const addD = (s, n) => { const d = D(s); d.setDate(d.getDate() + n); return ymd(d); };
	const todayS = () => ymd(new Date());
	if (!S.month) S.month = todayS().slice(0, 7);
	const curView = () => S.view || (MOB() ? 'list' : 'month');
	function range() {
		const first = S.month + '-01', f = D(first);
		const from = addD(first, -((f.getDay() + 6) % 7));
		const last = ymd(new Date(f.getFullYear(), f.getMonth() + 1, 0, 12));
		const l = D(last);
		return [from, addD(last, (7 - l.getDay()) % 7)];
	}
	const active = () => { const b = document.querySelector('.cpx-tabs [data-tab].on'); return !!(S.main && S.main.isConnected && b && b.dataset.tab === 'gnaptar'); };
	const go = tab => { if (window.CPXGo) window.CPXGo(tab); };
	const openDeal = id => { closeModal(); go('deals'); setTimeout(() => { if (window.CPCrm && window.CPCrm.deep) window.CPCrm.deep(String(id)); }, 80); };
	const hm = s => (s && s.length > 10 ? s.slice(11, 16) : '');
	const dayLabel = s => { const d = D(s); return d.getFullYear() + '. ' + HU_M[d.getMonth()] + ' ' + d.getDate() + '., ' + HU_D[d.getDay()]; };

	async function load(force) {
		const [from, to] = range(), key = from + '|' + to, my = ++S.req;
		if (!force && S.x && S.key === key && Date.now() - S.at < 60000) { draw(); return; }
		if (S.key !== key) { S.x = null; }
		S.err = '';
		if (active()) draw();
		try { const x = await post('cp_gcal_ev', { from, to, force: force ? 1 : 0 }); if (my !== S.req) return; S.x = x; S.key = key; S.at = Date.now(); }
		catch (e) { if (my !== S.req) return; S.err = e.message; }
		if (active()) { draw(); status(document.getElementById('cpx-status')); }
	}

	/* napra bontás (a több napos, egész napos bejegyzés minden napján megjelenik) */
	function byDay() {
		const m = {};
		((S.x && S.x.ev) || []).forEach(e => {
			const s = e.s.slice(0, 10), en = e.ad && e.e ? e.e.slice(0, 10) : s;
			for (let d = s, i = 0; d <= en && i < 40; d = addD(d, 1), i++) (m[d] = m[d] || []).push(e);
		});
		Object.keys(m).forEach(k => m[k].sort((a, b) => (a.ad === b.ad ? a.s.localeCompare(b.s) : (a.ad ? -1 : 1))));
		return m;
	}
	/* v1.71.1: a bejegyzés a Google Naptárban beállított saját színével (mint a telefonon); szín nélkül az alapszín */
	const GC = { 1: ['#7986CB', 'Levendula'], 2: ['#33B679', 'Zsálya'], 3: ['#8E24AA', 'Szőlő'], 4: ['#E67C73', 'Flamingó'], 5: ['#F6BF26', 'Banán'], 6: ['#F4511E', 'Mandarin'], 7: ['#039BE5', 'Páva'], 8: ['#616161', 'Grafit'], 9: ['#3F51B5', 'Áfonya'], 10: ['#0B8043', 'Bazsalikom'], 11: ['#D50000', 'Paradicsom'] };
	const colOf = e => (GC[e.col] || ['#4C7BD9', 'alapszín'])[0];
	const tint = c => c + '22';
	const sty = e => 'style="--gc:' + colOf(e) + ';background:' + tint(colOf(e)) + '"';
	const cls = e => 'gc-ev' + (e.pen ? ' pen' : '') + (e.deal ? '' : ' oth');
	const chip = (e, i) => '<button type="button" class="' + cls(e) + '" ' + sty(e) + ' data-ev="' + i + '" title="' + esc(e.t) + '">' + (!e.ad && hm(e.s) ? '<i>' + hm(e.s) + '</i>' : '') + '<span>' + esc(e.t) + '</span></button>';

	function draw() {
		const m = S.main;
		if (!m) return;
		const [y, mo] = S.month.split('-').map(Number);
		const v = curView();
		let h = '<div class="gc"><div class="gc-top"><div class="gc-nav"><button type="button" class="cpx-btn ghost" data-nav="-1" aria-label="Előző hónap">‹</button><h2>' + y + '. ' + HU_M[mo - 1] + '</h2><button type="button" class="cpx-btn ghost" data-nav="1" aria-label="Következő hónap">›</button><button type="button" class="cpx-btn ghost" data-nav="0">Ma</button></div>' +
			'<div class="gc-tools"><div class="gc-seg"><button type="button" data-v="month" class="' + (v === 'month' ? 'on' : '') + '">Hónap</button><button type="button" data-v="list" class="' + (v === 'list' ? 'on' : '') + '">Lista</button></div>' +
			'<button type="button" class="cpx-btn ghost" data-ref title="Frissítés a Google Naptárból">' + ico('refresh') + '<span>Frissítés</span></button></div></div>';
		if (S.x && S.x.ok === false) {
			const adm = C.ajax ? String(C.ajax).replace(/admin-ajax\.php.*$/, 'options-general.php?page=cp-gcal') : '';
			h += '<div class="gc-setup"><h3>A Google Naptár még nincs bekötve</h3><p>A panel az EventCrazy naptárat egy Google „szolgáltatásfiókkal” éri el. A bekötés egyszeri, kb. 10 perc.</p>' +
				(S.x.admin ? '<p><a class="cpx-btn" href="' + esc(adm) + '" target="_blank" rel="noopener">Bekötés a WordPressben →</a></p><p class="gc-sm">WordPress → Beállítások → Crazy Google Naptár: ott lépésről lépésre leírás van, a kulcsfájlt is ott kell feltölteni.</p>' : '<p class="gc-sm">Szólj az adminnak, ő tudja bekötni.</p>') +
				'<p class="gc-sm">Addig a rendezvények foglaltsága a <a href="#naptar" data-go="naptar">Kapacitás</a> fülön látszik.</p></div></div>';
			m.innerHTML = '<div class="gc">' + h.slice(h.indexOf('<div class="gc-setup">')); bind(); return;
		}
		if (!S.x) { m.innerHTML = h + (S.err ? '<div class="cpx-empty err">' + esc(S.err) + '<br><button class="cpx-btn" data-ref>Újra</button></div>' : '<div class="cpx-empty">Naptár betöltése…</div>') + '</div>'; bind(); return; }
		if (S.err) h += '<div class="gc-warn err">' + esc(S.err) + '</div>';
		const used = {}; (S.x.ev || []).forEach(e => { used[GC[e.col] ? e.col : 0] = (used[GC[e.col] ? e.col : 0] || 0) + 1; });
		h += '<div class="gc-leg">' + Object.keys(used).map(k => '<span><i style="background:' + (GC[k] ? GC[k][0] : '#4C7BD9') + '"></i>' + (GC[k] ? GC[k][1] : 'alapszín') + ' <small>(' + used[k] + ')</small></span>').join('') + '<span class="gc-sm">a színek a Google Naptárból · halványabb: nem dealhez kötött</span></div>';
		const miss = S.x.miss || [];
		if (miss.length) {
			h += '<div class="gc-warn"><button type="button" class="gc-mh" data-miss aria-expanded="' + (S.missOpen ? 'true' : 'false') + '">⚠️ <span class="gc-mt"><b>' + miss.length + ' szerződéses deal</b> rendezvénye nincs a naptárban ebben a nézetben</span><span class="gc-ml">' + (S.missOpen ? 'Elrejtés' : 'Megnézem') + '</span></button>' +
				(S.missOpen ? '<ul>' + miss.map(d => '<li><button type="button" data-deal="' + d.id + '"><b>' + esc(d.d.slice(5).replace('-', '.')) + '.</b> ' + esc(d.title) + ' <small>' + esc(d.stg) + '</small></button></li>').join('') + '</ul>' : '') + '</div>';
		}
		const bd = byDay(), today = S.x.today || todayS();
		const EV = (S.x.ev || []);
		const idx = e => EV.indexOf(e);
		if (v === 'month') {
			const [from, to] = range();
			h += '<div class="gc-grid">' + HU_DS.map(d => '<div class="gc-dow">' + d + '</div>').join('');
			for (let d = from; d <= to; d = addD(d, 1)) {
				const list = bd[d] || [], out = d.slice(0, 7) !== S.month, max = 4;
				h += '<div class="gc-day' + (out ? ' out' : '') + (d === today ? ' today' : '') + (d < today ? ' past' : '') + '" data-day="' + d + '"><div class="gc-dn">' + Number(d.slice(8)) + '</div>' +
					list.slice(0, max).map(e => chip(e, idx(e))).join('') + (list.length > max ? '<button type="button" class="gc-more" data-day="' + d + '">+' + (list.length - max) + ' további</button>' : '') + '</div>';
			}
			h += '</div>';
		} else {
			const days = Object.keys(bd).filter(d => d.slice(0, 7) === S.month).sort();
			if (!days.length) h += '<div class="cpx-empty">Ebben a hónapban nincs bejegyzés.</div>';
			h += '<div class="gc-list">' + days.map(d => '<section class="gc-ld' + (d === today ? ' today' : '') + (d < today ? ' past' : '') + '" id="gc-d-' + d + '"><h4>' + dayLabel(d) + (d === today ? ' <em>ma</em>' : '') + '</h4>' +
				bd[d].map(e => { const i = idx(e); return '<button type="button" class="gc-li ' + cls(e) + '" ' + sty(e) + ' data-ev="' + i + '"><span class="gc-tm">' + (e.ad ? 'egész nap' : esc(hm(e.s)) + (hm(e.e) ? '<b>–' + esc(hm(e.e)) + '</b>' : '')) + '</span><span class="gc-tt"><b>' + esc(e.t) + '</b>' +
					(e.loc ? '<small>📍 ' + esc(e.loc) + '</small>' : '') + (e.deal ? '<small>' + esc(e.stg) + (e.own ? ' · ' + esc(e.own) : '') + (e.g && e.g.length ? ' · 👤 ' + e.g.length + ' kezelő' : '') + '</small>' : '') + '</span></button>'; }).join('') + '</section>').join('') + '</div>';
		}
		h += '</div>';
		m.innerHTML = h;
		bind();
		if (v === 'list') {
			const f = S.focus || (S.month === today.slice(0, 7) ? today : '');
			S.focus = '';
			if (f) { let t = document.getElementById('gc-d-' + f); if (!t) { const n = Array.from(m.querySelectorAll('.gc-ld')).find(x => x.id.slice(5) >= f); t = n || null; } if (t) t.scrollIntoView({ block: 'start' }); }
		}
	}

	function bind() {
		const m = S.main;
		m.querySelectorAll('[data-nav]').forEach(b => b.addEventListener('click', () => {
			const n = Number(b.dataset.nav);
			if (!n) { S.month = todayS().slice(0, 7); S.focus = todayS(); }
			else { const d = D(S.month + '-01'); d.setMonth(d.getMonth() + n); S.month = ymd(d).slice(0, 7); }
			load(false);
		}));
		m.querySelectorAll('[data-v]').forEach(b => b.addEventListener('click', () => { S.view = b.dataset.v; try { localStorage.setItem('cpgc_view', S.view); } catch (e) { /* privát mód */ } draw(); }));
		m.querySelectorAll('[data-ref]').forEach(b => b.addEventListener('click', () => load(true)));
		m.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', e => { e.preventDefault(); go(b.dataset.go); }));
		m.querySelectorAll('[data-miss]').forEach(b => b.addEventListener('click', () => { S.missOpen = !S.missOpen; draw(); }));
		m.querySelectorAll('[data-deal]').forEach(b => b.addEventListener('click', () => openDeal(b.dataset.deal)));
		m.querySelectorAll('[data-ev]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); const x = (S.x.ev || [])[Number(b.dataset.ev)]; if (x) openEv(x); }));
		m.querySelectorAll('.gc-day[data-day], .gc-more').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); S.focus = b.dataset.day; if (b.dataset.day.slice(0, 7) !== S.month) { S.month = b.dataset.day.slice(0, 7); S.view = 'list'; load(false); return; } S.view = 'list'; draw(); }));
	}

	/* ------------------------------------------------------------ a bejegyzés adatlapja */
	function closeModal() { const x = document.getElementById('gc-mod'); if (x) x.remove(); document.removeEventListener('keydown', onKey); }
	function onKey(e) { if (e.key === 'Escape') closeModal(); }
	function openEv(e) {
		closeModal();
		const st = { accepted: '✅ elfogadta', declined: '❌ nem megy', tentative: '❔ talán', needsAction: '⏳ nem válaszolt' };
		const when = e.ad ? dayLabel(e.s) + (e.e && e.e.slice(0, 10) !== e.s.slice(0, 10) ? ' – ' + dayLabel(e.e) : '') + ' · egész nap' : dayLabel(e.s) + ' · ' + hm(e.s) + (hm(e.e) ? '–' + hm(e.e) : '');
		const w = document.createElement('div');
		w.id = 'gc-mod'; w.className = 'gc-mod';
		w.innerHTML = '<div class="gc-bg" data-x></div><div class="gc-card" role="dialog" aria-modal="true" aria-label="' + esc(e.t) + '"><header><span class="gc-dot" style="background:' + colOf(e) + '"></span><h3>' + esc(e.t) + '</h3><button type="button" class="gc-x" data-x aria-label="Bezárás">✕</button></header>' +
			'<div class="gc-body"><p class="gc-when">🗓 ' + esc(when) + '</p>' +
			(e.loc ? '<p>📍 <a href="https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(e.loc) + '" target="_blank" rel="noopener">' + esc(e.loc) + '</a></p>' : '') +
			(e.deal ? '<div class="gc-deal"><div><b>Deal:</b> ' + esc(e.stg) + (e.dst && e.dst !== 'Open' ? ' (' + esc(e.dst === 'Won' ? 'megnyert' : e.dst) + ')' : '') + (e.own ? ' · ' + esc(e.own) : '') + (e.pen ? ' · <span class="gc-pen">✏️ ceruzás</span>' : '') + '</div><button type="button" class="cpx-btn" data-deal="' + e.deal + '">Deal megnyitása</button></div>' : '<p class="gc-sm">Ehhez a bejegyzéshez a panel nem talált dealt (a kezelői adatlap linkje vagy a kontakt neve alapján keres).</p>') +
			(e.g && e.g.length ? '<div class="gc-g"><b>Kezelők, meghívottak</b><ul>' + e.g.map(g => '<li>' + esc(g.n || g.e) + ' <small>' + esc(st[g.st] || '') + '</small></li>').join('') + '</ul></div>' : '') +
			(e.desc ? '<div class="gc-desc">' + e.desc + '</div>' : '<p class="gc-sm">Nincs leírás.</p>') +
			(e.deal ? '<details class="gc-plan" data-plan="' + e.deal + '"><summary>Mit írna a panel ebbe a bejegyzésbe? (az átállás után)</summary><div class="gc-pb">Betöltés…</div></details>' : '') +
			'</div><footer>' + (e.link ? '<a class="cpx-btn ghost" href="' + esc(e.link) + '" target="_blank" rel="noopener">Megnyitás a Google Naptárban ↗</a>' : '') + '</footer></div>';
		document.body.appendChild(w);
		w.querySelectorAll('.gc-desc a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
		w.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', closeModal));
		w.querySelectorAll('[data-deal]').forEach(b => b.addEventListener('click', () => openDeal(b.dataset.deal)));
		const pl = w.querySelector('[data-plan]');
		if (pl) pl.addEventListener('toggle', async () => {
			if (!pl.open || pl.dataset.done) return;
			pl.dataset.done = '1';
			const box = pl.querySelector('.gc-pb');
			try { const p = await post('cp_gcal_plan', { id: pl.dataset.plan }); box.innerHTML = '<p><b>Cím:</b> ' + esc(p.title) + '</p><p><b>Időpont:</b> ' + esc(p.date) + (p.start ? ' ' + esc(p.start) + (p.end ? '–' + esc(p.end) : '') : ' (egész nap)') + '</p><div class="gc-desc">' + p.desc + '</div><p class="gc-sm">Ami nincs a dealben (pl. tematika, dress code, galérianév), azt az ügyfélportál adataiból egészíti majd ki.</p>'; box.querySelectorAll('a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; }); }
			catch (err) { box.textContent = err.message; }
		});
		document.addEventListener('keydown', onKey);
	}

	function status(s) {
		if (!s) return;
		if (S.err) { s.textContent = S.err; s.className = 'cpx-status err'; return; }
		if (!S.at) { s.textContent = 'Betöltés…'; s.className = 'cpx-status'; return; }
		const d = new Date(S.at); s.textContent = 'Naptár: ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); s.className = 'cpx-status';
	}
	function view(main) {
		S.main = main;
		draw();
		load(false);
		clearInterval(S.timer);
		S.timer = setInterval(() => { if (!active()) { clearInterval(S.timer); return; } if (!document.hidden && !document.getElementById('gc-mod')) load(true); }, 180000);
	}
	window.CPGcal = { view, refresh: () => load(true), status };

	const css = document.createElement('style');
	css.textContent =
		'.gc{max-width:1400px;margin:0 auto}' +
		'.gc-top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:10px}' +
		'.gc-nav{display:flex;align-items:center;gap:6px}.gc-nav h2{margin:0 6px;font:700 20px "Plus Jakarta Sans",Inter,sans-serif;min-width:150px;text-align:center}' +
		'.gc-tools{display:flex;gap:8px;align-items:center}' +
		'.gc-seg{display:inline-flex;border:1px solid #DDD9D2;border-radius:10px;overflow:hidden}.gc-seg button{border:0;background:#fff;padding:7px 14px;font:500 13px Inter,sans-serif;cursor:pointer;color:#4A5163}.gc-seg button.on{background:#141C33;color:#fff}' +
		'.gc-leg{display:flex;flex-wrap:wrap;gap:14px;font-size:12px;color:#6B6F78;margin:0 0 10px}.gc-leg i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:5px;vertical-align:-2px}' +
		'.gc-leg i.pen,.gc-dot.pen{background:#FFF1CC;border:1.5px dashed #D49A00}.gc-leg i.ok,.gc-dot.ok{background:#DDF3E4;border:1.5px solid #2E9D5B}.gc-leg i.oth,.gc-dot.oth{background:#EEF0F4;border:1.5px solid #A3A9B6}' +
		'.gc-warn{background:#FFF6E5;border:1px solid #F2D49B;border-radius:12px;margin:0 0 10px;padding:4px 6px}.gc-warn.err{background:#FDECEA;border-color:#F3B6AE;padding:10px 12px;color:#9B2C1F}' +
		'.gc-mh{display:flex;gap:6px;align-items:center;width:100%;border:0;background:transparent;padding:8px;font:500 14px Inter,sans-serif;text-align:left;cursor:pointer;color:#5B4300}.gc-mh .gc-mt{flex:1}.gc-mh .gc-ml{flex:none;color:#2F6FDB;font-weight:600}' +
		'.gc-warn ul{list-style:none;margin:0;padding:0 4px 6px}.gc-warn li button{width:100%;text-align:left;border:0;background:#fff;border-radius:8px;margin:3px 0;padding:8px 10px;font:14px Inter,sans-serif;cursor:pointer}.gc-warn li small{color:#8A6D1F;margin-left:6px}' +
		'.gc-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border:1px solid #E6E3DE;border-radius:14px;overflow:hidden;background:#E6E3DE;gap:1px}' +
		'.gc-dow{background:#F6F4F0;padding:6px 8px;font:600 12px Inter,sans-serif;color:#6B6F78;text-align:center}' +
		'.gc-day{background:#fff;min-height:118px;padding:4px 4px 6px;display:flex;flex-direction:column;gap:3px;cursor:pointer;min-width:0}.gc-day.out{background:#FAF9F7}.gc-day.out .gc-dn{color:#B5B8BF}.gc-day.past .gc-ev{opacity:.62}' +
		'.gc-dn{font:600 12.5px Inter,sans-serif;color:#4A5163;padding:2px 4px}.gc-day.today .gc-dn{display:inline-block;align-self:flex-start;background:#2F6FDB;color:#fff;border-radius:20px;padding:2px 8px}' +
		'.gc-ev{display:flex;gap:4px;align-items:baseline;width:100%;min-width:0;border-radius:6px;padding:3px 6px;font:500 12px/1.3 Inter,sans-serif;text-align:left;cursor:pointer;border:1px solid transparent;color:#141C33}' +
		'.gc-ev span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.gc-ev i{font-style:normal;font-weight:700;flex:none;color:#4A5163}' +
		'.gc-ev{border-left:4px solid var(--gc,#4C7BD9)!important}.gc-ev.pen{border-style:dashed}.gc-ev.oth{opacity:.72}' +
		'.gc-more{border:0;background:transparent;color:#2F6FDB;font:600 11.5px Inter,sans-serif;text-align:left;padding:1px 6px;cursor:pointer}' +
		'.gc-list{display:flex;flex-direction:column;gap:14px}.gc-ld h4{margin:0 0 6px;font:700 14px Inter,sans-serif;color:#141C33}.gc-ld h4 em{font-style:normal;background:#2F6FDB;color:#fff;border-radius:20px;padding:1px 8px;font-size:11.5px;margin-left:6px;text-transform:none}.gc-ld.past{opacity:.7}' +
		'.gc-li{display:flex;gap:12px;align-items:flex-start;padding:10px 12px;margin:0 0 6px;border-radius:12px;font-size:14px!important;line-height:1.35!important}.gc-li span{white-space:normal;overflow:visible}.gc-li .gc-tm{flex:none;width:86px;font-weight:700;color:#4A5163;font-size:13px}.gc-tt{display:flex;flex-direction:column;gap:2px;min-width:0}.gc-tt b{font-weight:600;overflow-wrap:anywhere}.gc-tt small{color:#5D6270;font-size:12.5px;overflow-wrap:anywhere}' +
		'.gc-setup{background:#fff;border:1px solid #E6E3DE;border-radius:16px;padding:22px;max-width:640px}.gc-setup h3{margin:0 0 8px}.gc-sm{color:#6B6F78;font-size:13px}' +
		'.gc-mod{position:fixed;inset:0;z-index:60;display:flex;align-items:center;justify-content:center}.gc-bg{position:absolute;inset:0;background:rgba(20,24,33,.45)}' +
		'.gc-card{position:relative;background:#fff;border-radius:18px;width:min(640px,calc(100vw - 24px));max-height:calc(100vh - 40px);display:flex;flex-direction:column;box-shadow:0 24px 48px -16px rgba(0,0,0,.45)}' +
		'.gc-card header{display:flex;gap:10px;align-items:center;padding:16px 18px 10px;border-bottom:1px solid #EEEAE4}.gc-card h3{margin:0;font:700 17px Inter,sans-serif;flex:1;overflow-wrap:anywhere}.gc-dot{width:14px;height:14px;border-radius:4px;flex:none}' +
		'.gc-x{border:0;background:#F3F1ED;border-radius:50%;width:34px;height:34px;font-size:15px;cursor:pointer;flex:none}' +
		'.gc-body{padding:12px 18px;overflow:auto;font-size:14px;line-height:1.5}.gc-body p{margin:0 0 8px}.gc-when{font-weight:600;text-transform:none}' +
		'.gc-deal{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;background:#F6F4F0;border-radius:12px;padding:10px 12px;margin:6px 0 10px}.gc-pen{color:#8A6400;font-weight:600}' +
		'.gc-g ul{margin:4px 0 10px;padding-left:18px}.gc-g small{color:#6B6F78}' +
		'.gc-desc{border-top:1px solid #EEEAE4;padding-top:10px;overflow-wrap:anywhere}.gc-desc a{color:#2F6FDB}' +
		'.gc-plan{margin-top:12px;border:1px dashed #C9C4BB;border-radius:12px;padding:8px 12px}.gc-plan summary{cursor:pointer;font-weight:600;color:#4A5163}.gc-pb{padding-top:8px}' +
		'.gc-card footer{padding:10px 18px 16px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #EEEAE4}' +
		'@media (max-width:760px){.gc-nav h2{font-size:17px;min-width:0}.gc-top{gap:8px}.gc-tools .cpx-btn span{display:none}' +
		'.gc-day{min-height:64px;padding:2px}.gc-ev{padding:1px 3px;font-size:10px}.gc-ev i{display:none}.gc-dn{font-size:11.5px;padding:1px 2px}.gc-more{font-size:10px;padding:0 2px}' +
		'.gc-mod{align-items:flex-end}.gc-card{width:100vw;max-height:88vh;border-radius:18px 18px 0 0;padding-bottom:env(safe-area-inset-bottom)}.gc-li .gc-tm{width:46px}.gc-li .gc-tm b{display:none}}';
	document.head.appendChild(css);
})();
