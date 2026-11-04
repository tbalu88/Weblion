<?php
/**
 * v1.96: cégjavaslat a deal „Cég” kártyáján – ha nincs cég rendelve és a kontakt nem ingyenes levelezőről ír.
 * Források (erősorrendben):
 *  1. meglévő cég ugyanazzal a domainnel (weboldal vagy ugyanarról a domainről írt kolléga-kontakt cége),
 *  2. az ügyfél leveleinek aláírása, illetve a deal / kontakt leírása (Kft., Zrt., Bt., Egyesület … végű sor),
 *  3. a domain weboldalának neve (og:site_name vagy <title>).
 * Semmit nem ír magától: a javaslatot a kolléga hagyja jóvá (cp_crm_co_apply), és csak akkor kerül a Salesmate-be.
 */
if (!defined('ABSPATH')) { exit; }

function cp_cos_free($dom) {
	return (bool) preg_match('/^(gmail\.com|googlemail\.com|freemail\.hu|citromail\.hu|indamail\.hu|vipmail\.hu|mailbox\.hu|t-online\.hu|upcmail\.hu|chello\.hu|invitel\.hu|digikabel\.hu|monornet\.hu|externet\.hu|(yahoo|ymail|hotmail|outlook|live|msn|gmx|aol|icloud|me|mac|proton|protonmail|tutanota|zoho|mail|yandex)\.[a-z.]+)$/', $dom);
}
function cp_cos_norm($s) {
	$s = mb_strtolower(trim((string) $s));
	$s = preg_replace('/\b(kft|zrt|nyrt|bt|kkt|ltd|gmbh|inc|llc|nonprofit|korlátolt felelősségű társaság|zártkörűen működő részvénytársaság)\b\.?/u', '', $s);
	return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));
}
/* cégformát tartalmazó név kiemelése egy szövegből */
function cp_cos_names_in($txt) {
	$out = array();
	$forms = 'Nonprofit\s+Kft|Nonprofit\s+Zrt|Kft|Zrt|Nyrt|Bt|Kkt|Ltd|GmbH|Inc|LLC|Kft\.|Egyesület|Alapítvány|Egyetem|Önkormányzat|Polgármesteri\s+Hivatal|Hivatal|Kamara|Szövetség|Gimnázium|Iskola|Kórház|Múzeum|Színház';
	foreach (preg_split('/\R/u', (string) $txt) as $line) {
		$line = trim(preg_replace('/\s+/u', ' ', $line));
		if ($line === '' || mb_strlen($line) > 160) { continue; }
		if (preg_match_all('/((?:[\p{Lu}0-9][\p{L}0-9&\'’.\-]*\s+){0,6}?(?:[\p{Lu}0-9][\p{L}0-9&\'’.\-]*)\s*,?\s+(?:' . $forms . '))(?![\p{L}])\.?/u', $line, $m)) {
			foreach ($m[1] as $n) {
				$n = trim(preg_replace('/^(?:[Cc]ég(?:név)?|[Cc]ompany|[Mm]unkahely|[Ss]zervezet)\s*:\s*/u', '', $n), " \t,;-–|");
				$n = preg_replace('/^(?:A|Az|The)\s+/u', '', $n);
				if (preg_match('/(?:Kft|Zrt|Nyrt|Bt|Kkt)$/u', $n)) { $n .= '.'; }
				if (preg_match('/crazy|studio\s*frames|framesev/iu', $n)) { continue; }
				if (mb_strlen($n) >= 4 && mb_strlen($n) <= 120) { $out[] = $n; }
			}
		}
		/* „Cégnév: Valami” (űrlapokból) – cégforma nélkül is */
		if (preg_match('/^(?:Cégnév|Cég neve|Cég|Company|Szervezet)\s*:\s*(.{2,100})$/u', $line, $m2)) { $out[] = trim($m2[1], " \t,;-–|"); }
	}
	return $out;
}
/* a domain weboldalának neve (7 napig gyorsítótárazva) */
function cp_cos_site_name($dom) {
	$k = 'cp_cos_site_' . md5($dom);
	$c = get_transient($k);
	if ($c !== false) { return (string) $c; }
	$name = '';
	foreach (array('https://' . $dom . '/', 'https://www.' . $dom . '/') as $u) {
		$r = wp_safe_remote_get($u, array('timeout' => 6, 'redirection' => 3, 'limit_response_size' => 300000, 'user-agent' => 'Mozilla/5.0 (Crazy panel)'));
		if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) >= 400) { continue; }
		$h = (string) wp_remote_retrieve_body($r);
		if (preg_match('/<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']{2,120})["\']/i', $h, $m) || preg_match('/<meta[^>]+content=["\']([^"\']{2,120})["\'][^>]+property=["\']og:site_name["\']/i', $h, $m)) { $name = $m[1]; }
		elseif (preg_match('#<title[^>]*>(.{2,200}?)</title>#is', $h, $m)) {
			$parts = array_values(array_filter(array_map('trim', preg_split('/\s+[|–—\-:•·]\s+/u', html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')))));
			$gen = '/^(főoldal|kezdőlap|nyitóoldal|home|homepage|welcome|üdvözöljük|index)$/iu';
			$parts = array_values(array_filter($parts, function ($p) use ($gen) { return !preg_match($gen, $p); }));
			/* a domain nevét tartalmazó rész, különben a legrövidebb */
			$stem = mb_strtolower(preg_replace('/\.[a-z.]+$/', '', $dom));
			$pick = '';
			foreach ($parts as $p) { if (mb_strpos(mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $p)), $stem) !== false) { $pick = $p; break; } }
			if ($pick === '' && $parts) { usort($parts, function ($a, $b) { return mb_strlen($a) - mb_strlen($b); }); $pick = $parts[0]; }
			$name = $pick;
		}
		$name = trim(html_entity_decode(wp_strip_all_tags($name), ENT_QUOTES, 'UTF-8'));
		if (mb_strlen($name) > 80) { $name = ''; }
		break;
	}
	set_transient($k, $name, 7 * DAY_IN_SECONDS);
	return $name;
}

function cp_cos_suggest($deal_id) {
	global $wpdb;
	$d = $wpdb->get_row($wpdb->prepare('SELECT id, contact_id, company_id, description, venue FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $deal_id), ARRAY_A);
	if (!$d) { return new WP_Error('cp', 'Nincs ilyen deal.'); }
	$c = $d['contact_id'] ? $wpdb->get_row($wpdb->prepare('SELECT id, name, email, company_id, description FROM ' . cp_crm_t('contacts') . ' WHERE id = %d', $d['contact_id']), ARRAY_A) : null;
	if ($d['company_id'] || ($c && $c['company_id'])) { return array('why' => 'has'); }
	$email = $c ? strtolower(trim((string) $c['email'])) : '';
	$dom = substr(strrchr($email, '@') ?: '', 1);
	if ($dom === '' || !preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/', $dom)) { return array('why' => 'noemail'); }
	if (cp_cos_free($dom)) { return array('why' => 'free', 'dom' => $dom); }
	$S = array(); $seen = array();
	$add = function ($x) use (&$S, &$seen) {
		$nk = 'n' . cp_cos_norm($x['n']); $k = isset($x['id']) ? 'id' . $x['id'] : $nk;
		if ($nk === 'n' || isset($seen[$k]) || isset($seen[$nk])) { return; }
		$seen[$k] = 1; $seen[$nk] = 1; $S[] = $x;
	};
	$CO = cp_crm_t('companies'); $CT = cp_crm_t('contacts');
	/* 1. meglévő cég ugyanazzal a domainnel */
	$like = '%' . $wpdb->esc_like($dom) . '%';
	foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, name, website FROM $CO WHERE deleted_at IS NULL AND website LIKE %s LIMIT 3", $like), ARRAY_A) as $r) {
		$h = strtolower((string) wp_parse_url((preg_match('#^https?://#i', $r['website']) ? '' : 'https://') . $r['website'], PHP_URL_HOST));
		if ($h === $dom || $h === 'www.' . $dom) { $add(array('id' => (int) $r['id'], 'n' => $r['name'], 'w' => $r['website'], 'src' => 'Meglévő cég – ugyanez a weboldal (' . $dom . ')')); }
	}
	foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT co.id, co.name, co.website, COUNT(*) n FROM $CT c JOIN $CO co ON co.id = c.company_id WHERE c.deleted_at IS NULL AND co.deleted_at IS NULL AND c.email LIKE %s GROUP BY co.id ORDER BY n DESC LIMIT 3", '%@' . $wpdb->esc_like($dom)), ARRAY_A) as $r) {
		$add(array('id' => (int) $r['id'], 'n' => $r['name'], 'w' => $r['website'], 'src' => 'Meglévő cég – ' . (int) $r['n'] . ' másik kontakt is innen ír (@' . $dom . ')'));
	}
	/* 2. aláírás és leírások */
	$texts = array();
	if (function_exists('cp_mail_t')) {
		$rows = (array) $wpdb->get_col($wpdb->prepare('SELECT body FROM ' . cp_mail_t('messages') . " WHERE dir = 'in' AND from_email = %s ORDER BY id DESC LIMIT 5", $email));
		foreach ($rows as $b) {
			$t = function_exists('cp_ai_text') ? cp_ai_text($b, 20000) : wp_strip_all_tags($b);
			$t = preg_replace('/\[korábbi idézett levél\].*$/su', '', $t);
			$lines = preg_split('/\R/u', trim($t));
			$texts[] = array('aláírásából', implode("\n", array_slice($lines, -18)));
		}
	}
	$texts[] = array('deal leírásából', (string) $d['description']);
	if ($c) { $texts[] = array('kontakt leírásából', (string) $c['description']); }
	$names = array();
	$vn = cp_cos_norm(preg_replace('/\s*[,(].*$/u', '', (string) $d['venue'])); /* a rendezvény helyszíne nem az ügyfél cége */
	if (mb_strlen($vn) < 4) { $vn = ''; }
	foreach ($texts as $t) { foreach (cp_cos_names_in($t[1]) as $n) { $k = cp_cos_norm($n); if ($k === '' || ($vn !== '' && (mb_strpos($vn, $k) !== false || mb_strpos($k, $vn) !== false))) { continue; } if (!isset($names[$k])) { $names[$k] = array('n' => $n, 'src' => $t[0], 'x' => 0); } $names[$k]['x']++; } }
	uasort($names, function ($a, $b) { return $b['x'] - $a['x']; });
	foreach (array_slice($names, 0, 3, true) as $k => $x) {
		$ex = $wpdb->get_row($wpdb->prepare("SELECT id, name, website FROM $CO WHERE deleted_at IS NULL AND name LIKE %s LIMIT 5", '%' . $wpdb->esc_like(explode(' ', $k)[0]) . '%'), ARRAY_A);
		if ($ex && cp_cos_norm($ex['name']) === $k) { $add(array('id' => (int) $ex['id'], 'n' => $ex['name'], 'w' => $ex['website'], 'src' => 'Meglévő cég – az ügyfél ' . $x['src'])); }
		else { $add(array('n' => $x['n'], 'w' => 'https://' . $dom, 'src' => 'Az ügyfél ' . $x['src'] . ($x['x'] > 1 ? ' (' . $x['x'] . '×)' : ''))); }
	}
	/* 3. weboldal neve */
	$sn = cp_cos_site_name($dom);
	if ($sn !== '') { $add(array('n' => $sn, 'w' => 'https://' . $dom, 'src' => 'A ' . $dom . ' weboldal neve')); }
	return array('why' => 'ok', 'dom' => $dom, 'rows' => array_slice($S, 0, 4));
}

add_action('wp_ajax_nopriv_cp_crm_co_suggest', 'cp_cos_ajax_suggest');
add_action('wp_ajax_cp_crm_co_suggest', 'cp_cos_ajax_suggest');
function cp_cos_ajax_suggest() {
	cp_crm_ajax_guard();
	$r = cp_cos_suggest((int) ($_POST['id'] ?? 0));
	if (is_wp_error($r)) { cp_el_err($r->get_error_message()); }
	wp_send_json_success($r);
}

/* jóváhagyás: meglévő cég (co) vagy új cég (name + website) → kontakt és deal cége a Salesmate-ben is */
add_action('wp_ajax_nopriv_cp_crm_co_apply', 'cp_cos_ajax_apply');
add_action('wp_ajax_cp_crm_co_apply', 'cp_cos_ajax_apply');
function cp_cos_ajax_apply() {
	cp_crm_ajax_guard();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (empty(cp_panel_opts()['stage_write'])) { cp_el_err('A Salesmate-be írás a panel beállításaiban ki van kapcsolva.'); }
	global $wpdb;
	$id = (int) ($_POST['id'] ?? 0);
	$d = $wpdb->get_row($wpdb->prepare('SELECT id, contact_id, company_id FROM ' . cp_crm_t('deals') . ' WHERE id = %d AND deleted_at IS NULL', $id), ARRAY_A);
	if (!$d) { cp_el_err('Nincs ilyen deal.', 404); }
	/* v1.99.2: közben valaki már rendelt hozzá céget? (két kolléga egyszerre) – nem írjuk felül */
	$cco = $d['contact_id'] ? (int) $wpdb->get_var($wpdb->prepare('SELECT company_id FROM ' . cp_crm_t('contacts') . ' WHERE id = %d', (int) $d['contact_id'])) : 0;
	if ((int) $d['company_id'] || $cco) { cp_el_err('Ehhez a dealhez / kontakthoz közben már hozzárendeltek egy céget – frissítsd az adatlapot.'); }
	$CO = cp_crm_t('companies');
	$cid = (int) ($_POST['co'] ?? 0);
	$warn = array();
	if ($cid) {
		if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $CO WHERE id = %d", $cid))) { cp_el_err('Nincs ilyen cég.'); }
	} else {
		$name = mb_substr(trim(sanitize_text_field((string) wp_unslash($_POST['name'] ?? ''))), 0, 190);
		if (mb_strlen($name) < 2) { cp_el_err('Add meg a cég nevét.'); }
		$w = trim((string) wp_unslash($_POST['website'] ?? ''));
		$w = $w === '' ? '' : mb_substr(esc_url_raw(preg_match('#^https?://#i', $w) ? $w : 'https://' . $w), 0, 255);
		/* ha közben már van ilyen nevű cég, azt használjuk – ne legyen kettő */
		/* azonos cég a cégforma / írásjelek / kis-nagybetű különbségeitől eltekintve („Foo Kft” = „Foo Kft.”) */
		$cid = 0; $nk = cp_cos_norm($name);
		$w1 = explode(' ', $nk)[0] ?? '';
		if ($w1 !== '') {
			foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, name FROM $CO WHERE deleted_at IS NULL AND name LIKE %s LIMIT 60", '%' . $wpdb->esc_like($w1) . '%'), ARRAY_A) as $c) { if (cp_cos_norm($c['name']) === $nk) { $cid = (int) $c['id']; break; } }
		}
		/* két kolléga egyszerre ugyanazt hozná létre: rövid zár */
		if (!$cid && !add_option('cp_cos_lk_' . md5($nk), time(), '', 'no')) {
			if ((int) get_option('cp_cos_lk_' . md5($nk)) > time() - 60) { cp_el_err('Ezt a céget épp most hozza létre valaki – frissíts pár másodperc múlva.'); }
			update_option('cp_cos_lk_' . md5($nk), time(), false);
		}
		if (!$cid) {
			$r = cp_sm_request('POST', '/apis/company/v4', array_filter(array('name' => $name, 'website' => $w), function ($v) { return $v !== ''; }));
			if (is_wp_error($r)) { delete_option('cp_cos_lk_' . md5($nk)); cp_el_err('A céget nem sikerült létrehozni a Salesmate-ben: ' . $r->get_error_message(), 502); }
			$cid = cp_crm_new_id($r);
			if (!$cid) { delete_option('cp_cos_lk_' . md5($nk)); cp_el_err('A cég létrejött, de a Salesmate nem adta vissza az azonosítóját – 10 percen belül megjelenik, akkor rendeld hozzá kézzel.', 502); }
		}
		$g = cp_sm_request('GET', '/apis/company/v4/' . $cid);
		if (!is_wp_error($g)) { cp_crm_store('company', array(isset($g['Data']) ? $g['Data'] : $g), 'get'); }
		delete_option('cp_cos_lk_' . md5($nk));
	}
	/* kontakt */
	if ($d['contact_id']) {
		$p = cp_sm_request('PUT', '/apis/contact/v4/' . (int) $d['contact_id'], array('company' => $cid));
		if (is_wp_error($p)) { $warn[] = 'a kontakthoz nem sikerült hozzárendelni (' . $p->get_error_message() . ')'; }
		else { $g = cp_sm_request('GET', '/apis/contact/v4/' . (int) $d['contact_id']); if (!is_wp_error($g)) { cp_crm_store('contact', array(isset($g['Data']) ? $g['Data'] : $g), 'get'); } }
	}
	/* deal */
	$p = cp_sm_request('PUT', '/apis/deal/v4/' . $id, array('primaryCompany' => $cid));
	$dealok = !is_wp_error($p);
	if (!$dealok) { $warn[] = 'a deal-hez nem sikerült hozzárendelni (' . $p->get_error_message() . ')'; }
	elseif (function_exists('cp_crm_after_write')) { cp_crm_after_write($id); }
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	if ($dealok && !$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_crm_t('history') . " WHERE deal_id = %d AND field = 'company_id' AND at >= %s", $id, gmdate('Y-m-d H:i:s', time() - 120)))) $wpdb->insert(cp_crm_t('history'), array('deal_id' => $id, 'field' => 'company', 'old_val' => '', 'new_val' => (string) $wpdb->get_var($wpdb->prepare("SELECT name FROM $CO WHERE id = %d", $cid)), 'by_user' => function_exists('cp_panel_who') ? cp_panel_who() : 'Crazy panel', 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel'));
	wp_send_json_success(array('co' => $cid, 'warn' => implode('; ', $warn)));
}
