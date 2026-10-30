/* =====================================================================
 * Szerződésszerkesztő (v1.76) – a deal adatlapján „Szerződés” kártya
 * Bal oldalt az adatok (kiállító, megbízó, rendezvény, bepipálható szolgáltatások órával és díjjal, kiszállás, fizetés),
 * jobbra élő előnézet; Word (.docx) letöltés és nyomtatható nézet (PDF-ként menthető). Az utolsó állapotot a panel menti.
 * ===================================================================== */
(function () {
	'use strict';
	const C = window.CPX || {};
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	async function post(action, data) {
		const fd = new FormData(); fd.append('action', action); fd.append('t', C.t);
		Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j; try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || 'Hiba történt.');
		return j.data;
	}
	function toast(m, bad) {
		let d = document.getElementById('cpx-toast');
		if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); }
		d.textContent = m; d.className = 'cpx-toast on' + (bad ? ' err' : ''); clearTimeout(d._t); d._t = setTimeout(() => { d.className = 'cpx-toast'; }, 4000);
	}
	const slot = () => (C.ct && C.ct.on ? '<section class="d3-c d3-ct" data-contract><h4>Szerződés</h4><p class="d3-sub">Megbízási szerződés a deal és az ügyfélportál adataiból – a szolgáltatások bepipálhatók, vegyes megrendelés is.</p><div class="d3-pact"><button type="button" class="cpx-btn" data-ctopen>Szerződés készítése</button></div></section>' : '');
	function mount(sh, x) {
		const b = sh.querySelector('[data-ctopen]');
		if (b) b.addEventListener('click', () => open(x.deal.id, x.deal.title));
	}

	async function open(id, title) {
		let D;
		try { D = await post('cp_ct_get', { id }); } catch (e) { toast(e.message, true); return; }
		const st = JSON.parse(JSON.stringify(D.saved || D.prefill));
		const SV = {}; D.services.forEach(s => { SV[s.k] = s; });
		let dirty = false, timer = 0, req = 0;
		const w = document.createElement('div');
		w.className = 'ct-mod';
		w.innerHTML = '<div class="ct-card" role="dialog" aria-modal="true" aria-label="Szerződésszerkesztő"><header><h3>Szerződés – ' + esc(title || ('#' + id)) + '</h3><span class="ct-tabs"><button type="button" data-tab="f" class="on">Adatok</button><button type="button" data-tab="p">Előnézet</button></span><span class="ct-st" data-st>' + (D.saved ? 'Mentett változat: ' + esc(D.saved_at) : (st.src === 'portal' ? 'Adatok az ügyfélportálról' : 'Adatok a dealből – ellenőrizd')) + '</span><button type="button" class="ct-x" data-x aria-label="Bezárás">✕</button></header>' +
			'<div class="ct-body"><form class="ct-f" data-f></form><div class="ct-p" data-p><div class="cpx-empty">Előnézet…</div></div></div>' +
			'<footer>' + (D.saved ? '<button type="button" class="cpx-btn ghost" data-reset title="Újra a deal és a portál aktuális adataiból">Újratöltés a dealből</button>' : '') + '<span class="ct-sp"></span><button type="button" class="cpx-btn ghost" data-pdf>PDF / nyomtatás</button><button type="button" class="cpx-btn" data-docx>Word letöltése</button></footer></div>';
		document.body.appendChild(w);
		const F = w.querySelector('[data-f]'), PV = w.querySelector('[data-p]');
		const close = () => { if (dirty && !window.confirm('Bezárod a szerkesztőt? (Az adatok mentve vannak, a szerződés bármikor újra megnyitható.)')) return; w.remove(); };
		w.querySelector('[data-x]').addEventListener('click', close);
		w.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => { w.querySelectorAll('[data-tab]').forEach(z => z.classList.toggle('on', z === b)); w.classList.toggle('show-p', b.dataset.tab === 'p'); }));
		const rs = w.querySelector('[data-reset]');
		if (rs) rs.addEventListener('click', () => { if (!window.confirm('Felülírod a mentett szerződésadatokat a deal és a portál mostani adataival?')) return; Object.assign(st, JSON.parse(JSON.stringify(D.prefill))); draw(); refresh(); });
		const inp = (k, label, val, opt) => '<label class="' + ((opt && opt.cls) || '') + '">' + label + '<input data-k="' + k + '" value="' + esc(val) + '"' + (opt && opt.type ? ' type="' + opt.type + '"' : '') + (opt && opt.ph ? ' placeholder="' + esc(opt.ph) + '"' : '') + '></label>';
		function draw() {
			const c = st.client, chosen = {}; st.svc.forEach(s => { chosen[s.k] = s; });
			let h = '<fieldset><legend>Nyelv</legend><div class="ct-seg"><label><input type="radio" name="ct-lang" value="hu"' + (st.lang !== 'en' ? ' checked' : '') + '> Magyar</label><label><input type="radio" name="ct-lang" value="en"' + (st.lang === 'en' ? ' checked' : '') + '> English (angol)</label></div>' + (st.lang === 'en' ? '<p class="ct-hint">Az angol modulszövegek fordítások – az első használatnál érdemes átolvasni.</p>' : '') + '</fieldset>';
			h += '<fieldset><legend>Kiállító (megbízott)</legend><div class="ct-seg">' + Object.keys(D.issuers).map(k => '<label><input type="radio" name="ct-iss" value="' + k + '"' + (st.issuer === k ? ' checked' : '') + '> ' + esc(D.issuers[k]) + '</label>').join('') + '</div><p class="ct-hint">' + (st.issuer === 'EV' ? 'Az árak AAM bruttó összegként szerepelnek.' : 'Az árak nettó + ÁFA összegként szerepelnek.') + '</p></fieldset>';
			h += '<fieldset><legend>Megbízó</legend><div class="ct-seg"><label><input type="radio" name="ct-ct" value="ceg"' + (c.type === 'ceg' ? ' checked' : '') + '> Cég</label><label><input type="radio" name="ct-ct" value="magan"' + (c.type === 'magan' ? ' checked' : '') + '> Magánszemély</label></div><div class="ct-g">' +
				(c.type === 'ceg' ? inp('client.company', 'Cégnév', c.company, { cls: 'w2' }) + inp('client.seat', 'Székhely', c.seat, { cls: 'w2' }) + inp('client.reg', 'Cégjegyzékszám', c.reg) + inp('client.tax', 'Adószám', c.tax) + inp('client.signer', 'Képviseli (név, beosztás)', c.signer, { cls: 'w2' })
					: inp('client.name', 'Név', c.name, { cls: 'w2' }) + inp('client.address', 'Lakcím', c.address, { cls: 'w2' })) + '</div></fieldset>';
			h += '<fieldset><legend>Kapcsolattartó a megbízó részéről</legend><div class="ct-g">' + inp('contact.name', 'Név', st.contact.name) + inp('contact.tel', 'Telefon', st.contact.tel) + '</div></fieldset>';
			h += '<fieldset><legend>Rendezvény</legend><div class="ct-g">' + inp('venue', 'Helyszín', st.venue, { cls: 'w2' }) + inp('date', 'Dátum', st.date, { type: 'date' }) + '<div class="ct-g2">' + inp('start', 'Kezdés', st.start, { ph: '18:00' }) + inp('end', 'Vége', st.end, { ph: '22:00' }) + '</div></div></fieldset>';
			h += '<fieldset><legend>Szolgáltatások</legend><p class="ct-hint">Pipáld be, ami a megrendelésben szerepel – több is lehet, ilyenkor egy közös szerződés készül.</p><div class="ct-svc">' +
				D.services.map(s => { const on = chosen[s.k]; return '<div class="ct-si' + (on ? ' on' : '') + '"><label class="ct-sn"><input type="checkbox" data-svc="' + s.k + '"' + (on ? ' checked' : '') + '> ' + esc(s.n) + '</label>' +
					(on ? '<div class="ct-sr"><label>Óra<input type="number" min="1" max="24" data-sv="' + s.k + '" data-f="hours" value="' + Number(on.hours) + '"></label><label>Díj (' + (st.issuer === 'EV' ? 'AAM bruttó' : 'nettó') + ', Ft)<input type="number" min="0" step="1" data-sv="' + s.k + '" data-f="price" value="' + Number(on.price) + '"></label>' +
						(s.hatter ? '<label class="w2">Háttér<input data-sv="' + s.k + '" data-f="bg" value="' + esc(on.bg || '') + '" placeholder="a választott háttér"></label>' : '') + '</div>' + (s.review && s.review.length ? '<p class="ct-warn">⚠ ' + esc(s.review.join(' ')) + '</p>' : '') : '') + '</div>'; }).join('') + '</div></fieldset>';
			h += '<fieldset><legend>Kiszállási díj</legend><div class="ct-g">' + inp('travel_city', 'Település', st.travel_city) + inp('travel', 'Díj (Ft, 0 = nincs)', st.travel, { type: 'number' }) + '</div></fieldset>';
			h += '<fieldset><legend>Fizetés</legend><div class="ct-seg">' + [['pre', 'előre utalással'], ['post', 'halasztott, 8 nap'], ['cash', 'helyszínen készpénzben']].map(o => '<label><input type="radio" name="ct-pay" value="' + o[0] + '"' + (st.pay === o[0] ? ' checked' : '') + '> ' + o[1] + '</label>').join('') + '</div></fieldset>';
			h += '<fieldset><legend>Keltezés és egyedi kikötés</legend><div class="ct-g">' + inp('place', 'Hely', st.place) + inp('cdate', 'Dátum', st.cdate, { type: 'date' }) + '<label class="w2">Egyedi kikötés (nem kötelező, az Egyéb rendelkezések végére kerül)<textarea data-k="note" rows="3">' + esc(st.note || '') + '</textarea></label></div></fieldset>';
			F.innerHTML = h;
			F.querySelectorAll('[data-k]').forEach(el => el.addEventListener('input', () => { const p = el.dataset.k.split('.'); if (p.length === 2) st[p[0]][p[1]] = el.value; else st[p[0]] = el.type === 'number' ? Number(el.value) : el.value; change(); }));
			F.querySelectorAll('input[name="ct-lang"]').forEach(el => el.addEventListener('change', () => { st.lang = el.value; draw(); change(); }));
			F.querySelectorAll('input[name="ct-iss"]').forEach(el => el.addEventListener('change', () => { st.issuer = el.value; draw(); change(); }));
			F.querySelectorAll('input[name="ct-ct"]').forEach(el => el.addEventListener('change', () => { st.client.type = el.value; draw(); change(); }));
			F.querySelectorAll('input[name="ct-pay"]').forEach(el => el.addEventListener('change', () => { st.pay = el.value; change(); }));
			F.querySelectorAll('[data-svc]').forEach(el => el.addEventListener('change', () => {
				const k = el.dataset.svc;
				if (el.checked) st.svc.push({ k, hours: SV[k].hours, price: 0, bg: '' }); else st.svc = st.svc.filter(s => s.k !== k);
				draw(); change();
			}));
			F.querySelectorAll('[data-sv]').forEach(el => el.addEventListener('input', () => { const s = st.svc.find(z => z.k === el.dataset.sv); if (!s) return; s[el.dataset.f] = el.dataset.f === 'bg' ? el.value : Number(el.value); change(); }));
		}
		function change() { dirty = true; clearTimeout(timer); timer = setTimeout(refresh, 450); }
		async function refresh() {
			const my = ++req;
			try { const r = await post('cp_ct_preview', { st: JSON.stringify(st), save: 1 }); if (my !== req) return; PV.innerHTML = r.html; w.querySelector('[data-st]').textContent = 'Automatikusan mentve'; dirty = false; }
			catch (e) { if (my === req) PV.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; }
		}
		const file = fmt => {
			if (!st.svc.length) { toast('Válassz legalább egy szolgáltatást.', true); return; }
			const f = document.createElement('form'); f.method = 'post'; f.action = C.ajax; f.target = fmt === 'docx' ? 'ct-dl' : '_blank';
			[['action', 'cp_ct_file'], ['t', C.t], ['st', JSON.stringify(st)], ['fmt', fmt]].forEach(p => { const i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i); });
			let fr = document.querySelector('iframe[name="ct-dl"]'); if (!fr) { fr = document.createElement('iframe'); fr.name = 'ct-dl'; fr.hidden = true; document.body.appendChild(fr); }
			document.body.appendChild(f); f.submit(); f.remove(); dirty = false;
			if (fmt === 'docx') toast('A Word-fájl letöltése elindult.');
		};
		w.querySelector('[data-docx]').addEventListener('click', () => file('docx'));
		w.querySelector('[data-pdf]').addEventListener('click', () => file('html'));
		draw(); refresh();
	}
	window.CPContract = { slot, mount, open };

	const css = document.createElement('style');
	css.textContent = '.ct-mod{position:fixed;inset:0;z-index:72;background:rgba(20,24,33,.5);display:flex;align-items:center;justify-content:center}' +
		'.ct-card{background:#F6F4F0;border-radius:16px;width:min(1400px,calc(100vw - 24px));height:calc(100vh - 32px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 48px -16px rgba(0,0,0,.5)}' +
		'.ct-card header{display:flex;gap:12px;align-items:center;padding:12px 16px;background:#fff;border-bottom:1px solid #E6E3DE}.ct-card h3{margin:0;font:800 17px "Plus Jakarta Sans",Inter,sans-serif;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
		'.ct-st{font-size:12px;color:#6B6F78}.ct-x{border:0;background:#F3F1ED;border-radius:50%;width:34px;height:34px;cursor:pointer;flex:none}.ct-tabs{display:none}' +
		'.ct-body{flex:1;display:grid;grid-template-columns:minmax(360px,460px) 1fr;min-height:0}.ct-f{overflow:auto;padding:12px 14px 30px;border-right:1px solid #E6E3DE}.ct-p{overflow:auto;padding:16px;background:#E9E6E1}.ct-p .ct-doc{box-shadow:0 6px 20px -10px rgba(0,0,0,.35)}' +
		'.ct-f fieldset{border:0;background:#fff;border-radius:12px;margin:0 0 10px;padding:10px 12px}.ct-f legend{font:800 13px Inter,sans-serif;color:#141C33;padding:0;margin-bottom:6px;float:left;width:100%}' +
		'.ct-g{display:grid;grid-template-columns:1fr 1fr;gap:8px;clear:both}.ct-g2{display:grid;grid-template-columns:1fr 1fr;gap:8px}.ct-g .w2,.ct-sr .w2{grid-column:span 2}.ct-g label,.ct-sr label{display:flex;flex-direction:column;gap:3px;font-size:12px;color:#6B6F78;min-width:0}' +
		'.ct-f input,.ct-f textarea{font:14px Inter,sans-serif;border:1px solid #DDD9D2;border-radius:8px;padding:7px 8px;min-width:0;width:100%;box-sizing:border-box;color:#141C33;background:#fff}.ct-f input[type=checkbox],.ct-f input[type=radio]{width:auto;padding:0;margin:0;flex:none}' +
		'.ct-seg{display:flex;flex-wrap:wrap;gap:6px 14px;clear:both;font-size:14px}.ct-seg label{display:flex;gap:6px;align-items:center;cursor:pointer}.ct-hint{margin:6px 0 0;font-size:12px;color:#8A8F99;clear:both}' +
		'.ct-svc{clear:both;display:flex;flex-direction:column;gap:4px;margin-top:6px}.ct-si{border:1px solid #EEEAE4;border-radius:10px;padding:6px 8px}.ct-si.on{border-color:#F0A46A;background:#FFF8F1}.ct-sn{display:flex;gap:8px;align-items:center;font-size:14px;cursor:pointer}' +
		'.ct-sr{display:grid;grid-template-columns:90px 1fr;gap:8px;margin:6px 0 2px 24px}.ct-warn{margin:6px 0 0 24px;font-size:12px;color:#8A6400}' +
		'.ct-card footer{display:flex;gap:8px;align-items:center;padding:10px 16px;background:#fff;border-top:1px solid #E6E3DE}.ct-sp{flex:1}' +
		'@media (max-width:860px){.ct-card{width:100vw;height:100vh;border-radius:0}.ct-tabs{display:inline-flex;border:1px solid #DDD9D2;border-radius:10px;overflow:hidden}.ct-tabs button{border:0;background:#fff;padding:6px 12px;font:600 13px Inter,sans-serif}.ct-tabs button.on{background:#141C33;color:#fff}' +
		'.ct-st{display:none}.ct-body{grid-template-columns:1fr}.ct-p{display:none;padding:8px}.ct-mod.show-p .ct-p{display:block}.ct-mod.show-p .ct-f{display:none}.ct-card h3{font-size:15px}.ct-p .ct-doc{padding:16px}}';
	document.head.appendChild(css);
})();
