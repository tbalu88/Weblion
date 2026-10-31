<?php
/* =====================================================================
 * Megkeresések (v1.42) – a weboldal Salesmate-űrlapjainak beküldései PÁRHUZAMOSAN a panelbe is.
 * A Salesmate webforms.js beküldéskor „SM_FORM_SUBMITTED” eseményt küld a beágyazó oldalnak (formId, formName,
 * formData). Egy kis figyelő az eventcrazy.hu oldalain ezt azonnal elküldi ide; a Salesmate ugyanúgy megkapja,
 * mint eddig – az űrlapon semmi nem változik. A panel eltárolja, értesít, és amikor a szinkron behozza a Salesmate
 * deal-t, összepárosítja vele (e-mail-cím és idő alapján).
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_LEAD_DB_VER', '1');

function cp_lead_t() { global $wpdb; return $wpdb->prefix . 'crazy_leads'; }

add_action('init', function () {
	if (get_option('cp_lead_db_ver') === CP_LEAD_DB_VER || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_lead_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id varchar(64) NOT NULL DEFAULT '',
  form_name varchar(190) NOT NULL DEFAULT '',
  data longtext NULL,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(60) NOT NULL DEFAULT '',
  page varchar(255) NOT NULL DEFAULT '',
  ip_hash varchar(32) NOT NULL DEFAULT '',
  deal_id bigint(20) unsigned NULL,
  status varchar(12) NOT NULL DEFAULT 'new',
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY created_at (created_at)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_lead_db_ver', CP_LEAD_DB_VER, false);
}, 7);
function cp_lead_db_ok() { return get_option('cp_lead_db_ver') === CP_LEAD_DB_VER; }

/* ------------------------------------------------------------ a figyelő a weboldalon (minden oldal alján, apró) */
add_action('wp_footer', function () {
	if (is_admin() || !cp_lead_db_ok()) { return; }
	$u = esc_url_raw(admin_url('admin-ajax.php'));
	/* v1.81.1: a régi SM_FORM_SUBMITTED mellett a Salesmate új űrlapfelületének (SalesmateFormsV1) beküldés-eseményére is figyel,
	   a mezőket onnan olvassa ki; ugyanazt a beküldést 15 mp-en belül csak egyszer küldi */
	echo "<script>(function(){if(window.__cpLead)return;window.__cpLead=1;var u=" . wp_json_encode($u) . ",last=0;function send(id,nm,data,ev){if(Date.now()-last<15000)return;last=Date.now();try{var b=new FormData();b.append('action','cp_lead_in');b.append('form',id||'');b.append('fname',nm||'');b.append('data',JSON.stringify(data||{}));b.append('page',location.href.slice(0,250));b.append('ev',ev);if(navigator.sendBeacon&&navigator.sendBeacon(u,b))return;fetch(u,{method:'POST',body:b,keepalive:true,credentials:'omit'});}catch(x){}}window.addEventListener('SM_FORM_SUBMITTED',function(e){var d=e.detail||{};send(d.formId,d.formName,d.formData||{},'legacy');});window.addEventListener('sm-form-event:on-submission:success',function(e){var d=e.detail||{},f=null;try{(window.SalesmateFormsV1?SalesmateFormsV1.getForms():[]).forEach(function(x){if(x.getInstanceId()===d.instanceId)f=x;});}catch(x){}send(d.formId,f?f.getFormName():'',f?f.getFormFieldValues():[],'v1');});})();</script>";
}, 99);

/* a formData egységes név → érték listává (a Salesmate tömböt vagy objektumot is küldhet) */
function cp_lead_pairs($fd) {
	$out = array();
	$walk = function ($v, $k) use (&$out, &$walk) {
		if (is_array($v) && isset($v['name']) && array_key_exists('value', $v) && !is_array($v['name'])) { $out[(string) $v['name']] = is_array($v['value']) ? implode(', ', array_map('strval', $v['value'])) : (string) $v['value']; return; }
		if (is_array($v)) { foreach ($v as $k2 => $v2) { $walk($v2, is_int($k2) ? $k : ($k !== '' ? $k . '.' : '') . $k2); } return; }
		if ($k !== '') { $out[$k] = (string) $v; }
	};
	$walk($fd, '');
	$clean = array();
	foreach (array_slice($out, 0, 80, true) as $k => $v) { $clean[mb_substr(sanitize_text_field($k), 0, 80)] = mb_substr(sanitize_textarea_field($v), 0, 2000); }
	return $clean;
}
function cp_lead_pick($pairs, $re) { foreach ($pairs as $k => $v) { if ($v !== '' && preg_match($re, $k)) { return $v; } } return ''; }

add_action('wp_ajax_nopriv_cp_lead_in', 'cp_lead_ajax_in');
add_action('wp_ajax_cp_lead_in', 'cp_lead_ajax_in');
function cp_lead_ajax_in() {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_lead_db_ok()) { wp_send_json_error(null, 400); }
	global $wpdb;
	$raw = (string) wp_unslash($_POST['data'] ?? '');
	if (strlen($raw) > 30000) { wp_send_json_error(null, 413); }
	/* visszaélés ellen: IP-nként óránként legfeljebb 15 beküldés */
	$ip = md5((string) ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt('nonce'));
	$rk = 'cp_lead_rl_' . $ip; $n = (int) get_transient($rk);
	if ($n >= 15) { wp_send_json_error(null, 429); }
	set_transient($rk, $n + 1, HOUR_IN_SECONDS);
	$fd = json_decode($raw, true);
	$pairs = is_array($fd) ? cp_lead_pairs($fd) : array();
	if (!$pairs) { wp_send_json_error(null, 400); }
	$email = sanitize_email(cp_lead_pick($pairs, '/e-?mail/i'));
	$first = cp_lead_pick($pairs, '/first.?name|keresztn/i'); $last = cp_lead_pick($pairs, '/last.?name|vezet[eé]kn/i');
	$name = trim(cp_lead_pick($pairs, '/(^|\.)(name|n[eé]v|full.?name)$/i') ?: trim($last . ' ' . $first));
	$phone = cp_lead_pick($pairs, '/phone|mobile|telefon|mobil/i');
	/* ugyanaz a beküldés kétszer (pl. dupla esemény) 2 percen belül: csak egyszer */
	$h = md5($raw);
	if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_lead_t() . ' WHERE ip_hash = %s AND MD5(data) = %s AND created_at >= %s', $ip, $h, gmdate('Y-m-d H:i:s', time() - 120)))) { wp_send_json_success(array('dup' => 1)); }
	$wpdb->insert(cp_lead_t(), array('form_id' => mb_substr(sanitize_text_field((string) wp_unslash($_POST['form'] ?? '')), 0, 64), 'form_name' => mb_substr(sanitize_text_field((string) wp_unslash($_POST['fname'] ?? '')), 0, 190),
		'data' => $raw, 'name' => mb_substr($name, 0, 190), 'email' => mb_substr($email, 0, 190), 'phone' => mb_substr($phone, 0, 60), 'page' => mb_substr(esc_url_raw((string) wp_unslash($_POST['page'] ?? '')), 0, 255),
		'ip_hash' => $ip, 'status' => 'new', 'created_at' => gmdate('Y-m-d H:i:s')));
	$id = (int) $wpdb->insert_id;
	/* v1.70: az átállás árnyék-üzeme (cutover.php) ebből számolja a panel deal-tervét */
	do_action('cp_lead_received', $id, $pairs, (string) wp_unslash($_POST['page'] ?? ''));
	/* a Salesmate deal minél előbb jöjjön át: soron kívüli szinkron 1,5 perc múlva */
	if (!wp_next_scheduled('cp_crm_more')) { wp_schedule_single_event(time() + 90, 'cp_crm_more'); }
	/* értesítés minden aktív kollégának (csengő, és ha be van állítva, Pumble) */
	if (function_exists('cp_notify') && function_exists('cp_pu_data')) {
		$what = cp_lead_pick($pairs, '/multiSelectCustomField3|szolg[aá]ltat|service/i');
		$when = cp_lead_pick($pairs, '/dateCustomField1|d[aá]tum|date/i');
		$txt = 'Új ajánlatkérés: ' . ($name !== '' ? $name : ($email !== '' ? $email : 'névtelen')) . ($when !== '' ? ' · ' . $when : '') . ($what !== '' ? ' · ' . mb_substr($what, 0, 80) : '');
		foreach (cp_pu_data()['list'] as $uid => $u) { if (!empty($u['a'])) { cp_notify((int) $uid, 'lead', $txt, 'deals:leads'); } }
	}
	wp_send_json_success(array('id' => $id));
}

/* a megkeresés párosítása a szinkronnal átjött Salesmate deal-lel: azonos e-mail, a beküldés körül létrehozva */
function cp_lead_match() {
	global $wpdb;
	if (!function_exists('cp_crm_t')) { return; }
	$rows = (array) $wpdb->get_results('SELECT id, email, created_at FROM ' . cp_lead_t() . " WHERE deal_id IS NULL AND email <> '' AND created_at >= '" . gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS) . "' LIMIT 200", ARRAY_A);
	foreach ($rows as $r) {
		$d = (int) $wpdb->get_var($wpdb->prepare('SELECT d.id FROM ' . cp_crm_t('deals') . ' d JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE c.email = %s AND d.created_at BETWEEN %s AND %s ORDER BY ABS(TIMESTAMPDIFF(SECOND, d.created_at, %s)) LIMIT 1',
			$r['email'], gmdate('Y-m-d H:i:s', strtotime($r['created_at']) - 1800), gmdate('Y-m-d H:i:s', strtotime($r['created_at']) + 6 * 3600), $r['created_at']));
		if ($d) { $wpdb->update(cp_lead_t(), array('deal_id' => $d), array('id' => (int) $r['id'])); }
	}
}

/* ------------------------------------------------------------ a panel: Deal-ek → Megkeresések */
add_action('wp_ajax_nopriv_cp_lead_list', 'cp_lead_ajax_list');
add_action('wp_ajax_cp_lead_list', 'cp_lead_ajax_list');
function cp_lead_ajax_list() {
	cp_el_auth();
	if (function_exists('cp_panel_can_x') && !cp_panel_can_x('deals')) { cp_el_err('Nincs jogosultságod.', 403); }
	if (!cp_lead_db_ok()) { cp_el_err('Az adatbázis még készül.', 503); }
	global $wpdb;
	cp_lead_match();
	$w = "status <> 'spam'";
	if (($_POST['st'] ?? '') === 'spam') { $w = "status = 'spam'"; }
	/* v1.43: a pipeline-hoz – csak a még párosítatlan (Salesmate-szinkronra váró) megkeresések, és a friss párosítások */
	$pending = !empty($_POST['pending']);
	if ($pending) { $w = "status <> 'spam' AND deal_id IS NULL AND created_at >= '" . gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS) . "'"; }
	$q = trim((string) wp_unslash($_POST['q'] ?? ''));
	if ($q !== '') { $l = '%' . $wpdb->esc_like($q) . '%'; $w .= $wpdb->prepare(' AND (name LIKE %s OR email LIKE %s OR phone LIKE %s OR data LIKE %s)', $l, $l, $l, $l); }
	$rows = (array) $wpdb->get_results('SELECT * FROM ' . cp_lead_t() . " WHERE $w ORDER BY id DESC LIMIT 200", ARRAY_A);
	$deals = array();
	$ids = array_filter(array_map(function ($r) { return (int) $r['deal_id']; }, $rows));
	if ($ids && function_exists('cp_crm_t')) { foreach ((array) $wpdb->get_results('SELECT id, title, stage, status FROM ' . cp_crm_t('deals') . ' WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A) as $d) { $deals[(int) $d['id']] = $d; } }
	$out = array();
	foreach ($rows as $r) {
		$fd = json_decode((string) $r['data'], true);
		$d = $r['deal_id'] ? ($deals[(int) $r['deal_id']] ?? null) : null;
		$out[] = array('id' => (int) $r['id'], 'form' => $r['form_name'] !== '' ? $r['form_name'] : $r['form_id'], 'n' => $r['name'], 'e' => $r['email'], 'p' => $r['phone'], 'page' => $r['page'], 'st' => $r['status'],
			'at' => function_exists('cp_crm_local') ? cp_crm_local($r['created_at']) : $r['created_at'], 'f' => is_array($fd) ? cp_lead_pairs($fd) : array(),
			'deal' => $d ? array('id' => (int) $d['id'], 't' => $d['title'], 'stage' => $d['stage'], 'status' => $d['status']) : null);
	}
	$fresh = array();
	if ($pending) {
		foreach ((array) $wpdb->get_results('SELECT id, deal_id FROM ' . cp_lead_t() . " WHERE status = 'new' AND deal_id IS NOT NULL AND created_at >= '" . gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS) . "'", ARRAY_A) as $f) { $fresh[(int) $f['deal_id']] = (int) $f['id']; }
	}
	wp_send_json_success(array('rows' => $out, 'labels' => cp_lead_labels(), 'cols' => cp_lead_cols(), 'fresh' => (object) $fresh));
}

add_action('wp_ajax_nopriv_cp_lead_set', 'cp_lead_ajax_set');
add_action('wp_ajax_cp_lead_set', 'cp_lead_ajax_set');
function cp_lead_ajax_set() {
	cp_el_auth();
	cp_tab_need(array('deals'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_lead_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$st = sanitize_key(wp_unslash($_POST['st'] ?? ''));
	if (!in_array($st, array('new', 'seen', 'spam'), true)) { cp_el_err('Hibás kérés.'); }
	$wpdb->update(cp_lead_t(), array('status' => $st), array('id' => (int) ($_POST['id'] ?? 0)));
	wp_send_json_success(array('ok' => 1));
}

/* a Salesmate mezőnevek magyar címkéi a megjelenítéshez (a CRM mezőtérképéből, ha elérhető) */
function cp_lead_labels() {
	$l = array('Contact.firstName' => 'Keresztnév', 'Contact.lastName' => 'Vezetéknév', 'Contact.name' => 'Név', 'Contact.email' => 'E-mail', 'Contact.mobile' => 'Mobil', 'Contact.phone' => 'Telefon',
		'Contact.company' => 'Cég', 'Company.name' => 'Cég', 'Deal.title' => 'Deal neve', 'Deal.description' => 'Megjegyzés', 'Deal.dateCustomField1' => 'Rendezvény dátuma', 'Deal.multiSelectCustomField3' => 'Szolgáltatás(ok)');
	return $l;
}
/* Salesmate deal-mező → a panel oszlopneve (a címkét a Deal-ek fül adja hozzá) */
function cp_lead_cols() {
	$o = array();
	if (function_exists('cp_crm_spec')) { foreach ((array) cp_crm_spec('deal') as $col => $x) { if (is_array($x) && !empty($x[0])) { $o['Deal.' . $x[0]] = $col; } } }
	return $o;
}
