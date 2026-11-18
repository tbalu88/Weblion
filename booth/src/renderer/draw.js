/* Crazy Booth – AI-rajz (0.15.0): a kész AI-kép „élő megrajzolása” a képernyőn.
 * 1. vonalak tollal (a kép éleiből vékonyított, sorba rendezett vonalak – előbb a nagy formák középről kifelé, aztán a részletek),
 * 2. színek ecsettel (a kép színcsoportjai világostól a sötétig, soronként festve, puha foltokkal),
 * 3. a kész kép ráúszik (a végleges vastag kontúrokkal), és kitart.
 * Nincs külön AI-hívás: minden helyben, a már elkészült képből számolódik. Nincs külső függőség. */
(function () {
  'use strict';
  const AN = 600; /* az elemzés felbontása (hosszabb oldal, px) – gyors és elég részletes */

  function analyse(img) {
    const w0 = img.naturalWidth || img.width, h0 = img.naturalHeight || img.height, k = AN / Math.max(w0, h0);
    const a = Math.max(8, Math.round(w0 * k)), b = Math.max(8, Math.round(h0 * k));
    const c = document.createElement('canvas'); c.width = a; c.height = b;
    const x = c.getContext('2d', { willReadFrequently: true }); x.fillStyle = '#fff'; x.fillRect(0, 0, a, b); x.drawImage(img, 0, 0, a, b);
    const px = x.getImageData(0, 0, a, b).data, N = a * b, L = new Float32Array(N);
    for (let i = 0; i < N; i++) L[i] = 0.299 * px[i * 4] + 0.587 * px[i * 4 + 1] + 0.114 * px[i * 4 + 2];
    /* élek: Sobel a fényességen; küszöb: a nem-üres élek erősségének felső része (a kép kontrasztjához igazodik) */
    const M = new Float32Array(N); const hist = new Uint32Array(256); let nz = 0;
    for (let y = 1; y < b - 1; y++) for (let i = y * a + 1, e = y * a + a - 1; i < e; i++) {
      const gx = -L[i - a - 1] - 2 * L[i - 1] - L[i + a - 1] + L[i - a + 1] + 2 * L[i + 1] + L[i + a + 1];
      const gy = -L[i - a - 1] - 2 * L[i - a] - L[i - a + 1] + L[i + a - 1] + 2 * L[i + a] + L[i + a + 1];
      const m = Math.min(255, Math.sqrt(gx * gx + gy * gy) / 4); M[i] = m; if (m > 8) { hist[m | 0]++; nz++; }
    }
    let T = 40, acc = 0; for (let v = 255; v > 8; v--) { acc += hist[v]; if (acc >= nz * 0.42) { T = Math.max(28, v); break; } }
    const E = new Uint8Array(N); for (let i = 0; i < N; i++) if (M[i] >= T) E[i] = 1;
    thin(E, a, b);
    const strokes = order(trace(E, a, b), a, b);
    const regions = colours(px, L, E, a, b);
    return { a, b, strokes, regions };
  }
  /* Zhang–Suen vékonyítás: egy pixel széles vonalak */
  function thin(E, a, b) {
    const del = []; let ch = true;
    while (ch) {
      ch = false;
      for (let it = 0; it < 2; it++) {
        del.length = 0;
        for (let y = 1; y < b - 1; y++) for (let x = 1; x < a - 1; x++) {
          const i = y * a + x; if (!E[i]) continue;
          const p2 = E[i - a], p3 = E[i - a + 1], p4 = E[i + 1], p5 = E[i + a + 1], p6 = E[i + a], p7 = E[i + a - 1], p8 = E[i - 1], p9 = E[i - a - 1];
          const B = p2 + p3 + p4 + p5 + p6 + p7 + p8 + p9; if (B < 2 || B > 6) continue;
          const A = (!p2 && p3) + (!p3 && p4) + (!p4 && p5) + (!p5 && p6) + (!p6 && p7) + (!p7 && p8) + (!p8 && p9) + (!p9 && p2); if (A !== 1) continue;
          if (it === 0 ? (p2 * p4 * p6 || p4 * p6 * p8) : (p2 * p4 * p8 || p2 * p6 * p8)) continue;
          del.push(i);
        }
        if (del.length) { ch = true; for (const i of del) E[i] = 0; }
      }
    }
  }
  const NB = [[1, 0], [0, 1], [-1, 0], [0, -1], [1, 1], [-1, 1], [-1, -1], [1, -1]];
  /* vonalak bejárása: előbb a végpontokból, aztán a zárt hurkok */
  function trace(E, a, b) {
    const seen = new Uint8Array(a * b), out = [];
    const deg = (x, y) => { let n = 0; for (const [dx, dy] of NB) { const X = x + dx, Y = y + dy; if (X >= 0 && Y >= 0 && X < a && Y < b && E[Y * a + X]) n++; } return n; };
    const walk = (x, y) => {
      const p = [x, y]; seen[y * a + x] = 1;
      for (;;) {
        let nx = -1, ny = -1;
        for (const [dx, dy] of NB) { const X = x + dx, Y = y + dy; if (X >= 0 && Y >= 0 && X < a && Y < b && E[Y * a + X] && !seen[Y * a + X]) { nx = X; ny = Y; break; } }
        if (nx < 0) break; x = nx; y = ny; seen[y * a + x] = 1; p.push(x, y);
      }
      return p;
    };
    for (let pass = 0; pass < 2; pass++) for (let y = 0; y < b; y++) for (let x = 0; x < a; x++) {
      const i = y * a + x; if (!E[i] || seen[i] || (pass === 0 && deg(x, y) !== 1)) continue;
      const p = walk(x, y); if (p.length >= 16) out.push(smooth(p));
    }
    return out;
  }
  /* simítás (csúszó átlag, a végpontok maradnak) – a lépcsős pixelvonal helyett folyó tollvonás */
  function smooth(p) {
    const n = p.length / 2; if (n < 5) return p; const o = p.slice();
    for (let r = 0; r < 2; r++) { const q = o.slice(); for (let i = 1; i < n - 1; i++) { o[i * 2] = (q[i * 2 - 2] + 2 * q[i * 2] + q[i * 2 + 2]) / 4; o[i * 2 + 1] = (q[i * 2 - 1] + 2 * q[i * 2 + 1] + q[i * 2 + 3]) / 4; } }
    return o;
  }
  const slen = p => { let s = 0; for (let i = 2; i < p.length; i += 2) s += Math.hypot(p[i] - p[i - 2], p[i + 1] - p[i - 1]); return s; };
  /* sorrend: a hosszú vonalak (körvonalak) középről kifelé, majd a részletek; a toll a legközelebbi végtől indul */
  function order(S, a, b) {
    const cx = a * 0.5, cy = b * 0.36, big = Math.max(a, b) * 0.09;
    const it = S.map(p => { let mx = 0, my = 0; const n = p.length / 2; for (let i = 0; i < p.length; i += 2) { mx += p[i]; my += p[i + 1]; } return { p, len: slen(p), d: Math.hypot(mx / n - cx, (my / n - cy) * 0.8) }; });
    const A = it.filter(s => s.len >= big).sort((x, y) => x.d - y.d), B = it.filter(s => s.len < big).sort((x, y) => x.d - y.d);
    let px = cx, py = cy; const out = [];
    for (const s of A.concat(B)) {
      let p = s.p; const n = p.length;
      if (Math.hypot(p[n - 2] - px, p[n - 1] - py) < Math.hypot(p[0] - px, p[1] - py)) { const r = []; for (let i = n - 2; i >= 0; i -= 2) r.push(p[i], p[i + 1]); p = r; }
      out.push({ p, len: s.len }); px = p[n - 2]; py = p[n - 1];
    }
    return out;
  }
  /* színcsoportok (k-közép, 6 csoport) a nem-fehér, nem-él pixelekből; világostól sötétig festve */
  function colours(px, L, E, a, b) {
    const N = a * b, K = 6, idx = [];
    for (let i = 0; i < N; i++) { const r = px[i * 4], g = px[i * 4 + 1], bl = px[i * 4 + 2]; if (L[i] > 238 && Math.max(r, g, bl) - Math.min(r, g, bl) < 18) continue; idx.push(i); }
    if (!idx.length) return [];
    const C = []; for (let k = 0; k < K; k++) { const i = idx[Math.floor((k + 0.5) / K * idx.length * 0.999)] ; C.push([px[i * 4], px[i * 4 + 1], px[i * 4 + 2]]); }
    /* a kezdőpontok fényesség szerint szétszórva */
    const byL = idx.slice().sort((x, y) => L[x] - L[y]); for (let k = 0; k < K; k++) { const i = byL[Math.floor((k + 0.5) / K * byL.length)]; C[k] = [px[i * 4], px[i * 4 + 1], px[i * 4 + 2]]; }
    const lab = new Int8Array(N).fill(-1);
    for (let iter = 0; iter < 7; iter++) {
      const s = C.map(() => [0, 0, 0, 0]);
      for (let j = 0; j < idx.length; j += (iter < 6 ? 3 : 1)) {
        const i = idx[j], r = px[i * 4], g = px[i * 4 + 1], bl = px[i * 4 + 2]; let bk = 0, bd = 1e9;
        for (let k = 0; k < K; k++) { const d = (r - C[k][0]) ** 2 + (g - C[k][1]) ** 2 + (bl - C[k][2]) ** 2; if (d < bd) { bd = d; bk = k; } }
        if (iter === 6) lab[i] = bk; const t = s[bk]; t[0] += r; t[1] += g; t[2] += bl; t[3]++;
      }
      if (iter < 6) s.forEach((t, k) => { if (t[3]) C[k] = [t[0] / t[3], t[1] / t[3], t[2] / t[3]]; });
    }
    const g = Math.max(6, Math.round(Math.max(a, b) / 34)); /* ecsetfolt-rács */
    const R = C.map((c, k) => ({ k, lum: 0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2], col: 'rgb(' + c.map(v => Math.round(v)).join(',') + ')', n: 0, mask: null, spl: [] }));
    const masks = R.map(() => { const m = document.createElement('canvas'); m.width = a; m.height = b; return m; });
    const md = masks.map(m => m.getContext('2d').createImageData(a, b));
    for (let i = 0; i < N; i++) { const k = lab[i]; if (k < 0) continue; R[k].n++; md[k].data[i * 4 + 3] = 255; }
    R.forEach((r, k) => {
      masks[k].getContext('2d').putImageData(md[k], 0, 0); r.mask = masks[k];
      /* foltok: rácscellánként, ha elég sok pixel az övé; soronként kígyózva, fentről lefelé */
      for (let gy = 0, row = 0; gy < b; gy += g, row++) {
        const line = [];
        for (let gx = 0; gx < a; gx += g) {
          let n = 0, sx = 0, sy = 0;
          for (let y = gy; y < Math.min(b, gy + g); y++) for (let x = gx; x < Math.min(a, gx + g); x++) if (lab[y * a + x] === k) { n++; sx += x; sy += y; }
          if (n >= g * g * 0.08) line.push([sx / n, sy / n]);
        }
        if (row % 2) line.reverse(); r.spl.push(...line);
      }
      r.g = g;
    });
    return R.filter(r => r.n > N * 0.002 && r.spl.length).sort((x, y) => y.lum - x.lum);
  }

  /* ---------- eszközök a képernyőn */
  function pen(x, X, Y, s) {
    x.save(); x.translate(X, Y); x.rotate(-0.62); x.scale(s, s);
    x.shadowColor = 'rgba(0,0,0,.28)'; x.shadowBlur = 10; x.shadowOffsetY = 4;
    x.fillStyle = '#1b1b1f'; x.beginPath(); x.moveTo(0, 0); x.lineTo(7, -16); x.lineTo(7, -110); x.lineTo(-7, -110); x.lineTo(-7, -16); x.closePath(); x.fill();
    x.shadowColor = 'transparent'; x.fillStyle = '#c9ccd3'; x.fillRect(-7, -34, 14, 6); x.fillStyle = '#000'; x.beginPath(); x.moveTo(0, 0); x.lineTo(3, -7); x.lineTo(-3, -7); x.closePath(); x.fill();
    x.restore();
  }
  function brush(x, X, Y, s, col) {
    x.save(); x.translate(X, Y); x.rotate(-0.62); x.scale(s, s);
    x.shadowColor = 'rgba(0,0,0,.25)'; x.shadowBlur = 10; x.shadowOffsetY = 4;
    x.fillStyle = '#b5652b'; x.fillRect(-4, -120, 8, 82);
    x.shadowColor = 'transparent'; x.fillStyle = '#c9ccd3'; x.fillRect(-6, -40, 12, 16);
    x.fillStyle = col; x.beginPath(); x.moveTo(-6, -24); x.quadraticCurveTo(-8, -6, 0, 2); x.quadraticCurveTo(8, -6, 6, -24); x.closePath(); x.fill();
    x.restore();
  }
  const ease = t => t < 0 ? 0 : t > 1 ? 1 : t * t * (3 - 2 * t);

  /* lejátszás: cv = a képernyő vászna, img = a kész AI-kép, sec = teljes hossz (mp). Promise: a végén teljesül. */
  /* opt.record: a rajzolás közben videó is készül (a képernyő vásznáról) – eredmény: { blob, mime, poster } */
  function play(cv, img, sec, an, opt) {
    an = an || analyse(img); opt = opt || {};
    const dpr = Math.min(2, window.devicePixelRatio || 1), box = (cv.parentElement || cv).getBoundingClientRect();
    const fit = Math.min((box.width || 600) / an.a, (box.height || 800) / an.b);
    const W = Math.round(an.a * fit * dpr / 2) * 2, H = Math.round(an.b * fit * dpr / 2) * 2, s = W / an.a; /* páros méret: a H.264-hez kell */
    cv.width = W; cv.height = H; cv.style.width = Math.round(W / dpr) + 'px'; cv.style.height = Math.round(H / dpr) + 'px';
    const x = cv.getContext('2d'), mk = () => { const c = document.createElement('canvas'); c.width = W; c.height = H; return c; };
    const lines = mk(), lx = lines.getContext('2d'); lx.strokeStyle = '#111'; lx.lineWidth = Math.max(1.2, 1.1 * dpr); lx.lineCap = 'round'; lx.lineJoin = 'round';
    const rev = mk(), rx = rev.getContext('2d'), cur = mk(), cx2 = cur.getContext('2d'), tmp = mk(), tx = tmp.getContext('2d'), mm = mk(), mmx = mm.getContext('2d');
    const total = Math.max(1, an.strokes.reduce((t, q) => t + q.len, 0));
    const nSpl = an.regions.reduce((t, r) => t + r.spl.length, 0);
    const D = Math.max(6, Number(sec) || 20) * 1000, T1 = 0.46, T2 = 0.86, T3 = 0.95, tool = Math.max(0.55, W / 900);
    let si = 0, sj = 2, done = 0, ri = 0, rk = 0, painted = 0;
    let rec = null, chunks = [], mime = '';
    if (opt.record && window.MediaRecorder && cv.captureStream) {
      try {
        mime = (window.BoothClips && window.BoothClips.pickMime()) || 'video/webm';
        rec = new MediaRecorder(cv.captureStream(30), { mimeType: mime, videoBitsPerSecond: 5e6 });
        rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
        x.fillStyle = '#fff'; x.fillRect(0, 0, W, H); rec.start(500);
      } catch (e) { rec = null; }
    }
    const finish = res => {
      if (!rec) { res(null); return; }
      /* a kész kép 1,5 mp-ig a videó végén is kitart */
      const h0 = performance.now(), hold = () => { x.globalAlpha = 1; x.drawImage(img, 0, 0, W, H); if (performance.now() - h0 < 1500) requestAnimationFrame(hold); else { rec.onstop = () => res({ blob: new Blob(chunks, { type: mime.split(';')[0] }), mime: mime.split(';')[0], poster: cv.toDataURL('image/jpeg', 0.88) }); try { rec.stop(); } catch (e) { res(null); } } };
      requestAnimationFrame(hold);
    };
    const t0 = performance.now();
    return new Promise(res => {
      const frame = now => {
        const p = (now - t0) / D;
        /* 1. vonalak: állandó tolltempó (a megtett út arányos az idővel) */
        const want = total * Math.min(1, p / T1); let tip = null;
        lx.beginPath();
        while (si < an.strokes.length && done < want) {
          const q = an.strokes[si].p;
          if (sj === 2) lx.moveTo(q[0] * s, q[1] * s);
          while (sj < q.length && done < want) { done += Math.hypot(q[sj] - q[sj - 2], q[sj + 1] - q[sj - 1]); lx.lineTo(q[sj] * s, q[sj + 1] * s); tip = [q[sj] * s, q[sj + 1] * s]; sj += 2; }
          if (sj >= q.length) { si++; sj = 2; if (si < an.strokes.length) lx.moveTo(an.strokes[si].p[0] * s, an.strokes[si].p[1] * s); }
        }
        lx.stroke();
        /* 2. színek: színcsoportonként, foltról foltra; a kész csoport rögzül */
        let bt = null, bcol = '#e9b48a';
        if (p > T1 && an.regions.length) {
          const wantS = Math.floor(nSpl * Math.min(1, (p - T1) / (T2 - T1)));
          while (painted < wantS && ri < an.regions.length) {
            const r = an.regions[ri], q = r.spl[rk], rad = r.g * 1.45 * s;
            const gr = cx2.createRadialGradient(q[0] * s, q[1] * s, 0, q[0] * s, q[1] * s, rad); gr.addColorStop(0, 'rgba(0,0,0,1)'); gr.addColorStop(0.6, 'rgba(0,0,0,.85)'); gr.addColorStop(1, 'rgba(0,0,0,0)');
            cx2.fillStyle = gr; cx2.beginPath(); cx2.arc(q[0] * s, q[1] * s, rad, 0, Math.PI * 2); cx2.fill();
            bt = [q[0] * s, q[1] * s]; painted++; rk++;
            if (rk >= r.spl.length) { cx2.globalCompositeOperation = 'destination-in'; cx2.imageSmoothingEnabled = true; cx2.drawImage(r.mask, 0, 0, W, H); cx2.globalCompositeOperation = 'source-over'; rx.drawImage(cur, 0, 0); cx2.clearRect(0, 0, W, H); ri++; rk = 0; }
          }
          if (ri < an.regions.length) { const r = an.regions[ri]; const q = r.spl[Math.max(0, rk - 1)]; bt = bt || [q[0] * s, q[1] * s]; bcol = r.col; } else if (an.regions.length) bcol = an.regions[an.regions.length - 1].col;
        }
        /* rajzolás a képernyőre */
        x.globalAlpha = 1; x.fillStyle = '#fff'; x.fillRect(0, 0, W, H);
        if (p > T1) {
          tx.clearRect(0, 0, W, H); tx.globalCompositeOperation = 'source-over'; tx.drawImage(rev, 0, 0);
          if (ri < an.regions.length) { mmx.globalCompositeOperation = 'copy'; mmx.drawImage(cur, 0, 0); mmx.globalCompositeOperation = 'destination-in'; mmx.drawImage(an.regions[ri].mask, 0, 0, W, H); mmx.globalCompositeOperation = 'source-over'; tx.drawImage(mm, 0, 0); }
          tx.globalCompositeOperation = 'source-in'; tx.drawImage(img, 0, 0, W, H); tx.globalCompositeOperation = 'source-over';
          x.drawImage(tmp, 0, 0);
        }
        x.drawImage(lines, 0, 0);
        if (p > T2) { x.globalAlpha = ease((p - T2) / (T3 - T2)); x.drawImage(img, 0, 0, W, H); x.globalAlpha = 1; }
        if (p <= T1 && tip) pen(x, tip[0], tip[1], tool);
        else if (p > T1 && p <= T2 && bt) brush(x, bt[0], bt[1], tool, bcol);
        if (p < 1) requestAnimationFrame(frame); else { x.globalAlpha = 1; x.drawImage(img, 0, 0, W, H); finish(res); }
      };
      requestAnimationFrame(frame);
    });
  }
  window.BoothDraw = { analyse, play };
})();
