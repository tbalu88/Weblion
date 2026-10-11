<?php
/* =====================================================================
 * STATISZTIKÁK – HETI / HAVI ÖSSZESÍTŐ A PUMBLE-RE (v1.18)
 * Heti: vasárnap este (alapból 19:00), a hétfő–vasárnap hétről. Havi: a hónap utolsó napján este.
 * Ha az időpontban nem fut a WP-Cron, a következő körben (36 órán belül) pótolja; periódusonként egyszer küld.
 * Webhook: külön (Beállítások → Crazy összesítők), titkosítva – NEM a kezelői setup-fotós csatorna.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_st_dg_opts() {
	$o = get_option('cp_st_dg', array());
	return array_merge(array('week' => 1, 'month' => 1, 'hour' => 19), is_array($o) ? $o : array());
}

function cp_st_dg_hook() {
	return cp_st_secret('digest_webhook_secret');
}

/* ------------------------------------------------------------ időzítés */

add_action('cp_st_quick', 'cp_st_dg_maybe_send', 20);
add_action('cp_st_cron', 'cp_st_dg_maybe_send', 20);

function cp_st_dg_targets() {
	$o = cp_st_dg_opts();
	$tz = cp_st_tz();
	$now = new DateTime('now', $tz);
	$h = max(0, min(23, (int) $o['hour']));
	$out = array();
	/* heti: a legutóbbi vasárnap $h óra (ha ma vasárnap van és már elmúlt, akkor ma) */
	$sun = (clone $now)->setTime($h, 0);
	$dow = (int) $now->format('N');
	if ($dow !== 7) { $sun->modify('-' . $dow . ' days'); }
	if ($sun > $now) { $sun->modify('-7 days'); }
	$mon = (clone $sun)->modify('-6 days');
	$out['week'] = array('at' => $sun->getTimestamp(), 'from' => $mon->format('Y-m-d'), 'to' => $sun->format('Y-m-d'), 'key' => 'w' . $mon->format('Y-m-d'));
	/* havi: a hónap utolsó napja $h óra (ha még nem jött el, az előző hónapé) */
	$last = (clone $now)->modify('last day of this month')->setTime($h, 0);
	if ($last > $now) { $last = (clone $now)->modify('last day of previous month')->setTime($h, 0); }
	$out['month'] = array('at' => $last->getTimestamp(), 'from' => $last->format('Y-m-01'), 'to' => $last->format('Y-m-d'), 'key' => 'm' . $last->format('Y-m'));
	return $out;
}

function cp_st_dg_maybe_send() {
	if (!cp_st_db_ok() || cp_st_dg_hook() === '') { return; }
	$o = cp_st_dg_opts();
	$sent = get_option('cp_st_dg_sent', array());
	if (!is_array($sent)) { $sent = array(); }
	foreach (cp_st_dg_targets() as $kind => $t) {
		if (empty($o[$kind]) || !empty($sent[$t['key']])) { continue; }
		$late = time() - $t['at'];
		if ($late < 0 || $late > 36 * HOUR_IN_SECONDS) { continue; }
		/* atomikus zár: két egyszerre futó kör ne küldjön kétszer */
		if (!add_option('cp_st_dg_lock_' . $t['key'], time(), '', 'no')) { continue; }
		$res = cp_st_dg_send($kind, $t['from'], $t['to'], false);
		if ($res === true) {
			$sent[$t['key']] = time();
			$sent = array_slice($sent, -40, null, true);
			update_option('cp_st_dg_sent', $sent, false);
		} else {
			delete_option('cp_st_dg_lock_' . $t['key']);
		}
		$log = get_option('cp_st_dg_log', array());
		if (!is_array($log)) { $log = array(); }
		array_unshift($log, array('t' => time(), 'k' => $t['key'], 'r' => $res === true ? 'elküldve' : (is_wp_error($res) ? $res->get_error_message() : (string) $res)));
		update_option('cp_st_dg_log', array_slice($log, 0, 30), false);
	}
}

function cp_st_dg_send($kind, $from, $to, $test) {
	$hook = cp_st_dg_hook();
	if ($hook === '') { return new WP_Error('nohook', 'Nincs megadva az összesítő Pumble-webhookja.'); }
	$txt = cp_st_dg_text($kind, $from, $to, !$test);
	if ($test) { $txt = "🧪 TESZT – " . $txt; }
	if (function_exists('cp_el_pumble')) { return cp_el_pumble($txt, $hook); }
	$res = wp_remote_post($hook, array('timeout' => 15, 'headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode(array('text' => $txt))));
	if (is_wp_error($res)) { return $res; }
	$code = (int) wp_remote_retrieve_response_code($res);
	return $code >= 200 && $code < 300 ? true : new WP_Error('pumble', 'Pumble hiba (' . $code . ')');
}

/* ------------------------------------------------------------ adatok */

/* lement / közelgő rendezvény: Won, aláírt (dátummal), vagy aláírt szakaszban álló deal – nem elvesztett */
function cp_st_dg_booked($r) {
	static $sg = null;
	if ($sg === null) { $sg = array_map('cp_panel_lc', cp_st_lines(cp_panel_opts()['signed_stages'])); }
	if ($r['status'] === 'Lost' || empty($r['event_d'])) { return false; }
	return $r['status'] === 'Won' || !empty($r['signed_d']) || in_array(cp_panel_lc($r['stage']), $sg, true);
}

function cp_st_dg_n($n) { return number_format((float) $n, 0, ',', ' '); }
function cp_st_dg_ft($n) { return cp_st_dg_n($n) . ' Ft'; }

/* „47 (előző: 40, +18%)” */
function cp_st_dg_cmp($cur, $prev, $lbl, $money = false) {
	$f = $money ? 'cp_st_dg_ft' : 'cp_st_dg_n';
	if ($prev === null) { return ''; }
	if (!$prev) { return $lbl . ': ' . $f($prev); }
	$p = round(($cur - $prev) / $prev * 100);
	return $lbl . ': ' . $f($prev) . ', ' . ($p > 0 ? '+' : ($p < 0 ? '−' : '')) . abs($p) . '%';
}

/* periódusonkénti idősorok a rekordokhoz: kulcs = hét hétfője (Y-m-d) vagy hónap (Y-m) */
function cp_st_dg_series($rows, $kind) {
	$key = function ($d) use ($kind) {
		if (!$d) { return null; }
		if ($kind === 'month') { return substr($d, 0, 7); }
		$x = new DateTime($d, cp_st_tz());
		$x->modify('-' . ((int) $x->format('N') - 1) . ' days');
		return $x->format('Y-m-d');
	};
	$s = array('leads' => array(), 'signed' => array(), 'value' => array(), 'events' => array(), 'evvalue' => array());
	$add = function ($m, $k, $v) use (&$s) { if ($k === null) { return; } $s[$m][$k] = (isset($s[$m][$k]) ? $s[$m][$k] : 0) + $v; };
	foreach ($rows as $r) {
		$add('leads', $key($r['created_d']), 1);
		if (!empty($r['signed_d'])) {
			$add('signed', $key($r['signed_d']), 1);
			if ($r['huf']) { $add('value', $key($r['signed_d']), $r['value']); }
		}
		if (cp_st_dg_booked($r)) {
			$add('events', $key($r['event_d']), 1);
			if ($r['huf']) { $add('evvalue', $key($r['event_d']), $r['value']); }
		}
	}
	/* levelek az eseménynaplóból */
	global $wpdb;
	foreach ((array) $wpdb->get_results('SELECT kind, at, n FROM ' . cp_st_t('mail_ev'), ARRAY_A) as $e) {
		$d = cp_st_local_d(strtotime($e['at'] . ' UTC'));
		if ($e['kind'] === 'sent') { $add('msent', $key($d), (int) $e['n']); }
		if ($e['kind'] === 'answered') { $add('mans', $key($d), 1); }
	}
	return $s;
}

/* all-time rekord / „legjobb N periódus óta” üzenetek */
function cp_st_dg_records($s, $kind, $pkey, $first) {
	$names = array('leads' => 'megkeresés', 'signed' => 'aláírt szerződés', 'value' => 'aláírt dealérték', 'events' => 'lement rendezvény', 'evvalue' => 'lement rendezvények értéke', 'msent' => 'elküldött levél', 'mans' => 'megválaszolt ügyféllevél');
	$unit = $kind === 'month' ? 'hónap' : 'hét';
	$out = array();
	foreach ($names as $m => $nm) {
		$cur = isset($s[$m][$pkey]) ? $s[$m][$pkey] : 0;
		if ($cur <= 0) { continue; }
		/* a korábbi periódusok a mérés kezdetétől (a levelekre a napló indulásától) */
		$prev = array();
		foreach ((isset($s[$m]) ? $s[$m] : array()) as $k => $v) { if ($k < $pkey && $k >= $first[$m]) { $prev[$k] = $v; } }
		$np = count($prev);
		$min = $kind === 'month' ? 6 : 8;
		if ($np < $min) { continue; }
		$money = in_array($m, array('value', 'evvalue'), true);
		$fmt = $money ? 'cp_st_dg_ft' : 'cp_st_dg_n';
		arsort($prev);
		$best = reset($prev); $bestk = key($prev);
		if ($cur > $best) {
			$out[] = '🏆 All-time rekord: ' . $fmt($cur) . ' ' . $nm . ' egy ' . $unit . ' alatt (az eddigi csúcs ' . $fmt($best) . ' volt, ' . cp_st_dg_plabel($kind, $bestk) . ').';
			continue;
		}
		/* legjobb N periódus óta */
		ksort($prev);
		$keys = array_reverse(array_keys($prev));
		$since = 0;
		foreach ($keys as $k) { if ($prev[$k] >= $cur) { break; } $since++; }
		if ($since >= ($kind === 'month' ? 12 : 26)) { $out[] = '⭐ ' . $since . ' ' . $unit . ' óta a legtöbb ' . $nm . ': ' . $fmt($cur) . '.'; }
	}
	return $out;
}

function cp_st_dg_plabel($kind, $k) {
	$hu = array('', 'január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
	if ($kind === 'month') { return substr($k, 0, 4) . '. ' . $hu[(int) substr($k, 5, 2)]; }
	$d = new DateTime($k, cp_st_tz());
	return $d->format('Y') . '. ' . (int) $d->format('W') . '. hét';
}

function cp_st_dg_text($kind, $f, $t, $commit = false) {
	global $wpdb;
	$rows = cp_st_load();
	$s = cp_st_dg_series($rows, $kind);
	$wk = $kind === 'week';
	$pkey = $wk ? $f : substr($f, 0, 7);
	/* összehasonlítás: előző periódus és tavaly ugyanez */
	if ($wk) {
		$pf = gmdate('Y-m-d', strtotime($f . ' 12:00 -7 days')); $pt = gmdate('Y-m-d', strtotime($t . ' 12:00 -7 days'));
		$yf = gmdate('Y-m-d', strtotime($f . ' 12:00 -364 days')); $yt = gmdate('Y-m-d', strtotime($t . ' 12:00 -364 days'));
	} else {
		$pf = (new DateTime($f, cp_st_tz()))->modify('-1 month')->format('Y-m-01'); $pt = (new DateTime($pf, cp_st_tz()))->format('Y-m-t');
		$yf = (new DateTime($f, cp_st_tz()))->modify('-1 year')->format('Y-m-01'); $yt = (new DateTime($yf, cp_st_tz()))->format('Y-m-t');
	}
	$m = function ($a, $b) use ($rows) {
		$x = array('leads' => 0, 'signed' => 0, 'value' => 0, 'events' => 0, 'evvalue' => 0, 'ch' => array(), 'svc' => array());
		foreach ($rows as $r) {
			if (cp_st_in($r['created_d'], $a, $b)) { $x['leads']++; $x['ch'][$r['ch']] = (isset($x['ch'][$r['ch']]) ? $x['ch'][$r['ch']] : 0) + 1; }
			if (cp_st_in($r['signed_d'], $a, $b)) { $x['signed']++; if ($r['huf']) { $x['value'] += $r['value']; } }
			if (cp_st_dg_booked($r) && cp_st_in($r['event_d'], $a, $b)) {
				$x['events']++;
				if ($r['huf']) { $x['evvalue'] += $r['value']; }
				$sv = $r['svc'] !== '' ? $r['svc'] : (!empty($r['svcs']) ? $r['svcs'][0] : 'nincs megadva');
				$x['svc'][$sv] = (isset($x['svc'][$sv]) ? $x['svc'][$sv] : 0) + 1;
			}
		}
		return $x;
	};
	$c = $m($f, $t); $p = $m($pf, $pt); $y = $m($yf, $yt);
	$hu = array('', 'jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.');
	$md = function ($d) use ($hu) { return $hu[(int) substr($d, 5, 2)] . ' ' . (int) substr($d, 8, 2) . '.'; };
	$L = array();
	$L[] = $wk ? '📊 Heti összesítő – ' . cp_st_dg_plabel('week', $f) . ' (' . $md($f) . ' – ' . $md($t) . ')' : '📊 Havi összesítő – ' . cp_st_dg_plabel('month', $pkey);
	$L[] = '';
	$pl = $wk ? 'előző hét' : 'előző hónap';
	$cm = function ($k, $money = false) use ($c, $p, $y, $pl) { return ' (' . implode(' · ', array_filter(array(cp_st_dg_cmp($c[$k], $p[$k], $pl, $money), cp_st_dg_cmp($c[$k], $y[$k], 'tavaly', $money)))) . ')'; };
	$L[] = '📥 Megkeresések: ' . cp_st_dg_n($c['leads']) . $cm('leads');
	arsort($c['ch']);
	$labels = cp_st_channel_labels();
	$chs = array();
	foreach (array_slice($c['ch'], 0, 4, true) as $k => $n) { $chs[] = (isset($labels[$k]) ? $labels[$k] : $k) . ' ' . $n; }
	if ($chs) { $L[] = '      csatornák: ' . implode(', ', $chs); }
	$L[] = '✍️ Aláírt szerződések: ' . cp_st_dg_n($c['signed']) . ' – ' . cp_st_dg_ft($c['value']) . $cm('signed');
	arsort($c['svc']);
	$sv = array();
	foreach (array_slice($c['svc'], 0, 4, true) as $k => $n) { $sv[] = $k . ' ' . $n; }
	$L[] = '🎉 Lement rendezvények: ' . cp_st_dg_n($c['events']) . ' – ' . cp_st_dg_ft($c['evvalue']) . $cm('events') . ($sv ? "\n      " . implode(', ', $sv) : '');
	foreach (cp_st_dg_conv($rows, $kind, $f, $t, $c, $p, $y, $pl) as $x) { $L[] = $x; }

	/* levelek (eseménynapló) */
	$from_utc = gmdate('Y-m-d H:i:s', strtotime($f . ' 00:00:00 Europe/Budapest'));
	$to_utc = gmdate('Y-m-d H:i:s', strtotime($t . ' 23:59:59 Europe/Budapest'));
	$E = cp_st_t('mail_ev');
	$sent = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(n),0) FROM $E WHERE kind='sent' AND at BETWEEN %s AND %s", $from_utc, $to_utc));
	$recv = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $E WHERE kind='recv' AND at BETWEEN %s AND %s", $from_utc, $to_utc));
	$waits = $wpdb->get_col($wpdb->prepare("SELECT wait_s FROM $E WHERE kind='answered' AND at BETWEEN %s AND %s", $from_utc, $to_utc));
	$evf = get_option('cp_st_mail_ev_from', '');
	$al = function_exists('cp_st_alerts') ? cp_st_alerts() : array('alerts' => array());
	$A = array();
	foreach ($al['alerts'] as $x) { $A[$x['id']] = $x; }
	$unans = isset($A['mail']) ? (int) $A['mail']['big'] : 0;
	$med = $waits ? cp_st_median(array_map('intval', $waits)) / 3600 : null;
	$partial = $evf !== '' && $evf > $from_utc;
	$L[] = '✉️ Levelek: ' . cp_st_dg_n($sent) . ' elküldött, ' . cp_st_dg_n($recv) . ' beérkezett ügyféllevél, ' . cp_st_dg_n(count($waits)) . ' megválaszolt' . ($med !== null ? ' (medián válaszidő: ' . ($med < 1 ? round($med * 60) . ' perc' : str_replace('.', ',', (string) round($med, 1)) . ' óra') . ')' : '') . ' · most ' . $unans . ' megválaszolatlan'
		. ($evf === '' ? "\n      (a levelek mérése most indult, a következő összesítőtől lesz adat)" : ($partial ? "\n      (a mérés " . wp_date('m.d. H:i', strtotime($evf . ' UTC')) . ' óta fut – ez az időszak még nem teljes)' : ''));

	/* kintlévőség + változás az előző összesítőhöz */
	$snap = get_option('cp_st_dg_snap', array());
	if (!is_array($snap)) { $snap = array(); }
	$rsum = isset($A['recv']) ? $A['recv'] : null; $osum = isset($A['over']) ? $A['over'] : null;
	$num = function ($x) { return (float) preg_replace('/[^0-9]/', '', (string) $x); };
	if ($rsum) {
		$rv = $num($rsum['big']); $ov = $osum ? $num($osum['big']) : 0;
		$chg = isset($snap[$kind]['recv']) ? $rv - $snap[$kind]['recv'] : null;
		$L[] = '💰 Kintlévőség: ' . cp_st_dg_ft($rv) . ' (' . (int) (isset($rsum['n']) ? $rsum['n'] : 0) . ' deal) · lejárt: ' . cp_st_dg_ft($ov) . ' (' . (int) ($osum && isset($osum['n']) ? $osum['n'] : 0) . ' deal)' . ($chg !== null ? ' · az előző összesítő óta ' . ($chg > 0 ? '+' : ($chg < 0 ? '−' : '')) . cp_st_dg_ft(abs($chg)) : '');
		if ($osum && !empty($osum['items'])) { $L[] = '      legrégebb lejárt: ' . $osum['items'][0]['late'] . ' napja'; }
		if ($commit) { $snap[$kind] = array('recv' => $rv, 'over' => $ov, 't' => time()); update_option('cp_st_dg_snap', $snap, false); }
	}

	/* hirdetés */
	$sp = cp_st_spend($f, $t);
	$tot = $sp['google'] + $sp['meta'] + $sp['linkedin'];
	if ($tot > 0) {
		$paid = 0; foreach (array('google', 'meta', 'linkedin') as $k) { $paid += isset($c['ch'][$k]) ? $c['ch'][$k] : 0; }
		$parts = array();
		foreach (array('google' => 'Google', 'meta' => 'Meta', 'linkedin' => 'LinkedIn') as $k => $n) { if ($sp[$k] > 0) { $parts[] = $n . ' ' . cp_st_dg_ft($sp[$k]); } }
		$L[] = '📣 Hirdetés: ' . cp_st_dg_ft($tot) . ' (' . implode(', ', $parts) . ')' . ($c['leads'] ? ' · költés / összes megkeresés: ' . cp_st_dg_ft($tot / $c['leads']) : '') . ' · UTM / forrás szerint fizetett csatornából: ' . $paid . ' megkeresés';
	}

	/* előre: a következő hét / hónap */
	$nf = gmdate('Y-m-d', strtotime($t . ' 12:00 +1 day'));
	$nt = $wk ? gmdate('Y-m-d', strtotime($t . ' 12:00 +7 days')) : (new DateTime($nf, cp_st_tz()))->format('Y-m-t');
	$nx = $m($nf, $nt);
	$up = array($nx['events'] . ' foglalt rendezvény (' . cp_st_dg_ft($nx['evvalue']) . ')');
	if (isset($A['cap']) && !empty($A['cap']['items'])) {
		$fulls = array();
		foreach ($A['cap']['items'] as $it) { if ($it['d'] >= $nf && $it['d'] <= $nt) { $fulls[] = $md($it['d']); } }
		if ($fulls) { $up[] = 'telt / majdnem telt nap: ' . implode(', ', array_slice($fulls, 0, 6)); }
	}
	if (isset($A['contract'])) { $up[] = 'aláíratlan szerződés a következő 14 napban: ' . $A['contract']['big']; }
	$L[] = '🗓️ ' . ($wk ? 'Jövő hét' : 'Következő hónap') . ': ' . implode(' · ', $up);

	/* rekordok */
	$first = array();
	$mn = $wpdb->get_var('SELECT MIN(created_d) FROM ' . cp_st_t('deals'));
	$fk = function ($d) use ($wk) { if (!$d) { return '9999'; } if (!$wk) { return substr($d, 0, 7); } $x = new DateTime($d, cp_st_tz()); $x->modify('-' . ((int) $x->format('N') - 1) . ' days'); return $x->format('Y-m-d'); };
	/* a teljes első periódus már nem csonka: a mérés kezdete utáni első periódustól */
	$base = $fk($mn);
	foreach (array('leads', 'signed', 'value', 'events', 'evvalue') as $k) { $first[$k] = $base; }
	$ef = $evf !== '' ? $fk(gmdate('Y-m-d', strtotime($evf . ' UTC +7 days'))) : '9999';
	$first['msent'] = $ef; $first['mans'] = $ef;
	$rec = cp_st_dg_records($s, $kind, $pkey, $first);
	$msl = array();
	foreach (cp_st_ms_log() as $x) { if ($x['d'] >= $f && $x['d'] <= $t) { $msl[] = '🎯 ' . $x['txt']; } }
	if ($rec || $msl) { $L[] = ''; foreach (array_merge($rec, array_reverse($msl)) as $r) { $L[] = $r; } }

	/* nyitott piros figyelmeztetések */
	$red = array();
	foreach ($al['alerts'] as $x) { if ($x['sev'] === 'red') { $red[] = $x['title'] . ' (' . $x['big'] . ')'; } }
	if ($red) { $L[] = ''; $L[] = '⚠️ Nyitott figyelmeztetés: ' . implode(' · ', $red); }

	$L[] = '';
	$L[] = 'Részletek: ' . home_url('/' . trim(cp_panel_opts()['slug'], '/') . '/#stat');
	return implode("\n", $L);
}

/* ------------------------------------------------------------ konverziós arány */

function cp_st_dg_pct($a, $b) {
	return $b ? str_replace('.', ',', (string) round($a / $b * 100, 1)) . '%' : '–';
}

/* pp-eltérés: „32,1% (előző: 28,0% · tavaly: 30,5%)” */
function cp_st_dg_conv($rows, $kind, $f, $t, $c, $p, $y, $pl) {
	$out = array();
	$r = function ($x) { return $x['leads'] ? $x['signed'] / $x['leads'] : null; };
	$fmt = function ($v) { return $v === null ? '–' : str_replace('.', ',', (string) round($v * 100, 1)) . '%'; };
	$out[] = '🔁 Konverzió (aláírt / megkeresés az időszakban): ' . $fmt($r($c)) . ' (' . $pl . ': ' . $fmt($r($p)) . ' · tavaly: ' . $fmt($r($y)) . ')';
	/* beérett kohorsz: havi összesítőnél a 3 hónappal korábbi hónap, hetinél a 6 héttel korábbi hét megkeresései, 120 napos horizonttal */
	if ($kind === 'month') {
		$cf = (new DateTime($f, cp_st_tz()))->modify('-3 months')->format('Y-m-01');
		$ct = (new DateTime($cf, cp_st_tz()))->format('Y-m-t');
		$lbl = 'A 3 hónappal korábbi (' . cp_st_dg_plabel('month', substr($cf, 0, 7)) . 'i)';
	} else {
		$cf = gmdate('Y-m-d', strtotime($f . ' 12:00 -42 days'));
		$ct = gmdate('Y-m-d', strtotime($t . ' 12:00 -42 days'));
		$lbl = 'A 6 héttel korábbi (' . cp_st_dg_plabel('week', $cf) . ')';
	}
	$coh = function ($a, $b) use ($rows) {
		$lim = gmdate('Y-m-d', strtotime($a . ' 12:00 +120 days'));
		$o = array('n' => 0, 'w' => 0, 'ch' => array());
		foreach ($rows as $x) {
			if (!cp_st_in($x['created_d'], $a, $b)) { continue; }
			/* aláírt, de a szinkron előtt, dátum nélkül (nodate): aláírtnak számít */
			$won = (!empty($x['signed_d']) && $x['signed_d'] <= $lim) || $x['signed_src'] === 'nodate' ? 1 : 0;
			$o['n']++; $o['w'] += $won;
			if (!isset($o['ch'][$x['ch']])) { $o['ch'][$x['ch']] = array(0, 0); }
			$o['ch'][$x['ch']][0]++; $o['ch'][$x['ch']][1] += $won;
		}
		return $o;
	};
	$k1 = $coh($cf, $ct);
	$yf = (new DateTime($cf, cp_st_tz()))->modify($kind === 'month' ? '-1 year' : '-364 days')->format('Y-m-d');
	$yt = (new DateTime($ct, cp_st_tz()))->modify($kind === 'month' ? '-1 year' : '-364 days')->format('Y-m-d');
	if ($kind === 'month') { $yt = (new DateTime($yf, cp_st_tz()))->format('Y-m-t'); }
	$k0 = $coh($yf, $yt);
	if ($k1['n'] >= 5) {
		$line = '      ' . $lbl . ' ' . $k1['n'] . ' megkeresésből ' . $k1['w'] . ' lett aláírt deal 120 napon belül (' . cp_st_dg_pct($k1['w'], $k1['n']) . ')' . ($k0['n'] >= 5 ? ' · tavaly ugyanez: ' . cp_st_dg_pct($k0['w'], $k0['n']) : '');
		$out[] = $line;
		if ($kind === 'month') {
			$labels = cp_st_channel_labels();
			$parts = array();
			uasort($k1['ch'], function ($a, $b) { return $b[0] - $a[0]; });
			foreach ($k1['ch'] as $ch => $v) { if ($v[0] >= 5) { $parts[] = (isset($labels[$ch]) ? $labels[$ch] : $ch) . ' ' . cp_st_dg_pct($v[1], $v[0]) . ' (' . $v[1] . '/' . $v[0] . ')'; } }
			if ($parts) { $out[] = '      csatornánként: ' . implode(', ', array_slice($parts, 0, 5)); }
		}
	}
	return $out;
}

/* ------------------------------------------------------------ mérföldkövek (azonnali üzenet) */

function cp_st_ms_defs() {
	return array(
		'ys' => array('Idén aláírt szerződés', 'db', 50),
		'yv' => array('Idei aláírt dealérték', 'Ft', 10000000),
		'yl' => array('Idei megkeresés', 'db', 250),
		'ye' => array('Idén lement rendezvény', 'db', 50),
		'mv' => array('Havi aláírt dealérték', 'Ft', 5000000),
		'ms' => array('Havi aláírt szerződés', 'db', 25),
		'ae' => array('Összes lement rendezvény (valaha)', 'db', 500),
	);
}

function cp_st_ms_steps() {
	$o = cp_st_dg_opts();
	$out = array();
	foreach (cp_st_ms_defs() as $k => $d) { $out[$k] = isset($o['ms_' . $k]) && (int) $o['ms_' . $k] > 0 ? (int) $o['ms_' . $k] : $d[2]; }
	return $out;
}

/* a mostani értékek + a tavalyi ugyanezen napi értékek */
function cp_st_ms_values() {
	$rows = cp_st_load();
	$today = cp_st_today();
	$y = substr($today, 0, 4); $mo = substr($today, 0, 7);
	$ly = (new DateTime($today, cp_st_tz()))->modify('-1 year')->format('Y-m-d');
	$lyy = substr($ly, 0, 4); $lmo = substr($ly, 0, 7);
	$v = array('ys' => 0, 'yv' => 0, 'yl' => 0, 'ye' => 0, 'mv' => 0, 'ms' => 0, 'ae' => 0);
	$l = $v;
	foreach ($rows as $r) {
		if (substr($r['created_d'], 0, 4) === $y) { $v['yl']++; }
		if ($r['created_d'] <= $ly && substr($r['created_d'], 0, 4) === $lyy) { $l['yl']++; }
		if (!empty($r['signed_d'])) {
			$val = $r['huf'] ? $r['value'] : 0;
			if (substr($r['signed_d'], 0, 4) === $y) { $v['ys']++; $v['yv'] += $val; }
			if (substr($r['signed_d'], 0, 7) === $mo) { $v['ms']++; $v['mv'] += $val; }
			if ($r['signed_d'] <= $ly && substr($r['signed_d'], 0, 4) === $lyy) { $l['ys']++; $l['yv'] += $val; }
			if ($r['signed_d'] <= $ly && substr($r['signed_d'], 0, 7) === $lmo) { $l['ms']++; $l['mv'] += $val; }
		}
		if (cp_st_dg_booked($r) && $r['event_d'] <= $today) {
			$v['ae']++;
			if (substr($r['event_d'], 0, 4) === $y) { $v['ye']++; }
		}
		if (cp_st_dg_booked($r) && $r['event_d'] <= $ly) {
			$l['ae']++;
			if (substr($r['event_d'], 0, 4) === $lyy) { $l['ye']++; }
		}
	}
	return array('v' => $v, 'l' => $l, 'y' => $y, 'mo' => $mo);
}

function cp_st_ms_log() {
	$l = get_option('cp_st_ms_log', array());
	return is_array($l) ? $l : array();
}

add_action('cp_st_quick', 'cp_st_ms_check', 30);

function cp_st_ms_check() {
	if (!cp_st_db_ok()) { return; }
	$o = cp_st_dg_opts();
	if (isset($o['ms']) && !$o['ms']) { return; }
	$st = cp_st_state();
	if (empty($st['deal_full_at']) || !empty($st['deal_redo'])) { return; }
	if (!add_option('cp_st_ms_lock', time(), '', 'no')) {
		if ((int) get_option('cp_st_ms_lock') < time() - 300) { delete_option('cp_st_ms_lock'); }
		return;
	}
	$x = cp_st_ms_values();
	$steps = cp_st_ms_steps();
	$defs = cp_st_ms_defs();
	$done = get_option('cp_st_ms_done', null);
	$first = !is_array($done);
	if ($first) { $done = array(); }
	$hook = cp_st_dg_hook();
	foreach ($steps as $k => $step) {
		$cur = $x['v'][$k];
		$lvl = (int) floor($cur / $step) * $step;
		if ($lvl <= 0) { continue; }
		$per = in_array($k, array('mv', 'ms'), true) ? $x['mo'] : ($k === 'ae' ? 'all' : $x['y']);
		$key = $k . '|' . $per . '|' . $lvl;
		if (isset($done[$key])) { continue; }
		$done[$key] = time();
		if ($first) { continue; } /* első bekapcsolás: a már elért mérföldköveket csak rögzítjük */
		$money = $defs[$k][1] === 'Ft';
		$num = $money ? cp_st_dg_ft($lvl) : cp_st_dg_n($lvl) . '.';
		$hub = array('', 'januárban', 'februárban', 'márciusban', 'áprilisban', 'májusban', 'júniusban', 'júliusban', 'augusztusban', 'szeptemberben', 'októberben', 'novemberben', 'decemberben');
		$when = in_array($k, array('mv', 'ms'), true) ? $hub[(int) substr($x['mo'], 5, 2)] : ($k === 'ae' ? 'összesen' : 'idén');
		$txt = array(
			'ys' => 'Idén megvan a ' . $num . ' aláírt szerződés!',
			'yv' => 'Az idei aláírt dealérték átlépte a ' . $num . '-ot!',
			'yl' => 'Idén megjött a ' . $num . ' megkeresés!',
			'ye' => 'Idén lement a ' . $num . ' rendezvény!',
			'mv' => ucfirst($when) . ' az aláírt dealérték átlépte a ' . $num . '-ot!',
			'ms' => ucfirst($when) . ' megvan a ' . $num . ' aláírt szerződés!',
			'ae' => 'Összesen lement a ' . $num . ' rendezvényünk!',
		);
		$t = $txt[$k];
		$lv = $x['l'][$k];
		if ($k !== 'ae' && $lv > 0) { $t .= ' (tavaly ugyanekkor: ' . ($money ? cp_st_dg_ft($lv) : cp_st_dg_n($lv)) . ')'; }
		$log = cp_st_ms_log();
		array_unshift($log, array('t' => time(), 'd' => cp_st_today(), 'k' => $key, 'txt' => $t));
		update_option('cp_st_ms_log', array_slice($log, 0, 60), false);
		if ($hook !== '') { cp_st_dg_post('🎯 Mérföldkő: ' . $t); }
	}
	update_option('cp_st_ms_done', $done, false);
	delete_option('cp_st_ms_lock');
}

function cp_st_dg_post($txt) {
	$hook = cp_st_dg_hook();
	if ($hook === '') { return new WP_Error('nohook', 'Nincs webhook.'); }
	if (function_exists('cp_el_pumble')) { return cp_el_pumble($txt, $hook); }
	$res = wp_remote_post($hook, array('timeout' => 15, 'headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode(array('text' => $txt))));
	return is_wp_error($res) ? $res : true;
}

/* ------------------------------------------------------------ WP admin: Beállítások → Crazy összesítők */

add_action('admin_menu', function () {
	add_options_page('Crazy összesítők', 'Crazy összesítők', 'manage_options', 'cp-st-dg', 'cp_st_dg_admin');
});

function cp_st_dg_admin() {
	if (!current_user_can('manage_options')) { return; }
	$msg = '';
	$pre = '';
	if (!empty($_POST['cp_st_dg_save'])) {
		check_admin_referer('cp_st_dg');
		$in = wp_unslash($_POST);
		$o = cp_st_dg_opts();
		$o['week'] = !empty($in['week']) ? 1 : 0;
		$o['month'] = !empty($in['month']) ? 1 : 0;
		$o['hour'] = max(0, min(23, (int) (isset($in['hour']) ? $in['hour'] : 19)));
		$o['ms'] = !empty($in['ms']) ? 1 : 0;
		foreach (array_keys(cp_st_ms_defs()) as $k) { if (isset($in['ms_' . $k])) { $o['ms_' . $k] = max(1, (int) preg_replace('/[^0-9]/', '', $in['ms_' . $k])); } }
		update_option('cp_st_dg', $o, false);
		$msg = 'Beállítások elmentve.';
		$u = isset($in['hook_new']) ? trim((string) $in['hook_new']) : '';
		if ($u !== '') {
			if (preg_match('#^https://api\.pumble\.com/[A-Za-z0-9/_.\-]+$#', $u)) { cp_st_secret_set('digest_webhook_secret', $u); $msg .= ' A webhook elmentve.'; }
			else { $msg .= ' A webhook címe nem https://api.pumble.com/… kezdetű, ezért nem mentettem.'; }
		}
		if (!empty($in['hook_clear'])) { cp_st_secret_set('digest_webhook_secret', ''); $msg .= ' A webhook törölve.'; }
		$t = cp_st_dg_targets();
		if (!empty($in['preview_week'])) { $pre = cp_st_dg_text('week', $t['week']['from'], $t['week']['to']); }
		if (!empty($in['preview_month'])) { $pre = cp_st_dg_text('month', $t['month']['from'], $t['month']['to']); }
		foreach (array('week', 'month') as $k) {
			if (!empty($in['test_' . $k])) {
				$r = cp_st_dg_send($k, $t[$k]['from'], $t[$k]['to'], true);
				$msg .= ' Teszt (' . ($k === 'week' ? 'heti' : 'havi') . '): ' . ($r === true ? 'elküldve.' : (is_wp_error($r) ? $r->get_error_message() : 'hiba'));
			}
		}
	}
	$o = cp_st_dg_opts();
	$t = cp_st_dg_targets();
	$has = cp_st_dg_hook() !== '';
	echo '<div class="wrap"><h1>Crazy összesítők (Pumble)</h1>';
	if ($msg) { echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>'; }
	echo '<p>Heti összesítő vasárnap este, havi összesítő a hónap utolsó napján este. Ha az időpontban nem fut a WP-Cron, 36 órán belül pótolja (periódusonként egyszer).</p>';
	echo '<form method="post">';
	wp_nonce_field('cp_st_dg');
	echo '<input type="hidden" name="cp_st_dg_save" value="1"><table class="form-table">';
	cp_panel_row('Pumble-webhook', '<input type="password" name="hook_new" class="large-text" autocomplete="new-password" placeholder="' . esc_attr($has ? 'beállítva – csak cseréhez írd be az újat' : 'https://api.pumble.com/workspaces/…/incomingWebhooks/postMessage/…') . '">' . ($has ? ' <label><input type="checkbox" name="hook_clear" value="1"> törlés</label>' : '') . '<p class="description">Pumble: Workspace settings → Incoming Webhooks → New Webhook → a vezetőségi csatorna (ne a kezelői setup-fotós). Titkosítva tárolódik.</p>');
	cp_panel_row('Küldés', '<label><input type="checkbox" name="week" value="1"' . checked(!empty($o['week']), true, false) . '> heti (vasárnap)</label> &nbsp; <label><input type="checkbox" name="month" value="1"' . checked(!empty($o['month']), true, false) . '> havi (a hónap utolsó napja)</label> &nbsp; időpont: <input name="hour" value="' . (int) $o['hour'] . '" class="small-text" inputmode="numeric"> óra');
	$mv = cp_st_ms_values();
	$steps = cp_st_ms_steps();
	$rowsh = array();
	foreach (cp_st_ms_defs() as $k => $d) {
		$cur = $mv['v'][$k];
		$next = ((int) floor($cur / $steps[$k]) + 1) * $steps[$k];
		$fmt = $d[1] === 'Ft' ? 'cp_st_dg_ft' : 'cp_st_dg_n';
		$rowsh[] = '<tr><td>' . esc_html($d[0]) . '</td><td>lépcső: <input name="ms_' . $k . '" value="' . (int) $steps[$k] . '" class="regular-text" style="width:9em" inputmode="numeric"> ' . esc_html($d[1]) . '</td><td>most: <b>' . esc_html($fmt($cur)) . '</b> · következő: ' . esc_html($fmt($next)) . '</td></tr>';
	}
	cp_panel_row('Mérföldkövek', '<label><input type="checkbox" name="ms" value="1"' . checked(!isset($o['ms']) || !empty($o['ms']), true, false) . '> azonnali Pumble-üzenet, ha egy lépcsőt átlépünk (10 percenként ellenőrzi; az első bekapcsoláskor a már elért szinteket nem küldi ki)</label><table class="widefat striped" style="margin-top:8px;max-width:860px">' . implode('', $rowsh) . '</table>');
	$ml = cp_st_ms_log();
	if ($ml) {
		$l = array();
		foreach (array_slice($ml, 0, 8) as $x) { $l[] = esc_html(wp_date('Y.m.d. H:i', $x['t']) . ' · ' . $x['txt']); }
		cp_panel_row('Elért mérföldkövek', implode('<br>', $l));
	}
	cp_panel_row('Következő', 'heti: ' . esc_html(cp_st_dg_plabel('week', $t['week']['from'])) . ' · havi: ' . esc_html(cp_st_dg_plabel('month', substr($t['month']['from'], 0, 7))) . ' (a legutóbbi esedékes periódus)');
	$log = get_option('cp_st_dg_log', array());
	if (is_array($log) && $log) {
		$l = array();
		foreach (array_slice($log, 0, 8) as $x) { $l[] = esc_html(wp_date('Y.m.d. H:i', $x['t']) . ' · ' . $x['k'] . ' · ' . $x['r']); }
		cp_panel_row('Napló', implode('<br>', $l));
	}
	echo '</table><p><button class="button button-primary">Mentés</button> <button class="button" name="preview_week" value="1">Heti előnézet</button> <button class="button" name="preview_month" value="1">Havi előnézet</button> '
		. ($has ? '<button class="button" name="test_week" value="1">Heti teszt küldése</button> <button class="button" name="test_month" value="1">Havi teszt küldése</button>' : '') . '</p></form>';
	if ($pre !== '') { echo '<h2>Előnézet</h2><pre style="background:#fff;border:1px solid #ccd0d4;padding:14px;white-space:pre-wrap;max-width:760px">' . esc_html($pre) . '</pre>'; }
	echo '</div>';
}
