<?php
/**
 * Crazy panel – az ügyfél-adatlap (portál) hasznos válaszai a deal adatlapján és a naptárbejegyzésben (v2.9.165).
 * Csak olvas a portálból; a Salesmate-be nem ír. A „Naptárbejegyzés frissítése” gomb a cégnaptár bejegyzését frissíti
 * (ha nincs, létrehozza) a deal és az adatlap mostani adataival.
 */
if (!defined('ABSPATH')) { exit; }

function cp_pinfo_keys() { return array('schedule', 'venue_room', 'location_type', 'power_within_10m', 'space_ok', 'access_route', 'arrival', 'vehicle_access', 'parking', 'onsite_contact', 'guests_type_dress', 'background', 'template_mode', 'special_request'); }

/* egy válasz olvasható szövege a mező leírása alapján (választható érték → felirat, részek → „címke: érték”) */
function cp_pinfo_text($f, $v) {
	$yn = array('yes' => 'igen', 'no' => 'nem', 'unknown' => 'nem tudja');
	$one = function ($def, $x) use ($yn) {
		if (is_bool($x)) { return $x ? 'igen' : 'nem'; }
		if (is_array($x)) { return implode(', ', array_filter(array_map('strval', array_filter($x, 'is_scalar')))); }
		$x = trim((string) $x); if ($x === '') { return ''; }
		if (!empty($def['options'][$x])) { return (string) $def['options'][$x]; }
		return $yn[$x] ?? $x;
	};
	if (!empty($f['parts']) && is_array($v)) {
		$o = array();
		foreach ($f['parts'] as $pk => $pd) { if (!isset($v[$pk])) { continue; } $t = $one($pd, $v[$pk]); if ($t !== '') { $o[] = (count($f['parts']) > 1 ? mb_strtolower((string) ($pd['label'] ?? $pk)) . ': ' : '') . $t; } }
		return implode(' · ', $o);
	}
	return $one($f, $v);
}

/* a deal adatlap-válaszai: [címke, szöveg] sorok + nyers kezdés/befejezés */
function cp_pinfo_rows($deal_id) {
	if (!function_exists('cp_portal_get_by_deal') || !function_exists('cp_portal_db_ready') || !cp_portal_db_ready()) { return null; }
	$p = cp_portal_get_by_deal((string) (int) $deal_id);
	if (!$p) { return null; }
	$a = cp_portal_answers($p->id);
	$F = cp_portal_fields();
	$rows = array();
	foreach (cp_pinfo_keys() as $k) {
		if (!isset($a[$k]) || !isset($F[$k]) || (function_exists('cp_portal_is_empty_value') && cp_portal_is_empty_value($a[$k]))) { continue; }
		if ($k === 'schedule' && is_array($a[$k])) { $t = trim(($a[$k]['start'] ?? '') . (!empty($a[$k]['end']) ? '–' . $a[$k]['end'] : '')); }
		else { $t = cp_pinfo_text($F[$k], $a[$k]); }
		if ($t === '') { continue; }
		$rows[] = array('k' => $k, 'l' => (string) ($F[$k]['qlabel'] ?? $F[$k]['label'] ?? $k), 't' => mb_substr($t, 0, 400));
	}
	$sch = isset($a['schedule']) && is_array($a['schedule']) ? $a['schedule'] : array();
	return array('rows' => $rows, 'start' => preg_match('/^\d{1,2}:\d{2}$/', (string) ($sch['start'] ?? '')) ? (string) $sch['start'] : '', 'end' => preg_match('/^\d{1,2}:\d{2}$/', (string) ($sch['end'] ?? '')) ? (string) $sch['end'] : '');
}

/* a deal adatlapjára */
add_filter('cp_crm_deal_data', function ($x) {
	$i = cp_pinfo_rows((int) $x['deal']['id']);
	$x['pinfo'] = $i ? $i['rows'] : array();
	$x['gcal_on'] = function_exists('cp_gcal_ok') && cp_gcal_ok() ? 1 : 0;
	return $x;
}, 20);

/* „Naptárbejegyzés frissítése” – a deal (minden rendezvénynapjának) cégnaptár-bejegyzése a mostani adatokkal; ha nincs, létrehozza */
add_action('wp_ajax_nopriv_cp_gcal_deal_sync', 'cp_gcal_ajax_deal_sync');
add_action('wp_ajax_cp_gcal_deal_sync', 'cp_gcal_ajax_deal_sync');
function cp_gcal_ajax_deal_sync() {
	cp_crm_ajax_guard(); /* a Deal-ek fül jogosultsága */
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (!function_exists('cp_gcal_ok') || !cp_gcal_ok()) { cp_el_err('A Google Naptár nincs bekötve.', 503); }
	$id = (int) ($_POST['id'] ?? 0);
	global $wpdb;
	$d = $wpdb->get_row($wpdb->prepare('SELECT id, status, event_d FROM ' . cp_crm_t('deals') . ' WHERE id = %d AND deleted_at IS NULL', $id), ARRAY_A);
	if (!$d) { cp_el_err('Nincs ilyen deal.', 404); }
	if (empty($d['event_d'])) { cp_el_err('A dealnek nincs rendezvénydátuma.'); }
	if ($d['status'] === 'Lost') { cp_el_err('Elvesztett deal bejegyzését nem frissítem.'); }
	$days = function_exists('cp_mday_get') ? cp_mday_align(cp_mday_get($id), (string) $d['event_d']) : array();
	$list = $days ? wp_list_pluck($days, 'day') : array((string) $d['event_d']);
	$made = get_option('cp_gcal_made', array()); if (!is_array($made)) { $made = array(); }
	$res = array();
	foreach ($list as $n => $day) {
		$p = cp_gcal_plan($id, $days ? $day : '');
		if (!$p || $p['date'] === '') { continue; }
		$ev = cp_gcal_events($day, $day, true);
		if (is_wp_error($ev)) { cp_el_err('A naptár nem érhető el: ' . $ev->get_error_message(), 502); }
		list($bd, $bi) = cp_gcal_index(cp_gcal_deals($day, $day));
		$hit = null;
		foreach ($ev as $e) { if (cp_gcal_match_key($e, $bd, $bi) === (string) $p['key']) { $hit = $e; break; } }
		$body = cp_gcal_body($p);
		if ($hit) {
			$body['summary'] = cp_gcal_pen($hit['t']) ? CP_GCAL_PEN . cp_gcal_unpen($p['title']) : $p['title'];
			$r = cp_gcal_req('PATCH', '/events/' . rawurlencode($hit['id']), array(), $body);
			if (is_wp_error($r)) { cp_el_err('Nem sikerült frissíteni: ' . $r->get_error_message(), 502); }
			$res[] = 'frissítve (' . str_replace('-', '.', $day) . '.)';
			cp_gcal_wlog('bejegyzés frissítve (gombbal)', $id, $p['title']);
		} else {
			$r = cp_gcal_req('POST', '/events', array(), $body);
			if (is_wp_error($r)) { cp_el_err('Nem sikerült létrehozni: ' . $r->get_error_message(), 502); }
			$made[(string) $p['key']] = (string) ($r['id'] ?? '1'); update_option('cp_gcal_made', $made, false);
			$res[] = 'létrehozva (' . str_replace('-', '.', $day) . '.)';
			cp_gcal_wlog('bejegyzés létrehozva (gombbal)', $id, $p['title']);
		}
	}
	if (function_exists('cp_audit_add')) { cp_audit_add('naptar', 'gcal_sync', 'Naptárbejegyzés frissítése', 'deal:' . $id, implode(', ', $res)); }
	wp_send_json_success(array('msg' => 'Naptárbejegyzés ' . implode(', ', $res) . '.'));
}
