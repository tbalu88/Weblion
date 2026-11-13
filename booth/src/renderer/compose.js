/* A nyomat összeállítása (4×6" 300 dpi). 1. fázis: beépített alap-elrendezések; a 2. fázisban a panel
 * fotósablonja (a portálon mentett nyomtatható PNG + fotóablakok) kerül ide. */
(function () {
  'use strict';
  const W = 1200, H = 1800;   /* álló 4×6" */
  function cover(g, im, x, y, w, h) {
    const iw = im.naturalWidth || im.videoWidth || im.width, ih = im.naturalHeight || im.videoHeight || im.height;
    const r = Math.max(w / iw, h / ih), sw = w / r, sh = h / r;
    g.drawImage(im, (iw - sw) / 2, (ih - sh) / 2, sw, sh, x, y, w, h);
  }
  function footer(g, x, y, w, h, line1, line2) {
    g.fillStyle = '#141C33'; g.textAlign = 'center'; g.textBaseline = 'middle';
    const fs = Math.round(Math.min(h * .3, w * .13));
    g.font = '700 ' + fs + 'px "Segoe UI",sans-serif'; g.fillText(line1, x + w / 2, y + h * .42, w * .86);
    if (line2) { g.font = '400 ' + Math.round(fs * .55) + 'px "Segoe UI",sans-serif'; g.fillStyle = '#5B6275'; g.fillText(line2, x + w / 2, y + h * .42 + fs * .95, w * .86); }
  }
  /* a panelből jött sablon (4×6" PNG, átlátszó fotóablakokkal): az ablakok felismerése.
   * Kétszer nyomtatott csíknál (bal és jobb fél ugyanannyi ablakkal) a két fél ugyanazokat a fotókat kapja. */
  function holes(img) {
    const W0 = img.naturalWidth, H0 = img.naturalHeight, k = Math.max(1, Math.round(Math.max(W0, H0) / 400));
    const w = Math.ceil(W0 / k), h = Math.ceil(H0 / k), c = document.createElement('canvas'); c.width = w; c.height = h;
    const g = c.getContext('2d'); g.drawImage(img, 0, 0, w, h);
    const a = g.getImageData(0, 0, w, h).data, seen = new Uint8Array(w * h), out = [];
    const clear = i => a[i * 4 + 3] < 40;
    for (let i = 0; i < w * h; i++) {
      if (seen[i] || !clear(i)) continue;
      let x0 = w, y0 = h, x1 = 0, y1 = 0, n = 0; const st = [i]; seen[i] = 1;
      while (st.length) { const p = st.pop(), x = p % w, y = (p - x) / w; n++; if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
        for (const q of [p - 1, p + 1, p - w, p + w]) { if (q < 0 || q >= w * h || seen[q] || Math.abs((q % w) - x) > 1 || !clear(q)) continue; seen[q] = 1; st.push(q); } }
      if (n > w * h * 0.01) out.push([x0 * k, y0 * k, (x1 - x0 + 1) * k, (y1 - y0 + 1) * k]);
    }
    const L = out.filter(r => r[0] + r[2] / 2 < W0 / 2).sort((p, q) => p[1] - q[1]), R = out.filter(r => r[0] + r[2] / 2 >= W0 / 2).sort((p, q) => p[1] - q[1]);
    if (L.length && L.length === R.length) return { holes: L.concat(R), map: L.map((_, i) => i).concat(R.map((_, i) => i)), shots: L.length };
    const all = out.sort((p, q) => p[1] - q[1] || p[0] - q[0]);
    return { holes: all, map: all.map((_, i) => i), shots: Math.max(1, Math.min(4, all.length)) };
  }
  /* shots: Image/Canvas lista; opts: { title, date, tpl: { img, holes, map } } – visszaad: canvas */
  function compose(cv, shots, opts) {
    opts = opts || {};
    const g = cv.getContext('2d');
    if (opts.tpl) {
      const t = opts.tpl; cv.width = t.img.naturalWidth; cv.height = t.img.naturalHeight;
      g.fillStyle = '#fff'; g.fillRect(0, 0, cv.width, cv.height);
      t.holes.forEach((r, i) => { const s = shots[t.map[i] % shots.length]; if (s) cover(g, s, r[0] - 2, r[1] - 2, r[2] + 4, r[3] + 4); });
      g.drawImage(t.img, 0, 0);
      return cv;
    }
    if (shots.length >= 3) {
      /* 3 képes csík kétszer a lapon (a nyomtató középen vágja: 2 db 2×6") */
      cv.width = W; cv.height = H;
      g.fillStyle = '#fff'; g.fillRect(0, 0, W, H);
      const sw = W / 2, pad = 36, pw = sw - pad * 2, ph = Math.round(pw * 2 / 3);
      for (let c = 0; c < 2; c++) {
        const x0 = c * sw + pad;
        for (let i = 0; i < 3; i++) cover(g, shots[i], x0, pad + i * (ph + 24), pw, ph);
        footer(g, c * sw, pad + 3 * (ph + 24), sw, H - (pad + 3 * (ph + 24)) - pad, opts.title || 'EventCrazy', opts.date || '');
      }
    } else {
      /* 1 kép: fekvő 6×4" */
      cv.width = H; cv.height = W;
      g.fillStyle = '#fff'; g.fillRect(0, 0, H, W);
      const pad = 40, fh = 170;
      cover(g, shots[0], pad, pad, H - pad * 2, W - pad * 2 - fh);
      footer(g, 0, W - pad - fh, H, fh, opts.title || 'EventCrazy', opts.date || '');
    }
    return cv;
  }
  /* a nyomtatóra mindig álló 4×6" megy: a fekvő képet elforgatjuk.
   * adj (v0.6): színkorrekció a nyomtatóhoz – { b fényerő, c kontraszt, s telítettség, w melegség }, mind -10…+10 */
  function toPrint(cv, adj) {
    const a = adj || {}, b = Number(a.b) || 0, c = Number(a.c) || 0, s = Number(a.s) || 0, w = Number(a.w) || 0, land = cv.width > cv.height;
    if (!land && !b && !c && !s && !w) return cv.toDataURL('image/jpeg', 0.95);
    const p = document.createElement('canvas'); p.width = land ? cv.height : cv.width; p.height = land ? cv.width : cv.height;
    const g = p.getContext('2d');
    if (land) { g.translate(p.width, 0); g.rotate(Math.PI / 2); }
    if (b || c || s) g.filter = 'brightness(' + (1 + b * 0.03) + ') contrast(' + (1 + c * 0.04) + ') saturate(' + (1 + s * 0.06) + ')';
    g.drawImage(cv, 0, 0); g.filter = 'none';
    if (w) { g.setTransform(1, 0, 0, 1, 0, 0); g.globalCompositeOperation = 'soft-light'; g.fillStyle = w > 0 ? 'rgba(255,138,30,' + (w * 0.035) + ')' : 'rgba(40,120,255,' + (-w * 0.035) + ')'; g.fillRect(0, 0, p.width, p.height); g.globalCompositeOperation = 'source-over'; }
    return p.toDataURL('image/jpeg', 0.95);
  }
  /* v0.9 matrica-ív (4×6", 300 dpi): n kép rácsban (2 / 4 / 6 / 8 / 16), vágási ráhagyással; a fotók váltakoznak */
  function stickerSheet(imgs, n, bleedMm) {
    const L = { 2: [1, 2], 4: [2, 2], 6: [2, 3], 8: [2, 4], 16: [4, 4] }[n] || [2, 2];
    const c = document.createElement('canvas'); c.width = W; c.height = H; const g = c.getContext('2d');
    g.fillStyle = '#fff'; g.fillRect(0, 0, W, H);
    const b = Math.round((Number(bleedMm) || 0) / 25.4 * 300), cw = (W - b * 2 * L[0]) / L[0], ch = (H - b * 2 * L[1]) / L[1];
    let k = 0;
    for (let r = 0; r < L[1]; r++) for (let q = 0; q < L[0]; q++) cover(g, imgs[k++ % imgs.length], b + q * (cw + 2 * b), b + r * (ch + 2 * b), cw, ch);
    return c;
  }
  window.BoothCompose = { compose, toPrint, holes, stickerSheet };
})();
