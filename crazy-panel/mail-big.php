<?php
/**
 * v2.9.335: Nagy mellékletek és Fájltár (levelezés).
 *  – 15 MB feletti fájl: a böngésző közvetlenül a céges Google Drive-ra tölti fel („EC - Levélmellékletek”, közös meghajtó),
 *    a panel „bárki, aki rendelkezik a linkkel – megtekintő” megosztást állít be, és a levélbe letöltődoboz kerül a linkkel.
 *    A fájlok örökre megmaradnak (a felhasználó döntése, 2026-11-19).
 *  – Fájltár („EC - Fájltár”): gyakran küldött fájlok (pl. kiajánló); a levélíróból böngészhető, feltölthető,
 *    és egy kattintással a levélbe tehető: 15 MB-ig mellékletként, fölötte linkként.
 * A feltöltés a Drive „resumable” munkamenetével megy: a panel indítja (a szolgáltatásfiókkal), a böngésző tölti fel
 * a fájlt közvetlenül a Google-höz – így a tárhely feltöltési korlátja nem számít. A kulcs nem kerül a böngészőbe.
 */
if (!defined('ABSPATH')) { exit; }

const CP_MBIG_API = 'https://www.googleapis.com/drive/v3/files';
const CP_MBIG_MAX = 5368709120; /* 5 GB */

function cp_mbig_on() { return function_exists('cp_gdb_api') && function_exists('cp_mdr_folder') && defined('CP_MDR_PARENT'); }
/* a közös meghajtó gyökere (a számlamappa meghajtója), megjegyezve */
function cp_mbig_root() {
	$r = (string) get_option('cp_mbig_root', '');
	if ($r !== '') { return $r; }
	$j = cp_mdr_json(cp_gdb_api('GET', CP_MBIG_API . '/' . rawurlencode(CP_MDR_PARENT) . '?supportsAllDrives=true&fields=driveId'));
	if (is_wp_error($j)) { return $j; }
	$r = (string) ($j['driveId'] ?? '') ?: CP_MDR_PARENT;
	update_option('cp_mbig_root', $r, false);
	return $r;
}
/* v2.9.337: a Fájltár a közös meghajtó „ECCloud” mappájában van (a felhasználó kérése); az ECCloud-ot nem hozzuk létre, csak megkeressük */
function cp_mbig_eccloud() {
	$c = (string) get_option('cp_mbig_eccloud', '');
	if ($c !== '') { return $c; }
	$root = cp_mbig_root(); if (is_wp_error($root)) { return $root; }
	$q = cp_mdr_q($root) . " in parents and name = 'ECCloud' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
	$j = cp_mdr_json(cp_gdb_api('GET', CP_MBIG_API . '?' . http_build_query(array('q' => $q, 'fields' => 'files(id)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'corpora' => 'allDrives'))));
	if (is_wp_error($j)) { return $j; }
	$id = (string) ($j['files'][0]['id'] ?? '');
	if ($id === '') { return new WP_Error('mbig', 'Nem találom az „ECCloud” mappát a közös meghajtón.'); }
	update_option('cp_mbig_eccloud', $id, false);
	return $id;
}
function cp_mbig_folder($kind) {
	$root = cp_mbig_root(); if (is_wp_error($root)) { return $root; }
	if ($kind === 'lib') { $ec = cp_mbig_eccloud(); if (is_wp_error($ec)) { return $ec; } return cp_mdr_folder($ec, 'EC - Fájltár'); }
	return cp_mdr_folder($root, 'EC - Levélmellékletek');
}
function cp_mbig_auth() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if (!cp_mbig_on()) { cp_el_err('A Google Drive-kapcsolat nincs beállítva.'); }
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cp_el_err('Hibás kérés.'); }
	return function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
}
function cp_mbig_file($id) {
	$id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $id);
	if ($id === '') { return new WP_Error('mbig', 'Hibás fájl.'); }
	$j = cp_mdr_json(cp_gdb_api('GET', CP_MBIG_API . '/' . rawurlencode($id) . '?supportsAllDrives=true&fields=id,name,size,mimeType,parents,trashed,webViewLink,modifiedTime'));
	if (is_wp_error($j)) { return $j; }
	if (!empty($j['trashed']) || empty($j['id'])) { return new WP_Error('mbig', 'A fájl már nincs a Drive-on.'); }
	return $j;
}
/* csak a saját két mappánkban lévő fájl kezelhető innen */
function cp_mbig_ours($f) {
	$ok = array();
	foreach (array('mail', 'lib') as $k) { $id = cp_mbig_folder($k); if (!is_wp_error($id)) { $ok[] = $id; } }
	return (bool) array_intersect((array) ($f['parents'] ?? array()), $ok);
}
/* „bárki, aki rendelkezik a linkkel – megtekintő” */
function cp_mbig_share($id) {
	$r = cp_gdb_api('POST', CP_MBIG_API . '/' . rawurlencode($id) . '/permissions?supportsAllDrives=true', wp_json_encode(array('role' => 'reader', 'type' => 'anyone')), array('Content-Type' => 'application/json'));
	return is_wp_error($r) ? $r : true;
}
function cp_mbig_out($f) {
	return array('id' => (string) $f['id'], 'n' => (string) $f['name'], 'size' => (int) ($f['size'] ?? 0), 'mime' => (string) ($f['mimeType'] ?? ''),
		'link' => (string) ($f['webViewLink'] ?? ''), 'at' => isset($f['modifiedTime']) ? wp_date('Y.m.d.', strtotime((string) $f['modifiedTime'])) : '');
}

/* 1. feltöltés indítása: a Drive feltöltési címe (a böngésző ide küldi a fájlt) */
add_action('wp_ajax_nopriv_cp_mbig_start', 'cp_mbig_ajax_start');
add_action('wp_ajax_cp_mbig_start', 'cp_mbig_ajax_start');
function cp_mbig_ajax_start() {
	$me = cp_mbig_auth();
	$name = function_exists('cp_att_safe_name') ? cp_att_safe_name(wp_unslash($_POST['n'] ?? '')) : sanitize_file_name(wp_unslash($_POST['n'] ?? ''));
	if ($name === '') { cp_el_err('Hibás fájlnév.'); }
	if (function_exists('cp_att_blocked') && cp_att_blocked($name)) { cp_el_err('Ilyen típusú fájl nem küldhető.'); }
	$size = (int) ($_POST['size'] ?? 0);
	if ($size <= 0 || $size > CP_MBIG_MAX) { cp_el_err('A fájl legfeljebb 5 GB lehet.'); }
	$kind = ($_POST['to'] ?? '') === 'lib' ? 'lib' : 'mail';
	$fid = cp_mbig_folder($kind); if (is_wp_error($fid)) { cp_el_err($fid->get_error_message()); }
	/* a böngésző címe (CORS): csak a saját oldalunkról */
	$origin = (string) wp_unslash($_POST['origin'] ?? '');
	$host = (string) parse_url(home_url(), PHP_URL_HOST);
	if (!preg_match('#^https://([a-z0-9.-]+)$#i', $origin, $m) || (strcasecmp($m[1], $host) !== 0 && !preg_match('/(^|\.)' . preg_quote($host, '/') . '$/i', $m[1]))) { $origin = 'https://' . $host; }
	$mime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', (string) ($_POST['mime'] ?? '')) ? (string) $_POST['mime'] : 'application/octet-stream';
	$meta = array('name' => $name, 'parents' => array($fid), 'description' => 'Crazy panel – ' . ($kind === 'lib' ? 'Fájltár' : 'levélmelléklet') . ' (' . (function_exists('cp_panel_who') ? cp_panel_who() : '') . ', ' . wp_date('Y.m.d. H:i') . ')');
	$r = cp_gdb_api('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&fields=id,name,size,webViewLink', wp_json_encode($meta),
		array('Content-Type' => 'application/json; charset=UTF-8', 'X-Upload-Content-Type' => $mime, 'X-Upload-Content-Length' => (string) $size, 'Origin' => $origin));
	if (is_wp_error($r)) { cp_el_err($r->get_error_message()); }
	$url = (string) wp_remote_retrieve_header($r, 'location');
	if (strpos($url, 'https://www.googleapis.com/') !== 0) { cp_el_err('A Drive nem adott feltöltési címet.'); }
	wp_send_json_success(array('url' => $url, 'n' => $name, 'mime' => $mime));
}

/* 2. a feltöltés után: megosztás „bárki a linkkel”, napló */
add_action('wp_ajax_nopriv_cp_mbig_done', 'cp_mbig_ajax_done');
add_action('wp_ajax_cp_mbig_done', 'cp_mbig_ajax_done');
function cp_mbig_ajax_done() {
	$me = cp_mbig_auth();
	$f = cp_mbig_file($_POST['id'] ?? ''); if (is_wp_error($f)) { cp_el_err($f->get_error_message()); }
	if (!cp_mbig_ours($f)) { cp_el_err('Ez a fájl nem a panel mappáiban van.', 403); }
	$s = cp_mbig_share($f['id']); if (is_wp_error($s)) { cp_el_err('A fájl feltöltődött, de a megosztás nem sikerült: ' . $s->get_error_message()); }
	$log = get_option('cp_mbig_log', array()); if (!is_array($log)) { $log = array(); }
	$log[] = array('id' => (string) $f['id'], 'n' => (string) $f['name'], 'size' => (int) ($f['size'] ?? 0), 'uid' => $me, 'at' => time(), 'lib' => ($_POST['to'] ?? '') === 'lib' ? 1 : 0);
	update_option('cp_mbig_log', array_slice($log, -500), false);
	if (function_exists('cp_audit_add')) { cp_audit_add('mail', 'bigfile', (($_POST['to'] ?? '') === 'lib' ? 'Fájltár: fájl feltöltve' : 'Nagy melléklet a Drive-ra'), '', (string) $f['name']); }
	wp_send_json_success(cp_mbig_out($f));
}

/* Fájltár: lista */
add_action('wp_ajax_nopriv_cp_mbig_lib', 'cp_mbig_ajax_lib');
add_action('wp_ajax_cp_mbig_lib', 'cp_mbig_ajax_lib');
function cp_mbig_ajax_lib() {
	cp_mbig_auth();
	$fid = cp_mbig_folder('lib'); if (is_wp_error($fid)) { cp_el_err($fid->get_error_message()); }
	$q = cp_mdr_q($fid) . " in parents and trashed = false and mimeType != 'application/vnd.google-apps.folder'";
	$j = cp_mdr_json(cp_gdb_api('GET', CP_MBIG_API . '?' . http_build_query(array('q' => $q, 'fields' => 'files(id,name,size,mimeType,webViewLink,modifiedTime)', 'orderBy' => 'name', 'pageSize' => 300, 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'corpora' => 'allDrives'))));
	if (is_wp_error($j)) { cp_el_err($j->get_error_message()); }
	wp_send_json_success(array('files' => array_map('cp_mbig_out', (array) ($j['files'] ?? array())), 'folder' => 'https://drive.google.com/drive/folders/' . $fid));
}

/* Fájltár: a levélbe – 15 MB-ig mellékletként (a szokásos melléklet-jeggyel), fölötte (vagy kérésre) linkként */
add_action('wp_ajax_nopriv_cp_mbig_use', 'cp_mbig_ajax_use');
add_action('wp_ajax_cp_mbig_use', 'cp_mbig_ajax_use');
function cp_mbig_ajax_use() {
	$me = cp_mbig_auth();
	$f = cp_mbig_file($_POST['id'] ?? ''); if (is_wp_error($f)) { cp_el_err($f->get_error_message()); }
	if (!cp_mbig_ours($f)) { cp_el_err('Ez a fájl nem a panel mappáiban van.', 403); }
	$size = (int) ($f['size'] ?? 0);
	$as_att = ($_POST['as'] ?? '') === 'att' && $size > 0 && $size <= 15 * 1048576 && strpos((string) $f['mimeType'], 'application/vnd.google-apps.') !== 0;
	if (!$as_att) {
		$s = cp_mbig_share($f['id']); if (is_wp_error($s)) { cp_el_err($s->get_error_message()); }
		wp_send_json_success(array('link' => 1) + cp_mbig_out($f));
	}
	if (!function_exists('cp_send_can') || !cp_send_can($me)) { cp_el_err('A panelből küldés neked nincs bekapcsolva.', 403); }
	$r = cp_gdb_api('GET', CP_MBIG_API . '/' . rawurlencode($f['id']) . '?alt=media&supportsAllDrives=true', null, array(), 120);
	if (is_wp_error($r)) { cp_el_err($r->get_error_message()); }
	$data = wp_remote_retrieve_body($r);
	$name = function_exists('cp_att_safe_name') ? cp_att_safe_name((string) $f['name']) : sanitize_file_name((string) $f['name']);
	$tok = wp_generate_password(20, false, false); $dir = cp_att_dir() . '/' . $tok; wp_mkdir_p($dir);
	$path = $dir . '/' . md5($name) . '.bin';
	if (file_put_contents($path, $data) === false) { cp_el_err('A melléklet mentése nem sikerült (tárhely).'); }
	set_transient('cp_att_' . $tok, array('uid' => $me, 'n' => $name, 'size' => strlen($data), 'mime' => (string) $f['mimeType'] ?: 'application/octet-stream', 'path' => $path), DAY_IN_SECONDS);
	wp_send_json_success(array('att' => 1, 'tok' => $tok, 'n' => $name, 'size' => strlen($data)));
}
