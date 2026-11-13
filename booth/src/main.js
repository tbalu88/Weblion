/* Crazy Booth – főfolyamat: ablak (teljes képernyő), beállítások, kamera, mentés, nyomtatás, napló. */
const { app, BrowserWindow, ipcMain, globalShortcut, systemPreferences, session } = require('electron');
const fs = require('fs');
const path = require('path');

const MOCK = process.argv.includes('--mock');
const MAC = process.platform === 'darwin';
const CFG_FILE = () => path.join(app.getPath('userData'), 'config.json');
const LOG_FILE = () => path.join(app.getPath('userData'), 'booth.log');
const DEF = {
  pin: '2580',
  camera: MOCK || MAC ? 'webcam' : 'dcc',   /* dcc = Nikon D5300 digiCamControllal (Windows), webcam = webkamera (Mac / PC) */
  camId: '',                          /* a választott webkamera (üres = az első) */
  camRes: '1080',                     /* 720 | 1080 | 2160 */
  mirror: true,                       /* az élőkép tükrözve (mint egy tükör); a mentett kép sosem tükrözött */
  dccUrl: 'http://127.0.0.1:5513',
  countdown: 3,                       /* mp */
  shots: 1,                           /* 1 kép vagy 3 képes csík */
  copies: 1,
  printer: '',                        /* üres = az alapértelmezett nyomtató */
  autoPrint: false,
  reviewSec: 25,                      /* ennyi mp után vissza a kezdőképernyőre */
  boothKey: '',                       /* a panelben (Beállítások → Crazy Booth gépek) kapott gépkulcs */
  event: null,                        /* a kiválasztott rendezvény: { deal, title, date, ev, template } – null = kézi */
  ui: {}                              /* a felület saját beállításai (módok, időzítések…) – az online felület bővítheti új program nélkül */
};
let cfg = Object.assign({}, DEF);
let cam = null;
let win = null;
let cloud = null;
let ui = null, uiPending = false;
let lan = null;

function log(m) {
  const line = new Date().toISOString() + ' ' + String(m).replace(/\s+/g, ' ').slice(0, 2000) + '\n';
  try { fs.appendFileSync(LOG_FILE(), line); } catch (e) { /* */ }
}
function loadCfg() {
  try { cfg = Object.assign({}, DEF, JSON.parse(fs.readFileSync(CFG_FILE(), 'utf8'))); } catch (e) { cfg = Object.assign({}, DEF); }
  if (cfg.camera === 'mock') cfg.camera = 'webcam';            /* a 0.1-es próbamód neve */
  if (MOCK || (MAC && cfg.camera === 'dcc')) cfg.camera = 'webcam'; /* a digiCamControl csak Windowson fut */
}
function saveCfg() { fs.mkdirSync(path.dirname(CFG_FILE()), { recursive: true }); fs.writeFileSync(CFG_FILE(), JSON.stringify(cfg, null, 2)); }
function dayDir() {
  const d = new Date(), p = n => String(n).padStart(2, '0');
  return path.join(app.getPath('pictures'), 'CrazyBooth', d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()));
}
function makeCam() {
  const folder = path.join(dayDir(), 'nyers');
  cam = (cfg.camera === 'dcc' ? require('./camera/dcc') : require('./camera/webcam')).make({ url: cfg.dccUrl, folder });
}
const toDataUrl = (buf, mime) => 'data:' + (mime || 'image/jpeg') + ';base64,' + buf.toString('base64');
const fromDataUrl = s => { const m = /^data:([^;]+);base64,(.*)$/s.exec(String(s || '')); if (!m) throw new Error('hibás kép'); return { mime: m[1], buf: Buffer.from(m[2], 'base64') }; };
/* az ipcMain.handle első paramétere az esemény – a felület adatai utána jönnek */
const wrap = fn => async (ev, ...a) => { try { return { ok: true, v: await fn(...a) }; } catch (e) { log('HIBA ' + (e && e.stack || e)); return { ok: false, err: String(e && e.message || e) }; } };

/* ---------- IPC */
ipcMain.handle('cfg:get', wrap(async () => Object.assign({}, cfg, { mock: MOCK, mac: MAC, version: app.getVersion() })));
ipcMain.handle('cfg:set', wrap(async c => {
  const keep = Object.keys(DEF);
  keep.forEach(k => { if (c && c[k] !== undefined) cfg[k] = c[k]; });
  saveCfg(); makeCam(); return cfg;
}));
ipcMain.handle('cam:init', wrap(async () => { if (!cam) makeCam(); return cam.init(); }));
/* v0.9: DSLR-beállítások (ISO, rekesz, záridő, expozíció-korrekció, fehéregyensúly) – digiCamControl */
ipcMain.handle('cam:props', wrap(async () => { if (!cam) makeCam(); if (!cam.props) throw new Error('Ehhez a kamerához nincs beállítás (csak DSLR, digiCamControl).'); return cam.props(); }));
ipcMain.handle('cam:set', wrap(async (p, v) => { if (!cam) makeCam(); if (!cam.setProp) throw new Error('Ehhez a kamerához nincs beállítás (csak DSLR, digiCamControl).'); return cam.setProp(p, v); }));
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
/* v0.6: a nyomtató állapota is (Mac/CUPS: printer-state 3 tétlen, 4 nyomtat, 5 leállt; printer-state-reasons pl. media-empty, offline-report) */
ipcMain.handle('print:list', wrap(async () => (await win.webContents.getPrintersAsync()).map(p => { const op = p.options || {}; return { name: p.name, def: !!p.isDefault, status: p.status, state: op['printer-state'] || '', reasons: op['printer-state-reasons'] || '' }; })));
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
/* panel + galéria */
ipcMain.handle('cloud:events', wrap(async date => cloud.events(date)));
ipcMain.handle('cloud:template', wrap(async deal => cloud.template(deal)));
ipcMain.handle('cloud:keep', wrap(async (ev, deal, date, photo, dataUrl, meta) => cloud.keep(ev, deal, date, photo, dataUrl, meta)));
ipcMain.handle('cloud:list', wrap(async ev => cloud.list(ev)));
ipcMain.handle('cloud:guest', wrap(async d => cloud.guest(d)));
ipcMain.handle('cloud:beat', wrap(async d => cloud.beat(d)));
ipcMain.handle('cloud:ai', wrap(async d => cloud.ai(d)));
/* v0.7: AirDrop / megosztás (Mac: a rendszer megosztás-menüje); helyi Wi-Fi-s QR-szerver */
ipcMain.handle('share:mac', wrap(async f => { if (!MAC) throw new Error('Csak Macen'); const { ShareMenu } = require('electron'); if (!fs.existsSync(f)) throw new Error('A fájl nem található'); new ShareMenu({ filePaths: [f] }).popup({ window: win }); return true; }));
ipcMain.handle('lan:start', wrap(async port => lan.start(port)));
ipcMain.handle('lan:info', wrap(async () => lan.info()));
ipcMain.handle('cloud:printq', wrap(async (method, p, body) => cloud.printq(method, p, body)));
ipcMain.handle('cloud:read', wrap(async f => cloud.readImage(f)));
ipcMain.handle('cloud:qr', wrap(async url => cloud.qr(url)));
ipcMain.handle('cloud:status', wrap(async () => cloud.status()));
ipcMain.handle('cloud:tok', wrap(async n => cloud.newTok(n || 10)));
ipcMain.handle('cloud:urls', wrap(async (ev, ph) => ({ photo: ph ? cloud.photoUrl(ev, ph) : '', event: cloud.eventUrl(ev) })));
ipcMain.handle('app:quit', wrap(async () => app.quit()));
/* teljes képernyő ki / be (a kezdőképernyő sarokgombja) */
/* online felület: újratöltés, ha közben új változat érkezett (a felület hívja, amikor a kezdőképernyőn tétlen) */
ipcMain.handle('ui:reload', wrap(async () => { const e = ui.entry(); uiPending = false; log('felület betöltése: ' + e.v); await win.loadFile(e.file); return e.v; }));
ipcMain.handle('ui:info', wrap(async () => Object.assign(ui.entry(), { pending: uiPending, shell: app.getVersion() })));
ipcMain.handle('win:isFull', wrap(async () => win.isFullScreen() || win.isKiosk()));
ipcMain.handle('win:full', wrap(async on => { const f = on === undefined ? !(win.isFullScreen() || win.isKiosk()) : !!on; win.setKiosk(f); win.setFullScreen(f); return f; }));

/* ---------- ablak */
app.whenReady().then(() => {
  loadCfg(); makeCam();
  lan = require('./lan').make({ picDir: () => path.join(app.getPath('pictures'), 'CrazyBooth'), log });
  cloud = require('./cloud').make({ dataDir: app.getPath('userData'), picDir: () => path.join(app.getPath('pictures'), 'CrazyBooth'), key: () => cfg.boothKey, log });
  log('indítás ' + app.getVersion() + ' ' + process.platform + (MOCK ? ' (próbamód)' : ''));
  /* webkamera: Macen a rendszer engedélyt kér (Adatvédelem → Kamera); a felületnek a kamera / mikrofon kérését engedjük */
  if (MAC && systemPreferences.askForMediaAccess) { systemPreferences.askForMediaAccess('camera').then(ok => log('kamera-engedély: ' + ok)).catch(() => {}); }
  session.defaultSession.setPermissionRequestHandler((wc, perm, cb) => cb(perm === 'media'));
  session.defaultSession.setPermissionCheckHandler((wc, perm) => perm === 'media');
  win = new BrowserWindow({
    fullscreen: !MOCK, kiosk: !MOCK, width: 1280, height: 800, backgroundColor: '#0E1220', autoHideMenuBar: true,
    webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false }
  });
  /* v0.9.1: a panel (pl. a sablonszerkesztő) a program fölött, keretes ablakban nyílik – bezárva vissza a programba; más címet a böngésző nyit meg */
  win.webContents.setWindowOpenHandler(({ url }) => {
    if (!/^https:\/\/(eventcrazy\.hu|tartalek\.eventcrazy\.hu)\//.test(url)) { require('electron').shell.openExternal(url).catch(() => {}); return { action: 'deny' }; }
    const wa = require('electron').screen.getDisplayMatching(win.getBounds()).workArea;
    return { action: 'allow', overrideBrowserWindowOptions: { parent: win, modal: false, fullscreenable: false, autoHideMenuBar: true, title: 'Crazy panel', backgroundColor: '#F5F3EF',
      x: wa.x + Math.round(wa.width * 0.03), y: wa.y + Math.round(wa.height * 0.04), width: Math.round(wa.width * 0.94), height: Math.round(wa.height * 0.92),
      webPreferences: { contextIsolation: true, nodeIntegration: false, sandbox: true } } };
  });
  win.webContents.on('did-create-window', child => { child.on('closed', () => { if (!win.isDestroyed()) win.webContents.send('child:closed'); }); });
  /* online felület: induláskor rövid (4 mp) frissítés-próba, utána a legfrissebb elérhető felület; 15 percenként újra */
  ui = require('./ui-update').make({ dataDir: app.getPath('userData'), bundled: path.join(__dirname, 'renderer', 'index.html'), shellVersion: app.getVersion(), log });
  const boot = async () => { if (!MOCK || process.argv.includes('--online')) { try { await ui.check(4000); } catch (e) { /* */ } } const e = (MOCK && !process.argv.includes('--online')) ? { file: path.join(__dirname, 'renderer', 'index.html'), v: 'helyi (fejlesztői)' } : ui.entry(); log('felület: ' + e.v); win.loadFile(e.file); };
  boot();
  setInterval(async () => { if (MOCK && !process.argv.includes('--online')) return; if (await ui.check(15000)) { uiPending = true; win.webContents.send('ui:ready'); } }, 15 * 60 * 1000);
  globalShortcut.register('CommandOrControl+Shift+Q', () => app.quit());
  /* a kijelző ne aludjon el rendezvény közben */
  require('electron').powerSaveBlocker.start('prevent-display-sleep');
});
app.on('window-all-closed', () => app.quit());
process.on('uncaughtException', e => log('KRITIKUS ' + (e && e.stack || e)));
