<?php
/* =====================================================================
 * v2.9.290: Dinamikus árazás (belső javaslat – az ügyfél nem látja)
 * Alapár: az adott hónapra (a rendezvény hónapja, pl. december) KIKÜLDÖTT ajánlatok értékének mediánja
 * márkánként (Crazyphoto / EventCrazy), szolgáltatásonként és óraszámonként. Kevés adatnál: + előző év ugyanazon
 * hónapja → a legközelebbi óraszám mediánja az árlista arányával átskálázva → árlista (jelölve).
 * Erre jön a napi igazítás: a hét napja (beállítható), az élő kapacitás (utolsó gépek: felár) és a last minute
 * (sok szabad gép, közeli nap: kedvezmény); korlátok és kerekítés. Beállítások → Dinamikus árazás.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_dp_defaults() {
	return array('on' => 1, 'wd1' => -10000, 'wd2' => -10000, 'wd3' => 0, 'wd4' => 10000, 'wd5' => 10000, 'wd6' => 0, 'wd7' => -10000,
		'last_thr' => 2, 'last_add' => 10000, 'lm_days' => 14, 'lm_free' => 5, 'lm_add' => -5000, 'min' => -15000, 'max' => 20000, 'step' => 5000, 'min_n' => 5, 'day_on' => 1, 'day_end' => 16, 'day_add' => -10000);
}
function cp_dp_opts() { $o = get_option('cp_dp', array()); return array_merge(cp_dp_defaults(), is_array($o) ? $o : array()); }
function cp_dp_on() { return !empty(cp_dp_opts()['on']); }

/* a böngészőnek (Kapacitás naptár címkéi) – csak a napi szabályok */
function cp_dp_cfg() {
	$o = cp_dp_opts(); if (empty($o['on'])) { return array('on' => 0); }
	$wd = array(); for ($i = 1; $i <= 7; $i++) { $wd[] = (int) $o['wd' . $i]; }
	return array('on' => 1, 'wd' => $wd, 'thr' => (float) $o['last_thr'], 'last' => (int) $o['last_add'], 'lmD' => (int) $o['lm_days'], 'lmF' => (float) $o['lm_free'], 'lm' => (int) $o['lm_add'], 'min' => (int) $o['min'], 'max' => (int) $o['max'], 'step' => max(1, (int) $o['step']));
}

/* szolgáltatás-kulcsok egy dealből (egyedi halmaz) */
function cp_dp_svc_key($v) {
	$v = mb_strtolower(remove_accents(trim((string) $v)));
	if ($v === '') { return null; }
	if (preg_match('/hibrid/', $v)) { return 'hibrid'; }
	if (preg_match('/karikatura/', $v)) { return 'aikarikatura'; }
	if (preg_match('/fotoautomata|szelfigep|photobooth/', $v)) { return 'szelfigep'; }
	if (preg_match('/overhead/', $v)) { return 'overhead'; }
	if (preg_match('/vip ?spinner/', $v)) { return 'vip'; }
	if (preg_match('/spinner|^360/', $v)) { return 'spinner'; }
	return function_exists('cp_est_ec_key') ? cp_est_ec_key($v) : null;
}
function cp_dp_keys($d) {
	$K = array();
	foreach (array('services_ec', 'service', 'services_cp') as $f) {
		foreach (preg_split('/\s*[|,&]\s*/u', (string) ($d[$f] ?? '')) as $p) { $k = cp_dp_svc_key($p); if ($k) { $K[$k] = 1; } }
	}
	return array_keys($K);
}
function cp_dp_brand($d) {
	$t = mb_strtolower((string) ($d['title'] ?? ''));
	return preg_match('/^\s*\[(eventcrazy|ec_)/u', $t) ? 'ec' : 'cp';
}
function cp_dp_svc_name($k) {
	$n = array('szelfigep' => 'Szelfigép', 'spinner' => '360 Spinner', 'aikarikatura' => 'AI karikatúra', 'hibrid' => 'Hibrid', 'overhead' => 'Overhead Spinner', 'vip' => 'VIP Spinner');
	if (isset($n[$k])) { return $n[$k]; }
	$P = function_exists('cp_est_ec_prices') ? cp_est_ec_prices() : array();
	return isset($P[$k]['n']) ? $P[$k]['n'] : $k;
}

/* árlista-érték (csak arányhoz és végső tartaléknak): EventCrazy-tábla, Crazyphoto-nál bruttó; szelfigép/spinner magánnál a Crazyphoto-csomagár */
function cp_dp_list($brand, $key, $h, $date) {
	$h = max(3, (int) $h);
	if ($brand === 'cp' && in_array($key, array('szelfigep', 'spinner'), true) && function_exists('cp_est_prices')) {
		$M = cp_est_prices()['m'][$key === 'spinner' ? 'spin' : 'selfie']; $pk = $M['pk']; $last = end($pk);
		foreach ($pk as $p) { if ((int) $p[1] === $h) { return (int) $p[2]; } }
		return (int) $last[2] + max(0, $h - (int) $last[1]) * (int) $M['xh'];
	}
	if (!function_exists('cp_est_ec_prices')) { return 0; }
	$P = cp_est_ec_prices(); $k = $key === 'szelfigep' ? 'szelfigep' : $key; if (!isset($P[$k])) { return 0; }
	$n = ''; $col = cp_est_ec_col($date, $n); $M = $P[$k];
	if (!empty($M['flat'])) { $p = $M['flat'][$col]; }
	else { $t = $M['t']; $p = $h <= 10 ? $t[$h][$col] : $t[10][$col] + ($h - 10) * ($t[10][$col] - $t[9][$col]); }
	return (int) round($p * ($brand === 'cp' ? 1.27 : 1));
}

/* egy hónap (YYYY-MM) mintái: [brand][key][h] => [év => értékek] – kiküldött ajánlatok (nyert, elveszett, foglalt, ajánlat kiküldve) */
function cp_dp_samples($ym) {
	$ck = 'cp_dp_s_' . $ym; $c = get_transient($ck); if (is_array($c)) { return $c; }
	global $wpdb; $y = (int) substr($ym, 0, 4); $m = substr($ym, 5, 2); $o = cp_panel_opts();
	$first = function_exists('cp_crm_stages') ? mb_strtolower((string) (cp_crm_stages()[0] ?? '')) : '';
	$offer = array_values(array_diff(array_map('mb_strtolower', array_merge(cp_panel_lines($o['booked_stages']), cp_panel_lines($o['pending_stages']))), array($first)));
	$rows = $wpdb->get_results($wpdb->prepare('SELECT id, title, stage, status, value, hours, service, services_ec, services_cp, event_d FROM ' . cp_crm_t('deals') . ' WHERE deleted_at IS NULL AND value > 0 AND hours > 0 AND (event_d BETWEEN %s AND %s OR event_d BETWEEN %s AND %s)',
		$y . '-' . $m . '-01', $y . '-' . $m . '-31', ($y - 1) . '-' . $m . '-01', ($y - 1) . '-' . $m . '-31'), ARRAY_A);
	$S = array();
	foreach ((array) $rows as $r) {
		$ok = $r['status'] === 'Won' || $r['status'] === 'Lost' || in_array(mb_strtolower((string) $r['stage']), $offer, true);
		if (!$ok) { continue; }
		$K = cp_dp_keys($r); if (count($K) !== 1) { continue; } /* csak egyszolgáltatásos ajánlat */
		$S[cp_dp_brand($r)][$K[0]][(int) $r['hours']][(int) substr($r['event_d'], 0, 4)][] = (int) round((float) $r['value']);
	}
	/* kiugró értékek (több gép, egyedi csomag) ki: a csoport mediánjának 0,4–2,5-szörösén kívül */
	foreach ($S as $b => $X) { foreach ($X as $k => $H) { foreach ($H as $h => $Y) {
		$all = array_merge(...array_values($Y)); $md = cp_dp_median($all);
		foreach ($Y as $yy => $v) { $S[$b][$k][$h][$yy] = array_values(array_filter($v, function ($x) use ($md) { return $x >= $md * 0.4 && $x <= $md * 2.5; })); }
	} } }
	set_transient($ck, $S, 6 * HOUR_IN_SECONDS);
	return $S;
}
function cp_dp_median($a) { $a = array_values($a); if (!$a) { return 0; } sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; }

/* alapár egy szolgáltatásra: array(p, forrás-szöveg, megbízható?) */
function cp_dp_base($brand, $key, $h, $date) {
	$ym = substr($date, 0, 7); $y = (int) substr($date, 0, 4); $o = cp_dp_opts(); $mn = max(1, (int) $o['min_n']);
	$hu = array('01' => 'januári', '02' => 'februári', '03' => 'márciusi', '04' => 'áprilisi', '05' => 'májusi', '06' => 'júniusi', '07' => 'júliusi', '08' => 'augusztusi', '09' => 'szeptemberi', '10' => 'októberi', '11' => 'novemberi', '12' => 'decemberi')[substr($ym, 5, 2)];
	$h = max(1, (int) $h); $G = cp_dp_samples($ym)[$brand][$key] ?? array();
	$cur = $G[$h][$y] ?? array(); $prev = $G[$h][$y - 1] ?? array();
	if (count($cur) >= $mn) { return array((int) cp_dp_median($cur), $y . '. ' . $hu . ' ajánlatok mediánja (' . count($cur) . ' db, ' . $h . ' óra)', 1); }
	if (count($cur) + count($prev) >= 3) { return array((int) cp_dp_median(array_merge($cur, $prev)), $hu . ' ajánlatok mediánja ' . ($y - 1) . '–' . $y . ' (' . (count($cur) + count($prev)) . ' db, ' . $h . ' óra)', 1); }
	/* a másik márka ugyanilyen ajánlatai (az ajánlati értékek a két márkánál hasonló szinten mozognak) */
	$ob = $brand === 'ec' ? 'cp' : 'ec'; $OG = cp_dp_samples($ym)[$ob][$key][$h] ?? array(); $oall = array_merge($OG[$y] ?? array(), $OG[$y - 1] ?? array());
	if (count($oall) >= 3) { return array((int) cp_dp_median($oall), $hu . ' ' . ($ob === 'ec' ? 'EventCrazy' : 'Crazyphoto') . '-ajánlatok mediánja (' . count($oall) . ' db, ' . $h . ' óra) – a saját márkánál kevés adat', 0); }
	/* a legközelebbi, elég mintás óraszám, az árlista arányával */
	$best = null;
	foreach ($G as $hh => $Y) { $all = array_merge(...array_values($Y ?: array(array()))); if (count($all) >= 3 && ($best === null || abs($hh - $h) < abs($best[0] - $h))) { $best = array($hh, $all); } }
	$L = cp_dp_list($brand, $key, $h, $date);
	if ($best) { $Lb = cp_dp_list($brand, $key, $best[0], $date); if ($Lb > 0 && $L > 0) { return array((int) round(cp_dp_median($best[1]) * $L / $Lb / 1000) * 1000, $hu . ' ajánlatok (' . $best[0] . ' óra, ' . count($best[1]) . ' db) átszámolva ' . $h . ' órára – kevés adat', 0); } }
	return array($L, $L ? 'árlista (kevés ' . $hu . ' ajánlat)' : 'nincs adat', 0);
}

/* napi igazítás: array(összeg, sorok[], szint, szabad) – $kind: selfie | spin; $self: a deal maga függőként számít-e */
/* v2.9.301: $endm – a rendezvény vége percben (kezdés + óraszám), ha ismert. Nappali rendezvény (legkésőbb day_end órakor
   véget ér): csúcsnapon is kedvezményes – a hét napja és az „utolsó gépek” felár helyett a nappali kedvezmény (a felhasználó döntése) */
function cp_dp_day($date, $kind = 'selfie', $self = false, $endm = null) {
	$o = cp_dp_opts(); $L = array(); $a = 0;
	$dow = (int) wp_date('N', strtotime($date . ' 12:00:00'));
	$nm = array(1 => 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap')[$dow];
	$dayev = !empty($o['day_on']) && $endm !== null && $endm <= (int) $o['day_end'] * 60;
	if ($dayev) { $L[] = array('Nappali rendezvény (' . sprintf('%d:%02d', intdiv($endm, 60), $endm % 60) . '-ig véget ér) – csúcsnapon is kedvezményes', (int) $o['day_add']); $a += (int) $o['day_add']; }
	else { $w = (int) $o['wd' . $dow]; if ($w) { $L[] = array(ucfirst($nm) . ($w < 0 ? ' (gyengébb nap)' : ' (erős nap)'), $w); $a += $w; } }
	$k = function_exists('cp_cap_day') ? cp_cap_day($date) : null; $free = null; $lvl = 'ok';
	if ($k) {
		$free = $kind === 'spin' ? $k['fp'] : $k['fs']; $pend = ($kind === 'spin' ? $k['pp'] : $k['ps']) - ($self ? 1 : 0);
		$eff = $free - 0.5 * max(0, $pend);
		if ($free <= 0) { $lvl = 'full'; $L[] = array('Betelt nap – alvállalkozó vagy másik nap', 0); }
		elseif (!$dayev && $eff <= ($kind === 'spin' ? min(1.0, (float) $o['last_thr']) : (float) $o['last_thr'])) { /* spinnerből csak 2 van: ott az utolsó gép számít */ $lvl = 'last'; $L[] = array('Utolsó gépek (' . $free . ' szabad, ' . max(0, $pend) . ' függő ajánlat)', (int) $o['last_add']); $a += (int) $o['last_add']; }
		$days = (int) floor((strtotime($date) - strtotime(wp_date('Y-m-d'))) / 86400);
		if ($lvl === 'ok' && $days >= 0 && $days < (int) $o['lm_days'] && $eff >= (float) $o['lm_free']) { $L[] = array('Last minute (' . $days . ' nap múlva, ' . $free . ' szabad gép)', (int) $o['lm_add']); $a += (int) $o['lm_add']; }
	}
	$a = max((int) $o['min'], min((int) $o['max'], $a));
	return array($a, $L, $lvl, $free);
}

/* teljes javaslat egy dealre (vagy az Új deal ablak adataira) */
function cp_dp_quote($d) {
	if (!cp_dp_on()) { return null; }
	$date = (string) ($d['event_d'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return null; }
	$K = cp_dp_keys($d); if (!$K) { return null; }
	$brand = cp_dp_brand($d); $h = (int) ($d['hours'] ?? 0); $noh = $h <= 0; if ($noh) { $h = 3; }
	$base = 0; $lines = array(); $sure = 1;
	foreach ($K as $k) { list($p, $src, $ok) = cp_dp_base($brand, $k, $h, $date); $base += $p; $sure = $sure && $ok; $lines[] = array(cp_dp_svc_name($k) . ' – ' . $src, $p); }
	if (count($K) > 1) { $lines[] = array('Több szolgáltatás: összeadva, csomagkedvezmény nélkül', 0); }
	$kind = count(array_intersect($K, array('spinner', 'overhead', 'vip'))) === count($K) ? 'spin' : 'selfie';
	$self = ($d['status'] ?? '') === 'Open' && function_exists('cp_crm_stages') && in_array($d['stage'] ?? '', array_slice(cp_crm_stages(), 0, 2), true);
	/* a rendezvény vége: kezdés (start_at helyi idő, vagy az Új deal ablak start_hm-je) + óraszám */
	$endm = null; $hm = '';
	if (!empty($d['start_hm']) && preg_match('/^(\d{1,2}):(\d{2})$/', (string) $d['start_hm'], $mm)) { $hm = $mm[0]; }
	elseif (!empty($d['start_at']) && preg_match('/\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})/', (string) $d['start_at'], $mm)) { $hm = $mm[1] . ':' . $mm[2]; }
	if ($hm !== '' && !$noh) { list($hh, $mi) = array_map('intval', explode(':', $hm)); $endm = $hh * 60 + $mi + $h * 60; }
	list($adj, $AL, $lvl, $free) = cp_dp_day($date, $kind, $self, $endm);
	$step = max(1, (int) cp_dp_opts()['step']);
	$p = (int) (round(($base + $adj) / $step) * $step);
	return array('p' => $p, 'base' => (int) $base, 'adj' => $adj, 'lines' => $lines, 'day' => $AL, 'lvl' => $lvl, 'free' => $free, 'sure' => $sure ? 1 : 0, 'noh' => $noh ? 1 : 0, 'h' => $h,
		'vat' => $brand === 'ec' ? 'nettó (+ ÁFA)' : 'bruttó', 'brand' => $brand === 'ec' ? 'EventCrazy' : 'Crazyphoto');
}

/* Új deal ablak: javaslat a beírt adatokra */
add_action('wp_ajax_nopriv_cp_dp_quote', 'cp_dp_ajax_quote'); add_action('wp_ajax_cp_dp_quote', 'cp_dp_ajax_quote');
function cp_dp_ajax_quote() {
	if (function_exists('cp_crm_ajax_guard')) { cp_crm_ajax_guard(array('deals')); } else { cp_el_auth(); }
	$g = function ($k, $n = 200) { return mb_substr(sanitize_text_field(wp_unslash((string) ($_POST[$k] ?? ''))), 0, $n); };
	$brand = $g('brand', 20) === 'ec' ? '[EventCrazy]' : '[Crazyphoto]';
	$q = cp_dp_quote(array('event_d' => $g('date', 10), 'hours' => (int) ($_POST['hours'] ?? 0), 'start_hm' => $g('start', 5), 'service' => $g('service', 300), 'title' => $brand, 'status' => 'Open', 'stage' => function_exists('cp_crm_stages') ? (cp_crm_stages()[0] ?? '') : ''));
	wp_send_json_success(array('q' => $q));
}

/* Beállítások → Dinamikus árazás */
add_filter('cp_sx_sections', function ($S) {
	$f = function ($k, $l, $g, $h = '') { return array('k' => $k, 't' => 'num', 'l' => $l, 'g' => $g, 'h' => $h, 'opt' => 'cp_dp', 'sub' => $k, 'def' => cp_dp_defaults()[$k], 'int' => 1); };
	$S['dp'] = array('n' => 'Dinamikus árazás', 'wp' => '',
		'd' => 'Belső árjavaslat (az ügyfél nem látja): alapár = az adott hónapra kiküldött ajánlatok mediánja (márkánként, szolgáltatásonként, óraszámonként), erre jön a napi igazítás. Az összegek forintban; negatív = kedvezmény.',
		'info' => function () {
			$out = array(); $ym = wp_date('Y') . '-12';
			foreach (array('cp' => 'Crazyphoto', 'ec' => 'EventCrazy') as $b => $bn) {
				$G = cp_dp_samples($ym)[$b]['szelfigep'] ?? array(); ksort($G); $t = array();
				foreach ($G as $h => $Y) { $c = $Y[(int) wp_date('Y')] ?? array(); if ($c) { $t[] = $h . ' ó: ' . number_format(cp_dp_median($c), 0, ',', ' ') . ' (' . count($c) . ')'; } }
				$out[] = array($bn . ' szelfigép – decemberi alapárak', $t ? implode(' · ', $t) : 'még kevés adat', $t ? 'ok' : '');
			}
			return $out;
		},
		'fields' => array(
			array('k' => 'on', 't' => 'sw', 'l' => 'Dinamikus árjavaslat bekapcsolva', 'g' => 'Általános', 'opt' => 'cp_dp', 'sub' => 'on', 'def' => 1),
			$f('wd1', 'Hétfő', 'A hét napjai'), $f('wd2', 'Kedd', 'A hét napjai'), $f('wd3', 'Szerda', 'A hét napjai'), $f('wd4', 'Csütörtök', 'A hét napjai'), $f('wd5', 'Péntek', 'A hét napjai'), $f('wd6', 'Szombat', 'A hét napjai'), $f('wd7', 'Vasárnap', 'A hét napjai'),
			$f('last_thr', 'Utolsó gépek: ennyi vagy kevesebb szabad gép', 'Kapacitás', 'a függő ajánlatok fél gépnek számítanak'), $f('last_add', 'Utolsó gépek felára', 'Kapacitás'),
			$f('lm_days', 'Last minute: ennyi napon belül', 'Kapacitás'), $f('lm_free', 'Last minute: legalább ennyi szabad gép', 'Kapacitás'), $f('lm_add', 'Last minute igazítás', 'Kapacitás'),
			array('k' => 'day_on', 't' => 'sw', 'l' => 'Nappali rendezvény kedvezmény (csúcsnapon is)', 'g' => 'Nappali rendezvény', 'opt' => 'cp_dp', 'sub' => 'day_on', 'def' => 1),
			$f('day_end', 'Nappalinak számít, ha legkésőbb ennyi órakor véget ér', 'Nappali rendezvény', 'kezdés + óraszám; pl. 16 = 16:00'), $f('day_add', 'Nappali kedvezmény (negatív)', 'Nappali rendezvény'),
			$f('min', 'Legnagyobb kedvezmény (negatív)', 'Korlátok'), $f('max', 'Legnagyobb felár', 'Korlátok'), $f('step', 'Kerekítés (Ft)', 'Korlátok'), $f('min_n', 'Legalább ennyi idei ajánlat kell az alapárhoz', 'Korlátok', 'kevesebbnél az előző év ugyanazon hónapja is számít'),
		),
		'after' => function () { global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_cp\\_dp\\_s\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cp\\_dp\\_s\\_%'"); },
	);
	return $S;
});
