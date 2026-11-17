<?php
/**
 * Plugin Name: Crazy – sablonszerkesztő mobiljavítás
 * Description: A „Választható fotó templatek” oldal élő sablonszerkesztője (#cpprev) mobilon ne szélesítse ki az oldalt:
 *   a lenyíló doboz rácsának oszlopa a képernyő szélessége (minmax(0,1fr)) – eddig a vízszintesen görgethető sablonsor
 *   teljes hossza (~1700 px) szabta meg, és a telefon az egész oldalt kicsinyítve mutatta. 2026-11-17.
 */
if (!defined('ABSPATH')) { exit; }
add_action('wp_head', function () {
	echo '<style id="cp-sablon-mobilfix">#cpprev .cpp-body{grid-template-columns:minmax(0,1fr)}#cpprev .cpp-body-in,#cpprev .cpp-pick,#cpprev .cpp-grid>*{min-width:0}'
		/* 2026-11-17: mobilon a felső blokk (cím) ne tapadjon a fejléc logójához; a blokk alatti fehér csík (6 px margó) eltűnik */
		. '@media (max-width:767px){html body.elementor-page-580 .elementor-element.elementor-element-a577341{padding-top:184px!important;padding-bottom:64px!important;margin-bottom:0!important}}</style>' . "\n";
}, 99);
/* egyszeri: a sablonoldal gyorsítótárazott (WP Rocket) változata ürül, hogy a javítás azonnal látsszon */
add_action('init', function () {
	if (get_option('cp_smf_purged2') || !function_exists('rocket_clean_files')) { return; }
	update_option('cp_smf_purged2', 1, false);
	rocket_clean_files(array(home_url('/valaszthato-foto-templatek/')));
}, 99);
