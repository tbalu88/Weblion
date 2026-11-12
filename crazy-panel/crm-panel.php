<?php
/* =====================================================================
 * CRM – a panel „Deal-ek” füle (v1.25, csak olvasás)
 * Lista keresővel és szűrőkkel, pipeline-tábla, deal-adatlap a saját táblákból (crm.php).
 * Ebben a lépésben semmit nem ír: módosítani egyelőre a Salesmate-ben lehet,
 * a változás a 10 perces szinkronnal (vagy a Frissítés gombbal) jön át.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/crm-filter.php';

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
	cp_crm_ajax_guard(array('deals'));
	wp_send_json_success(cp_crm_list_data(wp_unslash($_REQUEST)));
}

/* v1.63: $tabs – melyik fül(ek) engedik; alapból bármelyik ügyféladat-fül (a Deal-adatlap sok helyről nyílik) */
function cp_crm_ajax_guard($tabs = null) {
	cp_el_auth();
	cp_tab_need($tabs === null ? cp_tabs_data() : $tabs);
	if (!cp_crm_panel_on()) { cp_el_err('A CRM-adatok még töltődnek – próbáld újra pár perc múlva.', 503); }
}

/* $a: a kérés paraméterei (q, st, own, co, ct, cf, cols, so, dir, p) – v2.9.58: a már nem küldött stage, ev, f, to kivezetve */
function cp_crm_list_data($a) {
	global $wpdb;
	$rq = function ($k) use ($a) { return isset($a[$k]) && is_scalar($a[$k]) ? trim((string) $a[$k]) : ''; };
	$w = array('d.deleted_at IS NULL');
	/* v2.9.53: összetett szűrő (crm-filter.php); állapot-feltétel nélkül a nyitottak */
	$cfp = function_exists('cp_cf_parse') ? cp_cf_parse($a['cf'] ?? '') : null;
	$cfw = $cfp ? cp_cf_where($cfp) : '';
	if ($cfw !== '') { $w[] = $cfw; }
	$st = $rq('st');
	/* cégre/kontaktra szűrve (Deal 360°) minden állapot látszik, mint eddig */
	if (isset($a['cf']) && !cp_cf_has_status($cfp) && $rq('co') === '' && $rq('ct') === '') { $st = 'Open'; }
	if (in_array($st, array('Open', 'Won', 'Lost'), true)) { $w[] = $wpdb->prepare('d.status = %s', $st); }
	$own = $rq('own');
	if ($own !== '' && ctype_digit($own)) { $w[] = $wpdb->prepare('d.owner_id = %d', (int) $own); }
	/* v1.48: szűrés egy cégre vagy kontaktra */
	$co_f = $rq('co'); $ct_f = $rq('ct');
	if ($co_f !== '' && ctype_digit($co_f)) { $w[] = $wpdb->prepare('(d.company_id = %d OR c.company_id = %d)', (int) $co_f, (int) $co_f); }
	if ($ct_f !== '' && ctype_digit($ct_f)) { $w[] = $wpdb->prepare('d.contact_id = %d', (int) $ct_f); }
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
	/* v2.9.54: rendezés bármelyik mezőre („csoport.mező”, a szűrő fehérlistája szerint) */
	if (!isset($sorts[$so]) && function_exists('cp_cf_col') && ($sc = cp_cf_col($so))) { $sorts[$so] = $sc[0]; }
	if (!isset($sorts[$so])) { $so = 'mod'; }
	$dir = $rq('dir') === 'asc' ? 'ASC' : 'DESC';
	$order = $sorts[$so] . ' IS NULL, ' . $sorts[$so] . ' ' . $dir . ', d.id DESC';
	$n = 50;
	$p = max(0, (int) $rq('p'));
	$from = 'FROM ' . cp_crm_list_from() . ' WHERE ' . implode(' AND ', $w);
	$total = (int) $wpdb->get_var("SELECT COUNT(*) $from");
	$sum = $wpdb->get_results("SELECT d.status s, COUNT(*) n, SUM(d.value) v $from GROUP BY d.status", ARRAY_A);
	/* v2.9.54: a választott oszlopok értékei (x0, x1…) */
	list($xs, $xk) = cp_crm_xcols($a['cols'] ?? '');
	$rows = $wpdb->get_results('SELECT ' . cp_crm_list_cols() . "$xs $from ORDER BY $order LIMIT " . ($p * $n) . ", $n", ARRAY_A);
	$out = array();
	foreach ((array) $rows as $r) { $out[] = cp_crm_list_row($r, $xk); }
	$by = array();
	foreach ((array) $sum as $x) { $by[$x['s']] = array('n' => (int) $x['n'], 'v' => (float) $x['v']); }
	return array('rows' => $out, 'total' => $total, 'p' => $p, 'n' => $n, 'by' => $by, 'sync' => cp_crm_sync_at());
}

/* v2.9.58: a lista, a pipeline-tábla és az egy sor (cp_crm_row_out) közös oszlopai és táblakapcsolatai */
function cp_crm_list_cols() {
	return 'd.id, d.title, d.stage, d.status, d.value, d.currency, d.event_d, d.event_end_d, d.venue, d.package, d.service, d.owner_id, d.modified_at, d.created_at, d.paid, d.rotten_d, d.last_comm_at, (SELECT MAX(h.at) FROM ' . cp_crm_t('history') . " h WHERE h.deal_id = d.id AND h.field = 'stage') stage_at, " . cp_crm_lastout_sql() . " last_out, " . 'c.name cn, c.email ce, c.phone cp, c.mobile cm, co.name con';
}
function cp_crm_list_from() {
	return cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id LEFT JOIN ' . cp_crm_t('companies') . ' co ON co.id = d.company_id';
}
/* a választott oszlopok (crm-filter.php): array(SELECT-kiegészítés, álnév => array(kulcs, típus)) */
function cp_crm_xcols($raw) {
	$cols = function_exists('cp_cf_cols_parse') ? cp_cf_cols_parse($raw) : array();
	$xs = ''; $xk = array(); $i = 0;
	foreach ($cols as $k => $c) { $xs .= ', ' . $c[0] . ' x' . $i; $xk['x' . $i] = array($k, $c[1]); $i++; }
	return array($xs, $xk);
}

/* szakaszonként: Érdeklődő 24 óra · Ajánlat kiküldve 30 nap · Adatlap kiküldve: a rendezvény előtt 7 nappal (legalább 3 nap a szakaszban)
   · Szerződés kiküldve: a rendezvény előtt 30 nappal (legalább 7 nap a szakaszban – az 1 évre előre foglalók nem rohadnak idő előtt)
   · Szerződés aláírva, Rendezvény felkészül: nincs · Rendezvény lement: a rendezvény (utolsó napja) után 2 munkanappal.
   Az elvesztett deal nem rohad. A szakasz kezdete: az utolsó szakaszváltás a panel történetében, ennek hiányában a létrehozás. */
/* v2.9.123: a legutóbbi saját kommunikáció a deallel – kimenő levél (a deal levélszálaiban) vagy elvégzett tevékenység (hívás, teendő) */
/* v2.9.161: a Salesmate-ből örökölt, a fióktulajdonos nevén lévő „Crazyphoto aktivitás” tevékenységek a panelben nem látszanak
   (a felhasználó döntése, 2026-11-11) – a Salesmate-ben megmaradnak */
function cp_crm_act_hide_sql($a = '') { return " AND {$a}type NOT IN ('Crazyphoto aktivitás')"; }
function cp_crm_lastout_sql() {
	/* v2.9.123: + deal-jegyzet és levélszál-jegyzet (a hívást nem tudjuk naplózni, a jegyzet jelzi, hogy foglalkoztak vele) */
	$q = array("(SELECT MAX(a.completed_at) FROM " . cp_crm_t('activities') . " a WHERE a.related_id = d.id AND a.completed = 1 AND a.deleted_at IS NULL" . cp_crm_act_hide_sql('a.') . ")");
	if (function_exists('cp_dact_t')) { $q[] = "(SELECT MAX(n.created_at) FROM " . cp_dact_t() . " n WHERE n.deal_id = d.id AND n.deleted_at IS NULL AND n.src <> 'smi')"; }
	if (function_exists('cp_mail_t') && function_exists('cp_mail_db_ok') && cp_mail_db_ok()) {
		$q[] = "(SELECT MAX(m.sent_at) FROM " . cp_mail_t('messages') . " m JOIN " . cp_mail_t('threads') . " t ON t.id = m.thread_id WHERE t.deal_id = d.id AND m.dir = 'out')";
		$q[] = "(SELECT MAX(mn.created_at) FROM " . cp_mail_t('notes') . " mn JOIN " . cp_mail_t('threads') . " t2 ON t2.id = mn.thread_id WHERE t2.deal_id = d.id AND mn.deleted_at IS NULL)";
	}
	return 'GREATEST(' . implode(', ', array_map(function ($x) { return 'COALESCE(' . $x . ", '1970-01-01')"; }, $q)) . (count($q) === 1 ? ", '1970-01-01'" : '') . ')';
}
/* v2.9.124: a rohadás szabályai – Beállítások → Rohadás (admin) */
function cp_crm_rot_opts() {
	$d = array('lead_h' => 24, 'offer_d' => 30, 'sheet_before' => 7, 'sheet_min' => 3, 'con_before' => 30, 'con_min' => 7, 'done_wd' => 2,
		'g_lead_h' => 24, 'g_offer' => 7, 'g_sheet' => 3, 'g_con' => 7, 'g_done' => 2);
	$o = get_option('cp_rot_opts', array());
	return array_merge($d, is_array($o) ? array_intersect_key($o, $d) : array());
}
function cp_crm_rot($r) {
	/* csak a nyitott deal rohad (a megnyert rendezvények örökre a „Rendezvény lement” szakaszban maradnak) */
	if (($r['status'] ?? 'Open') !== 'Open') { return array('', 0); }
	$st = cp_crm_stages(); $stage = (string) ($r['stage'] ?? '');
	$tz = wp_timezone();
	$since = !empty($r['stage_at']) ? $r['stage_at'] : ($r['created_at'] ?? '');
	$sinceT = $since ? strtotime($since . ' UTC') : time();
	$ev = (string) ($r['event_d'] ?? ''); $eve = (string) ($r['event_end_d'] ?? '') ?: $ev;
	$evT = $ev ? (new DateTime($ev . ' 00:00:00', $tz))->getTimestamp() : null;
	$at = null; $w = 0;
	static $O = null; if ($O === null) { $O = cp_crm_rot_opts(); }
	if ($stage === $st[0]) { $at = $sinceT + (int) $O['lead_h'] * HOUR_IN_SECONDS; $w = max(60, (int) $O['lead_h'] * 60); }
	elseif ($stage === $st[1]) { $at = $sinceT + (int) $O['offer_d'] * DAY_IN_SECONDS; $w = max(1, (int) $O['offer_d']) * 24 * 60; }
	elseif ($stage === $st[2]) { $at = $evT ? max($evT - (int) $O['sheet_before'] * DAY_IN_SECONDS, $sinceT + (int) $O['sheet_min'] * DAY_IN_SECONDS) : $sinceT + 14 * DAY_IN_SECONDS; $w = 7 * 24 * 60; }
	elseif ($stage === $st[3]) { $at = $evT ? max($evT - (int) $O['con_before'] * DAY_IN_SECONDS, $sinceT + (int) $O['con_min'] * DAY_IN_SECONDS) : $sinceT + 21 * DAY_IN_SECONDS; $w = 14 * 24 * 60; }
	elseif ($stage === ($st[6] ?? '') && $eve) {
		$d = new DateTime($eve . ' 00:00:00', $tz); $n = 0;
		while ($n < max(0, (int) $O['done_wd'])) { $d->modify('+1 day'); if ((int) $d->format('N') <= 5) { $n++; } }
		$d->modify('+1 day'); /* a 2. munkanap végéig */
		$at = $d->getTimestamp(); $w = 5 * 24 * 60;
	}
	if ($at === null) { return array('', 0); }
	/* v2.9.123: ha a mi oldalunkról nemrég volt kommunikáció (levél, hívás), a deal még nem rohad – a szakaszváltás nem rajtunk múlik.
	   Türelmi idő a legutóbbi saját kommunikációtól: Érdeklődő 24 óra, Ajánlat kiküldve 7 nap, Adatlap 3 nap, Szerződés 7 nap, Lement 2 nap. */
	$lo = (string) ($r['last_out'] ?? '');
	if ($lo !== '' && $lo > '1971') {
		$grace = array($st[0] => (int) $O['g_lead_h'] * HOUR_IN_SECONDS, $st[1] => (int) $O['g_offer'] * DAY_IN_SECONDS, $st[2] => (int) $O['g_sheet'] * DAY_IN_SECONDS, $st[3] => (int) $O['g_con'] * DAY_IN_SECONDS);
		$g = $grace[$stage] ?? (int) $O['g_done'] * DAY_IN_SECONDS;
		$at = max($at, strtotime($lo . ' UTC') + $g);
	}
	return array(wp_date('Y-m-d H:i', $at), $w);
}
function cp_crm_list_row($r, $xk = array()) {
	$o = array(
		'id' => (int) $r['id'], 'title' => (string) $r['title'], 'stage' => (string) $r['stage'], 'status' => (string) $r['status'],
		'value' => $r['value'] === null ? null : (float) $r['value'], 'cur' => (string) $r['currency'],
		'ev' => (string) $r['event_d'], 'eve' => isset($r['event_end_d']) ? (string) $r['event_end_d'] : '', /* v2.9.71: többnapos: utolsó nap */ 'venue' => (string) $r['venue'], 'pkg' => (string) $r['package'], 'svc' => (string) $r['service'],
		'own' => (int) $r['owner_id'], 'mod' => cp_crm_local($r['modified_at']), 'cr' => cp_crm_local($r['created_at']), 'paid' => (string) $r['paid'],
		'cn' => (string) $r['cn'], 'ce' => (string) $r['ce'], 'cp' => (string) ($r['cm'] !== null && $r['cm'] !== '' ? $r['cm'] : $r['cp']), 'co' => (string) $r['con'],
	);
	/* v2.9.122: rohadás a panel szabályai szerint (a felhasználó döntése, 2026-11-11) – rot: mikortól rohad (helyi idő), rw: a színskála hossza percben */
	list($o['rot'], $o['rw']) = cp_crm_rot($r);
	/* v2.9.157: utolsó kommunikáció (a Salesmate-é és a mi levelünk / jegyzetünk / teendőnk közül a későbbi) – a csempe jobb alsó sarkába */
	$lc = max((string) ($r['last_comm_at'] ?? ''), (string) ($r['last_out'] ?? ''));
	$o['lc'] = $lc > '1971' ? cp_crm_local($lc) : '';
	if ($xk) { $o['x'] = array(); foreach ($xk as $al => $kt) { $v = $r[$al]; if ($kt[1] === 'dt' && $v) { $v = cp_crm_local($v); } $o['x'][$kt[0]] = $v === null ? null : (string) $v; } }
	return $o;
}

function cp_crm_sync_at() {
	$st = cp_crm_state();
	return isset($st['last_ok']) ? (int) $st['last_ok'] : 0;
}

/* ------------------------------------------------------------ AJAX: pipeline-tábla */

/* v1.94: könnyű „változott-e valami?” lekérdezés a Deal-ek nézet automatikus frissítéséhez (új / módosult deal, új megkeresés) */
add_action('wp_ajax_nopriv_cp_crm_stamp', 'cp_crm_ajax_stamp');
add_action('wp_ajax_cp_crm_stamp', 'cp_crm_ajax_stamp');
function cp_crm_ajax_stamp() {
	cp_crm_ajax_guard();
	global $wpdb;
	/* v2.9.58: olcsó változásjelző – a változásszámláló (cp_crm_rev: deal- és megkeresés-változáskor nő) + két indexes MAX();
	   a teljes táblát számoló COUNT csak akkor fut, ha a jelző változott (az eredmény a jelzőhöz kötve megmarad) */
	$m = (string) $wpdb->get_var('SELECT MAX(modified_at) FROM ' . cp_crm_t('deals'));
	$lm = function_exists('cp_lead_t') ? (int) $wpdb->get_var('SELECT MAX(id) FROM ' . cp_lead_t()) : 0;
	$k = md5(cp_crm_rev() . '|' . $m . '|' . $lm);
	$c = get_option('cp_crm_stamp_c');
	if (!is_array($c) || ($c['k'] ?? '') !== $k) {
		$c = array('k' => $k, 'n' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . cp_crm_t('deals') . ' WHERE deleted_at IS NULL'), 'ln' => function_exists('cp_lead_t') ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . cp_lead_t()) : 0);
		update_option('cp_crm_stamp_c', $c, false);
	}
	wp_send_json_success(array('k' => $k, 'n' => (int) $c['n'], 'ln' => (int) $c['ln']));
}

add_action('wp_ajax_nopriv_cp_crm_board', 'cp_crm_ajax_board');
add_action('wp_ajax_cp_crm_board', 'cp_crm_ajax_board');
function cp_crm_ajax_board() {
	cp_crm_ajax_guard(array('deals'));
	wp_send_json_success(cp_crm_board_data(wp_unslash($_REQUEST)));
}

function cp_crm_board_data($a) {
	global $wpdb;
	/* nyitott deal-ek (v2.9.58: a már nem küldött „won” / „own” paraméter kivezetve) */
	$w = "d.status = 'Open'";
	/* v2.9.53: összetett szűrő; ha van benne állapot-feltétel, az dönt (nem csak a nyitottak) */
	$cfp = function_exists('cp_cf_parse') ? cp_cf_parse($a['cf'] ?? '') : null;
	if ($cfp && cp_cf_has_status($cfp)) { $w = '1=1'; }
	$cfw = $cfp ? cp_cf_where($cfp) : '';
	if ($cfw !== '') { $w .= ' AND ' . $cfw; }
	$rows = $wpdb->get_results('SELECT ' . cp_crm_list_cols() . ' FROM ' . cp_crm_list_from() . " WHERE d.deleted_at IS NULL AND $w ORDER BY d.event_d IS NULL, d.event_d ASC, d.id DESC LIMIT 2001", ARRAY_A);
	$more = count((array) $rows) > 2000;
	if ($more) { $rows = array_slice($rows, 0, 2000); }
	$out = array();
	foreach ((array) $rows as $r) { $out[] = cp_crm_list_row($r); }
	/* a következő nyitott tevékenység deal-enként (a deal-hez vagy a kontaktjához kötve) – a kártya teendő-jelzéséhez */
	if ($out) {
		$ids = implode(',', array_map(function ($x) { return (int) $x['id']; }, $out));
		$na = array();
		foreach ((array) $wpdb->get_results('SELECT related_id rid, MIN(due_at) due FROM ' . cp_crm_t('activities') . " WHERE deleted_at IS NULL" . cp_crm_act_hide_sql() . " AND (completed IS NULL OR completed = 0) AND (related_module = '4' OR LOWER(related_module) LIKE 'deal%') AND related_id IN ($ids) GROUP BY related_id", ARRAY_A) as $x) { $na[(int) $x['rid']] = cp_crm_local($x['due']); }
		foreach ($out as &$x) { $x['na'] = isset($na[$x['id']]) ? $na[$x['id']] : ''; }
		unset($x);
	}
	/* v1.82: becsült ár az ajánlat előtti dealekre (estimate.php) */
	if ($out && function_exists('cp_est_for_rows')) { $E = cp_est_for_rows($out); foreach ($out as &$x) { if (isset($E[$x['id']])) { $x['est'] = $E[$x['id']]; } } unset($x); }
	/* v2.5: visszatérő ügyfél (loyal.php) */
	if ($out && function_exists('cp_ret_for_rows')) { $RT = cp_ret_for_rows($out); foreach ($out as &$x) { if (isset($RT[$x['id']])) { $x['ret'] = $RT[$x['id']]; } } unset($x); }
	/* v1.84: kapacitás a rendezvény napján (capacity.php) – az első két szakasz jövőbeli dealjeinél */
	if ($out && function_exists('cp_cap_for_rows')) { $K = cp_cap_for_rows($out); foreach ($out as &$x) { if (isset($K[$x['id']])) { $x['cap'] = $K[$x['id']]; } } unset($x); }
	return array('rows' => $out, 'more' => $more, 'sync' => cp_crm_sync_at(), 'now' => wp_date('Y-m-d H:i'));
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
	/* v2.9.122: rohadás a panel szabályai szerint (a helyi idő átírása előtt) */
	list($deal['rot'], $deal['rw']) = cp_crm_rot($deal + array('stage_at' => $wpdb->get_var($wpdb->prepare('SELECT MAX(at) FROM ' . cp_crm_t('history') . " WHERE deal_id = %d AND field = 'stage'", $id)), 'last_out' => $wpdb->get_var($wpdb->prepare('SELECT ' . cp_crm_lastout_sql() . ' FROM ' . cp_crm_t('deals') . ' d WHERE d.id = %d', $id))));
	foreach (array('closed_at', 'last_note_at', 'last_comm_at', 'created_at', 'modified_at', 'start_at', 'synced_at', 'deleted_at') as $k) { $deal[$k] = cp_crm_local($deal[$k]); }
	/* v1.83.2: ajánlat előtt (első szakasz, érték nélkül) becsült ár, mint a csempén */
	if (function_exists('cp_est_deal') && function_exists('cp_crm_stages') && $deal['status'] === 'Open' && $deal['stage'] === (cp_crm_stages()[0] ?? '')) { $e = function_exists('cp_est_any') ? cp_est_any($deal) : cp_est_deal($deal); if ($e) { $deal['est'] = $e; } } /* v2.9.77: beírt ár mellett is (az ajánlatlevél tételeihez) */
	/* v2.9.70: többnapos rendezvény: a napok listája (az 1. nap igazítva az event_d-hez) és a tömör kiírás */
	$mdays = function_exists('cp_mday_get') && !empty($deal['event_d']) ? cp_mday_align(cp_mday_get($id), (string) $deal['event_d']) : array();
	if ($mdays) { $deal['days'] = $mdays; $deal['days_label'] = cp_mday_label(wp_list_pluck($mdays, 'day')); }
	if (function_exists('cp_cap_day') && $deal['status'] === 'Open' && !empty($deal['event_d']) && ($deal['event_d'] >= wp_date('Y-m-d') || ($mdays && end($mdays)['day'] >= wp_date('Y-m-d')))) {
		/* v2.9.70: többnaposnál a legszűkebb nap (a dupla foglalás minden napot figyel) */
		$k = $mdays ? cp_mday_cap_worst(wp_list_pluck($mdays, 'day')) : cp_cap_day($deal['event_d']);
		if ($k) { $deal['cap'] = $k; }
	}
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
	$acts = $wpdb->get_results('SELECT id, title, type, owner_id, due_at, duration, completed, completed_at, description, related_id, related_module FROM ' . cp_crm_t('activities') . " WHERE deleted_at IS NULL" . cp_crm_act_hide_sql() . " AND ($aw) ORDER BY due_at DESC LIMIT 50", ARRAY_A);
	foreach ($acts as &$a) { $a['due_at'] = cp_crm_local($a['due_at']); $a['completed_at'] = cp_crm_local($a['completed_at']); $a['owner'] = cp_crm_user_name($a['owner_id']); }
	unset($a);
	$hist = $wpdb->get_results($wpdb->prepare('SELECT field, old_val, new_val, by_user, at FROM ' . cp_crm_t('history') . ' WHERE deal_id = %d ORDER BY at DESC, id DESC LIMIT 100', $id), ARRAY_A);
	foreach ($hist as &$h) {
		$h['at'] = cp_crm_local($h['at']);
		/* v2.9.4: a kezdés időpontja a táblában világidőben (UTC) van – a változásnaplóban helyi időben, másodperc nélkül */
		if ($h['field'] === 'start_at') { foreach (array('old_val', 'new_val') as $k) { if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', (string) $h[$k])) { $h[$k] = substr((string) cp_crm_local(str_replace('T', ' ', substr((string) $h[$k], 0, 19))), 0, 16); } } }
	}
	unset($h);
	return apply_filters('cp_crm_deal_data', array('deal' => $deal, 'contact' => $contact, 'company' => $company, 'others' => $others, 'acts' => $acts, 'hist' => $hist, 'sync' => cp_crm_sync_at()));
}

/* ------------------------------------------------------------ AJAX: szinkron most (Frissítés gomb; percenként legfeljebb egyszer) */

add_action('wp_ajax_nopriv_cp_crm_sync', 'cp_crm_ajax_sync');
add_action('wp_ajax_cp_crm_sync', 'cp_crm_ajax_sync');
function cp_crm_ajax_sync() {
	cp_crm_ajax_guard(array('deals'));
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
	/* v1.99.2: foglalt szakaszba / megnyertre lépéskor: a deal háttere aznap szabad-e még (két deal ugyanazzal az egyetlen háttérrel) */
	$warn = '';
	$booked = array_map('cp_panel_lc', cp_panel_lines($o['booked_stages']));
	if ((($to !== '' && in_array(cp_panel_lc($to), $booked, true)) || $st === 'Won') && $o['f_bg'] !== '' && function_exists('cp_portal_bg_taken')) {
		$bg = cp_panel_str(cp_panel_get($chk, $o['f_bg'])); $day = cp_panel_norm_date(cp_panel_get($chk, $o['f_date']));
		$k = cp_panel_lc($bg);
		if ($k !== '' && $k !== 'még nem tudom' && $day !== '') {
			$qty = 0; foreach (cp_panel_bg_list() as $b) { if (cp_panel_lc($b['name']) === $k) { $qty = (int) $b['qty']; } }
			$taken = cp_portal_bg_taken($day, (string) $id);
			if ($taken === null) { $warn = 'A háttér („' . $bg . '”) foglaltságát most nem sikerült ellenőrizni – nézd meg a Hátterek fülön.'; }
			elseif ($qty > 0 && isset($taken[$k]) && $taken[$k] >= $qty) { $warn = '⚠ A(z) „' . $bg . '” háttér ' . $day . '-n már foglalt egy másik dealnél – egyeztessétek (Hátterek fül).'; }
		}
	}
	wp_send_json_success(array('row' => cp_crm_row_out($id), 'changed' => true, 'warn' => $warn));
}

function cp_crm_row_out($id, $cols_raw = null) {
	global $wpdb;
	/* v2.9.54: a lista választott oszlopai is (gyors szerkesztés után a sor frissítéséhez) */
	if ($cols_raw === null && isset($_POST['cols'])) { $cols_raw = wp_unslash($_POST['cols']); }
	list($xs, $xk) = $cols_raw !== null ? cp_crm_xcols($cols_raw) : array('', array());
	$r = $wpdb->get_row($wpdb->prepare('SELECT ' . cp_crm_list_cols() . $xs . ' FROM ' . cp_crm_list_from() . ' WHERE d.id = %d', $id), ARRAY_A);
	if (!$r) { return null; }
	return cp_crm_list_row($r, $xk);
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
	/* v2.9.58: a szűrő gyorsítótárazott értéklistájából (cp_cf_opts, ugyanígy gyakoriság szerint), ha megvan benne */
	$co = function_exists('cp_cf_opts') ? cp_cf_opts() : array();
	$vals = isset($co['deal.' . $f]) ? array_slice($co['deal.' . $f], 0, 25) : $wpdb->get_col('SELECT ' . $f . ' v FROM ' . cp_crm_t('deals') . " WHERE deleted_at IS NULL AND $f <> '' GROUP BY $f ORDER BY COUNT(*) DESC LIMIT 25");
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
	cp_crm_ajax_guard(array('deals'));
	cp_admin_need(); /* v1.63: végleges törlés a Salesmate-ben – csak admin */
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (empty(cp_panel_opts()['stage_write'])) { cp_el_err('A Salesmate-be írás a panel beállításaiban ki van kapcsolva.'); }
	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
	if ($id <= 0 || (isset($_POST['ok']) ? (string) wp_unslash($_POST['ok']) : '') !== 'TÖRLÉS') { cp_el_err('Hibás kérés.'); }
	$plan = cp_crm_del_plan($id); /* v2.9.19: a deallel együtt törlendők (a Salesmate-törlés előtt, amíg az adatok megvannak) */
	if (function_exists('cp_bin_put')) { cp_bin_put($id, $plan); } /* v2.9.182: Lomtár – 30 napig visszaállítható */
	$res = cp_sm_request('DELETE', '/apis/deal/v4/' . $id);
	if (is_wp_error($res)) { if (function_exists('cp_bin_drop')) { cp_bin_drop($id); } cp_el_err($res->get_error_message(), 502); }
	$chk = cp_sm_get_deal($id);
	/* v2.1.2: a Salesmate a törölt deal-t „isDeleted / deletedAt” jelöléssel még visszaadja */
	$gone = is_wp_error($chk) || empty($chk) || !cp_panel_get($chk, 'id') || !empty($chk['isDeleted']) || !empty($chk['deletedAt']);
	if (!$gone) { if (function_exists('cp_bin_drop')) { cp_bin_drop($id); } cp_el_err('A Salesmate nem törölte a dealt.', 502); }
	global $wpdb;
	$wpdb->update(cp_crm_t('deals'), array('deleted_at' => gmdate('Y-m-d H:i:s')), array('id' => $id));
	cp_crm_rev_bump();
	$wpdb->insert(cp_crm_t('history'), array('deal_id' => $id, 'field' => 'deleted', 'old_val' => '', 'new_val' => 'törölve', 'by_user' => function_exists('cp_panel_who') ? cp_panel_who() : 'Crazy panel', 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel'));
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	wp_send_json_success(array('id' => $id, 'clean' => cp_crm_del_clean($id, $plan)));
}

/* v2.9.19: deal törlésekor minden hozzá tartozó adat megszűnik: ügyfélportál (fájlok, adatlap, linkek, napló),
 * Google Naptár-bejegyzés(ek), a deal levélszálai a Kukába. A számlák maradnak (jogszabály). */
function cp_crm_del_plan($id) {
	global $wpdb;
	$d = $wpdb->get_row($wpdb->prepare('SELECT d.id, d.title, d.event_d, c.name cn FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE d.id = %d', $id), ARRAY_A);
	$out = array('portal' => 0, 'files' => 0, 'cal' => array(), 'cal_err' => '', 'threads' => array());
	if (function_exists('cp_portal_get_by_deal') && function_exists('cp_portal_db_ready') && cp_portal_db_ready() && ($p = cp_portal_get_by_deal((string) $id))) {
		$out['portal'] = (int) $p->id;
		$a = cp_portal_answers($p->id);
		$vals = array_merge(array($a['photo_template'] ?? null, $a['template_logo'] ?? null), (array) ($a['photo_template_hist'] ?? array()));
		foreach ($vals as $v) { foreach (array('logo', 'preview', 'file', 'png') as $k) { if (is_array($v) && !empty($v[$k])) { $out['files']++; } } }
	}
	if ($d && !empty($d['event_d']) && function_exists('cp_gcal_ok') && cp_gcal_ok()) {
		$made = get_option('cp_gcal_made', array());
		$ev = cp_gcal_events($d['event_d'], $d['event_d'], true);
		if (is_wp_error($ev)) { $out['cal_err'] = $ev->get_error_message(); }
		else {
			/* az adott nap összes dealje kell a párosításhoz, különben egy hasonló nevű másik ügyfél bejegyzése is ide sorolódna */
			$all = cp_gcal_deals($d['event_d'], $d['event_d']);
			if (!in_array((int) $id, array_map('intval', array_column($all, 'id')), true)) { $all[] = $d; }
			list($byDate, $byId) = cp_gcal_index($all);
			foreach ($ev as $e) { if ((is_array($made) && ($made[$id] ?? '') === $e['id']) || cp_gcal_match($e, $byDate, $byId) === (int) $id) { $out['cal'][$e['id']] = $e['t']; } }
			/* v2.9.71: a többnapos rendezvény panel által létrehozott napi bejegyzései (napi kulcs) */
			foreach ((is_array($made) ? $made : array()) as $mk => $eid) { if (strpos((string) $mk, (int) $id . ':') === 0 && !isset($out['cal'][$eid])) { $out['cal'][$eid] = 'napi bejegyzés: ' . substr((string) $mk, strlen((int) $id . ':')); } }
		}
	}
	if (function_exists('cp_mail_t')) { $out['threads'] = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . cp_mail_t('threads') . " WHERE deal_id = %d AND folder <> 'trash'", $id))); }
	return $out;
}
function cp_crm_del_clean($id, $plan) {
	global $wpdb;
	$r = array();
	if ($plan['portal']) {
		$pid = (int) $plan['portal'];
		$a = cp_portal_answers($pid);
		if (function_exists('cp_portal_sb_files_delete')) { foreach (array_merge(array($a['photo_template'] ?? null, $a['template_logo'] ?? null), (array) ($a['photo_template_hist'] ?? array())) as $v) { cp_portal_sb_files_delete($v); } }
		foreach (array('form_answers', 'form_state', 'notifications', 'log', 'access_tokens', 'access_log') as $t) { $wpdb->delete(cp_portal_t($t), array('portal_id' => $pid)); }
		$wpdb->delete(cp_portal_t('portals'), array('id' => $pid));
		if (function_exists('cp_portal_retention_log')) { cp_portal_retention_log('portál törölve (deal törlése)', $pid, 1); }
		$r[] = 'ügyfélportál' . ($plan['files'] ? ' és ' . $plan['files'] . ' fájlja' : '');
	}
	if ($plan['cal']) {
		$ok = 0; $made = get_option('cp_gcal_made', array());
		foreach ($plan['cal'] as $eid => $t) { $x = cp_gcal_req('DELETE', '/events/' . rawurlencode($eid)); if (!is_wp_error($x)) { $ok++; } }
		if (is_array($made)) { foreach (array_keys($made) as $mk) { if ((string) $mk === (string) (int) $id || strpos((string) $mk, (int) $id . ':') === 0) { unset($made[$mk]); } } update_option('cp_gcal_made', $made, false); } /* v2.9.71: a napi kulcsok is */
		update_option('cp_gcal_gen', (int) get_option('cp_gcal_gen', 0) + 1, false);
		$r[] = $ok . ' naptárbejegyzés' . ($ok < count($plan['cal']) ? ' (' . (count($plan['cal']) - $ok) . ' nem sikerült)' : '');
	} elseif ($plan['cal_err'] !== '') { $r[] = 'a naptárat nem értem el: ' . $plan['cal_err']; }
	if ($plan['threads']) {
		$T = cp_mail_t('threads');
		foreach ($plan['threads'] as $tid) { $wpdb->query($wpdb->prepare("UPDATE $T SET prev_folder = IF(folder IN ('trash','spam'), 'inbox', folder), folder = 'trash' WHERE id = %d", $tid)); }
		$r[] = count($plan['threads']) . ' levélszál a Kukába';
	}
	/* v2.9.70: a többnapos rendezvény napjai (a döntés szerint a deallel minden törlődik) */
	if (function_exists('cp_mday_db_ok') && cp_mday_db_ok() && $wpdb->delete(cp_mday_t(), array('deal_id' => (int) $id))) { $r[] = 'a rendezvénynapok listája'; }
	return $r;
}

/* a törlés megerősítése előtt: mi törlődik a deallel együtt */
add_action('wp_ajax_nopriv_cp_crm_del_info', 'cp_crm_ajax_del_info');
add_action('wp_ajax_cp_crm_del_info', 'cp_crm_ajax_del_info');
function cp_crm_ajax_del_info() {
	cp_crm_ajax_guard(array('deals'));
	cp_admin_need();
	$p = cp_crm_del_plan((int) ($_POST['id'] ?? 0));
	wp_send_json_success(array('portal' => $p['portal'] ? 1 : 0, 'files' => $p['files'], 'cal' => count($p['cal']), 'cal_err' => $p['cal_err'], 'threads' => count($p['threads'])));
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
	if ($type === 'time') { return $want === null ? cp_crm_ts($nv) === 0 : abs(cp_crm_ts($nv) - strtotime($want)) < 60; }
	if ($type === 'multi') { return cp_crm_multi_list($nv) === cp_crm_multi_list($want); }
	return str_replace("\r\n", "\n", cp_panel_str($nv)) === (string) $want;
}

/* v2.9.58: a Salesmate időpont-értéke → Unix-idő: a crm.php cp_crm_ts() függvénye (a korábbi cp_crm_dt_ts ugyanezt csinálta) */

/* választólisták a szerkesztőhöz: a szöveges mezők eddigi értékei és a többválasztós mezők lehetőségei */
add_action('wp_ajax_nopriv_cp_crm_opts', 'cp_crm_ajax_opts');
add_action('wp_ajax_cp_crm_opts', 'cp_crm_ajax_opts');
function cp_crm_ajax_opts() {
	cp_crm_ajax_guard();
	global $wpdb;
	$t = cp_crm_t('deals');
	$out = array();
	/* v2.9.58: a szűrő gyorsítótárazott értéklistáiból (cp_cf_opts: ugyanazok a mezők, gyakoriság szerint), új lekérdezés csak ha hiányzik */
	$co = function_exists('cp_cf_opts') ? cp_cf_opts() : array();
	foreach (array('source', 'service', 'pkg_fix', 'background', 'operator', 'album', 'template_st', 'pay_method', 'paid', 'ctype') as $f) {
		if (isset($co['deal.' . $f])) { $out[$f] = array_slice($co['deal.' . $f], 0, 30); continue; }
		$out[$f] = array_values(array_map('strval', (array) $wpdb->get_col("SELECT $f FROM $t WHERE deleted_at IS NULL AND $f <> '' GROUP BY $f ORDER BY COUNT(*) DESC LIMIT 30")));
	}
	foreach (array('services_ec', 'services_cp', 'services_more', 'extras') as $f) {
		if (isset($co['deal.' . $f])) { $out[$f] = array_slice($co['deal.' . $f], 0, 40); continue; }
		$n = array();
		foreach ((array) $wpdb->get_col("SELECT $f FROM $t WHERE deleted_at IS NULL AND $f <> '' ORDER BY id DESC LIMIT 1500") as $v) { foreach (explode(' | ', $v) as $x) { $x = trim($x); if ($x !== '') { $n[$x] = (isset($n[$x]) ? $n[$x] : 0) + 1; } } }
		arsort($n);
		$out[$f] = array_slice(array_keys($n), 0, 40);
	}
	/* v2.9.127: a gépkezelő választólistája elöl a Kezelők fül aktív kezelői és hostjai */
	if (function_exists('cp_kz_t')) {
		$kn = array_map('strval', (array) $wpdb->get_col('SELECT name FROM ' . cp_kz_t() . " WHERE active = 1 AND kind IN ('kezelo','host') AND name <> '' ORDER BY kind = 'host', name"));
		$out['operator'] = array_values(array_unique(array_merge($kn, (array) ($out['operator'] ?? array()))));
	}
	if (function_exists('cp_svc_on') && cp_svc_on()) { $out['svc'] = cp_svc_list(); } /* v2.9.218 */
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
	/* v2.9.218: egységes szolgáltatások – a panel saját táblájába, a Salesmate-be nem */
	if (isset($f['svc']) && is_scalar($f['svc']) && function_exists('cp_svc_on') && cp_svc_on()) { cp_svc_set($id, array_filter(explode(' | ', (string) $f['svc']))); }
	unset($f['svc']);
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
	/* v1.65: a rendezvény dátumának változása azonnal az ügyfélportálra is (határidő, lezárás / újranyitás) */
	/* v1.93: bármely deal-mező mentésekor (háttér, csomag, óraszám, helyszín…) – nem csak dátumváltozáskor */
	if ($body && function_exists('cp_portal_get_by_deal') && function_exists('cp_portal_sm_refresh') && function_exists('cp_portal_db_ready') && cp_portal_db_ready()) {
		$pp = cp_portal_get_by_deal((string) $id);
		if ($pp) { cp_portal_sm_refresh($pp, 0); }
	}
	return array('row' => cp_crm_row_out($id), 'warn' => implode('; ', $warn));
}

/* új deal: kontakt (meglévő e-mail-cím alapján, vagy új), majd a deal a fő pipeline-ban */
add_action('wp_ajax_nopriv_cp_crm_create', 'cp_crm_ajax_create');
/* új kontakt a Salesmate-ben, és azonnal a saját táblában is (v2.9.90: közös – új deal és a levél „+” gombja is ezt használja).
   Hibánál cp_el_err-rel kilép; siker: a kontakt azonosítója. */
function cp_crm_contact_new($name, $email, $phone) {
	$cr = cp_sm_request('POST', '/apis/contact/v4', array_filter(array('firstName' => '', 'lastName' => $name, 'email' => $email, 'mobile' => $phone), function ($v) { return $v !== ''; }));
	if (is_wp_error($cr)) { cp_el_err('A kontaktot nem sikerült létrehozni: ' . $cr->get_error_message(), 502); }
	$cid = cp_crm_new_id($cr);
	if (!$cid) { cp_el_err('A kontakt létrejött, de a Salesmate nem adta vissza az azonosítóját: ' . mb_substr(wp_json_encode($cr), 0, 200), 502); }
	/* v2.0.1: a kontakt azonnal a saját táblába is – ha a folytatás elakad, az újrapróbálás ne hozzon létre második kontaktot */
	$cg0 = cp_sm_request('GET', '/apis/contact/v4/' . $cid);
	if (!is_wp_error($cg0)) { cp_crm_store('contact', array(isset($cg0['Data']) ? $cg0['Data'] : $cg0), 'get'); }
	return (int) $cid;
}
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
	$cid = 0;
	/* v2.5: a duplikátum-figyelőben kiválasztott meglévő kontakt */
	$pick = (int) $g('c_id');
	if ($pick && $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_crm_t('contacts') . ' WHERE id = %d AND deleted_at IS NULL', $pick))) { $cid = $pick; }
	/* v2.0.1: közös e-mail-cím (pl. info@ceg.hu): csak akkor ugyanaz a kontakt, ha a név is egyezik (vagy nincs megadva) – különben új kontakt */
	if (!$cid && $email !== '') {
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, name FROM ' . cp_crm_t('contacts') . ' WHERE email = %s AND deleted_at IS NULL ORDER BY id DESC LIMIT 10', $email), ARRAY_A) as $c) {
			$nn = function ($x) { return trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $x))); };
			if ($name === '' || $nn($c['name']) === $nn($name) || $nn($c['name']) === '') { $cid = (int) $c['id']; break; }
		}
	}
	if (!$cid) { $cid = cp_crm_contact_new($name, $email, $phone); }
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
	/* v2.9.218: egységes szolgáltatások – a panel saját táblájába (a Salesmate-be nem) */
	if (function_exists('cp_svc_on') && cp_svc_on() && $g('svc') !== '') { cp_svc_set($id, explode(' | ', $g('svc')), ''); }
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
