<?php
/**
 * EventCrazy – Flip Box csempék javítása (mobil)
 * Az Elementor „Flip Box” csempék hátoldala mobilon átlátszó, de elkapja a koppintást, így ahol a link csak a hátoldali
 * „További információ” gombon van, a csempe nem nyílik meg. Ez a szkript az egész csempét kattinthatóvá teszi
 * (a hátoldali link címére visz), a link nélküli csempéhez pedig tartalék célt ad.
 * Kikapcsolás: a fájl törlése (vagy átnevezése).
 */
if (!defined('ABSPATH')) { exit; }

add_action('wp_footer', function () {
	if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())) { return; }
	if (isset($_GET['elementor-preview'])) { return; }  /* az Elementor szerkesztőben ne zavarjon */
	echo "<script nowprocket data-cfasync=\"false\">\n" . <<<'ECJS'
(function () {
	/* a hátoldali link nélküli csempék célja (a szerkesztőben pótolandó) */
	var FALLBACK = { 'bffd745': '/eventcrazy-rendezvenyfilm/' };
	function linkOf(w) {
		var back = w.querySelector('.elementor-flip-box__back');
		var a = back && (back.tagName === 'A' ? back : back.querySelector('a[href]'));
		var h = a ? (a.getAttribute('href') || '').trim() : '';
		if (!h || h === '#') { h = FALLBACK[w.getAttribute('data-id')] || ''; a = null; }
		return h ? { href: h, blank: !!(a && a.getAttribute('target') === '_blank') } : null;
	}
	document.addEventListener('click', function (e) {
		var w = e.target.closest && e.target.closest('.elementor-widget-flip-box');
		if (!w) return;
		var own = e.target.closest('a[href]');
		if (own && (own.getAttribute('href') || '').trim() && own.getAttribute('href') !== '#') return; /* saját működő link: hagyjuk */
		var l = linkOf(w);
		if (!l) return;
		e.preventDefault();
		if (l.blank) { window.open(l.href, '_blank', 'noopener'); } else { window.location.href = l.href; }
	}, true);
	var st = document.createElement('style');
	st.textContent = '.elementor-widget-flip-box{cursor:pointer;-webkit-tap-highlight-color:rgba(0,0,0,.08)}';
	(document.head || document.documentElement).appendChild(st);
})();
ECJS
	. "\n</script>\n";
}, 99);

/* egyszeri gyorsítótár-ürítés (WP Rocket), hogy a látogatók már az új oldalt kapják */
add_action('wp_loaded', function () {
	if (get_option('ec_flipfix_purged') === '1') { return; }
	update_option('ec_flipfix_purged', '1', false);
	if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
});
