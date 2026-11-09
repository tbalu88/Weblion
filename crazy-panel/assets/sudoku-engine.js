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

  /* ---------- Logikai elemzés: mennyire nehéz egy mezőt kikövetkeztetni ----------
     0 = azonnal látszik (egyetlen jelölt vagy rejtett egyes a mostani táblán)
     1 = előre gondolkodás: több egyes lépés láncolásából jön ki
     2 = jelölt-technika kell (zárt jelöltek, csupasz pár/hármas, rejtett pár)
     3 = haladó vagy még nehezebb (X-wing, XY-wing, ill. ezekkel sem következik) */

  const UNITS = [];
  for (let k = 0; k < 9; k++) {
    const r = [], c = [], b = [];
    for (let j = 0; j < 9; j++) {
      r.push(k * 9 + j);
      c.push(j * 9 + k);
      b.push((((k / 3) | 0) * 3 + ((j / 3) | 0)) * 9 + (k % 3) * 3 + (j % 3));
    }
    UNITS.push(r, c, b);
  }
  const SEES = new Uint8Array(81 * 81);
  for (let i = 0; i < 81; i++) for (const p of PEERS[i]) SEES[i * 81 + p] = 1;

  function bitsOf(m) {
    const out = [];
    for (let d = 1; d <= 9; d++) if (m & (1 << d)) out.push(d);
    return out;
  }

  function solvesWith(values, target, tier, out) {
    const g = Uint8Array.from(values);
    const cand = new Uint16Array(81);
    for (let i = 0; i < 81; i++) if (!g[i]) cand[i] = candidates(g, i);
    const assign = (i, d) => {
      g[i] = d; cand[i] = 0;
      for (const p of PEERS[i]) cand[p] &= ~(1 << d);
    };
    // egy cellából jelölt törlése; true, ha tényleg változott
    const drop = (i, mask) => {
      if (g[i] || !(cand[i] & mask)) return false;
      cand[i] &= ~mask;
      return true;
    };

    function singles() {
      let any = false;
      for (let i = 0; i < 81; i++) {
        if (!g[i] && popcount(cand[i]) === 1) { assign(i, bitsOf(cand[i])[0]); any = true; }
      }
      for (const u of UNITS) {
        for (let d = 1; d <= 9; d++) {
          const bit = 1 << d;
          let at = -1, n = 0;
          for (const i of u) {
            if (g[i] === d) { n = -1; break; }
            if (!g[i] && (cand[i] & bit)) { at = i; n++; }
          }
          if (n === 1) { assign(at, d); any = true; }
        }
      }
      return any;
    }

    function locked() {
      let ch = false;
      for (let ui = 0; ui < 27; ui++) {
        const u = UNITS[ui];
        for (let d = 1; d <= 9; d++) {
          const bit = 1 << d;
          const cells = u.filter((i) => !g[i] && (cand[i] & bit));
          if (cells.length < 2) continue;
          if (ui % 3 === 2) {
            // blokk -> sor / oszlop (pointing)
            if (cells.every((i) => ROW[i] === ROW[cells[0]])) {
              for (const i of UNITS[ROW[cells[0]] * 3]) if (BOX[i] !== BOX[cells[0]]) ch = drop(i, bit) || ch;
            }
            if (cells.every((i) => COL[i] === COL[cells[0]])) {
              for (const i of UNITS[COL[cells[0]] * 3 + 1]) if (BOX[i] !== BOX[cells[0]]) ch = drop(i, bit) || ch;
            }
          } else if (cells.every((i) => BOX[i] === BOX[cells[0]])) {
            // sor / oszlop -> blokk (claiming)
            for (const i of UNITS[BOX[cells[0]] * 3 + 2]) if (!u.includes(i)) ch = drop(i, bit) || ch;
          }
        }
        if (ch) return true;
      }
      return false;
    }

    function nakedSubsets() {
      for (const u of UNITS) {
        const open = u.filter((i) => !g[i]);
        for (const k of [2, 3]) {
          const pool = open.filter((i) => popcount(cand[i]) <= k);
          const n = pool.length;
          for (let a = 0; a < n; a++) for (let b = a + 1; b < n; b++) {
            if (k === 2) {
              const m = cand[pool[a]] | cand[pool[b]];
              if (popcount(m) !== 2) continue;
              let ch = false;
              for (const i of open) if (i !== pool[a] && i !== pool[b]) ch = drop(i, m) || ch;
              if (ch) return true;
              continue;
            }
            for (let c = b + 1; c < n; c++) {
              const m = cand[pool[a]] | cand[pool[b]] | cand[pool[c]];
              if (popcount(m) !== 3) continue;
              let ch = false;
              for (const i of open) if (i !== pool[a] && i !== pool[b] && i !== pool[c]) ch = drop(i, m) || ch;
              if (ch) return true;
            }
          }
        }
      }
      return false;
    }

    function hiddenPairs() {
      for (const u of UNITS) {
        const where = [];
        for (let d = 1; d <= 9; d++) where[d] = u.filter((i) => !g[i] && (cand[i] & (1 << d)));
        for (let d1 = 1; d1 <= 9; d1++) {
          if (where[d1].length !== 2) continue;
          for (let d2 = d1 + 1; d2 <= 9; d2++) {
            if (where[d2].length !== 2 || where[d2][0] !== where[d1][0] || where[d2][1] !== where[d1][1]) continue;
            const keep = (1 << d1) | (1 << d2);
            let ch = false;
            for (const i of where[d1]) ch = drop(i, cand[i] & ~keep) || ch;
            if (ch) return true;
          }
        }
      }
      return false;
    }

    function xWing() {
      for (let d = 1; d <= 9; d++) {
        const bit = 1 << d;
        for (const byRow of [true, false]) {
          const lines = [];
          for (let k = 0; k < 9; k++) {
            const u = UNITS[k * 3 + (byRow ? 0 : 1)];
            const pos = [];
            for (let j = 0; j < 9; j++) if (!g[u[j]] && (cand[u[j]] & bit)) pos.push(j);
            if (pos.length === 2) lines.push([k, pos[0], pos[1]]);
          }
          for (let a = 0; a < lines.length; a++) for (let b = a + 1; b < lines.length; b++) {
            if (lines[a][1] !== lines[b][1] || lines[a][2] !== lines[b][2]) continue;
            let ch = false;
            for (const j of [lines[a][1], lines[a][2]]) {
              for (let k = 0; k < 9; k++) {
                if (k === lines[a][0] || k === lines[b][0]) continue;
                ch = drop(byRow ? k * 9 + j : j * 9 + k, bit) || ch;
              }
            }
            if (ch) return true;
          }
        }
      }
      return false;
    }

    function xyWing() {
      const bi = [];
      for (let i = 0; i < 81; i++) if (!g[i] && popcount(cand[i]) === 2) bi.push(i);
      for (const p of bi) {
        const [a, b] = bitsOf(cand[p]);
        for (const x of bi) {
          if (x === p || !SEES[p * 81 + x]) continue;
          const mx = cand[x];
          if (!(mx & (1 << a)) || (mx & (1 << b))) continue;
          const c = bitsOf(mx & ~(1 << a))[0];
          for (const y of bi) {
            if (y === p || y === x || !SEES[p * 81 + y]) continue;
            if (cand[y] !== ((1 << b) | (1 << c))) continue;
            let ch = false;
            for (let i = 0; i < 81; i++) {
              if (i === x || i === y || i === p) continue;
              if (SEES[x * 81 + i] && SEES[y * 81 + i]) ch = drop(i, 1 << c) || ch;
            }
            if (ch) return true;
          }
        }
      }
      return false;
    }

    for (let guard = 0; guard < 400; guard++) {
      if (target >= 0 && g[target]) break;
      if (singles()) continue;
      if (tier >= 1 && (locked() || nakedSubsets() || hiddenPairs())) continue;
      if (tier >= 2 && (xWing() || xyWing())) continue;
      break;
    }
    if (out) out.grid = g;
    return !!g[target];
  }

  /* Közvetlenül látszik-e a mező: egyetlen jelölt, vagy valamelyik egységében egyedül ő veheti fel egy számát. */
  function directSingle(values, target) {
    const m = candidates(values, target);
    if (popcount(m) === 1) return true;
    for (const u of UNITS) {
      if (!u.includes(target)) continue;
      for (const d of bitsOf(m)) {
        const bit = 1 << d;
        let other = false;
        for (const i of u) {
          if (i !== target && !values[i] && (candidates(values, i) & bit)) { other = true; break; }
        }
        if (!other) return true;
      }
    }
    return false;
  }

  /* values: csak a biztosan helyes számok (0 = üres). */
  function deductionTier(values, target) {
    if (values[target] || directSingle(values, target)) return 0;
    if (solvesWith(values, target, 0)) return 1;
    if (solvesWith(values, target, 1)) return 2;
    return 3;
  }

  global.SudokuEngine = {
    ROW, COL, BOX, PEERS, UNITS, DIFFICULTIES,
    generate, solve, countSolutions, candidates, popcount, deductionTier,
    // teszteléshez: logikai megoldás az adott szintig (target = -1: amíg lehet)
    logicSolve(values, tier) { const o = {}; solvesWith(values, -1, tier, o); return Array.from(o.grid); },
  };
})(typeof window !== 'undefined' ? window : globalThis);
