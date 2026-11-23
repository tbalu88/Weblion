/* Windows-próba (CI): a self-update.js runCmd-jével azonos módon indított szkript lefut-e szóközös útvonalból, és megkapja-e
   a (szintén szóközös) paramétereit. Kilépési kód: 0 = jó. */
const fs = require('fs'), path = require('path'), os = require('os'), { spawn } = require('child_process');
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'Crazy Booth probe '));
const script = path.join(dir, 'csere probe.cmd'), out = path.join(dir, 'eredmeny.txt');
fs.writeFileSync(script, ['@echo off', 'set "A=%~1"', 'set "B=%~2"', 'echo %A%^|%B%> "%~3"', ''].join('\r\n'));
const q = a => '"' + String(a).replace(/"/g, '') + '"';
const line = '"' + [script, '1234', 'C:\\Program Files\\Crazy Booth\\resources\\app.asar', out].map(q).join(' ') + '"';
spawn(process.env.ComSpec || 'cmd.exe', ['/d', '/s', '/c', line], { cwd: dir, detached: true, stdio: 'ignore', windowsHide: true, windowsVerbatimArguments: true }).unref();
let n = 0; const t = setInterval(() => {
  if (fs.existsSync(out)) { const v = fs.readFileSync(out, 'utf8').trim(); console.log('szkript lefutott, paraméterek:', v); clearInterval(t); process.exit(v === '1234|C:\\Program Files\\Crazy Booth\\resources\\app.asar' ? 0 : 2); }
  if (++n > 40) { console.log('A SZKRIPT NEM FUTOTT LE'); clearInterval(t); process.exit(1); }
}, 250);
