/* Crazy panel – értesítések (v1.36): csengő a menüben – megemlítések és kiosztások, kattintásra a kártyára / levélre visz */
(function () {
	'use strict';
	const C = window.CPX || {};
	if (!C.nt || !C.nt.on) return;
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const S = { n: 0, btn: null, pop: null, t: null };

	async function post(action, params) {
		const fd = new FormData();
		fd.append('action', action);
		fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		const j = await r.json();
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || 'Hiba');
		return j.data;
	}
	function ago(s) {
		if (!s) return '';
		const d = (Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 1000;
		if (d < 90) return 'most';
		if (d < 3600) return Math.round(d / 60) + ' perce';
		if (d < 86400) return Math.round(d / 3600) + ' órája';
		if (d < 172800) return 'tegnap';
		return String(s).slice(0, 10).replace(/-/g, '.') + '.';
	}
	const ICO = '<svg class="cpx-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2H4.5z"/><path d="M10 20.5a2 2 0 0 0 4 0"/></svg>';

	function badge() {
		if (!S.btn) return;
		const b = S.btn.querySelector('.cpx-bell-n');
		b.textContent = S.n > 99 ? '99+' : String(S.n);
		b.hidden = !S.n;
		S.btn.title = S.n ? S.n + ' olvasatlan értesítés' : 'Értesítések';
	}
	/* v2.7.9: olvasatlan levelek száma a Levelezés menüpont mellett (a menü újrarajzolása után is visszakerül) */
	function mailBadge() {
		document.querySelectorAll('.cpx-tabs [data-tab="mail"]').forEach(btn => {
			let n = btn.querySelector('.cpx-tab-n');
			if (!S.mail) { if (n) n.remove(); return; }
			if (!n) { n = document.createElement('b'); n.className = 'cpx-tab-n'; btn.appendChild(n); }
			n.textContent = S.mail > 99 ? '99+' : String(S.mail);
			btn.title = 'Levelezés – ' + S.mail + ' olvasatlan levél a Beérkezettben';
		});
	}
	new MutationObserver(() => { if (S.mail && !document.querySelector('.cpx-tabs [data-tab="mail"] .cpx-tab-n')) mailBadge(); }).observe(document.documentElement, { childList: true, subtree: true });
	async function poll() {
		try { const r = await post('cp_nt_list', { count: 1 }); S.n = r.unread || 0; badge(); if (r.mail != null) { S.mail = r.mail; mailBadge(); } } catch (e) { /* csendben újra */ }
	}

	/* a cél megnyitása: „feladatok:12”, „feladatok:mine”, „mail:3” */
	function go(link) {
		const i = String(link).indexOf(':'), tab = i < 0 ? link : link.slice(0, i), arg = i < 0 ? '' : link.slice(i + 1);
		if (tab === 'mail' && window.CPMail && window.CPMail.open) { window.CPMail.open(Number(arg)); return; }
		if (tab === 'munkanaplo' && window.CPWorklog) { window.CPWorklog.open(arg); return; }
		if (tab === 'deals' && window.CPCrm) { if (window.CPXGo) window.CPXGo('deals'); setTimeout(() => window.CPCrm.deep && window.CPCrm.deep(arg), 60); return; }
		if (tab === 'feladatok' && window.CPKanban) {
			if (window.CPXGo) window.CPXGo('feladatok');
			setTimeout(() => window.CPKanban.deep(arg), 60);
		}
	}

	function close() { if (S.pop) { S.pop.remove(); S.pop = null; } }
	async function open() {
		if (S.pop) { close(); return; }
		const pop = document.createElement('div');
		pop.className = 'cpx-ntpop';
		pop.innerHTML = '<div class="cpx-nth"><b>Értesítések</b><button type="button" data-all>Mind olvasott</button></div><div class="cpx-ntl"><div class="cpx-ntn">Betöltés…</div></div>';
		document.body.appendChild(pop);
		S.pop = pop;
		const r0 = S.btn.getBoundingClientRect();
		const w = Math.min(360, window.innerWidth - 16);
		pop.style.width = w + 'px';
		pop.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r0.right + 8 > window.innerWidth - w ? r0.right - w : r0.right + 8)) + 'px';
		pop.style.top = Math.max(8, Math.min(window.innerHeight - 440, r0.top - 8)) + 'px';
		setTimeout(() => document.addEventListener('click', function off(e) { if (!S.pop) { document.removeEventListener('click', off); return; } if (!pop.contains(e.target) && !S.btn.contains(e.target)) { close(); document.removeEventListener('click', off); } }), 0);
		pop.querySelector('[data-all]').addEventListener('click', async () => { try { const r = await post('cp_nt_read', { all: 1 }); S.n = r.unread; badge(); pop.querySelectorAll('.cpx-nti.new').forEach(x => x.classList.remove('new')); } catch (e) { /* később */ } });
		let x;
		try { x = await post('cp_nt_list', {}); } catch (e) { pop.querySelector('.cpx-ntl').innerHTML = '<div class="cpx-ntn">' + esc(e.message) + '</div>'; return; }
		S.n = x.unread; badge();
		const l = pop.querySelector('.cpx-ntl');
		if (x.off) { l.innerHTML = '<div class="cpx-ntn">Értesítéseket személyes fiókkal kapsz.</div>'; return; }
		l.innerHTML = x.rows.length ? x.rows.map(n => '<button type="button" class="cpx-nti' + (n.read ? '' : ' new') + '" data-id="' + n.id + '" data-link="' + esc(n.link) + '"><span class="cpx-ntk">' + (n.kind.indexOf('mail') === 0 ? '✉' : n.kind === 'lead' ? '★' : '☑') + '</span><span><b>' + esc(n.by_name || 'Valaki') + '</b> ' + esc(n.text) + '<small>' + esc(ago(n.created_at)) + '</small></span></button>').join('') :
			'<div class="cpx-ntn">Még nincs értesítésed. Ha valaki megemlít (@Neved) vagy rád oszt egy kártyát, levelet, itt látod.</div>';
		l.querySelectorAll('[data-id]').forEach(b => b.addEventListener('click', async () => {
			close();
			go(b.dataset.link);
			if (b.classList.contains('new')) { try { const r = await post('cp_nt_read', { id: b.dataset.id }); S.n = r.unread; badge(); } catch (e) { /* később */ } }
		}));
	}

	/* v2.7.6: külön oldal (#ertesitesek) a felugró ablak helyett */
	const V = { lim: 60, un: false, main: null };
	const kindIco = k => (String(k).indexOf('mail') === 0 ? '✉' : k === 'lead' ? '★' : k === 'deal_note' ? '💬' : '☑');
	function dayKey(s) {
		const d = new Date(String(s).replace(' ', 'T')), t = new Date(); t.setHours(0, 0, 0, 0);
		const x = new Date(d); x.setHours(0, 0, 0, 0);
		const diff = Math.round((t - x) / 86400000);
		if (diff === 0) return 'Ma';
		if (diff === 1) return 'Tegnap';
		return x.getFullYear() + '. ' + String(x.getMonth() + 1).padStart(2, '0') + '. ' + String(x.getDate()).padStart(2, '0') + '.';
	}
	const hm = s => String(s).slice(11, 16);
	async function view(main) {
		V.main = main || V.main;
		const m = V.main;
		if (!m) return;
		if (!m.querySelector('.ntp')) { m.innerHTML = '<div class="ntp"><div class="ntp-h"><h2>Értesítések</h2><div class="ntp-tools"></div></div><div class="ntp-dev" data-dev hidden></div><div class="ntp-l"><div class="cpx-ntn">Betöltés…</div></div></div>'; drawDev(m); }
		let x;
		try { x = await post('cp_nt_list', { lim: V.lim, un: V.un ? 1 : '' }); } catch (e) { m.querySelector('.ntp-l').innerHTML = '<div class="cpx-ntn">' + esc(e.message) + '</div>'; return; }
		if (!m.isConnected || !m.querySelector('.ntp')) return;
		S.n = x.unread || 0; badge();
		m.querySelector('.ntp-tools').innerHTML = '<div class="ntp-seg"><button type="button" data-f="0" class="' + (V.un ? '' : 'on') + '">Mind</button><button type="button" data-f="1" class="' + (V.un ? 'on' : '') + '">Olvasatlan' + (S.n ? ' <b>' + S.n + '</b>' : '') + '</button></div><button type="button" class="cpx-btn ghost" data-all' + (S.n ? '' : ' disabled') + '>✓ Mind olvasott</button>';
		const l = m.querySelector('.ntp-l');
		if (x.off) { l.innerHTML = '<div class="cpx-ntn">Értesítéseket személyes fiókkal kapsz.</div>'; return; }
		let last = '', h = '';
		(x.rows || []).forEach(n => {
			const k = dayKey(n.created_at);
			if (k !== last) { h += (last ? '</div>' : '') + '<div class="ntp-d">' + esc(k) + '</div><div class="ntp-g">'; last = k; }
			h += '<button type="button" class="ntp-i' + (n.read ? '' : ' new') + '" data-id="' + n.id + '" data-link="' + esc(n.link) + '"><span class="ntp-k">' + kindIco(n.kind) + '</span><span class="ntp-t"><b>' + esc(n.by_name || 'Valaki') + '</b> ' + esc(n.text) + '</span><span class="ntp-a">' + esc(hm(n.created_at)) + '</span></button>';
		});
		if (last) h += '</div>';
		l.innerHTML = h ? h + (x.more ? '<button type="button" class="ntp-more" data-more>Régebbi értesítések</button>' : '') : '<div class="cpx-ntn">' + (V.un ? 'Nincs olvasatlan értesítésed.' : 'Még nincs értesítésed. Ha valaki megemlít (@Neved), rád oszt egy kártyát vagy levelet, vagy új ajánlatkérés érkezik, itt látod.') + '</div>';
		m.querySelectorAll('[data-f]').forEach(b => b.addEventListener('click', () => { V.un = b.dataset.f === '1'; V.lim = 60; view(); }));
		const all = m.querySelector('[data-all]');
		if (all) all.addEventListener('click', async () => { try { const r = await post('cp_nt_read', { all: 1 }); S.n = r.unread; badge(); view(); } catch (e) { /* később */ } });
		const mb = m.querySelector('[data-more]');
		if (mb) mb.addEventListener('click', () => { mb.disabled = true; mb.textContent = 'Betöltés…'; V.lim += 100; view(); });
		l.querySelectorAll('[data-id]').forEach(b => b.addEventListener('click', async () => {
			if (b.classList.contains('new')) { b.classList.remove('new'); try { const r = await post('cp_nt_read', { id: b.dataset.id }); S.n = r.unread; badge(); } catch (e) { /* később */ } }
			go(b.dataset.link);
		}));
	}
	/* v2.8: telepíthető app + push-értesítés erre az eszközre */
	async function drawDev(m) {
		const el = m.querySelector('[data-dev]');
		if (!el || !window.CPPwa) return;
		let st; try { st = await window.CPPwa.state(); } catch (e) { return; }
		if (!st.sw) return;
		const B = (k, t, cls) => '<button type="button" class="cpx-btn ' + (cls || 'ghost') + ' sm" data-dv="' + k + '">' + t + '</button>';
		let h = '';
		if (st.ios && !st.standalone) {
			h = '<div><b>📲 Crazy panel app a telefonon</b><small>iPhone-on: Safariban a <b>Megosztás</b> gomb → <b>Főképernyőhöz adás</b>. Utána a kezdőképernyőn lévő ikonról nyisd meg, és itt bekapcsolhatod az értesítéseket.</small></div>';
		} else {
			const parts = [];
			if (st.install) parts.push(B('install', '📲 Telepítés', ''));
			if (st.canPush) {
				if (st.on) { parts.push(B('test', 'Próbaértesítés')); parts.push(B('off', 'Kikapcsolás')); }
				else if (st.perm === 'denied') { /* a gomb nem segít: magyarázat */ }
				else parts.push(B('on', '🔔 Bekapcsolás', ''));
			}
			const txt = !st.canPush ? 'Ez a böngésző nem tud push-értesítést fogadni.' : st.on ? 'Ezen az eszközön <b>bekapcsolva</b>: az értesítések akkor is megjönnek, ha a panel nincs nyitva.' : st.perm === 'denied' ? 'Az értesítések ezen az eszközön le vannak tiltva – a böngésző / telefon beállításaiban engedélyezheted a panelnek.' : 'Kapj értesítést erre az eszközre is (új ajánlatkérés, megemlítés, kiosztás), akkor is, ha a panel nincs nyitva.';
			h = '<div><b>🔔 Értesítések ezen az eszközön</b><small>' + txt + (st.install ? ' A panel telepíthető is: saját ikon, külön ablak.' : '') + '</small></div><span class="ntp-dvb">' + parts.join('') + '</span>';
		}
		el.innerHTML = h; el.hidden = false;
		el.querySelectorAll('[data-dv]').forEach(b => b.addEventListener('click', async () => {
			const k = b.dataset.dv; b.disabled = true;
			try {
				if (k === 'on') { await window.CPPwa.enable(); flash('Értesítések bekapcsolva ezen az eszközön.'); }
				if (k === 'off') { await window.CPPwa.disable(); flash('Értesítések kikapcsolva ezen az eszközön.'); }
				if (k === 'test') { const r = await window.CPPwa.test(); flash(r.ok ? 'Próbaértesítés elküldve (' + r.ok + ' eszközre).' : 'Nem sikerült elküldeni' + (r.err ? ': ' + r.err : '.')); }
				if (k === 'install') { await window.CPPwa.install(); }
			} catch (e) { flash(e.message); }
			drawDev(m);
		}));
	}
	function flash(t) { let d = document.getElementById('cpx-toast'); if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; document.body.appendChild(d); } d.textContent = t; d.classList.add('on'); clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), 4000); }
	document.addEventListener('cp-pwa', () => { if (V.main && V.main.isConnected) drawDev(V.main); });
	function openPage() { if (window.CPXGo) { V.lim = 60; window.CPXGo('ertesitesek'); } else { open(); } }

	function mount() {
		const host = document.querySelector('.cpx-top-r');
		if (!host) return false;
		if (host.querySelector('.cpx-bell')) return true;
		const b = document.createElement('button');
		b.type = 'button';
		b.className = 'cpx-btn ghost cpx-bell';
		b.innerHTML = ICO + '<span>Értesítések</span><b class="cpx-bell-n" hidden></b>';
		const st = host.querySelector('#cpx-status');
		host.insertBefore(b, st ? st.nextSibling : host.firstChild);
		b.addEventListener('click', e => { e.stopPropagation(); openPage(); });
		S.btn = b;
		badge();
		return true;
	}
	const css = document.createElement('style');
	css.textContent = '.cpx-bell{position:relative}.cpx-bell-n{position:absolute;top:2px;left:22px;background:#E8553E;color:#fff;border-radius:999px;font:700 10.5px/16px Inter,sans-serif;min-width:16px;padding:0 4px;text-align:center}' +
		'.cpx-ntpop{position:fixed;z-index:90;background:#fff;border:1px solid #E6E3DE;border-radius:14px;box-shadow:0 24px 50px -20px rgba(20,28,51,.45);max-height:440px;display:flex;flex-direction:column;overflow:hidden;color:#141C33}' +
		'.cpx-nth{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #EEEAE4;font-size:14px}.cpx-nth button{border:0;background:none;color:#2F5BD8;font:600 12.5px Inter,sans-serif;cursor:pointer}' +
		'.cpx-ntl{overflow-y:auto}.cpx-nti{display:flex;gap:10px;width:100%;border:0;border-bottom:1px solid #F2EFEA;background:#fff;text-align:left;padding:9px 14px;cursor:pointer;font:13px/1.4 Inter,sans-serif;color:#141C33}' +
		'.cpx-nti:hover{background:#FAF8F5}.cpx-nti.new{background:#EEF3FD}.cpx-nti.new:hover{background:#E4ECFC}.cpx-nti small{display:block;color:#8A8E96;font-size:11.5px;margin-top:2px}.cpx-ntk{flex:none;width:18px;color:#6B6F78}' +
		'.cpx-ntn{padding:18px 14px;color:#6B6F78;font-size:13px}' +
		'.ntp{max-width:820px;margin:0 auto;padding:4px 0 40px}.ntp-h{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 12px}.ntp-h h2{margin:0;font:800 22px "Plus Jakarta Sans",Inter,sans-serif;color:#141C33}' +
		'.ntp-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.ntp-seg{display:inline-flex;background:#F1EDE7;border-radius:999px;padding:3px}.ntp-seg button{border:0;background:none;border-radius:999px;padding:6px 14px;font:600 13px Inter,sans-serif;color:#5B6275;cursor:pointer}.ntp-seg button.on{background:#fff;color:#141C33;box-shadow:0 1px 2px rgba(20,28,51,.12)}.ntp-seg b{background:#E8553E;color:#fff;border-radius:999px;font-size:11px;padding:0 6px;margin-left:4px}' +
		'.ntp-d{font:700 11.5px Inter,sans-serif;text-transform:uppercase;letter-spacing:.05em;color:#8A8E96;margin:18px 4px 6px}.ntp-g{background:#fff;border:1px solid #E6E3DE;border-radius:14px;overflow:hidden}' +
		'.ntp-i{display:flex;align-items:flex-start;gap:12px;width:100%;border:0;border-bottom:1px solid #F2EFEA;background:#fff;text-align:left;padding:12px 16px;cursor:pointer;font:14px/1.45 Inter,sans-serif;color:#141C33}.ntp-i:last-child{border-bottom:0}.ntp-i:hover{background:#FAF8F5}' +
		'.ntp-i.new{background:#EEF3FD}.ntp-i.new:hover{background:#E4ECFC}.ntp-i.new .ntp-t:before{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;background:#2F5BD8;margin-right:7px;vertical-align:2px}' +
		'.ntp-k{flex:none;width:22px;text-align:center;color:#6B6F78;font-size:15px}.ntp-t{flex:1;min-width:0;overflow-wrap:anywhere}.ntp-a{flex:none;color:#8A8E96;font-size:12px;padding-top:2px}' +
		'.ntp-more{display:block;width:100%;margin:14px 0 0;padding:11px;border:1px dashed #E2DDD5;border-radius:12px;background:transparent;color:#5B6275;font:600 13px Inter,sans-serif;cursor:pointer}.ntp-more[disabled]{opacity:.6}' +
		'.ntp-dev{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:#fff;border:1px solid #E6E3DE;border-radius:14px;padding:12px 16px;margin:0 0 6px}.ntp-dev[hidden]{display:none}.ntp-dev b{font:700 14px Inter,sans-serif;color:#141C33}.ntp-dev small{display:block;color:#5B6275;font:13px/1.45 Inter,sans-serif;margin-top:3px;max-width:560px}.ntp-dvb{display:flex;gap:6px;flex-wrap:wrap}.ntp-dvb .sm{height:32px;padding:0 14px;font-size:13px}' +
		'@media (max-width:600px){.ntp-h h2{font-size:19px}.ntp-i{padding:12px}}';
	document.head.appendChild(css);
	let tries = 0;
	const t = setInterval(() => { if (mount() || ++tries > 50) { clearInterval(t); poll(); setInterval(() => { if (!document.hidden) poll(); }, 60000); } }, 200);
	window.CPNotify = { go, poll, view: m => view(m), refresh: () => view() };
	/* a levél megnyitása / olvasottnak jelölése után hamarabb frissüljön a szám */
	document.addEventListener('click', e => { if (e.target.closest && e.target.closest('.ml-item, [data-one="read"], [data-bulk], [data-tab="mail"]')) { clearTimeout(S.mt); S.mt = setTimeout(poll, 2500); } }, true);
})();
