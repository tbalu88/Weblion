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
	$data = array(); foreach ($F as $k => $x) { if ($cur[$k] !== '') { $data[$k] = $cur[$k]; } }
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
