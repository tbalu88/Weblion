/* 0.19.18: a Windows-nyomtatás PowerShell-szkriptjének próbája a CI-ben (windows-latest).
   A szkriptet a main.js-ből veszi; fekvő próbaképet nyomtat a „Microsoft Print to PDF” nyomtatóra fájlba, ha van ilyen;
   ha nincs, legalább a szkript lefutását és a hibaágat (nem létező nyomtató) ellenőrzi. */
const fs = require('fs'), path = require('path'), os = require('os'), { execFileSync } = require('child_process');
const src = fs.readFileSync(path.join(__dirname, '..', 'src', 'main.js'), 'utf8');
const m = /const WINPRINT_PS = (\[[\s\S]*?\])\.join\('\\r\\n'\);/.exec(src); if (!m) throw new Error('WINPRINT_PS nem található');
const PS = eval(m[1]).join('\r\n');
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cb print ')), ps1 = path.join(dir, 'cbprint.ps1'), img = path.join(dir, 'próba kép.jpg'), pdf = path.join(dir, 'ki.pdf');
fs.writeFileSync(ps1, '﻿' + PS, 'utf8');
/* fekvő próbakép: bal fele piros, jobb fele kék */
execFileSync('powershell.exe', ['-NoProfile', '-Command', 'Add-Type -AssemblyName System.Drawing; $b = New-Object System.Drawing.Bitmap 1800,1200; $g = [System.Drawing.Graphics]::FromImage($b); $g.FillRectangle([System.Drawing.Brushes]::Red, 0, 0, 900, 1200); $g.FillRectangle([System.Drawing.Brushes]::Blue, 900, 0, 900, 1200); $b.Save($env:CB_IMG, [System.Drawing.Imaging.ImageFormat]::Jpeg)'], { env: Object.assign({}, process.env, { CB_IMG: img }) });
const run = prn => execFileSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', ps1], { env: Object.assign({}, process.env, { CB_IMG: img, CB_PRN: prn, CB_COPIES: '1', CB_FIT: 'cover', CB_SCALE: '1', CB_DX: '0', CB_DY: '0', CB_FILE: pdf }), encoding: 'utf8' });
let bad = false; try { run('Nincs Ilyen Nyomtató 123'); bad = true; } catch (e) { if (!/nincs ilyen nyomtató/.test(String(e.stderr || e.message))) { console.log(String(e.stderr || e.message)); throw new Error('a hibaág nem a várt üzenetet adta'); } }
if (bad) throw new Error('nem létező nyomtatóra is sikert jelzett');
console.log('hibaág: rendben');
const list = execFileSync('powershell.exe', ['-NoProfile', '-Command', 'Get-Printer | Select-Object -ExpandProperty Name'], { encoding: 'utf8' });
console.log('nyomtatók:', list.trim().split(/\r?\n/).join(', '));
if (/Microsoft Print to PDF/.test(list)) {
  const out = run('Microsoft Print to PDF'); console.log('kimenet:', out.trim());
  if (!/^lap: /m.test(out)) throw new Error('nincs lapinformáció');
  for (let i = 0; i < 30 && !(fs.existsSync(pdf) && fs.statSync(pdf).size > 0); i++) execFileSync('powershell.exe', ['-NoProfile', '-Command', 'Start-Sleep -Milliseconds 500']);
  if (!fs.existsSync(pdf) || !fs.statSync(pdf).size) throw new Error('nem készült PDF');
  console.log('PDF: ' + fs.statSync(pdf).size + ' bájt – rendben');
} else console.log('FIGYELEM: nincs PDF-nyomtató, csak a hibaág ellenőrizve');
