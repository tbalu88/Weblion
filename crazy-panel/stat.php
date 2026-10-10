<?php
/* =====================================================================
 * STATISZTIKÁK (v1.15) – Statisztikák fül
 * Salesmate → saját táblák óránként ({prefix}crazy_st_deals / _contacts / _acts),
 * hirdetési költség ({prefix}crazy_st_ads: Google Ads-szkript, Meta Marketing API, LinkedIn kézi),
 * GA4 Data API (szolgáltatásfiók, JWT RS256), mutatók a fülnek.
 * Beállítások: cp_st_settings (nem titkos), cp_st_secrets (titkosítva; csak a WP adminban írható).
 * Minden időpont UTC-ben (Y-m-d H:i:s), a *_d mezők helyi (Europe/Budapest) dátumok.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_ST_DB_VER', '4');
define('CP_ST_OPT', 'cp_st_settings');
define('CP_ST_SEC', 'cp_st_secrets');
define('CP_ST_STATE', 'cp_st_state');
define('CP_ST_LI', 'cp_st_li');
define('CP_ST_TZ', 'Europe/Budapest');

function cp_st_t($name) {
	global $wpdb;
	return $wpdb->prefix . 'crazy_st_' . $name;
}

/* ------------------------------------------------------------ beállítások */

function cp_st_defaults() {
	return array(
		'f_svc'       => 'textCustomField13',
		'f_req'       => "multiSelectCustomField3\nmultiSelectCustomField4",
		'f_ctype'     => 'textCustomField11',
		'ctype_biz'   => 'KFT',
		'ctype_priv'  => 'EV',
		'c_evtype'    => 'textCustomField2',
		'c_ad'        => 'textCustomField3',
		'offer_from'  => 'Ajánlat kiküldve',
		'busy_re'     => 'foglalt|nincs szabad|kapacit|betelt',
		'paper_ft'    => 0,
		'ai_pack_ft'  => 58800,
		'ai_pack_cr'  => 10000,
		'ai_cr_img'   => 10,
		'ai_re'       => '\\bai\\b',
		'meta_act'    => '',
		'meta_ver'    => 'v24.0',
		'ga_prop'     => '',
		'li_act'      => '559891788',
		'eur_huf'     => 390,
		'svc_alias'   => "Szelfigép = Fotóautomata\nSzelfi gép = Fotóautomata\nSzelfi = Fotóautomata",
		'rules'       => "source:facebook => meta\nsource:instagram => meta\nsource:meta => meta\nsource:linkedin => linkedin\ntag:linkedin => linkedin\nutm:facebook => meta\nutm:fb => meta\nutm:ig => meta\nutm:instagram => meta\nutm:meta => meta\nutm:linkedin => linkedin\nutm:google => google\nsource:google => google\nsource:referral => referral\nsource:ajánl => referral\nsource:ads => ads\nsource:crazyphoto => organic\nsource:eventcrazy => organic\nsource:spinner => organic\nsource:web => organic",
	);
}

function cp_st_opts() {
	static $c = null;
	if ($c !== null) { return $c; }
	$o = get_option(CP_ST_OPT, array());
	$c = array_merge(cp_st_defaults(), is_array($o) ? $o : array());
	return $c;
}

function cp_st_save_opts($o) {
	update_option(CP_ST_OPT, $o, false);
}

/* titkok: AES-256-CBC, a kulcs a WordPress sóiból képzett (az adatbázis-mentésből önmagában nem olvasható) */
function cp_st_ck() {
	return hash('sha256', 'cp_st|' . wp_salt('secure_auth') . wp_salt('logged_in'), true);
}

function cp_st_enc($plain) {
	if ($plain === '' || !function_exists('openssl_encrypt')) { return ''; }
	$iv = random_bytes(16);
	$c = openssl_encrypt($plain, 'aes-256-cbc', cp_st_ck(), OPENSSL_RAW_DATA, $iv);
	return $c === false ? '' : 'v1:' . base64_encode($iv . $c);
}

function cp_st_dec($s) {
	if (!is_string($s) || strpos($s, 'v1:') !== 0) { return ''; }
	$raw = base64_decode(substr($s, 3), true);
	if ($raw === false || strlen($raw) < 17) { return ''; }
	$p = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', cp_st_ck(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
	return $p === false ? '' : $p;
}

/* a kulcsnevek szándékosan „secret”/„token” szót tartalmaznak (bármilyen kiolvasásnál maszkolódnak) */
function cp_st_secret($k) {
	$s = get_option(CP_ST_SEC, array());
	return (is_array($s) && isset($s[$k])) ? cp_st_dec($s[$k]) : '';
}

function cp_st_secret_set($k, $v) {
	$s = get_option(CP_ST_SEC, array());
	if (!is_array($s)) { $s = array(); }
	if ($v === '') { unset($s[$k]); } else { $s[$k] = cp_st_enc($v); }
	update_option(CP_ST_SEC, $s, false);
}

function cp_st_has_secret($k) {
	$s = get_option(CP_ST_SEC, array());
	return is_array($s) && !empty($s[$k]);
}

function cp_st_state() {
	$s = get_option(CP_ST_STATE, array());
	return is_array($s) ? $s : array();
}

function cp_st_state_set($s) {
	update_option(CP_ST_STATE, $s, false);
}

/* ------------------------------------------------------------ adatbázis */

add_action('plugins_loaded', 'cp_st_db_maybe_upgrade', 6);
function cp_st_db_maybe_upgrade() {
	if (get_option('cp_st_db_ver') === CP_ST_DB_VER) { return; }
	if (get_transient('cp_st_db_lock')) { return; }
	set_transient('cp_st_db_lock', 1, 60);
	cp_st_db_install();
	update_option('cp_st_db_ver', CP_ST_DB_VER, false);
	/* a felmérés ideiglenes eredményei */
	foreach (array('cp_stat_probe', 'cp_stat_probe2', 'cp_stat_probe3', 'cp_stat_probe4', 'cp_stat_probe5', 'cp_stat_probe6') as $x) { delete_option($x); }
	delete_transient('cp_st_db_lock');
}

function cp_st_db_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta("CREATE TABLE " . cp_st_t('deals') . " (
  id bigint(20) unsigned NOT NULL,
  contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  created_d date NOT NULL,
  status varchar(12) NOT NULL DEFAULT '',
  stage varchar(80) NOT NULL DEFAULT '',
  closed_stage varchar(80) NOT NULL DEFAULT '',
  closed_at datetime NULL,
  signed_d date NULL,
  signed_src varchar(8) NOT NULL DEFAULT '',
  source varchar(80) NOT NULL DEFAULT '',
  tags varchar(190) NOT NULL DEFAULT '',
  svc varchar(190) NOT NULL DEFAULT '',
  svc_req varchar(255) NOT NULL DEFAULT '',
  value decimal(14,2) NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT '',
  event_d date NULL,
  ctype varchar(30) NOT NULL DEFAULT '',
  kind varchar(8) NOT NULL DEFAULT '',
  lost_reason varchar(100) NOT NULL DEFAULT '',
  owner varchar(60) NOT NULL DEFAULT '',
  first_mail_at datetime NULL,
  modified_at datetime NULL,
  seen_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_d (created_d),
  KEY signed_d (signed_d),
  KEY event_d (event_d),
  KEY contact_id (contact_id)
) $c;");
	dbDelta("CREATE TABLE " . cp_st_t('contacts') . " (
  id bigint(20) unsigned NOT NULL,
  utm_source varchar(100) NOT NULL DEFAULT '',
  utm_medium varchar(100) NOT NULL DEFAULT '',
  utm_campaign varchar(190) NOT NULL DEFAULT '',
  utm_term varchar(190) NOT NULL DEFAULT '',
  utm_content varchar(190) NOT NULL DEFAULT '',
  ev_type varchar(100) NOT NULL DEFAULT '',
  ad_name varchar(190) NOT NULL DEFAULT '',
  company tinyint(1) NOT NULL DEFAULT 0,
  mail_sent_at datetime NULL,
  seen_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;");
	dbDelta("CREATE TABLE " . cp_st_t('acts') . " (
  id bigint(20) unsigned NOT NULL,
  deal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(60) NOT NULL DEFAULT '',
  done_at datetime NULL,
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY deal_id (deal_id)
) $c;");
	dbDelta("CREATE TABLE " . cp_st_t('ads') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  d date NOT NULL,
  channel varchar(20) NOT NULL,
  campaign_id varchar(64) NOT NULL,
  campaign_name varchar(190) NOT NULL DEFAULT '',
  spend decimal(14,2) NOT NULL DEFAULT 0,
  spend_orig decimal(14,2) NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT '',
  clicks int(11) NOT NULL DEFAULT 0,
  impressions int(11) NOT NULL DEFAULT 0,
  conversions decimal(12,2) NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dcc (d,channel,campaign_id),
  KEY channel (channel)
) $c;");
}

function cp_st_db_ok() {
	return get_option('cp_st_db_ver') === CP_ST_DB_VER;
}

/* ------------------------------------------------------------ segédfüggvények */

function cp_st_tz() {
	static $tz = null;
	if (!$tz) { $tz = new DateTimeZone(CP_ST_TZ); }
	return $tz;
}

/* Salesmate időpont (ms timestamp vagy ISO) → unix ts; 0 ha üres */
function cp_st_ts($v) {
	if ($v === null || $v === '' || is_array($v)) { return 0; }
	if (is_numeric($v)) {
		$t = (float) $v;
		if ($t > 100000000000) { $t = $t / 1000; }
		return $t > 0 ? (int) round($t) : 0;
	}
	$t = strtotime((string) $v);
	return $t ? (int) $t : 0;
}

function cp_st_utc($ts) {
	return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
}

function cp_st_local_d($ts) {
	if (!$ts) { return null; }
	$d = new DateTime('@' . $ts);
	$d->setTimezone(cp_st_tz());
	return $d->format('Y-m-d');
}

function cp_st_today() {
	$d = new DateTime('now', cp_st_tz());
	return $d->format('Y-m-d');
}

/* „EC+karácsonyi+kampány+2026” → „EC karácsonyi kampány 2026” (az űrlap URL-kódolva menti) */
function cp_st_clean($v, $len) {
	$s = cp_panel_str($v);
	if (strpos($s, '%') !== false) { $d = rawurldecode($s); if (mb_check_encoding($d, 'UTF-8')) { $s = $d; } }
	$s = trim(preg_replace('/\s+/u', ' ', str_replace('+', ' ', $s)));
	return mb_substr($s, 0, $len);
}

function cp_st_multi($v) {
	if (is_array($v)) { $v = implode(',', array_map('cp_panel_str', $v)); }
	$out = array();
	foreach (preg_split('/[,;|\n]+/u', cp_panel_str($v)) as $x) {
		$x = trim($x);
		if ($x !== '' && !in_array($x, $out, true)) { $out[] = $x; }
	}
	return $out;
}

function cp_st_lines($s) {
	return function_exists('cp_panel_lines') ? cp_panel_lines($s) : array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $s)));
}

/* sorok beszúrása/frissítése: INSERT … ON DUPLICATE KEY UPDATE (a nem felsorolt oszlopok megmaradnak) */
function cp_st_upsert($table, $cols, $rows, $update) {
	global $wpdb;
	if (!$rows) { return 0; }
	$n = 0;
	foreach (array_chunk($rows, 100) as $chunk) {
		$vals = array();
		$args = array();
		foreach ($chunk as $r) {
			$ph = array();
			foreach ($cols as $c) {
				$v = isset($r[$c]) ? $r[$c] : null;
				if ($v === null) { $ph[] = 'NULL'; continue; }
				$ph[] = is_int($v) ? '%d' : (is_float($v) ? '%f' : '%s');
				$args[] = $v;
			}
			$vals[] = '(' . implode(',', $ph) . ')';
		}
		$up = array();
		foreach ($update as $c) { $up[] = "`$c`=VALUES(`$c`)"; }
		$sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', $cols) . '`) VALUES ' . implode(',', $vals) . ' ON DUPLICATE KEY UPDATE ' . implode(',', $up);
		$wpdb->query($args ? $wpdb->prepare($sql, $args) : $sql);
		$n += count($chunk);
	}
	return $n;
}

/* ------------------------------------------------------------ Salesmate szinkron */

function cp_st_deal_fields() {
	$p = cp_panel_opts();
	$o = cp_st_opts();
	$f = array('id', 'createdAt', 'status', 'stage', 'closedStage', 'closedDate', 'source', 'tags', 'dealValue', 'currency', 'primaryContact', 'lostReason', 'owner', 'lastModifiedAt', 'pipeline');
	foreach (array($p['f_date'], $p['f_selfie'], $p['f_spin'], $o['f_svc'], $o['f_ctype']) as $k) { if ($k !== '') { $f[] = $k; } }
	foreach (cp_st_lines($o['f_req']) as $k) { $f[] = $k; }
	return array_map(function ($k) { return 'deal.' . $k; }, array_values(array_unique($f)));
}

function cp_st_search($module, $mid, $fields, $rules, $page, $rows = 250) {
	$body = array(
		'displayingFields' => $fields,
		'filterQuery'      => array('group' => array('operator' => 'AND', 'rules' => $rules)),
		'sort'             => array('fieldName' => $module . '.id', 'order' => 'desc'),
		'moduleId'         => $mid,
		'reportType'       => 'get_data',
		'getRecordsCount'  => true,
	);
	$j = cp_sm_request('POST', '/apis/' . $module . '/v4/search?rows=' . (int) $rows . '&from=' . ((int) $page * $rows), $body);
	if (is_wp_error($j)) { return $j; }
	return cp_sm_rows($j);
}

function cp_st_win_rule($module, $f, $t) {
	$M = ucfirst($module);
	$fld = array('fieldName' => $module . '.createdAt', 'type' => 'DateTime');
	return array(
		array('moduleName' => $M, 'field' => $fld, 'condition' => 'IS_AFTER', 'data' => gmdate('Y-m-d', strtotime($f . ' -1 day')), 'eventType' => 'DateTime'),
		array('moduleName' => $M, 'field' => $fld, 'condition' => 'IS_BEFORE', 'data' => gmdate('Y-m-d', strtotime($t . ' +1 day')), 'eventType' => 'DateTime'),
	);
}

function cp_st_mod_rule($module, $since) {
	$M = ucfirst($module);
	return array(array('moduleName' => $M, 'field' => array('fieldName' => $module . '.lastModifiedAt', 'type' => 'DateTime'), 'condition' => 'IS_AFTER', 'data' => $since, 'eventType' => 'DateTime'));
}

/* egy sor (keresési eredmény) → tábla-sor */
function cp_st_map_deal($r, $now) {
	$p = cp_panel_opts();
	$o = cp_st_opts();
	$id = (int) cp_panel_str(cp_panel_get($r, 'id'));
	if ($id <= 0) { return null; }
	$cts = cp_st_ts(cp_panel_get($r, 'createdAt'));
	if (!$cts) { return null; }
	$pc = cp_panel_get($r, 'primaryContact');
	$cid = 0;
	if (is_array($pc) && isset($pc['id'])) { $cid = (int) $pc['id']; }
	elseif (is_numeric($pc)) { $cid = (int) $pc; }
	elseif (isset($r['primaryContact.id'])) { $cid = (int) $r['primaryContact.id']; }
	$cur = cp_panel_get($r, 'currency');
	if (is_array($cur)) { $cur = isset($cur['code']) ? $cur['code'] : ''; }
	$req = array();
	foreach (cp_st_lines($o['f_req']) as $k) { $req = array_merge($req, cp_st_multi(cp_panel_get($r, $k))); }
	$req = array_values(array_unique($req));
	$sp = cp_panel_str(cp_panel_get($r, $p['f_selfie']));
	$spin = cp_panel_str(cp_panel_get($r, $p['f_spin']));
	$svc = cp_panel_str(cp_panel_get($r, $o['f_svc']));
	$kind = ($spin !== '' && $sp !== '') ? 'both' : ($spin !== '' ? 'spin' : ($sp !== '' ? 'selfie' : ''));
	if ($kind === '' && $svc !== '') {
		$s = mb_strtolower($svc);
		$self = strpos($s, 'fotóautomata') !== false || strpos($s, 'szelfi') !== false;
		$kind = (strpos($s, 'spinner') !== false && ($self || strpos($s, 'fotó') !== false)) ? 'both' : (strpos($s, 'spinner') !== false ? 'spin' : ($self ? 'selfie' : ''));
	}
	$clts = cp_st_ts(cp_panel_get($r, 'closedDate'));
	$status = cp_panel_str(cp_panel_get($r, 'status'));
	$own = cp_panel_get($r, 'owner');
	return array(
		'id'           => $id,
		'contact_id'   => $cid,
		'created_at'   => cp_st_utc($cts),
		'created_d'    => cp_st_local_d($cts),
		'status'       => mb_substr($status, 0, 12),
		'stage'        => mb_substr(cp_panel_str(cp_panel_get($r, 'stage')), 0, 80),
		'closed_stage' => mb_substr(cp_panel_str(cp_panel_get($r, 'closedStage')), 0, 80),
		'closed_at'    => ($status === 'Won' || $status === 'Lost') && $clts ? cp_st_utc($clts) : null,
		'source'       => mb_substr(cp_panel_str(cp_panel_get($r, 'source')), 0, 80),
		'tags'         => mb_substr(implode(',', cp_st_multi(cp_panel_get($r, 'tags'))), 0, 190),
		'svc'          => mb_substr($svc, 0, 190),
		'svc_req'      => mb_substr(implode('|', $req), 0, 255),
		'value'        => (float) cp_panel_str(cp_panel_get($r, 'dealValue')),
		'currency'     => mb_substr(cp_panel_str($cur), 0, 3),
		'event_d'      => cp_panel_norm_date(cp_panel_get($r, $p['f_date'])) ?: null,
		'ctype'        => mb_substr(cp_panel_str(cp_panel_get($r, $o['f_ctype'])), 0, 30),
		'kind'         => $kind,
		'lost_reason'  => mb_substr(cp_panel_str(cp_panel_get($r, 'lostReason')), 0, 100),
		'owner'        => mb_substr(is_array($own) ? (isset($own['id']) ? (string) $own['id'] : cp_panel_str($own)) : cp_panel_str($own), 0, 60),
		'modified_at'  => cp_st_utc(cp_st_ts(cp_panel_get($r, 'lastModifiedAt'))),
		'seen_at'      => $now,
	);
}

/* aláírás dátuma: első észlelés („seen”) > ügyfélportál létrehozása > Won lezárás > becsült (utolsó módosítás) */
function cp_st_signed_apply(&$rows, $backfill) {
	global $wpdb;
	if (!$rows) { return; }
	$p = cp_panel_opts();
	$signed = array_map('cp_panel_lc', cp_st_lines($p['signed_stages']));
	$ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
	$in = implode(',', array_map('intval', $ids));
	$old = array();
	foreach ((array) $wpdb->get_results('SELECT id, signed_d, signed_src FROM ' . cp_st_t('deals') . " WHERE id IN ($in)", ARRAY_A) as $x) { $old[(int) $x['id']] = $x; }
	$portal = array();
	if (function_exists('cp_portal_t') && function_exists('cp_portal_db_ready') && cp_portal_db_ready()) {
		$sin = implode(',', array_map(function ($i) { return "'" . (int) $i . "'"; }, $ids));
		foreach ((array) $wpdb->get_results('SELECT deal_id, created_at FROM ' . cp_portal_t('portals') . " WHERE deal_id IN ($sin)", ARRAY_A) as $x) { $portal[(int) $x['deal_id']] = cp_st_local_d(strtotime($x['created_at'] . ' UTC')); }
	}
	$rank = array('seen' => 4, 'portal' => 3, 'won' => 2, 'nodate' => 0, '' => 0);
	$today = cp_st_today();
	foreach ($rows as &$r) {
		$id = (int) $r['id'];
		$o = isset($old[$id]) ? $old[$id] : array('signed_d' => null, 'signed_src' => '');
		$is_signed = $r['status'] === 'Won' || ($r['status'] !== 'Lost' && in_array(cp_panel_lc($r['stage']), $signed, true));
		$cand = array();
		if (!empty($o['signed_d']) && isset($rank[$o['signed_src']]) && $o['signed_src'] !== 'nodate') { $cand[$o['signed_src']] = $o['signed_d']; }
		if ($is_signed) {
			if (!empty($portal[$id])) { $cand['portal'] = $portal[$id]; }
			if ($r['status'] === 'Won' && $r['closed_at']) { $cand['won'] = cp_st_local_d(strtotime($r['closed_at'] . ' UTC')); }
			if (empty($o['signed_d']) && !$backfill && $r['status'] !== 'Won' && $o['signed_src'] !== 'nodate') { $cand['seen'] = $today; }
		} elseif ($r['status'] === 'Open') {
			$cand = array(); /* visszaállított szakasz: már nem aláírt */
		}
		$best = '';
		foreach ($cand as $src => $d) { if ($rank[$src] > $rank[$best]) { $best = $src; } }
		/* az ügyfélportál az aláíráskor jön létre: ha korábbi, mint az „első észlelés”, az a pontosabb */
		if ($best === 'seen' && !empty($cand['portal']) && $cand['portal'] < $cand['seen']) { $best = 'portal'; }
		$r['signed_d'] = $best !== '' ? $cand[$best] : null;
		$r['signed_src'] = $best;
		/* aláírt, de a dátuma nem ismert (a szinkron előtt írták alá, nincs portál / Won dátum): nem becsüljük, nem számoljuk */
		if ($best === '' && $is_signed) { $r['signed_src'] = 'nodate'; }
	}
	unset($r);
}

function cp_st_store_deals($raw, $backfill) {
	$now = gmdate('Y-m-d H:i:s');
	$p = cp_panel_opts();
	$pl = cp_panel_lc($p['pipeline']);
	$rows = array();
	foreach ($raw as $r) {
		$m = cp_st_map_deal($r, $now);
		if (!$m) { continue; }
		$rows[$m['id']] = $m;
	}
	$rows = array_values($rows);
	cp_st_signed_apply($rows, $backfill);
	$cols = array('id', 'contact_id', 'created_at', 'created_d', 'status', 'stage', 'closed_stage', 'closed_at', 'signed_d', 'signed_src', 'source', 'tags', 'svc', 'svc_req', 'value', 'currency', 'event_d', 'ctype', 'kind', 'lost_reason', 'owner', 'modified_at', 'seen_at');
	return cp_st_upsert(cp_st_t('deals'), $cols, $rows, array_diff($cols, array('id')));
}

function cp_st_store_contacts($raw) {
	$o = cp_st_opts();
	$now = gmdate('Y-m-d H:i:s');
	$rows = array();
	foreach ($raw as $r) {
		$id = (int) cp_panel_str(isset($r['id']) ? $r['id'] : '');
		if ($id <= 0) { continue; }
		$g = function ($k) use ($r) { return isset($r[$k]) ? $r[$k] : (isset($r['contact.' . $k]) ? $r['contact.' . $k] : null); };
		$ms = cp_st_ts($g('lastEmailSentAt'));
		$co = $g('company');
		$rows[$id] = array(
			'id'           => $id,
			'utm_source'   => cp_st_clean($g('utmSource'), 100),
			'utm_medium'   => cp_st_clean($g('utmMedium'), 100),
			'utm_campaign' => cp_st_clean($g('utmCampaign'), 190),
			'utm_term'     => cp_st_clean($g('utmTerm'), 190),
			'utm_content'  => cp_st_clean($g('utmContent'), 190),
			'ev_type'      => $o['c_evtype'] !== '' ? str_replace('_', ' ', cp_st_clean($g($o['c_evtype']), 100)) : '',
			'ad_name'      => $o['c_ad'] !== '' ? cp_st_clean($g($o['c_ad']), 190) : '',
			'company'      => (is_array($co) ? !empty($co) : cp_panel_str($co) !== '') ? 1 : 0,
			'mail_sent_at' => cp_st_utc($ms),
			'seen_at'      => $now,
		);
	}
	$cols = array('id', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'ev_type', 'ad_name', 'company', 'mail_sent_at', 'seen_at');
	return cp_st_upsert(cp_st_t('contacts'), $cols, array_values($rows), array_diff($cols, array('id')));
}

function cp_st_contact_fields() {
	$o = cp_st_opts();
	$f = array('id', 'utmSource', 'utmMedium', 'utmCampaign', 'utmTerm', 'utmContent', 'lastEmailSentAt', 'company', 'lastModifiedAt');
	foreach (array($o['c_evtype'], $o['c_ad']) as $k) { if ($k !== '') { $f[] = $k; } }
	return array_map(function ($k) { return 'contact.' . $k; }, array_values(array_unique($f)));
}

function cp_st_store_acts($raw) {
	$rows = array();
	foreach ($raw as $r) {
		$id = (int) cp_panel_str(isset($r['id']) ? $r['id'] : '');
		if ($id <= 0) { continue; }
		$mod = cp_panel_str(isset($r['relatedToModule']) ? $r['relatedToModule'] : '');
		if ($mod !== '4' && cp_panel_lc($mod) !== 'deal') { continue; }
		$done = !empty($r['isCompleted']) && (string) $r['isCompleted'] !== '0' ? cp_st_ts(isset($r['completedAt']) ? $r['completedAt'] : '') : 0;
		$rows[$id] = array(
			'id'         => $id,
			'deal_id'    => (int) cp_panel_str(isset($r['relatedTo']) ? $r['relatedTo'] : ''),
			'type'       => mb_substr(cp_panel_str(isset($r['type']) ? $r['type'] : ''), 0, 60),
			'done_at'    => cp_st_utc($done),
			'created_at' => cp_st_utc(cp_st_ts(isset($r['createdAt']) ? $r['createdAt'] : '')),
		);
	}
	$cols = array('id', 'deal_id', 'type', 'done_at', 'created_at');
	return cp_st_upsert(cp_st_t('acts'), $cols, array_values($rows), array('deal_id', 'type', 'done_at', 'created_at'));
}

/* első kimenő e-mail: a szinkron indulása után létrejött dealeknél a kontakt „utolsó küldött e-mail” idejének
 * első olyan értéke, amely a deal létrehozása utáni (óránkénti figyelés – visszamenőleg nem állítható elő) */
function cp_st_mail_capture() {
	global $wpdb;
	$st = cp_st_state();
	if (empty($st['mail_from'])) { return; }
	$d = cp_st_t('deals');
	$c = cp_st_t('contacts');
	$wpdb->query($wpdb->prepare("UPDATE $d x JOIN $c k ON k.id = x.contact_id SET x.first_mail_at = k.mail_sent_at WHERE x.first_mail_at IS NULL AND x.created_at >= %s AND k.mail_sent_at IS NOT NULL AND k.mail_sent_at >= x.created_at AND x.created_at >= %s", $st['mail_from'], gmdate('Y-m-d H:i:s', time() - 21 * DAY_IN_SECONDS)));
}

/* egy szinkron-kör: a teljes beolvasás lapokban halad (óránként legfeljebb ~20 Salesmate-hívás), utána a változások */
function cp_st_sync($manual = false) {
	if (!function_exists('cp_sm_request') || !cp_st_db_ok()) { return 'Az adatbázis még nem kész.'; }
	if (get_transient('cp_st_sync_lock')) { return 'Már fut egy szinkron.'; }
	set_transient('cp_st_sync_lock', 1, 10 * MINUTE_IN_SECONDS);
	@set_time_limit(240);
	$st = cp_st_state();
	/* v2: ablakos teljes beolvasás – a korábbi (hiányos, instabil lapozású) beolvasás eredményét egyszer újratöltjük */
	if (empty($st['scanv']) || (int) $st['scanv'] < 2) {
		global $wpdb;
		foreach (array('deals', 'contacts', 'acts') as $tb) { $wpdb->query('TRUNCATE TABLE ' . cp_st_t($tb)); }
		$st = array('scanv' => 2, 'mail_from' => isset($st['mail_from']) ? $st['mail_from'] : '');
		if ($st['mail_from'] === '') { unset($st['mail_from']); }
		cp_st_state_set($st);
	}
	$t0 = microtime(true);
	$calls = 0;
	$log = array();
	$budget = $manual ? 14 : 24;
	try {
		/* napi teljes újraolvasás (törölt / átírt régi dealek miatt) */
		foreach (array('deal', 'contact', 'act') as $k) {
			if (!empty($st[$k . '_full_at']) && empty($st[$k . '_redo']) && time() - (int) $st[$k . '_full_at'] > DAY_IN_SECONDS) {
				$st[$k . '_page'] = 0; $st[$k . '_redo'] = 1; $st[$k . '_run'] = gmdate('Y-m-d H:i:s');
			}
		}
		$jobs = array(
			'deal'    => array('deal', 4, cp_st_deal_fields()),
			'contact' => array('contact', 1, cp_st_contact_fields()),
			'act'     => array('activity', 3, array('activity.id', 'activity.type', 'activity.isCompleted', 'activity.completedAt', 'activity.createdAt', 'activity.relatedTo', 'activity.relatedToModule')),
		);
		foreach ($jobs as $k => $j) {
			$full = empty($st[$k . '_full_at']) || !empty($st[$k . '_redo']);
			if ($full) {
				/* A Salesmate keresés lapozása nem stabil (a rendezést nem tartja), ezért a teljes beolvasás
				 * létrehozási dátum szerinti ablakokban halad visszafelé; a 250 soros (telített) ablakot megfelezzük. */
				if (empty($st[$k . '_run'])) { $st[$k . '_run'] = gmdate('Y-m-d H:i:s'); }
				$cur = !empty($st[$k . '_cur']) ? $st[$k . '_cur'] : gmdate('Y-m-d', strtotime(cp_st_today() . ' +1 day'));
				$span = !empty($st[$k . '_span']) ? (int) $st[$k . '_span'] : 31;
				$done = false;
				while ($calls < $budget) {
					$wf = gmdate('Y-m-d', strtotime($cur . ' -' . ($span - 1) . ' days'));
					$rows = cp_st_search($j[0], $j[1], $j[2], cp_st_win_rule($j[0], $wf, $cur), 0);
					$calls++;
					if (is_wp_error($rows)) { throw new Exception($k . ': ' . $rows->get_error_message()); }
					if (count($rows) >= 250 && $span > 1) { $span = (int) ceil($span / 2); continue; }
					if (count($rows) >= 250) {
						/* egyetlen napon 250+ sor: lapozás (ritka) */
						for ($pg = 1; $pg < 12 && $calls < $budget + 10; $pg++) {
							$more = cp_st_search($j[0], $j[1], $j[2], cp_st_win_rule($j[0], $wf, $cur), $pg);
							$calls++;
							if (is_wp_error($more)) { break; }
							$rows = array_merge($rows, $more);
							if (count($more) < 250) { break; }
						}
					}
					if ($k === 'deal') { cp_st_store_deals($rows, empty($st['deal_full_at'])); }
					elseif ($k === 'contact') { cp_st_store_contacts($rows); }
					else { cp_st_store_acts($rows); }
					$st[$k . '_page'] = (isset($st[$k . '_page']) ? (int) $st[$k . '_page'] : 0) + 1;
					$cur = gmdate('Y-m-d', strtotime($wf . ' -1 day'));
					if (count($rows) < 80) { $span = min(730, $span * 2); }
					$st[$k . '_cur'] = $cur;
					$st[$k . '_span'] = $span;
					if ($cur < '2014-01-01') { $done = true; break; }
				}
				if ($done) {
					/* a teljes beolvasásban nem látott sorok törölve lettek a Salesmate-ben */
					if (!empty($st[$k . '_redo']) && $k !== 'act') {
						global $wpdb;
						$tab = cp_st_t($k === 'deal' ? 'deals' : 'contacts');
						$wpdb->query($wpdb->prepare("DELETE FROM $tab WHERE seen_at < %s", gmdate('Y-m-d H:i:s', strtotime($st[$k . '_run'] . ' UTC') - 3600)));
					}
					$log[] = $k . ': teljes beolvasás kész (' . (int) $st[$k . '_page'] . ' ablak)';
					$st[$k . '_full_at'] = time();
					$st[$k . '_page'] = 0;
					unset($st[$k . '_redo'], $st[$k . '_run'], $st[$k . '_cur'], $st[$k . '_span']);
					if ($k === 'deal' && empty($st['mail_from'])) { $st['mail_from'] = gmdate('Y-m-d H:i:s'); }
				} else {
					$log[] = $k . ': teljes beolvasás folyamatban (' . $cur . '-ig visszafelé)';
				}
			}
			/* változások: az utolsó sikeres kör óta módosultak (egy nap ráhagyással) */
			if (!empty($st[$k . '_full_at']) && empty($st[$k . '_redo']) && $calls < $budget + 6) {
				$since = gmdate('Y-m-d', (isset($st[$k . '_inc']) ? (int) $st[$k . '_inc'] : (int) $st[$k . '_full_at']) - DAY_IN_SECONDS);
				$start = time();
				$n = 0;
				for ($page = 0; $page < 8; $page++) {
					/* aktivitások: az utolsó 21 napban létrehozottak (napi teljes újraolvasás pótolja a régieket) */
					$rules = $k === 'act' ? cp_st_win_rule($j[0], gmdate('Y-m-d', time() - 21 * DAY_IN_SECONDS), cp_st_today()) : cp_st_mod_rule($j[0], $since);
					$rows = cp_st_search($j[0], $j[1], $j[2], $rules, 0);
					$calls++;
					if (is_wp_error($rows)) { throw new Exception($k . ' (változások): ' . $rows->get_error_message()); }
					if ($k === 'deal') { cp_st_store_deals($rows, false); }
					elseif ($k === 'contact') { cp_st_store_contacts($rows); }
					else { cp_st_store_acts($rows); }
					$n += count($rows);
					/* 250+ változás (pl. tömeges módosítás): a lapozás nem megbízható, ezért teljes újraolvasás */
					if (count($rows) >= 250) { $st[$k . '_redo'] = 1; $st[$k . '_page'] = 0; $st[$k . '_run'] = gmdate('Y-m-d H:i:s'); }
					break;
				}
				$st[$k . '_inc'] = $start;
				$log[] = $k . ': ' . $n . ' friss sor';
			}
		}
		cp_st_mail_capture();
		$st['last_ok'] = time();
		$st['last_err'] = '';
	} catch (Exception $e) {
		$st['last_err'] = mb_substr($e->getMessage(), 0, 300);
		$st['err_at'] = time();
	}
	/* a hirdetési lekérések saját állapotot írnak: előbb mentünk, utána újraolvasunk (különben a végső mentés felülírná) */
	cp_st_state_set($st);
	/* hirdetési költség (Meta) – óránként */
	$meta = cp_st_meta_sync();
	if ($meta !== '') { $log[] = $meta; }
	/* LinkedIn (Windsor.ai) – naponta */
	$wli = cp_st_windsor_sync();
	if ($wli !== '') { $log[] = $wli; }
	$st = cp_st_state();
	$st['last_run'] = time();
	$st['last_log'] = implode(' · ', $log);
	$st['last_calls'] = $calls;
	$st['last_sec'] = round(microtime(true) - $t0, 1);
	cp_st_state_set($st);
	cp_st_bump();
	delete_transient('cp_st_sync_lock');
	return $st['last_err'] !== '' ? 'HIBA: ' . $st['last_err'] : ('OK – ' . $st['last_log']);
}

/* a mutatók gyorsítótárának érvénytelenítése */
function cp_st_bump() {
	update_option('cp_st_ver', (string) time(), false);
}

add_filter('cron_schedules', function ($s) {
	if (!isset($s['hourly'])) { $s['hourly'] = array('interval' => HOUR_IN_SECONDS, 'display' => 'Óránként'); }
	return $s;
});

add_action('init', function () {
	if (!wp_next_scheduled('cp_st_cron')) { wp_schedule_event(time() + 120, 'hourly', 'cp_st_cron'); }
});
add_action('cp_st_cron', function () {
	cp_st_sync(false);
	/* amíg a teljes beolvasás tart, 5 percenként folytatjuk */
	$st = cp_st_state();
	$busy = false;
	foreach (array('deal', 'contact', 'act') as $k) { if (empty($st[$k . '_full_at']) || !empty($st[$k . '_redo'])) { $busy = true; } }
	if ($busy && !wp_next_scheduled('cp_st_cron_more')) { wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'cp_st_cron_more'); }
});
add_action('cp_st_cron_more', function () { do_action('cp_st_cron'); });

/* ------------------------------------------------------------ hirdetési költség */

/* $prefix: fiókazonosító (pl. „1234567890:”) – így egy fiók adata nem törli a másikét */
function cp_st_ads_replace($channel, $from, $to, $rows, $prefix = '') {
	global $wpdb;
	$t = cp_st_t('ads');
	if ($prefix !== '') {
		$wpdb->query($wpdb->prepare("DELETE FROM $t WHERE channel = %s AND d BETWEEN %s AND %s AND campaign_id LIKE %s", $channel, $from, $to, $wpdb->esc_like($prefix) . '%'));
		foreach ($rows as &$x) { $x['campaign_id'] = mb_substr($prefix . $x['campaign_id'], 0, 64); }
		unset($x);
	} else {
		$wpdb->query($wpdb->prepare("DELETE FROM $t WHERE channel = %s AND d BETWEEN %s AND %s", $channel, $from, $to));
	}
	$now = gmdate('Y-m-d H:i:s');
	$out = array();
	foreach ($rows as $r) {
		if ($r['d'] < $from || $r['d'] > $to) { continue; }
		$key = $r['d'] . '|' . $r['campaign_id'];
		if (!isset($r['spend_orig'])) { $r['spend_orig'] = $r['spend']; }
		if (!isset($r['currency'])) { $r['currency'] = ''; }
		if (isset($out[$key])) {
			foreach (array('spend', 'spend_orig', 'clicks', 'impressions', 'conversions') as $k) { $out[$key][$k] += $r[$k]; }
			continue;
		}
		$r['channel'] = $channel;
		$r['updated_at'] = $now;
		$out[$key] = $r;
	}
	$cols = array('d', 'channel', 'campaign_id', 'campaign_name', 'spend', 'spend_orig', 'currency', 'clicks', 'impressions', 'conversions', 'updated_at');
	foreach ($out as &$r) { $r['spend'] = (string) round((float) $r['spend'], 2); $r['spend_orig'] = (string) round((float) $r['spend_orig'], 2); $r['currency'] = mb_substr((string) $r['currency'], 0, 3); $r['conversions'] = (string) round((float) $r['conversions'], 2); $r['clicks'] = (int) $r['clicks']; $r['impressions'] = (int) $r['impressions']; }
	unset($r);
	cp_st_upsert($t, $cols, array_values($out), array('campaign_name', 'spend', 'spend_orig', 'currency', 'clicks', 'impressions', 'conversions', 'updated_at'));
	cp_st_bump();
	return count($out);
}

/* Meta Marketing API: kampányszintű insights napi bontásban, a mai nap + az elmúlt 7 nap */
function cp_st_meta_sync($force = false) {
	$o = cp_st_opts();
	$tok = cp_st_secret('meta_token');
	$acts = cp_st_meta_accounts();
	if ($tok === '' || !$acts) { return ''; }
	$st = cp_st_state();
	if (!$force && !empty($st['meta_at']) && time() - (int) $st['meta_at'] < 50 * MINUTE_IN_SECONDS) { return ''; }
	$to = cp_st_today();
	$from = gmdate('Y-m-d', strtotime($to . ' -7 days'));
	$ver = preg_match('/^v\d{1,2}\.\d$/', $o['meta_ver']) ? $o['meta_ver'] : 'v24.0';
	$msgs = array();
	$errs = array();
	foreach ($acts as $act) {
		$r = cp_st_meta_account($tok, $ver, $act, $from, $to);
		if (is_string($r)) { $errs[] = 'act_' . $act . ': ' . $r; continue; }
		$msgs[] = cp_st_ads_replace('meta', $from, $to, $r, $act . ':');
		/* egyszeri előzmény-betöltés (a tavalyi összevetéshez): kb. 400 nap, 90 napos szeletekben, körönként egy szelet */
		$st = cp_st_state();
		$bk = 'meta_bf_' . $act;
		$bf = isset($st[$bk]) ? (string) $st[$bk] : gmdate('Y-m-d', strtotime($from . ' -1 day'));
		if ($bf !== 'kesz') {
			$lo = gmdate('Y-m-d', strtotime($to . ' -400 days'));
			$f2 = max($lo, gmdate('Y-m-d', strtotime($bf . ' -89 days')));
			$h = cp_st_meta_account($tok, $ver, $act, $f2, $bf);
			if (!is_string($h)) {
				cp_st_ads_replace('meta', $f2, $bf, $h, $act . ':');
				$st[$bk] = $f2 <= $lo ? 'kesz' : gmdate('Y-m-d', strtotime($f2 . ' -1 day'));
				cp_st_state_set($st);
			}
		}
	}
	$st = cp_st_state();
	$st['meta_at'] = time();
	$st['meta_err'] = implode(' · ', $errs);
	if ($msgs) { $st['meta_ok'] = time(); }
	/* amíg az előzmény töltődik, a következő kör ne várjon 50 percet */
	foreach ($acts as $act) { if (isset($st['meta_bf_' . $act]) && $st['meta_bf_' . $act] !== 'kesz') { $st['meta_at'] = time() - 45 * MINUTE_IN_SECONDS; } }
	cp_st_state_set($st);
	return 'Meta: ' . ($msgs ? array_sum($msgs) . ' kampánynap' : '') . ($errs ? ' HIBA ' . $st['meta_err'] : '');
}

function cp_st_meta_accounts() {
	$o = cp_st_opts();
	$out = array();
	foreach (preg_split('/[\s,;]+/', (string) $o['meta_act']) as $x) { $x = preg_replace('/[^0-9]/', '', $x); if ($x !== '' && !in_array($x, $out, true)) { $out[] = $x; } }
	return $out;
}

/* egy hirdetési fiók insights-sorai, vagy hibaüzenet (string) */
function cp_st_meta_account($tok, $ver, $act, $from, $to) {
	$url = 'https://graph.facebook.com/' . $ver . '/act_' . $act . '/insights?' . http_build_query(array(
		'level'          => 'campaign',
		'time_increment' => 1,
		'time_range'     => wp_json_encode(array('since' => $from, 'until' => $to)),
		'fields'         => 'campaign_id,campaign_name,spend,clicks,impressions,actions',
		'limit'          => 500,
	));
	$rows = array();
	$err = '';
	for ($i = 0; $i < 20 && $url; $i++) {
		$res = wp_remote_get($url, array('timeout' => 30, 'headers' => array('Authorization' => 'Bearer ' . $tok)));
		if (is_wp_error($res)) { $err = $res->get_error_message(); break; }
		$j = json_decode(wp_remote_retrieve_body($res), true);
		if ((int) wp_remote_retrieve_response_code($res) !== 200 || !is_array($j) || !isset($j['data'])) {
			$err = 'HTTP ' . wp_remote_retrieve_response_code($res) . ': ' . (isset($j['error']['message']) ? mb_substr($j['error']['message'], 0, 200) : 'érvénytelen válasz');
			break;
		}
		foreach ($j['data'] as $x) {
			$conv = 0;
			$acts = array();
			foreach ((isset($x['actions']) ? (array) $x['actions'] : array()) as $a) { if (isset($a['action_type'], $a['value'])) { $acts[$a['action_type']] = (float) $a['value']; } }
			if (isset($acts['lead'])) { $conv = $acts['lead']; }
			else { foreach (array('onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead') as $k) { if (isset($acts[$k])) { $conv += $acts[$k]; } } }
			$rows[] = array(
				'd'             => isset($x['date_start']) ? substr($x['date_start'], 0, 10) : '',
				'campaign_id'   => mb_substr(isset($x['campaign_id']) ? (string) $x['campaign_id'] : '', 0, 64),
				'campaign_name' => mb_substr(isset($x['campaign_name']) ? (string) $x['campaign_name'] : '', 0, 190),
				'spend'         => isset($x['spend']) ? (float) $x['spend'] : 0,
				'clicks'        => isset($x['clicks']) ? (int) $x['clicks'] : 0,
				'impressions'   => isset($x['impressions']) ? (int) $x['impressions'] : 0,
				'conversions'   => $conv,
			);
		}
		$url = isset($j['paging']['next']) ? $j['paging']['next'] : '';
	}
	if ($err !== '') { return $err; }
	return array_values(array_filter($rows, function ($r) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['d']) && $r['campaign_id'] !== ''; }));
}

/* Google Ads-szkript → REST végpont: POST /wp-json/crazy-panel/v1/ads, fejléc: X-CP-Key */
add_action('rest_api_init', function () {
	register_rest_route('crazy-panel/v1', '/ads', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'cp_st_rest_ads',
	));
});

function cp_st_rest_ads(WP_REST_Request $req) {
	$ip = function_exists('cp_panel_client_ip') ? cp_panel_client_ip() : '';
	$fk = 'cp_st_adsfail_' . md5($ip);
	if ((int) get_transient($fk) > 20) { return new WP_Error('cp_st_rate', 'Túl sok hibás próbálkozás – próbáld újra egy óra múlva.', array('status' => 429)); }
	$hash = cp_st_secret('gads_secret_hash');
	$key = (string) $req->get_header('x-cp-key');
	if ($hash === '' || $key === '' || !hash_equals($hash, hash('sha256', $key))) {
		set_transient($fk, (int) get_transient($fk) + 1, HOUR_IN_SECONDS);
		return new WP_Error('cp_st_key', 'Érvénytelen kulcs.', array('status' => 403));
	}
	$b = $req->get_json_params();
	if (!is_array($b) || !isset($b['rows']) || !is_array($b['rows']) || count($b['rows']) > 20000) { return new WP_Error('cp_st_body', 'Hibás adat.', array('status' => 400)); }
	$from = isset($b['from']) ? (string) $b['from'] : '';
	$to = isset($b['to']) ? (string) $b['to'] : '';
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to || (strtotime($to) - strtotime($from)) > 400 * DAY_IN_SECONDS) {
		return new WP_Error('cp_st_range', 'Hibás dátumtartomány.', array('status' => 400));
	}
	$rows = array();
	foreach ($b['rows'] as $r) {
		if (!is_array($r)) { continue; }
		$d = isset($r['d']) ? (string) $r['d'] : '';
		$id = isset($r['id']) ? preg_replace('/[^0-9A-Za-z_-]/', '', (string) $r['id']) : '';
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $id === '') { continue; }
		$rows[] = array(
			'd'             => $d,
			'campaign_id'   => mb_substr($id, 0, 64),
			'campaign_name' => mb_substr(sanitize_text_field(isset($r['name']) ? (string) $r['name'] : ''), 0, 190),
			'spend'         => max(0, (float) (isset($r['spend']) ? $r['spend'] : 0)),
			'clicks'        => max(0, (int) (isset($r['clicks']) ? $r['clicks'] : 0)),
			'impressions'   => max(0, (int) (isset($r['impr']) ? $r['impr'] : 0)),
			'conversions'   => max(0, (float) (isset($r['conv']) ? $r['conv'] : 0)),
		);
	}
	$acct = isset($b['acct']) ? preg_replace('/[^0-9]/', '', (string) $b['acct']) : '';
	$n = cp_st_ads_replace('google', $from, $to, $rows, $acct !== '' ? $acct . ':' : '');
	$st = cp_st_state();
	$st['gads_at'] = time();
	$st['gads_n'] = $n;
	cp_st_state_set($st);
	return array('ok' => true, 'rows' => $n);
}

/* LinkedIn: kézi tételek (időszak + összeg) – naponként arányosan szétosztva kerülnek a költségtáblába */
function cp_st_li_all() {
	$l = get_option(CP_ST_LI, array());
	return is_array($l) ? $l : array();
}

function cp_st_li_rebuild() {
	global $wpdb;
	$t = cp_st_t('ads');
	$wpdb->query("DELETE FROM $t WHERE channel = 'linkedin' AND campaign_id LIKE 'li-%'");
	/* ahol már van Windsor.ai-adat, ott a kézi tétel nem számít (nincs dupla költés) */
	$auto = array_flip((array) $wpdb->get_col("SELECT DISTINCT d FROM $t WHERE channel = 'linkedin' AND campaign_id NOT LIKE 'li-%'"));
	$rows = array();
	$now = gmdate('Y-m-d H:i:s');
	foreach (cp_st_li_all() as $e) {
		$days = (int) round((strtotime($e['to']) - strtotime($e['from'])) / DAY_IN_SECONDS) + 1;
		if ($days < 1 || $days > 400) { continue; }
		for ($i = 0; $i < $days; $i++) {
			$d = gmdate('Y-m-d', strtotime($e['from'] . ' +' . $i . ' days'));
			if (isset($auto[$d])) { continue; }
			$rows[] = array(
				'd'             => $d,
				'channel'       => 'linkedin',
				'campaign_id'   => 'li-' . $e['id'],
				'campaign_name' => $e['name'],
				'spend'         => (string) round($e['amount'] / $days, 2),
				'spend_orig'    => (string) round($e['amount'] / $days, 2),
				'currency'      => 'HUF',
				'clicks'        => $i === 0 ? (int) $e['clicks'] : 0,
				'impressions'   => $i === 0 ? (int) $e['impr'] : 0,
				'conversions'   => (string) ($i === 0 ? (float) $e['conv'] : 0),
				'updated_at'    => $now,
			);
		}
	}
	cp_st_upsert($t, array('d', 'channel', 'campaign_id', 'campaign_name', 'spend', 'spend_orig', 'currency', 'clicks', 'impressions', 'conversions', 'updated_at'), $rows, array('campaign_name', 'spend', 'spend_orig', 'currency', 'clicks', 'impressions', 'conversions', 'updated_at'));
	cp_st_bump();
}

/* ------------------------------------------------------------ LinkedIn Ads (Windsor.ai) */

function cp_st_li_accounts() {
	$out = array();
	foreach (preg_split('/[\s,;]+/', (string) cp_st_opts()['li_act']) as $x) { $x = preg_replace('/[^0-9]/', '', $x); if ($x !== '' && !in_array($x, $out, true)) { $out[] = $x; } }
	return $out;
}

function cp_st_eur_huf() {
	return max(0, (float) cp_st_opts()['eur_huf']);
}

/* eredeti összeg → Ft (HUF változatlan, EUR a beállított árfolyamon); ismeretlen pénznem: null */
function cp_st_to_huf($amount, $cur) {
	$cur = strtoupper((string) $cur);
	if ($cur === '' || $cur === 'HUF') { return (float) $amount; }
	if ($cur === 'EUR') { return (float) $amount * cp_st_eur_huf(); }
	return null;
}

/* árfolyamváltozáskor a már tárolt EUR-os sorok forintértéke is frissül */
function cp_st_reprice() {
	global $wpdb;
	$t = cp_st_t('ads');
	$wpdb->query($wpdb->prepare("UPDATE $t SET spend = ROUND(spend_orig * %f, 2) WHERE currency = 'EUR'", cp_st_eur_huf()));
	cp_st_bump();
}

/* naponta: az utolsó 30 nap újraírva; az első sikeres körben ~400 nap előzmény (a tavalyi összevetéshez) */
function cp_st_windsor_sync($force = false) {
	$key = cp_st_secret('windsor_key');
	$acts = cp_st_li_accounts();
	if ($key === '' || !$acts) { return ''; }
	$st = cp_st_state();
	if (!$force && !empty($st['wli_at']) && time() - (int) $st['wli_at'] < 20 * HOUR_IN_SECONDS) { return ''; }
	$to = cp_st_today();
	$msgs = array();
	$errs = array();
	foreach ($acts as $act) {
		$bk = 'wli_bf_' . $act;
		$from = gmdate('Y-m-d', strtotime($to . (empty($st[$bk]) ? ' -400 days' : ' -30 days')));
		$r = cp_st_windsor_account($key, $act, $from, $to);
		if (is_string($r)) { $errs[] = $act . ': ' . $r; continue; }
		$unk = array();
		foreach ($r as &$x) {
			$huf = cp_st_to_huf($x['spend_orig'], $x['currency']);
			if ($huf === null) { $unk[$x['currency']] = 1; $huf = 0; }
			$x['spend'] = $huf;
		}
		unset($x);
		if ($unk) { $errs[] = $act . ': ismeretlen pénznem (' . implode(', ', array_keys($unk)) . '), a forintösszegben 0'; }
		$msgs[] = cp_st_ads_replace('linkedin', $from, $to, $r, 'w' . $act . ':');
		$st[$bk] = 1;
	}
	/* csak hiba: egy óra múlva újra próbálja */
	$st['wli_at'] = $msgs ? time() : time() - 19 * HOUR_IN_SECONDS;
	$st['wli_err'] = mb_substr(implode(' · ', $errs), 0, 300);
	if ($msgs) { $st['wli_ok'] = time(); }
	cp_st_state_set($st);
	if ($msgs) { cp_st_li_rebuild(); }
	return 'LinkedIn: ' . ($msgs ? array_sum($msgs) . ' kampánynap' : '') . ($errs ? ' HIBA ' . $st['wli_err'] : '');
}

/* egy LinkedIn-fiók napi kampánysorai, vagy hibaüzenet (string); a kulcs csak a query-paraméterben megy, üzenetbe nem kerül */
function cp_st_windsor_account($key, $act, $from, $to) {
	$url = 'https://connectors.windsor.ai/linkedin?' . http_build_query(array(
		'api_key'         => $key,
		'fields'          => 'date,account_id,campaign_group_name,campaign,spend,impressions,clicks,oneclickleads,currency',
		'date_from'       => $from,
		'date_to'         => $to,
		'select_accounts' => $act,
	));
	$res = wp_remote_get($url, array('timeout' => 45));
	$hide = function ($m) use ($key) { return mb_substr(str_replace(array($key, rawurlencode($key)), '•••', (string) $m), 0, 200); };
	if (is_wp_error($res)) { return $hide($res->get_error_message()); }
	$code = (int) wp_remote_retrieve_response_code($res);
	$j = json_decode(wp_remote_retrieve_body($res), true);
	if ($code !== 200 || !is_array($j) || !isset($j['data']) || !is_array($j['data'])) {
		$m = '';
		if (is_array($j)) { foreach (array('error', 'message', 'detail') as $k) { if (!empty($j[$k])) { $m = is_array($j[$k]) ? (isset($j[$k]['message']) ? $j[$k]['message'] : wp_json_encode($j[$k])) : $j[$k]; break; } } }
		return 'HTTP ' . $code . ': ' . ($m !== '' ? $hide($m) : 'érvénytelen válasz');
	}
	$rows = array();
	foreach ($j['data'] as $x) {
		if (!is_array($x)) { continue; }
		$d = isset($x['date']) ? substr((string) $x['date'], 0, 10) : '';
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { continue; }
		$grp = trim(isset($x['campaign_group_name']) ? (string) $x['campaign_group_name'] : '');
		$camp = trim(isset($x['campaign']) ? (string) $x['campaign'] : '');
		if ($camp === '') { $camp = $grp !== '' ? $grp : 'LinkedIn'; }
		$rows[] = array(
			'd'             => $d,
			/* a Windsor nem ad kampányazonosítót: a csoport + kampány nevéből képzett kulcs */
			'campaign_id'   => substr(md5($grp . '|' . $camp), 0, 16),
			'campaign_name' => mb_substr(sanitize_text_field($grp !== '' && $grp !== $camp ? $grp . ' / ' . $camp : $camp), 0, 190),
			'spend_orig'    => max(0, (float) (isset($x['spend']) ? $x['spend'] : 0)),
			'currency'      => strtoupper(preg_replace('/[^A-Za-z]/', '', isset($x['currency']) ? (string) $x['currency'] : '')),
			'clicks'        => max(0, (int) (isset($x['clicks']) ? $x['clicks'] : 0)),
			'impressions'   => max(0, (int) (isset($x['impressions']) ? $x['impressions'] : 0)),
			'conversions'   => max(0, (float) (isset($x['oneclickleads']) ? $x['oneclickleads'] : 0)),
		);
	}
	return $rows;
}

/* ------------------------------------------------------------ GA4 Data API (függőség nélkül) */

function cp_st_ga_token() {
	$c = get_transient('cp_st_ga_at');
	if (is_string($c) && $c !== '') { return $c; }
	$k = json_decode(cp_st_secret('ga_secret_json'), true);
	if (!is_array($k) || empty($k['client_email']) || empty($k['private_key'])) { return new WP_Error('cp_st_ga', 'Nincs feltöltve a GA4 szolgáltatásfiók JSON-kulcsa.'); }
	if (!function_exists('openssl_sign')) { return new WP_Error('cp_st_ga', 'A szerveren nincs OpenSSL.'); }
	$b64 = function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
	$now = time();
	$head = $b64(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
	$claim = $b64(wp_json_encode(array('iss' => $k['client_email'], 'scope' => 'https://www.googleapis.com/auth/analytics.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600)));
	$sig = '';
	$pk = openssl_pkey_get_private($k['private_key']);
	if (!$pk || !openssl_sign($head . '.' . $claim, $sig, $pk, OPENSSL_ALGO_SHA256)) { return new WP_Error('cp_st_ga', 'A JSON-kulcs privát kulcsa nem olvasható.'); }
	$res = wp_remote_post('https://oauth2.googleapis.com/token', array('timeout' => 20, 'body' => array('grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $head . '.' . $claim . '.' . $b64($sig))));
	if (is_wp_error($res)) { return $res; }
	$j = json_decode(wp_remote_retrieve_body($res), true);
	if (empty($j['access_token'])) { return new WP_Error('cp_st_ga', 'Google hitelesítési hiba: ' . (isset($j['error_description']) ? mb_substr($j['error_description'], 0, 160) : (isset($j['error']) ? $j['error'] : 'ismeretlen'))); }
	set_transient('cp_st_ga_at', $j['access_token'], max(60, (int) (isset($j['expires_in']) ? $j['expires_in'] : 3600) - 300));
	return $j['access_token'];
}

/* „EventCrazy = 312345678” soronként (név nélkül is jó) → név => property ID */
function cp_st_ga_props() {
	$o = cp_st_opts();
	$out = array();
	foreach (cp_st_lines(str_replace(',', "\n", (string) $o['ga_prop'])) as $l) {
		$p = explode('=', $l, 2);
		$id = preg_replace('/[^0-9]/', '', count($p) === 2 ? $p[1] : $p[0]);
		if ($id === '') { continue; }
		$name = count($p) === 2 ? trim(sanitize_text_field($p[0])) : '';
		$out[$name !== '' ? $name : 'GA4 ' . (count($out) + 1)] = $id;
	}
	return $out;
}

function cp_st_ga_call($method, $body, $prop) {
	$prop = preg_replace('/[^0-9]/', '', (string) $prop);
	if ($prop === '') { return new WP_Error('cp_st_ga', 'Nincs megadva a GA4 property ID.'); }
	$tok = cp_st_ga_token();
	if (is_wp_error($tok)) { return $tok; }
	$res = wp_remote_post('https://analyticsdata.googleapis.com/v1beta/properties/' . $prop . ':' . $method, array('timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json'), 'body' => wp_json_encode($body)));
	if (is_wp_error($res)) { return $res; }
	$j = json_decode(wp_remote_retrieve_body($res), true);
	if ((int) wp_remote_retrieve_response_code($res) !== 200) {
		if ((int) wp_remote_retrieve_response_code($res) === 401) { delete_transient('cp_st_ga_at'); }
		return new WP_Error('cp_st_ga', 'GA4 hiba (' . wp_remote_retrieve_response_code($res) . '): ' . (isset($j['error']['message']) ? mb_substr($j['error']['message'], 0, 200) : ''));
	}
	return is_array($j) ? $j : array();
}

function cp_st_ga_num($row, $i) {
	return isset($row['metricValues'][$i]['value']) ? (float) $row['metricValues'][$i]['value'] : 0;
}

/* élő: az utolsó 30 perc aktív felhasználói és oldalmegtekintései (60 mp gyorsítótár) */
function cp_st_ga_live() {
	$c = get_transient('cp_st_ga_live');
	if (is_array($c) && isset($c['sites'])) { return $c; }
	$out = array('sites' => array(), 'at' => time());
	foreach (cp_st_ga_props() as $name => $prop) {
		$j = cp_st_ga_call('runRealtimeReport', array('metrics' => array(array('name' => 'activeUsers'), array('name' => 'screenPageViews'))), $prop);
		if (is_wp_error($j)) { $out['sites'][] = array('name' => $name, 'err' => $j->get_error_message()); continue; }
		$r = isset($j['rows'][0]) ? $j['rows'][0] : array();
		$top = cp_st_ga_call('runRealtimeReport', array('dimensions' => array(array('name' => 'unifiedScreenName')), 'metrics' => array(array('name' => 'screenPageViews')), 'limit' => 5), $prop);
		$pages = array();
		if (!is_wp_error($top) && !empty($top['rows'])) {
			foreach ($top['rows'] as $x) { $pages[] = array(isset($x['dimensionValues'][0]['value']) ? $x['dimensionValues'][0]['value'] : '', cp_st_ga_num($x, 0)); }
		}
		$out['sites'][] = array('name' => $name, 'users' => cp_st_ga_num($r, 0), 'views' => cp_st_ga_num($r, 1), 'pages' => $pages);
	}
	set_transient('cp_st_ga_live', $out, 60);
	return $out;
}

/* időszakos: oldalmegtekintések, munkamenetek, felhasználók (1 órás gyorsítótár) */
function cp_st_ga_period($ranges) {
	$ck = 'cp_st_ga_p_' . md5(wp_json_encode($ranges) . wp_json_encode(cp_st_ga_props()));
	$c = get_transient($ck);
	if (is_array($c) && isset($c['sites'])) { return $c; }
	$out = array('sites' => array(), 'at' => time());
	foreach (cp_st_ga_props() as $name => $prop) {
		$x = cp_st_ga_period_one($ranges, $prop);
		$x['name'] = $name;
		$out['sites'][] = $x;
	}
	set_transient($ck, $out, HOUR_IN_SECONDS);
	return $out;
}

function cp_st_ga_period_one($ranges, $prop) {
	$today = cp_st_today();
	$dr = array();
	foreach ($ranges as $name => $r) {
		if ($r[0] > $today) { continue; }
		$dr[] = array('startDate' => $r[0], 'endDate' => min($r[1], $today), 'name' => $name);
	}
	if (!$dr) { return array(); }
	$j = cp_st_ga_call('runReport', array('dateRanges' => $dr, 'metrics' => array(array('name' => 'screenPageViews'), array('name' => 'sessions'), array('name' => 'totalUsers'))), $prop);
	if (is_wp_error($j)) { return array('err' => $j->get_error_message()); }
	$out = array();
	foreach ((array) (isset($j['rows']) ? $j['rows'] : array()) as $x) {
		$n = isset($x['dimensionValues'][0]['value']) ? $x['dimensionValues'][0]['value'] : 'cur';
		$out[$n] = array('views' => cp_st_ga_num($x, 0), 'sessions' => cp_st_ga_num($x, 1), 'users' => cp_st_ga_num($x, 2));
	}
	if (count($dr) === 1 && isset($out['date_range_0'])) { $out = array($dr[0]['name'] => $out['date_range_0']); }
	return $out;
}

/* ------------------------------------------------------------ időszakok */

function cp_st_period($gran, $anchor) {
	$d = new DateTime($anchor, cp_st_tz());
	$y = (int) $d->format('Y');
	$m = (int) $d->format('n');
	switch ($gran) {
		case 'day':
			$f = $d->format('Y-m-d'); $t = $f; break;
		case 'week':
			$w = (int) $d->format('N');
			$f = (clone $d)->modify('-' . ($w - 1) . ' days')->format('Y-m-d');
			$t = (new DateTime($f, cp_st_tz()))->modify('+6 days')->format('Y-m-d'); break;
		case 'quarter':
			$qm = (int) (floor(($m - 1) / 3) * 3 + 1);
			$f = sprintf('%04d-%02d-01', $y, $qm);
			$t = (new DateTime($f, cp_st_tz()))->modify('+3 months -1 day')->format('Y-m-d'); break;
		case 'year':
			$f = $y . '-01-01'; $t = $y . '-12-31'; break;
		default:
			$f = sprintf('%04d-%02d-01', $y, $m);
			$t = (new DateTime($f, cp_st_tz()))->modify('+1 month -1 day')->format('Y-m-d');
	}
	return array($f, $t);
}

function cp_st_shift($gran, $date, $n) {
	$d = new DateTime($date, cp_st_tz());
	$map = array('day' => 'day', 'week' => 'week', 'month' => 'month', 'quarter' => 'month', 'year' => 'year');
	$k = $gran === 'quarter' ? 3 * $n : $n;
	if ($gran === 'month' || $gran === 'quarter') { $d->modify('first day of this month'); }
	$d->modify(($k >= 0 ? '+' : '') . $k . ' ' . $map[$gran]);
	return $d->format('Y-m-d');
}

/* tavalyi azonos időszak: a hétnél 52 héttel korábbi (a hét napjai egyeznek), máshol naptári év */
function cp_st_yoy($gran, $f, $t) {
	if ($gran === 'week') { return array(gmdate('Y-m-d', strtotime($f . ' -364 days')), gmdate('Y-m-d', strtotime($t . ' -364 days'))); }
	$a = new DateTime($f, cp_st_tz()); $a->modify('-1 year');
	$b = new DateTime($t, cp_st_tz()); $b->modify('-1 year');
	if ($gran !== 'day' && substr($t, 5) === '02-29') { $b = new DateTime(((int) substr($t, 0, 4) - 1) . '-02-28', cp_st_tz()); }
	return array($a->format('Y-m-d'), $b->format('Y-m-d'));
}

function cp_st_days($f, $t) {
	return (int) round((strtotime($t . ' 12:00') - strtotime($f . ' 12:00')) / DAY_IN_SECONDS) + 1;
}

/* folyamatban lévő időszaknál az összevetés azonos hosszúságú szakaszra történik */
function cp_st_trim($f, $t, $len) {
	$e = gmdate('Y-m-d', strtotime($f . ' 12:00 +' . ($len - 1) . ' days'));
	return array($f, min($e, $t));
}

/* ------------------------------------------------------------ csatorna */

function cp_st_rules() {
	$o = cp_st_opts();
	$out = array();
	foreach (cp_st_lines($o['rules']) as $l) {
		if (!preg_match('/^(source|tag|utm|medium|campaign)\s*:\s*(.+?)\s*=>\s*([a-z_]+)$/iu', $l, $m)) { continue; }
		$out[] = array(strtolower($m[1]), mb_strtolower(trim($m[2])), strtolower($m[3]));
	}
	return $out;
}

function cp_st_channel($d, $rules) {
	$src = mb_strtolower($d['source']);
	$tags = mb_strtolower($d['tags']);
	$us = mb_strtolower((string) $d['utm_source']);
	$um = mb_strtolower((string) $d['utm_medium']);
	$uc = mb_strtolower((string) $d['utm_campaign']);
	$paid = $um === '' || preg_match('/cpc|ppc|paid|ads|cpm|social_paid/', $um);
	/* elsőbbség: a fizetett UTM (a kattintás forrása), utána a Salesmate-forrás */
	foreach (array('utm', 'medium', 'campaign', 'source', 'tag') as $kind) {
		foreach ($rules as $r) {
			if ($r[0] !== $kind) { continue; }
			$hay = $kind === 'utm' ? $us : ($kind === 'medium' ? $um : ($kind === 'campaign' ? $uc : ($kind === 'source' ? $src : $tags)));
			if ($hay === '' || strpos($hay, $r[1]) === false) { continue; }
			if ($kind === 'utm' && in_array($r[2], array('google', 'meta', 'linkedin'), true) && !$paid) { return 'organic'; }
			return $r[2];
		}
	}
	return $src === '' && $us === '' ? 'unknown' : 'other';
}

function cp_st_channel_labels() {
	return array('google' => 'Google Ads', 'meta' => 'Meta', 'linkedin' => 'LinkedIn', 'organic' => 'Organikus / weboldal', 'referral' => 'Ajánlás', 'ads' => 'Hirdetés (nem bontott)', 'other' => 'Egyéb', 'unknown' => 'Nincs forrás');
}

/* ------------------------------------------------------------ mutatók */

function cp_st_load() {
	global $wpdb;
	$d = cp_st_t('deals');
	$c = cp_st_t('contacts');
	$a = cp_st_t('acts');
	$rows = $wpdb->get_results("SELECT x.id, x.created_at, x.created_d, x.status, x.stage, x.closed_stage, x.signed_d, x.signed_src, x.source, x.tags, x.svc, x.svc_req, x.value, x.currency, x.event_d, x.ctype, x.kind, x.lost_reason, x.first_mail_at,
		k.utm_source, k.utm_medium, k.utm_campaign, k.ev_type, k.ad_name, k.company, (k.id IS NOT NULL) AS has_contact,
		(SELECT MIN(CASE WHEN t.done_at IS NOT NULL AND t.done_at >= x.created_at THEN t.done_at WHEN t.type = 'Call' AND t.created_at >= x.created_at THEN t.created_at END) FROM $a t WHERE t.deal_id = x.id) AS first_act_at
		FROM $d x LEFT JOIN $c k ON k.id = x.contact_id", ARRAY_A);
	$rules = cp_st_rules();
	$o = cp_st_opts();
	$p = cp_panel_opts();
	$first_lc = array_map('cp_panel_lc', array_diff(cp_st_lines($p['pending_stages']), array($o['offer_from'])));
	foreach ($rows as &$r) {
		$r['ch'] = cp_st_channel($r, $rules);
		$st = cp_panel_lc($r['status'] === 'Open' ? $r['stage'] : ($r['closed_stage'] !== '' ? $r['closed_stage'] : $r['stage']));
		/* ajánlatig jutott: nem az első („Érdeklődő”) szakaszban áll / zárult, vagy aláírt */
		$r['offer'] = !empty($r['signed_d']) || ($st !== '' && !in_array($st, $first_lc, true)) ? 1 : 0;
		$r['value'] = (float) $r['value'];
		$r['huf'] = $r['currency'] === '' || strtoupper($r['currency']) === 'HUF';
		$r['h'] = $r['created_at'] ? (int) (new DateTime($r['created_at'] . ' UTC'))->setTimezone(cp_st_tz())->format('G') : 0;
		$r['wd'] = (int) (new DateTime($r['created_d'], cp_st_tz()))->format('N');
		$r['svcs'] = $r['svc_req'] !== '' ? explode('|', $r['svc_req']) : array();
		/* űrlap-tesztadat (pl. „<test lead: dummy data …>”) nem rendezvénytípus */
		if ($r['ev_type'] !== null && strpos((string) $r['ev_type'], '<') === 0) { $r['ev_type'] = ''; }
	}
	unset($r);
	cp_st_svc_unify($rows);
	return $rows;
}

/* „Szelfigép = Fotóautomata” soronként → kisbetűs név => egységes név */
function cp_st_svc_aliases() {
	$out = array();
	foreach (cp_st_lines(cp_st_opts()['svc_alias']) as $l) {
		$p = explode('=', $l, 2);
		if (count($p) !== 2 || trim($p[0]) === '' || trim($p[1]) === '') { continue; }
		$out[cp_panel_lc(trim($p[0]))] = trim($p[1]);
	}
	return $out;
}

/* ugyanaz a szolgáltatás egy néven: álnevek + kis/nagybetű („AI karikatúra” = „AI Karikatúra”, a gyakoribb írásmód marad) */
function cp_st_svc_unify(&$rows) {
	$al = cp_st_svc_aliases();
	$key = function ($x) use ($al) {
		$k = cp_panel_lc(preg_replace('/\s+/u', ' ', trim($x)));
		return isset($al[$k]) ? cp_panel_lc($al[$k]) : $k;
	};
	$split = function ($svc) { return array_values(array_filter(array_map('trim', preg_split('/\s*[,&+]\s*/u', $svc)), 'strlen')); };
	/* írásmódok számlálása kulcsonként; az álnév célja mindig nyer */
	$cnt = array();
	foreach ($rows as $r) {
		foreach (array_merge($r['svcs'], $r['svc'] !== '' ? $split($r['svc']) : array()) as $x) {
			$x = preg_replace('/\s+/u', ' ', trim($x));
			$cnt[$key($x)][$x] = (isset($cnt[$key($x)][$x]) ? $cnt[$key($x)][$x] : 0) + 1;
		}
	}
	$name = array();
	foreach ($cnt as $k => $v) { uksort($v, function ($a, $b) use ($v) { return $v[$b] - $v[$a] ?: strcmp($a, $b); }); $name[$k] = key($v); }
	foreach ($al as $t) { $name[cp_panel_lc($t)] = $t; }
	foreach ($rows as &$r) {
		$u = array();
		foreach ($r['svcs'] as $x) { $n = $name[$key($x)]; if (!in_array($n, $u, true)) { $u[] = $n; } }
		$r['svcs'] = $u;
		if ($r['svc'] !== '') {
			$u = array();
			foreach ($split($r['svc']) as $x) { $n = $name[$key($x)]; if (!in_array($n, $u, true)) { $u[] = $n; } }
			$r['svc'] = implode(' + ', $u);
		}
	}
	unset($r);
}

function cp_st_in($d, $f, $t) {
	return $d !== null && $d !== '' && $d >= $f && $d <= $t;
}

function cp_st_spend($f, $t) {
	global $wpdb;
	$x = cp_st_t('ads');
	$out = array('google' => 0, 'meta' => 0, 'linkedin' => 0);
	foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT channel, SUM(spend) s, SUM(clicks) c, SUM(impressions) i, SUM(conversions) v, SUM(CASE WHEN currency = 'EUR' THEN spend_orig ELSE 0 END) e FROM $x WHERE d BETWEEN %s AND %s GROUP BY channel", $f, $t), ARRAY_A) as $r) {
		$out[$r['channel']] = (float) $r['s'];
		$out['_' . $r['channel']] = array('spend' => round((float) $r['s']), 'clicks' => (int) $r['c'], 'impr' => (int) $r['i'], 'conv' => (float) $r['v'], 'eur' => round((float) $r['e'], 2));
	}
	return $out;
}

function cp_st_spend_any() {
	global $wpdb;
	$x = cp_st_t('ads');
	$out = array();
	foreach ((array) $wpdb->get_results("SELECT channel, MIN(d) f, MAX(d) t, MAX(updated_at) u FROM $x GROUP BY channel", ARRAY_A) as $r) { $out[$r['channel']] = $r; }
	return $out;
}

function cp_st_base($rows, $f, $t) {
	$leads = 0; $won = 0; $val = 0; $nonhuf = 0;
	foreach ($rows as $r) {
		if (cp_st_in($r['created_d'], $f, $t)) { $leads++; }
		if (cp_st_in($r['signed_d'], $f, $t)) { $won++; if ($r['huf']) { $val += $r['value']; } else { $nonhuf++; } }
	}
	$sp = cp_st_spend($f, $t);
	return array('leads' => $leads, 'won' => $won, 'value' => round($val), 'nonhuf' => $nonhuf, 'spend' => round($sp['google'] + $sp['meta'] + $sp['linkedin']));
}

function cp_st_median($a) {
	$a = array_values($a);
	$n = count($a);
	if (!$n) { return null; }
	sort($a);
	return $n % 2 ? $a[(int) ($n / 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2;
}

function cp_st_compute($gran, $anchor) {
	$rows = cp_st_load();
	$today = cp_st_today();
	list($f, $t) = cp_st_period($gran, $anchor);
	$partial = $t > $today && $f <= $today;
	$len = $partial ? cp_st_days($f, $today) : cp_st_days($f, $t);
	list($pf, $pt) = cp_st_period($gran, cp_st_shift($gran, $f, -1));
	list($yf, $yt) = cp_st_yoy($gran, $f, $t);
	$cur_t = $partial ? $today : $t;
	if ($partial) { list($pf, $pt) = cp_st_trim($pf, $pt, $len); list($yf, $yt) = cp_st_trim($yf, $yt, $len); }
	$labels = cp_st_channel_labels();
	$out = array(
		'gran' => $gran, 'from' => $f, 'to' => $t, 'curTo' => $cur_t, 'partial' => $partial,
		'prev' => array($pf, $pt), 'yoy' => array($yf, $yt), 'today' => $today,
		'kpi' => array('cur' => cp_st_base($rows, $f, $cur_t), 'prev' => cp_st_base($rows, $pf, $pt), 'yoy' => cp_st_base($rows, $yf, $yt)),
		'labels' => $labels,
		'miss' => array(),
	);

	/* trend: az utolsó N időszak + a tavalyi azonos időszakok */
	$nb = array('day' => 31, 'week' => 16, 'month' => 13, 'quarter' => 8, 'year' => 6);
	$trend = array();
	for ($i = $nb[$gran] - 1; $i >= 0; $i--) {
		list($bf, $bt) = cp_st_period($gran, cp_st_shift($gran, $f, -$i));
		list($lf, $lt) = cp_st_yoy($gran, $bf, $bt);
		$x = array('f' => $bf, 't' => $bt, 'l' => 0, 'w' => 0, 'ly' => 0, 'wy' => 0);
		foreach ($rows as $r) {
			if (cp_st_in($r['created_d'], $bf, $bt)) { $x['l']++; }
			if (cp_st_in($r['signed_d'], $bf, $bt)) { $x['w']++; }
			if (cp_st_in($r['created_d'], $lf, $lt)) { $x['ly']++; }
			if (cp_st_in($r['signed_d'], $lf, $lt)) { $x['wy']++; }
		}
		$trend[] = $x;
	}
	$out['trend'] = $trend;

	$P = array_values(array_filter($rows, function ($r) use ($f, $cur_t) { return cp_st_in($r['created_d'], $f, $cur_t); }));
	$W = array_values(array_filter($rows, function ($r) use ($f, $cur_t) { return cp_st_in($r['signed_d'], $f, $cur_t); }));
	$sp = cp_st_spend($f, $cur_t);

	/* 1) + 4) csatornánként: megkeresés, költség/megkeresés, aláírt, költség/aláírt, ROAS */
	$ch = array();
	$any = cp_st_spend_any();
	/* költés csak ott, ahonnan valaha jött költségadat – a hiányzó adat nem 0 Ft */
	foreach (array_keys($labels) as $k) { $ch[$k] = array('k' => $k, 'leads' => 0, 'won' => 0, 'value' => 0, 'cohort_won' => 0, 'spend' => (isset($sp[$k]) && !empty($any[$k])) ? round($sp[$k]) : null); }
	foreach ($P as $r) { $ch[$r['ch']]['leads']++; if (!empty($r['signed_d'])) { $ch[$r['ch']]['cohort_won']++; } }
	foreach ($W as $r) { $ch[$r['ch']]['won']++; if ($r['huf']) { $ch[$r['ch']]['value'] += $r['value']; } }
	$out['channels'] = array_values(array_filter($ch, function ($c) { return $c['leads'] || $c['won'] || $c['spend']; }));
	$out['spendInfo'] = $any;
	$out['spendDetail'] = array('google' => isset($sp['_google']) ? $sp['_google'] : null, 'meta' => isset($sp['_meta']) ? $sp['_meta'] : null, 'linkedin' => isset($sp['_linkedin']) ? $sp['_linkedin'] : null);
	$nos = 0; $ads = 0; $utm = 0;
	foreach ($P as $r) { if ($r['ch'] === 'unknown') { $nos++; } if ($r['ch'] === 'ads') { $ads++; } if ($r['utm_source'] !== null && $r['utm_source'] !== '') { $utm++; } }
	$out['srcCover'] = array('n' => count($P), 'nosource' => $nos, 'ads' => $ads, 'utm' => $utm);
	foreach (array('google', 'meta', 'linkedin') as $k) { if (empty($any[$k])) { $out['miss'][] = 'cost_' . $k; } }

	/* 2) foglalási előidő */
	$lt = array('all' => array(), 'svc' => array(), 'type' => array(), 'ctype' => array(), 'b' => array('0–7 nap' => 0, '8–30 nap' => 0, '31–90 nap' => 0, '91–180 nap' => 0, '180+ nap' => 0));
	$typed = 0;
	foreach ($P as $r) {
		if (empty($r['event_d']) || $r['event_d'] < $r['created_d']) { continue; }
		$days = cp_st_days($r['created_d'], $r['event_d']) - 1;
		if ($days > 800) { continue; }
		$lt['all'][] = $days;
		$s = $r['svc'] !== '' ? $r['svc'] : ($r['svcs'] ? $r['svcs'][0] : 'Nincs megadva');
		$lt['svc'][$s][] = $days;
		if ($r['ev_type'] !== null && $r['ev_type'] !== '') { $lt['type'][mb_strtolower($r['ev_type'])][] = $days; $typed++; }
		$ct = cp_st_ctype($r['ctype']);
		$lt['ctype'][$ct][] = $days;
		$b = $days <= 7 ? '0–7 nap' : ($days <= 30 ? '8–30 nap' : ($days <= 90 ? '31–90 nap' : ($days <= 180 ? '91–180 nap' : '180+ nap')));
		$lt['b'][$b]++;
	}
	$agg = function ($groups, $min) {
		$o = array();
		foreach ($groups as $k => $v) { if (count($v) >= $min) { $o[] = array('k' => (string) $k, 'n' => count($v), 'med' => cp_st_median($v), 'avg' => round(array_sum($v) / count($v))); } }
		usort($o, function ($a, $b) { return $b['n'] - $a['n']; });
		return array_slice($o, 0, 12);
	};
	$out['lead'] = array('n' => count($lt['all']), 'med' => cp_st_median($lt['all']), 'avg' => $lt['all'] ? round(array_sum($lt['all']) / count($lt['all'])) : null, 'buckets' => $lt['b'], 'svc' => $agg($lt['svc'], 1), 'type' => $agg($lt['type'], 1), 'ctype' => $agg($lt['ctype'], 1), 'typed' => $typed);
	if (count($lt['all']) && $typed < 0.5 * count($lt['all'])) { $out['miss'][] = 'evtype'; }

	/* 3) reakcióidő (óra): első kimenő e-mail vagy lezárt hívás/aktivitás */
	$rt = array(); $rtc = array(); $within = array(1 => 0, 4 => 0, 24 => 0);
	$st = cp_st_state();
	$mail_from = !empty($st['mail_from']) ? $st['mail_from'] : '';
	$elig = 0;
	foreach ($P as $r) {
		$c = strtotime($r['created_at'] . ' UTC');
		$cand = array();
		foreach (array('first_mail_at', 'first_act_at') as $k) { if (!empty($r[$k])) { $cand[] = strtotime($r[$k] . ' UTC'); } }
		if ($mail_from !== '' && $r['created_at'] >= $mail_from) { $elig++; }
		if (!$cand) { continue; }
		$h = max(0, (min($cand) - $c) / 3600);
		if ($h > 24 * 30) { continue; }
		$rt[] = $h;
		$rtc[$r['ch']][] = $h;
		foreach ($within as $lim => $n) { if ($h <= $lim) { $within[$lim]++; } }
	}
	$byc = array();
	foreach ($rtc as $k => $v) { $byc[] = array('k' => $k, 'n' => count($v), 'med' => round(cp_st_median($v), 1)); }
	usort($byc, function ($a, $b) { return $b['n'] - $a['n']; });
	$out['react'] = array('n' => count($rt), 'of' => count($P), 'med' => $rt ? round(cp_st_median($rt), 1) : null, 'within' => $within, 'ch' => $byc, 'mailFrom' => $mail_from, 'elig' => $elig);

	/* 5) tölcsér: a periódusban beérkezett megkeresések útja (kohorsz) */
	$fun = array('all' => array('leads' => 0, 'offer' => 0, 'won' => 0, 'lost' => 0, 'open' => 0), 'ch' => array(), 'svc' => array());
	foreach ($P as $r) {
		$won = !empty($r['signed_d']) ? 1 : 0;
		foreach (array(array('ch', $r['ch']), array('svc', $r['svc'] !== '' ? $r['svc'] : ($r['svcs'] ? implode(' + ', $r['svcs']) : 'Nincs megadva'))) as $g) {
			if (!isset($fun[$g[0]][$g[1]])) { $fun[$g[0]][$g[1]] = array('k' => $g[1], 'leads' => 0, 'offer' => 0, 'won' => 0); }
			$fun[$g[0]][$g[1]]['leads']++;
			$fun[$g[0]][$g[1]]['offer'] += $r['offer'];
			$fun[$g[0]][$g[1]]['won'] += $won;
		}
		$fun['all']['leads']++;
		$fun['all']['offer'] += $r['offer'];
		$fun['all']['won'] += $won;
		if (!$won && $r['status'] === 'Lost') { $fun['all']['lost']++; }
		if (!$won && $r['status'] === 'Open') { $fun['all']['open']++; }
	}
	foreach (array('ch', 'svc') as $g) { $fun[$g] = array_values($fun[$g]); usort($fun[$g], function ($a, $b) { return $b['leads'] - $a['leads']; }); $fun[$g] = array_slice($fun[$g], 0, 12); }
	$out['funnel'] = $fun;

	/* 6) szolgáltatásmix */
	$req = array(); $won_s = array(); $multi = 0; $biz = array('lead' => array('biz' => 0, 'priv' => 0, 'none' => 0), 'won' => array('biz' => 0, 'priv' => 0, 'none' => 0));
	foreach ($P as $r) {
		foreach ($r['svcs'] as $s) { $req[$s] = (isset($req[$s]) ? $req[$s] : 0) + 1; }
		$biz['lead'][cp_st_ctype_key($r['ctype'])]++;
	}
	$vals = array();
	foreach ($W as $r) {
		$s = $r['svc'] !== '' ? $r['svc'] : ($r['svcs'] ? implode(' + ', $r['svcs']) : 'Nincs megadva');
		if (!isset($won_s[$s])) { $won_s[$s] = array('k' => $s, 'n' => 0, 'value' => 0, 'vn' => 0); }
		$won_s[$s]['n']++;
		if ($r['huf'] && $r['value'] > 0) { $won_s[$s]['value'] += $r['value']; $won_s[$s]['vn']++; $vals[] = $r['value']; }
		if (count($r['svcs']) >= 2 || preg_match('/&|\+/', $r['svc'])) { $multi++; }
		$biz['won'][cp_st_ctype_key($r['ctype'])]++;
	}
	arsort($req);
	$won_s = array_values($won_s);
	usort($won_s, function ($a, $b) { return $b['n'] - $a['n']; });
	$reqn = 0; foreach ($P as $r) { if ($r['svcs']) { $reqn++; } }
	$out['mix'] = array('req' => array_slice($req, 0, 15, true), 'reqCover' => array($reqn, count($P)), 'won' => array_slice($won_s, 0, 15), 'avg' => $vals ? round(array_sum($vals) / count($vals)) : null, 'multi' => $multi, 'wonN' => count($W), 'biz' => $biz);

	/* 7) kapacitás: foglalt gépek a csúcsnapokon (rendezvénydátum a periódusban) */
	$p = cp_panel_opts();
	$days = array();
	foreach ($rows as $r) {
		if (!cp_st_in($r['event_d'], $f, $t) || empty($r['signed_d'])) { continue; }
		if (!isset($days[$r['event_d']])) { $days[$r['event_d']] = array('d' => $r['event_d'], 'selfie' => 0, 'spin' => 0, 'other' => 0, 'n' => 0); }
		$days[$r['event_d']]['n']++;
		/* a gépfoglaltság csak a fotóautomatára és a spinnerre értelmezett; a többi szolgáltatás „egyéb” */
		if ($r['kind'] === 'spin') { $days[$r['event_d']]['spin']++; }
		elseif ($r['kind'] === 'both') { $days[$r['event_d']]['spin']++; $days[$r['event_d']]['selfie']++; }
		elseif ($r['kind'] === 'selfie') { $days[$r['event_d']]['selfie']++; }
		else { $days[$r['event_d']]['other']++; }
	}
	usort($days, function ($a, $b) use ($p) { $x = $a['selfie'] / max(1, (int) $p['cap_selfie']) + $a['spin'] / max(1, (int) $p['cap_spin']); $y = $b['selfie'] / max(1, (int) $p['cap_selfie']) + $b['spin'] / max(1, (int) $p['cap_spin']); return $y <=> $x ?: ($b['n'] - $a['n']) ?: strcmp($a['d'], $b['d']); });
	$o = cp_st_opts();
	$busy = 0; $busy_any = 0;
	$re = '/' . str_replace('/', '\/', $o['busy_re']) . '/iu';
	foreach ($rows as $r) {
		if ($r['lost_reason'] === '' || @preg_match($re, $r['lost_reason']) !== 1) { continue; }
		$busy_any++;
		if (cp_st_in($r['created_d'], $f, $cur_t)) { $busy++; }
	}
	$out['cap'] = array('top' => array_slice($days, 0, 10), 'capSelfie' => (int) $p['cap_selfie'], 'capSpin' => (int) $p['cap_spin'], 'busy' => $busy, 'busyAny' => $busy_any, 'full' => count(array_filter($days, function ($x) use ($p) { return $x['selfie'] >= (int) $p['cap_selfie'] || $x['spin'] >= (int) $p['cap_spin']; })));
	if (!$busy_any) { $out['miss'][] = 'busy'; }

	/* 8) fedezet szolgáltatásonként: a lezajlott, aláírt rendezvények bevétele − az Elszámolás kifizetései (− papír) */
	$out['margin'] = cp_st_margin($rows, $f, min($t, $today));

	/* 9) hőtérkép: napokra és órákra (napi/heti nézetnél az utolsó 90 nap) */
	$hf = $f; $ht = $cur_t;
	if ($gran === 'day' || $gran === 'week') { $hf = gmdate('Y-m-d', strtotime($cur_t . ' -89 days')); }
	$heat = array_fill(1, 7, array_fill(0, 24, 0));
	foreach ($rows as $r) { if (cp_st_in($r['created_d'], $hf, $ht)) { $heat[$r['wd']][$r['h']]++; } }
	$out['heat'] = array('from' => $hf, 'to' => $ht, 'm' => array_values(array_map('array_values', $heat)));

	$out['sync'] = cp_st_sync_info();
	return $out;
}

function cp_st_ctype_key($v) {
	$o = cp_st_opts();
	$v = cp_panel_lc($v);
	if ($v === '') { return 'none'; }
	if ($v === cp_panel_lc($o['ctype_biz'])) { return 'biz'; }
	if ($v === cp_panel_lc($o['ctype_priv'])) { return 'priv'; }
	return 'none';
}

function cp_st_ctype($v) {
	$k = cp_st_ctype_key($v);
	return $k === 'biz' ? 'Céges' : ($k === 'priv' ? 'Magán' : 'Nincs megadva');
}

/* AI kép önköltsége: csomagár / kredit × kredit per kép (pl. 58 800 / 10 000 × 10 = 5,88 Ft) */
function cp_st_ai_ft() {
	$o = cp_st_opts();
	return (int) $o['ai_pack_cr'] > 0 ? (float) $o['ai_pack_ft'] / (int) $o['ai_pack_cr'] * (float) $o['ai_cr_img'] : 0;
}

function cp_st_margin($rows, $f, $t) {
	$o = cp_st_opts();
	$paper = (int) $o['paper_ft'];
	$ai = cp_st_ai_ft();
	$are = '/' . str_replace('/', '\/', $o['ai_re']) . '/iu';
	$by = array();
	$noel = 0; $unpaid = 0; $n = 0; $ai_nocount = 0;
	if ($t < $f) { return array('rows' => array(), 'n' => 0, 'noel' => 0, 'unpaid' => 0, 'paper' => $paper, 'ai' => $ai, 'aiNoCount' => 0); }
	foreach ($rows as $r) {
		if (empty($r['signed_d']) || !cp_st_in($r['event_d'], $f, $t) || !$r['huf']) { continue; }
		$n++;
		$el = function_exists('cp_el_st_get') ? cp_el_st_get((string) $r['id']) : array();
		if (empty($el['r']) || !is_array($el['r'])) { $noel++; continue; }
		$cost = 0; $pa = 0; $ok = true;
		foreach ($el['r'] as $row) {
			if (!is_array($row)) { continue; }
			if (!isset($row['pv']) || $row['pv'] === '') { $ok = false; break; }
			$cost += (int) $row['pv'];
			$pa += isset($row['pa']) ? (int) $row['pa'] : 0;
		}
		if (!$ok) { $unpaid++; continue; }
		$cost += $paper * $pa;
		$s = $r['svc'] !== '' ? $r['svc'] : ($r['svcs'] ? implode(' + ', $r['svcs']) : 'Nincs megadva');
		/* AI-szolgáltatás: a képek száma = az Elszámolás „Papírfogyasztás (db)” mezője */
		if ($ai > 0 && @preg_match($are, $s . ' ' . implode(' ', $r['svcs'])) === 1) {
			if ($pa > 0) { $cost += $ai * $pa; } else { $ai_nocount++; }
		}
		if (!isset($by[$s])) { $by[$s] = array('k' => $s, 'n' => 0, 'rev' => 0, 'cost' => 0); }
		$by[$s]['n']++;
		$by[$s]['rev'] += $r['value'];
		$by[$s]['cost'] += $cost;
	}
	$by = array_values($by);
	usort($by, function ($a, $b) { return $b['rev'] - $a['rev']; });
	return array('rows' => $by, 'n' => $n, 'noel' => $noel, 'unpaid' => $unpaid, 'paper' => $paper, 'ai' => round($ai, 2), 'aiNoCount' => $ai_nocount);
}

function cp_st_sync_info() {
	global $wpdb;
	$st = cp_st_state();
	$n = cp_st_db_ok() ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . cp_st_t('deals')) : 0;
	$busy = false;
	foreach (array('deal', 'contact', 'act') as $k) { if (empty($st[$k . '_full_at']) || !empty($st[$k . '_redo'])) { $busy = true; } }
	return array(
		'last'   => isset($st['last_ok']) ? (int) $st['last_ok'] : 0,
		'run'    => isset($st['last_run']) ? (int) $st['last_run'] : 0,
		'err'    => isset($st['last_err']) ? $st['last_err'] : '',
		'deals'  => $n,
		'busy'   => $busy && empty($st['deal_full_at']),
		'meta'   => array('on' => cp_st_has_secret('meta_token') && (bool) cp_st_meta_accounts(), 'ok' => isset($st['meta_ok']) ? (int) $st['meta_ok'] : 0, 'err' => isset($st['meta_err']) ? $st['meta_err'] : ''),
		'li'     => array('on' => cp_st_has_secret('windsor_key') && (bool) cp_st_li_accounts(), 'ok' => isset($st['wli_ok']) ? (int) $st['wli_ok'] : 0, 'err' => isset($st['wli_err']) ? $st['wli_err'] : '', 'rate' => cp_st_eur_huf()),
		'gads'   => array('on' => cp_st_has_secret('gads_secret_hash'), 'at' => isset($st['gads_at']) ? (int) $st['gads_at'] : 0),
		'ga'     => cp_st_has_secret('ga_secret_json') && (bool) cp_st_ga_props(),
	);
}

/* ------------------------------------------------------------ AJAX (panel: közös jelszó + CSRF, mint a többi fül) */

add_action('wp_ajax_nopriv_cp_st_data', 'cp_st_ajax_data');
add_action('wp_ajax_cp_st_data', 'cp_st_ajax_data');
function cp_st_ajax_data() {
	cp_el_auth();
	if (!cp_st_db_ok()) { cp_el_err('Az adatbázis-táblák még készülnek – próbáld újra egy perc múlva.', 503); }
	$gran = isset($_REQUEST['g']) ? sanitize_key(wp_unslash($_REQUEST['g'])) : 'month';
	if (!in_array($gran, array('day', 'week', 'month', 'quarter', 'year'), true)) { $gran = 'month'; }
	$a = cp_el_req_date('a');
	if ($a === '') { $a = cp_st_today(); }
	$ck = 'cp_st_c_' . md5($gran . '|' . $a . '|' . get_option('cp_st_ver', '') . '|' . cp_st_today() . '|' . wp_json_encode(cp_st_opts()));
	$c = empty($_REQUEST['refresh']) ? get_transient($ck) : false;
	if (!is_array($c)) {
		$c = cp_st_compute($gran, $a);
		set_transient($ck, $c, 10 * MINUTE_IN_SECONDS);
	}
	$c['li'] = array_values(cp_st_li_all());
	wp_send_json_success($c);
}

add_action('wp_ajax_nopriv_cp_st_ga', 'cp_st_ajax_ga');
add_action('wp_ajax_cp_st_ga', 'cp_st_ajax_ga');
function cp_st_ajax_ga() {
	cp_el_auth();
	if (!cp_st_has_secret('ga_secret_json') || !cp_st_ga_props()) { wp_send_json_success(array('off' => true)); }
	$out = array('live' => cp_st_ga_live());
	if (!empty($_REQUEST['f']) && !empty($_REQUEST['to'])) {
		$f = cp_el_req_date('f'); $t = cp_el_req_date('to');
		$pf = cp_el_req_date('pf'); $pt = cp_el_req_date('pt');
		$yf = cp_el_req_date('yf'); $yt = cp_el_req_date('yt');
		if ($f !== '' && $t !== '') {
			$r = array('cur' => array($f, $t));
			if ($pf !== '' && $pt !== '') { $r['prev'] = array($pf, $pt); }
			if ($yf !== '' && $yt !== '') { $r['yoy'] = array($yf, $yt); }
			$out['period'] = cp_st_ga_period($r);
		}
	}
	wp_send_json_success($out);
}

add_action('wp_ajax_nopriv_cp_st_li', 'cp_st_ajax_li');
add_action('wp_ajax_cp_st_li', 'cp_st_ajax_li');
function cp_st_ajax_li() {
	cp_el_auth();
	$in = wp_unslash($_POST);
	$l = cp_st_li_all();
	if (cp_el_s($in, 'del') !== '') {
		$id = preg_replace('/[^a-z0-9]/', '', cp_el_s($in, 'del'));
		unset($l[$id]);
	} else {
		$f = cp_el_req_date('lf');
		$t = cp_el_req_date('lt');
		$amt = cp_el_int(str_replace(array('Ft', 'ft'), '', cp_el_s($in, 'amt')), 1, 100000000);
		$name = mb_substr(trim(sanitize_text_field(cp_el_s($in, 'name'))), 0, 120);
		if ($f === '' || $t === '' || $t < $f || cp_st_days($f, $t) > 400) { cp_el_err('Add meg az időszakot (legfeljebb 400 nap).'); }
		if ($amt === null || $amt === '') { cp_el_err('Hibás összeg.'); }
		if ($name === '') { $name = 'LinkedIn kampány'; }
		$id = cp_el_s($in, 'id') !== '' ? preg_replace('/[^a-z0-9]/', '', cp_el_s($in, 'id')) : strtolower(wp_generate_password(10, false, false));
		$l[$id] = array('id' => $id, 'from' => $f, 'to' => $t, 'name' => $name, 'amount' => (int) $amt,
			'clicks' => max(0, (int) cp_el_s($in, 'clicks')), 'impr' => max(0, (int) cp_el_s($in, 'impr')), 'conv' => max(0, (int) cp_el_s($in, 'conv')), 'ts' => time());
	}
	update_option(CP_ST_LI, $l, false);
	cp_st_li_rebuild();
	wp_send_json_success(array('li' => array_values($l)));
}

function cp_st_cfg() {
	return array(
		'on'    => true,
		'admin' => admin_url('options-general.php?page=cp-st'),
		'css'   => plugins_url('assets/stat.css', __FILE__) . '?v=' . CP_PANEL_VER,
	);
}

/* ------------------------------------------------------------ WP admin: Beállítások → Crazy statisztikák */

add_action('admin_menu', function () {
	add_options_page('Crazy statisztikák', 'Crazy statisztikák', 'manage_options', 'cp-st', 'cp_st_admin_page');
});

function cp_st_admin_save() {
	if (!current_user_can('manage_options') || empty($_POST['cp_st_save'])) { return array('', ''); }
	check_admin_referer('cp_st_save');
	$in = wp_unslash($_POST);
	$o = cp_st_opts();
	$once = '';
	foreach (array('f_svc', 'f_ctype', 'c_evtype', 'c_ad') as $k) { if (isset($in[$k])) { $o[$k] = preg_replace('/[^A-Za-z0-9_]/', '', $in[$k]); } }
	foreach (array('ctype_biz', 'ctype_priv', 'offer_from', 'busy_re', 'meta_ver') as $k) { if (isset($in[$k])) { $o[$k] = sanitize_text_field($in[$k]); } }
	if (@preg_match('/' . str_replace('/', '\/', $o['busy_re']) . '/iu', '') === false) { $o['busy_re'] = cp_st_defaults()['busy_re']; }
	foreach (array('f_req', 'rules', 'svc_alias') as $k) { if (isset($in[$k])) { $o[$k] = sanitize_textarea_field($in[$k]); } }
	if (isset($in['paper_ft'])) { $o['paper_ft'] = max(0, (int) $in['paper_ft']); }
	foreach (array('ai_pack_ft', 'ai_pack_cr', 'ai_cr_img') as $k) { if (isset($in[$k])) { $o[$k] = max(0, (int) $in[$k]); } }
	if (isset($in['ai_re'])) { $o['ai_re'] = sanitize_text_field($in['ai_re']); if (@preg_match('/' . str_replace('/', '\/', $o['ai_re']) . '/iu', '') === false) { $o['ai_re'] = cp_st_defaults()['ai_re']; } }
	if (isset($in['meta_act'])) { $o['meta_act'] = trim(preg_replace('/[^0-9,\s]/', '', $in['meta_act'])); }
	if (isset($in['ga_prop'])) { $o['ga_prop'] = sanitize_textarea_field($in['ga_prop']); }
	if (isset($in['li_act'])) { $o['li_act'] = trim(preg_replace('/[^0-9,\s]/', '', $in['li_act'])); }
	$rate_old = (float) $o['eur_huf'];
	if (isset($in['eur_huf'])) { $o['eur_huf'] = max(0, min(10000, round((float) str_replace(array(' ', ','), array('', '.'), $in['eur_huf']), 4))); }
	cp_st_save_opts($o);
	$msg = 'Beállítások elmentve.';
	if ((float) $o['eur_huf'] !== $rate_old) { cp_st_reprice(); $msg .= ' Az EUR-os költések újraszámolva ' . $o['eur_huf'] . ' Ft/EUR árfolyamon.'; }
	if (!empty($in['windsor_key_new'])) { cp_st_secret_set('windsor_key', trim($in['windsor_key_new'])); $st = cp_st_state(); unset($st['wli_at']); cp_st_state_set($st); $msg .= ' A Windsor API-kulcs elmentve.'; }
	if (!empty($in['windsor_key_clear'])) { cp_st_secret_set('windsor_key', ''); $msg .= ' A Windsor API-kulcs törölve.'; }
	if (!empty($in['meta_token_new'])) { cp_st_secret_set('meta_token', trim($in['meta_token_new'])); $st = cp_st_state(); unset($st['meta_at']); cp_st_state_set($st); $msg .= ' A Meta-token elmentve.'; }
	if (!empty($in['meta_token_clear'])) { cp_st_secret_set('meta_token', ''); $msg .= ' A Meta-token törölve.'; }
	$json = '';
	if (!empty($_FILES['ga_json']['tmp_name']) && is_uploaded_file($_FILES['ga_json']['tmp_name']) && (int) $_FILES['ga_json']['size'] < 20000) { $json = (string) file_get_contents($_FILES['ga_json']['tmp_name']); }
	elseif (!empty($in['ga_json_paste'])) { $json = trim($in['ga_json_paste']); }
	if ($json !== '') {
		$k = json_decode($json, true);
		if (!is_array($k) || empty($k['client_email']) || empty($k['private_key']) || (isset($k['type']) && $k['type'] !== 'service_account')) {
			$msg .= ' A JSON-kulcs érvénytelen (szolgáltatásfiók kulcs kell, client_email + private_key).';
		} else {
			cp_st_secret_set('ga_secret_json', wp_json_encode(array('client_email' => $k['client_email'], 'private_key' => $k['private_key'])));
			update_option('cp_st_ga_email', sanitize_email($k['client_email']), false);
			delete_transient('cp_st_ga_at');
			$msg .= ' A GA4 JSON-kulcs elmentve.';
		}
	}
	if (!empty($in['ga_clear'])) { cp_st_secret_set('ga_secret_json', ''); delete_option('cp_st_ga_email'); delete_transient('cp_st_ga_at'); $msg .= ' A GA4 kulcs törölve.'; }
	if (!empty($in['gads_new'])) {
		$key = wp_generate_password(40, false, false);
		cp_st_secret_set('gads_secret_hash', hash('sha256', $key));
		$once = $key;
		$msg .= ' Új Google Ads-kulcs készült – most másold be a szkriptbe, később nem lesz látható.';
	}
	return array($msg, $once);
}

function cp_st_admin_page() {
	if (!current_user_can('manage_options')) { return; }
	list($msg, $once) = cp_st_admin_save();
	$act = isset($_GET['cp_st_do']) ? sanitize_key(wp_unslash($_GET['cp_st_do'])) : '';
	$res = '';
	if ($act !== '' && check_admin_referer('cp_st_do')) {
		if ($act === 'sync') { $res = cp_st_sync(true); }
		if ($act === 'full') { $st = cp_st_state(); foreach (array('deal', 'contact', 'act') as $k) { $st[$k . '_page'] = 0; $st[$k . '_redo'] = 1; $st[$k . '_run'] = gmdate('Y-m-d H:i:s'); } cp_st_state_set($st); $res = cp_st_sync(true); }
		if ($act === 'meta') { $res = cp_st_meta_sync(true); if ($res === '') { $res = 'Nincs beállítva a Meta-token vagy a hirdetési fiók.'; } }
		if ($act === 'windsor') { $res = cp_st_windsor_sync(true); if ($res === '') { $res = 'Nincs beállítva a Windsor API-kulcs vagy a LinkedIn hirdetési fiók.'; } }
		if ($act === 'ga') {
			delete_transient('cp_st_ga_live');
			$l = cp_st_ga_live();
			$parts = array();
			foreach ($l['sites'] as $x) { $parts[] = $x['name'] . ': ' . (isset($x['err']) ? 'HIBA – ' . $x['err'] : 'OK, most ' . (int) $x['users'] . ' aktív felhasználó, ' . (int) $x['views'] . ' oldalmegtekintés (30 perc)'); }
			$res = $parts ? implode(' · ', $parts) : 'Nincs megadva GA4 property ID.';
		}
	}
	$o = cp_st_opts();
	$st = cp_st_state();
	$si = cp_st_sync_info();
	$fmt = function ($t) { return $t ? wp_date('Y.m.d. H:i', (int) $t) : '–'; };
	$do = function ($a, $label) { return '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('options-general.php?page=cp-st&cp_st_do=' . $a), 'cp_st_do')) . '">' . esc_html($label) . '</a> '; };
	echo '<div class="wrap"><h1>Crazy statisztikák</h1>';
	if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
	if ($res) { echo '<div class="notice notice-info"><p>' . esc_html($res) . '</p></div>'; }
	if ($once !== '') {
		echo '<div class="notice notice-warning"><p><b>Google Ads-szkript kulcsa (csak most látszik):</b></p><p><input type="text" readonly value="' . esc_attr($once) . '" style="width:28em;font-family:monospace" onclick="this.select()"></p><p>Másold be a szkript <code>KEY</code> sorába. Ha elveszik, készíts újat (a régi akkor érvénytelen lesz).</p></div>';
	}
	echo '<p>A panel <b>Statisztikák</b> fülének beállításai. A titkos adatok (token, JSON-kulcs) titkosítva tárolódnak, és sehol nem jelennek meg újra.</p>';
	echo '<h2>Szinkron</h2><table class="form-table">';
	cp_panel_row('Állapot', 'Utolsó sikeres kör: <b>' . esc_html($fmt($si['last'])) . '</b> · dealek a táblában: <b>' . (int) $si['deals'] . '</b>' . ($si['busy'] ? ' · <i>az első teljes beolvasás folyamatban</i>' : '') . (!empty($st['last_log']) ? '<br><small>' . esc_html($st['last_log']) . ' (' . (int) (isset($st['last_calls']) ? $st['last_calls'] : 0) . ' hívás, ' . esc_html(isset($st['last_sec']) ? $st['last_sec'] : '') . ' mp)</small>' : '') . ($si['err'] ? '<br><b style="color:#b32d2e">' . esc_html($si['err']) . '</b>' : '') . '<br><small>Következő ütemezett kör: ' . esc_html($fmt(wp_next_scheduled('cp_st_cron'))) . '</small>');
	cp_panel_row('Műveletek', $do('sync', 'Szinkron most') . $do('full', 'Teljes újraolvasás'));
	cp_panel_row('Valódi cron', '<p class="description">A WP-Cron csak oldallátogatáskor fut. A Plesk → <b>Ütemezett feladatok</b> (Scheduled Tasks) menüben vegyél fel egy feladatot 5 percenként: <code>Fetch a URL</code> → <code>' . esc_html(site_url('wp-cron.php?doing_wp_cron')) . '</code>, majd a <code>wp-config.php</code>-ba írd be: <code>define(\'DISABLE_WP_CRON\', true);</code></p>');
	echo '</table>';
	echo '<form method="post" enctype="multipart/form-data">';
	wp_nonce_field('cp_st_save');
	echo '<h2>Meta (Facebook / Instagram) hirdetések</h2><table class="form-table">';
	cp_panel_row('Hirdetési fiók azonosítója', '<input name="meta_act" value="' . esc_attr($o['meta_act']) . '" class="regular-text" placeholder="pl. 1234567890 (act_ nélkül)"><p class="description">Több fiók (pl. EventCrazy és Crazyphoto) vesszővel elválasztva.</p>');
	cp_panel_row('System user token', '<input type="password" name="meta_token_new" class="regular-text" autocomplete="new-password" placeholder="' . (cp_st_has_secret('meta_token') ? 'Beállítva – csere esetén írd be az újat' : 'Még nincs beállítva') . '"> <label><input type="checkbox" name="meta_token_clear" value="1"> törlés</label><p class="description">ads_read jogosultságú system user token. Csak írható mező.</p>');
	cp_panel_row('Graph API verzió', '<input name="meta_ver" value="' . esc_attr($o['meta_ver']) . '" class="small-text">');
	cp_panel_row('Állapot', 'Utolsó sikeres lekérés: ' . esc_html($fmt(isset($st['meta_ok']) ? $st['meta_ok'] : 0)) . (!empty($st['meta_err']) ? ' · <b style="color:#b32d2e">' . esc_html($st['meta_err']) . '</b>' : '') . ' ' . $do('meta', 'Lekérés most'));
	echo '</table><h2>LinkedIn hirdetések (Windsor.ai)</h2><table class="form-table">';
	cp_panel_row('Windsor API-kulcs', '<input type="password" name="windsor_key_new" class="regular-text" autocomplete="new-password" placeholder="' . (cp_st_has_secret('windsor_key') ? 'Beállítva – csere esetén írd be az újat' : 'Még nincs beállítva') . '"> <label><input type="checkbox" name="windsor_key_clear" value="1"> törlés</label><p class="description">A windsor.ai fiókod API-kulcsa (onboard.windsor.ai → API key). Csak írható mező, titkosítva tárolódik.</p>');
	cp_panel_row('LinkedIn hirdetési fiók(ok)', '<input name="li_act" value="' . esc_attr($o['li_act']) . '" class="regular-text" placeholder="pl. 559891788"><p class="description">Több fiók vesszővel elválasztva.</p>');
	cp_panel_row('EUR/HUF árfolyam', '<input name="eur_huf" value="' . esc_attr(str_replace('.', ',', (string) $o['eur_huf'])) . '" class="small-text" inputmode="decimal"> Ft / EUR<p class="description">Az EUR-ban költő fiókok összegét ezen az árfolyamon számolja forintra (az eredeti összeg és pénznem is tárolódik). Módosításkor a korábbi EUR-os napok is újraszámolódnak.</p>');
	cp_panel_row('Állapot', 'Utolsó sikeres lekérés: ' . esc_html($fmt(isset($st['wli_ok']) ? $st['wli_ok'] : 0)) . (!empty($st['wli_err']) ? ' · <b style="color:#b32d2e">' . esc_html($st['wli_err']) . '</b>' : '') . ' ' . $do('windsor', 'Lekérés most') . '<p class="description">Naponta egyszer fut (az utolsó 30 nap újraírva, az első körben ~400 nap előzmény). Ahol van Windsor-adat, ott a kézi LinkedIn-tétel nem számít.</p>');
	echo '</table><h2>Google Ads</h2><table class="form-table">';
	cp_panel_row('Végpont', '<code>' . esc_html(rest_url('crazy-panel/v1/ads')) . '</code>');
	cp_panel_row('Szkript kulcsa', (cp_st_has_secret('gads_secret_hash') ? 'Beállítva (csak a hash-e tárolódik). ' : 'Még nincs. ') . '<label><input type="checkbox" name="gads_new" value="1"> új kulcs készítése mentéskor</label><br><small>Utolsó adatküldés a szkripttől: ' . esc_html($fmt(isset($st['gads_at']) ? $st['gads_at'] : 0)) . (isset($st['gads_n']) ? ' (' . (int) $st['gads_n'] . ' kampánynap)' : '') . '</small>');
	cp_panel_row('Szkript', '<p class="description">Minden Google Ads-fiókban (EventCrazy, Crazyphoto) ugyanez a szkript futhat, ugyanazzal a kulccsal; a fiókok adatai külön tárolódnak.</p><textarea readonly rows="12" style="width:100%;font-family:monospace;font-size:12px" onclick="this.select()">' . esc_textarea(cp_st_gads_script()) . '</textarea>');
	echo '</table><h2>Google Analytics 4</h2><table class="form-table">';
	cp_panel_row('GA4 tulajdonok', '<textarea name="ga_prop" rows="2" class="regular-text" placeholder="EventCrazy = 312345678&#10;Crazyphoto = 298765432">' . esc_textarea($o['ga_prop']) . '</textarea><p class="description">Soronként: név = property ID (Admin → Tulajdon részletei → TULAJDONAZONOSÍTÓ). Ugyanazt a szolgáltatásfiókot mindkét tulajdonhoz add hozzá.</p>');
	$gm = get_option('cp_st_ga_email', '');
	cp_panel_row('Szolgáltatásfiók JSON-kulcsa', (cp_st_has_secret('ga_secret_json') ? 'Feltöltve' . ($gm ? ' – ezt a címet add hozzá olvasóként a GA4-tulajdonhoz: <code>' . esc_html($gm) . '</code>' : '') . '<br>' : '') . '<input type="file" name="ga_json" accept=".json,application/json"> vagy beillesztés: <textarea name="ga_json_paste" rows="2" style="width:100%;font-family:monospace" autocomplete="off" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, … }"></textarea> <label><input type="checkbox" name="ga_clear" value="1"> törlés</label> ' . $do('ga', 'Kapcsolat tesztelése'));
	echo '</table><h2>Mezők és szabályok</h2><table class="form-table">';
	cp_panel_row('Szolgáltatás (deal)', '<input name="f_svc" value="' . esc_attr($o['f_svc']) . '">');
	cp_panel_row('Kért szolgáltatások (deal, többválasztós)', '<textarea name="f_req" rows="2" class="regular-text">' . esc_textarea($o['f_req']) . '</textarea>');
	cp_panel_row('Szolgáltatás-álnevek', '<textarea name="svc_alias" rows="3" class="regular-text">' . esc_textarea($o['svc_alias']) . '</textarea><p class="description">Soronként: <code>régi név = egységes név</code>. A statisztikában ezek egy szolgáltatásként számítanak. A csak kis/nagybetűben eltérő neveket (pl. „AI karikatúra” / „AI Karikatúra”) magától összevonja.</p>');
	cp_panel_row('Céges / magán (deal)', '<input name="f_ctype" value="' . esc_attr($o['f_ctype']) . '"> céges érték: <input name="ctype_biz" value="' . esc_attr($o['ctype_biz']) . '" class="small-text"> magán érték: <input name="ctype_priv" value="' . esc_attr($o['ctype_priv']) . '" class="small-text">');
	cp_panel_row('Rendezvénytípus (kontakt)', '<input name="c_evtype" value="' . esc_attr($o['c_evtype']) . '"> hirdetés neve (kontakt): <input name="c_ad" value="' . esc_attr($o['c_ad']) . '">');
	cp_panel_row('Ajánlat szakasz', '<input name="offer_from" value="' . esc_attr($o['offer_from']) . '" class="regular-text"><p class="description">Az ennél későbbi szakaszba jutott (vagy aláírt) deal „ajánlatig jutott”.</p>');
	cp_panel_row('Foglaltság miatt elvesztve', '<input name="busy_re" value="' . esc_attr($o['busy_re']) . '" class="regular-text"><p class="description">Az elvesztés okában keresett minta (|-vel elválasztva).</p>');
	cp_panel_row('Papír egységára (Ft/db)', '<input name="paper_ft" value="' . (int) $o['paper_ft'] . '" class="small-text" inputmode="numeric"> <span class="description">a fedezethez (0 = nem számolja)</span>');
	cp_panel_row('AI kép önköltsége', 'csomag ára <input name="ai_pack_ft" value="' . (int) $o['ai_pack_ft'] . '" class="small-text" inputmode="numeric"> Ft = <input name="ai_pack_cr" value="' . (int) $o['ai_pack_cr'] . '" class="small-text" inputmode="numeric"> kredit, egy kép <input name="ai_cr_img" value="' . (int) $o['ai_cr_img'] . '" class="small-text" inputmode="numeric"> kredit → <b>' . esc_html(number_format(cp_st_ai_ft(), 2, ',', ' ')) . ' Ft / kép</b><br>szolgáltatás mintája: <input name="ai_re" value="' . esc_attr($o['ai_re']) . '" class="regular-text"><p class="description">A fedezetben az AI-szolgáltatásos rendezvényeknél levonódik: képek száma (az Elszámolás „Papírfogyasztás (db)” mezője) × egy kép önköltsége.</p>');
	cp_panel_row('Csatornaszabályok', '<textarea name="rules" rows="10" style="width:100%;font-family:monospace">' . esc_textarea($o['rules']) . '</textarea><p class="description">Soronként: <code>source|tag|utm|medium|campaign: minta =&gt; csatorna</code>. Csatornák: google, meta, linkedin, organic, referral, ads, other. A fizetett UTM (cpc) elsőbbséget kap a Salesmate-forrással szemben.</p>');
	echo '</table>';
	submit_button('Mentés');
	echo '<input type="hidden" name="cp_st_save" value="1"></form></div>';
}

function cp_st_gads_script() {
	return "/* Crazy panel – Google Ads költség küldése (óránként futtasd) */\n"
		. "var URL = '" . rest_url('crazy-panel/v1/ads') . "';\n"
		. "var KEY = 'IDE_MÁSOLD_A_KULCSOT';\n"
		. "var DAYS = 7; // az ELSŐ kézi futtatásnál írd át 400-ra (előzmények), utána állítsd vissza 7-re\n\n"
		. "function main() {\n"
		. "  var tz = AdsApp.currentAccount().getTimeZone();\n"
		. "  var now = new Date();\n"
		. "  var to = Utilities.formatDate(now, tz, 'yyyy-MM-dd');\n"
		. "  var from = Utilities.formatDate(new Date(now.getTime() - DAYS * 864e5), tz, 'yyyy-MM-dd');\n"
		. "  var q = 'SELECT segments.date, campaign.id, campaign.name, metrics.cost_micros, metrics.clicks, ' +\n"
		. "          'metrics.impressions, metrics.conversions FROM campaign ' +\n"
		. "          \"WHERE segments.date BETWEEN '\" + from + \"' AND '\" + to + \"'\";\n"
		. "  var it = AdsApp.search(q);\n"
		. "  var rows = [];\n"
		. "  while (it.hasNext()) {\n"
		. "    var r = it.next();\n"
		. "    rows.push({ d: r.segments.date, id: String(r.campaign.id), name: r.campaign.name,\n"
		. "      spend: Number(r.metrics.costMicros || 0) / 1e6, clicks: Number(r.metrics.clicks || 0),\n"
		. "      impr: Number(r.metrics.impressions || 0), conv: Number(r.metrics.conversions || 0) });\n"
		. "  }\n"
		. "  var res = UrlFetchApp.fetch(URL, { method: 'post', contentType: 'application/json',\n"
		. "    headers: { 'X-CP-Key': KEY }, muteHttpExceptions: true,\n"
		. "    payload: JSON.stringify({ acct: AdsApp.currentAccount().getCustomerId(), from: from, to: to, rows: rows }) });\n"
		. "  Logger.log(res.getResponseCode() + ' ' + res.getContentText());\n"
		. "  if (res.getResponseCode() !== 200) { throw new Error('A küldés nem sikerült: ' + res.getContentText()); }\n"
		. "}\n";
}

/* költségtábla-összesítő a hibakereséshez (csatorna × fiók × hónap; titkot nem tartalmaz, a Claude-híd olvashatja) */
add_action('init', function () {
	if (get_transient('cp_st_adsdiag_t') || !cp_st_db_ok()) { return; }
	set_transient('cp_st_adsdiag_t', 1, 10 * MINUTE_IN_SECONDS);
	global $wpdb;
	$t = cp_st_t('ads');
	$r = $wpdb->get_results("SELECT channel, IF(LOCATE(':', campaign_id) > 0, SUBSTRING_INDEX(campaign_id, ':', 1), '(nincs)') acct, DATE_FORMAT(d, '%Y-%m') m, COUNT(*) n, COUNT(DISTINCT campaign_id) camps, ROUND(SUM(spend)) s, SUM(clicks) c, SUM(impressions) i, MAX(updated_at) u FROM $t WHERE d >= DATE_SUB(CURDATE(), INTERVAL 14 MONTH) GROUP BY channel, acct, m ORDER BY channel, acct, m", ARRAY_A);
	$top = $wpdb->get_results("SELECT d, campaign_id, campaign_name, spend, clicks, impressions FROM $t WHERE channel = 'google' ORDER BY spend DESC LIMIT 15", ARRAY_A);
	$d = cp_st_t('deals'); $c = cp_st_t('contacts');
	$src = $wpdb->get_results("SELECT DATE_FORMAT(x.created_d, '%Y-%m') m, x.source, IFNULL(k.utm_source, '') us, IFNULL(k.utm_medium, '') um, COUNT(*) n FROM $d x LEFT JOIN $c k ON k.id = x.contact_id WHERE x.created_d >= DATE_SUB(CURDATE(), INTERVAL 13 MONTH) GROUP BY m, x.source, us, um ORDER BY m, n DESC", ARRAY_A);
	update_option('cp_st_adsdiag', array('at' => time(), 'rows' => $r, 'google_top' => $top, 'lead_src' => $src), false);
});

/* az egyszeri riport-adatgyűjtés ideiglenes adatának törlése */
add_action('init', function () { if (get_option('cp_st_report_tmp') !== false) { delete_option('cp_st_report_tmp'); } });
