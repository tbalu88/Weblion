/* Crazy Booth – főfolyamat: ablak (teljes képernyő), beállítások, kamera, mentés, nyomtatás, napló. */
const { app, BrowserWindow, ipcMain, globalShortcut } = require('electron');
const fs = require('fs');
const path = require('path');

const MOCK = process.argv.includes('--mock');
const CFG_FILE = () => path.join(app.getPath('userData'), 'config.json');
const LOG_FILE = () => path.join(app.getPath('userData'), 'booth.log');
const DEF = {
  pin: '2580',
  camera: MOCK ? 'mock' : 'dcc',      /* dcc = digiCamControl (D5300), mock = próbamód */
  dccUrl: 'http://127.0.0.1:5513',
  countdown: 3,                       /* mp */
  shots: 1,                           /* 1 kép vagy 3 képes csík */
  copies: 1,
  printer: '',                        /* üres = az alapértelmezett nyomtató */
  autoPrint: false,
  reviewSec: 25                       /* ennyi mp után vissza a kezdőképernyőre */
};
let cfg = Object.assign({}, DEF);
let cam = null;
let win = null;

function log(m) {
  const line = new Date().toISOString() + ' ' + String(m).replace(/\s+/g, ' ').slice(0, 2000) + '\n';
  try { fs.appendFileSync(LOG_FILE(), line); } catch (e) { /* */ }
}
function loadCfg() {
  try { cfg = Object.assign({}, DEF, JSON.parse(fs.readFileSync(CFG_FILE(), 'utf8'))); } catch (e) { cfg = Object.assign({}, DEF); }
  if (MOCK) cfg.camera = 'mock';
}
function saveCfg() { fs.mkdirSync(path.dirname(CFG_FILE()), { recursive: true }); fs.writeFileSync(CFG_FILE(), JSON.stringify(cfg, null, 2)); }
function dayDir() {
  const d = new Date(), p = n => String(n).padStart(2, '0');
  return path.join(app.getPath('pictures'), 'CrazyBooth', d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()));
}
function makeCam() {
  const folder = path.join(dayDir(), 'nyers');
  cam = (cfg.camera === 'dcc' ? require('./camera/dcc') : require('./camera/mock')).make({ url: cfg.dccUrl, folder });
}
const toDataUrl = (buf, mime) => 'data:' + (mime || 'image/jpeg') + ';base64,' + buf.toString('base64');
const fromDataUrl = s => { const m = /^data:([^;]+);base64,(.*)$/s.exec(String(s || '')); if (!m) throw new Error('hibás kép'); return { mime: m[1], buf: Buffer.from(m[2], 'base64') }; };
const wrap = fn => async (...a) => { try { return { ok: true, v: await fn(...a) }; } catch (e) { log('HIBA ' + (e && e.stack || e)); return { ok: false, err: String(e && e.message || e) }; } };

/* ---------- IPC */
ipcMain.handle('cfg:get', wrap(async () => Object.assign({}, cfg, { mock: MOCK, version: app.getVersion() })));
ipcMain.handle('cfg:set', wrap(async c => {
  const keep = Object.keys(DEF);
  keep.forEach(k => { if (c && c[k] !== undefined) cfg[k] = c[k]; });
  saveCfg(); makeCam(); return cfg;
}));
ipcMain.handle('cam:init', wrap(async () => { if (!cam) makeCam(); return cam.init(); }));
ipcMain.handle('cam:liveStart', wrap(async () => cam.liveStart()));
ipcMain.handle('cam:liveStop', wrap(async () => cam.liveStop()));
ipcMain.handle('cam:liveFrame', wrap(async () => { const b = await cam.liveFrame(); return b ? toDataUrl(b) : null; }));
ipcMain.handle('cam:capture', wrap(async () => { const f = await cam.capture(); log('fotó: ' + f); return f ? toDataUrl(fs.readFileSync(f)) : null; }));
ipcMain.handle('shot:save', wrap(async dataUrl => {
  const { mime, buf } = fromDataUrl(dataUrl);
  const dir = dayDir(); fs.mkdirSync(dir, { recursive: true });
  const d = new Date(), p = n => String(n).padStart(2, '0');
  const f = path.join(dir, 'crazy_' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds()) + '_' + Math.random().toString(36).slice(2, 6) + (mime === 'image/png' ? '.png' : '.jpg'));
  fs.writeFileSync(f, buf); log('mentve: ' + f); return f;
}));
ipcMain.handle('print:list', wrap(async () => (await win.webContents.getPrintersAsync()).map(p => ({ name: p.name, def: !!p.isDefault }))));
/* nyomtatás: 4×6" (DNP DS620); a 2×6"-os csíkvágást a nyomtató-meghajtó papírmérete adja („PR (4x6) 2inch cut”) */
ipcMain.handle('print:do', wrap(async (dataUrl, o) => {
  o = o || {};
  const pw = new BrowserWindow({ show: false, webPreferences: { offscreen: true } });
  const html = '<!doctype html><html><head><style>@page{size:4in 6in;margin:0}html,body{margin:0;padding:0}img{display:block;width:4in;height:6in;object-fit:cover}</style></head><body><img src="' + dataUrl + '"></body></html>';
  await pw.loadURL('data:text/html;base64,' + Buffer.from(html).toString('base64'));
  const copies = Math.max(1, Math.min(10, Number(o.copies || cfg.copies) || 1));
  const res = await new Promise(res => pw.webContents.print({ silent: true, printBackground: true, deviceName: o.printer || cfg.printer || undefined, copies, margins: { marginType: 'none' }, pageSize: { width: 101600, height: 152400 } }, (ok, why) => res({ ok, why })));
  pw.destroy();
  log('nyomtatás: ' + (res.ok ? 'ok' : 'hiba ' + res.why) + ' x' + copies);
  if (!res.ok) throw new Error('A nyomtatás nem sikerült: ' + (res.why || 'ismeretlen hiba'));
  return true;
}));
ipcMain.handle('log', wrap(async m => log('felület: ' + m)));
ipcMain.handle('app:quit', wrap(async () => app.quit()));

/* ---------- ablak */
app.whenReady().then(() => {
  loadCfg(); makeCam(); log('indítás ' + app.getVersion() + (MOCK ? ' (próbamód)' : ''));
  win = new BrowserWindow({
    fullscreen: !MOCK, kiosk: !MOCK, width: 1280, height: 800, backgroundColor: '#0E1220', autoHideMenuBar: true,
    webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false }
  });
  win.loadFile(path.join(__dirname, 'renderer', 'index.html'));
  globalShortcut.register('CommandOrControl+Shift+Q', () => app.quit());
  /* a kijelző ne aludjon el rendezvény közben */
  require('electron').powerSaveBlocker.start('prevent-display-sleep');
});
app.on('window-all-closed', () => app.quit());
process.on('uncaughtException', e => log('KRITIKUS ' + (e && e.stack || e)));
