/* Crazy Booth mobil – offline: a felület fájljai a gépen is megvannak (a feltöltési sor az IndexedDB-ben).
 * Frissítés: hálózat előbb (ha van), különben a tárolt változat. Az API-hívásokat nem tároljuk. */
const C = 'cbm-v1';
const FILES = ['mobil.html', 'mobil.css', 'mobil.js', 'compose.js', 'qrcode.min.js', 'mobil-manifest.json'];
self.addEventListener('install', e => { e.waitUntil(caches.open(C).then(c => c.addAll(FILES)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(L => Promise.all(L.filter(k => k !== C).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin || !FILES.some(f => u.pathname.endsWith('/' + f))) return;
  e.respondWith(fetch(e.request).then(r => { if (r.ok) { const cp = r.clone(); caches.open(C).then(c => c.put(e.request, cp)); } return r; }).catch(() => caches.match(e.request, { ignoreSearch: true })));
});
