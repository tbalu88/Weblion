<?php
/* =====================================================================
 * CRM – a panel fülei a saját táblákból olvasnak (v1.27)
 * A cp_sm_fetch_range() (Naptár, Követés, Hátterek, Gyors ellenőrzés, Napi munkalap, Elszámolás, Tárca,
 * ügyfélportál háttérfoglalás és óránkénti tartalék) a Salesmate helyett a saját deal-táblából adja a sorokat,
 * ugyanabban a formában, ahogy a Salesmate keresője adná (a tárolt nyers sorból), a kontaktot és a felelőst kiegészítve.
 *
 * Biztonsági háló: ha a szinkron 30 percnél régebben futott le sikeresen, vagy a kezdő áthozatal még tart,
 * automatikusan a Salesmate-ből olvas, mint eddig. Kikapcsolható: Beállítások → Crazy CRM.
 * A panel saját Salesmate-írásai (szakasz, háttér stb.) után a deal azonnal frissül a saját táblában is.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_crm_source_on() {
	static $on = null;
	if ($on !== null) { return $on; }
	$on = false;
	if (get_option('cp_crm_source', '1') !== '1' || !function_exists('cp_crm_db_ok') || !cp_crm_db_ok()) { return $on; }
	if (function_exists('cp_crm_importing') && cp_crm_importing()) { return $on; }
	$st = cp_crm_state();
	$p = cp_panel_opts();
	if (empty($st['last_ok']) || time() - (int) $st['last_ok'] > 30 * MINUTE_IN_SECONDS) { return $on; }
	/* a rendezvény dátuma a saját táblában az event_d oszlop (dateCustomField1) */
	if ($p['f_date'] !== cp_crm_spec('deal')['event_d'][0]) { return $on; }
	$on = true;
	return $on;
}

/* a Frissítés gomb (refresh=1) előtt egy gyors szinkron, hogy a Salesmate-ben épp módosított adat is látsszon */
function cp_crm_source_fresh() {
	static $done = false;
	if ($done || !wp_doing_ajax() || empty($_REQUEST['refresh'])) { return; }
	$done = true;
	$st = cp_crm_state();
	if (empty($st['last_run']) || time() - (int) $st['last_run'] >= 60) { cp_crm_sync(true); }
}

/* a dátumtartomány deal-jei a Salesmate kereső sorformájában */
function cp_crm_range_rows($from, $to) {
	global $wpdb;
	cp_crm_source_fresh();
	$d = cp_crm_t('deals'); $c = cp_crm_t('contacts');
	$rows = $wpdb->get_results($wpdb->prepare("SELECT d.id, d.raw, d.owner_id, d.contact_id, d.start_at, c.name cn, c.email ce, c.phone cp, c.mobile cm FROM $d d LEFT JOIN $c c ON c.id = d.contact_id WHERE d.deleted_at IS NULL AND d.event_d BETWEEN %s AND %s ORDER BY d.id DESC", $from, $to), ARRAY_A);
	if ($wpdb->last_error) { return new WP_Error('cp_crm_db', 'Adatbázis-hiba: ' . $wpdb->last_error); }
	$out = array();
	foreach ((array) $rows as $x) { $n = cp_crm_row_shape($x); if ($n) { $out[] = $n; } }
	return $out;
}

/* egy tárolt sor → a Salesmate kereső/lekérés sorformája (a kontakt és a felelős kiegészítve) */
function cp_crm_row_shape($x) {
	static $users = null;
	if ($users === null) { $users = cp_crm_users(); }
	$tf = cp_crm_spec('deal')['start_at'][0];
	$r = json_decode((string) $x['raw'], true);
	if (!is_array($r)) { return null; }
	/* a nyers sor kulcsai „deal.mező” vagy „mező” alakúak – egységesen „mező” */
	$n = array();
	foreach ($r as $k => $v) { $n[strpos($k, 'deal.') === 0 ? substr($k, 5) : $k] = $v; }
	$n['id'] = (int) $x['id'];
	if ($x['owner_id']) { $n['owner'] = array('id' => (int) $x['owner_id'], 'name' => isset($users[(int) $x['owner_id']]) ? $users[(int) $x['owner_id']]['n'] : ''); }
	if ($x['contact_id']) {
		$n['primaryContact'] = array('id' => (int) $x['contact_id'], 'name' => (string) $x['cn'], 'email' => (string) $x['ce'], 'mobile' => (string) $x['cm'], 'phone' => (string) $x['cp']);
	}
	/* a kezdés időpontja: a GET-tel pótolt sorokban más lehet a formátum – a saját (UTC) oszlopból ezredmásodpercként */
	if ($x['start_at'] && !is_numeric(isset($n[$tf]) ? $n[$tf] : null)) { $n[$tf] = strtotime($x['start_at'] . ' UTC') * 1000; }
	return $n;
}

/* egy deal olvasása: a saját táblából (ha a szinkron friss és megvan), különben a Salesmate-ből – ügyfélportál, Sablonkészítő */
function cp_crm_deal_read($id) {
	if (function_exists('cp_crm_source_on') && cp_crm_source_on()) {
		global $wpdb;
		$d = cp_crm_t('deals'); $c = cp_crm_t('contacts');
		$x = $wpdb->get_row($wpdb->prepare("SELECT d.id, d.raw, d.owner_id, d.contact_id, d.start_at, c.name cn, c.email ce, c.phone cp, c.mobile cm FROM $d d LEFT JOIN $c c ON c.id = d.contact_id WHERE d.id = %d AND d.deleted_at IS NULL", (int) $id), ARRAY_A);
		$n = $x ? cp_crm_row_shape($x) : null;
		if ($n) { return $n; }
	}
	return cp_sm_get_deal($id);
}

/* ------------------------------------------------------------ a panel Salesmate-írásai után: a deal frissítése a saját táblában */

function cp_crm_after_write($id) {
	static $busy = false;
	if ($busy || !function_exists('cp_crm_db_ok') || !cp_crm_db_ok()) { return; }
	$busy = true;
	$d = cp_sm_get_deal($id);
	if (!is_wp_error($d) && is_array($d)) {
		$d['lastModifiedBy'] = null;
		cp_crm_store('deal', array($d), 'get');
		global $wpdb;
		$wpdb->query($wpdb->prepare('UPDATE ' . cp_crm_t('history') . " SET by_user = %s, src = 'panel' WHERE deal_id = %d AND src = 'salesmate' AND by_user = '' AND at >= %s", (function_exists('cp_panel_who') ? cp_panel_who() : 'Crazy panel'), $id, gmdate('Y-m-d H:i:s', time() - 7200)));
	}
	$busy = false;
}

/* ------------------------------------------------------------ a Statisztikák deal-keresése a saját táblából (v1.31)
 * A cp_st_search() szűrőit (létrehozás / utolsó módosítás: IS_AFTER, IS_BEFORE, napra) értelmezi; más szűrőnél hibát ad,
 * és a Statisztikák a Salesmate-ből olvasnak, mint eddig. A kontaktok és a tevékenységek egyelőre a Salesmate-ből jönnek
 * (a levelezési mezők – utolsó levél, levelek száma – a saját táblában még nincsenek meg). */
function cp_crm_st_search($rules, $page, $rows) {
	global $wpdb;
	$w = array('d.deleted_at IS NULL');
	$tz = wp_timezone();
	foreach ((array) $rules as $r) {
		$fn = isset($r['field']['fieldName']) ? (string) $r['field']['fieldName'] : '';
		$col = preg_match('/\.createdAt$/', $fn) ? 'd.created_at' : (preg_match('/\.lastModifiedAt$/', $fn) ? 'd.modified_at' : '');
		$day = isset($r['data']) ? (string) $r['data'] : '';
		$cond = isset($r['condition']) ? (string) $r['condition'] : '';
		if ($col === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !in_array($cond, array('IS_AFTER', 'IS_BEFORE'), true)) { return new WP_Error('cp_crm_st', 'Nem kezelt szűrő.'); }
		/* napra értve, helyi idő szerint, a Salesmate-hez hasonlóan a megadott napot is beleértve:
		 * „után” = az adott nap elejétől, „előtt” = a következő nap elejéig (a Statisztikák így legfeljebb egy-két sorral többet kapnak) */
		$b = new DateTime($day . ' 00:00:00', $tz);
		if ($cond === 'IS_BEFORE') { $b->modify('+1 day'); }
		$w[] = $wpdb->prepare($col . ($cond === 'IS_AFTER' ? ' >= %s' : ' < %s'), gmdate('Y-m-d H:i:s', $b->getTimestamp()));
	}
	$d = cp_crm_t('deals'); $c = cp_crm_t('contacts');
	$res = $wpdb->get_results("SELECT d.id, d.raw, d.owner_id, d.contact_id, d.start_at, c.name cn, c.email ce, c.phone cp, c.mobile cm FROM $d d LEFT JOIN $c c ON c.id = d.contact_id WHERE " . implode(' AND ', $w) . ' ORDER BY d.id DESC LIMIT ' . ((int) $page * (int) $rows) . ', ' . (int) $rows, ARRAY_A);
	if ($wpdb->last_error) { return new WP_Error('cp_crm_db', $wpdb->last_error); }
	$out = array();
	foreach ((array) $res as $x) { $n = cp_crm_row_shape($x); if ($n) { $out[] = $n; } }
	return $out;
}
