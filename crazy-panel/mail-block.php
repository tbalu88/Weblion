<?php
/**
 * Crazy panel – feladó tiltása (v2.9.188).
 * A tiltott címről (vagy domainről) érkező levél a beérkezéskor a Spam mappába kerül, értesítés nélkül. Tiltáskor a
 * feladó meglévő levélszálai is a Spambe kerülnek (onnan visszaállíthatók). Csak a panelben hat – a postafiókban nem.
 * Tárolás: cp_mail_block opció – kisbetűs címek, illetve „@domain.hu” bejegyzések.
 */
if (!defined('ABSPATH')) { exit; }

function cp_mail_block_list() { $l = get_option('cp_mail_block', array()); return is_array($l) ? $l : array(); }
/* a cím tiltott-e (pontos cím vagy a domain) */
function cp_mail_blocked($e) {
	$e = strtolower(trim((string) $e));
	if ($e === '' || strpos($e, '@') === false) { return false; }
	$l = cp_mail_block_list();
	if (!$l) { return false; }
	return in_array($e, $l, true) || in_array('@' . substr(strrchr($e, '@'), 1), $l, true);
}

add_action('wp_ajax_nopriv_cp_mail_block', 'cp_mail_ajax_block');
add_action('wp_ajax_cp_mail_block', 'cp_mail_ajax_block');
function cp_mail_ajax_block() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$T = cp_mail_t('threads');
	$op = (string) ($_POST['op'] ?? '');
	$e = strtolower(sanitize_email((string) wp_unslash($_POST['e'] ?? '')));
	if ($e === '' || !is_email($e)) { cp_el_err('Érvénytelen cím.'); }
	if (function_exists('cp_imp_ours') && cp_imp_ours($e)) { cp_el_err('Saját címet nem lehet tiltani.'); }
	$dom = !empty($_POST['dom']);
	$d = substr(strrchr($e, '@'), 1);
	if ($dom && function_exists('cp_dl_freedom') && cp_dl_freedom($d)) { cp_el_err('Ingyenes levelező (pl. gmail) domainje nem tiltható – csak a cím.'); }
	$key = $dom ? '@' . $d : $e;
	$l = cp_mail_block_list();
	$who = function_exists('cp_panel_who') ? cp_panel_who() : '';
	if ($op === 'unblock') {
		$l = array_values(array_diff($l, array($e, '@' . $d)));
		update_option('cp_mail_block', $l, false);
		if (function_exists('cp_audit_add')) { cp_audit_add('mail', 'unblock', 'Feladó tiltásának feloldása', 'mail:' . $key, ''); }
		wp_send_json_success(array('blocked' => false, 'moved' => 0));
	}
	if ($op !== 'block') { cp_el_err('Hibás kérés.'); }
	if (!in_array($key, $l, true)) { $l[] = $key; if (count($l) > 2000) { $l = array_slice($l, -2000); } update_option('cp_mail_block', $l, false); }
	/* a feladó meglévő levélszálai a Spambe (a Kuka / Spam / Archív kivételével) */
	$where = $dom ? $wpdb->prepare('LOWER(contact_email) LIKE %s', '%@' . $wpdb->esc_like($d)) : $wpdb->prepare('LOWER(contact_email) = %s', $e);
	$ids = array_map('intval', (array) $wpdb->get_col("SELECT id FROM $T WHERE $where AND folder IN ('inbox','promo','snooze','follow')"));
	if ($ids) {
		$wpdb->query("UPDATE $T SET prev_folder = folder, folder = 'spam' WHERE id IN (" . implode(',', $ids) . ')');
		foreach ($ids as $id) { $wpdb->insert(cp_mail_t('events'), array('thread_id' => $id, 'kind' => 'spam', 'text' => 'Spambe téve – a feladó tiltva' . ($dom ? ' (domain)' : ''), 'author_uid' => function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0, 'author' => $who, 'created_at' => gmdate('Y-m-d H:i:s'))); }
	}
	if (function_exists('cp_audit_add')) { cp_audit_add('mail', 'block', 'Feladó tiltva', 'mail:' . $key, count($ids) . ' szál a Spambe'); }
	wp_send_json_success(array('blocked' => true, 'moved' => count($ids), 'key' => $key));
}
