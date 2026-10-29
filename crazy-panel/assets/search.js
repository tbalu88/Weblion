/* Crazy panel – univerzális kereső (v1.49): a menü tetején vagy Ctrl+K / Cmd+K – deal, kontakt, cég, levél, feladat egy helyen */
(function () {
	'use strict';
	const C = window.CPX || {};
	if (!C.sr || !C.sr.on) return;
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const MAC = /Mac|iPhone|iPad/.test(navigator.platform || '');
	const HU_MS = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
	const huD = s => { if (!s) return ''; const p = String(s).slice(0, 10).split('-').map(Number); return p[0] + '. ' + HU_MS[p[1] - 1] + ' ' + p[2] + '.'; };
	const money = v => v == null ? '' : Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft';
	const ST = { Open: 'nyitott', Won: 'megnyert', Lost: 'elvesztett' };
	const S = { el: null, q: '', items: [], i: 0, seq: 0, t: null };

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
	/* a legutóbb megnyitottak (ebben a böngészőben) */
	function recent() { try { const v = JSON.parse(localStorage.getItem('cpx_recent') || '[]'); return Array.isArray(v) ? v.slice(0, 8) : []; } catch (e) { return []; } }
	function remember(it) { try { const l = recent().filter(x => !(x.k === it.k && x.id === it.id)); l.unshift({ k: it.k, id: it.id, t: it.t, s: it.s }); localStorage.setItem('cpx_recent', JSON.stringify(l.slice(0, 8))); } catch (e) { /* privát mód */ } }

	const KI = { deal: '$', ct: '👤', co: '🏢', mail: '✉', card: '☑' };
	function openIt(it) {
		remember(it);
		close();
		if (it.k === 'deal' && window.CPCrm) window.CPCrm.open(it.id);
		else if (it.k === 'ct' && window.CPCrm && window.CPCrm.contact) window.CPCrm.contact(it.id);
		else if (it.k === 'co' && window.CPCrm && window.CPCrm.company) window.CPCrm.company(it.id);
		else if (it.k === 'mail' && window.CPMail) window.CPMail.open(it.id);
		else if (it.k === 'card' && window.CPNotify) window.CPNotify.go('feladatok:' + it.id);
	}
	function row(it, n) {
		return '<button type="button" class="sr-it' + (n === S.i ? ' on' : '') + '" data-n="' + n + '"><span class="sr-k k-' + it.k + '">' + KI[it.k] + '</span><span class="sr-b"><b>' + esc(it.t) + '</b>' + (it.s ? '<small>' + esc(it.s) + '</small>' : '') + (it.x ? '<em>' + esc(it.x) + '</em>' : '') + '</span>' + (it.r ? '<span class="sr-r">' + it.r + '</span>' : '') + '</button>';
	}
	function draw(groups, note) {
		const box = S.el.querySelector('.sr-res');
		S.items = [];
		let h = '';
		groups.forEach(g => {
			if (!g[1].length) return;
			h += '<div class="sr-gh">' + esc(g[0]) + '</div>';
			g[1].forEach(it => { h += row(it, S.items.length); S.items.push(it); });
		});
		if (S.i >= S.items.length) S.i = 0;
		box.innerHTML = h || '<div class="sr-none">' + esc(note || 'Nincs találat.') + '</div>';
		box.querySelectorAll('[data-n]').forEach(b => {
			b.addEventListener('click', () => openIt(S.items[Number(b.dataset.n)]));
			b.addEventListener('mousemove', () => { if (S.i !== Number(b.dataset.n)) { S.i = Number(b.dataset.n); mark(); } });
		});
	}
	function mark() { S.el.querySelectorAll('.sr-it').forEach(b => b.classList.toggle('on', Number(b.dataset.n) === S.i)); const on = S.el.querySelector('.sr-it.on'); if (on) on.scrollIntoView({ block: 'nearest' }); }
	function showRecent() {
		const r = recent();
		draw([['Legutóbb megnyitott', r]], 'Kezdj el gépelni: deal neve, ügyfél, e-mail, telefonszám, cég, helyszín, #dealszám, vagy bármilyen szó a levelekből és a feladatokból.');
	}
	async function search(q) {
		const my = ++S.seq;
		S.el.querySelector('.sr-res').classList.add('busy');
		let r;
		try { r = await post('cp_search', { q: q }); } catch (e) { if (my === S.seq) draw([], e.message); return; }
		if (my !== S.seq || !S.el) return;
		S.el.querySelector('.sr-res').classList.remove('busy');
		S.i = 0;
		draw([
			['Deal-ek', r.deals.map(d => ({ k: 'deal', id: d.id, t: d.title || 'Deal #' + d.id, s: [d.cn, d.ev ? huD(d.ev) : '', d.stage].filter(Boolean).join(' · '), r: '<i class="sr-st ' + esc(String(d.st).toLowerCase()) + '">' + esc(ST[d.st] || d.st) + '</i>' + (d.v ? '<small>' + esc(money(d.v)) + '</small>' : '') }))],
			['Kontaktok', r.contacts.map(c => ({ k: 'ct', id: c.id, t: c.n, s: [c.e !== c.n ? c.e : '', c.co].filter(Boolean).join(' · '), r: '<small>' + c.c + ' deal</small>' }))],
			['Cégek', r.companies.map(c => ({ k: 'co', id: c.id, t: c.n, s: c.c + ' kontakt' }))],
			['Levelek', r.mail.map(m => ({ k: 'mail', id: m.id, t: m.subj || '(nincs tárgy)', s: [m.cn, huD(m.at), m.st === 'closed' ? 'lezárva' : ''].filter(Boolean).join(' · '), x: m.snip }))],
			['Feladatok', r.cards.map(c => ({ k: 'card', id: c.id, t: (c.done ? '✓ ' : '') + c.title, s: [c.where, c.due ? 'határidő: ' + huD(c.due) : ''].filter(Boolean).join(' · ') }))]
		], 'Nincs találat erre: „' + q + '”.');
	}
	function open(q) {
		if (S.el) { S.el.querySelector('input').focus(); return; }
		const el = document.createElement('div');
		el.className = 'sr-wrap';
		el.innerHTML = '<div class="sr" role="dialog" aria-modal="true" aria-label="Keresés"><div class="sr-in">' + (window.CPXIco ? window.CPXIco('search') : '🔍') + '<input type="search" placeholder="Keresés: deal, ügyfél, cég, e-mail, telefon, szó a levelekből…" aria-label="Keresés" autocomplete="off"><kbd>Esc</kbd></div><div class="sr-res"></div>' +
			'<div class="sr-ft"><span><kbd>↑</kbd><kbd>↓</kbd> választás</span><span><kbd>Enter</kbd> megnyitás</span><span><kbd>' + (MAC ? '⌘' : 'Ctrl') + '</kbd><kbd>K</kbd> bárhonnan</span></div></div>';
		document.body.appendChild(el);
		S.el = el; S.i = 0;
		const inp = el.querySelector('input');
		el.addEventListener('mousedown', e => { if (e.target === el) close(); });
		inp.addEventListener('input', () => {
			clearTimeout(S.t);
			const q = inp.value.trim();
			if (q.length < 2) { S.seq++; showRecent(); return; }
			S.t = setTimeout(() => search(q), 220);
		});
		inp.addEventListener('keydown', e => {
			if (e.key === 'ArrowDown') { e.preventDefault(); if (S.items.length) { S.i = (S.i + 1) % S.items.length; mark(); } }
			else if (e.key === 'ArrowUp') { e.preventDefault(); if (S.items.length) { S.i = (S.i - 1 + S.items.length) % S.items.length; mark(); } }
			else if (e.key === 'Enter') { e.preventDefault(); if (S.items[S.i]) openIt(S.items[S.i]); }
			else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
		});
		if (q) { inp.value = q; search(q); } else showRecent();
		inp.focus();
	}
	function close() { if (S.el) { S.el.remove(); S.el = null; S.seq++; } }

	/* a menü tetején: keresőmező-gomb */
	function mount() {
		const menu = document.getElementById('cpx-menu');
		if (!menu) return false;
		if (menu.querySelector('.sr-btn')) return true;
		const b = document.createElement('button');
		b.type = 'button';
		b.className = 'sr-btn';
		b.title = 'Keresés (' + (MAC ? '⌘' : 'Ctrl') + '+K)';
		b.innerHTML = (window.CPXIco ? window.CPXIco('search') : '🔍') + '<span>Keresés…</span><kbd>' + (MAC ? '⌘K' : 'Ctrl K') + '</kbd>';
		menu.insertBefore(b, menu.firstChild);
		b.addEventListener('click', e => { e.stopPropagation(); open(''); });
		/* mobilon a felső sávban is (a menü kinyitása nélkül) */
		const bg = document.getElementById('cpx-burger');
		if (bg && !document.querySelector('.sr-mob')) {
			const m = document.createElement('button');
			m.type = 'button'; m.className = 'sr-mob'; m.setAttribute('aria-label', 'Keresés');
			m.innerHTML = window.CPXIco ? window.CPXIco('search') : '🔍';
			bg.parentNode.insertBefore(m, bg);
			m.addEventListener('click', e => { e.stopPropagation(); open(''); });
		}
		return true;
	}
	document.addEventListener('keydown', e => {
		if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); if (S.el) close(); else open(''); }
	});
	const css = document.createElement('style');
	css.textContent = '.sr-btn{display:flex;align-items:center;gap:8px;width:100%;margin:0 0 10px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#C9CDD6;border-radius:10px;padding:8px 10px;font:500 13.5px Inter,sans-serif;cursor:pointer;text-align:left}' +
		'.sr-btn:hover{background:rgba(255,255,255,.12);color:#fff}.sr-btn span{flex:1}.sr-btn kbd{font:600 10.5px Inter,sans-serif;border:1px solid rgba(255,255,255,.2);border-radius:5px;padding:1px 5px;color:#9AA0AA}.sr-btn .cpx-ico{width:18px;height:18px;flex:none}' +
		'.cpx-mini .sr-btn{justify-content:center;padding:8px 0}.cpx-mini .sr-btn span,.cpx-mini .sr-btn kbd{display:none}' +
		'.sr-wrap{position:fixed;inset:0;z-index:95;background:rgba(20,28,51,.45);display:flex;justify-content:center;align-items:flex-start;padding:10vh 16px 16px}' +
		'.sr{width:min(680px,100%);max-height:76vh;background:#fff;border-radius:16px;box-shadow:0 30px 70px -20px rgba(20,28,51,.6);display:flex;flex-direction:column;overflow:hidden;color:#141C33}' +
		'.sr-in{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #EEEAE4}.sr-in .cpx-ico{width:20px;height:20px;fill:none;stroke:#6B6F78;stroke-width:2;flex:none}.sr-in input{flex:1;border:0;outline:0;font:500 16px Inter,sans-serif;color:#141C33;background:none;min-width:0}' +
		'.sr kbd{font:600 11px Inter,sans-serif;border:1px solid #E1DDD6;border-bottom-width:2px;border-radius:5px;padding:0 5px;color:#6B6F78;background:#FAF8F5}' +
		'.sr-res{overflow-y:auto;padding:6px 0;min-height:60px}.sr-res.busy{opacity:.6}.sr-gh{padding:8px 16px 4px;font:700 11px Inter,sans-serif;text-transform:uppercase;letter-spacing:.05em;color:#8A8E96}' +
		'.sr-it{display:flex;align-items:center;gap:10px;width:100%;border:0;background:none;text-align:left;padding:7px 16px;cursor:pointer;font:13.5px Inter,sans-serif;color:#141C33}.sr-it.on{background:#EEF3FD}' +
		'.sr-k{flex:none;width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#F1EFEB;font-size:13px;font-weight:700;color:#6B6F78}.sr-k.k-deal{background:#E4ECFC;color:#2F5BD8}.sr-k.k-ct{background:#FBEBDD}.sr-k.k-co{background:#E9F5EE}.sr-k.k-mail{background:#EEF3FD;color:#2F5BD8}.sr-k.k-card{background:#F3EEFC;color:#6E4FC4}' +
		'.sr-b{flex:1;min-width:0}.sr-b b{display:block;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sr-b small{display:block;color:#8A8E96;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sr-b em{display:block;font-style:normal;color:#4A4F5A;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
		'.sr-r{flex:none;display:flex;flex-direction:column;align-items:flex-end;gap:2px}.sr-r small{color:#6B6F78;font-size:12px}.sr-st{font-style:normal;font:600 11px Inter,sans-serif;border-radius:999px;padding:1px 7px;background:#EEF0F4;color:#141C33}.sr-st.won{background:#DFF3E8;color:#216E4E}.sr-st.lost{background:#FDECEA;color:#AE2E24}' +
		'.sr-none{padding:20px 16px;color:#6B6F78;font-size:13.5px}.sr-ft{display:flex;gap:16px;padding:8px 16px;border-top:1px solid #EEEAE4;font-size:12px;color:#8A8E96;flex-wrap:wrap}.sr-ft kbd{margin-right:2px}' +
		'.sr-mob{display:none;margin-left:auto;margin-right:8px;width:40px;height:40px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:transparent;color:#fff;align-items:center;justify-content:center;cursor:pointer}.sr-mob .cpx-ico{width:20px;height:20px}' +
		'@media (max-width:1024px){.sr-mob{display:flex}}' +
		'@media (max-width:600px){.sr-wrap{padding:8px}.sr{max-height:90vh}.sr-ft{display:none}}';
	document.head.appendChild(css);
	let tries = 0;
	const t = setInterval(() => { if (mount() || ++tries > 50) clearInterval(t); }, 200);
	window.CPSearch = { open, close };
})();
