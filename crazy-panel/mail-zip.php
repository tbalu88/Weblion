<?php
/**
 * Crazy panel – zip-csatolmányok tartalomellenőrzése (v2.9.28)
 * A panel a szerveren, kicsomagolás nélkül végignézi a zip tartalomjegyzékét: veszélyes fájl (program, weboldal,
 * álcázott név), jelszavas zip, zipben zip/rar, gyanúsan nagy kicsomagolt méret („zip-bomba”). A fájl nem hagyja el
 * a tárhelyet. Az eredmény egy hétig gyorsítótárban van (levél + melléklet szerint).
 */
if (!defined('ABSPATH')) { exit; }

function cp_zip_kind($n) {
	$n = (string) $n;
	if (preg_match('/[\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $n) || preg_match('/\.(pdf|docx?|xlsx?|jpe?g|png)\s{2,}/i', $n) || preg_match('/\.(pdf|docx?|xlsx?|jpe?g|png)\.(exe|scr|js|vbs|bat|cmd|com|hta|lnk)$/i', $n)) { return 'high'; }
	if (preg_match('/\.(exe|scr|bat|cmd|com|pif|msi|msp|js|jse|vbs|vbe|wsf|wsh|ps1|psm1|hta|jar|lnk|iso|img|vhdx?|reg|cpl|dll|html?|svg|xhtml|mht|url|appx|msix|sh|app|dmg|pkg)$/i', $n)) { return 'high'; }
	if (preg_match('/\.(zip|rar|7z|gz|tgz|tar|cab|ace|arj|bz2|xz)$/i', $n)) { return 'nest'; }
	if (preg_match('/\.(docm|dotm|xlsm|xltm|xlam|pptm|potm|ppsm|rtf|one)$/i', $n)) { return 'warn'; }
	if (preg_match('/\.(pdf|docx?|odt|xlsx?|ods|pptx?|odp|txt|csv|jpe?g|png|gif|webp|heic|heif|tiff?|bmp|mp4|mov|m4v|avi|mp3|wav|m4a)$/i', $n)) { return 'ok'; }
	return 'other';
}
function cp_zip_label($n) {
	if (preg_match('/\.pdf$/i', $n)) { return 'PDF'; }
	if (preg_match('/\.(docx?|odt|rtf|txt)$/i', $n)) { return 'szöveg'; }
	if (preg_match('/\.(xlsx?|ods|csv|pptx?|odp)$/i', $n)) { return 'táblázat/bemutató'; }
	if (preg_match('/\.(jpe?g|png|gif|webp|heic|heif|tiff?|bmp)$/i', $n)) { return 'kép'; }
	if (preg_match('/\.(mp4|mov|m4v|avi)$/i', $n)) { return 'videó'; }
	if (preg_match('/\.(mp3|wav|m4a)$/i', $n)) { return 'hang'; }
	return 'egyéb';
}
function cp_zip_inspect($data) {
	if (!class_exists('ZipArchive')) { return array('lvl' => '', 'err' => 'A tárhelyen nincs zip-olvasó.'); }
	$tmp = wp_tempnam('cpzip');
	file_put_contents($tmp, $data);
	$z = new ZipArchive();
	$r = $z->open($tmp);
	if ($r !== true) { @unlink($tmp); return array('lvl' => 'high', 'err' => 'Nem olvasható zip: sérült, vagy valójában nem zip (álcázott fájl lehet).'); }
	$out = array('n' => 0, 'kinds' => array(), 'risky' => array(), 'nested' => array(), 'macro' => array(), 'enc' => false, 'bomb' => false, 'list' => array());
	$total = 0; $comp = 0; $other = 0;
	$max = min((int) $z->numFiles, 3000);
	for ($i = 0; $i < $max; $i++) {
		$st = $z->statIndex($i);
		if (!$st) { continue; }
		$name = (string) $st['name'];
		if (substr($name, -1) === '/' || preg_match('#(^|/)(__MACOSX/|\.DS_Store$)#', $name)) { continue; }
		$base = basename($name);
		$out['n']++;
		if (count($out['list']) < 40) { $out['list'][] = $name; }
		$total += (int) $st['size']; $comp += (int) $st['comp_size'];
		if (!empty($st['encryption_method'])) { $out['enc'] = true; }
		$k = cp_zip_kind($base);
		if ($k === 'high' && count($out['risky']) < 10) { $out['risky'][] = $name; }
		elseif ($k === 'nest' && count($out['nested']) < 10) { $out['nested'][] = $name; }
		elseif ($k === 'warn' && count($out['macro']) < 10) { $out['macro'][] = $name; }
		elseif ($k === 'other') { $other++; }
		if ($k === 'ok' || $k === 'other') { $lb = cp_zip_label($base); $out['kinds'][$lb] = ($out['kinds'][$lb] ?? 0) + 1; }
	}
	if ((int) $z->numFiles > $max) { $out['more'] = (int) $z->numFiles - $max; }
	$z->close();
	@unlink($tmp);
	/* „zip-bomba”: nagyon nagy kicsomagolt méret extrém tömörítési aránnyal */
	if ($total > 500 * 1048576 || ($comp > 0 && $total / $comp > 200 && $total > 50 * 1048576)) { $out['bomb'] = true; }
	$out['size'] = $total;
	$out['lvl'] = ($out['risky'] || $out['enc']) ? 'high' : (($out['nested'] || $out['macro'] || $out['bomb'] || $other || !$out['n']) ? 'warn' : 'ok');
	return $out;
}

add_action('wp_ajax_nopriv_cp_mail_att_zip', 'cp_zip_ajax');
add_action('wp_ajax_cp_mail_att_zip', 'cp_zip_ajax');
function cp_zip_ajax() {
	if (!function_exists('cp_panel_authed') || !cp_panel_authed() || !hash_equals(cp_panel_csrf(), (string) wp_unslash($_REQUEST['t'] ?? ''))) { wp_send_json_error(array('msg' => 'auth'), 403); }
	if (function_exists('cp_panel_can') && !cp_panel_can('mail') && !cp_panel_can('deals')) { wp_send_json_error(array('msg' => 'Nincs jogosultság.'), 403); }
	$key = 'cp_zip_' . md5((int) ($_REQUEST['m'] ?? 0) . '|' . (int) ($_REQUEST['i'] ?? 0) . '|v1');
	$c = get_transient($key);
	if (is_array($c)) { wp_send_json_success($c); }
	@set_time_limit(40);
	$GLOBALS['cp_att_capture'] = function ($name, $mime, $data) use ($key) {
		if (strlen((string) $data) > 60 * 1048576) { wp_send_json_success(array('lvl' => '', 'err' => 'Túl nagy a zip az ellenőrzéshez (60 MB fölött).')); }
		$r = cp_zip_inspect((string) $data);
		if ($r['lvl'] !== '') { set_transient($key, $r, WEEK_IN_SECONDS); }
		wp_send_json_success($r);
	};
	cp_att_ajax_get();
}
