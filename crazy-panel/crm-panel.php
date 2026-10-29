<?php
/* =====================================================================
 * CRM – a panel „Deal-ek” füle (v1.25, csak olvasás)
 * Lista keresővel és szűrőkkel, pipeline-tábla, deal-adatlap a saját táblákból (crm.php).
 * Ebben a lépésben semmit nem ír: módosítani egyelőre a Salesmate-ben lehet,
 * a változás a 10 perces szinkronnal (vagy a Frissítés gombbal) jön át.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

/* a fő pipeline szakaszai sorrendben (a Salesmate beállításai szerint, 2026. okt.) */
function cp_crm_stages() {
	return array('Érdeklődő / Megrendelő', 'Ajánlat kiküldve', 'Adatlap kiküldve', 'Szerződés kiküldve', 'Szerződés aláírva', 'Rendezvény felkészül', 'Rendezvény lement (G&R)', 'Pénzre várunk');
}

function cp_crm_panel_on() {
	if (!function_exists('cp_crm_db_ok') || !cp_crm_db_ok()) { return false; }
	$st = cp_crm_state();
	return !empty($st['deal_n']);
}

function cp_crm_panel_cfg() {
	if (!cp_crm_panel_on()) { return array('on' => false); }
	$st = cp_crm_state();
	$u = array();
	foreach (cp_crm_users() as $id => $x) { $u[(string) $id] = array('n' => $x['n'], 'a' => (int) $x['a']); }
	return array(
		'on'     => true,
		'css'    => plugins_url('assets/crm.css', __FILE__) . '?v=' . CP_PANEL_VER,
		'stages' => cp_crm_stages(),
		'users'  => $u,
		'sync'   => isset($st['last_ok']) ? (int) $st['last_ok'] : 0,
		'write'  => !empty(cp_panel_opts()['stage_write']),
	);
}

/* datetime (UTC) → helyi idő szövegként */
function cp_crm_local($dt) {
	if (!$dt) { return ''; }
	$d = date_create($dt, new DateTimeZone('UTC'));
	if (!$d) { return ''; }
	$d->setTimezone(wp_timezone());
	return $d->format('Y-m-d H:i');
}

/* ------------------------------------------------------------ AJAX: lista */

add_action('wp_ajax_nopriv_cp_crm_list', 'cp_crm_ajax_list');
add_action('wp_ajax_cp_crm_list', 'cp_crm_ajax_list');
function cp_crm_ajax_list() {
	cp_crm_ajax_guard();
	wp_send_json_success(cp_crm_list_data(wp_unslash($_REQUEST)));
}

function cp_crm_ajax_guard() {
	cp_el_auth();
	if (!cp_crm_panel_on()) { cp_el_err('A CRM-adatok még töltődnek – próbáld újra pár perc múlva.', 503); }
}

function cp_crm_req_date($a, $k) {
	$d = isset($a[$k]) ? (string) $a[$k] : '';
	return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
}

/* $a: a kérés paraméterei (q, st, stage, own, ev, f, to, so, dir, p) */
function cp_crm_list_data($a) {
	global $wpdb;
	$d = cp_crm_t('deals'); $c = cp_crm_t('contacts'); $co = cp_crm_t('companies');
	$rq = function ($k) use ($a) { return isset($a[$k]) && is_scalar($a[$k]) ? trim((string) $a[$k]) : ''; };
	$w = array('d.deleted_at IS NULL');
	$st = $rq('st');
	if (in_array($st, array('Open', 'Won', 'Lost'), true)) { $w[] = $wpdb->prepare('d.status = %s', $st); }
	$stage = $rq('stage');
	if ($stage !== '') { $w[] = $wpdb->prepare('d.stage = %s', mb_substr($stage, 0, 80)); }
	$own = $rq('own');
	if ($own !== '' && ctype_digit($own)) { $w[] = $wpdb->prepare('d.owner_id = %d', (int) $own); }
	$today = wp_date('Y-m-d');
	$ev = $rq('ev');
	if ($ev === 'up') { $w[] = $wpdb->prepare('d.event_d >= %s', $today); }
	elseif ($ev === 'past') { $w[] = $wpdb->prepare('d.event_d < %s', $today); }
	elseif ($ev === 'none') { $w[] = 'd.event_d IS NULL'; }
	$f = cp_crm_req_date($a, 'f'); $t = cp_crm_req_date($a, 'to');
	if ($f !== '') { $w[] = $wpdb->prepare('d.event_d >= %s', $f); }
	if ($t !== '') { $w[] = $wpdb->prepare('d.event_d <= %s', $t); }
	$q = mb_substr($rq('q'), 0, 100);
	if ($q !== '') {
		$qs = preg_replace('/\s+/', '', $q);
		if (preg_match('/^#?\d{3,7}$/', $q)) {
			$w[] = $wpdb->prepare('d.id = %d', (int) ltrim($q, '#'));
		} else {
			$like = '%' . $wpdb->esc_like($q) . '%';
			$or = $wpdb->prepare('d.title LIKE %s OR d.venue LIKE %s OR c.name LIKE %s OR c.email LIKE %s OR co.name LIKE %s OR d.package LIKE %s', $like, $like, $like, $like, $like, $like);
			if (preg_match('/^\+?\d{6,}$/', $qs)) {
				$pl = '%' . $wpdb->esc_like(substr($qs, -8)) . '%';
				$or .= $wpdb->prepare(" OR REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '/', '') LIKE %s OR REPLACE(REPLACE(REPLACE(c.mobile, ' ', ''), '-', ''), '/', '') LIKE %s", $pl, $pl);
			}
			$w[] = '(' . $or . ')';
		}
	}
	$sorts = array('mod' => 'd.modified_at', 'cr' => 'd.created_at', 'ev' => 'd.event_d', 'val' => 'd.value', 'title' => 'd.title');
	$so = $rq('so');
	if (!isset($sorts[$so])) { $so = 'mod'; }
	$dir = $rq('dir') === 'asc' ? 'ASC' : 'DESC';
	$order = $sorts[$so] . ' IS NULL, ' . $sorts[$so] . ' ' . $dir . ', d.id DESC';
	$n = 50;
	$p = max(0, (int) $rq('p'));
	$from = "FROM $d d LEFT JOIN $c c ON c.id = d.contact_id LEFT JOIN $co co ON co.id = d.company_id WHERE " . implode(' AND ', $w);
	$total = (int) $wpdb->get_var("SELECT COUNT(*) $from");
	$sum = $wpdb->get_results("SELECT d.status s, COUNT(*) n, SUM(d.value) v $from GROUP BY d.status", ARRAY_A);
	$rows = $wpdb->get_results("SELECT d.id, d.title, d.stage, d.status, d.value, d.currency, d.event_d, d.venue, d.package, d.service, d.owner_id, d.modified_at, d.created_at, d.paid, c.name cn, c.email ce, c.phone cp, c.mobile cm, co.name con $from ORDER BY $order LIMIT " . ($p * $n) . ", $n", ARRAY_A);
	$out = array();
	foreach ((array) $rows as $r) { $out[] = cp_crm_list_row($r); }
	$by = array();
	foreach ((array) $sum as $x) { $by[$x['s']] = array('n' => (int) $x['n'], 'v' => (float) $x['v']); }
	return array('rows' => $out, 'total' => $total, 'p' => $p, 'n' => $n, 'by' => $by, 'sync' => cp_crm_sync_at());
}

function cp_crm_list_row($r) {
	return array(
		'id' => (int) $r['id'], 'title' => (string) $r['title'], 'stage' => (string) $r['stage'], 'status' => (string) $r['status'],
		'value' => $r['value'] === null ? null : (float) $r['value'], 'cur' => (string) $r['currency'],
		'ev' => (string) $r['event_d'], 'venue' => (string) $r['venue'], 'pkg' => (string) $r['package'], 'svc' => (string) $r['service'],
		'own' => (int) $r['owner_id'], 'mod' => cp_crm_local($r['modified_at']), 'cr' => cp_crm_local($r['created_at']), 'paid' => (string) $r['paid'],
		'cn' => (string) $r['cn'], 'ce' => (string) $r['ce'], 'cp' => (string) ($r['cm'] !== null && $r['cm'] !== '' ? $r['cm'] : $r['cp']), 'co' => (string) $r['con'],
	);
}

function cp_crm_sync_at() {
	$st = cp_crm_state();
	return isset($st['last_ok']) ? (int) $st['last_ok'] : 0;
}

/* ------------------------------------------------------------ AJAX: pipeline-tábla */

add_action('wp_ajax_nopriv_cp_crm_board', 'cp_crm_ajax_board');
add_action('wp_ajax_cp_crm_board', 'cp_crm_ajax_board');
function cp_crm_ajax_board() {
	cp_crm_ajax_guard();
	wp_send_json_success(cp_crm_board_data(wp_unslash($_REQUEST)));
}

function cp_crm_board_data($a) {
	global $wpdb;
	$d = cp_crm_t('deals'); $c = cp_crm_t('contacts'); $co = cp_crm_t('companies');
	/* nyitott deal-ek; „won” = a megnyertek is, amelyek rendezvénye még előttünk van (vagy 30 napon belül volt) */
	$w = "d.status = 'Open'";
	if (!empty($a['won'])) { $w = $wpdb->prepare("(d.status = 'Open' OR (d.status = 'Won' AND d.event_d >= %s))", wp_date('Y-m-d', time() - 30 * DAY_IN_SECONDS)); }
	$own = isset($a['own']) && is_scalar($a['own']) ? (string) $a['own'] : '';
	if ($own !== '' && ctype_digit($own)) { $w .= $wpdb->prepare(' AND d.owner_id = %d', (int) $own); }
	$rows = $wpdb->get_results("SELECT d.id, d.title, d.stage, d.status, d.value, d.currency, d.event_d, d.venue, d.package, d.service, d.owner_id, d.modified_at, d.created_at, d.paid, c.name cn, c.email ce, c.phone cp, c.mobile cm, co.name con FROM $d d LEFT JOIN $c c ON c.id = d.contact_id LEFT JOIN $co co ON co.id = d.company_id WHERE d.deleted_at IS NULL AND $w ORDER BY d.event_d IS NULL, d.event_d ASC, d.id DESC LIMIT 2000", ARRAY_A);
	$out = array();
	foreach ((array) $rows as $r) { $out[] = cp_crm_list_row($r); }
	return array('rows' => $out, 'sync' => cp_crm_sync_at());
}

/* ------------------------------------------------------------ AJAX: deal-adatlap */

add_action('wp_ajax_nopriv_cp_crm_deal', 'cp_crm_ajax_deal');
add_action('wp_ajax_cp_crm_deal', 'cp_crm_ajax_deal');
function cp_crm_ajax_deal() {
	cp_crm_ajax_guard();
	$x = cp_crm_deal_data(isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0);
	if (!$x) { cp_el_err('Nincs ilyen deal a panel adatai között.', 404); }
	wp_send_json_success($x);
}

function cp_crm_deal_data($id) {
	global $wpdb;
	$deal = $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $id), ARRAY_A) : null;
	if (!$deal) { return null; }
	unset($deal['raw']);
	foreach (array('closed_at', 'last_note_at', 'last_comm_at', 'created_at', 'modified_at', 'start_at', 'synced_at', 'deleted_at') as $k) { $deal[$k] = cp_crm_local($deal[$k]); }
	$deal['owner'] = cp_crm_user_name($deal['owner_id']);
	$deal['created_by_n'] = cp_crm_user_name($deal['created_by']);
	$deal['modified_by_n'] = cp_crm_user_name($deal['modified_by']);
	$contact = null; $company = null; $others = array();
	if ($deal['contact_id']) {
		$contact = $wpdb->get_row($wpdb->prepare('SELECT id, name, email, phone, mobile, company_id, type, tags, city, utm_source, utm_medium, utm_campaign, description, last_note, created_at FROM ' . cp_crm_t('contacts') . ' WHERE id = %d', $deal['contact_id']), ARRAY_A);
		if ($contact) { $contact['created_at'] = cp_crm_local($contact['created_at']); }
		$others = $wpdb->get_results($wpdb->prepare('SELECT id, title, stage, status, event_d, value FROM ' . cp_crm_t('deals') . ' WHERE contact_id = %d AND id <> %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 20', $deal['contact_id'], $id), ARRAY_A);
	}
	$coid = $deal['company_id'] ? $deal['company_id'] : ($contact && $contact['company_id'] ? $contact['company_id'] : 0);
	if ($coid) { $company = $wpdb->get_row($wpdb->prepare('SELECT id, name, website, phone, type FROM ' . cp_crm_t('companies') . ' WHERE id = %d', $coid), ARRAY_A); }
	/* tevékenységek: a deal-hez és a kontakthoz kötöttek */
	/* a Salesmate a modult számmal adja (4 = deal, 1 = kontakt), régebben névvel */
	$aw = $wpdb->prepare("(related_id = %d AND (related_module = '4' OR LOWER(related_module) LIKE 'deal%%'))", $id);
	if ($deal['contact_id']) { $aw .= $wpdb->prepare(" OR (related_id = %d AND (related_module = '1' OR LOWER(related_module) LIKE 'contact%%'))", $deal['contact_id']); }
	$acts = $wpdb->get_results('SELECT id, title, type, owner_id, due_at, completed, completed_at, description FROM ' . cp_crm_t('activities') . " WHERE deleted_at IS NULL AND ($aw) ORDER BY due_at DESC LIMIT 50", ARRAY_A);
	foreach ($acts as &$a) { $a['due_at'] = cp_crm_local($a['due_at']); $a['completed_at'] = cp_crm_local($a['completed_at']); $a['owner'] = cp_crm_user_name($a['owner_id']); }
	unset($a);
	$hist = $wpdb->get_results($wpdb->prepare('SELECT field, old_val, new_val, by_user, at FROM ' . cp_crm_t('history') . ' WHERE deal_id = %d ORDER BY at DESC, id DESC LIMIT 100', $id), ARRAY_A);
	foreach ($hist as &$h) { $h['at'] = cp_crm_local($h['at']); }
	unset($h);
	return array('deal' => $deal, 'contact' => $contact, 'company' => $company, 'others' => $others, 'acts' => $acts, 'hist' => $hist, 'sync' => cp_crm_sync_at());
}

/* ------------------------------------------------------------ AJAX: szinkron most (Frissítés gomb; percenként legfeljebb egyszer) */

add_action('wp_ajax_nopriv_cp_crm_sync', 'cp_crm_ajax_sync');
add_action('wp_ajax_cp_crm_sync', 'cp_crm_ajax_sync');
function cp_crm_ajax_sync() {
	cp_crm_ajax_guard();
	$st = cp_crm_state();
	if (empty($st['last_run']) || time() - (int) $st['last_run'] >= 60) { cp_crm_sync(true); }
	wp_send_json_success(array('sync' => cp_crm_sync_at()));
}

/* ------------------------------------------------------------ AJAX: deal áthelyezése (pipeline: húzás, adatlap: szakasz / megnyert / elvesztett)
 * Ez az első írás: a Salesmate-be ír (a Salesmate automatizációi lefutnak), visszaolvassa, majd a saját táblát is frissíti.
 * A panel beállításai között a „Szakasz visszaírása” kapcsolja (ugyanaz, mint a Követés fül szerződés-pipájáé). */

add_action('wp_ajax_nopriv_cp_crm_move', 'cp_crm_ajax_move');
add_action('wp_ajax_cp_crm_move', 'cp_crm_ajax_move');
function cp_crm_ajax_move() {
	cp_crm_ajax_guard();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$o = cp_panel_opts();
	if (empty($o['stage_write'])) { cp_el_err('A szakaszváltás a panel beállításaiban ki van kapcsolva.'); }
	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
	$to = isset($_POST['to']) ? trim((string) wp_unslash($_POST['to'])) : '';
	$st = isset($_POST['st']) ? (string) wp_unslash($_POST['st']) : '';
	$why = isset($_POST['why']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['why'])), 0, 100) : '';
	if ($id <= 0 || ($to === '' && !in_array($st, array('Won', 'Lost', 'Open'), true))) { cp_el_err('Hibás kérés.'); }
	if ($to !== '' && !in_array($to, cp_crm_stages(), true)) { cp_el_err('Ismeretlen szakasz.'); }
	$d = cp_sm_get_deal($id);
	if (is_wp_error($d)) { cp_el_err($d->get_error_message(), 502); }
	$cur = cp_panel_str(cp_panel_get($d, 'stage'));
	$curSt = cp_panel_str(cp_panel_get($d, 'status'));
	$body = array();
	if ($to !== '') {
		if (cp_panel_lc($curSt) === 'lost') { cp_el_err('Ez a deal a Salesmate-ben elvesztettként van jelölve – előbb nyisd újra.', 409); }
		if (cp_panel_lc($cur) === cp_panel_lc($to)) { wp_send_json_success(array('row' => cp_crm_row_out($id), 'changed' => false)); }
		$body['stage'] = $to;
		$pl = cp_panel_get($d, 'pipeline');
		if (is_string($pl) && trim($pl) !== '') { $body['pipeline'] = trim($pl); }
	} else {
		if (cp_panel_lc($curSt) === cp_panel_lc($st)) { wp_send_json_success(array('row' => cp_crm_row_out($id), 'changed' => false)); }
		$body['status'] = $st;
		if ($st === 'Lost' && $why !== '') { $body['lostReason'] = $why; }
	}
	$put = cp_sm_put_deal($id, $body);
	if (is_wp_error($put)) { cp_el_err($put->get_error_message(), 502); }
	$chk = cp_sm_get_deal($id);
	if (is_wp_error($chk)) { cp_el_err('A Salesmate-be beírtam, de a visszaolvasás nem sikerült – frissíts egy perc múlva.', 502); }
	$ok = $to !== '' ? cp_panel_lc(cp_panel_str(cp_panel_get($chk, 'stage'))) === cp_panel_lc($to) : cp_panel_lc(cp_panel_str(cp_panel_get($chk, 'status'))) === cp_panel_lc($st);
	if (!$ok) { cp_el_err('A Salesmate nem fogadta el a változást (jelenleg: „' . cp_panel_str(cp_panel_get($chk, $to !== '' ? 'stage' : 'status')) . '”).', 502); }
	/* a saját táblát és a változásnaplót a cp_sm_request() írás utáni kampója frissítette (crm-source.php) */
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	/* ügyfélportál: a „Szerződés aláírva” szakaszba lépéskor (mint a Követés fülön) */
	$signed = cp_panel_lines($o['signed_stages']);
	if ($to !== '' && $signed && cp_panel_lc($to) === cp_panel_lc($signed[0]) && function_exists('cp_portal_defer')) { cp_portal_defer(function () use ($id) { cp_portal_on_stage($id, '', 'panel'); }); }
	wp_send_json_success(array('row' => cp_crm_row_out($id), 'changed' => true));
}

function cp_crm_row_out($id) {
	global $wpdb;
	$d = cp_crm_t('deals'); $c = cp_crm_t('contacts'); $co = cp_crm_t('companies');
	$r = $wpdb->get_row($wpdb->prepare("SELECT d.id, d.title, d.stage, d.status, d.value, d.currency, d.event_d, d.venue, d.package, d.service, d.owner_id, d.modified_at, d.created_at, d.paid, c.name cn, c.email ce, c.phone cp, c.mobile cm, co.name con FROM $d d LEFT JOIN $c c ON c.id = d.contact_id LEFT JOIN $co co ON co.id = d.company_id WHERE d.id = %d", $id), ARRAY_A);
	return $r ? cp_crm_list_row($r) : null;
}
