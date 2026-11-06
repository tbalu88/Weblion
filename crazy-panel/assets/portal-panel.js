/* Crazy panel – ügyfélportál: címkék, „Adatlap” oszlop, portál-részletező
 *   - „Ügyfélportál kiküldése” (a szerződés előtt; megrendelőtípus előre beállítható), újraküldés
 *   - „Szerződés adatai”: mezőnkénti másolás + „Összes másolása” (a Word / Google Docs szerződéshez)
 *   - irodai szerkesztés, link-műveletek, napló; a mentett fotósablon előnézete és megnyitása a Sablonkészítőben
 * A panel.js hívja: window.CPPortal.flags(), .cell(), .info(), .open(); a panel.js adja: .onChange, .openSablon */
(function () {
  'use strict';
  const C = window.CPX || {};
  const P = C.portal || {};
  const SCHEMA = P.schema || [];
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pad = n => (n < 10 ? '0' : '') + n;
  const dt = t => { if (!t) return '–'; const d = new Date(t * 1000); return d.getFullYear() + '.' + pad(d.getMonth() + 1) + '.' + pad(d.getDate()) + '. ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); };
  const ST = { hianyos: 'Hiányos', kesz: 'Kész', modositva: 'Módosítva' };
  const BY = { ugyfel: 'ügyfél', iroda: 'iroda', rendszer: 'rendszer' };
  const STAGE = { a: 'Szerződéshez szükséges adatok', b: 'A rendezvény részletei', c: 'Háttér, fotósablon, adatmegőrzés' };
  const field = k => SCHEMA.find(f => f.key === k);
  const sbUrl = f => (P.sbFile || (C.ajax + '?action=cp_portal_sb_file&f=')) + encodeURIComponent(f);

  /* ---------------------------------------------------- címkék és cellák */
  function flags(p, where) {
    if (!p || !p.flags) return '';
    const l = p.flags.filter(f => !where || f.where.indexOf(where) >= 0);
    return l.length ? '<span class="cpp-flags">' + l.map(f => '<span class="cpp-flag ' + esc(f.tone) + '">' + esc(f.text) + '</span>').join('') + '</span>' : '';
  }

  /* „Adatlap” oszlop a Követés fülön; e = a rendezvény (ajánlati szakaszban kiküldés gomb) */
  function cell(p, e) {
    if (!p) {
      const pend = e && e.state !== 'booked';
      return '<button type="button" class="pp-cell ' + (pend ? 'send' : 'none') + '" data-portal title="Ügyfélportál – még nincs kiküldve">' + (pend ? '✉ Kiküldés' : '–') + '</button>';
    }
    const v2 = p.flow === 'v2' && p.ct > 0;
    let cls, t;
    if (v2 && p.cd < p.ct) { cls = 'bad'; t = 'Szerz. ' + p.cd + '/' + p.ct; }
    else if (v2 && (!p.cready || p.cchanged)) { cls = 'mod'; t = p.cchanged ? 'Szerz. módosult' : 'Szerz. adatok kész'; }
    else {
      cls = p.st === 'kesz' ? 'ok' : p.st === 'modositva' ? 'mod' : 'bad';
      t = p.st === 'kesz' ? '✓ Kész' : p.st === 'modositva' ? 'Módosítva' : (v2 ? '✓ Szerz. · ' : '') + p.done + '/' + p.total;
    }
    const warn = (p.flags || []).filter(f => f.code !== 'hianyos' && f.where.indexOf('munkalap') >= 0).length;
    const tip = 'Adatlap: ' + (ST[p.st] || p.st) + ' · ' + p.done + '/' + p.total + (v2 ? '\nSzerződéshez: ' + p.cd + '/' + p.ct + (p.cready ? ' – mehet a szerződés (' + dt(p.cready) + ')' : '') + (p.cchanged ? ' – azóta módosult' : '') : '') + (p.bg ? '\nHáttér (kérés): ' + p.bg : '') + (p.sb ? '\nFotósablon: elmentve' : '') + (p.dl ? '\nHatáridő: ' + p.dl : '') + (p.locked ? ' (lezárva)' : '');
    return '<button type="button" class="pp-cell ' + cls + '" data-portal title="' + esc(tip) + '">' + esc(t) + (p.sb ? ' <i class="sb">🖼</i>' : '') + (warn ? ' <i>⚠' + warn + '</i>' : '') + '</button>';
  }

  /* napi munkalap: sorok a kártyába (címke, érték, html-e) */
  function info(p) {
    if (!p || !p.info) return [];
    const i = p.info, rows = [];
    if (i.venue) rows.push(['Helyszín (adatlap)', esc(i.venue) + (i.room ? ' – ' + esc(i.room) : '') + (i.maps ? ' <a href="' + esc(i.maps) + '" target="_blank" rel="noopener">térkép ↗</a>' : ''), true]);
    if (i.access) rows.push(['Bejutás', i.access]);
    if (i.contact && i.contact.name) rows.push(['Helyszíni kapcsolattartó', esc(i.contact.name) + (i.contact.tel ? ' · <a href="tel:' + esc(i.contact.tel) + '">' + esc(i.contact.phone) + '</a>' : ''), true]);
    if (i.schedule) rows.push(['Menetrend', [i.schedule.arrival ? 'érkezés ' + i.schedule.arrival : '', i.schedule.start ? 'indulás ' + i.schedule.start : '', i.schedule.end ? 'vége ' + i.schedule.end : ''].filter(Boolean).join(' · ')]);
    if (i.vehicle) rows.push(['Behajtás', i.vehicle]);
    if (i.parking) rows.push(['Parkolás', i.parking]);
    if (i.guests) rows.push(['Vendégek', i.guests]);
    if (i.special) rows.push(['Különleges kérés', i.special]);
    return rows;
  }

  /* ---------------------------------------------------- értékek szövegként (napló) */
  function optLabel(f, part, v) {
    const o = part ? (f.parts && f.parts[part] && f.parts[part].options) : f.options;
    return o && o[v] != null ? o[v] : v;
  }
  function valText(k, v) {
    if (v == null || v === '') return '–';
    const f = field(k);
    if (!f) return typeof v === 'object' ? JSON.stringify(v) : String(v);
    if (f.kind === 'consent') return v.given ? 'igen' : 'nem';
    if (f.kind === 'sablon') return typeof v === 'string' ? v : (v.name || 'mentve');
    if (f.parts && typeof v === 'object') {
      return Object.keys(f.parts).filter(pk => v[pk] !== undefined && v[pk] !== '').map(pk => (f.parts[pk].label ? f.parts[pk].label.replace(/\s*\(.*\)\s*$/, '').replace(/\?$/, '') + ': ' : '') + optLabel(f, pk, v[pk])).join(' · ') || '–';
    }
    if (typeof v === 'object') return JSON.stringify(v);
    return String(optLabel(f, null, v));
  }

  /* ---------------------------------------------------- AJAX */
  async function call(action, data, get) {
    let r;
    if (get) {
      const q = new URLSearchParams(Object.assign({ action: action, t: C.t }, data || {}));
      r = await fetch(C.ajax + '?' + q.toString(), { credentials: 'same-origin' });
    } else {
      const fd = new FormData();
      fd.append('action', action); fd.append('t', C.t);
      Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
      r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
    }
    let j;
    try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
    if (!j.success) throw new Error(j.data && j.data.msg === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : (j.data && j.data.msg) || 'Hiba');
    return j.data;
  }
  async function copy(text) {
    try { await navigator.clipboard.writeText(text); return true; } catch (e) {
      const t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select();
      let ok = false; try { ok = document.execCommand('copy'); } catch (x) { ok = false; }
      t.remove(); return ok;
    }
  }

  /* ---------------------------------------------------- szerkesztők (iroda) */
  function input(name, p, v) {
    const val = v == null ? '' : v;
    if (p.kind === 'enum') return '<select data-in="' + esc(name) + '"><option value="">–</option>' + Object.keys(p.options || {}).map(o => '<option value="' + esc(o) + '"' + (String(val) === o ? ' selected' : '') + '>' + esc(p.options[o]) + '</option>').join('') + '</select>';
    if (p.kind === 'textarea') return '<textarea data-in="' + esc(name) + '" rows="2" maxlength="' + (p.max || 2000) + '">' + esc(val) + '</textarea>';
    if (p.kind === 'time') return '<input type="time" step="300" data-in="' + esc(name) + '" value="' + esc(val) + '">';
    if (p.kind === 'date') return '<input type="date" data-in="' + esc(name) + '" value="' + esc(val) + '">';
    if (p.kind === 'consent') return '<label class="chk"><input type="checkbox" data-in="' + esc(name) + '"' + (val && val.given ? ' checked' : '') + '> hozzájárult</label>';
    return '<input type="text" data-in="' + esc(name) + '" value="' + esc(val) + '"' + (p.max ? ' maxlength="' + p.max + '"' : '') + (p.kind === 'int' ? ' inputmode="numeric"' : '') + (p.kind === 'bg' ? ' placeholder="a háttér pontos neve"' : '') + '>';
  }
  function editor(f, v, meta) {
    let h = '<div class="pp-f" data-key="' + esc(f.key) + '"><div class="pp-fh"><b>' + esc(f.label) + '</b>' + (f.required ? '<span class="pp-req">kötelező</span>' : '') +
      '<small>' + (meta ? esc(BY[meta.by] || meta.by) + ', ' + esc(dt(meta.at)) : 'nincs kitöltve') + '</small></div><div class="pp-in">';
    if (f.parts) {
      Object.keys(f.parts).forEach(pk => {
        const p = f.parts[pk];
        h += '<label' + (p.kind === 'textarea' ? ' class="wide"' : '') + '>' + esc(p.label || 'Válasz') + input(pk, p, v && typeof v === 'object' ? v[pk] : '') + '</label>';
      });
    } else {
      h += '<label class="wide">' + input('', { kind: f.kind, options: f.options, max: f.max }, f.kind === 'consent' ? v : (typeof v === 'object' ? '' : v)) + '</label>';
    }
    if (f.note) h += '<small class="wide pp-note">' + esc(f.note) + '</small>';
    h += '</div><div class="pp-fa"><button type="button" class="cpx-btn ghost sm" data-save>Mentés</button><span class="pp-fm"></span></div></div>';
    return h;
  }
  function readEditor(box, f) {
    if (f.kind === 'consent') return { given: box.querySelector('[data-in]').checked };
    if (f.parts) {
      const o = {};
      box.querySelectorAll('[data-in]').forEach(i => { o[i.getAttribute('data-in')] = i.value; });
      return o;
    }
    return box.querySelector('[data-in]').value;
  }
  const activeField = (f, answers) => !f.show_if || String(answers[f.show_if[0]] || '') === f.show_if[1];

  /* ---------------------------------------------------- „Szerződés adatai” */
  function contractHtml(p) {
    const c = p.contract || {};
    const has = (c.groups || []).some(g => g[1] && g[1].length);
    let h = '<div class="pp-con"><div class="pp-con-h"><h4>Szerződés adatai' + (c.type ? ' <small>' + (c.type === 'ceg' ? 'cég' : 'magánszemély') + '</small>' : '') + '</h4>';
    if (has) h += '<button type="button" class="cpx-btn sm" data-copyall>Összes másolása</button>';
    h += '</div>';
    const st = c.missing && c.missing.length ? '<span class="pp-st hianyos">Hiányzik: ' + esc(c.missing.join(', ')) + '</span>'
      : c.ready ? '<span class="pp-st ' + (c.changed ? 'modositva' : 'kesz') + '">' + (c.changed ? 'Módosult a jelzés óta (' + esc(dt(c.changed)) + ')' : '✓ Mehet a szerződés – ' + esc(dt(c.ready))) + '</span>'
      : '<span class="pp-st modositva">Kitöltve, még nincs beküldve</span>';
    h += '<p class="pp-con-st">' + st + (p.flow === 'v1' ? ' <small class="cpx-muted">(a portál a szerződés után jött létre – ezek az adatok nem kötelezők)</small>' : '') + '</p>';
    if (!has) return h + '<p class="cpx-muted">Az ügyfél még nem adta meg a szerződés adatait.</p></div>';
    (c.groups || []).forEach((g, gi) => {
      if (!g[1] || !g[1].length) return;
      h += '<div class="pp-cg"><div class="pp-cgh">' + esc(g[0]) + '</div>' + g[1].map((r, ri) => '<div class="pp-cr"><span>' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b><button type="button" class="pp-cp" data-copy="' + gi + '.' + ri + '" title="Másolás">⧉</button></div>').join('') + '</div>';
    });
    return h + '</div>';
  }

  /* ---------------------------------------------------- fotósablon */
  function sablonHtml(p) {
    const s = p.sablon, c = p.custom;
    if (c) {
      return '<h4>Egyedi fotósablont kér <small class="cpx-muted">– díja ' + esc(c.fee) + '</small></h4>' +
        '<div class="pp-sb">' + (c.logo ? '<a href="' + esc(sbUrl(c.logo)) + '" target="_blank" rel="noopener" download="' + esc(c.logoName || 'logo') + '"><img src="' + esc(sbUrl(c.logo)) + '" alt="Az ügyfél logója" loading="lazy"></a>' : '') +
        '<div><p style="white-space:pre-line">' + (c.brief ? esc(c.brief) : '<span class="cpx-muted">Még nem írta le, mit szeretne.</span>') + '</p>' +
        (c.logo ? '<p class="cpx-muted">Logó: ' + esc(c.logoName) + ' – kattints a képre a letöltéshez.</p>' : '<p class="cpx-muted">Logót nem töltött fel.</p>') + '</div></div>';
    }
    if (!s) return '<h4>Fotósablon</h4><p class="cpx-muted">Az ügyfél még nem mentett sablont.</p>';
    return '<h4>Fotósablon <small class="cpx-muted">– az ügyfél mentette: ' + esc(dt(s.at)) + (s.name ? ' · ' + esc(s.name) : '') + '</small></h4>' +
      '<div class="pp-sb">' + (s.preview ? '<a href="' + esc(sbUrl(s.preview)) + '" target="_blank" rel="noopener"><img src="' + esc(sbUrl(s.preview)) + '" alt="Sablon-előnézet" loading="lazy"></a>' : '') +
      '<div><p class="cpx-muted">A Sablonkészítőben az ügyfél beállításaival nyílik meg (sablon, felirat' + (s.logo ? ', logó' : '') + ') – onnan töltsd le a lyukasztott PNG-t.</p>' +
      (window.CPSablon ? '<button type="button" class="cpx-btn sm" data-act="sablon">Megnyitás a Sablonkészítőben</button>' : '') + '</div></div>';
  }
  async function openSablon(p) {
    const s = p.sablon;
    if (!s || !s.snap) return;
    try { localStorage.setItem('cpsb_' + M.id, JSON.stringify(Object.assign({}, s.snap, { at: Date.now() }))); } catch (e) { /* tele */ }
    if (s.logo) {
      try {
        const r = await fetch(sbUrl(s.logo), { credentials: 'same-origin' });
        const b = await r.blob();
        const u = await new Promise(res => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(b); });
        localStorage.setItem('cpsb_logo_' + M.id, u);
      } catch (e) { /* a logót kézzel kell feltölteni */ }
    } else {
      try { localStorage.removeItem('cpsb_logo_' + M.id); } catch (e) { /* */ }
    }
    if (window.CPPortal.openSablon) window.CPPortal.openSablon(M.id);
  }

  /* ---------------------------------------------------- részletező */
  const M = { id: '', title: '', data: null, url: '', state: '' };

  function modal() {
    const dlg = document.getElementById('cpx-modal');
    if (!dlg) return null;
    dlg.hidden = false;
    return dlg;
  }

  async function open(id, title, e) {
    M.id = String(id); M.title = title || ('Deal #' + id); M.url = ''; M.state = e ? e.state : '';
    const dlg = modal();
    if (!dlg) return;
    dlg.innerHTML = '<div class="cpx-sheet wide pp"><div class="cpx-sheet-h"><h3>' + esc(M.title) + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div><p class="cpx-muted">Ügyfélportál betöltése…</p></div>';
    try { M.data = await call('cp_portal_p_get', { id: M.id }, true); draw(); }
    catch (x) { dlg.querySelector('.cpx-muted').textContent = x.message; }
  }

  function sendBox(again) {
    const d = M.data;
    if (d.mode === 'off') return '<p class="cpx-muted">Az ügyfélportál ki van kapcsolva (WP admin → Beállítások → Ügyfélportál).</p>';
    return '<div class="pp-send"><label>Megrendelő<select data-type><option value="">az ügyfél választja</option><option value="magan">magánszemély</option><option value="ceg">cég</option></select></label>' +
      '<button type="button" class="cpx-btn" data-act="send">' + (again ? 'Ügyfélportál újraküldése' : 'Ügyfélportál kiküldése') + '</button></div>' +
      '<p class="cpx-muted pp-small">' + (again ? 'Új belépőlinket küld e-mailben (a régi is él tovább).' : 'Létrehozza a portált, elküldi a meghívó levelet a belépőlinkkel' + (d.sentStage ? ', és a dealt a Salesmate-ben a „' + esc(d.sentStage) + '” szakaszba teszi (csak ajánlati szakaszból)' : '') + '.') + '</p>';
  }

  function draw(msg) {
    const dlg = document.getElementById('cpx-modal');
    if (!dlg || dlg.hidden || !M.data) return;
    const d = M.data, p = d.portal;
    let h = '<div class="cpx-sheet wide pp"><div class="cpx-sheet-h"><h3>' + esc(M.title) + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>';
    if (msg) h += '<p class="pp-msg">' + esc(msg) + '</p>';
    if (!p) {
      h += '<p>Ehhez a dealhez még nincs ügyfélportál.</p>' + sendBox(false);
    } else {
      const s = p.state;
      h += '<div class="pp-sum"><span class="pp-st ' + esc(s.st) + '">' + esc(ST[s.st] || s.st) + '</span> <b>' + s.done + '/' + s.total + '</b> kötelező kérdés' +
        (p.deadline ? ' · határidő: <b>' + esc(p.deadline.text) + '</b>' + (p.deadline.late ? ' (késői szerződés)' : '') + (p.deadline.passed ? ' · <span class="bad">lezárva az ügyfél előtt</span>' : '') : '') +
        '<br><small class="cpx-muted">Ügyfél: ' + esc([p.sm.name, p.sm.email, p.sm.phone].filter(Boolean).join(' · ') || '–') + ' · portál létrehozva: ' + esc(dt(p.created)) + (p.flow === 'v2' ? ' (kézi kiküldés)' : ' (automatikusan, a szerződés után)') + (s.completed ? ' · „Kész”: ' + esc(dt(s.completed)) : '') + '</small></div>';
      h += flags(p) ? '<div class="pp-flags">' + flags(p) + '</div>' : '';
      h += contractHtml(p);
      if (p.missing.length) h += '<p class="pp-miss">Hiányzik: ' + esc(p.missing.join(', ')) + '</p>';
      h += sablonHtml(p);
      h += '<h4>Kiküldés, belépőlink</h4>' + sendBox(true) + '<div class="pp-acts"><button type="button" class="cpx-btn ghost sm" data-act="new">Új link másolása</button><button type="button" class="cpx-btn ghost sm danger" data-act="revoke">Linkek visszavonása</button></div>';
      if (M.url) h += '<div class="pp-url"><input readonly value="' + esc(M.url) + '"><button type="button" class="cpx-btn sm" data-act="copy">Másolás</button></div><p class="cpx-muted pp-small">Személyes link – csak az ügyfélnek küldd el. Ezt a linket később nem tudjuk újra megmutatni (csak a lenyomata tárolódik).</p>';
      h += '<h4>Válaszok <small class="cpx-muted">– irodai szerkesztés' + (p.deadline && p.deadline.passed ? '; a határidő után minden módosítás „MÓDOSÍTVA” címkét és Pumble-értesítést ad' : '') + '</small></h4>';
      let stg = '';
      SCHEMA.forEach(f => {
        if (f.kind === 'sablon' || f.kind === 'fixed' || !activeField(f, p.answers)) return;
        if (f.stage !== stg) { stg = f.stage; h += '<div class="pp-sec">' + esc(STAGE[stg] || stg) + '</div>'; }
        const v = p.answers[f.key] !== undefined ? p.answers[f.key] : (p.prefill && p.prefill[f.key]) || null;
        h += editor(f, v, p.meta[f.key]);
      });
      h += '<h4>Változásnapló</h4>' + (p.log.length ? '<table class="pp-log"><tbody>' + p.log.map(l => '<tr><td>' + esc(dt(l.t)) + '</td><td>' + esc((field(l.f) || {}).qlabel || l.f) + '<small>' + esc(BY[l.by] || l.by) + '</small></td><td>' + esc(valText(l.f, l.o)) + ' → <b>' + esc(valText(l.f, l.n)) + '</b></td></tr>').join('') + '</tbody></table>' : '<p class="cpx-muted">Még nincs változás.</p>');
      h += '<h4>Értesítések</h4>' + (p.notes.length ? '<ul class="pp-ul">' + p.notes.map(n => '<li>' + esc(dt(n.t)) + ' · ' + esc(n.type.split(':')[0]) + ' (' + esc(n.ch) + ') · ' + esc(n.r) + '</li>').join('') + '</ul>' : '<p class="cpx-muted">Még nem ment ki értesítés.</p>');
      h += '<h4>Linkek</h4>' + (p.tokens.length ? '<ul class="pp-ul">' + p.tokens.map(t => '<li>' + esc(dt(t.c)) + ' · ' + (t.r ? '<span class="bad">visszavonva ' + esc(dt(t.r)) + '</span>' : t.x * 1000 < Date.now() ? 'lejárt' : 'érvényes ' + esc(dt(t.x)) + '-ig') + (t.u ? ' · utoljára használva ' + esc(dt(t.u)) : ' · még nem használták') + '</li>').join('') + '</ul>' : '<p class="cpx-muted">Nincs link.</p>');
    }
    if (d.errors && d.errors.length) h += '<h4>Hibák</h4><ul class="pp-ul bad">' + d.errors.map(e => '<li>' + esc(dt(e.t)) + ' · ' + esc(e.k) + ': ' + esc(e.m) + '</li>').join('') + '</ul>';
    h += '</div>';
    dlg.innerHTML = h;
    const sheet = dlg.querySelector('.pp');
    sheet.addEventListener('click', onClick);
  }

  async function onClick(ev) {
    const b = ev.target.closest('[data-act],[data-save],[data-copy],[data-copyall]');
    if (!b) return;
    const p = M.data && M.data.portal;
    try {
      if (b.hasAttribute('data-copy') || b.hasAttribute('data-copyall')) {
        const c = p.contract;
        let text;
        if (b.hasAttribute('data-copyall')) text = c.text;
        else { const x = b.getAttribute('data-copy').split('.'); text = c.groups[+x[0]][1][+x[1]][1]; }
        const ok = await copy(text);
        const old = b.textContent;
        b.textContent = ok ? '✓' : '!';
        if (b.hasAttribute('data-copyall')) b.textContent = ok ? '✓ Kimásolva' : 'Nem sikerült';
        setTimeout(() => { b.textContent = old; }, 1500);
        return;
      }
      if (b.hasAttribute('data-save')) {
        const box = b.closest('.pp-f'), f = field(box.getAttribute('data-key'));
        const m = box.querySelector('.pp-fm');
        b.disabled = true; m.textContent = 'Mentés…'; m.className = 'pp-fm';
        try {
          M.data = await call('cp_portal_p_save', { id: M.id, key: f.key, value: JSON.stringify(readEditor(box, f)) });
          draw('Mentve: ' + f.qlabel);
          if (window.CPPortal.onChange) window.CPPortal.onChange(M.id, M.data);
        } catch (x) { b.disabled = false; m.textContent = x.message; m.className = 'pp-fm bad'; }
        return;
      }
      const act = b.getAttribute('data-act');
      if (act === 'copy') {
        const i = b.parentNode.querySelector('input');
        i.select();
        await copy(i.value);
        b.textContent = 'Kimásolva ✓';
        return;
      }
      if (act === 'sablon') { await openSablon(p); return; }
      if (act === 'send') {
        const sel = b.parentNode.querySelector('[data-type]');
        const type = sel ? sel.value : '';
        const to = (p && p.sm && p.sm.email) || 'a deal kapcsolattartójának e-mail-címére';
        if (!confirm((p ? 'Újraküldöd az ügyfélportált (' + to + ')?\n\nÚj belépőlink megy ki e-mailben.' : 'Kiküldöd az ügyfélportált?\n\nAz ügyfél e-mailben megkapja a belépőlinket' + (M.data.sentStage ? ', a deal pedig a Salesmate-ben a „' + M.data.sentStage + '” szakaszba kerül (ha még ajánlati szakaszban van)' : '') + '.'))) return;
        b.disabled = true;
        M.data = await call('cp_portal_p_send', { id: M.id, type: type });
        draw(M.data.msg || 'Kiküldve.');
        if (window.CPPortal.onChange) window.CPPortal.onChange(M.id, M.data);
        return;
      }
      if (act === 'revoke' && !confirm('Visszavonod az összes eddigi belépőlinket?\n\nAz ügyfél a régi linkkel nem tud belépni, de a lejárt-link oldalon kérhet újat.')) return;
      b.disabled = true;
      const r = await call('cp_portal_p_link', { id: M.id, op: act });
      M.data = r;
      if (act === 'new') M.url = r.url || '';
      draw(r.msg || (act === 'new' ? 'Új link készült – másold ki.' : ''));
    } catch (x) {
      b.disabled = false;
      alert('Nem sikerült: ' + x.message);
    }
  }

  window.CPPortal = { flags: flags, cell: cell, info: info, open: open, onChange: null, openSablon: null };

  // Pumble-linkből érkezve (?portal=ID) rögtön megnyitjuk a részletezőt
  if (P.open) {
    const go = () => { if (document.getElementById('cpx-modal')) open(P.open, 'Deal #' + P.open); else setTimeout(go, 300); };
    setTimeout(go, 600);
  }
})();
