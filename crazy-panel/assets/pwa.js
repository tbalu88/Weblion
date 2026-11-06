/* Crazy panel – telepíthető app és push-értesítés (v2.8). A kezelőfelület az Értesítések oldal tetején (window.CPPwa). */
(function () {
	'use strict';
	const C = window.CPX || {}, P = C.pwa || {};
	if (!P.on || !('serviceWorker' in navigator)) { window.CPPwa = { state: async () => ({ sw: false }) }; return; }
	const S = { reg: null, bip: null };
	navigator.serviceWorker.register(P.sw, { scope: P.scope }).then(r => { S.reg = r; }).catch(() => { /* nem támogatott / tiltott */ });
	/* értesítésre kattintva a már nyitott panel a megfelelő helyre ugrik */
	navigator.serviceWorker.addEventListener('message', e => {
		const u = e.data && e.data.cpNav; if (!u) return;
		const h = String(u).split('#')[1] || '';
		if (h && window.CPNotify && window.CPNotify.go) window.CPNotify.go(h); else if (h) { location.hash = h; }
	});
	window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); S.bip = e; document.dispatchEvent(new Event('cp-pwa')); });
	const standalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
	const ios = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	const b64 = s => { const p = '='.repeat((4 - s.length % 4) % 4), b = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(b, c => c.charCodeAt(0)); };
	async function post(action, params) {
		const fd = new FormData(); fd.append('action', action); fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		const j = await r.json().catch(() => null);
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || 'Hiba');
		return j.data;
	}
	async function reg() { if (S.reg) return S.reg; return navigator.serviceWorker.ready; }
	async function state() {
		const can = 'PushManager' in window && 'Notification' in window && P.push;
		let sub = null;
		if (can) { try { sub = await (await reg()).pushManager.getSubscription(); } catch (e) { sub = null; } }
		return { sw: true, standalone: standalone(), ios: ios(), canPush: can, perm: can ? Notification.permission : 'unsupported', on: !!sub, install: !!S.bip };
	}
	async function enable() {
		const k = await post('cp_push_key', {});
		const perm = await Notification.requestPermission();
		if (perm !== 'granted') throw new Error('Az értesítések engedélyezése elutasítva – a böngésző / telefon beállításaiban engedélyezheted.');
		const r = await reg();
		let sub = await r.pushManager.getSubscription();
		if (!sub) sub = await r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(k.key) });
		await post('cp_push_sub', { sub: JSON.stringify(sub) });
		return true;
	}
	async function disable() {
		const r = await reg(); const sub = await r.pushManager.getSubscription();
		if (sub) { try { await post('cp_push_sub', { sub: JSON.stringify(sub), off: 1 }); } catch (e) { /* a szerveren is törlődik a következő küldéskor */ } await sub.unsubscribe(); }
	}
	async function install() { if (!S.bip) return false; S.bip.prompt(); const c = await S.bip.userChoice.catch(() => null); S.bip = null; return !!(c && c.outcome === 'accepted'); }
	const test = () => post('cp_push_test', {});
	window.CPPwa = { state, enable, disable, install, test };
})();
