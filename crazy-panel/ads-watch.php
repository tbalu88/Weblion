<?php
/* =====================================================================
 * v2.9.392: Hirdetésfiókok figyelése (csak olvas, a fiókokban semmit nem módosít)
 * - Google: az óránként futó Google Ads-szkript új változata a költés mellett a kampányok beállításait (camps), a fiók
 *   beállításait (cust) és a változási előzményeket (changes, utolsó 14 nap) is elküldi a /crazy-panel/v1/ads végpontra.
 * - Meta: a meglévő Meta-kapcsolattal (a költés-szinkronnal együtt, kb. óránként) a kampányok állapota, kerete,
 *   az elutasított hirdetések száma és a fiók tevékenységi előzményei.
 * - A két pillanatkép különbségéből a panel maga is naplóz (új kampány, leállt, keretváltozás, licit, UTM…),
 *   és figyelmeztet (leállt / elutasított / keret korlátozza / nincs UTM / a szkript nem küld).
 * Nézet: Statisztikák → Hirdetésfiókok. AJAX: cp_aw_data (jog: mint a Statisztikák).
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }
define('CP_AW_DB_VER', '1');
function cp_aw_t() { global $wpdb; return $wpdb->prefix . 'crazy_ads_chg'; }
function cp_aw_ok() { return get_option('cp_aw_db_ver') === CP_AW_DB_VER; }
add_action('init', function () {
	if (cp_aw_ok()) { return; }
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	global $wpdb;
	dbDelta('CREATE TABLE ' . cp_aw_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  src varchar(10) NOT NULL DEFAULT '',
  acct varchar(40) NOT NULL DEFAULT '',
  t datetime NOT NULL,
  who varchar(190) NOT NULL DEFAULT '',
  what varchar(255) NOT NULL DEFAULT '',
  obj varchar(255) NOT NULL DEFAULT '',
  detail text NULL,
  h char(32) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY h (h),
  KEY t (t)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_aw_db_ver', CP_AW_DB_VER, false);
}, 7);

function cp_aw_snaps() { $s = get_option('cp_aw_camps', array()); return is_array($s) ? $s : array(); }
function cp_aw_clip($v, $n = 190) { return mb_substr(sanitize_text_field((string) $v), 0, $n); }
/* változásnapló-sor (ugyanaz kétszer nem kerül be) */
function cp_aw_log($src, $acct, $ts, $who, $what, $obj, $detail = '') {
	if (!cp_aw_ok()) { return; }
	global $wpdb;
	$t = gmdate('Y-m-d H:i:s', (int) $ts);
	$h = md5($src . '|' . $acct . '|' . $t . '|' . $who . '|' . $what . '|' . $obj . '|' . $detail);
	$wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . cp_aw_t() . ' (src, acct, t, who, what, obj, detail, h) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)',
		$src, $acct, $t, mb_substr($who, 0, 190), mb_substr($what, 0, 255), mb_substr($obj, 0, 255), mb_substr($detail, 0, 2000), $h));
}

/* új pillanatkép: a különbségek a naplóba, a régi helyére az új */
function cp_aw_snap_put($key, $acctName, $list, $extra = array()) {
	$all = cp_aw_snaps(); $prev = isset($all[$key]['list']) ? $all[$key]['list'] : null;
	$src = strpos($key, 'meta:') === 0 ? 'meta' : 'google'; $acct = substr($key, strlen($src) + 1);
	if (is_array($prev)) {
		$P = array(); foreach ($prev as $c) { $P[$c['id']] = $c; }
		$L = array(
			'st' => 'állapot', 'bud' => 'napi keret (Ft)', 'bid' => 'licitstratégia', 'net' => 'hálózatok', 'geo' => 'helycélzás módja', 'sfx' => 'URL-utótag (UTM)', 'name' => 'név', 'obj' => 'cél',
		);
		foreach ($list as $c) {
			if (!isset($P[$c['id']])) { cp_aw_log('figyelő', $acct, time(), '', 'Új kampány', $c['name'], 'állapot: ' . $c['st'] . (isset($c['bud']) && $c['bud'] !== null ? ', napi keret: ' . $c['bud'] . ' Ft' : '')); continue; }
			$o = $P[$c['id']]; $d = array();
			foreach ($L as $k => $lbl) { $a = isset($o[$k]) ? (string) $o[$k] : ''; $b = isset($c[$k]) ? (string) $c[$k] : ''; if ($a !== $b) { $d[] = $lbl . ': ' . ($a === '' ? '–' : $a) . ' → ' . ($b === '' ? '–' : $b); } }
			if ($d) { cp_aw_log('figyelő', $acct, time(), '', 'Beállítás változott', $c['name'], implode('; ', $d)); }
			unset($P[$c['id']]);
		}
		foreach ($P as $o) { cp_aw_log('figyelő', $acct, time(), '', 'Kampány törölve / eltűnt', $o['name'], ''); }
	}
	$all[$key] = array('at' => time(), 'name' => $acctName, 'list' => array_values($list)) + $extra;
	update_option('cp_aw_camps', $all, false);
}

/* ------------------------------------------------------------ Google (a szkript küldi) */
function cp_aw_google_in($acct, $b) {
	if (!cp_aw_ok() || $acct === '' || !isset($b['camps']) || !is_array($b['camps'])) { return; }
	$list = array();
	foreach (array_slice($b['camps'], 0, 300) as $c) {
		if (!is_array($c) || empty($c['id'])) { continue; }
		$net = array(); if (!empty($c['search'])) { $net[] = 'keresés'; } if (!empty($c['partners'])) { $net[] = 'keresőpartnerek'; } if (!empty($c['display'])) { $net[] = 'Display'; }
		$list[] = array('id' => cp_aw_clip($c['id'], 30), 'name' => cp_aw_clip($c['name'] ?? ''), 'st' => cp_aw_clip($c['status'] ?? '', 20), 'serv' => cp_aw_clip($c['serving'] ?? '', 30),
			'pst' => cp_aw_clip($c['pstatus'] ?? '', 30), 'why' => cp_aw_clip(is_array($c['preasons'] ?? null) ? implode(',', $c['preasons']) : ($c['preasons'] ?? ''), 300),
			'type' => cp_aw_clip($c['type'] ?? '', 30), 'bid' => cp_aw_clip($c['bidding'] ?? '', 40), 'bud' => isset($c['budget']) && $c['budget'] !== null && $c['budget'] !== '' ? (int) round((float) $c['budget']) : null,
			'net' => implode(', ', $net), 'geo' => cp_aw_clip($c['geo'] ?? '', 30), 'sfx' => cp_aw_clip($c['suffix'] ?? '', 300));
	}
	$cust = isset($b['cust']) && is_array($b['cust']) ? array('sfx' => cp_aw_clip($b['cust']['suffix'] ?? '', 300), 'tpl' => cp_aw_clip($b['cust']['template'] ?? '', 300), 'auto' => !empty($b['cust']['autotag'])) : null;
	cp_aw_snap_put('google:' . $acct, cp_aw_clip($b['acctName'] ?? '', 120), $list, array('cust' => $cust));
	foreach (array_slice((array) ($b['changes'] ?? array()), 0, 1000) as $x) {
		if (!is_array($x) || empty($x['t'])) { continue; }
		$ts = strtotime((string) $x['t'] . (preg_match('/[+-]\d\d:?\d\d$|Z$/', (string) $x['t']) ? '' : ' ' . ($b['tz'] ?? 'Europe/Budapest')));
		if (!$ts) { continue; }
		$what = cp_aw_g_what((string) ($x['res'] ?? ''), (string) ($x['op'] ?? ''));
		$obj = trim(cp_aw_clip($x['camp'] ?? '') . (!empty($x['group']) ? ' › ' . cp_aw_clip($x['group']) : ''));
		$fields = cp_aw_clip(is_array($x['fields'] ?? null) ? implode(', ', $x['fields']) : ($x['fields'] ?? ''), 600);
		cp_aw_log('google', $acct, $ts, cp_aw_clip(($x['who'] ?? '') !== '' ? $x['who'] : cp_aw_g_client((string) ($x['client'] ?? '')), 190), $what, $obj, $fields !== '' ? 'mezők: ' . $fields : '');
	}
}
function cp_aw_g_what($res, $op) {
	$R = array('CAMPAIGN' => 'kampány', 'CAMPAIGN_BUDGET' => 'költségkeret', 'AD_GROUP' => 'hirdetéscsoport', 'AD_GROUP_AD' => 'hirdetés', 'AD' => 'hirdetés', 'AD_GROUP_CRITERION' => 'kulcsszó / célzás (csoport)', 'CAMPAIGN_CRITERION' => 'célzás / kizárás (kampány)',
		'ASSET' => 'eszköz', 'ASSET_GROUP' => 'eszközcsoport', 'ASSET_GROUP_ASSET' => 'eszközcsoport eleme', 'CAMPAIGN_ASSET' => 'kampány-eszköz', 'AD_GROUP_ASSET' => 'csoport-eszköz', 'CUSTOMER_ASSET' => 'fiók-eszköz', 'AD_GROUP_BID_MODIFIER' => 'licitmódosító', 'FEED' => 'feed', 'FEED_ITEM' => 'feed elem', 'CAMPAIGN_FEED' => 'kampány-feed', 'AD_GROUP_FEED' => 'csoport-feed', 'CUSTOMER' => 'fiók', 'ASSET_SET' => 'eszközkészlet', 'CAMPAIGN_ASSET_SET' => 'kampány-eszközkészlet');
	$O = array('CREATE' => 'létrehozva', 'UPDATE' => 'módosítva', 'REMOVE' => 'törölve');
	return ucfirst(($R[$res] ?? strtolower($res)) . ' ' . ($O[$op] ?? strtolower($op)));
}
function cp_aw_g_client($c) { $M = array('GOOGLE_ADS_WEB_CLIENT' => 'Google Ads felület', 'GOOGLE_ADS_AUTOMATED_RULE' => 'automatikus szabály', 'GOOGLE_ADS_SCRIPTS' => 'szkript', 'GOOGLE_ADS_BULK_UPLOAD' => 'tömeges feltöltés', 'GOOGLE_ADS_API' => 'API', 'GOOGLE_ADS_EDITOR' => 'Google Ads Editor', 'GOOGLE_ADS_MOBILE_APP' => 'Google Ads app', 'GOOGLE_ADS_RECOMMENDATIONS' => 'javaslat (automatikus)', 'INTERNAL_TOOL' => 'Google (belső)', 'SEARCH_ADS_360_SYNC' => 'SA360', 'OTHER' => 'egyéb'); return $M[$c] ?? $c; }

/* ------------------------------------------------------------ Meta (a költés-szinkron után) */
function cp_aw_meta_get($tok, $url) {
	$out = array();
	for ($i = 0; $i < 10 && $url; $i++) {
		$res = wp_remote_get($url, array('timeout' => 30, 'headers' => array('Authorization' => 'Bearer ' . $tok)));
		if (is_wp_error($res)) { return $res->get_error_message(); }
		$j = json_decode(wp_remote_retrieve_body($res), true);
		if ((int) wp_remote_retrieve_response_code($res) !== 200 || !is_array($j) || !isset($j['data'])) { return 'HTTP ' . wp_remote_retrieve_response_code($res) . ': ' . (isset($j['error']['message']) ? mb_substr($j['error']['message'], 0, 200) : 'érvénytelen válasz'); }
		$out = array_merge($out, $j['data']);
		$url = isset($j['paging']['next']) ? $j['paging']['next'] : '';
	}
	return $out;
}
function cp_aw_meta_sync($tok, $ver, $acts) {
	if (!cp_aw_ok()) { return; }
	$errs = array();
	foreach ($acts as $act) {
		$base = 'https://graph.facebook.com/' . $ver . '/act_' . $act;
		/* a keretek a pénznem legkisebb egységében jönnek: HUF-nál (és pl. JPY-nál) nincs váltóegység, máshol /100 */
		$cur = get_transient('cp_aw_cur_' . $act);
		if (!$cur) { $ci = wp_remote_get($base . '?fields=currency', array('timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $tok))); $cj = is_wp_error($ci) ? null : json_decode(wp_remote_retrieve_body($ci), true); $cur = is_array($cj) && !empty($cj['currency']) ? (string) $cj['currency'] : 'HUF'; set_transient('cp_aw_cur_' . $act, $cur, DAY_IN_SECONDS); }
		$div = in_array($cur, array('HUF', 'JPY', 'KRW', 'CLP', 'COP', 'CRC', 'ISK', 'IDR', 'PYG', 'TWD', 'VND'), true) ? 1 : 100;
		$camps = cp_aw_meta_get($tok, $base . '/campaigns?' . http_build_query(array('fields' => 'id,name,status,effective_status,objective,daily_budget,lifetime_budget,issues_info,stop_time', 'limit' => 200)));
		if (is_string($camps)) { $errs[] = 'act_' . $act . ': ' . $camps; continue; }
		$sets = cp_aw_meta_get($tok, $base . '/adsets?' . http_build_query(array('fields' => 'campaign_id,daily_budget,lifetime_budget,effective_status', 'limit' => 500)));
		$ads = cp_aw_meta_get($tok, $base . '/ads?' . http_build_query(array('fields' => 'campaign_id,effective_status', 'limit' => 500, 'effective_status' => wp_json_encode(array('DISAPPROVED', 'WITH_ISSUES')))));
		$sb = array(); if (is_array($sets)) { foreach ($sets as $s) { if (($s['effective_status'] ?? '') === 'ACTIVE' && !empty($s['daily_budget'])) { $sb[$s['campaign_id']] = ($sb[$s['campaign_id']] ?? 0) + (int) $s['daily_budget']; } } }
		$bad = array(); if (is_array($ads)) { foreach ($ads as $a) { $bad[$a['campaign_id']] = ($bad[$a['campaign_id']] ?? 0) + 1; } }
		$list = array();
		foreach ($camps as $c) {
			if (in_array($c['effective_status'] ?? '', array('DELETED', 'ARCHIVED'), true)) { continue; }
			$bud = !empty($c['daily_budget']) ? (int) $c['daily_budget'] : ($sb[$c['id']] ?? null);
			$iss = array(); foreach ((array) ($c['issues_info'] ?? array()) as $x) { $iss[] = cp_aw_clip($x['error_summary'] ?? ($x['error_message'] ?? ''), 160); }
			$list[] = array('id' => cp_aw_clip($c['id'], 30), 'name' => cp_aw_clip($c['name'] ?? ''), 'st' => cp_aw_clip($c['status'] ?? '', 20), 'serv' => cp_aw_clip($c['effective_status'] ?? '', 30),
				'obj' => cp_aw_clip($c['objective'] ?? '', 40), 'bud' => $bud !== null ? (int) round($bud / $div) : null, 'life' => !empty($c['lifetime_budget']) ? (int) round($c['lifetime_budget'] / $div) : null,
				'bad' => (int) ($bad[$c['id']] ?? 0), 'iss' => implode(' · ', array_filter($iss)), 'stop' => cp_aw_clip($c['stop_time'] ?? '', 30));
		}
		cp_aw_snap_put('meta:' . $act, '', $list);
		$acts2 = cp_aw_meta_get($tok, $base . '/activities?' . http_build_query(array('fields' => 'event_time,event_type,translated_event_type,actor_name,object_name,object_type,extra_data', 'since' => time() - 14 * DAY_IN_SECONDS, 'limit' => 200)));
		if (is_array($acts2)) {
			foreach ($acts2 as $x) {
				$ts = strtotime((string) ($x['event_time'] ?? '')); if (!$ts) { continue; }
				$ex = ''; if (!empty($x['extra_data'])) { $e = json_decode((string) $x['extra_data'], true); if (is_array($e)) { $o = $e['old_value'] ?? null; $n = $e['new_value'] ?? null; if ($o !== null || $n !== null) { $ex = (is_scalar($o) ? $o : wp_json_encode($o)) . ' → ' . (is_scalar($n) ? $n : wp_json_encode($n)); } } }
				cp_aw_log('meta', $act, $ts, cp_aw_clip($x['actor_name'] ?? ''), cp_aw_clip(($x['translated_event_type'] ?? '') !== '' ? $x['translated_event_type'] : ($x['event_type'] ?? ''), 255), cp_aw_clip($x['object_name'] ?? '', 255), mb_substr(sanitize_text_field($ex), 0, 600));
			}
		} else { $errs[] = 'tevékenységek (act_' . $act . '): ' . $acts2; }
	}
	update_option('cp_aw_meta_err', $errs ? array('at' => time(), 'msg' => implode(' · ', $errs)) : '', false);
	if (wp_rand(1, 24) === 1) { global $wpdb; $wpdb->query($wpdb->prepare('DELETE FROM ' . cp_aw_t() . ' WHERE t < %s', gmdate('Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS))); }
}

/* ------------------------------------------------------------ figyelmeztetések a mostani állapotból */
function cp_aw_has_utm($s) { return stripos((string) $s, 'utm_source') !== false || stripos((string) $s, 'utm_campaign') !== false; }
function cp_aw_alerts() {
	$out = array();
	$why = array('BUDGET_CONSTRAINED' => 'a költségkeret korlátozza', 'BID_STRATEGY_LIMITED' => 'a licitstratégia korlátozza', 'BID_STRATEGY_LEARNING' => 'tanulási időszak', 'BID_STRATEGY_MISCONFIGURED' => 'hibás licitbeállítás', 'BID_STRATEGY_CONSTRAINED' => 'a licitcél korlátozza',
		'HAS_ADS_DISAPPROVED' => 'elutasított hirdetés', 'HAS_ADS_LIMITED_BY_POLICY' => 'irányelv korlátozza a hirdetést', 'MOST_ADS_UNDER_REVIEW' => 'a hirdetések ellenőrzés alatt', 'CAMPAIGN_PENDING' => 'még nem indult', 'CAMPAIGN_ENDED' => 'lejárt', 'CAMPAIGN_PAUSED' => 'szüneteltetve', 'CAMPAIGN_REMOVED' => 'törölve',
		'MISSING_INVOICE_SETUP' => 'nincs számlázás beállítva', 'NOT_ELIGIBLE' => 'nem futhat', 'HAS_ADS_UNDER_REVIEW' => 'hirdetés ellenőrzés alatt', 'ASSET_GROUP_LIMITED_BY_POLICY' => 'irányelv korlátozza az eszközcsoportot', 'ASSET_GROUP_DISAPPROVED' => 'elutasított eszközcsoport', 'ASSET_GROUP_UNDER_REVIEW' => 'eszközcsoport ellenőrzés alatt');
	foreach (cp_aw_snaps() as $key => $s) {
		$isG = strpos($key, 'google:') === 0; $acct = $isG ? substr($key, 7) : substr($key, 5);
		$an = ($isG ? 'Google ' : 'Meta ') . ($s['name'] !== '' ? $s['name'] : $acct);
		if ($isG && time() - (int) $s['at'] > 3 * HOUR_IN_SECONDS) { $out[] = array('lvl' => 'mid', 'acct' => $an, 'camp' => '', 'msg' => 'A Google Ads-szkript ' . round((time() - (int) $s['at']) / 3600) . ' órája nem küldött adatot (fut-e óránként?).'); }
		$cs = $isG && !empty($s['cust']) ? (string) $s['cust']['sfx'] : '';
		foreach ((array) $s['list'] as $c) {
			if ($isG) {
				if ($c['st'] !== 'ENABLED') { continue; }
				$rs = array_filter(explode(',', (string) $c['why']));
				$hard = in_array($c['pst'], array('NOT_ELIGIBLE', 'PAUSED', 'REMOVED', 'ENDED', 'PENDING'), true);
				$bad = array_values(array_intersect($rs, array('BUDGET_CONSTRAINED', 'HAS_ADS_DISAPPROVED', 'ASSET_GROUP_DISAPPROVED', 'MISSING_INVOICE_SETUP', 'BID_STRATEGY_MISCONFIGURED', 'HAS_ADS_LIMITED_BY_POLICY', 'ASSET_GROUP_LIMITED_BY_POLICY')));
				if ($hard || $bad) { $out[] = array('lvl' => $hard || in_array('HAS_ADS_DISAPPROVED', $bad, true) || in_array('MISSING_INVOICE_SETUP', $bad, true) ? 'bad' : 'mid', 'acct' => $an, 'camp' => $c['name'], 'msg' => implode(', ', array_map(function ($r) use ($why) { return $why[$r] ?? $r; }, $hard ? ($rs ?: array($c['pst'])) : $bad))); }
				if (!cp_aw_has_utm($c['sfx']) && !cp_aw_has_utm($cs)) { $out[] = array('lvl' => 'mid', 'acct' => $an, 'camp' => $c['name'], 'msg' => 'nincs UTM az URL-utótagban – a Salesmate-ben nem azonosítható a kampány'); }
				if (strpos($c['net'], 'Display') !== false && $c['type'] === 'SEARCH') { $out[] = array('lvl' => 'mid', 'acct' => $an, 'camp' => $c['name'], 'msg' => 'keresőkampányban be van kapcsolva a Display-hálózat'); }
				if ($c['geo'] === 'PRESENCE_OR_INTEREST') { $out[] = array('lvl' => 'mid', 'acct' => $an, 'camp' => $c['name'], 'msg' => 'helycélzás: „Jelenlét vagy érdeklődés” (javasolt: Jelenlét)'); }
			} else {
				if ($c['st'] !== 'ACTIVE') { continue; }
				$E = array('DISAPPROVED' => 'elutasítva', 'WITH_ISSUES' => 'hibás', 'PENDING_REVIEW' => 'ellenőrzés alatt', 'PENDING_BILLING_INFO' => 'számlázási adat hiányzik', 'ADSET_PAUSED' => 'minden hirdetéskészlete szünetel', 'IN_PROCESS' => 'feldolgozás alatt');
				if (isset($E[$c['serv']])) { $out[] = array('lvl' => in_array($c['serv'], array('PENDING_REVIEW', 'IN_PROCESS'), true) ? 'mid' : 'bad', 'acct' => $an, 'camp' => $c['name'], 'msg' => $E[$c['serv']]); }
				if ($c['bad']) { $out[] = array('lvl' => 'bad', 'acct' => $an, 'camp' => $c['name'], 'msg' => $c['bad'] . ' elutasított / hibás hirdetés'); }
				if ($c['iss'] !== '') { $out[] = array('lvl' => 'bad', 'acct' => $an, 'camp' => $c['name'], 'msg' => $c['iss']); }
			}
		}
	}
	$me = get_option('cp_aw_meta_err'); if (is_array($me) && !empty($me['msg'])) { $out[] = array('lvl' => 'mid', 'acct' => 'Meta', 'camp' => '', 'msg' => 'Lekérdezési hiba: ' . $me['msg']); }
	return $out;
}

add_action('wp_ajax_nopriv_cp_aw_data', 'cp_aw_ajax_data'); add_action('wp_ajax_cp_aw_data', 'cp_aw_ajax_data');
function cp_aw_ajax_data() {
	cp_st_auth();
	if (!cp_aw_ok()) { cp_el_err('Az adatbázis még készül.', 503); }
	global $wpdb;
	$ch = $wpdb->get_results($wpdb->prepare('SELECT src, acct, t, who, what, obj, detail FROM ' . cp_aw_t() . ' WHERE t >= %s ORDER BY t DESC, id DESC LIMIT 400', gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)), ARRAY_A);
	foreach ($ch as &$r) { $r['t'] = (int) strtotime($r['t'] . ' UTC'); }
	$sn = array(); foreach (cp_aw_snaps() as $k => $s) { $sn[] = array('key' => $k, 'src' => strpos($k, 'meta:') === 0 ? 'meta' : 'google', 'acct' => preg_replace('/^[a-z]+:/', '', $k), 'name' => (string) $s['name'], 'at' => (int) $s['at'], 'cust' => $s['cust'] ?? null, 'list' => $s['list']); }
	wp_send_json_success(array('snaps' => $sn, 'changes' => $ch, 'alerts' => cp_aw_alerts(), 'script' => function_exists('cp_st_gads_script') ? cp_st_gads_script() : '', 'now' => time()));
}
