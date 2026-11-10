<?php
/* =====================================================================
 * Panelhasználat (v2.9.88) – ki mennyi időt töltött a panelben, és mikor. Csak az adminok látják (Eszközök → Napló).
 * Forrás: a panel percenkénti háttérkérése (cp_ping, lásd ping.php → cp_ping_track). Egy perc „nyitott”, ha a panel
 * látható böngészőablakban volt, és „aktív”, ha közben egér-, billentyű-, görgetés- vagy érintés-esemény is történt.
 * Csak a személyes fiókokat méri (a közös jelszavas belépést nem lehet személyhez kötni).
 * Megőrzés: a percenkénti részletek 90 napig, a napi összesítők 2 évig.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_US_DB_VER', '1');
function cp_us_t($n) { global $wpdb; return $wpdb->prefix . ($n === 'day' ? 'crazy_usage_day' : 'crazy_usage'); }
function cp_us_db_ok() { return get_option('cp_us_db_ver') === CP_US_DB_VER; }

add_action('init', function () {
	if (cp_us_db_ok() || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta('CREATE TABLE ' . cp_us_t('min') . " (
  uid int(11) NOT NULL,
  d date NOT NULL,
  m smallint(5) unsigned NOT NULL,
  act tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (uid,d,m),
  KEY d (d)
) $c;");
	dbDelta('CREATE TABLE ' . cp_us_t('day') . " (
  uid int(11) NOT NULL,
  d date NOT NULL,
  open_m smallint(5) unsigned NOT NULL DEFAULT 0,
  act_m smallint(5) unsigned NOT NULL DEFAULT 0,
  first_m smallint(5) unsigned NOT NULL DEFAULT 0,
  last_m smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (uid,d),
  KEY d (d)
) $c;");
	update_option('cp_us_db_ver', CP_US_DB_VER, true);
});

/* napi takarítás: 90 napnál régebbi percek, 2 évnél régebbi napi összesítők */
add_action('init', function () {
	if (cp_us_db_ok() && !wp_next_scheduled('cp_us_gc')) { wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', 'cp_us_gc'); }
});
add_action('cp_us_gc', function () {
	if (!cp_us_db_ok()) { return; }
	global $wpdb;
	$wpdb->query($wpdb->prepare('DELETE FROM ' . cp_us_t('min') . ' WHERE d < %s LIMIT 50000', wp_date('Y-m-d', time() - 90 * DAY_IN_SECONDS)));
	$wpdb->query($wpdb->prepare('DELETE FROM ' . cp_us_t('day') . ' WHERE d < %s LIMIT 50000', wp_date('Y-m-d', time() - 730 * DAY_IN_SECONDS)));
});

/* ------------------------------------------------------------ lekérdezés: csak admin */
add_action('wp_ajax_nopriv_cp_us_stats', 'cp_us_ajax_stats');
add_action('wp_ajax_cp_us_stats', 'cp_us_ajax_stats');
function cp_us_ajax_stats() {
	cp_el_auth();
	if (!function_exists('cp_panel_is_admin') || !cp_panel_is_admin()) { cp_el_err('A panelhasználat csak az adminoknak érhető el.', 403); }
	if (!cp_us_db_ok()) { cp_el_err('Az adatbázis még készül – töltsd újra az oldalt.', 503); }
	global $wpdb;
	$ok = function ($v) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : ''; };
	$to = $ok($_REQUEST['to'] ?? '') ?: wp_date('Y-m-d');
	$from = $ok($_REQUEST['from'] ?? '') ?: wp_date('Y-m-d', strtotime($to . ' 12:00:00') - 6 * DAY_IN_SECONDS);
	if ($from > $to) { list($from, $to) = array($to, $from); }
	if ((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS > 731) { $from = wp_date('Y-m-d', strtotime($to . ' 12:00:00') - 731 * DAY_IN_SECONDS); }
	/* fiókok: minden aktív személyes fiók (akinek nincs adata, 0-val szerepel) */
	$users = array();
	if (function_exists('cp_pu_data')) { foreach ((array) cp_pu_data()['list'] as $id => $u) { if (!empty($u['a'])) { $users[(int) $id] = array('id' => (int) $id, 'n' => (string) $u['n'], 'act' => 0, 'open' => 0, 'days' => 0, 'last' => ''); } } }
	$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT uid, COUNT(*) days, SUM(act_m) a, SUM(open_m) o FROM ' . cp_us_t('day') . ' WHERE d BETWEEN %s AND %s GROUP BY uid', $from, $to), ARRAY_A);
	foreach ($rows as $r) {
		$id = (int) $r['uid'];
		if (!isset($users[$id])) { $x = function_exists('cp_pu_get') ? cp_pu_get($id) : null; $users[$id] = array('id' => $id, 'n' => $x ? (string) $x['n'] : '#' . $id, 'act' => 0, 'open' => 0, 'days' => 0, 'last' => ''); }
		$users[$id]['act'] = (int) $r['a']; $users[$id]['open'] = (int) $r['o']; $users[$id]['days'] = (int) $r['days'];
	}
	/* utolsó használat (időszaktól függetlenül) és „most a panelben” (az utolsó 3 percben volt jel) */
	$now = new DateTime('now', wp_timezone()); $today = $now->format('Y-m-d'); $nm = (int) $now->format('G') * 60 + (int) $now->format('i');
	foreach ((array) $wpdb->get_results('SELECT t.uid, t.d, t.last_m FROM ' . cp_us_t('day') . ' t JOIN (SELECT uid, MAX(d) md FROM ' . cp_us_t('day') . ' GROUP BY uid) x ON x.uid = t.uid AND x.md = t.d', ARRAY_A) as $r) {
		$id = (int) $r['uid']; if (!isset($users[$id])) { continue; }
		$lm = (int) $r['last_m'];
		$users[$id]['last'] = $r['d'] . ' ' . sprintf('%02d:%02d', intdiv($lm, 60), $lm % 60);
		$users[$id]['now'] = $r['d'] === $today && $nm - $lm <= 3 ? 1 : 0;
	}
	$users = array_values($users);
	usort($users, function ($a, $b) { return $b['act'] - $a['act'] ?: $b['open'] - $a['open'] ?: strcmp($a['n'], $b['n']); });
	/* napi idővonal egy kiválasztott fióknak: 5 perces sávok (0 = nem volt, 1 = nyitva, 2 = aktív); a percek 90 napig vannak meg */
	$uid = (int) ($_REQUEST['uid'] ?? 0);
	if (!$uid && $users) { $uid = $users[0]['id']; }
	$days = array();
	if ($uid) {
		$tf = max($from, wp_date('Y-m-d', strtotime($to . ' 12:00:00') - 62 * DAY_IN_SECONDS));
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT d, open_m, act_m, first_m, last_m FROM ' . cp_us_t('day') . ' WHERE uid = %d AND d BETWEEN %s AND %s ORDER BY d DESC', $uid, $tf, $to), ARRAY_A) as $r) {
			$days[$r['d']] = array('d' => $r['d'], 'open' => (int) $r['open_m'], 'act' => (int) $r['act_m'], 'first' => (int) $r['first_m'], 'last' => (int) $r['last_m'], 'bar' => '');
		}
		if ($days) {
			$b = array();
			foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT d, FLOOR(m / 5) k, MAX(act) a FROM ' . cp_us_t('min') . ' WHERE uid = %d AND d BETWEEN %s AND %s GROUP BY d, k', $uid, $tf, $to), ARRAY_A) as $r) { $b[$r['d']][(int) $r['k']] = (int) $r['a'] ? '2' : '1'; }
			foreach ($days as $d => &$x) { if (isset($b[$d])) { $s = str_repeat('0', 288); foreach ($b[$d] as $k => $v) { if ($k >= 0 && $k < 288) { $s[$k] = $v; } } $x['bar'] = $s; } }
			unset($x);
		}
	}
	wp_send_json_success(array('from' => $from, 'to' => $to, 'users' => $users, 'uid' => $uid, 'days' => array_values($days), 'detail_from' => wp_date('Y-m-d', time() - 90 * DAY_IN_SECONDS)));
}
