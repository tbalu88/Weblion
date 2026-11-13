/* Crazy Booth – mozgóképes módok: GIF (képsor), boomerang, lassított (sebességváltással), videó, videós üzenet.
 * A kész klip MP4 (ha a rendszer tudja), különben WebM; zene és felirat-sáv opcionális.
 * A webkamerás élőképből dolgozik (a D5300-as módhoz később). */
(function () {
  'use strict';
  const MAXW = 1280;
  const pickMime = () => ['video/mp4;codecs=avc1.42E01E,mp4a.40.2', 'video/mp4;codecs=avc1.42E01E', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'].find(m => window.MediaRecorder && MediaRecorder.isTypeSupported(m)) || 'video/webm';
  const sleep = ms => new Promise(r => setTimeout(r, ms));

  /* képkockák gyűjtése a videóelemből (nem tükrözve, mint a fotó) */
  async function frames(video, ms, fps, onTick) {
    const w0 = video.videoWidth || 1280, h0 = video.videoHeight || 720, k = Math.min(1, MAXW / w0);
    const w = Math.round(w0 * k), h = Math.round(h0 * k), out = [], step = 1000 / fps, t0 = performance.now();
    while (performance.now() - t0 < ms) {
      const c = document.createElement('canvas'); c.width = w; c.height = h; c.getContext('2d').drawImage(video, 0, 0, w, h); out.push(c);
      if (onTick) onTick((performance.now() - t0) / ms);
      await sleep(Math.max(0, step - ((performance.now() - t0) % step)));
    }
    return out;
  }

  /* felirat-sáv a klip aljára (rendezvény / dátum) */
  function overlay(g, w, h, text, sub) {
    if (!text) return;
    const bh = Math.round(h * 0.12), y = h - bh;
    g.fillStyle = 'rgba(14,18,32,.62)'; g.fillRect(0, y, w, bh);
    g.fillStyle = '#fff'; g.textAlign = 'center'; g.textBaseline = 'middle';
    g.font = '700 ' + Math.round(bh * 0.36) + 'px "Segoe UI",system-ui,sans-serif'; g.fillText(text, w / 2, y + bh * (sub ? 0.4 : 0.5), w * 0.9);
    if (sub) { g.font = '400 ' + Math.round(bh * 0.22) + 'px "Segoe UI",system-ui,sans-serif'; g.globalAlpha = 0.85; g.fillText(sub, w / 2, y + bh * 0.76, w * 0.9); g.globalAlpha = 1; }
  }

  /* zene: a hangfájlt (Blob) a klip hosszára vágjuk, halkítással a végén */
  async function musicTrack(blob, seconds) {
    if (!blob) return null;
    try {
      const ac = new AudioContext(), buf = await ac.decodeAudioData(await blob.arrayBuffer());
      const dst = ac.createMediaStreamDestination(), src = ac.createBufferSource(), gain = ac.createGain();
      src.buffer = buf; src.loop = true; src.connect(gain); gain.connect(dst);
      gain.gain.setValueAtTime(0.9, ac.currentTime); gain.gain.setValueAtTime(0.9, ac.currentTime + Math.max(0, seconds - 1)); gain.gain.linearRampToValueAtTime(0, ac.currentTime + seconds);
      return { track: dst.stream.getAudioTracks()[0], start: () => src.start(), stop: () => { try { src.stop(); } catch (e) { /* */ } ac.close(); } };
    } catch (e) { return null; }
  }

  /* képkockák → videó: seq = képkocka-sorszámok listája (a sorrend és ismétlés adja a boomerangot / lassítást) */
  async function render(list, seq, fps, opt) {
    opt = opt || {};
    const w = list[0].width, h = list[0].height, c = document.createElement('canvas'); c.width = w; c.height = h;
    const g = c.getContext('2d'), stream = c.captureStream(fps);
    const mu = await musicTrack(opt.music, seq.length / fps);
    if (mu && mu.track) stream.addTrack(mu.track);
    const mime = pickMime(), rec = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 6e6 }), chunks = [];
    rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
    const done = new Promise(r => { rec.onstop = r; });
    const draw = i => { g.drawImage(list[i], 0, 0); overlay(g, w, h, opt.text, opt.sub); };
    draw(seq[0]); rec.start(); if (mu) mu.start();
    const t0 = performance.now();
    for (let n = 0; n < seq.length; n++) {
      draw(seq[n]); if (opt.onTick) opt.onTick(n / seq.length);
      const due = t0 + (n + 1) * 1000 / fps; await sleep(Math.max(0, due - performance.now()));
    }
    rec.stop(); if (mu) mu.stop(); await done;
    const poster = (() => { draw(seq[Math.floor(seq.length / 3)]); return c.toDataURL('image/jpeg', 0.88); })();
    return { blob: new Blob(chunks, { type: mime.split(';')[0] }), mime: mime.split(';')[0], poster };
  }

  /* élő felvétel (videó / videós üzenet): a kamerakép + felirat-sáv, hanggal (mikrofon vagy zene) */
  async function live(video, ms, opt) {
    opt = opt || {};
    const w0 = video.videoWidth || 1280, h0 = video.videoHeight || 720, k = Math.min(1, MAXW / w0), w = Math.round(w0 * k), h = Math.round(h0 * k);
    const c = document.createElement('canvas'); c.width = w; c.height = h; const g = c.getContext('2d'), stream = c.captureStream(30);
    let mic = null, mu = null;
    if (opt.mic) { try { mic = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }); stream.addTrack(mic.getAudioTracks()[0]); } catch (e) { mic = null; } }
    else if (opt.music) { mu = await musicTrack(opt.music, ms / 1000); if (mu && mu.track) stream.addTrack(mu.track); }
    const mime = pickMime(), rec = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 6e6 }), chunks = [];
    rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
    const done = new Promise(r => { rec.onstop = r; });
    let poster = '', stop = false; const t0 = performance.now();
    rec.start(); if (mu) mu.start();
    while (!stop && performance.now() - t0 < ms) {
      g.drawImage(video, 0, 0, w, h); overlay(g, w, h, opt.text, opt.sub);
      if (!poster && performance.now() - t0 > Math.min(1500, ms / 3)) poster = c.toDataURL('image/jpeg', 0.88);
      if (opt.onTick) opt.onTick((performance.now() - t0) / ms, () => { stop = true; });
      await sleep(1000 / 30);
    }
    rec.stop(); if (mu) mu.stop(); if (mic) mic.getTracks().forEach(t => t.stop()); await done;
    return { blob: new Blob(chunks, { type: mime.split(';')[0] }), mime: mime.split(';')[0], poster: poster || c.toDataURL('image/jpeg', 0.88) };
  }

  /* sorrendek */
  const seqBoom = (n, loops) => { const f = [...Array(n).keys()], one = f.concat(f.slice(1, -1).reverse()); let s = []; for (let i = 0; i < loops; i++) s = s.concat(one); return s; };
  /* lassítás sebességváltással: eleje normál, közepe 3× lassú, vége normál */
  const seqRamp = n => { const s = []; for (let i = 0; i < n; i++) { const p = i / n, rep = p < 0.25 || p > 0.8 ? 1 : 3; for (let r = 0; r < rep; r++) s.push(i); } return s; };
  /* képsor („GIF”): minden kép 0,7 mp, háromszor körbe */
  const seqStills = (n, fps, loops) => { const per = Math.round(fps * 0.7), s = []; for (let l = 0; l < loops; l++) for (let i = 0; i < n; i++) for (let r = 0; r < per; r++) s.push(i); return s; };

  const blobToDataUrl = b => new Promise((res, rej) => { const fr = new FileReader(); fr.onload = () => res(String(fr.result)); fr.onerror = () => rej(fr.error); fr.readAsDataURL(b); });

  /* ---------- v0.9 360°-os mód: hosszabb felvétel memóriakímélően (képkockák JPEG-ként), álló (9:16) vágással */
  async function captureJpeg(video, ms, fps, opt, onTick) {
    opt = opt || {};
    const vw = video.videoWidth || 1280, vh = video.videoHeight || 720;
    let sx = 0, sy = 0, sw = vw, sh = vh, ow, oh;
    if (opt.vertical) { sw = Math.min(vw, Math.round(vh * 9 / 16)); sx = Math.round((vw - sw) / 2); ow = 540; oh = 960; }
    else { const k = Math.min(1, 960 / vw); ow = Math.round(vw * k); oh = Math.round(vh * k); }
    const c = document.createElement('canvas'); c.width = ow; c.height = oh; const g = c.getContext('2d');
    const blobs = [], pend = [], step = 1000 / fps, t0 = performance.now();
    let n = 0;
    while (performance.now() - t0 < ms) {
      g.drawImage(video, sx, sy, sw, sh, 0, 0, ow, oh);
      const i = n++; pend.push(new Promise(r => c.toBlob(b => { blobs[i] = b; r(); }, 'image/jpeg', 0.86)));
      if (onTick) onTick((performance.now() - t0) / ms);
      await sleep(Math.max(0, t0 + n * step - performance.now()));
    }
    await Promise.all(pend);
    return { blobs: blobs.filter(Boolean), w: ow, h: oh };
  }
  /* sorrend: normál sebesség → a végén lassítva (2×) → gyors visszatekerés */
  const seqSpin = n => { const s = [], a = Math.floor(n * 0.6); for (let i = 0; i < a; i++) s.push(i); for (let i = a; i < n; i++) s.push(i, i); for (let i = n - 1; i >= 0; i -= 2) s.push(i); return s; };
  async function renderJpeg(blobs, seq, fps, opt) {
    opt = opt || {};
    const w = opt.w, h = opt.h, c = document.createElement('canvas'); c.width = w; c.height = h;
    const g = c.getContext('2d'), stream = c.captureStream(fps);
    const mu = await musicTrack(opt.music, seq.length / fps);
    if (mu && mu.track) stream.addTrack(mu.track);
    const mime = pickMime(), rec = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 6e6 }), chunks = [];
    rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
    const done = new Promise(r => { rec.onstop = r; });
    const cache = new Map();
    const bmp = async i => { if (cache.has(i)) return cache.get(i); const b = await createImageBitmap(blobs[i]); cache.set(i, b); if (cache.size > 8) { const k = cache.keys().next().value; const o = cache.get(k); cache.delete(k); if (o && o.close) o.close(); } return b; };
    const draw = async i => { g.drawImage(await bmp(i), 0, 0, w, h); overlay(g, w, h, opt.text, opt.sub); };
    await draw(seq[0]); rec.start(); if (mu) mu.start();
    const t0 = performance.now();
    for (let n = 0; n < seq.length; n++) {
      await draw(seq[n]); if (opt.onTick) opt.onTick(n / seq.length);
      await sleep(Math.max(0, t0 + (n + 1) * 1000 / fps - performance.now()));
    }
    rec.stop(); if (mu) mu.stop(); await done;
    await draw(seq[Math.floor(seq.length * 0.45)]); const poster = c.toDataURL('image/jpeg', 0.88);
    cache.forEach(b => { if (b && b.close) b.close(); });
    return { blob: new Blob(chunks, { type: mime.split(';')[0] }), mime: mime.split(';')[0], poster };
  }

  window.BoothClips = { frames, render, live, seqBoom, seqRamp, seqStills, blobToDataUrl, pickMime, captureJpeg, renderJpeg, seqSpin };
})();
