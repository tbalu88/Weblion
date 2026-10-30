<?php
/* =====================================================================
 * Google Naptár (v1.71) – az „EventCrazy” naptár a panelben
 * – bekötés szolgáltatásfiókkal (a kulcsfájlt az admin tölti fel a WordPressben: Beállítások → Crazy Google Naptár;
 *   a kulcs soha nem kerül ki a böngészőbe), a naptárat a szolgáltatásfiók e-mail-címével kell megosztani,
 * – Naptár fül: a bejegyzések havi és listanézetben, dealhez párosítva (a kezelői adatlap linkje vagy a név alapján),
 *   ✏️ = ceruzás (szerződés kiküldve), figyelmeztetés a naptárból hiányzó, szerződéses szakaszban lévő dealekre,
 * – átállás (Beállítások → Átállás, „Google Naptár”): árnyékban a Salesmate írja a naptárat, a panel ellenőrzi, hogy
 *   a „Szerződés kiküldve” után megjelent-e a bejegyzés (✏️-vel), és aláíráskor lekerült-e a ✏️. A panel még nem ír.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_GCAL_SCOPE', 'https://www.googleapis.com/auth/calendar.events');
/* a szakaszok, amelyekben a dealnek már van naptárbejegyzése (az első a ceruzás) */
function cp_gcal_stages() { return array('Szerződés kiküldve', 'Szerződés aláírva', 'Adatlap kiküldve', 'Rendezvény felkészül'); }

function cp_gcal_opts() { $o = get_option('cp_gcal', array()); return array_merge(array('cal' => '', 'email' => ''), is_array($o) ? $o : array()); }
function cp_gcal_key() { $k = get_option('cp_gcal_key', array()); return is_array($k) && !empty($k['pk']) && !empty($k['email']) ? $k : null; }
function cp_gcal_ok() { $o = cp_gcal_opts(); return $o['cal'] !== '' && cp_gcal_key() !== null; }

/* ------------------------------------------------------------ hozzáférési token (JWT, RS256) */
function cp_gcal_b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function cp_gcal_token($fresh = false) {
	if (!$fresh) { $t = get_transient('cp_gcal_tok'); if ($t) { return $t; } }
	$k = cp_gcal_key();
	if (!$k) { return new WP_Error('cp_gcal', 'A Google Naptár nincs bekötve.'); }
	if (!function_exists('openssl_sign')) { return new WP_Error('cp_gcal', 'A szerveren nincs OpenSSL.'); }
	$now = time();
	$uri = !empty($k['uri']) && strpos($k['uri'], 'https://oauth2.googleapis.com/') === 0 ? $k['uri'] : 'https://oauth2.googleapis.com/token';
	$jwt = cp_gcal_b64u(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT'))) . '.' . cp_gcal_b64u(wp_json_encode(array('iss' => $k['email'], 'scope' => CP_GCAL_SCOPE, 'aud' => $uri, 'iat' => $now - 30, 'exp' => $now + 3300)));
	$sig = '';
	if (!@openssl_sign($jwt, $sig, $k['pk'], 'sha256WithRSAEncryption')) { return new WP_Error('cp_gcal', 'A kulcsfájl nem használható (aláírási hiba) – töltsd fel újra.'); }
	$r = wp_remote_post($uri, array('timeout' => 15, 'body' => array('grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . cp_gcal_b64u($sig))));
	if (is_wp_error($r)) { return new WP_Error('cp_gcal', 'A Google nem érhető el: ' . $r->get_error_message()); }
	$j = json_decode(wp_remote_retrieve_body($r), true);
	if (empty($j['access_token'])) { return new WP_Error('cp_gcal', 'A Google elutasította a belépést (' . sanitize_text_field((string) ($j['error_description'] ?? $j['error'] ?? wp_remote_retrieve_response_code($r))) . ').'); }
	set_transient('cp_gcal_tok', $j['access_token'], max(60, (int) ($j['expires_in'] ?? 3600) - 300));
	return $j['access_token'];
}

function cp_gcal_req($method, $path, $query = array(), $body = null) {
	$tok = cp_gcal_token();
	if (is_wp_error($tok)) { return $tok; }
	$url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(cp_gcal_opts()['cal']) . $path . ($query ? '?' . http_build_query($query) : '');
	$args = array('method' => $method, 'timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json'));
	if ($body !== null) { $args['body'] = wp_json_encode($body); }
	$r = wp_remote_request($url, $args);
	if (is_wp_error($r)) { return new WP_Error('cp_gcal', 'A Google Naptár nem érhető el: ' . $r->get_error_message()); }
	$code = (int) wp_remote_retrieve_response_code($r);
	$j = json_decode(wp_remote_retrieve_body($r), true);
	if ($code === 401) { delete_transient('cp_gcal_tok'); }
	if ($code === 404) { return new WP_Error('cp_gcal', 'A naptár nem található – ellenőrizd a naptár azonosítóját, és hogy meg van-e osztva a szolgáltatásfiókkal.'); }
	if ($code === 403) { return new WP_Error('cp_gcal', 'Nincs jogosultság a naptárhoz – oszd meg a naptárat a szolgáltatásfiókkal („Módosíthatja az eseményeket”).'); }
	if ($code >= 300) { return new WP_Error('cp_gcal', 'Google Naptár hiba (' . $code . '): ' . sanitize_text_field((string) ($j['error']['message'] ?? ''))); }
	return is_array($j) ? $j : array();
}

/* ------------------------------------------------------------ események olvasása */
function cp_gcal_local($x) {
	if (!empty($x['dateTime'])) { try { $d = new DateTime($x['dateTime']); $d->setTimezone(wp_timezone()); return $d->format('Y-m-d H:i'); } catch (Exception $e) { return ''; } }
	return (string) ($x['date'] ?? '');
}
function cp_gcal_desc_html($d) {
	$d = (string) $d;
	if ($d === '') { return ''; }
	if (!preg_match('/<[a-z][^>]*>/i', $d)) { return nl2br(esc_html($d)); }
	return wp_kses($d, array('a' => array('href' => true), 'b' => array(), 'strong' => array(), 'i' => array(), 'em' => array(), 'u' => array(), 'br' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'span' => array()));
}
function cp_gcal_pen($t) { return strpos((string) $t, '✏') !== false; }

/* nyers lista a tartományra (helyi dátumok, a „to” napot is beleértve); 90 mp-ig tárolva */
function cp_gcal_events($from, $to, $force = false) {
	$ck = 'cp_gcal_l_' . md5($from . '|' . $to . '|' . cp_gcal_opts()['cal']);
	if (!$force) { $c = get_transient($ck); if (is_array($c)) { return $c; } }
	$tz = wp_timezone();
	$a = new DateTime($from . ' 00:00:00', $tz); $b = new DateTime($to . ' 00:00:00', $tz); $b->modify('+1 day');
	$q = array('timeMin' => $a->format(DATE_RFC3339), 'timeMax' => $b->format(DATE_RFC3339), 'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 2500, 'timeZone' => $tz->getName());
	$out = array(); $page = 0;
	do {
		$r = cp_gcal_req('GET', '/events', $q);
		if (is_wp_error($r)) { return $r; }
		foreach ((array) ($r['items'] ?? array()) as $e) {
			if (($e['status'] ?? '') === 'cancelled') { continue; }
			$ad = empty($e['start']['dateTime']);
			$s = cp_gcal_local($e['start'] ?? array()); $en = cp_gcal_local($e['end'] ?? array());
			if ($ad && $en !== '') { $en = gmdate('Y-m-d', strtotime($en . ' 00:00:00 UTC') - DAY_IN_SECONDS); }
			$g = array();
			foreach ((array) ($e['attendees'] ?? array()) as $x) { if (!empty($x['resource'])) { continue; } $g[] = array('n' => (string) ($x['displayName'] ?? ''), 'e' => (string) ($x['email'] ?? ''), 'st' => (string) ($x['responseStatus'] ?? '')); }
			$out[] = array('id' => (string) $e['id'], 't' => (string) ($e['summary'] ?? '(nincs cím)'), 's' => $s, 'e' => $en, 'ad' => $ad, 'loc' => (string) ($e['location'] ?? ''), 'raw' => (string) ($e['description'] ?? ''),
				'link' => (string) ($e['htmlLink'] ?? ''), 'col' => (string) ($e['colorId'] ?? ''), 'g' => $g);
		}
		$q['pageToken'] = (string) ($r['nextPageToken'] ?? '');
	} while ($q['pageToken'] !== '' && ++$page < 5);
	set_transient($ck, $out, 90);
	return $out;
}

/* ------------------------------------------------------------ párosítás a dealekkel */
function cp_gcal_norm($s) { return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(remove_accents((string) $s)))); }
function cp_gcal_deals($from, $to) {
	global $wpdb;
	return (array) $wpdb->get_results($wpdb->prepare('SELECT d.id, d.title, d.stage, d.status, d.event_d, d.owner_id, d.venue, d.hours, d.start_at, c.name cn FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . " c ON c.id = d.contact_id WHERE d.event_d BETWEEN %s AND %s AND d.status <> 'Lost'", $from, $to), ARRAY_A);
}
/* az esemény dealje: 1) a leírásban lévő kezelői adatlap link (…?k=<deal>.<aláírás>), 2) a kontakt neve a címben, azonos napon */
function cp_gcal_match($ev, $byDate, $byId) {
	if (preg_match('/[?&](?:amp;)?k=(\d{1,12})\.[a-f0-9]{14}/', $ev['raw'], $m) && isset($byId[(int) $m[1]])) { return (int) $m[1]; }
	$day = substr($ev['s'], 0, 10);
	$t = ' ' . cp_gcal_norm($ev['t']) . ' ';
	$best = 0; $bl = 0;
	foreach ($byDate[$day] ?? array() as $d) {
		$k = cp_gcal_norm($d['cn']);
		if (strlen($k) < 3) { continue; }
		$toks = array_filter(explode(' ', $k), function ($x) { return strlen($x) > 1; });
		$ok = strpos($t, ' ' . $k . ' ') !== false || (count($toks) >= 2 && !array_filter($toks, function ($x) use ($t) { return strpos($t, ' ' . $x . ' ') === false; }));
		if ($ok && strlen($k) > $bl) { $best = (int) $d['id']; $bl = strlen($k); }
	}
	return $best;
}
function cp_gcal_index($deals) {
	$byDate = array(); $byId = array();
	foreach ($deals as $d) { $byDate[$d['event_d']][] = $d; $byId[(int) $d['id']] = $d; }
	return array($byDate, $byId);
}

/* ------------------------------------------------------------ amit a panel írna (átállás után) */
function cp_gcal_plan($id) {
	global $wpdb;
	$d = $wpdb->get_row($wpdb->prepare('SELECT d.*, c.name cn, c.phone cp, c.mobile cm FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE d.id = %d', $id), ARRAY_A);
	if (!$d) { return null; }
	$pen = $d['stage'] === cp_gcal_stages()[0];
	$date = (string) $d['event_d'];
	$s = ''; $e = '';
	if (!empty($d['start_at'])) {
		$st = new DateTime($d['start_at'] . ' UTC'); $st->setTimezone(wp_timezone());
		if ($st->format('Y-m-d') === $date && $st->format('H:i') !== '00:00') { $s = $st->format('H:i'); if ((int) $d['hours'] > 0) { $st->modify('+' . (int) $d['hours'] . ' hours'); $e = $st->format('Y-m-d') > $date ? '24:00' : $st->format('H:i'); } }
	}
	$L = array();
	if (function_exists('cp_el_link')) { $L[] = '<b>📋 KEZELŐI ADATLAP</b> – a rendezvény után itt töltsd ki:<br><b><a href="' . esc_url(cp_el_link($id)) . '">' . esc_html(cp_el_link($id)) . '</a></b><br>━━━━━━━━━━━━━━━━━━━━<br>'; }
	$row = function ($k, $v) use (&$L) { if (trim((string) $v) !== '') { $L[] = esc_html($k) . ': ' . esc_html(trim((string) $v)); } };
	$row('Helyszín', $d['venue']);
	$row('Időpont', $date !== '' ? str_replace('-', '.', $date) . '.' : '');
	$row('Aktivitás', $s !== '' ? $s . ($e !== '' ? '-' . $e : '') : '');
	$row('Kontakt személy', $d['cn']);
	$row('Kontakt telefonszám', $d['cm'] ?: $d['cp']);
	$row('Rendelkezésre állási idő', (int) $d['hours'] > 0 ? (int) $d['hours'] . ' óra' : '');
	$row('Fizetés', $d['pay_method']);
	$x = array();
	if (trim((string) $d['album']) !== '') { $x[] = ' - Album: ' . esc_html($d['album']); }
	if ((int) $d['guests'] > 0) { $x[] = ' - Létszám: ' . (int) $d['guests'] . ' fő'; }
	if ($x) { $L[] = '<br>Egyéb:<br>' . implode('<br>', $x); }
	$L[] = '------------------------------------------------------------------------<br>bruttó = nettó + ÁFA<br>Hosszabbítás nettó 30 000 Ft / óra + ÁFA<br>Készenlét nettó  10 000 Ft / óra + ÁFA<br>Áttelepítés nettó  10 000 Ft / alkalom +ÁFA';
	return array('title' => trim((string) $d['title']) !== '' ? trim((string) $d['title']) : '[' . $d['source'] . '] ' . trim((string) $d['cn']) . ' | ' . $date, /* v1.71.1: a naptárban a deal neve a cím */ 'date' => $date, 'start' => $s, 'end' => $e, 'loc' => (string) $d['venue'], 'pen' => $pen, 'desc' => implode('<br>', $L));
}

/* ------------------------------------------------------------ Naptár fül */
add_action('wp_ajax_nopriv_cp_gcal_ev', 'cp_gcal_ajax_ev');
add_action('wp_ajax_cp_gcal_ev', 'cp_gcal_ajax_ev');
function cp_gcal_ajax_ev() {
	cp_el_auth();
	cp_tab_need(array('gnaptar'));
	if (!cp_gcal_ok()) { wp_send_json_success(array('ok' => false, 'admin' => cp_panel_is_admin())); }
	$from = cp_el_req_date('from'); $to = cp_el_req_date('to');
	if ($from === '' || $to === '' || $to < $from || strtotime($to) - strtotime($from) > 62 * DAY_IN_SECONDS) { cp_el_err('Hibás időszak.'); }
	$ev = cp_gcal_events($from, $to, !empty($_POST['force']));
	if (is_wp_error($ev)) { cp_el_err($ev->get_error_message(), 502); }
	$deals = function_exists('cp_crm_t') ? cp_gcal_deals($from, $to) : array();
	list($byDate, $byId) = cp_gcal_index($deals);
	$seen = array(); $out = array();
	foreach ($ev as $e) {
		$id = $deals ? cp_gcal_match($e, $byDate, $byId) : 0;
		$d = $id ? $byId[$id] : null;
		if ($id) { $seen[$id] = 1; }
		$o = $e; unset($o['raw']);
		$o['desc'] = cp_gcal_desc_html($e['raw']);
		$o['pen'] = cp_gcal_pen($e['t']);
		$o['deal'] = $id; $o['stg'] = $d ? (string) $d['stage'] : ''; $o['dst'] = $d ? (string) $d['status'] : '';
		$o['own'] = $d && function_exists('cp_crm_user_name') ? cp_crm_user_name((int) $d['owner_id']) : '';
		$out[] = $o;
	}
	/* szerződéses szakaszban lévő, jövőbeli rendezvény, amelyhez nincs bejegyzés */
	$miss = array(); $today = wp_date('Y-m-d');
	foreach ($deals as $d) {
		if (isset($seen[(int) $d['id']]) || $d['event_d'] < $today || !in_array($d['stage'], cp_gcal_stages(), true)) { continue; }
		$miss[] = array('id' => (int) $d['id'], 'title' => (string) $d['title'], 'd' => (string) $d['event_d'], 'stg' => (string) $d['stage']);
	}
	wp_send_json_success(array('ok' => true, 'ev' => $out, 'miss' => $miss, 'today' => $today, 'at' => time()));
}

/* a panel leírás-javaslata egy dealhez (az esemény adatlapján, „Mit írna a panel”) */
add_action('wp_ajax_nopriv_cp_gcal_plan', 'cp_gcal_ajax_plan');
add_action('wp_ajax_cp_gcal_plan', 'cp_gcal_ajax_plan');
function cp_gcal_ajax_plan() {
	cp_el_auth();
	cp_tab_need(array('gnaptar'));
	$p = cp_gcal_plan((int) ($_POST['id'] ?? 0));
	if (!$p) { cp_el_err('Nincs ilyen deal.', 404); }
	wp_send_json_success($p);
}

/* ------------------------------------------------------------ átállás: árnyék-ellenőrzés (10 percenként) */
add_action('cp_crm_tick', 'cp_gcal_shadow', 31);
function cp_gcal_shadow() {
	if (!cp_gcal_ok() || !function_exists('cp_cut_log') || !cp_cut_db_ok() || !function_exists('cp_crm_t') || cp_cut_mode('calendar') !== 'shadow') { return; }
	global $wpdb;
	$H = cp_crm_t('history'); $L = cp_cut_t();
	$last = (int) get_option('cp_gcal_hlast', 0);
	if (!$last) { update_option('cp_gcal_hlast', (int) $wpdb->get_var("SELECT MAX(id) FROM $H"), false); return; }
	$st = cp_gcal_stages();
	$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT id, deal_id, new_val FROM $H WHERE id > %d AND field = 'stage' AND new_val IN (%s, %s) ORDER BY id LIMIT 200", $last, $st[0], $st[1]), ARRAY_A);
	foreach ($rows as $h) {
		$last = max($last, (int) $h['id']);
		$p = cp_gcal_plan((int) $h['deal_id']);
		if (!$p || $p['date'] === '' || $p['date'] < wp_date('Y-m-d')) { continue; }
		$kind = $h['new_val'] === $st[0] ? 'pen' : 'sign';
		cp_cut_log('calendar', 'cal:' . (int) $h['deal_id'] . ':' . $kind, array('title' => $p['title'], 'kind' => $kind, 'event_d' => $p['date']), (int) $h['deal_id']);
	}
	if ($rows) { update_option('cp_gcal_hlast', $last, false); } else { $mx = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM $H WHERE id > %d", $last)); if ($mx > $last) { update_option('cp_gcal_hlast', $mx, false); } }
	$cache = array();
	foreach ((array) $wpdb->get_results("SELECT * FROM $L WHERE feat = 'calendar' AND status = 'pending' ORDER BY id LIMIT 60", ARRAY_A) as $r) {
		$deal = (int) $r['deal_id']; $plan = json_decode((string) $r['plan'], true) ?: array(); $kind = (string) ($plan['kind'] ?? 'pen');
		$age = time() - strtotime($r['created_at'] . ' UTC');
		$d = $wpdb->get_row($wpdb->prepare('SELECT d.id, d.title, d.stage, d.status, d.event_d, d.owner_id, d.venue, d.hours, d.start_at, c.name cn FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE d.id = %d', $deal), ARRAY_A);
		$set = function ($status, $diff) use ($wpdb, $L, $r) { $wpdb->update($L, array('status' => $status, 'diff' => $diff, 'checked_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $r['id'])); };
		if (!$d || $d['status'] === 'Lost' || empty($d['event_d'])) { $set('match', 'A deal közben lezárult vagy nincs dátuma – nincs teendő.'); continue; }
		$day = $d['event_d'];
		if (!isset($cache[$day])) { $x = cp_gcal_events($day, $day); if (is_wp_error($x)) { return; } $cache[$day] = $x; }
		list($byDate, $byId) = cp_gcal_index(array($d));
		$ev = null;
		foreach ($cache[$day] as $e) { if (cp_gcal_match($e, $byDate, $byId) === $deal) { $ev = $e; break; } }
		if (!$ev) { if ($age > 6 * HOUR_IN_SECONDS) { $set('missing', 'Nincs bejegyzés a naptárban a rendezvény napján (' . $day . ').'); } continue; }
		$note = '';
		/* v1.71.1: a ceruzás jelölés módja egyeztetés alatt (a címekben nincs ✏️) – addig csak a bejegyzés meglétét nézi */
		$set('match', $note);
	}
}

/* ------------------------------------------------------------ WordPress-admin: kulcs és naptár */
add_action('admin_menu', function () { add_options_page('Crazy Google Naptár', 'Crazy Google Naptár', 'manage_options', 'cp-gcal', 'cp_gcal_admin_page'); });
function cp_gcal_admin_page() {
	if (!current_user_can('manage_options')) { return; }
	$msg = ''; $err = '';
	if (!empty($_POST['cp_gcal_do']) && check_admin_referer('cp_gcal_do')) {
		$do = sanitize_key($_POST['cp_gcal_do']);
		if ($do === 'save') {
			$o = cp_gcal_opts();
			$o['cal'] = sanitize_text_field(wp_unslash($_POST['cal'] ?? ''));
			$raw = '';
			if (!empty($_FILES['key']['tmp_name']) && is_uploaded_file($_FILES['key']['tmp_name']) && (int) $_FILES['key']['size'] < 20000) { $raw = (string) file_get_contents($_FILES['key']['tmp_name']); }
			elseif (trim((string) ($_POST['key_txt'] ?? '')) !== '') { $raw = (string) wp_unslash($_POST['key_txt']); }
			if ($raw !== '') {
				$j = json_decode($raw, true);
				if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['client_email']) || empty($j['private_key'])) { $err = 'Ez nem szolgáltatásfiók-kulcs (JSON). A Google Cloudban a szolgáltatásfióknál: Kulcsok → Kulcs hozzáadása → JSON.'; }
				elseif (!function_exists('openssl_pkey_get_private') || !openssl_pkey_get_private($j['private_key'])) { $err = 'A kulcsfájlban lévő privát kulcs nem olvasható.'; }
				else { update_option('cp_gcal_key', array('email' => sanitize_email($j['client_email']), 'pk' => (string) $j['private_key'], 'uri' => esc_url_raw((string) ($j['token_uri'] ?? ''))), false); $o['email'] = sanitize_email($j['client_email']); delete_transient('cp_gcal_tok'); }
			}
			update_option('cp_gcal', $o, false);
			if (!$err) { $msg = 'Elmentve.'; }
		} elseif ($do === 'del') {
			delete_option('cp_gcal_key'); delete_transient('cp_gcal_tok'); $o = cp_gcal_opts(); $o['email'] = ''; update_option('cp_gcal', $o, false); $msg = 'A kulcs törölve – a panel nem fér hozzá a naptárhoz.';
		} elseif ($do === 'test') {
			delete_transient('cp_gcal_tok');
			$r = cp_gcal_ok() ? cp_gcal_req('GET', '/events', array('maxResults' => 250, 'singleEvents' => 'true', 'timeMin' => gmdate(DATE_RFC3339), 'timeMax' => gmdate(DATE_RFC3339, time() + 30 * DAY_IN_SECONDS))) : new WP_Error('cp_gcal', 'Előbb töltsd fel a kulcsot és add meg a naptár azonosítóját.');
			if (is_wp_error($r)) { $err = $r->get_error_message(); }
			else { $msg = 'Rendben: a „' . sanitize_text_field((string) ($r['summary'] ?? '')) . '” naptár elérhető, a következő 30 napban ' . count((array) ($r['items'] ?? array())) . ' bejegyzés. Jogosultság: ' . esc_html((string) ($r['accessRole'] ?? '?')) . '.'; }
		}
	}
	$o = cp_gcal_opts(); $k = cp_gcal_key();
	echo '<div class="wrap"><h1>Crazy Google Naptár</h1>';
	if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
	if ($err) { echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>'; }
	echo '<p>A panel <b>szolgáltatásfiókkal</b> éri el az EventCrazy naptárat. Lépések:</p><ol>' .
		'<li><a href="https://console.cloud.google.com/" target="_blank" rel="noopener">console.cloud.google.com</a> → új projekt (pl. „Crazy panel”) → <b>API-k és szolgáltatások → Könyvtár</b> → „Google Calendar API” → <b>Engedélyezés</b>.</li>' .
		'<li><b>IAM és adminisztráció → Szolgáltatásfiókok → Szolgáltatásfiók létrehozása</b> (név: crazy-panel; szerepkör nem kell) → a fiók → <b>Kulcsok → Kulcs hozzáadása → Új kulcs → JSON</b>. A letöltött fájlt töltsd fel lent (máshova ne küldd el).</li>' .
		'<li>A Google Naptárban az EventCrazy naptár <b>Beállítások és megosztás → Megosztás adott személyekkel</b>: add hozzá a szolgáltatásfiók e-mail-címét (lent látszik feltöltés után), jogosultság: <b>Módosíthatja az eseményeket</b>.</li>' .
		'<li>Ugyanott, lejjebb: <b>Naptár integrálása → Naptárazonosító</b> – másold be lent, majd <b>Mentés</b> és <b>Kapcsolat ellenőrzése</b>.</li></ol>';
	echo '<form method="post" enctype="multipart/form-data">' . wp_nonce_field('cp_gcal_do', '_wpnonce', true, false) . '<table class="form-table">' .
		'<tr><th>Naptárazonosító</th><td><input type="text" name="cal" class="regular-text" value="' . esc_attr($o['cal']) . '" placeholder="…@group.calendar.google.com"></td></tr>' .
		'<tr><th>Szolgáltatásfiók</th><td>' . ($k ? '✅ kulcs feltöltve · <code>' . esc_html($k['email']) . '</code> <small>(ezzel a címmel oszd meg a naptárat)</small>' : '❌ nincs kulcs') . '</td></tr>' .
		'<tr><th>' . ($k ? 'Új kulcsfájl' : 'Kulcsfájl (JSON)') . '</th><td><input type="file" name="key" accept=".json,application/json"><p class="description">Vagy illeszd be a fájl tartalmát:</p><textarea name="key_txt" rows="3" class="large-text code" autocomplete="off"></textarea><p class="description">A kulcsot a panel titkosan, csak a szerveren tárolja; a böngészőbe soha nem kerül ki.</p></td></tr>' .
		'</table><p><button class="button button-primary" name="cp_gcal_do" value="save">Mentés</button> <button class="button" name="cp_gcal_do" value="test">Kapcsolat ellenőrzése</button>' .
		($k ? ' <button class="button" name="cp_gcal_do" value="del" onclick="return confirm(\'Törlöd a kulcsot? A panel nem fér hozzá a naptárhoz.\')">Kulcs törlése</button>' : '') . '</p></form></div>';
}
