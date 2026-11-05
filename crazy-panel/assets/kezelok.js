/* Crazy panel – Kezelők (v2.6): a gépkezelők / hostok adatai táblázatban, minden mező helyben szerkeszthető.
   A személyes azonosítók (TAJ, személyi, adóazonosító, anyja neve, lakcím, születési adatok) csak adminnak, külön gombra jelennek meg. */
(function () {
	'use strict';
	const C = window.CPX || {};
	const K = C.kz || {};
	if (!K.on) return;
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const MON = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
	const huD = s => { if (!s) return ''; const p = String(s).slice(0, 10).split('-').map(Number); return MON[p[1] - 1] + ' ' + p[2] + '.'; };
	const SENS = K.sens || {};
	const SK = Object.keys(SENS);
	const S = { rows: [], el: [], reveal: false, q: '', inact: false, main: null, err: '', open: 0 };
	async function post(action, params) {
		const fd = new FormData(); fd.append('action', action); fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j; try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j && j.data && j.data.msg) || 'Hiba'));
		return j.data;
	}
	function toast(t, bad) { let d = document.getElementById('cpx-toast'); if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); } d.textContent = t; d.classList.toggle('bad', !!bad); d.classList.add('on'); clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), bad ? 7000 : 2500); }
	const COLS = [['name', 'Név', 'text'], ['email', 'E-mail', 'email'], ['phone', 'Telefon', 'tel'], ['plate', 'Autó rendszám', 'text']].concat(SK.map(k => [k, SENS[k], 'text', 1]));
	async function load() {
		try { const r = await post('cp_kz_list', { reveal: S.reveal ? 1 : '' }); S.rows = r.rows; S.el = r.el; S.err = ''; } catch (e) { S.err = e.message; }
		draw();
	}
	function cell(x, c) {
		const [k, , type, sens] = c;
		if (sens && !S.reveal) {
			const has = (x.have || []).includes(k);
			return '<td class="kz-s ' + (has ? 'ok' : 'no') + '" title="' + (has ? 'Megadva – a megtekintéshez: „Személyes adatok megjelenítése”' : 'Hiányzik') + '">' + (has ? '•••' : 'hiányzik') + '</td>';
		}
		const v = sens ? ((x.sens || {})[k] || '') : (x[k] || '');
		const ro = sens && !K.admin;
		return '<td' + (sens ? ' class="kz-sv"' : '') + '><input type="' + type + '" data-f="' + k + '" value="' + esc(v) + '"' + (ro ? ' readonly' : '') + ' aria-label="' + esc(c[1]) + '"' + (k === 'name' ? ' required' : '') + '></td>';
	}
	function draw() {
		const main = S.main; if (!main || !main.isConnected) return;
		const q = S.q.toLowerCase();
		const L = S.rows.filter(x => (S.inact || x.active) && (!q || [x.name, x.email, x.phone, x.plate, x.note].join(' ').toLowerCase().includes(q)));
		const miss = S.rows.filter(x => x.active && x.miss.length).length;
		main.querySelector('.kz-body').innerHTML = S.err ? '<div class="cpx-empty err">' + esc(S.err) + '</div>' :
			'<div class="kz-n">' + L.length + ' kezelő' + (miss ? ' · <b class="kz-warn">' + miss + ' kezelőnél hiányzik személyes adat</b>' : '') + (S.reveal ? ' · <span class="kz-open">🔓 a személyes adatok láthatók – a megtekintés naplózva</span>' : '') + '</div>' +
			'<div class="kz-tw"><table class="kz-t"><thead><tr><th class="kz-fl"></th>' + COLS.map(c => '<th' + (c[3] ? ' class="kz-sh"' : '') + '>' + esc(c[1]) + '</th>').join('') + '<th>Elszámolás</th><th>Közelgő</th><th></th></tr></thead><tbody>' +
			L.map(x => '<tr data-id="' + x.id + '" class="' + (x.flag ? 'fl-' + x.flag : '') + (x.active ? '' : ' off') + '"><td class="kz-fl"><button type="button" data-flag title="Jelölés (szín) váltása" aria-label="Jelölés">●</button></td>' + COLS.map(c => cell(x, c)).join('') +
				'<td><select data-f="el_id" aria-label="Kezelői elszámolás"><option value="">–</option>' + S.el.map(e => '<option value="' + esc(e.id) + '"' + (e.id === x.el_id ? ' selected' : '') + '>' + esc(e.name) + '</option>').join('') + '</select></td>' +
				'<td class="kz-up">' + (x.up.length ? '<button type="button" class="kz-link" data-up>' + x.up.length + ' rendezvény<small>' + esc(huD(x.up[0].event_d)) + '</small></button>' : '<span class="cpx-muted">–</span>') + '</td>' +
				'<td class="kz-act"><button type="button" class="kz-link" data-more title="Megjegyzés, elérhetőség, törlés">⋯</button></td></tr>' +
				(S.open === x.id ? '<tr class="kz-det"><td colspan="' + (COLS.length + 4) + '">' + detail(x) + '</td></tr>' : '')).join('') +
			'</tbody></table></div>';
		bind();
		if (window.CPXStatus) window.CPXStatus();
	}
	function detail(x) {
		return '<div class="kz-d"><div><label>Megjegyzés<textarea data-f="note" rows="2">' + esc(x.note) + '</textarea></label>' +
			'<label class="kz-chk"><input type="checkbox" data-f="active"' + (x.active ? ' checked' : '') + '> Aktív kezelő</label>' +
			(x.miss.length ? '<p class="kz-warn">Hiányzik: ' + esc(x.miss.map(k => SENS[k]).join(', ')) + '</p>' : '') +
			'<p class="cpx-muted">' + (x.phone ? '<a href="tel:' + esc(x.phone.replace(/[^\d+]/g, '')) + '">📞 Hívás</a> · ' : '') + (x.email ? '<a href="mailto:' + esc(x.email) + '">✉ E-mail</a> · ' : '') + (x.el ? 'Elszámolás: ' + esc(x.el.name) + ' (' + esc(x.el.role) + ', ' + x.el.rate + ' Ft/óra) · ' : '') + 'Módosítva: ' + esc(x.updated || '') + (x.by ? ' (' + esc(x.by) + ')' : '') + '</p>' +
			(K.admin ? '<button type="button" class="cpx-btn ghost danger sm" data-del>Kezelő törlése</button>' : '') + '</div>' +
			'<div><b>Közelgő rendezvényei</b> <small class="cpx-muted">(a deal „Gépkezelő” mezője alapján)</small>' + (x.up.length ? '<ul>' + x.up.map(u => '<li><a href="#deals:' + u.id + '" data-deal="' + u.id + '">' + esc(huD(u.event_d)) + ' – ' + esc(u.title) + '</a>' + (u.venue ? ' <small>' + esc(u.venue) + '</small>' : '') + '</li>').join('') + '</ul>' : '<p class="cpx-muted">Nincs.</p>') + '</div></div>';
	}
	async function save(id, f, el) {
		if (el) el.classList.add('saving');
		try {
			const r = await post('cp_kz_save', { id: id || '', f: JSON.stringify(f), reveal: S.reveal ? 1 : '' });
			const i = S.rows.findIndex(x => x.id === r.row.id);
			if (i >= 0) S.rows[i] = r.row; else S.rows.push(r.row);
			if (el) { el.classList.remove('saving'); el.classList.add('saved'); setTimeout(() => el.classList.remove('saved'), 1200); }
			return r.row;
		} catch (e) { if (el) el.classList.remove('saving'); toast(e.message, true); return null; }
	}
	function bind() {
		const main = S.main;
		main.querySelectorAll('tr[data-id], tr.kz-det').forEach(tr => {
			const id = Number((tr.dataset.id || (tr.previousElementSibling && tr.previousElementSibling.dataset.id)) || 0);
			const x = S.rows.find(z => z.id === id); if (!x) return;
			tr.querySelectorAll('[data-f]').forEach(inp => {
				inp.addEventListener('change', async () => {
					const k = inp.dataset.f, v = inp.type === 'checkbox' ? (inp.checked ? 1 : 0) : inp.value.trim();
					if (k === 'name' && !v) { toast('A név nem lehet üres.', true); inp.value = x.name; return; }
					const r = await save(id, { [k]: v }, inp);
					if (r && (k === 'el_id' || k === 'active' || k === 'name')) draw();
				});
				if (inp.tagName === 'INPUT') inp.addEventListener('keydown', e => { if (e.key === 'Enter') inp.blur(); });
			});
			const fl = tr.querySelector('[data-flag]');
			if (fl) fl.addEventListener('click', async () => { const o = ['', 'red', 'yellow', 'green']; const n = o[(o.indexOf(x.flag) + 1) % o.length]; if (await save(id, { flag: n })) draw(); });
			const more = tr.querySelector('[data-more]'), up = tr.querySelector('[data-up]');
			[more, up].forEach(b => b && b.addEventListener('click', () => { S.open = S.open === id ? 0 : id; draw(); }));
			const del = tr.querySelector('[data-del]');
			if (del) del.addEventListener('click', async () => { if (!window.confirm('Törlöd ' + x.name + ' adatait? Ez nem vonható vissza.')) return; try { await post('cp_kz_delete', { id }); S.rows = S.rows.filter(z => z.id !== id); toast('Törölve.'); draw(); } catch (e) { toast(e.message, true); } });
			tr.querySelectorAll('[data-deal]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); if (window.CPCrm) window.CPCrm.open(Number(a.dataset.deal)); }));
		});
	}
	function view(main) {
		S.main = main;
		main.innerHTML = '<div class="kz"><div class="kz-h"><input type="search" data-q placeholder="Keresés: név, e-mail, telefon, rendszám" value="' + esc(S.q) + '" aria-label="Keresés">' +
			'<label class="crm-chk"><input type="checkbox" data-inact' + (S.inact ? ' checked' : '') + '> Inaktívak is</label>' +
			(K.admin ? '<button type="button" class="cpx-btn ghost" data-rev>' + (S.reveal ? '🔒 Személyes adatok elrejtése' : '🔓 Személyes adatok megjelenítése') + '</button>' + (K.csv ? '<a class="cpx-btn ghost" href="' + esc(K.csv) + '" title="Minden adat letöltése (CSV) – naplózva">⬇ CSV</a>' : '') : '') +
			'<button type="button" class="cpx-btn" data-new>+ Új kezelő</button></div>' +
			'<p class="kz-help">Minden mező közvetlenül szerkeszthető: kattints bele, írd át, és Enterrel vagy kattintással máshova mentődik. A személyes azonosítókat titkosítva tároljuk, csak admin látja és módosíthatja.</p><div class="kz-body"><div class="cpx-empty">Betöltés…</div></div></div>';
		let t = null;
		main.querySelector('[data-q]').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => { S.q = e.target.value.trim(); draw(); }, 200); });
		main.querySelector('[data-inact]').addEventListener('change', e => { S.inact = e.target.checked; draw(); });
		const rv = main.querySelector('[data-rev]');
		if (rv) rv.addEventListener('click', () => { S.reveal = !S.reveal; view(main); });
		main.querySelector('[data-new]').addEventListener('click', async () => {
			const n = (window.prompt('Az új kezelő neve:', '') || '').trim(); if (!n) return;
			const r = await save(0, { name: n }); if (r) { S.open = r.id; draw(); toast('Felvéve – töltsd ki a többi adatát.'); }
		});
		load();
	}
	function status(s) { s.textContent = S.err || ''; s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	window.CPKezelok = { view, status };

	const css = document.createElement('style');
	css.textContent = '.kz{max-width:100%}.kz-h{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px}.kz-h input[type=search]{flex:1;min-width:200px;border:1px solid #E6E3DE;border-radius:8px;padding:7px 9px;font:13px Inter,sans-serif}.kz-help{font-size:12.5px;color:#6B6F78;margin:0 0 8px}' +
		'.kz-n{font-size:12.5px;color:#6B6F78;margin-bottom:6px}.kz-warn{color:#B4400E}.kz-open{color:#17663A}.kz-tw{background:#fff;border:1px solid #E6E3DE;border-radius:12px;overflow:auto}' +
		'.kz-t{border-collapse:collapse;font-size:13px;min-width:100%}.kz-t th{background:#E9F3E2;color:#2D4A1E;font-size:11.5px;text-align:left;padding:7px 6px;border-bottom:1px solid #D4E6C6;white-space:nowrap;position:sticky;top:0}.kz-t th.kz-sh{background:#F1F5EC}' +
		'.kz-t td{border-bottom:1px solid #F0ECE6;padding:2px 3px;vertical-align:middle}.kz-t input,.kz-t select{width:100%;min-width:110px;border:1px solid transparent;border-radius:6px;padding:6px 6px;font:13px Inter,sans-serif;background:transparent;color:#141C33}' +
		'.kz-t input:hover,.kz-t select:hover{border-color:#E6E3DE}.kz-t input:focus,.kz-t select:focus{border-color:#2F5BD8;background:#fff;outline:none}.kz-t input[data-f=name]{font-weight:600;min-width:150px}.kz-t input[data-f=email]{min-width:190px}.kz-t td.kz-sv input{min-width:120px}.kz-t input[data-f=address]{min-width:230px}' +
		'.kz-t input.saving{background:#FFF8E1}.kz-t input.saved,.kz-t select.saved{background:#E8F6EE}.kz-s{font-size:12px;text-align:center;white-space:nowrap}.kz-s.ok{color:#17663A}.kz-s.no{color:#B4400E;background:#FDF1EC}' +
		'.kz-fl{width:26px;text-align:center}.kz-fl button{border:0;background:none;cursor:pointer;font-size:15px;color:#D0CCC5}tr.fl-red td{background:#FBE9E7}tr.fl-red .kz-fl button{color:#D84315}tr.fl-yellow td{background:#FFF8E1}tr.fl-yellow .kz-fl button{color:#F9A825}tr.fl-green td{background:#EAF6EC}tr.fl-green .kz-fl button{color:#2E7D32}tr.off td{opacity:.55}' +
		'.kz-link{border:0;background:none;color:#2F5BD8;cursor:pointer;font:600 12.5px Inter,sans-serif;text-align:left;white-space:nowrap}.kz-link small{display:block;color:#8A8E96;font-weight:500}.kz-act{width:30px}' +
		'.kz-det td{background:#FAF8F5!important}.kz-d{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:8px 6px}.kz-d label{display:block;font-size:12px;color:#6B6F78}.kz-d textarea{width:100%;border:1px solid #E6E3DE;border-radius:8px;padding:6px;font:13px Inter,sans-serif}.kz-d ul{margin:6px 0;padding-left:18px}.kz-d li{margin:3px 0}.kz-chk{display:flex!important;gap:6px;align-items:center;margin:6px 0;color:#141C33!important;font-size:13px!important}.kz-d input[type=checkbox]{width:auto;min-width:0;accent-color:#2F5BD8}' +
		'@media (max-width:760px){.kz-d{grid-template-columns:1fr}}';
	document.head.appendChild(css);
})();
