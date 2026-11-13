<?php
/**
 * Plugin Name: Tartalék – csak galéria
 * Description: (2026-11-13, a tulajdonos kérésére: „maradjon csak ami szükséges”) A tartalék szerveren csak a híd (crazy-bridge) és a galéria (crazy-galeria) bővítmény fut, a többi (Elementor, régi panel stb.) nem töltődik be; a téma a beépített Twenty Twenty-Five. A szerver (tartalek.eventcrazy.hu) nyilvánosan csak a galériát (/galeria/) és a REST-felületet (/wp-json/: híd, szelfigép-feltöltés) mutatja; minden más oldalt az eventcrazy.hu-ra irányít, és a keresőknek tiltja az indexelést (az eventcrazy.hu másolata nem kerülhet a Google-be). A WordPress-admin és a belépés elérhető marad.
 * Kikapcsolás (pl. ha vészhelyzetben a tartalékot kell élesként használni): ezt a fájlt törölni kell a wp-content/mu-plugins mappából, vagy a wp-config.php-ba: define('CP_TARTALEK_NYITOTT', true);
 * Version: 1.0.0 (2026-11-13)
 */
if (!defined('ABSPATH')) { exit; }
if (defined('CP_TARTALEK_NYITOTT') && CP_TARTALEK_NYITOTT) { return; }
if (defined('WP_CLI') && WP_CLI) { return; }

/* csak a szükséges bővítmények töltődnek be (a többi a lemezen marad, nem fut) */
add_filter('option_active_plugins', function ($list) {
	$keep = array('crazy-bridge/crazy-bridge.php', 'crazy-galeria/crazy-galeria.php');
	$out = array_values(array_intersect((array) $list, $keep));
	if (!in_array('crazy-bridge/crazy-bridge.php', $out, true)) { $out[] = 'crazy-bridge/crazy-bridge.php'; } /* a híd mindig fut (ezen át lehet javítani) */
	return $out;
}, 1);
/* beépített téma (a child-téma az Elementorra épül) */
foreach (array('template', 'stylesheet') as $o) { add_filter('pre_option_' . $o, function () { return 'twentytwentyfive'; }, 1); }

/* a WP Rocket (már nem fut) elmentett oldalmásolatait a webszerver PHP nélkül is kiadná – egyszer kiürítjük (csak gyorsítótár) */
if (!get_option('cp_tartalek_cache_purged')) {
	$rm = function ($d) use (&$rm) { foreach ((array) @scandir($d) as $f) { if ($f === '.' || $f === '..') { continue; } $p = $d . '/' . $f; if (is_dir($p) && !is_link($p)) { $rm($p); @rmdir($p); } else { @unlink($p); } } };
	foreach (array('/cache/wp-rocket', '/cache/min', '/cache/busting') as $c) { $d = WP_CONTENT_DIR . $c; if (is_dir($d)) { $rm($d); } }
	update_option('cp_tartalek_cache_purged', time(), false);
}

/* minden válaszra: ne indexeljék */
if (!headers_sent()) { header('X-Robots-Tag: noindex, nofollow', true); }
add_filter('wp_robots', function ($r) { $r['noindex'] = true; $r['nofollow'] = true; return $r; }, 99);

(function () {
	$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
	$path = (string) parse_url($uri, PHP_URL_PATH);
	if ($path === '/robots.txt') {
		header('Content-Type: text/plain; charset=utf-8');
		echo "User-agent: *\nDisallow: /\n";
		exit;
	}
	$allow = array('/galeria', '/wp-json', '/wp-admin', '/wp-login.php', '/wp-cron.php', '/wp-content/uploads/crazy-galeria');
	foreach ($allow as $a) { if ($path === $a || strpos($path, $a . '/') === 0 || strpos($path, $a . '?') === 0) { return; } }
	if (strpos($path, '/wp-admin/') === 0 || isset($_GET['rest_route'])) { return; }
	/* minden más: az éles oldalra */
	if (!headers_sent()) { header('Location: https://eventcrazy.hu' . $uri, true, 302); }
	exit;
})();
