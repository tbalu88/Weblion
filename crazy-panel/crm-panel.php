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
		'bulk'   => cp_crm_bulk_fields(),
		'edit'   => !empty(cp_panel_opts()['stage_write']),
		'me_sm'  => function_exists('cp_pu_current') && cp_pu_current() ? (int) cp_pu_current()['sm'] : 0,
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
			/* ékezet- és kisbetű-független, szavanként (minden szónak szerepelnie kell valamelyik mezőben) */
			$hay = "CONVERT(CONCAT_WS(' ', d.title, d.venue, d.package, d.service, c.name, c.email, co.name) USING utf8mb4) COLLATE utf8mb4_unicode_ci";
			$and = array();
			foreach (array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $word) { $and[] = $wpdb->prepare("$hay LIKE %s", '%' . $wpdb->esc_like($word) . '%'); }
			$or = '(' . implode(' AND ', $and) . ')';
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
	$rows = $wpdb->get_results("SELECT d.id, d.title, d.stage, d.status, d.value, d.currency, d.event_d, d.venue, d.package, d.service, d.owner_id, d.modified_at, d.created_at, d.paid, d.rotten_d, c.name cn, c.email ce, c.phone cp, c.mobile cm, co.name con $from ORDER BY $order LIMIT " . ($p * $n) . ", $n", ARRAY_A);
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
		'own' => (int) $r['owner_id'], 'mod' => cp_crm_local($r['modified_at']), 'cr' => cp_crm_local($r['created_at']), 'paid' => (string) $r['paid'], 'rot' => isset($r['rotten_d']) ? (string) $r['rotten_d'] : '',
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
	$rows = $wpdb->get_results("SELECT d.id, d.title, d.stage, d.status, d.value, d.currency, d.event_d, d.venue, d.package, d.service, d.owner_id, d.modified_at, d.created_at, d.paid, d.rotten_d, c.name cn, c.email ce, c.phone cp, c.mobile cm, co.name con FROM $d d LEFT JOIN $c c ON c.id = d.contact_id LEFT JOIN $co co ON co.id = d.company_id WHERE d.deleted_at IS NULL AND $w ORDER BY d.event_d IS NULL, d.event_d ASC, d.id DESC LIMIT 2000", ARRAY_A);
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
	$r = $wpdb->get_row($wpdb->prepare("SELECT d.id, d.title, d.stage, d.status, d.value, d.currency, d.event_d, d.venue, d.package, d.service, d.owner_id, d.modified_at, d.created_at, d.paid, d.rotten_d, c.name cn, c.email ce, c.phone cp, c.mobile cm, co.name con FROM $d d LEFT JOIN $c c ON c.id = d.contact_id LEFT JOIN $co co ON co.id = d.company_id WHERE d.id = %d", $id), ARRAY_A);
	return $r ? cp_crm_list_row($r) : null;
}

/* ------------------------------------------------------------ tömeges műveletek a listában (kijelölés): mezőírás, törlés
 * Deal-enként egy kérés (a panel jelzi a haladást); a Salesmate-be ír, visszaolvas, a saját táblát a cp_sm_request() kampója frissíti. */

/* a tömegesen írható mezők: oszlop => array(címke, típus) – a típus: user | text */
function cp_crm_bulk_fields() {
	return array(
		'owner_id'    => array('Felelős', 'user'),
		'paid'        => array('Bejött az utalás?', 'text'),
		'pay_method'  => array('Választott fizetési mód', 'text'),
		'reminder'    => array('Menjen-e emlékeztető', 'text'),
		'operator'    => array('Gépkezelő asszisztens', 'text'),
		'album'       => array('Fotóalbum', 'text'),
		'ctype'       => array('Szerződés EV/KFT', 'text'),
		'cal_check'   => array('Naptár', 'text'),
		'template_st' => array('Template', 'text'),
	);
}

/* egy mező eddig használt értékei (a választólistához) */
add_action('wp_ajax_nopriv_cp_crm_values', 'cp_crm_ajax_values');
add_action('wp_ajax_cp_crm_values', 'cp_crm_ajax_values');
function cp_crm_ajax_values() {
	cp_crm_ajax_guard();
	global $wpdb;
	$f = isset($_REQUEST['f']) ? sanitize_key(wp_unslash($_REQUEST['f'])) : '';
	$all = cp_crm_bulk_fields();
	if (!isset($all[$f]) || $all[$f][1] !== 'text') { wp_send_json_success(array('vals' => array())); }
	$vals = $wpdb->get_col('SELECT ' . $f . ' v FROM ' . cp_crm_t('deals') . " WHERE deleted_at IS NULL AND $f <> '' GROUP BY $f ORDER BY COUNT(*) DESC LIMIT 25");
	wp_send_json_success(array('vals' => array_values(array_map('strval', (array) $vals))));
}

add_action('wp_ajax_nopriv_cp_crm_set', 'cp_crm_ajax_set');
add_action('wp_ajax_cp_crm_set', 'cp_crm_ajax_set');
function cp_crm_ajax_set() {
	cp_crm_ajax_guard();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (empty(cp_panel_opts()['stage_write'])) { cp_el_err('A Salesmate-be írás a panel beállításaiban ki van kapcsolva.'); }
	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
	$f = isset($_POST['f']) ? sanitize_key(wp_unslash($_POST['f'])) : '';
	$v = isset($_POST['v']) ? trim(sanitize_text_field(wp_unslash($_POST['v']))) : '';
	$all = cp_crm_bulk_fields();
	if ($id <= 0 || !isset($all[$f])) { cp_el_err('Hibás kérés.'); }
	$sm = cp_crm_spec('deal')[$f][0];
	if ($all[$f][1] === 'user') {
		if (!ctype_digit($v) || !isset(cp_crm_users()[(int) $v])) { cp_el_err('Ismeretlen felhasználó.'); }
		$val = (int) $v;
	} else {
		$val = mb_substr($v, 0, 190);
	}
	$put = cp_sm_put_deal($id, array($sm => $val));
	if (is_wp_error($put)) { cp_el_err($put->get_error_message(), 502); }
	$chk = cp_sm_get_deal($id);
	if (is_wp_error($chk)) { cp_el_err('Beírtam, de a visszaolvasás nem sikerült.', 502); }
	$now = cp_panel_get($chk, $sm);
	$nowS = is_array($now) ? (isset($now['id']) ? (string) $now['id'] : cp_panel_str($now)) : cp_panel_str($now);
	if ($nowS !== (string) $val) { cp_el_err('A Salesmate nem fogadta el az értéket (jelenleg: „' . $nowS . '”).', 502); }
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	wp_send_json_success(array('row' => cp_crm_row_out($id)));
}

add_action('wp_ajax_nopriv_cp_crm_delete', 'cp_crm_ajax_delete');
add_action('wp_ajax_cp_crm_delete', 'cp_crm_ajax_delete');
function cp_crm_ajax_delete() {
	cp_crm_ajax_guard();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (empty(cp_panel_opts()['stage_write'])) { cp_el_err('A Salesmate-be írás a panel beállításaiban ki van kapcsolva.'); }
	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
	if ($id <= 0 || (isset($_POST['ok']) ? (string) wp_unslash($_POST['ok']) : '') !== 'TÖRLÉS') { cp_el_err('Hibás kérés.'); }
	$res = cp_sm_request('DELETE', '/apis/deal/v4/' . $id);
	if (is_wp_error($res)) { cp_el_err($res->get_error_message(), 502); }
	$chk = cp_sm_get_deal($id);
	$gone = is_wp_error($chk) || empty($chk) || !cp_panel_get($chk, 'id');
	if (!$gone) { cp_el_err('A Salesmate nem törölte a dealt.', 502); }
	global $wpdb;
	$wpdb->update(cp_crm_t('deals'), array('deleted_at' => gmdate('Y-m-d H:i:s')), array('id' => $id));
	$wpdb->insert(cp_crm_t('history'), array('deal_id' => $id, 'field' => 'deleted', 'old_val' => '', 'new_val' => 'törölve', 'by_user' => function_exists('cp_panel_who') ? cp_panel_who() : 'Crazy panel', 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel'));
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	wp_send_json_success(array('id' => $id));
}


/* ------------------------------------------------------------ deal szerkesztése és új deal (v1.30)
 * Írási formátumok a Salesmate-ben (próba a #20209 teszt-deal-en, 2026. okt. 29.):
 * dátum: „Y-m-d”; időpont: UTC „Y-m-d\TH:i:s.000Z” (a zóna nélküli időt is UTC-nek veszi; az ezredmásodperc hibás évet ad); többválasztós: vesszővel elválasztva
 * (a pontosvessző kiüríti a mezőt); felelős: felhasználó-azonosító; kontakt neve: vezeték- + utónév (firstName, lastName). */

/* a panelben szerkeszthető deal-mezők: oszlop => típus */
function cp_crm_edit_fields() {
	return array(
		'title' => 'str', 'value' => 'dec', 'owner_id' => 'user', 'source' => 'str',
		'event_d' => 'date', 'start_at' => 'time', 'venue' => 'str', 'guests' => 'int', 'hours' => 'int', 'service' => 'str', 'pkg_fix' => 'str',
		'services_ec' => 'multi', 'services_cp' => 'multi', 'services_more' => 'multi', 'extras' => 'multi',
		'background' => 'str', 'km_fee' => 'int', 'extension' => 'dec', 'operator' => 'str', 'album' => 'str', 'template_st' => 'str', 'coupon' => 'str',
		'pay_method' => 'str', 'paid' => 'str', 'ctype' => 'str', 'note' => 'str',
	);
}

function cp_crm_sm_field($col) {
	return $col === 'value' ? 'dealValue' : ($col === 'owner_id' ? 'owner' : cp_crm_spec('deal')[$col][0]);
}

function cp_crm_multi_list($v) {
	$out = array();
	foreach (preg_split('/\s*(?:\||,|;)\s*/u', is_array($v) ? implode(',', array_map('cp_panel_str', $v)) : (string) $v) as $x) { $x = trim($x); if ($x !== '') { $out[] = $x; } }
	sort($out);
	return $out;
}

/* panel-érték → Salesmate-érték; $date: a rendezvény dátuma (az időponthoz) */
function cp_crm_to_sm($type, $v, $date) {
	if ($type === 'user') { return $v === '' ? null : (int) $v; }
	if ($type === 'int') { return $v === '' ? null : (int) round((float) str_replace(',', '.', $v)); }
	if ($type === 'dec') { return $v === '' ? null : round((float) str_replace(array(' ', ','), array('', '.'), $v), 2); }
	if ($type === 'date') { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }
	if ($type === 'time') {
		if ($v === '' || !preg_match('/^\d{1,2}:\d{2}$/', $v) || !$date) { return null; }
		$dt = new DateTime($date . ' ' . str_pad($v, 5, '0', STR_PAD_LEFT) . ':00', new DateTimeZone('Europe/Budapest'));
		return gmdate('Y-m-d\TH:i:s.000\Z', $dt->getTimestamp());
	}
	if ($type === 'multi') { $l = cp_crm_multi_list(str_replace(' | ', ',', $v)); return implode(',', $l); }
	return mb_substr(str_replace("\r\n", "\n", $v), 0, 2000);
}

/* visszaolvasott érték egyezik-e a kívánttal */
function cp_crm_sm_same($type, $want, $nv) {
	if ($type === 'user') { return (int) (is_array($nv) ? (isset($nv['id']) ? $nv['id'] : 0) : $nv) === (int) $want; }
	if ($type === 'int' || $type === 'dec') { return ($want === null && ($nv === null || $nv === '')) || ($want !== null && is_numeric($nv) && abs((float) $nv - (float) $want) < 0.005); }
	if ($type === 'date') { return $want === null ? cp_panel_norm_date($nv) === '' : cp_panel_norm_date($nv) === $want; }
	if ($type === 'time') { return $want === null ? cp_crm_dt_ts($nv) === 0 : abs(cp_crm_dt_ts($nv) - strtotime($want)) < 60; }
	if ($type === 'multi') { return cp_crm_multi_list($nv) === cp_crm_multi_list($want); }
	return str_replace("\r\n", "\n", cp_panel_str($nv)) === (string) $want;
}

/* a Salesmate időpont-értéke (ezredmásodperc vagy ISO, zónával) → Unix-idő */
function cp_crm_dt_ts($v) {
	if ($v === null || $v === '' || is_array($v)) { return 0; }
	if (is_numeric($v)) { $t = (float) $v; return (int) round($t > 100000000000 ? $t / 1000 : $t); }
	$t = strtotime((string) $v);
	return $t ? (int) $t : 0;
}

/* választólisták a szerkesztőhöz: a szöveges mezők eddigi értékei és a többválasztós mezők lehetőségei */
add_action('wp_ajax_nopriv_cp_crm_opts', 'cp_crm_ajax_opts');
add_action('wp_ajax_cp_crm_opts', 'cp_crm_ajax_opts');
function cp_crm_ajax_opts() {
	cp_crm_ajax_guard();
	global $wpdb;
	$t = cp_crm_t('deals');
	$out = array();
	foreach (array('source', 'service', 'pkg_fix', 'background', 'operator', 'album', 'template_st', 'pay_method', 'paid', 'ctype') as $f) {
		$out[$f] = array_values(array_map('strval', (array) $wpdb->get_col("SELECT $f FROM $t WHERE deleted_at IS NULL AND $f <> '' GROUP BY $f ORDER BY COUNT(*) DESC LIMIT 30")));
	}
	foreach (array('services_ec', 'services_cp', 'services_more', 'extras') as $f) {
		$n = array();
		foreach ((array) $wpdb->get_col("SELECT $f FROM $t WHERE deleted_at IS NULL AND $f <> '' ORDER BY id DESC LIMIT 1500") as $v) { foreach (explode(' | ', $v) as $x) { $x = trim($x); if ($x !== '') { $n[$x] = (isset($n[$x]) ? $n[$x] : 0) + 1; } } }
		arsort($n);
		$out[$f] = array_slice(array_keys($n), 0, 40);
	}
	wp_send_json_success(array('vals' => $out));
}

/* deal (és a kontaktja) mentése: csak a megváltozott mezők; a Salesmate-be ír, visszaolvas, ellenőriz */
add_action('wp_ajax_nopriv_cp_crm_save', 'cp_crm_ajax_save');
add_action('wp_ajax_cp_crm_save', 'cp_crm_ajax_save');
function cp_crm_ajax_save() {
	cp_crm_ajax_guard();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (empty(cp_panel_opts()['stage_write'])) { cp_el_err('A Salesmate-be írás a panel beállításaiban ki van kapcsolva.'); }
	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
	$f = json_decode(isset($_POST['f']) ? (string) wp_unslash($_POST['f']) : '', true);
	$c = json_decode(isset($_POST['c']) ? (string) wp_unslash($_POST['c']) : '', true);
	if ($id <= 0 || !is_array($f) || !is_array($c)) { cp_el_err('Hibás kérés.'); }
	$r = cp_crm_save_data($id, $f, $c);
	if (is_wp_error($r)) { cp_el_err($r->get_error_message(), (int) ($r->get_error_data() ?: 400)); }
	wp_send_json_success($r);
}

/* a mentés maga (a próbákhoz is): tömb vagy WP_Error */
function cp_crm_save_data($id, $f, $c) {
	global $wpdb;
	$row = $wpdb->get_row($wpdb->prepare('SELECT event_d, start_at, contact_id FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $id), ARRAY_A);
	if (!$row) { return new WP_Error('cp_crm', 'Nincs ilyen deal.', 404); }
	$F = cp_crm_edit_fields();
	$body = array(); $types = array();
	foreach ($f as $col => $v) {
		if (!isset($F[$col]) || !is_scalar($v)) { continue; }
		$v = trim(sanitize_textarea_field((string) $v));
		if ($col === 'title' && $v === '') { return new WP_Error('cp_crm', 'A deal neve nem lehet üres.', 400); }
		if ($F[$col] === 'user' && $v !== '' && !isset(cp_crm_users()[(int) $v])) { return new WP_Error('cp_crm', 'Ismeretlen felhasználó.', 400); }
		$body[cp_crm_sm_field($col)] = $v; $types[cp_crm_sm_field($col)] = $F[$col];
	}
	/* a kezdés időpontja a rendezvény (esetleg most módosított) dátumához tartozik */
	$date = isset($f['event_d']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['event_d']) ? (string) $f['event_d'] : (string) $row['event_d'];
	$tf = cp_crm_spec('deal')['start_at'][0];
	if (isset($f['event_d']) && !isset($f['start_at']) && $row['start_at'] && $date) { $body[$tf] = cp_crm_local($row['start_at']) ? substr(cp_crm_local($row['start_at']), 11, 5) : ''; $types[$tf] = 'time'; }
	foreach ($body as $k => $v) { $body[$k] = cp_crm_to_sm($types[$k], $v, $date); }
	$warn = array();
	if ($body) {
		$put = cp_sm_put_deal($id, $body);
		if (is_wp_error($put)) { return new WP_Error('cp_crm', $put->get_error_message(), 502); }
		$g = cp_sm_get_deal($id);
		if (is_wp_error($g)) { return new WP_Error('cp_crm', 'A Salesmate-be beírtam, de a visszaolvasás nem sikerült – frissíts egy perc múlva.', 502); }
		$lbl = array();
		foreach (array_keys($F) as $col) { $lbl[cp_crm_sm_field($col)] = $col; }
		foreach ($body as $k => $v) { if (!cp_crm_sm_same($types[$k], $v, cp_panel_get($g, $k))) { $warn[] = (isset($lbl[$k]) ? $lbl[$k] : $k) . ' nem úgy maradt meg, ahogy beírtad'; } }
	}
	/* kontakt */
	$cid = (int) $row['contact_id'];
	if ($c && $cid) {
		$cb = array();
		if (isset($c['name'])) { $n = trim(sanitize_text_field((string) $c['name'])); if ($n === '') { return new WP_Error('cp_crm', 'A kontakt neve nem lehet üres.', 400); } $cb['firstName'] = ''; $cb['lastName'] = $n; }
		if (isset($c['email'])) { $e = trim((string) $c['email']); if ($e !== '' && !is_email($e)) { return new WP_Error('cp_crm', 'Érvénytelen e-mail-cím.', 400); } $cb['email'] = sanitize_email($e); }
		foreach (array('mobile', 'phone') as $k) { if (isset($c[$k])) { $cb[$k] = mb_substr(preg_replace('/[^0-9+ ()\/-]/', '', (string) $c[$k]), 0, 40); } }
		if ($cb) {
			$cp = cp_sm_request('PUT', '/apis/contact/v4/' . $cid, $cb);
			if (is_wp_error($cp)) { $warn[] = 'a kontaktot nem sikerült menteni (' . $cp->get_error_message() . ')'; }
			else {
				$cg = cp_sm_request('GET', '/apis/contact/v4/' . $cid);
				$cd = is_wp_error($cg) ? null : (isset($cg['Data']) ? $cg['Data'] : $cg);
				if (is_array($cd)) {
					foreach (array('email', 'mobile', 'phone') as $k) { if (isset($cb[$k]) && cp_panel_str(isset($cd[$k]) ? $cd[$k] : '') !== $cb[$k]) { $warn[] = 'a kontakt ' . $k . ' mezője nem úgy maradt meg'; } }
					if (isset($cb['lastName']) && trim(cp_panel_str(isset($cd['name']) ? $cd['name'] : '')) !== $cb['lastName']) { $warn[] = 'a kontakt neve nem úgy maradt meg'; }
					cp_crm_store('contact', array($cd), 'get');
				}
			}
		}
	}
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	return array('row' => cp_crm_row_out($id), 'warn' => implode('; ', $warn));
}

/* új deal: kontakt (meglévő e-mail-cím alapján, vagy új), majd a deal a fő pipeline-ban */
add_action('wp_ajax_nopriv_cp_crm_create', 'cp_crm_ajax_create');
add_action('wp_ajax_cp_crm_create', 'cp_crm_ajax_create');
function cp_crm_ajax_create() {
	cp_crm_ajax_guard();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$o = cp_panel_opts();
	if (empty($o['stage_write'])) { cp_el_err('A Salesmate-be írás a panel beállításaiban ki van kapcsolva.'); }
	$in = json_decode(isset($_POST['d']) ? (string) wp_unslash($_POST['d']) : '', true);
	if (!is_array($in)) { cp_el_err('Hibás kérés.'); }
	$g = function ($k) use ($in) { return isset($in[$k]) && is_scalar($in[$k]) ? trim(sanitize_textarea_field((string) $in[$k])) : ''; };
	$name = $g('c_name'); $email = sanitize_email($g('c_email')); $phone = mb_substr(preg_replace('/[^0-9+ ()\/-]/', '', $g('c_mobile')), 0, 40);
	$title = $g('title'); $stage = $g('stage');
	if ($name === '') { cp_el_err('Add meg a kontakt nevét.'); }
	if ($g('c_email') !== '' && !is_email($email)) { cp_el_err('Érvénytelen e-mail-cím.'); }
	if ($title === '') { cp_el_err('Add meg a deal nevét.'); }
	if (!in_array($stage, cp_crm_stages(), true)) { $stage = cp_crm_stages()[0]; }
	global $wpdb;
	/* kontakt: ha van már ilyen e-mail-cím, azt használjuk */
	$cid = $email !== '' ? (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_crm_t('contacts') . ' WHERE email = %s AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', $email)) : 0;
	if (!$cid) {
		$cr = cp_sm_request('POST', '/apis/contact/v4', array_filter(array('firstName' => '', 'lastName' => $name, 'email' => $email, 'mobile' => $phone), function ($v) { return $v !== ''; }));
		if (is_wp_error($cr)) { cp_el_err('A kontaktot nem sikerült létrehozni: ' . $cr->get_error_message(), 502); }
		$cid = cp_crm_new_id($cr);
		if (!$cid) { cp_el_err('A kontakt létrejött, de a Salesmate nem adta vissza az azonosítóját: ' . mb_substr(wp_json_encode($cr), 0, 200), 502); }
	}
	$F = cp_crm_edit_fields();
	$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $g('event_d')) ? $g('event_d') : '';
	$body = array('title' => $title, 'pipeline' => $o['pipeline'], 'stage' => $stage, 'primaryContact' => $cid, 'currency' => 'HUF');
	$own = $g('owner_id');
	if ($own !== '' && isset(cp_crm_users()[(int) $own])) { $body['owner'] = (int) $own; }
	foreach ($F as $col => $type) {
		if (in_array($col, array('title', 'owner_id'), true) || $g($col) === '') { continue; }
		$v = cp_crm_to_sm($type, $g($col), $date);
		if ($v !== null && $v !== '') { $body[cp_crm_sm_field($col)] = $v; }
	}
	$dr = cp_sm_request('POST', '/apis/deal/v4', $body);
	if (is_wp_error($dr)) { cp_el_err('A deal-t nem sikerült létrehozni: ' . $dr->get_error_message(), 502); }
	$id = cp_crm_new_id($dr);
	if (!$id) { cp_el_err('A deal létrejött, de a Salesmate nem adta vissza az azonosítóját – 10 percen belül megjelenik a listában.', 502); }
	/* saját tábla: azonnal (a kontakt is) */
	$cg = cp_sm_request('GET', '/apis/contact/v4/' . $cid);
	if (!is_wp_error($cg)) { cp_crm_store('contact', array(isset($cg['Data']) ? $cg['Data'] : $cg), 'get'); }
	if (function_exists('cp_crm_after_write')) { cp_crm_after_write($id); }
	$wpdb->insert(cp_crm_t('history'), array('deal_id' => $id, 'field' => 'created', 'old_val' => '', 'new_val' => 'létrehozva a panelben', 'by_user' => function_exists('cp_panel_who') ? cp_panel_who() : 'Crazy panel', 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel'));
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	wp_send_json_success(array('id' => $id, 'row' => cp_crm_row_out($id)));
}

/* a Salesmate létrehozási válaszából az új azonosító */
function cp_crm_new_id($r) {
	foreach (array(array('Data', 'id'), array('Data', 'dealId'), array('Data', 'contactId'), array('id')) as $p) {
		$x = $r;
		foreach ($p as $k) { $x = is_array($x) && isset($x[$k]) ? $x[$k] : null; }
		if (is_numeric($x) && (int) $x > 0) { return (int) $x; }
	}
	if (isset($r['Data']) && is_numeric($r['Data'])) { return (int) $r['Data']; }
	return 0;
}
