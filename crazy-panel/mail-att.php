<?php
/* =====================================================================
 * Mellékletek (v1.54)
 * – Letöltés: a beérkezett levelek mellékletei a postafiókban maradnak; kattintásra a panel ott helyben kiolvassa
 *   (csak olvasva) és letölti / megnyitja. A régebbi leveleknél a levelet Message-ID alapján keresi meg, és megjegyzi.
 * – Csatolás: a panelből küldött levélhez fájl tölthető fel (egyenként 15 MB, összesen 25 MB); a küldött levél
 *   mellékletei a szerveren, védett mappában maradnak, hogy a panelből is letölthetők legyenek.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_att_dir() {
	$u = wp_upload_dir();
	$d = trailingslashit($u['basedir']) . 'crazy-mail';
	if (!is_dir($d)) {
		wp_mkdir_p($d);
		@file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
		@file_put_contents($d . '/index.php', '<?php // csend');
	}
	return $d;
}
function cp_att_safe_name($n) {
	$n = trim(preg_replace('/[\x00-\x1F\/\\\\:*?"<>|]+/u', '_', (string) $n));
	return $n !== '' ? mb_substr($n, 0, 120) : 'melleklet';
}
function cp_att_blocked($name) { return (bool) preg_match('/\.(php\d?|phtml|phar|exe|bat|cmd|com|scr|js|vbs|ps1|sh|jar|msi|htaccess)$/i', $name); }

/* a levél mellékletei a postafiókban: [{num, n, mime, enc, size}] – ugyanabban a sorrendben, ahogy a beérkezés felvette őket */
function cp_att_parts($mb, $uid) {
	$st = @imap_fetchstructure($mb, $uid, FT_UID);
	$out = array();
	$walk = function ($part, $num) use (&$walk, &$out) {
		$type = (int) $part->type;
		$fname = cp_imp_param($part, 'filename') ?: cp_imp_param($part, 'name');
		$disp = strtolower((string) ($part->disposition ?? ''));
		if ($type === 1 && !empty($part->parts)) { foreach ($part->parts as $i => $p) { $walk($p, ($num === '' ? '' : $num . '.') . ($i + 1)); } return; }
		if ($fname !== '' && ($disp === 'attachment' || $type !== 0)) {
			$types = array('text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model', 'other');
			$out[] = array('num' => $num === '' ? '1' : $num, 'n' => mb_substr($fname, 0, 120), 'mime' => ($types[$type] ?? 'application') . '/' . strtolower((string) ($part->subtype ?? 'octet-stream')), 'enc' => (int) $part->encoding, 'size' => (int) ($part->bytes ?? 0), 'cid' => !empty($part->ifid) ? trim((string) $part->id, '<> ') : '');
		}
	};
	if ($st) { $walk($st, ''); }
	return $out;
}

/* a levél helye a postafiókban (mappa + UID): a táblából, vagy Message-ID alapján megkeresve */
function cp_att_locate($m) {
	global $wpdb;
	$M = cp_mail_t('messages');
	if (!empty($m['imap_folder']) && !empty($m['imap_uid'])) { return array($m['imap_folder'], (int) $m['imap_uid']); }
	if (empty($m['mid'])) { return null; }
	$folders = array('INBOX');
	if ($m['dir'] === 'out' && function_exists('cp_send_sent_folder')) { $sf = cp_send_sent_folder(); if ($sf !== '') { array_unshift($folders, $sf); } }
	foreach ((array) (cp_imp_state()['folders'] ?? array()) as $f) { if (!empty($f['raw']) && !in_array($f['raw'], $folders, true)) { $folders[] = $f['raw']; } }
	$ts = strtotime($m['sent_at'] . ' UTC') ?: time();
	foreach (array_slice($folders, 0, 6) as $fd) {
		$mb = cp_imp_open($fd);
		if (is_wp_error($mb)) { continue; }
		$crit = 'SINCE "' . gmdate('d-M-Y', $ts - 2 * DAY_IN_SECONDS) . '" BEFORE "' . gmdate('d-M-Y', $ts + 2 * DAY_IN_SECONDS) . '"';
		if ($m['dir'] === 'in' && !empty($m['from_email'])) { $crit .= ' FROM "' . addcslashes($m['from_email'], '"\\') . '"'; }
		$hit = 0;
		foreach (array_slice(array_filter((array) @imap_search($mb, $crit, SE_UID)), 0, 60) as $uid) {
			$h = @imap_rfc822_parse_headers((string) imap_fetchheader($mb, $uid, FT_UID));
			$raw = $h && !empty($h->message_id) ? trim($h->message_id) : '';
			if ($raw !== '' && md5($raw) === $m['mid']) { $hit = (int) $uid; break; }
		}
		imap_errors(); imap_close($mb);
		if ($hit) { $wpdb->update($M, array('imap_folder' => $fd, 'imap_uid' => $hit), array('id' => (int) $m['id'])); return array($fd, $hit); }
	}
	return null;
}

function cp_att_send_file($name, $mime, $data, $inline) {
	nocache_headers();
	header('X-Content-Type-Options: nosniff');
	header('Content-Security-Policy: sandbox');
	$safe_inline = $inline && preg_match('#^(image/(png|jpe?g|gif|webp)|application/pdf)$#', $mime);
	header('Content-Type: ' . ($safe_inline ? $mime : 'application/octet-stream'));
	$ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', remove_accents($name));
	header('Content-Disposition: ' . ($safe_inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
	header('Content-Length: ' . strlen($data));
	echo $data;
	exit;
}
function cp_att_fail($msg, $code = 404) {
	status_header($code);
	nocache_headers();
	header('Content-Type: text/html; charset=utf-8');
	echo '<!doctype html><meta charset="utf-8"><title>Melléklet</title><div style="font:15px/1.5 Arial,sans-serif;max-width:520px;margin:60px auto;color:#1A1F2B"><h2 style="margin:0 0 8px">A melléklet nem nyitható meg</h2><p>' . esc_html($msg) . '</p><p style="color:#6B6F78">A levél a postafiókban, levelezőprogramból is elérhető.</p></div>';
	exit;
}

/* ------------------------------------------------------------ letöltés: ?action=cp_mail_att&m=<levél>&i=<sorszám>&t=… */
add_action('wp_ajax_nopriv_cp_mail_att', 'cp_att_ajax_get');
add_action('wp_ajax_cp_mail_att', 'cp_att_ajax_get');
function cp_att_ajax_get() {
	if (!function_exists('cp_panel_authed') || !cp_panel_authed() || !hash_equals(cp_panel_csrf(), (string) wp_unslash($_REQUEST['t'] ?? ''))) { cp_att_fail('Lejárt a belépés – töltsd újra a panelt.', 403); }
	if (function_exists('cp_panel_can') && !cp_panel_can('mail')) { cp_att_fail('A Levelezés füled nincs bekapcsolva.', 403); }
	global $wpdb;
	$m = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_mail_t('messages') . ' WHERE id = %d', (int) ($_REQUEST['m'] ?? 0)), ARRAY_A);
	if (!$m) { cp_att_fail('Nincs ilyen levél.'); }
	$i = (int) ($_REQUEST['i'] ?? 0);
	$list = json_decode((string) $m['attachments'], true) ?: array();
	if (!isset($list[$i])) { cp_att_fail('Nincs ilyen melléklet.'); }
	$a = $list[$i];
	$inline = !empty($_REQUEST['view']);
	/* a panelből küldött levél melléklete: a szerveren */
	if (!empty($a['f'])) {
		$path = cp_att_dir() . '/' . basename(dirname($a['f'])) . '/' . basename($a['f']);
		if (!is_file($path)) { cp_att_fail('A fájl már nincs meg a szerveren.'); }
		cp_att_send_file($a['n'], (string) ($a['mime'] ?? 'application/octet-stream'), (string) file_get_contents($path), $inline);
	}
	if (!function_exists('cp_imp_open') || cp_imp_pw() === '') { cp_att_fail('A postafiók nincs bekötve.'); }
	$loc = cp_att_locate($m);
	if (!$loc) { cp_att_fail('A levelet nem találom a postafiókban (lehet, hogy azóta törölték vagy áthelyezték).'); }
	$mb = cp_imp_open($loc[0]);
	if (is_wp_error($mb)) { cp_att_fail('A postafiókhoz nem sikerült csatlakozni: ' . $mb->get_error_message(), 502); }
	$parts = cp_att_parts($mb, $loc[1]);
	/* elsősorban a sorszám, ha a név is egyezik; különben név szerint */
	$p = isset($parts[$i]) && $parts[$i]['n'] === $a['n'] ? $parts[$i] : null;
	if (!$p) { foreach ($parts as $x) { if ($x['n'] === $a['n']) { $p = $x; break; } } }
	if (!$p) { imap_close($mb); cp_att_fail('A melléklet nincs meg a levélben.'); }
	if ($p['size'] > 40 * 1024 * 1024) { imap_close($mb); cp_att_fail('A melléklet túl nagy a panelhez (40 MB felett) – nyisd meg levelezőprogramból.'); }
	$raw = (string) imap_fetchbody($mb, $loc[1], $p['num'], FT_UID | FT_PEEK);
	imap_errors(); imap_close($mb);
	$data = $p['enc'] === 3 ? base64_decode($raw) : ($p['enc'] === 4 ? quoted_printable_decode($raw) : $raw);
	cp_att_send_file($p['n'], $p['mime'], (string) $data, $inline);
}

/* ------------------------------------------------------------ feltöltés küldéshez (a levélíróból) */
add_action('wp_ajax_nopriv_cp_mail_upload', 'cp_att_ajax_upload');
add_action('wp_ajax_cp_mail_upload', 'cp_att_ajax_upload');
function cp_att_ajax_upload() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if (!function_exists('cp_send_can') || !cp_send_can($me)) { cp_el_err('A panelből küldés neked nincs bekapcsolva.', 403); }
	if (empty($_FILES['f']) || !is_uploaded_file($_FILES['f']['tmp_name']) || (int) $_FILES['f']['error'] !== 0) { cp_el_err('A feltöltés nem sikerült.'); }
	$f = $_FILES['f'];
	$name = cp_att_safe_name(wp_unslash($f['name']));
	if (cp_att_blocked($name)) { cp_el_err('Ilyen típusú fájl nem csatolható.'); }
	if ((int) $f['size'] > 15 * 1024 * 1024) { cp_el_err('Egy melléklet legfeljebb 15 MB lehet.'); }
	$tok = wp_generate_password(20, false, false);
	$dir = cp_att_dir() . '/' . $tok;
	wp_mkdir_p($dir);
	$path = $dir . '/' . md5($name) . '.bin';
	if (!move_uploaded_file($f['tmp_name'], $path)) { cp_el_err('A feltöltés nem sikerült (tárhely).'); }
	$ft = wp_check_filetype($name);
	set_transient('cp_att_' . $tok, array('uid' => $me, 'n' => $name, 'size' => (int) $f['size'], 'mime' => $ft['type'] ?: 'application/octet-stream', 'path' => $path), DAY_IN_SECONDS);
	wp_send_json_success(array('tok' => $tok, 'n' => $name, 'size' => (int) $f['size']));
}
/* a levélíróban kiválasztott mellékletek (a küldéskor) – ellenőrizve, hogy a feltöltőé */
function cp_att_take($toks, $uid) {
	$out = array(); $tot = 0;
	foreach (array_slice(array_filter(array_map('sanitize_key', explode(',', (string) $toks))), 0, 10) as $t) {
		$x = get_transient('cp_att_' . $t);
		if (!$x || (int) $x['uid'] !== (int) $uid || !is_file($x['path'])) { return new WP_Error('att', 'Egy melléklet közben elveszett – csatold újra.'); }
		$tot += (int) $x['size'];
		$x['tok'] = $t;
		$out[] = $x;
	}
	if ($tot > 25 * 1024 * 1024) { return new WP_Error('att', 'A mellékletek együtt legfeljebb 25 MB-osak lehetnek.'); }
	return $out;
}
/* régi, el nem küldött feltöltések takarítása (naponta) */
add_action('init', function () {
	if (wp_doing_ajax() || get_transient('cp_att_gc')) { return; }
	set_transient('cp_att_gc', 1, DAY_IN_SECONDS);
	$d = cp_att_dir();
	foreach ((array) glob($d . '/*', GLOB_ONLYDIR) as $sub) {
		if (is_file($sub . '/.sent')) { continue; }
		if (filemtime($sub) < time() - 2 * DAY_IN_SECONDS) { foreach ((array) glob($sub . '/*') as $x) { @unlink($x); } @rmdir($sub); }
	}
});
