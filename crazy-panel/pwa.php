<?php
/* =====================================================================
 * Telepíthető app (PWA) + push-értesítés (v2.8)
 * – A panel a telefon / gép kezdőképernyőjére tehető (saját ikon, teljes képernyő, saját ablak).
 * – Push-értesítés az eszközre: minden panel-értesítés (új ajánlatkérés, megemlítés, kiosztás, követett levél…)
 *   a kolléga feliratkozott eszközeire is megy, akkor is, ha a panel nincs nyitva. iPhone-on csak a kezdőképernyőre
 *   telepített appban (iOS 16.4+).
 * – Szabvány Web Push (RFC 8291 titkosítás, RFC 8292 VAPID), külső szolgáltatás és könyvtár nélkül: a kulcspár a
 *   szerveren készül, a privát kulcs titkosítva tárolva (cp_st_enc), soha nem kerül a böngészőbe.
 * Végpontok a panel címén: ?cp_pwa=manifest | sw | icon192 | icon512 | icon180.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_pwa_base() { $o = cp_panel_opts(); return '/' . trim((string) $o['slug'], '/') . '/'; }
function cp_pwa_url($q) { return home_url(cp_pwa_base() . '?cp_pwa=' . $q); }

/* ------------------------------------------------------------ manifest, service worker, ikonok */
add_action('init', function () {
	if (!isset($_GET['cp_pwa'])) { return; }
	$what = sanitize_key(wp_unslash($_GET['cp_pwa']));
	$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
	if (rtrim($path, '/') . '/' !== cp_pwa_base()) { return; }
	nocache_headers();
	if ($what === 'manifest') {
		header('Content-Type: application/manifest+json; charset=utf-8');
		echo wp_json_encode(array(
			'name' => 'Crazy panel', 'short_name' => 'Crazy panel', 'id' => cp_pwa_base(), 'start_url' => cp_pwa_base(), 'scope' => cp_pwa_base(),
			'display' => 'standalone', 'background_color' => '#141C33', 'theme_color' => '#141C33', 'lang' => 'hu',
			'icons' => array(
				array('src' => cp_pwa_url('icon192'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'),
				array('src' => cp_pwa_url('icon512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'),
			),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}
	/* v2.9.58: a belépés nélküli kattintás-napló (?cp_pwa=click → cp_pwa_clicks) megszűnt – semmi nem olvasta, és bárki adatbázis-írást válthatott ki vele;
	 * a régi service workerek pingje (amíg frissülnek) olcsó 204-et kap, a panel oldala helyett */
	if ($what === 'click') { status_header(204); exit; }
	if ($what === 'sw') {
		header('Content-Type: application/javascript; charset=utf-8');
		header('Service-Worker-Allowed: ' . cp_pwa_base());
		header('Cache-Control: no-cache');
		readfile(__DIR__ . '/assets/sw.js');
		exit;
	}
	if (in_array($what, array('icon192', 'icon512', 'icon180'), true)) {
		$ic = is_file(__DIR__ . '/pwa-icons.php') ? include __DIR__ . '/pwa-icons.php' : array();
		$k = substr($what, 4);
		if (empty($ic[$k])) { status_header(404); exit; }
		header('Content-Type: image/png');
		header('Cache-Control: public, max-age=604800');
		echo base64_decode($ic[$k]);
		exit;
	}
}, 1);

/* a panel fejlécébe (cp_panel_head hívja) */
function cp_pwa_head() {
	echo '<link rel="manifest" href="' . esc_url(cp_pwa_url('manifest')) . '">';
	echo '<meta name="theme-color" content="#141C33"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">';
	echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="Crazy panel">';
	echo '<link rel="apple-touch-icon" href="' . esc_url(cp_pwa_url('icon180')) . '"><link rel="icon" type="image/png" sizes="192x192" href="' . esc_url(cp_pwa_url('icon192')) . '">';
}
function cp_pwa_cfg() { return array('on' => true, 'sw' => cp_pwa_url('sw'), 'scope' => cp_pwa_base(), 'push' => cp_push_ok()); }

/* ------------------------------------------------------------ VAPID kulcspár (egyszer készül) */
function cp_push_ok() { return function_exists('openssl_pkey_new') && function_exists('openssl_pkey_derive') && function_exists('hash_hkdf'); }
function cp_b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function cp_b64u_dec($s) { $s = strtr((string) $s, '-_', '+/'); return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function cp_push_keys() {
	$k = get_option('cp_push_vapid', array());
	if (is_array($k) && !empty($k['pub']) && !empty($k['priv'])) {
		$pem = function_exists('cp_st_dec') ? cp_st_dec($k['priv']) : $k['priv'];
		if ($pem) { return array('pub' => $k['pub'], 'pem' => $pem); }
	}
	if (!cp_push_ok()) { return null; }
	$key = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
	if (!$key) { return null; }
	openssl_pkey_export($key, $pem);
	$d = openssl_pkey_get_details($key);
	$pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
	update_option('cp_push_vapid', array('pub' => cp_b64u($pub), 'priv' => function_exists('cp_st_enc') ? cp_st_enc($pem) : $pem, 'at' => time()), false);
	return array('pub' => cp_b64u($pub), 'pem' => $pem);
}
/* nyers (65 bájtos) P-256 nyilvános kulcs → PEM */
function cp_push_pem_pub($raw) {
	$der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
	return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
/* DER ECDSA-aláírás → nyers r||s (64 bájt) a JWT-hez */
function cp_push_der2raw($der) {
	$p = 2; if (ord($der[1]) & 0x80) { $p += ord($der[1]) & 0x7f; }
	$rl = ord($der[$p + 1]); $r = substr($der, $p + 2, $rl); $p += 2 + $rl;
	$sl = ord($der[$p + 1]); $s = substr($der, $p + 2, $sl);
	$r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT); $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
	return $r . $s;
}
function cp_push_vapid_hdr($endpoint, $keys) {
	$u = parse_url($endpoint);
	$aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
	$h = cp_b64u(wp_json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
	$c = cp_b64u(wp_json_encode(array('aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:info@eventcrazy.hu')));
	$sig = '';
	if (!openssl_sign($h . '.' . $c, $sig, $keys['pem'], OPENSSL_ALGO_SHA256)) { return ''; }
	return 'vapid t=' . $h . '.' . $c . '.' . cp_b64u(cp_push_der2raw($sig)) . ', k=' . $keys['pub'];
}
/* RFC 8291 (aes128gcm) titkosítás */
function cp_push_encrypt($payload, $p256dh, $auth) {
	$ua = cp_b64u_dec($p256dh); $as = cp_b64u_dec($auth);
	if (strlen($ua) !== 65 || strlen($as) < 16) { return null; }
	$eph = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
	$d = openssl_pkey_get_details($eph);
	$asPub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
	$peer = openssl_pkey_get_public(cp_push_pem_pub($ua));
	if (!$peer) { return null; }
	$ecdh = openssl_pkey_derive($peer, $eph, 32);
	if ($ecdh === false) { return null; }
	$prkKey = hash_hmac('sha256', $ecdh, $as, true);
	$ikm = hash_hmac('sha256', "WebPush: info\0" . $ua . $asPub . "\x01", $prkKey, true);
	$salt = random_bytes(16);
	$prk = hash_hmac('sha256', $ikm, $salt, true);
	$cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
	$nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
	$tag = '';
	$ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
	if ($ct === false) { return null; }
	return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

/* v2.9.58: csak ismert push-szolgáltatók címére küldünk (Chrome/Android, Safari/iOS, Firefox, Edge/Windows) – belső / tetszőleges cím nem fogadható el */
function cp_push_host_ok($ep) {
	$u = wp_parse_url((string) $ep);
	if (!is_array($u) || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['port']) || isset($u['user'])) { return false; }
	$h = strtolower($u['host']);
	if (in_array($h, array('fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'), true)) { return true; }
	return (bool) preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.(push\.apple\.com|notify\.windows\.com)$/', $h);
}

/* ------------------------------------------------------------ feliratkozások (kollégánként, eszközönként) */
function cp_push_subs() { $s = get_option('cp_push_subs', array()); return is_array($s) ? $s : array(); }
function cp_push_auth() { cp_el_auth(); $me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0; if ($me <= 0) { cp_el_err('Az értesítésekhez személyes fiókkal kell belépni.'); } return $me; }
add_action('wp_ajax_nopriv_cp_push_key', 'cp_push_ajax_key');
add_action('wp_ajax_cp_push_key', 'cp_push_ajax_key');
function cp_push_ajax_key() {
	$me = cp_push_auth();
	$k = cp_push_keys();
	if (!$k) { cp_el_err('A szerveren nem érhető el a push-titkosítás.'); }
	$n = count(cp_push_subs()[$me] ?? array());
	wp_send_json_success(array('key' => $k['pub'], 'n' => $n));
}
add_action('wp_ajax_nopriv_cp_push_sub', 'cp_push_ajax_sub');
add_action('wp_ajax_cp_push_sub', 'cp_push_ajax_sub');
function cp_push_ajax_sub() {
	$me = cp_push_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$sub = json_decode((string) wp_unslash($_POST['sub'] ?? ''), true);
	$ep = is_array($sub) ? (string) ($sub['endpoint'] ?? '') : '';
	if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?/#i', $ep) || !cp_push_host_ok($ep) || strlen($ep) > 1000 || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) { cp_el_err('Hibás feliratkozás.'); }
	$all = cp_push_subs();
	$h = md5($ep);
	/* ugyanaz az eszköz másik kollégánál (közös telefon) – ott törlődik */
	foreach ($all as $uid => $list) { if ((int) $uid !== $me && isset($list[$h])) { unset($all[$uid][$h]); } }
	if (!empty($_POST['off'])) { unset($all[$me][$h]); update_option('cp_push_subs', $all, false); wp_send_json_success(array('n' => count($all[$me] ?? array()))); }
	$all[$me][$h] = array('ep' => $ep, 'p' => (string) $sub['keys']['p256dh'], 'a' => (string) $sub['keys']['auth'], 'ua' => mb_substr(sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 160), 't' => time());
	update_option('cp_push_subs', $all, false);
	wp_send_json_success(array('n' => count($all[$me])));
}
add_action('wp_ajax_nopriv_cp_push_test', 'cp_push_ajax_test');
add_action('wp_ajax_cp_push_test', 'cp_push_ajax_test');
function cp_push_ajax_test() {
	$me = cp_push_auth();
	$r = cp_push_send($me, 'Crazy panel', 'Próbaértesítés – így jelennek meg az értesítések ezen az eszközön.', 'ertesitesek');
	if (!$r['n']) { cp_el_err('Ehhez a fiókhoz még nincs feliratkozott eszköz.'); }
	wp_send_json_success($r);
}

/* ------------------------------------------------------------ küldés */
function cp_push_send($uid, $title, $body, $hash) {
	$out = array('n' => 0, 'ok' => 0, 'gone' => 0, 'err' => '');
	$all = cp_push_subs();
	$list = $all[(int) $uid] ?? array();
	if (!$list) { return $out; }
	$keys = cp_push_keys();
	if (!$keys) { $out['err'] = 'nincs kulcs'; return $out; }
	$url = (function_exists('cp_pu_panel_url') ? cp_pu_panel_url() : home_url(cp_pwa_base())) . ($hash !== '' ? '#' . $hash : '');
	$payload = wp_json_encode(array('title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 240), 'url' => $url, 'tag' => 'cp-' . md5($hash . $body)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$changed = false;
	foreach ($list as $h => $s) {
		$out['n']++;
		$enc = cp_push_encrypt($payload, $s['p'], $s['a']);
		$auth = cp_push_vapid_hdr($s['ep'], $keys);
		if (!cp_push_host_ok($s['ep'])) { $out['err'] = 'ismeretlen push-szolgáltató'; continue; }
		if ($enc === null || $auth === '') { $out['err'] = 'titkosítás'; continue; }
		$r = wp_safe_remote_post($s['ep'], array('timeout' => 4, 'headers' => array('Content-Type' => 'application/octet-stream', 'Content-Encoding' => 'aes128gcm', 'TTL' => '86400', 'Urgency' => 'high', 'Authorization' => $auth), 'body' => $enc));
		$code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
		if ($code >= 200 && $code < 300) { $out['ok']++; }
		elseif ($code === 404 || $code === 410) { unset($all[(int) $uid][$h]); $changed = true; $out['gone']++; }
		else { $out['err'] = is_wp_error($r) ? 'kapcsolódási hiba' : ('HTTP ' . $code); } /* v2.9.58: a szolgáltató válaszszövege nem megy vissza a böngészőnek */
	}
	if ($changed) { update_option('cp_push_subs', $all, false); }
	return $out;
}

/* a panel-értesítések (cp_notify) a válasz elküldése után mennek ki, hogy a mentés ne lassuljon */
function cp_push_queue($uid, $title, $body, $hash) {
	static $q = null;
	if ($q === null) {
		$q = array();
		register_shutdown_function(function () use (&$q) {
			if (!$q) { return; }
			if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); } elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
			foreach ($q as $x) { cp_push_send($x[0], $x[1], $x[2], $x[3]); }
		});
	}
	if (empty(cp_push_subs()[(int) $uid])) { return; }
	$q[] = array((int) $uid, $title, $body, $hash);
}
