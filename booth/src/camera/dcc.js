/* Nikon D5300 vezérlése a digiCamControl helyi webszerverén át (alap: http://127.0.0.1:5513).
 * Dokumentált parancsok: ?slc=capture, ?slc=get&param1=lastcaptured (folyamatban: "-"), /image/<név>,
 * ?CMD=LiveViewWnd_Show / _Hide, /liveview.jpg, ?slc=set&param1=session.folder&param2=<mappa>. */
const fs = require('fs');
const path = require('path');
/* v0.9 kamerabeállítások – a digiCamControl dokumentált tulajdonságnevei (slc get / set / list) */
const PROPS = ['iso', 'aperture', 'shutterspeed', 'exposurecompensation', 'whitebalance'];
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
  const get = async (q, asBuf) => {
    const ctl = new AbortController();
    const t = setTimeout(() => ctl.abort(), opts.timeout || 8000);
    try {
      const r = await fetch(base + q, { signal: ctl.signal, cache: 'no-store' });
      if (!r.ok) throw new Error('digiCamControl HTTP ' + r.status);
      return asBuf ? Buffer.from(await r.arrayBuffer()) : (await r.text()).trim();
    } catch (e) {
      if (e.name === 'AbortError') throw new Error('A digiCamControl nem válaszol (fut a program, be van kapcsolva a webszerver?)');
      if (/ECONNREFUSED|fetch failed/.test(String(e.message || e))) throw new Error('A digiCamControl nem érhető el – indítsd el, és kapcsold be a webszervert (5513).');
      throw e;
    } finally { clearTimeout(t); }
  };
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
    async liveFrame() { return get('/liveview.jpg?t=' + Date.now(), true); },
    /* kamerabeállítások: a lehetséges értékek és a mostani érték */
    async props() {
      const out = {};
      for (const p of PROPS) {
        let list = [], cur = '';
        try { list = parseList(await get('/?slc=list&param1=' + p + '&param2=')); } catch (e) { list = []; }
        try { cur = clean(await get('/?slc=get&param1=' + p + '&param2=')); if (/^ok$/i.test(cur)) cur = ''; } catch (e) { cur = ''; }
        out[p] = { cur, list };
      }
      return out;
    },
    async setProp(p, v) {
      if (!PROPS.includes(p)) throw new Error('Ismeretlen kamerabeállítás: ' + p);
      const r = clean(await get('/?slc=set&param1=' + p + '&param2=' + enc(String(v))));
      if (r && !/^ok\b/i.test(r)) throw new Error('A kamera nem fogadta el (' + p + ' = ' + v + '): ' + r.slice(0, 120));
      return true;
    },
    /* exponálás → a kész JPG útvonala a gépen */
    async capture() {
      const before = await get('/?slc=get&param1=lastcaptured&param2=').catch(() => '');
      await get('/?slc=capture&param1=&param2=');
      const until = Date.now() + (opts.captureTimeout || 15000);
      while (Date.now() < until) {
        await new Promise(r => setTimeout(r, 250));
        const name = await get('/?slc=get&param1=lastcaptured&param2=').catch(() => '-');
        if (!name || name === '-' || name === before) continue;
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
