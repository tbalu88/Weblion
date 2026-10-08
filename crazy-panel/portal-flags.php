<?php
/* Ügyfélportál – figyelmeztető címkék és „Helyszíni infó” blokk
 * Egyetlen címke-függvény (cp_portal_flags_build) dolgozik a kezelői adatlapnak, a napi munkalapnak
 * és az Elszámolásnak, így a szabályok egy helyen élnek. Helyek: adatlap | munkalap | elszamolas */

if (!defined('ABSPATH')) { exit; }

/* a specifikáció szerinti név */
function crazy_portal_flags($deal_id) {
	$portal = cp_portal_get_by_deal($deal_id);
	if (!$portal) { return array(); }
	return cp_portal_flags_build($portal, cp_portal_answers($portal->id), cp_portal_state($portal->id));
}

/* címkék: array(array('code', 'text', 'tone' => bad|warn|info, 'where' => array(...)), …) */
function cp_portal_flags_build($portal, $answers, $state) {
	$all = array('adatlap', 'munkalap', 'elszamolas');
	$out = array();
	$add = function ($code, $text, $tone, $where) use (&$out) { $out[] = array('code' => $code, 'text' => $text, 'tone' => $tone, 'where' => $where); };
	$g = function ($k, $sub = null) use ($answers) {
		if (!isset($answers[$k])) { return ''; }
		$v = $answers[$k];
		if ($sub !== null) { return is_array($v) && isset($v[$sub]) ? (string) $v[$sub] : ''; }
		return is_scalar($v) ? (string) $v : '';
	};
	$lt = $g('location_type');
	if ($lt === 'outdoor_covered') { $add('kulteri_fedett', 'KÜLTÉRI, FEDETT', 'warn', $all); }
	if ($lt === 'outdoor_open') { $add('kulteri_fedetlen', 'KÜLTÉRI, FEDETLEN', 'bad', $all); }
	$pw = $g('power_within_10m');
	if ($pw === 'no') { $add('nincs_aram', 'NINCS ÁRAM', 'bad', $all); }
	if ($pw === 'unknown') { $add('aram_nem_tudja', 'ÁRAM: NEM TUDJA', 'warn', $all); }
	$sp = $g('space_ok', 'answer');
	if ($sp === 'no') { $add('keves_hely', 'KEVÉS HELY', 'bad', $all); }
	if ($sp === 'unknown') { $add('hely_nem_tudja', 'HELY: NEM TUDJA', 'warn', $all); }
	if ($g('access_route', 'type') === 'stairs_only') {
		$fl = $g('access_route', 'floors');
		$add('csak_lepcso', 'CSAK LÉPCSŐ' . ($fl !== '' ? ' · ' . $fl . '. EMELET' : ''), 'bad', $all);
	}
	if ($g('vehicle_access', 'type') === 'permit_needed') { $add('behajtas', 'BEHAJTÁSI ENGEDÉLY KELL', 'warn', array('adatlap', 'munkalap')); }
	if ($g('parking', 'type') === 'paid') { $add('fizetos_parkolas', 'FIZETŐS PARKOLÁS', 'info', array('elszamolas')); }
	if ($state && !empty($state->changed_late_at)) {
		$add('modositva', 'MÓDOSÍTVA ' . wp_date('m.d. H:i', cp_portal_db_ts($state->changed_late_at), cp_portal_tz()), 'warn', array('adatlap', 'munkalap'));
	}
	$done = $state ? (int) $state->required_done : 0;
	if ($done < cp_portal_required_total()) { $add('hianyos', 'ADATLAP HIÁNYOS (' . $done . '/' . cp_portal_required_total() . ')', 'bad', $all); }
	return $out;
}

function cp_portal_flags_where($flags, $where) {
	return array_values(array_filter($flags, function ($f) use ($where) { return in_array($where, $f['where'], true); }));
}

/* olvasható összefoglaló a panelnek és a „Helyszíni infó” blokknak */
function cp_portal_info($portal, $answers) {
	$f = cp_portal_fields();
	$opt = function ($k, $v, $part = null) use ($f) {
		$o = $part === null ? (isset($f[$k]['options']) ? $f[$k]['options'] : array()) : (isset($f[$k]['parts'][$part]['options']) ? $f[$k]['parts'][$part]['options'] : array());
		return isset($o[$v]) ? $o[$v] : (string) $v;
	};
	$a = function ($k) use ($answers) { return isset($answers[$k]) ? $answers[$k] : null; };
	$i = array();
	$va = $a('venue_address');
	$addr = is_array($va) ? trim(implode(', ', array_filter(array(isset($va['name']) ? $va['name'] : '', isset($va['address']) ? $va['address'] : ''))), ', ') : '';
	$i['venue'] = $addr;
	$i['maps'] = is_array($va) && !empty($va['address']) ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim((isset($va['name']) ? $va['name'] . ', ' : '') . $va['address'])) : '';
	$i['room'] = is_string($a('venue_room')) ? $a('venue_room') : '';
	$c = $a('onsite_contact');
	$i['contact'] = is_array($c) ? array('name' => isset($c['name']) ? $c['name'] : '', 'phone' => isset($c['phone']) ? $c['phone'] : '', 'tel' => isset($c['phone']) ? preg_replace('/[^\d+]/', '', $c['phone']) : '') : null;
	$s = $a('schedule');
	$i['schedule'] = is_array($s) ? array('arrival' => isset($s['arrival']) ? $s['arrival'] : '', 'start' => isset($s['start']) ? $s['start'] : '', 'end' => isset($s['end']) ? $s['end'] : '') : null;
	$p = $a('parking');
	$i['parking'] = is_array($p) && !empty($p['type']) ? $opt('parking', $p['type'], 'type') . (!empty($p['note']) ? ' – ' . $p['note'] : '') : '';
	$v = $a('vehicle_access');
	$i['vehicle'] = is_array($v) && !empty($v['type']) ? $opt('vehicle_access', $v['type'], 'type') . (!empty($v['window']) ? ' (' . $v['window'] . ')' : '') . (!empty($v['note']) ? ' – ' . $v['note'] : '') : '';
	$ar = $a('access_route');
	$i['access'] = is_array($ar) && !empty($ar['type']) ? $opt('access_route', $ar['type'], 'type') . (!empty($ar['floors']) ? ', ' . $ar['floors'] . '. emelet' : '') . (!empty($ar['note']) ? ' – ' . $ar['note'] : '') : '';
	$g = $a('guests_type_dress');
	$i['guests'] = is_array($g) ? trim(implode(' · ', array_filter(array(!empty($g['guests']) ? $g['guests'] . ' fő' : '', isset($g['type']) ? $g['type'] : '', !empty($g['dress_code']) ? 'dress code: ' . $g['dress_code'] : '')))) : '';
	$i['special'] = is_string($a('special_request')) ? $a('special_request') : '';
	return $i;
}

/* ------------------------------------------------------------ „Helyszíni infó” a kezelői adatlap tetején */

function cp_portal_info_block($deal_id) {
	if (!is_string($deal_id) || !preg_match('/^\d{1,20}$/', $deal_id)) { return ''; }
	$portal = cp_portal_get_by_deal($deal_id);
	if (!$portal) { return ''; }
	$answers = cp_portal_answers($portal->id);
	$flags = cp_portal_flags_where(cp_portal_flags_build($portal, $answers, cp_portal_state($portal->id)), 'adatlap');
	$i = cp_portal_info($portal, $answers);
	$h = '<link rel="stylesheet" href="' . esc_url(plugins_url('assets/portal.css', CP_PORTAL_MAIN)) . '?v=' . CP_PORTAL_VER . '">';
	$h .= '<section class="cpp-info"><h2>Helyszíni infó <small>az ügyfél adatlapjáról</small></h2>';
	if ($flags) {
		$h .= '<div class="cpp-flags">';
		foreach ($flags as $f) { $h .= '<span class="cpp-flag ' . esc_attr($f['tone']) . '">' . esc_html($f['text']) . '</span>'; }
		$h .= '</div>';
	}
	$rows = array();
	if ($i['venue'] !== '') {
		$rows[] = array('Helyszín', esc_html($i['venue']) . ($i['room'] !== '' ? '<br><small>' . esc_html($i['room']) . '</small>' : '') . ($i['maps'] ? ' <a href="' . esc_url($i['maps']) . '" target="_blank" rel="noopener">térkép ↗</a>' : ''));
	}
	if ($i['access'] !== '') { $rows[] = array('Bejutás', esc_html($i['access'])); }
	if ($i['contact'] && $i['contact']['name'] !== '') {
		$rows[] = array('Kapcsolattartó', esc_html($i['contact']['name']) . ($i['contact']['tel'] !== '' ? ' · <a href="tel:' . esc_attr($i['contact']['tel']) . '">' . esc_html($i['contact']['phone']) . '</a>' : ''));
	}
	if ($i['schedule']) {
		$rows[] = array('Menetrend', esc_html(implode(' · ', array_filter(array($i['schedule']['arrival'] !== '' ? 'érkezés ' . $i['schedule']['arrival'] : '', $i['schedule']['start'] !== '' ? 'indulás ' . $i['schedule']['start'] : '', $i['schedule']['end'] !== '' ? 'zárás ' . $i['schedule']['end'] : '')))));
	}
	if ($i['vehicle'] !== '') { $rows[] = array('Behajtás', esc_html($i['vehicle'])); }
	if ($i['parking'] !== '') { $rows[] = array('Parkolás', esc_html($i['parking'])); }
	if ($i['guests'] !== '') { $rows[] = array('Létszám', esc_html($i['guests'])); }
	if ($i['special'] !== '') { $rows[] = array('Különleges kérés', nl2br(esc_html($i['special']))); }
	if ($rows) {
		$h .= '<dl>';
		foreach ($rows as $r) { $h .= '<dt>' . esc_html($r[0]) . '</dt><dd>' . $r[1] . '</dd>'; }
		$h .= '</dl>';
	} else {
		$h .= '<p class="cpp-muted">Az ügyfél még nem töltötte ki az adatlapot.</p>';
	}
	$h .= '</section>';
	return $h;
}

/* ------------------------------------------------------------ panel-összefoglaló több dealre
 * deal_id => array(st, done, total, flags, info, dl) – a rendezvénylista, a napi munkalap és az Elszámolás kapja */
function cp_portal_panel_map($deal_ids) {
	$portals = cp_portal_get_many($deal_ids);
	if (!$portals) { return new stdClass(); }
	$pids = array();
	foreach ($portals as $p) { $pids[] = (int) $p->id; }
	$ans = cp_portal_answers_many($pids);
	$sts = cp_portal_states_many($pids);
	$out = array();
	foreach ($portals as $did => $p) {
		$a = isset($ans[(int) $p->id]) ? $ans[(int) $p->id] : array();
		$st = isset($sts[(int) $p->id]) ? $sts[(int) $p->id] : cp_portal_state($p->id);
		$di = cp_portal_deadline_info($p);
		$out[(string) $did] = array(
			'st' => $st->status,
			'done' => (int) $st->required_done,
			'total' => cp_portal_required_total(),
			'flags' => cp_portal_flags_build($p, $a, $st),
			'info' => cp_portal_info($p, $a),
			'dl' => $di ? $di['text'] : '',
			'locked' => cp_portal_locked($p),
		);
	}
	return (object) $out;
}

/* ugyanez egy dátumtartomány portáljaira (a Követés fül percenkénti frissítéséhez) */
function cp_portal_panel_map_range($from, $to) {
	global $wpdb;
	if (!cp_portal_db_ready()) { return new stdClass(); }
	$ids = (array) $wpdb->get_col($wpdb->prepare('SELECT deal_id FROM ' . cp_portal_t('portals') . ' WHERE event_date BETWEEN %s AND %s', $from, $to));
	return cp_portal_panel_map($ids);
}
