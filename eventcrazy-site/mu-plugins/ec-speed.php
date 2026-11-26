<?php
/**
 * EventCrazy – mobil gyorsítás
 * 1) Google-címkék (GTM, gtag, Analytics, Ads) késleltetése: a WP Rocket „JavaScript késleltetése” funkciója az első
 *    érintéskor / görgetéskor / kattintáskor tölti be őket (eddig ki voltak véve belőle). A köszönő- és
 *    megrendelés-oldalakon (átirányítással érkezik a látogató, ott mérjük a konverziót) azonnal töltődnek, ahogy eddig.
 * 2) Flip Box csempék háttérképe csak akkor töltődik, amikor a csempe a képernyő közelébe ér (a főoldalon ~30 csempe,
 *    kb. 1 MB képet töltött be feleslegesen az első megjelenéskor).
 * 3) Videó-widgetek (pl. Háttérek bemutató): automatikus lejátszás és letöltés csak, amikor a videó látható.
 * 4) „Milk” betűtípus: font-display: swap (a szöveg azonnal látszik, nem vár a betűtípusra).
 * 5) Az imagesLoaded könyvtár nincs késleltetve (az Elementor Pro azonnal használja).
 * 6) A mobilos fejléckép kisebb (828 px-es WebP) változata a gyorsítótárazott oldalakban.
 * Kikapcsolás: a fájl törlése (vagy átnevezése), utána a WP Rocket gyorsítótárának ürítése.
 */
if (!defined('ABSPATH')) { exit; }

function ec_speed_skip() {
	return is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || isset($_GET['elementor-preview']);
}
/* köszönő- és megrendelés-oldalak: koszonjuk, thank-you, en/thank-you-2, megrendeles-rogzitve */
function ec_speed_conv_page() {
	return is_page(array(438, 527, 8195, 8263));
}

/* ------------------------------------------------------------ 1) Google-címkék késleltetése */
add_filter('rocket_delay_js_exclusions', function ($ex) {
	if (!is_array($ex)) { return $ex; }
	/* az imagesLoaded könyvtárat az (azonnal futó) Elementor Pro igényli – késleltetve „imagesLoaded is not defined” hibát adott */
	$ex[] = '/wp-includes/js/imagesloaded(.min)?.js';
	if (ec_speed_conv_page()) { return $ex; }
	$drop = array('googletagmanager.com', 'gtm.js', 'dataLayer', 'google-analytics.com', 'gtag');
	return array_values(array_filter($ex, function ($p) use ($drop) { return !in_array($p, $drop, true); }));
}, 99);

/* ------------------------------------------------------------ 2–4) fej: betűtípus, csempe-háttér, videó */
add_action('wp_head', function () {
	if (ec_speed_skip()) { return; }
	echo "<style id=\"ec-speed\">@font-face{font-family:'Milk';font-style:normal;font-weight:normal;font-display:swap;src:url('" . esc_url(home_url('/wp-content/uploads/2024/05/LemonMilk.woff')) . "') format('woff')}"
		. "html.ec-lz .elementor-flip-box__front:not(.ec-bgv){background-image:none!important}</style>\n";
	echo "<script nowprocket data-cfasync=\"false\" data-no-optimize=\"1\" data-no-defer=\"1\">\n" . <<<'ECJS'
(function () {
	if (!('IntersectionObserver' in window)) { return; }
	document.documentElement.classList.add('ec-lz');
	var io = new IntersectionObserver(function (es) {
		es.forEach(function (e) {
			if (!e.isIntersecting) { return; }
			var t = e.target; io.unobserve(t);
			if (t.tagName === 'VIDEO') { t.preload = 'auto'; t.autoplay = true; var p = t.play && t.play(); if (p && p.catch) { p.catch(function () {}); } }
			else { t.classList.add('ec-bgv'); }
		});
	}, { rootMargin: '1000px 0px' });
	function scan() {
		document.querySelectorAll('.elementor-flip-box__front:not(.ec-bgv)').forEach(function (n) { io.observe(n); });
		document.querySelectorAll('video[data-ec-lazy]').forEach(function (v) { v.removeAttribute('data-ec-lazy'); io.observe(v); });
	}
	document.addEventListener('DOMContentLoaded', scan);
	window.addEventListener('load', scan);
	/* biztonsági háló: ha valamiért nem futna le a figyelés, 20 mp után minden csempe megkapja a képét */
	setTimeout(function () { document.querySelectorAll('.elementor-flip-box__front').forEach(function (n) { n.classList.add('ec-bgv'); }); }, 20000);
})();
ECJS
	. "</script>\n";
}, 2);

/* videó-widget: autoplay és előtöltés csak láthatóságkor (a lejátszást a fenti figyelő indítja) */
add_filter('elementor/widget/render_content', function ($html, $w) {
	if (ec_speed_skip() || !is_object($w) || $w->get_name() !== 'video' || strpos($html, '<video') === false) { return $html; }
	return preg_replace_callback('/<video\b([^>]*)>/i', function ($m) {
		$a = $m[1];
		if (!preg_match('/\sautoplay\b/i', $a)) { return $m[0]; }
		$a = preg_replace('/\sautoplay(="[^"]*")?/i', '', $a);
		$a = preg_replace('/\spreload="[^"]*"/i', '', $a);
		return '<video' . $a . ' preload="none" data-ec-lazy="1">';
	}, $html);
}, 20, 2);

/* ------------------------------------------------------------ 6) Fejléckép mobilra: 828 px-es WebP (≈170 KB a 250 KB helyett)
 * A fejléc mobilos háttere (több szolgáltatásoldal egyedi CSS-ében és a WP Rocket előtöltésében) egy 1254 px-es PNG;
 * a gyorsítótárazott oldalakban a kisebb, szerveren készült változatra cseréljük. Az eredeti fájl változatlan. */
add_filter('rocket_buffer', function ($html) {
	$new = '/wp-content/uploads/2026/10/0215a5c9-hero-m828.webp';
	if (!is_string($html) || !file_exists(ABSPATH . ltrim($new, '/'))) { return $html; }
	return str_replace(array('/wp-content/uploads/2026/10/0215a5c9-74ab-4246-9d64-54396c38349b-optimized.png', '/wp-content/uploads/2026/10/0215a5c9-74ab-4246-9d64-54396c38349b.png'), $new, $html);
}, 50);
