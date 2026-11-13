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
	foreach (array('title', 'password', 'photo', 'hidden') as $k) { if (isset($_POST[$k])) { $body[$k] = sanitize_text_field(wp_unslash((string) $_POST[$k])); } }
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
