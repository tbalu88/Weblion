<?php
/* Ügyfélportál – adatbázis: saját táblák ({prefix}crazy_portal_*), migráció, adatelérés
 * Minden időpont UTC-ben (Y-m-d H:i:s), az event_date helyi (Budapest) dátum. */

if (!defined('ABSPATH')) { exit; }

function cp_portal_t($name) {
	global $wpdb;
	return $wpdb->prefix . 'crazy_portal_' . $name;
}

/* A bővítmény fájljai a hídon át cserélődnek, ezért az aktiválási hook nem fut le:
 * minden betöltéskor összevetjük a tárolt sémaverziót, és ha eltér, lefut a dbDelta. */
add_action('plugins_loaded', 'cp_portal_db_maybe_upgrade', 5);
function cp_portal_db_maybe_upgrade() {
	if (get_option('cp_portal_db_ver') === CP_PORTAL_DB_VER) { return; }
	if (get_transient('cp_portal_db_lock')) { return; }
	set_transient('cp_portal_db_lock', 1, 60);
	cp_portal_db_install();
	delete_transient('cp_portal_db_lock');
}

function cp_portal_db_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	$sql = array();
	$sql[] = "CREATE TABLE " . cp_portal_t('portals') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  deal_id varchar(20) NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'aktiv',
  flow varchar(4) NOT NULL DEFAULT 'v1',
  event_date date DEFAULT NULL,
  sm longtext NULL,
  sm_synced_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY deal_id (deal_id),
  KEY event_date (event_date)
) $c;";
	$sql[] = "CREATE TABLE " . cp_portal_t('access_tokens') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  portal_id bigint(20) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  email varchar(190) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  revoked_at datetime NULL,
  last_used_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY portal_id (portal_id)
) $c;";
	$sql[] = "CREATE TABLE " . cp_portal_t('form_answers') . " (
  portal_id bigint(20) unsigned NOT NULL,
  field_key varchar(64) NOT NULL,
  value longtext NOT NULL,
  updated_by varchar(10) NOT NULL DEFAULT 'ugyfel',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (portal_id,field_key)
) $c;";
	$sql[] = "CREATE TABLE " . cp_portal_t('form_state') . " (
  portal_id bigint(20) unsigned NOT NULL,
  required_done tinyint(3) unsigned NOT NULL DEFAULT 0,
  status varchar(10) NOT NULL DEFAULT 'hianyos',
  locked_at datetime NULL,
  completed_at datetime NULL,
  changed_late_at datetime NULL,
  late_notified_log bigint(20) unsigned NOT NULL DEFAULT 0,
  contract_done tinyint(3) unsigned NOT NULL DEFAULT 0,
  contract_ready_at datetime NULL,
  contract_changed_at datetime NULL,
  contract_notified_log bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (portal_id)
) $c;";
	$sql[] = "CREATE TABLE " . cp_portal_t('notifications') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  portal_id bigint(20) unsigned NOT NULL,
  type varchar(60) NOT NULL,
  channel varchar(10) NOT NULL,
  sent_at datetime NOT NULL,
  result varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY portal_type (portal_id,type)
) $c;";
	// saját változásnapló (a kezelői adatlap cp_eld_*.lg naplója 80 karakterre vág és 60 bejegyzést tart)
	$sql[] = "CREATE TABLE " . cp_portal_t('log') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  portal_id bigint(20) unsigned NOT NULL,
  field_key varchar(64) NOT NULL,
  old_value longtext NULL,
  new_value longtext NULL,
  updated_by varchar(10) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY portal_id (portal_id)
) $c;";
	// belépési napló (IP, időpont) – 6 hónapig
	$sql[] = "CREATE TABLE " . cp_portal_t('access_log') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  portal_id bigint(20) unsigned NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  event varchar(20) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY portal_id (portal_id)
) $c;";
	$prev = (string) get_option('cp_portal_db_ver');
	foreach ($sql as $q) { dbDelta($q); }
	$missing = cp_portal_db_missing_tables();
	if (!$missing && $prev !== '' && version_compare($prev, '2', '<')) { cp_portal_db_migrate_2(); }
	if (!$missing && $prev !== '' && version_compare($prev, '3', '<')) { $GLOBALS['cp_portal_recompute_all'] = true; }  // a kötelező kérdések köre változott (v3)
	if (!$missing) {
		update_option('cp_portal_db_ver', CP_PORTAL_DB_VER, true);
	} else {
		update_option('cp_portal_db_error', array('t' => time(), 'missing' => $missing, 'err' => $wpdb->last_error), false);
	}
}

/* v2: a menetrend érkezés része külön mezőbe költözik (a régi „schedule” {arrival,start,end} → „arrival” + „schedule” {start,end}) */
function cp_portal_db_migrate_2() {
	global $wpdb;
	$A = cp_portal_t('form_answers');
	foreach ((array) $wpdb->get_results("SELECT portal_id, value, updated_by, updated_at FROM $A WHERE field_key = 'schedule'") as $r) {
		$v = json_decode($r->value, true);
		if (!is_array($v) || !isset($v['arrival'])) { continue; }
		$arr = (string) $v['arrival'];
		unset($v['arrival']);
		$wpdb->update($A, array('value' => wp_json_encode($v)), array('portal_id' => (int) $r->portal_id, 'field_key' => 'schedule'));
		if ($arr !== '' && !$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $A WHERE portal_id = %d AND field_key = 'arrival'", (int) $r->portal_id))) {
			$wpdb->insert($A, array('portal_id' => (int) $r->portal_id, 'field_key' => 'arrival', 'value' => wp_json_encode($arr), 'updated_by' => $r->updated_by, 'updated_at' => $r->updated_at));
		}
	}
}

/* v3: az állapot (kitöltött kötelező kérdések) újraszámolása minden portálnál – a többi fájl betöltése után */
add_action('init', function () {
	if (empty($GLOBALS['cp_portal_recompute_all']) || !function_exists('cp_portal_recompute')) { return; }
	global $wpdb;
	foreach ((array) $wpdb->get_col('SELECT id FROM ' . cp_portal_t('portals')) as $pid) {
		$p = cp_portal_get($pid);
		if ($p) { cp_portal_recompute($p); }
	}
}, 5);

function cp_portal_db_missing_tables() {
	global $wpdb;
	$out = array();
	foreach (array('portals', 'access_tokens', 'form_answers', 'form_state', 'notifications', 'log', 'access_log') as $t) {
		$n = cp_portal_t($t);
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($n))) !== $n) { $out[] = $t; }
	}
	return $out;
}

function cp_portal_db_ready() {
	return get_option('cp_portal_db_ver') === CP_PORTAL_DB_VER;
}

/* ------------------------------------------------------------ portálok */

function cp_portal_prep($row) {
	if (!$row) { return null; }
	$row->sm = $row->sm ? json_decode($row->sm, true) : array();
	if (!is_array($row->sm)) { $row->sm = array(); }
	return $row;
}

function cp_portal_get($id) {
	global $wpdb;
	return cp_portal_prep($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_portal_t('portals') . ' WHERE id = %d', (int) $id)));
}

function cp_portal_get_by_deal($deal_id) {
	global $wpdb;
	if (!cp_portal_db_ready()) { return null; }
	return cp_portal_prep($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_portal_t('portals') . ' WHERE deal_id = %s', (string) $deal_id)));
}

/* több deal portálja egyszerre: deal_id => portál */
function cp_portal_get_many($deal_ids) {
	global $wpdb;
	$ids = array_values(array_unique(array_filter(array_map('strval', (array) $deal_ids), function ($x) { return preg_match('/^\d{1,20}$/', $x); })));
	if (!$ids || !cp_portal_db_ready()) { return array(); }
	$in = implode(',', array_fill(0, count($ids), '%s'));
	$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cp_portal_t('portals') . " WHERE deal_id IN ($in)", $ids));
	$out = array();
	foreach ((array) $rows as $r) { $out[(string) $r->deal_id] = cp_portal_prep($r); }
	return $out;
}

function cp_portal_update($id, $data) {
	global $wpdb;
	if (isset($data['sm']) && is_array($data['sm'])) { $data['sm'] = wp_json_encode($data['sm']); }
	$data['updated_at'] = cp_portal_db_now();
	return $wpdb->update(cp_portal_t('portals'), $data, array('id' => (int) $id));
}

/* ------------------------------------------------------------ válaszok */

/* field_key => érték (tömb vagy szöveg) */
function cp_portal_answers($pid) {
	$out = array();
	foreach (cp_portal_answer_rows($pid) as $k => $r) { $out[$k] = $r['value']; }
	return $out;
}

/* field_key => array(value, by, at) */
function cp_portal_answer_rows($pid) {
	global $wpdb;
	$rows = $wpdb->get_results($wpdb->prepare('SELECT field_key, value, updated_by, updated_at FROM ' . cp_portal_t('form_answers') . ' WHERE portal_id = %d', (int) $pid));
	$out = array();
	foreach ((array) $rows as $r) {
		$v = json_decode($r->value, true);
		$out[$r->field_key] = array('value' => $v, 'by' => $r->updated_by, 'at' => cp_portal_db_ts($r->updated_at));
	}
	return $out;
}

/* több portál válaszai: portal_id => field_key => érték */
function cp_portal_answers_many($pids) {
	global $wpdb;
	$pids = array_values(array_filter(array_map('intval', (array) $pids)));
	if (!$pids) { return array(); }
	$in = implode(',', array_fill(0, count($pids), '%d'));
	$rows = $wpdb->get_results($wpdb->prepare('SELECT portal_id, field_key, value FROM ' . cp_portal_t('form_answers') . " WHERE portal_id IN ($in)", $pids));
	$out = array();
	foreach ((array) $rows as $r) { $out[(int) $r->portal_id][$r->field_key] = json_decode($r->value, true); }
	return $out;
}

function cp_portal_answer_put($pid, $key, $value, $by) {
	global $wpdb;
	$t = cp_portal_t('form_answers');
	if ($value === null) {
		return $wpdb->delete($t, array('portal_id' => (int) $pid, 'field_key' => $key));
	}
	return $wpdb->query($wpdb->prepare(
		"INSERT INTO $t (portal_id, field_key, value, updated_by, updated_at) VALUES (%d, %s, %s, %s, %s)
		 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
		(int) $pid, $key, wp_json_encode($value), $by, cp_portal_db_now()
	));
}

/* ------------------------------------------------------------ állapot */

function cp_portal_state($pid) {
	global $wpdb;
	$r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_portal_t('form_state') . ' WHERE portal_id = %d', (int) $pid));
	if (!$r) {
		$r = (object) array('portal_id' => (int) $pid, 'required_done' => 0, 'status' => 'hianyos', 'locked_at' => null, 'completed_at' => null, 'changed_late_at' => null, 'late_notified_log' => 0);
	}
	return $r;
}

function cp_portal_states_many($pids) {
	global $wpdb;
	$pids = array_values(array_filter(array_map('intval', (array) $pids)));
	if (!$pids) { return array(); }
	$in = implode(',', array_fill(0, count($pids), '%d'));
	$out = array();
	foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cp_portal_t('form_state') . " WHERE portal_id IN ($in)", $pids)) as $r) { $out[(int) $r->portal_id] = $r; }
	return $out;
}

function cp_portal_state_put($pid, $data) {
	global $wpdb;
	$t = cp_portal_t('form_state');
	$exists = $wpdb->get_var($wpdb->prepare("SELECT portal_id FROM $t WHERE portal_id = %d", (int) $pid));
	if ($exists) { return $wpdb->update($t, $data, array('portal_id' => (int) $pid)); }
	$data['portal_id'] = (int) $pid;
	return $wpdb->insert($t, $data);
}

/* ------------------------------------------------------------ változásnapló */

function cp_portal_log_add($pid, $key, $old, $new, $by) {
	global $wpdb;
	$wpdb->insert(cp_portal_t('log'), array(
		'portal_id' => (int) $pid,
		'field_key' => (string) $key,
		'old_value' => $old === null ? null : wp_json_encode($old),
		'new_value' => $new === null ? null : wp_json_encode($new),
		'updated_by' => (string) $by,
		'created_at' => cp_portal_db_now(),
	));
	return (int) $wpdb->insert_id;
}

function cp_portal_log_list($pid, $limit = 100, $after_id = 0) {
	global $wpdb;
	return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cp_portal_t('log') . ' WHERE portal_id = %d AND id > %d ORDER BY id DESC LIMIT %d', (int) $pid, (int) $after_id, (int) $limit));
}

/* ------------------------------------------------------------ belépési napló */

function cp_portal_access_log($pid, $event) {
	global $wpdb;
	if (!cp_portal_db_ready()) { return; }
	$wpdb->insert(cp_portal_t('access_log'), array(
		'portal_id' => $pid ? (int) $pid : null,
		'ip' => cp_portal_ip(),
		'event' => substr((string) $event, 0, 20),
		'created_at' => cp_portal_db_now(),
	));
}

function cp_portal_ip() {
	return isset($_SERVER['REMOTE_ADDR']) ? substr(preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']), 0, 45) : '';
}

/* ------------------------------------------------------------ hibalista (panel) */

function cp_portal_error_add($deal_id, $kind, $msg) {
	$l = get_option('cp_portal_errors', array());
	if (!is_array($l)) { $l = array(); }
	array_unshift($l, array('t' => time(), 'deal' => (string) $deal_id, 'k' => (string) $kind, 'm' => mb_substr((string) $msg, 0, 400)));
	update_option('cp_portal_errors', array_slice($l, 0, 100), false);
}

function cp_portal_errors($deal_id = null) {
	$l = get_option('cp_portal_errors', array());
	if (!is_array($l)) { return array(); }
	if ($deal_id === null) { return $l; }
	return array_values(array_filter($l, function ($e) use ($deal_id) { return isset($e['deal']) && $e['deal'] === (string) $deal_id; }));
}
