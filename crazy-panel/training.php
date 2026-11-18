<?php
/**
 * v2.9.330: Betanulás-foglaló – a már alkalmasnak talált jelentkezőknek (a linket kézzel küldjük ki).
 * Nyilvános oldal: https://eventcrazy.hu/betanulas/ – csoportos betanulás, napokra (nincs idősáv), naponta legfeljebb N fő.
 * Név + e-mail; visszaigazoló levél naptárlinkkel és lemondó linkkel. Panel: Kezelők → Betanulás (#kezelok:betanulas).
 * Az interjú-foglaló (interview.php) segédeit használja (űrlap-jel, IP-lenyomat, levélküldés, stílus).
 */
if (!defined('ABSPATH')) { exit; }

function cp_tr_t() { global $wpdb; return $wpdb->prefix . 'crazy_tr_bookings'; }
function cp_tr_db() {
	if (get_option('cp_tr_db') === '1') { return true; }
	if (wp_doing_ajax()) { return false; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_tr_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  day date NOT NULL,
  tm varchar(5) NOT NULL DEFAULT '',
  name varchar(120) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  token varchar(32) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'ok',
  cancel_by varchar(100) NOT NULL DEFAULT '',
  ip varchar(16) NOT NULL DEFAULT '',
  created_at datetime NULL,
  changed_at datetime NULL,
  PRIMARY KEY  (id),
  KEY day (day),
  KEY email (email),
  KEY token (token)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_tr_db', '1', false);
	return true;
}
add_action('init', function () { if (!wp_doing_ajax() && get_option('cp_tr_db') !== '1') { cp_tr_db(); } }, 20);
function cp_tr_ok() { return get_option('cp_tr_db') === '1' && function_exists('cp_iv_send'); }

function cp_tr_defaults() {
	return array(
		'on' => 1, 'title' => 'Betanulás – EventCrazy', 'location' => 'XI. ker., Barázda u. 42. – Barázda Center', 'dur' => 120,
		'note' => 'Csoportos betanulás lesz, ezért kérjük, érkezz pontosan.',
		'days' => array(array('d' => '2026-11-20', 'tm' => '10:00', 'cap' => 4), array('d' => '2026-11-25', 'tm' => '10:00', 'cap' => 4)),
		'close_min' => 120, 'cancel_h' => 12,
	);
}
function cp_tr_opts() { $o = get_option('cp_tr', array()); return wp_parse_args(is_array($o) ? $o : array(), cp_tr_defaults()); }
function cp_tr_url($a = array()) { return add_query_arg($a, home_url('/betanulas/')); }
function cp_tr_day($d) { foreach ((array) cp_tr_opts()['days'] as $x) { if (($x['d'] ?? '') === $d) { return $x; } } return null; }
function cp_tr_count($d) { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_tr_t() . " WHERE day = %s AND status = 'ok'", $d)); }
function cp_tr_label($d, $tm, $long = true) { return cp_iv_hu_day($d, $long) . ', ' . $tm; }
function cp_tr_by_token($t) {
	global $wpdb;
	if (!preg_match('/^[a-f0-9]{32}$/', (string) $t)) { return null; }
	return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_tr_t() . ' WHERE token = %s', $t), ARRAY_A);
}
/* a választható napok (a lezárás a kezdés előtt close_min perccel) */
function cp_tr_days_view() {
	$o = cp_tr_opts(); $lim = time() + 60 * max(0, (int) $o['close_min']); $out = array();
	foreach ((array) $o['days'] as $x) {
		if (cp_iv_ts($x['d'], $x['tm']) < $lim) { continue; }
		$n = cp_tr_count($x['d']); $out[] = array('d' => $x['d'], 'tm' => $x['tm'], 'cap' => (int) $x['cap'], 'n' => $n, 'free' => max(0, (int) $x['cap'] - $n));
	}
	usort($out, function ($a, $b) { return strcmp($a['d'] . $a['tm'], $b['d'] . $b['tm']); });
	return $out;
}

function cp_tr_book($day, $name, $email) {
	global $wpdb; $T = cp_tr_t(); $o = cp_tr_opts();
	$x = cp_tr_day($day);
	if (!$x) { return new WP_Error('tr', 'Ez a nap nem választható. Válassz másikat.'); }
	if (cp_iv_ts($x['d'], $x['tm']) < time() + 60 * (int) $o['close_min']) { return new WP_Error('tr', 'Erre a napra már lezárult a jelentkezés. Válassz egy későbbit.'); }
	$dup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE email = %s AND status = 'ok' AND day >= %s LIMIT 1", $email, wp_date('Y-m-d')), ARRAY_A);
	$ip = cp_iv_ip();
	if ($dup) {
		if ($dup['day'] === $day && hash_equals((string) $dup['ip'], $ip) && strtotime($dup['created_at'] . ' UTC') >= time() - 15 * MINUTE_IN_SECONDS) { return $dup; }
		if (!get_transient('cp_tr_resend_' . (int) $dup['id'])) { set_transient('cp_tr_resend_' . (int) $dup['id'], 1, 10 * MINUTE_IN_SECONDS); cp_tr_mail($dup, 'new'); }
		return new WP_Error('tr', 'Ezzel az e-mail-címmel már jelentkeztél egy betanulásra. A visszaigazoló levelet újra elküldtük – abban találod a lemondás linkjét (ha nem érkezik meg, nézd meg a levélszemét mappát is).');
	}
	if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T WHERE ip = %s AND created_at >= %s", $ip, gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS))) >= 5) { return new WP_Error('tr', 'Túl sok jelentkezés erről az eszközről. Próbáld újra később, vagy hívj minket.'); }
	/* a létszámkorlát zárral – két egyszerre érkező jelentkezés sem lépheti túl */
	$wpdb->get_var("SELECT GET_LOCK('cp_tr_book', 10)");
	$n = cp_tr_count($day);
	if ($n >= (int) $x['cap']) { $wpdb->get_var("SELECT RELEASE_LOCK('cp_tr_book')"); return new WP_Error('tr_full', 'Ez a nap közben betelt. Válassz másikat.'); }
	$now = gmdate('Y-m-d H:i:s'); $tok = bin2hex(random_bytes(16));
	$wpdb->insert($T, array('day' => $day, 'tm' => $x['tm'], 'name' => $name, 'email' => $email, 'token' => $tok, 'status' => 'ok', 'ip' => $ip, 'created_at' => $now, 'changed_at' => $now));
	$id = (int) $wpdb->insert_id;
	$wpdb->get_var("SELECT RELEASE_LOCK('cp_tr_book')");
	if (!$id) { return new WP_Error('tr', 'Hiba történt. Kérjük, próbáld újra.'); }
	$b = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE id = %d", $id), ARRAY_A);
	cp_tr_mail($b, 'new');
	cp_tr_notify('Betanulás-jelentkezés: ' . $name . ' – ' . cp_tr_label($day, $x['tm'], false) . ' (' . ($n + 1) . '/' . (int) $x['cap'] . ' fő)');
	return $b;
}
function cp_tr_cancel($b, $by) {
	global $wpdb;
	$wpdb->update(cp_tr_t(), array('status' => 'cancel', 'cancel_by' => mb_substr($by, 0, 100), 'changed_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $b['id']));
	cp_tr_notify('Betanulás lemondva: ' . $b['name'] . ' – ' . cp_tr_label($b['day'], $b['tm'], false) . ($by === 'jelentkezo' ? ' (a jelentkező mondta le)' : ''));
}
function cp_tr_notify($text) {
	if (!function_exists('cp_notify') || !function_exists('cp_pu_data')) { return; }
	$to = function_exists('cp_iv_opts') ? array_map('intval', (array) cp_iv_opts()['notify']) : array();
	foreach (cp_pu_data()['list'] as $uid => $u) {
		if (empty($u['a'])) { continue; }
		if ($to ? in_array((int) $uid, $to, true) : (($u['r'] ?? '') === 'admin')) { cp_notify((int) $uid, 'iv_book', $text, 'kezelok:betanulas', 'Betanulás-foglaló'); }
	}
}

/* ------------------------------------------------------------ levél, naptár */
function cp_tr_span($b) { $st = cp_iv_ts($b['day'], $b['tm']); return array($st, $st + 60 * max(30, (int) cp_tr_opts()['dur'])); }
function cp_tr_gcal($b) {
	$o = cp_tr_opts(); list($st, $en) = cp_tr_span($b);
	return 'https://calendar.google.com/calendar/render?' . http_build_query(array('action' => 'TEMPLATE', 'text' => $o['title'], 'dates' => gmdate('Ymd\THis\Z', $st) . '/' . gmdate('Ymd\THis\Z', $en), 'location' => $o['location'], 'details' => 'Lemondás: ' . cp_tr_url(array('t' => $b['token']))));
}
function cp_tr_ics($b) {
	$o = cp_tr_opts(); list($st, $en) = cp_tr_span($b);
	$esc = function ($x) { return str_replace(array('\\', ';', ',', "\r\n", "\n"), array('\\\\', '\;', '\,', '\n', '\n'), (string) $x); };
	return implode("\r\n", array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//EventCrazy//Betanulas//HU', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
		'UID:tr-' . (int) $b['id'] . '-' . substr($b['token'], 0, 8) . '@eventcrazy.hu', 'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'DTSTART:' . gmdate('Ymd\THis\Z', $st), 'DTEND:' . gmdate('Ymd\THis\Z', $en),
		'SUMMARY:' . $esc($o['title']), 'LOCATION:' . $esc($o['location']), 'DESCRIPTION:' . $esc('Lemondás: ' . cp_tr_url(array('t' => $b['token']))), 'END:VEVENT', 'END:VCALENDAR')) . "\r\n";
}
function cp_tr_mail($b, $kind) {
	if (!is_email($b['email'])) { return false; }
	$o = cp_tr_opts();
	$btn = function ($href, $label, $primary) { return '<a href="' . esc_url($href) . '" style="display:inline-block;margin:0 8px 8px 0;padding:11px 18px;border-radius:999px;text-decoration:none;font-weight:bold;font-size:14px;' . ($primary ? 'background:#E8894A;color:#141C33' : 'background:#F1EDE7;color:#141C33') . '">' . esc_html($label) . '</a>'; };
	$h = '<p style="margin:0 0 14px">Kedves ' . esc_html($b['name']) . '!</p><p style="margin:0 0 14px">Köszönjük a jelentkezésed! Várunk a csoportos betanulásra:</p>'
		. '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#FAF8F4;border-radius:12px;margin:0 0 18px"><tr><td style="padding:14px 16px;font-size:15px;line-height:1.6">'
		. '<b style="font-size:17px">' . esc_html(cp_tr_label($b['day'], $b['tm'])) . '</b>' . ($o['location'] !== '' ? '<br>Helyszín: ' . nl2br(esc_html($o['location'])) : '') . '</td></tr></table>';
	if (trim((string) $o['note']) !== '') { $h .= '<p style="margin:0 0 16px">' . nl2br(esc_html($o['note'])) . '</p>'; }
	$h .= '<p style="margin:0 0 6px">' . $btn(cp_tr_gcal($b), 'Hozzáadás a Google Naptárhoz', true) . $btn(cp_tr_url(array('t' => $b['token'], 'ics' => 1)), 'Naptárfájl (.ics)', false) . '</p>';
	$h .= '<p style="margin:14px 0 6px">Ha mégsem érsz rá, kérjük, mondd le (legkésőbb ' . (int) $o['cancel_h'] . ' órával előtte), hogy más jöhessen a helyedre:</p><p style="margin:0">' . $btn(cp_tr_url(array('t' => $b['token'])), 'Lemondás', false) . '</p>';
	$h .= '<p style="margin:16px 0 0">Várunk szeretettel!<br>Az EventCrazy csapata</p>';
	$html = str_replace('mert időpontot foglaltál az EventCrazy kezelői interjújára', 'mert jelentkeztél az EventCrazy betanulására', cp_iv_mail_html('Várunk a betanuláson!', $h));
	return cp_iv_send($b['email'], 'Betanulás visszaigazolás – EventCrazy, ' . cp_iv_hu_day($b['day'], false) . ' ' . $b['tm'], $html);
}

/* ------------------------------------------------------------ nyilvános oldal: /betanulas/ */
add_action('init', function () {
	$p = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
	if ($p !== '/betanulas') { return; }
	if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
	nocache_headers();
	header('X-Robots-Tag: noindex, nofollow');
	cp_tr_db();
	if (!cp_tr_ok()) { status_header(503); echo 'Az oldal hamarosan elérhető.'; exit; }
	$t = isset($_GET['t']) ? (string) wp_unslash($_GET['t']) : (string) wp_unslash($_POST['t'] ?? '');
	$b = $t !== '' ? cp_tr_by_token($t) : null;
	if ($b && !empty($_GET['ics'])) { header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: attachment; filename="eventcrazy-betanulas.ics"'); echo cp_tr_ics($b); exit; }
	$o = cp_tr_opts(); $err = ''; $val = array('name' => '', 'email' => '', 'day' => '');
	if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
		$act = (string) ($_POST['tr_act'] ?? '');
		if (!cp_iv_form_ok($_POST['iv_t'] ?? 0, $_POST['iv_s'] ?? '') || !empty($_POST['website'])) { $err = 'A lap túl régóta volt nyitva, vagy hiba történt. Kérjük, töltsd be újra az oldalt.'; }
		elseif ($act === 'book') {
			$val = array('name' => mb_substr(trim(sanitize_text_field(wp_unslash($_POST['name'] ?? ''))), 0, 120), 'email' => strtolower(sanitize_email(wp_unslash($_POST['email'] ?? ''))), 'day' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['day'] ?? '')) ? (string) $_POST['day'] : '');
			if (empty($o['on'])) { $err = 'A jelentkezés jelenleg nincs nyitva.'; }
			elseif (!$val['day']) { $err = 'Válassz egy napot.'; }
			elseif (mb_strlen($val['name']) < 3) { $err = 'Add meg a teljes neved.'; }
			elseif (!is_email($val['email'])) { $err = 'Adj meg egy érvényes e-mail-címet.'; }
			elseif (empty($_POST['consent'])) { $err = 'A jelentkezéshez el kell fogadnod az adatkezelési tájékoztatót.'; }
			else {
				$r = cp_tr_book($val['day'], $val['name'], $val['email']);
				if (is_wp_error($r)) { $err = $r->get_error_message(); if ($r->get_error_code() === 'tr_full') { $val['day'] = ''; } }
				else { wp_safe_redirect(cp_tr_url(array('t' => $r['token'], 'kesz' => 1))); exit; }
			}
		} elseif ($b && $act === 'cancel' && $b['status'] === 'ok') {
			if (cp_iv_ts($b['day'], $b['tm']) < time() + 3600 * (int) $o['cancel_h']) { $err = 'A betanulás előtti ' . (int) $o['cancel_h'] . ' órában már nem lehet lemondani. Kérjük, hívj minket.'; }
			else { cp_tr_cancel($b, 'jelentkezo'); wp_safe_redirect(cp_tr_url(array('t' => $b['token'], 'lemondva' => 1))); exit; }
		}
		if ($b) { $b = cp_tr_by_token($b['token']); }
	}
	cp_tr_render($o, $b, $val, $err);
	exit;
}, 9);

function cp_tr_render($o, $b, $val, $err) {
	$ft = time();
	$hidden = '<input type="hidden" name="iv_t" value="' . $ft . '"><input type="hidden" name="iv_s" value="' . esc_attr(cp_iv_form_sig($ft)) . '"><div class="hp" aria-hidden="true"><label>Weboldal <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
	$where = $o['location'] !== '' ? '<p class="loc"><b>Helyszín:</b> ' . nl2br(esc_html($o['location'])) . '</p>' : '';
	echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html($o['title']) . '</title>';
	echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">';
	echo '<style>' . cp_iv_css() . '.tr-days{display:grid;gap:10px;margin:0 0 8px}.tr-day{display:flex;align-items:center;gap:14px;padding:14px 16px;border:1.5px solid var(--li);border-radius:14px;background:#fff;cursor:pointer}.tr-day input{width:20px;height:20px;flex-shrink:0;accent-color:var(--o);margin:0}.tr-day .dn{font-weight:600;flex:1;min-width:0}.tr-day .cnt{flex-shrink:0;font-size:14px;color:var(--mu);white-space:nowrap}.tr-day:has(input:checked){border-color:var(--o);background:#FFF8F1}.tr-day.full{opacity:.55;cursor:not-allowed}.tr-day.full .cnt{color:var(--bad);font-weight:600}</style></head><body><header class="top"><div class="wrap"><span class="brand">EventCrazy</span></div></header><main class="wrap">';
	if ($b) {
		if (!empty($_GET['kesz'])) { echo '<div class="ok" role="status"><b>Kész, jelentkezésed rögzítettük!</b> A visszaigazolást elküldtük a(z) ' . esc_html($b['email']) . ' címre.</div>'; }
		if ($err !== '') { echo '<div class="err" role="alert">' . esc_html($err) . '</div>'; }
		if ($b['status'] !== 'ok') {
			echo '<h1>A jelentkezésed le van mondva</h1><p class="lead">' . (!empty($_GET['lemondva']) ? 'Köszönjük, hogy jelezted. ' : '') . 'Ha mégis jönnél, jelentkezz újra egy másik napra.</p><p><a class="btn" href="' . esc_url(cp_tr_url()) . '">Jelentkezés</a></p>';
		} else {
			echo '<h1>Várunk a betanuláson</h1><div class="card when"><span class="big">' . esc_html(cp_tr_label($b['day'], $b['tm'])) . '</span><span>Csoportos betanulás</span>' . $where . '</div>';
			if (trim((string) $o['note']) !== '') { echo '<div class="punct" role="note">' . nl2br(esc_html($o['note'])) . '</div>'; }
			echo '<p class="cal"><a class="btn" href="' . esc_url(cp_tr_gcal($b)) . '" target="_blank" rel="noopener">Hozzáadás a Google Naptárhoz</a> <a class="btn ghost" href="' . esc_url(cp_tr_url(array('t' => $b['token'], 'ics' => 1))) . '">Naptárfájl (.ics)</a></p>';
			if (cp_iv_ts($b['day'], $b['tm']) < time() + 3600 * (int) $o['cancel_h']) { echo '<p class="muted">A betanulás előtti ' . (int) $o['cancel_h'] . ' órában már nem lehet lemondani. Ha közbejött valami, kérjük, hívj minket.</p>'; }
			elseif (!empty($_GET['lemond'])) { echo '<div class="card confirm"><p><b>Biztosan lemondod?</b><br>' . esc_html(cp_tr_label($b['day'], $b['tm'])) . '</p><form method="post">' . $hidden . '<input type="hidden" name="t" value="' . esc_attr($b['token']) . '"><input type="hidden" name="tr_act" value="cancel"><button class="btn danger" type="submit">Igen, lemondom</button><a class="btn ghost" href="' . esc_url(cp_tr_url(array('t' => $b['token']))) . '">Mégsem</a></form></div>'; }
			else { echo '<div class="acts"><a class="btn ghost danger-t" href="' . esc_url(cp_tr_url(array('t' => $b['token'], 'lemond' => 1))) . '">Lemondás</a></div>'; }
		}
	} else {
		$days = cp_tr_days_view();
		echo '<h1>Jelentkezés betanulásra</h1><p class="lead">Csoportos betanulás – válaszd ki, melyik napon jössz, és add meg a neved és az e-mail-címed. A visszaigazolást e-mailben küldjük.</p>' . $where;
		if (trim((string) $o['note']) !== '') { echo '<div class="punct" role="note">' . nl2br(esc_html($o['note'])) . '</div>'; }
		if (empty($o['on'])) { echo '<div class="err" role="status">A jelentkezés jelenleg nincs nyitva.</div>'; }
		if ($err !== '') { echo '<div class="err" role="alert">' . esc_html($err) . '</div>'; }
		echo '<form method="post" class="f" novalidate>' . $hidden . '<input type="hidden" name="tr_act" value="book"><h2>1. Nap</h2>';
		if (!$days) { echo '<p class="empty">Jelenleg nincs választható nap.</p>'; }
		else {
			echo '<div class="tr-days">';
			foreach ($days as $x) {
				$full = $x['free'] <= 0;
				echo '<label class="tr-day' . ($full ? ' full' : '') . '"><input type="radio" name="day" value="' . esc_attr($x['d']) . '"' . ($full ? ' disabled' : '') . ($val['day'] === $x['d'] && !$full ? ' checked' : '') . ' required><span class="dn">' . esc_html(cp_tr_label($x['d'], $x['tm'])) . '</span><span class="cnt">' . ($full ? 'Betelt' : 'még ' . (int) $x['free'] . ' hely') . '</span></label>';
			}
			echo '</div>';
		}
		echo '<h2>2. Adataid</h2><div class="fields"><label>Teljes név<input type="text" name="name" autocomplete="name" maxlength="120" required value="' . esc_attr($val['name']) . '"></label>'
			. '<label>E-mail-cím<input type="email" name="email" autocomplete="email" maxlength="190" required value="' . esc_attr($val['email']) . '"></label></div>';
		echo '<label class="consent"><input type="checkbox" name="consent" value="1" required> <span>Hozzájárulok, hogy az EventCrazy a megadott adataimat a betanulás megszervezése céljából kezelje. Az adatokat harmadik félnek nem adjuk ki, és kérésre töröljük.</span></label>';
		echo '<button class="btn wide" type="submit"' . (empty($o['on']) || !$days ? ' disabled' : '') . '>Jelentkezem</button></form>';
	}
	echo '</main><footer class="wrap foot">EventCrazy · <a href="https://eventcrazy.hu/">eventcrazy.hu</a></footer></body></html>';
}

/* ------------------------------------------------------------ panel: Kezelők → Betanulás */
function cp_tr_admin_data() {
	global $wpdb; $o = cp_tr_opts(); $by = array();
	foreach ((array) $wpdb->get_results('SELECT * FROM ' . cp_tr_t() . ' ORDER BY day, created_at', ARRAY_A) as $r) {
		$by[$r['day']][] = array('id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'live' => $r['status'] === 'ok' ? 1 : 0,
			'at' => function_exists('cp_crm_local') ? cp_crm_local($r['created_at']) : $r['created_at'], 'cby' => $r['cancel_by'] === 'jelentkezo' ? 'a jelentkező' : (strpos($r['cancel_by'], 'panel:') === 0 ? trim(substr($r['cancel_by'], 6)) : ''));
	}
	$days = array(); $seen = array();
	foreach ((array) $o['days'] as $x) { $seen[$x['d']] = 1; $L = $by[$x['d']] ?? array(); $days[] = array('d' => $x['d'], 'tm' => $x['tm'], 'cap' => (int) $x['cap'], 'label' => cp_tr_label($x['d'], $x['tm']), 'past' => cp_iv_ts($x['d'], $x['tm']) < time() ? 1 : 0, 'list' => $L); }
	foreach ($by as $d => $L) { if (!isset($seen[$d])) { $days[] = array('d' => $d, 'tm' => $L ? '' : '', 'cap' => 0, 'label' => cp_iv_hu_day($d) . ' (már nincs a beállításban)', 'past' => 1, 'list' => $L, 'gone' => 1); } }
	usort($days, function ($a, $b) { return strcmp($a['d'], $b['d']); });
	return array('opts' => array('on' => (int) $o['on'], 'title' => $o['title'], 'location' => $o['location'], 'note' => $o['note'], 'days' => array_values((array) $o['days']), 'close_min' => (int) $o['close_min'], 'cancel_h' => (int) $o['cancel_h']),
		'days' => $days, 'url' => cp_tr_url(), 'admin' => function_exists('cp_panel_is_admin') && cp_panel_is_admin() ? 1 : 0);
}
add_action('wp_ajax_nopriv_cp_tr_admin', 'cp_tr_ajax_admin');
add_action('wp_ajax_cp_tr_admin', 'cp_tr_ajax_admin');
function cp_tr_ajax_admin() {
	if (!function_exists('cp_kz_auth')) { wp_send_json_error(array('msg' => 'Hiba')); }
	cp_kz_auth();
	if (!cp_tr_ok()) { cp_el_err('A betanulás-foglaló még települ – töltsd újra az oldalt egy perc múlva.', 503); }
	$do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
	if ($do !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$who = function_exists('cp_kz_who') ? cp_kz_who() : '';
	if ($do === 'cancel') {
		$b = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_tr_t() . ' WHERE id = %d', (int) ($_POST['id'] ?? 0)), ARRAY_A);
		if ($b && $b['status'] === 'ok') { cp_tr_cancel($b, 'panel:' . $who); if (function_exists('cp_audit_add')) { cp_audit_add('kezelok', 'training', 'Betanulás-jelentkezés lemondva', '', $b['name'] . ' – ' . $b['day']); } }
	} elseif ($do === 'save') {
		if (function_exists('cp_admin_need')) { cp_admin_need(); }
		$in = json_decode((string) wp_unslash($_POST['o'] ?? ''), true);
		if (!is_array($in)) { cp_el_err('Hibás kérés.'); }
		$o = cp_tr_opts();
		$o['on'] = empty($in['on']) ? 0 : 1;
		$o['title'] = mb_substr(sanitize_text_field((string) ($in['title'] ?? $o['title'])), 0, 120) ?: cp_tr_defaults()['title'];
		$o['location'] = mb_substr(sanitize_textarea_field((string) ($in['location'] ?? '')), 0, 400);
		$o['note'] = mb_substr(sanitize_textarea_field((string) ($in['note'] ?? '')), 0, 800);
		$days = array(); $u = array();
		foreach (array_slice((array) ($in['days'] ?? array()), 0, 20) as $d) {
			$dd = (string) ($d['d'] ?? ''); $tm = (string) ($d['tm'] ?? ''); $cap = max(1, min(50, (int) ($d['cap'] ?? 4)));
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd) && preg_match('/^\d{2}:\d{2}$/', $tm) && !isset($u[$dd])) { $u[$dd] = 1; $days[] = array('d' => $dd, 'tm' => $tm, 'cap' => $cap); }
		}
		usort($days, function ($a, $b) { return strcmp($a['d'], $b['d']); });
		$o['days'] = $days;
		update_option('cp_tr', $o, false);
		if (function_exists('cp_audit_add')) { cp_audit_add('kezelok', 'training', 'Betanulás-napok beállítása ' . ($o['on'] ? '(nyitva)' : '(zárva)'), '', count($days) . ' nap'); }
	}
	wp_send_json_success(cp_tr_admin_data());
}
