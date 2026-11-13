<?php
/**
 * Plugin Name: Crazy galéria (szelfigépek)
 * Description: A Crazy Booth szelfigépek felhő-galériája: a gépek ide töltik fel a képeket (gépkulccsal, amit az eventcrazy.hu panel ellenőriz), a vendégek a QR-kóddal a /galeria/<rendezvény>/<kép> oldalon töltik le. A képek 60 nap után törlődnek.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) { exit; }
define('CG_VER', '0.1.0');
define('CG_KEEP_DAYS', 60);
define('CG_PANEL', 'https://eventcrazy.hu/wp-json/crazy/v1/booth/verify');

function cg_t() { global $wpdb; return $wpdb->prefix . 'crazy_galeria'; }
function cg_dir() { $u = wp_upload_dir(null, false); return trailingslashit($u['basedir']) . 'crazy-galeria'; }
function cg_url() { $u = wp_upload_dir(null, false); return trailingslashit($u['baseurl']) . 'crazy-galeria'; }
function cg_tok_ok($s) { return is_string($s) && preg_match('/^[a-z0-9]{6,32}$/', $s); }

register_activation_hook(__FILE__, 'cg_install');
add_action('plugins_loaded', function () { if (get_option('cg_db') !== CG_VER) { cg_install(); } });
function cg_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cg_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ev varchar(32) NOT NULL,
  photo varchar(32) NOT NULL,
  deal bigint(20) unsigned NOT NULL DEFAULT 0,
  evdate date NULL,
  device varchar(60) NOT NULL DEFAULT '',
  file varchar(120) NOT NULL DEFAULT '',
  w int(10) unsigned NOT NULL DEFAULT 0,
  h int(10) unsigned NOT NULL DEFAULT 0,
  bytes int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY evphoto (ev, photo),
  KEY ev (ev),
  KEY created_at (created_at)
) " . $wpdb->get_charset_collate() . ';');
	wp_mkdir_p(cg_dir());
	@file_put_contents(cg_dir() . '/index.php', "<?php // Silence is golden.\n");
	update_option('cg_db', CG_VER, false);
}

/* ---------- gépkulcs: az eventcrazy.hu panel ellenőrzi; a jó kulcsot 24 órára megjegyezzük (panel-kiesés esetére) */
function cg_device() {
	$key = isset($_SERVER['HTTP_X_BOOTH_KEY']) ? trim((string) $_SERVER['HTTP_X_BOOTH_KEY']) : '';
	if (strlen($key) < 20 || strlen($key) > 200) { return null; }
	$h = hash('sha256', $key);
	$c = get_transient('cg_dev_' . $h);
	if (is_array($c)) { return $c['name']; }
	if (get_transient('cg_bad_' . $h)) { return null; }
	/* a tartalékon a WordPress kimenő kérései tiltva vannak (WP_HTTP_BLOCK_EXTERNAL) – csak a panel felé, közvetlenül kérdezünk */
	$b = null;
	if (function_exists('curl_init')) {
		$ch = curl_init(CG_PANEL);
		curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(array('h' => $h)), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true));
		$body = curl_exec($ch); curl_close($ch);
		$b = is_string($body) ? json_decode($body, true) : null;
	}
	if (is_array($b) && !empty($b['ok']) && !empty($b['name'])) { set_transient('cg_dev_' . $h, array('name' => (string) $b['name']), DAY_IN_SECONDS); return (string) $b['name']; }
	if (is_array($b) && isset($b['ok']) && !$b['ok']) { set_transient('cg_bad_' . $h, 1, 10 * MINUTE_IN_SECONDS); }
	return null;
}

/* ---------- feltöltés: POST /wp-json/crazy-galeria/v1/upload (multipart: ev, photo, deal, date, file) */
add_action('rest_api_init', function () {
	register_rest_route('crazy-galeria/v1', '/upload', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'cg_upload'));
	register_rest_route('crazy-galeria/v1', '/event/(?P<ev>[a-z0-9]{6,32})', array('methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function ($r) {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare('SELECT photo, created_at FROM ' . cg_t() . ' WHERE ev = %s ORDER BY id DESC LIMIT 2000', $r['ev']), ARRAY_A);
		return array('ev' => $r['ev'], 'n' => count($rows), 'url' => home_url('/galeria/' . $r['ev'] . '/'), 'photos' => array_column($rows, 'photo'));
	}));
});
function cg_upload(WP_REST_Request $req) {
	global $wpdb;
	$dev = cg_device();
	if (!$dev) { return new WP_REST_Response(array('ok' => false, 'message' => 'Ismeretlen vagy letiltott gépkulcs.'), 401); }
	$ev = (string) $req->get_param('ev'); $ph = (string) $req->get_param('photo');
	if (!cg_tok_ok($ev) || !cg_tok_ok($ph)) { return new WP_REST_Response(array('ok' => false, 'message' => 'Hibás azonosító.'), 400); }
	if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cg_t() . ' WHERE ev = %s AND photo = %s', $ev, $ph))) { return array('ok' => true, 'dup' => true, 'url' => home_url('/galeria/' . $ev . '/' . $ph)); }
	$f = $req->get_file_params(); $f = isset($f['file']) ? $f['file'] : null;
	if (!$f || !empty($f['error']) || !is_uploaded_file($f['tmp_name'])) { return new WP_REST_Response(array('ok' => false, 'message' => 'Nincs fájl.'), 400); }
	if ((int) $f['size'] > 25 * MB_IN_BYTES) { return new WP_REST_Response(array('ok' => false, 'message' => 'Túl nagy fájl.'), 413); }
	$info = @getimagesize($f['tmp_name']);
	if (!$info || !in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG), true)) { return new WP_REST_Response(array('ok' => false, 'message' => 'Csak JPG / PNG.'), 415); }
	$dir = cg_dir() . '/' . $ev; wp_mkdir_p($dir);
	if (!is_file($dir . '/index.php')) { @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n"); }
	/* újrakódolás (biztonság + méret): legfeljebb 2000 px, JPG; és 480 px-es bélyegkép */
	$ed = wp_get_image_editor($f['tmp_name']);
	if (is_wp_error($ed)) { return new WP_REST_Response(array('ok' => false, 'message' => 'A kép nem dolgozható fel.'), 415); }
	$ed->resize(2000, 2000, false); $ed->set_quality(86);
	$sv = $ed->save($dir . '/' . $ph . '.jpg', 'image/jpeg');
	if (is_wp_error($sv)) { return new WP_REST_Response(array('ok' => false, 'message' => 'Mentési hiba.'), 500); }
	$th = wp_get_image_editor($dir . '/' . $ph . '.jpg');
	if (!is_wp_error($th)) { $th->resize(480, 480, false); $th->set_quality(78); $th->save($dir . '/' . $ph . '_t.jpg', 'image/jpeg'); }
	$d = (string) $req->get_param('date');
	$wpdb->insert(cg_t(), array('ev' => $ev, 'photo' => $ph, 'deal' => (int) $req->get_param('deal'), 'evdate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null, 'device' => mb_substr($dev, 0, 60), 'file' => $ph . '.jpg', 'w' => (int) $sv['width'], 'h' => (int) $sv['height'], 'bytes' => (int) @filesize($sv['path']), 'created_at' => current_time('mysql', true)));
	return array('ok' => true, 'url' => home_url('/galeria/' . $ev . '/' . $ph));
}

/* ---------- nyilvános oldalak: /galeria/<ev>/ és /galeria/<ev>/<kép> (?dl=1 letöltés) */
add_action('init', function () {
	$p = (string) parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
	if (!preg_match('#^/galeria/([a-z0-9]{6,32})(?:/([a-z0-9]{6,32}))?/?$#', $p, $m)) { return; }
	nocache_headers(); header('X-Robots-Tag: noindex, nofollow', true);
	global $wpdb;
	$ev = $m[1]; $ph = isset($m[2]) ? $m[2] : '';
	if ($ph !== '') {
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cg_t() . ' WHERE ev = %s AND photo = %s', $ev, $ph));
		if ($row && isset($_GET['dl'])) {
			$path = cg_dir() . '/' . $ev . '/' . $row->file;
			if (!is_file($path)) { status_header(404); exit; }
			header('Content-Type: image/jpeg'); header('Content-Length: ' . filesize($path));
			header('Content-Disposition: attachment; filename="eventcrazy-' . $ph . '.jpg"');
			readfile($path); exit;
		}
		cg_page($row ? 'photo' : 'wait', $ev, $row); exit;
	}
	$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cg_t() . ' WHERE ev = %s ORDER BY id DESC LIMIT 2000', $ev));
	cg_page($rows ? 'event' : 'empty', $ev, $rows); exit;
}, 1);

function cg_page($kind, $ev, $data) {
	$u = cg_url() . '/' . $ev . '/';
	$css = ':root{--n:#141C33;--o:#E8894A;--g:linear-gradient(120deg,#E8894A,#F2B35B)}*{box-sizing:border-box}body{margin:0;background:#F6F3EE;color:#1A1F2B;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}'
		. 'header{background:var(--n);color:#fff;padding:12px 16px;font:800 20px system-ui,sans-serif}header span{background:var(--g);-webkit-background-clip:text;background-clip:text;color:transparent}'
		. 'main{max-width:1100px;margin:0 auto;padding:16px}h1{font-size:22px;margin:4px 0 12px}.big{display:block;max-width:100%;max-height:72vh;margin:0 auto 14px;border-radius:10px;box-shadow:0 10px 30px rgba(20,28,51,.18);background:#fff}'
		. '.btn{display:flex;align-items:center;justify-content:center;max-width:420px;margin:0 auto 10px;height:52px;border-radius:999px;background:var(--g);color:#141C33;font-weight:800;text-decoration:none;font-size:17px}.btn.ghost{background:#fff;border:1.5px solid var(--o);color:#B85A1C}'
		. '.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}.grid a{display:block;aspect-ratio:2/3;background:#e9e4dc center/cover no-repeat;border-radius:8px}.mu{color:#5B6275;text-align:center}'
		. 'footer{text-align:center;color:#5B6275;font-size:13px;padding:20px}';
	$refresh = $kind === 'wait' ? '<meta http-equiv="refresh" content="15">' : '';
	echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">' . $refresh . '<title>EventCrazy fotók</title><style>' . $css . '</style></head><body><header>Event<span>Crazy</span></header><main>';
	if ($kind === 'photo') {
		echo '<img class="big" src="' . esc_url($u . $data->file) . '" alt="A fotód">';
		echo '<a class="btn" href="?dl=1">Letöltés</a><a class="btn ghost" href="' . esc_url(home_url('/galeria/' . $ev . '/')) . '">A rendezvény összes fotója</a>';
		echo '<p class="mu">Tipp: telefonon a képet hosszan nyomva is elmentheted.</p>';
	} elseif ($kind === 'wait') {
		echo '<h1>A fotód úton van…</h1><p class="mu">A kép feltöltése folyamatban – ez az oldal magától frissül. Ha a helyszínen gyenge a net, pár perc is lehet.</p>';
	} elseif ($kind === 'event') {
		echo '<h1>A rendezvény fotói <small class="mu">(' . count($data) . ')</small></h1><div class="grid">';
		foreach ($data as $r) { echo '<a href="' . esc_url(home_url('/galeria/' . $ev . '/' . $r->photo)) . '" style="background-image:url(' . esc_url($u . $r->photo . '_t.jpg') . ')" aria-label="Fotó"></a>'; }
		echo '</div>';
	} else {
		status_header(404);
		echo '<h1>Még nincs itt fotó</h1><p class="mu">Ha most fotóztatok, nézz vissza pár perc múlva.</p>';
	}
	echo '</main><footer>A fotók ' . CG_KEEP_DAYS . ' napig érhetők el. · EventCrazy</footer></body></html>';
}

/* ---------- 60 napnál régebbi képek törlése (naponta) */
add_action('init', function () { if (!wp_next_scheduled('cg_cleanup')) { wp_schedule_event(time() + 600, 'daily', 'cg_cleanup'); } });
add_action('cg_cleanup', function () {
	global $wpdb;
	$old = $wpdb->get_results($wpdb->prepare('SELECT id, ev, photo, file FROM ' . cg_t() . ' WHERE created_at < %s LIMIT 5000', gmdate('Y-m-d H:i:s', time() - CG_KEEP_DAYS * DAY_IN_SECONDS)));
	foreach ((array) $old as $r) {
		@unlink(cg_dir() . '/' . $r->ev . '/' . $r->file); @unlink(cg_dir() . '/' . $r->ev . '/' . $r->photo . '_t.jpg');
		$wpdb->delete(cg_t(), array('id' => (int) $r->id));
	}
});
