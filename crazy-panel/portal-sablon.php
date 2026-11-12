<?php
/* Ügyfélportál – fotósablon
 * A panel sablonkészítője (sablon.php + assets/sablon.js) ügyfél-módban, AI nélkül: az ügyfél kiválaszt egy sablont,
 * kitölti a feliratot, feltöltheti a logót, és elmenti. Mentéskor a beállítások (snapshot), a logó és egy JPG-előnézet
 * kerül a szerverre; a csapat a panel portál-részletezőjében látja, és egy gombbal megnyitja a Sablonkészítőben,
 * ahonnan a végleges, lyukasztott PNG készül (ahogy eddig).
 *   /rendezvenyem/sablon                  a sablonkészítő oldal (portál-munkamenettel)
 *   POST /wp-json/crazy/v1/portal/sablon  mentés
 *   admin-ajax: cp_portal_sb_file        a mentett logó / előnézet (a saját portálnak vagy a belépett panelnek) */

if (!defined('ABSPATH')) { exit; }

function cp_portal_sb_dir() {
	$d = cp_sb_dir() . '/portal';
	if (!is_dir($d)) {
		wp_mkdir_p($d);
		@file_put_contents($d . '/index.php', "<?php // Silence is golden.\n");
		@file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
	}
	return $d;
}

function cp_portal_sb_file_url($f) {
	return '/wp-admin/admin-ajax.php?action=cp_portal_sb_file&f=' . rawurlencode($f);
}

function cp_portal_sb_files_delete($val) {
	if (!is_array($val)) { return; }
	foreach (array('logo', 'preview', 'file', 'png') as $k) {
		if (!empty($val[$k]) && preg_match('/^p\d+-[a-z0-9]{12}-(logo|prev|clogo|print)\.(png|jpg)$/', $val[$k])) { @unlink(cp_portal_sb_dir() . '/' . $val[$k]); }
	}
}

/* a portál adatlapján: állapot + gomb a sablonkészítőhöz */
function cp_portal_sablon_card($portal, $val, $locked) {
	$h = '<div class="cpp-sb">';
	if ($val && !empty($val['preview'])) {
		$h .= '<img class="cpp-sb-prev" src="' . esc_url(cp_portal_sb_file_url($val['preview'])) . '" alt="A mentett fotósablon előnézete">';
		$h .= '<p class="cpp-note">Elmentve: ' . esc_html(wp_date('Y.m.d. H:i', (int) $val['at'], cp_portal_tz())) . (!empty($val['name']) ? ' · ' . esc_html($val['name']) : '') . '</p>';
	} else {
		$h .= '<p class="cpp-help">A fotók ebbe a keretbe kerülnek nyomtatáskor. Válassz sablont, írd bele a neveket / a dátumot, és ha kell, töltsd fel a logótokat. Ha most nem érsz rá, később is megteheted – vagy a csapatunk elkészíti a megbeszéltek alapján.</p>';
	}
	if (!$locked) {
		$h .= '<a class="cpp-btn ghost" href="' . esc_url(cp_portal_url('/rendezvenyem/sablon')) . '">' . ($val ? 'Sablon módosítása' : 'Sablon készítése') . '</a>';
	}
	return $h . '</div>';
}

/* ------------------------------------------------------------ a sablonkészítő oldal */

function cp_portal_render_sablon_page($portal, $tok) {
	$answers = cp_portal_answers($portal->id);
	$val = isset($answers['photo_template']) && is_array($answers['photo_template']) ? $answers['photo_template'] : null;
	$locked = cp_portal_locked($portal);
	$sm = $portal->sm;
	$own = array();
	/* v2.9.58: az ügyfélnek csak a rajzoláshoz szükséges mezők mennek ki (a belső AI-prompt nem) */
	$okk = array_flip(array('id', 'name', 'set', 'layout', 'slots', 'or', 'cat', 'font', 'color', 'wf', 'wc', 'file', 'thumb'));
	foreach (cp_sb_own_list() as $e) { if (isset($e['cat']) && in_array($e['cat'], array('wed', 'party'), true)) { $own[] = array_intersect_key($e, $okk); } }
	$ajax = '/wp-admin/admin-ajax.php';
	$cfg = array(
		'ajax' => $ajax,
		't' => '',
		'sb' => array('img' => $ajax . '?action=cp_sb_img&f=', 'src' => CP_SB_SRC, 'ai' => false, 'own' => $own, 'client' => true),
	);
	$ps = array(
		'rest' => wp_make_link_relative(rest_url('crazy/v1/portal/sablon')),
		'nonce' => cp_portal_nonce($tok),
		'back' => cp_portal_url('/rendezvenyem') . '#f-photo_template',
		'locked' => $locked,
		'deal' => array('id' => (string) $portal->deal_id, 'title' => isset($sm['who']) ? $sm['who'] : '', 'date' => (string) $portal->event_date, 'note' => ''),
		'saved' => $val && !empty($val['snap']) ? $val['snap'] : null,
		'logo' => $val && !empty($val['logo']) ? cp_portal_sb_file_url($val['logo']) : '',
	);
	$v = CP_PANEL_VER . '.' . CP_PORTAL_VER;
	echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">';
	echo '<title>Fotósablon – EventCrazy</title>';
	echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
	echo '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">';
	foreach (array('panel.css', 'sablon.css', 'portal.css') as $c) {
		$f = __DIR__ . '/assets/' . $c;
		echo '<link rel="stylesheet" href="' . esc_url(plugins_url('assets/' . $c, CP_PORTAL_MAIN)) . '?v=' . $v . '.' . (is_file($f) ? filemtime($f) : 0) . '">';
	}
	echo '</head><body class="cpp-sbpage">' . cp_portal_page_top(false, $ps['back']);   /* v2.9.230: a szerkesztőből nincs Kilépés, csak „Vissza az adatlaphoz” */
	echo '<main class="cpx-main cpp-sbmain"><div class="cpp-sbhead"><h1>Fotósablon</h1>';
	echo '<p>Válassz sablont, írd bele a feliratot, és ha kell, töltsd fel a logótokat. Amit beállítasz, folyamatosan megőrizzük (ebben a böngészőben), így bármikor visszatérhetsz hozzá. A „Sablon mentése” gombbal elküldöd nekünk – a végleges, nyomtatható változatot a csapatunk készíti el belőle.</p>';
	if ($locked) { echo '<p class="cpp-lock">A határidő lejárt, a sablon már nem módosítható. Változás esetén írj nekünk.</p>'; }
	echo '</div><div id="cpp-sbroot"></div></main>';
	echo '<script>window.CPX=' . wp_json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';window.CPPS=' . wp_json_encode($ps, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
	$f = __DIR__ . '/assets/sablon.js';
	echo '<script src="' . esc_url(plugins_url('assets/sablon.js', CP_PORTAL_MAIN)) . '?v=' . $v . '.' . (is_file($f) ? filemtime($f) : 0) . '"></script>';
	$f = __DIR__ . '/assets/portal-sablon.js';
	echo '<script src="' . esc_url(plugins_url('assets/portal-sablon.js', CP_PORTAL_MAIN)) . '?v=' . $v . '.' . (is_file($f) ? filemtime($f) : 0) . '"></script>';
	echo '</body></html>';
}

/* ------------------------------------------------------------ mentés (REST) */

function cp_portal_sb_dataurl($s, $types, $max) {
	/* v2.9.5: regex nélkül (a több MB-os PNG-nél a reguláris kifejezés elakadhat) */
	if (!is_string($s) || strncmp($s, 'data:image/', 11) !== 0) { return null; }
	$c = strpos($s, ';base64,');
	if ($c === false || $c > 30) { return null; }
	$m = array(1 => substr($s, 11, $c - 11));
	if (!in_array($m[1], array('png', 'jpeg'), true) || !in_array($m[1], $types, true)) { return null; }
	$b = base64_decode(substr($s, $c + 8), true);
	if ($b === false || strlen($b) > $max || !cp_sb_is_img($b)) { return null; }
	return array($m[1] === 'png' ? 'png' : 'jpg', $b);
}

/* v2.9.231: a deal Fájljai közé kerülő sablon neve: „2026.11.12. - Megrendelő neve.png” (rendezvény dátuma - megrendelő) */
function cp_portal_sb_filename($portal, $answers, $ext) {
	$d = isset($answers['event_date']) && is_string($answers['event_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $answers['event_date']) ? $answers['event_date'] : (string) $portal->event_date;
	$date = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? $m[1] . '.' . $m[2] . '.' . $m[3] . '.' : '';
	$type = isset($answers['client_type']) ? $answers['client_type'] : '';
	$cp = isset($answers['client_private']) && is_array($answers['client_private']) ? $answers['client_private'] : array();
	$cc = isset($answers['client_company']) && is_array($answers['client_company']) ? $answers['client_company'] : array();
	$name = $type === 'ceg' ? (isset($cc['company']) ? $cc['company'] : '') : (isset($cp['name']) ? $cp['name'] : '');
	if (trim((string) $name) === '') { $name = isset($portal->sm['who']) && $portal->sm['who'] !== '' ? $portal->sm['who'] : 'Deal ' . (int) $portal->deal_id; }
	/* fájlnévben tiltott jelek nélkül, de az ékezetek és a szóközök maradnak */
	$name = trim(preg_replace('/\s+/u', ' ', preg_replace('#[\\/:*?"<>|\x00-\x1F]+#u', ' ', (string) $name)));
	return mb_substr(($date !== '' ? $date . ' - ' : '') . $name, 0, 180) . '.' . $ext;
}

function cp_portal_rest_sablon_save(WP_REST_Request $req) {
	$s = cp_portal_session();
	$portal = $s['portal'];
	if (cp_portal_locked($portal)) {
		return new WP_REST_Response(array('ok' => false, 'message' => 'A határidő lejárt, a sablon már nem módosítható.'), 423);
	}
	$b = $req->get_json_params();
	$snap = is_array($b) && isset($b['snap']) && is_array($b['snap']) ? $b['snap'] : null;
	if (!$snap || strlen(wp_json_encode($snap)) > 20000 || empty($snap['d']) || !is_string($snap['d'])) {
		return new WP_REST_Response(array('ok' => false, 'message' => 'Hibás sablonadatok.'), 400);
	}
	$prev = cp_portal_sb_dataurl(isset($b['preview']) ? $b['preview'] : '', array('jpeg'), 3 * MB_IN_BYTES);
	if (!$prev) { return new WP_REST_Response(array('ok' => false, 'message' => 'Az előnézet nem készült el – próbáld újra.'), 400); }
	$answers = cp_portal_answers($portal->id);
	$old = isset($answers['photo_template']) && is_array($answers['photo_template']) ? $answers['photo_template'] : null;
	$dir = cp_portal_sb_dir();
	$base = 'p' . (int) $portal->id . '-' . strtolower(wp_generate_password(12, false, false));
	$val = array('snap' => $snap, 'name' => mb_substr((string) $snap['d'], 0, 80), 'at' => time(), 'logo' => '', 'preview' => $base . '-prev.jpg');
	$logo_in = isset($b['logo']) ? $b['logo'] : '';
	if ($logo_in === 'keep' && $old && !empty($old['logo'])) {
		$val['logo'] = $old['logo'];
	} elseif ($logo_in !== '' && $logo_in !== 'keep') {
		$lg = cp_portal_sb_dataurl($logo_in, array('png', 'jpeg'), 4 * MB_IN_BYTES);
		if (!$lg) { return new WP_REST_Response(array('ok' => false, 'message' => 'A logó nem menthető (PNG vagy JPG, legfeljebb 4 MB).'), 400); }
		$val['logo'] = $base . '-logo.' . $lg[0];
		if (@file_put_contents($dir . '/' . $val['logo'], $lg[1]) === false) { return new WP_REST_Response(array('ok' => false, 'message' => 'A logót nem sikerült elmenteni.'), 500); }
	}
	if (@file_put_contents($dir . '/' . $val['preview'], $prev[1]) === false) { return new WP_REST_Response(array('ok' => false, 'message' => 'Az előnézetet nem sikerült elmenteni.'), 500); }
	/* v2.9.5: a nyomtatható, lyukasztott PNG (4×6", átlátszó fotóablakok) – a böngésző készíti, ugyanúgy, mint a panel Sablonkészítője */
	$png = cp_portal_sb_dataurl(isset($b['png']) ? $b['png'] : '', array('png'), 15 * MB_IN_BYTES);
	if ($png) { $val['png'] = $base . '-print.png'; if (@file_put_contents($dir . '/' . $val['png'], $png[1]) === false) { unset($val['png']); } }
	/* v2.9.5: az előző változatok megmaradnak (az utolsó 5) – a dealen letölthetők; a régebbiek fájljai törlődnek */
	if ($old) {
		$hist = isset($answers['photo_template_hist']) && is_array($answers['photo_template_hist']) ? $answers['photo_template_hist'] : array();
		$o2 = $old; unset($o2['snap']);
		if ($val['logo'] !== '' && $val['logo'] === ($o2['logo'] ?? '')) { $o2['logo'] = ''; } /* a közös logó az aktuálisé */
		array_unshift($hist, $o2);
		foreach (array_slice($hist, 5) as $gone) { cp_portal_sb_files_delete($gone); }
		cp_portal_answer_put($portal->id, 'photo_template_hist', array_slice($hist, 0, 5), 'ugyfel');
	}
	cp_portal_answer_put($portal->id, 'photo_template', $val, 'ugyfel');
	/* v2.9.230: minden mentett változat másolata a deal Fájljai közé (a nyomtatható PNG, ha nincs, az előnézet) */
	if (function_exists('cp_df_add_copy')) {
		$cf = !empty($val['png']) ? $val['png'] : $val['preview'];
		cp_df_add_copy((int) $portal->deal_id, $dir . '/' . $cf, cp_portal_sb_filename($portal, cp_portal_answers($portal->id), pathinfo($cf, PATHINFO_EXTENSION)), 'Ügyfélportál');
	}
	if (!isset($answers['template_mode'])) { cp_portal_answer_put($portal->id, 'template_mode', 'own', 'ugyfel'); }
	cp_portal_log_add($portal->id, 'photo_template', $old ? $old['name'] : null, $val['name'], 'ugyfel');
	$extra = array();
	$di = cp_portal_deadline_info($portal);
	if ($di && time() >= $di['deadline']) { $extra['changed_late_at'] = cp_portal_db_now(); }
	cp_portal_recompute($portal, $extra);
	return array('ok' => true, 'at' => $val['at'], 'preview' => cp_portal_sb_file_url($val['preview']));
}

/* ------------------------------------------------------------ logó az egyedi sablonhoz (REST, multipart „logo” mező; „remove=1” törli)
 * Biztonság: csak PNG / JPG / WebP; a fájl tartalmát (nem a nevét / típusát) vizsgáljuk, majd a WP képszerkesztőjével
 * (Imagick / GD) újrakódoljuk – a mentett fájl egy frissen rajzolt kép, így beágyazott kód nem juthat át.
 * Véletlen név, rögzített .png / .jpg kiterjesztés, a mappa közvetlenül nem érhető el (.htaccess), csak a jogosultságot
 * ellenőrző admin-ajax végponton keresztül, nosniff fejléccel. */

function cp_portal_rest_template_logo(WP_REST_Request $req) {
	$s = cp_portal_session();
	$portal = $s['portal'];
	if (cp_portal_locked($portal)) {
		return new WP_REST_Response(array('ok' => false, 'message' => 'A határidő lejárt, a logó már nem módosítható.'), 423);
	}
	$answers = cp_portal_answers($portal->id);
	$old = isset($answers['template_logo']) && is_array($answers['template_logo']) ? $answers['template_logo'] : null;
	if ($req->get_param('remove')) {
		if ($old) {
			cp_portal_sb_files_delete($old);
			cp_portal_answer_put($portal->id, 'template_logo', null, 'ugyfel');
			cp_portal_log_add($portal->id, 'template_logo', $old['name'], null, 'ugyfel');
		}
		return array('ok' => true, 'removed' => true);
	}
	$files = $req->get_file_params();
	$up = isset($files['logo']) && is_array($files['logo']) ? $files['logo'] : null;
	$bad = function ($m, $code = 400) { return new WP_REST_Response(array('ok' => false, 'message' => $m), $code); };
	if (!$up || !empty($up['error']) || empty($up['tmp_name']) || !is_uploaded_file($up['tmp_name'])) { return $bad('A feltöltés nem sikerült – próbáld újra.'); }
	if ((int) $up['size'] > 10 * MB_IN_BYTES) { return $bad('A fájl túl nagy (legfeljebb 10 MB).'); }
	$head = (string) @file_get_contents($up['tmp_name'], false, null, 0, 16);
	$info = @getimagesize($up['tmp_name']);
	$types = array(IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'png');
	if (!cp_sb_is_img($head) || !$info || !isset($types[$info[2]]) || $info[0] < 16 || $info[1] < 16 || $info[0] * $info[1] > 60000000) {
		return $bad('Csak PNG, JPG vagy WebP képet tudunk fogadni.');
	}
	$ext = $types[$info[2]];
	$ed = wp_get_image_editor($up['tmp_name']);
	if (is_wp_error($ed)) { return $bad('A képet nem sikerült feldolgozni – próbáld PNG vagy JPG formátumban.'); }
	$sz = $ed->get_size();
	if ($sz['width'] > 3000 || $sz['height'] > 3000) { $ed->resize(3000, 3000, false); }
	$name = 'p' . (int) $portal->id . '-' . strtolower(wp_generate_password(12, false, false)) . '-clogo.' . $ext;
	$saved = $ed->save(cp_portal_sb_dir() . '/' . $name, $ext === 'png' ? 'image/png' : 'image/jpeg');
	if (is_wp_error($saved) || empty($saved['path']) || basename($saved['path']) !== $name) {
		if (!is_wp_error($saved) && !empty($saved['path'])) { @unlink($saved['path']); }
		return $bad('A logót nem sikerült elmenteni.', 500);
	}
	$orig = sanitize_file_name(wp_basename((string) $up['name']));
	$val = array('file' => $name, 'name' => mb_substr($orig !== '' ? $orig : 'logo.' . $ext, 0, 80), 'at' => time());
	if ($old) { cp_portal_sb_files_delete($old); }
	cp_portal_answer_put($portal->id, 'template_logo', $val, 'ugyfel');
	cp_portal_log_add($portal->id, 'template_logo', $old ? $old['name'] : null, $val['name'], 'ugyfel');
	$di = cp_portal_deadline_info($portal);
	cp_portal_recompute($portal, $di && time() >= $di['deadline'] ? array('changed_late_at' => cp_portal_db_now()) : array());
	return array('ok' => true, 'url' => cp_portal_sb_file_url($name), 'name' => $val['name']);
}

/* ------------------------------------------------------------ fájlok kiszolgálása */

add_action('wp_ajax_nopriv_cp_portal_sb_file', 'cp_portal_ajax_sb_file');
add_action('wp_ajax_cp_portal_sb_file', 'cp_portal_ajax_sb_file');
function cp_portal_ajax_sb_file() {
	$f = isset($_GET['f']) ? (string) wp_unslash($_GET['f']) : '';
	if (!preg_match('/^p(\d+)-[a-z0-9]{12}-(logo|prev|clogo|print)\.(png|jpg)$/', $f, $m)) { status_header(400); exit; }
	$ok = cp_panel_authed();
	if (!$ok) {
		$s = cp_portal_session();
		$ok = $s && (int) $s['portal']->id === (int) $m[1];
	}
	if (!$ok) { status_header(403); exit; }
	$path = cp_portal_sb_dir() . '/' . $f;
	if (!is_file($path)) { status_header(404); exit; }
	/* v2.9.5: letöltés beszédes fájlnévvel (?dl=Név) */
	if (!empty($_GET['dl'])) { $n = sanitize_file_name(remove_accents((string) wp_unslash($_GET['dl']))); if ($n !== '') { header('Content-Disposition: attachment; filename="' . $n . '"'); } }
	cp_sb_send($f, $path);
}
