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
/* v2.9.58: $neterr – igaz, ha egyetlen mappát sem sikerült megnyitni (kapcsolati hiba, nem „nincs meg”) */
function cp_att_locate($m, &$neterr = null) {
	$neterr = false;
	global $wpdb;
	$M = cp_mail_t('messages');
	if (!empty($m['imap_folder']) && !empty($m['imap_uid'])) { return array($m['imap_folder'], (int) $m['imap_uid']); }
	if (empty($m['mid'])) { return null; }
	$folders = array('INBOX');
	if ($m['dir'] === 'out' && function_exists('cp_send_sent_folder')) { $sf = cp_send_sent_folder(); if ($sf !== '') { array_unshift($folders, $sf); } }
	foreach ((array) (cp_imp_state()['folders'] ?? array()) as $f) { if (!empty($f['raw']) && !in_array($f['raw'], $folders, true)) { $folders[] = $f['raw']; } }
	$ts = strtotime($m['sent_at'] . ' UTC') ?: time();
	/* v2.9.58: egy IMAP-kapcsolat, mappaváltás imap_reopen-nel (nem új bejelentkezés mappánként) */
	$mb = null; $opened = 0;
	foreach (array_slice($folders, 0, 6) as $fd) {
		if (!$mb || !@imap_reopen($mb, cp_imp_ref() . $fd, OP_READONLY)) {
			if ($mb) { @imap_close($mb); imap_errors(); }
			$mb = cp_imp_open($fd);
			if (is_wp_error($mb)) { $mb = null; continue; }
		}
		$opened++;
		$crit = 'SINCE "' . gmdate('d-M-Y', $ts - 2 * DAY_IN_SECONDS) . '" BEFORE "' . gmdate('d-M-Y', $ts + 2 * DAY_IN_SECONDS) . '"';
		if ($m['dir'] === 'in' && !empty($m['from_email'])) { $crit .= ' FROM "' . addcslashes($m['from_email'], '"\\') . '"'; }
		$hit = 0;
		foreach (array_slice(array_filter((array) @imap_search($mb, $crit, SE_UID)), 0, 60) as $uid) {
			$h = @imap_rfc822_parse_headers((string) imap_fetchheader($mb, $uid, FT_UID));
			$raw = $h && !empty($h->message_id) ? trim($h->message_id) : '';
			if ($raw !== '' && md5($raw) === $m['mid']) { $hit = (int) $uid; break; }
		}
		imap_errors();
		if ($hit) { imap_close($mb); $wpdb->update($M, array('imap_folder' => $fd, 'imap_uid' => $hit), array('id' => (int) $m['id'])); return array($fd, $hit); }
	}
	if ($mb) { imap_close($mb); }
	$neterr = $opened === 0;
	return null;
}

function cp_att_send_file($name, $mime, $data, $inline) {
	if (!empty($GLOBALS['cp_att_capture'])) { call_user_func($GLOBALS['cp_att_capture'], $name, $mime, $data); exit; } /* v2.8.8: bélyegkép készítése */
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
/* v2.9.58: $perm – végleges hiba (a levél / melléklet nincs meg): a bélyegkép-kérés „nincs” jelölőt kap, és többé nem keres a postafiókban */
function cp_att_fail($msg, $code = 404, $perm = false) {
	if (!empty($GLOBALS['cp_att_capture'])) { if ($perm && !empty($GLOBALS['cp_thumb_req'])) { cp_thumb_none(); } status_header(404); header('Cache-Control: no-store'); exit; } /* átmeneti hiba (pl. postafiók): nem jegyzi meg */
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
	/* v2.9.58: csak a szükséges oszlopok (a törzs és a HTML nélkül) */
	$m = $wpdb->get_row($wpdb->prepare('SELECT id, dir, from_email, attachments, sent_at, mid, imap_folder, imap_uid FROM ' . cp_mail_t('messages') . ' WHERE id = %d', (int) ($_REQUEST['m'] ?? 0)), ARRAY_A);
	if (!$m) { cp_att_fail('Nincs ilyen levél.', 404, true); }
	$i = (int) ($_REQUEST['i'] ?? 0);
	$list = json_decode((string) $m['attachments'], true) ?: array();
	if (!isset($list[$i])) { cp_att_fail('Nincs ilyen melléklet.', 404, true); }
	$a = $list[$i];
	$inline = !empty($_REQUEST['view']);
	/* a panelből küldött levél melléklete: a szerveren */
	if (!empty($a['f'])) {
		$path = cp_att_dir() . '/' . basename(dirname($a['f'])) . '/' . basename($a['f']);
		if (!is_file($path)) { cp_att_fail('A fájl már nincs meg a szerveren.', 404, true); }
		cp_att_send_file($a['n'], (string) ($a['mime'] ?? 'application/octet-stream'), (string) file_get_contents($path), $inline);
	}
	if (!function_exists('cp_imp_open') || cp_imp_pw() === '') { cp_att_fail('A postafiók nincs bekötve.'); }
	$loc = cp_att_locate($m, $nerr);
	if (!$loc) { cp_att_fail($nerr ? 'A postafiókhoz most nem sikerült csatlakozni – próbáld újra később.' : 'A levelet nem találom a postafiókban (lehet, hogy azóta törölték vagy áthelyezték).', $nerr ? 502 : 404, !$nerr); }
	$mb = cp_imp_open($loc[0]);
	if (is_wp_error($mb)) { cp_att_fail('A postafiókhoz nem sikerült csatlakozni: ' . $mb->get_error_message(), 502); }
	/* v1.99.2: a tárolt helyen tényleg ez a levél van-e (a szerver újraszámozhatja a leveleket) – különben újrakeresés Message-ID alapján */
	if (!empty($m['mid'])) {
		$hraw = (string) @imap_fetchheader($mb, $loc[1], FT_UID);
		$hh = $hraw !== '' ? @imap_rfc822_parse_headers($hraw) : null;
		$got = $hh && !empty($hh->message_id) ? md5(trim($hh->message_id)) : ($hraw !== '' ? md5($hraw) : '');
		if ($got !== $m['mid']) {
			imap_close($mb);
			global $wpdb;
			$wpdb->update(cp_mail_t('messages'), array('imap_folder' => '', 'imap_uid' => null), array('id' => (int) $m['id']));
			$m['imap_folder'] = ''; $m['imap_uid'] = null;
			$loc = cp_att_locate($m, $nerr);
			if (!$loc) { cp_att_fail($nerr ? 'A postafiókhoz most nem sikerült csatlakozni – próbáld újra később.' : 'A levelet nem találom a postafiókban (lehet, hogy azóta törölték vagy áthelyezték).', $nerr ? 502 : 404, !$nerr); }
			$mb = cp_imp_open($loc[0]);
			if (is_wp_error($mb)) { cp_att_fail('A postafiókhoz nem sikerült csatlakozni: ' . $mb->get_error_message(), 502); }
		}
	}
	$parts = cp_att_parts($mb, $loc[1]);
	/* elsősorban a sorszám, ha a név is egyezik; különben név szerint */
	/* v2.8.6: a régi (kódolt nevű) bejegyzés is párosodjon a postafiók most már kiolvasott nevével */
	$nn = function ($v) { return function_exists('cp_mail_fname') ? cp_mail_fname((string) $v) : (string) $v; };
	$p = isset($parts[$i]) && $nn($parts[$i]['n']) === $nn($a['n']) ? $parts[$i] : null;
	if (!$p) { foreach ($parts as $x) { if ($nn($x['n']) === $nn($a['n'])) { $p = $x; break; } } }
	if (!$p) { imap_close($mb); cp_att_fail('A melléklet nincs meg a levélben.', 404, true); }
	if ($p['size'] > 40 * 1024 * 1024) { imap_close($mb); cp_att_fail('A melléklet túl nagy a panelhez (40 MB felett) – nyisd meg levelezőprogramból.', 404, true); }
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
		if (is_file($sub . '/.sent') || basename($sub) === 'thumbs') { continue; } /* a bélyegképeknek saját, 90 napos takarítása van */
		if (filemtime($sub) < time() - 2 * DAY_IN_SECONDS) { foreach ((array) glob($sub . '/*') as $x) { @unlink($x); } @rmdir($sub); }
	}
});

/* ------------------------------------------------------------ v2.8.8: bélyegképek a mellékletekhez
 * Csak képekből (JPG, PNG, GIF, WebP, HEIC – a fájl tartalma alapján ellenőrizve, nem a neve alapján), első megnézéskor
 * egyszer készül (240 px, WebP, ~10 KB), a védett crazy-mail/thumbs mappában tárolva; 90 napig nem nézett bélyeg törlődik.
 * PDF-ből és más fájlból nem készül (a PDF-feldolgozás – Ghostscript – idegen feladók fájljain biztonsági kockázat). */
function cp_thumb_dir() { $d = cp_att_dir() . '/thumbs'; if (!is_dir($d)) { wp_mkdir_p($d); @file_put_contents($d . '/index.php', '<?php // csend'); } return $d; }
function cp_thumb_key($m = null, $i = null) { return md5((int) ($m ?? ($_REQUEST['m'] ?? 0)) . '|' . (int) ($i ?? ($_REQUEST['i'] ?? 0)) . '|v1'); }
/* v2.9.66: elrejtendő kép a deal Fájlok kártyáján: 10 KB alatti (a levél adatai vagy a bélyegkészítés szerint), vagy nem jeleníthető meg (üres / sérült) */
function cp_thumb_hash($m, $i) {
	$f = cp_att_dir() . '/thumbs/' . cp_thumb_key($m, $i) . '.h';
	return is_file($f) ? (string) @file_get_contents($f) : '';
}
function cp_thumb_tiny($m, $i, $size = null) {
	if ($size !== null && (int) $size > 0) { return (int) $size < 10240; }
	$f = cp_att_dir() . '/thumbs/' . cp_thumb_key($m, $i);
	return is_file($f . '.small') || is_file($f . '.none');
}
function cp_thumb_none() {
	@file_put_contents(cp_thumb_dir() . '/' . cp_thumb_key() . '.none', '1');
	status_header(404); header('Cache-Control: private, max-age=86400'); exit;
}
function cp_thumb_fmt($data) {
	$h = substr((string) $data, 0, 16);
	if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) { return 'jpeg'; }
	if (strncmp($h, "\x89PNG", 4) === 0) { return 'png'; }
	if (strncmp($h, 'GIF8', 4) === 0) { return 'gif'; }
	if (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP') { return 'webp'; }
	if (substr($h, 4, 4) === 'ftyp' && preg_match('/^(heic|heix|mif1|msf1|hevc)$/', substr($h, 8, 4))) { return 'heic'; }
	return '';
}
function cp_thumb_make($data) {
	$fmt = cp_thumb_fmt($data);
	if ($fmt === '' || strlen($data) > 25 * 1024 * 1024 || !class_exists('Imagick')) { return null; }
	try {
		if ($fmt === 'heic' && !Imagick::queryFormats('HEIC')) { return null; }
		Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
		Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, 80000000);
		$im = new Imagick();
		$im->setFormat($fmt);
		$im->readImageBlob($data);
		if ($im->getNumberImages() > 1) { $im->setIteratorIndex(0); $im = $im->getImage(); }
		if (method_exists($im, 'autoOrient')) { $im->autoOrient(); }
		$im->thumbnailImage(240, 240, true);
		$im->setImageBackgroundColor('white');
		if ($im->getImageAlphaChannel()) { $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN); }
		$wf = Imagick::queryFormats('WEBP') ? 'webp' : 'jpeg'; /* ha a szerveren nincs WebP-író: JPEG */
		$im->setImageFormat($wf); $im->setImageCompressionQuality($wf === 'webp' ? 72 : 78); $im->stripImage();
		$b = $im->getImageBlob(); $im->clear();
		return $b !== '' ? $b : null;
	} catch (\Throwable $e) { return null; }
}
add_action('wp_ajax_nopriv_cp_mail_thumb', 'cp_thumb_ajax');
add_action('wp_ajax_cp_mail_thumb', 'cp_thumb_ajax');
function cp_thumb_ajax() {
	if (!function_exists('cp_panel_authed') || !cp_panel_authed() || !hash_equals(cp_panel_csrf(), (string) wp_unslash($_REQUEST['t'] ?? ''))) { status_header(403); exit; }
	$f = cp_thumb_dir() . '/' . cp_thumb_key();
	if (is_file($f . '.none')) { status_header(404); header('Cache-Control: private, max-age=86400'); exit; }
	$out = function ($b) { header('Content-Type: ' . (strncmp($b, 'RIFF', 4) === 0 ? 'image/webp' : (strncmp($b, "\x89PNG", 4) === 0 ? 'image/png' : 'image/jpeg'))); header('Cache-Control: private, max-age=2592000'); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . strlen($b)); echo $b; exit; };
	if (is_file($f . '.webp')) { @touch($f . '.webp'); $out((string) file_get_contents($f . '.webp')); }
	@set_time_limit(40);
	$GLOBALS['cp_thumb_req'] = 1; /* v2.9.58: a végleges „nincs meg” hibánál „nincs” jelölő (a zip-ellenőrzés nem ír ilyet) */
	$GLOBALS['cp_att_capture'] = function ($name, $mime, $data) use ($f, $out) {
		/* v2.9.66: a 10 KB alatti kép megjelölése – a deal Fájlok kártyáján az aláírásképekkel együtt rejtve */
		if (strlen((string) $data) < 10240) { @file_put_contents($f . '.small', '1'); }
		/* v2.9.71: a kép tartalmának ujjlenyomata – az azonos (más néven, más levélben érkezett) kép a deal Fájlok kártyáján egy példányban */
		@file_put_contents($f . '.h', substr(md5((string) $data), 0, 16));
		$b = cp_thumb_make((string) $data);
		if ($b === null) { cp_thumb_none(); }
		@file_put_contents($f . '.webp', $b);
		$out($b);
	};
	cp_att_ajax_get();
}
/* naponta: a 90 napja nem nézett bélyegek törlése */
add_action('cp_crm_tick', function () {
	if (get_transient('cp_thumb_gc')) { return; }
	set_transient('cp_thumb_gc', 1, DAY_IN_SECONDS);
	$d = cp_att_dir() . '/thumbs'; if (!is_dir($d)) { return; }
	foreach ((array) glob($d . '/*.{webp,jpg,none,small,h}', GLOB_BRACE) as $x) { if (@filemtime($x) < time() - 90 * DAY_IN_SECONDS) { @unlink($x); } }
}, 60);

