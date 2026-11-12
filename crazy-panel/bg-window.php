<?php
/**
 * Crazy panel – háttér-foglaltság időablakkal (v2.9.232, döntés 2026-11-12).
 * Egy háttér ugyanazon a napon két rendezvényre is kiadható, ha a korábbi vége és a későbbi kezdése között
 * legalább 2 óra van (pontosan 2 óra még elég). Időablak: a portálon kitöltött menetrend (kezdés–vége), különben
 * a deal kezdési ideje + óraszáma (többnapos rendezvénynél a nap saját értékei). Hiányzó idő → foglaltnak számít.
 * Éjfél utánra nyúló rendezvény a másnapot nem foglalja (a hátterek éjjel visszakerülnek a raktárba).
 */
if (!defined('ABSPATH')) { exit; }

define('CP_BGW_GAP', 120); /* perc */

/* két időablak (array(kezdés perc, vége perc) | null) elfér-e egymás mellett ugyanazon a napon */
function cp_bgw_fits($a, $b) {
	if (!is_array($a) || !is_array($b)) { return false; }
	return $a[1] + CP_BGW_GAP <= $b[0] || $b[1] + CP_BGW_GAP <= $a[0];
}

/* „HH:MM” + óraszám → ablak; hibás / hiányzó adatnál null */
function cp_bgw_make($hm, $hours) {
	if (!is_string($hm) || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($hm), $m) || (int) $m[1] > 23 || (int) $m[2] > 59) { return null; }
	$h = is_numeric($hours) ? (float) $hours : 0;
	if ($h <= 0 || $h > 24) { return null; }
	$s = (int) $m[1] * 60 + (int) $m[2];
	return array($s, $s + (int) round($h * 60));
}

/* a portál menetrendje (kezdés–vége) → ablak; null, ha nincs teljesen kitöltve */
function cp_bgw_sched($sch) {
	if (!is_array($sch) || empty($sch['start']) || empty($sch['end'])) { return null; }
	$ok = function ($t) { return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); };
	if (!$ok($sch['start']) || !$ok($sch['end'])) { return null; }
	$s = (int) substr($sch['start'], 0, 2) * 60 + (int) substr($sch['start'], 3, 2);
	$e = (int) substr($sch['end'], 0, 2) * 60 + (int) substr($sch['end'], 3, 2);
	if ($e <= $s) { $e += 1440; }
	return array($s, $e);
}

/* Salesmate-sor (a többnapos napi sor is) → ablak a deal kezdési idejéből és óraszámából */
function cp_bgw_row($r) {
	$p = cp_panel_opts();
	$e = function_exists('cp_el_opts') ? cp_el_opts() : array();
	$hm = '';
	if (!empty($e['f_start']) && function_exists('cp_el_time')) { $hm = cp_el_time(cp_panel_get($r, $e['f_start'])); }
	if ($hm === '' && $p['f_time'] !== '') { $hm = cp_panel_norm_time(cp_panel_get($r, $p['f_time'])); }
	$hours = !empty($e['f_hours']) ? cp_panel_get($r, $e['f_hours']) : null;
	return cp_bgw_make($hm, $hours);
}

/* a lekéréshez szükséges Salesmate-mezők */
function cp_bgw_fields() {
	$e = function_exists('cp_el_opts') ? cp_el_opts() : array();
	$f = array();
	foreach (array('f_start', 'f_hours') as $k) { if (!empty($e[$k])) { $f[] = 'deal.' . $e[$k]; } }
	return $f;
}

/* a portálon kitöltött menetrendek: deal_id => ablak (csak ahol kezdés és vége is megvan) */
function cp_bgw_portal_map($deal_ids) {
	global $wpdb;
	if (!function_exists('cp_portal_get_many') || !$deal_ids) { return array(); }
	$ps = cp_portal_get_many($deal_ids);
	if (!$ps) { return array(); }
	$byp = array();
	foreach ($ps as $did => $p) { $byp[(int) $p->id] = (string) $did; }
	$in = implode(',', array_map('intval', array_keys($byp)));
	$rows = $wpdb->get_results('SELECT portal_id, value FROM ' . cp_portal_t('form_answers') . " WHERE field_key = 'schedule' AND portal_id IN ($in)");
	$out = array();
	foreach ((array) $rows as $r) {
		$w = cp_bgw_sched(json_decode((string) $r->value, true));
		if ($w && isset($byp[(int) $r->portal_id])) { $out[$byp[(int) $r->portal_id]] = $w; }
	}
	return $out;
}

/* egy esemény (sor-azonosító, lehet többnapos napi kulcs) ablaka: portál-menetrend (csak az egynapos / 1. sor), különben a Salesmate-adat */
function cp_bgw_pick($id, $row_win, $pmap) {
	$id = (string) $id;
	if (ctype_digit($id) && isset($pmap[$id])) { return $pmap[$id]; }
	return $row_win;
}

function cp_bgw_hm($min) { $min = ((int) $min) % 1440; return sprintf('%02d:%02d', intdiv($min, 60), $min % 60); }
function cp_bgw_txt($w) { return is_array($w) ? cp_bgw_hm($w[0]) . '–' . cp_bgw_hm($w[1]) : 'időpont nélkül'; }
