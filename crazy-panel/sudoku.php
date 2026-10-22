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

/* ---------------------------------------------------------------------
 * Kétszemélyes játék: szobák REST API-val (/wp-json/crazy-sudoku/v1/…)
 * A szoba és a két játékos állása külön tranziensben van, így a két
 * játékos egyidejű mentése nem írja felül egymást.
 * ------------------------------------------------------------------- */

define('CP_SDK_TTL', 2 * DAY_IN_SECONDS);

add_action('rest_api_init', 'cp_sudoku_rest');
function cp_sudoku_rest() {
	$ns = 'crazy-sudoku/v1';
	$id = '/room/(?P<id>[a-z0-9]{6})';
	$open = '__return_true';
	register_rest_route($ns, '/room', array('methods' => 'POST', 'callback' => 'cp_sudoku_api_create', 'permission_callback' => $open));
	register_rest_route($ns, $id, array('methods' => 'GET', 'callback' => 'cp_sudoku_api_get', 'permission_callback' => $open));
	register_rest_route($ns, $id . '/join', array('methods' => 'POST', 'callback' => 'cp_sudoku_api_join', 'permission_callback' => $open));
	register_rest_route($ns, $id . '/state', array('methods' => 'POST', 'callback' => 'cp_sudoku_api_state', 'permission_callback' => $open));
	register_rest_route($ns, $id . '/rematch', array('methods' => 'POST', 'callback' => 'cp_sudoku_api_rematch', 'permission_callback' => $open));
}

function cp_sudoku_now() { return (int) round(microtime(true) * 1000); }

function cp_sudoku_room($id) { $r = get_transient('cp_sdk_r_' . $id); return is_array($r) ? $r : null; }
function cp_sudoku_room_save($r) { set_transient('cp_sdk_r_' . $r['id'], $r, CP_SDK_TTL); }
function cp_sudoku_seat($id, $seat) { $s = get_transient('cp_sdk_s_' . $id . '_' . $seat); return is_array($s) ? $s : null; }
function cp_sudoku_seat_save($id, $seat, $s) { set_transient('cp_sdk_s_' . $id . '_' . $seat, $s, CP_SDK_TTL); }

function cp_sudoku_err($code, $msg, $status) {
	return new WP_Error($code, $msg, array('status' => $status));
}

function cp_sudoku_name($v, $fallback) {
	$v = trim(sanitize_text_field((string) $v));
	if (function_exists('mb_substr')) { $v = mb_substr($v, 0, 20); } else { $v = substr($v, 0, 20); }
	return $v === '' ? $fallback : $v;
}

/* Feladvány + megoldás ellenőrzése: 81 számjegy, a megoldás érvényes, az adott számok egyeznek. */
function cp_sudoku_valid_puzzle($p, $s) {
	if (!is_string($p) || !is_string($s) || !preg_match('/^[0-9]{81}$/', $p) || !preg_match('/^[1-9]{81}$/', $s)) { return false; }
	for ($i = 0; $i < 81; $i++) {
		if ($p[$i] !== '0' && $p[$i] !== $s[$i]) { return false; }
	}
	for ($k = 0; $k < 9; $k++) {
		$row = $col = $box = array();
		for ($j = 0; $j < 9; $j++) {
			$row[] = $s[$k * 9 + $j];
			$col[] = $s[$j * 9 + $k];
			$box[] = $s[(intdiv($k, 3) * 3 + intdiv($j, 3)) * 9 + ($k % 3) * 3 + $j % 3];
		}
		if (count(array_unique($row)) !== 9 || count(array_unique($col)) !== 9 || count(array_unique($box)) !== 9) { return false; }
	}
	return true;
}

function cp_sudoku_auth($room, $token) {
	$token = (string) $token;
	if ($token === '' || empty($room['seats'])) { return 0; }
	$h = hash('sha256', $token);
	foreach ($room['seats'] as $seat => $info) {
		if (!empty($info['th']) && hash_equals($info['th'], $h)) { return (int) $seat; }
	}
	return 0;
}

function cp_sudoku_payload($room, $extra = array()) {
	$players = array();
	foreach (array(1, 2) as $seat) {
		if (empty($room['seats'][$seat])) { continue; }
		$s = cp_sudoku_seat($room['id'], $seat);
		if (!$s || (int) $s['round'] !== (int) $room['round']) {
			$s = array('round' => $room['round'], 'values' => $room['puzzle'], 'mistakes' => 0, 'score' => 0, 'done' => 0, 'out' => false, 'seen' => 0);
		}
		$s['name'] = $room['seats'][$seat]['name'];
		$players[$seat] = $s;
	}
	$res = new WP_REST_Response(array_merge(array(
		'now'  => cp_sudoku_now(),
		'room' => array(
			'id'       => $room['id'],
			'diff'     => $room['diff'],
			'round'    => (int) $room['round'],
			'puzzle'   => $room['puzzle'],
			'solution' => $room['solution'],
			'start'    => (int) $room['start'],
		),
		'players' => (object) $players,
	), $extra));
	$res->header('Cache-Control', 'no-store, max-age=0');
	return $res;
}

function cp_sudoku_api_create(WP_REST_Request $req) {
	$diff = (string) $req->get_param('diff');
	if (!in_array($diff, array('easy', 'medium', 'hard', 'expert', 'extreme'), true)) { $diff = 'medium'; }
	$p = (string) $req->get_param('puzzle');
	$s = (string) $req->get_param('solution');
	if (!cp_sudoku_valid_puzzle($p, $s)) { return cp_sudoku_err('cp_sdk_puzzle', 'Hibás feladvány.', 400); }
	$chars = 'abcdefghjkmnpqrstuvwxyz23456789';
	do {
		$id = '';
		for ($i = 0; $i < 6; $i++) { $id .= $chars[random_int(0, strlen($chars) - 1)]; }
	} while (cp_sudoku_room($id));
	$token = bin2hex(random_bytes(16));
	$room = array(
		'id' => $id, 'diff' => $diff, 'round' => 1, 'puzzle' => $p, 'solution' => $s, 'start' => 0,
		'created' => time(),
		'seats' => array(1 => array('name' => cp_sudoku_name($req->get_param('name'), 'Játékos 1'), 'th' => hash('sha256', $token))),
	);
	cp_sudoku_room_save($room);
	return cp_sudoku_payload($room, array('token' => $token, 'seat' => 1));
}

function cp_sudoku_api_get(WP_REST_Request $req) {
	$room = cp_sudoku_room($req['id']);
	if (!$room) { return cp_sudoku_err('cp_sdk_room', 'A szoba nem létezik vagy lejárt.', 404); }
	return cp_sudoku_payload($room);
}

function cp_sudoku_api_join(WP_REST_Request $req) {
	$room = cp_sudoku_room($req['id']);
	if (!$room) { return cp_sudoku_err('cp_sdk_room', 'A szoba nem létezik vagy lejárt.', 404); }
	$seat = cp_sudoku_auth($room, $req->get_param('token'));
	if ($seat) { return cp_sudoku_payload($room, array('seat' => $seat)); }
	if (!empty($room['seats'][2])) { return cp_sudoku_err('cp_sdk_full', 'Ebben a szobában már ketten játszanak.', 409); }
	$token = bin2hex(random_bytes(16));
	$room['seats'][2] = array('name' => cp_sudoku_name($req->get_param('name'), 'Játékos 2'), 'th' => hash('sha256', $token));
	$room['start'] = cp_sudoku_now() + 3000; // visszaszámlálás
	cp_sudoku_room_save($room);
	return cp_sudoku_payload($room, array('token' => $token, 'seat' => 2));
}

function cp_sudoku_api_state(WP_REST_Request $req) {
	$room = cp_sudoku_room($req['id']);
	if (!$room) { return cp_sudoku_err('cp_sdk_room', 'A szoba nem létezik vagy lejárt.', 404); }
	$seat = cp_sudoku_auth($room, $req->get_param('token'));
	if (!$seat) { return cp_sudoku_err('cp_sdk_token', 'Érvénytelen játékos.', 403); }
	if ((int) $req->get_param('round') === (int) $room['round']) {
		$values = (string) $req->get_param('values');
		if (!preg_match('/^[0-9]{81}$/', $values)) { return cp_sudoku_err('cp_sdk_values', 'Hibás állás.', 400); }
		$prev = cp_sudoku_seat($room['id'], $seat);
		if (!$prev || (int) $prev['round'] !== (int) $room['round']) { $prev = array('done' => 0, 'out' => false); }
		$solved = $values === $room['solution'];
		$now = cp_sudoku_now();
		cp_sudoku_seat_save($room['id'], $seat, array(
			'round'    => (int) $room['round'],
			'values'   => $values,
			'mistakes' => max(0, min(99, (int) $req->get_param('mistakes'))),
			'score'    => max(0, min(1000000, (int) $req->get_param('score'))),
			// a befejezés idejét a szerver rögzíti, egyszer
			'done'     => $prev['done'] ? $prev['done'] : ($solved ? $now : 0),
			'out'      => !empty($prev['out']) || (bool) $req->get_param('out'),
			'seen'     => $now,
		));
	}
	return cp_sudoku_payload($room);
}

function cp_sudoku_api_rematch(WP_REST_Request $req) {
	$room = cp_sudoku_room($req['id']);
	if (!$room) { return cp_sudoku_err('cp_sdk_room', 'A szoba nem létezik vagy lejárt.', 404); }
	if (!cp_sudoku_auth($room, $req->get_param('token'))) { return cp_sudoku_err('cp_sdk_token', 'Érvénytelen játékos.', 403); }
	// ha a másik játékos már indított visszavágót, azt kapja meg
	if ((int) $req->get_param('round') === (int) $room['round']) {
		$p = (string) $req->get_param('puzzle');
		$s = (string) $req->get_param('solution');
		if (!cp_sudoku_valid_puzzle($p, $s)) { return cp_sudoku_err('cp_sdk_puzzle', 'Hibás feladvány.', 400); }
		$room['round']++;
		$room['puzzle'] = $p;
		$room['solution'] = $s;
		$room['start'] = empty($room['seats'][2]) ? 0 : cp_sudoku_now() + 3000;
		cp_sudoku_room_save($room);
	}
	return cp_sudoku_payload($room);
}
