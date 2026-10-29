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
	async function poll() {
		try { const r = await post('cp_nt_list', { count: 1 }); S.n = r.unread || 0; badge(); } catch (e) { /* csendben újra */ }
	}

	/* a cél megnyitása: „feladatok:12”, „feladatok:mine”, „mail:3” */
	function go(link) {
		const i = String(link).indexOf(':'), tab = i < 0 ? link : link.slice(0, i), arg = i < 0 ? '' : link.slice(i + 1);
		if (tab === 'mail' && window.CPMail && window.CPMail.open) { window.CPMail.open(Number(arg)); return; }
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
		l.innerHTML = x.rows.length ? x.rows.map(n => '<button type="button" class="cpx-nti' + (n.read ? '' : ' new') + '" data-id="' + n.id + '" data-link="' + esc(n.link) + '"><span class="cpx-ntk">' + (n.kind.indexOf('mail') === 0 ? '✉' : '☑') + '</span><span><b>' + esc(n.by_name || 'Valaki') + '</b> ' + esc(n.text) + '<small>' + esc(ago(n.created_at)) + '</small></span></button>').join('') :
			'<div class="cpx-ntn">Még nincs értesítésed. Ha valaki megemlít (@Neved) vagy rád oszt egy kártyát, levelet, itt látod.</div>';
		l.querySelectorAll('[data-id]').forEach(b => b.addEventListener('click', async () => {
			close();
			go(b.dataset.link);
			if (b.classList.contains('new')) { try { const r = await post('cp_nt_read', { id: b.dataset.id }); S.n = r.unread; badge(); } catch (e) { /* később */ } }
		}));
	}

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
		b.addEventListener('click', e => { e.stopPropagation(); open(); });
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
		'.cpx-ntn{padding:18px 14px;color:#6B6F78;font-size:13px}';
	document.head.appendChild(css);
	let tries = 0;
	const t = setInterval(() => { if (mount() || ++tries > 50) { clearInterval(t); poll(); setInterval(() => { if (!document.hidden) poll(); }, 60000); } }, 200);
	window.CPNotify = { go, poll };
})();
