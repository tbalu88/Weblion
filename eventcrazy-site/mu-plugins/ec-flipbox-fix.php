<?php
/**
 * EventCrazy – mobilos javítások (Elementor oldalak)
 * 1) Flip Box csempék: az egész csempe kattintható (mobilon a hátoldali gomb nem érhető el), link nélküli csempéhez tartalék cél.
 * 2) Mobilon a sorkizárt bekezdések balra zárva, kisebb betűköz; a képernyő széléig érő szövegblokkok oldalmargót kapnak.
 * 3) Greenbox oldal: a véletlenül bemásolt második fejléc-kártya (értékelés-jelvény, telefon, kép) rejtve.
 * Kikapcsolás: a fájl törlése (vagy átnevezése).
 */
if (!defined('ABSPATH')) { exit; }

function ec_fix_skip() {
	return is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || isset($_GET['elementor-preview']);
}

add_action('wp_head', function () {
	if (ec_fix_skip()) { return; }
	echo "<style id=\"ec-mobile-fix\">\n" . <<<'ECCSS'
/* Greenbox: a második (bemásolt) fejléc-kártya – értékelés-jelvény, telefon, kép – minden nézetben rejtve */
[data-id="3e25d98"] [data-id="076669a"]{display:none!important}
@media (max-width:767px){
	/* mobil: kisebb betűköz a folyószövegben */
	.elementor-widget-text-editor p,.elementor-widget-text-editor li,.elementor-icon-box-description{letter-spacing:.2px!important;word-spacing:normal!important;-webkit-hyphens:auto;hyphens:auto}
	/* mobil: a képernyő széléig érő szövegblokkok oldalmargót kapnak */
	[data-id="df309d3"],[data-id="d299a87"]{margin-left:0!important;margin-right:0!important;padding-left:0!important;padding-right:0!important;width:100%!important;max-width:100%!important}
	.elementor-widget-wrap:has(>[data-id="df309d3"]),.elementor-widget-wrap:has(>[data-id="d299a87"]),.e-con:has(>[data-id="df309d3"]),.e-con:has(>[data-id="d299a87"]){padding-left:0!important;padding-right:0!important}
	[data-id="df309d3"]>.elementor-widget-container,[data-id="d299a87"]>.elementor-widget-container{margin-left:0!important;margin-right:0!important;padding-left:20px!important;padding-right:20px!important}
}
ECCSS
	. "</style>\n";
}, 99);

add_action('wp_footer', function () {
	if (ec_fix_skip()) { return; }
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

(function () {
	/* mobil: a sorkizárt bekezdések balra zárva (keskeny képernyőn a sorkizárás nagy lyukakat hagy a szavak között) */
	function unjustify() {
		if (window.innerWidth > 767) return;
		var els = document.querySelectorAll('.elementor-widget-text-editor, .elementor-widget-text-editor p, .elementor-widget-text-editor div, .elementor-widget-text-editor li, .elementor-icon-box-description, .elementor-image-box-description');
		for (var i = 0; i < els.length; i++) {
			if (getComputedStyle(els[i]).textAlign === 'justify') els[i].style.setProperty('text-align', 'left', 'important');
		}
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', unjustify); else unjustify();
	window.addEventListener('load', unjustify);
})();
ECJS
	. "\n</script>\n";
}, 99);

/* egyszeri gyorsítótár-ürítés (WP Rocket) minden új változat után */
add_action('wp_loaded', function () {
	$v = '2';
	if (get_option('ec_flipfix_purged') === $v) { return; }
	update_option('ec_flipfix_purged', $v, false);
	if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
});
