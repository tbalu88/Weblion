<?php
/* =====================================================================
 * Értesítések (v1.36) – megemlítés és kiosztás: a panel csengőjében, és ha be van állítva, Pumble-üzenetben
 * (a panelre mutató linkkel, ami egyenesen a kártyát / levélszálat nyitja meg).
 * Pumble: egy közös értesítési csatorna webhookja, felhasználónként felülírható saját webhookkal.
 * A webhookok titkosítva tárolódnak (cp_st_secret_set). A Salesmate-hez nem nyúl.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_NT_DB_VER', '1');

function cp_nt_t() {
	global $wpdb;
	return $wpdb->prefix . 'crazy_notifs';
}

add_action('init', function () {
	if (get_option('cp_nt_db_ver') === CP_NT_DB_VER || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_nt_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uid int(11) NOT NULL,
  kind varchar(20) NOT NULL DEFAULT '',
  text varchar(400) NOT NULL DEFAULT '',
  link varchar(60) NOT NULL DEFAULT '',
  by_uid int(11) NULL,
  by_name varchar(100) NOT NULL DEFAULT '',
  created_at datetime NULL,
  read_at datetime NULL,
  PRIMARY KEY  (id),
  KEY uid (uid, read_at)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_nt_db_ver', CP_NT_DB_VER, false);
}, 7);

function cp_nt_db_ok() { return get_option('cp_nt_db_ver') === CP_NT_DB_VER; }

/* a webhookok: közös (notif_hook) és felhasználónkénti (notif_hook_u{id}) */
function cp_nt_hook_get($k) { return function_exists('cp_st_secret') ? (string) cp_st_secret($k) : ''; }
function cp_nt_hook_set($k, $v) { if (function_exists('cp_st_secret_set')) { cp_st_secret_set($k, $v); } }
function cp_nt_hook_ok($u) { return (bool) preg_match('#^https://api\.pumble\.com/[A-Za-z0-9/_.\-]+$#', (string) $u); }

/* értesítés egy kollégának. $hash: a panelen belüli cél (pl. „feladatok:12”, „mail:3”). Saját magunknak nem megy. */
function cp_notify($uid, $kind, $text, $hash) {
	global $wpdb;
	$uid = (int) $uid;
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if ($uid <= 0 || $uid === $me || !cp_nt_db_ok() || !function_exists('cp_pu_get')) { return; }
	$u = cp_pu_get($uid);
	if (!$u || empty($u['a'])) { return; }
	$by = function_exists('cp_panel_who') ? cp_panel_who() : '';
	$text = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $text)), 0, 400);
	/* ugyanarról ugyanannak 2 percen belül csak egyszer (pl. több mentés egymás után) */
	if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_nt_t() . ' WHERE uid = %d AND kind = %s AND link = %s AND text = %s AND created_at >= %s', $uid, $kind, $hash, $text, gmdate('Y-m-d H:i:s', time() - 120)))) { return; }
	$wpdb->insert(cp_nt_t(), array('uid' => $uid, 'kind' => $kind, 'text' => $text, 'link' => mb_substr($hash, 0, 60), 'by_uid' => $me ?: null, 'by_name' => $by, 'created_at' => gmdate('Y-m-d H:i:s')));
	$hook = cp_nt_hook_get('notif_hook_u' . $uid);
	if ($hook === '') { $hook = cp_nt_hook_get('notif_hook'); }
	if ($hook === '' || !cp_nt_hook_ok($hook) || !function_exists('cp_pu_panel_url')) { return; }
	$url = cp_pu_panel_url() . '#' . $hash;
	$msg = '**' . $u['n'] . '** – ' . ($by !== '' ? $by . ' ' : '') . $text . "\n[Megnyitás a Crazy panelben](" . $url . ')';
	/* nem várjuk meg a választ: a mentés ne lassuljon a Pumble miatt */
	wp_remote_post($hook, array('timeout' => 3, 'blocking' => false, 'headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode(array('text' => $msg))));
}

/* a szövegben @Név formában megemlített aktív kollégák azonosítói */
function cp_nt_mentions($body) {
	$out = array();
	if (!function_exists('cp_pu_data')) { return $out; }
	foreach (cp_pu_data()['list'] as $id => $u) { if (!empty($u['a']) && mb_stripos((string) $body, '@' . $u['n']) !== false) { $out[] = (int) $id; } }
	return $out;
}
function cp_nt_quote($s, $n = 120) { $s = trim(preg_replace('/\s+/u', ' ', (string) $s)); return '„' . (mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s) . '”'; }

/* ------------------------------------------------------------ a panel csengője */
add_action('wp_ajax_nopriv_cp_nt_list', 'cp_nt_ajax_list');
add_action('wp_ajax_cp_nt_list', 'cp_nt_ajax_list');
function cp_nt_ajax_list() {
	cp_el_auth();
	global $wpdb;
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if ($me <= 0 || !cp_nt_db_ok()) { wp_send_json_success(array('rows' => array(), 'unread' => 0, 'off' => 1)); }
	$unread = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_nt_t() . ' WHERE uid = %d AND read_at IS NULL', $me));
	if (!empty($_POST['count'])) { wp_send_json_success(array('unread' => $unread)); }
	$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, kind, text, link, by_name, created_at, read_at FROM ' . cp_nt_t() . ' WHERE uid = %d ORDER BY id DESC LIMIT 40', $me), ARRAY_A);
	foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['created_at'] = function_exists('cp_crm_local') ? cp_crm_local($r['created_at']) : $r['created_at']; $r['read'] = $r['read_at'] ? 1 : 0; unset($r['read_at']); }
	unset($r);
	wp_send_json_success(array('rows' => $rows, 'unread' => $unread));
}

add_action('wp_ajax_nopriv_cp_nt_read', 'cp_nt_ajax_read');
add_action('wp_ajax_cp_nt_read', 'cp_nt_ajax_read');
function cp_nt_ajax_read() {
	cp_el_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_nt_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$now = gmdate('Y-m-d H:i:s');
	if (!empty($_POST['all'])) { $wpdb->query($wpdb->prepare('UPDATE ' . cp_nt_t() . ' SET read_at = %s WHERE uid = %d AND read_at IS NULL', $now, $me)); }
	else { $wpdb->query($wpdb->prepare('UPDATE ' . cp_nt_t() . ' SET read_at = %s WHERE uid = %d AND id = %d AND read_at IS NULL', $now, $me, (int) ($_POST['id'] ?? 0))); }
	wp_send_json_success(array('unread' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_nt_t() . ' WHERE uid = %d AND read_at IS NULL', $me))));
}

/* ------------------------------------------------------------ beállítás: a Panel-felhasználók oldal alján */
function cp_nt_admin_save() {
	$msg = array();
	$set = function ($k, $field, $label) use (&$msg) {
		if (!empty($_POST[$field . '_clear'])) { cp_nt_hook_set($k, ''); $msg[] = $label . ': törölve.'; return; }
		$v = trim((string) wp_unslash($_POST[$field] ?? ''));
		if ($v === '') { return; }
		if (!cp_nt_hook_ok($v)) { $msg[] = $label . ': a cím nem https://api.pumble.com/… kezdetű, nem mentettem.'; return; }
		cp_nt_hook_set($k, $v); $msg[] = $label . ': elmentve.';
	};
	$set('notif_hook', 'nt_hook', 'Közös csatorna');
	foreach (cp_pu_data()['list'] as $id => $u) { $set('notif_hook_u' . (int) $id, 'nt_hook_u' . (int) $id, $u['n']); }
	if (!empty($_POST['nt_test'])) {
		$h = cp_nt_hook_get('notif_hook');
		if ($h === '') { $msg[] = 'Teszt: nincs közös webhook.'; }
		else {
			$r = wp_remote_post($h, array('timeout' => 10, 'headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode(array('text' => '**Crazy panel** – teszt értesítés. Innen kapjátok a megemlítéseket és a kiosztásokat. [Panel megnyitása](' . cp_pu_panel_url() . '#feladatok:mine)'))));
			$code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
			$msg[] = $code >= 200 && $code < 300 ? 'Teszt üzenet elküldve.' : 'Teszt: a Pumble nem fogadta (' . ($code ?: $r->get_error_message()) . ').';
		}
	}
	return implode(' ', $msg);
}

function cp_nt_admin_section() {
	$has = cp_nt_hook_get('notif_hook') !== '';
	$ph = function ($on) { return esc_attr($on ? 'beállítva – csak cseréhez írd be az újat' : 'https://api.pumble.com/workspaces/…/incomingWebhooks/postMessage/…'); };
	echo '<h2>Értesítések (Pumble)</h2><form method="post">' . wp_nonce_field('cp_pu_do', '_wpnonce', true, false) . '<input type="hidden" name="cp_pu_do" value="notif">';
	echo '<p>Ha valakit megemlítenek (@Név) egy kártyán vagy levéljegyzetben, vagy rá osztanak egy kártyát / levelet, a panel csengőjén túl Pumble-üzenetet is kap, benne a linkkel, ami egyenesen oda visz. Pumble: Workspace settings → Incoming Webhooks → New Webhook → válaszd a csatornát. A címek titkosítva tárolódnak.</p>';
	echo '<table class="form-table" style="max-width:900px"><tr><th>Közös értesítési csatorna</th><td><input type="password" name="nt_hook" class="large-text" autocomplete="new-password" placeholder="' . $ph($has) . '">' . ($has ? ' <label><input type="checkbox" name="nt_hook_clear" value="1"> törlés</label>' : '') . '<p class="description">Ide megy mindenki értesítése, akinek nincs saját webhookja (az üzenet elején a címzett neve).</p></td></tr>';
	foreach (cp_pu_data()['list'] as $id => $u) {
		if (empty($u['a'])) { continue; }
		$h = cp_nt_hook_get('notif_hook_u' . (int) $id) !== '';
		echo '<tr><th>' . esc_html($u['n']) . ' <small style="font-weight:400">(saját, nem kötelező)</small></th><td><input type="password" name="nt_hook_u' . (int) $id . '" class="large-text" autocomplete="new-password" placeholder="' . $ph($h) . '">' . ($h ? ' <label><input type="checkbox" name="nt_hook_u' . (int) $id . '_clear" value="1"> törlés</label>' : '') . '</td></tr>';
	}
	echo '</table><p><button class="button button-primary">Mentés</button> <label style="margin-left:12px"><input type="checkbox" name="nt_test" value="1"> teszt üzenet a közös csatornára</label></p></form>';
}
