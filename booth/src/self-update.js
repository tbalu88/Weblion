/* Crazy Booth – önfrissítő (0.17.0): a program (keretrendszer) új kiadását a programon belül tölti le és telepíti.
 * Csak kézzel indul (a felhasználó döntése, 2026-11-20): Beállítások → Gép → Program frissítése.
 * Forrás: a GitHub-kiadások (booth-v<verzió>), Windowson a „…-Windows-x64.zip”, Macen a „…-Mac-AppleSilicon.zip”.
 * Menete: letöltés (sha256-ellenőrzés) → kicsomagolás a program mellé („.new”) → a program kilép → egy kis segédszkript
 *   kicseréli a mappát / alkalmazást (Macen ad-hoc aláírással), elindítja az újat, és ha az 40 mp alatt nem jelez vissza,
 *   visszaállítja a régit. A beállítások, képek és a felület a felhasználói mappában vannak – a csere nem érinti őket. */
/* 0.18.1: az Electron „fs”-e az .asar fájlokat mappaként kezeli – a csomag kibontásához, törléséhez, áthelyezéséhez
 * az eredeti fájlkezelő kell (különben EBUSY: rmdir …\resources\app.asar) */
let fs; try { fs = require('original-fs'); } catch (e) { fs = require('fs'); }
const path = require('path');
const crypto = require('crypto');
const { spawn, execFileSync } = require('child_process');

const REPO = 'tbalu88/Weblion';
const vnum = v => String(v || '0').replace(/^v/, '').split('.').map(n => parseInt(n, 10) || 0);
const newer = (a, b) => { const x = vnum(a), y = vnum(b); for (let i = 0; i < 3; i++) { if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) > (y[i] || 0); } return false; };

function make(o) {
  const { app, log } = o;
  const MAC = process.platform === 'darwin', WIN = process.platform === 'win32';
  const dir = () => { const d = path.join(app.getPath('userData'), 'upd'); fs.mkdirSync(d, { recursive: true }); return d; };
  const okFile = v => path.join(dir(), 'ok-' + v);
  let found = null, file = '';

  /* a telepített program helye: Windowson a mappa (benne a .exe), Macen a .app */
  function target() {
    const exe = process.execPath;
    if (MAC) { const i = exe.indexOf('.app/'); if (i < 0) throw new Error('A program helye nem állapítható meg.'); return exe.slice(0, i + 4); }
    return path.dirname(exe);
  }
  function writable(p) {
    const parent = path.dirname(p);
    try { const t = path.join(parent, '.cb-w-' + Date.now()); fs.mkdirSync(t); fs.rmdirSync(t); return true; } catch (e) { return false; }
  }

  async function check() {
    if (!MAC && !WIN) throw new Error('Ezen a rendszeren nincs önfrissítés.');
    if (MAC && process.arch !== 'arm64') throw new Error('Ehhez a Machez (Intel) nincs kiadás – kézzel kell telepíteni.');
    const r = await fetch('https://api.github.com/repos/' + REPO + '/releases?per_page=30', { headers: { 'User-Agent': 'CrazyBooth/' + app.getVersion(), Accept: 'application/vnd.github+json' } });
    if (!r.ok) throw new Error('A kiadások listája nem érhető el (' + r.status + ').');
    const L = (await r.json()).filter(x => /^booth-v\d+\.\d+\.\d+$/.test(x.tag_name || '') && !x.draft && !x.prerelease);
    const want = MAC ? /-Mac-AppleSilicon\.zip$/ : /-Windows-x64\.zip$/;
    let best = null;
    for (const x of L) {
      const v = x.tag_name.slice(7), a = (x.assets || []).find(z => want.test(z.name || ''));
      if (!a) continue;
      if (!best || newer(v, best.v)) best = { v, url: a.browser_download_url, size: a.size || 0, sha: /^sha256:([a-f0-9]{64})$/.exec(a.digest || '') ? a.digest.slice(7) : '', notes: String(x.body || '').slice(0, 600), name: x.name || '' };
    }
    const cur = app.getVersion();
    found = best && newer(best.v, cur) ? best : null;
    return { cur, avail: found ? { v: found.v, size: found.size, notes: found.notes } : null, latest: best ? best.v : cur };
  }

  async function download(onP) {
    if (!found) throw new Error('Előbb keresd meg a frissítést.');
    const f = path.join(dir(), 'CrazyBooth-' + found.v + '.zip');
    const r = await fetch(found.url, { headers: { 'User-Agent': 'CrazyBooth/' + app.getVersion() }, redirect: 'follow' });
    if (!r.ok || !r.body) throw new Error('A letöltés nem indult el (' + r.status + ').');
    const total = Number(r.headers.get('content-length')) || found.size || 0;
    const out = fs.createWriteStream(f), h = crypto.createHash('sha256'); let got = 0, last = 0;
    const rd = r.body.getReader();
    for (;;) {
      const { done, value } = await rd.read(); if (done) break;
      const b = Buffer.from(value); h.update(b); got += b.length;
      if (!out.write(b)) await new Promise(res => out.once('drain', res));
      if (onP && total && Date.now() - last > 250) { last = Date.now(); onP(got / total); }
    }
    await new Promise((res, rej) => out.end(err => err ? rej(err) : res()));
    const sha = h.digest('hex');
    if (found.sha && sha !== found.sha) { try { fs.unlinkSync(f); } catch (e) { /* */ } throw new Error('A letöltött fájl sérült (ellenőrzőösszeg). Próbáld újra.'); }
    if (onP) onP(1);
    file = f; log('önfrissítés: letöltve ' + found.v + ' (' + Math.round(got / 1048576) + ' MB)');
    return { v: found.v, mb: Math.round(got / 1048576) };
  }

  /* kicsomagolás a program mellé, segédszkript, kilépés */
  function install() {
    if (!file || !found) throw new Error('Előbb töltsd le a frissítést.');
    const t = target();
    if (MAC && /\/AppTranslocation\//.test(t)) throw new Error('A program a Letöltések mappából fut. Húzd át az Alkalmazások mappába, és onnan indítsd.');
    if (!writable(t)) throw new Error('A program mappája nem írható (' + path.dirname(t) + '). ' + (MAC ? 'Tedd az Alkalmazások mappába, rendszergazda fiókkal.' : 'Tedd egy írható mappába (pl. a Dokumentumok alá).'));
    const tmp = path.join(dir(), 'x-' + found.v); fs.rmSync(tmp, { recursive: true, force: true }); fs.mkdirSync(tmp, { recursive: true });
    if (MAC) execFileSync('/usr/bin/ditto', ['-x', '-k', file, tmp]);
    else execFileSync('tar', ['-xf', file, '-C', tmp], { windowsHide: true });
    const ok = okFile(found.v); try { fs.unlinkSync(ok); } catch (e) { /* */ }
    let script;
    if (MAC) {
      const app0 = fs.readdirSync(tmp).find(n => /\.app$/.test(n)); if (!app0) throw new Error('A csomagban nincs alkalmazás.');
      const NEW = t + '.new'; fs.rmSync(NEW, { recursive: true, force: true }); execFileSync('/bin/mv', [path.join(tmp, app0), NEW]);
      script = path.join(dir(), 'csere.sh');
      fs.writeFileSync(script, ['#!/bin/bash', 'PID="$1"; APP="$2"; NEW="$3"; OK="$4"; OLD="$APP.old"',
        'while kill -0 "$PID" 2>/dev/null; do sleep 1; done; sleep 1',
        'rm -rf "$OLD"; mv "$APP" "$OLD" || { open "$APP"; exit 1; }',
        'mv "$NEW" "$APP" || { mv "$OLD" "$APP"; open "$APP"; exit 1; }',
        'xattr -cr "$APP" 2>/dev/null; codesign --force --deep --sign - "$APP" 2>/dev/null',
        'open "$APP"',
        'for i in $(seq 1 40); do if [ -f "$OK" ]; then rm -rf "$OLD"; exit 0; fi; sleep 1; done',
        'pkill -f "$APP/Contents/MacOS/" 2>/dev/null; sleep 2; rm -rf "$APP"; mv "$OLD" "$APP"; open "$APP"', ''].join('\n'), { mode: 0o755 });
      log('önfrissítés: csere indul ' + app.getVersion() + ' → ' + found.v);
      spawn('/bin/bash', [script, String(process.pid), t, NEW, ok], { cwd: dir(), detached: true, stdio: 'ignore' }).unref();
    } else {
      /* a zip vagy közvetlenül a fájlokat, vagy egy mappát tartalmaz – ahol a .exe van, az az új programmappa */
      let src = tmp; if (!fs.readdirSync(tmp).some(n => /\.exe$/i.test(n))) { const sub = fs.readdirSync(tmp).map(n => path.join(tmp, n)).find(p => fs.statSync(p).isDirectory() && fs.readdirSync(p).some(n => /\.exe$/i.test(n))); if (sub) src = sub; }
      const exe = fs.readdirSync(src).find(n => /^Crazy Booth\.exe$/i.test(n)) || fs.readdirSync(src).find(n => /\.exe$/i.test(n) && !/unins|elevate/i.test(n));
      if (!exe) throw new Error('A csomagban nincs program.');
      const NEW = t + '.new'; fs.rmSync(NEW, { recursive: true, force: true }); try { fs.renameSync(src, NEW); } catch (e) { fs.cpSync(src, NEW, { recursive: true }); fs.rmSync(src, { recursive: true, force: true }); } /* másik meghajtón: másolás */
      script = path.join(dir(), 'csere.cmd');
      fs.writeFileSync(script, ['@echo off', 'setlocal',
        'set "PID=%~1"', 'set "APP=%~2"', 'set "NEW=%~3"', 'set "OK=%~4"', 'set "EXE=%~5"', 'set "OLD=%APP%.old"',
        ':wait', 'tasklist /FI "PID eq %PID%" 2>nul | find "%PID%" >nul && (timeout /t 1 /nobreak >nul & goto wait)', 'timeout /t 2 /nobreak >nul',
        'cd /d "%TEMP%"', 'if exist "%OLD%" rmdir /s /q "%OLD%"',
        'set /a M=0', ':mv', 'move "%APP%" "%OLD%" >nul && goto moved', 'set /a M+=1', 'if %M% GEQ 15 (start "" "%APP%\\%EXE%" & exit /b 1)', 'timeout /t 2 /nobreak >nul', 'goto mv', ':moved',
        'move "%NEW%" "%APP%" >nul || (move "%OLD%" "%APP%" >nul & start "" "%APP%\\%EXE%" & exit /b 1)',
        'start "" "%APP%\\%EXE%"',
        'set /a N=0', ':chk', 'if exist "%OK%" (rmdir /s /q "%OLD%" & exit /b 0)', 'set /a N+=1', 'if %N% GEQ 40 goto back', 'timeout /t 1 /nobreak >nul', 'goto chk',
        ':back', 'taskkill /F /IM "%EXE%" >nul 2>&1', 'timeout /t 2 /nobreak >nul', 'rmdir /s /q "%APP%"', 'move "%OLD%" "%APP%" >nul', 'start "" "%APP%\\%EXE%"', ''].join('\r\n'));
      log('önfrissítés: csere indul ' + app.getVersion() + ' → ' + found.v);
      /* 0.18.1: a szkript a programmappán kívülről fusson – a saját munkakönyvtárát a Windows nem engedi átnevezni */
      spawn('cmd.exe', ['/c', script, String(process.pid), t, NEW, ok, exe], { cwd: dir(), detached: true, stdio: 'ignore', windowsHide: true }).unref();
    }
    setTimeout(() => app.quit(), 400);
    return true;
  }

  /* indításkor: az új változat visszajelez a segédszkriptnek, hogy elindult; a régi letöltések takarítása */
  function started() {
    try { fs.writeFileSync(okFile(app.getVersion()), String(Date.now())); } catch (e) { /* */ }
    try { for (const n of fs.readdirSync(dir())) { if (/\.zip$/.test(n) || /^x-/.test(n)) { const p = path.join(dir(), n); if (Date.now() - fs.statSync(p).mtimeMs > 3600e3) fs.rmSync(p, { recursive: true, force: true }); } } } catch (e) { /* */ }
  }
  return { check, download, install, started };
}
module.exports = { make };
