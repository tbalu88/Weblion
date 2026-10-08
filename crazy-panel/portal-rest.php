<?php
/* Ügyfélportál – REST végpontok
 *   GET   /wp-json/crazy/v1/portal/form                 mezők, válaszok, határidő, zárolás
 *   PATCH /wp-json/crazy/v1/portal/form/{field_key}     egy mező mentése (POST is elfogadott)
 *   POST  /wp-json/crazy/v1/portal/form/complete        „Kész” gomb
 *   POST  /wp-json/crazy/v1/salesmate/deal-stage?key=…  Salesmate-webhook (szakaszváltás)
 * Az ügyféloldali végpontok a portál sütijével azonosítanak; írásnál X-CP-Nonce fejléc is kell. */

if (!defined('ABSPATH')) { exit; }

add_action('rest_api_init', 'cp_portal_rest_routes');
function cp_portal_rest_routes() {
	register_rest_route('crazy/v1', '/portal/form/complete', array(
		'methods' => 'POST',
		'permission_callback' => 'cp_portal_rest_perm_write',
		'callback' => 'cp_portal_rest_complete',
	));
	register_rest_route('crazy/v1', '/portal/form/(?P<field_key>[a-z0-9_]{2,64})', array(
		'methods' => 'PATCH, POST',
		'permission_callback' => 'cp_portal_rest_perm_write',
		'callback' => 'cp_portal_rest_patch',
	));
	register_rest_route('crazy/v1', '/portal/form', array(
		'methods' => 'GET',
		'permission_callback' => 'cp_portal_rest_perm_read',
		'callback' => 'cp_portal_rest_get',
	));
	register_rest_route('crazy/v1', '/salesmate/deal-stage', array(
		'methods' => 'POST',
		'permission_callback' => 'cp_portal_rest_perm_webhook',
		'callback' => 'cp_portal_rest_webhook',
	));
}

function cp_portal_rest_nocache() {
	if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
	nocache_headers();
}

function cp_portal_rest_perm_read() {
	cp_portal_rest_nocache();
	if (!cp_portal_session()) { return new WP_Error('cp_portal_auth', 'A belépés lejárt – nyisd meg újra az e-mailben kapott linket.', array('status' => 401)); }
	return true;
}

function cp_portal_rest_perm_write(WP_REST_Request $req) {
	$r = cp_portal_rest_perm_read();
	if (is_wp_error($r)) { return $r; }
	$s = cp_portal_session();
	if (!hash_equals(cp_portal_nonce($s['token']), (string) $req->get_header('x-cp-nonce'))) {
		return new WP_Error('cp_portal_nonce', 'Lejárt az oldal – töltsd újra.', array('status' => 403));
	}
	return true;
}

function cp_portal_rest_get() {
	$s = cp_portal_session();
	return cp_portal_form_payload($s['portal']);
}

function cp_portal_rest_patch(WP_REST_Request $req) {
	$s = cp_portal_session();
	$key = (string) $req->get_param('field_key');
	$fields = cp_portal_fields();
	if (!isset($fields[$key])) { return new WP_Error('cp_portal_field', 'Ismeretlen mező.', array('status' => 404)); }
	$body = $req->get_json_params();
	$raw = is_array($body) && array_key_exists('value', $body) ? $body['value'] : null;
	$r = cp_portal_save_field($s['portal'], $key, $raw, 'ugyfel');
	if (is_wp_error($r)) {
		$d = $r->get_error_data();
		$status = is_array($d) && isset($d['status']) ? $d['status'] : 400;
		return new WP_REST_Response(array('ok' => false, 'code' => $r->get_error_code(), 'message' => $r->get_error_message(), 'field' => $key, 'part' => is_array($d) && isset($d['part']) ? $d['part'] : null), $status);
	}
	$r['ok'] = true;
	$r['field'] = $key;
	return $r;
}

function cp_portal_rest_complete() {
	$s = cp_portal_session();
	if (cp_portal_locked($s['portal'])) {
		return new WP_REST_Response(array('ok' => false, 'code' => 'locked', 'message' => 'A határidő lejárt, az adatlap már nem módosítható.'), 423);
	}
	$r = cp_portal_complete($s['portal']);
	if (empty($r['ok'])) {
		$list = array();
		foreach ($r['missing'] as $q => $label) { $list[] = array('q' => $q, 'label' => $label); }
		return new WP_REST_Response(array('ok' => false, 'code' => 'missing', 'message' => 'Még hiányzik néhány kötelező válasz.', 'missing' => $list), 422);
	}
	return $r;
}

/* ------------------------------------------------------------ Salesmate-webhook */

function cp_portal_rest_perm_webhook(WP_REST_Request $req) {
	cp_portal_rest_nocache();
	$o = cp_portal_opts();
	$given = (string) ($req->get_header('x-crazy-key') ?: $req->get_param('key'));
	if ($o['webhook_key'] === '' || $given === '' || !hash_equals($o['webhook_key'], $given)) {
		return new WP_Error('cp_portal_key', 'Hibás kulcs.', array('status' => 403));
	}
	return true;
}

/* a webhook tartalmában nem bízunk: csak a deal azonosítóját vesszük ki, minden mást a Salesmate API-tól kérdezünk */
function cp_portal_webhook_deal_id($body) {
	if (!is_array($body)) { return ''; }
	foreach (array(array('id'), array('dealId'), array('deal', 'id'), array('data', 'id'), array('Data', 'id'), array('payload', 'id'), array('record', 'id'), array('data', 'deal', 'id')) as $path) {
		$v = $body;
		foreach ($path as $p) { $v = (is_array($v) && isset($v[$p])) ? $v[$p] : null; }
		if (is_scalar($v) && preg_match('/^\d{1,20}$/', (string) $v)) { return (string) $v; }
	}
	return '';
}

function cp_portal_rest_webhook(WP_REST_Request $req) {
	$body = $req->get_json_params();
	if (!is_array($body)) { $body = $req->get_body_params(); }
	$id = cp_portal_webhook_deal_id($body);
	if ($id === '' && preg_match('/^\d{1,20}$/', (string) $req->get_param('deal_id'))) { $id = (string) $req->get_param('deal_id'); }
	update_option('cp_portal_webhook_last', array('t' => time(), 'deal' => $id, 'keys' => is_array($body) ? array_slice(array_keys($body), 0, 20) : array()), false);
	if ($id === '') { return new WP_REST_Response(array('ok' => false, 'msg' => 'Nincs deal-azonosító a kérésben.'), 400); }
	$r = cp_portal_on_stage($id, '', 'webhook');
	return array('ok' => true, 'deal' => $id, 'result' => $r);
}
