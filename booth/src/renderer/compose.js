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
  /* shots: Image/Canvas lista; opts: { title, date } – visszaad: canvas */
  function compose(cv, shots, opts) {
    opts = opts || {};
    const g = cv.getContext('2d');
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
  /* a nyomtatóra mindig álló 4×6" megy: a fekvő képet elforgatjuk */
  function toPrint(cv) {
    if (cv.width <= cv.height) return cv.toDataURL('image/jpeg', 0.95);
    const p = document.createElement('canvas'); p.width = cv.height; p.height = cv.width;
    const g = p.getContext('2d'); g.translate(p.width, 0); g.rotate(Math.PI / 2); g.drawImage(cv, 0, 0);
    return p.toDataURL('image/jpeg', 0.95);
  }
  window.BoothCompose = { compose, toPrint };
})();
