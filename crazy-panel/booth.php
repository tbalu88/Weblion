<?php
/**
 * Crazy panel – Crazy Booth szelfigépek kiszolgálása (v2.9.238).
 * - Gépkulcsok: WP admin → Beállítások → Crazy Booth gépek (a kulcs csak létrehozáskor látszik, sha256-tal tárolva; letiltható).
 * - A gépeknek (X-Booth-Key fejléc): a nap foglalt rendezvényei, a dealhez az ügyfélportálon mentett nyomtatható sablon, a deal galéria-azonosítója.
 * - A tartalék szerver galériájának: kulcs-ellenőrzés (csak sha256-ot kap, a kulcsot nem).
 * A Salesmate-be semmi nem íródik.
 */
if (!defined('ABSPATH')) { exit; }
define('CP_BOOTH_GAL', 'https://tartalek.eventcrazy.hu/galeria/');

function cp_booth_devs() { $d = get_option('cp_booth_devices', array()); return is_array($d) ? $d : array(); }
function cp_booth_dev_by_hash($h) {
	foreach (cp_booth_devs() as $id => $d) { if (!empty($d['h']) && hash_equals($d['h'], (string) $h) && empty($d['off'])) { return array('id' => $id) + $d; } }
	return null;
}
function cp_booth_auth() {
	$k = isset($_SERVER['HTTP_X_BOOTH_KEY']) ? trim((string) $_SERVER['HTTP_X_BOOTH_KEY']) : '';
	if (strlen($k) < 20) { return null; }
	$d = cp_booth_dev_by_hash(hash('sha256', $k));
	if ($d) { $all = cp_booth_devs(); if (time() - (int) ($all[$d['id']]['seen'] ?? 0) > 300) { $all[$d['id']]['seen'] = time(); update_option('cp_booth_devices', $all, false); } }
	return $d;
}
/* a deal galéria-azonosítója (egyszer generált, utána állandó) */
function cp_booth_gal_tok($deal) {
	$m = get_option('cp_booth_gal', array()); if (!is_array($m)) { $m = array(); }
	$deal = (string) (int) $deal;
	if (empty($m[$deal])) { $m[$deal] = strtolower(wp_generate_password(14, false, false)); update_option('cp_booth_gal', $m, false); }
	return $m[$deal];
}
function cp_booth_gal_url($deal) { return CP_BOOTH_GAL . cp_booth_gal_tok($deal) . '/'; }
function cp_booth_gal_has($deal) { $m = get_option('cp_booth_gal', array()); return is_array($m) && !empty($m[(string) (int) $deal]); }

add_action('rest_api_init', function () {
	/* a tartalék galéria kérdezi: él-e ez a kulcs (sha256) */
	register_rest_route('crazy/v1', '/booth/verify', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
		$d = cp_booth_dev_by_hash((string) $r->get_param('h'));
		return $d ? array('ok' => true, 'name' => $d['name']) : array('ok' => false);
	}));
	$need = function () { return (bool) cp_booth_auth(); };
	/* a nap foglalt rendezvényei */
	register_rest_route('crazy/v1', '/booth/events', array('methods' => 'GET', 'permission_callback' => $need, 'callback' => function (WP_REST_Request $r) {
		$date = (string) $r->get_param('date');
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = wp_date('Y-m-d'); }
		$info = null; $rows = cp_sm_fetch_range($date, $date, $info);
		if (is_wp_error($rows)) { return new WP_REST_Response(array('ok' => false, 'message' => 'A rendezvények most nem kérhetők le.'), 502); }
		$out = array();
		foreach (cp_panel_normalize($rows) as $e) {
			if ($e['date'] !== $date || $e['state'] !== 'booked') { continue; }
			$deal = function_exists('cp_mday_base') ? cp_mday_base($e['id']) : $e['id'];
			$p = function_exists('cp_portal_get_by_deal') ? cp_portal_get_by_deal($deal) : null;
			$tpl = (bool) cp_booth_tpl_get((int) $deal);
			if (!$tpl && $p) { $a = cp_portal_answers($p->id); $tpl = !empty($a['photo_template']['png']); }
			$out[] = array('deal' => (int) $deal, 'title' => $e['title'], 'time' => $e['time'] ?: (is_array($e['win'] ?? null) ? sprintf('%02d:%02d', intdiv($e['win'][0], 60), $e['win'][0] % 60) : ''), 'kind' => $e['kind'], 'loc' => $e['loc'], 'template' => $tpl, 'ev' => cp_booth_gal_tok($deal), 'gallery' => cp_booth_gal_url($deal));
		}
		return array('ok' => true, 'date' => $date, 'events' => $out);
	}));
	/* a deal sablonja: a portálon mentett nyomtatható PNG (átlátszó fotóablakokkal), base64 */
	register_rest_route('crazy/v1', '/booth/template', array('methods' => 'GET', 'permission_callback' => $need, 'callback' => function (WP_REST_Request $r) {
		$deal = (int) $r->get_param('deal');
		$p = $deal && function_exists('cp_portal_get_by_deal') ? cp_portal_get_by_deal($deal) : null;
		$a = $p ? cp_portal_answers($p->id) : array();
		$t = isset($a['photo_template']) && is_array($a['photo_template']) ? $a['photo_template'] : null;
		/* v2.9.243: a panel Sablonkészítőjéből a szelfigépnek mentett sablon – ha az újabb, az a nyerő */
		$bt = cp_booth_tpl_get($deal);
		if ($bt && (!$t || empty($t['png']) || (int) $bt['at'] >= (int) ($t['at'] ?? 0))) {
			return array('ok' => true, 'template' => array('name' => (string) $bt['name'], 'at' => (int) $bt['at'], 'src' => 'panel', 'png' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($bt['path']))));
		}
		if (!$t) { return array('ok' => true, 'template' => null); }
		$f = !empty($t['png']) ? $t['png'] : '';
		$path = $f !== '' ? cp_portal_sb_dir() . '/' . $f : '';
		if ($path === '' || !is_file($path)) { return array('ok' => true, 'template' => null, 'note' => 'Nincs nyomtatható sablon (csak előnézet).'); }
		return array('ok' => true, 'template' => array('name' => (string) ($t['name'] ?? ''), 'at' => (int) ($t['at'] ?? 0), 'png' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($path))));
	}));
});

/* v2.9.243: a panel Sablonkészítőjéből a szelfigépnek mentett sablon (dealenként egy, a legutóbbi) */
function cp_booth_tpl_get($deal) {
	$m = get_option('cp_booth_tpl', array()); $x = is_array($m) && isset($m[(string) (int) $deal]) ? $m[(string) (int) $deal] : null;
	if (!$x || empty($x['f']) || !function_exists('cp_sb_dir')) { return null; }
	$x['path'] = cp_sb_dir() . '/' . basename($x['f']);
	return is_file($x['path']) ? $x : null;
}
add_action('wp_ajax_nopriv_cp_booth_tpl_save', 'cp_booth_ajax_tpl_save'); add_action('wp_ajax_cp_booth_tpl_save', 'cp_booth_ajax_tpl_save');
function cp_booth_ajax_tpl_save() {
	cp_el_auth();
	cp_tab_need(array('sablon'));
	$deal = (int) ($_POST['deal'] ?? 0);
	$png = (string) wp_unslash($_POST['png'] ?? '');
	if (!$deal || strncmp($png, 'data:image/png;base64,', 22) !== 0) { cp_el_err('Hibás kérés.'); }
	$bin = base64_decode(substr($png, 22), true);
	if (!$bin || strlen($bin) > 15 * MB_IN_BYTES || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") { cp_el_err('A sablon képe hibás vagy túl nagy.'); }
	$m = get_option('cp_booth_tpl', array()); if (!is_array($m)) { $m = array(); }
	$old = $m[(string) $deal] ?? null;
	$f = 'booth-' . $deal . '-' . strtolower(wp_generate_password(10, false, false)) . '.png';
	if (@file_put_contents(cp_sb_dir() . '/' . $f, $bin) === false) { cp_el_err('A sablont nem sikerült menteni.', 500); }
	if ($old && !empty($old['f'])) { @unlink(cp_sb_dir() . '/' . basename($old['f'])); }
	$m[(string) $deal] = array('f' => $f, 'at' => time(), 'name' => mb_substr(sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))), 0, 80), 'by' => function_exists('cp_panel_who') ? cp_panel_who() : '');
	update_option('cp_booth_tpl', $m, false);
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'booth_tpl', 'Sablon mentve a szelfigéphez', 'deal:' . $deal, $m[(string) $deal]['name']); }
	wp_send_json_success(array('ok' => true));
}

/* v2.9.241: a deal adatlapjára – a galéria linkje és a képek száma (a tartalék galériából, 10 percig tárolva) */
function cp_booth_gal_count($deal) {
	$tok = cp_booth_gal_tok($deal);
	$ck = 'cp_booth_galn_' . $tok;
	$n = get_transient($ck);
	if ($n === false) {
		$r = wp_remote_get('https://tartalek.eventcrazy.hu/wp-json/crazy-galeria/v1/event/' . rawurlencode($tok), array('timeout' => 5));
		$b = is_wp_error($r) ? null : json_decode(wp_remote_retrieve_body($r), true);
		$n = is_array($b) && isset($b['n']) ? (int) $b['n'] : -1;
		set_transient($ck, $n, $n >= 0 ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS);
	}
	return (int) $n;
}
add_filter('cp_crm_deal_data', function ($x) {
	$id = isset($x['deal']['id']) ? (int) $x['deal']['id'] : 0;
	if ($id && cp_booth_gal_has($id)) { $x['gal'] = array('url' => cp_booth_gal_url($id), 'n' => cp_booth_gal_count($id)); }
	return $x;
}, 22);

/* v2.9.242: a panel saját kulcsa a galéria kezeléséhez („Panel” nevű belső gép) */
function cp_booth_panel_key() {
	$k = get_option('cp_booth_pkey', '');
	if (!is_string($k) || strlen($k) < 30) { $k = 'cb_' . wp_generate_password(40, false, false); update_option('cp_booth_pkey', $k, false); }
	$d = cp_booth_devs(); $h = hash('sha256', $k);
	if (empty($d['panel']) || $d['panel']['h'] !== $h || !empty($d['panel']['off'])) { $d['panel'] = array('name' => 'Panel', 'h' => $h, 'at' => time(), 'seen' => 0, 'off' => 0); update_option('cp_booth_devices', $d, false); }
	return $k;
}
function cp_booth_gal_api($method, $ev, $body = null) {
	$args = array('method' => $method, 'timeout' => 15, 'headers' => array('X-Booth-Key' => cp_booth_panel_key()));
	if ($body !== null) { $args['body'] = $body; }
	$r = wp_remote_request('https://tartalek.eventcrazy.hu/wp-json/crazy-galeria/v1/admin/' . rawurlencode($ev), $args);
	if (is_wp_error($r)) { return new WP_Error('gal', 'A galéria most nem érhető el.'); }
	$b = json_decode(wp_remote_retrieve_body($r), true);
	if ((int) wp_remote_retrieve_response_code($r) !== 200 || !is_array($b)) { return new WP_Error('gal', 'A galéria nem fogadta a kérést (' . wp_remote_retrieve_response_code($r) . ').'); }
	return $b;
}
/* a deal galériájának kezelése a panelből: képek, cím, jelszó, elrejtés */
add_action('wp_ajax_nopriv_cp_booth_gal', 'cp_booth_ajax_gal'); add_action('wp_ajax_cp_booth_gal', 'cp_booth_ajax_gal');
function cp_booth_ajax_gal() {
	cp_crm_ajax_guard(array('deals'));
	$deal = (int) ($_POST['deal'] ?? 0);
	if (!$deal || !cp_booth_gal_has($deal)) { cp_el_err('Ennek a dealnek még nincs galériája.', 404); }
	$ev = cp_booth_gal_tok($deal);
	$body = null;
	foreach (array('title', 'password', 'photo', 'hidden', 'hook') as $k) { if (isset($_POST[$k])) { $body[$k] = sanitize_text_field(wp_unslash((string) $_POST[$k])); } }
	if ($body && isset($body['password']) && function_exists('cp_audit_add')) { cp_audit_add('deal', 'gal_pw', $body['password'] === '' ? 'Galéria jelszava törölve' : 'Galéria jelszava beállítva', 'deal:' . $deal, ''); }
	$r = cp_booth_gal_api($body ? 'POST' : 'GET', $ev, $body);
	if (is_wp_error($r)) { cp_el_err($r->get_error_message(), 502); }
	if ($body) { delete_transient('cp_booth_galn_' . $ev); $r = cp_booth_gal_api('GET', $ev); if (is_wp_error($r)) { cp_el_err($r->get_error_message(), 502); } }
	wp_send_json_success($r);
}

/* ---------- WP admin: gépek kezelése */
add_action('admin_menu', function () { add_options_page('Crazy Booth gépek', 'Crazy Booth gépek', 'manage_options', 'cp-booth', 'cp_booth_admin'); });
function cp_booth_admin() {
	if (!current_user_can('manage_options')) { return; }
	$devs = cp_booth_devs(); $newkey = '';
	if (!empty($_POST['cp_booth_nonce']) && wp_verify_nonce($_POST['cp_booth_nonce'], 'cp_booth')) {
		if (!empty($_POST['add']) && trim((string) $_POST['name']) !== '') {
			$newkey = 'cb_' . wp_generate_password(40, false, false);
			$id = 'd' . time() . wp_rand(10, 99);
			$devs[$id] = array('name' => sanitize_text_field(wp_unslash($_POST['name'])), 'h' => hash('sha256', $newkey), 'at' => time(), 'seen' => 0, 'off' => 0);
		}
		if (!empty($_POST['toggle']) && isset($devs[$_POST['toggle']])) { $devs[$_POST['toggle']]['off'] = empty($devs[$_POST['toggle']]['off']) ? 1 : 0; }
		update_option('cp_booth_devices', $devs, false);
	}
	echo '<div class="wrap"><h1>Crazy Booth gépek</h1><p>Minden szelfigép saját kulcsot kap. A kulcsot a gép Beállításaiban (F2) kell megadni. A kulcs <b>csak most</b> látszik – ha elveszik, készíts újat, a régit tiltsd le.</p>';
	if ($newkey !== '') { echo '<div class="notice notice-success"><p><b>Az új gép kulcsa:</b> <code style="font-size:15px;user-select:all">' . esc_html($newkey) . '</code></p></div>'; }
	echo '<form method="post">'; wp_nonce_field('cp_booth', 'cp_booth_nonce');
	echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>Gép</th><th>Létrehozva</th><th>Utoljára jelentkezett</th><th>Állapot</th><th></th></tr></thead><tbody>';
	foreach ($devs as $id => $d) {
		if ($id === 'panel') { echo '<tr><td>Panel (belső – a galéria kezeléséhez)</td><td>' . esc_html(wp_date('Y.m.d. H:i', (int) $d['at'])) . '</td><td>' . ($d['seen'] ? esc_html(wp_date('Y.m.d. H:i', (int) $d['seen'])) : '–') . '</td><td>aktív</td><td></td></tr>'; continue; }
		echo '<tr><td>' . esc_html($d['name']) . '</td><td>' . esc_html(wp_date('Y.m.d. H:i', (int) $d['at'])) . '</td><td>' . ($d['seen'] ? esc_html(wp_date('Y.m.d. H:i', (int) $d['seen'])) : '–') . '</td><td>' . (empty($d['off']) ? 'aktív' : '<b>letiltva</b>') . '</td><td><button class="button" name="toggle" value="' . esc_attr($id) . '">' . (empty($d['off']) ? 'Letiltás' : 'Engedélyezés') . '</button></td></tr>';
	}
	if (!$devs) { echo '<tr><td colspan="5">Még nincs gép.</td></tr>'; }
	echo '</tbody></table><p><input name="name" placeholder="pl. Szelfigép 1 (Mac)" class="regular-text"> <button class="button button-primary" name="add" value="1">Új gép + kulcs</button></p></form></div>';
}

/* ---------- v2.9.244: vendégadatok a szelfigépről (név, e-mail, telefon, kérdőív) – CSAK a panelben, a dealhez kötve,
 * 60 nap után törlődnek; a Salesmate-be nem kerülnek. Ha a vendég kéri, a panel levélküldője elküldi neki a fotója linkjét. */
function cp_bg_t() { global $wpdb; return $wpdb->prefix . 'cp_booth_guest'; }
add_action('plugins_loaded', function () {
	if (get_option('cp_bg_db') === '1') { return; }
	global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_bg_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  deal bigint(20) unsigned NOT NULL DEFAULT 0,
  ev varchar(32) NOT NULL DEFAULT '',
  photo varchar(32) NOT NULL DEFAULT '',
  name varchar(120) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  answers text NULL,
  consent tinyint(1) NOT NULL DEFAULT 0,
  mail varchar(20) NOT NULL DEFAULT '',
  device varchar(60) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY deal (deal),
  KEY created_at (created_at)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_bg_db', '1', false);
}, 30);
/* 60 napos törlés (naponta egyszer) */
add_action('init', function () {
	if (get_option('cp_bg_db') !== '1' || get_transient('cp_bg_purge')) { return; }
	set_transient('cp_bg_purge', 1, DAY_IN_SECONDS);
	global $wpdb; $wpdb->query($wpdb->prepare('DELETE FROM ' . cp_bg_t() . ' WHERE created_at < %s', gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS)));
});
function cp_bg_mail($row, $title) {
	$url = 'https://tartalek.eventcrazy.hu/galeria/' . $row['ev'] . '/' . $row['photo'];
	$gal = 'https://tartalek.eventcrazy.hu/galeria/' . $row['ev'] . '/';
	$hi = $row['name'] !== '' ? 'Kedves ' . esc_html($row['name']) . '!' : 'Szia!';
	$t = $title !== '' ? esc_html($title) : 'a rendezvényen';
	$body = '<p>' . $hi . '</p><p>Itt a fotód, ami ' . ($title !== '' ? 'a(z) <b>' . $t . '</b> rendezvényen' : 'a rendezvényen') . ' készült a szelfigépünkkel:</p>' .
		'<p style="margin:22px 0"><a href="' . esc_url($url) . '" style="background:#E8894A;color:#141C33;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:bold">A fotóm megnyitása és letöltése</a></p>' .
		'<p>A rendezvény többi fotója: <a href="' . esc_url($gal) . '">' . esc_html(preg_replace('#^https?://#', '', $gal)) . '</a></p>' .
		'<p style="color:#666;font-size:13px">A képeket 60 napig őrizzük, utána automatikusan töröljük – addig töltsd le, amit szeretnél megtartani. Ezt a levelet azért kaptad, mert a szelfigépen megadtad az e-mail-címedet.</p>';
	$doc = '<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1A1F2B">' . $body . '<p style="color:#999;font-size:12px">EventCrazy · eventcrazy.hu</p></body></html>';
	try {
		$m = cp_send_mailer();
		$m->addAddress($row['email'], $row['name']);
		$m->Subject = 'A fotód' . ($title !== '' ? ' – ' . $title : '') . ' 📸';
		$m->isHTML(true); $m->Body = $doc;
		$m->AltBody = ($row['name'] !== '' ? 'Kedves ' . $row['name'] . "!\n\n" : '') . "Itt a fotód: " . $url . "\nA rendezvény többi fotója: " . $gal . "\n\nA képeket 60 napig őrizzük.\nEventCrazy";
		$m->XMailer = 'Crazy panel';
		$m->send();
		return 'sent';
	} catch (\Throwable $ex) { return 'err'; }
}
add_action('rest_api_init', function () {
	register_rest_route('crazy/v1', '/booth/guest', array('methods' => 'POST', 'permission_callback' => function () { return (bool) cp_booth_auth(); }, 'callback' => function (WP_REST_Request $r) {
		global $wpdb;
		if (get_option('cp_bg_db') !== '1') { return new WP_REST_Response(array('ok' => false, 'message' => 'A vendégtábla még nem jött létre.'), 503); }
		$dev = cp_booth_auth(); $deal = (int) $r->get_param('deal'); $ev = (string) $r->get_param('ev'); $ph = (string) $r->get_param('photo');
		if (!$deal || !preg_match('/^[a-z0-9]{6,32}$/', $ev) || $ev !== cp_booth_gal_tok($deal)) { return new WP_REST_Response(array('ok' => false, 'message' => 'Ismeretlen rendezvény.'), 400); }
		if ($ph !== '' && !preg_match('/^[a-z0-9]{6,32}$/', $ph)) { $ph = ''; }
		if (!$r->get_param('consent')) { return new WP_REST_Response(array('ok' => false, 'message' => 'Hozzájárulás nélkül nem tárolunk adatot.'), 400); }
		$email = sanitize_email((string) $r->get_param('email')); if ($email !== '' && !is_email($email)) { $email = ''; }
		$row = array('deal' => $deal, 'ev' => $ev, 'photo' => $ph, 'name' => mb_substr(sanitize_text_field((string) $r->get_param('name')), 0, 120), 'email' => mb_substr($email, 0, 190), 'phone' => mb_substr(preg_replace('/[^0-9+ ()\/-]/', '', (string) $r->get_param('phone')), 0, 40), 'consent' => 1, 'mail' => '', 'device' => mb_substr((string) $dev['name'], 0, 60), 'created_at' => current_time('mysql', true));
		$ans = $r->get_param('answers'); $clean = array();
		if (is_array($ans)) { foreach (array_slice($ans, 0, 6, true) as $q => $a) { $clean[mb_substr(sanitize_text_field((string) $q), 0, 120)] = mb_substr(sanitize_text_field((string) $a), 0, 300); } }
		$row['answers'] = $clean ? wp_json_encode($clean, JSON_UNESCAPED_UNICODE) : '';
		if ($row['name'] === '' && $row['email'] === '' && $row['phone'] === '' && !$clean) { return array('ok' => true, 'skipped' => true); }
		/* ugyanaz a vendég + kép újraküldése (a gép offline sora) ne duplikáljon és ne küldjön kétszer */
		$dup = $wpdb->get_row($wpdb->prepare('SELECT id, mail FROM ' . cp_bg_t() . ' WHERE ev = %s AND photo = %s AND email = %s AND name = %s', $ev, $ph, $row['email'], $row['name']), ARRAY_A);
		if ($dup) { return array('ok' => true, 'id' => (int) $dup['id'], 'mail' => $dup['mail'], 'dup' => true); }
		$wpdb->insert(cp_bg_t(), $row); $id = (int) $wpdb->insert_id;
		if ($r->get_param('send') && $row['email'] !== '' && $ph !== '' && function_exists('cp_send_mailer') && cp_send_ready()) {
			/* visszaélés ellen: gépenként óránként legfeljebb 300 levél */
			$ck = 'cp_bg_rate_' . md5($row['device']); $n = (int) get_transient($ck);
			if ($n < 300) { set_transient($ck, $n + 1, HOUR_IN_SECONDS); $row['mail'] = cp_bg_mail($row, mb_substr(sanitize_text_field((string) $r->get_param('title')), 0, 120)); }
			else { $row['mail'] = 'limit'; }
			$wpdb->update(cp_bg_t(), array('mail' => $row['mail']), array('id' => $id));
		}
		return array('ok' => true, 'id' => $id, 'mail' => $row['mail']);
	}));
});
/* a deal adatlapjára: a vendégadatok száma (a lista CSV-ben tölthető le) */
add_filter('cp_crm_deal_data', function ($x) {
	$id = isset($x['deal']['id']) ? (int) $x['deal']['id'] : 0;
	if ($id && get_option('cp_bg_db') === '1') { global $wpdb; $n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_bg_t() . ' WHERE deal = %d', $id)); if ($n) { $x['guests'] = array('n' => $n, 'mailed' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . cp_bg_t() . " WHERE deal = %d AND mail = 'sent'", $id))); } }
	return $x;
}, 23);
add_action('wp_ajax_nopriv_cp_booth_guests', 'cp_booth_ajax_guests'); add_action('wp_ajax_cp_booth_guests', 'cp_booth_ajax_guests');
function cp_booth_ajax_guests() {
	cp_crm_ajax_guard(array('deals'));
	global $wpdb; $deal = (int) ($_POST['deal'] ?? 0);
	$rows = $deal ? $wpdb->get_results($wpdb->prepare('SELECT name, email, phone, answers, mail, photo, created_at FROM ' . cp_bg_t() . ' WHERE deal = %d ORDER BY id', $deal), ARRAY_A) : array();
	$qs = array(); foreach ($rows as $x) { $a = json_decode((string) $x['answers'], true); if (is_array($a)) { foreach ($a as $q => $_) { $qs[$q] = 1; } } }
	$qs = array_keys($qs);
	$esc = function ($v) { $v = (string) $v; if ($v !== '' && strpos('=+-@', $v[0]) !== false) { $v = "'" . $v; } return '"' . str_replace('"', '""', $v) . '"'; };
	$out = array(implode(';', array_map($esc, array_merge(array('Időpont', 'Név', 'E-mail', 'Telefon'), $qs, array('Levél', 'Fotó')))));
	foreach ($rows as $x) {
		$a = json_decode((string) $x['answers'], true); $a = is_array($a) ? $a : array();
		$line = array(get_date_from_gmt($x['created_at'], 'Y.m.d. H:i'), $x['name'], $x['email'], $x['phone']);
		foreach ($qs as $q) { $line[] = $a[$q] ?? ''; }
		$line[] = $x['mail'] === 'sent' ? 'elküldve' : ($x['mail'] === 'err' ? 'hiba' : ($x['mail'] === 'limit' ? 'korlát' : '')); $line[] = $x['photo'];
		$out[] = implode(';', array_map($esc, $line));
	}
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'booth_guests_csv', 'Vendégadatok letöltve (' . count($rows) . ' sor)', 'deal:' . $deal, ''); }
	wp_send_json_success(array('csv' => "\xEF\xBB\xBF" . implode("\r\n", $out), 'n' => count($rows)));
}

/* ---------- v2.9.246 (Crazy Booth 7. fázis): gépállapot (v7), távoli beállítások (v8), statisztika + ügyfélriport (v10), sorsolás (d4).
 * A gépek percenként jelentkeznek (állapot); a válaszban kapják a deal panelből mentett beállításait. Salesmate-be semmi nem megy. */
const CP_BOOTH_MODES = array('photo', 'glam', 'group', 'gif', 'boom', 'video', 'slow', 'guestbook');
function cp_booth_beat_get($id) { $b = get_transient('cp_bb_' . $id); return is_array($b) ? $b : null; }
function cp_booth_dcfg_all() { $a = get_option('cp_booth_dcfg', array()); return is_array($a) ? $a : array(); }
function cp_booth_dcfg_get($deal) { $a = cp_booth_dcfg_all(); return isset($a[(string) (int) $deal]) && is_array($a[(string) (int) $deal]) ? $a[(string) (int) $deal] : null; }
/* a panelből a gépre küldhető beállítások (fehérlista + korlátok) */
function cp_booth_dcfg_clean($in) {
	$in = is_array($in) ? $in : array(); $o = array(); $ui = array(); $sc = array();
	$num = function ($v, $a, $b) { $v = (int) $v; return max($a, min($b, $v)); };
	if (isset($in['shots'])) { $o['shots'] = (int) $in['shots'] >= 3 ? 3 : 1; }
	foreach (array('countdown' => array(1, 10), 'copies' => array(1, 10), 'reviewSec' => array(5, 120)) as $k => $r) { if (isset($in[$k]) && $in[$k] !== '') { $o[$k] = $num($in[$k], $r[0], $r[1]); } }
	if (isset($in['autoPrint'])) { $o['autoPrint'] = (bool) $in['autoPrint']; }
	if (isset($in['modes']) && is_array($in['modes'])) { $m = array_values(array_intersect(CP_BOOTH_MODES, array_map('strval', $in['modes']))); if ($m) { $ui['modes'] = $m; } }
	foreach (array('cdNext' => array(1, 10), 'groupCd' => array(3, 15), 'videoSec' => array(3, 30), 'gbSec' => array(10, 90), 'limitPhoto' => array(0, 20), 'limitEvent' => array(0, 5000)) as $k => $r) { if (isset($in[$k]) && $in[$k] !== '') { $ui[$k] = $num($in[$k], $r[0], $r[1]); } }
	foreach (array('bestOf', 'retake', 'shareEmail', 'formBefore') as $k) { if (isset($in[$k])) { $ui[$k] = (bool) $in[$k]; } }
	if (isset($in['askName']) && in_array($in['askName'], array('off', 'opt', 'req'), true)) { $ui['askName'] = $in['askName']; }
	if (isset($in['questions'])) { $ui['questions'] = mb_substr(implode("\n", array_slice(array_filter(array_map('trim', explode("\n", sanitize_textarea_field((string) $in['questions'])))), 0, 3)), 0, 300); }
	foreach (array('t1' => 60, 't2' => 80, 'brand' => 60) as $k => $n) { if (isset($in[$k])) { $sc[$k] = mb_substr(sanitize_text_field((string) $in[$k]), 0, $n); } }
	if (isset($in['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $in['color'])) { $sc['color'] = (string) $in['color']; }
	if ($ui) { $o['ui'] = $ui; } if ($sc) { $o['screen'] = $sc; }
	return $o;
}
add_action('rest_api_init', function () {
	register_rest_route('crazy/v1', '/booth/beat', array('methods' => 'POST', 'permission_callback' => function () { return (bool) cp_booth_auth(); }, 'callback' => function (WP_REST_Request $r) {
		$d = cp_booth_auth(); $j = $r->get_json_params(); $j = is_array($j) ? $j : array();
		$deal = (int) ($j['deal'] ?? 0); $paper = array();
		if (isset($j['paper']) && is_array($j['paper'])) { foreach (array_slice($j['paper'], 0, 4, true) as $k => $v) { $paper[mb_substr(sanitize_text_field((string) $k), 0, 60)] = $v === null ? null : max(0, (int) $v); } }
		$b = array('t' => time(), 'name' => (string) $d['name'], 'deal' => $deal, 'title' => mb_substr(sanitize_text_field((string) ($j['title'] ?? '')), 0, 120),
			'ver' => mb_substr(sanitize_text_field((string) ($j['ver'] ?? '')), 0, 40), 'scr' => mb_substr(sanitize_key((string) ($j['scr'] ?? '')), 0, 12),
			'paper' => $paper, 'queue' => max(0, (int) ($j['queue'] ?? 0)), 'guests' => max(0, (int) ($j['guests'] ?? 0)), 'prints' => max(0, (int) ($j['prints'] ?? 0)),
			'err' => mb_substr(sanitize_text_field((string) ($j['err'] ?? '')), 0, 160), 'err_at' => max(0, (int) ($j['err_at'] ?? 0)), 'cam' => array_key_exists('cam', $j) && $j['cam'] !== null ? (bool) $j['cam'] : null, 'net' => !empty($j['net']), 'station' => !empty($j['station']));
		set_transient('cp_bb_' . $d['id'], $b, 3 * DAY_IN_SECONDS);
		/* a nyomatok száma dealenként és gépenként (a riporthoz: a legnagyobb jelentett érték marad meg) */
		if ($deal && $b['prints']) { $p = get_option('cp_booth_prints', array()); if (!is_array($p)) { $p = array(); } $k = (string) $deal; if ((int) ($p[$k][$d['id']] ?? 0) < $b['prints']) { $p[$k][$d['id']] = $b['prints']; update_option('cp_booth_prints', $p, false); } }
		$c = $deal ? cp_booth_dcfg_get($deal) : null;
		return array('ok' => true, 'cfg' => $c ? $c['cfg'] : null, 'at' => $c ? (int) $c['at'] : 0);
	}));
});
/* a deal statisztikája a galériából (5 percig tárolva) */
function cp_booth_stats($deal) {
	if (!cp_booth_gal_has($deal)) { return null; }
	$ev = cp_booth_gal_tok($deal); $ck = 'cp_booth_st_' . $ev; $s = get_transient($ck);
	if (is_array($s)) { return $s; }
	$g = cp_booth_gal_api('GET', $ev);
	if (is_wp_error($g)) { return null; }
	$s = array('photos' => 0, 'videos' => 0, 'guestbook' => 0, 'hidden' => 0, 'hours' => array(), 'devices' => array(), 'first' => '', 'last' => '');
	foreach ((array) ($g['photos'] ?? array()) as $x) {
		$k = (string) ($x['kind'] ?? 'photo'); if ($k === 'guestbook') { $s['guestbook']++; } elseif ($k === 'video') { $s['videos']++; } else { $s['photos']++; }
		if (!empty($x['hidden'])) { $s['hidden']++; }
		$loc = get_date_from_gmt((string) $x['created_at'], 'Y-m-d H:i'); $h = substr($loc, 11, 2) . ':00';
		$s['hours'][$h] = ($s['hours'][$h] ?? 0) + 1; $dv = (string) ($x['device'] ?? ''); if ($dv !== '') { $s['devices'][$dv] = ($s['devices'][$dv] ?? 0) + 1; }
		if ($s['first'] === '' || $loc < $s['first']) { $s['first'] = $loc; } if ($loc > $s['last']) { $s['last'] = $loc; }
	}
	ksort($s['hours']);
	set_transient($ck, $s, 5 * MINUTE_IN_SECONDS);
	return $s;
}
add_filter('cp_crm_deal_data', function ($x) {
	$id = isset($x['deal']['id']) ? (int) $x['deal']['id'] : 0; if (!$id) { return $x; }
	$m = array();
	foreach (cp_booth_devs() as $did => $dv) { if ($did === 'panel') { continue; } $b = cp_booth_beat_get($did); if ($b && (int) $b['deal'] === $id && time() - (int) $b['t'] < 12 * HOUR_IN_SECONDS) { $b['ago'] = time() - (int) $b['t']; $m[] = $b; } }
	$p = get_option('cp_booth_prints', array()); $prints = is_array($p) && isset($p[(string) $id]) ? array_sum(array_map('intval', $p[(string) $id])) : 0;
	$c = cp_booth_dcfg_get($id);
	$x['booth'] = array('machines' => $m, 'cfg' => $c ? $c['cfg'] : null, 'cfg_at' => $c ? (int) $c['at'] : 0, 'cfg_by' => $c ? (string) ($c['by'] ?? '') : '', 'prints' => $prints, 'stats' => cp_booth_stats($id),
		'show' => cp_booth_gal_has($id) ? cp_booth_gal_url($id) . '?vetites=1' : '', 'station' => cp_booth_gal_has($id) ? cp_booth_gal_url($id) . '?allomas=1' : '');
	return $x;
}, 24);
/* beállítások mentése a deal szelfigépeire (a gépek a következő jelentkezéskor, legfeljebb 1 percen belül átveszik) */
add_action('wp_ajax_nopriv_cp_booth_dcfg', 'cp_booth_ajax_dcfg'); add_action('wp_ajax_cp_booth_dcfg', 'cp_booth_ajax_dcfg');
function cp_booth_ajax_dcfg() {
	cp_crm_ajax_guard(array('deals'));
	$deal = (int) ($_POST['deal'] ?? 0); if (!$deal) { cp_el_err('Hiányzó deal.', 400); }
	$a = cp_booth_dcfg_all(); $k = (string) $deal;
	if (!empty($_POST['clear'])) { unset($a[$k]); update_option('cp_booth_dcfg', $a, false); wp_send_json_success(array('cfg' => null)); }
	$in = json_decode(wp_unslash((string) ($_POST['cfg'] ?? '')), true);
	$c = cp_booth_dcfg_clean($in);
	$cu = function_exists('cp_pu_current') ? cp_pu_current() : null; $who = is_array($cu) ? (string) ($cu['name'] ?? '') : '';
	$a[$k] = array('at' => time(), 'by' => $who, 'cfg' => $c); update_option('cp_booth_dcfg', $a, false);
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'booth_cfg', 'Szelfigép-beállítások mentve', 'deal:' . $deal, ''); }
	wp_send_json_success(array('cfg' => $c, 'at' => $a[$k]['at']));
}
/* sorsolás a vendégek közül (akik adatot adtak meg); egy vendég egyszer nyerhet */
add_action('wp_ajax_nopriv_cp_booth_draw', 'cp_booth_ajax_draw'); add_action('wp_ajax_cp_booth_draw', 'cp_booth_ajax_draw');
function cp_booth_ajax_draw() {
	cp_crm_ajax_guard(array('deals'));
	global $wpdb; $deal = (int) ($_POST['deal'] ?? 0);
	if (!$deal || get_option('cp_bg_db') !== '1') { cp_el_err('Nincs vendégadat.', 404); }
	$dr = get_option('cp_booth_drawn', array()); if (!is_array($dr)) { $dr = array(); } $k = (string) $deal; $done = array_map('intval', (array) ($dr[$k] ?? array()));
	if (!empty($_POST['reset'])) { unset($dr[$k]); update_option('cp_booth_drawn', $dr, false); wp_send_json_success(array('reset' => true)); }
	$rows = $wpdb->get_results($wpdb->prepare('SELECT id, name, email FROM ' . cp_bg_t() . " WHERE deal = %d AND (name <> '' OR email <> '')", $deal), ARRAY_A);
	$seen = array(); $pool = array();
	foreach ($rows as $x) { $key = strtolower($x['email'] !== '' ? $x['email'] : $x['name']); if (isset($seen[$key])) { continue; } $seen[$key] = 1; if (!in_array((int) $x['id'], $done, true)) { $pool[] = $x; } }
	if (!$pool) { cp_el_err($rows ? 'Már mindenki nyert, akit lehetett.' : 'Még nincs vendég, aki megadta az adatait.', 404); }
	$w = $pool[random_int(0, count($pool) - 1)];
	$done[] = (int) $w['id']; $dr[$k] = $done; update_option('cp_booth_drawn', $dr, false);
	$em = $w['email']; if ($em !== '') { $p = explode('@', $em); $em = mb_substr($p[0], 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($p[0]) - 2))) . '@' . ($p[1] ?? ''); }
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'booth_draw', 'Sorsolás a szelfigép vendégei közül', 'deal:' . $deal, ''); }
	wp_send_json_success(array('name' => $w['name'], 'email' => $em, 'left' => count($pool) - 1, 'n' => count($seen), 'round' => count($done)));
}
