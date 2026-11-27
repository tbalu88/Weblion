<?php
/**
 * CrazyPhoto – konverziómérés a köszönőoldalon (2026-11-27)
 * A WP Rocket „JavaScript késleltetése” a Google-címkéket (gtag: Google Ads AW-980940116, GA4 G-3RQDQ5FLW4, GTM)
 * csak a látogató első érintésére tölti be. A köszönőoldalon (/koszonjuk/) ez elveszítheti a konverziót, ha a látogató
 * nem nyúl semmihez – ezért ott ezek a címkék azonnal töltődnek. Minden más oldal változatlanul gyors marad.
 * Kikapcsolás: a fájl törlése, utána a WP Rocket gyorsítótárának ürítése.
 */
if (!defined('ABSPATH')) { exit; }

function cp_konv_page() { return is_page('koszonjuk'); }

add_filter('rocket_delay_js_exclusions', function ($ex) {
	if (!is_array($ex) || !cp_konv_page()) { return $ex; }
	return array_merge($ex, array('googletagmanager.com', 'gtag', 'dataLayer', 'gtm.js', 'google-analytics.com', 'GTM-N54LXT6W', 'AW-980940116', 'G-3RQDQ5FLW4'));
}, 99);

/* egyszer: a köszönőoldal gyorsítótárazott változatának törlése, hogy az új beállítás azonnal érvényes legyen */
add_action('init', function () {
	if (get_option('cp_konv_clean') === '1' || !function_exists('rocket_clean_post')) { return; }
	$p = get_page_by_path('koszonjuk');
	if ($p) { rocket_clean_post($p->ID); }
	update_option('cp_konv_clean', '1', false);
}, 99);
