/* Crazy Booth – beépített digiCamControl (0.18.0, a felhasználó döntése 2026-11-20: „mehet az 1-es”).
 * DSLR-módban (Windows) a program maga indítja a digiCamControlt a háttérben, és bekapcsolja benne a webszervert,
 * így nem kell külön telepíteni és beállítani. Forrás sorrendben: a csomagba épített példány (resources/dcc),
 * különben a gépre telepített (Program Files). A digiCamControl MIT-licencű (Duka István), változtatás nélkül szállítjuk.
 * A beállításai: %ProgramData%\digiCamControl\settings.json (régebbi változat: settings.xml) – ebben a webszervert
 * (UseWebserver, WebserverPort) kapcsoljuk be; a többi beállításhoz nem nyúlunk. */
const fs = require('fs');
const path = require('path');
const { spawn, execFile } = require('child_process');

function make(o) {
  const log = o.log || (() => {});
  const WIN = process.platform === 'win32';
  let started = null, lastTry = 0, busy = null;

  const port = () => { const m = /:(\d+)/.exec(String(o.url() || '')); return m ? Number(m[1]) : 5513; };
  const host = () => { const m = /^https?:\/\/([^/:]+)/.exec(String(o.url() || '')); return m ? m[1] : '127.0.0.1'; };
  const local = () => /^(127\.0\.0\.1|localhost)$/i.test(host());

  function exe() {
    const C = [];
    if (process.resourcesPath) C.push(path.join(process.resourcesPath, 'dcc', 'CameraControl.exe'));
    for (const v of ['ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432']) if (process.env[v]) C.push(path.join(process.env[v], 'digiCamControl', 'CameraControl.exe'));
    return C.find(f => { try { return fs.statSync(f).isFile(); } catch (e) { return false; } }) || '';
  }
  async function up(ms) {
    const ctl = new AbortController(), t = setTimeout(() => ctl.abort(), ms || 2500);
    try { const r = await fetch('http://' + host() + ':' + port() + '/', { signal: ctl.signal, cache: 'no-store' }); return r.status > 0; }
    catch (e) { return false; } finally { clearTimeout(t); }
  }
  /* a webszerver bekapcsolása a beállításfájlban; true, ha változtattunk */
  function settings() {
    const dir = path.join(process.env.ProgramData || 'C:\\ProgramData', 'digiCamControl');
    const P = port(); let changed = false;
    try { fs.mkdirSync(dir, { recursive: true }); } catch (e) { /* */ }
    const js = path.join(dir, 'settings.json'), xml = path.join(dir, 'settings.xml');
    try {
      if (fs.existsSync(js)) {
        const raw = fs.readFileSync(js, 'utf8').replace(/^\uFEFF/, ''); const j = JSON.parse(raw);
        const W = { UseWebserver: true, WebserverPort: P, AllowWebserverActions: true, StartMinimized: true, MinimizeToTrayIcon: true, HideTrayNotifications: true };
        if (Object.keys(W).some(k => j[k] !== W[k])) { Object.assign(j, W); fs.writeFileSync(js, JSON.stringify(j, null, 2)); changed = true; }
      } else if (fs.existsSync(xml)) {
        let s = fs.readFileSync(xml, 'utf8'); const s0 = s;
        const put = (k, v) => { const re = new RegExp('<' + k + '>[^<]*</' + k + '>'); s = re.test(s) ? s.replace(re, '<' + k + '>' + v + '</' + k + '>') : s.replace(/<\/Settings>\s*$/, '  <' + k + '>' + v + '</' + k + '>\r\n</Settings>'); };
        put('UseWebserver', 'true'); put('WebserverPort', String(P)); put('AllowWebserverActions', 'true'); put('StartMinimized', 'true'); put('MinimizeToTrayIcon', 'true'); put('HideTrayNotifications', 'true');
        if (s !== s0) { fs.writeFileSync(xml, s); changed = true; }
      } else {
        /* még sosem futott: rövid beállításfájl, a többi alapértéken marad (a JSON-os és az XML-es változat is beolvassa) */
        fs.writeFileSync(xml, '<?xml version="1.0"?>\r\n<Settings xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">\r\n  <UseWebserver>true</UseWebserver>\r\n  <WebserverPort>' + P + '</WebserverPort>\r\n  <AllowWebserverActions>true</AllowWebserverActions>\r\n  <StartMinimized>true</StartMinimized>\r\n  <MinimizeToTrayIcon>true</MinimizeToTrayIcon>\r\n  <HideTrayNotifications>true</HideTrayNotifications>\r\n</Settings>\r\n');
        changed = true;
      }
    } catch (e) { log('digiCamControl beállítás: ' + e.message); }
    return changed;
  }
  /* 0.18.2: a beépített példány mellé Branding.xml – nincs betöltő- és üdvözlőképernyő, nincs frissítés-kérdés
     (a digiCamControl a programmappájából olvassa; a hiányzó elemek az alapértéken maradnak) */
  function branding(f) {
    if (!process.resourcesPath || path.dirname(f) !== path.join(process.resourcesPath, 'dcc')) return;
    const b = path.join(path.dirname(f), 'Branding.xml');
    const x = '<?xml version="1.0"?>\r\n<Branding xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">\r\n  <ShowStartupScreen>false</ShowStartupScreen>\r\n  <ShowStartupScreenAnimation>false</ShowStartupScreenAnimation>\r\n  <ShowWelcomeScreen>false</ShowWelcomeScreen>\r\n  <CheckForUpdate>false</CheckForUpdate>\r\n  <OnlineReference>false</OnlineReference>\r\n</Branding>\r\n';
    try { if (!fs.existsSync(b) || fs.readFileSync(b, 'utf8') !== x) fs.writeFileSync(b, x); } catch (e) { log('digiCamControl Branding.xml: ' + e.message); }
  }
  const running = () => new Promise(res => execFile('tasklist', ['/FI', 'IMAGENAME eq CameraControl.exe', '/NH'], { windowsHide: true }, (e, out) => res(!e && /CameraControl\.exe/i.test(String(out)))));
  const kill = () => new Promise(res => execFile('taskkill', ['/IM', 'CameraControl.exe', '/F'], { windowsHide: true }, () => res()));

  /* biztosítja, hogy a webszerver fusson; hibaüzenettel tér vissza, ha nem sikerült ('' = rendben) */
  async function ensure() {
    if (!WIN || !local()) return '';
    if (await up()) return '';
    if (busy) return busy;
    busy = (async () => {
      lastTry = Date.now();
      const f = exe();
      if (!f) return 'A digiCamControl nincs a gépen – telepítsd a Crazy Booth 0.18-as vagy újabb csomagját, vagy a digiCamControlt.';
      branding(f); const changed = settings();
      if (o.onStart) o.onStart();
      if (await running()) {
        /* 0.18.4: futó digiCamControlt csak akkor indítunk újra, ha a webszervere eddig ki volt kapcsolva; különben
           (pl. exponálás közben foglalt) megvárjuk – az újraindítás megszakította a fotózást */
        if (!changed) {
          for (let i = 0; i < 20; i++) { await new Promise(r => setTimeout(r, 750)); if (await up(2500)) return ''; }
          return 'A digiCamControl fut, de a webszervere nem válaszol – ellenőrizd a kamerát (bekapcsolva, USB).';
        }
        log('digiCamControl újraindítása (a webszerver eddig ki volt kapcsolva)'); await kill(); await new Promise(r => setTimeout(r, 1500));
      }
      log('digiCamControl indítása: ' + f);
      try { const p = spawn(f, [], { cwd: path.dirname(f), detached: true, stdio: 'ignore' }); p.unref(); started = p; } catch (e) { return 'A digiCamControl nem indult el: ' + e.message; }
      for (let i = 0; i < 40; i++) { await new Promise(r => setTimeout(r, 750)); if (await up(1500)) { log('digiCamControl webszerver kész (' + port() + ')'); if (o.onReady) o.onReady(); return ''; } }
      return 'A digiCamControl elindult, de a webszervere nem válaszol (' + port() + '). Ha a Windows tűzfal rákérdezett, engedélyezd.';
    })();
    try { return await busy; } finally { busy = null; }
  }
  /* figyelő: ha DSLR-mód és a webszerver leállt, újraindítja (legfeljebb félpercenként) */
  function watch(isDcc) {
    if (!WIN) return;
    setInterval(async () => { if (!isDcc() || busy || Date.now() - lastTry < 30000) return; if (!(await up(4000)) && !(await up(4000))) { const e = await ensure(); if (e) log('digiCamControl: ' + e); } }, 20000);
  }
  /* kilépéskor csak azt zárjuk be, amit mi indítottunk */
  function stop() { if (started) { try { execFile('taskkill', ['/IM', 'CameraControl.exe', '/F'], { windowsHide: true }); } catch (e) { /* */ } started = null; } }
  return { ensure, watch, stop, exe };
}
module.exports = { make };
