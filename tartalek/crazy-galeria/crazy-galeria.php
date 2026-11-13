<?php
/**
 * Plugin Name: Crazy galéria (szelfigépek)
 * Description: A Crazy Booth szelfigépek felhő-galériája: a gépek ide töltik fel a képeket (gépkulccsal, amit az eventcrazy.hu panel ellenőriz), a vendégek a QR-kóddal a /galeria/<rendezvény>/<kép> oldalon töltik le. A képek 60 nap után törlődnek.
 * Version: 0.3.0 (videó / boomerang / videós üzenet is; ZIP, jelszó, cím, elrejtés – a panelből kezelve)
 */
if (!defined('ABSPATH')) { exit; }
define('CG_VER', '0.4.0');
define('CG_KEEP_DAYS', 60);
define('CG_PANEL', 'https://eventcrazy.hu/wp-json/crazy/v1/booth/verify');

function cg_t() { global $wpdb; return $wpdb->prefix . 'crazy_galeria'; }
function cg_pt() { global $wpdb; return $wpdb->prefix . 'crazy_print'; }
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
  hidden tinyint(1) NOT NULL DEFAULT 0,
  kind varchar(12) NOT NULL DEFAULT 'photo',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY evphoto (ev, photo),
  KEY ev (ev),
  KEY created_at (created_at)
) " . $wpdb->get_charset_collate() . ';');
	/* v0.4: nyomtatási sor (nyomtatóállomás: a képet egy másik gép nyomtatja) */
	dbDelta('CREATE TABLE ' . cg_pt() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ev varchar(32) NOT NULL,
  photo varchar(32) NOT NULL,
  copies tinyint(3) unsigned NOT NULL DEFAULT 1,
  src varchar(60) NOT NULL DEFAULT '',
  station varchar(60) NOT NULL DEFAULT '',
  status varchar(8) NOT NULL DEFAULT 'new',
  msg varchar(190) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY evst (ev, status),
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

/* ---------- rendezvény-beállítások (a panelből): cím, jelszó (hash) */
function cg_evset($ev) { $a = get_option('cg_events', array()); return is_array($a) && isset($a[$ev]) && is_array($a[$ev]) ? $a[$ev] : array(); }
function cg_evset_put($ev, $v) { $a = get_option('cg_events', array()); if (!is_array($a)) { $a = array(); } $a[$ev] = $v; update_option('cg_events', $a, false); }
/* a panel kulcsa (a panelben „Panel” nevű gép) – csak az kezelheti a galériát */
function cg_is_panel() { $d = cg_device(); return $d === 'Panel'; }
/* jelszavas galéria: süti a helyes jelszó után (30 nap) */
function cg_unlocked($ev) {
	$s = cg_evset($ev); if (empty($s['pw'])) { return true; }
	$c = isset($_COOKIE['cg_' . $ev]) ? (string) $_COOKIE['cg_' . $ev] : '';
	return $c !== '' && hash_equals(hash_hmac('sha256', $ev . '|' . $s['pw'], wp_salt('auth')), $c);
}

/* ---------- feltöltés: POST /wp-json/crazy-galeria/v1/upload (multipart: ev, photo, deal, date, file) */
add_action('rest_api_init', function () {
	register_rest_route('crazy-galeria/v1', '/upload', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'cg_upload'));
	register_rest_route('crazy-galeria/v1', '/event/(?P<ev>[a-z0-9]{6,32})', array('methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function ($r) {
		global $wpdb;
		$n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cg_t() . ' WHERE ev = %s AND hidden = 0', $r['ev']));
		return array('ev' => $r['ev'], 'n' => $n, 'url' => home_url('/galeria/' . $r['ev'] . '/'));
	}));
	/* kezelés a panelből (csak a panel kulcsával): képek listája + beállítások; beállítás; elrejtés */
	register_rest_route('crazy-galeria/v1', '/admin/(?P<ev>[a-z0-9]{6,32})', array(
		array('methods' => 'GET', 'permission_callback' => 'cg_is_panel', 'callback' => function ($r) {
			global $wpdb; $ev = $r['ev']; $s = cg_evset($ev);
			$rows = $wpdb->get_results($wpdb->prepare('SELECT photo, hidden, device, created_at FROM ' . cg_t() . ' WHERE ev = %s ORDER BY id DESC LIMIT 3000', $ev), ARRAY_A);
			foreach ($rows as &$x) { $x['thumb'] = cg_url() . '/' . $ev . '/' . $x['photo'] . '_t.jpg'; $x['hidden'] = (int) $x['hidden']; } unset($x);
			return array('ev' => $ev, 'title' => (string) ($s['title'] ?? ''), 'has_pw' => !empty($s['pw']), 'photos' => $rows, 'url' => home_url('/galeria/' . $ev . '/'));
		}),
		array('methods' => 'POST', 'permission_callback' => 'cg_is_panel', 'callback' => function ($r) {
			global $wpdb; $ev = $r['ev']; $s = cg_evset($ev);
			if ($r->get_param('title') !== null) { $s['title'] = mb_substr(sanitize_text_field((string) $r->get_param('title')), 0, 120); }
			if ($r->get_param('password') !== null) { $pw = trim((string) $r->get_param('password')); if ($pw === '') { unset($s['pw']); } else { $s['pw'] = wp_hash_password($pw); } }
			cg_evset_put($ev, $s);
			$ph = (string) $r->get_param('photo');
			if ($ph !== '' && cg_tok_ok($ph) && $r->get_param('hidden') !== null) { $wpdb->update(cg_t(), array('hidden' => $r->get_param('hidden') ? 1 : 0), array('ev' => $ev, 'photo' => $ph)); }
			return array('ok' => true, 'has_pw' => !empty($s['pw']), 'title' => (string) ($s['title'] ?? ''));
		}),
	));
});
function cg_upload(WP_REST_Request $req) {
	global $wpdb;
	$dev = cg_device();
	if (!$dev) { return new WP_REST_Response(array('ok' => false, 'message' => 'Ismeretlen vagy letiltott gépkulcs.'), 401); }
	$ev = (string) $req->get_param('ev'); $ph = (string) $req->get_param('photo');
	if (!cg_tok_ok($ev) || !cg_tok_ok($ph)) { return new WP_REST_Response(array('ok' => false, 'message' => 'Hibás azonosító.'), 400); }
	if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cg_t() . ' WHERE ev = %s AND photo = %s', $ev, $ph))) { return array('ok' => true, 'dup' => true, 'url' => home_url('/galeria/' . $ev . '/' . $ph)); }
	$fp = $req->get_file_params(); $f = isset($fp['file']) ? $fp['file'] : null;
	if (!$f || !empty($f['error']) || !is_uploaded_file($f['tmp_name'])) { return new WP_REST_Response(array('ok' => false, 'message' => 'Nincs fájl.'), 400); }
	/* v0.3: videó (MP4 / WebM) – a fájl tartalma alapján ellenőrizve, eredeti formában tárolva; a borítókép külön („poster”) */
	$head = (string) @file_get_contents($f['tmp_name'], false, null, 0, 16);
	$vext = substr($head, 4, 4) === 'ftyp' ? 'mp4' : (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0 ? 'webm' : '');
	if ($vext !== '') {
		if ((int) $f['size'] > 80 * MB_IN_BYTES) { return new WP_REST_Response(array('ok' => false, 'message' => 'Túl nagy videó.'), 413); }
		$kind = in_array((string) $req->get_param('kind'), array('video', 'guestbook'), true) ? (string) $req->get_param('kind') : 'video';
		$dir = cg_dir() . '/' . $ev; wp_mkdir_p($dir);
		if (!is_file($dir . '/index.php')) { @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n"); }
		if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $ph . '.' . $vext)) { return new WP_REST_Response(array('ok' => false, 'message' => 'Mentési hiba.'), 500); }
		$po = isset($fp['poster']) ? $fp['poster'] : null; $w = 0; $h = 0;
		if ($po && empty($po['error']) && is_uploaded_file($po['tmp_name']) && @getimagesize($po['tmp_name'])) {
			$ed = wp_get_image_editor($po['tmp_name']);
			if (!is_wp_error($ed)) { $ed->resize(1600, 1600, false); $ed->set_quality(84); $sv = $ed->save($dir . '/' . $ph . '.jpg', 'image/jpeg'); if (!is_wp_error($sv)) { $w = (int) $sv['width']; $h = (int) $sv['height']; $th = wp_get_image_editor($sv['path']); if (!is_wp_error($th)) { $th->resize(480, 480, false); $th->set_quality(78); $th->save($dir . '/' . $ph . '_t.jpg', 'image/jpeg'); } } }
		}
		$d = (string) $req->get_param('date');
		$wpdb->insert(cg_t(), array('ev' => $ev, 'photo' => $ph, 'deal' => (int) $req->get_param('deal'), 'evdate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null, 'device' => mb_substr($dev, 0, 60), 'file' => $ph . '.' . $vext, 'w' => $w, 'h' => $h, 'bytes' => (int) @filesize($dir . '/' . $ph . '.' . $vext), 'kind' => $kind, 'created_at' => current_time('mysql', true)));
		return array('ok' => true, 'url' => home_url('/galeria/' . $ev . '/' . $ph));
	}
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
	if (!preg_match('#^/galeria/([a-z0-9]{6,32})(?:/([a-z0-9]{6,32}|zip))?/?$#', $p, $m)) { return; }
	nocache_headers(); header('X-Robots-Tag: noindex, nofollow', true);
	global $wpdb;
	$ev = $m[1]; $ph = isset($m[2]) ? $m[2] : '';
	/* jelszó beküldése */
	if ($ph === '' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw'])) {
		$s = cg_evset($ev);
		if (!empty($s['pw']) && wp_check_password(wp_unslash((string) $_POST['pw']), $s['pw'])) {
			setcookie('cg_' . $ev, hash_hmac('sha256', $ev . '|' . $s['pw'], wp_salt('auth')), array('expires' => time() + 30 * DAY_IN_SECONDS, 'path' => '/galeria/' . $ev . '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'));
			wp_safe_redirect(home_url('/galeria/' . $ev . '/')); exit;
		}
		cg_page('pw', $ev, array('bad' => 1)); exit;
	}
	/* összes kép ZIP-ben */
	if ($ph === 'zip') {
		if (!cg_unlocked($ev)) { cg_page('pw', $ev, array()); exit; }
		$rows = $wpdb->get_results($wpdb->prepare('SELECT photo, file FROM ' . cg_t() . ' WHERE ev = %s AND hidden = 0 ORDER BY id', $ev));
		if (!$rows || !class_exists('ZipArchive')) { cg_page('empty', $ev, null); exit; }
		@set_time_limit(120);
		$tmp = wp_tempnam('cg-zip'); $z = new ZipArchive();
		if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) { status_header(500); exit; }
		$i = 0; foreach ($rows as $r) { $f = cg_dir() . '/' . $ev . '/' . $r->file; if (is_file($f)) { $z->addFile($f, sprintf('eventcrazy-%03d.', ++$i) . strtolower(pathinfo($r->file, PATHINFO_EXTENSION))); } }
		$z->close();
		header('Content-Type: application/zip'); header('Content-Length: ' . filesize($tmp)); header('Content-Disposition: attachment; filename="eventcrazy-fotok.zip"');
		readfile($tmp); @unlink($tmp); exit;
	}
	if ($ph !== '') {
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cg_t() . ' WHERE ev = %s AND photo = %s AND hidden = 0', $ev, $ph));
		if ($row && isset($_GET['dl'])) {
			$path = cg_dir() . '/' . $ev . '/' . $row->file;
			if (!is_file($path)) { status_header(404); exit; }
			$ext = strtolower(pathinfo($row->file, PATHINFO_EXTENSION));
			header('Content-Type: ' . ($ext === 'mp4' ? 'video/mp4' : ($ext === 'webm' ? 'video/webm' : 'image/jpeg'))); header('Content-Length: ' . filesize($path));
			header('Content-Disposition: attachment; filename="eventcrazy-' . $ph . '.' . $ext . '"');
			readfile($path); exit;
		}
		cg_page($row ? 'photo' : 'wait', $ev, $row); exit;
	}
	if (!cg_unlocked($ev)) { cg_page('pw', $ev, array()); exit; }
	$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cg_t() . ' WHERE ev = %s AND hidden = 0 ORDER BY id DESC LIMIT 2000', $ev));
	cg_page($rows ? 'event' : 'empty', $ev, $rows); exit;
}, 1);

function cg_page($kind, $ev, $data) {
	$u = cg_url() . '/' . $ev . '/';
	$css = ':root{--n:#141C33;--o:#E8894A;--g:linear-gradient(120deg,#E8894A,#F2B35B)}*{box-sizing:border-box}body{margin:0;background:#F6F3EE;color:#1A1F2B;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}'
		. 'header{background:var(--n);color:#fff;padding:12px 16px;font:800 20px system-ui,sans-serif}header span{background:var(--g);-webkit-background-clip:text;background-clip:text;color:transparent}'
		. 'main{max-width:1100px;margin:0 auto;padding:16px}h1{font-size:22px;margin:4px 0 12px}.big{display:block;max-width:100%;max-height:72vh;margin:0 auto 14px;border-radius:10px;box-shadow:0 10px 30px rgba(20,28,51,.18);background:#fff}'
		. '.btn{display:flex;align-items:center;justify-content:center;max-width:420px;margin:0 auto 10px;height:52px;border-radius:999px;background:var(--g);color:#141C33;font-weight:800;text-decoration:none;font-size:17px}.btn.ghost{background:#fff;border:1.5px solid var(--o);color:#B85A1C}'
		. '.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}.grid a{position:relative;display:block;aspect-ratio:2/3;background:#e9e4dc center/cover no-repeat;border-radius:8px}.grid .pl{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:44px;height:44px;border-radius:50%;background:rgba(0,0,0,.55);color:#fff;font-style:normal;display:flex;align-items:center;justify-content:center;font-size:18px}.mu{color:#5B6275;text-align:center}'
		. 'footer{text-align:center;color:#5B6275;font-size:13px;padding:20px}';
	$refresh = $kind === 'wait' ? '<meta http-equiv="refresh" content="15">' : '';
	echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">' . $refresh . '<title>EventCrazy fotók</title><style>' . $css . '</style></head><body><header>Event<span>Crazy</span></header><main>';
	$st = cg_evset($ev); $title = isset($st['title']) ? (string) $st['title'] : '';
	if ($title !== '') { echo '<h1>' . esc_html($title) . '</h1>'; }
	if ($kind === 'pw') {
		echo '<h1>' . ($title !== '' ? 'A galéria jelszóval védett' : 'Jelszóval védett galéria') . '</h1><p class="mu">Az összes fotó megtekintéséhez add meg a jelszót, amit a szervezőtől kaptál.</p>';
		echo '<form method="post" style="max-width:420px;margin:0 auto"><input type="password" name="pw" required autofocus placeholder="Jelszó" style="width:100%;height:50px;border-radius:12px;border:1.5px solid #E7E2DA;padding:0 14px;font-size:16px;margin-bottom:10px">';
		if (!empty($data['bad'])) { echo '<p class="mu" style="color:#C2392F">Hibás jelszó – próbáld újra.</p>'; }
		echo '<button class="btn" style="width:100%;border:0;cursor:pointer">Megnyitás</button></form>';
	} elseif ($kind === 'photo') {
		if (preg_match('/\.(mp4|webm)$/', $data->file)) { echo '<video class="big" src="' . esc_url($u . $data->file) . '"' . (is_file(cg_dir() . '/' . $ev . '/' . $data->photo . '.jpg') ? ' poster="' . esc_url($u . $data->photo . '.jpg') . '"' : '') . ' controls autoplay muted loop playsinline></video>'; }
		else { echo '<img class="big" src="' . esc_url($u . $data->file) . '" alt="A fotód">'; }
		echo '<a class="btn" href="?dl=1">Letöltés</a><a class="btn ghost" href="' . esc_url(home_url('/galeria/' . $ev . '/')) . '">A rendezvény összes fotója</a>';
		echo '<p class="mu">Tipp: telefonon a képet hosszan nyomva is elmentheted.</p>';
	} elseif ($kind === 'wait') {
		echo '<h1>A fotód úton van…</h1><p class="mu">A kép feltöltése folyamatban – ez az oldal magától frissül. Ha a helyszínen gyenge a net, pár perc is lehet.</p>';
	} elseif ($kind === 'event') {
		echo '<h1>' . ($title !== '' ? 'Fotók' : 'A rendezvény fotói') . ' <small class="mu">(' . count($data) . ')</small></h1>';
		echo '<a class="btn ghost" href="' . esc_url(home_url('/galeria/' . $ev . '/zip')) . '" style="margin-bottom:14px">Összes letöltése (ZIP)</a><div class="grid">';
		foreach ($data as $r) { $vid = preg_match('/\.(mp4|webm)$/', $r->file); echo '<a href="' . esc_url(home_url('/galeria/' . $ev . '/' . $r->photo)) . '" style="background-image:url(' . esc_url($u . $r->photo . '_t.jpg') . ')" aria-label="' . ($vid ? 'Videó' : 'Fotó') . '">' . ($vid ? '<i class="pl">▶</i>' : '') . '</a>'; }
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
		@unlink(cg_dir() . '/' . $r->ev . '/' . $r->file); @unlink(cg_dir() . '/' . $r->ev . '/' . $r->photo . '_t.jpg'); @unlink(cg_dir() . '/' . $r->ev . '/' . $r->photo . '.jpg');
		$wpdb->delete(cg_t(), array('id' => (int) $r->id));
	}
});


/* ---------- v0.4 nyomtatási sor: a fotózó gép feladja, a nyomtatóállomás (ugyanarra a rendezvényre állított másik gép) kinyomtatja.
 * Minden hívás gépkulccsal (X-Booth-Key). A kép a galériából jön (a feltöltés után), base64-ben. */
add_action('rest_api_init', function () {
	$dev = function () { return cg_device() ? true : new WP_Error('cg_key', 'Ismeretlen vagy letiltott gépkulcs.', array('status' => 401)); };
	register_rest_route('crazy-galeria/v1', '/print', array('methods' => 'POST', 'permission_callback' => $dev, 'callback' => function ($r) {
		global $wpdb; $ev = (string) $r->get_param('ev'); $ph = (string) $r->get_param('photo');
		if (!cg_tok_ok($ev) || !cg_tok_ok($ph)) { return new WP_REST_Response(array('ok' => false, 'message' => 'Hibás azonosító.'), 400); }
		$now = current_time('mysql', true);
		$wpdb->insert(cg_pt(), array('ev' => $ev, 'photo' => $ph, 'copies' => max(1, min(10, (int) $r->get_param('copies'))), 'src' => mb_substr((string) cg_device(), 0, 60), 'status' => 'new', 'created_at' => $now, 'updated_at' => $now));
		$ahead = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cg_pt() . " WHERE ev = %s AND status IN ('new','taken') AND id < %d", $ev, $wpdb->insert_id));
		return array('ok' => true, 'id' => (int) $wpdb->insert_id, 'ahead' => $ahead);
	}));
	register_rest_route('crazy-galeria/v1', '/print/next', array('methods' => 'GET', 'permission_callback' => $dev, 'callback' => function ($r) {
		global $wpdb; $ev = (string) $r->get_param('ev'); $st = mb_substr((string) cg_device(), 0, 60);
		if (!cg_tok_ok($ev)) { return new WP_REST_Response(array('ok' => false, 'message' => 'Hibás azonosító.'), 400); }
		$now = current_time('mysql', true); $pt = cg_pt();
		/* 3 percnél régebben felvett, de le nem zárt feladat visszakerül a sorba; 3 napnál régebbi lezártak törlése */
		$wpdb->query($wpdb->prepare("UPDATE $pt SET status = 'new', station = '' WHERE status = 'taken' AND updated_at < %s", gmdate('Y-m-d H:i:s', time() - 180)));
		$wpdb->query($wpdb->prepare("DELETE FROM $pt WHERE status IN ('done','err') AND updated_at < %s", gmdate('Y-m-d H:i:s', time() - 3 * 86400)));
		$waiting = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $pt WHERE ev = %s AND status = 'new'", $ev));
		/* csak a már feltöltött képek */
		$job = $wpdb->get_row($wpdb->prepare("SELECT p.id, p.photo, p.copies, p.src, g.file FROM $pt p JOIN " . cg_t() . " g ON g.ev = p.ev AND g.photo = p.photo WHERE p.ev = %s AND p.status = 'new' ORDER BY p.id LIMIT 1", $ev));
		if (!$job) { return array('ok' => true, 'job' => null, 'waiting' => $waiting); }
		$ok = $wpdb->query($wpdb->prepare("UPDATE $pt SET status = 'taken', station = %s, updated_at = %s WHERE id = %d AND status = 'new'", $st, $now, $job->id));
		if (!$ok) { return array('ok' => true, 'job' => null, 'waiting' => $waiting); }
		$f = cg_dir() . '/' . $ev . '/' . $job->file;
		if (!is_file($f) || !preg_match('/\.jpg$/', $job->file)) { $wpdb->update($pt, array('status' => 'err', 'msg' => 'nincs nyomtatható kép', 'updated_at' => $now), array('id' => $job->id)); return array('ok' => true, 'job' => null, 'waiting' => $waiting - 1); }
		return array('ok' => true, 'waiting' => $waiting - 1, 'job' => array('id' => (int) $job->id, 'photo' => $job->photo, 'copies' => (int) $job->copies, 'src' => $job->src, 'img' => 'data:image/jpeg;base64,' . base64_encode(file_get_contents($f))));
	}));
	register_rest_route('crazy-galeria/v1', '/print/(?P<id>\d+)', array('methods' => 'POST', 'permission_callback' => $dev, 'callback' => function ($r) {
		global $wpdb; $s = $r->get_param('status') === 'done' ? 'done' : ($r->get_param('status') === 'new' ? 'new' : 'err');
		$wpdb->update(cg_pt(), array('status' => $s, 'msg' => mb_substr(sanitize_text_field((string) $r->get_param('msg')), 0, 190), 'updated_at' => current_time('mysql', true)), array('id' => (int) $r['id']));
		return array('ok' => true);
	}));
	/* a sor állapota (a fotózó gép és az állomás kijelzőjéhez) */
	register_rest_route('crazy-galeria/v1', '/print/status', array('methods' => 'GET', 'permission_callback' => $dev, 'callback' => function ($r) {
		global $wpdb; $ev = (string) $r->get_param('ev'); $pt = cg_pt();
		if (!cg_tok_ok($ev)) { return new WP_REST_Response(array('ok' => false, 'message' => 'Hibás azonosító.'), 400); }
		$rows = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) n, SUM(copies) c FROM $pt WHERE ev = %s GROUP BY status", $ev), ARRAY_A);
		$o = array('new' => 0, 'taken' => 0, 'done' => 0, 'err' => 0, 'copies' => 0);
		foreach ($rows as $x) { $o[$x['status']] = (int) $x['n']; if ($x['status'] === 'done') { $o['copies'] = (int) $x['c']; } }
		$o['stations'] = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT station FROM $pt WHERE ev = %s AND station <> '' AND updated_at > %s", $ev, gmdate('Y-m-d H:i:s', time() - 600)));
		$o['last_err'] = (string) $wpdb->get_var($wpdb->prepare("SELECT msg FROM $pt WHERE ev = %s AND status = 'err' ORDER BY updated_at DESC LIMIT 1", $ev));
		return array('ok' => true) + $o;
	}));
});
