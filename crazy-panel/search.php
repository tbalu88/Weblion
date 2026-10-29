<?php
/* =====================================================================
 * Univerzális kereső és Kapcsolatok (v1.49)
 * – cp_search: egy mezőből deal-ekre, kontaktokra, cégekre, levelekre (tárgy és szöveg) és feladatkártyákra keres,
 *   csak abban, amihez a felhasználónak van füle (a mások személyes tábláin lévő kártyák kimaradnak).
 * – cp_crm_people: a Kapcsolatok fül listája – kontaktok vagy cégek, a deal-jeik számával és a megnyert összeggel.
 * Csak olvas; a Salesmate-hez nem nyúl.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_srch_can($tab) { return !function_exists('cp_panel_can') || cp_panel_can($tab); }
/* ékezet- és kisbetű-független LIKE minden szóra */
function cp_srch_words($q) { return array_slice(preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY), 0, 5); }
function cp_srch_like($hay, $words) {
	global $wpdb;
	$and = array();
	foreach ($words as $w) { $and[] = $wpdb->prepare("CONVERT($hay USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE %s", '%' . $wpdb->esc_like($w) . '%'); }
	return '(' . implode(' AND ', $and) . ')';
}

add_action('wp_ajax_nopriv_cp_search', 'cp_srch_ajax');
add_action('wp_ajax_cp_search', 'cp_srch_ajax');
function cp_srch_ajax() {
	cp_el_auth();
	wp_send_json_success(cp_srch_data((string) wp_unslash($_REQUEST['q'] ?? '')));
}
function cp_srch_data($q) {
	global $wpdb;
	$q = trim(mb_substr($q, 0, 80));
	$out = array('deals' => array(), 'contacts' => array(), 'companies' => array(), 'mail' => array(), 'cards' => array(), 'q' => $q);
	if (mb_strlen($q) < 2) { return $out; }
	$words = cp_srch_words($q);
	$digits = preg_replace('/\D/', '', $q);
	$crm = function_exists('cp_crm_panel_on') && cp_crm_panel_on() && cp_srch_can('deals');
	if ($crm) {
		/* deal-ek: a deal-lista keresője (név, e-mail, telefon, helyszín, csomag, #szám) – lezártak is */
		$r = cp_crm_list_data(array('q' => $q, 'st' => '', 'so' => 'mod'));
		foreach (array_slice($r['rows'], 0, 8) as $d) { $out['deals'][] = array('id' => $d['id'], 'title' => $d['title'], 'st' => $d['status'], 'stage' => $d['stage'], 'ev' => $d['ev'], 'cn' => $d['cn'], 'v' => $d['value']); }
		$C = cp_crm_t('contacts'); $CO = cp_crm_t('companies'); $D = cp_crm_t('deals');
		$cw = cp_srch_like("CONCAT_WS(' ', c.name, c.email, c.city)", $words);
		if (strlen($digits) >= 6) { $pl = '%' . $wpdb->esc_like(substr($digits, -8)) . '%'; $cw = '(' . $cw . $wpdb->prepare(" OR REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '/', '') LIKE %s OR REPLACE(REPLACE(REPLACE(c.mobile, ' ', ''), '-', ''), '/', '') LIKE %s", $pl, $pl) . ')'; }
		foreach ((array) $wpdb->get_results("SELECT c.id, c.name, c.email, co.name co, (SELECT COUNT(*) FROM $D d WHERE d.contact_id = c.id AND d.deleted_at IS NULL) n FROM $C c LEFT JOIN $CO co ON co.id = c.company_id WHERE c.deleted_at IS NULL AND $cw ORDER BY n DESC, c.id DESC LIMIT 6", ARRAY_A) as $c) {
			$out['contacts'][] = array('id' => (int) $c['id'], 'n' => $c['name'] !== '' ? $c['name'] : $c['email'], 'e' => $c['email'], 'co' => (string) $c['co'], 'c' => (int) $c['n']);
		}
		$ow = cp_srch_like("CONCAT_WS(' ', co.name, co.website)", $words);
		foreach ((array) $wpdb->get_results("SELECT co.id, co.name, (SELECT COUNT(*) FROM $C c WHERE c.company_id = co.id AND c.deleted_at IS NULL) n FROM $CO co WHERE co.deleted_at IS NULL AND $ow ORDER BY n DESC, co.id DESC LIMIT 5", ARRAY_A) as $c) {
			$out['companies'][] = array('id' => (int) $c['id'], 'n' => $c['name'], 'c' => (int) $c['n']);
		}
	}
	/* levelek: tárgy / partner / szöveg – a szövegben teljes szavas kereséssel */
	if (function_exists('cp_mail_db_ok') && cp_mail_db_ok() && cp_srch_can('mail')) {
		$T = cp_mail_t('threads'); $M = cp_mail_t('messages');
		$tw = cp_srch_like("CONCAT_WS(' ', t.subject, t.contact_name, t.contact_email, t.snippet)", $words);
		$rows = (array) $wpdb->get_results("SELECT t.id, t.subject, t.contact_name, t.contact_email, t.last_at, t.status, t.folder, '' hit FROM $T t WHERE t.folder NOT IN ('trash', 'spam') AND $tw ORDER BY t.last_at DESC LIMIT 6", ARRAY_A);
		$seen = array_map(function ($r) { return (int) $r['id']; }, $rows);
		$ft = implode(' ', array_map(function ($w) { return '+' . preg_replace('/[^\p{L}\p{N}@._-]/u', '', $w) . '*'; }, array_filter($words, function ($w) { return mb_strlen($w) >= 3; })));
		if (count($rows) < 6 && trim(str_replace(array('+', '*'), '', $ft)) !== '') {
			$more = (array) $wpdb->get_results($wpdb->prepare("SELECT t.id, t.subject, t.contact_name, t.contact_email, t.last_at, t.status, t.folder, SUBSTRING(m.body, 1, 4000) hit FROM $M m JOIN $T t ON t.id = m.thread_id WHERE t.folder NOT IN ('trash', 'spam') AND MATCH(m.body) AGAINST (%s IN BOOLEAN MODE) ORDER BY m.sent_at DESC LIMIT 30", $ft), ARRAY_A);
			foreach ($more as $r) { if (count($rows) >= 8) { break; } if (!in_array((int) $r['id'], $seen, true)) { $rows[] = $r; $seen[] = (int) $r['id']; } }
		}
		foreach ($rows as $r) {
			$snip = '';
			if ($r['hit'] !== '') {
				$txt = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(str_replace(array('<br>', '</p>'), ' ', (string) $r['hit']))));
				$pos = mb_stripos($txt, $words[0]);
				$snip = ($pos > 40 ? '…' : '') . mb_substr($txt, max(0, ($pos === false ? 0 : $pos) - 40), 140) . '…';
			}
			$out['mail'][] = array('id' => (int) $r['id'], 'subj' => $r['subject'], 'cn' => $r['contact_name'] !== '' ? $r['contact_name'] : $r['contact_email'], 'at' => function_exists('cp_crm_local') ? cp_crm_local($r['last_at']) : $r['last_at'], 'st' => $r['status'], 'snip' => $snip);
		}
	}
	/* feladatkártyák: a csapattáblák és a saját személyes tábla */
	if (function_exists('cp_kb_t') && get_option('cp_kb_db_ver') && cp_srch_can('feladatok')) {
		$me = function_exists('cp_kb_me') ? (int) cp_kb_me() : 0;
		$kw = cp_srch_like("CONCAT_WS(' ', k.title, k.descr)", $words);
		$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT k.id, k.title, k.done, k.due, b.name board, l.name list FROM ' . cp_kb_t('cards') . ' k JOIN ' . cp_kb_t('boards') . ' b ON b.id = k.board_id LEFT JOIN ' . cp_kb_t('lists') . " l ON l.id = k.list_id WHERE k.archived_at IS NULL AND b.deleted_at IS NULL AND (b.kind = 'team' OR (b.kind = 'personal' AND b.owner_uid = %d AND %d > 0)) AND $kw ORDER BY k.done, k.id DESC LIMIT 6", $me, $me), ARRAY_A);
		foreach ($rows as $r) { $out['cards'][] = array('id' => (int) $r['id'], 'title' => $r['title'], 'done' => (int) $r['done'], 'due' => $r['due'], 'where' => trim($r['board'] . ' · ' . $r['list'], ' ·')); }
	}
	return $out;
}

/* ------------------------------------------------------------ Kapcsolatok: kontaktok és cégek listája */
add_action('wp_ajax_nopriv_cp_crm_people', 'cp_srch_ajax_people');
add_action('wp_ajax_cp_crm_people', 'cp_srch_ajax_people');
function cp_srch_ajax_people() {
	cp_crm_ajax_guard();
	if (!cp_srch_can('kapcsolatok')) { cp_el_err('Ehhez a fülhöz nincs hozzáférésed.', 403); }
	wp_send_json_success(cp_srch_people_data(wp_unslash($_REQUEST)));
}
function cp_srch_people_data($a) {
	global $wpdb;
	$C = cp_crm_t('contacts'); $CO = cp_crm_t('companies'); $D = cp_crm_t('deals');
	$kind = ($a['kind'] ?? '') === 'co' ? 'co' : 'ct';
	$q = trim(mb_substr((string) ($a['q'] ?? ''), 0, 80));
	$so = sanitize_key((string) ($a['so'] ?? 'recent'));
	$only = !empty($a['only']);
	$p = max(0, (int) ($a['p'] ?? 0)); $n = 50;
	$words = $q !== '' ? cp_srch_words($q) : array();
	if ($kind === 'ct') {
		$w = array('c.deleted_at IS NULL');
		if ($words) {
			$x = cp_srch_like("CONCAT_WS(' ', c.name, c.email, c.city, co.name)", $words);
			$dg = preg_replace('/\D/', '', $q);
			if (strlen($dg) >= 6) { $pl = '%' . $wpdb->esc_like(substr($dg, -8)) . '%'; $x = '(' . $x . $wpdb->prepare(" OR REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '/', '') LIKE %s OR REPLACE(REPLACE(REPLACE(c.mobile, ' ', ''), '-', ''), '/', '') LIKE %s", $pl, $pl) . ')'; }
			$w[] = $x;
		}
		$from = "FROM $C c LEFT JOIN $CO co ON co.id = c.company_id LEFT JOIN $D d ON d.contact_id = c.id AND d.deleted_at IS NULL WHERE " . implode(' AND ', $w) . ' GROUP BY c.id' . ($only ? ' HAVING COUNT(d.id) > 0' : '');
		$sel = "SELECT c.id, c.name, c.email, c.phone, c.mobile, c.city, c.created_at, co.id co_id, co.name co, COUNT(d.id) n, SUM(d.status = 'Won') won, SUM(CASE WHEN d.status = 'Won' THEN d.value ELSE 0 END) won_v, SUM(d.status = 'Open') open, MAX(d.event_d) last_ev, MAX(d.created_at) last_d";
	} else {
		$w = array('co.deleted_at IS NULL');
		if ($words) { $w[] = cp_srch_like("CONCAT_WS(' ', co.name, co.website)", $words); }
		if ($only) { $w[] = 'a.n > 0'; }
		/* a deal-ek cégenként előre összesítve (a deal cége, vagy ha nincs, a kontaktjáé) */
		$agg = "SELECT COALESCE(d.company_id, c.company_id) cid, COUNT(*) n, SUM(d.status = 'Won') won, SUM(CASE WHEN d.status = 'Won' THEN d.value ELSE 0 END) won_v, SUM(d.status = 'Open') open, MAX(d.event_d) last_ev, MAX(d.created_at) last_d FROM $D d LEFT JOIN $C c ON c.id = d.contact_id WHERE d.deleted_at IS NULL GROUP BY cid";
		$from = "FROM $CO co LEFT JOIN ($agg) a ON a.cid = co.id LEFT JOIN (SELECT company_id, COUNT(*) cts FROM $C WHERE deleted_at IS NULL AND company_id IS NOT NULL GROUP BY company_id) k ON k.company_id = co.id WHERE " . implode(' AND ', $w);
		$sel = 'SELECT co.id, co.name, co.website, co.phone, COALESCE(k.cts, 0) cts, COALESCE(a.n, 0) n, COALESCE(a.won, 0) won, COALESCE(a.won_v, 0) won_v, COALESCE(a.open, 0) open, a.last_ev, a.last_d';
	}
	/* MySQL: összesített álnév csak önmagában állhat a rendezésben (a NULL a csökkenő sorrend végére kerül) */
	$orders = array('recent' => 'last_d DESC', 'deals' => 'n DESC, last_d DESC', 'won' => 'won_v DESC, n DESC', 'name' => ($kind === 'ct' ? 'c.name' : 'co.name') . ' ASC', 'event' => 'last_ev DESC');
	$ord = $orders[$so] ?? $orders['recent'];
	$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT 1 $from) z");
	$rows = (array) $wpdb->get_results("$sel $from ORDER BY $ord LIMIT $n OFFSET " . ($p * $n), ARRAY_A);
	foreach ($rows as &$r) {
		foreach (array('id', 'n', 'won', 'open', 'cts', 'co_id') as $k) { if (isset($r[$k])) { $r[$k] = (int) $r[$k]; } }
		$r['won_v'] = (float) $r['won_v'];
		if (isset($r['created_at'])) { $r['created_at'] = cp_crm_local($r['created_at']); }
		$r['last_d'] = cp_crm_local($r['last_d']);
	}
	unset($r);
	return array('rows' => $rows, 'total' => $total, 'p' => $p, 'n' => $n, 'kind' => $kind);
}
