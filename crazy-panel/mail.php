<?php
/* =====================================================================
 * Levelezés (v1.33) – a panel „Levelezés” füle: postafiók-nézet, levélíró, sablonszerkesztő.
 * Ebben a lépésben a Salesmate-hez NEM nyúl, és levelet sem küld: a sablonok a panel saját táblájában vannak,
 * a postafiók-nézet a postafiókok (IMAP/SMTP) bekötéséig üres. A változók ({{kontakt.nev}} stb.) a saját CRM-táblából
 * töltődnek ki az előnézetben.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_MAIL_DB_VER', '9');

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
  tkey varchar(32) NOT NULL DEFAULT '',
  src varchar(10) NOT NULL DEFAULT '',
  seen_at datetime NULL,
  prev_folder varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY deal_id (deal_id),
  KEY last_at (last_at),
  KEY tkey (tkey)
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
  mid varchar(32) NOT NULL DEFAULT '',
  msgid varchar(255) NOT NULL DEFAULT '',
  imap_folder varchar(120) NOT NULL DEFAULT '',
  imap_uid int(10) unsigned NULL,
  html mediumtext NULL,
  reply_to varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY thread_id (thread_id),
  KEY mid (mid),
  FULLTEXT KEY body_ft (body)
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
	/* v5: olvasott / olvasatlan – a meglévő szálak olvasottként indulnak */
	if ((int) get_option('cp_mail_db_ver') < 5) { $wpdb->query('UPDATE ' . cp_mail_t('threads') . ' SET seen_at = COALESCE(last_at, UTC_TIMESTAMP()) WHERE seen_at IS NULL'); }
	update_option('cp_mail_db_ver', CP_MAIL_DB_VER, true); /* v2.9.58: automatikusan betöltve (minden kérés olvassa) */
}, 7);

function cp_mail_db_ok() {
	return get_option('cp_mail_db_ver') === CP_MAIL_DB_VER;
}

/* v2.9.58: a minden kérésnél olvasott apró jelzők (adatbázis-verzió, AI-napló, beérkezés be/ki) automatikusan betöltve –
 * így nem kell rájuk külön lekérdezés. Egyszeri átállítás; a jelző (cp_mail_al) maga is automatikusan betöltött, tehát olcsó. */
add_action('init', function () {
	if (get_option('cp_mail_al') === '1' || !function_exists('wp_set_option_autoload')) { return; }
	$all = wp_load_alloptions();
	foreach (array('cp_mail_db_ver', 'cp_ai_log_ver', 'cp_imp_sync_on') as $k) { if (!array_key_exists($k, $all)) { wp_set_option_autoload($k, true); } }
	update_option('cp_mail_al', '1', true);
}, 6);

/* v2.9.58: atomi zár a MySQL GET_LOCK-kal – egyszerre csak egy kérés kaphatja meg; ha a kérés összeomlik,
 * az adatbázis-kapcsolat bontásakor magától felszabadul (nincs beragadt zár). A név adatbázisonként egyedi. */
function cp_mail_lock_name($k) { global $wpdb; return 'cp_' . md5(DB_NAME . '|' . $wpdb->prefix . '|' . $k); }
function cp_mail_lock($k) { global $wpdb; return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', cp_mail_lock_name($k))) === '1'; }
function cp_mail_unlock($k) { global $wpdb; $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', cp_mail_lock_name($k))); }

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
		/* v2.9.14: a Salesmate-sablonok helytartóinak magyar megfelelői (a régi {{Deal.…}} / {{Contact.…}} alakok is működnek) */
		'rendezveny.nap'      => array('Rendezvény dátuma (2026.11.14)', '2026.11.14'),
		'rendezveny.oraszam'  => array('Rendelkezésre állás (óra)', '4'),
		'netto.ar'            => array('Nettó ár (Ft nélkül)', '190 000'),
		'kiszallasi.dij'      => array('Kiszállási díj (Ft nélkül)', '10 000'),
		'kontakt.azonosito'   => array('Kontakt azonosító', '12345'),
		'deal.azonosito'      => array('Deal azonosító', '23456'),
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
		'rendezveny.nap'      => $d['event_d'] ? str_replace('-', '.', substr((string) $d['event_d'], 0, 10)) : '',
		'rendezveny.oraszam'  => $d['hours'] !== null && $d['hours'] !== '' ? (string) (0 + $d['hours']) : '',
		'netto.ar'            => $d['value'] !== null && $d['value'] !== '' ? number_format((float) $d['value'], 0, ',', ' ') : '',
		'kiszallasi.dij'      => $d['km_fee'] !== null && $d['km_fee'] !== '' ? number_format((float) $d['km_fee'], 0, ',', ' ') : '',
		'kontakt.azonosito'   => $d['contact_id'] ? (string) $d['contact_id'] : '',
		'deal.azonosito'      => (string) $d['id'],
	));
	/* v1.43: a Salesmate-ből átvett sablonok változói ({{Deal.…}}, {{Contact.…}}) – a nyers értékek, a szűrőket a renderelés alkalmazza */
	if (function_exists('cp_crm_spec')) {
		foreach ((array) cp_crm_spec('deal') as $col => $x) { if (is_array($x) && !empty($x[0]) && array_key_exists($col, $d)) { $v['Deal.' . $x[0]] = ($x[1] ?? '') === 'dt' && $d[$col] && function_exists('cp_crm_local') ? cp_crm_local($d[$col]) : str_replace(' | ', ', ', (string) $d[$col]); } } /* v2.9.21: időpont helyi időben */
	}
	$v['Deal.id'] = (string) $d['id']; $v['Deal.title'] = (string) $d['title']; $v['Deal.dealValue'] = $d['value'] !== null ? (string) $d['value'] : '';
	$v['Deal.ugyfelportal_link'] = (string) $d['portal_link'];
	$v['Contact.id'] = (string) $d['contact_id']; $v['Contact.name'] = $c ? (string) $c['name'] : ''; $v['Contact.email'] = $c ? (string) $c['email'] : '';
	$v['Contact.mobile'] = $c ? (string) $c['mobile'] : ''; $v['Contact.phone'] = $c ? (string) $c['phone'] : ''; $v['Company.name'] = (string) $co;
	return $v;
}

/* v2.9.14: helytartó olvasható neve (a levélíró sárga jelöléséhez és a hiánylistához) */
function cp_mail_ph_label($k) {
	$V = cp_mail_vars();
	if (isset($V[$k])) { return preg_replace('/\s*\(.*\)$/u', '', $V[$k][0]); }
	$sm = array('Contact.name' => 'Kontakt neve', 'Contact.email' => 'Kontakt e-mail-címe', 'Contact.mobile' => 'Kontakt mobilszáma', 'Contact.phone' => 'Kontakt telefonszáma', 'Contact.id' => 'Kontakt azonosító',
		'Company.name' => 'Cég neve', 'Deal.id' => 'Deal azonosító', 'Deal.title' => 'Deal neve', 'Deal.dealValue' => 'Nettó ár', 'Deal.ugyfelportal_link' => 'Ügyfélportál linkje',
		'Deal.dateCustomField1' => 'Rendezvény dátuma', 'Deal.dateTimeCustomField1' => 'Kezdés', 'Deal.textCustomField1' => 'Helyszín', 'Deal.intCustomField1' => 'Tervezett létszám',
		'Deal.intCustomField2' => 'Kiszállási díj', 'Deal.intCustomField3' => 'Rendelkezésre állás (óra)', 'Deal.textCustomField5' => 'Fotófal / háttér', 'Deal.textCustomField13' => 'Szolgáltatás');
	return $sm[$k] ?? $k;
}
/* kitöltés a levélíróban: az ismert és kitöltött helytartók értékre cserélődnek; ami hiányzik, sárga jelölés lesz (HTML-ben),
 * amely a title-ben megőrzi az eredeti helytartót – így később (pl. deal csatolásakor) újra kitölthető, és küldés előtt felismerhető */
function cp_mail_fill($text, $vals, $html, &$miss) {
	return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*((?:\|[^}]*)?)\}\}/u', function ($m) use ($vals, $html, &$miss) {
		$x = array_key_exists($m[1], $vals) ? (string) $vals[$m[1]] : '';
		if ($x !== '' && $m[2] !== '') { $x = cp_mail_filter($x, $m[2]); }
		if ($x !== '') { return $html ? esc_html($x) : $x; }
		$l = cp_mail_ph_label($m[1]); $miss[$l] = 1;
		return $html ? '<span class="cp-ph" title="' . esc_attr(html_entity_decode($m[0], ENT_QUOTES, 'UTF-8')) . '" style="background:#FFF3CD;color:#7A5B00;border-radius:4px;padding:0 3px">⚠ ' . esc_html($l) . '</span>' : $m[0];
	}, $text);
}
/* küldés előtti ellenőrzés: maradt-e kitöltetlen helytartó (az idézett korábbi levélben nem keresünk) – visszatér: hiányzó nevek */
function cp_mail_unfilled($subj, $html) {
	$h = preg_replace('#<blockquote\b.*</blockquote>#isu', '', (string) $html);
	$out = array();
	if (preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)[^}]*\}\}/u', $subj . ' ' . html_entity_decode($h, ENT_QUOTES, 'UTF-8'), $m)) { foreach ($m[1] as $k) { $out[cp_mail_ph_label($k)] = 1; } }
	if (preg_match_all('#class="cp-ph"[^>]*>⚠\s*([^<]+)<#u', $h, $m)) { foreach ($m[1] as $k) { $out[trim(html_entity_decode($k, ENT_QUOTES, 'UTF-8'))] = 1; } }
	if (preg_match_all('#background:\#FFF3CD">\[([^\]<]+)\]#u', $h, $m)) { foreach ($m[1] as $k) { $out[cp_mail_ph_label($k)] = 1; } }
	return array_keys($out);
}

/* Salesmate-szűrők: date : "%Y.%m.%d.", money / money_without_currency, remove: 'x', default: 'x' */
function cp_mail_filter($x, $filters) {
	foreach (array_filter(array_map('trim', explode('|', html_entity_decode(stripslashes((string) $filters), ENT_QUOTES, 'UTF-8')))) as $f) {
		if (preg_match('/^date\s*:\s*["\']?(.*?)["\']?$/u', $f, $m)) {
			$t = preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) ? strtotime($x . ' 12:00:00 UTC') : (int) strtotime($x);
			if ($t) { $x = strtr($m[1], array('%Y' => gmdate('Y', $t), '%y' => gmdate('y', $t), '%m' => gmdate('m', $t), '%d' => gmdate('d', $t), '%e' => gmdate('j', $t), '%H' => gmdate('H', $t), '%M' => gmdate('i', $t))); }
		} elseif (preg_match('/^money/u', $f)) {
			if (is_numeric($x)) { $x = number_format((float) $x, 0, ',', ' ') . ($f === 'money' ? ' Ft' : ''); }
		} elseif (preg_match('/^remove\s*:\s*["\'](.*)["\']$/u', $f, $m)) {
			$x = str_replace($m[1], '', $x);
		} elseif (preg_match('/^default\s*:\s*["\'](.*)["\']$/u', $f, $m)) {
			if ($x === '') { $x = $m[1]; }
		}
	}
	return $x;
}

function cp_mail_render($text, $vals, $html) {
	return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*((?:\|[^}]*)?)\}\}/u', function ($m) use ($vals, $html) {
		if (!array_key_exists($m[1], $vals)) { return $m[0]; }
		$x = (string) $vals[$m[1]];
		if ($m[2] !== '' && $x !== '') { $x = cp_mail_filter($x, $m[2]); }
		if ($x === '') { return $html ? '<span style="background:#FFF3CD">[' . esc_html($m[1]) . ']</span>' : '[' . $m[1] . ']'; }
		return $html ? esc_html($x) : $x;
	}, $text);
}

/* a levéltörzs és az aláírás biztonságos HTML-je – az e-mailekben szokásos formázással (v1.58: a Salesmate-aláírások és
 * -sablonok táblázatai, linkstílusai, betűtípusai is megmaradnak; szkript, eseménykezelő, űrlap, iframe nem) */
function cp_mail_clean($html) {
	$c = array('style' => true, 'class' => true, 'align' => true, 'dir' => true, 'title' => true);
	$cell = $c + array('width' => true, 'height' => true, 'valign' => true, 'bgcolor' => true, 'colspan' => true, 'rowspan' => true, 'nowrap' => true);
	$allowed = array(
		'p' => $c, 'div' => $c, 'span' => $c, 'br' => array(), 'hr' => $c + array('size' => true, 'width' => true, 'color' => true, 'noshade' => true),
		'b' => $c, 'strong' => $c, 'i' => $c, 'em' => $c, 'u' => $c, 's' => $c, 'strike' => $c, 'small' => $c, 'big' => $c, 'sup' => $c, 'sub' => $c, 'center' => $c,
		'font' => $c + array('face' => true, 'size' => true, 'color' => true),
		'h1' => $c, 'h2' => $c, 'h3' => $c, 'h4' => $c, 'h5' => $c, 'h6' => $c, 'ul' => $c, 'ol' => $c + array('start' => true, 'type' => true), 'li' => $c, 'blockquote' => $c, 'pre' => $c, 'code' => $c,
		'a' => $c + array('href' => true, 'target' => true, 'rel' => true, 'name' => true),
		'img' => $c + array('src' => true, 'alt' => true, 'width' => true, 'height' => true, 'border' => true, 'hspace' => true, 'vspace' => true),
		'table' => $c + array('width' => true, 'height' => true, 'border' => true, 'cellpadding' => true, 'cellspacing' => true, 'bgcolor' => true, 'role' => true),
		'thead' => $c, 'tbody' => $c, 'tfoot' => $c, 'tr' => $c + array('valign' => true, 'bgcolor' => true, 'height' => true), 'td' => $cell, 'th' => $cell, 'colgroup' => $c, 'col' => $c + array('width' => true, 'span' => true),
	);
	/* a WordPress stílusszűrője alapból kidobna néhány, e-mailben gyakori CSS-tulajdonságot */
	$css = function ($p) { return array_unique(array_merge($p, array('display', 'vertical-align', 'line-height', 'letter-spacing', 'white-space', 'text-decoration', 'text-transform', 'border', 'border-left', 'border-right', 'border-top', 'border-bottom', 'border-collapse', 'border-spacing', 'border-radius', 'border-color', 'border-style', 'border-width', 'padding', 'padding-left', 'padding-right', 'padding-top', 'padding-bottom', 'margin', 'margin-left', 'margin-right', 'margin-top', 'margin-bottom', 'width', 'height', 'max-width', 'min-width', 'max-height', 'color', 'background', 'background-color', 'font', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-align', 'float', 'clear', 'overflow', 'table-layout', 'mso-line-height-rule'))); };
	add_filter('safe_style_css', $css, 99);
	/* v2.9.136: a WordPress stílusszűrője a zárójeles értéket (rgb(…)) eldobja – így vesztek el a Salesmate-sablonok és a
	   szerkesztőben (Chrome) választott betűszínek. Előtte #rrggbb alakra alakítjuk (átlátszó: transparent). */
	$html = preg_replace_callback('/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+)\s*)?\)/i', function ($m) {
		if (isset($m[4]) && $m[4] !== '' && (float) $m[4] === 0.0) { return 'transparent'; }
		return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
	}, (string) $html);
	/* v2.9.136: a <style>, <script>, <head>, <title> blokk tartalmastul törlődik (különben a CSS szövegként a levélbe kerülne) */
	$html = preg_replace('#<(style|script|head|title|xml)\b[^>]*>.*?</\1\s*>#is', '', $html);
	$out = wp_kses(mb_substr((string) $html, 0, 200000), $allowed, array('http', 'https', 'mailto', 'tel'));
	remove_filter('safe_style_css', $css, 99);
	return $out;
}

function cp_mail_cfg() {
	if (!cp_mail_db_ok()) { return array('on' => false); }
	return array(
		'on'      => true,
		'css'     => plugins_url('assets/mail.css', __FILE__) . '?v=' . CP_PANEL_VER,
		'vars'    => cp_mail_vars(),
		'boxes'   => array(array('id' => 'eventcrazy', 'n' => 'EventCrazy', 'e' => 'info@eventcrazy.hu'), array('id' => 'crazyphoto', 'n' => 'Crazyphoto', 'e' => ''), array('id' => 'studioframes', 'n' => 'Studio Frames', 'e' => '')),
		'connected' => false,
		'ai'      => function_exists('cp_ai_ready') && cp_ai_ready(),
		'sync'    => get_option('cp_imp_sync_on') === '1',
		'people'  => cp_mail_people(),
		'me'      => function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0,
		'admin'   => function_exists('cp_panel_is_admin') && cp_panel_is_admin(),
		'send'    => function_exists('cp_send_cfg') ? cp_send_cfg() : array('on' => false),
		'sig'     => function_exists('cp_send_sig_html') ? cp_send_sig_html() : '',
		/* v2.9.96: mellékletre kattintva azonnali letöltés (személyes beállítás) */
		'px'      => function_exists('cp_px_on') && cp_px_on() ? 1 : 0, /* v2.9.112 */
		'attdl'   => (function () { $me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0; $d = $me && function_exists('cp_pu_data') ? cp_pu_data() : array(); return $me && !empty($d['list'][$me]['attdl']) ? 1 : 0; })(),
	);
}

/* ------------------------------------------------------------ AJAX: sablonok */

add_action('wp_ajax_nopriv_cp_mail_tpls', 'cp_mail_ajax_tpls');
add_action('wp_ajax_cp_mail_tpls', 'cp_mail_ajax_tpls');
function cp_mail_ajax_tpls() {
	cp_el_auth();
	cp_tab_need(array('mail'));
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
	cp_tab_need(array('mail'));
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
	cp_tab_need(array('mail'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$wpdb->update(cp_mail_t(), array('deleted_at' => gmdate('Y-m-d H:i:s'), 'updated_by' => function_exists('cp_panel_who') ? cp_panel_who() : ''), array('id' => (int) ($_POST['id'] ?? 0)));
	wp_send_json_success(array());
}

/* előnézet: a változók kitöltése egy deal adataival */
add_action('wp_ajax_nopriv_cp_mail_preview', 'cp_mail_ajax_preview');
add_action('wp_ajax_cp_mail_preview', 'cp_mail_ajax_preview');
add_action('wp_ajax_nopriv_cp_mail_fill', 'cp_mail_ajax_fill');
add_action('wp_ajax_cp_mail_fill', 'cp_mail_ajax_fill');
function cp_mail_ajax_fill() {
	cp_el_auth();
	cp_tab_need(array('mail', 'deals'));
	$deal = (int) ($_POST['deal'] ?? 0);
	if (!$deal) { cp_el_err('Válassz deal-t a levélhez.'); }
	$vals = cp_mail_values($deal);
	$miss = array();
	$subj = cp_mail_fill(sanitize_text_field(wp_unslash($_POST['subject'] ?? '')), $vals, false, $miss);
	$body = cp_mail_fill(cp_mail_clean(wp_unslash($_POST['body'] ?? '')), $vals, true, $miss);
	wp_send_json_success(array('subject' => $subj, 'body' => $body, 'miss' => array_keys($miss)));
}
function cp_mail_ajax_preview() {
	cp_el_auth();
	cp_tab_need(array('mail'));
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
	cp_tab_need(array('mail', 'feladatok', 'deals'));
	if (!function_exists('cp_crm_list_data')) { wp_send_json_success(array('rows' => array())); }
	$r = cp_crm_list_data(array('q' => isset($_POST['q']) ? (string) wp_unslash($_POST['q']) : '', 'so' => 'mod'));
	wp_send_json_success(array('rows' => array_map(function ($x) { return array('id' => $x['id'], 'title' => $x['title'], 'cn' => $x['cn'], 'ce' => $x['ce'], 'ev' => $x['ev']); }, array_slice($r['rows'], 0, 12))));
}


/* v2.9.62: „Deal csatolása” – javaslatok a szálhoz, indoklással. Sorrend: a szál résztvevőinek (feladó, címzett, másolat) címéhez
 * tartozó dealek, majd az azonos cégdomainű kontaktok dealjei (ingyenes levelezők – gmail stb. – és a saját domainjeink nélkül),
 * végül a szál kontaktjának nevével egyező kontakt dealjei. Egy csoporton belül a nyitott dealek elöl, majd a levelezés idejéhez
 * legközelebbi rendezvény. Csak olvas. */
add_action('wp_ajax_nopriv_cp_mail_deal_suggest', 'cp_mail_ajax_deal_suggest');
add_action('wp_ajax_cp_mail_deal_suggest', 'cp_mail_ajax_deal_suggest');
function cp_mail_ajax_deal_suggest() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if (!function_exists('cp_crm_t') || !function_exists('cp_imp_thread_parts') || !function_exists('cp_crm_panel_on') || !cp_crm_panel_on()) { wp_send_json_success(array('rows' => array())); }
	global $wpdb;
	$t = $wpdb->get_row($wpdb->prepare('SELECT id, contact_email, contact_name, last_at, subject FROM ' . cp_mail_t('threads') . ' WHERE id = %d', (int) ($_POST['th'] ?? 0)), ARRAY_A);
	if (!$t) { wp_send_json_success(array('rows' => array())); }
	$D = cp_crm_t('deals'); $C = cp_crm_t('contacts');
	$at = (string) ($t['last_at'] ?: gmdate('Y-m-d H:i:s'));
	$sel = "SELECT d.id, d.title, d.status, d.event_d, c.name cn, c.email ce FROM $D d JOIN $C c ON c.id = d.contact_id WHERE d.deleted_at IS NULL AND c.deleted_at IS NULL AND ";
	$ord = $wpdb->prepare(" ORDER BY (d.status = 'Open') DESC, ABS(DATEDIFF(COALESCE(d.event_d, DATE(d.created_at)), %s)) LIMIT 8", $at);
	$out = array(); $seen = array();
	/* v2.9.73: a rangsoroláshoz bővebb jelöltlista (a dátum-egyezők előre kerülnek), a végén 8 */
	$add = function ($rows, $why) use (&$out, &$seen) {
		foreach ((array) $rows as $r) {
			if (isset($seen[$r['id']]) || count($out) >= 20) { continue; }
			$seen[$r['id']] = 1;
			$out[] = array('id' => (int) $r['id'], 'title' => (string) $r['title'], 'cn' => (string) $r['cn'], 'ce' => (string) $r['ce'], 'ev' => (string) $r['event_d'], 'st' => (string) $r['status'], 'why' => is_callable($why) ? $why($r) : $why);
		}
	};
	$parts = cp_imp_thread_parts((int) $t['id'], (string) $t['contact_email']);
	/* v2.9.73: deal-jel a tárgyban */
	$tok = function_exists('cp_dl_token') ? cp_dl_token((string) $t['subject']) : 0;
	if ($tok) { $add($wpdb->get_results($wpdb->prepare($sel . 'd.id = %d', $tok) . $ord, ARRAY_A), 'Deal-jel a tárgyban: #' . $tok); }
	if ($parts) {
		$in = implode(',', array_fill(0, count($parts), '%s'));
		$add($wpdb->get_results($wpdb->prepare($sel . "c.email IN ($in)", $parts) . str_replace('LIMIT 8', 'LIMIT 20', $ord), ARRAY_A), function ($r) { return 'A levelezésben szerepel: ' . $r['ce']; });
		/* v2.9.73: a kontaktok további címei (contact-emails.php) */
		if (function_exists('cp_ce_db_ok') && cp_ce_db_ok()) {
			$add($wpdb->get_results($wpdb->prepare("SELECT d.id, d.title, d.status, d.event_d, c.name cn, c.email ce, a.email ae FROM $D d JOIN $C c ON c.id = d.contact_id JOIN " . cp_ce_t() . " a ON a.contact_id = c.id WHERE d.deleted_at IS NULL AND c.deleted_at IS NULL AND a.email IN ($in)", $parts) . str_replace('LIMIT 8', 'LIMIT 20', $ord), ARRAY_A), function ($r) { return 'A kontakt további címe: ' . $r['ae']; });
		}
	}
	/* v2.9.73: régi Salesmate-sablon „|| ID: <kontakt>” */
	$smid = function_exists('cp_dl_smid') ? cp_dl_smid((string) $t['subject']) : 0;
	if ($smid) { $add($wpdb->get_results($wpdb->prepare($sel . 'c.id = %d', $smid) . $ord, ARRAY_A), 'Salesmate-azonosító a tárgyban'); }
	$doms = array();
	foreach ($parts as $e) { $d = substr(strrchr($e, '@'), 1); if ($d !== '' && !cp_imp_ours($e) && !(function_exists('cp_cos_free') && cp_cos_free($d)) && !in_array($d, $doms, true)) { $doms[] = $d; } }
	foreach (array_slice($doms, 0, 3) as $d) {
		$add($wpdb->get_results($wpdb->prepare($sel . 'c.email LIKE %s', '%@' . $wpdb->esc_like($d)) . $ord, ARRAY_A), 'Azonos cég (' . $d . ')');
	}
	$nm = trim((string) $t['contact_name']);
	if (mb_strlen($nm) >= 5 && strpos($nm, '@') === false && strpos($nm, ' ') !== false) {
		$add($wpdb->get_results($wpdb->prepare($sel . 'c.name = %s', $nm) . $ord, ARRAY_A), 'Azonos név: ' . $nm);
	}
	/* v2.9.73: a tárgyban lévő dátummal (rendezvénynap: event_d vagy a többnapos rendezvény bármelyik napja) egyező deal elöl */
	$dates = function_exists('cp_dl_dates') ? cp_dl_dates((string) $t['subject'], $at) : array();
	if ($dates && $out) {
		$days = array();
		foreach ($out as $r) { if ($r['ev'] !== '') { $days[$r['id']][] = substr($r['ev'], 0, 10); } }
		if (function_exists('cp_mday_db_ok') && function_exists('cp_mday_t') && cp_mday_db_ok()) {
			foreach ((array) $wpdb->get_results('SELECT deal_id, day FROM ' . cp_mday_t() . ' WHERE deal_id IN (' . implode(',', array_map('intval', array_column($out, 'id'))) . ')', ARRAY_A) as $x) { $days[(int) $x['deal_id']][] = substr((string) $x['day'], 0, 10); }
		}
		$hit = array(); $rest = array();
		foreach ($out as $r) {
			$m = array_values(array_intersect($days[$r['id']] ?? array(), $dates));
			if ($m) { $r['why'] = 'A tárgyban lévő dátum: ' . cp_dl_hu_day($m[0]) . ($r['why'] !== '' ? ' · ' . $r['why'] : ''); $r['dm'] = 1; $hit[] = $r; } else { $rest[] = $r; }
		}
		usort($hit, function ($a, $b) { return ($b['st'] === 'Open') <=> ($a['st'] === 'Open'); });
		$out = array_merge($hit, $rest);
	}
	wp_send_json_success(array('rows' => array_slice($out, 0, 8)));
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

function cp_mail_thread_row($t, $notes = null) {
	global $wpdb;
	/* v2.9.21: a listánál a legutóbbi jegyzeteket egyetlen lekérdezés hozza ($notes), egyedi szálnál külön */
	$n = is_array($notes) ? ($notes[(int) $t['id']] ?? null) : $wpdb->get_row($wpdb->prepare('SELECT body, author, created_at FROM ' . cp_mail_t('notes') . ' WHERE thread_id = %d AND deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1', $t['id']), ARRAY_A);
	if ($n) {
		$out = is_array($notes) ? ($n['_out'] ?? null) : $wpdb->get_var($wpdb->prepare('SELECT MAX(sent_at) FROM ' . cp_mail_t('messages') . " WHERE thread_id = %d AND dir = 'out'", $t['id']));
		if (!empty($out) && (string) $out >= (string) $n['created_at']) { $n = null; } /* v2.9.180: a jegyzet óta már válaszoltunk – nem releváns */
	}
	return array(
		'id' => (int) $t['id'], 'box' => $t['mailbox'], 'subj' => $t['subject'], 'cn' => $t['contact_name'], 'ce' => $t['contact_email'],
		'deal' => $t['deal_id'] ? (int) $t['deal_id'] : 0, 'as' => $t['assignee_uid'] ? (int) $t['assignee_uid'] : 0, 'sz' => $t['folder'] === 'snooze' ? (int) ((get_option('cp_mail_snooze', array())[(int) $t['id']]['ts'] ?? 0)) : 0, 'st' => $t['status'], 'folder' => ($t['folder'] === 'archive' && $t['prev_folder'] === '' && in_array(trim(explode(',', (string) $t['tags'])[0]), array('import', 'salesmate'), true)) ? 'inbox' : $t['folder'],
		'un' => empty($t['seen_at']) || (!empty($t['last_at']) && $t['seen_at'] < $t['last_at']) ? 1 : 0,
		'snip' => html_entity_decode((string) $t['snippet'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'cnt' => (int) $t['msg_count'], 'att' => (int) $t['has_attach'], 'at' => cp_mail_local($t['last_at']),
		'fol' => array_values(array_filter(array_map('intval', explode(',', (string) $t['followers'])))), 'tags' => implode(', ', cp_mail_tags_parts($t['tags'])[1]),
		'note' => $n ? array('b' => mb_substr((string) $n['body'], 0, 160), 'by' => $n['author'], 'at' => cp_mail_local($n['created_at'])) : null,
	);
}

add_action('wp_ajax_nopriv_cp_mail_threads', 'cp_mail_ajax_threads');
add_action('wp_ajax_cp_mail_threads', 'cp_mail_ajax_threads');
function cp_mail_ajax_threads() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if (!cp_mail_db_ok()) { cp_el_err('Az adatbázis még készül.', 503); }
	global $wpdb;
	$w = array('1=1');
	cp_mail_snooze_due();
	$folder = sanitize_key(wp_unslash($_POST['folder'] ?? 'inbox'));
	/* v2.9.40: keresésnél minden levél között (minden mappa és a lezártak is) – csak a Kuka és a Spam marad ki */
	$qs = trim((string) wp_unslash($_POST['q'] ?? '')) !== '';
	/* v2.9.42: címkére szűrve is minden levél között (a „Mind” fül szerint, minden mappából) */
	$tg0 = json_decode((string) wp_unslash($_POST['tg'] ?? ''), true);
	if (is_array($tg0) && array_filter(array_map('strval', $tg0), 'strlen')) { $qs = true; }
	if ($qs && in_array($folder, array('inbox', 'archive', 'promo', 'sent', 'outbox', 'snooze'), true)) { $folder = 'all'; }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if ($folder === 'follow') { $w[] = $wpdb->prepare('FIND_IN_SET(%d, followers)', $me); }
	/* v2.7.3: a beolvasáskor (postafiók, Salesmate) lezártként archivált régi levelezés a Beérkezettben látszik („Mind” fül);
	   az Archív csak a kézzel archivált szálakat mutatja (azoknál a korábbi mappa ki van töltve) */
	elseif ($folder === 'inbox') { $w[] = "(folder = 'inbox' OR (folder = 'archive' AND prev_folder = '' AND SUBSTRING_INDEX(tags, ',', 1) IN ('import','salesmate')))"; }
	elseif ($folder === 'archive') { $w[] = "(folder = 'archive' AND NOT (prev_folder = '' AND SUBSTRING_INDEX(tags, ',', 1) IN ('import','salesmate')))"; }
	elseif ($folder === 'all') { $w[] = "folder NOT IN ('trash','spam')"; }
	elseif ($folder !== '') { $w[] = $wpdb->prepare('folder = %s', $folder); }
	$tab = $qs ? 'all' : sanitize_key(wp_unslash($_POST['tab'] ?? 'open'));
	if ($tab === 'open') { $w[] = "status = 'open'"; }
	if ($tab === 'unassigned') { $w[] = 'assignee_uid IS NULL'; }
	/* v1.88: „Csak olvasatlan” gyorsszűrő */
	if (($_POST['un'] ?? '') === '1') { $w[] = '(seen_at IS NULL OR seen_at < last_at)'; }
	$box = sanitize_key(wp_unslash($_POST['box'] ?? ''));
	if ($box !== '') { $w[] = $wpdb->prepare('mailbox = %s', $box); }
	$q = trim((string) wp_unslash($_POST['q'] ?? ''));
	if ($q !== '') { $like = '%' . $wpdb->esc_like($q) . '%'; $w[] = $wpdb->prepare('(subject LIKE %s OR contact_name LIKE %s OR contact_email LIKE %s OR snippet LIKE %s)', $like, $like, $like, $like); }
	/* v2.9.25: címke-szűrő – a megadott címkék bármelyike (JSON-tömb) */
	$tg = json_decode((string) wp_unslash($_POST['tg'] ?? ''), true);
	if (is_array($tg)) { $or = array(); foreach (array_slice($tg, 0, 20) as $x) { $x = cp_mail_tag_norm($x); if ($x !== '' && !cp_mail_tag_is_sys($x)) { $or[] = cp_mail_tag_where($x); } } if ($or) { $w[] = '(' . implode(' OR ', $or) . ')'; } }
	$ord = sanitize_key(wp_unslash($_POST['so'] ?? '')) === 'old' ? 'ASC' : 'DESC';
	/* v2.7.2: lapozás – a lista alján „Régebbi levelek betöltése” (200-asával, legfeljebb 5000) */
	$lim = max(100, min(5000, (int) ($_POST['lim'] ?? 100)));
	$rows = (array) $wpdb->get_results('SELECT * FROM ' . cp_mail_t('threads') . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY last_at ' . $ord . ', id ' . $ord . ' LIMIT ' . ($lim + 1), ARRAY_A);
	$more = count($rows) > $lim;
	if ($more) { array_pop($rows); }
	$counts = array();
	foreach ((array) $wpdb->get_results('SELECT folder, COUNT(*) n FROM ' . cp_mail_t('threads') . " WHERE status = 'open' GROUP BY folder", ARRAY_A) as $c) { $counts[$c['folder']] = (int) $c['n']; }
	$un = array();
	foreach ((array) $wpdb->get_results('SELECT folder, COUNT(*) n FROM ' . cp_mail_t('threads') . " WHERE status = 'open' AND (seen_at IS NULL OR seen_at < last_at) GROUP BY folder", ARRAY_A) as $c) { $un[$c['folder']] = (int) $c['n']; }
	$counts['follow'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_mail_t('threads') . " WHERE status = 'open' AND FIND_IN_SET(%d, followers)", $me));
	/* v2.9: saját piszkozatok – a Piszkozatok mappa száma és a szálak „✎ Piszkozat” jelzése */
	$myd = function_exists('cp_mail_drafts_get') && $me > 0 ? cp_mail_drafts_get($me) : array();
	$counts['draft'] = count($myd);
	$dth = function_exists('cp_mail_draft_threads') ? cp_mail_draft_threads() : array();
	$ids = array_map(function ($t) { return (int) $t['id']; }, (array) $rows); $notes = array();
	if ($ids) { $N = cp_mail_t('notes'); $in = implode(',', $ids); /* v2.9.178: a legutóbbi jegyzet a dátum szerint (az áthozott jegyzetek azonosítója nem időrendi) */ foreach ((array) $wpdb->get_results("SELECT thread_id, body, author, created_at FROM $N WHERE deleted_at IS NULL AND thread_id IN ($in) ORDER BY thread_id, created_at, id", ARRAY_A) as $r) { $notes[(int) $r['thread_id']] = $r; }
		/* v2.9.180: a jegyzet csak addig látszik a csempén, amíg utána nem írtunk levelet a szálba */
		$M = cp_mail_t('messages'); foreach ((array) $wpdb->get_results("SELECT thread_id, MAX(sent_at) o FROM $M WHERE dir = 'out' AND thread_id IN ($in) GROUP BY thread_id", ARRAY_A) as $r) { if (isset($notes[(int) $r['thread_id']])) { $notes[(int) $r['thread_id']]['_out'] = $r['o']; } } }
	$rowsOut = array_map(function ($t) use ($dth, $notes) { $r = cp_mail_thread_row($t, $notes); if (isset($dth[(int) $t['id']])) { $r['dr'] = 1; } return $r; }, (array) $rows);
	wp_send_json_success(array('rows' => $rowsOut, 'counts' => $counts, 'un' => $un, 'more' => $more ? 1 : 0, 'tags' => cp_mail_tags_all()));
}

/* v2.9.68: az ügyfél válaszában idézett szövegből visszaállított saját levelünk („idézetből”) duplikátum-e.
   A szöveg összevetéséhez: címkék, idézőjelek (>) és minden nem betű/szám nélkül, kisbetűsen, az első 600 jel. */
function cp_mail_qd_norm($s) {
	$s = preg_replace('#<(style|script|head)[^>]*>.*?</\1>#is', '', (string) $s);
	$s = html_entity_decode(strip_tags(preg_replace('#<\s*(br|/p|/div|/tr|/li|/blockquote)\b[^>]*>#i', "\n", $s)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$s = preg_replace('/^[ \t]*(>[ \t]?)+/mu', '', $s);
	return mb_substr(mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s)), 0, 600);
}
/* v2.9.68: ugyanaz a szöveg-e: a rövidebb elejének (40 jel) benne kell lennie a másik elején (az eredetinél lehet egy
   plusz fejléc- vagy megszólítássor); $k: a vizsgált hossz; nagyon rövid szövegnél csak a teljes egyezés számít */
function cp_mail_qd_same($a, $b, $k = 40) {
	$a = cp_mail_qd_norm($a); $b = cp_mail_qd_norm($b);
	if ($a === '' || $b === '') { return false; }
	if (mb_strlen($a) < 12 || mb_strlen($b) < 12) { return $a === $b; }
	return mb_strpos($b, mb_substr($a, 0, $k)) !== false || mb_strpos($a, mb_substr($b, 0, $k)) !== false;
}
/* v2.9.68: mely „idézetből” visszaállított levelek rejtendők, mert megvan az eredeti kimenő levél (±$win mp, azonos szövegkezdet).
   $rows: array(array('id', 'dir', 'q' => bool, 'ts' => unix, 'body')); visszaad: array(id => true). Adatot nem töröl. */
function cp_mail_qd_hide(array $rows, $win = 120) {
	$hide = array();
	foreach ($rows as $q) {
		if (empty($q['q'])) { continue; }
		foreach ($rows as $o) {
			if (!empty($o['q']) || $o['dir'] !== 'out' || abs((int) $o['ts'] - (int) $q['ts']) > $win) { continue; }
			if (cp_mail_qd_same((string) $q['body'], (string) $o['body'])) { $hide[(int) $q['id']] = true; break; }
		}
	}
	return $hide;
}

function cp_mail_thread_full($id) {
	global $wpdb;
	$t = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_mail_t('threads') . ' WHERE id = %d', $id), ARRAY_A);
	if (!$t) { return null; }
	$msgs = $wpdb->get_results($wpdb->prepare('SELECT id, dir, from_name, from_email, to_list, cc_list, subject, body, attachments, status, sent_at, reply_to, CASE WHEN html IS NULL THEN -1 WHEN html = \'\' THEN 0 ELSE 1 END hh FROM ' . cp_mail_t('messages') . ' WHERE thread_id = %d ORDER BY sent_at, id', $id), ARRAY_A);
	/* v2.9.68: a szálon belül a visszaállított másolat rejtve, ha az eredeti kimenő levél is megvan (az adat marad) */
	$qh = cp_mail_qd_hide(array_map(function ($m) { return array('id' => $m['id'], 'dir' => $m['dir'], 'q' => $m['status'] === 'idézetből', 'ts' => strtotime($m['sent_at'] . ' UTC'), 'body' => $m['body']); }, (array) $msgs));
	if ($qh) { $msgs = array_values(array_filter($msgs, function ($m) use ($qh) { return !isset($qh[(int) $m['id']]); })); }
	foreach ($msgs as &$m) { $m['hh'] = (int) $m['hh']; $m['sent_at'] = cp_mail_local($m['sent_at']); $m['attachments'] = json_decode((string) $m['attachments'], true) ?: array(); if (function_exists('cp_mail_fname')) { foreach ($m['attachments'] as &$at) { if (isset($at['n'])) { $at['n'] = cp_mail_fname($at['n']); } } unset($at); } }
	unset($m);
	$notes = $wpdb->get_results($wpdb->prepare('SELECT id, body, author, author_uid, created_at FROM ' . cp_mail_t('notes') . ' WHERE thread_id = %d AND deleted_at IS NULL ORDER BY created_at, id', $id), ARRAY_A);
	foreach ($notes as &$n) { $n['id'] = (int) $n['id']; $n['author_uid'] = (int) $n['author_uid']; $n['created_at'] = cp_mail_local($n['created_at']); }
	unset($n);
	$ev = $wpdb->get_results($wpdb->prepare('SELECT id, kind, text, author, created_at FROM ' . cp_mail_t('events') . ' WHERE thread_id = %d ORDER BY id', $id), ARRAY_A);
	foreach ($ev as &$e) { $e['id'] = (int) $e['id']; $e['created_at'] = cp_mail_local($e['created_at']); }
	unset($e);
	$deal = $t['deal_id'] && function_exists('cp_crm_row_out') ? cp_crm_row_out((int) $t['deal_id']) : null;
	if ($deal && function_exists('cp_plk_chip')) { $deal['portal'] = cp_plk_chip((int) $t['deal_id']); }
	/* v1.98: figyelmeztetés, ha a szál dealje elvesztett / lezajlott / törölt – és ajánlat az ügyfél nyitott dealjére */
	if ($deal && function_exists('cp_crm_t')) {
		$dr = $wpdb->get_row($wpdb->prepare('SELECT status, event_d, deleted_at FROM ' . cp_crm_t('deals') . ' WHERE id = %d', (int) $t['deal_id']), ARRAY_A);
		$w = '';
		if ($dr && !empty($dr['deleted_at'])) { $w = 'Ez a deal törölve lett.'; }
		elseif ($dr && $dr['status'] === 'Lost') { $w = 'Ez a deal elvesztett.'; }
		elseif ($dr && $dr['status'] === 'Won' && !empty($dr['event_d']) && $dr['event_d'] < wp_date('Y-m-d')) { $w = 'Ennek a dealnek a rendezvénye már lezajlott (' . $dr['event_d'] . ').'; }
		if ($w !== '') {
			$deal['warn'] = $w . ' Válaszkor a sablon ennek a dealnek az adataival (dátum, helyszín, ár, portállink) tölt ki.';
			$alt = function_exists('cp_imp_pick_deal') ? cp_imp_pick_deal((string) $t['contact_email'], gmdate('Y-m-d H:i:s')) : 0;
			if ($alt && $alt !== (int) $t['deal_id'] && $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $alt)) === 'Open') {
				$deal['alt'] = array('id' => $alt, 'title' => (string) $wpdb->get_var($wpdb->prepare('SELECT title FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $alt)));
			}
		}
	}
	/* v2.5: visszatérő ügyfél – a feladó korábbi rendezvényei */
	$ret = function_exists('cp_ret_by_email') ? cp_ret_by_email((string) $t['contact_email'], (int) $t['deal_id']) : null;
	/* v2.9.109: megnyitás-követés – a kimenő levelek adata, és a saját követő képünk kiszűrése a megjelenített szövegből */
	if (function_exists('cp_px_for_msgs')) {
		$pxm = cp_px_for_msgs(array_map(function ($m) { return $m['dir'] === 'out' ? (int) $m['id'] : 0; }, (array) $msgs));
		foreach ($msgs as &$m) { if (isset($pxm[(int) $m['id']])) { $m['px'] = $pxm[(int) $m['id']]; } $m['body'] = cp_px_strip((string) $m['body']); }
		unset($m);
	}
	return array('t' => cp_mail_thread_row($t), 'msgs' => $msgs, 'notes' => $notes, 'events' => $ev, 'deal' => $deal, 'ret' => $ret, 'blk' => function_exists('cp_mail_blocked') && cp_mail_blocked((string) $t['contact_email']) ? 1 : 0);
}

add_action('wp_ajax_nopriv_cp_mail_thread', 'cp_mail_ajax_thread');
add_action('wp_ajax_cp_mail_thread', 'cp_mail_ajax_thread');
function cp_mail_ajax_thread() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	global $wpdb;
	$id = (int) ($_POST['id'] ?? 0);
	/* megnyitáskor olvasott (a csapat közös postafiókjában mindenkinek) */
	if ($id && cp_mail_db_ok()) { $wpdb->query($wpdb->prepare('UPDATE ' . cp_mail_t('threads') . ' SET seen_at = UTC_TIMESTAMP() WHERE id = %d', $id)); }
	$x = cp_mail_db_ok() ? cp_mail_thread_full($id) : null;
	if (!$x) { cp_el_err('Nincs ilyen levélszál.', 404); }
	wp_send_json_success($x);
}

/* jegyzet: sima szöveg (sortörésekkel), @Név megemlítésekkel */
add_action('wp_ajax_nopriv_cp_mail_note_add', 'cp_mail_ajax_note_add');
add_action('wp_ajax_cp_mail_note_add', 'cp_mail_ajax_note_add');
function cp_mail_ajax_note_add() {
	cp_el_auth();
	cp_tab_need(array('mail'));
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
	cp_tab_need(array('mail'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$n = $wpdb->get_row($wpdb->prepare('SELECT id, thread_id, author_uid FROM ' . cp_mail_t('notes') . ' WHERE id = %d', (int) ($_POST['id'] ?? 0)), ARRAY_A);
	if (!$n) { cp_el_err('Nincs ilyen jegyzet.', 404); }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if ((int) $n['author_uid'] !== $me && !(function_exists('cp_panel_is_admin') && cp_panel_is_admin())) { cp_el_err('Csak a saját jegyzetedet törölheted.', 403); }
	$wpdb->update(cp_mail_t('notes'), array('deleted_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $n['id']));
	wp_send_json_success(cp_mail_thread_full((int) $n['thread_id']));
}

/* v2.9.171: címzett-kiegészítés a levélíróban – kontaktok (név, e-mail, további címek) és a korábbi levelezőpartnerek */
add_action('wp_ajax_nopriv_cp_mail_rcpt', 'cp_mail_ajax_rcpt');
add_action('wp_ajax_cp_mail_rcpt', 'cp_mail_ajax_rcpt');
function cp_mail_ajax_rcpt() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	global $wpdb;
	$q = trim(sanitize_text_field(wp_unslash($_POST['q'] ?? '')));
	if (mb_strlen($q) < 2) { wp_send_json_success(array('rows' => array())); }
	$like = '%' . $wpdb->esc_like($q) . '%';
	$out = array();
	$add = function ($n, $e, $src) use (&$out) { $e = strtolower(trim((string) $e)); if (!is_email($e) || isset($out[$e]) || count($out) >= 10) { return; } $out[$e] = array('n' => trim((string) $n), 'e' => $e, 's' => $src); };
	if (function_exists('cp_crm_t')) {
		$C = cp_crm_t('contacts');
		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT c.name, c.email, co.name con FROM $C c LEFT JOIN " . cp_crm_t('companies') . " co ON co.id = c.company_id WHERE c.deleted_at IS NULL AND c.email <> '' AND (c.name LIKE %s OR c.email LIKE %s) ORDER BY c.modified_at DESC LIMIT 10", $like, $like), ARRAY_A) as $r) { $add($r['name'], $r['email'], $r['con'] ? (string) $r['con'] : 'kontakt'); }
		if (function_exists('cp_ce_t')) {
			foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT c.name, e.email FROM ' . cp_ce_t() . " e JOIN $C c ON c.id = e.contact_id WHERE c.deleted_at IS NULL AND (e.email LIKE %s OR c.name LIKE %s) LIMIT 10", $like, $like), ARRAY_A) as $r) { $add($r['name'], $r['email'], 'kontakt'); }
		}
	}
	if (count($out) < 10) {
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT contact_name n, contact_email e FROM ' . cp_mail_t('threads') . " WHERE contact_email <> '' AND (contact_email LIKE %s OR contact_name LIKE %s) GROUP BY contact_email ORDER BY MAX(last_at) DESC LIMIT 10", $like, $like), ARRAY_A) as $r) { $add($r['n'], $r['e'], 'korábbi levelezés'); }
	}
	wp_send_json_success(array('rows' => array_values($out)));
}

/* v2.9.170: levélszál-jegyzet szerkesztése (a saját, adminnak bármelyik) */
add_action('wp_ajax_nopriv_cp_mail_note_edit', 'cp_mail_ajax_note_edit');
add_action('wp_ajax_cp_mail_note_edit', 'cp_mail_ajax_note_edit');
function cp_mail_ajax_note_edit() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$n = $wpdb->get_row($wpdb->prepare('SELECT id, thread_id, author_uid FROM ' . cp_mail_t('notes') . ' WHERE id = %d AND deleted_at IS NULL', (int) ($_POST['nid'] ?? 0)), ARRAY_A);
	if (!$n) { cp_el_err('Nincs ilyen jegyzet.', 404); }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if ((int) $n['author_uid'] !== $me && !(function_exists('cp_panel_is_admin') && cp_panel_is_admin())) { cp_el_err('Csak a saját jegyzetedet szerkesztheted.', 403); }
	$body = trim(mb_substr(sanitize_textarea_field(wp_unslash($_POST['body'] ?? '')), 0, 4000));
	if ($body === '') { cp_el_err('Üres jegyzet – ha törölni szeretnéd, a × gombbal teheted.'); }
	$wpdb->update(cp_mail_t('notes'), array('body' => $body), array('id' => (int) $n['id']));
	wp_send_json_success(cp_mail_thread_full((int) $n['thread_id']));
}

/* szál beállításai: deal csatolása / leválasztása, felelős, lezárás / újranyitás, követés, címkék */
add_action('wp_ajax_nopriv_cp_mail_thread_set', 'cp_mail_ajax_thread_set');
add_action('wp_ajax_cp_mail_thread_set', 'cp_mail_ajax_thread_set');
function cp_mail_ajax_thread_set() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$id = (int) ($_POST['id'] ?? 0);
	$t = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_mail_t('threads') . ' WHERE id = %d', $id), ARRAY_A);
	if (!$t) { cp_el_err('Nincs ilyen levélszál.', 404); }
	$f = sanitize_key(wp_unslash($_POST['f'] ?? ''));
	$v = (string) wp_unslash($_POST['v'] ?? '');
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$set = array();
	/* v2.9.155: 'none' = kifejezetten egyik dealhez sem tartozik (0) – a kontakt címe alapján sem jelenik meg a deal adatlapján */
	if ($f === 'deal') { $set['deal_id'] = ctype_digit($v) && (int) $v > 0 ? (int) $v : ($v === 'none' ? 0 : null); }
	elseif ($f === 'assignee') { $set['assignee_uid'] = ctype_digit($v) && (int) $v > 0 ? (int) $v : null; }
	elseif ($f === 'status') { $set['status'] = $v === 'closed' ? 'closed' : 'open'; }
	elseif ($f === 'follow') { $l = array_filter(array_map('intval', explode(',', (string) $t['followers']))); $l = $v === '1' ? array_unique(array_merge($l, array($me))) : array_diff($l, array($me)); $set['followers'] = implode(',', $l); }
	/* v2.9.25: a felület csak a saját címkéket küldi; a belső jelölők (import, salesmate) megmaradnak */
	elseif ($f === 'tags') { $nu = array_values(array_filter(array_map('cp_mail_tag_norm', explode(',', $v)), function ($x) { return $x !== '' && !cp_mail_tag_is_sys($x); })); $set['tags'] = cp_mail_tags_join(cp_mail_tags_parts($t['tags'])[0], $nu); cp_mail_tag_register($nu); }
	else { cp_el_err('Hibás kérés.'); }
	$wpdb->update(cp_mail_t('threads'), $set, array('id' => $id));
	$ev = cp_mail_event_text($f, $set, $t);
	if ($f === 'assignee' && !empty($set['assignee_uid']) && (int) $set['assignee_uid'] !== (int) $t['assignee_uid'] && function_exists('cp_notify')) { cp_notify((int) $set['assignee_uid'], 'mail_assign', 'rád osztotta a levelet: „' . mb_substr($t['subject'], 0, 100) . '”', 'mail:' . $id); }
	if ($ev !== '') { $wpdb->insert(cp_mail_t('events'), array('thread_id' => $id, 'kind' => $f, 'text' => $ev, 'author_uid' => $me, 'author' => function_exists('cp_panel_who') ? cp_panel_who() : '', 'created_at' => gmdate('Y-m-d H:i:s'))); }
	$full = cp_mail_thread_full($id);
	/* v2.9.73: kézi csatolás után: a szál partnercíme egyik kontaktnál sincs meg → egyszeri ajánlat a deal kontaktjához */
	if ($f === 'deal' && !empty($set['deal_id']) && $ev !== '' && function_exists('cp_ce_offer') && is_array($full)) { $o = cp_ce_offer($t, (int) $set['deal_id']); if ($o) { $full['ce_offer'] = $o; } }
	wp_send_json_success($full);
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
	if ($f === 'tags') { $u = implode(', ', cp_mail_tags_parts($set['tags'])[1]); return $set['tags'] === $t['tags'] ? '' : ($u === '' ? 'Címkék törölve' : mb_substr('Címkék: ' . $u, 0, 255)); }
	return '';
}

/* ------------------------------------------------------------ v1.47: tömeges műveletek a kijelölt levélszálakon
 * Csak a panelben hat (olvasott, címke, felelős, lezárás, mappa) – a postafiókban és a Salesmate-ben semmi nem változik. */
function cp_mail_folder_name($f) {
	return array('inbox' => 'Beérkezett', 'snooze' => 'Szundi', 'archive' => 'Archív', 'trash' => 'Kuka', 'spam' => 'Spam', 'promo' => 'Promóciók', 'sent' => 'Elküldött', 'outbox' => 'Kimenő', 'draft' => 'Piszkozatok')[$f] ?? $f;
}
add_action('wp_ajax_nopriv_cp_mail_bulk', 'cp_mail_ajax_bulk');
add_action('wp_ajax_cp_mail_bulk', 'cp_mail_ajax_bulk');
function cp_mail_ajax_bulk() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$T = cp_mail_t('threads');
	$ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) wp_unslash($_POST['ids'] ?? '')))))), 0, 500);
	$op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
	$v = (string) wp_unslash($_POST['v'] ?? '');
	if (!$ids || !in_array($op, array('read', 'unread', 'close', 'open', 'assign', 'tag', 'untag', 'archive', 'trash', 'spam', 'restore', 'snooze'), true)) { cp_el_err('Hibás kérés.'); }
	/* v2.8.2: szundi – a megadott időpontig a Szundi mappában, utána vissza a Beérkezettbe olvasatlanként */
	$sz = $op === 'snooze' ? (int) $v : 0;
	if ($op === 'snooze' && ($sz < time() + 60 || $sz > time() + 366 * DAY_IN_SECONDS)) { cp_el_err('Válassz egy jövőbeli időpontot (legfeljebb egy évre előre).'); }
	$szm = cp_opt_fresh('cp_mail_snooze'); if (!is_array($szm)) { $szm = array(); }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$who = function_exists('cp_panel_who') ? cp_panel_who() : '';
	$now = gmdate('Y-m-d H:i:s');
	$rows = (array) $wpdb->get_results('SELECT * FROM ' . $T . ' WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A);
	$n = 0;
	$aname = '';
	if ($op === 'assign') {
		$uid = ctype_digit($v) ? (int) $v : 0;
		foreach (cp_mail_people() as $p) { if ($p['id'] === $uid) { $aname = $p['n']; } }
		if ($uid && $aname === '') { cp_el_err('Nincs ilyen kolléga.'); }
	}
	$tagv = in_array($op, array('tag', 'untag'), true) ? cp_mail_tag_norm($v) : '';
	if ($tagv !== '' && cp_mail_tag_is_sys($tagv)) { $tagv = ''; }
	if ($op === 'tag' && $tagv !== '') { cp_mail_tag_register(array($tagv)); }
	if (in_array($op, array('tag', 'untag'), true) && $tagv === '') { cp_el_err('Adj meg egy címkét.'); }
	foreach ($rows as $t) {
		$set = array(); $ev = '';
		switch ($op) {
			case 'read': $set['seen_at'] = $now; break;
			case 'unread': $set['seen_at'] = null; break;
			case 'close': if ($t['status'] !== 'closed') { $set['status'] = 'closed'; $ev = 'Levélszál lezárva'; } break;
			case 'open': if ($t['status'] !== 'open') { $set['status'] = 'open'; $ev = 'Levélszál újranyitva'; } break;
			case 'assign':
				if ((int) $t['assignee_uid'] !== $uid) { $set['assignee_uid'] = $uid ?: null; $ev = $uid ? 'Kiosztva: ' . $aname : 'Felelős eltávolítva'; }
				break;
			case 'tag': case 'untag':
				list($tsys, $tusr) = cp_mail_tags_parts($t['tags']);
				$has = in_array(mb_strtolower($tagv), array_map('mb_strtolower', $tusr), true);
				if ($op === 'tag' && !$has) { $tusr[] = $tagv; }
				if ($op === 'untag' && $has) { $tusr = array_values(array_filter($tusr, function ($x) use ($tagv) { return mb_strtolower($x) !== mb_strtolower($tagv); })); }
				$ns = cp_mail_tags_join($tsys, $tusr);
				if ($ns !== $t['tags']) { $set['tags'] = $ns; $ev = mb_substr(($op === 'tag' ? 'Címke hozzáadva: ' : 'Címke levéve: ') . $tagv, 0, 255); }
				break;
			case 'archive': case 'trash': case 'spam':
				$legacy = $t['folder'] === 'archive' && $t['prev_folder'] === '' && in_array(trim(explode(',', (string) $t['tags'])[0]), array('import', 'salesmate'), true); /* v2.7.3: a Beérkezettben látszó régi szál */
				if ($legacy && $op === 'archive') { $set['prev_folder'] = 'inbox'; $ev = 'Áthelyezve: ' . cp_mail_folder_name($op); }
				elseif ($t['folder'] !== $op) { $set['folder'] = $op; $set['prev_folder'] = $legacy ? 'inbox' : $t['folder']; $ev = 'Áthelyezve: ' . cp_mail_folder_name($op); }
				break;
			case 'snooze':
				$legacy = $t['folder'] === 'archive' && $t['prev_folder'] === '' && in_array(trim(explode(',', (string) $t['tags'])[0]), array('import', 'salesmate'), true);
				$set['folder'] = 'snooze';
				if ($t['folder'] !== 'snooze') { $set['prev_folder'] = $legacy || in_array($t['folder'], array('trash', 'spam'), true) ? 'inbox' : $t['folder']; }
				$szm[(int) $t['id']] = array('ts' => $sz, 'uid' => $me);
				$ev = 'Szundi eddig: ' . wp_date('Y.m.d. H:i', $sz);
				break;
			case 'restore':
				unset($szm[(int) $t['id']]);
				$to = $t['prev_folder'] !== '' && !in_array($t['prev_folder'], array('trash', 'spam', 'archive', 'snooze'), true) && !in_array($t['folder'], array('promo'), true) ? $t['prev_folder'] : 'inbox';
				if ($t['folder'] !== $to) { $set['folder'] = $to; $set['prev_folder'] = ''; $set['status'] = 'open'; $ev = 'Visszaállítva: ' . cp_mail_folder_name($to); }
				break;
		}
		if (!$set) { continue; }
		$wpdb->update($T, $set, array('id' => (int) $t['id']));
		$n++;
		if ($ev !== '') { $wpdb->insert(cp_mail_t('events'), array('thread_id' => (int) $t['id'], 'kind' => $op, 'text' => $ev, 'author_uid' => $me, 'author' => $who, 'created_at' => $now)); }
	}
	if (in_array($op, array('snooze', 'restore'), true)) { update_option('cp_mail_snooze', $szm, false); }
	if ($op === 'assign' && $uid && $n && $uid !== $me && function_exists('cp_notify')) {
		cp_notify($uid, 'mail_assign', $n === 1 ? 'rád osztott egy levélszálat' : 'rád osztott ' . $n . ' levélszálat', 'mail:' . (int) $rows[0]['id']);
	}
	wp_send_json_success(array('n' => $n));
}

/* v1.58.1: az aláírás linkjei a környező szöveg színét kapják (ha nincs saját színük), aláhúzás nélkül – ahogy a
 * Salesmate szerkesztője mutatja; így a címzett levelezőprogramjában is így jelenik meg (nem kéken, aláhúzva) */
function cp_sig_norm_links($html) {
	$html = (string) $html;
	if ($html === '' || stripos($html, '<a') === false || !class_exists('DOMDocument')) { return $html; }
	$doc = new DOMDocument();
	$prev = libxml_use_internal_errors(true);
	$ok = $doc->loadHTML('<?xml encoding="utf-8"?><div id="cp-sigw">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
	libxml_clear_errors(); libxml_use_internal_errors($prev);
	if (!$ok) { return $html; }
	foreach ($doc->getElementsByTagName('a') as $a) {
		$st = trim((string) $a->getAttribute('style'));
		$add = array();
		if (!preg_match('/(^|;)\s*color\s*:/i', $st)) {
			$col = '#000000';
			for ($n = $a->parentNode; $n && $n instanceof DOMElement; $n = $n->parentNode) {
				if (preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', (string) $n->getAttribute('style'), $m)) { $col = trim($m[1]); break; }
				if ($n->getAttribute('id') === 'cp-sigw') { break; }
			}
			$add[] = 'color:' . $col;
		}
		if (!preg_match('/text-decoration/i', $st)) { $add[] = 'text-decoration:none'; }
		if ($add) { $a->setAttribute('style', rtrim($st, '; ') . ($st !== '' ? ';' : '') . implode(';', $add)); }
	}
	$w = $doc->getElementById('cp-sigw');
	if (!$w) { return $html; }
	$out = '';
	foreach ($w->childNodes as $c) { $out .= $doc->saveHTML($c); }
	return $out;
}


/* ------------------------------------------------------------ v2.7.13: ütközésjelzés – ki nézi / ki válaszol épp a szálban
 * A levélszálat megnyitó kolléga böngészője 45 mp-enként jelez (v2.9.58; korábban 15) (nézi / válaszol); a válasz a többiek friss jelzéseit adja,
 * és a szál legutóbbi levelének azonosítóját (így látszik, ha közben új levél jött). Rövid életű adat, csak gyorsítótárban. */
add_action('wp_ajax_nopriv_cp_mail_presence', 'cp_mail_ajax_presence');
add_action('wp_ajax_cp_mail_presence', 'cp_mail_ajax_presence');
function cp_mail_ajax_presence() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$tid = (int) ($_POST['th'] ?? 0);
	if ($tid <= 0) { wp_send_json_success(array('who' => array(), 'last' => 0)); }
	$st = sanitize_key(wp_unslash($_POST['st'] ?? 'view')) === 'reply' ? 'reply' : 'view';
	$k = 'cp_mpres_' . $tid;
	$p = get_transient($k);
	if (!is_array($p)) { $p = array(); }
	$now = time();
	/* v2.9.58: a böngésző 45 mp-enként jelez; írás csak változáskor (új / távozó kolléga, más állapot), vagy ha a saját jelzés
	   már 60 mp-nél régebbi – így a jelzések többsége nem ír az adatbázisba. A 150 mp-nél régebbi jelzés lejárt. */
	$ch = false;
	foreach ($p as $uid => $x) { if ($now - (int) ($x['t'] ?? 0) > 150) { unset($p[$uid]); $ch = true; } }
	if ($me > 0) {
		if (!empty($_POST['leave'])) { if (isset($p[$me])) { unset($p[$me]); $ch = true; } }
		elseif (!isset($p[$me]) || ($p[$me]['s'] ?? '') !== $st || $now - (int) ($p[$me]['t'] ?? 0) >= 60) { $p[$me] = array('s' => $st, 't' => $now); $ch = true; }
	}
	if ($ch) { set_transient($k, $p, 300); }
	$who = array();
	foreach ($p as $uid => $x) {
		if ((int) $uid === $me) { continue; }
		$u = function_exists('cp_pu_get') ? cp_pu_get((int) $uid) : null;
		$who[] = array('n' => $u ? (string) $u['n'] : 'Egy kolléga', 's' => (string) $x['s']);
	}
	global $wpdb;
	$last = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM ' . cp_mail_t('messages') . ' WHERE thread_id = %d', $tid));
	wp_send_json_success(array('who' => $who, 'last' => $last));
}

/* v2.8.2: lejárt szundik – vissza az eredeti mappába (Beérkezett), nyitottként és olvasatlanként, a szundizónak értesítés.
 * Ha közben új levél jött (a beolvasás a szálat már visszatette a Beérkezettbe), csak a jegyzéket takarítja. */
function cp_mail_snooze_due() {
	$m = cp_opt_fresh('cp_mail_snooze');
	if (!is_array($m) || !$m || !function_exists('cp_mail_db_ok') || !cp_mail_db_ok()) { return; }
	global $wpdb; $T = cp_mail_t('threads'); $now = time(); $ch = false;
	foreach ($m as $id => $x) {
		$t = $wpdb->get_row($wpdb->prepare("SELECT id, folder, prev_folder, subject FROM $T WHERE id = %d", (int) $id), ARRAY_A);
		if (!$t || $t['folder'] !== 'snooze') { unset($m[$id]); $ch = true; continue; }
		if ((int) ($x['ts'] ?? 0) > $now) { continue; }
		$to = $t['prev_folder'] !== '' && !in_array($t['prev_folder'], array('trash', 'spam', 'archive', 'snooze'), true) ? $t['prev_folder'] : 'inbox';
		$wpdb->update($T, array('folder' => $to, 'prev_folder' => '', 'status' => 'open', 'seen_at' => null), array('id' => (int) $id));
		$wpdb->insert(cp_mail_t('events'), array('thread_id' => (int) $id, 'kind' => 'snooze', 'text' => 'Szundi lejárt – vissza a ' . cp_mail_folder_name($to) . ' mappába', 'author_uid' => 0, 'author' => 'Crazy panel', 'created_at' => gmdate('Y-m-d H:i:s')));
		if (!empty($x['uid']) && function_exists('cp_notify')) { cp_notify((int) $x['uid'], 'mail_snooze', 'visszatért a szundizott levélszál: „' . mb_substr((string) $t['subject'], 0, 100) . '”', 'mail:' . (int) $id, 'Crazy panel'); }
		unset($m[$id]); $ch = true;
	}
	if ($ch) { update_option('cp_mail_snooze', $m, false); }
}
add_action('cp_crm_tick', 'cp_mail_snooze_due', 40);


/* ------------------------------------------------------------ v2.9: saját piszkozatok a szerveren (kollégánként)
 * Mint a Gmail / Outlook: a levélíró gépelés közben és elhagyáskor menti, a Piszkozatok mappában a saját piszkozatok,
 * egy levélszálhoz tartozó félbehagyott válasz a szálon is látszik. Küldéskor / elvetéskor törlődik. Legfeljebb 60 db. */
function cp_mail_drafts_get($uid) { $d = get_option('cp_mail_drafts_u' . (int) $uid, array()); return is_array($d) ? $d : array(); }
function cp_mail_drafts_put($uid, $d) { update_option('cp_mail_drafts_u' . (int) $uid, $d, false); }
function cp_mail_draft_me() { cp_el_auth(); cp_tab_need(array('mail')); $me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0; if ($me <= 0) { cp_el_err('A piszkozatokhoz személyes fiókkal kell belépni.'); } return $me; }
function cp_mail_draft_row($id, $x) {
	$txt = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags(preg_replace('#<blockquote.*$#is', '', (string) ($x['body'] ?? ''))), ENT_QUOTES, 'UTF-8')));
	return array('id' => (string) $id, 'to' => array_values((array) ($x['to'] ?? array())), 'subject' => (string) ($x['subject'] ?? ''), 'snip' => mb_substr($txt, 0, 160), 'thread' => (int) ($x['thread'] ?? 0), 'at' => wp_date('Y-m-d H:i', (int) ($x['t'] ?? time())), 'ts' => (int) ($x['t'] ?? 0));
}
add_action('wp_ajax_nopriv_cp_mail_draft_save', 'cp_mail_ajax_draft_save');
add_action('wp_ajax_cp_mail_draft_save', 'cp_mail_ajax_draft_save');
function cp_mail_ajax_draft_save() {
	$me = cp_mail_draft_me();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$in = json_decode((string) wp_unslash($_POST['d'] ?? ''), true);
	if (!is_array($in)) { cp_el_err('Hibás kérés.'); }
	$all = cp_mail_drafts_get($me);
	$id = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($_POST['id'] ?? '')));
	/* v2.9.58: az idézett levél (akár több száz KB) változatlan → nem szűrjük újra (a tárolt, már szűrt változat marad; „qh” = a beküldött idézet lenyomata) */
	$qraw = (string) ($in['quote'] ?? ''); $qh = md5($qraw);
	$quote = $id !== '' && isset($all[$id]) && ($all[$id]['qh'] ?? '') === $qh ? (string) ($all[$id]['quote'] ?? '') : mb_substr(cp_mail_clean($qraw), 0, 300000);
	$addr = function ($l) { return array_slice(array_values(array_filter(array_map(function ($e) { return sanitize_email((string) $e); }, (array) $l))), 0, 20); };
	$x = array('to' => $addr($in['to'] ?? array()), 'cc' => $addr($in['cc'] ?? array()), 'bcc' => $addr($in['bcc'] ?? array()),
		'subject' => mb_substr(sanitize_text_field((string) ($in['subject'] ?? '')), 0, 255), 'body' => mb_substr(cp_mail_clean((string) ($in['body'] ?? '')), 0, 400000),
		'thread' => (int) ($in['thread'] ?? 0), 'tsubj' => mb_substr(sanitize_text_field((string) ($in['tsubj'] ?? '')), 0, 255), 'quote' => $quote, 'qh' => $qh,
		'deal' => (int) ($in['deal'] ?? 0), 'dealTitle' => mb_substr(sanitize_text_field((string) ($in['dealTitle'] ?? '')), 0, 255), 'known' => (int) ($in['known'] ?? 0),
		'atts' => array_slice(array_values(array_filter(array_map(function ($a) { return is_array($a) && !empty($a['tok']) ? array('n' => mb_substr(sanitize_text_field((string) ($a['n'] ?? '')), 0, 190), 'size' => (int) ($a['size'] ?? 0), 'tok' => preg_replace('/[^A-Za-z0-9._-]/', '', (string) $a['tok'])) : null; }, (array) ($in['atts'] ?? array())))), 0, 20), 't' => time());
	if ($id === '' || !isset($all[$id])) { $id = 'd' . base_convert((string) time(), 10, 36) . wp_generate_password(5, false, false); $id = strtolower(preg_replace('/[^a-z0-9]/i', '', $id)); }
	/* üres piszkozatot nem tárol (és a meglévőt törli) */
	$empty = !$x['to'] && !$x['cc'] && $x['subject'] === '' && trim(wp_strip_all_tags(str_replace((string) $x['quote'], '', (string) $x['body']))) === '' && !$x['atts'];
	if ($empty) { unset($all[$id]); cp_mail_drafts_put($me, $all); wp_send_json_success(array('id' => '', 'empty' => 1)); }
	$all[$id] = $x;
	if (count($all) > 60) { uasort($all, function ($a, $b) { return (int) $b['t'] - (int) $a['t']; }); $all = array_slice($all, 0, 60, true); }
	cp_mail_drafts_put($me, $all);
	wp_send_json_success(array('id' => $id, 'at' => wp_date('H:i')));
}
add_action('wp_ajax_nopriv_cp_mail_draft_list', 'cp_mail_ajax_draft_list');
add_action('wp_ajax_cp_mail_draft_list', 'cp_mail_ajax_draft_list');
function cp_mail_ajax_draft_list() {
	$me = cp_mail_draft_me();
	$all = cp_mail_drafts_get($me);
	uasort($all, function ($a, $b) { return (int) $b['t'] - (int) $a['t']; });
	$rows = array(); foreach ($all as $id => $x) { $rows[] = cp_mail_draft_row($id, $x); }
	wp_send_json_success(array('rows' => $rows));
}
add_action('wp_ajax_nopriv_cp_mail_draft_get', 'cp_mail_ajax_draft_get');
add_action('wp_ajax_cp_mail_draft_get', 'cp_mail_ajax_draft_get');
function cp_mail_ajax_draft_get() {
	$me = cp_mail_draft_me();
	$all = cp_mail_drafts_get($me);
	$id = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($_POST['id'] ?? '')));
	if ($id === '' && !empty($_POST['thread'])) { foreach ($all as $k => $x) { if ((int) ($x['thread'] ?? 0) === (int) $_POST['thread']) { $id = (string) $k; } } }
	if ($id === '' || !isset($all[$id])) { cp_el_err('Ez a piszkozat már nincs meg (elküldték vagy elvetették).', 404); }
	wp_send_json_success(array('id' => $id, 'd' => $all[$id]));
}
add_action('wp_ajax_nopriv_cp_mail_draft_del', 'cp_mail_ajax_draft_del');
add_action('wp_ajax_cp_mail_draft_del', 'cp_mail_ajax_draft_del');
function cp_mail_ajax_draft_del() {
	$me = cp_mail_draft_me();
	$all = cp_mail_drafts_get($me);
	/* v2.9.173: több piszkozat egyszerre (ids: vesszővel elválasztva) */
	foreach (array_slice(explode(',', (string) ($_POST['ids'] ?? ($_POST['id'] ?? ''))), 0, 500) as $x) { unset($all[preg_replace('/[^a-z0-9]/', '', strtolower($x))]); }
	cp_mail_drafts_put($me, $all);
	wp_send_json_success(array('n' => count($all)));
}
/* a szál-listához: mely szálakhoz van saját félbehagyott válasz */
function cp_mail_draft_threads() {
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if ($me <= 0) { return array(); }
	$o = array(); foreach (cp_mail_drafts_get($me) as $x) { if (!empty($x['thread'])) { $o[(int) $x['thread']] = 1; } }
	return $o;
}

require_once __DIR__ . '/mail-tags.php';
require_once __DIR__ . '/mail-zip.php';
require_once __DIR__ . '/mail-drive.php';
