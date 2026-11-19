<?php
/**
 * v2.9.324: „Rendezvényinfó a kezelőnek” – a naptárbejegyzésekbe eddig kézzel írt adatok a deal adatlapján.
 * Csak a panelben tárolódik (a Salesmate-be nem ír); a panel ebből írja a naptárbejegyzés leírását (cp_gcal_plan).
 */
if (!defined('ABSPATH')) { exit; }

function cp_dinfo_t() { global $wpdb; return $wpdb->prefix . 'crazy_deal_info'; }
function cp_dinfo_fields() {
	return array(
		'note'  => array('Megjegyzés a kezelőnek', 'area'),
		'theme' => array('Tematika', 'text'),
		'dress' => array('Dress code', 'text'),
		'gal'   => array('Galéria neve', 'text'),
		'build' => array('Építés', 'text'),
		'tear'  => array('Bontás', 'text'),
		'photo' => array('Fotó (nyomtatás)', 'text'),
	);
}
function cp_dinfo_db() {
	static $ok = null;
	if ($ok !== null) { return $ok; }
	if (get_option('cp_dinfo_db') !== '1') {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta('CREATE TABLE ' . cp_dinfo_t() . " (
  deal_id bigint(20) unsigned NOT NULL,
  data text NOT NULL,
  by_user varchar(100) NOT NULL DEFAULT '',
  updated_at datetime NULL,
  PRIMARY KEY  (deal_id)
) " . $wpdb->get_charset_collate() . ';');
		update_option('cp_dinfo_db', '1', false);
	}
	return $ok = true;
}
function cp_dinfo_get($id) {
	global $wpdb; cp_dinfo_db();
	$r = $wpdb->get_row($wpdb->prepare('SELECT data, by_user, updated_at FROM ' . cp_dinfo_t() . ' WHERE deal_id = %d', (int) $id), ARRAY_A);
	$d = $r ? json_decode((string) $r['data'], true) : array();
	$out = array(); foreach (cp_dinfo_fields() as $k => $x) { $out[$k] = is_array($d) && isset($d[$k]) ? (string) $d[$k] : ''; }
	return $out + array('_by' => $r ? (string) $r['by_user'] : '', '_at' => $r && function_exists('cp_crm_local') ? cp_crm_local((string) $r['updated_at']) : '');
}
function cp_dinfo_raw($id) {
	global $wpdb; cp_dinfo_db();
	$d = json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT data FROM ' . cp_dinfo_t() . ' WHERE deal_id = %d', (int) $id)), true);
	return is_array($d) ? $d : array();
}
/* részleges mentés: csak a megadott mezők változnak; üres érték törli a mezőt */
function cp_dinfo_set($id, $vals, $who = '') {
	global $wpdb; cp_dinfo_db();
	$cur = cp_dinfo_get($id); $F = cp_dinfo_fields(); $ch = array();
	foreach ((array) $vals as $k => $v) {
		if (!isset($F[$k]) || !is_scalar($v)) { continue; }
		$v = $F[$k][1] === 'area' ? mb_substr(trim(sanitize_textarea_field((string) $v)), 0, 2000) : mb_substr(trim(sanitize_text_field((string) $v)), 0, 200);
		if ($v !== $cur[$k]) { $cur[$k] = $v; $ch[] = $F[$k][0]; }
	}
	if (!$ch) { return $cur; }
	/* v2.9.347: a sor többi adata (gépszám, hátterek) megmarad */
	$data = cp_dinfo_raw($id); foreach ($F as $k => $x) { if ($cur[$k] !== '') { $data[$k] = $cur[$k]; } else { unset($data[$k]); } }
	$wpdb->replace(cp_dinfo_t(), array('deal_id' => (int) $id, 'data' => wp_json_encode($data, JSON_UNESCAPED_UNICODE), 'by_user' => mb_substr($who !== '' ? $who : (function_exists('cp_panel_who') ? cp_panel_who() : ''), 0, 100), 'updated_at' => gmdate('Y-m-d H:i:s')));
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'dinfo', 'Rendezvényinfó módosítva', 'deal:' . (int) $id, implode(', ', $ch)); }
	return cp_dinfo_get($id);
}
add_action('wp_ajax_nopriv_cp_dinfo_save', 'cp_dinfo_ajax_save');
add_action('wp_ajax_cp_dinfo_save', 'cp_dinfo_ajax_save');
function cp_dinfo_ajax_save() {
	cp_el_auth();
	cp_tab_need(array('deals'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$id = (int) ($_POST['id'] ?? 0);
	$f = json_decode((string) wp_unslash($_POST['f'] ?? ''), true);
	if ($id <= 0 || !is_array($f)) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $id))) { cp_el_err('Nincs ilyen deal.', 404); }
	wp_send_json_success(array('dinfo' => cp_dinfo_set($id, $f), 'fields' => cp_dinfo_fields()));
}
/* a deal adatlapjára (crm.js: x.dinfo, x.dinfoF) */
add_filter('cp_crm_deal_data', function ($x) {
	$id = (int) ($x['deal']['id'] ?? 0);
	if ($id) { $x['dinfo'] = cp_dinfo_get($id); $x['dinfoF'] = cp_dinfo_fields(); }
	return $x;
}, 21);

/* v2.9.344: Kezelői adatlap a deal adatlapján – rendezvénynaponként a kitöltő link és a kezelők válaszai (csak olvasva; szerkeszteni az Elszámolásban) */
function cp_dinfo_esheet($id) {
	if (!function_exists('cp_el_link') || !function_exists('cp_el_st_get')) { return null; }
	$keys = array(array((string) $id, (string) ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT event_d FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $id)) ?: ''), 1));
	if (function_exists('cp_mday_get')) { $n = 0; foreach ((array) cp_mday_get($id) as $r) { $n++; if ($n === 1) { $keys[0][1] = (string) $r['day']; continue; } $keys[] = array($id . ':' . $r['day'], (string) $r['day'], $n); } }
	$L = array('k' => 'Kezelő', 'ko' => 'Kezelő (kézi)', 'h' => 'Óraszám', 'hc' => 'Óraszám (kézi)', 'sv' => 'Szolgáltatás', 'sa' => 'Elégedettség', 'gm' => 'Gép működése', 'gmn' => 'Gép megjegyzés', 'pa' => 'Papír', 'pt' => 'Nyomtató', 'pk' => 'Parkolás', 'jt' => 'Jatt', 'rhx' => 'Hosszabbítás', 'no' => 'Megjegyzés');
	$out = array();
	foreach ($keys as $k) {
		$st = cp_el_st_get($k[0]); $rows = array(); $at = 0;
		foreach ((array) ($st['r'] ?? array()) as $r) {
			if (!is_array($r)) { continue; }
			$kv = array(); foreach ($L as $f => $lab) { if (isset($r[$f]) && trim((string) $r[$f]) !== '') { $kv[] = array($lab, (string) $r[$f]); } }
			$filled = !empty($r['fu']); if ($filled) { $at = max($at, (int) $r['fu']); }
			if ($kv || $filled) { $rows[] = array('kv' => $kv, 'filled' => $filled ? 1 : 0, 'ok' => !empty($r['ok']) ? 1 : 0, 'at' => $filled ? wp_date('Y.m.d. H:i', (int) $r['fu']) : ''); }
		}
		$out[] = array('key' => $k[0], 'day' => $k[1], 'n' => $k[2], 'link' => cp_el_link($k[0]), 'rows' => $rows, 'filled' => $at ? 1 : 0, 'at' => $at ? wp_date('Y.m.d. H:i', $at) : '');
	}
	return $out;
}
add_filter('cp_crm_deal_data', function ($x) {
	$id = (int) ($x['deal']['id'] ?? 0);
	if ($id) { $e = cp_dinfo_esheet($id); if ($e !== null) { $x['esheet'] = $e; } }
	return $x;
}, 22);

/* ---------------------------------------------------------------- v2.9.347: gépek – szelfigépek és spinnerek száma, gépenkénti háttér
   (a felhasználó döntése, 2026-11-19: csak a panelben; a Salesmate „Választott Fotófal” mezőjébe a hátterek összefűzve).
   Nincs megadva (null) = a mostani működés: a szolgáltatás szerint 1 szelfigép és/vagy 1 spinner. */
function cp_dmach_get($id) {
	$d = cp_dinfo_raw($id);
	return array('ns' => isset($d['ns']) ? (int) $d['ns'] : null, 'np' => isset($d['np']) ? (int) $d['np'] : null, 'bgs' => isset($d['bgs']) && is_array($d['bgs']) ? array_values(array_map('strval', $d['bgs'])) : array());
}
/* sok dealre egyszerre: id => array(ns|null, np|null) – csak ahol meg van adva */
function cp_dmach_many($ids) {
	global $wpdb; static $mem = array();
	$ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids)))); $out = array(); $need = array();
	foreach ($ids as $i) { if (array_key_exists($i, $mem)) { if ($mem[$i]) { $out[$i] = $mem[$i]; } } else { $need[] = $i; } }
	if ($need && cp_dinfo_db()) {
		foreach (array_chunk($need, 500) as $ch) {
			$got = array();
			foreach ((array) $wpdb->get_results('SELECT deal_id, data FROM ' . cp_dinfo_t() . ' WHERE deal_id IN (' . implode(',', $ch) . ')', ARRAY_A) as $r) {
				$d = json_decode((string) $r['data'], true); if (!is_array($d) || (!isset($d['ns']) && !isset($d['np']))) { continue; }
				$got[(int) $r['deal_id']] = array(isset($d['ns']) ? (int) $d['ns'] : null, isset($d['np']) ? (int) $d['np'] : null);
			}
			foreach ($ch as $i) { $mem[$i] = $got[$i] ?? null; if (isset($got[$i])) { $out[$i] = $got[$i]; } }
		}
	}
	return $out;
}
/* egy deal gépszáma (szelfigép, spinner): a megadott, különben a szolgáltatás szerint (1 / 0) */
function cp_dmach_n($deal, $kind) {
	$m = cp_dmach_many(array((int) $deal))[(int) $deal] ?? array(null, null);
	$s = in_array((string) $kind, array('selfie', 'both'), true) ? 1 : 0; $p = in_array((string) $kind, array('spin', 'both'), true) ? 1 : 0;
	return array($m[0] !== null ? (int) $m[0] : $s, $m[1] !== null ? (int) $m[1] : $p);
}
function cp_dmach_set($id, $ns, $np, $bgs, $who = '') {
	global $wpdb; cp_dinfo_db();
	$data = cp_dinfo_raw($id); $old = cp_dmach_get($id);
	$norm = function ($v) { if ($v === null || $v === '') { return null; } $v = (int) $v; return max(0, min(10, $v)); };
	$ns = $norm($ns); $np = $norm($np);
	if ($ns === null) { unset($data['ns']); } else { $data['ns'] = $ns; }
	if ($np === null) { unset($data['np']); } else { $data['np'] = $np; }
	$B = array(); foreach (array_slice((array) $bgs, 0, 10) as $b) { $B[] = mb_substr(trim(sanitize_text_field((string) $b)), 0, 80); }
	while ($B && end($B) === '') { array_pop($B); }
	if ($B) { $data['bgs'] = $B; } else { unset($data['bgs']); }
	$wpdb->replace(cp_dinfo_t(), array('deal_id' => (int) $id, 'data' => wp_json_encode($data, JSON_UNESCAPED_UNICODE), 'by_user' => mb_substr($who !== '' ? $who : (function_exists('cp_panel_who') ? cp_panel_who() : ''), 0, 100), 'updated_at' => gmdate('Y-m-d H:i:s')));
	$new = cp_dmach_get($id); $warn = '';
	/* a Salesmate háttérmezőbe a hátterek összefűzve (80 karakter) – csak ha változott */
	$join = implode(' + ', array_filter($new['bgs'], 'strlen'));
	if ($join !== implode(' + ', array_filter($old['bgs'], 'strlen')) && $join !== '' && function_exists('cp_crm_save_data')) {
		$jv = mb_strlen($join) > 80 ? mb_substr($join, 0, 79) . '…' : $join;
		$r = cp_crm_save_data((int) $id, array('background' => $jv), array());
		if (is_wp_error($r)) { $warn = 'A hátterek a Salesmate-be nem kerültek át: ' . $r->get_error_message(); }
	}
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'machines', 'Gépek / hátterek módosítva', 'deal:' . (int) $id, 'szelfigép: ' . ($new['ns'] === null ? 'alap' : $new['ns']) . ', spinner: ' . ($new['np'] === null ? 'alap' : $new['np']) . ($new['bgs'] ? ', hátterek: ' . implode(' + ', $new['bgs']) : '')); }
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	return array('mach' => $new, 'warn' => $warn);
}
add_action('wp_ajax_nopriv_cp_dmach_save', 'cp_dmach_ajax_save');
add_action('wp_ajax_cp_dmach_save', 'cp_dmach_ajax_save');
function cp_dmach_ajax_save() {
	cp_el_auth();
	cp_tab_need(array('deals'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$id = (int) ($_POST['id'] ?? 0);
	$bgs = json_decode((string) wp_unslash($_POST['bgs'] ?? '[]'), true);
	if ($id <= 0 || !is_array($bgs)) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $id))) { cp_el_err('Nincs ilyen deal.', 404); }
	wp_send_json_success(cp_dmach_set($id, wp_unslash($_POST['ns'] ?? ''), wp_unslash($_POST['np'] ?? ''), $bgs));
}
add_filter('cp_crm_deal_data', function ($x) {
	$id = (int) ($x['deal']['id'] ?? 0);
	if ($id) { $x['mach'] = cp_dmach_get($id); $x['bgl'] = function_exists('cp_panel_bg_list') ? array_map(function ($b) { return (string) $b['name']; }, cp_panel_bg_list()) : array(); }
	return $x;
}, 23);
