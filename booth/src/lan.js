/* Internet nélküli QR (v0.7): kis webszerver a gépen – a vendég telefonja a helyi Wi-Fi-n (a gép hotspotja vagy a helyszín
 * hálózata) éri el a saját fotóját. Csak a rendezvény-mappák fájljait adja ki, csak a véletlen fotó-azonosítóval. */
const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');

const TOK = /^[a-z0-9]{6,32}$/;
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

function make(o) {
  /* o: { picDir: () => string, log } */
  let srv = null, port = 0;
  function ip() {
    const all = os.networkInterfaces(), out = [];
    for (const [name, L] of Object.entries(all)) for (const a of L || []) if (a.family === 'IPv4' && !a.internal) out.push({ name, ip: a.address });
    /* Wi-Fi / hotspot előre (Mac: en0, bridge100 = internetmegosztás; Windows: Wi-Fi) */
    out.sort((a, b) => score(b.name) - score(a.name));
    return out[0] ? out[0].ip : '';
  }
  const score = n => /bridge|ap\d|hotspot/i.test(n) ? 3 : /^en0$|wi-?fi|wlan/i.test(n) ? 2 : /^en|eth/i.test(n) ? 1 : 0;
  function find(ev, ph) {
    const dir = path.join(o.picDir(), 'galeria-' + ev);
    for (const ext of ['jpg', 'mp4', 'webm']) { const f = path.join(dir, ph + '.' + ext); if (fs.existsSync(f)) return { f, ext }; }
    return null;
  }
  function handle(req, res) {
    const m = /^\/(p|f)\/([a-z0-9]+)\/([a-z0-9]+)(?:\/([a-z]+))?$/.exec((req.url || '').split('?')[0]);
    if (req.method !== 'GET' || !m || !TOK.test(m[2]) || !TOK.test(m[3])) { res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' }); res.end('Nincs ilyen fotó.'); return; }
    const x = find(m[2], m[3]);
    if (!x) { res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' }); res.end('Nincs ilyen fotó.'); return; }
    if (m[1] === 'f') {
      const type = x.ext === 'jpg' ? 'image/jpeg' : 'video/' + x.ext, st = fs.statSync(x.f);
      const h = { 'Content-Type': type, 'Content-Length': st.size, 'Cache-Control': 'no-store' };
      if (m[4] === 'dl') h['Content-Disposition'] = 'attachment; filename="eventcrazy-' + m[3] + '.' + x.ext + '"';
      res.writeHead(200, h); fs.createReadStream(x.f).pipe(res); return;
    }
    const src = '/f/' + m[2] + '/' + m[3], vid = x.ext !== 'jpg';
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end('<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>A fotód – EventCrazy</title>' +
      '<style>body{margin:0;background:#0E1220;color:#fff;font:16px/1.4 -apple-system,system-ui,sans-serif;display:flex;flex-direction:column;align-items:center;gap:16px;padding:20px 16px}img,video{max-width:100%;border-radius:10px}a{display:block;text-align:center;background:#E8894A;color:#141C33;font-weight:700;text-decoration:none;padding:14px 22px;border-radius:999px;width:100%;max-width:360px;box-sizing:border-box}p{color:rgba(255,255,255,.7);text-align:center;margin:0;max-width:420px}</style></head><body>' +
      (vid ? '<video src="' + esc(src) + '" controls autoplay muted loop playsinline></video>' : '<img src="' + esc(src) + '" alt="A fotód">') +
      '<a href="' + esc(src + '/dl') + '" download>Letöltés</a><p>' + (vid ? 'iPhone-on: nyomd meg hosszan a videót → Mentés.' : 'iPhone-on: nyomd meg hosszan a képet → Mentés a Fotókba.') + '</p><p>EventCrazy</p></body></html>');
  }
  return {
    async start(p) {
      if (srv) return this.info();
      port = Number(p) || 8787;
      srv = http.createServer(handle);
      await new Promise((res, rej) => { srv.once('error', rej); srv.listen(port, '0.0.0.0', res); }).catch(e => { srv = null; throw new Error('A helyi szerver nem indul (' + e.code + ')'); });
      o.log('helyi QR-szerver: ' + ip() + ':' + port);
      return this.info();
    },
    stop() { if (srv) { srv.close(); srv = null; } return true; },
    info() { return { on: !!srv, ip: ip(), port }; }
  };
}
module.exports = { make };
