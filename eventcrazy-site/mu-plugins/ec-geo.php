<?php
/**
 * EventCrazy – AI-láthatóság (GEO) kiegészítések
 * 1) Szolgáltatásoldalak: Service strukturált adat (schema.org), a Yoast/Elementor Organization adatára (@id) hivatkozva –
 *    így a keresők és AI-motorok egyértelműen tudják, melyik oldal melyik szolgáltatás, ki nyújtja és hol.
 * 2) IndexNow: új vagy módosított oldal/bejegyzés azonnali bejelentése a Bingnek és társainak (a ChatGPT keresője és a
 *    Copilot a Bing indexéből dolgozik). A kulcsfájl a /<kulcs>.txt címen érhető el; első futáskor egyszer az összes
 *    közzétett oldal bejelentésre kerül.
 * Kikapcsolás: a fájl törlése (vagy átnevezése).
 */
if (!defined('ABSPATH')) { exit; }

/* ------------------------------------------------------------ 1) Service séma */
function ec_geo_services() {
	return array(
		'szelfigep-berles' => array('Szelfigép, fotóautomata bérlés', 'Fotóautomata bérlés', 'Szelfigép bérlés rendezvényekre profi fotóélménnyel és azonnal nyomtatott képekkel, egyedi fotósablonnal és választható háttérrel.'),
		'eventcrazy-selfie-light' => array('SelfieStand', 'Fotóautomata bérlés', 'A szelfigép kompaktabb, egyedi külsejű változata, nyomtatóval vagy anélkül.'),
		'szelfidroid-szelfirobot-berles-ceges-rendezvenyre' => array('Szelfirobot bérlés', 'Fotóautomata bérlés', 'Szelfirobot (Szelfidroid) bérlés céges rendezvényekre.'),
		'eventcrazy-selfie-tukor' => array('Selfie tükör bérlés', 'Fotóautomata bérlés', 'Teljes alakos, interaktív fotótükör a szelfigép minden funkciójával.'),
		'eventcrazy-360-spinner-360-szelfi' => array('360 Spinner bérlés', '360 fokos videós fotóautomata bérlés', 'A Classic Spinner 3–5 fős társaságról készít lassított (slow motion) 360°-os videót, amely azonnal megtekinthető és továbbküldhető.'),
		'vip-spinner-berles-budapest' => array('VIP Spinner bérlés', '360 fokos videós fotóautomata bérlés', 'Interaktív okos táncparkett diszkófényekkel, füstgéppel és 360°-os szelfivideóval.'),
		'eventcrazy-overhead-spinner' => array('Overhead Spinner bérlés', '360 fokos videós fotóautomata bérlés', '3 méter átmérőjű körben készít lassított 360°-os felvételt – nagyobb létszámú társaságok kedvence.'),
		'eventcrazy-greenbox-fotozas' => array('Greenbox fotózás', 'Rendezvényfotózás', 'Fotózás cserélhető digitális hátterekkel, greenbox technológiával.'),
		'eventcrazy-ai-fotozas' => array('AI fotózás', 'AI fotóautomata', 'Arccserés AI fotózás: a vendég arca egy általa választott híresség képére kerül.'),
		'eventcrazy-ai-karikatura' => array('AI karikatúra', 'AI fotóautomata', 'Humoros karikatúra másodpercek alatt, mesterséges intelligenciával.'),
		'eventcrazy-digitalis-karikatura' => array('Digitális karikatúra', 'Karikatúrarajzolás rendezvényre', 'Művészeink percek alatt személyre szabott karikatúrát készítenek a vendégekről.'),
		'eventcrazy-rendezvenyfotozas' => array('Rendezvényfotózás', 'Rendezvényfotózás', 'Profi rendezvényfotózás cégeknek, esküvőkre és exkluzív eseményekre.'),
		'eventcrazy-rendezvenyfilm' => array('Rendezvényfilm', 'Rendezvényvideó készítés', 'Rendezvényfilm készítése profi csapattal esküvőkre, céges eseményekre és konferenciákra.'),
		'eventcrazy-vr-elmeny-argumented-reality-vr-games' => array('VR élmény', 'Rendezvényaktivitás', 'Virtuális valóság élmény rendezvényekre, amelyben a résztvevők aktívan játszanak.'),
		'eventcrazy-karaoke' => array('Karaoke', 'Rendezvényaktivitás', 'Karaoke rendezvényekre.'),
		'eventcrazy-led-tancter' => array('LED tánctér bérlés', 'Rendezvénytechnika', 'Interaktív okos LED táncparkett rendezvényekre.'),
		'eventcrazy-crazylight' => array('CrazyLight', 'Rendezvényaktivitás', 'Reflexjáték rendezvényekre, rekorddöntési lehetőséggel.'),
		'eventcrazy-mindflex' => array('Mindflex', 'Rendezvényaktivitás', 'Az agy erejével irányított játék céges rendezvényekre, fesztiválokra és csapatépítő programokra.'),
		'eventcrazy-crazycatch' => array('CrazyCatch', 'Rendezvényaktivitás', 'Reflexjáték rendezvényekre: kapd el, mielőtt leesik.'),
		'ulofoci-berles' => array('Ülőfoci (Subsoccer) bérlés', 'Rendezvényaktivitás', 'Ülőfoci (Subsoccer) bérlése rendezvényekre.'),
		'csocso-berles' => array('Csocsó bérlés', 'Rendezvényaktivitás', 'Csocsóasztal bérlése rendezvényekre.'),
		'xxl-csocso-berles' => array('XXL csocsó bérlés', 'Rendezvényaktivitás', 'XXL csocsó bérlése rendezvényekre és csapatépítőkre.'),
		'smink-fonas-rendezvenyre' => array('Crazy Beauty – smink és fonás rendezvényre', 'Rendezvényaktivitás', 'Fesztiválsmink, csillámtetoválás és hajfonás a rendezvény helyszínén.'),
		'crazy-dj' => array('Crazy DJ', 'DJ szolgáltatás', 'Profi DJ céges partira, esküvőre, születésnapra, hang- és fénytechnikával, mikrofonnal.'),
		'elozene-rendezvenyre' => array('Élőzene rendezvényre (akusztikus duó)', 'Élőzene', 'Akusztikus duó élőzene rendezvényekre.'),
	);
}
add_action('wp_head', function () {
	if (is_admin() || !is_singular()) { return; }
	$slug = trim((string) wp_parse_url(get_permalink(), PHP_URL_PATH), '/');
	$M = ec_geo_services();
	if (!isset($M[$slug])) { return; }
	list($name, $type, $desc) = $M[$slug];
	$url = home_url('/' . $slug . '/');
	$o = array(
		'@context' => 'https://schema.org', '@type' => 'Service', '@id' => $url . '#service',
		'name' => $name, 'serviceType' => $type, 'description' => $desc, 'url' => $url,
		'provider' => array('@id' => home_url('/#organization')),
		'areaServed' => array('@type' => 'Country', 'name' => 'Magyarország'),
		'audience' => array('@type' => 'Audience', 'audienceType' => 'Céges és privát rendezvények: esküvő, születésnap, céges buli, csapatépítő, konferencia, fesztivál'),
		'availableChannel' => array('@type' => 'ServiceChannel', 'serviceUrl' => $url, 'servicePhone' => '+36308441791', 'availableLanguage' => array('hu', 'en')),
		'inLanguage' => 'hu',
	);
	echo '<script type="application/ld+json" id="ec-geo-service">' . wp_json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
}, 30);

/* ------------------------------------------------------------ 2) IndexNow */
function ec_geo_key() {
	$k = get_option('ec_indexnow_key');
	if (!is_string($k) || !preg_match('/^[a-f0-9]{32}$/', $k)) { $k = bin2hex(random_bytes(16)); update_option('ec_indexnow_key', $k, true); wp_schedule_single_event(time() + 120, 'ec_geo_indexnow_all'); }
	return $k;
}
/* a kulcsfájl: /<kulcs>.txt (az IndexNow ezzel ellenőrzi, hogy a webhely tulajdonosa küldi a bejelentést) */
add_action('init', function () {
	$p = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
	if (!preg_match('#^/([a-f0-9]{32})\.txt$#', $p, $m)) { return; }
	if (!hash_equals(ec_geo_key(), $m[1])) { return; }
	nocache_headers(); header('Content-Type: text/plain; charset=utf-8'); echo $m[1]; exit;
}, 0);
function ec_geo_ping($urls) {
	$urls = array_values(array_unique(array_filter($urls, function ($u) { return is_string($u) && strpos($u, home_url('/')) === 0; })));
	if (!$urls) { return; }
	$k = ec_geo_key();
	$host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
	foreach (array_chunk($urls, 9000) as $chunk) {
		$r = wp_remote_post('https://api.indexnow.org/indexnow', array('timeout' => 15, 'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
			'body' => wp_json_encode(array('host' => $host, 'key' => $k, 'keyLocation' => home_url('/' . $k . '.txt'), 'urlList' => $chunk))));
		update_option('ec_indexnow_last', array('at' => time(), 'n' => count($chunk), 'code' => is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_response_code($r)), false);
	}
}
/* közzétételkor / módosításkor: a bejegyzés címe (10 percen belül ugyanazt csak egyszer) */
add_action('transition_post_status', function ($new, $old, $post) {
	if ($new !== 'publish' || wp_is_post_revision($post) || !in_array($post->post_type, array('page', 'post'), true)) { return; }
	if (get_transient('ec_inx_' . $post->ID)) { return; }
	set_transient('ec_inx_' . $post->ID, 1, 600);
	$u = get_permalink($post);
	add_action('shutdown', function () use ($u) { ec_geo_ping(array($u)); });
}, 10, 3);
/* első futáskor egyszer: minden közzétett oldal és bejegyzés */
add_action('ec_geo_indexnow_all', function () {
	$ids = get_posts(array('post_type' => array('page', 'post'), 'post_status' => 'publish', 'numberposts' => 2000, 'fields' => 'ids'));
	ec_geo_ping(array_merge(array(home_url('/')), array_map('get_permalink', $ids)));
});
add_action('init', 'ec_geo_key');
