<?php
/**
 * v2.3: Munkanapló (Eszközök → Munkanapló) – napi munkanapló fotókkal, költségekkel, útnyilvántartással.
 * Hozzáférés: alapból Sebestyén Ákos, Gacsal Zsolt, Tóth Balázs (a Felhasználók → Láthatóság táblában módosítható).
 * Mindenki a saját napját írja; a jóváhagyó (admin) jóváhagyja / visszanyitja, és mindenki hozzászólhat.
 * A fotók a webgyökéren KÍVÜL vannak (cp-files/worklog), csak belépve, a panelen át nyithatók meg.
 */
if (!defined('ABSPATH')) { exit; }

define('CP_WL_DB_VER', '2'); /* 2: shifts – további munkaidő-szakaszok egy napon */
function cp_wl_t($n) { global $wpdb; return $wpdb->prefix . 'crazy_wl_' . $n; }
add_action('init', function () {
	if (get_option('cp_wl_db_ver') === CP_WL_DB_VER || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta('CREATE TABLE ' . cp_wl_t('entries') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uid int(11) NOT NULL,
  day date NOT NULL,
  t_start varchar(5) NOT NULL DEFAULT '',
  t_end varchar(5) NOT NULL DEFAULT '',
  break_min int(11) NOT NULL DEFAULT 0,
  shifts text NULL,
  kinds varchar(255) NOT NULL DEFAULT '',
  place varchar(255) NOT NULL DEFAULT '',
  deals varchar(255) NOT NULL DEFAULT '',
  tasks text NULL,
  notes text NULL,
  vehicle varchar(100) NOT NULL DEFAULT '',
  km_start int(11) NULL,
  km_end int(11) NULL,
  km int(11) NULL,
  costs text NULL,
  issue text NULL,
  issue_flag tinyint(1) NOT NULL DEFAULT 0,
  tomorrow text NULL,
  status varchar(12) NOT NULL DEFAULT 'draft',
  approved_by varchar(100) NOT NULL DEFAULT '',
  approved_at datetime NULL,
  created_at datetime NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uday (uid,day),
  KEY day (day)
) $c;");
	dbDelta('CREATE TABLE ' . cp_wl_t('files') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  uid int(11) NOT NULL,
  kind varchar(10) NOT NULL DEFAULT 'photo',
  name varchar(190) NOT NULL DEFAULT '',
  mime varchar(60) NOT NULL DEFAULT '',
  size int(11) NOT NULL DEFAULT 0,
  path varchar(255) NOT NULL DEFAULT '',
  thumb varchar(255) NOT NULL DEFAULT '',
  caption varchar(255) NOT NULL DEFAULT '',
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id)
) $c;");
	dbDelta('CREATE TABLE ' . cp_wl_t('comments') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  uid int(11) NOT NULL,
  author varchar(100) NOT NULL DEFAULT '',
  body text NULL,
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id)
) $c;");
	update_option('cp_wl_db_ver', CP_WL_DB_VER, true); /* v2.9.58: autoload – minden kérés olvassa */
}, 8);
function cp_wl_db_ok() { return get_option('cp_wl_db_ver') === CP_WL_DB_VER; }

/* alapértelmezett hozzáférés: a három kolléga (név szerint felismerve), ha a Láthatóság táblában nincs más beállítva */
function cp_wl_default_uids() {
	$ids = array();
	if (!function_exists('cp_pu_data')) { return $ids; }
	foreach (cp_pu_data()['list'] as $id => $u) {
		$n = mb_strtolower((string) $u['n']);
		if (preg_match('/ákos|gacsal|tóth\s+balázs|balázs\s+tóth/u', $n)) { $ids[] = (int) $id; }
	}
	return $ids;
}
function cp_wl_me() { return function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0; }
function cp_wl_can() { return cp_wl_me() > 0 && function_exists('cp_panel_can') && cp_panel_can('munkanaplo'); }
function cp_wl_users() {
	$out = array();
	foreach (cp_pu_data()['list'] as $id => $u) { if (!empty($u['a']) && cp_pu_can_uid((int) $id, 'munkanaplo', (string) ($u['r'] ?? 'iroda'))) { $out[] = array('id' => (int) $id, 'n' => (string) $u['n'], 'admin' => ($u['r'] ?? '') === 'admin'); } }
	return $out;
}
/* v2.9.61: a napló gazdája (aki írja, és akit emlékeztetni kell) csak Ákos – a többi jogosult (Tóth Balázs, Gacsal Zsolt) olvassa,
 * jóváhagyja és hozzászól, de saját naplója nincs. A „Kinek a naplója” választó, a napló-lekérések és az esti emlékeztető ezt használja. */
function cp_wl_owners() {
	$out = array();
	foreach (cp_wl_users() as $u) { if (preg_match('/ákos/iu', $u['n'])) { $out[] = $u; } }
	return $out;
}
function cp_wl_is_approver() { return function_exists('cp_panel_is_admin') && cp_panel_is_admin(); }
function cp_wl_cfg() {
	if (!cp_wl_db_ok() || !cp_wl_can()) { return array('on' => false); }
	/* v2.9.61: a választóban csak a napló gazdája (Ákos); alapból az ő naplója nyílik */
	$users = cp_wl_owners();
	$main = $users ? $users[0]['id'] : 0;
	return array('on' => true, 'me' => cp_wl_me(), 'main' => $main ?: cp_wl_me(), 'users' => $users, 'approver' => cp_wl_is_approver(),
		'kinds' => array('Rendezvény', 'Szállítás', 'Kiépítés / bontás', 'Műhely / javítás', 'Raktár', 'Beszerzés', 'Iroda', 'Egyéb'));
}
function cp_wl_auth() {
	cp_el_auth();
	if (!cp_wl_db_ok()) { cp_el_err('A Munkanapló adatbázisa készül – töltsd újra az oldalt.', 503); }
	if (!cp_wl_can()) { cp_el_err('A Munkanaplóhoz nincs hozzáférésed.', 403); }
}
function cp_wl_day($v) { $v = (string) $v; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; }
function cp_wl_hm($v) { $v = trim((string) $v); return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $v) ? str_pad($v, 5, '0', STR_PAD_LEFT) : ''; }
/* a nap munkaidő-szakaszai: az első a t_start/t_end, a többi a shifts oszlopban (v2.9.86) – array(array(s, e), …) */
function cp_wl_segs($e) {
	$o = array();
	if ((string) $e['t_start'] !== '' || (string) $e['t_end'] !== '') { $o[] = array((string) $e['t_start'], (string) $e['t_end']); }
	$x = is_array($e['shifts'] ?? null) ? $e['shifts'] : (json_decode((string) ($e['shifts'] ?? ''), true) ?: array());
	foreach ((array) $x as $g) { if (is_array($g) && (($g['s'] ?? '') !== '' || ($g['e'] ?? '') !== '')) { $o[] = array((string) ($g['s'] ?? ''), (string) ($g['e'] ?? '')); } }
	return $o;
}
function cp_wl_span($e) {
	return implode(', ', array_map(function ($g) { return ($g[0] ?: '?') . '–' . ($g[1] ?: '?'); }, cp_wl_segs($e)));
}
function cp_wl_hours($e) {
	$m = 0; $any = false;
	foreach (cp_wl_segs($e) as $g) {
		if ($g[0] === '' || $g[1] === '') { continue; }
		list($a, $b) = explode(':', $g[0]); list($c, $d) = explode(':', $g[1]);
		$x = ((int) $c * 60 + (int) $d) - ((int) $a * 60 + (int) $b);
		if ($x < 0) { $x += 24 * 60; } /* éjfélen átnyúló műszak */
		$m += $x; $any = true;
	}
	if (!$any) { return 0; }
	return max(0, round(($m - (int) $e['break_min']) / 60, 2));
}
function cp_wl_files_dir() {
	$d = dirname(rtrim(ABSPATH, '/')) . '/cp-files/worklog/' . gmdate('Y/m');
	if (!is_dir($d)) { wp_mkdir_p($d); @chmod(dirname(dirname(dirname($d))), 0700); }
	return $d;
}
function cp_wl_out($e) {
	global $wpdb;
	if (!$e) { return null; }
	$e['id'] = (int) $e['id']; $e['uid'] = (int) $e['uid']; $e['break_min'] = (int) $e['break_min']; $e['issue_flag'] = (int) $e['issue_flag'];
	foreach (array('km_start', 'km_end', 'km') as $k) { $e[$k] = $e[$k] === null ? null : (int) $e[$k]; }
	$e['tasks'] = json_decode((string) $e['tasks'], true) ?: array();
	$e['costs'] = json_decode((string) $e['costs'], true) ?: array();
	$e['kinds'] = $e['kinds'] === '' ? array() : explode('|', $e['kinds']);
	$e['shifts'] = array_values(array_filter((array) (json_decode((string) ($e['shifts'] ?? ''), true) ?: array()), 'is_array'));
	$e['hours'] = cp_wl_hours($e);
	$e['files'] = array_map(function ($f) { return array('id' => (int) $f['id'], 'kind' => $f['kind'], 'name' => $f['name'], 'caption' => $f['caption'], 'size' => (int) $f['size']); }, (array) $wpdb->get_results($wpdb->prepare('SELECT id, kind, name, caption, size FROM ' . cp_wl_t('files') . ' WHERE entry_id = %d ORDER BY id', $e['id']), ARRAY_A));
	$e['comments'] = array_map(function ($c) { return array('id' => (int) $c['id'], 'uid' => (int) $c['uid'], 'author' => $c['author'], 'body' => $c['body'], 'at' => function_exists('cp_crm_local') ? cp_crm_local($c['created_at']) : $c['created_at']); }, (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cp_wl_t('comments') . ' WHERE entry_id = %d ORDER BY id', $e['id']), ARRAY_A));
	/* kapcsolt deal-ek neve */
	$e['deal_list'] = array();
	$ids = array_filter(array_map('intval', explode(',', (string) $e['deals'])));
	if ($ids && function_exists('cp_crm_t')) { $e['deal_list'] = array_map(function ($d) { return array('id' => (int) $d['id'], 'title' => $d['title']); }, (array) $wpdb->get_results('SELECT id, title FROM ' . cp_crm_t('deals') . ' WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A)); }
	if ($e['approved_at']) { $e['approved_at'] = function_exists('cp_crm_local') ? cp_crm_local($e['approved_at']) : $e['approved_at']; }
	if ($e['updated_at']) { $e['updated_at'] = function_exists('cp_crm_local') ? cp_crm_local($e['updated_at']) : $e['updated_at']; }
	return $e;
}
function cp_wl_get_row($uid, $day) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_wl_t('entries') . ' WHERE uid = %d AND day = %s', $uid, $day), ARRAY_A); }
function cp_wl_ensure($uid, $day) {
	global $wpdb;
	$e = cp_wl_get_row($uid, $day);
	if ($e) { return $e; }
	$wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . cp_wl_t('entries') . " (uid, day, status, created_at, updated_at) VALUES (%d, %s, 'draft', %s, %s)", $uid, $day, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')));
	return cp_wl_get_row($uid, $day);
}
function cp_wl_uid_param() {
	$uid = (int) ($_REQUEST['uid'] ?? 0);
	$own = cp_wl_owners();
	if (!$uid) { $uid = $own ? $own[0]['id'] : cp_wl_me(); }
	$ok = false; foreach ($own as $u) { if ($u['id'] === $uid) { $ok = true; } }
	if (!$ok) { cp_el_err('Ennek a kollégának nincs munkanaplója.'); }
	return $uid;
}

/* ------------------------------------------------------------ végpontok */
add_action('wp_ajax_nopriv_cp_wl_get', 'cp_wl_ajax_get'); add_action('wp_ajax_cp_wl_get', 'cp_wl_ajax_get');
function cp_wl_ajax_get() {
	cp_wl_auth();
	$uid = cp_wl_uid_param(); $day = cp_wl_day($_REQUEST['day'] ?? '');
	if (!$day) { cp_el_err('Hibás nap.'); }
	$e = cp_wl_get_row($uid, $day);
	/* aznapi rendezvények (gyors választáshoz) */
	$sug = array();
	if (function_exists('cp_crm_t')) {
		global $wpdb;
		$sug = array_map(function ($d) { return array('id' => (int) $d['id'], 'title' => $d['title'], 'venue' => (string) $d['venue']); }, (array) $wpdb->get_results($wpdb->prepare('SELECT id, title, venue FROM ' . cp_crm_t('deals') . " WHERE deleted_at IS NULL AND status <> 'Lost' AND event_d BETWEEN %s AND %s ORDER BY event_d, id LIMIT 30", gmdate('Y-m-d', strtotime($day . ' -1 day')), gmdate('Y-m-d', strtotime($day . ' +1 day'))), ARRAY_A));
	}
	/* az előző nap „holnapra” teendői és km-záróállása (folytatáshoz) */
	global $wpdb;
	$prev = $wpdb->get_row($wpdb->prepare('SELECT day, tomorrow, km_end, vehicle FROM ' . cp_wl_t('entries') . ' WHERE uid = %d AND day < %s ORDER BY day DESC LIMIT 1', $uid, $day), ARRAY_A);
	wp_send_json_success(array('e' => cp_wl_out($e), 'sug' => $sug, 'prev' => $prev));
}

add_action('wp_ajax_nopriv_cp_wl_save', 'cp_wl_ajax_save'); add_action('wp_ajax_cp_wl_save', 'cp_wl_ajax_save');
function cp_wl_ajax_save() {
	cp_wl_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$day = cp_wl_day($_POST['day'] ?? ''); if (!$day) { cp_el_err('Hibás nap.'); }
	$me = cp_wl_me();
	$e = cp_wl_ensure($me, $day);
	if ($e['status'] === 'approved') { cp_el_err('Ez a nap már jóvá van hagyva – módosításhoz kérd a visszanyitását.'); }
	$f = json_decode((string) wp_unslash($_POST['f'] ?? ''), true); if (!is_array($f)) { cp_el_err('Hibás kérés.'); }
	$s = function ($v, $n) { return mb_substr(trim(sanitize_text_field((string) $v)), 0, $n); };
	$ta = function ($v, $n) { return mb_substr(trim(sanitize_textarea_field((string) $v)), 0, $n); };
	$int = function ($v) { return $v === '' || $v === null ? null : max(0, min(9999999, (int) $v)); };
	$set = array();
	if (array_key_exists('t_start', $f)) { $set['t_start'] = cp_wl_hm($f['t_start']); }
	if (array_key_exists('t_end', $f)) { $set['t_end'] = cp_wl_hm($f['t_end']); }
	if (array_key_exists('shifts', $f)) { $set['shifts'] = wp_json_encode(array_values(array_slice(array_map(function ($g) { return array('s' => cp_wl_hm(is_array($g) ? ($g['s'] ?? '') : ''), 'e' => cp_wl_hm(is_array($g) ? ($g['e'] ?? '') : '')); }, (array) $f['shifts']), 0, 10))); }
	if (array_key_exists('break_min', $f)) { $set['break_min'] = max(0, min(600, (int) $f['break_min'])); }
	if (array_key_exists('kinds', $f)) { $set['kinds'] = mb_substr(implode('|', array_map(function ($x) use ($s) { return str_replace('|', '/', $s($x, 40)); }, array_slice((array) $f['kinds'], 0, 10))), 0, 255); }
	if (array_key_exists('place', $f)) { $set['place'] = $s($f['place'], 255); }
	if (array_key_exists('deals', $f)) { $set['deals'] = implode(',', array_slice(array_unique(array_filter(array_map('intval', (array) $f['deals']))), 0, 20)); }
	if (array_key_exists('tasks', $f)) { $set['tasks'] = wp_json_encode(array_values(array_slice(array_filter(array_map(function ($t) use ($s) { return is_array($t) && trim((string) ($t['t'] ?? '')) !== '' ? array('t' => $s($t['t'], 300), 'done' => !empty($t['done']), 'min' => max(0, min(1440, (int) ($t['min'] ?? 0))), 'tag' => in_array((string) ($t['tag'] ?? ''), cp_wl_tags(), true) ? (string) $t['tag'] : '') : null; }, (array) $f['tasks'])), 0, 60)), JSON_UNESCAPED_UNICODE); }
	if (array_key_exists('notes', $f)) { $set['notes'] = $ta($f['notes'], 8000); }
	if (array_key_exists('vehicle', $f)) { $set['vehicle'] = $s($f['vehicle'], 100); }
	foreach (array('km_start', 'km_end', 'km') as $k) { if (array_key_exists($k, $f)) { $set[$k] = $int($f[$k]); } }
	if (array_key_exists('km_start', $f) || array_key_exists('km_end', $f)) {
		$ks = array_key_exists('km_start', $set) ? $set['km_start'] : ($e['km_start'] === null ? null : (int) $e['km_start']);
		$ke = array_key_exists('km_end', $set) ? $set['km_end'] : ($e['km_end'] === null ? null : (int) $e['km_end']);
		if ($ks !== null && $ke !== null && $ke >= $ks) { $set['km'] = $ke - $ks; }
	}
	if (array_key_exists('costs', $f)) { $set['costs'] = wp_json_encode(array_values(array_slice(array_filter(array_map(function ($c) use ($s) { return is_array($c) && (trim((string) ($c['what'] ?? '')) !== '' || (float) ($c['amount'] ?? 0) > 0) ? array('what' => $s($c['what'] ?? '', 200), 'amount' => round(max(0, (float) str_replace(array(' ', ','), array('', '.'), (string) ($c['amount'] ?? 0))), 0), 'pay' => in_array($c['pay'] ?? '', array('kp', 'kartya', 'ceges', 'atutalas'), true) ? $c['pay'] : 'kp', 'file' => (int) ($c['file'] ?? 0)) : null; }, (array) $f['costs'])), 0, 40)), JSON_UNESCAPED_UNICODE); }
	$newIssue = false;
	if (array_key_exists('issue', $f)) { $set['issue'] = $ta($f['issue'], 4000); }
	if (array_key_exists('issue_flag', $f)) { $set['issue_flag'] = !empty($f['issue_flag']) ? 1 : 0; $newIssue = $set['issue_flag'] && !(int) $e['issue_flag']; }
	if (array_key_exists('tomorrow', $f)) { $set['tomorrow'] = $ta($f['tomorrow'], 4000); }
	if (!$set) { cp_el_err('Nincs mit menteni.'); }
	$set['updated_at'] = gmdate('Y-m-d H:i:s');
	global $wpdb;
	$wpdb->update(cp_wl_t('entries'), $set, array('id' => (int) $e['id']));
	/* hiba / eszközprobléma jelzése → az adminok értesítést kapnak */
	if ($newIssue) { cp_wl_notify_admins('hibát / eszközproblémát jelzett a munkanaplóban (' . $day . '): ' . mb_substr((string) ($set['issue'] ?? $e['issue']), 0, 160), $day); }
	wp_send_json_success(array('e' => cp_wl_out(cp_wl_get_row($me, $day))));
}

function cp_wl_notify_admins($text, $day) {
	if (!function_exists('cp_notify')) { return; }
	foreach (cp_wl_users() as $u) { if ($u['admin'] && $u['id'] !== cp_wl_me()) { cp_notify($u['id'], 'wl_closed', $text, 'munkanaplo:' . $day); } }
}

/* állapot: beküldés (saját), jóváhagyás / visszanyitás (admin, nem a sajátját) */
add_action('wp_ajax_nopriv_cp_wl_status', 'cp_wl_ajax_status'); add_action('wp_ajax_cp_wl_status', 'cp_wl_ajax_status');
/* v2.9.172: az elvégzett feladatok kötelező címkéi (és időtartam percben) */
function cp_wl_tags() { return array('Raktár', 'Rendezvény', 'Office', 'Egyéb'); }
function cp_wl_task_meta($t) { $m = (int) ($t['min'] ?? 0); $o = array(); if ($m > 0) { $o[] = ($m >= 60 ? intdiv($m, 60) . ' ó' : '') . ($m % 60 ? ($m >= 60 ? ' ' : '') . ($m % 60) . ' p' : ''); } if (!empty($t['tag'])) { $o[] = (string) $t['tag']; } return $o ? ' (' . implode(' · ', $o) . ')' : ''; }

function cp_wl_ajax_status() {
	cp_wl_auth();
	global $wpdb;
	$uid = cp_wl_uid_param(); $day = cp_wl_day($_POST['day'] ?? ''); $to = (string) ($_POST['to'] ?? '');
	$e = $day ? cp_wl_get_row($uid, $day) : null; if (!$e) { cp_el_err('Erre a napra még nincs bejegyzés.'); }
	$me = cp_wl_me(); $who = function_exists('cp_panel_who') ? cp_panel_who() : '';
	if ($to === 'submitted') {
		if ($uid !== $me) { cp_el_err('Csak a saját napodat zárhatod le.'); }
		if ($e['status'] === 'approved') { cp_el_err('Már jóvá van hagyva.'); }
		foreach ((json_decode((string) $e['tasks'], true) ?: array()) as $t) { if (!empty($t['done']) && (empty($t['min']) || empty($t['tag']))) { cp_el_err('Minden elvégzett feladatnál add meg, mennyi idő ment el rá, és a címkéjét.'); } }
		/* v2.9.58: már beküldött nap újraküldése (dupla kattintás / két fül) nem küld második értesítést és Pumble-riportot;
		 * a feltételes UPDATE miatt két egyidejű kérésből is csak az egyik „nyer” */
		if ($e['status'] === 'submitted') { wp_send_json_success(array('e' => cp_wl_out($e))); }
		$ok = $wpdb->query($wpdb->prepare('UPDATE ' . cp_wl_t('entries') . " SET status = 'submitted', updated_at = %s WHERE id = %d AND status = 'draft'", gmdate('Y-m-d H:i:s'), (int) $e['id']));
		if (!$ok) { wp_send_json_success(array('e' => cp_wl_out(cp_wl_get_row($uid, $day)))); }
		cp_wl_notify_admins('lezárta a munkanaplóját (' . $day . ', ' . cp_wl_hours($e) . ' óra) – jóváhagyásra vár.', $day);
		/* v2.6: összefoglaló a Pumble riport-csatornájába */
		$pr = cp_wl_pumble_report($uid, $day);
		if (is_wp_error($pr) && $pr->get_error_code() !== 'nohook') { error_log('Crazy panel munkanapló Pumble: ' . $pr->get_error_message()); }
	} elseif ($to === 'approved' || $to === 'draft') {
		if (!cp_wl_is_approver()) { cp_el_err('Jóváhagyni / visszanyitni csak admin tud.'); }
		if ($to === 'approved' && $uid === $me) { cp_el_err('A saját napodat nem hagyhatod jóvá.'); }
		$wpdb->update(cp_wl_t('entries'), array('status' => $to, 'approved_by' => $to === 'approved' ? $who : '', 'approved_at' => $to === 'approved' ? gmdate('Y-m-d H:i:s') : null), array('id' => (int) $e['id']));
		if (function_exists('cp_notify') && $uid !== $me) { cp_notify($uid, 'wl_review', ($to === 'approved' ? 'jóváhagyta' : 'visszanyitotta (javítsd, és zárd le újra)') . ' a munkanaplódat: ' . $day, 'munkanaplo:' . $day); }
	} else { cp_el_err('Hibás kérés.'); }
	wp_send_json_success(array('e' => cp_wl_out(cp_wl_get_row($uid, $day))));
}

add_action('wp_ajax_nopriv_cp_wl_comment', 'cp_wl_ajax_comment'); add_action('wp_ajax_cp_wl_comment', 'cp_wl_ajax_comment');
function cp_wl_ajax_comment() {
	cp_wl_auth();
	global $wpdb;
	$uid = cp_wl_uid_param(); $day = cp_wl_day($_POST['day'] ?? '');
	$body = mb_substr(trim(sanitize_textarea_field(wp_unslash((string) ($_POST['body'] ?? '')))), 0, 3000);
	if ($body === '' || !$day) { cp_el_err('Üres a hozzászólás.'); }
	/* v2.9.58: más kolléga napjához hozzászólva ne jöjjön létre neki üres „folyamatban” bejegyzés */
	$e = $uid === cp_wl_me() ? cp_wl_ensure($uid, $day) : cp_wl_get_row($uid, $day);
	if (!$e) { cp_el_err('Erre a napra még nincs bejegyzés – hozzászólni csak meglévő naphoz lehet.'); }
	$wpdb->insert(cp_wl_t('comments'), array('entry_id' => (int) $e['id'], 'uid' => cp_wl_me(), 'author' => function_exists('cp_panel_who') ? cp_panel_who() : '', 'body' => $body, 'created_at' => gmdate('Y-m-d H:i:s')));
	if (function_exists('cp_notify')) {
		$to = array($uid); foreach (cp_wl_users() as $u) { if ($u['admin']) { $to[] = $u['id']; } }
		foreach (array_unique($to) as $t) { if ($t !== cp_wl_me()) { cp_notify($t, 'wl_comment', 'hozzászólt a munkanaplóhoz (' . $day . '): „' . mb_substr($body, 0, 100) . '”', 'munkanaplo:' . $day); } }
	}
	wp_send_json_success(array('e' => cp_wl_out(cp_wl_get_row($uid, $day))));
}

/* fotó / blokk feltöltése (a böngésző már kicsinyíti; itt csak kép lehet) */
add_action('wp_ajax_nopriv_cp_wl_upload', 'cp_wl_ajax_upload'); add_action('wp_ajax_cp_wl_upload', 'cp_wl_ajax_upload');
function cp_wl_ajax_upload() {
	cp_wl_auth();
	global $wpdb;
	$day = cp_wl_day($_POST['day'] ?? ''); if (!$day) { cp_el_err('Hibás nap.'); }
	$me = cp_wl_me();
	$e = cp_wl_ensure($me, $day);
	if ($e['status'] === 'approved') { cp_el_err('Ez a nap már jóvá van hagyva.'); }
	if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) { cp_el_err('Nem jött fájl.'); }
	if ((int) $_FILES['file']['size'] > 12 * 1048576) { cp_el_err('A kép túl nagy (12 MB felett).'); }
	$info = @getimagesize($_FILES['file']['tmp_name']);
	$mime = $info['mime'] ?? '';
	if (!in_array($mime, array('image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'), true)) { cp_el_err('Csak kép tölthető fel (JPG, PNG, WebP).'); }
	if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_wl_t('files') . ' WHERE entry_id = %d', (int) $e['id'])) >= 60) { cp_el_err('Egy naphoz legfeljebb 60 kép tölthető fel.'); }
	$dir = cp_wl_files_dir();
	$ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
	$base = wp_generate_password(20, false, false);
	$path = $dir . '/' . $base . '.' . $ext;
	if (!@move_uploaded_file($_FILES['file']['tmp_name'], $path)) { cp_el_err('A képet nem sikerült menteni.'); }
	@chmod($path, 0600);
	/* bélyegkép a listához */
	$thumb = '';
	$ed = wp_get_image_editor($path);
	if (!is_wp_error($ed)) { $ed->resize(480, 480, false); $ed->set_quality(78); $tp = $dir . '/' . $base . '_t.jpg'; $sv = $ed->save($tp, 'image/jpeg'); if (!is_wp_error($sv)) { $thumb = $tp; @chmod($tp, 0600); } }
	$kind = ($_POST['kind'] ?? '') === 'receipt' ? 'receipt' : 'photo';
	$name = mb_substr(sanitize_file_name(wp_unslash((string) ($_FILES['file']['name'] ?? 'kep.' . $ext))), 0, 190);
	$wpdb->insert(cp_wl_t('files'), array('entry_id' => (int) $e['id'], 'uid' => $me, 'kind' => $kind, 'name' => $name, 'mime' => $mime, 'size' => (int) filesize($path), 'path' => $path, 'thumb' => $thumb, 'caption' => mb_substr(sanitize_text_field(wp_unslash((string) ($_POST['caption'] ?? ''))), 0, 255), 'created_at' => gmdate('Y-m-d H:i:s')));
	$fid = (int) $wpdb->insert_id;
	$wpdb->update(cp_wl_t('entries'), array('updated_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $e['id']));
	wp_send_json_success(array('id' => $fid, 'e' => cp_wl_out(cp_wl_get_row($me, $day))));
}

/* kép megnyitása (csak belépve, hozzáféréssel) */
add_action('wp_ajax_nopriv_cp_wl_file', 'cp_wl_ajax_file'); add_action('wp_ajax_cp_wl_file', 'cp_wl_ajax_file');
function cp_wl_ajax_file() {
	if (!function_exists('cp_panel_authed') || !cp_panel_authed() || !cp_wl_can()) { status_header(403); exit('Nincs hozzáférés.'); }
	global $wpdb;
	$f = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_wl_t('files') . ' WHERE id = %d', (int) ($_GET['id'] ?? 0)), ARRAY_A);
	if (!$f) { status_header(404); exit('Nincs ilyen kép.'); }
	$p = !empty($_GET['t']) && $f['thumb'] !== '' && is_file($f['thumb']) ? $f['thumb'] : $f['path'];
	$root = dirname(rtrim(ABSPATH, '/')) . '/cp-files/worklog/';
	if (!is_file($p) || strpos(realpath($p), realpath($root)) !== 0) { status_header(404); exit('A kép nincs meg.'); }
	nocache_headers();
	header('Content-Type: ' . ($p === $f['thumb'] ? 'image/jpeg' : $f['mime']));
	header('Content-Length: ' . filesize($p));
	header('X-Content-Type-Options: nosniff');
	header('Content-Security-Policy: sandbox');
	header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . rawurlencode($f['name']) . '"');
	header('Cache-Control: private, max-age=86400');
	readfile($p);
	exit;
}
add_action('wp_ajax_nopriv_cp_wl_file_del', 'cp_wl_ajax_file_del'); add_action('wp_ajax_cp_wl_file_del', 'cp_wl_ajax_file_del');
function cp_wl_ajax_file_del() {
	cp_wl_auth();
	global $wpdb;
	$f = $wpdb->get_row($wpdb->prepare('SELECT f.*, e.status, e.day, e.uid euid FROM ' . cp_wl_t('files') . ' f JOIN ' . cp_wl_t('entries') . ' e ON e.id = f.entry_id WHERE f.id = %d', (int) ($_POST['id'] ?? 0)), ARRAY_A);
	if (!$f) { cp_el_err('Nincs ilyen kép.'); }
	if ((int) $f['euid'] !== cp_wl_me()) { cp_el_err('Csak a saját képedet törölheted.'); }
	if ($f['status'] === 'approved') { cp_el_err('Ez a nap már jóvá van hagyva.'); }
	@unlink($f['path']); if ($f['thumb'] !== '') { @unlink($f['thumb']); }
	$wpdb->delete(cp_wl_t('files'), array('id' => (int) $f['id']));
	wp_send_json_success(array('e' => cp_wl_out(cp_wl_get_row((int) $f['euid'], $f['day']))));
}

/* havi áttekintés + CSV */
add_action('wp_ajax_nopriv_cp_wl_month', 'cp_wl_ajax_month'); add_action('wp_ajax_cp_wl_month', 'cp_wl_ajax_month');
function cp_wl_ajax_month() {
	cp_wl_auth();
	global $wpdb;
	$uid = cp_wl_uid_param();
	$m = preg_match('/^\d{4}-\d{2}$/', (string) ($_REQUEST['m'] ?? '')) ? (string) $_REQUEST['m'] : wp_date('Y-m');
	$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT e.*, (SELECT COUNT(*) FROM ' . cp_wl_t('files') . " f WHERE f.entry_id = e.id AND f.kind = 'photo') ph FROM " . cp_wl_t('entries') . ' e WHERE e.uid = %d AND e.day BETWEEN %s AND %s ORDER BY e.day', $uid, $m . '-01', gmdate('Y-m-t', strtotime($m . '-01'))), ARRAY_A);
	$out = array(); $tot = array('hours' => 0, 'km' => 0, 'cost' => 0, 'days' => 0);
	foreach ($rows as $r) {
		$costs = json_decode((string) $r['costs'], true) ?: array(); $c = 0; foreach ($costs as $x) { $c += (float) ($x['amount'] ?? 0); }
		$tasks = json_decode((string) $r['tasks'], true) ?: array();
		$h = cp_wl_hours($r);
		$sg = cp_wl_segs($r);
		$out[] = array('day' => $r['day'], 't_start' => $sg ? $sg[0][0] : '', 't_end' => $sg ? $sg[count($sg) - 1][1] : '', 'span' => count($sg) > 1 ? cp_wl_span($r) : '', 'hours' => $h, 'km' => $r['km'] === null ? null : (int) $r['km'], 'cost' => $c, 'status' => $r['status'], 'kinds' => $r['kinds'] === '' ? array() : explode('|', $r['kinds']), 'place' => $r['place'], 'photos' => (int) $r['ph'], 'tasks' => count($tasks), 'done' => count(array_filter($tasks, function ($t) { return !empty($t['done']); })), 'issue' => (int) $r['issue_flag'], 'notes' => mb_substr((string) $r['notes'], 0, 140));
		$tot['hours'] += $h; $tot['km'] += (int) $r['km']; $tot['cost'] += $c; if ($h > 0 || $r['notes'] !== '' || $tasks) { $tot['days']++; }
	}
	$tot['hours'] = round($tot['hours'], 2);
	if (!empty($_REQUEST['csv'])) {
		$name = ''; foreach (cp_wl_users() as $u) { if ($u['id'] === $uid) { $name = $u['n']; } }
		nocache_headers();
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="munkanaplo-' . sanitize_file_name(remove_accents($name)) . '-' . $m . '.csv"');
		echo "\xEF\xBB\xBF";
		$fh = fopen('php://output', 'w');
		fputcsv($fh, array('Dátum', 'Kezdés', 'Befejezés', 'Szakaszok', 'Óra', 'Munka típusa', 'Helyszín', 'Km', 'Költség (Ft)', 'Fotók', 'Feladatok (kész/összes)', 'Állapot', 'Megjegyzés'), ';');
		$st = array('draft' => 'folyamatban', 'submitted' => 'lezárva', 'approved' => 'jóváhagyva');
		/* v2.9.58: CSV-képletbefecskendezés ellen – a szöveges (felhasználói) cellák elé ' kerül, ha =, +, -, @ jellel kezdődnek */
		$cs = function ($v) { $v = (string) $v; return $v !== '' && strpos("=+-@\t\r", $v[0]) !== false ? "'" . $v : $v; };
		foreach ($out as $r) { fputcsv($fh, array($r['day'], $r['t_start'], $r['t_end'], $r['span'], str_replace('.', ',', (string) $r['hours']), $cs(implode(', ', $r['kinds'])), $cs($r['place']), $r['km'], $r['cost'], $r['photos'], $r['done'] . '/' . $r['tasks'], $st[$r['status']] ?? $r['status'], $cs($r['notes'])), ';'); }
		fputcsv($fh, array('Összesen', '', '', '', str_replace('.', ',', (string) $tot['hours']), '', '', $tot['km'], $tot['cost'], '', '', '', $tot['days'] . ' munkanap'), ';');
		fclose($fh); exit;
	}
	wp_send_json_success(array('m' => $m, 'rows' => $out, 'tot' => $tot));
}

/* emlékeztető: munkanapon 19:00-kor, ha a napló írójának még nincs bejegyzése aznapra */
add_action('init', function () { if (cp_wl_db_ok() && !wp_next_scheduled('cp_wl_remind')) { wp_schedule_event(time() + 600, 'cp_5min', 'cp_wl_remind'); } });
add_action('cp_wl_remind', function () {
	$tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Budapest');
	$now = new DateTime('now', $tz);
	if ((int) $now->format('N') > 5 || (int) $now->format('G') !== 19) { return; }
	$day = $now->format('Y-m-d');
	if (get_option('cp_wl_reminded') === $day) { return; }
	update_option('cp_wl_reminded', $day, false);
	global $wpdb;
	/* v2.9.61: csak a napló gazdája kap emlékeztetőt (Ákos) – korábban mindenki, akinek 30 napon belül volt bejegyzése
	   (egy hozzászólás is létrehozhatott ilyet), így az olvasók (Tóth Balázs, Gacsal Zsolt) is kaptak */
	$writers = array_map(function ($u) { return (int) $u['id']; }, cp_wl_owners());
	foreach ($writers as $w) {
		$e = cp_wl_get_row($w, $day);
		if ($e && ($e['t_start'] !== '' || trim((string) $e['notes']) !== '')) { continue; }
		if (function_exists('cp_notify')) { cp_notify($w, 'wl_remind', 'emlékeztető: a mai munkanapló még üres – írd meg a nap végén.', 'munkanaplo:' . $day, 'Crazy panel'); }
	}
});

/* ------------------------------------------------------------ v2.6: lezárt munkanap → Pumble riport-csatorna
 * Webhook: Beállítások → Munkanapló („Pumble webhook a munkanapló-riporthoz”); ha nincs megadva, az Összesítők (riport)
 * csatornájába megy. Képet nem küld (azok a panelben maradnak), csak a szöveges összefoglalót és a linket. */
function cp_wl_hook() {
	$h = function_exists('cp_st_secret') ? (string) cp_st_secret('wl_webhook') : '';
	if ($h === '' && function_exists('cp_st_dg_hook')) { $h = (string) cp_st_dg_hook(); }
	return $h;
}
function cp_wl_report_text($uid, $day) {
	$e = cp_wl_out(cp_wl_get_row($uid, $day));
	if (!$e) { return ''; }
	$u = function_exists('cp_pu_get') ? cp_pu_get($uid) : null;
	$hm = array('január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
	$dn = array('', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap');
	$p = array_map('intval', explode('-', $day)); $ts = strtotime($day . ' 12:00:00');
	$dl = $p[0] . '. ' . $hm[$p[1] - 1] . ' ' . $p[2] . '. (' . $dn[(int) gmdate('N', $ts)] . ')';
	$num = function ($n) { return str_replace('.', ',', (string) $n); };
	$ft = function ($n) { return number_format((float) $n, 0, ',', ' ') . ' Ft'; };
	$L = array('📋 **Munkanapló – ' . ($u ? $u['n'] : '#' . $uid) . ', ' . $dl . '**');
	if (cp_wl_segs($e)) { $L[] = '🕘 Munkaidő: ' . cp_wl_span($e) . ($e['break_min'] ? ' (szünet ' . $e['break_min'] . ' perc)' : '') . ' · **' . $num($e['hours']) . ' óra**'; }
	if ($e['kinds'] || trim((string) $e['place']) !== '') { $L[] = '🏷 ' . trim(implode(', ', $e['kinds']) . ($e['place'] ? ' · ' . $e['place'] : ''), ' ·'); }
	if ($e['deal_list']) { $L[] = '🎉 Rendezvények: ' . implode('; ', array_map(function ($d) { return $d['title']; }, $e['deal_list'])); }
	if ($e['tasks']) {
		$done = count(array_filter($e['tasks'], function ($t) { return !empty($t['done']); }));
		$L[] = '✅ Feladatok: ' . $done . '/' . count($e['tasks']) . ' kész';
		foreach (array_slice($e['tasks'], 0, 12) as $t) { $L[] = '   ' . (!empty($t['done']) ? '☑' : '☐') . ' ' . mb_substr((string) $t['t'], 0, 120) . cp_wl_task_meta($t); }
	}
	if (trim((string) $e['notes']) !== '') { $L[] = '📝 ' . mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $e['notes'])), 0, 400); }
	if ($e['km'] !== null || $e['vehicle'] !== '') { $L[] = '🚐 ' . trim(($e['vehicle'] ? $e['vehicle'] . ' · ' : '') . ($e['km'] !== null ? $e['km'] . ' km' : ''), ' ·'); }
	if ($e['costs']) {
		$sum = array_sum(array_map(function ($c) { return (float) ($c['amount'] ?? 0); }, $e['costs']));
		$L[] = '🧾 Költségek: **' . $ft($sum) . '** (' . implode('; ', array_map(function ($c) use ($ft) { return trim(($c['what'] ?? '') . ' ' . $ft($c['amount'] ?? 0)); }, array_slice($e['costs'], 0, 6))) . ')';
	}
	$ph = count(array_filter($e['files'], function ($f) { return $f['kind'] === 'photo'; }));
	if ($ph) { $L[] = '📷 ' . $ph . ' fotó (a panelben)'; }
	if (!empty($e['issue_flag']) || trim((string) $e['issue']) !== '') { $L[] = '⚠ **Probléma:** ' . mb_substr(trim((string) $e['issue']), 0, 400); }
	if (trim((string) $e['tomorrow']) !== '') { $L[] = '➡ Holnap: ' . mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $e['tomorrow'])), 0, 300); }
	if (function_exists('cp_pu_panel_url')) { $L[] = '[Megnyitás a Crazy panelben](' . cp_pu_panel_url() . '#munkanaplo:' . $day . ')'; }
	return implode("\n", $L);
}
function cp_wl_pumble_report($uid, $day) {
	if (!get_option('cp_wl_pb', 1)) { return new WP_Error('nohook', 'ki'); }
	if (function_exists('cp_ch_on') && !cp_ch_on('wl')) { return new WP_Error('chan_off', 'Ez a csatorna-üzenet ki van kapcsolva (Beállítások → Értesítések).'); }
	$hook = cp_wl_hook();
	if ($hook === '' || !preg_match('#^https://api\.pumble\.com/[A-Za-z0-9/_.\-]+$#', $hook)) { return new WP_Error('nohook', 'Nincs Pumble webhook.'); }
	/* ugyanarról a napról újra lezárásnál (visszanyitás után) „javítva” jelöléssel */
	$k = 'cp_wl_pb_' . (int) $uid . '_' . $day;
	$again = (bool) get_option($k);
	$txt = cp_wl_report_text($uid, $day);
	if ($txt === '') { return new WP_Error('empty', 'Nincs bejegyzés.'); }
	if ($again) { $txt = preg_replace('/^📋 \*\*/u', '📋 **(javítva) ', $txt, 1); }
	$r = function_exists('cp_el_pumble') ? cp_el_pumble($txt, $hook) : wp_remote_post($hook, array('timeout' => 10, 'headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode(array('text' => $txt))));
	if (!is_wp_error($r)) { update_option($k, time(), false); }
	return $r;
}
