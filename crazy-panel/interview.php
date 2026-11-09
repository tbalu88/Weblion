<?php
/* =====================================================================
 * v2.9.57: Interjú-időpontfoglalás kezelő-jelentkezőknek
 * Nyilvános oldal: https://eventcrazy.hu/interju/ (a téma és az Elementor nélkül – gyors, mobilra tervezve, JavaScript nélkül is működik)
 *  – a szabad időpontok naponként; egy időpontra egy jelentkező (adatbázis-szintű egyediség: dupla foglalás kizárva)
 *  – bekért adat: név, telefon, e-mail, időpont, adatkezelési hozzájárulás
 *  – visszaigazoló e-mail naptárbejegyzéssel (.ics) és módosítás / lemondás linkkel; emlékeztető az előző napon
 *  – a foglaló bekerül a Kezelők → Jelentkezők közé; értesítés az adminoknak (csengő / telefon)
 * Panel: Kezelők fül → „Interjú-időpontok” (foglalások, letiltás, lemondás, beállítások, link, CSV).
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_IV_DB_VER', '1');
function cp_iv_t($n) { global $wpdb; return $wpdb->prefix . 'crazy_iv_' . $n; }

add_action('init', function () {
	if (get_option('cp_iv_db_ver') === CP_IV_DB_VER) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta('CREATE TABLE ' . cp_iv_t('slots') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  day date NOT NULL,
  tm char(5) NOT NULL DEFAULT '',
  dur smallint(5) unsigned NOT NULL DEFAULT 20,
  blocked tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY dt (day,tm)
) $c;");
	dbDelta('CREATE TABLE ' . cp_iv_t('bookings') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slot_id bigint(20) unsigned NULL,
  last_slot bigint(20) unsigned NULL,
  name varchar(120) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  token char(32) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'ok',
  ip varchar(16) NOT NULL DEFAULT '',
  kz_id bigint(20) unsigned NULL,
  created_at datetime NULL,
  changed_at datetime NULL,
  reminded_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slot (slot_id),
  KEY token (token),
  KEY email (email)
) $c;");
	update_option('cp_iv_db_ver', CP_IV_DB_VER, false);
	cp_iv_sync_slots();
}, 8);
function cp_iv_ok() { return get_option('cp_iv_db_ver') === CP_IV_DB_VER; }

/* ------------------------------------------------------------ beállítások */
function cp_iv_defaults() {
	return array(
		'on' => 0, 'title' => 'Kezelői interjú – EventCrazy', 'location' => '', 'note' => '', 'dur' => 20,
		'days' => array(array('d' => '2026-11-12', 'from' => '10:00', 'to' => '14:00'), array('d' => '2026-11-17', 'from' => '18:00', 'to' => '21:00')),
		'close_min' => 120, 'cancel_h' => 12, 'remind' => 1, 'notify' => array(),
	);
}
function cp_iv_opts() { $o = get_option('cp_iv', array()); return wp_parse_args(is_array($o) ? $o : array(), cp_iv_defaults()); }
function cp_iv_url($args = array()) { return add_query_arg($args, home_url('/interju/')); }

/* időpontok a beállított napokból (a foglalt időpontot nem törli, akkor sem, ha kikerült a beállításból) */
function cp_iv_sync_slots() {
	if (!cp_iv_ok()) { return; }
	global $wpdb;
	$o = cp_iv_opts(); $dur = max(5, min(120, (int) $o['dur'])); $want = array();
	foreach ((array) $o['days'] as $d) {
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['d'] ?? '')) || !preg_match('/^\d{2}:\d{2}$/', (string) ($d['from'] ?? '')) || !preg_match('/^\d{2}:\d{2}$/', (string) ($d['to'] ?? ''))) { continue; }
		$a = (int) substr($d['from'], 0, 2) * 60 + (int) substr($d['from'], 3); $b = (int) substr($d['to'], 0, 2) * 60 + (int) substr($d['to'], 3);
		for ($m = $a; $m + $dur <= $b; $m += $dur) { $want[$d['d'] . ' ' . sprintf('%02d:%02d', intdiv($m, 60), $m % 60)] = 1; }
	}
	$S = cp_iv_t('slots'); $B = cp_iv_t('bookings');
	foreach (array_keys($want) as $k) { $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $S (day, tm, dur) VALUES (%s, %s, %d)", substr($k, 0, 10), substr($k, 11, 5), $dur)); }
	$wpdb->query($wpdb->prepare("UPDATE $S SET dur = %d", $dur));
	foreach ((array) $wpdb->get_results("SELECT s.id, s.day, s.tm FROM $S s LEFT JOIN $B b ON b.slot_id = s.id WHERE b.id IS NULL", ARRAY_A) as $s) {
		if (!isset($want[$s['day'] . ' ' . $s['tm']])) { $wpdb->delete($S, array('id' => (int) $s['id'])); }
	}
}

/* ------------------------------------------------------------ segédek */
function cp_iv_ts($day, $tm) { $d = date_create($day . ' ' . $tm . ':00', wp_timezone()); return $d ? $d->getTimestamp() : 0; }
function cp_iv_hu_day($day, $long = true) {
	$t = cp_iv_ts($day, '12:00');
	$m = array('január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
	$w = array('vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat');
	$n = (int) wp_date('n', $t); $j = (int) wp_date('j', $t); $dow = $w[(int) wp_date('w', $t)];
	return $long ? wp_date('Y', $t) . '. ' . $m[$n - 1] . ' ' . $j . '., ' . $dow : mb_substr($m[$n - 1], 0, 3) . '. ' . $j . '., ' . $dow;
}
function cp_iv_slot($id) { global $wpdb; return $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_iv_t('slots') . ' WHERE id = %d', (int) $id), ARRAY_A) : null; }
function cp_iv_slot_label($s, $long = true) { return cp_iv_hu_day($s['day'], $long) . ', ' . $s['tm']; }
function cp_iv_booking_by_token($t) {
	global $wpdb;
	if (!preg_match('/^[a-f0-9]{32}$/', (string) $t)) { return null; }
	return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_iv_t('bookings') . ' WHERE token = %s', $t), ARRAY_A);
}
/* a szabad, még foglalható időpontok (lezárás: a kezdés előtt close_min perccel) */
function cp_iv_free_slots() {
	global $wpdb;
	$o = cp_iv_opts(); $lim = time() + 60 * max(0, (int) $o['close_min']);
	$rows = $wpdb->get_results('SELECT s.* , b.id bid FROM ' . cp_iv_t('slots') . ' s LEFT JOIN ' . cp_iv_t('bookings') . ' b ON b.slot_id = s.id ORDER BY s.day, s.tm', ARRAY_A);
	$out = array();
	foreach ((array) $rows as $r) {
		$ts = cp_iv_ts($r['day'], $r['tm']); if ($ts < $lim) { continue; }
		$out[$r['day']][] = array('id' => (int) $r['id'], 'tm' => $r['tm'], 'free' => !$r['bid'] && !$r['blocked']);
	}
	return $out;
}
/* egyszerű, időhöz kötött űrlap-jel (botok és régi lapok ellen) */
function cp_iv_form_sig($t) { return substr(hash_hmac('sha256', 'iv|' . $t, wp_salt('nonce')), 0, 20); }
function cp_iv_form_ok($t, $sig) { $t = (int) $t; return $t > 0 && hash_equals(cp_iv_form_sig($t), (string) $sig) && time() - $t >= 3 && time() - $t <= 3 * HOUR_IN_SECONDS; }
function cp_iv_ip() { return substr(hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt('auth')), 0, 16); }

/* ------------------------------------------------------------ foglalás, módosítás, lemondás */
function cp_iv_book($slot_id, $name, $phone, $email) {
	global $wpdb;
	$B = cp_iv_t('bookings');
	$s = cp_iv_slot($slot_id);
	$o = cp_iv_opts();
	if (!$s || $s['blocked']) { return new WP_Error('iv', 'Ez az időpont nem foglalható. Válassz másikat.'); }
	if (cp_iv_ts($s['day'], $s['tm']) < time() + 60 * (int) $o['close_min']) { return new WP_Error('iv', 'Erre az időpontra már lezárult a foglalás. Válassz egy későbbit.'); }
	$dup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $B WHERE email = %s AND status = 'ok' AND slot_id IS NOT NULL LIMIT 1", $email), ARRAY_A);
	if ($dup) { $ds = cp_iv_slot($dup['slot_id']); return new WP_Error('iv_dup', 'Ezzel az e-mail-címmel már van foglalásod' . ($ds ? ' (' . cp_iv_slot_label($ds) . ')' : '') . '. A visszaigazoló levélben lévő linkkel módosíthatod vagy lemondhatod.'); }
	$ip = cp_iv_ip();
	if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $B WHERE ip = %s AND created_at >= %s", $ip, gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS))) >= 5) { return new WP_Error('iv', 'Túl sok foglalás erről az eszközről. Próbáld újra később, vagy hívj minket.'); }
	$token = bin2hex(random_bytes(16));
	$now = gmdate('Y-m-d H:i:s');
	$wpdb->suppress_errors(true);
	$ok = $wpdb->insert($B, array('slot_id' => (int) $s['id'], 'name' => $name, 'phone' => $phone, 'email' => $email, 'token' => $token, 'status' => 'ok', 'ip' => $ip, 'created_at' => $now, 'changed_at' => $now));
	$wpdb->suppress_errors(false);
	if (!$ok) { return new WP_Error('iv_taken', 'Ezt az időpontot közben valaki más lefoglalta. Válassz egy másikat.'); }
	$b = $wpdb->get_row($wpdb->prepare("SELECT * FROM $B WHERE id = %d", (int) $wpdb->insert_id), ARRAY_A);
	$kz = cp_iv_kz_upsert($b, $s, 'foglalva');
	if ($kz) { $wpdb->update($B, array('kz_id' => $kz), array('id' => (int) $b['id'])); $b['kz_id'] = $kz; }
	cp_iv_mail_confirm($b, $s, 'new');
	cp_iv_notify('Interjú-foglalás: ' . $name . ' – ' . cp_iv_slot_label($s, false));
	return $b;
}
function cp_iv_move($b, $slot_id) {
	global $wpdb;
	$B = cp_iv_t('bookings'); $o = cp_iv_opts();
	$old = cp_iv_slot($b['slot_id']);
	if ($old && cp_iv_ts($old['day'], $old['tm']) < time() + 3600 * (int) $o['cancel_h']) { return new WP_Error('iv', 'Az interjú előtti ' . (int) $o['cancel_h'] . ' órában már nem lehet módosítani – kérjük, hívj minket.'); }
	$s = cp_iv_slot($slot_id);
	if (!$s || $s['blocked'] || cp_iv_ts($s['day'], $s['tm']) < time() + 60 * (int) $o['close_min']) { return new WP_Error('iv', 'Ez az időpont nem foglalható. Válassz másikat.'); }
	if ((int) $b['slot_id'] === (int) $s['id']) { return $b; }
	$wpdb->suppress_errors(true);
	$ok = $wpdb->query($wpdb->prepare("UPDATE $B SET last_slot = slot_id, slot_id = %d, status = 'ok', changed_at = %s, reminded_at = NULL WHERE id = %d", (int) $s['id'], gmdate('Y-m-d H:i:s'), (int) $b['id']));
	$wpdb->suppress_errors(false);
	if (!$ok) { return new WP_Error('iv_taken', 'Ezt az időpontot közben valaki más lefoglalta. Válassz egy másikat.'); }
	$b = $wpdb->get_row($wpdb->prepare("SELECT * FROM $B WHERE id = %d", (int) $b['id']), ARRAY_A);
	cp_iv_kz_upsert($b, $s, 'módosítva' . ($old ? ' (előtte: ' . cp_iv_slot_label($old, false) . ')' : ''));
	cp_iv_mail_confirm($b, $s, 'move');
	cp_iv_notify('Interjú módosítva: ' . $b['name'] . ' – ' . cp_iv_slot_label($s, false) . ($old ? ' (előtte ' . $old['tm'] . ')' : ''));
	return $b;
}
function cp_iv_cancel($b, $by_admin = false) {
	global $wpdb;
	$o = cp_iv_opts(); $s = cp_iv_slot($b['slot_id']);
	if (!$by_admin && $s && cp_iv_ts($s['day'], $s['tm']) < time() + 3600 * (int) $o['cancel_h']) { return new WP_Error('iv', 'Az interjú előtti ' . (int) $o['cancel_h'] . ' órában már nem lehet lemondani – kérjük, hívj minket.'); }
	$wpdb->query($wpdb->prepare('UPDATE ' . cp_iv_t('bookings') . " SET last_slot = slot_id, slot_id = NULL, status = 'cancel', changed_at = %s WHERE id = %d", gmdate('Y-m-d H:i:s'), (int) $b['id']));
	if ($s) { cp_iv_kz_upsert($b, $s, $by_admin ? 'lemondva (a panelből)' : 'lemondva'); }
	if (!$by_admin) { cp_iv_notify('Interjú lemondva: ' . $b['name'] . ($s ? ' – ' . cp_iv_slot_label($s, false) : '')); }
	return true;
}

/* a jelentkező a Kezelők → Jelentkezők közé (e-mail alapján; ha már szerepel, csak megjegyzés kerül hozzá) */
function cp_iv_kz_upsert($b, $s, $what) {
	if (!function_exists('cp_kz_ok') || !cp_kz_ok()) { return 0; }
	global $wpdb;
	$T = cp_kz_t();
	$line = 'Interjú ' . $what . ': ' . cp_iv_slot_label($s, false) . ' (' . wp_date('m.d. H:i') . ')';
	$row = $b['kz_id'] ? $wpdb->get_row($wpdb->prepare("SELECT id, note FROM $T WHERE id = %d", (int) $b['kz_id']), ARRAY_A) : null;
	if (!$row && $b['email'] !== '') { $row = $wpdb->get_row($wpdb->prepare("SELECT id, note FROM $T WHERE email = %s ORDER BY id DESC LIMIT 1", $b['email']), ARRAY_A); }
	$now = gmdate('Y-m-d H:i:s');
	if ($row) {
		$wpdb->update($T, array('note' => mb_substr(trim((string) $row['note'] . "\n" . $line), -2000), 'updated_at' => $now, 'updated_by' => 'Interjú-foglaló'), array('id' => (int) $row['id']));
		return (int) $row['id'];
	}
	$wpdb->insert($T, array('name' => $b['name'], 'email' => $b['email'], 'phone' => $b['phone'], 'kind' => 'jelentkezo', 'note' => $line, 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'updated_by' => 'Interjú-foglaló'));
	return (int) $wpdb->insert_id;
}
function cp_iv_notify($text) {
	if (!function_exists('cp_notify') || !function_exists('cp_pu_data')) { return; }
	$o = cp_iv_opts(); $to = array_map('intval', (array) $o['notify']);
	foreach (cp_pu_data()['list'] as $uid => $u) {
		if (empty($u['a'])) { continue; }
		if ($to ? in_array((int) $uid, $to, true) : (($u['r'] ?? '') === 'admin')) { cp_notify((int) $uid, 'iv_book', $text, 'kezelok:iv', 'Interjú-foglaló'); }
	}
}

/* a pontosság fontossága (a rövid, egymást követő időpontok miatt) – az oldalon és minden levélben */
function cp_iv_punct($dur) { return array('Kérjük, érkezz pontosan – inkább 5 perccel korábban.', 'Az interjúk ' . (int) $dur . ' percenként követik egymást, ezért a késés a következő jelentkező idejéből venne el, és előfordulhat, hogy új időpontot kell egyeztetnünk. Ha mégis késnél, minél előbb jelezd nekünk.'); }

/* ------------------------------------------------------------ e-mailek */
function cp_iv_ics($b, $s) {
	$o = cp_iv_opts();
	$st = cp_iv_ts($s['day'], $s['tm']); $en = $st + 60 * (int) $s['dur'];
	$esc = function ($x) { return str_replace(array('\\', ';', ',', "\r\n", "\n"), array('\\\\', '\;', '\,', '\n', '\n'), (string) $x); };
	$l = array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//EventCrazy//Interju//HU', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
		'UID:iv-' . (int) $b['id'] . '-' . substr($b['token'], 0, 8) . '@eventcrazy.hu', 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
		'DTSTART:' . gmdate('Ymd\THis\Z', $st), 'DTEND:' . gmdate('Ymd\THis\Z', $en), 'SUMMARY:' . $esc($o['title']),
		'LOCATION:' . $esc($o['location']), 'DESCRIPTION:' . $esc('Módosítás / lemondás: ' . cp_iv_url(array('t' => $b['token']))), 'END:VEVENT', 'END:VCALENDAR');
	return implode("\r\n", $l) . "\r\n";
}
function cp_iv_gcal_link($b, $s) {
	$o = cp_iv_opts(); $st = cp_iv_ts($s['day'], $s['tm']); $en = $st + 60 * (int) $s['dur'];
	return 'https://calendar.google.com/calendar/render?' . http_build_query(array('action' => 'TEMPLATE', 'text' => $o['title'], 'dates' => gmdate('Ymd\THis\Z', $st) . '/' . gmdate('Ymd\THis\Z', $en), 'location' => $o['location'], 'details' => 'Módosítás / lemondás: ' . cp_iv_url(array('t' => $b['token']))));
}
function cp_iv_mail_html($title, $rows_html) {
	return '<!doctype html><html><body style="margin:0;background:#F6F3EE;font-family:Arial,Helvetica,sans-serif;color:#1A1F2B">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F3EE;padding:24px 12px"><tr><td align="center">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden">'
		. '<tr><td style="background:#141C33;padding:22px 28px;color:#ffffff;font-size:20px;font-weight:bold">EventCrazy</td></tr>'
		. '<tr><td style="padding:26px 28px 8px;font-size:22px;font-weight:bold;line-height:1.3">' . esc_html($title) . '</td></tr>'
		. '<tr><td style="padding:4px 28px 28px;font-size:15px;line-height:1.6">' . $rows_html . '</td></tr></table>'
		. '<p style="font-size:12px;color:#5B6275;margin:16px 0 0">Ezt a levelet azért kaptad, mert időpontot foglaltál az EventCrazy kezelői interjújára.</p></td></tr></table></body></html>';
}
function cp_iv_mail_body($b, $s, $kind) {
	$o = cp_iv_opts();
	$when = cp_iv_slot_label($s);
	$btn = function ($href, $label, $primary) { return '<a href="' . esc_url($href) . '" style="display:inline-block;margin:0 8px 8px 0;padding:11px 18px;border-radius:999px;text-decoration:none;font-weight:bold;font-size:14px;' . ($primary ? 'background:#E8894A;color:#141C33' : 'background:#F1EDE7;color:#141C33') . '">' . esc_html($label) . '</a>'; };
	$h = '<p style="margin:0 0 14px">Kedves ' . esc_html($b['name']) . '!</p>';
	if ($kind === 'remind') { $h .= '<p style="margin:0 0 14px">Emlékeztetünk, hogy <b>holnap</b> vár ránk az interjú.</p>'; }
	elseif ($kind === 'move') { $h .= '<p style="margin:0 0 14px">Módosítottuk az interjú időpontját. Az új időpont:</p>'; }
	else { $h .= '<p style="margin:0 0 14px">Köszönjük a jelentkezésed! Lefoglaltuk az időpontodat a kezelői interjúra:</p>'; }
	$h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#FAF8F4;border-radius:12px;margin:0 0 18px"><tr><td style="padding:14px 16px;font-size:15px;line-height:1.6">'
		. '<b style="font-size:17px">' . esc_html($when) . '</b><br>Időtartam: kb. ' . (int) $s['dur'] . ' perc'
		. ($o['location'] !== '' ? '<br>Helyszín: ' . nl2br(esc_html($o['location'])) : '') . '</td></tr></table>';
	$pu = cp_iv_punct($s['dur']);
	$h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#FFF1E4;border:1px solid #F4CDAB;border-radius:12px;margin:0 0 18px"><tr><td style="padding:12px 14px;font-size:14px;line-height:1.55;color:#5A2E0C"><b style="color:#141C33">' . esc_html($pu[0]) . '</b><br>' . esc_html($pu[1]) . '</td></tr></table>';
	if (trim((string) $o['note']) !== '') { $h .= '<p style="margin:0 0 16px">' . nl2br(esc_html($o['note'])) . '</p>'; }
	$h .= '<p style="margin:0 0 6px">' . $btn(cp_iv_gcal_link($b, $s), 'Hozzáadás a Google Naptárhoz', true) . $btn(cp_iv_url(array('t' => $b['token'], 'ics' => 1)), 'Naptárfájl (.ics)', false) . '</p>';
	$h .= '<p style="margin:14px 0 6px">Ha mégsem érsz rá (legkésőbb ' . (int) $o['cancel_h'] . ' órával előtte):</p><p style="margin:0">' . $btn(cp_iv_url(array('t' => $b['token'])), 'Időpont módosítása', false) . $btn(cp_iv_url(array('t' => $b['token'], 'lemond' => 1)), 'Lemondás', false) . '</p>';
	$h .= '<p style="margin:16px 0 0">Várunk szeretettel!<br>Az EventCrazy csapata</p>';
	return $h;
}
/* a feladó és a válaszcím az ügyfélportál levelezéséből – a portál teszt-postafiókja itt NEM érvényes (a jelentkező kapja meg a levelet) */
function cp_iv_send($to, $subject, $html) {
	if (!is_email($to)) { return false; }
	$h = array('Content-Type: text/html; charset=UTF-8');
	$o = function_exists('cp_portal_opts') ? cp_portal_opts() : array();
	$from = sanitize_email((string) ($o['mail_from'] ?? ''));
	if ($from !== '') { $h[] = 'From: ' . (function_exists('cp_portal_mail_name') ? cp_portal_mail_name($o['mail_from_name'] ?? 'EventCrazy') : 'EventCrazy') . ' <' . $from . '>'; }
	$rt = sanitize_email((string) ($o['reply_to'] ?? ''));
	if ($rt !== '') { $h[] = 'Reply-To: ' . $rt; }
	return (bool) wp_mail($to, $subject, $html, $h);
}
function cp_iv_mail_confirm($b, $s, $kind) {
	if (!is_email($b['email'])) { return false; }
	$when = cp_iv_hu_day($s['day'], false) . ' ' . $s['tm'];
	$subj = $kind === 'remind' ? 'Emlékeztető: holnap interjú – EventCrazy, ' . $s['tm'] : ($kind === 'move' ? 'Interjú-időpont módosítva – EventCrazy, ' . $when : 'Interjú-időpont visszaigazolás – EventCrazy, ' . $when);
	$title = $kind === 'remind' ? 'Holnap találkozunk!' : ($kind === 'move' ? 'Módosítottuk az időpontodat' : 'Lefoglaltuk az időpontodat');
	return cp_iv_send($b['email'], $subj, cp_iv_mail_html($title, cp_iv_mail_body($b, $s, $kind)));
}
/* emlékeztető: az interjú előtt 24 órával (ha a foglalás ennél korábbi), egyszer – a 10 perces CRM-ütemmel fut */
add_action('cp_crm_tick', function () {
	if (!cp_iv_ok() || empty(cp_iv_opts()['remind'])) { return; }
	global $wpdb;
	$B = cp_iv_t('bookings'); $S = cp_iv_t('slots');
	$rows = $wpdb->get_results("SELECT b.*, s.day, s.tm, s.dur FROM $B b JOIN $S s ON s.id = b.slot_id WHERE b.status = 'ok' AND b.reminded_at IS NULL", ARRAY_A);
	foreach ((array) $rows as $r) {
		$ts = cp_iv_ts($r['day'], $r['tm']); $cr = strtotime((string) $r['changed_at'] . ' UTC');
		if ($ts - time() > DAY_IN_SECONDS || $ts - time() < 2 * HOUR_IN_SECONDS) { if ($ts - time() < 2 * HOUR_IN_SECONDS) { $wpdb->update($B, array('reminded_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $r['id'])); } continue; }
		if ($ts - $cr < DAY_IN_SECONDS) { $wpdb->update($B, array('reminded_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $r['id'])); continue; } /* 24 órán belül foglalt: a visszaigazolás elég */
		$wpdb->update($B, array('reminded_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $r['id']));
		cp_iv_mail_confirm($r, array('day' => $r['day'], 'tm' => $r['tm'], 'dur' => $r['dur']), 'remind');
	}
}, 45);

/* ------------------------------------------------------------ nyilvános oldal: /interju/ */
add_action('init', function () {
	$p = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
	if ($p !== '/interju') { return; }
	if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
	nocache_headers();
	header('X-Robots-Tag: noindex, nofollow');
	if (!cp_iv_ok()) { status_header(503); echo 'Az oldal hamarosan elérhető.'; exit; }
	$t = isset($_GET['t']) ? (string) wp_unslash($_GET['t']) : (string) wp_unslash($_POST['t'] ?? '');
	$b = $t !== '' ? cp_iv_booking_by_token($t) : null;
	/* naptárfájl */
	if ($b && !empty($_GET['ics'])) {
		$s = cp_iv_slot($b['slot_id']);
		if (!$s) { status_header(404); exit; }
		header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: attachment; filename="eventcrazy-interju.ics"');
		echo cp_iv_ics($b, $s); exit;
	}
	$o = cp_iv_opts();
	$msg = ''; $err = ''; $val = array('name' => '', 'phone' => '', 'email' => '', 'slot' => 0);
	if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
		$act = (string) ($_POST['iv_act'] ?? '');
		if (!cp_iv_form_ok($_POST['iv_t'] ?? 0, $_POST['iv_s'] ?? '') || !empty($_POST['website'])) { $err = 'A lap túl régóta volt nyitva, vagy hiba történt. Kérjük, töltsd be újra az oldalt.'; }
		elseif ($act === 'book') {
			$val = array('name' => mb_substr(trim(sanitize_text_field(wp_unslash($_POST['name'] ?? ''))), 0, 120), 'phone' => mb_substr(preg_replace('/[^0-9+ ()\/-]/', '', (string) wp_unslash($_POST['phone'] ?? '')), 0, 40), 'email' => strtolower(sanitize_email(wp_unslash($_POST['email'] ?? ''))), 'slot' => (int) ($_POST['slot'] ?? 0));
			if (empty($o['on'])) { $err = 'A foglalás jelenleg nincs nyitva.'; }
			elseif (mb_strlen($val['name']) < 3) { $err = 'Add meg a teljes neved.'; }
			elseif (strlen(preg_replace('/\D/', '', $val['phone'])) < 8) { $err = 'Add meg a telefonszámod (pl. +36 30 123 4567).'; }
			elseif (!is_email($val['email'])) { $err = 'Add meg egy érvényes e-mail-címet.'; }
			elseif (!$val['slot']) { $err = 'Válassz egy időpontot.'; }
			elseif (empty($_POST['consent'])) { $err = 'A foglaláshoz el kell fogadnod az adatkezelési tájékoztatót.'; }
			else {
				$r = cp_iv_book($val['slot'], $val['name'], $val['phone'], $val['email']);
				if (is_wp_error($r)) { $err = $r->get_error_message(); if ($r->get_error_code() === 'iv_taken') { $val['slot'] = 0; } }
				else { wp_safe_redirect(cp_iv_url(array('t' => $r['token'], 'kesz' => 1))); exit; }
			}
		} elseif ($b && $act === 'cancel') {
			$r = cp_iv_cancel($b);
			if (is_wp_error($r)) { $err = $r->get_error_message(); } else { wp_safe_redirect(cp_iv_url(array('t' => $b['token'], 'lemondva' => 1))); exit; }
		} elseif ($b && $act === 'move') {
			$r = cp_iv_move($b, (int) ($_POST['slot'] ?? 0));
			if (is_wp_error($r)) { $err = $r->get_error_message(); } else { wp_safe_redirect(cp_iv_url(array('t' => $b['token'], 'modositva' => 1))); exit; }
		}
		if ($b) { $b = cp_iv_booking_by_token($b['token']); }
	}
	cp_iv_render_page($o, $b, $val, $err);
	exit;
}, 9);

function cp_iv_render_page($o, $b, $val, $err) {
	$ft = time(); $fs = cp_iv_form_sig($ft);
	$free = cp_iv_free_slots();
	$s = $b && $b['slot_id'] ? cp_iv_slot($b['slot_id']) : null;
	$e = 'esc_html';
	$hidden = '<input type="hidden" name="iv_t" value="' . $ft . '"><input type="hidden" name="iv_s" value="' . esc_attr($fs) . '"><div class="hp" aria-hidden="true"><label>Weboldal <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
	$picker = function ($sel, $mine = 0) use ($free) {
		if (!$free) { return '<p class="empty">Jelenleg nincs szabad időpont.</p>'; }
		$h = '';
		foreach ($free as $day => $sl) {
			$n = count(array_filter($sl, function ($x) { return $x['free']; }));
			$h .= '<fieldset class="day"><legend><span class="dn">' . esc_html(cp_iv_hu_day($day)) . '</span><span class="cnt">' . ($n ? $n . ' szabad' : 'betelt') . '</span></legend><div class="slots">';
			foreach ($sl as $x) {
				$on = $x['free'] || $x['id'] === (int) $mine;
				$h .= '<label class="slot' . ($on ? '' : ' taken') . '"><input type="radio" name="slot" value="' . (int) $x['id'] . '"' . ($on ? '' : ' disabled') . ((int) $sel === $x['id'] ? ' checked' : '') . ' required><span>' . esc_html($x['tm']) . ($on ? '' : '<small>foglalt</small>') . '</span></label>';
			}
			$h .= '</div></fieldset>';
		}
		return $h;
	};
	$where = $o['location'] !== '' ? '<p class="loc"><b>Helyszín:</b> ' . nl2br(esc_html($o['location'])) . '</p>' : '';
	$pu = cp_iv_punct($s ? $s['dur'] : $o['dur']);
	$punct = '<div class="punct" role="note"><b>' . esc_html($pu[0]) . '</b> ' . esc_html($pu[1]) . '</div>';
	ob_start();
	echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html($o['title']) . '</title>';
	echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">';
	echo '<style>' . cp_iv_css() . '</style></head><body><header class="top"><div class="wrap"><span class="brand">EventCrazy</span></div></header><main class="wrap">';
	if ($b) {
		/* a foglalás kezelése */
		$st = $s ? cp_iv_ts($s['day'], $s['tm']) : 0; $late = $s && $st < time() + 3600 * (int) $o['cancel_h'];
		if (!empty($_GET['kesz'])) { echo '<div class="ok" role="status"><b>Kész, lefoglaltuk!</b> A visszaigazolást elküldtük a(z) ' . esc_html($b['email']) . ' címre.</div>'; }
		if (!empty($_GET['modositva'])) { echo '<div class="ok" role="status"><b>Módosítottuk az időpontot.</b> Az új visszaigazolást elküldtük e-mailben.</div>'; }
		if ($err !== '') { echo '<div class="err" role="alert">' . esc_html($err) . '</div>'; }
		if ($b['status'] === 'cancel' || !$s) {
			echo '<h1>A foglalásod le van mondva</h1><p class="lead">' . (!empty($_GET['lemondva']) ? 'Köszönjük, hogy jelezted. ' : '') . 'Ha mégis jönnél, foglalj új időpontot.</p><p><a class="btn" href="' . esc_url(cp_iv_url()) . '">Új időpont foglalása</a></p>';
		} else {
			echo '<h1>Az interjúd időpontja</h1><div class="card when"><span class="big">' . esc_html(cp_iv_slot_label($s)) . '</span><span>Időtartam: kb. ' . (int) $s['dur'] . ' perc</span>' . $where . '</div>' . $punct;
			echo '<p class="cal"><a class="btn" href="' . esc_url(cp_iv_gcal_link($b, $s)) . '" target="_blank" rel="noopener">Hozzáadás a Google Naptárhoz</a> <a class="btn ghost" href="' . esc_url(cp_iv_url(array('t' => $b['token'], 'ics' => 1))) . '">Naptárfájl (.ics)</a></p>';
			if ($late) { echo '<p class="muted">Az interjú előtti ' . (int) $o['cancel_h'] . ' órában az időpont már nem módosítható. Ha közbejött valami, kérjük, hívj minket.</p>'; }
			else {
				if (!empty($_GET['lemond'])) {
					echo '<div class="card confirm"><p><b>Biztosan lemondod az interjút?</b><br>' . esc_html(cp_iv_slot_label($s)) . '</p><form method="post">' . $hidden . '<input type="hidden" name="t" value="' . esc_attr($b['token']) . '"><input type="hidden" name="iv_act" value="cancel"><button class="btn danger" type="submit">Igen, lemondom</button><a class="btn ghost" href="' . esc_url(cp_iv_url(array('t' => $b['token']))) . '">Mégsem</a></form></div>';
				} else {
					echo '<div class="acts"><a class="btn ghost" href="' . esc_url(cp_iv_url(array('t' => $b['token'], 'valt' => 1))) . '#valt">Másik időpont választása</a><a class="btn ghost danger-t" href="' . esc_url(cp_iv_url(array('t' => $b['token'], 'lemond' => 1))) . '">Lemondás</a></div>';
					if (!empty($_GET['valt'])) { echo '<div class="card" id="valt"><h2 style="margin-top:0">Válassz új időpontot</h2><form method="post" class="f">' . $hidden . '<input type="hidden" name="t" value="' . esc_attr($b['token']) . '"><input type="hidden" name="iv_act" value="move">' . $picker((int) $b['slot_id'], (int) $b['slot_id']) . '<button class="btn" type="submit">Időpont módosítása</button> <a class="btn ghost" href="' . esc_url(cp_iv_url(array('t' => $b['token']))) . '">Mégsem</a></form></div>'; }
				}

			}
		}
	} else {
		echo '<h1>Válassz időpontot az interjúra</h1><p class="lead">Az interjú kb. ' . (int) $o['dur'] . ' perces, személyes beszélgetés. Válaszd ki, mikor érsz rá, és add meg az elérhetőségeidet – a visszaigazolást e-mailben küldjük.</p>' . $where . $punct;
		if (empty($o['on'])) { echo '<div class="err" role="status">A foglalás jelenleg nincs nyitva.</div>'; }
		if ($err !== '') { echo '<div class="err" role="alert">' . esc_html($err) . '</div>'; }
		echo '<form method="post" class="f" novalidate>' . $hidden . '<input type="hidden" name="iv_act" value="book">';
		echo '<h2>1. Időpont</h2>' . $picker($val['slot']);
		echo '<h2>2. Elérhetőség</h2><div class="fields">'
			. '<label>Teljes név<input type="text" name="name" autocomplete="name" maxlength="120" required value="' . esc_attr($val['name']) . '"></label>'
			. '<label>Telefonszám<input type="tel" name="phone" autocomplete="tel" inputmode="tel" maxlength="40" required placeholder="+36 30 123 4567" value="' . esc_attr($val['phone']) . '"></label>'
			. '<label>E-mail-cím<input type="email" name="email" autocomplete="email" maxlength="190" required value="' . esc_attr($val['email']) . '"></label></div>';
		echo '<label class="consent"><input type="checkbox" name="consent" value="1" required> <span>Hozzájárulok, hogy az EventCrazy a megadott adataimat az interjú megszervezése és a jelentkezésem elbírálása céljából kezelje. Az adatokat harmadik félnek nem adjuk ki, és kérésre töröljük.</span></label>';
		echo '<button class="btn wide" type="submit"' . (empty($o['on']) ? ' disabled' : '') . '>Időpont lefoglalása</button></form>';
	}
	echo '</main><footer class="wrap foot">EventCrazy · <a href="https://eventcrazy.hu/">eventcrazy.hu</a></footer></body></html>';
	echo ob_get_clean();
}

function cp_iv_css() {
	return ':root{--n:#141C33;--o:#E8894A;--ot:#A85617;--ink:#1A1F2B;--mu:#5B6275;--li:#E7E2DA;--bg:#F6F3EE;--ok:#1F7A50;--okb:#E3F4EB;--bad:#B3261E;--badb:#FBE3E1}'
		. '*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif}'
		. '::selection{background:#F6D3B8}.wrap{max-width:640px;margin:0 auto;padding:0 16px}.top{background:var(--n);color:#fff}.top .wrap{height:60px;display:flex;align-items:center}'
		. '.brand{font:800 20px "Plus Jakarta Sans",Inter,sans-serif;letter-spacing:-.01em}main.wrap{padding:30px 16px 24px}'
		. 'h1{font:800 clamp(26px,6vw,34px)/1.15 "Plus Jakarta Sans",Inter,sans-serif;letter-spacing:-.02em;margin:0 0 10px;text-wrap:balance}'
		. 'h2{font:700 17px "Plus Jakarta Sans",Inter,sans-serif;margin:30px 0 10px}.lead{color:var(--mu);margin:0 0 8px;max-width:60ch}.loc{margin:8px 0 0}.muted{color:var(--mu)}'
		. '.day{border:0;padding:0;margin:0 0 18px}.day legend{display:flex;align-items:baseline;gap:10px;padding:0;margin:0 0 8px;font-weight:600}.cnt{font-size:13px;color:var(--mu);font-weight:500}'
		. '.slots{display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:8px}.slot{position:relative}.slot input{position:absolute;opacity:0;inset:0;margin:0;cursor:pointer}'
		. '.slot span{display:flex;flex-direction:column;align-items:center;justify-content:center;height:52px;border:1.5px solid var(--li);background:#fff;border-radius:12px;font-weight:600;font-variant-numeric:tabular-nums;transition:border-color .15s,background-color .15s,color .15s}'
		. '.slot:hover span{border-color:#D9C5B3}.slot input:checked+span{background:var(--n);border-color:var(--n);color:#fff}.slot input:focus-visible+span{outline:3px solid var(--o);outline-offset:2px}'
		. '.slot.taken span{color:#A7A39C;background:transparent;border-style:dashed;text-decoration:line-through}.slot.taken small{font-size:11px;text-decoration:none;font-weight:500}.slot.taken input{cursor:not-allowed}'
		. '.fields{display:grid;gap:14px}.fields label{display:flex;flex-direction:column;gap:6px;font-weight:600;font-size:14px}'
		. 'input[type=text],input[type=tel],input[type=email]{height:48px;border:1.5px solid var(--li);border-radius:12px;padding:0 14px;font:16px Inter,sans-serif;color:var(--ink);background:#fff}'
		. 'input[type=text]:focus,input[type=tel]:focus,input[type=email]:focus{outline:3px solid var(--o);outline-offset:1px;border-color:var(--o)}'
		. '.consent{display:flex;gap:10px;align-items:flex-start;margin:18px 0 0;font-size:14px;color:var(--mu);cursor:pointer}.consent input{width:20px;height:20px;margin:2px 0 0;accent-color:var(--o);flex:none}'
		. '.btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 22px;border:0;border-radius:999px;background:var(--o);color:var(--n);font:700 16px Inter,sans-serif;text-decoration:none;cursor:pointer;margin:0 8px 8px 0;transition:filter .15s}'
		. '.btn:hover{filter:brightness(.95)}.btn:focus-visible{outline:3px solid var(--n);outline-offset:2px}.btn[disabled]{opacity:.5;cursor:not-allowed}.btn.ghost{background:#fff;color:var(--n);border:1.5px solid var(--li)}.btn.wide{width:100%;margin:22px 0 0}'
		. '.card{background:#fff;border:1px solid var(--li);border-radius:16px;padding:18px;margin:16px 0}.when{display:flex;flex-direction:column;gap:4px}.when .big{font:800 22px "Plus Jakarta Sans",Inter,sans-serif;letter-spacing:-.01em}'
		. 'details summary{cursor:pointer;font-weight:700;list-style:none}details summary::-webkit-details-marker{display:none}details[open] summary{margin-bottom:12px}.acts{display:flex;flex-wrap:wrap;gap:0 8px;align-items:flex-start;margin:6px 0}.btn.danger{background:var(--bad);color:#fff}.btn.danger-t{color:var(--bad);border-color:#F1C3BE}.confirm p{margin:0 0 14px}.link{background:none;border:0;padding:0;color:var(--bad);font:600 15px Inter,sans-serif;text-decoration:underline;text-underline-offset:3px;cursor:pointer}'
		. '.punct{background:#FFF1E4;border:1px solid #F4CDAB;border-radius:12px;padding:12px 14px;margin:14px 0 4px;font-size:15px;color:#5A2E0C}.punct b{color:var(--n)}.ok,.err{border-radius:12px;padding:12px 14px;margin:0 0 18px}.ok{background:var(--okb);color:var(--ok)}.err{background:var(--badb);color:var(--bad)}.empty{color:var(--mu)}'
		. '.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}.foot{color:var(--mu);font-size:13px;padding:8px 16px 32px}.foot a{color:var(--mu)}'
		. '@media (prefers-reduced-motion:reduce){*{transition:none!important}}';
}

/* ------------------------------------------------------------ panel: Kezelők → Interjú-időpontok */
function cp_iv_admin_data() {
	global $wpdb;
	$o = cp_iv_opts();
	$rows = $wpdb->get_results('SELECT s.id, s.day, s.tm, s.dur, s.blocked, b.id bid, b.name, b.phone, b.email, b.kz_id, b.created_at FROM ' . cp_iv_t('slots') . ' s LEFT JOIN ' . cp_iv_t('bookings') . ' b ON b.slot_id = s.id ORDER BY s.day, s.tm', ARRAY_A);
	$days = array();
	foreach ((array) $rows as $r) { $days[$r['day']]['label'] = cp_iv_hu_day($r['day']); $days[$r['day']]['slots'][] = array('id' => (int) $r['id'], 'tm' => $r['tm'], 'blocked' => (int) $r['blocked'], 'b' => $r['bid'] ? array('id' => (int) $r['bid'], 'name' => $r['name'], 'phone' => $r['phone'], 'email' => $r['email'], 'kz' => (int) $r['kz_id'], 'at' => function_exists('cp_crm_local') ? cp_crm_local($r['created_at']) : $r['created_at']) : null); }
	$cancelled = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . cp_iv_t('bookings') . " WHERE status = 'cancel'");
	return array('opts' => array('on' => (int) $o['on'], 'title' => $o['title'], 'location' => $o['location'], 'note' => $o['note'], 'dur' => (int) $o['dur'], 'days' => array_values((array) $o['days']), 'close_min' => (int) $o['close_min'], 'cancel_h' => (int) $o['cancel_h'], 'remind' => (int) $o['remind']), 'days' => $days, 'cancelled' => $cancelled, 'url' => cp_iv_url());
}
add_action('wp_ajax_nopriv_cp_iv_admin', 'cp_iv_ajax_admin');
add_action('wp_ajax_cp_iv_admin', 'cp_iv_ajax_admin');
function cp_iv_ajax_admin() {
	if (!function_exists('cp_kz_auth')) { wp_send_json_error(array('msg' => 'Hiba')); }
	cp_kz_auth();
	if (!cp_iv_ok()) { cp_el_err('Az időpontfoglaló még települ – próbáld újra egy perc múlva.', 503); }
	$do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
	global $wpdb;
	if ($do !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cp_el_err('Hibás kérés.'); }
	if ($do === 'block' || $do === 'unblock') {
		$wpdb->update(cp_iv_t('slots'), array('blocked' => $do === 'block' ? 1 : 0), array('id' => (int) ($_POST['id'] ?? 0)));
	} elseif ($do === 'cancel') {
		$b = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_iv_t('bookings') . ' WHERE id = %d', (int) ($_POST['id'] ?? 0)), ARRAY_A);
		if ($b && $b['status'] === 'ok') { cp_iv_cancel($b, true); }
	} elseif ($do === 'save') {
		if (function_exists('cp_admin_need')) { cp_admin_need(); }
		$in = json_decode((string) wp_unslash($_POST['o'] ?? ''), true);
		if (!is_array($in)) { cp_el_err('Hibás kérés.'); }
		$o = cp_iv_opts();
		$o['on'] = empty($in['on']) ? 0 : 1;
		$o['title'] = mb_substr(sanitize_text_field((string) ($in['title'] ?? $o['title'])), 0, 120) ?: cp_iv_defaults()['title'];
		$o['location'] = mb_substr(sanitize_textarea_field((string) ($in['location'] ?? '')), 0, 400);
		$o['note'] = mb_substr(sanitize_textarea_field((string) ($in['note'] ?? '')), 0, 800);
		$o['dur'] = max(5, min(120, (int) ($in['dur'] ?? 20)));
		$o['close_min'] = max(0, min(2880, (int) ($in['close_min'] ?? 120)));
		$o['cancel_h'] = max(0, min(168, (int) ($in['cancel_h'] ?? 12)));
		$o['remind'] = empty($in['remind']) ? 0 : 1;
		$days = array();
		foreach (array_slice((array) ($in['days'] ?? array()), 0, 20) as $d) {
			$dd = (string) ($d['d'] ?? ''); $f = (string) ($d['from'] ?? ''); $t = (string) ($d['to'] ?? '');
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd) && preg_match('/^\d{2}:\d{2}$/', $f) && preg_match('/^\d{2}:\d{2}$/', $t) && $f < $t) { $days[] = array('d' => $dd, 'from' => $f, 'to' => $t); }
		}
		$o['days'] = $days;
		update_option('cp_iv', $o, false);
		cp_iv_sync_slots();
		if (function_exists('cp_audit_add')) { cp_audit_add('kezelok', 'interview', 'Interjú-időpontok beállítása ' . ($o['on'] ? '(nyitva)' : '(zárva)'), '', count($days) . ' nap, ' . $o['dur'] . ' perc'); }
	}
	wp_send_json_success(cp_iv_admin_data());
}
