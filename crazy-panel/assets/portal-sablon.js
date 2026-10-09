/* EventCrazy ügyfélportál – fotósablon: a panel sablonkészítője (sablon.js) ügyfél-módban.
 * Mentés: POST /wp-json/crazy/v1/portal/sablon {snap, logo (data-URL | 'keep' | ''), preview (JPG data-URL)} */
(function () {
  'use strict';
  var P = window.CPPS || {};
  var root = document.getElementById('cpp-sbroot');
  if (!root || !window.CPSablon) { if (root) root.textContent = 'A sablonkészítő nem töltődött be – töltsd újra az oldalt.'; return; }
  window.CPSablon.view(root, {
    deal: P.deal,
    saved: P.saved,
    logoUrl: P.logo,
    locked: !!P.locked,
    huDate: function (ymd) { return String(ymd || '').replace(/-/g, '.') + '.'; },
    save: function (data) {
      return fetch(P.rest, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CP-Nonce': P.nonce },
        body: JSON.stringify(data)
      }).then(function (r) {
        return r.json().then(function (b) { return { r: r, b: b || {} }; }, function () { return { r: r, b: {} }; });
      }).then(function (x) {
        if (!x.r.ok || !x.b.ok) throw new Error(x.b.message || ('szerverhiba (' + x.r.status + ')'));
        return x.b;
      });
    }
  });
})();
