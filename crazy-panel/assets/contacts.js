/* Crazy panel – Kapcsolatok fül (v1.49): kontaktok és cégek, a deal-jeik számával és a megnyert összeggel */
(function () {
	'use strict';
	const C = window.CPX || {};
	if (!C.crm || !C.crm.on) return;
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const HU_MS = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
	const huD = s => { if (!s) return ''; const p = String(s).slice(0, 10).split('-').map(Number); return p[0] + '. ' + HU_MS[p[1] - 1] + ' ' + p[2] + '.'; };
	const money = v => !v ? '–' : Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft';
	const ini = n => String(n || '').split(/[\s@]+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?';
	const AVC = ['#F6B48F', '#93D5B4', '#A9C1F6', '#E3B5E6', '#F3D27F', '#9FD8E5', '#F2A5A5'];
	const S = { kind: 'ct', q: '', so: 'recent', only: true, p: 0, data: null, err: '', main: null, seq: 0 };
	try { const k = JSON.parse(localStorage.getItem('cpx_people') || '{}'); if (k.kind === 'co' || k.kind === 'ct') S.kind = k.kind; if (typeof k.so === 'string') S.so = k.so; if (typeof k.only === 'boolean') S.only = k.only; } catch (e) { /* privát mód */ }
	const save = () => { try { localStorage.setItem('cpx_people', JSON.stringify({ kind: S.kind, so: S.so, only: S.only })); } catch (e) { /* privát mód */ } };

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
	const SORTS = [['recent', 'Legutóbbi deal'], ['event', 'Legutóbbi rendezvény'], ['deals', 'Legtöbb deal'], ['won', 'Legtöbb bevétel'], ['name', 'Név (A–Z)']];
	function view(main) {
		S.main = main;
		main.innerHTML = '<div class="pp"><div class="pp-h"><div class="crm-seg"><button type="button" data-k="ct" class="' + (S.kind === 'ct' ? 'on' : '') + '">Kontaktok</button><button type="button" data-k="co" class="' + (S.kind === 'co' ? 'on' : '') + '">Cégek</button></div>' +
			'<input type="search" data-q placeholder="' + (S.kind === 'ct' ? 'Keresés: név, e-mail, telefon, város, cég' : 'Keresés: cégnév, weboldal') + '" value="' + esc(S.q) + '" aria-label="Keresés">' +
			'<select data-so aria-label="Rendezés">' + SORTS.map(x => '<option value="' + x[0] + '"' + (S.so === x[0] ? ' selected' : '') + '>' + x[1] + '</option>').join('') + '</select>' +
			'<label class="crm-chk"><input type="checkbox" data-only' + (S.only ? ' checked' : '') + '> Csak akiknek van deal-je</label></div><div class="pp-body"><div class="cpx-empty">Betöltés…</div></div></div>';
		main.querySelectorAll('[data-k]').forEach(b => b.addEventListener('click', () => { if (S.kind === b.dataset.k) return; S.kind = b.dataset.k; S.p = 0; save(); view(main); }));
		let t = null;
		main.querySelector('[data-q]').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => { S.q = e.target.value.trim(); S.p = 0; load(); }, 300); });
		main.querySelector('[data-so]').addEventListener('change', e => { S.so = e.target.value; S.p = 0; save(); load(); });
		main.querySelector('[data-only]').addEventListener('change', e => { S.only = e.target.checked; S.p = 0; save(); load(); });
		load();
	}
	async function load() {
		const my = ++S.seq, main = S.main;
		if (!main || !main.isConnected) return;
		const body = main.querySelector('.pp-body');
		let r;
		try { r = await post('cp_crm_people', { kind: S.kind, q: S.q, so: S.so, only: S.only ? 1 : '', p: S.p }); S.err = ''; } catch (e) { if (my === S.seq) { S.err = e.message; body.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; } return; }
		if (my !== S.seq) return;
		S.data = r;
		const pages = Math.max(1, Math.ceil(r.total / r.n)), ct = r.kind === 'ct';
		body.innerHTML = '<div class="pp-n">' + r.total.toLocaleString('hu-HU') + (ct ? ' kontakt' : ' cég') + (pages > 1 ? ' · ' + (r.p + 1) + '. oldal / ' + pages : '') + '</div>' +
			(r.rows.length ? '<div class="pp-tw"><table class="pp-t"><thead><tr><th>' + (ct ? 'Kontakt' : 'Cég') + '</th><th>' + (ct ? 'Cég' : 'Kontaktok') + '</th><th class="r">Deal</th><th class="r">Megnyert</th><th class="r">Bevétel</th><th>Legutóbbi rendezvény</th></tr></thead><tbody>' +
				r.rows.map(x => '<tr tabindex="0" data-id="' + x.id + '"><td><span class="pp-av' + (ct ? '' : ' sq') + '" style="background:' + AVC[x.id % AVC.length] + '">' + esc(ini(x.name || x.email)) + '</span><span class="pp-nm"><b>' + esc(x.name || x.email || '#' + x.id) + '</b><small>' + esc(ct ? [x.name && x.email !== x.name ? x.email : '', x.mobile || x.phone, x.city].filter(Boolean).join(' · ') : (x.website || '')) + '</small></span></td>' +
					'<td>' + (ct ? (x.co ? '<a href="#" data-co="' + x.co_id + '">' + esc(x.co) + '</a>' : '<span class="cpx-muted">–</span>') : x.cts) + '</td><td class="r">' + x.n + (x.open ? ' <small class="pp-open">' + x.open + ' nyitott</small>' : '') + '</td><td class="r">' + x.won + '</td><td class="r">' + esc(money(x.won_v)) + '</td><td>' + esc(huD(x.last_ev)) + '</td></tr>').join('') + '</tbody></table></div>' +
				(pages > 1 ? '<div class="pp-pg"><button type="button" class="cpx-btn ghost" data-pg="-1"' + (r.p ? '' : ' disabled') + '>← Előző</button><button type="button" class="cpx-btn ghost" data-pg="1"' + (r.p + 1 < pages ? '' : ' disabled') + '>Következő →</button></div>' : '') :
				'<p class="cpx-muted">' + (S.q ? 'Nincs találat.' : 'Még nincs ' + (ct ? 'kontakt' : 'cég') + '.') + '</p>');
		body.querySelectorAll('tr[data-id]').forEach(tr => {
			const go = () => { const id = Number(tr.dataset.id); if (ct) window.CPCrm.contact(id); else window.CPCrm.company(id); };
			tr.addEventListener('click', e => { if (e.target.closest('[data-co]')) return; go(); });
			tr.addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
		});
		body.querySelectorAll('[data-co]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); window.CPCrm.company(Number(a.dataset.co)); }));
		body.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => { S.p = Math.max(0, S.p + Number(b.dataset.pg)); load(); window.scrollTo(0, 0); }));
		if (window.CPXStatus) window.CPXStatus();
	}
	function status(s) { s.textContent = S.err || ''; s.className = 'cpx-status' + (S.err ? ' err' : ''); }
	async function refresh() { if (S.main && S.main.isConnected) await load(); }
	window.CPContacts = { view, status, refresh };

	const css = document.createElement('style');
	css.textContent = '.pp{max-width:1300px}.pp-h{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}.pp-h input[type=search]{flex:1;min-width:200px}.pp-h input[type=search],.pp-h select{border:1px solid #E6E3DE;border-radius:8px;padding:7px 9px;font:13px Inter,sans-serif;background:#fff;color:#141C33}' +
		'.pp-n{font-size:12.5px;color:#6B6F78;margin-bottom:6px}.pp-tw{background:#fff;border:1px solid #E6E3DE;border-radius:12px;overflow:auto}.pp-t{width:100%;border-collapse:collapse;font-size:13px}' +
		'.pp-t th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#8A8E96;padding:8px 10px;border-bottom:1px solid #E6E3DE;background:#FAF8F5;white-space:nowrap}.pp-t th.r,.pp-t td.r{text-align:right;white-space:nowrap}' +
		'.pp-t td{padding:7px 10px;border-bottom:1px solid #F0ECE6;vertical-align:middle}.pp-t tbody tr{cursor:pointer}.pp-t tbody tr:hover{background:#FAF8F5}.pp-t tbody tr:focus-visible{outline:2px solid #2F5BD8;outline-offset:-2px}' +
		'.pp-t td:first-child{display:flex;align-items:center;gap:10px;min-width:240px}.pp-av{flex:none;width:30px;height:30px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font:700 11.5px Inter,sans-serif;color:#1A1F2B}.pp-av.sq{border-radius:8px}' +
		'.pp-nm{min-width:0}.pp-nm b{display:block;font-weight:600}.pp-nm small{display:block;color:#8A8E96;font-size:12px}.pp-t a{color:#2F5BD8;text-decoration:none;font-weight:600}.pp-open{color:#2F5BD8;font-size:11.5px}.pp-pg{display:flex;gap:8px;justify-content:center;margin-top:10px}' +
		'@media (max-width:760px){.pp-t th:nth-child(2),.pp-t td:nth-child(2),.pp-t th:nth-child(4),.pp-t td:nth-child(4),.pp-t th:nth-child(6),.pp-t td:nth-child(6){display:none}.pp-t td:first-child{min-width:0}}';
	document.head.appendChild(css);
})();
