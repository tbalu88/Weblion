<?php
/**
 * v2.4: Kontaktok és cégek összevonása (Kapcsolatok fül)
 *  – cp_crm_dups: a rendszer által javasolt lehetséges duplikátum-párok (e-mail, telefon, név, cégnév, weboldal, e-mail-domain alapján),
 *    a „Nem ugyanaz” jelölésűek kimaradnak. Csak olvas.
 *  – cp_crm_dup_ignore: egy pár megjelölése „nem ugyanaz”-ként.
 *  – cp_crm_merge: egy vagy több rekord összevonása a megtartott rekordba (csak admin, ha a Salesmate-be írás be van kapcsolva):
 *      1. a megtartott rekord üres mezőit kitölti a többiből, az eltérő e-mail/telefon a leírásba kerül;
 *      2. a többi rekord deal-jei (céges összevonásnál a kontaktjai is) átkerülnek a megtartottra;
 *      3. ha minden áthelyezés sikerült, a többi rekord törlődik (a Salesmate-ben a Lomtárból 30 napig visszaállítható).
 *    Ha bármelyik áthelyezés nem sikerül, a rekord NEM törlődik (nem vész el kapcsolat).
 */
if (!defined('ABSPATH')) { exit; }

/* ------------------------------------------------------------ normalizálás */
function cp_mrg_email($e) {
	$e = strtolower(trim((string) $e));
	if (!preg_match('/^([^@\s]+)@([^@\s]+)$/', $e, $m)) { return ''; }
	$l = $m[1]; $d = $m[2];
	if ($d === 'googlemail.com') { $d = 'gmail.com'; }
	if ($d === 'gmail.com') { $l = str_replace('.', '', preg_replace('/\+.*$/', '', $l)); }
	return $l . '@' . $d;
}
function cp_mrg_phone($p) {
	$d = preg_replace('/\D/', '', (string) $p);
	if (strlen($d) < 8) { return ''; }
	return substr($d, -9);
}
function cp_mrg_name($n) {
	$n = mb_strtolower(trim((string) $n));
	if (function_exists('remove_accents')) { $n = remove_accents($n); }
	$n = preg_replace('/[0-9]+/', ' ', $n);
	$w = preg_split('/[^a-z]+/', $n, -1, PREG_SPLIT_NO_EMPTY);
	$w = array_values(array_filter($w, function ($x) { return strlen($x) >= 2 && !in_array($x, array('dr', 'ifj', 'id', 'mr', 'mrs', 'ms'), true); }));
	sort($w);
	return count($w) >= 2 ? implode(' ', $w) : '';
}
function cp_mrg_co_norm($n) {
	$k = function_exists('cp_cos_norm') ? cp_cos_norm($n) : mb_strtolower(trim((string) $n));
	if (function_exists('remove_accents')) { $k = remove_accents($k); }
	return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower($k))));
}
function cp_mrg_host($u) {
	$h = strtolower((string) wp_parse_url((preg_match('#^https?://#i', (string) $u) ? '' : 'https://') . trim((string) $u), PHP_URL_HOST));
	return preg_replace('/^www\./', '', $h);
}
function cp_mrg_free($dom) { return function_exists('cp_cos_free') ? cp_cos_free($dom) : (bool) preg_match('/^(gmail|freemail|citromail|hotmail|outlook|yahoo|icloud)\./', $dom); }

/* ------------------------------------------------------------ „nem ugyanaz” jelölések */
function cp_mrg_ign_key($kind, $a, $b) { $a = (int) $a; $b = (int) $b; return $kind . ':' . min($a, $b) . '-' . max($a, $b); }
function cp_mrg_ign() { $x = get_option('cp_dup_ign', array()); return is_array($x) ? $x : array(); }

/* ------------------------------------------------------------ javaslatok */
function cp_mrg_dups($kind) {
	$ck = 'cp_dup_' . $kind;
	$c = get_transient($ck);
	if (is_array($c)) { return $c; }
	global $wpdb;
	$C = cp_crm_t('contacts'); $CO = cp_crm_t('companies'); $D = cp_crm_t('deals');
	$ign = array_flip(cp_mrg_ign());
	$pairs = array();
	$add = function ($a, $b, $why, $str) use (&$pairs, $ign, $kind) {
		if ($a === $b) { return; }
		$k = cp_mrg_ign_key($kind, $a, $b);
		if (isset($ign[$k])) { return; }
		if (!isset($pairs[$k])) { $pairs[$k] = array('a' => min($a, $b), 'b' => max($a, $b), 'why' => array(), 's' => 0); }
		if (!in_array($why, $pairs[$k]['why'], true)) { $pairs[$k]['why'][] = $why; $pairs[$k]['s'] += $str; }
	};
	if ($kind === 'ct') {
		$rows = (array) $wpdb->get_results("SELECT id, name, email, phone, mobile, company_id FROM $C WHERE deleted_at IS NULL", ARRAY_A);
		$by = array('e' => array(), 'p' => array(), 'n' => array());
		$info = array();
		foreach ($rows as $r) {
			$id = (int) $r['id'];
			$e = cp_mrg_email($r['email']); $n = cp_mrg_name($r['name']);
			$ph = array_unique(array_filter(array(cp_mrg_phone($r['mobile']), cp_mrg_phone($r['phone']))));
			$info[$id] = array('e' => $e, 'n' => $n, 'p' => $ph, 'co' => (int) $r['company_id'], 'dom' => $e ? substr(strrchr($e, '@'), 1) : '', 'loc' => $e ? strstr($e, '@', true) : '');
			if ($e) { $by['e'][$e][] = $id; }
			foreach ($ph as $p) { $by['p'][$p][] = $id; }
			if ($n) { $by['n'][$n][] = $id; }
		}
		$each = function ($list, $fn) { $list = array_values(array_unique($list)); $c = count($list); if ($c < 2 || $c > 12) { return; } for ($i = 0; $i < $c; $i++) { for ($j = $i + 1; $j < $c; $j++) { $fn($list[$i], $list[$j]); } } };
		$shareTok = function ($x, $y) { if (!$x || !$y) { return false; } return (bool) array_intersect(explode(' ', $x), explode(' ', $y)); };
		foreach ($by['e'] as $l) { $each($l, function ($a, $b) use ($add) { $add($a, $b, 'azonos e-mail-cím', 3); }); }
		foreach ($by['p'] as $l) { $each($l, function ($a, $b) use ($add, $info, $shareTok) { $sm = $shareTok($info[$a]['n'], $info[$b]['n']); $add($a, $b, 'azonos telefonszám', $sm || !$info[$a]['n'] || !$info[$b]['n'] ? 3 : 1); }); }
		foreach ($by['n'] as $l) {
			$each($l, function ($a, $b) use ($add, $info) {
				$x = $info[$a]; $y = $info[$b];
				if ($x['co'] && $x['co'] === $y['co']) { $add($a, $b, 'azonos név és cég', 2); }
				elseif ($x['dom'] && $x['dom'] === $y['dom'] && !cp_mrg_free($x['dom'])) { $add($a, $b, 'azonos név, azonos céges e-mail-domain', 2); }
				elseif ($x['loc'] && $y['loc'] && $x['dom'] === $y['dom'] && levenshtein($x['loc'], $y['loc']) <= 2) { $add($a, $b, 'azonos név, majdnem azonos e-mail-cím', 2); }
				elseif (!$x['e'] || !$y['e']) { $add($a, $b, 'azonos név (az egyiknek nincs e-mail-címe)', 1); }
			});
		}
	} else {
		$rows = (array) $wpdb->get_results("SELECT id, name, website FROM $CO WHERE deleted_at IS NULL", ARRAY_A);
		$generic = array_flip(array('budapesti', 'budapest', 'magyar', 'magyarorszagi', 'nemzeti', 'event', 'events', 'human', 'american', 'szegedi', 'debreceni', 'pecsi', 'gyori', 'hungary', 'hungaria', 'international', 'global', 'group', 'team', 'studio', 'media', 'creative', 'digital', 'marketing', 'consulting', 'services', 'solutions', 'rendezveny', 'iskola', 'egyetem', 'foundation', 'alapitvany', 'egyesulet', 'europe', 'european', 'central', 'national', 'first', 'smart', 'green', 'royal', 'grand', 'hotel', 'park', 'center', 'centre', 'kozpont', 'orszagos', 'allami', 'varosi'));
		$by = array(); $hosts = array(); $norm = array();
		foreach ($rows as $r) {
			$id = (int) $r['id']; $k = cp_mrg_co_norm($r['name']);
			if ($k !== '') { $by[$k][] = $id; $norm[$id] = $k; }
			$h = cp_mrg_host($r['website']);
			if ($h && strpos($h, '.') !== false && !cp_mrg_free($h) && !preg_match('/(facebook|instagram|linkedin|google)\./', $h)) { $hosts[$h][] = $id; }
		}
		$each = function ($list, $fn) { $list = array_values(array_unique($list)); $c = count($list); if ($c < 2 || $c > 12) { return; } for ($i = 0; $i < $c; $i++) { for ($j = $i + 1; $j < $c; $j++) { $fn($list[$i], $list[$j]); } } };
		foreach ($by as $l) { $each($l, function ($a, $b) use ($add) { $add($a, $b, 'azonos cégnév', 3); }); }
		foreach ($hosts as $l) { $each($l, function ($a, $b) use ($add) { $add($a, $b, 'azonos weboldal', 3); }); }
		/* az egyik név a másik eleje (pl. „Allianz Hungária” – „Allianz Hungária Biztosító”) */
		$keys = array_keys($by);
		foreach ($keys as $k1) {
			$w = explode(' ', $k1);
			if (count($w) === 1 && (strlen($k1) < 5 || isset($generic[$k1]))) { continue; }
			if (count($w) === 2 && isset($generic[$w[0]]) && isset($generic[$w[1]])) { continue; }
			foreach ($keys as $k2) {
				if ($k2 === $k1 || strlen($k2) <= strlen($k1) || strpos($k2, $k1 . ' ') !== 0) { continue; }
				foreach ($by[$k1] as $a) { foreach ($by[$k2] as $b) { $add($a, $b, 'a cégnév egyik a másik eleje', count($w) >= 2 ? 2 : 1); } }
			}
		}
		/* a cégekhez tartozó kontaktok ugyanarról a céges e-mail-domainről írnak */
		$doms = array();
		foreach ((array) $wpdb->get_results("SELECT company_id, LOWER(SUBSTRING_INDEX(email, '@', -1)) dom FROM $C WHERE deleted_at IS NULL AND company_id IS NOT NULL AND email LIKE '%@%' GROUP BY company_id, dom", ARRAY_A) as $r) {
			if ($r['dom'] === '' || cp_mrg_free($r['dom']) || !isset($norm[(int) $r['company_id']])) { continue; }
			$doms[$r['dom']][] = (int) $r['company_id'];
		}
		foreach ($doms as $l) { $each($l, function ($a, $b) use ($add) { $add($a, $b, 'a kontaktjaik ugyanarról a céges e-mail-domainről írnak', 1); }); }
	}
	/* a gyenge (csak 1 pontos) párok csak akkor, ha legalább az egyiknek van deal-je */
	$pairs = array_values($pairs);
	$ids = array();
	foreach ($pairs as $p) { $ids[$p['a']] = 1; $ids[$p['b']] = 1; }
	$det = $ids ? cp_mrg_details($kind, array_keys($ids)) : array();
	$out = array();
	foreach ($pairs as $p) {
		if (!isset($det[$p['a']], $det[$p['b']])) { continue; }
		if ($p['s'] < 2 && !$det[$p['a']]['n'] && !$det[$p['b']]['n']) { continue; }
		$p['lvl'] = $p['s'] >= 3 ? 'erős' : ($p['s'] >= 2 ? 'közepes' : 'gyenge');
		$p['A'] = $det[$p['a']]; $p['B'] = $det[$p['b']];
		$out[] = $p;
	}
	usort($out, function ($x, $y) { if ($x['s'] !== $y['s']) { return $y['s'] - $x['s']; } return ($y['A']['n'] + $y['B']['n']) - ($x['A']['n'] + $x['B']['n']); });
	$out = array_slice($out, 0, 300);
	set_transient($ck, $out, 15 * MINUTE_IN_SECONDS);
	return $out;
}
/* rekordok összefoglalója a javaslatokhoz és az összevonó ablakhoz */
function cp_mrg_details($kind, $ids) {
	global $wpdb;
	$ids = array_values(array_filter(array_map('intval', (array) $ids)));
	if (!$ids) { return array(); }
	$in = implode(',', $ids);
	$C = cp_crm_t('contacts'); $CO = cp_crm_t('companies'); $D = cp_crm_t('deals');
	$out = array();
	if ($kind === 'ct') {
		$rows = (array) $wpdb->get_results("SELECT c.id, c.name, c.email, c.phone, c.mobile, c.city, c.created_at, co.name co, (SELECT COUNT(*) FROM $D d WHERE d.contact_id = c.id AND d.deleted_at IS NULL) n, (SELECT MAX(d.event_d) FROM $D d WHERE d.contact_id = c.id AND d.deleted_at IS NULL) last_ev FROM $C c LEFT JOIN $CO co ON co.id = c.company_id WHERE c.id IN ($in) AND c.deleted_at IS NULL", ARRAY_A);
	} else {
		$rows = (array) $wpdb->get_results("SELECT co.id, co.name, co.website, co.phone, co.created_at, (SELECT COUNT(*) FROM $C c WHERE c.company_id = co.id AND c.deleted_at IS NULL) cts, (SELECT COUNT(*) FROM $D d WHERE d.company_id = co.id AND d.deleted_at IS NULL) n, (SELECT MAX(d.event_d) FROM $D d WHERE d.company_id = co.id AND d.deleted_at IS NULL) last_ev FROM $CO co WHERE co.id IN ($in) AND co.deleted_at IS NULL", ARRAY_A);
	}
	foreach ($rows as $r) {
		foreach (array('id', 'n', 'cts') as $k) { if (isset($r[$k])) { $r[$k] = (int) $r[$k]; } }
		$r['created_at'] = function_exists('cp_crm_local') ? cp_crm_local($r['created_at']) : $r['created_at'];
		$out[(int) $r['id']] = $r;
	}
	return $out;
}

add_action('wp_ajax_nopriv_cp_crm_dups', 'cp_mrg_ajax_dups');
add_action('wp_ajax_cp_crm_dups', 'cp_mrg_ajax_dups');
function cp_mrg_ajax_dups() {
	cp_crm_ajax_guard();
	if (function_exists('cp_srch_can') && !cp_srch_can('kapcsolatok')) { cp_el_err('Ehhez a fülhöz nincs hozzáférésed.', 403); }
	$kind = ($_REQUEST['kind'] ?? '') === 'co' ? 'co' : 'ct';
	if (!empty($_REQUEST['fresh'])) { delete_transient('cp_dup_' . $kind); }
	$l = cp_mrg_dups($kind);
	wp_send_json_success(array('kind' => $kind, 'pairs' => $l, 'n' => count($l)));
}
/* rekordok az összevonó ablakhoz (kézi kijelölésnél) */
add_action('wp_ajax_nopriv_cp_crm_merge_info', 'cp_mrg_ajax_info');
add_action('wp_ajax_cp_crm_merge_info', 'cp_mrg_ajax_info');
function cp_mrg_ajax_info() {
	cp_crm_ajax_guard();
	if (function_exists('cp_srch_can') && !cp_srch_can('kapcsolatok')) { cp_el_err('Ehhez a fülhöz nincs hozzáférésed.', 403); }
	$kind = ($_REQUEST['kind'] ?? '') === 'co' ? 'co' : 'ct';
	$ids = array_slice(array_map('intval', explode(',', (string) ($_REQUEST['ids'] ?? ''))), 0, 6);
	wp_send_json_success(array('kind' => $kind, 'rows' => array_values(cp_mrg_details($kind, $ids))));
}

add_action('wp_ajax_nopriv_cp_crm_dup_ignore', 'cp_mrg_ajax_ignore');
add_action('wp_ajax_cp_crm_dup_ignore', 'cp_mrg_ajax_ignore');
function cp_mrg_ajax_ignore() {
	cp_crm_ajax_guard();
	if (function_exists('cp_srch_can') && !cp_srch_can('kapcsolatok')) { cp_el_err('Ehhez a fülhöz nincs hozzáférésed.', 403); }
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$kind = ($_POST['kind'] ?? '') === 'co' ? 'co' : 'ct';
	$a = (int) ($_POST['a'] ?? 0); $b = (int) ($_POST['b'] ?? 0);
	if (!$a || !$b || $a === $b) { cp_el_err('Hibás pár.'); }
	$l = cp_mrg_ign();
	$k = cp_mrg_ign_key($kind, $a, $b);
	if (!in_array($k, $l, true)) { $l[] = $k; }
	update_option('cp_dup_ign', array_slice($l, -3000), false);
	delete_transient('cp_dup_' . $kind);
	wp_send_json_success(array('ok' => 1));
}

/* ------------------------------------------------------------ összevonás */
add_action('wp_ajax_nopriv_cp_crm_merge', 'cp_mrg_ajax_merge');
add_action('wp_ajax_cp_crm_merge', 'cp_mrg_ajax_merge');
function cp_mrg_ajax_merge() {
	cp_crm_ajax_guard();
	if (function_exists('cp_srch_can') && !cp_srch_can('kapcsolatok')) { cp_el_err('Ehhez a fülhöz nincs hozzáférésed.', 403); }
	cp_admin_need();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (empty(cp_panel_opts()['stage_write'])) { cp_el_err('A Salesmate-be írás a panel beállításaiban ki van kapcsolva.'); }
	$kind = ($_POST['kind'] ?? '') === 'co' ? 'co' : 'ct';
	$keep = (int) ($_POST['keep'] ?? 0);
	$ids = array_values(array_diff(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))))), array($keep)));
	$ids = array_slice($ids, 0, 5);
	if (!$keep || !$ids) { cp_el_err('Válaszd ki, melyik rekord maradjon meg, és legalább egy másikat.'); }
	/* egyszerre csak egy összevonás fusson (két kolléga ugyanazt vonná össze) */
	if (!add_option('cp_merge_lk', time(), '', 'no')) {
		if ((int) get_option('cp_merge_lk') > time() - 300) { cp_el_err('Épp fut egy másik összevonás – próbáld újra fél perc múlva.'); }
		update_option('cp_merge_lk', time(), false);
	}
	@set_time_limit(290);
	$out = array('ok' => 0, 'moved' => 0, 'err' => array(), 'warn' => array());
	try {
		foreach ($ids as $lid) {
			$r = $kind === 'ct' ? cp_mrg_contact($keep, $lid) : cp_mrg_company($keep, $lid);
			$out['moved'] += $r['moved'];
			$out['warn'] = array_merge($out['warn'], $r['warn']);
			if ($r['err']) { $out['err'][] = $r['err']; } else { $out['ok']++; }
		}
	} finally {
		delete_option('cp_merge_lk');
	}
	delete_transient('cp_dup_ct'); delete_transient('cp_dup_co');
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	wp_send_json_success($out);
}

function cp_mrg_data($r) { $d = is_wp_error($r) ? null : (isset($r['Data']) ? $r['Data'] : $r); return is_array($d) ? $d : null; }
function cp_mrg_gone($r) { $d = cp_mrg_data($r); return is_wp_error($r) || !$d || empty($d['id']) || !empty($d['isDeleted']) || !empty($d['deletedAt']); }
function cp_mrg_hist($deal_id, $field, $old, $new) {
	global $wpdb;
	$wpdb->insert(cp_crm_t('history'), array('deal_id' => (int) $deal_id, 'field' => $field, 'old_val' => mb_substr((string) $old, 0, 2000), 'new_val' => mb_substr((string) $new, 0, 2000), 'by_user' => function_exists('cp_panel_who') ? cp_panel_who() : 'Crazy panel', 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel'));
}
/* a megtartott rekord üres mezőinek kitöltése + a leírásba a megszűnő rekord eltérő elérhetőségei */
function cp_mrg_patch($m, $K, $L, $fill, $cmp, $label) {
	$spec = cp_crm_spec($m);
	$body = array(); $extra = array();
	foreach ($fill as $col) {
		$kv = trim((string) ($K[$col] ?? '')); $lv = trim((string) ($L[$col] ?? ''));
		if ($lv === '') { continue; }
		if ($kv === '') { $body[$spec[$col][0]] = $col === 'company_id' ? (int) $lv : $lv; continue; }
		if (isset($cmp[$col]) && $cmp[$col]($kv) !== $cmp[$col]($lv)) { $extra[] = $label[$col] . ': ' . $lv; }
	}
	if ($m === 'contact' && trim((string) $K['name']) === '' && trim((string) $L['name']) !== '') { $body['firstName'] = ''; $body['lastName'] = (string) $L['name']; }
	$note = '[Összevonva ' . wp_date('Y-m-d') . ': ' . ($L['name'] !== '' ? $L['name'] : ($L['email'] ?? '#' . $L['id'])) . ' (#' . (int) $L['id'] . ')' . ($extra ? ' – ' . implode('; ', $extra) : '') . ']';
	$desc = trim((string) ($K['description'] ?? ''));
	$ld = trim((string) ($L['description'] ?? ''));
	$nd = trim($desc . "\n" . $note . ($ld !== '' && $ld !== $desc && mb_strpos($desc, $ld) === false ? "\n" . mb_substr($ld, 0, 1500) : ''));
	$body['description'] = mb_substr($nd, 0, 6000);
	return $body;
}

function cp_mrg_contact($kid, $lid) {
	global $wpdb;
	$C = cp_crm_t('contacts'); $D = cp_crm_t('deals'); $A = cp_crm_t('activities');
	$res = array('moved' => 0, 'warn' => array(), 'err' => '');
	$K = $wpdb->get_row($wpdb->prepare("SELECT * FROM $C WHERE id = %d AND deleted_at IS NULL", $kid), ARRAY_A);
	$L = $wpdb->get_row($wpdb->prepare("SELECT * FROM $C WHERE id = %d AND deleted_at IS NULL", $lid), ARRAY_A);
	if (!$K) { $res['err'] = 'A megtartandó kontakt (#' . $kid . ') már nem létezik.'; return $res; }
	if (!$L) { $res['err'] = '#' . $lid . ': már nem létezik (lehet, hogy közben összevonták).'; return $res; }
	$ln = $L['name'] !== '' ? $L['name'] : ($L['email'] !== '' ? $L['email'] : '#' . $lid);
	/* 1. a megtartott kontakt kiegészítése */
	$fill = array('email', 'mobile', 'phone', 'company_id', 'city', 'type', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fb_date', 'fb_type', 'fb_ad');
	$cmp = array('email' => 'cp_mrg_email', 'mobile' => 'cp_mrg_phone', 'phone' => 'cp_mrg_phone', 'city' => 'mb_strtolower');
	$lbl = array('email' => 'másik e-mail', 'mobile' => 'másik mobil', 'phone' => 'másik telefon', 'city' => 'másik város');
	$body = cp_mrg_patch('contact', $K, $L, $fill, $cmp, $lbl);
	$p = cp_sm_request('PUT', '/apis/contact/v4/' . $kid, $body);
	if (is_wp_error($p)) { $res['err'] = $ln . ': a megtartott kontaktot nem sikerült kiegészíteni (' . $p->get_error_message() . ') – semmi nem változott.'; return $res; }
	$g = cp_sm_request('GET', '/apis/contact/v4/' . $kid);
	if (cp_mrg_data($g)) { cp_crm_store('contact', array(cp_mrg_data($g)), 'get'); }
	$kname = $K['name'] !== '' ? $K['name'] : $K['email'];
	/* 2. a deal-ek áthelyezése */
	$bad = 0;
	foreach (array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $D WHERE contact_id = %d AND deleted_at IS NULL", $lid))) as $did) {
		$body = array('primaryContact' => $kid);
		$dc = (int) $wpdb->get_var($wpdb->prepare("SELECT company_id FROM $D WHERE id = %d", $did));
		$kc = (int) $wpdb->get_var($wpdb->prepare("SELECT company_id FROM $C WHERE id = %d", $kid));
		if (!$dc && $kc) { $body['primaryCompany'] = $kc; }
		$r = cp_sm_put_deal($did, $body);
		if (!is_wp_error($r)) { cp_crm_after_write($did); }
		$now = (int) $wpdb->get_var($wpdb->prepare("SELECT contact_id FROM $D WHERE id = %d", $did));
		if (is_wp_error($r) || $now !== $kid) { $bad++; $res['warn'][] = 'deal #' . $did . ': nem sikerült áthelyezni' . (is_wp_error($r) ? ' (' . $r->get_error_message() . ')' : ''); continue; }
		cp_mrg_hist($did, 'contact', $ln . ' (#' . $lid . ')', $kname . ' (#' . $kid . ') – kontaktok összevonása');
		$res['moved']++;
	}
	/* a kontakthoz (nem deal-hez) kötött tevékenységek */
	foreach (array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $A WHERE related_id = %d AND (related_module = '1' OR LOWER(related_module) LIKE 'contact%%')", $lid))) as $aid) {
		$r = cp_sm_request('PUT', '/apis/activity/v4/' . $aid, array('relatedTo' => $kid));
		if (is_wp_error($r)) { $res['warn'][] = 'tevékenység #' . $aid . ' a régi kontaktnál maradt'; } else { $wpdb->update($A, array('related_id' => $kid), array('id' => $aid)); }
	}
	if ($bad) { $res['err'] = $ln . ': ' . $bad . ' deal-t nem sikerült áthelyezni, ezért ezt a kontaktot nem töröltem.'; return $res; }
	/* 3. törlés */
	$r = cp_sm_request('DELETE', '/apis/contact/v4/' . $lid);
	if (is_wp_error($r) || !cp_mrg_gone(cp_sm_request('GET', '/apis/contact/v4/' . $lid))) { $res['err'] = $ln . ': a deal-jei átkerültek, de a régi kontaktot a Salesmate nem törölte' . (is_wp_error($r) ? ' (' . $r->get_error_message() . ')' : '') . ' – töröld kézzel.'; return $res; }
	$wpdb->update($C, array('deleted_at' => gmdate('Y-m-d H:i:s')), array('id' => $lid));
	/* a statisztikák saját táblái */
	if (function_exists('cp_st_t')) {
		$wpdb->query($wpdb->prepare('UPDATE IGNORE ' . cp_st_t('deals') . ' SET contact_id = %d WHERE contact_id = %d', $kid, $lid));
		$wpdb->query($wpdb->prepare('UPDATE IGNORE ' . cp_st_t('mail_ev') . ' SET contact_id = %d WHERE contact_id = %d', $kid, $lid));
	}
	cp_mrg_log('ct', $kid, $lid);
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'merge', 'Kontaktok összevonása', 'contact:' . $kid, $ln . ' (#' . $lid . ') → ' . $kname . ' (#' . $kid . '), ' . $res['moved'] . ' deal áthelyezve'); }
	return $res;
}

function cp_mrg_company($kid, $lid) {
	global $wpdb;
	$C = cp_crm_t('contacts'); $CO = cp_crm_t('companies'); $D = cp_crm_t('deals'); $A = cp_crm_t('activities');
	$res = array('moved' => 0, 'warn' => array(), 'err' => '');
	$K = $wpdb->get_row($wpdb->prepare("SELECT * FROM $CO WHERE id = %d AND deleted_at IS NULL", $kid), ARRAY_A);
	$L = $wpdb->get_row($wpdb->prepare("SELECT * FROM $CO WHERE id = %d AND deleted_at IS NULL", $lid), ARRAY_A);
	if (!$K) { $res['err'] = 'A megtartandó cég (#' . $kid . ') már nem létezik.'; return $res; }
	if (!$L) { $res['err'] = '#' . $lid . ': már nem létezik (lehet, hogy közben összevonták).'; return $res; }
	$ln = $L['name'] !== '' ? $L['name'] : '#' . $lid;
	$body = cp_mrg_patch('company', $K, $L, array('website', 'phone', 'type', 'employees'), array('website' => 'cp_mrg_host', 'phone' => 'cp_mrg_phone'), array('website' => 'másik weboldal', 'phone' => 'másik telefon'));
	$p = cp_sm_request('PUT', '/apis/company/v4/' . $kid, $body);
	if (is_wp_error($p)) { $res['err'] = $ln . ': a megtartott céget nem sikerült kiegészíteni (' . $p->get_error_message() . ') – semmi nem változott.'; return $res; }
	$g = cp_sm_request('GET', '/apis/company/v4/' . $kid);
	if (cp_mrg_data($g)) { cp_crm_store('company', array(cp_mrg_data($g)), 'get'); }
	$bad = 0;
	/* kontaktok */
	foreach (array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $C WHERE company_id = %d AND deleted_at IS NULL", $lid))) as $cid) {
		$r = cp_sm_request('PUT', '/apis/contact/v4/' . $cid, array('company' => $kid));
		$cg = is_wp_error($r) ? null : cp_mrg_data(cp_sm_request('GET', '/apis/contact/v4/' . $cid));
		if ($cg) { cp_crm_store('contact', array($cg), 'get'); }
		if ((int) $wpdb->get_var($wpdb->prepare("SELECT company_id FROM $C WHERE id = %d", $cid)) !== $kid) { $bad++; $res['warn'][] = 'kontakt #' . $cid . ': nem sikerült áthelyezni'; }
	}
	/* deal-ek */
	foreach (array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $D WHERE company_id = %d AND deleted_at IS NULL", $lid))) as $did) {
		$r = cp_sm_put_deal($did, array('primaryCompany' => $kid));
		if (!is_wp_error($r)) { cp_crm_after_write($did); }
		if (is_wp_error($r) || (int) $wpdb->get_var($wpdb->prepare("SELECT company_id FROM $D WHERE id = %d", $did)) !== $kid) { $bad++; $res['warn'][] = 'deal #' . $did . ': nem sikerült áthelyezni' . (is_wp_error($r) ? ' (' . $r->get_error_message() . ')' : ''); continue; }
		cp_mrg_hist($did, 'company', $ln, $K['name'] . ' – cégek összevonása');
		$res['moved']++;
	}
	foreach (array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $A WHERE related_id = %d AND (related_module = '5' OR LOWER(related_module) LIKE 'compan%%')", $lid))) as $aid) {
		$r = cp_sm_request('PUT', '/apis/activity/v4/' . $aid, array('relatedTo' => $kid));
		if (is_wp_error($r)) { $res['warn'][] = 'tevékenység #' . $aid . ' a régi cégnél maradt'; } else { $wpdb->update($A, array('related_id' => $kid), array('id' => $aid)); }
	}
	if ($bad) { $res['err'] = $ln . ': ' . $bad . ' kapcsolódó kontaktot / deal-t nem sikerült áthelyezni, ezért ezt a céget nem töröltem.'; return $res; }
	$r = cp_sm_request('DELETE', '/apis/company/v4/' . $lid);
	if (is_wp_error($r) || !cp_mrg_gone(cp_sm_request('GET', '/apis/company/v4/' . $lid))) { $res['err'] = $ln . ': a kapcsolatai átkerültek, de a régi céget a Salesmate nem törölte' . (is_wp_error($r) ? ' (' . $r->get_error_message() . ')' : '') . ' – töröld kézzel.'; return $res; }
	$wpdb->update($CO, array('deleted_at' => gmdate('Y-m-d H:i:s')), array('id' => $lid));
	cp_mrg_log('co', $kid, $lid);
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'merge', 'Cégek összevonása', 'company:' . $kid, $ln . ' (#' . $lid . ') → ' . $K['name'] . ' (#' . $kid . '), ' . $res['moved'] . ' deal áthelyezve'); }
	return $res;
}
/* melyik rekord melyikbe olvadt (visszakereséshez) */
function cp_mrg_log($kind, $kid, $lid) {
	$l = get_option('cp_merge_log', array()); if (!is_array($l)) { $l = array(); }
	$l[] = array('k' => $kind, 'keep' => (int) $kid, 'gone' => (int) $lid, 'at' => time(), 'by' => function_exists('cp_panel_who') ? cp_panel_who() : '');
	update_option('cp_merge_log', array_slice($l, -2000), false);
}
