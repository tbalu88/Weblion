<?php
/**
 * v1.99: Őrszem – figyeli, hogy a rendszer háttérfolyamatai élnek-e, és szól (csengő + a beállított Pumble-értesítés)
 * az aktív admin kollégáknak, ha valami megállt. Egy hibáról 6 óránként legfeljebb egyszer, és szól, ha helyreállt.
 *  – levél: munkaidőben (H–P 8–18) 3 órája nem jött új levél a postafiókba → lehet levelezési (szerver-) hiba
 *  – levél-szinkron: hibát ad, vagy 20 perce nem futott (a háttérütemező állt le)
 *  – CRM-szinkron (Salesmate): 2 órája nem sikerült
 *  – napi adatbázis-mentés: 30 órája nem készült, vagy hibát adott
 */
if (!defined('ABSPATH')) { exit; }

add_action('init', function () { if (!wp_next_scheduled('cp_wd_tick')) { wp_schedule_event(time() + 120, 'cp_5min', 'cp_wd_tick'); } });
add_action('cp_wd_tick', 'cp_wd_tick');
function cp_wd_tick() {
	if (get_transient('cp_wd_at')) { return; }
	set_transient('cp_wd_at', 1, 9 * MINUTE_IN_SECONDS);
	$w = get_option('cp_wd', array()); if (!is_array($w)) { $w = array(); }
	$now = time();
	$issues = array();
	/* levél */
	if (get_option('cp_imp_sync_on') === '1') {
		$sy = get_option('cp_imp_sync', array()); if (!is_array($sy)) { $sy = array(); }
		$n = (int) ($sy['n'] ?? 0);
		if (!isset($w['n']) || $n > (int) $w['n']) { $w['n'] = $n; $w['new_at'] = $now; }
		$last = (int) ($sy['last'] ?? 0);
		if (!empty($sy['err'])) { $issues['mail_err'] = 'A levelek letöltése hibát ad: „' . mb_substr((string) $sy['err'], 0, 120) . '” – a panelbe most nem jönnek az új levelek.'; }
		elseif ($last && $now - $last > 20 * MINUTE_IN_SECONDS) { $issues['mail_cron'] = 'A levelek letöltése ' . round(($now - $last) / 60) . ' perce nem futott – a háttérütemező (cron) leállhatott.'; }
		$q = cp_wd_quiet_sec((int) ($w['new_at'] ?? $now), $now);
		if ($q > 3 * HOUR_IN_SECONDS) { $issues['mail_quiet'] = 'Munkaidőben ' . floor($q / 3600) . ' órája egyetlen új levél sem érkezett az info@ postafiókba. Ellenőrizd, hogy működik-e a levelezés (pl. küldj egy próbalevelet Gmailből).'; }
	}
	/* CRM-szinkron (amíg a Salesmate él) */
	if (function_exists('cp_crm_state') && get_option('cp_crm_primary') !== '1') {
		$cs = cp_crm_state();
		if (!empty($cs['last_ok']) && $now - (int) $cs['last_ok'] > 2 * HOUR_IN_SECONDS) { $issues['crm'] = 'A Salesmate-szinkron ' . floor(($now - (int) $cs['last_ok']) / 3600) . ' órája nem sikerült – a deal-ek adatai elavulhatnak.'; }
	}
	/* mentés */
	$ms = get_option('cp_mentes_state', null);
	if (is_array($ms)) {
		if (!empty($ms['err']) && (int) ($ms['err_at'] ?? 0) > (int) ($ms['last'] ?? 0)) { $issues['backup'] = 'A napi adatbázis-mentés hibát adott: „' . mb_substr((string) $ms['err'], 0, 120) . '”.'; }
		elseif (!empty($ms['last']) && $now - (int) $ms['last'] > 30 * HOUR_IN_SECONDS) { $issues['backup'] = 'A napi adatbázis-mentés ' . floor(($now - (int) $ms['last']) / 3600) . ' órája nem készült el.'; }
	}
	/* Google Drive mentés (ha be van kapcsolva) */
	if (function_exists('cp_gdb_opts') && cp_gdb_opts()['on'] === '1') {
		$gs = cp_gdb_state();
		if (!empty($gs['err']) && (int) ($gs['err_at'] ?? 0) > (int) ($gs['last'] ?? 0)) { $issues['gdrive'] = 'A Google Drive-ra mentés nem sikerült: „' . mb_substr((string) $gs['err'], 0, 140) . '”.'; }
		elseif (!empty($gs['last']) && $now - (int) $gs['last'] > 14 * HOUR_IN_SECONDS) { $issues['gdrive'] = 'A Google Drive-ra ' . floor(($now - (int) $gs['last']) / 3600) . ' órája nem került fel mentés.'; }
	}
	$sent = isset($w['sent']) && is_array($w['sent']) ? $w['sent'] : array();
	foreach ($issues as $k => $msg) {
		if (!empty($sent[$k]) && $now - (int) $sent[$k] < 6 * HOUR_IN_SECONDS) { continue; }
		cp_wd_alert('⚠ Rendszerfigyelő: ' . $msg);
		$sent[$k] = $now;
	}
	foreach (array_keys($sent) as $k) {
		if (!isset($issues[$k])) { cp_wd_alert('✓ Rendszerfigyelő: helyreállt – ' . cp_wd_label($k) . '.'); unset($sent[$k]); }
	}
	$w['sent'] = $sent; $w['issues'] = $issues; $w['at'] = $now;
	update_option('cp_wd', $w, false);
}
function cp_wd_label($k) {
	$L = array('mail_err' => 'a levelek letöltése', 'mail_cron' => 'a háttérütemező', 'mail_quiet' => 'újra érkeznek levelek', 'crm' => 'a Salesmate-szinkron', 'backup' => 'a napi mentés', 'gdrive' => 'a Google Drive mentés');
	return $L[$k] ?? $k;
}
/* a csend hossza csak munkaidőben számolva (H–P 8:00–18:00, budapesti idő) */
function cp_wd_quiet_sec($from, $to) {
	if ($to <= $from) { return 0; }
	$tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Budapest');
	$sum = 0;
	$d = (new DateTime('@' . $from))->setTimezone($tz); $d->setTime(0, 0);
	for ($i = 0; $i < 14 && $d->getTimestamp() < $to; $i++) {
		if ((int) $d->format('N') <= 5) {
			$a = (clone $d)->setTime(8, 0)->getTimestamp(); $b = (clone $d)->setTime(18, 0)->getTimestamp();
			$s = max($a, $from); $e = min($b, $to);
			if ($e > $s) { $sum += $e - $s; }
		}
		$d->modify('+1 day');
	}
	return $sum;
}
function cp_wd_alert($text) {
	if (!function_exists('cp_pu_data') || !function_exists('cp_notify')) { return; }
	foreach (cp_pu_data()['list'] as $id => $u) {
		if (!empty($u['a']) && ($u['r'] ?? '') === 'admin') { cp_notify((int) $id, 'system', $text, 'beallitasok', 'Crazy panel'); }
	}
	if (function_exists('cp_audit_add')) { cp_audit_add('rendszer', 'watchdog', 'Rendszerfigyelő', '', mb_substr($text, 0, 250)); }
}
