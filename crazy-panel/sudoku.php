<?php
/**
 * Szudoku: /crazy-panel/sudoku/ – önálló webes játék (PWA) az assets/sudoku* fájlokból.
 * Jelszó nélkül elérhető, a keresők elől rejtve.
 */

if (!defined('ABSPATH')) { exit; }

add_action('init', 'cp_sudoku_route', 98);
function cp_sudoku_route() {
	if (is_admin() || wp_doing_ajax()) { return; }
	$o = cp_panel_opts();
	$slug = trim($o['slug'], '/');
	if ($slug === '') { return; }
	$raw = (string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '', PHP_URL_PATH);
	$path = trim($raw, '/');
	$base = $slug . '/sudoku';
	if ($path !== $base && strpos($path, $base . '/') !== 0) { return; }

	if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
	if (!defined('DONOTROCKETOPTIMIZE')) { define('DONOTROCKETOPTIMIZE', true); }
	if (!defined('DONOTMINIFY')) { define('DONOTMINIFY', true); }

	// a relatív hivatkozások miatt perjellel végződő cím kell
	if ($path === $base && substr($raw, -1) !== '/') {
		wp_safe_redirect(home_url('/' . $base . '/'), 301);
		exit;
	}

	$file = substr($path, strlen($base) + 1);
	if ($file === false || $file === '') { $file = 'index.html'; }

	// nyilvános név => [fájl az assets mappában, típus]
	$map = array(
		'index.html'           => array('sudoku.html', 'text/html; charset=utf-8'),
		'style.css'            => array('sudoku.css', 'text/css; charset=utf-8'),
		'engine.js'            => array('sudoku-engine.js', 'application/javascript; charset=utf-8'),
		'app.js'               => array('sudoku-app.js', 'application/javascript; charset=utf-8'),
		'sw.js'                => array('sudoku-sw.js', 'application/javascript; charset=utf-8'),
		'manifest.webmanifest' => array('sudoku-manifest.json', 'application/manifest+json; charset=utf-8'),
		'icons/icon-180.png'   => array('icon:180', 'image/png'),
		'icons/icon-192.png'   => array('icon:192', 'image/png'),
		'icons/icon-512.png'   => array('icon:512', 'image/png'),
	);
	$body = isset($map[$file]) ? cp_sudoku_body($map[$file][0]) : null;
	if ($body === null) {
		status_header(404);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Nem található';
		exit;
	}

	status_header(200);
	header('Content-Type: ' . $map[$file][1]);
	header('X-Robots-Tag: noindex, nofollow', true);
	header('X-Content-Type-Options: nosniff');
	if (strpos($map[$file][1], 'image/') === 0) {
		header('Cache-Control: public, max-age=604800');
	} else {
		// a service worker gondoskodik az offline gyorsítótárról, a hálózatról mindig friss jöjjön
		header('Cache-Control: no-cache');
	}
	header('Content-Length: ' . strlen($body));
	echo $body;
	exit;
}

/* Fájl tartalma; az ikonok base64-ben a sudoku-icons.json-ban vannak (a tárhelyre csak szöveg írható). */
function cp_sudoku_body($name) {
	if (strpos($name, 'icon:') === 0) {
		$json = @file_get_contents(__DIR__ . '/assets/sudoku-icons.json');
		$icons = $json ? json_decode($json, true) : null;
		$key = substr($name, 5);
		if (!is_array($icons) || empty($icons[$key])) { return null; }
		$png = base64_decode($icons[$key], true);
		return $png === false ? null : $png;
	}
	$abs = __DIR__ . '/assets/' . $name;
	if (!is_file($abs)) { return null; }
	$body = file_get_contents($abs);
	return $body === false ? null : $body;
}
