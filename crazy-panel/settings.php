<?php
/* =====================================================================
 * Beállítások a panelen belül (v1.55)
 * – Saját beállításaim (mindenki, személyes fiókkal): név, saját e-mail-aláírás, saját Pumble-értesítés, jelszó-link.
 * – Adminisztráció (csak admin): felhasználók, láthatóság, közös jelszavak, értesítések, levelezés (beérkezés, küldés),
 *   AI, Salesmate-be írás, hátterek és párok. Ugyanazokat az opciókat írja, mint a WordPress-admin oldalai.
 * Titkot (API-kulcs, jelszó, webhook) a panel soha nem küld vissza a böngészőnek – csak azt, hogy be van-e állítva.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_set_admin() { return function_exists('cp_panel_is_admin') && cp_panel_is_admin(); }
function cp_set_auth($admin = false) {
	cp_el_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	/* a Beállítások fül láthatósága (Beállítások → Láthatóság); alapból csak adminnak */
	if (function_exists('cp_panel_can') && !cp_panel_can('beallitasok')) { cp_el_err('A Beállítások neked nincs engedélyezve.', 403); }
	if ($admin && !cp_set_admin()) { cp_el_err('Ez csak az adminoknak érhető el.', 403); }
}
function cp_set_me() { return function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0; }
/* a felhasználó saját aláírása (HTML) – a levélküldés ezt használja elsőként */
function cp_set_user_sig($uid) { $u = function_exists('cp_pu_get') ? cp_pu_get((int) $uid) : null; return $u && !empty($u['sig']) ? (string) $u['sig'] : ''; }

function cp_set_users_out() {
	$out = array();
	foreach (cp_pu_data()['list'] as $id => $u) {
		$out[] = array('id' => (int) $id, 'n' => $u['n'], 'e' => $u['e'], 'r' => $u['r'], 'sm' => (int) ($u['sm'] ?? 0), 'a' => (int) !empty($u['a']), 'st' => empty($u['a']) ? 'letiltva' : ((string) $u['h'] === '' ? 'meghívva' : 'aktív'), 'last' => !empty($u['last']) ? wp_date('Y.m.d. H:i', (int) $u['last']) : '', 'sig' => !empty($u['sig']) ? 1 : 0, 'sig_html' => (string) ($u['sig'] ?? ''));
	}
	return $out;
}
function cp_set_data() {
	$me = cp_set_me(); $u = $me ? cp_pu_get($me) : null;
	$x = array('me' => array('uid' => $me, 'n' => $u ? $u['n'] : (function_exists('cp_panel_who') ? cp_panel_who() : ''), 'e' => $u ? $u['e'] : '', 'r' => $u ? $u['r'] : (function_exists('cp_panel_role') ? cp_panel_role() : ''), 'sig' => $me ? cp_set_user_sig($me) : '', 'hook' => $me && function_exists('cp_nt_hook_get') && cp_nt_hook_get('notif_hook_u' . $me) !== ''), 'admin' => cp_set_admin());
	if (!$x['admin']) { return $x; }
	$tabs = cp_pu_tabs(); $vis = array();
	foreach (cp_pu_data()['list'] as $id => $uu) { if (empty($uu['a'])) { continue; } foreach (array_keys($tabs) as $k) { $vis[(int) $id][$k] = cp_pu_can_uid((int) $id, $k, $uu['r']) ? 1 : 0; } }
	$sm = array(); if (function_exists('cp_crm_users')) { foreach (cp_crm_users() as $id => $s) { $sm[] = array('id' => (int) $id, 'n' => $s['n'], 'a' => (int) $s['a']); } }
	$nt = array('common' => function_exists('cp_nt_hook_get') && cp_nt_hook_get('notif_hook') !== '', 'users' => array());
	foreach (cp_pu_data()['list'] as $id => $uu) { $nt['users'][(int) $id] = function_exists('cp_nt_hook_get') && cp_nt_hook_get('notif_hook_u' . (int) $id) !== ''; }
	$sy = get_option('cp_imp_sync', array()); $io = function_exists('cp_imp_opts') ? cp_imp_opts() : array();
	$so = function_exists('cp_send_opts') ? cp_send_opts() : array();
	$ai = function_exists('cp_ai_opts') ? cp_ai_opts() : array();
	$po = cp_panel_opts();
	$x += array(
		'users' => cp_set_users_out(), 'roles' => cp_pu_roles(), 'sm_users' => $sm, 'tabs' => $tabs, 'vis' => $vis, 'shared_on' => cp_pu_shared_on(), 'notif' => $nt,
		'mail' => array('sync_on' => get_option('cp_imp_sync_on') === '1', 'sync_last' => !empty($sy['last']) ? wp_date('Y.m.d. H:i', (int) $sy['last']) : '', 'sync_n' => (int) ($sy['n'] ?? 0), 'sync_err' => (string) ($sy['err'] ?? ''),
			'imap' => array('host' => $io['host'] ?? '', 'user' => $io['user'] ?? '', 'pw' => function_exists('cp_imp_pw') && cp_imp_pw() !== ''),
			'send' => $so ? array('on' => (int) $so['on'], 'users' => array_map('intval', (array) $so['users']), 'from_name' => $so['from_name'], 'sign' => $so['sign'], 'host' => $so['host'], 'port' => (int) $so['port'], 'secure' => $so['secure'], 'append' => (int) $so['append'], 'sent_folder' => $so['sent_folder'] !== '' && function_exists('cp_imp_utf8') ? cp_imp_utf8($so['sent_folder']) : '') : null),
		'ai' => $ai ? array('on' => (int) $ai['on'], 'model' => $ai['model'], 'models' => cp_ai_models(), 'effort' => $ai['effort'], 'style' => $ai['style'], 'key' => cp_ai_key() !== '') : null,
		'panel' => array('stage_write' => (int) !empty($po['stage_write']), 'backgrounds' => (string) $po['backgrounds'], 'bg_pairs' => (string) ($po['bg_pairs'] ?? ''), 'unknown' => function_exists('cp_panel_bg_pairs_unknown') ? cp_panel_bg_pairs_unknown() : array()),
		'wp' => array(
			array('n' => 'Salesmate-kapcsolat, kapacitások, szakaszok, ügyfélportál', 'u' => admin_url('options-general.php?page=cp-panel')),
			array('n' => 'Crazy CRM – szinkron állapota', 'u' => admin_url('options-general.php?page=cp-crm')),
			array('n' => 'Statisztikák és összesítők', 'u' => admin_url('options-general.php?page=cp-st')),
			array('n' => 'Levelek importja, postafiók jelszava, sablonok betöltése', 'u' => admin_url('options-general.php?page=cp-mail-import')),
			array('n' => 'Kezelői elszámolás', 'u' => admin_url('options-general.php?page=cp-el')),
		),
	);
	return $x;
}

add_action('wp_ajax_nopriv_cp_set_get', 'cp_set_ajax_get');
add_action('wp_ajax_cp_set_get', 'cp_set_ajax_get');
function cp_set_ajax_get() { cp_set_auth(); wp_send_json_success(cp_set_data()); }

/* ------------------------------------------------------------ saját beállítások */
add_action('wp_ajax_nopriv_cp_set_me', 'cp_set_ajax_me');
add_action('wp_ajax_cp_set_me', 'cp_set_ajax_me');
function cp_set_ajax_me() {
	cp_set_auth();
	$me = cp_set_me();
	if (!$me) { cp_el_err('A saját beállításokhoz személyes fiókkal kell belépni.', 403); }
	$d = cp_pu_data();
	if (!isset($d['list'][$me])) { cp_el_err('Nincs ilyen felhasználó.', 404); }
	$op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
	$msg = '';
	if ($op === 'save') {
		$n = sanitize_text_field(wp_unslash($_POST['n'] ?? ''));
		if ($n !== '') { $d['list'][$me]['n'] = mb_substr($n, 0, 100); }
		$sig = cp_sig_norm_links(cp_mail_clean(wp_unslash($_POST['sig'] ?? '')));
		$d['list'][$me]['sig'] = trim(wp_strip_all_tags($sig)) === '' && strpos($sig, '<img') === false ? '' : mb_substr($sig, 0, 20000);
		cp_pu_save($d);
		$h = trim((string) wp_unslash($_POST['hook'] ?? ''));
		if (!empty($_POST['hook_clear'])) { cp_nt_hook_set('notif_hook_u' . $me, ''); }
		elseif ($h !== '') { if (!cp_nt_hook_ok($h)) { cp_el_err('A Pumble-cím nem https://api.pumble.com/… kezdetű.'); } cp_nt_hook_set('notif_hook_u' . $me, $h); }
		$msg = 'Mentve.';
	} elseif ($op === 'pwlink') {
		$r = cp_pu_send_link($me, 'reset');
		if (is_wp_error($r)) { cp_el_err('A levél nem ment ki: ' . $r->get_error_message()); }
		$msg = 'Elküldtem a jelszó-linket ide: ' . $d['list'][$me]['e'];
	} else { cp_el_err('Hibás kérés.'); }
	wp_send_json_success(array('msg' => $msg, 'data' => cp_set_data()));
}

/* ------------------------------------------------------------ adminisztráció */
add_action('wp_ajax_nopriv_cp_set_admin', 'cp_set_ajax_admin');
add_action('wp_ajax_cp_set_admin', 'cp_set_ajax_admin');
function cp_set_ajax_admin() {
	cp_set_auth(true);
	$sec = sanitize_key(wp_unslash($_POST['sec'] ?? ''));
	$p = function ($k, $def = '') { return isset($_POST[$k]) ? wp_unslash($_POST[$k]) : $def; };
	$msg = ''; $link = '';
	switch ($sec) {
		case 'user':
			$d = cp_pu_data(); $op = sanitize_key($p('op')); $uid = (int) $p('uid', 0);
			if ($op === 'add') {
				$n = sanitize_text_field($p('n')); $e = sanitize_email($p('e')); $r = sanitize_key($p('r', 'iroda'));
				if ($n === '' || !is_email($e)) { cp_el_err('Add meg a nevet és egy érvényes e-mail-címet.'); }
				if (cp_pu_by_email($e)) { cp_el_err('Ezzel az e-mail-címmel már van felhasználó.'); }
				$id = (int) $d['next']; $d['next'] = $id + 1;
				$d['list'][$id] = array('n' => $n, 'e' => $e, 'r' => isset(cp_pu_roles()[$r]) ? $r : 'iroda', 'sm' => (int) $p('sm', 0), 'h' => '', 'v' => 1, 'a' => 1, 'last' => 0, 'at' => time());
				cp_pu_save($d);
				$res = $p('send') ? cp_pu_send_link($id, 'invite') : cp_pu_make_link($id, 7 * 24);
				if (is_wp_error($res)) { $link = cp_pu_make_link($id, 7 * 24); $msg = 'A felhasználó létrejött, de a meghívó nem ment ki (' . $res->get_error_message() . '). Ezt a linket küldd el neki:'; }
				else { $link = (string) $res; $msg = $p('send') ? 'Létrejött, a meghívó elment ide: ' . $e . '. Ha nem érkezne meg, ez a link is jó:' : 'Létrejött. Ezzel a linkkel állíthatja be a jelszavát (7 napig):'; }
				break;
			}
			if (!$uid || !isset($d['list'][$uid])) { cp_el_err('Nincs ilyen felhasználó.', 404); }
			$u = $d['list'][$uid];
			if ($op === 'save') {
				$r = sanitize_key($p('r', $u['r']));
				if ($uid === cp_set_me() && $r !== 'admin') { cp_el_err('A saját admin-jogodat nem veheted el (kérj meg egy másik admint).'); }
				$d['list'][$uid]['r'] = isset(cp_pu_roles()[$r]) ? $r : $u['r'];
				$d['list'][$uid]['n'] = sanitize_text_field($p('n', $u['n'])) ?: $u['n'];
				$d['list'][$uid]['sm'] = (int) $p('sm', $u['sm'] ?? 0);
				cp_pu_save($d); $msg = 'Mentve: ' . $d['list'][$uid]['n'] . '.';
			} elseif ($op === 'off' || $op === 'on') {
				if ($uid === cp_set_me()) { cp_el_err('Saját magadat nem tilthatod le.'); }
				$d['list'][$uid]['a'] = $op === 'on' ? 1 : 0; $d['list'][$uid]['v'] = (int) $u['v'] + 1;
				cp_pu_save($d); $msg = $u['n'] . ($op === 'on' ? ' újra aktív.' : ' letiltva (azonnal kiléptettük).');
			} elseif ($op === 'invite') {
				$res = cp_pu_send_link($uid, (string) $u['h'] === '' ? 'invite' : 'reset');
				if (is_wp_error($res)) { $link = cp_pu_make_link($uid, 48); $msg = 'A levél nem ment ki (' . $res->get_error_message() . '). Ezt a linket küldd el neki:'; }
				else { $link = (string) $res; $msg = 'Elküldtem a linket ide: ' . $u['e'] . '. Ha nem érkezne meg, ez is jó:'; }
			} elseif ($op === 'del') {
				if ($uid === cp_set_me()) { cp_el_err('Saját magadat nem törölheted.'); }
				if (!empty($u['a'])) { cp_el_err('Előbb tiltsd le, utána törölheted.'); }
				unset($d['list'][$uid]); cp_pu_save($d); $msg = $u['n'] . ' törölve.';
			} else { cp_el_err('Hibás kérés.'); }
			break;
		case 'vis':
			$in = json_decode((string) $p('vis', '{}'), true); if (!is_array($in)) { $in = array(); }
			$v = array();
			foreach (cp_pu_data()['list'] as $id => $u) {
				if (empty($u['a'])) { continue; }
				foreach (array_keys(cp_pu_tabs()) as $k) {
					$on = !empty($in[$id][$k]);
					if ((int) $id === cp_set_me() && $k === 'beallitasok') { $on = true; } /* saját magadtól nem veheted el */
					if ($on !== cp_pu_tab_default($k, $u['r'])) { $v[(int) $id][$k] = $on ? 1 : 0; }
				}
			}
			update_option('cp_panel_vis', $v, false); $msg = 'A láthatóság elmentve (a többieknél a következő oldalbetöltéskor látszik).';
			break;
		case 'sig':
			$d = cp_pu_data(); $uid = (int) $p('uid', 0);
			if (!isset($d['list'][$uid])) { cp_el_err('Nincs ilyen felhasználó.', 404); }
			$sig = cp_sig_norm_links(cp_mail_clean($p('sig')));
			$d['list'][$uid]['sig'] = trim(wp_strip_all_tags($sig)) === '' && strpos($sig, '<img') === false ? '' : mb_substr($sig, 0, 20000);
			cp_pu_save($d); $msg = $d['list'][$uid]['n'] . ' aláírása mentve.';
			break;
		case 'shared':
			$off = (bool) $p('off');
			if ($off && !cp_set_me()) { cp_el_err('Előbb lépj be a saját fiókoddal – most közös jelszóval vagy bent.'); }
			update_option('cp_panel_shared_off', $off ? '1' : '0', false);
			$msg = $off ? 'A közös jelszavak kikapcsolva – mostantól csak személyes fiókkal lehet belépni.' : 'A közös jelszavak újra működnek.';
			break;
		case 'notif':
			$set = function ($k, $v, $clear, $label) use (&$msg) {
				if ($clear) { cp_nt_hook_set($k, ''); $msg .= $label . ': törölve. '; return; }
				$v = trim((string) $v); if ($v === '') { return; }
				if (!cp_nt_hook_ok($v)) { cp_el_err($label . ': a cím nem https://api.pumble.com/… kezdetű.'); }
				cp_nt_hook_set($k, $v); $msg .= $label . ': elmentve. ';
			};
			$set('notif_hook', $p('common'), (bool) $p('common_clear'), 'Közös csatorna');
			$us = json_decode((string) $p('users', '{}'), true);
			foreach (cp_pu_data()['list'] as $id => $u) { if (isset($us[$id])) { $set('notif_hook_u' . (int) $id, $us[$id]['v'] ?? '', !empty($us[$id]['clear']), $u['n']); } }
			if ($p('test')) {
				$h = cp_nt_hook_get('notif_hook');
				if ($h === '') { $msg .= 'Teszt: nincs közös csatorna.'; }
				else { $r = wp_remote_post($h, array('timeout' => 10, 'headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode(array('text' => '**Crazy panel** – teszt értesítés a Beállításokból.')))); $c = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r); $msg .= $c >= 200 && $c < 300 ? 'Teszt üzenet elküldve.' : 'Teszt: a Pumble nem fogadta.'; }
			}
			$msg = trim($msg) ?: 'Nem változott semmi.';
			break;
		case 'mail':
			$on = (bool) $p('sync_on');
			update_option('cp_imp_sync_on', $on ? '1' : '0', false);
			if ($on) { if (!wp_next_scheduled('cp_imp_sync')) { wp_schedule_event(time() + 30, 'cp_5min', 'cp_imp_sync'); } } else { wp_clear_scheduled_hook('cp_imp_sync'); }
			if (function_exists('cp_send_opts')) {
				$o = cp_send_opts();
				$o['on'] = $p('send_on') ? 1 : 0;
				$o['users'] = array_values(array_filter(array_map('intval', (array) json_decode((string) $p('send_users', '[]'), true))));
				$o['from_name'] = sanitize_text_field($p('from_name', 'EventCrazy')) ?: 'EventCrazy';
				$o['sign'] = sanitize_textarea_field($p('sign'));
				$o['host'] = preg_replace('/[^a-z0-9.\-]/i', '', (string) $p('host'));
				$o['port'] = (int) $p('port', 465) ?: 465;
				$o['secure'] = $p('secure') === 'tls' ? 'tls' : 'ssl';
				$o['append'] = $p('append') ? 1 : 0;
				update_option('cp_send_opts', $o, false);
			}
			$msg = 'Levelezés mentve. Beérkezés: ' . ($on ? 'be' : 'ki') . '; küldés: ' . (!empty($o['on']) ? 'be (' . count($o['users']) . ' kolléga)' : 'ki') . '.';
			break;
		case 'smtp':
			if (!function_exists('cp_send_mailer')) { cp_el_err('A küldés modul nincs betöltve.'); }
			try { $m = cp_send_mailer(); $ok = $m->smtpConnect(); $m->smtpClose(); $f = cp_send_sent_folder(); $msg = ($ok ? 'Az SMTP-kapcsolat és a bejelentkezés rendben.' : 'Nem sikerült csatlakozni.') . ' Elküldött mappa: ' . ($f !== '' ? cp_imp_utf8($f) : 'nem található') . '. (Levél nem ment ki.)'; }
			catch (\Throwable $e) { cp_el_err('SMTP-hiba: ' . mb_substr($e->getMessage(), 0, 200)); }
			break;
		case 'ai':
			$o = cp_ai_opts();
			$o['on'] = $p('on') ? 1 : 0;
			$m = sanitize_text_field($p('model')); if (isset(cp_ai_models()[$m])) { $o['model'] = $m; }
			$e = sanitize_key($p('effort', 'medium')); if (in_array($e, array('low', 'medium', 'high'), true)) { $o['effort'] = $e; }
			$o['style'] = mb_substr(sanitize_textarea_field($p('style')), 0, 2000);
			update_option('cp_ai_opts', $o, false);
			$k = trim((string) $p('key'));
			if ($p('key_clear')) { cp_st_secret_set('ai_key', ''); $msg = 'AI mentve, a kulcs törölve.'; }
			elseif ($k !== '') { if (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $k)) { cp_el_err('Ez nem Anthropic API-kulcsnak tűnik (sk-ant-… kezdetű).'); } cp_st_secret_set('ai_key', $k); $msg = 'AI mentve, a kulcs beállítva.'; }
			else { $msg = 'AI mentve.'; }
			break;
		case 'panel':
			$o = get_option(CP_PANEL_OPT, array()); if (!is_array($o)) { $o = array(); }
			$o['stage_write'] = $p('stage_write') ? 1 : 0;
			$o['backgrounds'] = sanitize_textarea_field($p('backgrounds'));
			$o['bg_pairs'] = sanitize_textarea_field($p('bg_pairs'));
			update_option(CP_PANEL_OPT, $o);
			$msg = 'Mentve. (A háttérlista és a párok a következő oldalbetöltéskor frissülnek.)';
			break;
		default: cp_el_err('Hibás kérés.');
	}
	wp_send_json_success(array('msg' => $msg, 'link' => $link, 'data' => cp_set_data()));
}

/* ------------------------------------------------------------ kép az aláíráshoz (pl. logó) – nyilvános címen, mert a címzett levelezője tölti be */
add_action('wp_ajax_nopriv_cp_set_img', 'cp_set_ajax_img');
add_action('wp_ajax_cp_set_img', 'cp_set_ajax_img');
function cp_set_ajax_img() {
	cp_set_auth();
	if (empty($_FILES['f']) || !is_uploaded_file($_FILES['f']['tmp_name']) || (int) $_FILES['f']['error'] !== 0) { cp_el_err('A feltöltés nem sikerült.'); }
	$f = $_FILES['f'];
	if ((int) $f['size'] > 2 * 1024 * 1024) { cp_el_err('A kép legfeljebb 2 MB lehet.'); }
	$chk = wp_check_filetype_and_ext($f['tmp_name'], $f['name'], array('png' => 'image/png', 'jpg|jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'));
	if (empty($chk['ext']) || !@getimagesize($f['tmp_name'])) { cp_el_err('Csak PNG, JPG, GIF vagy WEBP kép tölthető fel.'); }
	$u = wp_upload_dir();
	$dir = trailingslashit($u['basedir']) . 'crazy-sign';
	wp_mkdir_p($dir);
	$name = wp_generate_password(10, false, false) . '-' . sanitize_file_name(pathinfo($f['name'], PATHINFO_FILENAME)) . '.' . $chk['ext'];
	if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { cp_el_err('A feltöltés nem sikerült (tárhely).'); }
	wp_send_json_success(array('url' => trailingslashit($u['baseurl']) . 'crazy-sign/' . $name));
}

