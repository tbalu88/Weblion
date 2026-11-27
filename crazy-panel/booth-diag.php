<?php
/* =====================================================================
 * v2.9.388: Crazy Booth hibanaplók a panelben
 * A gépek (az online felület) indításkor, minden hibánál (legfeljebb percenként) és a panel kérésére elküldik a naplójuk
 * végét és az állapotukat: POST /crazy/v1/booth/diag. Gépkulccsal a gép nevén, kulcs nélkül / hibás kulccsal
 * „azonosítatlan gépként” (gyakoriság- és méretkorláttal). 14 napig őrizzük. Nézet: Beállítások → Crazy Booth gépek.
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }
define('CP_BDG_DB_VER', '1');
function cp_bdg_t() { global $wpdb; return $wpdb->prefix . 'cp_booth_diag'; }
function cp_bdg_ok() { return get_option('cp_bdg_db_ver') === CP_BDG_DB_VER; }
add_action('init', function () {
	if (cp_bdg_ok()) { return; }
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	global $wpdb;
	dbDelta('CREATE TABLE ' . cp_bdg_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  at int(10) unsigned NOT NULL DEFAULT 0,
  dev varchar(40) NOT NULL DEFAULT '',
  inst varchar(40) NOT NULL DEFAULT '',
  name varchar(120) NOT NULL DEFAULT '',
  reasons text NULL,
  meta text NULL,
  log mediumtext NULL,
  PRIMARY KEY  (id),
  KEY at (at),
  KEY dev (dev, at)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_bdg_db_ver', CP_BDG_DB_VER, false);
}, 7);

/* a panel kérheti egy gép naplóját: a gép a következő állapotjelentésre (1 percen belül) küldi */
function cp_bdg_req_all() { $r = get_option('cp_bdg_req', array()); return is_array($r) ? $r : array(); }
function cp_bdg_req_take($dev) { $r = cp_bdg_req_all(); if (empty($r[$dev])) { return false; } unset($r[$dev]); update_option('cp_bdg_req', $r, false); return true; }

add_action('rest_api_init', function () {
	register_rest_route('crazy/v1', '/booth/diag', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
		if (!cp_bdg_ok()) { return new WP_REST_Response(array('ok' => false), 503); }
		$raw = (string) $r->get_body();
		if (strlen($raw) > 400000) { return new WP_REST_Response(array('ok' => false, 'message' => 'túl nagy'), 413); }
		$j = json_decode($raw, true); if (!is_array($j)) { return new WP_REST_Response(array('ok' => false), 400); }
		$d = cp_booth_auth();
		$inst = substr(preg_replace('/[^a-z0-9]/', '', strtolower((string) ($j['inst'] ?? ''))), 0, 40);
		/* gyakoriságkorlát: gépkulccsal 30 / óra, kulcs nélkül 10 / óra / telepítés és 40 / óra / IP */
		$ipk = 'cp_bdg_ip_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
		$ik = 'cp_bdg_i_' . md5($d ? $d['id'] : 'x' . $inst);
		$ni = (int) get_transient($ik); $np = (int) get_transient($ipk);
		if ($ni >= ($d ? 30 : 10) || (!$d && $np >= 40)) { return new WP_REST_Response(array('ok' => false, 'message' => 'túl sok'), 429); }
		set_transient($ik, $ni + 1, HOUR_IN_SECONDS); set_transient($ipk, $np + 1, HOUR_IN_SECONDS);
		$clip = function ($v, $n) { return mb_substr(sanitize_text_field((string) $v), 0, $n); };
		$reasons = array(); foreach (array_slice((array) ($j['reasons'] ?? array()), 0, 30) as $x) { $reasons[] = $clip($x, 300); }
		$meta = array();
		foreach (array('ver' => 60, 'os' => 200, 'key' => 10, 'cam' => 20, 'scr' => 20, 'upErr' => 200) as $k => $n) { $meta[$k] = $clip($j[$k] ?? '', $n); }
		$meta['mac'] = !empty($j['mac']); $meta['net'] = !empty($j['net']); $meta['camOk'] = array_key_exists('camOk', $j) && $j['camOk'] !== null ? (bool) $j['camOk'] : null; $meta['queue'] = max(0, (int) ($j['queue'] ?? 0));
		if (!empty($j['event']) && is_array($j['event'])) { $meta['event'] = array('deal' => (int) ($j['event']['deal'] ?? 0), 'title' => $clip($j['event']['title'] ?? '', 120), 'date' => $clip($j['event']['date'] ?? '', 12)); }
		if (!$d && isset($_SERVER['HTTP_X_BOOTH_KEY']) && trim((string) $_SERVER['HTTP_X_BOOTH_KEY']) !== '') { $meta['keybad'] = 1; } /* volt kulcs, de a panel nem fogadta el */
		$log = (string) ($j['log'] ?? ''); $log = mb_substr(wp_check_invalid_utf8($log, true), -150000);
		$log = preg_replace('/cb_[A-Za-z0-9]{30,}/', '[gépkulcs]', $log); /* biztonsági háló: kulcs ne maradjon a naplóban */
		global $wpdb;
		/* v2.9.391: gépenként egyetlen, mindig friss naplófájl. A gép csak az előző küldés óta írt sorokat küldi; ezeket az
		   eddigi naplóhoz fűzzük (legfeljebb 150 ezer karakter marad), és a gép régebbi bejegyzéseiből a napló törlődik. */
		$who = $d ? $wpdb->prepare('dev = %s', (string) $d['id']) : $wpdb->prepare("dev = '' AND inst = %s", $inst);
		$prev = array_key_exists('full', $j) && empty($j['full']) ? /* a régi felület (full nélkül) mindig a teljes véget küldi: az felülír */ (string) $wpdb->get_var('SELECT log FROM ' . cp_bdg_t() . ' WHERE ' . $who . " AND log <> '' ORDER BY id DESC LIMIT 1") : '';
		if ($prev !== '') { $log = $log === '' ? $prev : rtrim($prev, "\n") . "\n" . $log; $log = mb_substr($log, -150000); }
		$wpdb->query('UPDATE ' . cp_bdg_t() . " SET log = '' WHERE " . $who . " AND log <> ''");
		$wpdb->insert(cp_bdg_t(), array('at' => time(), 'dev' => $d ? (string) $d['id'] : '', 'inst' => $inst, 'name' => $d ? (string) $d['name'] : 'Azonosítatlan gép (' . substr($inst, 0, 6) . ')',
			'reasons' => wp_json_encode($reasons, JSON_UNESCAPED_UNICODE), 'meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE), 'log' => $log));
		if ($d) { cp_bdg_req_take((string) $d['id']); }
		if (wp_rand(1, 20) === 1) { $wpdb->query($wpdb->prepare('DELETE FROM ' . cp_bdg_t() . ' WHERE at < %d', time() - 14 * DAY_IN_SECONDS)); }
		return array('ok' => true);
	}));
});

/* v2.9.389: Crazy panel → Napló → Szelfigépek (a WordPress-admin helyett). Jogosultság: mint a Napló fül. */
add_action('wp_ajax_nopriv_cp_bdg_list', 'cp_bdg_ajax_list'); add_action('wp_ajax_cp_bdg_list', 'cp_bdg_ajax_list');
add_action('wp_ajax_nopriv_cp_bdg_log', 'cp_bdg_ajax_log'); add_action('wp_ajax_cp_bdg_log', 'cp_bdg_ajax_log');
add_action('wp_ajax_nopriv_cp_bdg_ask', 'cp_bdg_ajax_ask'); add_action('wp_ajax_cp_bdg_ask', 'cp_bdg_ajax_ask');
function cp_bdg_guard() {
	cp_el_auth();
	if (!function_exists('cp_panel_can') || !(cp_panel_can('naplo') || cp_panel_can('gepnaplo'))) { cp_el_err('A szelfigép-naplók nincsenek engedélyezve a fiókodnak (Beállítások → Láthatóság).', 403); }
	if (!cp_bdg_ok()) { cp_el_err('Az adatbázis még készül.', 503); }
}
function cp_bdg_ajax_list() {
	cp_bdg_guard();
	global $wpdb;
	$dev = sanitize_text_field(wp_unslash($_POST['dev'] ?? ''));
	$w = $dev === '' ? '' : ($dev === 'anon' ? " WHERE dev = ''" : $wpdb->prepare(' WHERE dev = %s', $dev));
	$rows = array();
	foreach ((array) $wpdb->get_results('SELECT id, at, dev, inst, name, reasons, meta, CHAR_LENGTH(log) AS ln FROM ' . cp_bdg_t() . $w . ' ORDER BY id DESC LIMIT 150', ARRAY_A) as $x) {
		$rows[] = array('id' => (int) $x['id'], 'at' => (int) $x['at'], 'dev' => (string) $x['dev'], 'name' => (string) $x['name'], 'r' => json_decode((string) $x['reasons'], true) ?: array(), 'm' => json_decode((string) $x['meta'], true) ?: new stdClass(), 'ln' => (int) $x['ln']);
	}
	$req = cp_bdg_req_all(); $devs = array();
	foreach (cp_booth_devs() as $id => $d) {
		if ($id === 'panel') { continue; }
		$b = function_exists('cp_booth_beat_get') ? cp_booth_beat_get($id) : null;
		$up = cp_bdg_upd_all()[$id] ?? null;
		$devs[] = array('id' => (string) $id, 'name' => (string) $d['name'], 'off' => !empty($d['off']), 'seen' => (int) ($d['seen'] ?? 0), 'beat' => $b ? (int) $b['t'] : 0, 'ver' => $b ? (string) $b['ver'] : '', 'req' => (int) ($req[$id] ?? 0), 'upd' => $up ? array('res' => (string) $up['res'], 'at' => (int) $up['res_at'], 'done' => (int) $up['done']) : null,
			'last' => (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(at) FROM ' . cp_bdg_t() . ' WHERE dev = %s', $id)));
	}
	wp_send_json_success(array('rows' => $rows, 'devs' => $devs, 'anon' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . cp_bdg_t() . " WHERE dev = ''"), 'now' => time()));
}
function cp_bdg_ajax_log() {
	cp_bdg_guard();
	global $wpdb;
	$l = $wpdb->get_var($wpdb->prepare('SELECT log FROM ' . cp_bdg_t() . ' WHERE id = %d', (int) ($_POST['id'] ?? 0)));
	if ($l === null) { cp_el_err('Nincs ilyen napló.', 404); }
	wp_send_json_success(array('log' => (string) $l));
}
function cp_bdg_ajax_ask() {
	cp_bdg_guard();
	$id = sanitize_text_field(wp_unslash($_POST['dev'] ?? '')); $devs = cp_booth_devs();
	if (!isset($devs[$id]) || $id === 'panel') { cp_el_err('Nincs ilyen gép.', 404); }
	$r = cp_bdg_req_all(); $r[$id] = time(); update_option('cp_bdg_req', $r, false);
	if (function_exists('cp_audit_add')) { cp_audit_add('rendszer', 'booth_diag', 'Szelfigép-napló bekérve: ' . $devs[$id]['name'], '', ''); }
	wp_send_json_success(array('ok' => 1));
}

/* ------------------------------------------------------------ v2.9.402: programfrissítés a panelből (távolról, visszaigazolással)
 * A kérés a gép következő állapotjelentésére (1 percen belül) megy ki; a gép csak tétlen kezdőképernyőn frissít. Az állapotot
 * (keresés / letöltés / telepítés / hiba / naprakész) a jelentésekben küldi; a sikert az jelzi, hogy a gép újabb verzióval jelentkezik. */
function cp_bdg_upd_all() { $u = get_option('cp_bdg_upd', array()); return is_array($u) ? $u : array(); }
function cp_bdg_shell($ver) { return preg_replace('/\/.*$/', '', (string) $ver); }
add_action('wp_ajax_nopriv_cp_bdg_upd', 'cp_bdg_ajax_upd'); add_action('wp_ajax_cp_bdg_upd', 'cp_bdg_ajax_upd');
function cp_bdg_ajax_upd() {
	cp_bdg_guard();
	$id = sanitize_text_field(wp_unslash($_POST['dev'] ?? '')); $devs = cp_booth_devs();
	if (!isset($devs[$id]) || $id === 'panel') { cp_el_err('Nincs ilyen gép.', 404); }
	$b = function_exists('cp_booth_beat_get') ? cp_booth_beat_get($id) : null;
	$u = cp_bdg_upd_all(); $u[$id] = array('at' => time(), 'from' => $b ? cp_bdg_shell($b['ver']) : '', 'by' => function_exists('cp_panel_who') ? cp_panel_who() : '', 'res' => 'kérve', 'res_at' => time(), 'done' => 0, 'to' => '');
	update_option('cp_bdg_upd', $u, false);
	if (function_exists('cp_audit_add')) { cp_audit_add('rendszer', 'booth_upd', 'Szelfigép-programfrissítés kérve: ' . $devs[$id]['name'], '', ''); }
	wp_send_json_success(array('ok' => 1));
}
/* az állapotjelentésből (booth.php /beat hívja): eredmény rögzítése, és kell-e még kérni */
function cp_bdg_upd_beat($dev, $ver, $res) {
	$u = cp_bdg_upd_all(); if (empty($u[$dev]) || !empty($u[$dev]['done'])) { return false; }
	$x = $u[$dev]; $sv = cp_bdg_shell($ver); $ch = false;
	if ($x['from'] !== '' && $sv !== '' && $sv !== $x['from'] && version_compare($sv, $x['from'], '>')) { $x['done'] = 1; $x['to'] = $sv; $x['res'] = 'sikeres: ' . $x['from'] . ' → ' . $sv; $x['res_at'] = time(); $ch = true; }
	elseif ($res !== '' && $res !== $x['res']) { $x['res'] = mb_substr($res, 0, 160); $x['res_at'] = time(); $ch = true; if (strpos($res, 'naprakész') === 0 || strpos($res, 'hiba') === 0) { $x['done'] = strpos($res, 'naprakész') === 0 ? 1 : 2; } }
	if (!$x['done'] && time() - (int) $x['at'] > 6 * HOUR_IN_SECONDS) { $x['done'] = 2; $x['res'] = 'lejárt: a gép 6 órán belül nem frissült'; $x['res_at'] = time(); $ch = true; }
	if ($ch) { $u[$dev] = $x; update_option('cp_bdg_upd', $u, false); }
	return !$x['done'] && strpos((string) $x['res'], 'telepítés') !== 0 && strpos((string) $x['res'], 'letöltés') !== 0;
}

