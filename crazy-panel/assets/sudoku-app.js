/* Klasszikus Szudoku – játéklogika és felület. */
(function () {
  'use strict';

  const E = window.SudokuEngine;
  const I18N = window.SudokuI18n;
  const { ROW, COL, BOX, PEERS, DIFFICULTIES } = E;
  const $ = (id) => document.getElementById(id);

  const KEY_GAME = 'szudoku.game.v1';
  const KEY_SETTINGS = 'szudoku.settings.v1';
  const KEY_STATS = 'szudoku.stats.v1';
  const KEY_MP = 'szudoku.mp.v1';
  const KEY_NAME = 'szudoku.name.v1';
  const MAX_MISTAKES = 3;
  // következtetés-bónusz szorzója szintenként (a nehézség alappontjára)
  const DEDUCE_MULT = [0, 2, 5, 8];

  const store = {
    get(key, fallback) {
      try { const v = localStorage.getItem(key); return v ? JSON.parse(v) : fallback; } catch (e) { return fallback; }
    },
    set(key, value) {
      try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* privát mód */ }
    },
    del(key) { try { localStorage.removeItem(key); } catch (e) { /* */ } },
  };

  const settings = Object.assign({
    mistakeLimit: true,
    showErrors: true,
    highlightSame: true,
    highlightPeers: true,
    autoRemoveNotes: true,
    hideDone: true,
    showTimer: true,
    split: false,
    gecko: false,
    lang: /^hu/i.test(navigator.language || 'hu') ? 'hu' : 'en',
  }, store.get(KEY_SETTINGS, {}));
  function saveSettings() { store.set(KEY_SETTINGS, settings); }

  let stats = store.get(KEY_STATS, { total: 0, byDiff: {} });

  let game = null;       // aktuális játék állapota
  let timerId = null;
  let paused = false;

  /* ---------- Nyelv ---------- */

  function t(key, vars) {
    let s = (I18N[settings.lang] || I18N.hu)[key];
    if (s == null) s = I18N.hu[key] != null ? I18N.hu[key] : key;
    if (vars) s = s.replace(/\{(\w+)\}/g, (m, k) => (vars[k] != null ? vars[k] : m));
    return s;
  }
  function fmtNum(n) { return Number(n).toLocaleString(settings.lang === 'en' ? 'en-US' : 'hu-HU'); }
  function diffName(key) { return t('diff.' + key); }

  function applyI18n() {
    document.documentElement.lang = settings.lang;
    document.title = t('app.title');
    document.querySelectorAll('[data-i18n]').forEach((el) => { el.textContent = t(el.dataset.i18n); });
    document.querySelectorAll('[data-i18n-ph]').forEach((el) => { el.placeholder = t(el.dataset.i18nPh); });
    document.querySelectorAll('[data-i18n-aria]').forEach((el) => { el.setAttribute('aria-label', t(el.dataset.i18nAria)); });
    document.querySelectorAll('[data-lang]').forEach((el) => el.classList.toggle('on', el.dataset.lang === settings.lang));
  }

  function setLang(lang) {
    if (!I18N[lang]) return;
    settings.lang = lang;
    saveSettings();
    applyI18n();
    if ($('home').classList.contains('active')) renderHome();
    else if (game) render();
  }

  /* ---------- Gecko mód ---------- */

  const GECKO_SVG =
    '<svg viewBox="0 0 60 120" class="gk-svg" aria-hidden="true">' +
    '<g stroke-linecap="round" fill="none">' +
    '<g class="leg l1"><path d="M23 40 L10 33 L6 24"/><circle cx="6" cy="24" r="3"/></g>' +
    '<g class="leg l2"><path d="M37 40 L50 33 L54 24"/><circle cx="54" cy="24" r="3"/></g>' +
    '<g class="leg l3"><path d="M23 66 L10 75 L7 85"/><circle cx="7" cy="85" r="3"/></g>' +
    '<g class="leg l4"><path d="M37 66 L50 75 L53 85"/><circle cx="53" cy="85" r="3"/></g></g>' +
    '<path class="tail" d="M30 74 C30 92 44 96 40 108 C37 117 28 115 31 108" fill="none" stroke-linecap="round"/>' +
    '<ellipse class="body" cx="30" cy="54" rx="11" ry="22"/>' +
    '<ellipse class="head" cx="30" cy="24" rx="10" ry="12"/>' +
    '<circle class="spot" cx="27" cy="48" r="2.2"/><circle class="spot" cx="33" cy="57" r="2.2"/><circle class="spot" cx="28" cy="65" r="1.8"/>' +
    '<circle class="eye" cx="24.5" cy="19" r="3.4"/><circle class="eye" cx="35.5" cy="19" r="3.4"/>' +
    '<circle class="pupil" cx="24.5" cy="18.5" r="1.4"/><circle class="pupil" cx="35.5" cy="18.5" r="1.4"/>' +
    '</svg>';

  function applyTheme() {
    const on = !!settings.gecko;
    document.documentElement.classList.toggle('gecko', on);
    document.querySelectorAll('.theme-emoji').forEach((el) => { el.textContent = on ? '🦎' : '🎃'; });
    document.querySelectorAll('[data-gecko-toggle]').forEach((el) => el.setAttribute('aria-pressed', on ? 'true' : 'false'));
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.content = on ? '#0f2416' : '#1b1d21';
  }

  function toggleGecko() {
    settings.gecko = !settings.gecko;
    saveSettings();
    applyTheme();
    document.querySelectorAll('[data-setting="gecko"]').forEach((el) => { el.checked = settings.gecko; });
    if (settings.gecko) {
      toast(t('toast.geckoOn'));
      if ($('game').classList.contains('active')) geckoRunBoard(Math.floor(Math.random() * 9), 'row');
      else geckoScreenRun(2);
    }
  }

  function makeGecko(cls) {
    const el = document.createElement('div');
    el.className = 'gk ' + cls;
    el.innerHTML = GECKO_SVG;
    return el;
  }

  /* Gecko kikukucskál egy mező széléről. */
  function geckoPeek(i) {
    if (!settings.gecko) return;
    const fx = $('fx');
    const size = fx.clientWidth / 9;
    const g = makeGecko('peek');
    const side = Math.floor(Math.random() * 4); // honnan bújik elő
    const ang = [180, 270, 0, 90][side];
    const cx = (COL[i] + 0.5) * size, cy = (ROW[i] + 0.5) * size;
    const off = [[0, -0.55], [0.55, 0], [0, 0.55], [-0.55, 0]][side];
    g.style.width = size * 0.9 + 'px';
    g.style.height = size * 1.8 + 'px';
    fx.appendChild(g);
    const at = (dx, dy, a, s) => 'translate(' + (cx + dx * size - size * 0.45) + 'px,' + (cy + dy * size - size * 0.9) + 'px) rotate(' + a + 'deg) scale(' + s + ')';
    const anim = g.animate([
      { transform: at(off[0], off[1], ang, 0.2), opacity: 0 },
      { transform: at(off[0] * 0.2, off[1] * 0.2, ang, 0.85), opacity: 1, offset: 0.25 },
      { transform: at(off[0] * 0.2, off[1] * 0.2, ang + 14, 0.85), opacity: 1, offset: 0.5 },
      { transform: at(off[0] * 0.2, off[1] * 0.2, ang - 14, 0.85), opacity: 1, offset: 0.72 },
      { transform: at(off[0], off[1], ang, 0.2), opacity: 0 },
    ], { duration: 1500, easing: 'ease-in-out' });
    anim.onfinish = () => g.remove();
  }

  /* Gecko átrohan egy soron / oszlopon / blokkon (index = sor, oszlop vagy blokk száma). */
  function geckoRunBoard(index, kind) {
    if (!settings.gecko) return;
    const fx = $('fx');
    const W = fx.clientWidth, size = W / 9;
    const g = makeGecko('run');
    const gw = size * 0.8, gh = size * 1.6;
    g.style.width = gw + 'px';
    g.style.height = gh + 'px';
    fx.appendChild(g);
    const rtl = Math.random() < 0.5;
    let x0, y0, x1, y1;
    if (kind === 'row') {
      y0 = y1 = (index + 0.5) * size;
      x0 = rtl ? W + gh : -gh; x1 = rtl ? -gh : W + gh;
    } else if (kind === 'col') {
      x0 = x1 = (index + 0.5) * size;
      y0 = rtl ? W + gh : -gh; y1 = rtl ? -gh : W + gh;
    } else {
      const br = Math.floor(index / 3) * 3, bc = (index % 3) * 3;
      x0 = (bc - 1) * size; y0 = (br - 1) * size; x1 = (bc + 4) * size; y1 = (br + 4) * size;
      if (rtl) { const tx = x0, ty = y0; x0 = x1; y0 = y1; x1 = tx; y1 = ty; }
    }
    const ang = Math.atan2(y1 - y0, x1 - x0) * 180 / Math.PI + 90;
    const tf = (x, y) => 'translate(' + (x - gw / 2) + 'px,' + (y - gh / 2) + 'px) rotate(' + ang + 'deg)';
    const anim = g.animate([{ transform: tf(x0, y0) }, { transform: tf(x1, y1) }], { duration: 1100, easing: 'linear' });
    anim.onfinish = () => g.remove();
  }

  /* Geckók szaladnak át az egész képernyőn (győzelem). */
  function geckoScreenRun(n) {
    if (!settings.gecko) return;
    const layer = $('screenFx');
    const W = layer.clientWidth, H = layer.clientHeight;
    for (let k = 0; k < n; k++) {
      setTimeout(() => {
        const g = makeGecko('run');
        const gw = 44, gh = 88;
        g.style.width = gw + 'px';
        g.style.height = gh + 'px';
        layer.appendChild(g);
        const y0 = H * (0.15 + Math.random() * 0.7), y1 = H * (0.15 + Math.random() * 0.7);
        const ltr = Math.random() < 0.5;
        const x0 = ltr ? -gh : W + gh, x1 = ltr ? W + gh : -gh;
        const ang = Math.atan2(y1 - y0, x1 - x0) * 180 / Math.PI + 90;
        const tf = (x, y) => 'translate(' + (x - gw / 2) + 'px,' + (y - gh / 2) + 'px) rotate(' + ang + 'deg)';
        const anim = g.animate([{ transform: tf(x0, y0) }, { transform: tf(x1, y1) }], { duration: 1400 + Math.random() * 600, easing: 'linear' });
        anim.onfinish = () => g.remove();
      }, k * 260);
    }
  }

  /* Felszálló felirat egy mező fölött (pl. következtetés-bónusz). */
  function celebrate(i, text, big) {
    const layer = $('fxLabels');
    const W = layer.clientWidth, size = W / 9;
    const el = document.createElement('div');
    el.className = 'fx-label' + (big ? ' big' : '');
    el.textContent = text;
    el.style.top = ROW[i] * size + 'px';
    layer.appendChild(el);
    // a felirat ne lógjon ki a tábla szélén
    const half = el.offsetWidth / 2 + 4;
    el.style.left = Math.min(Math.max((COL[i] + 0.5) * size, half), W - half) + 'px';
    setTimeout(() => el.remove(), 1900);
  }

  /* ---------- Játék létrehozása / mentése ---------- */

  function freshGame(diffKey, puzzle, solution) {
    const diff = DIFFICULTIES[diffKey];
    return {
      diff: diffKey,
      puzzle,
      solution,
      values: puzzle.slice(),
      notes: new Array(81).fill(0),
      locked: puzzle.map((v) => (v ? 1 : 0)), // 1 = adott, 2 = tippből/helyesen beírt
      tried: new Array(81).fill(0),           // 1 = ide már próbált hibás számot (nincs következtetés-bónusz)
      autoMask: new Array(81).fill(0),        // a Gyors ceruza által beírt (még nem kézzel átírt) jelöltek
      mistakes: 0,
      hintsLeft: diff.hints,
      score: 0,
      deductions: 0,
      deductPts: 0,
      elapsed: 0,
      selected: -1,
      pencil: false,
      fast: false,
      fastNum: 0,
      undo: [],
      status: 'playing',
    };
  }

  // régebbi mentésekből hiányzó mezők pótlása
  function upgrade(g) {
    if (!g) return g;
    if (!Array.isArray(g.tried)) g.tried = new Array(81).fill(0);
    if (!Array.isArray(g.autoMask)) g.autoMask = new Array(81).fill(0);
    if (g.deductions == null) { g.deductions = 0; g.deductPts = 0; }
    if (g.mp && !Array.isArray(g.mp.ev)) g.mp.ev = [];
    return g;
  }

  function newGame(diffKey) {
    const { puzzle, solution } = E.generate(diffKey);
    game = freshGame(diffKey, puzzle, solution);
    const s = diffStats(diffKey);
    s.played++;
    saveStats();
    saveGame();
    openGame();
  }

  function saveGame() {
    if (!game) return;
    if (game.mp) {
      store.set(KEY_MP, Object.assign({}, game, { undo: game.undo.slice(-60) }));
      return;
    }
    if (game.status === 'playing') {
      store.set(KEY_GAME, Object.assign({}, game, { undo: game.undo.slice(-60) }));
    } else {
      store.del(KEY_GAME);
    }
  }

  function loadGame() {
    const g = store.get(KEY_GAME, null);
    if (!g || g.status !== 'playing' || !Array.isArray(g.values) || g.values.length !== 81) return null;
    return upgrade(g);
  }

  function diffStats(key) {
    if (!stats.byDiff[key]) stats.byDiff[key] = { played: 0, won: 0, best: 0, totalTime: 0, streak: 0, bestStreak: 0 };
    return stats.byDiff[key];
  }
  function saveStats() { store.set(KEY_STATS, stats); }

  /* ---------- Képernyők ---------- */

  function showScreen(id) {
    document.querySelectorAll('.screen').forEach((s) => s.classList.toggle('active', s.id === id));
  }

  function openHome() {
    stopTimer();
    mpStopPolling();
    saveGame();
    showScreen('home');
    renderHome();
  }

  function renderHome() {
    $('totalScore').textContent = fmtNum(stats.total);
    const saved = game && !game.mp && game.status === 'playing' ? game : loadGame();
    $('btnContinue').classList.toggle('hidden', !saved);
    if (saved) $('continueInfo').textContent = diffName(saved.diff) + ' · ' + fmtTime(saved.elapsed);
    const m = loadMp();
    $('btnMpContinue').classList.toggle('hidden', !m || m.mp.ended);
    if (m) $('mpContinueInfo').textContent = t('home.room', { id: m.mp.id, diff: diffName(m.diff) });
    $('btnInstall').classList.toggle('hidden', isStandalone());
  }

  function openGame() {
    showScreen('game');
    paused = false;
    $('pauseOverlay').classList.add('hidden');
    $('board').style.visibility = '';
    $('fx').innerHTML = '';
    $('fxLabels').innerHTML = '';
    const isMp = !!game.mp;
    $('game').classList.toggle('mp', isMp);
    $('mpBar').classList.toggle('hidden', !isMp);
    $('mpFeedBox').classList.toggle('hidden', !isMp);
    $('btnShare').classList.toggle('hidden', !isMp);
    $('btnPause').classList.toggle('hidden', isMp);
    $('topTitle').textContent = isMp ? t('game.duel') : '';
    if (!isMp) {
      mpStopPolling();
      $('game').classList.remove('split');
      $('oppSide').classList.add('hidden');
      $('mpOverlay').classList.add('hidden');
    }
    render();
    startTimer();
    if (isMp) mpStartPolling();
  }

  /* ---------- Időzítő ---------- */

  function fmtTime(sec) {
    sec = Math.max(0, Math.floor(sec));
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    const mm = String(m).padStart(2, '0'), ss = String(s).padStart(2, '0');
    return h ? h + ':' + mm + ':' + ss : mm + ':' + ss;
  }

  function startTimer() {
    stopTimer();
    if (game && game.mp) { timerId = setInterval(mpClock, 250); mpClock(); return; }
    timerId = setInterval(() => {
      if (!game || game.status !== 'playing' || paused || document.hidden) return;
      game.elapsed++;
      $('timer').textContent = fmtTime(game.elapsed);
      if (game.elapsed % 5 === 0) saveGame();
    }, 1000);
  }
  function stopTimer() { if (timerId) clearInterval(timerId); timerId = null; }

  function setPaused(p) {
    if (!game || game.mp || game.status !== 'playing') return;
    paused = p;
    $('pauseOverlay').classList.toggle('hidden', !p);
    $('board').style.visibility = p ? 'hidden' : '';
    saveGame();
  }

  /* ---------- Tábla felépítése ---------- */

  const cells = [];
  function buildBoard() {
    const board = $('board');
    for (let i = 0; i < 81; i++) {
      const c = document.createElement('div');
      c.className = 'cell r' + ROW[i] + ' c' + COL[i];
      c.dataset.i = i;
      const v = document.createElement('span');
      v.className = 'v';
      const notes = document.createElement('div');
      notes.className = 'notes';
      for (let d = 1; d <= 9; d++) notes.appendChild(document.createElement('span'));
      c.appendChild(v);
      c.appendChild(notes);
      board.appendChild(c);
      cells.push(c);
    }
    board.addEventListener('pointerdown', (e) => {
      const c = e.target.closest('.cell');
      if (!c) return;
      e.preventDefault();
      onCellTap(+c.dataset.i);
    });

    const pad = $('numpad');
    for (let d = 1; d <= 9; d++) {
      const b = document.createElement('button');
      b.className = 'num';
      b.dataset.d = d;
      b.innerHTML = '<b>' + d + '</b><small></small>';
      b.addEventListener('click', () => onNumber(d));
      pad.appendChild(b);
    }
  }

  /* ---------- Megjelenítés ---------- */

  function countCorrect(d) {
    let n = 0;
    for (let i = 0; i < 81; i++) if (game.values[i] === d && game.solution[i] === d) n++;
    return n;
  }

  function isWrong(i) {
    return game.values[i] && game.values[i] !== game.solution[i];
  }

  function render() {
    if (!game) return;
    // gyors módban (kiválasztott számmal) nincs kijelölt mező, csak az azonos számok világítanak
    const fastActive = game.fast && game.fastNum;
    const sel = fastActive ? -1 : game.selected;
    const selVal = sel >= 0 ? game.values[sel] : 0;
    const focusDigit = fastActive ? game.fastNum : selVal;

    for (let i = 0; i < 81; i++) {
      const c = cells[i];
      const v = game.values[i];
      const cls = ['cell', 'r' + ROW[i], 'c' + COL[i]];
      if (i === sel) cls.push('sel');
      else if (settings.highlightSame && focusDigit && v === focusDigit) cls.push('same');
      else if (settings.highlightPeers && sel >= 0 && (ROW[i] === ROW[sel] || COL[i] === COL[sel] || BOX[i] === BOX[sel])) cls.push('peer');
      if (v && game.locked[i] !== 1) cls.push('user');
      const wrong = v && isWrong(i) && settings.showErrors;
      if (wrong) cls.push('wrong');
      if (wrong && sel >= 0 && i !== sel && v === selVal) cls.push('conflict');
      // ütköző mezők (azonos szám a sorban/oszlopban/blokkban) a hibás beíráshoz
      if (!wrong && settings.showErrors && v && sel >= 0 && isWrong(sel) && v === selVal && PEERS[sel].includes(i)) cls.push('conflict');
      // a megőrzött animációs osztályok
      ['flash', 'hinted', 'shake', 'deduced'].forEach((k) => { if (c.classList.contains(k)) cls.push(k); });
      c.className = cls.join(' ');

      const vEl = c.firstChild;
      vEl.textContent = v ? v : '';
      const notesEl = c.lastChild;
      const m = v ? 0 : game.notes[i];
      for (let d = 1; d <= 9; d++) {
        const s = notesEl.children[d - 1];
        const on = !!(m & (1 << d));
        s.textContent = on ? d : '';
        s.className = on && settings.highlightSame && focusDigit === d ? 'hl' : '';
      }
    }

    // fejléc
    $('score').textContent = fmtNum(game.score);
    $('diffName').textContent = diffName(game.diff);
    const mEl = $('mistakes');
    mEl.textContent = settings.mistakeLimit || game.mp
      ? t('game.mistakes', { n: game.mistakes, max: MAX_MISTAKES })
      : t('game.mistakesFree', { n: game.mistakes });
    mEl.classList.toggle('bad', game.mistakes > 0);
    $('timer').textContent = fmtTime(game.elapsed);
    $('timer').parentElement.style.visibility = settings.showTimer ? '' : 'hidden';

    // eszközök
    const pb = $('pencilBadge');
    pb.textContent = game.pencil ? 'ON' : 'OFF';
    pb.classList.toggle('on', game.pencil);
    const auto = autoActive();
    $('autoBadge').classList.toggle('hidden', !auto);
    $('btnAutoNotes').setAttribute('aria-pressed', auto ? 'true' : 'false');
    const hb = $('hintBadge');
    hb.textContent = game.hintsLeft;
    hb.classList.toggle('empty', game.hintsLeft <= 0);
    $('btnHint').classList.toggle('disabled', game.hintsLeft <= 0);
    $('btnUndo').classList.toggle('disabled', game.undo.length === 0);

    // gyors mód
    $('btnFast').setAttribute('aria-checked', game.fast ? 'true' : 'false');
    $('fastLabel').textContent = game.fast ? (game.fastNum ? t('fast.tap', { d: game.fastNum }) : t('fast.pick')) : '';

    // számbillentyűk
    const pad = $('numpad');
    pad.classList.toggle('pencil', game.pencil);
    for (const b of pad.children) {
      const d = +b.dataset.d;
      const left = 9 - countCorrect(d);
      b.lastChild.textContent = left;
      b.classList.toggle('done', left <= 0);
      b.classList.toggle('show', left <= 0 && !settings.hideDone);
      b.classList.toggle('active', game.fast && game.fastNum === d);
    }
    if (game.mp) renderMp();
  }

  function animate(i, cls, ms) {
    const c = cells[i];
    c.classList.remove(cls);
    void c.offsetWidth;
    c.classList.add(cls);
    setTimeout(() => c.classList.remove(cls), ms);
  }

  function pop(i) {
    const v = cells[i].firstChild;
    v.classList.remove('pop');
    void v.offsetWidth;
    v.classList.add('pop');
  }

  let toastTimer = null;
  /* action: { label, fn } – egy gomb az üzenetben (pl. Visszavonás) */
  function toast(msg, action) {
    const el = $('toast');
    el.textContent = '';
    const text = document.createElement('span');
    text.textContent = msg;
    el.appendChild(text);
    el.classList.toggle('has-action', !!action);
    if (action) {
      const b = document.createElement('button');
      b.className = 'toast-action';
      b.textContent = action.label;
      b.addEventListener('click', () => {
        clearTimeout(toastTimer);
        el.classList.remove('show');
        action.fn();
      });
      el.appendChild(b);
    }
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), action ? 4000 : 1800);
  }

  function haptic(ms) {
    if (navigator.vibrate) { try { navigator.vibrate(ms); } catch (e) { /* */ } }
  }

  /* ---------- Bevitel ---------- */

  function canPlay() { return game && game.status === 'playing' && !paused; }

  function snapshot() {
    game.undo.push({ values: game.values.slice(), notes: game.notes.slice(), locked: game.locked.slice(), autoMask: game.autoMask.slice() });
    if (game.undo.length > 200) game.undo.shift();
  }

  function restoreSnapshot(s) {
    game.values = s.values;
    game.notes = s.notes;
    game.locked = s.locked;
    // régi (autoMask nélküli) pillanatképnél a jelöltek kézzel írtnak számítanak
    game.autoMask = Array.isArray(s.autoMask) ? s.autoMask : new Array(81).fill(0);
  }

  function onCellTap(i) {
    if (!canPlay()) return;
    if (game.fast && game.fastNum) {
      input(i, game.fastNum);
      return;
    }
    game.selected = i;
    render();
  }

  function onNumber(d) {
    if (!canPlay()) return;
    if (game.fast) {
      game.fastNum = game.fastNum === d ? 0 : d;
      game.selected = -1;
      render();
      return;
    }
    if (game.selected < 0) { toast(t('toast.selectCell')); return; }
    input(game.selected, d);
  }

  function input(i, d) {
    if (game.locked[i]) { render(); return; }
    if (game.pencil) {
      if (game.values[i]) { render(); return; }
      // csak a lehetséges jelölteket engedi, ha a szám már szerepel a sorban/oszlopban/blokkban
      const bit = 1 << d;
      if (!(game.notes[i] & bit) && !(E.candidates(correctValues(), i) & bit)) {
        toast(t('toast.inUnit', { d }));
        animate(i, 'shake', 500);
        return;
      }
      snapshot();
      game.notes[i] ^= bit;
      game.autoMask[i] &= ~bit;
      afterChange();
      return;
    }
    if (game.values[i] === d) {
      // ugyanaz a hibás szám újra -> törlés
      snapshot();
      game.values[i] = 0;
      afterChange();
      return;
    }
    // a következtetés nehézségét a beírás előtti állapotból mérjük
    const tier = d === game.solution[i] && !game.tried[i] ? E.deductionTier(correctValues(), i) : 0;
    snapshot();
    game.values[i] = d;
    pop(i);
    if (d !== game.solution[i]) {
      game.mistakes++;
      game.tried[i] = 1;
      haptic([30, 40, 30]);
      animate(i, 'shake', 500);
      if ((settings.mistakeLimit || game.mp) && game.mistakes >= MAX_MISTAKES) {
        afterChange();
        lose();
        return;
      }
    } else {
      const pts = DIFFICULTIES[game.diff].points;
      game.locked[i] = 2;
      game.notes[i] = 0;
      game.autoMask[i] = 0;
      if (settings.autoRemoveNotes) for (const p of PEERS[i]) game.notes[p] &= ~(1 << d);
      game.score += pts;
      haptic(10);
      if (tier > 0) {
        const bonus = pts * DEDUCE_MULT[tier];
        game.score += bonus;
        game.deductions++;
        game.deductPts += bonus;
        celebrate(i, t('deduce.' + tier) + ' +' + bonus, tier >= 2);
        animate(i, 'deduced', 1200);
        haptic([15, 30, 15]);
        geckoPeek(i);
        if (game.mp) mpEvent({ k: 'd', l: tier, p: bonus });
      } else if (Math.random() < 0.3) {
        geckoPeek(i);
      }
      checkUnits(i, true);
    }
    afterChange();
    checkWin();
  }

  function correctValues() {
    return game.values.map((v, i) => (v === game.solution[i] ? v : 0));
  }

  function afterChange() {
    render();
    saveGame();
    if (game.mp) mpSchedulePush();
  }

  function unitCells(kind, k) {
    const out = [];
    for (let j = 0; j < 9; j++) {
      if (kind === 'row') out.push(k * 9 + j);
      else if (kind === 'col') out.push(j * 9 + k);
      else out.push((Math.floor(k / 3) * 3 + Math.floor(j / 3)) * 9 + (k % 3) * 3 + (j % 3));
    }
    return out;
  }

  /* Kitöltött sor / oszlop / blokk felvillantása és bónusz pont. */
  function checkUnits(i, mine) {
    const groups = [['row', ROW[i]], ['col', COL[i]], ['box', BOX[i]]];
    let completed = 0;
    for (const [kind, k] of groups) {
      const g = unitCells(kind, k);
      if (g.every((j) => game.values[j] && game.values[j] === game.solution[j])) {
        completed++;
        g.forEach((j, n) => setTimeout(() => animate(j, 'flash', 650), n * 35));
        setTimeout(() => geckoRunBoard(k, kind), completed * 250);
        if (mine && game.mp) mpFeedAdd(t('feed.you' + kind[0].toUpperCase() + kind.slice(1)), 'me');
      }
    }
    if (completed) game.score += completed * DIFFICULTIES[game.diff].points * 2;
  }

  function erase() {
    if (!canPlay()) return;
    const i = game.selected;
    if (i < 0) return;
    if (game.locked[i]) { toast(t('toast.locked')); return; }
    if (!game.values[i] && !game.notes[i]) return;
    snapshot();
    if (game.values[i]) game.values[i] = 0; else { game.notes[i] = 0; game.autoMask[i] = 0; }
    afterChange();
  }

  function undo() {
    if (!canPlay()) return;
    const s = game.undo.pop();
    if (!s) { toast(t('toast.noUndo')); return; }
    restoreSnapshot(s);
    afterChange();
  }

  function hint() {
    if (!canPlay()) return;
    if (game.hintsLeft <= 0) { toast(t('toast.noHints')); return; }
    let i = game.fast && game.fastNum ? -1 : game.selected;
    if (i < 0 || game.locked[i]) {
      // a legkevesebb jelölttel rendelkező üres mező
      const cv = correctValues();
      let best = -1, bestN = 10;
      for (let j = 0; j < 81; j++) {
        if (game.locked[j]) continue;
        const n = E.popcount(E.candidates(cv, j));
        if (n < bestN) { bestN = n; best = j; }
      }
      i = best;
    }
    if (i < 0) return;
    snapshot();
    const d = game.solution[i];
    game.values[i] = d;
    game.locked[i] = 2;
    game.notes[i] = 0;
    game.autoMask[i] = 0;
    if (settings.autoRemoveNotes) for (const p of PEERS[i]) game.notes[p] &= ~(1 << d);
    game.hintsLeft--;
    if (!(game.fast && game.fastNum)) game.selected = i;
    pop(i);
    animate(i, 'hinted', 1200);
    checkUnits(i, true);
    if (game.mp) mpEvent({ k: 'h' });
    afterChange();
    checkWin();
  }

  /* Vannak-e még látható, a Gyors ceruza által beírt jelöltek. */
  function autoActive() {
    for (let i = 0; i < 81; i++) if (!game.values[i] && (game.notes[i] & game.autoMask[i])) return true;
    return false;
  }

  /* Gyors ceruza: minden üres mezőbe beírja a lehetséges jelölteket.
     Ha már be vannak írva, újabb koppintásra eltávolítja őket. */
  function autoNotes() {
    if (!canPlay()) return;
    if (autoActive()) { removeAutoNotes(); return; }
    snapshot();
    const cv = correctValues();
    for (let i = 0; i < 81; i++) {
      if (game.values[i]) continue;
      const c = E.candidates(cv, i);
      game.autoMask[i] = c & ~game.notes[i]; // csak az újonnan hozzáadottak az automatáéi
      game.notes[i] = c;
    }
    toast(t('toast.notes'), { label: t('toast.undo'), fn: removeAutoNotes });
    afterChange();
  }

  /* Csak az automatikusan beírt jelölteket veszi ki; a beírt számok és a kézi jegyzetek maradnak. */
  function removeAutoNotes() {
    if (!canPlay() || !autoActive()) return;
    snapshot();
    for (let i = 0; i < 81; i++) {
      game.notes[i] &= ~game.autoMask[i];
      game.autoMask[i] = 0;
    }
    toast(t('toast.notesRemoved'));
    afterChange();
  }

  function togglePencil() {
    if (!canPlay()) return;
    game.pencil = !game.pencil;
    afterChange();
  }

  function toggleFast() {
    if (!canPlay()) return;
    game.fast = !game.fast;
    game.fastNum = 0;
    game.selected = -1;
    if (game.fast) toast(t('toast.fast'));
    afterChange();
  }

  /* ---------- Vége ---------- */

  function checkWin() {
    for (let i = 0; i < 81; i++) if (game.values[i] !== game.solution[i]) return;
    for (let i = 0; i < 81; i++) setTimeout(() => animate(i, 'flash', 650), (ROW[i] + COL[i]) * 40);
    geckoScreenRun(5);
    if (game.mp) { mpSolved(); return; }
    game.status = 'won';
    stopTimer();
    const diff = DIFFICULTIES[game.diff];
    // időbónusz: gyorsabb megoldás több pont
    const timeBonus = Math.max(0, Math.round(diff.points * 30 - game.elapsed * diff.points / 20));
    const mistakeBonus = game.mistakes === 0 ? diff.points * 10 : 0;
    game.score += timeBonus + mistakeBonus;
    const s = diffStats(game.diff);
    s.won++;
    s.totalTime += game.elapsed;
    s.streak++;
    s.bestStreak = Math.max(s.bestStreak, s.streak);
    const isBest = !s.best || game.elapsed < s.best;
    if (isBest) s.best = game.elapsed;
    stats.total += game.score;
    saveStats();
    saveGame();
    render();
    setTimeout(() => showEnd(true, isBest, timeBonus, mistakeBonus), 900);
  }

  function lose() {
    if (game.mp) { mpOut(); return; }
    game.status = 'lost';
    stopTimer();
    const s = diffStats(game.diff);
    s.streak = 0;
    saveStats();
    showEnd(false);
  }

  function showEnd(won, isBest, timeBonus, mistakeBonus) {
    $('endEmoji').textContent = won ? (settings.gecko ? '🦎' : '🏆') : '😵';
    $('endTitle').textContent = won ? t('end.won') : t('end.lost');
    let html =
      '<div><small>' + t('end.diff') + '</small><b>' + diffName(game.diff) + '</b></div>' +
      '<div><small>' + t('end.time') + '</small><b>' + fmtTime(game.elapsed) + '</b></div>' +
      '<div><small>' + t('end.mistakes') + '</small><b>' + game.mistakes + '</b></div>' +
      '<div><small>' + t('end.score') + '</small><b>' + fmtNum(game.score) + '</b></div>';
    if (won && (timeBonus || mistakeBonus)) {
      html += '<div class="best"><small>' + t('end.bonus') + '</small><b>+' + (timeBonus + mistakeBonus) + (mistakeBonus ? ' ' + t('end.flawless') : '') + '</b></div>';
    }
    if (game.deductions) html += '<div class="best deduce"><b>' + t('end.deductions', { n: game.deductions, p: fmtNum(game.deductPts) }) + '</b></div>';
    if (won && isBest) html += '<div class="best"><b>' + t('end.best') + '</b></div>';
    $('endStats').innerHTML = html;

    const actions = $('endActions');
    actions.innerHTML = '';
    if (!won) {
      addAction(actions, t('end.second'), 'btn primary', () => {
        game.status = 'playing';
        game.mistakes = MAX_MISTAKES - 1;
        closeModal();
        // az utolsó hibás beírás visszavonása
        const last = game.undo.pop();
        if (last) restoreSnapshot(last);
        afterChange();
        startTimer();
      });
    }
    addAction(actions, t('end.new'), won ? 'btn primary' : 'btn', () => { closeModal(); openSheet('diffSheet'); });
    addAction(actions, t('end.menu'), 'btn ghost', () => { closeModal(); if (game.status !== 'playing') game = null; openHome(); });
    $('endModal').classList.remove('hidden');
  }

  function addAction(parent, label, cls, fn) {
    const b = document.createElement('button');
    b.className = cls;
    b.textContent = label;
    b.addEventListener('click', fn);
    parent.appendChild(b);
  }
  function closeModal() { $('endModal').classList.add('hidden'); }

  /* ---------- Lapok ---------- */

  function openSheet(id, mode) {
    if (id === 'diffSheet') buildDiffList(mode === 'mp');
    if (id === 'statsSheet') buildStats();
    if (id === 'settingsSheet') {
      document.querySelectorAll('[data-setting]').forEach((el) => { el.checked = !!settings[el.dataset.setting]; });
    }
    $(id).classList.remove('hidden');
  }
  function closeSheet(el) { el.classList.add('hidden'); }

  function buildDiffList(isMp) {
    $('diffTitle').textContent = isMp ? t('game.duel') : t('diff.title');
    $('mpNameField').classList.toggle('hidden', !isMp);
    if (isMp) $('mpName').value = store.get(KEY_NAME, '');
    const list = $('diffList');
    list.innerHTML = '';
    Object.values(DIFFICULTIES).forEach((d, n) => {
      const b = document.createElement('button');
      b.className = 'diff-item';
      b.innerHTML = '<span>' + diffName(d.key) + '<br><small>' + t('diff.info', { clues: d.clues, hints: d.hints }) + '</small></span>' +
        '<span class="stars">' + '★'.repeat(n + 1) + '</span>';
      b.addEventListener('click', () => {
        closeSheet($('diffSheet'));
        b.disabled = true;
        // a generálás egy pillanatig tarthat – előbb rajzolódjon ki a lap bezárása
        if (isMp) {
          const name = $('mpName').value.trim();
          store.set(KEY_NAME, name);
          requestAnimationFrame(() => setTimeout(() => mpCreate(d.key, name), 10));
          return;
        }
        requestAnimationFrame(() => setTimeout(() => newGame(d.key), 10));
      });
      list.appendChild(b);
    });
  }

  function buildStats() {
    let rows = '';
    for (const d of Object.values(DIFFICULTIES)) {
      const s = diffStats(d.key);
      const rate = s.played ? Math.round((s.won / s.played) * 100) + '%' : '–';
      const avg = s.won ? fmtTime(s.totalTime / s.won) : '–';
      rows += '<tr><td>' + diffName(d.key) + '</td><td>' + s.won + '/' + s.played + '</td><td>' + rate + '</td><td>' +
        (s.best ? fmtTime(s.best) : '–') + '</td><td>' + avg + '</td><td>' + s.bestStreak + '</td></tr>';
    }
    $('statsBody').innerHTML =
      '<p class="stats-total">' + t('stats.total') + ' <b>' + fmtNum(stats.total) + '</b></p>' +
      '<div class="table-wrap"><table class="stats-table"><tr><th></th><th>' + t('stats.won') + '</th><th>' + t('stats.rate') + '</th><th>' +
      t('stats.best') + '</th><th>' + t('stats.avg') + '</th><th>' + t('stats.streak') + '</th></tr>' + rows + '</table></div>';
  }

  /* ---------- Események ---------- */

  function bind() {
    $('btnNew').addEventListener('click', () => openSheet('diffSheet'));
    $('btnContinue').addEventListener('click', () => {
      if (!game || game.mp || game.status !== 'playing') game = loadGame();
      if (game) openGame();
    });
    $('btnStats').addEventListener('click', () => openSheet('statsSheet'));
    let resetArmed = null;
    $('btnResetStats').addEventListener('click', (e) => {
      const b = e.currentTarget;
      if (!resetArmed) {
        b.textContent = t('stats.confirm');
        resetArmed = setTimeout(() => { resetArmed = null; b.textContent = t('stats.reset'); }, 3000);
        return;
      }
      clearTimeout(resetArmed);
      resetArmed = null;
      b.textContent = t('stats.reset');
      stats = { total: 0, byDiff: {} };
      saveStats();
      buildStats();
      $('totalScore').textContent = '0';
    });

    $('btnBack').addEventListener('click', openHome);
    $('btnRestart').addEventListener('click', () => openSheet('diffSheet'));
    $('btnSettings').addEventListener('click', () => { setPaused(true); openSheet('settingsSheet'); });
    $('btnPause').addEventListener('click', () => setPaused(!paused));
    $('btnResume').addEventListener('click', () => setPaused(false));

    $('btnUndo').addEventListener('click', undo);
    $('btnErase').addEventListener('click', erase);
    $('btnAutoNotes').addEventListener('click', autoNotes);
    $('btnPencil').addEventListener('click', togglePencil);
    $('btnHint').addEventListener('click', hint);
    $('btnFast').addEventListener('click', toggleFast);

    document.querySelectorAll('[data-lang]').forEach((el) => el.addEventListener('click', () => setLang(el.dataset.lang)));
    document.querySelectorAll('[data-gecko-toggle]').forEach((el) => el.addEventListener('click', toggleGecko));

    document.querySelectorAll('.sheet-backdrop').forEach((bd) => {
      bd.addEventListener('click', (e) => {
        if (e.target === bd || e.target.hasAttribute('data-close')) {
          closeSheet(bd);
          if (bd.id === 'settingsSheet' && game && game.status === 'playing') { setPaused(false); render(); }
        }
      });
    });

    document.querySelectorAll('[data-setting]').forEach((el) => {
      el.addEventListener('change', () => {
        if (el.dataset.setting === 'gecko') { if (el.checked !== !!settings.gecko) toggleGecko(); return; }
        settings[el.dataset.setting] = el.checked;
        saveSettings();
        if (game) render();
      });
    });

    // Billentyűzet (asztali géphez / külső billentyűzethez)
    document.addEventListener('keydown', (e) => {
      if (!game || !$('game').classList.contains('active')) return;
      if (e.target && /INPUT|TEXTAREA/.test(e.target.tagName)) return;
      const k = e.key;
      if (k >= '1' && k <= '9') { onNumber(+k); e.preventDefault(); return; }
      if (k === 'Backspace' || k === 'Delete' || k === '0') { erase(); return; }
      if (k === 'n' || k === 'N' || k === 'p' || k === 'P') { togglePencil(); return; }
      if ((k === 'a' || k === 'A') && !e.metaKey && !e.ctrlKey) { autoNotes(); return; }
      if (k === 'z' && (e.metaKey || e.ctrlKey)) { undo(); e.preventDefault(); return; }
      if (k === ' ') { setPaused(!paused); e.preventDefault(); return; }
      const moves = { ArrowUp: 1, ArrowDown: 1, ArrowLeft: 1, ArrowRight: 1 };
      if (moves[k] && canPlay()) {
        if (game.fast && game.fastNum) game.fastNum = 0;
        let i = game.selected < 0 ? 40 : game.selected;
        const r = ROW[i], c = COL[i];
        if (k === 'ArrowUp') i = ((r + 8) % 9) * 9 + c;
        if (k === 'ArrowDown') i = ((r + 1) % 9) * 9 + c;
        if (k === 'ArrowLeft') i = r * 9 + (c + 8) % 9;
        if (k === 'ArrowRight') i = r * 9 + (c + 1) % 9;
        game.selected = i;
        render();
        e.preventDefault();
      }
    });

    // Háttérbe kerüléskor automatikus szünet és mentés
    document.addEventListener('visibilitychange', () => {
      if (document.hidden && game && !game.mp && game.status === 'playing' && $('game').classList.contains('active')) {
        setPaused(true);
      }
    });
    window.addEventListener('pagehide', saveGame);

    // Kétszemélyes játék
    $('btnMp').addEventListener('click', () => openSheet('diffSheet', 'mp'));
    $('btnMpContinue').addEventListener('click', () => {
      const m = loadMp();
      if (!m) return;
      game = m;
      mpReset(!!m.mp.ended);
      openGame();
    });
    $('btnInstall').addEventListener('click', openInstall);
    $('btnShare').addEventListener('click', mpInvite);
    $('btnInvite').addEventListener('click', mpInvite);
    $('btnSplit').addEventListener('click', () => mpSetSplit(true));
    $('oppSide').addEventListener('click', () => mpSetSplit(false));
    $('btnJoin').addEventListener('click', () => {
      const name = $('joinName').value.trim();
      store.set(KEY_NAME, name);
      closeSheet($('joinSheet'));
      mpJoin(mp.joinId, name);
    });
  }

  /* ---------- Főképernyőre tétel (iOS) ---------- */

  const ICO = {
    share: '<span class="ico"><svg viewBox="0 0 24 24"><path d="M12 15V3M7 8l5-5 5 5"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg></span>',
    more: '<span class="ico"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></svg></span>',
    plus: '<span class="ico"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M12 8v8M8 12h8"/></svg></span>',
  };

  function isStandalone() {
    return window.navigator.standalone === true || (window.matchMedia && matchMedia('(display-mode: standalone)').matches);
  }

  function browserKind() {
    const ua = navigator.userAgent || '';
    const ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    // alkalmazáson belüli böngészők: itt nincs „Főképernyőhöz adás”
    const inApp = /FBAN|FBAV|FB_IAB|FBIOS|Messenger|Instagram|Line\/|GSA\/|LinkedInApp|Twitter|TikTok|musical_ly|Snapchat|Pinterest|WhatsApp|Viber|Gmail|GoogleApp/i.test(ua);
    let kind = 'safari';
    if (inApp) kind = 'inapp';
    else if (/CriOS/.test(ua)) kind = 'chrome';
    else if (/FxiOS|EdgiOS|OPiOS|DuckDuckGo/.test(ua)) kind = 'other';
    return { ios, kind };
  }

  function openInstall() {
    const { ios, kind } = browserKind();
    const url = location.origin + location.pathname;
    const step = (key) => '<li><span>' + t(key, ICO) + '</span></li>';
    const copyRow = '<div class="copy-row"><code>' + escapeHtml(url) + '</code><button class="btn primary" id="btnCopyUrl">' + t('install.copy') + '</button></div>';
    let html = '';
    if (ios && kind === 'inapp') {
      html = '<div class="install-warn">' + t('install.inappWarn') + '</div><ol class="steps">' +
        step('install.inapp1') + step('install.inapp2') + step('install.inapp3') + '</ol>' + copyRow;
    } else if (ios && kind === 'chrome') {
      html = '<ol class="steps">' + step('install.chrome1') + step('install.chrome2') + step('install.chrome3') + '</ol>' +
        '<p class="install-note">' + t('install.chromeNote') + '</p>';
    } else if (ios && kind === 'other') {
      html = '<div class="install-warn">' + t('install.otherWarn') + '</div>' + copyRow;
    } else if (ios) {
      html = '<ol class="steps">' + step('install.safari1') + step('install.safari2') + step('install.safari3') + '</ol>' +
        '<p class="install-note">' + t('install.safariNote') + '</p>';
    } else {
      html = '<p class="install-note big">' + t('install.desktop') + '</p>' + copyRow;
    }
    $('installBody').innerHTML = html;
    const copy = $('btnCopyUrl');
    if (copy) {
      copy.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(url); toast(t('install.copied')); }
        catch (e) { toast(t('install.copyFail')); }
      });
    }
    openSheet('installSheet');
  }

  /* ---------- Kétszemélyes játék ---------- */

  const API = location.origin + '/wp-json/crazy-sudoku/v1';
  const WIDE = window.matchMedia('(min-width: 900px) and (min-height: 600px)');
  WIDE.addEventListener && WIDE.addEventListener('change', () => { if (game && game.mp) render(); });
  const POLL_MS = 1500;
  const mp = {
    data: null, offset: 0, poll: null, pushTimer: null, pushing: false, dirty: false, lastPush: 0,
    ended: false, joinId: '', oppOutShown: false,
    // az ellenfél korábbi állapota a változások (villanások, események) felismeréséhez
    prev: null, fx: {}, seenEv: new Set(), fills: [], flags: {}, ticker: '', tickerUntil: 0,
  };

  function mpReset(ended) {
    mp.ended = !!ended;
    mp.oppOutShown = false;
    mp.prev = null;
    mp.fx = {};
    mp.seenEv = new Set();
    mp.fills = [];
    mp.flags = {};
    mp.ticker = '';
    $('mpFeed').innerHTML = '';
    $('mpFeedEmpty').classList.remove('hidden');
  }

  function loadMp() {
    const g = store.get(KEY_MP, null);
    if (!g || !g.mp || !Array.isArray(g.values) || g.values.length !== 81) return null;
    return upgrade(g);
  }

  function serverNow() { return Date.now() + mp.offset; }

  async function api(method, path, body) {
    const t0 = Date.now();
    let res;
    try {
      res = await fetch(API + path, {
        method,
        headers: body ? { 'Content-Type': 'application/json' } : {},
        body: body ? JSON.stringify(body) : undefined,
        cache: 'no-store',
        credentials: 'omit',
      });
    } catch (e) {
      const err = new Error(t('err.net'));
      err.code = 'net';
      throw err;
    }
    let data = null;
    try { data = await res.json(); } catch (e) { /* */ }
    if (!res.ok) {
      const code = data && data.code;
      const key = 'err.' + code;
      const err = new Error(code && I18N.hu[key] ? t(key) : (data && data.message) || t('err.server', { s: res.status }));
      err.code = code || 'server';
      throw err;
    }
    if (data && data.now) mp.offset = data.now - (t0 + Date.now()) / 2;
    return data;
  }

  function mpGameFromRoom(d, sess) {
    const puzzle = Array.from(d.room.puzzle, Number);
    const solution = Array.from(d.room.solution, Number);
    const g = freshGame(DIFFICULTIES[d.room.diff] ? d.room.diff : 'medium', puzzle, solution);
    g.status = 'waiting';
    g.mp = { id: d.room.id, token: sess.token, seat: sess.seat, round: d.room.round, start: d.room.start, ended: false, ev: [] };
    return g;
  }

  function mpBegin(d, sess) {
    game = mpGameFromRoom(d, sess);
    mpReset(false);
    mp.data = d;
    closeModal();
    saveGame();
    openGame();
    mpPush();
  }

  async function mpCreate(diffKey, name) {
    toast(t('mp.creating'));
    const { puzzle, solution } = E.generate(diffKey);
    try {
      const d = await api('POST', '/room', { diff: diffKey, name, puzzle: puzzle.join(''), solution: solution.join('') });
      mpBegin(d, { token: d.token, seat: d.seat });
    } catch (e) {
      toast(e.message);
    }
  }

  async function mpJoin(id, name) {
    const local = loadMp();
    const token = local && local.mp.id === id ? local.mp.token : '';
    try {
      const d = await api('POST', '/room/' + id + '/join', { name, token });
      if (token && local.mp.round === d.room.round) {
        game = local;
        mpReset(!!local.mp.ended);
        mp.data = d;
        openGame();
      } else {
        mpBegin(d, { token: d.token || token, seat: d.seat });
      }
    } catch (e) {
      toast(e.message);
      openHome();
    }
  }

  /* Meghívó link megnyitása: ?szoba=abc123 */
  async function mpHandleLink() {
    const params = new URLSearchParams(location.search);
    const id = (params.get('szoba') || params.get('room') || '').toLowerCase();
    if (!/^[a-z0-9]{6}$/.test(id)) return false;
    history.replaceState(null, '', location.pathname);
    const local = loadMp();
    if (local && local.mp.id === id) {
      game = local;
      mpReset(!!local.mp.ended);
      openGame();
      return true;
    }
    mp.joinId = id;
    $('joinName').value = store.get(KEY_NAME, '');
    $('joinInfo').textContent = t('join.info');
    $('btnJoin').disabled = false;
    openSheet('joinSheet');
    try {
      const d = await api('GET', '/room/' + id);
      const host = d.players[1];
      if (d.players[2]) {
        $('joinInfo').textContent = t('join.full');
        $('btnJoin').disabled = true;
      } else {
        $('joinInfo').textContent = t('join.invited', {
          name: host ? host.name : t('join.someone'),
          diff: DIFFICULTIES[d.room.diff] ? diffName(d.room.diff).toLowerCase() : '',
        });
      }
    } catch (e) {
      $('joinInfo').textContent = e.message;
      $('btnJoin').disabled = true;
    }
    return true;
  }

  function mpLink() {
    return location.origin + location.pathname + '?szoba=' + game.mp.id;
  }

  async function mpInvite() {
    if (!game || !game.mp) return;
    const url = mpLink();
    $('mpLink').textContent = url;
    $('mpLink').classList.remove('hidden');
    if (navigator.share) {
      try {
        await navigator.share({ title: t('mp.shareTitle'), text: t('mp.shareText'), url });
        return;
      } catch (e) {
        if (e && e.name === 'AbortError') return;
      }
    }
    try {
      await navigator.clipboard.writeText(url);
      toast(t('mp.linkCopied'));
    } catch (e) {
      toast(t('mp.linkCopyFail'));
    }
  }

  function mpStartPolling() {
    mpStopPolling();
    mpTick();
    mp.poll = setInterval(mpTick, POLL_MS);
  }
  function mpStopPolling() {
    if (mp.poll) clearInterval(mp.poll);
    mp.poll = null;
  }

  async function mpTick() {
    if (!game || !game.mp || document.hidden) return;
    // életjel / elmaradt mentés pótlása
    if (mp.dirty || Date.now() - mp.lastPush > 6000) { mpPush(); return; }
    try {
      mpApply(await api('GET', '/room/' + game.mp.id));
    } catch (e) {
      if (e.code === 'cp_sdk_room') { toast(e.message); mpStopPolling(); }
    }
  }

  function mpSchedulePush() {
    clearTimeout(mp.pushTimer);
    mp.pushTimer = setTimeout(mpPush, 200);
  }

  /* Saját esemény (következtetés, tipp) – az ellenfél élő naplójában jelenik meg. */
  function mpEvent(ev) {
    ev.t = Date.now();
    game.mp.ev.push(ev);
    if (game.mp.ev.length > 8) game.mp.ev.shift();
    if (ev.k === 'd') mpFeedAdd(t('feed.youDeduce', { label: t('deduce.' + ev.l), p: ev.p }), 'me good');
  }

  async function mpPush() {
    if (!game || !game.mp) return;
    if (mp.pushing) { mp.dirty = true; return; }
    mp.pushing = true;
    mp.dirty = false;
    mp.lastPush = Date.now();
    const g = game;
    try {
      const d = await api('POST', '/room/' + g.mp.id + '/state', {
        token: g.mp.token, round: g.mp.round, values: g.values.join(''),
        mistakes: g.mistakes, score: g.score, out: g.status === 'out', ev: g.mp.ev,
      });
      if (game === g) mpApply(d);
    } catch (e) {
      mp.dirty = true;
    } finally {
      mp.pushing = false;
    }
  }

  function mpApply(d) {
    if (!game || !game.mp || !d || !d.room || d.room.id !== game.mp.id) return;
    if (d.room.round !== game.mp.round) {
      // visszavágó indult
      if (d.room.round > game.mp.round) mpBegin(d, { token: game.mp.token, seat: game.mp.seat });
      return;
    }
    mp.data = d;
    game.mp.start = d.room.start;
    mpDiffOpponent();
    mpCheckEnd();
    render();
  }

  /* Az ellenfél állásának változásai: villanó csempék, kitöltött egységek, események. */
  function mpDiffOpponent() {
    const { opp } = mpSeats();
    if (!opp) return;
    const name = opp.name;
    const vals = opp.values || '';
    const now = Date.now();
    const prev = mp.prev;
    const evs = Array.isArray(opp.ev) ? opp.ev : [];
    mp.prev = { values: vals, mistakes: opp.mistakes };
    if (!prev) {
      // első adat: alapállapot, nincs visszajátszás
      evs.forEach((e) => mp.seenEv.add(e.k + e.t));
      return;
    }
    const newOk = [];
    for (let i = 0; i < 81; i++) {
      if (game.puzzle[i] || vals[i] === prev.values[i]) continue;
      const v = +vals[i];
      if (!v) continue;
      if (v === game.solution[i]) { newOk.push(i); mp.fx[i] = { cls: 'nf', until: now + 1300 }; }
      else mp.fx[i] = { cls: 'nx', until: now + 1300 };
    }
    if (opp.mistakes > prev.mistakes) mpFeedAdd(t('feed.mistake', { name, m: opp.mistakes }), 'opp bad', true);
    // kitöltött sor / oszlop / blokk
    const done = (kind, k) => unitCells(kind, k).every((j) => +vals[j] === game.solution[j]);
    const wasDone = (kind, k) => unitCells(kind, k).every((j) => +prev.values[j] === game.solution[j]);
    const seen = new Set();
    for (const i of newOk) {
      for (const [kind, k] of [['row', ROW[i]], ['col', COL[i]], ['box', BOX[i]]]) {
        const key = kind + k;
        if (seen.has(key) || !done(kind, k) || wasDone(kind, k)) continue;
        seen.add(key);
        unitCells(kind, k).forEach((j, n) => { mp.fx[j] = { cls: 'uf', until: now + 1400 + n * 30, delay: n * 30 }; });
        mpFeedAdd(t('feed.' + kind, { name }), 'opp', true);
        mpPressure();
      }
    }
    // lendület: 15 mp alatt legalább 5 helyes szám
    newOk.forEach(() => mp.fills.push(now));
    mp.fills = mp.fills.filter((x) => now - x < 15000);
    if (mp.fills.length >= 5 && (!mp.flags.fire || now - mp.flags.fire > 30000)) {
      mp.flags.fire = now;
      mpFeedAdd(t('feed.fire', { name }), 'opp hot', true);
      mpPressure();
    }
    const pct = mpProgress(vals);
    if (pct >= 0.5 && !mp.flags.half) { mp.flags.half = 1; mpFeedAdd(t('feed.half', { name }), 'opp', true); }
    if (pct >= 0.85 && !mp.flags.close && !opp.done) { mp.flags.close = 1; mpFeedAdd(t('feed.close', { name }), 'opp hot', true); mpPressure(); }
    // az ellenfél saját eseményei (következtetés, tipp)
    for (const e of evs) {
      const key = e.k + e.t;
      if (mp.seenEv.has(key)) continue;
      mp.seenEv.add(key);
      if (e.k === 'd') mpFeedAdd(t('feed.deduce', { name, label: t('deduce.' + e.l), p: e.p }), 'opp hot', true);
      if (e.k === 'h') mpFeedAdd(t('feed.hint', { name }), 'opp', true);
    }
  }

  /* Nyomás: a saját táblád széle felvillan narancssárgán. */
  function mpPressure() {
    const w = $('boardWrap');
    w.classList.remove('pressure');
    void w.offsetWidth;
    w.classList.add('pressure');
    setTimeout(() => w.classList.remove('pressure'), 1000);
    haptic(20);
  }

  function mpFeedAdd(text, cls, ticker) {
    const li = document.createElement('li');
    li.className = cls || '';
    const time = game && game.mp && game.mp.start ? fmtTime((serverNow() - game.mp.start) / 1000) : '';
    li.innerHTML = '<time>' + time + '</time><span></span>';
    li.lastChild.textContent = text;
    const feed = $('mpFeed');
    feed.insertBefore(li, feed.firstChild);
    while (feed.children.length > 40) feed.lastChild.remove();
    $('mpFeedEmpty').classList.add('hidden');
    if (ticker) {
      // a hírsávban a név már ott áll előtte, ezért levágjuk
      const name = (mpSeats().opp || {}).name || '';
      let short = text;
      if (name && short.startsWith(name + ': ')) short = short.slice(name.length + 2);
      else if (name && short.startsWith(name + ' ')) short = short.slice(name.length + 1);
      mp.ticker = short;
      mp.tickerUntil = Date.now() + 2800;
    }
  }

  function mpClock() {
    if (!game || !game.mp) return;
    const start = game.mp.start;
    if (game.status === 'waiting' && start && serverNow() >= start) {
      game.status = 'playing';
      mpFeedAdd(t('feed.start'), 'me');
      saveGame();
      render();
    }
    if (start && (game.status === 'playing' || game.status === 'waiting')) {
      game.elapsed = Math.max(0, Math.floor((serverNow() - start) / 1000));
    }
    $('timer').textContent = fmtTime(game.elapsed);
    mpOverlay();
    // lejárt villanások / hírsáv frissítése
    if (mp.tickerText !== (Date.now() < mp.tickerUntil ? mp.ticker : '')) renderMp();
  }

  function mpSeats() {
    const me = game.mp.seat, other = me === 1 ? 2 : 1;
    const P = (mp.data && mp.data.players) || {};
    return { me: P[me] || null, opp: P[other] || null };
  }

  function mpProgress(values) {
    let total = 0, ok = 0;
    for (let i = 0; i < 81; i++) {
      if (game.puzzle[i]) continue;
      total++;
      if (+values[i] === game.solution[i]) ok++;
    }
    return total ? ok / total : 0;
  }

  function mpOverlay() {
    const ov = $('mpOverlay');
    const { opp } = mpSeats();
    const start = game.mp.start;
    let show = false, count = '', msg = '';
    if (!opp) {
      show = true;
      msg = t('mp.waiting');
    } else if (game.status === 'waiting') {
      show = true;
      const left = start ? Math.ceil((start - serverNow()) / 1000) : 0;
      count = left > 0 ? String(left) : '';
      msg = t('mp.joined', { name: opp.name });
    }
    ov.classList.toggle('hidden', !show);
    if ($('mpCount').textContent !== count) $('mpCount').textContent = count;
    $('mpMsg').textContent = msg;
    $('btnInvite').classList.toggle('hidden', !!opp);
    if (opp) $('mpLink').classList.add('hidden');
  }

  function oppBoard(el, opp) {
    if (el.children.length !== 81) {
      el.innerHTML = '';
      for (let i = 0; i < 81; i++) el.appendChild(document.createElement('span'));
    }
    const vals = opp ? opp.values : '';
    const now = Date.now();
    for (let i = 0; i < 81; i++) {
      const s = el.children[i];
      const fx = mp.fx[i] && mp.fx[i].until > now ? mp.fx[i] : null;
      let cls = '';
      if (game.puzzle[i]) {
        if (s.textContent !== String(game.puzzle[i])) s.textContent = game.puzzle[i];
      } else {
        const v = vals ? +vals[i] : 0;
        if (s.textContent) s.textContent = '';
        cls = !v ? '' : v === game.solution[i] ? 'f' : 'x';
      }
      if (fx) cls += ' ' + fx.cls;
      cls = cls.trim();
      if (s.className !== cls) {
        s.className = cls;
        if (fx && fx.delay) s.style.animationDelay = fx.delay + 'ms'; else s.style.animationDelay = '';
      }
    }
  }

  function renderMp() {
    const { me, opp } = mpSeats();
    const myPct = Math.round(mpProgress(game.values) * 100);
    const myName = me ? me.name : t('mp.you');
    $('mpMeName').textContent = t('mp.youTag', { name: myName });
    $('mpMeInfo').textContent = t('mp.info', { pct: myPct, m: game.mistakes, s: fmtNum(game.score) });
    $('mpMeBar').style.width = myPct + '%';
    $('ownSideName').textContent = t('mp.youTag', { name: myName });
    $('ownSideInfo').textContent = $('mpMeInfo').textContent;
    const oppEl = document.querySelector('.mp-p.opp');
    let info = t('mp.notJoined');
    let oppPct = 0;
    if (opp) {
      oppPct = Math.round(mpProgress(opp.values) * 100);
      info = t('mp.info', { pct: oppPct, m: opp.mistakes, s: fmtNum(opp.score) });
      if (opp.done) info = t('mp.done', { t: fmtTime((opp.done - game.mp.start) / 1000) });
      else if (opp.out) info = t('mp.out');
    }
    const online = opp && opp.seen && serverNow() - opp.seen < 15000;
    oppEl.classList.toggle('online', !!online);
    oppEl.classList.toggle('offline', !!opp && !online);
    oppEl.classList.toggle('danger', !!opp && !opp.done && oppPct >= 85);
    // hírsáv: az ellenfél legutóbbi eseménye pár másodpercig az ő sorában
    const tick = Date.now() < mp.tickerUntil ? mp.ticker : '';
    mp.tickerText = tick;
    oppEl.classList.toggle('ticker', !!tick);
    $('mpOppName').textContent = opp ? opp.name : t('mp.opponent');
    $('mpOppInfo').textContent = tick || info;
    $('mpOppBar').style.width = oppPct + '%';
    $('oppSideName').textContent = opp ? opp.name : t('mp.opponent');
    $('oppSideInfo').textContent = info;
    // vezetés
    const lead = $('mpLead');
    if (opp && game.status !== 'waiting') {
      const diff = myPct - oppPct;
      lead.textContent = diff === 0 ? t('mp.tie') : diff > 0 ? t('mp.lead', { n: diff }) : t('mp.behind', { name: opp.name, n: -diff });
      lead.className = 'mp-lead ' + (diff > 0 ? 'ahead' : diff < 0 ? 'behind' : '');
    } else {
      lead.textContent = '';
      lead.className = 'mp-lead';
    }
    oppBoard($('oppBoardMini'), opp);
    oppBoard($('oppBoardBig'), opp);
    // asztali nézetben mindig egymás mellett a két tábla
    const split = !!settings.split || WIDE.matches;
    $('game').classList.toggle('split', split);
    $('oppSide').classList.toggle('hidden', !split);
    mpOverlay();
  }

  function mpSetSplit(on) {
    settings.split = on;
    saveSettings();
    render();
  }

  function mpSolved() {
    game.status = 'won';
    game.score += DIFFICULTIES[game.diff].points * 10;
    saveGame();
    render();
    mpPush(); // a szerver rögzíti, ki volt az első
  }

  function mpOut() {
    game.status = 'out';
    saveGame();
    render();
    mpPush();
    const { opp } = mpSeats();
    if (opp && !opp.done && !opp.out) {
      $('endEmoji').textContent = '😵';
      $('endTitle').textContent = t('mp.outTitle');
      $('endStats').innerHTML = '<div class="best"><b>' + escapeHtml(t('mp.stillPlaying', { name: opp.name })) + '</b></div>';
      const actions = $('endActions');
      actions.innerHTML = '';
      addAction(actions, t('mp.watch'), 'btn primary', closeModal);
      addAction(actions, t('end.menu'), 'btn ghost', () => { closeModal(); openHome(); });
      $('endModal').classList.remove('hidden');
    }
  }

  function mpCheckEnd() {
    if (mp.ended || !mp.data) return;
    const { me, opp } = mpSeats();
    if (!me || !opp) return;
    let result = '';
    if (me.done && (!opp.done || me.done <= opp.done)) result = 'win';
    else if (opp.done) result = 'lose';
    else if (me.out && opp.out) result = 'draw';
    else if (opp.out && !mp.oppOutShown && game.status === 'playing') {
      mp.oppOutShown = true;
      toast(t('mp.oppOut', { name: opp.name }));
    }
    if (!result) return;
    mp.ended = true;
    game.mp.ended = true;
    if (game.status === 'playing' || game.status === 'waiting') game.status = 'lost';
    if (result === 'win') { stats.total += game.score; saveStats(); }
    saveGame();
    setTimeout(() => mpShowEnd(result, me, opp), result === 'win' ? 900 : 200);
  }

  function mpShowEnd(result, me, opp) {
    const start = game.mp.start;
    const tm = (p) => (p.done ? fmtTime((p.done - start) / 1000) : p.out ? t('mp.outShort') : '–');
    $('endEmoji').textContent = result === 'win' ? (settings.gecko ? '🦎' : '🏆') : result === 'draw' ? '🤝' : '🥈';
    $('endTitle').textContent = result === 'win' ? t('mp.win') : result === 'draw' ? t('mp.draw') : t('mp.oppWon', { name: opp.name });
    $('endStats').innerHTML =
      '<div><small>' + t('mp.you') + '</small><b class="' + (result === 'win' ? 'win' : '') + '">' + tm(me) + '</b></div>' +
      '<div><small>' + escapeHtml(opp.name) + '</small><b class="opp-c">' + tm(opp) + '</b></div>' +
      '<div><small>' + t('mp.yourMistakes') + '</small><b>' + me.mistakes + '</b></div>' +
      '<div><small>' + t('mp.yourScore') + '</small><b>' + fmtNum(game.score) + '</b></div>' +
      (game.deductions ? '<div class="best deduce"><b>' + t('end.deductions', { n: game.deductions, p: fmtNum(game.deductPts) }) + '</b></div>' : '');
    const actions = $('endActions');
    actions.innerHTML = '';
    addAction(actions, t('mp.rematch'), 'btn primary', mpRematch);
    addAction(actions, t('end.menu'), 'btn ghost', () => { closeModal(); openHome(); });
    $('endModal').classList.remove('hidden');
  }

  async function mpRematch() {
    if (!game || !game.mp) return;
    closeModal();
    toast(t('mp.newPuzzle'));
    const { puzzle, solution } = E.generate(game.diff);
    try {
      const d = await api('POST', '/room/' + game.mp.id + '/rematch', {
        token: game.mp.token, round: game.mp.round, puzzle: puzzle.join(''), solution: solution.join(''),
      });
      mpApply(d);
    } catch (e) {
      toast(e.message);
    }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  /* ---------- Indítás ---------- */

  applyI18n();
  applyTheme();
  buildBoard();
  bind();
  game = loadGame();
  openHome();
  mpHandleLink();

  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    navigator.serviceWorker.register('sw.js').catch(() => { /* offline mód nem elérhető */ });
  }
})();
