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
        const ext = path.extname(j.file).slice(1).toLowerCase(), mime = ext === 'mp4' ? 'video/mp4' : ext === 'webm' ? 'video/webm' : 'image/jpeg';
        fd.append('file', new Blob([fs.readFileSync(j.file)], { type: mime }), j.photo + '.' + (ext || 'jpg'));
        if (j.poster && fs.existsSync(j.poster)) fd.append('poster', new Blob([fs.readFileSync(j.poster)], { type: 'image/jpeg' }), j.photo + '.jpg');
        if (j.kind) fd.append('kind', j.kind);
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

  /* v0.7: vendégadatok (név, e-mail, kérdőív) a panelbe – sorban, net nélkül is; a panel küldi a fotó-levelet */
  const gdir = path.join(o.dataDir, 'guests'); fs.mkdirSync(gdir, { recursive: true });
  let gbusy = false, glast = '';
  async function pumpGuests() {
    if (gbusy || !o.key()) return; gbusy = true;
    try {
      for (const f of fs.readdirSync(gdir).filter(x => x.endsWith('.json')).sort()) {
        const body = fs.readFileSync(path.join(gdir, f), 'utf8');
        const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 45000);
        let r;
        try { r = await fetch(PANEL + '/guest', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, hdr()), body, signal: ctl.signal }); }
        catch (e) { glast = 'nincs kapcsolat'; break; } finally { clearTimeout(t); }
        const b = await r.json().catch(() => ({}));
        if (r.ok && b.ok) { fs.unlinkSync(path.join(gdir, f)); glast = ''; o.log('vendégadat elküldve' + (b.mail ? ' (levél: ' + b.mail + ')' : '')); }
        else if (r.status === 401 || r.status === 403) { glast = 'gépkulcs elutasítva'; break; }
        else if (r.status >= 400 && r.status < 500) { o.log('vendégadat elutasítva (' + r.status + '): ' + (b.message || '')); fs.renameSync(path.join(gdir, f), path.join(gdir, f + '.hibas')); }
        else { glast = 'szerverhiba ' + r.status; break; }
      }
    } catch (e) { glast = String(e.message || e); } finally { gbusy = false; }
  }
  setInterval(pumpGuests, 20000);

  /* v0.6: nyomtatási sor a galérián (nyomtatóállomás) – csak a /print végpontok */
  async function printq(method, p, body) {
    if (!o.key()) throw new Error('Nincs megadva gépkulcs.');
    if (!/^\/print(\/(next|status|\d+))?(\?[a-z0-9=&]*)?$/.test(p)) throw new Error('tiltott kérés');
    const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 30000);
    try {
      const r = await fetch(GALLERY + '/wp-json/crazy-galeria/v1' + p, { method, headers: Object.assign({ 'Content-Type': 'application/json' }, hdr()), body: body ? JSON.stringify(body) : undefined, signal: ctl.signal });
      const j = await r.json().catch(() => ({}));
      if (r.status === 401) throw new Error('A galéria nem fogadja el a gépkulcsot.');
      if (!r.ok || j.ok === false) throw new Error(j.message || ('Galéria-hiba (' + r.status + ')'));
      return j;
    } catch (e) { if (e.name === 'AbortError') throw new Error('A galéria nem válaszol (nincs internet?)'); throw e; } finally { clearTimeout(t); }
  }

  return {
    newTok: tok,
    printq,
    /* v0.8: állapotjelentés a panelnek (percenként); a válaszban a deal panelből mentett beállításai */
    async beat(d) {
      if (!o.key()) return null;
      const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 12000);
      try {
        const r = await fetch(PANEL + '/beat', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, hdr()), body: JSON.stringify(d || {}), signal: ctl.signal });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.ok === false) throw new Error(j.message || ('Panel-hiba (' + r.status + ')'));
        return j;
      } catch (e) { if (e.name === 'AbortError') throw new Error('A panel nem válaszol'); throw e; } finally { clearTimeout(t); }
    },
    /* v0.10: AI-kép a galérián át (a szerver hívja a modellt, menti a galériába és visszaküldi). A hibakód a szövegben: […] */
    async ai(d) {
      if (!o.key()) throw new Error('Nincs gépkulcs. [key]');
      const m = /^data:([^;]+);base64,(.*)$/s.exec(String(d.jpeg || '')); if (!m) throw new Error('Hibás kép. [bad]');
      const fd = new FormData();
      ['ev', 'deal', 'date', 'src', 'photo', 'prompt'].forEach(k => fd.append(k, String(d[k] == null ? '' : d[k])));
      fd.append('file', new Blob([Buffer.from(m[2], 'base64')], { type: m[1] }), 'foto.jpg');
      const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 120000);
      try {
        const r = await fetch(GALLERY + '/wp-json/crazy-galeria/v1/ai', { method: 'POST', headers: hdr(), body: fd, signal: ctl.signal });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || !j.ok) throw new Error((j.message || ('AI-hiba (' + r.status + ')')) + ' [' + (j.code || r.status) + ']');
        o.log('AI-kép kész: ' + j.photo + ' (' + j.sec + ' mp, ' + j.cost + ' $)');
        return j;
      } catch (e) { if (e.name === 'AbortError') throw new Error('Az AI nem válaszolt időben. [timeout]'); throw e; } finally { clearTimeout(t); }
    },
    guest(d) { fs.writeFileSync(path.join(gdir, Date.now() + '-' + tok(6) + '.json'), JSON.stringify(d)); setTimeout(pumpGuests, 200); return true; },
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
    /* v0.5: kép (JPG) vagy videó (MP4/WebM, borítóképpel); meta: { poster (JPG data URL), kind: photo|video|guestbook } */
    async keep(ev, deal, date, photo, dataUrl, meta) {
      meta = meta || {};
      const dir = path.join(o.picDir(), 'galeria-' + ev); fs.mkdirSync(dir, { recursive: true });
      const mime = (/^data:([^;]+);/.exec(String(dataUrl)) || [])[1] || 'image/jpeg';
      const ext = /mp4/.test(mime) ? 'mp4' : /webm/.test(mime) ? 'webm' : 'jpg';
      const file = path.join(dir, photo + '.' + ext);
      fs.writeFileSync(file, Buffer.from(String(dataUrl).split(',')[1], 'base64'));
      let poster = '';
      if (meta.poster) { poster = path.join(dir, photo + '_p.jpg'); fs.writeFileSync(poster, Buffer.from(String(meta.poster).split(',')[1], 'base64')); }
      fs.writeFileSync(path.join(qdir, Date.now() + '-' + photo + '.json'), JSON.stringify({ ev, deal, date, photo, file, poster, kind: meta.kind || (ext === 'jpg' ? 'photo' : 'video') }));
      setTimeout(pump, 200);
      return file;
    },
    /* helyi galéria: a rendezvény képei (újabb elöl) */
    list(ev) {
      const dir = path.join(o.picDir(), 'galeria-' + ev);
      if (!fs.existsSync(dir)) return [];
      return fs.readdirSync(dir).filter(x => /\.(jpg|mp4|webm)$/.test(x) && !/_p\.jpg$/.test(x)).map(x => { const ph = x.replace(/\.[a-z0-9]+$/, ''), vid = !/\.jpg$/.test(x), po = path.join(dir, ph + '_p.jpg'); return { photo: ph, file: path.join(dir, x), video: vid, poster: vid && fs.existsSync(po) ? po : '', t: fs.statSync(path.join(dir, x)).mtimeMs }; }).sort((a, b) => b.t - a.t);
    },
    readImage: f => 'data:image/jpeg;base64,' + fs.readFileSync(f).toString('base64'),
    /* v0.3.1: a QR mátrixa (a felület rajzolja ki vászonra – nincs képbetöltés) */
    qr: url => { const q = QR.create(url, { errorCorrectionLevel: 'M' }); const m = q.modules; let s = '', k = 0; for (let i = 0; i < m.size * m.size; i++) { const on = m.data[i] ? 1 : 0; k += on; s += on ? '1' : '0'; } o.log('QR: ' + m.size + '×' + m.size + ', ' + k + ' sötét modul'); return { n: m.size, bits: s }; },
    status: () => ({ queued: fs.readdirSync(qdir).filter(x => x.endsWith('.json')).length, sent: last.ok, err: last.err, guests: fs.readdirSync(gdir).filter(x => x.endsWith('.json')).length, gerr: glast }),
    pump
  };
}
module.exports = { make };
