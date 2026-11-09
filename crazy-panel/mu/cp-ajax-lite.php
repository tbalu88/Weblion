<?php
/**
 * Crazy panel – könnyített AJAX-betöltés (2026-11-09).
 * A panel saját kéréseinél (admin-ajax.php, action = cp_…) nem tölti be azokat a bővítményeket, amelyeket a panel nem használ
 * (oldalépítő, SEO, sütibanner, pixel, betűtípus, képkonverter, átirányítás, nyelvi bővítmény). A weboldal kéréseit nem érinti.
 * Bent marad: a panel és a híd, a Wordfence és a belépésvédelem (biztonság), a WP Rocket (a panel üríti a gyorsítótárát).
 * Ok: a tárhely processzorkorlátja miatt minden betöltött kód várakozással jár; kevesebb kód = gyorsabb panel.
 * Kikapcsolás: a fájl törlése (vagy define('CP_AJAX_LITE_OFF', true) a wp-config.php-ban).
 */
if (!defined('ABSPATH')) { exit; }
(function () {
	if (defined('CP_AJAX_LITE_OFF') && CP_AJAX_LITE_OFF) { return; }
	$script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
	if (substr($script, -14) !== 'admin-ajax.php') { return; }
	$act = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? $_REQUEST['action'] : '';
	if (strpos($act, 'cp_') !== 0) { return; }
	$skip = array(
		'elementor/', 'elementor-pro/', 'wordpress-seo/', 'pixelyoursite/', 'cookie-law-info/', 'host-webfonts-local/',
		'insert-headers-and-footers/', 'duplicate-page/', 'webp-converter-for-media/', 'redirection/', 'polylang/',
	);
	add_filter('option_active_plugins', function ($list) use ($skip) {
		if (!is_array($list)) { return $list; }
		return array_values(array_filter($list, function ($p) use ($skip) {
			foreach ($skip as $s) { if (strpos((string) $p, $s) === 0) { return false; } }
			return true;
		}));
	}, 1);
})();
