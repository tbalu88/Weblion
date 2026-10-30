<?php
/* =====================================================================
 * Átállás a Salesmate-ről (v1.70) – funkciónkénti kapcsolók, árnyék-üzem, egyeztető riport
 * Állások: sm (a Salesmate csinálja) · shadow (a panel kiszámolja, mit tenne, de nem hajtja végre – és összeveti a
 * Salesmate tényleges lépésével) · live (a panel csinálja, a Salesmate-be is visszaír) · only (csak a panel).
 * Az árnyék-üzem semmit nem ír a Salesmate-be és nem küld semmit: csak naplóz és összehasonlít.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_CUT_DB_VER', '1');
function cp_cut_t() { global $wpdb; return $wpdb->prefix . 'crazy_cut_log'; }

add_action('init', function () {
	if (get_option('cp_cut_db_ver') === CP_CUT_DB_VER || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_cut_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  feat varchar(20) NOT NULL DEFAULT '',
  ref varchar(40) NOT NULL DEFAULT '',
  deal_id bigint(20) unsigned NULL,
  plan longtext NULL,
  actual longtext NULL,
  status varchar(10) NOT NULL DEFAULT 'pending',
  diff text NULL,
  created_at datetime NULL,
  checked_at datetime NULL,
  PRIMARY KEY  (id),
  KEY feat (feat),
  KEY status (status),
  KEY created_at (created_at)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_cut_db_ver', CP_CUT_DB_VER, false);
}, 8);
function cp_cut_db_ok() { return get_option('cp_cut_db_ver') === CP_CUT_DB_VER; }

/* a kapcsolható funkciók: kulcs => array(név, leírás, a panel már képes-e élesben csinálni) */
function cp_cut_feats() {
	return array(
		'forms_ec'    => array('EventCrazy űrlap → deal', 'Az eventcrazy.hu ajánlatkérő űrlapjából deal (név, felelős, mezők).', false),
		'forms_cp'    => array('Crazyphoto űrlap → deal', 'A crazyphoto.hu főoldali űrlapjából deal.', false),
		'fb'          => array('Facebook Lead Ads → deal', 'A Facebook-hirdetések űrlapjaiból deal (Make-forgatókönyvvel).', false),
		'flow_rename' => array('Átnevezés', '„[Forrás] Név | dátum” deal-név új dealnél.', false),
		'flow_pumble' => array('Új deal → Pumble', 'Értesítés a csatornába új dealnél.', false),
		'flow_t2'     => array('T-2 nap: ÉRKEZÜNK levél', 'Levél a rendezvény előtt 2 nappal.', false),
		'flow_lement' => array('Rendezvény lement', 'A rendezvény utáni napon a következő szakaszba.', false),
		'flow_portal' => array('Ügyfélportál-meghívó', 'Meghívó levél az „Adatlap kiküldve” szakaszban.', false),
		'calendar'    => array('Google Naptár (eventcrazy)', 'Rendezvény-bejegyzés a „Szerződés kiküldve” szakaszban.', false),
		'mail_send'   => array('Levélküldés a panelből', 'Mindenki a panelből küld, megnyitás-követéssel.', false),
	);
}
function cp_cut_modes() { $m = get_option('cp_cut_modes', array()); return is_array($m) ? $m : array(); }
function cp_cut_mode($k) { $m = cp_cut_modes(); return isset($m[$k]) ? (string) $m[$k] : ($k === 'forms_ec' || $k === 'forms_cp' ? 'shadow' : 'sm'); }

function cp_cut_log($feat, $ref, $plan, $deal = 0) {
	if (!cp_cut_db_ok()) { return 0; }
	global $wpdb;
	$wpdb->insert(cp_cut_t(), array('feat' => $feat, 'ref' => mb_substr((string) $ref, 0, 40), 'deal_id' => $deal ?: null, 'plan' => wp_json_encode($plan, JSON_UNESCAPED_UNICODE), 'status' => 'pending', 'created_at' => gmdate('Y-m-d H:i:s')));
	return (int) $wpdb->insert_id;
}

/* ------------------------------------------------------------ webes űrlapok: a panel deal-terve egy beküldésből */

/* a felelős körbeforgó kiosztással (a kollégák listája állítható; árnyékban külön mutatóval) */
function cp_cut_rr_owner($feat) {
	$rr = get_option('cp_cut_rr', array('users' => array(19, 25)));
	$users = array_values(array_filter(array_map('intval', (array) ($rr['users'] ?? array()))));
	if (!$users) { return 0; }
	$k = 'p_' . $feat; $i = (int) ($rr[$k] ?? 0);
	$rr[$k] = ($i + 1) % count($users);
	update_option('cp_cut_rr', $rr, false);
	return $users[$i % count($users)];
}

function cp_cut_plan_lead($pairs, $page, $feat) {
	$src = $feat === 'forms_cp' ? 'Crazyphoto.hu' : 'Eventcrazy.hu';
	$cols = function_exists('cp_lead_cols') ? cp_lead_cols() : array();
	$f = array();
	foreach ((array) $pairs as $k => $v) {
		if ($v === '' || !isset($cols[$k])) { continue; }
		$f[$cols[$k]] = $v;
	}
	if (!empty($f['event_d']) && preg_match('/(\d{4})[.\-\/ ]+(\d{1,2})[.\-\/ ]+(\d{1,2})/', (string) $f['event_d'], $m)) { $f['event_d'] = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]); }
	$email = sanitize_email(cp_lead_pick($pairs, '/e-?mail/i'));
	$first = cp_lead_pick($pairs, '/first.?name|keresztn/i'); $last = cp_lead_pick($pairs, '/last.?name|vezet[eé]kn/i');
	$name = trim(cp_lead_pick($pairs, '/(^|\.)(name|n[eé]v|full.?name)$/i') ?: trim($last . ' ' . $first));
	$o = function_exists('cp_panel_opts') ? cp_panel_opts() : array();
	$plan = array(
		'title' => '[' . $src . '] ' . $name . (!empty($f['event_d']) ? ' | ' . $f['event_d'] : ''),
		'pipeline' => (string) ($o['pipeline'] ?? ''), 'stage' => function_exists('cp_crm_stages') ? cp_crm_stages()[0] : '',
		'owner_id' => cp_cut_rr_owner($feat), 'source' => $src, 'c_name' => $name, 'c_email' => $email,
		'c_phone' => cp_lead_pick($pairs, '/phone|mobile|telefon|mobil/i'),
	);
	foreach (array('event_d', 'venue', 'guests', 'hours', 'service', 'services_ec', 'services_cp', 'note') as $c) { if (isset($f[$c])) { $plan[$c] = (string) $f[$c]; } }
	return $plan;
}

/* a figyelő minden beküldésnél szól (leads.php) */
add_action('cp_lead_received', function ($id, $pairs, $page) {
	$feat = stripos((string) $page, 'crazyphoto') !== false ? 'forms_cp' : 'forms_ec';
	if (cp_cut_mode($feat) !== 'shadow') { return; }
	cp_cut_log($feat, 'lead:' . (int) $id, cp_cut_plan_lead($pairs, $page, $feat));
}, 10, 3);

/* ------------------------------------------------------------ egyeztetés: a terv és a Salesmate valódi lépése */

function cp_cut_norm($k, $v) {
	$v = trim(preg_replace('/\s+/u', ' ', (string) $v));
	if ($k === 'c_email') { return mb_strtolower($v); }
	if ($k === 'guests' || $k === 'hours') { return (string) (int) $v; }
	if ($k === 'event_d') { return substr($v, 0, 10); }
	return $v;
}

function cp_cut_reconcile() {
	if (!cp_cut_db_ok() || !function_exists('cp_crm_t')) { return; }
	global $wpdb;
	if (function_exists('cp_lead_match')) { cp_lead_match(); }
	$L = cp_cut_t();
	foreach ((array) $wpdb->get_results("SELECT * FROM $L WHERE status = 'pending' AND ref LIKE 'lead:%' ORDER BY id LIMIT 100", ARRAY_A) as $r) {
		$lid = (int) substr($r['ref'], 5);
		$deal = (int) $wpdb->get_var($wpdb->prepare('SELECT deal_id FROM ' . cp_lead_t() . ' WHERE id = %d', $lid));
		if (!$deal) {
			if (strtotime($r['created_at'] . ' UTC') < time() - DAY_IN_SECONDS) { $wpdb->update($L, array('status' => 'missing', 'diff' => 'A Salesmate-ben 24 órán belül nem jött létre deal ehhez a beküldéshez.', 'checked_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $r['id'])); }
			continue;
		}
		$d = $wpdb->get_row($wpdb->prepare('SELECT d.title, d.pipeline, d.stage, d.owner_id, d.source, d.event_d, d.venue, d.guests, d.hours, d.service, d.created_at, c.email c_email, c.name c_name FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE d.id = %d', $deal), ARRAY_A);
		if (!$d) { continue; }
		/* a létrehozás óta kézzel módosított mezőknél a kezdeti érték számít (a változásnaplóból) */
		/* (a létrehozás utáni 15 percben az automatizmusok – pl. az Átnevezés – dolgoznak, azok a Salesmate lépései) */
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT field, old_val FROM ' . cp_crm_t('history') . ' WHERE deal_id = %d AND at > DATE_ADD(%s, INTERVAL 15 MINUTE) ORDER BY id DESC', $deal, $d['created_at']), ARRAY_A) as $h) { if (array_key_exists($h['field'], $d)) { $d[$h['field']] = (string) $h['old_val']; } }
		$plan = json_decode((string) $r['plan'], true) ?: array();
		$hard = array(); $soft = array();
		$L2 = array('title' => 'Deal neve', 'pipeline' => 'Pipeline', 'stage' => 'Szakasz', 'source' => 'Forrás', 'event_d' => 'Rendezvény dátuma', 'venue' => 'Helyszín', 'guests' => 'Létszám', 'hours' => 'Időtartam', 'service' => 'Szolgáltatás', 'c_email' => 'Kontakt e-mail', 'owner_id' => 'Felelős');
		foreach ($L2 as $k => $lab) {
			if (!array_key_exists($k, $plan) && !in_array($k, array('pipeline', 'stage', 'source', 'title', 'owner_id'), true)) { continue; }
			$a = cp_cut_norm($k, $d[$k] ?? ''); $p = cp_cut_norm($k, $plan[$k] ?? '');
			if ($a === $p) { continue; }
			$line = $lab . ': panel „' . ($k === 'owner_id' && function_exists('cp_crm_user_name') ? cp_crm_user_name((int) $p) : $p) . '” · Salesmate „' . ($k === 'owner_id' && function_exists('cp_crm_user_name') ? cp_crm_user_name((int) $a) : $a) . '”';
			if ($k === 'owner_id') { $soft[] = $line; } else { $hard[] = $line; }
		}
		$wpdb->update($L, array('deal_id' => $deal, 'actual' => wp_json_encode($d, JSON_UNESCAPED_UNICODE), 'status' => $hard ? 'diff' : 'match', 'diff' => implode("\n", array_merge($hard, $soft)), 'checked_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $r['id']));
	}
}
add_action('cp_crm_tick', 'cp_cut_reconcile', 30);

/* ------------------------------------------------------------ a felület (Beállítások → Átállás, csak admin) */

function cp_cut_auth() {
	cp_el_auth();
	cp_tab_need(array('beallitasok'));
	cp_admin_need();
	if (!cp_cut_db_ok()) { cp_el_err('Az átállási napló még készül – próbáld újra egy perc múlva.', 503); }
}

function cp_cut_data() {
	global $wpdb;
	$L = cp_cut_t();
	$since = gmdate('Y-m-d H:i:s', time() - 21 * DAY_IN_SECONDS);
	$by = array();
	foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT feat, DATE(CONVERT_TZ(created_at, '+00:00', %s)) d, status, COUNT(*) n FROM $L WHERE created_at >= %s GROUP BY feat, d, status", wp_date('P'), $since), ARRAY_A) as $r) { $by[$r['feat']][$r['d']][$r['status']] = (int) $r['n']; }
	$feats = array();
	foreach (cp_cut_feats() as $k => $f) {
		$days = $by[$k] ?? array();
		/* hibátlan munkanapok sorozata: visszafelé, a hétvégét átugorva; nap, amelyen volt eset és nem volt eltérés / hiány */
		$streak = 0;
		for ($i = 0; $i < 21; $i++) {
			$ts = time() - $i * DAY_IN_SECONDS; $dw = (int) wp_date('N', $ts); $dk = wp_date('Y-m-d', $ts);
			if ($dw >= 6) { continue; }
			$x = $days[$dk] ?? array();
			if (!empty($x['diff']) || !empty($x['missing'])) { break; }
			if (!empty($x['match'])) { $streak++; } elseif ($i > 0) { /* eset nélküli munkanap: nem tör, nem számít */ }
		}
		$tot = array('match' => 0, 'diff' => 0, 'missing' => 0, 'pending' => 0);
		foreach ($days as $x) { foreach ($x as $s => $n) { $tot[$s] = ($tot[$s] ?? 0) + $n; } }
		$feats[] = array('k' => $k, 'n' => $f[0], 'd' => $f[1], 'live_ok' => $f[2], 'mode' => cp_cut_mode($k), 'tot' => $tot, 'streak' => $streak);
	}
	$recent = array();
	foreach ((array) $wpdb->get_results("SELECT id, feat, deal_id, plan, status, diff, created_at FROM $L ORDER BY id DESC LIMIT 40", ARRAY_A) as $r) {
		$p = json_decode((string) $r['plan'], true) ?: array();
		$recent[] = array('id' => (int) $r['id'], 'feat' => $r['feat'], 'deal' => (int) $r['deal_id'], 'title' => (string) ($p['title'] ?? ''), 'st' => $r['status'], 'diff' => (string) $r['diff'], 'at' => function_exists('cp_crm_local') ? cp_crm_local($r['created_at']) : $r['created_at']);
	}
	$rr = get_option('cp_cut_rr', array('users' => array(19, 25)));
	return array('feats' => $feats, 'recent' => $recent, 'rr' => array_values(array_map('intval', (array) ($rr['users'] ?? array()))), 'users' => function_exists('cp_crm_users') ? array_map(function ($u) { return $u['n']; }, cp_crm_users()) : array());
}

add_action('wp_ajax_nopriv_cp_cut_get', 'cp_cut_ajax_get');
add_action('wp_ajax_cp_cut_get', 'cp_cut_ajax_get');
function cp_cut_ajax_get() {
	cp_cut_auth();
	if (!empty($_POST['check'])) { cp_cut_reconcile(); }
	wp_send_json_success(cp_cut_data());
}

add_action('wp_ajax_nopriv_cp_cut_set', 'cp_cut_ajax_set');
add_action('wp_ajax_cp_cut_set', 'cp_cut_ajax_set');
function cp_cut_ajax_set() {
	cp_cut_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$F = cp_cut_feats();
	if (isset($_POST['rr'])) {
		$ids = array_values(array_filter(array_map('intval', explode(',', (string) wp_unslash($_POST['rr']))), function ($i) { return function_exists('cp_crm_users') && isset(cp_crm_users()[$i]); }));
		if (!$ids) { cp_el_err('Válassz legalább egy kollégát.'); }
		$rr = get_option('cp_cut_rr', array()); $rr['users'] = $ids; update_option('cp_cut_rr', $rr, false);
		wp_send_json_success(cp_cut_data());
	}
	$k = sanitize_key(wp_unslash($_POST['feat'] ?? ''));
	$m = sanitize_key(wp_unslash($_POST['mode'] ?? ''));
	if (!isset($F[$k]) || !in_array($m, array('sm', 'shadow', 'live', 'only'), true)) { cp_el_err('Hibás kérés.'); }
	if (in_array($m, array('live', 'only'), true) && !$F[$k][2]) { cp_el_err('Ezt a funkciót a panel még nem tudja élesben – előbb árnyék-üzemben kell hibátlanul futnia.'); }
	$all = cp_cut_modes(); $all[$k] = $m; update_option('cp_cut_modes', $all, false);
	wp_send_json_success(cp_cut_data());
}
