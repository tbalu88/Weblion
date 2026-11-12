/* Crazy Booth – felület: kezdő → élőkép + visszaszámlálás → fotó(k) → előnézet → nyomtatás. */
(function () {
  'use strict';
  const B = window.booth;
  const $ = s => document.querySelector(s);
  const S = { cfg: null, scr: 'start', busy: false, shots: [], copies: 1, lvT: 0, stream: null, idleT: 0 };
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const call = async (fn, ...a) => { const r = await fn(...a); if (!r || !r.ok) throw new Error((r && r.err) || 'ismeretlen hiba'); return r.v; };

  function show(s) { document.querySelectorAll('.scr').forEach(x => x.classList.toggle('on', x.dataset.s === s)); S.scr = s; }
  function err(m) { const e = $('[data-err]'); e.textContent = m; e.hidden = false; clearTimeout(err.t); err.t = setTimeout(() => { e.hidden = true; }, 6000); B.log('hiba: ' + m); }
  const today = () => { const d = new Date(), p = n => String(n).padStart(2, '0'); return d.getFullYear() + '.' + p(d.getMonth() + 1) + '.' + p(d.getDate()) + '.'; };

  /* ---------- élőkép */
  async function liveOn() {
    const v = $('[data-video]'), im = $('[data-lvimg]');
    if (S.cfg.camera === 'mock') {
      im.hidden = true; v.hidden = false;
      if (!S.stream) { try { S.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 1920, height: 1080 } }); } catch (e) { S.stream = null; } }
      if (S.stream) { v.srcObject = S.stream; await v.play().catch(() => {}); }
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
  async function liveOff() { clearTimeout(S.lvT); if (S.cfg.camera !== 'mock') await B.liveStop(); }
  function mockFrame() {
    const c = document.createElement('canvas'); c.width = 1500; c.height = 1000; const g = c.getContext('2d');
    const gr = g.createLinearGradient(0, 0, 1500, 1000); gr.addColorStop(0, '#2F4A7A'); gr.addColorStop(1, '#E8894A'); g.fillStyle = gr; g.fillRect(0, 0, 1500, 1000);
    g.fillStyle = '#fff'; g.font = '800 90px "Segoe UI",sans-serif'; g.textAlign = 'center'; g.fillText('PRÓBAMÓD', 750, 480); g.font = '400 44px "Segoe UI",sans-serif'; g.fillText(new Date().toLocaleTimeString('hu-HU'), 750, 560);
    return c.toDataURL('image/jpeg', 0.9);
  }
  /* egy fotó: D5300 → teljes felbontású JPG; próbamód → a webkamera / minta képkockája */
  async function snap() {
    if (S.cfg.camera === 'mock') {
      const v = $('[data-video]'), c = document.createElement('canvas');
      if (S.stream && v.videoWidth) { c.width = v.videoWidth; c.height = v.videoHeight; const g = c.getContext('2d'); g.drawImage(v, 0, 0);   /* a mentett kép nem tükrözött (csak az élőkép) */ return c.toDataURL('image/jpeg', 0.92); }
      return mockFrame();
    }
    return call(B.capture);
  }
  const loadImg = src => new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('A kép nem tölthető be')); i.src = src; });

  /* ---------- fotózás */
  async function run() {
    if (S.busy) return; S.busy = true; S.shots = [];
    const n = Number(S.cfg.shots) >= 3 ? 3 : 1;
    try {
      show('live'); await liveOn();
      for (let i = 0; i < n; i++) {
        $('[data-shotno]').textContent = n > 1 ? (i + 1) + ' / ' + n : '';
        for (let k = Number(S.cfg.countdown) || 3; k > 0; k--) { $('[data-cd]').textContent = k; await sleep(1000); }
        $('[data-cd]').textContent = '';
        const f = $('[data-flash]'); f.classList.remove('go'); void f.offsetWidth; f.classList.add('go');
        const src = await snap();
        S.shots.push(await loadImg(src));
        B.saveShot(src);
        if (i < n - 1) await sleep(900);
      }
      await liveOff();
      review();
    } catch (e) {
      await liveOff().catch(() => {});
      show('start'); err(e.message);
    } finally { S.busy = false; }
  }
  function review() {
    const cv = $('[data-out]');
    BoothCompose.compose(cv, S.shots, { title: S.cfg.title || 'EventCrazy', date: today() });
    S.copies = Number(S.cfg.copies) || 1; $('[data-cpn]').textContent = S.copies;
    show('review');
    B.saveShot(cv.toDataURL('image/jpeg', 0.92));
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
    ['camera', 'dccUrl', 'shots', 'countdown', 'copies', 'reviewSec'].forEach(k => { f.elements[k].value = c[k]; });
    f.elements.autoPrint.checked = !!c.autoPrint; f.elements.pin.value = '';
    const pr = await B.printers(); const sel = $('[data-printers]');
    sel.innerHTML = '<option value="">(alapértelmezett nyomtató)</option>' + ((pr.ok && pr.v) || []).map(p => '<option' + (p.name === c.printer ? ' selected' : '') + '>' + p.name.replace(/</g, '&lt;') + '</option>').join('');
    $('[data-ver]').textContent = 'v' + c.version + (c.mock ? ' · próbamód' : '');
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
    else if (act === 'save') {
      const f = $('[data-form]'), o = {};
      ['camera', 'dccUrl', 'printer'].forEach(k => { o[k] = f.elements[k].value; });
      ['shots', 'countdown', 'copies', 'reviewSec'].forEach(k => { o[k] = Number(f.elements[k].value) || S.cfg[k]; });
      o.autoPrint = f.elements.autoPrint.checked;
      if (f.elements.pin.value.trim()) o.pin = f.elements.pin.value.trim();
      try { S.cfg = Object.assign(S.cfg, await call(B.saveConfig, o)); $('[data-stmsg]').textContent = 'Mentve.'; await initCam(); modeInfo(); }
      catch (x) { $('[data-stmsg]').textContent = x.message; }
    }
  });
  document.querySelectorAll('[data-cp]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); S.copies = Math.max(1, Math.min(10, S.copies + Number(b.dataset.cp))); $('[data-cpn]').textContent = S.copies; idle(); }));
  /* bal felső sarok 3 mp-ig nyomva / F2 → beállítások */
  let hold = 0;
  $('[data-corner]').addEventListener('pointerdown', () => { hold = setTimeout(openSettings, 3000); });
  ['pointerup', 'pointerleave'].forEach(ev => $('[data-corner]').addEventListener(ev, () => clearTimeout(hold)));
  document.addEventListener('keydown', e => { if (e.key === 'F2') openSettings(); if (e.key === 'Escape' && S.scr === 'settings') show('start'); });
  $('[data-s=start]').addEventListener('click', () => run());

  function modeInfo() { $('[data-modeinfo]').textContent = (S.cfg.camera === 'mock' ? 'Próbamód · ' : 'Nikon D5300 · ') + (Number(S.cfg.shots) >= 3 ? '3 képes csík' : '1 kép'); }
  async function initCam() { try { await call(B.camInit); } catch (e) { err(e.message); } }
  (async () => { S.cfg = await call(B.config); modeInfo(); await initCam(); })().catch(e => err(e.message));
})();
