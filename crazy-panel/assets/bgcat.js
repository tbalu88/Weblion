/* Crazy panel – Eszközök → Háttérkatalógus (v1.50): a hátterek képpel, és a kétoldalas párok – képesen és szövegesen is */
(function () {
	'use strict';
	const C = window.CPX || {};
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const lc = s => String(s || '').trim().toLowerCase();
	const fold = s => lc(s).normalize('NFD').replace(/[̀-ͯ]/g, '');
	const S = { main: null, q: '', show: 'all', mode: 'pic' };
	try { const k = JSON.parse(localStorage.getItem('cpx_bgcat') || '{}'); if (['all', 'pair', 'single'].includes(k.show)) S.show = k.show; if (['pic', 'text'].includes(k.mode)) S.mode = k.mode; } catch (e) { /* privát mód */ }
	const save = () => { try { localStorage.setItem('cpx_bgcat', JSON.stringify({ show: S.show, mode: S.mode })); } catch (e) { /* privát mód */ } };

	/* a hátterek és a párok összefésülése: minden pár egyszer, a listában nem szereplő párnév jelölve */
	function model() {
		const bgs = Array.isArray(C.bgs) ? C.bgs : [];
		const by = new Map(bgs.map(b => [lc(b.name), b]));
		const P = C.bgPairs || {};
		const pairs = [], inPair = new Set(), unknown = [];
		Object.keys(P).forEach(k => {
			const other = lc(P[k]);
			if (inPair.has(k) || inPair.has(other)) return;
			inPair.add(k); inPair.add(other);
			const a = by.get(k) || { name: P[other] || k, img: '', qty: 1, missing: 1 }, b = by.get(other) || { name: P[k], img: '', qty: 1, missing: 1 };
			if (a.missing) unknown.push(a.name);
			if (b.missing) unknown.push(b.name);
			pairs.push([a, b]);
		});
		pairs.sort((x, y) => x[0].name.localeCompare(y[0].name, 'hu'));
		const singles = bgs.filter(b => !inPair.has(lc(b.name))).sort((x, y) => x.name.localeCompare(y.name, 'hu'));
		return { bgs, pairs, singles, unknown };
	}
	const hit = (b, q) => !q || fold(b.name).includes(q);
	const pic = (b, cls) => '<button type="button" class="bc-img' + (cls ? ' ' + cls : '') + '"' + (b.img ? ' data-zoom="' + esc(b.img) + '" data-zn="' + esc(b.name) + '"' : ' disabled') + ' aria-label="' + esc(b.name) + ' – nagyítás">' +
		(b.img ? '<img src="' + esc(b.img) + '" alt="" loading="lazy">' : '<span>' + (b.missing ? 'nincs a háttérlistában' : 'nincs kép') + '</span>') + '</button>';
	const qty = b => Number(b.qty) > 1 ? '<i class="bc-qty" title="Ennyi darab van belőle">' + Number(b.qty) + ' db</i>' : '';

	function view(main) {
		S.main = main;
		const m = model();
		main.innerHTML = '<div class="bc"><div class="bc-head"><div><h2>Háttérkatalógus</h2><p>Az összes háttér képpel, és a <b>kétoldalas hátterek</b>: egy fizikai háttér két grafikával – ha az egyik oldalt lefoglalják, aznapra a párja is foglalt.</p></div>' +
			'<div class="bc-sum"><span><b>' + m.bgs.length + '</b> háttér</span><span><b>' + m.pairs.length + '</b> kétoldalas pár</span><span><b>' + m.singles.length + '</b> egyoldalas</span></div></div>' +
			(m.unknown.length ? '<p class="bc-warn">A párlistában van olyan név, ami a háttérlistában nem szerepel (elírás vagy kivezetett háttér): <b>' + m.unknown.map(esc).join(', ') + '</b>. Javítani a WordPressben, a Foglaltsági panel beállításainál, a „Kétoldalas hátterek” mezőben lehet.</p>' : '') +
			'<div class="bc-bar"><div class="crm-seg">' + [['pic', 'Képes'], ['text', 'Szöveges']].map(x => '<button type="button" data-mode="' + x[0] + '" class="' + (S.mode === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div>' +
			'<div class="crm-seg">' + [['all', 'Mind'], ['pair', 'Kétoldalas'], ['single', 'Egyoldalas']].map(x => '<button type="button" data-show="' + x[0] + '" class="' + (S.show === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div>' +
			'<input type="search" data-q placeholder="Háttér neve…" value="' + esc(S.q) + '" aria-label="Keresés"><button type="button" class="cpx-btn ghost" data-copy>Lista másolása</button></div><div class="bc-body"></div></div>';
		main.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { S.mode = b.dataset.mode; save(); view(main); }));
		main.querySelectorAll('[data-show]').forEach(b => b.addEventListener('click', () => { S.show = b.dataset.show; save(); view(main); }));
		let t = null;
		main.querySelector('[data-q]').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => { S.q = e.target.value; draw(main.querySelector('.bc-body'), model()); }, 120); });
		main.querySelector('[data-copy]').addEventListener('click', () => copy(model()));
		draw(main.querySelector('.bc-body'), m);
	}
	function draw(box, m) {
		const q = fold(S.q).trim();
		const pairs = S.show === 'single' ? [] : m.pairs.filter(p => hit(p[0], q) || hit(p[1], q));
		const singles = S.show === 'pair' ? [] : m.singles.filter(b => hit(b, q));
		let h = '';
		if (S.mode === 'pic') {
			if (pairs.length) h += '<h3>Kétoldalas párok <small>' + pairs.length + '</small></h3><div class="bc-pairs">' + pairs.map(p => '<div class="bc-pair"><div class="bc-side">' + pic(p[0]) + '<b>' + esc(p[0].name) + '</b>' + qty(p[0]) + '</div><span class="bc-link" title="Egy fizikai háttér két oldala">⇄</span><div class="bc-side">' + pic(p[1]) + '<b>' + esc(p[1].name) + '</b>' + qty(p[1]) + '</div></div>').join('') + '</div>';
			if (singles.length) h += '<h3>Egyoldalas hátterek <small>' + singles.length + '</small></h3><div class="bc-grid">' + singles.map(b => '<div class="bc-one">' + pic(b) + '<b>' + esc(b.name) + '</b>' + qty(b) + '</div>').join('') + '</div>';
		} else {
			if (pairs.length) h += '<h3>Kétoldalas párok <small>' + pairs.length + '</small></h3><table class="bc-t"><thead><tr><th>#</th><th>Egyik oldal</th><th></th><th>Másik oldal</th></tr></thead><tbody>' + pairs.map((p, i) => '<tr><td>' + (i + 1) + '.</td><td>' + esc(p[0].name) + (p[0].missing ? ' <small class="bc-miss">nincs a listában</small>' : '') + '</td><td>⇄</td><td>' + esc(p[1].name) + (p[1].missing ? ' <small class="bc-miss">nincs a listában</small>' : '') + '</td></tr>').join('') + '</tbody></table>';
			if (singles.length) h += '<h3>Egyoldalas hátterek <small>' + singles.length + '</small></h3><ul class="bc-list">' + singles.map(b => '<li>' + esc(b.name) + (Number(b.qty) > 1 ? ' <small>(' + Number(b.qty) + ' db)</small>' : '') + '</li>').join('') + '</ul>';
		}
		box.innerHTML = h || '<p class="cpx-muted">Nincs ilyen háttér.</p>';
		box.querySelectorAll('[data-zoom]').forEach(b => b.addEventListener('click', () => zoom(b.dataset.zoom, b.dataset.zn, m)));
	}
	/* a WordPress bélyegkép (…-300x300.jpg) helyett az eredeti méret; ha nincs, a bélyegkép marad */
	const big = u => String(u).replace(/-\d{2,4}x\d{2,4}(?=[-.][^/]*$)/, '');
	/* nagyítás: a háttér képe, és ha kétoldalas, a párja is mellette */
	function zoom(src, name, m) {
		const p = m.pairs.find(x => lc(x[0].name) === lc(name) || lc(x[1].name) === lc(name));
		const items = p ? p : [m.bgs.find(b => lc(b.name) === lc(name)) || { name: name, img: src }];
		const d = document.createElement('div');
		d.className = 'bc-zoom';
		d.innerHTML = '<div class="bc-zin"><button type="button" class="cpx-x" aria-label="Bezárás">×</button><div class="bc-zr' + (p ? ' two' : '') + '">' + items.map(b => '<figure>' + (b.img ? '<img src="' + esc(big(b.img)) + '" data-thumb="' + esc(b.img) + '" alt="' + esc(b.name) + '">' : '<span>nincs kép</span>') + '<figcaption>' + esc(b.name) + '</figcaption></figure>').join(p ? '<span class="bc-link">⇄</span>' : '') + '</div>' +
			(p ? '<p>Kétoldalas háttér: <b>' + esc(p[0].name) + '</b> és <b>' + esc(p[1].name) + '</b> ugyanannak a háttérnek a két oldala – egy napon csak az egyik használható.</p>' : '<p>Egyoldalas háttér.</p>') + '</div>';
		document.body.appendChild(d);
		d.querySelectorAll('img[data-thumb]').forEach(i => i.addEventListener('error', () => { if (i.src !== i.dataset.thumb) i.src = i.dataset.thumb; }, { once: true }));
		const close = () => { d.remove(); document.removeEventListener('keydown', key); };
		const key = e => { if (e.key === 'Escape') close(); };
		d.addEventListener('click', e => { if (e.target === d || e.target.closest('.cpx-x')) close(); });
		document.addEventListener('keydown', key);
	}
	/* szöveges lista a vágólapra (pl. Pumble-be, levélbe) */
	async function copy(m) {
		const t = 'KÉTOLDALAS HÁTTEREK (egy fizikai háttér két oldala)\n' + m.pairs.map(p => '• ' + p[0].name + ' ⇄ ' + p[1].name).join('\n') +
			'\n\nEGYOLDALAS HÁTTEREK\n' + m.singles.map(b => '• ' + b.name + (Number(b.qty) > 1 ? ' (' + Number(b.qty) + ' db)' : '')).join('\n');
		let ok = false;
		try { await navigator.clipboard.writeText(t); ok = true; } catch (e) { /* régi böngésző */ }
		if (!ok) { const ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { ok = document.execCommand('copy'); } catch (e) { /* nincs */ } ta.remove(); }
		const b = S.main && S.main.querySelector('[data-copy]');
		if (b) { b.textContent = ok ? '✓ Kimásolva' : 'Nem sikerült másolni'; setTimeout(() => { if (b.isConnected) b.textContent = 'Lista másolása'; }, 2200); }
	}
	function status(s) { s.textContent = ''; s.className = 'cpx-status'; }
	async function refresh() { if (S.main && S.main.isConnected) view(S.main); }
	window.CPBgCat = { view, status, refresh };

	const css = document.createElement('style');
	css.textContent = '.bc{max-width:1400px}.bc-head{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-end}.bc-head h2{margin:0 0 4px;font:800 20px "Plus Jakarta Sans",sans-serif}.bc-head p{margin:0;font-size:13.5px;max-width:720px}' +
		'.bc-sum{display:flex;gap:8px;flex-wrap:wrap}.bc-sum span{background:#fff;border:1px solid #E6E3DE;border-radius:10px;padding:6px 12px;font-size:12.5px;color:#6B6F78}.bc-sum b{color:#141C33;font-size:15px;margin-right:3px}' +
		'.bc-warn{margin:10px 0 0;background:#FFF7E0;color:#7F5F01;border-radius:8px;padding:8px 12px;font-size:13px}' +
		'.bc-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0 6px}.bc-bar input{flex:1;min-width:180px;max-width:320px;border:1px solid #E6E3DE;border-radius:8px;padding:7px 9px;font:13px Inter,sans-serif;background:#fff}' +
		'.bc h3{margin:18px 0 10px;font:800 15px "Plus Jakarta Sans",sans-serif}.bc h3 small{color:#8A8E96;font:600 12px Inter,sans-serif}' +
		'.bc-pairs{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:12px}.bc-pair{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #E6E3DE;border-radius:14px;padding:10px;box-shadow:0 1px 2px rgba(20,28,51,.05)}' +
		'.bc-side,.bc-one{flex:1;min-width:0;display:flex;flex-direction:column;gap:5px;position:relative}.bc-side b,.bc-one b{font-size:13px;font-weight:700;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
		'.bc-link{flex:none;width:30px;height:30px;border-radius:50%;background:#FBEBDD;color:#C2621A;display:flex;align-items:center;justify-content:center;font:800 15px Inter,sans-serif}' +
		'.bc-img{display:block;width:100%;aspect-ratio:1/1;border:0;padding:0;border-radius:10px;overflow:hidden;background:#F1EFEB;cursor:zoom-in}.bc-img[disabled]{cursor:default}.bc-img img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .2s}.bc-img:hover img{transform:scale(1.04)}' +
		'.bc-img span{display:flex;height:100%;align-items:center;justify-content:center;color:#8A8E96;font-size:12px;padding:6px;text-align:center}.bc-qty{position:absolute;top:6px;right:6px;background:rgba(20,28,51,.75);color:#fff;font:600 11px Inter,sans-serif;font-style:normal;border-radius:999px;padding:1px 7px}' +
		'.bc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}.bc-one{background:#fff;border:1px solid #E6E3DE;border-radius:12px;padding:8px}' +
		'.bc-t{border-collapse:collapse;background:#fff;border:1px solid #E6E3DE;border-radius:12px;overflow:hidden;font-size:13.5px;min-width:min(560px,100%)}.bc-t th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#8A8E96;padding:8px 12px;background:#FAF8F5;border-bottom:1px solid #E6E3DE}.bc-t td{padding:7px 12px;border-bottom:1px solid #F0ECE6}.bc-t td:first-child{color:#8A8E96;width:36px}.bc-t td:nth-child(3){color:#C2621A;font-weight:800;width:30px;text-align:center}' +
		'.bc-miss{color:#AE2E24}.bc-list{columns:4 180px;margin:0;padding:0 0 0 18px;font-size:13.5px;line-height:1.8}.bc-list small{color:#8A8E96}' +
		'.bc-zoom{position:fixed;inset:0;z-index:96;background:rgba(20,28,51,.7);display:flex;align-items:center;justify-content:center;padding:16px}.bc-zin{position:relative;background:#fff;border-radius:16px;padding:16px;max-width:min(1000px,100%);max-height:100%;overflow:auto}.bc-zin .cpx-x{position:absolute;top:8px;right:8px}' +
		'.bc-zr{display:flex;align-items:center;gap:12px;justify-content:center}.bc-zr figure{margin:0;flex:1;min-width:0;max-width:440px}.bc-zr img{width:100%;max-height:70vh;object-fit:contain;border-radius:10px;display:block;background:#F1EFEB}.bc-zr figcaption{text-align:center;font-weight:700;margin-top:6px}.bc-zin p{margin:10px 0 0;text-align:center;font-size:13.5px}' +
		'@media (max-width:600px){.bc-pairs{grid-template-columns:1fr}.bc-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.bc-zr.two{flex-direction:column}.bc-zr.two .bc-link{transform:rotate(90deg)}}';
	document.head.appendChild(css);
})();
