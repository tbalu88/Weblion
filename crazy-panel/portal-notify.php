<?php
/* Ügyfélportál – értesítések: e-mail (wp_mail), Pumble, notifications napló (kettős küldés ellen) */

if (!defined('ABSPATH')) { exit; }

/* Típusonként egyszer: a notifications tábla egyedi kulcsa (portal_id + type) garantálja.
 * Előbb lefoglaljuk a sort, csak utána küldünk; átmeneti hibánál (legfeljebb 3-szor) újra próbálható. */
function cp_portal_notify_once($portal, $type, $channel, $send) {
	global $wpdb;
	$t = cp_portal_t('notifications');
	$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (portal_id, type, channel, sent_at, result) VALUES (%d, %s, %s, %s, %s)", (int) $portal->id, $type, $channel, cp_portal_db_now(), 'küldés…'));
	if ((int) $wpdb->rows_affected !== 1) { return 'already'; }
	$id = (int) $wpdb->insert_id;
	$r = call_user_func($send);
	if (is_wp_error($r)) {
		$tk = 'cp_portal_ntry_' . $portal->id . '_' . md5($type);
		$n = (int) get_transient($tk) + 1;
		if ($n < 3) {
			set_transient($tk, $n, DAY_IN_SECONDS);
			$wpdb->delete($t, array('id' => $id));  // a következő futás újra megpróbálja
		} else {
			$wpdb->update($t, array('result' => mb_substr('hiba: ' . $r->get_error_message(), 0, 250)), array('id' => $id));
		}
		cp_portal_error_add($portal->deal_id, 'értesítés: ' . $type, $r->get_error_message());
		return $r;
	}
	$wpdb->update($t, array('result' => is_string($r) ? mb_substr('ok: ' . $r, 0, 250) : 'ok', 'sent_at' => cp_portal_db_now()), array('id' => $id));
	return 'sent';
}

function cp_portal_notifications($pid) {
	global $wpdb;
	return (array) $wpdb->get_results($wpdb->prepare('SELECT type, channel, sent_at, result FROM ' . cp_portal_t('notifications') . ' WHERE portal_id = %d ORDER BY id DESC LIMIT 50', (int) $pid));
}

/* ------------------------------------------------------------ e-mail */

/* $to: valódi címzett; ha be van állítva teszt-postafiók, minden ügyfél-levél oda megy */
function cp_portal_mail($to, $subject, $html) {
	$o = cp_portal_opts();
	$test = sanitize_email((string) $o['test_inbox']);
	if ($test !== '') {
		$subject = '[TESZT → ' . $to . '] ' . $subject;
		$to = $test;
	}
	if (!is_email($to)) { return new WP_Error('mail_to', 'Érvénytelen címzett: ' . $to); }
	$h = array('Content-Type: text/html; charset=UTF-8');
	$from = sanitize_email((string) $o['mail_from']);
	if ($from !== '') { $h[] = 'From: ' . cp_portal_mail_name($o['mail_from_name']) . ' <' . $from . '>'; }
	$rt = sanitize_email((string) $o['reply_to']);
	if ($rt !== '') { $h[] = 'Reply-To: ' . $rt; }
	$err = null;
	$cb = function ($e) use (&$err) { $err = $e; };
	add_action('wp_mail_failed', $cb);
	$ok = wp_mail($to, $subject, $html, $h);
	remove_action('wp_mail_failed', $cb);
	if (!$ok) { return new WP_Error('mail', 'A levél nem ment ki' . ($err instanceof WP_Error ? ': ' . $err->get_error_message() : '.')); }
	return $to;
}

function cp_portal_mail_name($n) {
	return trim(preg_replace('/[\r\n<>"]/', '', (string) $n)) ?: 'EventCrazy';
}

/* ügyfél-levél (welcome, reminder_1, reminder_2, completed, newlink, resend) – mindegyikben egy gomb a portálra.
 * A link minden levélhez újonnan készül (az adatbázisban csak hash van, a régi linket nem tudjuk visszaadni). */
function cp_portal_send_client_mail($portal, $type, $force = false) {
	if (!$force && cp_portal_mode() === 'off') { return new WP_Error('off', 'Az ügyfélportál ki van kapcsolva – levél nem megy ki.'); }
	$portal = cp_portal_sm_refresh($portal, 3600);
	$to = isset($portal->sm['email']) ? (string) $portal->sm['email'] : '';
	if ($to === '') { return new WP_Error('no_email', 'A deal kapcsolattartójának nincs e-mail-címe a Salesmate-ben.'); }
	$link = cp_portal_issue_link($portal);
	if (is_wp_error($link)) { return $link; }
	require_once __DIR__ . '/portal-tpl-mail.php';
	$di = cp_portal_deadline_info($portal);
	$miss = cp_portal_missing(cp_portal_answers($portal->id));
	$m = cp_portal_mail_render($type, array(
		'name' => isset($portal->sm['name']) ? $portal->sm['name'] : '',
		'event_date' => cp_portal_hu_date($portal->event_date, false),
		'package' => isset($portal->sm['package']) ? $portal->sm['package'] : '',
		'link' => $link,
		'deadline' => $di ? $di['text'] : '',
		'missing' => array_values($miss),
		'contact' => cp_portal_opts()['reply_to'],
	));
	return cp_portal_mail($to, $m['subject'], $m['html']);
}

/* új link e-mailben (ügyfél kérte vagy az iroda küldte); a címzett címét adja vissza */
function cp_portal_send_link_mail($portal, $type = 'newlink', $force = false) {
	return cp_portal_send_client_mail($portal, $type, $force);
}

/* ------------------------------------------------------------ Pumble */

function cp_portal_pumble($text) {
	$o = cp_portal_opts();
	$url = trim((string) $o['pumble_url']);
	if ($url === '') { return new WP_Error('nopumble', 'Az ügyfélportál Pumble-webhookja nincs beállítva (WP admin → Beállítások → Ügyfélportál).'); }
	return cp_el_pumble(array('text' => $text), $url);
}

function cp_portal_pumble_head($portal) {
	$who = isset($portal->sm['who']) && $portal->sm['who'] !== '' ? $portal->sm['who'] : 'Deal #' . $portal->deal_id;
	return $who . ' · ' . cp_portal_hu_date($portal->event_date);
}

function cp_portal_pumble_overdue($portal, $missing) {
	$t = '⚠️ Hiányos adatlap a határidő után – ' . cp_portal_pumble_head($portal) . "\n";
	$t .= 'Hiányzik: ' . implode(', ', array_values($missing)) . "\n";
	$t .= 'Portál: ' . cp_portal_panel_url($portal->deal_id);
	return cp_portal_pumble($t);
}

function cp_portal_pumble_late_change($portal, $logs) {
	$o = cp_portal_opts();
	$fields = cp_portal_fields();
	$who = array('ugyfel' => 'ügyfél', 'iroda' => 'iroda', 'rendszer' => 'rendszer');
	$lines = array();
	foreach (array_reverse($logs) as $l) {
		$label = isset($fields[$l->field_key]) ? $fields[$l->field_key]['qlabel'] : $l->field_key;
		$lines[] = '• ' . $label . ' (' . (isset($who[$l->updated_by]) ? $who[$l->updated_by] : $l->updated_by) . ', ' . wp_date('m.d. H:i', cp_portal_db_ts($l->created_at), cp_portal_tz()) . ')';
	}
	$m = trim((string) $o['pumble_mention']);
	$t = '✏️ Adatlap módosítva a határidő után – ' . cp_portal_pumble_head($portal) . ($m !== '' ? ' · ' . $m : '') . "\n";
	$t .= implode("\n", array_values(array_unique($lines))) . "\n";
	$t .= 'Portál: ' . cp_portal_panel_url($portal->deal_id);
	return cp_portal_pumble($t);
}
