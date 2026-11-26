<?php
/* =====================================================================
 * v2.9.384: Naptárbejegyzések változatmentése és visszaállítása
 * - 5 percenként (cp_crm_tick, a naptár-szinkron előtt) a panel végigolvassa a bejegyzéseket (a múlt héttől 400 napig).
 *   Ha egy bejegyzés címe (a [Ceruzás] jelölés nélkül), leírása, ideje vagy helyszíne eltér az utolsó mentéstől, új változat
 *   kerül a wp_crazy_gcal_ver táblába (forrás: kézi módosítás vagy a panel írása). A törölt bejegyzés utolsó állapota is megmarad.
 * - A panel minden saját írásánál (cp_gcal_req PATCH/POST) és minden felülírás előtt (cp_gcal_snap) azonnal ment.
 * - Bejegyzésenként az utolsó 30 változat marad, 180 napig (a legutolsó változat mindig megmarad).
 * - Visszaállítás bejegyzésenként vagy tömegesen (minden bejegyzés egy adott időpont előtti állapotára). Jogosultság: admin
 *   vagy a „Naptár visszaállítása” (gnvissza) engedély. A visszaállított bejegyzést a panel kézi módosításnak tekinti (nem írja felül).
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }

define('CP_GV_DB_VER', '1');
define('CP_GV_KEEP', 30);
define('CP_GV_DAYS', 180);
function cp_gv_t() { global $wpdb; return $wpdb->prefix . 'crazy_gcal_ver'; }
add_action('init', function () {
	if (get_option('cp_gv_db_ver') === CP_GV_DB_VER) { return; }
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	global $wpdb;
	dbDelta('CREATE TABLE ' . cp_gv_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  eid varchar(255) NOT NULL DEFAULT '',
  at int(10) unsigned NOT NULL DEFAULT 0,
  gup int(10) unsigned NOT NULL DEFAULT 0,
  src varchar(40) NOT NULL DEFAULT '',
  who varchar(120) NOT NULL DEFAULT '',
  t text NULL,
  raw mediumtext NULL,
  s varchar(20) NOT NULL DEFAULT '',
  e varchar(20) NOT NULL DEFAULT '',
  ad tinyint(1) NOT NULL DEFAULT 0,
  loc text NULL,
  h char(32) NOT NULL DEFAULT '',
  del tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY eid (eid(64), id),
  KEY at (at)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_gv_db_ver', CP_GV_DB_VER, false);
}, 6);
function cp_gv_ok() { global $wpdb; return get_option('cp_gv_db_ver') === CP_GV_DB_VER; }
function cp_gv_can() { return (function_exists('cp_panel_is_admin') && cp_panel_is_admin()) || (function_exists('cp_panel_can') && cp_panel_can('gnvissza')); }

function cp_gv_hash($e) {
	return md5(cp_gcal_unpen((string) ($e['t'] ?? '')) . "\n" . (string) ($e['raw'] ?? '') . "\n" . ($e['s'] ?? '') . '|' . ($e['e'] ?? '') . '|' . (!empty($e['ad']) ? 1 : 0) . '|' . trim((string) ($e['loc'] ?? '')));
}
/* a Google API válaszából ugyanaz a forma, mint a cp_gcal_events() bejegyzései */
function cp_gv_from_api($r) {
	if (!is_array($r) || empty($r['id'])) { return null; }
	$ad = empty($r['start']['dateTime']);
	$s = cp_gcal_local($r['start'] ?? array()); $en = cp_gcal_local($r['end'] ?? array());
	if ($ad && $en !== '') { $en = gmdate('Y-m-d', strtotime($en . ' 00:00:00 UTC') - DAY_IN_SECONDS); }
	return array('id' => (string) $r['id'], 't' => (string) ($r['summary'] ?? ''), 's' => $s, 'e' => $en, 'ad' => $ad, 'loc' => (string) ($r['location'] ?? ''), 'raw' => (string) ($r['description'] ?? ''),
		'upd' => (string) ($r['updated'] ?? ''), 'cancelled' => ($r['status'] ?? '') === 'cancelled');
}
function cp_gv_last($eid) {
	global $wpdb;
	return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_gv_t() . ' WHERE eid = %s ORDER BY id DESC LIMIT 1', $eid), ARRAY_A);
}
/* új változat, ha a tartalom eltér az utolsótól ($last: már lekérdezett utolsó sor, hogy a körben ne kelljen újra) */
function cp_gv_add($e, $src, $who = '', $del = false, $last = false) {
	if (!cp_gv_ok() || !is_array($e) || empty($e['id'])) { return false; }
	global $wpdb;
	$eid = (string) $e['id']; $h = cp_gv_hash($e);
	if ($last === false) { $last = cp_gv_last($eid); }
	if ($last && $last['h'] === $h && (int) $last['del'] === ($del ? 1 : 0)) { return false; }
	$gup = !empty($e['upd']) ? (int) strtotime((string) $e['upd']) : 0;
	$wpdb->insert(cp_gv_t(), array('eid' => $eid, 'at' => time(), 'gup' => $gup, 'src' => mb_substr($src, 0, 40), 'who' => mb_substr((string) $who, 0, 120),
		't' => (string) ($e['t'] ?? ''), 'raw' => (string) ($e['raw'] ?? ''), 's' => (string) ($e['s'] ?? ''), 'e' => (string) ($e['e'] ?? ''), 'ad' => !empty($e['ad']) ? 1 : 0,
		'loc' => (string) ($e['loc'] ?? ''), 'h' => $h, 'del' => $del ? 1 : 0));
	$n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_gv_t() . ' WHERE eid = %s', $eid));
	if ($n > CP_GV_KEEP) {
		$cut = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_gv_t() . ' WHERE eid = %s ORDER BY id DESC LIMIT 1 OFFSET ' . (CP_GV_KEEP - 1), $eid));
		if ($cut) { $wpdb->query($wpdb->prepare('DELETE FROM ' . cp_gv_t() . ' WHERE eid = %s AND id < %d', $eid, $cut)); }
	}
	return true;
}
/* a panel saját írása: ki (bejelentkezett kolléga) vagy az automatikus szinkron */
function cp_gv_who() { return (function_exists('wp_doing_cron') && wp_doing_cron()) || !function_exists('cp_panel_who') ? 'automatikus' : cp_panel_who(); }
function cp_gv_after_write($method, $path, $r) {
	if (!cp_gv_ok() || is_wp_error($r) || strpos($path, '/events') !== 0) { return; }
	if ($method === 'DELETE') {
		$eid = rawurldecode(substr($path, 8)); $l = cp_gv_last($eid);
		if ($l && !(int) $l['del']) { $l['id'] = $eid; cp_gv_add($l, 'panel törölte', cp_gv_who(), true, $l); }
		return;
	}
	if ($method !== 'PATCH' && $method !== 'POST' && $method !== 'PUT') { return; }
	$e = cp_gv_from_api($r); if (!$e) { return; }
	cp_gv_add($e, $GLOBALS['cp_gv_src'] ?? 'panel írta', cp_gv_who());
}

/* 5 percenként: a változások összegyűjtése */
function cp_gv_scan() {
	if (!cp_gv_ok() || !function_exists('cp_gcal_ok') || !cp_gcal_ok() || get_transient('cp_gv_lock')) { return; }
	set_transient('cp_gv_lock', 1, 240);
	global $wpdb;
	$from = wp_date('Y-m-d', time() - 7 * DAY_IN_SECONDS); $to = wp_date('Y-m-d', time() + 400 * DAY_IN_SECONDS);
	$ev = cp_gcal_events($from, $to, true);
	if (is_wp_error($ev)) { delete_transient('cp_gv_lock'); return; }
	$last = array();
	foreach ((array) $wpdb->get_results('SELECT v.* FROM ' . cp_gv_t() . ' v JOIN (SELECT MAX(id) m FROM ' . cp_gv_t() . ' GROUP BY eid) x ON v.id = x.m', ARRAY_A) as $r) { $last[$r['eid']] = $r; }
	$seen = array();
	foreach ($ev as $e) {
		$eid = (string) $e['id']; $seen[$eid] = 1;
		$l = $last[$eid] ?? null;
		if ($l && $l['h'] === cp_gv_hash($e) && !(int) $l['del']) { continue; }
		$src = !$l ? 'első mentés' : ((function_exists('cp_gcal_wts_one') && cp_gcal_wts_one($eid) === cp_gcal_sig_ev($e)) ? 'panel írta' : 'kézi módosítás');
		cp_gv_add($e, $src, $src === 'kézi módosítás' ? 'Google Naptár' : '', false, $l ?: null);
	}
	/* eltűnt bejegyzések (az időszakban voltak, most nincsenek): törölték vagy kikerültek az időszakból */
	$chk = 0;
	foreach ($last as $eid => $l) {
		if (isset($seen[$eid]) || (int) $l['del'] || substr((string) $l['s'], 0, 10) < $from || substr((string) $l['s'], 0, 10) > $to) { continue; }
		if (++$chk > 25) { break; }
		$r = cp_gcal_req('GET', '/events/' . rawurlencode($eid));
		$x = is_wp_error($r) ? null : cp_gv_from_api($r);
		if ($x && empty($x['cancelled'])) { cp_gv_add($x, 'kézi módosítás', 'Google Naptár', false, $l); continue; }
		if (is_wp_error($r) && strpos($r->get_error_message(), 'nem található') === false && strpos($r->get_error_message(), '(410)') === false) { continue; } /* hálózati hiba: majd legközelebb */
		$l['id'] = $eid; cp_gv_add($l, 'törölve a naptárból', 'Google Naptár', true, $l);
	}
	/* naponta egyszer: 180 napnál régebbi változatok törlése (a bejegyzés legutolsó változata marad) */
	if ((int) get_option('cp_gv_prune', 0) < time() - DAY_IN_SECONDS) {
		update_option('cp_gv_prune', time(), false);
		$wpdb->query($wpdb->prepare('DELETE v FROM ' . cp_gv_t() . ' v JOIN (SELECT eid, MAX(id) m FROM ' . cp_gv_t() . ' GROUP BY eid) x ON v.eid = x.eid WHERE v.id < x.m AND v.at < %d', time() - CP_GV_DAYS * DAY_IN_SECONDS));
	}
	delete_transient('cp_gv_lock');
}
add_action('cp_crm_tick', 'cp_gv_scan', 31);

/* ------------------------------------------------------------ visszaállítás */
function cp_gv_body($v) {
	$tz = wp_timezone();
	$b = array('summary' => (string) $v['t'], 'description' => (string) $v['raw'], 'location' => (string) $v['loc']);
	if ((int) $v['ad'] || strlen((string) $v['s']) < 16) {
		$sd = substr((string) $v['s'], 0, 10); $ed = substr((string) ($v['e'] ?: $v['s']), 0, 10);
		$b['start'] = array('date' => $sd, 'dateTime' => null); $b['end'] = array('date' => gmdate('Y-m-d', strtotime($ed . ' 00:00:00 UTC') + DAY_IN_SECONDS), 'dateTime' => null);
	} else {
		$s = new DateTime($v['s'], $tz); $e = new DateTime($v['e'] ?: $v['s'], $tz);
		$b['start'] = array('dateTime' => $s->format(DATE_RFC3339), 'timeZone' => $tz->getName(), 'date' => null); $b['end'] = array('dateTime' => $e->format(DATE_RFC3339), 'timeZone' => $tz->getName(), 'date' => null);
	}
	return $b;
}
/* egy változat visszaírása; a mostani állapot előtte mentődik. Visszaad: '' (siker) vagy hibaüzenet */
function cp_gv_restore_one($vid, $why) {
	global $wpdb;
	$v = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_gv_t() . ' WHERE id = %d', $vid), ARRAY_A);
	if (!$v || (int) $v['del']) { return 'Ez a változat nem állítható vissza.'; }
	$eid = (string) $v['eid'];
	$cur = cp_gcal_req('GET', '/events/' . rawurlencode($eid));
	$c = is_wp_error($cur) ? null : cp_gv_from_api($cur);
	$b = cp_gv_body($v);
	$GLOBALS['cp_gv_src'] = $why;
	if ($c && empty($c['cancelled'])) {
		cp_gv_add($c, 'visszaállítás előtt', cp_gv_who());
		/* a [Ceruzás] jelölés a mostani állapot szerint marad (azt a deal szakasza vezérli) */
		$b['summary'] = cp_gcal_pen($c['t']) ? CP_GCAL_PEN . cp_gcal_unpen($v['t']) : cp_gcal_unpen($v['t']);
		$r = cp_gcal_req('PATCH', '/events/' . rawurlencode($eid), array(), $b);
	} else {
		/* törölt bejegyzés: újra létrehozzuk (új azonosítóval), a panel saját nyilvántartása az újra mutat */
		$b = array_map(function ($x) { return is_array($x) ? array_filter($x, function ($y) { return $y !== null; }) : $x; }, $b);
		$r = cp_gcal_req('POST', '/events', array(), $b);
		if (!is_wp_error($r) && !empty($r['id'])) {
			$made = get_option('cp_gcal_made', array());
			if (is_array($made)) { foreach ($made as $k => $x) { if ((string) $x === $eid) { $made[$k] = (string) $r['id']; } } update_option('cp_gcal_made', $made, false); }
			$l = cp_gv_last($eid); if ($l && !(int) $l['del']) { $l['id'] = $eid; cp_gv_add($l, 'újra létrehozva', cp_gv_who(), true, $l); }
		}
	}
	unset($GLOBALS['cp_gv_src']);
	if (is_wp_error($r)) { return $r->get_error_message(); }
	update_option('cp_gcal_gen', (int) get_option('cp_gcal_gen', 0) + 1, false);
	if (function_exists('cp_gcal_wlog')) { cp_gcal_wlog('bejegyzés visszaállítva (' . wp_date('m.d. H:i', (int) $v['at']) . '-i állapot, ' . cp_gv_who() . ')', 0, $v['t']); }
	return '';
}

/* ------------------------------------------------------------ AJAX */
function cp_gv_row_out($r, $full = true) {
	$o = array('id' => (int) $r['id'], 'eid' => (string) $r['eid'], 'at' => (int) $r['at'], 'gup' => (int) $r['gup'], 'src' => (string) $r['src'], 'who' => (string) $r['who'],
		't' => (string) $r['t'], 's' => (string) $r['s'], 'e' => (string) $r['e'], 'ad' => (int) $r['ad'], 'loc' => (string) $r['loc'], 'del' => (int) $r['del']);
	if ($full) { $o['lines'] = cp_gcal_lines((string) $r['raw']); }
	return $o;
}
foreach (array('cp_gv_hist', 'cp_gv_feed', 'cp_gv_restore', 'cp_gv_mass') as $cp_a) {
	add_action('wp_ajax_' . $cp_a, $cp_a . '_ajax'); add_action('wp_ajax_nopriv_' . $cp_a, $cp_a . '_ajax');
}
unset($cp_a);
function cp_gv_guard($restore = false) {
	cp_el_auth();
	cp_tab_need(array('gnaptar'));
	if (!cp_gv_ok()) { cp_el_err('A mentés még nem indult el.', 503); }
	if ($restore && !cp_gv_can()) { cp_el_err('Visszaállításhoz nincs jogosultságod (Beállítások → Láthatóság: „Naptár visszaállítása”).', 403); }
}
function cp_gv_hist_ajax() {
	cp_gv_guard();
	global $wpdb;
	$eid = sanitize_text_field(wp_unslash($_POST['eid'] ?? ''));
	$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cp_gv_t() . ' WHERE eid = %s ORDER BY id DESC', $eid), ARRAY_A);
	wp_send_json_success(array('v' => array_map('cp_gv_row_out', (array) $rows), 'can' => cp_gv_can(), 'keep' => CP_GV_KEEP, 'days' => CP_GV_DAYS));
}
function cp_gv_feed_ajax() {
	cp_gv_guard();
	global $wpdb;
	$q = 'SELECT * FROM ' . cp_gv_t() . " WHERE src NOT IN ('első mentés', 'visszaállítás előtt') ORDER BY id DESC LIMIT 80";
	$first = (int) $wpdb->get_var('SELECT MIN(at) FROM ' . cp_gv_t());
	wp_send_json_success(array('v' => array_map(function ($r) { return cp_gv_row_out($r, false); }, (array) $wpdb->get_results($q, ARRAY_A)), 'can' => cp_gv_can(), 'since' => $first,
		'n' => (int) $wpdb->get_var('SELECT COUNT(DISTINCT eid) FROM ' . cp_gv_t())));
}
function cp_gv_restore_ajax() {
	cp_gv_guard(true);
	$err = cp_gv_restore_one((int) ($_POST['vid'] ?? 0), 'visszaállítva');
	if ($err !== '') { cp_el_err($err, 502); }
	wp_send_json_success(array('ok' => 1));
}
/* tömeges: minden bejegyzés, amely a megadott időpont óta változott, az akkori (utolsó előtte mentett) állapotára */
function cp_gv_mass_ajax() {
	cp_gv_guard(true);
	global $wpdb;
	$ts = (int) ($_POST['ts'] ?? 0);
	if ($ts < time() - (CP_GV_DAYS + 1) * DAY_IN_SECONDS || $ts > time()) { cp_el_err('Hibás időpont.'); }
	$changed = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT eid FROM ' . cp_gv_t() . " WHERE at >= %d AND src <> 'visszaállítás előtt'", $ts));
	$out = array();
	foreach ((array) $changed as $eid) {
		$tg = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_gv_t() . ' WHERE eid = %s AND at < %d ORDER BY id DESC LIMIT 1', $eid, $ts), ARRAY_A);
		$cu = cp_gv_last($eid);
		if (!$tg || !$cu || (int) $tg['del'] || ($cu['h'] === $tg['h'] && !(int) $cu['del'])) { continue; }
		$out[] = array('vid' => (int) $tg['id'], 'eid' => $eid, 't' => (string) $tg['t'], 's' => (string) $tg['s'], 'tat' => (int) $tg['at'], 'cat' => (int) $cu['at'], 'csrc' => (string) $cu['src'], 'cwho' => (string) $cu['who'], 'cdel' => (int) $cu['del']);
	}
	usort($out, function ($a, $b) { return strcmp($a['s'], $b['s']); });
	if (empty($_POST['go'])) { wp_send_json_success(array('list' => $out)); }
	$pick = array_map('intval', (array) json_decode(wp_unslash((string) ($_POST['vids'] ?? '[]')), true));
	$ok = 0; $err = array();
	foreach ($out as $x) {
		if (!in_array($x['vid'], $pick, true)) { continue; }
		$m = cp_gv_restore_one($x['vid'], 'tömeges visszaállítás');
		if ($m === '') { $ok++; } else { $err[] = $x['t'] . ': ' . $m; }
	}
	wp_send_json_success(array('ok' => $ok, 'err' => $err));
}
