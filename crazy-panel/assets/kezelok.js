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
	const SKL = K.skills || {}, PRC = K.procs || {}, KINDS = K.kinds || { kezelo: 'Kezelők' };
	const S = { rows: [], el: [], reveal: false, q: '', inact: false, main: null, err: '', open: 0, kind: 'kezelo', edit: false };
	try { const k = localStorage.getItem('cpx_kz_kind'); if (KINDS[k]) S.kind = k; } catch (e) { /* privát mód */ }
	async function post(action, params) {
		const fd = new FormData(); fd.append('action', action); fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j; try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j && j.data && j.data.msg) || 'Hiba'));
		return j.data;
	}
	function toast(t, bad) { let d = document.getElementById('cpx-toast'); if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); } d.textContent = t; d.classList.toggle('bad', !!bad); d.classList.add('on'); clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), bad ? 7000 : 2500); }
	/* oszlopok csoportonként: [mező, fejléc, típus, személyes?] – típus: text/email/tel, car (lenyíló), chk (igen/nem), sk:<gép>, pr:<lépés> */
	const SENSC = SK.map(k => [k, SENS[k], 'text', 1]);
	const colsFor = kind => kind === 'kezelo' ? [['name', 'Név', 'text'], ['email', 'E-mail', 'email'], ['phone', 'Telefon', 'tel'], ['plate', 'Rendszám', 'text'], ['car', 'Autó típusa', 'car']].concat(Object.keys(SKL).map(k => ['sk:' + k, SKL[k], 'sk']), [['extra', 'Egyéb', 'text']], SENSC)
		: kind === 'host' ? [['name', 'Név', 'text'], ['email', 'E-mail', 'email'], ['phone', 'Telefon', 'tel'], ['has_car', 'Autó', 'chk'], ['extra', 'Egyéb', 'text']].concat(SENSC)
		: [['name', 'Név', 'text'], ['email', 'E-mail', 'email'], ['phone', 'Telefon', 'tel'], ['has_car', 'Autó', 'chk']].concat(Object.keys(PRC).map(k => ['pr:' + k, PRC[k], 'pr']), [['extra', 'Megjegyzés', 'text']]);
	let COLS = colsFor(S.kind);
	async function load() {
		try { const r = await post('cp_kz_list', { reveal: S.reveal ? 1 : '' }); S.rows = r.rows; S.el = r.el; S.err = ''; } catch (e) { S.err = e.message; }
		draw();
	}
	function cell(x, c) {
		const [k, , type, sens] = c;
		if (type === 'sk' || type === 'pr') { const key = k.slice(3), on = (type === 'sk' ? x.skills : x.proc).includes(key); return '<td class="kz-c"><input type="checkbox" data-' + type + '="' + key + '"' + (on ? ' checked' : '') + ' aria-label="' + esc(c[1]) + '"></td>'; }
		if (type === 'chk') return '<td class="kz-c"><input type="checkbox" data-f="' + k + '"' + (x[k] ? ' checked' : '') + ' aria-label="' + esc(c[1]) + '"></td>';
		if (type === 'car') return '<td><select data-f="car" class="kz-car ' + esc(x.car) + '" aria-label="Autó típusa"><option value="">–</option><option value="szemely"' + (x.car === 'szemely' ? ' selected' : '') + '>személy</option><option value="teher"' + (x.car === 'teher' ? ' selected' : '') + '>teher</option></select></td>';
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
		COLS = colsFor(S.kind);
		const cnt = k => S.rows.filter(x => x.kind === k && (S.inact || x.active)).length;
		main.querySelector('.kz-seg').innerHTML = Object.keys(KINDS).map(k => '<button type="button" data-kind="' + k + '" class="' + (S.kind === k ? 'on' : '') + '">' + esc(KINDS[k]) + ' <small>' + cnt(k) + '</small></button>').join('');
		main.querySelectorAll('[data-kind]').forEach(b => b.addEventListener('click', () => { S.kind = b.dataset.kind; S.open = 0; try { localStorage.setItem('cpx_kz_kind', S.kind); } catch (e) { /* privát mód */ } draw(); }));
		if (!S.edit) return drawRead(main, q);
		const L = S.rows.filter(x => x.kind === S.kind && (S.inact || x.active) && (!q || [x.name, x.email, x.phone, x.plate, x.note, x.extra].join(' ').toLowerCase().includes(q)));
		const miss = S.kind === 'jelentkezo' ? 0 : L.filter(x => x.active && x.miss.length).length;
		const J = S.kind === 'jelentkezo';
		main.querySelector('.kz-body').innerHTML = S.err ? '<div class="cpx-empty err">' + esc(S.err) + '</div>' :
			'<div class="kz-n">' + L.length + ' kezelő' + (miss ? ' · <b class="kz-warn">' + miss + ' kezelőnél hiányzik személyes adat</b>' : '') + (S.reveal ? ' · <span class="kz-open">🔓 a személyes adatok láthatók – a megtekintés naplózva</span>' : '') + '</div>' +
			'<div class="kz-tw"><table class="kz-t"><thead><tr><th class="kz-fl"></th>' + COLS.map(c => '<th' + (c[3] ? ' class="kz-sh"' : (c[2] === 'sk' || c[2] === 'pr' || c[2] === 'chk') ? ' class="kz-ch"' : '') + '>' + esc(c[1]) + '</th>').join('') + (J ? '<th></th>' : '<th>Elszámolás</th><th>Közelgő</th>') + '</tr></thead><tbody>' +
			L.map(x => '<tr data-id="' + x.id + '" class="' + (x.flag ? 'fl-' + x.flag : '') + (x.active ? '' : ' off') + '"><td class="kz-fl kz-flw"><span class="kz-drag" title="Húzd a sorrend módosításához" aria-label="Áthelyezés">⠿</span><button type="button" class="kz-tog" data-more aria-expanded="' + (S.open === x.id) + '" title="Részletek">' + (S.open === x.id ? '▾' : '▸') + '</button></td>' + COLS.map(c => cell(x, c)).join('') +
				(J ? '<td><button type="button" class="cpx-btn sm" data-hire title="Áthelyezés a Kezelők közé">→ Kezelő lett</button></td>' :
				'<td><select data-f="el_id" aria-label="Kezelői elszámolás"><option value="">–</option>' + S.el.map(e => '<option value="' + esc(e.id) + '"' + (e.id === x.el_id ? ' selected' : '') + '>' + esc(e.name) + '</option>').join('') + '</select></td>' +
				'<td class="kz-up">' + (x.up.length ? '<button type="button" class="kz-link" data-up>' + x.up.length + ' rendezvény<small>' + esc(huD(x.up[0].event_d)) + '</small></button>' : '<span class="cpx-muted">–</span>') + '</td>') +
				'</tr>' +
				(S.open === x.id ? '<tr class="kz-det"><td colspan="' + (COLS.length + 3) + '">' + detail(x) + '</td></tr>' : '')).join('') +
			'</tbody></table></div>';
		bind();
		dnd(main);
		if (window.CPXStatus) window.CPXStatus();
	}
	/* húzás: csak szerkesztés módban, a ⠿ fogantyúnál fogva; elengedéskor az aktuális csoport új sorrendje mentődik */
	function dnd(main) {
		const tb = main.querySelector('.kz-t tbody'); if (!tb) return;
		let drag = null;
		tb.querySelectorAll('tr[data-id]').forEach(tr => {
			const h = tr.querySelector('.kz-drag');
			h.addEventListener('mousedown', () => { tr.draggable = true; });
			h.addEventListener('touchstart', () => { tr.draggable = true; }, { passive: true });
			tr.addEventListener('dragstart', e => { if (!tr.draggable) { e.preventDefault(); return; } drag = tr; S.open = 0; const d = tr.nextElementSibling; if (d && d.classList.contains('kz-det')) d.remove(); tr.classList.add('kz-dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', tr.dataset.id); } catch (er) { /* régi böngésző */ } });
			tr.addEventListener('dragend', async () => {
				tr.draggable = false; tr.classList.remove('kz-dragging'); tb.querySelectorAll('.kz-over').forEach(z => z.classList.remove('kz-over'));
				if (!drag) return; drag = null;
				const vis = Array.from(tb.querySelectorAll('tr[data-id]')).map(z => Number(z.dataset.id));
				/* a többi (más csoport, rejtett inaktív, szűrésből kimaradt) a helyén marad: a látható sorok sorrendje a saját helyeikre kerül */
				const slots = S.rows.map((x, i) => vis.includes(x.id) ? i : -1).filter(i => i >= 0);
				const byId = new Map(S.rows.map(x => [x.id, x]));
				const nr = S.rows.slice(); slots.forEach((si, k) => { nr[si] = byId.get(vis[k]); });
				const same = nr.every((x, i) => x === S.rows[i]); if (same) return;
				S.rows = nr;
				try { await post('cp_kz_order', { ids: S.rows.map(x => x.id).join(',') }); toast('Sorrend mentve.'); } catch (e) { toast(e.message, true); load(); }
			});
			tr.addEventListener('dragover', e => {
				if (!drag || drag === tr) return; e.preventDefault(); e.dataTransfer.dropEffect = 'move';
				const r = tr.getBoundingClientRect(), after = e.clientY > r.top + r.height / 2;
				tb.insertBefore(drag, after ? tr.nextSibling : tr);
			});
		});
	}

	/* ------------------------------------------------------------ v2.6.2: olvasó nézet – tömör, semmi nem kattintható át véletlenül */
	const chips = (keys, lab, all) => keys.length ? keys.map(k => '<span class="kz-chip">' + esc(lab[k] || k) + '</span>').join('') : '<span class="cpx-muted">–</span>';
	const tel = p => p ? '<a href="tel:' + esc(String(p).replace(/[^\d+]/g, '')) + '">' + esc(p) + '</a>' : '<span class="cpx-muted">–</span>';
	const mail = e => e ? '<a href="mailto:' + esc(e) + '">' + esc(e) + '</a>' : '<span class="cpx-muted">–</span>';
	function sensCell(x) {
		if (S.reveal) return '<td class="kz-sr">' + SK.map(k => (x.sens || {})[k] ? '<div><small>' + esc(SENS[k]) + ':</small> ' + esc(x.sens[k]) + '</div>' : '').join('') + (x.miss.length ? '<div class="kz-warn"><small>hiányzik: ' + esc(x.miss.map(k => SENS[k]).join(', ')) + '</small></div>' : '') + '</td>';
		const n = SK.length - x.miss.length;
		return '<td class="kz-sc ' + (x.miss.length ? (n ? 'mid' : 'no') : 'ok') + '" title="' + esc(x.miss.length ? 'Hiányzik: ' + x.miss.map(k => SENS[k]).join(', ') : 'Minden személyes adat megvan') + '">' + n + '/' + SK.length + '</td>';
	}
	function drawRead(main, q) {
		const k = S.kind, J = k === 'jelentkezo';
		const L = S.rows.filter(x => x.kind === k && (S.inact || x.active) && (!q || [x.name, x.email, x.phone, x.plate, x.note, x.extra].join(' ').toLowerCase().includes(q)));
		const miss = J ? 0 : L.filter(x => x.active && x.miss.length).length;
		const head = ['Név', 'Telefon', 'E-mail'].concat(k === 'kezelo' ? ['Autó', 'Gépek'] : ['Autó'], J ? ['Felvétel'] : [], [J ? 'Megjegyzés' : 'Egyéb'], J ? [] : ['Adatok', 'Közelgő']);
		main.querySelector('.kz-body').innerHTML = S.err ? '<div class="cpx-empty err">' + esc(S.err) + '</div>' :
			'<div class="kz-n">' + L.length + ' fő' + (miss ? ' · <b class="kz-warn">' + miss + ' főnél hiányzik személyes adat</b>' : '') + (S.reveal ? ' · <span class="kz-open">🔓 személyes adatok láthatók (naplózva)</span>' : '') + '</div>' +
			'<div class="kz-tw"><table class="kz-t kz-r"><thead><tr><th class="kz-fl"></th>' + head.map(h => '<th>' + esc(h) + '</th>').join('') + '</tr></thead><tbody>' +
			L.map(x => '<tr data-id="' + x.id + '" class="' + (x.flag ? 'fl-' + x.flag : '') + (x.active ? '' : ' off') + '"><td class="kz-fl"><button type="button" class="kz-tog" data-more aria-expanded="' + (S.open === x.id) + '" title="Részletek">' + (S.open === x.id ? '▾' : '▸') + '</button></td>' +
				'<td class="kz-nm"><b>' + esc(x.name) + '</b>' + (x.el ? '<small>' + esc(x.el.name) + ' · ' + esc(x.el.role) + '</small>' : '') + '</td><td class="kz-nw">' + tel(x.phone) + '</td><td>' + mail(x.email) + '</td>' +
				(k === 'kezelo' ? '<td class="kz-nw">' + (x.plate ? esc(x.plate) : '') + (x.car ? ' <span class="kz-car ' + esc(x.car) + '">' + (x.car === 'teher' ? 'teher' : 'személy') + '</span>' : (x.plate ? '' : '<span class="cpx-muted">–</span>')) + '</td><td class="kz-chips">' + chips(x.skills, SKL) + '</td>'
					: '<td class="kz-nw">' + (x.has_car ? '✓ van' : '<span class="cpx-muted">–</span>') + '</td>') +
				(J ? '<td class="kz-chips">' + Object.keys(PRC).map(p => '<span class="kz-chip ' + (x.proc.includes(p) ? 'ok' : 'no') + '">' + (x.proc.includes(p) ? '✓ ' : '') + esc(PRC[p].replace('Telefonos egyeztetés', 'Telefon').replace('Személyes interjú', 'Interjú')) + '</span>').join('') + '</td>' : '') +
				'<td class="kz-ex">' + esc(x.extra || '') + '</td>' +
				(J ? '' : sensCell(x) + '<td class="kz-nw">' + (x.up.length ? '<button type="button" class="kz-link" data-more>' + x.up.length + ' · ' + esc(huD(x.up[0].event_d)) + '</button>' : '<span class="cpx-muted">–</span>') + '</td>') +
				'</tr>' +
				(S.open === x.id ? '<tr class="kz-det"><td colspan="' + (head.length + 1) + '">' + detailRead(x) + '</td></tr>' : '')).join('') +
			'</tbody></table></div>';
		main.querySelectorAll('tr[data-id] [data-more]').forEach(b => b.addEventListener('click', () => { const id = Number(b.closest('tr').dataset.id); S.open = S.open === id ? 0 : id; draw(); }));
		main.querySelectorAll('[data-deal]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); if (window.CPCrm) window.CPCrm.open(Number(a.dataset.deal)); }));
		if (window.CPXStatus) window.CPXStatus();
	}
	function detailRead(x) {
		return '<div class="kz-d"><div>' + (x.note ? '<p class="kz-note">' + esc(x.note) + '</p>' : '<p class="cpx-muted">Nincs megjegyzés.</p>') +
			'<p class="cpx-muted">' + (x.active ? 'Aktív' : 'Inaktív') + ' · ' + esc(KINDS[x.kind] || '') + (x.el ? ' · elszámolás: ' + esc(x.el.name) + ' (' + x.el.rate + ' Ft/óra)' : '') + (x.updated ? ' · módosítva: ' + esc(x.updated) + (x.by ? ' (' + esc(x.by) + ')' : '') : '') + '</p></div>' +
			'<div><b>Közelgő rendezvényei</b>' + (x.up.length ? '<ul>' + x.up.map(u => '<li><a href="#deals:' + u.id + '" data-deal="' + u.id + '">' + esc(huD(u.event_d)) + ' – ' + esc(u.title) + '</a></li>').join('') + '</ul>' : '<p class="cpx-muted">Nincs.</p>') + '</div></div>';
	}
	function detail(x) {
		return '<div class="kz-d"><div><label>Megjegyzés<textarea data-f="note" rows="2">' + esc(x.note) + '</textarea></label>' +
			'<label class="kz-chk"><input type="checkbox" data-f="active"' + (x.active ? ' checked' : '') + '> Aktív</label>' +
			'<label class="kz-chk">Jelölés: <select data-f="flag"><option value="">nincs</option><option value="red"' + (x.flag === 'red' ? ' selected' : '') + '>piros</option><option value="yellow"' + (x.flag === 'yellow' ? ' selected' : '') + '>sárga</option><option value="green"' + (x.flag === 'green' ? ' selected' : '') + '>zöld</option></select></label>' +
			'<label class="kz-chk">Csoport: <select data-f="kind">' + Object.keys(KINDS).map(k => '<option value="' + k + '"' + (x.kind === k ? ' selected' : '') + '>' + esc(KINDS[k]) + '</option>').join('') + '</select></label>' +
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
					if (r && (k === 'el_id' || k === 'active' || k === 'name' || k === 'kind' || k === 'flag')) draw();
					if (r && k === 'car') inp.className = 'kz-car ' + r.car;
				});
				if (inp.tagName === 'INPUT') inp.addEventListener('keydown', e => { if (e.key === 'Enter') inp.blur(); });
			});
			/* gépenkénti tudás / felvételi lépések: a sor összes pipája együtt mentődik */
			['sk', 'pr'].forEach(t => tr.querySelectorAll('[data-' + t + ']').forEach(cb => cb.addEventListener('change', async () => {
				const v = Array.from(tr.querySelectorAll('[data-' + t + ']:checked')).map(z => z.dataset[t]).join(',');
				await save(id, { [t === 'sk' ? 'skills' : 'proc']: v }, cb.closest('td'));
			})));
			const hire = tr.querySelector('[data-hire]');
			if (hire) hire.addEventListener('click', async () => { if (!window.confirm(x.name + ' átkerül a Kezelők közé. Mehet?')) return; if (await save(id, { kind: 'kezelo' })) { toast(x.name + ' mostantól a Kezelők között van.'); draw(); } });
			const more = tr.querySelector('[data-more]'), up = tr.querySelector('[data-up]');
			[more, up].forEach(b => b && b.addEventListener('click', () => { S.open = S.open === id ? 0 : id; draw(); }));
			const del = tr.querySelector('[data-del]');
			if (del) del.addEventListener('click', async () => { if (!window.confirm('Törlöd ' + x.name + ' adatait? Ez nem vonható vissza.')) return; try { await post('cp_kz_delete', { id }); S.rows = S.rows.filter(z => z.id !== id); toast('Törölve.'); draw(); } catch (e) { toast(e.message, true); } });
			tr.querySelectorAll('[data-deal]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); if (window.CPCrm) window.CPCrm.open(Number(a.dataset.deal)); }));
		});
	}
	function view(main) {
		S.main = main;
		main.innerHTML = '<div class="kz"><div class="kz-h"><div class="crm-seg kz-seg"></div><input type="search" data-q placeholder="Keresés: név, e-mail, telefon, rendszám" value="' + esc(S.q) + '" aria-label="Keresés">' +
			'<label class="crm-chk"><input type="checkbox" data-inact' + (S.inact ? ' checked' : '') + '> Inaktívak is</label>' +
			(K.admin ? '<button type="button" class="cpx-btn ghost" data-rev>' + (S.reveal ? '🔒 Személyes adatok elrejtése' : '🔓 Személyes adatok megjelenítése') + '</button>' + (K.csv ? '<a class="cpx-btn ghost" href="' + esc(K.csv) + '" title="Minden adat letöltése (CSV) – naplózva">⬇ CSV</a>' : '') : '') +
			(S.edit ? '<button type="button" class="cpx-btn ghost" data-new>+ Új</button>' : '') + '<button type="button" class="cpx-btn' + (S.edit ? ' kz-on' : '') + '" data-edit>' + (S.edit ? '✓ Kész' : '✎ Szerkesztés') + '</button></div>' +
			'<p class="kz-help">' + (S.edit ? '<b>Szerkesztés:</b> kattints a mezőbe, írd át – Enterre vagy máshova kattintva mentődik. A pipák is itt állíthatók. Ha végeztél: <b>✓ Kész</b>.' : 'A lista csak olvasható. Módosításhoz: <b>✎ Szerkesztés</b>.') + '</p><div class="kz-body"><div class="cpx-empty">Betöltés…</div></div></div>';
		let t = null;
		main.querySelector('[data-q]').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => { S.q = e.target.value.trim(); draw(); }, 200); });
		main.querySelector('[data-inact]').addEventListener('change', e => { S.inact = e.target.checked; draw(); });
		main.querySelector('[data-edit]').addEventListener('click', () => { S.edit = !S.edit; S.open = 0; view(main); });
		const rv = main.querySelector('[data-rev]');
		if (rv) rv.addEventListener('click', () => { S.reveal = !S.reveal; view(main); });
		const nb = main.querySelector('[data-new]');
		if (nb) nb.addEventListener('click', async () => {
			const n = (window.prompt('Az új kezelő neve:', '') || '').trim(); if (!n) return;
			const r = await save(0, { name: n, kind: S.kind }); if (r) { S.open = r.id; draw(); toast('Felvéve – töltsd ki a többi adatát.'); }
		});
		load();
	}
	function status(s) { s.textContent = S.err || ''; s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	window.CPKezelok = { view, status };

	const css = document.createElement('style');
	css.id = 'kz-css';
	css.textContent = '.kz-r td{padding:5px 8px;font-size:12.5px;line-height:1.3}.kz-r th{padding:6px 8px}.kz-r a{color:#2F5BD8;text-decoration:none}.kz-nm b{display:block;font-size:13px}.kz-nm small{display:block;color:#8A8E96;font-size:11px}.kz-nw{white-space:nowrap}.kz-ex{color:#6B6F78;max-width:220px}.kz-chips{min-width:120px}.kz-chip{display:inline-block;font-size:10.5px;font-weight:600;background:#EEF3FD;color:#2F4FA8;border-radius:5px;padding:1px 6px;margin:1px 3px 1px 0;white-space:nowrap}.kz-chip.ok{background:#DDF3E6;color:#17663A}.kz-chip.no{background:#F3F4F6;color:#8A8E96}.kz-car{font-size:10.5px;font-weight:600;border-radius:999px;padding:1px 7px}span.kz-car.szemely{background:#DDF3E6;color:#17663A}span.kz-car.teher{background:#3B2F2A;color:#fff}.kz-sc{text-align:center;font-weight:600;font-size:12px}.kz-sc.ok{color:#17663A}.kz-sc.mid{color:#9A5B00;background:#FFF8E1}.kz-sc.no{color:#B4400E;background:#FDF1EC}.kz-sr{font-size:11.5px;min-width:220px}.kz-sr small{color:#8A8E96}.kz-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#E2DED8}tr.fl-red .kz-dot{background:#D84315}tr.fl-yellow .kz-dot{background:#F9A825}tr.fl-green .kz-dot{background:#2E7D32}.kz-note{white-space:pre-wrap;margin:0 0 6px}.cpx-btn.kz-on{background:#17663A;border-color:#17663A}' +
		'.kz-c{text-align:center}.kz-c input{width:17px!important;min-width:0!important;height:17px;accent-color:#2E7D32;cursor:pointer}.kz-t th.kz-ch{font-size:10.5px;text-align:center;white-space:normal;min-width:56px;max-width:86px}.kz-car{min-width:100px!important;border-radius:999px!important;text-align:center}.kz-car.szemely{background:#DDF3E6!important;color:#17663A}.kz-car.teher{background:#3B2F2A!important;color:#fff}.kz-c.saving{background:#FFF8E1}.kz-c.saved{background:#E8F6EE}' +
		'.kz{max-width:100%}.kz-h{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px}.kz-h input[type=search]{flex:1;min-width:150px;border:1px solid #E6E3DE;border-radius:8px;padding:7px 9px;font:13px Inter,sans-serif}.kz-help{font-size:12.5px;color:#6B6F78;margin:0 0 8px}' +
		'.kz-n{font-size:12.5px;color:#6B6F78;margin-bottom:6px}.kz-warn{color:#B4400E}.kz-open{color:#17663A}.kz-tw{background:#fff;border:1px solid #E6E3DE;border-radius:12px;overflow:auto}' +
		'.kz-t{border-collapse:collapse;font-size:13px;min-width:100%}.kz-t th{background:#E9F3E2;color:#2D4A1E;font-size:11.5px;text-align:left;padding:7px 6px;border-bottom:1px solid #D4E6C6;white-space:nowrap;position:sticky;top:0}.kz-t th.kz-sh{background:#F1F5EC}' +
		'.kz-t td{border-bottom:1px solid #F0ECE6;padding:2px 3px;vertical-align:middle}.kz-t input,.kz-t select{width:100%;min-width:110px;border:1px solid transparent;border-radius:6px;padding:6px 6px;font:13px Inter,sans-serif;background:transparent;color:#141C33}' +
		'.kz-t input:hover,.kz-t select:hover{border-color:#E6E3DE}.kz-t input:focus,.kz-t select:focus{border-color:#2F5BD8;background:#fff;outline:none}.kz-t input[data-f=name]{font-weight:600;min-width:150px}.kz-t input[data-f=email]{min-width:190px}.kz-t td.kz-sv input{min-width:120px}.kz-t input[data-f=address]{min-width:230px}' +
		'.kz-t input.saving{background:#FFF8E1}.kz-t input.saved,.kz-t select.saved{background:#E8F6EE}.kz-s{font-size:12px;text-align:center;white-space:nowrap}.kz-s.ok{color:#17663A}.kz-s.no{color:#B4400E;background:#FDF1EC}' +
		'.kz-fl{width:28px;text-align:center}.kz-flw{width:46px;white-space:nowrap}.kz-drag{cursor:grab;color:#B5B0A8;font-size:14px;padding:0 3px;user-select:none}.kz-drag:hover{color:#141C33}tr.kz-dragging td{opacity:.45;background:#EEF3FD!important}.kz-tog{border:0;background:none;cursor:pointer;color:#6B6F78;font-size:13px;width:24px;height:24px;border-radius:6px}.kz-tog:hover{background:#F0ECE6;color:#141C33}.kz-fl button{border:0;background:none;cursor:pointer;font-size:15px;color:#D0CCC5}tr.fl-red td{background:#FBE9E7}tr.fl-red .kz-fl button{color:#D84315}tr.fl-yellow td{background:#FFF8E1}tr.fl-yellow .kz-fl button{color:#F9A825}tr.fl-green td{background:#EAF6EC}tr.fl-green .kz-fl button{color:#2E7D32}tr.off td{opacity:.55}' +
		'.kz-link{border:0;background:none;color:#2F5BD8;cursor:pointer;font:600 12.5px Inter,sans-serif;text-align:left;white-space:nowrap}.kz-link small{display:block;color:#8A8E96;font-weight:500}.kz-act{width:30px}' +
		'.kz-det td{background:#FAF8F5!important}.kz-d{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:8px 6px}.kz-d label{display:block;font-size:12px;color:#6B6F78}.kz-d textarea{width:100%;border:1px solid #E6E3DE;border-radius:8px;padding:6px;font:13px Inter,sans-serif}.kz-d ul{margin:6px 0;padding-left:18px}.kz-d li{margin:3px 0}.kz-chk{display:flex!important;gap:6px;align-items:center;margin:6px 0;color:#141C33!important;font-size:13px!important}.kz-d input[type=checkbox]{width:auto;min-width:0;accent-color:#2F5BD8}.kz-d select{width:auto!important;min-width:150px!important;border:1px solid #E6E3DE!important;background:#fff!important}' +
		'@media (max-width:760px){.kz-d{grid-template-columns:1fr}}';
	document.head.appendChild(css);
})();
