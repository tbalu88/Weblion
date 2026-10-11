<?php
/* =====================================================================
 * STATISZTIKÁK – NYOMTATÁS, ÜGYFÉL-ELÉGEDETTSÉG, GÉPÁLLAPOT (v1.19)
 * Forrás: az Elszámolás fül kezelői sorai (cp_eld_{deal}): pa = papírfogyasztás (db), pt = nyomtató,
 * sa = ügyfél-elégedettség, gm = gép működése (+ gmn megjegyzés), sv = eszköz, h = óraszám, no = megjegyzés.
 * Időszak: a rendezvény napja szerint, csak lezajlott rendezvények.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

/* $rows: cp_st_load() kimenete (deal adatok cím, szolgáltatás, géptípus miatt) */
function cp_st_ops($rows, $f, $t) {
	global $wpdb;
	$today = cp_st_today();
	if ($t > $today) { $t = $today; }
	$titles = $wpdb->get_results('SELECT id, title FROM ' . cp_st_t('deals'), OBJECT_K);
	$by = array();
	foreach ($rows as $r) { $by[(string) $r['id']] = $r; }
	$o = array(
		'print' => array('total' => 0, 'events' => 0, 'hours' => 0, 'printers' => array(), 'svc' => array(), 'dev' => array(), 'top' => array(), 'photo' => 0, 'cost' => 0),
		'sat'   => array('counts' => array(), 'rated' => 0, 'events' => 0, 'ratedEvents' => 0, 'bad' => array()),
		'gm'    => array('counts' => array(), 'bad' => array()),
		'since' => array('print' => '', 'sat' => '', 'gm' => ''),
	);
	if ($f > $t) { return $o; }
	$opt = cp_st_opts();
	$paper = (int) $opt['paper_ft'];
	$evs = array();
	/* a fotós (nyomtatós) rendezvények száma a lefedettséghez */
	foreach ($rows as $r) {
		if (!cp_st_in($r['event_d'], $f, $t) || (function_exists('cp_st_dg_booked') ? !cp_st_dg_booked($r) : empty($r['signed_d']))) { continue; }
		$evs[(string) $r['id']] = 1;
		if (in_array($r['kind'], array('selfie', 'both'), true)) { $o['print']['photo']++; }
	}
	$o['sat']['events'] = count($evs);
	foreach (cp_el_st_all() as $pair) {
		list($id, $st) = $pair;
		if (empty($st['r']) || !is_array($st['r'])) { continue; }
		$id = (string) $id;
		$d = isset($by[$id]) && !empty($by[$id]['event_d']) ? $by[$id]['event_d'] : (string) (isset($st['d']) ? $st['d'] : '');
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d > $today) { continue; }
		/* a mérés kezdete (minden időszakra) */
		foreach ($st['r'] as $row) {
			if (!is_array($row)) { continue; }
			if (isset($row['pa']) && $row['pa'] !== '' && ($o['since']['print'] === '' || $d < $o['since']['print'])) { $o['since']['print'] = $d; }
			if (!empty($row['sa']) && ($o['since']['sat'] === '' || $d < $o['since']['sat'])) { $o['since']['sat'] = $d; }
			if (!empty($row['gm']) && ($o['since']['gm'] === '' || $d < $o['since']['gm'])) { $o['since']['gm'] = $d; }
		}
		if ($d < $f || $d > $t) { continue; }
		$title = isset($titles[$id]) ? (string) $titles[$id]->title : ('Deal #' . $id);
		$svc = isset($by[$id]) ? ($by[$id]['svc'] !== '' ? $by[$id]['svc'] : (!empty($by[$id]['svcs']) ? $by[$id]['svcs'][0] : 'nincs megadva')) : 'nincs megadva';
		$ev_pa = 0; $ev_has = false; $ev_rated = false;
		foreach ($st['r'] as $row) {
			if (!is_array($row)) { continue; }
			if (isset($row['pa']) && $row['pa'] !== '') {
				$n = (int) $row['pa'];
				$ev_has = true;
				$ev_pa += $n;
				$o['print']['hours'] += isset($row['h']) && $row['h'] !== '' ? (float) $row['h'] : 0;
				$pt = !empty($row['pt']) ? (string) $row['pt'] : 'nincs megadva';
				$o['print']['printers'][$pt] = (isset($o['print']['printers'][$pt]) ? $o['print']['printers'][$pt] : 0) + $n;
				foreach (!empty($row['sv']) ? array_map('trim', explode(',', (string) $row['sv'])) : array() as $dv) { if ($dv !== '') { $o['print']['dev'][$dv] = (isset($o['print']['dev'][$dv]) ? $o['print']['dev'][$dv] : 0) + $n; } }
			}
			if (!empty($row['sa'])) {
				$sa = (string) $row['sa'];
				$o['sat']['counts'][$sa] = (isset($o['sat']['counts'][$sa]) ? $o['sat']['counts'][$sa] : 0) + 1;
				$o['sat']['rated']++;
				$ev_rated = true;
				if (cp_panel_lc($sa) !== 'elégedett') { $o['sat']['bad'][] = array('id' => $id, 't' => $title, 'd' => $d, 'v' => $sa, 'note' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) (isset($row['no']) ? $row['no'] : ''))), 0, 160)); }
			}
			if (!empty($row['gm'])) {
				$gm = (string) $row['gm'];
				$o['gm']['counts'][$gm] = (isset($o['gm']['counts'][$gm]) ? $o['gm']['counts'][$gm] : 0) + 1;
				if (cp_panel_lc($gm) !== 'jó') { $o['gm']['bad'][] = array('id' => $id, 't' => $title, 'd' => $d, 'v' => $gm, 'dev' => (string) (isset($row['sv']) ? $row['sv'] : ''), 'note' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) (isset($row['gmn']) ? $row['gmn'] : ''))), 0, 160)); }
			}
		}
		if ($ev_has) {
			$o['print']['events']++;
			$o['print']['total'] += $ev_pa;
			if (!isset($o['print']['svc'][$svc])) { $o['print']['svc'][$svc] = array('k' => $svc, 'n' => 0, 'ev' => 0); }
			$o['print']['svc'][$svc]['n'] += $ev_pa;
			$o['print']['svc'][$svc]['ev']++;
			$o['print']['top'][] = array('id' => $id, 't' => $title, 'd' => $d, 'n' => $ev_pa);
		}
		if ($ev_rated) { $o['sat']['ratedEvents']++; }
	}
	$o['print']['cost'] = $paper * $o['print']['total'];
	$o['print']['paper'] = $paper;
	$o['print']['svc'] = array_values($o['print']['svc']);
	usort($o['print']['svc'], function ($a, $b) { return $b['n'] - $a['n']; });
	usort($o['print']['top'], function ($a, $b) { return $b['n'] - $a['n']; });
	$o['print']['top'] = array_slice($o['print']['top'], 0, 5);
	arsort($o['print']['printers']);
	arsort($o['print']['dev']);
	foreach (array('sat', 'gm') as $k) { usort($o[$k]['bad'], function ($a, $b) { return strcmp($b['d'], $a['d']); }); $o[$k]['bad'] = array_slice($o[$k]['bad'], 0, 12); }
	$c = $o['sat']['counts'];
	$o['sat']['pct'] = $o['sat']['rated'] ? round((isset($c['Elégedett']) ? $c['Elégedett'] : 0) / $o['sat']['rated'] * 100) : null;
	return $o;
}
