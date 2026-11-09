<?php
/**
 * Crazy panel – könnyített „változott-e valami?” kérés betöltője (2026-11-09, P1).
 * A panel percenkénti háttérkérését (admin-ajax.php, action = cp_ping) a többi bővítmény betöltése ELŐTT szolgálja ki
 * a crazy-panel/ping.php-val, és kilép – a kérés így nem tölti be az oldalépítőt, a biztonsági és egyéb bővítményeket.
 * Csak akkor lép közbe, ha a Crazy panel bővítmény aktív és a ping.php megvan; különben a rendes út szolgál ki (ugyanúgy, lassabban).
 * Kikapcsolás: a fájl törlése (vagy define('CP_PING_LITE_OFF', true) a wp-config.php-ban).
 */
if (!defined('ABSPATH')) { exit; }
(function () {
	if (defined('CP_PING_LITE_OFF') && CP_PING_LITE_OFF) { return; }
	$script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
	if (substr($script, -14) !== 'admin-ajax.php' || !isset($_REQUEST['action']) || $_REQUEST['action'] !== 'cp_ping') { return; }
	$f = WP_PLUGIN_DIR . '/crazy-panel/ping.php';
	if (!is_file($f) || !in_array('crazy-panel/crazy-panel.php', (array) get_option('active_plugins', array()), true)) { return; }
	require_once $f;
	if (function_exists('cp_ping_serve')) { cp_ping_serve(true); }
})();
