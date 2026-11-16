<?php
/* =====================================================================
 * Crazy panel – Raktár (v2.9.259)
 *  Cikkek (fogyóanyag darabszámmal, eszköztípus), egyedi eszközök sorozatszámmal, helyek, készletmozgások naplóval.
 *  A készlet a mozgások összege helyenként (nincs külön készletmező) – így minden darab visszakövethető.
 *  Mozgástípusok: in (beérkezés), out (kiadás rendezvényre), back (visszavétel), move (áthelyezés), scrap (selejt), adj (leltárkorrekció).
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }

define('CP_WH_DB_VER', '2'); /* 2: units.tag – egyedi felirat a QR-címkén (v2.9.274) */
function cp_wh_t($n) { global $wpdb; return $wpdb->prefix . 'cp_wh_' . $n; }
function cp_wh_db_ok() { return get_option('cp_wh_db_ver') === CP_WH_DB_VER; }

add_action('init', function () {
	if (cp_wh_db_ok() || wp_doing_ajax()) { return; }
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	global $wpdb; $cs = $wpdb->get_charset_collate();
	dbDelta('CREATE TABLE ' . cp_wh_t('items') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(8) NOT NULL DEFAULT 'stock',
  name varchar(160) NOT NULL DEFAULT '',
  cat varchar(60) NOT NULL DEFAULT '',
  unit varchar(20) NOT NULL DEFAULT 'db',
  min_qty decimal(12,2) NOT NULL DEFAULT 0,
  price int(11) NOT NULL DEFAULT 0,
  supplier varchar(160) NOT NULL DEFAULT '',
  sku varchar(80) NOT NULL DEFAULT '',
  per_print decimal(10,4) NOT NULL DEFAULT 0,
  note varchar(500) NOT NULL DEFAULT '',
  active tinyint(1) NOT NULL DEFAULT 1,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  KEY kind (kind, active)
) $cs;");
	dbDelta('CREATE TABLE ' . cp_wh_t('units') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  label varchar(120) NOT NULL DEFAULT '',
  serial varchar(120) NOT NULL DEFAULT '',
  model varchar(160) NOT NULL DEFAULT '',
  loc varchar(60) NOT NULL DEFAULT '',
  deal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(10) NOT NULL DEFAULT 'ok',
  qr varchar(12) NOT NULL DEFAULT '',
  counter int(11) NOT NULL DEFAULT 0,
  svc_limit int(11) NOT NULL DEFAULT 0,
  svc_base int(11) NOT NULL DEFAULT 0,
  note varchar(500) NOT NULL DEFAULT '',
  tag varchar(120) NOT NULL DEFAULT '',
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  KEY item (item_id),
  KEY serial (serial),
  KEY qr (qr)
) $cs;");
	dbDelta('CREATE TABLE ' . cp_wh_t('moves') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  qty decimal(12,2) NOT NULL DEFAULT 0,
  type varchar(8) NOT NULL DEFAULT '',
  loc_from varchar(60) NOT NULL DEFAULT '',
  loc_to varchar(60) NOT NULL DEFAULT '',
  deal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  price int(11) NOT NULL DEFAULT 0,
  by_name varchar(100) NOT NULL DEFAULT '',
  at datetime NOT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  auto tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY item (item_id, at),
  KEY deal (deal_id),
  KEY unit (unit_id)
) $cs;");
	update_option('cp_wh_db_ver', CP_WH_DB_VER, false);
});

/* helyek: az első a fő raktár (ide jön a beérkezés); a „Rendezvényen” a kiadott tételeké */
define('CP_WH_EVENT', 'Rendezvényen');
function cp_wh_locs() {
	$l = get_option('cp_wh_locs', null);
	if (!is_array($l) || !$l) { $l = array('Raktár (Barázda u.)', 'Autó', 'Kezelőnél', 'Szerviz'); }
	return array_values(array_unique(array_filter(array_map('strval', $l))));
}
function cp_wh_main() { $l = cp_wh_locs(); return $l[0]; }
function cp_wh_types() { return array('in' => 'Beérkezés', 'out' => 'Kiadás rendezvényre', 'back' => 'Visszavétel', 'move' => 'Áthelyezés', 'scrap' => 'Selejt', 'adj' => 'Leltárkorrekció'); }
function cp_wh_cats() { return array('Papír és festékszalag', 'Kellék', 'Elem, akku', 'Gép', 'Kamera', 'Objektív', 'Nyomtató', 'Világítás', 'Kábel, tartozék', 'Egyéb'); }
function cp_wh_status() { return array('ok' => 'Használható', 'svc' => 'Szervizben', 'bad' => 'Hibás', 'lost' => 'Elveszett', 'gone' => 'Kivezetve'); }

function cp_wh_guard() {
	if (function_exists('cp_crm_ajax_guard')) { cp_crm_ajax_guard(array('deals')); } else { cp_el_auth(); }
	if (!cp_wh_db_ok()) { cp_el_err('A raktár adatbázisa még készül – próbáld újra egy perc múlva.', 503); }
}
function cp_wh_who() { return function_exists('cp_panel_who') ? mb_substr((string) cp_panel_who(), 0, 100) : ''; }
function cp_wh_num($v) { return round((float) str_replace(array(' ', ','), array('', '.'), (string) $v), 2); }
function cp_wh_str($k, $n) { return mb_substr(trim(sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? '')))), 0, $n); }

/* készlet: cikk → hely → mennyiség (fogyóanyag); eszköznél a darabok helye a units.loc */
function cp_wh_stock() {
	global $wpdb; $s = array();
	foreach ((array) $wpdb->get_results('SELECT item_id, loc_to l, SUM(qty) q FROM ' . cp_wh_t('moves') . " WHERE unit_id = 0 AND loc_to <> '' GROUP BY item_id, loc_to", ARRAY_A) as $r) { $s[(int) $r['item_id']][$r['l']] = ($s[(int) $r['item_id']][$r['l']] ?? 0) + (float) $r['q']; }
	foreach ((array) $wpdb->get_results('SELECT item_id, loc_from l, SUM(qty) q FROM ' . cp_wh_t('moves') . " WHERE unit_id = 0 AND loc_from <> '' GROUP BY item_id, loc_from", ARRAY_A) as $r) { $s[(int) $r['item_id']][$r['l']] = ($s[(int) $r['item_id']][$r['l']] ?? 0) - (float) $r['q']; }
	foreach ($s as $i => $L) { foreach ($L as $l => $q) { $q = round($q, 2); if (abs($q) < 0.005) { unset($s[$i][$l]); } else { $s[$i][$l] = $q; } } }
	return $s;
}
function cp_wh_deal_titles($ids) {
	global $wpdb; $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids)))); $o = array();
	if (!$ids || !function_exists('cp_crm_t')) { return $o; }
	foreach ((array) $wpdb->get_results('SELECT id, title, event_d FROM ' . cp_crm_t('deals') . ' WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A) as $d) { $o[(int) $d['id']] = array('t' => (string) $d['title'], 'd' => (string) $d['event_d']); }
	return $o;
}

/* minden adat a laphoz */
add_action('wp_ajax_nopriv_cp_wh_data', 'cp_wh_ajax_data'); add_action('wp_ajax_cp_wh_data', 'cp_wh_ajax_data');
function cp_wh_ajax_data() {
	cp_wh_guard(); global $wpdb;
	$items = (array) $wpdb->get_results('SELECT * FROM ' . cp_wh_t('items') . ' ORDER BY active DESC, cat, name', ARRAY_A);
	$units = (array) $wpdb->get_results('SELECT * FROM ' . cp_wh_t('units') . " ORDER BY item_id, label, id", ARRAY_A);
	foreach ($items as &$i) { foreach (array('id', 'price', 'active') as $k) { $i[$k] = (int) $i[$k]; } foreach (array('min_qty', 'per_print') as $k) { $i[$k] = (float) $i[$k]; } } unset($i);
	foreach ($units as &$u) { foreach (array('id', 'item_id', 'deal_id', 'counter', 'svc_limit', 'svc_base') as $k) { $u[$k] = (int) $u[$k]; } } unset($u);
	wp_send_json_success(array('items' => $items, 'units' => $units, 'stock' => cp_wh_stock(), 'locs' => cp_wh_locs(), 'ev' => CP_WH_EVENT, 'types' => cp_wh_types(), 'cats' => cp_wh_cats(), 'status' => cp_wh_status(),
		'deals' => cp_wh_deal_titles(array_column($units, 'deal_id'))));
}

/* cikk mentése (új vagy meglévő) */
add_action('wp_ajax_nopriv_cp_wh_item', 'cp_wh_ajax_item'); add_action('wp_ajax_cp_wh_item', 'cp_wh_ajax_item');
function cp_wh_ajax_item() {
	cp_wh_guard(); global $wpdb;
	$id = (int) ($_POST['id'] ?? 0);
	$row = array('kind' => ($_POST['kind'] ?? '') === 'asset' ? 'asset' : 'stock', 'name' => cp_wh_str('name', 160), 'cat' => cp_wh_str('cat', 60), 'unit' => cp_wh_str('unit', 20) ?: 'db',
		'min_qty' => max(0, cp_wh_num($_POST['min_qty'] ?? 0)), 'price' => max(0, (int) round(cp_wh_num($_POST['price'] ?? 0))), 'supplier' => cp_wh_str('supplier', 160), 'sku' => cp_wh_str('sku', 80),
		'per_print' => max(0, (float) str_replace(',', '.', (string) ($_POST['per_print'] ?? 0))), 'note' => cp_wh_str('note', 500), 'active' => empty($_POST['inactive']) ? 1 : 0, 'updated_at' => current_time('mysql'));
	if ($row['name'] === '') { cp_el_err('Add meg a cikk nevét.'); }
	if ($id) { $wpdb->update(cp_wh_t('items'), $row, array('id' => $id)); } else { $wpdb->insert(cp_wh_t('items'), $row); $id = (int) $wpdb->insert_id; }
	if (function_exists('cp_audit_add')) { cp_audit_add('raktar', 'item', 'Raktár: cikk mentve – ' . $row['name'], 'raktar', ''); }
	wp_send_json_success(array('id' => $id));
}

/* egyedi eszköz mentése */
function cp_wh_new_qr() { global $wpdb; for ($i = 0; $i < 20; $i++) { $q = strtoupper(wp_generate_password(7, false, false)); $q = preg_replace('/[^A-Z0-9]/', '', $q); if (strlen($q) === 7 && !$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_wh_t('units') . ' WHERE qr = %s', $q))) { return $q; } } return strtoupper(substr(md5(uniqid('', true)), 0, 7)); }
add_action('wp_ajax_nopriv_cp_wh_unit', 'cp_wh_ajax_unit'); add_action('wp_ajax_cp_wh_unit', 'cp_wh_ajax_unit');
function cp_wh_ajax_unit() {
	cp_wh_guard(); global $wpdb;
	$id = (int) ($_POST['id'] ?? 0); $item = (int) ($_POST['item_id'] ?? 0);
	if (!$item || !$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_wh_t('items') . " WHERE id = %d AND kind = 'asset'", $item))) { cp_el_err('Válassz eszköztípust.'); }
	$st = (string) ($_POST['status'] ?? 'ok'); if (!isset(cp_wh_status()[$st])) { $st = 'ok'; }
	$row = array('item_id' => $item, 'label' => cp_wh_str('label', 120), 'serial' => cp_wh_str('serial', 120), 'model' => cp_wh_str('model', 160), 'status' => $st,
		'counter' => max(0, (int) ($_POST['counter'] ?? 0)), 'svc_limit' => max(0, (int) ($_POST['svc_limit'] ?? 0)), 'note' => cp_wh_str('note', 500), 'tag' => cp_wh_str('tag', 120), 'updated_at' => current_time('mysql'));
	if ($row['label'] === '' && $row['serial'] === '') { cp_el_err('Adj nevet vagy sorozatszámot az eszköznek.'); }
	if ($row['serial'] !== '' && $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_wh_t('units') . ' WHERE serial = %s AND id <> %d', $row['serial'], $id))) { cp_el_err('Ez a sorozatszám már szerepel egy másik eszköznél.'); }
	if ($id) {
		$wpdb->update(cp_wh_t('units'), $row, array('id' => $id));
	} else {
		$row['loc'] = cp_wh_main(); $row['qr'] = cp_wh_new_qr(); $row['svc_base'] = $row['counter'];
		$wpdb->insert(cp_wh_t('units'), $row); $id = (int) $wpdb->insert_id;
		$wpdb->insert(cp_wh_t('moves'), array('item_id' => $item, 'unit_id' => $id, 'qty' => 1, 'type' => 'in', 'loc_to' => $row['loc'], 'by_name' => cp_wh_who(), 'at' => current_time('mysql'), 'note' => 'felvéve'));
	}
	if (!empty($_POST['svc_done'])) { $wpdb->query($wpdb->prepare('UPDATE ' . cp_wh_t('units') . ' SET svc_base = counter WHERE id = %d', $id)); }
	wp_send_json_success(array('id' => $id));
}

/* mozgás rögzítése: fogyóanyagnál mennyiség + honnan/hová; eszköznél a darab helye változik */
add_action('wp_ajax_nopriv_cp_wh_move', 'cp_wh_ajax_move'); add_action('wp_ajax_cp_wh_move', 'cp_wh_ajax_move');
function cp_wh_ajax_move() {
	cp_wh_guard(); global $wpdb;
	$type = (string) ($_POST['type'] ?? ''); if (!isset(cp_wh_types()[$type])) { cp_el_err('Ismeretlen mozgás.'); }
	$unit = (int) ($_POST['unit_id'] ?? 0); $deal = (int) ($_POST['deal'] ?? 0); $note = cp_wh_str('note', 255);
	$locs = array_merge(cp_wh_locs(), array(CP_WH_EVENT));
	$from = cp_wh_str('from', 60); $to = cp_wh_str('to', 60);
	if ($unit) { $u = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_wh_t('units') . ' WHERE id = %d', $unit), ARRAY_A); if (!$u) { cp_el_err('Nincs ilyen eszköz.', 404); } $item = (int) $u['item_id']; $qty = 1; $from = (string) $u['loc']; }
	else { $item = (int) ($_POST['item_id'] ?? 0); $qty = cp_wh_num($_POST['qty'] ?? 0); if (!$item) { cp_el_err('Válassz cikket.'); } }
	/* a típusból adódó irány */
	if ($type === 'in') { $from = ''; $to = $to ?: cp_wh_main(); }
	if ($type === 'out') { $to = CP_WH_EVENT; $from = $from ?: cp_wh_main(); if (!$deal) { cp_el_err('Melyik rendezvényre adod ki? Válassz dealt.'); } }
	if ($type === 'back') { $from = $unit ? $from : CP_WH_EVENT; $to = $to ?: cp_wh_main(); }
	if ($type === 'scrap') { $to = ''; $from = $from ?: cp_wh_main(); }
	if ($type === 'adj') { $from = ''; $to = $to ?: cp_wh_main(); }
	if ($type === 'move' && ($to === '' || $to === $from)) { cp_el_err('Hová kerüljön?'); }
	foreach (array($from, $to) as $l) { if ($l !== '' && !in_array($l, $locs, true)) { cp_el_err('Ismeretlen hely: ' . $l); } }
	if (!$unit && $type !== 'adj' && $qty <= 0) { cp_el_err('Adj meg pozitív mennyiséget.'); }
	if (!$unit && $type === 'adj' && abs($qty) < 0.005) { cp_el_err('A korrekció nem lehet nulla.'); }
	$price = $type === 'in' ? max(0, (int) round(cp_wh_num($_POST['price'] ?? 0))) : 0;
	$wpdb->insert(cp_wh_t('moves'), array('item_id' => $item, 'unit_id' => $unit, 'qty' => $qty, 'type' => $type, 'loc_from' => $from, 'loc_to' => $to, 'deal_id' => $deal, 'price' => $price, 'by_name' => cp_wh_who(), 'at' => current_time('mysql'), 'note' => $note));
	if ($unit) {
		$set = array('loc' => $to === '' ? '' : $to, 'deal_id' => $type === 'out' ? $deal : 0, 'updated_at' => current_time('mysql'));
		if ($type === 'scrap') { $set['status'] = 'gone'; }
		if ($to === 'Szerviz') { $set['status'] = 'svc'; } elseif ($from === 'Szerviz' && $u['status'] === 'svc') { $set['status'] = 'ok'; }
		$wpdb->update(cp_wh_t('units'), $set, array('id' => $unit));
	}
	if ($price && !$unit) { $wpdb->update(cp_wh_t('items'), array('price' => (int) round($price / max(0.01, $qty))), array('id' => $item)); }
	wp_send_json_success(array('ok' => true));
}

/* mozgásnapló (szűrhető cikkre / eszközre / dealre) */
add_action('wp_ajax_nopriv_cp_wh_moves', 'cp_wh_ajax_moves'); add_action('wp_ajax_cp_wh_moves', 'cp_wh_ajax_moves');
function cp_wh_ajax_moves() {
	cp_wh_guard(); global $wpdb;
	$w = array('1=1'); $a = array();
	foreach (array('item_id', 'unit_id', 'deal_id') as $k) { if (!empty($_POST[$k])) { $w[] = "m.$k = %d"; $a[] = (int) $_POST[$k]; } }
	$sql = 'SELECT m.*, i.name, i.unit, u.label, u.serial FROM ' . cp_wh_t('moves') . ' m LEFT JOIN ' . cp_wh_t('items') . ' i ON i.id = m.item_id LEFT JOIN ' . cp_wh_t('units') . ' u ON u.id = m.unit_id WHERE ' . implode(' AND ', $w) . ' ORDER BY m.at DESC, m.id DESC LIMIT 300';
	$rows = (array) $wpdb->get_results($a ? $wpdb->prepare($sql, $a) : $sql, ARRAY_A);
	wp_send_json_success(array('rows' => $rows, 'deals' => cp_wh_deal_titles(array_column($rows, 'deal_id'))));
}

/* helyek szerkesztése */
add_action('wp_ajax_nopriv_cp_wh_locs', 'cp_wh_ajax_locs'); add_action('wp_ajax_cp_wh_locs', 'cp_wh_ajax_locs');
function cp_wh_ajax_locs() {
	cp_wh_guard();
	$L = array_values(array_unique(array_filter(array_map(function ($x) { return mb_substr(trim(sanitize_text_field($x)), 0, 60); }, explode("\n", (string) wp_unslash($_POST['locs'] ?? ''))))));
	$L = array_values(array_filter($L, function ($x) { return $x !== CP_WH_EVENT; }));
	if (!$L) { cp_el_err('Legalább egy hely kell (az első a fő raktár).'); }
	update_option('cp_wh_locs', array_slice($L, 0, 30), false);
	wp_send_json_success(array('locs' => cp_wh_locs()));
}

/* deal-kereső a kiadáshoz (cím vagy szám alapján) */
add_action('wp_ajax_nopriv_cp_wh_dealq', 'cp_wh_ajax_dealq'); add_action('wp_ajax_cp_wh_dealq', 'cp_wh_ajax_dealq');
function cp_wh_ajax_dealq() {
	cp_wh_guard(); global $wpdb;
	$q = cp_wh_str('q', 80); $T = cp_crm_t('deals');
	if (ctype_digit($q)) { $rows = $wpdb->get_results($wpdb->prepare("SELECT id, title, event_d FROM $T WHERE id = %d AND deleted_at IS NULL", (int) $q), ARRAY_A); }
	elseif ($q === '') { $rows = $wpdb->get_results($wpdb->prepare("SELECT id, title, event_d FROM $T WHERE deleted_at IS NULL AND status <> 'Lost' AND event_d BETWEEN %s AND %s ORDER BY event_d LIMIT 20", wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS), wp_date('Y-m-d', time() + 14 * DAY_IN_SECONDS)), ARRAY_A); }
	else { $rows = $wpdb->get_results($wpdb->prepare("SELECT id, title, event_d FROM $T WHERE deleted_at IS NULL AND title LIKE %s ORDER BY event_d DESC LIMIT 20", '%' . $wpdb->esc_like($q) . '%'), ARRAY_A); }
	wp_send_json_success(array('rows' => (array) $rows));
}

/* v2.9.272: eszköz felvétele egy lépésben – megnevezés (ha már van ilyen eszközfajta, ahhoz kerül), darabszám, sorozatszámok soronként */
add_action('wp_ajax_nopriv_cp_wh_assets_add', 'cp_wh_ajax_assets_add'); add_action('wp_ajax_cp_wh_assets_add', 'cp_wh_ajax_assets_add');
function cp_wh_ajax_assets_add() {
	cp_wh_guard(); global $wpdb;
	$name = cp_wh_str('name', 160); if ($name === '') { cp_el_err('Add meg az eszköz nevét (pl. „Nikon D5300 váz”).'); }
	$serials = array_values(array_filter(array_map(function ($x) { return mb_substr(trim(sanitize_text_field($x)), 0, 120); }, preg_split('/[\r\n]+/', (string) wp_unslash($_POST['serials'] ?? '')))));
	$n = max(1, min(100, (int) ($_POST['qty'] ?? 1))); $n = max($n, count($serials));
	foreach ($serials as $s) { if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_wh_t('units') . ' WHERE serial = %s', $s))) { cp_el_err('Ez a sorozatszám már szerepel: ' . $s); } }
	if (count(array_unique($serials)) !== count($serials)) { cp_el_err('Egy sorozatszám kétszer szerepel a listában.'); }
	$item = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_wh_t('items') . " WHERE kind = 'asset' AND name = %s LIMIT 1", $name));
	$now = current_time('mysql');
	if (!$item) { $wpdb->insert(cp_wh_t('items'), array('kind' => 'asset', 'name' => $name, 'cat' => cp_wh_str('cat', 60), 'unit' => 'db', 'price' => max(0, (int) round(cp_wh_num($_POST['price'] ?? 0))), 'active' => 1, 'updated_at' => $now)); $item = (int) $wpdb->insert_id; }
	$have = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_wh_t('units') . ' WHERE item_id = %d', $item));
	$model = cp_wh_str('model', 160); $lim = max(0, (int) ($_POST['svc_limit'] ?? 0)); $loc = cp_wh_main();
	for ($i = 0; $i < $n; $i++) {
		$wpdb->insert(cp_wh_t('units'), array('item_id' => $item, 'label' => $name . ' #' . ($have + $i + 1), 'serial' => $serials[$i] ?? '', 'model' => $model, 'loc' => $loc, 'status' => 'ok', 'qr' => cp_wh_new_qr(), 'svc_limit' => $lim, 'updated_at' => $now));
		$uid = (int) $wpdb->insert_id;
		$wpdb->insert(cp_wh_t('moves'), array('item_id' => $item, 'unit_id' => $uid, 'qty' => 1, 'type' => 'in', 'loc_to' => $loc, 'by_name' => cp_wh_who(), 'at' => $now, 'note' => 'felvéve'));
	}
	if (function_exists('cp_audit_add')) { cp_audit_add('raktar', 'asset', 'Raktár: ' . $n . ' db eszköz felvéve – ' . $name, 'raktar', ''); }
	wp_send_json_success(array('item' => $item, 'n' => $n));
}

/* v2.9.274: egyedi felirat a QR-címkén (a QR-ablakból mentve) */
add_action('wp_ajax_nopriv_cp_wh_tag', 'cp_wh_ajax_tag'); add_action('wp_ajax_cp_wh_tag', 'cp_wh_ajax_tag');
function cp_wh_ajax_tag() {
	cp_wh_guard(); global $wpdb;
	$id = (int) ($_POST['id'] ?? 0); if (!$id) { cp_el_err('Hiányzó eszköz.'); }
	$wpdb->update(cp_wh_t('units'), array('tag' => cp_wh_str('tag', 120), 'updated_at' => current_time('mysql')), array('id' => $id));
	wp_send_json_success(array('ok' => true));
}

/* v2.9.275: törlés – cikk / eszközfajta (a darabjaival és a mozgásnaplójával együtt) vagy egy darab (a mozgásaival) */
add_action('wp_ajax_nopriv_cp_wh_del', 'cp_wh_ajax_del'); add_action('wp_ajax_cp_wh_del', 'cp_wh_ajax_del');
function cp_wh_ajax_del() {
	cp_wh_guard(); global $wpdb;
	$what = (string) ($_POST['what'] ?? ''); $id = (int) ($_POST['id'] ?? 0);
	if (!$id || !in_array($what, array('item', 'unit'), true)) { cp_el_err('Hibás kérés.'); }
	if ($what === 'unit') {
		$u = $wpdb->get_row($wpdb->prepare('SELECT u.label, u.serial, i.name FROM ' . cp_wh_t('units') . ' u LEFT JOIN ' . cp_wh_t('items') . ' i ON i.id = u.item_id WHERE u.id = %d', $id), ARRAY_A);
		if (!$u) { cp_el_err('Nincs ilyen eszköz.', 404); }
		$m = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . cp_wh_t('moves') . ' WHERE unit_id = %d', $id));
		$wpdb->delete(cp_wh_t('units'), array('id' => $id));
		$log = 'Raktár: eszköz törölve – ' . ($u['label'] ?: $u['name']) . ($u['serial'] !== '' ? ' (S/N ' . $u['serial'] . ')' : '') . ', ' . $m . ' mozgás';
	} else {
		$name = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . cp_wh_t('items') . ' WHERE id = %d', $id));
		if ($name === '') { cp_el_err('Nincs ilyen cikk.', 404); }
		$m = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . cp_wh_t('moves') . ' WHERE item_id = %d', $id));
		$n = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . cp_wh_t('units') . ' WHERE item_id = %d', $id));
		$wpdb->delete(cp_wh_t('items'), array('id' => $id));
		$log = 'Raktár: cikk törölve – ' . $name . ($n ? ', ' . $n . ' darab' : '') . ', ' . $m . ' mozgás';
	}
	if (function_exists('cp_audit_add')) { cp_audit_add('raktar', 'delete', $log, 'raktar', ''); }
	wp_send_json_success(array('ok' => true));
}
