<?php
/* =====================================================================
 * v1.82: becsült ár a Deal-ek fül csempéin – az ajánlat előtti (első szakaszban lévő, érték nélküli) dealekre
 * Ugyanaz az árlista és számítás, mint a crazyphoto.hu árkalkulátoráé (crazyphoto-site/arkalkulator):
 * csomag (vagy óraszám), létszám-felár, −15% szelfigép + spinner együtt, kiszállás (Budapesten csomag szerint,
 * vidéken 130 Ft/km oda-vissza). A távolságot a háttérben számolja (OpenStreetMap: Nominatim + OSRM), és megjegyzi.
 * Árváltozáskor itt (cp_est_prices) és az árkalkulátorban is frissíteni kell.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_est_prices() {
	return array(
		'km' => 130, 'trip' => 2, 'both' => 0.15,
		'guests' => array(array(150, 0), array(200, 5000), array(300, 7500), array(500, 10000), array(1000, 20000)),
		'origin' => array(47.4577, 19.0424),
		'm' => array(
			'selfie' => array('n' => 'Szelfigép', 'xh' => 30000, 'pk' => array(array('Mini', 3, 99990, 5000), array('Medium Party', 4, 119990, 5000), array('Mega Party', 5, 139990, 0), array('Carneval', 6, 159990, 0))),
			'spin' => array('n' => '360 Spinner', 'xh' => 40000, 'pk' => array(array('Mini', 3, 169990, 5000), array('Medium Party', 4, 179990, 5000), array('Mega Party', 5, 194990, 5000), array('Carneval', 6, 219990, 5000))),
		),
	);
}
function cp_est_norm_venue($v) { return trim(mb_strtolower(preg_replace('/\s+/u', ' ', (string) $v))); }
function cp_est_is_bp($v) {
	$v = cp_est_norm_venue($v);
	if ($v === '') { return false; }
	if (preg_match('/(^|\D)[2-9]\d{3}(\D|$)/', $v)) { return false; }
	if (preg_match('/(^|\D)1\d{3}(\D|$)/', $v) || preg_match('/\b(budapest\w*|bp\.?)(\b|$)/u', $v)) { return true; }
	/* elütések: „budapets”, „budaepst”, „bduapest” – szavanként legfeljebb 2 betű eltérés (ékezet nélkül) */
	foreach (preg_split('/[^\p{L}]+/u', remove_accents($v)) as $w) { if (strlen($w) >= 6 && strlen($w) <= 11 && levenshtein($w, 'budapest') <= 2) { return true; } }
	return false;
}
function cp_est_geo_get($v) { $g = get_option('cp_est_geo', array()); $k = md5(cp_est_norm_venue($v)); return is_array($g) && isset($g[$k]) ? $g[$k] : null; }

/* a becslés egy dealre; null, ha nem tudni, mire kérnek ajánlatot */
function cp_est_deal($d) {
	$P = cp_est_prices();
	$txt = mb_strtolower(implode(' | ', array_map('strval', array($d['service'] ?? '', $d['services_cp'] ?? '', $d['pkg_fix'] ?? '', $d['package'] ?? ''))));
	$spinTxt = mb_strtolower((string) ($d['spinner_pkg'] ?? ''));
	$ms = array();
	if (preg_match('/szelfi|selfie|fotóautomata|fotoautomata|szelfigép/u', $txt) || preg_match('/\b(mini|medium|mega|carneval)\b.*(nyomtat|korlátlan)/u', $txt)) { $ms[] = 'selfie'; }
	if ($spinTxt !== '' || preg_match('/360|spinner/u', $txt)) { $ms[] = 'spin'; }
	$guess = false;
	/* csak a deal-névből: „[Crazyphoto] …” – a crazyphoto.hu főszolgáltatása a szelfigép */
	if (!$ms && preg_match('/^\[crazyphoto(\.hu)?\]/i', (string) ($d['title'] ?? '')) && trim((string) ($d['services_ec'] ?? '')) === '') { $ms[] = 'selfie'; $guess = true; }
	if (!$ms) { return null; }
	$hours = (int) ($d['hours'] ?? 0);
	$lines = array(); $tot = 0; $pk = 0; $approx = $guess; $bud = 0;
	foreach ($ms as $k) {
		$M = $P['m'][$k]; $src = $k === 'spin' && $spinTxt !== '' ? $spinTxt : $txt; $idx = null; $xh = 0;
		foreach ($M['pk'] as $i => $p) { if (preg_match('/\b' . preg_quote(mb_strtolower(strtok($p[0], ' ')), '/') . '\b/u', $src)) { $idx = $i; break; } }
		if ($idx === null && $hours > 0) { $idx = max(0, min(3, $hours - 3)); if ($hours > 6) { $xh = $hours - 6; } }
		$nopkg = $idx === null;
		if ($nopkg) { $idx = 0; $approx = true; }
		$p = $M['pk'][$idx];
		$lines[] = $M['n'] . ' – ' . $p[0] . ' (' . $p[1] . ' óra)' . ($nopkg ? ' – nincs megadva csomag, a legkisebbel számolva' : '') . ': ' . cp_est_ft($p[2]);
		$tot += $p[2]; $pk += $p[2]; $bud = max($bud, $p[3]);
		if ($xh) { $lines[] = 'Extra óra (' . $xh . ' × ' . cp_est_ft($M['xh']) . '): ' . cp_est_ft($xh * $M['xh']); $tot += $xh * $M['xh']; }
	}
	if (count($ms) === 2) { $disc = - (int) (round($pk * $P['both'] / 10) * 10); $lines[] = 'Csomagkedvezmény −15%: ' . cp_est_ft($disc); $tot += $disc; }
	$g = (int) ($d['guests'] ?? 0);
	if ($g > 1000) { $lines[] = '1000 fő felett egyedi ajánlat'; $approx = true; }
	elseif ($g > 150) { foreach ($P['guests'] as $b) { if ($g <= $b[0]) { $lines[] = 'Létszám-felár (' . $g . ' fő): ' . cp_est_ft($b[1]); $tot += $b[1]; break; } } }
	$km = (int) ($d['km_fee'] ?? 0); $venue = (string) ($d['venue'] ?? ''); $geo = null;
	if ($km > 0) { $lines[] = 'Kiszállás (a dealben megadva): ' . cp_est_ft($km); $tot += $km; }
	elseif (cp_est_is_bp($venue)) { $lines[] = 'Kiszállás Budapesten: ' . ($bud ? cp_est_ft($bud) : 'ingyenes'); $tot += $bud; }
	elseif ($venue !== '' && is_array($geo = cp_est_geo_get($venue)) && !empty($geo['bp'])) { $lines[] = 'Kiszállás Budapesten' . (!empty($geo['n']) ? ' (felismerve: ' . $geo['n'] . ')' : '') . ': ' . ($bud ? cp_est_ft($bud) : 'ingyenes'); $tot += $bud; }
	elseif ($venue !== '' && is_array($geo) && !empty($geo['km'])) { $kmv = (int) $geo['km'] * $P['trip']; $fee = $kmv * $P['km']; $lines[] = 'Kiszállás (' . (!empty($geo['n']) ? $geo['n'] . ', ' : '') . '~' . $kmv . ' km oda-vissza): ' . cp_est_ft($fee); $tot += $fee; }
	else { $lines[] = $venue === '' ? 'Kiszállás nélkül (nincs helyszín)' : (is_array($geo ?? null) ? 'Kiszállás nélkül (a helyszínt nem találtam a térképen – írd be a települést vagy irányítószámot)' : 'Kiszállás nélkül (a távolság számolása folyamatban, 10 percen belül frissül)'); $approx = true; }
	return array('p' => (int) $tot, 'a' => $approx ? 1 : 0, 'l' => $lines);
}
function cp_est_ft($n) { return ($n < 0 ? '−' : '') . number_format(abs((float) $n), 0, ',', ' ') . ' Ft'; }

/* a csempékhez: az első szakasz érték nélküli nyitott dealjei */
function cp_est_for_rows($rows) {
	if (!$rows || !function_exists('cp_crm_stages')) { return array(); }
	$st0 = cp_crm_stages()[0] ?? '';
	$ids = array(); foreach ($rows as $r) { if ($r['stage'] === $st0 && $r['status'] === 'Open' && !((float) $r['value'] > 0)) { $ids[] = (int) $r['id']; } }
	if (!$ids) { return array(); }
	global $wpdb;
	$out = array();
	foreach ((array) $wpdb->get_results('SELECT id, title, service, package, pkg_fix, spinner_pkg, services_cp, services_ec, hours, guests, venue, km_fee FROM ' . cp_crm_t('deals') . ' WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A) as $d) {
		$e = cp_est_deal($d); if ($e) { $out[(int) $d['id']] = $e; }
	}
	return $out;
}

/* egy helyszín feloldása: település (elütéstűrően) és útvonalhossz Budapest XI.-ből; a tick és a próba közös */
function cp_est_geocode($v) {
	$P = cp_est_prices(); $ua = array('User-Agent' => 'CrazyPanel/1.0 (eventcrazy.hu; info@eventcrazy.hu)', 'Accept-Language' => 'hu');
	/* irányítószám, ha van; különben a teljes szöveg, majd az első vesszőig tartó rész */
	$qs = array();
	if (preg_match('/(^|\D)([1-9]\d{3})(\D|$)/', $v, $m)) { $qs[] = array('postalcode' => $m[2], 'country' => 'hu'); }
	$qs[] = array('q' => $v, 'countrycodes' => 'hu'); $first = trim(explode(',', $v)[0]); if ($first !== $v) { $qs[] = array('q' => $first, 'countrycodes' => 'hu'); }
	$hit = null; $name = '';
	/* 1) irányítószám (pontos) – 2) Photon: elütéstűrő kereső, településre és helyszínnévre is („Szegd”, „Hotel Ozon, Matrahaza”) – 3) Nominatim */
	if (isset($qs[0]['postalcode'])) {
	$r = wp_remote_get(add_query_arg(array_merge(array('format' => 'jsonv2', 'limit' => 1, 'addressdetails' => 1), $qs[0]), 'https://nominatim.openstreetmap.org/search'), array('timeout' => 12, 'headers' => $ua));
	$j = is_wp_error($r) ? null : json_decode(wp_remote_retrieve_body($r), true);
		if (is_array($j) && !empty($j[0]['lat'])) { $hit = $j[0]; $a = (array) ($j[0]['address'] ?? array()); $name = (string) ($a['city'] ?? $a['town'] ?? $a['village'] ?? ''); }
		array_shift($qs);
	}
	if (!$hit) {
	$r = wp_remote_get(add_query_arg(array('q' => $v, 'limit' => 1, 'lat' => 47.2, 'lon' => 19.4, 'bbox' => '16.1,45.7,22.9,48.6'), 'https://photon.komoot.io/api/'), array('timeout' => 12, 'headers' => $ua));
	$j = is_wp_error($r) ? null : json_decode(wp_remote_retrieve_body($r), true);
	$f = $j['features'][0] ?? null;
		if ($f && strtoupper((string) ($f['properties']['countrycode'] ?? '')) === 'HU' && isset($f['geometry']['coordinates'][1])) {
		$hit = array('lat' => $f['geometry']['coordinates'][1], 'lon' => $f['geometry']['coordinates'][0]);
		$pp = $f['properties']; $name = (string) (in_array($pp['type'] ?? '', array('city', 'town', 'village', 'county', 'district', 'locality'), true) ? ($pp['name'] ?? '') : ($pp['city'] ?? $pp['name'] ?? ''));
		}
	}
	foreach ($hit ? array() : $qs as $i => $q) {
		sleep(2);
	$r = wp_remote_get(add_query_arg(array_merge(array('format' => 'jsonv2', 'limit' => 1, 'addressdetails' => 1), $q), 'https://nominatim.openstreetmap.org/search'), array('timeout' => 12, 'headers' => $ua));
	$j = is_wp_error($r) ? null : json_decode(wp_remote_retrieve_body($r), true);
		if (is_array($j) && !empty($j[0]['lat'])) { $hit = $j[0]; $a = (array) ($j[0]['address'] ?? array()); $name = (string) ($a['city'] ?? $a['town'] ?? $a['village'] ?? ''); break; }
	}
	if (!$hit) { return array('km' => 0, 't' => time()); }
	$name = sanitize_text_field(mb_substr($name, 0, 60));
	/* Budapestre esik (pl. „budapets”-ből javítva): a budapesti kiszállási díj jár, nem km-alapú */
	if (mb_strtolower($name) === 'budapest' || mb_strpos(mb_strtolower($name), 'budapest') === 0) { return array('km' => 0, 'bp' => 1, 'n' => 'Budapest', 't' => time()); }
	$o = $P['origin'];
	$r = wp_remote_get('https://router.project-osrm.org/route/v1/driving/' . $o[1] . ',' . $o[0] . ';' . (float) $hit['lon'] . ',' . (float) $hit['lat'] . '?overview=false', array('timeout' => 12, 'headers' => $ua));
	$j = is_wp_error($r) ? null : json_decode(wp_remote_retrieve_body($r), true);
	$m = isset($j['routes'][0]['distance']) ? (float) $j['routes'][0]['distance'] : 0;
	return array('km' => $m > 0 ? (int) round($m / 1000) : 0, 'n' => $name, 't' => time());
}

/* távolság a háttérben: 10 percenként legfeljebb 2 új helyszín (a Nominatim szabálya: max. 1 kérés / mp, azonosító fejléccel) */
add_action('cp_crm_tick', 'cp_est_geo_tick', 40);
function cp_est_geo_tick() {
	if (!function_exists('cp_crm_stages') || !function_exists('cp_crm_t')) { return; }
	global $wpdb;
	$st0 = cp_crm_stages()[0] ?? '';
	$vs = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT venue FROM ' . cp_crm_t('deals') . " WHERE status = 'Open' AND deleted_at IS NULL AND stage = %s AND venue <> '' AND (km_fee IS NULL OR km_fee = 0) LIMIT 60", $st0));
	$G = get_option('cp_est_geo', array()); if (!is_array($G)) { $G = array(); }
	$n = 0; $P = cp_est_prices(); $ua = array('User-Agent' => 'CrazyPanel/1.0 (eventcrazy.hu; info@eventcrazy.hu)', 'Accept-Language' => 'hu');
	foreach ($vs as $v) {
		if ($n >= 2) { break; }
		if (cp_est_is_bp($v)) { continue; }
		$k = md5(cp_est_norm_venue($v));
		/* a v1.82.1 előtti (elütést nem tűrő) sikertelen keresést azonnal újrapróbálja; egyébként a sikertelent naponta egyszer */
		if (isset($G[$k]) && (!empty($G[$k]['km']) || !empty($G[$k]['bp']) || (isset($G[$k]['n']) && (time() - (int) ($G[$k]['t'] ?? 0)) < DAY_IN_SECONDS))) { continue; }
		$n++;
		if ($n > 1) { sleep(2); }
		$G[$k] = cp_est_geocode($v);
	}
	if ($n) { if (count($G) > 400) { $G = array_slice($G, -300, null, true); } update_option('cp_est_geo', $G, false); }
}
