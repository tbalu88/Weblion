/* Nikon D5300 vezérlése a digiCamControl helyi webszerverén át (alap: http://127.0.0.1:5513).
 * Dokumentált parancsok: ?slc=capture, ?slc=get&param1=lastcaptured (folyamatban: "-"), /image/<név>,
 * ?CMD=LiveViewWnd_Show / _Hide, /liveview.jpg, ?slc=set&param1=session.folder&param2=<mappa>. */
const fs = require('fs');
const path = require('path');
/* v0.9 kamerabeállítások – a digiCamControl dokumentált tulajdonságnevei (slc get / set / list) */
const PROPS = ['iso', 'aperture', 'shutterspeed', 'exposurecompensation', 'whitebalance', 'temperature'];
/* v0.12: kézi fehéregyensúly (Kelvin) – a digiCamControl Nikon-meghajtójában „Temperature” (camera.temperature),
 * 31 előre megadott érték („2500 K” … „10000 K”), csak „Kelvin” fehéregyensúlynál hat */
const SLC = { temperature: 'camera.temperature' };
/* a válasz formátuma nincs dokumentálva: JSON-tömböt és soronkénti / vesszős szöveget is elfogadunk */
const clean = s => String(s == null ? '' : s).replace(/^\uFEFF/, '').trim().replace(/^"(.*)"$/, '$1');
function parseList(t) {
  const s = clean(t); if (!s || /^ok$/i.test(s)) return [];
  try { const j = JSON.parse(s); if (Array.isArray(j)) return [...new Set(j.map(clean).filter(Boolean))]; } catch (e) { /* nem JSON */ }
  return [...new Set(s.split(/\r?\n|;|,/).map(clean).filter(x => x && !/^ok$/i.test(x)))];
}

function make(opts) {
  const base = (opts.url || 'http://127.0.0.1:5513').replace(/\/+$/, '');
  const folder = opts.folder;
  /* 0.18.0: ha a webszerver nem érhető el, a beépített digiCamControlt elindítjuk, és egyszer újrapróbáljuk */
  let ensT = 0;
  const get = async (q, asBuf, noEnsure) => {
    try { return await get1(q, asBuf); }
    catch (e) {
      /* 0.18.4: csak valódi „nem fut” (kapcsolat elutasítva) indítja el a digiCamControlt; az élőkép-hiba soha */
      if (noEnsure || !opts.ensure || !e.refused || Date.now() - ensT < 20000) throw e;
      ensT = Date.now(); const msg = await opts.ensure();
      if (msg) throw new Error(msg);
      return get1(q, asBuf);
    }
  };
  /* 0.18.5: minden kérés saját, új kapcsolaton (agent: false) – a digiCamControl webszervere a megtartott (keep-alive)
     kapcsolatot exponálás közben bontja, és ettől a fetch „megszakadt” hibát adott, pedig a kép elkészült */
  const http = require('http');
  const get1 = (q, asBuf) => new Promise((res, rej) => {
    let done = false; const fin = (f, v) => { if (!done) { done = true; f(v); } };
    const rq = http.get(base + q, { agent: false, headers: { Connection: 'close', 'Cache-Control': 'no-store' } }, r => {
      const ch = []; r.on('data', c => ch.push(c));
      r.on('end', () => { const b = Buffer.concat(ch); if (r.statusCode >= 400) { const m = asBuf ? '' : b.toString('utf8').trim().slice(0, 200); return fin(rej, new Error(m && !/^</.test(m) ? 'Kamera: ' + m : 'A kamerakezelő hibát jelzett (' + r.statusCode + ')')); } fin(res, asBuf ? b : b.toString('utf8').trim()); });
      r.on('error', e => fin(rej, mapErr(e)));
    });
    rq.setTimeout(opts.timeout || 8000, () => { rq.destroy(); fin(rej, new Error('A digiCamControl nem válaszol (fut a program, be van kapcsolva a webszerver?)')); });
    rq.on('error', e => fin(rej, mapErr(e)));
  });
  function mapErr(e) {
    const code = String(e.code || '') + ' ' + String(e.message || e);
    if (/ECONNREFUSED/.test(code)) { const x = new Error('A digiCamControl nem érhető el – a program most elindítja; ha nem sikerül, ellenőrizd a kamerát.'); x.refused = true; return x; }
    if (/ECONNRESET|socket hang up|EPIPE|closed/i.test(code)) { const x = new Error('A digiCamControl megszakította a kapcsolatot (a kamera épp foglalt) – próbáld újra.'); x.reset = true; return x; }
    return e;
  }
  const enc = encodeURIComponent;
  let lvOn = false;

  return {
    kind: 'dcc',
    async init() {
      fs.mkdirSync(folder, { recursive: true });
      await get('/?slc=set&param1=session.folder&param2=' + enc(folder));
      return true;
    },
    async liveStart() { if (!lvOn) { await get('/?CMD=LiveViewWnd_Show'); lvOn = true; } },
    async liveStop() { if (lvOn) { lvOn = false; try { await get('/?CMD=LiveViewWnd_Hide'); } catch (e) { /* mindegy */ } } },
    /* egy élőkép-képkocka JPG-ként (Buffer) */
    async liveFrame() { return get('/liveview.jpg?t=' + Date.now(), true, true); },
    /* kamerabeállítások: a lehetséges értékek és a mostani érték */
    async props() {
      const out = {};
      for (const p of PROPS) {
        let list = [], cur = '';
        try { list = parseList(await get('/?slc=list&param1=' + (SLC[p] || p) + '&param2=')); } catch (e) { list = []; }
        try { cur = clean(await get('/?slc=get&param1=' + (SLC[p] || p) + '&param2=')); if (/^ok$/i.test(cur)) cur = ''; } catch (e) { cur = ''; }
        out[p] = { cur, list };
      }
      return out;
    },
    async setProp(p, v) {
      if (!PROPS.includes(p)) throw new Error('Ismeretlen kamerabeállítás: ' + p);
      const r = clean(await get('/?slc=set&param1=' + (SLC[p] || p) + '&param2=' + enc(String(v))));
      if (r && !/^ok\b/i.test(r)) throw new Error('A kamera nem fogadta el (' + p + ' = ' + v + '): ' + r.slice(0, 120));
      return true;
    },
    /* exponálás → a kész JPG útvonala a gépen */
    async capture() {
      const before = await get('/?slc=get&param1=lastcaptured&param2=').catch(() => '');
      /* 0.18.5: ha az exponálási kérés válasza megszakad, a kép attól még elkészülhet – megvárjuk (lastcaptured) */
      try { await get('/?slc=capture&param1=&param2=', false, true); } catch (e) { if (!e.reset && !/nem válaszol/.test(e.message)) throw e; }
      const until = Date.now() + (opts.captureTimeout || 15000);
      while (Date.now() < until) {
        await new Promise(r => setTimeout(r, 250));
        const name = await get('/?slc=get&param1=lastcaptured&param2=').catch(() => '-');
        if (!name || name === '-' || name === before) continue;
        if (/^HIBA/.test(name)) throw new Error('A kamera nem adott képet: ' + name.replace(/^HIBA:\s*/, '')); /* 0.19.0: a cbcam hibajelzése */
        const local = path.isAbsolute(name) ? name : path.join(folder, path.basename(name));
        if (fs.existsSync(local)) return local;
        /* ha nem a mi mappánkba mentett: letöltjük HTTP-n */
        const buf = await get('/image/' + enc(path.basename(name)), true);
        const out = path.join(folder, path.basename(name));
        fs.writeFileSync(out, buf);
        return out;
      }
      throw new Error('A kamera nem adott képet 15 mp alatt (fókusz? kártya? akku?)');
    }
  };
}
module.exports = { make };
