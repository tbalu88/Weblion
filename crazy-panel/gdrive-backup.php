<?php
/**
 * v2.1: Google Drive mentés – a 6 óránkénti adatbázis-mentéseket (mindkét weboldal, cp-mentes) jelszavas,
 * AES-256 titkosítású ZIP-be csomagolja, és feltölti a megadott Google Drive-mappába (közös meghajtó), a Google Naptár
 * szolgáltatásfiókjával. Védőkorlátok:
 *  – jelszó nélkül nem tölt fel (titkosítatlan mentés soha nem megy ki a szerverről)
 *  – ha a mappa nyilvános („bárki, aki rendelkezik a linkkel”), nem tölt fel, és riaszt
 * Megőrzés a Drive-on: 7 napig minden mentés, 60 napig napi egy, egy évig havi egy.
 */
if (!defined('ABSPATH')) { exit; }

define('CP_GDB_SCOPE', 'https://www.googleapis.com/auth/drive');
function cp_gdb_opts() {
	$o = get_option('cp_gdb', array()); if (!is_array($o)) { $o = array(); }
	/* v2.6.10: a Beállítások kapcsolója számként (1/0) ment – egységesen szöveg */
	$r = array_merge(array('on' => '0', 'folder' => '1wN0hY77ih9rOV9Y8rnQaX0J7vxQ1Cs8S'), $o);
	$r['on'] = empty($r['on']) ? '0' : '1';
	return $r;
}
function cp_gdb_state() { $s = get_option('cp_gdb_state', array()); return is_array($s) ? $s : array(); }
function cp_gdb_pass() { return function_exists('cp_st_secret') ? (string) cp_st_secret('gdb_pass') : ''; }

/* hozzáférés: a Naptár szolgáltatásfiókjának kulcsa, Drive-jogosultsággal */
function cp_gdb_token() {
	$t = get_transient('cp_gdb_tok'); if ($t) { return $t; }
	$k = function_exists('cp_gcal_key') ? cp_gcal_key() : null;
	if (!$k) { return new WP_Error('cp_gdb', 'Nincs Google-szolgáltatásfiók (Beállítások → Google Naptár → kulcs).'); }
	$now = time();
	$uri = 'https://oauth2.googleapis.com/token';
	$jwt = cp_gcal_b64u(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT'))) . '.' . cp_gcal_b64u(wp_json_encode(array('iss' => $k['email'], 'scope' => CP_GDB_SCOPE, 'aud' => $uri, 'iat' => $now - 30, 'exp' => $now + 3300)));
	$sig = '';
	if (!@openssl_sign($jwt, $sig, $k['pk'], 'sha256WithRSAEncryption')) { return new WP_Error('cp_gdb', 'A szolgáltatásfiók kulcsa nem használható.'); }
	$r = wp_remote_post($uri, array('timeout' => 15, 'body' => array('grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . cp_gcal_b64u($sig))));
	if (is_wp_error($r)) { return new WP_Error('cp_gdb', 'A Google nem érhető el: ' . $r->get_error_message()); }
	$j = json_decode(wp_remote_retrieve_body($r), true);
	if (empty($j['access_token'])) { return new WP_Error('cp_gdb', 'A Google elutasította a belépést (' . sanitize_text_field((string) ($j['error_description'] ?? $j['error'] ?? '')) . ').'); }
	set_transient('cp_gdb_tok', $j['access_token'], max(60, (int) ($j['expires_in'] ?? 3600) - 300));
	return $j['access_token'];
}
function cp_gdb_api($method, $url, $body = null, $headers = array(), $timeout = 30) {
	$tok = cp_gdb_token(); if (is_wp_error($tok)) { return $tok; }
	$args = array('method' => $method, 'timeout' => $timeout, 'headers' => array_merge(array('Authorization' => 'Bearer ' . $tok), $headers));
	if ($body !== null) { $args['body'] = $body; }
	$r = wp_remote_request($url, $args);
	if (is_wp_error($r)) { return $r; }
	$code = (int) wp_remote_retrieve_response_code($r);
	if ($code >= 300) {
		$j = json_decode(wp_remote_retrieve_body($r), true);
		$m = is_array($j) ? (string) ($j['error']['message'] ?? '') : '';
		if ($code === 404 || $code === 403) { $m = 'A szolgáltatásfiók nem fér hozzá a mappához – add hozzá a közös meghajtóhoz „Tartalomkezelő” szerepkörrel. (' . $m . ')'; }
		return new WP_Error('cp_gdb', 'Google Drive hiba (' . $code . '): ' . mb_substr($m, 0, 220));
	}
	return $r;
}
/* a mappa nem lehet nyilvános */
function cp_gdb_check_folder($folder) {
	$r = cp_gdb_api('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($folder) . '/permissions?supportsAllDrives=true&fields=permissions(type,role)');
	if (is_wp_error($r)) { return $r; }
	$j = json_decode(wp_remote_retrieve_body($r), true);
	foreach ((array) ($j['permissions'] ?? array()) as $p) {
		if (($p['type'] ?? '') === 'anyone') { return new WP_Error('cp_gdb_public', 'A Drive-mappa nyilvános („bárki, aki rendelkezik a linkkel”) – amíg így van, nem töltök fel. A mappa (vagy a közös meghajtó) megosztásánál állítsd „Korlátozott”-ra.'); }
	}
	return true;
}

/* a még fel nem töltött helyi mentések (mindkét weboldal), régebbi elöl */
function cp_gdb_pending() {
	$root = dirname(rtrim(ABSPATH, '/')) . '/cp-mentes';
	$done = array_flip((array) (cp_gdb_state()['done'] ?? array()));
	$out = array();
	foreach ((array) glob($root . '/*/*.sql.gz') as $f) {
		if (filemtime($f) < time() - 3 * DAY_IN_SECONDS) { continue; }
		$k = basename(dirname($f)) . '/' . basename($f);
		if (!isset($done[$k])) { $out[$k] = $f; }
	}
	uasort($out, function ($a, $b) { return filemtime($a) - filemtime($b); });
	return $out;
}

function cp_gdb_upload_one($key, $file) {
	$o = cp_gdb_opts(); $pass = cp_gdb_pass();
	if (strlen($pass) < 12) { return new WP_Error('cp_gdb', 'Nincs megadva (legalább 12 karakteres) mentési jelszó – titkosítatlanul nem töltök fel.'); }
	$chk = cp_gdb_check_folder($o['folder']); if (is_wp_error($chk)) { return $chk; }
	if (!class_exists('ZipArchive')) { return new WP_Error('cp_gdb', 'A szerveren nincs ZIP-támogatás.'); }
	/* titkosított ZIP (7-Zip / WinRAR / macOS Archive Utility nyitja a jelszóval) */
	$site = basename(dirname($file));
	$zipf = dirname($file) . '/.up-' . wp_generate_password(8, false) . '.zip';
	$z = new ZipArchive();
	if ($z->open($zipf, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { return new WP_Error('cp_gdb', 'A ZIP nem hozható létre.'); }
	$entry = basename($file);
	$z->addFile($file, $entry);
	if (!$z->setEncryptionName($entry, ZipArchive::EM_AES_256, $pass)) { $z->close(); @unlink($zipf); return new WP_Error('cp_gdb', 'A titkosítás nem sikerült.'); }
	$z->setCompressionName($entry, ZipArchive::CM_STORE); /* a .gz már tömörített */
	$z->close();
	@chmod($zipf, 0600);
	$name = $site . '_' . preg_replace('/\.sql\.gz$/', '', $entry) . '.zip';
	$size = filesize($zipf);
	/* folytatható feltöltés: 1) munkamenet, 2) a fájl egyben */
	$meta = wp_json_encode(array('name' => $name, 'parents' => array($o['folder']), 'mimeType' => 'application/zip', 'description' => 'Crazy panel adatbázis-mentés (' . $site . '), AES-256 titkosított ZIP'));
	$r = cp_gdb_api('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', $meta, array('Content-Type' => 'application/json; charset=UTF-8', 'X-Upload-Content-Type' => 'application/zip', 'X-Upload-Content-Length' => (string) $size));
	if (is_wp_error($r)) { @unlink($zipf); return $r; }
	$loc = wp_remote_retrieve_header($r, 'location');
	if (!$loc || strpos($loc, 'https://www.googleapis.com/') !== 0) { @unlink($zipf); return new WP_Error('cp_gdb', 'A Drive nem adott feltöltési címet.'); }
	$r2 = cp_gdb_api('PUT', $loc, (string) file_get_contents($zipf), array('Content-Type' => 'application/zip', 'Content-Length' => (string) $size), 180);
	@unlink($zipf);
	if (is_wp_error($r2)) { return $r2; }
	$j = json_decode(wp_remote_retrieve_body($r2), true);
	if (empty($j['id'])) { return new WP_Error('cp_gdb', 'A feltöltés után nem jött vissza fájlazonosító.'); }
	return array('id' => $j['id'], 'name' => $name, 'mb' => round($size / 1048576, 1));
}

/* megőrzés a Drive-on: 7 napig minden, 60 napig napi egy, 365 napig havi egy (weboldalanként) */
function cp_gdb_prune() {
	$o = cp_gdb_opts();
	$q = rawurlencode("'" . $o['folder'] . "' in parents and trashed = false");
	$r = cp_gdb_api('GET', 'https://www.googleapis.com/drive/v3/files?q=' . $q . '&supportsAllDrives=true&includeItemsFromAllDrives=true&pageSize=1000&orderBy=name&fields=files(id,name,createdTime)');
	if (is_wp_error($r)) { return $r; }
	$files = (array) (json_decode(wp_remote_retrieve_body($r), true)['files'] ?? array());
	$seenDay = array(); $seenMon = array(); $del = 0;
	foreach ($files as $f) {
		if (!preg_match('/^(.+?)_.*-(\d{4})-(\d{2})-(\d{2})(?:-\d{2})?\.zip$/', (string) $f['name'], $m)) { continue; } /* csak a saját mentéseink */
		$age = (time() - strtotime((string) $f['createdTime'])) / DAY_IN_SECONDS;
		$dk = $m[1] . $m[2] . $m[3] . $m[4]; $mk = $m[1] . $m[2] . $m[3];
		$firstDay = !isset($seenDay[$dk]); $seenDay[$dk] = 1;
		$firstMon = !isset($seenMon[$mk]); $seenMon[$mk] = 1;
		if ($age <= 7 || ($firstDay && $age <= 60) || ($firstMon && $age <= 365)) { continue; }
		$x = cp_gdb_api('DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($f['id']) . '?supportsAllDrives=true');
		if (!is_wp_error($x)) { $del++; }
	}
	return $del;
}

/* ütemezés: 15 percenként megnézi, van-e új helyi mentés; egyszerre egyet tölt fel */
add_action('init', function () { if (!wp_next_scheduled('cp_gdb_tick')) { wp_schedule_event(time() + 300, 'cp_5min', 'cp_gdb_tick'); } });
add_action('cp_gdb_tick', 'cp_gdb_tick');
function cp_gdb_tick($force = false) {
	$o = cp_gdb_opts();
	if ($o['on'] !== '1' && !$force) { return 'A Google Drive mentés ki van kapcsolva.'; }
	if (!$force && get_transient('cp_gdb_at')) { return ''; }
	set_transient('cp_gdb_at', 1, 14 * MINUTE_IN_SECONDS);
	if (!add_option('cp_gdb_lock', time(), '', 'no')) { if ((int) get_option('cp_gdb_lock') > time() - 900) { return 'Épp fut egy feltöltés.'; } update_option('cp_gdb_lock', time(), false); }
	if (function_exists('set_time_limit')) { @set_time_limit(280); }
	$st = cp_gdb_state(); $msg = 'Nincs új mentés a feltöltéshez.';
	try {
		$pend = cp_gdb_pending();
		if ($pend) {
			$key = array_key_first($pend);
			$r = cp_gdb_upload_one($key, $pend[$key]);
			if (is_wp_error($r)) { $st['err'] = $r->get_error_message(); $st['err_at'] = time(); $msg = 'Hiba: ' . $st['err']; }
			else {
				$st['done'][] = $key; $st['done'] = array_slice((array) $st['done'], -300);
				$st['last'] = time(); $st['last_name'] = $r['name']; $st['last_mb'] = $r['mb']; $st['err'] = '';
				$msg = 'Feltöltve: ' . $r['name'] . ' (' . $r['mb'] . ' MB)' . (count($pend) > 1 ? ' – még ' . (count($pend) - 1) . ' vár.' : '.');
			}
		}
		if (empty($st['pruned']) || $st['pruned'] < time() - DAY_IN_SECONDS) { $p = cp_gdb_prune(); if (!is_wp_error($p)) { $st['pruned'] = time(); $st['pruned_n'] = $p; } }
	} catch (Throwable $e) { $st['err'] = mb_substr($e->getMessage(), 0, 200); $st['err_at'] = time(); $msg = 'Hiba: ' . $st['err']; }
	update_option('cp_gdb_state', $st, false);
	delete_option('cp_gdb_lock');
	return $msg;
}
