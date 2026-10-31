<?php
/* =====================================================================
 * Számlázás – Billingo (kft.) és Számlázz.hu (egyéni vállalkozó) (v1.73, Számlázz.hu: v1.73.1)
 * – két kiállító (a deal „Szerződés EV/KFT” mezője szerint): mindkettőnek saját Billingo API-kulcsa, számlatömbje,
 *   bankszámlája és alap ÁFA-kulcsa (WordPress → Beállítások → Crazy Billingo; a kulcs soha nem kerül a böngészőbe),
 * – a deal adatlapján „Számlázás” kártya: a vevő adatai (az ügyfélportálról, ha kitöltötte, különben a dealből /
 *   kontaktból), előre kitöltött tételek; számla, díjbekérő, előlegszámla és végszámla (az előleg beszámításával);
 *   a kiállítás mindig a kolléga jóváhagyásával, gombnyomásra történik,
 * – fotós szolgáltatásnál a számla a galériával együtt megy ki: a Követés fül „Galéria” pipájára küldi el a Billingo,
 * – a kiállított bizonylatok fizetési állapotát óránként frissíti.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_BIL_DB_VER', '2');
define('CP_BIL_API', 'https://api.billingo.hu/v3');
function cp_bil_t() { global $wpdb; return $wpdb->prefix . 'crazy_billing'; }

add_action('init', function () {
	if (get_option('cp_bil_db_ver') === CP_BIL_DB_VER || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_bil_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  deal_id bigint(20) unsigned NOT NULL,
  profile varchar(8) NOT NULL DEFAULT '',
  bid bigint(20) unsigned NULL,
  type varchar(12) NOT NULL DEFAULT '',
  number varchar(60) NOT NULL DEFAULT '',
  gross decimal(14,2) NULL,
  currency varchar(3) NOT NULL DEFAULT 'HUF',
  send_mode varchar(10) NOT NULL DEFAULT '',
  sent_at datetime NULL,
  pay varchar(20) NOT NULL DEFAULT '',
  pay_at datetime NULL,
  emails text NULL,
  url text NULL,
  req longtext NULL,
  created_by varchar(100) NOT NULL DEFAULT '',
  created_at datetime NULL,
  checked_at datetime NULL,
  err text NULL,
  PRIMARY KEY  (id),
  KEY deal_id (deal_id),
  KEY bid (bid)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_bil_db_ver', CP_BIL_DB_VER, false);
}, 7);
function cp_bil_db_ok() { return get_option('cp_bil_db_ver') === CP_BIL_DB_VER; }

/* ------------------------------------------------------------ beállítások */
function cp_bil_profiles() { return array('KFT' => 'Kft. – Billingo', 'EV' => 'Egyéni vállalkozó – Számlázz.hu'); }
/* v1.73.1: az egyéni vállalkozó a Számlázz.hu-n számláz (Számla Agent), a kft. a Billingón */
function cp_bil_prov($p) { return $p === 'EV' ? 'szamlazz' : 'billingo'; }
function cp_bil_opts($p) {
	$o = get_option('cp_bil_' . $p, array());
	return array_merge(array('block' => 0, 'bank' => 0, 'vat' => $p === 'EV' ? 'AAM' : '27%', 'due' => 8, 'blocks' => array(), 'banks' => array(), 'org' => '', 'prefix' => '', 'bank_name' => '', 'bank_acc' => '', 'mode' => 'api'), is_array($o) ? $o : array());
}
function cp_bil_key($p) { return (string) get_option('cp_bil_key_' . $p, ''); }
/* v1.74: kézi mód (amikor nincs API-előfizetés): a panel előkészíti az adatokat, a számlát a kolléga állítja ki, a számot visszaírja */
function cp_bil_manual($p) { return cp_bil_opts($p)['mode'] === 'manual'; }
function cp_bil_ok($p) { $o = cp_bil_opts($p); if ($o['mode'] === 'manual') { return true; } return cp_bil_key($p) !== '' && (cp_bil_prov($p) === 'szamlazz' || (int) $o['block'] > 0); }
function cp_bil_any() { foreach (array_keys(cp_bil_profiles()) as $p) { if (cp_bil_ok($p)) { return true; } } return false; }
function cp_bil_gopts() { $o = get_option('cp_bil_g', array()); return array_merge(array('photo' => "fotó\nszelfi\nkarikatúra\nphoto\nautomata\nfotófal"), is_array($o) ? $o : array()); }

function cp_bil_req($p, $method, $path, $query = array(), $body = null) {
	$k = cp_bil_key($p);
	if ($k === '') { return new WP_Error('cp_bil', 'A(z) ' . $p . ' Billingo-fiók nincs bekötve.'); }
	$args = array('method' => $method, 'timeout' => 25, 'headers' => array('X-API-KEY' => $k, 'Accept' => 'application/json', 'Content-Type' => 'application/json'));
	if ($body !== null) { $args['body'] = wp_json_encode($body); }
	$r = wp_remote_request(CP_BIL_API . $path . ($query ? '?' . http_build_query($query) : ''), $args);
	if (is_wp_error($r)) { return new WP_Error('cp_bil', 'A Billingo nem érhető el: ' . $r->get_error_message()); }
	$code = (int) wp_remote_retrieve_response_code($r);
	$raw = wp_remote_retrieve_body($r);
	$j = json_decode($raw, true);
	if ($code === 401) { return new WP_Error('cp_bil', 'A Billingo elutasította az API-kulcsot (' . $p . ').'); }
	if ($code === 429) { return new WP_Error('cp_bil', 'A Billingo most túl sok kérést kapott – próbáld újra egy perc múlva.'); }
	if ($code >= 300) {
		$m = is_array($j) ? (string) ($j['error']['message'] ?? $j['message'] ?? '') : '';
		if (is_array($j) && !empty($j['errors']) && is_array($j['errors'])) { $m .= ' ' . implode(' ', array_map(function ($e) { return is_array($e) ? (string) ($e['message'] ?? wp_json_encode($e)) : (string) $e; }, array_slice($j['errors'], 0, 4))); }
		return new WP_Error('cp_bil', 'Billingo hiba (' . $code . '): ' . sanitize_text_field(trim($m) !== '' ? $m : substr((string) $raw, 0, 200)));
	}
	return is_array($j) ? $j : array('_raw' => $raw);
}

/* ------------------------------------------------------------ a deal számlázási adatai */
function cp_bil_photo($d) {
	$s = mb_strtolower(implode(' ', array_map(function ($k) use ($d) { return (string) ($d[$k] ?? ''); }, array('service', 'pkg_v2', 'pkg_fix', 'package', 'services_ec', 'services_cp', 'services_more', 'title'))));
	foreach (preg_split('/\r?\n/', (string) cp_bil_gopts()['photo']) as $w) { $w = mb_strtolower(trim($w)); if ($w !== '' && mb_strpos($s, $w) !== false) { return true; } }
	return false;
}
/* „1234 Város, Utca 1.” → irányítószám, város, cím */
function cp_bil_addr($s) {
	$s = trim(preg_replace('/\s+/u', ' ', (string) $s));
	if (preg_match('/^(\d{4})\s*,?\s*([^,]+?)\s*,\s*(.+)$/u', $s, $m)) { return array('post_code' => $m[1], 'city' => trim($m[2]), 'address' => trim($m[3])); }
	if (preg_match('/^(\d{4})\s+(\S+)\s+(.+)$/u', $s, $m)) { return array('post_code' => $m[1], 'city' => trim($m[2], ', '), 'address' => trim($m[3])); }
	return array('post_code' => '', 'city' => '', 'address' => $s);
}
function cp_bil_deal_row($id) {
	global $wpdb;
	return $wpdb->get_row($wpdb->prepare('SELECT d.*, c.name cn, c.email ce, c.phone cp, c.mobile cm, c.city ccity, co.name con FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id LEFT JOIN ' . cp_crm_t('companies') . ' co ON co.id = d.company_id WHERE d.id = %d', $id), ARRAY_A);
}
function cp_bil_buyer($d) {
	$b = array('src' => 'deal', 'type' => '', 'name' => '', 'post_code' => '', 'city' => '', 'address' => '', 'taxcode' => '', 'emails' => '', 'note' => '', 'po' => '');
	$a = null;
	if (function_exists('cp_portal_get_by_deal') && function_exists('cp_portal_answers')) { $po = cp_portal_get_by_deal((int) $d['id']); if ($po) { $a = cp_portal_answers($po->id); } }
	$g = function ($k, $s) use ($a) { return is_array($a) && isset($a[$k][$s]) && is_scalar($a[$k][$s]) ? trim((string) $a[$k][$s]) : ''; };
	$type = is_array($a) && isset($a['client_type']) && is_string($a['client_type']) ? $a['client_type'] : '';
	if ($type === 'ceg' && $g('client_company', 'company') !== '') {
		$b = array_merge($b, array('src' => 'portal', 'type' => 'ceg', 'name' => $g('client_company', 'company'), 'taxcode' => $g('client_company', 'tax'), 'emails' => $g('client_company', 'billing_email'), 'po' => $g('client_company', 'po')), cp_bil_addr($g('client_company', 'seat')));
	} elseif ($type === 'magan' && $g('client_private', 'name') !== '') {
		$b = array_merge($b, array('src' => 'portal', 'type' => 'magan', 'name' => $g('client_private', 'name'), 'emails' => $g('client_private', 'email'), 'note' => $g('client_private', 'billing')), cp_bil_addr($g('client_private', 'address')));
	} else {
		$b['name'] = trim((string) ($d['con'] ?: $d['cn'])); $b['type'] = $d['con'] ? 'ceg' : 'magan'; $b['emails'] = (string) $d['ce']; $b['city'] = (string) $d['ccity'];
	}
	return $b;
}
function cp_bil_items($d, $p) {
	$o = cp_bil_opts($p);
	$svc = trim((string) ($d['pkg_v2'] ?: $d['pkg_fix'] ?: $d['service'] ?: ''));
	$more = trim(str_replace(' | ', ', ', implode(', ', array_filter(array((string) ($d['services_ec'] ?? ''), (string) ($d['services_cp'] ?? ''))))), ', ');
	$name = $svc !== '' ? $svc : ($more !== '' ? $more : 'Rendezvényszolgáltatás');
	$cm = trim(($d['event_d'] ? 'Rendezvény: ' . str_replace('-', '.', $d['event_d']) . '.' : '') . ($d['venue'] ? ', ' . $d['venue'] : '') . ((int) $d['hours'] > 0 ? ', ' . (int) $d['hours'] . ' óra' : ''), ', ');
	$it = array(array('name' => $name, 'qty' => 1, 'unit' => 'alkalom', 'price' => (float) $d['value'], 'vat' => $o['vat'], 'comment' => $cm));
	if ((int) $d['km_fee'] > 0) { $it[] = array('name' => 'Kiszállási díj', 'qty' => 1, 'unit' => 'alkalom', 'price' => (float) $d['km_fee'], 'vat' => $o['vat'], 'comment' => ''); }
	if ((float) $d['extension'] > 0) { $it[] = array('name' => 'Hosszabbítás', 'qty' => (float) $d['extension'], 'unit' => 'óra', 'price' => 30000, 'vat' => $o['vat'], 'comment' => ''); }
	return $it;
}
function cp_bil_docs($deal) {
	global $wpdb;
	return (array) $wpdb->get_results($wpdb->prepare('SELECT id, profile, bid, type, number, gross, currency, send_mode, sent_at, pay, pay_at, emails, created_by, created_at, err, (req IS NOT NULL AND number = \'\') pending FROM ' . cp_bil_t() . ' WHERE deal_id = %d ORDER BY id DESC', $deal), ARRAY_A);
}

/* ------------------------------------------------------------ végpontok (deal adatlap) */
function cp_bil_auth() {
	cp_el_auth();
	cp_tab_need(array('szamlazas'));
	if (!cp_bil_db_ok()) { cp_el_err('A számlázás adatbázisa készül – próbáld újra egy perc múlva.', 503); }
}
add_action('wp_ajax_nopriv_cp_bil_deal', 'cp_bil_ajax_deal');
add_action('wp_ajax_cp_bil_deal', 'cp_bil_ajax_deal');
function cp_bil_ajax_deal() {
	cp_bil_auth();
	$d = cp_bil_deal_row((int) ($_POST['id'] ?? 0));
	if (!$d) { cp_el_err('Nincs ilyen deal.', 404); }
	$p = strtoupper((string) $d['ctype']) === 'EV' ? 'EV' : 'KFT';
	$prof = array();
	foreach (cp_bil_profiles() as $k => $n) { $o = cp_bil_opts($k); $prof[$k] = array('n' => $n, 'ok' => cp_bil_ok($k), 'vat' => $o['vat'], 'due' => (int) $o['due'], 'prov' => cp_bil_prov($k), 'manual' => $o['mode'] === 'manual'); }
	$docs = array_map(function ($r) { $r['id'] = (int) $r['id']; $r['bid'] = (int) $r['bid']; $r['gross'] = (float) $r['gross']; $r['at'] = function_exists('cp_crm_local') ? cp_crm_local($r['created_at']) : $r['created_at']; return $r; }, cp_bil_docs((int) $d['id']));
	wp_send_json_success(array('profile' => $p, 'ctype' => (string) $d['ctype'], 'profiles' => $prof, 'buyer' => cp_bil_buyer($d), 'items' => cp_bil_items($d, $p),
		'photo' => cp_bil_photo($d), 'event_d' => (string) $d['event_d'], 'today' => wp_date('Y-m-d'), 'docs' => $docs, 'gallery' => (string) (get_option(CP_PANEL_TRACK . (int) $d['id'], array())['gl'] ?? '') === '1'));
}

/* vevő a Billingóban: adószám (vagy név + város) szerint megkeresi, különben létrehozza */
function cp_bil_partner($p, $b) {
	$tax = preg_replace('/\s+/', '', (string) $b['taxcode']);
	$q = $tax !== '' ? $tax : $b['name'];
	$r = cp_bil_req($p, 'GET', '/partners', array('query' => mb_substr($q, 0, 60), 'per_page' => 25));
	if (is_wp_error($r)) { return $r; }
	$digits = function ($x) { return preg_replace('/\D/', '', (string) $x); };
	foreach ((array) ($r['data'] ?? array()) as $x) {
		if ($tax !== '' && $digits($x['taxcode'] ?? '') !== '' && substr($digits($x['taxcode']), 0, 8) === substr($digits($tax), 0, 8)) { return (int) $x['id']; }
		if ($tax === '' && mb_strtolower(trim((string) ($x['name'] ?? ''))) === mb_strtolower(trim($b['name'])) && mb_strtolower(trim((string) ($x['address']['city'] ?? ''))) === mb_strtolower(trim($b['city']))) { return (int) $x['id']; }
	}
	$foreign = $tax !== '' && preg_match('/^[A-Z]{2}/', $tax) && strpos($tax, 'HU') !== 0;
	$body = array('name' => $b['name'], 'address' => array('country_code' => 'HU', 'post_code' => $b['post_code'], 'city' => $b['city'], 'address' => $b['address']),
		'emails' => array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $b['emails'])), 'is_email')), 'taxcode' => $tax, 'tax_type' => $tax === '' ? 'NO_TAX_NUMBER' : ($foreign ? 'FOREIGN' : 'HAS_TAX_NUMBER'));
	$c = cp_bil_req($p, 'POST', '/partners', array(), $body);
	if (is_wp_error($c)) { return $c; }
	return (int) ($c['id'] ?? 0) ?: new WP_Error('cp_bil', 'A vevő létrehozása nem sikerült.');
}

add_action('wp_ajax_nopriv_cp_bil_issue', 'cp_bil_ajax_issue');
add_action('wp_ajax_cp_bil_issue', 'cp_bil_ajax_issue');
function cp_bil_ajax_issue() {
	cp_bil_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$d = cp_bil_deal_row((int) ($_POST['id'] ?? 0));
	if (!$d) { cp_el_err('Nincs ilyen deal.', 404); }
	$p = (string) ($_POST['profile'] ?? ''); if (!isset(cp_bil_profiles()[$p]) || !cp_bil_ok($p)) { cp_el_err('Ez a kiállító nincs bekötve.'); }
	$type = (string) ($_POST['type'] ?? ''); if (!in_array($type, array('invoice', 'proforma', 'advance'), true)) { cp_el_err('Ismeretlen bizonylattípus.'); }
	$o = cp_bil_opts($p);
	$b = json_decode(wp_unslash((string) ($_POST['buyer'] ?? '')), true);
	$items = json_decode(wp_unslash((string) ($_POST['items'] ?? '')), true);
	if (!is_array($b) || trim((string) ($b['name'] ?? '')) === '') { cp_el_err('A vevő neve kötelező.'); }
	foreach (array('name', 'post_code', 'city', 'address', 'taxcode', 'emails') as $k) { $b[$k] = sanitize_text_field((string) ($b[$k] ?? '')); }
	if ($b['post_code'] === '' || $b['city'] === '' || $b['address'] === '') { cp_el_err('A vevő címe (irányítószám, város, utca) kötelező.'); }
	if (!is_array($items) || !$items) { cp_el_err('Legalább egy tétel kell.'); }
	$vats = array('27%', '18%', '5%', '0%', 'AAM', 'TAM', 'EU', 'EUK', 'MAA', 'F.AFA', 'K.AFA', 'ÁKK', 'ÁTHK');
	$lines = array(); $sum = 0;
	foreach (array_slice($items, 0, 30) as $i) {
		$n = sanitize_text_field((string) ($i['name'] ?? '')); $q = (float) ($i['qty'] ?? 0); $pr = (float) ($i['price'] ?? 0); $v = (string) ($i['vat'] ?? $o['vat']);
		if ($n === '' || $q <= 0 || $pr <= 0) { cp_el_err('Minden tételnél kell név, mennyiség és pozitív egységár.'); }
		if (!in_array($v, $vats, true)) { cp_el_err('Ismeretlen ÁFA-kulcs: ' . $v); }
		$lines[] = array('name' => $n, 'unit_price' => round($pr, 2), 'unit_price_type' => ($i['ptype'] ?? 'net') === 'gross' ? 'gross' : 'net', 'quantity' => $q, 'unit' => sanitize_text_field((string) ($i['unit'] ?? 'db')) ?: 'db', 'vat' => $v, 'comment' => sanitize_text_field((string) ($i['comment'] ?? '')));
		$sum += $q * $pr;
	}
	$pm = (string) ($_POST['pay'] ?? 'wire_transfer'); if (!in_array($pm, array('wire_transfer', 'cash', 'bankcard', 'elore_utalas', 'other'), true)) { $pm = 'wire_transfer'; }
	$ful = cp_el_req_date('fulfillment') ?: wp_date('Y-m-d'); $due = cp_el_req_date('due') ?: wp_date('Y-m-d', time() + (int) $o['due'] * DAY_IN_SECONDS);
	$adv = array();
	if ($type === 'invoice' && !empty($_POST['advance'])) {
		foreach (array_map('intval', (array) json_decode(wp_unslash((string) $_POST['advance']), true)) as $aid) {
			$bid = (int) $wpdb->get_var($wpdb->prepare('SELECT bid FROM ' . cp_bil_t() . " WHERE id = %d AND deal_id = %d AND type = 'advance' AND profile = %s", $aid, (int) $d['id'], $p));
			if ($bid) { $adv[] = $bid; }
		}
	}
	$mode = (string) ($_POST['send'] ?? 'now'); if (!in_array($mode, array('now', 'gallery', 'no'), true)) { $mode = 'now'; }
	if (cp_bil_manual($p)) { $mode = 'no'; }
	$emails = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $b['emails'])), 'is_email'));
	if ($mode !== 'no' && !$emails) { cp_el_err('A kiküldéshez kell a vevő e-mail-címe (vagy válaszd: „nem küldöm ki”).'); }
	$cm = sanitize_textarea_field(wp_unslash((string) ($_POST['comment'] ?? '')));
	$who = function_exists('cp_pu_current') && cp_pu_current() ? cp_pu_current()['n'] : (function_exists('cp_panel_who') ? cp_panel_who() : '');
	if (cp_bil_manual($p)) {
		/* kézi mód: csak rögzíti a máshol kiállított bizonylatot (a számot a kolléga írja be) */
		$num = mb_substr(sanitize_text_field(wp_unslash((string) ($_POST['number'] ?? ''))), 0, 60);
		if ($num === '') { cp_el_err('Írd be a kiállított bizonylat számát.'); }
		$gross = 0;
		foreach ($lines as $l) { $r8 = preg_match('/^(\d+)%$/', $l['vat'], $m) ? (int) $m[1] / 100 : 0; $v = $l['unit_price'] * $l['quantity']; $gross += $l['unit_price_type'] === 'gross' ? $v : $v * (1 + $r8); }
		$wpdb->insert(cp_bil_t(), array('deal_id' => (int) $d['id'], 'profile' => $p, 'type' => $type, 'number' => $num, 'gross' => round($gross), 'currency' => 'HUF', 'send_mode' => 'manual', 'emails' => implode(', ', $emails), 'created_by' => $who, 'created_at' => gmdate('Y-m-d H:i:s')));
		wp_send_json_success(array('msg' => 'Rögzítve: ' . $num . '.', 'docs' => cp_bil_docs((int) $d['id'])));
	}
	if (cp_bil_prov($p) === 'szamlazz') {
		$advNo = '';
		if ($type === 'invoice' && !empty($_POST['advance'])) {
			foreach (array_map('intval', (array) json_decode(wp_unslash((string) $_POST['advance']), true)) as $aid) {
				$advNo = (string) $wpdb->get_var($wpdb->prepare('SELECT number FROM ' . cp_bil_t() . " WHERE id = %d AND deal_id = %d AND type = 'advance' AND profile = %s AND number <> ''", $aid, (int) $d['id'], $p));
				if ($advNo !== '') { break; }
			}
		}
		$req = array('type' => $type, 'buyer' => $b, 'lines' => $lines, 'ful' => $ful, 'due' => $due, 'pay' => $pm, 'comment' => $cm, 'po' => sanitize_text_field((string) ($b['po'] ?? '')), 'adv' => $advNo, 'emails' => $emails);
		if (!empty($_POST['preview'])) {
			$x = cp_szl_issue($p, $req, 'preview');
			if (is_wp_error($x)) { cp_el_err($x->get_error_message(), 502); }
			wp_send_json_success(array('pdf' => base64_encode($x['pdf'])));
		}
		$lk = 'cp_bil_lock_' . (int) $d['id'] . '_' . $type;
		if (get_transient($lk)) { cp_el_err('Ez a bizonylat épp most készült – frissítsd az adatlapot.'); }
		set_transient($lk, 1, 60);
		$galDone = (string) (get_option(CP_PANEL_TRACK . (int) $d['id'], array())['gl'] ?? '') === '1';
		$wpdb->insert(cp_bil_t(), array('deal_id' => (int) $d['id'], 'profile' => $p, 'type' => $type, 'number' => '', 'currency' => 'HUF', 'send_mode' => $mode, 'emails' => implode(', ', $emails), 'req' => wp_json_encode($req, JSON_UNESCAPED_UNICODE), 'created_by' => $who, 'created_at' => gmdate('Y-m-d H:i:s')));
		$row = (int) $wpdb->insert_id;
		if ($mode === 'gallery' && !$galDone) { wp_send_json_success(array('msg' => 'Előkészítve: a Számlázz.hu a galéria kiküldésekor (Követés fül, „Galéria” pipa) állítja ki és küldi el.', 'docs' => cp_bil_docs((int) $d['id']))); }
		$x = cp_szl_issue_row($row, $mode !== 'no');
		if (is_wp_error($x)) { $wpdb->delete(cp_bil_t(), array('id' => $row)); delete_transient($lk); cp_el_err($x->get_error_message(), 502); }
		wp_send_json_success(array('msg' => 'Kiállítva a Számlázz.hu-n: ' . $x . ($mode !== 'no' ? '. A Számlázz.hu elküldte: ' . implode(', ', $emails) . '.' : '.'), 'docs' => cp_bil_docs((int) $d['id'])));
	}
	/* kétszeri kattintás ellen: ugyanarra a dealre ugyanaz a típus 60 mp-en belül csak egyszer */
	$lk = 'cp_bil_lock_' . (int) $d['id'] . '_' . $type;
	if (get_transient($lk)) { cp_el_err('Ez a bizonylat épp most készült – frissítsd az adatlapot.'); }
	set_transient($lk, 1, 60);
	$pid = cp_bil_partner($p, $b);
	if (is_wp_error($pid)) { delete_transient($lk); cp_el_err($pid->get_error_message(), 502); }
	/* v1.73.2: díjbekérőhöz a Billingo díjbekérő-tömbjét használja (ha van), minden máshoz a kiválasztott számlatömböt */
	$blk = (int) $o['block'];
	if ($type === 'proforma') { foreach ((array) $o['blocks'] as $x) { if (preg_match('/– proforma$/', (string) $x['n'])) { $blk = (int) $x['id']; break; } } }
	$body = array('partner_id' => $pid, 'block_id' => $blk, 'type' => $type, 'fulfillment_date' => $ful, 'due_date' => $due, 'payment_method' => $pm, 'language' => 'hu', 'currency' => 'HUF', 'electronic' => true, 'paid' => false, 'items' => $lines, 'comment' => $cm);
	if ((int) $o['bank'] > 0) { $body['bank_account_id'] = (int) $o['bank']; }
	if ($adv) { $body['advance_invoice'] = $adv; }
	if (!empty($b['po'])) { $body['settings'] = array('order_number' => mb_substr(sanitize_text_field((string) $b['po']), 0, 60)); }
	$r = cp_bil_req($p, 'POST', '/documents', array(), $body);
	if (is_wp_error($r)) { delete_transient($lk); cp_el_err($r->get_error_message(), 502); }
	$wpdb->insert(cp_bil_t(), array('deal_id' => (int) $d['id'], 'profile' => $p, 'bid' => (int) ($r['id'] ?? 0), 'type' => $type, 'number' => (string) ($r['invoice_number'] ?? ''), 'gross' => (float) ($r['gross_total'] ?? 0), 'currency' => 'HUF',
		'send_mode' => $mode, 'pay' => (string) ($r['payment_status'] ?? ''), 'emails' => implode(', ', $emails), 'created_by' => $who, 'created_at' => gmdate('Y-m-d H:i:s')));
	$row = (int) $wpdb->insert_id;
	$msg = 'Kiállítva a Billingóban' . (!empty($r['invoice_number']) ? ': ' . $r['invoice_number'] : '') . '.';
	if ($mode === 'now') { $s = cp_bil_send($row); $msg .= is_wp_error($s) ? ' A kiküldés nem sikerült: ' . $s->get_error_message() : ' Elküldve: ' . implode(', ', $emails) . '.'; }
	elseif ($mode === 'gallery') { $msg .= ' A galériával együtt megy ki (a Követés fül „Galéria” pipájára).'; if ((string) (get_option(CP_PANEL_TRACK . (int) $d['id'], array())['gl'] ?? '') === '1') { $s = cp_bil_send($row); $msg .= is_wp_error($s) ? '' : ' A galéria már ki van küldve, ezért most elküldtem.'; } }
	wp_send_json_success(array('msg' => $msg, 'docs' => cp_bil_docs((int) $d['id'])));
}

function cp_bil_send($row) {
	global $wpdb;
	$r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_bil_t() . ' WHERE id = %d', $row), ARRAY_A);
	if ($r && cp_bil_prov($r['profile']) === 'szamlazz') {
		if ($r['number'] !== '') { return new WP_Error('cp_bil', 'A Számlázz.hu-n kiállított számlát a Számlázz.hu felületén lehet újra elküldeni.'); }
		$x = cp_szl_issue_row((int) $r['id'], true);
		return is_wp_error($x) ? $x : true;
	}
	if (!$r || !$r['bid']) { return new WP_Error('cp_bil', 'Nincs ilyen bizonylat.'); }
	$emails = array_values(array_filter(array_map('trim', explode(',', (string) $r['emails'])), 'is_email'));
	if (!$emails) { return new WP_Error('cp_bil', 'Nincs e-mail-cím.'); }
	$x = cp_bil_req($r['profile'], 'POST', '/documents/' . (int) $r['bid'] . '/send', array(), array('emails' => $emails));
	if (is_wp_error($x)) { $wpdb->update(cp_bil_t(), array('err' => $x->get_error_message()), array('id' => (int) $r['id'])); return $x; }
	$wpdb->update(cp_bil_t(), array('sent_at' => gmdate('Y-m-d H:i:s'), 'err' => null), array('id' => (int) $r['id']));
	return true;
}

/* kézi kiküldés / PDF-link */
add_action('wp_ajax_nopriv_cp_bil_act', 'cp_bil_ajax_act');
add_action('wp_ajax_cp_bil_act', 'cp_bil_ajax_act');
function cp_bil_ajax_act() {
	cp_bil_auth();
	global $wpdb;
	$r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_bil_t() . ' WHERE id = %d', (int) ($_POST['row'] ?? 0)), ARRAY_A);
	if (!$r) { cp_el_err('Nincs ilyen bizonylat.', 404); }
	$op = (string) ($_POST['op'] ?? '');
	if ($op === 'send') { $s = cp_bil_send((int) $r['id']); if (is_wp_error($s)) { cp_el_err($s->get_error_message(), 502); } wp_send_json_success(array('msg' => 'Elküldve: ' . $r['emails'], 'docs' => cp_bil_docs((int) $r['deal_id']))); }
	if ($op === 'url' && cp_bil_prov($r['profile']) === 'szamlazz') { if ((string) $r['url'] === '') { cp_el_err('Ehhez a számlához nincs link (a Számlázz.hu felületén nyisd meg).'); } wp_send_json_success(array('url' => esc_url_raw((string) $r['url']))); }
	if ($op === 'url') { $x = cp_bil_req($r['profile'], 'GET', '/documents/' . (int) $r['bid'] . '/public-url'); if (is_wp_error($x)) { cp_el_err($x->get_error_message(), 502); } wp_send_json_success(array('url' => esc_url_raw((string) ($x['public_url'] ?? '')))); }
	cp_el_err('Ismeretlen művelet.');
}

/* ------------------------------------------------------------ háttér: galériával küldés + fizetési állapot */
add_action('cp_crm_tick', 'cp_bil_tick', 33);
function cp_bil_tick() {
	if (!cp_bil_db_ok() || !cp_bil_any()) { return; }
	global $wpdb;
	$T = cp_bil_t();
	foreach ((array) $wpdb->get_results("SELECT id, deal_id FROM $T WHERE send_mode = 'gallery' AND sent_at IS NULL AND (bid IS NOT NULL OR (req IS NOT NULL AND number = '')) ORDER BY id LIMIT 20", ARRAY_A) as $r) {
		if ((string) (get_option(CP_PANEL_TRACK . (int) $r['deal_id'], array())['gl'] ?? '') === '1') { cp_bil_send((int) $r['id']); }
	}
	foreach ((array) $wpdb->get_results("SELECT id, profile, bid FROM $T WHERE bid IS NOT NULL AND profile = 'KFT' AND pay NOT IN ('paid') AND (checked_at IS NULL OR checked_at < NOW() - INTERVAL 1 HOUR) AND created_at > NOW() - INTERVAL 120 DAY ORDER BY checked_at LIMIT 15", ARRAY_A) as $r) {
		$x = cp_bil_req($r['profile'], 'GET', '/documents/' . (int) $r['bid']);
		$u = array('checked_at' => gmdate('Y-m-d H:i:s'));
		if (!is_wp_error($x)) { $u['pay'] = (string) ($x['payment_status'] ?? ''); if (!empty($x['invoice_number'])) { $u['number'] = (string) $x['invoice_number']; } if (!empty($x['paid_date'])) { $u['pay_at'] = substr((string) $x['paid_date'], 0, 10) . ' 12:00:00'; } }
		$wpdb->update($T, $u, array('id' => (int) $r['id']));
	}
}

/* ------------------------------------------------------------ Számlázz.hu (Számla Agent, XML) */
function cp_szl_post($action, $xml) {
	$bd = '----cpszl' . wp_generate_password(20, false, false);
	$body = '--' . $bd . "\r\nContent-Disposition: form-data; name=\"" . $action . "\"; filename=\"request.xml\"\r\nContent-Type: text/xml\r\n\r\n" . $xml . "\r\n--" . $bd . "--\r\n";
	$r = wp_remote_post('https://www.szamlazz.hu/szamla/', array('timeout' => 45, 'headers' => array('Content-Type' => 'multipart/form-data; boundary=' . $bd), 'body' => $body));
	if (is_wp_error($r)) { return new WP_Error('cp_bil', 'A Számlázz.hu nem érhető el: ' . $r->get_error_message()); }
	$h = wp_remote_retrieve_headers($r);
	if (!empty($h['szlahu_error_code'])) { return new WP_Error('cp_bil', 'Számlázz.hu hiba (' . sanitize_text_field((string) $h['szlahu_error_code']) . '): ' . sanitize_text_field(urldecode(str_replace('+', ' ', (string) ($h['szlahu_error'] ?? ''))))); }
	if ((int) wp_remote_retrieve_response_code($r) !== 200) { return new WP_Error('cp_bil', 'Számlázz.hu hiba (' . (int) wp_remote_retrieve_response_code($r) . ').'); }
	return array('h' => $h, 'body' => (string) wp_remote_retrieve_body($r));
}
function cp_szl_el($name, $pairs) {
	$o = '<' . $name . '>';
	foreach ($pairs as $k => $v) {
		if ($v === null || $v === '') { continue; }
		if (is_bool($v)) { $v = $v ? 'true' : 'false'; }
		$o .= '<' . $k . '>' . htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $k . '>';
	}
	return $o . '</' . $name . '>';
}
/* $mode: 'preview' (csak PDF-előnézet, nem állít ki) | 'send' (kiállít és a Számlázz.hu e-mailben elküldi) | 'quiet' */
function cp_szl_issue($p, $q, $mode) {
	$o = cp_bil_opts($p);
	$PM = array('wire_transfer' => 'Átutalás', 'cash' => 'Készpénz', 'bankcard' => 'Bankkártya', 'elore_utalas' => 'Előre utalás', 'other' => 'Egyéb');
	$b = $q['buyer']; $tax = preg_replace('/\s+/', '', (string) $b['taxcode']);
	$x = '<?xml version="1.0" encoding="UTF-8"?><xmlszamla xmlns="http://www.szamlazz.hu/xmlszamla" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.szamlazz.hu/xmlszamla https://www.szamlazz.hu/szamla/docs/xsds/agent/xmlszamla.xsd">';
	$x .= cp_szl_el('beallitasok', array('szamlaagentkulcs' => cp_bil_key($p), 'eszamla' => true, 'szamlaLetoltes' => $mode === 'preview', 'valaszVerzio' => 1));
	$x .= cp_szl_el('fejlec', array('keltDatum' => wp_date('Y-m-d'), 'teljesitesDatum' => $q['ful'], 'fizetesiHataridoDatum' => $q['due'], 'fizmod' => $PM[$q['pay']] ?? 'Átutalás', 'penznem' => 'HUF', 'szamlaNyelve' => 'hu', 'megjegyzes' => $q['comment'], 'rendelesSzam' => $q['po'],
		'elolegszamla' => $q['type'] === 'advance' ? true : null, 'vegszamla' => $q['adv'] !== '' ? true : null, 'elolegSzamlaszam' => $q['adv'] !== '' ? $q['adv'] : null, 'dijbekero' => $q['type'] === 'proforma' ? true : null,
		'szamlaszamElotag' => (string) $o['prefix'], 'fizetve' => false, 'elonezetpdf' => $mode === 'preview' ? true : null));
	$x .= cp_szl_el('elado', array('bank' => (string) $o['bank_name'], 'bankszamlaszam' => (string) $o['bank_acc']));
	$x .= cp_szl_el('vevo', array('nev' => $b['name'], 'irsz' => $b['post_code'], 'telepules' => $b['city'], 'cim' => $b['address'], 'email' => implode(',', (array) $q['emails']), 'sendEmail' => $mode === 'send',
		'adoalany' => $tax === '' ? -1 : 1, 'adoszam' => $tax));
	$t = '';
	foreach ($q['lines'] as $l) {
		$special = !preg_match('/^(\d+)%$/', $l['vat'], $m); $rate = $special ? 0 : (int) $m[1];
		if ($l['unit_price_type'] === 'gross') { $gross = round($l['unit_price'] * $l['quantity']); $vat = round($gross / (100 + $rate) * $rate); $net = $gross - $vat; $unit = round($net / $l['quantity'], 2); }
		else { $unit = round($l['unit_price'], 2); $net = round($unit * $l['quantity']); $vat = round($net * $rate / 100); $gross = $net + $vat; }
		$t .= cp_szl_el('tetel', array('megnevezes' => $l['name'], 'mennyiseg' => $l['quantity'], 'mennyisegiEgyseg' => $l['unit'], 'nettoEgysegar' => $unit, 'afakulcs' => $special ? $l['vat'] : (string) $rate, 'nettoErtek' => $net, 'afaErtek' => $vat, 'bruttoErtek' => $gross, 'megjegyzes' => $l['comment']));
	}
	$x .= '<tetelek>' . $t . '</tetelek></xmlszamla>';
	$r = cp_szl_post('action-xmlagentxmlfile', $x);
	if (is_wp_error($r)) { return $r; }
	if ($mode === 'preview') { if (strpos($r['body'], '%PDF') !== 0) { return new WP_Error('cp_bil', 'A Számlázz.hu nem adott előnézetet.'); } return array('pdf' => $r['body']); }
	return array('number' => sanitize_text_field((string) ($r['h']['szlahu_szamlaszam'] ?? '')), 'gross' => (float) ($r['h']['szlahu_bruttovegosszeg'] ?? 0), 'url' => esc_url_raw(urldecode((string) ($r['h']['szlahu_vevoifiokurl'] ?? ''))));
}
/* egy előkészített sor kiállítása (azonnal vagy a galéria kiküldésekor) – a számlaszámot adja vissza */
function cp_szl_issue_row($row, $send) {
	global $wpdb;
	$r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_bil_t() . ' WHERE id = %d', $row), ARRAY_A);
	if (!$r || $r['number'] !== '' || empty($r['req'])) { return new WP_Error('cp_bil', 'Ez a számla már ki van állítva.'); }
	$lk = 'cp_szl_row_' . (int) $row; if (get_transient($lk)) { return new WP_Error('cp_bil', 'A kiállítás folyamatban.'); } set_transient($lk, 1, 120);
	$q = json_decode((string) $r['req'], true);
	$x = cp_szl_issue($r['profile'], $q, $send ? 'send' : 'quiet');
	delete_transient($lk);
	if (is_wp_error($x)) { $wpdb->update(cp_bil_t(), array('err' => $x->get_error_message()), array('id' => (int) $row)); return $x; }
	$wpdb->update(cp_bil_t(), array('number' => $x['number'] !== '' ? $x['number'] : '(kiállítva)', 'gross' => $x['gross'], 'url' => $x['url'], 'sent_at' => $send ? gmdate('Y-m-d H:i:s') : null, 'err' => null), array('id' => (int) $row));
	return $x['number'];
}

/* ------------------------------------------------------------ WordPress-admin */
add_action('admin_menu', function () { add_options_page('Crazy számlázás', 'Crazy számlázás', 'manage_options', 'cp-billingo', 'cp_bil_admin_page'); });
/* kapcsolatpróba (Billingo: számlatömbök, bankszámlák, cégnév betöltése; Számlázz.hu: kulcsellenőrzés) – a WordPress-oldal és a panel közös */
function cp_bil_test($p, &$o) {
	$msg = ''; $err = '';
	if (cp_bil_prov($p) === 'szamlazz') {
		/* próbalekérdezés egy nem létező számlára: a kulcs hibáját a Számlázz.hu külön jelzi */
		$x = cp_bil_key($p) === '' ? new WP_Error('cp_bil', 'Nincs megadva Számla Agent kulcs.') : cp_szl_post('action-szamla_agent_xml', '<?xml version="1.0" encoding="UTF-8"?><xmlszamlaxml xmlns="http://www.szamlazz.hu/xmlszamlaxml" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.szamlazz.hu/xmlszamlaxml https://www.szamlazz.hu/szamla/docs/xsds/agentxml/xmlszamlaxml.xsd"><szamlaagentkulcs>' . htmlspecialchars(cp_bil_key($p), ENT_XML1) . '</szamlaagentkulcs><szamlaszam>CRAZYPANEL-TESZT-0</szamlaszam></xmlszamlaxml>');
		$m = is_wp_error($x) ? $x->get_error_message() : 'rendben';
		if (preg_match('/bejelentkez|kulcs|jelsz|felhaszn|login/iu', $m)) { $err = $p . ': a Számlázz.hu nem fogadta el a kulcsot – ' . $m; }
		else { $msg = $p . ': a Számlázz.hu elfogadta a kulcsot (próbalekérdezés egy nem létező számlára: ' . $m . ').'; }
	}
	else {
		$bl = cp_bil_req($p, 'GET', '/document-blocks', array('per_page' => 100));
		$ba = is_wp_error($bl) ? $bl : cp_bil_req($p, 'GET', '/bank-accounts', array('per_page' => 100));
		$og = is_wp_error($ba) ? $ba : cp_bil_req($p, 'GET', '/organization');
		if (is_wp_error($og)) { $err = $og->get_error_message(); }
		else {
			$o['blocks'] = array_map(function ($x) { return array('id' => (int) $x['id'], 'n' => (string) $x['name'] . (!empty($x['prefix']) ? ' (' . $x['prefix'] . ')' : '') . (!empty($x['type']) ? ' – ' . $x['type'] : '')); }, (array) ($bl['data'] ?? array()));
			$o['banks'] = array_map(function ($x) { return array('id' => (int) $x['id'], 'n' => (string) $x['name'] . ' ' . (string) ($x['account_number'] ?? '')); }, (array) ($ba['data'] ?? array()));
			$o['org'] = sanitize_text_field((string) ($og['name'] ?? ''));
			if (!$o['block'] && $o['blocks']) { $o['block'] = $o['blocks'][0]['id']; }
			if (!$o['bank'] && $o['banks']) { $o['bank'] = $o['banks'][0]['id']; }
			$msg = $p . ': a Billingo-fiók elérhető (' . $o['org'] . '), ' . count($o['blocks']) . ' számlatömb, ' . count($o['banks']) . ' bankszámla.';
		}
	}
	return array($msg, $err);
}
function cp_bil_admin_page() {
	if (!current_user_can('manage_options')) { return; }
	$msg = ''; $err = '';
	if (!empty($_POST['cp_bil_do']) && check_admin_referer('cp_bil_do')) {
		$do = (string) $_POST['cp_bil_do'];
		$p = sanitize_key((string) ($_POST['p'] ?? '')); $p = strtoupper($p);
		if ($do === 'g') { update_option('cp_bil_g', array('photo' => sanitize_textarea_field(wp_unslash((string) ($_POST['photo'] ?? '')))), false); $msg = 'Elmentve.'; }
		elseif (isset(cp_bil_profiles()[$p])) {
			$o = cp_bil_opts($p);
			if ($do === 'save') {
				$k = trim((string) wp_unslash($_POST['key'] ?? ''));
				if ($k !== '') { update_option('cp_bil_key_' . $p, preg_replace('/[^A-Za-z0-9\-_]/', '', $k), false); }
				$o['block'] = (int) ($_POST['block'] ?? 0); $o['bank'] = (int) ($_POST['bank'] ?? 0);
				$o['vat'] = in_array($_POST['vat'] ?? '', array('27%', '18%', '5%', '0%', 'AAM', 'TAM'), true) ? $_POST['vat'] : $o['vat'];
				$o['due'] = max(0, min(90, (int) ($_POST['due'] ?? 8)));
				$o['mode'] = ($_POST['mode'] ?? '') === 'manual' ? 'manual' : 'api';
				foreach (array('prefix', 'bank_name', 'bank_acc') as $f) { if (isset($_POST[$f])) { $o[$f] = mb_substr(sanitize_text_field(wp_unslash((string) $_POST[$f])), 0, 60); } }
				$do = $o['mode'] === 'manual' && cp_bil_key($p) === '' ? '' : 'test';
				if ($do === '') { $msg = $p . ': elmentve (kézi mód).'; }
			}
			if ($do === 'del') { delete_option('cp_bil_key_' . $p); $o['blocks'] = array(); $o['banks'] = array(); $o['org'] = ''; $msg = $p . ': a kulcs törölve.'; }
			if ($do === 'test') { list($m2, $e2) = cp_bil_test($p, $o); if ($m2 !== '') { $msg = $m2; } if ($e2 !== '') { $err = $e2; } }
			update_option('cp_bil_' . $p, $o, false);
		}
	}
	echo '<div class="wrap"><h1>Crazy számlázás</h1>';
	if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
	if ($err) { echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>'; }
	echo '<p>A deal adatlapján a <b>Számlázás</b> kártyáról számla, díjbekérő, előlegszámla és végszámla állítható ki – mindig a kolléga jóváhagyásával. A kiállító a deal „Szerződés EV/KFT” mezőjéből jön (az adatlapon átírható). API-kulcs: Billingo → Beállítások → <b>API</b> → Új kulcs (v3). Az egyéni vállalkozó a <b>Számlázz.hu</b>-n számláz: Számlázz.hu → Beállítások → <b>Számla Agent kulcsok</b> → Új kulcs. A kulcsokat csak itt add meg, máshova ne küldd el.</p>';
	foreach (cp_bil_profiles() as $p => $n) {
		$o = cp_bil_opts($p); $has = cp_bil_key($p) !== '';
		$sel = function ($name, $list, $cur) { $h = '<select name="' . $name . '"><option value="0">–</option>'; foreach ($list as $x) { $h .= '<option value="' . (int) $x['id'] . '"' . selected((int) $cur, (int) $x['id'], false) . '>' . esc_html($x['n']) . '</option>'; } return $h . '</select>'; };
		if (cp_bil_prov($p) === 'szamlazz') {
			echo '<h2>' . esc_html($n) . ' <small>(' . $p . ')</small></h2><form method="post">' . wp_nonce_field('cp_bil_do', '_wpnonce', true, false) . '<input type="hidden" name="p" value="' . $p . '"><table class="form-table">' .
				'<tr><th>Működés</th><td><label><input type="radio" name="mode" value="api"' . checked($o['mode'] !== 'manual', true, false) . '> <b>API</b> – a panel állítja ki és küldi el (ehhez API-s előfizetés kell)</label><br><label><input type="radio" name="mode" value="manual"' . checked($o['mode'] === 'manual', true, false) . '> <b>Kézi</b> – a panel előkészíti az adatokat (másolható), a számlát a számlázóban állítod ki, a számát visszaírod</label></td></tr>' .
				'<tr><th>Számla Agent kulcs</th><td><input type="password" name="key" class="regular-text" autocomplete="new-password" placeholder="' . ($has ? '•••••••• (megadva – üresen hagyva marad)' : 'Számlázz.hu Számla Agent kulcs') . '"> ' . ($has ? '✅' : '❌ nincs') . '</td></tr>' .
				'<tr><th>Számlaszám-előtag</th><td><input type="text" name="prefix" value="' . esc_attr($o['prefix']) . '" style="width:120px"> <small>(üresen: a Számlázz.hu alapbeállítása)</small></td></tr>' .
				'<tr><th>Bank / bankszámla</th><td><input type="text" name="bank_name" value="' . esc_attr($o['bank_name']) . '" placeholder="bank neve"> <input type="text" name="bank_acc" value="' . esc_attr($o['bank_acc']) . '" placeholder="12345678-12345678-12345678" class="regular-text"> <small>(üresen: a Számlázz.hu-fiók adatai)</small></td></tr>' .
				'<tr><th>Alap ÁFA</th><td><select name="vat">' . implode('', array_map(function ($v) use ($o) { return '<option' . selected($o['vat'], $v, false) . '>' . $v . '</option>'; }, array('AAM', '27%', '18%', '5%', '0%', 'TAM'))) . '</select> <small>(AAM = alanyi adómentes)</small></td></tr>' .
				'<tr><th>Fizetési határidő</th><td><input type="number" name="due" min="0" max="90" value="' . (int) $o['due'] . '" style="width:70px"> nap</td></tr>' .
				'</table><p><button class="button button-primary" name="cp_bil_do" value="save">Mentés és ellenőrzés</button> ' . ($has ? '<button class="button" name="cp_bil_do" value="test">Kapcsolat ellenőrzése</button> <button class="button" name="cp_bil_do" value="del" onclick="return confirm(\'Törlöd a kulcsot?\')">Kulcs törlése</button>' : '') . '</p></form>';
			continue;
		}
		echo '<h2>' . esc_html($n) . ' <small>(' . $p . ')</small></h2><form method="post">' . wp_nonce_field('cp_bil_do', '_wpnonce', true, false) . '<input type="hidden" name="p" value="' . $p . '"><table class="form-table">' .
			'<tr><th>Működés</th><td><label><input type="radio" name="mode" value="api"' . checked($o['mode'] !== 'manual', true, false) . '> <b>API</b> – a panel állítja ki és küldi el (ehhez API-s előfizetés kell)</label><br><label><input type="radio" name="mode" value="manual"' . checked($o['mode'] === 'manual', true, false) . '> <b>Kézi</b> – a panel előkészíti az adatokat (másolható), a számlát a számlázóban állítod ki, a számát visszaírod</label></td></tr>' .
			'<tr><th>API-kulcs</th><td><input type="password" name="key" class="regular-text" autocomplete="new-password" placeholder="' . ($has ? '•••••••• (megadva – üresen hagyva marad)' : 'Billingo API v3 kulcs') . '"> ' . ($has ? '✅' . ($o['org'] ? ' ' . esc_html($o['org']) : '') : '❌ nincs') . '</td></tr>' .
			'<tr><th>Számlatömb</th><td>' . ($o['blocks'] ? $sel('block', $o['blocks'], $o['block']) : '<em>a kulcs mentése után választható</em>') . '</td></tr>' .
			'<tr><th>Bankszámla</th><td>' . ($o['banks'] ? $sel('bank', $o['banks'], $o['bank']) : '<em>a kulcs mentése után választható</em>') . '</td></tr>' .
			'<tr><th>Alap ÁFA</th><td><select name="vat">' . implode('', array_map(function ($v) use ($o) { return '<option' . selected($o['vat'], $v, false) . '>' . $v . '</option>'; }, array('27%', '18%', '5%', '0%', 'AAM', 'TAM'))) . '</select> <small>(AAM = alanyi adómentes)</small></td></tr>' .
			'<tr><th>Fizetési határidő</th><td><input type="number" name="due" min="0" max="90" value="' . (int) $o['due'] . '" style="width:70px"> nap</td></tr>' .
			'</table><p><button class="button button-primary" name="cp_bil_do" value="save">Mentés és ellenőrzés</button> ' . ($has ? '<button class="button" name="cp_bil_do" value="test">Kapcsolat ellenőrzése</button> <button class="button" name="cp_bil_do" value="del" onclick="return confirm(\'Törlöd a kulcsot?\')">Kulcs törlése</button>' : '') . '</p></form>';
	}
	$g = cp_bil_gopts();
	echo '<h2>Galériával együtt küldött számlák</h2><form method="post">' . wp_nonce_field('cp_bil_do', '_wpnonce', true, false) . '<p>Ha a deal szolgáltatásában szerepel valamelyik szó (soronként egy), az adatlapon alapból a „galériával együtt” küldés van kiválasztva: a számla akkor megy ki (a Billingónál ekkor küldi el, a Számlázz.hu-nál ekkor állítja ki és küldi el), amikor a Követés fülön bepipáljátok a <b>Galéria</b> oszlopot.</p><textarea name="photo" rows="6" cols="40">' . esc_textarea($g['photo']) . '</textarea><p><button class="button" name="cp_bil_do" value="g">Mentés</button></p></form></div>';
}
