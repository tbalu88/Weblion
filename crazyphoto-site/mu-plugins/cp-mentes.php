<?php
/**
 * Plugin Name: Crazy – napi adatbázis-mentés
 * Description: 6 óránként (3, 9, 15, 21 óra körül) a teljes WordPress-adatbázist (a Crazy panel minden adatával) tömörítve elmenti a tárhely webgyökerén KÍVÜLI mappába (böngészőből nem érhető el); a Crazy panel innen tölti fel titkosítva a Google Drive-ra. Megőrzés helyben: az utolsó 2 nap minden mentése, 14 napig napi egy, fél évig havi egy.
 * Version: 1.1
 */
if (!defined('ABSPATH')) { exit; }

define('CP_MENTES_KEEP_DAYS', 14);
define('CP_MENTES_KEEP_MONTHLY_DAYS', 190);

function cp_mentes_dir() {
	$slug = preg_replace('/[^a-z0-9.-]/', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
	return dirname(rtrim(ABSPATH, '/')) . '/cp-mentes/' . ($slug !== '' ? $slug : 'site');
}
function cp_mentes_state() { $s = get_option('cp_mentes_state', array()); return is_array($s) ? $s : array(); }

/* ütemezés: 6 óránként, 03:17 / 09:17 / 15:17 / 21:17 (Budapest) */
add_filter('cron_schedules', function ($s) { $s['cp_6h'] = array('interval' => 6 * HOUR_IN_SECONDS, 'display' => '6 óránként (Crazy mentés)'); return $s; });
add_action('init', function () {
	if (wp_next_scheduled('cp_mentes_daily')) { wp_clear_scheduled_hook('cp_mentes_daily'); }
	if (wp_next_scheduled('cp_mentes_6h')) { return; }
	$tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Budapest');
	$best = null;
	foreach (array('today 03:17', 'today 09:17', 'today 15:17', 'today 21:17', 'tomorrow 03:17') as $t) { $d = new DateTime($t, $tz); if ($d->getTimestamp() > time() + 60) { $best = $d->getTimestamp(); break; } }
	wp_schedule_event($best, 'cp_6h', 'cp_mentes_6h');
});
add_action('cp_mentes_6h', 'cp_mentes_run');
add_action('cp_mentes_daily', 'cp_mentes_run');

function cp_mentes_run() {
	/* atomi zár: egyszerre egy mentés */
	if (!add_option('cp_mentes_lock', time(), '', 'no')) {
		if ((int) get_option('cp_mentes_lock') > time() - 3600) { return; }
		update_option('cp_mentes_lock', time(), false);
	}
	if (function_exists('set_time_limit')) { @set_time_limit(280); }
	$t0 = microtime(true);
	$st = cp_mentes_state();
	$dir = cp_mentes_dir();
	try {
		if (!is_dir($dir) && !wp_mkdir_p($dir)) { throw new Exception('A mentési mappa nem hozható létre.'); }
		@chmod(dirname($dir), 0700); @chmod($dir, 0700);
		@file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
		$file = $dir . '/' . DB_NAME . '-' . wp_date('Y-m-d-H') . '.sql.gz';
		$tmp = $file . '.tmp';
		$method = cp_mentes_mysqldump($tmp) ? 'mysqldump' : '';
		if ($method === '') { cp_mentes_php_dump($tmp); $method = 'php'; }
		if (!is_file($tmp) || filesize($tmp) < 2048) { throw new Exception('A mentés üres lett.'); }
		@rename($tmp, $file); @chmod($file, 0600);
		cp_mentes_prune($dir);
		$st = array('last' => time(), 'file' => basename($file), 'mb' => round(filesize($file) / 1048576, 1), 'method' => $method, 'sec' => round(microtime(true) - $t0), 'err' => '', 'count' => count((array) glob($dir . '/*.sql.gz')));
	} catch (Throwable $e) {
		if (isset($tmp) && is_file($tmp)) { @unlink($tmp); }
		$st['err'] = mb_substr($e->getMessage(), 0, 200);
		$st['err_at'] = time();
	}
	update_option('cp_mentes_state', $st, false);
	delete_option('cp_mentes_lock');
}

/* mysqldump – a jelszó ideiglenes, csak a tulajdonos által olvasható fájlban (nem a parancssorban) */
function cp_mentes_mysqldump($out) {
	if (!function_exists('exec')) { return false; }
	$bin = '';
	foreach (array('/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/bin/mariadb-dump') as $b) { if (@is_executable($b)) { $bin = $b; break; } }
	if ($bin === '') { return false; }
	$host = DB_HOST; $port = ''; $sock = '';
	if (strpos($host, ':') !== false) { list($host, $x) = explode(':', $host, 2); if (ctype_digit($x)) { $port = $x; } else { $sock = $x; } }
	$cnf = dirname($out) . '/.my-' . wp_generate_password(12, false) . '.cnf';
	$c = "[client]\nuser=\"" . addcslashes(DB_USER, '"\\') . "\"\npassword=\"" . addcslashes(DB_PASSWORD, '"\\') . "\"\nhost=\"" . addcslashes($host, '"\\') . "\"\n" . ($port !== '' ? "port=$port\n" : '') . ($sock !== '' ? 'socket="' . addcslashes($sock, '"\\') . "\"\n" : '');
	if (@file_put_contents($cnf, $c) === false) { return false; }
	@chmod($cnf, 0600);
	$cmd = 'set -o pipefail 2>/dev/null; ' . escapeshellarg($bin) . ' --defaults-extra-file=' . escapeshellarg($cnf) . ' --single-transaction --quick --skip-lock-tables --default-character-set=utf8mb4 --no-tablespaces ' . escapeshellarg(DB_NAME) . ' 2>/dev/null | gzip -6 > ' . escapeshellarg($out);
	$rc = 1; $o = array();
	@exec('bash -c ' . escapeshellarg($cmd), $o, $rc);
	@unlink($cnf);
	if ($rc !== 0 || !is_file($out) || filesize($out) < 2048) { @unlink($out); return false; }
	/* a végén ott kell legyen a „Dump completed” */
	$tail = @shell_exec('gzip -dc ' . escapeshellarg($out) . ' 2>/dev/null | tail -c 200');
	if (is_string($tail) && strpos($tail, 'Dump completed') === false) { @unlink($out); return false; }
	return true;
}

/* tartalék: PHP-s mentés táblánként, 500 soros darabokban */
function cp_mentes_php_dump($out) {
	global $wpdb;
	$gz = gzopen($out, 'wb6');
	if (!$gz) { throw new Exception('A mentési fájl nem írható.'); }
	gzwrite($gz, "-- Crazy mentés (PHP) " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
	$dbh = $wpdb->dbh;
	foreach ((array) $wpdb->get_col('SHOW TABLES') as $t) {
		$cr = $wpdb->get_row('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`', ARRAY_N);
		if (!$cr) { continue; }
		gzwrite($gz, "\nDROP TABLE IF EXISTS `$t`;\n" . $cr[1] . ";\n");
		for ($off = 0; ; $off += 500) {
			$rows = $wpdb->get_results('SELECT * FROM `' . str_replace('`', '', $t) . '` LIMIT ' . (int) $off . ', 500', ARRAY_N);
			if (!$rows) { break; }
			$vals = array();
			foreach ($rows as $r) { $vals[] = '(' . implode(',', array_map(function ($v) use ($dbh) { return $v === null ? 'NULL' : "'" . mysqli_real_escape_string($dbh, (string) $v) . "'"; }, $r)) . ')'; }
			gzwrite($gz, "INSERT INTO `$t` VALUES\n" . implode(",\n", $vals) . ";\n");
			if (count($rows) < 500) { break; }
		}
	}
	gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n-- Dump completed\n");
	gzclose($gz);
}

/* megőrzés: az utolsó 2 nap minden mentése; 14 napig naponta az első; fél évig a hónap első napjának első mentése */
function cp_mentes_prune($dir) {
	$files = (array) glob($dir . '/*.sql.gz'); sort($files);
	$dayFirst = array();
	foreach ($files as $f) {
		if (!preg_match('/-(\d{4})-(\d{2})-(\d{2})(?:-(\d{2}))?\.sql\.gz$/', $f, $m)) { continue; }
		$day = $m[1] . '-' . $m[2] . '-' . $m[3];
		$age = (time() - filemtime($f)) / DAY_IN_SECONDS;
		$first = !isset($dayFirst[$day]); $dayFirst[$day] = 1;
		if ($age <= 2) { continue; }
		if ($first && $age <= CP_MENTES_KEEP_DAYS) { continue; }
		if ($first && $m[3] === '01' && $age <= CP_MENTES_KEEP_MONTHLY_DAYS) { continue; }
		@unlink($f);
	}
	foreach ((array) glob($dir . '/*.tmp') as $f) { if (filemtime($f) < time() - DAY_IN_SECONDS) { @unlink($f); } }
}
