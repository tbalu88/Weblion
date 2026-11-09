/* Crazy panel – service worker (v2.8): telepíthető app + push-értesítés.
 * Nem tárol adatot és nem gyorsítótáraz: a panel mindig a szerverről töltődik; hálózat nélkül egy rövid üzenetet mutat. */
self.addEventListener('install', e => { self.skipWaiting(); });
self.addEventListener('activate', e => { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', e => {
	if (e.request.mode !== 'navigate') return;
	e.respondWith(fetch(e.request).catch(() => new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font:16px system-ui,sans-serif;background:#141C33;color:#fff;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:24px"><div><h2>Nincs internetkapcsolat</h2><p>A Crazy panel kapcsolat nélkül nem érhető el. Próbáld újra, amint van hálózat.</p><button onclick="location.reload()" style="font:600 15px system-ui;padding:10px 20px;border:0;border-radius:999px;background:#E8894A;color:#141C33">Újra</button></div></body>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
});
self.addEventListener('push', e => {
	let d = {};
	try { d = e.data ? e.data.json() : {}; } catch (x) { d = { body: e.data ? e.data.text() : '' }; }
	const scope = self.registration.scope;
	e.waitUntil(self.registration.showNotification(d.title || 'Crazy panel', {
		body: d.body || '', tag: d.tag || undefined, renotify: !!d.tag, data: { url: d.url || scope },
		icon: scope + '?cp_pwa=icon192', badge: scope + '?cp_pwa=icon192'
	}));
});
self.addEventListener('notificationclick', e => {
	e.notification.close();
	/* v2.8.3: mindig a panel saját címén belül (a hash a cél: #deals:12, #mail:3 …), így nem nyílik külső böngészőablak */
	const raw = (e.notification.data && e.notification.data.url) || '', scope = self.registration.scope;
	const hash = raw.indexOf('#') >= 0 ? raw.slice(raw.indexOf('#')) : '';
	const url = scope + hash;
	/* v2.9.58: a kattintás-napló kérése megszűnt */
	e.waitUntil((async () => {
		const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
		for (const c of all) {
			if (c.url.indexOf(self.registration.scope) === 0) { try { await c.focus(); c.postMessage({ cpNav: url }); return; } catch (x) { /* új ablak */ } }
		}
		await self.clients.openWindow(url);
	})());
});
