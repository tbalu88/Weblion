<?php
/**
 * v2.6: Kezelők fül – a gépkezelők / hostok adatai (név, e-mail, telefon, autó rendszáma, TAJ, személyi igazolvány száma,
 * adóazonosító, anyja neve, lakcím, születési hely és idő), minden mező szerkeszthető.
 *  – A személyes azonosítók (TAJ, személyi, adóazonosító, anyja neve, lakcím, születési adatok) titkosítva tárolódnak
 *    (AES-256, mint a panel többi titka); a listában csak az látszik, hogy megvan-e. Megnézni és szerkeszteni csak admin
 *    tudja, és minden megnyitás a Naplóba kerül.
 *  – Összekötve: a Kezelői elszámolás kezelőjével (díjszabás), és a dealek „Gépkezelő” mezőjével (közelgő rendezvények).
 */
if (!defined('ABSPATH')) { exit; }

define('CP_KZ_DB_VER', '2');
function cp_kz_t() { global $wpdb; return $wpdb->prefix . 'crazy_kezelok'; }
/* v2.6.1: csoport, gépenkénti tudás, autó, felvételi lépések (az új kezelői táblázat szerint) */
function cp_kz_kinds() { return array('kezelo' => 'Kezelők', 'host' => 'Host/Hostess', 'jelentkezo' => 'Jelentkezők'); }
function cp_kz_skills() { return array('selfie' => 'Selfie', 'spinner' => 'Spinner', 'stand' => 'Stand', 'tukor' => 'Tükör', 'robot' => 'Robot', 'vr' => 'VR', 'overhead' => 'Overhead', 'vip' => 'VIP', 'catchit' => 'Catch It'); }
function cp_kz_procs() { return array('phone' => 'Telefonos egyeztetés', 'interview' => 'Személyes interjú', 'trial' => 'Próbamunka'); }
function cp_kz_csv_ok($v, $allowed) { return implode(',', array_values(array_intersect(array_keys($allowed), array_map('trim', explode(',', (string) $v))))); }
function cp_kz_sens_keys() { return array('taj' => 'TAJ', 'idcard' => 'Személyi ig. szám', 'taxid' => 'Adóazonosító', 'mother' => 'Anyja neve', 'address' => 'Lakcím', 'birth' => 'Születési hely és idő'); }
add_action('init', function () {
	if (get_option('cp_kz_db_ver') === CP_KZ_DB_VER) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_kz_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  plate varchar(40) NOT NULL DEFAULT '',
  sens text NULL,
  sens_have varchar(120) NOT NULL DEFAULT '',
  el_id varchar(40) NOT NULL DEFAULT '',
  kind varchar(12) NOT NULL DEFAULT 'kezelo',
  car varchar(12) NOT NULL DEFAULT '',
  has_car tinyint(1) NOT NULL DEFAULT 0,
  skills varchar(200) NOT NULL DEFAULT '',
  extra varchar(255) NOT NULL DEFAULT '',
  proc varchar(60) NOT NULL DEFAULT '',
  note text NULL,
  flag varchar(12) NOT NULL DEFAULT '',
  active tinyint(1) NOT NULL DEFAULT 1,
  pos int(11) NOT NULL DEFAULT 0,
  created_at datetime NULL,
  updated_at datetime NULL,
  updated_by varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY name (name)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_kz_db_ver', CP_KZ_DB_VER, true); /* v2.9.58: autoload – minden kérés olvassa */
}, 7);
function cp_kz_ok() { return get_option('cp_kz_db_ver') === CP_KZ_DB_VER && function_exists('cp_st_enc'); }
function cp_kz_cfg() {
	$on = cp_kz_ok() && function_exists('cp_panel_can') && cp_panel_can('kezelok');
	$adm = function_exists('cp_panel_is_admin') && cp_panel_is_admin();
	return array('on' => $on, 'admin' => $adm, 'sens' => cp_kz_sens_keys(), 'kinds' => cp_kz_kinds(), 'skills' => cp_kz_skills(), 'procs' => cp_kz_procs(), 'iv' => function_exists('cp_iv_ok') && cp_iv_ok() ? 1 : 0, 'csv' => $on && $adm ? admin_url('admin-ajax.php?action=cp_kz_csv&n=' . wp_create_nonce('cp_kz_csv')) : '');
}
function cp_kz_auth($admin = false) {
	cp_el_auth();
	if (!cp_kz_ok() || !cp_panel_can('kezelok')) { cp_el_err('Ehhez a fülhöz nincs hozzáférésed.', 403); }
	if ($admin) { cp_admin_need(); }
}
function cp_kz_sens_get($row) { $j = json_decode((string) cp_st_dec((string) $row['sens']), true); return is_array($j) ? $j : array(); }
function cp_kz_who() { return function_exists('cp_panel_who') ? cp_panel_who() : ''; }

/* a Kezelői elszámolás kezelőjének azonosítója név alapján (ékezet / sorrend nélkül, vagy keresztnév + vezetéknév kezdőbetűje) */
function cp_kz_el_guess($name) {
	if (!function_exists('cp_el_kez_all')) { return ''; }
	$n = function ($s) { $s = mb_strtolower(trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', (string) $s))); $s = function_exists('remove_accents') ? remove_accents($s) : $s; $w = preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY); sort($w); return implode(' ', $w); };
	$me = $n($name);
	foreach (cp_el_kez_all() as $k) { if ($n($k['sm'] ?? '') === $me || $n($k['name']) === $me) { return (string) $k['id']; } }
	return '';
}
/* közelgő rendezvények, ahol a deal „Gépkezelő” mezőjében szerepel a kezelő (teljes név, vagy az elszámolásbeli rövid név) */
/* v2.9.58: kezelőnkénti LIKE-lekérdezés helyett egyszer kérjük le a közelgő, kitöltött „Gépkezelő” mezős dealeket (kérésenként
 * gyorsítótárazva), és PHP-ben párosítunk (kis-/nagybetű és ékezet nélkül, mint a MySQL-összevetés) */
function cp_kz_up_norm($s) { $s = mb_strtolower((string) $s); return function_exists('remove_accents') ? remove_accents($s) : $s; }
function cp_kz_up_deals() {
	static $c = null;
	if ($c !== null) { return $c; }
	global $wpdb;
	$c = array();
	/* v2.9.71: többnapos rendezvény: minden közelgő napja külön sor (a nap kezdésével, helyszínével; event_d = az adott nap) */
	$t = wp_date('Y-m-d');
	$mor = function_exists('cp_mday_or_ids') ? cp_mday_or_ids($t, '9999-12-31') : '';
	$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT d.id, d.title, d.event_d, d.start_at, d.venue, d.operator FROM ' . cp_crm_t('deals') . " d WHERE d.deleted_at IS NULL AND d.status <> 'Lost' AND d.operator <> '' AND (d.event_d >= %s", $t) . $mor . ') ORDER BY d.event_d', ARRAY_A);
	if (function_exists('cp_mday_rows_days')) { $rows = cp_mday_rows_days($rows, $t, '9999-12-31'); }
	foreach ($rows as $d) {
		$x = array('id' => $d['id'], 'title' => $d['title'], 'event_d' => (string) ($d['day'] ?? $d['event_d']), 'venue' => $d['venue'], 'tm' => (string) ($d['tm'] ?? ''));
		if (!empty($d['dof'])) { $x['dn'] = (int) $d['dn']; $x['dof'] = (int) $d['dof']; }
		$x['_op'] = cp_kz_up_norm($d['operator']); $c[] = $x;
	}
	return $c;
}
function cp_kz_upcoming($names, $limit = 8) {
	if (!function_exists('cp_crm_t')) { return array(); }
	$ns = array();
	foreach (array_unique(array_filter($names)) as $x) { $x = trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', $x)); if (mb_strlen($x) >= 3) { $ns[] = cp_kz_up_norm($x); } }
	if (!$ns) { return array(); }
	$out = array();
	foreach (cp_kz_up_deals() as $d) {
		foreach ($ns as $n) { if (strpos($d['_op'], $n) !== false) { unset($d['_op']); $out[] = $d; break; } }
		if (count($out) >= (int) $limit) { break; }
	}
	return $out;
}
function cp_kz_out($r, $reveal) {
	$el = $r['el_id'] !== '' && function_exists('cp_el_kez_find') ? cp_el_kez_find($r['el_id']) : null;
	$have = $r['sens_have'] === '' ? array() : explode(',', $r['sens_have']);
	$x = array('id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'phone' => $r['phone'], 'plate' => $r['plate'], 'note' => (string) $r['note'], 'flag' => $r['flag'], 'active' => (int) $r['active'],
		'kind' => $r['kind'] ?: 'kezelo', 'car' => $r['car'], 'has_car' => (int) $r['has_car'], 'skills' => $r['skills'] === '' ? array() : explode(',', $r['skills']), 'extra' => $r['extra'], 'proc' => $r['proc'] === '' ? array() : explode(',', $r['proc']),
		'el_id' => $r['el_id'], 'el' => $el ? array('name' => $el['name'], 'role' => $el['role'], 'rate' => (int) $el['rate']) : null,
		'have' => $have, 'miss' => array_values(array_diff(array_keys(cp_kz_sens_keys()), $have)), 'updated' => function_exists('cp_crm_local') ? cp_crm_local($r['updated_at']) : $r['updated_at'], 'by' => $r['updated_by']);
	$x['up'] = cp_kz_upcoming(array($r['name'], $el ? $el['name'] : '', $el ? ($el['sm'] ?? '') : ''), 6);
	if ($reveal) { $x['sens'] = cp_kz_sens_get($r); }
	/* v2.9.85: az interjú-foglalásai jegyzettel / értékeléssel (csak olvasható; szerkeszteni a Kezelők → Interjú-időpontok oldalon lehet) */
	$x['iv'] = function_exists('cp_iv_kz_notes') ? cp_iv_kz_notes((int) $r['id']) : array();
	return $x;
}

add_action('wp_ajax_nopriv_cp_kz_list', 'cp_kz_ajax_list');
add_action('wp_ajax_cp_kz_list', 'cp_kz_ajax_list');
function cp_kz_ajax_list() {
	cp_kz_auth();
	global $wpdb;
	$reveal = !empty($_POST['reveal']) && cp_panel_is_admin();
	$rows = (array) $wpdb->get_results('SELECT * FROM ' . cp_kz_t() . ' ORDER BY pos, name', ARRAY_A);
	if ($reveal && function_exists('cp_audit_add')) { cp_audit_add('rendszer', 'kz_reveal', 'Kezelők személyes adatainak megtekintése', '', count($rows) . ' kezelő'); }
	$el = function_exists('cp_el_kez_all') ? array_map(function ($k) { return array('id' => (string) $k['id'], 'name' => $k['name'], 'role' => $k['role']); }, cp_el_kez_all()) : array();
	wp_send_json_success(array('rows' => array_map(function ($r) use ($reveal) { return cp_kz_out($r, $reveal); }, $rows), 'el' => $el, 'reveal' => $reveal ? 1 : 0));
}
add_action('wp_ajax_nopriv_cp_kz_save', 'cp_kz_ajax_save');
add_action('wp_ajax_cp_kz_save', 'cp_kz_ajax_save');
function cp_kz_ajax_save() {
	cp_kz_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$T = cp_kz_t();
	$id = (int) ($_POST['id'] ?? 0);
	$f = json_decode((string) wp_unslash($_POST['f'] ?? ''), true);
	if (!is_array($f)) { cp_el_err('Hibás kérés.'); }
	$row = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE id = %d", $id), ARRAY_A) : null;
	if ($id && !$row) { cp_el_err('Nincs ilyen kezelő.', 404); }
	$set = array();
	$s = function ($k, $max) use ($f) { return mb_substr(trim(sanitize_text_field((string) $f[$k])), 0, $max); };
	if (array_key_exists('name', $f)) { $set['name'] = $s('name', 120); if ($set['name'] === '') { cp_el_err('A név nem lehet üres.'); } }
	if (array_key_exists('email', $f)) { $e = strtolower($s('email', 190)); if ($e !== '' && !is_email($e)) { cp_el_err('Érvénytelen e-mail-cím.'); } $set['email'] = $e; }
	if (array_key_exists('phone', $f)) { $set['phone'] = mb_substr(preg_replace('/[^0-9+ ()\/-]/', '', (string) $f['phone']), 0, 40); }
	if (array_key_exists('plate', $f)) { $set['plate'] = mb_strtoupper($s('plate', 40)); }
	if (array_key_exists('note', $f)) {
		$set['note'] = mb_substr(sanitize_textarea_field((string) $f['note']), 0, 2000);
		/* v2.9.87: egyszerre szerkesztők – ha a kiinduló szöveg óta más is módosította, összefésüljük (egyik sem vész el) */
		if ($row && array_key_exists('note_base', $f) && function_exists('cp_iv_note_merge')) {
			$base = sanitize_textarea_field((string) $f['note_base']); $cur = (string) $row['note'];
			if ($cur !== $base && $cur !== $set['note'] && sanitize_textarea_field($cur) !== $base) { $set['note'] = mb_substr(cp_iv_note_merge($base, $cur, $set['note']), 0, 4000); }
		}
	}
	if (array_key_exists('flag', $f)) { $set['flag'] = in_array($f['flag'], array('', 'red', 'yellow', 'green'), true) ? $f['flag'] : ''; }
	if (array_key_exists('active', $f)) { $set['active'] = empty($f['active']) ? 0 : 1; }
	if (array_key_exists('el_id', $f)) { $set['el_id'] = sanitize_key((string) $f['el_id']); }
	if (array_key_exists('kind', $f)) { $set['kind'] = isset(cp_kz_kinds()[$f['kind']]) ? $f['kind'] : 'kezelo'; }
	if (array_key_exists('car', $f)) { $set['car'] = in_array($f['car'], array('', 'szemely', 'teher'), true) ? $f['car'] : ''; }
	if (array_key_exists('has_car', $f)) { $set['has_car'] = empty($f['has_car']) ? 0 : 1; }
	if (array_key_exists('skills', $f)) { $set['skills'] = cp_kz_csv_ok($f['skills'], cp_kz_skills()); }
	if (array_key_exists('proc', $f)) { $set['proc'] = cp_kz_csv_ok($f['proc'], cp_kz_procs()); }
	if (array_key_exists('extra', $f)) { $set['extra'] = $s('extra', 255); }
	/* személyes azonosítók: csak admin */
	$sk = array_intersect(array_keys($f), array_keys(cp_kz_sens_keys()));
	if ($sk) {
		if (!cp_panel_is_admin()) { cp_el_err('A személyes adatokat csak admin módosíthatja.', 403); }
		$cur = $row ? cp_kz_sens_get($row) : array();
		foreach ($sk as $k) { $v = mb_substr(trim(sanitize_text_field((string) $f[$k])), 0, $k === 'address' ? 255 : 120); if ($v === '') { unset($cur[$k]); } else { $cur[$k] = $v; } }
		$set['sens'] = $cur ? cp_st_enc(wp_json_encode($cur, JSON_UNESCAPED_UNICODE)) : '';
		$set['sens_have'] = implode(',', array_keys($cur));
	}
	if (!$set) { cp_el_err('Nincs mit menteni.'); }
	$set['updated_at'] = gmdate('Y-m-d H:i:s'); $set['updated_by'] = mb_substr(cp_kz_who(), 0, 100);
	if ($id) { $wpdb->update($T, $set, array('id' => $id)); }
	else {
		if (empty($set['name'])) { cp_el_err('Add meg a nevet.'); }
		if (!isset($set['el_id'])) { $set['el_id'] = cp_kz_el_guess($set['name']); }
		$wpdb->insert($T, $set + array('created_at' => gmdate('Y-m-d H:i:s'), 'pos' => (int) $wpdb->get_var("SELECT COALESCE(MAX(pos), 0) + 1 FROM $T")));
		$id = (int) $wpdb->insert_id;
	}
	if (function_exists('cp_audit_add')) { $L = cp_kz_sens_keys() + array('name' => 'név', 'email' => 'e-mail', 'phone' => 'telefon', 'plate' => 'rendszám', 'note' => 'megjegyzés', 'flag' => 'jelölés', 'active' => 'aktív', 'el_id' => 'elszámolás', 'kind' => 'csoport', 'car' => 'autó típusa', 'has_car' => 'autó', 'skills' => 'tudás', 'proc' => 'felvételi lépések', 'extra' => 'egyéb'); cp_audit_add('rendszer', 'kz_save', 'Kezelő adatainak módosítása', 'kezelo:' . $id, ($row ? $row['name'] : ($set['name'] ?? '')) . ': ' . implode(', ', array_map(function ($k) use ($L) { return $L[$k] ?? $k; }, array_keys(array_diff_key($f, array('note_base' => 1)))))); }
	$r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE id = %d", $id), ARRAY_A);
	wp_send_json_success(array('row' => cp_kz_out($r, cp_panel_is_admin() && !empty($_POST['reveal']))));
}
/* v2.6.3: sorrend (húzással) – a kapott azonosítók sorrendjében */
add_action('wp_ajax_nopriv_cp_kz_order', 'cp_kz_ajax_order');
add_action('wp_ajax_cp_kz_order', 'cp_kz_ajax_order');
function cp_kz_ajax_order() {
	cp_kz_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))));
	if (!$ids || count($ids) > 500) { cp_el_err('Hibás kérés.'); }
	foreach ($ids as $i => $id) { $wpdb->update(cp_kz_t(), array('pos' => $i + 1), array('id' => $id)); }
	if (function_exists('cp_audit_add')) { cp_audit_add('rendszer', 'kz_order', 'Kezelők sorrendje', '', count($ids) . ' sor'); }
	wp_send_json_success(array('ok' => 1));
}
add_action('wp_ajax_nopriv_cp_kz_delete', 'cp_kz_ajax_delete');
add_action('wp_ajax_cp_kz_delete', 'cp_kz_ajax_delete');
function cp_kz_ajax_delete() {
	cp_kz_auth(true);
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$id = (int) ($_POST['id'] ?? 0);
	$n = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . cp_kz_t() . ' WHERE id = %d', $id));
	if ($n === '') { cp_el_err('Nincs ilyen kezelő.', 404); }
	$wpdb->delete(cp_kz_t(), array('id' => $id));
	if (function_exists('cp_audit_add')) { cp_audit_add('rendszer', 'kz_delete', 'Kezelő törlése', 'kezelo:' . $id, $n); }
	wp_send_json_success(array('ok' => 1));
}
/* CSV (pl. a könyvelőnek) – csak admin, naplózva
 * v2.9.58: a panel-fiókok nem WP-belépéssel dolgoznak → nopriv regisztráció is (a hozzáférést a cp_kz_auth(true) → cp_el_auth
 * ellenőrzi, a link a panel-tokent is viszi) */
function cp_kz_csv_cell($v) { $v = (string) $v; return $v !== '' && strpos("=+-@\t\r", $v[0]) !== false ? "'" . $v : $v; } /* képletbefecskendezés ellen */
add_action('wp_ajax_nopriv_cp_kz_csv', 'cp_kz_ajax_csv');
add_action('wp_ajax_cp_kz_csv', 'cp_kz_ajax_csv');
function cp_kz_ajax_csv() {
	cp_kz_auth(true);
	check_ajax_referer('cp_kz_csv', 'n');
	global $wpdb;
	$rows = (array) $wpdb->get_results('SELECT * FROM ' . cp_kz_t() . ' ORDER BY active DESC, pos, name', ARRAY_A);
	if (function_exists('cp_audit_add')) { cp_audit_add('rendszer', 'kz_csv', 'Kezelők adatainak letöltése (CSV)', '', count($rows) . ' kezelő'); }
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="kezelok-' . wp_date('Y-m-d') . '.csv"');
	$fh = fopen('php://output', 'w'); fwrite($fh, "\xEF\xBB\xBF");
	fputcsv($fh, array_merge(array('Csoport', 'Név', 'E-mail', 'Telefon', 'Rendszám', 'Autó', 'Tudás', 'Egyéb'), array_values(cp_kz_sens_keys()), array('Megjegyzés', 'Aktív')), ';');
	$KN = cp_kz_kinds(); $SKL = cp_kz_skills();
	foreach ($rows as $r) { $sv = cp_kz_sens_get($r); $o = array($KN[$r['kind']] ?? $r['kind'], $r['name'], $r['email'], $r['phone'], $r['plate'], $r['car'] !== '' ? $r['car'] : ($r['has_car'] ? 'van' : ''), implode(', ', array_map(function ($k) use ($SKL) { return $SKL[$k] ?? $k; }, array_filter(explode(',', $r['skills'])))), $r['extra']); foreach (array_keys(cp_kz_sens_keys()) as $k) { $o[] = $sv[$k] ?? ''; } $o[] = $r['note']; $o[] = $r['active'] ? 'igen' : 'nem'; fputcsv($fh, array_map('cp_kz_csv_cell', $o), ';'); }
	fclose($fh); exit;
}

/* v2.9.57: interjú-időpontfoglalás (kezelő-jelentkezők) */
require_once __DIR__ . '/interview.php';
