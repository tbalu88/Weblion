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
  /* 0.19.9: nyers kapcsolattal – a digiCamControl webszervere kétszer küld „Content-Length” fejlécet, amitől a fetch
     hibát dobott, így a futó, válaszoló programot „nem válaszol”-nak láttuk; elég, ha HTTP-válasz érkezik */
  function up(ms) {
    return new Promise(res => {
      let done = false; const fin = v => { if (!done) { done = true; clearTimeout(t); try { so.destroy(); } catch (e) { /* */ } res(v); } };
      const so = require('net').connect({ host: host(), port: port() }, () => so.write('GET / HTTP/1.0\r\nHost: ' + host() + '\r\nConnection: close\r\n\r\n'));
      const t = setTimeout(() => fin(false), ms || 2500);
      so.on('data', d => fin(/^HTTP\//.test(d.toString('latin1', 0, 5))));
      so.on('error', () => fin(false)); so.on('close', () => fin(false));
    });
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
  const running = (im) => new Promise(res => execFile('tasklist', ['/FI', 'IMAGENAME eq ' + (im || 'CameraControl.exe'), '/NH'], { windowsHide: true }, (e, out) => res(!e && String(out).toLowerCase().includes((im || 'CameraControl.exe').toLowerCase()))));
  const kill = (im) => new Promise(res => execFile('taskkill', ['/IM', im || 'CameraControl.exe', '/F'], { windowsHide: true }, () => res()));
  /* 0.19.0: ablak nélküli kamerakezelő (cbcam.exe) – a digiCamControl kamerakönyvtárával, a grafikus program nélkül;
     ugyanazt a HTTP-felületet adja. Ha nincs a csomagban, vagy hibakereséshez a grafikus program kell (Kamera fül), a régi út marad. */
  let mode = '';
  const cbExe = () => { if (!process.resourcesPath || (o.gui && o.gui())) return ''; const f = path.join(process.resourcesPath, 'dcc', 'cbcam.exe'); return fs.existsSync(f) || fs.existsSync(f + '.new') ? f : ''; };
  /* a kis frissítéssel érkezett új kamerakezelő („.new”) életbe léptetése – csak ha épp nem fut */
  function promote(f) { const d = path.dirname(f); try { for (const n of fs.readdirSync(d)) { if (/^cbcam\.exe(\.config)?\.new$/i.test(n)) { const t = path.join(d, n.slice(0, -4)); try { fs.rmSync(t, { force: true }); } catch (e) { /* */ } fs.renameSync(path.join(d, n), t); log('kamerakezelő frissítve: ' + n.slice(0, -4)); } } } catch (e) { log('kamerakezelő frissítése: ' + e.message); } }
  async function ensureCb(f) {
    mode = 'cbcam';
    if (await running('CameraControl.exe')) { log('a grafikus digiCamControl leállítása (a kamerát a cbcam kezeli)'); await kill('CameraControl.exe'); await new Promise(r => setTimeout(r, 1500)); }
    if (await running('cbcam.exe')) {
      for (let i = 0; i < 16; i++) { await new Promise(r => setTimeout(r, 750)); if (await up(2500)) return ''; }
      log('a cbcam nem válaszol – újraindítás'); await kill('cbcam.exe'); await new Promise(r => setTimeout(r, 1000));
    }
    promote(f);
    if (!fs.existsSync(f)) return 'A kamerakezelő (cbcam.exe) hiányzik.';
    log('cbcam indítása: ' + f);
    try { const p = spawn(f, [String(port()), String(process.pid), o.dataDir || path.dirname(f)], { cwd: path.dirname(f), windowsHide: true, stdio: 'ignore' }); started = p; p.on('exit', c => { log('cbcam kilépett (' + c + ')'); if (started === p) started = null; }); } catch (e) { return 'A kamerakezelő nem indult el: ' + e.message; }
    for (let i = 0; i < 40; i++) { await new Promise(r => setTimeout(r, 500)); if (await up(1500)) { log('cbcam kész (' + port() + ')'); if (o.onReady) o.onReady(); return ''; } }
    return 'A kamerakezelő elindult, de nem válaszol (' + port() + ') – a napló: cbcam.log a program adatmappájában.';
  }

  /* biztosítja, hogy a webszerver fusson; hibaüzenettel tér vissza, ha nem sikerült ('' = rendben) */
  async function ensure() {
    if (!WIN || !local()) return '';
    if (await up()) return '';
    if (busy) return busy;
    busy = (async () => {
      lastTry = Date.now();
      const cb = cbExe(); if (cb) return ensureCb(cb);
      mode = 'gui';
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
  /* 0.18.6: a digiCamControl ablakai ne látszódjanak – az élőképhez nyitva kell lenniük, ezért nem zárjuk be, hanem a
     képernyőn kívülre tesszük őket (egy kis háttér-PowerShell figyeli, a Crazy Booth kilépésével ő is leáll).
     Hibakereséshez: Kezelő → Kamera → „digiCamControl ablakainak mutatása”. */
  let hider = null;
  const PS = [
    'param([int]$ParentPid)',
    'Add-Type @"',
    'using System; using System.Runtime.InteropServices;',
    'public class CbW {',
    '  public delegate bool EnumProc(IntPtr h, IntPtr l);',
    '  [DllImport("user32.dll")] public static extern bool EnumWindows(EnumProc f, IntPtr l);',
    '  [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr h, out uint pid);',
    '  [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr h);',
    '  [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr h, out RECT r);',
    '  [DllImport("user32.dll")] public static extern bool SetWindowPos(IntPtr h, IntPtr a, int x, int y, int cx, int cy, uint f);',
    '  [StructLayout(LayoutKind.Sequential)] public struct RECT { public int L, T, R, B; }',
    '}',
    '"@',
    'while ($true) {',
    '  if (-not (Get-Process -Id $ParentPid -ErrorAction SilentlyContinue)) { break }',
    '  $script:ids = @(Get-Process CameraControl -ErrorAction SilentlyContinue | ForEach-Object { [uint32]$_.Id })',
    '  if ($script:ids.Count -gt 0) {',
    '    $cb = [CbW+EnumProc]{ param($h, $l) $p = [uint32]0; [void][CbW]::GetWindowThreadProcessId($h, [ref]$p); if (($script:ids -contains $p) -and [CbW]::IsWindowVisible($h)) { $r = New-Object CbW+RECT; [void][CbW]::GetWindowRect($h, [ref]$r); if ($r.L -gt -20000) { [void][CbW]::SetWindowPos($h, [IntPtr]::Zero, -32000, -32000, 0, 0, 0x0015) } }; return $true }',
    '    [void][CbW]::EnumWindows($cb, [IntPtr]::Zero)',
    '  }',
    '  Start-Sleep -Milliseconds 600',
    '}', ''].join('\r\n');
  function hide(on) {
    if (!WIN) return;
    if (on && cbExe()) on = false; /* a cbcam-nak nincs ablaka */
    if (!on) { if (hider) { try { hider.kill(); } catch (e) { /* */ } hider = null; log('digiCamControl ablakai: látszanak'); } return; }
    if (hider) return;
    try {
      const f = path.join(o.dataDir || require('os').tmpdir(), 'dcc-rejt.ps1'); fs.writeFileSync(f, PS);
      hider = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden', '-File', f, '-ParentPid', String(process.pid)], { windowsHide: true, stdio: 'ignore' });
      hider.on('exit', () => { hider = null; });
      log('digiCamControl ablakai: rejtve');
    } catch (e) { log('digiCamControl elrejtése: ' + e.message); }
  }
  /* kilépéskor csak azt zárjuk be, amit mi indítottunk */
  function stop() { hide(false); if (started) { try { if (mode === 'cbcam') started.kill(); else execFile('taskkill', ['/IM', 'CameraControl.exe', '/F'], { windowsHide: true }); } catch (e) { /* */ } started = null; } }
  return { ensure, watch, stop, exe, hide };
}
module.exports = { make };
