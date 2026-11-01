<?php
/**
 * Crazy – médiatár-takarító (2026-11-01). Csak a Claude-híd kulcsával hívható: POST /wp-json/crazy-bridge/v1/cleanup {step}.
 * Lépések: scan (hivatkozások gyűjtése) → classify (melyik médiafájl nincs használva a publikált oldalon) → report (összesítő, fájlnevek nélkül)
 * → quarantine (lomtárba: uploads/_lomtar/<időbélyeg>/…, a médiatárban kukába; visszaállítható) → restore (visszaállítás) / purge (30 nap után végleges törlés).
 * Csak a médiatár havi mappái (uploads/ÉÉÉÉ/HH) érintettek; a panel mappái (crazy-mail, crazy-sign, crazy-sablon, cp-el…) soha.
 * Használtnak számít: bármely publikált tartalom (bejegyzés, oldal, Elementor-sablon, kit), annak meta-adatai, minden beállítás, a panel saját táblái,
 * a sablon- / mu-plugin- / crazy-panel-fájlok szövege – fájlnév és csatolmány-azonosító szerint is. Az utóbbi 14 napban feltöltött fájl kimarad.
 */
if (!defined('ABSPATH')) { exit; }

function cpt_dir() { $d = trailingslashit(wp_get_upload_dir()['basedir']) . '_lomtar'; if (!is_dir($d)) { wp_mkdir_p($d); @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); @file_put_contents($d . '/index.php', '<?php // csend'); } return $d; }
function cpt_get($k) { $f = cpt_dir() . '/' . $k . '.json'; return is_file($f) ? json_decode((string) file_get_contents($f), true) : null; }
function cpt_put($k, $v) { return (bool) file_put_contents(cpt_dir() . '/' . $k . '.json', wp_json_encode($v)); }

add_action('rest_api_init', function () {
	register_rest_route('crazy-bridge/v1', '/cleanup', array('methods' => 'POST', 'permission_callback' => function () { return function_exists('cb_can') ? cb_can() : new WP_Error('cpt', 'Nincs híd.', array('status' => 403)); }, 'callback' => 'cpt_route'));
});

function cpt_route(WP_REST_Request $req) {
	@set_time_limit(60);
	$step = (string) $req->get_param('step');
	try {
		switch ($step) {
			case 'scan': return cpt_scan();
			case 'classify': return cpt_classify();
			case 'report': return cpt_report();
			case 'verify': return cpt_verify((array) $req->get_param('refs'));
			case 'inlomtar': $st = preg_replace('/[^0-9-]/', '', (string) $req->get_param('stamp')); $b = cpt_dir() . '/' . $st . '/uploads/'; $h = array(); foreach ((array) $req->get_param('refs') as $r) { $r = str_replace('..', '', (string) $r); if ($r !== '' && is_file($b . $r)) { $h[] = $r; } } return array('lomtarban' => $h);
			case 'regen': return cpt_regen((string) $req->get_param('do'));
			case 'env': return cpt_env();
			case 'compress': return cpt_compress((string) $req->get_param('confirm'), (int) $req->get_param('max'));
			case 'compstat': $st = preg_replace('/[^0-9-]/', '', (string) $req->get_param('stamp')); $bk = cpt_dir() . '/' . $st . '-tomorites/uploads/'; $up = wp_normalize_path(wp_get_upload_dir()['basedir']); $a = 0; $b = 0; $n = 0; if (is_dir($bk)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bk, FilesystemIterator::SKIP_DOTS)) as $f) { if (!$f->isFile()) { continue; } $rel = substr(wp_normalize_path($f->getPathname()), strlen(wp_normalize_path($bk))); $n++; $a += $f->getSize(); $b += is_file($up . '/' . $rel) ? filesize($up . '/' . $rel) : 0; } } return array('tomoritett_kep' => $n, 'elotte_mb' => round($a / 1048576, 1), 'utana_mb' => round($b / 1048576, 1), 'megtakaritas_mb' => round(($a - $b) / 1048576, 1));
			case 'uncompress': return cpt_uncompress((string) $req->get_param('stamp'));
			case 'why': return cpt_why((int) $req->get_param('post'), (string) $req->get_param('file'));
			case 'quarantine': return cpt_quarantine((string) $req->get_param('confirm'));
			case 'restore': return cpt_restore((string) $req->get_param('stamp'));
			case 'purge': return cpt_purge((string) $req->get_param('stamp'));
			case 'status': return array('lomtar' => array_values(array_filter(array_map('basename', (array) glob(cpt_dir() . '/q-*.json')))), 'scan' => (cpt_get('refs') ?: array())['meta'] ?? null);
		}
	} catch (\Throwable $e) { return new WP_Error('cpt', $e->getMessage(), array('status' => 500)); }
	return new WP_Error('cpt', 'Ismeretlen lépés.', array('status' => 400));
}

/* a szövegből a feltöltések havi mappáira mutató hivatkozások (ÉÉÉÉ/HH/fájl), JSON-escape-elt perjelekkel is */
function cpt_refs_from($s, &$set) {
	if (!is_string($s) || $s === '' || stripos($s, 'uploads') === false) { return; }
	$s = str_replace(array('\\/', '%2F', '%2f'), '/', $s);
	if (preg_match_all('~uploads/(\d{4}/\d{2}/[^"\'\s<>()\[\]\\\\?#,;|*]+)~i', $s, $m)) { foreach ($m[1] as $r) { $set[strtolower(rawurldecode($r))] = 1; } }
}
function cpt_ids_from($s, &$ids) {
	if (!is_string($s) || $s === '') { return; }
	foreach (array('/wp-image-(\d+)/', '/"id"\s*:\s*"?(\d+)"?/', '/"ids?"\s*:\s*\[([\d,\s"]+)\]/', '/\bids?="([\d,\s]+)"/', '/attachment_id[=":\s]+(\d+)/', '/"image_id"\s*:\s*"?(\d+)/') as $re) {
		if (preg_match_all($re, $s, $m)) { foreach ($m[1] as $g) { foreach (preg_split('/[^\d]+/', $g) as $n) { if ($n !== '') { $ids[(int) $n] = 1; } } } }
	}
}

function cpt_scan() {
	global $wpdb; $t0 = microtime(true); $set = array(); $ids = array(); $done = array();
	$pub = "p.post_status IN ('publish','future') AND p.post_type NOT IN ('attachment','revision')";
	/* 1) publikált tartalom */
	$last = 0;
	while (true) {
		$rows = $wpdb->get_results($wpdb->prepare("SELECT p.ID, p.post_content FROM {$wpdb->posts} p WHERE $pub AND p.ID > %d ORDER BY p.ID LIMIT 300", $last), ARRAY_A);
		if (!$rows) { break; }
		foreach ($rows as $r) { cpt_refs_from($r['post_content'], $set); cpt_ids_from($r['post_content'], $ids); $last = (int) $r['ID']; }
	}
	$done[] = 'tartalom';
	/* 2) publikált tartalom meta-adatai (Elementor, kiemelt kép, SEO-képek…) */
	$last = 0;
	while (true) {
		$rows = $wpdb->get_results($wpdb->prepare("SELECT m.meta_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE $pub AND m.meta_id > %d ORDER BY m.meta_id LIMIT 1000", $last), ARRAY_A);
		if (!$rows) { break; }
		foreach ($rows as $r) {
			$last = (int) $r['meta_id']; $v = (string) $r['meta_value'];
			cpt_refs_from($v, $set);
			if ($r['meta_key'] === '_elementor_data' || $r['meta_key'] === '_elementor_page_settings') { cpt_ids_from($v, $ids); }
			if (ctype_digit($v) && preg_match('/thumbnail|image|logo|icon|og|gallery|bg|background|photo|picture|media/i', $r['meta_key'])) { $ids[(int) $v] = 1; }
			if (preg_match('/gallery|image|ids/i', $r['meta_key'])) { cpt_ids_from($v, $ids); }
		}
		if (microtime(true) - $t0 > 40) { throw new \Exception('időtúllépés a meta-adatoknál – nem fejeződött be'); }
	}
	$done[] = 'meta';
	/* 3) minden beállítás (logó, ikon, widgetek, panel-beállítások…) */
	$last = 0;
	while (true) {
		$rows = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d AND option_name NOT LIKE %s ORDER BY option_id LIMIT 500", $last, $wpdb->esc_like('_transient_') . '%'), ARRAY_A);
		if (!$rows) { break; }
		foreach ($rows as $r) {
			$last = (int) $r['option_id']; $v = (string) $r['option_value'];
			cpt_refs_from($v, $set);
			if (in_array($r['option_name'], array('site_icon', 'site_logo', 'page_on_front'), true) && ctype_digit($v)) { $ids[(int) $v] = 1; }
			if (preg_match('/^theme_mods_|elementor|logo|icon|image|widget/i', $r['option_name'])) { cpt_ids_from($v, $ids); if (preg_match_all('/"(?:custom_logo|site_logo|logo|image_id|attachment_id|header_image_data)";(?:i|s:\d+):"?(\d+)/', $v, $m)) { foreach ($m[1] as $n) { $ids[(int) $n] = 1; } } }
		}
	}
	$done[] = 'beállítások';
	/* 4) termmeta */
	foreach ((array) $wpdb->get_col("SELECT meta_value FROM {$wpdb->termmeta}") as $v) { cpt_refs_from($v, $set); if (ctype_digit((string) $v)) { $ids[(int) $v] = 1; } }
	$done[] = 'termmeta';
	/* 5) a panel saját táblái (sablonok, levelek, portál…) – szöveges oszlopok, ahol feltöltésre mutató cím van */
	$tabs = array_merge((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'crazy') . '%')), (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'cp') . '%')));
	$meta_tabs = array();
	foreach ($tabs as $tb) {
		$meta_tabs[] = $tb;
		$cols = (array) $wpdb->get_results("SHOW COLUMNS FROM `$tb`", ARRAY_A);
		foreach ($cols as $c) {
			if (!preg_match('/text|char|blob/i', $c['Type'])) { continue; }
			$cn = $c['Field'];
			foreach ((array) $wpdb->get_col("SELECT `$cn` FROM `$tb` WHERE `$cn` LIKE '%uploads%' LIMIT 20000") as $v) { cpt_refs_from($v, $set); }
			if (microtime(true) - $t0 > 50) { throw new \Exception('időtúllépés a panel tábláinál – nem fejeződött be'); }
		}
	}
	$done[] = 'panel-táblák (' . count($meta_tabs) . ')';
	/* 6) sablon-, mu-plugin- és crazy-panel fájlok szövege */
	foreach (array(get_theme_root(), WPMU_PLUGIN_DIR, WP_PLUGIN_DIR . '/crazy-panel') as $d) {
		if (!is_dir($d)) { continue; }
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) { if ($f->isFile() && $f->getSize() < 3 * 1048576 && preg_match('/\.(php|css|js|json|html?|txt)$/i', $f->getFilename())) { cpt_refs_from((string) @file_get_contents($f->getPathname()), $set); } }
	}
	$done[] = 'fájlok';
	$meta = array('at' => time(), 'refs' => count($set), 'ids' => count($ids), 'kesz' => $done, 'mp' => round(microtime(true) - $t0, 1));
	cpt_put('refs', array('meta' => $meta, 'set' => array_keys($set), 'ids' => array_keys($ids)));
	return $meta;
}

/* egy csatolmány összes fájlja (relatív, kisbetűs kulccsal is) */
function cpt_att_files($id, $file, $md) {
	$out = array(); if (!$file) { return $out; }
	$dir = dirname($file); $out[] = $file;
	if (is_array($md)) {
		if (!empty($md['original_image'])) { $out[] = ($dir === '.' ? '' : $dir . '/') . $md['original_image']; }
		foreach ((array) ($md['sizes'] ?? array()) as $s) { if (!empty($s['file'])) { $out[] = ($dir === '.' ? '' : $dir . '/') . $s['file']; } }
	}
	return array_values(array_unique($out));
}

/* a fájlnév „törzse” a méret- és feldolgozási utótagok nélkül (pl. kep-scaled-optimized-1024x683.jpg → 2022/02/kep) */
function cpt_stem($rel) {
	$rel = strtolower(preg_replace('/\.[a-z0-9]+(\.webp)?$/i', '', (string) $rel));
	do { $o = $rel; $rel = preg_replace('/-(\d{2,5}x\d{2,5}|scaled|optimized|rotated|e\d{10,13}|\d)$/', '', $rel); } while ($rel !== $o);
	return $rel;
}

function cpt_classify() {
	global $wpdb; $t0 = microtime(true);
	$r = cpt_get('refs'); if (!$r || empty($r['meta']['at']) || time() - $r['meta']['at'] > 3 * HOUR_IN_SECONDS) { throw new \Exception('Előbb futtasd a scan lépést (3 órán belül).'); }
	$set = array_flip($r['set']); $ids = array_flip($r['ids']);
	$stems = array(); foreach ($r['set'] as $x) { $stems[cpt_stem($x)] = 1; }
	/* biztonsági zár: ha a felmérés gyanúsan kevés hivatkozást talált, valami elromlott – nem sorolunk be semmit */
	if (count($set) < 200 || count($ids) < 50) { throw new \Exception('Gyanúsan kevés hivatkozás (' . count($set) . ' fájl, ' . count($ids) . ' azonosító) – nem folytatom.'); }
	$up = wp_normalize_path(wp_get_upload_dir()['basedir']); $recent = time() - 14 * DAY_IN_SECONDS;
	$att = $wpdb->get_results("SELECT p.ID, a.meta_value f, m.meta_value md FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_wp_attached_file' LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata' WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'", ARRAY_A);
	$owned = array(); $cand = array(); $kept = 0; $keepf = array(); $pre = array();
	foreach ($att as $a) {
		$id = (int) $a['ID']; $file = (string) $a['f'];
		if (!preg_match('#^\d{4}/\d{2}/#', $file)) { $kept++; foreach (cpt_att_files($id, $file, maybe_unserialize($a['md'])) as $x) { $owned[strtolower($x)] = 1; } continue; }
		$files = cpt_att_files($id, $file, maybe_unserialize($a['md']));
		$used = isset($ids[$id]);
		foreach ($files as $x) { $owned[strtolower($x)] = 1; if (isset($set[strtolower($x)]) || isset($stems[cpt_stem($x)])) { $used = true; } }
		$p = $up . '/' . $file;
		if (is_file($p) && filemtime($p) > $recent) { $used = true; }
		if ($used) { $kept++; foreach ($files as $x) { $keepf[strtolower($x)] = 1; } continue; }
		$pre[] = array('id' => $id, 'files' => $files);
	}
	/* egy fájl, amely bármelyik megtartott csatolmányhoz is tartozik (pl. ugyanarra a fájlra mutató másolat-bejegyzés), soha nem mozdul */
	foreach ($pre as $c) {
		$sz = 0; $ex = array(); $shared = 0;
		foreach ($c['files'] as $x) { if (isset($keepf[strtolower($x)])) { $shared++; continue; } if (is_file($up . '/' . $x)) { $sz += filesize($up . '/' . $x); $ex[] = $x; } }
		$cand[] = array('id' => $c['id'], 'files' => $ex, 'size' => $sz, 'shared' => $shared);
	}
	/* árva fájlok: a havi mappákban, de egyik csatolmányhoz sem tartoznak, és semmi nem hivatkozik rájuk */
	$orph = array();
	foreach ((array) glob($up . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/*') as $p) {
		if (!is_file($p)) { continue; }
		$rel = substr(wp_normalize_path($p), strlen($up) + 1); $k = strtolower($rel);
		if (isset($owned[$k]) || isset($set[$k]) || isset($stems[cpt_stem($rel)]) || filemtime($p) > $recent || preg_match('/\.(php|htaccess|html?)$/i', $rel)) { continue; }
		$orph[] = array('file' => $rel, 'size' => filesize($p));
	}
	$res = array('at' => time(), 'attachments' => count($att), 'kept' => $kept, 'cand' => $cand, 'orph' => $orph, 'mp' => round(microtime(true) - $t0, 1));
	cpt_put('plan', $res);
	return cpt_report();
}

function cpt_report() {
	$p = cpt_get('plan'); if (!$p) { throw new \Exception('Nincs terv – futtasd a classify lépést.'); }
	$by = array(); $tot = 0; $n = 0;
	$add = function ($rel, $sz) use (&$by, &$tot, &$n) { $y = substr($rel, 0, 4); $e = strtolower(pathinfo($rel, PATHINFO_EXTENSION)); $k = $y . ' .' . $e; if (!isset($by[$k])) { $by[$k] = array(0, 0); } $by[$k][0] += $sz; $by[$k][1]++; $tot += $sz; $n++; };
	foreach ($p['cand'] as $c) { foreach ($c['files'] as $f) { $add($f, @filesize(wp_get_upload_dir()['basedir'] . '/' . $f) ?: 0); } }
	foreach ($p['orph'] as $o) { $add($o['file'], $o['size']); }
	uasort($by, function ($a, $b) { return $b[0] - $a[0]; });
	return array('osszes_csatolmany' => $p['attachments'], 'megtartva' => $p['kept'], 'nem_hasznalt_csatolmany' => count($p['cand']), 'arva_fajl' => count($p['orph']), 'fajl' => $n, 'mb' => round($tot / 1048576, 1),
		'bontas' => array_map(function ($a) { return array(round($a[0] / 1048576, 1), $a[1]); }, array_slice($by, 0, 30, true)));
}

/* független ellenőrzés: a nyilvános oldalakon ténylegesen betöltött fájlok (ÉÉÉÉ/HH/fájl) közül hány szerepel a lomtár-tervben */
function cpt_verify($refs) {
	$p = cpt_get('plan'); if (!$p) { throw new \Exception('Nincs terv.'); }
	$c = array(); foreach ($p['cand'] as $x) { foreach ($x['files'] as $f) { $c[strtolower($f)] = 1; } } foreach ($p['orph'] as $o) { $c[strtolower($o['file'])] = 1; }
	$hit = array(); foreach ($refs as $r) { $k = strtolower((string) $r); if (isset($c[$k])) { $hit[] = $k; } }
	return array('ellenorzott' => count($refs), 'utkozes' => count($hit), 'peldak' => array_slice(array_values(array_unique($hit)), 0, 30));
}

/* hibakeresés: egy bejegyzés kiemelt képe és egy fájl helyzete a tervben */
function cpt_why($post, $file) {
	global $wpdb; $r = cpt_get('refs'); $p = cpt_get('plan'); $ids = array_flip($r['ids'] ?? array());
	$th = (int) get_post_meta($post, '_thumbnail_id', true);
	$out = array('post_status' => get_post_status($post), 'post_type' => get_post_type($post), 'thumbnail_id' => $th, 'thumb_in_ids' => isset($ids[$th]), 'thumb_att_status' => $th ? get_post_status($th) : null, 'thumb_file' => $th ? get_post_meta($th, '_wp_attached_file', true) : null);
	$out['thumb_meta_rows'] = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post, '%thumbnail%'), ARRAY_A);
	$out['file_in_cand'] = array(); foreach (($p['cand'] ?? array()) as $c) { if (in_array($file, $c['files'], true)) { $out['file_in_cand'][] = $c['id']; } }
	$out['file_in_orph'] = in_array($file, array_column($p['orph'] ?? array(), 'file'), true);
	return $out;
}

/* hiányzó képméretek: azoknál a (nem kukás) képeknél, ahol a meta-adatban szereplő méretfájl nincs meg, de az eredeti igen – do=igen esetén újragenerálja */
function cpt_regen($do) {
	global $wpdb; $up = wp_normalize_path(wp_get_upload_dir()['basedir']); $t0 = microtime(true);
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$rows = $wpdb->get_results("SELECT p.ID, a.meta_value f, m.meta_value md FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_wp_attached_file' LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata' WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.post_mime_type LIKE 'image/%'", ARRAY_A);
	$need = array(); $done = 0; $fail = 0;
	foreach ($rows as $r) {
		$md = maybe_unserialize($r['md']); $dir = dirname($r['f']); $miss = 0;
		if (!is_file($up . '/' . $r['f'])) { continue; }
		foreach ((array) ($md['sizes'] ?? array()) as $sz) { if (!empty($sz['file']) && !is_file($up . '/' . ($dir === '.' ? '' : $dir . '/') . $sz['file'])) { $miss++; } }
		if (!$miss) { continue; }
		$need[] = array('id' => (int) $r['ID'], 'hiany' => $miss);
		if ($do === 'igen' && microtime(true) - $t0 < 40) {
			$new = wp_generate_attachment_metadata((int) $r['ID'], $up . '/' . $r['f']);
			if (is_array($new) && !empty($new)) { wp_update_attachment_metadata((int) $r['ID'], $new); $done++; } else { $fail++; }
		}
	}
	return array('hianyos_kep' => count($need), 'hianyzo_meret' => array_sum(array_column($need, 'hiany')), 'ujrageneralva' => $done, 'sikertelen' => $fail);
}

/* tömörítési lehetőségek felmérése */
function cpt_env() {
	$up = wp_normalize_path(wp_get_upload_dir()['basedir']); $s = array('jpg_eredeti_500k_felett' => array(0, 0), 'png_500k_felett' => array(0, 0), 'kep_2560px_felett' => array(0, 0));
	foreach ((array) glob($up . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/*') as $p) {
		if (!is_file($p) || preg_match('/-\d{2,4}x\d{2,4}\./', $p)) { continue; }
		$sz = filesize($p); $e = strtolower(pathinfo($p, PATHINFO_EXTENSION));
		if (in_array($e, array('jpg', 'jpeg'), true) && $sz > 512000) { $s['jpg_eredeti_500k_felett'][0]++; $s['jpg_eredeti_500k_felett'][1] += $sz; }
		if ($e === 'png' && $sz > 512000) { $s['png_500k_felett'][0]++; $s['png_500k_felett'][1] += $sz; }
		if (in_array($e, array('jpg', 'jpeg', 'png'), true) && $sz > 300000) { $d = @getimagesize($p); if ($d && max($d[0], $d[1]) > 2560) { $s['kep_2560px_felett'][0]++; $s['kep_2560px_felett'][1] += $sz; } }
	}
	foreach ($s as $k => $v) { $s[$k] = array($v[0], round($v[1] / 1048576, 1)); }
	return $s + array('imagick' => extension_loaded('imagick'), 'gd' => extension_loaded('gd'), 'editor' => (function () { $e = wp_get_image_editor(__FILE__); return is_wp_error($e) ? _wp_image_editor_choose(array('mime_type' => 'image/jpeg')) : get_class($e); })(), 'jpeg_quality' => apply_filters('jpeg_quality', 82, 'image_resize'), 'big_image_threshold' => apply_filters('big_image_size_threshold', 2560, array(0, 0), '', 0));
}

/* képtömörítés helyben (a fájlnév nem változik): >2560 px → 2560 px; JPG 82-es minőség; PNG veszteségmentes. Csak ha ≥10% a nyereség.
   Az eredeti mentése: _lomtar/<stamp>-tomorites/uploads/… Részletekben fut (hívásonként ~40 mp), a manifest tömörítés-<stamp>.json. */
function cpt_compress($confirm, $max = 0) {
	if ($confirm !== 'igen') { throw new \Exception('A tömörítéshez confirm=igen kell.'); }
	if (!extension_loaded('imagick')) { throw new \Exception('Nincs Imagick.'); }
	$t0 = microtime(true); $up = wp_normalize_path(wp_get_upload_dir()['basedir']); $recent = time() - 14 * DAY_IN_SECONDS;
	$cur = null; foreach ((array) glob(cpt_dir() . '/tomorites-*.json') as $f) { $m = json_decode((string) file_get_contents($f), true); if (is_array($m) && empty($m['kesz'])) { $cur = $m; break; } }
	if (!$cur) {
		$list = array();
		foreach ((array) glob($up . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/*') as $p) {
			if (!is_file($p) || preg_match('/-\d{2,4}x\d{2,4}\.[a-z]+$/i', $p) || filemtime($p) > $recent) { continue; }
			$e = strtolower(pathinfo($p, PATHINFO_EXTENSION)); if (!in_array($e, array('jpg', 'jpeg', 'png'), true) || filesize($p) < 300000) { continue; }
			$list[] = substr(wp_normalize_path($p), strlen($up) + 1);
		}
		$cur = array('stamp' => gmdate('Ymd-His'), 'at' => time(), 'list' => $list, 'pos' => 0, 'done' => array(), 'skip' => 0, 'err' => 0, 'elotte' => 0, 'utana' => 0, 'kesz' => false);
	}
	$bk = cpt_dir() . '/' . $cur['stamp'] . '-tomorites/uploads/';
	$did = 0;
	while ($cur['pos'] < count($cur['list']) && microtime(true) - $t0 < 40 && (!$max || $did < $max)) {
		$did++;
		$rel = $cur['list'][$cur['pos']++]; $p = $up . '/' . $rel; if (!is_file($p)) { continue; }
		try {
			$old = filesize($p); $e = strtolower(pathinfo($p, PATHINFO_EXTENSION)); $tmp = $p . '.cptmp';
			$im = new Imagick($p);
			if ($im->getNumberImages() > 1) { $im->clear(); $cur['skip']++; continue; }
			$ori = $im->getImageOrientation();
			if ($ori > 1) { if (method_exists($im, 'autoOrient')) { $im->autoOrient(); } else { $im->clear(); $cur['skip']++; continue; } }
			$w = $im->getImageWidth(); $h = $im->getImageHeight();
			if (max($w, $h) > 2560) { $im->resizeImage($w >= $h ? 2560 : 0, $h > $w ? 2560 : 0, Imagick::FILTER_LANCZOS, 1); }
			$icc = null; try { $icc = $im->getImageProfile('icc'); } catch (\Throwable $x) { $icc = null; }
			$im->stripImage(); if ($icc) { $im->profileImage('icc', $icc); }
			if ($e === 'png') { $im->setImageCompressionQuality(95); $im->setOption('png:compression-level', '9'); $im->setOption('png:compression-filter', '5'); }
			else { $im->setImageCompression(Imagick::COMPRESSION_JPEG); $im->setImageCompressionQuality(82); $im->setSamplingFactors(array('2x2', '1x1', '1x1')); $im->setInterlaceScheme(Imagick::INTERLACE_PLANE); }
			$fmt = $e === 'png' ? 'png' : 'jpeg'; $im->setImageFormat($fmt); $im->writeImage($fmt . ':' . $tmp); $im->clear();
			$new = is_file($tmp) ? filesize($tmp) : 0;
			if ($new > 0 && $new < $old * 0.9 && @getimagesize($tmp)) {
				wp_mkdir_p(dirname($bk . $rel)); if (!@copy($p, $bk . $rel)) { @unlink($tmp); $cur['err']++; continue; }
				if (@rename($tmp, $p)) { $cur['done'][] = $rel; $cur['elotte'] += $old; $cur['utana'] += $new; } else { @unlink($tmp); $cur['err']++; }
			} else { @unlink($tmp); $cur['skip']++; }
		} catch (\Throwable $x) { $cur['err']++; if (isset($tmp) && is_file($tmp)) { @unlink($tmp); } }
	}
	if ($cur['pos'] >= count($cur['list'])) { $cur['kesz'] = true; if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); } }
	cpt_put('tomorites-' . $cur['stamp'], $cur);
	return array('stamp' => $cur['stamp'], 'utolso' => array_slice($cur['done'], -min(5, count($cur['done']))), 'osszes' => count($cur['list']), 'feldolgozva' => $cur['pos'], 'tomoritve' => count($cur['done']), 'kihagyva' => $cur['skip'], 'hiba' => $cur['err'], 'megtakaritas_mb' => round(($cur['elotte'] - $cur['utana']) / 1048576, 1), 'kesz' => $cur['kesz']);
}
function cpt_uncompress($stamp) {
	$stamp = preg_replace('/[^0-9-]/', '', $stamp); $m = cpt_get('tomorites-' . $stamp); if (!$m) { throw new \Exception('Nincs ilyen tömörítés.'); }
	$up = wp_normalize_path(wp_get_upload_dir()['basedir']); $bk = cpt_dir() . '/' . $stamp . '-tomorites/uploads/'; $n = 0;
	/* a mentési mappa teljes tartalmából (akkor is, ha egy megszakadt részlet könyvelése elveszett) */
	if (is_dir($bk)) { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bk, FilesystemIterator::SKIP_DOTS)); foreach ($it as $f) { if (!$f->isFile()) { continue; } $rel = substr(wp_normalize_path($f->getPathname()), strlen(wp_normalize_path($bk))); if (@rename($f->getPathname(), $up . '/' . $rel)) { $n++; } } }
	$m['visszaallitva'] = time(); $m['kesz'] = true; cpt_put('tomorites-' . $stamp, $m);
	return array('visszaallitva' => $n);
}

function cpt_quarantine($confirm) {
	if ($confirm !== 'igen') { throw new \Exception('A lomtárba helyezéshez confirm=igen kell.'); }
	global $wpdb; $p = cpt_get('plan'); if (!$p || time() - $p['at'] > 3 * HOUR_IN_SECONDS) { throw new \Exception('A terv elavult vagy hiányzik – futtasd újra a scan és classify lépést.'); }
	$up = wp_normalize_path(wp_get_upload_dir()['basedir']); $wp = wp_normalize_path(dirname($up) . '/uploads-webpc/uploads');
	$stamp = gmdate('Ymd-His'); $base = cpt_dir() . '/' . $stamp; $man = array('stamp' => $stamp, 'at' => time(), 'att' => array(), 'orph' => array(), 'moved' => 0, 'bytes' => 0, 'err' => 0);
	$mv = function ($from, $to) use (&$man) { if (!is_file($from)) { return false; } wp_mkdir_p(dirname($to)); $s = filesize($from); if (@rename($from, $to)) { $man['moved']++; $man['bytes'] += $s; return true; } $man['err']++; return false; };
	foreach ($p['cand'] as $c) {
		$moved = array();
		foreach ($c['files'] as $f) {
			if ($mv($up . '/' . $f, $base . '/uploads/' . $f)) { $moved[] = $f; }
			foreach (array($f . '.webp', preg_replace('/\.[^.]+$/', '.webp', $f)) as $w) { $mv($wp . '/' . $w, $base . '/uploads-webpc/' . $w); }
		}
		$wpdb->update($wpdb->posts, array('post_status' => 'trash'), array('ID' => $c['id']));
		update_post_meta($c['id'], '_wp_trash_meta_status', 'inherit'); update_post_meta($c['id'], '_wp_trash_meta_time', time()); update_post_meta($c['id'], '_cp_lomtar', $stamp);
		clean_post_cache($c['id']);
		$man['att'][] = array('id' => $c['id'], 'files' => $moved);
	}
	foreach ($p['orph'] as $o) { if ($mv($up . '/' . $o['file'], $base . '/uploads/' . $o['file'])) { $man['orph'][] = $o['file']; } }
	cpt_put('q-' . $stamp, $man);
	@unlink(cpt_dir() . '/plan.json');
	return array('stamp' => $stamp, 'csatolmany' => count($man['att']), 'arva' => count($man['orph']), 'athelyezett_fajl' => $man['moved'], 'mb' => round($man['bytes'] / 1048576, 1), 'hiba' => $man['err']);
}

function cpt_restore($stamp) {
	global $wpdb; $stamp = preg_replace('/[^0-9-]/', '', $stamp); $m = cpt_get('q-' . $stamp); if (!$m) { throw new \Exception('Nincs ilyen lomtár.'); }
	$up = wp_normalize_path(wp_get_upload_dir()['basedir']); $wp = wp_normalize_path(dirname($up) . '/uploads-webpc/uploads'); $base = cpt_dir() . '/' . $stamp; $n = 0;
	$it = is_dir($base) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) : array();
	foreach ($it as $f) { if (!$f->isFile()) { continue; } $rel = substr(wp_normalize_path($f->getPathname()), strlen($base) + 1); $to = strpos($rel, 'uploads-webpc/') === 0 ? $wp . '/' . substr($rel, 14) : $up . '/' . substr($rel, 8); wp_mkdir_p(dirname($to)); if (@rename($f->getPathname(), $to)) { $n++; } }
	foreach ($m['att'] as $a) { $wpdb->update($wpdb->posts, array('post_status' => 'inherit'), array('ID' => (int) $a['id'])); delete_post_meta((int) $a['id'], '_cp_lomtar'); delete_post_meta((int) $a['id'], '_wp_trash_meta_status'); delete_post_meta((int) $a['id'], '_wp_trash_meta_time'); clean_post_cache((int) $a['id']); }
	@rename(cpt_dir() . '/q-' . $stamp . '.json', cpt_dir() . '/visszaallitva-' . $stamp . '.json');
	return array('visszaallitott_fajl' => $n, 'csatolmany' => count($m['att']));
}

function cpt_purge($stamp) {
	$stamp = preg_replace('/[^0-9-]/', '', $stamp); $m = cpt_get('q-' . $stamp); if (!$m) { throw new \Exception('Nincs ilyen lomtár.'); }
	if (time() - (int) $m['at'] < 30 * DAY_IN_SECONDS) { throw new \Exception('A lomtár 30 napig megmarad; végleges törlés: ' . wp_date('Y.m.d.', (int) $m['at'] + 30 * DAY_IN_SECONDS) . ' után.'); }
	foreach ($m['att'] as $a) { wp_delete_attachment((int) $a['id'], true); }
	$base = cpt_dir() . '/' . $stamp; $n = 0;
	if (is_dir($base)) { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $f) { if ($f->isDir()) { @rmdir($f->getPathname()); } else { @unlink($f->getPathname()); $n++; } } @rmdir($base); }
	@rename(cpt_dir() . '/q-' . $stamp . '.json', cpt_dir() . '/torolve-' . $stamp . '.json');
	return array('torolt_fajl' => $n, 'csatolmany' => count($m['att']));
}
