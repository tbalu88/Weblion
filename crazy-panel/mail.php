<?php
/* =====================================================================
 * Levelezés (v1.33) – a panel „Levelezés” füle: postafiók-nézet, levélíró, sablonszerkesztő.
 * Ebben a lépésben a Salesmate-hez NEM nyúl, és levelet sem küld: a sablonok a panel saját táblájában vannak,
 * a postafiók-nézet a postafiókok (IMAP/SMTP) bekötéséig üres. A változók ({{kontakt.nev}} stb.) a saját CRM-táblából
 * töltődnek ki az előnézetben.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_MAIL_DB_VER', '3');

function cp_mail_t($name = 'templates') {
	global $wpdb;
	return $wpdb->prefix . 'crazy_mail_' . $name;
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
	/* v2: levélszálak, levelek, jegyzetek (a postafiókok bekötéséig üresek; a jegyzetek már írhatók) */
	dbDelta('CREATE TABLE ' . cp_mail_t('threads') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mailbox varchar(40) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  contact_name varchar(190) NOT NULL DEFAULT '',
  contact_email varchar(190) NOT NULL DEFAULT '',
  deal_id bigint(20) unsigned NULL,
  assignee_uid int(11) NULL,
  followers text NULL,
  tags varchar(255) NOT NULL DEFAULT '',
  status varchar(12) NOT NULL DEFAULT 'open',
  folder varchar(20) NOT NULL DEFAULT 'inbox',
  snippet varchar(255) NOT NULL DEFAULT '',
  msg_count int(11) NOT NULL DEFAULT 0,
  has_attach tinyint(1) NOT NULL DEFAULT 0,
  last_at datetime NULL,
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY deal_id (deal_id),
  KEY last_at (last_at)
) " . $wpdb->get_charset_collate() . ';');
	dbDelta('CREATE TABLE ' . cp_mail_t('messages') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  thread_id bigint(20) unsigned NOT NULL,
  dir varchar(3) NOT NULL DEFAULT 'in',
  from_name varchar(190) NOT NULL DEFAULT '',
  from_email varchar(190) NOT NULL DEFAULT '',
  to_list text NULL,
  cc_list text NULL,
  subject varchar(255) NOT NULL DEFAULT '',
  body longtext NULL,
  attachments text NULL,
  status varchar(20) NOT NULL DEFAULT '',
  sent_at datetime NULL,
  PRIMARY KEY  (id),
  KEY thread_id (thread_id)
) " . $wpdb->get_charset_collate() . ';');
	dbDelta('CREATE TABLE ' . cp_mail_t('notes') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  thread_id bigint(20) unsigned NOT NULL,
  deal_id bigint(20) unsigned NULL,
  body text NULL,
  author_uid int(11) NULL,
  author varchar(100) NOT NULL DEFAULT '',
  mentions varchar(255) NOT NULL DEFAULT '',
  created_at datetime NULL,
  deleted_at datetime NULL,
  PRIMARY KEY  (id),
  KEY thread_id (thread_id),
  KEY deal_id (deal_id)
) " . $wpdb->get_charset_collate() . ';');
	/* v3: a szál eseményei (lezárás, kiosztás, deal csatolása…) – a levelek között jelennek meg */
	dbDelta('CREATE TABLE ' . cp_mail_t('events') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  thread_id bigint(20) unsigned NOT NULL,
  kind varchar(20) NOT NULL DEFAULT '',
  text varchar(255) NOT NULL DEFAULT '',
  author_uid int(11) NULL,
  author varchar(100) NOT NULL DEFAULT '',
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY thread_id (thread_id)
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
		'people'  => cp_mail_people(),
		'me'      => function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0,
		'admin'   => function_exists('cp_panel_is_admin') && cp_panel_is_admin(),
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


/* ------------------------------------------------------------ levélszálak és jegyzetek (v1.34) */

/* a megemlíthető / kiosztható kollégák: a panel aktív felhasználói */
function cp_mail_people() {
	$out = array();
	if (!function_exists('cp_pu_data')) { return $out; }
	foreach (cp_pu_data()['list'] as $id => $u) { if (!empty($u['a'])) { $out[] = array('id' => (int) $id, 'n' => (string) $u['n']); } }
	return $out;
}

function cp_mail_local($dt) {
	return function_exists('cp_crm_local') ? cp_crm_local($dt) : (string) $dt;
}

function cp_mail_thread_row($t) {
	global $wpdb;
	$n = $wpdb->get_row($wpdb->prepare('SELECT body, author, created_at FROM ' . cp_mail_t('notes') . ' WHERE thread_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', $t['id']), ARRAY_A);
	return array(
		'id' => (int) $t['id'], 'box' => $t['mailbox'], 'subj' => $t['subject'], 'cn' => $t['contact_name'], 'ce' => $t['contact_email'],
		'deal' => $t['deal_id'] ? (int) $t['deal_id'] : 0, 'as' => $t['assignee_uid'] ? (int) $t['assignee_uid'] : 0, 'st' => $t['status'], 'folder' => $t['folder'],
		'snip' => $t['snippet'], 'cnt' => (int) $t['msg_count'], 'att' => (int) $t['has_attach'], 'at' => cp_mail_local($t['last_at']),
		'fol' => array_values(array_filter(array_map('intval', explode(',', (string) $t['followers'])))), 'tags' => (string) $t['tags'],
		'note' => $n ? array('b' => mb_substr((string) $n['body'], 0, 160), 'by' => $n['author'], 'at' => cp_mail_local($n['created_at'])) : null,
	);
}

add_action('wp_ajax_nopriv_cp_mail_threads', 'cp_mail_ajax_threads');
add_action('wp_ajax_cp_mail_threads', 'cp_mail_ajax_threads');
function cp_mail_ajax_threads() {
	cp_el_auth();
	if (!cp_mail_db_ok()) { cp_el_err('Az adatbázis még készül.', 503); }
	global $wpdb;
	$w = array('1=1');
	$folder = sanitize_key(wp_unslash($_POST['folder'] ?? 'inbox'));
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if ($folder === 'follow') { $w[] = $wpdb->prepare('FIND_IN_SET(%d, followers)', $me); }
	elseif ($folder !== '') { $w[] = $wpdb->prepare('folder = %s', $folder); }
	$tab = sanitize_key(wp_unslash($_POST['tab'] ?? 'open'));
	if ($tab === 'open') { $w[] = "status = 'open'"; }
	if ($tab === 'unassigned') { $w[] = 'assignee_uid IS NULL'; }
	$box = sanitize_key(wp_unslash($_POST['box'] ?? ''));
	if ($box !== '') { $w[] = $wpdb->prepare('mailbox = %s', $box); }
	$q = trim((string) wp_unslash($_POST['q'] ?? ''));
	if ($q !== '') { $like = '%' . $wpdb->esc_like($q) . '%'; $w[] = $wpdb->prepare('(subject LIKE %s OR contact_name LIKE %s OR contact_email LIKE %s OR snippet LIKE %s)', $like, $like, $like, $like); }
	$rows = $wpdb->get_results('SELECT * FROM ' . cp_mail_t('threads') . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY last_at DESC LIMIT 100', ARRAY_A);
	$counts = array();
	foreach ((array) $wpdb->get_results('SELECT folder, COUNT(*) n FROM ' . cp_mail_t('threads') . " WHERE status = 'open' GROUP BY folder", ARRAY_A) as $c) { $counts[$c['folder']] = (int) $c['n']; }
	$counts['follow'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_mail_t('threads') . " WHERE status = 'open' AND FIND_IN_SET(%d, followers)", $me));
	wp_send_json_success(array('rows' => array_map('cp_mail_thread_row', (array) $rows), 'counts' => $counts));
}

function cp_mail_thread_full($id) {
	global $wpdb;
	$t = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_mail_t('threads') . ' WHERE id = %d', $id), ARRAY_A);
	if (!$t) { return null; }
	$msgs = $wpdb->get_results($wpdb->prepare('SELECT id, dir, from_name, from_email, to_list, cc_list, subject, body, attachments, status, sent_at FROM ' . cp_mail_t('messages') . ' WHERE thread_id = %d ORDER BY sent_at, id', $id), ARRAY_A);
	foreach ($msgs as &$m) { $m['sent_at'] = cp_mail_local($m['sent_at']); $m['attachments'] = json_decode((string) $m['attachments'], true) ?: array(); }
	unset($m);
	$notes = $wpdb->get_results($wpdb->prepare('SELECT id, body, author, author_uid, created_at FROM ' . cp_mail_t('notes') . ' WHERE thread_id = %d AND deleted_at IS NULL ORDER BY id', $id), ARRAY_A);
	foreach ($notes as &$n) { $n['id'] = (int) $n['id']; $n['author_uid'] = (int) $n['author_uid']; $n['created_at'] = cp_mail_local($n['created_at']); }
	unset($n);
	$ev = $wpdb->get_results($wpdb->prepare('SELECT id, kind, text, author, created_at FROM ' . cp_mail_t('events') . ' WHERE thread_id = %d ORDER BY id', $id), ARRAY_A);
	foreach ($ev as &$e) { $e['id'] = (int) $e['id']; $e['created_at'] = cp_mail_local($e['created_at']); }
	unset($e);
	$deal = $t['deal_id'] && function_exists('cp_crm_row_out') ? cp_crm_row_out((int) $t['deal_id']) : null;
	return array('t' => cp_mail_thread_row($t), 'msgs' => $msgs, 'notes' => $notes, 'events' => $ev, 'deal' => $deal);
}

add_action('wp_ajax_nopriv_cp_mail_thread', 'cp_mail_ajax_thread');
add_action('wp_ajax_cp_mail_thread', 'cp_mail_ajax_thread');
function cp_mail_ajax_thread() {
	cp_el_auth();
	$x = cp_mail_db_ok() ? cp_mail_thread_full((int) ($_POST['id'] ?? 0)) : null;
	if (!$x) { cp_el_err('Nincs ilyen levélszál.', 404); }
	wp_send_json_success($x);
}

/* jegyzet: sima szöveg (sortörésekkel), @Név megemlítésekkel */
add_action('wp_ajax_nopriv_cp_mail_note_add', 'cp_mail_ajax_note_add');
add_action('wp_ajax_cp_mail_note_add', 'cp_mail_ajax_note_add');
function cp_mail_ajax_note_add() {
	cp_el_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$tid = (int) ($_POST['thread'] ?? 0);
	$t = $wpdb->get_row($wpdb->prepare('SELECT id, deal_id, subject FROM ' . cp_mail_t('threads') . ' WHERE id = %d', $tid), ARRAY_A);
	if (!$t) { cp_el_err('Nincs ilyen levélszál.', 404); }
	$body = trim(mb_substr(sanitize_textarea_field(wp_unslash($_POST['body'] ?? '')), 0, 4000));
	if ($body === '') { cp_el_err('Üres jegyzet.'); }
	$men = array();
	foreach (cp_mail_people() as $p) { if (mb_stripos($body, '@' . $p['n']) !== false) { $men[] = $p['id']; } }
	$wpdb->insert(cp_mail_t('notes'), array('thread_id' => $tid, 'deal_id' => $t['deal_id'], 'body' => $body, 'author_uid' => function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0, 'author' => function_exists('cp_panel_who') ? cp_panel_who() : '', 'mentions' => implode(',', $men), 'created_at' => gmdate('Y-m-d H:i:s')));
	foreach ($men as $u) { if (function_exists('cp_notify')) { cp_notify($u, 'mail_mention', 'megemlített egy levéljegyzetben (' . mb_substr($t['subject'], 0, 80) . '): ' . cp_nt_quote($body), 'mail:' . $tid); } }
	wp_send_json_success(cp_mail_thread_full($tid));
}

add_action('wp_ajax_nopriv_cp_mail_note_del', 'cp_mail_ajax_note_del');
add_action('wp_ajax_cp_mail_note_del', 'cp_mail_ajax_note_del');
function cp_mail_ajax_note_del() {
	cp_el_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$n = $wpdb->get_row($wpdb->prepare('SELECT id, thread_id, author_uid FROM ' . cp_mail_t('notes') . ' WHERE id = %d', (int) ($_POST['id'] ?? 0)), ARRAY_A);
	if (!$n) { cp_el_err('Nincs ilyen jegyzet.', 404); }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if ((int) $n['author_uid'] !== $me && !(function_exists('cp_panel_is_admin') && cp_panel_is_admin())) { cp_el_err('Csak a saját jegyzetedet törölheted.', 403); }
	$wpdb->update(cp_mail_t('notes'), array('deleted_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $n['id']));
	wp_send_json_success(cp_mail_thread_full((int) $n['thread_id']));
}

/* szál beállításai: deal csatolása / leválasztása, felelős, lezárás / újranyitás, követés, címkék */
add_action('wp_ajax_nopriv_cp_mail_thread_set', 'cp_mail_ajax_thread_set');
add_action('wp_ajax_cp_mail_thread_set', 'cp_mail_ajax_thread_set');
function cp_mail_ajax_thread_set() {
	cp_el_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$id = (int) ($_POST['id'] ?? 0);
	$t = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_mail_t('threads') . ' WHERE id = %d', $id), ARRAY_A);
	if (!$t) { cp_el_err('Nincs ilyen levélszál.', 404); }
	$f = sanitize_key(wp_unslash($_POST['f'] ?? ''));
	$v = (string) wp_unslash($_POST['v'] ?? '');
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$set = array();
	if ($f === 'deal') { $set['deal_id'] = ctype_digit($v) && (int) $v > 0 ? (int) $v : null; }
	elseif ($f === 'assignee') { $set['assignee_uid'] = ctype_digit($v) && (int) $v > 0 ? (int) $v : null; }
	elseif ($f === 'status') { $set['status'] = $v === 'closed' ? 'closed' : 'open'; }
	elseif ($f === 'follow') { $l = array_filter(array_map('intval', explode(',', (string) $t['followers']))); $l = $v === '1' ? array_unique(array_merge($l, array($me))) : array_diff($l, array($me)); $set['followers'] = implode(',', $l); }
	elseif ($f === 'tags') { $set['tags'] = mb_substr(sanitize_text_field($v), 0, 255); }
	else { cp_el_err('Hibás kérés.'); }
	$wpdb->update(cp_mail_t('threads'), $set, array('id' => $id));
	$ev = cp_mail_event_text($f, $set, $t);
	if ($f === 'assignee' && !empty($set['assignee_uid']) && (int) $set['assignee_uid'] !== (int) $t['assignee_uid'] && function_exists('cp_notify')) { cp_notify((int) $set['assignee_uid'], 'mail_assign', 'rád osztotta a levelet: „' . mb_substr($t['subject'], 0, 100) . '”', 'mail:' . $id); }
	if ($ev !== '') { $wpdb->insert(cp_mail_t('events'), array('thread_id' => $id, 'kind' => $f, 'text' => $ev, 'author_uid' => $me, 'author' => function_exists('cp_panel_who') ? cp_panel_who() : '', 'created_at' => gmdate('Y-m-d H:i:s'))); }
	wp_send_json_success(cp_mail_thread_full($id));
}

/* az esemény szövege a szál idővonalán (követés nem kerül bele; változatlan értéknél üres) */
function cp_mail_event_text($f, $set, $t) {
	if ($f === 'status') { return $set['status'] === $t['status'] ? '' : ($set['status'] === 'closed' ? 'Levélszál lezárva' : 'Levélszál újranyitva'); }
	if ($f === 'assignee') {
		if ((int) $set['assignee_uid'] === (int) $t['assignee_uid']) { return ''; }
		if (!$set['assignee_uid']) { return 'Felelős eltávolítva'; }
		foreach (cp_mail_people() as $p) { if ($p['id'] === (int) $set['assignee_uid']) { return 'Kiosztva: ' . $p['n']; } }
		return 'Kiosztva';
	}
	if ($f === 'deal') {
		if ((int) $set['deal_id'] === (int) $t['deal_id']) { return ''; }
		if (!$set['deal_id']) { return 'Deal leválasztva'; }
		$d = function_exists('cp_crm_row_out') ? cp_crm_row_out((int) $set['deal_id']) : null;
		return mb_substr('Deal csatolva: ' . ($d ? $d['title'] : '#' . $set['deal_id']), 0, 255);
	}
	if ($f === 'tags') { return $set['tags'] === $t['tags'] ? '' : ($set['tags'] === '' ? 'Címkék törölve' : mb_substr('Címkék: ' . $set['tags'], 0, 255)); }
	return '';
}
