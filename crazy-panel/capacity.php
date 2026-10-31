<?php
/* =====================================================================
 * v1.84: kapacitásjelzés a dealen – „aznap 1 szabad gép · 9 ajánlat”
 * Ugyanaz a forrás és számítás, mint a Kapacitás fülé (cp_sm_fetch_range + cp_panel_normalize, ugyanaz a
 * havi gyorsítótár-kulcs), így a két szám mindig egyezik: szabad = saját gép + aznapi alvállalkozói gép − foglalt.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

/* egy hónap rendezvényei – a Kapacitás fül havi gyorsítótárát használja / tölti */
function cp_cap_month($m, $cached_only = false) {
	static $mem = array();
	if (array_key_exists($m, $mem) && ($mem[$m] !== null || $cached_only)) { return $mem[$m]; }
	if (!function_exists('cp_sm_fetch_range') || !function_exists('cp_panel_normalize')) { return array(); }
	$from = $m . '-01'; $to = gmdate('Y-m-t', strtotime($from));
	$o = cp_panel_opts();
	$ck = 'cp_panel_d_' . md5($from . $to . wp_json_encode(cp_sm_fields()) . $o['pipeline'] . $o['booked_stages'] . $o['pending_stages']);
	$c = get_transient($ck);
	/* a csempéknél csak akkor tölt, ha a panel a saját tábláiból olvas (gyors); különben a Salesmate-et nem terheli */
	if ($c === false && $cached_only && !(function_exists('cp_crm_source_on') && cp_crm_source_on())) { return $mem[$m] = null; }
	if ($c === false) {
		$info = null; $rows = cp_sm_fetch_range($from, $to, $info);
		if (is_wp_error($rows)) { return $mem[$m] = array(); }
		$ev = array_values(array_filter(cp_panel_normalize($rows), function ($e) use ($from, $to) { return $e['date'] >= $from && $e['date'] <= $to; }));
		$c = array('events' => $ev, 'at' => time(), 'mode' => $info['mode'] ?? '', 'calls' => $info['calls'] ?? 0);
		set_transient($ck, $c, max(1, (int) $o['cache_min']) * MINUTE_IN_SECONDS);
	}
	return $mem[$m] = (array) ($c['events'] ?? array());
}
/* egy nap kapacitása, a Kapacitás fül dayInfo() számításával azonosan */
function cp_cap_day($date, $cached_only = false) {
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) { return null; }
	$o = cp_panel_opts();
	$subs = get_option(defined('CP_PANEL_SUBS') ? CP_PANEL_SUBS : 'cp_panel_subs', array());
	$sub = is_array($subs) && isset($subs[$date]) ? (int) $subs[$date] : (int) ($o['subs_default'] ?? 0);
	$x = array('bs' => 0, 'bp' => 0, 'ps' => 0, 'pp' => 0);
	$evs = cp_cap_month(substr($date, 0, 7), $cached_only);
	if ($evs === null) { return null; }
	foreach ($evs as $e) {
		if (($e['date'] ?? '') !== $date) { continue; }
		$s = in_array($e['kind'] ?? '', array('selfie', 'both'), true); $p = in_array($e['kind'] ?? '', array('spin', 'both'), true);
		if (($e['state'] ?? '') === 'booked') { $x['bs'] += $s ? 1 : 0; $x['bp'] += $p ? 1 : 0; } else { $x['ps'] += $s ? 1 : 0; $x['pp'] += $p ? 1 : 0; }
	}
	$capS = (int) $o['cap_selfie'] + $sub; $capP = (int) $o['cap_spin'];
	$fs = $capS - $x['bs']; $fp = $capP - $x['bp'];
	$lvl = $fs <= 0 ? 'full' : ($fs <= 2 ? 'mid' : 'ok');
	return array('d' => $date, 'fs' => $fs, 'cs' => $capS, 'fp' => $fp, 'cp' => $capP, 'ps' => $x['ps'], 'pp' => $x['pp'], 'sub' => $sub, 'lvl' => $lvl, 'risk' => ($x['ps'] > max(0, $fs) || $x['pp'] > max(0, $fp)) ? 1 : 0);
}
/* a csempékhez: a még le nem zárt (első két szakasz) jövőbeli dealek napjai, a következő 12 hónapban */
function cp_cap_for_rows($rows) {
	if (!$rows || !function_exists('cp_crm_stages')) { return array(); }
	$st = array_slice(cp_crm_stages(), 0, 2); $t = wp_date('Y-m-d'); $max = wp_date('Y-m-d', time() + 365 * DAY_IN_SECONDS);
	$out = array(); $days = array();
	foreach ($rows as $r) {
		$d = (string) ($r['ev'] ?? '');
		if ($r['status'] !== 'Open' || !in_array($r['stage'], $st, true) || $d < $t || $d > $max) { continue; }
		if (!isset($days[$d])) { $days[$d] = cp_cap_day($d, true); }
		if ($days[$d]) { $out[(int) $r['id']] = $days[$d]; }
	}
	return $out;
}
