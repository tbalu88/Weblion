<?php
/* =====================================================================
 * Levelezés (v1.33) – a panel „Levelezés” füle: postafiók-nézet, levélíró, sablonszerkesztő.
 * Ebben a lépésben a Salesmate-hez NEM nyúl, és levelet sem küld: a sablonok a panel saját táblájában vannak,
 * a postafiók-nézet a postafiókok (IMAP/SMTP) bekötéséig üres. A változók ({{kontakt.nev}} stb.) a saját CRM-táblából
 * töltődnek ki az előnézetben.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_MAIL_DB_VER', '1');

function cp_mail_t() {
	global $wpdb;
	return $wpdb->prefix . 'crazy_mail_templates';
}

add_action('init', function () {
	if (get_option('cp_mail_db_ver') === CP_MAIL_DB_VER || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_mail_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  category varchar(80) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  body longtext NULL,
  updated_by varchar(100) NOT NULL DEFAULT '',
  updated_at datetime NULL,
  created_at datetime NULL,
  deleted_at datetime NULL,
  PRIMARY KEY  (id),
  KEY category (category)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_mail_db_ver', CP_MAIL_DB_VER, false);
}, 7);

function cp_mail_db_ok() {
	return get_option('cp_mail_db_ver') === CP_MAIL_DB_VER;
}

/* a sablonokban használható változók: kulcs => array(címke, minta) */
function cp_mail_vars() {
	return array(
		'kontakt.nev'         => array('Kontakt neve', 'Kiss Anna'),
		'kontakt.email'       => array('Kontakt e-mail-címe', 'anna@example.com'),
		'kontakt.telefon'     => array('Kontakt telefonszáma', '+36 30 123 4567'),
		'ceg.nev'             => array('Cég neve', 'Példa Kft.'),
		'deal.nev'            => array('Deal neve', '[Crazyphoto] Kiss Anna | 2026-11-14'),
		'rendezveny.datum'    => array('Rendezvény dátuma', '2026. november 14. (szombat)'),
		'rendezveny.kezdes'   => array('Kezdés időpontja', '18:00'),
		'rendezveny.helyszin' => array('Helyszín', 'Budapest, Példa utca 1.'),
		'rendezveny.letszam'  => array('Tervezett létszám', '120'),
		'csomag'              => array('Választott csomag', 'Medium – 4 óra'),
		'szolgaltatas'        => array('Szolgáltatás(ok)', 'Szelfigép, Greenbox'),
		'ertek'               => array('Érték', '190 000 Ft'),
		'portal.link'         => array('Ügyfélportál linkje', 'https://eventcrazy.hu/portal/…'),
		'felelos.nev'         => array('Felelős kolléga', 'Andrea'),
		'sajat.nev'           => array('A te neved', 'Teszt Elek'),
	);
}

/* változók értékei egy deal-ből (saját CRM-tábla) */
function cp_mail_values($deal_id) {
	global $wpdb;
	$v = array();
	foreach (cp_mail_vars() as $k => $x) { $v[$k] = ''; }
	$u = function_exists('cp_pu_current') ? cp_pu_current() : null;
	$v['sajat.nev'] = $u ? $u['n'] : '';
	if (!$deal_id || !function_exists('cp_crm_t')) { return $v; }
	$d = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_crm_t('deals') . ' WHERE id = %d', (int) $deal_id), ARRAY_A);
	if (!$d) { return $v; }
	$c = $d['contact_id'] ? $wpdb->get_row($wpdb->prepare('SELECT name, email, phone, mobile FROM ' . cp_crm_t('contacts') . ' WHERE id = %d', $d['contact_id']), ARRAY_A) : null;
	$co = $d['company_id'] ? $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . cp_crm_t('companies') . ' WHERE id = %d', $d['company_id'])) : '';
	$months = array('január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
	$days = array('vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat');
	$date = '';
	if ($d['event_d']) { $t = strtotime($d['event_d'] . ' 12:00:00'); $date = gmdate('Y', $t) . '. ' . $months[(int) gmdate('n', $t) - 1] . ' ' . (int) gmdate('j', $t) . '. (' . $days[(int) gmdate('w', $t)] . ')'; }
	$start = $d['start_at'] && function_exists('cp_crm_local') ? substr(cp_crm_local($d['start_at']), 11, 5) : '';
	$v = array_merge($v, array(
		'kontakt.nev'         => $c ? (string) $c['name'] : '',
		'kontakt.email'       => $c ? (string) $c['email'] : '',
		'kontakt.telefon'     => $c ? (string) ($c['mobile'] ?: $c['phone']) : '',
		'ceg.nev'             => (string) $co,
		'deal.nev'            => (string) $d['title'],
		'rendezveny.datum'    => $date,
		'rendezveny.kezdes'   => $start,
		'rendezveny.helyszin' => (string) $d['venue'],
		'rendezveny.letszam'  => $d['guests'] !== null ? (string) $d['guests'] : '',
		'csomag'              => (string) $d['package'],
		'szolgaltatas'        => implode(', ', array_filter(array((string) $d['service'], str_replace(' | ', ', ', (string) $d['services_ec'])))),
		'ertek'               => $d['value'] !== null ? number_format((float) $d['value'], 0, ',', ' ') . ' Ft' : '',
		'portal.link'         => (string) $d['portal_link'],
		'felelos.nev'         => function_exists('cp_crm_user_name') ? cp_crm_user_name($d['owner_id']) : '',
	));
	return $v;
}

function cp_mail_render($text, $vals, $html) {
	return preg_replace_callback('/\{\{\s*([a-z.]+)\s*\}\}/', function ($m) use ($vals, $html) {
		if (!array_key_exists($m[1], $vals)) { return $m[0]; }
		$x = (string) $vals[$m[1]];
		if ($x === '') { return $html ? '<span style="background:#FFF3CD">[' . esc_html($m[1]) . ']</span>' : '[' . $m[1] . ']'; }
		return $html ? esc_html($x) : $x;
	}, $text);
}

/* a levéltörzs biztonságos HTML-je (formázás, linkek, listák, képek) */
function cp_mail_clean($html) {
	$allowed = array(
		'p' => array('style' => true), 'br' => array(), 'b' => array(), 'strong' => array(), 'i' => array(), 'em' => array(), 'u' => array(), 's' => array(),
		'h1' => array(), 'h2' => array(), 'h3' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'blockquote' => array(),
		'a' => array('href' => true, 'target' => true, 'rel' => true), 'span' => array('style' => true), 'div' => array('style' => true),
		'img' => array('src' => true, 'alt' => true, 'width' => true, 'height' => true, 'style' => true), 'hr' => array(),
		'table' => array('style' => true), 'tr' => array(), 'td' => array('style' => true), 'th' => array('style' => true), 'tbody' => array(), 'thead' => array(),
	);
	return wp_kses(mb_substr((string) $html, 0, 200000), $allowed, array('http', 'https', 'mailto', 'tel'));
}

function cp_mail_cfg() {
	if (!cp_mail_db_ok()) { return array('on' => false); }
	return array(
		'on'      => true,
		'css'     => plugins_url('assets/mail.css', __FILE__) . '?v=' . CP_PANEL_VER,
		'vars'    => cp_mail_vars(),
		'boxes'   => array(array('id' => 'eventcrazy', 'n' => 'EventCrazy', 'e' => 'info@eventcrazy.hu'), array('id' => 'crazyphoto', 'n' => 'Crazyphoto', 'e' => ''), array('id' => 'studioframes', 'n' => 'Studio Frames', 'e' => '')),
		'connected' => false,
	);
}

/* ------------------------------------------------------------ AJAX: sablonok */

add_action('wp_ajax_nopriv_cp_mail_tpls', 'cp_mail_ajax_tpls');
add_action('wp_ajax_cp_mail_tpls', 'cp_mail_ajax_tpls');
function cp_mail_ajax_tpls() {
	cp_el_auth();
	if (!cp_mail_db_ok()) { cp_el_err('Az adatbázis még készül – próbáld újra egy perc múlva.', 503); }
	global $wpdb;
	$rows = $wpdb->get_results('SELECT id, name, category, subject, body, updated_by, updated_at FROM ' . cp_mail_t() . ' WHERE deleted_at IS NULL ORDER BY category, name', ARRAY_A);
	foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['updated_at'] = function_exists('cp_crm_local') ? cp_crm_local($r['updated_at']) : $r['updated_at']; }
	unset($r);
	wp_send_json_success(array('tpls' => $rows));
}

add_action('wp_ajax_nopriv_cp_mail_tpl_save', 'cp_mail_ajax_tpl_save');
add_action('wp_ajax_cp_mail_tpl_save', 'cp_mail_ajax_tpl_save');
function cp_mail_ajax_tpl_save() {
	cp_el_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
	$name = mb_substr(trim(sanitize_text_field(wp_unslash($_POST['name'] ?? ''))), 0, 190);
	if ($name === '') { cp_el_err('Add meg a sablon nevét.'); }
	$row = array(
		'name' => $name,
		'category' => mb_substr(trim(sanitize_text_field(wp_unslash($_POST['category'] ?? ''))), 0, 80),
		'subject' => mb_substr(trim(sanitize_text_field(wp_unslash($_POST['subject'] ?? ''))), 0, 255),
		'body' => cp_mail_clean(wp_unslash($_POST['body'] ?? '')),
		'updated_by' => function_exists('cp_panel_who') ? cp_panel_who() : '',
		'updated_at' => gmdate('Y-m-d H:i:s'),
	);
	if ($id) { $wpdb->update(cp_mail_t(), $row, array('id' => $id)); }
	else { $row['created_at'] = $row['updated_at']; $wpdb->insert(cp_mail_t(), $row); $id = (int) $wpdb->insert_id; }
	if ($wpdb->last_error) { cp_el_err('Mentési hiba.', 500); }
	wp_send_json_success(array('id' => $id));
}

add_action('wp_ajax_nopriv_cp_mail_tpl_del', 'cp_mail_ajax_tpl_del');
add_action('wp_ajax_cp_mail_tpl_del', 'cp_mail_ajax_tpl_del');
function cp_mail_ajax_tpl_del() {
	cp_el_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$wpdb->update(cp_mail_t(), array('deleted_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => function_exists('cp_panel_who') ? cp_panel_who() : ''), array('id' => (int) ($_POST['id'] ?? 0)));
	wp_send_json_success(array());
}

/* előnézet: a változók kitöltése egy deal adataival */
add_action('wp_ajax_nopriv_cp_mail_preview', 'cp_mail_ajax_preview');
add_action('wp_ajax_cp_mail_preview', 'cp_mail_ajax_preview');
function cp_mail_ajax_preview() {
	cp_el_auth();
	$vals = cp_mail_values(isset($_POST['deal']) ? (int) $_POST['deal'] : 0);
	$sample = empty($_POST['deal']);
	if ($sample) { foreach (cp_mail_vars() as $k => $x) { if ($vals[$k] === '') { $vals[$k] = $x[1]; } } }
	wp_send_json_success(array(
		'subject' => cp_mail_render(sanitize_text_field(wp_unslash($_POST['subject'] ?? '')), $vals, false),
		'body' => cp_mail_render(cp_mail_clean(wp_unslash($_POST['body'] ?? '')), $vals, true),
		'to' => $vals['kontakt.email'],
	));
}

/* deal-kereső a levélíróhoz és az előnézethez (név / #szám) */
add_action('wp_ajax_nopriv_cp_mail_deals', 'cp_mail_ajax_deals');
add_action('wp_ajax_cp_mail_deals', 'cp_mail_ajax_deals');
function cp_mail_ajax_deals() {
	cp_el_auth();
	if (!function_exists('cp_crm_list_data')) { wp_send_json_success(array('rows' => array())); }
	$r = cp_crm_list_data(array('q' => isset($_POST['q']) ? (string) wp_unslash($_POST['q']) : '', 'so' => 'mod'));
	wp_send_json_success(array('rows' => array_map(function ($x) { return array('id' => $x['id'], 'title' => $x['title'], 'cn' => $x['cn'], 'ce' => $x['ce'], 'ev' => $x['ev']); }, array_slice($r['rows'], 0, 12))));
}
