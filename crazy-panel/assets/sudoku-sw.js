/* Offline gyorsítótár – verzióváltáskor növeld a CACHE nevet. */
const CACHE = 'szudoku-v8';
const ASSETS = ['./', 'index.html', 'style.css', 'i18n.js', 'engine.js', 'app.js', 'manifest.webmanifest',
  'icons/icon-180.png', 'icons/icon-192.png', 'icons/icon-512.png'];
// az oldal a szkripteket a bővítmény mappájából tölti (verziózott címmel); offline ezek helyett a fenti példányok is jók
const ALIAS = { 'sudoku.css': 'style.css', 'sudoku-app.js': 'app.js', 'sudoku-engine.js': 'engine.js', 'sudoku-i18n.js': 'i18n.js' };

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

function fromCache(req) {
  return caches.match(req, { ignoreSearch: true }).then((r) => {
    if (r) return r;
    const alias = ALIAS[new URL(req.url).pathname.split('/').pop()];
    if (alias) return caches.match(alias);
    if (req.mode === 'navigate') return caches.match('index.html');
    return undefined;
  });
}

// Hálózat előbb (friss verzió); hibás válasz (pl. 502) vagy offline esetén a gyorsítótárból.
// Csak sikeres választ mentünk, a párbaj API-ját soha.
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET' || new URL(req.url).pathname.indexOf('/wp-json/') !== -1) return;
  e.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
          return res;
        }
        return fromCache(req).then((r) => r || res);
      })
      .catch(() => fromCache(req).then((r) => r || Response.error()))
  );
});
