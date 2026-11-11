<?php
/**
 * Crazy panel – Lomtár (v2.9.182): a törölt deal 30 napig visszaállítható.
 * Törléskor (a Salesmate-törlés előtt) elmentjük a deal Salesmate-adatait, a rendezvénynapokat és a Kukába tett levélszálakat.
 * Visszaállításkor a Salesmate-ben ÚJ deal jön létre (új azonosítóval) a mentett mezőkkel; a panel saját adatai (levelek,
 * beosztás, elszámolás, logisztika, feladatkártyák, előzmények, jegyzetek) a régi azonosítóról átkerülnek az újra.
 * Ami nem jön vissza: a Salesmate-beli jegyzetek, tevékenységek és előzmények, valamint az ügyfélportál (törléskor megszűnt).
 * Csak admin. Tárolás: cp_crm_bin opció (nem töltődik automatikusan), 30 nap után magától kiürül.
 */
if (!defined('ABSPATH')) { exit; }

const CP_BIN_DAYS = 30;

function cp_bin_get() {
	$b = get_option('cp_crm_bin', array());
	if (!is_array($b)) { $b = array(); }
	$lim = time() - CP_BIN_DAYS * DAY_IN_SECONDS; $ch = false;
	foreach ($b as $k => $e) { if ((int) ($e['ts'] ?? 0) < $lim) { unset($b[$k]); $ch = true; } }
	if ($ch) { update_option('cp_crm_bin', $b, false); }
	return $b;
}

/* törléskor, a Salesmate-törlés előtt hívja a cp_crm_ajax_delete */
function cp_bin_put($id, $plan) {
	global $wpdb;
	$id = (int) $id;
	$sm = cp_sm_get_deal($id);
	if (is_wp_error($sm) || !is_array($sm)) { $sm = null; }
	$row = $wpdb->get_row($wpdb->prepare('SELECT d.*, c.name cn FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE d.id = %d', $id), ARRAY_A);
	$days = array();
	if (function_exists('cp_mday_db_ok') && cp_mday_db_ok()) { $days = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cp_mday_t() . ' WHERE deal_id = %d', $id), ARRAY_A); }
	$b = cp_bin_get();
	$b[$id] = array(
		'ts' => time(), 'by' => function_exists('cp_panel_who') ? cp_panel_who() : '',
		'title' => (string) ($row['title'] ?? ($sm['title'] ?? '')), 'cn' => (string) ($row['cn'] ?? ''), 'event_d' => (string) ($row['event_d'] ?? ''),
		'value' => (float) ($row['value'] ?? 0), 'stage' => (string) ($row['stage'] ?? ''), 'status' => (string) ($row['status'] ?? ''),
		'sm' => $sm, 'row' => $row, 'days' => $days, 'threads' => array_map('intval', (array) ($plan['threads'] ?? array())), 'portal' => !empty($plan['portal']),
	);
	update_option('cp_crm_bin', $b, false);
}

/* ha a törlés mégsem sikerült, a mentés kikerül */
function cp_bin_drop($id) { $b = cp_bin_get(); if (isset($b[(int) $id])) { unset($b[(int) $id]); update_option('cp_crm_bin', $b, false); } }

/* a mentett Salesmate-adatból az új deal kérése (csak írható mezők) */
function cp_bin_body($e) {
	$o = cp_panel_opts();
	$sm = is_array($e['sm'] ?? null) ? $e['sm'] : array();
	$idv = function ($v) { return is_array($v) ? (isset($v['id']) ? (int) $v['id'] : null) : (is_numeric($v) ? (int) $v : null); };
	$body = array();
	foreach (array('title', 'currency', 'estimatedCloseDate', 'description', 'source', 'tags', 'status', 'lostReason', 'dealValue') as $k) {
		if (isset($sm[$k]) && $sm[$k] !== '' && $sm[$k] !== null && !is_array($sm[$k])) { $body[$k] = $sm[$k]; }
	}
	foreach (array('pipeline', 'stage') as $k) { if (isset($sm[$k]) && is_scalar($sm[$k]) && $sm[$k] !== '') { $body[$k] = $sm[$k]; } }
	foreach (array('primaryContact', 'primaryCompany', 'owner') as $k) { if (isset($sm[$k])) { $v = $idv($sm[$k]); if ($v) { $body[$k] = $v; } } }
	foreach ($sm as $k => $v) {
		if (!preg_match('/CustomField\d+$/', (string) $k) || $v === null || $v === '') { continue; }
		if (is_array($v)) { $v = isset($v['id']) ? $v['id'] : implode(',', array_map('cp_panel_str', $v)); }
		$body[$k] = $v;
	}
	/* ha nincs Salesmate-mentés: a panel tárolt sora */
	$r = is_array($e['row'] ?? null) ? $e['row'] : array();
	if (empty($body['title'])) { $body['title'] = (string) ($r['title'] ?? $e['title'] ?? 'Visszaállított deal'); }
	if (empty($body['pipeline'])) { $body['pipeline'] = $o['pipeline']; }
	if (empty($body['stage'])) { $body['stage'] = (string) ($r['stage'] ?? '') !== '' ? $r['stage'] : cp_crm_stages()[0]; }
	if (empty($body['primaryContact']) && !empty($r['contact_id'])) { $body['primaryContact'] = (int) $r['contact_id']; }
	if (empty($body['owner']) && !empty($r['owner_id'])) { $body['owner'] = (int) $r['owner_id']; }
	if (!isset($body['dealValue']) && isset($r['value']) && $r['value'] !== null) { $body['dealValue'] = (float) $r['value']; }
	if (empty($body['currency'])) { $body['currency'] = 'HUF'; }
	if (!$sm && $r) {
		foreach (cp_crm_edit_fields() as $col => $type) {
			if (in_array($col, array('title', 'owner_id', 'value'), true) || !isset($r[$col]) || (string) $r[$col] === '') { continue; }
			$v = cp_crm_to_sm($type, (string) ($type === 'time' ? substr((string) $r[$col], 11, 5) : $r[$col]), (string) ($r['event_d'] ?? ''));
			if ($v !== null && $v !== '') { $body[cp_crm_sm_field($col)] = $v; }
		}
	}
	return $body;
}

/* a panel táblái, amelyek a deal azonosítójára hivatkoznak (a régi → új átírásnál) */
function cp_bin_ref_tables() {
	global $wpdb;
	$out = array();
	foreach (array('mail_threads', 'mail_notes', 'mail_px', 'logi', 'kb_cards', 'st_acts', 'slot_asg', 'crm_history', 'billing', 'leads') as $t) {
		$n = $wpdb->prefix . 'crazy_' . $t;
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $n)) === $n) { $out[] = $n; }
	}
	return $out;
}

add_action('wp_ajax_nopriv_cp_crm_bin_list', 'cp_bin_ajax_list');
add_action('wp_ajax_cp_crm_bin_list', 'cp_bin_ajax_list');
function cp_bin_ajax_list() {
	cp_crm_ajax_guard(array('deals'));
	cp_admin_need();
	$out = array();
	foreach (cp_bin_get() as $id => $e) {
		$out[] = array('id' => (int) $id, 'title' => $e['title'], 'cn' => $e['cn'], 'event_d' => $e['event_d'], 'value' => $e['value'], 'stage' => $e['stage'], 'status' => $e['status'],
			'at' => wp_date('Y-m-d H:i', (int) $e['ts']), 'by' => $e['by'], 'left' => max(0, (int) ceil(((int) $e['ts'] + CP_BIN_DAYS * DAY_IN_SECONDS - time()) / DAY_IN_SECONDS)),
			'threads' => count($e['threads'] ?? array()), 'portal' => !empty($e['portal']), 'sm' => !empty($e['sm']));
	}
	usort($out, function ($a, $b) { return strcmp($b['at'], $a['at']); });
	wp_send_json_success(array('rows' => $out, 'days' => CP_BIN_DAYS));
}

add_action('wp_ajax_nopriv_cp_crm_bin_restore', 'cp_bin_ajax_restore');
add_action('wp_ajax_cp_crm_bin_restore', 'cp_bin_ajax_restore');
function cp_bin_ajax_restore() {
	cp_crm_ajax_guard(array('deals'));
	cp_admin_need();
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (empty(cp_panel_opts()['stage_write'])) { cp_el_err('A Salesmate-be írás a panel beállításaiban ki van kapcsolva.'); }
	$old = (int) ($_POST['id'] ?? 0);
	$b = cp_bin_get();
	if (!$old || empty($b[$old])) { cp_el_err('Ez a deal már nincs a Lomtárban (visszaállították, vagy letelt a 30 nap).', 404); }
	$e = $b[$old];
	/* kettős kattintás ellen: azonnal kivesszük, hiba esetén visszatesszük */
	unset($b[$old]); update_option('cp_crm_bin', $b, false);
	$putBack = function () use ($old, $e) { $x = cp_bin_get(); $x[$old] = $e; update_option('cp_crm_bin', $x, false); };
	$body = cp_bin_body($e);
	$dr = cp_sm_request('POST', '/apis/deal/v4', $body);
	$partial = false;
	if (is_wp_error($dr)) {
		/* ha egy mezőt nem fogad el a Salesmate: újra csak az alapadatokkal */
		$min = array_intersect_key($body, array_flip(array('title', 'pipeline', 'stage', 'primaryContact', 'owner', 'dealValue', 'currency')));
		$dr = cp_sm_request('POST', '/apis/deal/v4', $min);
		$partial = true;
		if (is_wp_error($dr)) { $putBack(); cp_el_err('A Salesmate-ben nem sikerült létrehozni: ' . $dr->get_error_message(), 502); }
	}
	$new = cp_crm_new_id($dr);
	if (!$new) { $putBack(); cp_el_err('A deal létrejöhetett, de a Salesmate nem adta vissza az azonosítóját – nézd meg a Salesmate-ben, mielőtt újra próbálod.', 502); }
	global $wpdb;
	if (function_exists('cp_crm_after_write')) { cp_crm_after_write($new); }
	/* a panel saját adatai a régi azonosítóról az újra */
	$moved = 0;
	foreach (cp_bin_ref_tables() as $t) { $moved += (int) $wpdb->query($wpdb->prepare("UPDATE $t SET deal_id = %d WHERE deal_id = %d", $new, $old)); }
	/* a deallel a Kukába tett levélszálak vissza a helyükre */
	$thr = array_map('intval', (array) ($e['threads'] ?? array()));
	if ($thr && function_exists('cp_mail_t')) { $wpdb->query('UPDATE ' . cp_mail_t('threads') . " SET folder = IF(prev_folder IN ('', 'trash'), 'inbox', prev_folder) WHERE folder = 'trash' AND id IN (" . implode(',', $thr) . ')'); }
	/* rendezvénynapok */
	$nd = 0;
	if (!empty($e['days']) && function_exists('cp_mday_db_ok') && cp_mday_db_ok()) {
		foreach ((array) $e['days'] as $d) { unset($d['id']); $d['deal_id'] = $new; if ($wpdb->insert(cp_mday_t(), $d)) { $nd++; } }
	}
	$wpdb->insert(cp_crm_t('history'), array('deal_id' => $new, 'field' => 'restored', 'old_val' => '#' . $old, 'new_val' => 'visszaállítva a Lomtárból', 'by_user' => function_exists('cp_panel_who') ? cp_panel_who() : 'Crazy panel', 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel'));
	if (function_exists('cp_crm_rev_bump')) { cp_crm_rev_bump(); }
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'bin_restore', 'Deal visszaállítása a Lomtárból', 'deal:' . $new, '#' . $old . ' → #' . $new . ($partial ? ' (csak alapadatok)' : '')); }
	wp_send_json_success(array('id' => $new, 'old' => $old, 'partial' => $partial, 'moved' => $moved, 'days' => $nd, 'threads' => count($thr), 'portal' => !empty($e['portal'])));
}
