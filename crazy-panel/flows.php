<?php
/* =====================================================================
 * Automatizmusok (v1.44) – a Salesmate Smart Flow-k mása a panelben, folyamatonként kapcsolhatóan:
 *   Ki · Árnyék (csak naplózza, mit csinálna, és utólag ellenőrzi, hogy a Salesmate ugyanezt tette-e) · Élő.
 * A folyamatok leírása (indító, feltételek, lépések) adatszerkezet – később a panelben szerkeszthető lesz.
 * Az engine 10 percenként fut a panel saját adatain: új deal-ek (a szinkronból), szakaszváltások (változásnapló),
 * rendezvénydátumhoz kötött időpontok. Élő módban a Salesmate-be ír (ugyanúgy, mint a panel többi írása).
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_FLOW_DB_VER', '1');
function cp_flow_t() { global $wpdb; return $wpdb->prefix . 'crazy_flow_log'; }

add_action('init', function () {
	if (get_option('cp_flow_db_ver') === CP_FLOW_DB_VER || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_flow_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  flow varchar(20) NOT NULL DEFAULT '',
  deal_id bigint(20) unsigned NULL,
  mode varchar(8) NOT NULL DEFAULT '',
  action varchar(20) NOT NULL DEFAULT '',
  detail text NULL,
  expect_f varchar(20) NOT NULL DEFAULT '',
  expect_v varchar(255) NOT NULL DEFAULT '',
  result varchar(20) NOT NULL DEFAULT '',
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY flow (flow, created_at),
  KEY deal_id (deal_id)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_flow_db_ver', CP_FLOW_DB_VER, false);
}, 7);
function cp_flow_db_ok() { return get_option('cp_flow_db_ver') === CP_FLOW_DB_VER; }

/* ------------------------------------------------------------ a folyamatok (a Salesmate Smart Flow-k alapján)
 * trigger: deal_created | stage_enter {stage} | date {days (+ után / − előtt), hour} | contact_tag {tag} | manual | none
 * conds: [{f, op (eq, neq, in, empty, not_empty, this_week), v}]  ·  steps: [{a (set_title, set_stage, pumble, activity, email, branch, stop), …}] */
function cp_flow_defaults() {
	return array(
		'fb_lead' => array('n' => 'Facebook lead → új deal', 'sm' => 'Aktív', 'folder' => 'Rendszer automatizálások', 'live_ok' => false,
			'why' => 'A Facebook Lead Ads űrlap most a Salesmate-be köt; ennek átvételéhez az űrlapot közvetlenül a panelhez kell kötni.',
			'def' => array('trigger' => array('type' => 'contact_tag', 'tag' => 'Facebook Lead Ads'), 'conds' => array(),
				'steps' => array(array('a' => 'note', 't' => 'A rendezvény dátumát az eventcrazy.hu /crazy/v1/datum végpontja értelmezi (ha nem értelmezhető: 2099.12.31., cím: „DÁTUM PONTOSÍTANDÓ”)'),
					array('a' => 'note', 't' => 'Deal létrehozása: „[Facebook Lead Ads] {név} | {dátum}”, felelős: Andrea Gyarmati, Crazyphoto megrendelés / Érdeklődő / Megrendelő, forrás: Facebook Lead Ads, szolgáltatás: Szelfigép, helyszín: a kontakt városa'),
					array('a' => 'note', 't' => '3 perc várakozás, majd a cím visszaírása (az „Átnevezés” után)')))),
		'rename' => array('n' => 'Átnevezés', 'sm' => 'Aktív', 'live_ok' => true,
			'def' => array('trigger' => array('type' => 'deal_created'), 'conds' => array(array('f' => 'source', 'op' => 'not_empty')),
				'steps' => array(array('a' => 'set_title', 'v' => '[{source}] {contact} | {event_date}')))),
		'pumble' => array('n' => 'Új deal → Pumble értesítés', 'sm' => 'Aktív', 'live_ok' => true,
			'def' => array('trigger' => array('type' => 'deal_created'), 'conds' => array(),
				'steps' => array(array('a' => 'pumble', 'v' => "🆕 Új deal: {title}\n📅 Dátum: {event_date}\n🛠 Szolgáltatás: {services}\n📝 Megjegyzés: {note}\n🔗 {link}")))),
		'calendar' => array('n' => 'Naptárba ceruzásan', 'sm' => 'Aktív', 'live_ok' => false,
			'why' => 'A tevékenység a Salesmate-ből szinkronizálódik a Google Naptárba – amíg a naptár nincs átkötve, maradjon a Salesmate-ben.',
			'def' => array('trigger' => array('type' => 'stage_enter', 'stage' => 'Szerződés kiküldve'), 'conds' => array(),
				'steps' => array(array('a' => 'activity', 'v' => 'EC | {contact} | {event_date}', 'type' => 'Crazyphoto aktivitás', 'owner' => 'Tóth Balázs', 'due' => 'event_d')))),
		't2' => array('n' => 'T-2 nap: ÉRKEZÜNK', 'sm' => 'Aktív', 'folder' => 'Automata emailek', 'live_ok' => false,
			'why' => 'Levelet küld – élesben a panelből küldés bekötése után kapcsolható.',
			'def' => array('trigger' => array('type' => 'date', 'days' => -2, 'hour' => 8), 'conds' => array(array('f' => 'stage', 'op' => 'eq', 'v' => 'Szerződés aláírva'), array('f' => 'status', 'op' => 'eq', 'v' => 'Open')),
				'steps' => array(array('a' => 'branch', 'if' => array('f' => 'last_comm_at', 'op' => 'this_week'), 'then' => array(array('a' => 'note', 't' => 'ezen a héten már volt kommunikáció: nem megy levél')),
					'else' => array(array('a' => 'email', 'by' => 'ctype', 'map' => array('KFT' => 'EC - T-2 nap', 'EV' => 'CP - T-2 nap')))),
					array('a' => 'set_stage', 'v' => 'Rendezvény felkészül')))),
		'lement' => array('n' => 'Rendezvény lement', 'sm' => 'Aktív', 'live_ok' => true,
			'def' => array('trigger' => array('type' => 'date', 'days' => 1, 'hour' => 7), 'conds' => array(array('f' => 'status', 'op' => 'eq', 'v' => 'Open'), array('f' => 'stage', 'op' => 'in', 'v' => array('Szerződés aláírva', 'Rendezvény felkészül'))),
				'steps' => array(array('a' => 'set_stage', 'v' => 'Rendezvény lement (G&R)')))),
		'teszt' => array('n' => 'TESZT – FB dátum konverzió', 'sm' => 'Publikált, csak kézzel indul', 'live_ok' => false, 'why' => 'Teszt folyamat, semmit nem módosít – a Salesmate-ben törölhető.',
			'def' => array('trigger' => array('type' => 'manual'), 'conds' => array(), 'steps' => array(array('a' => 'note', 't' => 'API-hívás egy nem létező végpontra, majd keresés – nincs írás')))),
		'portal' => array('n' => 'Ügyfélportál meghívó', 'sm' => 'Piszkozat (nem fut)', 'live_ok' => false, 'why' => 'Levelet küld – élesben a panelből küldés bekötése után kapcsolható.',
			'def' => array('trigger' => array('type' => 'stage_enter', 'stage' => 'Adatlap kiküldve'), 'conds' => array(array('f' => 'portal_link', 'op' => 'not_empty')),
				'steps' => array(array('a' => 'email', 'tpl' => 'Ügyfélportál – meghívó')))),
		'gallery' => array('n' => 'Online galéria & Greview', 'sm' => 'Üres piszkozat', 'live_ok' => false, 'why' => 'Még nincs benne se indító, se lépés.',
			'def' => array('trigger' => array('type' => 'none'), 'conds' => array(), 'steps' => array())),
	);
}
function cp_flows() {
	$o = get_option('cp_flows', array());
	$out = array();
	foreach (cp_flow_defaults() as $id => $f) {
		$x = is_array($o[$id] ?? null) ? $o[$id] : array();
		$f['mode'] = in_array($x['mode'] ?? 'off', array('off', 'shadow', 'live'), true) ? ($x['mode'] ?? 'off') : 'off';
		if (!$f['live_ok'] && $f['mode'] === 'live') { $f['mode'] = 'shadow'; }
		if (isset($x['def']) && is_array($x['def'])) { $f['def'] = $x['def']; }
		$f['id'] = $id;
		$out[$id] = $f;
	}
	return $out;
}

/* ------------------------------------------------------------ segédek */
function cp_flow_deal($id) {
	global $wpdb;
	$d = $wpdb->get_row($wpdb->prepare('SELECT d.*, c.name cn FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE d.id = %d', $id), ARRAY_A);
	return $d ?: null;
}
function cp_flow_cond($d, $c) {
	$v = (string) ($d[$c['f']] ?? '');
	switch ($c['op']) {
		case 'eq': return mb_strtolower($v) === mb_strtolower((string) $c['v']);
		case 'neq': return mb_strtolower($v) !== mb_strtolower((string) $c['v']);
		case 'in': return in_array(mb_strtolower($v), array_map('mb_strtolower', (array) $c['v']), true);
		case 'empty': return trim($v) === '';
		case 'not_empty': return trim($v) !== '';
		case 'this_week': if ($v === '') { return false; } $t = strtotime($v . ' UTC'); return $t && wp_date('o-W', $t) === wp_date('o-W');
	}
	return false;
}
function cp_flow_fill($tpl, $d) {
	$svc = implode(' ', array_filter(array_map(function ($k) use ($d) { return str_replace(' | ', ', ', (string) ($d[$k] ?? '')); }, array('service', 'package', 'services_ec', 'services_cp', 'services_more'))));
	$panel = function_exists('cp_pu_panel_url') ? cp_pu_panel_url() : home_url('/');
	return strtr($tpl, array('{title}' => (string) $d['title'], '{source}' => (string) $d['source'], '{contact}' => (string) ($d['cn'] ?? ''), '{event_date}' => (string) $d['event_d'],
		'{services}' => $svc, '{note}' => (string) ($d['note'] ?? ''), '{id}' => (string) $d['id'], '{link}' => $panel . '#deals:' . $d['id']));
}
function cp_flow_log($flow, $deal, $mode, $action, $detail, $ef = '', $ev = '', $result = 'logged') {
	global $wpdb;
	$wpdb->insert(cp_flow_t(), array('flow' => $flow, 'deal_id' => $deal ?: null, 'mode' => $mode, 'action' => $action, 'detail' => mb_substr((string) $detail, 0, 2000), 'expect_f' => $ef, 'expect_v' => mb_substr((string) $ev, 0, 255), 'result' => $result, 'created_at' => gmdate('Y-m-d H:i:s')));
}

/* egy lépés: árnyékban csak naplóz, élőben végrehajtja (csak ha az adott lépés élőben támogatott) */
function cp_flow_step($f, $s, $d, $mode) {
	$id = (int) $d['id'];
	switch ($s['a']) {
		case 'set_title':
			$new = trim(cp_flow_fill($s['v'], $d));
			if ($new === (string) $d['title']) { cp_flow_log($f['id'], $id, $mode, 'set_title', 'A cím már ez: „' . $new . '”', 'title', $new, 'same'); return; }
			if ($mode === 'live') { $r = function_exists('cp_sm_put_deal') ? cp_sm_put_deal($id, array('title' => $new)) : new WP_Error('x', 'nincs'); cp_flow_log($f['id'], $id, $mode, 'set_title', 'Cím: „' . $new . '”', 'title', $new, is_wp_error($r) ? 'error' : 'done'); return; }
			cp_flow_log($f['id'], $id, $mode, 'set_title', 'Átnevezné: „' . $new . '”', 'title', $new); return;
		case 'set_stage':
			if ((string) $d['stage'] === $s['v']) { cp_flow_log($f['id'], $id, $mode, 'set_stage', 'Már ebben a szakaszban van: ' . $s['v'], 'stage', $s['v'], 'same'); return; }
			if ($mode === 'live') {
				$r = new WP_Error('x', 'nincs');
				if (function_exists('cp_sm_get_deal') && function_exists('cp_sm_put_deal')) { $sd = cp_sm_get_deal($id); $body = array('stage' => $s['v']); if (!is_wp_error($sd)) { $pl = cp_panel_get($sd, 'pipeline'); if (is_string($pl) && trim($pl) !== '') { $body['pipeline'] = trim($pl); } } $r = cp_sm_put_deal($id, $body); }
				cp_flow_log($f['id'], $id, $mode, 'set_stage', 'Szakasz: ' . $d['stage'] . ' → ' . $s['v'], 'stage', $s['v'], is_wp_error($r) ? 'error' : 'done'); return;
			}
			cp_flow_log($f['id'], $id, $mode, 'set_stage', 'Szakaszt váltana: ' . $d['stage'] . ' → ' . $s['v'], 'stage', $s['v']); return;
		case 'pumble':
			$txt = cp_flow_fill($s['v'], $d);
			if ($mode === 'live') {
				$hook = function_exists('cp_nt_hook_get') ? cp_nt_hook_get('notif_hook') : '';
				$r = $hook !== '' && function_exists('cp_el_pumble') ? cp_el_pumble($txt, $hook) : new WP_Error('x', 'Nincs beállítva Pumble webhook');
				cp_flow_log($f['id'], $id, $mode, 'pumble', $txt, '', '', is_wp_error($r) ? 'error' : 'done'); return;
			}
			cp_flow_log($f['id'], $id, $mode, 'pumble', 'Pumble-üzenetet küldene: ' . $txt); return;
		case 'activity':
			cp_flow_log($f['id'], $id, $mode, 'activity', 'Tevékenységet hozna létre: „' . cp_flow_fill($s['v'], $d) . '” (' . $s['type'] . ', ' . $s['owner'] . ', határidő: ' . $d['event_d'] . ')'); return;
		case 'email':
			$tpl = $s['tpl'] ?? (($s['map'] ?? array())[strtoupper(trim((string) ($d[$s['by'] ?? ''] ?? '')))] ?? '');
			cp_flow_log($f['id'], $id, $mode, 'email', $tpl !== '' ? 'Levelet küldene a kontaktnak: „' . $tpl . '”' : 'Nem menne levél (a „' . ($s['by'] ?? '') . '” mező üres vagy ismeretlen)', '', '', $tpl !== '' ? 'logged' : 'skipped'); return;
		case 'branch':
			foreach ((cp_flow_cond($d, $s['if']) ? $s['then'] : $s['else']) as $s2) { cp_flow_step($f, $s2, $d, $mode); }
			return;
		case 'note':
			cp_flow_log($f['id'], $id, $mode, 'note', $s['t'], '', '', 'skipped'); return;
	}
}
function cp_flow_run($f, $id) {
	$d = cp_flow_deal($id);
	if (!$d) { return; }
	foreach ((array) $f['def']['conds'] as $c) { if (!cp_flow_cond($d, $c)) { return; } }
	foreach ((array) $f['def']['steps'] as $s) {
		cp_flow_step($f, $s, $d, $f['mode']);
		if ($f['mode'] === 'live') { $d = cp_flow_deal($id) ?: $d; }
	}
}

/* ------------------------------------------------------------ az engine (10 percenként) */
add_action('init', function () {
	if (cp_flow_db_ok() && !wp_next_scheduled('cp_flow_tick')) { wp_schedule_event(time() + 120, 'cp_crm_10min', 'cp_flow_tick'); }
});
add_action('cp_flow_tick', 'cp_flow_tick');
function cp_flow_tick() {
	global $wpdb;
	if (!cp_flow_db_ok() || !function_exists('cp_crm_t') || get_transient('cp_flow_lock')) { return; }
	set_transient('cp_flow_lock', 1, 300);
	$st = get_option('cp_flow_state', array());
	$now = gmdate('Y-m-d H:i:s');
	$D = cp_crm_t('deals'); $H = cp_crm_t('history');
	/* első futás: innentől figyel, a múltat nem dolgozza fel */
	if (empty($st['since'])) { $st = array('since' => $now, 'hist' => (int) $wpdb->get_var("SELECT MAX(id) FROM $H"), 'days' => array()); update_option('cp_flow_state', $st, false); delete_transient('cp_flow_lock'); return; }
	$flows = array_filter(cp_flows(), function ($f) { return $f['mode'] !== 'off'; });
	if ($flows) {
		/* új deal-ek a legutóbbi futás óta (a szinkron hozza be őket) */
		$new = (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $D WHERE created_at > %s AND created_at <= %s ORDER BY created_at LIMIT 200", $st['since'], $now));
		foreach ($flows as $f) { if (($f['def']['trigger']['type'] ?? '') === 'deal_created') { foreach ($new as $id) { cp_flow_run($f, (int) $id); } } }
		/* szakaszba lépés (a változásnaplóból) */
		$hs = (array) $wpdb->get_results($wpdb->prepare("SELECT id, deal_id, new_val FROM $H WHERE id > %d AND field = 'stage' ORDER BY id LIMIT 500", (int) $st['hist']), ARRAY_A);
		foreach ($flows as $f) { if (($f['def']['trigger']['type'] ?? '') === 'stage_enter') { foreach ($hs as $h) { if ((string) $h['new_val'] === (string) $f['def']['trigger']['stage']) { cp_flow_run($f, (int) $h['deal_id']); } } } }
		/* rendezvénydátumhoz kötött időpont: naponta egyszer, a megadott óra után (budapesti idő) */
		$today = wp_date('Y-m-d'); $hour = (int) wp_date('G');
		foreach ($flows as $f) {
			$t = $f['def']['trigger'];
			if (($t['type'] ?? '') !== 'date' || $hour < (int) $t['hour'] || ($st['days'][$f['id']] ?? '') === $today) { continue; }
			$ev = gmdate('Y-m-d', strtotime($today . ' 12:00:00 UTC') - (int) $t['days'] * DAY_IN_SECONDS);
			foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $D WHERE event_d = %s AND deleted_at IS NULL LIMIT 500", $ev)) as $id) { cp_flow_run($f, (int) $id); }
			$st['days'][$f['id']] = $today;
		}
	}
	$st['since'] = $now;
	$st['hist'] = (int) ($wpdb->get_var("SELECT MAX(id) FROM $H") ?: $st['hist']);
	$st['last'] = time();
	update_option('cp_flow_state', $st, false);
	delete_transient('cp_flow_lock');
}

/* ------------------------------------------------------------ a panel: Eszközök → Automatizmusok */
function cp_flow_auth() {
	cp_el_auth();
	if (function_exists('cp_panel_can_x') && !cp_panel_can_x('flows')) { cp_el_err('Az Automatizmusok nincsenek engedélyezve a fiókodnak.', 403); }
	if (!cp_flow_db_ok()) { cp_el_err('Az adatbázis még készül.', 503); }
}
add_action('wp_ajax_nopriv_cp_flow_list', 'cp_flow_ajax_list');
add_action('wp_ajax_cp_flow_list', 'cp_flow_ajax_list');
function cp_flow_ajax_list() {
	cp_flow_auth();
	global $wpdb;
	$L = cp_flow_t();
	$since = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
	$stats = array();
	foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT flow, COUNT(*) n, COUNT(DISTINCT deal_id) d FROM $L WHERE created_at >= %s AND action <> 'note' GROUP BY flow", $since), ARRAY_A) as $r) { $stats[$r['flow']] = array('n' => (int) $r['n'], 'd' => (int) $r['d']); }
	$out = array_values(array_map(function ($f) use ($stats) { return array('id' => $f['id'], 'n' => $f['n'], 'sm' => $f['sm'], 'folder' => $f['folder'] ?? '', 'live_ok' => $f['live_ok'], 'why' => $f['why'] ?? '', 'mode' => $f['mode'], 'def' => $f['def'], 'st' => $stats[$f['id']] ?? array('n' => 0, 'd' => 0)); }, cp_flows()));
	$st = get_option('cp_flow_state', array());
	wp_send_json_success(array('flows' => $out, 'last' => !empty($st['last']) ? wp_date('Y-m-d H:i', (int) $st['last']) : '', 'since' => !empty($st['since']) ? $st['since'] : ''));
}

/* a napló, utólagos összevetéssel: amit a folyamat várt (cím / szakasz), az a Salesmate-ben (a szinkron után) is úgy lett-e */
add_action('wp_ajax_nopriv_cp_flow_log', 'cp_flow_ajax_log');
add_action('wp_ajax_cp_flow_log', 'cp_flow_ajax_log');
function cp_flow_ajax_log() {
	cp_flow_auth();
	global $wpdb;
	$flow = sanitize_key(wp_unslash($_POST['flow'] ?? ''));
	$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT l.*, d.title, d.stage FROM ' . cp_flow_t() . ' l LEFT JOIN ' . cp_crm_t('deals') . " d ON d.id = l.deal_id WHERE l.flow = %s ORDER BY l.id DESC LIMIT 80", $flow), ARRAY_A);
	$out = array();
	foreach ($rows as $r) {
		$chk = '';
		if ($r['expect_f'] !== '' && in_array($r['result'], array('logged', 'done', 'same'), true)) {
			$age = time() - strtotime($r['created_at'] . ' UTC');
			$cur = (string) ($r[$r['expect_f']] ?? '');
			$chk = $cur === $r['expect_v'] ? 'ok' : ($age < 1200 ? 'wait' : 'diff');
		}
		$out[] = array('id' => (int) $r['id'], 'deal' => (int) $r['deal_id'], 'title' => (string) $r['title'], 'mode' => $r['mode'], 'a' => $r['action'], 'detail' => $r['detail'], 'res' => $r['result'], 'chk' => $chk,
			'cur' => $r['expect_f'] !== '' ? (string) ($r[$r['expect_f']] ?? '') : '', 'at' => function_exists('cp_crm_local') ? cp_crm_local($r['created_at']) : $r['created_at']);
	}
	wp_send_json_success(array('rows' => $out));
}

add_action('wp_ajax_nopriv_cp_flow_set', 'cp_flow_ajax_set');
add_action('wp_ajax_cp_flow_set', 'cp_flow_ajax_set');
function cp_flow_ajax_set() {
	cp_flow_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (function_exists('cp_panel_is_admin') && !cp_panel_is_admin()) { cp_el_err('Az automatizmusokat csak admin kapcsolhatja.', 403); }
	$id = sanitize_key(wp_unslash($_POST['id'] ?? ''));
	$mode = sanitize_key(wp_unslash($_POST['mode'] ?? ''));
	$all = cp_flows();
	if (!isset($all[$id]) || !in_array($mode, array('off', 'shadow', 'live'), true)) { cp_el_err('Hibás kérés.'); }
	if ($mode === 'live' && !$all[$id]['live_ok']) { cp_el_err('Ez a folyamat élőben még nem kapcsolható: ' . ($all[$id]['why'] ?? '')); }
	$o = get_option('cp_flows', array());
	if (!is_array($o)) { $o = array(); }
	$o[$id] = array_merge(is_array($o[$id] ?? null) ? $o[$id] : array(), array('mode' => $mode, 'by' => function_exists('cp_panel_who') ? cp_panel_who() : '', 'at' => time()));
	update_option('cp_flows', $o, false);
	cp_flow_log($id, 0, $mode, 'switch', 'Átkapcsolva: ' . array('off' => 'Ki', 'shadow' => 'Árnyék', 'live' => 'Élő')[$mode] . (function_exists('cp_panel_who') && cp_panel_who() !== '' ? ' (' . cp_panel_who() . ')' : ''), '', '', 'skipped');
	wp_send_json_success(array('ok' => 1));
}

function cp_flow_cfg() { return array('on' => cp_flow_db_ok(), 'admin' => function_exists('cp_panel_is_admin') && cp_panel_is_admin()); }
