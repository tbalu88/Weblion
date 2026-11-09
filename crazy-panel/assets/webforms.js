/* Crazy panel – saját ajánlatkérő űrlap a Salesmate webforms.js helyén (v2.7).
 * Ugyanazt a felületet adja, mint a Salesmate szkriptje (SmFormSettings.loadForm, SalesmateFormsV1.getForms,
 * sm-form-event:on-ready), így az oldalak beágyazó kódja és az árkalkulátor változtatás nélkül működik.
 * Ha az űrlap a panelben nincs élesítve (vagy a panel nem érhető el), az eredeti Salesmate-űrlapot tölti be. */
(function () {
	if (window.__cpWF) { return; }
	window.__cpWF = 1;
	var AJ = 'https://eventcrazy.hu/wp-admin/admin-ajax.php';
	var SM = 'https://studioframes.salesmate.io/webforms.js';
	var TEST = (function () { try { var m = /[?&]cpwftest=([A-Za-z0-9]+)/.exec(location.search); if (m) { sessionStorage.setItem('cpwftest', m[1]); return m[1]; } return sessionStorage.getItem('cpwftest') || ''; } catch (e) { return ''; } })();
	window.__salesmate_webform_iframes__ = window.__salesmate_webform_iframes__ || [];

	/* ---------------------------------------------------------------- eredeti Salesmate-űrlap (nincs élesítve) */
	var smState = 0, smQueue = [];
	function passthrough(W) {
		if (smState === 2 && window.SmFormSettings && window.SmFormSettings !== API) { try { window.SmFormSettings.loadForm(W); } catch (e) {} return; }
		smQueue.push(W);
		if (smState) { return; }
		smState = 1;
		if (window.SalesmateFormsV1 && window.SalesmateFormsV1.__cp) { try { delete window.SalesmateFormsV1; } catch (e) { window.SalesmateFormsV1 = undefined; } }
		var s = document.createElement('script');
		s.src = SM; s.async = true;
		s.onload = function () { smState = 2; var q = smQueue; smQueue = []; q.forEach(function (w) { try { window.SmFormSettings.loadForm(w); } catch (e) {} }); };
		(document.head || document.documentElement).appendChild(s);
	}

	/* ---------------------------------------------------------------- UTM: az érkezéskori értékek */
	var UTM = (function () {
		var o = {}, keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
		try { var st = JSON.parse(sessionStorage.getItem('cp_utm') || '{}'); o = st || {}; } catch (e) {}
		try { var p = new URLSearchParams(location.search), any = false; keys.forEach(function (k) { if (p.get(k)) { any = true; } }); if (any) { o = {}; keys.forEach(function (k) { if (p.get(k)) { o[k] = p.get(k).slice(0, 200); } }); sessionStorage.setItem('cp_utm', JSON.stringify(o)); } } catch (e) {}
		return o;
	})();
	var UTMF = { 'Contact.utmSource': 'utm_source', 'Contact.utmMedium': 'utm_medium', 'Contact.utmCampaign': 'utm_campaign', 'Contact.utmTerm': 'utm_term', 'Contact.utmContent': 'utm_content' };

	/* ---------------------------------------------------------------- a saját űrlap */
	var forms = [], seq = 0;
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function css() {
		if (document.getElementById('cpwf-css')) { return; }
		var st = document.createElement('style'); st.id = 'cpwf-css';
		st.textContent =
			'.cpwf{--bg:#fff;--bd:#DFE1E6;--lb:#505f79;--tx:#333;--ph:#9aa0ab;--in:#fff;--ac:#f57e23;--bt:#fff;--br:20px;--rq:#e5484d;--ln:#f57e23;box-sizing:border-box;max-width:600px;margin:0 auto;background:var(--bg);color:var(--tx);border-radius:16px;padding:24px;font:15px/1.45 inherit;text-align:left}' +
			'.cpwf *{box-sizing:border-box}.cpwf-f{margin:0 0 16px}.cpwf-l{display:block;font-weight:500;font-size:14px;color:var(--lb);margin:0 0 6px}.cpwf-l i{font-style:normal;color:var(--rq);margin-right:3px}' +
			'.cpwf input[type=text],.cpwf input[type=email],.cpwf input[type=tel],.cpwf input[type=number],.cpwf input[type=date],.cpwf select,.cpwf textarea{display:block;width:100%;min-height:44px;margin:0;padding:10px 14px;font:16px/1.3 inherit;color:var(--tx);background:var(--in);border:1px solid var(--bd);border-radius:10px;box-shadow:none;outline:none;-webkit-appearance:none;appearance:none}' +
			'.cpwf select{background-image:linear-gradient(45deg,transparent 50%,currentColor 50%),linear-gradient(135deg,currentColor 50%,transparent 50%);background-position:calc(100% - 20px) 50%,calc(100% - 15px) 50%;background-size:5px 5px;background-repeat:no-repeat;padding-right:36px}' +
			'.cpwf textarea{min-height:70px;resize:vertical}.cpwf textarea.cpwf-big{min-height:130px}.cpwf ::placeholder{color:var(--ph);opacity:1}' +
			'.cpwf input:focus,.cpwf select:focus,.cpwf textarea:focus{border-color:var(--ac);box-shadow:0 0 0 3px color-mix(in srgb,var(--ac) 25%,transparent)}' +
			'.cpwf-ch{display:flex;flex-wrap:wrap;gap:6px}.cpwf-ch label{display:inline-flex;align-items:center;gap:6px;cursor:pointer;padding:7px 12px;border:1px solid var(--bd);border-radius:999px;font-size:14px;line-height:1.2;background:var(--in);color:var(--tx);user-select:none}' +
			'.cpwf-ch input{position:absolute;opacity:0;width:1px;height:1px;pointer-events:none}.cpwf-ch label:has(input:checked){background:var(--ac);border-color:var(--ac);color:var(--bt)}.cpwf-ch label:has(input:focus-visible){box-shadow:0 0 0 3px color-mix(in srgb,var(--ac) 35%,transparent)}' +
			'.cpwf-pp{display:flex;gap:10px;align-items:flex-start;font-size:13px;margin:4px 0 18px;cursor:pointer}.cpwf-pp input{margin-top:3px;width:17px;height:17px;accent-color:var(--ac);flex:none}.cpwf-pp a{color:var(--ln)}.cpwf-pp div{display:inline}' +
			'.cpwf-e{display:none;color:var(--rq);font-size:13px;margin-top:4px}.cpwf-f.bad .cpwf-e,.cpwf-pp.bad+.cpwf-e{display:block}.cpwf-f.bad input,.cpwf-f.bad select,.cpwf-f.bad textarea{border-color:var(--rq)}' +
			'.cpwf-b{display:inline-block;border:0;cursor:pointer;padding:12px 40px;font:600 15px/1.2 inherit;color:var(--bt);background:var(--ac);border-radius:var(--br);transition:transform .15s,filter .15s}.cpwf-b:hover{filter:brightness(1.05);transform:translateY(-1px)}.cpwf-b[disabled]{opacity:.6;cursor:wait;transform:none}' +
			'.cpwf-m{margin:12px 0 0;font-size:14px;color:var(--rq)}.cpwf-ok{text-align:center;padding:30px 10px;font-size:16px}.cpwf-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}' +
			'.cpwf.ec{--bg:linear-gradient(160deg,rgba(20,16,90,.85) 0%,rgba(10,10,60,.92) 100%);--bd:rgba(120,150,255,.35);--lb:#fff;--tx:#fff;--ph:rgba(255,255,255,.45);--in:rgba(255,255,255,.06);--ac:#FFC83D;--bt:#0a0a3c;--br:12px;--rq:#FFC83D;--ln:#FFC83D;border:1px solid rgba(120,150,255,.45);box-shadow:0 0 30px rgba(80,110,255,.2);padding:32px;font-family:Poppins,sans-serif;border-radius:20px}' +
			'.cpwf.ec .cpwf-b{background:linear-gradient(135deg,#FFC83D 0%,#FFB915 100%);box-shadow:0 6px 20px rgba(255,185,21,.35)}.cpwf.ec .cpwf-e,.cpwf.ec .cpwf-m{color:#ffb4b4}.cpwf.ec .cpwf-f.bad input,.cpwf.ec .cpwf-f.bad select,.cpwf.ec .cpwf-f.bad textarea{border-color:#ff8a8a}.cpwf.ec select option{color:#222}.cpwf.ec input[type=date]{color-scheme:dark}.cpwf.ec .cpwf-pp{color:rgba(255,255,255,.75)}' +
			'.cpwf.spin{--ac:#1F62FF;--br:6px;--ln:#1F62FF}' +
			'@media (max-width:520px){.cpwf{padding:18px}.cpwf.ec{padding:22px 16px}.cpwf-b{width:100%}}';
		(document.head || document.documentElement).appendChild(st);
	}
	function T(en, hu) { return en ? hu[1] : hu[0]; }
	/* v2.9.58: a mai nap helyi idő szerint (a toISOString UTC-t ad – éjfél és 1–2 óra között még a tegnapot) */
	function today() { var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); }

	function render(W, el, d) {
		var f = d.form, en = f.lang === 'en', id = 'cpwf' + (++seq), inst = 'cpwf-' + seq;
		var h = '<form class="cpwf ' + esc(f.theme || 'cp') + '" novalidate autocomplete="on" id="' + id + '">';
		f.fields.forEach(function (x, i) {
			if (x.hid) { return; }
			var fid = id + '-' + i, req = x.req ? '<i>*</i>' : '';
			h += '<div class="cpwf-f" data-n="' + esc(x.n) + '"><label class="cpwf-l" for="' + fid + '">' + req + esc(x.l) + '</label>';
			if (x.t === 'multi') {
				h += '<div class="cpwf-ch" role="group" aria-label="' + esc(x.l) + '">' + (x.o || []).map(function (o, j) { return '<label><input type="checkbox" name="' + esc(x.n) + '" value="' + esc(o) + '"' + (j === 0 ? ' id="' + fid + '"' : '') + '>' + esc(o) + '</label>'; }).join('') + '</div>';
			} else if (x.t === 'select') {
				h += '<select id="' + fid + '" name="' + esc(x.n) + '"' + (x.req ? ' required' : '') + '><option value="">' + esc(x.ph || T(en, ['Válassz…', 'Choose…'])) + '</option>' + (x.o || []).map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select>';
			} else if ((x.t === 'textarea' && x.ui !== 'line') || x.ui === 'area') {
				/* v2.9.49: a megjelenés („ui”) független a mező típusától – a Megjegyzés nagy szövegdoboz, a Kuponkód egysoros */
				h += '<textarea id="' + fid + '" name="' + esc(x.n) + '" rows="' + (x.ui === 'area' ? 5 : 2) + '"' + (x.ui === 'area' ? ' class="cpwf-big"' : '') + (x.t !== 'textarea' ? ' maxlength="500"' : '') + ' placeholder="' + esc(x.ph) + '"' + (x.req ? ' required' : '') + '></textarea>';
			} else {
				var ty = x.t === 'int' ? 'number' : (x.t === 'email' ? 'email' : (x.t === 'tel' ? 'tel' : (x.t === 'date' ? 'date' : 'text')));
				var ac = x.n === 'Contact.name' ? ' autocomplete="name"' : (x.n === 'Contact.email' ? ' autocomplete="email"' : (x.n === 'Contact.mobile' ? ' autocomplete="tel"' : ''));
				/* v2.9.58: létszám / óraszám legalább 1; szövegmezők legfeljebb 255 karakter */
				var ex = ty === 'number' ? ' min="1" max="100000" step="1" inputmode="numeric"' : (ty === 'date' ? ' min="' + today() + '"' : ' maxlength="255"');
				h += '<input type="' + ty + '" id="' + fid + '" name="' + esc(x.n) + '" placeholder="' + esc(x.ph) + '"' + ac + ex + (x.req ? ' required' : '') + '>';
			}
			h += '<div class="cpwf-e"></div></div>';
		});
		h += '<div class="cpwf-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
		if (f.privacy) { h += '<label class="cpwf-pp"><input type="checkbox" name="_pp"><span>' + f.privacy.replace(/<\/?div[^>]*>/g, '') + '</span></label><div class="cpwf-e">' + T(en, ['Kérjük, fogadd el az adatkezelési nyilatkozatot.', 'Please accept the privacy statement.']) + '</div>'; }
		h += '<button type="submit" class="cpwf-b">' + esc(f.btn || T(en, ['Elküld', 'Submit'])) + '</button><p class="cpwf-m" hidden></p></form>';
		el.innerHTML = h;
		var form = el.querySelector('form');
		var obj = {
			formId: W.formId,
			getFormId: function () { return W.formId; },
			getInstanceId: function () { return inst; },
			getFormName: function () { return f.name; },
			setFieldValue: function (n, v) {
				var els = form.querySelectorAll('[name="' + n.replace(/"/g, '') + '"]');
				if (!els.length) { obj._hidden[n] = v; return; }
				if (els[0].type === 'checkbox') { var vs = Array.isArray(v) ? v : String(v).split(/\s*,\s*/); els.forEach(function (c) { c.checked = vs.indexOf(c.value) >= 0; }); return; }
				var e = els[0];
				if (e.tagName === 'SELECT' && !Array.prototype.some.call(e.options, function (o) { return o.value === String(v) || o.text === String(v); })) { return; }
				e.value = String(v);
				try { e.dispatchEvent(new Event('input', { bubbles: true })); } catch (x) {}
			},
			getFormFieldValues: function () { var v = values(); return Object.keys(v).map(function (k) { return { name: k, value: v[k] }; }); },
			_hidden: {}
		};
		function values() {
			var v = {};
			f.fields.forEach(function (x) {
				if (x.hid) {
					if (UTMF[x.n]) { if (UTM[UTMF[x.n]]) { v[x.n] = UTM[UTMF[x.n]]; } }
					else if (obj._hidden[x.n] != null) { v[x.n] = obj._hidden[x.n]; }
					return;
				}
				if (x.t === 'multi') { var a = []; form.querySelectorAll('[name="' + x.n + '"]:checked').forEach(function (c) { a.push(c.value); }); v[x.n] = a; return; }
				var e = form.querySelector('[name="' + x.n + '"]'); v[x.n] = e ? e.value.trim() : '';
			});
			return v;
		}
		forms = forms.filter(function (o) { return document.contains(o._form); });
		obj._form = form; forms.push(obj);
		var t0 = Date.now(), busy = false, msg = form.querySelector('.cpwf-m');
		function mark(n, text) { var b = form.querySelector('.cpwf-f[data-n="' + n + '"]'); if (!b) { return; } b.classList.toggle('bad', !!text); b.querySelector('.cpwf-e').textContent = text || ''; }
		form.addEventListener('input', function () { if (!form.querySelector('.bad')) { msg.hidden = true; } });
		form.addEventListener('input', function (e) { var b = e.target.closest && e.target.closest('.cpwf-f'); if (b) { b.classList.remove('bad'); } if (e.target.name === '_pp') { e.target.closest('.cpwf-pp').classList.remove('bad'); } });
		form.addEventListener('change', function (e) { var b = e.target.closest && e.target.closest('.cpwf-f'); if (b) { b.classList.remove('bad'); } if (e.target.name === '_pp') { e.target.closest('.cpwf-pp').classList.remove('bad'); } });
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (busy) { return; }
			var v = values(), first = null, ok = true;
			f.fields.forEach(function (x) {
				if (x.hid) { return; }
				var val = v[x.n], bad = '';
				if (x.req && (Array.isArray(val) ? !val.length : val === '')) { bad = x.t === 'multi' ? T(en, ['Válassz legalább egyet.', 'Choose at least one.']) : T(en, ['Kötelező mező.', 'Required.']); }
				else if (x.t === 'email' && val && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(val)) { bad = T(en, ['Érvénytelen e-mail-cím.', 'Invalid email address.']); }
				else if (x.t === 'date' && val && val < today()) { bad = T(en, ['Jövőbeli dátumot adj meg.', 'Please choose a future date.']); }
				else if (x.t === 'int' && val !== '' && !(/^\d+$/.test(val) && +val >= 1 && +val <= 100000)) { bad = T(en, ['Adj meg egy 1 és 100 000 közötti egész számot.', 'Please enter a whole number between 1 and 100 000.']); }
				mark(x.n, bad);
				if (bad) { ok = false; if (!first) { first = form.querySelector('.cpwf-f[data-n="' + x.n + '"]'); } }
			});
			var pp = form.querySelector('[name=_pp]');
			if (pp && !pp.checked) { ok = false; pp.closest('.cpwf-pp').classList.add('bad'); if (!first) { first = pp.closest('.cpwf-pp'); } }
			if (!ok) { msg.hidden = false; msg.textContent = T(en, ['Kérjük, nézd át a jelölt mezőket.', 'Please check the highlighted fields.']); if (first && first.scrollIntoView) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); } return; }
			msg.hidden = true; busy = true;
			var btn = form.querySelector('.cpwf-b'), label = btn.textContent;
			btn.disabled = true; btn.textContent = T(en, ['Küldés…', 'Sending…']);
			var wait = Math.max(0, 3200 - (Date.now() - t0));
			setTimeout(function () {
				var b = new FormData();
				b.append('action', 'cp_wf_send'); b.append('fid', W.formId); b.append('tk', d.tk); b.append('v', JSON.stringify(v));
				b.append('page', location.href.slice(0, 250)); b.append('website', form.querySelector('[name=website]').value);
				if (d.test) { b.append('test', TEST); }
				fetch(AJ, { method: 'POST', body: b, credentials: 'omit' }).then(function (r) { return r.json().catch(function () { return { ok: 0 }; }); }).then(function (r) {
					if (r && r.ok) {
						if (r.test) { busy = false; btn.disabled = false; btn.textContent = label; msg.hidden = false; msg.textContent = 'Próba rendben – nem jött létre deal. Terv: ' + (r.plan ? r.plan.title : ''); window.__cpwfLast = r; return; }
						try { window.dispatchEvent(new CustomEvent('cp-form-submitted', { detail: { formId: W.formId } })); } catch (x) {}
						if (r.redirect) { location.href = r.redirect; return; }
						el.innerHTML = '<div class="cpwf ' + esc(f.theme || 'cp') + '"><div class="cpwf-ok">' + T(en, ['Köszönjük, megkaptuk! Hamarosan jelentkezünk.', 'Thank you, we received it! We will be in touch soon.']) + '</div></div>';
						return;
					}
					busy = false; btn.disabled = false; btn.textContent = label;
					if (r && r.fields) { Object.keys(r.fields).forEach(function (n) { mark(n, r.fields[n]); }); }
					if (r && r.err === 'tk') { t0 = Date.now() - 3300; }
					msg.hidden = false; msg.textContent = (r && r.msg) || T(en, ['Nem sikerült elküldeni – kérjük, próbáld újra, vagy hívj minket: +36 30 844 1791.', 'Could not send – please try again or call us: +36 30 844 1791.']);
				}).catch(function () {
					busy = false; btn.disabled = false; btn.textContent = label;
					msg.hidden = false; msg.textContent = T(en, ['Nem sikerült elküldeni (hálózati hiba) – kérjük, próbáld újra, vagy hívj minket: +36 30 844 1791.', 'Could not send (network error) – please try again or call us: +36 30 844 1791.']);
				});
			}, wait);
		});
		try { window.dispatchEvent(new CustomEvent('sm-form-event:on-ready', { detail: { formId: W.formId, instanceId: inst } })); } catch (x) {}
	}

	function target(W) {
		var el = W.divId ? document.getElementById(W.divId) : null;
		if (!el) {
			el = document.createElement('div');
			el.id = (W.formId || 'cpwf') + '-' + (++seq);
			var ref = W.loadElement && W.loadElement.parentNode ? W.loadElement : null;
			if (ref) { ref.parentNode.insertBefore(el, ref); } else { document.body.appendChild(el); }
			W.divId = el.id;
		}
		return el;
	}

	/* az árkalkulátor ezen át tölti ki az űrlapot – csak a saját űrlaphoz (a Salesmate-szkript a sajátját hozza létre) */
	var V1 = { __cp: 1, getForms: function () { return forms.filter(function (o) { return document.contains(o._form); }); }, _newInstanceId: function () { return 'cpwf-' + (++seq); }, _register: function () {} };
	function stubV1() { if (!window.SalesmateFormsV1) { window.SalesmateFormsV1 = V1; } }

	var API = {
		loadForm: function (W) {
			W = W || {};
			if (!W.formId) { return; }
			var done = false, el;
			var to = setTimeout(function () { if (!done) { done = true; passthrough(W); } }, 6000);
			fetch(AJ + '?action=cp_wf_def&id=' + encodeURIComponent(W.formId) + (TEST ? '&test=' + encodeURIComponent(TEST) : ''), { credentials: 'omit', cache: 'no-store' })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (done) { return; }
					done = true; clearTimeout(to);
					if (!d || !d.live || !d.form) { passthrough(W); return; }
					css(); el = target(W); stubV1(); render(W, el, d);
				})
				.catch(function () { if (!done) { done = true; clearTimeout(to); passthrough(W); } });
		}
	};
	window.SmFormSettings = API;
})();

/* v2.9.49: az eventcrazy.hu „Ajánlatlista” sávjának „Ajánlatot kérek →” gombja eddig a Salesmate-űrlap ablakának adta át a kiválasztott
 * szolgáltatásokat (iframe-cím paraméterrel). A panel űrlapjánál nincs ilyen ablak: itt a panel űrlapjában pipáljuk be őket, és odagörgetünk.
 * A csempe-nevek → űrlap-értékek megfeleltetése ugyanaz, mint az oldal saját szkriptjében. */
(function () {
	var MAP = {"Csocsó":"Csocsó / Foosball","XXL csocsó":"XXL csocsó / XXL Foosball","Foosball":"Csocsó / Foosball","XXL Foosball":"XXL csocsó / XXL Foosball","Rendezvényfotó":"Rendezvényfotó","Rendezvényvideó":"Rendezvény videó","Ülőfoci":"Ülőfoci / Seated Football","Szelfigép":"Szelfigép","Selfie Stand":"SelfieLight","AI Karikatúra":"AI Karikatúra","Greenbox fotózás":"Greenbox","Overhead Spinner":"OverheadSpinner","CrazySpinner":"SpinnerClassic","AI Fotózás":"AI fotózás","Karaoke":"Karaoke","Élő zenekar":"Élőzene","Digitális Karikatúra":"Digitális Karikatúra","VR élmény":"VR Élmény","VIP Spinner":"VIP Spinner","Selfie tükör":"Selfie Tükör","Selfiebot":"SzelfiRobot","LED Tánctér":"LED Tánctér","Brain War":"Mindflex","CrazyCatch":"CrazyCatch","Crazy DJ":"DJ","CrazyLight":"CrazyLight","Crazy Beauty":"Crazy Beauty","Rendezvény fotó és videó":"Rendezvényfotó,Rendezvény videó"};
	function fill(vals) {
		var box = document.querySelector('.cpwf');
		if (!box) { return false; }
		var cbs = box.querySelectorAll('input[type=checkbox][name="Deal.multiSelectCustomField3"]');
		for (var i = 0; i < cbs.length; i++) { if (vals.indexOf(cbs[i].value) > -1 && !cbs[i].checked) { cbs[i].checked = true; cbs[i].dispatchEvent(new Event('change', { bubbles: true })); } }
		window.scrollTo({ top: box.getBoundingClientRect().top + window.scrollY - 90, behavior: 'instant' });
		return true;
	}
	document.addEventListener('click', function (e) {
		var b = e.target && e.target.closest ? e.target.closest('.ec-go') : null;
		if (!b || document.querySelector('iframe[src*="salesmate.io/webforms"]')) { return; }
		var sel = []; try { sel = JSON.parse(localStorage.getItem('ecAjanlatlista') || '[]'); } catch (x) {}
		var vals = [];
		sel.forEach(function (n) { String(MAP[n] || n).split(',').forEach(function (v) { if (vals.indexOf(v) < 0) { vals.push(v); } }); });
		var bar = document.querySelector('.ec-bar'); if (bar) { bar.classList.remove('show'); }
		if (fill(vals)) { return; }
		/* az űrlap még nincs kirajzolva (lusta betöltés): betöltjük, és megvárjuk */
		if (window.cpLoadForm) { try { window.cpLoadForm(); } catch (x) {} }
		var n = 0, t = setInterval(function () { if (fill(vals) || ++n > 40) { clearInterval(t); } }, 150);
	}, true);
})();
