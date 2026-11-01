<?php
/**
 * Crazy panel – valódi időzített futtatás (2026-11-01).
 * A wePanel cron 5 percenként hívja a wp-cron.php-t, ezért a látogatásokból induló WP-Cront kikapcsoljuk (gyorsabb oldalbetöltés,
 * pontos 5 perces levélszinkron és értesítések). Biztonsági tartalék: ha a külső cron 20 percig nem jelentkezik, a beépített
 * WP-Cron magától visszakapcsol, így semmi nem áll le. Állapot: cp_cron_last opció (az utolsó külső futás ideje).
 * Kikapcsolás: a fájl törlése.
 */
if (!defined('ABSPATH')) { exit; }
if (defined('DOING_CRON') && DOING_CRON && isset($_GET['doing_wp_cron']) && $_GET['doing_wp_cron'] === '') {
	/* a külső cron hívása (wp-cron.php?doing_wp_cron, érték nélkül – a beépített WP-Cron időbélyeggel hívja magát) – az idejét feljegyezzük (legfeljebb percenként egyszer írunk) */
	add_action('init', function () { $l = (int) get_option('cp_cron_last', 0); if (time() - $l > 60) { update_option('cp_cron_last', time(), true); } }, 1);
}
if (!defined('DISABLE_WP_CRON') && time() - (int) get_option('cp_cron_last', 0) < 20 * MINUTE_IN_SECONDS) {
	define('DISABLE_WP_CRON', true);
}
