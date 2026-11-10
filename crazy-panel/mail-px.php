<?php
/* =====================================================================
 * Megnyitás-követés (v2.9.109) – a panelből küldött levélbe egy láthatatlan 1×1-es kép kerül a saját domainről
 * (https://eventcrazy.hu/?cp_o=<jel>). Amikor az ügyfél levelezője betölti, a panel feljegyzi: mikor nyitották meg először /
 * utoljára, hányszor. Csak időpontot és darabszámot tárol – IP-címet, eszközt nem. 90 nap után törlődik.
 *  – Az Apple Mail (Mail Privacy Protection) a kézbesítéskor magától letölti a képeket, a vírusszűrők is: az Apple-szerverről
 *    (17.x.x.x) jövő és a küldés utáni 60 mp-en belüli letöltés „valószínűleg automatikus” – külön számoljuk.
 *  – A panelben megjelenített levelekből (a saját elküldött levél, az ügyfél válaszában idézett levelünk) a kép kiszűrve – a
 *    kollégák nézése nem számít megnyitásnak. Az Elküldött mappába a kép nélküli változat kerül.
 *  – Kikapcsolható: Beállítások → Levelezés → Megnyitás-követés (cp_px_on).
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }

define('CP_PX_DB_VER', '2'); /* 2: eseménynapló (crazy_mail_px_ev) – megnyitásonként egy sor (idő, automatikus-e), mint a Salesmate „Tracking” listája */
function cp_px_ev_t() { global $wpdb; return $wpdb->prefix . 'crazy_mail_px_ev'; }
function cp_px_t() { global $wpdb; return $wpdb->prefix . 'crazy_mail_px'; }
function cp_px_on() { return get_option('cp_px_on', '1') === '1' && get_option('cp_px_db_ver') === CP_PX_DB_VER; }

add_action('init', function () {
	if (get_option('cp_px_db_ver') === CP_PX_DB_VER || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_px_t() . " (
  tok char(24) NOT NULL,
  msg_id bigint(20) unsigned NOT NULL DEFAULT 0,
  thread_id bigint(20) unsigned NOT NULL DEFAULT 0,
  deal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sent_at datetime NOT NULL,
  n int(11) NOT NULL DEFAULT 0,
  n_auto int(11) NOT NULL DEFAULT 0,
  first_at datetime NULL,
  last_at datetime NULL,
  auto_at datetime NULL,
  PRIMARY KEY  (tok),
  KEY msg_id (msg_id),
  KEY deal_id (deal_id)
) " . $wpdb->get_charset_collate() . ';');
	dbDelta('CREATE TABLE ' . cp_px_ev_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tok char(24) NOT NULL,
  at datetime NOT NULL,
  auto tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY tok (tok)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_px_db_ver', CP_PX_DB_VER, true);
});

/* küldéskor: új jel + a kép HTML-je (a levél törzsének végére) */
function cp_px_new($thread, $deal) {
	if (!cp_px_on()) { return null; }
	global $wpdb;
	$tok = substr(bin2hex(random_bytes(12)), 0, 24);
	$wpdb->insert(cp_px_t(), array('tok' => $tok, 'thread_id' => (int) $thread, 'deal_id' => (int) $deal, 'sent_at' => gmdate('Y-m-d H:i:s')));
	return array('tok' => $tok, 'img' => '<img src="' . esc_url(home_url('/?cp_o=' . $tok)) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;opacity:0" />');
}
function cp_px_bind($tok, $msg_id, $thread, $deal) {
	global $wpdb;
	$wpdb->update(cp_px_t(), array('msg_id' => (int) $msg_id, 'thread_id' => (int) $thread, 'deal_id' => (int) $deal), array('tok' => $tok));
}
/* a panelben megjelenített HTML-ből a saját képünk kiszűrése */
function cp_px_strip($html) { return preg_replace('#<img\b[^>]*[?&](?:amp;)?cp_o=[a-f0-9]{24}[^>]*>#i', '', (string) $html); }

/* a kép kiszolgálása – a lehető legkorábban, gyorsan (a többi bővítmény betöltése nélkül is mehetne, de így egyszerűbb) */
add_action('plugins_loaded', function () {
	if (!isset($_GET['cp_o'])) { return; }
	$tok = preg_replace('/[^a-f0-9]/', '', (string) $_GET['cp_o']);
	if (strlen($tok) === 24 && get_option('cp_px_db_ver') === CP_PX_DB_VER) {
		global $wpdb;
		$r = $wpdb->get_row($wpdb->prepare('SELECT sent_at, last_at, auto_at FROM ' . cp_px_t() . ' WHERE tok = %s', $tok), ARRAY_A);
		if ($r) {
			$now = time();
			$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
			$auto = strpos($ip, '17.') === 0 || $now - strtotime($r['sent_at'] . ' UTC') < 60;
			$nowS = gmdate('Y-m-d H:i:s', $now);
			/* ugyanaz a megnyitás többszöri betöltése (10 mp-en belül) egynek számít */
			$recent = $auto ? ($r['auto_at'] && $now - strtotime($r['auto_at'] . ' UTC') < 10) : ($r['last_at'] && $now - strtotime($r['last_at'] . ' UTC') < 10);
			if (!$recent) {
				if ($auto) { $wpdb->query($wpdb->prepare('UPDATE ' . cp_px_t() . ' SET n_auto = n_auto + 1, auto_at = %s WHERE tok = %s', $nowS, $tok)); }
				else { $wpdb->query($wpdb->prepare('UPDATE ' . cp_px_t() . ' SET n = n + 1, first_at = COALESCE(first_at, %s), last_at = %s WHERE tok = %s', $nowS, $nowS, $tok)); }
				/* v2.9.113: eseménynapló (levelenként legfeljebb 100 sor) */
				if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_px_ev_t() . ' WHERE tok = %s', $tok)) < 100) { $wpdb->insert(cp_px_ev_t(), array('tok' => $tok, 'at' => $nowS, 'auto' => $auto ? 1 : 0)); }
			}
		}
	}
	nocache_headers();
	header('Content-Type: image/gif');
	header('X-Robots-Tag: noindex');
	echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
	exit;
}, 0);

/* a levélszál kimenő leveleihez: megnyitás-adat (üzenet-azonosító => adat) */
function cp_px_for_msgs($ids) {
	$ids = array_values(array_filter(array_map('intval', (array) $ids)));
	if (!$ids || get_option('cp_px_db_ver') !== CP_PX_DB_VER) { return array(); }
	global $wpdb;
	$out = array();
	$tk = array();
	foreach ((array) $wpdb->get_results('SELECT tok, msg_id, n, n_auto, first_at, last_at, auto_at FROM ' . cp_px_t() . ' WHERE msg_id IN (' . implode(',', $ids) . ')', ARRAY_A) as $r) {
		$out[(int) $r['msg_id']] = cp_px_out($r) + array('ev' => array());
		$tk[$r['tok']] = (int) $r['msg_id'];
	}
	/* v2.9.113: a megnyitások listája (legújabb elöl, levelenként legfeljebb 30) */
	if ($tk) {
		$in = implode(',', array_map(function ($t) use ($wpdb) { return $wpdb->prepare('%s', $t); }, array_keys($tk)));
		foreach ((array) $wpdb->get_results('SELECT tok, at, auto FROM ' . cp_px_ev_t() . " WHERE tok IN ($in) ORDER BY id DESC LIMIT 600", ARRAY_A) as $e) {
			$mid = $tk[$e['tok']] ?? 0;
			if ($mid && count($out[$mid]['ev']) < 30) { $out[$mid]['ev'][] = array('at' => function_exists('cp_mail_local') ? cp_mail_local($e['at']) : $e['at'], 'a' => (int) $e['auto']); }
		}
	}
	return $out;
}
function cp_px_out($r) {
	$loc = function ($d) { return $d ? (function_exists('cp_mail_local') ? cp_mail_local($d) : $d) : ''; };
	return array('n' => (int) $r['n'], 'na' => (int) $r['n_auto'], 'f' => $loc($r['first_at']), 'l' => $loc($r['last_at']), 'a' => $loc($r['auto_at']));
}
/* a deal idővonalához: a deal leveleinek első valódi megnyitása */
function cp_px_for_deal($deal) {
	if (!$deal || get_option('cp_px_db_ver') !== CP_PX_DB_VER || !function_exists('cp_mail_t')) { return array(); }
	global $wpdb;
	$out = array();
	foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT p.thread_id, p.n, p.n_auto, p.first_at, p.last_at, p.auto_at, m.subject FROM ' . cp_px_t() . ' p JOIN ' . cp_mail_t('messages') . ' m ON m.id = p.msg_id WHERE p.deal_id = %d AND p.n > 0 ORDER BY p.first_at DESC LIMIT 20', (int) $deal), ARRAY_A) as $r) {
		$out[] = array('th' => (int) $r['thread_id'], 's' => (string) $r['subject'], 'at' => function_exists('cp_crm_local') ? cp_crm_local($r['first_at']) : $r['first_at']) + cp_px_out($r);
	}
	return $out;
}

/* 90 napnál régebbi adatok törlése (naponta) */
add_action('init', function () { if (get_option('cp_px_db_ver') === CP_PX_DB_VER && !wp_next_scheduled('cp_px_gc')) { wp_schedule_event(time() + 3 * HOUR_IN_SECONDS, 'daily', 'cp_px_gc'); } });
add_action('cp_px_gc', function () {
	global $wpdb;
	$wpdb->query($wpdb->prepare('DELETE FROM ' . cp_px_t() . ' WHERE sent_at < %s LIMIT 20000', gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS)));
	$wpdb->query($wpdb->prepare('DELETE FROM ' . cp_px_ev_t() . ' WHERE at < %s LIMIT 50000', gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS)));
});
