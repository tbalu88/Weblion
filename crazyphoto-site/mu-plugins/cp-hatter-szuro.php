<?php
/* Háttér-szűrő (2026-11-24): kategóriagombok a „Választható hátterek” oldal tetején (Összes, Karácsony és tél, Csillogó és fények,
   Elegáns és gála, Virágos és esküvő, Városi és modern, Színes és party). A kategóriát a kép felirata (háttérnév) adja; új háttérnél
   a lenti MAP-be kell felvenni a nevét (szám nélkül). Csak a megadott oldalakon fut. */
if (!defined('ABSPATH')) { exit; }
add_action('wp_footer', function () {
	if (is_admin() || !is_page(array('valaszthato-hatterek'))) { return; }
		echo '<script id="cpbg-js" nowprocket data-nowprocket data-no-optimize="1" data-no-defer="1">' . <<<'CPBGJS'
/* Háttér-szűrő (2026-11-24): kategóriagombok a „Választható hátterek” oldal tetején. A kategóriát a felirat (háttérnév) adja. */
(function () {
  'use strict';
  /* a stílus a szkriptből kerül az oldalra – a gyorsító (WP Rocket „nem használt CSS”) a csak futás közben használt szabályokat kidobta volna */
  if (!document.getElementById('cpbg-css')) { var st = document.createElement('style'); st.id = 'cpbg-css'; st.textContent = ".cpbg-ice{display:none!important}.cpbg-grid .cpbg-dup{display:none!important}.cpbg-grid .cpbg-cta,.cpbg-grid .cpbg-cta>.elementor-widget-wrap{border:0!important;background:none!important;box-shadow:none!important;padding:0!important;align-self:start}.cpbg-grid .cpbg-cta>.elementor-widget-wrap{display:block!important;margin:0!important;width:100%!important}body .cpbg-grid .cpbg-cta .elementor-element.elementor-widget-text-editor{width:100%!important;max-width:none!important;margin:0!important;padding:0!important;border:0!important;background:none!important}body .cpbg-grid .cpbg-cta .elementor-element.elementor-widget-text-editor>.elementor-widget-container{box-sizing:border-box!important;width:100%!important;aspect-ratio:var(--cpbg-ar,1/1);margin:0!important;padding:24px!important;display:flex!important;align-items:center;justify-content:center;border:1.5px dashed var(--cpbg-line,rgba(255,255,255,.35))!important;border-radius:20px!important;background:none!important;transition:border-color .2s}body .cpbg-grid .cpbg-cta .elementor-element.elementor-widget-text-editor>.elementor-widget-container:hover{border-color:var(--cpbg-acc,#F5A524)!important}.cpbg-grid .cpbg-cta p{margin:0!important;font-size:19px!important;line-height:1.4!important;text-align:center}\n:root{--cpbg-ar:4/5;--cpbg-ink:#1F2433;--cpbg-line:rgba(20,28,51,.18);--cpbg-acc:#E8894A;--cpbg-on:#fff}.cpbg-grid>.cpbg-bar{grid-column:1/-1;display:flex!important;flex-wrap:wrap;justify-content:center;align-items:center;gap:8px;margin:0 0 8px;padding:0;width:auto}\n.cpbg-bar .cpbg-b{appearance:none!important;box-sizing:border-box!important;width:auto!important;height:auto!important;min-height:0!important;flex:0 0 auto!important;margin:0!important;border:1.5px solid var(--cpbg-line,rgba(255,255,255,.28))!important;background:transparent!important;color:var(--cpbg-ink,#fff)!important;border-radius:999px!important;padding:9px 16px!important;font-size:14px!important;line-height:1.2!important;font-weight:600!important;letter-spacing:0!important;text-transform:none!important;box-shadow:none!important;cursor:pointer;transition:background .15s,border-color .15s,color .15s;white-space:nowrap}\n.cpbg-bar .cpbg-b span{opacity:.6;font-weight:500;margin-left:4px;font-variant-numeric:tabular-nums}\n.cpbg-bar .cpbg-b:hover{border-color:var(--cpbg-acc,#F5A524)!important}\n.cpbg-bar .cpbg-b[aria-pressed=true]{background:var(--cpbg-acc,#F5A524)!important;border-color:var(--cpbg-acc,#F5A524)!important;color:var(--cpbg-on,#141C33)!important}\n.cpbg-bar .cpbg-b:focus-visible{outline:2px solid var(--cpbg-acc,#F5A524);outline-offset:2px}\n.cpbg-grid{display:grid!important;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:28px 32px}\n.cpbg-grid>.elementor-inner-section,.cpbg-grid>.elementor-inner-section>.elementor-container{display:contents!important}\n.cpbg-grid .elementor-inner-column{width:auto!important;max-width:none!important;min-width:0}\n.cpbg-grid .elementor-inner-column:not(:has(.elementor-widget)){display:none!important}\n.cpbg-grid .elementor-inner-column>.elementor-widget-wrap{padding:0!important}\n.cpbg-grid .cpbg-off{display:none!important}\n@media (max-width:600px){body .cpbg-grid .cpbg-cta .elementor-element.elementor-widget-text-editor>.elementor-widget-container{padding:12px!important}.cpbg-grid .cpbg-cta p{font-size:14px!important;line-height:1.35!important}.cpbg-bar{gap:6px;margin-bottom:20px}.cpbg-bar .cpbg-b{padding:7px 12px!important;font-size:13px!important}.cpbg-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}}\n"; document.head.appendChild(st); }
  function run() {
    var caps = [].slice.call(document.querySelectorAll('.elementor-widget-image figcaption'));
    if (caps.length < 8 || document.querySelector('.cpbg-bar')) return;
    var en = /^en/i.test(document.documentElement.lang || '');
    var norm = function (s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s*\d+\s*$/, '').trim(); };
    var MAP = {
      karacsony: ['xmas'], christmas: ['xmas'],
      bokeh: ['glitter'], fenyek: ['glitter'], lights: ['glitter'], shimmer: ['glitter'], pezsgo: ['glitter'], champagne: ['glitter'], arany: ['glitter', 'elegant'], gold: ['glitter', 'elegant'],
      rose: ['glitter', 'flower'],
      'art deco': ['elegant'], gatsby: ['elegant'], hollywood: ['elegant'], selyem: ['elegant'], silk: ['elegant'], marvany: ['elegant'], marble: ['elegant'], bor: ['elegant'], leather: ['elegant'],
      eskuvo: ['flower'], wedding: ['flower'], eukaliptusz: ['flower'], eucalyptus: ['flower'], vintage: ['flower', 'elegant'], zold: ['flower'], green: ['flower'], levelek: ['flower'], leaves: ['flower'],
      varosi: ['urban'], city: ['urban'], tegla: ['urban'], brick: ['urban'], deszka: ['urban'], board: ['urban'], graffiti: ['urban'], polygon: ['urban'], zebra: ['urban'],
      candy: ['party'], pink: ['party'], absztrakt: ['party'], abstract: ['party']
    };
    var CATS = [['all', en ? 'All' : 'Összes'], ['xmas', en ? 'Christmas & winter' : 'Karácsony és tél'], ['glitter', en ? 'Glitter & lights' : 'Csillogó és fények'], ['elegant', en ? 'Elegant & gala' : 'Elegáns és gála'], ['flower', en ? 'Floral & wedding' : 'Virágos és esküvő'], ['urban', en ? 'Urban & modern' : 'Városi és modern'], ['party', en ? 'Colourful & party' : 'Színes és party']];
    /* jegelt hátterek (2026-11-24: még nem érkeztek meg) – 2026.11.30. 0:00-ig rejtve, utána maguktól visszajönnek */
    var ICE = ['karácsony 6', 'karácsony 7', 'christmas 6', 'christmas 7', 'art deco 2', 'bokeh 6', 'bokeh 7', 'bokeh 8', 'eukaliptusz', 'eucalyptus', 'vintage', 'zöld 2', 'green 2', 'városi 2', 'city 2'];
    if (Date.now() < Date.parse('2026-11-30T00:00:00+01:00')) {
      caps = caps.filter(function (c) { if (ICE.indexOf(c.textContent.trim().toLowerCase()) < 0) return true; var col = c.closest('.elementor-column'); if (col) col.classList.add('cpbg-ice'); return false; });
    }
    var cells = [], first = null;
    caps.forEach(function (c) {
      var col = c.closest('.elementor-column'); if (!col) return;
      var sec = col.closest('.elementor-inner-section'); if (sec && !first) first = sec;
      cells.push({ el: col, cats: MAP[norm(c.textContent)] || [] });
    });
    if (!first) return;
    var wrap = first.parentElement; if (!wrap) return;
    wrap.classList.add('cpbg-grid');
    /* az „Egyik sem az igazi?” doboz: az oldalon egy asztali és egy mobil változat is van – csak egyet hagyunk, a rács végére,
       és kártyastílust kap (minden kategóriában látszik) */
    var ctas = [].filter.call(wrap.querySelectorAll('.elementor-inner-column'), function (c) { return c.querySelector('.elementor-widget-text-editor') && !c.querySelector('figcaption'); });
    ctas.forEach(function (c, i) { if (i === 0) { c.classList.add('cpbg-cta'); [c].concat([].slice.call(c.querySelectorAll('[class*=elementor-hidden-]'))).forEach(function (e) { e.className = e.className.replace(/\belementor-hidden-\S+/g, ''); }); wrap.appendChild(c); } else { c.classList.add('cpbg-dup'); } });
    var cnt = {}; cells.forEach(function (x) { x.cats.forEach(function (k) { cnt[k] = (cnt[k] || 0) + 1; }); });
    var bar = document.createElement('div'); bar.className = 'cpbg-bar'; bar.setAttribute('role', 'group'); bar.setAttribute('aria-label', en ? 'Filter backdrops' : 'Hátterek szűrése');
    CATS.forEach(function (c) {
      if (c[0] !== 'all' && !cnt[c[0]]) return;
      var b = document.createElement('button'); b.type = 'button'; b.className = 'cpbg-b'; b.dataset.cat = c[0];
      b.innerHTML = c[1] + ' <span>' + (c[0] === 'all' ? cells.length : cnt[c[0]]) + '</span>';
      b.setAttribute('aria-pressed', c[0] === 'all' ? 'true' : 'false');
      b.addEventListener('click', function () { set(c[0]); });
      bar.appendChild(b);
    });
    wrap.insertBefore(bar, wrap.firstChild);
    function set(k) {
      [].forEach.call(bar.children, function (b) { b.setAttribute('aria-pressed', b.dataset.cat === k ? 'true' : 'false'); });
      cells.forEach(function (x) { x.el.classList.toggle('cpbg-off', k !== 'all' && x.cats.indexOf(k) < 0); });
      try { history.replaceState(null, '', k === 'all' ? location.pathname + location.search : '#' + k); } catch (e) { /* */ }
      var top = bar.getBoundingClientRect().top; if (top < 0) window.scrollBy(0, top - 90);
      if (window.dispatchEvent) window.dispatchEvent(new Event('scroll')); /* a lusta betöltésű képek induljanak el */
    }
    var h = (location.hash || '').slice(1); if (h && cnt[h]) set(h);
  }
  /* több ponton is próbálkozik (a lusta betöltésű / később kirajzolt elemek miatt); a második futás már nem tesz semmit */
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
  window.addEventListener('load', run); setTimeout(run, 1500); setTimeout(run, 4000); setTimeout(run, 8000);
})();

CPBGJS
 . '</script>';
}, 50);
