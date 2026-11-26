<?php
/* =====================================================================
 * v2.9.388: Crazy Booth hibanaplók a panelben
 * A gépek (az online felület) indításkor, minden hibánál (legfeljebb percenként) és a panel kérésére elküldik a naplójuk
 * végét és az állapotukat: POST /crazy/v1/booth/diag. Gépkulccsal a gép nevén, kulcs nélkül / hibás kulccsal
 * „azonosítatlan gépként” (gyakoriság- és méretkorláttal). 14 napig őrizzük. Nézet: Beállítások → Crazy Booth gépek.
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }
define('CP_BDG_DB_VER', '1');
function cp_bdg_t() { global $wpdb; return $wpdb->prefix . 'cp_booth_diag'; }
function cp_bdg_ok() { return get_option('cp_bdg_db_ver') === CP_BDG_DB_VER; }
add_action('init', function () {
	if (cp_bdg_ok()) { return; }
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	global $wpdb;
	dbDelta('CREATE TABLE ' . cp_bdg_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  at int(10) unsigned NOT NULL DEFAULT 0,
  dev varchar(40) NOT NULL DEFAULT '',
  inst varchar(40) NOT NULL DEFAULT '',
  name varchar(120) NOT NULL DEFAULT '',
  reasons text NULL,
  meta text NULL,
  log mediumtext NULL,
  PRIMARY KEY  (id),
  KEY at (at),
  KEY dev (dev, at)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_bdg_db_ver', CP_BDG_DB_VER, false);
}, 7);

/* a panel kérheti egy gép naplóját: a gép a következő állapotjelentésre (1 percen belül) küldi */
function cp_bdg_req_all() { $r = get_option('cp_bdg_req', array()); return is_array($r) ? $r : array(); }
function cp_bdg_req_take($dev) { $r = cp_bdg_req_all(); if (empty($r[$dev])) { return false; } unset($r[$dev]); update_option('cp_bdg_req', $r, false); return true; }

add_action('rest_api_init', function () {
	register_rest_route('crazy/v1', '/booth/diag', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
		if (!cp_bdg_ok()) { return new WP_REST_Response(array('ok' => false), 503); }
		$raw = (string) $r->get_body();
		if (strlen($raw) > 400000) { return new WP_REST_Response(array('ok' => false, 'message' => 'túl nagy'), 413); }
		$j = json_decode($raw, true); if (!is_array($j)) { return new WP_REST_Response(array('ok' => false), 400); }
		$d = cp_booth_auth();
		$inst = substr(preg_replace('/[^a-z0-9]/', '', strtolower((string) ($j['inst'] ?? ''))), 0, 40);
		/* gyakoriságkorlát: gépkulccsal 30 / óra, kulcs nélkül 10 / óra / telepítés és 40 / óra / IP */
		$ipk = 'cp_bdg_ip_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
		$ik = 'cp_bdg_i_' . md5($d ? $d['id'] : 'x' . $inst);
		$ni = (int) get_transient($ik); $np = (int) get_transient($ipk);
		if ($ni >= ($d ? 30 : 10) || (!$d && $np >= 40)) { return new WP_REST_Response(array('ok' => false, 'message' => 'túl sok'), 429); }
		set_transient($ik, $ni + 1, HOUR_IN_SECONDS); set_transient($ipk, $np + 1, HOUR_IN_SECONDS);
		$clip = function ($v, $n) { return mb_substr(sanitize_text_field((string) $v), 0, $n); };
		$reasons = array(); foreach (array_slice((array) ($j['reasons'] ?? array()), 0, 30) as $x) { $reasons[] = $clip($x, 300); }
		$meta = array();
		foreach (array('ver' => 60, 'os' => 200, 'key' => 10, 'cam' => 20, 'scr' => 20, 'upErr' => 200) as $k => $n) { $meta[$k] = $clip($j[$k] ?? '', $n); }
		$meta['mac'] = !empty($j['mac']); $meta['net'] = !empty($j['net']); $meta['camOk'] = array_key_exists('camOk', $j) && $j['camOk'] !== null ? (bool) $j['camOk'] : null; $meta['queue'] = max(0, (int) ($j['queue'] ?? 0));
		if (!empty($j['event']) && is_array($j['event'])) { $meta['event'] = array('deal' => (int) ($j['event']['deal'] ?? 0), 'title' => $clip($j['event']['title'] ?? '', 120), 'date' => $clip($j['event']['date'] ?? '', 12)); }
		if (!$d && isset($_SERVER['HTTP_X_BOOTH_KEY']) && trim((string) $_SERVER['HTTP_X_BOOTH_KEY']) !== '') { $meta['keybad'] = 1; } /* volt kulcs, de a panel nem fogadta el */
		$log = (string) ($j['log'] ?? ''); $log = mb_substr(wp_check_invalid_utf8($log, true), -150000);
		$log = preg_replace('/cb_[A-Za-z0-9]{30,}/', '[gépkulcs]', $log); /* biztonsági háló: kulcs ne maradjon a naplóban */
		global $wpdb;
		$wpdb->insert(cp_bdg_t(), array('at' => time(), 'dev' => $d ? (string) $d['id'] : '', 'inst' => $inst, 'name' => $d ? (string) $d['name'] : 'Azonosítatlan gép (' . substr($inst, 0, 6) . ')',
			'reasons' => wp_json_encode($reasons, JSON_UNESCAPED_UNICODE), 'meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE), 'log' => $log));
		if ($d) { cp_bdg_req_take((string) $d['id']); }
		if (wp_rand(1, 20) === 1) { $wpdb->query($wpdb->prepare('DELETE FROM ' . cp_bdg_t() . ' WHERE at < %d', time() - 14 * DAY_IN_SECONDS)); }
		return array('ok' => true);
	}));
});

/* WP admin → Beállítások → Crazy Booth gépek: a beérkezett naplók + bekérés */
function cp_bdg_admin() {
	if (!cp_bdg_ok() || !current_user_can('manage_options')) { return; }
	global $wpdb;
	if (!empty($_POST['cp_bdg_nonce']) && wp_verify_nonce($_POST['cp_bdg_nonce'], 'cp_bdg') && !empty($_POST['bdg_req'])) {
		$r = cp_bdg_req_all(); $r[sanitize_key(wp_unslash($_POST['bdg_req']))] = time(); update_option('cp_bdg_req', $r, false);
		echo '<div class="notice notice-success"><p>Bekérve – a gép a következő jelentkezéskor (1 percen belül, ha be van kapcsolva) küldi.</p></div>';
	}
	echo '<h2 style="margin-top:28px">Hibanaplók a gépekről</h2><p>A gépek indításkor, minden hibánál és kérésre maguktól küldik (14 napig őrizzük). Gépkulcs nélkül is: ilyenkor „Azonosítatlan gép”.</p>';
	echo '<form method="post">'; wp_nonce_field('cp_bdg', 'cp_bdg_nonce');
	$req = cp_bdg_req_all();
	foreach (cp_booth_devs() as $id => $dv) { if ($id === 'panel') { continue; } echo '<button class="button" name="bdg_req" value="' . esc_attr($id) . '" style="margin:0 6px 6px 0">Napló bekérése: ' . esc_html($dv['name']) . (isset($req[$id]) ? ' (kérve)' : '') . '</button>'; }
	echo '</form>';
	$rows = $wpdb->get_results('SELECT * FROM ' . cp_bdg_t() . ' ORDER BY id DESC LIMIT 60', ARRAY_A);
	if (!$rows) { echo '<p>Még nem érkezett napló.</p>'; return; }
	echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:120px">Mikor</th><th style="width:170px">Gép</th><th>Ok / állapot</th></tr></thead><tbody>';
	foreach ($rows as $x) {
		$m = json_decode((string) $x['meta'], true) ?: array(); $rs = json_decode((string) $x['reasons'], true) ?: array();
		$st = 'v' . ($m['ver'] ?? '?') . ' · gépkulcs: ' . ($m['key'] ?? '?') . (!empty($m['keybad']) ? ' (a panel nem fogadta el)' : '') . ' · kamera: ' . ($m['cam'] ?? '') . ($m['camOk'] === false ? ' (nincs kép)' : '') . ' · net: ' . (!empty($m['net']) ? 'van' : 'nincs') . (!empty($m['event']['title']) ? ' · rendezvény: ' . $m['event']['title'] : '') . (!empty($m['queue']) ? ' · feltöltésre vár: ' . (int) $m['queue'] : '');
		echo '<tr><td>' . esc_html(wp_date('m.d. H:i:s', (int) $x['at'])) . '</td><td>' . esc_html($x['name']) . '</td><td><div>' . esc_html(implode(' | ', $rs)) . '</div><small>' . esc_html($st) . '</small>' .
			($x['log'] !== '' ? '<details><summary>Napló (' . number_format_i18n(mb_strlen((string) $x['log'])) . ' karakter)</summary><pre style="max-height:420px;overflow:auto;white-space:pre-wrap;font-size:11px;background:#f6f7f7;padding:8px">' . esc_html(mb_substr((string) $x['log'], -40000)) . '</pre></details>' : '') . '</td></tr>';
	}
	echo '</tbody></table>';
}
