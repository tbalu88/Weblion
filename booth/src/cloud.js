/* Panel- és galéria-kapcsolat: a nap rendezvényei, a deal sablonja, a képek háttérfeltöltése (sorban, net nélkül is), QR, helyi galéria. */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const QR = require('qrcode');

const PANEL = 'https://eventcrazy.hu/wp-json/crazy/v1/booth';
const GALLERY = 'https://tartalek.eventcrazy.hu';

function make(o) {
  /* o: { dataDir, picDir, key: () => string, log } */
  const qdir = path.join(o.dataDir, 'queue'); const tdir = path.join(o.dataDir, 'templates');
  fs.mkdirSync(qdir, { recursive: true }); fs.mkdirSync(tdir, { recursive: true });
  const tok = n => crypto.randomBytes(16).toString('base64').replace(/[^a-z0-9]/gi, '').toLowerCase().slice(0, n);
  const hdr = () => ({ 'X-Booth-Key': o.key() || '' });
  async function api(p) {
    if (!o.key()) throw new Error('Nincs megadva gépkulcs (Beállítások → Gépkulcs).');
    const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 15000);
    try {
      const r = await fetch(PANEL + p, { headers: hdr(), signal: ctl.signal });
      const j = await r.json().catch(() => ({}));
      if (r.status === 401 || r.status === 403) throw new Error('A panel nem fogadja el a gépkulcsot (letiltva vagy hibás).');
      if (!r.ok || j.ok === false) throw new Error(j.message || ('Panel-hiba (' + r.status + ')'));
      return j;
    } catch (e) { if (e.name === 'AbortError') throw new Error('A panel nem válaszol (nincs internet?)'); throw e; } finally { clearTimeout(t); }
  }
  let busy = false, last = { ok: 0, err: '' };
  async function pump() {
    if (busy || !o.key()) return; busy = true;
    try {
      for (const f of fs.readdirSync(qdir).filter(x => x.endsWith('.json')).sort()) {
        const j = JSON.parse(fs.readFileSync(path.join(qdir, f), 'utf8'));
        if (!fs.existsSync(j.file)) { fs.unlinkSync(path.join(qdir, f)); continue; }
        const fd = new FormData();
        ['ev', 'photo', 'date'].forEach(k => fd.append(k, String(j[k] || '')));
        fd.append('deal', String(j.deal || 0));
        fd.append('file', new Blob([fs.readFileSync(j.file)], { type: 'image/jpeg' }), j.photo + '.jpg');
        const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 60000);
        let r;
        try { r = await fetch(GALLERY + '/wp-json/crazy-galeria/v1/upload', { method: 'POST', headers: hdr(), body: fd, signal: ctl.signal }); }
        catch (e) { last.err = 'nincs kapcsolat'; break; } finally { clearTimeout(t); }
        const b = await r.json().catch(() => ({}));
        if (r.ok && b.ok) { fs.unlinkSync(path.join(qdir, f)); last.ok++; last.err = ''; o.log('feltöltve: ' + j.ev + '/' + j.photo); }
        else if (r.status === 401) { last.err = 'gépkulcs elutasítva'; break; }
        else if (r.status >= 400 && r.status < 500) { o.log('feltöltés elutasítva (' + r.status + '): ' + (b.message || '')); fs.renameSync(path.join(qdir, f), path.join(qdir, f + '.hibas')); }
        else { last.err = 'szerverhiba ' + r.status; break; }
      }
    } catch (e) { last.err = String(e.message || e); o.log('sor hiba: ' + last.err); } finally { busy = false; }
  }
  setInterval(pump, 15000);

  return {
    newTok: tok,
    photoUrl: (ev, ph) => GALLERY + '/galeria/' + ev + '/' + ph,
    eventUrl: ev => GALLERY + '/galeria/' + ev + '/',
    async events(date) { return (await api('/events?date=' + encodeURIComponent(date || ''))).events || []; },
    /* sablon: letöltés és helyi tárolás (rendezvény közben net nélkül is megvan) */
    async template(deal) {
      const f = path.join(tdir, deal + '.png');
      try {
        const j = await api('/template?deal=' + encodeURIComponent(deal));
        if (j.template && j.template.png) { fs.writeFileSync(f, Buffer.from(j.template.png.split(',')[1], 'base64')); return { png: j.template.png, name: j.template.name, cached: false }; }
        if (fs.existsSync(f)) fs.unlinkSync(f);
        return null;
      } catch (e) {
        if (fs.existsSync(f)) return { png: 'data:image/png;base64,' + fs.readFileSync(f).toString('base64'), cached: true };
        throw e;
      }
    },
    /* a kész kép mentése a rendezvény mappájába + sorba állítás feltöltésre */
    async keep(ev, deal, date, photo, dataUrl) {
      const dir = path.join(o.picDir(), 'galeria-' + ev); fs.mkdirSync(dir, { recursive: true });
      const file = path.join(dir, photo + '.jpg');
      fs.writeFileSync(file, Buffer.from(String(dataUrl).split(',')[1], 'base64'));
      fs.writeFileSync(path.join(qdir, Date.now() + '-' + photo + '.json'), JSON.stringify({ ev, deal, date, photo, file }));
      setTimeout(pump, 200);
      return file;
    },
    /* helyi galéria: a rendezvény képei (újabb elöl) */
    list(ev) {
      const dir = path.join(o.picDir(), 'galeria-' + ev);
      if (!fs.existsSync(dir)) return [];
      return fs.readdirSync(dir).filter(x => x.endsWith('.jpg')).map(x => ({ photo: x.slice(0, -4), file: path.join(dir, x), t: fs.statSync(path.join(dir, x)).mtimeMs })).sort((a, b) => b.t - a.t);
    },
    readImage: f => 'data:image/jpeg;base64,' + fs.readFileSync(f).toString('base64'),
    /* v0.3.1: a QR mátrixa (a felület rajzolja ki vászonra – nincs képbetöltés) */
    qr: url => { const q = QR.create(url, { errorCorrectionLevel: 'M' }); const m = q.modules; let s = ''; for (let i = 0; i < m.size * m.size; i++) s += m.data[i] ? '1' : '0'; return { n: m.size, bits: s }; },
    status: () => ({ queued: fs.readdirSync(qdir).filter(x => x.endsWith('.json')).length, sent: last.ok, err: last.err }),
    pump
  };
}
module.exports = { make };
