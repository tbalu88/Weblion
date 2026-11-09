<?php
/* Ügyfélportál – ütemezett feladatok
 *   cp_portal_tick    15 percenként: emlékeztetők, határidő utáni Pumble, zárolás jelölése, Salesmate-sor
 *   cp_portal_hourly  óránként: tartalék portál-létrehozás, ha a webhook nem jött meg
 *   cp_portal_daily   naponta (03:30): lezárás, adatmegőrzés és törlés
 * Kis forgalomnál a WP-Cron késhet: javasolt valódi szerver-cron a wp-cron.php-ra 15 percenként
 * és DISABLE_WP_CRON a wp-config.php-ban. */

if (!defined('ABSPATH')) { exit; }

add_filter('cron_schedules', function ($s) {
	$s['cp_portal_15m'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Ügyfélportál – 15 percenként');
	return $s;
});

add_action('init', 'cp_portal_cron_ensure', 20);
function cp_portal_cron_ensure() {
	if (!cp_portal_db_ready()) { return; }
	if (!wp_next_scheduled('cp_portal_tick')) { wp_schedule_event(time() + 60, 'cp_portal_15m', 'cp_portal_tick'); }
	if (!wp_next_scheduled('cp_portal_hourly')) { wp_schedule_event(time() + 300, 'hourly', 'cp_portal_hourly'); }
	if (!wp_next_scheduled('cp_portal_daily')) {
		$t = cp_portal_local_ts(cp_portal_ymd_add(cp_portal_today(), 1), '03:30');
		wp_schedule_event($t, 'daily', 'cp_portal_daily');
	}
}

register_deactivation_hook(CP_PORTAL_MAIN, 'cp_portal_cron_clear');
function cp_portal_cron_clear() {
	foreach (array('cp_portal_tick', 'cp_portal_hourly', 'cp_portal_daily', 'cp_portal_smq_run') as $h) { wp_clear_scheduled_hook($h); }
}

add_action('cp_portal_hourly', 'cp_portal_hourly_scan');

add_action('cp_portal_tick', 'cp_portal_tick');
function cp_portal_tick() {
	if (!cp_portal_db_ready()) { return; }
	if (get_transient('cp_portal_tick_lock')) { return; }
	set_transient('cp_portal_tick_lock', 1, 10 * MINUTE_IN_SECONDS);
	update_option('cp_portal_tick_last', time(), false);
	cp_portal_smq_run();
	global $wpdb;
	$from = cp_portal_ymd_add(cp_portal_today(), -2);
	$to = cp_portal_ymd_add(cp_portal_today(), 400);  // a szerződés előtt kiküldött portálok rendezvénye messze is lehet
	$portals = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cp_portal_t('portals') . " WHERE status = 'aktiv' AND event_date BETWEEN %s AND %s ORDER BY event_date", $from, $to));
	$send = cp_portal_mode() !== 'off';
	$now = time();
	foreach ($portals as $p) {
		$p = cp_portal_prep($p);
		/* v1.92: legfeljebb 6 óránként friss deal-adat (állapot, háttér, helyszín) – elvesztett dealre nem megy emlékeztető / jelzés */
		if (function_exists('cp_portal_sm_refresh')) { $p2 = cp_portal_sm_refresh($p, 6 * HOUR_IN_SECONDS); if ($p2) { $p = $p2; } }  /* a visszaadott portál már előkészített (sm tömb) */
		$sm = is_array($p->sm ?? null) ? $p->sm : (array) ($p->sm ?? array());
		if (isset($sm['status']) && in_array((string) $sm['status'], array('lost', 'elvesztett'), true)) { continue; }
		$di = cp_portal_deadline_info($p);
		if (!$di) { continue; }
		$st = cp_portal_state($p->id);
		$created = cp_portal_db_ts($p->created_at);
		/* v1.92: a portál saját kérdéssora szerint (v1-nél a szerződéses lépcső nem kötelező) */
		$ans = cp_portal_answers($p->id);  // v2.9.58: egyszer olvassuk
		$missing = cp_portal_missing($ans, null, cp_portal_flow($p), $p);

		// „Mehet a szerződés” tartalék: a szerződéses adatok kész, de a gombot nem nyomták meg (vagy módosultak a jelzés óta),
		// és 30 perce nem változott semmi → a rendszer jelzi (Pumble)
		if (cp_portal_flow($p) === 'v2' && !cp_portal_missing($ans, 'a', 'v2')) {
			$quiet = cp_portal_db_ts($wpdb->get_var($wpdb->prepare('SELECT MAX(updated_at) FROM ' . cp_portal_t('form_answers') . ' WHERE portal_id = %d', (int) $p->id))) <= $now - 30 * MINUTE_IN_SECONDS;
			if ($quiet && (empty($st->contract_ready_at) || !empty($st->contract_changed_at))) { cp_portal_contract_submit($p, 'rendszer'); }
		}

		// zárolás jelölése a panelnek
		if ($now >= $di['deadline'] && empty($st->locked_at)) { cp_portal_state_put($p->id, array('locked_at' => cp_portal_db_now($di['deadline']))); }
		if (!$send) { continue; }

		// emlékeztetők: csak ha hiányzik kötelező, és a portál már létezett az emlékeztető időpontjában
		if ($missing && $now < $di['deadline']) {
			/* v1.99.2: kiesés után ne menjen ki egyszerre mindkét emlékeztető – csak a legutóbbi esedékes; a lekésett első kimarad */
			$due = '';
			foreach (array('reminder_1', 'reminder_2') as $type) { $at = $di[$type]; if ($at && $now >= $at && $created <= $at) { $due = $type; } }
			if ($due === 'reminder_2' && $di['reminder_1']) { cp_portal_notify_once($p, 'reminder_1', 'email', function () { return 'kihagyva – a második emlékeztető ment ki helyette'; }); }
			if ($due !== '') { cp_portal_notify_once($p, $due, 'email', function () use ($p, $due) { return cp_portal_send_client_mail($p, $due); }); }
		}
		// határidő után az első futáskor: hiányos adatlap → iroda, Pumble
		if ($missing && $now >= $di['deadline']) {
			cp_portal_notify_once($p, 'overdue', 'pumble', function () use ($p, $missing) { return cp_portal_pumble_overdue($p, $missing); });
		}
		// határidő utáni módosítás → iroda és Ákos, Pumble (a mezőnkénti mentéseket 10 percig gyűjti)
		if (!empty($st->changed_late_at) && cp_portal_db_ts($st->changed_late_at) <= $now - 10 * MINUTE_IN_SECONDS) {
			$logs = array_filter(cp_portal_log_list($p->id, 50, (int) $st->late_notified_log), function ($l) use ($di) { return cp_portal_db_ts($l->created_at) >= $di['deadline'] && $l->field_key !== 'retention_consent'; });
			if ($logs) {
				$max = max(array_map(function ($l) { return (int) $l->id; }, $logs));
				cp_portal_state_put($p->id, array('late_notified_log' => $max));
				cp_portal_notify_once($p, 'late_change:' . $max, 'pumble', function () use ($p, $logs) { return cp_portal_pumble_late_change($p, array_values($logs)); });
				cp_portal_sm_queue($p->deal_id, 'note', 'Adatlap módosítva a határidő után (ügyfélportál)');
			}
		}
	}
	delete_transient('cp_portal_tick_lock');
}

/* ------------------------------------------------------------ napi: lezárás, adatmegőrzés */

add_action('cp_portal_daily', 'cp_portal_daily');
function cp_portal_daily() {
	if (!cp_portal_db_ready()) { return; }
	global $wpdb;
	// a rendezvény után a portál „lezárt” (csak olvasható; a link 90 napig még megnyitható)
	$wpdb->query($wpdb->prepare('UPDATE ' . cp_portal_t('portals') . " SET status = 'lezart', updated_at = %s WHERE status = 'aktiv' AND event_date < %s", cp_portal_db_now(), cp_portal_today()));
	cp_portal_retention();
}

/* a napló érintett soraiban a régi/új érték cseréje $blank-ra (ahol még nem az) */
function cp_portal_log_blank($pid, $where, $args, $blank) {
	global $wpdb;
	$L = cp_portal_t('log');
	$sql = "UPDATE $L SET old_value = CASE WHEN old_value IS NULL THEN NULL ELSE %s END, new_value = CASE WHEN new_value IS NULL THEN NULL ELSE %s END
		WHERE portal_id = %d AND $where AND NOT ((old_value IS NULL OR old_value = %s) AND (new_value IS NULL OR new_value = %s))";
	$params = array_merge(array($blank, $blank, (int) $pid), $args, array($blank, $blank));
	return (int) $wpdb->query($wpdb->prepare($sql, $params));
}

function cp_portal_retention_log($what, $pid, $n) {
	if (!$n) { return; }
	$l = get_option('cp_portal_retention_log', array());
	if (!is_array($l)) { $l = array(); }
	array_unshift($l, array('t' => time(), 'w' => (string) $what, 'p' => (int) $pid, 'n' => (int) $n));
	update_option('cp_portal_retention_log', array_slice($l, 0, 300), false);
}

/* Megőrzés (jogászi döntés szerint):
 *   adatlap (a helyszín kivételével): rendezvény + 30 nap, hozzájárulással + 3 év → törlés
 *   helyszíni kapcsolattartó: rendezvény + 30 nap → anonimizálás, a változásnaplóban is
 *   belépési napló, lejárt / visszavont tokenek: 6 hónap → törlés
 *   portál alapadat, helyszín, értesítésnapló: rendezvény + 5 év → törlés
 * $today paraméter a teszteléshez. */
function cp_portal_retention($today = null) {
	global $wpdb;
	$today = $today ?: cp_portal_today();
	$P = cp_portal_t('portals');
	$A = cp_portal_t('form_answers');
	$L = cp_portal_t('log');
	$keep = array('venue_address', 'venue_room', 'retention_consent');
	$anon = array('name' => 'törölve', 'phone' => 'törölve');
	$anon_json = wp_json_encode($anon);

	$cut30 = cp_portal_ymd_add($today, -CP_PORTAL_KEEP_FORM_DAYS);
	$cut3y = cp_portal_ymd_add($today, -CP_PORTAL_KEEP_CONSENT_YEARS * 365 - 1);
	$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT id, event_date, deal_id FROM $P WHERE event_date < %s", $cut30));
	/* v2.9.58: a már teljesen törölt adatlapú portálokat (pid → akkori rendezvénynap) nem dolgozzuk fel minden éjjel újra */
	$done = get_option('cp_portal_ret_done', array());
	if (!is_array($done)) { $done = array(); }
	$done0 = $done;
	foreach ($rows as $p) {
		$pid = (int) $p->id;
		if (isset($done[$pid]) && $done[$pid] === (string) $p->event_date) { continue; }
		/* v1.99.2: ha a dealen közben későbbre tették a rendezvényt (a portál még a régi dátumot tudja), nem törlünk – frissítjük a portált */
		if (function_exists('cp_crm_t') && !empty($p->deal_id)) {
			$nd = (string) $wpdb->get_var($wpdb->prepare('SELECT event_d FROM ' . cp_crm_t('deals') . ' WHERE id = %d AND deleted_at IS NULL', (int) $p->deal_id));
			if ($nd !== '' && $nd >= $cut30) {
				$pp = function_exists('cp_portal_get') ? cp_portal_get($pid) : null;
				if ($pp && function_exists('cp_portal_sm_refresh')) { cp_portal_sm_refresh($pp, 0); }
				continue;
			}
		}
		$answers = cp_portal_answers($pid);
		$consent = isset($answers['retention_consent']['given']) && $answers['retention_consent']['given'];
		$form_due = !$consent || $p->event_date < $cut3y;

		// 1) kapcsolattartó anonimizálása (hozzájárulástól függetlenül), a naplóban is
		$n = 0;
		if (isset($answers['onsite_contact']) && wp_json_encode($answers['onsite_contact']) !== $anon_json) {
			$n += (int) $wpdb->query($wpdb->prepare("UPDATE $A SET value = %s, updated_by = 'rendszer' WHERE portal_id = %d AND field_key = 'onsite_contact'", $anon_json, $pid));
		}
		$n += cp_portal_log_blank($pid, "field_key = 'onsite_contact'", array(), $anon_json);
		cp_portal_retention_log('kapcsolattartó anonimizálva', $pid, $n);

		// 2) adatlap törlése (helyszín és hozzájárulás marad); a naplóban az értékek helyére „törölve”
		if ($form_due) {
			if (function_exists('cp_portal_sb_files_delete')) { cp_portal_sb_files_delete(isset($answers['photo_template']) ? $answers['photo_template'] : null); cp_portal_sb_files_delete(isset($answers['template_logo']) ? $answers['template_logo'] : null); foreach ((array) ($answers['photo_template_hist'] ?? array()) as $hv) { cp_portal_sb_files_delete($hv); } }
			$in = implode(',', array_fill(0, count($keep), '%s'));
			$args = array_merge(array($pid), $keep);
			$d = (int) $wpdb->query($wpdb->prepare("DELETE FROM $A WHERE portal_id = %d AND field_key NOT IN ($in) AND field_key <> 'onsite_contact'", $args));
			$d += (int) $wpdb->query($wpdb->prepare("DELETE FROM $A WHERE portal_id = %d AND field_key = 'onsite_contact'", $pid));
			$x = cp_portal_log_blank($pid, "field_key NOT IN ($in) AND field_key <> 'onsite_contact'", $keep, wp_json_encode('törölve'));
			cp_portal_retention_log('adatlap törölve', $pid, $d);
			cp_portal_retention_log('napló-értékek törölve', $pid, $x);
			$done[$pid] = (string) $p->event_date;
		}
	}

	// 3) belépési napló és lejárt / visszavont tokenek: 6 hónap
	$cut6m = cp_portal_db_now(time() - CP_PORTAL_KEEP_ACCESS_DAYS * DAY_IN_SECONDS);
	$n = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . cp_portal_t('access_log') . ' WHERE created_at < %s', $cut6m));
	cp_portal_retention_log('belépési napló törölve', 0, $n);
	$n = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . cp_portal_t('access_tokens') . ' WHERE (expires_at < %s) OR (revoked_at IS NOT NULL AND revoked_at < %s)', $cut6m, $cut6m));
	cp_portal_retention_log('lejárt tokenek törölve', 0, $n);

	// 4) portál alapadat, helyszín, értesítésnapló: rendezvény + 5 év
	$cut5y = cp_portal_ymd_add($today, -CP_PORTAL_KEEP_PORTAL_YEARS * 365 - 1);
	$old = (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $P WHERE event_date < %s", $cut5y));
	foreach ($old as $pid) {
		$pid = (int) $pid;
		$oa = cp_portal_answers($pid);
		if (function_exists('cp_portal_sb_files_delete')) { cp_portal_sb_files_delete(isset($oa['photo_template']) ? $oa['photo_template'] : null); cp_portal_sb_files_delete(isset($oa['template_logo']) ? $oa['template_logo'] : null); foreach ((array) ($oa['photo_template_hist'] ?? array()) as $hv) { cp_portal_sb_files_delete($hv); } }
		foreach (array('form_answers', 'form_state', 'notifications', 'log', 'access_tokens', 'access_log') as $t) {
			$wpdb->delete(cp_portal_t($t), array('portal_id' => $pid));
		}
		$wpdb->delete($P, array('id' => $pid));
		unset($done[$pid]);
		cp_portal_retention_log('portál törölve (5 év)', $pid, 1);
	}
	if ($done !== $done0) { update_option('cp_portal_ret_done', $done, false); }
	update_option('cp_portal_retention_last', time(), false);
}
