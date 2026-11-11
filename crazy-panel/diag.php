<?php
/**
 * Crazy panel – ideiglenes elrendezés-diagnosztika (v2.9.195). A Levelezés keresés utáni nézetéről csak méreteket és
 * osztályneveket ment (levéltartalmat, nevet, címet nem); az utolsó 6 mérés a cp_diag opcióban. Hibakeresés után törölhető.
 */
if (!defined('ABSPATH')) { exit; }
add_action('wp_ajax_nopriv_cp_diag', 'cp_diag_ajax'); add_action('wp_ajax_cp_diag', 'cp_diag_ajax');
function cp_diag_ajax() {
	cp_el_auth();
	$d = json_decode((string) wp_unslash($_POST['d'] ?? ''), true);
	if (!is_array($d)) { cp_el_err('Hibás kérés.'); }
	$s = mb_substr(wp_json_encode($d), 0, 12000);
	$l = get_option('cp_diag', array()); if (!is_array($l)) { $l = array(); }
	$l[] = array('at' => gmdate('Y-m-d H:i:s'), 'd' => $s); $l = array_slice($l, -6);
	update_option('cp_diag', $l, false);
	wp_send_json_success(array('ok' => 1));
}
