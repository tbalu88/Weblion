/* EventCrazy ügyfélportál – rendezvény-adatlap: mezőnkénti automatikus mentés
 * - választáskor és a mező elhagyásakor azonnal, szöveges mezőnél 800 ms gépelési szünet után ment
 * - a „Mentve” jelzés csak sikeres szerverválasz után jelenik meg
 * - kapcsolat nélkül a válasz a böngészőben marad (localStorage), és visszatéréskor újraküldjük */
(function () {
  'use strict';
  var C = window.CPP || {};
  var form = document.getElementById('cpp-form');
  if (!form) return;
  var LS = 'cpp_pending_' + C.pid;
  var locked = !!C.locked;
  var S = {};          // mezőkulcs → { saved, timer, busy, again, wait }
  var retryT = null, retryMs = 3000;

  function $all(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }
  function fieldEls() { return $all(form, '[data-field]'); }
  function byKey(k) { return form.querySelector('[data-field="' + k + '"]'); }

  /* ---------------------------------------------------- olvasás / beírás */
  function readPart(box) {
    var k = box.getAttribute('data-pkind');
    if (k === 'enum') { var c = box.querySelector('input[type=radio]:checked'); return c ? c.value : ''; }
    if (k === 'consent') { return !!box.querySelector('input[type=checkbox]').checked; }
    var i = box.querySelector('input,textarea');
    return i ? i.value : '';
  }
  function read(fd) {
    var kind = fd.getAttribute('data-kind');
    if (kind === 'consent') return { given: !!fd.querySelector('input[type=checkbox]').checked };
    if (kind === 'object') {
      var o = {};
      $all(fd, '[data-part]').forEach(function (p) { o[p.getAttribute('data-part')] = p.hidden ? '' : readPart(p); });
      return o;
    }
    return readPart(fd.querySelector('[data-part]'));
  }
  function writePart(box, v) {
    var k = box.getAttribute('data-pkind');
    if (k === 'enum') { $all(box, 'input[type=radio]').forEach(function (r) { r.checked = String(v) === r.value; }); return; }
    var i = box.querySelector('input,textarea');
    if (i) i.value = v == null ? '' : String(v);
  }
  function write(fd, v) {
    var kind = fd.getAttribute('data-kind');
    if (kind === 'consent') { fd.querySelector('input[type=checkbox]').checked = !!(v && v.given); return; }
    if (kind === 'object') { $all(fd, '[data-part]').forEach(function (p) { writePart(p, v && v[p.getAttribute('data-part')]); }); showIf(fd); return; }
    writePart(fd.querySelector('[data-part]'), v);
  }
  /* kötelező, látható, de üres rész (pl. a helyszín címe még nincs beírva) */
  function incomplete(fd) {
    return $all(fd, '[data-part][data-req]').some(function (p) { return !p.hidden && String(readPart(p)).trim() === ''; });
  }
  function blank(v) {
    if (v == null || v === '' || v === false) return true;
    if (typeof v === 'object') { for (var k in v) { if (!blank(v[k])) return false; } return true; }
    return String(v).trim() === '';
  }
  function showIf(fd) {
    $all(fd, '[data-show-if]').forEach(function (p) {
      var x = p.getAttribute('data-show-if').split('=');
      var src = fd.querySelector('[data-part="' + x[0] + '"]');
      p.hidden = !src || readPart(src) !== x[1];
    });
  }

  /* ---------------------------------------------------- jelzések */
  function mark(fd, cls, text) {
    var s = fd.querySelector('.cpp-saved');
    if (!s) return;
    s.className = 'cpp-saved' + (cls ? ' ' + cls : '');
    s.textContent = text || '';
  }
  function err(fd, msg, part) {
    var e = fd.querySelector('.cpp-ferr');
    $all(fd, '.bad').forEach(function (x) { x.classList.remove('bad'); });
    if (!e) return;
    if (!msg) { e.hidden = true; e.textContent = ''; fd.classList.remove('has-err'); return; }
    e.textContent = msg; e.hidden = false; fd.classList.add('has-err');
    var p = part ? fd.querySelector('[data-part="' + part + '"]') : null;
    if (p) p.classList.add('bad');
  }
  function progress(b) {
    if (!b || b.required_done == null) return;
    var d = document.getElementById('cpp-done'), bar = document.getElementById('cpp-bar');
    if (d) d.textContent = b.required_done;
    if (bar) bar.style.width = Math.round(b.required_done / (b.required_total || C.total || 7) * 100) + '%';
  }
  function banner(msg) {
    var st = document.getElementById('cpp-status');
    if (!st) return;
    var b = document.getElementById('cpp-banner');
    if (!b) { b = document.createElement('p'); b.id = 'cpp-banner'; b.className = 'cpp-lock'; st.appendChild(b); }
    b.textContent = msg;
  }
  function lockUI(msg) {
    locked = true;
    form.classList.add('locked');
    $all(form, 'input,textarea,button').forEach(function (x) { x.disabled = true; });
    banner(msg || 'A határidő lejárt, az adatlap már csak olvasható. Változás esetén írj nekünk.');
    pendingClear();
  }

  /* ---------------------------------------------------- függő mentések (offline) */
  function pendingGet() { try { return JSON.parse(localStorage.getItem(LS) || '{}') || {}; } catch (e) { return {}; } }
  function pendingSet(o) { try { if (Object.keys(o).length) localStorage.setItem(LS, JSON.stringify(o)); else localStorage.removeItem(LS); } catch (e) { /* privát mód */ } }
  function pendingPut(k, v) { var o = pendingGet(); o[k] = v; pendingSet(o); }
  function pendingDel(k) { var o = pendingGet(); delete o[k]; pendingSet(o); }
  function pendingClear() { pendingSet({}); }
  function retryLater() {
    if (retryT) return;
    retryT = setTimeout(function () { retryT = null; retryMs = Math.min(retryMs * 2, 60000); flushPending(); }, retryMs);
  }
  function flushPending() {
    var o = pendingGet();
    Object.keys(o).forEach(function (k) { var fd = byKey(k); if (fd) save(fd, true); });
  }

  /* ---------------------------------------------------- mentés */
  function st(fd) {
    var k = fd.getAttribute('data-field');
    if (!S[k]) S[k] = { saved: null };
    return S[k];
  }
  function later(fd, ms) {
    var s = st(fd);
    clearTimeout(s.timer);
    s.timer = setTimeout(function () { s.timer = null; save(fd); }, ms);
  }
  function save(fd, force) {
    var s = st(fd), key = fd.getAttribute('data-field');
    clearTimeout(s.timer); s.timer = null;
    if (locked) return Promise.resolve();
    var v = read(fd), j = JSON.stringify(v);
    if (!force && j === s.saved) return Promise.resolve();
    if (s.saved === null && (blank(v) || j === s.initial)) return Promise.resolve();   // üres, vagy csak az előtöltés van benne: nincs mit menteni
    if (s.busy) { s.again = true; return s.wait; }
    s.busy = true;
    pendingPut(key, v);
    mark(fd, 'busy', 'Mentés…');
    s.wait = fetch(C.rest + '/' + encodeURIComponent(key), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CP-Nonce': C.nonce, 'X-HTTP-Method-Override': 'PATCH' },
      body: JSON.stringify({ value: v })
    }).then(function (r) {
      return r.json().then(function (b) { return { r: r, b: b }; }, function () { return { r: r, b: null }; });
    }).then(function (x) {
      var b = x.b || {};
      if (x.r.ok && b.ok) {
        s.saved = j;
        pendingDel(key);
        retryMs = 3000;
        mark(fd, 'ok', 'Mentve');
        err(fd, '');
        fd.classList.remove('miss');
        progress(b);
      } else if (x.r.status === 423) {
        lockUI(b.message);
      } else if (x.r.status === 401 || x.r.status === 403) {
        mark(fd, 'bad', 'Nincs mentve');
        banner(b.message || 'A belépés lejárt – töltsd újra az oldalt, vagy nyisd meg újra az e-mailben kapott linket.');
      } else if (x.r.status >= 500 || !x.b) {
        mark(fd, 'bad', 'Nincs mentve – újrapróbálom');
        retryLater();
      } else {
        // hibás érték: nem próbáljuk újra, amíg nem javítják
        pendingDel(key);
        mark(fd, 'bad', 'Nincs mentve');
        err(fd, b.message || 'Nem sikerült menteni.', b.part);
      }
    }, function () {
      mark(fd, 'bad', 'Nincs kapcsolat – a válaszod megmarad, újrapróbálom');
      retryLater();
    }).then(function () {
      s.busy = false;
      if (s.again) { s.again = false; return save(fd); }
    });
    return s.wait;
  }

  /* ---------------------------------------------------- események */
  fieldEls().forEach(function (fd) {
    var s = st(fd);
    var sv = fd.querySelector('.cpp-saved');
    s.saved = sv && sv.classList.contains('ok') ? JSON.stringify(read(fd)) : null;
    s.initial = JSON.stringify(read(fd));
    showIf(fd);
  });

  form.addEventListener('input', function (e) {
    var fd = e.target.closest('[data-field]');
    if (!fd || e.target.type === 'radio' || e.target.type === 'checkbox') return;
    mark(fd, '', '');
    if (fd.getAttribute('data-kind') === 'object' && incomplete(fd)) return;  // amíg a mezőben dolgozik, nem szólunk a hiányzó részért
    later(fd, 800);
  });
  form.addEventListener('change', function (e) {
    var fd = e.target.closest('[data-field]');
    if (!fd) return;
    showIf(fd);
    if (e.target.type === 'radio' || e.target.type === 'checkbox') {
      if (fd.getAttribute('data-kind') === 'object' && incomplete(fd)) { mark(fd, '', ''); return; }
      save(fd);
    }
  });
  form.addEventListener('focusout', function (e) {
    var fd = e.target.closest('[data-field]');
    if (!fd) return;
    if (e.relatedTarget && fd.contains(e.relatedTarget)) return;   // a mezőn belül lépett tovább
    save(fd);
  });
  form.addEventListener('submit', function (e) { e.preventDefault(); });
  window.addEventListener('online', flushPending);
  window.addEventListener('beforeunload', function (e) {
    var busy = Object.keys(S).some(function (k) { return S[k].busy || S[k].timer; });
    if (busy || Object.keys(pendingGet()).length) { e.preventDefault(); e.returnValue = ''; }
  });

  // korábbi, el nem küldött válaszok visszatöltése (pl. kapcsolat megszakadt, oldal újratöltve)
  (function restore() {
    var o = pendingGet();
    if (locked) { pendingClear(); return; }
    Object.keys(o).forEach(function (k) {
      var fd = byKey(k);
      if (!fd) { pendingDel(k); return; }
      write(fd, o[k]);
      save(fd, true);
    });
  })();

  /* ---------------------------------------------------- „Kész” */
  var done = document.getElementById('cpp-complete');
  var out = document.getElementById('cpp-result');
  if (done) done.addEventListener('click', function () {
    if (locked) return;
    done.disabled = true;
    out.className = ''; out.textContent = 'Mentés…';
    Promise.all(fieldEls().map(function (fd) { return save(fd); })).then(function () {
      return fetch(C.rest + '/complete', { method: 'POST', credentials: 'same-origin', headers: { 'X-CP-Nonce': C.nonce } });
    }).then(function (r) {
      return r.json().then(function (b) { return { r: r, b: b || {} }; }, function () { return { r: r, b: {} }; });
    }).then(function (x) {
      done.disabled = false;
      $all(form, '.cpp-field.miss').forEach(function (f) { f.classList.remove('miss'); });
      if (x.r.ok && x.b.ok) {
        progress(x.b);
        out.className = 'cpp-ok';
        out.textContent = 'Köszönjük! Megkaptuk az adatlapot' + (x.b.first ? ', és küldtünk egy visszaigazolást e-mailben.' : '.');
        return;
      }
      if (x.r.status === 423) { lockUI(x.b.message); out.textContent = ''; return; }
      if (x.b.code === 'missing') {
        out.className = 'cpp-bad';
        out.innerHTML = '';
        var p = document.createElement('p'); p.textContent = 'Még hiányzik:'; out.appendChild(p);
        var ul = document.createElement('ul');
        (x.b.missing || []).forEach(function (m) {
          var fd = form.querySelector('[data-q="' + m.q + '"].req');
          if (fd) fd.classList.add('miss');
          var li = document.createElement('li'), a = document.createElement('a');
          a.href = fd ? '#' + fd.id : '#'; a.textContent = m.label;
          a.addEventListener('click', function (ev) { if (fd) { ev.preventDefault(); fd.scrollIntoView({ behavior: 'smooth', block: 'center' }); var i = fd.querySelector('input,textarea'); if (i) setTimeout(function () { i.focus({ preventScroll: true }); }, 400); } });
          li.appendChild(a); ul.appendChild(li);
        });
        out.appendChild(ul);
        return;
      }
      out.className = 'cpp-bad';
      out.textContent = x.b.message || 'Nem sikerült – próbáld újra pár perc múlva.';
    }, function () {
      done.disabled = false;
      out.className = 'cpp-bad';
      out.textContent = 'Nincs kapcsolat – a válaszaid megmaradnak, próbáld újra, ha visszajött a net.';
    });
  });
})();
