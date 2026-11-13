<?php
/**
 * Plugin Name: Tartalék – takarító (ideiglenes)
 * Description: Csak a híd kulcsával hívható (crazy-bridge/v1/takarito). GET: lista arról, mi törölhető (az eventcrazy-másolat fájljai és táblái) és mekkora. POST run=1: törlés – csak a tulajdonos jóváhagyása után. A munka végén ez a fájl is törlendő.
 */
if (!defined('ABSPATH')) { exit; }

function tk_size($p) {
	if (is_link($p)) { return 0; }
	if (is_file($p)) { return (int) @filesize($p); }
	$s = 0;
	try { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS)); foreach ($it as $f) { if ($f->isFile()) { $s += $f->getSize(); } } } catch (Exception $e) { /* */ }
	return $s;
}
function tk_mb($b) { return round($b / 1048576, 1); }
/* mi marad: a WordPress alaprendszere, a híd, a galéria, a beépített téma, a mu-plugins */
function tk_targets() {
	$c = WP_CONTENT_DIR; $out = array();
	foreach ((array) @scandir($c . '/plugins') as $f) { if ($f === '.' || $f === '..' || $f === 'index.php' || $f === 'crazy-bridge' || $f === 'crazy-galeria') { continue; } $out[] = $c . '/plugins/' . $f; }
	foreach ((array) @scandir($c . '/themes') as $f) { if ($f === '.' || $f === '..' || $f === 'index.php' || $f === 'twentytwentyfive') { continue; } $out[] = $c . '/themes/' . $f; }
	foreach ((array) @scandir($c) as $f) { if (in_array($f, array('.', '..', 'plugins', 'themes', 'mu-plugins', 'index.php', 'languages'), true)) { continue; } $out[] = $c . '/' . $f; }
	return $out;
}
function tk_tables() {
	global $wpdb;
	$core = array_map(function ($t) use ($wpdb) { return $wpdb->prefix . $t; }, array('posts', 'postmeta', 'options', 'users', 'usermeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'links'));
	$rows = $wpdb->get_results($wpdb->prepare('SELECT table_name n, ROUND((data_length+index_length)/1048576,1) mb, table_rows r FROM information_schema.tables WHERE table_schema = %s ORDER BY (data_length+index_length) DESC', DB_NAME), ARRAY_A);
	$del = array(); $keep = array();
	foreach ((array) $rows as $r) { if (in_array($r['n'], $core, true) || strpos($r['n'], $wpdb->prefix . 'crazy_galeria') === 0) { $keep[] = $r; } else { $del[] = $r; } }
	return array($del, $keep);
}
add_action('rest_api_init', function () {
	$auth = function () { return !empty($GLOBALS['cb_authed']); };
	register_rest_route('crazy-bridge/v1', '/takarito', array(
		array('methods' => 'GET', 'permission_callback' => $auth, 'callback' => function () {
			$f = array(); $tot = 0;
			foreach (tk_targets() as $p) { $s = tk_size($p); $tot += $s; $f[] = array(str_replace(ABSPATH, '', $p), tk_mb($s)); }
			usort($f, function ($a, $b) { return $b[1] <=> $a[1]; });
			list($del, $keep) = tk_tables();
			$df = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : 0; $dt = function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : 0;
			return array('files_mb' => tk_mb($tot), 'files' => $f, 'tables_del' => $del, 'tables_del_mb' => array_sum(array_column($del, 'mb')), 'tables_keep' => array_column($keep, 'n'), 'disk_free_gb' => round($df / 1073741824, 1), 'disk_total_gb' => round($dt / 1073741824, 1), 'wp_total_mb' => tk_mb(tk_size(ABSPATH)));
		}),
		/* POST mode=b: törlés a jóváhagyott „b” változat szerint – az ai1wm-backups (teljes mentésfájl) MARAD. Időkerettel, ismételten hívandó, amíg done=true. */
		array('methods' => 'POST', 'permission_callback' => $auth, 'callback' => function (WP_REST_Request $r) {
			global $wpdb;
			/* mode=move: a megtartott mentésfájl(ok) a webes gyökéren kívülre (nginx a .htaccess-t nem veszi figyelembe) */
			if ($r->get_param('mode') === 'move') {
				$src = WP_CONTENT_DIR . '/ai1wm-backups'; $dst = dirname(rtrim(ABSPATH, '/')) . '/mentes-privat';
				if (!is_dir($dst)) { @mkdir($dst, 0700, true); }
				$moved = array();
				foreach ((array) @glob($src . '/*.wpress') as $f) { $to = $dst . '/' . basename($f); if (@rename($f, $to)) { $moved[] = array(basename($f), round(filesize($to) / 1048576)); } }
				return array('moved' => $moved, 'dst_outside_webroot' => strpos($dst, rtrim(ABSPATH, '/')) !== 0, 'dst_dirname' => basename($dst), 'left_in_web' => count((array) @glob($src . '/*.wpress')));
			}
			if ($r->get_param('mode') !== 'b') { return new WP_Error('tk', 'mode=b kell', array('status' => 400)); }
			@set_time_limit(60);
			$t0 = microtime(true); $budget = 20; $n = 0; $left = false;
			$rm = function ($p) use (&$rm, &$n, $t0, $budget, &$left) {
				if (microtime(true) - $t0 > $budget) { $left = true; return; }
				if (is_link($p) || is_file($p)) { if (@unlink($p)) { $n++; } return; }
				if (!is_dir($p)) { return; }
				foreach ((array) @scandir($p) as $f) { if ($f === '.' || $f === '..') { continue; } $rm($p . '/' . $f); if ($left) { return; } }
				@rmdir($p);
			};
			foreach (tk_targets() as $p) {
				if (basename($p) === 'ai1wm-backups' || basename($p) === 'crazy-bridge-backups') { continue; }
				$rm($p); if ($left) { break; }
			}
			$tables = 0; $opts = 0;
			if (!$left) {
				list($del) = tk_tables();
				foreach ($del as $t) { if (preg_match('/^[A-Za-z0-9_]+$/', $t['n'])) { $wpdb->query('DROP TABLE IF EXISTS `' . $t['n'] . '`'); $tables++; } }
				/* a panel és a bővítmények beállításai (kulcsok, címek, ügyféladat) – a híd és a tartalék saját beállításai maradnak */
				$opts = (int) $wpdb->query("DELETE FROM {$wpdb->options} WHERE (option_name LIKE 'cp\\_%' OR option_name LIKE 'crazy\\_%' OR option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name LIKE 'elementor%' OR option_name LIKE 'wpseo%' OR option_name LIKE 'wordfence%' OR option_name LIKE 'wf\\_%' OR option_name LIKE 'rocket%' OR option_name LIKE 'wp\\_rocket%' OR option_name LIKE 'gf\\_%' OR option_name LIKE 'rg\\_%' OR option_name LIKE 'pys\\_%' OR option_name LIKE 'polylang%' OR option_name LIKE 'redirection%') AND option_name NOT IN ('crazy_bridge', 'cp_tartalek_cache_purged')");
			}
			return array('deleted_files' => $n, 'done' => !$left, 'tables_dropped' => $tables, 'options_deleted' => $opts);
		}),
	));
});
