<?php
/* =====================================================================
 * v2.9.73: kontakt további e-mail-címei (csak a panelé, a Salesmate-be semmi nem íródik)
 * Tábla: {prefix}crazy_crm_contact_emails (contact_id, email UNIQUE, source, created_by, created_at).
 * Ha egy kolléga kézzel csatol dealt egy olyan szálhoz, amelynek a partnercíme egyik kontaktnál sincs meg, a levelezés
 * egyszer felajánlja, hogy a címet a deal kontaktjához vegye fel; a kontakt adatlapján a címek látszanak és törölhetők.
 * A deal-kötés szabályai (deal-link.php) és a „Deal csatolása” javaslatai ezeket is figyelembe veszik.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_CE_DB_VER', '1'); /* v2.9.73: 1 = a tábla */

function cp_ce_t() {
	global $wpdb;
	return $wpdb->prefix . 'crazy_crm_contact_emails';
}
function cp_ce_db_install() {
	global $wpdb;
	if (get_transient('cp_ce_db_inst')) { return; }
	set_transient('cp_ce_db_inst', 1, 5 * MINUTE_IN_SECONDS);
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_ce_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  contact_id bigint(20) unsigned NOT NULL,
  email varchar(190) NOT NULL,
  source varchar(20) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NULL,
  created_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY contact_id (contact_id)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_ce_db_ver', CP_CE_DB_VER, true);
	delete_transient('cp_ce_db_inst');
}
function cp_ce_db_ok() {
	static $ok = null;
	if ($ok === null) { $ok = get_option('cp_ce_db_ver') === CP_CE_DB_VER; }
	return $ok;
}
/* a crm.php / leads.php mintájára: a tábla az első nem-AJAX kérésnél készül, a verziójelző automatikusan betöltődik */
add_action('init', function () {
	if (wp_doing_ajax()) { return; }
	if (get_option('cp_ce_db_ver') === CP_CE_DB_VER) { if (function_exists('cp_opt_autoload')) { cp_opt_autoload('cp_ce_db_ver'); } return; }
	cp_ce_db_install();
}, 7);

function cp_ce_norm($e) {
	$e = strtolower(trim((string) $e));
	return is_email($e) ? $e : '';
}
/* a cím ismert-e (egy kontakt fő címe vagy további címe) */
function cp_ce_known($e) {
	global $wpdb;
	if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_crm_t('contacts') . ' WHERE email = %s AND deleted_at IS NULL', $e))) { return true; }
	return cp_ce_db_ok() && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_ce_t() . ' WHERE email = %s', $e));
}
function cp_ce_declined() { $d = get_option('cp_ce_declined', array()); return is_array($d) ? $d : array(); }

/* v2.9.73: kézi deal-csatolás után – felajánlható-e a szál partnercíme a deal kontaktjához?
   Nem: saját cím, már ismert cím, korábban „Nem”-mel elutasított cím (cím + kontakt), kontakt nélküli deal. */
function cp_ce_offer($t, $deal) {
	if (!cp_ce_db_ok() || !function_exists('cp_crm_t') || (int) $deal <= 0) { return null; }
	global $wpdb;
	$e = cp_ce_norm($t['contact_email'] ?? '');
	if ($e === '' || (function_exists('cp_imp_ours') && cp_imp_ours($e))) { return null; }
	$c = $wpdb->get_row($wpdb->prepare('SELECT c.id, c.name, c.email FROM ' . cp_crm_t('deals') . ' d JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE d.id = %d AND c.deleted_at IS NULL', (int) $deal), ARRAY_A);
	if (!$c || strtolower((string) $c['email']) === $e || cp_ce_known($e)) { return null; }
	if (isset(cp_ce_declined()[$e . '|' . (int) $c['id']])) { return null; }
	return array('e' => $e, 'cid' => (int) $c['id'], 'cn' => (string) ($c['name'] !== '' ? $c['name'] : $c['email']), 'ce' => (string) $c['email']);
}

function cp_ce_rows($cid) {
	global $wpdb;
	if (!cp_ce_db_ok()) { return array(); }
	return array_map(function ($r) { return array('id' => (int) $r['id'], 'e' => (string) $r['email'], 'src' => (string) $r['source'], 'at' => (string) $r['created_at']); },
		(array) $wpdb->get_results($wpdb->prepare('SELECT id, email, source, created_at FROM ' . cp_ce_t() . ' WHERE contact_id = %d ORDER BY id LIMIT 50', (int) $cid), ARRAY_A));
}

/* ------------------------------------------------------------ végpontok */

/* a kontakt további címei (adatlap) */
add_action('wp_ajax_nopriv_cp_ce_list', 'cp_ce_ajax_list');
add_action('wp_ajax_cp_ce_list', 'cp_ce_ajax_list');
function cp_ce_ajax_list() {
	cp_crm_ajax_guard();
	wp_send_json_success(array('rows' => cp_ce_rows((int) ($_POST['cid'] ?? 0)), 'on' => cp_ce_db_ok() ? 1 : 0));
}
/* felvétel – a levelezés ajánlatából (Levelezés jog) */
add_action('wp_ajax_nopriv_cp_ce_add', 'cp_ce_ajax_add');
add_action('wp_ajax_cp_ce_add', 'cp_ce_ajax_add');
function cp_ce_ajax_add() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_ce_db_ok() || !function_exists('cp_crm_t')) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$e = cp_ce_norm(wp_unslash($_POST['e'] ?? ''));
	$cid = (int) ($_POST['cid'] ?? 0);
	if ($e === '' || $cid <= 0) { cp_el_err('Hibás e-mail-cím.'); }
	if (function_exists('cp_imp_ours') && cp_imp_ours($e)) { cp_el_err('Saját címet nem lehet ügyfélhez felvenni.'); }
	$c = $wpdb->get_row($wpdb->prepare('SELECT id, name, email FROM ' . cp_crm_t('contacts') . ' WHERE id = %d AND deleted_at IS NULL', $cid), ARRAY_A);
	if (!$c) { cp_el_err('Nincs ilyen kontakt.', 404); }
	if (cp_ce_known($e)) { cp_el_err('Ez a cím már egy kontakthoz tartozik.'); }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$ok = $wpdb->insert(cp_ce_t(), array('contact_id' => $cid, 'email' => $e, 'source' => 'mail', 'created_by' => $me ?: null, 'created_at' => gmdate('Y-m-d H:i:s')));
	if (!$ok) { cp_el_err('A cím mentése nem sikerült.'); }
	$th = (int) ($_POST['th'] ?? 0);
	if ($th > 0 && function_exists('cp_mail_t')) {
		$wpdb->insert(cp_mail_t('events'), array('thread_id' => $th, 'kind' => 'ce', 'text' => mb_substr('Cím felvéve a kontakthoz: ' . $e . ' → ' . ($c['name'] !== '' ? $c['name'] : $c['email']), 0, 255), 'author_uid' => $me, 'author' => function_exists('cp_panel_who') ? cp_panel_who() : '', 'created_at' => gmdate('Y-m-d H:i:s')));
	}
	wp_send_json_success(array('rows' => cp_ce_rows($cid)));
}
/* „Nem” – ugyanezt a címet ehhez a kontakthoz többé nem ajánlja fel */
add_action('wp_ajax_nopriv_cp_ce_decline', 'cp_ce_ajax_decline');
add_action('wp_ajax_cp_ce_decline', 'cp_ce_ajax_decline');
function cp_ce_ajax_decline() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$e = cp_ce_norm(wp_unslash($_POST['e'] ?? ''));
	$cid = (int) ($_POST['cid'] ?? 0);
	if ($e === '' || $cid <= 0) { cp_el_err('Hibás kérés.'); }
	$d = cp_ce_declined();
	$d[$e . '|' . $cid] = time();
	if (count($d) > 1000) { asort($d); $d = array_slice($d, -1000, null, true); }
	update_option('cp_ce_declined', $d, false);
	wp_send_json_success(array('ok' => 1));
}
/* törlés – a kontakt adatlapjáról */
add_action('wp_ajax_nopriv_cp_ce_del', 'cp_ce_ajax_del');
add_action('wp_ajax_cp_ce_del', 'cp_ce_ajax_del');
function cp_ce_ajax_del() {
	cp_crm_ajax_guard();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_ce_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$id = (int) ($_POST['id'] ?? 0);
	$cid = (int) ($_POST['cid'] ?? 0);
	$wpdb->delete(cp_ce_t(), array('id' => $id, 'contact_id' => $cid));
	wp_send_json_success(array('rows' => cp_ce_rows($cid)));
}
