<?php
/* =====================================================================
 * v2.9.53: összetett deal-szűrő (mint a Salesmate „View Settings → Filter”)
 * Csoportok (Deal / Kontakt / Cég / Teendő), csoporton belül ÉS/VAGY, a csoportok között
 * „mind” vagy „bármelyik”. A mezők és a műveletek fehérlistásak – a kérésből csak
 * mezőkulcs, műveletkulcs és érték jön, SQL soha.
 * Mentett nézetek: cp_cf_views opció (személyes vagy a csapattal megosztott).
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

/* mezők csoportonként: kulcs => array(címke, típus[, statikus opciók])
 * típusok: text, num, date (nap), dt (UTC időpont), enum, multi (" | "-vel tárolt lista), user, bool */
function cp_cf_defs() {
	static $d = null;
	if ($d !== null) { return $d; }
	$st = array('Open' => 'Nyitott', 'Won' => 'Megnyert', 'Lost' => 'Elvesztett');
	$d = array(
		'deal' => array('n' => 'Deal', 'f' => array(
			'title' => array('Deal neve', 'text'), 'id' => array('Deal-szám', 'num'),
			'status' => array('Állapot', 'enum', $st), 'stage' => array('Szakasz', 'enum', array_combine(cp_crm_stages(), cp_crm_stages())),
			'owner_id' => array('Felelős', 'user'), 'value' => array('Érték (Ft)', 'num'),
			'event_d' => array('Rendezvény dátuma', 'date'), 'event_end_d' => array('Rendezvény vége', 'date'), 'start_at' => array('Kezdés időpontja', 'dt'),
			'venue' => array('Rendezvény helyszíne', 'text'), 'service' => array('Szolgáltatás', 'enum'), 'package' => array('Csomag', 'enum'),
			'services_ec' => array('EventCrazy – választott szolgáltatás(ok)', 'multi'), 'services_cp' => array('Választott szolgáltatás (Crazyphoto)', 'multi'),
			'services_more' => array('További szolgáltatások', 'multi'), 'extras' => array('Választott extra', 'multi'),
			'pkg_v2' => array('Választott csomag', 'enum'), 'pkg_fix' => array('Választott csomag2', 'enum'), 'pkg_old' => array('Választott csomag_old', 'enum'),
			'spinner_pkg' => array('Spinner csomag', 'enum'), 'melange_pkg' => array('Melange csomag', 'enum'),
			'background' => array('Választott fotófal', 'enum'), 'guests' => array('Tervezett létszám', 'num'), 'hours' => array('Rendelkezésre állás (óra)', 'num'),
			'km_fee' => array('Kiszállási díj', 'num'), 'extension' => array('Hosszabbítás', 'num'),
			'pay_method' => array('Fizetési mód', 'enum'), 'paid' => array('Bejött az utalás?', 'enum'), 'coupon' => array('Kuponkód', 'text'),
			'ctype' => array('Szerződés EV/KFT', 'enum'), 'operator' => array('Gépkezelő asszisztens', 'enum'), 'album' => array('Fotóalbum', 'enum'),
			'template_st' => array('Template', 'enum'), 'template_paid' => array('Fizetős template', 'enum'), 'reminder' => array('Menjen-e emlékeztető', 'enum'),
			'cal_check' => array('Naptár', 'enum'), 'note' => array('Megjegyzés', 'text'), 'tags' => array('Címkék', 'multi'),
			'source' => array('Forrás', 'enum'), 'pipeline' => array('Pipeline', 'enum'), 'lost_reason' => array('Elvesztés oka', 'enum'),
			'win_prob' => array('Nyerési esély (%)', 'num'), 'est_close_d' => array('Várható lezárás', 'date'), 'closed_at' => array('Lezárva', 'dt'),
			'rotten_d' => array('Rohadás dátuma', 'date'), 'last_comm_at' => array('Utolsó kommunikáció', 'dt'), 'last_comm_mode' => array('Utolsó kommunikáció módja', 'enum'),
			'last_note' => array('Utolsó jegyzet', 'text'), 'last_note_at' => array('Utolsó jegyzet ideje', 'dt'), 'description' => array('Leírás', 'text'),
			'template_link' => array('Sablon kép (link)', 'text'), 'portal_link' => array('Ügyfélportál link', 'text'),
			'created_at' => array('Létrehozva', 'dt'), 'created_by' => array('Létrehozta', 'user'), 'modified_at' => array('Utoljára módosítva', 'dt'), 'modified_by' => array('Utoljára módosította', 'user'),
		)),
		'contact' => array('n' => 'Kontakt', 'f' => array(
			'name' => array('Név', 'text'), 'email' => array('E-mail', 'text'), 'phone' => array('Telefon', 'text'), 'mobile' => array('Mobil', 'text'),
			'type' => array('Típus', 'enum'), 'tags' => array('Címkék', 'multi'), 'owner_id' => array('Felelős', 'user'), 'city' => array('Város', 'text'),
			'utm_source' => array('UTM forrás', 'enum'), 'utm_medium' => array('UTM médium', 'enum'), 'utm_campaign' => array('UTM kampány', 'text'),
			'utm_term' => array('UTM kulcsszó', 'text'), 'utm_content' => array('UTM tartalom', 'text'),
			'fb_type' => array('Facebook-lead típusa', 'enum'), 'fb_ad' => array('Facebook-hirdetés', 'text'), 'fb_date' => array('Facebook-lead dátuma', 'text'),
			'bg_pref' => array('Fotófal-preferencia', 'multi'), 'email_opt_out' => array('Leiratkozott a levelekről', 'bool'),
			'description' => array('Leírás', 'text'), 'created_at' => array('Létrehozva', 'dt'),
			'deals_open' => array('Nyitott dealjeinek száma', 'num'), 'deals_won' => array('Megnyert dealjeinek száma', 'num'),
		)),
		'company' => array('n' => 'Cég', 'f' => array(
			'name' => array('Cégnév', 'text'), 'website' => array('Weboldal', 'text'), 'phone' => array('Telefon', 'text'),
			'type' => array('Típus', 'enum'), 'employees' => array('Létszám', 'num'), 'owner_id' => array('Felelős', 'user'), 'created_at' => array('Létrehozva', 'dt'),
		)),
		'activity' => array('n' => 'Teendő', 'f' => array(
			'open' => array('Nyitott teendő', 'bool'), 'late' => array('Lejárt teendő', 'bool'),
			'due' => array('Nyitott teendő határideje', 'date'), 'type' => array('Nyitott teendő típusa', 'enum'),
			'owner_id' => array('Nyitott teendő felelőse', 'user'), 'title' => array('Nyitott teendő címe', 'text'),
			'done' => array('Utolsó elvégzett teendő', 'date'),
		)),
	);
	return $d;
}

/* műveletek típusonként (a felület ugyanezt a listát kapja) */
function cp_cf_ops() {
	$o = array(
		'text' => array('has' => 'tartalmazza', 'nhas' => 'nem tartalmazza', 'eq' => 'pontosan', 'neq' => 'nem ez', 'sw' => 'így kezdődik', 'set' => 'ki van töltve', 'empty' => 'üres'),
		'num' => array('eq' => '=', 'neq' => '≠', 'gt' => 'nagyobb, mint', 'lt' => 'kisebb, mint', 'btw' => 'között', 'set' => 'ki van töltve', 'empty' => 'üres'),
		'date' => array('on' => 'ezen a napon', 'before' => 'előtte', 'after' => 'utána', 'btw' => 'között', 'today' => 'ma', 'next' => 'a következő N napban', 'past' => 'az elmúlt N napban', 'older' => 'több mint N napja', 'later' => 'több mint N nap múlva', 'set' => 'ki van töltve', 'empty' => 'üres'),
		'enum' => array('in' => 'bármelyike', 'nin' => 'egyike sem', 'set' => 'ki van töltve', 'empty' => 'üres'),
		'multi' => array('any' => 'bármelyiket tartalmazza', 'all' => 'mindet tartalmazza', 'none' => 'egyiket sem tartalmazza', 'set' => 'ki van töltve', 'empty' => 'üres'),
		'user' => array('in' => 'bármelyike', 'nin' => 'egyike sem', 'me' => 'én vagyok', 'set' => 'ki van töltve', 'empty' => 'üres'),
		'bool' => array('yes' => 'van / igen', 'no' => 'nincs / nem'),
	);
	$o['dt'] = $o['date']; /* időpont: ugyanazok a napra vonatkozó műveletek */
	return $o;
}

/* a választós mezők értékei az adatbázisból (darabszám szerint), 30 percig tárolva */
function cp_cf_opts() {
	$k = 'cp_cf_opts_' . CP_PANEL_VER;
	$c = get_transient($k);
	if (is_array($c)) { return $c; }
	global $wpdb;
	$tb = array('deal' => cp_crm_t('deals'), 'contact' => cp_crm_t('contacts'), 'company' => cp_crm_t('companies'));
	$out = array();
	foreach (cp_cf_defs() as $g => $G) {
		foreach ($G['f'] as $f => $x) {
			if (!empty($x[2]) || !in_array($x[1], array('enum', 'multi'), true)) { continue; }
			if ($g === 'activity') {
				$v = $wpdb->get_col('SELECT type FROM ' . cp_crm_t('activities') . " WHERE deleted_at IS NULL AND type <> '' GROUP BY type ORDER BY COUNT(*) DESC LIMIT 60");
			} elseif ($x[1] === 'enum') {
				$v = $wpdb->get_col("SELECT `$f` FROM {$tb[$g]} WHERE deleted_at IS NULL AND `$f` IS NOT NULL AND `$f` <> '' GROUP BY `$f` ORDER BY COUNT(*) DESC LIMIT 150");
			} else {
				$cnt = array();
				foreach ((array) $wpdb->get_col("SELECT `$f` FROM {$tb[$g]} WHERE deleted_at IS NULL AND `$f` IS NOT NULL AND `$f` <> '' ORDER BY id DESC LIMIT 6000") as $row) {
					foreach (explode(' | ', (string) $row) as $p) { $p = trim($p); if ($p !== '') { $cnt[$p] = ($cnt[$p] ?? 0) + 1; } }
				}
				arsort($cnt);
				$v = array_slice(array_keys($cnt), 0, 150);
			}
			$out[$g . '.' . $f] = array_values(array_map('strval', (array) $v));
		}
	}
	set_transient($k, $out, 30 * MINUTE_IN_SECONDS);
	return $out;
}

/* a felületnek: mezők, műveletek, értékek, nézetek */
function cp_cf_meta() {
	$o = cp_cf_opts();
	$ed = cp_cf_editable();
	$g = array();
	foreach (cp_cf_defs() as $gk => $G) {
		$fs = array();
		foreach ($G['f'] as $f => $x) {
			$e = array('k' => $f, 'n' => $x[0], 't' => $x[1]);
			if (isset($ed[$gk . '.' . $f])) { $e['e'] = $ed[$gk . '.' . $f]; }
			if (!empty($x[2])) { $e['o'] = array_map(function ($v, $n) { return array($v, $n); }, array_keys($x[2]), array_values($x[2])); }
			elseif (isset($o[$gk . '.' . $f])) { $e['o'] = array_map(function ($v) { return array($v, $v); }, $o[$gk . '.' . $f]); }
			$fs[] = $e;
		}
		$g[] = array('k' => $gk, 'n' => $G['n'], 'f' => $fs);
	}
	return array('groups' => $g, 'ops' => cp_cf_ops(), 'views' => cp_cf_views_for_me());
}

/* ------------------------------------------------------------ a kérés szűrője → tiszta szerkezet */
function cp_cf_parse($raw) {
	if (is_string($raw)) { $raw = json_decode($raw, true); }
	if (!is_array($raw)) { return null; }
	$defs = cp_cf_defs(); $ops = cp_cf_ops();
	$out = array('m' => ($raw['m'] ?? '') === 'any' ? 'any' : 'all', 'g' => array());
	foreach (array_slice((array) ($raw['g'] ?? array()), 0, 10) as $g) {
		if (!is_array($g) || !isset($defs[$g['t'] ?? ''])) { continue; }
		$t = $g['t']; $cs = array();
		foreach (array_slice((array) ($g['c'] ?? array()), 0, 20) as $c) {
			if (!is_array($c)) { continue; }
			$f = (string) ($c['f'] ?? ''); $o = (string) ($c['o'] ?? '');
			if (!isset($defs[$t]['f'][$f])) { continue; }
			$ty = $defs[$t]['f'][$f][1];
			if (!isset($ops[$ty][$o])) { continue; }
			$v = $c['v'] ?? '';
			if (is_array($v)) { $v = array_slice(array_values(array_filter(array_map(function ($x) { return is_scalar($x) ? mb_substr(trim((string) $x), 0, 200) : ''; }, $v), 'strlen')), 0, 60); }
			else { $v = is_scalar($v) ? mb_substr(trim((string) $v), 0, 200) : ''; }
			$cs[] = array('f' => $f, 'o' => $o, 'v' => $v, 'v2' => is_scalar($c['v2'] ?? '') ? mb_substr(trim((string) ($c['v2'] ?? '')), 0, 40) : '');
		}
		if ($cs) { $out['g'][] = array('t' => $t, 'm' => ($g['m'] ?? '') === 'or' ? 'or' : 'and', 'c' => $cs); }
	}
	return $out['g'] ? $out : null;
}

/* van-e benne állapot-feltétel (akkor a tábla nem szorítkozik a nyitottakra) */
function cp_cf_has_status($p) {
	if (!$p) { return false; }
	foreach ($p['g'] as $g) { foreach ($g['c'] as $c) { if ($g['t'] === 'deal' && $c['f'] === 'status') { return true; } } }
	return false;
}

/* helyi nap → UTC időpont (a dt oszlopok UTC-ben vannak) */
function cp_cf_utc($day, $plus = 0) {
	$d = date_create($day . ' 00:00:00', wp_timezone());
	if (!$d) { return null; }
	if ($plus) { $d->modify('+' . (int) $plus . ' day'); }
	$d->setTimezone(new DateTimeZone('UTC'));
	return $d->format('Y-m-d H:i:s');
}
function cp_cf_day($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; }
function cp_cf_shift($days) { return wp_date('Y-m-d', time() + (int) $days * DAY_IN_SECONDS); }

/* dátum-művelet → SQL (a $col dátum vagy UTC időpont) */
function cp_cf_date_sql($col, $dt, $o, $v, $v2) {
	global $wpdb;
	$today = wp_date('Y-m-d');
	$rng = function ($a, $b) use ($col, $dt, $wpdb) { /* [a, b] napok, mindkettő benne */
		if ($a === '' && $b === '') { return null; }
		$w = array();
		if ($a !== '') { $w[] = $dt ? $wpdb->prepare("$col >= %s", cp_cf_utc($a)) : $wpdb->prepare("$col >= %s", $a); }
		if ($b !== '') { $w[] = $dt ? $wpdb->prepare("$col < %s", cp_cf_utc($b, 1)) : $wpdb->prepare("$col <= %s", $b); }
		return '(' . implode(' AND ', $w) . ')';
	};
	$n = max(0, min(3650, (int) (is_array($v) ? 0 : $v)));
	$day = cp_cf_day(is_array($v) ? '' : $v);
	switch ($o) {
		case 'on': return $day ? $rng($day, $day) : null;
		case 'before': return $day ? ($dt ? $wpdb->prepare("$col < %s", cp_cf_utc($day)) : $wpdb->prepare("$col < %s", $day)) : null;
		case 'after': return $day ? ($dt ? $wpdb->prepare("$col >= %s", cp_cf_utc($day, 1)) : $wpdb->prepare("$col > %s", $day)) : null;
		case 'btw': $b = cp_cf_day($v2); return ($day || $b) ? $rng($day, $b) : null;
		case 'today': return $rng($today, $today);
		case 'next': return $rng($today, cp_cf_shift($n));
		case 'past': return $rng(cp_cf_shift(-$n), $today);
		case 'older': $x = cp_cf_shift(-$n); return $dt ? $wpdb->prepare("$col < %s", cp_cf_utc($x)) : $wpdb->prepare("$col < %s", $x);
		case 'later': $x = cp_cf_shift($n); return $dt ? $wpdb->prepare("$col >= %s", cp_cf_utc($x, 1)) : $wpdb->prepare("$col > %s", $x);
		case 'set': return "$col IS NOT NULL";
		case 'empty': return "$col IS NULL";
	}
	return null;
}

/* v2.9.71: „Rendezvény dátuma” szűrő többnapos dealekre: a deal akkor felel meg, ha BÁRMELYIK napja a tartományba esik.
   Gyors előszűrés: az első nap (event_d, indexelt) a tartományban, VAGY az [event_d, event_end_d] átfedi a tartományt és a napok
   táblájában (day index) tényleg van napja benne – így a szórt napok közötti üres tartomány nem talál.
   „előtte X” = az első nap X előtt; „utána X” = az utolsó nap X után; ki van töltve / üres: az event_d. */
function cp_cf_event_sql($o, $v, $v2) {
	global $wpdb;
	$today = wp_date('Y-m-d');
	$n = max(0, min(3650, (int) (is_array($v) ? 0 : $v)));
	$day = cp_cf_day(is_array($v) ? '' : $v);
	$end = 'COALESCE(d.event_end_d, d.event_d)';
	$rng = function ($a, $b) use ($wpdb) {
		if ($a === '' && $b === '') { return null; }
		$a = $a !== '' ? $a : '1000-01-01'; $b = $b !== '' ? $b : '9999-12-31';
		if ($a > $b) { $t = $a; $a = $b; $b = $t; }
		$md = function_exists('cp_mday_db_ok') && cp_mday_db_ok()
			? $wpdb->prepare(' AND EXISTS (SELECT 1 FROM ' . cp_mday_t() . ' x WHERE x.deal_id = d.id AND x.day BETWEEN %s AND %s)', $a, $b) : ' AND 1=0';
		return $wpdb->prepare('(d.event_d BETWEEN %s AND %s OR (d.event_end_d IS NOT NULL AND d.event_d < %s AND d.event_end_d >= %s', $a, $b, $a, $a) . $md . '))';
	};
	switch ($o) {
		case 'on': return $day ? $rng($day, $day) : null;
		case 'btw': $b = cp_cf_day($v2); return ($day || $b) ? $rng($day, $b) : null;
		case 'today': return $rng($today, $today);
		case 'next': return $rng($today, cp_cf_shift($n));
		case 'past': return $rng(cp_cf_shift(-$n), $today);
		case 'before': return $day ? $wpdb->prepare('d.event_d < %s', $day) : null;
		case 'older': return $wpdb->prepare('d.event_d < %s', cp_cf_shift(-$n));
		case 'after': return $day ? $wpdb->prepare("$end > %s", $day) : null;
		case 'later': return $wpdb->prepare("$end > %s", cp_cf_shift($n));
		case 'set': return 'd.event_d IS NOT NULL';
		case 'empty': return 'd.event_d IS NULL';
	}
	return null;
}

/* egy feltétel → SQL-részlet (null: hiányos, kimarad) */
function cp_cf_cond_sql($t, $c) {
	global $wpdb;
	$defs = cp_cf_defs();
	$ty = $defs[$t]['f'][$c['f']][1];
	$o = $c['o']; $v = $c['v'];
	$vs = is_array($v) ? $v : ($v === '' ? array() : array($v));
	$me = function_exists('cp_pu_current') && cp_pu_current() ? (int) cp_pu_current()['sm'] : 0;

	if ($t === 'activity') {
		$A = cp_crm_t('activities');
		/* nincs benne %-jel: a részlet $wpdb->prepare() mintájába is kerülhet */
		/* v2.9.53: a deal- és a kontakt-kapcsolat két külön (indexelt related_id-s) részlekérdezés – VAGY-gyal egyben az index nem használható, és lassú volt */
		$rd = "a.related_id = d.id AND (a.related_module = '4' OR LEFT(LOWER(a.related_module), 4) = 'deal')";
		$rc = "a.related_id = d.contact_id AND (a.related_module = '1' OR LEFT(LOWER(a.related_module), 7) = 'contact')";
		$open = "a.deleted_at IS NULL AND (a.completed IS NULL OR a.completed = 0)";
		$ex = function ($extra) use ($A, $open, $rd, $rc) {
			$x = $extra !== '' ? " AND $extra" : '';
			return "(EXISTS (SELECT 1 FROM $A a WHERE $open AND $rd$x) OR (d.contact_id IS NOT NULL AND EXISTS (SELECT 1 FROM $A a WHERE $open AND $rc$x)))";
		};
		$neg = function ($sql) { return $sql === null ? null : "NOT $sql"; };
		switch ($c['f']) {
			case 'open': return $o === 'yes' ? $ex('') : $neg($ex(''));
			case 'late': $s = $ex($wpdb->prepare('a.due_at < %s', gmdate('Y-m-d H:i:s'))); return $o === 'yes' ? $s : $neg($s);
			case 'due':
				$s = cp_cf_date_sql('a.due_at', true, $o, $v, $c['v2']);
				if ($o === 'empty') { return $neg($ex('')); }
				return $s === null ? null : $ex($s);
			case 'type':
				if ($o === 'set') { return $ex(''); } if ($o === 'empty') { return $neg($ex('')); }
				if (!$vs) { return null; }
				$s = $ex('a.type IN (' . implode(',', array_map(function ($x) use ($wpdb) { return $wpdb->prepare('%s', $x); }, $vs)) . ')');
				return $o === 'nin' ? $neg($s) : $s;
			case 'owner_id':
				if ($o === 'set') { return $ex(''); } if ($o === 'empty') { return $neg($ex('')); }
				$ids = $o === 'me' ? ($me ? array($me) : array()) : array_map('intval', $vs);
				if (!$ids) { return null; }
				$s = $ex('a.owner_id IN (' . implode(',', $ids) . ')');
				return $o === 'nin' ? $neg($s) : $s;
			case 'title':
				if ($o === 'set') { return $ex(''); } if ($o === 'empty') { return $neg($ex('')); }
				$s = cp_cf_text_sql('a.title', $o === 'nhas' ? 'has' : ($o === 'neq' ? 'eq' : $o), $v);
				return $s === null ? null : (in_array($o, array('nhas', 'neq'), true) ? $neg($ex($s)) : $ex($s));
			case 'done':
				$mx = function ($r) use ($A) { return "COALESCE((SELECT MAX(COALESCE(a.completed_at, a.due_at)) FROM $A a WHERE a.deleted_at IS NULL AND a.completed = 1 AND $r), '1000-01-01 00:00:00')"; };
				$col = 'NULLIF(GREATEST(' . $mx($rd) . ', ' . $mx($rc) . "), '1000-01-01 00:00:00')";
				return cp_cf_date_sql($col, true, $o, $v, $c['v2']);
		}
		return null;
	}

	$al = array('deal' => 'd', 'contact' => 'c', 'company' => 'co');
	if ($t === 'contact' && in_array($c['f'], array('deals_open', 'deals_won'), true)) {
		$col = '(SELECT COUNT(*) FROM ' . cp_crm_t('deals') . " d2 WHERE d2.contact_id = d.contact_id AND d2.deleted_at IS NULL AND d2.status = '" . ($c['f'] === 'deals_open' ? 'Open' : 'Won') . "')";
	} else {
		$col = $al[$t] . '.`' . $c['f'] . '`';
	}
	/* v2.9.71: a rendezvény dátuma többnapos dealnél: bármelyik napja számít (lásd cp_cf_event_sql) */
	if ($t === 'deal' && $c['f'] === 'event_d') { return cp_cf_event_sql($o, $v, $c['v2']); }
	switch ($ty) {
		case 'text': return cp_cf_text_sql($col, $o, $v);
		case 'num':
			if ($o === 'set') { return "$col IS NOT NULL"; } if ($o === 'empty') { return "$col IS NULL"; }
			if (!is_numeric($v)) { return null; }
			$n = (float) $v;
			if ($o === 'btw') { if (!is_numeric($c['v2'])) { return null; } return $wpdb->prepare("$col BETWEEN %f AND %f", min($n, (float) $c['v2']), max($n, (float) $c['v2'])); }
			$m = array('eq' => '=', 'neq' => '<>', 'gt' => '>', 'lt' => '<');
			return $o === 'neq' ? $wpdb->prepare("($col IS NULL OR $col <> %f)", $n) : $wpdb->prepare("$col {$m[$o]} %f", $n);
		case 'date': return cp_cf_date_sql($col, false, $o, $v, $c['v2']);
		case 'dt': return cp_cf_date_sql($col, true, $o, $v, $c['v2']);
		case 'bool': return $o === 'yes' ? "COALESCE($col, 0) <> 0" : "COALESCE($col, 0) = 0";
		case 'enum': case 'user':
			if ($o === 'set') { return $ty === 'user' ? "COALESCE($col, 0) <> 0" : "COALESCE($col, '') <> ''"; }
			if ($o === 'empty') { return $ty === 'user' ? "COALESCE($col, 0) = 0" : "COALESCE($col, '') = ''"; }
			if ($o === 'me') { return $me ? $wpdb->prepare("$col = %d", $me) : '1=0'; }
			if (!$vs) { return null; }
			$in = implode(',', array_map(function ($x) use ($wpdb, $ty) { return $ty === 'user' ? (string) (int) $x : $wpdb->prepare('%s', $x); }, $vs));
			return $o === 'nin' ? "($col IS NULL OR $col NOT IN ($in))" : "$col IN ($in)";
		case 'multi':
			if ($o === 'set') { return "COALESCE($col, '') <> ''"; } if ($o === 'empty') { return "COALESCE($col, '') = ''"; }
			if (!$vs) { return null; }
			$p = array_map(function ($x) use ($wpdb, $col) { return $wpdb->prepare("CONCAT(' | ', $col, ' | ') LIKE %s", '% | ' . $wpdb->esc_like($x) . ' | %'); }, $vs);
			if ($o === 'all') { return '(' . implode(' AND ', $p) . ')'; }
			if ($o === 'none') { return "($col IS NULL OR NOT (" . implode(' OR ', $p) . '))'; }
			return '(' . implode(' OR ', $p) . ')';
	}
	return null;
}

/* szöveg: ékezet- és kisbetű-független */
function cp_cf_text_sql($col, $o, $v) {
	global $wpdb;
	if ($o === 'set') { return "COALESCE($col, '') <> ''"; }
	if ($o === 'empty') { return "COALESCE($col, '') = ''"; }
	if (is_array($v) || $v === '') { return null; }
	$cc = "CONVERT(COALESCE($col, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci";
	switch ($o) {
		case 'has': return $wpdb->prepare("$cc LIKE %s", '%' . $wpdb->esc_like($v) . '%');
		case 'nhas': return $wpdb->prepare("$cc NOT LIKE %s", '%' . $wpdb->esc_like($v) . '%');
		case 'eq': return $wpdb->prepare("$cc = %s", $v);
		case 'neq': return $wpdb->prepare("$cc <> %s", $v);
		case 'sw': return $wpdb->prepare("$cc LIKE %s", $wpdb->esc_like($v) . '%');
	}
	return null;
}

/* a teljes szűrő → SQL (üres szöveg: nincs szűrés). A lekérdezésben d = deals, c = contacts, co = companies. */
function cp_cf_where($p) {
	if (!$p) { return ''; }
	$gs = array();
	foreach ($p['g'] as $g) {
		$cs = array();
		foreach ($g['c'] as $c) { $s = cp_cf_cond_sql($g['t'], $c); if ($s !== null && $s !== '') { $cs[] = '(' . $s . ')'; } }
		if ($cs) { $gs[] = '(' . implode($g['m'] === 'or' ? ' OR ' : ' AND ', $cs) . ')'; }
	}
	return $gs ? '(' . implode($p['m'] === 'any' ? ' OR ' : ' AND ', $gs) . ')' : '';
}

/* ------------------------------------------------------------ v2.9.54: lista-oszlopok és rendezés bármelyik mezőre */
/* „csoport.mező” → array(SQL-kifejezés, típus) vagy null (a Teendő-csoport nem lehet oszlop) */
function cp_cf_col($key) {
	if (!is_string($key) || !preg_match('/^(deal|contact|company)\.([a-z0-9_]+)$/', $key, $m)) { return null; }
	$defs = cp_cf_defs();
	if (!isset($defs[$m[1]]['f'][$m[2]])) { return null; }
	$ty = $defs[$m[1]]['f'][$m[2]][1];
	if ($m[1] === 'contact' && in_array($m[2], array('deals_open', 'deals_won'), true)) {
		return array('(SELECT COUNT(*) FROM ' . cp_crm_t('deals') . " d2 WHERE d2.contact_id = d.contact_id AND d2.deleted_at IS NULL AND d2.status = '" . ($m[2] === 'deals_open' ? 'Open' : 'Won') . "')", $ty);
	}
	$al = array('deal' => 'd', 'contact' => 'c', 'company' => 'co');
	return array($al[$m[1]] . '.`' . $m[2] . '`', $ty);
}
/* a kérés oszlopai (legfeljebb 30) → array(kulcs => array(kifejezés, típus)) */
function cp_cf_cols_parse($raw) {
	if (is_string($raw)) { $raw = json_decode($raw, true); }
	$out = array();
	foreach (array_slice(is_array($raw) ? $raw : array(), 0, 30) as $k) { $c = cp_cf_col(is_string($k) ? $k : ''); if ($c) { $out[$k] = $c; } }
	return $out;
}
/* a gyors szerkesztésben írható mezők: deal (a deal-adatlap mentése szerint) és a kontakt alapadatai */
function cp_cf_editable() {
	$e = array();
	if (function_exists('cp_crm_edit_fields')) { foreach (cp_crm_edit_fields() as $f => $t) { $e['deal.' . $f] = $t; } }
	foreach (array('name' => 'str', 'email' => 'str', 'mobile' => 'str', 'phone' => 'str') as $f => $t) { $e['contact.' . $f] = $t; }
	return $e;
}

/* ------------------------------------------------------------ mentett nézetek */
function cp_cf_views_all() { $v = get_option('cp_cf_views', array()); return is_array($v) ? $v : array(); }
function cp_cf_views_for_me() {
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$out = array();
	foreach (cp_cf_views_all() as $id => $x) {
		if (!empty($x['sh']) || (int) ($x['by'] ?? 0) === $me) {
			$out[] = array('id' => (string) $id, 'n' => (string) $x['n'], 'sh' => !empty($x['sh']), 'mine' => (int) ($x['by'] ?? 0) === $me, 'f' => $x['f'], 'so' => (string) ($x['so'] ?? ''), 'cols' => isset($x['cols']) && is_array($x['cols']) ? $x['cols'] : null);
		}
	}
	usort($out, function ($a, $b) { return strcmp(mb_strtolower($a['n']), mb_strtolower($b['n'])); });
	return $out;
}
function cp_cf_is_admin() { return function_exists('cp_pu_current') && cp_pu_current() && (cp_pu_current()['r'] ?? '') === 'admin'; }

add_action('wp_ajax_nopriv_cp_cf_meta', 'cp_cf_ajax_meta');
add_action('wp_ajax_cp_cf_meta', 'cp_cf_ajax_meta');
function cp_cf_ajax_meta() {
	cp_crm_ajax_guard(array('deals'));
	wp_send_json_success(cp_cf_meta());
}

add_action('wp_ajax_nopriv_cp_cf_view_save', 'cp_cf_ajax_view_save');
add_action('wp_ajax_cp_cf_view_save', 'cp_cf_ajax_view_save');
function cp_cf_ajax_view_save() {
	cp_crm_ajax_guard(array('deals'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$n = mb_substr(sanitize_text_field(wp_unslash($_POST['n'] ?? '')), 0, 60);
	$p = cp_cf_parse(wp_unslash($_POST['f'] ?? ''));
	if ($n === '') { cp_el_err('Adj nevet a nézetnek.'); }
	if (!$p) { cp_el_err('A szűrőben nincs egyetlen kész feltétel sem.'); }
	$all = cp_cf_views_all();
	$id = preg_replace('/[^a-z0-9]/', '', (string) wp_unslash($_POST['id'] ?? ''));
	if ($id !== '' && isset($all[$id])) {
		if ((int) ($all[$id]['by'] ?? 0) !== $me && !cp_cf_is_admin()) { cp_el_err('Ezt a nézetet csak a létrehozója módosíthatja.'); }
	} else {
		if (count($all) >= 200) { cp_el_err('Túl sok mentett nézet – törölj néhányat.'); }
		$id = strtolower(wp_generate_password(8, false));
		$all[$id] = array('by' => $me);
	}
	$so = preg_replace('/[^a-z_.:]/', '', (string) wp_unslash($_POST['so'] ?? ''));
	/* v2.9.54: a lista oszlopai (kulcs + szélesség) is a nézettel együtt */
	$cols = null;
	$cr = json_decode((string) wp_unslash($_POST['cols'] ?? ''), true);
	if (is_array($cr)) {
		$cols = array();
		foreach (array_slice($cr, 0, 30) as $c) {
			$k = is_array($c) ? (string) ($c['k'] ?? '') : '';
			if ($k === '' || (!cp_cf_col($k) && !in_array($k, array('_contact', '_event', '_pkg', '_stage'), true))) { continue; }
			$cols[] = array('k' => $k, 'w' => max(0, min(800, (int) ($c['w'] ?? 0))));
		}
	}
	$all[$id] = array_merge($all[$id], array('n' => $n, 'sh' => !empty($_POST['sh']) ? 1 : 0, 'f' => $p, 'so' => mb_substr($so, 0, 40), 'cols' => $cols, 'at' => time()));
	update_option('cp_cf_views', $all, false);
	wp_send_json_success(array('id' => $id, 'views' => cp_cf_views_for_me()));
}

add_action('wp_ajax_nopriv_cp_cf_view_del', 'cp_cf_ajax_view_del');
add_action('wp_ajax_cp_cf_view_del', 'cp_cf_ajax_view_del');
function cp_cf_ajax_view_del() {
	cp_crm_ajax_guard(array('deals'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$all = cp_cf_views_all();
	$id = preg_replace('/[^a-z0-9]/', '', (string) wp_unslash($_POST['id'] ?? ''));
	if (!isset($all[$id])) { cp_el_err('Nincs ilyen nézet.'); }
	if ((int) ($all[$id]['by'] ?? 0) !== $me && !cp_cf_is_admin()) { cp_el_err('Ezt a nézetet csak a létrehozója törölheti.'); }
	unset($all[$id]);
	update_option('cp_cf_views', $all, false);
	wp_send_json_success(array('views' => cp_cf_views_for_me()));
}
