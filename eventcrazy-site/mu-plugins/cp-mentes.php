<?php
/**
 * Plugin Name: Crazy – napi adatbázis-mentés
 * Description: Óránként (minden óra 17. percében) megnézi, változott-e az adatbázis tartalma; ha igen (vagy 24 órája nem volt mentés), a teljes WordPress-adatbázist (a Crazy panel minden adatával) tömörítve elmenti a tárhely webgyökerén KÍVÜLI mappába; a Crazy panel innen tölti fel titkosítva a Google Drive-ra. Megőrzés helyben: a legutolsó 3, 7 napig napi egy, 3 hónapig havi egy.
 * Version: 1.2 (2026-11-13: óránkénti, változásfüggő mentés)
 */
if (!defined('ABSPATH')) { exit; }

define('CP_MENTES_KEEP_DAYS', 7);
define('CP_MENTES_KEEP_MONTHLY_DAYS', 92);

function cp_mentes_dir() {
	$slug = preg_replace('/[^a-z0-9.-]/', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
	return dirname(rtrim(ABSPATH, '/')) . '/cp-mentes/' . ($slug !== '' ? $slug : 'site');
}
function cp_mentes_state() { $s = get_option('cp_mentes_state', array()); return is_array($s) ? $s : array(); }

/* ütemezés: óránként, minden óra 17. percében; mentés csak, ha változott az adatbázis tartalma (vagy 24 órája nem volt) */
add_filter('cron_schedules', function ($s) { $s['cp_1h'] = array('interval' => HOUR_IN_SECONDS, 'display' => 'Óránként (Crazy mentés)'); return $s; });
add_action('init', function () {
	foreach (array('cp_mentes_daily', 'cp_mentes_6h') as $old) { if (wp_next_scheduled($old)) { wp_clear_scheduled_hook($old); } }
	if (wp_next_scheduled('cp_mentes_1h')) { return; }
	$t = time(); $next = $t - ($t % HOUR_IN_SECONDS) + 17 * MINUTE_IN_SECONDS; if ($next <= $t + 60) { $next += HOUR_IN_SECONDS; }
	wp_schedule_event($next, 'cp_1h', 'cp_mentes_1h');
});
add_action('cp_mentes_1h', 'cp_mentes_run');
add_action('cp_mentes_6h', 'cp_mentes_run');

/* az adatbázis tartalmának ujjlenyomata – a magától, adat nélkül is frissülő táblák (beállítás-gyorsítótár, naplók, statisztika,
   használat, időzítő, biztonsági bővítmények) nélkül; kisebb tábláknál tartalom-ellenőrzőösszeg, a nagyoknál sorszám + legnagyobb azonosító */
function cp_mentes_fp() {
	global $wpdb;
	$p = $wpdb->prefix;
	$skip = '/^' . preg_quote($p, '/') . '(options|actionscheduler_|redirection_|wf|wpr_|yoast_|aioseo_|e_|icl_|gf_draft|crazy_usage|crazy_st_|crazy_notifs|crazy_cut_log|crazy_flow_log|crazy_audit|crazy_mail_px)/';
	$rows = $wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME n, DATA_LENGTH d FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = %s ORDER BY TABLE_NAME', DB_NAME, 'BASE TABLE'), ARRAY_A);
	$parts = array();
	foreach ((array) $rows as $r) {
		$t = (string) $r['n'];
		if (!preg_match('/^[A-Za-z0-9_]+$/', $t) || preg_match($skip, $t)) { continue; }
		if ((int) $r['d'] < 64 * 1048576) {
			$c = $wpdb->get_row('CHECKSUM TABLE `' . $t . '`', ARRAY_N);
			$parts[] = $t . ':' . ($c ? $c[1] : '?');
		} else {
			$pk = $wpdb->get_var($wpdb->prepare('SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s LIMIT 1', DB_NAME, $t, 'PRIMARY'));
			$parts[] = $t . ':' . $wpdb->get_var('SELECT CONCAT(COUNT(*), "/", ' . ($pk && preg_match('/^[A-Za-z0-9_]+$/', $pk) ? 'MAX(`' . $pk . '`)' : '0') . ') FROM `' . $t . '`');
		}
	}
	/* a saját beállítások (a pillanatnyi állapot-, zár- és gyorsítótár-bejegyzések nélkül) */
	$parts[] = 'opt:' . $wpdb->get_var("SELECT MD5(GROUP_CONCAT(option_name, ':', MD5(option_value) ORDER BY option_name)) FROM {$wpdb->options} WHERE (option_name LIKE 'cp\\_%' OR option_name LIKE 'crazy\\_%') AND option_name NOT REGEXP '(state|sync|lock|tick|_at$|_gc|seen|last|cache|queue|_wd|recent|probe|_stat|usage|_tmp|_run)'");
	return md5(implode('|', $parts));
}

function cp_mentes_run() {
	/* atomi zár: egyszerre egy mentés */
	if (!add_option('cp_mentes_lock', time(), '', 'no')) {
		if ((int) get_option('cp_mentes_lock') > time() - 3600) { return; }
		update_option('cp_mentes_lock', time(), false);
	}
	if (function_exists('set_time_limit')) { @set_time_limit(280); }
	$t0 = microtime(true);
	$st = cp_mentes_state();
	/* v1.2: nincs változás és 24 órán belül volt mentés → most nem kell */
	$fp = '';
	try { $fp = cp_mentes_fp(); } catch (Throwable $e) { $fp = ''; }
	if ($fp !== '' && !empty($st['fp']) && hash_equals((string) $st['fp'], $fp) && empty($st['err']) && (int) ($st['last'] ?? 0) > time() - 23 * HOUR_IN_SECONDS) {
		$st['skip'] = time(); $st['skips'] = (int) ($st['skips'] ?? 0) + 1;
		update_option('cp_mentes_state', $st, false);
		delete_option('cp_mentes_lock');
		return;
	}
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
		$st = array('last' => time(), 'file' => basename($file), 'mb' => round(filesize($file) / 1048576, 1), 'method' => $method, 'sec' => round(microtime(true) - $t0), 'err' => '', 'count' => count((array) glob($dir . '/*.sql.gz')), 'fp' => $fp, 'skips' => 0);
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
	/* v1.2: a legutolsó 3; 7 napig naponta a nap utolsó mentése; 3 hónapig havonta a hónap utolsó mentése */
	$files = (array) glob($dir . '/*.sql.gz'); rsort($files);
	$seenDay = array(); $seenMon = array(); $i = 0;
	foreach ($files as $f) {
		if (!preg_match('/-(\d{4})-(\d{2})-(\d{2})(?:-(\d{2}))?\.sql\.gz$/', $f, $m)) { continue; }
		$day = $m[1] . '-' . $m[2] . '-' . $m[3]; $mon = $m[1] . '-' . $m[2];
		$age = (time() - filemtime($f)) / DAY_IN_SECONDS;
		$lastDay = !isset($seenDay[$day]); $seenDay[$day] = 1;
		$lastMon = !isset($seenMon[$mon]); $seenMon[$mon] = 1;
		if ($i++ < 3) { continue; }
		if ($lastDay && $age <= CP_MENTES_KEEP_DAYS) { continue; }
		if ($lastMon && $age <= CP_MENTES_KEEP_MONTHLY_DAYS) { continue; }
		@unlink($f);
	}
	foreach ((array) glob($dir . '/*.tmp') as $f) { if (filemtime($f) < time() - DAY_IN_SECONDS) { @unlink($f); } }
}
