<?php
/**
 * Crazy panel – új kontakt / új cég a Kapcsolatok oldalon (v2.9.184).
 * A Salesmate-ben jön létre (ugyanúgy, mint az Új deal kontaktja), és azonnal a panel saját táblájába is bekerül.
 * Duplikátum-védelem: azonos e-mail-című kontakt, illetve azonos nevű cég (cégforma / írásjelek nélkül) esetén nem hoz
 * létre újat, hanem a meglévőt adja vissza.
 */
if (!defined('ABSPATH')) { exit; }

add_action('wp_ajax_nopriv_cp_crm_people_new', 'cp_pn_ajax_new');
add_action('wp_ajax_cp_crm_people_new', 'cp_pn_ajax_new');
function cp_pn_ajax_new() {
	cp_crm_ajax_guard();
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (empty(cp_panel_opts()['stage_write'])) { cp_el_err('A Salesmate-be írás a panel beállításaiban ki van kapcsolva.'); }
	global $wpdb;
	$g = function ($k, $max = 190) { return mb_substr(trim(sanitize_text_field((string) wp_unslash($_POST[$k] ?? ''))), 0, $max); };
	$kind = $g('kind') === 'co' ? 'co' : 'ct';
	$CO = cp_crm_t('companies');
	/* cég keresése / létrehozása név szerint */
	$company = function ($name, $web, $create) use ($wpdb, $CO) {
		$nk = function_exists('cp_cos_norm') ? cp_cos_norm($name) : mb_strtolower($name);
		$w1 = explode(' ', $nk)[0] ?? '';
		if ($w1 !== '') {
			foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, name FROM $CO WHERE deleted_at IS NULL AND name LIKE %s LIMIT 80", '%' . $wpdb->esc_like($w1) . '%'), ARRAY_A) as $c) {
				if ((function_exists('cp_cos_norm') ? cp_cos_norm($c['name']) : mb_strtolower($c['name'])) === $nk) { return array((int) $c['id'], false); }
			}
		}
		if (!$create) { return array(0, false); }
		$r = cp_sm_request('POST', '/apis/company/v4', array_filter(array('name' => $name, 'website' => $web), function ($v) { return $v !== ''; }));
		if (is_wp_error($r)) { cp_el_err('A céget nem sikerült létrehozni a Salesmate-ben: ' . $r->get_error_message(), 502); }
		$cid = cp_crm_new_id($r);
		if (!$cid) { cp_el_err('A cég létrejött, de a Salesmate nem adta vissza az azonosítóját – pár perc múlva megjelenik a listában.', 502); }
		$gg = cp_sm_request('GET', '/apis/company/v4/' . $cid);
		if (!is_wp_error($gg)) { cp_crm_store('company', array(isset($gg['Data']) ? $gg['Data'] : $gg), 'get'); }
		return array((int) $cid, true);
	};
	if ($kind === 'co') {
		$name = $g('name'); $web = esc_url_raw($g('web', 255));
		if ($name === '') { cp_el_err('Add meg a cég nevét.'); }
		list($cid, $made) = $company($name, $web, true);
		if (!$made) { wp_send_json_success(array('kind' => 'co', 'id' => $cid, 'exists' => 1)); }
		if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'company_new', 'Új cég', 'company:' . $cid, $name); }
		wp_send_json_success(array('kind' => 'co', 'id' => $cid, 'exists' => 0));
	}
	$name = $g('name'); $email = sanitize_email($g('email')); $phone = mb_substr(preg_replace('/[^0-9+ ()\/-]/', '', $g('phone', 60)), 0, 40); $coName = $g('company');
	if ($name === '') { cp_el_err('Add meg a kontakt nevét.'); }
	if ($g('email') !== '' && !is_email($email)) { cp_el_err('Érvénytelen e-mail-cím.'); }
	if ($email !== '') {
		$ex = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_crm_t('contacts') . ' WHERE email = %s AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', $email));
		if ($ex) { wp_send_json_success(array('kind' => 'ct', 'id' => $ex, 'exists' => 1)); }
	}
	$coId = 0; $warn = '';
	if ($coName !== '') { list($coId) = $company($coName, '', true); }
	$cid = cp_crm_contact_new($name, $email, $phone);
	if ($coId) {
		$p = cp_sm_request('PUT', '/apis/contact/v4/' . $cid, array('company' => $coId));
		if (is_wp_error($p)) { $warn = 'A kontakt létrejött, de a céghez nem sikerült hozzárendelni: ' . $p->get_error_message(); }
		else { $cg = cp_sm_request('GET', '/apis/contact/v4/' . $cid); if (!is_wp_error($cg)) { cp_crm_store('contact', array(isset($cg['Data']) ? $cg['Data'] : $cg), 'get'); } }
	}
	if (function_exists('cp_audit_add')) { cp_audit_add('deal', 'contact_new', 'Új kontakt', 'contact:' . $cid, $name); }
	wp_send_json_success(array('kind' => 'ct', 'id' => $cid, 'exists' => 0, 'warn' => $warn));
}
