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
			'<div class="gc-tools"><div class="gc-seg"><button type="button" data-v="month" class="' + (v === 'month' ? 'on' : '') + '">Hónap</button><button type="button" data-v="list" class="' + (v === 'list' ? 'on' : '') + '">Lista</button><button type="button" data-v="diff" class="' + (v === 'diff' ? 'on' : '') + '" title="Eltérések a naptár és a panel között, mától az év végéig">Eltérések</button><button type="button" data-v="hist" class="' + (v === 'hist' ? 'on' : '') + '" title="A bejegyzések mentett változatai, visszaállítás">Előzmények</button></div>' +
			'<button type="button" class="cpx-btn ghost" data-ref title="Frissítés a Google Naptárból">' + ico('refresh') + '<span>Frissítés</span></button></div></div>';
		if (v === 'diff') { drawDiff(m, h); return; }
		if (v === 'hist') { drawHist(m, h); return; }
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
		h += '<div class="gc-leg">' + Object.keys(used).map(k => '<span><i style="background:' + (GC[k] ? GC[k][0] : '#4C7BD9') + '"></i>' + (GC[k] ? GC[k][1] : 'alapszín') + ' <small>(' + used[k] + ')</small></span>').join('') + '<span class="gc-sm">a színek a Google Naptárból · szaggatott keret: [Ceruzás] · halványabb: nem dealhez kötött</span></div>';
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
					(e.loc ? '<small>📍 ' + esc(e.loc) + '</small>' : '') + (e.deal ? '<small>' + (e.dof ? '<span class="dtag">' + e.dn + '/' + e.dof + '. nap</span> ' : '') /* v2.9.71 */ + esc(e.stg) + (e.own ? ' · ' + esc(e.own) : '') + (e.g && e.g.length ? ' · 👤 ' + e.g.length + ' kezelő' : '') + '</small>' : '') + '</span></button>'; }).join('') + '</section>').join('') + '</div>';
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
			(e.deal ? '<details class="gc-plan" data-plan="' + e.deal + '" data-pday="' + (e.dof ? esc(String(e.s).slice(0, 10)) : '') + '"><summary>Mit írna a panel ebbe a bejegyzésbe? (az átállás után)</summary><div class="gc-pb">Betöltés…</div></details>' : '') +
			'</div><footer><button type="button" class="cpx-btn ghost" data-hist>Előzmények</button>' + (e.link ? '<a class="cpx-btn ghost" href="' + esc(e.link) + '" target="_blank" rel="noopener">Megnyitás a Google Naptárban ↗</a>' : '') + '</footer></div>';
		document.body.appendChild(w);
		w.querySelectorAll('.gc-desc a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
		w.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', closeModal));
		w.querySelectorAll('[data-deal]').forEach(b => b.addEventListener('click', () => openDeal(b.dataset.deal)));
		w.querySelectorAll('[data-hist]').forEach(b => b.addEventListener('click', () => openHist(e.id, e.t)));
		const pl = w.querySelector('[data-plan]');
		if (pl) pl.addEventListener('toggle', async () => {
			if (!pl.open || pl.dataset.done) return;
			pl.dataset.done = '1';
			const box = pl.querySelector('.gc-pb');
			try { const p = await post('cp_gcal_plan', { id: pl.dataset.plan, day: pl.dataset.pday || '' }); box.innerHTML = '<p><b>Cím:</b> ' + esc(p.title) + '</p><p><b>Időpont:</b> ' + esc(p.date) + (p.start ? ' ' + esc(p.start) + (p.end ? '–' + esc(p.end) : '') : ' (egész nap)') + '</p><div class="gc-desc">' + p.desc + '</div><p class="gc-sm">Ami nincs a dealben (pl. tematika, dress code, galérianév), azt az ügyfélportál adataiból egészíti majd ki.</p>'; box.querySelectorAll('a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; }); }
			catch (err) { box.textContent = err.message; }
		});
		document.addEventListener('keydown', onKey);
	}


	/* ------------------------------------------------------------ v2.9.384: változatok (mentés minden módosítás után) és visszaállítás */
	const fts = ts => { if (!ts) return ''; const d = new Date(ts * 1000), y = d.getFullYear() !== new Date().getFullYear(); return (y ? d.getFullYear() + '. ' : '') + pad(d.getMonth() + 1) + '.' + pad(d.getDate()) + '. ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); };
	const unpen = t => String(t || '').replace(/^\s*(\[ceruzás\]|✏️?)\s*/i, '').trim();
	const vWhen = v => (v.ad ? String(v.s).slice(0, 10).replace(/-/g, '.') + '. egész nap' + (v.e && v.e.slice(0, 10) !== v.s.slice(0, 10) ? ' – ' + v.e.slice(0, 10).replace(/-/g, '.') + '.' : '') : String(v.s).slice(0, 10).replace(/-/g, '.') + '. ' + hm(v.s) + (hm(v.e) ? '–' + hm(v.e) : ''));
	const SRC = { 'kézi módosítás': 'man', 'panel írta': 'pan', 'első mentés': 'first', 'visszaállítva': 'rest', 'tömeges visszaállítás': 'rest', 'visszaállítás előtt': 'first', 'törölve a naptárból': 'del', 'panel törölte': 'del', 'újra létrehozva': 'rest' };
	const srcChip = v => '<span class="gv-src ' + (SRC[v.src] || 'pan') + '">' + esc(v.src) + '</span>' + (v.who ? ' <small class="gv-who">' + esc(v.who) + '</small>' : '');
	function vChanges(v, o) {
		if (!o) return [];
		const c = [];
		if (v.del !== o.del) return [v.del ? 'törölve' : 'újra megvan'];
		if (unpen(v.t) !== unpen(o.t)) c.push('cím');
		if (v.s !== o.s || v.e !== o.e || v.ad !== o.ad) c.push('időpont');
		if ((v.loc || '').trim() !== (o.loc || '').trim()) c.push('helyszín');
		if ((v.lines || []).join('\n') !== (o.lines || []).join('\n')) c.push('leírás');
		return c;
	}
	function vBody(v, o) {
		const cur = v.lines || [], old = o ? (o.lines || []) : null;
		const oset = new Set(old || []), nset = new Set(cur);
		const rows = cur.map(l => '<li' + (old && !oset.has(l) ? ' class="add"' : '') + '>' + esc(l) + '</li>').concat(old ? old.filter(l => !nset.has(l)).map(l => '<li class="rm">' + esc(l) + '</li>') : []);
		return '<p><b>Cím:</b> ' + esc(v.t) + '</p><p><b>Időpont:</b> ' + esc(vWhen(v)) + '</p>' + (v.loc ? '<p><b>Helyszín:</b> ' + esc(v.loc) + '</p>' : '') +
			(rows.length ? '<ul class="gv-lines">' + rows.join('') + '</ul>' + (old ? '<p class="gc-sm"><span class="gv-lg add">zöld: új sor</span> <span class="gv-lg rm">áthúzott: törölt sor</span> (az előző változathoz képest)</p>' : '') : '<p class="gc-sm">Nincs leírás.</p>');
	}
	async function openHist(eid, title) {
		closeModal();
		const w = document.createElement('div');
		w.id = 'gc-mod'; w.className = 'gc-mod';
		w.innerHTML = '<div class="gc-bg" data-x></div><div class="gc-card" role="dialog" aria-modal="true" aria-label="Előzmények"><header><h3>Előzmények: ' + esc(title || '') + '</h3><button type="button" class="gc-x" data-x aria-label="Bezárás">✕</button></header><div class="gc-body"><p class="gc-sm">Betöltés…</p></div><footer></footer></div>';
		document.body.appendChild(w);
		w.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', closeModal));
		document.addEventListener('keydown', onKey);
		const body = w.querySelector('.gc-body');
		let x;
		try { x = await post('cp_gv_hist', { eid }); } catch (err) { body.innerHTML = '<p class="cpx-empty err">' + esc(err.message) + '</p>'; return; }
		const V = x.v || [];
		if (!V.length) { body.innerHTML = '<p class="gc-sm">Ehhez a bejegyzéshez még nincs mentett változat. A panel 5 percenként menti a változásokat.</p>'; return; }
		body.innerHTML = '<p class="gc-sm">A panel minden módosítás után ment (a Google Naptárban kézzel végzett módosítást legfeljebb 5 percen belül). Bejegyzésenként az utolsó ' + x.keep + ' változat marad meg, ' + x.days + ' napig. A legfelső a mostani állapot.</p>' +
			'<ol class="gv-list">' + V.map((v, i) => { const o = V[i + 1], ch = vChanges(v, o);
				return '<li class="gv-it' + (v.del ? ' del' : '') + '"><details' + (i === 0 ? ' open' : '') + '><summary><span class="gv-at">' + esc(fts(v.at)) + '</span>' + srcChip(v) + (i === 0 ? ' <span class="gv-now">' + (v.del ? 'törölve' : 'mostani') + '</span>' : '') +
					(ch.length ? '<span class="gv-ch">változott: ' + esc(ch.join(', ')) + '</span>' : '') + '</summary><div class="gv-b">' + (v.gup && v.src === 'kézi módosítás' ? '<p class="gc-sm">A Google Naptár szerint módosítva: ' + esc(fts(v.gup)) + '</p>' : '') + vBody(v, o) +
					(x.can && !v.del && (i > 0 || V[0].del) ? '<p><button type="button" class="cpx-btn" data-rv="' + v.id + '">Visszaállítás erre a változatra</button></p>' : '') + '</div></details></li>'; }).join('') + '</ol>' +
			(x.can ? '' : '<p class="gc-sm">Visszaállítani az admin és az tud, akinek a „Naptár visszaállítása” engedélyt megadták.</p>');
		body.querySelectorAll('[data-rv]').forEach(b => b.addEventListener('click', async () => {
			const v = V.find(z => String(z.id) === b.dataset.rv);
			if (!v || !window.confirm('A bejegyzés visszaáll a ' + fts(v.at) + '-i állapotra (cím, leírás, időpont, helyszín). A mostani állapot is megmarad az előzmények között. Mehet?')) return;
			b.disabled = true; b.textContent = 'Visszaállítás…';
			try { await post('cp_gv_restore', { vid: v.id }); closeModal(); load(true); if (curView() === 'hist') { S.hist = null; draw(); } openHist(eid, title); }
			catch (err) { window.alert(err.message); b.disabled = false; b.textContent = 'Visszaállítás erre a változatra'; }
		}));
	}
	async function loadHist() {
		S.hErr = '';
		try { S.hist = await post('cp_gv_feed', {}); } catch (e) { S.hErr = e.message; S.hist = null; }
		if (active() && curView() === 'hist') draw();
	}
	function drawHist(m, h) {
		const H = S.hist;
		if (!H && !S.hErr) { loadHist(); m.innerHTML = h + '<div class="cpx-empty">Előzmények betöltése…</div></div>'; bind(); return; }
		if (!H) { m.innerHTML = h + '<div class="cpx-empty err">' + esc(S.hErr) + '</div></div>'; bind(); return; }
		const now = new Date(Date.now() - 3600000), dv = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
		h += '<div class="gv">' + '<p class="gc-dinfo">A panel minden naptárbejegyzésről mentést készít, amikor az módosul (kézzel a Google Naptárban vagy a panel által). Mentés ' + (H.since ? esc(fts(H.since)) + ' óta' : 'még nincs') + ', ' + H.n + ' bejegyzésről. Egy bejegyzés változatait az adatlapján vagy lent az „Előzmények” gombbal nézheted meg.</p>' +
			(H.can ? '<div class="gv-mass"><b>Tömeges visszaállítás</b><span>Minden bejegyzés, amely az alábbi időpont óta módosult, visszaáll az akkori állapotára. Előbb előnézetet kapsz, és kiválaszthatod, melyik álljon vissza.</span><label>Időpont <input type="datetime-local" data-mts value="' + esc(S.mts || dv) + '"></label><button type="button" class="cpx-btn ghost" data-mprev>Előnézet</button></div>' +
				(S.mass ? '<div class="gv-mp">' + (S.mass.length ? '<p><b>' + S.mass.length + ' bejegyzés</b> állna vissza:</p><ul>' + S.mass.map(x => '<li><label><input type="checkbox" data-mv="' + x.vid + '"' + (S.mSel[x.vid] !== false ? ' checked' : '') + '> <span><b>' + esc(String(x.s).slice(5, 10).replace('-', '.')) + '.</b> ' + esc(x.t) + '<small>vissza erre: ' + esc(fts(x.tat)) + ' · utolsó változás: ' + esc(fts(x.cat)) + ', ' + esc(x.cdel ? 'törölve' : x.csrc) + (x.cwho ? ' (' + esc(x.cwho) + ')' : '') + '</small></span></label></li>').join('') + '</ul><button type="button" class="cpx-btn" data-mgo>Kijelöltek visszaállítása (' + S.mass.filter(x => S.mSel[x.vid] !== false).length + ')</button>' : '<p>Ez óta az időpont óta egyik bejegyzés sem változott (vagy nincs korábbi mentés róla).</p>') + '</div>' : '') : '') +
			'<h3 class="gv-h">Legutóbbi változások</h3>' + ((H.v || []).length ? '<div class="gv-feed">' + H.v.map(v => '<div class="gv-fr"><span class="gv-at">' + esc(fts(v.at)) + '</span><span class="gv-ft"><b>' + esc(v.t) + '</b><small>' + esc(vWhen(v)) + '</small></span><span>' + srcChip(v) + '</span><button type="button" class="cpx-btn ghost" data-fh="' + esc(v.eid) + '" data-ft="' + esc(v.t) + '">Előzmények</button></div>').join('') + '</div>' : '<div class="cpx-empty">Még nem volt változás a mentés indulása óta.</div>') + '</div>';
		m.innerHTML = h + '</div>'; bind();
		m.querySelectorAll('[data-fh]').forEach(b => b.addEventListener('click', () => openHist(b.dataset.fh, b.dataset.ft)));
		const ts = m.querySelector('[data-mts]'); if (ts) ts.addEventListener('change', () => { S.mts = ts.value; S.mass = null; draw(); });
		const mp = m.querySelector('[data-mprev]');
		if (mp) mp.addEventListener('click', async () => {
			S.mts = ts.value; const t = Math.floor(new Date(ts.value).getTime() / 1000); if (!t) return;
			mp.disabled = true; mp.textContent = 'Keresés…';
			try { S.mass = (await post('cp_gv_mass', { ts: t })).list; S.mSel = {}; } catch (e) { window.alert(e.message); }
			draw();
		});
		m.querySelectorAll('[data-mv]').forEach(c => c.addEventListener('change', () => { S.mSel[c.dataset.mv] = c.checked; draw(); }));
		const mg = m.querySelector('[data-mgo]');
		if (mg) mg.addEventListener('click', async () => {
			const vids = S.mass.filter(x => S.mSel[x.vid] !== false).map(x => x.vid);
			if (!vids.length || !window.confirm(vids.length + ' naptárbejegyzés visszaáll a ' + S.mts.replace('T', ' ') + ' előtti állapotára. A mostani állapotuk is megmarad az előzmények között. Mehet?')) return;
			mg.disabled = true; mg.textContent = 'Visszaállítás…';
			try { const r = await post('cp_gv_mass', { ts: Math.floor(new Date(S.mts).getTime() / 1000), go: 1, vids: JSON.stringify(vids) }); window.alert(r.ok + ' bejegyzés visszaállítva.' + (r.err.length ? '\n\nNem sikerült:\n' + r.err.join('\n') : '')); }
			catch (e) { window.alert(e.message); }
			S.mass = null; S.hist = null; load(true); draw();
		});
	}
	S.mSel = {};

	function status(s) {
		if (!s) return;
		if (S.err) { s.textContent = S.err; s.className = 'cpx-status err'; return; }
		if (!S.at) { s.textContent = 'Betöltés…'; s.className = 'cpx-status'; return; }
		const d = new Date(S.at); s.textContent = 'Naptár: ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); s.className = 'cpx-status';
	}
	/* v2.9.323: eltérések a naptár és a panel között (csak lista – semmit nem ír át) */
	const DK = { time: 'Kezdés', info: 'Rendezvényinfó', hours: 'Rendelkezésre állás', loc: 'Helyszín', contact: 'Kontakt', other: 'Egyéb adat', op: 'Kezelő', date: 'Dátum', lost: 'Elveszett / törölt deal', nodeal: 'Nincs hozzá deal', noev: 'Nincs naptárbejegyzés' };
	S.sel = S.sel || {};
	async function loadDiff(force) {
		S.dErr = ''; S.dBusy = true;
		try { S.diff = await post('cp_gcal_diff', { to: new Date().getFullYear() + '-12-31', force: force ? 1 : 0 }); } catch (e) { S.dErr = e.message; }
		S.dBusy = false; if (active()) draw();
	}
	function drawDiff(m, h) {
		const D = S.diff;
		if (!D && !S.dBusy && !S.dErr) loadDiff(false);
		if (!D) { m.innerHTML = h + (S.dErr ? '<div class="cpx-empty err">' + esc(S.dErr) + '<br><button class="cpx-btn" data-dref>Újra</button></div>' : '<div class="cpx-empty">Eltérések keresése a naptárban…</div>') + '</div>'; bind(); bindDiff(m); return; }
		const cnt = {}; D.rows.forEach(r => r.it.forEach(i => { cnt[i.k] = (cnt[i.k] || 0) + 1; }));
		const f = S.dF || '';
		const rows = D.rows.filter(r => !f || r.it.some(i => i.k === f));
		const fd = s => { const p = s.split('-'); return p[1] + '.' + p[2] + '.'; };
		h += '<div class="gc-diff"><p class="gc-dinfo">A naptár (' + D.ev + ' bejegyzés) és a panel összevetése <b>' + fd(D.from) + ' – ' + fd(D.to) + '</b> között. Magától semmit nem ír át: csak a kipipált tételek kerülnek át. A deal nevére kattintva megnyílik az adatlap, ott javíthatod.</p>' +
			'<div class="gc-dch"><button type="button" data-df="" class="' + (!f ? 'on' : '') + '">Mind <b>' + D.rows.length + '</b></button>' + Object.keys(DK).filter(k => cnt[k]).map(k => '<button type="button" data-df="' + k + '" class="' + (f === k ? 'on' : '') + '">' + DK[k] + ' <b>' + cnt[k] + '</b></button>').join('') + '<button type="button" class="cpx-btn ghost" data-dref>Újra</button></div>' +
			(() => { const imp = []; D.rows.forEach(r => r.it.forEach(i => { if (i.f && r.deal) imp.push(r.deal + '|' + i.f); })); const n = Object.keys(S.sel).filter(k => S.sel[k] && imp.includes(k)).length;
				return imp.length ? '<div class="gc-imp"><span><b>' + imp.length + '</b> tétel átvehető a naptárból (kezdés, fizetési mód, összeg az üres deal-értékbe, létszám, rendelkezésre állás, rendezvényinfó). Pipáld ki, amit átviszel – a kezdés, a fizetési mód, az összeg, a létszám és a rendelkezésre állás a Salesmate-be is bekerül, a rendezvényinfó csak a panelbe.</span><button type="button" class="cpx-btn ghost" data-impall>Mind kijelölése</button><button type="button" class="cpx-btn" data-impgo' + (n ? '' : ' disabled') + '>Kijelöltek átvétele (' + n + ')</button></div>' : ''; })() +
			(rows.length ? '<div class="gc-dt"><div class="gc-dr gc-dhd"><span>Dátum</span><span>Naptárbejegyzés</span><span>Deal a panelben</span><span>Eltérés (naptár → panel)</span></div>' + rows.map(r =>
				'<div class="gc-dr"><span class="gc-dd">' + esc(fd(r.day)) + '</span><span>' + (r.ev ? (r.link ? '<a href="' + esc(r.link) + '" target="_blank" rel="noopener">' + esc(r.ev) + ' ↗</a>' : esc(r.ev)) : '<i class="gc-mu">– nincs –</i>') + '</span>' +
				'<span>' + (r.deal ? '<a href="#deals:' + r.deal + '" data-pdeal="' + r.deal + '">' + esc(r.dt) + '</a>' + (r.stg ? '<small>' + esc(r.stg) + '</small>' : '') : '<i class="gc-mu">– nincs –</i>') + '</span>' +
				'<span class="gc-dit">' + r.it.map(i => { const id = i.f ? r.deal + '|' + i.f : ''; return '<span class="gc-di ' + i.k + (i.f ? ' imp' : '') + '">' + (i.f && r.deal ? '<label class="gc-ck" title="Átvétel a dealre"><input type="checkbox" data-imp="' + esc(id) + '"' + (S.sel[id] ? ' checked' : '') + '></label>' : '') + '<span><b>' + esc(i.m) + '</b>' + (i.cal !== undefined ? ' <em>' + esc(i.cal) + ' → ' + esc(i.pan) + '</em>' : '') + '</span></span>'; }).join('') + '</span></div>').join('') + '</div>'
				: '<div class="cpx-empty">Ebben a csoportban nincs eltérés.</div>') + '</div>';
		m.innerHTML = h + '</div>'; bind(); bindDiff(m);
		/* v2.9.386: az átvételi sáv lebeg (követi a görgetést), a panel ragadós fejléce alatt */
		/* v2.9.387: csak a vízszintes (felső) fejléc magassága számít – az oldalsávos elrendezésben a .cpx-top a teljes magas oldalsáv, az eddig a képernyő alá tolta a sávot */
		const tp = document.querySelector('.cpx-top'), tr = tp ? tp.getBoundingClientRect() : null; const hh = tr && getComputedStyle(tp).position === 'sticky' && tr.width > window.innerWidth / 2 && tr.height < 200 ? tr.height : 0; m.style.setProperty('--gc-st', (hh + 8) + 'px');
	}
	function bindDiff(m) {
		m.querySelectorAll('[data-df]').forEach(b => b.addEventListener('click', () => { S.dF = b.dataset.df; draw(); }));
		m.querySelectorAll('[data-dref]').forEach(b => b.addEventListener('click', () => { S.diff = null; loadDiff(true); draw(); }));
		m.querySelectorAll('[data-imp]').forEach(c => c.addEventListener('change', () => { S.sel[c.dataset.imp] = c.checked; draw(); }));
		const ia = m.querySelector('[data-impall]'); if (ia) ia.addEventListener('click', () => { const f = S.dF || ''; S.diff.rows.forEach(r => { if (f && !r.it.some(i => i.k === f)) return; r.it.forEach(i => { if (i.f && r.deal) S.sel[r.deal + '|' + i.f] = true; }); }); draw(); });
		const ig = m.querySelector('[data-impgo]');
		if (ig) ig.addEventListener('click', async () => {
			const items = []; S.diff.rows.forEach(r => r.it.forEach(i => { if (i.f && r.deal && S.sel[r.deal + '|' + i.f]) items.push({ deal: r.deal, f: i.f, v: i.v }); }));
			if (!items.length) return;
			const nStart = items.filter(x => x.f === 'start').length;
			if (!window.confirm(items.length + ' tétel átvétele a naptárból' + (nStart ? ' (ebből ' + nStart + ' kezdési idő a Salesmate-be is bekerül)' : '') + '. Mehet?')) return;
			ig.disabled = true; ig.textContent = 'Átvétel…';
			try { const r = await post('cp_gcal_import', { items: JSON.stringify(items) }); S.sel = {}; window.alert(r.ok + ' tétel átvéve.' + (r.err.length ? '\n\nNem sikerült:\n' + r.err.join('\n') : '')); }
			catch (e) { window.alert(e.message); }
			S.diff = null; loadDiff(true); draw();
		});
	}
	function view(main) {
		S.main = main;
		draw();
		load(false);
		clearInterval(S.timer);
		S.timer = setInterval(() => { if (!active()) { clearInterval(S.timer); return; } if (!document.hidden && !document.getElementById('gc-mod')) load(false); }, 180000); /* v2.9.58: az automatikus frissítés a szerver gyorsítótárát használja – kényszerített Google-hívás csak a Frissítés gombra */
	}
	window.CPGcal = { view, refresh: () => load(true), status };

	const css = document.createElement('style');
	css.textContent =
		'.gc{max-width:1400px;margin:0 auto}' +
		'.gc-dinfo{margin:0 0 10px;color:#5F5B55;font-size:13.5px}.gc-dch{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;align-items:center}.gc-dch button[data-df]{border:1px solid #DDD9D2;background:#fff;border-radius:999px;padding:6px 12px;font:500 13px Inter,sans-serif;cursor:pointer;color:#1F1D1A}.gc-dch button[data-df].on{background:#141C33;border-color:#141C33;color:#fff}.gc-dch b{margin-left:4px;font-variant-numeric:tabular-nums}' +
		'.gc-dt{background:#fff;border:1px solid #E6E2DC;border-radius:14px;overflow:hidden}.gc-dr{display:grid;grid-template-columns:70px minmax(0,1.2fr) minmax(0,1.2fr) minmax(0,1.6fr);gap:12px;padding:10px 14px;border-top:1px solid #F0ECE6;font-size:13.5px;align-items:start}.gc-dr:first-child{border-top:0}.gc-dhd{background:#F7F5F1;font-weight:700;font-size:12px;color:#5F5B55;text-transform:uppercase;letter-spacing:.03em}' +
		'.gc-dr a{color:#141C33;font-weight:600;text-decoration:none;overflow-wrap:anywhere}.gc-dr a:hover{text-decoration:underline}.gc-dr small{display:block;color:#8A857D;font-size:12px}.gc-dd{font-weight:700;font-variant-numeric:tabular-nums}.gc-mu{color:#A09A91}' +
		'.gc-imp{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;background:#EEF2FA;border:1px solid #D3DCEF;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:13.5p;position:sticky;top:var(--gc-st,8px);z-index:15;box-shadow:0 6px 16px -10px rgba(20,28,51,.35)}@media (max-width:760px){.gc-imp{font-size:12.5px;padding:8px 10px}}.gc-imp span{flex:1 1 320px}.gc-di.imp{display:flex;gap:8px;align-items:flex-start}.gc-ck input{width:18px;height:18px;margin:1px 0 0;accent-color:#141C33;cursor:pointer}.gc-di.info{background:#EAF5EE}' + '.gc-dit{display:flex;flex-direction:column;gap:4px}.gc-di{border-radius:8px;padding:4px 8px;background:#F1EEE9}.gc-di b{font-weight:600}.gc-di em{font-style:normal;color:#5F5B55;overflow-wrap:anywhere}.gc-di.nodeal,.gc-di.lost,.gc-di.noev{background:#FDECEA;color:#8C1D18}.gc-di.date{background:#FFF3C4}' +
		'@media (max-width:760px){.gc-dhd{display:none}.gc-dr{grid-template-columns:1fr;gap:4px}}' +
		'.gv{max-width:1000px}.gv-h{font:700 15px Inter,sans-serif;margin:16px 0 8px}.gv-mass{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;background:#EEF2FA;border:1px solid #D3DCEF;border-radius:12px;padding:10px 14px;font-size:13.5px}.gv-mass span{flex:1 1 320px}.gv-mass input{font:14px Inter,sans-serif;padding:5px 8px;border:1px solid #C9CFDC;border-radius:8px}' +
		'.gv-mp{background:#fff;border:1px solid #E6E2DC;border-radius:12px;padding:10px 14px;margin-top:8px;font-size:13.5px}.gv-mp ul{list-style:none;margin:0 0 10px;padding:0;max-height:420px;overflow:auto}.gv-mp li label{display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-top:1px solid #F0ECE6;cursor:pointer}.gv-mp li span{min-width:0;overflow-wrap:anywhere}.gv-mp small{display:block;color:#8A857D;font-size:12px}.gv-mp input{width:18px;height:18px;flex:none;accent-color:#141C33}' +
		'.gv-feed{background:#fff;border:1px solid #E6E2DC;border-radius:14px;overflow:hidden}.gv-fr{display:grid;grid-template-columns:110px minmax(0,1fr) auto auto;gap:12px;align-items:center;padding:8px 14px;border-top:1px solid #F0ECE6;font-size:13.5px}.gv-fr:first-child{border-top:0}.gv-ft{min-width:0}.gv-ft b{font-weight:600;overflow-wrap:anywhere}.gv-ft small{display:block;color:#8A857D;font-size:12px}' +
		'.gv-at{font-variant-numeric:tabular-nums;font-weight:600;color:#4A5163;white-space:nowrap}.gv-src{display:inline-block;border-radius:999px;padding:1px 8px;font-size:12px;font-weight:600;white-space:nowrap;background:#EEF0F4;color:#4A5163}.gv-src.man{background:#FFF1CC;color:#7A5600}.gv-src.pan{background:#E3ECFB;color:#1F4E9E}.gv-src.rest{background:#DDF3E4;color:#1D6B3C}.gv-src.del{background:#FDECEA;color:#9B2C1F}.gv-who{color:#8A857D}' +
		'.gv-list{list-style:none;margin:8px 0 0;padding:0}.gv-it{border:1px solid #EEEAE4;border-radius:12px;margin:0 0 8px}.gv-it summary{display:flex;flex-wrap:wrap;gap:6px 8px;align-items:center;padding:8px 12px;cursor:pointer}.gv-now{background:#141C33;color:#fff;border-radius:999px;padding:1px 8px;font-size:11.5px}.gv-ch{flex-basis:100%;color:#656973;font-size:12.5px}.gv-b{padding:0 12px 10px}' +
		'.gv-lines{margin:6px 0;padding-left:18px;overflow-wrap:anywhere}.gv-lines li.add,.gv-lg.add{background:#DDF3E4}.gv-lines li.rm,.gv-lg.rm{background:#FDECEA;text-decoration:line-through;color:#9B2C1F}.gv-lg{padding:0 4px;border-radius:4px}' +
		'@media (max-width:760px){.gv-fr{grid-template-columns:1fr auto;gap:4px 10px}.gv-fr .gv-at{grid-column:1/-1}}' +
		'.gc-top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:10px}' +
		'.gc-nav{display:flex;align-items:center;gap:6px}.gc-nav h2{margin:0 6px;font:700 20px "Plus Jakarta Sans",Inter,sans-serif;min-width:150px;text-align:center}' +
		'.gc-tools{display:flex;gap:8px;align-items:center}' +
		'.gc-seg{display:inline-flex;border:1px solid #DDD9D2;border-radius:10px;overflow:hidden}.gc-seg button{border:0;background:#fff;padding:7px 14px;font:500 13px Inter,sans-serif;cursor:pointer;color:#4A5163}.gc-seg button.on{background:#141C33;color:#fff}' +
		'.gc-leg{display:flex;flex-wrap:wrap;gap:14px;font-size:12px;color:#656973;margin:0 0 10px}.gc-leg i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:5px;vertical-align:-2px}' +
		'.gc-leg i.pen,.gc-dot.pen{background:#FFF1CC;border:1.5px dashed #D49A00}.gc-leg i.ok,.gc-dot.ok{background:#DDF3E4;border:1.5px solid #2E9D5B}.gc-leg i.oth,.gc-dot.oth{background:#EEF0F4;border:1.5px solid #A3A9B6}' +
		'.gc-warn{background:#FFF6E5;border:1px solid #F2D49B;border-radius:12px;margin:0 0 10px;padding:4px 6px}.gc-warn.err{background:#FDECEA;border-color:#F3B6AE;padding:10px 12px;color:#9B2C1F}' +
		'.gc-mh{display:flex;gap:6px;align-items:center;width:100%;border:0;background:transparent;padding:8px;font:500 14px Inter,sans-serif;text-align:left;cursor:pointer;color:#5B4300}.gc-mh .gc-mt{flex:1}.gc-mh .gc-ml{flex:none;color:#2F6FDB;font-weight:600}' +
		'.gc-warn ul{list-style:none;margin:0;padding:0 4px 6px}.gc-warn li button{width:100%;text-align:left;border:0;background:#fff;border-radius:8px;margin:3px 0;padding:8px 10px;font:14px Inter,sans-serif;cursor:pointer}.gc-warn li small{color:#8A6D1F;margin-left:6px}' +
		'.gc-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border:1px solid #E6E3DE;border-radius:14px;overflow:hidden;background:#E6E3DE;gap:1px}' +
		'.gc-dow{background:#F6F4F0;padding:6px 8px;font:600 12px Inter,sans-serif;color:#656973;text-align:center}' +
		'.gc-day{background:#fff;min-height:118px;padding:4px 4px 6px;display:flex;flex-direction:column;gap:3px;cursor:pointer;min-width:0}.gc-day.out{background:#FAF9F7}.gc-day.out .gc-dn{color:#B5B8BF}.gc-day.past .gc-ev{opacity:.62}' +
		'.gc-dn{font:600 12.5px Inter,sans-serif;color:#4A5163;padding:2px 4px}.gc-day.today .gc-dn{display:inline-block;align-self:flex-start;background:#2F6FDB;color:#fff;border-radius:20px;padding:2px 8px}' +
		'.gc-ev{display:flex;gap:4px;align-items:baseline;width:100%;min-width:0;border-radius:6px;padding:3px 6px;font:500 12px/1.3 Inter,sans-serif;text-align:left;cursor:pointer;border:1px solid transparent;color:#141C33}' +
		'.gc-ev span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.gc-ev i{font-style:normal;font-weight:700;flex:none;color:#4A5163}' +
		'.gc-ev{border-left:4px solid var(--gc,#4C7BD9)!important}.gc-ev.pen{border-style:dashed}.gc-ev.oth{opacity:.72}' +
		'.gc-more{border:0;background:transparent;color:#2F6FDB;font:600 11.5px Inter,sans-serif;text-align:left;padding:1px 6px;cursor:pointer}' +
		'.gc-list{display:flex;flex-direction:column;gap:14px}.gc-ld h4{margin:0 0 6px;font:700 14px Inter,sans-serif;color:#141C33}.gc-ld h4 em{font-style:normal;background:#2F6FDB;color:#fff;border-radius:20px;padding:1px 8px;font-size:11.5px;margin-left:6px;text-transform:none}.gc-ld.past{opacity:.7}' +
		'.gc-li{display:flex;gap:12px;align-items:flex-start;padding:10px 12px;margin:0 0 6px;border-radius:12px;font-size:14px!important;line-height:1.35!important}.gc-li span{white-space:normal;overflow:visible}.gc-li .gc-tm{flex:none;width:86px;font-weight:700;color:#4A5163;font-size:13px}.gc-tt{display:flex;flex-direction:column;gap:2px;min-width:0}.gc-tt b{font-weight:600;overflow-wrap:anywhere}.gc-tt small{color:#5D6270;font-size:12.5px;overflow-wrap:anywhere}' +
		'.gc-setup{background:#fff;border:1px solid #E6E3DE;border-radius:16px;padding:22px;max-width:640px}.gc-setup h3{margin:0 0 8px}.gc-sm{color:#656973;font-size:13px}' +
		'.gc-mod{position:fixed;inset:0;z-index:60;display:flex;align-items:center;justify-content:center}.gc-bg{position:absolute;inset:0;background:rgba(20,24,33,.45)}' +
		'.gc-card{position:relative;background:#fff;border-radius:18px;width:min(640px,calc(100vw - 24px));max-height:calc(100vh - 40px);display:flex;flex-direction:column;box-shadow:0 24px 48px -16px rgba(0,0,0,.45)}' +
		'.gc-card header{display:flex;gap:10px;align-items:center;padding:16px 18px 10px;border-bottom:1px solid #EEEAE4}.gc-card h3{margin:0;font:700 17px Inter,sans-serif;flex:1;overflow-wrap:anywhere}.gc-dot{width:14px;height:14px;border-radius:4px;flex:none}' +
		'.gc-x{border:0;background:#F3F1ED;border-radius:50%;width:34px;height:34px;font-size:15px;cursor:pointer;flex:none}' +
		'.gc-body{padding:12px 18px;overflow:auto;font-size:14px;line-height:1.5}.gc-body p{margin:0 0 8px}.gc-when{font-weight:600;text-transform:none}' +
		'.gc-deal{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;background:#F6F4F0;border-radius:12px;padding:10px 12px;margin:6px 0 10px}.gc-pen{color:#8A6400;font-weight:600}' +
		'.gc-g ul{margin:4px 0 10px;padding-left:18px}.gc-g small{color:#656973}' +
		'.gc-desc{border-top:1px solid #EEEAE4;padding-top:10px;overflow-wrap:anywhere}.gc-desc a{color:#2F6FDB}' +
		'.gc-plan{margin-top:12px;border:1px dashed #C9C4BB;border-radius:12px;padding:8px 12px}.gc-plan summary{cursor:pointer;font-weight:600;color:#4A5163}.gc-pb{padding-top:8px}' +
		'.gc-card footer{padding:10px 18px 16px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #EEEAE4}' +
		'@media (max-width:760px){.gc-nav h2{font-size:17px;min-width:0}.gc-top{gap:8px}.gc-tools .cpx-btn span{display:none}' +
		'.gc-day{min-height:64px;padding:2px}.gc-ev{padding:1px 3px;font-size:10px}.gc-ev i{display:none}.gc-dn{font-size:11.5px;padding:1px 2px}.gc-more{font-size:10px;padding:0 2px}' +
		'.gc-mod{align-items:flex-end}.gc-card{width:100vw;max-height:88vh;border-radius:18px 18px 0 0;padding-bottom:env(safe-area-inset-bottom)}.gc-li .gc-tm{width:46px}.gc-li .gc-tm b{display:none}}';
	document.head.appendChild(css);
})();
