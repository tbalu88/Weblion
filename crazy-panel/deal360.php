<?php
/* =====================================================================
 * Deal 360° (v1.48) – a deal-adatlapon minden összefut: a deal mezői, a kontakt és a cég, a korábbi munkák
 * (a kontakt és a cég többi deal-je), a levelek és jegyzetek, a tevékenységek, a feladatkártyák, a webes
 * megkeresések, az automatizmusok bejegyzései és a változásnapló. Plusz kontakt- és cégnézet.
 * Csak olvas; a Salesmate-hez nem nyúl.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

/* egy deal-lista összesítője: darab, megnyert / nyitott / elvesztett és összegük */
function cp_d360_stats($deals) {
	$s = array('n' => 0, 'won' => 0, 'won_v' => 0.0, 'open' => 0, 'open_v' => 0.0, 'lost' => 0, 'first' => '', 'last_ev' => '');
	foreach ($deals as $d) {
		$s['n']++;
		if ($d['status'] === 'Won') { $s['won']++; $s['won_v'] += (float) $d['value']; }
		elseif ($d['status'] === 'Lost') { $s['lost']++; }
		else { $s['open']++; $s['open_v'] += (float) $d['value']; }
		if (!empty($d['created_at']) && ($s['first'] === '' || $d['created_at'] < $s['first'])) { $s['first'] = $d['created_at']; }
		if (!empty($d['event_d']) && $d['event_d'] > $s['last_ev']) { $s['last_ev'] = $d['event_d']; }
	}
	return $s;
}
function cp_d360_deals($where) {
	global $wpdb;
	$rows = (array) $wpdb->get_results('SELECT d.id, d.title, d.stage, d.status, d.value, d.currency, d.event_d, d.venue, d.service, d.owner_id, d.contact_id, d.created_at, d.closed_at, c.name cn FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . " c ON c.id = d.contact_id WHERE d.deleted_at IS NULL AND ($where) ORDER BY COALESCE(d.event_d, DATE(d.created_at)) DESC, d.id DESC LIMIT 300", ARRAY_A);
	foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['value'] = $r['value'] === null ? null : (float) $r['value']; $r['owner'] = cp_crm_user_name($r['owner_id']); $r['created_at'] = cp_crm_local($r['created_at']); $r['closed_at'] = cp_crm_local($r['closed_at']); }
	unset($r);
	return $rows;
}

/* levélszálak és levelek: a deal-hez csatoltak, illetve a kontakt e-mail-címével érkezett, máshoz nem csatolt szálak */
function cp_d360_mail($thread_where, $msg_limit = 80) {
	global $wpdb;
	if (!function_exists('cp_mail_db_ok') || !cp_mail_db_ok() || (function_exists('cp_panel_can') && !cp_panel_can('mail'))) { return array('threads' => array(), 'msgs' => array(), 'notes' => array(), 'files' => array(), 'off' => 1); }
	$T = cp_mail_t('threads');
	$threads = (array) $wpdb->get_results("SELECT id, subject, contact_name, contact_email, deal_id, assignee_uid, status, folder, msg_count, last_at, snippet FROM $T WHERE folder NOT IN ('trash', 'spam') AND ($thread_where) ORDER BY last_at DESC LIMIT 100", ARRAY_A);
	$ids = array_map(function ($t) { return (int) $t['id']; }, $threads);
	$out = array('threads' => array(), 'msgs' => array(), 'notes' => array(), 'files' => array());
	foreach ($threads as $t) { $out['threads'][] = array('id' => (int) $t['id'], 'subj' => $t['subject'], 'ce' => $t['contact_email'], 'deal' => (int) $t['deal_id'], 'as' => (int) $t['assignee_uid'], 'st' => $t['status'], 'folder' => $t['folder'], 'cnt' => (int) $t['msg_count'], 'at' => cp_crm_local($t['last_at'])); }
	if (!$ids) { return $out; }
	$in = implode(',', $ids);
	$msgs = (array) $wpdb->get_results('SELECT id, thread_id, dir, from_name, from_email, subject, body, attachments, status, sent_at FROM ' . cp_mail_t('messages') . " WHERE thread_id IN ($in) ORDER BY sent_at DESC, id DESC LIMIT " . (int) $msg_limit, ARRAY_A);
	foreach ($msgs as $m) {
		$txt = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(str_replace(array('<br>', '</p>'), array("\n", "\n"), (string) $m['body']))));
		$att = json_decode((string) $m['attachments'], true) ?: array();
		$out['msgs'][] = array('id' => (int) $m['id'], 'th' => (int) $m['thread_id'], 'dir' => $m['dir'], 'from' => $m['from_name'] !== '' ? $m['from_name'] : $m['from_email'], 'subj' => $m['subject'], 'snip' => mb_substr($txt, 0, 260), 'att' => count($att), 'q' => $m['status'] === 'idézetből' ? 1 : 0, 'at' => cp_crm_local($m['sent_at']));
		foreach ($att as $a) { if (count($out['files']) < 60 && !empty($a['n'])) { $out['files'][] = array('n' => (string) $a['n'], 'th' => (int) $m['thread_id'], 'at' => cp_crm_local($m['sent_at']), 'from' => $m['from_name']); } }
	}
	$notes = (array) $wpdb->get_results('SELECT id, thread_id, body, author, created_at FROM ' . cp_mail_t('notes') . " WHERE deleted_at IS NULL AND thread_id IN ($in) ORDER BY id DESC LIMIT 60", ARRAY_A);
	foreach ($notes as $n) { $out['notes'][] = array('id' => (int) $n['id'], 'th' => (int) $n['thread_id'], 'b' => mb_substr((string) $n['body'], 0, 1200), 'by' => $n['author'], 'at' => cp_crm_local($n['created_at'])); }
	return $out;
}

/* a deal-adatlap kiegészítése (a cp_crm_deal válaszába) */
add_filter('cp_crm_deal_data', function ($x) {
	global $wpdb;
	$d = $x['deal']; $id = (int) $d['id']; $c = $x['contact']; $co = $x['company'];
	/* korábbi munkák: a kontakt és a cég összes deal-je (ez is benne, hogy az összesítő teljes legyen) */
	$x['ct_deals'] = $c ? cp_d360_deals($wpdb->prepare('d.contact_id = %d', $c['id'])) : array();
	$x['ct_stats'] = cp_d360_stats($x['ct_deals']);
	$x['co_deals'] = array(); $x['co_stats'] = null; $x['co_contacts'] = array();
	if ($co) {
		$cw = $wpdb->prepare('d.company_id = %d OR c.company_id = %d', $co['id'], $co['id']);
		$x['co_deals'] = cp_d360_deals($cw);
		$x['co_stats'] = cp_d360_stats($x['co_deals']);
		$x['co_contacts'] = (array) $wpdb->get_results($wpdb->prepare('SELECT id, name, email FROM ' . cp_crm_t('contacts') . ' WHERE company_id = %d AND deleted_at IS NULL ORDER BY name LIMIT 50', $co['id']), ARRAY_A);
	}
	/* levelezés */
	$tw = $wpdb->prepare('deal_id = %d', $id);
	if ($c && !empty($c['email'])) { $tw .= $wpdb->prepare(' OR (deal_id IS NULL AND contact_email = %s)', strtolower($c['email'])); }
	$x['mail'] = cp_d360_mail($tw);
	/* feladatkártyák */
	$x['cards'] = array();
	if (function_exists('cp_kb_t') && get_option('cp_kb_db_ver')) {
		$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT k.id, k.title, k.assignees, k.due, k.done, k.created_by, k.created_at, l.name list FROM ' . cp_kb_t('cards') . ' k LEFT JOIN ' . cp_kb_t('lists') . ' l ON l.id = k.list_id WHERE k.deal_id = %d AND k.archived_at IS NULL ORDER BY k.id DESC LIMIT 50', $id), ARRAY_A);
		foreach ($rows as $r) {
			$as = array();
			foreach (array_filter(array_map('intval', explode(',', (string) $r['assignees']))) as $u) { $p = function_exists('cp_pu_get') ? cp_pu_get($u) : null; if ($p) { $as[] = $p['n']; } }
			$x['cards'][] = array('id' => (int) $r['id'], 'title' => $r['title'], 'list' => (string) $r['list'], 'due' => $r['due'], 'done' => (int) $r['done'], 'as' => $as, 'by' => $r['created_by'], 'at' => cp_crm_local($r['created_at']));
		}
	}
	/* webes megkeresések (űrlap) */
	$x['leads'] = array();
	if (function_exists('cp_lead_t') && function_exists('cp_lead_db_ok') && cp_lead_db_ok()) {
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, form_name, data, page, created_at FROM ' . cp_lead_t() . " WHERE deal_id = %d AND status <> 'spam' ORDER BY id DESC LIMIT 10", $id), ARRAY_A) as $l) {
			$f = json_decode((string) $l['data'], true);
			$kv = array();
			if (is_array($f)) { foreach ($f as $k => $v) { if (is_scalar($v) && trim((string) $v) !== '' && count($kv) < 14) { $kv[] = array(mb_substr((string) $k, 0, 60), mb_substr((string) $v, 0, 300)); } } }
			$x['leads'][] = array('id' => (int) $l['id'], 'form' => $l['form_name'], 'kv' => $kv, 'at' => cp_crm_local($l['created_at']));
		}
	}
	/* automatizmusok */
	$x['flows'] = array();
	if (function_exists('cp_flow_t') && get_option('cp_flow_db_ver')) {
		$fn = function_exists('cp_flow_defaults') ? cp_flow_defaults() : array();
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT flow, mode, action, detail, result, created_at FROM ' . cp_flow_t() . ' WHERE deal_id = %d ORDER BY id DESC LIMIT 20', $id), ARRAY_A) as $f) {
			$x['flows'][] = array('n' => isset($fn[$f['flow']]['n']) ? $fn[$f['flow']]['n'] : $f['flow'], 'mode' => $f['mode'], 'res' => $f['result'], 'detail' => mb_substr((string) $f['detail'], 0, 300), 'at' => cp_crm_local($f['created_at']));
		}
	}
	/* mióta van a jelenlegi szakaszban */
	$x['stage_since'] = cp_crm_local($wpdb->get_var($wpdb->prepare('SELECT at FROM ' . cp_crm_t('history') . " WHERE deal_id = %d AND field = 'stage' ORDER BY at DESC, id DESC LIMIT 1", $id)));
	return $x;
});

/* ------------------------------------------------------------ kontakt- és cégnézet */
add_action('wp_ajax_nopriv_cp_crm_contact', 'cp_d360_ajax_contact');
add_action('wp_ajax_cp_crm_contact', 'cp_d360_ajax_contact');
function cp_d360_ajax_contact() {
	cp_crm_ajax_guard();
	global $wpdb;
	$id = (int) ($_REQUEST['id'] ?? 0);
	$c = $id ? $wpdb->get_row($wpdb->prepare('SELECT id, name, email, phone, mobile, company_id, type, tags, city, utm_source, utm_medium, utm_campaign, description, last_note, created_at FROM ' . cp_crm_t('contacts') . ' WHERE id = %d', $id), ARRAY_A) : null;
	if (!$c) { cp_el_err('Nincs ilyen kontakt a panel adatai között.', 404); }
	$c['created_at'] = cp_crm_local($c['created_at']);
	$co = $c['company_id'] ? $wpdb->get_row($wpdb->prepare('SELECT id, name, website, phone, type FROM ' . cp_crm_t('companies') . ' WHERE id = %d', $c['company_id']), ARRAY_A) : null;
	$deals = cp_d360_deals($wpdb->prepare('d.contact_id = %d', $id));
	$mail = !empty($c['email']) ? cp_d360_mail($wpdb->prepare('contact_email = %s', strtolower($c['email'])), 40) : array('threads' => array(), 'msgs' => array(), 'notes' => array(), 'files' => array());
	wp_send_json_success(array('contact' => $c, 'company' => $co, 'deals' => $deals, 'stats' => cp_d360_stats($deals), 'mail' => $mail));
}
add_action('wp_ajax_nopriv_cp_crm_company', 'cp_d360_ajax_company');
add_action('wp_ajax_cp_crm_company', 'cp_d360_ajax_company');
function cp_d360_ajax_company() {
	cp_crm_ajax_guard();
	global $wpdb;
	$id = (int) ($_REQUEST['id'] ?? 0);
	$co = $id ? $wpdb->get_row($wpdb->prepare('SELECT id, name, website, phone, type FROM ' . cp_crm_t('companies') . ' WHERE id = %d', $id), ARRAY_A) : null;
	if (!$co) { cp_el_err('Nincs ilyen cég a panel adatai között.', 404); }
	$contacts = (array) $wpdb->get_results($wpdb->prepare('SELECT id, name, email, phone, mobile FROM ' . cp_crm_t('contacts') . ' WHERE company_id = %d AND deleted_at IS NULL ORDER BY name LIMIT 100', $id), ARRAY_A);
	$deals = cp_d360_deals($wpdb->prepare('d.company_id = %d OR c.company_id = %d', $id, $id));
	$em = array_values(array_filter(array_map(function ($c) { return strtolower((string) $c['email']); }, $contacts)));
	$mail = $em ? cp_d360_mail('contact_email IN (' . implode(',', array_map(function ($e) use ($wpdb) { return $wpdb->prepare('%s', $e); }, array_slice($em, 0, 50))) . ')', 40) : array('threads' => array(), 'msgs' => array(), 'notes' => array(), 'files' => array());
	wp_send_json_success(array('company' => $co, 'contacts' => $contacts, 'deals' => $deals, 'stats' => cp_d360_stats($deals), 'mail' => $mail));
}

/* cég- / kontaktkereső a deal-lista szűrőjéhez */
add_action('wp_ajax_nopriv_cp_crm_who', 'cp_d360_ajax_who');
add_action('wp_ajax_cp_crm_who', 'cp_d360_ajax_who');
function cp_d360_ajax_who() {
	cp_crm_ajax_guard();
	global $wpdb;
	$q = trim(mb_substr((string) wp_unslash($_REQUEST['q'] ?? ''), 0, 60));
	if (mb_strlen($q) < 2) { wp_send_json_success(array('rows' => array())); }
	$l = '%' . $wpdb->esc_like($q) . '%';
	$out = array();
	foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT co.id, co.name, COUNT(DISTINCT d.id) n FROM ' . cp_crm_t('companies') . ' co LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.company_id = co.id LEFT JOIN ' . cp_crm_t('deals') . ' d ON (d.company_id = co.id OR d.contact_id = c.id) AND d.deleted_at IS NULL WHERE co.name LIKE %s GROUP BY co.id ORDER BY n DESC LIMIT 8', $l), ARRAY_A) as $r) { $out[] = array('k' => 'co', 'id' => (int) $r['id'], 'n' => $r['name'], 'c' => (int) $r['n']); }
	foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT c.id, c.name, c.email, COUNT(d.id) n FROM ' . cp_crm_t('contacts') . ' c LEFT JOIN ' . cp_crm_t('deals') . ' d ON d.contact_id = c.id AND d.deleted_at IS NULL WHERE (c.name LIKE %s OR c.email LIKE %s) AND c.deleted_at IS NULL GROUP BY c.id ORDER BY n DESC LIMIT 8', $l, $l), ARRAY_A) as $r) { $out[] = array('k' => 'ct', 'id' => (int) $r['id'], 'n' => $r['name'] !== '' ? $r['name'] : $r['email'], 'e' => $r['email'], 'c' => (int) $r['n']); }
	wp_send_json_success(array('rows' => $out));
}
