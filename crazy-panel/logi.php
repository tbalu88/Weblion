<?php
/* =====================================================================
 * Logisztika (v2.9.118) – egy nap rendezvényeinek kiszállítása és bontása.
 *  – helyszínek a Kapacitás adataiból (ugyanaz a forrás), a beosztás (slotok) szerint ki van ott
 *  – időpontok: bepakolás (érkezés), kezdés, vége, bontás – a dealből becsülve, a panelben felülírható (a Salesmate-be nem ír)
 *  – „a telepítő viszi” (alapból: ahol nincs Kezelő beosztva – a Host autó nélkül megy ki), a többit a Kezelő maga viszi
 *  – geokódolás: OpenStreetMap Nominatim (gyorsítótárazva, kérésenként legfeljebb 4 új cím, 1 mp-es szünetekkel)
 *  – útvonal: OSRM (menetidő, útvonalvonal), a raktárból (XI. ker. Barázda u. 42.) – kiszállítás a bepakolási idők
 *    sorrendjében, bontás a bontási idők sorrendjében, vissza a raktárba; késés / ütközés jelzése
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }

define('CP_LOGI_DB_VER', '1');
function cp_logi_t($n) { global $wpdb; return $wpdb->prefix . ($n === 'geo' ? 'crazy_geo' : 'crazy_logi'); }
function cp_logi_db_ok() { return get_option('cp_logi_db_ver') === CP_LOGI_DB_VER; }
function cp_logi_opts() { $o = get_option('cp_logi_opts', array()); return array_merge(array('home' => 'Budapest, Barázda utca 42.', 'work' => 40, 'pre' => 60, 'hours' => 4), is_array($o) ? $o : array()); }

add_action('init', function () {
	if (cp_logi_db_ok() || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta('CREATE TABLE ' . cp_logi_t('geo') . " (
  qh char(32) NOT NULL,
  q varchar(255) NOT NULL DEFAULT '',
  lat decimal(10,7) NULL,
  lon decimal(10,7) NULL,
  ok tinyint(1) NOT NULL DEFAULT 0,
  at datetime NULL,
  PRIMARY KEY  (qh)
) $c;");
	dbDelta('CREATE TABLE ' . cp_logi_t('day') . " (
  deal_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  t_setup varchar(5) NOT NULL DEFAULT '',
  t_start varchar(5) NOT NULL DEFAULT '',
  t_end varchar(5) NOT NULL DEFAULT '',
  t_down varchar(5) NOT NULL DEFAULT '',
  deliver tinyint(1) NULL,
  note varchar(255) NOT NULL DEFAULT '',
  updated_at datetime NULL,
  PRIMARY KEY  (deal_id, day)
) $c;");
	update_option('cp_logi_db_ver', CP_LOGI_DB_VER, true);
});

function cp_logi_hm($v) { $v = trim((string) $v); return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $v) ? str_pad($v, 5, '0', STR_PAD_LEFT) : ''; }
function cp_logi_min($hm) { if ($hm === '') { return null; } list($h, $m) = array_map('intval', explode(':', $hm)); return $h * 60 + $m; }
function cp_logi_fmt($min) { if ($min === null) { return ''; } $min = (($min % 1440) + 1440) % 1440; return sprintf('%02d:%02d', intdiv($min, 60), $min % 60); }

/* cím → koordináta (gyorsítótárból; ha nincs, és $allow, lekéri) */
function cp_logi_geo($q, &$budget) {
	global $wpdb;
	$q = trim(preg_replace('/\s+/u', ' ', (string) $q));
	if ($q === '') { return null; }
	/* a helyszín-mezőkben gyakori „… - Étterem neve” / „Étterem / cím” forma: a cím rész a keresésre */
	$qq = $q;
	if (preg_match('/\b\d{4}\s+[\p{L} .-]+,?\s*[^-–\/(]+/u', $q, $m)) { $qq = trim($m[0], " ,-–"); }
	$h = md5(mb_strtolower($qq));
	$r = $wpdb->get_row($wpdb->prepare('SELECT lat, lon, ok FROM ' . cp_logi_t('geo') . ' WHERE qh = %s', $h), ARRAY_A);
	if ($r) { return (int) $r['ok'] ? array((float) $r['lat'], (float) $r['lon']) : false; }
	if ($budget <= 0) { return 'pending'; }
	$budget--;
	static $last = 0;
	$wait = 1100000 - (int) ((microtime(true) - $last) * 1000000); if ($last && $wait > 0) { usleep($wait); }
	$try = array($qq);
	if (!preg_match('/budapest|\b\d{4}\b/iu', $qq)) { $try[] = $qq . ', Budapest'; }
	$res = null;
	foreach ($try as $t) {
		$resp = wp_remote_get('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=hu&q=' . rawurlencode($t), array('timeout' => 10, 'user-agent' => 'CrazyPanel/1.0 (+https://eventcrazy.hu)'));
		$last = microtime(true);
		if (is_wp_error($resp)) { return 'pending'; }
		$j = json_decode(wp_remote_retrieve_body($resp), true);
		if (is_array($j) && !empty($j[0]['lat'])) { $res = array((float) $j[0]['lat'], (float) $j[0]['lon']); break; }
		if (count($try) > 1) { usleep(1100000); }
	}
	$wpdb->replace(cp_logi_t('geo'), array('qh' => $h, 'q' => mb_substr($qq, 0, 255), 'lat' => $res ? $res[0] : null, 'lon' => $res ? $res[1] : null, 'ok' => $res ? 1 : 0, 'at' => gmdate('Y-m-d H:i:s')));
	return $res ?: false;
}
/* OSRM útvonal (menetidő másodpercben szakaszonként + vonal) – gyorsítótárazva */
function cp_logi_route($pts) {
	if (count($pts) < 2) { return null; }
	$key = 'cp_logi_r_' . md5(wp_json_encode($pts));
	$c = get_transient($key); if ($c !== false) { return $c; }
	$coord = implode(';', array_map(function ($p) { return round($p[1], 6) . ',' . round($p[0], 6); }, $pts));
	$resp = wp_remote_get('https://router.project-osrm.org/route/v1/driving/' . $coord . '?overview=simplified&geometries=geojson', array('timeout' => 12, 'user-agent' => 'CrazyPanel/1.0 (+https://eventcrazy.hu)'));
	$out = null;
	if (!is_wp_error($resp)) {
		$j = json_decode(wp_remote_retrieve_body($resp), true);
		if (is_array($j) && ($j['code'] ?? '') === 'Ok' && !empty($j['routes'][0])) {
			$rt = $j['routes'][0];
			$out = array('legs' => array_map(function ($l) { return array('s' => (int) round($l['duration']), 'm' => (int) round($l['distance'])); }, $rt['legs']), 'line' => array_map(function ($c) { return array(round($c[1], 5), round($c[0], 5)); }, $rt['geometry']['coordinates'] ?? array()));
		}
	}
	/* tartalék: légvonal × 1,35, 35 km/h városban – ha az útvonaltervező nem érhető el */
	if (!$out) {
		$legs = array();
		for ($i = 1; $i < count($pts); $i++) {
			$a = $pts[$i - 1]; $b = $pts[$i];
			$d = 6371000 * 2 * asin(sqrt(pow(sin(deg2rad($b[0] - $a[0]) / 2), 2) + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * pow(sin(deg2rad($b[1] - $a[1]) / 2), 2))) * 1.35;
			$legs[] = array('s' => (int) round($d / (35000 / 3600)), 'm' => (int) round($d), 'est' => 1);
		}
		$out = array('legs' => $legs, 'line' => array_map(function ($p) { return array($p[0], $p[1]); }, $pts), 'est' => 1);
	} else { set_transient($key, $out, 7 * DAY_IN_SECONDS); }
	return $out;
}

function cp_logi_day($day) {
	global $wpdb;
	$o = cp_logi_opts();
	$evs = function_exists('cp_cap_month') ? (array) cp_cap_month(substr($day, 0, 7)) : array();
	$saved = array();
	foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cp_logi_t('day') . ' WHERE day = %s', $day), ARRAY_A) as $r) { $saved[(int) $r['deal_id']] = $r; }
	/* beosztás (véglegesített, ráér, meghívva) dealenként */
	$crew = array();
	if (function_exists('cp_slot_db_ok') && cp_slot_db_ok() && function_exists('cp_kz_t')) {
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT a.deal_id, a.status, k.name, k.kind, k.phone FROM ' . cp_slot_t() . ' a JOIN ' . cp_kz_t() . " k ON k.id = a.kz_id WHERE a.day = %s AND a.status IN ('final','accepted') ORDER BY FIELD(a.status,'final','accepted')", $day), ARRAY_A) as $r) {
			$crew[(int) $r['deal_id']][] = array('n' => (string) $r['name'], 'k' => (string) $r['kind'], 'st' => (string) $r['status'], 'ph' => (string) $r['phone']);
		}
	}
	$D = function_exists('cp_crm_t') ? cp_crm_t('deals') : '';
	$budget = 4; $pending = 0;
	$sites = array(); $seen = array();
	foreach ($evs as $e) {
		if (($e['date'] ?? '') !== $day) { continue; }
		$deal = (int) ($e['deal'] ?? $e['id']);
		if ($deal <= 0 || isset($seen[$deal])) { if (isset($seen[$deal])) { $sites[$seen[$deal]]['n']++; } continue; }
		$d = $D ? $wpdb->get_row($wpdb->prepare("SELECT title, venue, start_at, hours FROM $D WHERE id = %d", $deal), ARRAY_A) : null;
		$start = cp_logi_hm($e['time'] ?? '') ?: ($d && $d['start_at'] ? substr((string) cp_crm_local($d['start_at']), 11, 5) : '');
		$hours = $d && (int) $d['hours'] > 0 ? (int) $d['hours'] : (int) $o['hours'];
		$sv = $saved[$deal] ?? array();
		$tS = cp_logi_hm($sv['t_start'] ?? '') ?: $start;
		$sm = cp_logi_min($tS);
		$tE = cp_logi_hm($sv['t_end'] ?? '') ?: ($sm !== null ? cp_logi_fmt($sm + $hours * 60) : '');
		$tU = cp_logi_hm($sv['t_setup'] ?? '') ?: ($sm !== null ? cp_logi_fmt($sm - (int) $o['pre']) : '');
		$tD = cp_logi_hm($sv['t_down'] ?? '') ?: $tE;
		$cr = $crew[$deal] ?? array();
		$hasKez = (bool) array_filter($cr, function ($c) { return $c['k'] === 'kezelo'; });
		$loc = (string) ($e['loc'] ?? ($d['venue'] ?? ''));
		/* alapból csak az a budapesti (vagy ismeretlen helyű) rendezvény, ahova Host/hostess van beosztva és Kezelő nincs –
		   a Kezelő maga viszi a gépet, a vidékre Kezelő megy; a még beosztatlanokat kézzel lehet a körbe tenni */
		$hasHost = (bool) array_filter($cr, function ($c) { return $c['k'] === 'host'; });
		$bp = function_exists('cp_slot_bp') ? cp_slot_bp($loc) : null;
		$deliver = isset($sv['deliver']) && $sv['deliver'] !== null ? (int) $sv['deliver'] : (!$hasKez && $hasHost && $bp !== false ? 1 : 0);
		$g = cp_logi_geo($loc, $budget);
		if ($g === 'pending') { $pending++; }
		$seen[$deal] = count($sites);
		$sites[] = array('deal' => $deal, 'title' => (string) ($e['title'] ?? ($d['title'] ?? '')), 'loc' => $loc, 'n' => ($e['kind'] ?? '') === 'both' ? 2 : 1, 'kind' => (string) ($e['kind'] ?? ''), 'booked' => ($e['state'] ?? '') === 'booked' ? 1 : 0,
			'setup' => $tU, 'start' => $tS, 'end' => $tE, 'down' => $tD, 'est' => array('setup' => empty($sv['t_setup']), 'start' => empty($sv['t_start']) && cp_logi_hm($e['time'] ?? '') === '', 'end' => empty($sv['t_end']), 'down' => empty($sv['t_down'])),
			'deliver' => $deliver, 'deliver_set' => isset($sv['deliver']) && $sv['deliver'] !== null ? 1 : 0, 'crew' => $cr, 'note' => (string) ($sv['note'] ?? ''),
			'lat' => is_array($g) ? $g[0] : null, 'lon' => is_array($g) ? $g[1] : null, 'geo' => is_array($g) ? 1 : ($g === 'pending' ? -1 : 0));
	}
	$hb = 99; $home = cp_logi_geo($o['home'], $hb);
	/* kiszállítás és bontás: csak a „telepítő viszi” helyszínek, ahol van koordináta */
	$plan = array('out' => null, 'back' => null);
	if (is_array($home)) {
		$L = array_values(array_filter($sites, function ($s) { return $s['deliver'] && $s['lat'] !== null; }));
		foreach (array('out' => 'setup', 'back' => 'down') as $k => $f) {
			if (!$L) { continue; }
			$ord = $L;
			usort($ord, function ($a, $b) use ($f) { $x = cp_logi_min($a[$f]); $y = cp_logi_min($b[$f]); if ($x === null && $y === null) { return 0; } if ($x === null) { return 1; } if ($y === null) { return -1; } return $x - $y; });
			$pts = array_merge(array($home), array_map(function ($s) { return array($s['lat'], $s['lon']); }, $ord), array($home));
			$rt = cp_logi_route($pts);
			if (!$rt) { continue; }
			$work = (int) $o['work'];
			$stops = array(); $t = null;
			if ($k === 'out') {
				/* visszaszámolva: úgy indul, hogy az első helyszínre a bepakolás idejére odaérjen */
				$first = cp_logi_min($ord[0]['setup']);
				$t = $first !== null ? $first - (int) ceil($rt['legs'][0]['s'] / 60) - 10 : 8 * 60;
				$dep = $t;
				foreach ($ord as $i => $s) {
					$arr = $t + (int) ceil($rt['legs'][$i]['s'] / 60);
					$need = cp_logi_min($s['setup']);
					$late = $need !== null && $arr > $need ? $arr - $need : 0;
					$beg = $need !== null ? max($arr, $need) : $arr;
					$t = $beg + $work * $s['n'];
					$stops[] = array('deal' => $s['deal'], 'arr' => cp_logi_fmt($arr), 'beg' => cp_logi_fmt($beg), 'leave' => cp_logi_fmt($t), 'drive' => (int) ceil($rt['legs'][$i]['s'] / 60), 'km' => round($rt['legs'][$i]['m'] / 1000, 1), 'late' => $late,
						'startLate' => cp_logi_min($s['start']) !== null && $t > cp_logi_min($s['start']) ? $t - cp_logi_min($s['start']) : 0);
				}
				$endLeg = end($rt['legs']);
				$plan['out'] = array('dep' => cp_logi_fmt($dep), 'stops' => $stops, 'home' => cp_logi_fmt($t + (int) ceil($endLeg['s'] / 60)), 'km' => round(array_sum(array_column($rt['legs'], 'm')) / 1000, 1), 'line' => $rt['line'], 'est' => !empty($rt['est']) ? 1 : 0);
			} else {
				$first = cp_logi_min($ord[0]['down']);
				$t = $first !== null ? $first - (int) ceil($rt['legs'][0]['s'] / 60) : 20 * 60;
				$dep = $t;
				foreach ($ord as $i => $s) {
					$arr = $t + (int) ceil($rt['legs'][$i]['s'] / 60);
					$need = cp_logi_min($s['down']);
					$wait = $need !== null && $arr > $need ? $arr - $need : 0; /* ennyit vár a gép / a host a bontásra */
					$beg = $need !== null ? max($arr, $need) : $arr;
					$t = $beg + $work * $s['n'];
					$stops[] = array('deal' => $s['deal'], 'arr' => cp_logi_fmt($arr), 'beg' => cp_logi_fmt($beg), 'leave' => cp_logi_fmt($t), 'drive' => (int) ceil($rt['legs'][$i]['s'] / 60), 'km' => round($rt['legs'][$i]['m'] / 1000, 1), 'late' => $wait);
				}
				$endLeg = end($rt['legs']);
				$plan['back'] = array('dep' => cp_logi_fmt($dep), 'stops' => $stops, 'home' => cp_logi_fmt($t + (int) ceil($endLeg['s'] / 60)), 'km' => round(array_sum(array_column($rt['legs'], 'm')) / 1000, 1), 'line' => $rt['line'], 'est' => !empty($rt['est']) ? 1 : 0);
			}
		}
	}
	return array('day' => $day, 'sites' => $sites, 'home' => is_array($home) ? array('lat' => $home[0], 'lon' => $home[1], 'n' => $o['home']) : null, 'plan' => $plan, 'pending' => $pending, 'opts' => $o);
}

function cp_logi_auth() {
	cp_el_auth();
	cp_tab_need(array('naptar'));
	if (!cp_logi_db_ok()) { cp_el_err('A Logisztika adatbázisa készül – töltsd újra az oldalt.', 503); }
}
add_action('wp_ajax_nopriv_cp_logi_day', 'cp_logi_ajax_day'); add_action('wp_ajax_cp_logi_day', 'cp_logi_ajax_day');
function cp_logi_ajax_day() {
	cp_logi_auth();
	$day = (string) ($_REQUEST['day'] ?? '');
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { cp_el_err('Hibás nap.'); }
	wp_send_json_success(cp_logi_day($day));
}
add_action('wp_ajax_nopriv_cp_logi_save', 'cp_logi_ajax_save'); add_action('wp_ajax_cp_logi_save', 'cp_logi_ajax_save');
function cp_logi_ajax_save() {
	cp_logi_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$day = (string) ($_POST['day'] ?? ''); $deal = (int) ($_POST['deal'] ?? 0);
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $deal <= 0) { cp_el_err('Hibás kérés.'); }
	$T = cp_logi_t('day');
	$cur = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE deal_id = %d AND day = %s", $deal, $day), ARRAY_A) ?: array('deal_id' => $deal, 'day' => $day, 't_setup' => '', 't_start' => '', 't_end' => '', 't_down' => '', 'deliver' => null, 'note' => '');
	foreach (array('setup' => 't_setup', 'start' => 't_start', 'end' => 't_end', 'down' => 't_down') as $k => $col) { if (isset($_POST[$k])) { $cur[$col] = cp_logi_hm(wp_unslash($_POST[$k])); } }
	if (isset($_POST['deliver'])) { $cur['deliver'] = $_POST['deliver'] === '' ? null : ($_POST['deliver'] === '1' ? 1 : 0); }
	if (isset($_POST['note'])) { $cur['note'] = mb_substr(sanitize_text_field(wp_unslash($_POST['note'])), 0, 255); }
	$cur['updated_at'] = gmdate('Y-m-d H:i:s');
	$wpdb->replace($T, $cur);
	wp_send_json_success(cp_logi_day($day));
}
