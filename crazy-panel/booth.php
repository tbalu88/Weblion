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
			$tpl = false;
			if ($p) { $a = cp_portal_answers($p->id); $tpl = !empty($a['photo_template']['png']) || !empty($a['photo_template']['preview']); }
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
		if (!$t) { return array('ok' => true, 'template' => null); }
		$f = !empty($t['png']) ? $t['png'] : '';
		$path = $f !== '' ? cp_portal_sb_dir() . '/' . $f : '';
		if ($path === '' || !is_file($path)) { return array('ok' => true, 'template' => null, 'note' => 'Nincs nyomtatható sablon (csak előnézet).'); }
		return array('ok' => true, 'template' => array('name' => (string) ($t['name'] ?? ''), 'at' => (int) ($t['at'] ?? 0), 'png' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($path))));
	}));
});

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
		echo '<tr><td>' . esc_html($d['name']) . '</td><td>' . esc_html(wp_date('Y.m.d. H:i', (int) $d['at'])) . '</td><td>' . ($d['seen'] ? esc_html(wp_date('Y.m.d. H:i', (int) $d['seen'])) : '–') . '</td><td>' . (empty($d['off']) ? 'aktív' : '<b>letiltva</b>') . '</td><td><button class="button" name="toggle" value="' . esc_attr($id) . '">' . (empty($d['off']) ? 'Letiltás' : 'Engedélyezés') . '</button></td></tr>';
	}
	if (!$devs) { echo '<tr><td colspan="5">Még nincs gép.</td></tr>'; }
	echo '</tbody></table><p><input name="name" placeholder="pl. Szelfigép 1 (Mac)" class="regular-text"> <button class="button button-primary" name="add" value="1">Új gép + kulcs</button></p></form></div>';
}
