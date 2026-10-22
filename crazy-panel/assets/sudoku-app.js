/* Klasszikus Szudoku – játéklogika és felület. */
(function () {
  'use strict';

  const E = window.SudokuEngine;
  const { ROW, COL, BOX, PEERS, DIFFICULTIES } = E;
  const $ = (id) => document.getElementById(id);

  const KEY_GAME = 'szudoku.game.v1';
  const KEY_SETTINGS = 'szudoku.settings.v1';
  const KEY_STATS = 'szudoku.stats.v1';
  const KEY_MP = 'szudoku.mp.v1';
  const KEY_NAME = 'szudoku.name.v1';
  const MAX_MISTAKES = 3;

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
  }, store.get(KEY_SETTINGS, {}));

  let stats = store.get(KEY_STATS, { total: 0, byDiff: {} });

  let game = null;       // aktuális játék állapota
  let timerId = null;
  let paused = false;

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
      mistakes: 0,
      hintsLeft: diff.hints,
      score: 0,
      elapsed: 0,
      selected: -1,
      pencil: false,
      fast: false,
      fastNum: 0,
      undo: [],
      status: 'playing',
      counted: false,
    };
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
      const copy = Object.assign({}, game, { undo: game.undo.slice(-60) });
      store.set(KEY_GAME, copy);
    } else {
      store.del(KEY_GAME);
    }
  }

  function loadGame() {
    const g = store.get(KEY_GAME, null);
    if (!g || g.status !== 'playing' || !Array.isArray(g.values) || g.values.length !== 81) return null;
    return g;
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
    $('totalScore').textContent = stats.total.toLocaleString('hu-HU');
    const saved = game && !game.mp && game.status === 'playing' ? game : loadGame();
    if (saved) {
      $('btnContinue').classList.remove('hidden');
      $('continueInfo').textContent = DIFFICULTIES[saved.diff].name + ' · ' + fmtTime(saved.elapsed);
    } else {
      $('btnContinue').classList.add('hidden');
    }
    const m = loadMp();
    $('btnMpContinue').classList.toggle('hidden', !m || m.mp.ended);
    if (m) $('mpContinueInfo').textContent = 'Szoba: ' + m.mp.id + ' · ' + DIFFICULTIES[m.diff].name;
  }

  function openGame() {
    showScreen('game');
    paused = false;
    $('pauseOverlay').classList.add('hidden');
    $('board').style.visibility = '';
    const isMp = !!game.mp;
    $('game').classList.toggle('mp', isMp);
    $('mpBar').classList.toggle('hidden', !isMp);
    $('btnShare').classList.toggle('hidden', !isMp);
    $('btnPause').classList.toggle('hidden', isMp);
    $('topTitle').textContent = isMp ? 'Kétszemélyes játék' : '';
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
    sec = Math.floor(sec);
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
    const sel = game.selected;
    const selVal = sel >= 0 ? game.values[sel] : 0;
    const focusDigit = game.fast && game.fastNum ? game.fastNum : selVal;

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
      ['flash', 'hinted', 'shake'].forEach((k) => { if (c.classList.contains(k)) cls.push(k); });
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
    $('score').textContent = game.score.toLocaleString('hu-HU');
    $('diffName').textContent = DIFFICULTIES[game.diff].name;
    const mEl = $('mistakes');
    mEl.textContent = settings.mistakeLimit || game.mp ? 'Hibák: ' + game.mistakes + '/' + MAX_MISTAKES : 'Hibák: ' + game.mistakes;
    mEl.classList.toggle('bad', game.mistakes > 0);
    $('timer').textContent = fmtTime(game.elapsed);
    $('timer').parentElement.style.visibility = settings.showTimer ? '' : 'hidden';

    // eszközök
    const pb = $('pencilBadge');
    pb.textContent = game.pencil ? 'ON' : 'OFF';
    pb.classList.toggle('on', game.pencil);
    const hb = $('hintBadge');
    hb.textContent = game.hintsLeft;
    hb.classList.toggle('empty', game.hintsLeft <= 0);
    $('btnHint').classList.toggle('disabled', game.hintsLeft <= 0);
    $('btnUndo').classList.toggle('disabled', game.undo.length === 0);

    // gyors mód
    const fb = $('btnFast');
    fb.setAttribute('aria-checked', game.fast ? 'true' : 'false');
    $('fastLabel').textContent = game.fast ? (game.fastNum ? 'Koppints a mezőkre: ' + game.fastNum : 'Válassz számot') : '';

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
  function toast(msg) {
    const t = $('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 1600);
  }

  function haptic(ms) {
    if (navigator.vibrate) { try { navigator.vibrate(ms); } catch (e) { /* */ } }
  }

  /* ---------- Bevitel ---------- */

  function canPlay() { return game && game.status === 'playing' && !paused; }

  function snapshot() {
    game.undo.push({ values: game.values.slice(), notes: game.notes.slice(), locked: game.locked.slice() });
    if (game.undo.length > 200) game.undo.shift();
  }

  function onCellTap(i) {
    if (!canPlay()) return;
    if (game.fast && game.fastNum) {
      game.selected = i;
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
      render();
      return;
    }
    if (game.selected < 0) { toast('Előbb válassz egy mezőt'); return; }
    input(game.selected, d);
  }

  function input(i, d) {
    if (game.locked[i]) { render(); return; }
    if (game.pencil) {
      if (game.values[i]) { render(); return; }
      // csak a lehetséges jelölteket engedi, ha a szám már szerepel a sorban/oszlopban/blokkban
      const bit = 1 << d;
      if (!(game.notes[i] & bit) && !(E.candidates(correctValues(), i) & bit)) {
        toast('A ' + d + ' már szerepel a sorban, oszlopban vagy blokkban');
        animate(i, 'shake', 500);
        return;
      }
      snapshot();
      game.notes[i] ^= bit;
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
    snapshot();
    game.values[i] = d;
    pop(i);
    if (d !== game.solution[i]) {
      game.mistakes++;
      haptic([30, 40, 30]);
      animate(i, 'shake', 500);
      if ((settings.mistakeLimit || game.mp) && game.mistakes >= MAX_MISTAKES) {
        afterChange();
        lose();
        return;
      }
    } else {
      game.locked[i] = 2;
      game.notes[i] = 0;
      if (settings.autoRemoveNotes) for (const p of PEERS[i]) game.notes[p] &= ~(1 << d);
      game.score += DIFFICULTIES[game.diff].points;
      haptic(10);
      checkUnits(i);
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

  /* Kitöltött sor / oszlop / blokk felvillantása és bónusz pont. */
  function checkUnits(i) {
    const groups = [
      [...Array(9).keys()].map((k) => ROW[i] * 9 + k),
      [...Array(9).keys()].map((k) => k * 9 + COL[i]),
      [...Array(9).keys()].map((k) => {
        const br = Math.floor(BOX[i] / 3) * 3, bc = (BOX[i] % 3) * 3;
        return (br + Math.floor(k / 3)) * 9 + bc + (k % 3);
      }),
    ];
    let completed = 0;
    for (const g of groups) {
      if (g.every((j) => game.values[j] && game.values[j] === game.solution[j])) {
        completed++;
        g.forEach((j, n) => setTimeout(() => animate(j, 'flash', 650), n * 35));
      }
    }
    if (completed) game.score += completed * DIFFICULTIES[game.diff].points * 2;
  }

  function erase() {
    if (!canPlay()) return;
    const i = game.selected;
    if (i < 0) return;
    if (game.locked[i]) { toast('Ez a mező nem törölhető'); return; }
    if (!game.values[i] && !game.notes[i]) return;
    snapshot();
    if (game.values[i]) game.values[i] = 0; else game.notes[i] = 0;
    afterChange();
  }

  function undo() {
    if (!canPlay()) return;
    const s = game.undo.pop();
    if (!s) { toast('Nincs mit visszavonni'); return; }
    game.values = s.values;
    game.notes = s.notes;
    game.locked = s.locked;
    afterChange();
  }

  function hint() {
    if (!canPlay()) return;
    if (game.hintsLeft <= 0) { toast('Elfogytak a tippek'); return; }
    let i = game.selected;
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
    if (settings.autoRemoveNotes) for (const p of PEERS[i]) game.notes[p] &= ~(1 << d);
    game.hintsLeft--;
    game.selected = i;
    pop(i);
    animate(i, 'hinted', 1200);
    checkUnits(i);
    afterChange();
    checkWin();
  }

  /* Gyors ceruza: minden üres mezőbe beírja a lehetséges jelölteket. */
  function autoNotes() {
    if (!canPlay()) return;
    snapshot();
    const cv = correctValues();
    for (let i = 0; i < 81; i++) {
      if (!game.values[i]) game.notes[i] = E.candidates(cv, i);
    }
    toast('Jelöltek kitöltve');
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
    if (game.fast) toast('Gyors mód: előbb számot, aztán mezőket válassz');
    afterChange();
  }

  /* ---------- Vége ---------- */

  function checkWin() {
    for (let i = 0; i < 81; i++) if (game.values[i] !== game.solution[i]) return;
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
    for (let i = 0; i < 81; i++) setTimeout(() => animate(i, 'flash', 650), (ROW[i] + COL[i]) * 40);
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
    $('endEmoji').textContent = won ? '🏆' : '😵';
    $('endTitle').textContent = won ? 'Gratulálok, megoldottad!' : 'Elfogyott a 3 lehetőség';
    const diff = DIFFICULTIES[game.diff];
    let html =
      '<div><small>Nehézség</small><b>' + diff.name + '</b></div>' +
      '<div><small>Idő</small><b>' + fmtTime(game.elapsed) + '</b></div>' +
      '<div><small>Hibák</small><b>' + game.mistakes + '</b></div>' +
      '<div><small>Eredmény</small><b>' + game.score.toLocaleString('hu-HU') + '</b></div>';
    if (won && (timeBonus || mistakeBonus)) {
      html += '<div class="best"><small>Bónusz</small><b>+' + (timeBonus + mistakeBonus) + (mistakeBonus ? ' (hibátlan!)' : '') + '</b></div>';
    }
    if (won && isBest) html += '<div class="best"><b>⭐ Új legjobb idő!</b></div>';
    $('endStats').innerHTML = html;

    const actions = $('endActions');
    actions.innerHTML = '';
    if (!won) {
      addAction(actions, 'Második esély', 'btn primary', () => {
        game.status = 'playing';
        game.mistakes = MAX_MISTAKES - 1;
        closeModal();
        // az utolsó hibás beírás visszavonása
        const last = game.undo.pop();
        if (last) { game.values = last.values; game.notes = last.notes; game.locked = last.locked; }
        afterChange();
        startTimer();
      });
    }
    addAction(actions, 'Új játék', won ? 'btn primary' : 'btn', () => { closeModal(); openSheet('diffSheet'); });
    addAction(actions, 'Főmenü', 'btn ghost', () => { closeModal(); if (game.status !== 'playing') game = null; openHome(); });
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
    $('diffTitle').textContent = isMp ? 'Kétszemélyes játék' : 'Válassz nehézséget';
    $('mpNameField').classList.toggle('hidden', !isMp);
    if (isMp) $('mpName').value = store.get(KEY_NAME, '');
    const list = $('diffList');
    list.innerHTML = '';
    Object.values(DIFFICULTIES).forEach((d, n) => {
      const b = document.createElement('button');
      b.className = 'diff-item';
      b.innerHTML = '<span>' + d.name + '<br><small>' + d.clues + ' adott szám · ' + d.hints + ' tipp</small></span>' +
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
      rows += '<tr><td>' + d.name + '</td><td>' + s.won + '/' + s.played + '</td><td>' + rate + '</td><td>' +
        (s.best ? fmtTime(s.best) : '–') + '</td><td>' + avg + '</td><td>' + s.bestStreak + '</td></tr>';
    }
    $('statsBody').innerHTML =
      '<p style="text-align:center;color:var(--accent);margin:0 0 6px">Összpontszám: <b>' + stats.total.toLocaleString('hu-HU') + '</b></p>' +
      '<table class="stats-table"><tr><th></th><th>Nyert</th><th>Arány</th><th>Legjobb</th><th>Átlag</th><th>Sorozat</th></tr>' + rows + '</table>';
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
        b.textContent = 'Biztosan? Koppints újra';
        resetArmed = setTimeout(() => { resetArmed = null; b.textContent = 'Statisztika törlése'; }, 3000);
        return;
      }
      clearTimeout(resetArmed);
      resetArmed = null;
      b.textContent = 'Statisztika törlése';
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
        settings[el.dataset.setting] = el.checked;
        store.set(KEY_SETTINGS, settings);
        if (game) render();
      });
    });

    // Billentyűzet (asztali géphez / külső billentyűzethez)
    document.addEventListener('keydown', (e) => {
      if (!game || !$('game').classList.contains('active')) return;
      const k = e.key;
      if (k >= '1' && k <= '9') { onNumber(+k); e.preventDefault(); return; }
      if (k === 'Backspace' || k === 'Delete' || k === '0') { erase(); return; }
      if (k === 'n' || k === 'N' || k === 'p' || k === 'P') { togglePencil(); return; }
      if (k === 'z' && (e.metaKey || e.ctrlKey)) { undo(); e.preventDefault(); return; }
      if (k === ' ') { setPaused(!paused); e.preventDefault(); return; }
      const moves = { ArrowUp: -9, ArrowDown: 9, ArrowLeft: -1, ArrowRight: 1 };
      if (moves[k] && canPlay()) {
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
      mp.ended = !!m.mp.ended;
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

  const ICO_SHARE = '<span class="ico"><svg viewBox="0 0 24 24"><path d="M12 15V3M7 8l5-5 5 5"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg></span>';
  const ICO_MORE = '<span class="ico"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></svg></span>';
  const ICO_PLUS = '<span class="ico"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M12 8v8M8 12h8"/></svg></span>';

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
    let html = '';
    if (ios && kind === 'inapp') {
      html =
        '<div class="install-warn">Az oldalt most egy alkalmazás (pl. Messenger, Gmail, Instagram) saját böngészője nyitotta meg. ' +
        'Itt nincs „Főképernyőhöz adás”, ezért előbb nyisd meg <b>Safariban</b>.</div>' +
        '<ol class="steps">' +
        '<li><span>Koppints a jobb felső vagy alsó ' + ICO_MORE + ' menüre, és válaszd a <b>Megnyitás Safariban</b> (vagy „Megnyitás böngészőben”) lehetőséget.</span></li>' +
        '<li><span>Ha nincs ilyen, másold ki a címet, nyisd meg a <b>Safarit</b>, és illeszd be a címsorba.</span></li>' +
        '<li><span>Safariban nyisd meg újra ezt az útmutatót a kezdőképernyőn.</span></li>' +
        '</ol>' +
        '<div class="copy-row"><code>' + url + '</code><button class="btn primary" id="btnCopyUrl">Másolás</button></div>';
    } else if (ios && kind === 'chrome') {
      html = '<ol class="steps">' +
        '<li><span>Koppints a címsor jobb oldalán a ' + ICO_SHARE + ' <b>Megosztás</b> ikonra.</span></li>' +
        '<li><span>Görgess le, és válaszd a ' + ICO_PLUS + ' <b>Főképernyőhöz adás</b> lehetőséget.</span></li>' +
        '<li><span>Koppints a <b>Hozzáadás</b> gombra.</span></li>' +
        '</ol>' +
        '<p class="install-note">Ha nem látod, nyisd meg az oldalt Safariban – ott biztosan működik.</p>';
    } else if (ios && kind === 'other') {
      html = '<div class="install-warn">Ebben a böngészőben a főképernyőre tétel nem mindig érhető el. Nyisd meg az oldalt <b>Safariban</b>.</div>' +
        '<div class="copy-row"><code>' + url + '</code><button class="btn primary" id="btnCopyUrl">Másolás</button></div>';
    } else if (ios) {
      html = '<ol class="steps">' +
        '<li><span>Koppints a Safari ' + ICO_SHARE + ' <b>Megosztás</b> ikonjára. iOS 26-on előbb a címsor melletti ' + ICO_MORE + ' gombra, majd a <b>Megosztás</b>ra.</span></li>' +
        '<li><span><b>Görgess lejjebb</b> a megnyíló listában, és válaszd a ' + ICO_PLUS + ' <b>Főképernyőhöz adás</b> lehetőséget.</span></li>' +
        '<li><span>Hagyd bekapcsolva a <b>Megnyitás webappként</b> kapcsolót (ha van), és koppints a <b>Hozzáadás</b> gombra.</span></li>' +
        '</ol>' +
        '<p class="install-note">Ha a listában nincs ott: görgess a lista aljára → <b>Műveletek szerkesztése</b> → keresd meg a „Főképernyőhöz adás”-t és kapcsold be. ' +
        'Privát böngészésben és ha a Képernyőidő korlátozza a Safarit, az opció nem jelenik meg.</p>';
    } else {
      html = '<p class="install-note" style="font-size:15px">Telefonon nyisd meg ezt a címet Safariban (iPhone) vagy Chrome-ban (Android), majd a böngésző menüjéből válaszd a <b>Főképernyőhöz adás</b> lehetőséget.</p>' +
        '<div class="copy-row"><code>' + url + '</code><button class="btn primary" id="btnCopyUrl">Másolás</button></div>';
    }
    $('installBody').innerHTML = html;
    const copy = $('btnCopyUrl');
    if (copy) {
      copy.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(url); toast('Cím kimásolva'); }
        catch (e) { toast('Jelöld ki és másold a címet'); }
      });
    }
    openSheet('installSheet');
  }

  /* ---------- Kétszemélyes játék ---------- */

  const API = location.origin + '/wp-json/crazy-sudoku/v1';
  const POLL_MS = 2000;
  const mp = { data: null, offset: 0, poll: null, pushTimer: null, pushing: false, dirty: false, lastPush: 0, ended: false, joinId: '', oppOutShown: false };

  function loadMp() {
    const g = store.get(KEY_MP, null);
    if (!g || !g.mp || !Array.isArray(g.values) || g.values.length !== 81) return null;
    return g;
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
      throw new Error('Nincs internetkapcsolat');
    }
    let data = null;
    try { data = await res.json(); } catch (e) { /* */ }
    if (!res.ok) throw new Error((data && data.message) || 'Szerverhiba (' + res.status + ')');
    if (data && data.now) mp.offset = data.now - (t0 + Date.now()) / 2;
    return data;
  }

  function mpGameFromRoom(d, sess) {
    const puzzle = Array.from(d.room.puzzle, Number);
    const solution = Array.from(d.room.solution, Number);
    const g = freshGame(DIFFICULTIES[d.room.diff] ? d.room.diff : 'medium', puzzle, solution);
    g.status = 'waiting';
    g.mp = { id: d.room.id, token: sess.token, seat: sess.seat, round: d.room.round, start: d.room.start, ended: false };
    return g;
  }

  function mpBegin(d, sess) {
    game = mpGameFromRoom(d, sess);
    mp.data = d;
    mp.ended = false;
    mp.oppOutShown = false;
    closeModal();
    saveGame();
    openGame();
    mpPush();
  }

  async function mpCreate(diffKey, name) {
    toast('Szoba létrehozása…');
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
        mp.data = d;
        mp.ended = !!local.mp.ended;
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
    const id = (params.get('szoba') || '').toLowerCase();
    if (!/^[a-z0-9]{6}$/.test(id)) return false;
    history.replaceState(null, '', location.pathname);
    const local = loadMp();
    if (local && local.mp.id === id) {
      game = local;
      mp.ended = !!local.mp.ended;
      openGame();
      return true;
    }
    mp.joinId = id;
    $('joinName').value = store.get(KEY_NAME, '');
    $('joinInfo').textContent = 'Ugyanazt a feladványt oldjátok meg egyszerre. Aki előbb végez, nyer.';
    openSheet('joinSheet');
    try {
      const d = await api('GET', '/room/' + id);
      const host = d.players[1];
      const diff = DIFFICULTIES[d.room.diff];
      if (d.players[2]) {
        $('joinInfo').textContent = 'Ebben a szobában már ketten játszanak.';
        $('btnJoin').disabled = true;
      } else {
        $('joinInfo').textContent = (host ? host.name : 'Valaki') + ' meghívott egy ' + (diff ? diff.name.toLowerCase() : '') +
          ' szudoku párbajra. Ugyanazt a feladványt oldjátok meg egyszerre, aki előbb végez, nyer.';
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
        await navigator.share({ title: 'Szudoku párbaj', text: 'Játsszunk egy szudokut! Ugyanaz a feladvány, aki előbb végez, nyer.', url });
        return;
      } catch (e) {
        if (e && e.name === 'AbortError') return;
      }
    }
    try {
      await navigator.clipboard.writeText(url);
      toast('Link kimásolva');
    } catch (e) {
      toast('Másold ki a linket a tábláról');
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
      if (/nem létezik/.test(e.message)) { toast(e.message); mpStopPolling(); }
    }
  }

  function mpSchedulePush() {
    clearTimeout(mp.pushTimer);
    mp.pushTimer = setTimeout(mpPush, 250);
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
        mistakes: g.mistakes, score: g.score, out: g.status === 'out',
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
    mpCheckEnd();
    render();
  }

  function mpClock() {
    if (!game || !game.mp) return;
    const start = game.mp.start;
    if (game.status === 'waiting' && start && serverNow() >= start) {
      game.status = 'playing';
      saveGame();
      render();
    }
    if (start && (game.status === 'playing' || game.status === 'waiting')) {
      game.elapsed = Math.max(0, Math.floor((serverNow() - start) / 1000));
    }
    $('timer').textContent = fmtTime(game.elapsed);
    mpOverlay();
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
      msg = 'Várakozás a másik játékosra… Küldd el neki a meghívó linket! Amint csatlakozik, indul a visszaszámlálás.';
    } else if (game.status === 'waiting') {
      show = true;
      const left = start ? Math.ceil((start - serverNow()) / 1000) : 0;
      count = left > 0 ? String(left) : '';
      msg = opp.name + ' csatlakozott. Indulás…';
    }
    ov.classList.toggle('hidden', !show);
    $('mpCount').textContent = count;
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
    for (let i = 0; i < 81; i++) {
      const s = el.children[i];
      if (game.puzzle[i]) { s.textContent = game.puzzle[i]; s.className = ''; continue; }
      const v = vals ? +vals[i] : 0;
      s.textContent = '';
      s.className = !v ? '' : v === game.solution[i] ? 'f' : 'x';
    }
  }

  function renderMp() {
    const { me, opp } = mpSeats();
    const myPct = Math.round(mpProgress(game.values) * 100);
    $('mpMeName').textContent = (me ? me.name : 'Te') + ' (te)';
    $('mpMeInfo').textContent = myPct + '% · ' + game.mistakes + ' hiba · ' + game.score + ' p';
    $('mpMeBar').style.width = myPct + '%';
    const oppEl = document.querySelector('.mp-p.opp');
    let info = 'még nem csatlakozott';
    let oppPct = 0;
    if (opp) {
      oppPct = Math.round(mpProgress(opp.values) * 100);
      info = oppPct + '% · ' + opp.mistakes + ' hiba · ' + opp.score + ' p';
      if (opp.done) info = 'kész · ' + fmtTime((opp.done - game.mp.start) / 1000);
      else if (opp.out) info = 'kiesett (3 hiba)';
    }
    const online = opp && opp.seen && serverNow() - opp.seen < 15000;
    oppEl.classList.toggle('online', !!online);
    oppEl.classList.toggle('offline', !!opp && !online);
    $('mpOppName').textContent = opp ? opp.name : 'Ellenfél';
    $('mpOppInfo').textContent = info;
    $('mpOppBar').style.width = oppPct + '%';
    $('oppSideName').textContent = opp ? opp.name : 'Ellenfél';
    $('oppSideInfo').textContent = info;
    oppBoard($('oppBoardMini'), opp);
    oppBoard($('oppBoardBig'), opp);
    $('game').classList.toggle('split', !!settings.split);
    $('oppSide').classList.toggle('hidden', !settings.split);
    $('diffName').textContent = DIFFICULTIES[game.diff].name;
    mpOverlay();
  }

  function mpSetSplit(on) {
    settings.split = on;
    store.set(KEY_SETTINGS, settings);
    render();
  }

  function mpSolved() {
    game.status = 'won';
    game.score += DIFFICULTIES[game.diff].points * 10;
    for (let i = 0; i < 81; i++) setTimeout(() => animate(i, 'flash', 650), (ROW[i] + COL[i]) * 40);
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
      $('endTitle').textContent = 'Kiestél – elfogyott a 3 lehetőség';
      $('endStats').innerHTML = '<div class="best"><b>' + escapeHtml(opp.name) + ' még játszik.</b></div>';
      const actions = $('endActions');
      actions.innerHTML = '';
      addAction(actions, 'Figyelem tovább', 'btn primary', closeModal);
      addAction(actions, 'Főmenü', 'btn ghost', () => { closeModal(); openHome(); });
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
      toast(opp.name + ' kiesett – fejezd be a győzelemért!');
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
    const t = (p) => (p.done ? fmtTime((p.done - start) / 1000) : p.out ? 'kiesett' : '–');
    $('endEmoji').textContent = result === 'win' ? '🏆' : result === 'draw' ? '🤝' : '🥈';
    $('endTitle').textContent = result === 'win' ? 'Nyertél!' : result === 'draw' ? 'Döntetlen – mindketten kiestetek' : opp.name + ' nyert';
    $('endStats').innerHTML =
      '<div><small>Te</small><b class="' + (result === 'win' ? 'win' : '') + '">' + t(me) + '</b></div>' +
      '<div><small>' + escapeHtml(opp.name) + '</small><b class="opp-c">' + t(opp) + '</b></div>' +
      '<div><small>Hibáid</small><b>' + me.mistakes + '</b></div>' +
      '<div><small>Pontjaid</small><b>' + game.score.toLocaleString('hu-HU') + '</b></div>';
    const actions = $('endActions');
    actions.innerHTML = '';
    addAction(actions, 'Visszavágó', 'btn primary', mpRematch);
    addAction(actions, 'Főmenü', 'btn ghost', () => { closeModal(); openHome(); });
    $('endModal').classList.remove('hidden');
  }

  async function mpRematch() {
    if (!game || !game.mp) return;
    closeModal();
    toast('Új feladvány…');
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

  buildBoard();
  bind();
  game = loadGame();
  openHome();
  $('btnInstall').classList.toggle('hidden', isStandalone());
  mpHandleLink();

  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    navigator.serviceWorker.register('sw.js').catch(() => { /* offline mód nem elérhető */ });
  }
})();
