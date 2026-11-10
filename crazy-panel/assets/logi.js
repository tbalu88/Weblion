/* Crazy panel – Logisztika (v2.9.118): egy nap kiszállítása és bontása – térkép, idővonal, útvonal-javaslat a raktárból */
(function () {
	'use strict';
	const C = window.CPX || {};
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const pad = n => String(n).padStart(2, '0');
	const ymd = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	const addD = (s, n) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + n); return ymd(d); };
	const MON = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
	const DOW = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
	const huDay = s => { const d = new Date(s + 'T12:00:00'); return d.getFullYear() + '. ' + MON[d.getMonth()] + ' ' + d.getDate() + '. (' + DOW[d.getDay()] + ')'; };
	const min = hm => { if (!hm) return null; const p = hm.split(':').map(Number); return p[0] * 60 + p[1]; };
	const S = { day: null, d: null, main: null, seq: 0, map: null, err: '' };
	const CLR = ['#2F5BD8', '#C2621A', '#17663A', '#8A3FB8', '#B42318', '#0E7C86', '#7A5B00', '#4B5563'];

	async function post(action, p) {
		const fd = new FormData(); fd.append('action', action); fd.append('t', C.t); Object.keys(p || {}).forEach(k => fd.append(k, p[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j; try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j.success) throw new Error((j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j.data && j.data.msg) || 'Hiba'));
		return j.data;
	}
	/* Leaflet (térkép) igény szerint, a cdnjs-ről */
	let LP = null;
	function leaflet() {
		if (window.L && window.L.map) return Promise.resolve();
		if (LP) return LP;
		LP = new Promise((res, rej) => {
			const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(css);
			const s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; s.onload = () => res(); s.onerror = () => { LP = null; rej(new Error('A térkép nem töltődött be.')); }; document.head.appendChild(s);
		});
		return LP;
	}

	function view(main) {
		S.main = main;
		if (!S.day) { try { S.day = sessionStorage.getItem('cpx_logi_day') || ''; } catch (e) { S.day = ''; } }
		if (!/^\d{4}-\d{2}-\d{2}$/.test(S.day || '')) { const d = new Date(); S.day = ymd(d); }
		main.innerHTML = '<div class="lg"><div class="lg-h"><h2>Logisztika</h2><div class="lg-nav"><button type="button" class="cpx-btn ghost" data-dd="-1" aria-label="Előző nap">‹</button><input type="date" data-day value="' + S.day + '"><button type="button" class="cpx-btn ghost" data-dd="1" aria-label="Következő nap">›</button><button type="button" class="cpx-btn ghost" data-today>Ma</button><button type="button" class="cpx-btn ghost" data-print>🖨 Napi terv</button></div></div>' +
			'<p class="lg-help">A nap rendezvényei a térképen, a kiszállítás és a bontás javasolt sorrendje a raktárból. A <b>„telepítő viszi”</b> helyszínekre (ahol nincs Kezelő beosztva) az útvonal-javaslat számol; az időpontok a dealből becsültek (dőlt), kattintva átírhatók – csak a panelben, a Salesmate-be nem ír.</p>' +
			'<div class="lg-b"><div class="cpx-empty">Betöltés…</div></div></div>';
		main.querySelectorAll('[data-dd]').forEach(b => b.addEventListener('click', () => go(addD(S.day, Number(b.dataset.dd)))));
		main.querySelector('[data-day]').addEventListener('change', e => { if (e.target.value) go(e.target.value); });
		main.querySelector('[data-today]').addEventListener('click', () => go(ymd(new Date())));
		main.querySelector('[data-print]').addEventListener('click', printPlan);
		load();
	}
	function go(day) { S.day = day; try { sessionStorage.setItem('cpx_logi_day', day); } catch (e) { /* privát mód */ } const i = S.main && S.main.querySelector('[data-day]'); if (i) i.value = day; load(); }
	async function load(p) {
		const box = S.main && S.main.querySelector('.lg-b'); if (!box) return;
		const my = ++S.seq;
		if (!p) box.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
		let d;
		try { d = p || await post('cp_logi_day', { day: S.day }); } catch (e) { if (my === S.seq) { S.err = e.message; box.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; } return; }
		if (my !== S.seq) return;
		S.err = ''; S.d = d;
		draw(box, d);
		/* a még meg nem keresett címek: pár másodperc múlva újra (a térképszolgáltatás másodpercenként 1 címet ad) */
		if (d.pending) setTimeout(() => { if (my === S.seq && S.main && S.main.isConnected) load(); }, 1500);
	}
	const crewH = s => s.crew.length ? s.crew.map(c => '<span class="lg-cw ' + c.k + (c.st === 'final' ? '' : ' tent') + '" title="' + (c.k === 'kezelo' ? 'Kezelő' : 'Host/hostess') + (c.st === 'final' ? ' – véglegesítve' : ' – ráér, még nem végleges') + '">' + esc(c.n) + '</span>').join('') : '<span class="lg-cw none">nincs beosztva</span>';
	function draw(box, d) {
		const S2 = d.sites;
		if (!S2.length) { box.innerHTML = '<div class="cpx-empty">' + esc(huDay(d.day)) + ': nincs rendezvény.</div>'; return; }
		const idx = {}; S2.forEach((s, i) => { idx[s.deal] = i; });
		const tin = (s, k, lab) => '<label class="lg-t' + (s.est[k] ? ' est' : '') + '" title="' + (s.est[k] ? 'Becsült – átírható' : 'Kézzel megadva') + '"><span>' + lab + '</span><input type="time" data-tf="' + k + '" data-deal="' + s.deal + '" value="' + esc(s[k]) + '"></label>';
		const P = d.plan || {};
		const runH = (r, lab, cls) => !r ? '' : '<section class="lg-run ' + cls + '"><h3>' + lab + ' <small>' + r.km + ' km' + (r.est ? ' · becsült menetidő (az útvonaltervező nem érhető el)' : '') + '</small></h3><ol>' +
			'<li class="home"><b>' + esc(r.dep) + '</b> indulás a raktárból</li>' +
			r.stops.map((t, k) => { const s = S2[idx[t.deal]] || {}; return '<li><b>' + esc(t.arr) + '</b> <span class="lg-num" style="background:' + CLR[idx[t.deal] % CLR.length] + '">' + (idx[t.deal] + 1) + '</span> ' + esc(s.title || '') + '<small>' + t.drive + ' perc · ' + t.km + ' km · ' + (cls === 'out' ? 'bepakolás ' + esc(s.setup || '?') + ', kész ' + esc(t.leave) : 'bontás ' + esc(s.down || '?') + ', indulás ' + esc(t.leave)) + '</small>' + (t.late ? '<em class="lg-warn">' + (cls === 'out' ? t.late + ' perc késés a bepakolásról' : 'a gép ' + t.late + ' percet vár a bontásra') + '</em>' : '') + (t.startLate ? '<em class="lg-warn">a kezdésre sem készül el (' + t.startLate + ' perc)</em>' : '') + '</li>'; }).join('') +
			'<li class="home"><b>' + esc(r.home) + '</b> vissza a raktárba</li></ol></section>';
		/* idővonal: 6:00–24:00 (ami kilóg, a szélén) */
		const t0 = Math.min(6 * 60, ...S2.map(s => min(s.setup) ?? 1440)), t1 = Math.max(24 * 60, ...S2.map(s => min(s.down) ?? 0));
		const pct = m => ((m - t0) / (t1 - t0) * 100).toFixed(2) + '%';
		const bar = (a, b, cls, tt) => (a === null || b === null || b <= a) ? '' : '<i class="' + cls + '" style="left:' + pct(a) + ';width:calc(' + pct(b) + ' - ' + pct(a) + ')" title="' + esc(tt) + '"></i>';
		const ax = []; for (let h = Math.ceil(t0 / 60); h <= Math.floor(t1 / 60); h += 2) ax.push('<span style="left:' + pct(h * 60) + '">' + (h % 24) + '</span>');
		box.innerHTML = '<div class="lg-grid"><div class="lg-map" id="lg-map"><div class="cpx-empty">Térkép betöltése…</div></div><div class="lg-side">' +
			(d.pending ? '<p class="lg-pend">📍 ' + d.pending + ' cím keresése a térképen…</p>' : '') +
			'<ul class="lg-sites">' + S2.map((s, i) => '<li class="' + (s.deliver ? 'dl' : '') + '"><div class="lg-st"><span class="lg-num" style="background:' + CLR[i % CLR.length] + '">' + (i + 1) + '</span><b>' + esc(s.title) + '</b>' + (s.n > 1 ? ' <small>' + s.n + ' gép</small>' : '') + (s.booked ? '' : ' <small class="soft">ajánlat</small>') + '</div>' +
				'<div class="lg-loc">' + (s.geo === 0 ? '<span class="lg-warn">📍 nem találom a térképen:</span> ' : s.geo === -1 ? '⏳ ' : '📍 ') + esc(s.loc || 'nincs helyszín') + '</div>' +
				'<div class="lg-crew">' + crewH(s) + '</div>' +
				'<div class="lg-ts">' + tin(s, 'setup', 'Bepakolás') + tin(s, 'start', 'Kezdés') + tin(s, 'end', 'Vége') + tin(s, 'down', 'Bontás') + '</div>' +
				'<label class="lg-dl"><input type="checkbox" data-dlv="' + s.deal + '"' + (s.deliver ? ' checked' : '') + '> A telepítő viszi (kiszállítás + bontás)' + (s.deliver_set ? '' : ' <small>– automatikus</small>') + '</label></li>').join('') + '</ul></div></div>' +
			'<section class="lg-tl"><h3>Idővonal</h3><div class="lg-ax">' + ax.join('') + '</div>' + S2.map((s, i) => '<div class="lg-row"><span class="lg-rl"><span class="lg-num" style="background:' + CLR[i % CLR.length] + '">' + (i + 1) + '</span>' + esc(s.title) + '</span><div class="lg-bars">' +
				bar(min(s.setup), min(s.start), 'su', 'Bepakolás ' + s.setup + '–' + s.start) + bar(min(s.start), min(s.end), 'ev', 'Rendezvény ' + s.start + '–' + s.end) + bar(min(s.end), Math.max(min(s.down) ?? 0, min(s.end)) + 40, 'td', 'Bontás ' + s.down) + '</div></div>').join('') +
				'<div class="lg-lg"><span><i class="su"></i>bepakolás</span><span><i class="ev"></i>rendezvény</span><span><i class="td"></i>bontás</span></div></section>' +
			'<div class="lg-runs">' + runH(P.out, '🚐 Kiszállítás', 'out') + runH(P.back, '🔙 Bontás és visszaút', 'back') + (!P.out && S2.some(s => s.deliver) ? '<p class="cpx-muted">Az útvonalhoz a helyszínek koordinátája kell – a címek keresése folyamatban, vagy nem találhatók.</p>' : '') + (!S2.some(s => s.deliver) ? '<p class="cpx-muted">Még nincs kiszállítandó helyszín. A körbe azok kerülnek, ahova Host/hostess van beosztva (a Kezelő maga viszi a gépet) – vagy pipáld be a „A telepítő viszi” jelölőt a helyszínnél.</p>' : '') + '</div>';
		const save = async (p) => { try { load(await post('cp_logi_save', Object.assign({ day: S.day }, p))); } catch (e) { alert(e.message); } };
		box.querySelectorAll('[data-tf]').forEach(i => i.addEventListener('change', () => save({ deal: i.dataset.deal, [i.dataset.tf]: i.value })));
		box.querySelectorAll('[data-dlv]').forEach(c => c.addEventListener('change', () => save({ deal: c.dataset.dlv, deliver: c.checked ? '1' : '0' })));
		drawMap(d);
	}
	async function drawMap(d) {
		const el = document.getElementById('lg-map'); if (!el) return;
		try { await leaflet(); } catch (e) { el.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		if (!el.isConnected) return;
		el.innerHTML = '';
		const m = window.L.map(el, { scrollWheelZoom: false });
		window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '© OpenStreetMap' }).addTo(m);
		const pts = [];
		if (d.home) { pts.push([d.home.lat, d.home.lon]); window.L.marker([d.home.lat, d.home.lon], { icon: window.L.divIcon({ className: 'lg-pin home', html: '🏠', iconSize: [30, 30] }) }).addTo(m).bindPopup('<b>Raktár</b><br>' + esc(d.home.n)); }
		d.sites.forEach((s, i) => {
			if (s.lat === null) return;
			pts.push([s.lat, s.lon]);
			window.L.marker([s.lat, s.lon], { icon: window.L.divIcon({ className: 'lg-pin', html: '<span style="background:' + CLR[i % CLR.length] + '">' + (i + 1) + '</span>', iconSize: [26, 26] }) }).addTo(m)
				.bindPopup('<b>' + esc(s.title) + '</b><br>' + esc(s.loc) + '<br>' + esc([s.setup && 'bepakolás ' + s.setup, s.start && 'kezdés ' + s.start, s.down && 'bontás ' + s.down].filter(Boolean).join(' · ')));
		});
		const P = d.plan || {};
		if (P.out && P.out.line.length) window.L.polyline(P.out.line, { color: '#2F5BD8', weight: 4, opacity: .75 }).addTo(m);
		if (P.back && P.back.line.length) window.L.polyline(P.back.line, { color: '#C2621A', weight: 3, opacity: .6, dashArray: '6 6' }).addTo(m);
		if (pts.length) m.fitBounds(pts, { padding: [30, 30], maxZoom: 13 }); else m.setView([47.4979, 19.0402], 10);
		S.map = m;
	}
	function printPlan() {
		const d = S.d; if (!d || !d.sites.length) { alert('Ezen a napon nincs mit nyomtatni.'); return; }
		const w = window.open('', '_blank'); if (!w) return;
		const P = d.plan || {}, idx = {}; d.sites.forEach((s, i) => { idx[s.deal] = i; });
		const run = (r, lab, cls) => !r ? '' : '<h2>' + lab + ' (' + r.km + ' km)</h2><table><tr><td><b>' + esc(r.dep) + '</b></td><td>indulás a raktárból</td></tr>' + r.stops.map(t => { const s = d.sites[idx[t.deal]]; return '<tr><td><b>' + esc(t.arr) + '</b></td><td>' + (idx[t.deal] + 1) + '. ' + esc(s.title) + '<br><small>' + esc(s.loc) + ' · ' + (cls === 'out' ? 'bepakolás ' + esc(s.setup) : 'bontás ' + esc(s.down)) + (t.late ? ' · ⚠ ' + t.late + ' perc' : '') + '</small></td></tr>'; }).join('') + '<tr><td><b>' + esc(r.home) + '</b></td><td>vissza a raktárba</td></tr></table>';
		w.document.write('<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Napi terv ' + esc(d.day) + '</title><style>body{font:13px/1.45 Arial,sans-serif;margin:24px;color:#111}h1{font-size:18px}h2{font-size:14px;margin:18px 0 6px;border-bottom:1px solid #ccc}table{border-collapse:collapse;width:100%}td{padding:4px 10px 4px 0;vertical-align:top}small{color:#555}</style></head><body>' +
			'<h1>Napi terv – ' + esc(huDay(d.day)) + '</h1><h2>Helyszínek</h2><table>' + d.sites.map((s, i) => '<tr><td><b>' + (i + 1) + '.</b></td><td><b>' + esc(s.title) + '</b><br>' + esc(s.loc) + '<br><small>bepakolás ' + esc(s.setup || '?') + ' · kezdés ' + esc(s.start || '?') + ' · vége ' + esc(s.end || '?') + ' · bontás ' + esc(s.down || '?') + ' · ' + esc(s.crew.map(c => c.n + (c.k === 'kezelo' ? ' (kezelő)' : ' (host)')).join(', ') || 'nincs beosztva') + (s.deliver ? ' · a telepítő viszi' : '') + '</small></td></tr>').join('') + '</table>' +
			run(P.out, 'Kiszállítás', 'out') + run(P.back, 'Bontás és visszaút', 'back') + '<script>window.onload=function(){setTimeout(function(){window.print()},300)}<\/script></body></html>');
		w.document.close();
	}
	function status(s) { s.textContent = S.err || (S.d ? S.d.sites.length + ' helyszín' : ''); s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function refresh() { if (S.main && S.main.isConnected) await load(); }
	function deep(day) { if (/^\d{4}-\d{2}-\d{2}$/.test(day || '')) go(day); }
	window.CPLogi = { view, status, refresh, deep };

	const css = document.createElement('style');
	css.textContent = '.lg-h{display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:space-between}.lg-h h2{margin:0;font:800 20px "Plus Jakarta Sans",sans-serif}.lg-nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.lg-nav input{border:1px solid #E6E3DE;border-radius:8px;padding:6px 8px;font:14px Inter,sans-serif}.lg-help{font-size:13px;color:#4A4F5A;margin:8px 0 12px}' +
		'.lg-grid{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:12px;align-items:start}.lg-map{height:520px;border-radius:12px;overflow:hidden;border:1px solid #E6E3DE;background:#F5F3EF}.lg-side{max-height:520px;overflow:auto}' +
		'.lg-sites{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}.lg-sites li{background:#fff;border:1px solid #E6E3DE;border-radius:12px;padding:10px 12px}.lg-sites li.dl{border-left:4px solid #2F5BD8}' +
		'.lg-st{display:flex;align-items:center;gap:8px}.lg-st b{font-size:13.5px;overflow-wrap:anywhere}.lg-st small{color:#656973}.lg-st small.soft{background:#FFF3C4;color:#7A5B00;border-radius:6px;padding:0 6px}.lg-loc{font-size:12.5px;color:#4A4F5A;margin:4px 0;overflow-wrap:anywhere}' +
		'.lg-num{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:50%;color:#fff;font:700 11px Inter,sans-serif;flex-shrink:0}' +
		'.lg-crew{display:flex;flex-wrap:wrap;gap:4px;margin:4px 0}.lg-cw{font:600 11.5px Inter,sans-serif;border-radius:999px;padding:1px 8px;background:#EEF0F3}.lg-cw.kezelo{background:#141C33;color:#fff}.lg-cw.host{background:#E8EEFC;color:#2F4FA8}.lg-cw.tent{opacity:.65;border:1px dashed currentColor}.lg-cw.none{background:#FDECEA;color:#B42318}' +
		'.lg-ts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin:6px 0}.lg-t{display:flex;flex-direction:column;gap:2px;font-size:11px;color:#656973}.lg-t input{border:1px solid #E6E3DE;border-radius:7px;padding:4px 5px;font:13px Inter,sans-serif;min-width:0;width:100%;box-sizing:border-box}.lg-t.est input{font-style:italic;color:#6B6F78;background:#FAF8F5}' +
		'.lg-dl{font-size:12.5px;display:flex;align-items:center;gap:6px}.lg-dl small{color:#8A8E96}.lg-warn{color:#B42318;font-style:normal;font-weight:600}.lg-pend{font-size:12.5px;color:#8A5300;margin:0 0 8px}' +
		'.lg-tl{background:#fff;border:1px solid #E6E3DE;border-radius:12px;padding:10px 12px;margin-top:12px}.lg-tl h3,.lg-run h3{margin:0 0 8px;font:700 15px "Plus Jakarta Sans",sans-serif}.lg-run h3 small{font:400 12px Inter,sans-serif;color:#656973}' +
		'.lg-ax{position:relative;height:16px;margin-left:200px;font-size:10.5px;color:#8A8E96}.lg-ax span{position:absolute;transform:translateX(-50%)}' +
		'.lg-row{display:grid;grid-template-columns:200px minmax(0,1fr);gap:0;align-items:center;padding:3px 0;border-top:1px solid #F0ECE6}.lg-rl{display:flex;gap:6px;align-items:center;font-size:12px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;padding-right:8px}' +
		'.lg-bars{position:relative;height:16px;background:repeating-linear-gradient(90deg,#FAF8F5 0,#FAF8F5 calc(11.11% - 1px),#EEE9E2 calc(11.11% - 1px),#EEE9E2 11.11%);border-radius:4px}.lg-bars i,.lg-lg i{position:absolute;top:2px;bottom:2px;border-radius:3px}.lg-bars i.su,.lg-lg i.su{background:#F2C14E}.lg-bars i.ev,.lg-lg i.ev{background:#2F5BD8}.lg-bars i.td,.lg-lg i.td{background:#C2621A}' +
		'.lg-lg{display:flex;gap:14px;font-size:11.5px;color:#656973;margin-top:8px}.lg-lg i{position:static;display:inline-block;width:12px;height:8px;margin-right:5px}' +
		'.lg-runs{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px;margin-top:12px}.lg-run{background:#fff;border:1px solid #E6E3DE;border-radius:12px;padding:10px 12px}.lg-run.out{border-top:4px solid #2F5BD8}.lg-run.back{border-top:4px solid #C2621A}' +
		'.lg-run ol{list-style:none;margin:0;padding:0}.lg-run li{padding:6px 0;border-top:1px solid #F0ECE6;font-size:13px;overflow-wrap:anywhere}.lg-run li:first-child{border-top:0}.lg-run li small{display:block;color:#656973;font-size:12px;margin-left:46px}.lg-run li b{display:inline-block;width:42px;font-variant-numeric:tabular-nums}.lg-run li.home{color:#656973}.lg-run li em{display:block;margin-left:46px;font-size:12px}' +
		'.lg-pin{background:none;border:0}.lg-pin span{display:flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;color:#fff;font:700 12px Inter,sans-serif;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35)}.lg-pin.home{font-size:22px;line-height:30px;text-align:center}' +
		'@media (max-width:900px){.lg-grid{grid-template-columns:1fr}.lg-map{height:360px}.lg-side{max-height:none}.lg-ax{margin-left:110px}.lg-row{grid-template-columns:110px minmax(0,1fr)}}' +
		'@media (max-width:520px){.lg-ts{grid-template-columns:1fr 1fr}}';
	document.head.appendChild(css);
})();
