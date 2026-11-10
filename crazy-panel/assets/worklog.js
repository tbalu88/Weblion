/* Crazy panel – Munkanapló (v2.3): napi munkanapló fotókkal, költségekkel, útnyilvántartással; havi összesítő */
(function () {
	'use strict';
	const C = window.CPX || {};
	const W = C.wl || {};
	if (!W.on) return;
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const pad = n => String(n).padStart(2, '0');
	const ymd = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	const today = () => ymd(new Date());
	const addDays = (s, n) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + n); return ymd(d); };
	const DOW = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
	const MON = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
	const huDay = s => { const d = new Date(s + 'T12:00:00'); return d.getFullYear() + '. ' + MON[d.getMonth()] + ' ' + d.getDate() + '., ' + DOW[d.getDay()]; };
	const ft = v => Math.round(Number(v) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft';
	const hfmt = h => { h = Number(h) || 0; const m = Math.round(h * 60); return Math.floor(m / 60) + ' ó ' + pad(m % 60) + ' p'; };
	const nowHM = () => { const d = new Date(); return pad(d.getHours()) + ':' + pad(d.getMinutes()); };
	const PAY = { kp: 'készpénz', kartya: 'saját kártya', ceges: 'céges kártya', atutalas: 'átutalás' };
	const ST = { draft: ['folyamatban', 'd'], submitted: ['lezárva – jóváhagyásra vár', 's'], approved: ['jóváhagyva', 'a'] };
	const S = { uid: W.main || W.me, day: today(), mode: 'day', e: null, sug: [], prev: null, main: null, saving: 0, seq: 0, dirty: {}, t: null, m: today().slice(0, 7) };
	try { const k = JSON.parse(localStorage.getItem('cpx_wl') || '{}'); if (k.uid && (W.users || []).some(u => u.id === k.uid)) S.uid = k.uid; } catch (e) { /* privát mód */ }

	async function post(action, params, file) {
		const fd = new FormData(); fd.append('action', action); fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k]));
		if (file) fd.append('file', file, file.name || 'kep.jpg');
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j; try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j && j.data && j.data.msg) || 'Hiba'));
		return j.data;
	}
	function toast(t, bad) { let d = document.getElementById('cpx-toast'); if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); } d.textContent = t; d.classList.toggle('bad', !!bad); d.classList.add('on'); clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), bad ? 6000 : 3000); }
	const fileUrl = (id, thumb, dl) => C.ajax + '?action=cp_wl_file&id=' + id + (thumb ? '&t=1' : '') + (dl ? '&dl=1' : '');
	const mine = () => S.uid === W.me;
	const editable = () => mine() && (!S.e || S.e.status !== 'approved');
	const uname = id => ((W.users || []).find(u => u.id === id) || {}).n || '';

	/* kép kicsinyítése a böngészőben (max 1920 px, JPEG) – gyorsabb feltöltés mobilnetről is */
	async function shrink(file) {
		try {
			if (!/^image\//.test(file.type) || file.size < 400 * 1024) return file;
			const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
			const k = Math.min(1, 1920 / Math.max(bmp.width, bmp.height));
			const cv = document.createElement('canvas'); cv.width = Math.round(bmp.width * k); cv.height = Math.round(bmp.height * k);
			cv.getContext('2d').drawImage(bmp, 0, 0, cv.width, cv.height);
			const blob = await new Promise(r => cv.toBlob(r, 'image/jpeg', 0.84));
			if (!blob || blob.size >= file.size) return file;
			return new File([blob], (file.name || 'kep').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
		} catch (e) { return file; }
	}

	function view(main) { S.main = main; draw(); }
	function open(day) { if (/^\d{4}-\d{2}-\d{2}$/.test(day || '')) { S.day = day; S.mode = 'day'; } if (window.CPXGo) window.CPXGo('munkanaplo'); else if (S.main) draw(); }
	function status(s) { s.textContent = S.saving ? 'Mentés…' : (S.e && S.e.updated_at ? 'Mentve: ' + String(S.e.updated_at).slice(11, 16) : ''); s.className = 'cpx-status'; }
	const setStatus = () => { const s = document.querySelector('.cpx-status'); if (s && window.CPXStatus) window.CPXStatus(); };

	function head() {
		const us = W.users || [];
		return '<div class="wl-h"><div class="crm-seg"><button type="button" data-md="day" class="' + (S.mode === 'day' ? 'on' : '') + '">Nap</button><button type="button" data-md="month" class="' + (S.mode === 'month' ? 'on' : '') + '">Hónap</button></div>' +
			(us.length > 1 ? '<select data-uid aria-label="Kinek a naplója">' + us.map(u => '<option value="' + u.id + '"' + (u.id === S.uid ? ' selected' : '') + '>' + esc(u.n) + (u.id === W.me ? ' (én)' : '') + '</option>').join('') + '</select>' : '') +
			(S.mode === 'day' ? '<div class="wl-nav"><button type="button" class="cpx-btn ghost" data-dd="-1" aria-label="Előző nap">‹</button><input type="date" data-day value="' + S.day + '"><button type="button" class="cpx-btn ghost" data-dd="1" aria-label="Következő nap">›</button>' + (S.day !== today() ? '<button type="button" class="cpx-btn ghost" data-today>Ma</button>' : '') + '</div>' :
				'<div class="wl-nav"><button type="button" class="cpx-btn ghost" data-mm="-1">‹</button><b>' + S.m.slice(0, 4) + '. ' + MON[Number(S.m.slice(5)) - 1] + '</b><button type="button" class="cpx-btn ghost" data-mm="1">›</button></div>') + '</div>';
	}
	function bindHead(main) {
		main.querySelectorAll('[data-md]').forEach(b => b.addEventListener('click', () => { S.mode = b.dataset.md; if (S.mode === 'month') S.m = S.day.slice(0, 7); draw(); }));
		const us = main.querySelector('[data-uid]'); if (us) us.addEventListener('change', () => { flush(); S.uid = Number(us.value); try { localStorage.setItem('cpx_wl', JSON.stringify({ uid: S.uid })); } catch (e) { /* privát mód */ } draw(); });
		main.querySelectorAll('[data-dd]').forEach(b => b.addEventListener('click', () => { flush(); S.day = addDays(S.day, Number(b.dataset.dd)); draw(); }));
		const di = main.querySelector('[data-day]'); if (di) di.addEventListener('change', () => { if (di.value) { flush(); S.day = di.value; draw(); } });
		const td = main.querySelector('[data-today]'); if (td) td.addEventListener('click', () => { flush(); S.day = today(); draw(); });
		main.querySelectorAll('[data-mm]').forEach(b => b.addEventListener('click', () => { const d = new Date(S.m + '-15T12:00:00'); d.setMonth(d.getMonth() + Number(b.dataset.mm)); S.m = d.getFullYear() + '-' + pad(d.getMonth() + 1); draw(); }));
	}

	async function draw() {
		const main = S.main; if (!main || !main.isConnected) return;
		main.innerHTML = '<div class="wl">' + head() + '<div class="wl-b"><div class="cpx-empty">Betöltés…</div></div></div>';
		bindHead(main);
		const b = main.querySelector('.wl-b');
		/* v2.9.58: kérés-sorszám – gyors napváltásnál a régebbi (elavult) válasz nem írhatja felül az újat */
		const seq = ++S.seq;
		try {
			if (S.mode === 'month') { const r = await post('cp_wl_month', { uid: S.uid, m: S.m }); if (seq === S.seq && main.isConnected) drawMonth(b, r); return; }
			const r = await post('cp_wl_get', { uid: S.uid, day: S.day });
			if (seq !== S.seq) return;
			S.e = r.e; S.sug = r.sug || []; S.prev = r.prev; S.dirty = {};
			if (main.isConnected) drawDay(b);
		} catch (e) { if (seq === S.seq) b.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; }
	}

	/* ------------------------------------------------------------ nap */
	function drawDay(b) {
		const e = S.e || { t_start: '', t_end: '', shifts: [], break_min: 0, kinds: [], place: '', deal_list: [], tasks: [], notes: '', vehicle: '', km_start: null, km_end: null, km: null, costs: [], issue: '', issue_flag: 0, tomorrow: '', status: 'draft', files: [], comments: [], hours: 0 };
		const ed = editable(), ro = ed ? '' : ' disabled';
		const st = ST[e.status] || ST.draft;
		const photos = e.files.filter(f => f.kind === 'photo'), rcpt = e.files.filter(f => f.kind === 'receipt');
		const prevTom = S.prev && S.prev.tomorrow && !e.tasks.length && ed ? S.prev.tomorrow : '';
		const costSum = e.costs.reduce((a, c) => a + (Number(c.amount) || 0), 0);
		b.innerHTML =
			'<div class="wl-title"><h2>' + esc(huDay(S.day)) + '</h2><span class="wl-st ' + st[1] + '">' + st[0] + (e.status === 'approved' && e.approved_by ? ' · ' + esc(e.approved_by) : '') + '</span>' + (!mine() ? '<span class="wl-who">' + esc(uname(S.uid)) + ' naplója</span>' : '') + '</div>' +
			(!S.e && !ed ? '<div class="cpx-empty">Erre a napra nincs bejegyzés.</div>' : '') +
			'<div class="wl-grid">' +
			/* munkaidő */
			'<section class="wl-c"><h4>⏱ Munkaidő</h4><div class="wl-time"><label>Kezdés<input type="time" data-f="t_start" value="' + esc(e.t_start) + '"' + ro + '></label><label>Befejezés<input type="time" data-f="t_end" value="' + esc(e.t_end) + '"' + ro + '></label><label>Szünet (perc)<input type="number" min="0" max="600" step="5" data-f="break_min" value="' + (e.break_min || 0) + '"' + ro + '></label></div>' +
				(e.shifts || []).map((g, i) => '<div class="wl-time wl-seg" data-sg="' + i + '"><label>' + (i + 2) + '. kezdés<input type="time" data-sf="s" value="' + esc(g.s || '') + '"' + ro + '></label><label>' + (i + 2) + '. befejezés<input type="time" data-sf="e" value="' + esc(g.e || '') + '"' + ro + '></label>' + (ed ? '<button type="button" class="wl-sgx" data-sgx="' + i + '" aria-label="Szakasz törlése" title="Szakasz törlése">×</button>' : '<span></span>') + '</div>').join('') +
				(ed ? '<div class="wl-qb"><button type="button" class="cpx-btn ghost" data-now="s">▶ Most kezdem</button><button type="button" class="cpx-btn ghost" data-now="e">■ Most végeztem</button><button type="button" class="cpx-btn ghost" data-sgadd>＋ Újabb szakasz</button></div>' : '') +
				'<div class="wl-sum" data-hours>' + (e.hours ? 'Ledolgozott idő: <b>' + hfmt(e.hours) + '</b>' : '') + '</div></section>' +
			/* típus + helyszín + rendezvények */
			'<section class="wl-c"><h4>🧰 Mit csináltál ma?</h4><div class="wl-chips">' + (W.kinds || []).map(k => '<button type="button" class="wl-chip' + (e.kinds.includes(k) ? ' on' : '') + '" data-kind="' + esc(k) + '"' + ro + '>' + esc(k) + '</button>').join('') + '</div>' +
				'<label class="wl-l">Helyszín<input type="text" data-f="place" maxlength="255" placeholder="pl. raktár, Budapest – Bálna, ügyfél címe" value="' + esc(e.place) + '"' + ro + '></label>' +
				'<div class="wl-l">Rendezvények (deal-ek)<div class="wl-deals" data-deals>' + e.deal_list.map(d => '<span class="wl-dl"><a href="#deals:' + d.id + '" data-od="' + d.id + '">' + esc(d.title) + '</a>' + (ed ? '<button type="button" data-dx="' + d.id + '" aria-label="Eltávolítás">×</button>' : '') + '</span>').join('') + '</div>' +
				(ed && S.sug.filter(s => !e.deal_list.some(d => d.id === s.id)).length ? '<div class="wl-sug"><small>Aznapi rendezvények – koppints a hozzáadáshoz:</small>' + S.sug.filter(s => !e.deal_list.some(d => d.id === s.id)).map(s => '<button type="button" class="wl-chip" data-da="' + s.id + '" data-dt="' + esc(s.title) + '">＋ ' + esc(s.title) + '</button>').join('') + '</div>' : '') + '</div></section>' +
			/* feladatok */
			'<section class="wl-c wl-w"><h4>☑ Elvégzett feladatok</h4>' + (prevTom ? '<div class="wl-prev">Tegnapra (' + esc(S.prev.day) + ') ezt írtad holnapra:<p>' + esc(prevTom) + '</p><button type="button" class="cpx-btn ghost" data-ptake>Átveszem feladatlistának</button></div>' : '') +
				'<ul class="wl-tasks" data-tasks>' + e.tasks.map((t, i) => '<li><label><input type="checkbox" data-tk="' + i + '"' + (t.done ? ' checked' : '') + ro + '><span>' + esc(t.t) + '</span></label>' + (ed ? '<button type="button" data-tx="' + i + '" aria-label="Törlés">×</button>' : '') + '</li>').join('') + '</ul>' +
				(ed ? '<div class="wl-add"><input type="text" data-tnew maxlength="300" placeholder="Új feladat (Enter)"><button type="button" class="cpx-btn" data-tadd>Hozzáad</button></div>' : '') +
				'<label class="wl-l">Leírás, megjegyzés<textarea data-f="notes" rows="4" maxlength="8000" placeholder="Mi történt ma, mire figyeljünk…"' + ro + '>' + esc(e.notes) + '</textarea></label></section>' +
			/* fotók */
			'<section class="wl-c wl-w"><h4>📷 Fotók <small>' + (photos.length ? photos.length + ' db' : '') + '</small></h4><div class="wl-ph">' + photos.map(f => '<button type="button" class="wl-th" data-pv="' + f.id + '" title="' + esc(f.caption || f.name) + '"><img loading="lazy" src="' + fileUrl(f.id, 1) + '" alt="">' + (ed ? '<i data-fdel="' + f.id + '" title="Kép törlése">×</i>' : '') + '</button>').join('') +
				(ed ? '<label class="wl-up"><input type="file" accept="image/*" multiple data-upl="photo" hidden><span>＋<small>Fotó</small></span></label>' : '') + '</div>' + (ed ? '<small class="cpx-muted">Több képet is kijelölhetsz, a telefon kamerája is használható. A képeket a panel kicsinyíti, és csak belépve láthatók.</small>' : '') + '</section>' +
			/* útnyilvántartás */
			'<section class="wl-c"><h4>🚐 Útnyilvántartás</h4><label class="wl-l">Jármű<input type="text" data-f="vehicle" maxlength="100" placeholder="pl. Transit (ABC-123)" value="' + esc(e.vehicle || (ed && S.prev ? S.prev.vehicle || '' : '')) + '"' + (!e.vehicle && ed && S.prev && S.prev.vehicle ? ' data-pre="1"' : '') + ro + '></label>' +
				'<div class="wl-time"><label>Km-óra induláskor<input type="number" inputmode="numeric" data-f="km_start" value="' + (e.km_start == null ? (ed && S.prev && S.prev.km_end ? S.prev.km_end : '') : e.km_start) + '"' + (e.km_start == null && ed && S.prev && S.prev.km_end ? ' data-pre="1"' : '') + ro + '></label><label>Km-óra a végén<input type="number" inputmode="numeric" data-f="km_end" value="' + (e.km_end == null ? '' : e.km_end) + '"' + ro + '></label><label>Megtett km<input type="number" inputmode="numeric" data-f="km" value="' + (e.km == null ? '' : e.km) + '"' + ro + '></label></div>' +
				(ed && S.prev && S.prev.km_end && e.km_start == null ? '<small class="cpx-muted">Az induló állás az előző napi záró állás (' + S.prev.km_end + ').</small>' : '') + '</section>' +
			/* költségek */
			'<section class="wl-c"><h4>🧾 Költségek <small>' + (costSum ? ft(costSum) : '') + '</small></h4><div data-costs>' + e.costs.map((c, i) => costRow(c, i, ed, rcpt)).join('') + '</div>' +
				(ed ? '<button type="button" class="cpx-btn ghost" data-cadd>＋ Költség (tankolás, parkolás, anyag…)</button>' : '') + '</section>' +
			/* hiba */
			'<section class="wl-c"><h4>⚠ Hiba, eszközprobléma</h4><textarea data-f="issue" rows="2" maxlength="4000" placeholder="pl. a 3-as szelfigép nyomtatója akad"' + ro + '>' + esc(e.issue) + '</textarea>' +
				'<label class="wl-chk"><input type="checkbox" data-f="issue_flag"' + (e.issue_flag ? ' checked' : '') + ro + '> Jelezni kell – az adminok értesítést kapnak</label></section>' +
			/* holnapra */
			'<section class="wl-c"><h4>➡ Holnapra / teendők</h4><textarea data-f="tomorrow" rows="3" maxlength="4000" placeholder="Mit kell holnap megcsinálni, mit vigyél…"' + ro + '>' + esc(e.tomorrow) + '</textarea></section>' +
			'</div>' +
			/* állapot + hozzászólások */
			'<div class="wl-foot">' + footBtns(e) + '</div>' +
			'<section class="wl-c wl-w wl-cm"><h4>💬 Hozzászólások</h4>' + (e.comments.length ? e.comments.map(c => '<div class="wl-cmi"><b>' + esc(c.author) + '</b> <small>' + esc(String(c.at || '').slice(0, 16)) + '</small><p>' + esc(c.body) + '</p></div>').join('') : '<p class="cpx-muted">Még nincs hozzászólás.</p>') +
				'<div class="wl-add"><textarea data-cmt rows="2" maxlength="3000" placeholder="Hozzászólás, kérdés a naphoz…"></textarea><button type="button" class="cpx-btn" data-csend>Küldés</button></div></section>';
		bindDay(b);
	}
	function costRow(c, i, ed, rcpt) {
		const ro = ed ? '' : ' disabled', f = c.file ? rcpt.find(x => x.id === Number(c.file)) : null;
		return '<div class="wl-cost" data-ci="' + i + '"><input type="text" data-cf="what" maxlength="200" placeholder="Mire?" value="' + esc(c.what) + '"' + ro + '><input type="number" inputmode="numeric" data-cf="amount" placeholder="Ft" value="' + (c.amount || '') + '"' + ro + '>' +
			'<select data-cf="pay"' + ro + '>' + Object.keys(PAY).map(k => '<option value="' + k + '"' + (c.pay === k ? ' selected' : '') + '>' + PAY[k] + '</option>').join('') + '</select>' +
			(f ? '<button type="button" class="wl-rc" data-pv="' + f.id + '" title="Blokk megnyitása"><img src="' + fileUrl(f.id, 1) + '" alt=""></button>' : (ed ? '<label class="wl-rcu" title="Blokk fotója"><input type="file" accept="image/*" data-upl="receipt" data-for="' + i + '" hidden>📎 blokk</label>' : '')) +
			(ed ? '<button type="button" class="wl-cx" data-cx="' + i + '" aria-label="Törlés">×</button>' : '') + '</div>';
	}
	function footBtns(e) {
		const out = [];
		if (mine() && e.status === 'draft') out.push('<button type="button" class="cpx-btn" data-st="submitted">✓ Nap lezárása (beküldés jóváhagyásra)</button>');
		if (mine() && e.status === 'submitted') out.push('<span class="cpx-muted">Lezárva – még javíthatod, amíg jóvá nem hagyják.</span>');
		if (W.approver && !mine() && e.status === 'submitted') out.push('<button type="button" class="cpx-btn" data-st="approved">✓ Jóváhagyás</button>');
		if (W.approver && e.status !== 'draft' && S.e) out.push('<button type="button" class="cpx-btn ghost" data-st="draft">↺ Visszanyitás</button>');
		out.push('<button type="button" class="cpx-btn ghost" data-print>🖨 Nyomtatás</button>');
		return out.join('');
	}
	/* mentés: mezőnként gyűjtve, 1,2 mp tétlenség után (vagy azonnal elhagyáskor) */
	function queue(k, v) { S.dirty[k] = v; clearTimeout(S.t); S.t = setTimeout(flush, 1200); }
	async function flush() {
		clearTimeout(S.t);
		const f = S.dirty; if (!Object.keys(f).length || !editable()) return;
		/* az előző napból előtöltött mezők (jármű, induló km) az első mentéssel együtt mennek */
		if (S.main) S.main.querySelectorAll('[data-pre]').forEach(i => { if (!(i.dataset.f in f) && i.value !== '') f[i.dataset.f] = i.value; i.removeAttribute('data-pre'); });
		if (f.costs) f.costs = f.costs.map(c => ({ what: c.what, amount: c.amount, pay: c.pay, file: c.file || 0 }));
		S.dirty = {}; S.saving++; setStatus();
		const day = S.day;
		try { const r = await post('cp_wl_save', { day: day, f: JSON.stringify(f) }); if (day === S.day && S.uid === W.me) { const ks = S.e && S.e.shifts; S.e = r.e; if (S.dirty.shifts && ks) S.e.shifts = ks; const h = S.main && S.main.querySelector('[data-hours]'); if (h) h.innerHTML = S.e.hours ? 'Ledolgozott idő: <b>' + hfmt(S.e.hours) + '</b>' : ''; if ('km_start' in f || 'km_end' in f) { const k = S.main.querySelector('[data-f="km"]'); if (k && S.e.km != null) k.value = S.e.km; } } }
		catch (e) { toast('Nem sikerült menteni: ' + e.message, true); Object.assign(S.dirty, f); }
		S.saving--; setStatus();
	}
	function bindDay(b) {
		const ed = editable();
		b.querySelectorAll('[data-pv]').forEach(x => x.addEventListener('click', ev => { if (ev.target.closest('[data-fdel]')) return; lightbox(Number(x.dataset.pv)); }));
		b.querySelectorAll('[data-od]').forEach(a => a.addEventListener('click', ev => { ev.preventDefault(); if (window.CPCrm && window.CPCrm.open) window.CPCrm.open(Number(a.dataset.od)); }));
		const pr = b.querySelector('[data-print]'); if (pr) pr.addEventListener('click', () => printDay());
		const cs = b.querySelector('[data-csend]');
		if (cs) cs.addEventListener('click', async () => { const t = b.querySelector('[data-cmt]'); if (!t.value.trim()) return; cs.disabled = true; try { await flush(); const r = await post('cp_wl_comment', { uid: S.uid, day: S.day, body: t.value }); S.e = r.e; drawDay(b); } catch (e) { toast(e.message, true); cs.disabled = false; } });
		b.querySelectorAll('[data-st]').forEach(x => x.addEventListener('click', async () => {
			const to = x.dataset.st;
			if (to === 'submitted' && !(S.e && (S.e.t_start || (S.e.notes || '').trim() || S.e.tasks.length)) && !window.confirm('Még szinte üres a nap. Biztosan lezárod?')) return;
			if (to === 'submitted' && S.e && ((S.e.t_start && !S.e.t_end) || (S.e.shifts || []).some(g => g.s && !g.e)) && !window.confirm('Nincs megadva a befejezés ideje. Így zárod le?')) return;
			x.disabled = true; try { await flush(); const r = await post('cp_wl_status', { uid: S.uid, day: S.day, to: to }); S.e = r.e; toast({ submitted: 'Lezárva – a jóváhagyók értesítést kaptak.', approved: 'Jóváhagyva.', draft: 'Visszanyitva.' }[to]); drawDay(b); } catch (e) { toast(e.message, true); x.disabled = false; }
		}));
		if (!ed) return;
		b.querySelectorAll('[data-f]').forEach(i => {
			const k = i.dataset.f;
			const val = () => i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value;
			i.addEventListener(i.type === 'checkbox' || i.type === 'time' || i.type === 'date' ? 'change' : 'input', () => queue(k, val()));
			i.addEventListener('blur', () => { if (S.dirty[k] !== undefined) flush(); });
		});
		/* munkaidő-szakaszok: az első a t_start/t_end, a további szakaszok a shifts listában */
		const shifts = () => (S.e && S.e.shifts ? S.e.shifts.map(g => ({ s: g.s || '', e: g.e || '' })) : []);
		const setShifts = async (l, redraw) => { if (!S.e) S.e = { tasks: [], shifts: [] }; S.e.shifts = l; queue('shifts', l); await flush(); if (redraw) { drawDay(b); } };
		b.querySelectorAll('[data-sg] [data-sf]').forEach(i => i.addEventListener('change', () => { const l = shifts(), k = Number(i.closest('[data-sg]').dataset.sg); if (!l[k]) return; l[k][i.dataset.sf] = i.value; setShifts(l, false); }));
		b.querySelectorAll('[data-sgx]').forEach(x => x.addEventListener('click', () => { const l = shifts(); l.splice(Number(x.dataset.sgx), 1); setShifts(l, true); }));
		const sga = b.querySelector('[data-sgadd]');
		if (sga) sga.addEventListener('click', async () => { const l = shifts(); l.push({ s: '', e: '' }); await setShifts(l, true); const n = S.main && S.main.querySelectorAll('[data-sg] [data-sf="s"]'); if (n && n.length) n[n.length - 1].focus(); });
		/* „Most kezdem”: ha az utolsó szakasz már lezárult, új szakaszt nyit; „Most végeztem”: a nyitott szakaszt zárja */
		b.querySelectorAll('[data-now]').forEach(x => x.addEventListener('click', async () => {
			const t = nowHM(), l = shifts(), w = x.dataset.now;
			const f0 = b.querySelector('[data-f="t_start"]'), f1 = b.querySelector('[data-f="t_end"]');
			const last = l.length ? l[l.length - 1] : null;
			if (w === 's') {
				if (!f0.value) { f0.value = t; queue('t_start', t); flush(); return; }
				if (last && !last.s) { last.s = t; return setShifts(l, true); }
				if (!last && !f1.value) { toast('Már elkezdted (' + f0.value + ') – előbb a „Most végeztem”.', true); return; }
				if (last && !last.e) { toast('Már elkezdted (' + last.s + ') – előbb a „Most végeztem”.', true); return; }
				l.push({ s: t, e: '' }); return setShifts(l, true);
			}
			for (let k = l.length - 1; k >= 0; k--) { if (l[k].s && !l[k].e) { l[k].e = t; return setShifts(l, true); } }
			if (f0.value && !f1.value) { f1.value = t; queue('t_end', t); flush(); return; }
			toast(f0.value ? 'Nincs nyitott szakasz – a „Most kezdem” újat nyit.' : 'Előbb a „Most kezdem”.', true);
		}));
		b.querySelectorAll('[data-kind]').forEach(x => x.addEventListener('click', () => { x.classList.toggle('on'); queue('kinds', Array.from(b.querySelectorAll('[data-kind].on')).map(y => y.dataset.kind)); }));
		const dealIds = () => (S.e ? S.e.deal_list.map(d => d.id) : []);
		const setDeals = async ids => { queue('deals', ids); await flush(); drawDay(b); };
		b.querySelectorAll('[data-da]').forEach(x => x.addEventListener('click', () => setDeals(dealIds().concat([Number(x.dataset.da)]))));
		b.querySelectorAll('[data-dx]').forEach(x => x.addEventListener('click', () => setDeals(dealIds().filter(id => id !== Number(x.dataset.dx)))));
		/* feladatok */
		const tasks = () => (S.e ? S.e.tasks.slice() : []);
		const setTasks = async l => { if (!S.e) S.e = { tasks: [] }; S.e.tasks = l; queue('tasks', l); await flush(); drawDay(b); };
		const tn = b.querySelector('[data-tnew]');
		const addT = () => { const v = tn.value.trim(); if (!v) return; setTasks(tasks().concat(v.split(/\n+/).map(x => ({ t: x.trim(), done: true })).filter(x => x.t))).then(() => { const n = S.main.querySelector('[data-tnew]'); if (n) n.focus(); }); };
		if (tn) { tn.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); addT(); } }); b.querySelector('[data-tadd]').addEventListener('click', addT); }
		b.querySelectorAll('[data-tk]').forEach(x => x.addEventListener('change', () => { const l = tasks(); l[Number(x.dataset.tk)].done = x.checked; S.e.tasks = l; queue('tasks', l); }));
		b.querySelectorAll('[data-tx]').forEach(x => x.addEventListener('click', () => { const l = tasks(); l.splice(Number(x.dataset.tx), 1); setTasks(l); }));
		const pt = b.querySelector('[data-ptake]'); if (pt) pt.addEventListener('click', () => setTasks(String(S.prev.tomorrow).split(/\n+/).map(x => x.replace(/^[-•*\s]+/, '').trim()).filter(Boolean).map(x => ({ t: x, done: false }))));
		/* költségek */
		const costs = () => (S.e ? S.e.costs.map(c => Object.assign({}, c)) : []);
		const setCosts = async (l, redraw) => { if (!S.e) S.e = { costs: [] }; S.e.costs = l; queue('costs', l); if (redraw) { await flush(); drawDay(b); } };
		const ca = b.querySelector('[data-cadd]'); if (ca) ca.addEventListener('click', () => { const l = costs(); l.push({ what: '', amount: '', pay: 'kp', file: 0, _new: 1 }); S.e = S.e || {}; S.e.costs = l; drawDay(b); const r = S.main.querySelectorAll('[data-ci]'); const last = r[r.length - 1]; if (last) last.querySelector('[data-cf="what"]').focus(); });
		b.querySelectorAll('[data-ci]').forEach(row => {
			const i = Number(row.dataset.ci);
			row.querySelectorAll('[data-cf]').forEach(inp => inp.addEventListener(inp.tagName === 'SELECT' ? 'change' : 'input', () => { const l = costs(); l[i][inp.dataset.cf] = inp.value; setCosts(l, false); }));
		});
		b.querySelectorAll('[data-cx]').forEach(x => x.addEventListener('click', () => { const l = costs(); l.splice(Number(x.dataset.cx), 1); setCosts(l, true); }));
		/* feltöltés */
		b.querySelectorAll('[data-upl]').forEach(inp => inp.addEventListener('change', async () => {
			const files = Array.from(inp.files || []); if (!files.length) return;
			const kind = inp.dataset.upl, forI = inp.dataset.for;
			/* v2.9.58: a nap rögzítése a kiválasztáskor – napváltás közben is arra a napra megy a kép, amelyiknél kijelölték */
			const day = S.day;
			await flush();
			let n = 0, last = 0;
			for (const f0 of files) {
				toast('Feltöltés: ' + (++n) + ' / ' + files.length + '…');
				try { const f = await shrink(f0); const r = await post('cp_wl_upload', { day: day, kind: kind }, f); if (day === S.day && S.uid === W.me) S.e = r.e; last = r.id; }
				catch (e) { toast(e.message, true); }
			}
			if (day !== S.day || S.uid !== W.me || !b.isConnected) { toast(files.length > 1 ? files.length + ' kép feltöltve (' + day + ').' : 'Feltöltve (' + day + ').'); return; }
			if (kind === 'receipt' && last && forI !== undefined) { const l = costs(); if (l[Number(forI)]) { l[Number(forI)].file = last; await setCosts(l, true); return; } }
			toast(files.length > 1 ? files.length + ' kép feltöltve.' : 'Feltöltve.');
			drawDay(b);
		}));
		b.querySelectorAll('[data-fdel]').forEach(x => x.addEventListener('click', async ev => { ev.stopPropagation(); if (!window.confirm('Törlöd ezt a képet?')) return; try { const r = await post('cp_wl_file_del', { id: x.dataset.fdel }); S.e = r.e; drawDay(b); } catch (e) { toast(e.message, true); } }));
	}
	function lightbox(id) {
		const L = (S.e ? S.e.files : []), i0 = L.findIndex(f => f.id === id);
		let i = i0 < 0 ? 0 : i0;
		const ov = document.createElement('div'); ov.className = 'wl-lb';
		const show = () => { const f = L[i]; ov.innerHTML = '<button type="button" class="wl-lbx" aria-label="Bezárás">×</button>' + (L.length > 1 ? '<button type="button" class="wl-lbp" aria-label="Előző">‹</button><button type="button" class="wl-lbn" aria-label="Következő">›</button>' : '') + '<img src="' + fileUrl(f.id) + '" alt=""><div class="wl-lbc">' + (i + 1) + ' / ' + L.length + (f.kind === 'receipt' ? ' · blokk' : '') + ' · <a href="' + fileUrl(f.id, 0, 1) + '">Letöltés</a></div>'; };
		show();
		const close = () => { ov.remove(); document.removeEventListener('keydown', key); };
		const key = ev => { if (ev.key === 'Escape') close(); if (ev.key === 'ArrowRight') { i = (i + 1) % L.length; show(); } if (ev.key === 'ArrowLeft') { i = (i - 1 + L.length) % L.length; show(); } };
		ov.addEventListener('click', ev => { if (ev.target.closest('.wl-lbx') || ev.target === ov) close(); else if (ev.target.closest('.wl-lbn')) { i = (i + 1) % L.length; show(); } else if (ev.target.closest('.wl-lbp')) { i = (i - 1 + L.length) % L.length; show(); } });
		let sx = null; ov.addEventListener('touchstart', ev => { sx = ev.touches[0].clientX; }, { passive: true }); ov.addEventListener('touchend', ev => { if (sx === null) return; const dx = ev.changedTouches[0].clientX - sx; if (Math.abs(dx) > 50 && L.length > 1) { i = (i + (dx < 0 ? 1 : -1) + L.length) % L.length; show(); } sx = null; });
		document.addEventListener('keydown', key);
		document.body.appendChild(ov);
	}
	function printDay() {
		const e = S.e; if (!e) { toast('Nincs mit nyomtatni.', true); return; }
		const w = window.open('', '_blank'); if (!w) return;
		const ph = e.files.filter(f => f.kind === 'photo');
		w.document.write('<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Munkanapló ' + esc(S.day) + '</title><style>body{font:13px/1.45 Arial,sans-serif;margin:24px;color:#111}h1{font-size:18px;margin:0 0 4px}h2{font-size:14px;margin:16px 0 4px;border-bottom:1px solid #ccc}table{border-collapse:collapse}td{padding:2px 10px 2px 0}.ph img{width:160px;height:120px;object-fit:cover;margin:0 6px 6px 0}</style></head><body>' +
			'<h1>Munkanapló – ' + esc(uname(S.uid)) + '</h1><div>' + esc(huDay(S.day)) + ' · ' + esc((ST[e.status] || ST.draft)[0]) + (e.approved_by ? ' (' + esc(e.approved_by) + ')' : '') + '</div>' +
			'<h2>Munkaidő</h2><table>' + [{ s: e.t_start, e: e.t_end }].concat(e.shifts || []).filter((g, i) => i === 0 || g.s || g.e).map((g, i) => '<tr><td>' + (i ? (i + 1) + '. kezdés' : 'Kezdés') + '</td><td>' + esc(g.s || '–') + '</td><td>Befejezés</td><td>' + esc(g.e || '–') + '</td>' + (i ? '' : '<td>Szünet</td><td>' + (e.break_min || 0) + ' perc</td><td><b>' + hfmt(e.hours) + '</b></td>') + '</tr>').join('') + '</table>' +
			'<h2>Munka</h2><div>' + esc(e.kinds.join(', ')) + (e.place ? ' · ' + esc(e.place) : '') + '</div>' + (e.deal_list.length ? '<div>Rendezvények: ' + e.deal_list.map(d => esc(d.title)).join('; ') + '</div>' : '') +
			(e.tasks.length ? '<h2>Feladatok</h2><ul>' + e.tasks.map(t => '<li>' + (t.done ? '☑ ' : '☐ ') + esc(t.t) + '</li>').join('') + '</ul>' : '') + (e.notes ? '<h2>Leírás</h2><div>' + esc(e.notes).replace(/\n/g, '<br>') + '</div>' : '') +
			((e.km || e.vehicle) ? '<h2>Útnyilvántartás</h2><div>' + esc(e.vehicle || '') + ' · ' + (e.km_start != null ? e.km_start + ' → ' + (e.km_end != null ? e.km_end : '?') + ' · ' : '') + (e.km != null ? e.km + ' km' : '') + '</div>' : '') +
			(e.costs.length ? '<h2>Költségek</h2><table>' + e.costs.map(c => '<tr><td>' + esc(c.what) + '</td><td>' + ft(c.amount) + '</td><td>' + esc(PAY[c.pay] || '') + '</td></tr>').join('') + '<tr><td><b>Összesen</b></td><td><b>' + ft(e.costs.reduce((a, c) => a + (Number(c.amount) || 0), 0)) + '</b></td></tr></table>' : '') +
			(e.issue ? '<h2>Hiba, eszközprobléma</h2><div>' + esc(e.issue).replace(/\n/g, '<br>') + '</div>' : '') + (e.tomorrow ? '<h2>Holnapra</h2><div>' + esc(e.tomorrow).replace(/\n/g, '<br>') + '</div>' : '') +
			(ph.length ? '<h2>Fotók (' + ph.length + ')</h2><div class="ph">' + ph.map(f => '<img src="' + fileUrl(f.id, 1) + '">').join('') + '</div>' : '') +
			'<script>window.onload=function(){setTimeout(function(){window.print()},400)}<\/script></body></html>');
		w.document.close();
	}

	/* ------------------------------------------------------------ hónap */
	function drawMonth(b, r) {
		const t = r.tot;
		b.innerHTML = '<div class="wl-mt"><div class="wl-k"><b>' + hfmt(t.hours) + '</b><small>ledolgozott idő</small></div><div class="wl-k"><b>' + t.days + '</b><small>munkanap</small></div><div class="wl-k"><b>' + (t.km || 0).toLocaleString('hu-HU') + ' km</b><small>megtett út</small></div><div class="wl-k"><b>' + ft(t.cost) + '</b><small>költség</small></div></div>' +
			(r.rows.length ? '<div class="wl-tw"><table class="wl-t"><thead><tr><th>Nap</th><th>Idő</th><th class="r">Óra</th><th>Munka</th><th class="r">Km</th><th class="r">Költség</th><th class="r">Fotó</th><th>Állapot</th></tr></thead><tbody>' +
				r.rows.map(x => '<tr data-go="' + x.day + '"><td><b>' + esc(x.day.slice(8)) + '.</b> <small>' + DOW[new Date(x.day + 'T12:00:00').getDay()].slice(0, 3) + '</small>' + (x.issue ? ' <span title="Hibát jelzett">⚠</span>' : '') + '</td><td>' + (x.span ? '<span title="' + esc(x.span) + '">' + esc(x.span) + '</span>' : esc(x.t_start || '') + (x.t_end ? '–' + esc(x.t_end) : '')) + '</td><td class="r">' + (x.hours ? String(x.hours).replace('.', ',') : '') + '</td><td><span class="wl-ell">' + esc(x.kinds.join(', ') + (x.place ? ' · ' + x.place : '')) + '</span></td><td class="r">' + (x.km == null ? '' : x.km) + '</td><td class="r">' + (x.cost ? ft(x.cost) : '') + '</td><td class="r">' + (x.photos || '') + '</td><td><span class="wl-st ' + (ST[x.status] || ST.draft)[1] + '">' + (ST[x.status] || ST.draft)[0].split(' – ')[0] + '</span></td></tr>').join('') + '</tbody></table></div>' : '<div class="cpx-empty">Ebben a hónapban még nincs bejegyzés.</div>') +
			'<div class="wl-foot"><a class="cpx-btn ghost" href="' + C.ajax + '?action=cp_wl_month&csv=1&uid=' + S.uid + '&m=' + r.m + '&t=' + encodeURIComponent(C.t) + '">⬇ Letöltés (CSV / Excel)</a></div>';
		b.querySelectorAll('[data-go]').forEach(tr => tr.addEventListener('click', () => { S.day = tr.dataset.go; S.mode = 'day'; draw(); }));
	}

	window.addEventListener('beforeunload', () => { if (Object.keys(S.dirty).length) flush(); });
	document.addEventListener('visibilitychange', () => { if (document.hidden) flush(); });
	window.CPWorklog = { view, open, status };

	const css = document.createElement('style');
	css.textContent = '.wl{max-width:1180px}.wl-h{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px}.wl-h select,.wl-nav input[type=date]{border:1px solid #E6E3DE;border-radius:8px;padding:7px 9px;font:13.5px Inter,sans-serif;background:#fff;color:#141C33}.wl-nav{display:flex;gap:6px;align-items:center}.wl-nav b{min-width:140px;text-align:center}.wl-nav .cpx-btn{height:34px;min-width:34px;padding:0 10px}' +
		'.wl-title{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:2px 0 12px}.wl-title h2{font:700 19px Inter,sans-serif;margin:0}.wl-st{font-size:12px;font-weight:600;border-radius:999px;padding:3px 10px;background:#F1EEE9;color:#5A5F6B}.wl-st.s{background:#FFF4E5;color:#8A5300}.wl-st.a{background:#E6F6EC;color:#1E7A43}.wl-who{font-size:12.5px;color:#656973}' +
		'.wl-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.wl-c{background:#fff;border:1px solid #E6E3DE;border-radius:14px;padding:14px}.wl-c.wl-w{grid-column:1/-1}.wl-c h4{margin:0 0 10px;font:700 14px Inter,sans-serif;display:flex;gap:8px;align-items:baseline}.wl-c h4 small{font-weight:500;color:#656973}' +
		'.wl-time{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.wl-seg{align-items:end}.wl-sgx{justify-self:start;width:36px;height:36px;border:1px solid #E6E3DE;border-radius:9px;background:#fff;color:#656973;font-size:18px;cursor:pointer}.wl-sgx:hover{color:#B42318;border-color:#F0C8C3}.wl-time label,.wl-l{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#656973;margin-top:6px}.wl-c input[type=text],.wl-c input[type=time],.wl-c input[type=number],.wl-c textarea,.wl-c select{border:1px solid #E6E3DE;border-radius:9px;padding:8px 10px;font:14px Inter,sans-serif;color:#141C33;background:#fff;width:100%;box-sizing:border-box}.wl-c textarea{resize:vertical}.wl-c :disabled{background:#FAF8F5;color:#3A3F4B}' +
		'.wl-qb{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}.wl-sum{margin-top:10px;font-size:14px}.wl-chips{display:flex;flex-wrap:wrap;gap:6px}.wl-chip{border:1px solid #E6E3DE;background:#fff;border-radius:999px;padding:6px 12px;font:600 12.5px Inter,sans-serif;color:#3A3F4B;cursor:pointer}.wl-chip.on{background:#2F5BD8;border-color:#2F5BD8;color:#fff}.wl-chip:disabled{cursor:default}' +
		'.wl-deals{display:flex;flex-wrap:wrap;gap:6px}.wl-dl{display:inline-flex;align-items:center;gap:4px;background:#EEF3FD;border-radius:999px;padding:4px 6px 4px 10px;font-size:12.5px}.wl-dl a{color:#2F5BD8;text-decoration:none;font-weight:600}.wl-dl button,.wl-tasks button,.wl-cx{border:0;background:none;color:#656973;font-size:18px;line-height:1;cursor:pointer;padding:0 4px}.wl-sug{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.wl-sug small{width:100%;color:#656973}' +
		'.wl-tasks{list-style:none;margin:0 0 8px;padding:0}.wl-tasks li{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #F0ECE6;padding:6px 0}.wl-tasks label{display:flex;gap:8px;align-items:center;font-size:14px;flex:1}.wl-tasks input{width:18px;height:18px;accent-color:#2F5BD8}.wl-add{display:flex;gap:8px;margin:6px 0}.wl-add input,.wl-add textarea{flex:1}' +
		'.wl-prev{background:#FAF8FF;border:1px dashed #D9CFF5;border-radius:10px;padding:8px 10px;margin-bottom:8px;font-size:12.5px;color:#4B3A8C}.wl-prev p{margin:4px 0 8px;white-space:pre-wrap;color:#141C33}' +
		'.wl-ph{display:flex;flex-wrap:wrap;gap:8px}.wl-th{position:relative;width:110px;height:110px;border:0;padding:0;border-radius:10px;overflow:hidden;background:#F1EEE9;cursor:zoom-in}.wl-th img{width:100%;height:100%;object-fit:cover;display:block}.wl-th i{position:absolute;top:4px;right:4px;width:22px;height:22px;border-radius:50%;background:rgba(20,28,51,.7);color:#fff;font:700 15px/22px Inter,sans-serif;font-style:normal;text-align:center}' +
		'.wl-up{width:110px;height:110px;border:2px dashed #C9D6FB;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#2F5BD8;font-size:28px}.wl-up span{display:flex;flex-direction:column;align-items:center}.wl-up small{font-size:12px;font-weight:600}' +
		'.wl-cost{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,.8fr) minmax(0,1fr) auto auto;gap:6px;align-items:center;margin-bottom:6px}.wl-rc{width:38px;height:38px;border:0;padding:0;border-radius:8px;overflow:hidden;cursor:zoom-in}.wl-rc img{width:100%;height:100%;object-fit:cover}.wl-rcu{font-size:12px;color:#2F5BD8;cursor:pointer;white-space:nowrap;font-weight:600}' +
		'.wl-chk{display:flex;gap:8px;align-items:center;margin-top:8px;font-size:13px}.wl-foot{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0}.wl-cm{margin-top:4px}.wl-cmi{border-bottom:1px solid #F0ECE6;padding:6px 0}.wl-cmi p{margin:2px 0 0;white-space:pre-wrap}.wl-cmi small{color:#656973}' +
		'.wl-lb{position:fixed;inset:0;z-index:9999;background:rgba(10,14,25,.92);display:flex;align-items:center;justify-content:center}.wl-lb img{max-width:94vw;max-height:84vh;object-fit:contain}.wl-lbx{position:absolute;top:10px;right:14px;font-size:34px;color:#fff;background:none;border:0;cursor:pointer}.wl-lbp,.wl-lbn{position:absolute;top:50%;transform:translateY(-50%);font-size:44px;color:#fff;background:none;border:0;cursor:pointer;padding:10px}.wl-lbp{left:6px}.wl-lbn{right:6px}.wl-lbc{position:absolute;bottom:14px;left:0;right:0;text-align:center;color:#ddd;font-size:13px}.wl-lbc a{color:#fff}' +
		'.wl-mt{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}.wl-k{background:#fff;border:1px solid #E6E3DE;border-radius:12px;padding:12px}.wl-k b{display:block;font-size:19px}.wl-k small{color:#656973}.wl-tw{background:#fff;border:1px solid #E6E3DE;border-radius:12px;overflow:auto}.wl-t{width:100%;border-collapse:collapse;font-size:13px}.wl-t th{text-align:left;font-size:11.5px;text-transform:uppercase;color:#656973;padding:8px 10px;border-bottom:1px solid #E6E3DE;background:#FAF8F5;white-space:nowrap}.wl-t td{padding:8px 10px;border-bottom:1px solid #F0ECE6;white-space:nowrap}.wl-t .r{text-align:right}.wl-t tbody tr{cursor:pointer}.wl-t tbody tr:hover{background:#FAF8F5}.wl-ell{display:inline-block;max-width:260px;overflow:hidden;text-overflow:ellipsis;vertical-align:bottom}' +
		'@media (max-width:760px){.wl-grid{grid-template-columns:1fr}.wl-time{grid-template-columns:1fr 1fr 1fr}.wl-mt{grid-template-columns:1fr 1fr}.wl-th,.wl-up{width:calc(33.3% - 6px);height:auto;aspect-ratio:1}.wl-cost{grid-template-columns:1fr 90px;grid-auto-flow:row}.wl-cost select{grid-column:1}.wl-c input,.wl-c textarea,.wl-c select{font-size:16px}.wl-nav{width:100%}.wl-nav input[type=date]{flex:1}.wl-t .wl-ell{max-width:120px}}';
	document.head.appendChild(css);
})();
