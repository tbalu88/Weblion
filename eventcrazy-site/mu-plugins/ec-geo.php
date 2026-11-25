<?php
/**
 * EventCrazy – AI-láthatóság (GEO) kiegészítések
 * 1) Szolgáltatásoldalak: Service strukturált adat (schema.org), a Yoast/Elementor Organization adatára (@id) hivatkozva –
 *    így a keresők és AI-motorok egyértelműen tudják, melyik oldal melyik szolgáltatás, ki nyújtja és hol.
 * 2) IndexNow: új vagy módosított oldal/bejegyzés azonnali bejelentése a Bingnek és társainak (a ChatGPT keresője és a
 *    Copilot a Bing indexéből dolgozik). A kulcsfájl a /<kulcs>.txt címen érhető el; első futáskor egyszer az összes
 *    közzétett oldal bejelentésre kerül.
 * 3) Cégadat: a Yoast Organization adatát egészíti ki (valódi logó, cégnév, elérhetőség, profilok, LocalBusiness típus),
 *    így egyetlen, teljes cégadat van az oldalon (korábban egy külön Elementor-kódrészlet adott egy második példányt).
 * Az angol (Polylang) szolgáltatásoldalak a magyar párjuk alapján angol Service adatot kapnak.
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
/* angol nevek és leírások (kulcs: a magyar oldal slugja) */
function ec_geo_services_en() {
	return array(
		'szelfigep-berles' => array('Photo booth rental', 'Photo booth rental', 'Photo booth rental for events with instant prints, a custom photo template and a choice of backgrounds.'),
		'eventcrazy-selfie-light' => array('Selfie Stand', 'Photo booth rental', 'A compact photo booth with a distinctive look, with or without a printer.'),
		'szelfidroid-szelfirobot-berles-ceges-rendezvenyre' => array('Selfie robot rental', 'Photo booth rental', 'Selfie robot rental for corporate events.'),
		'eventcrazy-selfie-tukor' => array('Selfie mirror rental', 'Photo booth rental', 'A full-length, interactive photo mirror with every feature of the photo booth.'),
		'eventcrazy-360-spinner-360-szelfi' => array('360 Spinner rental', '360 video booth rental', 'The Classic Spinner films groups of 3–5 people in slow-motion 360° video, ready to view and share instantly.'),
		'vip-spinner-berles-budapest' => array('VIP Spinner rental', '360 video booth rental', 'An interactive smart dance floor with disco lights, a fog machine and 360° selfie video.'),
		'eventcrazy-overhead-spinner' => array('Overhead Spinner rental', '360 video booth rental', 'Slow-motion 360° video in a 3-metre circle – a favourite for larger groups.'),
		'eventcrazy-greenbox-fotozas' => array('Greenbox photography', 'Event photography', 'Photos with interchangeable digital backgrounds using greenbox technology.'),
		'eventcrazy-ai-fotozas' => array('AI photography', 'AI photo booth', 'Face-swap AI photos: the guest’s face appears on the picture of a celebrity of their choice.'),
		'eventcrazy-ai-karikatura' => array('AI caricature', 'AI photo booth', 'Funny caricatures in seconds, made with artificial intelligence.'),
		'eventcrazy-digitalis-karikatura' => array('Digital caricature', 'Caricature drawing for events', 'Our artists draw personalised caricatures of the guests in minutes.'),
		'eventcrazy-rendezvenyfotozas' => array('Event photography', 'Event photography', 'Professional event photography for companies, weddings and exclusive events.'),
		'eventcrazy-rendezvenyfilm' => array('Event film', 'Event video production', 'Event films by a professional crew for weddings, corporate events and conferences.'),
		'eventcrazy-vr-elmeny-argumented-reality-vr-games' => array('VR experience', 'Event activity', 'A virtual reality experience for events in which participants actively play.'),
		'eventcrazy-karaoke' => array('Karaoke', 'Event activity', 'Karaoke for events.'),
		'eventcrazy-led-tancter' => array('LED dance floor rental', 'Event technology', 'An interactive smart LED dance floor for events.'),
		'eventcrazy-crazylight' => array('CrazyLight', 'Event activity', 'A reaction game for events, with a chance to beat the record.'),
		'eventcrazy-mindflex' => array('Mindflex', 'Event activity', 'A game controlled by brain power for corporate events, festivals and team building.'),
		'eventcrazy-crazycatch' => array('CrazyCatch', 'Event activity', 'A reflex game for events: catch it before it drops.'),
		'ulofoci-berles' => array('Seated football (Subsoccer) rental', 'Event activity', 'Seated football (Subsoccer) rental for events.'),
		'csocso-berles' => array('Foosball table rental', 'Event activity', 'Foosball table rental for events.'),
		'xxl-csocso-berles' => array('XXL foosball rental', 'Event activity', 'XXL foosball rental for events and team building.'),
		'smink-fonas-rendezvenyre' => array('Crazy Beauty – festival make-up and braiding', 'Event activity', 'Festival make-up, glitter tattoos and hair braiding on site at your event.'),
		'crazy-dj' => array('Crazy DJ', 'DJ service', 'A professional DJ for corporate parties, weddings and birthdays, with sound and lighting equipment and a microphone.'),
		'elozene-rendezvenyre' => array('Live music for events (acoustic duo)', 'Live music', 'Acoustic duo live music for events.'),
	);
}
add_action('wp_head', function () {
	if (is_admin() || !is_singular()) { return; }
	$id = get_queried_object_id();
	$lang = function_exists('pll_get_post_language') ? (string) pll_get_post_language($id) : 'hu';
	$hid = ($lang === 'en' && function_exists('pll_get_post')) ? (int) pll_get_post($id, 'hu') : $id;
	if (!$hid) { return; }
	$slug = trim((string) wp_parse_url(get_permalink($hid), PHP_URL_PATH), '/');
	$M = $lang === 'en' ? ec_geo_services_en() : ec_geo_services();
	if (!isset($M[$slug])) { return; }
	if ($lang !== 'en') { $lang = 'hu'; }
	list($name, $type, $desc) = $M[$slug];
	$url = $hid === $id ? home_url('/' . $slug . '/') : get_permalink($id);
	$o = array(
		'@context' => 'https://schema.org', '@type' => 'Service', '@id' => $url . '#service',
		'name' => $name, 'serviceType' => $type, 'description' => $desc, 'url' => $url,
		'provider' => array('@id' => home_url('/#organization')),
		'areaServed' => array('@type' => 'Country', 'name' => $lang === 'en' ? 'Hungary' : 'Magyarország'),
		'audience' => array('@type' => 'Audience', 'audienceType' => $lang === 'en' ? 'Corporate and private events: weddings, birthdays, company parties, team building, conferences, festivals' : 'Céges és privát rendezvények: esküvő, születésnap, céges buli, csapatépítő, konferencia, fesztivál'),
		'availableChannel' => array('@type' => 'ServiceChannel', 'serviceUrl' => $url, 'servicePhone' => '+36308441791', 'availableLanguage' => array('hu', 'en')),
		'inLanguage' => $lang,
	);
	echo '<script type="application/ld+json" id="ec-geo-service">' . wp_json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
}, 30);

/* ------------------------------------------------------------ 3) Cégadat (Yoast Organization kiegészítése) */
add_filter('wpseo_schema_organization', function ($d) {
	if (!is_array($d)) { return $d; }
	$logo = home_url('/wp-content/uploads/2021/09/eventcrazy_logo_hor.png');
	$d['@type'] = array('Organization', 'LocalBusiness');
	$d['name'] = 'EventCrazy';
	$d['legalName'] = 'Insignis Plus Kft.';
	$d['description'] = 'Prémium fotó- és élménymegoldások bérbeadása céges és privát rendezvényekre: fotóautomaták, 360°-os spinnerek, AI-fotózás, rendezvényfotózás és -film, interaktív aktivitások.';
	$d['logo'] = array('@type' => 'ImageObject', '@id' => home_url('/#/schema/logo/image/'), 'url' => $logo, 'contentUrl' => $logo, 'caption' => 'EventCrazy', 'inLanguage' => 'hu');
	$d['image'] = array('@id' => home_url('/#/schema/logo/image/'));
	$d['email'] = 'info@eventcrazy.hu';
	$d['telephone'] = '+36308441791';
	$d['taxID'] = '25095632-2-43';
	$d['address'] = array('@type' => 'PostalAddress', 'addressLocality' => 'Budapest', 'addressCountry' => 'HU');
	$d['areaServed'] = array('@type' => 'Country', 'name' => 'Magyarország');
	$d['contactPoint'] = array('@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => '+36308441791', 'email' => 'info@eventcrazy.hu', 'availableLanguage' => array('hu', 'en'));
	$d['knowsLanguage'] = array('hu', 'en');
	$d['sameAs'] = array_values(array_unique(array_merge((array) ($d['sameAs'] ?? array()), array('https://www.facebook.com/eventcrazyhun', 'https://www.instagram.com/eventcrazy_'))));
	return $d;
}, 20);

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
