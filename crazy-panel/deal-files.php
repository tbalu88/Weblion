<?php
/**
 * Crazy panel – fájlfeltöltés a dealhez (v2.9.184).
 * A fájl a webes gyökéren kívül, a cp-files/deals/ mappában van (mint a munkanapló képei), és csak belépve, a Deal-ek fül
 * jogosultságával nyitható meg. A Salesmate-be nem kerül. Képhez bélyegkép készül. Törölni a feltöltő vagy admin tud.
 */
if (!defined('ABSPATH')) { exit; }

function cp_df_t() { global $wpdb; return $wpdb->prefix . 'crazy_deal_files'; }
function cp_df_db() {
	static $ok = null;
	if ($ok !== null) { return $ok; }
	if (get_option('cp_df_db') !== '1') {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta('CREATE TABLE ' . cp_df_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  deal_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  mime varchar(100) NOT NULL DEFAULT '',
  size int(10) unsigned NOT NULL DEFAULT 0,
  path varchar(255) NOT NULL DEFAULT '',
  thumb varchar(255) NOT NULL DEFAULT '',
  uid int(11) NOT NULL DEFAULT 0,
  by_user varchar(100) NOT NULL DEFAULT '',
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY deal_id (deal_id)
) " . $wpdb->get_charset_collate() . ';');
		update_option('cp_df_db', '1', false);
	}
	return $ok = true;
}
function cp_df_root() { return dirname(rtrim(ABSPATH, '/')) . '/cp-files/deals/'; }
/* engedélyezett fájltípusok (programfájl, weboldal-fájl nem) */
function cp_df_ext_ok($ext) {
	return in_array($ext, array('pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'svg', 'ai', 'eps', 'psd', 'doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp', 'key', 'pages', 'numbers', 'zip', 'mp4', 'mov', 'mp3', 'wav'), true);
}
function cp_df_list($deal) {
	global $wpdb;
	if (!cp_df_db()) { return array(); }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$adm = function_exists('cp_panel_role') ? cp_panel_role() === 'admin' : false;
	return array_map(function ($f) use ($me, $adm) {
		return array('id' => (int) $f['id'], 'n' => $f['name'], 's' => (int) $f['size'], 'by' => $f['by_user'], 'at' => function_exists('cp_crm_local') ? cp_crm_local($f['created_at']) : $f['created_at'], 'img' => $f['thumb'] !== '' ? 1 : 0, 'del' => ($adm || ($me && (int) $f['uid'] === $me)) ? 1 : 0);
	}, (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cp_df_t() . ' WHERE deal_id = %d ORDER BY id DESC', (int) $deal), ARRAY_A));
}

/* a deal adatlapjára */
add_filter('cp_crm_deal_data', function ($x) { $x['dfiles'] = cp_df_list((int) $x['deal']['id']); return $x; }, 21);

/* v2.9.230: egy szerveren már meglévő fájl másolata a deal Fájljai közé (pl. az ügyfélportálon mentett fotósablon) */
function cp_df_add_copy($deal, $src, $name, $by) {
	global $wpdb;
	$deal = (int) $deal;
	if (!$deal || !is_file($src) || !cp_df_db()) { return false; }
	$name = mb_substr((string) $name, 0, 190);   /* a hívó adja a biztonságos nevet (ékezettel, szóközzel); a tárolt fájl neve véletlen */
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	if (!cp_df_ext_ok($ext)) { return false; }
	if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_df_t() . ' WHERE deal_id = %d', $deal)) >= 200) { return false; }
	$dir = cp_df_root() . gmdate('Y/m');
	if (!is_dir($dir)) { wp_mkdir_p($dir); @chmod(cp_df_root(), 0700); }
	$base = wp_generate_password(24, false, false);
	$path = $dir . '/' . $base . '.' . $ext;
	if (!@copy($src, $path)) { return false; }
	@chmod($path, 0600);
	$ft = wp_check_filetype($name);
	$thumb = '';
	if (in_array($ext, array('jpg', 'jpeg', 'png'), true)) {
		$ed = wp_get_image_editor($path);
		if (!is_wp_error($ed)) { $ed->resize(480, 480, false); $ed->set_quality(78); $tp = $dir . '/' . $base . '_t.jpg'; $sv = $ed->save($tp, 'image/jpeg'); if (!is_wp_error($sv)) { $thumb = $tp; @chmod($tp, 0600); } }
	}
	$wpdb->insert(cp_df_t(), array('deal_id' => $deal, 'name' => $name, 'mime' => $ft['type'] ?: 'application/octet-stream', 'size' => (int) filesize($path), 'path' => $path, 'thumb' => $thumb, 'uid' => 0, 'by_user' => mb_substr((string) $by, 0, 100), 'created_at' => gmdate('Y-m-d H:i:s')));
	return (int) $wpdb->insert_id;
}

add_action('wp_ajax_nopriv_cp_crm_dfile_up', 'cp_df_ajax_up'); add_action('wp_ajax_cp_crm_dfile_up', 'cp_df_ajax_up');
function cp_df_ajax_up() {
	cp_crm_ajax_guard(array('deals'));
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$deal = (int) ($_POST['deal'] ?? 0);
	if (!$deal || !$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_crm_t('deals') . ' WHERE id = %d AND deleted_at IS NULL', $deal))) { cp_el_err('Nincs ilyen deal.', 404); }
	if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) { cp_el_err('Nem jött fájl (lehet, hogy túl nagy).'); }
	if ((int) $_FILES['file']['size'] > 25 * 1048576) { cp_el_err('A fájl túl nagy (legfeljebb 25 MB).'); }
	cp_df_db();
	if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_df_t() . ' WHERE deal_id = %d', $deal)) >= 200) { cp_el_err('Egy dealhez legfeljebb 200 fájl tölthető fel.'); }
	$name = mb_substr(sanitize_file_name(wp_unslash((string) ($_FILES['file']['name'] ?? 'fajl'))), 0, 190);
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	if (!cp_df_ext_ok($ext)) { cp_el_err('Ez a fájltípus nem tölthető fel (.' . esc_html($ext) . '). PDF, kép, Office-dokumentum, szöveg, zip, videó és hang mehet.'); }
	$dir = cp_df_root() . gmdate('Y/m');
	if (!is_dir($dir)) { wp_mkdir_p($dir); @chmod(cp_df_root(), 0700); }
	$base = wp_generate_password(24, false, false);
	$path = $dir . '/' . $base . '.' . $ext;
	if (!@move_uploaded_file($_FILES['file']['tmp_name'], $path)) { cp_el_err('A fájlt nem sikerült menteni.'); }
	@chmod($path, 0600);
	$ft = wp_check_filetype($name); $mime = $ft['type'] ?: 'application/octet-stream';
	$thumb = '';
	if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'), true)) {
		$ed = wp_get_image_editor($path);
		if (!is_wp_error($ed)) { $ed->resize(480, 480, false); $ed->set_quality(78); $tp = $dir . '/' . $base . '_t.jpg'; $sv = $ed->save($tp, 'image/jpeg'); if (!is_wp_error($sv)) { $thumb = $tp; @chmod($tp, 0600); } }
	}
	$wpdb->insert(cp_df_t(), array('deal_id' => $deal, 'name' => $name, 'mime' => $mime, 'size' => (int) filesize($path), 'path' => $path, 'thumb' => $thumb, 'uid' => function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0, 'by_user' => function_exists('cp_panel_who') ? mb_substr((string) cp_panel_who(), 0, 100) : '', 'created_at' => gmdate('Y-m-d H:i:s')));
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'dfile_up', 'Fájl feltöltve a dealhez', 'deal:' . $deal, $name); }
	wp_send_json_success(array('files' => cp_df_list($deal)));
}

add_action('wp_ajax_nopriv_cp_crm_dfile', 'cp_df_ajax_get'); add_action('wp_ajax_cp_crm_dfile', 'cp_df_ajax_get');
function cp_df_ajax_get() {
	if (!function_exists('cp_panel_authed') || !cp_panel_authed()) { status_header(403); exit('Nincs hozzáférés.'); }
	if (function_exists('cp_panel_can_any') && !cp_panel_can_any(array('deals'))) { status_header(403); exit('Nincs hozzáférés.'); }
	global $wpdb;
	cp_df_db();
	$f = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_df_t() . ' WHERE id = %d', (int) ($_GET['id'] ?? 0)), ARRAY_A);
	if (!$f) { status_header(404); exit('Nincs ilyen fájl.'); }
	$p = !empty($_GET['t']) && $f['thumb'] !== '' && is_file($f['thumb']) ? $f['thumb'] : $f['path'];
	$rp = realpath($p); $root = realpath(cp_df_root());
	if (!$rp || !$root || strpos($rp, $root) !== 0) { status_header(404); exit('A fájl nincs meg.'); }
	$inline = empty($_GET['dl']) && (strpos($f['mime'], 'image/') === 0 || $f['mime'] === 'application/pdf') && $f['mime'] !== 'image/svg+xml';
	nocache_headers();
	header('Content-Type: ' . ($p === $f['thumb'] ? 'image/jpeg' : $f['mime']));
	header('Content-Length: ' . filesize($rp));
	header('X-Content-Type-Options: nosniff');
	header('Content-Security-Policy: sandbox');
	header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($f['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($f['name']));
	header('Cache-Control: private, max-age=86400');
	readfile($rp);
	exit;
}

add_action('wp_ajax_nopriv_cp_crm_dfile_del', 'cp_df_ajax_del'); add_action('wp_ajax_cp_crm_dfile_del', 'cp_df_ajax_del');
function cp_df_ajax_del() {
	cp_crm_ajax_guard(array('deals'));
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	cp_df_db();
	$f = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_df_t() . ' WHERE id = %d', (int) ($_POST['id'] ?? 0)), ARRAY_A);
	if (!$f) { cp_el_err('Nincs ilyen fájl.', 404); }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$adm = function_exists('cp_panel_role') ? cp_panel_role() === 'admin' : false;
	if (!$adm && (!$me || (int) $f['uid'] !== $me)) { cp_el_err('Csak a feltöltő vagy admin törölheti.', 403); }
	$root = realpath(cp_df_root());
	foreach (array($f['path'], $f['thumb']) as $p) { $rp = $p !== '' ? realpath($p) : false; if ($rp && $root && strpos($rp, $root) === 0) { @unlink($rp); } }
	$wpdb->delete(cp_df_t(), array('id' => (int) $f['id']));
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'dfile_del', 'Feltöltött fájl törölve', 'deal:' . (int) $f['deal_id'], $f['name']); }
	wp_send_json_success(array('files' => cp_df_list((int) $f['deal_id'])));
}
