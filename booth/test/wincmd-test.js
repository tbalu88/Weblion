/* Windows-próba (CI): a self-update.js-ben használt wincmd.run-nal indított szkript lefut-e szóközös, ékezetes útvonalból,
   megkapja-e a (szintén szóközös) paramétereit, és a rejtett konzolban futnak-e a belső parancsai (tasklist | find).
   Kilépési kód: 0 = jó. */
const fs = require('fs'), path = require('path'), os = require('os');
const { run } = require('../src/wincmd');
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'Crazy Booth próba gép '));
const script = path.join(dir, 'csere probe.cmd'), out = path.join(dir, 'eredmeny.txt');
fs.writeFileSync(script, ['@echo off', 'set "A=%~1"', 'set "B=%~2"', 'tasklist /FI "PID eq %A%" 2>nul | find "%A%" >nul', 'echo %A%^|%B%> "%~3"', ''].join('\r\n'));
const how = run(script, [String(process.pid), 'C:\\Program Files\\Crazy Booth\\resources\\app.asar', out], dir);
console.log('indítás módja:', how);
let n = 0; const t = setInterval(() => {
  if (fs.existsSync(out)) { const v = fs.readFileSync(out, 'utf8').trim(); console.log('szkript lefutott, paraméterek:', v); clearInterval(t); process.exit(v === process.pid + '|C:\\Program Files\\Crazy Booth\\resources\\app.asar' && how === 'vbs' ? 0 : 2); }
  if (++n > 60) { console.log('A SZKRIPT NEM FUTOTT LE'); clearInterval(t); process.exit(1); }
}, 250);
