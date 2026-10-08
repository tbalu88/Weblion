/* Crazy panel – ügyfélportál: címkék, „Adatlap” oszlop, portál-részletező (irodai szerkesztés, link-műveletek)
 * A panel.js hívja: window.CPPortal.flags(), .cell(), .info(), .open() */
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
  const field = k => SCHEMA.find(f => f.key === k);

  /* ---------------------------------------------------- címkék és cellák */
  function flags(p, where) {
    if (!p || !p.flags) return '';
    const l = p.flags.filter(f => !where || f.where.indexOf(where) >= 0);
    return l.length ? '<span class="cpp-flags">' + l.map(f => '<span class="cpp-flag ' + esc(f.tone) + '">' + esc(f.text) + '</span>').join('') + '</span>' : '';
  }

  /* „Adatlap” oszlop a Követés fülön */
  function cell(p) {
    if (!p) return '<button type="button" class="pp-cell none" data-portal title="Ügyfélportál – nincs még létrehozva">–</button>';
    const cls = p.st === 'kesz' ? 'ok' : p.st === 'modositva' ? 'mod' : 'bad';
    const t = p.st === 'kesz' ? '✓ Kész' : p.st === 'modositva' ? 'Módosítva' : p.done + '/' + p.total;
    const warn = (p.flags || []).filter(f => f.code !== 'hianyos' && f.where.indexOf('munkalap') >= 0).length;
    return '<button type="button" class="pp-cell ' + cls + '" data-portal title="' + esc('Adatlap: ' + (ST[p.st] || p.st) + ' · ' + p.done + '/' + p.total + (p.dl ? '\nHatáridő: ' + p.dl : '') + (p.locked ? ' (lezárva)' : '')) + '">' + esc(t) + (warn ? ' <i>⚠' + warn + '</i>' : '') + '</button>';
  }

  /* napi munkalap: sorok a kártyába (címke, érték, html-e) */
  function info(p) {
    if (!p || !p.info) return [];
    const i = p.info, rows = [];
    if (i.venue) rows.push(['Helyszín (adatlap)', esc(i.venue) + (i.room ? ' – ' + esc(i.room) : '') + (i.maps ? ' <a href="' + esc(i.maps) + '" target="_blank" rel="noopener">térkép ↗</a>' : ''), true]);
    if (i.access) rows.push(['Bejutás', i.access]);
    if (i.contact && i.contact.name) rows.push(['Helyszíni kapcsolattartó', esc(i.contact.name) + (i.contact.tel ? ' · <a href="tel:' + esc(i.contact.tel) + '">' + esc(i.contact.phone) + '</a>' : ''), true]);
    if (i.schedule) rows.push(['Menetrend', [i.schedule.arrival ? 'érkezés ' + i.schedule.arrival : '', i.schedule.start ? 'indulás ' + i.schedule.start : '', i.schedule.end ? 'zárás ' + i.schedule.end : ''].filter(Boolean).join(' · ')]);
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

  /* ---------------------------------------------------- szerkesztők (iroda) */
  function input(name, p, v) {
    const val = v == null ? '' : v;
    if (p.kind === 'enum') return '<select data-in="' + esc(name) + '"><option value="">–</option>' + Object.keys(p.options || {}).map(o => '<option value="' + esc(o) + '"' + (String(val) === o ? ' selected' : '') + '>' + esc(p.options[o]) + '</option>').join('') + '</select>';
    if (p.kind === 'textarea') return '<textarea data-in="' + esc(name) + '" rows="3" maxlength="' + (p.max || 2000) + '">' + esc(val) + '</textarea>';
    if (p.kind === 'time') return '<input type="time" step="300" data-in="' + esc(name) + '" value="' + esc(val) + '">';
    if (p.kind === 'consent') return '<label class="chk"><input type="checkbox" data-in="' + esc(name) + '"' + (val && val.given ? ' checked' : '') + '> hozzájárult</label>';
    return '<input type="text" data-in="' + esc(name) + '" value="' + esc(val) + '"' + (p.max ? ' maxlength="' + p.max + '"' : '') + (p.kind === 'int' ? ' inputmode="numeric"' : '') + '>';
  }
  function editor(f, v, meta) {
    let h = '<div class="pp-f" data-key="' + esc(f.key) + '"><div class="pp-fh"><b>' + esc(f.label) + '</b>' + (f.required ? '<span class="pp-req">kötelező</span>' : '') +
      '<small>' + (meta ? esc(BY[meta.by] || meta.by) + ', ' + esc(dt(meta.at)) : 'nincs kitöltve') + '</small></div><div class="pp-in">';
    if (f.parts) {
      Object.keys(f.parts).forEach(pk => {
        const p = f.parts[pk];
        h += '<label>' + esc(p.label || 'Válasz') + input(pk, p, v && typeof v === 'object' ? v[pk] : '') + '</label>';
      });
    } else {
      h += '<label class="wide">' + input('', { kind: f.kind, options: f.options, max: f.max }, f.kind === 'consent' ? v : (typeof v === 'object' ? '' : v)) + '</label>';
    }
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

  /* ---------------------------------------------------- részletező */
  const M = { id: '', title: '', data: null, url: '' };

  function modal() {
    const dlg = document.getElementById('cpx-modal');
    if (!dlg) return null;
    dlg.hidden = false;
    return dlg;
  }

  async function open(id, title) {
    M.id = String(id); M.title = title || ('Deal #' + id); M.url = '';
    const dlg = modal();
    if (!dlg) return;
    dlg.innerHTML = '<div class="cpx-sheet wide pp"><div class="cpx-sheet-h"><h3>' + esc(M.title) + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div><p class="cpx-muted">Ügyfélportál betöltése…</p></div>';
    try { M.data = await call('cp_portal_p_get', { id: M.id }, true); draw(); }
    catch (e) { dlg.querySelector('.cpx-muted').textContent = e.message; }
  }

  function draw(msg) {
    const dlg = document.getElementById('cpx-modal');
    if (!dlg || dlg.hidden || !M.data) return;
    const d = M.data, p = d.portal;
    let h = '<div class="cpx-sheet wide pp"><div class="cpx-sheet-h"><h3>' + esc(M.title) + '</h3><button class="cpx-x" data-close aria-label="Bezárás">×</button></div>';
    if (msg) h += '<p class="pp-msg">' + esc(msg) + '</p>';
    if (!p) {
      h += '<p>Ehhez a dealhez még nincs ügyfélportál.</p>';
      if (d.mode === 'off') h += '<p class="cpx-muted">Az ügyfélportál ki van kapcsolva (WP admin → Beállítások → Ügyfélportál).</p>';
      else h += '<p class="cpx-muted">' + (d.mode === 'selected' ? 'Bevezetés alatt: csak a kiválasztott dealek kapnak portált. ' : '') + 'A létrehozással az ügyfél azonnal megkapja az üdvözlő levelet a belépőlinkkel.</p><p><button type="button" class="cpx-btn" data-act="create">Portál bekapcsolása ennél a dealnél</button></p>';
    } else {
      const s = p.state;
      h += '<div class="pp-sum"><span class="pp-st ' + esc(s.st) + '">' + esc(ST[s.st] || s.st) + '</span> <b>' + s.done + '/' + s.total + '</b> kötelező kérdés' +
        (p.deadline ? ' · határidő: <b>' + esc(p.deadline.text) + '</b>' + (p.deadline.late ? ' (késői szerződés)' : '') + (p.deadline.passed ? ' · <span class="bad">lezárva az ügyfél előtt</span>' : '') : '') +
        '<br><small class="cpx-muted">Ügyfél: ' + esc([p.sm.name, p.sm.email, p.sm.phone].filter(Boolean).join(' · ') || '–') + ' · portál létrehozva: ' + esc(dt(p.created)) + (s.completed ? ' · „Kész”: ' + esc(dt(s.completed)) : '') + '</small></div>';
      h += flags(p) ? '<div class="pp-flags">' + flags(p) + '</div>' : '';
      if (p.missing.length) h += '<p class="pp-miss">Hiányzik: ' + esc(p.missing.join(', ')) + '</p>';
      h += '<h4>Belépőlink</h4><div class="pp-acts"><button type="button" class="cpx-btn ghost sm" data-act="new">Új link másolása</button><button type="button" class="cpx-btn ghost sm" data-act="send">Link újraküldése e-mailben</button><button type="button" class="cpx-btn ghost sm danger" data-act="revoke">Linkek visszavonása</button></div>';
      if (M.url) h += '<div class="pp-url"><input readonly value="' + esc(M.url) + '"><button type="button" class="cpx-btn sm" data-act="copy">Másolás</button></div><p class="cpx-muted pp-small">Személyes link – csak az ügyfélnek küldd el. Ezt a linket később nem tudjuk újra megmutatni (csak a lenyomata tárolódik).</p>';
      h += '<h4>Válaszok <small class="cpx-muted">– irodai szerkesztés' + (p.deadline && p.deadline.passed ? '; a határidő után minden módosítás „MÓDOSÍTVA” címkét és Pumble-értesítést ad' : '') + '</small></h4>';
      let sec = '';
      SCHEMA.forEach(f => {
        if (f.section !== sec) { sec = f.section; h += '<div class="pp-sec">' + esc({ helyszin: 'Helyszín', kapcsolat: 'Kapcsolattartó, menetrend', opcionalis: 'Nem kötelező', vege: 'Adatmegőrzés' }[sec] || sec) + '</div>'; }
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
    const b = ev.target.closest('[data-act],[data-save]');
    if (!b) return;
    const p = M.data && M.data.portal;
    try {
      if (b.hasAttribute('data-save')) {
        const box = b.closest('.pp-f'), f = field(box.getAttribute('data-key'));
        const m = box.querySelector('.pp-fm');
        b.disabled = true; m.textContent = 'Mentés…'; m.className = 'pp-fm';
        try {
          M.data = await call('cp_portal_p_save', { id: M.id, key: f.key, value: JSON.stringify(readEditor(box, f)) });
          draw('Mentve: ' + f.qlabel);
          if (window.CPPortal.onChange) window.CPPortal.onChange(M.id, M.data);
        } catch (e) { b.disabled = false; m.textContent = e.message; m.className = 'pp-fm bad'; }
        return;
      }
      const act = b.getAttribute('data-act');
      if (act === 'copy') {
        const i = b.parentNode.querySelector('input');
        i.select();
        try { await navigator.clipboard.writeText(i.value); } catch (e) { document.execCommand('copy'); }
        b.textContent = 'Kimásolva ✓';
        return;
      }
      if (act === 'create') {
        if (!confirm('Bekapcsolod az ügyfélportált ennél a dealnél?\n\nAz ügyfél azonnal megkapja az üdvözlő levelet a belépőlinkkel.')) return;
        b.disabled = true;
        M.data = await call('cp_portal_p_create', { id: M.id });
        draw('A portál létrejött' + (M.data.portal && M.data.portal.sm && M.data.portal.sm.email ? ', az üdvözlő levél kiment.' : ' – a dealnek nincs e-mail-címe, a linket másold ki és küldd el.'));
        if (window.CPPortal.onChange) window.CPPortal.onChange(M.id, M.data);
        return;
      }
      if (act === 'revoke' && !confirm('Visszavonod az összes eddigi belépőlinket?\n\nAz ügyfél a régi linkkel nem tud belépni, de a lejárt-link oldalon kérhet újat.')) return;
      if (act === 'send' && !confirm('Új belépőlinket küldesz az ügyfélnek e-mailben (' + ((p && p.sm && p.sm.email) || 'nincs e-mail-cím') + ')?')) return;
      b.disabled = true;
      const r = await call('cp_portal_p_link', { id: M.id, op: act });
      M.data = r;
      if (act === 'new') M.url = r.url || '';
      draw(r.msg || (act === 'new' ? 'Új link készült – másold ki.' : ''));
    } catch (e) {
      b.disabled = false;
      alert('Nem sikerült: ' + e.message);
    }
  }

  window.CPPortal = { flags: flags, cell: cell, info: info, open: open, onChange: null };

  // Pumble-linkből érkezve (?portal=ID) rögtön megnyitjuk a részletezőt
  if (P.open) {
    const go = () => { if (document.getElementById('cpx-modal')) open(P.open, 'Deal #' + P.open); else setTimeout(go, 300); };
    setTimeout(go, 600);
  }
})();
