/* Crazy panel – Statisztikák fül (v1.15) */
(function () {
	'use strict';
	const C = window.CPX || {};
	const HU_M = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
	const HU_MS = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
	const DOW = ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];
	const CH_COL = { google: '#4C7BD9', meta: '#7B5BD6', linkedin: '#2B8CA8', organic: '#2E9E6A', referral: '#D9902A', ads: '#9A8F80', other: '#B7AFA3', unknown: '#D2CBC0' };
	const GRANS = [['day', 'Nap'], ['week', 'Hét'], ['month', 'Hónap'], ['quarter', 'Negyedév'], ['year', 'Év']];

	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const pad = n => (n < 10 ? '0' : '') + n;
	const ymd = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	const pd = s => { const p = s.split('-').map(Number); return new Date(p[0], p[1] - 1, p[2]); };
	const num = n => n == null || isNaN(n) ? '–' : Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
	const ft = n => n == null || isNaN(n) ? '–' : num(n) + ' Ft';
	const pct = (a, b) => b ? Math.round(a / b * 100) + '%' : '–';
	const dshort = s => { const d = pd(s); return d.getFullYear() + '.' + pad(d.getMonth() + 1) + '.' + pad(d.getDate()) + '.'; };
	const md = s => { const d = pd(s); return HU_MS[d.getMonth()] + ' ' + d.getDate() + '.'; };
	const hrs = h => h == null ? '–' : (h < 1 ? Math.round(h * 60) + ' perc' : (h < 48 ? (Math.round(h * 10) / 10).toString().replace('.', ',') + ' óra' : Math.round(h / 24) + ' nap'));

	const S = { gran: 'month', a: ymd(new Date()), data: null, ga: null, err: '', loading: false, gaTimer: null, yoy: true, main: null, sel: -1 };
	try { const g = localStorage.getItem('cpst_gran'); if (g && GRANS.some(x => x[0] === g)) S.gran = g; } catch (e) { /* privát mód */ }
	S.edit = false;

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

	function periodLabel(g, f, t) {
		const a = pd(f);
		if (g === 'day') return a.getFullYear() + '. ' + HU_M[a.getMonth()] + ' ' + a.getDate() + '.';
		if (g === 'week') { const b = pd(t); return md(f) + ' – ' + md(t) + (a.getFullYear() !== new Date().getFullYear() || b.getFullYear() !== a.getFullYear() ? ' ' + b.getFullYear() : ''); }
		if (g === 'month') return a.getFullYear() + '. ' + HU_M[a.getMonth()];
		if (g === 'quarter') return a.getFullYear() + '. ' + (Math.floor(a.getMonth() / 3) + 1) + '. negyedév';
		return a.getFullYear() + '.';
	}

	function shiftAnchor(dir) {
		const d = pd(S.a);
		if (S.gran === 'day') d.setDate(d.getDate() + dir);
		else if (S.gran === 'week') d.setDate(d.getDate() + 7 * dir);
		else if (S.gran === 'month') { d.setDate(1); d.setMonth(d.getMonth() + dir); }
		else if (S.gran === 'quarter') { d.setDate(1); d.setMonth(d.getMonth() + 3 * dir); }
		else { d.setDate(1); d.setFullYear(d.getFullYear() + dir); }
		S.a = ymd(d);
	}

	async function load(force) {
		S.loading = true; S.err = '';
		status();
		try {
			S.data = await post('cp_st_data', { g: S.gran, a: S.a, refresh: force ? 1 : '' });
		} catch (e) { S.err = e.message; }
		S.loading = false;
		status();
	}

	async function loadGa() {
		const d = S.data;
		if (!d || !d.sync || !d.sync.ga) { S.ga = { off: true }; return; }
		try {
			S.ga = await post('cp_st_ga', { f: d.from, to: d.curTo, pf: d.prev[0], pt: d.prev[1], yf: d.yoy[0], yt: d.yoy[1] });
		} catch (e) { S.ga = { err: e.message }; }
	}

	function status(el) {
		const s = el || document.getElementById('cpx-status');
		if (!s) return;
		if (S.loading) { s.textContent = 'Betöltés…'; s.className = 'cpx-status'; return; }
		if (S.err) { s.textContent = S.err; s.className = 'cpx-status err'; return; }
		const y = S.data && S.data.sync;
		if (y && y.last) { const d = new Date(y.last * 1000); s.textContent = 'Salesmate-szinkron: ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); s.className = 'cpx-status'; }
		else s.textContent = '';
	}

	/* ------------------------------------------------------------ nézet */
	async function view(main) {
		S.main = main;
		if (!S.data) {
			main.innerHTML = '<div class="cpx-empty">Statisztikák betöltése…</div>';
			await load(false);
		}
		draw();
		loadAlerts();
		clearInterval(S.alTimer);
		S.alTimer = setInterval(() => { if (document.getElementById('st-alerts') && !document.hidden) loadAlerts(); }, 60000);
		loadGa().then(() => { if (S.main && document.body.contains(S.main) && document.getElementById('st-ga')) drawGa(); });
		clearInterval(S.gaTimer);
		S.gaTimer = setInterval(() => {
			if (!document.getElementById('st-ga') || document.hidden) return;
			loadGa().then(drawGa);
		}, 60000);
	}

	async function reload(force) {
		if (S.main) S.main.querySelectorAll('.st-body').forEach(x => { x.style.opacity = '.45'; });
		await load(force);
		draw();
		loadGa().then(drawGa);
	}

	function delta(cur, base, money, inv, fmt) {
		if (base == null) return '';
		const f = fmt || (money ? ft : num);
		if (!base && !cur) return '<span class="st-eq">0</span>';
		if (!base) return '<span class="st-up">új</span>';
		if (cur == null) return '<span>(' + f(base) + ')</span>';
		const p = Math.round((cur - base) / base * 100);
		const good = inv ? p < 0 : p > 0;
		const cls = p === 0 ? 'st-eq' : (good ? 'st-up' : 'st-dn');
		return '<span class="' + cls + '">' + (p > 0 ? '+' : '') + p + '%</span> <span>(' + f(base) + ')</span>';
	}

	function kpiTile(label, cur, prev, yoy, money, inv, fmt) {
		const f = fmt || (money ? ft : num);
		return '<div class="st-kpi"><div class="l">' + label + '</div><b title="' + esc(f(cur)) + '">' + f(cur) + '</b><div class="d">' +
			'<span>előző: ' + (prev == null ? '–' : delta(cur, prev, money, inv, fmt)) + '</span>' +
			'<span>tavaly: ' + (yoy == null ? '–' : delta(cur, yoy, money, inv, fmt)) + '</span></div></div>';
	}

	function draw() {
		const main = S.main;
		if (!main) return;
		const d = S.data;
		if (!d) {
			main.innerHTML = '<div class="cpx-empty err">' + esc(S.err || 'Nem sikerült betölteni.') + '<br><button class="cpx-btn" id="st-retry">Újra</button></div>';
			main.querySelector('#st-retry').onclick = () => reload(false);
			return;
		}
		const L = d.labels;
		const k = d.kpi;
		let h = '<div class="st-head"><div class="st-seg" role="tablist">' + GRANS.map(g => '<button data-g="' + g[0] + '" class="' + (S.gran === g[0] ? 'on' : '') + '">' + g[1] + '</button>').join('') + '</div>' +
			'<div class="st-nav"><button class="cpx-btn ghost" data-d="-1" aria-label="Előző időszak">‹</button><h2>' + esc(periodLabel(d.gran, d.from, d.to)) + '</h2><button class="cpx-btn ghost" data-d="1" aria-label="Következő időszak">›</button>' +
			'<button class="cpx-btn ghost" data-now' + (d.from <= d.today && d.to >= d.today ? ' disabled' : '') + '>Most</button>' +
			'<button class="cpx-btn ghost st-edit-btn' + (S.edit ? ' on' : '') + '" data-edit title="Csempék áthelyezése, átméretezése, elrejtése">' + (S.edit ? 'Kész' : 'Elrendezés') + '</button></div></div>';
		h += '<div class="st-note">' + (d.partial ? 'Folyamatban lévő időszak (' + dshort(d.from) + '–' + dshort(d.curTo) + '); az összevetés azonos hosszúságú szakaszokkal: előző ' + dshort(d.prev[0]) + '–' + dshort(d.prev[1]) + ', tavaly ' + dshort(d.yoy[0]) + '–' + dshort(d.yoy[1]) + '.' : 'Előző időszak: ' + dshort(d.prev[0]) + '–' + dshort(d.prev[1]) + ' · tavalyi azonos időszak: ' + dshort(d.yoy[0]) + '–' + dshort(d.yoy[1]) + '.') + ' Megkeresés = beérkezett deal (létrehozás napja), új deal = aláírt szerződés (aláírás napja).</div>';
		const y = d.sync;
		if (y.busy) h += '<div class="st-warn"><b>Az első Salesmate-beolvasás folyamatban</b> (' + num(y.deals) + ' deal eddig) – a számok még hiányosak lehetnek.</div>';
		if (y.err) h += '<div class="st-warn err"><b>Szinkronhiba:</b> ' + esc(y.err) + '</div>';
		if (y.last && Date.now() / 1000 - y.last > 3 * 3600) h += '<div class="st-warn">A legutóbbi sikeres szinkron ' + Math.round((Date.now() / 1000 - y.last) / 3600) + ' órája volt – a WP-Cron valószínűleg nem fut rendszeresen.</div>';

		const cpl = (s, l) => s && l ? s / l : null;
		const tiles = [
			['leads', kpiTile('Megkeresések', k.cur.leads, k.prev.leads, k.yoy.leads)],
			['won', kpiTile('Új (aláírt) dealek', k.cur.won, k.prev.won, k.yoy.won)],
			['value', kpiTile('Aláírt dealérték', k.cur.value, k.prev.value, k.yoy.value, true)],
			['spend', kpiTile('Hirdetési költés', k.cur.spend, k.prev.spend, k.yoy.spend, true, true)],
			['cpl', kpiTile('Költség / megkeresés', cpl(k.cur.spend, k.cur.leads), cpl(k.prev.spend, k.prev.leads), cpl(k.yoy.spend, k.yoy.leads), true, true)],
			['roas', kpiTile('Aláírt érték / hirdetési költés', k.cur.spend ? k.cur.value / k.cur.spend : null, k.prev.spend ? k.prev.value / k.prev.spend : null, k.yoy.spend ? k.yoy.value / k.yoy.spend : null, false, false, v => v == null ? '–' : (Math.round(v * 10) / 10).toString().replace('.', ',') + '×')]
		];
		h += '<div class="st-body">' + (S.edit ? '<div class="st-editbar" id="st-editbar"></div>' : '') + '<div class="st-kpis" data-lc="kpi">' +
			tiles.map(t => t[1].replace('<div class="st-kpi">', '<div class="st-kpi" data-lid="k-' + t[0] + '" data-lt="' + esc(t[1].match(/class="l">([^<]*)/)[1]) + '">')).join('') +
			'</div>';
		if (k.cur.nonhuf) h += '<div class="st-note">' + k.cur.nonhuf + ' nem forintos deal értéke nincs az összegben.</div>';

		h += '<div class="st-grid" data-lc="card"><div class="cpx-card wide" data-lid="alerts" data-lt="Figyelmeztetések"><div id="st-alerts"></div></div><div class="cpx-card wide" data-lid="trend" data-lt="Trend"><div class="st-card-h"><h3>Trend</h3><small>' + ({ day: 'utolsó 31 nap', week: 'utolsó 16 hét', month: 'utolsó 13 hónap', quarter: 'utolsó 8 negyedév', year: 'utolsó 6 év' })[d.gran] + '</small></div>' +
			'<div id="st-trend"></div><div class="st-legend"><span><i style="background:var(--o)"></i>Megkeresés</span><span><i style="background:var(--ok)"></i>Aláírt deal</span><label><input type="checkbox" id="st-yoy"' + (S.yoy ? ' checked' : '') + '> <i class="ln" style="background:#8A93A8"></i>tavaly ugyanekkor</label></div><div class="st-tip" id="st-tip"></div></div>';

		h += '<div class="cpx-card wide" data-lid="ga" data-lt="Weboldal-forgalom (GA4)"><div id="st-ga"></div></div>';

		h += cardCpl(d, L);
		h += cardAds(d, L);
		h += cardLead(d);
		h += cardReact(d, L);
		h += cardRoas(d, L);
		h += cardFunnel(d, L);
		h += cardMix(d);
		h += cardCap(d);
		h += cardMargin(d);
		h += cardPrint(d);
		h += cardSat(d);
		h += cardHeat(d);
		h += cardLi(d);
		h += cardSources(d);
		h += '</div></div>';
		main.innerHTML = h;

		main.querySelectorAll('[data-g]').forEach(b => b.onclick = () => { S.gran = b.dataset.g; try { localStorage.setItem('cpst_gran', S.gran); } catch (e) { /* privát mód */ } reload(false); });
		main.querySelectorAll('[data-d]').forEach(b => b.onclick = () => { shiftAnchor(+b.dataset.d); reload(false); });
		main.querySelector('[data-now]').onclick = () => { S.a = ymd(new Date()); reload(false); };
		main.querySelector('#st-yoy').onchange = e => { S.yoy = e.target.checked; drawTrend(); };
		main.querySelector('[data-edit]').onclick = () => { S.edit = !S.edit; draw(); };
		bindLi(main);
		layoutApply(main);
		if (S.edit) layoutEdit(main);
		drawTrend();
		drawGa();
		drawAlerts();
	}

	/* ------------------------------------------------------------ élő figyelmeztetések */
	async function loadAlerts() {
		try { S.al = await post('cp_st_alerts', {}); S.alErr = ''; } catch (e) { S.alErr = e.message; }
		drawAlerts();
	}
	const hm = ts => { const x = new Date(ts * 1000); return pad(x.getHours()) + ':' + pad(x.getMinutes()); };
	function alItem(x) {
		const ext = [];
		if (x.h != null) ext.push(x.h < 1 ? Math.round(x.h * 60) + ' perce vár' : (x.h < 48 ? Math.round(x.h) + ' órája vár' : Math.round(x.h / 24) + ' napja vár'));
		if (x.late != null && x.late > 0) ext.push('<b class="st-bad">' + x.late + ' napja lejárt</b>');
		else if (x.late != null) ext.push('határidő: ' + dshort(x.d));
		if (x.ev) ext.push('rendezvény: ' + dshort(x.ev));
		if (x.left != null) ext.push(x.left === 0 ? '<b class="st-bad">ma</b>' : x.left + ' nap múlva');
		if (x.age != null) ext.push(x.age === 0 ? 'ma rohadt meg' : x.age + ' napja rohad');
		if (x.term) ext.push(x.term + ' napos fizetés');
		if (x.pm) ext.push(esc(x.pm));
		if (x.st && x.id) ext.push(esc(x.st));
		const name = x.id ? '<a href="' + esc(C.sm + x.id + '/detail') + '" target="_blank" rel="noopener">' + esc(x.t || ('Deal #' + x.id)) + '</a>' : '<b>' + esc(x.t) + '</b>';
		return '<li' + (x.sev ? ' class="st-al-' + x.sev + '"' : '') + '>' + name + (x.id ? '' : (x.st ? ' – ' + esc(x.st) : '')) + (ext.length ? ' <span class="st-sub">· ' + ext.join(' · ') + '</span>' : '') + (x.v ? ' <span class="st-al-v">' + ft(x.v) + '</span>' : '') + (x.ok && x.cid ? ' <button type="button" class="cpx-btn ghost sm st-mok" data-cid="' + esc(x.cid) + '" data-rv="' + esc(x.rv) + '" title="Nem kell rá válaszolni (pl. köszönő levél) – újabb levélnél újra jelez">✓ Nem kell válasz</button>' : '') + '</li>';
	}
	function toastSt(t) {
		const d = document.createElement('div');
		d.className = 'st-toast';
		d.textContent = t;
		document.body.appendChild(d);
		setTimeout(() => d.remove(), 2600);
	}
	function drawAlerts() {
		const box = document.getElementById('st-alerts');
		if (!box) return;
		const a = S.al;
		let h = '<div class="st-card-h"><h3>Figyelmeztetések</h3><small>' + (a ? 'élő · frissítve ' + hm(a.at) + (a.quick ? ' · Salesmate: ' + hm(a.quick) : '') : '') + '</small></div>';
		if (S.alErr && !a) { box.innerHTML = h + '<div class="st-warn err">' + esc(S.alErr) + '</div>'; return; }
		if (!a) { box.innerHTML = h + '<div class="st-sub">Betöltés…</div>'; return; }
		const act = a.alerts.filter(x => x.sev !== 'ok'), ok = a.alerts.filter(x => x.sev === 'ok');
		h += '<div class="st-als">' + act.map(x => '<div class="st-al st-al-' + x.sev + '"><div class="st-al-h"><span class="st-al-big">' + esc(String(x.big)) + '</span><div><b>' + esc(x.title) + '</b>' + (x.n != null ? ' <span class="st-sub">(' + x.n + ' deal)</span>' : '') + '<div class="st-sub">' + esc(x.text) + '</div>' + (x.sub ? '<div class="st-sub st-al-sub">' + esc(x.sub) + '</div>' : '') + '</div></div>' +
			(x.items && x.items.length ? '<details' + (x.sev === 'red' ? ' open' : '') + '><summary>' + (x.items.length + (x.more || 0)) + ' tétel' + (x.more ? ' (az első ' + x.items.length + ' látszik)' : '') + '</summary><ul>' + x.items.map((it, i) => (x.id === 'mail' && it.sev === 'info' && (i === 0 || x.items[i - 1].sev !== 'info') ? '<li class="st-al-sep">Lezajlott rendezvény utáni levelek – nem számítanak bele (jellemzően köszönő):</li>' : '') + alItem(it)).join('') + (x.more ? '<li class="st-sub">… és még ' + x.more + '</li>' : '') + '</ul>' + (x.note ? '<div class="st-sub st-al-note">' + esc(x.note) + '</div>' : '') + '</details>' : (x.note ? '<div class="st-sub st-al-note">' + esc(x.note) + '</div>' : '')) + '</div>').join('') + '</div>';
		if (ok.length) h += '<div class="st-al-okline">✓ Rendben: ' + ok.map(x => esc(x.title)).join(' · ') + '</div>';
		if (a.quickErr) h += '<div class="st-warn err" style="margin-top:8px">Gyors szinkron hiba: ' + esc(a.quickErr) + '</div>';
		const open = [...box.querySelectorAll('details')].map(d => d.open);
		box.innerHTML = h;
		box.querySelectorAll('details').forEach((d, i) => { if (open[i] != null) d.open = open[i]; });
		box.querySelectorAll('.st-mok').forEach(b => b.onclick = async () => {
			b.disabled = true;
			try { S.al = await post('cp_st_mail_ok', { cid: b.dataset.cid, rv: b.dataset.rv }); drawAlerts(); toastSt('Elrejtve – ha újabb levél jön, újra jelez.'); }
			catch (e) { b.disabled = false; alert(e.message); }
		});
	}

	/* ------------------------------------------------------------ elrendezés: áthelyezés, átméretezés, elrejtés (böngészőnként mentve) */
	const LKEY = 'cpst_layout_v1';
	function layoutGet() {
		try { const x = JSON.parse(localStorage.getItem(LKEY) || '{}'); return x && typeof x === 'object' ? x : {}; } catch (e) { return {}; }
	}
	function layoutSave(main) {
		const out = {};
		main.querySelectorAll('[data-lc]').forEach(c => {
			const items = [...c.children].filter(x => x.dataset.lid);
			out[c.dataset.lc] = { order: items.map(x => x.dataset.lid), span: {}, hidden: items.filter(x => x.classList.contains('st-hid')).map(x => x.dataset.lid) };
			items.forEach(x => { if (x.dataset.span) out[c.dataset.lc].span[x.dataset.lid] = +x.dataset.span; });
		});
		try { localStorage.setItem(LKEY, JSON.stringify(out)); } catch (e) { /* privát mód: csak erre a megnyitásra marad meg */ }
	}
	const defSpan = el => el.classList.contains('st-kpi') ? 1 : (el.classList.contains('wide') ? 12 : 6);
	function setSpan(el, n) {
		const max = el.classList.contains('st-kpi') ? 3 : 12, min = el.classList.contains('st-kpi') ? 1 : 3;
		n = Math.max(min, Math.min(max, Math.round(n)));
		el.dataset.span = n;
		el.style.gridColumn = 'span ' + n;
	}
	function layoutApply(main) {
		const L = layoutGet();
		main.querySelectorAll('[data-lc]').forEach(c => {
			const cfg = L[c.dataset.lc] || {};
			const items = [...c.children].filter(x => x.dataset.lid);
			const pos = id => { const i = (cfg.order || []).indexOf(id); return i < 0 ? 1e3 + items.findIndex(x => x.dataset.lid === id) : i; };
			/* új (még nem mentett) csempe: az alapértelmezett helyén, a mentett szomszédja után */
			if (cfg.order && cfg.order.length) {
				const known = items.filter(x => cfg.order.includes(x.dataset.lid)).sort((a, b) => pos(a.dataset.lid) - pos(b.dataset.lid));
				const res = [];
				let ki = 0;
				items.forEach(x => { if (cfg.order.includes(x.dataset.lid)) res.push(known[ki++]); else res.push(x); });
				res.forEach(x => c.appendChild(x));
			}
			items.forEach(x => {
				const sp = cfg.span && cfg.span[x.dataset.lid];
				setSpan(x, sp || defSpan(x));
				x.classList.toggle('st-hid', (cfg.hidden || []).includes(x.dataset.lid));
			});
		});
	}
	function layoutEdit(main) {
		main.classList.add('st-editing');
		const bar = main.querySelector('#st-editbar');
		const drawBar = () => {
			const hid = [...main.querySelectorAll('[data-lid].st-hid')];
			bar.innerHTML = '<div><b>Elrendezés szerkesztése</b> – húzd a <span class="st-hint">⠿</span> fogantyúnál az áthelyezéshez, a jobb alsó saroknál a méretezéshez; × = elrejtés. Böngészőnként mentődik.</div>' +
				'<div class="st-editbar-r">' + (hid.length ? '<span class="st-sub">Rejtett:</span> ' + hid.map(x => '<button class="cpx-btn ghost sm" data-show="' + esc(x.dataset.lid) + '">+ ' + esc(x.dataset.lt || x.dataset.lid) + '</button>').join(' ') : '') +
				' <button class="cpx-btn ghost sm" data-lreset>Alaphelyzet</button> <button class="cpx-btn sm" data-ldone>Kész</button></div>';
			bar.querySelectorAll('[data-show]').forEach(b => b.onclick = () => { const x = main.querySelector('[data-lid="' + b.dataset.show + '"]'); if (x) x.classList.remove('st-hid'); layoutSave(main); drawBar(); });
			bar.querySelector('[data-lreset]').onclick = () => { if (!confirm('Visszaállítod az alap elrendezést?')) return; try { localStorage.removeItem(LKEY); } catch (e) { /* privát mód */ } draw(); };
			bar.querySelector('[data-ldone]').onclick = () => { S.edit = false; draw(); };
		};
		drawBar();
		main.querySelectorAll('[data-lid]').forEach(x => {
			const ctl = document.createElement('div');
			ctl.className = 'st-ctl';
			ctl.innerHTML = '<button type="button" class="st-grip" aria-label="Áthelyezés" title="Húzd az áthelyezéshez">⠿</button><button type="button" class="st-x" aria-label="Elrejtés" title="Elrejtés">×</button>';
			x.appendChild(ctl);
			const rz = document.createElement('div');
			rz.className = 'st-rz';
			rz.title = 'Húzd a méretezéshez';
			x.appendChild(rz);
			ctl.querySelector('.st-x').onclick = () => { x.classList.add('st-hid'); layoutSave(main); drawBar(); };
			ctl.querySelector('.st-grip').addEventListener('pointerdown', e => dragStart(e, x, main));
			rz.addEventListener('pointerdown', e => resizeStart(e, x, main));
		});
	}
	function dragStart(e, item, main) {
		e.preventDefault();
		const c = item.parentNode;
		item.classList.add('st-dragging');
		let lastY = e.clientY, raf = 0;
		const scroll = () => { const m = 60; if (lastY < m) window.scrollBy(0, -12); else if (lastY > innerHeight - m) window.scrollBy(0, 12); raf = requestAnimationFrame(scroll); };
		raf = requestAnimationFrame(scroll);
		const move = ev => {
			lastY = ev.clientY;
			const t = document.elementFromPoint(ev.clientX, ev.clientY);
			const over = t && t.closest('[data-lid]');
			if (!over || over === item || over.parentNode !== c) return;
			const r = over.getBoundingClientRect();
			const sameRow = ev.clientY > r.top && ev.clientY < r.bottom;
			const before = sameRow ? ev.clientX < r.left + r.width / 2 : ev.clientY < r.top + r.height / 2;
			c.insertBefore(item, before ? over : over.nextSibling);
		};
		const up = () => {
			cancelAnimationFrame(raf);
			item.classList.remove('st-dragging');
			removeEventListener('pointermove', move);
			removeEventListener('pointerup', up);
			removeEventListener('pointercancel', up);
			layoutSave(main);
			if (item.querySelector('#st-trend')) drawTrend();
		};
		addEventListener('pointermove', move);
		addEventListener('pointerup', up);
		addEventListener('pointercancel', up);
	}
	function resizeStart(e, item, main) {
		e.preventDefault();
		const c = item.parentNode;
		const cs = getComputedStyle(c);
		const tracks = cs.gridTemplateColumns.split(' ').map(parseFloat).filter(n => !isNaN(n));
		const gap = parseFloat(cs.columnGap) || 0;
		const colW = (tracks[0] || 100) + gap;
		const x0 = e.clientX, w0 = item.getBoundingClientRect().width;
		item.classList.add('st-resizing');
		const move = ev => {
			const n = Math.min(tracks.length || 12, (w0 + (ev.clientX - x0) + gap) / colW);
			setSpan(item, n);
		};
		const up = () => {
			item.classList.remove('st-resizing');
			removeEventListener('pointermove', move);
			removeEventListener('pointerup', up);
			removeEventListener('pointercancel', up);
			layoutSave(main);
			drawTrend();
		};
		addEventListener('pointermove', move);
		addEventListener('pointerup', up);
		addEventListener('pointercancel', up);
	}

	/* ------------------------------------------------------------ trenddiagram (SVG) */
	function drawTrend() {
		const box = document.getElementById('st-trend');
		if (!box || !S.data) return;
		const tr = S.data.trend;
		const W = Math.max(300, Math.min(1200, box.clientWidth || 760)), H = W < 500 ? 200 : 230, pl = 30, pr = 6, pt = 10, pb = 24;
		const n = tr.length;
		const max = Math.max(1, ...tr.map(x => Math.max(x.l, x.w, S.yoy ? x.ly : 0, S.yoy ? x.wy : 0)));
		const step = niceStep(max);
		const top = Math.ceil(max / step) * step;
		const cw = (W - pl - pr) / n;
		const bw = Math.max(2, Math.min(22, cw * 0.36));
		const Y = v => pt + (H - pt - pb) * (1 - v / top);
		let s = '<svg class="st-chart" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Megkeresések és aláírt dealek trendje">';
		for (let v = 0; v <= top; v += step) s += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + Y(v) + '" y2="' + Y(v) + '" stroke="#EEE9E1"/><text x="' + (pl - 6) + '" y="' + (Y(v) + 4) + '" text-anchor="end">' + v + '</text>';
		const every = Math.ceil(n / (W < 500 ? 6 : 10));
		tr.forEach((x, i) => {
			const cx = pl + cw * i + cw / 2;
			const sel = i === S.sel;
			s += '<rect x="' + (pl + cw * i) + '" y="' + pt + '" width="' + cw + '" height="' + (H - pt - pb) + '" fill="' + (sel ? 'rgba(232,137,74,.08)' : 'transparent') + '" data-i="' + i + '"/>';
			s += '<rect x="' + (cx - bw - 1) + '" y="' + Y(x.l) + '" width="' + bw + '" height="' + (Y(0) - Y(x.l)) + '" rx="3" fill="#E8894A" pointer-events="none"/>';
			s += '<rect x="' + (cx + 1) + '" y="' + Y(x.w) + '" width="' + bw + '" height="' + (Y(0) - Y(x.w)) + '" rx="3" fill="#2E9E6A" pointer-events="none"/>';
			if (i % every === 0 || i === n - 1) s += '<text x="' + cx + '" y="' + (H - 8) + '" text-anchor="middle">' + esc(tickLabel(S.data.gran, x.f)) + '</text>';
		});
		if (S.yoy) {
			const pts = k => tr.map((x, i) => (pl + cw * i + cw / 2) + ',' + Y(x[k])).join(' ');
			s += '<polyline points="' + pts('ly') + '" fill="none" stroke="#C0682F" stroke-width="1.5" stroke-dasharray="4 3" pointer-events="none"/>';
			s += '<polyline points="' + pts('wy') + '" fill="none" stroke="#1E6B47" stroke-width="1.5" stroke-dasharray="4 3" pointer-events="none"/>';
		}
		s += '</svg>';
		box.innerHTML = s;
		const tip = document.getElementById('st-tip');
		const show = i => {
			const x = tr[i];
			S.sel = i;
			tip.innerHTML = '<b>' + esc(periodLabel(S.data.gran, x.f, x.t)) + '</b>: ' + x.l + ' megkeresés, ' + x.w + ' aláírt' + (S.yoy ? ' · tavaly: ' + x.ly + ' / ' + x.wy : '');
			box.querySelectorAll('rect[data-i]').forEach(r => r.setAttribute('fill', +r.dataset.i === i ? 'rgba(232,137,74,.08)' : 'transparent'));
		};
		box.querySelectorAll('rect[data-i]').forEach(r => { r.addEventListener('mouseenter', () => show(+r.dataset.i)); r.addEventListener('click', () => show(+r.dataset.i)); });
		show(S.sel >= 0 && S.sel < n ? S.sel : n - 1);
	}

	function niceStep(max) {
		const raw = max / 4;
		const p = Math.pow(10, Math.floor(Math.log10(raw)));
		const m = raw / p;
		return Math.max(1, (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p);
	}

	function tickLabel(g, f) {
		const d = pd(f);
		if (g === 'day') return d.getDate() + '';
		if (g === 'week') return md(f);
		if (g === 'month') return HU_MS[d.getMonth()] + (d.getMonth() === 0 ? ' ' + String(d.getFullYear()).slice(2) : '');
		if (g === 'quarter') return String(d.getFullYear()).slice(2) + '/' + (Math.floor(d.getMonth() / 3) + 1) + 'n';
		return d.getFullYear() + '';
	}

	/* ------------------------------------------------------------ GA4 */
	function drawGa() {
		const box = document.getElementById('st-ga');
		if (!box) return;
		const g = S.ga;
		let h = '<div class="st-card-h"><h3>Weboldal-forgalom (GA4)</h3><small>Az időszakos adatokat a Google néhány órás késéssel frissíti.</small></div>';
		if (!g) { box.innerHTML = h + '<div class="st-sub">Betöltés…</div>'; return; }
		if (g.off) { box.innerHTML = h + '<div class="st-miss">A GA4 még nincs összekötve: a WordPress admin → Beállítások → Crazy statisztikák oldalon add meg a tulajdonokat (EventCrazy, Crazyphoto) és töltsd fel a szolgáltatásfiók JSON-kulcsát.</div>'; return; }
		if (g.err) { box.innerHTML = h + '<div class="st-warn err">' + esc(g.err) + '</div>'; return; }
		const live = (g.live && g.live.sites) || [];
		const per = (g.period && g.period.sites) || [];
		const names = live.map(x => x.name);
		per.forEach(x => { if (!names.includes(x.name)) names.push(x.name); });
		const sum = (arr, k, sub) => arr.reduce((a, x) => a + ((sub ? (x[sub] || {})[k] : x[k]) || 0), 0);
		const okLive = live.filter(x => !x.err), okPer = per.filter(x => !x.err && x.cur);
		h += '<div class="st-live"><div><div class="st-sub"><span class="pulse"></span>Most (utolsó 30 perc' + (names.length > 1 ? ', összesen' : '') + ')</div><span class="st-num">' + num(sum(okLive, 'users')) + '</span> <span class="st-sub">aktív felhasználó</span> · <span class="st-num">' + num(sum(okLive, 'views')) + '</span> <span class="st-sub">oldalmegtekintés</span></div>';
		if (okPer.length) {
			[['views', 'Oldalmegtekintés'], ['sessions', 'Munkamenet'], ['users', 'Felhasználó']].forEach(m => {
				const c = sum(okPer, m[0], 'cur'), pv = okPer.every(x => x.prev) ? sum(okPer, m[0], 'prev') : null, yv = okPer.every(x => x.yoy) ? sum(okPer, m[0], 'yoy') : null;
				h += '<div><div class="st-sub">' + m[1] + (names.length > 1 ? ' (összesen)' : '') + '</div><span class="st-num">' + num(c) + '</span><div class="st-sub">előző: ' + (pv != null ? delta(c, pv) : '–') + ' · tavaly: ' + (yv != null ? delta(c, yv) : '–') + '</div></div>';
			});
		}
		h += '</div>';
		if (names.length > 1 || live.some(x => x.err) || per.some(x => x.err)) {
			h += '<div class="st-tw" style="margin-top:12px"><table class="st-t"><thead><tr><th>Oldal</th><th class="r">Aktív most</th><th class="r">Oldalmegt. most</th><th class="r">Oldalmegtekintés</th><th class="r">Munkamenet</th><th class="r">Felhasználó</th></tr></thead><tbody>';
			names.forEach(n => {
				const l = live.find(x => x.name === n) || {}, p = per.find(x => x.name === n) || {};
				if (l.err || p.err) { h += '<tr><td>' + esc(n) + '</td><td colspan="5" class="st-bad">' + esc(l.err || p.err) + '</td></tr>'; return; }
				const c = p.cur || {};
				h += '<tr><td>' + esc(n) + '</td><td class="r">' + num(l.users) + '</td><td class="r">' + num(l.views) + '</td><td class="r">' + num(c.views) + '</td><td class="r">' + num(c.sessions) + '</td><td class="r">' + num(c.users) + '</td></tr>';
			});
			h += '</tbody></table></div>';
		}
		const pages = [];
		live.forEach(x => (x.pages || []).forEach(p => pages.push([(names.length > 1 ? x.name + ': ' : '') + (p[0] || '(nincs cím)'), p[1]])));
		pages.sort((a, b) => b[1] - a[1]);
		if (pages.length) h += '<div class="st-sub" style="margin-top:10px">Legnézettebb most: ' + pages.slice(0, 6).map(x => esc(x[0]) + ' (' + num(x[1]) + ')').join(' · ') + '</div>';
		box.innerHTML = h;
	}

	/* ------------------------------------------------------------ kártyák */
	const chName = (L, k) => '<span class="st-dot" style="background:' + (CH_COL[k] || '#ccc') + '"></span>' + esc(L[k] || k);
	const card = (title, sub, body, wide) => { const t = String(title).replace(/<[^>]+>/g, ''); return '<div class="cpx-card' + (wide ? ' wide' : '') + '" data-lid="c-' + esc(t.toLowerCase().replace(/^\d+\.\s*/, '').replace(/[^a-z0-9áéíóöőúüű]+/g, '-').slice(0, 40)) + '" data-lt="' + esc(t) + '"><div class="st-card-h"><h3>' + title + '</h3>' + (sub ? '<small>' + sub + '</small>' : '') + '</div>' + body + '</div>'; };
	const miss = t => '<div class="st-miss">' + t + '</div>';
	const paid = k => k === 'google' || k === 'meta' || k === 'linkedin';

	function costMiss(d) {
		const m = [];
		if (d.miss.includes('cost_google')) m.push('Google Ads (a szkript még nem küldött adatot)');
		if (d.miss.includes('cost_meta')) m.push('Meta (nincs megadva token / hirdetési fiók)');
		if (d.miss.includes('cost_linkedin')) m.push('LinkedIn (nincs Windsor.ai-kulcs / adat és kézi tétel sem)');
		return m.length ? miss('Hiányzó költségadat: ' + m.join(', ') + '. Ezeknél a csatornáknál a költségmutató nem számolható.') : '';
	}

	function srcMiss(d) {
		const c = d.srcCover;
		const t = [];
		if (c.nosource) t.push(c.nosource + ' megkeresésnél nincs forrás');
		if (c.ads) t.push(c.ads + ' megkeresés csak „Ads” forrású (nem tudni, melyik hirdetési rendszer)');
		if (c.n && c.utm < c.n) t.push('UTM csak ' + c.utm + ' / ' + c.n + ' megkeresésnél van (a kontakt UTM-mezőit az űrlapok nemrég kezdték tölteni) – UTM nélkül a webes megkeresés „Organikus / weboldal” lesz, akkor is, ha hirdetésből jött');
		return t.length ? miss(t.join('; ') + '. Ezek külön sorban szerepelnek, becslés nélkül.') : '';
	}

	function cardCpl(d, L) {
		const rows = d.channels.filter(c => c.leads || c.spend);
		let b = '<div class="st-tw"><table class="st-t"><thead><tr><th>Csatorna</th><th class="r">Megkeresés</th><th class="r">Költés</th><th class="r">Költség / megkeresés</th></tr></thead><tbody>';
		let tl = 0, ts = 0;
		rows.forEach(c => {
			tl += c.leads; ts += c.spend || 0;
			b += '<tr><td>' + chName(L, c.k) + '</td><td class="r">' + num(c.leads) + '</td><td class="r">' + (paid(c.k) ? ft(c.spend) : '–') + '</td><td class="r">' + (paid(c.k) && c.spend && c.leads ? ft(c.spend / c.leads) : '–') + '</td></tr>';
		});
		b += '<tr class="tot"><td>Összesen</td><td class="r">' + num(tl) + '</td><td class="r">' + ft(ts) + '</td><td class="r">' + (ts && tl ? ft(ts / tl) : '–') + '</td></tr></tbody></table></div>';
		if (!rows.length) b = '<div class="st-sub">Ebben az időszakban nem volt megkeresés.</div>';
		return card('1. Költség / megkeresés csatornánként', 'a periódusban beérkezett megkeresések', b + costMiss(d) + srcMiss(d));
	}

	/* platformonkénti hirdetési mutatók a kiválasztott időszakra (lead = a platform saját konverziója; LinkedIn: one-click lead) */
	function cardAds(d, L) {
		const sd = d.spendDetail || {};
		const ks = ['google', 'meta', 'linkedin'].filter(k => sd[k]);
		if (!ks.length) return card('Hirdetési platformok', '', '<div class="st-sub">Ebben az időszakban nincs hirdetési adat.</div>' + costMiss(d), true);
		const dec = n => n == null ? '–' : (Math.round(n * 10) / 10).toString().replace('.', ',');
		const eur = n => (Math.round(n * 100) / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' €';
		let b = '<div class="st-tw"><table class="st-t"><thead><tr><th>Platform</th><th class="r">Költés</th><th class="r">Megjelenés</th><th class="r">Kattintás</th><th class="r">CTR</th><th class="r">Lead</th><th class="r">CPL</th></tr></thead><tbody>';
		ks.forEach(k => {
			const x = sd[k];
			b += '<tr><td>' + chName(L, k) + '</td><td class="r">' + ft(x.spend) + (x.eur ? '<br><small class="st-sub">' + eur(x.eur) + '</small>' : '') + '</td><td class="r">' + num(x.impr) + '</td><td class="r">' + num(x.clicks) + '</td><td class="r">' + (x.impr ? dec(x.clicks / x.impr * 100) + '%' : '–') + '</td><td class="r">' + dec(x.conv) + '</td><td class="r">' + (x.conv ? ft(x.spend / x.conv) : '–') + '</td></tr>';
		});
		b += '</tbody></table></div>';
		const li = d.sync && d.sync.li;
		b += '<div class="st-sub" style="margin-top:8px">Lead = a hirdetési rendszer saját lead-konverziója (LinkedIn: one-click lead űrlap), nem a Salesmate-megkeresés. CPL = költés / lead.' + (li && sd.linkedin && sd.linkedin.eur ? ' A LinkedIn EUR-ban költ, forintra ' + dec(li.rate) + ' Ft/EUR árfolyamon számolva.' : '') + '</div>';
		return card('Hirdetési platformok', 'költés, megjelenés, kattintás, lead a kiválasztott időszakban', b + costMiss(d), true);
	}

	function cardLead(d) {
		const x = d.lead;
		if (!x.n) return card('2. Foglalási előidő', '', '<div class="st-sub">Nincs rendezvénydátummal rendelkező megkeresés ebben az időszakban.</div>');
		const bmax = Math.max(1, ...Object.values(x.buckets));
		let b = '<div><span class="st-num">' + num(x.med) + ' nap</span> <span class="st-sub">medián · átlag ' + num(x.avg) + ' nap · ' + x.n + ' megkeresés</span></div>';
		b += '<div class="st-bars" style="margin:10px 0">' + Object.keys(x.buckets).map(k => '<div class="st-bar"><span class="t">' + k + '</span><span class="b"><span style="width:' + (x.buckets[k] / bmax * 100) + '%"></span></span><span class="v">' + x.buckets[k] + '</span></div>').join('') + '</div>';
		const tbl = (title, rows) => rows.length ? '<div class="st-tw"><table class="st-t"><thead><tr><th>' + title + '</th><th class="r">Db</th><th class="r">Medián</th><th class="r">Átlag</th></tr></thead><tbody>' + rows.map(r => '<tr><td>' + esc(r.k) + '</td><td class="r">' + r.n + '</td><td class="r">' + num(r.med) + ' nap</td><td class="r">' + num(r.avg) + ' nap</td></tr>').join('') + '</tbody></table></div>' : '';
		b += tbl('Szolgáltatás', x.svc) + tbl('Rendezvénytípus', x.type) + tbl('Ügyfél', x.ctype);
		if (d.miss.includes('evtype')) b += miss('Rendezvénytípus csak ' + x.typed + ' / ' + x.n + ' megkeresésnél van (a kontakt „rendezvény típusa” mezője – jelenleg a Facebook-leadek töltik). A többi forrásnál ezt is rögzíteni kell.');
		return card('2. Foglalási előidő', 'hány nappal a rendezvény előtt jön a megkeresés', b);
	}

	function cardReact(d, L) {
		const x = d.react;
		let b = '';
		if (!x.n) b = '<div class="st-sub">Nincs mérhető válasz ebben az időszakban.</div>';
		else {
			b = '<div><span class="st-num">' + hrs(x.med) + '</span> <span class="st-sub">medián · ' + x.n + ' / ' + x.of + ' megkeresésnél mérhető</span></div>';
			b += '<div class="st-bars" style="margin:10px 0">' + [[1, '1 órán belül'], [4, '4 órán belül'], [24, '24 órán belül']].map(w => '<div class="st-bar"><span class="t">' + w[1] + '</span><span class="b"><span class="g" style="width:' + (x.within[w[0]] / x.n * 100) + '%"></span></span><span class="v">' + pct(x.within[w[0]], x.n) + '</span></div>').join('') + '</div>';
			if (x.ch.length) b += '<div class="st-tw"><table class="st-t"><thead><tr><th>Csatorna</th><th class="r">Mért</th><th class="r">Medián</th></tr></thead><tbody>' + x.ch.map(r => '<tr><td>' + chName(L, r.k) + '</td><td class="r">' + r.n + '</td><td class="r">' + hrs(r.med) + '</td></tr>').join('') + '</tbody></table></div>';
		}
		b += miss('Az első kimenő e-mail a Salesmate API-n nem kérhető le visszamenőleg: a panel ' + (x.mailFrom ? 'a szinkron indulása (' + esc(x.mailFrom.slice(0, 10).replace(/-/g, '.')) + '.) óta' : 'a szinkron indulásától') + ' óránként figyeli a kontakt „utolsó küldött e-mail” idejét (az automatikus e-mailek is beszámíthatnak). Korábbra csak a lezárt aktivitások és a rögzített hívások számítanak – az aktivitásokat a Salesmate-ben ritkán zárjátok le, ezért ott kevés a mérhető adat.');
		return card('3. Reakcióidő', 'megkeresés → első kimenő e-mail, hívás vagy aktivitás', b);
	}

	function cardRoas(d, L) {
		const rows = d.channels.filter(c => c.won || c.spend || c.leads);
		let tw = 0, tv = 0, ts = 0;
		let b = '<div class="st-tw"><table class="st-t"><thead><tr><th>Csatorna</th><th class="r">Aláírt</th><th class="r">Aláírt érték</th><th class="r">Költség / aláírt</th><th class="r">ROAS</th></tr></thead><tbody>';
		rows.forEach(c => {
			tw += c.won; tv += c.value; ts += c.spend || 0;
			b += '<tr><td>' + chName(L, c.k) + '</td><td class="r">' + c.won + '</td><td class="r">' + ft(c.value) + '</td><td class="r">' + (paid(c.k) && c.spend && c.won ? ft(c.spend / c.won) : '–') + '</td><td class="r">' + (paid(c.k) && c.spend ? (Math.round(c.value / c.spend * 10) / 10).toString().replace('.', ',') + '×' : '–') + '</td></tr>';
		});
		b += '<tr class="tot"><td>Összesen</td><td class="r">' + tw + '</td><td class="r">' + ft(tv) + '</td><td class="r">' + (ts && tw ? ft(ts / tw) : '–') + '</td><td class="r">' + (ts ? (Math.round(tv / ts * 10) / 10).toString().replace('.', ',') + '×' : '–') + '</td></tr></tbody></table></div>';
		return card('4. Költség / aláírt deal és megtérülés', 'a periódusban aláírt dealek; ROAS = aláírt érték / költés', b + costMiss(d));
	}

	function cardFunnel(d, L) {
		const f = d.funnel;
		const a = f.all;
		if (!a.leads) return card('5. Tölcsér', '', '<div class="st-sub">Nincs megkeresés ebben az időszakban.</div>');
		let b = '<div class="st-fun">' + [['Megkeresés', a.leads], ['Ajánlat', a.offer], ['Aláírt', a.won]].map(x => '<div><span>' + x[0] + '</span><span class="b" style="width:' + Math.max(2, x[1] / a.leads * 100) + '%"></span><span class="r">' + x[1] + ' · ' + pct(x[1], a.leads) + '</span></div>').join('') + '</div>';
		b += '<div class="st-sub" style="margin-bottom:8px">Nyitott: ' + a.open + ' · elvesztett: ' + a.lost + '. A periódusban beérkezett megkeresések mostani állapota.</div>';
		const tbl = (title, rows, fmtK) => '<div class="st-tw"><table class="st-t"><thead><tr><th>' + title + '</th><th class="r">Megk.</th><th class="r">Ajánlat</th><th class="r">Aláírt</th><th class="r">Nyerési arány</th></tr></thead><tbody>' + rows.map(r => '<tr><td>' + fmtK(r.k) + '</td><td class="r">' + r.leads + '</td><td class="r">' + r.offer + '</td><td class="r">' + r.won + '</td><td class="r">' + pct(r.won, r.leads) + '</td></tr>').join('') + '</tbody></table></div>';
		b += tbl('Csatorna', f.ch, k => chName(L, k)) + '<div style="height:8px"></div>' + tbl('Szolgáltatás', f.svc, esc);
		return card('5. Tölcsér', 'megkeresés → ajánlat → aláírt', b);
	}

	function cardMix(d) {
		const m = d.mix;
		const rq = Object.entries(m.req);
		const rmax = Math.max(1, ...rq.map(x => x[1]));
		let b = '<h4 style="margin:0 0 6px">Mit kérnek</h4>';
		b += rq.length ? '<div class="st-bars">' + rq.map(x => '<div class="st-bar"><span class="t">' + esc(x[0]) + '</span><span class="b"><span style="width:' + (x[1] / rmax * 100) + '%"></span></span><span class="v">' + x[1] + '</span></div>').join('') + '</div>' : '<div class="st-sub">–</div>';
		if (m.reqCover[1] && m.reqCover[0] < m.reqCover[1]) b += '<div class="st-sub" style="margin-top:4px">A kért szolgáltatás ' + m.reqCover[0] + ' / ' + m.reqCover[1] + ' megkeresésnél van kitöltve.</div>';
		b += '<h4 style="margin:14px 0 6px">Mit nyernek meg</h4>';
		b += m.won.length ? '<div class="st-tw"><table class="st-t"><thead><tr><th>Szolgáltatás</th><th class="r">Aláírt</th><th class="r">Átlagos dealérték</th></tr></thead><tbody>' + m.won.map(r => '<tr><td>' + esc(r.k) + '</td><td class="r">' + r.n + '</td><td class="r">' + (r.vn ? ft(r.value / r.vn) : '–') + '</td></tr>').join('') + '</tbody></table></div>' : '<div class="st-sub">Nincs aláírt deal ebben az időszakban.</div>';
		b += '<div class="st-sub" style="margin-top:8px">Átlagos aláírt dealérték: <b>' + ft(m.avg) + '</b> · 2+ szolgáltatásos: <b>' + pct(m.multi, m.wonN) + '</b> (' + m.multi + ' / ' + m.wonN + ')</div>';
		const split = (t, x) => {
			const n = x.biz + x.priv + x.none;
			if (!n) return '';
			return '<div style="margin-top:10px"><div class="st-sub">' + t + ': céges ' + pct(x.biz, n) + ' · magán ' + pct(x.priv, n) + (x.none ? ' · nincs megadva ' + pct(x.none, n) : '') + '</div><div class="st-split"><span style="width:' + (x.biz / n * 100) + '%;background:#4C7BD9"></span><span style="width:' + (x.priv / n * 100) + '%;background:#E8894A"></span><span style="width:' + (x.none / n * 100) + '%;background:#D2CBC0"></span></div></div>';
		};
		b += split('Megkeresések', m.biz.lead) + split('Aláírt dealek', m.biz.won);
		b += '<div class="st-sub" style="margin-top:4px">Céges / magán: a „Szerződés EV/KFT” mező (KFT = céges, EV = magán).</div>';
		return card('6. Szolgáltatásmix', '', b);
	}

	function cardCap(d) {
		const c = d.cap;
		let b = '';
		if (!c.top.length) b = '<div class="st-sub">Nincs aláírt rendezvény ebben az időszakban.</div>';
		else {
			b = '<div class="st-sub" style="margin-bottom:6px">Kapacitás: ' + c.capSelfie + ' fotóautomata, ' + c.capSpin + ' spinner · teltházas nap: <b>' + c.full + '</b></div>';
			b += '<div class="st-tw"><table class="st-t"><thead><tr><th>Nap</th><th class="r">Fotóautomata</th><th class="r">Spinner</th><th class="r">Egyéb</th><th class="r">Rendezvény</th></tr></thead><tbody>' + c.top.map(x => '<tr><td>' + dshort(x.d) + '</td><td class="r' + (x.selfie >= c.capSelfie ? ' st-bad' : '') + '">' + x.selfie + ' / ' + c.capSelfie + '</td><td class="r' + (x.spin >= c.capSpin ? ' st-bad' : '') + '">' + x.spin + ' / ' + c.capSpin + '</td><td class="r">' + (x.other || 0) + '</td><td class="r">' + x.n + '</td></tr>').join('') + '</tbody></table></div>';
		}
		b += d.miss.includes('busy') ? miss('Foglaltság miatt elutasított megkeresés nem mérhető: a Salesmate-ben nincs ilyen elvesztési ok. Vegyél fel egy „Foglalt – nincs szabad gép” okot (Beállítások → Deal → Lost reasons), és azt jelöld elvesztéskor.') : '<div class="st-sub" style="margin-top:8px">Foglaltság miatt elutasított megkeresés: <b>' + c.busy + '</b></div>';
		return card('7. Kapacitás', 'foglalt gépek a csúcsnapokon (rendezvénydátum szerint)', b);
	}

	function cardMargin(d) {
		const m = d.margin;
		let b = '';
		if (!m.rows.length) b = '<div class="st-sub">Nincs elszámolt (kifizetett) rendezvény ebben az időszakban.</div>';
		else {
			let tr = 0, tc = 0, tn = 0;
			b = '<div class="st-tw"><table class="st-t"><thead><tr><th>Szolgáltatás</th><th class="r">Db</th><th class="r">Bevétel</th><th class="r">Kezelői költség</th><th class="r">Fedezet</th><th class="r">%</th></tr></thead><tbody>' + m.rows.map(r => { tr += r.rev; tc += r.cost; tn += r.n; return '<tr><td>' + esc(r.k) + '</td><td class="r">' + r.n + '</td><td class="r">' + ft(r.rev) + '</td><td class="r">' + ft(r.cost) + '</td><td class="r">' + ft(r.rev - r.cost) + '</td><td class="r">' + pct(r.rev - r.cost, r.rev) + '</td></tr>'; }).join('') +
				'<tr class="tot"><td>Összesen</td><td class="r">' + tn + '</td><td class="r">' + ft(tr) + '</td><td class="r">' + ft(tc) + '</td><td class="r">' + ft(tr - tc) + '</td><td class="r">' + pct(tr - tc, tr) + '</td></tr></tbody></table></div>';
		}
		const notes = [];
		if (m.noel) notes.push(m.noel + ' lezajlott rendezvénynek nincs Elszámolás-adata');
		if (m.unpaid) notes.push(m.unpaid + ' rendezvénynél még nincs minden kezelő kifizetve');
		if (!m.paper) notes.push('a papír egységára nincs megadva (WP admin → Crazy statisztikák), ezért a papírköltség nincs levonva');
		if (m.aiNoCount) notes.push(m.aiNoCount + ' AI-szolgáltatásos rendezvénynél nincs rögzítve a képek / nyomatok száma (Elszámolás → Papírfogyasztás), ott az AI-költség hiányzik');
		b += '<div class="st-sub" style="margin-top:8px">Fedezet = dealérték − az Elszámolás fülön kifizetett kezelői összegek (bér, km, parkolás, jatt)' + (m.paper ? ' − papír (' + m.paper + ' Ft/db)' : '') + (m.ai ? ' − AI-képek (' + String(m.ai).replace('.', ',') + ' Ft/kép, AI-szolgáltatásnál)' : '') + '. Csak a teljesen kifizetett rendezvények számítanak.</div>';
		if (notes.length) b += miss(notes.join('; ') + '.');
		return card('8. Fedezet szolgáltatásonként', 'lezajlott, aláírt rendezvények (rendezvénydátum szerint)', b);
	}

	/* nyomtatás (Elszámolás: papírfogyasztás) */
	function cardPrint(d) {
		const o = d.ops, op = d.opsPrev;
		if (!o) return '';
		const p = o.print;
		const sm = s => s ? dshort(s) : '–';
		if (!p.events) return card('Nyomtatás', 'lezajlott rendezvények, az Elszámolás fül papírfogyasztása alapján', '<div class="st-sub">Ebben az időszakban nincs rögzített papírfogyasztás.</div>' + (o.since.print ? '<div class="st-sub">A papírfogyasztást ' + sm(o.since.print) + ' óta rögzítitek.</div>' : miss('A kezelői adatlapon / Elszámolás fülön még nincs papírfogyasztás rögzítve.')));
		let b = '<div><span class="st-num">' + num(p.total) + '</span> <span class="st-sub">nyomat · ' + p.events + ' rendezvény · átlag ' + num(p.total / p.events) + ' / rendezvény' + (p.hours ? ' · ' + num(p.total / p.hours) + ' / óra' : '') + '</span></div>';
		b += '<div class="st-sub">előző időszak: ' + (op ? delta(p.total, op.print.total) : '–') + (p.cost ? ' · papírköltség: <b>' + ft(p.cost) + '</b> (' + p.paper + ' Ft/db)' : '') + '</div>';
		const bars = (title, obj) => { const e = Object.entries(obj); if (!e.length) return ''; const mx = Math.max(...e.map(x => x[1])); return '<h4 style="margin:12px 0 6px">' + title + '</h4><div class="st-bars">' + e.slice(0, 8).map(x => '<div class="st-bar"><span class="t">' + esc(x[0]) + '</span><span class="b"><span style="width:' + (x[1] / mx * 100) + '%"></span></span><span class="v">' + num(x[1]) + '</span></div>').join('') + '</div>'; };
		const svc = {}; p.svc.forEach(x => { svc[x.k + ' (' + x.ev + ' rend.)'] = x.n; });
		b += bars('Szolgáltatásonként', svc) + bars('Nyomtatónként', p.printers) + bars('Eszközönként', p.dev);
		if (p.top.length) b += '<h4 style="margin:12px 0 6px">Legtöbb nyomat</h4><ul class="st-ul">' + p.top.map(x => '<li><a href="' + esc(C.sm + x.id + '/detail') + '" target="_blank" rel="noopener">' + esc(x.t) + '</a> <span class="st-sub">· ' + dshort(x.d) + '</span> <b>' + num(x.n) + '</b></li>').join('') + '</ul>';
		if (p.photo > p.events) b += miss((p.photo - p.events) + ' fotós rendezvénynél nincs rögzítve a papírfogyasztás (Elszámolás → Papírfogyasztás).');
		if (p.printers['nincs megadva'] && Object.keys(p.printers).length === 1) b += '<div class="st-sub" style="margin-top:6px">A nyomtatótípust nem töltik ki – ha kell nyomtatónkénti bontás, az Elszámolásban a „Nyomtató típusa” mezőt is érdemes kitölteni.</div>';
		b += '<div class="st-sub" style="margin-top:6px">Adat ' + sm(o.since.print) + ' óta.</div>';
		return card('Nyomtatás', 'lezajlott rendezvények, az Elszámolás fül papírfogyasztása alapján', b);
	}

	/* ügyfél-elégedettség + gépállapot (Elszámolás) */
	function cardSat(d) {
		const o = d.ops, op = d.opsPrev;
		if (!o) return '';
		const s = o.sat, g = o.gm;
		const col = { 'Elégedett': 'var(--ok)', 'Foglalkozós': 'var(--mid)', 'Elégedetlen': 'var(--full)', 'Jó': 'var(--ok)', 'HIBA': 'var(--full)' };
		let b = '';
		if (!s.rated) b += '<div class="st-sub">Ebben az időszakban nincs ügyfél-elégedettségi értékelés.' + (o.since.sat ? ' (' + dshort(o.since.sat) + ' óta rögzítitek.)' : '') + '</div>';
		else {
			const tot = s.rated;
			b += '<div><span class="st-num">' + s.pct + '%</span> <span class="st-sub">elégedett · ' + tot + ' értékelés · ' + s.ratedEvents + ' / ' + s.events + ' rendezvény értékelve' + (op && op.sat.pct != null ? ' · előző időszak: ' + op.sat.pct + '%' : '') + '</span></div>';
			b += '<div class="st-split">' + Object.entries(s.counts).map(x => '<span style="width:' + (x[1] / tot * 100) + '%;background:' + (col[x[0]] || '#9A8F80') + '" title="' + esc(x[0] + ': ' + x[1]) + '"></span>').join('') + '</div>';
			b += '<div class="st-sub">' + Object.entries(s.counts).map(x => esc(x[0]) + ': <b>' + x[1] + '</b>').join(' · ') + '</div>';
			if (s.bad.length) b += '<h4 style="margin:12px 0 6px">Nem „Elégedett” értékelések</h4><ul class="st-ul">' + s.bad.map(x => '<li><b>' + esc(x.v) + '</b> – <a href="' + esc(C.sm + x.id + '/detail') + '" target="_blank" rel="noopener">' + esc(x.t) + '</a> <span class="st-sub">· ' + dshort(x.d) + (x.note ? ' · „' + esc(x.note) + '”' : '') + '</span></li>').join('') + '</ul>';
			if (s.ratedEvents < s.events) b += miss((s.events - s.ratedEvents) + ' lezajlott rendezvénynél nincs elégedettségi értékelés (kezelői adatlap / Elszámolás → Ügyfél elégedettség).');
		}
		b += '<h4 style="margin:14px 0 6px">Gépállapot</h4>';
		const gt = Object.values(g.counts).reduce((a, x) => a + x, 0);
		if (!gt) b += '<div class="st-sub">Nincs gépállapot-bejegyzés ebben az időszakban.' + (o.since.gm ? ' (' + dshort(o.since.gm) + ' óta rögzítitek.)' : '') + '</div>';
		else {
			b += '<div class="st-sub">' + Object.entries(g.counts).map(x => '<span style="color:' + (col[x[0]] || 'var(--mid)') + ';font-weight:700">' + esc(x[0]) + '</span>: ' + x[1]).join(' · ') + '</div>';
			if (g.bad.length) b += '<ul class="st-ul">' + g.bad.map(x => '<li><b>' + esc(x.v) + '</b>' + (x.dev ? ' (' + esc(x.dev) + ')' : '') + ' – <a href="' + esc(C.sm + x.id + '/detail') + '" target="_blank" rel="noopener">' + esc(x.t) + '</a> <span class="st-sub">· ' + dshort(x.d) + (x.note ? ' · „' + esc(x.note) + '”' : '') + '</span></li>').join('') + '</ul>';
		}
		return card('Ügyfél-elégedettség és gépállapot', 'kezelői visszajelzések az Elszámolás fülről', b);
	}

	function cardHeat(d) {
		const m = d.heat.m;
		const max = Math.max(1, ...m.map(r => Math.max(...r)));
		let b = '<div class="st-tw"><div class="st-heat"><b></b>' + Array.from({ length: 24 }, (_, i) => '<em>' + (i % 3 === 0 ? i : '') + '</em>').join('');
		m.forEach((r, i) => {
			b += '<b>' + DOW[i] + '</b>' + r.map((v, hh) => '<span title="' + DOW[i] + ' ' + hh + ':00–' + hh + ':59 · ' + v + ' megkeresés" style="background:' + (v ? 'rgba(232,137,74,' + (0.15 + 0.85 * v / max).toFixed(2) + ')' : '#F3EFE8') + '"></span>').join('');
		});
		b += '</div></div><div class="st-sub" style="margin-top:6px">' + dshort(d.heat.from) + '–' + dshort(d.heat.to) + (d.gran === 'day' || d.gran === 'week' ? ' (napi / heti nézetben az utolsó 90 nap)' : '') + ' · a megkeresés beérkezésének ideje (Budapest).</div>';
		return card('9. Megkeresések hőtérképe', 'napok × órák', b, true);
	}

	function cardLi(d) {
		const list = (d.li || []).slice().sort((a, b) => b.from.localeCompare(a.from));
		let b = '<form class="st-li-form" id="st-li"><input type="hidden" name="id" value="">' +
			'<label>Kezdet<input type="date" name="lf" required></label><label>Vége<input type="date" name="lt" required></label>' +
			'<label>Kampány / megnevezés<input name="name" maxlength="120" placeholder="pl. LinkedIn – december"></label>' +
			'<label>Költés (Ft)<input name="amt" inputmode="numeric" required placeholder="pl. 150000"></label>' +
			'<label>Kattintás<input name="clicks" inputmode="numeric" placeholder="nem kötelező"></label>' +
			'<label>Megjelenés<input name="impr" inputmode="numeric" placeholder="nem kötelező"></label>' +
			'<label>Konverzió<input name="conv" inputmode="numeric" placeholder="nem kötelező"></label>' +
			'<button class="cpx-btn" type="submit">Mentés</button></form>';
		b += '<div class="st-sub" style="margin:6px 0 10px">Havi összegnél add meg a hónap első és utolsó napját; az összeg a napokra arányosan oszlik el. A kampány december 10-ig fut.' + (d.sync && d.sync.li && d.sync.li.on ? ' A Windsor.ai-ból lekért napokon a kézi tétel nem számít bele (nincs dupla költés).' : '') + '</div>';
		if (list.length) b += '<div class="st-tw"><table class="st-t"><thead><tr><th>Időszak</th><th>Megnevezés</th><th class="r">Költés</th><th class="r">Katt.</th><th></th></tr></thead><tbody>' + list.map(e => '<tr><td>' + dshort(e.from) + '–' + dshort(e.to) + '</td><td>' + esc(e.name) + '</td><td class="r">' + ft(e.amount) + '</td><td class="r">' + (e.clicks || '–') + '</td><td class="r"><button class="cpx-btn ghost sm" data-li-ed="' + esc(e.id) + '" aria-label="Szerkesztés">✎</button> <button class="cpx-btn ghost sm" data-li-del="' + esc(e.id) + '" aria-label="Törlés">×</button></td></tr>').join('') + '</tbody></table></div>';
		return card('LinkedIn – kézi költség', '', b, true);
	}

	function bindLi(main) {
		const f = main.querySelector('#st-li');
		if (!f) return;
		f.onsubmit = async e => {
			e.preventDefault();
			const btn = f.querySelector('button[type=submit]');
			btn.disabled = true;
			try {
				const p = {};
				new FormData(f).forEach((v, k) => { p[k] = v; });
				const r = await post('cp_st_li', p);
				S.data.li = r.li;
				await reload(false);
			} catch (err) { alert(err.message); }
			btn.disabled = false;
		};
		main.querySelectorAll('[data-li-del]').forEach(b => b.onclick = async () => {
			if (!confirm('Biztosan törlöd ezt a LinkedIn-tételt?')) return;
			try { await post('cp_st_li', { del: b.dataset.liDel }); await reload(false); } catch (err) { alert(err.message); }
		});
		main.querySelectorAll('[data-li-ed]').forEach(b => b.onclick = () => {
			const e = (S.data.li || []).find(x => x.id === b.dataset.liEd);
			if (!e) return;
			f.elements.id.value = e.id; f.elements.lf.value = e.from; f.elements.lt.value = e.to; f.elements.name.value = e.name;
			f.elements.amt.value = e.amount; f.elements.clicks.value = e.clicks || ''; f.elements.impr.value = e.impr || ''; f.elements.conv.value = e.conv || '';
			f.scrollIntoView({ behavior: 'smooth', block: 'center' });
		});
	}

	function cardSources(d) {
		const y = d.sync;
		const t = ts => ts ? (() => { const x = new Date(ts * 1000); return x.getFullYear() + '.' + pad(x.getMonth() + 1) + '.' + pad(x.getDate()) + '. ' + pad(x.getHours()) + ':' + pad(x.getMinutes()); })() : '–';
		const sp = d.spendInfo || {};
		const it = (name, ok, txt) => '<div><b class="' + (ok === true ? 'st-ok' : ok === false ? 'st-bad' : 'st-off') + '">' + name + '</b>' + txt + '</div>';
		let b = '<div class="st-src">' +
			it('Salesmate', y.err ? false : !!y.last, y.err ? esc(y.err) : 'utolsó szinkron: ' + t(y.last) + ' · ' + num(y.deals) + ' deal') +
			it('Google Ads', y.gads.at ? true : null, y.gads.at ? 'utolsó adat: ' + t(y.gads.at) : (y.gads.on ? 'kulcs kész, a szkript még nem küldött adatot' : 'nincs beállítva')) +
			it('Meta', y.meta.err ? false : (y.meta.ok ? true : null), y.meta.err ? esc(y.meta.err) : (y.meta.ok ? 'utolsó lekérés: ' + t(y.meta.ok) : (y.meta.on ? 'beállítva, lekérésre vár' : 'nincs beállítva'))) +
			it('LinkedIn', y.li && y.li.err ? false : (y.li && y.li.ok ? true : (sp.linkedin ? true : null)), y.li && y.li.err ? esc(y.li.err) : (y.li && y.li.ok ? 'Windsor.ai, utolsó lekérés: ' + t(y.li.ok) : (y.li && y.li.on ? 'Windsor.ai beállítva, lekérésre vár' : (sp.linkedin ? 'kézi tételek: ' + dshort(sp.linkedin.f) + '–' + dshort(sp.linkedin.t) : 'nincs beállítva')))) +
			it('GA4', y.ga ? true : null, y.ga ? 'összekötve' : 'nincs beállítva') +
			'</div>';
		if (C.st && C.st.admin) b += '<div class="st-sub" style="margin-top:8px">A kulcsokat és tokeneket a WordPress adminban lehet megadni: Beállítások → Crazy statisztikák (csak adminnak).</div>';
		return card('Adatforrások', '', b, true);
	}

	let rz = 0;
	window.addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(() => { if (document.getElementById('st-trend')) drawTrend(); }, 200); });

	window.CPStat = { view, status, refresh: () => reload(true) };
})();
