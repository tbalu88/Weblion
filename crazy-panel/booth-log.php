<?php
/* =====================================================================
 * Crazy Booth – eseménynapló és riasztás (v2.9.256)
 *  – a gépek percenkénti jelentkezéséből (/booth/beat) rendezvényenkénti napló: indulás, leállás, hiba, kamera ki/be,
 *    5 percenként összesítő (nyomat, vendég, sor, papír). Ebből jön a munkaidő, az idővonal és a szervezői nézet.
 *  – riasztás: ha egy rendezvényen már futott Crazy Booth (bármikor korábban, pl. teszt), és a kezdés előtt 30 / 15 perccel
 *    egyetlen gép sem jelentkezik ezzel a rendezvénnyel → panel-értesítés a beállított kollégáknak (alapból: Ákos + adminok).
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }

define('CP_BLOG_DB_VER', '1');
define('CP_BLOG_GAP', 600); /* 10 perc csend = a gép leállt */
function cp_blog_t() { global $wpdb; return $wpdb->prefix . 'cp_booth_log'; }
function cp_blog_db_ok() { return get_option('cp_blog_db_ver') === CP_BLOG_DB_VER; }

add_action('init', function () {
	if (cp_blog_db_ok() || wp_doing_ajax()) { return; }
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	global $wpdb;
	dbDelta('CREATE TABLE ' . cp_blog_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  deal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  dev varchar(40) NOT NULL DEFAULT '',
  t datetime NOT NULL,
  kind varchar(12) NOT NULL DEFAULT '',
  info varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY deal (deal_id, t),
  KEY tk (t)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_blog_db_ver', CP_BLOG_DB_VER, false);
	/* a korábban már nyomtatott rendezvények „futott már Crazy Booth” jelölése */
	$p = get_option('cp_booth_prints', array()); $seen = get_option('cp_booth_seen', null);
	if (is_array($p) && !is_array($seen)) { $seen = array(); foreach (array_keys($p) as $k) { $seen[(string) $k] = time(); } update_option('cp_booth_seen', $seen, false); }
});

function cp_blog_add($deal, $dev, $ts, $kind, $info = '') {
	if (!cp_blog_db_ok()) { return; }
	global $wpdb;
	$wpdb->insert(cp_blog_t(), array('deal_id' => (int) $deal, 'dev' => mb_substr((string) $dev, 0, 40), 't' => gmdate('Y-m-d H:i:s', (int) $ts), 'kind' => $kind, 'info' => mb_substr((string) $info, 0, 255)));
}
/* futó munkamenetek gépenként: dev => [deal, s (indulás), l (utolsó jelentkezés)] */
function cp_blog_open() { $o = get_option('cp_blog_open', array()); return is_array($o) ? $o : array(); }
function cp_blog_close(&$open, $dev) {
	if (empty($open[$dev])) { return; }
	$x = $open[$dev];
	if ((int) $x['deal']) { cp_blog_add($x['deal'], $dev, $x['l'], 'stop', (string) max(0, (int) $x['l'] - (int) $x['s'])); }
	unset($open[$dev]);
}

/* a beat végpont hívja: $prev = az előző jelentés (vagy null), $b = a mostani */
function cp_blog_beat($dev, $prev, $b) {
	$deal = (int) $b['deal']; $t = (int) $b['t'];
	$open = cp_blog_open();
	if ($deal) {
		$seen = get_option('cp_booth_seen', array()); if (!is_array($seen)) { $seen = array(); }
		if (empty($seen[(string) $deal])) { $seen[(string) $deal] = $t; update_option('cp_booth_seen', $seen, false); }
	}
	$cur = $open[$dev] ?? null;
	if ($cur && ((int) $cur['deal'] !== $deal || $t - (int) $cur['l'] > CP_BLOG_GAP)) { cp_blog_close($open, $dev); $cur = null; }
	if (!$cur) {
		$open[$dev] = array('deal' => $deal, 's' => $t, 'l' => $t);
		if ($deal) { cp_blog_add($deal, $dev, $t, 'start', (string) $b['ver']); }
	} else { $open[$dev]['l'] = $t; }
	update_option('cp_blog_open', $open, false);
	if (!$deal) { return; }
	if (!empty($b['err_at']) && (!$prev || (int) ($prev['err_at'] ?? 0) !== (int) $b['err_at'])) { cp_blog_add($deal, $dev, (int) $b['err_at'], 'err', (string) $b['err']); }
	if ($prev && (int) ($prev['deal'] ?? 0) === $deal && $t - (int) $prev['t'] <= CP_BLOG_GAP) {
		if (($prev['cam'] ?? null) === true && $b['cam'] === false) { cp_blog_add($deal, $dev, $t, 'cam_off'); }
		if (($prev['cam'] ?? null) === false && $b['cam'] === true) { cp_blog_add($deal, $dev, $t, 'cam_on'); }
	}
	/* 5 percenként összesítő */
	$ck = 'cp_bl_s_' . md5($dev);
	if (!get_transient($ck)) {
		set_transient($ck, 1, 5 * MINUTE_IN_SECONDS - 10);
		$paper = array_filter((array) $b['paper'], function ($v) { return $v !== null; });
		cp_blog_add($deal, $dev, $t, 'sum', wp_json_encode(array('p' => (int) $b['prints'], 'g' => (int) $b['guests'], 'q' => (int) $b['queue'], 'pa' => $paper ? min($paper) : null, 'net' => (int) !empty($b['net']))));
	}
}

/* percenkénti feladat: leállás észlelése, riasztás (5 percenként), régi sorok törlése (naponta) */
add_action('init', function () {
	if (!wp_next_scheduled('cp_blog_tick') && cp_blog_db_ok()) { wp_schedule_event(time() + 90, 'cp_minute', 'cp_blog_tick'); }
});
add_action('cp_blog_tick', 'cp_blog_tick');
function cp_blog_tick() {
	$open = cp_blog_open(); $ch = false;
	foreach (array_keys($open) as $dev) { if (time() - (int) $open[$dev]['l'] > CP_BLOG_GAP) { cp_blog_close($open, $dev); $ch = true; } }
	if ($ch) { update_option('cp_blog_open', $open, false); }
	if (!get_transient('cp_bl_alert_chk')) { set_transient('cp_bl_alert_chk', 1, 5 * MINUTE_IN_SECONDS - 20); cp_blog_alerts(); }
	if (!get_transient('cp_bl_gc') && cp_blog_db_ok()) {
		set_transient('cp_bl_gc', 1, DAY_IN_SECONDS);
		global $wpdb; $wpdb->query($wpdb->prepare('DELETE FROM ' . cp_blog_t() . ' WHERE t < %s', gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS)));
	}
}

/* kik kapják a riasztást: a Crazy Booth gépek oldalon állítható; alapból Ákos + az adminok */
function cp_blog_alert_to() {
	$v = get_option('cp_booth_alert_to', null);
	if (is_array($v)) { return array_map('intval', $v); }
	$out = array();
	if (function_exists('cp_pu_data')) { foreach (cp_pu_data()['list'] as $uid => $u) { if (!empty($u['a']) && (($u['r'] ?? '') === 'admin' || preg_match('/ákos/iu', (string) $u['n']))) { $out[] = (int) $uid; } } }
	return $out;
}
/* a mai lefoglalt rendezvények kezdési idővel (10 percig tárolva) */
function cp_blog_today() {
	$day = wp_date('Y-m-d'); $ck = 'cp_bl_today_' . $day; $c = get_transient($ck);
	if (is_array($c)) { return $c; }
	if (!function_exists('cp_sm_fetch_range') || !function_exists('cp_panel_normalize')) { return array(); }
	$info = null; $rows = cp_sm_fetch_range($day, $day, $info);
	if (is_wp_error($rows)) { return array(); }
	$out = array();
	foreach (cp_panel_normalize($rows) as $e) {
		if ($e['date'] !== $day || $e['state'] !== 'booked') { continue; }
		$min = null;
		if (preg_match('/^(\d{1,2}):(\d{2})/', (string) ($e['time'] ?? ''), $m)) { $min = (int) $m[1] * 60 + (int) $m[2]; }
		elseif (is_array($e['win'] ?? null)) { $min = (int) $e['win'][0]; }
		if ($min === null) { continue; }
		$deal = function_exists('cp_mday_base') ? (int) cp_mday_base($e['id']) : (int) $e['id'];
		$out[] = array('deal' => $deal, 'title' => (string) $e['title'], 'min' => $min);
	}
	set_transient($ck, $out, 10 * MINUTE_IN_SECONDS);
	return $out;
}
function cp_blog_alerts() {
	$seen = get_option('cp_booth_seen', array()); if (!is_array($seen) || !$seen || !function_exists('cp_notify')) { return; }
	$events = cp_blog_today(); if (!$events) { return; }
	$now = (int) wp_date('G') * 60 + (int) wp_date('i'); $day = wp_date('Y-m-d');
	$live = array(); foreach (cp_blog_open() as $x) { if (time() - (int) $x['l'] <= 15 * MINUTE_IN_SECONDS) { $live[(int) $x['deal']] = 1; } }
	$sent = get_option('cp_bl_alerted', array()); if (!is_array($sent)) { $sent = array(); }
	foreach ($sent as $k => $v) { if (strpos($k, $day) !== 0) { unset($sent[$k]); } }
	$ch = false;
	foreach ($events as $e) {
		if (empty($seen[(string) $e['deal']]) || isset($live[$e['deal']])) { continue; }
		$left = $e['min'] - $now;
		$stage = $left <= 30 && $left > 15 ? 1 : ($left <= 15 && $left > -30 ? 2 : 0);
		$key = $day . ':' . $e['deal'];
		if (!$stage || (int) ($sent[$key] ?? 0) >= $stage) { continue; }
		$sent[$key] = $stage; $ch = true;
		$hh = sprintf('%02d:%02d', intdiv($e['min'], 60), $e['min'] % 60);
		$txt = ($stage === 1 ? 'a szelfigép még nem jelentkezett – ' : 'MÉG MINDIG nincs jelentkező szelfigép – ') . mb_substr($e['title'], 0, 90) . ' (kezdés ' . $hh . ')';
		foreach (cp_blog_alert_to() as $uid) { cp_notify($uid, 'booth_miss', $txt, 'deals:' . $e['deal'], 'Crazy Booth'); }
	}
	if ($ch) { update_option('cp_bl_alerted', $sent, false); }
}

/* a deal naplója (idővonalhoz, munkaidőhöz) */
function cp_blog_rows($deal) {
	if (!cp_blog_db_ok()) { return array(); }
	global $wpdb;
	return (array) $wpdb->get_results($wpdb->prepare('SELECT dev, t, kind, info FROM ' . cp_blog_t() . ' WHERE deal_id = %d ORDER BY t, id LIMIT 3000', (int) $deal), ARRAY_A);
}

/* beállítás a „Crazy Booth gépek” oldalon: kik kapják a riasztást */
function cp_blog_admin() {
	if (!current_user_can('manage_options') || !function_exists('cp_pu_data')) { return; }
	if (!empty($_POST['cp_bla_nonce']) && wp_verify_nonce($_POST['cp_bla_nonce'], 'cp_bla')) {
		update_option('cp_booth_alert_to', array_values(array_map('intval', (array) ($_POST['to'] ?? array()))), false);
		echo '<div class="notice notice-success"><p>Mentve.</p></div>';
	}
	$to = cp_blog_alert_to();
	echo '<h2>Riasztás, ha a gép nem jelentkezik</h2><p>Ha egy rendezvényen már futott Crazy Booth (akár egy korábbi teszten), és a kezdés előtt 30, majd 15 perccel egyetlen gép sem jelentkezik vele, panel-értesítést kapnak:</p><form method="post">';
	wp_nonce_field('cp_bla', 'cp_bla_nonce');
	foreach (cp_pu_data()['list'] as $uid => $u) { if (empty($u['a'])) { continue; } echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="to[]" value="' . (int) $uid . '"' . (in_array((int) $uid, $to, true) ? ' checked' : '') . '> ' . esc_html($u['n']) . '</label>'; }
	echo '<p class="description">Mindenki a saját Beállítások → Értesítések oldalán is kikapcsolhatja (csengő / telefon / Pumble külön).</p><p><button class="button button-primary">Mentés</button></p></form>';
}

/* v2.9.257: a deal idővonala (Szelfigép kártya → Idővonal): munkamenetek gépenként, események, 5 perces összesítők, fotók 15 percenként */
add_action('wp_ajax_nopriv_cp_booth_log', 'cp_booth_ajax_log'); add_action('wp_ajax_cp_booth_log', 'cp_booth_ajax_log');
function cp_booth_ajax_log() {
	cp_crm_ajax_guard(array('deals'));
	$deal = (int) ($_POST['deal'] ?? 0); if (!$deal) { cp_el_err('Hiányzó deal.', 400); }
	wp_send_json_success(cp_blog_timeline($deal));
}
function cp_blog_timeline($deal) {
	$devs = function_exists('cp_booth_devs') ? cp_booth_devs() : array();
	$nm = function ($d) use ($devs) { return isset($devs[$d]['name']) ? (string) $devs[$d]['name'] : 'ismeretlen gép'; };
	$loc = function ($t) { return get_date_from_gmt($t, 'Y-m-d H:i'); };
	$ev = array(); $ses = array(); $sum = array(); $st = array();
	foreach (cp_blog_rows($deal) as $r) {
		$d = $r['dev']; $tl = $loc($r['t']);
		if ($r['kind'] === 'start') { $st[$d] = $tl; $ev[] = array($tl, $nm($d), 'start', $r['info'] !== '' ? 'program ' . $r['info'] : ''); }
		elseif ($r['kind'] === 'stop') { $ses[] = array($nm($d), $st[$d] ?? '', $tl, (int) $r['info']); unset($st[$d]); $ev[] = array($tl, $nm($d), 'stop', ''); }
		elseif ($r['kind'] === 'sum') { $j = json_decode($r['info'], true); if (is_array($j)) { $sum[] = array($tl, $nm($d), (int) ($j['p'] ?? 0), $j['pa'] ?? null, (int) ($j['q'] ?? 0), (int) ($j['net'] ?? 1)); } }
		else { $ev[] = array($tl, $nm($d), $r['kind'], $r['info']); }
	}
	/* a még futó gépek (nincs leállás) */
	foreach (cp_blog_open() as $d => $x) { if ((int) $x['deal'] === $deal && isset($st[$d])) { $ses[] = array($nm($d), $st[$d], $loc(gmdate('Y-m-d H:i:s', (int) $x['l'])), (int) $x['l'] - (int) $x['s'], 1); } }
	/* fotók 15 percenként a galériából */
	$q = array();
	if (function_exists('cp_booth_gal_has') && cp_booth_gal_has($deal)) {
		$g = cp_booth_gal_api('GET', cp_booth_gal_tok($deal), null, 8);
		if (!is_wp_error($g)) { foreach ((array) ($g['photos'] ?? array()) as $p) { $l = get_date_from_gmt((string) $p['created_at'], 'Y-m-d H:i'); $k = substr($l, 0, 14) . str_pad((string) (intdiv((int) substr($l, 14, 2), 15) * 15), 2, '0', STR_PAD_LEFT); $q[$k] = ($q[$k] ?? 0) + 1; } ksort($q); }
	}
	return array('events' => $ev, 'sessions' => $ses, 'sums' => $sum, 'q15' => $q);
}
