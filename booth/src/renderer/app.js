/* Crazy Booth – felület: kezdő → élőkép + visszaszámlálás → fotó(k) → előnézet → nyomtatás. */
(function () {
  'use strict';
  const B = window.booth;
  const $ = s => document.querySelector(s);
  const S = { cfg: null, scr: 'start', busy: false, shots: [], copies: 1, lvT: 0, stream: null, idleT: 0 };
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const call = async (fn, ...a) => { const r = await fn(...a); if (!r || !r.ok) throw new Error((r && r.err) || 'ismeretlen hiba'); return r.v; };

  function show(s) { document.querySelectorAll('.scr').forEach(x => x.classList.toggle('on', x.dataset.s === s)); S.scr = s; if (s === 'start' && S.uiNew) uiSwap(); }
  /* online felület: ha új változat jött, a kezdőképernyőn (tétlenül) töltjük újra */
  function uiSwap() { if (S.busy || S.scr !== 'start' || !B.reloadUi) return; S.uiNew = false; setTimeout(() => { if (!S.busy && S.scr === 'start') B.reloadUi(); else S.uiNew = true; }, 1500); }
  if (B.onUiReady) B.onUiReady(() => { S.uiNew = true; uiSwap(); });
  function err(m) { const e = $('[data-err]'); e.textContent = m; e.hidden = false; clearTimeout(err.t); err.t = setTimeout(() => { e.hidden = true; }, 6000); B.log('hiba: ' + m); }
  const today = () => { const d = new Date(), p = n => String(n).padStart(2, '0'); return d.getFullYear() + '.' + p(d.getMonth() + 1) + '.' + p(d.getDate()) + '.'; };

  /* ---------- élőkép */
  const WEB = () => S.cfg.camera !== 'dcc';
  /* webkamera (Mac / PC): a választott kamera, a kért felbontásban; a folyam nyitva marad a fotózások között (gyorsabb indulás) */
  async function webStream() {
    const h = Number(S.cfg.camRes) || 1080, w = Math.round(h * 16 / 9);
    const key = (S.cfg.camId || '') + '|' + h;
    if (S.stream && S.streamKey === key && S.stream.getVideoTracks().some(t => t.readyState === 'live')) return S.stream;
    if (S.stream) S.stream.getTracks().forEach(t => t.stop());
    S.stream = null;
    const v = { width: { ideal: w }, height: { ideal: h } };
    if (S.cfg.camId) v.deviceId = { exact: S.cfg.camId };
    try { S.stream = await navigator.mediaDevices.getUserMedia({ video: v, audio: false }); }
    catch (e) {
      if (S.cfg.camId && e.name === 'OverconstrainedError') { delete v.deviceId; S.stream = await navigator.mediaDevices.getUserMedia({ video: v, audio: false }).catch(() => null); }
      if (!S.stream) throw new Error(e.name === 'NotAllowedError' ? 'Nincs engedély a kamerához – Macen: Rendszerbeállítások → Adatvédelem és biztonság → Kamera → Crazy Booth bekapcsolása.' : 'A webkamera nem indul (' + (e.message || e.name) + ').');
    }
    S.streamKey = key;
    const st = S.stream.getVideoTracks()[0].getSettings(); B.log('webkamera: ' + (st.width || '?') + '×' + (st.height || '?'));
    return S.stream;
  }
  async function liveOn() {
    const v = $('[data-video]'), im = $('[data-lvimg]');
    document.querySelector('.lv').classList.toggle('mir', S.cfg.mirror !== false);
    if (WEB()) {
      im.hidden = true; v.hidden = false;
      let st = null;
      try { st = await webStream(); } catch (e) { if (!S.cfg.mock) throw e; }
      if (st) { if (v.srcObject !== st) v.srcObject = st; await v.play().catch(() => {}); }
      else { v.hidden = true; im.hidden = false; im.src = mockFrame(); }
      return;
    }
    v.hidden = true; im.hidden = false;
    await call(B.liveStart);
    const tick = async () => {
      if (S.scr !== 'live') return;
      const r = await B.liveFrame();
      if (r && r.ok && r.v) im.src = r.v;
      S.lvT = setTimeout(tick, 70);
    };
    tick();
  }
  async function liveOff() { clearTimeout(S.lvT); if (!WEB()) await B.liveStop(); }
  function mockFrame() {
    const c = document.createElement('canvas'); c.width = 1500; c.height = 1000; const g = c.getContext('2d');
    const gr = g.createLinearGradient(0, 0, 1500, 1000); gr.addColorStop(0, '#2F4A7A'); gr.addColorStop(1, '#E8894A'); g.fillStyle = gr; g.fillRect(0, 0, 1500, 1000);
    g.fillStyle = '#fff'; g.font = '800 90px "Segoe UI",sans-serif'; g.textAlign = 'center'; g.fillText('PRÓBAMÓD', 750, 480); g.font = '400 44px "Segoe UI",sans-serif'; g.fillText(new Date().toLocaleTimeString('hu-HU'), 750, 560);
    return c.toDataURL('image/jpeg', 0.9);
  }
  /* egy fotó: D5300 → teljes felbontású JPG; próbamód → a webkamera / minta képkockája */
  async function snap() {
    if (WEB()) {
      const v = $('[data-video]'), c = document.createElement('canvas');
      if (S.stream && v.videoWidth) { c.width = v.videoWidth; c.height = v.videoHeight; const g = c.getContext('2d'); g.drawImage(v, 0, 0);   /* a mentett kép nem tükrözött (csak az élőkép) */ return c.toDataURL('image/jpeg', 0.92); }
      return mockFrame();
    }
    return call(B.capture);
  }
  const loadImg = src => new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('A kép nem tölthető be')); i.src = src; });

  /* ---------- fotózás */
  /* megszakítás (✕ gomb / ESC) fotózás közben */
  class Cancel extends Error {}
  const chk = () => { if (S.cancel) throw new Cancel('megszakítva'); };
  const wait = async ms => { const end = Date.now() + ms; while (Date.now() < end) { chk(); await sleep(Math.min(100, end - Date.now())); } chk(); };
  function cancelRun() { if (S.scr === 'live' && S.busy) { S.cancel = true; $('[data-cd]').textContent = ''; } }
  async function run() {
    if (S.busy) return; S.busy = true; S.shots = []; S.cancel = false;
    const n = S.tpl ? S.tpl.shots : (Number(S.cfg.shots) >= 3 ? 3 : 1);
    try {
      show('live'); await liveOn();
      for (let i = 0; i < n; i++) {
        $('[data-shotno]').textContent = n > 1 ? (i + 1) + ' / ' + n : '';
        for (let k = Number(S.cfg.countdown) || 3; k > 0; k--) { $('[data-cd]').textContent = k; await wait(1000); }
        $('[data-cd]').textContent = ''; chk();
        const f = $('[data-flash]'); f.classList.remove('go'); void f.offsetWidth; f.classList.add('go');
        const src = await snap();
        chk();
        S.shots.push(await loadImg(src));
        B.saveShot(src);
        if (i < n - 1) await wait(900);
      }
      await liveOff();
      review();
    } catch (e) {
      await liveOff().catch(() => {});
      $('[data-cd]').textContent = ''; $('[data-shotno]').textContent = '';
      show('start');
      if (!(e instanceof Cancel)) err(e.message);
    } finally { S.busy = false; S.cancel = false; }
  }
  async function review() {
    const cv = $('[data-out]');
    BoothCompose.compose(cv, S.shots, { title: S.cfg.title || 'EventCrazy', date: today(), tpl: S.tpl });
    S.copies = Number(S.cfg.copies) || 1; $('[data-cpn]').textContent = S.copies;
    $('[data-qrbox]').hidden = true;
    show('review');
    /* a kész kép: a rendezvény galériájába (helyben + háttérfeltöltés); QR a letöltő-oldalra */
    const ev = S.cfg.event, photo = (await call(B.newTok, 10));
    S.lastPhoto = photo;
    const data = cv.toDataURL('image/jpeg', 0.92);
    try { await call(B.keep, ev.ev, ev.deal || 0, ev.date || '', photo, data); } catch (e) { err('A kép mentése nem sikerült: ' + e.message); }
    if (S.cfg.boothKey) showQr($('[data-qrbox]'), $('[data-qr]'), ev.ev, photo);
    if (S.cfg.autoPrint) { print(); return; }
    idle();
  }
  function idle() {
    clearTimeout(S.idleT); let left = Number(S.cfg.reviewSec) || 25;
    const t = () => { if (S.scr !== 'review') return; $('[data-rvt]').textContent = 'Vissza a kezdéshez ' + left + ' mp múlva'; if (left-- <= 0) { show('start'); return; } S.idleT = setTimeout(t, 1000); };
    t();
  }
  async function print() {
    clearTimeout(S.idleT);
    show('busy'); $('[data-busyt]').textContent = 'Nyomtatás…';
    try { await call(B.print, BoothCompose.toPrint($('[data-out]')), { copies: S.copies }); $('[data-busyt]').textContent = 'Kész! Vedd ki a fotót a nyomtatóból.'; await sleep(2500); show('start'); }
    catch (e) { show('review'); err(e.message); idle(); }
  }

  /* ---------- beállítások (PIN) */
  let pinBuf = '';
  function openSettings() {
    pinBuf = ''; $('[data-pin]').textContent = ''; $('[data-pinbox]').hidden = false; $('[data-form]').hidden = true; show('settings');
  }
  async function fillForm() {
    const f = $('[data-form]'), c = S.cfg;
    ['camera', 'dccUrl', 'shots', 'countdown', 'copies', 'reviewSec', 'camRes'].forEach(k => { f.elements[k].value = c[k]; });
    f.elements.autoPrint.checked = !!c.autoPrint; f.elements.mirror.checked = c.mirror !== false; f.elements.pin.value = '';
    /* a D5300 (digiCamControl) csak Windowson; Macen csak a webkamera választható */
    f.elements.camera.querySelector('option[value=dcc]').disabled = !!c.mac;
    /* webkamerák listája (a nevek csak engedély után látszanak) */
    let cams = [];
    try { if (WEB()) await webStream().catch(() => null); cams = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'videoinput'); } catch (e) { cams = []; }
    f.elements.camId.innerHTML = '<option value="">(az első / beépített kamera)</option>' + cams.map((d, i) => '<option value="' + d.deviceId.replace(/"/g, '&quot;') + '"' + (d.deviceId === c.camId ? ' selected' : '') + '>' + (d.label || ('Kamera ' + (i + 1))).replace(/</g, '&lt;') + '</option>').join('');
    camRows();
    f.elements.boothKey.value = c.boothKey || '';
    const d0 = (c.event && c.event.date) || new Date().toISOString().slice(0, 10);
    $('[data-evdate]').value = d0; S.evList = null;
    $('[data-evlist]').innerHTML = '<p class="st-msg">' + esc(c.event ? 'Most: ' + c.event.title : '') + '</p>';
    $('[data-upst]').textContent = await upStatus();
    preDraw(); tplInfo();
    const pr = await B.printers(); const sel = $('[data-printers]');
    sel.innerHTML = '<option value="">(alapértelmezett nyomtató)</option>' + ((pr.ok && pr.v) || []).map(p => '<option' + (p.name === c.printer ? ' selected' : '') + '>' + p.name.replace(/</g, '&lt;') + '</option>').join('');
    $('[data-ver]').textContent = 'program v' + c.version + (c.mac ? ' · Mac' : '') + (c.mock ? ' · próbamód' : '');
    if (B.uiInfo) B.uiInfo().then(r => { if (r && r.ok) $('[data-ver]').textContent += ' · felület ' + r.v.v + (r.v.online ? ' (online)' : ''); });
  }
  document.querySelector('.pad').innerHTML = '1 2 3 4 5 6 7 8 9 ⌫ 0 ✓'.split(' ').map(k => '<button type="button" data-k="' + k + '">' + k + '</button>').join('');
  document.querySelector('.pad').addEventListener('click', async e => {
    const k = e.target.dataset.k; if (!k) return;
    if (k === '⌫') pinBuf = pinBuf.slice(0, -1);
    else if (k === '✓') { if (pinBuf === String(S.cfg.pin)) { $('[data-pinbox]').hidden = true; $('[data-form]').hidden = false; await fillForm(); } else { pinBuf = ''; err('Hibás PIN'); } }
    else if (pinBuf.length < 8) pinBuf += k;
    $('[data-pin]').textContent = '•'.repeat(pinBuf.length);
  });
  document.addEventListener('click', async e => {
    const a = e.target.closest('[data-act]'); if (!a) return;
    const act = a.dataset.act;
    if (act === 'print') print();
    else if (act === 'again') { clearTimeout(S.idleT); run(); }
    else if (act === 'done') { clearTimeout(S.idleT); show('start'); }
    else if (act === 'close') { show('start'); }
    else if (act === 'quit') { B.quit(); }
    else if (act === 'cancel') { cancelRun(); }
    else if (act === 'tpledit') { const e = S.cfg.event; if (!e || !e.deal) { $('[data-stmsg]').textContent = 'Előbb válassz rendezvényt (deal), és mentsd el.'; return; } window.open('https://eventcrazy.hu/crazy-panel/#sablon:' + e.deal, '_blank', 'width=1400,height=900'); S.tplEditing = true; $('[data-stmsg]').textContent = 'A panel Sablonkészítője új ablakban nyílt meg. Ott a „Mentés a szelfigéphez” gombbal mentsd el, majd gyere vissza ide.'; }
    else if (act === 'tplreload') { S.cfg.event && (S.cfg.event.template = true); await loadTemplate(); tplInfo(); modeInfo(); $('[data-stmsg]').textContent = S.tpl ? 'Sablon frissítve (' + S.tpl.shots + ' fotó).' : 'Ehhez a rendezvényhez nincs sablon – az alap keret marad.'; }
    else if (act === 'presave') { const n = $('#pre-name').value.trim(); if (!n) { $('[data-stmsg]').textContent = 'Adj nevet a sablonnak.'; return; } const L = preLoad(), cfg = {}; PRE_KEYS.forEach(k => { cfg[k] = S.cfg[k]; }); const ev = S.cfg.event && S.cfg.event.deal ? S.cfg.event : null; const i = L.findIndex(p => p.name === n); const p = { name: n, cfg, event: ev, at: Date.now() }; if (i >= 0) L[i] = p; else L.push(p); preStore(L); $('#pre-name').value = ''; preDraw(); $('[data-stmsg]').textContent = '„' + n + '” elmentve.'; }
    else if (act === 'export') { const cfg = {}; EXP_KEYS.forEach(k => { cfg[k] = S.cfg[k]; }); const blob = new Blob([JSON.stringify({ crazyBooth: 1, at: new Date().toISOString(), cfg, presets: preLoad() }, null, 2)], { type: 'application/json' }); const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'crazy-booth-beallitasok.json'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000); $('[data-stmsg]').textContent = 'Exportálva.'; }
    else if (act === 'gallery') { gallery(); }
    else if (act === 'back') { show('start'); }
    else if (act === 'reprint') { reprint(); }
    else if (act === 'evload') { evLoad(); }
    else if (act === 'fs') { e.stopPropagation(); const r = await B.fullscreen(); if (r && r.ok) a.querySelector('span').textContent = r.v ? 'Teljes képernyő ki' : 'Teljes képernyő be'; }
    else if (act === 'save') {
      const f = $('[data-form]'), o = {};
      ['camera', 'dccUrl', 'printer', 'camId', 'camRes'].forEach(k => { o[k] = f.elements[k].value; });
      o.mirror = f.elements.mirror.checked;
      o.boothKey = f.elements.boothKey.value.trim();
      const pick = f.querySelector('input[name=evpick]:checked');
      if (pick && S.evList) {
        const e = Number(pick.value) >= 0 ? S.evList[Number(pick.value)] : null;
        if (e) o.event = { deal: e.deal, title: e.title, date: e.date, ev: e.ev, template: !!e.template };
        else if (S.cfg.event && S.cfg.event.deal) o.event = null;
      }
      ['shots', 'countdown', 'copies', 'reviewSec'].forEach(k => { o[k] = Number(f.elements[k].value) || S.cfg[k]; });
      o.autoPrint = f.elements.autoPrint.checked;
      if (f.elements.pin.value.trim()) o.pin = f.elements.pin.value.trim();
      try { S.cfg = Object.assign(S.cfg, await call(B.saveConfig, o)); await ensureEvent(); await loadTemplate(); $('[data-stmsg]').textContent = 'Mentve.' + (S.tpl ? ' Sablon betöltve (' + S.tpl.shots + ' fotó).' : ''); await initCam(); modeInfo(); }
      catch (x) { $('[data-stmsg]').textContent = x.message; }
    }
  });
  document.querySelectorAll('[data-cp]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); S.copies = Math.max(1, Math.min(10, S.copies + Number(b.dataset.cp))); $('[data-cpn]').textContent = S.copies; idle(); }));
  /* bal felső sarok 3 mp-ig nyomva / F2 → beállítások */
  let hold = 0;
  $('[data-corner]').addEventListener('pointerdown', () => { hold = setTimeout(openSettings, 3000); });
  ['pointerup', 'pointerleave'].forEach(ev => $('[data-corner]').addEventListener(ev, () => clearTimeout(hold)));
  document.addEventListener('keydown', e => {
    if (e.key === 'F2') openSettings();
    if (e.key === 'Escape') { if (S.scr === 'live') cancelRun(); else if (S.scr === 'settings' || S.scr === 'gallery') show('start'); else if (S.scr === 'photo') gallery(); }
  });
  $('[data-s=start]').addEventListener('click', e => { if (!e.target.closest('[data-act]')) run(); });

  /* v0.3.2: QR inline SVG-ként (sem képbetöltés, sem vászon); hibánál kivétel – a csempe nem jelenik meg */
  function drawQr(el, q) {
    if (!q || !q.n || typeof q.bits !== 'string' || q.bits.length !== q.n * q.n) throw new Error('hibás QR-adat (' + (q && q.n) + '/' + (q && q.bits && q.bits.length) + ')');
    let d = '', ones = 0;
    for (let y = 0; y < q.n; y++) for (let x = 0; x < q.n; x++) if (q.bits.charCodeAt(y * q.n + x) === 49) { d += 'M' + (x + 2) + ' ' + (y + 2) + 'h1v1h-1z'; ones++; }
    if (!ones) throw new Error('üres QR');
    const s = q.n + 4;
    el.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 ' + s + ' ' + s + '" shape-rendering="crispEdges" style="display:block"><rect width="' + s + '" height="' + s + '" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
    /* napló: mekkora lett a QR a képernyőn (hibakereséshez) */
    requestAnimationFrame(() => {
      const sv = el.querySelector('svg'), r = sv ? sv.getBoundingClientRect() : null, pr = el.getBoundingClientRect();
      const info = 'QR ' + q.n + '×' + q.n + ' · ' + ones + ' modul · svg ' + (r ? Math.round(r.width) + '×' + Math.round(r.height) : 'nincs') + ' · doboz ' + Math.round(pr.width) + '×' + Math.round(pr.height) + ' · d ' + d.length;
      B.log('QR kirajzolva: ' + info);
    });
  }
  async function showQr(box, el, ev, photo) {
    box.hidden = true;
    if (box.dataset.err) { box.classList.remove('err'); delete box.dataset.err; const sp = box.querySelector('span'); if (sp) sp.innerHTML = 'Olvasd be a telefonoddal,<br>és töltsd le' + (box.hasAttribute('data-qrbox') ? ' a fotódat!' : '!'); }
    try { const u = await call(B.urls, ev, photo); drawQr(el, await call(B.qr, u.photo)); box.hidden = false; }
    catch (e) {
      B.log('QR hiba: ' + e.message);
      box.hidden = true;   /* hiba esetén nincs csempe (a hiba a naplóban) */
    }
  }
  /* ---------- rendezvény (a panelből) + sablon */
  async function ensureEvent() {
    if (!S.cfg.event || !S.cfg.event.ev) {
      const d = new Date(), p = n => String(n).padStart(2, '0');
      S.cfg.event = { deal: 0, title: 'Kézi (rendezvény nélkül)', date: d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()), ev: 'k' + (await call(B.newTok, 12)), template: false };
      await call(B.saveConfig, { event: S.cfg.event });
    }
  }
  async function loadTemplate() {
    S.tpl = null;
    const e = S.cfg.event;
    if (!e || !e.deal) return;   /* v: a sablon a panelből később is jöhet – mindig megkérdezzük */
    try {
      const t = await call(B.template, e.deal);
      if (!t) return;
      S.tplName = (t.src === 'panel' ? 'panelből mentett' : 'ügyfélportálon mentett') + (t.name ? ': ' + t.name : '') + (t.cached ? ' (offline másolat)' : '');
      const img = await loadImg(t.png);
      const h = BoothCompose.holes(img);
      if (!h.holes.length) { err('A sablonban nem találtam fotóablakot – alap keret marad.'); return; }
      S.tpl = Object.assign({ img }, h);
    } catch (x) { err('Sablon: ' + x.message); }
  }
  async function evLoad() {
    const box = $('[data-evlist]'), msg = $('[data-evmsg]'), date = $('[data-evdate]').value;
    msg.textContent = 'Betöltés…'; box.innerHTML = '';
    try {
      const list = await call(B.events, date);
      const cur = S.cfg.event || {};
      box.innerHTML = list.map((e, i) => '<label><input type="radio" name="evpick" value="' + i + '"' + (cur.deal === e.deal ? ' checked' : '') + '> <span>' + (e.time ? '<b>' + e.time + '</b> ' : '') + esc(e.title) + ' <small>' + esc(e.loc || '') + (e.template ? ' · sablon ✓' : ' · nincs sablon') + '</small></span></label>').join('') +
        '<label><input type="radio" name="evpick" value="-1"' + (!cur.deal ? ' checked' : '') + '> <span>Kézi (rendezvény nélkül)</span></label>';
      S.evList = list.map(e => Object.assign({ date }, e));
      msg.textContent = list.length ? '' : 'Erre a napra nincs foglalt rendezvény.';
    } catch (x) { msg.textContent = x.message; S.evList = []; box.innerHTML = '<label><input type="radio" name="evpick" value="-1" checked> <span>Kézi (rendezvény nélkül)</span></label>'; }
  }
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  /* ---------- galéria a gépen */
  async function gallery() {
    clearTimeout(S.idleT);
    const ev = S.cfg.event; show('gallery');
    $('[data-glinfo]').textContent = ev.title || '';
    const grid = $('[data-glgrid]');
    const L = (await B.list(ev.ev)).v || [];
    grid.innerHTML = L.length ? L.map(p => '<button type="button" data-ph="' + p.photo + '" data-f="' + esc(p.file) + '" style="background-image:url(\'' + encodeURI('file://' + p.file.replace(/\\/g, '/')).replace(/'/g, '%27') + '\')"></button>').join('') : '<p>Még nincs kép ezen a rendezvényen.</p>';
    grid.querySelectorAll('[data-ph]').forEach(b => { const i = new Image(); i.onload = () => b.classList.toggle('land', i.naturalWidth > i.naturalHeight); i.src = b.style.backgroundImage.slice(5, -2); });
    const q = $('[data-glqr]'); q.innerHTML = '';
    if (S.cfg.boothKey) { try { const u = await call(B.urls, ev.ev); q.innerHTML = '<div class="qrc"></div><span>Az összes fotó<br>a telefonodon</span>'; drawQr(q.querySelector('.qrc'), await call(B.qr, u.event)); } catch (e) { /* */ } }
  }
  async function openPhoto(photo, file) {
    const src = await call(B.readImage, file);
    $('[data-phbig]').src = src; S.photoSrc = src; S.lastPhoto = photo;
    $('[data-phqrbox]').hidden = true;
    show('photo');
    if (S.cfg.boothKey) showQr($('[data-phqrbox]'), $('[data-phqr]'), S.cfg.event.ev, photo);
  }
  async function reprint() {
    const img = await loadImg(S.photoSrc), c = document.createElement('canvas'); c.width = img.naturalWidth; c.height = img.naturalHeight; c.getContext('2d').drawImage(img, 0, 0);
    show('busy'); $('[data-busyt]').textContent = 'Nyomtatás…';
    try { await call(B.print, BoothCompose.toPrint(c), { copies: 1 }); $('[data-busyt]').textContent = 'Kész!'; await sleep(1800); show('photo'); }
    catch (e) { show('photo'); err(e.message); }
  }
  $('[data-glgrid]').addEventListener('click', e => { const b = e.target.closest('[data-ph]'); if (b) openPhoto(b.dataset.ph, b.dataset.f); });
  async function upStatus() { const r = await B.cloudStatus(); if (!r || !r.ok) return ''; const s = r.v; return s.queued ? 'Feltöltésre vár: ' + s.queued + ' kép' + (s.err ? ' (' + s.err + ')' : '') : (S.cfg.boothKey ? 'Minden kép feltöltve' + (s.sent ? ' (' + s.sent + ')' : '') : 'Nincs gépkulcs – a képek csak a gépen vannak'); }
  /* ---------- v11: figyelmeztetések a kezdőképernyőn (net, feltöltési sor, gépkulcs, kamera) */
  async function warnings() {
    const L = [];
    if (!navigator.onLine) L.push(['Nincs internet – a képek a gépen várnak, a QR később is működik', 'mid']);
    try { const r = await B.cloudStatus(); if (r && r.ok) { const s = r.v; if (s.queued > 0 && (s.err || !navigator.onLine)) L.push(['Feltöltésre vár: ' + s.queued + ' kép' + (s.err ? ' (' + s.err + ')' : ''), 'mid']); if (s.err && /kulcs/.test(s.err)) L.push(['A gépkulcsot a galéria elutasította – F2 → Gépkulcs', '']); } } catch (e) { /* */ }
    if (WEB() && S.stream && !S.stream.getVideoTracks().some(t => t.readyState === 'live')) L.push(['A kamera kapcsolata megszakadt – húzd ki és dugd vissza, vagy indítsd újra a programot', '']);
    const d = new Date(); if (d.getFullYear() < 2026) L.push(['A gép órája rossz (' + d.toLocaleDateString('hu-HU') + ') – a képek dátuma hibás lesz', '']);
    const w = $('[data-warn]'); w.innerHTML = L.map(x => '<span class="' + x[1] + '">⚠ ' + esc(x[0]) + '</span>').join(''); w.hidden = !L.length;
  }
  setInterval(() => { if (S.scr === 'start') warnings(); }, 20000);
  window.addEventListener('online', warnings); window.addEventListener('offline', warnings);
  /* ---------- s10: mentett beállítások (rendezvény-sablonok) – ezen a gépen */
  const PRE_KEYS = ['shots', 'countdown', 'copies', 'autoPrint', 'reviewSec', 'mirror', 'camRes', 'camera'];
  const preLoad = () => { try { return JSON.parse(localStorage.getItem('cb_presets') || '[]'); } catch (e) { return []; } };
  const preStore = L => { try { localStorage.setItem('cb_presets', JSON.stringify(L)); } catch (e) { err('A sablon nem menthető'); } };
  function preDraw() {
    const L = preLoad(), box = $('[data-prelist]');
    box.innerHTML = L.length ? L.map((p, i) => '<div><b>' + esc(p.name) + '</b><small>' + (Number(p.cfg.shots) >= 3 ? '3 képes csík' : '1 kép') + ' · ' + p.cfg.countdown + ' mp' + (p.event && p.event.deal ? ' · ' + esc(p.event.title) : '') + '</small><button type="button" data-preuse="' + i + '">Betöltés</button><button type="button" data-predel="' + i + '">Törlés</button></div>').join('') : '<small style="color:var(--mu)">Még nincs mentett beállítás.</small>';
  }
  $('[data-prelist]').addEventListener('click', async e => {
    const u = e.target.closest('[data-preuse]'), d = e.target.closest('[data-predel]'); const L = preLoad();
    if (d) { L.splice(Number(d.dataset.predel), 1); preStore(L); preDraw(); return; }
    if (u) { const p = L[Number(u.dataset.preuse)]; if (!p) return; const o = Object.assign({}, p.cfg); if (p.event && p.event.deal) o.event = p.event; S.cfg = Object.assign(S.cfg, await call(B.saveConfig, o)); await ensureEvent(); await loadTemplate(); await fillForm(); modeInfo(); $('[data-stmsg]').textContent = '„' + p.name + '” betöltve.'; }
  });
  /* ---------- v12: beállítások exportja / importja (gépkulcs és PIN nélkül) */
  const EXP_KEYS = ['camera', 'camId', 'camRes', 'mirror', 'shots', 'countdown', 'copies', 'printer', 'autoPrint', 'reviewSec', 'dccUrl'];
  $('[data-import]').addEventListener('change', async e => {
    const f = e.target.files && e.target.files[0]; e.target.value = ''; if (!f) return;
    try { const j = JSON.parse(await f.text()); if (!j || j.crazyBooth !== 1) throw new Error('Ez nem Crazy Booth beállításfájl'); const o = {}; EXP_KEYS.forEach(k => { if (j.cfg && j.cfg[k] !== undefined) o[k] = j.cfg[k]; }); S.cfg = Object.assign(S.cfg, await call(B.saveConfig, o)); if (Array.isArray(j.presets)) preStore(j.presets); await fillForm(); modeInfo(); $('[data-stmsg]').textContent = 'Beállítások betöltve.'; }
    catch (x) { $('[data-stmsg]').textContent = 'Importálás: ' + x.message; }
  });
  function tplInfo() { const el = $('[data-tplinfo]'); if (el) el.textContent = 'Sablon: ' + (S.tpl ? S.tplName + ' · ' + S.tpl.shots + ' fotó' : (S.cfg.event && S.cfg.event.deal ? 'nincs – alap keret' : 'nincs rendezvény kiválasztva – alap keret')); }
  /* a panelből visszatérve a sablon újratöltődik */
  window.addEventListener('focus', async () => { if (!S.tplEditing || S.busy) return; S.tplEditing = false; if (S.cfg.event) S.cfg.event.template = true; await loadTemplate(); tplInfo(); modeInfo(); });
  function camRows() { const f = $('[data-form]'), web = f.elements.camera.value !== 'dcc'; f.querySelectorAll('[data-web]').forEach(x => { x.hidden = !web; }); f.querySelectorAll('[data-dcc]').forEach(x => { x.hidden = web; }); }
  $('[data-form]').elements.camera.addEventListener('change', camRows);
  function modeInfo() { $('[data-modeinfo]').textContent = (S.cfg.mock ? 'Próbamód · ' : WEB() ? 'Webkamera · ' : 'Nikon D5300 · ') + (S.tpl ? 'sablon (' + S.tpl.shots + ' fotó)' : (Number(S.cfg.shots) >= 3 ? '3 képes csík' : '1 kép')) + (S.cfg.event && S.cfg.event.deal ? ' · ' + S.cfg.event.title : '') + (S.cfg.boothKey ? '' : ' · ⚠ nincs gépkulcs: QR és online galéria kikapcsolva (F2)'); }
  async function initCam() { try { await call(B.camInit); } catch (e) { err(e.message); } }
  (async () => { S.cfg = await call(B.config); await ensureEvent(); await loadTemplate(); modeInfo(); setTimeout(warnings, 3000); B.isFullscreen().then(r => { if (r && r.ok) $('[data-act=fs] span').textContent = r.v ? 'Teljes képernyő ki' : 'Teljes képernyő be'; }); await initCam(); })().catch(e => err(e.message));
})();
