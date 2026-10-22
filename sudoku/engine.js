/* Sudoku motor: generálás egyértelmű megoldással, megoldásszámlálás, jelöltek. */
(function (global) {
  'use strict';

  const ROW = new Uint8Array(81), COL = new Uint8Array(81), BOX = new Uint8Array(81);
  const PEERS = [];
  for (let i = 0; i < 81; i++) {
    ROW[i] = (i / 9) | 0;
    COL[i] = i % 9;
    BOX[i] = ((ROW[i] / 3) | 0) * 3 + ((COL[i] / 3) | 0);
  }
  for (let i = 0; i < 81; i++) {
    const p = [];
    for (let j = 0; j < 81; j++) {
      if (j !== i && (ROW[j] === ROW[i] || COL[j] === COL[i] || BOX[j] === BOX[i])) p.push(j);
    }
    PEERS.push(p);
  }

  const ALL = 0x3fe; // 1..9 bitek

  function popcount(m) {
    let n = 0;
    while (m) { m &= m - 1; n++; }
    return n;
  }

  function shuffle(a) {
    for (let i = a.length - 1; i > 0; i--) {
      const j = (Math.random() * (i + 1)) | 0;
      const t = a[i]; a[i] = a[j]; a[j] = t;
    }
    return a;
  }

  /* Visszalépéses kereső (MRV). limit: ennyi megoldás után leáll.
     random=true esetén véletlen sorrendben próbál (teljes rács generálásához). */
  function search(grid, limit, random) {
    const g = Uint8Array.from(grid);
    const rows = new Uint16Array(9), cols = new Uint16Array(9), boxes = new Uint16Array(9);
    for (let i = 0; i < 81; i++) {
      const v = g[i];
      if (!v) continue;
      const bit = 1 << v;
      if ((rows[ROW[i]] | cols[COL[i]] | boxes[BOX[i]]) & bit) return { count: 0, solution: null };
      rows[ROW[i]] |= bit; cols[COL[i]] |= bit; boxes[BOX[i]] |= bit;
    }
    let count = 0, solution = null;

    function rec() {
      let best = -1, bestMask = 0, bestCnt = 10;
      for (let i = 0; i < 81; i++) {
        if (g[i]) continue;
        const m = ~(rows[ROW[i]] | cols[COL[i]] | boxes[BOX[i]]) & ALL;
        const n = popcount(m);
        if (n < bestCnt) {
          best = i; bestMask = m; bestCnt = n;
          if (n <= 1) break;
        }
      }
      if (best < 0) {
        count++;
        if (!solution) solution = Array.from(g);
        return;
      }
      if (bestCnt === 0) return;
      const digits = [];
      for (let d = 1; d <= 9; d++) if (bestMask & (1 << d)) digits.push(d);
      if (random) shuffle(digits);
      const r = ROW[best], c = COL[best], b = BOX[best];
      for (const d of digits) {
        const bit = 1 << d;
        g[best] = d; rows[r] |= bit; cols[c] |= bit; boxes[b] |= bit;
        rec();
        g[best] = 0; rows[r] &= ~bit; cols[c] &= ~bit; boxes[b] &= ~bit;
        if (count >= limit) return;
      }
    }
    rec();
    return { count, solution };
  }

  function countSolutions(grid, limit) {
    return search(grid, limit || 2, false).count;
  }

  function solve(grid) {
    return search(grid, 1, false).solution;
  }

  function generateSolved() {
    return search(new Array(81).fill(0), 1, true).solution;
  }

  const DIFFICULTIES = {
    easy:    { key: 'easy',    name: 'Könnyű',   clues: 40, hints: 5, points: 10 },
    medium:  { key: 'medium',  name: 'Közepes',  clues: 33, hints: 4, points: 20 },
    hard:    { key: 'hard',    name: 'Nehéz',    clues: 28, hints: 3, points: 30 },
    expert:  { key: 'expert',  name: 'Szakértő', clues: 25, hints: 2, points: 45 },
    extreme: { key: 'extreme', name: 'Extrém',   clues: 22, hints: 1, points: 60 },
  };

  /* Feladvány készítése: a megoldott rácsból addig vesz ki számokat (középpontosan
     szimmetrikus párokban), amíg a megoldás egyértelmű és el nem éri a cél-számot. */
  function generate(diffKey) {
    const diff = DIFFICULTIES[diffKey] || DIFFICULTIES.medium;
    const attempts = diff.clues <= 25 ? 6 : 1;
    let best = null;
    for (let a = 0; a < attempts; a++) {
      const solution = generateSolved();
      const puzzle = solution.slice();
      let clues = 81;
      const order = shuffle([...Array(41).keys()]); // 0..40, a 40 a középső mező
      for (const i of order) {
        if (clues <= diff.clues) break;
        const j = 80 - i;
        const a1 = puzzle[i], a2 = puzzle[j];
        puzzle[i] = 0; puzzle[j] = 0;
        const removed = i === j ? 1 : 2;
        if (clues - removed < diff.clues - 1 || countSolutions(puzzle, 2) !== 1) {
          puzzle[i] = a1; puzzle[j] = a2;
        } else {
          clues -= removed;
        }
      }
      // Ha szimmetrikusan nem sikerült elég mélyre menni, egyenként folytatja.
      if (clues > diff.clues) {
        for (const i of shuffle([...Array(81).keys()])) {
          if (clues <= diff.clues) break;
          if (!puzzle[i]) continue;
          const v = puzzle[i];
          puzzle[i] = 0;
          if (countSolutions(puzzle, 2) !== 1) puzzle[i] = v; else clues--;
        }
      }
      if (!best || clues < best.clues) best = { puzzle, solution, clues };
      if (clues <= diff.clues) break;
    }
    return best;
  }

  /* Jelöltek bitmaszkja egy mezőre az aktuális értékek alapján. */
  function candidates(values, i) {
    let used = 0;
    for (const p of PEERS[i]) if (values[p]) used |= 1 << values[p];
    return ~used & ALL;
  }

  global.SudokuEngine = {
    ROW, COL, BOX, PEERS, DIFFICULTIES,
    generate, solve, countSolutions, candidates, popcount,
  };
})(typeof window !== 'undefined' ? window : globalThis);
