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
  function fieldEls() { return $all(form, '[data-field]').filter(function (f) { var k = f.getAttribute('data-kind'); return k !== 'sablon' && k !== 'fixed'; }); }
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
  /* két sáv: 1. szerződéshez (a), 2. részletek (b = összes − a) */
  function progress(b) {
    if (!b || b.required_done == null) return;
    var ca = b.contract_total || 0, cd = b.contract_done || 0;
    var bt = (b.required_total || 0) - ca, bd = Math.max(0, (b.required_done || 0) - cd);
    var set = function (id, d, t) {
      var e = document.getElementById('cpp-done-' + id), bar = document.getElementById('cpp-bar-' + id);
      if (e) e.textContent = d;
      if (bar) bar.style.width = (t ? Math.round(d / t * 100) : 0) + '%';
    };
    if (b.contract_total != null) set('a', cd, ca);
    set('b', bd, bt);
  }
  /* magánszemély / cég: a hozzá tartozó mezőcsoport látszik */
  function showFields() {
    $all(form, '[data-show-if-field]').forEach(function (fd) {
      var x = fd.getAttribute('data-show-if-field').split('=');
      var src = byKey(x[0]);
      fd.hidden = !src || read(src) !== x[1];
    });
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
    // force: true = újraküldés (függő mentés); 'accept' = a gombnyomás az előtöltött (foglalás szerinti) értéket is jóváhagyja
    if (force !== true && j === s.saved) return Promise.resolve();
    if (s.saved === null && (blank(v) || (j === s.initial && force !== 'accept'))) return Promise.resolve();   // üres, vagy csak az előtöltés van benne: nincs mit menteni
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
    if (fd.getAttribute('data-field') === 'schedule') { autoEnd(fd); duration(fd); }  // betöltéskor: üres vége a csomag alapján (előtöltésként)
    var s = st(fd);
    var sv = fd.querySelector('.cpp-saved');
    s.saved = sv && sv.classList.contains('ok') ? JSON.stringify(read(fd)) : null;
    s.initial = JSON.stringify(read(fd));
    showIf(fd);
  });

  form.addEventListener('input', function (e) {
    var fd = e.target.closest('[data-field]');
    if (!fd || e.target.type === 'file' || e.target.type === 'radio' || e.target.type === 'checkbox') return;
    mark(fd, '', '');
    if (fd.getAttribute('data-field') === 'schedule') {
      if (e.target.closest('[data-part="start"]')) autoEnd(fd);
      else if (e.target.closest('[data-part="end"]') && e.target.value === '') fd.removeAttribute('data-auto-end');  // kitörölte: újra automatikus
      duration(fd);
    }
    if (fd.getAttribute('data-field') === 'guests_type_dress') guests(fd);
    if (fd.getAttribute('data-field') === 'onsite_contact') { var sc = fd.querySelector('[data-same-as-client]'); if (sc) sc.checked = false; }
    if (fd.getAttribute('data-kind') === 'object' && incomplete(fd)) return;  // amíg a mezőben dolgozik, nem szólunk a hiányzó részért
    later(fd, 800);
  });
  /* „Ugyanaz, mint a megrendelő”: a helyszíni kapcsolattartó a megrendelő (vagy a cég kapcsolattartójának) adataival */
  function sameAsClient(fd, cb) {
    if (!cb.checked) return;
    var type = byKey('client_type') ? read(byKey('client_type')) : '';
    var src = type === 'ceg' ? byKey('client_company') : byKey('client_private');
    var v = src ? read(src) : {};
    var name = type === 'ceg' ? v.contact_name : v.name, phone = type === 'ceg' ? v.contact_phone : v.phone;
    if (!name && !phone) { cb.checked = false; err(fd, 'Előbb add meg a megrendelő adatait (1. rész), utána tudom átvenni.'); return; }
    write(fd, { name: name || '', phone: phone || '' });
    err(fd, '');
    save(fd);
  }
  /* a foglalt csomag óraszámához képest eltér-e a megadott menetrend */
  function duration(fd) {
    var box = fd.querySelector('.cpp-dur');
    if (!box) return;
    var pkg = parseFloat(box.getAttribute('data-pkg-hours')) || 0, v = read(fd);
    var m = function (t) { var x = /^(\d{1,2}):(\d{2})$/.exec(t || ''); return x ? (+x[1]) * 60 + (+x[2]) : null; };
    var a = m(v.start), b = m(v.end);
    var hrs = function (h) { return String(Math.round(h * 100) / 100).replace('.', ',') + ' óra'; };
    var t = 'A foglalásod szerint ' + hrs(pkg).replace(/óra$/, 'órás') + ' csomagot kértél.', diff = false;
    if (a != null && b != null) {
      if (b <= a) b += 1440;
      var h = (b - a) / 60;
      diff = Math.abs(h - pkg) >= 0.25;
      if (diff) t += ' A megadott időtartam ' + hrs(h) + ' – ez eltér a csomagtól, a kollégáink egyeztetnek veled a különbözetről.';
    }
    box.textContent = t;
    box.classList.toggle('diff', diff);
  }
  /* a megadott létszám vs. az ajánlatkérésben szereplő (Salesmate) – magasabbnál felár / új ajánlat */
  function guests(fd) {
    var box = fd.querySelector('[data-orig-guests]'), inp = fd.querySelector('[data-part="guests"] input');
    if (!box || !inp) return;
    var o = parseInt(box.getAttribute('data-orig-guests'), 10) || 0, n = parseInt(inp.value, 10) || 0;
    var more = 'Magasabb létszám esetén felárat számolhatunk fel, és új ajánlatot küldünk', t;
    if (n > o) t = 'A megadott létszám ' + n + ' fő – ez több, mint az ajánlatkérésben szereplő ' + o + ' fő. ' + more + '; a kollégáink hamarosan jelentkeznek.';
    else if (n > 0 && n < o) t = 'A megadott létszám ' + n + ' fő – ez eltér az ajánlatkérésben szereplő ' + o + ' főtől. A kollégáink szükség esetén egyeztetnek veled.';
    else t = 'Az ajánlatkérésben ' + o + ' fő szerepelt. ' + more + '.';
    box.textContent = t;
    box.classList.toggle('diff', n > o);
  }
  /* egyedi sablon: a díj a megrendelő típusa szerint (magánszemély / cég + ÁFA) */
  function tplFee() {
    var p = form.querySelector('[data-tpl-fee]'), ct = byKey('client_type');
    if (!p || !C.fees) return;
    var v = ct ? read(ct) : '';
    p.textContent = C.fees[v] || C.fees[''];
  }
  /* logó feltöltése az egyedi sablonhoz (a szerver ellenőrzi és újrakódolja) */
  function logoUp(inp, remove) {
    var box = inp.closest('[data-logo-up]'), errEl = box.querySelector('[data-logo-err]'), cur = box.querySelector('.cpp-logo-cur');
    var btn = box.querySelector('.cpp-logo-btn span'), fd = new FormData();
    var showErr = function (m) { errEl.textContent = m || ''; errEl.hidden = !m; };
    if (remove) fd.append('remove', '1');
    else {
      var f = inp.files && inp.files[0];
      if (!f) return;
      if (f.size > 10 * 1024 * 1024) { showErr('A fájl túl nagy (legfeljebb 10 MB).'); inp.value = ''; return; }
      if (!/^image\/(png|jpeg|webp)$/.test(f.type)) { showErr('Csak PNG, JPG vagy WebP képet tudunk fogadni. Vektoros logót e-mailben küldj el.'); inp.value = ''; return; }
      fd.append('logo', f);
    }
    showErr('');
    if (btn) btn.textContent = remove ? 'Törlés…' : 'Feltöltés…';
    fetch(C.logoRest, { method: 'POST', credentials: 'same-origin', headers: { 'X-CP-Nonce': C.nonce }, body: fd })
      .then(function (r) { return r.json().then(function (b) { return { r: r, b: b }; }, function () { return { r: r, b: {} }; }); })
      .then(function (x) {
        if (x.r.status === 423) { lockUI(x.b.message); return; }
        if (!x.r.ok || !x.b.ok) { showErr(x.b.message || 'A feltöltés nem sikerült – próbáld újra.'); return; }
        if (x.b.removed) { cur.hidden = true; }
        else { cur.querySelector('img').src = x.b.url; cur.querySelector('span').textContent = x.b.name; cur.hidden = false; }
      }, function () { showErr('Nincs kapcsolat – próbáld újra.'); })
      .then(function () { inp.value = ''; if (btn) btn.textContent = cur.hidden ? 'Logó feltöltése' : 'Másik logó feltöltése'; });
  }
  form.addEventListener('click', function (e) {
    var d = e.target.closest('[data-logo-del]');
    if (!d) return;
    e.preventDefault();
    var box = d.closest('[data-logo-up]'), inp = box.querySelector('input[type=file]');
    if (inp) logoUp(inp, true);
  });
  /* a kezdés + a foglalt csomag óraszáma → a szolgáltatás vége (amíg az ügyfél kézzel át nem írja) */
  function autoEnd(fd) {
    var box = fd.querySelector('.cpp-dur'), si = fd.querySelector('[data-part="start"] input'), ei = fd.querySelector('[data-part="end"] input');
    var pkg = box ? parseFloat(box.getAttribute('data-pkg-hours')) || 0 : 0;
    var x = si ? /^(\d{1,2}):(\d{2})$/.exec(si.value || '') : null;
    if (!pkg || !x || !ei || ei.disabled) return false;
    if (ei.value !== '' && ei.value !== fd.getAttribute('data-auto-end')) return false;   // kézzel megadott vége: nem írjuk felül
    var m = ((+x[1]) * 60 + (+x[2]) + Math.round(pkg * 60)) % 1440;
    var v = pad2(Math.floor(m / 60)) + ':' + pad2(m % 60);
    ei.value = v;
    fd.setAttribute('data-auto-end', v);
    return true;
  }
  function pad2(n) { return (n < 10 ? '0' : '') + n; }
  form.addEventListener('change', function (e) {
    var fd = e.target.closest('[data-field]');
    if (!fd) return;
    if (e.target.hasAttribute('data-same-as-client')) { sameAsClient(fd, e.target); return; }
    if (e.target.type === 'file') { logoUp(e.target); return; }
    if (fd.getAttribute('data-field') === 'client_type') tplFee();
    if (fd.getAttribute('data-field') === 'schedule' && e.target.closest('[data-part="start"]')) autoEnd(fd);
    if (fd.getAttribute('data-field') === 'schedule') duration(fd);
    showIf(fd);
    showFields();
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

  /* ---------------------------------------------------- „Mehet a szerződés” és „Kész” */
  function submitter(btnId, outId, path, fields, okText) {
    var btn = document.getElementById(btnId), out = document.getElementById(outId);
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (locked) return;
      btn.disabled = true;
      out.className = 'cpp-result'; out.textContent = 'Mentés…';
      Promise.all(fields().filter(function (fd) { return !fd.hidden; }).map(function (fd) { return save(fd, 'accept'); })).then(function () {
        return fetch(C.rest + '/' + path, { method: 'POST', credentials: 'same-origin', headers: { 'X-CP-Nonce': C.nonce } });
      }).then(function (r) {
        return r.json().then(function (b) { return { r: r, b: b || {} }; }, function () { return { r: r, b: {} }; });
      }).then(function (x) {
        btn.disabled = false;
        $all(form, '.cpp-field.miss').forEach(function (f) { f.classList.remove('miss'); });
        if (x.r.ok && x.b.ok) {
          progress(x.b);
          out.className = 'cpp-result cpp-ok';
          out.textContent = okText(x.b);
          return;
        }
        if (x.r.status === 423) { lockUI(x.b.message); out.textContent = ''; return; }
        if (x.b.code === 'missing') {
          out.className = 'cpp-result cpp-bad';
          out.innerHTML = '';
          var p = document.createElement('p'); p.textContent = x.b.message || 'Még hiányzik:'; out.appendChild(p);
          var ul = document.createElement('ul');
          (x.b.missing || []).forEach(function (m) {
            var fd = $all(form, '[data-q="' + m.q + '"].req').filter(function (f) { return !f.hidden; })[0] || null;
            if (fd) fd.classList.add('miss');
            var li = document.createElement('li'), a = document.createElement('a');
            a.href = fd ? '#' + fd.id : '#'; a.textContent = m.label;
            a.addEventListener('click', function (ev) { if (fd) { ev.preventDefault(); fd.scrollIntoView({ behavior: 'smooth', block: 'center' }); var i = fd.querySelector('input,textarea'); if (i) setTimeout(function () { i.focus({ preventScroll: true }); }, 400); } });
            li.appendChild(a); ul.appendChild(li);
          });
          out.appendChild(ul);
          return;
        }
        out.className = 'cpp-result cpp-bad';
        out.textContent = x.b.message || 'Nem sikerült – próbáld újra pár perc múlva.';
      }, function () {
        btn.disabled = false;
        out.className = 'cpp-result cpp-bad';
        out.textContent = 'Nincs kapcsolat – a válaszaid megmaradnak, próbáld újra, ha visszajött a net.';
      });
    });
  }
  var stageA = function () { return fieldEls().filter(function (fd) { return fd.closest('[data-stage="a"]'); }); };
  submitter('cpp-contract', 'cpp-contract-result', 'contract', stageA, function (b) {
    var r = document.getElementById('cpp-ready'); if (r) r.hidden = false;
    return b.first ? 'Köszönjük! Megkaptuk a szerződéshez szükséges adatokat – hamarosan küldjük a szerződést. A 2. részt ráér a határidőig kitölteni.' :
      b.changed ? 'Köszönjük, jeleztük a kollégáknak, hogy módosultak az adatok.' : 'Köszönjük, az adatok már nálunk vannak.';
  });
  submitter('cpp-complete', 'cpp-result', 'complete', fieldEls, function (b) {
    return 'Köszönjük! Megkaptuk az adatlapot' + (b.first ? ', és küldtünk egy visszaigazolást e-mailben.' : '.');
  });
  showFields();
})();
