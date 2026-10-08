<?php
/* Ügyfélportál – útvonalak a portál címén (alapból https://portal.eventcrazy.hu):
 *   /r/{token}      belépőlink: süti beállítása, átirányítás /rendezvenyem-re (a token eltűnik a címsorból)
 *   /rendezvenyem   az adatlap
 *   /               átirányítás vagy tájékoztató
 * A portál ugyanarra a WordPressre mutat; a bővítmény a host (és opcionálisan egy útvonal-előtag) alapján
 * szolgálja ki. A wp-json, wp-admin stb. kérések érintetlenül továbbmennek. */

if (!defined('ABSPATH')) { exit; }

add_action('init', 'cp_portal_route', 1);
function cp_portal_route() {
	if (is_admin() || wp_doing_ajax() || (function_exists('wp_doing_cron') && wp_doing_cron()) || isset($_GET['rest_route'])) { return; }
	$b = cp_portal_base();
	if ($b['host'] === '') { return; }
	$host = strtolower(preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : ''));
	if ($host !== $b['host']) { return; }
	$path = '/' . trim((string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/', PHP_URL_PATH), '/');
	if ($path === '/') { $path = ''; }

	if ($b['path'] !== '') {
		if ($path !== $b['path'] && strpos($path, $b['path'] . '/') !== 0) { return; }
		$sub = (string) substr($path, strlen($b['path']));
	} else {
		if (preg_match('#^/(wp-json|wp-admin|wp-login\.php|wp-content|wp-includes|wp-cron\.php|xmlrpc\.php|favicon\.ico)(/|$)#', $path)) { return; }
		if ($path === '/robots.txt') {
			header('Content-Type: text/plain; charset=utf-8');
			echo "User-agent: *\nDisallow: /\n";
			exit;
		}
		$sub = $path;
	}

	if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
	if (!defined('DONOTROCKETOPTIMIZE')) { define('DONOTROCKETOPTIMIZE', true); }
	if (!defined('DONOTMINIFY')) { define('DONOTMINIFY', true); }
	nocache_headers();
	header('X-Robots-Tag: noindex, nofollow', true);
	header('Referrer-Policy: no-referrer');
	header('X-Frame-Options: DENY');
	header('X-Content-Type-Options: nosniff');
	header('Content-Type: text/html; charset=utf-8');
	require_once __DIR__ . '/portal-tpl-page.php';

	if (!cp_portal_db_ready()) {
		cp_portal_render_message('Hamarosan', 'Az ügyfélportál még nem érhető el. Kérjük, próbáld újra később.', array('status' => 503));
		exit;
	}

	$post = isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST';

	if (preg_match('#^/r/([A-Za-z0-9_-]{20,64})$#', $sub, $m)) {
		cp_portal_route_token($m[1], $post);
		exit;
	}
	if ($sub === '/rendezvenyem') {
		if ($post && isset($_POST['cpp_newlink'])) { cp_portal_route_newlink(cp_portal_cookie_raw()); exit; }
		$s = cp_portal_session();
		if ($s) {
			cp_portal_render_form_page($s['portal'], $s['token']);
			exit;
		}
		$raw = cp_portal_cookie_raw();
		if ($raw !== '') {
			$tok = cp_portal_token_find($raw);
			$portal = $tok ? cp_portal_get($tok->portal_id) : null;
			cp_portal_render_invalid(cp_portal_token_problem($tok, $portal), $portal);
			exit;
		}
		cp_portal_render_message('Belépés a személyes linkkel', 'Az adatlapot az e-mailben kapott személyes linkkel tudod megnyitni. Ha nem találod a levelet, keresd az irodát, és küldünk újat.', array('status' => 401));
		exit;
	}
	if ($sub === '/kilepes') {
		cp_portal_cookie_clear();
		cp_portal_render_message('Kiléptél', 'Ezen az eszközön kiléptél a portálról. Újra belépni az e-mailben kapott linkkel tudsz.');
		exit;
	}
	if ($sub === '') {
		if (cp_portal_session()) { wp_redirect(cp_portal_url('/rendezvenyem'), 302); exit; }
		cp_portal_render_message('EventCrazy ügyfélportál', 'Az adatlapot az e-mailben kapott személyes linkkel tudod megnyitni.');
		exit;
	}
	cp_portal_render_message('Nincs ilyen oldal', 'Ez az oldal nem található. Az adatlapot az e-mailben kapott személyes linkkel tudod megnyitni.', array('status' => 404));
	exit;
}

function cp_portal_route_token($raw, $post) {
	if (!cp_portal_rate_ok('token', 10, MINUTE_IN_SECONDS)) {
		cp_portal_render_message('Túl sok próbálkozás', 'Kérjük, várj egy percet, és próbáld újra.', array('status' => 429));
		return;
	}
	if ($post && isset($_POST['cpp_newlink'])) { cp_portal_route_newlink($raw); return; }
	$tok = cp_portal_token_find($raw);
	$portal = $tok ? cp_portal_get($tok->portal_id) : null;
	$problem = cp_portal_token_problem($tok, $portal);
	if ($problem !== '') {
		cp_portal_access_log($portal ? $portal->id : null, 'link_' . $problem);
		cp_portal_render_invalid($problem, $portal);
		return;
	}
	global $wpdb;
	$wpdb->update(cp_portal_t('access_tokens'), array('last_used_at' => cp_portal_db_now()), array('id' => (int) $tok->id));
	cp_portal_access_log($portal->id, 'login');
	cp_portal_cookie_set($raw);
	wp_redirect(cp_portal_url('/rendezvenyem'), 302);
}

/* „Kérj új linket”: a régi (lejárt / visszavont) token alapján tudjuk, melyik portálhoz kell;
 * az új link a portál ügyfél-e-mail-címére megy, sosem a kérő által megadott címre. */
function cp_portal_route_newlink($raw) {
	$tok = cp_portal_token_find($raw);
	$portal = $tok ? cp_portal_get($tok->portal_id) : null;
	if (!$portal) {
		cp_portal_render_message('Érvénytelen link', 'Ezt a linket nem ismerjük fel. Keresd az irodát, és küldünk újat.', array('status' => 404));
		return;
	}
	if (cp_portal_link_expiry($portal->event_date) < time()) {
		cp_portal_render_message('A portál lezárult', 'Ennek a rendezvénynek a portálja már lezárult. Ha segíthetünk, keresd az irodát.');
		return;
	}
	if (!cp_portal_rate_ok('newlink', 5, HOUR_IN_SECONDS) || !cp_portal_rate_ok('newlink_p' . $portal->id, 3, HOUR_IN_SECONDS)) {
		cp_portal_render_message('Már küldtünk új linket', 'Nemrég küldtünk új linket – nézd meg a postafiókodat (a levélszemét mappát is). Ha nem érkezett meg, keresd az irodát.', array('status' => 429));
		return;
	}
	cp_portal_access_log($portal->id, 'newlink');
	$r = cp_portal_send_link_mail($portal, 'newlink');
	if (is_wp_error($r)) {
		cp_portal_render_message('Nem sikerült elküldeni', 'Most nem tudtunk új linket küldeni. Kérjük, keresd az irodát.', array('status' => 503));
		return;
	}
	cp_portal_render_message('Elküldtük az új linket', 'Az új belépőlinket elküldtük a(z) ' . cp_portal_mask_email($r) . ' címre. Nézd meg a postafiókodat (a levélszemét mappát is).');
}
