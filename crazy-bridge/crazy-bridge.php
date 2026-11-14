<?php
/**
 * Plugin Name: Crazy Claude-híd
 * Description: Biztonságos REST híd: Claude a felhőből (a te géped nélkül) olvashatja és szerkesztheti a bővítmények / sablonok fájljait. Írás előtt szintaxist ellenőriz, mentést készít, és ha utána az oldal hibára fut, automatikusan visszaállítja a fájlt. Kulcs nélkül semmit nem enged.
 * Version: 1.1.3
 * Author: Crazyphoto / EventCrazy
 * Requires PHP: 7.4
 * Text Domain: crazy-bridge
 */

if (!defined('ABSPATH')) { exit; }

define('CB_VER', '1.1.4');
define('CB_OPT', 'crazy_bridge');
define('CB_LOG', 'crazy_bridge_log');
define('CB_NS', 'crazy-bridge/v1');
define('CB_MAX_BYTES', 4 * 1024 * 1024);
define('CB_KEEP', 20);

/* ------------------------------------------------------------ beállítások, napló */

function cb_opts() {
	$o = get_option(CB_OPT, array());
	return wp_parse_args(is_array($o) ? $o : array(), array(
		'hash'    => '',
		'user'    => 0,
		'created' => 0,
		'last'    => 0,
		'last_ip' => '',
		'on'      => 1,
		'paths'   => '',
	));
}

function cb_hash($tok) {
	return hash_hmac('sha256', (string) $tok, wp_salt('auth'));
}

function cb_ip() {
	return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
}

function cb_log($action, $path = '', $info = '') {
	$l = get_option(CB_LOG, array());
	if (!is_array($l)) { $l = array(); }
	array_unshift($l, array('t' => time(), 'a' => (string) $action, 'p' => (string) $path, 'i' => mb_substr((string) $info, 0, 300), 'ip' => cb_ip()));
	update_option(CB_LOG, array_slice($l, 0, 150), false);
}

/* ------------------------------------------------------------ hitelesítés (Authorization: Bearer <kulcs>) */

function cb_is_rest() {
	if (!empty($_GET['rest_route'])) { return true; }
	$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
	return strpos($uri, '/' . rest_get_url_prefix() . '/') !== false;
}

/* v1.1.3: a kulcs csak a híd saját végpontjaira (crazy-bridge/v1/…) léptet be – a WordPress többi REST-útvonalára nem */
function cb_is_bridge_route() {
	$r = isset($_GET['rest_route']) ? (string) $_GET['rest_route'] : '';
	if ($r === '') {
		$uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
		$pre = '/' . rest_get_url_prefix() . '/';
		$i = strpos($uri, $pre);
		if ($i === false) { return false; }
		$r = substr($uri, $i + strlen($pre) - 1);
	}
	$r = '/' . ltrim(rawurldecode($r), '/');
	return strpos($r, '/crazy-bridge/v1/') === 0 || $r === '/crazy-bridge/v1';
}

function cb_req_token() {
	$h = '';
	foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $k) {
		if (!empty($_SERVER[$k])) { $h = (string) $_SERVER[$k]; break; }
	}
	if ($h === '' && function_exists('getallheaders')) {
		foreach ((array) getallheaders() as $k => $v) {
			if (strtolower((string) $k) === 'authorization') { $h = (string) $v; break; }
		}
	}
	if ($h !== '' && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $h, $m)) { return $m[1]; }
	if (!empty($_SERVER['HTTP_X_CRAZY_TOKEN'])) { return trim((string) $_SERVER['HTTP_X_CRAZY_TOKEN']); }
	return '';
}

add_filter('determine_current_user', 'cb_auth', 30);
function cb_auth($uid) {
	if (!empty($uid) || !cb_is_rest() || !cb_is_bridge_route()) { return $uid; }
	$tok = cb_req_token();
	if ($tok === '') { return $uid; }
	$o = cb_opts();
	if (empty($o['on']) || $o['hash'] === '' || empty($o['user'])) { $GLOBALS['cb_auth_err'] = 'off'; return $uid; }
	if (!hash_equals($o['hash'], cb_hash($tok))) { $GLOBALS['cb_auth_err'] = 'bad'; return $uid; }
	$GLOBALS['cb_authed'] = true;
	if (time() - (int) $o['last'] > 60 || $o['last_ip'] !== cb_ip()) {
		$o['last'] = time();
		$o['last_ip'] = cb_ip();
		update_option(CB_OPT, $o, false);
	}
	return (int) $o['user'];
}

add_filter('rest_authentication_errors', 'cb_auth_errors', 99);
function cb_auth_errors($err) {
	if (!empty($err)) { return $err; }
	if (!empty($GLOBALS['cb_auth_err'])) {
		$msg = $GLOBALS['cb_auth_err'] === 'off' ? 'A Claude-híd ki van kapcsolva, vagy nincs kulcs generálva.' : 'Érvénytelen Claude-híd kulcs.';
		return new WP_Error('cb_auth', $msg, array('status' => 401));
	}
	return $err;
}

function cb_can() {
	if (!is_user_logged_in()) {
		return new WP_Error('cb_auth', 'Érvényes Claude-híd kulcs szükséges (Authorization: Bearer …).', array('status' => 401));
	}
	if (!current_user_can('manage_options') || !current_user_can('edit_plugins')) {
		return new WP_Error('cb_cap', 'A kulcshoz rendelt felhasználó nem szerkeszthet bővítményfájlokat.', array('status' => 403));
	}
	return true;
}

/* ------------------------------------------------------------ útvonalak: csak plugins/, themes/, mu-plugins/ alatt */

function cb_roots() {
	$r = array(
		'plugins'    => WP_PLUGIN_DIR,
		'themes'     => get_theme_root(),
		'mu-plugins' => WPMU_PLUGIN_DIR,
	);
	foreach ($r as $k => $d) {
		$real = is_dir($d) ? realpath($d) : false;
		$r[$k] = wp_normalize_path($real ? $real : $d);
	}
	return $r;
}

function cb_text_ext($path) {
	return (bool) preg_match('/\.(php|js|mjs|css|scss|json|txt|md|html?|svg|xml|ya?ml|pot?)$/i', $path);
}

/* relatív útvonal (pl. plugins/crazy-panel/crazy-panel.php) → abszolút; WP_Error, ha kívül esik */
function cb_resolve($rel, $must_exist = true) {
	$rel = ltrim(wp_normalize_path(trim((string) $rel)), '/');
	if ($rel === '' || strpos($rel, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
		return new WP_Error('cb_path', 'Érvénytelen útvonal.', array('status' => 400));
	}
	$parts = explode('/', $rel, 2);
	$roots = cb_roots();
	if (!isset($roots[$parts[0]])) {
		return new WP_Error('cb_path', 'Csak a plugins/, themes/ vagy mu-plugins/ alatti fájlok érhetők el.', array('status' => 403));
	}
	$root = $roots[$parts[0]];
	$abs = $root . (isset($parts[1]) && $parts[1] !== '' ? '/' . $parts[1] : '');
	if (file_exists($abs)) {
		$real = wp_normalize_path((string) realpath($abs));
		if ($real !== $root && strpos($real, $root . '/') !== 0) {
			return new WP_Error('cb_path', 'Az útvonal a megengedett mappán kívülre mutat.', array('status' => 403));
		}
		return $real;
	}
	if ($must_exist) {
		return new WP_Error('cb_404', 'Nincs ilyen fájl: ' . $rel, array('status' => 404));
	}
	$pd = realpath(dirname($abs));
	$pd = $pd ? wp_normalize_path($pd) : '';
	if ($pd === '' || ($pd !== $root && strpos($pd, $root . '/') !== 0)) {
		return new WP_Error('cb_path', 'A célmappa nem létezik vagy nem megengedett: ' . dirname($rel), array('status' => 400));
	}
	return $pd . '/' . basename($abs);
}

function cb_rel($abs) {
	$abs = wp_normalize_path($abs);
	foreach (cb_roots() as $k => $root) {
		if ($abs === $root) { return $k; }
		if (strpos($abs, $root . '/') === 0) { return $k . '/' . substr($abs, strlen($root) + 1); }
	}
	return basename($abs);
}

/* ------------------------------------------------------------ mentések (wp-content/crazy-bridge-backups, PHP-ként: weben nem olvashatók) */

function cb_bdir() {
	$d = wp_normalize_path(WP_CONTENT_DIR) . '/crazy-bridge-backups';
	if (!is_dir($d)) {
		wp_mkdir_p($d);
		@file_put_contents($d . '/index.php', "<?php // Silence is golden.\n");
		@file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
	}
	return $d;
}

function cb_bkey($rel) {
	return substr(md5((string) $rel), 0, 12);
}

function cb_backup($abs, $why = '') {
	if (!is_file($abs)) { return ''; }
	$rel = cb_rel($abs);
	$c = file_get_contents($abs);
	if ($c === false) { return new WP_Error('cb_backup', 'A mentés nem sikerült (nem olvasható a fájl).', array('status' => 500)); }
	$id = cb_bkey($rel) . '__' . gmdate('Ymd-His') . '__' . wp_rand(1000, 9999) . '.php';
	$meta = str_replace(array('*/', "\n", "\r", '?>'), array('* /', ' ', ' ', '? >'), $rel . ' | ' . time() . ' | ' . hash('sha256', $c) . ' | ' . $why);
	$ok = file_put_contents(cb_bdir() . '/' . $id, '<?php exit; /* crazy-bridge: ' . $meta . " */ ?>\n" . $c);
	if ($ok === false) { return new WP_Error('cb_backup', 'A mentés nem sikerült (nem írható a mentési mappa).', array('status' => 500)); }
	cb_backup_prune($rel);
	return $id;
}

function cb_backups($rel) {
	$out = array();
	foreach ((array) glob(cb_bdir() . '/' . cb_bkey($rel) . '__*.php') as $f) {
		$fh = fopen($f, 'r');
		if (!$fh) { continue; }
		$h = (string) fgets($fh);
		fclose($fh);
		if (!preg_match('#/\* crazy-bridge: (.*?) \| (\d+) \| ([0-9a-f]{64}) \| (.*?) \*/#', (string) $h, $m)) { continue; }
		if ($m[1] !== $rel) { continue; }
		$out[] = array('id' => basename($f), 'time' => (int) $m[2], 'sha256' => $m[3], 'why' => $m[4], 'size' => max(0, filesize($f) - strlen($h)));
	}
	usort($out, function ($a, $b) { return $b['time'] - $a['time'] ?: strcmp($b['id'], $a['id']); });
	return $out;
}

function cb_backup_prune($rel) {
	foreach (array_slice(cb_backups($rel), CB_KEEP) as $b) { @unlink(cb_bdir() . '/' . $b['id']); }
}

function cb_backup_read($id) {
	$id = basename((string) $id);
	if (!preg_match('/^[0-9a-f]{12}__\d{8}-\d{6}__\d{4}\.php$/', $id)) { return new WP_Error('cb_bk', 'Érvénytelen mentés azonosító.', array('status' => 400)); }
	$f = cb_bdir() . '/' . $id;
	if (!is_file($f)) { return new WP_Error('cb_bk', 'Nincs ilyen mentés.', array('status' => 404)); }
	$c = file_get_contents($f);
	$p = strpos($c, "\n");
	return $p === false ? '' : substr($c, $p + 1);
}

/* ------------------------------------------------------------ írás + ellenőrzés + automatikus visszaállítás */

function cb_php_syntax($code) {
	if (!function_exists('token_get_all') || !defined('TOKEN_PARSE')) { return true; }
	try {
		token_get_all($code, TOKEN_PARSE);
	} catch (\ParseError $e) {
		return new WP_Error('cb_syntax', 'PHP szintaxis hiba a(z) ' . $e->getLine() . '. sorban: ' . $e->getMessage(), array('status' => 422, 'line' => $e->getLine()));
	} catch (\Throwable $e) {
		return new WP_Error('cb_syntax', 'PHP ellenőrzési hiba: ' . $e->getMessage(), array('status' => 422));
	}
	return true;
}

function cb_put($abs, $content) {
	$perm = is_file($abs) ? (fileperms($abs) & 0777) : 0644;
	$tmp = dirname($abs) . '/.cb-tmp-' . wp_generate_password(8, false) . '.tmp';
	if (file_put_contents($tmp, $content) === false) {
		return new WP_Error('cb_write', 'Nem írható a mappa: ' . cb_rel(dirname($abs)), array('status' => 500));
	}
	@chmod($tmp, $perm);
	if (!@rename($tmp, $abs)) {
		@unlink($tmp);
		if (file_put_contents($abs, $content) === false) {
			return new WP_Error('cb_write', 'Nem írható a fájl: ' . cb_rel($abs), array('status' => 500));
		}
	}
	clearstatcache(true, $abs);
	if (function_exists('opcache_invalidate')) { @opcache_invalidate($abs, true); }
	return true;
}

function cb_health_urls() {
	$u = array(
		'főoldal'    => add_query_arg('cb_health', wp_rand(), home_url('/')),
		'admin-ajax' => admin_url('admin-ajax.php'),
		'rest'       => rest_url(CB_NS . '/alive'),
	);
	$p = get_option('cp_panel_settings');
	if (is_array($p) && !empty($p['slug'])) {
		$u['crazy panel'] = add_query_arg('cb_health', wp_rand(), home_url('/' . trim($p['slug'], '/') . '/'));
	}
	$o = cb_opts();
	foreach (preg_split('/\r?\n/', (string) $o['paths']) as $line) {
		$line = trim($line);
		if ($line !== '' && $line[0] === '/') { $u[$line] = add_query_arg('cb_health', wp_rand(), home_url($line)); }
	}
	return $u;
}

/* a weboldal saját magát hívja meg: ha bármelyik oldal 500-at vagy PHP hibát ad, az nem jó */
function cb_health() {
	$args = array('timeout' => 25, 'redirection' => 3, 'sslverify' => false, 'headers' => array('Cache-Control' => 'no-cache', 'User-Agent' => 'CrazyBridge-Health/' . CB_VER));
	$all = true;
	$checks = array();
	foreach (cb_health_urls() as $name => $url) {
		$r = wp_remote_get($url, $args);
		if (is_wp_error($r)) {
			/* ha a szerver nem éri el saját magát, azt nem tekintjük a módosítás hibájának */
			$checks[] = array('name' => $name, 'ok' => null, 'code' => 0, 'note' => 'nem elérhető: ' . $r->get_error_message());
			continue;
		}
		$code = (int) wp_remote_retrieve_response_code($r);
		$body = (string) wp_remote_retrieve_body($r);
		$bad = $code >= 500 || preg_match('#critical error on this website|Kritikus hiba történt|<b>(Fatal|Parse) error</b>|PHP (Fatal|Parse) error#i', $body);
		if ($name === 'rest' && !$bad) { $j = json_decode($body, true); $bad = !(is_array($j) && !empty($j['alive'])); }
		$note = '';
		if ($bad && preg_match('#<b>(?:Fatal|Parse) error</b>:\s*(.{0,300})#is', $body, $m)) { $note = wp_strip_all_tags($m[1]); }
		$checks[] = array('name' => $name, 'ok' => !$bad, 'code' => $code, 'note' => $note);
		if ($bad) { $all = false; }
	}
	return array('ok' => $all, 'checks' => $checks);
}

/* ------------------------------------------------------------ REST végpontok */

add_action('rest_api_init', 'cb_routes');
function cb_routes() {
	$p = 'cb_can';
	register_rest_route(CB_NS, '/alive', array('methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () {
		return array('alive' => true);
	}));
	register_rest_route(CB_NS, '/ping', array('methods' => 'GET', 'permission_callback' => $p, 'callback' => 'cb_r_ping'));
	register_rest_route(CB_NS, '/files', array('methods' => 'GET', 'permission_callback' => $p, 'callback' => 'cb_r_files'));
	register_rest_route(CB_NS, '/file', array(
		array('methods' => 'GET', 'permission_callback' => $p, 'callback' => 'cb_r_file_get'),
		array('methods' => 'POST', 'permission_callback' => $p, 'callback' => 'cb_r_file_put'),
	));
	register_rest_route(CB_NS, '/backups', array('methods' => 'GET', 'permission_callback' => $p, 'callback' => 'cb_r_backups'));
	register_rest_route(CB_NS, '/backup', array('methods' => 'GET', 'permission_callback' => $p, 'callback' => 'cb_r_backup_get'));
	register_rest_route(CB_NS, '/restore', array('methods' => 'POST', 'permission_callback' => $p, 'callback' => 'cb_r_restore'));
	register_rest_route(CB_NS, '/health', array('methods' => 'GET', 'permission_callback' => $p, 'callback' => function () { return cb_health(); }));
	register_rest_route(CB_NS, '/log', array('methods' => 'GET', 'permission_callback' => $p, 'callback' => 'cb_r_log'));
	register_rest_route(CB_NS, '/option', array('methods' => 'GET', 'permission_callback' => $p, 'callback' => 'cb_r_option'));
}

function cb_r_ping() {
	global $wp_version;
	if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
	$pl = array();
	foreach (get_plugins() as $f => $d) { $pl[] = array('file' => $f, 'name' => $d['Name'], 'version' => $d['Version'], 'active' => is_plugin_active($f)); }
	return array(
		'ok'      => true,
		'bridge'  => CB_VER,
		'user'    => wp_get_current_user()->user_login,
		'via'     => !empty($GLOBALS['cb_authed']) ? 'token' : 'cookie',
		'wp'      => $wp_version,
		'php'     => PHP_VERSION,
		'theme'   => get_stylesheet(),
		'plugins' => $pl,
		'help'    => array(
			'GET  /files?dir=plugins/crazy-panel'                           => 'fájllista',
			'GET  /file?path=plugins/x/y.php'                               => 'fájl tartalma + sha256',
			'POST /file {path, content | content_b64, sha256, check=true}' => 'írás: szintaxis ellenőrzés, mentés, egészség-ellenőrzés, hiba esetén automatikus visszaállítás; sha256 = a módosítás előtti tartalom hash-e (új fájlnál elhagyható, force=true felülírja)',
			'GET  /backups?path=…'                                          => 'mentések listája',
			'GET  /backup?id=…'                                             => 'egy mentés tartalma',
			'POST /restore {path, id}'                                      => 'visszaállítás mentésből',
			'GET  /health'                                                  => 'az oldal működik-e (főoldal, admin-ajax, REST, crazy panel)',
			'GET  /log?lines=200'                                           => 'debug.log / PHP hibanapló vége',
			'GET  /option?name=cp_el_pq'                                    => 'cp_ / crazy_ kezdetű beállítás olvasása (titkos mezők maszkolva)',
		),
	);
}

function cb_r_files(WP_REST_Request $req) {
	$abs = cb_resolve($req->get_param('dir') ?: 'plugins');
	if (is_wp_error($abs)) { return $abs; }
	if (!is_dir($abs)) { return new WP_Error('cb_dir', 'Ez nem mappa.', array('status' => 400)); }
	$depth = max(0, min(10, (int) ($req->get_param('depth') ?: 6)));
	$out = array();
	$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
		function ($f) { return !in_array($f->getFilename(), array('.git', 'node_modules', '.svn'), true); }
	), RecursiveIteratorIterator::SELF_FIRST);
	$it->setMaxDepth($depth);
	foreach ($it as $f) {
		if (count($out) >= 3000) { break; }
		$out[] = array('path' => cb_rel($f->getPathname()), 'dir' => $f->isDir(), 'size' => $f->isDir() ? 0 : $f->getSize(), 'mtime' => $f->getMTime());
	}
	return array('dir' => cb_rel($abs), 'count' => count($out), 'files' => $out);
}

function cb_r_file_get(WP_REST_Request $req) {
	$abs = cb_resolve($req->get_param('path'));
	if (is_wp_error($abs)) { return $abs; }
	if (!is_file($abs)) { return new WP_Error('cb_file', 'Ez nem fájl.', array('status' => 400)); }
	if (!cb_text_ext($abs)) { return new WP_Error('cb_ext', 'Csak szöveges fájlok (php, js, css, json, txt, md, html, svg, xml, yml, po) olvashatók.', array('status' => 415)); }
	if (filesize($abs) > CB_MAX_BYTES) { return new WP_Error('cb_size', 'Túl nagy fájl.', array('status' => 413)); }
	$c = (string) file_get_contents($abs);
	$r = array('path' => cb_rel($abs), 'size' => strlen($c), 'mtime' => filemtime($abs), 'sha256' => hash('sha256', $c));
	if (function_exists('mb_check_encoding') && !mb_check_encoding($c, 'UTF-8')) { $r['content_b64'] = base64_encode($c); } else { $r['content'] = $c; }
	return $r;
}

function cb_r_file_put(WP_REST_Request $req) {
	$rel_in = (string) $req->get_param('path');
	$abs = cb_resolve($rel_in, false);
	if (is_wp_error($abs)) { return $abs; }
	if (!cb_text_ext($abs)) { return new WP_Error('cb_ext', 'Csak szöveges fájl írható.', array('status' => 415)); }
	if (wp_normalize_path($abs) === wp_normalize_path(__FILE__) && !$req->get_param('self')) {
		return new WP_Error('cb_self', 'A híd saját fájlját csak self=true paraméterrel lehet módosítani (ha elromlik, elveszik a hozzáférés).', array('status' => 400));
	}
	$b64 = $req->get_param('content_b64');
	if ($b64 !== null && $b64 !== '') {
		$content = base64_decode((string) $b64, true);
		if ($content === false) { return new WP_Error('cb_b64', 'Hibás content_b64.', array('status' => 400)); }
	} else {
		$content = $req->get_param('content');
		if (!is_string($content)) { return new WP_Error('cb_content', 'Hiányzik a content (vagy content_b64).', array('status' => 400)); }
	}
	if (strlen($content) > CB_MAX_BYTES) { return new WP_Error('cb_size', 'Túl nagy tartalom.', array('status' => 413)); }
	$rel = cb_rel($abs);
	$exists = is_file($abs);
	$force = (bool) $req->get_param('force');
	if ($exists) {
		$cur = (string) file_get_contents($abs);
		$want = strtolower(trim((string) $req->get_param('sha256')));
		if (!$force && $want === '') { return new WP_Error('cb_sha', 'Meglévő fájlnál kötelező a sha256 (a módosítás előtti tartalomé), vagy force=true.', array('status' => 400)); }
		if (!$force && !hash_equals(hash('sha256', $cur), $want)) {
			return new WP_Error('cb_conflict', 'A fájl közben megváltozott – olvasd be újra.', array('status' => 409, 'sha256' => hash('sha256', $cur)));
		}
		if ($cur === $content) { return array('ok' => true, 'path' => $rel, 'unchanged' => true, 'sha256' => hash('sha256', $cur)); }
	}
	$is_php = (bool) preg_match('/\.php$/i', $abs);
	if ($is_php) {
		$s = cb_php_syntax($content);
		if (is_wp_error($s)) { cb_log('elutasítva', $rel, $s->get_error_message()); return $s; }
	}
	$bk = $exists ? cb_backup($abs, 'írás előtt') : '';
	if (is_wp_error($bk)) { return $bk; }
	$w = cb_put($abs, $content);
	if (is_wp_error($w)) { return $w; }
	$res = array('ok' => true, 'path' => $rel, 'created' => !$exists, 'sha256' => hash('sha256', $content), 'size' => strlen($content), 'backup' => $bk);
	$check = $req->get_param('check');
	if ($is_php && ($check === null || filter_var($check, FILTER_VALIDATE_BOOLEAN))) {
		$h = cb_health();
		$res['health'] = $h;
		if (!$h['ok']) {
			if ($exists) { cb_put($abs, cb_backup_read($bk)); } else { @unlink($abs); if (function_exists('opcache_invalidate')) { @opcache_invalidate($abs, true); } }
			$res['ok'] = false;
			$res['rolled_back'] = true;
			$res['health_after_rollback'] = cb_health();
			$dl = defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '' && WP_DEBUG_LOG !== '1' ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
			foreach (array($dl, ini_get('error_log')) as $lf) {
				$t = cb_tail($lf, 400);
				if ($t !== null && preg_match_all('/^.*PHP (?:Fatal|Parse) error.*$/m', $t, $mm)) { $res['errors'] = array_slice(array_values(array_unique($mm[0])), -3); break; }
			}
			cb_log('visszaállítva', $rel, 'hibás lett az oldal, visszaállítva');
			return new WP_REST_Response($res, 500);
		}
	}
	cb_log($exists ? 'módosítva' : 'létrehozva', $rel, 'sha ' . substr($res['sha256'], 0, 12) . ($bk ? ' · mentés: ' . $bk : ''));
	return $res;
}

function cb_r_backups(WP_REST_Request $req) {
	$abs = cb_resolve($req->get_param('path'), false);
	if (is_wp_error($abs)) { return $abs; }
	return array('path' => cb_rel($abs), 'backups' => cb_backups(cb_rel($abs)));
}

function cb_r_backup_get(WP_REST_Request $req) {
	$c = cb_backup_read($req->get_param('id'));
	if (is_wp_error($c)) { return $c; }
	return array('id' => basename((string) $req->get_param('id')), 'sha256' => hash('sha256', $c), 'content' => $c);
}

function cb_r_restore(WP_REST_Request $req) {
	$abs = cb_resolve($req->get_param('path'), false);
	if (is_wp_error($abs)) { return $abs; }
	$rel = cb_rel($abs);
	$id = basename((string) $req->get_param('id'));
	$found = false;
	foreach (cb_backups($rel) as $b) { if ($b['id'] === $id) { $found = true; break; } }
	if (!$found) { return new WP_Error('cb_bk', 'Ez a mentés nem ehhez a fájlhoz tartozik.', array('status' => 400)); }
	$c = cb_backup_read($id);
	if (is_wp_error($c)) { return $c; }
	$bk = is_file($abs) ? cb_backup($abs, 'visszaállítás előtt') : '';
	if (is_wp_error($bk)) { return $bk; }
	$w = cb_put($abs, $c);
	if (is_wp_error($w)) { return $w; }
	cb_log('visszaállítva', $rel, 'mentésből: ' . $id);
	return array('ok' => true, 'path' => $rel, 'restored' => $id, 'backup_of_previous' => $bk, 'sha256' => hash('sha256', $c), 'health' => preg_match('/\.php$/i', $abs) ? cb_health() : null);
}

function cb_tail($file, $lines) {
	if (!$file || !is_file($file) || !is_readable($file)) { return null; }
	$size = (int) @filesize($file);
	if ($size <= 0) { return ''; }   /* v1.1.4: üres (pl. épp forgatott) naplónál a fread(…, 0) végzetes hiba volt */
	$fh = @fopen($file, 'r');
	if (!$fh) { return null; }
	$read = min($size, 512 * 1024);
	fseek($fh, -$read, SEEK_END);
	$buf = (string) fread($fh, $read);
	fclose($fh);
	$arr = preg_split('/\r?\n/', rtrim($buf));
	return implode("\n", array_slice($arr, -$lines));
}

/* v1.1: a naplósorokból kitakarja a személyes adatot és a titkokat (e-mail, IP, telefon, kulcs, token, hosszú azonosító) */
function cb_log_mask($s) {
	$s = (string) $s;
	$s = preg_replace('/Bearer\s+\S+/i', 'Bearer •••', $s);
	$s = preg_replace('/([?&;](?:[^=&\s"\']*(?:token|key|secret|pass|pwd|sig|auth|nonce|code|hash|session)[^=&\s"\']*)=)[^&\s"\']+/i', '$1•••', $s);
	$s = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[e-mail]', $s);
	$s = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $s);
	$s = preg_replace('/\b(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}\b/i', '[ip]', $s);
	$s = preg_replace('/(?<![\w:])(?=[0-9a-f:]*\d)(?:[0-9a-f]{1,4}:){1,6}:(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4})*)?(?![\w:])/i', '[ip]', $s);
	$s = preg_replace('/(?<![\w.])\+?(?:36|06)[\s\/-]?\d{1,2}[\s\/-]?\d{3}[\s\/-]?\d{3,4}\b/', '[tel]', $s);
	$s = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '•••', $s);
	return function_exists('cb_mask_val') && cb_mask_val($s) ? '•••(kitakarva)' : $s;
}

/* a lehetséges PHP-hibanaplók: a php.ini szerinti (relatív útnál a WordPress gyökeréhez képest), a gyökér, a wp-admin és a wp-content error_log fájlja, valamint a debug.log */
function cb_log_files() {
	$f = array();
	$dl = defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : false;
	$f['debug.log'] = is_string($dl) && $dl !== '' && $dl !== '1' ? $dl : WP_CONTENT_DIR . '/debug.log';
	$ini = (string) ini_get('error_log');
	if ($ini !== '' && strpos($ini, 'syslog') === false) { $f['php error_log'] = preg_match('#^(/|[A-Za-z]:)#', $ini) ? $ini : ABSPATH . $ini; }
	foreach (array('gyökér' => ABSPATH . 'error_log', 'wp-admin' => ABSPATH . 'wp-admin/error_log', 'wp-content' => WP_CONTENT_DIR . '/error_log') as $k => $p) {
		if (!in_array(wp_normalize_path($p), array_map('wp_normalize_path', $f), true)) { $f[$k] = $p; }
	}
	return $f;
}

/* GET /log – paraméterek: lines (10–2000), mode=tail|summary, only=fatal|warn|all, grep=szövegrész (pl. crazy-panel) */
function cb_r_log(WP_REST_Request $req) {
	$n = max(10, min(2000, (int) ($req->get_param('lines') ?: 200)));
	$mode = $req->get_param('mode') === 'summary' ? 'summary' : 'tail';
	$only = (string) $req->get_param('only');
	$grep = mb_substr(trim((string) $req->get_param('grep')), 0, 80);
	$lv = array('fatal' => '/PHP (Fatal|Parse) error|Uncaught|Allowed memory|Maximum execution time/i', 'warn' => '/PHP (Fatal|Parse) error|Uncaught|Warning|Allowed memory|Maximum execution time/i');
	$dl = defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : false;
	$out = array('wp_debug' => defined('WP_DEBUG') && WP_DEBUG, 'wp_debug_log' => $dl, 'mode' => $mode, 'logs' => array());
	foreach (cb_log_files() as $k => $f) {
		if (!$f || !is_file($f) || !is_readable($f)) { $out['logs'][$k] = null; continue; }
		$raw = cb_tail($f, $mode === 'summary' ? 2000 : max($n, 400));
		$rows = array_values(array_filter(preg_split('/\r?\n/', (string) $raw), function ($l) use ($only, $lv, $grep) {
			if (trim($l) === '') { return false; }
			if (isset($lv[$only]) && !preg_match($lv[$only], $l)) { return false; }
			return $grep === '' || stripos($l, $grep) !== false;
		}));
		$info = array('file' => wp_normalize_path((string) $f), 'size_kb' => (int) round(filesize($f) / 1024), 'modified' => gmdate('Y-m-d H:i', (int) filemtime($f)) . ' UTC');
		if ($mode === 'summary') {
			$g = array();
			foreach ($rows as $l) {
				/* a hibák folytatósorai (Stack trace, #0 …, thrown in) az előző bejegyzéshez tartoznak */
				if (!preg_match('/^\[[^\]]+\]/', $l)) { continue; }
				$at = preg_match('/^\[([^\]]+)\]/', $l, $m) ? $m[1] : '';
				$msg = preg_replace('/^\[[^\]]+\]\s*/', '', $l);
				$key = preg_replace(array('/\d+/', '/\s+/'), array('#', ' '), cb_log_mask($msg));
				$key = mb_substr($key, 0, 260);
				if (!isset($g[$key])) { $g[$key] = array('n' => 0, 'last' => '', 'msg' => mb_substr(cb_log_mask($msg), 0, 400)); }
				$g[$key]['n']++; $g[$key]['last'] = $at;
			}
			uasort($g, function ($a, $b) { return $b['n'] - $a['n']; });
			$info['lines_scanned'] = count($rows);
			$info['groups'] = array_values(array_slice($g, 0, 40));
		} else {
			$info['tail'] = implode("\n", array_map('cb_log_mask', array_slice($rows, -$n)));
		}
		$out['logs'][$k] = $info;
	}
	$bl = array_slice((array) get_option(CB_LOG, array()), 0, 30);
	$out['bridge_log'] = json_decode(cb_log_mask(wp_json_encode($bl)), true) ?: array();
	return $out;
}

/* titkosnak tűnő kulcsnév (a teljes opció nevét is ide adjuk, ha az opció egyetlen szöveg) */
function cb_mask_key($k) {
	/* ^h$ / ^tok: a panel-felhasználók jelszó-lenyomata és belépési tokenje (cp_panel_users) */
	return $k !== '' && (bool) preg_match('/secret|token|pass|hash|webhook|_url$|^url$|key|kulcs|private|credential|signature|^sig$|salt|cookie|session|bearer|auth|^h$|^tok(_|$)/i', $k);
}

/* a kulcsnévtől függetlenül titoknak tűnő érték (API-kulcs, token, privát kulcs, webhook) */
function cb_mask_val($s) {
	if (strlen($s) < 16) { return false; }
	if (preg_match('/^\s*(sk-[A-Za-z0-9_-]{16,}|sk-(proj|ant|live|test)-|rk_(live|test)_|pk_live_|EAA[A-Za-z0-9]{20,}|AIza[0-9A-Za-z_-]{30,}|ya29\.|1\/\/0[A-Za-z0-9_-]{20,}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_|xox[abprs]-|glpat-|eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.)/', $s)) { return true; }
	if (stripos($s, 'PRIVATE KEY') !== false || stripos($s, '"private_key"') !== false) { return true; }
	if (preg_match('#https?://[^\s"\']*(webhook|hooks?\.|/hook/|token=|key=|secret=|sig=)#i', $s)) { return true; }
	return false;
}

function cb_mask($v, $k = '') {
	if (is_object($v)) { $v = get_object_vars($v); }
	if (is_array($v)) {
		$o = array();
		foreach ($v as $kk => $vv) { $o[$kk] = cb_mask($vv, is_int($kk) ? $k : (string) $kk); }
		return $o;
	}
	if (is_string($v) && $v !== '' && (cb_mask_key($k) || cb_mask_val($v))) {
		return '•••(' . strlen($v) . ' karakter)';
	}
	return $v;
}

function cb_r_option(WP_REST_Request $req) {
	$name = sanitize_key((string) $req->get_param('name'));
	if (!preg_match('/^(cp_|crazy_)/', $name) || strpos($name, CB_OPT) === 0) {
		return new WP_Error('cb_opt', 'Csak cp_ vagy crazy_ kezdetű beállítás olvasható (a híd sajátja nem).', array('status' => 403));
	}
	$v = get_option($name, null);
	return array('name' => $name, 'exists' => $v !== null, 'value' => cb_mask($v, is_array($v) || is_object($v) ? '' : $name));
}

/* ------------------------------------------------------------ beállítási oldal: Eszközök → Claude-híd */

add_action('admin_menu', function () {
	add_management_page('Claude-híd', 'Claude-híd', 'manage_options', 'crazy-bridge', 'cb_admin_page');
});

function cb_admin_page() {
	if (!current_user_can('manage_options')) { return; }
	$o = cb_opts();
	$new = '';
	$msg = '';
	if (isset($_POST['cb_do']) && check_admin_referer('cb_admin')) {
		$do = sanitize_key(wp_unslash($_POST['cb_do']));
		if ($do === 'gen') {
			if (!current_user_can('edit_plugins')) {
				$msg = 'A fiókodnak nincs joga bővítményfájlokat szerkeszteni (edit_plugins), így kulcsot sem generálhat.';
			} else {
				$new = wp_generate_password(48, false, false);
				$o['hash'] = cb_hash($new);
				$o['user'] = get_current_user_id();
				$o['created'] = time();
				$o['last'] = 0;
				$o['last_ip'] = '';
				$o['on'] = 1;
				update_option(CB_OPT, $o, false);
				cb_log('új kulcs', '', 'generálta: ' . wp_get_current_user()->user_login);
			}
		} elseif ($do === 'revoke') {
			$o['hash'] = '';
			$o['user'] = 0;
			update_option(CB_OPT, $o, false);
			cb_log('kulcs visszavonva', '', wp_get_current_user()->user_login);
			$msg = 'A kulcs visszavonva – a híd mostantól semmilyen kérést nem enged.';
		} elseif ($do === 'save') {
			$o['on'] = empty($_POST['cb_on']) ? 0 : 1;
			$o['paths'] = sanitize_textarea_field(wp_unslash(isset($_POST['cb_paths']) ? $_POST['cb_paths'] : ''));
			update_option(CB_OPT, $o, false);
			$msg = 'Mentve.';
		}
	}
	$u = $o['user'] ? get_userdata($o['user']) : null;
	$fmt = function ($t) { return $t ? wp_date('Y.m.d. H:i', (int) $t) : '–'; };
	echo '<div class="wrap"><h1>Claude-híd</h1>';
	echo '<p>Ezen keresztül Claude a felhőből – a te géped és böngésződ nélkül – olvashatja és szerkesztheti a bővítmények és sablonok fájljait. Minden írás előtt mentés készül; PHP fájlnál szintaxis-ellenőrzés, írás után pedig az oldal ellenőrzése, és ha hibára futna, a fájl automatikusan visszaáll.</p>';
	if ($msg !== '') { echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>'; }
	if ($new !== '') {
		echo '<div class="notice notice-success" style="padding:12px 16px"><p><strong>Az új kulcs (csak most látszik, másold ki):</strong></p>';
		echo '<p><input type="text" readonly value="' . esc_attr($new) . '" style="width:100%;max-width:560px;font-family:monospace" onclick="this.select()"></p>';
		echo '<ol style="margin-left:1.4em"><li>Nyisd meg: <a href="https://claude.ai/code" target="_blank" rel="noopener">claude.ai/code</a> → az üzenetmező feletti felhő ikon (a környezet neve, pl. <em>Default</em>) → vidd fölé az egeret, és kattints a fogaskerékre.</li>';
		echo '<li>Az <strong>API credentials</strong> résznél: <strong>Add credential</strong>.</li>';
		echo '<li><strong>Name:</strong> ' . esc_html(trim(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)) ?: wp_parse_url(home_url(), PHP_URL_HOST)) . ' WordPress · <strong>Allowed websites:</strong> <code>' . esc_html(wp_parse_url(home_url(), PHP_URL_HOST)) . '</code> · <strong>Custom headers:</strong> Name = <code>Authorization</code>, Prefix = <code>Bearer</code>, Value = a fenti kulcs.</li>';
		echo '<li><strong>Connect</strong>. Kész – ezután szólj Claude-nak, és kipróbálja.</li></ol></div>';
	}
	echo '<table class="form-table"><tbody>';
	echo '<tr><th>Kulcs</th><td>' . ($o['hash'] !== '' ? 'van – generálva: ' . esc_html($fmt($o['created'])) . ($u ? ', felhasználó: <strong>' . esc_html($u->user_login) . '</strong>' : '') : '<strong>nincs</strong> (a híd semmit nem enged)') . '</td></tr>';
	echo '<tr><th>Utoljára használva</th><td>' . esc_html($fmt($o['last'])) . ($o['last_ip'] ? ' – ' . esc_html($o['last_ip']) : '') . '</td></tr>';
	echo '</tbody></table>';
	echo '<form method="post" style="display:inline-block;margin-right:8px">';
	wp_nonce_field('cb_admin');
	echo '<input type="hidden" name="cb_do" value="gen"><button class="button button-primary" onclick="return ' . ($o['hash'] !== '' ? 'confirm(\'Új kulcs: a régi azonnal érvényét veszti. Mehet?\')' : 'true') . '">' . ($o['hash'] !== '' ? 'Új kulcs generálása' : 'Kulcs generálása') . '</button></form>';
	if ($o['hash'] !== '') {
		echo '<form method="post" style="display:inline-block">';
		wp_nonce_field('cb_admin');
		echo '<input type="hidden" name="cb_do" value="revoke"><button class="button" onclick="return confirm(\'Visszavonod a kulcsot? Claude ezután nem éri el a fájlokat.\')">Kulcs visszavonása</button></form>';
	}
	echo '<h2 style="margin-top:2em">Beállítások</h2><form method="post">';
	wp_nonce_field('cb_admin');
	echo '<input type="hidden" name="cb_do" value="save"><table class="form-table"><tbody>';
	echo '<tr><th>Híd engedélyezve</th><td><label><input type="checkbox" name="cb_on" value="1"' . checked(!empty($o['on']), true, false) . '> ha kikapcsolod, a kulcs megmarad, de a híd semmit nem enged</label></td></tr>';
	echo '<tr><th>Ellenőrzött oldalak</th><td><textarea name="cb_paths" rows="3" class="large-text code" placeholder="/rolunk/">' . esc_textarea($o['paths']) . '</textarea><p class="description">PHP fájl írása után ezeket is lekéri (soronként egy, /-rel kezdve). Alapból: főoldal, admin-ajax, REST és a Crazy panel oldala.</p></td></tr>';
	echo '</tbody></table>';
	submit_button('Mentés');
	echo '</form>';
	$log = get_option(CB_LOG, array());
	echo '<h2>Napló</h2>';
	if (!$log) { echo '<p>Még nincs bejegyzés.</p>'; } else {
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Idő</th><th>Esemény</th><th>Fájl</th><th>Részletek</th><th>IP</th></tr></thead><tbody>';
		foreach (array_slice((array) $log, 0, 60) as $e) {
			echo '<tr><td>' . esc_html($fmt($e['t'])) . '</td><td>' . esc_html($e['a']) . '</td><td><code>' . esc_html($e['p']) . '</code></td><td>' . esc_html($e['i']) . '</td><td>' . esc_html(isset($e['ip']) ? $e['ip'] : '') . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '<p class="description" style="margin-top:1em">Mentések helye: <code>wp-content/crazy-bridge-backups/</code> (fájlonként a legutóbbi ' . (int) CB_KEEP . ', weben nem olvashatók).</p>';
	echo '</div>';
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($l) {
	array_unshift($l, '<a href="' . esc_url(admin_url('tools.php?page=crazy-bridge')) . '">Beállítások</a>');
	return $l;
});
