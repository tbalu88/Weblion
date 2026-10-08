<?php
/* Ügyfélportál – Crazy panel oldali AJAX (a panel közös jelszavas belépésével és CSRF-kulcsával: cp_el_auth)
 *   cp_portal_p_get     portál-részletező adatai egy dealhez
 *   cp_portal_p_save    irodai szerkesztés (updated_by = iroda; határidő után „módosítva”)
 *   cp_portal_p_create  portál bekapcsolása / létrehozása egy dealhez (dealenkénti kapcsoló)
 *   cp_portal_p_link    link: új másolása | újraküldése e-mailben | visszavonása
 *   cp_portal_p_errors  hibalista (Salesmate-visszaírás, értesítések), törlés */

if (!defined('ABSPATH')) { exit; }

function cp_portal_p_deal_id() {
	$id = isset($_REQUEST['id']) ? preg_replace('/[^0-9]/', '', (string) wp_unslash($_REQUEST['id'])) : '';
	if ($id === '' || strlen($id) > 20) { cp_el_err('Hibás kérés.'); }
	if (!cp_portal_db_ready()) { cp_el_err('Az ügyfélportál táblái még nem jöttek létre.', 503); }
	return $id;
}

/* a panel konfigurációjába (window.CPX.portal) */
function cp_portal_panel_cfg() {
	return array(
		'on' => cp_portal_db_ready(),
		'mode' => cp_portal_mode(),
		'schema' => cp_portal_schema(),
		'total' => cp_portal_required_total(),
		'open' => isset($_GET['portal']) ? preg_replace('/[^0-9]/', '', (string) wp_unslash($_GET['portal'])) : '',
		'errors' => count(cp_portal_errors()),
	);
}

function cp_portal_p_detail($deal_id) {
	$portal = cp_portal_get_by_deal($deal_id);
	$out = array(
		'id' => $deal_id,
		'mode' => cp_portal_mode(),
		'selected' => isset(cp_portal_selected_deals()[$deal_id]),
		'portal' => null,
		'errors' => array_slice(cp_portal_errors($deal_id), 0, 20),
	);
	if (!$portal) { return $out; }
	$portal = cp_portal_sm_refresh($portal, 600);
	$rows = cp_portal_answer_rows($portal->id);
	$answers = array();
	$meta = array();
	foreach ($rows as $k => $r) { $answers[$k] = $r['value']; $meta[$k] = array('by' => $r['by'], 'at' => $r['at']); }
	$st = cp_portal_state($portal->id);
	$di = cp_portal_deadline_info($portal);
	$log = array();
	foreach (cp_portal_log_list($portal->id, 60) as $l) {
		$log[] = array('t' => cp_portal_db_ts($l->created_at), 'f' => $l->field_key, 'by' => $l->updated_by, 'o' => $l->old_value === null ? null : json_decode($l->old_value, true), 'n' => $l->new_value === null ? null : json_decode($l->new_value, true));
	}
	$tokens = array();
	foreach (cp_portal_tokens_list($portal->id) as $t) {
		$tokens[] = array('id' => (int) $t->id, 'email' => $t->email, 'c' => cp_portal_db_ts($t->created_at), 'x' => cp_portal_db_ts($t->expires_at), 'r' => cp_portal_db_ts($t->revoked_at), 'u' => cp_portal_db_ts($t->last_used_at));
	}
	$notes = array();
	foreach (cp_portal_notifications($portal->id) as $n) { $notes[] = array('type' => $n->type, 'ch' => $n->channel, 't' => cp_portal_db_ts($n->sent_at), 'r' => $n->result); }
	$out['portal'] = array(
		'pid' => (int) $portal->id,
		'status' => $portal->status,
		'event_date' => $portal->event_date,
		'created' => cp_portal_db_ts($portal->created_at),
		'sm' => $portal->sm,
		'answers' => (object) $answers,
		'meta' => (object) $meta,
		'prefill' => cp_portal_prefill($portal),
		'state' => array('st' => $st->status, 'done' => (int) $st->required_done, 'total' => cp_portal_required_total(), 'completed' => cp_portal_db_ts($st->completed_at), 'late' => cp_portal_db_ts($st->changed_late_at)),
		'missing' => array_values(cp_portal_missing($answers)),
		'deadline' => $di ? array('text' => $di['text'], 'late' => $di['late'], 'passed' => time() >= $di['deadline']) : null,
		'locked' => cp_portal_locked($portal),
		'flags' => cp_portal_flags_build($portal, $answers, $st),
		'log' => $log,
		'tokens' => $tokens,
		'notes' => $notes,
	);
	return $out;
}

add_action('wp_ajax_nopriv_cp_portal_p_get', 'cp_portal_ajax_p_get');
add_action('wp_ajax_cp_portal_p_get', 'cp_portal_ajax_p_get');
function cp_portal_ajax_p_get() {
	cp_el_auth();
	wp_send_json_success(cp_portal_p_detail(cp_portal_p_deal_id()));
}

add_action('wp_ajax_nopriv_cp_portal_p_save', 'cp_portal_ajax_p_save');
add_action('wp_ajax_cp_portal_p_save', 'cp_portal_ajax_p_save');
function cp_portal_ajax_p_save() {
	cp_el_auth();
	$id = cp_portal_p_deal_id();
	$portal = cp_portal_get_by_deal($id);
	if (!$portal) { cp_el_err('Ehhez a dealhez nincs portál.', 404); }
	$key = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
	$raw = isset($_POST['value']) ? json_decode((string) wp_unslash($_POST['value']), true) : null;
	$r = cp_portal_save_field($portal, $key, $raw, 'iroda');
	if (is_wp_error($r)) { cp_el_err($r->get_error_message(), 400); }
	wp_send_json_success(cp_portal_p_detail($id));
}

add_action('wp_ajax_nopriv_cp_portal_p_create', 'cp_portal_ajax_p_create');
add_action('wp_ajax_cp_portal_p_create', 'cp_portal_ajax_p_create');
function cp_portal_ajax_p_create() {
	cp_el_auth();
	$id = cp_portal_p_deal_id();
	if (cp_portal_mode() === 'off') { cp_el_err('Az ügyfélportál ki van kapcsolva (WP admin → Beállítások → Ügyfélportál).', 409); }
	if (cp_portal_mode() === 'selected') {
		// dealenkénti kapcsoló: felvesszük a kiválasztottak közé
		$o = get_option(CP_PORTAL_OPT, array());
		if (!is_array($o)) { $o = array(); }
		$sel = cp_portal_selected_deals();
		$sel[$id] = 1;
		$o['deals'] = implode("\n", array_keys($sel));
		update_option(CP_PORTAL_OPT, $o, false);
	}
	$r = cp_portal_create($id, null, 'panel');
	if (is_wp_error($r)) { cp_el_err($r->get_error_message(), 400); }
	wp_send_json_success(cp_portal_p_detail($id));
}

add_action('wp_ajax_nopriv_cp_portal_p_link', 'cp_portal_ajax_p_link');
add_action('wp_ajax_cp_portal_p_link', 'cp_portal_ajax_p_link');
function cp_portal_ajax_p_link() {
	cp_el_auth();
	$id = cp_portal_p_deal_id();
	$portal = cp_portal_get_by_deal($id);
	if (!$portal) { cp_el_err('Ehhez a dealhez nincs portál.', 404); }
	$op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
	$extra = array();
	if ($op === 'new') {
		$url = cp_portal_issue_link($portal);
		if (is_wp_error($url)) { cp_el_err($url->get_error_message(), 500); }
		$extra['url'] = $url;
	} elseif ($op === 'send') {
		$to = cp_portal_send_link_mail($portal, 'resend', true);
		if (is_wp_error($to)) { cp_el_err($to->get_error_message(), 502); }
		cp_portal_notify_log($portal, 'resend', 'email', 'ok: ' . $to);
		$extra['msg'] = 'Új link elküldve: ' . $to;
	} elseif ($op === 'revoke') {
		$n = cp_portal_tokens_revoke($portal->id);
		$extra['msg'] = $n . ' link visszavonva.';
	} else {
		cp_el_err('Hibás kérés.');
	}
	wp_send_json_success(array_merge(cp_portal_p_detail($id), $extra));
}

/* kézi küldések naplózása (nem egyszeri típus, ezért egyedi kulccsal) */
function cp_portal_notify_log($portal, $type, $channel, $result) {
	global $wpdb;
	$wpdb->insert(cp_portal_t('notifications'), array('portal_id' => (int) $portal->id, 'type' => $type . ':' . time() . ':' . wp_rand(100, 999), 'channel' => $channel, 'sent_at' => cp_portal_db_now(), 'result' => mb_substr($result, 0, 250)));
}

add_action('wp_ajax_nopriv_cp_portal_p_errors', 'cp_portal_ajax_p_errors');
add_action('wp_ajax_cp_portal_p_errors', 'cp_portal_ajax_p_errors');
function cp_portal_ajax_p_errors() {
	cp_el_auth();
	if (!empty($_POST['clear'])) { update_option('cp_portal_errors', array(), false); }
	$q = get_option('cp_portal_smq', array());
	wp_send_json_success(array('errors' => cp_portal_errors(), 'queue' => is_array($q) ? array_values($q) : array()));
}
