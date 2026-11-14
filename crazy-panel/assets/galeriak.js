/* Crazy panel – Galériák (v2.9.255): az összes szelfigép-galéria egy helyen – megnyitás, kezelés, megosztás.
 * Megosztás: link másolása, QR-kód (nyomtatható PNG-ben is), levél az ügyfélnek a levélíróban (a levelet a kolléga küldi el).
 * Adat: cp_booth_gal_list (a galéria /admin-list összesítője + a panel deal-adatai, 60 mp gyorsítótár).
 * A kezelés (cím, jelszó, webhook, képek elrejtése / törlése) a deal adatlapjáról ismert ablak: CPCrm.galManage. */
(function () {
	'use strict';
	const C = window.CPX || {};
	if (!C.gal) return;
	const SRC = (document.currentScript && document.currentScript.src) || '';
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const S = { main: null, d: null, q: '', f: 'all', sort: 'last', seq: 0, err: '' };
	try { const o = JSON.parse(localStorage.getItem('cpx_gal') || '{}'); if (o.f) S.f = o.f; if (o.sort) S.sort = o.sort; } catch (e) { /* privát mód */ }
	const keep = () => { try { localStorage.setItem('cpx_gal', JSON.stringify({ f: S.f, sort: S.sort })); } catch (e) { /* privát mód */ } };

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
		clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), 3200);
	}
	const p2 = n => String(n).padStart(2, '0');
	const ymd = d => d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate());
	const huD = s => (s ? String(s).slice(0, 10).replace(/-/g, '.') + '.' : '');
	const utc = s => (s ? Date.parse(String(s).replace(' ', 'T') + 'Z') || 0 : 0);   /* a galéria ideje UTC */
	function ago(s) {
		const t = utc(s); if (!t) return '';
		const d = new Date(t), days = Math.round((Date.parse(ymd(new Date())) - Date.parse(ymd(d))) / 864e5), hm = p2(d.getHours()) + ':' + p2(d.getMinutes());
		return days <= 0 ? 'ma ' + hm : days === 1 ? 'tegnap ' + hm : days < 7 ? days + ' napja' : huD(ymd(d));
	}
	const name = x => x.dtitle || x.title || (x.date ? 'Galéria · ' + huD(x.date) : 'Rendezvény nélküli galéria');
	/* a vendégeknek szóló felirat (QR-kártya, megosztás): a galéria saját címe – a deal belső neve nem kerül ki */
	const pub = x => x.title || (x.date ? 'A rendezvény fotói · ' + huD(x.date) : 'A rendezvény fotói');
	const FILTERS = [['all', 'Mind'], ['soon', 'Közelgő'], ['recent', 'Elmúlt 30 nap'], ['nodeal', 'Rendezvény nélkül']];
	function match(x) {
		if (S.f === 'soon' && !(x.date && x.date >= ymd(new Date()))) return false;
		if (S.f === 'recent') { const t = utc(x.last); if (!t || Date.now() - t > 30 * 864e5) return false; }
		if (S.f === 'nodeal' && x.deal) return false;
		if (S.q) {
			const h = [name(x), x.title, huD(x.date), x.date, x.cname, x.ev].join(' ').toLocaleLowerCase('hu');
			if (!S.q.toLocaleLowerCase('hu').split(/\s+/).filter(Boolean).every(w => h.includes(w))) return false;
		}
		return true;
	}
	function sorted(L) {
		const by = { last: x => utc(x.last), date: x => (x.date ? Date.parse(x.date) || 0 : 0), n: x => x.n || 0 }[S.sort] || (() => 0);
		return L.slice().sort((a, b) => by(b) - by(a) || utc(b.last) - utc(a.last));
	}

	function view(main) {
		css();
		S.main = main;
		main.innerHTML = '<div class="glr"><div class="glr-h"><div><h2>Galériák</h2><p class="glr-lead" data-glead>Az összes szelfigép-galéria egy helyen: megnyitás, kezelés, megosztás.</p></div>' +
			'<button type="button" class="cpx-btn ghost sm" data-gref>Frissítés</button></div>' +
			'<div class="glr-tb"><input type="search" data-gq placeholder="Keresés: rendezvény, ügyfél, dátum…" aria-label="Keresés a galériák között" value="' + esc(S.q) + '">' +
			'<div class="glr-seg" role="group" aria-label="Szűrés">' + FILTERS.map(f => '<button type="button" data-gf="' + f[0] + '" aria-pressed="' + (S.f === f[0]) + '">' + f[1] + '</button>').join('') + '</div>' +
			'<label class="glr-sort">Rendezés <select data-gs><option value="last">Legutóbbi feltöltés</option><option value="date">Rendezvény dátuma</option><option value="n">Képek száma</option></select></label></div>' +
			'<p class="glr-sum" data-gsum aria-live="polite"></p><div class="glr-list" data-glist><div class="cpx-empty">Betöltés…</div></div></div>';
		main.querySelector('[data-gs]').value = S.sort;
		let qt = 0;
		main.querySelector('[data-gq]').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { S.q = e.target.value.trim(); draw(); }, 150); });
		main.querySelectorAll('[data-gf]').forEach(b => b.addEventListener('click', () => { S.f = b.dataset.gf; keep(); main.querySelectorAll('[data-gf]').forEach(x => x.setAttribute('aria-pressed', String(x === b))); draw(); }));
		main.querySelector('[data-gs]').addEventListener('change', e => { S.sort = e.target.value; keep(); draw(); });
		main.querySelector('[data-gref]').addEventListener('click', () => load(true));
		main.querySelector('[data-glist]').addEventListener('click', act);
		if (S.d) draw();
		load(!!S.d);
	}
	async function load(fresh) {
		const my = ++S.seq;
		try { const d = await post('cp_booth_gal_list', fresh ? { fresh: 1 } : {}); if (my !== S.seq) return; S.d = d; S.err = ''; }
		catch (e) { if (my !== S.seq) return; S.err = e.message; if (S.d) toast(e.message, true); }
		draw();
	}
	function draw() {
		const box = S.main && S.main.querySelector('[data-glist]');
		if (!box || !box.isConnected) return;
		const sum = S.main.querySelector('[data-gsum]');
		if (!S.d) {
			if (S.err) { box.innerHTML = '<div class="cpx-empty err">' + esc(S.err) + '<br><button type="button" class="cpx-btn sm" data-ga="retry">Újra</button></div>'; sum.textContent = ''; }
			return;
		}
		const kd = Number(S.d.keep_days) || 60;
		S.main.querySelector('[data-glead]').textContent = 'Az összes szelfigép-galéria egy helyen: megnyitás, kezelés, megosztás. A képek a feltöltéstől ' + kd + ' napig érhetők el.';
		const all = S.d.list || [], L = sorted(all.filter(match));
		const tot = L.reduce((a, x) => a + (x.n || 0), 0), ai = L.reduce((a, x) => a + (x.ai || 0), 0);
		sum.textContent = L.length + ' galéria' + (L.length !== all.length ? ' (összesen ' + all.length + ')' : '') + ' · ' + tot.toLocaleString('hu-HU') + ' kép' + (ai ? ' · ebből ' + ai.toLocaleString('hu-HU') + ' AI-kép' : '');
		if (!L.length) { box.innerHTML = '<div class="cpx-empty">' + (all.length ? 'Nincs a szűrésnek megfelelő galéria.' : 'Még nincs galéria. Amint egy szelfigép vagy a mobil app feltölti az első képet, itt megjelenik.') + '</div>'; return; }
		box.innerHTML = L.map(card).join('');
	}
	function card(x) {
		const th = (x.thumbs || []).slice(0, 4);
		const pics = th.length ? th.map(u => '<span style="background-image:url(\'' + esc(u) + '\')"></span>').join('') : '<em>Még nincs kép</em>';
		const meta = [x.date ? huD(x.date) : (x.deal ? '' : 'rendezvény nélkül'), (x.n || 0).toLocaleString('hu-HU') + ' kép', x.ai ? x.ai + ' AI' : '', x.video ? x.video + ' videó' : '', x.hidden ? x.hidden + ' elrejtve' : '', x.last ? 'utoljára ' + ago(x.last) : ''].filter(Boolean).join(' · ');
		return '<article class="glr-c" data-ev="' + esc(x.ev) + '">' +
			'<a class="glr-th n' + th.length + '" href="' + esc(x.url) + '" target="_blank" rel="noopener" title="Galéria megnyitása új lapon" aria-label="' + esc(name(x)) + ' – galéria megnyitása új lapon">' + pics + '</a>' +
			'<div class="glr-b"><h3><a href="' + esc(x.url) + '" target="_blank" rel="noopener">' + esc(name(x)) + '</a>' + (x.has_pw ? '<span class="glr-fl">jelszavas</span>' : '') + '</h3>' +
			'<p class="glr-m">' + esc(meta) + '</p>' + (x.cname ? '<p class="glr-m glr-cl">' + esc(x.cname) + '</p>' : '') +
			'<div class="glr-a"><button type="button" class="cpx-btn ghost sm" data-ga="copy">Link másolása</button><button type="button" class="cpx-btn ghost sm" data-ga="qr">QR-kód</button><button type="button" class="cpx-btn ghost sm" data-ga="man">Kezelés</button>' +
			(x.deal && x.cemail ? '<button type="button" class="cpx-btn ghost sm" data-ga="mail">Levél az ügyfélnek</button>' : '') +
			(x.deal ? '<button type="button" class="glr-lnk" data-ga="deal">Deal megnyitása</button>' : '') + '</div></div></article>';
	}
	function find(el) { const c = el.closest('[data-ev]'); return c && S.d ? (S.d.list || []).find(x => x.ev === c.dataset.ev) : null; }
	function act(e) {
		const b = e.target.closest('[data-ga]'); if (!b) return;
		const a = b.dataset.ga;
		if (a === 'retry') { load(true); return; }
		const x = find(b); if (!x) return;
		if (a === 'copy') copy(x.url, 'A galéria linkje a vágólapon.');
		else if (a === 'qr') qr(x);
		else if (a === 'man') {
			if (window.CPCrm && window.CPCrm.galManage) window.CPCrm.galManage(x.deal || 0, x.deal ? '' : x.ev, () => load(true));
			else toast('A kezelés a Deal-ek modullal érhető el.', true);
		}
		else if (a === 'mail') mail(x);
		else if (a === 'deal' && window.CPCrm) window.CPCrm.open(x.deal);
	}
	async function copy(t, ok) {
		try { await navigator.clipboard.writeText(t); toast(ok); return; } catch (e) { /* régebbi böngésző: tartalék */ }
		const ta = document.createElement('textarea'); ta.value = t; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
		document.body.appendChild(ta); ta.select();
		let done = false; try { done = document.execCommand('copy'); } catch (e) { done = false; }
		ta.remove(); toast(done ? ok : 'Nem sikerült másolni: ' + t, !done);
	}
	/* levél az ügyfélnek: a levélíró megnyílik a kitöltött szöveggel – a kolléga átnézi és ő küldi el */
	function mail(x) {
		if (!window.CPMail || !window.CPMail.compose) { toast('A levélíró nem érhető el.', true); return; }
		const kd = Number(S.d && S.d.keep_days) || 60;
		const body = '<p>Kedves ' + esc(x.cfirst || 'Ügyfelünk') + '!</p>' +
			'<p>Köszönjük, hogy velünk ünnepeltetek! Elkészült ' + (x.date ? 'a ' + esc(huD(x.date)) + ' rendezvény' : 'a rendezvény') + ' fotógalériája, itt nézhetitek meg és tölthetitek le a képeket:</p>' +
			'<p><a href="' + esc(x.url) + '">' + esc(x.url) + '</a></p>' + (x.has_pw ? '<p>A galéria jelszóval védett.</p>' : '') +
			'<p>A fotók ' + kd + ' napig érhetők el – az „Összes letöltése (ZIP)” gombbal egyben is letölthetők.</p>';
		window.CPMail.compose({ to: [x.cemail], subject: 'Elkészült a rendezvény fotógalériája' + (x.date ? ' – ' + huD(x.date) : ''), body, deal: x.deal, dealTitle: x.dtitle });
	}
	/* QR-kód: a megosztó lib (qrcode-generator, MIT) igény szerint töltődik; nyomtatható PNG a galéria nevével */
	let qrP = null;
	function qrLib() {
		if (window.qrcode) return Promise.resolve();
		if (!qrP) qrP = new Promise((res, rej) => { const s = document.createElement('script'); s.src = SRC.replace(/galeriak\.js(\?.*)?$/, 'qrcode.min.js$1'); s.onload = res; s.onerror = () => { qrP = null; rej(new Error('A QR-kód készítője nem töltődött be.')); }; document.head.appendChild(s); });
		return qrP;
	}
	async function qr(x) {
		try { await qrLib(); } catch (e) { toast(e.message, true); return; }
		const q = window.qrcode(0, 'M'); q.addData(x.url); q.make();
		const svg = q.createSvgTag({ cellSize: 8, margin: 2 }).replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ');
		const ov = document.createElement('div'); ov.className = 'glr-ov'; ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-label', 'QR-kód');
		ov.innerHTML = '<div class="glr-dlg"><h3>' + esc(name(x)) + '</h3><p class="glr-url">A kártyán: „' + esc(pub(x)) + '”</p><div class="glr-qr" style="background-image:url(\'data:image/svg+xml;base64,' + btoa(svg) + '\')" role="img" aria-label="QR-kód: ' + esc(x.url) + '"></div>' +
			'<p class="glr-url">' + esc(x.url) + '</p><div class="glr-a">' +
			'<button type="button" class="cpx-btn sm" data-q="png">PNG letöltése (nyomtatáshoz)</button><button type="button" class="cpx-btn ghost sm" data-q="copy">Link másolása</button>' +
			(navigator.share ? '<button type="button" class="cpx-btn ghost sm" data-q="share">Megosztás…</button>' : '') +
			'<button type="button" class="cpx-btn ghost sm" data-q="x">Bezárás</button></div></div>';
		document.body.appendChild(ov);
		const close = () => { ov.remove(); document.removeEventListener('keydown', key, true); };
		const key = e => { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
		document.addEventListener('keydown', key, true);
		ov.addEventListener('click', async e => {
			if (e.target === ov) { close(); return; }
			const b = e.target.closest('[data-q]'); if (!b) return;
			if (b.dataset.q === 'x') close();
			else if (b.dataset.q === 'copy') copy(x.url, 'A galéria linkje a vágólapon.');
			else if (b.dataset.q === 'share') { try { await navigator.share({ title: pub(x), text: 'A rendezvény fotói:', url: x.url }); } catch (er) { /* bezárták */ } }
			else if (b.dataset.q === 'png') png(q, x);
		});
		const f = ov.querySelector('[data-q="png"]'); if (f) f.focus();
	}
	function png(q, x) {
		const n = q.getModuleCount(), W = 1000, cell = Math.floor(760 / n), qs = cell * n, ox = Math.round((W - qs) / 2), oy = 90;
		const c = document.createElement('canvas'); c.width = W; c.height = oy + qs + 260;
		const g = c.getContext('2d');
		g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.fillStyle = '#111';
		for (let r = 0; r < n; r++) for (let k = 0; k < n; k++) if (q.isDark(r, k)) g.fillRect(ox + k * cell, oy + r * cell, cell, cell);
		g.textAlign = 'center'; g.fillStyle = '#141C33';
		const fit = (t, size, w) => { let s = size; do { g.font = '700 ' + s + 'px Inter, "Segoe UI", sans-serif'; s -= 2; } while (g.measureText(t).width > w && s > 18); return t; };
		g.fillText(fit(pub(x), 46, W - 120), W / 2, oy + qs + 90);
		g.font = '500 30px Inter, "Segoe UI", sans-serif'; g.fillStyle = '#4A5060'; g.fillText('Olvasd be a telefonoddal, és töltsd le a fotóidat!', W / 2, oy + qs + 150);
		g.font = '400 24px Inter, "Segoe UI", sans-serif'; g.fillStyle = '#6B7180'; g.fillText(x.url.replace(/^https?:\/\//, ''), W / 2, oy + qs + 200);
		c.toBlob(b => {
			if (!b) { toast('A PNG nem készült el.', true); return; }
			const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'galeria-qr-' + x.ev + '.png';
			document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
		}, 'image/png');
	}
	function status(s) { if (s) s.textContent = S.d ? (S.d.list || []).length + ' galéria' : ''; }

	let cssDone = false;
	function css() {
		if (cssDone) return; cssDone = true;
		const st = document.createElement('style');
		st.textContent = '.glr-h{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:10px}' +
			'.glr-h h2{margin:0;font:800 20px "Plus Jakarta Sans",sans-serif}' +
			'.glr-lead{margin:4px 0 0;font-size:13px;color:var(--mu);max-width:70ch}' +
			'.glr-tb{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:14px 0 6px}' +
			'.glr-tb input[type=search]{flex:1 1 240px;min-width:0;height:38px;border:1.5px solid var(--li);border-radius:10px;padding:0 12px;background:#fff;font:inherit}' +
			'.glr-tb input[type=search]:focus{outline:0;border-color:var(--o)}' +
			'.glr-seg{display:flex;flex-wrap:wrap;gap:4px;background:#F0ECE6;border-radius:999px;padding:3px}' +
			'.glr-seg button{border:0;background:transparent;border-radius:999px;padding:0 12px;height:30px;font:600 13px Inter,sans-serif;color:var(--mu);cursor:pointer}' +
			'.glr-seg button[aria-pressed=true]{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(20,28,51,.12)}' +
			'.glr-seg button:focus-visible{outline:2px solid var(--o);outline-offset:1px}' +
			'.glr-sort{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--mu)}' +
			'.glr-sort select{height:34px;border:1.5px solid var(--li);border-radius:10px;padding:0 8px;background:#fff;font:inherit;color:var(--ink)}' +
			'.glr-sum{margin:6px 0 12px;font-size:13px;color:var(--mu);font-variant-numeric:tabular-nums}' +
			'.glr-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}' +
			'.glr-c{display:flex;flex-direction:column;background:#fff;border:1px solid var(--li);border-radius:14px;overflow:hidden;min-width:0}' +
			'.glr-th{display:grid;grid-template-columns:repeat(4,1fr);gap:2px;height:120px;background:#F0ECE6;text-decoration:none}' +
			'.glr-th span{background:#E7E2DA center/cover no-repeat;min-width:0}' +
			'.glr-th.n1{grid-template-columns:1fr}.glr-th.n2{grid-template-columns:1fr 1fr}.glr-th.n3{grid-template-columns:repeat(3,1fr)}' +
			'.glr-th.n0{display:flex;align-items:center;justify-content:center}.glr-th em{font-style:normal;font-size:13px;color:var(--mu)}' +
			'.glr-b{display:flex;flex-direction:column;gap:6px;padding:12px 14px 14px;min-width:0}' +
			'.glr-b h3{margin:0;font:700 15px/1.3 Inter,sans-serif;display:flex;flex-wrap:wrap;align-items:center;gap:6px}' +
			'.glr-b h3 a{color:var(--ink);text-decoration:none;overflow-wrap:anywhere;min-width:0}.glr-b h3 a:hover{text-decoration:underline}' +
			'.glr-fl{font:600 11px Inter,sans-serif;color:#7A4E12;background:#FCF0DC;border-radius:999px;padding:2px 8px;white-space:nowrap}' +
			'.glr-m{margin:0;font-size:12.5px;color:var(--mu);font-variant-numeric:tabular-nums;overflow-wrap:anywhere}' +
			'.glr-cl{margin-top:-4px}' +
			'.glr-a{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:4px}' +
			'.glr-lnk{border:0;background:none;padding:4px 2px;font:600 12.5px Inter,sans-serif;color:var(--n);text-decoration:underline;text-underline-offset:2px;cursor:pointer}' +
			'.glr-ov{position:fixed;inset:0;z-index:10070;background:rgba(20,28,51,.4);display:flex;align-items:center;justify-content:center;padding:16px}' +
			'.glr-dlg{background:#fff;border-radius:18px;padding:22px;width:min(420px,100%);box-shadow:0 24px 60px rgba(20,28,51,.3);display:flex;flex-direction:column;align-items:center;gap:10px;text-align:center}' +
			'.glr-dlg h3{margin:0;font:700 16px/1.3 Inter,sans-serif;overflow-wrap:anywhere}' +
			'.glr-qr{width:240px;height:240px;background:#fff center/contain no-repeat}' +
			'.glr-url{margin:0;font-size:12px;color:var(--mu);overflow-wrap:anywhere}' +
			'.glr-dlg .glr-a{justify-content:center}' +
			'@media (max-width:560px){.glr-list{grid-template-columns:1fr}.glr-th{height:104px}}';
		document.head.appendChild(st);
	}

	window.CPGal = { view, refresh: () => load(true), status };
})();
