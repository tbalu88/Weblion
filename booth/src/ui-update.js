/* Online felület: a felület (index.html, app.js, compose.js, style.css) a szerverről frissül.
 * A gép induláskor és 15 percenként megnézi a manifest.json-t; az újabb változatot letölti, sha256-tal ellenőrzi,
 * és helyben elmenti (userData/ui/current) – így net nélkül is az utolsó letöltött fut. Ha nincs letöltött, a beépített. */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const BASE = 'https://tartalek.eventcrazy.hu/wp-content/plugins/crazy-booth-ui/';
const vnum = v => String(v || '0').split('.').map(n => parseInt(n, 10) || 0);
const vge = (a, b) => { const x = vnum(a), y = vnum(b); for (let i = 0; i < 3; i++) { if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) > (y[i] || 0); } return true; };

function make(o) {
  /* o: { dataDir, bundled (beépített index.html útja), shellVersion, log } */
  const root = path.join(o.dataDir, 'ui'), cur = path.join(root, 'current');
  const readMan = d => { try { return JSON.parse(fs.readFileSync(path.join(d, 'manifest.json'), 'utf8')); } catch (e) { return null; } };
  const valid = d => { const m = readMan(d); if (!m || !m.files) return false; return Object.keys(m.files).every(f => fs.existsSync(path.join(d, f))); };
  async function get(url, ms) {
    const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), ms || 15000);
    try { const r = await fetch(url + (url.includes('?') ? '&' : '?') + 't=' + Date.now(), { signal: ctl.signal, cache: 'no-store' }); if (!r.ok) throw new Error('HTTP ' + r.status); return Buffer.from(await r.arrayBuffer()); }
    finally { clearTimeout(t); }
  }
  /* új változat letöltése; true, ha frissült */
  async function check(ms) {
    let man;
    try { man = JSON.parse((await get(BASE + 'manifest.json', ms)).toString('utf8')); } catch (e) { o.log('felület-frissítés: nem érhető el (' + e.message + ')'); return false; }
    if (!man || !man.v || !man.files) return false;
    const have = readMan(cur);
    if (have && have.v === man.v && valid(cur)) return false;
    if (man.shell && !vge(o.shellVersion, man.shell)) { o.log('felület ' + man.v + ': újabb programot kér (' + man.shell + '), marad a mostani'); return false; }
    const tmp = path.join(root, 'tmp-' + Date.now());
    fs.mkdirSync(tmp, { recursive: true });
    try {
      for (const [f, sha] of Object.entries(man.files)) {
        if (!/^[a-z0-9._-]+$/i.test(f)) throw new Error('hibás fájlnév');
        /* v0.11: a változatlan fájlt (pl. a sablonszerkesztő 3 MB-os adatcsomagja) nem töltjük le újra */
        let b = null; const of = path.join(cur, f);
        if (have && have.files && have.files[f] === sha && fs.existsSync(of)) { const x = fs.readFileSync(of); if (crypto.createHash('sha256').update(x).digest('hex') === sha) b = x; }
        if (!b) b = await get(BASE + encodeURIComponent(f), 120000);
        if (crypto.createHash('sha256').update(b).digest('hex') !== sha) throw new Error('ellenőrzőösszeg-hiba: ' + f);
        fs.writeFileSync(path.join(tmp, f), b);
      }
      fs.writeFileSync(path.join(tmp, 'manifest.json'), JSON.stringify(man));
      const old = path.join(root, 'old');
      fs.rmSync(old, { recursive: true, force: true });
      if (fs.existsSync(cur)) fs.renameSync(cur, old);
      fs.renameSync(tmp, cur);
      fs.rmSync(old, { recursive: true, force: true });
      o.log('felület frissítve: ' + man.v + (man.note ? ' – ' + man.note : ''));
      return true;
    } catch (e) { fs.rmSync(tmp, { recursive: true, force: true }); o.log('felület-frissítés hiba: ' + e.message); return false; }
  }
  return {
    check,
    /* a betöltendő index.html és a felület változata */
    entry() { if (valid(cur)) { const m = readMan(cur); return { file: path.join(cur, 'index.html'), v: m.v, online: true }; } return { file: o.bundled, v: 'beépített', online: false }; }
  };
}
module.exports = { make };
