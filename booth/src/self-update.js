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

  /* 0.19.3: a GitHub-tároló privát lett (2026-11-21) – a frissítés a saját szerverről (tartalék) jön: latest.json + a kis csomag.
     A teljes csomag (motorváltáskor) kézi telepítés; ha a szerver nem érhető el, a GitHub a tartalék út. */
  const DIST = 'https://tartalek.eventcrazy.hu/wp-content/plugins/crazy-booth-ui/';
  async function checkDist() {
    const r = await fetch(DIST + 'booth-latest.json?t=' + Date.now(), { headers: { 'User-Agent': 'CrazyBooth/' + app.getVersion() }, cache: 'no-store' });
    if (!r.ok) throw new Error('A frissítési szerver nem érhető el (' + r.status + ').');
    const m = await r.json(), cur = app.getVersion();
    if (!m || !m.v || !newer(m.v, cur)) { found = null; return { cur, avail: null, latest: (m && m.v) || cur }; }
    const dccOk = !WIN || !m.dcc || fs.existsSync(path.join(process.resourcesPath || '', 'dcc', 'CameraControl.exe'));
    found = { v: m.v, notes: String(m.note || ''), url: '', size: 0, sha: '', assets: [] };
    if (m.app && m.electron === process.versions.electron && dccOk && process.resourcesPath) found.small = { url: DIST + m.app.file, size: m.app.size || 0, sha: m.app.sha256 };
    else {
      const f = m.full && (MAC ? m.full.mac : m.full.win);
      if (f && f.file) { found.url = DIST + f.file; found.size = f.size || 0; found.sha = f.sha256 || ''; }
      else { found = null; throw new Error('Új változat: ' + m.v + ' – ehhez a géphez a teljes csomag kell (új motor' + (dccOk ? '' : ' / hiányzik a kamerakezelő') + '). Kérd az irodától a kézi telepítést.'); }
    }
    log('önfrissítés: ' + cur + ' → ' + m.v + (found.small ? ' (kis csomag)' : ' (teljes csomag)'));
    return { cur, avail: { v: found.v, size: found.small ? found.small.size : found.size, small: !!found.small, notes: found.notes }, latest: m.v };
  }

  async function check() {
    if (!MAC && !WIN) throw new Error('Ezen a rendszeren nincs önfrissítés.');
    if (MAC && process.arch !== 'arm64') throw new Error('Ehhez a Machez (Intel) nincs kiadás – kézzel kell telepíteni.');
    try { return await checkDist(); }
    catch (e) { if (/teljes csomag kell/.test(e.message)) throw e; log('önfrissítés: saját szerver – ' + e.message + '; GitHub-próba'); }
    const r = await fetch('https://api.github.com/repos/' + REPO + '/releases?per_page=30', { headers: { 'User-Agent': 'CrazyBooth/' + app.getVersion(), Accept: 'application/vnd.github+json' } });
    if (!r.ok) throw new Error('A kiadások listája nem érhető el (' + r.status + ').');
    const L = (await r.json()).filter(x => /^booth-v\d+\.\d+\.\d+$/.test(x.tag_name || '') && !x.draft && !x.prerelease);
    const want = MAC ? /-Mac-AppleSilicon\.zip$/ : /-Windows-x64\.zip$/;
    /* 2026-11-21: a Mac-csomag nem minden kiadásnál készül – a kiadás akkor is jelölt, ha csak kis csomagja (programkód) van;
       ha végül teljes csomag kell, a legújabb olyan kiadás jön, amelyikben van ehhez a géphez teljes csomag */
    let best = null, bestFull = null;
    const mk = (x, a) => ({ v: x.tag_name.slice(7), url: a ? a.browser_download_url : '', size: a ? a.size || 0 : 0, sha: a && /^sha256:([a-f0-9]{64})$/.exec(a.digest || '') ? a.digest.slice(7) : '', notes: String(x.body || '').slice(0, 600), name: x.name || '', assets: x.assets || [] });
    for (const x of L) {
      const v = x.tag_name.slice(7), a = (x.assets || []).find(z => want.test(z.name || ''));
      const hasSmall = (x.assets || []).some(z => /-app\.zip$/.test(z.name || '')) && (x.assets || []).some(z => /-app\.json$/.test(z.name || ''));
      if (a && (!bestFull || newer(v, bestFull.v))) bestFull = mk(x, a);
      if ((a || hasSmall) && (!best || newer(v, best.v))) best = mk(x, a);
    }
    const cur = app.getVersion();
    found = best && newer(best.v, cur) ? best : null;
    /* 0.18.3: kis frissítés – ha a motor (Electron) nem változott, csak a program kódja (app.asar, ~3 MB) jön le;
       a teljes csomag (~200 MB) csak motorváltáskor, vagy ha a gépen hiányzik a beépített digiCamControl */
    if (found) {
      const aj = found.assets.find(z => /-app\.json$/.test(z.name || '')), az = found.assets.find(z => /-app\.zip$/.test(z.name || ''));
      if (aj && az && process.resourcesPath) {
        try {
          const m = await (await fetch(aj.browser_download_url, { headers: { 'User-Agent': 'CrazyBooth/' + cur }, redirect: 'follow' })).json();
          const dccOk = !WIN || !m.dcc || fs.existsSync(path.join(process.resourcesPath, 'dcc', 'CameraControl.exe'));
          if (m && m.electron === process.versions.electron && dccOk && /^[a-f0-9]{64}$/.test(m.sha256 || '')) { found.small = { url: az.browser_download_url, size: az.size || 0, sha: m.sha256 }; }
          else log('önfrissítés: teljes csomag kell (motor: ' + process.versions.electron + ' → ' + (m && m.electron) + (dccOk ? '' : ', hiányzik a digiCamControl') + ')');
        } catch (e) { log('önfrissítés: a kis csomag adatai nem olvashatók – teljes csomag (' + e.message + ')'); }
      }
      /* nincs kis csomag és ehhez a géphez nincs teljes csomag ebben a kiadásban → a legújabb teljes csomag (ha újabb) */
      if (!found.small && !found.url) found = bestFull && newer(bestFull.v, cur) ? bestFull : null;
    }
    return { cur, avail: found ? { v: found.v, size: found.small ? found.small.size : found.size, small: !!found.small, notes: found.notes } : null, latest: best ? best.v : cur };
  }

  async function download(onP) {
    if (!found) throw new Error('Előbb keresd meg a frissítést.');
    const src = found.small || found;
    const f = path.join(dir(), 'CrazyBooth-' + found.v + (found.small ? '-app' : '') + '.zip');
    const r = await fetch(src.url, { headers: { 'User-Agent': 'CrazyBooth/' + app.getVersion() }, redirect: 'follow' });
    if (!r.ok || !r.body) throw new Error('A letöltés nem indult el (' + r.status + ').');
    const total = Number(r.headers.get('content-length')) || src.size || 0;
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
    if (src.sha && sha !== src.sha) { try { fs.unlinkSync(f); } catch (e) { /* */ } throw new Error('A letöltött fájl sérült (ellenőrzőösszeg). Próbáld újra.'); }
    if (onP) onP(1);
    file = f; log('önfrissítés: letöltve ' + found.v + ' (' + Math.round(got / 1048576) + ' MB)');
    return { v: found.v, mb: Math.round(got / 1048576) };
  }

  /* kicsomagolás a program mellé, segédszkript, kilépés */
  /* 0.19.6: a cserélő szkript indítása Windowson. A szkript útvonalában szóköz van („…\\Crazy Booth\\upd\\…”), és a cmd.exe
     a Node által idézőjelezett „/c "szkript" "arg" …” sorból az első és az utolsó idézőjelet levágta – a szkript el sem indult,
     a program bezárult, és a régi változat maradt. Most: cmd /d /s /c ""szkript" "arg1" …"" szó szerint átadva (a /s pontosan
     a külső idézőjelpárt veszi le). */
  function runCmd(script, args) {
    const q = a => '"' + String(a).replace(/"/g, '') + '"';
    const line = '"' + [script].concat(args).map(q).join(' ') + '"';
    /* 0.19.12: rejtett ablakkal (wincmd.js) – a „detached” cmd minden belső parancsa saját fekete ablakot nyitott */
    void line; const how = require('./wincmd').run(script, args, dir());
    log('önfrissítés: cserélő szkript elindítva (' + how + ')');
  }
  function install() {
    if (!file || !found) throw new Error('Előbb töltsd le a frissítést.');
    if (found.small && /-app\.zip$/.test(file)) return installSmall();
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
        'for i in $(seq 1 120); do if [ -f "$OK" ]; then rm -rf "$OLD"; exit 0; fi; sleep 1; done',
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
        'set /a W=0', ':wait', 'set /a W+=1', 'if %W% GEQ 30 (taskkill /F /PID %PID% >nul 2>&1 & timeout /t 2 /nobreak >nul & goto waited)', 'tasklist /FI "PID eq %PID%" 2>nul | find "%PID%" >nul && (timeout /t 1 /nobreak >nul & goto wait)', ':waited', 'taskkill /F /IM "%EXE%" >nul 2>&1', 'timeout /t 2 /nobreak >nul',
        'cd /d "%TEMP%"', 'if exist "%OLD%" rmdir /s /q "%OLD%"',
        'set /a M=0', ':mv', 'move "%APP%" "%OLD%" >nul && goto moved', 'set /a M+=1', 'if %M% GEQ 15 (start "" "%APP%\\%EXE%" & exit /b 1)', 'timeout /t 2 /nobreak >nul', 'goto mv', ':moved',
        'move "%NEW%" "%APP%" >nul || (move "%OLD%" "%APP%" >nul & start "" "%APP%\\%EXE%" & exit /b 1)',
        'start "" "%APP%\\%EXE%"',
        'set /a N=0', ':chk', 'if exist "%OK%" (rmdir /s /q "%OLD%" & exit /b 0)', 'set /a N+=1', 'if %N% GEQ 120 goto back', 'timeout /t 1 /nobreak >nul', 'goto chk',
        ':back', 'taskkill /F /IM "%EXE%" >nul 2>&1', 'timeout /t 2 /nobreak >nul', 'rmdir /s /q "%APP%"', 'move "%OLD%" "%APP%" >nul', 'start "" "%APP%\\%EXE%"', ''].join('\r\n'));
      log('önfrissítés: csere indul ' + app.getVersion() + ' → ' + found.v);
      /* 0.18.1: a szkript a programmappán kívülről fusson – a saját munkakönyvtárát a Windows nem engedi átnevezni */
      runCmd(script, [String(process.pid), t, NEW, ok, exe]);
    }
    /* 0.19.10: a szkript a régi folyamat után minden más futó Crazy Booth-példányt is leállít – egy második (régi)
       példány fogta az egypéldányos zárat, így az új változat azonnal kilépett, és 2 perc múlva visszaállt a régi */
    /* 0.19.8: ha a program 3 mp alatt nem zár be magától (pl. egy háttérfolyamat tartja), kényszerített kilépés */
    setTimeout(() => { app.quit(); setTimeout(() => app.exit(0), 3000); }, 400);
    return true;
  }

  /* 0.18.3: kis frissítés – csak a resources/app.asar cseréje (a motor, a digiCamControl és minden más marad) */
  function installSmall() {
    const res = process.resourcesPath, cur = path.join(res, 'app.asar');
    if (!writable(cur)) throw new Error('A program mappája nem írható (' + res + ').');
    const tmp = path.join(dir(), 'x-' + found.v); fs.rmSync(tmp, { recursive: true, force: true }); fs.mkdirSync(tmp, { recursive: true });
    if (MAC) execFileSync('/usr/bin/ditto', ['-x', '-k', file, tmp]); else execFileSync('tar', ['-xf', file, '-C', tmp], { windowsHide: true });
    const na = path.join(tmp, 'app.asar'); if (!fs.existsSync(na)) throw new Error('A csomagban nincs programkód.');
    const NEW = cur + '.new'; try { fs.rmSync(NEW, { force: true }); } catch (e) { /* */ } fs.copyFileSync(na, NEW);
    /* 0.19.0: a csomagban lévő kamerakezelő-fájlok (dcc/cbcam.exe …) „.new” néven a helyükre; induláskor lépnek életbe */
    const nd = path.join(tmp, 'dcc');
    if (WIN && fs.existsSync(nd) && fs.existsSync(path.join(res, 'dcc'))) { for (const n of fs.readdirSync(nd)) { try { fs.copyFileSync(path.join(nd, n), path.join(res, 'dcc', n + '.new')); } catch (e) { log('önfrissítés: ' + n + ' nem másolható: ' + e.message); } } }
    const ok = okFile(found.v); try { fs.unlinkSync(ok); } catch (e) { /* */ }
    let script;
    if (MAC) {
      const appDir = target();
      script = path.join(dir(), 'csere-app.sh');
      fs.writeFileSync(script, ['#!/bin/bash', 'PID="$1"; CUR="$2"; NEW="$3"; OK="$4"; APP="$5"',
        'cd /tmp; while kill -0 "$PID" 2>/dev/null; do sleep 1; done; sleep 1',
        'rm -f "$CUR.old"; mv "$CUR" "$CUR.old" || { open "$APP"; exit 1; }',
        'mv "$NEW" "$CUR" || { mv "$CUR.old" "$CUR"; open "$APP"; exit 1; }',
        'xattr -cr "$APP" 2>/dev/null; codesign --force --deep --sign - "$APP" 2>/dev/null',
        'open "$APP"',
        'for i in $(seq 1 120); do if [ -f "$OK" ]; then rm -f "$CUR.old"; exit 0; fi; sleep 1; done',
        'pkill -f "$APP/Contents/MacOS/" 2>/dev/null; sleep 2; rm -f "$CUR"; mv "$CUR.old" "$CUR"; codesign --force --deep --sign - "$APP" 2>/dev/null; open "$APP"', ''].join('\n'), { mode: 0o755 });
      log('önfrissítés (kis csomag): csere indul ' + app.getVersion() + ' → ' + found.v);
      spawn('/bin/bash', [script, String(process.pid), cur, NEW, ok, appDir], { cwd: dir(), detached: true, stdio: 'ignore' }).unref();
    } else {
      const exe = process.execPath, im = path.basename(exe);
      script = path.join(dir(), 'csere-app.cmd');
      fs.writeFileSync(script, ['@echo off', 'setlocal', 'set "PID=%~1"', 'set "CUR=%~2"', 'set "NEW=%~3"', 'set "OK=%~4"', 'set "EXE=%~5"', 'set "IM=%~6"', 'cd /d "%TEMP%"',
        'set /a W=0', ':wait', 'set /a W+=1', 'if %W% GEQ 30 (taskkill /F /PID %PID% >nul 2>&1 & timeout /t 2 /nobreak >nul & goto waited)', 'tasklist /FI "PID eq %PID%" 2>nul | find "%PID%" >nul && (timeout /t 1 /nobreak >nul & goto wait)', ':waited', 'taskkill /F /IM "%IM%" >nul 2>&1', 'timeout /t 2 /nobreak >nul',
        'if exist "%CUR%.old" del /f /q "%CUR%.old"',
        'set /a M=0', ':mv', 'move /y "%CUR%" "%CUR%.old" >nul && goto moved', 'set /a M+=1', 'if %M% GEQ 15 (start "" "%EXE%" & exit /b 1)', 'timeout /t 2 /nobreak >nul', 'goto mv', ':moved',
        'move /y "%NEW%" "%CUR%" >nul || (move /y "%CUR%.old" "%CUR%" >nul & start "" "%EXE%" & exit /b 1)',
        'start "" "%EXE%"',
        'set /a N=0', ':chk', 'if exist "%OK%" (del /f /q "%CUR%.old" & exit /b 0)', 'set /a N+=1', 'if %N% GEQ 120 goto back', 'timeout /t 1 /nobreak >nul', 'goto chk',
        ':back', 'taskkill /F /IM "%IM%" >nul 2>&1', 'timeout /t 2 /nobreak >nul', 'del /f /q "%CUR%"', 'move /y "%CUR%.old" "%CUR%" >nul', 'start "" "%EXE%"', ''].join('\r\n'));
      log('önfrissítés (kis csomag): csere indul ' + app.getVersion() + ' → ' + found.v);
      runCmd(script, [String(process.pid), cur, NEW, ok, exe, im]);
    }
    /* 0.19.8: ha a program 3 mp alatt nem zár be magától (pl. egy háttérfolyamat tartja), kényszerített kilépés */
    setTimeout(() => { app.quit(); setTimeout(() => app.exit(0), 3000); }, 400);
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
