<?php
/**
 * Plugin Name: Crazy Foglaltsági Panel
 * Description: Belső, jelszóval védett panel a Salesmate foglalásokhoz: naptár és szabad gépek, háttér-foglaltság, rendezvény-követés (szerződés, template, háttér, díjbekérő, galéria), gyors ellenőrzés válaszlevéllel, napi munkalap, kezelői elszámolás kezelői adatlappal, ügyfélportál (rendezvény-adatlap), sablonkészítő (lyukasztott PNG a fotós szoftvernek).
 * Version: 1.13.1
 * Author: Crazyphoto / EventCrazy
 * Requires PHP: 7.4
 * Text Domain: crazy-panel
 */

if (!defined('ABSPATH')) { exit; }

define('CP_PANEL_VER', '1.13.1');
define('CP_PANEL_TRACK', 'cp_pt_');
define('CP_PANEL_OPT', 'cp_panel_settings');
define('CP_PANEL_SUBS', 'cp_panel_subs');
define('CP_PANEL_COOKIE', 'cp_panel_auth');

/* ---------------------------------------------------------------------
 * Beállítások
 * ------------------------------------------------------------------- */

function cp_panel_defaults() {
	return array(
		'slug'          => 'crazy-panel',
		'linkname'      => 'studioframes.salesmate.io',
		'token'         => '',
		'pw_hash'       => '',
		'pw_ver'        => '1',
		'pipeline'      => 'Crazyphoto megrendelés',
		'booked_stages' => "Szerződés kiküldve\nSzerződés aláírva\nRendezvény felkészül\nRendezvény lement (G&R)\nPénzre várunk",
		'pending_stages'=> "Érdeklődő / Megrendelő\nAjánlat kiküldve",
		'signed_stages' => "Szerződés aláírva\nRendezvény felkészül\nRendezvény lement (G&R)\nPénzre várunk",
		'cap_selfie'    => 7,
		'cap_spin'      => 2,
		'subs_default'  => 0,
		'f_date'        => 'dateCustomField1',
		'f_time'        => '',
		'f_bg'          => 'textCustomField5',
		'f_selfie'      => 'textCustomField18',
		'f_spin'        => 'textCustomField14',
		'f_loc'         => 'textCustomField1',
		'f_guests'      => 'intCustomField1',
		'f_note'        => 'textCustomField3',
		'f_extra'       => '',
		'f_paid'        => '',
		'f_hx'          => '',
		'stage_write'   => 1,
		'backgrounds'   => "Absztrakt\nArany\nArany 2\nArt Deco\nBokeh\nBokeh 2\nBokeh 3\nBokeh 4\nBokeh 5\nBőr\nCandy\nDeszka\nDeszka 2\nEsküvő\nFehér\nFények\nGatsby\nGraffiti\nHollywood\nKarácsony\nKarácsony 2\nKarácsony 3\nKarácsony 4\nLevelek\nLevelek 2\nMárvány\nPezsgő\nPink\nPolygon\nPolygon 2\nRosé\nSelyem\nShimmer\nTégla\nVárosi\nZebra\nZöld",
		'cache_min'     => 5,
		'bg_url'        => 'https://crazyphoto.hu/valaszthato-hatterek/',
		'bg_write'      => 1,
		'search_mode'   => 'auto',
		'reply_sign'    => "Üdvözlettel:\nCrazyphoto csapat\nhttps://crazyphoto.hu",
		'openai_key'    => '',
		'ai_model'      => 'gpt-image-1',
		'ai_quality'    => 'medium',
		'ai_daily'      => 30,
	);
}

function cp_panel_opts() {
	$o = get_option(CP_PANEL_OPT, array());
	if (!is_array($o)) { $o = array(); }
	return array_merge(cp_panel_defaults(), $o);
}

function cp_panel_lines($s) {
	$out = array();
	foreach (preg_split('/\r\n|\r|\n/', (string) $s) as $l) {
		$l = trim($l);
		if ($l !== '') { $out[] = $l; }
	}
	return $out;
}

/* ---------------------------------------------------------------------
 * Útvonal: /crazy-panel/ (téma nélküli, önálló oldal)
 * ------------------------------------------------------------------- */

add_action('init', 'cp_panel_route', 99);
function cp_panel_route() {
	if (is_admin() || wp_doing_ajax()) { return; }
	$o = cp_panel_opts();
	$slug = trim($o['slug'], '/');
	if ($slug === '') { return; }
	$path = trim((string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '', PHP_URL_PATH), '/');
	if ($path !== $slug) { return; }

	if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
	if (!defined('DONOTROCKETOPTIMIZE')) { define('DONOTROCKETOPTIMIZE', true); }
	if (!defined('DONOTMINIFY')) { define('DONOTMINIFY', true); }
	nocache_headers();
	header('X-Robots-Tag: noindex, nofollow', true);
	header('Content-Type: text/html; charset=utf-8');

	// kezelői adatlap (Elszámolás): jelszó nélkül, aláírt linkkel
	if (isset($_GET['k'])) { cp_el_render_form((string) wp_unslash($_GET['k'])); exit; }

	$err = '';
	if (isset($_POST['cp_pw'])) {
		$err = cp_panel_try_login(wp_unslash($_POST['cp_pw']));
		if ($err === '') {
			wp_safe_redirect(home_url('/' . $slug . '/'));
			exit;
		}
	}
	if (isset($_GET['kilep'])) {
		setcookie(CP_PANEL_COOKIE, '', time() - 3600, '/', '', is_ssl(), true);
		wp_safe_redirect(home_url('/' . $slug . '/'));
		exit;
	}
	if (!cp_panel_authed()) {
		cp_panel_render_login($err);
		exit;
	}
	cp_panel_render_app();
	exit;
}

/* ---------------------------------------------------------------------
 * Hitelesítés (közös jelszó, aláírt süti)
 * ------------------------------------------------------------------- */

function cp_panel_client_ip() {
	return isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-fA-F:.]/', '', $_SERVER['REMOTE_ADDR']) : '0';
}

function cp_panel_sig($exp) {
	$o = cp_panel_opts();
	return hash_hmac('sha256', $exp . '|' . $o['pw_ver'] . '|' . substr($o['pw_hash'], 0, 20), wp_salt('auth'));
}

function cp_panel_authed() {
	if (empty($_COOKIE[CP_PANEL_COOKIE])) { return false; }
	$o = cp_panel_opts();
	if ($o['pw_hash'] === '') { return false; }
	$parts = explode('|', (string) wp_unslash($_COOKIE[CP_PANEL_COOKIE]));
	if (count($parts) !== 2) { return false; }
	$exp = (int) $parts[0];
	if ($exp < time()) { return false; }
	return hash_equals(cp_panel_sig($exp), $parts[1]);
}

function cp_panel_try_login($pw) {
	$o = cp_panel_opts();
	if ($o['pw_hash'] === '') { return 'A panel jelszava még nincs beállítva (WordPress admin → Beállítások → Foglaltsági panel).'; }
	$key = 'cp_panel_fail_' . md5(cp_panel_client_ip());
	$fails = (int) get_transient($key);
	if ($fails >= 6) { return 'Túl sok sikertelen próbálkozás. Próbáld újra 15 perc múlva.'; }
	require_once ABSPATH . WPINC . '/class-phpass.php';
	if (!wp_check_password($pw, $o['pw_hash'])) {
		set_transient($key, $fails + 1, 15 * MINUTE_IN_SECONDS);
		return 'Hibás jelszó.';
	}
	delete_transient($key);
	$exp = time() + 30 * DAY_IN_SECONDS;
	setcookie(CP_PANEL_COOKIE, $exp . '|' . cp_panel_sig($exp), $exp, '/', '', is_ssl(), true);
	return '';
}

function cp_panel_csrf() {
	return isset($_COOKIE[CP_PANEL_COOKIE]) ? substr(hash_hmac('sha256', 'csrf|' . wp_unslash($_COOKIE[CP_PANEL_COOKIE]), wp_salt('nonce')), 0, 32) : '';
}

/* ---------------------------------------------------------------------
 * Salesmate kliens
 * ------------------------------------------------------------------- */

function cp_sm_request($method, $path, $body = null) {
	$o = cp_panel_opts();
	if ($o['token'] === '') { return new WP_Error('cp_no_token', 'Nincs megadva Salesmate API kulcs.'); }
	$url = 'https://' . $o['linkname'] . $path;
	$args = array(
		'method'  => $method,
		'timeout' => 30,
		'headers' => array(
			'accessToken'  => $o['token'],
			'sessionToken' => $o['token'],
			'x-linkname'   => $o['linkname'],
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		),
	);
	if ($body !== null) { $args['body'] = wp_json_encode($body); }
	$res = wp_remote_request($url, $args);
	if (is_wp_error($res)) { return $res; }
	$code = wp_remote_retrieve_response_code($res);
	$raw = wp_remote_retrieve_body($res);
	$json = json_decode($raw, true);
	if ($code < 200 || $code >= 300) {
		return new WP_Error('cp_http', 'Salesmate hiba (' . $code . '): ' . mb_substr($raw, 0, 300));
	}
	return is_array($json) ? $json : new WP_Error('cp_json', 'Érvénytelen válasz: ' . mb_substr($raw, 0, 300));
}

/* v1.8.2: a rendezvényfotózás és a rendezvényfilm nem a panel része (külön elszámolás / követés) */
function cp_svc_is_excluded($name) {
	$n = cp_panel_lc(trim((string) $name));
	return $n !== '' && (bool) preg_match('/^rendezv[ée]ny\s*-?\s*(fot[óo]|film|vide[óo])/u', $n);
}

/* igaz, ha a deal minden megadott szolgáltatása kizárt (pl. csak rendezvényfotózás) – vegyes dealt nem rejtünk el */
function cp_svc_only_excluded($vals) {
	$any = false;
	foreach ((array) $vals as $v) {
		if (is_array($v) && isset($v[0])) { $v = implode(',', array_map('cp_panel_str', $v)); } /* többválasztós mező listaként */
		foreach (preg_split('/[,;|·+\n]+/u', cp_panel_str($v)) as $t) {
			$t = trim($t);
			if ($t === '') { continue; }
			if (!cp_svc_is_excluded($t)) { return false; }
			$any = true;
		}
	}
	return $any;
}

function cp_sm_fields() {
	$o = cp_panel_opts();
	$f = array('deal.id', 'deal.title', 'deal.stage', 'deal.status', 'deal.pipeline', 'deal.dealValue', 'deal.owner', 'deal.primaryContact');
	foreach (array('f_date', 'f_time', 'f_bg', 'f_selfie', 'f_spin', 'f_loc', 'f_guests', 'f_note', 'f_paid', 'f_hx') as $k) {
		if ($o[$k] !== '') { $f[] = 'deal.' . $o[$k]; }
	}
	foreach (cp_panel_extra_fields() as $key => $label) { $f[] = 'deal.' . $key; }
	$e = cp_el_opts();
	foreach (array('f_svc', 'f_svc2') as $k) { if ($e[$k] !== '') { $f[] = 'deal.' . $e[$k]; } }
	return array_values(array_unique($f));
}

function cp_panel_extra_fields() {
	$o = cp_panel_opts();
	$out = array();
	foreach (cp_panel_lines($o['f_extra']) as $l) {
		$p = explode('=', $l, 2);
		$key = trim($p[0]);
		if ($key === '') { continue; }
		$out[$key] = isset($p[1]) ? trim($p[1]) : $key;
	}
	return $out;
}

function cp_sm_rows($json) {
	if (isset($json['Data']['data']) && is_array($json['Data']['data'])) { return $json['Data']['data']; }
	if (isset($json['Data']) && is_array($json['Data']) && isset($json['Data'][0])) { return $json['Data']; }
	if (isset($json['data']) && is_array($json['data'])) { return $json['data']; }
	return array();
}

function cp_sm_search_body($rules, $from_rows = 0, $fields = null) {
	return array(
		'displayingFields' => $fields ? $fields : cp_sm_fields(),
		'filterQuery'      => array('group' => array('operator' => 'AND', 'rules' => $rules)),
		'sort'             => array('fieldName' => 'deal.id', 'order' => 'desc'),
		'moduleId'         => 4,
		'reportType'       => 'get_data',
		'getRecordsCount'  => true,
	);
}

function cp_sm_date_rules($from, $to, $variant) {
	$o = cp_panel_opts();
	$fn = 'deal.' . $o['f_date'];
	$field = array('fieldName' => $fn, 'type' => 'Date');
	if ($variant === 'between') {
		return array(array('moduleName' => 'Deal', 'field' => $field, 'condition' => 'BETWEEN', 'data' => array($from, $to), 'eventType' => 'Date'));
	}
	if ($variant === 'between_str') {
		return array(array('moduleName' => 'Deal', 'field' => $field, 'condition' => 'BETWEEN', 'data' => $from . ',' . $to, 'eventType' => 'Date'));
	}
	// after/before
	$d1 = gmdate('Y-m-d', strtotime($from . ' -1 day'));
	$d2 = gmdate('Y-m-d', strtotime($to . ' +1 day'));
	return array(
		array('moduleName' => 'Deal', 'field' => $field, 'condition' => 'IS_AFTER', 'data' => $d1, 'eventType' => 'Date'),
		array('moduleName' => 'Deal', 'field' => $field, 'condition' => 'IS_BEFORE', 'data' => $d2, 'eventType' => 'Date'),
	);
}

/* Lekérdezés egy dátumtartományra; visszaadja a nyers sorokat. */
function cp_sm_fetch_range($from, $to, &$info = null, $fields = null) {
	$o = cp_panel_opts();
	$info = array('mode' => '', 'calls' => 0);
	$modes = $o['search_mode'] === 'auto' ? array('after_before', 'between', 'between_str', 'scan') : array($o['search_mode']);
	$last_err = null;
	foreach ($modes as $mode) {
		$rows = array();
		if ($mode === 'scan') {
			$rules = array();
			$max_pages = 24;
		} else {
			$rules = cp_sm_date_rules($from, $to, $mode);
			$max_pages = 12;
		}
		$ok = true;
		for ($p = 0; $p < $max_pages; $p++) {
			$json = cp_sm_request('POST', '/apis/deal/v4/search?rows=250&from=' . ($p * 250), cp_sm_search_body($rules, 0, $fields));
			$info['calls']++;
			if (is_wp_error($json)) { $ok = false; $last_err = $json; break; }
			$batch = cp_sm_rows($json);
			$rows = array_merge($rows, $batch);
			if (count($batch) < 250) { break; }
		}
		if (!$ok) { continue; }
		// ellenőrzés: a szűrt mód tényleg szűr-e (ha minden sor kívül esik, gyanús)
		$in = 0;
		foreach ($rows as $r) {
			$d = cp_panel_norm_date(cp_panel_get($r, $o['f_date']));
			if ($d !== '' && $d >= $from && $d <= $to) { $in++; }
		}
		if ($mode !== 'scan' && count($rows) > 0 && $in === 0) { continue; }
		$info['mode'] = $mode;
		return $rows;
	}
	return $last_err ? $last_err : new WP_Error('cp_empty', 'Nem sikerült lekérdezni a dealeket.');
}

/* ---------------------------------------------------------------------
 * Normalizálás
 * ------------------------------------------------------------------- */

function cp_panel_get($row, $key) {
	if ($key === '' || !is_array($row)) { return null; }
	if (array_key_exists($key, $row)) { return $row[$key]; }
	if (array_key_exists('deal.' . $key, $row)) { return $row['deal.' . $key]; }
	if (isset($row['deal']) && is_array($row['deal']) && array_key_exists($key, $row['deal'])) { return $row['deal'][$key]; }
	return null;
}

function cp_panel_str($v) {
	if (is_array($v)) {
		foreach (array('name', 'title', 'label', 'value', 'firstName') as $k) {
			if (isset($v[$k]) && is_scalar($v[$k])) {
				$s = (string) $v[$k];
				if ($k === 'firstName' && isset($v['lastName'])) { $s = trim($v['lastName'] . ' ' . $v['firstName']); }
				return trim($s);
			}
		}
		return '';
	}
	return is_scalar($v) ? trim((string) $v) : '';
}

function cp_panel_norm_date($v) {
	if ($v === null || $v === '') { return ''; }
	$tz = new DateTimeZone('Europe/Budapest');
	try {
		if (is_numeric($v)) {
			$ts = (float) $v;
			if ($ts > 100000000000) { $ts = $ts / 1000; }
			$d = new DateTime('@' . (int) round($ts));
			$d->setTimezone($tz);
			return $d->format('Y-m-d');
		}
		$s = (string) $v;
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s)) { return $s; }
		if (preg_match('/^(\d{4})[.\/](\d{1,2})[.\/](\d{1,2})/', $s, $m)) { return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]); }
		$d = new DateTime($s);
		$d->setTimezone($tz);
		return $d->format('Y-m-d');
	} catch (Exception $e) {
		return '';
	}
}

function cp_panel_norm_time($v) {
	if ($v === null || $v === '') { return ''; }
	if (is_numeric($v)) {
		$ts = (float) $v;
		if ($ts > 100000000000) { $ts = $ts / 1000; }
		$d = new DateTime('@' . (int) round($ts));
		$d->setTimezone(new DateTimeZone('Europe/Budapest'));
		return $d->format('H:i');
	}
	$s = (string) $v;
	if (preg_match('/(\d{1,2}):(\d{2})/', $s, $m)) { return sprintf('%02d:%02d', $m[1], $m[2]); }
	return $s;
}

function cp_panel_normalize($rows) {
	$o = cp_panel_opts();
	$booked = array_map('mb_strtolower', cp_panel_lines($o['booked_stages']));
	$pending = array_map('mb_strtolower', cp_panel_lines($o['pending_stages']));
	// ügyfélportál: az „Adatlap kiküldve” szakasz (a szerződés előtt) ajánlatként látszik
	if (function_exists('cp_portal_sent_stage') && cp_portal_sent_stage() !== '') { $pending[] = mb_strtolower(cp_portal_sent_stage()); }
	$pipeline = mb_strtolower(trim($o['pipeline']));
	$extra = cp_panel_extra_fields();
	$out = array();
	$seen = array();
	foreach ($rows as $r) {
		$id = cp_panel_str(cp_panel_get($r, 'id'));
		if ($id === '' || isset($seen[$id])) { continue; }
		$seen[$id] = 1;
		$pl = mb_strtolower(cp_panel_str(cp_panel_get($r, 'pipeline')));
		if ($pipeline !== '' && $pl !== '' && $pl !== $pipeline) { continue; }
		$status = mb_strtolower(cp_panel_str(cp_panel_get($r, 'status')));
		if ($status === 'lost') { continue; }
		$stage = cp_panel_str(cp_panel_get($r, 'stage'));
		$st = mb_strtolower($stage);
		$state = '';
		if ($status === 'won' || in_array($st, $booked, true)) { $state = 'booked'; }
		elseif (in_array($st, $pending, true)) { $state = 'pending'; }
		else { continue; }
		$date = cp_panel_norm_date(cp_panel_get($r, $o['f_date']));
		if ($date === '') { continue; }
		$sp = cp_panel_str(cp_panel_get($r, $o['f_selfie']));
		$spin = cp_panel_str(cp_panel_get($r, $o['f_spin']));
		$eo = cp_el_opts();
		if (cp_svc_only_excluded(array($sp, $spin, $eo['f_svc'] !== '' ? cp_panel_get($r, $eo['f_svc']) : '', $eo['f_svc2'] !== '' ? cp_panel_get($r, $eo['f_svc2']) : ''))) { continue; }
		$pc = cp_panel_get($r, 'primaryContact');
		$contact = array('name' => '', 'phone' => '', 'email' => '');
		if (is_array($pc)) {
			$contact['name'] = trim(cp_panel_str(isset($pc['name']) ? $pc['name'] : '') ?: trim((isset($pc['lastName']) ? $pc['lastName'] : '') . ' ' . (isset($pc['firstName']) ? $pc['firstName'] : '')));
			foreach (array('mobile', 'phone', 'otherPhone') as $k) { if (!empty($pc[$k]) && $contact['phone'] === '') { $contact['phone'] = cp_panel_str($pc[$k]); } }
			if (!empty($pc['email'])) { $contact['email'] = cp_panel_str($pc['email']); }
		} elseif (is_scalar($pc)) {
			$contact['name'] = (string) $pc;
		}
		foreach (array('primaryContact.name' => 'name', 'primaryContact.mobile' => 'phone', 'primaryContact.phone' => 'phone', 'primaryContact.email' => 'email') as $k => $t) {
			if ($contact[$t] === '' && isset($r[$k])) { $contact[$t] = cp_panel_str($r[$k]); }
		}
		$ex = array();
		foreach ($extra as $key => $label) {
			$v = cp_panel_str(cp_panel_get($r, $key));
			if ($v !== '') { $ex[] = array($label, $v); }
		}
		$out[] = array(
			'id'      => $id,
			'title'   => cp_panel_str(cp_panel_get($r, 'title')),
			'stage'   => $stage,
			'state'   => $state,
			'won'     => $status === 'won',
			'date'    => $date,
			'time'    => $o['f_time'] !== '' ? cp_panel_norm_time(cp_panel_get($r, $o['f_time'])) : '',
			'selfie'  => $sp,
			'spin'    => $spin,
			'kind'    => ($spin !== '' && $sp === '') ? 'spin' : (($spin !== '' && $sp !== '') ? 'both' : 'selfie'),
			'bg'      => cp_panel_str(cp_panel_get($r, $o['f_bg'])),
			'loc'     => cp_panel_str(cp_panel_get($r, $o['f_loc'])),
			'guests'  => cp_panel_str(cp_panel_get($r, $o['f_guests'])),
			'note'    => cp_panel_str(cp_panel_get($r, $o['f_note'])),
			'paid'    => $o['f_paid'] !== '' ? cp_panel_str(cp_panel_get($r, $o['f_paid'])) : '',
			'hx'      => $o['f_hx'] !== '' ? cp_panel_hx_parse(cp_panel_get($r, $o['f_hx'])) : '',
			'value'   => cp_panel_str(cp_panel_get($r, 'dealValue')),
			'owner'   => cp_panel_str(cp_panel_get($r, 'owner')),
			'contact' => $contact,
			'extra'   => $ex,
		);
	}
	usort($out, function ($a, $b) { return strcmp($a['date'] . $a['time'], $b['date'] . $b['time']); });
	return $out;
}

/* ---------------------------------------------------------------------
 * AJAX: adatok, alvállalkozói slotok
 * ------------------------------------------------------------------- */

add_action('wp_ajax_nopriv_cp_panel_data', 'cp_panel_ajax_data');
add_action('wp_ajax_cp_panel_data', 'cp_panel_ajax_data');
function cp_panel_ajax_data() {
	nocache_headers();
	if (!cp_panel_authed() || !hash_equals(cp_panel_csrf(), isset($_REQUEST['t']) ? (string) wp_unslash($_REQUEST['t']) : '')) {
		wp_send_json_error(array('msg' => 'auth'), 403);
	}
	$from = isset($_REQUEST['from']) ? preg_replace('/[^0-9-]/', '', wp_unslash($_REQUEST['from'])) : '';
	$to = isset($_REQUEST['to']) ? preg_replace('/[^0-9-]/', '', wp_unslash($_REQUEST['to'])) : '';
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
		wp_send_json_error(array('msg' => 'Hibás dátumtartomány.'), 400);
	}
	if ((strtotime($to) - strtotime($from)) > 120 * DAY_IN_SECONDS) {
		wp_send_json_error(array('msg' => 'Legfeljebb 120 napos tartomány kérhető le.'), 400);
	}
	$o = cp_panel_opts();
	$ck = 'cp_panel_d_' . md5($from . $to . wp_json_encode(cp_sm_fields()) . $o['pipeline'] . $o['booked_stages'] . $o['pending_stages']);
	$force = !empty($_REQUEST['refresh']);
	$cached = $force ? false : get_transient($ck);
	if ($cached === false) {
		$info = null;
		$rows = cp_sm_fetch_range($from, $to, $info);
		if (is_wp_error($rows)) {
			wp_send_json_error(array('msg' => $rows->get_error_message()), 502);
		}
		$events = array_values(array_filter(cp_panel_normalize($rows), function ($e) use ($from, $to) { return $e['date'] >= $from && $e['date'] <= $to; }));
		$cached = array('events' => $events, 'at' => time(), 'mode' => $info['mode'], 'calls' => $info['calls']);
		set_transient($ck, $cached, max(1, (int) $o['cache_min']) * MINUTE_IN_SECONDS);
	}
	$subs = get_option(CP_PANEL_SUBS, array());
	if (!is_array($subs)) { $subs = array(); }
	$sub_out = array();
	foreach ($subs as $d => $n) { if ($d >= $from && $d <= $to) { $sub_out[$d] = (int) $n; } }
	wp_send_json_success(array(
		'events' => $cached['events'],
		'at'     => $cached['at'],
		'mode'   => $cached['mode'],
		'subs'   => (object) $sub_out,
		'track'  => (object) array_intersect_key(cp_panel_track_all(), array_flip(array_map('strval', wp_list_pluck($cached['events'], 'id')))),
		'portal' => function_exists('cp_portal_panel_map') ? cp_portal_panel_map(wp_list_pluck($cached['events'], 'id')) : new stdClass(),
	));
}

add_action('wp_ajax_nopriv_cp_panel_subs', 'cp_panel_ajax_subs');
add_action('wp_ajax_cp_panel_subs', 'cp_panel_ajax_subs');
function cp_panel_ajax_subs() {
	nocache_headers();
	if (!cp_panel_authed() || !hash_equals(cp_panel_csrf(), isset($_POST['t']) ? (string) wp_unslash($_POST['t']) : '')) {
		wp_send_json_error(array('msg' => 'auth'), 403);
	}
	$d = isset($_POST['date']) ? preg_replace('/[^0-9-]/', '', wp_unslash($_POST['date'])) : '';
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { wp_send_json_error(array('msg' => 'Hibás dátum.'), 400); }
	$n = isset($_POST['n']) ? max(0, min(50, (int) $_POST['n'])) : 0;
	$subs = get_option(CP_PANEL_SUBS, array());
	if (!is_array($subs)) { $subs = array(); }
	$o = cp_panel_opts();
	if ($n === (int) $o['subs_default']) { unset($subs[$d]); } else { $subs[$d] = $n; }
	// régi bejegyzések takarítása
	$limit = gmdate('Y-m-d', time() - 60 * DAY_IN_SECONDS);
	foreach ($subs as $k => $v) { if ($k < $limit) { unset($subs[$k]); } }
	update_option(CP_PANEL_SUBS, $subs, false);
	wp_send_json_success(array('date' => $d, 'n' => $n));
}


/* ---------------------------------------------------------------------
 * Rendezvény-követés (Követés fül) – közös, szerveren tárolt pipák
 * Dealenként egy nem-autoload opció: cp_pt_{id}
 * ------------------------------------------------------------------- */

function cp_panel_track_fields() {
	return array(
		'sz'   => array('', '0', '1'),          // szerződés (üres = automatikus a szakasz alapján)
		'tp'   => array('', '0', '1'),          // template kész
		'bg'   => array('', '0', '1'),          // háttér rendben (egyedi kész / megbízótól megjött / nem kell)
		'dj'   => array('', 'ok', '8', '30'),   // díjbekérő
		'gl'   => array('', '0', '1'),          // galéria elküldve
		'hx'   => cp_el_hx_values(), // hosszabbítás (óra) – rendezvény utáni info
		'note' => null,                          // szabad szöveg
	);
}

function cp_panel_track_all() {
	global $wpdb;
	$like = $wpdb->esc_like(CP_PANEL_TRACK) . '%';
	$rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like), ARRAY_A);
	$out = array();
	foreach ((array) $rows as $r) {
		$id = substr($r['option_name'], strlen(CP_PANEL_TRACK));
		$v = maybe_unserialize($r['option_value']);
		if ($id !== '' && is_array($v)) { $out[$id] = $v; }
	}
	return $out;
}

function cp_panel_track_range($from, $to) {
	// tág ablak: ha egy rendezvény dátuma közben módosult, a pipái akkor is megjönnek
	$f = gmdate('Y-m-d', strtotime($from) - 200 * DAY_IN_SECONDS);
	$t = gmdate('Y-m-d', strtotime($to) + 200 * DAY_IN_SECONDS);
	$out = array();
	foreach (cp_panel_track_all() as $id => $v) {
		$d = isset($v['d']) ? (string) $v['d'] : '';
		if ($d === '' || ($d >= $f && $d <= $t)) { $out[$id] = $v; }
	}
	return $out;
}

function cp_panel_track_prune() {
	if (get_transient('cp_panel_track_pruned')) { return; }
	set_transient('cp_panel_track_pruned', 1, DAY_IN_SECONDS);
	$limit = gmdate('Y-m-d', time() - 400 * DAY_IN_SECONDS);
	foreach (cp_panel_track_all() as $id => $v) {
		if (!empty($v['d']) && $v['d'] < $limit) { delete_option(CP_PANEL_TRACK . $id); }
	}
}

add_action('wp_ajax_nopriv_cp_panel_track', 'cp_panel_ajax_track');
add_action('wp_ajax_cp_panel_track', 'cp_panel_ajax_track');
function cp_panel_ajax_track() {
	nocache_headers();
	if (!cp_panel_authed() || !hash_equals(cp_panel_csrf(), isset($_REQUEST['t']) ? (string) wp_unslash($_REQUEST['t']) : '')) {
		wp_send_json_error(array('msg' => 'auth'), 403);
	}
	// lista (frissítéshez): from/to
	if (isset($_REQUEST['from'], $_REQUEST['to']) && !isset($_POST['id'])) {
		$from = preg_replace('/[^0-9-]/', '', wp_unslash($_REQUEST['from']));
		$to = preg_replace('/[^0-9-]/', '', wp_unslash($_REQUEST['to']));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { wp_send_json_error(array('msg' => 'Hibás dátum.'), 400); }
		wp_send_json_success(array('track' => (object) cp_panel_track_range($from, $to), 'portal' => function_exists('cp_portal_panel_map_range') ? cp_portal_panel_map_range($from, $to) : new stdClass()));
	}
	$id = isset($_POST['id']) ? preg_replace('/[^0-9]/', '', wp_unslash($_POST['id'])) : '';
	$f = isset($_POST['f']) ? sanitize_key(wp_unslash($_POST['f'])) : '';
	$v = isset($_POST['v']) ? (string) wp_unslash($_POST['v']) : '';
	$d = isset($_POST['d']) ? preg_replace('/[^0-9-]/', '', wp_unslash($_POST['d'])) : '';
	$fields = cp_panel_track_fields();
	if ($id === '' || strlen($id) > 20 || !array_key_exists($f, $fields)) { wp_send_json_error(array('msg' => 'Hibás kérés.'), 400); }
	if ($f === 'note') {
		$v = mb_substr(sanitize_textarea_field($v), 0, 600);
	} elseif (!in_array($v, $fields[$f], true)) {
		wp_send_json_error(array('msg' => 'Hibás érték.'), 400);
	}
	// hosszabbítás: ha be van állítva Salesmate mező, oda is beírjuk
	$o = cp_panel_opts();
	if ($f === 'hx' && $o['f_hx'] !== '') {
		$val = cp_panel_hx_value($o['f_hx'], $v);
		if (is_wp_error($val)) { wp_send_json_error(array('msg' => $val->get_error_message()), 400); }
		$w = cp_sm_write_field($id, $o['f_hx'], $val);
		if (is_wp_error($w)) { wp_send_json_error(array('msg' => $w->get_error_message()), 502); }
		if (cp_panel_hx_parse($w) !== $v) {
			wp_send_json_error(array('msg' => 'A Salesmate nem fogadta el az értéket (jelenleg: „' . cp_panel_str($w) . '”).'), 502);
		}
		cp_panel_flush_cache();
	}
	$key = CP_PANEL_TRACK . $id;
	$cur = get_option($key, array());
	if (!is_array($cur)) { $cur = array(); }
	if ($v === '') { unset($cur[$f]); } else { $cur[$f] = $v; }
	if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $cur['d'] = $d; }
	$cur['u'] = time();
	$has = array_intersect_key($cur, $fields + array('sp' => 1));
	if (empty($has)) { delete_option($key); $cur = array(); }
	else { update_option($key, $cur, false); }
	cp_panel_track_prune();
	wp_send_json_success(array('id' => $id, 'track' => (object) $cur));
}

/* ---------------------------------------------------------------------
 * Front-end oldalak
 * ------------------------------------------------------------------- */

function cp_panel_head($title) {
	echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">';
	echo '<title>' . esc_html($title) . '</title>';
	echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
	echo '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">';
	echo '<link rel="stylesheet" href="' . esc_url(plugins_url('assets/panel.css', __FILE__)) . '?v=' . CP_PANEL_VER . '">';
	echo '</head>';
}

function cp_panel_render_login($err) {
	cp_panel_head('Crazy panel – belépés');
	echo '<body class="cpx-login"><form method="post" class="cpx-login-box" autocomplete="off">';
	echo '<div class="cpx-logo">Crazy<span>panel</span></div>';
	echo '<p class="cpx-muted">Belső foglaltsági panel. Add meg a közös jelszót.</p>';
	if ($err !== '') { echo '<div class="cpx-err">' . esc_html($err) . '</div>'; }
	echo '<input type="password" name="cp_pw" placeholder="Jelszó" required autofocus>';
	echo '<button type="submit">Belépés</button>';
	echo '</form></body></html>';
}

function cp_panel_render_app() {
	$o = cp_panel_opts();
	$cfg = array(
		'ajax'     => admin_url('admin-ajax.php'),
		't'        => cp_panel_csrf(),
		'capSelfie'=> (int) $o['cap_selfie'],
		'capSpin'  => (int) $o['cap_spin'],
		'subsDef'  => (int) $o['subs_default'],
		'bgs'      => cp_panel_bg_list(),
		'sign'     => (string) $o['reply_sign'],
		'logout'   => home_url('/' . trim($o['slug'], '/') . '/?kilep=1'),
		'sm'       => 'https://' . $o['linkname'] . '/#/app/deals/',
		'hasTime'  => $o['f_time'] !== '',
		'signed'   => array_map('mb_strtolower', cp_panel_lines($o['signed_stages'])),
		'hasPaid'  => $o['f_paid'] !== '',
		'bgWrite'  => !empty($o['bg_write']) && $o['f_bg'] !== '',
		'stageWrite' => !empty($o['stage_write']),
		'signFirst'  => (string) (cp_panel_lines($o['signed_stages']) ? cp_panel_lines($o['signed_stages'])[0] : ''),
		'bookedFirst'=> (string) (cp_panel_lines($o['booked_stages']) ? cp_panel_lines($o['booked_stages'])[0] : ''),
		'hxSm'       => $o['f_hx'] !== '',
		'el'          => cp_el_cfg(),
		'hxInt'      => (bool) preg_match('/^int/i', (string) $o['f_hx']),
		'portal'     => function_exists('cp_portal_panel_cfg') ? cp_portal_panel_cfg() : array('on' => false),
		'sb'         => function_exists('cp_sb_cfg') ? cp_sb_cfg() : array(),
	);
	cp_panel_head('Crazy panel');
	echo '<body><div id="cpx-app"></div>';
	echo '<script>window.CPX=' . wp_json_encode($cfg) . ';</script>';
	if (!empty($cfg['portal']['on'])) { echo '<script src="' . esc_url(plugins_url('assets/portal-panel.js', __FILE__)) . '?v=' . CP_PANEL_VER . '"></script>'; }
	if (function_exists('cp_sb_assets')) { cp_sb_assets(); }
	echo '<script src="' . esc_url(plugins_url('assets/panel.js', __FILE__)) . '?v=' . CP_PANEL_VER . '"></script>';
	echo '</body></html>';
}

function cp_panel_bg_list() {
	$o = cp_panel_opts();
	$qty = array();
	$extra = array();
	foreach (cp_panel_lines($o['backgrounds']) as $l) {
		$p = explode('|', $l, 2);
		$n = trim($p[0]);
		if ($n === '') { continue; }
		$qty[cp_panel_lc($n)] = isset($p[1]) ? max(1, (int) $p[1]) : 1;
		$extra[] = $n;
	}
	$out = array();
	$seen = array();
	$web = cp_panel_web_bgs();
	foreach ($web['list'] as $b) {
		$k = cp_panel_lc($b['name']);
		if (isset($seen[$k])) { continue; }
		$seen[$k] = 1;
		$out[] = array('name' => $b['name'], 'qty' => isset($qty[$k]) ? $qty[$k] : 1, 'img' => $b['img'], 'web' => 1);
	}
	foreach ($extra as $n) {
		$k = cp_panel_lc($n);
		if (isset($seen[$k])) { continue; }
		$seen[$k] = 1;
		$out[] = array('name' => $n, 'qty' => $qty[$k], 'img' => '', 'web' => 0);
	}
	return $out;
}

function cp_panel_lc($s) {
	return mb_strtolower(trim((string) $s));
}

/* A weboldal „Választható hátterek” oldalának beolvasása (mindig friss lista, képekkel) */
function cp_panel_web_bgs($force = false) {
	$o = cp_panel_opts();
	$url = trim((string) $o['bg_url']);
	$empty = array('list' => array(), 'at' => 0, 'dups' => array(), 'err' => '');
	if ($url === '') { return $empty; }
	if (!$force) {
		$c = get_transient('cp_panel_webbg');
		if (is_array($c) && isset($c['url']) && $c['url'] === $url) { return $c; }
	}
	$res = wp_remote_get($url, array('timeout' => 15, 'redirection' => 3, 'user-agent' => 'Mozilla/5.0 (CrazyPanel háttérlista; +' . home_url('/') . ')'));
	$code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
	$parsed = ($code === 200) ? cp_panel_parse_bgs(wp_remote_retrieve_body($res)) : array('list' => array(), 'dups' => array());
	if (count($parsed['list']) >= 5) {
		$data = array('list' => $parsed['list'], 'dups' => $parsed['dups'], 'at' => time(), 'err' => '', 'url' => $url);
		update_option('cp_panel_webbg_last', $data, false);
		set_transient('cp_panel_webbg', $data, 6 * HOUR_IN_SECONDS);
		return $data;
	}
	// hiba: az utolsó jó listát használjuk, 30 perc múlva újrapróbáljuk
	$last = get_option('cp_panel_webbg_last', $empty);
	if (!is_array($last)) { $last = $empty; }
	$last['err'] = is_wp_error($res) ? $res->get_error_message() : ('HTTP ' . $code . ', ' . count($parsed['list']) . ' háttér');
	$last['url'] = $url;
	set_transient('cp_panel_webbg', $last, 30 * MINUTE_IN_SECONDS);
	return $last;
}

function cp_panel_parse_bgs($html) {
	$list = array();
	$seen = array();
	$dups = array();
	if (!preg_match_all('#<figure\b[^>]*>(.*?)</figure>#si', (string) $html, $m)) { return array('list' => $list, 'dups' => $dups); }
	foreach ($m[1] as $fig) {
		if (!preg_match('#<figcaption\b[^>]*>(.*?)</figcaption>#si', $fig, $c)) { continue; }
		$name = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($c[1]), ENT_QUOTES, 'UTF-8')));
		if ($name === '' || mb_strlen($name) > 60) { continue; }
		preg_match_all('#https?://[^\s"\'<>,]+?\.(?:jpe?g|png|webp)#i', $fig, $u);
		$img = '';
		foreach ($u[0] as $x) { if (strpos($x, '-300x300') !== false) { $img = $x; break; } }
		if ($img === '' && !empty($u[0])) { $img = $u[0][0]; }
		$k = cp_panel_lc($name);
		if (isset($seen[$k])) { $dups[] = $name; continue; }
		$seen[$k] = 1;
		$list[] = array('name' => $name, 'img' => esc_url_raw($img));
	}
	return array('list' => $list, 'dups' => array_values(array_unique($dups)));
}

function cp_panel_flush_cache() {
	global $wpdb;
	$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_cp\\_panel\\_d\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cp\\_panel\\_d\\_%'");
}


/* ---------------------------------------------------------------------
 * Visszaírás a Salesmate-be: segédfüggvények, szakaszváltás
 * ------------------------------------------------------------------- */

function cp_sm_get_deal($id) {
	$r = cp_sm_request('GET', '/apis/deal/v4/' . rawurlencode($id));
	if (is_wp_error($r)) { return $r; }
	$d = isset($r['Data']) ? $r['Data'] : $r;
	return is_array($d) ? $d : new WP_Error('cp_json', 'Érvénytelen deal válasz.');
}

function cp_sm_put_deal($id, $body) {
	$res = cp_sm_request('PUT', '/apis/deal/v4/' . rawurlencode($id), $body);
	if (is_wp_error($res)) { return $res; }
	if (isset($res['Status']) && strtolower((string) $res['Status']) !== 'success') {
		return new WP_Error('cp_sm', 'Salesmate: ' . mb_substr(wp_json_encode($res), 0, 200));
	}
	return $res;
}

/* egy mező írása + visszaolvasás; a mező új (nyers) értékét adja vissza */
function cp_sm_write_field($id, $field, $value) {
	$put = cp_sm_put_deal($id, array($field => $value));
	if (is_wp_error($put)) { return $put; }
	$d = cp_sm_get_deal($id);
	if (is_wp_error($d)) { return $d; }
	return cp_panel_get($d, $field);
}

/* hosszabbítás: panel-érték ('', '0', '0.5' … '5') → Salesmate mezőérték a mező típusa szerint */
function cp_panel_hx_value($field, $v) {
	if (preg_match('/^(decimal|number|currency|float|double)/i', $field)) { return $v === '' ? null : (float) $v; }
	if (preg_match('/^int/i', $field)) {
		if ($v === '') { return null; }
		if ((float) $v != (int) $v) { return new WP_Error('cp_int', 'A Salesmate-ben ez a mező csak egész órát fogad (' . $field . '). Félórához tizedes vagy szöveges mező kell.'); }
		return (int) $v;
	}
	if ($v === '') { return ''; }
	if ($v === '0') { return 'Nem volt'; }
	return '+' . str_replace('.', ',', $v) . ' óra';
}

/* Salesmate mezőérték → panel-érték */
function cp_panel_hx_parse($raw) {
	$s = cp_panel_lc(cp_panel_str($raw));
	if ($s === '') { return ''; }
	if (strpos($s, 'nem') !== false || strpos($s, 'nincs') !== false) { return '0'; }
	if (!preg_match('/\d+(?:[.,]\d+)?/', $s, $m)) { return ''; }
	$n = round((float) str_replace(',', '.', $m[0]) * 2) / 2;
	return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
}

add_action('wp_ajax_nopriv_cp_panel_setstage', 'cp_panel_ajax_setstage');
add_action('wp_ajax_cp_panel_setstage', 'cp_panel_ajax_setstage');
function cp_panel_ajax_setstage() {
	nocache_headers();
	if (!cp_panel_authed() || !hash_equals(cp_panel_csrf(), isset($_POST['t']) ? (string) wp_unslash($_POST['t']) : '')) {
		wp_send_json_error(array('msg' => 'auth'), 403);
	}
	$o = cp_panel_opts();
	$signed = cp_panel_lines($o['signed_stages']);
	$booked = cp_panel_lines($o['booked_stages']);
	if (empty($o['stage_write']) || !$signed) { wp_send_json_error(array('msg' => 'A szakaszváltás ki van kapcsolva.'), 400); }
	$id = isset($_POST['id']) ? preg_replace('/[^0-9]/', '', wp_unslash($_POST['id'])) : '';
	$dir = isset($_POST['dir']) ? sanitize_key(wp_unslash($_POST['dir'])) : '';
	$date = isset($_POST['d']) ? preg_replace('/[^0-9-]/', '', wp_unslash($_POST['d'])) : '';
	if ($id === '' || strlen($id) > 20 || !in_array($dir, array('sign', 'revert'), true)) { wp_send_json_error(array('msg' => 'Hibás kérés.'), 400); }
	$d = cp_sm_get_deal($id);
	if (is_wp_error($d)) { wp_send_json_error(array('msg' => $d->get_error_message()), 502); }
	$cur = cp_panel_str(cp_panel_get($d, 'stage'));
	$status = cp_panel_lc(cp_panel_str(cp_panel_get($d, 'status')));
	$plRaw = cp_panel_get($d, 'pipeline');
	$signedLc = array_map('cp_panel_lc', $signed);
	$key = CP_PANEL_TRACK . $id;
	$tr = get_option($key, array());
	if (!is_array($tr)) { $tr = array(); }
	if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $tr['d'] = $date; }
	$tr['u'] = time();
	if ($dir === 'sign') {
		if ($status === 'won' || in_array(cp_panel_lc($cur), $signedLc, true)) {
			unset($tr['sz']);
			update_option($key, $tr, false);
			wp_send_json_success(array('id' => $id, 'stage' => $cur, 'changed' => false, 'track' => (object) $tr));
		}
		if ($status === 'lost') { wp_send_json_error(array('msg' => 'Ez a deal elvesztettként van jelölve a Salesmate-ben.'), 400); }
		$target = $signed[0];
		$prev = $cur;
	} else {
		if (cp_panel_lc($cur) !== cp_panel_lc($signed[0])) {
			wp_send_json_error(array('msg' => 'A deal már a(z) „' . $cur . '” szakaszban van – ezt a Salesmate-ben lehet visszaállítani.'), 409);
		}
		$target = !empty($tr['sp']) ? (string) $tr['sp'] : ($booked ? $booked[0] : '');
		if ($target === '' || cp_panel_lc($target) === cp_panel_lc($cur)) { wp_send_json_error(array('msg' => 'Nincs hová visszaállítani.'), 400); }
		$prev = '';
	}
	$body = array('stage' => $target);
	if (is_string($plRaw) && trim($plRaw) !== '') { $body['pipeline'] = trim($plRaw); }
	$put = cp_sm_put_deal($id, $body);
	if (is_wp_error($put)) { wp_send_json_error(array('msg' => $put->get_error_message()), 502); }
	$chk = cp_sm_get_deal($id);
	$now = is_wp_error($chk) ? $target : cp_panel_str(cp_panel_get($chk, 'stage'));
	if (cp_panel_lc($now) !== cp_panel_lc($target)) {
		wp_send_json_error(array('msg' => 'A Salesmate nem fogadta el a szakaszváltást (jelenleg: „' . $now . '”).'), 502);
	}
	unset($tr['sz']);
	if ($prev !== '') { $tr['sp'] = $prev; } else { unset($tr['sp']); }
	update_option($key, $tr, false);
	cp_panel_flush_cache();
	// ügyfélportál: a „Szerződés aláírva” szakaszba lépéskor létrejön (a válasz elküldése után, hogy a panel ne várjon)
	if ($dir === 'sign' && function_exists('cp_portal_defer')) { cp_portal_defer(function () use ($id) { cp_portal_on_stage($id, '', 'panel'); }); }
	wp_send_json_success(array('id' => $id, 'stage' => $now, 'changed' => true, 'track' => (object) $tr));
}

/* ---------------------------------------------------------------------
 * Visszaírás a Salesmate-be: háttér kiválasztása
 * ------------------------------------------------------------------- */

add_action('wp_ajax_nopriv_cp_panel_setbg', 'cp_panel_ajax_setbg');
add_action('wp_ajax_cp_panel_setbg', 'cp_panel_ajax_setbg');
function cp_panel_ajax_setbg() {
	nocache_headers();
	if (!cp_panel_authed() || !hash_equals(cp_panel_csrf(), isset($_POST['t']) ? (string) wp_unslash($_POST['t']) : '')) {
		wp_send_json_error(array('msg' => 'auth'), 403);
	}
	$o = cp_panel_opts();
	if (empty($o['bg_write']) || $o['f_bg'] === '') { wp_send_json_error(array('msg' => 'A háttér visszaírása ki van kapcsolva.'), 400); }
	$id = isset($_POST['id']) ? preg_replace('/[^0-9]/', '', wp_unslash($_POST['id'])) : '';
	$date = isset($_POST['d']) ? preg_replace('/[^0-9-]/', '', wp_unslash($_POST['d'])) : '';
	$bg = isset($_POST['bg']) ? trim(sanitize_text_field(wp_unslash($_POST['bg']))) : '';
	$force = !empty($_POST['force']);
	if ($id === '' || strlen($id) > 20 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || mb_strlen($bg) > 80) {
		wp_send_json_error(array('msg' => 'Hibás kérés.'), 400);
	}
	// dupla foglalás elleni védelem: friss adat az adott napra
	$k = cp_panel_lc($bg);
	if ($k !== '' && $k !== 'még nem tudom' && !$force) {
		$qty = 0;
		foreach (cp_panel_bg_list() as $b) { if (cp_panel_lc($b['name']) === $k) { $qty = (int) $b['qty']; } }
		if ($qty > 0) {
			$info = null;
			$rows = cp_sm_fetch_range($date, $date, $info);
			if (is_wp_error($rows)) { wp_send_json_error(array('msg' => 'Nem sikerült ellenőrizni a foglaltságot: ' . $rows->get_error_message()), 502); }
			$who = array();
			foreach (cp_panel_normalize($rows) as $e) {
				if ($e['date'] === $date && $e['state'] === 'booked' && (string) $e['id'] !== $id && cp_panel_lc($e['bg']) === $k) { $who[] = $e['title']; }
			}
			if (count($who) >= $qty) {
				wp_send_json_error(array('msg' => 'conflict', 'who' => $who), 409);
			}
		}
	}
	$res = cp_sm_request('PUT', '/apis/deal/v4/' . rawurlencode($id), array($o['f_bg'] => $bg));
	if (is_wp_error($res)) { wp_send_json_error(array('msg' => $res->get_error_message()), 502); }
	if (isset($res['Status']) && strtolower((string) $res['Status']) !== 'success') {
		wp_send_json_error(array('msg' => 'Salesmate: ' . mb_substr(wp_json_encode($res), 0, 200)), 502);
	}
	// ellenőrzés: tényleg átíródott-e
	$chk = cp_sm_request('GET', '/apis/deal/v4/' . rawurlencode($id));
	$now = null;
	if (!is_wp_error($chk)) {
		$d = isset($chk['Data']) ? $chk['Data'] : $chk;
		$now = cp_panel_str(cp_panel_get($d, $o['f_bg']));
		if (cp_panel_lc($now) !== $k) {
			wp_send_json_error(array('msg' => 'A Salesmate nem fogadta el ezt az értéket (jelenleg: „' . $now . '”). Lehet, hogy a Háttér mező legördülő listájában nincs ilyen opció.'), 502);
		}
	}
	cp_panel_flush_cache();
	wp_send_json_success(array('id' => $id, 'bg' => $now !== null ? $now : $bg, 'verified' => $now !== null));
}

/* ---------------------------------------------------------------------
 * Admin: beállítások + diagnosztika
 * ------------------------------------------------------------------- */

add_action('admin_menu', function () {
	add_options_page('Foglaltsági panel', 'Foglaltsági panel', 'manage_options', 'cp-panel', 'cp_panel_admin_page');
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
	array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=cp-panel')) . '">Beállítások</a>');
	return $links;
});

function cp_panel_admin_save() {
	if (!current_user_can('manage_options') || !isset($_POST['cp_panel_save'])) { return ''; }
	check_admin_referer('cp_panel_save');
	$o = cp_panel_opts();
	$in = wp_unslash($_POST);
	$text = array('slug', 'linkname', 'pipeline', 'f_date', 'f_time', 'f_bg', 'f_selfie', 'f_spin', 'f_loc', 'f_guests', 'f_note', 'f_paid', 'search_mode');
	$o['bg_url'] = isset($in['bg_url']) ? esc_url_raw(trim($in['bg_url'])) : $o['bg_url'];
	$o['bg_write'] = !empty($in['bg_write']) ? 1 : 0;
	$o['stage_write'] = !empty($in['stage_write']) ? 1 : 0;
	$o['f_hx'] = isset($in['f_hx']) ? preg_replace('/[^A-Za-z0-9_]/', '', $in['f_hx']) : $o['f_hx'];
	delete_transient('cp_panel_webbg');
	foreach ($text as $k) { if (isset($in[$k])) { $o[$k] = sanitize_text_field($in[$k]); } }
	$o['slug'] = sanitize_title($o['slug']);
	foreach (array('booked_stages', 'pending_stages', 'signed_stages', 'backgrounds', 'f_extra', 'reply_sign') as $k) { if (isset($in[$k])) { $o[$k] = sanitize_textarea_field($in[$k]); } }
	foreach (array('cap_selfie', 'cap_spin', 'subs_default', 'cache_min') as $k) { if (isset($in[$k])) { $o[$k] = max(0, (int) $in[$k]); } }
	if (!empty($in['token_new'])) { $o['token'] = trim(sanitize_text_field($in['token_new'])); }
	if (!empty($in['token_clear'])) { $o['token'] = ''; }
	if (!empty($in['openai_key_new'])) { $o['openai_key'] = trim(sanitize_text_field($in['openai_key_new'])); }
	if (!empty($in['openai_key_clear'])) { $o['openai_key'] = ''; }
	if (isset($in['ai_model'])) { $o['ai_model'] = preg_replace('/[^A-Za-z0-9._-]/', '', $in['ai_model']); }
	if (isset($in['ai_quality']) && in_array($in['ai_quality'], array('low', 'medium', 'high', 'auto'), true)) { $o['ai_quality'] = $in['ai_quality']; }
	if (isset($in['ai_daily'])) { $o['ai_daily'] = max(0, (int) $in['ai_daily']); }
	$msg = 'Beállítások elmentve.';
	if (!empty($in['pw_new'])) {
		if (strlen($in['pw_new']) < 8) {
			$msg = 'A jelszó legalább 8 karakter legyen – a jelszót nem változtattam meg.';
		} else {
			$o['pw_hash'] = wp_hash_password($in['pw_new']);
			$o['pw_ver'] = (string) (((int) $o['pw_ver']) + 1);
			$msg .= ' A panel jelszava megváltozott (mindenkit kiléptettünk).';
		}
	}
	update_option(CP_PANEL_OPT, $o, false);
	return $msg;
}

function cp_panel_admin_page() {
	if (!current_user_can('manage_options')) { return; }
	$msg = cp_panel_admin_save();
	$o = cp_panel_opts();
	$url = home_url('/' . trim($o['slug'], '/') . '/');
	echo '<div class="wrap"><h1>Foglaltsági panel</h1>';
	if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
	echo '<p>Panel címe: <a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a></p>';
	echo '<form method="post">';
	wp_nonce_field('cp_panel_save');
	echo '<h2>Hozzáférés</h2><table class="form-table">';
	cp_panel_row('Panel útvonala', '<code>' . esc_html(home_url('/')) . '</code><input name="slug" value="' . esc_attr($o['slug']) . '" class="regular-text">');
	cp_panel_row('Közös jelszó', '<input type="password" name="pw_new" class="regular-text" autocomplete="new-password" placeholder="' . ($o['pw_hash'] ? 'Beállítva – új jelszó megadásához írd be' : 'Még nincs beállítva') . '"><p class="description">Legalább 8 karakter. Csak a hash-e kerül mentésre.</p>');
	echo '</table><h2>Salesmate kapcsolat</h2><table class="form-table">';
	cp_panel_row('Salesmate cím (linkname)', '<input name="linkname" value="' . esc_attr($o['linkname']) . '" class="regular-text">');
	cp_panel_row('API kulcs (Access Key)', '<input type="password" name="token_new" class="regular-text" autocomplete="off" placeholder="' . ($o['token'] ? 'Beállítva (••••' . esc_attr(substr($o['token'], -4)) . ') – cseréhez írd be az újat' : 'Illeszd be a Salesmate Access Key-t') . '"> <label><input type="checkbox" name="token_clear" value="1"> törlés</label><p class="description">Salesmate: Profil → My Account / Developer → Access Key. A kulcs csak a szerveren marad, a panel böngészőjébe nem kerül ki.</p>');
	cp_panel_row('Pipeline neve', '<input name="pipeline" value="' . esc_attr($o['pipeline']) . '" class="regular-text">');
	cp_panel_row('Foglalásnak számító szakaszok', '<textarea name="booked_stages" rows="5" class="large-text">' . esc_textarea($o['booked_stages']) . '</textarea><p class="description">Soronként egy. A megnyert (Won) dealek mindig foglaltak, az elvesztettek (Lost) soha.</p>');
	cp_panel_row('Függő (sárga) szakaszok', '<textarea name="pending_stages" rows="3" class="large-text">' . esc_textarea($o['pending_stages']) . '</textarea>');
	cp_panel_row('Aláírt szerződésnek számító szakaszok', '<textarea name="signed_stages" rows="4" class="large-text">' . esc_textarea($o['signed_stages']) . '</textarea><p class="description">A Követés fülön ezekben a szakaszokban (és a megnyert dealeknél) a „Szerződés” pipa automatikusan be van jelölve. Kézzel felülírható.</p>');
	cp_panel_row('Keresési mód', '<select name="search_mode">' . cp_panel_opt_html(array('auto' => 'Automatikus', 'after_before' => 'Dátum után/előtt', 'between' => 'BETWEEN (tömb)', 'between_str' => 'BETWEEN (szöveg)', 'scan' => 'Teljes átnézés (lassú)'), $o['search_mode']) . '</select>');
	cp_panel_row('Gyorsítótár (perc)', '<input type="number" min="1" name="cache_min" value="' . esc_attr($o['cache_min']) . '" class="small-text">');
	echo '</table><h2>Kapacitás</h2><table class="form-table">';
	cp_panel_row('Saját szelfigépek', '<input type="number" min="0" name="cap_selfie" value="' . esc_attr($o['cap_selfie']) . '" class="small-text">');
	cp_panel_row('360 Spinnerek', '<input type="number" min="0" name="cap_spin" value="' . esc_attr($o['cap_spin']) . '" class="small-text">');
	cp_panel_row('Alvállalkozói slot / nap (alap)', '<input type="number" min="0" name="subs_default" value="' . esc_attr($o['subs_default']) . '" class="small-text"><p class="description">Napra lebontva a panelen módosítható.</p>');
	$wb = cp_panel_web_bgs();
	cp_panel_row('Háttérlista forrása (weboldal)', '<input name="bg_url" value="' . esc_attr($o['bg_url']) . '" class="large-text"><p class="description">Erről az oldalról olvassa be a panel a háttereket képpel együtt (6 óránként frissül). Jelenleg: <b>' . count($wb['list']) . '</b> háttér' . ($wb['at'] ? ', beolvasva ' . esc_html(wp_date('Y.m.d H:i', $wb['at'])) : '') . ($wb['err'] ? ' – <span style="color:#b32d2e">utolsó hiba: ' . esc_html($wb['err']) . '</span>' : '') . (!empty($wb['dups']) ? ' – <span style="color:#b32d2e">kétszer szereplő név: ' . esc_html(implode(', ', $wb['dups'])) . '</span>' : '') . '</p>');
	cp_panel_row('Háttér visszaírása', '<label><input type="checkbox" name="bg_write" value="1"' . checked(!empty($o['bg_write']), true, false) . '> A panelen kiválasztott háttér mentése a Salesmate dealbe</label>');
	cp_panel_row('Szakasz visszaírása', '<label><input type="checkbox" name="stage_write" value="1"' . checked(!empty($o['stage_write']), true, false) . '> A Követés fülön a „Szerződés” pipa a dealt átteszi a(z) „' . esc_html(cp_panel_lines($o['signed_stages']) ? cp_panel_lines($o['signed_stages'])[0] : '–') . '” szakaszba (megerősítés után; a Salesmate szakasz-automatizációi lefutnak)</label>');
	cp_panel_row('Hosszabbítás mező (Salesmate kulcs)', '<input name="f_hx" value="' . esc_attr($o['f_hx']) . '" class="regular-text" placeholder="pl. decimalCustomField1 vagy textCustomField20"><p class="description">Ha megadod, a Követés fülön választott hosszabbítás a Salesmate dealbe is beíródik (és onnan olvasódik vissza). Tizedes vagy szöveges mező ajánlott (félórák miatt). A kulcsot lent a „Mezőkulcs keresése” segít megtalálni.</p>');
	cp_panel_row('Hátterek (kiegészítés, darabszám)', '<textarea name="backgrounds" rows="8" class="large-text">' . esc_textarea($o['backgrounds']) . '</textarea><p class="description">A weboldalon nem szereplő, de még használt hátterek, illetve darabszám: <code>Fehér|2</code>. A weboldalas lista ezzel egészül ki.</p>');
	echo '</table><h2>Mezők (Salesmate API kulcsnevek)</h2><table class="form-table">';
	foreach (array('f_date' => 'Rendezvény dátuma', 'f_time' => 'Kezdés időpontja', 'f_bg' => 'Háttér', 'f_selfie' => 'Szelfigép csomag', 'f_spin' => '360 csomag', 'f_loc' => 'Helyszín', 'f_guests' => 'Létszám', 'f_note' => 'Megjegyzés', 'f_paid' => 'Bejött az utalás? (nem kötelező)') as $k => $label) {
		cp_panel_row($label, '<input name="' . $k . '" value="' . esc_attr($o[$k]) . '" class="regular-text">');
	}
	cp_panel_row('További mezők a munkalapon', '<textarea name="f_extra" rows="4" class="large-text" placeholder="textCustomField7=Gépkezelő asszisztens">' . esc_textarea($o['f_extra']) . '</textarea><p class="description">Soronként: <code>kulcs=Címke</code></p>');
	cp_panel_row('Válaszlevél aláírása', '<textarea name="reply_sign" rows="3" class="large-text">' . esc_textarea($o['reply_sign']) . '</textarea>');
	echo '</table><h2>Sablonkészítő – egyedi keret AI-val (OpenAI)</h2><table class="form-table">';
	cp_panel_row('OpenAI API kulcs', '<input type="password" name="openai_key_new" class="regular-text" autocomplete="off" placeholder="' . ($o['openai_key'] ? 'Beállítva (••••' . esc_attr(substr($o['openai_key'], -4)) . ') – cseréhez írd be az újat' : 'sk-…') . '"> <label><input type="checkbox" name="openai_key_clear" value="1"> törlés</label><p class="description">platform.openai.com → API keys. A kulcs csak a szerveren marad, a panel böngészőjébe nem kerül ki. Kulcs nélkül a Sablon fülön nem jelenik meg a generálás.</p>');
	cp_panel_row('Képmodell', '<input name="ai_model" value="' . esc_attr($o['ai_model']) . '" class="regular-text" placeholder="gpt-image-1"><p class="description">OpenAI képszerkesztő (images/edits) modell, ami támogatja a maszkot és az álló 1024×1536-os méretet, pl. <code>gpt-image-1</code>.</p>');
	cp_panel_row('Minőség', '<select name="ai_quality">' . cp_panel_opt_html(array('low' => 'Alacsony (olcsó vázlat)', 'medium' => 'Közepes', 'high' => 'Magas (nyomdai, drágább)', 'auto' => 'Automatikus'), $o['ai_quality']) . '</select>');
	cp_panel_row('Napi generálási keret', '<input type="number" min="0" name="ai_daily" value="' . esc_attr($o['ai_daily']) . '" class="small-text"> db / nap<p class="description">Költségvédelem: ennyi generálás engedélyezett naponta a panelről (0 = korlátlan).</p>');
	echo '</table>';
	submit_button('Mentés', 'primary', 'cp_panel_save');
	echo '</form>';

	echo '<hr><h2>Diagnosztika</h2><p>A kapcsolat és a mezőnevek ellenőrzéséhez. (Csak adminisztrátorok látják.)</p>';
	$dn = wp_create_nonce('cp_panel_diag');
	$base = admin_url('options-general.php?page=cp-panel&_wpnonce=' . $dn . '&diag=');
	echo '<p><a class="button" href="' . esc_url($base . 'ping') . '">Kapcsolat teszt</a> ';
	echo '<a class="button" href="' . esc_url($base . 'keys') . '">Mezőkulcsok (legutóbbi deal)</a> ';
	echo '<a class="button" href="' . esc_url($base . 'range') . '">Decemberi lekérdezés teszt</a> ';
	echo '<a class="button" href="' . esc_url($base . 'stages') . '">Szakaszok</a> ';
	echo '<a class="button" href="' . esc_url($base . 'webbg') . '">Háttérlista frissítése</a> ';
	echo '<a class="button" href="' . esc_url($base . 'writetest') . '">Visszaírás teszt (változatlan értékkel)</a> ';
	echo '<a class="button" href="' . esc_url($base . 'flush') . '">Gyorsítótár ürítése</a></p>';
	echo '<form method="get" style="margin:8px 0 14px"><input type="hidden" name="page" value="cp-panel"><input type="hidden" name="_wpnonce" value="' . esc_attr($dn) . '"><input type="hidden" name="diag" value="findkey">';
	echo '<b>Mezőkulcs keresése:</b> írj a Salesmate-ben egy egyedi tesztértéket (pl. <code>7.5</code>) az új mezőbe egy dealnél, majd add meg itt: deal ID <input name="deal" size="7" inputmode="numeric"> érték <input name="val" size="10"> <button class="button">Keresés</button></form>';
	if (!empty($_GET['diag']) && wp_verify_nonce(isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '', 'cp_panel_diag')) {
		echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:520px;overflow:auto"><pre style="white-space:pre-wrap">';
		echo esc_html(cp_panel_diag(sanitize_key($_GET['diag'])));
		echo '</pre></div>';
	}
	echo '</div>';
}

function cp_panel_row($label, $html) {
	echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore
}

function cp_panel_opt_html($opts, $cur) {
	$h = '';
	foreach ($opts as $v => $l) { $h .= '<option value="' . esc_attr($v) . '"' . selected($cur, $v, false) . '>' . esc_html($l) . '</option>'; }
	return $h;
}

function cp_panel_mask($v) {
	if (is_array($v)) {
		$out = array();
		foreach ($v as $k => $x) { $out[$k] = cp_panel_mask($x); }
		return $out;
	}
	if (is_string($v) && mb_strlen($v) > 60) { return mb_substr($v, 0, 60) . '…'; }
	return $v;
}

function cp_panel_diag($what) {
	$o = cp_panel_opts();
	if ($what === 'flush') {
		cp_panel_flush_cache();
		delete_transient('cp_panel_webbg');
		return 'Gyorsítótár ürítve (Salesmate adatok és háttérlista).';
	}
	if ($what === 'ping') {
		$json = cp_sm_request('POST', '/apis/deal/v4/search?rows=1&from=0', cp_sm_search_body(array()));
		if (is_wp_error($json)) { return 'HIBA: ' . $json->get_error_message(); }
		$rows = cp_sm_rows($json);
		return "OK – kapcsolat rendben.\nVálasz kulcsai: " . implode(', ', array_keys($json)) . "\nElső sor:\n" . print_r(cp_panel_mask(isset($rows[0]) ? $rows[0] : $json), true);
	}
	if ($what === 'keys') {
		$json = cp_sm_request('POST', '/apis/deal/v4/search?rows=1&from=0', cp_sm_search_body(array()));
		if (is_wp_error($json)) { return 'HIBA: ' . $json->get_error_message(); }
		$rows = cp_sm_rows($json);
		$id = isset($rows[0]) ? cp_panel_str(cp_panel_get($rows[0], 'id')) : '';
		if ($id === '') { return 'Nem találtam dealt.'; }
		$deal = cp_sm_request('GET', '/apis/deal/v4/' . rawurlencode($id));
		if (is_wp_error($deal)) { return 'HIBA: ' . $deal->get_error_message(); }
		$d = isset($deal['Data']) ? $deal['Data'] : $deal;
		$lines = array('Deal #' . $id . ' mezői (kulcs => minta érték):');
		if (is_array($d)) {
			ksort($d);
			foreach ($d as $k => $v) {
				$s = is_array($v) ? wp_json_encode(cp_panel_mask($v)) : (string) $v;
				$lines[] = $k . ' => ' . mb_substr($s, 0, 80);
			}
		}
		return implode("\n", $lines);
	}
	if ($what === 'range') {
		$y = (int) gmdate('Y');
		$info = null;
		$t = microtime(true);
		$rows = cp_sm_fetch_range($y . '-12-01', $y . '-12-31', $info);
		if (is_wp_error($rows)) { return 'HIBA: ' . $rows->get_error_message(); }
		$ev = cp_panel_normalize($rows);
		$cnt = array('booked' => 0, 'pending' => 0);
		foreach ($ev as $e) { if ($e['date'] >= $y . '-12-01' && $e['date'] <= $y . '-12-31') { $cnt[$e['state']]++; } }
		return sprintf("Mód: %s, API hívások: %d, idő: %.1f mp\nNyers sorok: %d\nDecemberi foglalás: %d, függő: %d\nMinta (első 3):\n%s", $info['mode'], $info['calls'], microtime(true) - $t, count($rows), $cnt['booked'], $cnt['pending'], print_r(array_slice(array_map(function ($e) { unset($e['contact'], $e['note']); return $e; }, $ev), 0, 3), true));
	}
	if ($what === 'stages') {
		$json = cp_sm_request('POST', '/apis/deal/v4/search?rows=250&from=0', cp_sm_search_body(array()));
		if (is_wp_error($json)) { return 'HIBA: ' . $json->get_error_message(); }
		$c = array();
		foreach (cp_sm_rows($json) as $r) {
			$k = cp_panel_str(cp_panel_get($r, 'pipeline')) . ' → ' . cp_panel_str(cp_panel_get($r, 'stage')) . ' [' . cp_panel_str(cp_panel_get($r, 'status')) . ']';
			$c[$k] = isset($c[$k]) ? $c[$k] + 1 : 1;
		}
		arsort($c);
		$out = array('Legutóbbi 250 deal szakaszai:');
		foreach ($c as $k => $n) { $out[] = $n . ' × ' . $k; }
		return implode("\n", $out);
	}
	if ($what === 'findkey') {
		$deal = isset($_GET['deal']) ? preg_replace('/[^0-9]/', '', wp_unslash($_GET['deal'])) : '';
		$val = isset($_GET['val']) ? trim(sanitize_text_field(wp_unslash($_GET['val']))) : '';
		if ($deal === '' || $val === '') { return 'Add meg a deal ID-t és a keresett értéket.'; }
		$d = cp_sm_get_deal($deal);
		if (is_wp_error($d)) { return 'HIBA: ' . $d->get_error_message(); }
		$hits = array();
		$nv = str_replace(',', '.', $val);
		foreach ($d as $k => $v) {
			if (!is_scalar($v) || $v === '' || $v === null) { continue; }
			$sv = trim((string) $v);
			if (cp_panel_lc($sv) === cp_panel_lc($val) || (is_numeric($nv) && is_numeric(str_replace(',', '.', $sv)) && (float) $nv == (float) str_replace(',', '.', $sv))) { $hits[] = $k; }
		}
		return $hits ? "Ezekben a mezőkben szerepel ez az érték:\n  " . implode("\n  ", $hits) . "\n\nAz új mező kulcsa valószínűleg a CustomField végű." : 'Nem találtam ezt az értéket a deal #' . $deal . ' mezőiben.';
	}
	if ($what === 'webbg') {
		$w = cp_panel_web_bgs(true);
		$lines = array('Forrás: ' . $o['bg_url'], 'Hátterek: ' . count($w['list']) . ($w['err'] ? ' (hiba: ' . $w['err'] . ')' : ''));
		if (!empty($w['dups'])) { $lines[] = 'Kétszer szereplő név az oldalon: ' . implode(', ', $w['dups']); }
		foreach ($w['list'] as $b) { $lines[] = '  ' . $b['name'] . ($b['img'] ? '' : '  (kép nélkül)'); }
		$web = array_map('cp_panel_lc', wp_list_pluck($w['list'], 'name'));
		$only = array();
		foreach (cp_panel_lines($o['backgrounds']) as $l) { $n = trim(explode('|', $l)[0]); if ($n !== '' && !in_array(cp_panel_lc($n), $web, true)) { $only[] = $n; } }
		if ($only) { $lines[] = 'Csak a beállításokban (a weboldalon nincs): ' . implode(', ', $only); }
		return implode("\n", $lines);
	}
	if ($what === 'writetest') {
		// egy decemberi, háttérrel rendelkező dealbe visszaírja UGYANAZT az értéket, és összeveti a deal összes mezőjét előtte/utána
		$y = (int) gmdate('Y');
		$info = null;
		$rows = cp_sm_fetch_range($y . '-12-01', $y . '-12-31', $info);
		if (is_wp_error($rows)) { return 'HIBA: ' . $rows->get_error_message(); }
		$pick = null;
		foreach (cp_panel_normalize($rows) as $e) { if ($e['state'] === 'booked' && $e['bg'] !== '') { $pick = $e; break; } }
		if (!$pick) { return 'Nincs háttérrel rendelkező decemberi foglalás a teszthez.'; }
		$b1 = cp_sm_request('GET', '/apis/deal/v4/' . rawurlencode($pick['id']));
		if (is_wp_error($b1)) { return 'HIBA (olvasás): ' . $b1->get_error_message(); }
		$put = cp_sm_request('PUT', '/apis/deal/v4/' . rawurlencode($pick['id']), array($o['f_bg'] => $pick['bg']));
		if (is_wp_error($put)) { return 'HIBA (írás): ' . $put->get_error_message(); }
		$b2 = cp_sm_request('GET', '/apis/deal/v4/' . rawurlencode($pick['id']));
		if (is_wp_error($b2)) { return 'HIBA (visszaolvasás): ' . $b2->get_error_message(); }
		$d1 = isset($b1['Data']) ? $b1['Data'] : $b1;
		$d2 = isset($b2['Data']) ? $b2['Data'] : $b2;
		$changed = array();
		foreach (array_unique(array_merge(array_keys((array) $d1), array_keys((array) $d2))) as $key) {
			$v1 = isset($d1[$key]) ? wp_json_encode($d1[$key]) : null;
			$v2 = isset($d2[$key]) ? wp_json_encode($d2[$key]) : null;
			if ($v1 !== $v2) { $changed[] = $key; }
		}
		return "Deal #" . $pick['id'] . " – írás válasza: " . (isset($put['Status']) ? $put['Status'] : 'ok') . "\nMezők száma előtte/utána: " . count((array) $d1) . ' / ' . count((array) $d2) . "\nMegváltozott mezők: " . ($changed ? implode(', ', $changed) : 'nincs') . "\n(Csak mezőnevek, értékek nélkül.)";
	}
	if ($what === 'labels') {
		$paths = array('/apis/core/v4/fields?moduleId=4', '/apis/deal/v4/fields', '/apis/core/v4/modules/4/fields', '/apis/v1/fields?moduleName=Deal', '/apis/v3/fields?moduleName=Deal', '/apis/core/v4/field?moduleName=Deal', '/apis/core/v4/fields/Deal');
		$out = array();
		foreach ($paths as $p) {
			$json = cp_sm_request('GET', $p);
			if (is_wp_error($json)) { $out[] = $p . ' → ' . mb_substr($json->get_error_message(), 0, 80); continue; }
			$pairs = array();
			cp_panel_collect_labels($json, $pairs);
			$out[] = $p . ' → ' . count($pairs) . ' mező';
			if ($pairs) {
				ksort($pairs, SORT_NATURAL);
				foreach ($pairs as $k => $l) { $out[] = '   ' . $k . ' = ' . $l; }
				break;
			}
		}
		return implode("\n", $out);
	}
	return 'Ismeretlen művelet.';
}

function cp_panel_collect_labels($a, &$pairs, $depth = 0) {
	if (!is_array($a) || $depth > 8) { return; }
	$key = '';
	foreach (array('fieldName', 'systemName', 'name', 'apiName', 'columnName') as $k) {
		if (isset($a[$k]) && is_string($a[$k]) && preg_match('/^[a-zA-Z]+CustomField\d+$/', $a[$k])) { $key = $a[$k]; break; }
	}
	if ($key !== '') {
		foreach (array('displayName', 'label', 'fieldLabel', 'title', 'caption') as $k) {
			if (isset($a[$k]) && is_string($a[$k]) && $a[$k] !== '') { $pairs[$key] = $a[$k]; break; }
		}
	}
	foreach ($a as $v) { if (is_array($v)) { cp_panel_collect_labels($v, $pairs, $depth + 1); } }
}

register_uninstall_hook(__FILE__, 'cp_panel_uninstall');
function cp_panel_uninstall() {
	global $wpdb;
	delete_option(CP_PANEL_OPT);
	delete_option(CP_PANEL_SUBS);
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(CP_PANEL_TRACK) . '%'));
}

/* =====================================================================
 * KEZELŐI ELSZÁMOLÁS (v1.4) – Elszámolás fül + kezelői kitöltő link
 * Tárolás: cp_el_settings, cp_el_kezelok, cp_el_geo, dealenként cp_eld_{id}
 * ===================================================================== */

define('CP_EL_OPT', 'cp_el_settings');
define('CP_EL_KEZ', 'cp_el_kezelok');
define('CP_EL_GEO', 'cp_el_geo');
define('CP_EL_ST', 'cp_eld_');
define('CP_EL_LOCK', 'cp_el_locks');
define('CP_EL_CAL', 'cp_el_cal');

function cp_el_defaults() {
	return array(
		'f_gk' => 'textCustomField8',
		'f_hours' => 'intCustomField3',
		'f_svc' => 'textCustomField13',
		'f_svc2' => 'multiSelectCustomField3',
		'f_start' => 'dateTimeCustomField1',
		'f_kd' => 'intCustomField2',
		'kd_bp' => 10000,
		'gk_write' => 1,
		'h_write' => 1,
		'skip_stages' => "Érdeklődő / Megrendelő\nAjánlat kiküldve",
		'payers' => "Ákos\nAndi\nBalázs",
		'sat' => "Elégedett\nFoglalkozós\nElégedetlen",
		'origin' => '47.4577,19.0424',
		'calendar' => 'EventCrazy',
		'devices' => "Selfie1\nSelfie2\nSelfie3\nSelfie4\nSelfie5\nSelfieStand\nSelfieTükör\nSpinner1\nSpinner2\nVR\nRaceSimulator\nCatch It\nCatchTheLight",
		'gm_opts' => "Jó\nFoglalkozós\nHIBA",
		'pumble_url' => '',
		'pumble_setup' => 1,
		'secret' => '',
		'feed_key' => '',
	);
}

function cp_el_opts() {
	static $cache = null;
	if ($cache !== null) { return $cache; }
	$o = get_option(CP_EL_OPT, array());
	if (!is_array($o)) { $o = array(); }
	$o = array_merge(cp_el_defaults(), $o);
	/* v1.8.2: rendezvényfotózás / -film nem választható az adatlapon */
	$o['devices'] = implode("\n", array_values(array_filter(cp_panel_lines($o['devices']), function ($x) { return !cp_svc_is_excluded($x); })));
	if ($o['secret'] === '' || $o['feed_key'] === '') {
		if ($o['secret'] === '') { $o['secret'] = wp_generate_password(40, false, false); }
		if ($o['feed_key'] === '') { $o['feed_key'] = wp_generate_password(24, false, false); }
		update_option(CP_EL_OPT, $o, false);
	}
	$cache = $o;
	return $o;
}

function cp_el_s($a, $k) {
	return (is_array($a) && isset($a[$k]) && is_scalar($a[$k])) ? trim((string) $a[$k]) : '';
}

/* ------------------------------------------------------------ hónap lezárása + változásnapló (v1.5) */

function cp_el_locks() {
	$l = get_option(CP_EL_LOCK, array());
	return is_array($l) ? $l : array();
}

/* a hónap (YYYY-MM) lezárásának adatai, vagy null */
function cp_el_lock_of($ym) {
	$ym = (string) $ym;
	if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { return null; }
	$l = cp_el_locks();
	return (isset($l[$ym]) && is_array($l[$ym])) ? $l[$ym] : null;
}

/* hibával megáll, ha a dátum (YYYY-MM-DD) hónapja le van zárva */
function cp_el_lock_guard($date) {
	$date = (string) $date;
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return; }
	if (cp_el_lock_of(substr($date, 0, 7))) {
		cp_el_err('Ez a hónap le van zárva – módosításhoz előbb nyisd újra a hónapot (Elszámolás fül, Kezelőnként kártya).', 409);
	}
}

function cp_el_log_val($v) {
	if (is_array($v) || is_object($v)) { $v = wp_json_encode($v); }
	return mb_substr((string) $v, 0, 80);
}

/* változás bejegyzése a rendezvény tárolójába (legújabb elöl, legfeljebb 60 bejegyzés) */
function cp_el_log(&$st, $src, $i, $f, $old, $new) {
	$old = cp_el_log_val($old);
	$new = cp_el_log_val($new);
	if ($old === $new) { return; }
	if (!isset($st['lg']) || !is_array($st['lg'])) { $st['lg'] = array(); }
	array_unshift($st['lg'], array('t' => time(), 's' => (string) $src, 'i' => (string) $i, 'f' => (string) $f, 'o' => $old, 'n' => $new));
	$st['lg'] = array_slice($st['lg'], 0, 60);
}

function cp_el_hx_values() {
	$out = array('', '0');
	for ($i = 1; $i <= 16; $i++) {
		$out[] = rtrim(rtrim(number_format($i / 2, 1, '.', ''), '0'), '.');
	}
	return $out;
}

/* ------------------------------------------------------------ kezelők */

function cp_el_roles() {
	return array(
		'kezelo' => array('rate' => 4500, 'pack' => 4500, 'hxr' => 9000, 'bp' => 5000, 'km' => 120, 'sup' => 2500, 'min' => 15000),
		'vezeto' => array('rate' => 5000, 'pack' => 5000, 'hxr' => 10000, 'bp' => 5000, 'km' => 120, 'sup' => 2500, 'min' => 15000),
		'host' => array('rate' => 3000, 'pack' => 0, 'hxr' => 6000, 'bp' => 0, 'km' => 0, 'sup' => 0, 'min' => 10000),
	);
}

function cp_el_mk($id, $name, $sm, $role, $over = array()) {
	$r = cp_el_roles();
	return array_merge(array('id' => $id, 'name' => $name, 'sm' => $sm, 'role' => $role, 'act' => 1), $r[$role], $over);
}

/* kezdőlista a 2026-os elszámoló táblázat alapján */
function cp_el_seed() {
	return array(
		cp_el_mk('akos', 'Ákos', 'Sebestyén Ákos', 'vezeto', array('bp' => 0, 'km' => 40, 'sup' => 0, 'min' => 0)),
		cp_el_mk('hdani', 'Horváth Dani', 'Horváth Dániel', 'kezelo'),
		cp_el_mk('hkrisz', 'Horváth Krisz', 'Horváth Krisztián', 'kezelo'),
		cp_el_mk('gandi', 'Gyarmati Andi', 'Gyarmati Andi', 'kezelo'),
		cp_el_mk('fdorka', 'Fehér Dorka', 'Fehér Dorka', 'kezelo'),
		cp_el_mk('fmarci', 'Farkas Marci', 'Farkas Marci', 'kezelo'),
		cp_el_mk('bferi', 'Bizco Feri', 'Bizco Feri', 'kezelo'),
		cp_el_mk('bbalazs', 'Bögős Balázs', 'Bögős Balázs', 'kezelo'),
		cp_el_mk('pkatus', 'Purmann Katus', 'Purmann Katus', 'kezelo'),
		cp_el_mk('cjani', 'Csapó Jani', 'Csapó Jani', 'kezelo'),
		cp_el_mk('zviki', 'Zódor Viki', 'Zódor Viki', 'host'),
		cp_el_mk('mkitti', 'Marczis Kitti', 'Marczis Kitti', 'host'),
		cp_el_mk('hdomi', 'Halász Domi', 'Halász Domi', 'host'),
		cp_el_mk('ganiko', 'Győri Anikó', 'Győri Anikó', 'host'),
		cp_el_mk('beugros', 'Beugrós', 'Beugrós', 'host'),
	);
}

function cp_el_kez_all() {
	$l = get_option(CP_EL_KEZ, null);
	if (!is_array($l) || !$l) {
		$l = cp_el_seed();
		update_option(CP_EL_KEZ, $l, false);
	}
	return array_values($l);
}

function cp_el_kez_find($id) {
	if ($id === '' || $id === null) { return null; }
	foreach (cp_el_kez_all() as $k) { if ((string) $k['id'] === (string) $id) { return $k; } }
	return null;
}

function cp_el_kez_by_label($label) {
	$n = cp_panel_lc($label);
	if ($n === '') { return null; }
	foreach (cp_el_kez_all() as $k) {
		if (cp_panel_lc($k['sm']) === $n || cp_panel_lc($k['name']) === $n) { return $k; }
	}
	return null;
}

/* ------------------------------------------------------------ tárolás */

function cp_el_id_ok($id) {
	return is_string($id) && preg_match('/^m?\d{1,20}$/', $id) === 1;
}

function cp_el_st_get($id) {
	$v = get_option(CP_EL_ST . $id, array());
	return is_array($v) ? $v : array();
}

function cp_el_st_put($id, $v) {
	if (!is_array($v)) { $v = array(); }
	$rows = array();
	if (!empty($v['r']) && is_array($v['r'])) {
		foreach ($v['r'] as $row) {
			if (!is_array($row) || empty($row['i'])) { continue; }
			if ($row['i'] === 'r0' && count($row) === 1) { continue; }
			$rows[] = $row;
		}
	}
	$v['r'] = $rows;
	$meaningful = array_diff_key($v, array('d' => 1, 'u' => 1, 'r' => 1));
	if (!$rows && !$meaningful) {
		delete_option(CP_EL_ST . $id);
		return array();
	}
	$v['u'] = time();
	update_option(CP_EL_ST . $id, $v, false);
	return $v;
}

/* [id, adat] párok (a numerikus kulcsok miatt nem asszociatív tömb) */
function cp_el_st_all() {
	global $wpdb;
	$res = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(CP_EL_ST) . '%'), ARRAY_A);
	$out = array();
	foreach ((array) $res as $r) {
		$id = (string) substr($r['option_name'], strlen(CP_EL_ST));
		$v = maybe_unserialize($r['option_value']);
		if (cp_el_id_ok($id) && is_array($v)) { $out[] = array($id, $v); }
	}
	return $out;
}

function cp_el_row_idx($st, $i) {
	if (!empty($st['r']) && is_array($st['r'])) {
		foreach ($st['r'] as $n => $row) {
			if (is_array($row) && isset($row['i']) && $row['i'] === $i) { return (int) $n; }
		}
	}
	return -1;
}

/* a sor indexét adja; az r0 (fő kezelő) sort szükség esetén létrehozza */
function cp_el_row_ensure(&$st, $i) {
	if (!isset($st['r']) || !is_array($st['r'])) { $st['r'] = array(); }
	$st['r'] = array_values($st['r']);
	$n = cp_el_row_idx($st, $i);
	if ($n >= 0) { return $n; }
	if ($i !== 'r0') { return -1; }
	array_unshift($st['r'], array('i' => 'r0'));
	return 0;
}

function cp_el_int($v, $min, $max) {
	$v = trim(str_replace(array(' ', "\xc2\xa0", '.'), '', (string) $v));
	if ($v === '') { return ''; }
	if (!preg_match('/^-?\d{1,9}$/', $v)) { return null; }
	$n = (int) $v;
	return ($n < $min || $n > $max) ? null : $n;
}

function cp_el_dec($v, $max) {
	$v = str_replace(',', '.', trim((string) $v));
	if ($v === '') { return ''; }
	if (!is_numeric($v)) { return null; }
	$n = round(((float) $v) * 4) / 4;
	if ($n < 0 || $n > $max) { return null; }
	return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

/* v1.8.1: papírfogyasztáshoz választható nyomtatótípus */
function cp_el_printers() {
	return array('DS620', 'RX1', 'QW410', 'Kodak');
}

function cp_el_row_fields() {
	return array('k', 'h', 'p', 'kx', 'c', 'cn', 'pk', 'jt', 'pa', 'pt', 'sa', 'no', 'ok', 'rhx', 'sv', 'gm', 'gmn', 'ko', 'hc');
}

/* array(rendben?, érték vagy hibaüzenet); '' = mező törlése */
function cp_el_clean_field($f, $v) {
	$v = (string) $v;
	switch ($f) {
		case 'k':
		case 'ko':
			$v = sanitize_key($v);
			if ($v !== '' && !cp_el_kez_find($v)) { return array(false, 'Ismeretlen kezelő.'); }
			return array(true, $v);
		case 'h':
		case 'hc':
			$x = cp_el_dec($v, 24);
			return $x === null ? array(false, 'Hibás óraszám.') : array(true, $x);
		case 'p':
		case 'kx':
		case 'pk':
		case 'jt':
			$x = cp_el_int($v, 0, 2000000);
			return $x === null ? array(false, 'Hibás összeg.') : array(true, $x);
		case 'c':
			$x = cp_el_int($v, -2000000, 2000000);
			return $x === null ? array(false, 'Hibás korrekció.') : array(true, $x);
		case 'pa':
			$x = cp_el_int($v, 0, 100000);
			return $x === null ? array(false, 'Hibás papírmennyiség.') : array(true, $x);
		case 'pt':
			$v = trim(sanitize_text_field($v));
			if ($v === '') { return array(true, ''); }
			foreach (cp_el_printers() as $s) { if (cp_panel_lc($s) === cp_panel_lc($v)) { return array(true, $s); } }
			return array(false, 'Hibás nyomtatótípus.');
		case 'cn':
		case 'no':
			return array(true, mb_substr(trim(sanitize_textarea_field($v)), 0, 600));
		case 'sa':
			$v = trim(sanitize_text_field($v));
			if ($v === '') { return array(true, ''); }
			$o = cp_el_opts();
			foreach (cp_panel_lines($o['sat']) as $s) { if (cp_panel_lc($s) === cp_panel_lc($v)) { return array(true, $s); } }
			return array(false, 'Hibás elégedettség.');
		case 'rhx':
			$v = trim(str_replace(',', '.', $v));
			return in_array($v, cp_el_hx_values(), true) ? array(true, $v) : array(false, 'Hibás hosszabbítás.');
		case 'sv':
			$want = array();
			foreach (explode(',', sanitize_text_field($v)) as $x) { $x = cp_panel_lc(trim($x)); if ($x !== '') { $want[$x] = 1; } }
			if (!$want) { return array(true, ''); }
			$o = cp_el_opts();
			$out = array();
			foreach (cp_panel_lines($o['devices']) as $s) { if (isset($want[cp_panel_lc($s)])) { $out[] = $s; } }
			return count($out) === count($want) ? array(true, implode(', ', $out)) : array(false, 'Ismeretlen eszköz.');
		case 'gm':
			$v = trim(sanitize_text_field($v));
			if ($v === '') { return array(true, ''); }
			$o = cp_el_opts();
			foreach (cp_panel_lines($o['gm_opts']) as $s) { if (cp_panel_lc($s) === cp_panel_lc($v)) { return array(true, $s); } }
			return array(false, 'Hibás gépállapot.');
		case 'gmn':
			return array(true, mb_substr(trim(sanitize_textarea_field($v)), 0, 600));
		case 'ok':
			return array(true, $v === '1' ? '1' : '');
	}
	return array(false, 'Ismeretlen mező.');
}

/* ------------------------------------------------------------ linkek */

function cp_el_sig($id) {
	$o = cp_el_opts();
	return substr(hash_hmac('sha256', 'el|' . $id, $o['secret']), 0, 14);
}

function cp_el_link($id) {
	$p = cp_panel_opts();
	return home_url('/' . trim($p['slug'], '/') . '/?k=' . rawurlencode($id . '.' . cp_el_sig($id)));
}

function cp_el_check_tok($tok) {
	if (!preg_match('/^(m?\d{1,20})\.([a-f0-9]{14})$/', (string) $tok, $m)) { return ''; }
	return hash_equals(cp_el_sig($m[1]), $m[2]) ? $m[1] : '';
}

/* ------------------------------------------------------------ Salesmate dealek */

function cp_el_sm_fields() {
	$p = cp_panel_opts();
	$o = cp_el_opts();
	$f = array('deal.id', 'deal.title', 'deal.stage', 'deal.status', 'deal.pipeline', 'deal.owner', 'deal.primaryContact');
	foreach (array($p['f_date'], $p['f_loc'], $p['f_selfie'], $p['f_spin'], $p['f_hx'], $o['f_gk'], $o['f_hours'], $o['f_svc'], $o['f_svc2'], $o['f_start'], $o['f_kd']) as $k) {
		if ($k !== '') { $f[] = 'deal.' . $k; }
	}
	return array_values(array_unique($f));
}

function cp_el_who($title, $fallback = '') {
	$t = preg_replace('/^\s*\[[^\]]*\]\s*/u', '', (string) $title);
	$t = preg_replace('/\s*\|.*$/u', '', (string) $t);
	$t = preg_replace('/[\s,–-]*\d{4}[-.]\s?\d{2}[-.]\s?\d{2}\.?\s*$/u', '', (string) $t);
	$t = trim((string) $t);
	return $t !== '' ? $t : (string) $fallback;
}

/* üres dátum-idő mező: a Salesmate 0-t (1970) ad vissza */
function cp_el_time($v) {
	if ($v === null || $v === '' || $v === false) { return ''; }
	if (is_numeric($v)) {
		$ts = (float) $v;
		if ($ts > 100000000000) { $ts = $ts / 1000; }
		if ($ts < 946684800) { return ''; }
	} elseif (preg_match('/^\s*(19\d\d|0000)/', (string) $v)) {
		return '';
	}
	return cp_panel_norm_time($v);
}

function cp_el_norm_one($r) {
	$p = cp_panel_opts();
	$o = cp_el_opts();
	$id = cp_panel_str(cp_panel_get($r, 'id'));
	if ($id === '') { return null; }
	$title = cp_panel_str(cp_panel_get($r, 'title'));
	$pc = cp_panel_get($r, 'primaryContact');
	$cname = is_array($pc) ? cp_panel_str($pc) : '';
	$svc = cp_panel_str(cp_panel_get($r, $o['f_svc']));
	$svc2 = trim(cp_panel_str(cp_panel_get($r, $o['f_svc2'])), " ,");
	$svc2 = $svc2 === '' ? '' : implode(', ', array_filter(array_map('trim', explode(',', $svc2))));
	$sp = cp_panel_str(cp_panel_get($r, $p['f_selfie']));
	$spin = cp_panel_str(cp_panel_get($r, $p['f_spin']));
	$svcx = cp_svc_only_excluded(array($svc, $svc2, cp_panel_get($r, $o['f_svc2']), $sp, $spin));
	$svcTxt = implode(' · ', array_values(array_unique(array_filter(array($svc, $svc2)))));
	if ($svcTxt === '') { $svcTxt = implode(' + ', array_filter(array($sp, $spin))); }
	$h = $o['f_hours'] !== '' ? cp_panel_get($r, $o['f_hours']) : null;
	$status = cp_panel_lc(cp_panel_str(cp_panel_get($r, 'status')));
	return array(
		'id' => $id,
		'title' => $title,
		'who' => cp_el_who($title, $cname !== '' ? $cname : 'Deal #' . $id),
		'date' => cp_panel_norm_date(cp_panel_get($r, $p['f_date'])),
		'time' => $o['f_start'] !== '' ? cp_el_time(cp_panel_get($r, $o['f_start'])) : '',
		'stage' => cp_panel_str(cp_panel_get($r, 'stage')),
		'status' => $status,
		'won' => $status === 'won',
		'loc' => cp_panel_str(cp_panel_get($r, $p['f_loc'])),
		'hours' => is_numeric($h) ? rtrim(rtrim(number_format((float) $h, 2, '.', ''), '0'), '.') : '',
		'gk' => $o['f_gk'] !== '' ? cp_panel_str(cp_panel_get($r, $o['f_gk'])) : '',
		'hx' => $p['f_hx'] !== '' ? cp_panel_hx_parse(cp_panel_get($r, $p['f_hx'])) : '',
		'svc' => $svcTxt,
		'svcx' => $svcx,
		'kd' => ($o['f_kd'] !== '' && is_numeric(cp_panel_get($r, $o['f_kd']))) ? (int) round((float) cp_panel_get($r, $o['f_kd'])) : null,
	);
}

function cp_el_normalize($rows, $from, $to) {
	$o = cp_el_opts();
	$skip = array_map('cp_panel_lc', cp_panel_lines($o['skip_stages']));
	if (function_exists('cp_portal_sent_stage') && cp_portal_sent_stage() !== '') { $skip[] = cp_panel_lc(cp_portal_sent_stage()); }  // a szerződés előtt még nincs elszámolás
	$out = array();
	$seen = array();
	foreach ((array) $rows as $r) {
		$d = cp_el_norm_one($r);
		if (!$d || isset($seen[$d['id']])) { continue; }
		$seen[$d['id']] = 1;
		if ($d['status'] === 'lost') { continue; }
		if (!$d['won'] && in_array(cp_panel_lc($d['stage']), $skip, true)) { continue; }
		if ($d['date'] === '' || $d['date'] < $from || $d['date'] > $to) { continue; }
		if (!empty($d['svcx'])) { continue; }
		unset($d['status']);
		$out[] = $d;
	}
	usort($out, function ($a, $b) { return strcmp($a['date'] . ($a['time'] !== '' ? $a['time'] : '99'), $b['date'] . ($b['time'] !== '' ? $b['time'] : '99')); });
	return $out;
}

function cp_el_deals($from, $to, $force = false) {
	$p = cp_panel_opts();
	$o = cp_el_opts();
	$fields = cp_el_sm_fields();
	// a cp_panel_d_ előtag miatt a meglévő gyorsítótár-ürítés ezt is törli
	$ck = 'cp_panel_d_el' . md5($from . $to . wp_json_encode($fields) . $o['skip_stages']);
	$c = $force ? false : get_transient($ck);
	if (!is_array($c)) {
		$info = null;
		$rows = cp_sm_fetch_range($from, $to, $info, $fields);
		if (is_wp_error($rows)) { return $rows; }
		$c = array('deals' => cp_el_normalize($rows, $from, $to), 'at' => time());
		set_transient($ck, $c, max(1, (int) $p['cache_min']) * MINUTE_IN_SECONDS);
	}
	return $c;
}

function cp_el_manual_deal($id, $st) {
	return array(
		'id' => $id,
		'manual' => true,
		'title' => cp_el_s($st, 'title'),
		'who' => cp_el_s($st, 'title'),
		'date' => cp_el_s($st, 'd'),
		'time' => '',
		'stage' => 'Kézi tétel',
		'won' => false,
		'loc' => cp_el_s($st, 'loc'),
		'hours' => cp_el_s($st, 'dh'),
		'gk' => '',
		'svc' => cp_el_s($st, 'svc'),
	);
}

/* ------------------------------------------------------------ AJAX segédek */

function cp_el_auth() {
	nocache_headers();
	if (!cp_panel_authed() || !hash_equals(cp_panel_csrf(), isset($_REQUEST['t']) ? (string) wp_unslash($_REQUEST['t']) : '')) {
		wp_send_json_error(array('msg' => 'auth'), 403);
	}
}

function cp_el_err($msg, $code = 400) {
	wp_send_json_error(array('msg' => $msg), $code);
}

function cp_el_req_date($key) {
	$d = isset($_REQUEST[$key]) ? preg_replace('/[^0-9-]/', '', (string) wp_unslash($_REQUEST[$key])) : '';
	return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
}

function cp_el_req_rid() {
	$i = isset($_REQUEST['i']) ? preg_replace('/[^a-z0-9]/', '', (string) wp_unslash($_REQUEST['i'])) : '';
	return $i !== '' ? substr($i, 0, 12) : 'r0';
}

function cp_el_req_postid() {
	$id = isset($_REQUEST['id']) ? (string) wp_unslash($_REQUEST['id']) : '';
	if (!cp_el_id_ok($id)) { cp_el_err('Hibás kérés.'); }
	return $id;
}

/* kezelői link (k=…) VAGY bejelentkezett panel; array(id, 'tok'|'panel') */
function cp_el_req_id() {
	nocache_headers();
	$k = isset($_REQUEST['k']) ? (string) wp_unslash($_REQUEST['k']) : '';
	if ($k !== '') {
		$id = cp_el_check_tok($k);
		if ($id === '') { cp_el_err('Érvénytelen link.', 403); }
		return array($id, 'tok');
	}
	if (!cp_panel_authed()) { cp_el_err('auth', 403); }
	$get = isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'GET';
	if (!$get && !hash_equals(cp_panel_csrf(), isset($_REQUEST['t']) ? (string) wp_unslash($_REQUEST['t']) : '')) { cp_el_err('auth', 403); }
	return array(cp_el_req_postid(), 'panel');
}

function cp_el_range() {
	$from = cp_el_req_date('from');
	$to = cp_el_req_date('to');
	if ($from === '' || $to === '' || $to < $from) { cp_el_err('Hibás dátumtartomány.'); }
	if ((strtotime($to) - strtotime($from)) > 120 * DAY_IN_SECONDS) { cp_el_err('Legfeljebb 120 napos tartomány kérhető le.'); }
	return array($from, $to);
}

/* ------------------------------------------------------------ AJAX: adatok */

add_action('wp_ajax_nopriv_cp_el_data', 'cp_el_ajax_data');
add_action('wp_ajax_cp_el_data', 'cp_el_ajax_data');
function cp_el_ajax_data() {
	cp_el_auth();
	list($from, $to) = cp_el_range();
	$c = cp_el_deals($from, $to, !empty($_REQUEST['refresh']));
	if (is_wp_error($c)) { cp_el_err($c->get_error_message(), 502); }
	$deals = $c['deals'];
	$ids = array();
	foreach ($deals as $d) { $ids[(string) $d['id']] = 1; }
	$store = array();
	foreach (cp_el_st_all() as $pair) {
		list($id, $v) = $pair;
		if (isset($ids[$id])) {
			$store[$id] = $v;
		} elseif ($id[0] === 'm' && !empty($v['d']) && $v['d'] >= $from && $v['d'] <= $to) {
			$store[$id] = $v;
			$deals[] = cp_el_manual_deal($id, $v);
		}
	}
	usort($deals, function ($a, $b) { return strcmp($a['date'] . ($a['time'] !== '' ? $a['time'] : '99'), $b['date'] . ($b['time'] !== '' ? $b['time'] : '99')); });
	$track = cp_panel_track_all();
	$gc = get_option(CP_EL_GEO, array());
	if (!is_array($gc)) { $gc = array(); }
	$hx = array();
	$links = array();
	$geo = array();
	foreach ($deals as $d) {
		$id = (string) $d['id'];
		$links[$id] = cp_el_link($id);
		if (empty($d['manual'])) {
			if (isset($d['hx']) && $d['hx'] !== '') { $hx[$id] = (string) $d['hx']; }
			elseif (isset($track[$id]['hx']) && $track[$id]['hx'] !== '') { $hx[$id] = (string) $track[$id]['hx']; }
		}
		if ($d['loc'] !== '') {
			$gk = cp_el_geo_key($d['loc']);
			if (isset($gc[$gk])) { $geo[$id] = $gc[$gk]; }
		}
	}
	$calAll = get_option(CP_EL_CAL, array());
	if (!is_array($calAll)) { $calAll = array(); }
	$calOut = array();
	$geoc = array();
	foreach ($deals as $d) {
		$id = (string) $d['id'];
		if (!isset($calAll[$id]) || !is_array($calAll[$id])) { continue; }
		$calOut[$id] = cp_el_cal_resolve($calAll[$id], isset($store[$id]) ? (array) $store[$id] : array(), isset($d['gk']) ? (string) $d['gk'] : '');
		$gl = isset($calAll[$id]['gl']) ? (string) $calAll[$id]['gl'] : '';
		if ($gl !== '') {
			$gk2 = cp_el_geo_key($gl);
			if (isset($gc[$gk2])) { $geoc[$id] = $gc[$gk2]; }
		}
	}
	$st_out = array();
	foreach ($store as $id => $v) { $st_out[(string) $id] = (object) $v; }
	wp_send_json_success(array(
		'deals' => $deals,
		'store' => (object) $st_out,
		'hx' => (object) $hx,
		'links' => (object) $links,
		'geo' => (object) $geo,
		'kez' => cp_el_kez_all(),
		'at' => $c['at'],
		'lock' => cp_el_lock_of(substr($from, 0, 7)),
		'cal' => (object) $calOut,
		'geoc' => (object) $geoc,
		'calSync' => get_option('cp_el_cal_at', null),
		'portal' => function_exists('cp_portal_panel_map') ? cp_portal_panel_map(array_keys($ids)) : new stdClass(),
	));
}

/* ------------------------------------------------------------ AJAX: mentés (panel) */

add_action('wp_ajax_nopriv_cp_el_save', 'cp_el_ajax_save');
add_action('wp_ajax_cp_el_save', 'cp_el_ajax_save');
function cp_el_ajax_save() {
	cp_el_auth();
	$id = cp_el_req_postid();
	$i = cp_el_req_rid();
	$fields = json_decode(isset($_POST['fields']) ? (string) wp_unslash($_POST['fields']) : '', true);
	if (!is_array($fields) || !$fields || count($fields) > 30) { cp_el_err('Hibás kérés.'); }
	cp_el_lock_guard(cp_el_req_date('d') !== '' ? cp_el_req_date('d') : cp_el_s(cp_el_st_get($id), 'd'));
	$o = cp_el_opts();
	$isSm = ctype_digit($id);
	$st = cp_el_st_get($id);
	$date = cp_el_req_date('d');
	if ($date !== '') { $st['d'] = $date; }
	$res = array();
	$warn = array();
	$flush = false;
	foreach ($fields as $f => $v) {
		$f = (string) $f;
		$v = is_scalar($v) ? (string) $v : '';
		/* dealszintű mezők */
		if ($f === 'km') {
			$x = cp_el_int($v, 0, 3000);
			if ($x === null) { cp_el_err('Hibás km.'); }
			cp_el_log($st, 'panel', '', 'km', isset($st['km']) ? $st['km'] : '', $x);
			if ($x === '') { unset($st['km']); } else { $st['km'] = $x; }
			continue;
		}
		if ($f === 'hx') {
			if ($isSm) { cp_el_err('A hosszabbítás a Követés mezőjébe mentődik.'); }
			if (!in_array($v, cp_el_hx_values(), true)) { cp_el_err('Hibás hosszabbítás.'); }
			cp_el_log($st, 'panel', '', 'hx', isset($st['hx']) ? $st['hx'] : '', $v);
			if ($v === '') { unset($st['hx']); } else { $st['hx'] = $v; }
			continue;
		}
		/* soronkénti mezők */
		if (!in_array($f, cp_el_row_fields(), true)) { cp_el_err('Ismeretlen mező: ' . $f); }
		$n = cp_el_row_ensure($st, $i);
		if ($n < 0) { cp_el_err('Ez a sor már nem létezik – frissítsd az oldalt.', 409); }
		list($ok, $x) = cp_el_clean_field($f, $v);
		if (!$ok) { cp_el_err($x); }
		$row = $st['r'][$n];
		cp_el_log($st, 'panel', $i, $f, isset($row[$f]) ? $row[$f] : (($f === 'h' && $i === 'r0' && isset($_POST['smv'])) ? (string) wp_unslash($_POST['smv']) : ''), $x);

		if ($isSm && $i === 'r0' && $f === 'k' && !empty($o['gk_write']) && $o['f_gk'] !== '') {
			$kz = $x === '' ? null : cp_el_kez_find($x);
			$label = $kz ? (string) $kz['sm'] : '';
			$w = cp_sm_write_field($id, $o['f_gk'], $label);
			if (is_wp_error($w)) { cp_el_err('Salesmate: ' . $w->get_error_message(), 502); }
			$now = cp_panel_str($w);
			$res['gk'] = $now;
			$flush = true;
			if (cp_panel_lc($now) === cp_panel_lc($label)) {
				unset($row['k'], $row['kf'], $row['ksm']);
			} else {
				$row['k'] = $x;
				$row['kf'] = 1;
				$row['ksm'] = $now;
				$warn[] = $label === '' ? 'A Salesmate-ben nem sikerült törölni a kezelőt – a panelben elmentettem.' : 'A Salesmate „Gépkezelő asszisztens” listájában nincs „' . $label . '” – a panelben elmentettem, a Salesmate-ben vedd fel a listára (vagy a kezelőnél állítsd be a pontos Salesmate-nevet).';
			}
			$st['r'][$n] = $row;
			continue;
		}

		if ($isSm && $i === 'r0' && $f === 'h' && $o['f_hours'] !== '') {
			$smv = isset($_POST['smv']) ? (string) wp_unslash($_POST['smv']) : '';
			if ($x === '' || $x === $smv) {
				unset($row['h'], $row['hsm']);
			} elseif (!empty($o['h_write']) && preg_match('/^\d+$/', $x)) {
				$w = cp_sm_write_field($id, $o['f_hours'], (int) $x);
				if (is_wp_error($w)) { cp_el_err('Salesmate: ' . $w->get_error_message(), 502); }
				$now = is_numeric($w) ? rtrim(rtrim(number_format((float) $w, 2, '.', ''), '0'), '.') : '';
				$res['hours'] = $now;
				$flush = true;
				if ($now === $x) {
					unset($row['h'], $row['hsm']);
				} else {
					$row['h'] = $x;
					$row['hsm'] = $now;
					$warn[] = 'A Salesmate nem fogadta el az óraszámot – a panelben elmentettem.';
				}
			} else {
				// félóra: a Salesmate egész órás mezőjébe nem írható, csak a panelben él
				$row['h'] = $x;
				$row['hsm'] = $smv;
			}
			$st['r'][$n] = $row;
			continue;
		}

		if ($x === '') { unset($row[$f]); } else { $row[$f] = $x; }
		if ($f === 'ok') {
			if ($x === '1') { $row['okt'] = time(); } else { unset($row['okt']); }
		}
		$st['r'][$n] = $row;
	}
	$st = cp_el_st_put($id, $st);
	if ($flush) {
		cp_panel_flush_cache();
		delete_transient('cp_el_fd_' . $id);
	}
	$res['store'] = (object) $st;
	if ($warn) { $res['warn'] = implode(' ', $warn); }
	wp_send_json_success($res);
}

/* ------------------------------------------------------------ AJAX: sor hozzáadása / törlése */

add_action('wp_ajax_nopriv_cp_el_row', 'cp_el_ajax_row');
add_action('wp_ajax_cp_el_row', 'cp_el_ajax_row');
function cp_el_ajax_row() {
	cp_el_auth();
	$id = cp_el_req_postid();
	$op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
	cp_el_lock_guard(cp_el_req_date('d') !== '' ? cp_el_req_date('d') : cp_el_s(cp_el_st_get($id), 'd'));
	$st = cp_el_st_get($id);
	$date = cp_el_req_date('d');
	if ($date !== '') { $st['d'] = $date; }
	if ($op === 'add') {
		cp_el_row_ensure($st, 'r0');
		if (count($st['r']) >= 12) { cp_el_err('Egy rendezvényhez legfeljebb 12 kezelő vehető fel.'); }
		$row = array('i' => 'r' . strtolower(wp_generate_password(6, false, false)), 'x' => 1);
		$k = isset($_POST['k']) ? sanitize_key(wp_unslash($_POST['k'])) : '';
		if ($k !== '' && cp_el_kez_find($k)) { $row['k'] = $k; }
		$st['r'][] = $row;
		cp_el_log($st, 'panel', $row['i'], 'row', '', 'új kezelő-sor');
	} elseif ($op === 'del') {
		$i = cp_el_req_rid();
		if ($i === 'r0') { cp_el_err('A fő kezelő sora nem törölhető.'); }
		$n = cp_el_row_idx($st, $i);
		if ($n < 0) { cp_el_err('Ez a sor már nem létezik.', 409); }
		if (!empty($st['r'][$n]['pd'])) { cp_el_err('Kifizetett sort nem lehet törölni – előbb vond vissza a kifizetést.'); }
		cp_el_photos_rm($st['r'][$n]);
		cp_el_log($st, 'panel', $i, 'row', 'kezelő-sor', 'törölve');
		array_splice($st['r'], $n, 1);
	} else {
		cp_el_err('Hibás kérés.');
	}
	$st = cp_el_st_put($id, $st);
	wp_send_json_success(array('store' => (object) $st));
}

/* ------------------------------------------------------------ AJAX: kézi tétel */

add_action('wp_ajax_nopriv_cp_el_manual', 'cp_el_ajax_manual');
add_action('wp_ajax_cp_el_manual', 'cp_el_ajax_manual');
function cp_el_ajax_manual() {
	cp_el_auth();
	$op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : 'save';
	$rid = isset($_POST['id']) ? (string) wp_unslash($_POST['id']) : '';
	if ($rid !== '' && cp_el_id_ok($rid)) { cp_el_lock_guard(cp_el_s(cp_el_st_get($rid), 'd')); }
	if ($op !== 'del') { cp_el_lock_guard(cp_el_req_date('date')); }
	if ($op === 'del') {
		if (!cp_el_id_ok($rid) || $rid[0] !== 'm') { cp_el_err('Hibás kérés.'); }
		$st = cp_el_st_get($rid);
		foreach ((array) (isset($st['r']) ? $st['r'] : array()) as $row) {
			if (!empty($row['pd'])) { cp_el_err('Kifizetett tételt nem lehet törölni.'); }
		}
		foreach ((array) (isset($st['r']) ? $st['r'] : array()) as $row) { cp_el_photos_rm($row); }
		delete_option(CP_EL_ST . $rid);
		wp_send_json_success(array('id' => $rid));
	}
	if ($rid === '') { $rid = 'm' . time() . wp_rand(10, 99); }
	if (!cp_el_id_ok($rid) || $rid[0] !== 'm') { cp_el_err('Hibás kérés.'); }
	$date = cp_el_req_date('date');
	if ($date === '') { cp_el_err('Add meg a dátumot.'); }
	$title = mb_substr(sanitize_text_field(cp_el_s(wp_unslash($_POST), 'title')), 0, 120);
	if ($title === '') { cp_el_err('Add meg az ügyfél / tétel nevét.'); }
	$h = cp_el_dec(cp_el_s(wp_unslash($_POST), 'dh'), 24);
	if ($h === null) { cp_el_err('Hibás óraszám.'); }
	$st = cp_el_st_get($rid);
	$st['d'] = $date;
	$st['title'] = $title;
	$st['loc'] = mb_substr(sanitize_text_field(cp_el_s(wp_unslash($_POST), 'loc')), 0, 200);
	$st['svc'] = mb_substr(sanitize_text_field(cp_el_s(wp_unslash($_POST), 'svc')), 0, 120);
	if ($h === '') { unset($st['dh']); } else { $st['dh'] = $h; }
	$n = cp_el_row_ensure($st, 'r0');
	$k = sanitize_key(cp_el_s(wp_unslash($_POST), 'k'));
	if ($k !== '' && cp_el_kez_find($k)) { $st['r'][$n]['k'] = $k; }
	$st['r'][$n]['x'] = 1;
	$st = cp_el_st_put($rid, $st);
	wp_send_json_success(array('id' => $rid, 'store' => (object) $st, 'deal' => cp_el_manual_deal($rid, $st), 'link' => cp_el_link($rid)));
}

/* ------------------------------------------------------------ AJAX: kezelők */

add_action('wp_ajax_nopriv_cp_el_kez', 'cp_el_ajax_kez');
add_action('wp_ajax_cp_el_kez', 'cp_el_ajax_kez');
function cp_el_ajax_kez() {
	cp_el_auth();
	$in = json_decode(isset($_POST['k']) ? (string) wp_unslash($_POST['k']) : '', true);
	if (!is_array($in)) { cp_el_err('Hibás kérés.'); }
	$name = mb_substr(sanitize_text_field(cp_el_s($in, 'name')), 0, 60);
	if ($name === '') { cp_el_err('Add meg a kezelő nevét.'); }
	$roles = cp_el_roles();
	$role = isset($roles[cp_el_s($in, 'role')]) ? cp_el_s($in, 'role') : 'kezelo';
	$sm = mb_substr(sanitize_text_field(cp_el_s($in, 'sm')), 0, 80);
	$rec = array('id' => '', 'name' => $name, 'sm' => $sm !== '' ? $sm : $name, 'role' => $role, 'act' => cp_el_s($in, 'act') === '0' ? 0 : 1);
	$rec['em'] = mb_substr(strtolower(preg_replace('/\s+/', '', sanitize_text_field(cp_el_s($in, 'em')))), 0, 200);
	foreach (array('rate', 'pack', 'hxr', 'bp', 'km', 'sup', 'min') as $f) {
		$x = cp_el_int(cp_el_s($in, $f), 0, 1000000);
		if ($x === null) { cp_el_err('Hibás érték: ' . $f); }
		$rec[$f] = $x === '' ? $roles[$role][$f] : $x;
	}
	$list = cp_el_kez_all();
	$id = sanitize_key(cp_el_s($in, 'id'));
	foreach ($list as $k) {
		if ($k['id'] !== $id && cp_panel_lc($k['name']) === cp_panel_lc($name)) { cp_el_err('Már van ilyen nevű kezelő.'); }
	}
	if ($id === '') {
		$id = 'k' . strtolower(wp_generate_password(6, false, false));
		$rec['id'] = $id;
		$list[] = $rec;
	} else {
		$found = false;
		foreach ($list as $n => $k) {
			if ($k['id'] === $id) { $rec['id'] = $id; $list[$n] = $rec; $found = true; }
		}
		if (!$found) { cp_el_err('Nem található kezelő.', 404); }
	}
	update_option(CP_EL_KEZ, array_values($list), false);
	wp_send_json_success(array('kez' => array_values($list), 'id' => $id));
}

/* ------------------------------------------------------------ AJAX: kifizetés */

add_action('wp_ajax_nopriv_cp_el_pay', 'cp_el_ajax_pay');
add_action('wp_ajax_cp_el_pay', 'cp_el_ajax_pay');
function cp_el_ajax_pay() {
	cp_el_auth();
	$items = isset($_POST['items']) && is_array($_POST['items']) ? array_map('sanitize_text_field', wp_unslash($_POST['items'])) : array();
	$undo = !empty($_POST['undo']);
	$payer = mb_substr(sanitize_text_field(cp_el_s(wp_unslash($_POST), 'payer')), 0, 60);
	$date = cp_el_req_date('pdate');
	$meth = sanitize_key(cp_el_s(wp_unslash($_POST), 'pmeth'));
	if (!in_array($meth, array('atutalas', 'keszpenz'), true)) { $meth = ''; }
	$ym = preg_replace('/[^0-9-]/', '', cp_el_s(wp_unslash($_POST), 'ym'));
	if ($ym !== '' && cp_el_lock_of($ym)) { cp_el_err('Ez a hónap le van zárva – a kifizetés előtt nyisd újra.', 409); }
	if (!$undo && ($payer === '' || $date === '')) { cp_el_err('Add meg, ki és mikor fizette.'); }
	if (!$items || count($items) > 400) { cp_el_err('Nincs kijelölt tétel.'); }
	$by = array();
	foreach ($items as $it) {
		$p = explode('|', (string) $it);
		if (count($p) !== 3 || !cp_el_id_ok($p[0])) { continue; }
		$by[$p[0]][] = array(preg_replace('/[^a-z0-9]/', '', $p[1]), (int) $p[2]);
	}
	$out = array();
	foreach (array_keys($by) as $gid) { cp_el_lock_guard(cp_el_s(cp_el_st_get((string) $gid), 'd')); }
	foreach ($by as $id => $list) {
		$id = (string) $id;
		$st = cp_el_st_get($id);
		foreach ($list as $x) {
			$n = cp_el_row_ensure($st, $x[0]);
			if ($n < 0) { continue; }
			if ($undo) {
				cp_el_log($st, 'panel', $x[0], 'pay', trim(cp_el_s($st['r'][$n], 'pd') . ' ' . cp_el_s($st['r'][$n], 'pv') . ' Ft'), 'visszavonva');
				unset($st['r'][$n]['pd'], $st['r'][$n]['pb'], $st['r'][$n]['pv'], $st['r'][$n]['pm']);
			} else {
				$st['r'][$n]['pd'] = $date;
				$st['r'][$n]['pb'] = $payer;
				$st['r'][$n]['pv'] = $x[1];
				if ($meth !== '') { $st['r'][$n]['pm'] = $meth; } else { unset($st['r'][$n]['pm']); }
				cp_el_log($st, 'panel', $x[0], 'pay', '', $date . ' · ' . $payer . ($meth === 'keszpenz' ? ' · készpénz' : ($meth === 'atutalas' ? ' · átutalás' : '')) . ' · ' . $x[1] . ' Ft');
			}
		}
		$out[$id] = (object) cp_el_st_put($id, $st);
	}
	wp_send_json_success(array('stores' => (object) $out));
}

/* ------------------------------------------------------------ távolság (a gyors ellenőrzés kalkulátorával azonos módszer) */

function cp_el_geo_key($loc) {
	return md5(cp_panel_lc(preg_replace('/\s+/u', ' ', (string) $loc)));
}

function cp_el_http_json($url) {
	$res = wp_remote_get($url, array('timeout' => 12, 'headers' => array('Accept-Language' => 'hu', 'User-Agent' => 'CrazyPanel/' . CP_PANEL_VER . ' (+' . home_url('/') . ')')));
	if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) { return null; }
	$j = json_decode(wp_remote_retrieve_body($res), true);
	return is_array($j) ? $j : null;
}

function cp_el_nominatim($params) {
	$q = array_merge(array('format' => 'json', 'limit' => 1, 'addressdetails' => 1, 'countrycodes' => 'hu'), $params);
	$a = cp_el_http_json('https://nominatim.openstreetmap.org/search?' . http_build_query($q));
	return ($a && isset($a[0]['lat'], $a[0]['lon'])) ? $a[0] : null;
}

function cp_el_geo_lookup($loc) {
	$s = trim(preg_replace('/\s+/u', ' ', (string) $loc));
	$t = time();
	if ($s === '') { return array('err' => 1, 't' => $t); }
	if (preg_match('/\bbudapest\b|^\s*1\d{3}\b|\bbp\.?(\s|,|$)/iu', $s)) {
		return array('n' => 'Budapest', 'b' => 1, 'km' => 0, 't' => $t);
	}
	$tries = array();
	if (preg_match('/(?<!\d)([2-9]\d{3})(?!\d)\s*,?\s*([^\d,;\-–(]{2,40})?/u', $s, $m)) {
		$town = isset($m[2]) ? trim($m[2], " .") : '';
		if ($town !== '') { $tries[] = array('q' => $m[1] . ' ' . $town); }
		$tries[] = array('postalcode' => $m[1]);
	}
	$tries[] = array('q' => $s);
	$parts = preg_split('/\s+[-–]\s+|,/u', $s);
	foreach ($parts as $pp) {
		$pp = trim($pp);
		if (mb_strlen($pp) >= 3 && $pp !== $s) { $tries[] = array('q' => $pp); }
		if (count($tries) >= 5) { break; }
	}
	$g = null;
	foreach ($tries as $n => $q) {
		if ($n > 0) { usleep(1000000); } // Nominatim: max. 1 kérés / mp
		$g = cp_el_nominatim($q);
		if ($g) { break; }
	}
	if (!$g) { return array('err' => 1, 't' => $t); }
	$ad = isset($g['address']) && is_array($g['address']) ? $g['address'] : array();
	if (cp_el_s($ad, 'city') === 'Budapest' || cp_el_s($ad, 'state') === 'Budapest') {
		return array('n' => 'Budapest', 'b' => 1, 'km' => 0, 't' => $t);
	}
	$name = '';
	foreach (array('city', 'town', 'village', 'municipality', 'county') as $kk) {
		if (cp_el_s($ad, $kk) !== '') { $name = cp_el_s($ad, $kk); break; }
	}
	if ($name === '') { $name = cp_el_s($g, 'name') !== '' ? cp_el_s($g, 'name') : $s; }
	$o = cp_el_opts();
	$or = array_map('floatval', explode(',', $o['origin']));
	if (count($or) !== 2) { $or = array(47.4577, 19.0424); }
	$r = cp_el_http_json('https://router.project-osrm.org/route/v1/driving/' . $or[1] . ',' . $or[0] . ';' . $g['lon'] . ',' . $g['lat'] . '?overview=false');
	if (!$r || empty($r['routes'][0]['distance'])) { return array('err' => 1, 'n' => $name, 't' => $t); }
	return array('n' => $name, 'b' => 0, 'km' => (int) round($r['routes'][0]['distance'] / 1000), 't' => $t);
}

function cp_el_geo($loc) {
	$k = cp_el_geo_key($loc);
	$c = get_option(CP_EL_GEO, array());
	if (!is_array($c)) { $c = array(); }
	if (isset($c[$k]) && (empty($c[$k]['err']) || (time() - (int) $c[$k]['t']) < 3 * DAY_IN_SECONDS)) { return $c[$k]; }
	$g = cp_el_geo_lookup($loc);
	$c[$k] = $g;
	if (count($c) > 3000) { $c = array_slice($c, -2500, null, true); }
	update_option(CP_EL_GEO, $c, false);
	return $g;
}

/* ------------------------------------------------------------ Google Naptár → panel (v1.6) */

/* névösszevetéshez: ékezet, kis-nagybetű, írásjel és névsorrend nem számít */
function cp_el_norm_name($s) {
	$s = remove_accents(mb_strtolower((string) $s));
	$s = preg_replace('/[^a-z0-9]+/', ' ', $s);
	$t = array_values(array_filter(explode(' ', trim($s)), function ($x) { return strlen($x) > 1; }));
	sort($t);
	return implode(' ', $t);
}

/* naptári meghívott → kezelő azonosító (név, vagy az e-mail cím névrésze alapján); '' ha nincs ilyen kezelő */
/* e-mail cím névrésze (a @ előtti rész), kisbetűvel */
function cp_el_em_local($s) {
	$s = strtolower(trim((string) $s));
	$p = strpos($s, '@');
	return $p === false ? $s : substr($s, 0, $p);
}

/* naptári meghívott → kezelő azonosító: a kezelőnél megadott naptár e-mail (több is lehet, vesszővel), különben név; '' ha nincs ilyen */
function cp_el_kez_by_guest($name, $email) {
	$el = cp_el_em_local($email);
	if ($el !== '') {
		foreach (cp_el_kez_all() as $k) {
			foreach (explode(',', isset($k['em']) ? (string) $k['em'] : '') as $x) {
				if (cp_el_em_local($x) !== '' && cp_el_em_local($x) === $el) { return (string) $k['id']; }
			}
		}
	}
	$n = cp_el_norm_name($name);
	$local = $el !== '' ? cp_el_norm_name(str_replace(array('.', '_', '-', '+'), ' ', $el)) : '';
	foreach (cp_el_kez_all() as $k) {
		$a = cp_el_norm_name($k['sm']);
		$b = cp_el_norm_name($k['name']);
		if ($n !== '' && ($n === $a || $n === $b)) { return (string) $k['id']; }
		if ($local !== '' && ($local === $a || $local === $b)) { return (string) $k['id']; }
	}
	return '';
}

/* tárolt naptáradat frissítése a mostani kezelőlista szerint (meghívott → kezelő, fő kezelő) */
function cp_el_cal_resolve($rec, $st, $gk) {
	if (!isset($rec['g']) || !is_array($rec['g'])) { $rec['g'] = array(); }
	foreach ($rec['g'] as $n => $x) {
		$nm = cp_el_s($x, 'n');
		$em = cp_el_s($x, 'e');
		if ($em === '' && strpos($nm, ' ') === false) { $em = $nm; }
		$rec['g'][$n]['k'] = cp_el_kez_by_guest($nm, $em);
	}
	$r0k = '';
	foreach ((isset($st['r']) && is_array($st['r'])) ? $st['r'] : array() as $r) {
		if (is_array($r) && isset($r['i']) && $r['i'] === 'r0') { $r0k = cp_el_s($r, 'k'); }
	}
	$rec['k0'] = cp_el_cal_k0($rec, $gk, $r0k);
	return $rec;
}

/* a km-számításhoz használható naptári helyszín: a leírás „Helyszín:” sora vagy a bejegyzés helye, ha van benne irányítószám vagy Budapest */
function cp_el_cal_geo_loc($hl, $loc) {
	// Google-formátum: „Hely neve, Település, Utca, 8220 Magyarország” → „8220 Település, Utca”
	if (preg_match('/^(?:.*,\s*)?([^,]+),\s*([^,]+),\s*(\d{4})\s+Magyarorsz[áa]g\s*$/u', trim((string) $loc), $m)) {
		return $m[3] . ' ' . trim($m[1]) . ', ' . trim($m[2]);
	}
	foreach (array($hl, $loc) as $s) {
		$s = trim((string) $s);
		if ($s !== '' && preg_match('/(?<!\d)\d{4}(?!\d)|budapest/iu', $s)) { return $s; }
	}
	return '';
}

/* a fő kezelő (r0) a naptár szerint: a Salesmate-ben megadott kezelő, ha meghívott; egyébként az első felismert meghívott */
function cp_el_cal_k0($rec, $gk, $r0k = '') {
	$kids = array();
	foreach ($rec['g'] as $x) { if ($x['k'] !== '') { $kids[] = $x['k']; } }
	if (!$kids) { return ''; }
	if ($r0k !== '' && in_array($r0k, $kids, true)) { return $r0k; }
	$m = $gk !== '' ? cp_el_kez_by_label($gk) : null;
	if ($m && in_array((string) $m['id'], $kids, true)) { return (string) $m['id']; }
	return $kids[0];
}

/* lezajlott rendezvény: a fő kezelőn kívüli meghívottak külön kezelő-sort kapnak (ismételt futtatásra sem duplikál) */
function cp_el_cal_rows($id, $rec) {
	$st0 = cp_el_st_get($id);
	if (cp_el_lock_of(substr($rec['d'], 0, 7))) { return false; }
	// már kifizetett tételnél nem nyúl a sorokhoz
	foreach ((isset($st0['r']) && is_array($st0['r'])) ? $st0['r'] : array() as $r) { if (!empty($r['pd'])) { return false; } }
	$st = cp_el_st_get($id);
	$rows = (isset($st['r']) && is_array($st['r'])) ? array_values($st['r']) : array();
	$ko = '';
	$r0k = '';
	foreach ($rows as $r) {
		if (isset($r['i']) && $r['i'] === 'r0') { $ko = cp_el_s($r, 'ko'); $r0k = cp_el_s($r, 'k'); }
	}
	$want = array();
	foreach ($rec['g'] as $x) {
		if ($x['k'] !== '' && $x['k'] !== $rec['k0'] && $x['k'] !== $ko && $x['k'] !== $r0k && !in_array($x['k'], $want, true)) { $want[] = $x['k']; }
	}
	$changed = false;
	$have = array();
	foreach ($rows as $n => $r) {
		if (!isset($r['i']) || $r['i'] === 'r0') { continue; }
		$k = isset($r['k']) ? (string) $r['k'] : '';
		if (!empty($r['ca']) && !in_array($k, $want, true) && !array_diff_key($r, array('i' => 1, 'x' => 1, 'k' => 1, 'ca' => 1))) {
			unset($rows[$n]);
			$changed = true;
			continue;
		}
		$have[] = $k;
	}
	foreach ($want as $k) {
		if (in_array($k, $have, true) || count($rows) >= 12) { continue; }
		$rows[] = array('i' => 'r' . strtolower(wp_generate_password(6, false, false)), 'x' => 1, 'k' => $k, 'ca' => 1);
		$changed = true;
	}
	if (!$changed) { return false; }
	$st['r'] = array_values($rows);
	if (empty($st['d'])) { $st['d'] = $rec['d']; }
	cp_el_log($st, 'naptár', '', 'row', '', 'kezelő-sorok a meghívottak szerint');
	cp_el_st_put($id, $st);
	return true;
}

/* a Google Apps Script óránként elküldi a dealekhez párosított naptárbejegyzések adatait (feed kulccsal) */
add_action('wp_ajax_nopriv_cp_el_calpush', 'cp_el_ajax_calpush');
add_action('wp_ajax_cp_el_calpush', 'cp_el_ajax_calpush');
function cp_el_ajax_calpush() {
	nocache_headers();
	$o = cp_el_opts();
	$key = isset($_GET['key']) ? (string) wp_unslash($_GET['key']) : '';
	if ($key === '' || !hash_equals((string) $o['feed_key'], $key)) { cp_el_err('auth', 403); }
	$in = json_decode((string) file_get_contents('php://input'), true);
	if (!is_array($in) || !isset($in['ev']) || !is_array($in['ev']) || count($in['ev']) > 3000) { cp_el_err('Hibás adat.'); }
	$from = cp_el_s($in, 'from');
	$to = cp_el_s($in, 'to');
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) { cp_el_err('Hibás tartomány.'); }
	$hm = function ($v) {
		$v = trim((string) $v);
		return preg_match('/^([01]?\d|2[0-4])[:.]([0-5]\d)$/', $v, $m) ? sprintf('%02d:%s', (int) $m[1], $m[2]) : '';
	};
	$recs = array();
	foreach ($in['ev'] as $e) {
		if (!is_array($e)) { continue; }
		$id = cp_el_s($e, 'id');
		$d = cp_el_s($e, 'd');
		if (!cp_el_id_ok($id) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d < $from || $d > $to) { continue; }
		$g = array();
		foreach ((isset($e['g']) && is_array($e['g'])) ? array_slice($e['g'], 0, 12) : array() as $x) {
			if (!is_array($x) || strtoupper(cp_el_s($x, 'st')) === 'NO') { continue; }
			$nm = mb_substr(sanitize_text_field(cp_el_s($x, 'n')), 0, 80);
			$em = sanitize_email(cp_el_s($x, 'e'));
			if ($nm === '' && $em === '') { continue; }
			$g[] = array('n' => $nm !== '' ? $nm : cp_el_em_local($em), 'e' => cp_el_em_local($em), 'k' => cp_el_kez_by_guest($nm, $em));
		}
		if (isset($recs[$id])) {
			// ugyanahhoz a dealhez több bejegyzés: a meghívottak összeadódnak, az idő az elsőé marad
			foreach ($g as $x) {
				$dup = false;
				foreach ($recs[$id]['g'] as $y) { if ($y['n'] === $x['n']) { $dup = true; } }
				if (!$dup) { $recs[$id]['g'][] = $x; }
			}
			continue;
		}
		$h = cp_el_dec(cp_el_s($e, 'h'), 24);
		$hl = mb_substr(sanitize_text_field(cp_el_s($e, 'hl')), 0, 200);
		$loc = mb_substr(sanitize_text_field(cp_el_s($e, 'loc')), 0, 200);
		$recs[$id] = array(
			'd' => $d,
			's' => $hm(cp_el_s($e, 's')),
			'e' => $hm(cp_el_s($e, 'e')),
			'as' => $hm(cp_el_s($e, 'as')),
			'ae' => $hm(cp_el_s($e, 'ae')),
			'loc' => $loc,
			'hl' => $hl,
			'gl' => cp_el_cal_geo_loc($hl, $loc),
			'h' => $h === null ? '' : $h,
			'g' => $g,
			't' => time(),
		);
	}
	$gkOf = array();
	$dl = cp_el_deals($from, $to);
	if (!is_wp_error($dl)) { foreach ($dl['deals'] as $d) { $gkOf[(string) $d['id']] = $d['gk']; } }
	$cal = get_option(CP_EL_CAL, array());
	if (!is_array($cal)) { $cal = array(); }
	// a tartományba eső régi adatok cseréje (ami a naptárból kikerült, az innen is)
	foreach ($cal as $id => $c) { if (!is_array($c) || (isset($c['d']) && $c['d'] >= $from && $c['d'] <= $to)) { unset($cal[$id]); } }
	$today = wp_date('Y-m-d');
	$rowsChanged = 0;
	foreach ($recs as $id => $rec) {
		$rec = cp_el_cal_resolve($rec, cp_el_st_get((string) $id), isset($gkOf[$id]) ? (string) $gkOf[$id] : '');
		$cal[$id] = $rec;
		if ($rec['d'] < $today && cp_el_cal_rows((string) $id, $rec)) { $rowsChanged++; }
	}
	$old = wp_date('Y-m-d', time() - 400 * DAY_IN_SECONDS);
	foreach ($cal as $id => $c) { if (!isset($c['d']) || $c['d'] < $old) { unset($cal[$id]); } }
	update_option(CP_EL_CAL, $cal, false);
	update_option('cp_el_cal_at', array('t' => time(), 'n' => count($recs), 'rows' => $rowsChanged, 'from' => $from, 'to' => $to), false);
	wp_send_json_success(array('saved' => count($recs), 'rows' => $rowsChanged));
}

/* ------------------------------------------------------------ AJAX: hónap lezárása (v1.5) */

add_action('wp_ajax_nopriv_cp_el_lock', 'cp_el_ajax_lock');
add_action('wp_ajax_cp_el_lock', 'cp_el_ajax_lock');
function cp_el_ajax_lock() {
	cp_el_auth();
	$ym = isset($_POST['ym']) ? preg_replace('/[^0-9-]/', '', (string) wp_unslash($_POST['ym'])) : '';
	if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { cp_el_err('Hibás hónap.'); }
	$l = cp_el_locks();
	if (!empty($_POST['undo'])) {
		unset($l[$ym]);
	} else {
		$l[$ym] = array(
			't' => time(),
			'n' => max(0, (int) (isset($_POST['n']) ? $_POST['n'] : 0)),
			'sum' => max(0, (int) (isset($_POST['sum']) ? $_POST['sum'] : 0)),
		);
	}
	ksort($l);
	update_option(CP_EL_LOCK, $l, false);
	wp_send_json_success(array('lock' => isset($l[$ym]) ? $l[$ym] : null));
}

add_action('wp_ajax_nopriv_cp_el_geo', 'cp_el_ajax_geo');
add_action('wp_ajax_cp_el_geo', 'cp_el_ajax_geo');
function cp_el_ajax_geo() {
	cp_el_auth();
	$loc = mb_substr(sanitize_text_field(cp_el_s(wp_unslash($_POST), 'loc')), 0, 300);
	if ($loc === '') { cp_el_err('Nincs helyszín.'); }
	if (!empty($_POST['retry'])) {
		$c = get_option(CP_EL_GEO, array());
		if (is_array($c)) { unset($c[cp_el_geo_key($loc)]); update_option(CP_EL_GEO, $c, false); }
	}
	wp_send_json_success(array('geo' => cp_el_geo($loc)));
}

/* ------------------------------------------------------------ fotók */

function cp_el_dir() {
	$u = wp_upload_dir(null, false);
	$dir = trailingslashit($u['basedir']) . 'cp-el';
	if (!is_dir($dir)) {
		wp_mkdir_p($dir);
		@file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
		@file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
	}
	return $dir;
}

function cp_el_photos_rm($row) {
	$dir = cp_el_dir();
	foreach (array('ph', 'pha', 'phs') as $key) {
		if (empty($row[$key]) || !is_array($row[$key])) { continue; }
		foreach ($row[$key] as $n) {
			$n = basename((string) $n);
			if ($n !== '' && is_file($dir . '/' . $n)) { @unlink($dir . '/' . $n); }
		}
	}
}

function cp_el_store_upload($id, $key) {
	if (empty($_FILES[$key]) || !is_array($_FILES[$key]) || !isset($_FILES[$key]['tmp_name']) || is_array($_FILES[$key]['tmp_name'])) {
		return new WP_Error('nf', 'Nem érkezett fájl.');
	}
	$f = $_FILES[$key];
	if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
		return new WP_Error('up', 'A feltöltés nem sikerült (hibakód: ' . (int) $f['error'] . ').');
	}
	if ((int) $f['size'] > 15 * 1024 * 1024) { return new WP_Error('big', 'Túl nagy fájl (max. 15 MB).'); }
	$info = @getimagesize($f['tmp_name']);
	$okTypes = array(IMAGETYPE_JPEG, IMAGETYPE_PNG);
	if (defined('IMAGETYPE_WEBP')) { $okTypes[] = IMAGETYPE_WEBP; }
	if (!$info || !in_array($info[2], $okTypes, true)) { return new WP_Error('type', 'Csak JPG, PNG vagy WebP kép tölthető fel.'); }
	$dir = cp_el_dir();
	$base = $id . '-' . strtolower(wp_generate_password(16, false, false));
	$ed = wp_get_image_editor($f['tmp_name']);
	if (!is_wp_error($ed)) {
		if (method_exists($ed, 'maybe_exif_rotate')) { $ed->maybe_exif_rotate(); }
		$sz = $ed->get_size();
		if ($sz['width'] > 1800 || $sz['height'] > 1800) { $ed->resize(1800, 1800, false); }
		$ed->set_quality(82);
		$sv = $ed->save($dir . '/' . $base . '.jpg', 'image/jpeg');
		if (!is_wp_error($sv) && !empty($sv['path']) && is_file($sv['path']) && basename($sv['path']) === $base . '.jpg') { return $base . '.jpg'; }
	}
	$ext = $info[2] === IMAGETYPE_PNG ? 'png' : ($info[2] === IMAGETYPE_JPEG ? 'jpg' : 'webp');
	if (!@copy($f['tmp_name'], $dir . '/' . $base . '.' . $ext)) { return new WP_Error('mv', 'A fájlt nem sikerült elmenteni.'); }
	return $base . '.' . $ext;
}

function cp_el_img_url($id, $n, $tok = '') {
	return admin_url('admin-ajax.php') . '?action=cp_el_img&' . ($tok !== '' ? 'k=' . rawurlencode($tok) : 'id=' . rawurlencode($id)) . '&n=' . rawurlencode($n);
}

add_action('wp_ajax_nopriv_cp_el_img', 'cp_el_ajax_img');
add_action('wp_ajax_cp_el_img', 'cp_el_ajax_img');
function cp_el_ajax_img() {
	list($id) = cp_el_req_id();
	$n = isset($_GET['n']) ? basename((string) wp_unslash($_GET['n'])) : '';
	$types = array('jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
	if (!preg_match('/^' . preg_quote($id, '/') . '-[a-z0-9]{16}\.(jpg|png|webp)$/', $n, $m)) { status_header(404); exit; }
	$p = cp_el_dir() . '/' . $n;
	if (!is_file($p)) { status_header(404); exit; }
	header_remove('Expires');
	header_remove('Pragma');
	header('Content-Type: ' . $types[$m[1]]);
	header('Content-Length: ' . filesize($p));
	header('Cache-Control: private, max-age=604800');
	header('X-Content-Type-Options: nosniff');
	echo file_get_contents($p);
	exit;
}

/* fotó feltöltése: kezelői linkről vagy a panelből; cat=a → setup / aktivitás fotó, cat=s → nyomtatási statisztika */
function cp_el_req_cat() {
	$c = isset($_REQUEST['cat']) ? (string) wp_unslash($_REQUEST['cat']) : '';
	return $c === 'a' ? 'pha' : ($c === 's' ? 'phs' : 'ph');
}

add_action('wp_ajax_nopriv_cp_el_up', 'cp_el_ajax_up');
add_action('wp_ajax_cp_el_up', 'cp_el_ajax_up');
function cp_el_ajax_up() {
	list($id, $via) = cp_el_req_id();
	$i = cp_el_req_rid();
	$key = cp_el_req_cat();
	$st = cp_el_st_get($id);
	$n = cp_el_row_ensure($st, $i);
	if ($n < 0) { cp_el_err('Ez a sor már nem létezik – töltsd újra az oldalt.', 409); }
	if ($via === 'tok' && !empty($st['r'][$n]['ok'])) { cp_el_err('Ezt a tételt már ellenőrizték – módosításhoz szólj az irodának.'); }
	$ph = (isset($st['r'][$n][$key]) && is_array($st['r'][$n][$key])) ? $st['r'][$n][$key] : array();
	if (count($ph) >= 12) { cp_el_err('Tételenként legfeljebb 12 fotó tölthető fel.'); }
	$name = cp_el_store_upload($id, 'f');
	if (is_wp_error($name)) { cp_el_err($name->get_error_message()); }
	$ph[] = $name;
	$st['r'][$n][$key] = $ph;
	$date = cp_el_req_date('d');
	if ($date !== '') { $st['d'] = $date; }
	$st = cp_el_st_put($id, $st);
	$k = cp_el_row_idx($st, $i);
	if ($key === 'pha' && $k >= 0) { cp_el_pq_add($id, $i, $name); } /* v1.6.6: várólistára – az együtt feltöltöttek egy Pumble üzenetben mennek ki */
	wp_send_json_success(array('row' => $k >= 0 ? (object) $st['r'][$k] : null, 'store' => (object) $st, 'n' => $name, 'pu' => $key === 'pha' ? cp_el_pub_img_url($id, $name) : ''));
}

add_action('wp_ajax_nopriv_cp_el_phdel', 'cp_el_ajax_phdel');
add_action('wp_ajax_cp_el_phdel', 'cp_el_ajax_phdel');
function cp_el_ajax_phdel() {
	list($id, $via) = cp_el_req_id();
	$i = cp_el_req_rid();
	$key = cp_el_req_cat();
	$name = isset($_POST['n']) ? basename((string) wp_unslash($_POST['n'])) : '';
	$st = cp_el_st_get($id);
	$n = cp_el_row_idx($st, $i);
	if ($n < 0) { cp_el_err('Ez a sor már nem létezik.', 409); }
	if ($via === 'tok' && !empty($st['r'][$n]['ok'])) { cp_el_err('Ezt a tételt már ellenőrizték – módosításhoz szólj az irodának.'); }
	$ph = (isset($st['r'][$n][$key]) && is_array($st['r'][$n][$key])) ? $st['r'][$n][$key] : array();
	$pos = array_search($name, $ph, true);
	if ($pos === false) { cp_el_err('Nincs ilyen fotó.', 404); }
	cp_el_photos_rm(array('ph' => array($name)));
	array_splice($ph, $pos, 1);
	if ($ph) { $st['r'][$n][$key] = $ph; } else { unset($st['r'][$n][$key]); }
	$st = cp_el_st_put($id, $st);
	$k = cp_el_row_idx($st, $i);
	wp_send_json_success(array('row' => $k >= 0 ? (object) $st['r'][$k] : null, 'store' => (object) $st));
}

/* ------------------------------------------------------------ kezelői adatlap mentése (link) */

add_action('wp_ajax_nopriv_cp_el_form', 'cp_el_ajax_form');
add_action('wp_ajax_cp_el_form', 'cp_el_ajax_form');
function cp_el_ajax_form() {
	list($id, $via) = cp_el_req_id();
	$i = cp_el_req_rid();
	$st = cp_el_st_get($id);
	$n = cp_el_row_ensure($st, $i);
	if ($n < 0) { cp_el_err('Ez a sor már nem létezik – töltsd újra az oldalt.', 409); }
	if ($via === 'tok' && !empty($st['r'][$n]['ok'])) { cp_el_err('Ezt a tételt már ellenőrizték – módosításhoz szólj az irodának.'); }
	cp_el_lock_guard(cp_el_req_date('d') !== '' ? cp_el_req_date('d') : cp_el_s($st, 'd'));
	foreach (array('pk', 'jt', 'pa', 'pt', 'sa', 'no', 'rhx', 'sv', 'gm', 'gmn') as $f) {
		if (!isset($_POST[$f])) { continue; }
		list($ok, $x) = cp_el_clean_field($f, (string) wp_unslash($_POST[$f]));
		if (!$ok) { cp_el_err($x); }
		cp_el_log($st, $via === 'tok' ? 'kezelő' : 'panel', $i, $f, isset($st['r'][$n][$f]) ? $st['r'][$n][$f] : '', $x);
		if ($x === '') { unset($st['r'][$n][$f]); } else { $st['r'][$n][$f] = $x; }
	}
	$st['r'][$n]['fu'] = time();
	$date = cp_el_req_date('d');
	if ($date !== '') { $st['d'] = $date; }
	$st = cp_el_st_put($id, $st);
	$k = cp_el_row_idx($st, $i);
	wp_send_json_success(array('row' => $k >= 0 ? (object) $st['r'][$k] : null));
}

/* ------------------------------------------------------------ naptár-feed (Google Apps Script) */

add_action('wp_ajax_nopriv_cp_el_feed', 'cp_el_ajax_feed');
add_action('wp_ajax_cp_el_feed', 'cp_el_ajax_feed');
function cp_el_ajax_feed() {
	nocache_headers();
	$o = cp_el_opts();
	$key = isset($_GET['key']) ? (string) wp_unslash($_GET['key']) : '';
	if ($key === '' || !hash_equals((string) $o['feed_key'], $key)) { cp_el_err('auth', 403); }
	$from = cp_el_req_date('from');
	$to = cp_el_req_date('to');
	if ($from === '') { $from = wp_date('Y-m-d', time() - 7 * DAY_IN_SECONDS); }
	if ($to === '') { $to = wp_date('Y-m-d', time() + 90 * DAY_IN_SECONDS); }
	if ($to < $from || (strtotime($to) - strtotime($from)) > 200 * DAY_IN_SECONDS) { cp_el_err('Legfeljebb 120 napos tartomány kérhető le.'); }
	$c = cp_el_deals($from, $to);
	if (is_wp_error($c)) { cp_el_err($c->get_error_message(), 502); }
	$out = array();
	foreach ($c['deals'] as $d) {
		$out[] = array('id' => $d['id'], 'date' => $d['date'], 'who' => $d['who'], 'title' => $d['title'], 'link' => cp_el_link($d['id']));
	}
	foreach (cp_el_st_all() as $pair) {
		list($id, $v) = $pair;
		if ($id[0] === 'm' && !empty($v['d']) && $v['d'] >= $from && $v['d'] <= $to && cp_el_s($v, 'title') !== '') {
			$out[] = array('id' => $id, 'date' => $v['d'], 'who' => cp_el_s($v, 'title'), 'title' => cp_el_s($v, 'title'), 'link' => cp_el_link($id));
		}
	}
	wp_send_json_success(array(
		'deals' => $out,
		// a naptárbejegyzés elejére írt blokk – az Apps Script innen veszi, így a formátum a script cseréje nélkül is módosítható
		'desc' => array(
			'tpl' => '<b>📋 KEZELŐI ADATLAP</b> – a rendezvény után itt töltsd ki:<br><b><a href="{{LINK}}">{{LINK}}</a></b><br>━━━━━━━━━━━━━━━━━━━━<br><br>',
			'mark' => 'KEZELŐI ADATLAP',
			'today' => wp_date('Y-m-d'),
		),
	));
}

/* ------------------------------------------------------------ panel-konfiguráció */

function cp_el_cfg() {
	$o = cp_el_opts();
	return array(
		'roles' => cp_el_roles(),
		'sat' => cp_panel_lines($o['sat']),
		'payers' => cp_panel_lines($o['payers']),
		'gkWrite' => !empty($o['gk_write']) && $o['f_gk'] !== '',
		'hWrite' => !empty($o['h_write']) && $o['f_hours'] !== '',
		'img' => admin_url('admin-ajax.php') . '?action=cp_el_img',
		'devices' => cp_panel_lines($o['devices']),
		'gmOpts' => cp_panel_lines($o['gm_opts']),
		'pumble' => trim((string) $o['pumble_url']) !== '' && !empty($o['pumble_setup']),
		'kdBp' => (int) $o['kd_bp'],
	);
}

/* ------------------------------------------------------------ kezelői adatlap (jelszó nélküli oldal) */

function cp_el_form_event($id) {
	if ($id[0] === 'm') {
		$st = cp_el_st_get($id);
		return cp_el_s($st, 'title') !== '' ? cp_el_manual_deal($id, $st) : null;
	}
	$ck = 'cp_el_fd_' . $id;
	$d = get_transient($ck);
	if (!is_array($d)) {
		$raw = cp_sm_get_deal($id);
		if (is_wp_error($raw)) { return $raw; }
		$d = cp_el_norm_one($raw);
		if (!$d) { return null; }
		set_transient($ck, $d, 10 * MINUTE_IN_SECONDS);
	}
	return $d;
}

function cp_el_row_kname($deal, $row) {
	$o = cp_el_opts();
	$isR0 = isset($row['i']) && $row['i'] === 'r0';
	if ($isR0 && empty($deal['manual']) && !empty($o['gk_write'])) {
		if (!empty($row['kf']) && cp_panel_lc(cp_el_s($row, 'ksm')) === cp_panel_lc($deal['gk'])) {
			$k = cp_el_kez_find(cp_el_s($row, 'k'));
			return $k ? $k['name'] : '';
		}
		$k = cp_el_kez_by_label($deal['gk']);
		if ($k) { return $k['name']; }
		if ($deal['gk'] !== '') { return $deal['gk']; }
	}
	$k = cp_el_kez_find(cp_el_s($row, 'k'));
	if ($k) { return $k['name']; }
	if ($isR0 && $deal['gk'] !== '') { return $deal['gk']; }
	return '';
}

function cp_el_hu_date($ymd) {
	$m = array('január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
	$d = array('vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat');
	$ts = strtotime($ymd . ' 12:00:00');
	if (!$ts) { return $ymd; }
	return gmdate('Y', $ts) . '. ' . $m[(int) gmdate('n', $ts) - 1] . ' ' . (int) gmdate('j', $ts) . '. (' . $d[(int) gmdate('w', $ts)] . ')';
}

function cp_el_render_form($tok) {
	$id = cp_el_check_tok($tok);
	cp_panel_head('Kezelői adatlap – Crazy panel');
	$top = '<header class="cpk-top"><div class="cpx-logo">Crazy<span>panel</span></div><span>Kezelői adatlap</span></header>';
	$ev = $id !== '' ? cp_el_form_event($id) : null;
	if ($id === '' || !$ev || is_wp_error($ev)) {
		$msg = $id === '' ? 'Ez a link érvénytelen. Kérd el újra az irodától.' : (is_wp_error($ev) ? 'Most nem érem el a rendezvény adatait. Próbáld újra pár perc múlva.' : 'Ez a rendezvény már nem található.');
		echo '<body class="cpk">' . $top . '<main class="cpk-main"><div class="cpk-card"><p>' . esc_html($msg) . '</p></div></main></body></html>';
		return;
	}
	$o = cp_el_opts();
	$st = cp_el_st_get($id);
	$rows = (!empty($st['r']) && is_array($st['r'])) ? array_values($st['r']) : array();
	if (cp_el_row_idx(array('r' => $rows), 'r0') < 0) { array_unshift($rows, array('i' => 'r0')); }
	$list = array();
	foreach ($rows as $row) {
		$ph = array();
		foreach ((isset($row['ph']) && is_array($row['ph'])) ? $row['ph'] : array() as $n) { $ph[] = array('n' => $n, 'u' => cp_el_img_url($id, $n, $tok)); }
		$pha = array();
		foreach ((isset($row['pha']) && is_array($row['pha'])) ? $row['pha'] : array() as $n) { $pha[] = array('n' => $n, 'u' => cp_el_img_url($id, $n, $tok)); }
		$phs = array();
		foreach ((isset($row['phs']) && is_array($row['phs'])) ? $row['phs'] : array() as $n) { $phs[] = array('n' => $n, 'u' => cp_el_img_url($id, $n, $tok)); }
		$list[] = array(
			'i' => $row['i'],
			'name' => cp_el_row_kname($ev, $row),
			'pk' => isset($row['pk']) ? $row['pk'] : '',
			'jt' => isset($row['jt']) ? $row['jt'] : '',
			'pa' => isset($row['pa']) ? $row['pa'] : '',
			'pt' => cp_el_s($row, 'pt'),
			'sa' => cp_el_s($row, 'sa'),
			'no' => cp_el_s($row, 'no'),
			'rhx' => cp_el_s($row, 'rhx'),
			'sv' => cp_el_s($row, 'sv'),
			'gm' => cp_el_s($row, 'gm'),
			'gmn' => cp_el_s($row, 'gmn'),
			'ok' => !empty($row['ok']) || (bool) cp_el_lock_of(substr((string) $ev['date'], 0, 7)),
			'fu' => !empty($row['fu']) ? wp_date('Y.m.d. H:i', (int) $row['fu']) : '',
			'ph' => $ph,
			'pha' => $pha,
			'phs' => $phs,
		);
	}
	$gmo = cp_panel_lines($o['gm_opts']);
	/* v1.8.1: a hosszabbítás alapból „Nem volt” – kivéve, ha az iroda már rögzített hosszabbítást a rendezvényhez */
	$dhx = isset($ev['hx']) ? (string) $ev['hx'] : '';
	if ($dhx === '') { $dhx = cp_el_s($st, 'hx'); }
	if ($dhx === '' || !in_array($dhx, cp_el_hx_values(), true)) { $dhx = '0'; }
	$data = array(
		'ajax' => admin_url('admin-ajax.php'),
		'k' => $tok,
		'd' => $ev['date'],
		'rows' => $list,
		'gmOk' => $gmo ? $gmo[0] : '',
		'dhx' => $dhx,
	);
	$meta = array_filter(array(cp_el_hu_date($ev['date']) . ($ev['time'] !== '' ? ' · ' . $ev['time'] : ''), $ev['loc'], $ev['svc']));
	echo '<body class="cpk">' . $top . '<main class="cpk-main">';
	echo '<section class="cpk-ev"><small>Rendezvény</small><h1>' . esc_html($ev['who']) . '</h1>';
	foreach ($meta as $line) { echo '<p>' . esc_html($line) . '</p>'; }
	echo '</section>';
	if (function_exists('cp_portal_info_block')) { echo cp_portal_info_block((string) $id); }
	echo '<form class="cpk-card" id="cpk-f" novalidate>';
	if (count($list) > 1) {
		echo '<div class="cpk-who"><span class="cpk-l">Ki vagy?</span><div class="cpk-seg" id="cpk-who">';
		foreach ($list as $n => $r) {
			echo '<button type="button" data-i="' . esc_attr($r['i']) . '" class="' . ($n === 0 ? 'on' : '') . '">' . esc_html($r['name'] !== '' ? $r['name'] : ($n + 1) . '. kezelő') . '</button>';
		}
		echo '</div></div>';
	} elseif ($list[0]['name'] !== '') {
		echo '<p class="cpk-kez">Kezelő: <b>' . esc_html($list[0]['name']) . '</b></p>';
	}
	echo '<p class="cpk-lock" id="cpk-lock" hidden>Ezt a tételt az iroda már ellenőrizte, ezért nem módosítható. Ha hibát találsz, szólj az irodának.</p>';
	/* v1.7.1: szolgáltatás legördülő listában, több is választható (jelölőnégyzetek) */
	echo '<div class="cpk-sv"><span class="cpk-l" id="cpk-sv-l">Szolgáltatás (melyik gép / eszköz) – több is választható</span>';
	echo '<style>.cpk-dd{position:relative}.cpk-dd>summary{list-style:none;display:flex;align-items:center;gap:10px;min-height:50px;border:1.5px solid var(--li);border-radius:12px;padding:10px 14px;background:#fff;font-size:16px;color:var(--ink);cursor:pointer;user-select:none}.cpk-dd>summary::-webkit-details-marker{display:none}.cpk-dd>summary::after{content:"";flex:none;width:9px;height:9px;margin-left:auto;border-right:2px solid var(--mu);border-bottom:2px solid var(--mu);transform:translateY(-3px) rotate(45deg);transition:transform .15s}.cpk-dd[open]>summary::after{transform:translateY(2px) rotate(-135deg)}.cpk-dd[open]>summary,.cpk-dd>summary:focus-visible{border-color:var(--o);box-shadow:0 0 0 2px rgba(232,137,74,.2);outline:0}.cpk-dd-t{flex:1;min-width:0;font-weight:700;line-height:1.35}.cpk-dd-t.ph{color:var(--mu)}.cpk-dd-m{position:absolute;left:0;right:0;top:calc(100% + 6px);z-index:40;background:#fff;border:1.5px solid var(--li);border-radius:12px;box-shadow:0 14px 34px rgba(20,28,51,.18);max-height:min(60vh,430px);overflow:auto;padding:6px}.cpk-card .cpk-dd-o{display:flex;align-items:center;gap:12px;padding:11px 10px;border-radius:9px;font-size:16px;font-weight:600;color:var(--ink);cursor:pointer}.cpk-card .cpk-dd-o:hover{background:var(--bg)}.cpk-card .cpk-dd-o input[type=checkbox]{display:block!important;flex:none;width:20px!important;height:20px!important;margin:0!important;padding:0!important;border:0!important;accent-color:var(--o)}.cpk-dd-o input:disabled+span{opacity:.6}.cpk-dd-ok{position:sticky;bottom:0;display:block;width:100%;height:44px;margin-top:6px;border:0;border-radius:10px;background:var(--g);font:700 15px "Plus Jakarta Sans",sans-serif;color:var(--ink);cursor:pointer}</style>';
	echo '<details class="cpk-dd" id="cpk-dd"><summary><span class="cpk-dd-t ph" id="cpk-dd-t">– válassz –</span></summary><div class="cpk-dd-m" role="group" aria-labelledby="cpk-sv-l">';
	foreach (cp_panel_lines($o['devices']) as $dv) {
		echo '<label class="cpk-dd-o"><input type="checkbox" name="sv" value="' . esc_attr($dv) . '"><span>' . esc_html($dv) . '</span></label>';
	}
	echo '<button type="button" class="cpk-dd-ok" id="cpk-dd-ok">Kész</button></div></details></div>';
	echo '<script>(function(){var dd=document.getElementById(\'cpk-dd\');if(!dd){return;}var t=document.getElementById(\'cpk-dd-t\');function sync(){var v=[].slice.call(dd.querySelectorAll(\'input[name="sv"]:checked\')).map(function(x){return x.value;});t.textContent=v.length?v.join(\', \'):\'– válassz –\';t.classList.toggle(\'ph\',!v.length);}window.cpkSvSync=sync;dd.addEventListener(\'change\',sync);document.getElementById(\'cpk-dd-ok\').addEventListener(\'click\',function(){dd.open=false;});document.addEventListener(\'click\',function(e){if(dd.open&&!dd.contains(e.target)){dd.open=false;}});document.addEventListener(\'keydown\',function(e){if(e.key===\'Escape\'&&dd.open){dd.open=false;}});sync();})();</script>';
	echo '<label class="cpk-full">Hosszabbítás (óra)<select name="rhx"><option value="">– válassz –</option>';
	foreach (cp_el_hx_values() as $v) {
		if ($v === '') { continue; }
		echo '<option value="' . esc_attr($v) . '">' . esc_html($v === '0' ? 'Nem volt hosszabbítás' : '+' . str_replace('.', ',', $v) . ' óra') . '</option>';
	}
	echo '</select></label>';
	echo '<div class="cpk-grid">';
	echo '<label>Parkolás (Ft)<input name="pk" inputmode="numeric" autocomplete="off" placeholder="0"></label>';
	echo '<label>Jatt (Ft)<input name="jt" inputmode="numeric" autocomplete="off" placeholder="0"></label>';
	echo '<label>Papírfogyasztás (db)<input name="pa" inputmode="numeric" autocomplete="off" placeholder="pl. 150"></label>';
	echo '<label>Nyomtató típusa<select name="pt"><option value="">– válassz –</option>';
	foreach (cp_el_printers() as $pr) { echo '<option value="' . esc_attr($pr) . '">' . esc_html($pr) . '</option>'; }
	echo '</select></label>';
	echo '</div>';
	echo '<div class="cpk-sat"><span class="cpk-l">Ügyfél elégedettsége</span><div class="cpk-seg">';
	foreach (cp_panel_lines($o['sat']) as $s) {
		echo '<label><input type="radio" name="sa" value="' . esc_attr($s) . '"><span>' . esc_html($s) . '</span></label>';
	}
	echo '</div></div>';
	echo '<div class="cpk-gm"><span class="cpk-l">Jól működött a gép?</span><div class="cpk-seg">';
	foreach ($gmo as $n => $s) {
		echo '<label><input type="radio" name="gm" value="' . esc_attr($s) . '"><span>' . esc_html($n === 0 ? 'Igen, jól (' . $s . ')' : $s) . '</span></label>';
	}
	echo '</div><label class="cpk-full cpk-gmn" id="cpk-gmn" hidden>Mi volt a gond?<textarea name="gmn" rows="3" maxlength="600" placeholder="pl. elakadt a nyomtató, lefagyott a program, rossz volt a fény…"></textarea></label></div>';
	echo '<label class="cpk-full">Megjegyzés<textarea name="no" rows="4" maxlength="600" placeholder="Bármi, amit az irodának tudnia kell (kérés, extra munka…)"></textarea></label>';
	echo '<div class="cpk-ph"><span class="cpk-l">Setup / aktivitás fotó</span><div class="cpk-thumbs" id="cpk-thumbs-a"></div>';
	echo '<label class="cpk-up" id="cpk-upl-a">+ Fotó a setupról<input type="file" id="cpk-file-a" data-cat="a" accept="image/*" multiple hidden></label><small id="cpk-upmsg-a" class="cpk-hint"></small></div>';
	echo '<div class="cpk-ph"><span class="cpk-l">Nyomtatási statisztika</span><div class="cpk-thumbs" id="cpk-thumbs-s"></div>';
	echo '<label class="cpk-up" id="cpk-upl-s">+ Fotó a nyomtatási statisztikáról<input type="file" id="cpk-file-s" data-cat="s" accept="image/*" multiple hidden></label><small id="cpk-upmsg-s" class="cpk-hint"></small></div>';
	echo '<div class="cpk-ph"><span class="cpk-l">Parkolás fotója / blokk</span><div class="cpk-thumbs" id="cpk-thumbs"></div>';
	echo '<label class="cpk-up" id="cpk-upl">+ Fotó hozzáadása<input type="file" id="cpk-file" data-cat="" accept="image/*" multiple hidden></label><small id="cpk-upmsg" class="cpk-hint"></small></div>';
	echo '<button type="submit" class="cpk-save" id="cpk-save">Mentés</button><p class="cpk-msg" id="cpk-msg" role="status"></p>';
	echo '</form>';
	echo '<p class="cpk-foot">A linket csak a rendezvény kezelői használják. Mentés után is módosíthatod, amíg az iroda nem ellenőrizte.</p>';
	echo '</main>';
	echo '<script>window.CPK=' . wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
	echo '<script>' . cp_el_form_js() . '</script>';
	echo '</body></html>';
}

function cp_el_form_js() {
	return <<<'CPKJS'
(function () {
  'use strict';
  var D = window.CPK || {}, f = document.getElementById('cpk-f');
  var cur = D.rows && D.rows[0] ? D.rows[0].i : 'r0';
  var msg = document.getElementById('cpk-msg');
  var CATS = { '': { box: 'cpk-thumbs', up: 'cpk-upl', msg: 'cpk-upmsg', key: 'ph' }, 'a': { box: 'cpk-thumbs-a', up: 'cpk-upl-a', msg: 'cpk-upmsg-a', key: 'pha' }, 's': { box: 'cpk-thumbs-s', up: 'cpk-upl-s', msg: 'cpk-upmsg-s', key: 'phs' } };
  function row() { for (var n = 0; n < D.rows.length; n++) { if (D.rows[n].i === cur) return D.rows[n]; } return { i: cur, ph: [], pha: [], phs: [] }; }
  function setRow(r) { for (var n = 0; n < D.rows.length; n++) { if (D.rows[n].i === r.i) { r.name = D.rows[n].name; D.rows[n] = r; } } }
  function say(el, t, bad) { el.textContent = t; el.className = el.className.replace(/ (ok|bad)$/, '') + (t ? (bad ? ' bad' : ' ok') : ''); }
  function all(sel) { return Array.prototype.slice.call(f.querySelectorAll(sel)); }
  /* v1.6.6: a setup fotók a szerveren gyűlnek; az utolsó feltöltés után 45 mp-cel (vagy az oldal elhagyásakor) EGY Pumble üzenetben mennek ki */
  var pqT = null, pqRow = null;
  function pqFlush() {
    if (pqT) { clearTimeout(pqT); pqT = null; }
    if (pqRow === null) return;
    var fd = new FormData();
    fd.append('action', 'cp_el_pflush'); fd.append('k', D.k); fd.append('i', pqRow);
    pqRow = null;
    try { if (navigator.sendBeacon && navigator.sendBeacon(D.ajax, fd)) return; } catch (e) { /* fetch-csel próbáljuk */ }
    fetch(D.ajax, { method: 'POST', body: fd, credentials: 'omit', keepalive: true }).catch(function () {});
  }
  function pqTick() { if (document.hidden) { pqT = setTimeout(pqTick, 15000); return; } pqFlush(); }
  function pqLater() { if (pqRow === null) return; if (pqT) clearTimeout(pqT); pqT = setTimeout(pqTick, 45000); }
  window.addEventListener('pagehide', pqFlush);
  function gmToggle() {
    var g = f.querySelector('input[name=gm]:checked');
    document.getElementById('cpk-gmn').hidden = !(g && g.value !== D.gmOk);
  }
  function fill() {
    var r = row();
    ['pk', 'jt', 'pa', 'no', 'gmn'].forEach(function (k) { f.elements[k].value = r[k] !== undefined && r[k] !== null ? r[k] : ''; });
    f.elements.pt.value = r.pt || '';
    f.elements.rhx.value = r.rhx || (r.ok ? '' : (D.dhx || '0'));
    all('input[name=sa]').forEach(function (x) { x.checked = x.value === (r.sa || ''); });
    all('input[name=gm]').forEach(function (x) { x.checked = x.value === (r.gm || ''); });
    var sv = String(r.sv || '').split(',').map(function (x) { return x.trim().toLowerCase(); });
    all('input[name=sv]').forEach(function (x) { x.checked = sv.indexOf(x.value.toLowerCase()) >= 0; });
    if (window.cpkSvSync) { window.cpkSvSync(); }
    gmToggle();
    var locked = !!r.ok;
    all('input,textarea,select,#cpk-save').forEach(function (x) { x.disabled = locked; });
    document.getElementById('cpk-upl').hidden = locked;
    document.getElementById('cpk-upl-a').hidden = locked;
    document.getElementById('cpk-upl-s').hidden = locked;
    document.getElementById('cpk-lock').hidden = !locked;
    say(msg, r.fu ? 'Utoljára mentve: ' + r.fu : '', false);
    thumbs('');
    thumbs('a');
    thumbs('s');
  }
  function thumbs(cat) {
    var c = CATS[cat], r = row(), box = document.getElementById(c.box);
    box.innerHTML = '';
    (r[c.key] || []).forEach(function (p) {
      var a = document.createElement('a');
      a.href = p.u; a.target = '_blank'; a.rel = 'noopener'; a.className = 'cpk-th';
      var img = document.createElement('img'); img.src = p.u; img.alt = 'Feltöltött fotó'; img.loading = 'lazy';
      a.appendChild(img);
      if (!r.ok) {
        var b = document.createElement('button');
        b.type = 'button'; b.textContent = '×'; b.title = 'Fotó törlése'; b.setAttribute('aria-label', 'Fotó törlése');
        b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); if (confirm('Törlöd ezt a fotót?')) delPhoto(p.n, cat); });
        a.appendChild(b);
      }
      box.appendChild(a);
    });
  }
  function post(data, file) {
    var fd = new FormData();
    fd.append('k', D.k); fd.append('i', cur); fd.append('d', D.d);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    if (file) fd.append('f', file, 'foto.jpg');
    return fetch(D.ajax, { method: 'POST', body: fd, credentials: 'omit' }).then(function (r) {
      return r.json().catch(function () { throw new Error('Szerverhiba (' + r.status + ')'); });
    }).then(function (j) {
      if (!j.success) throw new Error((j.data && j.data.msg) || 'Hiba történt.');
      return j.data;
    });
  }
  function urls(list) {
    return (list || []).map(function (n) { return { n: n, u: D.ajax + '?action=cp_el_img&k=' + encodeURIComponent(D.k) + '&n=' + encodeURIComponent(n) }; });
  }
  function fromServer(sr) {
    var old = row();
    return { i: sr.i, name: old.name, pk: sr.pk !== undefined ? sr.pk : '', jt: sr.jt !== undefined ? sr.jt : '', pa: sr.pa !== undefined ? sr.pa : '', pt: sr.pt || '', sa: sr.sa || '', no: sr.no || '', rhx: sr.rhx !== undefined ? String(sr.rhx) : '', sv: sr.sv || '', gm: sr.gm || '', gmn: sr.gmn || '', ok: !!sr.ok, fu: sr.fu ? new Date(sr.fu * 1000).toLocaleString('hu-HU', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : '', ph: urls(sr.ph), pha: urls(sr.pha), phs: urls(sr.phs) };
  }
  function delPhoto(n, cat) {
    var m = document.getElementById(CATS[cat].msg);
    say(m, 'Törlés…', false);
    post({ action: 'cp_el_phdel', n: n, cat: cat }).then(function (d) { if (d.row) setRow(fromServer(d.row)); say(m, 'Fotó törölve.', false); thumbs(cat); })
      .catch(function (e) { say(m, e.message, true); });
  }
  function shrink(file) {
    return new Promise(function (res) {
      if (!/^image\//.test(file.type) || !window.URL || !document.createElement('canvas').toBlob) { res(file); return; }
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        try {
          var s = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
          var c = document.createElement('canvas');
          c.width = Math.max(1, Math.round(img.naturalWidth * s)); c.height = Math.max(1, Math.round(img.naturalHeight * s));
          c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
          URL.revokeObjectURL(url);
          c.toBlob(function (b) { res(b && b.size ? b : file); }, 'image/jpeg', 0.82);
        } catch (e) { URL.revokeObjectURL(url); res(file); }
      };
      img.onerror = function () { URL.revokeObjectURL(url); res(file); };
      img.src = url;
    });
  }
  ['cpk-file', 'cpk-file-a', 'cpk-file-s'].forEach(function (fid) {
    document.getElementById(fid).addEventListener('change', function (e) {
      var cat = e.target.getAttribute('data-cat') || '', m = document.getElementById(CATS[cat].msg);
      var files = Array.prototype.slice.call(e.target.files || []);
      e.target.value = '';
      if (!files.length) return;
      var done = 0, chain = Promise.resolve();
      if (cat === 'a') { if (pqT) { clearTimeout(pqT); pqT = null; } if (pqRow !== null && pqRow !== cur) pqFlush(); pqRow = cur; }
      files.forEach(function (file) {
        chain = chain.then(function () {
          say(m, 'Feltöltés… (' + (done + 1) + '/' + files.length + ')', false);
          return shrink(file).then(function (b) { return post({ action: 'cp_el_up', cat: cat }, b); }).then(function (d) {
            done++;
            if (d.row) setRow(fromServer(d.row));
            thumbs(cat);
          });
        });
      });
      chain.then(function () { say(m, done === 1 ? 'Fotó feltöltve.' : done + ' fotó feltöltve.', false); })
        .catch(function (err) { say(m, 'Nem sikerült: ' + err.message, true); thumbs(cat); })
        .then(function () { if (cat === 'a') pqLater(); });
    });
  });
  all('input[name=gm]').forEach(function (x) { x.addEventListener('change', gmToggle); });
  var who = document.getElementById('cpk-who');
  if (who) who.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-i]');
    if (!b) return;
    cur = b.getAttribute('data-i');
    Array.prototype.forEach.call(who.querySelectorAll('button'), function (x) { x.classList.toggle('on', x === b); });
    fill();
  });
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    var sa = f.querySelector('input[name=sa]:checked'), gm = f.querySelector('input[name=gm]:checked');
    if (gm && gm.value !== D.gmOk && !f.elements.gmn.value.trim()) { say(msg, 'Írd le röviden, mi volt a gond a géppel.', true); f.elements.gmn.focus(); return; }
    var sv = all('input[name=sv]').filter(function (x) { return x.checked; }).map(function (x) { return x.value; }).join(', ');
    var data = { action: 'cp_el_form', pk: f.elements.pk.value, jt: f.elements.jt.value, pa: f.elements.pa.value, pt: f.elements.pt.value, sa: sa ? sa.value : '', no: f.elements.no.value, rhx: f.elements.rhx.value, sv: sv, gm: gm ? gm.value : '', gmn: gm && gm.value !== D.gmOk ? f.elements.gmn.value : '' };
    var btn = document.getElementById('cpk-save');
    btn.disabled = true; say(msg, 'Mentés…', false);
    post(data).then(function (d) {
      if (d.row) setRow(fromServer(d.row));
      fill();
      say(msg, 'Köszönjük, elmentve! ' + (row().fu ? '(' + row().fu + ')' : ''), false);
    }).catch(function (err) { say(msg, 'Nem sikerült menteni: ' + err.message, true); })
      .then(function () { btn.disabled = !!row().ok; });
  });
  fill();
})();
CPKJS;
}

/* ------------------------------------------------------------ admin: beállítások + Apps Script */

add_action('admin_menu', function () {
	add_options_page('Kezelői elszámolás', 'Kezelői elszámolás', 'manage_options', 'cp-el', 'cp_el_admin_page');
});

function cp_el_apps_script($o = null) {
	if (!is_array($o)) { $o = cp_el_opts(); }
	$feed = admin_url('admin-ajax.php') . '?action=cp_el_feed&key=' . rawurlencode($o['feed_key']);
	$js = <<<'GAS'
/**
 * Crazy panel – naptár ↔ panel szinkron (v1.6).
 * Óránként végignézi a(z) „__CAL__” naptár eseményeit:
 *  1) a dealhez tartozó bejegyzés leírásának elejére beírja (vagy frissíti) a kezelői adatlap linkjét,
 *     félkövér, elválasztott blokkban (a mai és a jövőbeli rendezvényeknél a régi formátumot is átírja),
 *  2) elküldi a panelnek a bejegyzés idejét, helyszínét, meghívottjait és a rendelkezésre állási időt
 *     (a lezajlott rendezvények elszámolása ezek alapján készül, a jövőbeliek ütközés-ellenőrzése is).
 * Első futtatás: idozitoBeallitasa (engedélyezés után óránként fut magától).
 */
var FEED = '__FEED__';
var CALENDAR = '__CAL__';
var LABEL = 'Kezelői adatlap: ';
var DAYS_BACK = 45, DAYS_AHEAD = 90;

function linkekFrissitese() {
  var tz = 'Europe/Budapest';
  var from = new Date(); from.setDate(from.getDate() - DAYS_BACK); from.setHours(0, 0, 0, 0);
  var to = new Date(); to.setDate(to.getDate() + DAYS_AHEAD); to.setHours(23, 59, 59, 0);
  var fromS = Utilities.formatDate(from, tz, 'yyyy-MM-dd'), toS = Utilities.formatDate(to, tz, 'yyyy-MM-dd');
  var res = JSON.parse(UrlFetchApp.fetch(FEED + '&from=' + fromS + '&to=' + toS, { muteHttpExceptions: true }).getContentText());
  if (!res.success) throw new Error('Panel hiba: ' + JSON.stringify(res.data));
  var cals = CalendarApp.getCalendarsByName(CALENDAR);
  if (!cals.length) throw new Error('Nincs ilyen naptár: ' + CALENDAR);
  var byDate = {};
  res.data.deals.forEach(function (d) { (byDate[d.date] = byDate[d.date] || []).push(d); });
  var changed = 0, out = [];
  var dc = res.data.desc || {};
  var TPL = dc.tpl || '<b>KEZELŐI ADATLAP</b><br><a href="{{LINK}}">{{LINK}}</a><br>━━━━━━━━━━━━━━━━━━━━<br><br>';
  var MARK = dc.mark || 'KEZELŐI ADATLAP';
  var TODAY = dc.today || Utilities.formatDate(new Date(), tz, 'yyyy-MM-dd');
  cals[0].getEvents(from, to).forEach(function (ev) {
    var day = Utilities.formatDate(ev.getStartTime(), tz, 'yyyy-MM-dd');
    var list = byDate[day];
    if (!list) return;
    var title = ' ' + norm(ev.getTitle()) + ' ', best = null, bestLen = 0;
    list.forEach(function (d) {
      var k = norm(d.who);
      if (k.length < 3) return;
      var toks = k.split(' ').filter(function (t) { return t.length > 1; });
      var ok = title.indexOf(' ' + k + ' ') >= 0 || (toks.length >= 2 && toks.every(function (t) { return title.indexOf(' ' + t + ' ') >= 0; }));
      if (ok && k.length > bestLen) { best = d; bestLen = k.length; }
    });
    if (!best) return;
    var desc = ev.getDescription() || '';
    try { out.push(adatok(ev, best, day, desc, tz)); } catch (e) { Logger.log('Adathiba: ' + ev.getTitle() + ' – ' + e); }
    var mi = desc.indexOf(MARK);
    if (mi >= 0 && mi < 80 && desc.indexOf(best.link) > mi) return;      // már az új formában, a leírás elején
    if (day < TODAY && desc.indexOf(best.link) >= 0) return;            // lezajlott rendezvény: marad, ahogy volt
    var rest = desc
      .replace(/(\s|<br\s*\/?>)*Kezelői adatlap:\s*(<a[^>]*>[^<]*<\/a>|\S+)/g, '')
      .replace(new RegExp('(<[^>]+>|\\s)*[^<>\\n]{0,4}' + MARK + '[\\s\\S]*?━{5,}(<[^>]+>|\\s|&nbsp;)*', 'g'), '')
      .replace(/^(\s|<br\s*\/?>)+/, '').replace(/(\s|<br\s*\/?>)+$/, '');
    ev.setDescription(TPL.split('{{LINK}}').join(best.link) + rest);
    changed++;
  });
  var push = UrlFetchApp.fetch(FEED.replace('action=cp_el_feed', 'action=cp_el_calpush'), {
    method: 'post', contentType: 'application/json', muteHttpExceptions: true,
    payload: JSON.stringify({ from: fromS, to: toS, ev: out })
  });
  Logger.log(changed + ' naptárbejegyzés frissítve; ' + out.length + ' rendezvény adatai a panelnek: ' + push.getContentText().slice(0, 200));
}

/* a panelnek küldött adatok – csak ami az elszámoláshoz kell (kapcsolattartó, telefon stb. nem) */
function adatok(ev, d, day, desc, tz) {
  var txt = String(desc).replace(/<br\s*\/?>/gi, '\n').replace(/<\/(p|div|li)>/gi, '\n').replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&');
  var m, o = { id: d.id, d: day, loc: ev.getLocation() || '', g: [] };
  if (!ev.isAllDayEvent()) {
    o.s = Utilities.formatDate(ev.getStartTime(), tz, 'HH:mm');
    var end = ev.getEndTime();
    o.e = Utilities.formatDate(end, tz, 'yyyy-MM-dd') > day ? '24:00' : Utilities.formatDate(end, tz, 'HH:mm');
  }
  if ((m = /Rendelkez[ée]sre\s*[áa]ll[áa]si\s*id[őöo]\s*:\s*([\d.,]+)/i.exec(txt))) o.h = m[1].replace(',', '.');
  if ((m = /Aktivit[áa]s\s*:\s*(\d{1,2}[:.]\d{2})\s*[-–]\s*(\d{1,2}[:.]\d{2})/i.exec(txt))) { o.as = m[1]; o.ae = m[2]; }
  if ((m = /Helysz[íi]n\s*:\s*([^\n]+)/i.exec(txt))) o.hl = m[1].trim();
  ev.getGuestList().forEach(function (g) {
    o.g.push({ n: g.getName() || '', e: g.getEmail() || '', st: String(g.getGuestStatus()) });
  });
  return o;
}

function norm(s) {
  // ékezetek, írásjelek és névsorrend nem számít (pl. Leóna = Leona, Tünde Neuwald = Neuwald Tünde)
  return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
}

function idozitoBeallitasa() {
  ScriptApp.getProjectTriggers().forEach(function (t) {
    if (t.getHandlerFunction() === 'linkekFrissitese') ScriptApp.deleteTrigger(t);
  });
  ScriptApp.newTrigger('linkekFrissitese').timeBased().everyHours(1).create();
  linkekFrissitese();
}
GAS;
	return str_replace(array('__FEED__', '__CAL__'), array($feed, str_replace("'", "\\'", $o['calendar'])), $js);
}

function cp_el_admin_page() {
	if (!current_user_can('manage_options')) { return; }
	$msg = '';
	if (isset($_POST['cp_el_save'])) {
		check_admin_referer('cp_el_save');
		$o = get_option(CP_EL_OPT, array());
		$o = array_merge(cp_el_opts(), is_array($o) ? $o : array());
		$in = wp_unslash($_POST);
		foreach (array('f_gk', 'f_hours', 'f_svc', 'f_svc2', 'f_start') as $k) {
			if (isset($in[$k])) { $o[$k] = preg_replace('/[^A-Za-z0-9_]/', '', (string) $in[$k]); }
		}
		foreach (array('skip_stages', 'payers', 'sat', 'devices', 'gm_opts') as $k) {
			if (isset($in[$k])) { $o[$k] = sanitize_textarea_field($in[$k]); }
		}
		if (isset($in['calendar'])) { $o['calendar'] = sanitize_text_field($in['calendar']); }
		$o['gk_write'] = !empty($in['gk_write']) ? 1 : 0;
		$o['h_write'] = !empty($in['h_write']) ? 1 : 0;
		$o['pumble_setup'] = !empty($in['pumble_setup']) ? 1 : 0;
		$pu = isset($in['pumble_url']) ? trim((string) $in['pumble_url']) : '';
		$perr = '';
		if (!empty($in['pumble_clear'])) {
			$o['pumble_url'] = '';
		} elseif ($pu !== '') {
			if (preg_match('#^https://api\.pumble\.com/[A-Za-z0-9/_.\-]+$#', $pu)) { $o['pumble_url'] = $pu; } else { $perr = ' A Pumble webhook címe nem https://api.pumble.com/… kezdetű, ezért nem mentettem.'; }
		}
		if (isset($in['origin']) && preg_match('/^\s*-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?\s*$/', (string) $in['origin'])) { $o['origin'] = preg_replace('/\s+/', '', (string) $in['origin']); }
		if (!empty($in['feed_new'])) { $o['feed_key'] = wp_generate_password(24, false, false); }
		update_option(CP_EL_OPT, $o, false);
		cp_panel_flush_cache();
		$msg = 'Elmentve. Ha új kulcsot generáltál, a Google Apps Scriptben is cseréld le a kódot.' . $perr;
		if (!empty($in['pumble_test'])) {
			/* v1.6.7: megadott rendezvény (deal ID) setup fotóival is lehet tesztelni */
			$tid = isset($in['pumble_test_id']) ? preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $in['pumble_test_id']))) : '';
			$last = $tid !== '' ? cp_el_test_setup_row($tid) : cp_el_last_setup_photo();
			if (trim((string) $o['pumble_url']) === '') { $t = new WP_Error('x', 'nincs megadva webhook.'); }
			elseif ($tid !== '' && !$last) { $t = new WP_Error('x', 'a(z) ' . $tid . ' rendezvénynél nincs setup fotó.'); }
			elseif ($last) { $t = cp_el_pumble_setup($last[0], $last[1], !empty($last[1]['pha']) ? $last[1]['pha'] : $last[2], $o['pumble_url']); }
			else { $t = cp_el_pumble('✅ Crazy panel – teszt üzenet. Ide érkeznek majd a kezelők setup fotói.', $o['pumble_url']); }
			$msg .= is_wp_error($t) ? ' Pumble teszt sikertelen: ' . $t->get_error_message() : ' Pumble teszt üzenet elküldve' . ($last ? ' (' . ($tid !== '' ? 'a(z) ' . $tid . ' rendezvény' : 'a legutóbbi tétel') . ' ' . count((array) $last[1]['pha']) . ' setup fotójával, egy üzenetben)' : '') . ' – nézd meg a csatornát.';
		}
	}
	$o = get_option(CP_EL_OPT, array());
	$o = array_merge(cp_el_defaults(), is_array($o) ? $o : array());
	if ($o['feed_key'] === '') { $o = cp_el_opts(); }
	echo '<div class="wrap"><h1>Kezelői elszámolás</h1>';
	if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
	echo '<p>A Crazy panel „Elszámolás” fülének beállításai. A kezelők listáját és díjait a panelen, a <b>Kezelők</b> gombbal lehet szerkeszteni.</p>';
	echo '<form method="post">';
	wp_nonce_field('cp_el_save');
	echo '<h2>Salesmate mezők</h2><table class="form-table">';
	cp_panel_row('Gépkezelő (kezelő) mező', '<input name="f_gk" value="' . esc_attr($o['f_gk']) . '" class="regular-text"> <label><input type="checkbox" name="gk_write" value="1"' . checked(!empty($o['gk_write']), true, false) . '> a panelen választott fő kezelő visszaíródik a Salesmate-be</label><p class="description">Salesmate: „Gépkezelő asszisztens” (legördülő). Csak a listában szereplő nevet fogadja el – új kezelőt ott is fel kell venni.</p>');
	cp_panel_row('Óraszám mező', '<input name="f_hours" value="' . esc_attr($o['f_hours']) . '" class="regular-text"> <label><input type="checkbox" name="h_write" value="1"' . checked(!empty($o['h_write']), true, false) . '> egész órás módosítás visszaírása</label><p class="description">Salesmate: „Rendelkezésreállás hossza (óra)” – egész szám, a fél órák csak a panelben élnek.</p>');
	cp_panel_row('Szolgáltatás mezők', '<input name="f_svc" value="' . esc_attr($o['f_svc']) . '" class="regular-text"> <input name="f_svc2" value="' . esc_attr($o['f_svc2']) . '" class="regular-text">');
	cp_panel_row('Kezdés időpontja', '<input name="f_start" value="' . esc_attr($o['f_start']) . '" class="regular-text">');
	cp_panel_row('Kihagyott szakaszok', '<textarea name="skip_stages" rows="3" class="large-text">' . esc_textarea($o['skip_stages']) . '</textarea><p class="description">Ezekben a szakaszokban lévő (nem megnyert) dealek nem kerülnek az elszámolásba. Az elvesztett dealek soha.</p>');
	echo '</table><h2>Elszámolás</h2><table class="form-table">';
	cp_panel_row('Kifizetők', '<textarea name="payers" rows="3" class="large-text">' . esc_textarea($o['payers']) . '</textarea>');
	cp_panel_row('Elégedettségi lehetőségek', '<textarea name="sat" rows="3" class="large-text">' . esc_textarea($o['sat']) . '</textarea>');
	cp_panel_row('Kiindulási pont (raktár)', '<input name="origin" value="' . esc_attr($o['origin']) . '" class="regular-text"><p class="description">Szélesség,hosszúság – innen számolja a km-t (ugyanaz, mint a gyors ellenőrzés kalkulátoráé).</p>');
	echo '</table><h2>Kezelői adatlap</h2><table class="form-table">';
	cp_panel_row('Szolgáltatások (eszközök)', '<textarea name="devices" rows="6" class="large-text">' . esc_textarea($o['devices']) . '</textarea><p class="description">Soronként egy – ezek közül választ a kezelő az adatlapon (a régi táblázat „Szolgáltatás” listája).</p>');
	cp_panel_row('Gép működése', '<textarea name="gm_opts" rows="3" class="large-text">' . esc_textarea($o['gm_opts']) . '</textarea><p class="description">Az első sor a „rendben” állapot; a többinél a kezelőnek meg kell írnia, mi volt a gond.</p>');
	$pset = trim((string) $o['pumble_url']) !== '';
	echo '</table><h2>Pumble</h2><table class="form-table">';
	cp_panel_row('Incoming webhook', '<input type="password" name="pumble_url" value="" class="large-text" autocomplete="new-password" placeholder="' . esc_attr($pset ? 'beállítva – csak cseréhez írd be az újat' : 'https://api.pumble.com/workspaces/…/incomingWebhooks/postMessage/…') . '">' . ($pset ? '<p><code>' . esc_html(substr($o['pumble_url'], 0, 31)) . '…' . esc_html(substr($o['pumble_url'], -4)) . '</code> <label><input type="checkbox" name="pumble_clear" value="1"> törlés</label></p>' : '') . '<p class="description">Pumble: <b>Workspace settings → General settings → Incoming Webhooks → New Webhook</b>, válaszd ki a csatornát, majd a kapott címet másold ide. A cím titkos: aki ismeri, írhat a csatornába.</p>');
	cp_panel_row('Setup fotók a csatornába', '<label><input type="checkbox" name="pumble_setup" value="1"' . checked(!empty($o['pumble_setup']), true, false) . '> a kezelő által feltöltött setup / aktivitás fotó üzenetben megjelenik a csatornában (rendezvény, kezelő, eszköz, helyszín, képek + kezelői adatlap link; kezelői adatlaponként csak egyszer, az első feltöltéskor – az együtt feltöltött fotók egy üzenetben, kb. 45 mp-cel az utolsó után; a később feltöltött fotókról és az adatlap többi módosításáról nem megy üzenet)</label><br><label><input type="checkbox" name="pumble_test" value="1"> mentéskor küldj egy teszt üzenetet</label> a(z) <input type="text" name="pumble_test_id" value="" style="width:8em" placeholder="deal ID" inputmode="numeric"> rendezvény összes setup fotójával, egy üzenetben <span class="description">(üresen: a legutóbb feltöltött setup fotó tétele)</span>');
	$plog = get_option('cp_el_plog', array());
	if (is_array($plog) && $plog) {
		$lh = '';
		foreach (array_slice($plog, 0, 15) as $e) {
			$lh .= '<tr><td style="white-space:nowrap;padding:2px 12px 2px 0">' . esc_html(wp_date('m.d. H:i:s', (int) $e['t'])) . '</td><td style="white-space:nowrap;padding:2px 12px 2px 0">' . esc_html($e['k']) . '</td><td style="padding:2px 0;word-break:break-word">' . esc_html($e['i']) . '</td></tr>';
		}
		cp_panel_row('Napló', '<table style="font-size:12px;border-collapse:collapse">' . $lh . '</table><p class="description">A küldések eredménye, és ki nyitotta meg a fotólinkeket (pl. a Pumble, amikor előnézetet készít).</p>');
	}
	echo '</table><h2>Google Naptár</h2><table class="form-table">';
	$cs = get_option('cp_el_cal_at', null);
	$csTxt = is_array($cs) && !empty($cs['t']) ? 'Utolsó szinkron: ' . wp_date('Y.m.d. H:i', (int) $cs['t']) . ' – ' . (int) $cs['n'] . ' rendezvény adatai (' . esc_html($cs['from'] . ' – ' . $cs['to']) . ')' : 'A naptár-szinkron még nem futott (a frissített Apps Script első futása után jelenik meg).';
	cp_panel_row('Naptár neve', '<input name="calendar" value="' . esc_attr($o['calendar']) . '" class="regular-text"><p class="description">' . $csTxt . '</p>');
	cp_panel_row('Feed kulcs', '<code>' . esc_html(substr($o['feed_key'], 0, 4)) . '…' . esc_html(substr($o['feed_key'], -4)) . '</code> <label><input type="checkbox" name="feed_new" value="1"> új kulcs generálása</label>');
	echo '</table>';
	submit_button('Mentés', 'primary', 'cp_el_save');
	echo '</form>';
	echo '<h2>Apps Script a naptárhoz</h2><ol><li>Nyisd meg a <a href="https://script.google.com/home/projects/create" target="_blank" rel="noopener">script.google.com</a> oldalt azzal a Google-fiókkal, amelyik a(z) „' . esc_html($o['calendar']) . '” naptárt szerkeszti.</li><li>Másold be az alábbi kódot (a meglévőt cseréld le), mentsd.</li><li>Fent válaszd az <code>idozitoBeallitasa</code> függvényt, és kattints a <b>Futtatás</b> gombra, majd engedélyezd a hozzáférést. Ezután óránként magától fut.</li></ol>';
	echo '<textarea readonly rows="22" class="large-text code" onclick="this.select()">' . esc_textarea(cp_el_apps_script($o)) . '</textarea>';
	echo '</div>';
}

/* ------------------------------------------------------------ AJAX: átvezetés a régi elszámoló táblázatból (v1.4.2) */

add_action('wp_ajax_nopriv_cp_el_import', 'cp_el_ajax_import');
add_action('wp_ajax_cp_el_import', 'cp_el_ajax_import');
function cp_el_ajax_import() {
	cp_el_auth();
	$data = json_decode(isset($_POST['data']) ? (string) wp_unslash($_POST['data']) : '', true);
	if (!is_array($data) || !$data || count($data) > 150) { cp_el_err('Hibás kérés.'); }
	$dry = !empty($_POST['dry']);
	$overwrite = !empty($_POST['overwrite']);
	$res = array();
	foreach ($data as $id => $in) {
		$id = (string) $id;
		if (!cp_el_id_ok($id) || !is_array($in) || empty($in['r']) || !is_array($in['r'])) { $res[$id] = 'hibás'; continue; }
		$old = cp_el_st_get($id);
		if (!empty($old['r']) && !$overwrite) { $res[$id] = 'kihagyva (már van adat)'; continue; }
		$st = array();
		$d = preg_replace('/[^0-9-]/', '', cp_el_s($in, 'd'));
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $st['d'] = $d; }
		if ($id[0] === 'm') {
			$st['title'] = mb_substr(sanitize_text_field(cp_el_s($in, 'title')), 0, 120);
			if ($st['title'] === '' || empty($st['d'])) { $res[$id] = 'hibás (kézi tétel)'; continue; }
			$st['loc'] = mb_substr(sanitize_text_field(cp_el_s($in, 'loc')), 0, 200);
			$st['svc'] = mb_substr(sanitize_text_field(cp_el_s($in, 'svc')), 0, 120);
			$dh = cp_el_dec(cp_el_s($in, 'dh'), 24);
			if ($dh !== null && $dh !== '') { $st['dh'] = $dh; }
		}
		$rows = array();
		$err = '';
		foreach ($in['r'] as $n => $r) {
			if (!is_array($r)) { continue; }
			$row = array('i' => $n === 0 ? 'r0' : 'r' . substr(preg_replace('/[^a-z0-9]/', '', strtolower(cp_el_s($r, 'i'))), 0, 8));
			if ($n > 0 && strlen($row['i']) < 3) { $row['i'] = 'r' . strtolower(wp_generate_password(6, false, false)); }
			foreach (array('k', 'h', 'p', 'kx', 'c', 'cn', 'pk', 'jt', 'pa', 'sa', 'no', 'ok', 'rhx', 'sv', 'gm', 'gmn') as $f) {
				if (!isset($r[$f]) || !is_scalar($r[$f]) || (string) $r[$f] === '') { continue; }
				list($ok, $x) = cp_el_clean_field($f, (string) $r[$f]);
				if (!$ok) { $err = $f . ': ' . $x; continue; }
				if ($x !== '') { $row[$f] = $x; }
			}
			if (!empty($r['kf'])) { $row['kf'] = 1; $row['ksm'] = mb_substr(sanitize_text_field(cp_el_s($r, 'ksm')), 0, 80); }
			if (isset($row['h']) && $n === 0 && $id[0] !== 'm') { $row['hsm'] = preg_replace('/[^0-9.]/', '', cp_el_s($r, 'hsm')); }
			if (!empty($row['ok'])) { $row['okt'] = time(); }
			$pd = preg_replace('/[^0-9-]/', '', cp_el_s($r, 'pd'));
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd)) {
				$row['pd'] = $pd;
				$row['pb'] = mb_substr(sanitize_text_field(cp_el_s($r, 'pb')), 0, 60);
				$row['pv'] = (int) cp_el_s($r, 'pv');
			}
			if (!empty($r['fu'])) { $row['fu'] = (int) $r['fu']; }
			$row['x'] = 1;
			$row['im'] = 1; // átvezetve a táblázatból
			$rows[] = $row;
		}
		if (!$rows) { $res[$id] = 'nincs sor'; continue; }
		$st['r'] = $rows;
		if ($dry) { $res[$id] = 'ok' . ($err !== '' ? ' (figyelmeztetés: ' . $err . ')' : ''); continue; }
		cp_el_st_put($id, $st);
		$res[$id] = 'mentve' . ($err !== '' ? ' (figyelmeztetés: ' . $err . ')' : '');
	}
	wp_send_json_success(array('res' => (object) $res));
}

/* ------------------------------------------------------------ Pumble: setup fotók a csatornába (v1.4.4) */

/* rövid napló a beállítások oldalra: küldések eredménye + ki nyitotta meg a fotólinkeket (pl. a Pumble előnézet-robotja) */
function cp_el_plog($kind, $info) {
	$l = get_option('cp_el_plog', array());
	if (!is_array($l)) { $l = array(); }
	array_unshift($l, array('t' => time(), 'k' => (string) $kind, 'i' => mb_substr((string) $info, 0, 300)));
	update_option('cp_el_plog', array_slice($l, 0, 40), false);
}

function cp_el_pumble($payload, $url = null) {
	if ($url === null) { $o = cp_el_opts(); $url = $o['pumble_url']; }
	$url = trim((string) $url);
	if ($url === '' || !preg_match('#^https://api\.pumble\.com/#', $url)) { return new WP_Error('nopumble', 'Nincs beállítva Pumble webhook.'); }
	if (!is_array($payload)) { $payload = array('text' => (string) $payload); }
	$res = wp_remote_post($url, array('timeout' => 10, 'headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode($payload)));
	$how = isset($payload['attachments']) ? 'kártyával' : 'csak szöveg';
	if (is_wp_error($res)) { cp_el_plog('küldés', $how . ' – hiba: ' . $res->get_error_message()); return $res; }
	$code = (int) wp_remote_retrieve_response_code($res);
	$body = mb_substr(trim(wp_strip_all_tags(wp_remote_retrieve_body($res))), 0, 200);
	cp_el_plog('küldés', $how . ' – ' . $code . ($code >= 300 && $body !== '' ? ': ' . $body : ''));
	if ($code >= 200 && $code < 300) { return true; }
	return new WP_Error('pumble', 'Pumble hiba (' . $code . '): ' . $body);
}

/* nyilvános linkek: képenként külön aláírás (nem azonos a kezelői link kulcsával) */
function cp_el_img_sig($id, $name) {
	$o = cp_el_opts();
	return substr(hash_hmac('sha256', 'img|' . $id . '|' . $name, $o['secret']), 0, 14);
}

/* maga a képfájl */
function cp_el_pub_img_url($id, $name) {
	$p = cp_panel_opts();
	return home_url('/' . trim($p['slug'], '/') . '/kep/' . $id . '.' . cp_el_img_sig($id, $name) . '/' . rawurlencode($name));
}

/* fotóoldal Open Graph adatokkal – ebből tud a chat előnézetet (kártyát képpel) készíteni */
function cp_el_pub_page_url($id, $name) {
	$p = cp_panel_opts();
	return home_url('/' . trim($p['slug'], '/') . '/foto/' . $id . '.' . cp_el_img_sig($id, $name) . '/' . rawurlencode(preg_replace('/\.(jpg|png|webp)$/', '', $name)));
}

function cp_el_pumble_info($id, $row) {
	$ev = cp_el_form_event($id);
	if (!$ev || is_wp_error($ev)) { $ev = array('who' => 'Rendezvény #' . $id, 'date' => '', 'time' => '', 'loc' => '', 'svc' => '', 'gk' => '', 'manual' => false); }
	$kname = is_array($row) ? cp_el_row_kname($ev, $row) : '';
	$meta = array_values(array_filter(array(
		$kname !== '' ? 'Kezelő: ' . $kname : '',
		(is_array($row) && !empty($row['sv'])) ? 'Eszköz: ' . $row['sv'] : '',
		$ev['loc'] !== '' ? 'Helyszín: ' . $ev['loc'] : '',
	)));
	$when = $ev['date'] !== '' ? cp_el_hu_date($ev['date']) . ($ev['time'] !== '' ? ' ' . $ev['time'] : '') : '';
	return array('who' => $ev['who'], 'when' => $when, 'meta' => $meta, 'title' => 'Setup fotó – ' . $ev['who'] . ($when !== '' ? ' · ' . $when : ''));
}

/* $url megadva = teszt a beállítások oldalról (a mentett, de még gyorsítótárazatlan címmel)
 * $names: egy fájlnév vagy több – v1.6.6: az együtt / egymás után feltöltött setup fotók EGY Pumble üzenetben */
function cp_el_pumble_setup($id, $row, $names, $url = null) {
	$o = cp_el_opts();
	if ($url === null && (empty($o['pumble_setup']) || trim((string) $o['pumble_url']) === '')) { return null; }
	$names = array_values(array_unique(array_filter(array_map('strval', (array) $names), 'strlen')));
	if (!$names) { return null; }
	$cnt = count($names);
	$inf = cp_el_pumble_info($id, $row);
	$title = $cnt > 1 ? preg_replace('/^Setup fotó/u', $cnt . ' setup fotó', $inf['title']) : $inf['title'];
	$meta = implode(' · ', $inf['meta']);
	/* v1.6.5: kattintható kezelői adatlap (ugyanaz a link, mint a panel Link ⧉ gombja) */
	$form = cp_el_link($id);
	/* v1.6.8: fejléc a szövegben, képenként egyforma kártya (kattintható cím + feltöltés ideje + kép) */
	$dir = cp_el_dir();
	$links = array();
	$att = array();
	foreach ($names as $n => $name) {
		$page = cp_el_pub_page_url($id, $name);
		$img = cp_el_pub_img_url($id, $name);
		$label = $cnt > 1 ? ($n + 1) . '. fotó' : 'Fotó';
		$links[] = '[' . ($cnt > 1 ? $label : 'Fotó megnyitása') . '](' . $page . ')';
		$card = array(
			'title'      => $label . ' megnyitása ↗',
			'title_link' => $page,
			'color'      => '#E8894A',
			'image_url'  => $img,
			'thumb_url'  => $img, /* a Pumble webhook csak a thumb_url-t jeleníti meg (image_url egyedül: nincs kép – teszt v1.6.9) */
		);
		$f = $dir . '/' . basename($name);
		$ft = is_file($f) ? (int) filemtime($f) : 0;
		if ($ft > 0) { $card['text'] = 'Feltöltve: ' . wp_date('H:i', $ft); }
		$att[] = $card;
	}
	$head = ($url !== null ? '🧪 teszt – ' : '') . '📸 **' . $title . '**' . ($meta !== '' ? "\n" . $meta : '');
	$adat = '📝 [Kezelői adatlap megnyitása](' . $form . ')';
	$r = cp_el_pumble(array('text' => $head . "\n" . $adat, 'attachments' => $att), $url);
	/* ha a Pumble nem fogadja el a kártyákat, a szöveg a fotók linkjeivel együtt menjen át */
	if (is_wp_error($r) && $r->get_error_code() === 'pumble') { $r = cp_el_pumble(array('text' => $head . "\n" . '📷 ' . implode(' · ', $links) . "\n" . $adat), $url); }
	return $r;
}

/* ---- v1.6.6: setup fotó várólista – feltöltéskor nem megy ki azonnal, hanem soronként gyűlik.
 * Kiküldés: a feltöltő oldal szól (cp_el_pflush) 45 mp-cel az utolsó feltöltés után vagy az oldal elhagyásakor;
 * tartalék: WP-Cron, illetve bármely későbbi feltöltés küldi ki, ami 90 mp óta nem bővült. */
define('CP_EL_PQ', 'cp_el_pq');
define('CP_EL_PQ_SENT', 'cp_el_pq_sent');

/* v1.7.2: kezelői adatlaponként (rendezvény + kezelő-sor) csak EGY Pumble üzenet megy ki: az első setup fotó(k) feltöltésekor.
 * Ami ezután jön (pl. 20 perccel később még egy fotó), arról nincs üzenet – és az adatlap többi módosításáról sem. */
function cp_el_pq_first($id, $ri) {
	$s = get_option(CP_EL_PQ_SENT, array());
	if (is_array($s) && isset($s[$id . '|' . $ri])) { return false; }
	$st = cp_el_st_get($id);
	$k = cp_el_row_idx($st, $ri);
	if ($k < 0) { return false; }
	$ph = (!empty($st['r'][$k]['pha']) && is_array($st['r'][$k]['pha'])) ? $st['r'][$k]['pha'] : array();
	return count($ph) <= 1; /* a most feltöltött már benne van; ha előtte is volt fotó (a frissítés előtti soroknál is), arról már ment üzenet */
}

/* v1.7.2: megjegyzi, hogy erről az adatlapról már kiment az üzenet (a ~13 hónapnál régebbi bejegyzések törlődnek) */
function cp_el_pq_mark($key) {
	$s = get_option(CP_EL_PQ_SENT, array());
	if (!is_array($s)) { $s = array(); }
	$s[(string) $key] = time();
	$old = time() - 400 * DAY_IN_SECONDS;
	foreach ($s as $kk => $t) { if ((int) $t < $old) { unset($s[$kk]); } }
	update_option(CP_EL_PQ_SENT, $s, false);
}

function cp_el_pq_on() {
	$o = cp_el_opts();
	return !empty($o['pumble_setup']) && trim((string) $o['pumble_url']) !== '';
}

function cp_el_pq_add($id, $ri, $name) {
	if (!cp_el_pq_on()) { return; }
	$q = get_option(CP_EL_PQ, array());
	if (!is_array($q)) { $q = array(); }
	$key = $id . '|' . $ri;
	if (empty($q[$key]) && !cp_el_pq_first($id, $ri)) { cp_el_pq_flush_due(90); return; } /* v1.7.2: csak az adatlap első setup fotó(i)ról megy üzenet */
	if (empty($q[$key]) || !is_array($q[$key])) { $q[$key] = array('n' => array(), 't' => 0); }
	$q[$key]['n'][] = (string) $name;
	$q[$key]['t'] = time();
	update_option(CP_EL_PQ, $q, false);
	if (!wp_next_scheduled('cp_el_pq_cron')) { wp_schedule_single_event(time() + 150, 'cp_el_pq_cron'); }
	cp_el_pq_flush_due(90, $key);
}

/* egy sor összegyűlt fotói egy üzenetben; a közben törölt fotók kimaradnak */
function cp_el_pq_flush($key) {
	$q = get_option(CP_EL_PQ, array());
	if (!is_array($q) || empty($q[$key])) { return null; }
	$e = $q[$key];
	unset($q[$key]);
	update_option(CP_EL_PQ, $q, false);
	$p = strpos((string) $key, '|');
	if ($p === false || !is_array($e) || empty($e['n'])) { return null; }
	$id = substr($key, 0, $p);
	$ri = substr($key, $p + 1);
	$st = cp_el_st_get($id);
	$k = cp_el_row_idx($st, $ri);
	if ($k < 0) { return null; }
	$row = $st['r'][$k];
	$have = (!empty($row['pha']) && is_array($row['pha'])) ? $row['pha'] : array();
	$names = array_values(array_intersect(array_unique((array) $e['n']), $have));
	if (!$names) { return null; }
	$r = cp_el_pumble_setup($id, $row, $names);
	if ($r !== null && !is_wp_error($r)) { cp_el_pq_mark($key); } /* v1.7.2: erről az adatlapról több setup fotó üzenet nem megy */
	return $r;
}

/* ami $age másodperce nem bővült, az kimegy ($skip: ezt a sort most ne) */
function cp_el_pq_flush_due($age, $skip = '') {
	$q = get_option(CP_EL_PQ, array());
	if (!is_array($q) || !$q) { return; }
	foreach ($q as $key => $e) {
		if ((string) $key === (string) $skip) { continue; }
		if (!is_array($e) || (int) (isset($e['t']) ? $e['t'] : 0) <= time() - $age) { cp_el_pq_flush($key); }
	}
}

add_action('cp_el_pq_cron', 'cp_el_pq_cron_run');
function cp_el_pq_cron_run() {
	cp_el_pq_flush_due(90);
	$q = get_option(CP_EL_PQ, array());
	if (is_array($q) && $q && !wp_next_scheduled('cp_el_pq_cron')) { wp_schedule_single_event(time() + 120, 'cp_el_pq_cron'); }
}

add_action('wp_ajax_nopriv_cp_el_pflush', 'cp_el_ajax_pflush');
add_action('wp_ajax_cp_el_pflush', 'cp_el_ajax_pflush');
function cp_el_ajax_pflush() {
	list($id, $via) = cp_el_req_id();
	$i = cp_el_req_rid();
	cp_el_pq_flush($id . '|' . $i);
	cp_el_pq_flush_due(90);
	wp_send_json_success(array('ok' => 1));
}

/* v1.6.7: teszt egy megadott rendezvény (Salesmate deal ID) setup fotóival – a legtöbb fotós kezelő-sorral */
function cp_el_test_setup_row($id) {
	$id = (string) $id;
	if (!cp_el_id_ok($id)) { return null; }
	$st = cp_el_st_get($id);
	$best = null;
	foreach ((isset($st['r']) && is_array($st['r'])) ? $st['r'] : array() as $r) {
		$n = (is_array($r) && !empty($r['pha']) && is_array($r['pha'])) ? count($r['pha']) : 0;
		if ($n > 0 && ($best === null || $n > count($best['pha']))) { $best = $r; }
	}
	return $best !== null ? array($id, $best, (string) reset($best['pha'])) : null;
}

/* a legutóbb feltöltött setup fotó (a beállítások oldali teszthez) */
function cp_el_last_setup_photo() {
	$best = null;
	$bt = 0;
	$dir = cp_el_dir();
	foreach (cp_el_st_all() as $pair) {
		list($id, $v) = $pair;
		foreach ((isset($v['r']) && is_array($v['r'])) ? $v['r'] : array() as $r) {
			foreach ((!empty($r['pha']) && is_array($r['pha'])) ? $r['pha'] : array() as $n) {
				$f = $dir . '/' . basename((string) $n);
				$t = is_file($f) ? (int) filemtime($f) : 0;
				if ($t > $bt) { $bt = $t; $best = array((string) $id, $r, basename((string) $n)); }
			}
		}
	}
	return $best;
}

function cp_el_photo_page($id, $name, $file) {
	$st = cp_el_st_get($id);
	$row = null;
	foreach ((isset($st['r']) && is_array($st['r'])) ? $st['r'] : array() as $r) {
		if (!empty($r['pha']) && is_array($r['pha']) && in_array($name, $r['pha'], true)) { $row = $r; break; }
	}
	$inf = cp_el_pumble_info($id, $row);
	$img = cp_el_pub_img_url($id, $name);
	$desc = implode(' · ', array_filter(array($inf['when'], implode(' · ', $inf['meta']))));
	$types = array('jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	$sz = @getimagesize($file);
	status_header(200);
	header('Content-Type: text/html; charset=utf-8');
	header('Cache-Control: public, max-age=3600');
	header('X-Robots-Tag: noindex, nofollow');
	echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<title>' . esc_html($inf['title']) . '</title><meta name="robots" content="noindex,nofollow">';
	echo '<meta property="og:type" content="article"><meta property="og:site_name" content="EventCrazy">';
	echo '<meta property="og:title" content="' . esc_attr($inf['title']) . '"><meta property="og:description" content="' . esc_attr($desc) . '">';
	echo '<meta property="og:url" content="' . esc_url(cp_el_pub_page_url($id, $name)) . '">';
	echo '<meta property="og:image" content="' . esc_url($img) . '"><meta property="og:image:secure_url" content="' . esc_url($img) . '">';
	echo '<meta property="og:image:type" content="' . (isset($types[$ext]) ? $types[$ext] : 'image/jpeg') . '">';
	if ($sz) { echo '<meta property="og:image:width" content="' . (int) $sz[0] . '"><meta property="og:image:height" content="' . (int) $sz[1] . '">'; }
	echo '<meta property="og:image:alt" content="' . esc_attr($inf['title']) . '">';
	echo '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="' . esc_attr($inf['title']) . '"><meta name="twitter:image" content="' . esc_url($img) . '">';
	echo '<style>body{margin:0;background:#14213D;color:#fff;font:15px/1.45 system-ui,-apple-system,sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:16px;box-sizing:border-box}img{max-width:100%;max-height:82vh;border-radius:12px}p{margin:0;text-align:center;opacity:.85}</style></head><body>';
	echo '<img src="' . esc_url($img) . '" alt="' . esc_attr($inf['title']) . '"><p><b>' . esc_html($inf['title']) . '</b>' . ($desc !== '' ? '<br>' . esc_html($desc) : '') . '</p></body></html>';
}

add_action('init', 'cp_el_img_route', 98);
function cp_el_img_route() {
	if (is_admin() || wp_doing_ajax()) { return; }
	$p = cp_panel_opts();
	$slug = trim($p['slug'], '/');
	$path = trim((string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '', PHP_URL_PATH), '/');
	if ($slug === '' || !preg_match('#^' . preg_quote($slug, '#') . '/(kep|foto)/(m?\d{1,20})\.([a-f0-9]{14})/([^/]+)$#', $path, $m)) { return; }
	if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
	$id = $m[2];
	$seg = basename(rawurldecode($m[4]));
	$cands = ($m[1] === 'kep' || preg_match('/\.(jpg|png|webp)$/', $seg)) ? array($seg) : array($seg . '.jpg', $seg . '.png', $seg . '.webp');
	$name = '';
	foreach ($cands as $c) {
		if (preg_match('/^' . preg_quote($id, '/') . '-[a-z0-9]{16}\.(jpg|png|webp)$/', $c) && hash_equals(cp_el_img_sig($id, $c), $m[3]) && is_file(cp_el_dir() . '/' . $c)) { $name = $c; break; }
	}
	$ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
	cp_el_plog($m[1] === 'kep' ? 'kép' : 'fotóoldal', ($name === '' ? '404' : '200') . ' – ' . ($ua !== '' ? $ua : 'ismeretlen kliens'));
	if ($name === '') {
		status_header(404);
		nocache_headers();
		exit;
	}
	$file = cp_el_dir() . '/' . $name;
	if ($m[1] === 'foto') {
		cp_el_photo_page($id, $name, $file);
		exit;
	}
	$types = array('jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	status_header(200);
	header('Content-Type: ' . $types[$ext]);
	header('Content-Length: ' . filesize($file));
	header('Cache-Control: public, max-age=604800');
	header('X-Robots-Tag: noindex, nofollow');
	echo file_get_contents($file);
	exit;
}

/* =====================================================================
 * ÜGYFÉLPORTÁL (v1.8) – portal.php és a portal-*.php fájlok
 * ===================================================================== */
require_once __DIR__ . '/portal.php';
require_once __DIR__ . '/sablon.php';

/* =====================================================================
 * ÁKOS TÁRCA (v1.9) – Tárca fül + egyenleg az Elszámolás fülön
 * Egyenleg = kézi/importált tételek
 *          + az Elszámolás kifizetései (Ákos fizette, nem átutalással; az indulás után rögzítettek)
 *          + tárcába került AAM-befizetések (Salesmate: helyszínen készpénz + EV)
 * Tárolás: cp_tw_settings, tételenként cp_twe_{id}, cp_tw_base (az indulás előtti kifizetések kulcsai)
 * ===================================================================== */

define('CP_TW_OPT', 'cp_tw_settings');
define('CP_TW_E', 'cp_twe_');
define('CP_TW_BASE', 'cp_tw_base');

function cp_tw_defaults() {
	return array(
		'payer'    => 'Ákos',
		'f_pay'    => 'textCustomField4',
		'pay_val'  => 'Helyszínen készpénz',
		'f_ev'     => 'textCustomField11',
		'ev_val'   => 'EV',
		'aam_from' => '2026-09-01',
		'aam_mode' => 'strict',
		'aam_skip' => array(),
		'key'      => '',
	);
}

function cp_tw_opts() {
	$o = get_option(CP_TW_OPT, array());
	if (!is_array($o)) { $o = array(); }
	$o = array_merge(cp_tw_defaults(), $o);
	if (!is_array($o['aam_skip'])) { $o['aam_skip'] = array(); }
	if ($o['key'] === '') {
		$o['key'] = wp_generate_password(24, false, false);
		update_option(CP_TW_OPT, $o, false);
	}
	return $o;
}

function cp_tw_save_opts($o) {
	update_option(CP_TW_OPT, $o, false);
}

/* kategória => alapértelmezett előjel (+1 bevétel, -1 kiadás) */
function cp_tw_cats() {
	return array(
		'bér'              => -1,
		'AAM befiz'        => 1,
		'Tulaj. befiz'     => 1,
		'furgon'           => -1,
		'parkolás'         => -1,
		'raktár'           => -1,
		'eszköz bérbeadás' => 1,
		'Ákos törlesztő'   => 1,
		'egyenleg'         => 1,
		'egyéb'            => -1,
	);
}

/* ------------------------------------------------------------ tételek */

function cp_tw_id_ok($id) {
	return is_string($id) && preg_match('/^w[a-z0-9]{8,30}$/', $id) === 1;
}

function cp_tw_newid() {
	return 'w' . uniqid() . wp_rand(10, 99);
}

function cp_tw_now_o() {
	return (int) round(microtime(true) * 1000);
}

function cp_tw_get($id) {
	if (!cp_tw_id_ok($id)) { return null; }
	$v = get_option(CP_TW_E . $id, null);
	return (is_array($v) && !empty($v['id'])) ? $v : null;
}

function cp_tw_put($x) {
	update_option(CP_TW_E . $x['id'], $x, false);
}

function cp_tw_cmp($a, $b) {
	$c = strcmp((string) $a['d'], (string) $b['d']);
	if ($c !== 0) { return $c; }
	$oa = isset($a['o']) ? (float) $a['o'] : 0;
	$ob = isset($b['o']) ? (float) $b['o'] : 0;
	return $oa < $ob ? -1 : ($oa > $ob ? 1 : 0);
}

function cp_tw_all() {
	global $wpdb;
	$res = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(CP_TW_E) . '%'), ARRAY_A);
	$out = array();
	foreach ((array) $res as $r) {
		$v = maybe_unserialize($r['option_value']);
		if (is_array($v) && !empty($v['id']) && isset($v['d'], $v['a'])) { $out[] = $v; }
	}
	usort($out, 'cp_tw_cmp');
	return $out;
}

/* bejövő adatok ellenőrzése; tömb vagy hibaüzenet */
function cp_tw_clean($in) {
	$d = preg_replace('/[^0-9-]/', '', cp_el_s($in, 'd'));
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { return 'Hibás dátum.'; }
	$a = cp_el_int(str_replace(array('Ft', 'ft'), '', cp_el_s($in, 'a')), -50000000, 50000000);
	if ($a === null || $a === '' || (int) $a === 0) { return 'Hibás összeg.'; }
	$c = mb_substr(trim(sanitize_text_field(cp_el_s($in, 'c'))), 0, 40);
	if ($c === '') { $c = 'egyéb'; }
	$t = mb_substr(trim(sanitize_text_field(cp_el_s($in, 't'))), 0, 200);
	if ($t === '') { return 'Add meg a megnevezést.'; }
	return array(
		'd'  => $d,
		'a'  => (int) $a,
		'c'  => $c,
		'k'  => mb_substr(trim(sanitize_text_field(cp_el_s($in, 'k'))), 0, 60),
		't'  => $t,
		'n'  => mb_substr(trim(sanitize_text_field(cp_el_s($in, 'n'))), 0, 300),
		'sz' => cp_el_s($in, 'sz') === '1' ? '1' : '',
	);
}

/* ------------------------------------------------------------ Elszámolás → tárca (bérek) */

function cp_tw_base() {
	$b = get_option(CP_TW_BASE, null);
	return (is_array($b) && isset($b['k']) && is_array($b['k'])) ? $b : null;
}

function cp_tw_row_kez($id, $row, $cal) {
	$kid = '';
	foreach (array('ko', 'k') as $f) {
		if (!empty($row[$f]) && is_scalar($row[$f])) { $kid = (string) $row[$f]; break; }
	}
	if ($kid === '' && isset($row['i']) && $row['i'] === 'r0' && isset($cal[$id]['k0']) && is_scalar($cal[$id]['k0'])) {
		$kid = (string) $cal[$id]['k0'];
	}
	$kz = $kid !== '' ? cp_el_kez_find($kid) : null;
	if ($kz) { return (string) $kz['name']; }
	if (!empty($row['ksm']) && is_scalar($row['ksm'])) { return (string) $row['ksm']; }
	return $kid;
}

/* az Elszámolás fülön rögzített, Ákos által nem átutalással fizetett tételek – kifizetési nap + kezelő szerint összevonva */
function cp_tw_derived($o) {
	$base = cp_tw_base();
	if (!$base) { return array(); }
	$cal = get_option(CP_EL_CAL, array());
	if (!is_array($cal)) { $cal = array(); }
	$payer = cp_panel_lc($o['payer']);
	$grp = array();
	foreach (cp_el_st_all() as $pair) {
		list($id, $st) = $pair;
		if (empty($st['r']) || !is_array($st['r'])) { continue; }
		foreach ($st['r'] as $row) {
			if (!is_array($row) || empty($row['pd']) || !isset($row['i'])) { continue; }
			if (cp_panel_lc(trim(cp_el_s($row, 'pb'))) !== $payer) { continue; }
			if (cp_el_s($row, 'pm') === 'atutalas') { continue; }
			$pd = (string) $row['pd'];
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd)) { continue; }
			if (isset($base['k'][$id . '|' . $row['i'] . '|' . $pd])) { continue; }
			/* az indulás előtti napokra rögzített (utólag bevitt) kifizetések már a táblázatban vannak */
			if (!empty($base['from']) && $pd < $base['from']) { continue; }
			$name = cp_tw_row_kez($id, $row, $cal);
			/* ismeretlen kezelő (pl. csak a Salesmate-ben van): külön sor, a panel a hivatkozásból feloldja */
			$ref = $name === '' ? $id . '|' . $row['i'] : '';
			$gk = $pd . '|' . ($ref !== '' ? '?' . $ref : $name);
			if (!isset($grp[$gk])) {
				$grp[$gk] = array('pd' => $pd, 'name' => $name !== '' ? $name : 'Kezelő', 'ref' => $ref, 'refs' => array(), 'sum' => 0, 'dates' => array(), 'n' => 0);
			}
			$grp[$gk]['sum'] += (int) (isset($row['pv']) ? $row['pv'] : 0);
			$grp[$gk]['n']++;
			$grp[$gk]['refs'][] = $id . '|' . $row['i'];
			$ed = cp_el_s($st, 'd');
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed)) { $grp[$gk]['dates'][$ed] = 1; }
		}
	}
	$out = array();
	foreach ($grp as $gk => $g) {
		if ((int) $g['sum'] === 0) { continue; }
		$dates = array_keys($g['dates']);
		sort($dates);
		$lbl = array();
		foreach ($dates as $x) { $lbl[] = str_replace('-', '.', substr($x, 5)); }
		$out[] = array(
			'id'  => 'el' . substr(md5($gk), 0, 12),
			'd'   => $g['pd'],
			'a'   => -1 * (int) $g['sum'],
			'c'   => 'bér',
			'k'   => $g['name'],
			't'   => $g['name'] . ' bér' . ($lbl ? ' (' . implode(', ', $lbl) . ')' : ''),
			'n'   => $g['n'] . ' tétel az Elszámolásból',
			'sz'  => '',
			'src' => 'el',
			'em'  => $dates ? $dates[0] : $g['pd'],
			'ref' => $g['ref'],
			'refs' => $g['refs'],
			'o'   => (int) strtotime($g['pd'] . ' 12:00:00') * 1000,
		);
	}
	usort($out, 'cp_tw_cmp');
	return $out;
}

/* ------------------------------------------------------------ AAM (helyszíni készpénz) a Salesmate-ből */

function cp_tw_aam_deals($o, $force) {
	$p = cp_panel_opts();
	$e = cp_el_opts();
	$from = $o['aam_from'];
	$to = current_time('Y-m-d');
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || $from > $to) { return array('list' => array(), 'loose' => array(), 'stats' => array(), 'at' => time()); }
	$fields = array('deal.id', 'deal.title', 'deal.status', 'deal.stage', 'deal.dealValue', 'deal.primaryContact', 'deal.' . $p['f_date'], 'deal.' . $o['f_pay'], 'deal.' . $o['f_ev']);
	if ($e['f_kd'] !== '') { $fields[] = 'deal.' . $e['f_kd']; }
	if ($e['f_gk'] !== '') { $fields[] = 'deal.' . $e['f_gk']; }
	$fields = array_values(array_unique($fields));
	// a cp_panel_d_ előtag miatt a panel gyorsítótár-ürítése ezt is törli
	$ck = 'cp_panel_d_tw' . md5('v2|' . $from . $to . wp_json_encode($fields) . $o['pay_val'] . '|' . $o['ev_val']);
	$c = $force ? false : get_transient($ck);
	if (!is_array($c) || !isset($c['loose'])) {
		$info = null;
		$rows = cp_sm_fetch_range($from, $to, $info, $fields);
		if (is_wp_error($rows)) { return $rows; }
		$list = array();
		$loose = array();
		$stats = array();
		$seen = array();
		$pv = cp_panel_lc($o['pay_val']);
		$ev = cp_panel_lc($o['ev_val']);
		foreach ((array) $rows as $r) {
			$id = cp_panel_str(cp_panel_get($r, 'id'));
			if ($id === '' || isset($seen[$id])) { continue; }
			$seen[$id] = 1;
			if (cp_panel_lc(cp_panel_str(cp_panel_get($r, 'status'))) === 'lost') { continue; }
			if (cp_panel_lc(trim(cp_panel_str(cp_panel_get($r, $o['f_ev'])))) !== $ev) { continue; }
			$date = cp_panel_norm_date(cp_panel_get($r, $p['f_date']));
			if ($date === '' || $date < $from || $date > $to) { continue; }
			$pm = trim(cp_panel_str(cp_panel_get($r, $o['f_pay'])));
			$sk = $pm === '' ? '(nincs kitöltve)' : $pm;
			$stats[$sk] = isset($stats[$sk]) ? $stats[$sk] + 1 : 1;
			$isPay = cp_panel_lc($pm) === $pv;
			/* „laza” mód: EV + helyszíni készpénz VAGY üres fizetési mód; más fizetési móddal (utalás, kártya) soha */
			if (!$isPay && $pm !== '') { continue; }
			$title = cp_panel_str(cp_panel_get($r, 'title'));
			$pc = cp_panel_get($r, 'primaryContact');
			$val = cp_panel_get($r, 'dealValue');
			$kd = $e['f_kd'] !== '' ? cp_panel_get($r, $e['f_kd']) : null;
			$item = array(
				'id'    => $id,
				'date'  => $date,
				'title' => $title,
				'who'   => cp_el_who($title, is_array($pc) ? cp_panel_str($pc) : ('Deal #' . $id)),
				'val'   => is_numeric($val) ? (int) round((float) $val) : 0,
				'kd'    => is_numeric($kd) ? (int) round((float) $kd) : 0,
				'gk'    => $e['f_gk'] !== '' ? cp_panel_str(cp_panel_get($r, $e['f_gk'])) : '',
				'stage' => cp_panel_str(cp_panel_get($r, 'stage')),
				'pm'    => $pm,
			);
			if ($isPay) { $list[] = $item; }
			$loose[] = $item;
		}
		$cmp = function ($a, $b) { return strcmp($a['date'], $b['date']); };
		usort($list, $cmp);
		usort($loose, $cmp);
		$c = array('list' => $list, 'loose' => $loose, 'stats' => $stats, 'at' => time());
		set_transient($ck, $c, max(1, (int) $p['cache_min']) * MINUTE_IN_SECONDS);
	}
	return $c;
}

function cp_tw_norm_txt($s) {
	return trim(preg_replace('/\s+/u', ' ', cp_panel_lc((string) $s)));
}

/* a még nem a tárcában lévő AAM-dealek (a már rögzítettek, a kivettek és a táblázatból importáltak nélkül) */
function cp_tw_pending($o, $list, $led) {
	$booked = array();
	$txt = array();
	foreach ($led as $x) {
		if (!empty($x['deal'])) { $booked[(string) $x['deal']] = 1; }
		if (empty($x['deal']) && cp_panel_lc((string) $x['c']) === cp_panel_lc('AAM befiz')) { $txt[] = cp_tw_norm_txt($x['t'] . ' ' . (isset($x['n']) ? $x['n'] : '')); }
	}
	$out = array();
	foreach ($list as $d) {
		$id = (string) $d['id'];
		if (isset($booked[$id]) || isset($o['aam_skip'][$id])) { continue; }
		$who = cp_tw_norm_txt($d['who']);
		$hit = false;
		if (mb_strlen($who) >= 5) {
			foreach ($txt as $t) { if (mb_strpos($t, $who) !== false) { $hit = true; break; } }
		}
		if ($hit) { continue; }
		$out[] = $d;
	}
	return $out;
}

function cp_tw_rows_all($o) {
	$rows = array_merge(cp_tw_all(), cp_tw_derived($o));
	usort($rows, 'cp_tw_cmp');
	return $rows;
}

function cp_tw_payload($force) {
	$o = cp_tw_opts();
	$p = cp_panel_opts();
	$led = cp_tw_all();
	$der = cp_tw_derived($o);
	$aam = cp_tw_aam_deals($o, $force);
	$aamErr = '';
	if (is_wp_error($aam)) {
		$aamErr = $aam->get_error_message();
		$aam = array('list' => array(), 'loose' => array(), 'stats' => array(), 'at' => null);
	}
	$mode = $o['aam_mode'] === 'loose' ? 'loose' : 'strict';
	$src = ($mode === 'loose' && isset($aam['loose'])) ? $aam['loose'] : $aam['list'];
	$pending = cp_tw_pending($o, $src, $led);
	$bal = 0;
	foreach ($led as $x) { $bal += (int) $x['a']; }
	foreach ($der as $x) { $bal += (int) $x['a']; }
	$aamOpen = 0;
	foreach ($pending as $x) { $aamOpen += (int) $x['val'] + (int) $x['kd']; }
	$base = cp_tw_base();
	return array(
		'ledger'  => $led,
		'derived' => $der,
		'aam'     => $pending,
		'aamErr'  => $aamErr,
		'aamAt'   => $aam['at'],
		'aamFrom' => $o['aam_from'],
		'bal'     => $bal,
		'aamOpen' => $aamOpen,
		'aamTotal' => count($src),
		'aamMode' => $mode,
		'aamStats' => isset($aam['stats']) ? (object) $aam['stats'] : new stdClass(),
		'cats'    => array_keys(cp_tw_cats()),
		'catSign' => cp_tw_cats(),
		'payer'   => $o['payer'],
		'payVal'  => $o['pay_val'],
		'evVal'   => $o['ev_val'],
		'skipped' => count($o['aam_skip']),
		'live'    => (bool) $base,
		'liveAt'  => $base ? (int) $base['t'] : null,
		'liveFrom' => ($base && !empty($base['from'])) ? $base['from'] : null,
		'feed'    => admin_url('admin-ajax.php') . '?action=cp_tw_feed&key=' . rawurlencode($o['key']),
	);
}

/* ------------------------------------------------------------ AJAX */

add_action('wp_ajax_nopriv_cp_tw_data', 'cp_tw_ajax_data');
add_action('wp_ajax_cp_tw_data', 'cp_tw_ajax_data');
function cp_tw_ajax_data() {
	cp_el_auth();
	wp_send_json_success(cp_tw_payload(!empty($_REQUEST['refresh'])));
}

add_action('wp_ajax_nopriv_cp_tw_save', 'cp_tw_ajax_save');
add_action('wp_ajax_cp_tw_save', 'cp_tw_ajax_save');
function cp_tw_ajax_save() {
	cp_el_auth();
	$in = wp_unslash($_POST);
	/* a megnevezés „tt” néven jön, mert a „t” a panel CSRF-tokenje */
	$in['t'] = cp_el_s($in, 'tt');
	$x = cp_tw_clean($in);
	if (!is_array($x)) { cp_el_err($x); }
	$id = cp_el_s($in, 'id');
	if ($id !== '') {
		$old = cp_tw_get($id);
		if (!$old) { cp_el_err('A tétel már nem létezik – frissítsd az oldalt.', 404); }
		$x = array_merge($old, $x);
		$x['m'] = time();
	} else {
		$x['id'] = cp_tw_newid();
		$x['src'] = 'kezi';
		$x['o'] = cp_tw_now_o();
		$x['ts'] = time();
	}
	cp_tw_put($x);
	wp_send_json_success(cp_tw_payload(false));
}

add_action('wp_ajax_nopriv_cp_tw_del', 'cp_tw_ajax_del');
add_action('wp_ajax_cp_tw_del', 'cp_tw_ajax_del');
function cp_tw_ajax_del() {
	cp_el_auth();
	$id = cp_el_s(wp_unslash($_POST), 'id');
	if (!cp_tw_get($id)) { cp_el_err('A tétel már nem létezik – frissítsd az oldalt.', 404); }
	delete_option(CP_TW_E . $id);
	wp_send_json_success(cp_tw_payload(false));
}

add_action('wp_ajax_nopriv_cp_tw_aam', 'cp_tw_ajax_aam');
add_action('wp_ajax_cp_tw_aam', 'cp_tw_ajax_aam');
function cp_tw_ajax_aam() {
	cp_el_auth();
	$in = wp_unslash($_POST);
	$deal = preg_replace('/[^0-9]/', '', cp_el_s($in, 'deal'));
	if ($deal === '' || strlen($deal) > 20) { cp_el_err('Hibás deal.'); }
	$o = cp_tw_opts();
	if (cp_el_s($in, 'skip') === '1') {
		$o['aam_skip'][$deal] = time();
		cp_tw_save_opts($o);
		wp_send_json_success(cp_tw_payload(false));
	}
	foreach (cp_tw_all() as $e) {
		if (!empty($e['deal']) && (string) $e['deal'] === $deal) { cp_el_err('Ez a befizetés már a tárcában van.', 409); }
	}
	$ed = cp_el_req_date('ed');
	$who = mb_substr(trim(sanitize_text_field(cp_el_s($in, 'who'))), 0, 120);
	$x = cp_tw_clean(array(
		'd' => cp_el_s($in, 'd'),
		'a' => cp_el_s($in, 'a'),
		'c' => 'AAM befiz',
		'k' => cp_el_s($in, 'k'),
		't' => ($ed !== '' ? str_replace('-', '.', $ed) . ' ' : '') . ($who !== '' ? $who : 'Deal #' . $deal) . ' AAM',
		'n' => cp_el_s($in, 'n'),
	));
	if (!is_array($x)) { cp_el_err($x); }
	if ($x['a'] < 0) { cp_el_err('A befizetés összege pozitív legyen.'); }
	$x['id'] = cp_tw_newid();
	$x['src'] = 'aam';
	$x['deal'] = $deal;
	$x['o'] = cp_tw_now_o();
	$x['ts'] = time();
	cp_tw_put($x);
	wp_send_json_success(cp_tw_payload(false));
}

add_action('wp_ajax_nopriv_cp_tw_cfg', 'cp_tw_ajax_cfg');
add_action('wp_ajax_cp_tw_cfg', 'cp_tw_ajax_cfg');
function cp_tw_ajax_cfg() {
	cp_el_auth();
	$in = wp_unslash($_POST);
	$o = cp_tw_opts();
	$f = cp_el_req_date('aam_from');
	if ($f !== '') { $o['aam_from'] = $f; }
	$lf = cp_el_req_date('live_from');
	$b = cp_tw_base();
	if ($lf !== '' && $b) { $b['from'] = $lf; update_option(CP_TW_BASE, $b, false); }
	if (cp_el_s($in, 'unskip') === '1') { $o['aam_skip'] = array(); }
	$mode = cp_el_s($in, 'aam_mode');
	if ($mode === 'strict' || $mode === 'loose') { $o['aam_mode'] = $mode; }
	if (cp_el_s($in, 'newkey') === '1') { $o['key'] = wp_generate_password(24, false, false); }
	cp_tw_save_opts($o);
	wp_send_json_success(cp_tw_payload(false));
}

/* egyszeri import a 2026-os táblázatból – csak üres tárcába; egyben rögzíti az indulás előtti kifizetéseket */
add_action('wp_ajax_nopriv_cp_tw_import', 'cp_tw_ajax_import');
add_action('wp_ajax_cp_tw_import', 'cp_tw_ajax_import');
function cp_tw_ajax_import() {
	cp_el_auth();
	if (cp_tw_all()) { cp_el_err('A tárcában már vannak tételek – az import csak üres tárcába futtatható.', 409); }
	$rows = json_decode(isset($_POST['rows']) ? (string) wp_unslash($_POST['rows']) : '', true);
	if (!is_array($rows) || !$rows || count($rows) > 3000) { cp_el_err('Hibás importadat.'); }
	$clean = array();
	$bad = array();
	foreach (array_values($rows) as $n => $r) {
		$x = cp_tw_clean(is_array($r) ? $r : array());
		if (!is_array($x)) { $bad[] = ($n + 1) . '. sor: ' . $x; continue; }
		$clean[] = $x;
	}
	if ($bad) { cp_el_err('Hibás sorok: ' . implode('; ', array_slice($bad, 0, 5))); }
	$o0 = cp_tw_now_o() - 100000;
	foreach ($clean as $n => $x) {
		$x['id'] = cp_tw_newid();
		$x['src'] = 'import';
		$x['o'] = $o0 + $n;
		$x['ts'] = time();
		cp_tw_put($x);
	}
	$base = array();
	foreach (cp_el_st_all() as $pair) {
		list($id, $st) = $pair;
		if (empty($st['r']) || !is_array($st['r'])) { continue; }
		foreach ($st['r'] as $row) {
			if (is_array($row) && !empty($row['pd']) && isset($row['i'])) { $base[$id . '|' . $row['i'] . '|' . $row['pd']] = 1; }
		}
	}
	$lf = cp_el_req_date('live_from');
	if ($lf === '') { $lf = current_time('Y-m-d'); }
	update_option(CP_TW_BASE, array('t' => time(), 'from' => $lf, 'k' => $base), false);
	$f = cp_el_req_date('aam_from');
	if ($f !== '') {
		$o = cp_tw_opts();
		$o['aam_from'] = $f;
		cp_tw_save_opts($o);
	}
	wp_send_json_success(cp_tw_payload(false));
}

function cp_tw_csv_line($vals) {
	$out = array();
	foreach ($vals as $v) {
		$v = (string) $v;
		$out[] = preg_match('/[",\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
	}
	return implode(',', $out) . "\r\n";
}

/* Google-táblázat tükör: =IMPORTDATA("…admin-ajax.php?action=cp_tw_feed&key=…") */
add_action('wp_ajax_nopriv_cp_tw_feed', 'cp_tw_feed');
add_action('wp_ajax_cp_tw_feed', 'cp_tw_feed');
function cp_tw_feed() {
	$o = cp_tw_opts();
	$k = isset($_GET['key']) ? (string) wp_unslash($_GET['key']) : '';
	if ($k === '' || !hash_equals((string) $o['key'], $k)) {
		status_header(403);
		echo 'forbidden';
		exit;
	}
	$src = array('el' => 'Elszámolás', 'aam' => 'Salesmate AAM', 'import' => 'Táblázat (import)', 'kezi' => 'Kézi');
	nocache_headers();
	header('Content-Type: text/csv; charset=utf-8');
	header('X-Robots-Tag: noindex, nofollow');
	echo cp_tw_csv_line(array('Dátum', 'Összeg', 'Tárca egyenleg', 'Kategória', 'Kezelő', 'Megnevezés', 'Megjegyzés', 'Számla leadva', 'Forrás'));
	$run = 0;
	foreach (cp_tw_rows_all($o) as $x) {
		$run += (int) $x['a'];
		$s = isset($x['src']) ? (string) $x['src'] : 'kezi';
		echo cp_tw_csv_line(array(
			str_replace('-', '.', $x['d']),
			(int) $x['a'],
			$run,
			isset($x['c']) ? $x['c'] : '',
			isset($x['k']) ? $x['k'] : '',
			isset($x['t']) ? $x['t'] : '',
			isset($x['n']) ? $x['n'] : '',
			!empty($x['sz']) ? 'igen' : '',
			isset($src[$s]) ? $src[$s] : $s,
		));
	}
	exit;
}
