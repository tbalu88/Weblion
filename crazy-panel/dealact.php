<?php
/* =====================================================================
 * Tevékenységek és jegyzetek a dealen (v1.51)
 * A panelből felvett tevékenység és jegyzet a Salesmate-be is beíródik (párhuzamos működés: az SM naptárszinkronja
 * és automatizmusai ugyanúgy látják), és azonnal megjelenik a deal idővonalán. A deal Salesmate-jegyzetei is
 * áthozódnak (megnyitáskor, legfeljebb 5 percenként), így a panel idővonala teljes.
 * Írás csak akkor, ha a „Szakasz visszaírása” (stage_write) be van kapcsolva – ugyanaz a feltétel, mint a deal szerkesztésénél.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_DACT_DB_VER', '1');
function cp_dact_t() { global $wpdb; return $wpdb->prefix . 'crazy_crm_notes'; }

add_action('init', function () {
	if (get_option('cp_dact_db_ver') === CP_DACT_DB_VER || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_dact_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  deal_id bigint(20) unsigned NOT NULL,
  sm_id bigint(20) unsigned NULL,
  body text NULL,
  author varchar(100) NOT NULL DEFAULT '',
  author_uid int(11) NULL,
  src varchar(8) NOT NULL DEFAULT '',
  created_at datetime NULL,
  deleted_at datetime NULL,
  PRIMARY KEY  (id),
  KEY deal_id (deal_id),
  KEY sm_id (sm_id)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_dact_db_ver', CP_DACT_DB_VER, false);
}, 7);
function cp_dact_db_ok() { return get_option('cp_dact_db_ver') === CP_DACT_DB_VER; }

function cp_dact_auth_write() {
	cp_crm_ajax_guard();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (empty(cp_panel_opts()['stage_write'])) { cp_el_err('A Salesmate-be írás a panel beállításaiban ki van kapcsolva.'); }
	if (!cp_dact_db_ok()) { cp_el_err('Az adatbázis még készül – töltsd újra az oldalt pár másodperc múlva.', 503); }
}
function cp_dact_me() { return function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0; }
function cp_dact_who() { return function_exists('cp_panel_who') ? cp_panel_who() : ''; }
function cp_dact_deal($id) {
	global $wpdb;
	return $id ? $wpdb->get_row($wpdb->prepare('SELECT id, title, contact_id, owner_id FROM ' . cp_crm_t('deals') . ' WHERE id = %d AND deleted_at IS NULL', $id), ARRAY_A) : null;
}
/* a Salesmate HTML-jegyzete sima szöveggé */
function cp_dact_plain($html) {
	$t = preg_replace('#<\s*(br|/p|/div|/li)\b[^>]*>#i', "\n", (string) $html);
	$t = html_entity_decode(wp_strip_all_tags($t), ENT_QUOTES, 'UTF-8');
	return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/u', ' ', $t)));
}

/* ------------------------------------------------------------ a deal Salesmate-jegyzeteinek áthozása (5 percenként legfeljebb) */
function cp_dact_pull($deal) {
	global $wpdb;
	if (!cp_dact_db_ok() || get_transient('cp_dact_p' . $deal)) { return; }
	set_transient('cp_dact_p' . $deal, 1, 5 * MINUTE_IN_SECONDS);
	$r = cp_sm_request('GET', '/apis/module/v4/modules/4/objects/' . (int) $deal . '/notes');
	if (is_wp_error($r) || empty($r['Data']) || !is_array($r['Data'])) { return; }
	$T = cp_dact_t();
	$have = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT sm_id FROM $T WHERE deal_id = %d AND sm_id IS NOT NULL", $deal)));
	$live = array();
	foreach ($r['Data'] as $n) {
		$sid = (int) ($n['id'] ?? 0);
		if (!$sid) { continue; }
		$live[] = $sid;
		if (in_array($sid, $have, true)) { continue; }
		$ts = !empty($n['createAt']) ? strtotime((string) $n['createAt']) : time();
		$wpdb->insert($T, array('deal_id' => $deal, 'sm_id' => $sid, 'body' => mb_substr(cp_dact_plain($n['note'] ?? ''), 0, 5000), 'author' => mb_substr((string) ($n['createdBy']['name'] ?? ''), 0, 100), 'src' => 'sm', 'created_at' => gmdate('Y-m-d H:i:s', $ts ?: time())));
	}
	/* a Salesmate-ben törölt jegyzet itt is eltűnik */
	if ($have) {
		$gone = array_diff($have, $live);
		if ($gone) { $wpdb->query("UPDATE $T SET deleted_at = UTC_TIMESTAMP() WHERE deleted_at IS NULL AND sm_id IN (" . implode(',', array_map('intval', $gone)) . ')'); }
	}
}
function cp_dact_notes($deal) {
	global $wpdb;
	if (!cp_dact_db_ok()) { return array(); }
	$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, body, author, author_uid, src, created_at FROM ' . cp_dact_t() . ' WHERE deal_id = %d AND deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 200', $deal), ARRAY_A);
	$me = cp_dact_me();
	foreach ($rows as &$n) { $n['id'] = (int) $n['id']; $n['mine'] = $me > 0 && (int) $n['author_uid'] === $me ? 1 : 0; $n['at'] = cp_crm_local($n['created_at']); unset($n['created_at'], $n['author_uid']); }
	unset($n);
	return $rows;
}
add_filter('cp_crm_deal_data', function ($x) {
	$id = (int) $x['deal']['id'];
	cp_dact_pull($id);
	$x['dnotes'] = cp_dact_notes($id);
	$x['act_types'] = cp_dact_types();
	return $x;
}, 20);
function cp_dact_types() {
	global $wpdb;
	$t = (array) $wpdb->get_col('SELECT type FROM ' . cp_crm_t('activities') . " WHERE deleted_at IS NULL AND type <> '' GROUP BY type ORDER BY COUNT(*) DESC LIMIT 12");
	return $t ?: array('Task', 'Call');
}

/* ------------------------------------------------------------ jegyzet a dealen */
add_action('wp_ajax_nopriv_cp_crm_note_add', 'cp_dact_ajax_note_add');
add_action('wp_ajax_cp_crm_note_add', 'cp_dact_ajax_note_add');
function cp_dact_ajax_note_add() {
	cp_dact_auth_write();
	global $wpdb;
	$deal = cp_dact_deal((int) ($_POST['deal'] ?? 0));
	if (!$deal) { cp_el_err('Nincs ilyen deal.', 404); }
	$body = trim(sanitize_textarea_field(wp_unslash($_POST['body'] ?? '')));
	if ($body === '') { cp_el_err('Üres a jegyzet.'); }
	$body = mb_substr($body, 0, 5000);
	$r = cp_sm_request('POST', '/apis/deal/v4/modules/4/object/' . (int) $deal['id'] . '/notes', array('note' => '<div>' . nl2br(esc_html($body)) . '</div>', 'attachments' => array(), 'type' => 'Note'));
	if (is_wp_error($r)) { cp_el_err('A Salesmate nem fogadta a jegyzetet: ' . $r->get_error_message(), 502); }
	$sid = (int) ($r['Data']['noteId'] ?? ($r['Data']['id'] ?? 0));
	$wpdb->insert(cp_dact_t(), array('deal_id' => (int) $deal['id'], 'sm_id' => $sid ?: null, 'body' => $body, 'author' => cp_dact_who(), 'author_uid' => cp_dact_me() ?: null, 'src' => 'panel', 'created_at' => gmdate('Y-m-d H:i:s')));
	/* @Név megemlítés: értesítés a kollégának */
	if (function_exists('cp_notify') && function_exists('cp_pu_data')) {
		foreach (cp_pu_data()['list'] as $uid => $u) {
			if (empty($u['a']) || (int) $uid === cp_dact_me()) { continue; }
			if (mb_stripos($body, '@' . $u['n']) !== false) { cp_notify((int) $uid, 'deal_note', 'megemlített egy deal jegyzetében: „' . mb_substr($deal['title'], 0, 90) . '”', 'deals:' . (int) $deal['id']); }
		}
	}
	wp_send_json_success(array('notes' => cp_dact_notes((int) $deal['id'])));
}
add_action('wp_ajax_nopriv_cp_crm_note_del', 'cp_dact_ajax_note_del');
add_action('wp_ajax_cp_crm_note_del', 'cp_dact_ajax_note_del');
function cp_dact_ajax_note_del() {
	cp_dact_auth_write();
	global $wpdb;
	$n = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_dact_t() . ' WHERE id = %d AND deleted_at IS NULL', (int) ($_POST['id'] ?? 0)), ARRAY_A);
	if (!$n) { cp_el_err('Nincs ilyen jegyzet.', 404); }
	$admin = function_exists('cp_panel_is_admin') && cp_panel_is_admin();
	if (!$admin && ((int) $n['author_uid'] !== cp_dact_me() || cp_dact_me() <= 0)) { cp_el_err('Csak a saját jegyzetedet törölheted.', 403); }
	if ($n['sm_id']) {
		$r = cp_sm_request('DELETE', '/apis/deal/v4/modules/4/object/' . (int) $n['deal_id'] . '/notes/' . (int) $n['sm_id']);
		if (is_wp_error($r) && strpos($r->get_error_message(), '(404)') === false) { cp_el_err('A Salesmate-ben nem sikerült törölni: ' . $r->get_error_message(), 502); }
	}
	$wpdb->update(cp_dact_t(), array('deleted_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $n['id']));
	wp_send_json_success(array('notes' => cp_dact_notes((int) $n['deal_id'])));
}

/* ------------------------------------------------------------ tevékenység: felvétel, szerkesztés, késznek jelölés */
function cp_dact_due_iso($date, $time) {
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return null; }
	if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) { return $date . 'T00:00:00.000Z'; } /* egész napos: ahogy a Salesmate is tárolja */
	$dt = new DateTime($date . ' ' . str_pad($time, 5, '0', STR_PAD_LEFT) . ':00', wp_timezone());
	return gmdate('Y-m-d\TH:i:s.000\Z', $dt->getTimestamp());
}
function cp_dact_refresh_act($id) {
	$a = cp_sm_request('GET', '/apis/activity/v4/' . (int) $id);
	if (!is_wp_error($a) && !empty($a['Data']) && is_array($a['Data'])) { cp_crm_store('activity', array($a['Data']), 'get'); return true; }
	return false;
}
add_action('wp_ajax_nopriv_cp_crm_act_save', 'cp_dact_ajax_act_save');
add_action('wp_ajax_cp_crm_act_save', 'cp_dact_ajax_act_save');
function cp_dact_ajax_act_save() {
	cp_dact_auth_write();
	global $wpdb;
	$deal = cp_dact_deal((int) ($_POST['deal'] ?? 0));
	if (!$deal) { cp_el_err('Nincs ilyen deal.', 404); }
	$id = (int) ($_POST['id'] ?? 0);
	$title = trim(mb_substr(sanitize_text_field(wp_unslash($_POST['title'] ?? '')), 0, 490));
	$type = trim(mb_substr(sanitize_text_field(wp_unslash($_POST['type'] ?? '')), 0, 200));
	$owner = (int) ($_POST['owner'] ?? 0);
	if ($title === '' || $type === '') { cp_el_err('A cím és a típus kötelező.'); }
	if (!$owner) { $owner = (int) $deal['owner_id']; }
	if (!$owner || !array_key_exists($owner, cp_crm_users())) { cp_el_err('Válassz felelőst.'); }
	$due = cp_dact_due_iso((string) ($_POST['due'] ?? ''), (string) ($_POST['time'] ?? ''));
	$body = array('title' => $title, 'type' => $type, 'owner' => $owner, 'description' => mb_substr(sanitize_textarea_field(wp_unslash($_POST['descr'] ?? '')), 0, 2000), 'duration' => max(0, min(1440, (int) ($_POST['dur'] ?? 30))), 'isCalendarInvite' => false);
	if ($due) { $body['dueDate'] = $due; }
	if ($id) {
		$old = $wpdb->get_row($wpdb->prepare('SELECT id, completed FROM ' . cp_crm_t('activities') . ' WHERE id = %d AND related_id = %d', $id, (int) $deal['id']), ARRAY_A);
		if (!$old) { cp_el_err('Ez a tevékenység nem ehhez a dealhez tartozik.', 404); }
		$body['isCompleted'] = (bool) $old['completed'];
		$r = cp_sm_request('PUT', '/apis/activity/v4/' . $id, $body);
	} else {
		$body['isCompleted'] = false;
		$body['relatedTo'] = (int) $deal['id'];
		if ($deal['contact_id']) { $body['primaryContact'] = (int) $deal['contact_id']; }
		$r = cp_sm_request('POST', '/apis/activity/v4', $body);
		if (!is_wp_error($r)) { $id = (int) ($r['Data']['id'] ?? 0); }
	}
	if (is_wp_error($r)) { cp_el_err('A Salesmate nem fogadta a tevékenységet: ' . $r->get_error_message(), 502); }
	if ($id) { cp_dact_refresh_act($id); }
	wp_send_json_success(array('id' => $id));
}
add_action('wp_ajax_nopriv_cp_crm_act_done', 'cp_dact_ajax_act_done');
add_action('wp_ajax_cp_crm_act_done', 'cp_dact_ajax_act_done');
function cp_dact_ajax_act_done() {
	cp_dact_auth_write();
	global $wpdb;
	$a = $wpdb->get_row($wpdb->prepare('SELECT id, title, type, owner_id, related_id FROM ' . cp_crm_t('activities') . ' WHERE id = %d AND deleted_at IS NULL', (int) ($_POST['id'] ?? 0)), ARRAY_A);
	if (!$a) { cp_el_err('Nincs ilyen tevékenység.', 404); }
	$done = !empty($_POST['done']);
	$r = cp_sm_request('PUT', '/apis/activity/v4/' . (int) $a['id'], array('title' => $a['title'], 'type' => $a['type'] !== '' ? $a['type'] : 'Task', 'owner' => (int) $a['owner_id'], 'isCompleted' => $done));
	if (is_wp_error($r)) { cp_el_err('A Salesmate nem fogadta: ' . $r->get_error_message(), 502); }
	if (!cp_dact_refresh_act((int) $a['id'])) { $wpdb->update(cp_crm_t('activities'), array('completed' => $done ? 1 : 0, 'completed_at' => $done ? gmdate('Y-m-d H:i:s') : null), array('id' => (int) $a['id'])); }
	wp_send_json_success(array('id' => (int) $a['id'], 'done' => $done ? 1 : 0));
}

/* ------------------------------------------------------------ v1.53: a Salesmate deal-jegyzeteinek egyszeri áthozatala a háttérben
 * (hogy a kereső a még meg nem nyitott dealek jegyzeteiben is találjon). Csak azoknál a dealeknél kérdez, ahol a Salesmate
 * szerint van jegyzet; percenként legfeljebb 15 deal. Utána a deal megnyitásakor frissül, mint eddig. */
add_action('init', function () {
	if (!cp_dact_db_ok() || wp_doing_ajax()) { return; }
	$bf = get_option('cp_dact_bf', array());
	if (!empty($bf['done'])) { return; }
	if (!wp_next_scheduled('cp_dact_bf')) { wp_schedule_event(time() + 60, 'cp_minute', 'cp_dact_bf'); }
}, 30);
add_action('cp_dact_bf', 'cp_dact_backfill');
function cp_dact_backfill() {
	global $wpdb;
	if (!cp_dact_db_ok() || !function_exists('cp_crm_db_ok') || !cp_crm_db_ok() || get_transient('cp_dact_bf_lock')) { return; }
	set_transient('cp_dact_bf_lock', 1, 55);
	$bf = get_option('cp_dact_bf', array());
	if (!is_array($bf)) { $bf = array(); }
	$last = isset($bf['last']) ? (int) $bf['last'] : PHP_INT_MAX;
	$ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . cp_crm_t('deals') . " WHERE deleted_at IS NULL AND (last_note <> '' OR last_note_at IS NOT NULL) AND id < %d ORDER BY id DESC LIMIT 15", min($last, 2147483647))));
	$t0 = time();
	foreach ($ids as $id) {
		if (time() - $t0 > 45) { break; }
		cp_dact_pull($id);
		$bf['last'] = $id; $bf['n'] = (int) ($bf['n'] ?? 0) + 1;
	}
	if (!$ids) { $bf['done'] = time(); wp_clear_scheduled_hook('cp_dact_bf'); }
	update_option('cp_dact_bf', $bf, false);
	delete_transient('cp_dact_bf_lock');
}
