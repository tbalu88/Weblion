<?php
/**
 * Plugin Name: Crazy – alap védelem
 * Description: A WordPress-felhasználók listája nem nyilvános (a belépési nevek ne legyenek kitalálhatók), és alap biztonsági fejlécek (HSTS, keretezés, hivatkozó).
 * Version: 1.0
 */
if (!defined('ABSPATH')) { exit; }

/* a /wp-json/wp/v2/users végpontok csak bejelentkezett szerkesztőknek */
add_filter('rest_endpoints', function ($ep) {
	if (is_user_logged_in() && current_user_can('list_users')) { return $ep; }
	foreach (array_keys($ep) as $route) { if (preg_match('#^/wp/v2/users(/|$)#', $route)) { unset($ep[$route]); } }
	return $ep;
});
/* ?author=N átirányítás a felhasználónévre – kikapcsolva */
add_action('template_redirect', function () {
	if (!is_user_logged_in() && isset($_GET['author'])) { wp_safe_redirect(home_url('/'), 301); exit; }
}, 1);
/* biztonsági fejlécek (csak https-en) */
add_action('send_headers', function () {
	if (headers_sent()) { return; }
	if (is_ssl()) { header('Strict-Transport-Security: max-age=31536000'); }
	header('X-Frame-Options: SAMEORIGIN');
	header('Referrer-Policy: strict-origin-when-cross-origin');
	header('X-Content-Type-Options: nosniff');
});
