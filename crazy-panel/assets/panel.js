/* Crazy Foglaltsági Panel – front-end */
(function () {
  'use strict';
  const C = window.CPX || {};
  const app = document.getElementById('cpx-app');
  const HU_MONTHS = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
  const HU_DAYS = ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];
  const HU_DAYS_LONG = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];

  /* ------------------------------------------------------------ utils */
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pad = n => (n < 10 ? '0' : '') + n;
  const ymd = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const parseYmd = s => { const p = s.split('-').map(Number); return new Date(p[0], p[1] - 1, p[2]); };
  const today = () => ymd(new Date());
  const monthKey = s => s.slice(0, 7);
  const ft = n => Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft';
  const huDate = s => { const d = parseYmd(s); return d.getFullYear() + '. ' + HU_MONTHS[d.getMonth()] + ' ' + d.getDate() + '. (' + HU_DAYS_LONG[d.getDay()] + ')'; };
  const norm = s => String(s || '').trim().toLowerCase();
  function el(tag, attrs, html) {
    const e = document.createElement(tag);
    if (attrs) for (const k in attrs) { if (k === 'class') e.className = attrs[k]; else e.setAttribute(k, attrs[k]); }
    if (html != null) e.innerHTML = html;
    return e;
  }

  /* ------------------------------------------------------------ state */
  const S = {
    tab: (location.hash || '#naptar').slice(1),
    month: monthKey(today()),
    day: today(),
    cache: {},      // month -> {events, subs, at}
    track: {},      // dealId -> {sz,tp,bg,dj,gl,note,d,u}
    portal: {},     // dealId -> ügyfélportál összefoglaló {st,done,total,flags,info,dl,locked}
    tf: 'all', tq: '',
    loading: false,
    err: ''
  };
  if (!['naptar', 'kovetes', 'hatterek', 'ellenorzes', 'munkalap', 'elszamolas', 'tarca', 'sablon'].includes(S.tab)) S.tab = 'naptar';
  const d0 = new Date();
  if (d0.getMonth() >= 9) { /* okt–dec: alapból december */ S.month = d0.getFullYear() + '-12'; }

  function monthRange(m) {
    const [y, mo] = m.split('-').map(Number);
    const last = new Date(y, mo, 0).getDate();
    return [m + '-01', m + '-' + pad(last)];
  }

  async function load(m, force) {
    if (!force && S.cache[m]) return S.cache[m];
    const [from, to] = monthRange(m);
    const url = C.ajax + '?action=cp_panel_data&t=' + encodeURIComponent(C.t) + '&from=' + from + '&to=' + to + (force ? '&refresh=1' : '');
    S.loading = true; S.err = ''; renderStatus();
    try {
      const r = await fetch(url, { credentials: 'same-origin' });
      const j = await r.json();
      if (!j.success) throw new Error(j.data && j.data.msg === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : (j.data && j.data.msg) || 'Hiba a lekérdezésben.');
      S.cache[m] = { events: j.data.events || [], subs: j.data.subs || {}, at: j.data.at };
      const tr = j.data.track || {};
      S.cache[m].events.forEach(e => { S.track[e.id] = tr[e.id] || {}; });
      Object.assign(S.portal, j.data.portal || {});
      return S.cache[m];
    } catch (e) {
      S.err = e.message || String(e);
      throw e;
    } finally {
      S.loading = false; renderStatus();
    }
  }

  function dayInfo(m, date) {
    const c = S.cache[m];
    const ev = c ? c.events.filter(e => e.date === date) : [];
    const subs = c && c.subs[date] != null ? c.subs[date] : C.subsDef;
    let selfie = 0, spin = 0, pSelfie = 0, pSpin = 0;
    ev.forEach(e => {
      const s = e.kind === 'selfie' || e.kind === 'both', p = e.kind === 'spin' || e.kind === 'both';
      if (e.state === 'booked') { if (s) selfie++; if (p) spin++; } else { if (s) pSelfie++; if (p) pSpin++; }
    });
    const capS = C.capSelfie + subs;
    return { ev, subs, selfie, spin, pSelfie, pSpin, capS, capP: C.capSpin, freeS: capS - selfie, freeP: C.capSpin - spin };
  }

  function bgStatus(m, date) {
    const c = S.cache[m];
    const map = {};
    (C.bgs || []).forEach(b => { map[norm(b.name)] = { name: b.name, qty: b.qty, booked: [], pending: [] }; });
    const unknown = [];
    (c ? c.events : []).filter(e => e.date === date).forEach(e => {
      const k = norm(e.bg);
      if (map[k]) map[k][e.state === 'booked' ? 'booked' : 'pending'].push(e);
      else unknown.push(e);
    });
    return { list: Object.values(map), unknown };
  }

  /* ------------------------------------------------------------ shell */
  function shell() {
    app.innerHTML = '';
    const top = el('header', { class: 'cpx-top' });
    top.innerHTML = '<div class="cpx-logo">Crazy<span>panel</span></div>' +
      '<nav class="cpx-tabs">' +
      tabBtn('naptar', 'Naptár') + tabBtn('kovetes', 'Követés') + tabBtn('hatterek', 'Hátterek') + tabBtn('sablon', 'Sablonkészítő') + tabBtn('ellenorzes', 'Gyors ellenőrzés') + tabBtn('munkalap', 'Napi munkalap') + tabBtn('elszamolas', 'Elszámolás') + tabBtn('tarca', 'Tárca') +
      '</nav><div class="cpx-top-r"><span class="cpx-status" id="cpx-status"></span>' +
      '<button class="cpx-btn ghost" id="cpx-refresh" title="Frissítés a Salesmate-ből">Frissítés</button>' +
      '<a class="cpx-btn ghost" href="' + esc(C.logout) + '">Kilépés</a></div>';
    app.appendChild(top);
    const main = el('main', { class: 'cpx-main', id: 'cpx-main' });
    app.appendChild(main);
    top.querySelectorAll('[data-tab]').forEach(b => b.addEventListener('click', () => go(b.dataset.tab)));
    top.querySelector('#cpx-refresh').addEventListener('click', () => refresh());
    const dlg = el('div', { class: 'cpx-modal', id: 'cpx-modal', hidden: '' });
    app.appendChild(dlg);
    dlg.addEventListener('click', e => { if (e.target === dlg || e.target.closest('[data-close]')) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
  }
  const tabBtn = (id, label) => '<button data-tab="' + id + '" class="' + (S.tab === id ? 'on' : '') + '">' + label + '</button>';

  function renderStatus() {
    const s = document.getElementById('cpx-status');
    if (!s) return;
	if (S.tab === 'elszamolas') { elStatus(s); return; }
	if (S.tab === 'tarca') { twStatus(s); return; }
    if (S.loading) { s.textContent = 'Betöltés…'; s.className = 'cpx-status'; return; }
    if (S.err) { s.textContent = S.err; s.className = 'cpx-status err'; return; }
    const c = S.cache[S.month];
    if (c && c.at) { const d = new Date(c.at * 1000); s.textContent = 'Adatok: ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); s.className = 'cpx-status'; }
  }

  function go(tab) {
    S.tab = tab;
    history.replaceState(null, '', '#' + tab);
	if (tab === 'tarca' || tab === 'elszamolas') setTimeout(() => twTick(true), 50);
    document.querySelectorAll('.cpx-tabs [data-tab]').forEach(b => b.classList.toggle('on', b.dataset.tab === tab));
    closeModal();
    render();
  }

  async function refresh() {
	if (S.tab === 'tarca') { twMonths().forEach(m => { delete EL.cache[m]; }); try { await Promise.all([twLoad(true), twEnsureEl()]); } catch (e) { /* állapotsor */ } render(); return; }
	if (S.tab === 'elszamolas') { try { await elLoad(S.month, true); } catch (e) { /* állapotsor */ } render(); return; }
    const ms = new Set([S.month, monthKey(S.day)]);
    for (const m of ms) { try { await load(m, true); } catch (e) { /* status */ } }
    render();
  }

  /* Sablonkészítő (assets/sablon.js) – a panel adatai felé */
  const SBX = {
    take: () => { const id = S.sbDeal || ''; S.sbDeal = ''; return id; },
    load: m => load(m),
    events: () => { const seen = {}, out = []; Object.keys(S.cache).forEach(m => ((S.cache[m] || {}).events || []).forEach(e => { if (!seen[e.id]) { seen[e.id] = 1; out.push(e); } })); return out.sort((a, b) => (a.date + (a.time || '')).localeCompare(b.date + (b.time || ''))); },
    who: e => who(e),
    huDate: d => huDate(d),
    tp: id => (S.track[id] || {}).tp === '1',
    markTp: async id => { let ev = null; eachEv(id, x => { if (!ev) ev = x; }); if (!ev) throw new Error('Ez a rendezvény nincs betöltve a panelen – pipáld a Követés fülön.'); await setTrack(ev, 'tp', '1'); return (S.track[id] || {}).tp === '1'; },
    toast: t => toast(t)
  };
  async function render() {
    const main = document.getElementById('cpx-main');
	if (S.tab === 'tarca') { pollTrack(false); elPoll(false); viewTw(main); renderStatus(); return; }
	if (S.tab === 'elszamolas') { pollTrack(false); viewEl(main); renderStatus(); return; }
	if (S.tab === 'sablon') { pollTrack(false); elPoll(false); if (window.CPSablon) window.CPSablon.view(main, SBX); else main.innerHTML = '<div class="cpx-empty err">A Sablonkészítő nem töltődött be – töltsd újra az oldalt.</div>'; renderStatus(); return; }
	elPoll(false);
    const m = S.tab === 'munkalap' || S.tab === 'ellenorzes' ? monthKey(S.day) : S.month;
    if (!S.cache[m]) {
      main.innerHTML = '<div class="cpx-empty">Adatok betöltése a Salesmate-ből…</div>';
      try { await load(m); } catch (e) { main.innerHTML = '<div class="cpx-empty err">' + esc(S.err) + '<br><button class="cpx-btn" id="cpx-retry">Újra</button></div>'; main.querySelector('#cpx-retry').onclick = () => render(); return; }
    }
    pollTrack(S.tab === 'kovetes');
    if (S.tab === 'naptar') viewCalendar(main);
    else if (S.tab === 'kovetes') viewTrack(main);
    else if (S.tab === 'hatterek') viewBackgrounds(main);
    else if (S.tab === 'ellenorzes') viewCheck(main);
    else viewSheet(main);
    renderStatus();
  }

  function monthNav(onChange) {
	const [y, mo] = S.month.split('-').map(Number);
	const cur = monthKey(today());
	const box = el('div', { class: 'cpx-mnav' });
	box.innerHTML = '<button class="cpx-btn ghost" data-d="-1" aria-label="Előző hónap">‹</button><h2>' + y + '. ' + HU_MONTHS[mo - 1] + '</h2><button class="cpx-btn ghost" data-d="1" aria-label="Következő hónap">›</button>' +
		'<button class="cpx-btn ghost cpx-today" data-today title="Ugrás az aktuális hónapra"' + (S.month === cur ? ' disabled' : '') + '>Ma</button>';
	box.querySelectorAll('[data-d]').forEach(b => b.addEventListener('click', () => {
		const d = new Date(y, mo - 1 + Number(b.dataset.d), 1);
		S.month = d.getFullYear() + '-' + pad(d.getMonth() + 1);
		onChange();
	}));
	box.querySelector('[data-today]').addEventListener('click', () => {
		if (S.month === cur) return;
		S.month = cur;
		onChange();
	});
	return box;
}

/* ------------------------------------------------------------ naptár */
  function viewCalendar(main) {
    main.innerHTML = '';
    const head = el('div', { class: 'cpx-head' });
    head.appendChild(monthNav(render));
    head.appendChild(el('div', { class: 'cpx-legend' }, '<span class="lg ok"></span>3+ szabad<span class="lg mid"></span>1–2 szabad<span class="lg full"></span>betelt<span class="lg pend"></span>függő ajánlat'));
    main.appendChild(head);
    const c = S.cache[S.month];
    const [from, to] = monthRange(S.month);
    // összesítő
    let bookedN = 0, pendN = 0, fullDays = 0;
    c.events.forEach(e => { if (e.state === 'booked') bookedN++; else pendN++; });
    const grid = el('div', { class: 'cpx-cal' });
    HU_DAYS.forEach(d => grid.appendChild(el('div', { class: 'cpx-dow' }, d)));
    const first = parseYmd(from);
    const lead = (first.getDay() + 6) % 7;
    for (let i = 0; i < lead; i++) grid.appendChild(el('div', { class: 'cpx-day blank' }));
    const last = parseYmd(to).getDate();
    const tdy = today();
    for (let d = 1; d <= last; d++) {
      const date = S.month + '-' + pad(d);
      const I = dayInfo(S.month, date);
      if (I.freeS <= 0) fullDays++;
      const lvl = I.freeS <= 0 ? 'full' : I.freeS <= 2 ? 'mid' : 'ok';
      const cell = el('button', { class: 'cpx-day ' + lvl + (date < tdy ? ' past' : '') + (date === tdy ? ' today' : ''), 'data-date': date });
      const pctS = I.capS ? Math.min(100, Math.round(100 * I.selfie / I.capS)) : 100;
      cell.innerHTML = '<div class="dn">' + d + (I.subs ? '<span class="sub">+' + I.subs + ' alv.</span>' : '') + '</div>' +
        '<div class="row"><span>Szelfi</span><b>' + Math.max(0, I.freeS) + '</b><small>/' + I.capS + ' szabad</small></div>' +
        '<div class="bar"><i style="width:' + pctS + '%"></i></div>' +
        '<div class="row sp"><span>Spinner</span><b>' + Math.max(0, I.freeP) + '</b><small>/' + I.capP + '</small></div>' +
        (I.pSelfie + I.pSpin ? '<div class="pend">' + (I.pSelfie + I.pSpin) + '<span> függő</span></div>' : '');
      cell.addEventListener('click', () => openDay(date));
      grid.appendChild(cell);
    }
    main.appendChild(el('div', { class: 'cpx-kpis' },
      kpi('Foglalás a hónapban', bookedN) + kpi('Függő ajánlat', pendN) + kpi('Betelt nap', fullDays) + kpi('Saját gép / spinner', C.capSelfie + ' / ' + C.capSpin)));
    main.appendChild(grid);
  }
  const kpi = (l, v) => '<div class="kpi"><b>' + esc(v) + '</b><span>' + esc(l) + '</span></div>';

  function openDay(date) {
    const m = monthKey(date);
    const I = dayInfo(m, date);
    const bs = bgStatus(m, date);
    const dayBgs = I.ev.filter(e => e.bg);
    const dlg = document.getElementById('cpx-modal');
    dlg.innerHTML = '<div class="cpx-sheet"><div class="cpx-sheet-h"><h3>' + esc(huDate(date)) + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>' +
      '<div class="cpx-cap">' +
      capBox('Szelfigép', I.selfie, I.capS, I.pSelfie) + capBox('360 Spinner', I.spin, I.capP, I.pSpin) +
      '<div class="cpx-subs"><span>Alvállalkozói slot erre a napra</span><div class="stepper"><button data-s="-1">−</button><b id="cpx-subn">' + I.subs + '</b><button data-s="1">+</button></div></div>' +
      '</div>' +
      '<h4>Rendezvények (' + I.ev.length + ')</h4>' + (I.ev.length ? I.ev.map(evRow).join('') : '<p class="cpx-muted">Erre a napra még nincs rendezvény.</p>') +
      '<h4>Hátterek ezen a napon</h4>' + (dayBgs.length ? dayBgs.map(e => '<div class="bgline"><span class="chip ' + (e.state === 'booked' ? 'taken' : 'pend') + '">' + esc(e.bg) + '</span><span>' + (e.state === 'booked' ? '<b>foglalt</b>' : '<b>csak ajánlatban</b> – még nem foglalt') + ' · ' + esc(e.title) + '</span></div>').join('') : '<p class="cpx-muted">Erre a napra még senki nem választott hátteret.</p>') +
      '<div class="cpx-sheet-f"><button class="cpx-btn" id="cpx-goSheet">Napi munkalap</button><button class="cpx-btn ghost" id="cpx-goCheck">Gyors ellenőrzés erre a napra</button></div></div>';
    dlg.hidden = false;
    dlg.querySelectorAll('[data-s]').forEach(b => b.addEventListener('click', async () => {
      const n = Math.max(0, (I.subs || 0) + Number(b.dataset.s));
      b.disabled = true;
      try {
        const fd = new FormData(); fd.append('action', 'cp_panel_subs'); fd.append('t', C.t); fd.append('date', date); fd.append('n', n);
        const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.success) throw new Error((j.data && j.data.msg) || 'Mentési hiba');
        S.cache[m].subs[date] = n;
        render(); openDay(date);
      } catch (e) { alert(e.message); b.disabled = false; }
    }));
    dlg.querySelector('#cpx-goSheet').onclick = () => { S.day = date; closeModal(); go('munkalap'); };
    dlg.querySelector('#cpx-goCheck').onclick = () => { S.day = date; closeModal(); go('ellenorzes'); };
  }
  function closeModal() { const d = document.getElementById('cpx-modal'); if (d) { d.hidden = true; d.innerHTML = ''; } }
  function capBox(label, used, cap, pend) {
    const free = cap - used;
    return '<div class="capbox ' + (free <= 0 ? 'full' : free <= 2 ? 'mid' : 'ok') + '"><span>' + label + '</span><b>' + Math.max(0, free) + ' szabad</b><small>' + used + ' foglalt / ' + cap + ' kapacitás' + (pend ? ' · ' + pend + ' függő' : '') + '</small></div>';
  }
  function evRow(e) {
    return '<div class="evrow ' + e.state + '"><div><b>' + esc(e.title || 'Deal #' + e.id) + '</b> ' + stateBadge(e) + '</div><small>' +
      [e.time, kindLabel(e), e.loc, e.bg ? 'Háttér: ' + e.bg : ''].filter(Boolean).map(esc).join(' · ') + '</small>' +
      (e.state === 'booked' ? prepLine(e) : '') + '</div>';
  }
  function prepLine(e) {
    const k = chk(e);
    return '<div class="prep ' + k.st + '">' + (k.st === 'done' ? '✓ Minden megvan' : 'Hiányzik: ' + esc(k.miss.join(', '))) + '</div>';
  }
  const kindLabel = e => e.kind === 'spin' ? '360 Spinner' : e.kind === 'both' ? 'Szelfi + Spinner' : 'Szelfigép';
  const stateBadge = e => e.state === 'booked' ? '<span class="badge ok">' + esc(e.won ? 'Megnyert' : e.stage) + '</span>' : '<span class="badge pend">' + esc(e.stage || 'Függő') + '</span>';

  /* ------------------------------------------------------------ hátterek */
  function viewBackgrounds(main) {
    main.innerHTML = '';
    const head = el('div', { class: 'cpx-head' });
    head.appendChild(monthNav(render));
    head.appendChild(el('div', { class: 'cpx-legend' }, '<span class="lg full"></span>foglalt<span class="lg pend"></span>csak ajánlatban<span class="lg ok"></span>szabad'));
    main.appendChild(head);
    main.appendChild(el('p', { class: 'cpx-help' }, '<b>Piros</b> = a háttér foglalt: egy szerződéses (Szerződés kiküldve vagy későbbi) rendezvényhez választották, erre a napra nem adható ki. <b>Sárga</b> = csak egy árajánlatban szerepel: még kiadható, de ha az ajánlatból szerződés lesz, ütközni fog. <b>Zöld / üres</b> = szabad. A táblázat celláira kattintva megnyílik az adott nap.'));
    const [from, to] = monthRange(S.month);
    const last = parseYmd(to).getDate();
    const c = S.cache[S.month];
    // napi gyorskereső
    const pick = el('div', { class: 'cpx-card cpx-bgday' });
    const defDay = monthKey(S.day) === S.month ? S.day : from;
    pick.innerHTML = '<label>Hátterek ezen a napon: <input type="date" id="cpx-bgdate" value="' + defDay + '" min="' + from + '" max="' + to + '"></label><div id="cpx-bgchips"></div>';
    main.appendChild(pick);
    const drawChips = () => {
      const date = pick.querySelector('#cpx-bgdate').value || defDay;
      const bs = bgStatus(S.month, date);
      const taken = bs.list.filter(b => b.booked.length >= b.qty);
      const pend = bs.list.filter(b => b.booked.length < b.qty && b.pending.length);
      const free = bs.list.filter(b => b.booked.length < b.qty && !b.pending.length);
      const who = arr => esc(arr.map(x => x.title).join(', '));
      pick.querySelector('#cpx-bgchips').innerHTML =
        '<div class="bggroup"><h4>Foglalt (' + taken.length + ')</h4>' + (taken.length ? taken.map(b => '<div class="bgline"><span class="chip taken">' + esc(b.name) + '</span><span>' + who(b.booked) + '</span></div>').join('') : '<p class="cpx-muted">Erre a napra nincs foglalt háttér.</p>') + '</div>' +
        (pend.length ? '<div class="bggroup"><h4>Csak ajánlatban – még kiadható (' + pend.length + ')</h4>' + pend.map(b => '<div class="bgline"><span class="chip pend">' + esc(b.name) + '</span><span>' + who(b.pending) + '</span></div>').join('') + '</div>' : '') +
        '<div class="bggroup"><h4>Szabad (' + free.length + ')</h4>' + free.map(b => '<span class="chip">' + esc(b.name) + '</span>').join(' ') + '</div>' +
        (bs.unknown.filter(e => e.bg && norm(e.bg) !== 'még nem tudom').length ? '<p class="cpx-muted">Listán kívüli háttér is szerepel: ' + esc(bs.unknown.filter(e => e.bg && norm(e.bg) !== 'még nem tudom').map(e => e.bg + ' (' + e.title + ')').join('; ')) + '</p>' : '');
    };
    pick.querySelector('#cpx-bgdate').addEventListener('change', e => { S.day = e.target.value; drawChips(); });
    drawChips();

    // havi mátrix
    const wrap = el('div', { class: 'cpx-matrix-wrap' });
    let h = '<table class="cpx-matrix"><thead><tr><th class="sticky">Háttér</th>';
    for (let d = 1; d <= last; d++) {
      const dt = parseYmd(S.month + '-' + pad(d));
      h += '<th class="' + (dt.getDay() === 0 || dt.getDay() === 6 ? 'we' : '') + '">' + d + '<small>' + HU_DAYS[(dt.getDay() + 6) % 7] + '</small></th>';
    }
    h += '</tr></thead><tbody>';
    (C.bgs || []).forEach(b => {
      h += '<tr><th class="sticky">' + esc(b.name) + (b.qty > 1 ? ' <small>×' + b.qty + '</small>' : '') + '</th>';
      for (let d = 1; d <= last; d++) {
        const date = S.month + '-' + pad(d);
        const evs = c.events.filter(e => e.date === date && norm(e.bg) === norm(b.name));
        const bk = evs.filter(e => e.state === 'booked');
        const pd = evs.filter(e => e.state !== 'booked');
        const cls = bk.length >= b.qty ? 'full' : pd.length || bk.length ? 'pend' : '';
        const tip = evs.map(e => (e.state === 'booked' ? '✔ ' : '… ') + e.title).join('\n');
        h += '<td class="' + cls + '" data-date="' + date + '" title="' + esc(tip) + '">' + (bk.length ? '●' : pd.length ? '?' : '') + '</td>';
      }
      h += '</tr>';
    });
    h += '</tbody></table>';
    wrap.innerHTML = h;
    wrap.addEventListener('click', ev => { const td = ev.target.closest('td[data-date]'); if (td) openDay(td.dataset.date); });
    main.appendChild(wrap);

    // háttér nélküli foglalások
    const names = new Set((C.bgs || []).map(b => norm(b.name)));
    const missing = c.events.filter(e => e.state === 'booked' && !names.has(norm(e.bg)));
    const box = el('div', { class: 'cpx-card' });
    box.innerHTML = '<h3>Foglalás háttér nélkül / egyedi háttérrel (' + missing.length + ')</h3>' +
      (missing.length ? missing.map(e => '<div class="mini"><b>' + esc(huDate(e.date)) + '</b>' + esc(e.title) + '<span>' + esc(e.bg || 'nincs kiválasztva') + '</span></div>').join('') : '<p class="cpx-muted">Minden foglaláshoz van háttér választva.</p>');
    main.appendChild(box);
  }


  /* ------------------------------------------------------------ követés */
  const SIGNED = (C.signed || []).map(norm);
  /* ügyfélportál (portal-panel.js): címkék, „Adatlap” oszlop, részletező */
  const PORTAL = !!(C.portal && C.portal.on && window.CPPortal);
  if (PORTAL) window.CPPortal.onChange = async () => {
    const [from, to] = monthRange(S.month);
    try {
      const r = await fetch(C.ajax + '?action=cp_panel_track&t=' + encodeURIComponent(C.t) + '&from=' + from + '&to=' + to, { credentials: 'same-origin' });
      const j = await r.json();
      if (j.success) { Object.assign(S.portal, j.data.portal || {}); if (S.trackDraw && S.tab === 'kovetes') S.trackDraw(); }
    } catch (x) { /* csendben */ }
  };
  const BGSET = new Set((C.bgs || []).map(b => norm(b.name)));
  const DJ = [['', '–'], ['ok', '✓ Rendben'], ['8', '8 napos'], ['30', '30 napos']];
  const HX = [['', '–'], ['0', 'Nem volt']].concat(Array.from({ length: 16 }, (_, i) => { const v = String((i + 1) / 2); return [v, '+' + v.replace('.', ',') + ' óra']; }));
  const hxLabel = v => (HX.find(o => o[0] === v) || ['', ''])[1];
  const hxOf = e => (C.hxSm && e.hx) ? e.hx : (trk(e).hx || '');
  const HXO = (C.hxSm && C.hxInt) ? HX.filter(o => !/\./.test(o[0])) : HX;  // egész órás Salesmate mezőhöz nincs félóra
  const trk = e => S.track[e.id] || {};
  const who = e => {
    const t = String(e.title || '').replace(/^\s*\[[^\]]*\]\s*/, '').replace(/\s*\|.*$/, '').replace(/[\s,–-]*\d{4}[-.]\s?\d{2}[-.]\s?\d{2}\.?\s*$/, '').trim();
    return t || (e.contact && e.contact.name) || ('Deal #' + e.id);
  };
  const daysTo = d => Math.round((parseYmd(d) - parseYmd(today())) / 86400000);

  function bgKind(e) {
    const b = norm(e.bg);
    if (!b || b === '-' || b === '–' || b === 'még nem tudom' || b === 'nincs') return e.kind === 'spin' ? 'na' : 'missing';
    if (/egyedi|megb[ií]z|saj[áa]t|hozz/.test(b)) return /megb[ií]z|hozz/.test(b) ? 'client' : 'custom';
    return BGSET.has(b) ? 'ok' : 'other';
  }

  function bgClashes(m) {
    const c = S.cache[m], qty = {}, cnt = {}, out = new Set();
    (C.bgs || []).forEach(b => { qty[norm(b.name)] = b.qty; });
    if (!c) return out;
    c.events.forEach(e => { if (e.state === 'booked' && qty[norm(e.bg)]) { const k = e.date + '|' + norm(e.bg); (cnt[k] = cnt[k] || []).push(e.id); } });
    Object.keys(cnt).forEach(k => { if (cnt[k].length > qty[k.split('|')[1]]) cnt[k].forEach(id => out.add(id)); });
    return out;
  }

  function chk(e, clashes) {
    const t = trk(e);
    const autoSz = !!e.won || SIGNED.includes(norm(e.stage));
    const sz = t.sz != null ? t.sz === '1' : autoSz;
    const tp = t.tp === '1';
    const bk = bgKind(e);
    const clash = (clashes || bgClashes(monthKey(e.date))).has(e.id);
    const bgOk = !clash && (bk === 'ok' || bk === 'other' || bk === 'na' || t.bg === '1');
    const paidAuto = !!(C.hasPaid && /^(igen|yes|true|1|bejött|megjött|ok)/i.test(String(e.paid || '').trim()));
    const dj = t.dj || '';
    const djOk = !!dj || paidAuto;
    const gl = t.gl === '1';
    const dleft = daysTo(e.date);
    const after = dleft < 0;
    const miss = [];
    if (!sz) miss.push('Szerződés');
    if (!tp) miss.push('Template');
    if (!bgOk) miss.push(clash ? 'Háttér-ütközés' : 'Háttér');
    if (!djOk) miss.push('Díjbekérő');
    if (after && !gl) miss.push('Galéria');
    const pt = S.portal[e.id];
    const ad = !pt || pt.done >= pt.total;
    if (!ad && !after) miss.push('Adatlap');
    const preMiss = miss.filter(x => x !== 'Galéria').length;
    const st = !miss.length ? 'done' : (preMiss && dleft <= 7) ? 'urgent' : 'todo';
    return { t, sz, szAuto: t.sz == null && autoSz, tp, bk, bgOk, clash, dj, djOk, paidAuto, gl, after, dleft, miss, st, pt, ad };
  }

  function trackEvents() {
    const c = S.cache[S.month];
    return c ? c.events.filter(e => e.state === 'booked') : [];
  }

  function viewTrack(main) {
    main.innerHTML = '';
    const head = el('div', { class: 'cpx-head' });
    head.appendChild(monthNav(render));
    const tools = el('div', { class: 'cpx-trk-tools' });
    tools.innerHTML = '<div class="seg sm" id="tk-f">' + [['all', 'Mind'], ['todo', 'Hiányos'], ['urgent', 'Sürgős'], ['done', 'Kész']].concat(PORTAL ? [['adatlap', 'Adatlap hiányos']] : []).map(x => '<button data-v="' + x[0] + '" class="' + (S.tf === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div>' +
      '<input type="search" id="tk-q" placeholder="Keresés: név, háttér…" value="' + esc(S.tq) + '">' +
      '<button class="cpx-btn ghost" id="tk-csv" title="Letöltés Excelhez (CSV)">Excel export</button>';
    head.appendChild(tools);
    main.appendChild(head);
    const kp = el('div', { class: 'cpx-kpis', id: 'tk-kpi' });
    main.appendChild(kp);
    main.appendChild(el('p', { class: 'cpx-help' }, 'A rendezvény-követő táblázat: minden <b>szerződéses</b> rendezvény ebben a hónapban. A pipákat bárki átállíthatja, mindenki ugyanazt látja (percenként frissül). A <b>Szerződés</b> magától bepipálódik, ha a deal a Salesmate-ben már „Szerződés aláírva” vagy későbbi szakaszban van' + (C.stageWrite ? '; ha itt pipálod be, a deal a Salesmate-ben is átkerül ebbe a szakaszba (az automatizációk lefutnak)' : '') + '. A <b>Galéria</b> csak a rendezvény után számít hiánynak, a <b>Hosszabbítás</b> pedig csak információ. Hátteret a háttér nevére vagy a <b>Választ ✎</b> gombra kattintva lehet választani: a választás azonnal bekerül a Salesmate-be, és az aznap már foglalt hátteret nem engedi kiválasztani. <b>Piros</b> sor = 7 napon belüli rendezvény, amiről még hiányzik valami.'));
    const wrap = el('div', { class: 'cpx-trk-wrap' });
    main.appendChild(wrap);

    const draw = () => {
      const all = trackEvents();
      const clashes = bgClashes(S.month);
      const rows = all.map(e => ({ e, k: chk(e, clashes) }));
      const q = norm(S.tq);
      const vis = rows.filter(r => (S.tf === 'all' || (S.tf === 'adatlap' ? (r.k.pt && !r.k.ad) : S.tf === 'done' ? r.k.st === 'done' : S.tf === 'urgent' ? r.k.st === 'urgent' : r.k.st !== 'done')) &&
        (!q || norm(who(r.e) + ' ' + r.e.title + ' ' + r.e.bg + ' ' + (r.k.t.note || '')).includes(q)));
      const n = rows.length, done = rows.filter(r => r.k.st === 'done').length, urg = rows.filter(r => r.k.st === 'urgent').length;
      kp.innerHTML = kpi('Szerződéses rendezvény', n) + kpi('Minden megvan', done) + kpi('Hiányos', n - done) + kpi('Sürgős (7 napon belül)', urg);
      const cnt = f => rows.filter(f).length + '/' + n;
      let h = '<table class="cpx-trk"><thead><tr><th>Dátum</th><th>Megrendelő</th>' +
        '<th>Szerződés<small>' + cnt(r => r.k.sz) + '</small></th><th>Template<small>' + cnt(r => r.k.tp) + '</small></th>' +
        '<th>Háttér<small>' + cnt(r => r.k.bgOk) + '</small></th><th>Díjbekérő<small>' + cnt(r => r.k.djOk) + '</small></th>' +
        '<th>Galéria<small>' + cnt(r => r.k.gl) + '</small></th><th>Hosszabbítás<small>utólag</small></th>' + (PORTAL ? '<th>Adatlap<small>' + cnt(r => r.k.pt && r.k.ad) + '</small></th>' : '') + '<th>Állapot</th></tr></thead><tbody>';
      let lastD = '', g = 1;
      vis.forEach(r => { if (r.e.date !== lastD) { g = 1 - g; lastD = r.e.date; } h += rowHtml(r.e, r.k, g); });
      if (!vis.length) h += '<tr><td colspan="' + (PORTAL ? 10 : 9) + '" class="cpx-empty">' + (n ? 'Nincs a szűrésnek megfelelő rendezvény.' : 'Ebben a hónapban még nincs szerződéses rendezvény.') + '</td></tr>';
      h += '</tbody></table>';
      wrap.innerHTML = h;
    };
    S.trackDraw = draw;

    const find = id => trackEvents().find(e => e.id === id);
    wrap.addEventListener('click', ev => {
      const b = ev.target.closest('[data-f]');
      const tr = ev.target.closest('tr[data-id]');
      if (!tr) return;
      const e = find(tr.dataset.id);
      if (!e) return;
      if (ev.target.closest('[data-note]')) { noteModal(e); return; }
      if (ev.target.closest('[data-sb]')) { S.sbDeal = e.id; go('sablon'); return; }
      if (ev.target.closest('[data-portal]')) { if (window.CPPortal) window.CPPortal.open(e.id, who(e) + ' – ' + huDate(e.date)); return; }
      if (ev.target.closest('[data-pick]')) { bgPicker(e); return; }
      if (!b || b.tagName === 'SELECT') return;
      const f = b.dataset.f, k = chk(e);
      if (f === 'sz') {
        if (C.stageWrite && C.signFirst && !e.won) {
          const inSigned = SIGNED.includes(norm(e.stage));
          if (!k.sz && !inSigned) {
            if (confirm('Megjött az aláírt szerződés?\n\nA deal a Salesmate-ben átkerül a(z) „' + C.signFirst + '” szakaszba (most: „' + e.stage + '”). A szakaszhoz tartozó Salesmate automatizációk lefutnak.')) setStage(e, 'sign');
            return;
          }
          if (k.sz && k.szAuto && norm(e.stage) === norm(C.signFirst)) {
            const back = k.t.sp || C.bookedFirst;
            if (confirm('Visszavonod a „Szerződés” pipát?\n\nA deal a Salesmate-ben visszakerül a(z) „' + back + '” szakaszba.')) setStage(e, 'revert');
            return;
          }
        }
        setTrack(e, 'sz', k.sz ? '0' : '1');
      }
      else if (f === 'bg') setTrack(e, 'bg', k.t.bg === '1' ? '' : '1');
      else setTrack(e, f, k.t[f] === '1' ? '' : '1');
    });
    wrap.addEventListener('change', ev => {
      const sel = ev.target.closest('select[data-f]');
      const tr = ev.target.closest('tr[data-id]');
      if (!sel || !tr) return;
      const e = find(tr.dataset.id);
      if (e) setTrack(e, sel.dataset.f, sel.value);
    });
    tools.querySelectorAll('#tk-f button').forEach(b => b.addEventListener('click', () => { S.tf = b.dataset.v; tools.querySelectorAll('#tk-f button').forEach(x => x.classList.toggle('on', x === b)); draw(); }));
    tools.querySelector('#tk-q').addEventListener('input', e => { S.tq = e.target.value; draw(); });
    tools.querySelector('#tk-csv').addEventListener('click', exportCsv);
    draw();
  }

  function tick(f, on, extra, title) {
    return '<button type="button" class="tk ' + (on ? 'on' : '') + (extra ? ' ' + extra : '') + '" data-f="' + f + '" aria-pressed="' + (on ? 'true' : 'false') + '"' + (title ? ' title="' + esc(title) + '"' : '') + '>' + (on ? '✓' : '') + '</button>';
  }

  function rowHtml(e, k, g) {
    const d = parseYmd(e.date);
    const dn = pad(d.getMonth() + 1) + '.' + pad(d.getDate()) + '.';
    let bgCell;
    const bgName = esc(e.bg || '');
    if (k.clash) bgCell = '<span class="bgn bad">' + bgName + '</span><small class="bad">⚠ ütközik egy másik foglalással</small>';
    else if (k.bk === 'custom') bgCell = '<span class="bgn ' + (k.t.bg === '1' ? '' : 'bad') + '">' + bgName.toUpperCase() + '</span>' + '<button type="button" class="bgst ' + (k.t.bg === '1' ? 'on' : '') + '" data-f="bg">' + (k.t.bg === '1' ? '✓ elkészült' : 'gyártás alatt') + '</button>';
    else if (k.bk === 'client') bgCell = '<span class="bgn ' + (k.t.bg === '1' ? '' : 'bad') + '">' + bgName.toUpperCase() + '</span>' + '<button type="button" class="bgst ' + (k.t.bg === '1' ? 'on' : '') + '" data-f="bg">' + (k.t.bg === '1' ? '✓ megérkezett' : 'még nincs nálunk') + '</button>';
    else if (k.bk === 'missing') bgCell = (k.t.bg === '1' ? '<span class="bgn mu">– nem kell</span>' : '<span class="bgn bad">' + (e.bg ? bgName : 'Nincs választva') + '</span>') + '<button type="button" class="bgst ' + (k.t.bg === '1' ? 'on' : '') + '" data-f="bg">' + (k.t.bg === '1' ? 'visszaállít' : 'nem kell háttér') + '</button>';
    else if (k.bk === 'na') bgCell = '<span class="bgn mu">– (spinner)</span>';
    else bgCell = C.bgWrite ? '<button type="button" class="bgn bgname" data-pick title="Másik háttér választása – mentés a Salesmate-be">' + bgName + ' <i>✎</i></button>' : '<span class="bgn">' + bgName + '</span>';
    if (C.bgWrite && k.bk !== 'ok' && k.bk !== 'other') bgCell += '<button type="button" class="bgpick" data-pick title="Háttér kiválasztása – mentés a Salesmate-be">' + (k.bk === 'missing' || k.bk === 'na' ? 'Választ ✎' : '✎') + '</button>';
    const djSel = '<select class="tsel ' + (k.djOk ? 'ok' : '') + '" data-f="dj" aria-label="Díjbekérő">' + DJ.map(o => '<option value="' + o[0] + '"' + (k.dj === o[0] ? ' selected' : '') + '>' + o[1] + '</option>').join('') + '</select>' + (k.paidAuto && !k.dj ? '<small class="okt">utalás bejött (SM)</small>' : '');
    const stTxt = k.st === 'done' ? '✓ Kész' : (k.st === 'urgent' ? '⚠ ' : '') + k.miss.join(', ');
    const sub = [e.time, kindLabel(e)].filter(Boolean).join(' · ');
    return '<tr class="g' + g + ' st-' + k.st + '" data-id="' + esc(e.id) + '">' +
      '<td class="td-d" data-l="Dátum"><b>' + dn + '</b><small>' + HU_DAYS[(d.getDay() + 6) % 7] + (k.dleft >= 0 && k.dleft <= 7 ? ' · ' + (k.dleft === 0 ? 'ma' : k.dleft + ' nap') : '') + '</small></td>' +
      '<td class="td-n" data-l="Megrendelő"><div class="nm"><a href="' + esc(C.sm + e.id + '/detail') + '" target="_blank" rel="noopener" title="Megnyitás a Salesmate-ben">' + esc(who(e)) + '</a>' +
      '<button type="button" class="nt ' + (k.t.note ? 'has' : '') + '" data-note title="' + esc(k.t.note || 'Megjegyzés hozzáadása') + '">' + (k.t.note ? '📝' : '+ megj.') + '</button></div><small>' + esc(sub) + (norm(e.stage) === 'szerződés kiküldve' ? ' · <span class="mut">szerződés kiküldve</span>' : '') + '</small>' + (k.t.note ? '<small class="ntx">' + esc(k.t.note) + '</small>' : '') + '</td>' +
      '<td class="td-c" data-l="Szerződés">' + tick('sz', k.sz, k.szAuto ? 'auto' : '', k.szAuto ? 'Salesmate szerint aláírva (' + e.stage + ')' : '') + '</td>' +
      '<td class="td-c" data-l="Template">' + tick('tp', k.tp) + (window.CPSablon ? '<button type="button" class="sbgo" data-sb title="Sablon elkészítése a Sablonkészítőben (lyukasztott PNG)">✎ sablon</button>' : '') + '</td>' +
      '<td class="td-bg" data-l="Háttér">' + bgCell + '</td>' +
      '<td class="td-c" data-l="Díjbekérő">' + djSel + '</td>' +
      '<td class="td-c" data-l="Galéria">' + tick('gl', k.gl, k.after ? '' : 'later', k.after ? '' : 'A rendezvény után esedékes') + '</td>' +
      '<td class="td-c" data-l="Hosszabbítás"><select class="tsel hx ' + (hxOf(e) ? 'set' : '') + (k.after ? '' : ' later') + '" data-f="hx" aria-label="Hosszabbítás">' + HXO.map(o => '<option value="' + o[0] + '"' + (hxOf(e) === o[0] ? ' selected' : '') + '>' + o[1] + '</option>').join('') + '</select></td>' +
      (PORTAL ? '<td class="td-c td-pp" data-l="Adatlap">' + window.CPPortal.cell(k.pt) + '</td>' : '') +
      '<td class="td-s" data-l="Állapot"><span class="stc ' + k.st + '">' + esc(stTxt) + '</span></td></tr>';
  }


  const eachEv = (id, fn) => Object.keys(S.cache).forEach(mm => S.cache[mm].events.forEach(x => { if (x.id === id) fn(x); }));

  /* szakaszváltás a Salesmate-ben (Szerződés pipa) */
  async function setStage(e, dir) {
    toast(dir === 'sign' ? 'Szakaszváltás a Salesmate-ben…' : 'Visszaállítás a Salesmate-ben…');
    try {
      const fd = new FormData();
      fd.append('action', 'cp_panel_setstage'); fd.append('t', C.t); fd.append('id', e.id); fd.append('dir', dir); fd.append('d', e.date);
      const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json();
      if (!j.success) throw new Error(j.data && j.data.msg === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : (j.data && j.data.msg) || 'Mentési hiba');
      eachEv(e.id, x => { x.stage = j.data.stage; });
      S.track[e.id] = j.data.track || {};
      toast(j.data.changed ? 'Salesmate szakasz: ' + j.data.stage : 'A deal már ebben a szakaszban volt: ' + j.data.stage);
    } catch (x) {
      alert('Nem sikerült: ' + x.message);
    }
    if (S.trackDraw && S.tab === 'kovetes') S.trackDraw();
  }

  async function setTrack(e, f, v) {
    const prev = Object.assign({}, trk(e));
    const nx = Object.assign({}, prev);
    if (v === '') delete nx[f]; else nx[f] = v;
    S.track[e.id] = nx;
    if (S.trackDraw && S.tab === 'kovetes') S.trackDraw();
    try {
      const fd = new FormData();
      fd.append('action', 'cp_panel_track'); fd.append('t', C.t); fd.append('id', e.id); fd.append('f', f); fd.append('v', v); fd.append('d', e.date);
      const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json();
      if (!j.success) throw new Error(j.data && j.data.msg === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : (j.data && j.data.msg) || 'Mentési hiba');
      S.track[e.id] = j.data.track || {};
      if (f === 'hx' && C.hxSm) { eachEv(e.id, x => { x.hx = v; }); toast('Hosszabbítás mentve a Salesmate-be: ' + (hxLabel(v) || '–')); }
    } catch (x) {
      S.track[e.id] = prev;
      alert('Nem sikerült menteni: ' + x.message);
    }
    if (S.trackDraw && S.tab === 'kovetes') S.trackDraw();
  }


  /* háttérválasztó – a választás visszaíródik a Salesmate-be */
  function bgPicker(e) {
    const m = monthKey(e.date);
    const bs = bgStatus(m, e.date);
    const cur = norm(e.bg);
    const stOf = b => {
      const others = b.booked.filter(x => x.id !== e.id), pend = b.pending.filter(x => x.id !== e.id);
      return others.length >= b.qty ? { c: 'taken', t: 'Foglalt – ' + others.map(who).join(', '), who: others } : pend.length ? { c: 'pend', t: 'Ajánlatban – ' + pend.map(who).join(', ') } : { c: 'free', t: 'Szabad' };
    };
    const imgOf = {};
    (C.bgs || []).forEach(b => { imgOf[norm(b.name)] = b.img || ''; });
    const card = b => {
      const st = stOf(b), on = norm(b.name) === cur, img = imgOf[norm(b.name)];
      return '<button type="button" class="pcard ' + st.c + (on ? ' cur' : '') + '" data-bg="' + esc(b.name) + '" title="' + esc(st.t) + '">' +
        (img ? '<img loading="lazy" src="' + esc(img) + '" alt="">' : '<span class="noimg">' + esc(b.name.slice(0, 2)) + '</span>') +
        '<b>' + esc(b.name) + '</b><small>' + (on ? '✓ jelenlegi' : esc(st.c === 'free' ? 'Szabad' : st.c === 'pend' ? 'Ajánlatban' : 'Foglalt')) + '</small></button>';
    };
    const web = bs.list.filter(b => imgOf[norm(b.name)]), rest = bs.list.filter(b => !imgOf[norm(b.name)]);
    const dlg = document.getElementById('cpx-modal');
    dlg.innerHTML = '<div class="cpx-sheet wide"><div class="cpx-sheet-h"><h3>Háttér – ' + esc(who(e)) + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>' +
      '<p class="cpx-muted">' + esc(huDate(e.date)) + ' · jelenleg: <b>' + esc(e.bg || 'nincs megadva') + '</b><br>A választás azonnal mentődik a Salesmate dealbe. A szürke (foglalt) háttereket erre a napra már másik szerződés kérte.</p>' +
      '<input type="search" id="pk-q" class="pk-q" placeholder="Keresés a hátterek között…">' +
      '<div class="pgrid" id="pk-grid">' + web.map(card).join('') + '</div>' +
      (rest.length ? '<h4>További hátterek (a weboldalon nem szerepelnek)</h4><div class="pgrid small">' + rest.map(card).join('') + '</div>' : '') +
      '<h4>Egyéb</h4><div class="pk-other"><button type="button" class="cpx-btn ghost" data-bg="Még nem tudom">Még nem tudom</button>' +
      '<div class="row2"><input id="pk-custom" placeholder="pl. Egyedi – gyártás alatt / Megbízó biztosítja" maxlength="80"><button type="button" class="cpx-btn ghost" id="pk-cgo">Mentés</button></div></div>' +
      '<p class="cpx-muted" id="pk-msg"></p></div>';
    dlg.hidden = false;
    const msg = dlg.querySelector('#pk-msg');
    const save = async (name, force) => {
      msg.textContent = 'Mentés a Salesmate-be…'; msg.className = 'cpx-muted';
      dlg.querySelectorAll('.pcard,.pk-other button').forEach(b => { b.disabled = true; });
      try {
        const fd = new FormData();
        fd.append('action', 'cp_panel_setbg'); fd.append('t', C.t); fd.append('id', e.id); fd.append('d', e.date); fd.append('bg', name);
        if (force) fd.append('force', '1');
        const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.success) {
          if (j.data && j.data.msg === 'conflict') {
            dlg.querySelectorAll('.pcard,.pk-other button').forEach(b => { b.disabled = false; });
            if (confirm('A(z) „' + name + '” háttér erre a napra már foglalt:\n' + (j.data.who || []).join('\n') + '\n\nBiztosan mégis ezt választod?')) return save(name, true);
            msg.textContent = 'Nem mentettem – válassz másik hátteret.'; return;
          }
          throw new Error(j.data && j.data.msg === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : (j.data && j.data.msg) || 'Mentési hiba');
        }
        Object.keys(S.cache).forEach(mm => S.cache[mm].events.forEach(x => { if (x.id === e.id) x.bg = j.data.bg; }));
        closeModal();
        toast('Háttér mentve a Salesmate-be: ' + (j.data.bg || '–'));
        if (S.trackDraw && S.tab === 'kovetes') S.trackDraw();
      } catch (x) {
        msg.textContent = 'Hiba: ' + x.message; msg.className = 'err';
        dlg.querySelectorAll('.pcard,.pk-other button').forEach(b => { b.disabled = false; });
      }
    };
    dlg.querySelectorAll('[data-bg]').forEach(b => b.addEventListener('click', () => {
      const name = b.dataset.bg;
      if (norm(name) === cur) { closeModal(); return; }
      if (b.classList.contains('taken') && !confirm('A(z) „' + name + '” erre a napra már foglalt (' + b.title.replace(/^Foglalt – /, '') + '). Mégis ezt választod?')) return;
      save(name, b.classList.contains('taken'));
    }));
    dlg.querySelector('#pk-cgo').addEventListener('click', () => { const v = dlg.querySelector('#pk-custom').value.trim(); if (v) save(v, false); });
    dlg.querySelector('#pk-q').addEventListener('input', ev => {
      const q = norm(ev.target.value);
      dlg.querySelectorAll('.pcard').forEach(c => { c.hidden = q && !norm(c.dataset.bg).includes(q); });
    });
  }

  function toast(t) {
    let d = document.getElementById('cpx-toast');
    if (!d) { d = el('div', { id: 'cpx-toast', class: 'cpx-toast', role: 'status' }); document.body.appendChild(d); }
    d.textContent = t; d.classList.add('on');
    clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), 3200);
  }

  function noteModal(e) {
    const dlg = document.getElementById('cpx-modal');
    dlg.innerHTML = '<div class="cpx-sheet narrow"><div class="cpx-sheet-h"><h3>Megjegyzés – ' + esc(who(e)) + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>' +
      '<p class="cpx-muted">' + esc(huDate(e.date)) + (e.bg ? ' · Háttér: ' + esc(e.bg) : '') + '</p>' +
      '<textarea id="tk-note" rows="5" maxlength="600" placeholder="pl. díjbekérő a cégnek megy, galéria jelszó kell, egyedi háttér 12.01-re kész…">' + esc(trk(e).note || '') + '</textarea>' +
      '<div class="cpx-sheet-f"><button class="cpx-btn" id="tk-save">Mentés</button>' + (trk(e).note ? '<button class="cpx-btn ghost" id="tk-del">Törlés</button>' : '') + '</div></div>';
    dlg.hidden = false;
    const ta = dlg.querySelector('#tk-note');
    ta.focus();
    dlg.querySelector('#tk-save').onclick = () => { setTrack(e, 'note', ta.value.trim()); closeModal(); };
    const del = dlg.querySelector('#tk-del');
    if (del) del.onclick = () => { setTrack(e, 'note', ''); closeModal(); };
  }

  function exportCsv() {
    const clashes = bgClashes(S.month);
    const q = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
    const lines = [['Dátum', 'Kezdés', 'Megrendelő', 'Szolgáltatás', 'Szerződés', 'Template', 'Háttér', 'Háttér rendben', 'Díjbekérő', 'Galéria', 'Hosszabbítás', 'Állapot', 'Megjegyzés', 'Salesmate'].map(q).join(';')];
    trackEvents().forEach(e => {
      const k = chk(e, clashes);
      const dj = (DJ.find(o => o[0] === k.dj) || ['', ''])[1].replace('✓ ', '');
      lines.push([e.date.replace(/-/g, '.') + '.', e.time, who(e), kindLabel(e), k.sz ? 'igen' : 'nem', k.tp ? 'igen' : 'nem', e.bg, k.bgOk ? 'igen' : 'nem', k.dj ? dj : (k.paidAuto ? 'utalás bejött' : ''), k.gl ? 'igen' : (k.after ? 'nem' : ''), hxOf(e) ? hxLabel(hxOf(e)) : '', k.st === 'done' ? 'Kész' : 'Hiányzik: ' + k.miss.join(', '), k.t.note || '', C.sm + e.id + '/detail'].map(q).join(';'));
    });
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = el('a', { href: URL.createObjectURL(blob), download: 'rendezveny-kovetes-' + S.month + '.csv' });
    document.body.appendChild(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  }

  // közös pipák frissítése percenként, amíg a Követés fül nyitva van
  function pollTrack(on) {
    if (S.pollT) { clearInterval(S.pollT); S.pollT = null; }
    if (!on) return;
    S.pollT = setInterval(async () => {
      if (document.hidden || S.tab !== 'kovetes') return;
      const ae = document.activeElement;
      if (ae && (ae.tagName === 'SELECT' || ae.tagName === 'TEXTAREA') || !document.getElementById('cpx-modal').hidden) return;
      const c = S.cache[S.month];
      if (!c) return;
      const [from, to] = monthRange(S.month);
      try {
        const r = await fetch(C.ajax + '?action=cp_panel_track&t=' + encodeURIComponent(C.t) + '&from=' + from + '&to=' + to, { credentials: 'same-origin' });
        const j = await r.json();
        if (!j.success) return;
        const tr = j.data.track || {};
        let changed = false;
        c.events.forEach(e => { const nv = tr[e.id] || {}; if (JSON.stringify(nv) !== JSON.stringify(S.track[e.id] || {})) { S.track[e.id] = nv; changed = true; } });
        const pt = j.data.portal || {};
        c.events.forEach(e => { const nv = pt[e.id]; if (JSON.stringify(nv || null) !== JSON.stringify(S.portal[e.id] || null)) { if (nv) S.portal[e.id] = nv; else delete S.portal[e.id]; changed = true; } });
        if (changed && S.trackDraw) S.trackDraw();
      } catch (x) { /* csendben */ }
    }, 60000);
  }

  /* ------------------------------------------------------------ gyors ellenőrzés */
  const PRICE = {
    origin: { lat: 47.4577, lon: 19.0424 }, km: 130, trip: 2, both: 0.15,
    guests: [['150 főig', 0], ['151–200 fő', 5000], ['201–300 fő', 7500], ['301–500 fő', 10000], ['501–1000 fő', 20000], ['1000 fő felett (egyedi)', -1]],
    selfie: [['Mini – 3 óra', 99990, 5000], ['Medium Party – 4 óra', 119990, 5000], ['Mega Party – 5 óra', 139990, 0], ['Carneval – 6 óra', 159990, 0]],
    spin: [['Mini Party – 3 óra', 169990, 5000], ['Medium Party – 4 óra', 179990, 5000], ['Mega Party – 5 óra', 194990, 5000], ['Carneval – 6 óra', 219990, 5000]]
  };
  const Q = { kind: 'selfie', ps: 1, pp: 1, g: 0, loc: null, locTxt: '', name: '', bg: '' };

  function viewCheck(main) {
    const m = monthKey(S.day);
    main.innerHTML = '';
    const grid = el('div', { class: 'cpx-check' });
    const form = el('div', { class: 'cpx-card' });
    form.innerHTML =
      '<h3>Ajánlatkérés gyors ellenőrzése</h3>' +
      '<label>Dátum<input type="date" id="q-date" value="' + S.day + '"></label>' +
      '<label>Szolgáltatás<div class="seg" id="q-kind">' + ['selfie:Szelfigép', 'spin:360 Spinner', 'both:Mindkettő −15%'].map(x => { const [v, l] = x.split(':'); return '<button data-v="' + v + '" class="' + (Q.kind === v ? 'on' : '') + '">' + l + '</button>'; }).join('') + '</div></label>' +
      '<label id="q-ps-w">Szelfigép csomag<select id="q-ps">' + PRICE.selfie.map((p, i) => '<option value="' + i + '"' + (i === Q.ps ? ' selected' : '') + '>' + p[0] + ' – ' + ft(p[1]) + '</option>').join('') + '</select></label>' +
      '<label id="q-pp-w">360 Spinner csomag<select id="q-pp">' + PRICE.spin.map((p, i) => '<option value="' + i + '"' + (i === Q.pp ? ' selected' : '') + '>' + p[0] + ' – ' + ft(p[1]) + '</option>').join('') + '</select></label>' +
      '<label>Létszám<select id="q-g">' + PRICE.guests.map((p, i) => '<option value="' + i + '"' + (i === Q.g ? ' selected' : '') + '>' + p[0] + (p[1] > 0 ? ' (+' + ft(p[1]) + ')' : '') + '</option>').join('') + '</select></label>' +
      '<label>Helyszín (település vagy irányítószám)<div class="row2"><input id="q-loc" value="' + esc(Q.locTxt) + '" placeholder="pl. Győr vagy 8000"><button class="cpx-btn" id="q-locgo">Számol</button></div><small id="q-locres" class="cpx-muted"></small></label>' +
      '<label>Kívánt háttér (nem kötelező)<select id="q-bg"><option value="">–</option>' + (C.bgs || []).map(b => '<option' + (Q.bg === b.name ? ' selected' : '') + '>' + esc(b.name) + '</option>').join('') + '</select></label>' +
      '<label>Ügyfél keresztneve (a levélhez)<input id="q-name" value="' + esc(Q.name) + '" placeholder="pl. Ditta"></label>';
    const out = el('div', { class: 'cpx-card', id: 'q-out' });
    grid.appendChild(form); grid.appendChild(out);
    main.appendChild(grid);

    const upd = () => {
      form.querySelector('#q-ps-w').hidden = Q.kind === 'spin';
      form.querySelector('#q-pp-w').hidden = Q.kind === 'selfie';
      drawCheck(out);
    };
    form.querySelector('#q-date').addEventListener('change', async e => {
      S.day = e.target.value || today();
      const mm = monthKey(S.day);
      if (!S.cache[mm]) { out.innerHTML = '<p class="cpx-muted">Betöltés…</p>'; try { await load(mm); } catch (x) { out.innerHTML = '<p class="err">' + esc(S.err) + '</p>'; return; } }
      upd();
    });
    form.querySelectorAll('#q-kind button').forEach(b => b.addEventListener('click', () => { Q.kind = b.dataset.v; form.querySelectorAll('#q-kind button').forEach(x => x.classList.toggle('on', x === b)); upd(); }));
    form.querySelector('#q-ps').addEventListener('change', e => { Q.ps = +e.target.value; upd(); });
    form.querySelector('#q-pp').addEventListener('change', e => { Q.pp = +e.target.value; upd(); });
    form.querySelector('#q-g').addEventListener('change', e => { Q.g = +e.target.value; upd(); });
    form.querySelector('#q-bg').addEventListener('change', e => { Q.bg = e.target.value; upd(); });
    form.querySelector('#q-name').addEventListener('input', e => { Q.name = e.target.value; drawCheck(out); });
    const lookup = async () => {
      const v = form.querySelector('#q-loc').value.trim();
      Q.locTxt = v;
      const res = form.querySelector('#q-locres');
      if (!v) { Q.loc = null; res.textContent = ''; upd(); return; }
      res.textContent = 'Távolság számítása…';
      try { Q.loc = await geo(v); res.textContent = Q.loc.bud ? Q.loc.name + ' – budapesti helyszín' : Q.loc.name + ' – kb. ' + Q.loc.km + ' km tőlünk (oda-vissza ' + (Q.loc.km * PRICE.trip) + ' km)'; }
      catch (e) { Q.loc = null; res.textContent = 'Nem találtam a helyszínt – próbáld irányítószámmal vagy településnévvel.'; }
      upd();
    };
    form.querySelector('#q-locgo').addEventListener('click', lookup);
    form.querySelector('#q-loc').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); lookup(); } });
    upd();
  }

  async function geo(v) {
    const pc = /^\d{4}$/.test(v);
    if (pc && v[0] === '1') return { name: v + ' Budapest', bud: true, km: 0 };
    const u = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&addressdetails=1&countrycodes=hu&' + (pc ? 'postalcode=' + v : 'q=' + encodeURIComponent(v));
    const a = await (await fetch(u, { headers: { 'Accept-Language': 'hu' } })).json();
    if (!a || !a.length) throw new Error('nf');
    const g = a[0], ad = g.address || {};
    const name = (pc ? v + ' ' : '') + (ad.city || ad.town || ad.village || ad.municipality || g.name || v);
    if (ad.city === 'Budapest' || ad.state === 'Budapest') return { name, bud: true, km: 0 };
    const r = await (await fetch('https://router.project-osrm.org/route/v1/driving/' + PRICE.origin.lon + ',' + PRICE.origin.lat + ';' + g.lon + ',' + g.lat + '?overview=false')).json();
    const km = r && r.routes && r.routes[0] ? Math.round(r.routes[0].distance / 1000) : null;
    if (km == null) throw new Error('route');
    return { name, bud: false, km };
  }

  function quote() {
    const lines = [];
    let tot = 0, bud = 0;
    const s = PRICE.selfie[Q.ps], p = PRICE.spin[Q.pp];
    if (Q.kind !== 'spin') { lines.push(['Szelfigép – ' + s[0], s[1]]); tot += s[1]; bud = Math.max(bud, s[2]); }
    if (Q.kind !== 'selfie') { lines.push(['360 Spinner – ' + p[0], p[1]]); tot += p[1]; bud = Math.max(bud, p[2]); }
    if (Q.kind === 'both') { const d = Math.round((s[1] + p[1]) * PRICE.both / 10) * 10; lines.push(['Csomagkedvezmény (2 szolgáltatás) −15%', -d]); tot -= d; }
    const g = PRICE.guests[Q.g];
    let custom = false;
    if (g[1] > 0) { lines.push(['Létszám-felár (' + g[0] + ')', g[1]]); tot += g[1]; }
    if (g[1] < 0) custom = true;
    if (Q.loc) {
      if (Q.loc.bud) { if (bud) { lines.push(['Kiszállás (Budapest)', bud]); tot += bud; } else lines.push(['Kiszállás (Budapest)', 0]); }
      else { const k = Q.loc.km * PRICE.trip * PRICE.km; lines.push(['Kiszállás (' + Q.loc.name + ', ~' + (Q.loc.km * PRICE.trip) + ' km oda-vissza)', k]); tot += k; }
    }
    return { lines, tot, custom };
  }

  function drawCheck(out) {
    const date = S.day, m = monthKey(date);
    const I = dayInfo(m, date);
    const bs = bgStatus(m, date);
    const needS = Q.kind !== 'spin', needP = Q.kind !== 'selfie';
    const okS = !needS || I.freeS > 0, okP = !needP || I.freeP > 0;
    let bgOk = true, bgTxt = '';
    if (Q.bg) {
      const b = bs.list.find(x => x.name === Q.bg);
      bgOk = !b || b.booked.length < b.qty;
      bgTxt = bgOk ? 'A(z) „' + Q.bg + '” háttér szabad.' : 'A(z) „' + Q.bg + '” háttér erre a napra már foglalt.';
    }
    const avail = okS && okP;
    const q = quote();
    // közeli szabad napok
    let alt = [];
    if (!avail) {
      const c = S.cache[m];
      const base = parseYmd(date);
      for (let k = 1; k <= 4 && alt.length < 4; k++) {
        [-k, k].forEach(dd => {
          const d = new Date(base); d.setDate(d.getDate() + dd);
          const ds = ymd(d);
          if (monthKey(ds) !== m || ds < today()) return;
          const J = dayInfo(m, ds);
          if ((!needS || J.freeS > 0) && (!needP || J.freeP > 0)) alt.push(ds);
        });
      }
      alt = alt.slice(0, 4);
    }
    out.innerHTML =
      '<h3>' + esc(huDate(date)) + '</h3>' +
      '<div class="cpx-cap">' + (needS ? capBox('Szelfigép', I.selfie, I.capS, I.pSelfie) : '') + (needP ? capBox('360 Spinner', I.spin, I.capP, I.pSpin) : '') + '</div>' +
      '<div class="verdict ' + (avail ? 'ok' : 'no') + '">' + (avail ? '✔ Elvállalható' : '✖ Erre a napra nincs szabad ' + (!okS ? 'szelfigép' : '360 Spinner')) + (bgTxt ? '<small>' + esc(bgTxt) + '</small>' : '') + '</div>' +
      (alt.length ? '<p class="cpx-muted">Közeli szabad napok: ' + alt.map(huDate).map(esc).join(', ') + '</p>' : '') +
      '<table class="cpx-price">' + q.lines.map(l => '<tr><td>' + esc(l[0]) + '</td><td>' + (l[1] < 0 ? '−' + ft(-l[1]) : ft(l[1])) + '</td></tr>').join('') +
      '<tr class="tot"><td>Becsült végösszeg' + (Q.loc ? '' : ' (kiszállás nélkül)') + '</td><td>' + ft(q.tot) + '</td></tr></table>' +
      (q.custom ? '<p class="cpx-muted">1000 fő felett egyedi ajánlat szükséges.</p>' : '') +
      '<h4>Válaszlevél</h4><textarea id="q-mail" rows="14"></textarea><div class="row2"><button class="cpx-btn" id="q-copy">Másolás</button><span class="cpx-muted" id="q-copied"></span></div>';
    out.querySelector('#q-mail').value = mailText(date, avail, q, alt, bgOk, okS, okP);
    out.querySelector('#q-copy').addEventListener('click', async () => {
      const t = out.querySelector('#q-mail');
      try { await navigator.clipboard.writeText(t.value); } catch (e) { t.select(); document.execCommand('copy'); }
      out.querySelector('#q-copied').textContent = 'Vágólapra másolva';
    });
  }

  function onDay(s) {
    const d = parseYmd(s), n = d.getDate();
    const suf = n % 10 === 0 ? (n === 10 ? 'én' : 'án') : ({ 1: 'jén', 2: 'án', 3: 'án', 4: 'én', 5: 'én', 6: 'án', 7: 'én', 8: 'án', 9: 'én' })[n % 10];
    return d.getFullYear() + '. ' + HU_MONTHS[d.getMonth()] + ' ' + n + '-' + suf;
  }
  function mailText(date, avail, q, alt, bgOk, okS, okP) {
    const hi = 'Kedves ' + (Q.name.trim() || '…') + '!';
    const svc = Q.kind === 'spin' ? '360 Spinnert' : Q.kind === 'both' ? 'szelfigépet és 360 Spinnert' : 'szelfigépet';
    let t = hi + '\n\nKöszönjük megkeresésedet!\n\n';
    if (avail) {
      t += 'Örömmel jelezzük, hogy ' + onDay(date) + ' még tudunk ' + svc + ' biztosítani.\n\n';
      t += 'Az árajánlatunk:\n' + q.lines.map(l => '• ' + l[0] + ': ' + (l[1] < 0 ? '−' + ft(-l[1]) : ft(l[1]))).join('\n') + '\n';
      t += 'Összesen: ' + ft(q.tot) + (Q.loc ? '' : ' + kiszállási díj') + '\n\n';
      if (Q.bg) t += bgOk ? 'A választott „' + Q.bg + '” háttér erre a napra még szabad.\n\n' : 'A választott „' + Q.bg + '” háttér erre a napra sajnos már foglalt – szívesen segítünk másikat választani: https://crazyphoto.hu/valaszthato-hatterek/\n\n';
      t += 'A foglaláshoz kérjük, válaszolj erre a levélre, és elküldjük a szerződést. A foglalás a szerződés visszaküldésével válik véglegessé' + (parseYmd(date).getMonth() === 11 ? ' – decemberben a helyeket a visszaküldés sorrendjében tudjuk garantálni' : '') + '.\n\n';
    } else {
      const full = !okS && !okP ? 'minden szelfigépünk és 360 Spinnerünk' : !okS ? 'minden szelfigépünk' : 'minden 360 Spinnerünk';
      t += 'Sajnos ' + onDay(date) + ' ' + full + ' foglalt.\n\n';
      if (Q.kind === 'both' && (okS || okP)) t += 'Erre a napra ' + (okS ? 'szelfigépet' : '360 Spinnert') + ' még tudunk vállalni, ha az önmagában is megfelel.\n\n';
      if (alt.length) t += 'Ezekre a közeli napokra még tudunk vállalni: ' + alt.map(x => huDate(x).replace(/ \(.*\)$/, '')).join(', ') + '\n\n';
      t += 'Ha a dátum rugalmas, vagy szeretnéd, hogy szóljunk, ha felszabadul hely, írj nekünk bátran!\n\n';
    }
    t += C.sign || '';
    return t;
  }

  /* ------------------------------------------------------------ munkalap */
  function viewSheet(main) {
    const m = monthKey(S.day);
    const c = S.cache[m];
    const evs = c.events.filter(e => e.date === S.day);
    const I = dayInfo(m, S.day);
    main.innerHTML = '';
    const head = el('div', { class: 'cpx-head noprint' });
    head.innerHTML = '<div class="cpx-mnav"><button class="cpx-btn ghost" data-dd="-1">‹</button><input type="date" id="w-date" value="' + S.day + '"><button class="cpx-btn ghost" data-dd="1">›</button></div>' +
      '<div class="row2"><label class="chk"><input type="checkbox" id="w-pend"' + (S.showPend ? ' checked' : '') + '> függők is</label><button class="cpx-btn" id="w-print">Nyomtatás</button></div>';
    main.appendChild(head);
    const shift = async dd => { const d = parseYmd(S.day); d.setDate(d.getDate() + dd); S.day = ymd(d); await render(); };
    head.querySelectorAll('[data-dd]').forEach(b => b.addEventListener('click', () => shift(+b.dataset.dd)));
    head.querySelector('#w-date').addEventListener('change', e => { S.day = e.target.value || today(); render(); });
    head.querySelector('#w-pend').addEventListener('change', e => { S.showPend = e.target.checked; render(); });
    head.querySelector('#w-print').addEventListener('click', () => window.print());
    const list = evs.filter(e => S.showPend || e.state === 'booked');
    const sheet = el('div', { class: 'cpx-worksheet' });
    sheet.innerHTML = '<h2>Munkalap – ' + esc(huDate(S.day)) + '</h2>' +
      '<p class="cpx-muted">' + I.selfie + ' szelfigép, ' + I.spin + ' 360 Spinner foglalva' + (I.subs ? ' · ' + I.subs + ' alvállalkozói slot' : '') + '</p>' +
      (list.length ? list.map(card).join('') : '<div class="cpx-empty">Erre a napra nincs ' + (S.showPend ? '' : 'foglalt ') + 'rendezvény.</div>');
    main.appendChild(sheet);
  }

  function card(e) {
    const maps = e.loc ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(e.loc) : '';
    const tel = e.contact && e.contact.phone ? e.contact.phone.replace(/[^\d+]/g, '') : '';
    const rows = [
      ['Időpont', e.time],
      ['Szolgáltatás', kindLabel(e)],
      ['Szelfi csomag', e.selfie],
      ['360 csomag', e.spin],
      ['Létszám', e.guests ? e.guests + ' fő' : ''],
      ['Háttér', e.bg],
      ['Helyszín', e.loc ? esc(e.loc) + (maps ? ' <a href="' + maps + '" target="_blank" rel="noopener">térkép ↗</a>' : '') : '', true],
      ['Kapcsolattartó', e.contact ? [e.contact.name, tel ? '<a href="tel:' + esc(tel) + '">' + esc(e.contact.phone) + '</a>' : '', e.contact.email].filter(Boolean).map((x, i) => i === 1 ? x : esc(x)).join(' · ') : '', true]
    ].concat(PORTAL ? window.CPPortal.info(S.portal[e.id]) : []).concat((e.extra || []).map(x => [x[0], x[1]]));
    return '<article class="wcard ' + e.state + '"><header><b>' + esc(e.title || ('Deal #' + e.id)) + '</b>' + stateBadge(e) +
      '<a class="smlink noprint" href="' + esc(C.sm + e.id + '/detail') + '" target="_blank" rel="noopener">Salesmate ↗</a></header>' +
      (e.state === 'booked' ? prepLine(e) : '') +
      (trk(e).note ? '<p class="note">📝 ' + esc(trk(e).note) + '</p>' : '') +
      (PORTAL ? window.CPPortal.flags(S.portal[e.id], 'munkalap') : '') +
      '<dl>' + rows.filter(r => r[1]).map(r => '<dt>' + esc(r[0]) + '</dt><dd>' + (r[2] ? r[1] : esc(r[1])) + '</dd>').join('') + '</dl>' +
      (e.note ? '<p class="note">' + esc(e.note) + '</p>' : '') + '</article>';
  }

  /* ------------------------------------------------------------ kezelői elszámolás (v1.4) */
const ELC = C.el || {};
const EL = { cache: {}, kez: [], kf: '', q: '', only: '', init: false, geoBusy: false, geoTried: {}, pollT: null, draw: null };
const HX8 = [['', '–'], ['0', 'Nem volt']].concat(Array.from({ length: 16 }, (_, i) => { const v = String((i + 1) / 2); return [v, '+' + v.replace('.', ',') + ' ó']; }));
const ROLE_L = { kezelo: 'Kezelő', vezeto: 'Kezelővezető', host: 'Host/ess' };
const SAT = (ELC.sat && ELC.sat.length) ? ELC.sat : ['Elégedett', 'Foglalkozós', 'Elégedetlen'];
const PAYERS = ELC.payers || [];
const DEVS = (ELC.devices && ELC.devices.length) ? ELC.devices : ['Selfie1', 'Selfie2', 'Selfie3', 'Selfie4', 'Selfie5', 'SelfieStand', 'SelfieTükör', 'Spinner1', 'Spinner2', 'VR', 'RaceSimulator', 'Catch It', 'CatchTheLight'];
const GMO = (ELC.gmOpts && ELC.gmOpts.length) ? ELC.gmOpts : ['Jó', 'Foglalkozós', 'HIBA'];
const gmBad = v => !!v && norm(v) !== norm(GMO[0]);
const elNum = v => { const x = parseFloat(String(v == null ? '' : v).replace(/\s/g, '').replace(',', '.')); return isFinite(x) ? x : 0; };
const elHas = v => v !== undefined && v !== null && v !== '';
const elFt = n => (n < 0 ? '−' : '') + ft(Math.abs(n));
const elKez = () => EL.kez || [];
const kezById = id => elKez().find(k => k.id === id) || null;
const kezBySm = lbl => { const n = norm(lbl); return n ? (elKez().find(k => norm(k.sm) === n) || elKez().find(k => norm(k.name) === n) || null) : null; };
const roleDef = r => (ELC.roles || {})[r] || (ELC.roles || {}).kezelo || { rate: 4500, pack: 4500, hxr: 9000, bp: 5000, km: 120, sup: 2500, min: 15000 };
const EL_LOCK_MSG = 'Ez a hónap le van zárva – módosításhoz előbb nyisd újra (Kezelőnként kártya).';
const EL_GAP = 120; // perc – ennyi szünet kell két rendezvény között ugyanannak a kezelőnek (építés + utazás)
const elMin = s => { const m = /^(\d{1,2})[:.](\d{2})$/.exec(String(s || '').trim()); return m ? (+m[1]) * 60 + (+m[2]) : null; };
const elHm = n => pad(Math.floor((n % 1440) / 60)) + ':' + pad(n % 60);
const elWinTxt = w => w ? elHm(w.s) + '–' + elHm(w.e) : 'időpont?';
/* a rendezvény időablaka percben: naptár (aktivitás, különben a bejegyzés ideje), ennek híján Salesmate kezdés + óraszám */
function elWin(d, cal, hours) {
	let s = null, e = null;
	if (cal) {
		const as = elMin(cal.as), ae = elMin(cal.ae), cs = elMin(cal.s), ce = elMin(cal.e);
		if (as != null && ae != null) { s = as; e = ae <= as ? ae + 1440 : ae; }
		else if (cs != null && ce != null) { s = cs; e = ce <= cs ? ce + 1440 : ce; }
	}
	if (s == null) {
		const ds = elMin(d.time);
		if (ds != null) { s = ds; e = hours > 0 ? ds + Math.round(hours * 60) : null; }
	}
	return s == null ? null : { s: s, e: e == null ? s + 60 : e, est: e == null };
}
/* a kezelő aznapi többi rendezvénye a sorhoz képest: t = 'x' ütközik, 'ok' elfér, '?' nincs időpont */
function elDayOthers(L, kid) {
	const list = ((EL.busy || {})[L.d.date] || {})[kid] || [];
	return list.filter(x => x.d.id !== L.d.id).map(x => {
		const a = L.win, b = x.win;
		if (!a || !b) return { L: x, t: '?' };
		return { L: x, t: Math.max(b.s - a.e, a.s - b.e) < EL_GAP ? 'x' : 'ok' };
	});
}
const elLocked = () => { const c = EL.cache[S.month]; return !!(c && c.lock); };
const satCls = s => { const i = SAT.findIndex(x => norm(x) === norm(s)); return i < 0 ? '' : i === 0 ? 'ok' : i === SAT.length - 1 ? 'bad' : 'mid'; };

async function elPost(data) {
	const fd = new FormData();
	fd.append('t', C.t);
	Object.keys(data).forEach(k => {
		const v = data[k];
		if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
		else if (v instanceof Blob) fd.append(k, v, 'foto.jpg');
		else fd.append(k, v == null ? '' : v);
	});
	const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
	let j;
	try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
	if (!j.success) throw new Error(j.data && j.data.msg === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : (j.data && j.data.msg) || 'Mentési hiba');
	return j.data;
}

/* v1.6.6: a setup fotók a szerveren gyűlnek; az utolsó feltöltés után 45 mp-cel (vagy az oldal elhagyásakor) EGY Pumble üzenetben mennek ki */
const EL_PQ_WAIT = 45000;
let elPqT = null, elPqKey = null;
function elPqFlush() {
	if (elPqT) { clearTimeout(elPqT); elPqT = null; }
	if (!elPqKey) return;
	const fd = new FormData();
	fd.append('action', 'cp_el_pflush'); fd.append('t', C.t); fd.append('id', elPqKey.id); fd.append('i', elPqKey.i);
	elPqKey = null;
	try { if (navigator.sendBeacon && navigator.sendBeacon(C.ajax, fd)) return; } catch (e) { /* fetch-csel próbáljuk */ }
	fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(() => {});
}
function elPqTick() { if (document.hidden) { elPqT = setTimeout(elPqTick, 15000); return; } elPqFlush(); }
function elPqStart(id, i) {
	if (elPqT) { clearTimeout(elPqT); elPqT = null; }
	if (elPqKey && (elPqKey.id !== id || elPqKey.i !== i)) elPqFlush();
	elPqKey = { id: id, i: i };
}
function elPqLater() { if (!elPqKey) return; if (elPqT) clearTimeout(elPqT); elPqT = setTimeout(elPqTick, EL_PQ_WAIT); }
window.addEventListener('pagehide', elPqFlush);

async function elLoad(m, force) {
	const [from, to] = monthRange(m);
	S.loading = true; S.err = ''; renderStatus();
	try {
		const r = await fetch(C.ajax + '?action=cp_el_data&t=' + encodeURIComponent(C.t) + '&from=' + from + '&to=' + to + (force ? '&refresh=1' : ''), { credentials: 'same-origin' });
		let j;
		try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j.success) throw new Error(j.data && j.data.msg === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : (j.data && j.data.msg) || 'Hiba a lekérdezésben.');
		const d = j.data;
		d.store = d.store || {}; d.portal = d.portal || {}; d.hx = d.hx || {}; d.links = d.links || {}; d.geo = d.geo || {}; d.cal = d.cal || {}; d.geoc = d.geoc || {};
		EL.cache[m] = d;
		EL.kez = d.kez || EL.kez;
		return d;
	} catch (e) {
		S.err = e.message || String(e);
		throw e;
	} finally {
		S.loading = false; renderStatus();
	}
}

function elStatus(s) {
	if (S.loading) { s.textContent = 'Betöltés…'; s.className = 'cpx-status'; return; }
	if (S.err) { s.textContent = S.err; s.className = 'cpx-status err'; return; }
	const c = EL.cache[S.month];
	if (c && c.at) { const d = new Date(c.at * 1000); s.textContent = 'Adatok: ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); const cs = c.calSync && c.calSync.t ? new Date(c.calSync.t * 1000) : null; if (cs) s.textContent += ' · Naptár: ' + (cs.toDateString() === new Date().toDateString() ? '' : pad(cs.getMonth() + 1) + '.' + pad(cs.getDate()) + '. ') + pad(cs.getHours()) + ':' + pad(cs.getMinutes()); s.className = 'cpx-status'; }
	else s.textContent = '';
}

/* ---- számítás */
function elGeoOf(c, d, st, cal) {
	if (elHas(st.km)) { const km = elNum(st.km); return km > 0 ? { n: 'kézi km', km: km, b: 0, manual: 1 } : { n: 'Budapest (kézi)', km: 0, b: 1, manual: 1 }; }
	if (cal && cal.gl) return (c.geoc || {})[d.id] || null;
	if (!d.loc) return { none: 1 };
	return c.geo[d.id] || null;
}

function elRowsOf(c, d) {
	const st = c.store[d.id] || {};
	const rows = (Array.isArray(st.r) ? st.r : []).slice();
	if (!rows.find(r => r.i === 'r0')) rows.unshift({ i: 'r0' });
	rows.sort((a, b) => (a.i === 'r0' ? -1 : b.i === 'r0' ? 1 : 0));
	return rows;
}

function elR0Kez(d, row, cal) {
	if (cal) {
		if (row.ko) return { id: row.ko, unk: '', src: 'kézi' };
		if (row.k) return { id: row.k, unk: '', src: row.im ? '' : 'kézi' };
		if (cal.k0) return { id: cal.k0, unk: '', src: 'naptár' };
	}
	if (!d.manual && ELC.gkWrite) {
		if (row.kf && norm(row.ksm) === norm(d.gk)) return { id: row.k || '', unk: '' };
		const m = kezBySm(d.gk);
		if (m) return { id: m.id, unk: '' };
		if (d.gk) return { id: '', unk: d.gk };
		return { id: row.k || '', unk: '' };
	}
	if (row.k) return { id: row.k, unk: '' };
	const m = kezBySm(d.gk);
	return m ? { id: m.id, unk: '' } : { id: '', unk: d.gk || '' };
}

function elLines(m) {
	const c = EL.cache[m];
	if (!c) return [];
	const out = [];
	const tdy = today();
	c.deals.forEach(d => {
		const st = c.store[d.id] || {};
		const rows = elRowsOf(c, d);
		const cal = (c.cal || {})[d.id] || null;
		const useCal = !!cal && d.date < tdy;
		const hx = d.manual ? (st.hx || '') : (c.hx[d.id] || '');
		const geo = elGeoOf(c, d, st, useCal ? cal : null);
		const r0 = rows[0];
		const smh = d.hours == null ? '' : String(d.hours);
		const cw = useCal ? elWin(d, cal, 0) : null;
		const calH = useCal ? (elHas(cal.h) ? String(cal.h) : (cw && !cw.est ? String(Math.round((cw.e - cw.s) / 30) / 2) : '')) : '';
		const r0p = (elHas(r0.h) && String(r0.hsm == null ? '' : r0.hsm) === smh) ? String(r0.h) : '';
		const r0h = useCal && elHas(r0.hc) ? String(r0.hc) : (r0p ? r0p : (calH ? calH : smh));
		rows.forEach((row, idx) => {
			const kk = row.i === 'r0' ? elR0Kez(d, row, useCal ? cal : null) : { id: row.k || '', unk: '', src: row.ca ? 'naptár' : '' };
			const kz = kezById(kk.id);
			const R = Object.assign({}, roleDef(kz ? kz.role : 'kezelo'), kz || {});
			const h = row.i === 'r0' ? r0h : (elHas(row.h) ? String(row.h) : r0h);
			const hOver = row.i === 'r0' ? (r0h !== smh) : elHas(row.h);
			const rhx = elHas(row.rhx) ? String(row.rhx) : hx;
			const hN = elNum(h), hxN = elNum(rhx);
			const pack = elHas(row.p) ? elNum(row.p) : elNum(R.pack);
			let munka = 0, minA = false;
			if (hN > 0) {
				munka = hN * elNum(R.rate) + pack + hxN * elNum(R.hxr);
				if (elNum(R.min) > 0 && munka < elNum(R.min)) { munka = elNum(R.min); minA = true; }
			}
			let kisz = 0, kiszTxt = '', kiszMiss = false, kiszTip = '';
			if (elHas(row.kx)) { kisz = elNum(row.kx); kiszTxt = 'kézi összeg'; kiszTip = 'Kézzel megadott kiszállási díj (csak ennél a kezelőnél)'; }
			else if (geo && geo.b) { kisz = elNum(R.bp); kiszTxt = geo.manual ? geo.n : 'Budapest'; kiszTip = 'Budapesti fix díj: ' + ft(elNum(R.bp)); }
			else if (geo && geo.km > 0) { kisz = geo.km * 2 * elNum(R.km) + elNum(R.sup); kiszTxt = geo.n + ' · ' + geo.km + ' km'; kiszTip = geo.km + ' km × 2 × ' + ft(elNum(R.km)) + (elNum(R.sup) ? ' + ' + ft(elNum(R.sup)) + ' raktárba jutási támogatás' : '') + ' = ' + ft(kisz); }
			else { kiszMiss = true; kiszTxt = !geo ? 'km számolás…' : geo.none ? 'nincs helyszín' : 'nem található – add meg a km-t'; }
			const pk = elNum(row.pk), jt = elNum(row.jt), corr = elNum(row.c);
			const tot = munka + kisz + pk + jt + corr;
			const past = d.date < tdy;
			const miss = [];
			if (!kk.id && !kk.unk) miss.push('kezelő');
			if (kk.unk) miss.push('kezelő nincs a listában');
			if (!hN) miss.push('óraszám');
			if (kiszMiss && geo && geo.none && !d.manual) miss.push('helyszín');
			else if (kiszMiss && geo && !geo.none) miss.push('km');
			if (past && !row.fu) miss.push('kezelői adatok');
			const locWarn = !d.manual && !elHas(st.km) && !elHas(row.kx) && !!geo && !!geo.b && d.kd != null && elNum(d.kd) > (elNum(ELC.kdBp) || 10000);
			if (locWarn) miss.push('helyszín?');
			out.push({ d, st, row, idx, n: rows.length, kid: kk.id, unk: kk.unk, kz, R, h, hOver, smh, hN, hx: rhx, dhx: hx, hxN, pack, munka, minA, geo, kisz, kiszTxt, kiszMiss, pk, jt, corr, tot, past, miss, paid: !!row.pd, ok: row.ok === '1' || row.ok === 1, kiszTip, locWarn, cal, useCal, calH, kSrc: kk.src || '', win: elWin(d, cal, hN + hxN), locShow: useCal && (cal.loc || cal.hl) ? (cal.loc || cal.hl) : d.loc, days: Math.round((parseYmd(d.date) - parseYmd(tdy)) / 864e5), tar: kz ? kz.name + ' tarifája' : 'Alap kezelői tarifa (nincs kezelő kiválasztva)' });
		});
	});
	return out;
}

const elKezName = L => L.kz ? L.kz.name : L.unk ? L.unk : '';
const elKeyOf = L => L.kz ? L.kz.id : L.unk ? '?' + norm(L.unk) : '';

/* ---- nézet */
function viewEl(main) {
	if (!EL.init) {
		EL.init = true;
		const cm = monthKey(today());
		if (S.month > cm) S.month = cm;
	}
	const m = S.month;
	if (!EL.cache[m]) {
		main.innerHTML = '<div class="cpx-empty">Elszámolás betöltése a Salesmate-ből…</div>';
		elLoad(m).then(() => { if (S.tab === 'elszamolas' && S.month === m) viewEl(main); })
			.catch(() => { if (S.tab !== 'elszamolas') return; main.innerHTML = '<div class="cpx-empty err">' + esc(S.err) + '<br><button class="cpx-btn" id="el-retry">Újra</button></div>'; main.querySelector('#el-retry').onclick = () => viewEl(main); });
		return;
	}
	main.innerHTML = '';
	const head = el('div', { class: 'cpx-head' });
	head.appendChild(monthNav(render));
	const tools = el('div', { class: 'cpx-trk-tools' });
	tools.innerHTML = '<select id="el-kf" class="el-sel" aria-label="Szűrés kezelőre"><option value="">Minden kezelő</option></select>' +
		'<div class="seg sm" id="el-only">' + [['', 'Mind'], ['miss', 'Hiányos'], ['open', 'Nem fizetett']].map(x => '<button data-v="' + x[0] + '" class="' + (EL.only === x[0] ? 'on' : '') + '">' + x[1] + '</button>').join('') + '</div>' +
		'<input type="search" id="el-q" placeholder="Keresés: ügyfél, helyszín…" value="' + esc(EL.q) + '">' +
		'<button class="cpx-btn ghost" id="el-kez">Kezelők</button>' +
		'<button class="cpx-btn ghost" id="el-man">+ Kézi tétel</button>' +
		'<button class="cpx-btn ghost" id="el-csv" title="Letöltés Excelhez (CSV)">Excel export</button>';
	head.appendChild(tools);
	main.appendChild(head);
	const twb = el('div', { id: 'el-tw' });
	main.appendChild(twb);
	twb.addEventListener('click', e => { if (e.target.closest('[data-tw-go]')) go('tarca'); });
	if (!TW.data) twLoad().then(twBadgeDraw, twBadgeDraw);
	twEnsureEl().then(twBadgeDraw);
	const kp = el('div', { class: 'cpx-kpis', id: 'el-kpi' });
	main.appendChild(kp);
	main.appendChild(el('p', { class: 'cpx-help' }, 'Kezelői elszámolás: minden rendezvény a hónapban (Salesmate, napok szerint), soronként egy kezelő. A <b>fő kezelő</b> és az <b>egész órás óraszám</b> oda-vissza szinkronban van a Salesmate-tel (Gépkezelő asszisztens, Rendelkezésreállás hossza). Egy rendezvényhez a <b>+ kezelő</b> gombbal több kezelő is felvehető. A <b>hosszabbítás</b> ugyanaz, mint a Követés fülön. A kiszállás: Budapest fix díj, vidéken km × 2 × Ft/km + raktárba jutási támogatás. A <b>Link</b> gombbal másolható a kezelő adatlapja (parkolás, jatt, papír, elégedettség, megjegyzés, fotó) – amit ott kitölt, azonnal ide kerül. A sorra kattintva (✎) minden részlet szerkeszthető. A narancs <b>Ma</b> vonal alatti sorok még nem zajlottak le, ezért az összegük csak becsült. A <b>Kezelőnként</b> kártyán nyomtatható kimutatás készíthető, és ott zárható le a hónap. A <b>lezajlott</b> rendezvényeknél a kezelő, az óraszám, a helyszín és az időpont a <b>Google Naptárból</b> jön (📅), a jövőbelieknél a Salesmate-ből.'));
	const note = el('div', { id: 'el-note' });
	main.appendChild(note);
	const wrap = el('div', { class: 'el-wrap' });
	main.appendChild(wrap);
	const sum = el('div', { class: 'cpx-card el-sumcard', id: 'el-sum' });
	main.appendChild(sum);

	const kfSel = tools.querySelector('#el-kf');
	const fillKf = lines => {
		const seen = {};
		const opts = [];
		lines.forEach(L => { const k = elKeyOf(L); if (k && !seen[k]) { seen[k] = 1; opts.push([k, elKezName(L) + (L.unk ? ' (Salesmate)' : '')]); } });
		opts.sort((a, b) => a[1].localeCompare(b[1], 'hu'));
		if (EL.kf && !seen[EL.kf] && EL.kf !== '-') EL.kf = '';
		kfSel.innerHTML = '<option value="">Minden kezelő</option>' + opts.map(o => '<option value="' + esc(o[0]) + '"' + (EL.kf === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>').join('') + '<option value="-"' + (EL.kf === '-' ? ' selected' : '') + '>– nincs kezelő –</option>';
	};

	const draw = () => {
		const c = EL.cache[S.month];
		if (!c) return;
		const all = elLines(S.month);
		fillKf(all);
		const tdy = today();
		const locked = !!c.lock;
		const busy = {};
		all.forEach(L => { if (!L.kid) return; const b = busy[L.d.date] = busy[L.d.date] || {}; (b[L.kid] = b[L.kid] || []).push(L); });
		EL.busy = busy;
		const q = norm(EL.q);
		const vis = all.filter(L => (!EL.kf || (EL.kf === '-' ? !elKeyOf(L) : elKeyOf(L) === EL.kf)) &&
			(!EL.only || (EL.only === 'miss' ? L.miss.length : !L.paid)) &&
			(!q || norm(L.d.who + ' ' + L.d.title + ' ' + L.d.loc + ' ' + elKezName(L) + ' ' + (L.row.no || '')).includes(q)));
		const deals = new Set(all.map(L => L.d.id));
		const noKez = all.filter(L => !L.kid && !L.unk).length;
		const totAll = all.reduce((s, L) => s + L.tot, 0);
		const open = all.filter(L => !L.paid).reduce((s, L) => s + L.tot, 0);
		kp.innerHTML = kpi('Rendezvény a hónapban', deals.size) + kpi('Sor kezelő nélkül', noKez) + kpi('Kezelői bér összesen', ft(totAll)) + kpi('Még nem fizetett', ft(open));
		twBadgeDraw();
		let nh = '';
		if (locked) {
			const lt = c.lock.t ? new Date(c.lock.t * 1000) : null;
			nh += '<div class="el-lockbar">🔒 A hónap le van zárva' + (lt ? ' (' + esc(lt.toLocaleString('hu-HU', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })) + ')' : '') + ' – a tételek sem itt, sem a kezelői adatlapon nem módosíthatók.<button type="button" class="cpx-btn ghost sm" data-lock="0">Újranyitás</button></div>';
		}
		const soonL = all.filter(L => !L.past && !L.kid && !L.unk && L.days <= 7);
		if (soonL.length) {
			const s3 = new Set(soonL.filter(L => L.days <= 3).map(L => L.d.id)).size;
			const s7 = new Set(soonL.filter(L => L.days > 3).map(L => L.d.id)).size;
			nh += '<div class="el-alert">⚠ Nincs kezelő: ' + [s3 ? s3 + ' rendezvénynél 3 napon belül' : '', s7 ? s7 + ' rendezvénynél egy héten belül' : ''].filter(Boolean).join(', ') + '.' + (EL.kf !== '-' ? '<button type="button" class="cpx-btn ghost sm" data-soon>Kezelő nélküliek mutatása</button>' : '') + '</div>';
		}
		const csy = c.calSync && c.calSync.t ? c.calSync : null;
		const csAge = csy ? Date.now() / 1000 - csy.t : null;
		if (!csy || csAge > 3 * 3600) nh += '<div class="el-lockbar">📅 ' + (!csy ? 'A naptár-szinkron még nem futott' : 'A naptár-szinkron ' + Math.floor(csAge / 3600) + ' órája nem futott') + ' – a lezajlott rendezvények addig a Salesmate adataival számolódnak. (Google Apps Script: linkekFrissitese, óránként fut.)</div>';
		const locL = new Set(all.filter(L => L.locWarn).map(L => L.d.id));
		if (locL.size) nh += '<div class="el-alert">⚠ Helyszín ellenőrzése: ' + locL.size + ' rendezvénynél a Salesmate kiszállási díja vidéki helyszínre utal, de a helyszín mező szerint Budapest.' + (EL.only !== 'miss' ? '<button type="button" class="cpx-btn ghost sm" data-miss>Hiányosak mutatása</button>' : '') + '</div>';
		note.innerHTML = nh;
		let h = '<table class="el-t"><thead><tr><th class="c-d">Dátum</th><th class="c-n">Ügyfél</th><th>Kezelő</th><th>Óra</th><th>Hossz.</th><th class="r">Munkadíj</th><th class="r">Kiszállás</th><th>Adatlap</th><th class="r">Összesen</th><th>Ell.</th><th>Fizetve</th></tr></thead><tbody>';
		let lastD = '', g = 1;
		const td0 = parseYmd(tdy);
		const todayRow = '<tr class="el-today"><td colspan="11"><span>▼ Ma, ' + pad(td0.getMonth() + 1) + '.' + pad(td0.getDate()) + '. (' + HU_DAYS_LONG[td0.getDay()] + ') – innentől a hátralévő rendezvények, becsült összegekkel</span></td></tr>';
		let line = S.month !== monthKey(tdy);
		vis.forEach(L => {
			if (!line && L.d.date >= tdy) { h += todayRow; line = true; }
			if (L.d.date !== lastD) { g = 1 - g; lastD = L.d.date; }
			h += elRowHtml(L, g, locked);
		});
		if (!vis.length) h += '<tr><td colspan="11" class="cpx-empty">' + (all.length ? 'Nincs a szűrésnek megfelelő tétel.' : 'Ebben a hónapban még nincs rendezvény.') + '</td></tr>';
		else h += '<tr class="el-foot"><td colspan="5">Összesen (' + vis.length + ' sor)</td><td class="r">' + ft(vis.reduce((s, L) => s + L.munka, 0)) + '</td><td class="r">' + ft(vis.reduce((s, L) => s + L.kisz, 0)) + '</td><td>P/J ' + ft(vis.reduce((s, L) => s + L.pk + L.jt, 0)) + ' · 📄 ' + vis.reduce((s, L) => s + elNum(L.row.pa), 0) + '</td><td class="r"><b>' + ft(vis.reduce((s, L) => s + L.tot, 0)) + '</b></td><td colspan="2"></td></tr>';
		h += '</tbody></table>';
		const sl = wrap.scrollLeft, stp = wrap.scrollTop;
		wrap.innerHTML = h;
		wrap.scrollLeft = sl; wrap.scrollTop = stp;
		wrap.classList.toggle('locked', locked);
		sum.innerHTML = elSummaryHtml(all);
		elGeoQueue();
	};
	EL.draw = draw;
	EL.lines = () => elLines(S.month);

	const lineOf = tr => {
		if (!tr) return null;
		return elLines(S.month).find(L => L.d.id === tr.dataset.id && L.row.i === tr.dataset.i) || null;
	};
	wrap.addEventListener('change', ev => {
		const t = ev.target.closest('[data-ef]');
		const L = lineOf(ev.target.closest('tr[data-id]'));
		if (!t || !L) return;
		if (elLocked()) { toast(EL_LOCK_MSG); draw(); return; }
		const f = t.dataset.ef, v = t.value;
		if (f === 'k') {
			if (v === '__new') { t.value = L.kid; kezModal(null, nk => { if (nk) elSave(L, { k: nk.id }); }); return; }
			if (v === '__unk') return;
			if (L.useCal && L.row.i === 'r0') elSave(L, { k: v, ko: v === (L.cal.k0 || '') ? '' : v });
			else elSave(L, { k: v });
		} else if (f === 'h') {
			const x = v.trim().replace(',', '.');
			if (x !== '' && !(+x >= 0 && +x <= 24)) { alert('Az óraszám 0 és 24 közötti szám legyen.'); t.value = L.row.i === 'r0' ? L.h : (L.row.h || ''); return; }
			if (L.useCal && L.row.i === 'r0' && L.calH) elSave(L, { hc: x === L.calH ? '' : x });
			else elSave(L, { h: x });
		} else if (f === 'hx') {
			elSetHx(L.d, v);
		}
	});
	wrap.addEventListener('keydown', ev => { if (ev.key === 'Enter' && ev.target.matches('input[data-ef]')) ev.target.blur(); });
	wrap.addEventListener('click', ev => {
		const tr = ev.target.closest('tr[data-id]');
		const L = lineOf(tr);
		if (!L) return;
		const a = ev.target.closest('[data-act]');
		if (!a) {
			if (ev.target.closest('select,input,a,button')) return;
			if (ev.target.closest('td.el-click')) elDetail(L);
			return;
		}
		const act = a.dataset.act;
		if (act !== 'det' && act !== 'link' && elLocked()) { toast(EL_LOCK_MSG); return; }
		if (act === 'det') elDetail(L);
		else if (act === 'link') elCopyLink(L.d);
		else if (act === 'add') elAddRow(L.d);
		else if (act === 'del') { if (confirm('Törlöd ezt a kezelő-sort (' + (elKezName(L) || 'üres') + ')?')) elDelRow(L); }
		else if (act === 'ok') elSave(L, { ok: L.ok ? '' : '1' });
		else if (act === 'pay') { if (L.paid) { if (confirm('Visszavonod a kifizetést?\n' + (elKezName(L) || '') + ' – ' + L.d.who + ' (' + L.row.pd + ', ' + (L.row.pb || '') + ')')) elPay([L], true); } else payModal([L], elKezName(L)); }
		else if (act === 'mdel') elManualDel(L.d);
		else if (act === 'medit') manualModal(L.d);
	});
	sum.addEventListener('click', ev => {
		const lk = ev.target.closest('[data-lock]');
		if (lk) { elLock(lk.dataset.lock === '0'); return; }
		const sm = ev.target.closest('[data-stm]');
		if (sm) { elStatement(sm.dataset.stm); return; }
		const b = ev.target.closest('[data-pay]');
		if (!b) return;
		const key = b.dataset.pay;
		const lines = elLines(S.month).filter(L => elKeyOf(L) === key && !L.paid && L.tot !== 0);
		if (!lines.length) { toast('Nincs nyitott tétel.'); return; }
		payModal(lines, elKezName(lines[0]));
	});
	note.addEventListener('click', ev => {
		if (ev.target.closest('[data-lock]')) { elLock(true); return; }
		if (ev.target.closest('[data-miss]')) {
			EL.only = 'miss';
			tools.querySelectorAll('#el-only button').forEach(x => x.classList.toggle('on', x.dataset.v === 'miss'));
			draw();
			return;
		}
		if (ev.target.closest('[data-soon]')) {
			EL.kf = '-'; EL.only = '';
			tools.querySelectorAll('#el-only button').forEach(x => x.classList.toggle('on', x.dataset.v === ''));
			draw();
		}
	});
	kfSel.addEventListener('change', e => { EL.kf = e.target.value; draw(); });
	tools.querySelectorAll('#el-only button').forEach(b => b.addEventListener('click', () => { EL.only = b.dataset.v; tools.querySelectorAll('#el-only button').forEach(x => x.classList.toggle('on', x === b)); draw(); }));
	tools.querySelector('#el-q').addEventListener('input', e => { EL.q = e.target.value; draw(); });
	tools.querySelector('#el-kez').addEventListener('click', () => kezListModal());
	tools.querySelector('#el-man').addEventListener('click', () => manualModal(null));
	tools.querySelector('#el-csv').addEventListener('click', elCsv);
	draw();
	elPoll(true);
}

function elRowHtml(L, g, locked) {
	const d = L.d, row = L.row, first = L.idx === 0;
	const dt = parseYmd(d.date);
	const dn = pad(dt.getMonth() + 1) + '.' + pad(dt.getDate()) + '.';
	const noK = !L.kid && !L.unk;
	const soon = !L.past && noK && L.days <= 7 ? (L.days <= 3 ? 3 : 7) : 0;
	const st = L.paid ? 'paid' : L.miss.length ? (L.past || soon === 3 ? 'urgent' : 'todo') : 'done';
	const dis = locked ? ' disabled' : '';
	// kezelő választó – az aznap már beosztott kezelők szürkén, „foglalt” jelöléssel
	
	const act = elKez().filter(k => k.act !== 0 && k.act !== '0' || k.id === L.kid).slice().sort((a, b) => a.name.localeCompare(b.name, 'hu'));
	let ko = '<option value="">– válassz –</option>';
	if (L.unk) ko += '<option value="__unk" selected>? ' + esc(L.unk) + ' (Salesmate)</option>';
	ko += act.map(k => {
		const ot = k.id === L.kid ? [] : elDayOthers(L, k.id);
		const bad = ot.filter(o => o.t === 'x');
		const show = bad.length ? bad : ot;
		let bw = show.length ? (bad.length ? ' · ⚠ ütközik: ' : ' · aznap: ') + show.map(o => elWinTxt(o.L.win) + ' ' + o.L.d.who).join(', ') : '';
		if (bw.length > 52) bw = bw.slice(0, 50) + '…';
		return '<option value="' + esc(k.id) + '"' + (k.id === L.kid ? ' selected' : '') + (bad.length ? ' style="color:#9AA0AE"' : '') + '>' + esc(k.name + bw) + '</option>';
	}).join('');
	ko += '<option value="__new">+ Új kezelő…</option>';
	const warnK = L.unk ? ' title="A Salesmate-ben „' + esc(L.unk) + '” szerepel, ami nincs a panel kezelői között – vedd fel a Kezelők gombbal, vagy válassz mást."' : (row.kf && !row.im ? ' title="Csak a panelben mentve – a Salesmate listában nincs ilyen név."' : (row.im ? ' title="A régi táblázatból átvezetve (a Salesmate-be nem íródott vissza)"' : ''));
	const kSel = '<select class="tsel el-k ' + (L.kid ? 'ok' : '') + (L.unk || (row.kf && !row.im) ? ' warn' : '') + '" data-ef="k" aria-label="Kezelő"' + warnK + dis + '>' + ko + '</select>';
	// óra
	const hIn = '<input class="el-in' + (L.hOver ? ' over' : '') + (!L.hN ? ' bad' : '') + '" data-ef="h" inputmode="decimal" value="' + esc(L.row.i === 'r0' ? L.h : (row.h != null ? row.h : '')) + '" placeholder="' + esc(L.row.i === 'r0' ? '' : L.h) + '" aria-label="Óraszám"' + (L.row.i === 'r0' && (L.hOver || L.useCal) ? ' title="' + esc((L.useCal && L.calH ? 'Naptár: ' + L.calH + ' óra' + (elHas(L.row.hc) ? ' (kézzel felülírva)' : '') + '\n' : '') + 'Salesmate: ' + (L.smh || '–') + ' óra') + '"' : '') + dis + '>';
	// hosszabbítás (dealszintű)
	const hxSel = (first ? '<select class="tsel hx ' + (L.dhx ? 'set' : '') + '" data-ef="hx" aria-label="Hosszabbítás"' + dis + '>' + (C.hxSm && C.hxInt ? HX8.filter(o => !/\./.test(o[0])) : HX8).map(o => '<option value="' + o[0] + '"' + (String(L.dhx) === o[0] ? ' selected' : '') + '>' + o[1] + '</option>').join('') + '</select>' : (elHas(row.rhx) ? '' : '<span class="mu">' + (L.hxN ? '+' + String(L.hx).replace('.', ',') + ' ó' : '') + '</span>')) + (elHas(row.rhx) ? '<small class="mu" title="A kezelő adatlapja szerint">kezelő: ' + (L.hxN ? '+' + String(L.hx).replace('.', ',') + ' ó' : 'nem volt') + '</small>' : '');
	// képletek a tooltipben, a kezelő saját tarifájával
	const munkaTip = (L.hN ? (String(L.h).replace('.', ',') + ' ó × ' + ft(elNum(L.R.rate)) + ' + pakolás ' + ft(L.pack) + (L.hxN ? ' + hossz. ' + String(L.hx).replace('.', ',') + ' ó × ' + ft(elNum(L.R.hxr)) : '') + (L.minA ? ' → minimum ' + ft(elNum(L.R.min)) : '')) + ' = ' + ft(L.munka) : 'Hiányzik az óraszám') + '\n' + L.tar;
	const locTip = L.locWarn ? '⚠ A Salesmate kiszállási díja ' + ft(elNum(d.kd)) + ' – ez vidéki helyszínre utal, de a helyszín mező: „' + (d.loc || '') + '”. Javítsd a „Rendezvény helyszíne” mezőt a Salesmate-ben, vagy add meg a km-t (✎ → Km egy irányba).\n\n' : '';
	const kiszTip = locTip + (L.kiszMiss ? L.kiszTxt : L.kiszTxt + '\n' + L.kiszTip + '\n' + L.tar + ' (beállítás: Kezelők → ✎)');
	// adatlap: parkolás/jatt, papír, elégedettség, gép, megjegyzés, korrekció, fotók – egy oszlopban
	const ph = Array.isArray(row.ph) ? row.ph.length : 0;
	const pha = Array.isArray(row.pha) ? row.pha.length : 0;
	const phs = Array.isArray(row.phs) ? row.phs.length : 0;
	const pj = L.pk + L.jt;
	const chips = [], tip = [];
	if (pj) { chips.push('<span class="el-chip">P/J ' + ft(pj) + '</span>'); tip.push('Parkolás: ' + ft(L.pk) + ', jatt: ' + ft(L.jt)); }
	if (elHas(row.pa) || row.pt) { chips.push('<span class="el-chip">📄 ' + esc([row.pa, row.pt].filter(x => elHas(x)).join(' · ')) + '</span>'); tip.push('Papír: ' + (elHas(row.pa) ? row.pa + ' db' : '–') + (row.pt ? ' (' + row.pt + ')' : '')); }
	if (row.sa) { chips.push('<span class="el-sat ' + satCls(row.sa) + '">' + esc(row.sa) + '</span>'); tip.push('Elégedettség: ' + row.sa); }
	if (gmBad(row.gm)) { chips.push('<span class="el-chip ' + (norm(row.gm) === norm(GMO[GMO.length - 1]) ? 'bad' : 'mid') + '">Gép: ' + esc(row.gm) + '</span>'); tip.push('Gép: ' + row.gm + (row.gmn ? ' – ' + row.gmn : '')); }
	else if (row.gm) tip.push('Gép rendben');
	if (row.no) { chips.push('<span class="el-chip">💬 megj.</span>'); tip.push('Megjegyzés: ' + row.no); }
	if (L.corr) { chips.push('<span class="el-chip">korr. ' + elFt(L.corr) + '</span>'); tip.push('Korrekció: ' + elFt(L.corr) + (row.cn ? ' – ' + row.cn : '')); }
	if (ph || pha || phs) { chips.push('<span class="el-chip ph">📷 ' + (ph + pha + phs) + '</span>'); tip.push([pha ? pha + ' setup fotó' : '', phs ? phs + ' nyomtatási statisztika' : '', ph ? ph + ' parkolás fotó' : ''].filter(Boolean).join(', ')); }
	if (row.fu) tip.push('Kitöltve: ' + new Date(row.fu * 1000).toLocaleString('hu-HU'));
	const adat = chips.length ? '<div class="el-chips">' + chips.join('') + '</div>' : (L.past && !row.fu ? '<span class="bad">nincs kitöltve</span>' : '<span class="mu">–</span>');
	const sub = [L.useCal && L.win ? elWinTxt(L.win) : (L.win && !d.time ? elWinTxt(L.win) : d.time), d.svc].filter(Boolean).join(' · ');
	const locS = L.locShow || '';
	const locFromCal = L.useCal && locS !== (d.loc || '');
	let who;
	if (first) {
		who = '<div class="nm">' + (d.manual ? '<b>' + esc(d.who) + '</b><span class="el-tag">kézi</span>' : '<a href="' + esc(C.sm + d.id + '/detail') + '" target="_blank" rel="noopener" title="Megnyitás a Salesmate-ben">' + esc(d.who) + '</a>') + '</div>' +
			'<small>' + esc(sub) + '</small>' + (PORTAL && !d.manual ? window.CPPortal.flags(((EL.cache[S.month] || {}).portal || {})[d.id], 'elszamolas') : '') + (locS ? '<small class="el-loc" title="' + esc(locS + (locFromCal ? '\n(naptár szerint; Salesmate: ' + (d.loc || '–') + ')' : '')) + '">' + (locFromCal ? '📅 ' : '') + esc(locS.length > 46 ? locS.slice(0, 44) + '…' : locS) + '</small>' : '') +
			'<div class="el-acts"><button type="button" class="el-a" data-act="det" title="Részletek – minden adat szerkesztése">✎</button><button type="button" class="el-a" data-act="link" title="Kezelői adatlap linkjének másolása">Link ⧉</button>' + (locked ? '' : '<button type="button" class="el-a" data-act="add" title="Még egy kezelő ehhez a rendezvényhez">+ kezelő</button>' +
			(d.manual ? '<button type="button" class="el-a" data-act="medit">✎ tétel</button><button type="button" class="el-a" data-act="mdel">törlés</button>' : '')) + '</div>';
	} else {
		who = '<div class="nm el-sub">↳ ' + esc(d.who) + '</div><div class="el-acts"><button type="button" class="el-a" data-act="det" title="Részletek – minden adat szerkesztése">✎</button>' + (locked ? '' : '<button type="button" class="el-a" data-act="del" title="Kezelő-sor törlése">× sor törlése</button>') + '</div>';
	}
	const pmT = row.pm === 'keszpenz' ? 'készpénz' : row.pm === 'atutalas' ? 'átutalás' : '';
	const pdiff = L.paid && elHas(row.pv) && Math.round(L.tot) !== Math.round(elNum(row.pv));
	const totTip = 'Munkadíj ' + ft(L.munka) + ' + kiszállás ' + ft(L.kisz) + (pj ? ' + parkolás/jatt ' + ft(pj) : '') + (L.corr ? ' + korrekció ' + elFt(L.corr) : '') + ' = ' + ft(L.tot) + (L.past ? '' : '\n(becsült – a rendezvény még nem volt meg)') + (L.miss.length ? '\nHiányzik: ' + L.miss.join(', ') : '');
	const okBtn = (!L.past && !L.ok) ? '<span class="mu" title="A rendezvény még nem volt meg">–</span>' : '<button type="button" class="tk ' + (L.ok ? 'on' : '') + '" data-act="ok" aria-pressed="' + (L.ok ? 'true' : 'false') + '" title="' + (L.ok ? 'Ellenőrizve – a kezelő már nem módosíthatja' : 'Ellenőrizve?') + '"' + dis + '>' + (L.ok ? '✓' : '') + '</button>';
	const myOt = L.kid ? elDayOthers(L, L.kid) : [];
	const myBad = myOt.filter(o => o.t === 'x');
	const busyTxt = myBad.length ? '<small class="el-busy bad" title="Kevesebb mint ' + (EL_GAP / 60) + ' óra szünet a két rendezvény között">⚠ ütközik: ' + esc(myBad.map(o => elWinTxt(o.L.win) + ' ' + o.L.d.who).join(', ')) + '</small>' : (myOt.length ? '<small class="el-busy" title="Ugyanaz a kezelő aznap még">aznap még: ' + esc(myOt.map(o => elWinTxt(o.L.win) + ' ' + o.L.d.who).join(', ')) + '</small>' : '');
	const unkN = first && L.useCal && L.cal && Array.isArray(L.cal.g) ? L.cal.g.filter(x => !x.k).map(x => x.n) : [];
	const unkG = unkN.length ? '<small class="el-busy" title="A naptárban meghívott, de nincs a panel kezelői között – vedd fel a Kezelők gombbal (a Salesmate-névvel egyezően)">📅 meghívott: ' + esc(unkN.join(', ')) + '</small>' : '';
	const soonD = L.days === 0 ? 'ma' : L.days === 1 ? 'holnap' : L.days + ' nap múlva';
	const soonTxt = soon ? '<small class="el-soon s' + soon + '" title="Nincs kezelő – a rendezvény ' + soonD + ' lesz">' + (soon === 3 ? '⚠ ' : '') + soonD + '</small>' : '';
	return '<tr class="g' + g + ' st-' + st + (first ? '' : ' el-extra') + (L.past ? '' : ' fut') + (soon ? ' soon' + soon : '') + '" data-id="' + esc(d.id) + '" data-i="' + esc(row.i) + '">' +
		'<td class="td-d" data-l="Dátum">' + (first ? '<b>' + dn + '</b><small>' + HU_DAYS[(dt.getDay() + 6) % 7] + '</small>' : '') + '</td>' +
		'<td class="td-n" data-l="Ügyfél">' + who + '</td>' +
		'<td data-l="Kezelő">' + kSel + (L.kz ? '<small class="mu">' + esc(ROLE_L[L.kz.role] || '') + (L.kSrc === 'naptár' ? ' · 📅 naptár' : L.kSrc === 'kézi' ? ' · kézi' : '') + '</small>' : '') + soonTxt + busyTxt + unkG + (row.sv ? '<small class="el-sv">' + esc(row.sv) + '</small>' : '') + '</td>' +
		'<td data-l="Óra">' + hIn + '</td>' +
		'<td data-l="Hossz.">' + hxSel + '</td>' +
		'<td class="r el-click el-money" data-l="Munkadíj" title="' + esc(munkaTip) + '">' + (L.hN ? ft(L.munka) : '<span class="bad">óra?</span>') + (L.minA ? '<small class="mu">minimum</small>' : '') + '</td>' +
		'<td class="r el-click el-money" data-l="Kiszállás" title="' + esc(kiszTip) + '">' + (L.kiszMiss ? '<span class="' + (L.geo ? 'bad' : 'mu') + '">' + (L.geo ? 'km?' : '…') + '</span>' : ft(L.kisz)) + '<small class="mu">' + esc(L.kiszTxt.length > 26 ? L.kiszTxt.slice(0, 24) + '…' : L.kiszTxt) + '</small>' + (L.locWarn ? '<small class="el-locw">⚠ helyszín?</small>' : '') + '</td>' +
		'<td class="el-click el-ad" data-l="Adatlap"' + (tip.length ? ' title="' + esc(tip.join('\n')) + '"' : '') + '>' + adat + '</td>' +
		'<td class="r el-tot el-click el-money" data-l="Összesen" title="' + esc(totTip) + '"><b>' + ft(L.tot) + '</b>' + (L.past ? '' : '<small class="el-est">becsült</small>') + (L.miss.length && L.past ? '<small class="bad">hiányzik: ' + esc(L.miss.join(', ')) + '</small>' : '') + (pdiff ? '<small class="el-pdiff" title="A kifizetés óta megváltozott a számított összeg">kifizetve: ' + ft(elNum(row.pv)) + '</small>' : '') + '</td>' +
		'<td class="td-c" data-l="Ell.">' + okBtn + '</td>' +
		'<td class="td-c" data-l="Fizetve"><button type="button" class="tk pay ' + (L.paid ? 'on' : '') + '" data-act="pay" aria-pressed="' + (L.paid ? 'true' : 'false') + '" title="' + (L.paid ? 'Kifizetve: ' + esc(L.row.pd + ' · ' + (L.row.pb || '') + (pmT ? ' · ' + pmT : '')) : 'Kifizetés rögzítése') + '"' + dis + '>' + (L.paid ? '✓' : '') + '</button>' + (L.paid ? '<small class="mu">' + esc(String(L.row.pd).slice(5).replace('-', '.') + '. ' + (L.row.pb || '')) + '</small>' + (pmT ? '<small class="mu">' + pmT + '</small>' : '') : '') + '</td></tr>';
}

function elSummaryHtml(all) {
	const c = EL.cache[S.month] || {};
	const locked = !!c.lock;
	const g = {};
	all.forEach(L => {
		const k = elKeyOf(L) || '-';
		const x = g[k] = g[k] || { key: k, name: k === '-' ? '– nincs kezelő –' : elKezName(L) + (L.unk ? ' (Salesmate)' : ''), n: 0, h: 0, munka: 0, kisz: 0, pj: 0, tot: 0, paid: 0, open: 0, nOpen: 0, fut: 0 };
		x.n++; x.h += L.hN + L.hxN; x.munka += L.munka; x.kisz += L.kisz; x.pj += L.pk + L.jt + L.corr; x.tot += L.tot;
		if (L.paid) x.paid += L.tot; else { x.open += L.tot; x.nOpen++; if (!L.past) x.fut += L.tot; }
	});
	const list = Object.values(g).sort((a, b) => a.key === '-' ? 1 : b.key === '-' ? -1 : a.name.localeCompare(b.name, 'hu'));
	const [y, mo] = S.month.split('-').map(Number);
	const lockBtn = locked ? '<button type="button" class="cpx-btn ghost sm" data-lock="0">🔓 Hónap újranyitása</button>' : '<button type="button" class="cpx-btn ghost sm" data-lock="1" title="Lezárás után a hónap tételei nem módosíthatók – a kezelői adatlapon sem">🔒 Hónap lezárása</button>';
	const hd = '<div class="el-sumh"><h3>Kezelőnként – ' + y + '. ' + HU_MONTHS[mo - 1] + (locked ? ' · lezárva' : '') + '</h3>' + lockBtn + '</div>';
	if (!list.length) return hd + '<p class="cpx-muted">Nincs tétel.</p>';
	let h = hd + '<div class="el-sumwrap"><table class="el-sum"><thead><tr><th>Kezelő</th><th class="r">Tétel</th><th class="r">Óra</th><th class="r">Munkadíj</th><th class="r">Kiszállás</th><th class="r">Park./jatt/korr.</th><th class="r">Összesen</th><th class="r">Kifizetve</th><th class="r">Fizetendő</th><th></th></tr></thead><tbody>';
	list.forEach(x => {
		const btns = x.key === '-' ? '' : ((x.nOpen && !locked ? '<button type="button" class="cpx-btn sm" data-pay="' + esc(x.key) + '">Kifizetés…</button>' : x.n && !x.nOpen ? '<span class="okt">✓ rendezve</span>' : '') + '<button type="button" class="cpx-btn ghost sm" data-stm="' + esc(x.key) + '" title="Nyomtatható havi kimutatás – PDF-be is menthető">Kimutatás</button>');
		h += '<tr' + (x.open ? '' : ' class="done"') + '><td><b>' + esc(x.name) + '</b></td><td class="r">' + x.n + '</td><td class="r">' + String(Math.round(x.h * 100) / 100).replace('.', ',') + '</td><td class="r">' + ft(x.munka) + '</td><td class="r">' + ft(x.kisz) + '</td><td class="r">' + elFt(x.pj) + '</td><td class="r"><b>' + ft(x.tot) + '</b></td><td class="r">' + ft(x.paid) + '</td><td class="r"><b class="' + (x.open ? 'due' : '') + '">' + ft(x.open) + '</b>' + (x.fut ? '<small>ebből becsült: ' + ft(x.fut) + '</small>' : '') + '</td><td>' + btns + '</td></tr>';
	});
	const T = list.reduce((s, x) => { s.n += x.n; s.tot += x.tot; s.paid += x.paid; s.open += x.open; return s; }, { n: 0, tot: 0, paid: 0, open: 0 });
	h += '<tr class="el-foot"><td>Összesen</td><td class="r">' + T.n + '</td><td colspan="4"></td><td class="r"><b>' + ft(T.tot) + '</b></td><td class="r">' + ft(T.paid) + '</td><td class="r"><b>' + ft(T.open) + '</b></td><td></td></tr>';
	return h + '</tbody></table></div>';
}

/* ---- mentések */
function elApply(id, data) {
	Object.keys(EL.cache).forEach(m => {
		const c = EL.cache[m];
		if (!c.deals.find(x => x.id === id)) return;
		if (data.store !== undefined) c.store[id] = data.store || {};
		if (data.gk !== undefined) c.deals.forEach(x => { if (x.id === id) x.gk = data.gk; });
		if (data.hours !== undefined) c.deals.forEach(x => { if (x.id === id) x.hours = data.hours; });
	});
	if (data.kez) EL.kez = data.kez;
}

async function elSave(L, fields, quiet) {
	if (elLocked()) { toast(EL_LOCK_MSG); if (EL.draw) EL.draw(); return; }
	const d = L.d;
	const body = { action: 'cp_el_save', id: d.id, i: L.row.i, d: d.date, fields: JSON.stringify(fields) };
	if (fields.h !== undefined && L.row.i === 'r0') body.smv = d.hours == null ? '' : d.hours;
	const slow = (fields.k !== undefined || fields.h !== undefined) && L.row.i === 'r0' && !d.manual;
	if (slow) toast('Mentés a Salesmate-be…');
	try {
		const res = await elPost(body);
		elApply(d.id, res);
		if (res.warn) alert(res.warn);
		else if (!quiet) toast(slow ? 'Mentve a Salesmate-be is' : 'Mentve');
	} catch (e) {
		alert('Nem sikerült menteni: ' + e.message);
	}
	if (EL.draw && S.tab === 'elszamolas') EL.draw();
}

async function elSetHx(d, v) {
	if (elLocked()) { toast(EL_LOCK_MSG); if (EL.draw) EL.draw(); return; }
	try {
		if (d.manual) {
			const res = await elPost({ action: 'cp_el_save', id: d.id, i: 'r0', d: d.date, fields: JSON.stringify({ hx: v }) });
			elApply(d.id, res);
		} else {
			const fd = { action: 'cp_panel_track', id: d.id, f: 'hx', v: v, d: d.date };
			const res = await elPost(fd);
			S.track[d.id] = res.track || {};
			Object.keys(EL.cache).forEach(m => { const c = EL.cache[m]; if (v === '') delete c.hx[d.id]; else c.hx[d.id] = v; });
		}
		toast('Hosszabbítás mentve' + (v && v !== '0' ? ': +' + v.replace('.', ',') + ' óra' : ''));
	} catch (e) {
		alert('Nem sikerült menteni: ' + e.message);
	}
	if (EL.draw && S.tab === 'elszamolas') EL.draw();
}

async function elAddRow(d) {
	if (elLocked()) { toast(EL_LOCK_MSG); if (EL.draw) EL.draw(); return; }
	try {
		const res = await elPost({ action: 'cp_el_row', op: 'add', id: d.id, d: d.date });
		elApply(d.id, res);
		toast('Új kezelő-sor – válaszd ki a kezelőt');
	} catch (e) { alert('Nem sikerült: ' + e.message); }
	if (EL.draw) EL.draw();
}

async function elDelRow(L) {
	if (elLocked()) { toast(EL_LOCK_MSG); if (EL.draw) EL.draw(); return; }
	try {
		const res = await elPost({ action: 'cp_el_row', op: 'del', id: L.d.id, i: L.row.i, d: L.d.date });
		elApply(L.d.id, res);
		toast('Sor törölve');
	} catch (e) { alert('Nem sikerült: ' + e.message); }
	if (EL.draw) EL.draw();
}

async function elCopyLink(d) {
	const c = EL.cache[S.month];
	const url = c && c.links[d.id];
	if (!url) { alert('A link még nem érhető el – frissítsd az oldalt.'); return; }
	try { await navigator.clipboard.writeText(url); toast('Kezelői link a vágólapon – küldd el a kezelőnek'); }
	catch (e) { prompt('Másold ki a linket:', url); }
}

/* ---- km számítás a háttérben (a gyors ellenőrzés kalkulátorával azonos módszer, szerveren tárolva) */
async function elGeoQueue() {
	if (EL.geoBusy) return;
	const c = EL.cache[S.month];
	if (!c) return;
	const tdy = today();
	const calOf = d => { const cl = (c.cal || {})[d.id]; return cl && cl.gl && d.date < tdy ? cl : null; };
	let loc = '', key = '';
	const ct = c.deals.find(d => calOf(d) && !(c.geoc || {})[d.id] && !EL.geoTried['c' + d.id] && !elHas((c.store[d.id] || {}).km));
	if (ct) { loc = calOf(ct).gl; key = 'c' + ct.id; }
	else {
		const todo = c.deals.find(d => d.loc && !calOf(d) && !c.geo[d.id] && !EL.geoTried[d.id] && !elHas((c.store[d.id] || {}).km));
		if (!todo) return;
		loc = todo.loc; key = todo.id;
	}
	EL.geoBusy = true;
	EL.geoTried[key] = 1;
	let g;
	try { g = (await elPost({ action: 'cp_el_geo', loc: loc })).geo; } catch (e) { g = { err: 1 }; }
	Object.keys(EL.cache).forEach(m => {
		const cm = EL.cache[m];
		cm.geoc = cm.geoc || {};
		cm.deals.forEach(x => {
			if (x.loc === loc) cm.geo[x.id] = g;
			const cl = (cm.cal || {})[x.id];
			if (cl && cl.gl === loc) cm.geoc[x.id] = g;
		});
	});
	EL.geoBusy = false;
	if (EL.draw && S.tab === 'elszamolas') { clearTimeout(EL.geoT); EL.geoT = setTimeout(elSafeDraw, 150); }
}

/* újrarajzolás, de nem egy éppen nyitott legördülő / beviteli mező alatt */
function elSafeDraw() {
	const ae = document.activeElement;
	if (ae && ae.closest && ae.closest('.el-wrap') && /^(SELECT|INPUT)$/.test(ae.tagName)) { clearTimeout(EL.geoT); EL.geoT = setTimeout(elSafeDraw, 1500); return; }
	if (EL.draw && S.tab === 'elszamolas') EL.draw();
}

function elPoll(on) {
	if (EL.pollT) { clearInterval(EL.pollT); EL.pollT = null; }
	if (!on) return;
	EL.pollT = setInterval(async () => {
		if (document.hidden || S.tab !== 'elszamolas' || S.loading) return;
		const ae = document.activeElement;
		if (ae && /^(SELECT|INPUT|TEXTAREA)$/.test(ae.tagName) || !document.getElementById('cpx-modal').hidden) return;
		try { await elLoad(S.month); if (EL.draw && S.tab === 'elszamolas') EL.draw(); } catch (e) { /* állapotsorban látszik */ }
	}, 30000);
}

/* ---- részletek */
function elDetail(L) {
	const d = L.d, row = L.row;
	const c = EL.cache[S.month];
	const st = c.store[d.id] || {};
	const link = c.links[d.id] || '';
	const thumbs = (list, cat) => (Array.isArray(list) && list.length) ? list.map(n => { const u = ELC.img + '&id=' + encodeURIComponent(d.id) + '&n=' + encodeURIComponent(n); return '<a class="el-th" href="' + esc(u) + '" target="_blank" rel="noopener"><img loading="lazy" src="' + esc(u) + '" alt="Fotó"><button type="button" data-phdel="' + esc(n) + '" data-cat="' + cat + '" title="Fotó törlése">×</button></a>'; }).join('') : '<span class="cpx-muted">Még nincs fotó.</span>';
	const svSel = String(row.sv || '').split(',').map(x => norm(x)).filter(Boolean);
	const R = L.R;
	const geoTxt = L.geo && !L.geo.none && !L.geo.err ? (L.geo.b ? 'Budapest' : (L.geo.n + ' · ' + L.geo.km + ' km (egy irányba)')) : (d.loc ? 'nem található' : 'nincs helyszín');
	const inp = (id, label, val, ph, extra) => '<label>' + label + '<input id="' + id + '" value="' + esc(val == null ? '' : val) + '" placeholder="' + esc(ph == null ? '' : ph) + '" inputmode="' + (extra || 'numeric') + '" autocomplete="off"></label>';
	const dlg = document.getElementById('cpx-modal');
	dlg.innerHTML = '<div class="cpx-sheet wide el-dlg"><div class="cpx-sheet-h"><h3>' + esc(d.who) + (elKezName(L) ? ' – ' + esc(elKezName(L)) : '') + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>' +
		'<p class="cpx-muted">' + esc(huDate(d.date)) + (d.time ? ' · ' + esc(d.time) : '') + (d.svc ? ' · ' + esc(d.svc) : '') + (d.loc ? '<br>' + esc(d.loc) : '') + '</p>' +
		(L.cal ? '<p class="el-calinfo">📅 <b>Naptár:</b> ' + esc(elWinTxt(elWin(d, L.cal, 0))) + (L.cal.loc || L.cal.hl ? ' · ' + esc(L.cal.loc || L.cal.hl) : '') + (Array.isArray(L.cal.g) && L.cal.g.length ? ' · meghívott: ' + esc(L.cal.g.map(x => x.n + (x.k ? '' : ' (?)')).join(', ')) : ' · nincs meghívott') + (L.cal.h ? ' · rendelkezésre állás: ' + esc(String(L.cal.h).replace('.', ',')) + ' óra' : '') + '<br><small>' + (L.useCal ? 'Lezajlott rendezvény – a kezelő, az óraszám, a helyszín és az időpont a naptár szerint számít (a panelben kézzel felülírható).' : 'Jövőbeli rendezvény – a Salesmate adatai számítanak, a naptár időpontja az ütközés-ellenőrzéshez kell.') + '</small></p>' : '') + '<h4>Munkadíj</h4><div class="el-grid">' +
		inp('ed-h', 'Óraszám' + (row.i === 'r0' && !d.manual ? ' <small>(' + (L.useCal && L.calH ? 'naptár: ' + esc(L.calH) + ', ' : '') + 'Salesmate: ' + esc(L.smh || '–') + ')</small>' : ''), row.i === 'r0' ? L.h : (row.h != null ? row.h : ''), row.i === 'r0' ? '' : L.h, 'decimal') +
		inp('ed-p', 'Raktári pakolás (Ft)', row.p, String(elNum(R.pack))) +
		inp('ed-c', 'Korrekció (Ft, + / −)', row.c, '0', 'text') +
		'<label class="wide">Korrekció oka<input id="ed-cn" value="' + esc(row.cn || '') + '" maxlength="200" placeholder="pl. külön építési díj, 2 órabér"></label></div>' +
		'<h4>Kiszállás</h4><p class="cpx-muted">Helyszín: <b>' + esc(geoTxt) + '</b>' + (L.geo && L.geo.manual ? ' (kézzel megadva)' : '') + ' · ' + esc(ROLE_L[R.role] || 'Kezelő') + ': Budapest ' + ft(elNum(R.bp)) + ', vidék km × 2 × ' + ft(elNum(R.km)) + (elNum(R.sup) ? ' + ' + ft(elNum(R.sup)) + ' raktárba jutási támogatás' : '') + '</p>' + (L.locWarn ? '<p class="el-locwarn">⚠ A Salesmate kiszállási díja <b>' + ft(elNum(d.kd)) + '</b>, ami vidéki helyszínre utal, de a „Rendezvény helyszíne” mező: <b>' + esc(d.loc || '–') + '</b>. Javítsd a Salesmate-ben, vagy add meg lent a km-t.</p>' : (d.kd != null && !d.manual ? '<p class="cpx-muted">Salesmate kiszállási díj (ügyfélnek): ' + ft(elNum(d.kd)) + '</p>' : '')) + '<div class="el-grid">' +
		inp('ed-km', 'Km egy irányba (az egész rendezvényre; 0 = Budapest)', st.km, L.geo && L.geo.km ? String(L.geo.km) : '') +
		inp('ed-kx', 'Kiszállási díj kézzel (Ft, csak ennél a kezelőnél)', row.kx, String(L.kiszMiss ? '' : L.kisz)) +
		(d.loc && !(L.geo && L.geo.manual) ? '<div class="el-geo-re"><button type="button" class="cpx-btn ghost sm" id="ed-geo">Távolság újraszámolása</button></div>' : '') + '</div>' +
		'<h4>Kezelői adatok <small class="cpx-muted">' + (row.fu ? '– a kezelő kitöltötte: ' + esc(new Date(row.fu * 1000).toLocaleString('hu-HU')) : '– a kezelő még nem töltötte ki') + '</small></h4><div class="el-grid">' +
		inp('ed-pk', 'Parkolás (Ft)', row.pk, '0') + inp('ed-jt', 'Jatt (Ft)', row.jt, '0') + inp('ed-pa', 'Papírfogyasztás (db)', row.pa, '') +
		'<label>Nyomtató típusa<select id="ed-pt"><option value="">–</option>' + EL_PRN.map(s => '<option' + (norm(row.pt) === norm(s) ? ' selected' : '') + '>' + esc(s) + '</option>').join('') + '</select></label>' +
		'<label>Ügyfél elégedettség<select id="ed-sa"><option value="">–</option>' + SAT.map(s => '<option' + (norm(row.sa) === norm(s) ? ' selected' : '') + '>' + esc(s) + '</option>').join('') + '</select></label>' +
		'<label>Hosszabbítás a kezelő szerint<select id="ed-rhx">' + HX8.map(o => '<option value="' + o[0] + '"' + (String(row.rhx == null ? '' : row.rhx) === o[0] ? ' selected' : '') + '>' + (o[0] === '' ? '– (a rendezvényé)' : o[1]) + '</option>').join('') + '</select></label>' +
		'<label>Gép működése<select id="ed-gm"><option value="">–</option>' + GMO.map(s => '<option' + (norm(row.gm) === norm(s) ? ' selected' : '') + '>' + esc(s) + '</option>').join('') + '</select></label>' +
		'<label class="wide">Ha nem működött jól, miért?<input id="ed-gmn" value="' + esc(row.gmn || '') + '" maxlength="600"></label>' +
		'<div class="wide el-svs"><span>Szolgáltatás (eszköz)</span>' + DEVS.map(x => '<label class="chk"><input type="checkbox" data-sv value="' + esc(x) + '"' + (svSel.includes(norm(x)) ? ' checked' : '') + '> ' + esc(x) + '</label>').join('') + '</div>' +
		'<label class="wide">Megjegyzés<textarea id="ed-no" rows="3" maxlength="600">' + esc(row.no || '') + '</textarea></label></div>' +
		'<div class="el-phs"><b>Setup / aktivitás fotó(k)</b>' + (ELC.pumble ? ' <small class="cpx-muted">– a Pumble csatornába is bekerül (az egymás után feltöltöttek egy üzenetben, kb. 45 mp múlva)</small>' : '') + '<div class="el-ths" id="ed-thsa">' + thumbs(row.pha, 'a') + '</div><label class="cpx-btn ghost sm el-upl">+ Fotó feltöltése<input type="file" id="ed-filea" accept="image/*" multiple hidden></label> <small id="ed-upmsga" class="cpx-muted"></small></div>' +
		'<div class="el-phs"><b>Nyomtatási statisztika</b><div class="el-ths" id="ed-thss">' + thumbs(row.phs, 's') + '</div><label class="cpx-btn ghost sm el-upl">+ Fotó feltöltése<input type="file" id="ed-files" accept="image/*" multiple hidden></label> <small id="ed-upmsgs" class="cpx-muted"></small></div>' +
		'<div class="el-phs"><b>Parkolás fotó(k)</b><div class="el-ths" id="ed-ths">' + thumbs(row.ph, '') + '</div><label class="cpx-btn ghost sm el-upl">+ Fotó feltöltése<input type="file" id="ed-file" accept="image/*" multiple hidden></label> <small id="ed-upmsg" class="cpx-muted"></small></div>' +
		'<h4>Számítás</h4><table class="cpx-price">' +
		'<tr><td>Munkadíj' + (L.hN ? ' (' + String(L.h).replace('.', ',') + ' ó × ' + ft(elNum(R.rate)) + ' + pakolás ' + ft(L.pack) + (L.hxN ? ' + hosszabbítás ' + String(L.hx).replace('.', ',') + ' ó × ' + ft(elNum(R.hxr)) : '') + ')' : '') + (L.minA ? ' – minimum' : '') + '</td><td>' + ft(L.munka) + '</td></tr>' +
		'<tr><td>Kiszállás (' + esc(L.kiszTxt) + ')</td><td>' + ft(L.kisz) + '</td></tr>' +
		'<tr><td>Parkolás + jatt</td><td>' + ft(L.pk + L.jt) + '</td></tr>' +
		(L.corr ? '<tr><td>Korrekció' + (row.cn ? ' – ' + esc(row.cn) : '') + '</td><td>' + elFt(L.corr) + '</td></tr>' : '') +
		'<tr class="tot"><td>Összesen</td><td>' + ft(L.tot) + '</td></tr></table>' +
		elHistHtml(st, row) +
		'<h4>Kezelői adatlap linkje</h4><div class="row2 el-link"><input readonly value="' + esc(link) + '" id="ed-link"><button type="button" class="cpx-btn ghost sm" id="ed-copy">Másolás</button><a class="cpx-btn ghost sm" href="' + esc(link) + '" target="_blank" rel="noopener">Megnyitás</a></div>' +
		(elLocked() ? '<p class="el-lockmsg">🔒 A hónap le van zárva – módosításhoz előbb nyisd újra (Kezelőnként kártya).</p>' : '') + '<div class="cpx-sheet-f"><button class="cpx-btn" id="ed-save"' + (elLocked() ? ' disabled' : '') + '>Mentés</button><button class="cpx-btn ghost" data-close>Mégse</button></div></div>';
	dlg.hidden = false;
	const q = s => dlg.querySelector(s);
	q('#ed-copy').onclick = async () => { try { await navigator.clipboard.writeText(link); toast('Link a vágólapon'); } catch (e) { q('#ed-link').select(); document.execCommand('copy'); } };
	const geoBtn = q('#ed-geo');
	if (geoBtn) geoBtn.onclick = async () => {
		geoBtn.disabled = true; geoBtn.textContent = 'Számolás…';
		try {
			const res = await elPost({ action: 'cp_el_geo', loc: d.loc, retry: 1 });
			Object.keys(EL.cache).forEach(m => EL.cache[m].deals.forEach(x => { if (x.loc === d.loc) EL.cache[m].geo[x.id] = res.geo; }));
			if (EL.draw) EL.draw();
			const nl = EL.lines().find(x => x.d.id === d.id && x.row.i === row.i);
			if (nl) elDetail(nl);
		} catch (e) { alert(e.message); geoBtn.disabled = false; geoBtn.textContent = 'Távolság újraszámolása'; }
	};
	const refreshThumbs = res => {
		elApply(d.id, res);
		if (EL.draw) EL.draw();
		const nl = EL.lines().find(x => x.d.id === d.id && x.row.i === row.i);
		row.ph = nl && Array.isArray(nl.row.ph) ? nl.row.ph : [];
		row.pha = nl && Array.isArray(nl.row.pha) ? nl.row.pha : [];
		q('#ed-ths').innerHTML = thumbs(row.ph, '');
		row.phs = nl && Array.isArray(nl.row.phs) ? nl.row.phs : [];
		q('#ed-thsa').innerHTML = thumbs(row.pha, 'a');
		q('#ed-thss').innerHTML = thumbs(row.phs, 's');
	};
	['#ed-ths', '#ed-thsa', '#ed-thss'].forEach(sel => q(sel).addEventListener('click', async ev => {
		const b = ev.target.closest('[data-phdel]');
		if (!b) return;
		ev.preventDefault();
		if (!confirm('Törlöd ezt a fotót?')) return;
		try { refreshThumbs(await elPost({ action: 'cp_el_phdel', id: d.id, i: row.i, n: b.dataset.phdel, cat: b.dataset.cat || '' })); } catch (e) { alert(e.message); }
	}));
	[['#ed-file', '#ed-upmsg', ''], ['#ed-filea', '#ed-upmsga', 'a'], ['#ed-files', '#ed-upmsgs', 's']].forEach(([fsel, msel, cat]) => q(fsel).addEventListener('change', async ev => {
		const files = Array.from(ev.target.files || []);
		ev.target.value = '';
		const msg = q(msel);
		if (cat === 'a' && files.length) elPqStart(d.id, row.i);
		for (let n = 0; n < files.length; n++) {
			msg.textContent = 'Feltöltés… (' + (n + 1) + '/' + files.length + ')';
			try { refreshThumbs(await elPost({ action: 'cp_el_up', id: d.id, i: row.i, d: d.date, cat: cat, f: await elShrink(files[n]) })); }
			catch (e) { msg.textContent = 'Hiba: ' + e.message; if (cat === 'a') elPqLater(); return; }
		}
		if (cat === 'a' && files.length) elPqLater();
		msg.textContent = files.length ? 'Feltöltve.' : '';
	}));
	q('#ed-save').onclick = async () => {
		const v = s => q(s).value.trim();
		const f = {};
		const set = (k, val, cur) => { if (String(val) !== String(cur == null ? '' : cur)) f[k] = val; };
		if (L.useCal && row.i === 'r0' && L.calH) { const hx2 = v('#ed-h').replace(',', '.'); if (hx2 !== String(L.h)) f.hc = hx2 === L.calH ? '' : hx2; }
		else set('h', v('#ed-h').replace(',', '.'), row.i === 'r0' ? L.h : row.h);
		set('p', v('#ed-p'), row.p); set('c', v('#ed-c').replace('−', '-'), row.c); set('cn', v('#ed-cn'), row.cn);
		set('kx', v('#ed-kx'), row.kx); set('km', v('#ed-km'), st.km);
		set('pk', v('#ed-pk'), row.pk); set('jt', v('#ed-jt'), row.jt); set('pa', v('#ed-pa'), row.pa); set('pt', q('#ed-pt').value, row.pt);
		set('sa', q('#ed-sa').value, row.sa); set('no', v('#ed-no'), row.no);
		set('rhx', q('#ed-rhx').value, row.rhx); set('gm', q('#ed-gm').value, row.gm); set('gmn', v('#ed-gmn'), row.gmn);
		set('sv', Array.from(dlg.querySelectorAll('[data-sv]')).filter(x => x.checked).map(x => x.value).join(', '), row.sv);
		if (!Object.keys(f).length) { closeModal(); return; }
		q('#ed-save').disabled = true;
		closeModal();
		await elSave(L, f);
	};
}

function elShrink(file) {
	return new Promise(res => {
		if (!/^image\//.test(file.type)) { res(file); return; }
		const url = URL.createObjectURL(file), img = new Image();
		img.onload = () => {
			try {
				const s = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
				const cv = document.createElement('canvas');
				cv.width = Math.max(1, Math.round(img.naturalWidth * s)); cv.height = Math.max(1, Math.round(img.naturalHeight * s));
				cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
				URL.revokeObjectURL(url);
				cv.toBlob(b => res(b && b.size ? b : file), 'image/jpeg', 0.82);
			} catch (e) { URL.revokeObjectURL(url); res(file); }
		};
		img.onerror = () => { URL.revokeObjectURL(url); res(file); };
		img.src = url;
	});
}

/* ---- kifizetés */
function payModal(lines, name) {
	if (elLocked()) { toast(EL_LOCK_MSG); return; }
	const dlg = document.getElementById('cpx-modal');
	const opts = PAYERS.map(p => '<option>' + esc(p) + '</option>').join('') + '<option value="__other">Más…</option>';
	const one = lines.length === 1;
	dlg.innerHTML = '<div class="cpx-sheet narrow"><div class="cpx-sheet-h"><h3>Kifizetés – ' + esc(name || 'kezelő') + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>' +
		'<p class="cpx-muted">Jelöld ki, mely tételeket fizetted ki. A még meg nem tartott rendezvények alapból nincsenek kijelölve. A kifizetett tétel összege rögzítődik.</p>' +
		'<div class="el-paylist">' + lines.map((L, n) => '<label class="el-payrow"><input type="checkbox" data-n="' + n + '"' + (L.past || one ? ' checked' : '') + '><span>' + esc(L.d.date.slice(5).replace('-', '.') + '. ' + L.d.who) + (L.past ? '' : ' <small class="mu">(még nem volt – becsült)</small>') + (L.miss.length ? ' <small class="bad">(hiányzik: ' + esc(L.miss.join(', ')) + ')</small>' : '') + '</span><b>' + ft(L.tot) + '</b></label>').join('') + '</div>' +
		'<div class="el-grid"><label>Ki fizette<select id="py-who">' + opts + '</select></label><label>Fizetés módja<select id="py-m"><option value="atutalas">Átutalás</option><option value="keszpenz">Készpénz</option></select></label><label>Fizetés dátuma<input type="date" id="py-date" value="' + today() + '"></label><label id="py-ow" hidden>Név<input id="py-other" maxlength="60"></label></div>' +
		'<div class="el-paytot">Összesen: <b id="py-tot"></b></div>' +
		'<div class="cpx-sheet-f"><button class="cpx-btn" id="py-go">Kifizetve ✓</button><button class="cpx-btn ghost" data-close>Mégse</button></div></div>';
	dlg.hidden = false;
	const q = s => dlg.querySelector(s);
	const sel = () => Array.from(dlg.querySelectorAll('[data-n]')).filter(x => x.checked).map(x => lines[+x.dataset.n]);
	const upd = () => { q('#py-tot').textContent = ft(sel().reduce((s, L) => s + L.tot, 0)); };
	dlg.querySelectorAll('[data-n]').forEach(x => x.addEventListener('change', upd));
	q('#py-who').addEventListener('change', e => { q('#py-ow').hidden = e.target.value !== '__other'; });
	const twPm = () => { if (norm(q('#py-who').value) === norm((TW.data && TW.data.payer) || 'Ákos')) q('#py-m').value = 'keszpenz'; };
	q('#py-who').addEventListener('change', twPm);
	twPm();
	q('.el-paytot').insertAdjacentHTML('beforebegin', '<p class="cpx-muted tw-payh">Ha ' + esc((TW.data && TW.data.payer) || 'Ákos') + ' fizeti, és nem átutalással, az összeg magától levonódik a tárcájából (Tárca fül).</p>');
	upd();
	q('#py-go').onclick = async () => {
		const ls = sel();
		if (!ls.length) { alert('Nincs kijelölt tétel.'); return; }
		const who = q('#py-who').value === '__other' ? q('#py-other').value.trim() : q('#py-who').value;
		const date = q('#py-date').value;
		if (!who || !date) { alert('Add meg, ki és mikor fizette.'); return; }
		const meth = q('#py-m').value;
		closeModal();
		await elPay(ls, false, who, date, meth);
	};
}

async function elPay(lines, undo, payer, date, meth) {
	if (elLocked()) { toast(EL_LOCK_MSG); return; }
	try {
		const res = await elPost({ action: 'cp_el_pay', items: lines.map(L => L.d.id + '|' + L.row.i + '|' + Math.round(L.tot)), undo: undo ? '1' : '', payer: payer || '', pdate: date || '', pmeth: meth || '', ym: S.month });
		Object.keys(res.stores || {}).forEach(id => elApply(id, { store: res.stores[id] }));
		toast(undo ? 'Kifizetés visszavonva' : 'Kifizetve: ' + ft(lines.reduce((s, L) => s + L.tot, 0)));
		twLoad(true).then(twBadgeDraw, twBadgeDraw);
	} catch (e) { alert('Nem sikerült: ' + e.message); }
	if (EL.draw) EL.draw();
}

/* ---- hónap lezárása (v1.5) */
async function elLock(undo) {
	const c = EL.cache[S.month];
	if (!c) return;
	const m = S.month;
	const [y, mo] = m.split('-').map(Number);
	const label = y + '. ' + HU_MONTHS[mo - 1];
	const all = elLines(m);
	const tot = all.reduce((s, L) => s + L.tot, 0);
	if (undo) {
		if (!confirm('Újranyitod: ' + label + '?\nUtána a tételek a panelben és a kezelői adatlapokon is ismét módosíthatók.')) return;
	} else {
		const open = all.filter(L => !L.paid && L.tot !== 0);
		const fut = all.filter(L => !L.past);
		let msg = 'Lezárod: ' + label + '?\n\nLezárás után a hónap tételei nem módosíthatók – sem itt, sem a kezelői adatlapon –, amíg újra nem nyitod.';
		if (open.length) msg += '\n\nFigyelem: ' + open.length + ' tétel még nincs kifizetve (' + ft(open.reduce((s, L) => s + L.tot, 0)) + ').';
		if (fut.length) msg += '\n' + fut.length + ' rendezvény még nem volt meg.';
		if (!confirm(msg)) return;
	}
	try {
		const res = await elPost({ action: 'cp_el_lock', ym: m, undo: undo ? '1' : '', n: all.length, sum: Math.round(tot) });
		c.lock = res.lock || null;
		toast(undo ? 'Hónap újranyitva' : 'Hónap lezárva: ' + label);
	} catch (e) { alert('Nem sikerült: ' + e.message); }
	if (EL.draw && S.tab === 'elszamolas') EL.draw();
}

/* ---- havi kimutatás kezelőnként (nyomtatható, PDF-be menthető) */
function elStatement(key) {
	const lines = elLines(S.month).filter(L => elKeyOf(L) === key);
	if (!lines.length) { toast('Nincs tétel.'); return; }
	const name = elKezName(lines[0]);
	const [y, mo] = S.month.split('-').map(Number);
	const title = name + ' – kezelői elszámolás – ' + y + '. ' + HU_MONTHS[mo - 1];
	const R = lines[0].R;
	const hh = v => String(v).replace('.', ',');
	const pmT = r => r.pm === 'keszpenz' ? ', készpénz' : r.pm === 'atutalas' ? ', átutalás' : '';
	const sum = f => lines.reduce((s, L) => s + f(L), 0);
	const rows = lines.map(L => {
		const dt = parseYmd(L.d.date);
		return '<tr><td>' + pad(dt.getMonth() + 1) + '.' + pad(dt.getDate()) + '.<small>' + HU_DAYS[(dt.getDay() + 6) % 7] + '</small></td>' +
			'<td><b>' + esc(L.d.who) + '</b>' + (L.d.loc ? '<small>' + esc(L.d.loc) + '</small>' : '') + '</td>' +
			'<td class="r">' + (L.hN ? hh(L.h) + ' ó' : '–') + (L.hxN ? '<small>+ ' + hh(L.hx) + ' ó hossz.</small>' : '') + '</td>' +
			'<td class="r">' + ft(L.munka) + (L.minA ? '<small>minimum</small>' : '') + '</td>' +
			'<td class="r">' + ft(L.kisz) + '<small>' + esc(L.kiszTxt) + '</small></td>' +
			'<td class="r">' + (L.pk + L.jt ? ft(L.pk + L.jt) : '–') + '</td>' +
			'<td class="r">' + (L.corr ? elFt(L.corr) + (L.row.cn ? '<small>' + esc(L.row.cn) + '</small>' : '') : '–') + '</td>' +
			'<td class="r"><b>' + ft(L.tot) + '</b></td>' +
			'<td>' + (L.paid ? 'kifizetve<small>' + esc(String(L.row.pd).replace(/-/g, '.') + '.' + pmT(L.row)) + '</small>' : L.past ? 'fizetendő' : 'még nem volt<small>becsült</small>') + '</td></tr>';
	}).join('');
	const T = sum(L => L.tot), P = sum(L => L.paid ? L.tot : 0), O = sum(L => !L.paid && L.past ? L.tot : 0), F = sum(L => !L.paid && !L.past ? L.tot : 0);
	const css = 'body{font:14px/1.45 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#1A1F2B;margin:28px}h1{font-size:21px;margin:0 0 4px}p{margin:0 0 4px;color:#5B6275}table{width:100%;border-collapse:collapse;margin:18px 0}th{text-align:left;font-size:12px;color:#5B6275;border-bottom:2px solid #E7E2DA;padding:6px}td{border-bottom:1px solid #F0ECE6;padding:6px;vertical-align:top}td small{display:block;color:#5B6275;font-size:11.5px}.r{text-align:right;white-space:nowrap}.tot{display:flex;gap:28px;flex-wrap:wrap;margin-top:6px}.tot div{font-size:13px;color:#5B6275}.tot b{display:block;font-size:19px;color:#1A1F2B}.bar{margin-bottom:18px}.bar button{font:inherit;padding:8px 16px;border-radius:999px;border:0;background:#E8894A;color:#1A1F2B;font-weight:700;cursor:pointer}@media print{.bar{display:none}body{margin:12mm}}';
	const html = '<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>' + esc(title) + '</title><style>' + css + '</style></head><body>' +
		'<div class="bar"><button onclick="window.print()">Nyomtatás / mentés PDF-be</button></div>' +
		'<h1>' + esc(name) + ' – kezelői elszámolás</h1><p>' + y + '. ' + HU_MONTHS[mo - 1] + ' · ' + lines.length + ' tétel · készült: ' + esc(new Date().toLocaleString('hu-HU')) + '</p>' +
		'<p>Tarifa' + (ROLE_L[R.role] ? ' (' + esc(ROLE_L[R.role]) + ')' : '') + ': óradíj ' + ft(elNum(R.rate)) + ', pakolás ' + ft(elNum(R.pack)) + ', hosszabbítás ' + ft(elNum(R.hxr)) + '/óra, Budapest ' + ft(elNum(R.bp)) + ', vidék km × 2 × ' + ft(elNum(R.km)) + (elNum(R.sup) ? ' + ' + ft(elNum(R.sup)) + ' raktárba jutási támogatás' : '') + (elNum(R.min) ? ', minimum ' + ft(elNum(R.min)) : '') + '</p>' +
		'<table><thead><tr><th>Dátum</th><th>Rendezvény</th><th class="r">Óra</th><th class="r">Munkadíj</th><th class="r">Kiszállás</th><th class="r">Park./jatt</th><th class="r">Korr.</th><th class="r">Összesen</th><th>Állapot</th></tr></thead><tbody>' + rows + '</tbody></table>' +
		'<div class="tot"><div>Összesen<b>' + ft(T) + '</b></div><div>Kifizetve<b>' + ft(P) + '</b></div><div>Fizetendő (lezajlott)<b>' + ft(O) + '</b></div>' + (F ? '<div>Még nem volt (becsült)<b>' + ft(F) + '</b></div>' : '') + '</div>' +
		'</body></html>';
	const w = window.open('', '_blank');
	if (!w) { alert('A böngésző letiltotta az új ablakot – engedélyezd a felugró ablakokat ennél az oldalnál.'); return; }
	w.document.open();
	w.document.write(html);
	w.document.close();
}

/* ---- változásnapló (v1.5) */
const EL_PRN = ['DS620', 'RX1', 'QW410', 'Kodak'];
const EL_FL = { k: 'Kezelő', h: 'Óraszám', p: 'Pakolás', kx: 'Kiszállás (kézi)', c: 'Korrekció', cn: 'Korrekció oka', pk: 'Parkolás', jt: 'Jatt', pa: 'Papír', pt: 'Nyomtató', sa: 'Elégedettség', no: 'Megjegyzés', ok: 'Ellenőrizve', rhx: 'Hosszabbítás (kezelő)', sv: 'Szolgáltatás', gm: 'Gép működése', gmn: 'Gép megjegyzés', km: 'Km', hx: 'Hosszabbítás', pay: 'Kifizetés', row: 'Kezelő-sor', ko: 'Kezelő (kézi, a naptár helyett)', hc: 'Óraszám (kézi, a naptár helyett)' };
function elHistHtml(st, row) {
	const lg = (Array.isArray(st.lg) ? st.lg : []).filter(x => x.i === row.i || !x.i || x.f === 'row');
	if (!lg.length) return '<h4>Előzmények</h4><p class="cpx-muted">Még nincs rögzített változás. A napló az 1.5-ös verziótól rögzíti a panelben és a kezelői adatlapon végzett módosításokat (a Salesmate-ben közvetlenül végzett változásokat nem).</p>';
	const v = (f, x) => {
		if (x === '' || x == null) return '–';
		if (f === 'k') { const k = kezById(x); return k ? k.name : x; }
		if (f === 'ok') return x === '1' ? 'igen' : '–';
		if (['p', 'kx', 'c', 'pk', 'jt'].includes(f)) return elFt(elNum(x));
		return String(x);
	};
	return '<h4>Előzmények</h4><div class="el-hist">' + lg.slice(0, 40).map(x => {
		const d = new Date(x.t * 1000);
		return '<div class="el-hrow"><span class="mu">' + esc(d.toLocaleString('hu-HU', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })) + '</span><b class="el-src' + (x.s === 'kezelő' ? ' k' : '') + '">' + esc(x.s) + '</b><span>' + esc(EL_FL[x.f] || x.f) + ': ' + esc(v(x.f, x.o)) + ' → ' + esc(v(x.f, x.n)) + '</span></div>';
	}).join('') + '</div>';
}

/* ---- kezelők */
function kezListModal() {
	const dlg = document.getElementById('cpx-modal');
	const list = elKez().slice().sort((a, b) => (b.act - a.act) || a.name.localeCompare(b.name, 'hu'));
	dlg.innerHTML = '<div class="cpx-sheet wide"><div class="cpx-sheet-h"><h3>Kezelők és díjak</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>' +
		'<p class="cpx-muted">A <b>Salesmate név</b> az, ami a Salesmate „Gépkezelő asszisztens” listájában szerepel – a panel ezen a néven olvassa és írja vissza a fő kezelőt. Új kezelőt a Salesmate listába is fel kell venni.</p>' +
		'<div class="cpx-sheet-f" style="margin:0 0 12px"><button class="cpx-btn" id="kz-new">+ Új kezelő</button></div>' +
		'<div class="el-sumwrap"><table class="el-sum el-kz"><thead><tr><th>Név</th><th>Salesmate név</th><th>Szerep</th><th class="r">Óradíj</th><th class="r">Pakolás</th><th class="r">Hossz./ó</th><th class="r">Bp</th><th class="r">Ft/km</th><th class="r">Raktár tám.</th><th class="r">Minimum</th><th></th></tr></thead><tbody>' +
		list.map(k => '<tr class="' + (k.act ? '' : 'off') + '"><td><b>' + esc(k.name) + '</b>' + (k.act ? '' : ' <small class="mu">(inaktív)</small>') + '</td><td>' + esc(k.sm) + (k.em ? '<small class="mu" style="display:block">📅 ' + esc(k.em) + '</small>' : '') + '</td><td>' + esc(ROLE_L[k.role] || k.role) + '</td><td class="r">' + ft(k.rate) + '</td><td class="r">' + ft(k.pack) + '</td><td class="r">' + ft(k.hxr) + '</td><td class="r">' + ft(k.bp) + '</td><td class="r">' + esc(k.km) + '</td><td class="r">' + ft(k.sup) + '</td><td class="r">' + ft(k.min) + '</td><td><button type="button" class="el-a" data-kz="' + esc(k.id) + '">✎</button></td></tr>').join('') +
		'</tbody></table></div></div>';
	dlg.hidden = false;
	dlg.querySelector('#kz-new').onclick = () => kezModal(null, () => kezListModal());
	dlg.querySelectorAll('[data-kz]').forEach(b => b.addEventListener('click', () => kezModal(kezById(b.dataset.kz), () => kezListModal())));
}

function kezModal(k, done) {
	const isNew = !k;
	const role = k ? k.role : 'kezelo';
	const R = Object.assign({}, roleDef(role), k || {});
	const dlg = document.getElementById('cpx-modal');
	const f = (id, label, val) => '<label>' + label + '<input id="' + id + '" value="' + esc(val) + '" inputmode="numeric"></label>';
	dlg.innerHTML = '<div class="cpx-sheet narrow"><div class="cpx-sheet-h"><h3>' + (isNew ? 'Új kezelő' : esc(k.name)) + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>' +
		'<div class="el-grid">' +
		'<label class="wide">Név (a panelen)<input id="kz-name" value="' + esc(k ? k.name : '') + '" maxlength="60" placeholder="pl. Kiss Peti"></label>' +
		'<label class="wide">Salesmate név (Gépkezelő asszisztens)<input id="kz-sm" value="' + esc(k ? k.sm : '') + '" maxlength="80" placeholder="ha üres, ugyanaz, mint a név"></label>' +
		'<label class="wide">Naptár e-mail <small>(amivel a naptárbejegyzésekbe meg van hívva; több is lehet, vesszővel)</small><input id="kz-em" value="' + esc(k && k.em ? k.em : '') + '" maxlength="200" placeholder="pl. kiss.peti@gmail.com" inputmode="email" autocomplete="off"></label>' +
		'<label class="wide">Szerep<select id="kz-role">' + Object.keys(ROLE_L).map(r => '<option value="' + r + '"' + (r === role ? ' selected' : '') + '>' + ROLE_L[r] + '</option>').join('') + '</select></label>' +
		f('kz-rate', 'Óradíj (Ft)', R.rate) + f('kz-pack', 'Raktári pakolás (Ft)', R.pack) + f('kz-hxr', 'Hosszabbítás (Ft/óra)', R.hxr) + f('kz-min', 'Minimum (Ft)', R.min) +
		f('kz-bp', 'Budapesti kiszállás (Ft)', R.bp) + f('kz-km', 'Vidék: Ft/km (oda-vissza km-re)', R.km) + f('kz-sup', 'Raktárba jutási támogatás (Ft)', R.sup) +
		(isNew ? '' : '<label class="chk wide"><input type="checkbox" id="kz-act"' + (k.act ? ' checked' : '') + '> aktív (választható a listában)</label>') +
		'</div><p class="cpx-muted" id="kz-msg"></p><div class="cpx-sheet-f"><button class="cpx-btn" id="kz-save">Mentés</button><button class="cpx-btn ghost" id="kz-cancel">Mégse</button></div></div>';
	dlg.hidden = false;
	const q = s => dlg.querySelector(s);
	q('#kz-name').focus();
	q('#kz-role').addEventListener('change', e => {
		if (!isNew) return;
		const D = roleDef(e.target.value);
		['rate', 'pack', 'hxr', 'min', 'bp', 'km', 'sup'].forEach(x => { q('#kz-' + x).value = D[x]; });
	});
	q('#kz-cancel').onclick = () => { closeModal(); if (done) done(null); };
	q('#kz-save').onclick = async () => {
		const rec = { id: k ? k.id : '', name: q('#kz-name').value.trim(), sm: q('#kz-sm').value.trim(), role: q('#kz-role').value, act: isNew || q('#kz-act').checked ? '1' : '0', em: q('#kz-em').value.trim() };
		['rate', 'pack', 'hxr', 'min', 'bp', 'km', 'sup'].forEach(x => { rec[x] = q('#kz-' + x).value.trim(); });
		if (!rec.name) { q('#kz-msg').textContent = 'Add meg a nevet.'; return; }
		q('#kz-save').disabled = true;
		try {
			const res = await elPost({ action: 'cp_el_kez', k: JSON.stringify(rec) });
			EL.kez = res.kez || EL.kez;
			Object.keys(EL.cache).forEach(m => { EL.cache[m].kez = EL.kez; });
			toast('Kezelő mentve');
			elLoad(S.month).then(() => { if (EL.draw && S.tab === 'elszamolas') EL.draw(); }).catch(() => {});
			if (EL.draw) EL.draw();
			closeModal();
			if (done) done(kezById(res.id));
		} catch (e) { q('#kz-msg').textContent = 'Hiba: ' + e.message; q('#kz-save').disabled = false; }
	};
}

/* ---- kézi tétel */
function manualModal(d) {
	const st = d ? (EL.cache[S.month].store[d.id] || {}) : {};
	const dlg = document.getElementById('cpx-modal');
	const def = monthKey(today()) === S.month ? today() : S.month + '-01';
	const act = elKez().filter(k => k.act).sort((a, b) => a.name.localeCompare(b.name, 'hu'));
	dlg.innerHTML = '<div class="cpx-sheet narrow"><div class="cpx-sheet-h"><h3>' + (d ? 'Kézi tétel szerkesztése' : 'Új kézi tétel') + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>' +
		'<p class="cpx-muted">Olyan munkához, ami nincs a Salesmate-ben (pl. raktári munka, beugrás, külsős rendezvény).</p><div class="el-grid">' +
		'<label>Dátum<input type="date" id="mn-date" value="' + esc(d ? d.date : def) + '"></label>' +
		'<label>Óraszám<input id="mn-h" inputmode="decimal" value="' + esc(d ? d.hours : '') + '"></label>' +
		'<label class="wide">Ügyfél / megnevezés<input id="mn-title" maxlength="120" value="' + esc(d ? d.title : '') + '"></label>' +
		'<label class="wide">Helyszín (település vagy cím – ebből számol km-t)<input id="mn-loc" maxlength="200" value="' + esc(d ? d.loc : '') + '"></label>' +
		'<label class="wide">Szolgáltatás<input id="mn-svc" maxlength="120" value="' + esc(d ? d.svc : '') + '"></label>' +
		(d ? '' : '<label class="wide">Kezelő<select id="mn-k"><option value="">– később –</option>' + act.map(k => '<option value="' + esc(k.id) + '">' + esc(k.name) + '</option>').join('') + '</select></label>') +
		'</div><p class="cpx-muted" id="mn-msg"></p><div class="cpx-sheet-f"><button class="cpx-btn" id="mn-save">Mentés</button><button class="cpx-btn ghost" data-close>Mégse</button></div></div>';
	dlg.hidden = false;
	const q = s => dlg.querySelector(s);
	q('#mn-save').onclick = async () => {
		const date = q('#mn-date').value;
		const body = { action: 'cp_el_manual', op: 'save', id: d ? d.id : '', date: date, title: q('#mn-title').value.trim(), loc: q('#mn-loc').value.trim(), svc: q('#mn-svc').value.trim(), dh: q('#mn-h').value.trim().replace(',', '.'), k: d ? '' : q('#mn-k').value };
		if (!body.title || !date) { q('#mn-msg').textContent = 'Add meg a dátumot és a megnevezést.'; return; }
		q('#mn-save').disabled = true;
		try {
			const res = await elPost(body);
			const m = monthKey(date);
			Object.keys(EL.cache).forEach(mm => { const c = EL.cache[mm]; c.deals = c.deals.filter(x => x.id !== res.id); delete c.store[res.id]; });
			const c = EL.cache[m];
			if (c) {
				c.deals.push(res.deal);
				c.deals.sort((a, b) => (a.date + (a.time || '99')).localeCompare(b.date + (b.time || '99')));
				c.store[res.id] = res.store;
				c.links[res.id] = res.link;
			}
			closeModal();
			toast('Kézi tétel mentve');
			if (m !== S.month) { S.month = m; render(); } else if (EL.draw) EL.draw();
		} catch (e) { q('#mn-msg').textContent = 'Hiba: ' + e.message; q('#mn-save').disabled = false; }
	};
}

async function elManualDel(d) {
	if (!confirm('Törlöd ezt a kézi tételt? (' + d.who + ', ' + d.date + ')')) return;
	try {
		await elPost({ action: 'cp_el_manual', op: 'del', id: d.id });
		Object.keys(EL.cache).forEach(m => { const c = EL.cache[m]; c.deals = c.deals.filter(x => x.id !== d.id); delete c.store[d.id]; });
		toast('Kézi tétel törölve');
	} catch (e) { alert('Nem sikerült: ' + e.message); }
	if (EL.draw) EL.draw();
}

/* ---- export */
function elCsv() {
	const q = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
	const lines = [['Dátum', 'Kezelő', 'Ügyfél', 'Helyszín', 'Szolgáltatás', 'Óraszám', 'Óradíj', 'Raktár pakolás', 'Hosszabbítás (óra)', 'Munkadíj', 'Település / km', 'Kiszállási díj', 'Parkolás', 'Jatt', 'Korrekció', 'Összesen', 'Papír fogyasztás', 'Nyomtató típusa', 'Ügyfél elégedettség', 'Szolgáltatás (eszköz)', 'Gép működése', 'Gép megjegyzés', 'Megjegyzés', 'Ellenőrizve', 'Fizetve', 'Ki fizette', 'Kifizetett összeg', 'Salesmate', 'Fizetés módja'].map(q).join(';')];
	elLines(S.month).forEach(L => {
		const r = L.row;
		lines.push([L.d.date.replace(/-/g, '.') + '.', elKezName(L), L.d.who, L.d.loc, L.d.svc, String(L.h).replace('.', ','), elNum(L.R.rate), L.pack, String(L.hx || '').replace('.', ','), Math.round(L.munka), L.kiszTxt, Math.round(L.kisz), L.pk || '', L.jt || '', L.corr || '', Math.round(L.tot), r.pa || '', r.pt || '', r.sa || '', r.sv || '', r.gm || '', r.gmn || '', [r.no, r.cn].filter(Boolean).join(' | '), L.ok ? 'igen' : '', r.pd || '', r.pb || '', r.pv || '', L.d.manual ? 'kézi tétel' : C.sm + L.d.id + '/detail', r.pm === 'keszpenz' ? 'készpénz' : r.pm === 'atutalas' ? 'átutalás' : ''].map(q).join(';'));
	});
	const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
	const a = el('a', { href: URL.createObjectURL(blob), download: 'kezeloi-elszamolas-' + S.month + '.csv' });
	document.body.appendChild(a); a.click();
	setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

/* ------------------------------------------------------------ Ákos tárca (v1.9) */
	const TW = { data: null, loading: null, err: '', m: '', c: '', q: '', draw: null };
	const TW_MS = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
	const twSign = n => (n > 0 ? '+' : n < 0 ? '−' : '') + ft(Math.abs(n));
	const twDot = s => String(s || '').slice(5).replace('-', '.') + '.';
	const twNum = v => { const x = parseInt(String(v == null ? '' : v).replace(/ft/ig, '').replace(/[\s.]/g, ''), 10); return isFinite(x) ? x : 0; };
	const twCatCls = c => {
		const m = { 'bér': 1, 'aam befiz': 2, 'tulaj. befiz': 3, 'furgon': 4, 'parkolás': 5, 'raktár': 6, 'raktár/felszerelés': 6, 'ákos törlesztő': 7, 'eszköz bérbeadás': 8, 'egyenleg': 0 };
		const k = norm(c);
		if (k in m) return 'c' + m[k];
		let h = 0;
		for (const ch of k) h = (h * 31 + ch.charCodeAt(0)) % 997;
		return 'c' + (1 + h % 8);
	};
	const twMonthLbl = m => m.slice(0, 4) + '. ' + HU_MONTHS[+m.slice(5) - 1];

	async function twLoad(force) {
		if (TW.loading && !force) return TW.loading;
		const p = (async () => {
			const r = await fetch(C.ajax + '?action=cp_tw_data&t=' + encodeURIComponent(C.t) + (force ? '&refresh=1' : ''), { credentials: 'same-origin' });
			let j;
			try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
			if (!j.success) throw new Error(j.data && j.data.msg === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : (j.data && j.data.msg) || 'Hiba a tárca betöltésekor.');
			TW.data = j.data;
			TW.at = Date.now();
			TW.err = '';
			if (S.tab === 'tarca') setTimeout(renderStatus, 0);
			return j.data;
		})();
		TW.loading = p;
		try { return await p; } catch (e) { TW.err = e.message || String(e); throw e; } finally { if (TW.loading === p) TW.loading = null; }
	}

	async function twPost(data) {
		const d = await elPost(data);
		TW.data = d;
		TW.err = '';
		return d;
	}

	/* előző + aktuális hónap – ezekből becsüljük a még kifizetetlen béreket */
	function twMonths() {
		const d = new Date();
		d.setDate(1);
		d.setMonth(d.getMonth() - 1);
		return [d.getFullYear() + '-' + pad(d.getMonth() + 1), monthKey(today())];
	}
	async function twEnsureEl() {
		for (const m of twMonths()) {
			if (EL.cache[m]) continue;
			const [from, to] = monthRange(m);
			try {
				const r = await fetch(C.ajax + '?action=cp_el_data&t=' + encodeURIComponent(C.t) + '&from=' + from + '&to=' + to, { credentials: 'same-origin' });
				const j = await r.json();
				if (j && j.success && !EL.cache[m]) {
					const x = j.data;
					['store', 'portal', 'hx', 'links', 'geo', 'cal', 'geoc'].forEach(k => { x[k] = x[k] || {}; });
					EL.cache[m] = x;
					if (x.kez) EL.kez = x.kez;
				}
			} catch (e) { /* a becslés enélkül is működik */ }
		}
	}
	function twUnpaid() {
		let sum = 0, n = 0;
		const ms = twMonths().filter(m => EL.cache[m]);
		ms.forEach(m => elLines(m).forEach(L => { if (L.past && !L.paid && L.tot > 0) { sum += L.tot; n++; } }));
		return { sum, n, ms };
	}
	function twCalc() {
		const d = TW.data;
		if (!d) return null;
		const u = twUnpaid();
		const bal = +d.bal || 0, aam = +d.aamOpen || 0;
		return { bal, aam, aamN: (d.aam || []).length, unpaid: u.sum, unpaidN: u.n, ms: u.ms, exp: bal + aam - u.sum, live: !!d.live };
	}
	const twMsTxt = ms => ms.length ? ms.map(m => TW_MS[+m.slice(5) - 1]).join('–') : 'nincs betöltve';

	/* ---- egyenleg-sáv az Elszámolás fülön */
	function twBadgeHtml() {
		const x = twCalc();
		if (!x) return '<div class="tw-badge ld">' + (TW.err ? 'Ákos tárca: ' + esc(TW.err) : 'Ákos tárca betöltése…') + '</div>';
		const neg = x.exp < 0;
		return '<button type="button" class="tw-badge ' + (neg ? 'neg' : 'pos') + '" data-tw-go title="Megnyitás a Tárca fülön">' +
			'<span class="tw-b-l">Ákos tárca · várható egyenleg</span><b>' + twSign(x.exp) + '</b>' +
			'<span class="tw-b-s">' + (neg ? 'Ákosnak még ' + ft(-x.exp) + ' kell a kezelők kifizetéséhez' : 'Van fedezet a kifizetetlen bérekre') + '</span>' +
			'<span class="tw-b-d">Most a tárcában: <b>' + elFt(x.bal) + '</b> · kifizetetlen, lezajlott bérek (' + esc(twMsTxt(x.ms)) + '): <b>−' + ft(x.unpaid) + '</b> · kezelőknél lévő AAM-készpénz: <b>+' + ft(x.aam) + '</b>' + (x.live ? '' : ' · <b>a táblázat importja még nem futott</b>') + '</span></button>';
	}
	function twBadgeDraw() {
		const b = document.getElementById('el-tw');
		if (b) b.innerHTML = twBadgeHtml();
	}
	function twStatus(s) {
		if (TW.err) { s.textContent = TW.err; s.className = 'cpx-status err'; return; }
		const d = TW.data;
		if (d && TW.at) { const t = new Date(TW.at); s.textContent = 'Frissítve: ' + pad(t.getHours()) + ':' + pad(t.getMinutes()) + ':' + pad(t.getSeconds()) + ' · 30 mp-enként magától'; s.className = 'cpx-status'; }
		else { s.textContent = TW.loading ? 'Betöltés…' : ''; s.className = 'cpx-status'; }
	}

	/* ---- tételek futó egyenleggel (régi → új) */
	function twRows() {
		const d = TW.data;
		const all = (d.ledger || []).map(x => Object.assign({}, x)).concat((d.derived || []).map(x => Object.assign({}, x, { src: 'el' })));
		if (all.some(x => x.ref || (x.refs && x.refs.length))) {
			const info = {};
			Object.keys(EL.cache).forEach(m => elLines(m).forEach(L => { info[L.d.id + '|' + L.row.i] = { k: elKezName(L), who: L.d.who, date: L.d.date }; }));
			all.forEach(x => {
				if (x.ref && info[x.ref] && info[x.ref].k) { x.t = x.t.replace(/^Kezelő/, info[x.ref].k); x.k = info[x.ref].k; }
				const ws = (x.refs || []).map(r => info[r]).filter(Boolean);
				if (ws.length) x.t = x.k + ' bér – ' + ws.map(w => w.who + ' (' + twDot(w.date) + ')').join(', ');
			});
		}
		all.forEach(x => { x.a = +x.a || 0; x.o = +x.o || 0; });
		all.sort((a, b) => (a.d < b.d ? -1 : a.d > b.d ? 1 : a.o - b.o));
		let run = 0;
		all.forEach(x => { run += x.a; x.run = run; });
		return all;
	}

	/* ---- nézet */
	function viewTw(main) {
		if (!TW.data) {
			main.innerHTML = '<div class="cpx-empty">Ákos tárca betöltése…</div>';
			Promise.all([twLoad(), twEnsureEl()]).then(() => { if (S.tab === 'tarca') viewTw(main); })
				.catch(() => { if (S.tab !== 'tarca') return; main.innerHTML = '<div class="cpx-empty err">' + esc(TW.err) + '<br><button class="cpx-btn" id="tw-retry">Újra</button></div>'; main.querySelector('#tw-retry').onclick = () => viewTw(main); });
			return;
		}
		main.innerHTML = '';
		const head = el('div', { class: 'cpx-head' });
		head.appendChild(el('h2', { class: 'tw-h' }, 'Ákos tárca <small>készpénz</small>'));
		const tools = el('div', { class: 'cpx-trk-tools' });
		tools.innerHTML = '<select id="tw-m" class="el-sel" aria-label="Hónap"></select><select id="tw-c" class="el-sel" aria-label="Kategória"></select>' +
			'<input type="search" id="tw-q" placeholder="Keresés: név, megnevezés…" value="' + esc(TW.q) + '">' +
			'<button class="cpx-btn sm" id="tw-in">+ Bevétel</button><button class="cpx-btn ghost sm" id="tw-out">− Kiadás</button>' +
			'<button class="cpx-btn ghost sm" id="tw-csv" title="Letöltés Excelhez (CSV)">Excel export</button><button class="cpx-btn ghost sm" id="tw-set">Beállítások</button>';
		head.appendChild(tools);
		main.appendChild(head);
		const kp = el('div', { class: 'cpx-kpis' });
		main.appendChild(kp);
		const note = el('div');
		main.appendChild(note);
		const aam = el('div', { class: 'cpx-card tw-aam' });
		main.appendChild(aam);
		const wrap = el('div', { class: 'el-wrap tw-wrap' });
		main.appendChild(wrap);
		main.appendChild(el('p', { class: 'cpx-help' }, 'Ákos készpénzes tárcája. Az egyenleg három forrásból áll: a <b>kézi tételekből</b> (+ Bevétel / − Kiadás), az <b>Elszámolás fül kifizetéseiből</b> (ha Ákos fizette, és nem átutalással – ezek itt nem szerkeszthetők, a kifizetést az Elszámolás fülön lehet visszavonni), valamint a <b>tárcába került AAM-befizetésekből</b>. A „Kezelőknél lévő AAM-befizetések” a Salesmate-ből jönnek (Szerződés EV/KFT = EV és Választott fizetési mód = Helyszínen készpénz, lezajlott rendezvény – a Beállításokban kérhető, hogy az üres fizetési módú EV-s dealek is ide kerüljenek) – amikor a kezelő leadja a pénzt Ákosnak, nyomd meg a „Tárcába került” gombot. A <b>várható egyenleg</b> = tárca + kezelőknél lévő AAM-készpénz − a még ki nem fizetett, már lezajlott kezelői bérek (előző és aktuális hónap, az Elszámolás fül alapján). Ha negatív, Ákosnak pénz kell a bérekhez.'));
		const mSel = tools.querySelector('#tw-m'), cSel = tools.querySelector('#tw-c');

		const draw = () => {
			const d = TW.data;
			if (!d) return;
			const x = twCalc();
			const rows = twRows();
			const months = Array.from(new Set(rows.map(r => r.d.slice(0, 7)))).sort().reverse();
			if (TW.m && !months.includes(TW.m)) TW.m = '';
			mSel.innerHTML = '<option value="">Minden hónap</option>' + months.map(m => '<option value="' + m + '"' + (TW.m === m ? ' selected' : '') + '>' + esc(twMonthLbl(m)) + '</option>').join('');
			const cats = Array.from(new Set(rows.map(r => r.c))).sort((a, b) => a.localeCompare(b, 'hu'));
			if (TW.c && !cats.includes(TW.c)) TW.c = '';
			cSel.innerHTML = '<option value="">Minden kategória</option>' + cats.map(c => '<option' + (TW.c === c ? ' selected' : '') + '>' + esc(c) + '</option>').join('');
			kp.innerHTML = kpi('Most a tárcában', elFt(x.bal)) + kpi('Kezelőknél lévő AAM (' + x.aamN + ' db)', '+' + ft(x.aam)) + kpi('Kifizetetlen bérek (' + twMsTxt(x.ms) + ')', '−' + ft(x.unpaid)) +
				'<div class="kpi ' + (x.exp < 0 ? 'tw-neg' : 'tw-pos') + '"><b>' + esc(twSign(x.exp)) + '</b><span>Várható egyenleg</span></div>';
			let nh = '';
			if (!x.live) nh += '<div class="el-lockbar">A 2026-os táblázat importja még nem futott – addig az Elszámolás kifizetései nem számítanak bele a tárcába.</div>';
			if (x.exp < 0) nh += '<div class="el-alert">⚠ Ákosnak még ' + ft(-x.exp) + ' kell a kezelők kifizetéséhez (várható egyenleg).</div>';
			if (d.aamErr) nh += '<div class="el-lockbar">Az AAM-lista most nem frissült a Salesmate-ből: ' + esc(d.aamErr) + '</div>';
			note.innerHTML = nh;

			const al = d.aam || [];
			aam.innerHTML = '<h3>Kezelőknél lévő AAM-befizetések <small class="cpx-muted">(Salesmate: ' + (d.aamMode === 'loose' ? 'EV, helyszíni készpénz vagy üres fizetési mód' : 'EV + helyszínen készpénz') + ', ' + esc(String(d.aamFrom || '').replace(/-/g, '.')) + '. óta: ' + (+d.aamTotal || 0) + ' deal, ebből ' + Math.max(0, (+d.aamTotal || 0) - al.length) + ' már rendezve)</small></h3>' +
				(al.length ? '<div class="tw-aamwrap"><table class="el-t tw-at"><thead><tr><th>Dátum</th><th>Ügyfél</th><th>Kezelő</th><th class="r">Csomag</th><th class="r">Kiszállás</th><th class="r">Várható</th><th></th></tr></thead><tbody>' +
					al.map((a, n) => {
						const kz = kezBySm(a.gk);
						return '<tr><td class="td-d">' + twDot(a.date) + '</td><td><a href="' + esc(C.sm + a.id + '/detail') + '" target="_blank" rel="noopener">' + esc(a.who) + '</a><small class="mu">' + esc(a.stage) + '</small>' + (a.pm ? '' : '<small class="tw-pm">fizetési mód nincs kitöltve</small>') + '</td><td>' + esc(kz ? kz.name : (a.gk || '–')) + '</td>' +
							'<td class="r">' + ft(a.val) + '</td><td class="r">' + (a.kd ? ft(a.kd) : '–') + '</td><td class="r"><b>' + ft(a.val + a.kd) + '</b></td>' +
							'<td class="tw-acts"><button class="cpx-btn sm" data-aam="' + n + '">Tárcába került ✓</button> <button class="cpx-btn ghost sm" data-skip="' + n + '" title="Kivétel a listából (pl. mégsem készpénzben fizetett)">Nem kell</button></td></tr>';
					}).join('') + '</tbody></table></div>'
					: '<p class="cpx-muted">Nincs függő AAM-befizetés – minden helyszíni készpénzes EV-s rendezvény pénze a tárcában van.</p>');

			const q = norm(TW.q);
			const vis = rows.filter(r => (!TW.m || r.d.slice(0, 7) === TW.m) && (!TW.c || r.c === TW.c) &&
				(!q || norm(r.d + ' ' + r.t + ' ' + (r.k || '') + ' ' + (r.n || '') + ' ' + r.c).includes(q))).reverse();
			const mt = {};
			vis.forEach(r => { const t = mt[r.d.slice(0, 7)] = mt[r.d.slice(0, 7)] || { i: 0, o: 0 }; if (r.a > 0) t.i += r.a; else t.o += r.a; });
			let h = '<table class="el-t tw-t"><thead><tr><th class="c-d">Dátum</th><th class="r">Összeg</th><th class="r">Egyenleg</th><th>Kategória</th><th>Kezelő</th><th>Megnevezés</th><th>Megjegyzés</th><th></th></tr></thead><tbody>';
			let lastM = '';
			vis.forEach(r => {
				const m = r.d.slice(0, 7);
				if (m !== lastM) { lastM = m; h += '<tr class="tw-m"><td colspan="8"><span class="tw-ml">' + esc(twMonthLbl(m)) + '</span><span class="tw-mr">be: +' + ft(mt[m].i) + ' · ki: −' + ft(-mt[m].o) + '</span></td></tr>'; }
				const src = r.src === 'el' ? '<span class="tw-src el" title="Az Elszámolás fülön rögzített kifizetés">Elszámolás</span>' : r.src === 'aam' ? '<span class="tw-src aam" title="Salesmate AAM-befizetés">AAM</span>' : '';
				h += '<tr><td class="td-d">' + twDot(r.d) + '</td><td class="r tw-a ' + (r.a < 0 ? 'neg' : 'pos') + '">' + esc(twSign(r.a)) + '</td><td class="r tw-run' + (r.run < 0 ? ' neg' : '') + '">' + esc(elFt(r.run)) + '</td>' +
					'<td class="tw-cat"><span class="tw-chip ' + twCatCls(r.c) + '">' + esc(r.c) + '</span></td><td class="tw-k">' + esc(r.k || '') + '</td><td class="tw-tt">' + esc(r.t) + src + '</td><td class="tw-nn">' + esc(r.n || '') + (r.sz ? ' <span class="tw-sz" title="Számla / bizonylat leadva">✓ számla</span>' : '') + '</td>' +
					'<td class="tw-acts">' + (r.src === 'el' ? '<button class="cpx-btn ghost sm" data-goel="' + esc(r.em || r.d) + '" title="Megnyitás az Elszámolás fülön">→</button>' : '<button class="cpx-btn ghost sm" data-ed="' + esc(r.id) + '" aria-label="Szerkesztés">✎</button>') + '</td></tr>';
			});
			if (!vis.length) h += '<tr><td colspan="8" class="cpx-empty">' + (rows.length ? 'Nincs a szűrésnek megfelelő tétel.' : 'Még nincs tétel a tárcában.') + '</td></tr>';
			h += '</tbody></table>';
			const sl = wrap.scrollLeft, stp = wrap.scrollTop;
			wrap.innerHTML = h;
			wrap.scrollLeft = sl; wrap.scrollTop = stp;
		};
		TW.draw = draw;

		mSel.addEventListener('change', e => { TW.m = e.target.value; draw(); });
		cSel.addEventListener('change', e => { TW.c = e.target.value; draw(); });
		tools.querySelector('#tw-q').addEventListener('input', e => { TW.q = e.target.value; draw(); });
		tools.querySelector('#tw-in').addEventListener('click', () => twEntryModal(null, 1));
		tools.querySelector('#tw-out').addEventListener('click', () => twEntryModal(null, -1));
		tools.querySelector('#tw-csv').addEventListener('click', twCsv);
		tools.querySelector('#tw-set').addEventListener('click', twSetModal);
		wrap.addEventListener('click', ev => {
			const ed = ev.target.closest('[data-ed]');
			if (ed) { const r = (TW.data.ledger || []).find(x => x.id === ed.dataset.ed); if (r) twEntryModal(r); return; }
			const ge = ev.target.closest('[data-goel]');
			if (ge) { S.month = ge.dataset.goel.slice(0, 7); go('elszamolas'); }
		});
		aam.addEventListener('click', ev => {
			const a = ev.target.closest('[data-aam]');
			if (a) { const x = (TW.data.aam || [])[+a.dataset.aam]; if (x) twAamModal(x); return; }
			const s = ev.target.closest('[data-skip]');
			if (s) {
				const x = (TW.data.aam || [])[+s.dataset.skip];
				if (x && confirm('Kiveszed a listából?\n' + x.who + ' (' + twDot(x.date) + ')\nPl. ha mégsem készpénzben fizetett. A Beállításokban visszaállítható.')) twAct({ action: 'cp_tw_aam', deal: x.id, skip: '1' }, 'Kivéve a listából');
			}
		});
		draw();
		twEnsureEl().then(() => { if (S.tab === 'tarca' && TW.draw === draw) draw(); });
	}

	async function twAct(data, msg) {
		try { await twPost(data); if (msg) toast(msg); } catch (e) { alert('Nem sikerült: ' + e.message); }
		if (TW.draw && S.tab === 'tarca') TW.draw();
		twBadgeDraw();
	}

	const twKezList = id => {
		const names = [];
		elKez().forEach(k => { if (k.name && !names.includes(k.name)) names.push(k.name); });
		((TW.data && TW.data.ledger) || []).forEach(x => { if (x.k && !names.includes(x.k)) names.push(x.k); });
		names.sort((a, b) => a.localeCompare(b, 'hu'));
		return '<datalist id="' + id + '">' + names.map(n => '<option value="' + esc(n) + '"></option>').join('') + '</datalist>';
	};

	/* ---- új / szerkesztett tétel */
	function twEntryModal(x, sign) {
		const d = TW.data;
		const dlg = document.getElementById('cpx-modal');
		const isNew = !x;
		const cur = x ? Object.assign({}, x) : { d: today(), a: '', c: sign > 0 ? 'Tulaj. befiz' : 'bér', k: '', t: '', n: '', sz: '' };
		let sg = x ? (+x.a < 0 ? -1 : 1) : sign;
		const cats = (d.cats || []).slice();
		(d.ledger || []).forEach(x => { if (x.c && !cats.includes(x.c)) cats.push(x.c); });
		if (cur.c && !cats.includes(cur.c)) cats.push(cur.c);
		dlg.innerHTML = '<div class="cpx-sheet narrow"><div class="cpx-sheet-h"><h3>' + (isNew ? 'Új tétel – Ákos tárca' : 'Tétel szerkesztése') + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>' +
			'<div class="seg" id="tw-sg"><button type="button" data-s="1">+ Bevétel (be a tárcába)</button><button type="button" data-s="-1">− Kiadás (ki a tárcából)</button></div>' +
			'<div class="el-grid tw-grid">' +
			'<label>Dátum<input type="date" id="tw-d" value="' + esc(cur.d) + '"></label>' +
			'<label>Összeg (Ft)<input id="tw-a" inputmode="numeric" autocomplete="off" value="' + (cur.a === '' ? '' : esc(Math.abs(+cur.a))) + '" placeholder="pl. 25000"></label>' +
			'<label>Kategória<select id="tw-c">' + cats.map(c => '<option' + (c === cur.c ? ' selected' : '') + '>' + esc(c) + '</option>').join('') + '<option value="__new">+ Új kategória…</option></select></label>' +
			'<label>Kezelő / személy <small>(bármilyen név beírható)</small><input id="tw-k" list="tw-kl" maxlength="60" value="' + esc(cur.k || '') + '"></label>' +
			'<label class="wide">Megnevezés<input id="tw-t" maxlength="200" value="' + esc(cur.t || '') + '" placeholder="pl. Furgon tankolás, Balázs befizetés kezelő bérekre"></label>' +
			'<label class="wide">Megjegyzés<input id="tw-n" maxlength="300" value="' + esc(cur.n || '') + '"></label>' +
			'<label class="chk wide"><input type="checkbox" id="tw-sz"' + (cur.sz ? ' checked' : '') + '> Számla / bizonylat leadva</label>' +
			'</div>' + twKezList('tw-kl') +
			(x && x.src === 'aam' ? '<p class="cpx-muted">Salesmate AAM-befizetésből rögzítve. Ha törlöd, a deal visszakerül a „Kezelőknél” listába.</p>' : '') +
			(isNew ? '<p class="cpx-muted">A kezelői béreket az Elszámolás fülön fizesd ki (Ákos + Készpénz) – azok maguktól levonódnak innen. Ide a többi mozgás jön: befizetés a tárcába, tankolás, parkolás, raktár, törlesztés stb.</p>' : '') +
			'<div class="cpx-sheet-f"><button class="cpx-btn" id="tw-go">Mentés</button><button class="cpx-btn ghost" data-close>Mégse</button>' + (isNew ? '' : '<button class="cpx-btn ghost tw-del" id="tw-del">Törlés</button>') + '</div></div>';
		dlg.hidden = false;
		const q = s => dlg.querySelector(s);
		const setSg = v => { sg = v; dlg.querySelectorAll('#tw-sg [data-s]').forEach(b => b.classList.toggle('on', +b.dataset.s === sg)); };
		setSg(sg);
		dlg.querySelectorAll('#tw-sg [data-s]').forEach(b => b.addEventListener('click', () => setSg(+b.dataset.s)));
		let lastCat = cur.c;
		q('#tw-c').addEventListener('change', e => {
			const sel = e.target;
			if (sel.value === '__new') {
				const n = (prompt('Új kategória neve:') || '').trim().slice(0, 40);
				if (!n || n === '__new') { sel.value = lastCat; return; }
				let o = Array.from(sel.options).find(x => x.value === n);
				if (!o) { o = document.createElement('option'); o.value = n; o.textContent = n; sel.insertBefore(o, sel.querySelector('option[value="__new"]')); }
				sel.value = n;
				toast('Új kategória: ' + n + ' – nézd meg, hogy bevétel vagy kiadás legyen');
			}
			lastCat = sel.value;
			const s = (d.catSign || {})[sel.value];
			if (isNew && s) setSg(s);
		});
		q('#tw-go').onclick = async () => {
			const a = Math.abs(twNum(q('#tw-a').value));
			if (!a) { alert('Add meg az összeget.'); return; }
			if (!q('#tw-d').value) { alert('Add meg a dátumot.'); return; }
			if (!q('#tw-t').value.trim()) { alert('Add meg a megnevezést.'); return; }
			const btn = q('#tw-go');
			btn.disabled = true;
			try {
				await twPost({ action: 'cp_tw_save', id: isNew ? '' : x.id, d: q('#tw-d').value, a: String(sg * a), c: q('#tw-c').value, k: q('#tw-k').value.trim(), tt: q('#tw-t').value.trim(), n: q('#tw-n').value.trim(), sz: q('#tw-sz').checked ? '1' : '' });
				closeModal();
				toast(isNew ? 'Tétel rögzítve: ' + twSign(sg * a) : 'Tétel mentve');
			} catch (e) { btn.disabled = false; alert('Nem sikerült: ' + e.message); return; }
			if (TW.draw && S.tab === 'tarca') TW.draw();
			twBadgeDraw();
		};
		if (!isNew) q('#tw-del').onclick = async () => {
			if (!confirm('Törlöd ezt a tételt?\n' + x.t + ' (' + twSign(+x.a) + ')')) return;
			try { await twPost({ action: 'cp_tw_del', id: x.id }); closeModal(); toast('Tétel törölve'); } catch (e) { alert('Nem sikerült: ' + e.message); return; }
			if (TW.draw && S.tab === 'tarca') TW.draw();
			twBadgeDraw();
		};
	}

	/* ---- AAM: a kezelő leadta a pénzt */
	function twAamModal(x) {
		const dlg = document.getElementById('cpx-modal');
		const kz = kezBySm(x.gk);
		dlg.innerHTML = '<div class="cpx-sheet narrow"><div class="cpx-sheet-h"><h3>AAM-befizetés a tárcába</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>' +
			'<p><b>' + esc(x.who) + '</b> – rendezvény: ' + esc(x.date.replace(/-/g, '.')) + '.<br><span class="cpx-muted">Csomag (deal értéke): ' + ft(x.val) + (x.kd ? ' · kiszállási díj: ' + ft(x.kd) : '') + '</span></p>' +
			'<div class="el-grid"><label>Tárcába került<input type="date" id="ta-d" value="' + today() + '"></label>' +
			'<label>Átadott összeg (Ft)<input id="ta-a" inputmode="numeric" autocomplete="off" value="' + (x.val + x.kd) + '"></label>' +
			'<label>Ki hozta<input id="ta-k" list="tw-kl2" maxlength="60" value="' + esc(kz ? kz.name : (x.gk || '')) + '"></label>' +
			'<label class="wide">Megjegyzés<input id="ta-n" maxlength="300" placeholder="pl. + 3500 visszajáró"></label></div>' + twKezList('tw-kl2') +
			'<p class="cpx-muted">Az összeget írd át, ha a kezelő mást adott le (pl. kiszállás nélkül, visszajáróval).</p>' +
			'<div class="cpx-sheet-f"><button class="cpx-btn" id="ta-go">Tárcába került ✓</button><button class="cpx-btn ghost" data-close>Mégse</button></div></div>';
		dlg.hidden = false;
		const q = s => dlg.querySelector(s);
		q('#ta-go').onclick = async () => {
			const a = Math.abs(twNum(q('#ta-a').value));
			if (!a || !q('#ta-d').value) { alert('Add meg a dátumot és az összeget.'); return; }
			q('#ta-go').disabled = true;
			try {
				await twPost({ action: 'cp_tw_aam', deal: x.id, ed: x.date, who: x.who, d: q('#ta-d').value, a: String(a), k: q('#ta-k').value.trim(), n: q('#ta-n').value.trim() });
				closeModal();
				toast('AAM a tárcában: +' + ft(a));
			} catch (e) { q('#ta-go').disabled = false; alert('Nem sikerült: ' + e.message); return; }
			if (TW.draw && S.tab === 'tarca') TW.draw();
			twBadgeDraw();
		};
	}

	/* ---- beállítások + Google-táblázat tükör */
	function twSetModal() {
		const d = TW.data;
		const dlg = document.getElementById('cpx-modal');
		const f = '=IMPORTDATA("' + d.feed + '")';
		dlg.innerHTML = '<div class="cpx-sheet narrow"><div class="cpx-sheet-h"><h3>Tárca – beállítások</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>' +
			'<h4>Mi számít bele a tárcába?</h4><p class="cpx-muted">• kézi tételek (bevétel / kiadás) és a 2026-os táblázatból importált sorok<br>• az Elszámolás fül kifizetései, ha <b>' + esc(d.payer) + '</b> fizette, és nem átutalással' + (d.liveFrom ? ' (' + esc(d.liveFrom.replace(/-/g, '.')) + '. napi vagy későbbi kifizetési dátummal)' : '') + '<br>• a „Tárcába került” AAM-befizetések (Salesmate: Választott fizetési mód = ' + esc(d.payVal) + ', Szerződés EV/KFT = ' + esc(d.evVal) + ')</p>' +
			'<div class="el-grid">' + (d.live ? '<label>Elszámolás-kifizetések ettől a kifizetési naptól<input type="date" id="ts-live" value="' + esc(d.liveFrom || '') + '"></label>' : '') + '<label>AAM-dealek figyelése ettől a rendezvénydátumtól<input type="date" id="ts-from" value="' + esc(d.aamFrom) + '"></label><label>Mi számít AAM-befizetésnek?<select id="ts-mode"><option value="strict"' + (d.aamMode !== 'loose' ? ' selected' : '') + '>EV + Helyszínen készpénz</option><option value="loose"' + (d.aamMode === 'loose' ? ' selected' : '') + '>EV + Helyszínen készpénz vagy üres fizetési mód</option></select></label></div>' +
			(Object.keys(d.aamStats || {}).length ? '<p class="cpx-muted">EV-s rendezvények ' + esc(String(d.aamFrom).replace(/-/g, '.')) + '. óta, „Választott fizetési mód” szerint: ' + Object.keys(d.aamStats).map(k => esc(k) + ': ' + d.aamStats[k]).join(' · ') + '</p>' : '') +
			(d.skipped ? '<p><button class="cpx-btn ghost sm" id="ts-unskip">„Nem kell”-lel kivett AAM-dealek visszaállítása (' + d.skipped + ')</button></p>' : '') +
			'<h4>Google-táblázat tükör</h4><p class="cpx-muted">Írd be ezt a képletet egy üres munkalap A1 cellájába – a Google-táblázat magától frissül a panel adataiból (kb. óránként). A link titkos: akinek megvan, látja a tárcát.</p>' +
			'<div class="el-link"><input readonly id="ts-feed" value="' + esc(f) + '"></div>' +
			'<p><button class="cpx-btn ghost sm" id="ts-copy">Képlet másolása</button> <button class="cpx-btn ghost sm" id="ts-newkey">Új link (a régi megszűnik)</button></p>' +
			'<div class="cpx-sheet-f"><button class="cpx-btn" id="ts-go">Mentés</button><button class="cpx-btn ghost" data-close>Bezárás</button></div></div>';
		dlg.hidden = false;
		const q = s => dlg.querySelector(s);
		const after = () => { if (TW.draw && S.tab === 'tarca') TW.draw(); twBadgeDraw(); };
		q('#ts-copy').onclick = () => { const i = q('#ts-feed'); i.select(); try { navigator.clipboard.writeText(i.value).then(() => toast('Képlet a vágólapon'), () => document.execCommand('copy')); } catch (e) { document.execCommand('copy'); toast('Képlet a vágólapon'); } };
		q('#ts-newkey').onclick = async () => {
			if (!confirm('Új linket készítesz? A régi képlet ezután nem működik.')) return;
			try { await twPost({ action: 'cp_tw_cfg', newkey: '1' }); closeModal(); twSetModal(); toast('Új link kész'); } catch (e) { alert('Nem sikerült: ' + e.message); }
		};
		if (q('#ts-unskip')) q('#ts-unskip').onclick = async () => {
			try { await twPost({ action: 'cp_tw_cfg', unskip: '1' }); closeModal(); toast('Visszaállítva'); } catch (e) { alert('Nem sikerült: ' + e.message); return; }
			after();
		};
		q('#ts-go').onclick = async () => {
			try { await twPost({ action: 'cp_tw_cfg', aam_from: q('#ts-from').value, aam_mode: q('#ts-mode').value, live_from: q('#ts-live') ? q('#ts-live').value : '' }); closeModal(); toast('Mentve'); } catch (e) { alert('Nem sikerült: ' + e.message); return; }
			after();
		};
	}

	function twCsv() {
		if (!TW.data) return;
		const qq = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
		const lbl = { el: 'Elszámolás', aam: 'Salesmate AAM', import: 'Táblázat (import)', kezi: 'Kézi' };
		const lines = [['Dátum', 'Összeg', 'Tárca egyenleg', 'Kategória', 'Kezelő', 'Megnevezés', 'Megjegyzés', 'Számla leadva', 'Forrás'].map(qq).join(';')];
		twRows().forEach(r => lines.push([r.d.replace(/-/g, '.') + '.', r.a, r.run, r.c, r.k || '', r.t, r.n || '', r.sz ? 'igen' : '', lbl[r.src] || r.src || ''].map(qq).join(';')));
		const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
		const a = el('a', { href: URL.createObjectURL(blob), download: 'akos-tarca-' + today() + '.csv' });
		document.body.appendChild(a); a.click();
		setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
	}

/* ------------------------------------------------------------ Tárca + Elszámolás automatikus frissítés (v1.9.7) */
	const TW_POLL_MS = 30000;
	async function twRefreshEl(skip) {
		for (const m of twMonths()) {
			if (m === skip) continue;
			const [from, to] = monthRange(m);
			try {
				const r = await fetch(C.ajax + '?action=cp_el_data&t=' + encodeURIComponent(C.t) + '&from=' + from + '&to=' + to, { credentials: 'same-origin' });
				const j = await r.json();
				if (j && j.success) {
					const x = j.data;
					['store', 'portal', 'hx', 'links', 'geo', 'cal', 'geoc'].forEach(k => { x[k] = x[k] || {}; });
					EL.cache[m] = x;
					if (x.kez) EL.kez = x.kez;
				}
			} catch (e) { /* a következő körben újra */ }
		}
	}
	const twBusy = () => { const ae = document.activeElement; return !!(ae && /^(SELECT|INPUT|TEXTAREA)$/.test(ae.tagName) && ae.id !== 'tw-q') || !document.getElementById('cpx-modal').hidden; };
	let twTicking = false;
	async function twTick(now) {
		if (twTicking || document.hidden || (S.tab !== 'tarca' && S.tab !== 'elszamolas')) return;
		if (!now && twBusy()) return;
		twTicking = true;
		try { await Promise.all([twLoad(), twRefreshEl(S.tab === 'elszamolas' ? S.month : '')]); } catch (e) { /* állapotsor */ }
		twTicking = false;
		if (S.tab === 'tarca' && TW.draw && !twBusy()) TW.draw();
		if (S.tab === 'elszamolas') twBadgeDraw();
		renderStatus();
	}
	setInterval(() => twTick(false), TW_POLL_MS);
	document.addEventListener('visibilitychange', () => { if (!document.hidden) twTick(false); });

	/* ------------------------------------------------------------ start */
  shell();
  render();
})();
