<?php
/* =====================================================================
 * CRM – a Salesmate kiváltása, 1. szakasz (v1.24)
 * Saját táblák a deal-eknek, kontaktoknak, cégeknek, tevékenységeknek + változásnapló.
 * Áthozatal és szinkron a Salesmate API-ból, CSAK OLVASVA: ez a modul a Salesmate-be nem ír,
 * és a panel mostani működését sem változtatja meg (a fülek egyelőre a Salesmate-ből olvasnak tovább).
 *
 * – Kezdő áthozatal: létrehozási dátum szerinti ablakokban visszafelé (a Salesmate lapozása nem stabil,
 *   a 250 soros, telített ablakot megfelezzük) – a Statisztikák bevált módszere.
 * – Változások: 10 percenként a módosult rekordok (lastModifiedAt).
 * – Naponta: teljes újraolvasás (a Salesmate-ben törölt rekordok jelölése) és azonosítónkénti ellenőrzés
 *   a legfrissebb deal-ekre (a keresőből kimaradt deal-ek pótlása, pl. 2026. okt. 13.).
 * – Változásnapló: a szinkron minden mezőváltozást rögzít (ki, mikor, mit, mire).
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_CRM_DB_VER', '1');

function cp_crm_t($name) {
	global $wpdb;
	return $wpdb->prefix . 'crazy_crm_' . $name;
}

/* ------------------------------------------------------------ mezőtérkép: oszlop => array(Salesmate-mező, típus) */

function cp_crm_spec($m) {
	static $s = null;
	if ($s === null) {
		$s = array(
			'deal' => array(
				'title' => array('title', 'str:255'), 'pipeline' => array('pipeline', 'str:80'), 'stage' => array('stage', 'str:80'),
				'status' => array('status', 'str:12'), 'owner_id' => array('owner', 'ref'), 'value' => array('dealValue', 'dec'),
				'currency' => array('currency', 'cur'), 'contact_id' => array('primaryContact', 'ref'), 'company_id' => array('primaryCompany', 'ref'),
				'source' => array('source', 'str:80'), 'tags' => array('tags', 'multi'), 'lost_reason' => array('lostReason', 'str:100'),
				'win_prob' => array('winProbability', 'int'), 'est_close_d' => array('estimatedCloseDate', 'date'), 'closed_at' => array('closedDate', 'dt'),
				'closed_stage' => array('closedStage', 'str:80'), 'rotten_d' => array('rottenDate', 'date'), 'description' => array('description', 'text'),
				'last_note' => array('lastNote', 'text'), 'last_note_at' => array('lastNoteAddedAt', 'dt'), 'last_comm_at' => array('lastCommunicationAt', 'dt'),
				'last_comm_mode' => array('lastCommunicationMode', 'str:20'), 'created_at' => array('createdAt', 'dt'), 'created_by' => array('createdBy', 'ref'),
				'modified_at' => array('lastModifiedAt', 'dt'), 'modified_by' => array('lastModifiedBy', 'ref'),
				/* egyedi mezők (a címkék a Salesmate beállításaiból, 2026. okt.) */
				'event_d' => array('dateCustomField1', 'date'),          // Rendezvény dátuma
				'start_at' => array('dateTimeCustomField1', 'dt'),       // Kezdés időpontja
				'venue' => array('textCustomField1', 'str:255'),         // Rendezvény helyszíne
				'pkg_old' => array('textCustomField2', 'str:120'),       // Választott csomag_old
				'note' => array('textCustomField3', 'text'),             // Megjegyzés
				'pay_method' => array('textCustomField4', 'str:40'),     // Választott fizetési mód
				'background' => array('textCustomField5', 'str:80'),     // Választott Fotófal
				'reminder' => array('textCustomField6', 'str:10'),       // Menjen-e emlékeztető
				'paid' => array('textCustomField7', 'str:10'),           // Bejött az utalás?
				'operator' => array('textCustomField8', 'str:80'),       // Gépkezelő asszisztens
				'album' => array('textCustomField9', 'str:20'),          // Fotóalbum
				'template_st' => array('textCustomField10', 'str:30'),   // Template
				'ctype' => array('textCustomField11', 'str:10'),         // Szerződés EV/KFT
				'cal_check' => array('textCustomField12', 'str:60'),     // Naptár
				'service' => array('textCustomField13', 'str:100'),      // Szolgáltatás
				'spinner_pkg' => array('textCustomField14', 'str:80'),   // Spinner csomagok
				'template_paid' => array('textCustomField15', 'str:10'), // Fizetős template
				'melange_pkg' => array('textCustomField16', 'str:80'),   // Melange csomag
				'pkg_v2' => array('textCustomField17', 'str:120'),       // Választott csomag
				'pkg_fix' => array('textCustomField18', 'str:120'),      // Választott csomag2
				'template_link' => array('textCustomField19', 'str:500'),// Sablon kép (link)
				'portal_link' => array('textCustomField20', 'str:500'),  // Ügyfélportál link
				'guests' => array('intCustomField1', 'int'),             // Tervezett létszám
				'km_fee' => array('intCustomField2', 'int'),             // Kiszállási díj
				'hours' => array('intCustomField3', 'int'),              // Rendelkezésreállás hossza (óra)
				'extension' => array('decimalCustomField1', 'dec'),      // Hosszabbítás volt
				'coupon' => array('textAreaCustomField1', 'text'),       // Kuponkód
				'services_more' => array('multiSelectCustomField1', 'multi'), // További szolgáltatások
				'extras' => array('multiSelectCustomField2', 'multi'),        // Választott extra
				'services_ec' => array('multiSelectCustomField3', 'multi'),   // EventCrazy – Választott szolgáltatás(ok)
				'services_cp' => array('multiSelectCustomField4', 'multi'),   // Választott szolgáltatás
			),
			'contact' => array(
				'name' => array('name', 'str:190'), 'first_name' => array('firstName', 'str:100'), 'last_name' => array('lastName', 'str:100'),
				'email' => array('email', 'str:190'), 'phone' => array('phone', 'str:60'), 'mobile' => array('mobile', 'str:60'),
				'company_id' => array('company', 'ref'), 'type' => array('type', 'str:60'), 'tags' => array('tags', 'multi'), 'owner_id' => array('owner', 'ref'),
				'city' => array('billingCity', 'str:100'), 'utm_source' => array('utmSource', 'str:100'), 'utm_medium' => array('utmMedium', 'str:100'),
				'utm_campaign' => array('utmCampaign', 'str:190'), 'utm_term' => array('utmTerm', 'str:190'), 'utm_content' => array('utmContent', 'str:190'),
				'fb_date' => array('textCustomField1', 'str:100'), 'fb_type' => array('textCustomField2', 'str:100'), 'fb_ad' => array('textCustomField3', 'str:190'),
				'bg_pref' => array('multiSelectCustomField1', 'multi'), 'email_opt_out' => array('emailOptOut', 'int'),
				'description' => array('description', 'text'), 'last_note' => array('lastNote', 'text'), 'last_note_at' => array('lastNoteAddedAt', 'dt'),
				'created_at' => array('createdAt', 'dt'), 'modified_at' => array('lastModifiedAt', 'dt'),
			),
			'company' => array(
				'name' => array('name', 'str:190'), 'website' => array('website', 'str:255'), 'employees' => array('numberOfEmployees', 'int'),
				'type' => array('type', 'str:60'), 'owner_id' => array('owner', 'ref'), 'phone' => array('phone', 'str:60'),
				'description' => array('description', 'text'), 'last_note' => array('lastNote', 'text'),
				'created_at' => array('createdAt', 'dt'), 'modified_at' => array('lastModifiedAt', 'dt'),
			),
			'activity' => array(
				'title' => array('title', 'str:255'), 'type' => array('type', 'str:60'), 'owner_id' => array('owner', 'ref'),
				'related_id' => array('relatedTo', 'ref'), 'related_module' => array('relatedToModule', 'str:30'),
				'due_at' => array('dueDate', 'dt'), 'duration' => array('duration', 'int'), 'completed' => array('isCompleted', 'int'),
				'completed_at' => array('completedAt', 'dt'), 'description' => array('description', 'text'),
				'created_at' => array('createdAt', 'dt'), 'modified_at' => array('lastModifiedAt', 'dt'),
			),
		);
	}
	return $s[$m];
}

/* Salesmate-modul → (tábla, moduleId) */
function cp_crm_mods() {
	return array('deal' => array('deals', 4), 'contact' => array('contacts', 1), 'company' => array('companies', 5), 'activity' => array('activities', 3));
}

/* a változásnaplóba nem kerülő (gyakran, magától változó) deal-mezők */
function cp_crm_hist_skip() {
	return array('modified_at', 'modified_by', 'last_comm_at', 'last_comm_mode', 'last_note_at', 'rotten_d', 'win_prob', 'description');
}

/* ------------------------------------------------------------ táblák */

function cp_crm_sql_type($t) {
	if ($t === 'ref') { return 'bigint(20) unsigned NULL'; }
	if ($t === 'int') { return 'int(11) NULL'; }
	if ($t === 'dec') { return 'decimal(14,2) NULL'; }
	if ($t === 'cur') { return "varchar(3) NOT NULL DEFAULT ''"; }
	if ($t === 'date') { return 'date NULL'; }
	if ($t === 'dt') { return 'datetime NULL'; }
	if ($t === 'text' || $t === 'multi') { return 'text NULL'; }
	$n = (int) substr($t, 4);
	return 'varchar(' . max(1, $n) . ") NOT NULL DEFAULT ''";
}

function cp_crm_db_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	$keys = array(
		'deal' => array('KEY event_d (event_d)', 'KEY stage (stage)', 'KEY contact_id (contact_id)', 'KEY modified_at (modified_at)', 'KEY created_at (created_at)'),
		'contact' => array('KEY email (email)', 'KEY company_id (company_id)', 'KEY modified_at (modified_at)'),
		'company' => array('KEY name (name)'),
		'activity' => array('KEY related_id (related_id)', 'KEY due_at (due_at)'),
	);
	foreach (cp_crm_mods() as $m => $x) {
		$cols = array('  id bigint(20) unsigned NOT NULL');
		foreach (cp_crm_spec($m) as $col => $f) { $cols[] = '  ' . $col . ' ' . cp_crm_sql_type($f[1]); }
		if ($m === 'deal') {
			$cols[] = "  package varchar(120) NOT NULL DEFAULT ''";   // a három csomagmező összevonva
			$cols[] = '  event_end_d date NULL';                      // többnapos rendezvény vége (a panel tölti, a Salesmate-ben nincs ilyen mező)
		}
		$cols[] = '  raw longtext NULL';
		$cols[] = "  via varchar(8) NOT NULL DEFAULT ''";             // search | get (a keresőből kimaradt, azonosítóval lekért)
		$cols[] = '  synced_at datetime NULL';
		$cols[] = '  deleted_at datetime NULL';                       // a Salesmate-ben már nem található
		$cols[] = '  PRIMARY KEY  (id)';
		foreach ($keys[$m] as $k) { $cols[] = '  ' . $k; }
		dbDelta('CREATE TABLE ' . cp_crm_t($x[0]) . " (\n" . implode(",\n", $cols) . "\n) $c;");
	}
	dbDelta('CREATE TABLE ' . cp_crm_t('history') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  deal_id bigint(20) unsigned NOT NULL,
  field varchar(40) NOT NULL,
  old_val text NULL,
  new_val text NULL,
  by_user varchar(100) NOT NULL DEFAULT '',
  at datetime NOT NULL,
  src varchar(12) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY deal_id (deal_id),
  KEY at (at)
) $c;");
	update_option('cp_crm_db_ver', CP_CRM_DB_VER, false);
}

function cp_crm_db_ok() {
	return get_option('cp_crm_db_ver') === CP_CRM_DB_VER;
}

add_action('init', function () {
	if (!cp_crm_db_ok() && !wp_doing_ajax()) { cp_crm_db_install(); }
}, 6);

/* ------------------------------------------------------------ értékek átalakítása */

function cp_crm_ts($v) {
	if ($v === null || $v === '' || is_array($v)) { return 0; }
	if (is_numeric($v)) { $t = (float) $v; if ($t > 100000000000) { $t = $t / 1000; } return $t > 0 ? (int) round($t) : 0; }
	$t = strtotime((string) $v);
	return $t ? (int) $t : 0;
}

function cp_crm_val($v, $t) {
	if ($t === 'ref') {
		if (is_array($v)) { $v = isset($v['id']) ? $v['id'] : null; }
		return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
	}
	if ($t === 'int') { return ($v === null || $v === '' || is_array($v)) ? null : (int) $v; }
	if ($t === 'dec') { return ($v === null || $v === '' || is_array($v)) ? null : round((float) $v, 2); }
	if ($t === 'cur') { if (is_array($v)) { $v = isset($v['code']) ? $v['code'] : ''; } return mb_substr(trim((string) $v), 0, 3); }
	if ($t === 'dt') { $ts = cp_crm_ts($v); return $ts ? gmdate('Y-m-d H:i:s', $ts) : null; }
	if ($t === 'date') {
		if ($v === null || $v === '' || is_array($v)) { return null; }
		if (is_numeric($v)) { $ts = cp_crm_ts($v); if (!$ts) { return null; } $d = new DateTime('@' . $ts); $d->setTimezone(wp_timezone()); return $d->format('Y-m-d'); }
		return preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $v, $m) ? $m[1] : null;
	}
	if ($t === 'multi') {
		if (is_array($v)) { $v = implode(',', array_map('cp_panel_str', $v)); }
		$out = array();
		foreach (preg_split('/[,;|\n]+/u', (string) $v) as $x) { $x = trim($x); if ($x !== '' && !in_array($x, $out, true)) { $out[] = $x; } }
		return $out ? implode(' | ', $out) : null;
	}
	if ($t === 'text') { $s = is_array($v) ? cp_panel_str($v) : (string) $v; $s = trim($s); return $s === '' ? null : $s; }
	$n = (int) substr($t, 4);
	return mb_substr(trim(is_array($v) ? cp_panel_str($v) : (string) $v), 0, $n);
}

/* egy keresési / lekérési sor → tábla-sor (null, ha nem kell) */
function cp_crm_row($m, $r, $via) {
	$get = function ($k) use ($r, $m) {
		if (array_key_exists($k, $r)) { return $r[$k]; }
		if (array_key_exists($m . '.' . $k, $r)) { return $r[$m . '.' . $k]; }
		return null;
	};
	$id = (int) cp_panel_str($get('id'));
	if ($id <= 0) { return null; }
	$row = array('id' => $id);
	foreach (cp_crm_spec($m) as $col => $f) { $row[$col] = cp_crm_val($get($f[0]), $f[1]); }
	if ($m === 'deal') {
		/* csak a fő pipeline (a „Todo” és az „Ügynökség megkeresések” nem kell) */
		$main = cp_panel_lc(cp_panel_opts()['pipeline']);
		if ($row['pipeline'] !== '' && $main !== '' && cp_panel_lc($row['pipeline']) !== $main) { return null; }
		if ($row['company_id'] === null && is_string($get('primaryCompany'))) { $row['company_id'] = null; }
		$pk = '';
		foreach (array('pkg_fix', 'pkg_v2', 'pkg_old', 'spinner_pkg', 'melange_pkg') as $k) { if ($row[$k] !== '') { $pk = $row[$k]; break; } }
		$row['package'] = mb_substr(trim(preg_replace('/\s*\|\s*[\d.\s]+Ft\s*$/u', '', $pk)), 0, 120);
	}
	$row['raw'] = wp_json_encode($r, JSON_UNESCAPED_UNICODE);
	$row['via'] = $via;
	$row['synced_at'] = gmdate('Y-m-d H:i:s');
	$row['deleted_at'] = null;
	return $row;
}

/* ------------------------------------------------------------ felhasználók (a tulajdonos és a módosító nevéhez) */

function cp_crm_users($refresh = false) {
	$u = get_option('cp_crm_users', array());
	if (!$refresh && is_array($u) && !empty($u['t']) && time() - (int) $u['t'] < DAY_IN_SECONDS) { return $u['list']; }
	$r = cp_sm_request('GET', '/apis/v1/users');
	$list = is_array($u) && isset($u['list']) ? $u['list'] : array();
	if (is_array($r) && isset($r['Data']) && is_array($r['Data'])) {
		$list = array();
		foreach ($r['Data'] as $x) {
			if (!isset($x['id'])) { continue; }
			$list[(int) $x['id']] = array('n' => trim((isset($x['firstName']) ? $x['firstName'] : '') . ' ' . (isset($x['lastName']) ? $x['lastName'] : '')), 'a' => !empty($x['status']) ? 1 : 0);
		}
	}
	update_option('cp_crm_users', array('t' => time(), 'list' => $list), false);
	return $list;
}

function cp_crm_user_name($id) {
	if (!$id) { return ''; }
	$l = cp_crm_users();
	return isset($l[(int) $id]) ? $l[(int) $id]['n'] : ('#' . (int) $id);
}

/* ------------------------------------------------------------ mentés (upsert) + változásnapló */

function cp_crm_store($m, $raw, $via = 'search') {
	global $wpdb;
	$mods = cp_crm_mods();
	$tab = cp_crm_t($mods[$m][0]);
	$rows = array();
	foreach ($raw as $r) { if (is_array($r)) { $x = cp_crm_row($m, $r, $via); if ($x) { $rows[$x['id']] = $x; } } }
	if (!$rows) { return 0; }
	$ids = implode(',', array_map('intval', array_keys($rows)));
	$old = array();
	if ($m === 'deal') {
		$cols = array_keys(cp_crm_spec('deal'));
		$cols[] = 'package';
		foreach ((array) $wpdb->get_results('SELECT id, ' . implode(', ', $cols) . " FROM $tab WHERE id IN ($ids)", ARRAY_A) as $o) { $old[(int) $o['id']] = $o; }
	}
	$skip = array_flip(cp_crm_hist_skip());
	$hist = array();
	foreach ($rows as $id => $row) {
		/* változásnapló: csak a már meglévő deal-eknél, a ténylegesen megváltozott mezőkről */
		if ($m === 'deal' && isset($old[$id])) {
			$by = cp_crm_user_name($row['modified_by']);
			$at = $row['modified_at'] ? $row['modified_at'] : gmdate('Y-m-d H:i:s');
			foreach ($old[$id] as $col => $ov) {
				if ($col === 'id' || isset($skip[$col]) || !array_key_exists($col, $row)) { continue; }
				$nv = $row[$col];
				$a = $ov === null ? '' : (string) $ov; $b = $nv === null ? '' : (string) $nv;
				if (is_numeric($a) && is_numeric($b) && (float) $a == (float) $b) { continue; }
				if ($a === $b) { continue; }
				$hist[] = $wpdb->prepare('(%d, %s, %s, %s, %s, %s, %s)', $id, $col, mb_substr($a, 0, 2000), mb_substr($b, 0, 2000), mb_substr($by, 0, 100), $at, 'salesmate');
			}
		}
		$cols = array_keys($row);
		$vals = array();
		foreach ($row as $v) { $vals[] = $v === null ? 'NULL' : $wpdb->prepare('%s', (string) $v); }
		$upd = array();
		foreach ($cols as $c) { if ($c !== 'id') { $upd[] = "$c = VALUES($c)"; } }
		$wpdb->query("INSERT INTO $tab (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $upd));
	}
	if ($hist) {
		foreach (array_chunk($hist, 200) as $ch) {
			$wpdb->query('INSERT INTO ' . cp_crm_t('history') . ' (deal_id, field, old_val, new_val, by_user, at, src) VALUES ' . implode(', ', $ch));
		}
	}
	return count($rows);
}

/* ------------------------------------------------------------ Salesmate-keresés */

function cp_crm_fields($m) {
	$f = array($m . '.id');
	foreach (cp_crm_spec($m) as $x) { $f[] = $m . '.' . $x[0]; }
	return array_values(array_unique($f));
}

function cp_crm_search($m, $rules, $page = 0, $rows = 250) {
	$mods = cp_crm_mods();
	$body = array(
		'displayingFields' => cp_crm_fields($m),
		'filterQuery'      => array('group' => array('operator' => 'AND', 'rules' => $rules)),
		'sort'             => array('fieldName' => $m . '.id', 'order' => 'desc'),
		'moduleId'         => $mods[$m][1],
		'reportType'       => 'get_data',
		'getRecordsCount'  => true,
	);
	$j = cp_sm_request('POST', '/apis/' . $m . '/v4/search?rows=' . (int) $rows . '&from=' . ((int) $page * $rows), $body);
	if (is_wp_error($j)) { return $j; }
	$d = isset($j['Data']) ? $j['Data'] : $j;
	return array('rows' => cp_sm_rows($j), 'total' => isset($d['totalRows']) ? (int) $d['totalRows'] : null);
}

function cp_crm_rule($m, $field, $cond, $data) {
	return array('moduleName' => ucfirst($m), 'field' => array('fieldName' => $m . '.' . $field, 'type' => 'DateTime'), 'condition' => $cond, 'data' => $data, 'eventType' => 'DateTime');
}

function cp_crm_win($m, $f, $t) {
	return array(
		cp_crm_rule($m, 'createdAt', 'IS_AFTER', gmdate('Y-m-d', strtotime($f . ' -1 day'))),
		cp_crm_rule($m, 'createdAt', 'IS_BEFORE', gmdate('Y-m-d', strtotime($t . ' +1 day'))),
	);
}

/* ------------------------------------------------------------ állapot */

function cp_crm_state() {
	$s = get_option('cp_crm_state', array());
	return is_array($s) ? $s : array();
}

function cp_crm_state_set($s) {
	update_option('cp_crm_state', $s, false);
}

function cp_crm_lock() {
	if (function_exists('cp_st_dg_lock')) { return cp_st_dg_lock('cp_crm_lock', 9 * MINUTE_IN_SECONDS); }
	if (get_transient('cp_crm_lock')) { return false; }
	set_transient('cp_crm_lock', 1, 9 * MINUTE_IN_SECONDS);
	return true;
}

function cp_crm_unlock() {
	if (function_exists('cp_st_dg_unlock')) { cp_st_dg_unlock('cp_crm_lock'); return; }
	delete_transient('cp_crm_lock');
}

/* ------------------------------------------------------------ egy szinkron-kör */

function cp_crm_sync($manual = false) {
	if (!function_exists('cp_sm_request') || !cp_crm_db_ok()) { return 'Az adatbázis még nem kész.'; }
	if (!cp_crm_lock()) { return 'Már fut egy szinkron.'; }
	@set_time_limit(240);
	global $wpdb;
	$st = cp_crm_state();
	$calls = 0;
	$budget = $manual ? 16 : 28;
	$log = array();
	$t0 = microtime(true);
	try {
		cp_crm_users();
		foreach (cp_crm_mods() as $m => $x) {
			$tab = cp_crm_t($x[0]);
			/* napi teljes újraolvasás (a Salesmate-ben törölt rekordok miatt), éjjel 1 és 5 óra között indul */
			$h = (int) wp_date('G');
			if (!empty($st[$m . '_full_at']) && empty($st[$m . '_redo']) && time() - (int) $st[$m . '_full_at'] > 20 * HOUR_IN_SECONDS && $h >= 1 && $h <= 5) {
				$st[$m . '_redo'] = 1; $st[$m . '_run'] = gmdate('Y-m-d H:i:s'); unset($st[$m . '_cur'], $st[$m . '_span']);
			}
			$full = empty($st[$m . '_full_at']) || !empty($st[$m . '_redo']);
			if ($full && $calls < $budget) {
				if (empty($st[$m . '_run'])) { $st[$m . '_run'] = gmdate('Y-m-d H:i:s'); }
				$cur = !empty($st[$m . '_cur']) ? $st[$m . '_cur'] : wp_date('Y-m-d', time() + DAY_IN_SECONDS);
				$span = !empty($st[$m . '_span']) ? (int) $st[$m . '_span'] : 31;
				$done = false;
				while ($calls < $budget && microtime(true) - $t0 < 150) {
					$wf = gmdate('Y-m-d', strtotime($cur . ' 12:00 -' . ($span - 1) . ' days'));
					$res = cp_crm_search($m, cp_crm_win($m, $wf, $cur), 0);
					$calls++;
					if (is_wp_error($res)) { throw new Exception($m . ': ' . $res->get_error_message()); }
					$rows = $res['rows'];
					if (count($rows) >= 250 && $span > 1) { $span = (int) ceil($span / 2); continue; }
					if (count($rows) >= 250) {
						for ($pg = 1; $pg < 12; $pg++) {
							$more = cp_crm_search($m, cp_crm_win($m, $wf, $cur), $pg);
							$calls++;
							if (is_wp_error($more)) { break; }
							$rows = array_merge($rows, $more['rows']);
							if (count($more['rows']) < 250) { break; }
						}
					}
					cp_crm_store($m, $rows, 'search');
					$cur = gmdate('Y-m-d', strtotime($wf . ' 12:00 -1 day'));
					if (count($rows) < 80) { $span = min(730, $span * 2); }
					$st[$m . '_cur'] = $cur; $st[$m . '_span'] = $span;
					if ($cur < '2015-01-01') { $done = true; break; }
				}
				if ($done) {
					/* a teljes olvasásban nem látott rekordok: a Salesmate-ben törölve (csak jelöljük, nem töröljük) */
					if (!empty($st[$m . '_redo'])) {
						$cut = gmdate('Y-m-d H:i:s', strtotime($st[$m . '_run'] . ' UTC') - 3600);
						$n = $wpdb->query($wpdb->prepare("UPDATE $tab SET deleted_at = %s WHERE deleted_at IS NULL AND via = 'search' AND (synced_at IS NULL OR synced_at < %s)", gmdate('Y-m-d H:i:s'), $cut));
						if ($n) { $log[] = $m . ': ' . (int) $n . ' törölt jelölve'; }
					}
					$st[$m . '_full_at'] = time();
					$st[$m . '_n'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $tab WHERE deleted_at IS NULL");
					$log[] = $m . ': teljes olvasás kész (' . $st[$m . '_n'] . ')';
					unset($st[$m . '_redo'], $st[$m . '_run'], $st[$m . '_cur'], $st[$m . '_span']);
					if (empty($st[$m . '_inc'])) { $st[$m . '_inc'] = time(); }
				} else {
					$log[] = $m . ': teljes olvasás folyamatban (' . $cur . '-ig visszafelé)';
				}
			}
			/* változások: az utolsó kör óta módosultak, egy nap ráhagyással */
			if (!empty($st[$m . '_full_at']) && empty($st[$m . '_redo']) && $calls < $budget + 6) {
				$since = gmdate('Y-m-d', (int) (isset($st[$m . '_inc']) ? $st[$m . '_inc'] : $st[$m . '_full_at']) - DAY_IN_SECONDS);
				$start = time();
				$res = cp_crm_search($m, array(cp_crm_rule($m, 'lastModifiedAt', 'IS_AFTER', $since)), 0);
				$calls++;
				if (is_wp_error($res)) { throw new Exception($m . ' (változások): ' . $res->get_error_message()); }
				cp_crm_store($m, $res['rows'], 'search');
				if (count($res['rows']) >= 250) { $st[$m . '_redo'] = 1; $st[$m . '_run'] = gmdate('Y-m-d H:i:s'); }
				$st[$m . '_inc'] = $start;
				$log[] = $m . ': ' . count($res['rows']) . ' friss';
				/* v1.31: a Salesmate a kommunikáció idejét (levél, hívás) a módosítás ideje nélkül frissíti – külön lekérdezés */
				if ($m === 'deal' && $calls < $budget) {
					$rc = cp_crm_search('deal', array(cp_crm_rule('deal', 'lastCommunicationAt', 'IS_AFTER', $since)), 0);
					$calls++;
					if (!is_wp_error($rc)) { cp_crm_store('deal', $rc['rows'], 'search'); $log[] = 'deal: ' . count($rc['rows']) . ' kommunikáció'; }
				}
			}
		}
		/* naponta: a keresőből kimaradt friss deal-ek azonosítónként (legfeljebb 80 lekérés) */
		if (!empty($st['deal_full_at']) && (empty($st['gap_at']) || time() - (int) $st['gap_at'] > 20 * HOUR_IN_SECONDS) && $calls < $budget) {
			$tab = cp_crm_t('deals');
			$max = (int) $wpdb->get_var("SELECT MAX(id) FROM $tab");
			$have = array_flip(array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM $tab WHERE id >= %d", $max - 300))));
			$found = 0; $tried = 0;
			for ($id = $max + 40; $id >= $max - 300 && $tried < 80; $id--) {
				if (isset($have[$id])) { continue; }
				$tried++;
				$g = cp_sm_get_deal((string) $id);
				if (is_wp_error($g) || empty($g['id'])) { continue; }
				$found += cp_crm_store('deal', array($g), 'get');
			}
			$st['gap_at'] = time();
			$st['gap_found'] = $found;
			if ($found) { $log[] = 'keresőből kimaradt deal: ' . $found; }
		}
		/* Salesmate-oldali darabszámok az összevetéshez (naponta) */
		if (empty($st['tot_at']) || time() - (int) $st['tot_at'] > 20 * HOUR_IN_SECONDS) {
			foreach (cp_crm_mods() as $m => $x) {
				$res = cp_crm_search($m, array(), 0, 1);
				if (!is_wp_error($res) && $res['total'] !== null) { $st[$m . '_sm'] = $res['total']; }
			}
			$st['tot_at'] = time();
		}
		$st['last_ok'] = time();
		$st['last_err'] = '';
	} catch (Exception $e) {
		$st['last_err'] = mb_substr($e->getMessage(), 0, 300);
		$st['err_at'] = time();
	}
	foreach (cp_crm_mods() as $m => $x) { $st[$m . '_n'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . cp_crm_t($x[0]) . ' WHERE deleted_at IS NULL'); }
	$st['last_run'] = time();
	$st['last_calls'] = $calls;
	$st['last_log'] = implode(' · ', $log);
	cp_crm_state_set($st);
	cp_crm_unlock();
	/* a fülek a saját táblákból olvasnak: a gyorsítótárukat ürítjük, hogy a Salesmate-ből jött változás azonnal látsszon */
	if (function_exists('cp_crm_source_on') && cp_crm_source_on() && function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	return $st['last_err'] !== '' ? 'Hiba: ' . $st['last_err'] : ($log ? implode(' · ', $log) : 'Nincs változás.');
}

function cp_crm_importing() {
	$st = cp_crm_state();
	foreach (cp_crm_mods() as $m => $x) { if (empty($st[$m . '_full_at']) || !empty($st[$m . '_redo'])) { return true; } }
	return false;
}

/* ------------------------------------------------------------ időzítés: 10 percenként, az áthozatal alatt 3 percenként */

add_filter('cron_schedules', function ($s) {
	$s['cp_crm_10min'] = array('interval' => 10 * MINUTE_IN_SECONDS, 'display' => '10 percenként');
	return $s;
});
add_action('init', function () {
	if (cp_crm_db_ok() && !wp_next_scheduled('cp_crm_tick')) { wp_schedule_event(time() + 60, 'cp_crm_10min', 'cp_crm_tick'); }
});
add_action('cp_crm_tick', 'cp_crm_tick_run');
add_action('cp_crm_more', 'cp_crm_tick_run');
function cp_crm_tick_run() {
	cp_crm_sync(false);
	if (cp_crm_importing() && !wp_next_scheduled('cp_crm_more')) { wp_schedule_single_event(time() + 3 * MINUTE_IN_SECONDS, 'cp_crm_more'); }
}

/* ------------------------------------------------------------ admin: Beállítások › Crazy CRM */

add_action('admin_menu', function () {
	add_options_page('Crazy CRM', 'Crazy CRM', 'manage_options', 'cp-crm', 'cp_crm_admin_page');
});

function cp_crm_admin_page() {
	if (!current_user_can('manage_options')) { return; }
	global $wpdb;
	$msg = '';
	if (isset($_POST['cp_crm_do'])) {
		check_admin_referer('cp_crm_do');
		$do = sanitize_key(wp_unslash($_POST['cp_crm_do']));
		if ($do === 'sync') { $msg = cp_crm_sync(true); }
		if ($do === 'src_on' || $do === 'src_off') { update_option('cp_crm_source', $do === 'src_on' ? '1' : '0', false); if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); } $msg = $do === 'src_on' ? 'A panel fülei mostantól a saját táblákból olvasnak.' : 'A panel fülei újra közvetlenül a Salesmate-ből olvasnak.'; }
		if ($do === 'full') {
			$st = cp_crm_state();
			foreach (cp_crm_mods() as $m => $x) { $st[$m . '_redo'] = 1; $st[$m . '_run'] = gmdate('Y-m-d H:i:s'); unset($st[$m . '_cur'], $st[$m . '_span']); }
			cp_crm_state_set($st);
			$msg = 'A teljes újraolvasás elindul a következő körökben.';
			if (!wp_next_scheduled('cp_crm_more')) { wp_schedule_single_event(time() + 30, 'cp_crm_more'); }
		}
	}
	$st = cp_crm_state();
	$t = function ($k) use ($st) { return !empty($st[$k]) ? wp_date('Y.m.d. H:i', (int) $st[$k]) : '–'; };
	echo '<div class="wrap"><h1>Crazy CRM – áthozatal a Salesmate-ből</h1>';
	echo '<p>A Salesmate kiváltásának 1. szakasza. A modul 10 percenként a panel saját tábláiba másolja a Salesmate adatait. A Salesmate-be csak a panel saját műveletei írnak (szakaszváltás, háttér stb.).</p>';
	$src = get_option('cp_crm_source', '1') === '1';
	$live = function_exists('cp_crm_source_on') && cp_crm_source_on();
	echo '<p><b>A panel fülei:</b> ' . ($live ? 'a saját táblákból olvasnak.' : ($src ? '<span style="color:#b32d2e">átmenetileg a Salesmate-ből olvasnak</span> (a szinkron 30 percnél régebben futott le sikeresen, vagy az áthozatal még tart).' : 'a Salesmate-ből olvasnak (kikapcsolva).')) . '</p>';
	if ($msg !== '') { echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>'; }
	if (!empty($st['last_err'])) { echo '<div class="notice notice-error"><p>Utolsó hiba (' . esc_html($t('err_at')) . '): ' . esc_html($st['last_err']) . '</p></div>'; }
	echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Modul</th><th>Salesmate-ben</th><th>A panelben</th><th>Teljes olvasás</th><th>Utolsó változás-lekérés</th></tr></thead><tbody>';
	$names = array('deal' => 'Deal', 'contact' => 'Kontakt', 'company' => 'Cég', 'activity' => 'Tevékenység');
	foreach (cp_crm_mods() as $m => $x) {
		$full = !empty($st[$m . '_redo']) || empty($st[$m . '_full_at']) ? 'folyamatban' . (!empty($st[$m . '_cur']) ? ' (' . esc_html($st[$m . '_cur']) . '-ig)' : '') : $t($m . '_full_at');
		echo '<tr><td>' . esc_html($names[$m]) . '</td><td>' . (isset($st[$m . '_sm']) ? (int) $st[$m . '_sm'] : '–') . '</td><td>' . (isset($st[$m . '_n']) ? (int) $st[$m . '_n'] : 0) . '</td><td>' . $full . '</td><td>' . esc_html($t($m . '_inc')) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p>Utolsó kör: ' . esc_html($t('last_run')) . ' · ' . (int) (isset($st['last_calls']) ? $st['last_calls'] : 0) . ' Salesmate-hívás · ' . esc_html(isset($st['last_log']) ? $st['last_log'] : '') . '</p>';
	echo '<p>A deal-ek száma a Salesmate-ben a „Todo” és az „Ügynökség megkeresések” pipeline-t is tartalmazhatja; a panel csak a „' . esc_html(cp_panel_opts()['pipeline']) . '” pipeline-t hozza át. A keresőből kimaradt, azonosítóval pótolt deal-ek: ' . (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . cp_crm_t('deals') . " WHERE via = 'get'") . '.</p>';
	echo '<form method="post" style="margin:12px 0">';
	wp_nonce_field('cp_crm_do');
	echo ($src ? '<button class="button" name="cp_crm_do" value="src_off">Fülek: vissza a Salesmate-re</button> ' : '<button class="button" name="cp_crm_do" value="src_on">Fülek: a saját táblákból</button> ');
	echo '<button class="button button-primary" name="cp_crm_do" value="sync">Szinkron most</button> <button class="button" name="cp_crm_do" value="full" onclick="return confirm(\'Mindent újraolvas a Salesmate-ből (néhány kör). Mehet?\')">Teljes újraolvasás</button></form>';
	$h = $wpdb->get_results('SELECT h.deal_id, h.field, h.old_val, h.new_val, h.by_user, h.at, d.title FROM ' . cp_crm_t('history') . ' h LEFT JOIN ' . cp_crm_t('deals') . ' d ON d.id = h.deal_id ORDER BY h.id DESC LIMIT 30', ARRAY_A);
	echo '<h2>Változásnapló (legutóbbi 30)</h2>';
	if (!$h) { echo '<p>Még nincs bejegyzés: a napló a kezdő áthozatal utáni változásokat rögzíti.</p>'; }
	else {
		echo '<table class="widefat striped"><thead><tr><th>Időpont</th><th>Deal</th><th>Mező</th><th>Régi</th><th>Új</th><th>Ki</th></tr></thead><tbody>';
		foreach ($h as $r) {
			echo '<tr><td>' . esc_html(wp_date('Y.m.d. H:i', strtotime($r['at'] . ' UTC'))) . '</td><td>#' . (int) $r['deal_id'] . ' ' . esc_html(mb_substr((string) $r['title'], 0, 50)) . '</td><td>' . esc_html($r['field']) . '</td><td>' . esc_html(mb_substr((string) $r['old_val'], 0, 60)) . '</td><td>' . esc_html(mb_substr((string) $r['new_val'], 0, 60)) . '</td><td>' . esc_html($r['by_user']) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}
