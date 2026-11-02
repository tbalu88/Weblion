<?php
/* Ügyfélportál – belépés: személyes link (token), süti-munkamenet, IP-korlát, írásvédelem
 * A token 32 bájtos véletlen érték base64url-kódolva; az adatbázisban csak a SHA-256 hash-e van. */

if (!defined('ABSPATH')) { exit; }

function cp_portal_token_hash($raw) {
	return hash('sha256', (string) $raw);
}

function cp_portal_token_format_ok($raw) {
	return is_string($raw) && preg_match('/^[A-Za-z0-9_-]{43}$/', $raw) === 1;
}

/* új belépőlink; a nyers tokent adja vissza (csak most látszik) */
function cp_portal_token_new($portal, $email = '') {
	global $wpdb;
	$raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	$exp = cp_portal_link_expiry($portal->event_date);
	$ok = $wpdb->insert(cp_portal_t('access_tokens'), array(
		'portal_id' => (int) $portal->id,
		'token_hash' => cp_portal_token_hash($raw),
		'email' => mb_substr((string) $email, 0, 190),
		'created_at' => cp_portal_db_now(),
		'expires_at' => cp_portal_db_now($exp),
	));
	return $ok ? $raw : new WP_Error('token', 'Nem sikerült belépőlinket létrehozni.');
}

function cp_portal_link_for($raw) {
	return cp_portal_url('/r/' . $raw);
}

/* új link a portálhoz (e-mailekbe, panelből másoláshoz) */
function cp_portal_issue_link($portal) {
	$email = isset($portal->sm['email']) ? $portal->sm['email'] : '';
	$raw = cp_portal_token_new($portal, $email);
	return is_wp_error($raw) ? $raw : cp_portal_link_for($raw);
}

/* a token sora (érvényességtől függetlenül) vagy null */
function cp_portal_token_find($raw) {
	global $wpdb;
	if (!cp_portal_token_format_ok($raw) || !cp_portal_db_ready()) { return null; }
	return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_portal_t('access_tokens') . ' WHERE token_hash = %s', cp_portal_token_hash($raw)));
}

/* '' ha érvényes, különben az ok: revoked | expired | closed | missing */
function cp_portal_token_problem($tok, $portal) {
	if (!$tok || !$portal) { return 'missing'; }
	if (!empty($tok->revoked_at)) { return 'revoked'; }
	if (cp_portal_db_ts($tok->expires_at) < time()) { return 'expired'; }
	if ($portal->status !== 'aktiv' && cp_portal_link_expiry($portal->event_date) < time()) { return 'closed'; }
	return '';
}

function cp_portal_tokens_revoke($pid) {
	global $wpdb;
	return (int) $wpdb->query($wpdb->prepare('UPDATE ' . cp_portal_t('access_tokens') . ' SET revoked_at = %s WHERE portal_id = %d AND revoked_at IS NULL', cp_portal_db_now(), (int) $pid));
}

function cp_portal_tokens_list($pid) {
	global $wpdb;
	return (array) $wpdb->get_results($wpdb->prepare('SELECT id, email, created_at, expires_at, revoked_at, last_used_at FROM ' . cp_portal_t('access_tokens') . ' WHERE portal_id = %d ORDER BY id DESC LIMIT 30', (int) $pid));
}

/* a rendezvény dátumának változásakor az élő linkek lejárata is igazodik */
function cp_portal_tokens_reexpire($portal) {
	global $wpdb;
	$wpdb->query($wpdb->prepare('UPDATE ' . cp_portal_t('access_tokens') . ' SET expires_at = %s WHERE portal_id = %d AND revoked_at IS NULL', cp_portal_db_now(cp_portal_link_expiry($portal->event_date)), (int) $portal->id));
}

/* ------------------------------------------------------------ süti-munkamenet
 * A süti a nyers tokent tárolja (HttpOnly, Secure, SameSite=Lax, 30 nap); minden kérésnél
 * újra ellenőrizzük az adatbázisban, így a visszavonás és a lejárat azonnal érvényes. */

function cp_portal_cookie_set($raw) {
	$opts = array('expires' => time() + CP_PORTAL_COOKIE_DAYS * DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax');
	setcookie(CP_PORTAL_COOKIE, $raw, $opts);
	$_COOKIE[CP_PORTAL_COOKIE] = $raw;
}

function cp_portal_cookie_clear() {
	setcookie(CP_PORTAL_COOKIE, '', array('expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax'));
	unset($_COOKIE[CP_PORTAL_COOKIE]);
}

function cp_portal_cookie_raw() {
	return isset($_COOKIE[CP_PORTAL_COOKIE]) ? (string) wp_unslash($_COOKIE[CP_PORTAL_COOKIE]) : '';
}

/* érvényes munkamenet: array('portal' => …, 'token' => …) vagy null */
function cp_portal_session() {
	static $memo = false;
	if ($memo !== false) { return $memo; }
	$memo = null;
	$raw = cp_portal_cookie_raw();
	if ($raw === '') { return null; }
	$tok = cp_portal_token_find($raw);
	$portal = $tok ? cp_portal_get($tok->portal_id) : null;
	if (cp_portal_token_problem($tok, $portal) !== '') { return null; }
	if (cp_portal_db_ts($tok->last_used_at) < time() - 600) {
		global $wpdb;
		$wpdb->update(cp_portal_t('access_tokens'), array('last_used_at' => cp_portal_db_now()), array('id' => (int) $tok->id));
	}
	$memo = array('portal' => $portal, 'token' => $tok);
	return $memo;
}

/* írásvédelem: a munkamenethez kötött kulcs, a JS fejlécben küldi (X-CP-Nonce) */
function cp_portal_nonce($tok) {
	return substr(hash_hmac('sha256', 'portal-nonce|' . $tok->token_hash, wp_salt('nonce')), 0, 32);
}

/* ------------------------------------------------------------ IP-alapú korlát */

/* true, ha még belefér; minden hívás számít */
function cp_portal_rate_ok($bucket, $limit, $window) {
	$key = 'cp_portal_rl_' . md5($bucket . '|' . cp_portal_ip());
	$n = (int) get_transient($key);
	if ($n >= $limit) { return false; }
	set_transient($key, $n + 1, $window);
	return true;
}

function cp_portal_mask_email($e) {
	$e = (string) $e;
	if (strpos($e, '@') === false) { return $e; }
	list($u, $d) = explode('@', $e, 2);
	return mb_substr($u, 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($u) - 2))) . '@' . $d;
}
