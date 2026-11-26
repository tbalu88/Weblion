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
/* ceruzás bejegyzés: „[Ceruzás] ” a cím elején (a korábbi Salesmate-automatizmus jelölése; v1.72-től a panel teszi fel / veszi le) */
define('CP_GCAL_PEN', '[Ceruzás] ');
function cp_gcal_pen($t) { return mb_stripos((string) $t, '[ceruzás]') !== false || strpos((string) $t, '✏') !== false; }
function cp_gcal_unpen($t) { return trim(preg_replace('/^\s*(\[ceruzás\]|✏️?)\s*/iu', '', (string) $t)); }

/* nyers lista a tartományra (helyi dátumok, a „to” napot is beleértve); 90 mp-ig tárolva */
function cp_gcal_events($from, $to, $force = false) {
	$ck = 'cp_gcal_l_' . md5($from . '|' . $to . '|' . cp_gcal_opts()['cal'] . '|' . (int) get_option('cp_gcal_gen', 0));
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
				'link' => (string) ($e['htmlLink'] ?? ''), 'col' => (string) ($e['colorId'] ?? ''), 'g' => $g, 'upd' => (string) ($e['updated'] ?? ''));
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
	/* v2.9.71: a többnapos deal akkor is, ha csak a 2..n. napja esik a tartományba */
	$mor = function_exists('cp_mday_or_ids') ? cp_mday_or_ids($from, $to) : '';
	return (array) $wpdb->get_results($wpdb->prepare('SELECT d.id, d.title, d.stage, d.status, d.event_d, d.owner_id, d.venue, d.hours, d.start_at, c.name cn FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . " c ON c.id = d.contact_id WHERE d.deleted_at IS NULL AND d.status <> 'Lost' AND (d.event_d BETWEEN %s AND %s", $from, $to) . $mor . ')', ARRAY_A); /* v2.9.58: a törölt deal nem számít */
}
/* az esemény napi kulcsa (v2.9.71): 1) a leírásban lévő kezelői adatlap link (…?k=<deal>[:<nap>].<aláírás>, a kettőspont
   „%3A”-ként is), 2) a kontakt neve a címben, azon a napon – többnapos dealnél a nap kulcsa („<deal>:<nap>”, az 1. nap a deal) */
function cp_gcal_match_key($ev, $byDate, $byId) {
	if (preg_match('/[?&](?:amp;)?k=(\d{1,12})(?:(?:%3A|:)(\d{4}-\d{2}-\d{2}))?\.[a-f0-9]{14}/i', $ev['raw'], $m) && isset($byId[(int) $m[1]])) { return !empty($m[2]) ? (int) $m[1] . ':' . $m[2] : (string) (int) $m[1]; }
	$day = substr($ev['s'], 0, 10);
	$t = ' ' . cp_gcal_norm($ev['t']) . ' ';
	$best = ''; $bl = 0;
	foreach ($byDate[$day] ?? array() as $d) {
		$k = cp_gcal_norm($d['cn']);
		if (strlen($k) < 3) { continue; }
		$toks = array_filter(explode(' ', $k), function ($x) { return strlen($x) > 1; });
		$ok = strpos($t, ' ' . $k . ' ') !== false || (count($toks) >= 2 && !array_filter($toks, function ($x) use ($t) { return strpos($t, ' ' . $x . ' ') === false; }));
		if ($ok && strlen($k) > $bl) { $best = isset($d['key']) ? (string) $d['key'] : (string) (int) $d['id']; $bl = strlen($k); }
	}
	return $best;
}
/* az esemény dealje (a deal azonosítója, a napi kulcsból) */
function cp_gcal_match($ev, $byDate, $byId) {
	$k = cp_gcal_match_key($ev, $byDate, $byId);
	return $k === '' ? 0 : (int) $k;
}
/* v2.9.71: többnapos deal minden napja a saját dátumán szerepel ('key', 'day', 'dn', 'dof'); a harmadik elem: napi kulcs => sor */
function cp_gcal_index($deals) {
	$byDate = array(); $byId = array(); $byKey = array();
	$map = function_exists('cp_mday_many') ? cp_mday_many(array_map(function ($d) { return (int) $d['id']; }, (array) $deals)) : array();
	foreach ($deals as $d) {
		$byId[(int) $d['id']] = $d;
		if (isset($map[(int) $d['id']]) && !empty($d['event_d'])) {
			$days = cp_mday_align($map[(int) $d['id']], (string) $d['event_d']);
			foreach ($days as $k => $dr) {
				$x = $d + array('key' => cp_mday_key((int) $d['id'], $dr['day'], $k + 1), 'day' => $dr['day'], 'dn' => $k + 1, 'dof' => count($days));
				$byDate[$dr['day']][] = $x; $byKey[$x['key']] = $x;
			}
			continue;
		}
		$x = $d + array('key' => (string) (int) $d['id'], 'day' => (string) $d['event_d']);
		$byDate[$d['event_d']][] = $x; $byKey[$x['key']] = $x;
	}
	return array($byDate, $byId, $byKey);
}

/* ------------------------------------------------------------ amit a panel írna (átállás után) */
/* v2.9.71: $day – többnapos dealnél az adott nap bejegyzése (a nap kezdése, óraszáma, helyszíne, saját kezelői adatlap-linkje,
   a címben „ – 2/3. nap”); üresen az 1. nap. Ha a nap már nincs a deal napjai között: null */
function cp_gcal_plan($id, $day = '') {
	global $wpdb;
	$d = $wpdb->get_row($wpdb->prepare('SELECT d.*, c.name cn, c.phone cp, c.mobile cm FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE d.id = %d', $id), ARRAY_A);
	if (!$d) { return null; }
	$pen = cp_gcal_mark_on() && $d['stage'] === cp_gcal_stages()[0];
	$date = (string) $d['event_d'];
	$key = (string) (int) $id; $dn = 0; $dof = 0; $md = false;
	$days = function_exists('cp_mday_get') && $date !== '' ? cp_mday_align(cp_mday_get((int) $id), $date) : array();
	if ($days) {
		$md = true; $dof = count($days); $hit = null;
		foreach ($days as $k => $dr) { if ($dr['day'] === ($day !== '' ? $day : $date)) { $hit = $dr; $dn = $k + 1; } }
		if (!$hit) { return null; }
		$date = $hit['day']; $key = cp_mday_key((int) $id, $date, $dn);
		if ($hit['venue'] !== '') { $d['venue'] = $hit['venue']; }
		if ($hit['hours'] !== null) { $d['hours'] = $hit['hours']; }
		if ($hit['guests'] !== null) { $d['guests'] = $hit['guests']; }
		$d['_st'] = $hit['start_t'];
	} elseif ($day !== '' && $day !== $date) { return null; }
	$s = ''; $e = '';
	$hrs = (float) $d['hours'];
	if (!empty($d['_st'])) { $s = $d['_st']; }
	elseif (!empty($d['start_at'])) {
		$st = new DateTime($d['start_at'] . ' UTC'); $st->setTimezone(wp_timezone());
		/* többnapos dealnél a deal kezdési ideje minden napra érvényes (a napnak nincs saját időpontja) */
		if (($md || $st->format('Y-m-d') === $date) && $st->format('H:i') !== '00:00') { $s = $st->format('H:i'); }
	}
	/* v2.9.165: az ügyfél-adatlap (portál) kezdés/befejezés, ha a dealen nincs időpont; és a hasznos válaszok a leírásba */
	$pi = function_exists('cp_pinfo_rows') ? cp_pinfo_rows((int) $id) : null;
	if ($s === '' && $pi && $pi['start'] !== '') { $s = str_pad($pi['start'], 5, '0', STR_PAD_LEFT); if ($pi['end'] !== '') { $e = str_pad($pi['end'], 5, '0', STR_PAD_LEFT); $efix = true; } }
	/* ha a deal kezdése egyezik az adatlapéval, a befejezés is az adatlapról (pontosabb, mint a kezdés + rendelkezésre állás) */
	elseif ($s !== '' && $pi && $pi['end'] !== '' && str_pad($pi['start'], 5, '0', STR_PAD_LEFT) === $s) { $e = str_pad($pi['end'], 5, '0', STR_PAD_LEFT); $efix = true; }
	if ($s !== '' && $hrs > 0 && empty($efix)) { $st = new DateTime($date . ' ' . $s, wp_timezone()); $st->modify('+' . (int) round($hrs * 60) . ' minutes'); $e = $st->format('Y-m-d') > $date ? '24:00' : $st->format('H:i'); }
	$L = array();
	if (function_exists('cp_el_link')) { $L[] = '<b>📋 KEZELŐI ADATLAP</b> – a rendezvény után itt töltsd ki:<br><b><a href="' . esc_url(cp_el_link($key)) . '">' . esc_html(cp_el_link($key)) . '</a></b><br>━━━━━━━━━━━━━━━━━━━━<br>'; }
	$row = function ($k, $v) use (&$L) { if (trim((string) $v) !== '') { $L[] = esc_html($k) . ': ' . esc_html(trim((string) $v)); } };
	/* v2.9.324: Rendezvényinfó a kezelőnek (a deal adatlapjáról) – a megjegyzés legfelül, kiemelve */
	$di = function_exists('cp_dinfo_get') ? cp_dinfo_get((int) $id) : array();
	if (!empty($di['note'])) { $L[] = '<b>' . nl2br(esc_html($di['note'])) . '</b><br>'; }
	$row('Tematika', $di['theme'] ?? ''); $row('DressCode', $di['dress'] ?? ''); $row('Galéria név', $di['gal'] ?? '');
	if (!empty($di['theme']) || !empty($di['dress']) || !empty($di['gal'])) { $L[] = ''; }
	$row('Helyszín', $d['venue']);
	$row('Időpont', $date !== '' ? str_replace('-', '.', $date) . '.' : '');
	$row('Aktivitás', $s !== '' ? $s . ($e !== '' ? '-' . $e : '') : '');
	$row('Építés', $di['build'] ?? ''); $row('Bontás', $di['tear'] ?? '');
	$row('Kontakt személy', $d['cn']);
	$row('Kontakt telefonszám', $d['cm'] ?: $d['cp']);
	$row('Rendelkezésre állási idő', $hrs > 0 ? str_replace('.', ',', rtrim(rtrim(number_format($hrs, 1, '.', ''), '0'), '.')) . ' óra' : '');
	if ($md) { $row('Rendezvénynapok', function_exists('cp_mday_label') ? cp_mday_label(wp_list_pluck($days, 'day')) . ' – ez a ' . $dn . '. nap' : ''); }
	$row('Fizetés', $d['pay_method']);
	$x = array();
	if (trim((string) $d['album']) !== '') { $x[] = ' - Album: ' . esc_html($d['album']); }
	if (!empty($di['photo'])) { $x[] = ' - Fotó: ' . esc_html($di['photo']); }
	if ((int) $d['guests'] > 0) { $x[] = ' - Létszám: ' . (int) $d['guests'] . ' fő'; }
	if ($x) { $L[] = '<br>Egyéb:<br>' . implode('<br>', $x); }
	if ($pi && $pi['rows']) { $L[] = '<br><b>Az ügyfél adatlapjáról:</b><br>' . implode('<br>', array_map(function ($r) { return esc_html($r['l']) . ': ' . esc_html($r['t']); }, array_filter($pi['rows'], function ($r) { return $r['k'] !== 'schedule'; }))); }
	$L[] = '------------------------------------------------------------------------<br>bruttó = nettó + ÁFA<br>Hosszabbítás nettó 30 000 Ft / óra + ÁFA<br>Készenlét nettó  10 000 Ft / óra + ÁFA<br>Áttelepítés nettó  10 000 Ft / alkalom +ÁFA';
	return array('title' => ($pen ? CP_GCAL_PEN : '') . (trim((string) $d['title']) !== '' ? trim((string) $d['title']) : '[' . $d['source'] . '] ' . trim((string) $d['cn']) . ' | ' . $date) /* v1.71.1: a naptárban a deal neve a cím */ . ($md ? ' – ' . $dn . '/' . $dof . '. nap' : ''),
		'date' => $date, 'start' => $s, 'end' => $e, 'loc' => (string) $d['venue'], 'pen' => $pen, 'desc' => implode('<br>', $L), 'key' => $key, 'dn' => $dn, 'dof' => $dof);
}

/* v2.9.342: a panel által írt leírás frissítésekor a kollégák által kézzel beírt sorok nem veszhetnek el.
   A régi leírás azon sorai, amelyek nincsenek az új leírásban, és nem a panel saját sorai (azonos címkével, pl. „Helyszín:”),
   egy „Megjegyzések a naptárból” blokkba kerülnek a lábléc elé. A blokk a következő frissítéskor is megmarad. */
define('CP_GCAL_KEEP_H', 'Megjegyzések a naptárból (kézzel beírva):');
function cp_gcal_lines($html) {
	$t = preg_replace('#<\s*(br|/p|/div|/li|/h\d)\s*/?>#i', "\n", (string) $html);
	$t = html_entity_decode(wp_strip_all_tags($t, false), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$o = array(); foreach (preg_split('/\R/u', $t) as $l) { $l = trim(preg_replace('/[\x{00a0}\s]+/u', ' ', $l)); if ($l !== '') { $o[] = $l; } }
	return $o;
}
function cp_gcal_keep_manual($old, $new) {
	$ol = cp_gcal_lines($old); if (!$ol) { return $new; }
	$nl = cp_gcal_lines($new); $nset = array(); $nlab = array();
	foreach ($nl as $l) { $nset[mb_strtolower($l)] = 1; if (preg_match('/^-?\s*([^:]{2,40}):/u', $l, $m)) { $nlab[mb_strtolower(trim($m[1]))] = 1; } }
	$skip = '/^(-{5,}|━{3,}|egyéb:?$|az ügyfél adatlapjáról|📋|kezelői adatlap|https?:\/\/[^ ]*crazy-panel|bruttó = nettó|hosszabbítás nettó|készenlét nettó|áttelepítés nettó|' . preg_quote(mb_strtolower(CP_GCAL_KEEP_H), '/') . ')/iu';
	$keep = array();
	foreach ($ol as $l) {
		$lc = mb_strtolower($l);
		if (isset($nset[$lc]) || preg_match($skip, $lc)) { continue; }
		if (preg_match('/^-?\s*([^:]{2,40}):/u', $l, $m) && isset($nlab[mb_strtolower(trim($m[1]))])) { continue; }
		$keep[$lc] = $l;
	}
	if (!$keep) { return $new; }
	$blk = '<br><b>' . esc_html(CP_GCAL_KEEP_H) . '</b><br>' . implode('<br>', array_map('esc_html', array_values($keep))) . '<br>';
	$pos = strpos($new, '------------------------------------------------------------------------');
	return $pos === false ? $new . '<br><br>' . $blk : substr($new, 0, $pos) . $blk . substr($new, $pos);
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
	list($byDate, $byId, $byKey) = cp_gcal_index($deals);
	$seen = array(); $out = array();
	foreach ($ev as $e) {
		$key = $deals ? cp_gcal_match_key($e, $byDate, $byId) : ''; /* v2.9.71: napi kulcs */
		$id = (int) $key;
		$d = $id ? $byId[$id] : null;
		if ($key !== '') { $seen[$key] = 1; }
		if ($key !== '' && isset($byKey[$key]['dof'])) { $e['dn'] = $byKey[$key]['dn']; $e['dof'] = $byKey[$key]['dof']; }
		$o = $e; unset($o['raw']);
		$o['desc'] = cp_gcal_desc_html($e['raw']);
		$o['pen'] = cp_gcal_pen($e['t']);
		$o['deal'] = $id; $o['stg'] = $d ? (string) $d['stage'] : ''; $o['dst'] = $d ? (string) $d['status'] : '';
		$o['own'] = $d && function_exists('cp_crm_user_name') ? cp_crm_user_name((int) $d['owner_id']) : '';
		$out[] = $o;
	}
	/* szerződéses szakaszban lévő, jövőbeli rendezvény, amelyhez nincs bejegyzés */
	$miss = array(); $today = wp_date('Y-m-d');
	/* v2.9.71: napra – a többnapos deal hiányzó napja is (a tartományba eső napok) */
	foreach ($byKey as $k => $d) {
		if (isset($seen[$k]) || $d['day'] < $today || $d['day'] < $from || $d['day'] > $to || !in_array($d['stage'], cp_gcal_stages(), true)) { continue; }
		$miss[] = array('id' => (int) $d['id'], 'title' => (string) $d['title'] . (isset($d['dof']) ? ' – ' . $d['dn'] . '/' . $d['dof'] . '. nap' : ''), 'd' => (string) $d['day'], 'stg' => (string) $d['stage']);
	}
	usort($miss, function ($a, $b) { return strcmp($a['d'], $b['d']); });
	wp_send_json_success(array('ok' => true, 'ev' => $out, 'miss' => $miss, 'today' => $today, 'at' => time()));
}

/* a panel leírás-javaslata egy dealhez (az esemény adatlapján, „Mit írna a panel”) */
add_action('wp_ajax_nopriv_cp_gcal_plan', 'cp_gcal_ajax_plan');
add_action('wp_ajax_cp_gcal_plan', 'cp_gcal_ajax_plan');
function cp_gcal_ajax_plan() {
	cp_el_auth();
	cp_tab_need(array('gnaptar'));
	$p = cp_gcal_plan((int) ($_POST['id'] ?? 0), cp_el_req_date('day')); /* v2.9.71: többnapos: az adott nap */
	if (!$p) { cp_el_err('Nincs ilyen deal.', 404); }
	wp_send_json_success($p);
}

/* ------------------------------------------------------------ v2.9.323: eltérések a naptár és a panel között (csak olvas)
 * A naptárbejegyzéseket a megszokott módon párosítja a dealekhez (kezelői link vagy az aznapi ügyfélnév), majd összeveti:
 * dátum, kezdés, időtartam, helyszín, kezelő; jelzi az elveszett / törölt dealt, a párosítatlan bejegyzést és a bejegyzés
 * nélküli foglalt dealt. Semmit nem ír sem a naptárba, sem a panelbe. */
add_action('wp_ajax_nopriv_cp_gcal_diff', 'cp_gcal_ajax_diff');
add_action('wp_ajax_cp_gcal_diff', 'cp_gcal_ajax_diff');
/* v2.9.324: a (kézzel írt vagy a panel által írt) leírás „Címke: érték” sorai + a felső szabad szöveg (megjegyzés a kezelőnek) */
function cp_gcal_parse($raw) {
	$t = preg_replace('#<br\s*/?>|</p>|</div>|</li>#i', "\n", (string) $raw);
	$t = html_entity_decode(wp_strip_all_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$map = array('tematika' => 'theme', 'dresscode' => 'dress', 'dress code' => 'dress', 'galeria nev' => 'gal', 'galeria neve' => 'gal', 'helyszin' => 'venue', 'idopont' => 'date', 'aktivitas' => 'act',
		'epites' => 'build', 'bontas' => 'tear', 'kontakt szemely' => 'cn', 'kontakt telefonszam' => 'phone', 'kontakt telefon' => 'phone', 'rendelkezesre allasi ido' => 'hours', 'rendelkezesre allas' => 'hours',
		'hatter' => 'bg', 'fizetes' => 'pay', 'album' => 'album', 'foto' => 'photo', 'letszam' => 'guests');
	$out = array(); $note = array(); $started = false;
	foreach (preg_split('/\R/u', $t) as $ln) {
		$l = trim(preg_replace('/^\s*[-–•]\s*/u', '', $ln));
		if ($l === '') { continue; }
		if ($started && preg_match('/^-{5,}/', trim($ln))) { break; } /* lábléc (árak) */
		if (preg_match('/^([^:]{2,40}):\s*(.*)$/u', $l, $m)) {
			$k = cp_gcal_norm($m[1]);
			if (isset($map[$k])) { $v = trim($m[2]); if ($v !== '' && $v !== '-' && $v !== '–') { $out[$map[$k]] = mb_substr($v, 0, 300); } $started = true; continue; }
		}
		if (!$started && !preg_match('#https?://|kezel[őo]i adatlap|^[━─—_=\-\s]+$#iu', $l)) { $note[] = $l; }
	}
	if ($note) { $out['note'] = mb_substr(implode("\n", $note), 0, 2000); }
	return $out;
}
function cp_gcal_hm($dt) { return preg_match('/(\d{2}):(\d{2})/', (string) $dt, $m) ? $m[1] . ':' . $m[2] : ''; }
function cp_gcal_ajax_diff() {
	cp_el_auth();
	cp_tab_need(array('gnaptar'));
	if (!cp_gcal_ok()) { cp_el_err('A Google Naptár nincs bekötve.'); }
	@set_time_limit(90);
	$r = cp_gcal_diff(cp_el_req_date('to'), !empty($_POST['force']));
	if (is_wp_error($r)) { cp_el_err($r->get_error_message(), 502); }
	wp_send_json_success($r);
}
/* v2.9.324: átvétel a naptárból – jóváhagyott tételek: kezdés (a dealre, a Salesmate-be is) és a Rendezvényinfó mezői (csak a panelbe) */
add_action('wp_ajax_nopriv_cp_gcal_import', 'cp_gcal_ajax_import');
add_action('wp_ajax_cp_gcal_import', 'cp_gcal_ajax_import');
function cp_gcal_ajax_import() {
	cp_el_auth();
	cp_tab_need(array('gnaptar'));
	cp_tab_need(array('deals'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	@set_time_limit(120);
	$L = json_decode((string) wp_unslash($_POST['items'] ?? ''), true);
	if (!is_array($L) || !$L || count($L) > 200) { cp_el_err('Hibás kérés.'); }
	$F = function_exists('cp_dinfo_fields') ? cp_dinfo_fields() : array();
	$by = array(); foreach ($L as $x) { $id = (int) ($x['deal'] ?? 0); $f = (string) ($x['f'] ?? ''); $v = (string) ($x['v'] ?? ''); if ($id > 0 && (in_array($f, array('start', 'pay', 'guests', 'hours', 'value'), true) || isset($F[$f]))) { $by[$id][$f] = $v; } }
	$ok = 0; $err = array();
	foreach ($by as $id => $vals) {
		if (isset($vals['start'])) {
			$t = preg_match('/^(\d{1,2}):(\d{2})$/', $vals['start'], $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : '';
			if ($t === '') { $err[] = '#' . $id . ': hibás időpont'; }
			else { $r = cp_crm_save_data($id, array('start_at' => $t), array()); if (is_wp_error($r)) { $err[] = '#' . $id . ': ' . $r->get_error_message(); } else { $ok++; } }
			unset($vals['start']);
		}
		/* v2.9.341: Salesmate-mezők (a felhasználó jóváhagyásával, 2026-11-19): fizetési mód, létszám, rendelkezésre állás */
		$sm = array();
		if (isset($vals['pay'])) { $pv = mb_substr(trim(sanitize_text_field($vals['pay'])), 0, 40); if ($pv !== '') { $sm['pay_method'] = $pv; } unset($vals['pay']); }
		if (isset($vals['guests'])) { $gv = (int) $vals['guests']; if ($gv >= 1 && $gv <= 100000) { $sm['guests'] = $gv; } unset($vals['guests']); }
		if (isset($vals['hours'])) { $hv = (int) $vals['hours']; if ($hv >= 1 && $hv <= 48) { $sm['hours'] = $hv; } unset($vals['hours']); }
		if (isset($vals['value'])) { $vv = (int) $vals['value']; $cur = (float) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT value FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $id)); if ($vv >= 1000 && $vv <= 100000000 && $cur <= 0) { $sm['value'] = $vv; } elseif ($cur > 0) { $err[] = '#' . $id . ': a deal értéke közben kitöltődött – nem írtam felül'; } unset($vals['value']); }
		if ($sm) { $r = cp_crm_save_data($id, $sm, array()); if (is_wp_error($r)) { $err[] = '#' . $id . ': ' . $r->get_error_message(); } else { $ok += count($sm); } }
		if ($vals && function_exists('cp_dinfo_set')) { cp_dinfo_set($id, $vals); $ok += count($vals); }
	}
	if (function_exists('cp_audit_add')) { cp_audit_add('naptar', 'gcal_import', 'Átvétel a naptárból', '', $ok . ' tétel' . ($err ? ', ' . count($err) . ' hiba' : '')); }
	wp_send_json_success(array('ok' => $ok, 'err' => $err));
}
function cp_gcal_diff($to = '', $force = false) {
	global $wpdb;
	/* v2.9.339: a hónap elejétől (a felhasználó kérése: a teljes november is) */
	$from = wp_date('Y-m-01'); if ($to === '' || $to < $from) { $to = wp_date('Y') . '-12-31'; }
	/* v2.9.326: 10 perces gyorsítótár + zár – egyszerre csak egy számolás fusson (a nehéz kérés ne terhelje a szervert) */
	$ck = 'cp_gcal_diff_' . md5($from . $to . (defined('CP_PANEL_VER') ? CP_PANEL_VER : '')); /* v2.9.345: új panelváltozatnál friss számolás (a régi gyorsítótár nem rejti el az új átvehető tételeket) */
	if (!$force) { $c = get_transient($ck); if (is_array($c)) { return $c + array('cached' => 1); } }
	if (get_transient('cp_gcal_diff_lock')) { $c = get_transient($ck); if (is_array($c)) { return $c + array('cached' => 1); } return new WP_Error('busy', 'Az összevetés most készül – próbáld újra fél perc múlva.'); }
	set_transient('cp_gcal_diff_lock', 1, 120);
	$r = cp_gcal_diff_run($from, $to, $force);
	delete_transient('cp_gcal_diff_lock');
	if (!is_wp_error($r)) { set_transient($ck, $r, 10 * MINUTE_IN_SECONDS); }
	return $r;
}
function cp_gcal_diff_run($from, $to, $force) {
	global $wpdb;
	if (strtotime($to) - strtotime($from) > 200 * DAY_IN_SECONDS) { $to = wp_date('Y-m-d', strtotime($from) + 200 * DAY_IN_SECONDS); }
	$ev = cp_gcal_events($from, $to, $force);
	if (is_wp_error($ev)) { return $ev; }
	/* minden deal az időszakban (az elveszett / törölt is – hogy jelezhessük, ha a naptárban még szerepel) */
	$D = cp_crm_t('deals');
	$all = (array) $wpdb->get_results($wpdb->prepare("SELECT d.id, d.title, d.stage, d.status, d.event_d, d.owner_id, d.venue, d.hours, d.start_at, d.operator, d.deleted_at, d.pay_method, d.guests, d.value, d.album, d.background, c.name cn, c.mobile cm, c.phone cp FROM $D d LEFT JOIN " . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE d.event_d BETWEEN %s AND %s', wp_date('Y-m-d', strtotime($from) - 60 * DAY_IN_SECONDS), wp_date('Y-m-d', strtotime($to) + 60 * DAY_IN_SECONDS)), ARRAY_A);
	$live = array_values(array_filter($all, function ($d) use ($from, $to) { return empty($d['deleted_at']) && $d['status'] !== 'Lost' && $d['event_d'] >= $from && $d['event_d'] <= $to; }));
	list($byDate, $byId, $byKey) = cp_gcal_index($live);
	$dead = array(); foreach ($all as $d) { if (!empty($d['deleted_at']) || $d['status'] === 'Lost') { $dead[] = $d; } }
	list($dDate, $dId) = cp_gcal_index($dead);
	list($aDate, $aId) = cp_gcal_index(array_values(array_filter($all, function ($d) { return empty($d['deleted_at']) && $d['status'] !== 'Lost'; })));
	$kz = array(); if (function_exists('cp_kz_t')) { foreach ((array) $wpdb->get_col('SELECT name FROM ' . cp_kz_t() . " WHERE active = 1 AND kind IN ('kezelo','host')") as $n) { $n = trim((string) $n); if (mb_strlen($n) >= 4) { $kz[] = $n; } } }
	$booked = array_map('mb_strtolower', cp_panel_lines(cp_panel_opts()['booked_stages']));
	$norm = function ($x) { return cp_gcal_norm($x); };
	$names = function ($txt) use ($kz, $norm) { $t = ' ' . $norm($txt) . ' '; $o = array(); foreach ($kz as $n) { $k = $norm($n); $p = explode(' ', $k); $last = end($p); if (strpos($t, ' ' . $k . ' ') !== false || (count($p) > 1 && strpos($t, ' ' . $p[0] . ' ') !== false && strpos($t, ' ' . $last . ' ') !== false)) { $o[] = $n; } } return array_values(array_unique($o)); };
	$rows = array(); $seen = array();
	foreach ($ev as $e) {
		$day = substr($e['s'], 0, 10); if ($day < $from) { continue; }
		$key = cp_gcal_match_key($e, $byDate, $byId);
		$it = array();
		$row = array('day' => $day, 'ev' => cp_gcal_unpen($e['t']), 'link' => $e['link'], 'deal' => 0, 'dt' => '', 'stg' => '');
		if ($key === '') {
			/* más napon van-e ilyen nevű deal (dátumeltérés), vagy elveszett / törölt deal */
			$alt = '';
			foreach ($aDate as $dd => $L) { if ($dd === $day) { continue; } $tmp = cp_gcal_match_key(array('raw' => '', 's' => $dd . ' 00:00', 't' => $e['t']), array($dd => $L), $aId); if ($tmp !== '') { $alt = $tmp; $altDay = $dd; break; } }
			$dk = cp_gcal_match_key($e, $dDate, $dId);
			if ($dk !== '') { $d = $dId[(int) $dk]; $row['deal'] = (int) $d['id']; $row['dt'] = (string) $d['title']; $it[] = array('k' => 'lost', 'm' => !empty($d['deleted_at']) ? 'A deal törölve van, a naptárban még szerepel' : 'A deal elveszett, a naptárban még szerepel'); }
			elseif ($alt !== '') { $d = $aId[(int) $alt]; $row['deal'] = (int) $d['id']; $row['dt'] = (string) $d['title']; $row['stg'] = (string) $d['stage']; $it[] = array('k' => 'date', 'm' => 'Dátum eltér', 'cal' => $day, 'pan' => (string) $d['event_d']); }
			else {
				/* v2.9.340: belső bejegyzés (szabadság, OFF, megbeszélés…) – nem rendezvény, nem eltérés */
				if (preg_match('/\b(off|szabi|szabadság|szabadsag|beteg|megbeszélés|meeting|leltár|raktár)\b/iu', (string) $e['t']) && !preg_match('/^\s*\[/u', (string) $e['t'])) { continue; }
				$it[] = array('k' => 'nodeal', 'm' => 'Nincs hozzá deal a panelben (név vagy dátum alapján sem)');
			}
			$row['it'] = $it; $rows[] = $row; continue;
		}
		$seen[$key] = 1;
		$x = $byKey[$key] ?? $byId[(int) $key]; $d = $byId[(int) $key];
		$row['deal'] = (int) $d['id']; $row['dt'] = (string) $d['title']; $row['stg'] = (string) $d['stage'];
		$dn = (int) ($x['dn'] ?? 1);
		$P = cp_gcal_parse($e['raw']);
		$di = function_exists('cp_dinfo_get') ? cp_dinfo_get((int) $d['id']) : array();
		$add = function ($k, $m, $cal, $pan, $f = '', $v = null) use (&$it) { $it[] = array('k' => $k, 'm' => $m, 'cal' => (string) $cal, 'pan' => (string) ($pan === '' ? '–' : $pan)) + ($f !== '' ? array('f' => $f, 'v' => (string) ($v === null ? $cal : $v)) : array()); };
		$same = function ($a, $b) { $a = cp_gcal_norm($a); $b = cp_gcal_norm($b); return $a === $b || ($a !== '' && $b !== '' && (strpos($a, $b) !== false || strpos($b, $a) !== false)); };
		if ($dn === 1) {
			/* kezdés: a leírás „Aktivitás” sorából, különben a bejegyzés kezdéséből */
			$ch = ''; if (!empty($P['act']) && preg_match('/(\d{1,2})[:.](\d{2})/', $P['act'], $m)) { $ch = sprintf('%02d:%02d', $m[1], $m[2]); } elseif (empty($e['ad'])) { $ch = cp_gcal_hm($e['s']); }
			$ph = $d['start_at'] ? cp_gcal_hm(cp_crm_local((string) $d['start_at'])) : '';
			if ($ch !== '' && $ch !== '00:00' && $ch !== $ph) { $add('time', $ph === '' ? 'Kezdés csak a naptárban' : 'Kezdés eltér', $ch, $ph, 'start'); }
			if (!empty($P['hours']) && preg_match('/(\d+(?:[.,]\d+)?)/', $P['hours'], $m)) { $ch2 = (float) str_replace(',', '.', $m[1]); $phs = (float) $d['hours']; if ($ch2 > 0 && abs($ch2 - $phs) >= 0.25) { $add('hours', $phs > 0 ? 'Rendelkezésre állás eltér' : 'Rendelkezésre állás csak a naptárban', rtrim(rtrim(number_format($ch2, 1, ',', ''), '0'), ',') . ' óra', $phs > 0 ? rtrim(rtrim(number_format($phs, 1, ',', ''), '0'), ',') . ' óra' : '', abs($ch2 - round($ch2)) < 0.01 ? 'hours' : '', (string) (int) round($ch2)); } } /* v2.9.341: egész óraszám átvehető (Salesmate) */
		}
		$cl = trim((string) ($P['venue'] ?? '')) !== '' ? trim((string) $P['venue']) : trim((string) $e['loc']); $pl = trim((string) $d['venue']);
		if ($cl !== '' && $pl === '') { $add('loc', 'Helyszín csak a naptárban', $cl, ''); }
		elseif ($cl !== '' && $pl !== '') { $a = $norm($cl); $b = $norm($pl); $ta = array_filter(explode(' ', $a), function ($w) { return strlen($w) > 3; }); $tb = array_filter(explode(' ', $b), function ($w) { return strlen($w) > 3; }); if ($ta && $tb && !array_intersect($ta, $tb) && strpos($a, $b) === false && strpos($b, $a) === false) { $add('loc', 'Helyszín eltér', $cl, $pl); } }
		if (!empty($P['cn']) && trim((string) $d['cn']) !== '' && !$same($P['cn'], $d['cn'])) { $add('contact', 'Kontakt személy eltér', $P['cn'], $d['cn']); }
		if (!empty($P['phone'])) { $cp = preg_replace('/\D/', '', (string) ($d['cm'] ?: $d['cp'])); $cc = preg_replace('/\D/', '', $P['phone']); if ($cc !== '' && substr($cc, -8) !== substr($cp, -8)) { $add('contact', $cp === '' ? 'Telefonszám csak a naptárban' : 'Telefonszám eltér', $P['phone'], (string) ($d['cm'] ?: $d['cp'])); } }
		/* v2.9.341: a fizetési mód átvehető (a Salesmate „Választott fizetési mód” mezőjébe, legfeljebb 40 karakter); az összeg csak eltérésként jelenik meg */
		if (!empty($P['pay'])) {
			$pm = trim(preg_replace('/[\x{00a0}\s]+/u', ' ', (string) $P['pay']));
			$pm = trim(preg_split('/\s[-–—]\s|\s[-–—]$/u', $pm)[0]); /* a „ - ” utáni rész (összeg, megjegyzés) nem a fizetési mód */
			$pm = trim(preg_replace('/\s*[:,(]\s*(\S*\s*)?(bruttó|nettó|brutto|netto)?\s*\d.*$/iu', '', $pm));
			if (mb_strlen($pm) > 40 || (mb_strpos($pm, '(') !== false && mb_strpos($pm, ')') === false)) { $pm = trim(preg_split('/\s*[(,]/u', $pm)[0]); }
			if ($pm === '') { $pm = trim(preg_replace('/\d.*$/u', '', (string) $P['pay'])); }
			$pm = rtrim(mb_substr($pm, 0, 40), ' -–,.');
			if ($pm !== '' && !$same($pm, (string) $d['pay_method'])) { $add('other', trim((string) $d['pay_method']) === '' ? 'Fizetés csak a naptárban' : 'Fizetés eltér', $P['pay'], (string) $d['pay_method'], 'pay', $pm); }
			if (preg_match('/(\d{1,3}(?:[ .\x{00a0}]\d{3})+|\d{4,})\s*(?:Ft|HUF|forint)/iu', (string) $P['pay'], $am)) {
				$amt = (int) preg_replace('/\D/', '', $am[1]); $val = (float) ($d['value'] ?? 0);
				/* v2.9.343: üres deal-értéknél átvehető (a felhasználó döntése): alanyi adómentes (AAM) vagy nettó összeg úgy, ahogy van; ÁFA-s bruttónál csak jelzés */
				$aam = (bool) preg_match('/\bAAM\b|alanyi/iu', (string) $P['pay']); $gross = (bool) preg_match('/brutt/iu', (string) $P['pay']);
				$canv = $val <= 0 && ($aam || !$gross);
				if ($amt > 0 && ($val <= 0 || (abs($amt - $val) > 2 && abs($amt - $val * 1.27) > 2))) { $add('other', $val > 0 ? 'Összeg eltér' : ($canv ? 'Összeg csak a naptárban' : 'Összeg csak a naptárban (bruttó, ÁFA-s – kézzel add meg a nettót)'), number_format($amt, 0, ',', ' ') . ' Ft', $val > 0 ? number_format($val, 0, ',', ' ') . ' Ft (deal értéke' . ' – nettó és bruttó szerint is eltér)' : '', $canv ? 'value' : '', (string) $amt); }
			}
		}
		if (!empty($P['guests']) && preg_match('/(\d+)/', $P['guests'], $m) && (int) $m[1] !== (int) $d['guests']) { $add('other', (int) $d['guests'] > 0 ? 'Létszám eltér' : 'Létszám csak a naptárban', (int) $m[1] . ' fő', (int) $d['guests'] > 0 ? (int) $d['guests'] . ' fő' : '', (int) $m[1] > 0 && (int) $m[1] <= 100000 ? 'guests' : '', (string) (int) $m[1]); }
		/* v2.9.325: azonos jelentésű értékek („Nincs” = „Nem kell”; „??” = „Még nem tudom”) nem számítanak eltérésnek */
		$no = function ($v) { return (bool) preg_match('/^(nincs|nem|nem kell|-|–)$/u', cp_gcal_norm($v) === '' ? '-' : mb_strtolower(trim((string) $v))); };
		$unk = function ($v) { $t = trim((string) $v); return $t === '' || (bool) preg_match('/^\?+$/', $t) || cp_gcal_norm($t) === 'meg nem tudom'; };
		if (!empty($P['album']) && !$same($P['album'], (string) $d['album']) && !($no($P['album']) && $no((string) $d['album']))) { $add('other', trim((string) $d['album']) === '' ? 'Album csak a naptárban' : 'Album eltér', $P['album'], (string) $d['album']); }
		if (!empty($P['bg']) && !$unk($P['bg']) && !$same($P['bg'], (string) $d['background'])) { $add('other', trim((string) $d['background']) === '' ? 'Háttér csak a naptárban' : 'Háttér eltér', $P['bg'], (string) $d['background']); }
		/* Rendezvényinfó a kezelőnek (új mezők) – átvehető */
		$FL = function_exists('cp_dinfo_fields') ? cp_dinfo_fields() : array();
		foreach ($FL as $fk => $fx) { if (!empty($P[$fk]) && trim((string) ($di[$fk] ?? '')) !== trim((string) $P[$fk])) { $add('info', $fx[0] . (trim((string) ($di[$fk] ?? '')) === '' ? ' – csak a naptárban' : ' – eltér'), $P[$fk], (string) ($di[$fk] ?? ''), $fk); } }
		$cn = $names($e['t'] . ' ' . wp_strip_all_tags($e['raw'])); $pn = $names((string) $d['operator']);
		if ($cn && !$pn) { $add('op', 'Kezelő csak a naptárban', implode(', ', $cn), trim((string) $d['operator'])); }
		elseif ($cn && $pn && array_diff($cn, $pn)) { $add('op', 'Kezelő eltér', implode(', ', $cn), (string) $d['operator']); }
		if ($it) { $row['it'] = $it; $rows[] = $row; }
	}
	/* foglalt (szerződéses) deal naptárbejegyzés nélkül */
	foreach ($byKey as $k => $x) {
		if (isset($seen[$k]) || $x['day'] < $from || $x['day'] > $to) { continue; }
		if (!in_array(mb_strtolower((string) $x['stage']), $booked, true) && $x['status'] !== 'Won') { continue; }
		$rows[] = array('day' => $x['day'], 'ev' => '', 'link' => '', 'deal' => (int) $x['id'], 'dt' => (string) $x['title'], 'stg' => (string) $x['stage'], 'it' => array(array('k' => 'noev', 'm' => 'Nincs naptárbejegyzés')));
	}
	usort($rows, function ($a, $b) { return strcmp($a['day'], $b['day']); });
	return array('from' => $from, 'to' => $to, 'ev' => count($ev), 'rows' => $rows);
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
		/* v1.71.1: a ceruzás jelölés módja egyeztetés alatt (a címekben nincs ✏️) – addig csak a bejegyzés meglétét nézi */
		$set('match', '');
	}
}

/* ------------------------------------------------------------ v1.72: ceruzás jelölés a panelből (a Salesmate-automatizmus leállt)
 * „Szerződés kiküldve” → „[Ceruzás] ” a cím elé; továbblépéskor le; ha 3 óra után sincs bejegyzés, a panel létrehozza.
 * Elvesztett deal bejegyzéséhez nem nyúl. Minden írás naplózva (WordPress → Beállítások → Crazy Google Naptár). */
function cp_gcal_pen_on() { return !empty(cp_gcal_opts()['pen']); }
/* v2.9.47: a „[Ceruzás]” jelölés külön kapcsoló (alapból ki – a kollégák nem használják); a bejegyzések létrehozása / frissítése ettől független */
function cp_gcal_mark_on() { return !empty(cp_gcal_opts()['mark']); }
function cp_gcal_wlog($a, $deal, $t) {
	$l = get_option('cp_gcal_wlog', array()); if (!is_array($l)) { $l = array(); }
	array_unshift($l, array('at' => time(), 'a' => $a, 'deal' => (int) $deal, 't' => mb_substr((string) $t, 0, 160)));
	update_option('cp_gcal_wlog', array_slice($l, 0, 150), false);
}
/* v2.9.364: kézi módosítás felismerése. A panel minden saját írása után megjegyzi a bejegyzés tartalmának ujjlenyomatát
   (leírás szövege, kezdés, vége, helyszín – a cím és a vendégek visszaigazolása nem számít). Ha a naptárban a tartalom ettől eltér,
   azt valaki kézzel módosította: onnantól a panel nem írja felül (a kollégák változtatása marad).
   A még meg nem jegyzett bejegyzéseknél az első szinkron a mostani állapotot menti kiindulásnak. */
function cp_gcal_sig($desc, $s, $e, $loc) { return md5(implode("\n", cp_gcal_lines($desc)) . '|' . $s . '|' . $e . '|' . trim((string) $loc)); }
function cp_gcal_sig_ev($e) { return cp_gcal_sig($e['raw'] ?? '', $e['s'] ?? '', $e['e'] ?? '', $e['loc'] ?? ''); }
function cp_gcal_sig_api($r) {
	if (!is_array($r) || empty($r['id'])) { return ''; }
	$ad = empty($r['start']['dateTime']); $s = cp_gcal_local($r['start'] ?? array()); $en = cp_gcal_local($r['end'] ?? array());
	if ($ad && $en !== '') { $en = gmdate('Y-m-d', strtotime($en . ' 00:00:00 UTC') - DAY_IN_SECONDS); }
	return cp_gcal_sig((string) ($r['description'] ?? ''), $s, $en, (string) ($r['location'] ?? ''));
}
/* v2.9.381: bejegyzésenként külön opcióban (cp_gcw_<md5>) – a közös tömb egyidejű írásnál (szinkron + gomb) elveszíthetett tételeket,
   és a hiányzó alap miatt a panel a kézzel módosított bejegyzést is felülírta. A régi közös tömb olvasható marad (átmenet). */
function cp_gcal_wts_get() { $w = get_option('cp_gcal_wts', array()); return is_array($w) ? $w : array(); }
function cp_gcal_wts_key($eid) { return 'cp_gcw_' . md5((string) $eid); }
function cp_gcal_wts_one($eid) { $v = get_option(cp_gcal_wts_key($eid), null); if (is_string($v) && $v !== '') { return $v; } $w = cp_gcal_wts_get(); return isset($w[$eid]) ? (string) $w[$eid] : null; }
function cp_gcal_wts_put($eid, $sig) { if ($eid === '' || $sig === '') { return; } update_option(cp_gcal_wts_key($eid), $sig, false); }
function cp_gcal_wts_set($eid, $r) { cp_gcal_wts_put((string) $eid, is_array($r) ? cp_gcal_sig_api($r) : ''); }
/* ha nincs megjegyzett alap: most mentjük, de ebben a körben kézi módosításnak tekintjük (nem írjuk felül – a következő körtől összevethető) */
function cp_gcal_manual($e) { $sg = cp_gcal_sig_ev($e); $w = cp_gcal_wts_one($e['id']); if ($w === null) { cp_gcal_wts_put($e['id'], $sg); return true; } return $w !== $sg; }
/* v2.9.382: minden panel-felülírás előtt mentés a bejegyzés addigi tartalmáról (cím, leírás, idő, helyszín; bejegyzésenként az utolsó 5),
   hogy egy téves felülírás visszaállítható legyen (a Google Naptár nem őriz korábbi változatot) */
function cp_gcal_snap($e, $why = '') {
	if (empty($e['id'])) { return; }
	$k = 'cp_gcb_' . md5((string) $e['id']); $l = get_option($k, array()); if (!is_array($l)) { $l = array(); }
	array_unshift($l, array('at' => time(), 'why' => $why, 't' => (string) ($e['t'] ?? ''), 'raw' => (string) ($e['raw'] ?? ''), 's' => (string) ($e['s'] ?? ''), 'e' => (string) ($e['e'] ?? ''), 'ad' => !empty($e['ad']) ? 1 : 0, 'loc' => (string) ($e['loc'] ?? '')));
	update_option($k, array_slice($l, 0, 5), false);
}
function cp_gcal_body($p) {
	$tz = wp_timezone();
	$b = array('summary' => $p['title'], 'location' => $p['loc'], 'description' => $p['desc']);
	if ($p['start'] !== '') {
		$s = new DateTime($p['date'] . ' ' . $p['start'], $tz);
		$e = clone $s; if ($p['end'] === '24:00') { $e = new DateTime($p['date'] . ' 00:00', $tz); $e->modify('+1 day'); } elseif ($p['end'] !== '') { $e = new DateTime($p['date'] . ' ' . $p['end'], $tz); if ($e <= $s) { $e->modify('+1 day'); } } else { $e->modify('+4 hours'); }
		$b['start'] = array('dateTime' => $s->format(DATE_RFC3339), 'timeZone' => $tz->getName()); $b['end'] = array('dateTime' => $e->format(DATE_RFC3339), 'timeZone' => $tz->getName());
	} else {
		$b['start'] = array('date' => $p['date']); $b['end'] = array('date' => gmdate('Y-m-d', strtotime($p['date'] . ' 00:00:00 UTC') + DAY_IN_SECONDS));
	}
	return $b;
}
/* $dry: csak kiszámolja, mit tenne (darabszámok + deal-azonosítók) */
function cp_gcal_pen_sync($dry = false) {
	if (!cp_gcal_ok() || !function_exists('cp_crm_t') || (!$dry && !cp_gcal_pen_on())) { return null; }
	if (!$dry && get_transient('cp_gcal_pen_lock')) { return null; }
	if (!$dry) { set_transient('cp_gcal_pen_lock', 1, 300); }
	global $wpdb;
	$from = wp_date('Y-m-d'); $to = wp_date('Y-m-d', time() + 400 * DAY_IN_SECONDS);
	$ev = cp_gcal_events($from, $to, true);
	if (is_wp_error($ev)) { if (!$dry) { delete_transient('cp_gcal_pen_lock'); } return $ev; }
	$deals = cp_gcal_deals($from, $to); list($bd, $bi, $bk) = cp_gcal_index($deals);
	$PEN = cp_gcal_stages()[0];
	$later = array('Szerződés aláírva', 'Adatlap kiküldve', 'Adatlap kitöltve', 'Rendezvény felkészül', 'Pénzre várunk', 'Rendezvény lement (G&R)');
	$out = array('add' => array(), 'remove' => array(), 'create' => array(), 'wait' => array(), 'err' => array());
	$has = array();
	foreach ($ev as $e) {
		$key = cp_gcal_match_key($e, $bd, $bi); if ($key === '') { continue; } /* v2.9.71: napi kulcs („<deal>:<nap>”, az 1. nap a deal) */
		$id = (int) $key; $d = $bi[$id]; $has[$key] = 1;
		if ($d['status'] !== 'Open' || !cp_gcal_mark_on()) { continue; }
		$pen = cp_gcal_pen($e['t']); $new = null; $k = '';
		if ($d['stage'] === $PEN && !$pen) { $new = CP_GCAL_PEN . $e['t']; $k = 'add'; }
		elseif ($pen && in_array($d['stage'], $later, true)) { $new = cp_gcal_unpen($e['t']); $k = 'remove'; }
		if ($new === null) { continue; }
		$out[$k][] = $id;
		if ($dry) { continue; }
		$r = cp_gcal_req('PATCH', '/events/' . rawurlencode($e['id']), array(), array('summary' => $new));
		if (is_wp_error($r)) { $out['err'][] = $r->get_error_message(); break; }
		if (!cp_gcal_manual($e)) { cp_gcal_wts_set($e['id'], $r); }
		cp_gcal_wlog($k === 'add' ? 'ceruzás fel' : 'ceruzás le', $id, $new);
	}
	$made = get_option('cp_gcal_made', array()); if (!is_array($made)) { $made = array(); }
	foreach ($deals as $d) {
		$id = (int) $d['id'];
		/* v2.9.164: a szerződés utáni szakaszokban is létrejön (eddig csak „Szerződés kiküldve” + 3 óra után, így a gyorsan
		   továbblépett vagy azt kihagyó deal soha nem került naptárba); a 3 órás várakozás csak a „Szerződés kiküldve” szakaszra vonatkozik */
		$okC = array($PEN, 'Szerződés aláírva', 'Rendezvény felkészül', 'Rendezvény lement (G&R)', 'Pénzre várunk');
		if (isset($has[$id]) || !in_array($d['stage'], $okC, true) || $d['status'] !== 'Open' || $d['event_d'] < $from || isset($made[$id])) { continue; }
		/* v2.9.168: a „Szerződés kiküldve” utáni 3 órás várakozás megszűnt (a felhasználó döntése, 2026-11-11) – azonnal a naptárba */
		$out['create'][] = $id;
		if ($dry || $out['err']) { continue; }
		$p = cp_gcal_plan($id); if (!$p || $p['date'] === '') { continue; }
		$r = cp_gcal_req('POST', '/events', array(), cp_gcal_body($p));
		if (is_wp_error($r)) { $out['err'][] = $r->get_error_message(); break; }
		$made[$id] = (string) ($r['id'] ?? '1'); update_option('cp_gcal_made', $made, false);
		cp_gcal_wts_set((string) ($r['id'] ?? ''), $r);
		cp_gcal_wlog('bejegyzés létrehozva', $id, $p['title']);
	}
	/* v2.9.71: többnapos rendezvény 2..n. napja – külön bejegyzés napi kulccsal (cp_gcal_made['<deal>:<nap>']), ha a deal már
	   szerződéses vagy későbbi szakaszban van, és a napnak még nincs bejegyzése (a kézzel felvettet is felismeri a napon) */
	$okSt = array_merge(array($PEN), $later);
	foreach ($bk as $key => $x) {
		if (strpos((string) $key, ':') === false || isset($has[$key]) || isset($made[$key]) || $x['day'] < $from || $x['status'] === 'Lost' || !in_array($x['stage'], $okSt, true)) { continue; }
		$out['create'][] = $key;
		if ($dry || $out['err']) { continue; }
		$p = cp_gcal_plan((int) $key, $x['day']); if (!$p) { continue; }
		$r = cp_gcal_req('POST', '/events', array(), cp_gcal_body($p));
		if (is_wp_error($r)) { $out['err'][] = $r->get_error_message(); break; }
		$made[$key] = (string) ($r['id'] ?? '1'); update_option('cp_gcal_made', $made, false);
		cp_gcal_wts_set((string) ($r['id'] ?? ''), $r);
		cp_gcal_wlog('bejegyzés létrehozva (' . $x['dn'] . '/' . $x['dof'] . '. nap)', (int) $key, $p['title']);
	}
	/* v1.93: a panel által létrehozott bejegyzések követik a deal változását (dátum, kezdés, óraszám, helyszín, leírás);
	   elvesztett dealnél „[ELVESZETT]” jelölés kerül a cím elé (nem töröljük). A kézzel felvett bejegyzésekhez nem nyúlunk. */
	$out['update'] = array(); $out['lost'] = array(); $out['manual'] = array();
	if ($made && !$out['err']) {
		$byEv = array(); foreach ($ev as $e) { $byEv[$e['id']] = $e; }
		$ids = array_values(array_unique(array_map('intval', array_keys($made)))); /* v2.9.71: a napi kulcsból a deal */
		$st = array(); foreach ((array) $wpdb->get_results('SELECT id, status FROM ' . cp_crm_t('deals') . ' WHERE id IN (' . implode(',', $ids ?: array(0)) . ')', ARRAY_A) as $r) { $st[(int) $r['id']] = (string) $r['status']; }
		$out['delete'] = array();
		foreach ($made as $mk => $eid) {
			$did = (int) $mk; $mday = strpos((string) $mk, ':') !== false ? substr((string) $mk, strpos((string) $mk, ':') + 1) : '';
			/* v2.9.71: a többnapos deal törölt napja (vagy a deal újra egynapos): a panel által létrehozott napi bejegyzés törlődik */
			if ($mday !== '' && ($st[$did] ?? '') !== 'Lost' && !cp_gcal_plan($did, $mday)) {
				$out['delete'][] = (string) $mk; if ($dry) { continue; }
				$r = cp_gcal_req('DELETE', '/events/' . rawurlencode($eid));
				if (is_wp_error($r) && !isset($byEv[$eid])) { $r = null; } /* már nincs a naptárban */
				if (is_wp_error($r)) { $out['err'][] = $r->get_error_message(); break; }
				unset($made[$mk]); update_option('cp_gcal_made', $made, false);
				cp_gcal_wlog('napi bejegyzés törölve (a nap kikerült)', $did, $mday); continue;
			}
			if (!isset($byEv[$eid])) { continue; }
			$e = $byEv[$eid];
			if (($st[$did] ?? '') === 'Lost') {
				if (mb_stripos($e['t'], '[ELVESZETT]') === 0) { continue; }
				$out['lost'][] = $did; if ($dry) { continue; }
				$nt = '[ELVESZETT] ' . cp_gcal_unpen($e['t']);
				$r = cp_gcal_req('PATCH', '/events/' . rawurlencode($eid), array(), array('summary' => $nt));
				if (is_wp_error($r)) { $out['err'][] = $r->get_error_message(); break; }
				cp_gcal_wts_set($eid, $r);
				cp_gcal_wlog('elvesztett deal jelölve', $did, $nt); continue;
			}
			$p = cp_gcal_plan($did, $mday); if (!$p || $p['date'] === '') { continue; }
			$chg = substr($e['s'], 0, 10) !== $p['date'] || ($p['start'] !== '' && ($e['ad'] || substr($e['s'], 11, 5) !== $p['start'])) || ($p['start'] === '' && !$e['ad'])
				|| ($p['end'] !== '' && $p['end'] !== '24:00' && !$e['ad'] && substr($e['e'], 11, 5) !== $p['end']) || trim($e['loc']) !== trim((string) $p['loc']);
			$man = cp_gcal_manual($e);
			if (!$chg) { continue; }
			if ($man) {
				$out['manual'][] = $did;
				$ml = get_option('cp_gcal_manlog', array()); if (!is_array($ml)) { $ml = array(); }
				$sig = cp_gcal_sig_ev($e) . '|' . $p['date'] . $p['start'] . $p['end'] . $p['loc'];
				if (($ml[$eid] ?? '') !== $sig && !$dry) { $ml[$eid] = $sig; update_option('cp_gcal_manlog', $ml, false); cp_gcal_wlog('nem írtam felül – a naptárban kézzel módosították (a deal eltér)', $did, $e['t']); }
				continue;
			}
			$out['update'][] = $did; if ($dry) { continue; }
			cp_gcal_snap($e, 'deal változott');
			$b = cp_gcal_body($p); $b['summary'] = $e['t'];  /* a cím (ceruzás jelöléssel) marad – azt a fenti rész kezeli */
			$b['description'] = cp_gcal_keep_manual($e['raw'], $p['desc']); /* v2.9.342: a kézzel beírt sorok megmaradnak */
			$r = cp_gcal_req('PATCH', '/events/' . rawurlencode($eid), array(), $b);
			if (is_wp_error($r)) { $out['err'][] = $r->get_error_message(); break; }
			cp_gcal_wts_set($eid, $r);
			cp_gcal_wlog('bejegyzés frissítve (a deal változott)', $did, $e['t']);
		}
	}
	if (!$dry) {
		if ($out['add'] || $out['remove'] || $out['create'] || $out['update'] || $out['lost'] || !empty($out['delete'])) { update_option('cp_gcal_gen', (int) get_option('cp_gcal_gen', 0) + 1, false); }
		if ($out['err']) { update_option('cp_gcal_werr', array('at' => time(), 'msg' => $out['err'][0]), false); } else { delete_option('cp_gcal_werr'); }
		delete_transient('cp_gcal_pen_lock');
	}
	return $out;
}
add_action('cp_crm_tick', function () { cp_gcal_pen_sync(false); }, 32);
/* v2.9.168: szakaszváltás / deal-mentés után a naptár-szinkron azonnal (pár mp múlva) lefut, nem kell kivárni az 5 perces kört */
add_action('cp_gcal_now', function () { cp_gcal_pen_sync(false); });
foreach (array('wp_ajax_cp_crm_move', 'wp_ajax_cp_crm_save') as $cp_gh) {
	add_action($cp_gh, function () { if (function_exists('cp_gcal_ok') && cp_gcal_ok() && !wp_next_scheduled('cp_gcal_now')) { wp_schedule_single_event(time() + 15, 'cp_gcal_now'); } }, 1);
}
unset($cp_gh);

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
			$o['pen'] = empty($_POST['pen']) ? 0 : 1;
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
		'<tr><th>Ceruzás jelölés</th><td><label><input type="checkbox" name="pen" value="1"' . checked(cp_gcal_pen_on(), true, false) . '> a panel teszi fel a „[Ceruzás]” feliratot a „Szerződés kiküldve” dealek bejegyzésére, továbblépéskor leveszi, és ha 3 óra után sincs bejegyzés, létrehozza</label></td></tr>' .
		'<tr><th>Szolgáltatásfiók</th><td>' . ($k ? '✅ kulcs feltöltve · <code>' . esc_html($k['email']) . '</code> <small>(ezzel a címmel oszd meg a naptárat)</small>' : '❌ nincs kulcs') . '</td></tr>' .
		'<tr><th>' . ($k ? 'Új kulcsfájl' : 'Kulcsfájl (JSON)') . '</th><td><input type="file" name="key" accept=".json,application/json"><p class="description">Vagy illeszd be a fájl tartalmát:</p><textarea name="key_txt" rows="3" class="large-text code" autocomplete="off"></textarea><p class="description">A kulcsot a panel titkosan, csak a szerveren tárolja; a böngészőbe soha nem kerül ki.</p></td></tr>' .
		'</table><p><button class="button button-primary" name="cp_gcal_do" value="save">Mentés</button> <button class="button" name="cp_gcal_do" value="test">Kapcsolat ellenőrzése</button>' .
		($k ? ' <button class="button" name="cp_gcal_do" value="del" onclick="return confirm(\'Törlöd a kulcsot? A panel nem fér hozzá a naptárhoz.\')">Kulcs törlése</button>' : '') . '</p></form>';
	$we = get_option('cp_gcal_werr'); if (is_array($we)) { echo '<div class="notice notice-error"><p>Utolsó írási hiba (' . esc_html(wp_date('Y.m.d. H:i', (int) $we['at'])) . '): ' . esc_html($we['msg']) . '</p></div>'; }
	$wl = get_option('cp_gcal_wlog', array());
	echo '<h2>A panel írásai a naptárban</h2>' . (is_array($wl) && $wl ? '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Mikor</th><th>Mit</th><th>Deal</th><th>Cím</th></tr></thead><tbody>' . implode('', array_map(function ($x) { return '<tr><td>' . esc_html(wp_date('Y.m.d. H:i', (int) $x['at'])) . '</td><td>' . esc_html($x['a']) . '</td><td>' . (int) $x['deal'] . '</td><td>' . esc_html($x['t']) . '</td></tr>'; }, array_slice($wl, 0, 60))) . '</tbody></table>' : '<p>Még nem írt semmit.</p>') . '</div>';
}
