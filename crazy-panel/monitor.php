<?php
/* =====================================================================
 * v2.9.302: Figyelők (a felhasználó választása: M1, M7)
 *  M1 – a tartalék szerver (galéria, AI-kép, mobil app, feltöltő híd) 5 percenként: ha kétszer egymás után nem válaszol,
 *       riasztás (csengő / telefon / Pumble a beállítások szerint); ha helyreáll, külön értesítés.
 *  M7 – az AI-kredit (OpenRouter-egyenleg) 30 percenként: ha a küszöb (10 $) alá esik, egyszer riaszt; újra csak akkor,
 *       ha közben fölé ment (feltöltés).
 * Címzettek: mint a gép-kimaradásnál (cp_blog_alert_to: Ákos + adminok, a Crazy Booth gépek oldalon állítható).
 * Futás: a percenkénti cp_blog_tick ütemező mellett, saját időzítéssel (transient).
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }

function cp_mon_to() { return function_exists('cp_blog_alert_to') ? cp_blog_alert_to() : array(); }
function cp_mon_notify($kind, $txt, $hash) { if (!function_exists('cp_notify')) { return; } foreach (cp_mon_to() as $u) { cp_notify((int) $u, $kind, $txt, $hash, 'Crazy panel'); } }
function cp_mon_state() { $s = get_option('cp_mon_state', array()); return is_array($s) ? $s : array(); }
function cp_mon_save($s) { update_option('cp_mon_state', $s, false); }

/* M1: egy próba – a feltöltő híd „alive” címe (bejelentkezés nélkül, olcsó) */
function cp_mon_probe() {
	$r = wp_remote_get('https://tartalek.eventcrazy.hu/wp-json/crazy-bridge/v1/alive', array('timeout' => 12, 'redirection' => 0));
	if (is_wp_error($r)) { return 'nem érhető el (' . mb_substr($r->get_error_message(), 0, 60) . ')'; }
	$c = (int) wp_remote_retrieve_response_code($r);
	return $c >= 200 && $c < 400 ? '' : 'HTTP ' . $c;
}
function cp_mon_tick() {
	$now = time(); $s = cp_mon_state();
	/* M1 – 5 percenként */
	if ($now - (int) ($s['srv_at'] ?? 0) >= 290) {
		$err = cp_mon_probe(); $s['srv_at'] = $now;
		if ($err === '') {
			if (!empty($s['srv_down'])) { cp_mon_notify('srv_down', 'helyreállt a tartalék szerver (galéria, AI-kép, mobil app) – ' . round(($now - (int) $s['srv_down']) / 60) . ' perc kiesés után', 'aikredit'); }
			$s['srv_fail'] = 0; $s['srv_down'] = 0;
		} else {
			$s['srv_fail'] = (int) ($s['srv_fail'] ?? 0) + 1; $s['srv_err'] = $err;
			if ($s['srv_fail'] === 2 && empty($s['srv_down'])) { $s['srv_down'] = $now - 300; cp_mon_notify('srv_down', 'NEM ÉRHETŐ EL a tartalék szerver (' . $err . ') – az online galériák, a QR-linkek, az AI-képek és a gépek feltöltése most nem működnek. Teendő: RackForest ügyfélkapu → a VPS újraindítása.', 'aikredit'); }
		}
		cp_mon_save($s);
	}
	/* M7 – 30 percenként, csak ha a szerver él */
	if (empty($s['srv_down']) && (int) ($s['srv_fail'] ?? 0) === 0 && $now - (int) ($s['ai_at'] ?? 0) >= 1790 && function_exists('cp_booth_gal_req')) {
		$s['ai_at'] = $now; cp_mon_save($s);
		$st = cp_booth_gal_req('GET', '/ai-setup');
		$bal = !is_wp_error($st) && isset($st['credits']['bal']) && $st['credits']['bal'] !== null ? (float) $st['credits']['bal'] : null;
		$thr = (float) get_option('cp_mon_ai_thr', 10); /* a felhasználó döntése: 10 $ */
		if ($bal !== null) {
			if ($bal < $thr && empty($s['ai_low'])) { $s['ai_low'] = 1; cp_mon_notify('ai_low', 'kevés az AI-kredit: ' . number_format($bal, 2, ',', ' ') . ' $ (kb. ' . max(0, (int) floor($bal / 0.055)) . ' AI-kép) – töltsd fel: Eszközök → AI-kredit → Kredit feltöltése', 'aikredit'); }
			elseif ($bal >= $thr + 1) { $s['ai_low'] = 0; }
			$s['ai_bal'] = $bal; cp_mon_save($s);
		}
	}
}
add_action('cp_blog_tick', 'cp_mon_tick', 20);
