<?php
/* =====================================================================
 * v2.9.70: többnapos rendezvény – 1. szakasz
 * Egy deal-nek több (egymást követő vagy szórt) rendezvénynapja lehet. A napok listája CSAK a panelé
 * ({prefix}crazy_crm_deal_days), a Salesmate-be semmi nem íródik.
 *
 * Szabályok:
 * – Nincs sor = egynapos deal, pontosan úgy, mint eddig (csak az event_d számít). Egy napnál a sorok törlődnek.
 * – Az 1. nap mindig az event_d (Salesmate dateCustomField1). A szerkesztő az 1. napot nem mozgatja és nem törli –
 *   azt a deal „Rendezvény dátuma” mezőjében lehet (az a Salesmate-be ír, mint eddig).
 * – Ha a Salesmate-ben (vagy a panelből) az event_d megváltozik: a TELJES naplista ugyanannyi nappal eltolódik
 *   (a rendezvény átkerült), a napi követés- és elszámolás-adatok a napokkal együtt átkerülnek (cp_mday_on_moved).
 * – event_end_d = az utolsó nap (a panel tölti), egynaposnál NULL – a listák, szűrők, rendezés ezt használják.
 * – Napi azonosító: az 1. nap a sima deal-azonosító (a meglévő követés / elszámolás adata marad), a 2..n. nap
 *   „<dealId>:<ÉÉÉÉ-HH-NN>” (cp_mday_key). A Követés pipái, a napi megjegyzés és az Elszámolás kezelő-sorai erre kötődnek.
 * – A napi mezők (kezdés, üzemóra, helyszín, létszám, háttér, megjegyzés) üresen a deal értékét öröklik.
 * – Az ár egy összeg marad a deal egészére.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_MDAY_DB_VER', '1'); /* v2.9.70: 1 = a napok táblája */
define('CP_MDAY_MAX', 62);     /* v2.9.70: legfeljebb ennyi nap egy dealen */

function cp_mday_t() {
	global $wpdb;
	return $wpdb->prefix . 'crazy_crm_deal_days';
}

/* ------------------------------------------------------------ tábla */

function cp_mday_db_install() {
	global $wpdb;
	if (get_transient('cp_mday_db_inst')) { return; }
	set_transient('cp_mday_db_inst', 1, 5 * MINUTE_IN_SECONDS);
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta('CREATE TABLE ' . cp_mday_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  deal_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  start_t varchar(5) NOT NULL DEFAULT '',
  hours decimal(4,1) NULL,
  venue varchar(255) NOT NULL DEFAULT '',
  guests int(11) NULL,
  bg varchar(80) NOT NULL DEFAULT '',
  note text NULL,
  sort smallint(6) NOT NULL DEFAULT 0,
  created_at datetime NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY deal_day (deal_id,day),
  KEY deal_id (deal_id),
  KEY day (day)
) $c;");
	update_option('cp_mday_db_ver', CP_MDAY_DB_VER, true);
	delete_transient('cp_mday_db_inst');
}

/* a crm.php mintájára: a későbbi verziók itt fogadják el a régebbit is, amíg a következő nem-AJAX kérés frissít */
function cp_mday_db_ok() {
	static $ok = null;
	if ($ok === null) { $ok = in_array(get_option('cp_mday_db_ver'), array(CP_MDAY_DB_VER), true); }
	return $ok;
}

add_action('init', function () {
	if (wp_doing_ajax()) { return; }
	if (get_option('cp_mday_db_ver') === CP_MDAY_DB_VER) { if (function_exists('cp_opt_autoload')) { cp_opt_autoload('cp_mday_db_ver'); } return; }
	cp_mday_db_install();
}, 7);

/* ------------------------------------------------------------ kulcsok, dátumok */

function cp_mday_key($deal, $day, $n) {
	return $n <= 1 ? (string) (int) $deal : (int) $deal . ':' . $day;
}

/* „123” vagy „123:2026-12-05” → array(123, '2026-12-05' | ''); érvénytelennél null */
function cp_mday_parse_key($k) {
	if (!preg_match('/^(\d{1,20})(?::(\d{4}-\d{2}-\d{2}))?$/', (string) $k, $m)) { return null; }
	return array((int) $m[1], isset($m[2]) ? $m[2] : '');
}

function cp_mday_base($k) {
	$p = cp_mday_parse_key($k);
	return $p ? (string) $p[0] : (string) $k;
}

function cp_mday_ymd_ok($d) {
	if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $d, $m)) { return false; }
	return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function cp_mday_add_days($ymd, $n) {
	$d = new DateTime($ymd . ' 12:00:00', new DateTimeZone('UTC'));
	$d->modify(($n >= 0 ? '+' : '') . (int) $n . ' day');
	return $d->format('Y-m-d');
}

function cp_mday_diff($a, $b) {
	return (int) round((strtotime($b . ' 12:00:00 UTC') - strtotime($a . ' 12:00:00 UTC')) / DAY_IN_SECONDS);
}

/* tömör kiírás: „dec. 3., 5., 12. (3 nap)”, „dec. 14–16. (3 nap)”, „dec. 30. – jan. 1. (3 nap)”;
   több évet érintve az év is: „2026. dec. 31. – 2027. jan. 1. (2 nap)” (a JS-ben ugyanez: crm.js mdayLabel) */
function cp_mday_label($days) {
	$days = array_values(array_unique(array_filter(array_map('strval', (array) $days), 'cp_mday_ymd_ok')));
	sort($days);
	$n = count($days);
	if (!$n) { return ''; }
	$ms = array('jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.');
	$multiY = substr($days[0], 0, 4) !== substr($days[$n - 1], 0, 4);
	$runs = array(); $s = $days[0]; $p = $days[0];
	for ($i = 1; $i <= $n; $i++) {
		$d = $i < $n ? $days[$i] : null;
		if ($d !== null && cp_mday_diff($p, $d) === 1) { $p = $d; continue; }
		$runs[] = array($s, $p);
		if ($d !== null) { $s = $d; $p = $d; }
	}
	$out = array(); $lastY = ''; $lastM = '';
	$fmt = function ($d, $forceM) use (&$lastY, &$lastM, $ms, $multiY) {
		$y = substr($d, 0, 4); $m = substr($d, 0, 7);
		$t = '';
		if ($multiY && $y !== $lastY) { $t .= $y . '. '; }
		if ($forceM || $m !== $lastM) { $t .= $ms[(int) substr($d, 5, 2) - 1] . ' '; }
		$lastY = $y; $lastM = $m;
		return $t . (int) substr($d, 8, 2);
	};
	foreach ($runs as $r) {
		if ($r[0] === $r[1]) { $out[] = $fmt($r[0], false) . '.'; continue; }
		$a = $fmt($r[0], false);
		if (substr($r[0], 0, 7) === substr($r[1], 0, 7)) { $lastY = substr($r[1], 0, 4); $out[] = $a . '–' . (int) substr($r[1], 8, 2) . '.'; }
		else { $out[] = $a . '. – ' . $fmt($r[1], true) . '.'; }
	}
	return implode(', ', $out) . ' (' . $n . ' nap)';
}

/* ------------------------------------------------------------ olvasás */

function cp_mday_clean_row($r) {
	return array(
		'id' => (int) $r['id'],
		'day' => (string) $r['day'],
		'start_t' => (string) $r['start_t'],
		'hours' => $r['hours'] === null || $r['hours'] === '' ? null : rtrim(rtrim(number_format((float) $r['hours'], 1, '.', ''), '0'), '.'),
		'venue' => (string) $r['venue'],
		'guests' => $r['guests'] === null || $r['guests'] === '' ? null : (int) $r['guests'],
		'bg' => (string) $r['bg'],
		'note' => (string) $r['note'],
	);
}

/* dealId => napok (dátum szerint); csak a valóban többnapos (≥ 2 sor) dealek */
function cp_mday_many($ids) {
	global $wpdb;
	$ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
	if (!$ids || !cp_mday_db_ok()) { return array(); }
	$out = array();
	foreach (array_chunk($ids, 500) as $ch) {
		$res = $wpdb->get_results('SELECT id, deal_id, day, start_t, hours, venue, guests, bg, note FROM ' . cp_mday_t() . ' WHERE deal_id IN (' . implode(',', $ch) . ') ORDER BY deal_id, day', ARRAY_A);
		foreach ((array) $res as $r) { $out[(int) $r['deal_id']][] = cp_mday_clean_row($r); }
	}
	foreach ($out as $k => $v) { if (count($v) < 2) { unset($out[$k]); } }
	return $out;
}

function cp_mday_get($id) {
	$m = cp_mday_many(array($id));
	return isset($m[(int) $id]) ? $m[(int) $id] : array();
}

/* azok a deal-ek, amelyeknek van napja a tartományban (az első napjuk lehet előtte is) */
function cp_mday_ids_in_range($from, $to) {
	global $wpdb;
	if (!cp_mday_db_ok()) { return array(); }
	return array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT DISTINCT deal_id FROM ' . cp_mday_t() . ' WHERE day BETWEEN %s AND %s', $from, $to)));
}

/* a napok igazítása a deal tényleges (Salesmate) dátumához: ha az 1. nap eltér, az egész lista eltolódik */
function cp_mday_align($days, $event_d) {
	if (!$days || !cp_mday_ymd_ok($event_d) || $days[0]['day'] === $event_d) { return $days; }
	$delta = cp_mday_diff($days[0]['day'], $event_d);
	foreach ($days as &$d) { $d['day'] = cp_mday_add_days($d['day'], $delta); }
	unset($d);
	return $days;
}

/* ------------------------------------------------------------ tartomány-kibontás (cp_sm_fetch_range után)
 * Minden többnapos deal annyi sorrá bomlik, ahány napja a tartományba esik; a sor a nap adataival (felülírásokkal),
 * a napi azonosítóval és egy '_mday' jelölővel ('deal', 'n', 'of', 'day'). Az egynapos sorok érintetlenek. */
function cp_mday_expand_raw($rows, $from = null, $to = null) {
	if (!is_array($rows) || !$rows || !cp_mday_db_ok()) { return $rows; }
	$ids = array();
	foreach ($rows as $r) { $id = cp_panel_str(cp_panel_get($r, 'id')); if (ctype_digit($id)) { $ids[] = (int) $id; } }
	$map = cp_mday_many($ids);
	if (!$map) { return $rows; }
	$p = cp_panel_opts();
	$e = function_exists('cp_el_opts') ? cp_el_opts() : array();
	$out = array();
	foreach ($rows as $r) {
		$id = cp_panel_str(cp_panel_get($r, 'id'));
		$ev = ctype_digit($id) && isset($map[(int) $id]) ? cp_panel_norm_date(cp_panel_get($r, $p['f_date'])) : '';
		if ($ev === '') { $out[] = $r; continue; }
		$days = cp_mday_align($map[(int) $id], $ev);
		$n = count($days);
		foreach ($days as $k => $dr) {
			if (($from !== null && $dr['day'] < $from) || ($to !== null && $dr['day'] > $to)) { continue; }
			$out[] = cp_mday_apply($r, $dr, $k + 1, $n, (int) $id, $p, $e);
		}
	}
	return $out;
}

function cp_mday_apply($r, $dr, $k, $n, $id, $p, $e) {
	$set = function (&$r, $keys, $v) { foreach ((array) $keys as $f) { if ($f !== '' && $f !== null) { $r[$f] = $v; } } };
	$c = $r;
	$c['id'] = cp_mday_key($id, $dr['day'], $k);
	$set($c, $p['f_date'], $dr['day']);
	/* kezdés: a nap saját időpontja, különben a deal kezdési ideje ugyanazon a napon */
	$sk = array_values(array_unique(array_filter(array($p['f_time'] ?? '', $e['f_start'] ?? ''))));
	if ($sk) {
		$hm = $dr['start_t'];
		if ($hm === '' && function_exists('cp_el_time')) { $hm = cp_el_time(cp_panel_get($r, $sk[0])); }
		if (preg_match('/^(\d{1,2}):(\d{2})$/', (string) $hm, $m)) {
			$dt = new DateTime($dr['day'] . ' ' . sprintf('%02d:%02d', $m[1], $m[2]) . ':00', new DateTimeZone('Europe/Budapest'));
			$set($c, $sk, $dt->getTimestamp() * 1000);
		}
	}
	if ($dr['hours'] !== null) { $set($c, $e['f_hours'] ?? '', is_numeric($dr['hours']) ? $dr['hours'] + 0 : $dr['hours']); }
	if ($dr['venue'] !== '') { $set($c, $p['f_loc'], $dr['venue']); }
	if ($dr['guests'] !== null) { $set($c, $p['f_guests'], $dr['guests']); }
	if ($dr['bg'] !== '') { $set($c, $p['f_bg'], $dr['bg']); }
	if ($dr['note'] !== '') { $set($c, $p['f_note'], $dr['note']); }
	/* a hosszabbítás (Salesmate-mező) az 1. napé; a többi nap a saját követés-adatából */
	if ($k > 1 && ($p['f_hx'] ?? '') !== '') { $c[$p['f_hx']] = null; }
	$c['_mday'] = array('deal' => (string) $id, 'n' => $k, 'of' => $n, 'day' => $dr['day']);
	return $c;
}

/* a Salesmate-ből olvasott tartományhoz: a tartomány előtt kezdődő, de benne is napot tartó deal-ek pótlása a saját táblából */
function cp_mday_supplement($rows, $from, $to) {
	if (!is_array($rows) || !cp_mday_db_ok() || !function_exists('cp_crm_row_shape') || !function_exists('cp_crm_db_ok') || !cp_crm_db_ok()) { return $rows; }
	$have = array();
	foreach ($rows as $r) { $have[cp_panel_str(cp_panel_get($r, 'id'))] = 1; }
	$need = array();
	foreach (cp_mday_ids_in_range($from, $to) as $id) { if (!isset($have[(string) $id])) { $need[] = (int) $id; } }
	if (!$need) { return $rows; }
	global $wpdb;
	$d = cp_crm_t('deals'); $c = cp_crm_t('contacts');
	$res = $wpdb->get_results("SELECT d.id, d.raw, d.owner_id, d.contact_id, d.start_at, c.name cn, c.email ce, c.phone cp, c.mobile cm FROM $d d LEFT JOIN $c c ON c.id = d.contact_id WHERE d.deleted_at IS NULL AND d.id IN (" . implode(',', array_slice($need, 0, 300)) . ')', ARRAY_A);
	foreach ((array) $res as $x) { $n = cp_crm_row_shape($x); if ($n) { $rows[] = $n; } }
	return $rows;
}

/* esemény / sor → a deal azonosítója (a napi kulcsból) */
function cp_mday_base_ids($list) {
	$out = array();
	foreach ((array) $list as $k) { $out[cp_mday_base($k)] = 1; }
	return array_keys($out);
}

/* ------------------------------------------------------------ v2.9.71: listák napokra bontva (Kezdőlap, Kezelők)
 * $rows: deal-táblás sorok (legalább id, event_d; ha van: start_at UTC, venue, hours). Vissza: a [from, to] közé eső napok sorai,
 * mindegyikben 'day', 'key' (napi kulcs), 'tm' (helyi kezdés, a nap saját időpontja vagy a dealé), a nap helyszíne / óraszáma,
 * többnaposnál 'dn' és 'dof' („2/3. nap”). Nap és kezdés szerint rendezve. */
function cp_mday_rows_days($rows, $from, $to) {
	$map = cp_mday_many(array_map(function ($r) { return (int) $r['id']; }, (array) $rows));
	$loc = function ($dt) { return $dt && function_exists('cp_crm_local') ? substr((string) cp_crm_local($dt), 11, 5) : ''; };
	$out = array();
	foreach ((array) $rows as $r) {
		$tm = $loc($r['start_at'] ?? '');
		$id = (int) $r['id'];
		if (!isset($map[$id]) || empty($r['event_d'])) {
			if (!empty($r['event_d']) && $r['event_d'] >= $from && $r['event_d'] <= $to) { $out[] = $r + array('day' => (string) $r['event_d'], 'key' => (string) $id, 'tm' => $tm); }
			continue;
		}
		$days = cp_mday_align($map[$id], (string) $r['event_d']);
		$n = count($days);
		foreach ($days as $k => $dr) {
			if ($dr['day'] < $from || $dr['day'] > $to) { continue; }
			$x = $r;
			$x['day'] = $dr['day']; $x['key'] = cp_mday_key($id, $dr['day'], $k + 1); $x['dn'] = $k + 1; $x['dof'] = $n;
			$x['tm'] = $dr['start_t'] !== '' ? $dr['start_t'] : $tm;
			if ($dr['venue'] !== '' && array_key_exists('venue', $r)) { $x['venue'] = $dr['venue']; }
			if ($dr['hours'] !== null && array_key_exists('hours', $r)) { $x['hours'] = $dr['hours']; }
			$out[] = $x;
		}
	}
	usort($out, function ($a, $b) { return strcmp($a['day'] . ($a['tm'] !== '' ? $a['tm'] : '99'), $b['day'] . ($b['tm'] !== '' ? $b['tm'] : '99')) ?: ((int) $a['id'] - (int) $b['id']); });
	return $out;
}

/* a többnapos dealek kiegészítő feltétele egy event_d-s lekérdezéshez: „ OR d.id IN (…)” (üres, ha nincs) */
function cp_mday_or_ids($from, $to, $alias = 'd') {
	$ids = array_slice(cp_mday_ids_in_range($from, $to), 0, 500);
	return $ids ? ' OR ' . $alias . '.id IN (' . implode(',', array_map('intval', $ids)) . ')' : '';
}

/* ------------------------------------------------------------ kapacitás: a deal legrosszabb napja */

function cp_mday_cap_worst($days, $cached_only = false) {
	if (!function_exists('cp_cap_day')) { return null; }
	$t = wp_date('Y-m-d');
	$best = null;
	foreach ((array) $days as $d) {
		if ($d < $t) { continue; }
		$k = cp_cap_day($d, $cached_only);
		if (!$k) { continue; }
		if ($best === null || $k['fs'] < $best['fs'] || ($k['fs'] === $best['fs'] && $k['risk'] > $best['risk'])) { $best = $k; }
	}
	if ($best) { $best['days'] = count($days); }
	return $best;
}

/* ------------------------------------------------------------ írás */

/* a napi követés (cp_pt_) és elszámolás (cp_eld_) adatainak átvitele új napi kulcsra: array(régi => új) */
function cp_mday_rekey($map) {
	$map = array_filter((array) $map, function ($v, $k) { return (string) $v !== (string) $k; }, ARRAY_FILTER_USE_BOTH);
	if (!$map) { return; }
	/* v2.9.71: a panel által létrehozott napi Google Naptár-bejegyzés is a napjával megy (a következő szinkron áthelyezi) */
	$made = get_option('cp_gcal_made', null);
	if (is_array($made)) {
		$mv = array();
		foreach ($map as $old => $new) { if (isset($made[$old])) { $mv[$new] = $made[$old]; unset($made[$old]); } }
		if ($mv) { update_option('cp_gcal_made', $mv + $made, false); }
	}
	$pre = array(defined('CP_PANEL_TRACK') ? CP_PANEL_TRACK : 'cp_pt_', defined('CP_EL_ST') ? CP_EL_ST : 'cp_eld_');
	foreach ($pre as $px) {
		$vals = array();
		foreach ($map as $old => $new) {
			$v = get_option($px . $old, null);
			if ($v !== null && $v !== false) { $vals[$new] = $v; delete_option($px . $old); }
		}
		foreach ($vals as $new => $v) {
			if (is_array($v) && isset($v['d']) && ($p = cp_mday_parse_key($new)) && $p[1] !== '') { $v['d'] = $p[1]; }
			update_option($px . $new, $v, false);
		}
	}
}

function cp_mday_hist($deal, $old, $new) {
	global $wpdb;
	if ((string) $old === (string) $new || !function_exists('cp_crm_t')) { return; }
	$wpdb->insert(cp_crm_t('history'), array(
		'deal_id' => (int) $deal, 'field' => 'event_days', 'old_val' => mb_substr((string) $old, 0, 2000), 'new_val' => mb_substr((string) $new, 0, 2000),
		'by_user' => mb_substr(function_exists('cp_panel_who') ? (string) cp_panel_who() : 'Crazy panel', 0, 100), 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel',
	));
}

function cp_mday_set_end($deal, $days) {
	global $wpdb;
	if (!function_exists('cp_crm_t')) { return; }
	$end = count($days) >= 2 ? max($days) : null;
	$wpdb->query($end === null ? $wpdb->prepare('UPDATE ' . cp_crm_t('deals') . ' SET event_end_d = NULL WHERE id = %d', $deal) : $wpdb->prepare('UPDATE ' . cp_crm_t('deals') . ' SET event_end_d = %s WHERE id = %d', $end, $deal));
}

function cp_mday_after_change() {
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	if (function_exists('cp_crm_rev_bump')) { cp_crm_rev_bump(); }
}

/* v2.9.70: van-e rögzített elszámolás a napi kulcson: '' = nincs, különben az ok (kifizetve / óraszám vagy díj / fotó).
   A kifizetés a kezelő-soron 'pd' (dátum); óraszám: h, hc, rhx; díjak: p, kx, c, pk, jt; a nap km-e: km */
function cp_mday_billing($key) {
	$st = get_option((defined('CP_EL_ST') ? CP_EL_ST : 'cp_eld_') . $key, array());
	if (!is_array($st)) { return ''; }
	$rows = isset($st['r']) && is_array($st['r']) ? $st['r'] : array();
	foreach ($rows as $r) { if (is_array($r) && !empty($r['pd'])) { return 'kifizetve'; } }
	$has = function ($v) { return $v !== null && $v !== '' && $v !== false; };
	if (isset($st['km']) && $has($st['km'])) { return 'óraszám vagy díj'; }
	foreach ($rows as $r) {
		if (!is_array($r)) { continue; }
		foreach (array('h', 'hc', 'rhx', 'p', 'kx', 'c', 'pk', 'jt') as $f) { if (isset($r[$f]) && $has($r[$f])) { return 'óraszám vagy díj'; } }
		foreach (array('ph', 'pha', 'phs') as $f) { if (!empty($r[$f])) { return 'feltöltött fotó'; } }
	}
	return '';
}

/* a hibaüzenethez névelővel és raggal: „A dec. 15-i”, „Az okt. 1-jei”, „A jan. 21-jei” */
function cp_mday_hu_day($d) {
	$ms = array('jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.');
	$m = $ms[(int) substr($d, 5, 2) - 1]; $n = (int) substr($d, 8, 2);
	return (in_array($m, array('ápr.', 'aug.', 'okt.'), true) ? 'Az ' : 'A ') . $m . ' ' . $n . ($n % 10 === 1 && $n !== 11 ? '-jei' : '-i');
}

/* egy nap mezőinek tisztítása; hibánál WP_Error */
function cp_mday_clean_in($x) {
	$g = function ($k) use ($x) { return isset($x[$k]) && is_scalar($x[$k]) ? trim((string) $x[$k]) : ''; };
	$day = $g('day');
	if (!cp_mday_ymd_ok($day)) { return new WP_Error('cp_mday', 'Hibás dátum: ' . $day); }
	$st = $g('start_t');
	if ($st !== '' && !preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)$/', $st, $m)) { return new WP_Error('cp_mday', 'Hibás kezdési idő (' . $day . '): ' . $st . ' – óó:pp formában add meg.'); }
	if ($st !== '') { $st = sprintf('%02d:%02d', $m[1], $m[2]); }
	$h = str_replace(',', '.', $g('hours'));
	if ($h !== '' && (!is_numeric($h) || (float) $h <= 0 || (float) $h > 24)) { return new WP_Error('cp_mday', 'Hibás üzemóra (' . $day . '): 0 és 24 közötti szám kell.'); }
	$gs = $g('guests');
	if ($gs !== '' && (!ctype_digit($gs) || (int) $gs < 1 || (int) $gs > 100000)) { return new WP_Error('cp_mday', 'Hibás létszám (' . $day . ').'); }
	return array(
		'id' => isset($x['id']) && is_numeric($x['id']) ? (int) $x['id'] : 0,
		'day' => $day, 'start_t' => $st,
		'hours' => $h === '' ? null : round((float) $h * 2) / 2,
		'venue' => mb_substr(sanitize_text_field($g('venue')), 0, 255),
		'guests' => $gs === '' ? null : (int) $gs,
		'bg' => mb_substr(sanitize_text_field($g('bg')), 0, 80),
		'note' => mb_substr(sanitize_textarea_field($g('note')), 0, 1000),
	);
}

/* a deal napjainak mentése (a teljes lista); visszaad: array(days, label, end) vagy WP_Error */
function cp_mday_save($deal, $list) {
	global $wpdb;
	if (!cp_mday_db_ok()) { return new WP_Error('cp_mday', 'A napok táblája még készül – próbáld újra pár perc múlva.', 503); }
	$row = $wpdb->get_row($wpdb->prepare('SELECT id, event_d, deleted_at FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $deal), ARRAY_A);
	if (!$row || $row['deleted_at']) { return new WP_Error('cp_mday', 'Nincs ilyen deal.', 404); }
	$ev = (string) $row['event_d'];
	if (!cp_mday_ymd_ok($ev)) { return new WP_Error('cp_mday', 'Előbb add meg a rendezvény dátumát – az lesz az 1. nap.'); }
	if (!is_array($list) || count($list) > CP_MDAY_MAX) { return new WP_Error('cp_mday', 'Legfeljebb ' . CP_MDAY_MAX . ' nap adható meg.'); }
	$new = array();
	foreach ($list as $x) {
		$c = cp_mday_clean_in(is_array($x) ? $x : array());
		if (is_wp_error($c)) { return $c; }
		if (isset($new[$c['day']])) { return new WP_Error('cp_mday', 'Egy nap kétszer szerepel: ' . $c['day']); }
		$new[$c['day']] = $c;
	}
	ksort($new);
	$new = array_values($new);
	if ($new && $new[0]['day'] !== $ev) {
		return new WP_Error('cp_mday', $new[0]['day'] < $ev ? 'Az 1. nap a deal „Rendezvény dátuma” (' . $ev . '). Korábbi napot ott állíts be – a többi nap vele együtt tolódik.' : 'Az 1. nap (' . $ev . ', a deal „Rendezvény dátuma”) nem hagyható ki.');
	}
	$old = $wpdb->get_results($wpdb->prepare('SELECT id, deal_id, day, start_t, hours, venue, guests, bg, note, created_at FROM ' . cp_mday_t() . ' WHERE deal_id = %d ORDER BY day', $deal), ARRAY_A);
	$oldDays = array_map(function ($r) { return (string) $r['day']; }, (array) $old);
	$oldLbl = count($oldDays) >= 2 ? cp_mday_label($oldDays) : '';
	/* napi kulcsok átvitele: a megmaradt (azonos azonosítójú) sor dátuma módosult */
	$byId = array(); $created = array();
	foreach ((array) $old as $k => $r) { $byId[(int) $r['id']] = $r; $created[(string) $r['day']] = $r['created_at']; }
	$rk = array();
	if (count($new) >= 2) {
		foreach ($new as $k => $c) {
			if ($c['id'] && isset($byId[$c['id']]) && (string) $byId[$c['id']]['day'] !== $c['day'] && $k > 0 && (string) $byId[$c['id']]['day'] !== (string) $oldDays[0]) {
				$rk[(int) $deal . ':' . $byId[$c['id']]['day']] = cp_mday_key($deal, $c['day'], 2);
			}
		}
	}
	/* v2.9.70: a 2..n. napok sorsa – megmarad, új dátumot kap, vagy törlődik. Rögzített elszámolású nap nem törölhető és nem
	   kap új dátumot (a kifizetés / a kezelői díj a napi kulcson van, a hónapzárás és a kifizetés-lista arra hivatkozik) */
	$newDay = array(); $newId = array();
	foreach ($new as $c) { $newDay[$c['day']] = 1; if ($c['id']) { $newId[$c['id']] = $c['day']; } }
	$gone = array();
	foreach ((array) $old as $k => $r) {
		$d = (string) $r['day'];
		if ($k === 0 || $d === (string) $oldDays[0]) { continue; }
		$to = count($new) >= 2 && isset($newId[(int) $r['id']]) ? $newId[(int) $r['id']] : (count($new) >= 2 && isset($newDay[$d]) ? $d : '');
		if ($to === $d) { continue; }
		$why = cp_mday_billing((int) $deal . ':' . $d);
		if ($why !== '') {
			return new WP_Error('cp_mday', cp_mday_hu_day($d) . ' nap ' . ($to === '' ? 'nem törölhető' : 'dátuma nem módosítható') . ': van hozzá rögzített elszámolás (' . $why . '). Előbb az Elszámolásban vedd ki.', 409);
		}
		if ($to === '') { $gone[] = (int) $deal . ':' . $d; }
	}
	$now = gmdate('Y-m-d H:i:s');
	$wpdb->query($wpdb->prepare('DELETE FROM ' . cp_mday_t() . ' WHERE deal_id = %d', $deal));
	if (count($new) >= 2) {
		foreach ($new as $k => $c) {
			$wpdb->insert(cp_mday_t(), array(
				'deal_id' => (int) $deal, 'day' => $c['day'], 'start_t' => $c['start_t'], 'hours' => $c['hours'], 'venue' => $c['venue'],
				'guests' => $c['guests'], 'bg' => $c['bg'], 'note' => $c['note'], 'sort' => $k,
				'created_at' => isset($created[$c['day']]) && $created[$c['day']] ? $created[$c['day']] : $now, 'updated_at' => $now,
			));
		}
	}
	cp_mday_rekey($rk);
	/* v2.9.70: a törölt (elszámolás nélküli) nap napi kulcsú pipái és üres kezelő-sorai ne maradjanak árván */
	foreach ($gone as $gk) {
		delete_option((defined('CP_PANEL_TRACK') ? CP_PANEL_TRACK : 'cp_pt_') . $gk);
		delete_option((defined('CP_EL_ST') ? CP_EL_ST : 'cp_eld_') . $gk);
	}
	$days = array_map(function ($c) { return $c['day']; }, $new);
	cp_mday_set_end($deal, $days);
	$newLbl = count($days) >= 2 ? cp_mday_label($days) : '';
	$chg = $oldLbl !== $newLbl;
	if (!$chg && $old && count($new) >= 2) {
		$sig = function ($rows) { return wp_json_encode(array_map(function ($r) { $x = cp_mday_clean_row(array_merge($r, array('id' => 0))); unset($x['id']); return $x; }, array_values($rows))); };
		if ($sig($old) !== $sig($new)) { cp_mday_hist($deal, $oldLbl, $newLbl . ' – napi adatok módosítva'); }
	} elseif ($chg) {
		cp_mday_hist($deal, $oldLbl !== '' ? $oldLbl : 'egynapos (' . $ev . ')', $newLbl !== '' ? $newLbl : 'egynapos (' . $ev . ')');
	}
	cp_mday_after_change();
	$out = cp_mday_get($deal);
	return array('days' => $out, 'label' => $newLbl, 'end' => count($days) >= 2 ? max($days) : null);
}

/* a háttér egy napra (a Követés / Hátterek választójából, többnapos dealnél – a Salesmate-be nem ír) */
function cp_mday_set_bg($deal, $day, $bg) {
	global $wpdb;
	$n = $wpdb->query($wpdb->prepare('UPDATE ' . cp_mday_t() . ' SET bg = %s, updated_at = %s WHERE deal_id = %d AND day = %s', mb_substr((string) $bg, 0, 80), gmdate('Y-m-d H:i:s'), $deal, $day));
	if ($n) { cp_mday_hist($deal, '', 'háttér ' . $day . ': ' . ($bg !== '' ? $bg : '(a deal hátterét örökli)')); cp_mday_after_change(); }
	return (bool) $n;
}

/* a deal dátuma megváltozott (Salesmate-szinkron vagy panelből mentés): a teljes lista eltolódik */
function cp_mday_on_moved($deal, $old, $new) {
	global $wpdb;
	if (!cp_mday_db_ok() || !cp_mday_ymd_ok($old) || !cp_mday_ymd_ok($new) || $old === $new) { return; }
	$rows = $wpdb->get_results($wpdb->prepare('SELECT id, day FROM ' . cp_mday_t() . ' WHERE deal_id = %d ORDER BY day', $deal), ARRAY_A);
	if (count((array) $rows) < 2) { return; }
	$first = (string) $rows[0]['day'];
	$delta = cp_mday_diff($first, $new);
	if ($delta === 0) { return; }
	$oldDays = array(); $newDays = array(); $rk = array();
	foreach ($rows as $k => $r) {
		$d = (string) $r['day']; $nd = cp_mday_add_days($d, $delta);
		$oldDays[] = $d; $newDays[] = $nd;
		if ($k > 0) { $rk[(int) $deal . ':' . $d] = (int) $deal . ':' . $nd; }
	}
	/* a UNIQUE (deal_id, day) miatt két lépésben: előbb messzire, aztán a helyére */
	$wpdb->query($wpdb->prepare('UPDATE ' . cp_mday_t() . ' SET day = DATE_ADD(day, INTERVAL 40000 DAY) WHERE deal_id = %d', $deal));
	$wpdb->query($wpdb->prepare('UPDATE ' . cp_mday_t() . ' SET day = DATE_ADD(day, INTERVAL %d DAY), updated_at = %s WHERE deal_id = %d', $delta - 40000, gmdate('Y-m-d H:i:s'), $deal));
	cp_mday_rekey($rk);
	cp_mday_set_end($deal, $newDays);
	cp_mday_hist($deal, cp_mday_label($oldDays), cp_mday_label($newDays) . ' – a rendezvény dátumával együtt eltolva');
	cp_mday_after_change();
}

/* ------------------------------------------------------------ AJAX: napok mentése (Deal-adatlap) */

add_action('wp_ajax_nopriv_cp_crm_days_save', 'cp_mday_ajax_save');
add_action('wp_ajax_cp_crm_days_save', 'cp_mday_ajax_save');
function cp_mday_ajax_save() {
	cp_crm_ajax_guard();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
	$list = json_decode(isset($_POST['days']) ? (string) wp_unslash($_POST['days']) : '', true);
	if ($id <= 0 || !is_array($list)) { cp_el_err('Hibás kérés.'); }
	$r = cp_mday_save($id, $list);
	if (is_wp_error($r)) { cp_el_err($r->get_error_message(), (int) ($r->get_error_data() ?: 400)); }
	wp_send_json_success($r);
}
