<?php
/* =====================================================================
 * STATISZTIKÁK – ÉLŐ FIGYELMEZTETÉSEK (v1.17)
 * Gyors szinkron 10 percenként (csak a változások: deal + kontakt), és a panel kérésére is,
 * ha az adat 10 percnél régebbi. A figyelmeztetések a saját táblákból számolódnak.
 * Beállítások (cp_st_settings, opcionális kulcsok): al_recv_stages, al_pay_days, al_pre_days, al_mail_h
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_st_al_opts() {
	$o = cp_st_opts();
	$d = array(
		'al_recv_stages' => "Rendezvény lement (G&R)\nPénzre várunk", /* kintlévőség: lezajlott, még nem fizetett */
		'al_pay_days'    => 8,   /* fizetési határidő a rendezvény után, ha a díjbekérőnél nincs megadva */
		'al_contract_days' => 14, /* aláíratlan szerződés figyelése ennyi nappal a rendezvény előtt */
		'al_mail_h'      => 4,   /* ennyi óra után sárga a megválaszolatlan levél, 24 után piros */
	);
	foreach ($d as $k => $v) { if (isset($o[$k]) && $o[$k] !== '') { $d[$k] = $o[$k]; } }
	return $d;
}

/* ------------------------------------------------------------ gyors szinkron (változások) */

add_filter('cron_schedules', function ($s) {
	$s['cp_st_10min'] = array('interval' => 10 * MINUTE_IN_SECONDS, 'display' => '10 percenként');
	return $s;
});
add_action('init', function () {
	if (!wp_next_scheduled('cp_st_quick')) { wp_schedule_event(time() + 300, 'cp_st_10min', 'cp_st_quick'); }
});
add_action('cp_st_quick', function () { cp_st_quick_sync(false); });

function cp_st_quick_sync($force) {
	if (!function_exists('cp_sm_request') || !cp_st_db_ok()) { return; }
	$st = cp_st_state();
	if (empty($st['deal_full_at']) || empty($st['contact_full_at'])) { return; }
	if (!$force && time() - (int) (isset($st['quick_at']) ? $st['quick_at'] : 0) < 8 * MINUTE_IN_SECONDS) { return; }
	if (get_transient('cp_st_sync_lock') || get_transient('cp_st_quick_lock')) { return; }
	set_transient('cp_st_quick_lock', 1, 2 * MINUTE_IN_SECONDS);
	$since = gmdate('Y-m-d', time() - DAY_IN_SECONDS);
	$err = '';
	$r = cp_st_search('deal', 4, cp_st_deal_fields(), cp_st_mod_rule('deal', $since), 0);
	if (is_wp_error($r)) { $err = $r->get_error_message(); } else { cp_st_store_deals($r, false); }
	$c = cp_st_search('contact', 1, cp_st_contact_fields(), cp_st_mod_rule('contact', $since), 0);
	if (is_wp_error($c)) { $err = $c->get_error_message(); } else { cp_st_store_contacts($c); }
	cp_st_mail_capture();
	$st = cp_st_state();
	$st['quick_at'] = time();
	$st['quick_err'] = mb_substr($err, 0, 200);
	if ($err === '') { $st['quick_ok'] = time(); }
	cp_st_state_set($st);
	cp_st_bump();
	delete_transient('cp_st_quick_lock');
}

/* ------------------------------------------------------------ figyelmeztetések */

function cp_st_al_item($r, $d, $extra = array()) {
	return array_merge(array('id' => (string) $r['id'], 't' => (string) $r['title'], 'd' => $d, 'v' => isset($r['value']) ? (float) $r['value'] : null), $extra);
}

function cp_st_alerts() {
	global $wpdb;
	$D = cp_st_t('deals');
	$C = cp_st_t('contacts');
	$A = cp_st_t('ads');
	$o = cp_st_al_opts();
	$p = cp_panel_opts();
	$today = cp_st_today();
	$now = time();
	$out = array();
	$lc = function ($a) { return array_map('cp_panel_lc', $a); };
	$recv = $lc(cp_st_lines($o['al_recv_stages']));
	$signed = $lc(cp_st_lines($p['signed_stages']));
	$booked = $lc(cp_st_lines($p['booked_stages']));
	$open = $wpdb->get_results("SELECT id, title, stage, value, event_d, rotten_d, pay_method, contact_id, created_at FROM $D WHERE status = 'Open'", ARRAY_A);

	/* 1) megválaszolatlan levelek: a deal utolsó kommunikációja az ügyfél bejövő levele (nyitott deal, 30 napon belül).
	 * A kontakt „utolsó kapott” ideje önmagában nem elég: a nem a dealhez kötött (pl. belső / továbbított) levelek is frissítik,
	 * az „utolsó küldött” mező pedig a csapatpostafiókból küldött leveleknél gyakran üres. Ezért döntő a deal utolsó kommunikációja,
	 * és a küldés után 2 percen belül „beérkező” levél (a saját levelünk visszhangja) nem számít. */
	$rows = $wpdb->get_results($wpdb->prepare("SELECT x.id, x.title, x.value, x.stage, k.id cid, k.mail_recv_at, k.mail_sent_at FROM $D x JOIN $C k ON k.id = x.contact_id
		WHERE x.status = 'Open' AND k.mail_recv_at IS NOT NULL AND (k.mail_sent_at IS NULL OR k.mail_recv_at > k.mail_sent_at) AND k.mail_recv_at >= %s
		AND x.last_comm_at IS NOT NULL AND x.last_comm_mode = 'Email' AND ABS(TIMESTAMPDIFF(SECOND, x.last_comm_at, k.mail_recv_at)) <= 60
		AND NOT (k.mail_sent_at IS NOT NULL AND TIMESTAMPDIFF(SECOND, k.mail_sent_at, k.mail_recv_at) BETWEEN 0 AND 120)
		ORDER BY k.mail_recv_at ASC", gmdate('Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS)), ARRAY_A);
	/* „Nem kell válasz” jelölés kontaktonként (a jelölt levélnél újabb bejövő levél újra jelez) */
	$okm = get_option('cp_st_mail_ok', array());
	if (!is_array($okm)) { $okm = array(); }
	$seen = array(); $items = array(); $after = array(); $oldest = 0; $dism = 0;
	$open_by = array();
	foreach ($open as $r) { $open_by[$r['id']] = $r; }
	foreach ($rows as $r) {
		if (isset($seen[$r['cid']])) { continue; }
		$seen[$r['cid']] = 1;
		if (isset($okm[$r['cid']]) && $okm[$r['cid']] >= $r['mail_recv_at']) { $dism++; continue; }
		$h = ($now - strtotime($r['mail_recv_at'] . ' UTC')) / 3600;
		$ev = isset($open_by[$r['id']]['event_d']) ? $open_by[$r['id']]['event_d'] : '';
		$it = cp_st_al_item($r, '', array('h' => round($h, 1), 'st' => $r['stage'], 'cid' => (string) $r['cid'], 'rv' => $r['mail_recv_at'], 'ok' => 1));
		/* lezajlott rendezvény utáni levél (jellemzően köszönő): külön, a jelzésbe nem számít */
		if (in_array(cp_panel_lc($r['stage']), $recv, true) || ($ev !== '' && $ev < $today)) { $it['sev'] = 'info'; $after[] = $it; continue; }
		$oldest = max($oldest, $h);
		$items[] = $it;
	}
	$out[] = array(
		'id' => 'mail', 'title' => 'Megválaszolatlan levelek',
		'sev' => !$items ? ($after ? 'info' : 'ok') : ($oldest >= 24 ? 'red' : ($oldest >= (float) $o['al_mail_h'] ? 'amber' : 'info')),
		'big' => count($items),
		'text' => ($items ? 'Az ügyfél levele a deal utolsó kommunikációja, még nem válaszoltunk. A legrégebbi ' . cp_st_al_age($oldest) . ' vár.' : 'Rendezvény előtti dealnél nincs megválaszolatlan levél.')
			. ($after ? ' A lezajlott rendezvények utáni ' . count($after) . ' levél (jellemzően köszönő) nem számít bele – lent, külön.' : '')
			. ($dism ? ' ' . $dism . ' levél „nem kell válasz” jelöléssel elrejtve.' : ''),
		'items' => array_merge(array_slice($items, 0, 15), array_slice($after, 0, 10)), 'more' => max(0, count($items) - 15) + max(0, count($after) - 10),
		'note' => 'Akkor jelez, ha a deal utolsó kommunikációja az ügyfél levele (Salesmate, ~10 perces frissítés). A küldés után 2 percen belül „beérkező” saját másolat nem számít. A levél szövegét a Salesmate API nem adja ki, ezért a köszönő leveleket a „Nem kell válasz” gombbal lehet lezárni.',
	);

	/* 2) rohadó dealek */
	$rot = array(); $fresh = array(); $old = 0; $sg = 0; $by = array();
	foreach ($open as $r) {
		if (empty($r['rotten_d']) || $r['rotten_d'] >= $today) { continue; }
		$rot[] = $r;
		$by[$r['stage']] = (isset($by[$r['stage']]) ? $by[$r['stage']] : 0) + 1;
		$age = cp_st_days($r['rotten_d'], $today) - 1;
		if ($age <= 14) { $fresh[] = cp_st_al_item($r, $r['rotten_d'], array('st' => $r['stage'], 'age' => $age)); }
		if ($age > 60) { $old++; }
		if (in_array(cp_panel_lc($r['stage']), $booked, true)) { $sg++; }
	}
	usort($fresh, function ($a, $b) { return $a['age'] - $b['age']; });
	arsort($by);
	$parts = array();
	foreach ($by as $k => $n) { $parts[] = $k . ': ' . $n; }
	$out[] = array(
		'id' => 'rot', 'title' => 'Rohadó dealek',
		'sev' => !$rot ? 'ok' : ($fresh || $sg ? 'amber' : 'info'),
		'big' => count($rot),
		'text' => $rot ? count($fresh) . ' az utolsó 14 napban rohadt meg' . ($sg ? ', ' . $sg . ' már foglalt (szerződéses) szakaszban' : '') . '. ' . ($old ? $old . ' deal 60+ napja áll – ezeket érdemes lezárni (Lost), hogy a lista tiszta legyen.' : '') : 'Nincs lejárt (rohadó) nyitott deal.',
		'sub' => implode(' · ', $parts),
		'items' => array_slice($fresh, 0, 12), 'more' => max(0, count($fresh) - 12),
		'note' => 'Rohadó = a Salesmate „rotten” dátuma lejárt (a szakaszban beállított napok óta nem volt aktivitás). A lista az utolsó 14 napban megrohadtakat mutatja.',
	);

	/* 3–4) kintlévőségek: lezajlott rendezvény, kintlévőség-szakaszban (még nem Won); határidő = rendezvény + díjbekérő napjai */
	$due = array(); $over = array(); $sum = 0; $osum = 0; $noval = 0;
	foreach ($open as $r) {
		if (!in_array(cp_panel_lc($r['stage']), $recv, true) || empty($r['event_d']) || $r['event_d'] > $today) { continue; }
		$tr = get_option(CP_PANEL_TRACK . $r['id'], array());
		$days = (is_array($tr) && isset($tr['dj']) && in_array($tr['dj'], array('8', '30'), true)) ? (int) $tr['dj'] : (int) $o['al_pay_days'];
		$dd = gmdate('Y-m-d', strtotime($r['event_d'] . ' 12:00 +' . $days . ' days'));
		$late = cp_st_days($dd, $today) - 1;
		$it = cp_st_al_item($r, $dd, array('st' => $r['stage'], 'ev' => $r['event_d'], 'late' => $late, 'pm' => $r['pay_method'], 'term' => $days));
		if ((float) $r['value'] <= 0) { $noval++; }
		$sum += (float) $r['value'];
		$due[] = $it;
		if ($dd < $today) { $over[] = $it; $osum += (float) $r['value']; }
	}
	usort($over, function ($a, $b) { return $b['late'] - $a['late']; });
	usort($due, function ($a, $b) { return strcmp($a['d'], $b['d']); });
	$out[] = array(
		'id' => 'over', 'title' => 'Lejárt kintlévőségek',
		'sev' => $over ? 'red' : 'ok', 'big' => $over ? cp_st_al_ft($osum) : 0, 'n' => count($over),
		'text' => $over ? count($over) . ' deal fizetési határideje lejárt, a legrégebbi ' . $over[0]['late'] . ' napja.' : 'Nincs lejárt kintlévőség.',
		'items' => array_slice($over, 0, 12), 'more' => max(0, count($over) - 12),
		'note' => 'Határidő = rendezvény napja + a díjbekérőnél megadott 8 / 30 nap (ha nincs megadva: ' . (int) $o['al_pay_days'] . ' nap). Fizetettnek a Won státusz számít.',
	);
	$out[] = array(
		'id' => 'recv', 'title' => 'Kintlévőségek',
		'sev' => $due ? 'info' : 'ok', 'big' => cp_st_al_ft($sum), 'n' => count($due),
		'text' => $due ? count($due) . ' lezajlott rendezvény vár fizetésre (' . implode(', ', array_map('trim', cp_st_lines($o['al_recv_stages']))) . '), ebből ' . (count($due) - count($over)) . ' még határidőn belül.' . ($noval ? ' ' . $noval . ' dealnél nincs dealérték megadva.' : '') : 'Nincs fizetésre váró lezajlott rendezvény.',
		'items' => array_slice($due, 0, 12), 'more' => max(0, count($due) - 12),
	);

	/* 5) lezajlott, de a szakasza nincs továbbléptetve */
	$stale = array();
	foreach ($open as $r) {
		$s = cp_panel_lc($r['stage']);
		if (empty($r['event_d']) || $r['event_d'] >= $today || in_array($s, $recv, true) || !in_array($s, $booked, true)) { continue; }
		$stale[] = cp_st_al_item($r, $r['event_d'], array('st' => $r['stage']));
	}
	if ($stale) {
		$out[] = array('id' => 'stale', 'title' => 'Lezajlott, de nincs továbbléptetve', 'sev' => 'amber', 'big' => count($stale),
			'text' => 'A rendezvény már lement, a deal mégis „' . implode('” / „', array_unique(array_column($stale, 'st'))) . '” szakaszban áll – így a kintlévőségek között sem látszik.',
			'items' => array_slice($stale, 0, 12), 'more' => max(0, count($stale) - 12));
	}

	/* 6) közelgő rendezvény aláíratlan szerződéssel */
	$soon = array();
	$lim = gmdate('Y-m-d', strtotime($today . ' 12:00 +' . (int) $o['al_contract_days'] . ' days'));
	foreach ($open as $r) {
		$s = cp_panel_lc($r['stage']);
		if (empty($r['event_d']) || $r['event_d'] < $today || $r['event_d'] > $lim || !in_array($s, $booked, true) || in_array($s, $signed, true)) { continue; }
		$soon[] = cp_st_al_item($r, $r['event_d'], array('st' => $r['stage'], 'left' => cp_st_days($today, $r['event_d']) - 1));
	}
	usort($soon, function ($a, $b) { return $a['left'] - $b['left']; });
	if ($soon) {
		$out[] = array('id' => 'contract', 'title' => 'Közelgő rendezvény, aláíratlan szerződés', 'sev' => $soon[0]['left'] <= 7 ? 'red' : 'amber', 'big' => count($soon),
			'text' => (int) $o['al_contract_days'] . ' napon belüli rendezvény, a szerződés még nincs aláírva. A legközelebbi ' . ($soon[0]['left'] === 0 ? 'ma' : $soon[0]['left'] . ' nap múlva') . ' lesz.',
			'items' => $soon);
	}

	/* 7) kapacitás: telt napok a következő 60 napban */
	$cap = $wpdb->get_results($wpdb->prepare("SELECT event_d d, SUM(kind IN ('selfie','both')) s, SUM(kind IN ('spin','both')) p, COUNT(*) n FROM $D
		WHERE event_d BETWEEN %s AND %s AND status <> 'Lost' AND (status = 'Won' OR signed_d IS NOT NULL OR LOWER(stage) IN ('" . implode("','", array_map('esc_sql', $booked)) . "')) GROUP BY event_d ORDER BY event_d", $today, gmdate('Y-m-d', strtotime($today . ' +60 days'))), ARRAY_A);
	$full = array(); $near = array();
	foreach ($cap as $c) {
		$fs = (int) $c['s'] >= (int) $p['cap_selfie'];
		$fp = (int) $p['cap_spin'] > 0 && (int) $c['p'] >= (int) $p['cap_spin'];
		$x = array('id' => '', 't' => cp_st_al_hud($c['d']), 'd' => $c['d'], 'v' => null, 'st' => 'fotóautomata ' . (int) $c['s'] . '/' . (int) $p['cap_selfie'] . ', spinner ' . (int) $c['p'] . '/' . (int) $p['cap_spin']);
		if ($fs || $fp) { $full[] = $x; } elseif ((int) $c['s'] >= (int) $p['cap_selfie'] - 1) { $near[] = $x; }
	}
	if ($full || $near) {
		$out[] = array('id' => 'cap', 'title' => 'Telt napok (60 napon belül)', 'sev' => 'info', 'big' => count($full),
			'text' => ($full ? count($full) . ' nap teltházas' : 'Nincs teltházas nap') . ($near ? ', ' . count($near) . ' napon már csak 1 fotóautomata szabad' : '') . '. Ezekre a napokra az új megkeresésnél érdemes azonnal jelezni a foglaltságot.',
			'items' => array_merge($full, $near));
	}

	/* 8) hirdetési anomáliák + adatforrások frissessége */
	$out[] = cp_st_al_ads($today);

	/* 9) szezonális összevetés és előfoglaltság */
	foreach (cp_st_al_season($today) as $x) { $out[] = $x; }

	/* adatfrissítés */
	$st = cp_st_state();
	$age = $now - (int) (isset($st['last_ok']) ? $st['last_ok'] : 0);
	if (!empty($st['last_err']) || $age > 2 * HOUR_IN_SECONDS) {
		$out[] = array('id' => 'sync', 'title' => 'Salesmate-szinkron', 'sev' => 'red', 'big' => '!',
			'text' => !empty($st['last_err']) ? 'Hiba: ' . $st['last_err'] : 'A legutóbbi teljes szinkron ' . round($age / 3600) . ' órája volt – a WP-Cron nem fut rendszeresen (lásd: valódi cron a WP adminban).');
	}

	$rank = array('red' => 0, 'amber' => 1, 'info' => 2, 'good' => 2, 'ok' => 3);
	usort($out, function ($a, $b) use ($rank) { return $rank[$a['sev']] - $rank[$b['sev']]; });
	return array('alerts' => $out, 'at' => $now, 'quick' => isset($st['quick_ok']) ? (int) $st['quick_ok'] : 0, 'quickErr' => isset($st['quick_err']) ? $st['quick_err'] : '');
}

function cp_st_al_age($h) {
	return $h < 1 ? round($h * 60) . ' perce' : ($h < 48 ? round($h) . ' órája' : round($h / 24) . ' napja');
}

function cp_st_al_ft($n) {
	return number_format((float) $n, 0, ',', ' ') . ' Ft';
}

function cp_st_al_hud($d) {
	$n = array('', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap');
	return str_replace('-', '.', $d) . '. ' . $n[(int) (new DateTime($d, cp_st_tz()))->format('N')];
}

/* hirdetési anomáliák: az utolsó teljes nap (tegnap) a megelőző 14 nap átlagához mérve, csatornánként */
function cp_st_al_ads($today) {
	global $wpdb;
	$A = cp_st_t('ads');
	$y = gmdate('Y-m-d', strtotime($today . ' 12:00 -1 day'));
	$b0 = gmdate('Y-m-d', strtotime($today . ' 12:00 -15 days'));
	$names = array('google' => 'Google Ads', 'meta' => 'Meta', 'linkedin' => 'LinkedIn');
	$rows = $wpdb->get_results($wpdb->prepare("SELECT channel, d, SUM(spend) s, SUM(clicks) c, SUM(impressions) i, SUM(conversions) v FROM $A WHERE d BETWEEN %s AND %s GROUP BY channel, d", $b0, $y), ARRAY_A);
	$by = array();
	foreach ($rows as $r) { $by[$r['channel']][$r['d']] = $r; }
	foreach ($by as &$x) { ksort($x); }
	unset($x);
	$items = array(); $sev = 'ok';
	$bump = function ($s) use (&$sev) { $o = array('ok' => 0, 'info' => 1, 'amber' => 2, 'red' => 3); if ($o[$s] > $o[$sev]) { $sev = $s; } };
	$add = function ($s, $ch, $txt) use (&$items, $bump, $names) { $items[] = array('id' => '', 't' => $names[$ch], 'd' => '', 'v' => null, 'st' => $txt, 'sev' => $s); $bump($s); };
	foreach ($by as $ch => $days) {
		if (!isset($names[$ch])) { continue; }
		$last = max(array_keys($days));
		/* a LinkedIn (Windsor.ai) naponta frissül: az utolsó meglévő nap számít */
		$ref = $ch === 'linkedin' ? $last : $y;
		$base = array_filter($days, function ($k) use ($ref) { return $k < $ref; }, ARRAY_FILTER_USE_KEY);
		$bn = count($base);
		if ($bn < 5) { continue; }
		$ms = array_sum(array_column($base, 's')) / $bn;
		$mc = array_sum(array_column($base, 'c')) / $bn;
		$mv = array_sum(array_column($base, 'v')) / $bn;
		$cpc = $mc > 0 ? $ms / $mc : 0;
		$t = isset($days[$ref]) ? $days[$ref] : array('s' => 0, 'c' => 0, 'v' => 0, 'i' => 0);
		$s = (float) $t['s']; $c = (int) $t['c'];
		$lbl = $ref === $y ? 'tegnap' : str_replace('-', '.', substr($ref, 5)) . '.';
		if ($ms >= 1000 && $s <= 0) { $add('amber', $ch, $lbl . ' nem költött (átlag ' . cp_st_al_ft($ms) . '/nap) – leállt a kampány, vagy elfogyott a keret?'); continue; }
		if ($ms >= 1000 && $s > 2 * $ms) { $add('amber', $ch, $lbl . ' ' . cp_st_al_ft($s) . ' költés, ' . round($s / $ms, 1) . '× a szokásos napi átlagnak (' . cp_st_al_ft($ms) . ')'); }
		elseif ($ms >= 1000 && $s < 0.4 * $ms) { $add('amber', $ch, $lbl . ' csak ' . cp_st_al_ft($s) . ' költés, a szokásos ' . cp_st_al_ft($ms) . ' helyett (−' . round((1 - $s / $ms) * 100) . '%)'); }
		/* költ, de nincs kattintás (3 nap) */
		$k3 = array_slice(array_keys($days), -3);
		$s3 = 0; $c3 = 0;
		foreach ($k3 as $k) { $s3 += (float) $days[$k]['s']; $c3 += (int) $days[$k]['c']; }
		if ($s3 >= 1500 && $c3 === 0) { $add('red', $ch, 'az utolsó 3 napban ' . cp_st_al_ft($s3) . ' költés, de 0 kattintás – ellenőrizd a hirdetést / célzást'); }
		elseif ($c >= 5 && $cpc > 0 && $s / $c > 2 * $cpc) { $add('amber', $ch, $lbl . ' a kattintás ára ' . cp_st_al_ft($s / $c) . ', a szokásos ' . cp_st_al_ft($cpc) . ' kétszerese'); }
		/* a platform szerint nincs lead (3 nap), pedig szokott lenni */
		$v3 = 0; foreach ($k3 as $k) { $v3 += (float) $days[$k]['v']; }
		if ($mv >= 0.7 && $s3 > 0 && $v3 <= 0) { $add('amber', $ch, '3 napja nincs platform szerinti lead/konverzió (átlag ' . round($mv, 1) . '/nap)'); }
		/* leállt kampányok */
		$camp = $wpdb->get_results($wpdb->prepare("SELECT campaign_name n, SUM(CASE WHEN d < %s THEN spend ELSE 0 END) / %d b, SUM(CASE WHEN d = %s THEN spend ELSE 0 END) t FROM $A WHERE channel = %s AND d BETWEEN %s AND %s GROUP BY campaign_id, campaign_name HAVING b >= 2000 AND t = 0", $ref, max(1, $bn), $ref, $ch, $b0, $ref), ARRAY_A);
		foreach ($camp as $x) { $add('info', $ch, '„' . $x['n'] . '” kampány ' . $lbl . ' nem költött (átlag ' . cp_st_al_ft($x['b']) . '/nap)'); }
	}
	/* frissesség */
	$st = cp_st_state();
	$si = cp_st_sync_info();
	$age = function ($t) { return (time() - (int) $t) / 3600; };
	if (!empty($si['gads']['on']) && $si['gads']['at'] && $age($si['gads']['at']) > 3) { $add('red', 'google', 'a Google Ads-szkript ' . round($age($si['gads']['at'])) . ' órája nem küldött adatot (óránként kellene) – ellenőrizd az ütemezést'); }
	if (!empty($si['meta']['on']) && (!empty($si['meta']['err']) || ($si['meta']['ok'] && $age($si['meta']['ok']) > 3))) { $add('red', 'meta', !empty($si['meta']['err']) ? 'lekérési hiba: ' . $si['meta']['err'] : 'az adatok ' . round($age($si['meta']['ok'])) . ' órásak'); }
	if (!empty($si['li']['on']) && (!empty($si['li']['err']) || ($si['li']['ok'] && $age($si['li']['ok']) > 30))) { $add('amber', 'linkedin', !empty($si['li']['err']) ? 'Windsor.ai hiba: ' . $si['li']['err'] : 'az adatok ' . round($age($si['li']['ok'])) . ' órásak'); }
	$ord = array('red' => 0, 'amber' => 1, 'info' => 2);
	usort($items, function ($a, $b) use ($ord) { return $ord[$a['sev']] - $ord[$b['sev']]; });
	$nwarn = count(array_filter($items, function ($x) { return $x['sev'] !== 'info'; }));
	return array('id' => 'ads', 'title' => 'Hirdetési anomáliák', 'sev' => $sev, 'big' => $nwarn,
		'text' => $items ? ($nwarn ? $nwarn . ' eltérés a szokásostól (az utolsó teljes nap a megelőző 14 nap átlagához mérve).' : 'Nincs komoly eltérés, csak tájékoztató tételek.') : 'A költés, a kattintások és a leadek a szokásos sávban vannak.',
		'items' => $items);
}

/* szezonális összevetés: az utolsó 30 nap a korábbi évek azonos időszakához; előfoglaltság a következő 3 hónapra */
function cp_st_al_season($today) {
	global $wpdb;
	$D = cp_st_t('deals');
	$out = array();
	$f = gmdate('Y-m-d', strtotime($today . ' 12:00 -29 days'));
	$cnt = function ($col, $a, $b) use ($wpdb, $D) { return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $D WHERE $col BETWEEN %s AND %s", $a, $b)); };
	foreach (array(array('created_d', 'megkeresés'), array('signed_d', 'aláírt deal')) as $m) {
		$cur = $cnt($m[0], $f, $today);
		$prev = array();
		for ($k = 1; $k <= 3; $k++) {
			$a = (new DateTime($f, cp_st_tz()))->modify('-' . $k . ' year')->format('Y-m-d');
			$b = (new DateTime($today, cp_st_tz()))->modify('-' . $k . ' year')->format('Y-m-d');
			$n = $cnt($m[0], $a, $b);
			/* csak az az év számít, amelyből van adat (a Salesmate-ben hiányos évek kimaradnak) */
			if ($n > 0) { $prev[substr($a, 0, 4)] = $n; }
		}
		if (!$prev) { continue; }
		$avg = array_sum($prev) / count($prev);
		$pct = $avg > 0 ? round(($cur - $avg) / $avg * 100) : 0;
		$parts = array();
		foreach ($prev as $yy => $n) { $parts[] = $yy . ': ' . $n; }
		$out[] = array('id' => 'season_' . $m[0], 'title' => 'Szezonális összevetés – ' . $m[1],
			'sev' => $pct <= -15 ? 'amber' : ($pct >= 15 ? 'good' : 'info'), 'big' => ($pct > 0 ? '+' : '') . $pct . '%',
			'text' => 'Az utolsó 30 napban ' . $cur . ' ' . $m[1] . ', a korábbi évek azonos időszakának átlaga ' . round($avg) . ' (' . implode(', ', $parts) . ').' . ($pct <= -15 ? ' Az év ezen szakához képest kevesebb – érdemes lehet hirdetést / ajánlatkövetést erősíteni.' : ''));
	}
	/* kereslet a következő hónapokra: az adott hónap rendezvényeire eddig beérkezett megkeresések, a tavalyi év ugyanezen napjához mérve
	 * (a megkeresés dátuma megbízható; az aláírás dátuma a régi dealeknél csak a Won-lezárásból ismert, ezért azt nem vetjük össze) */
	$items = array(); $worst = 0;
	$ly = (new DateTime($today, cp_st_tz()))->modify('-1 year')->format('Y-m-d');
	$m0 = new DateTime(substr($today, 0, 7) . '-01', cp_st_tz());
	$hu = array('', 'január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
	$q = function ($a, $b, $upto) use ($wpdb, $D) { return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $D WHERE event_d BETWEEN %s AND %s AND created_d <= %s", $a, $b, $upto)); };
	$qs = function ($a, $b, $upto) use ($wpdb, $D) { return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $D WHERE event_d BETWEEN %s AND %s AND created_d <= %s AND signed_d IS NOT NULL", $a, $b, $upto)); };
	for ($k = 0; $k < 4; $k++) {
		$ms = (clone $m0)->modify('+' . $k . ' month');
		$a = $ms->format('Y-m-01'); $b = $ms->format('Y-m-t');
		$la = (clone $ms)->modify('-1 year')->format('Y-m-01'); $lb = (clone $ms)->modify('-1 year')->format('Y-m-t');
		$now_n = $q($a, $b, $today);
		$was = $q($la, $lb, $ly);
		$fin = $q($la, $lb, '9999-12-31');
		$fins = $qs($la, $lb, '9999-12-31');
		if ($was < 3) { continue; }
		$pct = round(($now_n - $was) / $was * 100);
		$worst = min($worst, $pct);
		$items[] = array('id' => '', 't' => $ms->format('Y') . '. ' . $hu[(int) $ms->format('n')], 'd' => '', 'v' => null,
			'st' => $now_n . ' megkeresés eddig, tavaly ugyanekkor ' . $was . ' (' . ($pct > 0 ? '+' : '') . $pct . '%); tavaly végül ' . $fin . ' megkeresés, ' . $fins . ' aláírt', 'sev' => $pct <= -20 ? 'amber' : 'info');
	}
	if ($items) {
		$out[] = array('id' => 'pace', 'title' => 'Kereslet a következő hónapokra', 'sev' => $worst <= -20 ? 'amber' : 'info', 'big' => count($items) . ' hó',
			'text' => 'Az adott hónap rendezvényeire eddig beérkezett megkeresések, a tavalyi év ugyanezen napjához mérve.' . ($worst <= -20 ? ' Van hónap, ahol jelentősen elmaradunk a tavalyitól – érdemes rá célzott kampányt indítani.' : ''),
			'items' => $items);
	}
	return $out;
}

add_action('wp_ajax_nopriv_cp_st_mail_ok', 'cp_st_ajax_mail_ok');
add_action('wp_ajax_cp_st_mail_ok', 'cp_st_ajax_mail_ok');
function cp_st_ajax_mail_ok() {
	cp_el_auth();
	$cid = preg_replace('/[^0-9]/', '', isset($_POST['cid']) ? (string) wp_unslash($_POST['cid']) : '');
	$rv = isset($_POST['rv']) ? preg_replace('/[^0-9: -]/', '', (string) wp_unslash($_POST['rv'])) : '';
	if ($cid === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rv)) { cp_el_err('Hibás kérés.'); }
	$m = get_option('cp_st_mail_ok', array());
	if (!is_array($m)) { $m = array(); }
	if (!empty($_POST['undo'])) { unset($m[$cid]); } else { $m[$cid] = $rv; }
	/* 60 napnál régebbi jelölések törlése */
	$lim = gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS);
	foreach ($m as $k => $v) { if ($v < $lim) { unset($m[$k]); } }
	update_option('cp_st_mail_ok', $m, false);
	cp_st_bump();
	wp_send_json_success(cp_st_alerts());
}

add_action('wp_ajax_nopriv_cp_st_alerts', 'cp_st_ajax_alerts');
add_action('wp_ajax_cp_st_alerts', 'cp_st_ajax_alerts');
function cp_st_ajax_alerts() {
	cp_el_auth();
	if (!cp_st_db_ok()) { cp_el_err('Az adatbázis még készül.', 503); }
	/* „élő”: ha valaki nézi a fület, és 10 percnél régebbi a gyors szinkron, most frissít */
	$st = cp_st_state();
	if (time() - (int) (isset($st['quick_at']) ? $st['quick_at'] : 0) > 10 * MINUTE_IN_SECONDS) { cp_st_quick_sync(false); }
	$ck = 'cp_st_al_' . md5(get_option('cp_st_ver', '') . cp_st_today() . gmdate('YmdHi'));
	$c = get_transient($ck);
	if (!is_array($c)) {
		$c = cp_st_alerts();
		set_transient($ck, $c, 90);
	}
	wp_send_json_success($c);
}
