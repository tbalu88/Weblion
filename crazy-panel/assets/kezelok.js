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
	const S = { rows: [], el: [], reveal: false, q: '', inact: false, main: null, err: '', open: 0, kind: 'kezelo', edit: false, loaded: false, sub: 'list', force: null }; /* v2.9.85: sub = lista / interjú nézet */
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
		try { const r = await post('cp_kz_list', { reveal: S.reveal ? 1 : '' }); S.rows = r.rows; S.el = r.el; S.err = ''; S.loaded = true; } catch (e) { S.err = e.message; }
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
		bindIvGo(main);
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

	/* v2.9.85: az adatlap „Jegyzet a foglalásnál” linkje az Interjú-időpontok oldalra visz, a jegyzet nyitva */
	const bindIvGo = main => main.querySelectorAll('[data-ivgo]').forEach(a => a.addEventListener('click', e => { if (e.metaKey || e.ctrlKey || e.shiftKey) return; e.preventDefault(); nav('iv', Number(a.dataset.ivgo)); }));
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
		const head = ['Név', 'Telefon', 'E-mail'].concat(k === 'kezelo' ? ['Autó', 'Gépek'] : ['Autó'], J ? ['Felvétel'] : [], [J ? 'Megjegyzés' : 'Egyéb'], J ? [] : ['Közelgő']);
		main.querySelector('.kz-body').innerHTML = S.err ? '<div class="cpx-empty err">' + esc(S.err) + '</div>' :
			'<div class="kz-n">' + L.length + ' fő' + (miss ? ' · <b class="kz-warn">' + miss + ' főnél hiányzik személyes adat</b>' : '') + (S.reveal ? ' · <span class="kz-open">🔓 személyes adatok láthatók (naplózva)</span>' : '') + '</div>' +
			'<div class="kz-tw"><table class="kz-t kz-r"><thead><tr><th class="kz-fl"></th>' + head.map(h => '<th>' + esc(h) + '</th>').join('') + '</tr></thead><tbody>' +
			L.map(x => '<tr data-id="' + x.id + '" class="' + (x.flag ? 'fl-' + x.flag : '') + (x.active ? '' : ' off') + '"><td class="kz-fl"><button type="button" class="kz-tog" data-more aria-expanded="' + (S.open === x.id) + '" title="Részletek">' + (S.open === x.id ? '▾' : '▸') + '</button></td>' +
				'<td class="kz-nm"><b>' + esc(x.name) + '</b>' + (x.el ? '<small>' + esc(x.el.name) + ' · ' + esc(x.el.role) + '</small>' : '') + '</td><td class="kz-nw">' + tel(x.phone) + '</td><td>' + mail(x.email) + '</td>' +
				(k === 'kezelo' ? '<td class="kz-nw">' + (x.plate ? esc(x.plate) : '') + (x.car ? ' <span class="kz-car ' + esc(x.car) + '">' + (x.car === 'teher' ? 'teher' : 'személy') + '</span>' : (x.plate ? '' : '<span class="cpx-muted">–</span>')) + '</td><td class="kz-chips">' + chips(x.skills, SKL) + '</td>'
					: '<td class="kz-nw">' + (x.has_car ? '✓ van' : '<span class="cpx-muted">–</span>') + '</td>') +
				(J ? '<td class="kz-chips">' + Object.keys(PRC).map(p => '<span class="kz-chip ' + (x.proc.includes(p) ? 'ok' : 'no') + '">' + (x.proc.includes(p) ? '✓ ' : '') + esc(PRC[p].replace('Telefonos egyeztetés', 'Telefon').replace('Személyes interjú', 'Interjú')) + '</span>').join('') + '</td>' : '') +
				'<td class="kz-ex">' + esc(x.extra || '') + '</td>' +
				(J ? '' : '<td class="kz-nw">' + (x.up.length ? '<button type="button" class="kz-link" data-more>' + x.up.length + ' · ' + esc(huD(x.up[0].event_d)) + '</button>' : '<span class="cpx-muted">–</span>') + '</td>') +
				'</tr>' +
				(S.open === x.id ? '<tr class="kz-det"><td colspan="' + (head.length + 1) + '">' + detailRead(x) + '</td></tr>' : '')).join('') +
			'</tbody></table></div>';
		main.querySelectorAll('tr[data-id] [data-more]').forEach(b => b.addEventListener('click', () => { const id = Number(b.closest('tr').dataset.id); S.open = S.open === id ? 0 : id; draw(); }));
		main.querySelectorAll('[data-deal]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); if (window.CPCrm) window.CPCrm.open(Number(a.dataset.deal)); }));
		bindIvGo(main);
		if (window.CPXStatus) window.CPXStatus();
	}
	function detailRead(x) {
		const n = SK.length - x.miss.length;
		const sens = x.kind === 'jelentkezo' ? '' : S.reveal ? '<div class="kz-sr">' + SK.map(k => '<div><small>' + esc(SENS[k]) + ':</small> ' + ((x.sens || {})[k] ? esc(x.sens[k]) : '<span class="kz-warn">hiányzik</span>') + '</div>').join('') + '</div>'
			: '<p class="' + (x.miss.length ? 'kz-warn' : 'kz-open') + '">Személyes adatok: ' + n + '/' + SK.length + ' megvan' + (x.miss.length ? ' – hiányzik: ' + esc(x.miss.map(k => SENS[k]).join(', ')) : '') + (K.admin ? ' <small class="cpx-muted">(megtekintés: „Személyes adatok megjelenítése”)</small>' : '') + '</p>';
		return '<div class="kz-d"><div>' + sens + (x.note ? '<p class="kz-note">' + esc(x.note) + '</p>' : '<p class="cpx-muted">Nincs megjegyzés.</p>') + (avTxt(x) ? '<p class="kz-avs"><b>Elérhetőség:</b> ' + esc(avTxt(x)) + '</p>' : '') /* v2.9.151 */ + ivRec(x) /* v2.9.85 */ + slotRec(x) +
			'<p class="cpx-muted">' + (x.active ? 'Aktív' : 'Inaktív') + ' · ' + esc(KINDS[x.kind] || '') + (x.el ? ' · elszámolás: ' + esc(x.el.name) + ' (' + x.el.rate + ' Ft/óra)' : '') + (x.updated ? ' · módosítva: ' + esc(x.updated) + (x.by ? ' (' + esc(x.by) + ')' : '') : '') + '</p></div>' +
			'<div><b>Közelgő rendezvényei</b>' + (x.up.length ? '<ul>' + x.up.map(u => '<li><a href="#deals:' + u.id + '" data-deal="' + u.id + '">' + esc(huD(u.event_d)) + (u.tm ? ' ' + esc(u.tm) : '') + ' – ' + esc(u.title) + '</a>' + (u.dof ? ' <span class="dtag">' + u.dn + '/' + u.dof + '. nap</span>' : '') /* v2.9.71 */ + '</li>').join('') + '</ul>' : '<p class="cpx-muted">Nincs.</p>') + '</div></div>';
	}
	/* ------------------------------------------------------------ v2.9.151: elérhetőség (soha nem ér rá napok, kizárt dátumok) */
	const AVD = ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'], AVDL = ['hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap'];
	const avOf = x => x.avail || { wd: [], days: [], note: '' };
	/* egymást követő dátumok összevonása: 12.24–12.26. */
	function avRanges(days) {
		const L = (days || []).slice().sort(), out = []; let a = null, b = null;
		const nx = d => { const t = new Date(d + 'T12:00:00'); t.setDate(t.getDate() + 1); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
		L.forEach(d => { if (b && nx(b) === d) b = d; else { if (a) out.push([a, b]); a = b = d; } }); if (a) out.push([a, b]);
		return out;
	}
	const avD = d => d.slice(5).replace('-', '.') + '.';
	function avTxt(x) {
		const v = avOf(x), p = [];
		const wd = v.wd || [];
		if (wd.length) p.push(wd.join() === '6,7' ? 'hétvégén nem ér rá' : wd.join() === '1,2,3,4,5' ? 'hétköznap nem ér rá' : wd.length === 7 ? 'egyik nap sem ér rá' : 'soha nem ér rá: ' + wd.map(n => AVDL[n - 1]).join(', '));
		const r = avRanges(v.days); if (r.length) p.push('kizárt napok: ' + r.slice(0, 6).map(z => z[0] === z[1] ? avD(z[0]) : avD(z[0]) + '–' + avD(z[1])).join(', ') + (r.length > 6 ? ' …' : ''));
		if (v.note) p.push('„' + v.note + '”');
		return p.join(' · ');
	}
	function avH(x) {
		const v = avOf(x), wd = v.wd || [], has = n => wd.includes(n);
		const all = (L) => L.every(n => has(n));
		return '<div class="kz-av" data-av><b>Elérhetőség</b> <small class="cpx-muted">– a beosztásnál a „nem javasolt” közé kerül, akinek aznap nem jó</small>' +
			'<div class="kz-avq"><label class="kz-chk"><input type="checkbox" data-avq="1,2,3,4,5"' + (all([1, 2, 3, 4, 5]) ? ' checked' : '') + '> Hétköznap nem ér rá</label><label class="kz-chk"><input type="checkbox" data-avq="6,7"' + (all([6, 7]) ? ' checked' : '') + '> Hétvégén nem ér rá</label></div>' +
			'<div class="kz-avw" role="group" aria-label="Soha nem ér rá ezeken a napokon"><small>Soha nem ér rá:</small>' + AVD.map((d, i) => '<button type="button" data-avw="' + (i + 1) + '" aria-pressed="' + has(i + 1) + '" title="' + AVDL[i] + 'enként soha">' + d + '</button>').join('') + '</div>' +
			'<div class="kz-avr"><small>Adott nap(ok) kizárása:</small><input type="date" data-avf aria-label="Ettől"><span>–</span><input type="date" data-avt aria-label="Eddig (nem kötelező)"><button type="button" class="cpx-btn sm" data-avadd>Kizárás</button></div>' +
			(v.days && v.days.length ? '<div class="kz-avl">' + avRanges(v.days).map(z => '<span class="kz-chip">' + (z[0] === z[1] ? avD(z[0]) : avD(z[0]) + '–' + avD(z[1])) + '<button type="button" data-avx="' + z[0] + '|' + z[1] + '" aria-label="Törlés" title="Törlés">×</button></span>').join('') + '</div>' : '') +
			'<label class="kz-avn">Megjegyzés<input type="text" data-avn maxlength="200" value="' + esc(v.note || '') + '" placeholder="pl. egyetem miatt csütörtökön csak este"></label></div>';
	}
	function bindAv(tr, id) {
		const box = tr.querySelector('[data-av]'); if (!box) return;
		const x = () => S.rows.find(z => z.id === id) || {};
		const put = async (v) => { const r = await save(id, { avail: v }, box); if (r) draw(); };
		const cur = () => { const v = avOf(x()); return { wd: (v.wd || []).slice(), days: (v.days || []).slice(), note: v.note || '' }; };
		box.querySelectorAll('[data-avq]').forEach(cb => cb.addEventListener('change', () => { const v = cur(), L = cb.dataset.avq.split(',').map(Number); v.wd = cb.checked ? Array.from(new Set(v.wd.concat(L))) : v.wd.filter(n => !L.includes(n)); put(v); }));
		box.querySelectorAll('[data-avw]').forEach(b => b.addEventListener('click', () => { const v = cur(), n = Number(b.dataset.avw); v.wd = v.wd.includes(n) ? v.wd.filter(z => z !== n) : v.wd.concat([n]); put(v); }));
		box.querySelector('[data-avadd]').addEventListener('click', () => {
			const f = box.querySelector('[data-avf]').value, t = box.querySelector('[data-avt]').value || f;
			if (!f) { toast('Válaszd ki a napot.', true); return; }
			const a = new Date((f < t ? f : t) + 'T12:00:00'), z = new Date((f < t ? t : f) + 'T12:00:00'), v = cur(); let n = 0;
			while (a <= z && n < 120) { v.days.push(a.getFullYear() + '-' + String(a.getMonth() + 1).padStart(2, '0') + '-' + String(a.getDate()).padStart(2, '0')); a.setDate(a.getDate() + 1); n++; }
			put(v);
		});
		box.querySelectorAll('[data-avx]').forEach(b => b.addEventListener('click', () => { const [f, t] = b.dataset.avx.split('|'), v = cur(); v.days = v.days.filter(d => d < f || d > t); put(v); }));
		const nt = box.querySelector('[data-avn]'); nt.addEventListener('change', () => { const v = cur(); v.note = nt.value.trim(); put(v); }); nt.addEventListener('keydown', e => { if (e.key === 'Enter') nt.blur(); });
	}
	function detail(x) {
		return '<div class="kz-d"><div><label>Megjegyzés<textarea data-f="note" rows="2">' + esc(x.note) + '</textarea></label>' +
			'<label class="kz-chk"><input type="checkbox" data-f="active"' + (x.active ? ' checked' : '') + '> Aktív</label>' +
			'<label class="kz-chk">Jelölés: <select data-f="flag"><option value="">nincs</option><option value="red"' + (x.flag === 'red' ? ' selected' : '') + '>piros</option><option value="yellow"' + (x.flag === 'yellow' ? ' selected' : '') + '>sárga</option><option value="green"' + (x.flag === 'green' ? ' selected' : '') + '>zöld</option></select></label>' +
			'<label class="kz-chk">Csoport: <select data-f="kind">' + Object.keys(KINDS).map(k => '<option value="' + k + '"' + (x.kind === k ? ' selected' : '') + '>' + esc(KINDS[k]) + '</option>').join('') + '</select></label>' +
			(x.miss.length ? '<p class="kz-warn">Hiányzik: ' + esc(x.miss.map(k => SENS[k]).join(', ')) + '</p>' : '') +
			'<p class="cpx-muted">' + (x.phone ? '<a href="tel:' + esc(x.phone.replace(/[^\d+]/g, '')) + '">📞 Hívás</a> · ' : '') + (x.email ? '<a href="mailto:' + esc(x.email) + '">✉ E-mail</a> · ' : '') + (x.el ? 'Elszámolás: ' + esc(x.el.name) + ' (' + esc(x.el.role) + ', ' + x.el.rate + ' Ft/óra) · ' : '') + 'Módosítva: ' + esc(x.updated || '') + (x.by ? ' (' + esc(x.by) + ')' : '') + '</p>' +
			(x.kind !== 'jelentkezo' ? avH(x) : '') /* v2.9.151 */ + ivRec(x) /* v2.9.85 */ + slotRec(x) + (K.admin ? '<button type="button" class="cpx-btn ghost danger sm" data-del>Kezelő törlése</button>' : '') + '</div>' +
			'<div><b>Közelgő rendezvényei</b> <small class="cpx-muted">(a deal „Gépkezelő” mezője alapján)</small>' + (x.up.length ? '<ul>' + x.up.map(u => '<li><a href="#deals:' + u.id + '" data-deal="' + u.id + '">' + esc(huD(u.event_d)) + (u.tm ? ' ' + esc(u.tm) : '') + ' – ' + esc(u.title) + '</a>' + (u.dof ? ' <span class="dtag">' + u.dn + '/' + u.dof + '. nap</span>' : '') /* v2.9.71 */ + '' + (u.venue ? ' <small>' + esc(u.venue) + '</small>' : '') + '</li>').join('') + '</ul>' : '<p class="cpx-muted">Nincs.</p>') + '</div></div>';
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
					/* v2.9.87: a megjegyzésnél a kiinduló szöveg is megy – ha közben más is módosította, a szerver összefésüli */
					const r = await save(id, k === 'note' ? { note: v, note_base: x.note || '' } : { [k]: v }, inp);
					if (r && k === 'note') { const nx = S.rows.find(z => z.id === id); if (nx && nx.note !== v) { inp.value = nx.note; toast('Közben más is írt a megjegyzésbe – a kettőt összefésültem.'); } x.note = nx ? nx.note : v; }
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
			bindAv(tr, id); /* v2.9.151 */
			const hire = tr.querySelector('[data-hire]');
			if (hire) hire.addEventListener('click', async () => { if (!window.confirm(x.name + ' átkerül a Kezelők közé. Mehet?')) return; if (await save(id, { kind: 'kezelo' })) { toast(x.name + ' mostantól a Kezelők között van.'); draw(); } });
			const more = tr.querySelector('[data-more]'), up = tr.querySelector('[data-up]');
			[more, up].forEach(b => b && b.addEventListener('click', () => { S.open = S.open === id ? 0 : id; draw(); }));
			const del = tr.querySelector('[data-del]');
			if (del) del.addEventListener('click', async () => { if (!window.confirm('Törlöd ' + x.name + ' adatait? Ez nem vonható vissza.')) return; try { await post('cp_kz_delete', { id }); S.rows = S.rows.filter(z => z.id !== id); toast('Törölve.'); draw(); } catch (e) { toast(e.message, true); } });
			tr.querySelectorAll('[data-deal]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); if (window.CPCrm) window.CPCrm.open(Number(a.dataset.deal)); }));
		});
	}
	/* ------------------------------------------------------------ v2.9.57: Interjú-időpontok (kezelő-jelentkezők foglalója)
	   v2.9.85: nem felugró ablak, hanem a Kezelők fül saját oldala – saját címe: #kezelok:interju (#kezelok:interju:<foglalás> = a jegyzete nyitva);
	   a fejléc kapcsolója („Kezelők” | „Interjú-időpontok”) előzménylépést ad, így a böngésző Vissza / Előre gombja a két nézet között lép.
	   Foglalásonként jegyzet (automatikus mentés gépelés közben) és gyors értékelés (Alkalmas / Talán / Nem). */
	const IV = { d: null, busy: false, err: '', open: new Set(), ed: {}, focus: 0, setOpen: false, changed: false };
	const RATES = [[3, 'Alkalmas', 'ok'], [2, 'Talán', 'mid'], [1, 'Nem', 'no']];
	const rateOf = n => RATES.find(r => r[0] === Number(n));
	const rateChip = n => { const r = rateOf(n); return r ? '<span class="iv-rt ' + r[2] + '">' + r[1] + '</span>' : ''; };
	/* v2.9.143: személyenkénti bejegyzések – a saját (szerkeszthető), a többieké (csak olvasható), összesítés értékelésenként */
	const CM = () => !!(IV.d && IV.d.cmt);
	const myC = b => (b.cm || []).find(c => c.mine);
	const rateSum = L => { const n = {}; (L || []).forEach(c => { if (c.rate) n[c.rate] = (n[c.rate] || 0) + 1; }); return RATES.filter(r => n[r[0]]).map(r => '<span class="iv-rt ' + r[2] + '">' + r[1] + (n[r[0]] > 1 ? ' ×' + n[r[0]] : '') + '</span>').join(''); };
	const cmCard = c => '<div class="iv-cm' + (c.mine ? ' me' : '') + '"><div class="iv-cmh"><b>' + esc(c.who || '–') + (c.mine ? ' (te)' : '') + '</b>' + rateChip(c.rate) + '<small>' + esc(c.at || '') + '</small></div>' + (c.body ? '<p>' + esc(c.body) + '</p>' : '') + '</div>';
	const oldCard = b => b.note ? '<div class="iv-cm old"><div class="iv-cmh"><b>Korábbi közös jegyzet</b>' + rateChip(b.rate) + '<small>' + esc((b.nat || '') + (b.nby ? ' · ' + b.nby : '')) + '</small></div><p>' + esc(b.note) + '</p></div>' : '';
	const cmText = b => [b.note ? 'Közös jegyzet' + (rateOf(b.rate) ? ' (' + rateOf(b.rate)[1] + ')' : '') + ': ' + b.note : ''].concat((b.cm || []).map(c => (c.who || '–') + (rateOf(c.rate) ? ' (' + rateOf(c.rate)[1] + ')' : '') + (c.body ? ': ' + c.body : ''))).filter(Boolean).join('\n');
	const hhmm = () => { const d = new Date(); return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
	/* a cím → nézet: #kezelok = lista, #kezelok:interju (vagy a régi értesítésekből #kezelok:iv), opcionálisan :<foglalás> */
	function ivHash() { const m = /^#kezelok(?::(interju|iv)(?::(\d+))?)?$/.exec(location.hash); return m ? { v: m[1] ? 'iv' : 'list', b: Number(m[2]) || 0 } : null; }
	function nav(v, bid) {
		ivFlushAll();
		const url = v === 'iv' ? '#kezelok:interju' : '#kezelok';
		if (location.hash !== url) history.pushState({ cpKz: v }, '', url);
		S.sub = v; if (bid) IV.focus = bid;
		if (S.main && S.main.isConnected) view(S.main, true);
	}
	window.addEventListener('popstate', () => {
		const h = ivHash(); if (!h) return;
		/* másik fülről lépett vissza egy Kezelők-címre: vissza a fülre, a megfelelő nézettel */
		if (!(S.main && S.main.isConnected && S.main.querySelector('.kz'))) { if (window.CPXGo) { S.force = h; window.CPXGo('kezelok'); } return; }
		if (h.v !== S.sub || h.b) { ivFlushAll(); S.sub = h.v; if (h.b) IV.focus = h.b; view(S.main, true); }
	});
	/* értesítésből: „kezelok:interju”, „kezelok:iv”, „kezelok:interju:12” */
	function deep(arg) { const m = /^(?:interju|iv)(?::(\d+))?$/.exec(String(arg || '')); if (m && K.iv) nav('iv', Number(m[1]) || 0); }
	const ivOpen = () => nav('iv'); /* v2.9.85: a régi felugró ablak helyett az oldalra visz */
	const vsw = () => K.iv ? '<nav class="crm-seg kz-vs" aria-label="Nézet"><a href="#kezelok" data-view="list"' + (S.sub === 'iv' ? '' : ' class="on" aria-current="page"') + '>Lista</a><a href="#kezelok:interju" data-view="iv"' + (S.sub === 'iv' ? ' class="on" aria-current="page"' : '') + '>Interjú-időpontok</a></nav>' : '';
	function bindVsw(main) {
		main.querySelectorAll('.kz-vs [data-view]').forEach(a => a.addEventListener('click', e => {
			if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return; /* új lapon / ablakban: a böngészőé */
			e.preventDefault(); if (a.dataset.view !== S.sub) nav(a.dataset.view);
		}));
	}
	function ivView(main) {
		main.innerHTML = '<div class="kz kz-ivv">' + vsw() + '<div class="iv-pg"><div class="iv-b"><div class="cpx-empty">Betöltés…</div></div></div></div>';
		bindVsw(main);
		if (IV.d) ivDraw();
		ivLoad();
		if (window.CPXStatus) window.CPXStatus();
	}
	async function ivLoad() {
		try { IV.d = await post('cp_iv_admin', {}); IV.err = ''; ivSync(); } catch (e) { IV.err = e.message; if (IV.d) toast(e.message, true); }
		ivDraw();
	}
	async function ivDo(params) {
		if (IV.busy) return; IV.busy = true;
		try { IV.d = await post('cp_iv_admin', params); ivSync(); ivDraw(); return true; } catch (e) { toast(e.message, true); return false; } finally { IV.busy = false; }
	}
	/* friss adat után a mentett (nem függő) helyi szövegek helyett a szerveré látszik – így más kolléga jegyzete is megjelenik */
	function ivSync() { Object.keys(IV.ed).forEach(k => { const e = IV.ed[k]; if (!e.dirty && !e.inflight && !e.err) { const b = ivFind(Number(k)); if (b && CM()) { const m = myC(b); if ((m ? m.body : '') !== e.text || (m ? m.rate : 0) !== e.rate || !e.saved) delete IV.ed[k]; return; } if (!b || b.note !== e.text || (b.rate || 0) !== e.rate || !e.saved) delete IV.ed[k]; } }); }
	const ivBox = () => S.main && S.main.isConnected ? S.main.querySelector('.kz-ivv .iv-b') : null;
	function ivFind(bid) { const days = (IV.d && IV.d.days) || {}; for (const k of Object.keys(days)) for (const s of days[k].slots) if (s.b && s.b.id === bid) return s.b; return null; }
	/* ---- jegyzet: helyi szerkesztési állapot foglalásonként (a szöveg újrarajzoláskor és mentési hibánál sem vész el) */
	const edOf = b => IV.ed[b.id] || (IV.ed[b.id] = CM() ? (m => ({ text: m ? m.body : '', rate: m ? m.rate : 0, base: '', brate: 0, timer: 0, inflight: false, dirty: false, err: '', saved: '', mby: '' }))(myC(b)) : { text: b.note || '', rate: b.rate || 0, base: b.note || '', brate: b.rate || 0, timer: 0, inflight: false, dirty: false, err: '', saved: '', mby: '' });
	function ivQueue(bid, now) {
		const e = IV.ed[bid]; if (!e) return;
		e.dirty = true; e.err = ''; clearTimeout(e.timer);
		e.timer = setTimeout(() => ivSave(bid), now ? 0 : 800);
		ivStat(bid);
	}
	async function ivSave(bid) {
		const e = IV.ed[bid]; if (!e) return;
		clearTimeout(e.timer); e.timer = 0;
		if (e.inflight || !e.dirty) return;
		e.dirty = false; e.inflight = true; ivStat(bid);
		try {
			/* v2.9.87: a kiinduló szerveri változat is megy – ha közben más is írt bele, a szerver összefésüli (semmi nem vész el) */
			const sent = e.text, srate = e.rate;
			if (CM()) {
				const r2 = await post('cp_iv_cmt', { id: bid, body: sent, rate: srate });
				const b2 = ivFind(bid); if (b2) { b2.cm = r2.cm; const box2 = ivBox(), ol = box2 && box2.querySelector('[data-ivoth="' + bid + '"]'); if (ol) ol.innerHTML = othH(b2); }
				e.saved = hhmm(); e.err = ''; IV.changed = true;
				throw { ok2: 1 };
			}
			const r = await post('cp_iv_note', { id: bid, note: sent, rate: srate, base: e.base, brate: e.brate });
			const b = ivFind(bid); if (b) { b.note = r.note; b.rate = r.rate; b.nby = r.nby; b.nat = r.nat; }
			e.base = r.note; e.brate = r.rate;
			if (r.merged) e.mby = r.mby || 'egy kolléga';
			/* a mentett szöveg eltér a beküldöttől (összefésült): ha közben nem gépelt tovább, a mező a közös szöveget mutatja */
			if (r.note !== sent && e.text === sent) { e.text = r.note; ivSetTa(bid, r.note); }
			if (r.rate !== srate && e.rate === srate) { e.rate = r.rate; const box = ivBox(); if (box) box.querySelectorAll('[data-ivrate="' + bid + '"]').forEach(x => x.setAttribute('aria-pressed', String(Number(x.dataset.v) === e.rate))); }
			e.saved = hhmm(); e.err = ''; IV.changed = true;
		} catch (er) { if (!(er && er.ok2)) { e.dirty = true; e.err = er.message; } }
		e.inflight = false;
		/* közben tovább gépelt: újra mentjük; hibánál 8 mp múlva újrapróbálja (a szöveg addig is megmarad) */
		if (e.dirty) e.timer = setTimeout(() => ivSave(bid), e.err ? 8000 : 0);
		ivStat(bid);
	}
	function ivFlushAll() { Object.keys(IV.ed).forEach(k => { const e = IV.ed[k]; if (e.dirty && !e.inflight) ivSave(Number(k)); }); }
	const ivPending = () => Object.keys(IV.ed).some(k => IV.ed[k].dirty || IV.ed[k].inflight);
	window.addEventListener('beforeunload', e => { if (ivPending()) { ivFlushAll(); e.preventDefault(); e.returnValue = ''; } });
	function ivStatTxt(bid) {
		const e = IV.ed[bid], b = ivFind(bid) || {};
		if (e && e.err) return ['bad', 'Nem sikerült menteni (' + e.err + ') – újrapróbálom, a szöveg megmarad.'];
		if (e && (e.dirty || e.inflight)) return ['', 'Mentés…'];
		if (e && e.saved && e.mby) return ['ok', 'Mentve ' + e.saved + ' – közben ' + e.mby + ' is írt bele, a kettőt összefésültem.'];
		if (e && e.saved) return ['ok', 'Mentve ' + e.saved];
		if (CM()) { const m = myC(b); return ['', m ? 'A bejegyzésed: ' + m.at + ' · gépelés közben magától mentődik; üresre törölve eltűnik.' : 'Gépelés közben magától mentődik. A neved és az idő automatikusan kerül mellé.']; }
		return ['', b.nat ? 'Utoljára: ' + b.nat + (b.nby ? ' · ' + b.nby : '') : 'Gépelés közben magától mentődik.'];
	}
	function ivStat(bid) {
		const box = ivBox(); const el = box && box.querySelector('[data-ivst="' + bid + '"]'); if (!el) return;
		const [c, t] = ivStatTxt(bid); el.className = 'iv-ns' + (c ? ' ' + c : ''); el.textContent = t;
	}
	/* a mező tartalmának cseréje a kurzor megtartásával (a hozzáfűzött rész a végére kerül) */
	function ivSetTa(bid, v) {
		const box = ivBox(), t = box && box.querySelector('[data-ivta="' + bid + '"]'); if (!t || t.value === v) return;
		const f = document.activeElement === t, a = t.selectionStart, z = t.selectionEnd;
		t.value = v; autosz(t); if (f) { try { t.setSelectionRange(a, z); } catch (er) { /* nem baj */ } }
	}
	/* a nyitott interjú-nézet 20 mp-enként csendben frissül – a kollégák jegyzetei gépelés nélküli állapotban megjelennek */
	setInterval(async () => {
		if (!ivBox() || !IV.d || IV.busy || IV.setOpen || document.hidden || ivPending()) return;
		try {
			const d = await post('cp_iv_admin', {});
			if (!ivBox() || IV.busy || ivPending()) return;
			if (JSON.stringify(d) === JSON.stringify(IV.d)) return;
			IV.d = d; ivSync(); ivDraw();
		} catch (er) { /* a következő kör újrapróbálja */ }
	}, 20000);
	const autosz = ta => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight + 2, 480) + 'px'; };
	const othH = b => { const L = (b.cm || []).filter(c => !c.mine); return oldCard(b) + L.map(cmCard).join('') + (!L.length && !b.note ? '<p class="iv-cm0">Még senki más nem írt bejegyzést.</p>' : ''); };
	function ivNoteBox(b) {
		const e = edOf(b), [c, t] = ivStatTxt(b.id);
		return '<div class="iv-nb" id="ivn-' + b.id + '">' + (CM() ? '<div class="iv-oth" data-ivoth="' + b.id + '">' + othH(b) + '</div><div class="iv-mel">A te bejegyzésed</div>' : '') + '<div class="iv-rates" role="group" aria-label="Értékelés">' +
			RATES.map(r => '<button type="button" class="iv-rb ' + r[2] + '" data-ivrate="' + b.id + '" data-v="' + r[0] + '" aria-pressed="' + (e.rate === r[0]) + '">' + r[1] + '</button>').join('') + '</div>' +
			'<textarea data-ivta="' + b.id + '" rows="3" maxlength="5000" aria-label="Interjú-jegyzet: ' + esc(b.name) + '" placeholder="' + (CM() ? 'A te benyomásod – a neved és az idő automatikusan mellé kerül' : 'Jegyzet az interjúhoz – benyomás, tapasztalat, mikor ér rá…') + '">' + esc(e.text) + '</textarea>' +
			'<div class="iv-ns' + (c ? ' ' + c : '') + '" data-ivst="' + b.id + '" aria-live="polite">' + esc(t) + '</div></div>';
	}
	function ivDraw() {
		const box = ivBox(); if (!box) return;
		if (!IV.d) { box.innerHTML = IV.err ? '<div class="cpx-empty err">' + esc(IV.err) + '<br><button type="button" class="cpx-btn" data-ivretry>Újra</button></div>' : '<div class="cpx-empty">Betöltés…</div>'; const rb = box.querySelector('[data-ivretry]'); if (rb) rb.addEventListener('click', () => { IV.err = ''; ivDraw(); ivLoad(); }); return; }
		/* a fókuszban lévő jegyzetmező (és a kurzor) újrarajzolás után is marad */
		const ae = document.activeElement, act = ae && box.contains(ae) && ae.dataset && ae.dataset.ivta ? { b: ae.dataset.ivta, s: ae.selectionStart, e: ae.selectionEnd } : null;
		const d = IV.d, o = d.opts, days = d.days || {}, NT = !!d.notes;
		let tot = 0, bk = 0;
		Object.keys(days).forEach(k => days[k].slots.forEach(s => { if (!s.blocked) tot++; if (s.b) bk++; }));
		if (IV.focus && NT) { if (ivFind(IV.focus)) IV.open.add(IV.focus); else toast('Ez a foglalás már nincs a listában (lemondták vagy áthelyezték).', true); }
		let h = '<div class="iv-top"><label class="iv-sw"><input type="checkbox" data-ivon' + (o.on ? ' checked' : '') + (K.admin ? '' : ' disabled') + '> <b>' + (o.on ? 'A foglalás nyitva' : 'A foglalás zárva') + '</b></label>' +
			'<span class="iv-sum">' + bk + ' / ' + tot + ' időpont foglalt' + (d.cancelled ? ' · ' + d.cancelled + ' lemondás' : '') + '</span></div>' +
			'<div class="iv-link"><input type="text" readonly value="' + esc(d.url) + '" aria-label="A jelentkezőknek küldendő link"><button type="button" class="cpx-btn" data-ivcopy>Link másolása</button><a class="cpx-btn ghost" href="' + esc(d.url) + '" target="_blank" rel="noopener">Megnyitás</a></div>' +
			(!o.location ? '<p class="iv-warn">Még nincs megadva az interjú helyszíne – a levelekbe és az oldalra ez kerül.' + (K.admin ? ' Add meg lent, a Beállításokban.' : '') + '</p>' : '') +
			(NT ? '' : '<p class="iv-warn">A jegyzetek az első oldalbetöltés után lesznek elérhetők (az adatbázis frissül).</p>');
		Object.keys(days).forEach(k => {
			const D = days[k], n = D.slots.filter(s => s.b).length;
			h += '<section class="iv-day"><h4>' + esc(D.label) + ' <small>' + n + ' / ' + D.slots.filter(s => !s.blocked).length + ' foglalt</small></h4><div class="iv-list">' + D.slots.map(s => {
				const b = s.b;
				if (!b) return '<div class="iv-r' + (s.blocked ? ' bl' : '') + '"><div class="iv-tm">' + esc(s.tm) + '</div><div class="iv-who"><span class="iv-mu">' + (s.blocked ? 'letiltva' : 'szabad') + '</span></div><div class="iv-a"><button type="button" class="kz-link" data-ivblock="' + s.id + '" data-on="' + (s.blocked ? 0 : 1) + '">' + (s.blocked ? 'Feloldás' : 'Letiltás') + '</button></div></div>';
				const op = NT && IV.open.has(b.id), e = IV.ed[b.id], nt = CM() ? (((b.cm || []).slice(-1)[0] || {}).body || b.note || '') : (e ? e.text : (b.note || '')), rt = e ? e.rate : (b.rate || 0);
				const first = String(nt).split('\n').map(x => x.trim()).filter(Boolean)[0] || '';
				const nC = (b.cm || []).length;
				return '<div class="iv-r bk' + (op ? ' op' : '') + '" data-bid="' + b.id + '"><div class="iv-tm">' + esc(s.tm) + '</div>' +
					'<div class="iv-who"><b>' + esc(b.name) + '</b><span class="iv-c"><a href="tel:' + esc(String(b.phone).replace(/[^0-9+]/g, '')) + '">' + esc(b.phone) + '</a> · <a href="mailto:' + esc(b.email) + '">' + esc(b.email) + '</a></span>' +
					(!op && (first || rt || nC) ? '<button type="button" class="iv-np" data-ivnote="' + b.id + '" title="A bejegyzések megnyitása">' + (CM() ? rateSum((b.cm || []).concat(b.rate && !(b.cm || []).some(c => c.who === b.nby) ? [{ rate: b.rate }] : [])) /* v2.9.144: a régi közös értékelés csak akkor számít, ha a szerzőjétől még nincs saját bejegyzés */ + (nC ? '<em>' + nC + ' bejegyzés</em>' : '') : rateChip(rt)) + (first ? '<span>' + esc(first) + '</span>' : '') + '</button>' : '') + '</div>' +
					'<div class="iv-a">' + (NT ? '<button type="button" class="kz-link" data-ivnote="' + b.id + '" aria-expanded="' + op + '" aria-controls="ivn-' + b.id + '">' + (op ? 'Becsuk' : (CM() ? 'Bejegyzések' : 'Jegyzet')) + '</button>' : '') + '<button type="button" class="kz-link iv-del" data-ivcancel="' + b.id + '" data-n="' + esc(b.name) + '">Lemondás</button></div>' +
					(op ? ivNoteBox(b) : '') + '</div>';
			}).join('') + '</div></section>';
		});
		if (!Object.keys(days).length) h += '<div class="cpx-empty">Nincs beállított nap' + (K.admin ? ' – add meg lent, a Beállításokban.' : '.') + '</div>';
		h += '<div class="iv-tools"><button type="button" class="cpx-btn ghost" data-ivcsv>⬇ Lista (CSV)</button><button type="button" class="cpx-btn ghost" data-ivprint>Nyomtatás</button></div>';
		if (K.admin) {
			h += '<details class="iv-set"' + (IV.setOpen ? ' open' : '') + '><summary>Beállítások</summary><div class="iv-g">' +
				'<label>Cím (a lap és a levelek fejléce)<input type="text" data-o="title" value="' + esc(o.title) + '"></label>' +
				'<label>Helyszín (a levelekben és az oldalon)<textarea data-o="location" rows="2" placeholder="pl. 1095 Budapest, Példa utca 1., 2. emelet – csengő: EventCrazy">' + esc(o.location) + '</textarea></label>' +
				'<label>Megjegyzés a jelentkezőknek (nem kötelező)<textarea data-o="note" rows="2" placeholder="pl. Hozz magaddal fényképes igazolványt.">' + esc(o.note) + '</textarea></label>' +
				'<div class="iv-row"><label>Egy időpont (perc)<input type="number" min="5" max="120" data-o="dur" value="' + o.dur + '"></label><label>Foglalás lezárása előtte (perc)<input type="number" min="0" data-o="close_min" value="' + o.close_min + '"></label><label>Lemondás / módosítás legkésőbb (óra)<input type="number" min="0" data-o="cancel_h" value="' + o.cancel_h + '"></label></div>' +
				'<label class="crm-chk"><input type="checkbox" data-o="remind"' + (o.remind ? ' checked' : '') + '> Emlékeztető e-mail az interjú előtti napon</label>' +
				'<p class="iv-lab">Napok és sávok</p><div class="iv-days">' + (o.days || []).map((x, i) => ivDayRow(x, i)).join('') + '</div><button type="button" class="kz-link" data-ivaddday>+ Nap hozzáadása</button>' +
				'<p class="iv-help">A foglalt időpontok mentéskor is megmaradnak. Ha egy sávot szűkítesz, a már foglalt időpontok a listában maradnak.</p>' +
				'<button type="button" class="cpx-btn" data-ivsave>Mentés</button></div></details>';
		}
		box.innerHTML = h;
		const q = (s, fn, ev) => box.querySelectorAll(s).forEach(el => el.addEventListener(ev || 'click', e => fn(el, e)));
		q('[data-ivcopy]', async () => { const v = d.url; try { await navigator.clipboard.writeText(v); toast('Link a vágólapon.'); } catch (e) { const i = box.querySelector('.iv-link input'); i.select(); document.execCommand('copy'); toast('Link a vágólapon.'); } });
		q('[data-ivon]', el => { const oo = ivCollect(box); oo.on = el.checked ? 1 : 0; ivDo({ do: 'save', o: JSON.stringify(oo) }).then(ok => ok && toast(oo.on ? 'A foglalás megnyitva.' : 'A foglalás lezárva.')); }, 'change');
		q('[data-ivblock]', el => ivDo({ do: el.dataset.on === '1' ? 'block' : 'unblock', id: el.dataset.ivblock }));
		q('[data-ivcancel]', el => { const b = ivFind(Number(el.dataset.ivcancel)) || {}; if (window.confirm('Lemondod ' + el.dataset.n + ' interjúját? Az időpont felszabadul. (A jelentkező nem kap erről levelet – szólj neki.)' + (b.note || (b.cm || []).length ? ' A bejegyzések megmaradnak a jelentkező adatlapján.' : ''))) { ivFlushAll(); ivDo({ do: 'cancel', id: el.dataset.ivcancel }); } });
		q('[data-ivsave]', () => ivDo({ do: 'save', o: JSON.stringify(ivCollect(box)) }).then(ok => ok && toast('Elmentve.')));
		q('[data-ivaddday]', () => { const w = box.querySelector('.iv-days'); w.insertAdjacentHTML('beforeend', ivDayRow({ d: '', from: '10:00', to: '14:00' }, w.children.length)); w.lastElementChild.querySelector('[data-ivdel]').addEventListener('click', e => e.target.closest('.iv-dr').remove()); });
		q('[data-ivdel]', el => el.closest('.iv-dr').remove());
		q('.iv-set', el => { IV.setOpen = el.open; }, 'toggle');
		/* jegyzet: lenyitás / becsukás, értékelés, gépelés */
		q('[data-ivnote]', el => { const id = Number(el.dataset.ivnote), was = IV.open.has(id); if (was) { IV.open.delete(id); ivSave(id); } else IV.open.add(id); ivDraw(); if (!was) { const t = box.querySelector('[data-ivta="' + id + '"]'); if (t) t.focus(); } });
		q('[data-ivrate]', el => { const id = Number(el.dataset.ivrate), e = IV.ed[id]; if (!e) return; const v = Number(el.dataset.v); e.rate = e.rate === v ? 0 : v; el.parentNode.querySelectorAll('[data-ivrate]').forEach(x => x.setAttribute('aria-pressed', String(Number(x.dataset.v) === e.rate))); ivQueue(id, true); });
		q('[data-ivta]', el => { const id = Number(el.dataset.ivta), e = IV.ed[id]; if (!e) return; e.text = el.value; autosz(el); ivQueue(id); }, 'input');
		q('[data-ivta]', el => ivSave(Number(el.dataset.ivta)), 'blur');
		box.querySelectorAll('[data-ivta]').forEach(autosz);
		if (act) { const t = box.querySelector('[data-ivta="' + act.b + '"]'); if (t) { t.focus({ preventScroll: true }); try { t.setSelectionRange(act.s, act.e); } catch (e) { /* nem baj */ } } }
		if (IV.focus) { const r = box.querySelector('.iv-r[data-bid="' + IV.focus + '"]'); IV.focus = 0; if (r) { r.scrollIntoView({ block: 'center' }); const t = r.querySelector('[data-ivta]'); if (t) t.focus({ preventScroll: true }); } }
		q('[data-ivcsv]', () => {
			const L = [['Nap', 'Időpont', 'Név', 'Telefon', 'E-mail'].concat(NT ? ['Értékelés', 'Jegyzet'] : [])];
			Object.keys(days).forEach(k => days[k].slots.forEach(s => { if (!s.b) return; const e = IV.ed[s.b.id], r = rateOf(e ? e.rate : s.b.rate); L.push([days[k].label, s.tm, s.b.name, s.b.phone, s.b.email].concat(NT ? (CM() ? [(s.b.cm || []).filter(c => c.rate).map(c => c.who + ': ' + rateOf(c.rate)[1]).join(', ') || (r ? r[1] : ''), cmText(s.b)] : [r ? r[1] : '', e ? e.text : (s.b.note || '')]) : [])); }));
			/* képletként értelmezhető cellák (=, +, -, @ az elején) elé aposztróf – a táblázatkezelő ne futtassa */
			const cel = c => { let v = String(c == null ? '' : c); if (/^[=+\-@\t\r]/.test(v)) v = "'" + v; return '"' + v.replace(/"/g, '""') + '"'; };
			const csv = '﻿' + L.map(r => r.map(cel).join(';')).join('\r\n');
			const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); a.download = 'interju-idopontok.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
		});
		q('[data-ivprint]', () => {
			const w = window.open('', '_blank'); if (!w) return;
			let p = '<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Interjú-időpontok</title><style>body{font:13px Arial,sans-serif;margin:24px}h2{font-size:16px;margin:18px 0 6px}table{border-collapse:collapse;width:100%}td{border-bottom:1px solid #ccc;padding:6px 4px;vertical-align:top}td:first-child{width:60px;font-weight:bold}.n{white-space:pre-wrap;margin-top:4px;color:#333}.r{font-weight:bold}tr{break-inside:avoid}</style></head><body><h1 style="font-size:18px">' + esc(o.title) + '</h1>';
			Object.keys(days).forEach(k => { p += '<h2>' + esc(days[k].label) + '</h2><table>' + days[k].slots.map(s => { const b = s.b, e = b && IV.ed[b.id], nt = b ? (CM() ? cmText(b) : (e ? e.text : (b.note || ''))) : '', r = b && !CM() && rateOf(e ? e.rate : b.rate); return '<tr><td>' + esc(s.tm) + '</td><td>' + (b ? esc(b.name) + '<br>' + esc(b.phone) + ' · ' + esc(b.email) + (r ? '<div class="r">Értékelés: ' + r[1] + '</div>' : '') + (nt ? '<div class="n">' + esc(nt) + '</div>' : '') : (s.blocked ? '— letiltva —' : '')) + '</td></tr>'; }).join('') + '</table>'; });
			w.document.write(p + '</body></html>'); w.document.close(); w.focus(); w.print();
		});
	}
	function ivDayRow(x, i) { return '<div class="iv-dr"><input type="date" data-dd value="' + esc(x.d) + '" aria-label="Nap"><input type="time" step="300" data-df value="' + esc(x.from) + '" aria-label="Kezdés"><span>–</span><input type="time" step="300" data-dt value="' + esc(x.to) + '" aria-label="Vége"><button type="button" class="kz-tog" data-ivdel aria-label="Nap törlése" title="Nap törlése">×</button></div>'; }
	function ivCollect(box) {
		const o = Object.assign({}, IV.d.opts);
		box.querySelectorAll('[data-o]').forEach(el => { o[el.dataset.o] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; });
		const ds = [...box.querySelectorAll('.iv-dr')].map(r => ({ d: r.querySelector('[data-dd]').value, from: r.querySelector('[data-df]').value, to: r.querySelector('[data-dt]').value })).filter(x => x.d && x.from && x.to);
		if (box.querySelector('.iv-days')) o.days = ds;
		return o;
	}
	/* v2.9.85: a jelentkező adatlapján az interjú-jegyzete – csak olvasható, link a foglaláshoz */
	/* v2.9.117: közelgő beosztásai (Kapacitás → Beosztás) */
	function slotRec(x) {
		const L = x.slots || []; if (!L.length) return '';
		const C2 = { final: 'ok', accepted: '', invited: 'no' };
		return '<div class="kz-slots"><b>Beosztás (közelgő)</b><ul>' + L.map(v => '<li>' + esc(huD(v.d)) + ' · ' + esc(v.g) + (v.v ? ' · ' + esc(v.v) : '') + (v.deal ? ' – <a href="#deals:' + v.deal + '" data-deal="' + v.deal + '">' + esc(v.t || 'Deal #' + v.deal) + '</a>' : '') + ' <span class="kz-chip ' + (C2[v.st] || '') + '">' + esc(v.sl) + '</span></li>').join('') + '</ul></div>';
	}
	function ivRec(x) {
		const L = (x.iv || []).filter(v => v.live || v.note || v.rate || (v.cm || []).length); if (!L.length) return '';
		return '<div class="kz-iv"><b>Interjú</b>' + L.map(v => '<div class="kz-ivi"><div class="kz-ivh"><span>' + esc(v.when || 'időpont nélkül') + (v.live ? '' : ' <span class="cpx-muted">(lemondva)</span>') + '</span>' + rateChip(v.rate) +
			(v.live && K.iv ? '<a class="kz-link" href="#kezelok:interju:' + v.bid + '" data-ivgo="' + v.bid + '">Jegyzet a foglalásnál →</a>' : '') + '</div>' +
			(v.cm && v.cm.length || v.note ? oldCard(v) + (v.cm || []).map(cmCard).join('') : '<p class="cpx-muted">Még nincs bejegyzés.</p>') + '</div>').join('') + '</div>';
	}
	function view(main, keep) {
		S.main = main;
		const h = S.force || ivHash(); S.force = null;
		if (h) { S.sub = h.v; if (h.b) IV.focus = h.b; }
		if (!K.iv) S.sub = 'list';
		if (S.sub === 'iv') { if (location.hash !== '#kezelok:interju') history.replaceState(history.state, '', '#kezelok:interju'); return ivView(main); }
		/* a jegyzetek változtak: a jelentkezők adatlapja frissen töltődik */
		if (IV.changed) { IV.changed = false; keep = false; }
		main.innerHTML = '<div class="kz">' + vsw() + '<div class="kz-h"><div class="crm-seg kz-seg"></div><input type="search" data-q placeholder="Keresés: név, e-mail, telefon, rendszám" value="' + esc(S.q) + '" aria-label="Keresés">' +
			'<label class="crm-chk"><input type="checkbox" data-inact' + (S.inact ? ' checked' : '') + '> Inaktívak is</label>' +
			(K.admin ? '<button type="button" class="cpx-btn ghost" data-rev>' + (S.reveal ? '🔒 Személyes adatok elrejtése' : '🔓 Személyes adatok megjelenítése') + '</button>' + (K.csv ? '<a class="cpx-btn ghost" href="' + esc(K.csv + '&t=' + encodeURIComponent(C.t || '')) + '" title="Minden adat letöltése (CSV) – naplózva">⬇ CSV</a>' : '') : '') +
			(S.edit ? '<button type="button" class="cpx-btn ghost" data-new>+ Új</button>' : '') + '<button type="button" class="cpx-btn' + (S.edit ? ' kz-on' : '') + '" data-edit>' + (S.edit ? '✓ Kész' : '✎ Szerkesztés') + '</button></div>' +
			'<p class="kz-help">' + (S.edit ? '<b>Szerkesztés:</b> kattints a mezőbe, írd át – Enterre vagy máshova kattintva mentődik. A pipák is itt állíthatók. Ha végeztél: <b>✓ Kész</b>.' : 'A lista csak olvasható. Módosításhoz: <b>✎ Szerkesztés</b>.') + '</p><div class="kz-body"><div class="cpx-empty">Betöltés…</div></div></div>';
		bindVsw(main);
		let t = null;
		main.querySelector('[data-q]').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => { S.q = e.target.value.trim(); draw(); }, 200); });
		main.querySelector('[data-inact]').addEventListener('change', e => { S.inact = e.target.checked; draw(); });
		/* v2.9.58: a szerkesztés / olvasás váltása a már betöltött sorokból rajzol újra (nem kér le mindent újra) */
		main.querySelector('[data-edit]').addEventListener('click', () => { S.edit = !S.edit; S.open = 0; view(main, true); });
		const rv = main.querySelector('[data-rev]');
		if (rv) rv.addEventListener('click', () => { S.reveal = !S.reveal; view(main); });
		const nb = main.querySelector('[data-new]');
		if (nb) nb.addEventListener('click', async () => {
			const n = (window.prompt('Az új kezelő neve:', '') || '').trim(); if (!n) return;
			const r = await save(0, { name: n, kind: S.kind }); if (r) { S.open = r.id; draw(); toast('Felvéve – töltsd ki a többi adatát.'); }
		});
		if (keep && S.loaded) draw(); else load();
	}
	function status(s) { s.textContent = S.err || ''; s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	window.CPKezelok = { view, status, deep, ivOpen }; /* v2.9.85: deep = értesítésből / mélylinkből a nézet */

	const css = document.createElement('style');
	css.id = 'kz-css';
	css.textContent = '.kz-slots{margin:8px 0}.kz-slots ul{margin:4px 0 0;padding-left:18px;font-size:13px}.kz-slots li{margin:2px 0}.kz-slots a{color:#2F5BD8;text-decoration:none}' + '.kz-r td{padding:5px 8px;font-size:12.5px;line-height:1.3}.kz-r th{padding:6px 8px}.kz-r a{color:#2F5BD8;text-decoration:none}.kz-nm b{display:block;font-size:13px}.kz-nm small{display:block;color:#656973;font-size:11px}.kz-nw{white-space:nowrap}.kz-ex{color:#656973;max-width:220px}.kz-chips{min-width:120px}.kz-chip{display:inline-block;font-size:10.5px;font-weight:600;background:#EEF3FD;color:#2F4FA8;border-radius:5px;padding:1px 6px;margin:1px 3px 1px 0;white-space:nowrap}.kz-chip.ok{background:#DDF3E6;color:#17663A}.kz-chip.no{background:#F3F4F6;color:#656973}.kz-car{font-size:10.5px;font-weight:600;border-radius:999px;padding:1px 7px}span.kz-car.szemely{background:#DDF3E6;color:#17663A}span.kz-car.teher{background:#3B2F2A;color:#fff}.kz-sc{text-align:center;font-weight:600;font-size:12px}.kz-sc.ok{color:#17663A}.kz-sc.mid{color:#9A5B00;background:#FFF8E1}.kz-sc.no{color:#B4400E;background:#FDF1EC}.kz-sr{font-size:11.5px;min-width:220px}.kz-sr small{color:#656973}.kz-note{white-space:pre-wrap;margin:0 0 6px}.cpx-btn.kz-on{background:#17663A;border-color:#17663A}' +
		'.kz-c{text-align:center}.kz-c input{width:17px!important;min-width:0!important;height:17px;accent-color:#2E7D32;cursor:pointer}.kz-t th.kz-ch{font-size:10.5px;text-align:center;white-space:normal;min-width:56px;max-width:86px}.kz-car{min-width:100px!important;border-radius:999px!important;text-align:center}.kz-car.szemely{background:#DDF3E6!important;color:#17663A}.kz-car.teher{background:#3B2F2A!important;color:#fff}.kz-c.saving{background:#FFF8E1}.kz-c.saved{background:#E8F6EE}' +
		'.kz{max-width:100%}.kz-h{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px}.kz-h input[type=search]{flex:1;min-width:150px;border:1px solid #E6E3DE;border-radius:8px;padding:7px 9px;font:13px Inter,sans-serif}.kz-help{font-size:12.5px;color:#656973;margin:0 0 8px}' +
		'.kz-n{font-size:12.5px;color:#656973;margin-bottom:6px}.kz-warn{color:#B4400E}.kz-open{color:#17663A}.kz-tw{background:#fff;border:1px solid #E6E3DE;border-radius:12px;overflow:auto}' +
		'.kz-t{border-collapse:collapse;font-size:13px;min-width:100%}.kz-t th{background:#E9F3E2;color:#2D4A1E;font-size:11.5px;text-align:left;padding:7px 6px;border-bottom:1px solid #D4E6C6;white-space:nowrap;position:sticky;top:0}.kz-t th.kz-sh{background:#F1F5EC}' +
		'.kz-t td{border-bottom:1px solid #F0ECE6;padding:2px 3px;vertical-align:middle}.kz-t input,.kz-t select{width:100%;min-width:110px;border:1px solid transparent;border-radius:6px;padding:6px 6px;font:13px Inter,sans-serif;background:transparent;color:#141C33}' +
		'.kz-t input:hover,.kz-t select:hover{border-color:#E6E3DE}.kz-t input:focus,.kz-t select:focus{border-color:#2F5BD8;background:#fff;outline:none}.kz-t input[data-f=name]{font-weight:600;min-width:150px}.kz-t input[data-f=email]{min-width:190px}.kz-t td.kz-sv input{min-width:120px}.kz-t input[data-f=address]{min-width:230px}' +
		'.kz-t input.saving{background:#FFF8E1}.kz-t input.saved,.kz-t select.saved{background:#E8F6EE}.kz-s{font-size:12px;text-align:center;white-space:nowrap}.kz-s.ok{color:#17663A}.kz-s.no{color:#B4400E;background:#FDF1EC}' +
		'.kz-fl{width:28px;text-align:center}.kz-flw{width:46px;white-space:nowrap}.kz-drag{cursor:grab;color:#B5B0A8;font-size:14px;padding:0 3px;user-select:none}.kz-drag:hover{color:#141C33}tr.kz-dragging td{opacity:.45;background:#EEF3FD!important}.kz-tog{border:0;background:none;cursor:pointer;color:#656973;font-size:13px;width:24px;height:24px;border-radius:6px}.kz-tog:hover{background:#F0ECE6;color:#141C33}.kz-fl button{border:0;background:none;cursor:pointer;font-size:15px;color:#D0CCC5}tr.fl-red td{background:#FBE9E7}tr.fl-red .kz-fl button{color:#D84315}tr.fl-yellow td{background:#FFF8E1}tr.fl-yellow .kz-fl button{color:#F9A825}tr.fl-green td{background:#EAF6EC}tr.fl-green .kz-fl button{color:#2E7D32}tr.off td{opacity:.55}' +
		'.kz-link{border:0;background:none;color:#2F5BD8;cursor:pointer;font:600 12.5px Inter,sans-serif;text-align:left;white-space:nowrap}.kz-link small{display:block;color:#656973;font-weight:500}' +
		'.kz-det td{background:#FAF8F5!important}.kz-d{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:8px 6px}.kz-d label{display:block;font-size:12px;color:#656973}.kz-d textarea{width:100%;border:1px solid #E6E3DE;border-radius:8px;padding:6px;font:13px Inter,sans-serif}.kz-d ul{margin:6px 0;padding-left:18px}.kz-d li{margin:3px 0}.kz-chk{display:flex!important;gap:6px;align-items:center;margin:6px 0;color:#141C33!important;font-size:13px!important}.kz-d input[type=checkbox]{width:auto;min-width:0;accent-color:#2F5BD8}.kz-d select{width:auto!important;min-width:150px!important;border:1px solid #E6E3DE!important;background:#fff!important}' +
		/* v2.9.85: Interjú-időpontok oldal (a fül fő területén), nézetkapcsoló, jegyzet */
		'.kz-vs{margin:0 0 10px}.kz-vs a{border-radius:999px;padding:6px 14px;font:700 13px "Plus Jakarta Sans",sans-serif;color:var(--mu);text-decoration:none;white-space:nowrap}.kz-vs a.on{background:var(--n);color:#fff}.kz-vs a:not(.on):hover{color:var(--n)}.kz-vs a:focus-visible,.iv-rb:focus-visible,.iv-np:focus-visible{outline:2px solid #2F5BD8;outline-offset:2px}' +
		'.iv-pg{max-width:900px}.iv-b{padding:2px 0 12px}.iv-top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:10px}.iv-sw{display:inline-flex;gap:8px;align-items:center;cursor:pointer}.iv-sw input{width:18px;height:18px;accent-color:#2E7D32}.iv-sum{font-size:13px;color:#656973}' +
		'.iv-link{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}.iv-link input{flex:1;min-width:200px;border:1px solid #E6E3DE;border-radius:8px;padding:8px 10px;font:13px Inter,sans-serif;background:#FAF8F5}.iv-warn{background:#FFF4E5;color:#8A4B08;border-radius:10px;padding:8px 10px;font-size:13px;margin:0 0 10px}' +
		'.iv-day h4{margin:16px 0 6px;font:700 14px "Plus Jakarta Sans",sans-serif}.iv-day h4 small{font:500 12px Inter,sans-serif;color:#656973;margin-left:6px}' +
		'.iv-list{background:#fff;border:1px solid #E6E3DE;border-radius:12px;overflow:hidden;font-size:13px}.iv-r{display:grid;grid-template-columns:52px minmax(0,1fr) auto;gap:4px 12px;padding:8px 12px;border-bottom:1px solid #F0ECE6;align-items:start}.iv-r:last-child{border-bottom:0}.iv-r.bk{background:#F3F9F4}.iv-r.bl{background:#F7F5F2;color:#8C8880}' +
		'.iv-who{min-width:0}.iv-who b{display:block;overflow-wrap:anywhere}.iv-a{display:flex;gap:14px;justify-content:flex-end;align-items:center;white-space:nowrap}.iv-a .kz-link{padding:2px 0}.iv-a .iv-del{color:#9A3A12}' +
		'.iv-np{display:flex;gap:6px;align-items:center;max-width:100%;margin-top:4px;padding:0;border:0;background:none;cursor:pointer;font:12.5px/1.4 Inter,sans-serif;color:#3C4252;text-align:left}.iv-np span:last-child{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.iv-np:hover span:last-child{text-decoration:underline;text-underline-offset:2px}' +
		'.iv-rt{flex:none;font:600 10.5px Inter,sans-serif;border-radius:5px;padding:1px 6px;white-space:nowrap}.iv-rt.ok{background:#DDF3E6;color:#17663A}.iv-rt.mid{background:#FFF1D6;color:#8A4B08}.iv-rt.no{background:#FBE3DC;color:#9A3A12}' +
		'.kz-av{margin:10px 0;padding:10px 12px;border:1px solid var(--li);border-radius:12px;background:#FAF8F5;display:flex;flex-direction:column;gap:8px}.kz-av.saving{opacity:.6}.kz-avq{display:flex;flex-wrap:wrap;gap:4px 16px}.kz-avw,.kz-avr{display:flex;flex-wrap:wrap;align-items:center;gap:6px}.kz-avw small,.kz-avr small{color:var(--mu);font-size:12px;margin-right:2px}' +
		'.kz-avw button{min-width:38px;height:30px;border:1px solid #DCD6CC;border-radius:999px;background:#fff;font:600 12.5px Inter,sans-serif;color:var(--ink);cursor:pointer}.kz-avw button[aria-pressed=true]{background:#FDECEA;border-color:#E8A5A0;color:#B42318;text-decoration:line-through}' +
		'.kz-avr input{height:32px;border:1px solid var(--li);border-radius:8px;padding:0 8px;font:13px Inter,sans-serif;background:#fff}.kz-avl{display:flex;flex-wrap:wrap;gap:6px}.kz-avl .kz-chip{background:#FDECEA;color:#B42318;display:inline-flex;align-items:center;gap:4px}.kz-avl button{border:0;background:none;color:inherit;font-size:14px;cursor:pointer;padding:0 2px}' +
		'.kz-avn{display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--mu)}.kz-avn input{height:32px;border:1px solid var(--li);border-radius:8px;padding:0 8px;font:13px Inter,sans-serif;color:var(--ink);background:#fff}.kz-avs{font-size:13px;margin:6px 0}' +
		'.iv-oth{display:flex;flex-direction:column;gap:6px}.iv-cm{background:#FAF8F5;border:1px solid #EEE9E1;border-radius:10px;padding:8px 10px}.iv-cm.old{background:#F5F3EF;border-style:dashed}.iv-cm.me{background:#EEF3FD;border-color:#D6E2FB}.iv-cmh{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;font-size:13px}.iv-cmh small{margin-left:auto;color:var(--mu);font-size:11.5px;font-variant-numeric:tabular-nums}.iv-cm p{margin:4px 0 0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:13.5px}.iv-cm0{margin:0;font-size:12.5px;color:var(--mu)}.iv-mel{font:700 11.5px Inter,sans-serif;text-transform:uppercase;letter-spacing:.04em;color:var(--mu);margin-top:6px}.iv-np em{font-style:normal;font-size:12px;color:var(--mu);margin:0 4px}.kz-ivi .iv-cm{margin-top:6px}' +
		'.iv-nb{grid-column:2/-1;display:flex;flex-direction:column;gap:6px;margin:4px 0 2px}.iv-rates{display:flex;gap:6px;flex-wrap:wrap}.iv-rb{min-height:30px;padding:0 12px;border:1px solid #DCD6CC;border-radius:999px;background:#fff;color:#3C4252;font:600 12.5px Inter,sans-serif;cursor:pointer}.iv-rb:hover{border-color:#B9B2A7}' +
		'.iv-rb.ok[aria-pressed=true]{background:#DDF3E6;border-color:#9FD3B4;color:#17663A}.iv-rb.mid[aria-pressed=true]{background:#FFF1D6;border-color:#EBC27E;color:#8A4B08}.iv-rb.no[aria-pressed=true]{background:#FBE3DC;border-color:#E9A991;color:#9A3A12}' +
		'.iv-nb textarea{width:100%;min-height:76px;max-height:480px;border:1px solid #DCD6CC;border-radius:8px;padding:8px 10px;font:13.5px/1.45 Inter,sans-serif;color:#141C33;background:#fff;resize:vertical;box-sizing:border-box}.iv-nb textarea:focus{border-color:#2F5BD8;outline:none;box-shadow:0 0 0 3px rgba(47,91,216,.15)}.iv-ns{font-size:12px;color:#656973}.iv-ns.ok{color:#17663A}.iv-ns.bad{color:#B4400E}' +
		'.kz-iv{margin-top:10px;padding-top:8px;border-top:1px solid #EDE8E1}.kz-ivi{margin:6px 0 10px}.kz-ivh{display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center;font-size:12.5px;margin-bottom:3px}.kz-ivh .kz-link{margin-left:auto;text-decoration:none}' +
		'.iv-tm{font-weight:700;font-variant-numeric:tabular-nums}.iv-c{display:block;font-size:12px;color:#656973;overflow-wrap:anywhere}.iv-c a{color:#2F5BD8;text-decoration:none}.iv-mu{color:#8C8880}' +
		'.iv-tools{display:flex;gap:6px;margin:14px 0}.iv-set{border-top:1px solid #F0ECE6;padding-top:10px;margin-top:6px}.iv-set summary{cursor:pointer;font-weight:700}.iv-g{display:flex;flex-direction:column;gap:10px;margin-top:10px}.iv-g label{display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:#656973}' +
		'.iv-g input[type=text],.iv-g input[type=number],.iv-g textarea,.iv-dr input{border:1px solid #E6E3DE;border-radius:8px;padding:7px 9px;font:13px Inter,sans-serif;color:#141C33}.iv-row{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.iv-lab{margin:6px 0 0;font-weight:700;font-size:13px}.iv-dr{display:flex;gap:6px;align-items:center;margin-bottom:6px}.iv-help{font-size:12px;color:#656973;margin:0}' +
		'@media (max-width:760px){.kz-d{grid-template-columns:1fr}.iv-row{grid-template-columns:1fr}}' +
		'@media (max-width:560px){.iv-r{grid-template-columns:44px minmax(0,1fr);padding:9px 10px}.iv-a{grid-column:2;justify-content:flex-start;gap:18px}.iv-a .kz-link{padding:6px 0}.iv-nb{grid-column:1/-1}.iv-rb{min-height:36px;padding:0 14px}.iv-nb textarea{font-size:16px}.kz-vs{display:flex}.kz-vs a{flex:1;text-align:center}}';
	document.head.appendChild(css);
})();
