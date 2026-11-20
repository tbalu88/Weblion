/* Crazy Booth – rétegalapú sablonszerkesztő (0.18.0), internet nélkül is.
 * Felépítés: felső eszközsor (új, mentés, import, export, próbanyomat, visszavonás) · bal: Hozzáadás + Elrendezés ·
 * közép: a lap (4×6", 300 dpi) · jobb: Infó (pozíció, méret, forgatás) · Igazítás · Rétegek · Beállítások.
 * Kimenet: a gép mostani sablonformátuma – 4×6-os PNG, a fotók helyén átlátszó ablakkal (a BoothCompose.holes ismeri fel),
 * a fotó fölötti rétegek a fotóra kerülnek, az alatta lévők nem látszanak az ablakban. A szerkeszthető terv (JSON) is mentődik.
 * Használat: BoothTplEd.view(main, { doc, save(o), close(), print(png), ev: {title, date, url}, fonts: [], toast(t), qr(url) }) */
(function () {
  'use strict';
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const PAPER = { '4x6': [1200, 1800] };
  const PH_COL = ['#E8894A', '#7FB069', '#5FA8B8', '#3D4F5C'];
  const SYS_FONTS = ['Montserrat', 'Arial', 'Georgia', 'Segoe UI', 'Times New Roman', 'Courier New'];
  /* rajzolt ikonok (egységes 1.8-as vonal) */
  const P = {
    nw: '<rect x="4" y="4" width="7" height="16" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>',
    save: '<path d="M5 4h11l3 3v13H5z"/><path d="M8 4v5h8V4M8 20v-6h8v6"/>',
    imp: '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>', exp: '<path d="M12 15V4M7 9l5-5 5 5M5 20h14"/>',
    prt: '<path d="M7 9V4h10v5M7 17H5v-7h14v7h-2"/><rect x="7" y="14" width="10" height="6"/>',
    undo: '<path d="M9 7L4 12l5 5"/><path d="M4 12h10a6 6 0 0 1 0 12h-2" transform="translate(0 -6)"/>', redo: '<path d="M15 7l5 5-5 5"/><path d="M20 12H10a6 6 0 0 0 0 12h2" transform="translate(0 -6)"/>',
    lib: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 15l4-4 4 4 3-3 5 5"/><circle cx="15.5" cy="8.5" r="1.5"/>',
    img: '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M4 16l5-5 4 4 2-2 5 5"/><circle cx="15.5" cy="9.5" r="1.5"/>',
    cam: '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>', txt: '<path d="M5 6V4h14v2M12 4v16M9 20h6"/>',
    data: '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>', shp: '<rect x="4" y="11" width="9" height="9"/><circle cx="16" cy="8" r="4.5"/>',
    qr: '<rect x="4" y="4" width="6" height="6"/><rect x="14" y="4" width="6" height="6"/><rect x="4" y="14" width="6" height="6"/><path d="M14 14h2v2h-2zM18 18h2v2h-2zM14 18h2M18 14h2"/>',
    bg: '<path d="M6 13l6-8 6 8a6 6 0 0 1-12 0z"/>', eye: '<path d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z"/><circle cx="12" cy="12" r="2.5"/>',
    eyeoff: '<path d="M3 12s3.5-6 9-6c2 0 3.7.8 5 1.8M21 12s-3.5 6-9 6c-2 0-3.7-.8-5-1.8M4 4l16 16"/>', lock: '<rect x="6" y="11" width="12" height="9" rx="1.5"/><path d="M9 11V8a3 3 0 0 1 6 0v3"/>',
    unlock: '<rect x="6" y="11" width="12" height="9" rx="1.5"/><path d="M9 11V8a3 3 0 0 1 5.8-1"/>', dup: '<rect x="8" y="8" width="11" height="11" rx="1.5"/><path d="M5 15V5h10"/>',
    del: '<path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13"/>', up: '<path d="M12 18V6M7 11l5-5 5 5"/>', down: '<path d="M12 6v12M7 13l5 5 5-5"/>', back: '<path d="M15 5l-7 7 7 7"/>',
    aL: '<path d="M5 4v16M8 8h10v3H8zM8 13h6v3H8z"/>', aC: '<path d="M12 4v16M7 8h10v3H7zM9 13h6v3H9z"/>', aR: '<path d="M19 4v16M6 8h10v3H6zM10 13h6v3h-6z"/>',
    aT: '<path d="M4 5h16M8 8v10h3V8zM13 8v6h3V8z"/>', aM: '<path d="M4 12h16M8 7v10h3V7zM13 9v6h3V9z"/>', aB: '<path d="M4 19h16M8 6v10h3V6zM13 10v6h3v-6z"/>',
    dH: '<path d="M4 4v16M20 4v16M9 8h2v8H9zM13 8h2v8h-2z"/>', dV: '<path d="M4 4h16M4 20h16M8 9h8v2H8zM8 13h8v2H8z"/>'
  };
  const ico = k => '<svg viewBox="0 0 24 24" aria-hidden="true">' + P[k] + '</svg>';
  const uid = () => 'l' + Math.random().toString(36).slice(2, 9);
  const clone = o => JSON.parse(JSON.stringify(o));
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

  /* ---------- kezdő elrendezések */
  function preset(kind, land) {
    const [W, H] = land ? [1800, 1200] : [1200, 1800], m = 60, g = 40;
    const d = { v: 1, paper: '4x6', land: !!land, w: W, h: H, bg: '#FFFFFF', layers: [] };
    const ph = (n, x, y, w, h) => d.layers.push({ id: uid(), type: 'photo', n, name: 'Fotó ' + n, x, y, w, h, rot: 0, vis: 1, lock: 0, r: 0, sw: 0, sc: '#FFFFFF' });
    const fh = Math.round(H * 0.16);
    if (kind === 1) ph(1, m, m, W - 2 * m, H - 2 * m - fh);
    else if (kind === 2) { const h = (H - 2 * m - g - fh) / 2; ph(1, m, m, W - 2 * m, h); ph(2, m, m + h + g, W - 2 * m, h); }
    else if (kind === 3) { const h = (H - 2 * m - 2 * g - fh) / 3; for (let i = 0; i < 3; i++) ph(i + 1, m, m + i * (h + g), W - 2 * m, h); }
    else if (kind === 4) { const w = (W - 2 * m - g) / 2, h = (H - 2 * m - g - fh) / 2; ph(1, m, m, w, h); ph(2, m + w + g, m, w, h); ph(3, m, m + h + g, w, h); ph(4, m + w + g, m + h + g, w, h); }
    if (kind) d.layers.push({ id: uid(), type: 'text', name: 'Felirat', x: m, y: H - m - fh + 10, w: W - 2 * m, h: fh - 20, rot: 0, vis: 1, lock: 0, txt: '{rendezveny}', font: 'Montserrat', size: Math.round(fh * 0.34), col: '#141C33', bold: 1, ital: 0, al: 'center', op: 1 });
    d.layers.forEach(l => { l.x = Math.round(l.x); l.y = Math.round(l.y); l.w = Math.round(l.w); l.h = Math.round(l.h); });
    return d;
  }

  /* ---------- rajzolás (szerkesztő és kimenet közös) */
  const imgCache = new Map();
  function getImg(src) { if (!src) return null; let c = imgCache.get(src); if (!c) { c = new Image(); c.onload = () => { if (DRAW) DRAW(); }; c.src = src; imgCache.set(src, c); } return c.complete && c.naturalWidth ? c : null; }
  let DRAW = null;
  function fillTxt(t, ev) { return String(t || '').replace(/\{rendezveny\}|\{rendezvény\}/gi, ev.title || 'Rendezvény neve').replace(/\{datum\}|\{dátum\}/gi, ev.date || '2026.12.18.'); }
  function shadowOn(g, l, k) { if (l.sh) { g.shadowColor = l.shc || 'rgba(0,0,0,.45)'; g.shadowBlur = (l.shb || 12) * k; g.shadowOffsetX = (l.shd || 6) * k; g.shadowOffsetY = (l.shd || 6) * k; } }
  function rrect(g, x, y, w, h, r) { r = Math.max(0, Math.min(r || 0, w / 2, h / 2)); g.beginPath(); g.moveTo(x + r, y); g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r); g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath(); }
  function drawLayer(g, l, k, mode, ev) {
    if (!l.vis) return;
    g.save(); g.globalAlpha = l.op == null ? 1 : l.op;
    const cx = (l.x + l.w / 2) * k, cy = (l.y + l.h / 2) * k; g.translate(cx, cy); g.rotate((l.rot || 0) * Math.PI / 180);
    const x = -l.w * k / 2, y = -l.h * k / 2, w = l.w * k, h = l.h * k;
    if (l.type === 'photo') {
      if (mode === 'out') { g.globalCompositeOperation = 'destination-out'; g.globalAlpha = 1; rrect(g, x, y, w, h, (l.r || 0) * k); g.fill(); }
      else { g.fillStyle = PH_COL[(l.n - 1) % 4]; rrect(g, x, y, w, h, (l.r || 0) * k); g.fill(); g.fillStyle = 'rgba(255,255,255,.95)'; g.font = '800 ' + Math.round(Math.min(w, h) * 0.38) + 'px Montserrat, "Segoe UI", sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText(String(l.n), 0, 0); }
      if (l.sw) { g.globalCompositeOperation = 'source-over'; g.globalAlpha = 1; g.strokeStyle = l.sc || '#fff'; g.lineWidth = l.sw * k; rrect(g, x - l.sw * k / 2, y - l.sw * k / 2, w + l.sw * k, h + l.sw * k, (l.r || 0) * k); g.stroke(); }
    } else if (l.type === 'image') {
      const im = getImg(l.src); shadowOn(g, l, k);
      if (im) g.drawImage(im, x, y, w, h); else if (mode !== 'out') { g.strokeStyle = '#9AA0AE'; g.setLineDash([8, 6]); g.strokeRect(x, y, w, h); }
    } else if (l.type === 'shape') {
      shadowOn(g, l, k); g.fillStyle = l.fill || '#E8894A';
      if (l.sh2 === 'ell') { g.beginPath(); g.ellipse(0, 0, w / 2, h / 2, 0, 0, Math.PI * 2); } else if (l.sh2 === 'line') { g.beginPath(); g.rect(x, -Math.max(1, (l.sw || 6) * k) / 2, w, Math.max(1, (l.sw || 6) * k)); } else rrect(g, x, y, w, h, (l.r || 0) * k);
      if (l.sh2 !== 'line' && l.fill !== 'none') g.fill(); else if (l.sh2 === 'line') { g.fillStyle = l.sc || '#141C33'; g.fill(); }
      if (l.sh2 !== 'line' && l.sw) { g.shadowColor = 'transparent'; g.strokeStyle = l.sc || '#141C33'; g.lineWidth = l.sw * k; g.stroke(); }
    } else if (l.type === 'text' || l.type === 'data') {
      shadowOn(g, l, k); const t = fillTxt(l.txt, ev), fs = (l.size || 60) * k;
      g.font = (l.ital ? 'italic ' : '') + (l.bold ? '700 ' : '400 ') + fs + 'px "' + (l.font || 'Montserrat') + '", "Segoe UI", sans-serif';
      g.fillStyle = l.col || '#141C33'; g.textBaseline = 'middle'; g.textAlign = l.al || 'center';
      const lines = t.split('\n'), lh = fs * 1.15, tx = l.al === 'left' ? x : l.al === 'right' ? x + w : 0;
      lines.forEach((s, i) => { const yy = (i - (lines.length - 1) / 2) * lh; if (l.sw) { g.strokeStyle = l.sc || '#fff'; g.lineWidth = l.sw * k; g.lineJoin = 'round'; g.strokeText(s, tx, yy, w); } g.fillText(s, tx, yy, w); });
    } else if (l.type === 'qr') {
      const q = l.bits ? l : null; g.fillStyle = l.bgc || '#FFFFFF'; g.fillRect(x, y, w, h);
      if (q && q.n) { const cell = Math.min(w, h) / (q.n + 2); g.fillStyle = l.col || '#141C33'; for (let i = 0; i < q.n; i++) for (let j = 0; j < q.n; j++) if (q.bits[i * q.n + j] === '1') g.fillRect(x + (j + 1) * cell, y + (i + 1) * cell, Math.ceil(cell), Math.ceil(cell)); }
      else if (mode !== 'out') { g.fillStyle = '#141C33'; g.font = '600 ' + Math.round(h * 0.12) + 'px "Segoe UI"'; g.textAlign = 'center'; g.fillText('QR', 0, 0); }
    }
    g.restore();
  }
  function render(doc, k, mode, ev) {
    const c = document.createElement('canvas'); c.width = Math.round(doc.w * k); c.height = Math.round(doc.h * k);
    const g = c.getContext('2d'); g.fillStyle = doc.bg || '#fff'; g.fillRect(0, 0, c.width, c.height);
    doc.layers.forEach(l => drawLayer(g, l, k, mode, ev));
    return c;
  }

  /* ---------- a szerkesztő */
  function view(main, ctx) {
    const ev = ctx.ev || {};
    const st = { doc: ctx.doc ? clone(ctx.doc) : preset(4, false), sel: [], hist: [], fut: [], dirty: false, unit: 'px', keep: true };
    const fonts = [...new Set((ctx.fonts || []).concat(SYS_FONTS))];
    main.innerHTML =
      '<div class="te"><header class="te-top">' +
        '<button type="button" data-a="back" class="te-tb">' + ico('back') + '<span>Vissza</span></button><span class="te-sep"></span>' +
        '<div class="te-menu"><button type="button" data-a="new" class="te-tb">' + ico('nw') + '<span>Új sablon</span></button><div class="te-pop" data-pop="new" hidden>' +
          [[1, '1 fotó'], [2, '2 fotó'], [3, '3 fotó (csík)'], [4, '4 fotó (rács)'], [0, 'Üres lap']].map(x => '<button type="button" data-preset="' + x[0] + '">' + x[1] + '</button>').join('') +
          '<label class="te-chk"><input type="checkbox" data-pland> Fekvő</label></div></div>' +
        '<button type="button" data-a="save" class="te-tb" title="Mentés erre a gépre (Ctrl+S) – a gép azonnal ezt használja">' + ico('save') + '<span>Mentés</span></button>' +
        '<button type="button" data-a="imp" class="te-tb">' + ico('imp') + '<span>Import</span></button>' +
        '<button type="button" data-a="exp" class="te-tb">' + ico('exp') + '<span>Export</span></button>' +
        '<button type="button" data-a="print" class="te-tb">' + ico('prt') + '<span>Próbanyomat</span></button>' +
        (ctx.library ? '<button type="button" data-a="lib" class="te-tb">' + ico('lib') + '<span>Kész sablonok</span></button>' : '') +
        '<span class="te-sep"></span><button type="button" data-a="undo" class="te-tb">' + ico('undo') + '<span>Visszavonás</span></button><button type="button" data-a="redo" class="te-tb">' + ico('redo') + '<span>Újra</span></button>' +
        '<div class="te-title"><b>' + esc(ev.title || 'Sablon') + '</b><small data-stat>Mentve</small></div>' +
      '</header><div class="te-body">' +
        '<aside class="te-l"><h3>Hozzáadás</h3><div class="te-add">' +
          '<button type="button" data-add="image">' + ico('img') + 'Kép</button><button type="button" data-add="photo">' + ico('cam') + 'Fotó a gépről</button>' +
          '<button type="button" data-add="text">' + ico('txt') + 'Szöveg</button>' +
          '<div class="te-menu"><button type="button" data-add="data">' + ico('data') + 'Rendezvény adat<i>›</i></button><div class="te-pop side" data-pop="data" hidden><button type="button" data-dv="{rendezveny}">A rendezvény neve</button><button type="button" data-dv="{datum}">A rendezvény dátuma</button></div></div>' +
          '<div class="te-menu"><button type="button" data-add="shape">' + ico('shp') + 'Alakzat<i>›</i></button><div class="te-pop side" data-pop="shape" hidden><button type="button" data-sv="rect">Téglalap</button><button type="button" data-sv="ell">Ellipszis</button><button type="button" data-sv="line">Vonal</button></div></div>' +
          '<button type="button" data-add="qr">' + ico('qr') + 'QR-kód (galéria)</button>' +
          '<label class="te-bgc">' + ico('bg') + 'Háttérszín<input type="color" data-bg></label></div>' +
          '<h3>Elrendezés</h3><div class="te-lay"><label>Papírméret<select data-paper><option value="4x6">4×6" (10×15 cm)</option></select></label>' +
          '<label>Tájolás<select data-orient><option value="0">Álló</option><option value="1">Fekvő</option></select></label>' +
          '<label>Felbontás<select disabled><option>300 dpi</option></select></label><label>Mértékegység<select data-unit><option value="px">Pixel</option><option value="mm">Milliméter</option></select></label>' +
          '<div class="te-wh"><label>Szélesség<input data-pw readonly></label><label>Magasság<input data-ph readonly></label></div></div>' +
          '<input type="file" accept="image/png,image/jpeg,image/webp" data-file hidden><input type="file" accept=".cbsablon,application/json,image/png" data-impf hidden>' +
        '</aside>' +
        '<main class="te-c" data-stage><div class="te-paper" data-paperbox><canvas data-cv></canvas><div class="te-ov" data-ov></div></div></main>' +
        '<aside class="te-r"><h3>Infó</h3><div class="te-info" data-info></div>' +
          '<h3>Igazítás és elosztás</h3><div class="te-al">' + [['aL', 'Balra'], ['aC', 'Vízszintesen középre'], ['aR', 'Jobbra'], ['aT', 'Fentre'], ['aM', 'Függőlegesen középre'], ['aB', 'Lentre'], ['dH', 'Elosztás vízszintesen (3+ réteg)'], ['dV', 'Elosztás függőlegesen (3+ réteg)']].map(x => '<button type="button" data-al="' + x[0] + '" title="' + x[1] + '" aria-label="' + x[1] + '">' + ico(x[0]) + '</button>').join('') + '</div>' +
          '<h3>Rétegek</h3><div class="te-lys" data-lys></div><div class="te-lyb"><button type="button" data-ly="up" title="Feljebb" aria-label="Réteg feljebb">' + ico('up') + '</button><button type="button" data-ly="down" title="Lejjebb" aria-label="Réteg lejjebb">' + ico('down') + '</button><span></span><button type="button" data-ly="lock" title="Zárolás" aria-label="Zárolás">' + ico('lock') + '</button><button type="button" data-ly="dup" title="Másolat" aria-label="Másolat">' + ico('dup') + '</button><button type="button" data-ly="del" title="Törlés" aria-label="Törlés">' + ico('del') + '</button></div>' +
          '<h3>Beállítások</h3><div class="te-opt" data-opt></div>' +
        '</aside></div></div>';
    const q = s => main.querySelector(s), qa = s => main.querySelectorAll(s);
    const cv = q('[data-cv]'), ov = q('[data-ov]'), stage = q('[data-stage]'), box = q('[data-paperbox]');
    let K = 0.4; /* képpont / lappixel */
    const D = () => st.doc, selL = () => D().layers.filter(l => st.sel.includes(l.id)), one = () => (st.sel.length === 1 ? D().layers.find(l => l.id === st.sel[0]) : null);
    const mm = v => st.unit === 'mm' ? Math.round(v / 300 * 25.4 * 10) / 10 : Math.round(v), unmm = v => st.unit === 'mm' ? Math.round(v / 25.4 * 300) : Math.round(v);
    function snap() { st.hist.push(JSON.stringify(D())); if (st.hist.length > 80) st.hist.shift(); st.fut = []; st.dirty = true; stat(); }
    function stat() { q('[data-stat]').textContent = st.dirty ? 'Nincs mentve' : 'Mentve'; q('[data-stat]').classList.toggle('dirty', st.dirty); q('[data-a=undo]').disabled = !st.hist.length; q('[data-a=redo]').disabled = !st.fut.length; }
    function fit() {
      const r = stage.getBoundingClientRect(), pad = 48;
      K = Math.max(0.08, Math.min((r.width - pad) / D().w, (r.height - pad) / D().h));
      const dpr = window.devicePixelRatio || 1;
      cv.width = Math.round(D().w * K * dpr); cv.height = Math.round(D().h * K * dpr);
      box.style.width = Math.round(D().w * K) + 'px'; box.style.height = Math.round(D().h * K) + 'px';
      draw();
    }
    function draw() {
      const dpr = window.devicePixelRatio || 1, g = cv.getContext('2d'), c = render(D(), K * dpr, 'edit', ev);
      g.clearRect(0, 0, cv.width, cv.height); g.drawImage(c, 0, 0);
      drawOv();
    }
    DRAW = draw;
    function drawOv() {
      ov.innerHTML = D().layers.filter(l => st.sel.includes(l.id) && l.vis).map(l => {
        const s = 'left:' + l.x * K + 'px;top:' + l.y * K + 'px;width:' + l.w * K + 'px;height:' + l.h * K + 'px;transform:rotate(' + (l.rot || 0) + 'deg)';
        return '<div class="te-selb' + (l.lock ? ' lk' : '') + '" style="' + s + '">' + (st.sel.length === 1 && !l.lock ? ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].map(h => '<span class="te-h ' + h + '" data-h="' + h + '"></span>').join('') : '') + '</div>';
      }).join('');
    }
    function panels() { info(); layersList(); opts(); stat(); q('[data-bg]').value = D().bg || '#ffffff'; q('[data-orient]').value = D().land ? '1' : '0'; q('[data-pw]').value = mm(D().w) + ' ' + st.unit; q('[data-ph]').value = mm(D().h) + ' ' + st.unit; }
    function refresh() { draw(); panels(); }
    function info() {
      const l = one(), dis = !l ? ' disabled' : '', v = k => (l ? mm(l[k]) : '');
      q('[data-info]').innerHTML = '<div class="te-row"><span>Pozíció</span><label>X<input type="number" data-i="x" value="' + v('x') + '"' + dis + '></label><label>Y<input type="number" data-i="y" value="' + v('y') + '"' + dis + '></label></div>' +
        '<div class="te-row"><span>Méret</span><label>Sz<input type="number" data-i="w" value="' + v('w') + '"' + dis + '></label><label>M<input type="number" data-i="h" value="' + v('h') + '"' + dis + '></label></div>' +
        '<label class="te-chk"><input type="checkbox" data-keep' + (st.keep ? ' checked' : '') + '> Arány megtartása</label>' +
        '<div class="te-row"><span>Forgatás</span><label>°<input type="number" data-i="rot" value="' + (l ? Math.round(l.rot || 0) : '') + '"' + (l && l.type !== 'photo' ? '' : ' disabled') + '></label></div>' +
        (l && l.type === 'photo' ? '<p class="te-note">A fotóablak nem forgatható – a gép egyenes ablakba teszi a képet.</p>' : '');
    }
    const TN = { photo: 'cam', image: 'img', text: 'txt', data: 'data', shape: 'shp', qr: 'qr' };
    function layersList() {
      const L = D().layers.slice().reverse();
      q('[data-lys]').innerHTML = L.length ? L.map(l => '<div class="te-ly' + (st.sel.includes(l.id) ? ' on' : '') + (l.vis ? '' : ' off') + '" data-lid="' + l.id + '"><button type="button" class="te-ic" data-vis title="' + (l.vis ? 'Elrejtés' : 'Megjelenítés') + '" aria-label="' + (l.vis ? 'Elrejtés' : 'Megjelenítés') + '">' + ico(l.vis ? 'eye' : 'eyeoff') + '</button>' + ico(TN[l.type] || 'shp') + '<span>' + esc(l.name || l.type) + '</span>' + (l.lock ? '<i class="te-lk">' + ico('lock') + '</i>' : '') + '</div>').join('') : '<p class="te-note">Üres lap – adj hozzá elemet balról.</p>';
    }
    function opts() {
      const l = one(), o = q('[data-opt]');
      if (!l) { o.innerHTML = '<p class="te-note">' + (st.sel.length > 1 ? st.sel.length + ' réteg kijelölve – igazíthatod vagy eloszthatod őket.' : 'Jelölj ki egy réteget a lapon vagy a listában.') + '</p>'; return; }
      const c = (k, lab, d) => '<label class="te-f">' + lab + '<input type="color" data-o="' + k + '" value="' + esc(l[k] || d) + '"></label>';
      const n = (k, lab, a, b, d) => '<label class="te-f">' + lab + '<input type="range" min="' + a + '" max="' + b + '" data-o="' + k + '" value="' + (l[k] != null ? l[k] : d) + '"><output>' + (l[k] != null ? l[k] : d) + '</output></label>';
      let h = '<label class="te-f">Név<input type="text" data-o="name" value="' + esc(l.name || '') + '" maxlength="40"></label>';
      if (l.type === 'photo') h += '<label class="te-f">Fotó sorszáma<select data-o="n">' + [1, 2, 3, 4].map(i => '<option' + (l.n === i ? ' selected' : '') + '>' + i + '</option>').join('') + '</select></label>' + n('r', 'Lekerekítés', 0, 200, 0) + n('sw', 'Keret vastagsága', 0, 40, 0) + c('sc', 'Keret színe', '#FFFFFF');
      if (l.type === 'text' || l.type === 'data') h += (l.type === 'text' ? '<label class="te-f">Szöveg<textarea data-o="txt" rows="3">' + esc(l.txt) + '</textarea></label>' : '<p class="te-note">Ide a rendezvény ' + (/datum/.test(l.txt) ? 'dátuma' : 'neve') + ' kerül: ' + esc(fillTxt(l.txt, ev)) + '</p>') +
        '<label class="te-f">Betűtípus<select data-o="font">' + fonts.map(f => '<option' + (l.font === f ? ' selected' : '') + ' style="font-family:\'' + esc(f) + '\'">' + esc(f) + '</option>').join('') + '</select></label>' + n('size', 'Betűméret', 12, 400, 60) + c('col', 'Szín', '#141C33') +
        '<div class="te-seg"><button type="button" data-tog="bold" class="' + (l.bold ? 'on' : '') + '"><b>F</b></button><button type="button" data-tog="ital" class="' + (l.ital ? 'on' : '') + '"><i>D</i></button><span></span>' + ['left', 'center', 'right'].map(a => '<button type="button" data-o-al="' + a + '" class="' + ((l.al || 'center') === a ? 'on' : '') + '">' + ({ left: 'Bal', center: 'Közép', right: 'Jobb' })[a] + '</button>').join('') + '</div>' + n('sw', 'Körvonal', 0, 20, 0) + c('sc', 'Körvonal színe', '#FFFFFF');
      if (l.type === 'shape') h += (l.sh2 !== 'line' ? c('fill', 'Kitöltés', '#E8894A') + n('r', 'Lekerekítés', 0, 300, 0) : '') + n('sw', l.sh2 === 'line' ? 'Vastagság' : 'Körvonal', l.sh2 === 'line' ? 1 : 0, 60, l.sh2 === 'line' ? 6 : 0) + c('sc', l.sh2 === 'line' ? 'Szín' : 'Körvonal színe', '#141C33');
      if (l.type === 'image') h += '<button type="button" class="te-btn" data-repl>Kép cseréje…</button>';
      if (l.type === 'qr') h += '<p class="te-note">A rendezvény online galériájára mutat' + (ev.url ? '' : ' – ehhez rendezvény és gépkulcs kell') + '.</p>' + c('col', 'Szín', '#141C33') + c('bgc', 'Háttér', '#FFFFFF');
      if (l.type !== 'photo') h += n('op', 'Átlátszatlanság (%)', 10, 100, 100).replace('value="' + (l.op != null ? l.op : 100) + '"', 'value="' + Math.round((l.op == null ? 1 : l.op) * 100) + '"').replace('<output>' + (l.op != null ? l.op : 100) + '</output>', '<output>' + Math.round((l.op == null ? 1 : l.op) * 100) + '</output>') +
        '<label class="te-chk"><input type="checkbox" data-o="sh"' + (l.sh ? ' checked' : '') + '> Árnyék</label>' + (l.sh ? c('shc', 'Árnyék színe', '#000000') + n('shb', 'Elmosás', 0, 60, 12) + n('shd', 'Távolság', 0, 60, 6) : '');
      o.innerHTML = h;
    }
    /* ---------- műveletek */
    function add(type, x) {
      snap(); const d = D(), W = d.w, H = d.h;
      let l = { id: uid(), type, x: Math.round(W * 0.25), y: Math.round(H * 0.35), w: Math.round(W * 0.5), h: Math.round(H * 0.15), rot: 0, vis: 1, lock: 0, op: 1 };
      if (type === 'photo') { const n = Math.min(4, d.layers.filter(z => z.type === 'photo').length + 1); Object.assign(l, { n, name: 'Fotó ' + n, w: Math.round(W * 0.6), h: Math.round(W * 0.6 * 2 / 3), r: 0, sw: 0, sc: '#FFFFFF' }); delete l.op; }
      if (type === 'text') Object.assign(l, { name: 'Szöveg', txt: 'Írd ide a szöveget', font: 'Montserrat', size: 90, col: '#141C33', bold: 1, ital: 0, al: 'center' });
      if (type === 'data') Object.assign(l, { name: x === '{datum}' ? 'Dátum' : 'Rendezvény neve', txt: x, font: 'Montserrat', size: 80, col: '#141C33', bold: 0, ital: 0, al: 'center' });
      if (type === 'shape') Object.assign(l, { name: ({ rect: 'Téglalap', ell: 'Ellipszis', line: 'Vonal' })[x], sh2: x, fill: '#E8894A', sw: x === 'line' ? 8 : 0, sc: '#141C33', r: 0 }, x === 'line' ? { h: 40 } : x === 'ell' ? { w: Math.round(W * 0.3), h: Math.round(W * 0.3) } : {});
      if (type === 'qr') Object.assign(l, { name: 'QR-kód', w: 300, h: 300, x: W - 360, y: H - 360, col: '#141C33', bgc: '#FFFFFF' });
      if (type === 'image') Object.assign(l, { name: x.name || 'Kép', src: x.src, w: x.w, h: x.h, x: Math.round((W - x.w) / 2), y: Math.round((H - x.h) / 2) });
      l.x = clamp(l.x, 0, W - 20); l.y = clamp(l.y, 0, H - 20);
      d.layers.push(l); st.sel = [l.id];
      if (type === 'qr' && ev.url && ctx.qr) ctx.qr(ev.url).then(r => { if (r && r.n) { l.n = r.n; l.bits = r.bits; refresh(); } }).catch(() => {});
      refresh();
    }
    function undo() { if (!st.hist.length) return; st.fut.push(JSON.stringify(D())); st.doc = JSON.parse(st.hist.pop()); st.sel = st.sel.filter(id => D().layers.some(l => l.id === id)); st.dirty = true; fit(); panels(); }
    function redo() { if (!st.fut.length) return; st.hist.push(JSON.stringify(D())); st.doc = JSON.parse(st.fut.pop()); st.dirty = true; fit(); panels(); }
    function del() { const L = selL().filter(l => !l.lock); if (!L.length) return; snap(); D().layers = D().layers.filter(l => !L.includes(l)); st.sel = []; refresh(); }
    function dup() { const L = selL(); if (!L.length) return; snap(); const n = L.map(l => Object.assign(clone(l), { id: uid(), x: l.x + 40, y: l.y + 40, name: (l.name || '') + ' másolat', lock: 0 })); D().layers.push(...n); st.sel = n.map(l => l.id); refresh(); }
    function move(dir) { const l = one(); if (!l) return; const L = D().layers, i = L.indexOf(l), j = i + dir; if (j < 0 || j >= L.length) return; snap(); L.splice(i, 1); L.splice(j, 0, l); refresh(); }
    function align(a) {
      const L = selL().filter(l => !l.lock); if (!L.length) return; snap();
      const one1 = L.length === 1, W = D().w, H = D().h;
      const bx = one1 ? { x0: 0, y0: 0, x1: W, y1: H } : { x0: Math.min(...L.map(l => l.x)), y0: Math.min(...L.map(l => l.y)), x1: Math.max(...L.map(l => l.x + l.w)), y1: Math.max(...L.map(l => l.y + l.h)) };
      if (a === 'dH' || a === 'dV') {
        if (L.length < 3) { ctx.toast && ctx.toast('Elosztáshoz legalább 3 réteget jelölj ki (Shift + kattintás).'); return; }
        const h = a === 'dH', S = L.slice().sort((p, r) => h ? p.x - r.x : p.y - r.y), tot = S.reduce((s, l) => s + (h ? l.w : l.h), 0), gap = ((h ? bx.x1 - bx.x0 : bx.y1 - bx.y0) - tot) / (S.length - 1);
        let p = h ? bx.x0 : bx.y0; S.forEach(l => { if (h) l.x = Math.round(p); else l.y = Math.round(p); p += (h ? l.w : l.h) + gap; });
      } else L.forEach(l => { if (a === 'aL') l.x = bx.x0; if (a === 'aR') l.x = bx.x1 - l.w; if (a === 'aC') l.x = Math.round((bx.x0 + bx.x1 - l.w) / 2); if (a === 'aT') l.y = bx.y0; if (a === 'aB') l.y = bx.y1 - l.h; if (a === 'aM') l.y = Math.round((bx.y0 + bx.y1 - l.h) / 2); });
      refresh();
    }
    function setOrient(land) {
      const d = D(); if (!!d.land === !!land) return; snap();
      /* arányos kicsinyítés a lapra, középre – a fotóablakok aránya nem torzul */
      const [W, H] = land ? [1800, 1200] : [1200, 1800], k = Math.min(W / d.w, H / d.h), ox = (W - d.w * k) / 2, oy = (H - d.h * k) / 2;
      d.layers.forEach(l => { l.x = Math.round(ox + l.x * k); l.y = Math.round(oy + l.y * k); l.w = Math.round(l.w * k); l.h = Math.round(l.h * k); if (l.size) l.size = Math.round(l.size * k); if (l.sw) l.sw = Math.max(1, Math.round(l.sw * k)); if (l.r) l.r = Math.round(l.r * k); });
      d.w = W; d.h = H; d.land = !!land; fit(); panels();
    }
    async function save() {
      const d = D(), ph = d.layers.filter(l => l.type === 'photo' && l.vis);
      if (!ph.length) { ctx.toast && ctx.toast('Legalább egy „Fotó a gépről” réteg kell – ide kerül a vendég fotója.'); return; }
      if (d.layers.some(l => l.type === 'image' && !getImg(l.src))) { ctx.toast && ctx.toast('Egy kép még töltődik – próbáld újra egy pillanat múlva.'); return; }
      const png = render(d, 1, 'out', ev).toDataURL('image/png');
      const btn = q('[data-a=save]'); btn.disabled = true;
      try { await ctx.save({ png, doc: clone(d) }); st.dirty = false; stat(); ctx.toast && ctx.toast('Sablon mentve – a gép mostantól ezt használja.'); }
      catch (e) { ctx.toast && ctx.toast(e.message || 'A mentés nem sikerült.'); }
      finally { btn.disabled = false; }
    }
    function exportDoc() {
      const b = new Blob([JSON.stringify({ app: 'CrazyBooth', kind: 'sablon', doc: D() })], { type: 'application/json' });
      const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = (ev.title || 'sablon').replace(/[^\wÁÉÍÓÖŐÚÜŰáéíóöőúüű -]+/g, '').trim().slice(0, 60) + '.cbsablon'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    }
    function importFile(f) {
      const r = new FileReader();
      r.onload = () => {
        try {
          if (/^image\//.test(f.type)) { const im = new Image(); im.onload = () => { snap(); const land = im.naturalWidth > im.naturalHeight; const d = preset(0, land); d.layers.push({ id: uid(), type: 'image', name: 'Importált sablon', src: r.result, x: 0, y: 0, w: d.w, h: d.h, rot: 0, vis: 1, lock: 1, op: 1 }); st.doc = d; st.sel = []; fit(); panels(); ctx.toast && ctx.toast('A kép az alsó, zárolt rétegre került – tegyél rá „Fotó a gépről” ablakokat.'); }; im.src = r.result; return; }
          const j = JSON.parse(r.result); if (!j || !j.doc || !Array.isArray(j.doc.layers)) throw new Error('x');
          snap(); st.doc = j.doc; st.sel = []; fit(); panels(); ctx.toast && ctx.toast('Sablon betöltve.');
        } catch (e) { ctx.toast && ctx.toast('Ez nem Crazy Booth sablonfájl.'); }
      };
      if (/^image\//.test(f.type)) r.readAsDataURL(f); else r.readAsText(f);
    }
    /* ---------- események */
    main.addEventListener('click', e => {
      const t = e.target.closest('button,[data-lid]'); if (!t || !main.contains(t)) return;
      const pop = t.closest('.te-menu') && t.parentElement.classList.contains('te-pop') ? null : null; void pop;
      if (t.dataset.a) {
        const a = t.dataset.a;
        if (a === 'new' || a === undefined) { qa('[data-pop]').forEach(p => { if (p.dataset.pop !== 'new') p.hidden = true; }); q('[data-pop=new]').hidden = !q('[data-pop=new]').hidden; return; }
        qa('[data-pop]').forEach(p => { p.hidden = true; });
        if (a === 'back') { if (st.dirty && !window.confirm('A sablon nincs mentve. Kilépsz mentés nélkül?')) return; DRAW = null; ctx.close && ctx.close(); }
        if (a === 'save') save(); if (a === 'exp') exportDoc(); if (a === 'imp') q('[data-impf]').click();
        if (a === 'undo') undo(); if (a === 'redo') redo(); if (a === 'lib' && ctx.library) ctx.library();
        if (a === 'print' && ctx.print) { const d = D(); if (!d.layers.some(l => l.type === 'photo')) { ctx.toast && ctx.toast('Nincs fotóablak a sablonban.'); return; } ctx.print(render(d, 1, 'edit', ev).toDataURL('image/jpeg', 0.92)); }
        return;
      }
      if (t.dataset.preset != null) { if (st.dirty && D().layers.length && !window.confirm('Új sablont kezdesz – a mostani változások elvesznek, ha nem mentetted. Mehet?')) return; snap(); st.doc = preset(Number(t.dataset.preset), q('[data-pland]').checked); st.sel = []; q('[data-pop=new]').hidden = true; fit(); panels(); return; }
      if (t.dataset.add) {
        const a = t.dataset.add;
        if (a === 'data' || a === 'shape') { qa('[data-pop]').forEach(p => { if (p.dataset.pop !== a) p.hidden = true; }); q('[data-pop=' + a + ']').hidden = !q('[data-pop=' + a + ']').hidden; return; }
        qa('[data-pop]').forEach(p => { p.hidden = true; });
        if (a === 'image') { q('[data-file]').click(); return; }
        add(a); return;
      }
      if (t.dataset.dv) { qa('[data-pop]').forEach(p => { p.hidden = true; }); add('data', t.dataset.dv); return; }
      if (t.dataset.sv) { qa('[data-pop]').forEach(p => { p.hidden = true; }); add('shape', t.dataset.sv); return; }
      if (t.dataset.al) { align(t.dataset.al); return; }
      if (t.dataset.ly) { const k = t.dataset.ly; if (k === 'up') move(1); if (k === 'down') move(-1); if (k === 'del') del(); if (k === 'dup') dup(); if (k === 'lock') { const L = selL(); if (L.length) { snap(); const on = !L.every(l => l.lock); L.forEach(l => { l.lock = on ? 1 : 0; }); refresh(); } } return; }
      if (t.hasAttribute('data-vis')) { const l = D().layers.find(z => z.id === t.closest('[data-lid]').dataset.lid); snap(); l.vis = l.vis ? 0 : 1; refresh(); e.stopPropagation(); return; }
      if (t.dataset.lid) { const id = t.dataset.lid; st.sel = e.shiftKey ? (st.sel.includes(id) ? st.sel.filter(x => x !== id) : st.sel.concat(id)) : [id]; refresh(); return; }
      if (t.dataset.tog) { const l = one(); snap(); l[t.dataset.tog] = l[t.dataset.tog] ? 0 : 1; refresh(); return; }
      if (t.dataset.oAl) { const l = one(); snap(); l.al = t.dataset.oAl; refresh(); return; }
      if (t.hasAttribute('data-repl')) { q('[data-file]').dataset.repl = '1'; q('[data-file]').click(); }
    });
    q('[data-file]').addEventListener('change', e => {
      const f = e.target.files && e.target.files[0], repl = e.target.dataset.repl; e.target.value = ''; delete e.target.dataset.repl; if (!f) return;
      if (f.size > 25 * 1048576) { ctx.toast && ctx.toast('A kép túl nagy (legfeljebb 25 MB).'); return; }
      const r = new FileReader(); r.onload = () => { const im = new Image(); im.onload = () => {
        const s = Math.min(1, D().w * 0.8 / im.naturalWidth, D().h * 0.8 / im.naturalHeight), w = Math.round(im.naturalWidth * s), h = Math.round(im.naturalHeight * s);
        if (repl && one()) { snap(); one().src = r.result; refresh(); } else add('image', { src: r.result, w, h, name: f.name.replace(/\.[^.]+$/, '').slice(0, 30) });
      }; im.src = r.result; }; r.readAsDataURL(f);
    });
    q('[data-impf]').addEventListener('change', e => { const f = e.target.files && e.target.files[0]; e.target.value = ''; if (f) importFile(f); });
    q('[data-bg]').addEventListener('change', e => { snap(); D().bg = e.target.value; refresh(); });
    q('[data-orient]').addEventListener('change', e => setOrient(e.target.value === '1'));
    q('[data-unit]').addEventListener('change', e => { st.unit = e.target.value; panels(); });
    /* Infó mezők */
    q('[data-info]').addEventListener('change', e => {
      if (e.target.hasAttribute('data-keep')) { st.keep = e.target.checked; return; }
      const l = one(), k = e.target.dataset.i; if (!l || !k) return; snap();
      const v = Number(e.target.value) || 0;
      if (k === 'rot') l.rot = ((v % 360) + 360) % 360;
      else if (k === 'w' || k === 'h') { const nv = Math.max(10, unmm(v)), r = l.w / l.h; if (k === 'w') { l.w = nv; if (st.keep) l.h = Math.round(nv / r); } else { l.h = nv; if (st.keep) l.w = Math.round(nv * r); } }
      else l[k] = unmm(v);
      refresh();
    });
    /* Beállítások */
    const optSet = (e, live) => {
      const l = one(), k = e.target.dataset.o; if (!l || !k) return;
      let v = e.target.type === 'checkbox' ? (e.target.checked ? 1 : 0) : e.target.value;
      if (e.target.type === 'range') { v = Number(v); const out = e.target.nextElementSibling; if (out) out.textContent = v; if (k === 'op') v = v / 100; }
      if (k === 'n') v = Number(v);
      if (!live) snap(); l[k] = v; if (k === 'n' && l.type === 'photo' && /^Fotó \d$/.test(l.name || '')) l.name = 'Fotó ' + v;
      draw(); if (!live && (k === 'sh' || k === 'name' || k === 'n')) panels();
    };
    q('[data-opt]').addEventListener('input', e => { if (e.target.type === 'range' || e.target.type === 'color') optSet(e, true); else if (e.target.dataset.o === 'txt') { one().txt = e.target.value; draw(); } });
    q('[data-opt]').addEventListener('change', e => { if (e.target.dataset.o === 'txt') { snap(); one().txt = e.target.value; draw(); return; } optSet(e, false); });
    /* húzás, átméretezés a lapon */
    const pt = e => { const r = box.getBoundingClientRect(); return { x: (e.clientX - r.left) / K, y: (e.clientY - r.top) / K }; };
    const hit = (p) => { const L = D().layers; for (let i = L.length - 1; i >= 0; i--) { const l = L[i]; if (!l.vis) continue; const a = -(l.rot || 0) * Math.PI / 180, cx = l.x + l.w / 2, cy = l.y + l.h / 2, dx = p.x - cx, dy = p.y - cy, lx = dx * Math.cos(a) - dy * Math.sin(a), ly = dx * Math.sin(a) + dy * Math.cos(a); if (Math.abs(lx) <= l.w / 2 && Math.abs(ly) <= l.h / 2) return l; } return null; };
    let drag = null;
    box.addEventListener('pointerdown', e => {
      qa('[data-pop]').forEach(p => { p.hidden = true; });
      const p = pt(e), h = e.target.dataset && e.target.dataset.h;
      if (h) { const l = one(); drag = { mode: 'rs', h, l, p0: p, o: { x: l.x, y: l.y, w: l.w, h: l.h }, snapped: false }; box.setPointerCapture(e.pointerId); return; }
      const l = hit(p);
      if (!l) { st.sel = []; refresh(); return; }
      if (e.shiftKey) { st.sel = st.sel.includes(l.id) ? st.sel.filter(x => x !== l.id) : st.sel.concat(l.id); refresh(); return; }
      if (!st.sel.includes(l.id)) { st.sel = [l.id]; refresh(); }
      const L = selL().filter(z => !z.lock); if (!L.length) return;
      drag = { mode: 'mv', p0: p, L, o: L.map(z => ({ x: z.x, y: z.y })), snapped: false }; box.setPointerCapture(e.pointerId);
    });
    box.addEventListener('pointermove', e => {
      if (!drag) return; const p = pt(e), dx = p.x - drag.p0.x, dy = p.y - drag.p0.y;
      if (!drag.snapped) { if (Math.abs(dx) + Math.abs(dy) < 3 / K) return; snap(); drag.snapped = true; }
      const W = D().w, H = D().h, sn = 12 / K;
      if (drag.mode === 'mv') {
        drag.L.forEach((l, i) => { l.x = Math.round(drag.o[i].x + dx); l.y = Math.round(drag.o[i].y + dy); });
        if (drag.L.length === 1 && !e.altKey) { const l = drag.L[0]; /* tapadás a lap széleihez és közepéhez */
          [[0, 0], [W / 2 - l.w / 2, 0], [W - l.w, 0]].forEach(([v]) => { if (Math.abs(l.x - v) < sn) l.x = Math.round(v); });
          [0, H / 2 - l.h / 2, H - l.h].forEach(v => { if (Math.abs(l.y - v) < sn) l.y = Math.round(v); }); }
      } else {
        const l = drag.l, o = drag.o, h = drag.h, r = o.w / o.h; let x = o.x, y = o.y, w = o.w, hh = o.h;
        if (h.includes('e')) w = Math.max(20, o.w + dx); if (h.includes('s')) hh = Math.max(20, o.h + dy);
        if (h.includes('w')) { w = Math.max(20, o.w - dx); x = o.x + o.w - w; } if (h.includes('n')) { hh = Math.max(20, o.h - dy); y = o.y + o.h - hh; }
        if ((st.keep || e.shiftKey) && h.length === 2) { if (w / hh > r) w = Math.round(hh * r); else hh = Math.round(w / r); if (h.includes('w')) x = o.x + o.w - w; if (h.includes('n')) y = o.y + o.h - hh; }
        Object.assign(l, { x: Math.round(x), y: Math.round(y), w: Math.round(w), h: Math.round(hh) });
      }
      draw(); info();
    });
    const end = () => { if (drag) { drag = null; panels(); } };
    box.addEventListener('pointerup', end); box.addEventListener('pointercancel', end);
    const key = e => {
      if (!main.isConnected) { document.removeEventListener('keydown', key); window.removeEventListener('resize', fit); return; }
      if (/INPUT|TEXTAREA|SELECT/.test(document.activeElement && document.activeElement.tagName)) return;
      const mod = e.ctrlKey || e.metaKey;
      if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
      if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); return; }
      if (mod && e.key.toLowerCase() === 'd') { e.preventDefault(); dup(); return; }
      if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); save(); return; }
      if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); del(); return; }
      const ar = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
      if (ar) { const L = selL().filter(l => !l.lock); if (!L.length) return; e.preventDefault(); snap(); const s = e.shiftKey ? 20 : 2; L.forEach(l => { l.x += ar[0] * s; l.y += ar[1] * s; }); refresh(); }
    };
    document.addEventListener('keydown', key);
    window.addEventListener('resize', fit);
    stat(); requestAnimationFrame(() => { fit(); panels(); });
    return { render: m => render(D(), 1, m || 'out', ev), doc: () => clone(D()) };
  }
  window.BoothTplEd = { view, preset, render };
})();
