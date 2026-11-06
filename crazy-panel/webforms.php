<?php
/* =====================================================================
 * Saját ajánlatkérő űrlapok (v2.7) – a Salesmate-űrlapok helyett, ugyanazokkal a mezőkkel.
 *
 * Hogyan cserél: az oldalakon a Salesmate beágyazó kódja a studioframes.salesmate.io/webforms.js szkriptet tölti be,
 * majd SmFormSettings.loadForm({formId, divId}) hívással kirakja az űrlapot. A weboldal kimenetében (itt és a
 * crazyphoto.hu-n a crazy-lead-watcher.php) ez a cím a panel saját szkriptjére cserélődik (assets/webforms.js),
 * amely ugyanezt a felületet adja (SmFormSettings, SalesmateFormsV1 – az árkalkulátor ezzel tölti ki), és:
 *   – ha az űrlap funkciója (Beállítások → Átállás: „EventCrazy / Crazyphoto űrlap → deal”) „Panel + tükör” vagy
 *     „Csak panel”, a saját űrlapot rajzolja ki, a beküldést ide küldi, a panel hozza létre a kontaktot és a deal-t;
 *   – különben (és ha a panel nem érhető el) változatlanul az eredeti Salesmate-űrlapot tölti be.
 * Így a kapcsoló a panelben van, a weboldalhoz nem kell nyúlni, és bármikor visszaállítható.
 *
 * A mezők, a választható értékek, a kötelezőség, a köszönőoldal és az értesítési cím a Salesmate-űrlapok
 * 2026-11-05-i állapota (webforms-defs.json). A deal neve, felelőse, mezői az árnyék-üzemben ellenőrzött terv szerint.
 * Ha a deal létrehozása nem sikerül (pl. a Salesmate nem válaszol), a megkeresés megmarad, és a panel 10 percenként
 * újrapróbálja; 3 sikertelen próba után szól az adminoknak. A látogató ebből semmit nem érzékel.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_WF_VER', '1');

function cp_wf_defs() {
	static $d = null;
	if ($d === null) { $d = json_decode((string) @file_get_contents(__DIR__ . '/webforms-defs.json'), true); if (!is_array($d)) { $d = array(); } }
	return $d;
}
function cp_wf_def($fid) { $d = cp_wf_defs(); return isset($d[$fid]) ? $d[$fid] : null; }
function cp_wf_live($def) { return $def && function_exists('cp_cut_mode') && in_array(cp_cut_mode((string) $def['feat']), array('live', 'only'), true); }

/* a beküldő oldalak (a crazyphoto.hu is ide küld) */
function cp_wf_cors() {
	$o = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
	if (preg_match('#^https://(www\.)?(eventcrazy|crazyphoto)\.hu$#', $o)) {
		header('Access-Control-Allow-Origin: ' . $o);
		header('Vary: Origin');
	}
	header('Cache-Control: no-store');
}

/* űrlapjegy: a kirajzoláskor kapja, beküldéskor ellenőrizzük (aláírt időbélyeg – a közvetlenül posztoló robotok ellen) */
function cp_wf_tk($fid, $ts = null) {
	$ts = $ts === null ? time() : (int) $ts;
	return $ts . '.' . substr(hash_hmac('sha256', $fid . '|' . $ts, wp_salt('auth') . 'cp_wf'), 0, 24);
}
function cp_wf_tk_ok($fid, $tk) {
	if (!preg_match('/^(\d{9,11})\.([0-9a-f]{24})$/', (string) $tk, $m)) { return 'bad'; }
	if (!hash_equals(cp_wf_tk($fid, (int) $m[1]), (string) $tk)) { return 'bad'; }
	$age = time() - (int) $m[1];
	if ($age < 3) { return 'fast'; }
	if ($age > 2 * DAY_IN_SECONDS) { return 'old'; }
	return '';
}

/* ------------------------------------------------------------ az űrlap leírása a weboldal szkriptjének */
add_action('wp_ajax_nopriv_cp_wf_def', 'cp_wf_ajax_def');
add_action('wp_ajax_cp_wf_def', 'cp_wf_ajax_def');
function cp_wf_ajax_def() {
	cp_wf_cors();
	$fid = sanitize_text_field(wp_unslash($_GET['id'] ?? ''));
	$def = cp_wf_def($fid);
	/* próba: ?test=<jegy> – élesítés előtt is a saját űrlapot mutatja (a beküldés ekkor nem hoz létre deal-t) */
	$test = cp_wf_test_ok((string) wp_unslash($_GET['test'] ?? ''));
	if (!$def || (!cp_wf_live($def) && !$test)) { wp_send_json(array('live' => 0)); }
	$f = $def; unset($f['notify'], $f['feat']);
	wp_send_json(array('live' => 1, 'test' => $test ? 1 : 0, 'tk' => cp_wf_tk($fid), 'form' => $f));
}

function cp_wf_test_ok($t) {
	$o = get_option('cp_wf_test', array());
	return $t !== '' && is_array($o) && !empty($o['tok']) && (int) ($o['exp'] ?? 0) > time() && hash_equals((string) $o['tok'], hash('sha256', $t));
}

/* ------------------------------------------------------------ beküldés */
add_action('wp_ajax_nopriv_cp_wf_send', 'cp_wf_ajax_send');
add_action('wp_ajax_cp_wf_send', 'cp_wf_ajax_send');
function cp_wf_ajax_send() {
	cp_wf_cors();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !function_exists('cp_lead_db_ok') || !cp_lead_db_ok()) { wp_send_json(array('ok' => 0, 'err' => 'server'), 400); }
	$fid = sanitize_text_field(wp_unslash($_POST['fid'] ?? ''));
	$def = cp_wf_def($fid);
	if (!$def) { wp_send_json(array('ok' => 0, 'err' => 'form'), 400); }
	$en = ($def['lang'] ?? 'hu') === 'en';
	$test = cp_wf_test_ok((string) wp_unslash($_POST['test'] ?? ''));
	/* robotszűrés: rejtett mező, jegy, gyorsaság */
	if (trim((string) wp_unslash($_POST['website'] ?? '')) !== '') { wp_send_json(array('ok' => 1, 'redirect' => (string) $def['redirect'])); }
	$tk = cp_wf_tk_ok($fid, (string) wp_unslash($_POST['tk'] ?? ''));
	if ($tk === 'bad' || $tk === 'fast') { wp_send_json(array('ok' => 0, 'err' => 'tk', 'msg' => $en ? 'Please wait a moment and submit again.' : 'Kérjük, várj egy pillanatot, és küldd el újra.'), 400); }
	if ($tk === 'old') { wp_send_json(array('ok' => 0, 'err' => 'old', 'msg' => $en ? 'The form has expired – please reload the page.' : 'Az űrlap lejárt – kérjük, töltsd újra az oldalt.'), 400); }
	/* visszaélés ellen: IP-nként óránként legfeljebb 10 beküldés */
	$ip = md5((string) ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt('nonce'));
	$rk = 'cp_wf_rl_' . $ip; $n = (int) get_transient($rk);
	if ($n >= 10 && !$test) { wp_send_json(array('ok' => 0, 'err' => 'rate', 'msg' => $en ? 'Too many submissions – please call us.' : 'Túl sok beküldés – kérjük, hívj minket telefonon.'), 429); }
	set_transient($rk, $n + 1, HOUR_IN_SECONDS);

	$raw = json_decode((string) wp_unslash($_POST['v'] ?? ''), true);
	if (!is_array($raw)) { wp_send_json(array('ok' => 0, 'err' => 'data'), 400); }
	$pairs = array(); $errs = array();
	foreach ($def['fields'] as $f) {
		$n = $f['n'];
		$v = $raw[$n] ?? ($f['def'] ?? '');
		if (!empty($f['hid']) && isset($f['def']) && !preg_match('/^Contact\.utm/', $n)) { $v = $f['def']; } /* rejtett mező: a beállított érték, nem a böngészőé */
		if (is_array($v)) { $v = implode(', ', array_map('strval', $v)); }
		$v = trim(sanitize_textarea_field((string) $v));
		$v = mb_substr($v, 0, $f['t'] === 'textarea' ? 2000 : 500);
		if ($v !== '') {
			if ($f['t'] === 'email' && !is_email($v)) { $errs[$n] = $en ? 'Invalid email address.' : 'Érvénytelen e-mail-cím.'; }
			if ($f['t'] === 'int') { $v = preg_replace('/[^0-9]/', '', $v); }
			if ($f['t'] === 'date') { $v = function_exists('cp_cut_date') ? cp_cut_date($v) : $v; if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $errs[$n] = $en ? 'Invalid date.' : 'Érvénytelen dátum.'; } }
			if ($f['t'] === 'select' && !empty($f['o']) && !in_array($v, $f['o'], true)) { $v = ''; }
			if ($f['t'] === 'multi' && !empty($f['o'])) { $v = implode(', ', array_values(array_intersect($f['o'], array_map('trim', explode(', ', $v))))); }
		}
		if ($v === '' && !empty($f['req']) && empty($f['hid'])) { $errs[$n] = $en ? 'Required.' : 'Kötelező mező.'; }
		if ($v !== '') { $pairs[$n] = $v; }
	}
	if ($errs) { wp_send_json(array('ok' => 0, 'err' => 'fields', 'fields' => $errs, 'msg' => $en ? 'Please check the highlighted fields.' : 'Kérjük, nézd át a jelölt mezőket.'), 400); }
	$pairs['Deal.title'] = str_replace('{{Contact.name}}', $pairs['Contact.name'] ?? '', (string) ($pairs['Deal.title'] ?? ''));
	$page = mb_substr(esc_url_raw((string) wp_unslash($_POST['page'] ?? '')), 0, 255);
	$redirect = (string) $def['redirect'];
	if ($test) { $rr0 = get_option('cp_cut_rr'); $plan = cp_cut_plan_lead($pairs, $page, $def['feat']); update_option('cp_cut_rr', $rr0, false); wp_send_json(array('ok' => 1, 'test' => 1, 'redirect' => '', 'plan' => $plan, 'pairs' => $pairs)); }

	global $wpdb;
	$data = wp_json_encode($pairs, JSON_UNESCAPED_UNICODE);
	/* ugyanaz kétszer (dupla kattintás) 5 percen belül: csak egyszer */
	if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_lead_t() . ' WHERE ip_hash = %s AND MD5(data) = %s AND created_at >= %s', $ip, md5($data), gmdate('Y-m-d H:i:s', time() - 300)))) { wp_send_json(array('ok' => 1, 'redirect' => $redirect)); }
	$email = sanitize_email($pairs['Contact.email'] ?? '');
	$wpdb->insert(cp_lead_t(), array('form_id' => $fid, 'form_name' => mb_substr((string) $def['name'], 0, 190), 'data' => $data, 'name' => mb_substr((string) ($pairs['Contact.name'] ?? ''), 0, 190),
		'email' => mb_substr($email, 0, 190), 'phone' => mb_substr((string) ($pairs['Contact.mobile'] ?? ''), 0, 60), 'page' => $page, 'ip_hash' => $ip, 'status' => 'new', 'created_at' => gmdate('Y-m-d H:i:s')));
	$lid = (int) $wpdb->insert_id;
	if (!$lid) { wp_send_json(array('ok' => 0, 'err' => 'server', 'msg' => $en ? 'Something went wrong – please call us.' : 'Hiba történt – kérjük, hívj minket telefonon.'), 500); }
	cp_wf_queue_add($lid);
	/* a látogató azonnal továbbmegy; a deal a válasz után készül (a lassú Salesmate ne tartsa fel) */
	ignore_user_abort(true);
	$out = wp_json_encode(array('ok' => 1, 'redirect' => $redirect));
	if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
		header('Content-Type: application/json; charset=UTF-8');
		echo $out;
		if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } else { litespeed_finish_request(); }
		cp_wf_after($lid);
		exit;
	}
	cp_wf_after($lid);
	header('Content-Type: application/json; charset=UTF-8');
	echo $out;
	exit;
}

/* a beküldés utáni teendők: deal, értesítések, levél */
function cp_wf_after($lid) {
	global $wpdb;
	$r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_lead_t() . ' WHERE id = %d', $lid), ARRAY_A);
	if (!$r) { return; }
	$pairs = json_decode((string) $r['data'], true) ?: array();
	$def = cp_wf_def((string) $r['form_id']);
	$res = cp_wf_create($lid);
	$deal = is_wp_error($res) ? 0 : (int) $res;
	/* értesítés minden aktív kollégának (csengő, és ha be van állítva, Pumble) – ugyanaz a szöveg, mint eddig */
	if (function_exists('cp_notify') && function_exists('cp_pu_data')) {
		$name = (string) ($pairs['Contact.name'] ?? '');
		$what = (string) ($pairs['Deal.multiSelectCustomField3'] ?? ($pairs['Deal.textCustomField13'] ?? ''));
		$when = (string) ($pairs['Deal.dateCustomField1'] ?? '');
		$txt = 'Új ajánlatkérés: ' . ($name !== '' ? $name : ((string) $r['email'] ?: 'névtelen')) . ($when !== '' ? ' · ' . $when : '') . ($what !== '' ? ' · ' . mb_substr($what, 0, 80) : '');
		foreach (cp_pu_data()['list'] as $uid => $u) { if (!empty($u['a'])) { cp_notify((int) $uid, 'lead', $txt, $deal ? 'deals:' . $deal : 'deals:leads', 'Weboldal'); } }
	}
	if ($def && !empty($def['notify'])) { cp_wf_mail($def, $pairs, $deal, (string) $r['page']); }
	if (function_exists('cp_cut_log') && $def) {
		$lg = cp_cut_log($def['feat'], 'form:' . $lid, cp_wf_plan($lid, $pairs, (string) $r['page'], $def['feat']), $deal);
		if ($lg) { $wpdb->update(cp_cut_t(), array('status' => $deal ? 'live' : 'queued', 'checked_at' => gmdate('Y-m-d H:i:s')), array('id' => $lg)); }
	}
}

/* értesítő levél az űrlap címzettjeinek – a Salesmate „New Submission” levelének mintájára (v2.7.4), kiegészítve:
 * tárgy: „<űrlap> - New Submission [sorszám]”, törzs: minden mező „Címke: érték” (üres: „-”), felül a panel-link és
 * a visszatérő ügyfél jelzése. Feladó: a weboldal (nem az info@), válaszcím: az ügyfél – így a panel Levelezése a
 * postafiókból az ügyféllel közös szálként, a deal-hez kötve veszi fel, és a „Válasz” az ügyfélnek megy. */
function cp_wf_seq($fid) {
	$s = get_option('cp_wf_seq', array()); if (!is_array($s)) { $s = array(); }
	if (!isset($s[$fid])) {
		$seed = array('249aeb1c-71c0-4c4c-9f6a-6eeffd1d24e5' => 746); /* a Salesmate utolsó sorszáma (2026-11-05) */
		global $wpdb;
		$s[$fid] = $seed[$fid] ?? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_lead_t() . ' WHERE form_id = %s', $fid));
	}
	$s[$fid] = (int) $s[$fid] + 1;
	update_option('cp_wf_seq', $s, false);
	return $s[$fid];
}
function cp_wf_mail($def, $pairs, $deal, $page, $shadow = false) {
	/* nyers beküldési lista ([{name, value}]) esetén név => érték alakra */
	if (isset($pairs[0]) && is_array($pairs[0]) && function_exists('cp_lead_pairs')) { $pairs = cp_lead_pairs($pairs); }
	$fid = '';
	foreach (cp_wf_defs() as $k => $d) { if ($d['name'] === $def['name']) { $fid = $k; } }
	$n = cp_wf_seq($fid);
	$e = function ($v) { return esc_html((string) $v); };
	$rows = '';
	foreach ($def['fields'] as $f) {
		$v = isset($pairs[$f['n']]) && $pairs[$f['n']] !== '' ? (string) $pairs[$f['n']] : '-';
		$rows .= '<p style="margin:0 0 12px"><b>' . $e($f['l']) . ':</b><br>' . nl2br($e($v)) . '</p>';
	}
	$base = function_exists('cp_pu_panel_url') ? cp_pu_panel_url() : home_url('/');
	$link = $base . '#' . ($deal ? 'deals:' . $deal : 'deals:leads');
	$em = sanitize_email((string) ($pairs['Contact.email'] ?? ''));
	$ret = function_exists('cp_ret_by_email') ? cp_ret_by_email($em, (int) $deal) : null;
	$top = '<p style="margin:0 0 6px"><a href="' . esc_url($link) . '" style="display:inline-block;background:#E8894A;color:#1A1F2B;padding:8px 16px;border-radius:999px;text-decoration:none;font-weight:700">' . ($deal ? 'Deal megnyitása a Crazy panelben' : 'Megkeresések a Crazy panelben') . '</a></p>'
		. ($deal ? '' : ($shadow ? '<p style="margin:0 0 6px;color:#5B6275">A deal a Salesmate-ből érkezik a panelbe pár percen belül.</p>' : '<p style="margin:0 0 6px;color:#B4432F">A deal még készül (a panel újrapróbálja).</p>'))
		. ($ret ? '<p style="margin:0 0 6px">🔁 <b>Visszatérő ügyfél:</b> ' . (int) $ret['n'] . ' korábbi deal' . ($ret['won'] ? ', ebből ' . (int) $ret['won'] . ' megnyert' . ($ret['last_ev'] ? ' (utoljára ' . $e(substr($ret['last_ev'], 0, 10)) . ')' : '') : '') . '</p>' : '')
		. '<p style="margin:0 0 6px;color:#5B6275">Válaszolj erre a levélre: a válasz az ügyfélnek megy.</p>';
	$html = '<div style="font:14px/1.5 Arial,sans-serif;color:#222;max-width:640px">' . $top . '<hr style="border:0;border-top:1px solid #e5e5e5;margin:14px 0">' . $rows
		. ($page !== '' ? '<p style="color:#888;font-size:12px;margin-top:16px">Beküldve innen: ' . $e($page) . '</p>' : '') . '</div>';
	$name = str_replace(array("\r", "\n", '<', '>', '"'), '', (string) ($pairs['Contact.name'] ?? ''));
	$h = array('Content-Type: text/html; charset=UTF-8', 'From: Weboldal-űrlap <wordpress@eventcrazy.hu>', 'X-Crazy-Panel: webform');
	if ($em !== '') { $h[] = 'Reply-To: "' . $name . '" <' . $em . '>'; }
	/* v2.9.10: árnyék módban a Salesmate küldi a saját értesítőjét a címzetteknek – a panel csak a saját postafiókjába (Levelezés) */
	$to = $shadow ? array() : array_map('sanitize_email', (array) $def['notify']);
	/* a crazyphoto.hu űrlapjai a panel postafiókjába (info@eventcrazy.hu) is, hogy a Levelezésben is megjelenjenek */
	if (!in_array('info@eventcrazy.hu', $to, true)) { $to[] = 'info@eventcrazy.hu'; }
	foreach (array_unique(array_filter($to)) as $t) { @wp_mail($t, $def['name'] . ' - New Submission [' . $n . ']', $html, $h); }
}

/* ------------------------------------------------------------ kontakt + deal (a panel „Új deal” lépései szerint) */
function cp_wf_create($lid) {
	global $wpdb;
	if (!function_exists('cp_crm_t') || !function_exists('cp_sm_request')) { return new WP_Error('cp_wf', 'A CRM nem elérhető.'); }
	/* egyszerre csak egy folyamat dolgozzon ugyanazon a megkeresésen */
	$lk = 'cp_wf_lk_' . $lid;
	if (get_transient($lk)) { return new WP_Error('cp_wf', 'Folyamatban.'); }
	set_transient($lk, 1, 120);
	$r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_lead_t() . ' WHERE id = %d', $lid), ARRAY_A);
	if (!$r) { delete_transient($lk); return new WP_Error('cp_wf', 'Nincs ilyen megkeresés.'); }
	if (!empty($r['deal_id'])) { cp_wf_queue_del($lid); delete_transient($lk); return (int) $r['deal_id']; }
	$def = cp_wf_def((string) $r['form_id']);
	$pairs = json_decode((string) $r['data'], true) ?: array();
	if (!$def) { cp_wf_queue_del($lid); delete_transient($lk); return new WP_Error('cp_wf', 'Ismeretlen űrlap.'); }
	$plan = cp_wf_plan($lid, $pairs, (string) $r['page'], $def['feat']);
	$tags = implode(',', (array) ($def['tags'] ?? array()));
	$name = (string) ($pairs['Contact.name'] ?? ''); $email = sanitize_email((string) ($pairs['Contact.email'] ?? '')); $phone = mb_substr(preg_replace('/[^0-9+ ()\/-]/', '', (string) ($pairs['Contact.mobile'] ?? '')), 0, 40);
	$fail = function ($msg) use ($lk) { delete_transient($lk); return new WP_Error('cp_wf', $msg); };

	/* kontakt: azonos e-mail-cím és név (vagy névtelen régi kontakt) → a meglévő; különben új */
	$q0 = get_option('cp_wf_q', array());
	$cid = is_array($q0) && !empty($q0[$lid]['c']) ? (int) $q0[$lid]['c'] : 0;
	if ($cid && !$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_crm_t('contacts') . ' WHERE id = %d AND deleted_at IS NULL', $cid))) { $cid = 0; }
	if (!$cid && $email !== '') {
		$nn = function ($x) { return trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $x))); };
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, name FROM ' . cp_crm_t('contacts') . ' WHERE email = %s AND deleted_at IS NULL ORDER BY id DESC LIMIT 10', $email), ARRAY_A) as $c) {
			if ($nn($c['name']) === $nn($name) || $nn($c['name']) === '') { $cid = (int) $c['id']; break; }
		}
	}
	if (!$cid) {
		$cb = array('firstName' => '', 'lastName' => $name, 'email' => $email, 'mobile' => $phone, 'tags' => $tags, 'owner' => 19);
		foreach (array('utmSource', 'utmMedium', 'utmCampaign', 'utmTerm', 'utmContent') as $u) { if (!empty($pairs['Contact.' . $u])) { $cb[$u] = mb_substr((string) $pairs['Contact.' . $u], 0, 200); } }
		if (!function_exists('cp_crm_users') || !isset(cp_crm_users()[19])) { unset($cb['owner']); }
		$cr = cp_sm_request('POST', '/apis/contact/v4', array_filter($cb, function ($v) { return $v !== ''; }));
		if (is_wp_error($cr)) { return $fail('A kontaktot nem sikerült létrehozni: ' . $cr->get_error_message()); }
		$cid = cp_crm_new_id($cr);
		if (!$cid) { return $fail('A kontakt létrejött, de a Salesmate nem adta vissza az azonosítóját.'); }
		$cg0 = cp_sm_request('GET', '/apis/contact/v4/' . $cid);
		if (!is_wp_error($cg0)) { cp_crm_store('contact', array(isset($cg0['Data']) ? $cg0['Data'] : $cg0), 'get'); }
	}
	/* a kontakt megjegyezve: ha a deal most elakad, az újrapróbálás ne hozzon létre második kontaktot */
	cp_wf_lead_cid($lid, $cid);

	$body = array('title' => mb_substr((string) $plan['title'], 0, 250), 'pipeline' => $plan['pipeline'], 'stage' => $plan['stage'], 'primaryContact' => $cid, 'currency' => 'HUF', 'tags' => $tags);
	if (!empty($plan['owner_id']) && function_exists('cp_crm_users') && isset(cp_crm_users()[(int) $plan['owner_id']])) { $body['owner'] = (int) $plan['owner_id']; }
	$types = array();
	foreach ($def['fields'] as $f) { $types[$f['n']] = $f['t']; }
	foreach ($pairs as $n => $v) {
		if (strpos($n, 'Deal.') !== 0 || $n === 'Deal.title' || $v === '') { continue; }
		$k = substr($n, 5); $t = $types[$n] ?? 'text';
		if ($t === 'int') { $v = (int) $v; }
		elseif ($t === 'multi') { $v = implode(',', array_map('trim', explode(', ', $v))); }
		$body[$k] = $v;
	}
	$dr = cp_sm_request('POST', '/apis/deal/v4', $body);
	if (is_wp_error($dr)) { return $fail('A deal-t nem sikerült létrehozni: ' . $dr->get_error_message()); }
	$id = cp_crm_new_id($dr);
	if (!$id) { return $fail('A deal létrejött, de a Salesmate nem adta vissza az azonosítóját.'); }
	$wpdb->update(cp_lead_t(), array('deal_id' => $id), array('id' => $lid));
	cp_wf_queue_del($lid);
	$cg = cp_sm_request('GET', '/apis/contact/v4/' . $cid);
	if (!is_wp_error($cg)) { cp_crm_store('contact', array(isset($cg['Data']) ? $cg['Data'] : $cg), 'get'); }
	if (function_exists('cp_crm_after_write')) { cp_crm_after_write($id); }
	$wpdb->insert(cp_crm_t('history'), array('deal_id' => $id, 'field' => 'created', 'old_val' => '', 'new_val' => 'létrehozva a weboldal űrlapjából (' . $def['name'] . ')', 'by_user' => 'Weboldal-űrlap', 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel'));
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	delete_transient($lk);
	return $id;
}

/* a deal-terv egyszer készül (a felelős körbeforgása csak egyet lépjen), az újrapróbálás ugyanazt használja */
function cp_wf_plan($lid, $pairs, $page, $feat) {
	static $mem = array();
	if (isset($mem[$lid])) { return $mem[$lid]; }
	$q = get_option('cp_wf_q', array()); if (!is_array($q)) { $q = array(); }
	if (!empty($q[$lid]['p']) && is_array($q[$lid]['p'])) { return $mem[$lid] = $q[$lid]['p']; }
	$p = cp_cut_plan_lead($pairs, $page, $feat);
	if (isset($q[$lid])) { $q = get_option('cp_wf_q', array()); $q[$lid]['p'] = $p; update_option('cp_wf_q', $q, false); }
	return $mem[$lid] = $p;
}

/* a megkeresés kontaktja (újrapróbáláshoz) – a sorban tartva */
function cp_wf_lead_cid($lid, $cid) {
	$q = get_option('cp_wf_q', array()); if (!is_array($q)) { $q = array(); }
	if (isset($q[$lid])) { $q[$lid]['c'] = (int) $cid; update_option('cp_wf_q', $q, false); }
}

/* ------------------------------------------------------------ újrapróbálás (10 percenként, a CRM-ütemmel) */
function cp_wf_queue_add($lid) { $q = get_option('cp_wf_q', array()); if (!is_array($q)) { $q = array(); } $q[$lid] = array('n' => 0, 'c' => 0, 't' => time()); update_option('cp_wf_q', $q, false); }
function cp_wf_queue_del($lid) { $q = get_option('cp_wf_q', array()); if (is_array($q) && isset($q[$lid])) { unset($q[$lid]); update_option('cp_wf_q', $q, false); } }
add_action('cp_crm_tick', function () {
	$q = get_option('cp_wf_q', array());
	if (!is_array($q) || !$q) { return; }
	global $wpdb;
	foreach (array_slice($q, 0, 5, true) as $lid => $x) {
		if (time() - (int) ($x['t'] ?? 0) < 120) { continue; } /* a beküldés még dolgozik rajta */
		$res = cp_wf_create((int) $lid);
		if (!is_wp_error($res)) {
			$wpdb->update(cp_cut_t(), array('status' => 'live', 'deal_id' => (int) $res, 'checked_at' => gmdate('Y-m-d H:i:s')), array('ref' => 'form:' . (int) $lid));
			continue;
		}
		$q2 = get_option('cp_wf_q', array());
		if (!isset($q2[$lid])) { continue; }
		$q2[$lid]['n'] = (int) $q2[$lid]['n'] + 1; $q2[$lid]['e'] = mb_substr($res->get_error_message(), 0, 200);
		if ($q2[$lid]['n'] === 3 && function_exists('cp_notify') && function_exists('cp_pu_data')) {
			foreach (cp_pu_data()['list'] as $uid => $u) { if (!empty($u['a']) && ($u['r'] ?? '') === 'admin') { cp_notify((int) $uid, 'lead', 'Egy weboldalas ajánlatkérésből nem sikerült deal-t létrehozni (' . $q2[$lid]['e'] . ') – a Megkeresések között megvan, a panel tovább próbálja.', 'deals:leads', 'Crazy panel'); } }
		}
		if ($q2[$lid]['n'] >= 30) { unset($q2[$lid]); } /* kb. 5 óra után feladja – a megkeresés a listában marad */
		update_option('cp_wf_q', $q2, false);
	}
}, 25);

/* ------------------------------------------------------------ a weboldal kimenete: a Salesmate-szkript helyett a panelé */
function cp_wf_shim_url() { return plugins_url('assets/webforms.js', __FILE__) . '?v=' . CP_WF_VER . '.' . (int) @filemtime(__DIR__ . '/assets/webforms.js'); }
function cp_wf_rewrite($html) {
	if (strpos($html, 'studioframes.salesmate.io/webforms.js') === false) { return $html; }
	return preg_replace('#https?://studioframes\.salesmate\.io/webforms\.js#', esc_url_raw(cp_wf_shim_url()), $html);
}
function cp_wf_state() {
	return array('ec' => in_array(cp_cut_mode('forms_ec'), array('live', 'only'), true) ? 1 : 0, 'cp' => in_array(cp_cut_mode('forms_cp'), array('live', 'only'), true) ? 1 : 0, 'shim' => cp_wf_shim_url());
}
/* csak akkor cserél, ha valamelyik űrlap élesítve van (a szkript űrlaponként dönt, a többinél a Salesmate-űrlapot tölti be) */
add_action('template_redirect', function () {
	if (is_admin() || wp_doing_ajax() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
	$st = cp_wf_state();
	if (!$st['ec'] && !$st['cp']) { return; }
	ob_start('cp_wf_rewrite');
}, 1000);

/* a crazyphoto.hu innen kérdezi le, melyik űrlap él (crazy-lead-watcher.php) */
add_action('wp_ajax_nopriv_cp_wf_state', 'cp_wf_ajax_state');
add_action('wp_ajax_cp_wf_state', 'cp_wf_ajax_state');
function cp_wf_ajax_state() { cp_wf_cors(); wp_send_json(cp_wf_state()); }

/* átkapcsoláskor (Beállítások → Átállás): a gyorsítótárazott oldalak frissüljenek itt és a crazyphoto.hu-n is */
function cp_wf_switched() {
	if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
	wp_remote_get('https://crazyphoto.hu/?cp_wf_ping=' . time(), array('timeout' => 8, 'blocking' => true));
}

/* v2.9.10: amíg az űrlap a Salesmate-é (árnyék / Salesmate mód), a figyelő által elkapott beküldésről is megy értesítő a panel postafiókjába,
 * hogy a Levelezésben is ott legyen (élő módban a cp_wf_after küldi). */
add_action('cp_lead_received', function ($id, $pairs, $page) {
	global $wpdb;
	$fid = (string) $wpdb->get_var($wpdb->prepare('SELECT form_id FROM ' . cp_lead_t() . ' WHERE id = %d', (int) $id));
	$def = cp_wf_def($fid);
	if (!$def || empty($def['notify']) || cp_wf_live($def)) { return; }
	try { cp_wf_mail($def, (array) $pairs, 0, (string) $page, true); } catch (\Throwable $e) { /* az értesítő hibája ne akassza meg a beküldést */ }
}, 20, 3);
