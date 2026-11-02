<?php
/* =====================================================================
 * v1.91: becsült ár az EventCrazy-s ajánlatkérésekre (EventCrazy árlista 2026 – nettó, + ÁFA).
 * Óradíjas táblák 3–10 órára, négy oszlop: off-szezon H–Cs, off-szezon P–V, szezon H–Cs, szezon P–V.
 * Szezon: május–szeptember; off: január–április, október, november; december az árlistában nincs – szezonárral, közelítőként.
 * Kiszállás Budapesten: 360 / Overhead Spinner 10 000, VIP Spinner 20 000, minden más 5 000, 3 szolgáltatásnál összesen 15 000 Ft;
 * vidéken 130 Ft/km (oda-vissza, ahogy a Crazyphoto-becslésnél). Árváltozáskor itt kell frissíteni.
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }

function cp_est_ec_prices() {
	$t = function ($rows) { $o = array(); $h = 3; foreach ($rows as $r) { $o[$h++] = $r; } return $o; };
	return array(
		'szelfigep' => array('n' => 'Szelfigép', 'bp' => 5000, 't' => $t(array(array(120000, 130000, 130000, 140000), array(135000, 140000, 145000, 155000), array(150000, 160000, 165000, 170000), array(165000, 175000, 185000, 195000), array(180000, 190000, 202500, 210000), array(195000, 205000, 221000, 228000), array(210000, 225000, 239500, 246000), array(225000, 240000, 258000, 264000)))),
		'greenbox' => array('n' => 'Greenbox', 'bp' => 5000, 't' => $t(array(array(150000, 170000, 170000, 190000), array(165000, 180000, 175000, 205000), array(180000, 200000, 195000, 220000), array(195000, 215000, 225000, 245000), array(220000, 230000, 242500, 260000), array(225000, 245000, 261000, 278000), array(240000, 265000, 279500, 296000), array(255000, 280000, 298000, 314000)))),
		'aifoto' => array('n' => 'AI fotózás', 'bp' => 5000, 't' => $t(array(array(155000, 175000, 175000, 195000), array(170000, 185000, 190000, 210000), array(185000, 205000, 210000, 225000), array(200000, 220000, 230000, 250000), array(215000, 235000, 247500, 265000), array(230000, 250000, 266000, 283000), array(245000, 270000, 284500, 301000), array(260000, 285000, 303000, 319000)))),
		'aikarikatura' => array('n' => 'AI karikatúra', 'bp' => 5000, 't' => $t(array(array(160000, 180000, 180000, 200000), array(175000, 190000, 195000, 215000), array(190000, 210000, 215000, 230000), array(205000, 225000, 235000, 255000), array(220000, 240000, 252500, 270000), array(235000, 255000, 271000, 288000), array(250000, 275000, 289500, 306000), array(265000, 290000, 308000, 324000)))),
		/* a 6 órás szezon P–V ár az árlistában elírt („2055 000 Ft”) – 205 000 Ft-tal számolva */
		'tukor' => array('n' => 'Szelfi tükör', 'bp' => 5000, 't' => $t(array(array(130000, 145000, 145000, 160000), array(145000, 155000, 160000, 175000), array(160000, 175000, 180000, 190000), array(175000, 190000, 200000, 205000), array(190000, 205000, 222500, 230000), array(205000, 220000, 236000, 248000), array(220000, 240000, 254500, 266000), array(235000, 255000, 273000, 284000)))),
		'robot' => array('n' => 'Szelfirobot', 'bp' => 5000, 't' => $t(array(array(190000, 210000, 210000, 230000), array(205000, 220000, 225000, 245000), array(220000, 240000, 245000, 260000), array(235000, 255000, 265000, 285000), array(250000, 270000, 282500, 300000), array(265000, 285000, 301000, 318000), array(280000, 305000, 319500, 336000), array(295000, 320000, 338000, 354000)))),
		'vr' => array('n' => 'VR élmény', 'bp' => 5000, 't' => $t(array(array(130000, 140000, 140000, 145000), array(145000, 155000, 155000, 160000), array(165000, 170000, 170000, 175000), array(185000, 195000, 195000, 190000), array(202500, 210000, 210000, 220000), array(221000, 228000, 228000, 235000), array(239500, 246000, 235000, 240000), array(258000, 264000, 250000, 260000)))),
		'vrcockpit' => array('n' => 'VR cockpit szimulátor', 'bp' => 5000, 't' => $t(array(array(155000, 180000, 170000, 195000), array(170000, 195000, 185000, 210000), array(190000, 210000, 200000, 225000), array(210000, 235000, 225000, 240000), array(227500, 250000, 240000, 270000), array(246000, 268000, 258000, 285000), array(264500, 286000, 265000, 290000), array(283000, 304000, 280000, 310000)))),
		'spinner' => array('n' => '360 Spinner', 'bp' => 10000, 't' => $t(array(array(150000, 165000, 170000, 180000), array(165000, 180000, 180000, 190000), array(180000, 195000, 195000, 210000), array(195000, 210000, 215000, 230000), array(210000, 225000, 225000, 240000), array(225000, 240000, 240000, 258000), array(240000, 255000, 255500, 274000), array(255000, 270000, 270000, 290000)))),
		'overhead' => array('n' => 'Overhead Spinner', 'bp' => 10000, 't' => $t(array(array(165000, 185000, 185000, 200000), array(180000, 200000, 195000, 210000), array(195000, 215000, 210000, 230000), array(210000, 230000, 230000, 250000), array(225000, 245000, 240000, 260000), array(240000, 260000, 265000, 278000), array(255000, 275000, 270500, 294000), array(270000, 290000, 295000, 310000)))),
		'vip' => array('n' => 'VIP Spinner', 'bp' => 20000, 'flat' => array(400000, 500000, 450000, 550000)),
		'catchit' => array('n' => 'CrazyCatch (Catch it)', 'bp' => 5000, 't' => $t(array(array(120000, 135000, 135000, 145000), array(130000, 145000, 145000, 155000), array(140000, 155000, 155000, 165000), array(150000, 165000, 165000, 175000), array(160000, 175000, 175000, 185000), array(170000, 185000, 185000, 195000), array(180000, 195000, 195000, 205000), array(190000, 205000, 205000, 215000)))),
		'dancefloor' => array('n' => 'LED tánctér (Dancefloor)', 'bp' => 5000, 'feltetelezes' => 'a „Dancefloor” árával számolva', 't' => $t(array(array(250000, 260000, 260000, 275000), array(265000, 280000, 280000, 285000), array(280000, 300000, 300000, 295000), array(295000, 320000, 320000, 305000), array(310000, 340000, 340000, 315000), array(325000, 360000, 360000, 325000), array(340000, 380000, 380000, 335000), array(355000, 400000, 400000, 345000)))),
	);
}
/* a deal EventCrazy-szolgáltatásmezőjének értékei → árlista-kulcs (null: egyedi árazású / nincs az árlistában) */
function cp_est_ec_key($v) {
	$v = mb_strtolower(remove_accents(trim((string) $v)));
	$map = array('/^szelfigep|^photobooth/' => 'szelfigep', '/greenbox/' => 'greenbox', '/^ai foto/' => 'aifoto', '/^ai karikatura/' => 'aikarikatura', '/tukor|mirror/' => 'tukor', '/robot|droid/' => 'robot',
		'/cockpit/' => 'vrcockpit', '/^vr/' => 'vr', '/vip ?spinner/' => 'vip', '/overhead/' => 'overhead', '/spinnerclassic|^360|^spinner/' => 'spinner', '/crazycatch|catch ?it/' => 'catchit', '/led tanc|dancefloor|tancter/' => 'dancefloor');
	foreach ($map as $re => $k) { if (preg_match($re, $v)) { return $k; } }
	return null;
}
/* szezon / nap oszlop a rendezvény dátumából: 0 off H–Cs, 1 off P–V, 2 szezon H–Cs, 3 szezon P–V */
function cp_est_ec_col($date, &$note) {
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) { $note = 'nincs rendezvénydátum – szezonális hétvégi árral számolva'; return 3; }
	$ts = strtotime($date . ' 12:00:00'); $m = (int) gmdate('n', $ts); $dow = (int) gmdate('N', $ts);
	$season = $m >= 5 && $m <= 9; if ($m === 12) { $season = true; $note = 'december az árlistában nincs – szezonális árral számolva'; }
	return ($season ? 2 : 0) + ($dow >= 5 ? 1 : 0);
}
/* az árlistás tételek sorai és összege (kiszállás nélkül); $mult = 1.27: bruttó (magánszemélynek) */
function cp_est_ec_items($keys, $d, $mult, &$approx, &$bud) {
	$P = cp_est_ec_prices(); $note = ''; $col = cp_est_ec_col((string) ($d['event_d'] ?? ''), $note);
	$colN = array('off-szezon, hétköznap', 'off-szezon, hétvége', 'szezon, hétköznap', 'szezon, hétvége')[$col];
	$h = (int) ($d['hours'] ?? 0); $lines = array(); $tot = 0; if ($note !== '') { $approx = true; }
	if ($h <= 0) { $h = 3; $approx = true; $hn = ' – nincs megadva óraszám, 3 órával számolva'; } else { $hn = ''; }
	$br = $mult > 1 ? ', bruttó' : '';
	foreach ($keys as $k => $orig) {
		$M = $P[$k];
		if (!empty($M['flat'])) { $p = $M['flat'][$col]; $lab = $M['n'] . ' (' . $colN . $br . ')'; }
		else {
			$hh = max(3, $h); $t = $M['t'];
			if ($hh <= 10) { $p = $t[$hh][$col]; } else { $p = $t[10][$col] + ($hh - 10) * ($t[10][$col] - $t[9][$col]); $approx = true; }
			$lab = $M['n'] . ' – ' . $hh . ' óra (' . $colN . $br . ')' . ($h < 3 ? ' – legalább 3 óra' : '') . $hn . (!empty($M['feltetelezes']) ? ' – ' . $M['feltetelezes'] : '');
			if (!empty($M['feltetelezes'])) { $approx = true; }
		}
		$p = (int) round($p * $mult / 10) * 10;
		$lines[] = $lab . ': ' . cp_est_ft($p); $tot += $p; $bud += $M['bp'];
	}
	if ($note !== '') { $lines[] = 'Megjegyzés: ' . $note; }
	return array($tot, $lines);
}
function cp_est_ec_deal($d, $private = false) {
	$P = cp_est_ec_prices(); $T = cp_est_prices();
	$vals = array_values(array_unique(array_filter(array_map('trim', explode('|', (string) ($d['services_ec'] ?? ''))))));
	$keys = array(); $custom = array();
	foreach ($vals as $v) { $k = cp_est_ec_key($v); if ($k && isset($P[$k])) { $keys[$k] = $v; } elseif (!preg_match('/visszahiv|egyeb/i', remove_accents($v))) { $custom[] = $v; } }
	if (!$keys) { return null; }
	$approx = false; $bud = 0; $lines = array(); $tot = 0;
	/* magánszemély (ingyenes e-mail-cím): a Crazyphoto-kínálatban is meglévő szolgáltatás (szelfigép, 360 spinner) Crazyphoto-áron, a többi EventCrazy-áron bruttóban */
	if ($private) {
		$cpk = array_intersect_key($keys, array('szelfigep' => 1, 'spinner' => 1)); $rest = array_diff_key($keys, $cpk);
		$lines[] = 'Magánszemély (ingyenes e-mail-cím) – Crazyphoto-árak, bruttó';
		if ($cpk) {
			$c = $d; $c['services_ec'] = ''; $c['package'] = $c['pkg_fix'] = $c['services_cp'] = ''; $c['spinner_pkg'] = '';
			$c['service'] = implode(' | ', array_map(function ($k) { return $k === 'spinner' ? '360 spinner' : 'szelfigép'; }, array_keys($cpk)));
			$c['title'] = '';
			$R = cp_est_deal($c);
			if ($R) { $tot += $R['p']; $approx = $approx || !empty($R['a']); foreach ($R['l'] as $l) { $lines[] = $l; } }
		}
		if ($rest) {
			list($t2, $l2) = cp_est_ec_items($rest, $d, 1.27, $approx, $bud); $tot += $t2;
			$lines[] = 'Csak EventCrazy-kínálatban (EventCrazy-ár + 27% ÁFA):'; foreach ($l2 as $l) { $lines[] = $l; }
			if (!$cpk) {
				$venue = (string) ($d['venue'] ?? ''); $geo = null;
				if (cp_est_is_bp($venue) || ($venue !== '' && is_array($geo = cp_est_geo_get($venue)) && !empty($geo['bp']))) { $f = (int) round($bud * 1.27 / 10) * 10; $lines[] = 'Kiszállás Budapesten (bruttó): ' . cp_est_ft($f); $tot += $f; }
				elseif ($venue !== '' && is_array($geo) && !empty($geo['km'])) { $kmv = (int) $geo['km'] * $T['trip']; $f = (int) round($kmv * $T['km'] * 1.27 / 10) * 10; $lines[] = 'Kiszállás (' . (!empty($geo['n']) ? $geo['n'] . ', ' : '') . '~' . $kmv . ' km oda-vissza, bruttó): ' . cp_est_ft($f); $tot += $f; }
				else { $lines[] = 'Kiszállás nélkül'; $approx = true; }
			}
		}
		foreach ($custom as $cc) { $lines[] = $cc . ': egyedi ár (nincs az árlistában)'; $approx = true; }
		return array('p' => (int) $tot, 'a' => $approx ? 1 : 0, 'l' => $lines, 'v' => $rest ? 'pb' : 'p');
	}
	list($tot, $lines) = cp_est_ec_items($keys, $d, 1, $approx, $bud);
	if (count($keys) + count($custom) >= 3) { $bud = 15000; }
	foreach ($custom as $c) { $lines[] = $c . ': egyedi ár (nincs az árlistában)'; $approx = true; }
	$km = (int) ($d['km_fee'] ?? 0); $venue = (string) ($d['venue'] ?? ''); $geo = null;
	if ($km > 0) { $lines[] = 'Kiszállás (a dealben megadva): ' . cp_est_ft($km); $tot += $km; }
	elseif (cp_est_is_bp($venue) || ($venue !== '' && is_array($geo = cp_est_geo_get($venue)) && !empty($geo['bp']))) { $lines[] = 'Kiszállás Budapesten: ' . cp_est_ft($bud); $tot += $bud; }
	elseif ($venue !== '' && is_array($geo) && !empty($geo['km'])) { $kmv = (int) $geo['km'] * $T['trip']; $fee = $kmv * $T['km']; $lines[] = 'Kiszállás (' . (!empty($geo['n']) ? $geo['n'] . ', ' : '') . '~' . $kmv . ' km oda-vissza × 130 Ft): ' . cp_est_ft($fee); $tot += $fee; }
	else { $lines[] = $venue === '' ? 'Kiszállás nélkül (nincs helyszín)' : (is_array($geo) ? 'Kiszállás nélkül (a helyszínt nem találtam a térképen)' : 'Kiszállás nélkül (a távolság számolása folyamatban, 10 percen belül frissül)'); $approx = true; }
	$lines[] = 'Az árak nettó árak, az ÁFA-t nem tartalmazzák.';
	return array('p' => (int) $tot, 'a' => $approx ? 1 : 0, 'l' => $lines, 'v' => 'ec');
}
