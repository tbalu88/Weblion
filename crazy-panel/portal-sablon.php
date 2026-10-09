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
	foreach (array('logo', 'preview') as $k) {
		if (!empty($val[$k]) && preg_match('/^p\d+-[a-z0-9]{12}-(logo|prev)\.(png|jpg)$/', $val[$k])) { @unlink(cp_portal_sb_dir() . '/' . $val[$k]); }
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
	foreach (cp_sb_own_list() as $e) { if (isset($e['cat']) && in_array($e['cat'], array('wed', 'party'), true)) { $own[] = $e; } }
	$ajax = '/wp-admin/admin-ajax.php';
	$cfg = array(
		'ajax' => $ajax,
		't' => '',
		'sb' => array('img' => $ajax . '?action=cp_sb_img&f=', 'src' => CP_SB_SRC, 'ai' => false, 'own' => $own, 'client' => true),
	);
	$ps = array(
		'rest' => wp_make_link_relative(rest_url('crazy/v1/portal/sablon')),
		'nonce' => cp_portal_nonce($tok),
		'back' => cp_portal_url('/rendezvenyem'),
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
	echo '</head><body class="cpp-sbpage">' . cp_portal_page_top(true);
	echo '<main class="cpx-main cpp-sbmain"><div class="cpp-sbhead"><a href="' . esc_url($ps['back']) . '">← Vissza az adatlaphoz</a><h1>Fotósablon</h1>';
	echo '<p>Válassz sablont, írd bele a feliratot, és ha kell, töltsd fel a logótokat. A „Sablon mentése” gombbal elküldöd nekünk – a végleges, nyomtatható változatot a csapatunk készíti el belőle.</p>';
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
	if (!is_string($s) || !preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#', $s, $m) || !in_array($m[1], $types, true)) { return null; }
	$b = base64_decode($m[2], true);
	if ($b === false || strlen($b) > $max || !cp_sb_is_img($b)) { return null; }
	return array($m[1] === 'png' ? 'png' : 'jpg', $b);
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
	if ($old) {
		$del = $old;
		if ($val['logo'] === (isset($old['logo']) ? $old['logo'] : '')) { $del['logo'] = ''; }
		cp_portal_sb_files_delete($del);
	}
	cp_portal_answer_put($portal->id, 'photo_template', $val, 'ugyfel');
	cp_portal_log_add($portal->id, 'photo_template', $old ? $old['name'] : null, $val['name'], 'ugyfel');
	$extra = array();
	$di = cp_portal_deadline_info($portal);
	if ($di && time() >= $di['deadline']) { $extra['changed_late_at'] = cp_portal_db_now(); }
	cp_portal_recompute($portal, $extra);
	return array('ok' => true, 'at' => $val['at'], 'preview' => cp_portal_sb_file_url($val['preview']));
}

/* ------------------------------------------------------------ fájlok kiszolgálása */

add_action('wp_ajax_nopriv_cp_portal_sb_file', 'cp_portal_ajax_sb_file');
add_action('wp_ajax_cp_portal_sb_file', 'cp_portal_ajax_sb_file');
function cp_portal_ajax_sb_file() {
	$f = isset($_GET['f']) ? (string) wp_unslash($_GET['f']) : '';
	if (!preg_match('/^p(\d+)-[a-z0-9]{12}-(logo|prev)\.(png|jpg)$/', $f, $m)) { status_header(400); exit; }
	$ok = cp_panel_authed();
	if (!$ok) {
		$s = cp_portal_session();
		$ok = $s && (int) $s['portal']->id === (int) $m[1];
	}
	if (!$ok) { status_header(403); exit; }
	$path = cp_portal_sb_dir() . '/' . $f;
	if (!is_file($path)) { status_header(404); exit; }
	cp_sb_send($f, $path);
}
