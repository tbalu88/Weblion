<?php
/* Crazy panel – Sablonkészítő (v1.11)
 * A crazyphoto.hu „Választható fotósablonok” élő előnézetének panelbeli változata:
 * lyukasztott (átlátszó fotóablakos) PNG a fotós szoftvernek, egységes 4×6" méretben, a csíkos sablon duplán.
 *
 *   cp_sb_img   crazyphoto.hu sablon- és mintakép, helyi gyorsítótárból (a canvas-exporthoz azonos domain kell)
 *   cp_sb_deal  egy deal adatai: az ügyfél választása (megjegyzés mező), a csatolt sablonkép linkje, dátum
 *   cp_sb_ai    egyedi keretgrafika OpenAI képszerkesztővel (csak a panelbe belépett csapatnak; a kulcs a szerveren marad)
 *   cp_sb_own_save / cp_sb_own_upd / cp_sb_own_del   a jól sikerült keret mentése / beállításai / törlése az „Egyedi” kategóriában
 *
 * Betöltő: a crazy-panel.php végén egyetlen require_once. Front-end: assets/sablon.js + assets/sablon.css
 */

if (!defined('ABSPATH')) { exit; }

define('CP_SB_SRC', 'https://crazyphoto.hu/wp-content/uploads/2026/10/');
define('CP_SB_F_IMG', 'textCustomField19'); /* Salesmate: „Sablon kép (link)” – a crazyphoto.hu ajánlatkérő tölti */
define('CP_SB_TTL', 7 * DAY_IN_SECONDS);   /* ennyi idő után újra letöltjük a crazyphoto.hu-ról (hiba esetén marad a régi) */

/* a panel konfigurációjába (window.CPX.sb) */
function cp_sb_cfg() {
	return array(
		'img' => admin_url('admin-ajax.php') . '?action=cp_sb_img&f=',
		'src' => CP_SB_SRC,
		'ai'  => cp_sb_ai_key() !== '',
		'own' => cp_sb_own_list(),
	);
}

/* a panel oldalába: CSS + JS, a fájl módosítási idejével (frissítés után ne a böngésző régi példánya fusson) */
function cp_sb_assets() {
	foreach (array('css', 'js') as $t) {
		$f = __DIR__ . '/assets/sablon.' . $t;
		$u = esc_url(plugins_url('assets/sablon.' . $t, __FILE__) . '?v=' . CP_PANEL_VER . '.' . (is_file($f) ? filemtime($f) : 0));
		echo $t === 'css' ? '<link rel="stylesheet" href="' . $u . '">' : '<script src="' . $u . '"></script>';
	}
}

function cp_sb_dir() {
	$u = wp_upload_dir(null, false);
	$d = wp_normalize_path(trailingslashit($u['basedir']) . 'crazy-sablon');
	if (!is_dir($d)) {
		wp_mkdir_p($d);
		@file_put_contents($d . '/index.php', "<?php // Silence is golden.\n");
	}
	return $d;
}

function cp_sb_is_img($b) {
	$b = (string) $b;
	return (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP')
		|| substr($b, 0, 8) === "\x89PNG\r\n\x1a\n"
		|| substr($b, 0, 3) === "\xFF\xD8\xFF";
}

function cp_sb_mime($f) {
	if (preg_match('/\.png$/i', $f)) { return 'image/png'; }
	if (preg_match('/\.jpe?g$/i', $f)) { return 'image/jpeg'; }
	return 'image/webp';
}

function cp_sb_send($f, $path = '', $body = null) {
	if (function_exists('header_remove')) { header_remove('Pragma'); header_remove('Expires'); }
	header('Content-Type: ' . cp_sb_mime($f));
	header('Cache-Control: private, max-age=604800');
	header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 604800) . ' GMT');
	header('X-Content-Type-Options: nosniff');
	if ($body === null) {
		header('Content-Length: ' . filesize($path));
		readfile($path);
	} else {
		header('Content-Length: ' . strlen($body));
		echo $body; // phpcs:ignore -- képfájl
	}
	exit;
}

/* GET admin-ajax.php?action=cp_sb_img&f=sablon-leaves-1x.webp – csak bejelentkezett panelnek, csak sablon-/mintaképek */
add_action('wp_ajax_nopriv_cp_sb_img', 'cp_sb_ajax_img');
add_action('wp_ajax_cp_sb_img', 'cp_sb_ajax_img');
function cp_sb_ajax_img() {
	if (!cp_panel_authed()) { status_header(403); exit; }
	$f = isset($_GET['f']) ? (string) wp_unslash($_GET['f']) : '';
	if (!preg_match('/^(sablon|minta|egyedi)-[a-z0-9-]{1,80}\.(webp|png|jpe?g)$/', $f)) { status_header(400); exit; }
	$path = cp_sb_dir() . '/' . $f;
	$have = is_file($path) && filesize($path) > 100;
	if (strpos($f, 'egyedi-') === 0) { /* AI-val készült egyedi keret: csak helyben létezik */
		if ($have) { cp_sb_send($f, $path); }
		nocache_headers();
		status_header(404);
		exit;
	}
	if ($have && (time() - filemtime($path)) < CP_SB_TTL) { cp_sb_send($f, $path); }

	$r = wp_remote_get(CP_SB_SRC . $f, array('timeout' => 25, 'redirection' => 2));
	$code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
	$body = $code === 200 ? (string) wp_remote_retrieve_body($r) : '';
	if ($body === '' || !cp_sb_is_img($body)) {
		if ($have) { @touch($path); cp_sb_send($f, $path); } /* a crazyphoto.hu most nem érhető el: marad a régi */
		nocache_headers();
		status_header($code === 404 ? 404 : 502);
		exit;
	}
	$tmp = $path . '.' . wp_generate_password(6, false) . '.tmp';
	if (@file_put_contents($tmp, $body) !== false && @rename($tmp, $path)) {
		clearstatcache(true, $path);
		cp_sb_send($f, $path);
	}
	@unlink($tmp);
	cp_sb_send($f, '', $body); /* nem írható a mappa: kiszolgáljuk közvetlenül */
}

/* egy deal adatai a sablonhoz (a panel közös belépésével és CSRF-kulcsával) */
add_action('wp_ajax_nopriv_cp_sb_deal', 'cp_sb_ajax_deal');
add_action('wp_ajax_cp_sb_deal', 'cp_sb_ajax_deal');
function cp_sb_ajax_deal() {
	cp_el_auth();
	$id = isset($_REQUEST['id']) ? preg_replace('/[^0-9]/', '', (string) wp_unslash($_REQUEST['id'])) : '';
	if ($id === '' || strlen($id) > 20) { cp_el_err('Hibás deal azonosító.'); }
	$d = cp_sm_get_deal($id);
	if (is_wp_error($d)) { cp_el_err($d->get_error_message(), 502); }
	$o = cp_panel_opts();
	$img = trim(cp_panel_str(cp_panel_get($d, CP_SB_F_IMG)));
	if ($img !== '' && !preg_match('#^https://#i', $img)) { $img = ''; }
	$pc = cp_panel_get($d, 'primaryContact');
	$contact = '';
	if (is_array($pc)) {
		$contact = trim(cp_panel_str(isset($pc['name']) ? $pc['name'] : ''));
		if ($contact === '') { $contact = trim((isset($pc['lastName']) ? cp_panel_str($pc['lastName']) : '') . ' ' . (isset($pc['firstName']) ? cp_panel_str($pc['firstName']) : '')); }
	}
	wp_send_json_success(array(
		'id'      => $id,
		'title'   => cp_panel_str(cp_panel_get($d, 'title')),
		'date'    => $o['f_date'] !== '' ? cp_panel_norm_date(cp_panel_get($d, $o['f_date'])) : '',
		'note'    => $o['f_note'] !== '' ? cp_panel_str(cp_panel_get($d, $o['f_note'])) : '',
		'img'     => $img !== '' ? esc_url_raw($img) : '',
		'contact' => $contact,
	));
}

/* ---------------------------------------------------------------------
 * Egyedi keret AI-val (OpenAI képszerkesztés maszkkal)
 * A panel álló 1024×1536-os vázlatot küld (szürke téglalap = fotóablak) és egy maszkot
 * (átlátszó = ezt festheti az AI, átlátszatlan = fotóablak, maradjon). Az AI csak a díszítő
 * hátteret / keretet készíti; a lyukasztást, a feliratokat és a logót a panel teszi rá, mint eddig.
 * A kulcs csak a szerveren van, a hívás csak a panelbe belépett csapatnak (közös jelszó + CSRF) megy.
 * ------------------------------------------------------------------- */

define('CP_SB_OWN', 'cp_sb_own');   /* mentett egyedi stílusok listája */
define('CP_SB_OWN_MAX', 60);
define('CP_SB_LAYOUTS', 'csik3,1x,2x,4x');

function cp_sb_ai_key() {
	$o = cp_panel_opts();
	return isset($o['openai_key']) ? trim((string) $o['openai_key']) : '';
}

function cp_sb_own_list() {
	$l = get_option(CP_SB_OWN, array());
	return is_array($l) ? array_values($l) : array();
}

/* data:image/png;base64,... a POST-ból → bináris PNG (méretkorláttal), hibánál '' */
function cp_sb_png_in($key, $max) {
	$v = isset($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : '';
	if (strpos($v, 'data:image/png;base64,') === 0) { $v = substr($v, 22); }
	if ($v === '' || strlen($v) > $max * 4 / 3 + 8) { return ''; }
	$b = base64_decode($v, true);
	return ($b !== false && substr($b, 0, 8) === "\x89PNG\r\n\x1a\n") ? $b : '';
}

function cp_sb_png_size($b) {
	if (strlen($b) < 24 || substr($b, 12, 4) !== 'IHDR') { return array(0, 0); }
	$u = unpack('Nw/Nh', substr($b, 16, 8));
	return array((int) $u['w'], (int) $u['h']);
}

function cp_sb_req_layout() {
	$l = isset($_POST['layout']) ? sanitize_key(wp_unslash($_POST['layout'])) : '';
	return in_array($l, explode(',', CP_SB_LAYOUTS), true) ? $l : '1x';
}

function cp_sb_ai_layout_hint($lay) {
	$h = array(
		'1x'    => 'one large photo window in the upper part',
		'2x'    => 'two photo windows stacked vertically',
		'4x'    => 'four photo windows in a 2x2 grid',
		'csik3' => 'two identical narrow vertical photo strips side by side, each with three photo windows; the sheet is cut in half vertically, so decorate both halves the same way and keep the vertical centre line clean',
	);
	return isset($h[$lay]) ? $h[$lay] : $h['1x'];
}

add_action('wp_ajax_nopriv_cp_sb_ai', 'cp_sb_ajax_ai');
add_action('wp_ajax_cp_sb_ai', 'cp_sb_ajax_ai');
function cp_sb_ajax_ai() {
	cp_el_auth();
	$key = cp_sb_ai_key();
	if ($key === '') { cp_el_err('Az OpenAI kulcs nincs beállítva (WordPress admin → Beállítások → Foglaltsági panel).'); }
	$o = cp_panel_opts();
	$prompt = isset($_POST['prompt']) ? trim(sanitize_textarea_field(wp_unslash($_POST['prompt']))) : '';
	if (mb_strlen($prompt) < 3) { cp_el_err('Írd le, milyen keretet szeretnél.'); }
	$prompt = mb_substr($prompt, 0, 1200);
	$lay = cp_sb_req_layout();
	$img = cp_sb_png_in('image', 4 * MB_IN_BYTES);
	$mask = cp_sb_png_in('mask', 4 * MB_IN_BYTES);
	if ($img === '' || $mask === '') { cp_el_err('Hiányzó vagy hibás vázlat / maszk.'); }
	if (cp_sb_png_size($img) !== array(1024, 1536) || cp_sb_png_size($mask) !== array(1024, 1536)) { cp_el_err('A vázlat és a maszk 1024×1536-os PNG legyen.'); }

	/* napi keret (költségvédelem) */
	$lim = isset($o['ai_daily']) ? max(0, (int) $o['ai_daily']) : 30;
	$ck = 'cp_sb_ai_n_' . wp_date('Ymd');
	$n = (int) get_transient($ck);
	if ($lim > 0 && $n >= $lim) { cp_el_err('Elérted a mai AI-generálási keretet (' . $lim . ' db). Az admin beállításokban emelhető.', 429); }
	set_transient($ck, $n + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS);

	$full = 'Design only the decorative background / frame artwork of a printed photo-booth template, portrait orientation (4x6 inch print, 2:3). '
		. 'Layout: ' . cp_sb_ai_layout_hint($lay) . '. '
		. 'The flat grey rectangles outlined with a thin dark line are the photo windows. They are protected by the mask: keep them exactly where they are, '
		. 'same size and position, empty, and never draw inside or across them. Compose the whole artwork around these exact windows: '
		. 'every window must get its own clearly visible, emphasised frame that follows its outline precisely (for example an elegant thin gold or ornamental border hugging the window edge, with decorative corners), '
		. 'and all decoration must start from the window edges outward, never overlapping them. Leave a clean, even margin between neighbouring windows. '
		. 'Keep the area below the photo windows (the bottom part of the image) calm, light and low-detail, with decoration only near its edges, '
		. 'because names, a date and possibly a logo will be added there later. '
		. 'Absolutely no text, letters, numbers, monograms, logos, watermarks, people or faces. Print quality, crisp, flat front view, no mockup, no perspective, no shadows of a physical card. '
		. 'Style requested by the designer (may be in Hungarian): ' . $prompt;

	$model = !empty($o['ai_model']) ? preg_replace('/[^A-Za-z0-9._-]/', '', (string) $o['ai_model']) : 'gpt-image-1';
	$quality = isset($o['ai_quality']) && in_array($o['ai_quality'], array('low', 'medium', 'high', 'auto'), true) ? $o['ai_quality'] : 'medium';
	$bd = 'cpsb' . wp_generate_password(24, false, false);
	$body = '';
	foreach (array('model' => $model, 'prompt' => $full, 'size' => '1024x1536', 'quality' => $quality, 'n' => '1') as $k => $v) {
		$body .= '--' . $bd . "\r\nContent-Disposition: form-data; name=\"" . $k . "\"\r\n\r\n" . $v . "\r\n";
	}
	foreach (array('image' => $img, 'mask' => $mask) as $k => $v) {
		$body .= '--' . $bd . "\r\nContent-Disposition: form-data; name=\"" . $k . '"; filename="' . $k . ".png\"\r\nContent-Type: image/png\r\n\r\n" . $v . "\r\n";
	}
	$body .= '--' . $bd . "--\r\n";

	if (function_exists('set_time_limit')) { @set_time_limit(380); }
	/* input_fidelity=high: a vázlat elrendezését (fotóablakok helyét) jobban megtartja; ha a modell nem ismeri, nélküle újra */
	$fid = '--' . $bd . "\r\nContent-Disposition: form-data; name=\"input_fidelity\"\r\n\r\nhigh\r\n";
	foreach (array($fid . $body, $body) as $try => $b) {
		$r = wp_remote_post('https://api.openai.com/v1/images/edits', array(
			'timeout' => 180,
			'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'multipart/form-data; boundary=' . $bd),
			'body'    => $b,
		));
		if (is_wp_error($r)) { cp_el_err('Az OpenAI nem válaszolt: ' . $r->get_error_message(), 502); }
		$code = (int) wp_remote_retrieve_response_code($r);
		$j = json_decode((string) wp_remote_retrieve_body($r), true);
		if ($try === 0 && $code === 400 && is_array($j) && isset($j['error']['message']) && stripos((string) $j['error']['message'], 'fidelity') !== false) { continue; }
		break;
	}
	if ($code !== 200 || empty($j['data'][0]['b64_json'])) {
		$m = is_array($j) && isset($j['error']['message']) ? (string) $j['error']['message'] : 'HTTP ' . $code;
		cp_el_err('OpenAI hiba: ' . mb_substr($m, 0, 300), 502);
	}
	$out = base64_decode((string) $j['data'][0]['b64_json'], true);
	if ($out === false || !cp_sb_is_img($out)) { cp_el_err('Az OpenAI nem adott vissza képet.', 502); }
	$mime = substr($out, 0, 4) === "\x89PNG" ? 'image/png' : (substr($out, 0, 3) === "\xFF\xD8\xFF" ? 'image/jpeg' : 'image/webp');
	wp_send_json_success(array(
		'img'  => 'data:' . $mime . ';base64,' . base64_encode($out),
		'left' => $lim > 0 ? max(0, $lim - $n - 1) : -1,
	));
}

/* a kész (a panelen már lyukasztott) keret mentése új stílusként az „Egyedi” kategóriába */
add_action('wp_ajax_nopriv_cp_sb_own_save', 'cp_sb_ajax_own_save');
add_action('wp_ajax_cp_sb_own_save', 'cp_sb_ajax_own_save');
function cp_sb_ajax_own_save() {
	cp_el_auth();
	$name = isset($_POST['name']) ? trim(sanitize_text_field(wp_unslash($_POST['name']))) : '';
	if ($name === '') { cp_el_err('Adj nevet a stílusnak.'); }
	$name = mb_substr($name, 0, 40);
	$png = cp_sb_png_in('png', 12 * MB_IN_BYTES);
	$th = cp_sb_png_in('thumb', MB_IN_BYTES);
	if ($png === '' || $th === '') { cp_el_err('Hiányzó vagy hibás kép.'); }
	if (cp_sb_png_size($png) !== array(1200, 1800)) { cp_el_err('A keret 1200×1800-as PNG legyen.'); }
	$list = cp_sb_own_list();
	if (count($list) >= CP_SB_OWN_MAX) { cp_el_err('Legfeljebb ' . CP_SB_OWN_MAX . ' egyedi stílus menthető – törölj egy régit.'); }
	$set = isset($_POST['set']) ? sanitize_key(wp_unslash($_POST['set'])) : 'wed';
	if (!in_array($set, array('wed', 'corp', 'bday'), true)) { $set = 'wed'; }
	$font = isset($_POST['font']) ? sanitize_key(wp_unslash($_POST['font'])) : 'serif';
	if (!in_array($font, array('serif', 'script', 'sans'), true)) { $font = 'serif'; }
	$col = isset($_POST['color']) ? sanitize_hex_color(wp_unslash($_POST['color'])) : '';
	list($wf, $wc) = cp_sb_req_wf();
	foreach ($list as $e) {
		if (isset($e['name']) && mb_strtolower($e['name']) === mb_strtolower($name)) { cp_el_err('Már van ilyen nevű egyedi stílus – adj más nevet.'); }
	}
	$id = strtolower(wp_generate_password(10, false, false));
	$slug = substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(remove_accents($name))), '-'), 0, 30);
	$base = 'egyedi-' . ($slug !== '' ? $slug . '-' : '') . $id;
	$d = cp_sb_dir();
	if (@file_put_contents($d . '/' . $base . '.png', $png) === false || @file_put_contents($d . '/' . $base . '-thumb.png', $th) === false) {
		@unlink($d . '/' . $base . '.png');
		cp_el_err('A képet nem sikerült elmenteni (feltöltési mappa).', 500);
	}
	$e = array(
		'id'     => $id,
		'name'   => $name,
		'set'    => $set,
		'layout' => cp_sb_req_layout(),
		'font'   => $font,
		'color'  => $col ? $col : '#4A4237',
		'wf'     => $wf,
		'wc'     => $wc,
		'file'   => $base . '.png',
		'thumb'  => $base . '-thumb.png',
		'prompt' => isset($_POST['prompt']) ? mb_substr(sanitize_textarea_field(wp_unslash($_POST['prompt'])), 0, 1200) : '',
		'at'     => time(),
	);
	$list[] = $e;
	update_option(CP_SB_OWN, $list, false);
	wp_send_json_success($e);
}

/* ablakkeret-stílus a kérésből: [stílus, szín] */
function cp_sb_req_wf() {
	$wf = isset($_POST['wf']) ? sanitize_key(wp_unslash($_POST['wf'])) : 'none';
	if (!in_array($wf, array('none', 'gold', 'goldmat', 'line', 'double', 'mat'), true)) { $wf = 'none'; }
	$wc = isset($_POST['wc']) ? sanitize_hex_color(wp_unslash($_POST['wc'])) : '';
	return array($wf, $wc ? $wc : '#3A3A3A');
}

/* mentett egyedi stílus felirat- és ablakkeret-beállításainak frissítése (a kép nem változik) */
add_action('wp_ajax_nopriv_cp_sb_own_upd', 'cp_sb_ajax_own_upd');
add_action('wp_ajax_cp_sb_own_upd', 'cp_sb_ajax_own_upd');
function cp_sb_ajax_own_upd() {
	cp_el_auth();
	$id = isset($_POST['id']) ? preg_replace('/[^a-z0-9]/', '', (string) wp_unslash($_POST['id'])) : '';
	$list = cp_sb_own_list();
	foreach ($list as $i => $e) {
		if ($id === '' || !isset($e['id']) || $e['id'] !== $id) { continue; }
		$set = isset($_POST['set']) ? sanitize_key(wp_unslash($_POST['set'])) : $e['set'];
		if (in_array($set, array('wed', 'corp', 'bday'), true)) { $list[$i]['set'] = $set; }
		$font = isset($_POST['font']) ? sanitize_key(wp_unslash($_POST['font'])) : $e['font'];
		if (in_array($font, array('serif', 'script', 'sans'), true)) { $list[$i]['font'] = $font; }
		$col = isset($_POST['color']) ? sanitize_hex_color(wp_unslash($_POST['color'])) : '';
		if ($col) { $list[$i]['color'] = $col; }
		list($list[$i]['wf'], $list[$i]['wc']) = cp_sb_req_wf();
		update_option(CP_SB_OWN, $list, false);
		wp_send_json_success($list[$i]);
	}
	cp_el_err('Ez a stílus már nincs meg.');
}

add_action('wp_ajax_nopriv_cp_sb_own_del', 'cp_sb_ajax_own_del');
add_action('wp_ajax_cp_sb_own_del', 'cp_sb_ajax_own_del');
function cp_sb_ajax_own_del() {
	cp_el_auth();
	$id = isset($_POST['id']) ? preg_replace('/[^a-z0-9]/', '', (string) wp_unslash($_POST['id'])) : '';
	$keep = array();
	$found = false;
	foreach (cp_sb_own_list() as $e) {
		if ($id !== '' && isset($e['id']) && $e['id'] === $id) {
			$found = true;
			foreach (array('file', 'thumb') as $k) {
				if (!empty($e[$k]) && preg_match('/^egyedi-[a-z0-9-]{1,80}\.png$/', $e[$k])) { @unlink(cp_sb_dir() . '/' . $e[$k]); }
			}
			continue;
		}
		$keep[] = $e;
	}
	if (!$found) { cp_el_err('Ez a stílus már nincs meg.'); }
	update_option(CP_SB_OWN, $keep, false);
	wp_send_json_success(array('id' => $id));
}
