<?php
/* Háttér-szűrő (2026-11-24): kategóriagombok a „Választható hátterek” oldal tetején (Összes, Karácsony és tél, Csillogó és fények,
   Elegáns és gála, Virágos és esküvő, Városi és modern, Színes és party). A kategóriát a kép felirata (háttérnév) adja; új háttérnél
   a lenti MAP-be kell felvenni a nevét (szám nélkül). Csak a megadott oldalakon fut. */
if (!defined('ABSPATH')) { exit; }
add_action('wp_footer', function () {
	if (is_admin() || !is_page(array('valaszthato-foto-hatterek-fotofalak', 'background-options'))) { return; }
	echo '<style id="cpbg-css">' . '.cpbg-grid>.cpbg-bar{grid-column:1/-1;display:flex!important;flex-wrap:wrap;justify-content:center;align-items:center;gap:8px;margin:0 0 8px;padding:0;width:auto}\n.cpbg-bar .cpbg-b{appearance:none!important;box-sizing:border-box!important;width:auto!important;height:auto!important;min-height:0!important;flex:0 0 auto!important;margin:0!important;border:1.5px solid var(--cpbg-line,rgba(255,255,255,.28))!important;background:transparent!important;color:var(--cpbg-ink,#fff)!important;border-radius:999px!important;padding:9px 16px!important;font-size:14px!important;line-height:1.2!important;font-weight:600!important;letter-spacing:0!important;text-transform:none!important;box-shadow:none!important;cursor:pointer;transition:background .15s,border-color .15s,color .15s;white-space:nowrap}\n.cpbg-bar .cpbg-b span{opacity:.6;font-weight:500;margin-left:4px;font-variant-numeric:tabular-nums}\n.cpbg-bar .cpbg-b:hover{border-color:var(--cpbg-acc,#F5A524)!important}\n.cpbg-bar .cpbg-b[aria-pressed=true]{background:var(--cpbg-acc,#F5A524)!important;border-color:var(--cpbg-acc,#F5A524)!important;color:var(--cpbg-on,#141C33)!important}\n.cpbg-bar .cpbg-b:focus-visible{outline:2px solid var(--cpbg-acc,#F5A524);outline-offset:2px}\n.cpbg-grid{display:grid!important;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:28px 32px}\n.cpbg-grid>.elementor-inner-section,.cpbg-grid>.elementor-inner-section>.elementor-container{display:contents!important}\n.cpbg-grid .elementor-inner-column{width:auto!important;max-width:none!important;min-width:0}\n.cpbg-grid .elementor-inner-column:not(:has(.elementor-widget)){display:none!important}\n.cpbg-grid .elementor-inner-column>.elementor-widget-wrap{padding:0!important}\n.cpbg-grid .cpbg-off{display:none!important}\n@media (max-width:600px){.cpbg-bar{gap:6px;margin-bottom:20px}.cpbg-bar .cpbg-b{padding:7px 12px!important;font-size:13px!important}.cpbg-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}}\n' . '</style>';
	echo '<script id="cpbg-js">' . <<<'CPBGJS'
/* Háttér-szűrő (2026-11-24): kategóriagombok a „Választható hátterek” oldal tetején. A kategóriát a felirat (háttérnév) adja. */
(function () {
  'use strict';
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
    var cells = [], first = null;
    caps.forEach(function (c) {
      var col = c.closest('.elementor-column'); if (!col) return;
      var sec = col.closest('.elementor-inner-section'); if (sec && !first) first = sec;
      cells.push({ el: col, cats: MAP[norm(c.textContent)] || [] });
    });
    if (!first) return;
    var wrap = first.parentElement; if (!wrap) return;
    wrap.classList.add('cpbg-grid');
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
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();

CPBGJS
 . '</script>';
}, 50);
