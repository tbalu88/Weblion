<?php
/* =====================================================================
 * Személyes felhasználók a panelhez (v1.29)
 * Mindenki saját e-mail-címmel és jelszóval lép be; szerepkör: admin (mindent lát) vagy iroda (a Statisztikák nélkül).
 * A felhasználókat az admin veszi fel (WordPress admin → Beállítások → Panel-felhasználók); a meghívott e-mailben
 * kap egy linket, amellyel a saját jelszavát beállítja. Elfelejtett jelszó: ugyanígy, e-mailben.
 * Átmenetként a régi közös jelszavak (admin / iroda) még működnek, amíg ki nem kapcsolod őket.
 * A változásnaplóban és később a levelezésben a felhasználó neve szerepel.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_PU_OPT', 'cp_panel_users');

/* ------------------------------------------------------------ tárolás */

function cp_pu_data() {
	$d = get_option(CP_PU_OPT, array());
	if (!is_array($d)) { $d = array(); }
	if (empty($d['list']) || !is_array($d['list'])) { $d['list'] = array(); }
	if (empty($d['next'])) { $d['next'] = 1; }
	return $d;
}

function cp_pu_save($d) {
	update_option(CP_PU_OPT, $d, false);
}

function cp_pu_get($uid) {
	$d = cp_pu_data();
	return isset($d['list'][(int) $uid]) ? $d['list'][(int) $uid] : null;
}

function cp_pu_by_email($email) {
	$email = strtolower(trim((string) $email));
	if ($email === '') { return 0; }
	foreach (cp_pu_data()['list'] as $id => $u) { if (strtolower($u['e']) === $email) { return (int) $id; } }
	return 0;
}

function cp_pu_roles() {
	return array('admin' => 'Admin – mindent lát', 'iroda' => 'Iroda – a Statisztikák nélkül');
}

/* a régi közös jelszavak (átmenet): kikapcsolhatók, ha már mindenkinek van saját belépése */
function cp_pu_shared_on() {
	return get_option('cp_panel_shared_off', '0') !== '1';
}

/* ------------------------------------------------------------ süti: „lejárat|u|azonosító|aláírás” */

function cp_pu_sig($exp, $uid, $u) {
	return hash_hmac('sha256', $exp . '|u|' . (int) $uid . '|' . (int) $u['v'] . '|' . substr((string) $u['h'], 0, 20), wp_salt('auth'));
}

function cp_pu_set_cookie($uid) {
	$u = cp_pu_get($uid);
	if (!$u) { return; }
	$exp = time() + 30 * DAY_IN_SECONDS;
	cp_panel_setcookie($exp . '|u|' . (int) $uid . '|' . cp_pu_sig($exp, $uid, $u), $exp);
	$d = cp_pu_data();
	$d['list'][(int) $uid]['last'] = time();
	cp_pu_save($d);
}

/* a sütiben lévő felhasználó azonosítója (0, ha nincs vagy érvénytelen) */
function cp_pu_current_id() {
	static $id = null;
	if ($id !== null) { return $id; }
	$id = 0;
	if (empty($_COOKIE[CP_PANEL_COOKIE])) { return $id; }
	$p = explode('|', (string) wp_unslash($_COOKIE[CP_PANEL_COOKIE]));
	if (count($p) !== 4 || $p[1] !== 'u' || (int) $p[0] < time()) { return $id; }
	$u = cp_pu_get((int) $p[2]);
	if (!$u || empty($u['a']) || (string) $u['h'] === '') { return $id; }
	if (hash_equals(cp_pu_sig((int) $p[0], (int) $p[2], $u), $p[3])) { $id = (int) $p[2]; }
	return $id;
}

function cp_pu_current() {
	$id = cp_pu_current_id();
	return $id ? cp_pu_get($id) : null;
}

/* ------------------------------------------------------------ belépés */

function cp_pu_login($email, $pw) {
	$uid = cp_pu_by_email($email);
	$u = $uid ? cp_pu_get($uid) : null;
	require_once ABSPATH . WPINC . '/class-phpass.php';
	if (!$u || empty($u['a']) || (string) $u['h'] === '' || !wp_check_password($pw, $u['h'])) {
		return new WP_Error('login', $u && (string) $u['h'] === '' && !empty($u['a']) ? 'Ehhez a fiókhoz még nincs jelszó beállítva – használd a meghívó e-mailben kapott linket, vagy kérj újat az „Elfelejtett jelszó” linkkel.' : 'Hibás e-mail-cím vagy jelszó.');
	}
	return $uid;
}

/* ------------------------------------------------------------ v1.37: ki melyik fület / eszközt látja
 * Alapértelmezés szerepkör szerint (Statisztikák és Tárca: csak admin, minden más: mindenki); felhasználónként
 * eltérés állítható (cp_panel_vis: uid => tab => 0/1). A közös jelszavas belépésnél az alapértelmezés érvényes. */
function cp_pu_tabs() {
	return array('kezdolap' => 'Kezdőlap', 'stat' => 'Statisztikák', 'deals' => 'Deal-ek', 'kapcsolatok' => 'Kapcsolatok', 'mail' => 'Levelezés', 'feladatok' => 'Feladatok', 'naptar' => 'Naptár', 'kovetes' => 'Követés',
		'hatterek' => 'Hátterek', 'munkalap' => 'Napi munkalap', 'elszamolas' => 'Elszámolás', 'tarca' => 'Tárca', 'ellenorzes' => 'Gyors ellenőrzés', 'sablon' => 'Sablonkészítő', 'hatterkat' => 'Háttérkatalógus', 'flows' => 'Automatizmusok', 'beallitasok' => 'Beállítások');
}
function cp_pu_tab_default($tab, $role) { return in_array($tab, array('stat', 'tarca', 'flows', 'beallitasok'), true) ? $role === 'admin' : true; }
function cp_pu_vis() { $v = get_option('cp_panel_vis', array()); return is_array($v) ? $v : array(); }
function cp_pu_can_uid($uid, $tab, $role) {
	$v = cp_pu_vis();
	if ($uid > 0 && isset($v[$uid][$tab])) { return (bool) $v[$uid][$tab]; }
	return cp_pu_tab_default($tab, $role);
}
function cp_panel_can($tab) {
	$uid = cp_pu_current_id();
	$role = function_exists('cp_panel_role') ? cp_panel_role() : 'iroda';
	return cp_pu_can_uid($uid, $tab, $role);
}
function cp_panel_tabs_allowed() { return array_values(array_filter(array_keys(cp_pu_tabs()), 'cp_panel_can')); }

function cp_pu_vis_section($d) {
	$tabs = cp_pu_tabs();
	echo '<h2>Ki mit lát a panelben</h2><form method="post">' . wp_nonce_field('cp_pu_do', '_wpnonce', true, false) . '<input type="hidden" name="cp_pu_do" value="vis">';
	echo '<p>Pipáld be, kinek melyik fül és eszköz jelenjen meg. Alapból a <b>Statisztikák</b>, a <b>Tárca</b> és az <b>Automatizmusok</b> csak az adminoknak látszik, minden más mindenkinek; az alaptól eltérő beállítás <b>félkövér</b>. A Statisztikák és a Tárca adatait a szerver is csak annak adja ki, akinek be van pipálva.</p>';
	echo '<div style="overflow-x:auto"><table class="widefat striped" style="width:auto"><thead><tr><th>Felhasználó</th>';
	foreach ($tabs as $l) { echo '<th style="text-align:center;font-size:12px">' . esc_html($l) . '</th>'; }
	echo '</tr></thead><tbody>';
	foreach ($d['list'] as $id => $u) {
		if (empty($u['a'])) { continue; }
		echo '<tr><td>' . esc_html($u['n']) . ' <small>(' . esc_html($u['r']) . ')</small></td>';
		foreach ($tabs as $k => $l) {
			$on = cp_pu_can_uid((int) $id, $k, $u['r']);
			$diff = $on !== cp_pu_tab_default($k, $u['r']);
			echo '<td style="text-align:center' . ($diff ? ';font-weight:700;background:#fff7e0' : '') . '"><input type="checkbox" name="vis[' . (int) $id . '][' . esc_attr($k) . ']" value="1"' . checked($on, true, false) . ' title="' . esc_attr($u['n'] . ' – ' . $l) . '"></td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table></div><p><button class="button button-primary">Mentés</button> <button class="button" name="vis_reset" value="1" onclick="return confirm(\'Mindenkinél visszaállítod az alapértelmezést?\')">Alapértelmezés visszaállítása</button></p></form>';
}

/* ------------------------------------------------------------ meghívó / jelszó-visszaállító link */

function cp_pu_panel_url($q = '') {
	$o = cp_panel_opts();
	return home_url('/' . trim($o['slug'], '/') . '/' . ($q !== '' ? '?' . $q : ''));
}

/* új link (a régit érvényteleníti); $hours: érvényesség */
function cp_pu_make_link($uid, $hours) {
	$d = cp_pu_data();
	if (!isset($d['list'][(int) $uid])) { return ''; }
	$tok = wp_generate_password(40, false, false);
	$d['list'][(int) $uid]['tok'] = hash('sha256', $tok);
	$d['list'][(int) $uid]['tok_exp'] = time() + (int) $hours * HOUR_IN_SECONDS;
	cp_pu_save($d);
	return cp_pu_panel_url('jelszo=' . (int) $uid . '.' . $tok);
}

function cp_pu_check_token($raw) {
	if (!preg_match('/^(\d+)\.([A-Za-z0-9]{40})$/', (string) $raw, $m)) { return 0; }
	$u = cp_pu_get((int) $m[1]);
	if (!$u || empty($u['a']) || empty($u['tok']) || empty($u['tok_exp']) || (int) $u['tok_exp'] < time()) { return 0; }
	return hash_equals($u['tok'], hash('sha256', $m[2])) ? (int) $m[1] : 0;
}

function cp_pu_mail($to, $subject, $html) {
	$h = array('Content-Type: text/html; charset=UTF-8');
	if (function_exists('cp_portal_opts')) {
		$po = cp_portal_opts();
		$from = sanitize_email((string) (isset($po['mail_from']) ? $po['mail_from'] : ''));
		if ($from !== '') { $h[] = 'From: Crazy panel <' . $from . '>'; }
	}
	$err = null;
	$cb = function ($e) use (&$err) { $err = $e; };
	add_action('wp_mail_failed', $cb);
	$ok = wp_mail($to, $subject, $html, $h);
	remove_action('wp_mail_failed', $cb);
	return $ok ? true : new WP_Error('mail', 'A levél nem ment ki' . ($err instanceof WP_Error ? ': ' . $err->get_error_message() : '.'));
}

/* $kind: invite | reset */
function cp_pu_send_link($uid, $kind) {
	$u = cp_pu_get($uid);
	if (!$u || empty($u['a'])) { return new WP_Error('user', 'Nincs ilyen aktív felhasználó.'); }
	$hours = $kind === 'invite' ? 7 * 24 : 2;
	$url = cp_pu_make_link($uid, $hours);
	$first = trim(preg_replace('/^.*\s/u', '', $u['n'])) ?: $u['n'];
	$btn = '<p style="margin:24px 0"><a href="' . esc_url($url) . '" style="background:#E8894A;color:#1A1F2B;padding:12px 22px;border-radius:999px;text-decoration:none;font-weight:700">Jelszó beállítása</a></p>';
	$body = $kind === 'invite'
		? '<p>Szia ' . esc_html($first) . '!</p><p>Fiókot kaptál a Crazy panelhez (' . esc_html(cp_pu_roles()[$u['r']] ?? $u['r']) . '). A belépéshez állítsd be a saját jelszavadat:</p>' . $btn . '<p style="color:#5B6275;font-size:13px">A link 7 napig érvényes, és egyszer használható. Belépéskor az e-mail-címed: ' . esc_html($u['e']) . '</p>'
		: '<p>Szia ' . esc_html($first) . '!</p><p>Új jelszót kértél a Crazy panelhez. Az alábbi linken beállíthatod:</p>' . $btn . '<p style="color:#5B6275;font-size:13px">A link 2 óráig érvényes, és egyszer használható. Ha nem te kérted, nyugodtan hagyd figyelmen kívül – a jelszavad nem változik.</p>';
	$r = cp_pu_mail($u['e'], $kind === 'invite' ? 'Meghívó a Crazy panelbe' : 'Crazy panel – új jelszó', '<div style="font:15px/1.55 Arial,sans-serif;color:#1A1F2B;max-width:520px">' . $body . '</div>');
	return is_wp_error($r) ? $r : $url;
}

/* ------------------------------------------------------------ oldalak a panel címén: ?jelszo=… és ?elfelejtett */

function cp_pu_page_open($title) {
	cp_panel_head($title);
	echo '<body class="cpx-login"><form method="post" class="cpx-login-box" autocomplete="off"><div class="cpx-logo">Crazy<span>panel</span></div>';
}

function cp_pu_render_setpw() {
	$raw = (string) wp_unslash($_GET['jelszo']);
	$uid = cp_pu_check_token($raw);
	$err = '';
	if ($uid && isset($_POST['cp_pw1'])) {
		$a = (string) wp_unslash($_POST['cp_pw1']); $b = isset($_POST['cp_pw2']) ? (string) wp_unslash($_POST['cp_pw2']) : '';
		if (strlen($a) < 10) { $err = 'A jelszó legalább 10 karakter legyen.'; }
		elseif ($a !== $b) { $err = 'A két jelszó nem egyezik.'; }
		else {
			$d = cp_pu_data();
			$d['list'][$uid]['h'] = wp_hash_password($a);
			$d['list'][$uid]['v'] = (int) $d['list'][$uid]['v'] + 1;
			unset($d['list'][$uid]['tok'], $d['list'][$uid]['tok_exp']);
			cp_pu_save($d);
			cp_pu_set_cookie($uid);
			wp_safe_redirect(cp_pu_panel_url());
			exit;
		}
	}
	cp_pu_page_open('Crazy panel – jelszó beállítása');
	if (!$uid) {
		echo '<div class="cpx-err">Ez a link már nem érvényes (lejárt vagy már felhasználták).</div><p class="cpx-muted"><a href="' . esc_url(cp_pu_panel_url('elfelejtett=1')) . '" style="color:#F2B35B">Kérj új linket</a></p>';
	} else {
		$u = cp_pu_get($uid);
		echo '<p class="cpx-muted">Szia ' . esc_html($u['n']) . '! Állítsd be a jelszavadat (legalább 10 karakter). Belépéskor az e-mail-címed: <b style="color:#fff">' . esc_html($u['e']) . '</b></p>';
		if ($err !== '') { echo '<div class="cpx-err">' . esc_html($err) . '</div>'; }
		echo '<input type="password" name="cp_pw1" placeholder="Új jelszó" required autofocus autocomplete="new-password"><input type="password" name="cp_pw2" placeholder="Új jelszó még egyszer" required autocomplete="new-password"><button type="submit">Jelszó mentése és belépés</button>';
	}
	echo '</form></body></html>';
}

function cp_pu_render_forgot() {
	$msg = '';
	if (isset($_POST['cp_email'])) {
		$key = 'cp_pu_forgot_' . md5(cp_panel_client_ip());
		$n = (int) get_transient($key);
		if ($n < 5) {
			set_transient($key, $n + 1, HOUR_IN_SECONDS);
			$uid = cp_pu_by_email(sanitize_email(wp_unslash($_POST['cp_email'])));
			if ($uid) { cp_pu_send_link($uid, 'reset'); }
		}
		/* mindig ugyanaz a válasz: ne lehessen kipróbálni, kinek van fiókja */
		$msg = 'Ha ezzel a címmel van fiók, pár percen belül megérkezik a levél a jelszó beállításához.';
	}
	cp_pu_page_open('Crazy panel – elfelejtett jelszó');
	echo '<p class="cpx-muted">Add meg az e-mail-címedet, és küldünk egy linket az új jelszó beállításához.</p>';
	if ($msg !== '') { echo '<div class="cpx-err" style="background:#E3F4EB;color:#2E9E6A">' . esc_html($msg) . '</div>'; }
	echo '<input type="email" name="cp_email" placeholder="E-mail-cím" required autofocus><button type="submit">Link küldése</button><p class="cpx-muted"><a href="' . esc_url(cp_pu_panel_url()) . '" style="color:#F2B35B">Vissza a belépéshez</a></p></form></body></html>';
}

/* ------------------------------------------------------------ admin: Beállítások › Panel-felhasználók */

add_action('admin_menu', function () {
	add_options_page('Panel-felhasználók', 'Panel-felhasználók', 'manage_options', 'cp-panel-users', 'cp_pu_admin_page');
});

function cp_pu_admin_page() {
	if (!current_user_can('manage_options')) { return; }
	$msg = ''; $link = '';
	if (isset($_POST['cp_pu_do'])) {
		check_admin_referer('cp_pu_do');
		$do = sanitize_key(wp_unslash($_POST['cp_pu_do']));
		$d = cp_pu_data();
		$uid = isset($_POST['uid']) ? (int) $_POST['uid'] : 0;
		if ($do === 'add') {
			$n = sanitize_text_field(wp_unslash($_POST['n'] ?? ''));
			$e = sanitize_email(wp_unslash($_POST['e'] ?? ''));
			$r = sanitize_key(wp_unslash($_POST['r'] ?? 'iroda'));
			$sm = (int) ($_POST['sm'] ?? 0);
			if ($n === '' || !is_email($e)) { $msg = 'Add meg a nevet és egy érvényes e-mail-címet.'; }
			elseif (cp_pu_by_email($e)) { $msg = 'Ezzel az e-mail-címmel már van felhasználó.'; }
			else {
				$id = (int) $d['next'];
				$d['next'] = $id + 1;
				$d['list'][$id] = array('n' => $n, 'e' => $e, 'r' => isset(cp_pu_roles()[$r]) ? $r : 'iroda', 'sm' => $sm, 'h' => '', 'v' => 1, 'a' => 1, 'last' => 0, 'at' => time());
				cp_pu_save($d);
				$res = !empty($_POST['send']) ? cp_pu_send_link($id, 'invite') : cp_pu_make_link($id, 7 * 24);
				if (is_wp_error($res)) { $msg = 'A felhasználó létrejött, de a meghívó levél nem ment ki (' . $res->get_error_message() . '). Az alábbi linket küldd el neki máshogy:'; $link = cp_pu_make_link($id, 7 * 24); }
				else { $msg = !empty($_POST['send']) ? 'A felhasználó létrejött, a meghívó levél elment ide: ' . $e . '. Ha nem érkezne meg, ezt a linket is elküldheted neki:' : 'A felhasználó létrejött. Ezzel a linkkel állíthatja be a jelszavát (7 napig érvényes):'; $link = $res; }
			}
		} elseif ($uid && isset($d['list'][$uid])) {
			$u = $d['list'][$uid];
			if ($do === 'save') {
				$r = sanitize_key(wp_unslash($_POST['r'] ?? $u['r']));
				$d['list'][$uid]['r'] = isset(cp_pu_roles()[$r]) ? $r : $u['r'];
				$d['list'][$uid]['n'] = sanitize_text_field(wp_unslash($_POST['n'] ?? $u['n'])) ?: $u['n'];
				$d['list'][$uid]['sm'] = (int) ($_POST['sm'] ?? $u['sm']);
				cp_pu_save($d); $msg = 'Mentve: ' . $d['list'][$uid]['n'] . '.';
			} elseif ($do === 'off' || $do === 'on') {
				$d['list'][$uid]['a'] = $do === 'on' ? 1 : 0;
				$d['list'][$uid]['v'] = (int) $u['v'] + 1;  // kilépteti
				cp_pu_save($d); $msg = $u['n'] . ($do === 'on' ? ' újra aktív.' : ' letiltva (azonnal kiléptettük).');
			} elseif ($do === 'invite') {
				$res = cp_pu_send_link($uid, $u['h'] === '' ? 'invite' : 'reset');
				if (is_wp_error($res)) { $msg = 'A levél nem ment ki (' . $res->get_error_message() . '). Ezt a linket küldd el neki máshogy:'; $link = cp_pu_make_link($uid, 48); }
				else { $msg = 'Elküldtem a linket ide: ' . $u['e'] . '. Ha nem érkezne meg, ezt is elküldheted:'; $link = $res; }
			} elseif ($do === 'del') {
				unset($d['list'][$uid]); cp_pu_save($d); $msg = $u['n'] . ' törölve.';
			}
		} elseif ($do === 'vis') {
			if (!empty($_POST['vis_reset'])) { delete_option('cp_panel_vis'); $msg = 'A láthatóság mindenkinél az alapértelmezés szerinti.'; }
			else {
				$in = isset($_POST['vis']) && is_array($_POST['vis']) ? wp_unslash($_POST['vis']) : array();
				$v = array();
				foreach ($d['list'] as $id => $u) {
					if (empty($u['a'])) { continue; }
					foreach (array_keys(cp_pu_tabs()) as $k) {
						$on = !empty($in[$id][$k]);
						if ($on !== cp_pu_tab_default($k, $u['r'])) { $v[(int) $id][$k] = $on ? 1 : 0; }
					}
				}
				update_option('cp_panel_vis', $v, false);
				$msg = 'A láthatóság elmentve (a változás a következő oldalbetöltéskor látszik).';
			}
		} elseif ($do === 'notif' && function_exists('cp_nt_admin_save')) {
			$msg = cp_nt_admin_save() ?: 'Nem változott semmi.';
		} elseif ($do === 'shared') {
			update_option('cp_panel_shared_off', !empty($_POST['off']) ? '1' : '0', false);
			$msg = !empty($_POST['off']) ? 'A közös jelszavak kikapcsolva – mostantól csak személyes fiókkal lehet belépni.' : 'A közös jelszavak újra működnek.';
		}
	}
	$d = cp_pu_data();
	$sm = function_exists('cp_crm_users') ? cp_crm_users() : array();
	$smSel = function ($cur, $f = '') use ($sm) { $h = '<select name="sm"' . ($f ? ' form="' . $f . '"' : '') . '><option value="0">–</option>'; foreach ($sm as $id => $x) { $h .= '<option value="' . (int) $id . '"' . selected((int) $cur, (int) $id, false) . '>' . esc_html($x['n'] . ($x['a'] ? '' : ' (inaktív)')) . '</option>'; } return $h . '</select>'; };
	$roleSel = function ($cur, $f = '') { $h = '<select name="r"' . ($f ? ' form="' . $f . '"' : '') . '>'; foreach (cp_pu_roles() as $k => $l) { $h .= '<option value="' . esc_attr($k) . '"' . selected($cur, $k, false) . '>' . esc_html($l) . '</option>'; } return $h . '</select>'; };
	echo '<div class="wrap"><h1>Crazy panel – felhasználók</h1>';
	echo '<p>Mindenki saját e-mail-címmel és jelszóval lép be a panelbe (' . esc_html(cp_pu_panel_url()) . '). Az <b>admin</b> mindent lát, az <b>iroda</b> a Statisztikák fület nem. A változásnaplóban a felhasználó neve szerepel. A „Salesmate-felhasználó” összekötés a későbbi levelezéshez és a „saját deal-jeim” nézethez kell.</p>';
	if ($msg !== '') { echo '<div class="notice notice-info"><p>' . esc_html($msg) . ($link !== '' ? '<br><input type="text" readonly value="' . esc_attr($link) . '" style="width:100%;max-width:820px" onclick="this.select()">' : '') . '</p></div>'; }
	echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Név</th><th>E-mail</th><th>Szerepkör</th><th>Salesmate-felhasználó</th><th>Állapot</th><th>Utolsó belépés</th><th></th></tr></thead><tbody>';
	if (!$d['list']) { echo '<tr><td colspan="7">Még nincs felhasználó.</td></tr>'; }
	$forms = '';
	foreach ($d['list'] as $id => $u) {
		$f = 'cpu' . (int) $id;
		$forms .= '<form method="post" id="' . $f . '">' . wp_nonce_field('cp_pu_do', '_wpnonce', true, false) . '<input type="hidden" name="uid" value="' . (int) $id . '"></form>';
		$st = empty($u['a']) ? '<span style="color:#b32d2e">letiltva</span>' : ((string) $u['h'] === '' ? 'meghívva, még nincs jelszava' : 'aktív');
		$b = function ($do, $label, $ask = '') use ($f) { return '<button class="button" form="' . $f . '" name="cp_pu_do" value="' . $do . '"' . ($ask ? ' onclick="return confirm(\'' . $ask . '\')"' : '') . '>' . $label . '</button> '; };
		echo '<tr><td><input type="text" name="n" form="' . $f . '" value="' . esc_attr($u['n']) . '"></td><td>' . esc_html($u['e']) . '</td><td>' . $roleSel($u['r'], $f) . '</td><td>' . $smSel($u['sm'], $f) . '</td><td>' . $st . '</td><td>' . ($u['last'] ? esc_html(wp_date('Y.m.d. H:i', (int) $u['last'])) : '–') . '</td>';
		echo '<td style="white-space:nowrap">' . $b('save', 'Mentés') . $b('invite', (string) $u['h'] === '' ? 'Meghívó újra' : 'Jelszó-link küldése');
		echo empty($u['a']) ? $b('on', 'Engedélyezés') . $b('del', 'Törlés', 'Végleg törlöd?') : $b('off', 'Letiltás', 'Letiltod és kiléptetd?');
		echo '</td></tr>';
	}
	echo '</tbody></table>' . $forms;
	echo '<h2>Új felhasználó</h2><form method="post">' . wp_nonce_field('cp_pu_do', '_wpnonce', true, false) . '<input type="hidden" name="cp_pu_do" value="add"><table class="form-table" style="max-width:820px">';
	echo '<tr><th>Név</th><td><input type="text" name="n" class="regular-text" required></td></tr><tr><th>E-mail</th><td><input type="email" name="e" class="regular-text" required></td></tr>';
	echo '<tr><th>Szerepkör</th><td>' . $roleSel('iroda') . '</td></tr><tr><th>Salesmate-felhasználó</th><td>' . $smSel(0) . '</td></tr>';
	echo '<tr><th></th><td><label><input type="checkbox" name="send" value="1" checked> Meghívó levél küldése (a jelszó beállításához)</label></td></tr></table><p><button class="button button-primary">Felhasználó létrehozása</button></p></form>';
	$off = !cp_pu_shared_on();
	echo '<h2>Közös jelszavak (átmenet)</h2><form method="post">' . wp_nonce_field('cp_pu_do', '_wpnonce', true, false) . '<input type="hidden" name="cp_pu_do" value="shared"><p>A régi közös admin- és irodai jelszó ' . ($off ? '<b>ki van kapcsolva</b>.' : '<b>még működik</b> (a belépésnél üresen hagyott e-mail-címmel). Ha mindenki beállította a saját jelszavát, kapcsold ki.') . '</p><label><input type="checkbox" name="off" value="1"' . checked($off, true, false) . '> Közös jelszavak kikapcsolása (csak személyes fiókkal lehessen belépni)</label><p><button class="button">Mentés</button></p></form>';
	cp_pu_vis_section($d);
	if (function_exists('cp_nt_admin_section')) { cp_nt_admin_section(); }
	echo '</div>';
}
