/* Crazy Booth mobil (PWA) – telefon: járkálós fotós (a telefon saját kamerája, teljes felbontás);
 * iPad / Android-tablet: szelfigép (élőkép, visszaszámlálás, a panel sablonja). Feltöltés a galériába sorban (net nélkül is),
 * QR a vendégnek, nyomtatás a nyomtatóállomáson, e-mail a panel levélküldőjén. A gépkulcs csak ezen az eszközön tárolódik. */
(function () {
  'use strict';
  const $ = s => document.querySelector(s), $$ = s => [...document.querySelectorAll(s)];
  const API = '/wp-json/crazy-galeria/v1', GAL = location.origin + '/galeria/';
  const LS = {
    get: (k, d) => { try { const v = localStorage.getItem('cbm_' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: (k, v) => { try { localStorage.setItem('cbm_' + k, JSON.stringify(v)); } catch (e) { /* tele / tiltva */ } }
  };
  const OPT_DEF = { pin: '2580', frame: false, shots: 1, cd: 3, print: false, email: false };
  const S = { key: LS.get('key', ''), ev: LS.get('event', null), opt: Object.assign({}, OPT_DEF, LS.get('opt', {})), tpl: null, scr: '', last: null, prev: 'home', busy: false };
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const tok = n => { const a = new Uint8Array(n); crypto.getRandomValues(a); return Array.from(a, x => 'abcdefghijklmnopqrstuvwxyz0123456789'[x % 36]).join(''); };
  const today = () => { const d = new Date(), p = n => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); };
  const huDate = s => s ? s.replace(/-/g, '.') + '.' : '';

  function show(s) { $$('.scr').forEach(x => x.classList.toggle('on', x.dataset.s === s)); if (S.scr !== s && S.scr !== 'busy') S.prev = S.scr; S.scr = s; window.scrollTo(0, 0); }
  function toast(m, bad) { const t = $('[data-toast]'); t.textContent = m; t.classList.toggle('bad', !!bad); t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => { t.hidden = true; }, bad ? 6000 : 3000); }
  function busy(t) { $('[data-busyt]').textContent = t || 'Pillanat…'; show('busy'); }

  async function api(path, o) {
    o = o || {};
    const h = { 'X-Booth-Key': S.key }; if (o.json) h['Content-Type'] = 'application/json';
    const ctl = new AbortController(), tm = setTimeout(() => ctl.abort(), o.timeout || 30000);
    let r;
    try { r = await fetch(API + path, { method: o.method || 'GET', headers: h, body: o.json ? JSON.stringify(o.json) : o.body, cache: 'no-store', signal: ctl.signal }); }
    catch (e) { throw new Error(e.name === 'AbortError' ? 'Nem jött válasz (gyenge a net?)' : 'Nincs internetkapcsolat.'); }
    finally { clearTimeout(tm); }
    const j = await r.json().catch(() => ({}));
    if (r.status === 401 || r.status === 403) throw new Error('A rendszer nem fogadja el a gépkulcsot.');
    if (!r.ok || j.ok === false) throw new Error(j.message || ('Hiba (' + r.status + ')'));
    return j;
  }

  /* ---------- helyi tár (IndexedDB): feltöltési sor, mai fotók, sablon */
  let dbp = null;
  const db = () => dbp || (dbp = new Promise((res, rej) => {
    const q = indexedDB.open('cbm', 1);
    q.onupgradeneeded = () => { q.result.createObjectStore('q', { keyPath: 'photo' }); q.result.createObjectStore('done', { keyPath: 'photo' }); q.result.createObjectStore('kv'); };
    q.onsuccess = () => res(q.result); q.onerror = () => rej(q.error);
  }));
  const tx = async (st, mode, fn) => { const d = await db(); return new Promise((res, rej) => { const t = d.transaction(st, mode), r = fn(t.objectStore(st)); t.oncomplete = () => res(r && r.result); t.onerror = () => rej(t.error); }); };
  const qAll = () => tx('q', 'readonly', s => s.getAll());
  const kvGet = k => tx('kv', 'readonly', s => s.get(k));
  const kvSet = (k, v) => tx('kv', 'readwrite', s => s.put(v, k));

  let pumping = false;
  /* v0.8 (360-as videók): XHR – a nagy fájloknál a feltöltés százaléka látszik; hálózati hibánál null */
  const send = (fd, onp) => new Promise(res => {
    const x = new XMLHttpRequest(); x.open('POST', API + '/upload'); x.setRequestHeader('X-Booth-Key', S.key);
    x.upload.onprogress = e => { if (e.lengthComputable && onp) onp(e.loaded / e.total); };
    x.onload = () => { let j = {}; try { j = JSON.parse(x.responseText || '{}'); } catch (e) { j = {}; } res({ ok: x.status >= 200 && x.status < 300, status: x.status, j }); };
    x.onerror = () => res(null); x.onabort = () => res(null);
    x.send(fd);
  });
  async function pump() {
    if (pumping || !S.key) return status();
    pumping = true;
    try {
      for (const it of await qAll()) {
        if (!navigator.onLine) break;
        const vid = it.kind === 'video', fd = new FormData();
        fd.append('ev', it.ev); fd.append('photo', it.photo); fd.append('date', it.date || ''); fd.append('deal', String(it.deal || 0));
        fd.append('file', it.blob, it.photo + (vid ? '.' + ((/\.(mp4|mov|m4v|webm)$/i.exec(it.name || '') || [0, 'mp4'])[1].toLowerCase()) : '.jpg'));
        if (vid) { fd.append('kind', 'video'); if (it.poster) fd.append('poster', it.poster, it.photo + '.jpg'); }
        const r = await send(fd, vid ? p => { VP[it.photo] = p; vprog(it.photo); } : null);
        delete VP[it.photo];
        if (!r) break;
        const j = r.j || {};
        if (r.ok && j.ok) { await tx('q', 'readwrite', s => s.delete(it.photo)); if (it.dk) await kvSet(it.dk, Date.now()).catch(() => {}); }
        else if (r.status === 401 || r.status === 403) { toast('A galéria nem fogadja el a gépkulcsot.', true); break; }
        else if (r.status >= 400 && r.status < 500) { await tx('q', 'readwrite', s => s.delete(it.photo)); if (vid) VF[it.photo] = j.message || ('hiba ' + r.status); toast((vid ? 'Egy videót' : 'Egy képet') + ' a galéria nem fogadott el: ' + (j.message || r.status), true); }
        else break;
        if (vid) vlist();
      }
    } finally { pumping = false; status(); if (S.scr === 'vids') vlist(); }
  }
  setInterval(pump, 15000); window.addEventListener('online', pump);
  async function status() {
    let n = 0; try { n = (await qAll()).length; } catch (e) { n = 0; }
    $$('[data-q]').forEach(x => { x.textContent = n ? n + ' fájl (kép / videó) feltöltésre vár' + (navigator.onLine ? '…' : ' (nincs internet – automatikusan pótolja)') : 'Minden feltöltve.'; });
  }

  /* ---------- képek */
  const loadImg = src => new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('A kép nem tölthető be.')); i.src = src; });
  async function fileImg(f) { const u = URL.createObjectURL(f); try { const i = await loadImg(u); await i.decode().catch(() => {}); return i; } finally { setTimeout(() => URL.revokeObjectURL(u), 60000); } }
  function scaled(img, max) {
    const w0 = img.naturalWidth || img.width, h0 = img.naturalHeight || img.height, k = Math.min(1, max / Math.max(w0, h0));
    const c = document.createElement('canvas'); c.width = Math.round(w0 * k); c.height = Math.round(h0 * k); c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); return c;
  }
  const toBlob = (c, q) => new Promise((res, rej) => c.toBlob(b => (b ? res(b) : rej(new Error('A kép nem készült el.'))), 'image/jpeg', q || 0.9));
  function drawQr(el, url) {
    try { const q = qrcode(0, 'M'); q.addData(url); q.make(); el.innerHTML = q.createSvgTag({ cellSize: 6, margin: 0 }); }
    catch (e) { el.textContent = url; }
  }

  /* ---------- rendezvény + sablon */
  function evInfo() {
    const e = S.ev;
    $('[data-evtitle]').textContent = e ? e.title : 'Válassz rendezvényt';
    $('[data-evsub]').textContent = e ? (huDate(e.date) + (e.deal ? '' : ' · nincs dealhez kötve') + (S.tpl ? ' · sablon: ' + S.tpl.shots + ' fotó' : '')) : 'Koppints ide a kiválasztáshoz';
    $('[data-pgev]').textContent = e ? e.title : '';
  }
  async function loadTpl() {
    S.tpl = null;
    if (!S.ev || !S.ev.deal) return;
    let png = null;
    try { const j = await api('/m/template?deal=' + S.ev.deal); png = j.template && j.template.png; await kvSet('tpl_' + S.ev.deal, png || ''); }
    catch (e) { png = await kvGet('tpl_' + S.ev.deal).catch(() => null); }   /* net nélkül a legutóbb letöltött */
    if (!png) return;
    try { const img = await loadImg(png); S.tpl = Object.assign({ img }, BoothCompose.holes(img)); } catch (e) { S.tpl = null; }
  }
  async function evLoad() {
    const box = $('[data-evlist]'), d = $('[data-evdate]').value || today();
    box.innerHTML = '<p class="mu">Betöltés…</p>';
    try {
      const j = await api('/m/events?date=' + d);
      const L = j.events || [];
      box.innerHTML = L.length ? L.map((e, i) => '<button type="button" data-evi="' + i + '"><b>' + esc(e.title) + '</b><span>' + esc([e.time, e.loc].filter(Boolean).join(' · ')) + (e.template ? ' · van sablon' : '') + '</span></button>').join('') : '<p class="mu">Erre a napra nincs foglalt rendezvény.</p>';
      box.onclick = async ev => {
        const b = ev.target.closest('[data-evi]'); if (!b) return;
        const e = L[Number(b.dataset.evi)];
        S.ev = { ev: e.ev, deal: e.deal, title: e.title, date: d }; LS.set('event', S.ev);
        busy('Sablon letöltése…'); await loadTpl(); evInfo(); show('home');
      };
    } catch (e) { box.innerHTML = '<p class="err">' + esc(e.message) + '</p>'; }
  }

  /* ---------- a kész kép: helyi mentés + sorba állítás + eredményképernyő */
  async function keep(canvas, kind) {
    const blob = await toBlob(canvas, 0.9), photo = tok(10), e = S.ev;
    await tx('q', 'readwrite', s => s.put({ photo, ev: e.ev, deal: e.deal || 0, date: e.date || '', blob, t: Date.now() }));
    const th = scaled(canvas, 360), thumb = th.toDataURL('image/jpeg', 0.7);
    await tx('done', 'readwrite', s => s.put({ photo, ev: e.ev, thumb, t: Date.now() }));
    pump();
    S.last = { photo, url: URL.createObjectURL(blob), kind };
    result();
  }
  function result() {
    const L = S.last, e = S.ev;
    $('[data-rimg]').src = L.url;
    drawQr($('[data-rqr]'), GAL + e.ev + '/' + L.photo);
    $('[data-rprint]').hidden = !S.opt.print; $('[data-remail]').hidden = !(S.opt.email && e.deal);
    $('[data-rnext]').textContent = L.kind === 'kiosk' ? 'Kész' : 'Következő fotó';
    show('result');
    clearTimeout(S.rt);
    if (L.kind === 'kiosk') { let n = 40; const t = () => { if (S.scr !== 'result') return; $('[data-rt]').textContent = 'Vissza ' + n + ' mp múlva'; if (n-- <= 0) { kioskOn(); return; } S.rt = setTimeout(t, 1000); }; t(); }
    else $('[data-rt]').textContent = '';
  }

  /* ---------- fotós mód (telefon): a rendszer kamerája, teljes felbontás */
  $('[data-shot]').addEventListener('change', async ev => {
    const f = ev.target.files && ev.target.files[0]; ev.target.value = ''; if (!f) return;
    busy('Kép feldolgozása…');
    try {
      const img = await fileImg(f);
      let c;
      if (S.opt.frame && S.tpl) { c = document.createElement('canvas'); BoothCompose.compose(c, [img], { title: 'EventCrazy', date: huDate(today()), tpl: S.tpl }); }
      else c = scaled(img, 2400);
      await keep(c, 'photog');
    } catch (e) { toast(e.message, true); show('photog'); }
  });

  /* ---------- szelfigép mód (tablet): élőkép, visszaszámlálás, sablon */
  let stream = null, wake = null;
  async function kioskOn() {
    clearTimeout(S.rt);
    show('kiosk'); $('[data-kstart]').hidden = false; $('[data-kcd]').textContent = ''; $('[data-kno]').textContent = '';
    if (!stream || !stream.getVideoTracks().some(t => t.readyState === 'live')) {
      try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false }); }
      catch (e) { toast('A kamera nem indul: ' + (e.name === 'NotAllowedError' ? 'engedélyezd a kamerát a böngésző beállításaiban.' : (e.message || e.name)), true); show('home'); return; }
    }
    const v = $('[data-kv]'); if (v.srcObject !== stream) v.srcObject = stream; v.play().catch(() => {});
    try { if (navigator.wakeLock && !wake) wake = await navigator.wakeLock.request('screen'); } catch (e) { wake = null; }
  }
  function kioskOff() { if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } if (wake) { wake.release().catch(() => {}); wake = null; } }
  async function kioskRun() {
    if (S.busy) return; S.busy = true;
    const v = $('[data-kv]'), n = S.tpl ? S.tpl.shots : (Number(S.opt.shots) >= 3 ? 3 : 1), shots = [];
    try {
      $('[data-kstart]').hidden = true;
      for (let i = 0; i < n; i++) {
        $('[data-kno]').textContent = n > 1 ? (i + 1) + ' / ' + n : '';
        for (let k = Math.max(1, Number(S.opt.cd) || 3); k > 0; k--) { $('[data-kcd]').textContent = k; await sleep(1000); }
        $('[data-kcd]').textContent = '';
        const f = $('[data-flash]'); f.classList.remove('go'); void f.offsetWidth; f.classList.add('go');
        const c = document.createElement('canvas'); c.width = v.videoWidth || 1280; c.height = v.videoHeight || 720; c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
        shots.push(c);
        if (i < n - 1) await sleep(900);
      }
      $('[data-kno]').textContent = '';
      const out = document.createElement('canvas');
      BoothCompose.compose(out, shots, { title: 'EventCrazy', date: huDate(today()), tpl: S.tpl });
      await keep(out, 'kiosk');
    } catch (e) { toast(e.message, true); kioskOn(); }
    finally { S.busy = false; }
  }
  $('[data-s=kiosk]').addEventListener('click', e => { if (!e.target.closest('[data-corner]') && !S.busy) kioskRun(); });
  /* kilépés a szelfigép módból: bal felső sarok 3 mp-ig → PIN */
  let hold = 0;
  $('[data-corner]').addEventListener('pointerdown', () => { hold = setTimeout(() => { $('[data-pin]').value = ''; show('pin'); setTimeout(() => $('[data-pin]').focus(), 50); }, 3000); });
  ['pointerup', 'pointerleave', 'pointercancel'].forEach(n => $('[data-corner]').addEventListener(n, () => clearTimeout(hold)));

  /* ---------- 360-as videók (pl. LumaBooth): a telefon Fotók alkalmazásából, borítóképpel, a már feltöltöttek kihagyásával */
  const VP = {}, VF = {};   /* feltöltés közbeni százalék, elutasított videók üzenete */
  const MAXV = 80 * 1048576;
  function vidPoster(f) {
    return new Promise((res, rej) => {
      const v = document.createElement('video'), u = URL.createObjectURL(f); let done = false, tried = false;
      const end = (b, er) => { if (done) return; done = true; clearTimeout(t1); clearTimeout(t2); try { v.pause(); } catch (e) { /* */ } v.removeAttribute('src'); try { v.load(); } catch (e) { /* */ } URL.revokeObjectURL(u); er ? rej(er) : res(b); };
      const t1 = setTimeout(() => end(null, new Error('A borítókép nem készült el.')), 12000);
      /* iOS: ha a léptetés nem hoz képkockát, egy rövid lejátszás után próbálja újra */
      const t2 = setTimeout(() => { if (!tried) { tried = true; v.play().then(() => setTimeout(() => { v.pause(); grab(); }, 300)).catch(() => {}); } }, 4000);
      const grab = () => {
        try {
          if (!v.videoWidth) return;
          const k = Math.min(1, 1080 / Math.max(v.videoWidth, v.videoHeight)), c = document.createElement('canvas');
          c.width = Math.round(v.videoWidth * k); c.height = Math.round(v.videoHeight * k); c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
          c.toBlob(b => (b ? end(b) : end(null, new Error('A borítókép nem készült el.'))), 'image/jpeg', 0.85);
        } catch (e) { end(null, e); }
      };
      v.muted = true; v.playsInline = true; v.setAttribute('playsinline', ''); v.preload = 'auto';
      v.onloadedmetadata = () => { try { v.currentTime = Math.min(1, (v.duration || 2) / 2); } catch (e) { /* a lejátszásos próba jön */ } };
      v.onseeked = grab;
      v.onerror = () => end(null, new Error('A videó nem olvasható ezen az eszközön.'));
      v.src = u;
    });
  }
  async function blobThumb(b) { const u = URL.createObjectURL(b); try { const i = await loadImg(u); return scaled(i, 360).toDataURL('image/jpeg', 0.7); } finally { setTimeout(() => URL.revokeObjectURL(u), 1000); } }
  const mb = n => (n / 1048576).toLocaleString('hu-HU', { maximumFractionDigits: 1 }) + ' MB';
  function vidsInfo() { const e = S.ev; $('[data-vev]').textContent = e ? (e.title || 'Rendezvény') + (e.date ? ' · ' + huDate(e.date) : '') + ' – ide kerülnek a videók' : ''; }
  async function addVids(files) {
    const e = S.ev; if (!e) { toast('Előbb válassz rendezvényt.', true); return; }
    const L = [...files].filter(f => /^video\//.test(f.type) || /\.(mp4|mov|m4v)$/i.test(f.name));
    if (!L.length) { toast('Nem választottál videót.', true); return; }
    let added = 0, skip = 0, big = 0, err = 0;
    const queued = new Set((await qAll().catch(() => [])).map(x => x.dk).filter(Boolean));
    for (let i = 0; i < L.length; i++) {
      const f = L[i];
      $('[data-vev]').textContent = 'Előkészítés: ' + (i + 1) + ' / ' + L.length + '…';
      if (f.size > MAXV) { big++; continue; }
      const dk = 'sent:' + e.ev + ':' + f.name + ':' + f.size + ':' + (f.lastModified || 0);
      if (queued.has(dk) || await kvGet(dk).catch(() => null)) { skip++; continue; }
      const photo = tok(10);
      let poster = null; try { poster = await vidPoster(f); } catch (x) { poster = null; }
      let thumb = ''; try { thumb = poster ? await blobThumb(poster) : ''; } catch (x) { thumb = ''; }
      try {
        await tx('q', 'readwrite', s => s.put({ photo, ev: e.ev, deal: e.deal || 0, date: e.date || '', blob: f, poster, kind: 'video', name: f.name, size: f.size, dk, t: Date.now() }));
        await tx('done', 'readwrite', s => s.put({ photo, ev: e.ev, thumb, kind: 'video', name: f.name, size: f.size, t: Date.now() }));
        queued.add(dk); added++;
      } catch (x) { err++; }
    }
    vidsInfo();
    const msg = [added ? added + ' videó feltöltésre kész' : '', skip ? skip + ' már feltöltve' : '', big ? big + ' túl nagy (80 MB fölött – a LumaBooth-ban kisebb felbontás segít)' : '', err ? err + ' nem fért el a telefonon' : ''].filter(Boolean).join(' · ');
    toast(msg || 'Nincs új videó.', !added && (big || err));
    vlist(); pump();
  }
  async function vlist() {
    const box = $('[data-vlist]'); if (!box || !S.ev) return;
    let D = [], Q = [];
    try { D = (await tx('done', 'readonly', s => s.getAll())).filter(x => x.kind === 'video' && x.ev === S.ev.ev).sort((a, b) => b.t - a.t).slice(0, 60); } catch (e) { D = []; }
    try { Q = (await qAll()).map(x => x.photo); } catch (e) { Q = []; }
    box.innerHTML = D.map(x => {
      const p = VP[x.photo], q = Q.includes(x.photo), f = VF[x.photo];
      const st = f ? '<span class="bad">' + esc(f) + '</span>' : q ? '<span>' + (p != null ? 'Feltöltés: ' + Math.round(p * 100) + '%' : navigator.onLine ? 'Feltöltésre vár…' : 'Vár – nincs internet') + '</span>' : '<span class="ok">Feltöltve – a galériában és a megosztó állomáson</span>';
      return '<div class="vr" data-vph="' + esc(x.photo) + '"><span class="vt v" style="' + (x.thumb ? 'background-image:url(\'' + x.thumb + '\')' : '') + '"></span><div class="vn"><b>' + esc(x.name || 'videó') + '</b>' + st + (q ? '<div class="vp"><i style="width:' + Math.round((p || 0) * 100) + '%"></i></div>' : '') + '<span>' + (x.size ? mb(x.size) : '') + '</span></div></div>';
    }).join('');
  }
  let vpT = 0;
  function vprog(ph) {
    const r = $('[data-vph="' + ph + '"]'); if (!r || S.scr !== 'vids') return;
    const now = Date.now(); if (now - vpT < 250) return; vpT = now;
    const i = r.querySelector('.vp i'), s = r.querySelector('.vn span'); const p = VP[ph] || 0;
    if (i) i.style.width = Math.round(p * 100) + '%'; if (s) s.textContent = 'Feltöltés: ' + Math.round(p * 100) + '%';
  }
  $('[data-vfiles]').addEventListener('change', e => { const f = e.target.files; if (f && f.length) addVids(f).finally(() => { e.target.value = ''; }); });

  /* ---------- mai fotók */
  async function recent() {
    let L = []; try { L = await tx('done', 'readonly', s => s.getAll()); } catch (e) { L = []; }
    const d0 = new Date(); d0.setHours(0, 0, 0, 0);
    L = L.filter(x => x.t >= d0.getTime()).sort((a, b) => b.t - a.t);
    let q = []; try { q = (await qAll()).map(x => x.photo); } catch (e) { q = []; }
    $('[data-rcn]').textContent = L.length ? '(' + L.length + ')' : '';
    $('[data-rgrid]').innerHTML = L.length ? L.map(x => '<button type="button"' + (x.kind === 'video' ? ' class="v" aria-label="Videó"' : '') + ' data-rph="' + esc(x.photo) + '" data-rev="' + esc(x.ev) + '" style="background-image:url(\'' + x.thumb + '\')">' + (q.includes(x.photo) ? '<i>vár</i>' : '') + '</button>').join('') : '<p class="mu">Ma még nem készült fotó ezen az eszközön.</p>';
    show('recent');
  }
  $('[data-rgrid]').addEventListener('click', async e => {
    const b = e.target.closest('[data-rph]'); if (!b) return;
    const it = await tx('done', 'readonly', s => s.get(b.dataset.rph));
    S.last = { photo: b.dataset.rph, url: it.thumb, kind: 'photog' };
    const keepEv = S.ev; S.ev = Object.assign({}, S.ev || {}, { ev: b.dataset.rev }); result(); S.ev = keepEv;
  });

  /* ---------- e-mail (a panel küldi a linket; csak a megadott adatok, hozzájárulással) */
  $('[data-mail]').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, em = f.elements.email.value.trim(), E = $('[data-mailerr]');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(em)) { E.textContent = 'Ez az e-mail-cím nem tűnik helyesnek.'; return; }
    if (!f.elements.consent.checked) { E.textContent = 'A küldéshez fogadd el a hozzájárulást.'; return; }
    busy('Küldés…');
    try {
      await api('/m/guest', { method: 'POST', timeout: 45000, json: { deal: S.ev.deal, ev: S.ev.ev, photo: S.last.photo, title: S.ev.title || '', name: f.elements.name.value.trim(), email: em, consent: true, send: true } });
      toast('Elküldtük! Pár percen belül megérkezik.'); f.reset(); result();
    } catch (x) { E.textContent = x.message; show('mail'); }
  });

  /* ---------- gombok */
  document.addEventListener('click', async e => {
    const a = e.target.closest('[data-act]'); if (!a) return;
    const act = a.dataset.act;
    if (act === 'home') { kioskOff(); evInfo(); status(); show('home'); }
    else if (act === 'setup') { setupForm(); show('setup'); }
    else if (act === 'events') { $('[data-evdate]').value = (S.ev && S.ev.date) || today(); show('events'); evLoad(); }
    else if (act === 'evload') { evLoad(); }
    else if (act === 'evnone') { S.ev = { ev: 'm' + tok(12), deal: 0, title: 'Rendezvény nélkül', date: today() }; LS.set('event', S.ev); S.tpl = null; evInfo(); show('home'); }
    else if (act === 'photog') { if (!S.ev) { toast('Előbb válassz rendezvényt.', true); return; } show('photog'); status(); }
    else if (act === 'kiosk') { if (!S.ev) { toast('Előbb válassz rendezvényt.', true); return; } kioskOn(); }
    else if (act === 'vids') { if (!S.ev) { toast('Előbb válassz rendezvényt.', true); return; } vidsInfo(); show('vids'); vlist(); status(); }
    else if (act === 'recent') { recent(); }
    else if (act === 'back') { if (S.prev === 'photog') show('photog'); else show('home'); }
    else if (act === 'next') { clearTimeout(S.rt); if (S.last && S.last.kind === 'kiosk') kioskOn(); else if (S.prev === 'recent') recent(); else show('photog'); }
    else if (act === 'rprint') {
      try { a.disabled = true; await api('/print', { method: 'POST', json: { ev: S.ev.ev, photo: S.last.photo, copies: 1 } }); toast('Elküldve a nyomtatóra – a nyomtatónál veheted át.'); }
      catch (x) { toast(x.message, true); } finally { a.disabled = false; }
    }
    else if (act === 'remail') { clearTimeout(S.rt); $('[data-mailerr]').textContent = ''; show('mail'); }
    else if (act === 'mailback') { result(); }
    else if (act === 'pinok') { if ($('[data-pin]').value === String(S.opt.pin || '2580')) { kioskOff(); evInfo(); show('home'); } else { toast('Hibás PIN.', true); $('[data-pin]').value = ''; } }
    else if (act === 'pinno') { kioskOn(); }
  });

  /* ---------- beállítás */
  function setupForm() {
    const f = $('[data-setup]'), o = S.opt;
    f.elements.key.value = S.key ? '••••••••' : ''; f.elements.key.dataset.keep = S.key ? '1' : '';
    f.elements.pin.value = o.pin || ''; f.elements.frame.checked = !!o.frame; f.elements.shots.value = String(o.shots || 1); f.elements.cd.value = o.cd || 3;
    f.elements.print.checked = !!o.print; f.elements.email.checked = !!o.email;
    $$('[data-ifkey]').forEach(x => { x.hidden = !S.key; }); $('[data-setuperr]').textContent = '';
  }
  $('[data-setup]').elements.key.addEventListener('input', e => { e.target.dataset.keep = ''; });
  $('[data-setup]').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, E = $('[data-setuperr]');
    const key = f.elements.key.dataset.keep ? S.key : f.elements.key.value.trim();
    if (key.length < 20) { E.textContent = 'Add meg a gépkulcsot (a panelben: Beállítások → Crazy Booth gépek).'; return; }
    const old = S.key; S.key = key; E.textContent = 'Ellenőrzés…';
    try { await api('/m/events?date=' + today()); }
    catch (x) { S.key = old; E.textContent = x.message; return; }
    LS.set('key', key);
    S.opt = { pin: f.elements.pin.value.trim() || '2580', frame: f.elements.frame.checked, shots: Number(f.elements.shots.value) || 1, cd: Math.max(1, Math.min(10, Number(f.elements.cd.value) || 3)), print: f.elements.print.checked, email: f.elements.email.checked };
    LS.set('opt', S.opt); E.textContent = '';
    toast('Mentve.'); evInfo(); status(); show('home'); pump();
  });

  /* ---------- indulás */
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('mobil-sw.js').catch(() => {});
  (async () => {
    if (!S.key) { setupForm(); show('setup'); return; }
    evInfo(); show('home'); status(); pump();
    if (S.ev && S.ev.deal) { await loadTpl(); evInfo(); }
  })();
})();
