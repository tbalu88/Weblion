<?php
/**
 * Crazy panel – egységes „Szolgáltatás(ok)” (v2.9.216).
 *
 * A deal szolgáltatásai egy listában. Forrás:
 *   1) ha a panelben valaki beállította: a saját panel-tábla (crazy_deal_svc) – ezt a Salesmate-szinkron nem írja felül;
 *   2) különben a régi Salesmate-mezőkből SZÁMOLVA, olvasáskor (EventCrazy szolgáltatás(ok), Választott szolgáltatás,
 *      További szolgáltatások, Szolgáltatás) egy átnevezési táblával. A régi mezők NEM módosulnak, a Salesmate-be nem írunk.
 * A bekapcsolás kapcsolóval: cp_svc_on() (cp_svc_v2 beállítás) – kikapcsolva minden a régi módon működik.
 * Terv: tasks/plan.md
 */
if (!defined('ABSPATH')) { exit; }

function cp_svc_on() { return get_option('cp_svc_v2') === '1'; }

/* a választható szolgáltatások (az EventCrazy-lista + Hibrid + Fotózás 2 fotóssal) – a sorrend a szerkesztőé */
function cp_svc_list() {
	return array('Szelfigép', 'Hibrid (Szelfigép + AI karikatúra)', 'Digitális Karikatúra', 'AI Karikatúra', 'AI fotózás', 'SpinnerClassic', 'OverheadSpinner', 'VIP Spinner',
		'VR Élmény', 'Greenbox', 'SzelfiRobot', 'SelfieLight', 'Selfie Tükör', 'Rendezvényfotó', 'Fotózás 2 fotóssal', 'Rendezvény videó', 'Drón',
		'LED Tánctér', 'Élőzene', 'DJ', 'Karaoke', 'TattooPrinting', 'Hashtag Printing', 'Mindflex', 'CrazyCatch', 'CrazyLight', 'Crazy Beauty',
		'Csocsó / Foosball', 'XXL csocsó / XXL Foosball', 'Ülőfoci / Seated Football', 'Visszahívást kérek', 'Egyéb');
}
function cp_svc_key($v) { return preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents(trim((string) $v)))); }

/* régi érték → új érték(ek); a kulcs a normalizált (kisbetűs, ékezet és írásjel nélküli) szöveg */
function cp_svc_alias() {
	return array(
		'360spinner' => array('SpinnerClassic'), 'spinner' => array('SpinnerClassic'), 'spinnerclassic' => array('SpinnerClassic'),
		'overhead360' => array('OverheadSpinner'), 'oveerheadspinner' => array('OverheadSpinner'), 'overheadspinner' => array('OverheadSpinner'),
		'miniszelfitukor' => array('Selfie Tükör'), 'selfietukor' => array('Selfie Tükör'),
		'rendezvenyfotovideo' => array('Rendezvényfotó', 'Rendezvény videó'),
		'karikatura' => array('Digitális Karikatúra'), 'digitaliskarikatura' => array('Digitális Karikatúra'),
		'selfielight' => array('SelfieLight'), 'foto' => array('Rendezvényfotó'), 'video' => array('Rendezvény videó'),
		'dancefloor' => array('LED Tánctér'), 'ledtancter' => array('LED Tánctér'),
		'fotoautomata' => array('Szelfigép'), 'fotoautomataspinner' => array('Szelfigép', 'SpinnerClassic'),
		'vr' => array('VR Élmény'), 'vrelmeny' => array('VR Élmény'), 'crazycathc' => array('CrazyCatch'),
		'fotozas2fotossal' => array('Fotózás 2 fotóssal'), 'aikarikatura' => array('AI Karikatúra'),
		'nemnevezettmegkonkretszolgaltatast' => array(), /* nem szolgáltatás – kimarad */
	);
}
/* egy régi érték leképezése: array(lista, megjegyzés-e) */
function cp_svc_map_one($v) {
	static $canon = null; if ($canon === null) { $canon = array(); foreach (cp_svc_list() as $c) { $canon[cp_svc_key($c)] = $c; } }
	$k = cp_svc_key($v);
	if ($k === '') { return array(array(), ''); }
	$A = cp_svc_alias();
	if (isset($A[$k])) { return array($A[$k], ''); }
	if (isset($canon[$k])) { return array(array($canon[$k]), ''); }
	/* szabad szöveg (v2.9.220): darabokra bontva („Szelfigép + VR”), darabonként a felismerhető szolgáltatás; a fel nem ismert darab Egyéb + az eredeti szöveg */
	$parts = preg_split('/\s*(?:\+|&|,|;|\bés\b|\band\b)\s*/u', trim((string) $v));
	if (count($parts) > 1) {
		$out = array(); $nn = array();
		foreach ($parts as $pp) { if (trim($pp) === '') { continue; } list($l, $n) = cp_svc_map_one($pp); foreach ($l as $x) { if (!in_array($x, $out, true)) { $out[] = $x; } } if ($n !== '') { $nn[] = $n; } }
		return array($out, implode(', ', $nn));
	}
	$re = array('/robot|droid/' => 'SzelfiRobot', '/tukor|mirror/' => 'Selfie Tükör', '/hibrid/' => 'Hibrid (Szelfigép + AI karikatúra)', '/aikarikatura/' => 'AI Karikatúra', '/karikatura/' => 'Digitális Karikatúra',
		'/aifoto/' => 'AI fotózás', '/greenbox|greenscreen/' => 'Greenbox', '/vip/' => 'VIP Spinner', '/overhead/' => 'OverheadSpinner', '/360|spinner/' => 'SpinnerClassic', '/^vr|vrelmeny/' => 'VR Élmény',
		'/selfielight/' => 'SelfieLight', '/szelfi|selfi|fotoautomata|photobooth/' => 'Szelfigép', '/video/' => 'Rendezvény videó', '/foto/' => 'Rendezvényfotó', '/tancter|dancefloor/' => 'LED Tánctér',
		'/karaoke/' => 'Karaoke', '/elozene|zenekar/' => 'Élőzene', '/\bdj|^dj/' => 'DJ', '/dron/' => 'Drón', '/tattoo/' => 'TattooPrinting', '/hashtag/' => 'Hashtag Printing', '/csocso|foosball/' => 'Csocsó / Foosball');
	foreach ($re as $rx => $c) { if (preg_match($rx, $k)) { return array(array($c), ''); } }
	return array(array('Egyéb'), trim((string) $v));
}
/* a régi mezőkből számolt lista (a régi mezők nem módosulnak) */
function cp_svc_derive($d) {
	$out = array(); $notes = array();
	foreach (array('services_ec', 'services_cp', 'services_more', 'service') as $f) {
		$raw = (string) ($d[$f] ?? '');
		if (trim($raw) === '') { continue; }
		foreach (explode(' | ', $raw) as $v) {
			list($l, $n) = cp_svc_map_one($v);
			foreach ($l as $x) { if (!in_array($x, $out, true)) { $out[] = $x; } }
			if ($n !== '' && !in_array($n, $notes, true)) { $notes[] = $n; }
		}
	}
	/* csak csomagnév van (Crazyphoto szelfigép-csomag: Mini / Medium / Mega / Carneval), vagy a deal-név „[Crazyphoto] …” – szelfigép (mint a régi becslésnél) */
	if (!$out) {
		$pk = implode(' ', array(($d['package'] ?? ''), ($d['pkg_fix'] ?? ''), ($d['pkg_v2'] ?? '')));
		if (preg_match('/\b(mini|medium|mega|carneval)\b/i', $pk)) { $out[] = 'Szelfigép'; }
		elseif (preg_match('/^\[crazyphoto(\.hu)?\]/i', (string) ($d['title'] ?? ''))) { $out[] = 'Szelfigép'; $guess = 1; }
	}
	/* a választható lista sorrendjében */
	$ord = array_flip(cp_svc_list());
	usort($out, function ($a, $b) use ($ord) { return ($ord[$a] ?? 999) - ($ord[$b] ?? 999); });
	return array('l' => $out, 'x' => $notes, 'src' => 'sm') + (!empty($guess) ? array('g' => 1) : array());
}

/* ------------------------------------------------------------ a panel saját táblája (csak a panelben módosított dealek) */
function cp_svc_t() { global $wpdb; return $wpdb->prefix . 'crazy_deal_svc'; }
function cp_svc_db() {
	static $ok = null;
	if ($ok !== null) { return $ok; }
	if (get_option('cp_svc_db') !== '1') {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta('CREATE TABLE ' . cp_svc_t() . " (
  deal_id bigint(20) unsigned NOT NULL,
  services text NOT NULL,
  note text NULL,
  uid int(11) NOT NULL DEFAULT 0,
  by_user varchar(100) NOT NULL DEFAULT '',
  updated_at datetime NULL,
  PRIMARY KEY  (deal_id)
) " . $wpdb->get_charset_collate() . ';');
		update_option('cp_svc_db', '1', false);
	}
	return $ok = true;
}
/* több deal panel-sora egyszerre (a listákhoz) – gyorsítótárral */
function cp_svc_rows($ids) {
	if (!isset($GLOBALS['cp_svc_cache'])) { $GLOBALS['cp_svc_cache'] = array(); }
	$cache = &$GLOBALS['cp_svc_cache'];
	$ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
	$need = array_values(array_diff($ids, array_keys($cache)));
	if ($need && get_option('cp_svc_db') === '1') {
		global $wpdb;
		foreach ($need as $i) { $cache[$i] = null; }
		foreach (array_chunk($need, 500) as $ch) {
			foreach ((array) $wpdb->get_results('SELECT deal_id, services, note FROM ' . cp_svc_t() . ' WHERE deal_id IN (' . implode(',', $ch) . ')', ARRAY_A) as $r) {
				$l = json_decode((string) $r['services'], true);
				$cache[(int) $r['deal_id']] = array('l' => is_array($l) ? array_values(array_map('strval', $l)) : array(), 'x' => (string) $r['note'] !== '' ? array((string) $r['note']) : array(), 'src' => 'panel');
			}
		}
	}
	$o = array(); foreach ($ids as $i) { $o[$i] = $cache[$i] ?? null; } return $o;
}
/* a deal érvényes szolgáltatásai: array('l' => [...], 'x' => [megjegyzések], 'src' => 'panel'|'sm') */
function cp_deal_svc($d) {
	$id = (int) ($d['id'] ?? 0);
	if ($id) { $r = cp_svc_rows(array($id)); if (!empty($r[$id])) { return $r[$id]; } }
	return cp_svc_derive($d);
}
function cp_deal_svc_str($d, $sep = ', ') { $s = cp_deal_svc($d); return implode($sep, $s['l']); }
/* ügyfélnek / kollégának kiírt szöveg (díjbekérő, levélváltozó, Pumble): az „Egyéb” helyén az eredeti szöveg, a „Visszahívást kérek” nem szolgáltatás */
function cp_deal_svc_label($d, $sep = ', ') {
	$s = cp_deal_svc($d); $o = array();
	foreach ($s['l'] as $x) { if ($x === 'Visszahívást kérek') { continue; } if ($x === 'Egyéb' && $s['x']) { foreach ($s['x'] as $n) { $o[] = $n; } continue; } $o[] = $x; }
	return implode($sep, array_values(array_unique($o)));
}

/* ------------------------------------------------------------ új árbecslés: a szolgáltatásokból + óraszámból (a csomagnév nem számít) */
function cp_est_v2($d) {
	if (!function_exists('cp_est_ec_deal')) { return null; }
	$s = cp_deal_svc($d);
	if (!$s['l']) { return null; }
	$c = $d;
	$c['services_ec'] = implode(' | ', $s['l']);
	/* a régi csomag-/szövegmezők nem számítanak – a magánszemély (Crazyphoto-ár) ága az óraszámból számol */
	$c['service'] = $c['package'] = $c['pkg_fix'] = $c['pkg_v2'] = $c['services_cp'] = $c['services_more'] = $c['spinner_pkg'] = '';
	$r = cp_est_ec_deal($c, cp_est_private($d));
	if (!$r) { return null; }
	if (!empty($s['g'])) { $r['a'] = 1; } /* csak a deal-névből kitalált szelfigép: közelítő (mint a régi becslésnél) */
	/* döntés 2026-11-12 (C): minden bejelölt szolgáltatás összeadva; 3 vagy több szolgáltatásnál „közelítő” + egyeztetési jelzés */
	$real = array_values(array_filter($s['l'], function ($x) { return !in_array($x, array('Egyéb', 'Visszahívást kérek'), true); }));
	if (count($real) >= 3) { $r['a'] = 1; $r['m'] = 1; array_unshift($r['l'], 'Több szolgáltatás (' . count($real) . ') – egyeztetni, melyiket kérik ténylegesen'); }
	if ($s['x']) { foreach ($s['x'] as $n) { $r['l'][] = 'Megjegyzés (régi szöveg): ' . $n; } }
	return $r;
}
/* a becslés: kapcsoló szerint az új vagy a régi */
function cp_est_any($d) { return cp_svc_on() ? cp_est_v2($d) : (function_exists('cp_est_deal') ? cp_est_deal($d) : null); }

/* ------------------------------------------------------------ mentés a panel-táblába (a Salesmate-be nem ír) */
function cp_svc_set($id, $list, $note = null) {
	global $wpdb;
	cp_svc_db();
	$ok = cp_svc_list();
	$l = array_values(array_unique(array_filter(array_map('strval', (array) $list), function ($v) use ($ok) { return in_array($v, $ok, true); })));
	$ord = array_flip($ok); usort($l, function ($a, $b) use ($ord) { return $ord[$a] - $ord[$b]; });
	$prev = cp_deal_svc(array('id' => (int) $id) + (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_crm_t('deals') . ' WHERE id = %d', (int) $id), ARRAY_A));
	if ($note === null) { $note = $prev['x'] ? implode('; ', $prev['x']) : ''; }
	$wpdb->replace(cp_svc_t(), array('deal_id' => (int) $id, 'services' => wp_json_encode($l), 'note' => mb_substr((string) $note, 0, 2000), 'uid' => function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0, 'by_user' => function_exists('cp_panel_who') ? mb_substr((string) cp_panel_who(), 0, 100) : '', 'updated_at' => gmdate('Y-m-d H:i:s')));
	/* előzmény a deal változásai közé (a panel saját táblájába, a Salesmate-be nem) */
	if ($prev['l'] !== $l && function_exists('cp_crm_t')) {
		$wpdb->insert(cp_crm_t('history'), array('deal_id' => (int) $id, 'field' => 'svc', 'old_val' => implode(', ', $prev['l']), 'new_val' => implode(', ', $l), 'by_user' => function_exists('cp_panel_who') ? mb_substr((string) cp_panel_who(), 0, 100) : '', 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel'));
	}
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'svc', 'Szolgáltatások módosítva', 'deal:' . (int) $id, implode(', ', $l)); }
	$GLOBALS['cp_svc_cache'][(int) $id] = array('l' => $l, 'x' => (string) $note !== '' ? array((string) $note) : array(), 'src' => 'panel'); /* ugyanabban a kérésben is az új lista */
	return $l;
}
/* a deal adatlapjára (csak bekapcsolva) */
add_filter('cp_crm_deal_data', function ($x) {
	if (!cp_svc_on() || empty($x['deal']['id'])) { return $x; }
	$s = cp_deal_svc($x['deal']);
	$x['svc'] = $s; $x['svc_opts'] = cp_svc_list();
	return $x;
}, 22);

/* ------------------------------------------------------------ ügyfélportál: a „csomag / szolgáltatás” szöveg az egységes listából (v2.9.221) */
function cp_svc_portal_pkg($deal_id, $fallback = '') {
	if (!cp_svc_on() || !(int) $deal_id || !function_exists('cp_crm_t')) { return $fallback; }
	global $wpdb;
	$d = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_crm_t('deals') . ' WHERE id = %d', (int) $deal_id), ARRAY_A);
	if (!$d) { return $fallback; }
	$l = cp_deal_svc_label($d);
	return $l !== '' ? $l : $fallback;
}
