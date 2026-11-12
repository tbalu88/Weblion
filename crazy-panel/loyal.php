<?php
/**
 * v2.5: ügyfélmegtartás és gyorsabb ajánlatadás
 *  – N1 Visszatérő ügyfél: a levélszálnál, a deal adatlapján és a Deal-ek csempéin látszik, ha az ügyfél (kontakt vagy cége)
 *    már rendezett velünk (megnyert deal-ek száma, összege, utolsó rendezvény).
 *  – N2 Éves visszahívó: naponta egyszer megnézi, kinek volt tavaly 2 hónap múlva esedékes (megnyert) rendezvénye; ha azóta
 *    nem jött új megkeresés tőle, kártyát tesz a Feladatok közös táblájára a deal felelősének („Visszahívás”), és értesíti.
 *  – N7 Kiszállási díj: a deal helyszínéből a Crazyphoto-árkalkulátor szabályaival (Budapest XI.-ből, 130 Ft/km oda-vissza,
 *    Budapesten a csomag / szolgáltatás szerint) – a becslés (estimate.php, estimate-ec.php) számításából; egy gombbal a dealbe írható.
 */
if (!defined('ABSPATH')) { exit; }

/* ------------------------------------------------------------ N1: visszatérő ügyfél */
/* korábbi deal-ek összesítése kontakt- és cégazonosítók alapján (a $excl deal nélkül) */
function cp_ret_sum($cids, $coids, $excl = 0) {
	global $wpdb;
	$cids = array_values(array_filter(array_map('intval', (array) $cids)));
	$coids = array_values(array_filter(array_map('intval', (array) $coids)));
	if (!$cids && !$coids) { return null; }
	$D = cp_crm_t('deals');
	$or = array();
	if ($cids) { $or[] = 'contact_id IN (' . implode(',', $cids) . ')'; }
	if ($coids) { $or[] = 'company_id IN (' . implode(',', $coids) . ')'; }
	$r = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) n, SUM(status = 'Won') won, SUM(CASE WHEN status = 'Won' THEN value ELSE 0 END) won_v, MAX(CASE WHEN status = 'Won' THEN event_d END) last_ev FROM $D WHERE deleted_at IS NULL AND id <> %d AND (" . implode(' OR ', $or) . ')', (int) $excl), ARRAY_A);
	if (!$r || !(int) $r['n']) { return null; }
	$last = $r['last_ev'] ? $wpdb->get_row($wpdb->prepare("SELECT id, title, event_d FROM $D WHERE deleted_at IS NULL AND id <> %d AND status = 'Won' AND event_d = %s AND (" . implode(' OR ', $or) . ') ORDER BY id DESC LIMIT 1', (int) $excl, $r['last_ev']), ARRAY_A) : null;
	return array('n' => (int) $r['n'], 'won' => (int) $r['won'], 'won_v' => (float) $r['won_v'], 'last_ev' => (string) $r['last_ev'], 'last_id' => $last ? (int) $last['id'] : 0, 'last_t' => $last ? (string) $last['title'] : '');
}
/* egy e-mail-cím (levélszál) alapján: az összes azonos című kontakt és cégük */
function cp_ret_by_email($email, $excl = 0) {
	global $wpdb;
	$email = strtolower(trim((string) $email));
	if ($email === '' || strpos($email, '@') === false || (function_exists('cp_imp_ours') && cp_imp_ours($email))) { return null; }
	$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, company_id FROM ' . cp_crm_t('contacts') . ' WHERE deleted_at IS NULL AND email = %s LIMIT 20', $email) /* v2.9.58: a rendezés kis-nagybetű-független – így az e-mail-index használható */, ARRAY_A);
	if (!$rows) { return null; }
	$r = cp_ret_sum(array_column($rows, 'id'), array_column($rows, 'company_id'), $excl);
	if ($r) { $r['ct'] = (int) $rows[0]['id']; }
	return $r;
}
/* a Deal-ek fül soraihoz: hány megnyert deal-je volt már a kontaktnak / cégnek (csak a nyitott deal-eknél) */
function cp_ret_for_rows($rows) {
	global $wpdb;
	$ids = array();
	foreach ($rows as $r) { if (($r['status'] ?? '') === 'Open') { $ids[] = (int) $r['id']; } }
	if (!$ids) { return array(); }
	$D = cp_crm_t('deals'); $in = implode(',', array_slice($ids, 0, 1500));
	$out = array();
	/* v2.9.58: a VAGY-os önösszekapcsolás helyett két indexes összekapcsolás (kontakt, cég) uniója; a DISTINCT miatt az eredmény ugyanaz */
	$won = "o.id <> d.id AND o.deleted_at IS NULL AND o.status = 'Won'";
	foreach ((array) $wpdb->get_results("SELECT x.id, COUNT(DISTINCT x.oid) won, MAX(x.ev) last_ev FROM (
		SELECT d.id, o.id oid, o.event_d ev FROM $D d JOIN $D o ON o.contact_id = d.contact_id AND $won WHERE d.id IN ($in) AND d.contact_id IS NOT NULL
		UNION ALL
		SELECT d.id, o.id oid, o.event_d ev FROM $D d JOIN $D o ON o.company_id = d.company_id AND $won WHERE d.id IN ($in) AND d.company_id IS NOT NULL
	) x GROUP BY x.id", ARRAY_A) as $x) {
		$out[(int) $x['id']] = array('won' => (int) $x['won'], 'last_ev' => (string) $x['last_ev']);
	}
	return $out;
}

/* ------------------------------------------------------------ N7: kiszállási díj */
function cp_trv_calc($deal_id, $geocode = true) {
	global $wpdb;
	$d = $wpdb->get_row($wpdb->prepare('SELECT d.id, d.title, d.service, d.package, d.pkg_fix, d.spinner_pkg, d.services_cp, d.services_ec, d.hours, d.guests, d.venue, d.km_fee, d.event_d, d.contact_id, c.email ce FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE d.id = %d', (int) $deal_id), ARRAY_A);
	if (!$d) { return array('ok' => 0, 'msg' => 'Nincs ilyen deal.'); }
	$venue = trim((string) $d['venue']);
	$cur = (int) $d['km_fee'];
	if ($venue === '') { return array('ok' => 0, 'cur' => $cur, 'msg' => 'Add meg a helyszínt, és kiszámolom a kiszállási díjat.'); }
	if (!function_exists('cp_est_is_bp')) { return array('ok' => 0, 'cur' => $cur, 'msg' => 'A kalkulátor nem érhető el.'); }
	$bp = cp_est_is_bp($venue);
	$geo = $bp ? null : cp_est_geo_get($venue);
	if (!$bp && $geocode && (!is_array($geo) || (empty($geo['km']) && empty($geo['bp']) && time() - (int) ($geo['t'] ?? 0) > HOUR_IN_SECONDS))) {
		$geo = cp_est_geocode($venue);
		$G = get_option('cp_est_geo', array()); if (!is_array($G)) { $G = array(); }
		$G[md5(cp_est_norm_venue($venue))] = $geo;
		if (count($G) > 400) { $G = array_slice($G, -300, null, true); }
		update_option('cp_est_geo', $G, false);
	}
	if (!$bp && is_array($geo) && !empty($geo['bp'])) { $bp = true; }
	if (!$bp && (!is_array($geo) || empty($geo['km']))) { return array('ok' => 0, 'cur' => $cur, 'msg' => 'A helyszínt nem találtam a térképen – írd be a települést vagy az irányítószámot is.'); }
	$P = cp_est_prices();
	$km = $bp ? 0 : (int) $geo['km'];
	$place = $bp ? 'Budapest' : (string) ($geo['n'] ?? '');
	/* a becslés ugyanazokkal a szabályokkal számol (csomag / szolgáltatás szerinti budapesti díj, magánszemélynél bruttó) */
	$d['km_fee'] = 0;
	$e = function_exists('cp_est_deal') ? cp_est_deal($d) : null;
	$fee = null; $vat = '';
	if ($e) {
		foreach ((array) $e['l'] as $l) {
			if (mb_strpos($l, 'Kiszállás') !== 0 || mb_strpos($l, 'Kiszállás nélkül') === 0) { continue; }
			if (preg_match('/ingyenes$/u', $l)) { $fee = 0; break; }
			if (preg_match('/:\s*([\d\s\x{00A0}]+)\s*Ft$/u', $l, $m)) { $fee = (int) preg_replace('/\D/', '', $m[1]); if (mb_strpos($l, 'bruttó') !== false) { $vat = 'bruttó'; } break; }
		}
		if ($vat === '') { $vat = ($e['v'] ?? '') === 'ec' ? 'nettó' : ''; }
	}
	if ($fee === null) {
		if ($bp) { return array('ok' => 1, 'cur' => $cur, 'bp' => 1, 'fee' => null, 'place' => 'Budapest', 'how' => 'Budapesten a díj a szolgáltatástól függ (Crazyphoto-csomagnál 5 000 Ft vagy ingyenes; EventCrazy: 5 000 Ft, spinnernél 10 000, VIP spinnernél 20 000, 3 szolgáltatásnál összesen 15 000 Ft). Add meg a szolgáltatást, és pontosan kiszámolom.'); }
		$fee = $km * $P['trip'] * $P['km'];
	}
	$how = $bp ? 'Budapesten, a választott csomag / szolgáltatás szerint' : $place . ', ~' . ($km * $P['trip']) . ' km oda-vissza × ' . $P['km'] . ' Ft (Budapest XI.-ből)';
	return array('ok' => 1, 'cur' => $cur, 'bp' => $bp ? 1 : 0, 'fee' => (int) $fee, 'km' => $km * $P['trip'], 'place' => $place, 'how' => $how, 'vat' => $vat);
}
add_action('wp_ajax_nopriv_cp_crm_trv', 'cp_trv_ajax');
add_action('wp_ajax_cp_crm_trv', 'cp_trv_ajax');
function cp_trv_ajax() {
	cp_crm_ajax_guard();
	@set_time_limit(60);
	wp_send_json_success(cp_trv_calc((int) ($_REQUEST['id'] ?? 0), true));
}

/* ------------------------------------------------------------ N2: éves visszahívó */
function cp_rcl_opts() {
	$o = get_option('cp_rcl', array()); if (!is_array($o)) { $o = array(); }
	return array_merge(array('on' => '1', 'days' => 60), $o);
}
add_action('init', function () { if (!wp_next_scheduled('cp_rcl_tick') && function_exists('cp_crm_t')) { wp_schedule_event(time() + 300, 'hourly', 'cp_rcl_tick'); } });
add_action('cp_rcl_tick', 'cp_rcl_tick');
function cp_rcl_hud($ymd, $year) {
	$m = array('január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
	$p = array_map('intval', explode('-', (string) $ymd));
	if (count($p) < 3 || !$p[1]) { return (string) $ymd; }
	return ($year ? $p[0] . '. ' : '') . $m[$p[1] - 1] . ' ' . $p[2] . '.';
}
function cp_rcl_tick($force = false) {
	$o = cp_rcl_opts();
	if ($o['on'] !== '1' || !function_exists('cp_kb_t') || !function_exists('cp_kb_db_ok') || !cp_kb_db_ok()) { return array('skip' => 'ki'); }
	/* naponta egyszer, reggel 7 után (budapesti idő) */
	$today = wp_date('Y-m-d');
	if (!$force && (get_option('cp_rcl_day') === $today || (int) wp_date('G') < 7)) { return array('skip' => 'ma már futott'); }
	update_option('cp_rcl_day', $today, false);
	global $wpdb;
	$D = cp_crm_t('deals'); $C = cp_crm_t('contacts'); $CO = cp_crm_t('companies');
	$days = max(14, min(120, (int) $o['days']));
	/* tavalyi rendezvények, amelyek évfordulója $days nap múlva van (7 napos visszatekintéssel, hogy egy kimaradt nap se vesszen el) */
	$to = wp_date('Y-m-d', strtotime($today . ' +' . $days . ' days -1 year'));
	$from = wp_date('Y-m-d', strtotime($to . ' -7 days'));
	/* egyszeri pótlás (cp_rcl['catch'] = '1'): a 2 héten túli, de már 60 napon belüli évfordulók is – utána magától kikapcsol */
	$catch = ($o['catch'] ?? '') === '1';
	if ($catch) { $from = wp_date('Y-m-d', strtotime($today . ' +14 days -1 year')); }
	$done = get_option('cp_rcl_done', array()); if (!is_array($done)) { $done = array(); }
	$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT d.id, d.title, d.event_d, d.value, d.service, d.services_ec, d.venue, d.owner_id, d.contact_id, d.company_id, c.name cn, c.email ce, co.name con FROM $D d LEFT JOIN $C c ON c.id = d.contact_id LEFT JOIN $CO co ON co.id = d.company_id WHERE d.deleted_at IS NULL AND d.status = 'Won' AND d.event_d BETWEEN %s AND %s ORDER BY d.event_d", $from, $to), ARRAY_A);
	$board = (int) $wpdb->get_var('SELECT id FROM ' . cp_kb_t('boards') . " WHERE deleted_at IS NULL AND kind = 'team' ORDER BY pos, id LIMIT 1");
	$list = $board ? (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_kb_t('lists') . ' WHERE board_id = %d AND deleted_at IS NULL ORDER BY pos, id LIMIT 1', $board)) : 0;
	if (!$list) { return array('skip' => 'nincs közös feladattábla'); }
	$uids = array();
	if (function_exists('cp_pu_data')) { foreach (cp_pu_data()['list'] as $uid => $u) { if (!empty($u['a']) && !empty($u['sm'])) { $uids[(int) $u['sm']] = (int) $uid; } } }
	$made = 0; $skip = array();
	$seen = array();
	foreach ($rows as $r) {
		$id = (int) $r['id'];
		if (isset($done[$id])) { continue; }
		$key = $r['company_id'] ? 'co' . $r['company_id'] : 'ct' . $r['contact_id'];
		if (isset($seen[$key])) { $done[$id] = time(); continue; }
		$seen[$key] = 1;
		/* azóta jelentkezett már (nyitott deal, vagy fél éven belül új megkeresés)? akkor nem kell visszahívni */
		$or = array();
		if ($r['contact_id']) { $or[] = $wpdb->prepare('contact_id = %d', $r['contact_id']); }
		if ($r['company_id']) { $or[] = $wpdb->prepare('company_id = %d', $r['company_id']); }
		if (!$or) { $done[$id] = time(); continue; }
		$newer = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $D WHERE deleted_at IS NULL AND id <> %d AND (" . implode(' OR ', $or) . ") AND (status = 'Open' OR created_at >= %s OR event_d > %s)", $id, gmdate('Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS), $r['event_d']));
		if ($newer) { $done[$id] = time(); $skip[] = $id; continue; }
		$who = $r['con'] ?: ($r['cn'] ?: $r['ce']);
		$svc = (function_exists('cp_svc_on') && cp_svc_on()) ? cp_deal_svc_str($r) : trim(implode(', ', array_filter(array((string) $r['service'], str_replace(' | ', ', ', (string) $r['services_ec']))))); /* v2.9.218 */
		$ev = cp_rcl_hud($r['event_d'], true);
		$next = cp_rcl_hud(wp_date('Y-m-d', strtotime($r['event_d'] . ' 12:00 +1 year')), false);
		$title = mb_substr('🔁 Visszahívás: ' . $who . ' – egy éve: ' . $ev . ($svc !== '' ? ' (' . $svc . ')' : ''), 0, 250);
		$descr = 'Egy éve, ' . $ev . ' rendezvényük volt velünk' . ($svc !== '' ? ' (' . $svc . ')' : '') . ($r['venue'] ? ', helyszín: ' . $r['venue'] : '') . ($r['value'] ? ', ' . number_format((float) $r['value'], 0, ',', ' ') . ' Ft értékben' : '') . ".\n" .
			'Ha idén is lesz (kb. ' . $next . ' körül), most érdemes megkeresni őket – azóta nem jelentkeztek.' . "\n" . 'Kapcsolat: ' . trim(($r['cn'] ?: '') . ' ' . ($r['ce'] ? '<' . $r['ce'] . '>' : '')) . "\n\nAz egy évvel ezelőtti deal a kártyához van kötve.";
		$as = isset($uids[(int) $r['owner_id']]) ? (string) $uids[(int) $r['owner_id']] : '';
		$pos = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MIN(pos), 1) - 1 FROM ' . cp_kb_t('cards') . ' WHERE list_id = %d', $list));
		$now = gmdate('Y-m-d H:i:s');
		$wpdb->insert(cp_kb_t('cards'), array('board_id' => $board, 'list_id' => $list, 'pos' => $pos, 'title' => $title, 'descr' => $descr, 'assignees' => $as, 'labels' => '', 'due' => $today,
			'checklist' => wp_json_encode(array(array('t' => 'Felhívni / e-mailt írni', 'd' => 0), array('t' => 'Ha kér: új deal a panelben', 'd' => 0))), 'deal_id' => $id, 'created_uid' => null, 'created_by' => 'Crazy panel', 'created_at' => $now, 'updated_at' => $now));
		$cid = (int) $wpdb->insert_id;
		if ($cid && function_exists('cp_kb_log')) { cp_kb_log($cid, 'Létrehozta az éves visszahívó (az egy évvel ezelőtti rendezvény: ' . $r['event_d'] . ')'); }
		if ($cid && $as !== '' && function_exists('cp_notify')) { cp_notify((int) $as, 'kb_recall', 'visszahívandó ügyfél: ' . $who . ' (egy éve volt rendezvényük: ' . $ev . ')', 'feladatok:' . $cid, 'Crazy panel'); }
		$done[$id] = time(); $made++;
	}
	/* 2 évnél régebbi jelölések törlése */
	foreach ($done as $k => $t) { if ($t < time() - 800 * DAY_IN_SECONDS) { unset($done[$k]); } }
	update_option('cp_rcl_done', $done, false);
	if ($catch) { $o['catch'] = ''; update_option('cp_rcl', $o, false); }
	update_option('cp_rcl_last', array('at' => time(), 'made' => $made, 'from' => $from, 'to' => $to, 'cand' => count($rows), 'skip' => count($skip)), false);
	return array('made' => $made, 'cand' => count($rows), 'skip' => count($skip), 'from' => $from, 'to' => $to);
}
