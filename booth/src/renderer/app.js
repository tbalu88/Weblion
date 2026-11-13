/* Crazy Booth – felület: kezdő → élőkép + visszaszámlálás → fotó(k) → előnézet → nyomtatás. */
(function () {
  'use strict';
  const B = window.booth;
  const $ = s => document.querySelector(s);
  const S = { cfg: null, scr: 'start', busy: false, shots: [], copies: 1, lvT: 0, stream: null, idleT: 0 };
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const call = async (fn, ...a) => { const r = await fn(...a); if (!r || !r.ok) throw new Error((r && r.err) || 'ismeretlen hiba'); return r.v; };

  function show(s) {
    if (S.scr === 'review' && s !== 'review' && s !== 'live' && S.pending) commit();
    document.querySelectorAll('.scr').forEach(x => x.classList.toggle('on', x.dataset.s === s)); S.scr = s; if (s === 'start' && S.uiNew) uiSwap();
  }
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
  /* ---------- fotózási módok (3. fázis) */
  const MODES = {
    photo: { name: 'Fotó', sub: 'klasszikus nyomat', ico: '<rect x="6" y="14" width="36" height="26" rx="5"/><circle cx="24" cy="27" r="7"/><path d="M17 14l3-5h8l3 5"/>' },
    glam: { name: 'Glam', sub: 'fekete-fehér, sztáros', ico: '<circle cx="24" cy="20" r="9"/><path d="M10 42c2-8 8-12 14-12s12 4 14 12"/><path d="M36 6l1.5 3.5L41 11l-3.5 1.5L36 16l-1.5-3.5L31 11l3.5-1.5z"/>' },
    group: { name: 'Csoportkép', sub: 'több idő beállni', ico: '<circle cx="16" cy="18" r="5"/><circle cx="32" cy="18" r="5"/><circle cx="24" cy="14" r="5"/><path d="M6 38c1-6 5-9 10-9M42 38c-1-6-5-9-10-9M14 40c1-7 5-10 10-10s9 3 10 10"/>' },
    gif: { name: 'GIF', sub: '4 kép, pörgetve', clip: true, ico: '<rect x="6" y="8" width="16" height="14" rx="3"/><rect x="26" y="8" width="16" height="14" rx="3"/><rect x="6" y="26" width="16" height="14" rx="3"/><rect x="26" y="26" width="16" height="14" rx="3"/>' },
    boom: { name: 'Boomerang', sub: 'oda-vissza mozdulat', clip: true, ico: '<path d="M8 24c0-8 6-12 12-12 8 0 8 24 16 24 6 0 8-6 8-12"/><path d="M40 16l4 8-8 1"/>' },
    video: { name: 'Videó', sub: 'rövid klip zenével', clip: true, ico: '<rect x="5" y="12" width="28" height="24" rx="5"/><path d="M33 21l10-6v18l-10-6z"/>' },
    slow: { name: 'Lassított', sub: 'slow motion', clip: true, ico: '<circle cx="24" cy="24" r="17"/><path d="M24 14v10l7 5"/>' },
    guestbook: { name: 'Videóüzenet', sub: 'jókívánság hanggal', clip: true, ico: '<rect x="18" y="6" width="12" height="22" rx="6"/><path d="M11 22c0 7 6 12 13 12s13-5 13-12M24 34v8M17 42h14"/>' }
  };
  const U = () => (S.cfg && S.cfg.ui) || {};
  const modeList = () => { const m = (U().modes || ['photo']).filter(k => MODES[k]); return m.length ? m : ['photo']; };
  const POSES = ['Mosolyt!', 'Most egy vicceset!', 'És egy utolsót – a legjobbat!', 'Még egyet!'];
  const svgIco = p => '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
  function modesScreen() {
    const L = modeList();
    $('[data-modes]').innerHTML = L.map(k => '<button type="button" class="mt" data-mode="' + k + '">' + svgIco(MODES[k].ico) + '<b>' + MODES[k].name + '</b><small>' + MODES[k].sub + '</small></button>').join('');
    $('[data-modes]').style.setProperty('--n', Math.min(4, L.length));
    show('modes');
    clearTimeout(S.idleT); S.idleT = setTimeout(() => { if (S.scr === 'modes') show('start'); }, 25000);
  }
  function startTap() { const L = modeList(); if (L.length > 1) modesScreen(); else run(L[0]); }

  /* glam: fekete-fehér, kontraszt, lágy bőr (elmosott réteg ráúsztatva) */
  async function glamify(img) {
    const w = img.naturalWidth, h = img.naturalHeight, c = document.createElement('canvas'); c.width = w; c.height = h; const g = c.getContext('2d');
    g.filter = 'grayscale(1) contrast(1.18) brightness(1.06)'; g.drawImage(img, 0, 0);
    g.globalAlpha = 0.38; g.filter = 'grayscale(1) blur(' + Math.max(2, Math.round(w / 420)) + 'px) brightness(1.1)'; g.drawImage(img, 0, 0);
    g.globalAlpha = 1; g.filter = 'none';
    const vg = g.createRadialGradient(w / 2, h / 2, Math.min(w, h) * 0.35, w / 2, h / 2, Math.max(w, h) * 0.75); vg.addColorStop(0, 'rgba(0,0,0,0)'); vg.addColorStop(1, 'rgba(0,0,0,.35)'); g.fillStyle = vg; g.fillRect(0, 0, w, h);
    return loadImg(c.toDataURL('image/jpeg', 0.93));
  }
  async function countdown(sec) {
    for (let k = sec; k > 0; k--) { $('[data-cd]').textContent = k; await wait(1000); }
    $('[data-cd]').textContent = ''; chk();
    const f = $('[data-flash]'); f.classList.remove('go'); void f.offsetWidth; f.classList.add('go');
  }
  const pose = t => { const p = $('[data-pose]'); if (U().getReady === false) t = ''; p.textContent = t || ''; p.hidden = !t; };
  const cdNext = () => Number(U().cdNext) || Number(S.cfg.countdown) || 3;
  const cdSec = mode => { const b = Number(S.cfg.countdown) || 3; return mode === 'group' ? Math.max(b, Number(U().groupCd) || 6) : b; };
  async function shoot(mode) {
    const img = await loadImg(await snap()); chk();
    B.saveShot(img.src);
    return mode === 'glam' ? glamify(img) : img;
  }
  async function run(mode) {
    if (S.busy) return; S.busy = true; S.shots = []; S.cancel = false; clearTimeout(S.idleT);
    mode = MODES[mode] ? mode : modeList()[0]; S.mode = mode;
    if (MODES[mode].clip) { S.busy = false; return runClip(mode); }
    const n = S.tpl ? S.tpl.shots : (Number(S.cfg.shots) >= 3 ? 3 : 1), take = n === 1 && U().bestOf ? 3 : n;
    try {
      show('live'); await liveOn();
      const pool = [];
      for (let i = 0; i < take; i++) {
        $('[data-shotno]').textContent = take > 1 ? (i + 1) + ' / ' + take : '';
        pose(i === 0 ? (take > 1 ? 'Készülj: ' + take + ' fotó jön!' : mode === 'group' ? 'Mindenki beférjen a képbe!' : mode === 'glam' ? 'Glam fotó – nézz a kamerába!' : 'Mosolyt!') : POSES[i === take - 1 ? 2 : (i - 1) % 2]);
        await countdown(i ? cdNext() : cdSec(mode)); pose('');
        pool.push(await shoot(mode));
        if (i < take - 1) await wait(900);
      }
      await liveOff();
      S.pool = pool; S.pick = take > n; S.shots = S.pick ? [pool[0]] : pool.slice();
      review();
    } catch (e) {
      await liveOff().catch(() => {});
      $('[data-cd]').textContent = ''; $('[data-shotno]').textContent = ''; pose('');
      show('start');
      if (!(e instanceof Cancel)) err(e.message);
    } finally { S.busy = false; S.cancel = false; }
  }
  /* m12: egy kép újrafotózása a csíkban */
  async function retake(i) {
    if (S.busy) return; S.busy = true; S.cancel = false; clearTimeout(S.idleT); clearTimeout(S.saveT);
    try {
      show('live'); await liveOn(); $('[data-shotno]').textContent = (i + 1) + '. kép újra'; pose('Még egyszer!');
      await countdown(Number(S.cfg.countdown) || 3); pose('');
      const img = await shoot(S.mode); await liveOff();
      S.pool[i] = img; S.shots[i] = img; S.retakes = (S.retakes || 0) + 1;
      review(true);
    } catch (e) { await liveOff().catch(() => {}); pose(''); show('review'); if (!(e instanceof Cancel)) err(e.message); idle(); }
    finally { S.busy = false; S.cancel = false; $('[data-shotno]').textContent = ''; }
  }
  function thumbs() {
    const el = $('[data-thumbs]'), canRe = U().retake !== false && !S.pick && S.pool && S.pool.length > 1 && (S.retakes || 0) < 3;
    if (!S.pick && !canRe) { el.hidden = true; el.innerHTML = ''; return; }
    el.hidden = false;
    el.innerHTML = '<p>' + (S.pick ? 'Válaszd ki a kedvenced!' : 'Nem tetszik egy kép? Koppints rá, és újrafotózzuk.') + '</p><div>' +
      S.pool.map((im, i) => '<button type="button" data-th="' + i + '"' + (S.pick && S.shots[0] === im ? ' class="on"' : '') + '><img alt="" src="' + im.src + '">' + (S.pick ? '' : '<span>↻</span>') + '</button>').join('') + '</div>';
  }
  $('[data-thumbs]').addEventListener('click', e => {
    const b = e.target.closest('[data-th]'); if (!b) return; const i = Number(b.dataset.th);
    if (S.pick) { S.shots = [S.pool[i]]; review(true); } else retake(i);
  });
  /* a kész kép mentése (helyben + háttérfeltöltés). Ha még cserélhető (választás / újrafotózás), csak kilépéskor vagy 12 mp nyugalom után. */
  async function commit() {
    clearTimeout(S.saveT); const p = S.pending; if (!p) return; S.pending = null;
    try { await call(B.keep, p.ev.ev, p.ev.deal || 0, p.ev.date || '', p.photo, p.data, p.meta); } catch (e) { err('A kép mentése nem sikerült: ' + e.message); }
  }
  async function review(again) {
    const cv = $('[data-out]'), vo = $('[data-outv]');
    vo.hidden = true; vo.removeAttribute('src'); cv.hidden = false;
    BoothCompose.compose(cv, S.shots, { title: S.cfg.title || 'EventCrazy', date: today(), tpl: S.tpl });
    $('[data-printui]').hidden = false;
    if (!again) { S.copies = Number(S.cfg.copies) || 1; S.retakes = 0; }
    $('[data-cpn]').textContent = S.copies;
    thumbs(); show('review');
    const ev = S.cfg.event;
    if (!again) { S.lastPhoto = await call(B.newTok, 10); $('[data-qrbox]').hidden = true; if (S.cfg.boothKey) showQr($('[data-qrbox]'), $('[data-qr]'), ev.ev, S.lastPhoto); }
    S.pending = { ev, photo: S.lastPhoto, data: cv.toDataURL('image/jpeg', 0.92), meta: {} };
    const changeable = S.pick || (S.pool && S.pool.length > 1);
    if (changeable) { clearTimeout(S.saveT); S.saveT = setTimeout(commit, 12000); } else await commit();
    if (S.cfg.autoPrint && !again && !changeable) { print(); return; }
    idle();
  }

  /* ---------- mozgóképes módok */
  async function runClip(mode) {
    if (S.busy) return; S.busy = true; S.cancel = false;
    const C = window.BoothClips, v = $('[data-video]'), opt = { text: (S.cfg.event && S.cfg.event.title) || '', sub: today() };
    const rec = $('[data-rec]'), bar = $('[data-recbar]'), stopB = $('[data-act=recstop]');
    let stopFn = null; S.recStop = false;
    const tick = (p, stop) => { bar.style.transform = 'scaleX(' + Math.min(1, p) + ')'; if (stop) stopFn = stop; if ((S.cancel || S.recStop) && stopFn) stopFn(); };
    try {
      if (!WEB()) throw new Error('A mozgóképes módokhoz webkamera kell (Beállítások → Kamera).');
      if (!window.MediaRecorder || !C) throw new Error('Ezen a gépen nem készíthető videó.');
      show('live'); await liveOn();
      if (!v.videoWidth) throw new Error('A kamera nem ad képet.');
      if (mode !== 'guestbook' && U().music) { const m = await kvGet('music').catch(() => null); if (m && m.blob) opt.music = m.blob; }
      let out;
      if (mode === 'gif') {
        const list = [];
        for (let i = 0; i < 4; i++) {
          $('[data-shotno]').textContent = (i + 1) + ' / 4'; pose(i ? POSES[i % 2] : 'Négy kép jön egymás után!');
          await countdown(i ? Math.min(2, cdNext()) : cdSec(mode)); pose('');
          const fr = await C.frames(v, 1, 1); list.push(fr[0]); chk();
        }
        $('[data-shotno]').textContent = '';
        busy('Készül a GIF…'); out = await C.render(list, C.seqStills(4, 15, 2), 15, Object.assign({ onTick: busyBar }, opt));
      } else if (mode === 'boom' || mode === 'slow') {
        pose(mode === 'boom' ? 'Mozogj! Integess, ugorj, koccints!' : 'Valami látványosat!'); await countdown(cdSec(mode)); pose('');
        rec.hidden = false;
        const fr = await C.frames(v, mode === 'boom' ? 1600 : 3000, mode === 'boom' ? 24 : 30, p => tick(p)); chk();
        rec.hidden = true;
        busy(mode === 'boom' ? 'Készül a boomerang…' : 'Készül a lassított videó…');
        out = await C.render(fr, mode === 'boom' ? C.seqBoom(fr.length, 2) : C.seqRamp(fr.length), mode === 'boom' ? 24 : 30, Object.assign({ onTick: busyBar }, opt));
      } else {
        const gb = mode === 'guestbook', sec = gb ? (Number(U().gbSec) || 30) : (Number(U().videoSec) || 10);
        pose(gb ? 'Mondd el a jókívánságaidat! (' + sec + ' mp)' : 'Indul a felvétel – ' + sec + ' mp'); await countdown(cdSec(mode)); pose('');
        rec.hidden = false; stopB.hidden = !gb;
        out = await C.live(v, sec * 1000, Object.assign({ mic: gb, onTick: tick }, opt));
        rec.hidden = true; stopB.hidden = true; chk();
      }
      await liveOff();
      S.clip = out; await reviewClip(mode);
    } catch (e) {
      await liveOff().catch(() => {});
      rec.hidden = true; stopB.hidden = true; $('[data-cd]').textContent = ''; $('[data-shotno]').textContent = ''; pose('');
      show('start');
      if (!(e instanceof Cancel)) err(e.message);
    } finally { S.busy = false; S.cancel = false; }
  }
  const busy = t => { show('busy'); $('[data-busyt]').textContent = t; busyBar(0); };
  const busyBar = p => { const b = $('[data-busybar]'); b.hidden = !(p > 0); b.firstElementChild.style.transform = 'scaleX(' + Math.min(1, p) + ')'; };
  async function reviewClip(mode) {
    const cv = $('[data-out]'), vo = $('[data-outv]');
    cv.hidden = true; vo.hidden = false; vo.src = URL.createObjectURL(S.clip.blob); vo.muted = mode !== 'guestbook'; vo.play().catch(() => {});
    $('[data-printui]').hidden = true; $('[data-thumbs]').hidden = true; S.pool = null; S.pick = false;
    $('[data-qrbox]').hidden = true; show('review');
    const ev = S.cfg.event; S.lastPhoto = await call(B.newTok, 10);
    if (S.cfg.boothKey) showQr($('[data-qrbox]'), $('[data-qr]'), ev.ev, S.lastPhoto);
    S.pending = { ev, photo: S.lastPhoto, data: await window.BoothClips.blobToDataUrl(S.clip.blob), meta: { poster: S.clip.poster, kind: mode === 'guestbook' ? 'guestbook' : 'video' } };
    await commit();
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
    preDraw(); tplInfo(); screenForm(); uiForm(); musicInfo();
    const pr = await B.printers(); const sel = $('[data-printers]');
    sel.innerHTML = '<option value="">(alapértelmezett nyomtató)</option>' + ((pr.ok && pr.v) || []).map(p => '<option' + (p.name === c.printer ? ' selected' : '') + '>' + p.name.replace(/</g, '&lt;') + '</option>').join('');
    $('[data-ver2]').textContent = 'program v' + c.version + (c.mac ? ' · Mac' : '') + (c.mock ? ' · próbamód' : '');
    if (B.uiInfo) B.uiInfo().then(r => { if (r && r.ok) $('[data-ver2]').textContent += ' · felület ' + r.v.v + (r.v.online ? ' (online)' : ''); });
  }
  document.querySelector('.pad').innerHTML = '1 2 3 4 5 6 7 8 9 ⌫ 0 ✓'.split(' ').map(k => '<button type="button" data-k="' + k + '">' + k + '</button>').join('');
  document.querySelector('.pad').addEventListener('click', async e => {
    const k = e.target.dataset.k; if (!k) return;
    if (k === '⌫') pinBuf = pinBuf.slice(0, -1);
    else if (k === '✓') { if (pinBuf === String(S.cfg.pin)) { $('[data-pinbox]').hidden = true; $('[data-form]').hidden = false; tab('ov'); await fillForm(); preflight(); } else { pinBuf = ''; err('Hibás PIN'); } }
    else if (pinBuf.length < 8) pinBuf += k;
    $('[data-pin]').textContent = '•'.repeat(pinBuf.length);
  });
  document.addEventListener('click', async e => {
    const a = e.target.closest('[data-act]'); if (!a) return;
    const act = a.dataset.act;
    if (act === 'print') print();
    else if (act === 'again') { clearTimeout(S.idleT); await commit(); run(S.mode); }
    else if (act === 'recstop') { S.recStop = true; }
    else if (act === 'pfre') { preflight(); }
    else if (act === 'standby') { standby(); }
    else if (act === 'musicclear') { await kvSet('music', null); musicInfo(); $('[data-stmsg]').textContent = 'Zene törölve.'; }
    else if (act === 'defaults') { if (!window.confirm('Visszaállítod a fotózási, nyomtatási és mód-beállításokat az alapértékekre? (A gépkulcs, a PIN, a rendezvény és a kamera marad.)')) return; try { S.cfg = Object.assign(S.cfg, await call(B.saveConfig, { countdown: 3, shots: 1, copies: 1, autoPrint: false, reviewSec: 25, mirror: true, ui: {} })); await fillForm(); $('[data-stmsg]').textContent = 'Alapértékek visszaállítva.'; } catch (x) { $('[data-stmsg]').textContent = x.message; } }
    else if (act === 'done') { clearTimeout(S.idleT); show('start'); }
    else if (act === 'close') { show('start'); if (!S.stream) initCam(); }
    else if (act === 'quit') { B.quit(); }
    else if (act === 'cancel') { cancelRun(); }
    else if (act === 'scsave') { const sc = (await kvGet('screen')) || {}; sc.t1 = $('#sc-t1').value.trim(); sc.t2 = $('#sc-t2').value.trim(); sc.brand = $('#sc-brand').value.trim(); sc.color = $('#sc-color').value; await kvSet('screen', sc); await screenForm(); $('[data-stmsg]').textContent = 'Kezdőképernyő mentve.'; }
    else if (act === 'bgclear') { const sc = (await kvGet('screen')) || {}; delete sc.bg; await kvSet('screen', sc); await screenForm(); $('[data-stmsg]').textContent = 'Háttér törölve.'; }
    else if (act === 'tpledit') { const e = S.cfg.event; if (!e || !e.deal) { $('[data-stmsg]').textContent = 'Előbb válassz rendezvényt (deal), és mentsd el.'; return; } window.open('https://eventcrazy.hu/crazy-panel/#sablon:' + e.deal, '_blank', 'width=1400,height=900'); S.tplEditing = true; $('[data-stmsg]').textContent = 'A panel Sablonkészítője új ablakban nyílt meg. Ott a „Mentés a szelfigéphez” gombbal mentsd el, majd gyere vissza ide.'; }
    else if (act === 'tplreload') { S.cfg.event && (S.cfg.event.template = true); await loadTemplate(); tplInfo(); modeInfo(); $('[data-stmsg]').textContent = S.tpl ? 'Sablon frissítve (' + S.tpl.shots + ' fotó).' : 'Ehhez a rendezvényhez nincs sablon – az alap keret marad.'; }
    else if (act === 'presave') { const n = $('#pre-name').value.trim(); if (!n) { $('[data-stmsg]').textContent = 'Adj nevet a sablonnak.'; return; } const L = preLoad(), cfg = {}; PRE_KEYS.forEach(k => { cfg[k] = S.cfg[k]; }); const ev = S.cfg.event && S.cfg.event.deal ? S.cfg.event : null; const i = L.findIndex(p => p.name === n); const p = { name: n, cfg, event: ev, at: Date.now() }; if (i >= 0) L[i] = p; else L.push(p); preStore(L); $('#pre-name').value = ''; preDraw(); $('[data-stmsg]').textContent = '„' + n + '” elmentve.'; }
    else if (act === 'export') { const cfg = {}; EXP_KEYS.forEach(k => { cfg[k] = S.cfg[k]; }); const blob = new Blob([JSON.stringify({ crazyBooth: 1, at: new Date().toISOString(), cfg, presets: preLoad() }, null, 2)], { type: 'application/json' }); const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'crazy-booth-beallitasok.json'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000); $('[data-stmsg]').textContent = 'Exportálva.'; }
    else if (act === 'gallery') { gallery(); }
    else if (act === 'back') { clearTimeout(S.idleT); show('start'); }
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
      o.ui = uiCollect();
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
    if (e.key === 'Escape') { if (S.scr === 'live') cancelRun(); else if (S.scr === 'settings' || S.scr === 'gallery' || S.scr === 'modes') show('start'); else if (S.scr === 'photo') gallery(); }
  });
  $('[data-s=start]').addEventListener('click', e => { if (!e.target.closest('[data-act]')) startTap(); });
  $('[data-modes]').addEventListener('click', e => { const b = e.target.closest('[data-mode]'); if (b) { clearTimeout(S.idleT); run(b.dataset.mode); } });

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
    const fu = f => encodeURI('file://' + f.replace(/\\/g, '/')).replace(/'/g, '%27');
    grid.innerHTML = L.length ? L.map(p => '<button type="button" data-ph="' + p.photo + '" data-f="' + esc(p.file) + '"' + (p.video ? ' data-v="1" class="vid"' : '') + ' style="background-image:url(\'' + fu(p.video ? (p.poster || '') : p.file) + '\')"></button>').join('') : '<p>Még nincs kép ezen a rendezvényen.</p>';
    grid.querySelectorAll('[data-ph]').forEach(b => { const i = new Image(); i.onload = () => b.classList.toggle('land', i.naturalWidth > i.naturalHeight); i.src = b.style.backgroundImage.slice(5, -2); });
    const q = $('[data-glqr]'); q.innerHTML = '';
    if (S.cfg.boothKey) { try { const u = await call(B.urls, ev.ev); q.innerHTML = '<div class="qrc"></div><span>Az összes fotó<br>a telefonodon</span>'; drawQr(q.querySelector('.qrc'), await call(B.qr, u.event)); } catch (e) { /* */ } }
  }
  async function openPhoto(photo, file, vid) {
    const im = $('[data-phbig]'), vv = $('[data-phvid]');
    im.hidden = !!vid; vv.hidden = !vid; $('[data-reprint]').hidden = !!vid; vv.pause();
    if (vid) { vv.src = encodeURI('file://' + file.replace(/\\/g, '/')); vv.play().catch(() => {}); S.photoSrc = ''; }
    else { const src = await call(B.readImage, file); im.src = src; S.photoSrc = src; }
    S.lastPhoto = photo;
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
  $('[data-glgrid]').addEventListener('click', e => { const b = e.target.closest('[data-ph]'); if (b) openPhoto(b.dataset.ph, b.dataset.f, !!b.dataset.v); });
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
  const PRE_KEYS = ['shots', 'countdown', 'copies', 'autoPrint', 'reviewSec', 'mirror', 'camRes', 'camera', 'ui'];
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
  const EXP_KEYS = ['camera', 'camId', 'camRes', 'mirror', 'shots', 'countdown', 'copies', 'printer', 'autoPrint', 'reviewSec', 'dccUrl', 'ui'];
  $('[data-import]').addEventListener('change', async e => {
    const f = e.target.files && e.target.files[0]; e.target.value = ''; if (!f) return;
    try { const j = JSON.parse(await f.text()); if (!j || j.crazyBooth !== 1) throw new Error('Ez nem Crazy Booth beállításfájl'); const o = {}; EXP_KEYS.forEach(k => { if (j.cfg && j.cfg[k] !== undefined) o[k] = j.cfg[k]; }); S.cfg = Object.assign(S.cfg, await call(B.saveConfig, o)); if (Array.isArray(j.presets)) preStore(j.presets); await fillForm(); modeInfo(); $('[data-stmsg]').textContent = 'Beállítások betöltve.'; }
    catch (x) { $('[data-stmsg]').textContent = 'Importálás: ' + x.message; }
  });
  function tplInfo() { const el = $('[data-tplinfo]'); if (el) el.textContent = 'Sablon: ' + (S.tpl ? S.tplName + ' · ' + S.tpl.shots + ' fotó' : (S.cfg.event && S.cfg.event.deal ? 'nincs – alap keret' : 'nincs rendezvény kiválasztva – alap keret')); }
  /* a panelből visszatérve a sablon újratöltődik */
  window.addEventListener('focus', async () => { if (!S.tplEditing || S.busy) return; S.tplEditing = false; if (S.cfg.event) S.cfg.event.template = true; await loadTemplate(); tplInfo(); modeInfo(); });
  /* ---------- s5/s6: kezdőképernyő testreszabása (felirat, szín, háttérkép vagy ismétlődő videó) – IndexedDB-ben, a gépen */
  const idb = () => new Promise((res, rej) => { const r = indexedDB.open('crazybooth', 1); r.onupgradeneeded = () => r.result.createObjectStore('kv'); r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); });
  const kvGet = async k => { const d = await idb(); return new Promise(res => { const q = d.transaction('kv').objectStore('kv').get(k); q.onsuccess = () => res(q.result); q.onerror = () => res(undefined); }); };
  const kvSet = async (k, v) => { const d = await idb(); return new Promise((res, rej) => { const t = d.transaction('kv', 'readwrite'); if (v === undefined) t.objectStore('kv').delete(k); else t.objectStore('kv').put(v, k); t.oncomplete = () => res(); t.onerror = () => rej(t.error); }); };
  let bgUrl = '';
  async function screenApply() {
    let sc = {}; try { sc = (await kvGet('screen')) || {}; } catch (e) { sc = {}; }
    $('[data-st1]').textContent = sc.t1 || 'Érints meg!';
    $('[data-st2]').textContent = sc.t2 || 'és mosolyogj a kamerába';
    const br = $('[data-brand]'); if (sc.brand) br.textContent = sc.brand; else br.innerHTML = 'Event<span>Crazy</span>';
    document.documentElement.style.setProperty('--o', sc.color || '#E8894A');
    document.documentElement.style.setProperty('--g', sc.color ? 'linear-gradient(120deg,' + sc.color + ' 0%,' + sc.color + ' 100%)' : 'linear-gradient(120deg,#E8894A 0%,#F2B35B 100%)');
    const box = $('[data-bgm]'); if (bgUrl) { URL.revokeObjectURL(bgUrl); bgUrl = ''; } box.innerHTML = '';
    if (sc.bg instanceof Blob) { bgUrl = URL.createObjectURL(sc.bg); box.innerHTML = /^video\//.test(sc.bg.type) ? '<video autoplay muted loop playsinline src="' + bgUrl + '"></video>' : '<img alt="" src="' + bgUrl + '">'; }
    return sc;
  }
  async function screenForm() {
    const sc = await screenApply();
    $('#sc-t1').value = sc.t1 || ''; $('#sc-t2').value = sc.t2 || ''; $('#sc-brand').value = sc.brand || ''; $('#sc-color').value = sc.color || '#E8894A';
    $('[data-bginfo]').textContent = sc.bg instanceof Blob ? 'Háttér: ' + (/^video/.test(sc.bg.type) ? 'videó' : 'kép') + ' (' + Math.round(sc.bg.size / 1048576 * 10) / 10 + ' MB)' : 'Háttér: alap (sötétkék)';
  }
  $('[data-bgfile]').addEventListener('change', async e => {
    const f = e.target.files && e.target.files[0]; e.target.value = ''; if (!f) return;
    if (f.size > 300 * 1048576) { $('[data-stmsg]').textContent = 'A fájl túl nagy (legfeljebb 300 MB).'; return; }
    try { const sc = (await kvGet('screen')) || {}; sc.bg = f; await kvSet('screen', sc); await screenForm(); $('[data-stmsg]').textContent = 'Háttér beállítva.'; } catch (x) { $('[data-stmsg]').textContent = 'A háttér nem menthető: ' + x.message; }
  });
  function camRows() { const f = $('[data-form]'), web = f.elements.camera.value !== 'dcc'; f.querySelectorAll('[data-web]').forEach(x => { x.hidden = !web; }); f.querySelectorAll('[data-dcc]').forEach(x => { x.hidden = web; }); }
  $('[data-form]').elements.camera.addEventListener('change', camRows);
  function modeInfo() { $('[data-modeinfo]').textContent = (S.cfg.mock ? 'Próbamód · ' : WEB() ? 'Webkamera · ' : 'Nikon D5300 · ') + (S.tpl ? 'sablon (' + S.tpl.shots + ' fotó)' : (Number(S.cfg.shots) >= 3 ? '3 képes csík' : '1 kép')) + (S.cfg.event && S.cfg.event.deal ? ' · ' + S.cfg.event.title : '') + (S.cfg.boothKey ? '' : ' · ⚠ nincs gépkulcs: QR és online galéria kikapcsolva (F2)'); }
  /* ---------- kezelőpult: fülek a vendég útja szerint, keresés, indulás előtti ellenőrzés, szünet */
  function tab(t) {
    $('[data-stq]').value = ''; search('');
    document.querySelectorAll('[data-tab]').forEach(b => b.classList.toggle('on', b.dataset.tab === t));
    document.querySelectorAll('[data-pane]').forEach(p => p.classList.toggle('on', p.dataset.pane === t));
    if (t === 'ov') preflight();
  }
  $('[data-tabs]').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) tab(b.dataset.tab); });
  /* keresés: minden fülön, a találatok látszanak, a többi elrejtve */
  const norm = x => String(x || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  function search(q) {
    q = norm(q.trim()); const panes = document.querySelectorAll('[data-pane]'); let hit = 0;
    $('[data-form]').classList.toggle('srch', !!q);
    panes.forEach(p => {
      let ph = 0;
      p.querySelectorAll(':scope > label, :scope > .st-row > label, :scope > .st-h, :scope > .st-b, :scope > .st-ev, :scope > .st-modes, :scope > .st-pre, :scope > .st-prel, :scope > p').forEach(el => {
        const m = !q || norm(el.textContent + ' ' + [...el.querySelectorAll('input[placeholder]')].map(i => i.placeholder).join(' ')).includes(q);
        el.classList.toggle('st-hide', !m); if (m && q) ph++;
      });
      p.classList.toggle('hit', !!ph); hit += ph;
    });
    $('[data-stnone]').hidden = !q || !!hit;
  }
  $('[data-stq]').addEventListener('input', e => search(e.target.value));

  /* a felület saját beállításai (cfg.ui) */
  const UI_NUM = ['cdNext', 'groupCd', 'videoSec', 'gbSec'], UI_CHK = ['bestOf', 'retake', 'music', 'getReady'];
  const UI_DEF = { cdNext: 2, groupCd: 6, videoSec: 10, gbSec: 30, bestOf: false, retake: true, music: false, getReady: true };
  function uiForm() {
    const u = Object.assign({}, UI_DEF, U()), on = modeList();
    UI_NUM.forEach(k => { $('#u-' + k).value = u[k]; });
    UI_CHK.forEach(k => { $('#u-' + k).checked = !!u[k]; });
    const rec = !!(window.MediaRecorder && window.BoothClips);
    $('[data-modecheck]').innerHTML = Object.keys(MODES).map(k => '<label class="mchk' + (MODES[k].clip && !rec ? ' dis' : '') + '"><input type="checkbox" data-m="' + k + '"' + (on.includes(k) ? ' checked' : '') + (MODES[k].clip && !rec ? ' disabled' : '') + '>' + svgIco(MODES[k].ico) + '<b>' + MODES[k].name + '</b><small>' + MODES[k].sub + '</small></label>').join('');
  }
  function uiCollect() {
    const u = Object.assign({}, U());
    UI_NUM.forEach(k => { const v = Number($('#u-' + k).value); if (v > 0) u[k] = v; });
    UI_CHK.forEach(k => { u[k] = $('#u-' + k).checked; });
    const m = [...document.querySelectorAll('[data-m]:checked')].map(x => x.dataset.m); u.modes = m.length ? m : ['photo'];
    return u;
  }
  async function musicInfo() { let m = null; try { m = await kvGet('music'); } catch (e) { /* */ } $('[data-musicinfo]').textContent = m && m.blob ? 'Zene: ' + m.name + ' (' + Math.round(m.blob.size / 1024) + ' KB)' : 'Nincs zene kiválasztva.'; }
  $('[data-musicfile]').addEventListener('change', async e => {
    const f = e.target.files && e.target.files[0]; e.target.value = ''; if (!f) return;
    if (f.size > 15 * 1024 * 1024) { $('[data-stmsg]').textContent = 'A zenefájl túl nagy (max. 15 MB).'; return; }
    try { await kvSet('music', { name: f.name, blob: f }); await musicInfo(); $('#u-music').checked = true; $('[data-stmsg]').textContent = 'Zene beállítva – a Mentés gombbal véglegesítsd.'; } catch (x) { $('[data-stmsg]').textContent = 'A zene nem menthető: ' + x.message; }
  });

  /* indulás előtti ellenőrzés */
  async function preflight() {
    const box = $('[data-preflight]'), L = [], add = (st, t, d) => L.push([st, t, d || '']);
    box.innerHTML = '<p class="st-up">Ellenőrzés…</p>';
    if (S.cfg.mock) add('mid', 'Próbamód', 'a program próbamódban fut – éles rendezvény előtt indítsd normálisan');
    try {
      if (WEB()) { const st = await webStream(); const s0 = st.getVideoTracks()[0]; const t = s0.getSettings(); add('ok', 'Kamera', (s0.label || 'webkamera') + ' · ' + (t.width || '?') + '×' + (t.height || '?')); }
      else { await call(B.camInit); add('ok', 'Kamera', 'Nikon D5300 csatlakozik'); }
    } catch (e) { add(S.cfg.mock ? 'mid' : 'bad', 'Kamera', e.message); }
    const ms = modeList(), clips = ms.filter(k => MODES[k].clip);
    if (clips.length) add(window.MediaRecorder ? 'ok' : 'bad', 'Videó', window.MediaRecorder ? (window.BoothClips.pickMime().indexOf('mp4') >= 0 ? 'MP4' : 'WebM') + ' formátumban' : 'ezen a gépen nem készíthető videó');
    if (ms.some(k => !MODES[k].clip)) {
      try { const pr = ((await B.printers()).v) || []; const name = S.cfg.printer; const p = name ? pr.find(x => x.name === name) : (pr.find(x => x.isDefault) || pr[0]); add(p ? 'ok' : 'bad', 'Nyomtató', p ? p.name + (name ? '' : ' (alapértelmezett)') : (name ? '„' + name + '” nem található' : 'nincs nyomtató')); }
      catch (e) { add('bad', 'Nyomtató', e.message); }
    }
    add(navigator.onLine ? 'ok' : 'mid', 'Internet', navigator.onLine ? 'van kapcsolat' : 'nincs – a képek a gépen várnak, később feltöltődnek');
    add(S.cfg.boothKey ? 'ok' : 'mid', 'Gépkulcs', S.cfg.boothKey ? 'megadva' : 'nincs – QR és online galéria kikapcsolva (Gép fül)');
    try { const r = await B.cloudStatus(); if (r && r.ok) { const s = r.v; add(s.err ? 'bad' : s.queued ? 'mid' : 'ok', 'Feltöltés', s.err ? s.err : s.queued ? s.queued + ' kép vár feltöltésre' : 'minden feltöltve'); } } catch (e) { /* */ }
    const ev = S.cfg.event; add(ev && ev.deal ? 'ok' : 'mid', 'Rendezvény', ev && ev.deal ? ev.title + ' · ' + (ev.date || '') : 'nincs kiválasztva (Rendezvény fül)');
    add(S.tpl ? 'ok' : 'mid', 'Sablon', S.tpl ? S.tplName + ' · ' + S.tpl.shots + ' fotó' : 'alap keret');
    add('ok', 'Módok', ms.map(k => MODES[k].name).join(', '));
    const d = new Date(); add(d.getFullYear() < 2026 ? 'bad' : 'ok', 'Óra', d.toLocaleString('hu-HU'));
    const bad = L.filter(x => x[0] === 'bad').length, mid = L.filter(x => x[0] === 'mid').length;
    box.innerHTML = '<p class="pf-sum ' + (bad ? 'bad' : mid ? 'mid' : 'ok') + '">' + (bad ? bad + ' hiba – javítsd indulás előtt' : mid ? 'Indulhat, ' + mid + ' figyelmeztetéssel' : 'Minden rendben – indulhat a fotózás') + '</p>' +
      L.map(x => '<div class="pf-i ' + x[0] + '"><i></i><b>' + esc(x[1]) + '</b><span>' + esc(x[2]) + '</span></div>').join('');
  }

  /* szünet-képernyő: üzenet, kamera ki, folytatás kézzel vagy a megadott időpontban */
  function standby() {
    const msg = $('#sb-msg').value.trim() || 'Hamarosan folytatjuk!', until = $('#sb-until').value;
    $('[data-sbmsg]').textContent = msg; $('[data-sbuntil]').textContent = until ? 'Folytatás: ' + until : '';
    S.sbUntil = until || '';
    if (S.stream) { S.stream.getTracks().forEach(t => t.stop()); S.stream = null; }
    show('standby'); B.log('szünet: ' + msg + (until ? ' (' + until + ')' : ''));
  }
  setInterval(() => {
    if (S.scr !== 'standby' || !S.sbUntil) return;
    const d = new Date(), now = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    if (now >= S.sbUntil) { S.sbUntil = ''; show('start'); initCam(); }
  }, 15000);
  async function initCam() { try { await call(B.camInit); } catch (e) { err(e.message); } }
  (async () => { S.cfg = await call(B.config); screenApply().catch(() => {}); await ensureEvent(); await loadTemplate(); modeInfo(); setTimeout(warnings, 3000); B.isFullscreen().then(r => { if (r && r.ok) $('[data-act=fs] span').textContent = r.v ? 'Teljes képernyő ki' : 'Teljes képernyő be'; }); await initCam(); })().catch(e => err(e.message));
})();
