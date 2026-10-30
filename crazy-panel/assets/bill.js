/* =====================================================================
 * Számlázás kártya a deal adatlapján (v1.73) – Billingo
 * Kft.: Billingo, egyéni vállalkozó: Számlázz.hu (v1.73.1, előnézettel). Számla / díjbekérő / előlegszámla / végszámla (előleg beszámításával): előre kitöltött űrlap, átnézés, kiállítás
 * gombnyomásra. Fotós szolgáltatásnál a kiküldés a galériával együtt (Követés fül, „Galéria” pipa).
 * ===================================================================== */
(function () {
	'use strict';
	const C = window.CPX || {};
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const ft = v => Math.round(Number(v) || 0).toLocaleString('hu-HU').replace(/\s/g, ' ') + ' Ft';
	const TYPES = { invoice: 'Számla', proforma: 'Díjbekérő', advance: 'Előlegszámla', final: 'Végszámla' };
	const PAY = { paid: ['fizetve', 'ok'], outstanding: ['nyitott', ''], expired: ['lejárt', 'bad'], partially_paid: ['részben fizetve', ''], none: ['', ''] };
	const VATS = ['27%', '18%', '5%', '0%', 'AAM', 'TAM', 'EU', 'EUK'];
	const RATE = v => { const m = /^(\d+)%$/.exec(v); return m ? Number(m[1]) / 100 : 0; };
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
		d.textContent = m; d.className = 'cpx-toast on' + (bad ? ' err' : ''); clearTimeout(d._t); d._t = setTimeout(() => { d.className = 'cpx-toast'; }, 5000);
	}

	const slot = () => (C.bil && C.bil.on ? '<section class="d3-c d3-bill" data-bill><h4>Számlázás</h4><p class="cpx-muted">Betöltés…</p></section>' : '');
	async function mount(sh, x) {
		const box = sh.querySelector('[data-bill]');
		if (!box) return;
		const id = x.deal.id;
		let B;
		try { B = await post('cp_bil_deal', { id }); } catch (e) { box.innerHTML = '<h4>Számlázás</h4><p class="cpx-muted">' + esc(e.message) + '</p>'; return; }
		draw(box, id, B);
	}
	function draw(box, id, B) {
		const anyOk = Object.keys(B.profiles).some(k => B.profiles[k].ok);
		const adv = B.docs.filter(d => d.type === 'advance');
		let h = '<h4>Számlázás' + (B.photo ? ' <span class="d3-q">galériával</span>' : '') + '</h4>';
		if (!anyOk) h += '<p class="d3-sub">A Billingo még nincs bekötve – admin: WordPress → Beállítások → Crazy Billingo.</p>';
		if (B.docs.length) {
			h += '<ul class="bl-docs">' + B.docs.map(d => {
				const p = PAY[d.pay] || ['', ''];
				const szl = (B.profiles[d.profile] || {}).prov === 'szamlazz', pend = Number(d.pending) === 1;
				const st = d.sent_at ? 'kiküldve' : (pend ? '⏳ előkészítve – a galéria kiküldésekor állítja ki és küldi el' : (d.send_mode === 'gallery' ? '⏳ a galériával megy ki' : 'nincs kiküldve'));
				return '<li><div><b>' + esc(TYPES[d.type] || d.type) + '</b> ' + esc(d.number || '') + ' · ' + ft(d.gross) + (p[0] ? ' <span class="bl-pay ' + p[1] + '">' + p[0] + '</span>' : '') +
					'<small>' + esc(d.profile) + ' · ' + esc(d.at || '') + (d.created_by ? ' · ' + esc(d.created_by) : '') + ' · ' + esc(st) + (d.err ? ' · <span class="bl-err">' + esc(d.err) + '</span>' : '') + '</small></div>' +
					'<div class="bl-da">' + (pend ? '' : '<button type="button" class="cpx-btn ghost" data-bopen="' + d.id + '">Megnyitás</button>') + (!d.sent_at && (!szl || pend) ? '<button type="button" class="cpx-btn ghost" data-bsend="' + d.id + '">' + (pend ? 'Kiállítás és küldés most' : 'Küldés most') + '</button>' : '') + '</div></li>';
			}).join('') + '</ul>';
		}
		if (anyOk) h += '<div class="d3-pact">' + ['invoice', 'advance', 'proforma'].map(t => '<button type="button" class="cpx-btn' + (t === 'invoice' ? '' : ' ghost') + '" data-bnew="' + t + '">' + TYPES[t] + '</button>').join('') +
			(adv.length ? '<button type="button" class="cpx-btn" data-bnew="final">Végszámla (előleg beszámításával)</button>' : '') + '</div>';
		box.innerHTML = h;
		box.querySelectorAll('[data-bnew]').forEach(b => b.addEventListener('click', () => form(box, id, B, b.dataset.bnew)));
		box.querySelectorAll('[data-bsend]').forEach(b => b.addEventListener('click', async () => {
			const d = B.docs.find(z => String(z.id) === b.dataset.bsend);
			if (!window.confirm((Number(d.pending) === 1 ? 'Kiállítod és elküldöd most a Számlázz.hu-n?' : 'Elküldöd most?') + '\n\n' + (TYPES[d.type] || '') + ' ' + (d.number || '') + ' → ' + (d.emails || ''))) return;
			b.disabled = true;
			try { const r = await post('cp_bil_act', { row: d.id, op: 'send' }); toast(r.msg); B.docs = r.docs.map(fix); draw(box, id, B); } catch (e) { b.disabled = false; toast(e.message, true); }
		}));
		box.querySelectorAll('[data-bopen]').forEach(b => b.addEventListener('click', async () => {
			const w = window.open('', '_blank');
			try { const r = await post('cp_bil_act', { row: b.dataset.bopen, op: 'url' }); if (w && r.url) w.location = r.url; else if (r.url) window.location.href = r.url; } catch (e) { if (w) w.close(); toast(e.message, true); }
		}));
	}
	const fix = d => Object.assign({}, d, { id: Number(d.id), gross: Number(d.gross), at: d.at || (d.created_at || '').slice(0, 16) });

	/* ------------------------------------------------------------ kiállító űrlap */
	function form(box, id, B, kind) {
		const type = kind === 'final' ? 'invoice' : kind;
		let prof = B.profiles[B.profile] && B.profiles[B.profile].ok ? B.profile : Object.keys(B.profiles).find(k => B.profiles[k].ok);
		const vat0 = () => B.profiles[prof].vat;
		let items = B.items.map(i => Object.assign({ ptype: 'net' }, i, { vat: vat0() }));
		if (kind === 'advance') { const tot = items.reduce((s, i) => s + i.qty * i.price, 0); items = [{ name: 'Előleg – ' + (items[0] ? items[0].name : 'rendezvényszolgáltatás'), qty: 1, unit: 'alkalom', price: Math.round(tot / 2), ptype: 'net', vat: vat0(), comment: items[0] ? items[0].comment : '' }]; }
		const b = Object.assign({}, B.buyer);
		const addDays = (s, n) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };
		const ful = type === 'invoice' && B.event_d && B.event_d <= B.today ? B.event_d : B.today;
		const advs = B.docs.filter(d => d.type === 'advance' && d.profile === prof);
		const w = document.createElement('div');
		w.className = 'bl-mod';
		const srcTxt = b.src === 'portal' ? 'az ügyfélportálról (az ügyfél adta meg)' : 'a dealből / kontaktból – ellenőrizd, a portálon még nincs kitöltve';
		w.innerHTML = '<div class="bl-bg" data-x></div><div class="bl-card" role="dialog" aria-modal="true"><header><h3>' + esc(TYPES[kind]) + ' készítése</h3><button type="button" class="bl-x" data-x aria-label="Bezárás">✕</button></header><div class="bl-body">' +
			'<label class="bl-row">Kiállító <select data-f="prof">' + Object.keys(B.profiles).map(k => '<option value="' + k + '"' + (k === prof ? ' selected' : '') + (B.profiles[k].ok ? '' : ' disabled') + '>' + esc(B.profiles[k].n) + (B.profiles[k].ok ? '' : ' – nincs bekötve') + '</option>').join('') + '</select><small>a dealen: ' + esc(B.ctype || 'nincs megadva') + '</small></label>' +
			'<h5>Vevő <small>' + esc(srcTxt) + '</small></h5><div class="bl-g">' +
			'<label class="w2">Név / cégnév<input data-b="name" value="' + esc(b.name) + '"></label><label>Adószám<input data-b="taxcode" value="' + esc(b.taxcode) + '" placeholder="magánszemélynél üres"></label>' +
			'<label>Irányítószám<input data-b="post_code" value="' + esc(b.post_code) + '" inputmode="numeric"></label><label>Város<input data-b="city" value="' + esc(b.city) + '"></label><label class="w2">Cím (utca, házszám)<input data-b="address" value="' + esc(b.address) + '"></label>' +
			'<label class="w2">E-mail (ide megy a számla)<input data-b="emails" value="' + esc(b.emails) + '"></label>' + (b.po ? '<label>Megrendelésszám<input data-b="po" value="' + esc(b.po) + '"></label>' : '') + '</div>' +
			(b.note ? '<p class="bl-note">Az ügyfél megjegyzése a számlázáshoz: <b>' + esc(b.note) + '</b></p>' : '') +
			'<h5>Tételek</h5><div class="bl-items" data-items></div><button type="button" class="cpx-btn ghost" data-add>+ Tétel</button><div class="bl-sum" data-sum></div>' +
			(kind === 'final' ? '<h5>Beszámított előleg</h5><div data-advs>' + (advs.length ? advs.map(a => '<label class="bl-chk"><input type="checkbox" value="' + a.id + '" checked> ' + esc(a.number || 'előlegszámla') + ' · ' + ft(a.gross) + '</label>').join('') : '<p class="cpx-muted">Ennél a kiállítónál nincs előlegszámla.</p>') + '</div>' : '') +
			'<div class="bl-g"><label>Teljesítés<input type="date" data-f="ful" value="' + ful + '"></label><label>Fizetési határidő<input type="date" data-f="due" value="' + addDays(B.today, B.profiles[prof].due) + '"></label>' +
			'<label>Fizetési mód<select data-f="pay"><option value="wire_transfer">átutalás</option><option value="cash">készpénz</option><option value="bankcard">bankkártya</option><option value="elore_utalas">előre utalás</option></select></label></div>' +
			'<label class="bl-row w">Megjegyzés a számlán<textarea data-f="comment" rows="2"></textarea></label>' +
			'<h5>Kiküldés</h5><div class="bl-send">' + [['now', 'Kiállítás után azonnal elküldöm'], ['gallery', 'A galériával együtt (a Követés fül „Galéria” pipájára)' + (B.gallery ? ' – a galéria már ki van küldve, ezért azonnal megy' : '')], ['no', 'Nem küldöm ki (csak kiállítom)']].map(o => '<label class="bl-chk"><input type="radio" name="bl-send" value="' + o[0] + '"' + ((B.photo && type === 'invoice' ? 'gallery' : 'now') === o[0] ? ' checked' : '') + '> ' + o[1] + '</label>').join('') + '</div>' +
			'<div class="bl-errbox" data-err hidden></div></div><footer><span class="cpx-muted" data-warn></span><button type="button" class="cpx-btn ghost" data-x>Mégse</button><button type="button" class="cpx-btn ghost" data-prev hidden>Előnézet (PDF)</button><button type="button" class="cpx-btn" data-go>Kiállítás a Billingóban</button></footer></div>';
		document.body.appendChild(w);
		const close = () => w.remove();
		w.querySelectorAll('[data-x]').forEach(x => x.addEventListener('click', close));
		const itBox = w.querySelector('[data-items]'), sumBox = w.querySelector('[data-sum]');
		const drawItems = () => {
			itBox.innerHTML = '<div class="bl-ih"><span>Megnevezés</span><span>Menny.</span><span>Egység</span><span>Egységár</span><span></span><span>ÁFA</span><span></span></div>' + items.map((i, n) => '<div class="bl-it" data-i="' + n + '"><input data-k="name" value="' + esc(i.name) + '" aria-label="Megnevezés"><input data-k="qty" type="number" step="0.5" min="0" value="' + i.qty + '" aria-label="Mennyiség"><input data-k="unit" value="' + esc(i.unit) + '" aria-label="Egység"><input data-k="price" type="number" step="1" min="0" value="' + i.price + '" aria-label="Egységár">' +
				'<select data-k="ptype" aria-label="Nettó/bruttó"><option value="net"' + (i.ptype !== 'gross' ? ' selected' : '') + '>nettó</option><option value="gross"' + (i.ptype === 'gross' ? ' selected' : '') + '>bruttó</option></select><select data-k="vat" aria-label="ÁFA">' + VATS.map(v => '<option' + (v === i.vat ? ' selected' : '') + '>' + v + '</option>').join('') + '</select><button type="button" class="bl-del" data-del="' + n + '" aria-label="Tétel törlése">✕</button>' +
				'<input data-k="comment" class="bl-ic" value="' + esc(i.comment || '') + '" placeholder="megjegyzés a tételhez (nem kötelező)" aria-label="Megjegyzés"></div>').join('');
			itBox.querySelectorAll('[data-i] [data-k]').forEach(el => el.addEventListener('input', () => { const n = Number(el.closest('[data-i]').dataset.i), k = el.dataset.k; items[n][k] = (k === 'qty' || k === 'price') ? Number(el.value) : el.value; sum(); }));
			itBox.querySelectorAll('[data-del]').forEach(el => el.addEventListener('click', () => { items.splice(Number(el.dataset.del), 1); drawItems(); }));
			sum();
		};
		const sum = () => {
			let net = 0, gross = 0;
			items.forEach(i => { const v = i.qty * i.price, r = RATE(i.vat); if (i.ptype === 'gross') { gross += v; net += v / (1 + r); } else { net += v; gross += v * (1 + r); } });
			sumBox.innerHTML = 'Nettó: <b>' + ft(net) + '</b> · Bruttó: <b>' + ft(gross) + '</b>' + (kind === 'final' ? ' <small>(' + (B.profiles[prof].prov === 'szamlazz' ? 'a teljes összeget add meg; a Számlázz.hu az előlegszámla alapján vonja le – nézd meg az Előnézetben' : 'a beszámított előleget a Billingo vonja le') + ')</small>' : '');
		};
		drawItems();
		const provUi = () => { const z = B.profiles[prof].prov === 'szamlazz'; w.querySelector('[data-prev]').hidden = !z; w.querySelector('[data-warn]').textContent = (z ? 'A Számlázz.hu-n' : 'A Billingóban') + ' élesben kiállítja – visszavonni csak sztornóval lehet.'; w.querySelector('[data-go]').textContent = z ? 'Kiállítás a Számlázz.hu-n' : 'Kiállítás a Billingóban'; };
		provUi();
		const collect = () => { w.querySelectorAll('[data-b]').forEach(el => { b[el.dataset.b] = el.value.trim(); }); const f = k => w.querySelector('[data-f="' + k + '"]').value; return { id, profile: prof, type, buyer: JSON.stringify(b), items: JSON.stringify(items), fulfillment: f('ful'), due: f('due'), pay: f('pay'), comment: f('comment'), send: (w.querySelector('input[name="bl-send"]:checked') || {}).value || 'now', advance: JSON.stringify(kind === 'final' ? Array.from(w.querySelectorAll('[data-advs] input:checked')).map(x => Number(x.value)) : []) }; };
		w.querySelector('[data-prev]').addEventListener('click', async ev => {
			const btn = ev.currentTarget, win = window.open('', '_blank');
			btn.disabled = true;
			try { const r = await post('cp_bil_issue', Object.assign(collect(), { preview: 1 })); const bin = atob(r.pdf), u8 = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i); const url = URL.createObjectURL(new Blob([u8], { type: 'application/pdf' })); if (win) win.location = url; else window.open(url, '_blank'); }
			catch (e) { if (win) win.close(); const eb = w.querySelector('[data-err]'); eb.textContent = e.message; eb.hidden = false; toast(e.message, true); }
			btn.disabled = false;
		});
		w.querySelector('[data-add]').addEventListener('click', () => { items.push({ name: '', qty: 1, unit: 'db', price: 0, ptype: 'net', vat: vat0(), comment: '' }); drawItems(); });
		w.querySelector('[data-f="prof"]').addEventListener('change', e => { prof = e.target.value; items.forEach(i => { i.vat = vat0(); }); drawItems(); provUi(); });
		w.querySelector('[data-go]').addEventListener('click', async ev => {
			const btn = ev.currentTarget, P = collect(), send = P.send, advSel = JSON.parse(P.advance), zz = B.profiles[prof].prov === 'szamlazz';
			const errBox = w.querySelector('[data-err]'), fail = m => { errBox.textContent = m; errBox.hidden = false; errBox.scrollIntoView({ block: 'nearest' }); };
			errBox.hidden = true;
			itBox.querySelectorAll('.bad').forEach(x => x.classList.remove('bad'));
			const badI = items.findIndex(i => !String(i.name).trim() || !(Number(i.qty) > 0) || !(Number(i.price) > 0));
			if (badI >= 0) { const row = itBox.querySelector('[data-i="' + badI + '"]'); if (row) ['name', 'qty', 'price'].forEach(k => { const el = row.querySelector('[data-k="' + k + '"]'); const v = items[badI][k]; if (k === 'name' ? !String(v).trim() : !(Number(v) > 0)) el.classList.add('bad'); }); fail('A(z) ' + (badI + 1) + '. tételnél hiányzik a megnevezés, a mennyiség vagy az egységár (0 Ft nem lehet). A dealnek nincs értéke, ezért írd be az árat.'); return; }
			if (!b.name || !b.post_code || !b.city || !b.address) { fail('A vevő neve és teljes címe (irányítószám, város, utca) kötelező.'); return; }
			if (send !== 'no' && !/@/.test(b.emails)) { fail('A kiküldéshez kell a vevő e-mail-címe – vagy válaszd: „Nem küldöm ki”.'); return; }
			if (zz && advSel.length > 1) { toast('A Számlázz.hu végszámlája egy előlegszámlára hivatkozhat – csak egyet jelölj be.', true); return; }
			if (kind === 'final' && !advSel.length && !window.confirm('Nincs kijelölve beszámítandó előleg – sima számlaként állítod ki?')) return;
			const tot = sumBox.textContent;
			if (!window.confirm(TYPES[kind] + ' kiállítása ' + (zz ? 'a Számlázz.hu-n' : 'a Billingóban') + ' (' + B.profiles[prof].n + ')\n\nVevő: ' + b.name + (b.taxcode ? ' (' + b.taxcode + ')' : '') + '\n' + tot + '\nKiküldés: ' + ({ now: 'azonnal → ' + b.emails, gallery: zz ? 'a galéria kiküldésekor állítja ki és küldi el' : 'a galériával együtt', no: 'nem megy ki' }[send]) + '\n\nMehet?')) return;
			btn.disabled = true; btn.textContent = 'Kiállítás…';
			try {
				const r = await post('cp_bil_issue', P);
				toast(r.msg); close(); B.docs = r.docs.map(fix); draw(box, id, B);
			} catch (e) { btn.disabled = false; provUi(); fail(e.message); toast(e.message, true); }
		});
	}
	window.CPBill = { slot, mount };

	const css = document.createElement('style');
	css.textContent = '.bl-docs{list-style:none;margin:0 0 10px;padding:0}.bl-docs li{display:flex;gap:8px;align-items:flex-start;justify-content:space-between;border-top:1px solid #EEEAE4;padding:8px 0;font-size:13.5px}.bl-docs small{display:block;color:#6B6F78;font-size:12px}.bl-da{display:flex;gap:4px;flex:none}.bl-da .cpx-btn{padding:4px 8px;font-size:12px}' +
		'.bl-pay{display:inline-block;border-radius:10px;padding:0 7px;font-size:11.5px;background:#EEF0F4}.bl-pay.ok{background:#DDF3E4;color:#1D6B3B}.bl-pay.bad{background:#FDECEA;color:#9B2C1F}.bl-err{color:#B3261E}' +
		'.bl-mod{position:fixed;inset:0;z-index:70;display:flex;align-items:center;justify-content:center}.bl-bg{position:absolute;inset:0;background:rgba(20,24,33,.45)}' +
		'.bl-card{position:relative;background:#fff;border-radius:18px;width:min(860px,calc(100vw - 24px));max-height:calc(100vh - 32px);display:flex;flex-direction:column;box-shadow:0 24px 48px -16px rgba(0,0,0,.45)}' +
		'.bl-card header{display:flex;align-items:center;padding:14px 18px;border-bottom:1px solid #EEEAE4}.bl-card h3{margin:0;flex:1;font:700 17px Inter,sans-serif}.bl-x{border:0;background:#F3F1ED;border-radius:50%;width:34px;height:34px;cursor:pointer}' +
		'.bl-body{padding:12px 18px;overflow:auto;font-size:14px}.bl-body h5{margin:14px 0 6px;font:700 13px Inter,sans-serif;text-transform:uppercase;letter-spacing:.03em;color:#4A5163}.bl-body h5 small{text-transform:none;letter-spacing:0;font-weight:500;color:#8A6D1F;margin-left:6px}' +
		'.bl-g{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.bl-g label,.bl-row{display:flex;flex-direction:column;gap:3px;font-size:12px;color:#6B6F78}.bl-g .w2{grid-column:span 2}.bl-g>*{min-width:0}.bl-g input,.bl-g select,.bl-row select,.bl-row textarea{width:100%;box-sizing:border-box}.bl-row{margin:4px 0}.bl-row small{color:#8A8F99}' +
		'.bl-body input,.bl-body select,.bl-body textarea{font:14px Inter,sans-serif;border:1px solid #DDD9D2;border-radius:8px;padding:7px 8px;min-width:0;color:#141C33;background:#fff}' +
		'.bl-ih,.bl-it{display:grid;grid-template-columns:minmax(0,3fr) 70px 80px 110px 84px 76px 30px;gap:6px;align-items:center}.bl-ih{font-size:11.5px;color:#8A8F99;padding:0 2px 2px}.bl-it{margin-bottom:8px}.bl-it .bl-ic{grid-column:1 / -2;font-size:12.5px;padding:5px 8px}' +
		'.bl-errbox{margin:12px 0 4px;background:#FDECEA;color:#9B2C1F;border:1px solid #F3B6AE;border-radius:10px;padding:10px 12px;font-weight:600}.bl-body input.bad{border-color:#D93025;background:#FFF5F4}.bl-del{border:0;background:#F3F1ED;border-radius:8px;height:32px;cursor:pointer}.bl-sum{margin:8px 0;font-size:14px}.bl-note{background:#FFF6E5;border-radius:10px;padding:8px 10px;margin:8px 0}' +
		'.bl-chk{display:flex;gap:8px;align-items:center;margin:4px 0;font-size:14px;color:#141C33}.bl-chk input{margin:0}' +
		'.bl-card footer{display:flex;gap:8px;align-items:center;justify-content:flex-end;padding:12px 18px;border-top:1px solid #EEEAE4}.bl-card footer span{margin-right:auto;font-size:12px}' +
		'@media (max-width:760px){.bl-mod{align-items:flex-end}.bl-card{width:100vw;max-height:92vh;border-radius:18px 18px 0 0}.bl-g{grid-template-columns:1fr 1fr}.bl-ih{display:none}.bl-it{grid-template-columns:1fr 1fr 1fr;border:1px solid #EEEAE4;border-radius:10px;padding:8px}.bl-it [data-k="name"],.bl-it .bl-ic{grid-column:1 / -1}.bl-card footer span{display:none}}';
	document.head.appendChild(css);
})();
