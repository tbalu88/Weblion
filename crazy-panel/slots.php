<?php
/* =====================================================================
 * Slotok és beosztás (v2.9.115, 1. lépés) – a Kapacitás fül napjaira.
 * Slot = egy gép egy napra egy rendezvényhez (a Kapacitás ugyanígy számol: szelfigép / spinner; „both” = 2 slot).
 * Szabályok (a felhasználó döntése, 2026-11-10):
 *  – Budapesten kívül (vidék) mindig Kezelő kell (autóval, önállóan épít és bont); Budapesten Host/hostess is mehet.
 *  – Spinnerre csak Kezelő, aki a Kezelők fülön „Spinner” tudással rendelkezik; Sebestyén Ákos az elsődleges.
 *  – A gép-tudás a Kezelők fülről (Selfie, Tükör, Robot…) – akinek nincs meg, azt nem javasolja (kézzel meghívható).
 *  – Meghívni a szerződött / „Szerződés kiküldve” rendezvényre lehet; a karácsonyi időszakban (beállítható) már ajánlatkérés után is.
 * Folyamat: meghívva (iroda) → elfogadta / nem ér rá (telefonon rögzítve, később linkről) → véglegesítve (Ákos vagy admin).
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }

define('CP_SLOT_DB_VER', '2'); /* 2: sent_at, ans_at, inv_uid, fin_mail_at – meghívó levél és válaszlink (v2.9.117) */
function cp_slot_t() { global $wpdb; return $wpdb->prefix . 'crazy_slot_asg'; }
function cp_slot_db_ok() { return get_option('cp_slot_db_ver') === CP_SLOT_DB_VER; }

add_action('init', function () {
	if (cp_slot_db_ok() || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_slot_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  deal_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  skey varchar(12) NOT NULL DEFAULT '',
  kz_id bigint(20) unsigned NOT NULL,
  status varchar(12) NOT NULL DEFAULT 'invited',
  note varchar(255) NOT NULL DEFAULT '',
  tok char(24) NOT NULL DEFAULT '',
  by_name varchar(100) NOT NULL DEFAULT '',
  inv_uid int(11) NOT NULL DEFAULT 0,
  sent_at datetime NULL,
  ans_at datetime NULL,
  fin_mail_at datetime NULL,
  created_at datetime NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  KEY dayk (day),
  KEY kz (kz_id, day),
  KEY slot (deal_id, day, skey)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_slot_db_ver', CP_SLOT_DB_VER, true);
});

function cp_slot_states() { return array('invited' => 'Meghívva', 'accepted' => 'Ráér', 'declined' => 'Nem ér rá', 'final' => 'Véglegesítve', 'dropped' => 'Nem rá esett'); }
/* a karácsonyi időszak (ilyenkor már ajánlatkérés után is meghívható) – HH-NN tól-ig, beállítható */
function cp_slot_opts() { $o = get_option('cp_slot_opts', array()); return array_merge(array('xmas_from' => '11-15', 'xmas_to' => '12-31'), is_array($o) ? $o : array()); }
function cp_slot_xmas($day) { $o = cp_slot_opts(); $md = substr((string) $day, 5); return $md >= $o['xmas_from'] && $md <= $o['xmas_to']; }
/* véglegesíteni Ákos (a munkanapló gazdája) vagy admin tud */
function cp_slot_can_final() {
	if (function_exists('cp_panel_is_admin') && cp_panel_is_admin()) { return true; }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if ($me && function_exists('cp_wl_owners')) { foreach (cp_wl_owners() as $u) { if ((int) $u['id'] === $me) { return true; } } }
	return false;
}
/* Budapest-e a helyszín (irányítószám 1xxx, „Budapest”, „Bp”, „XI. ker.”) – üres helyszín: ismeretlen */
function cp_slot_bp($loc) {
	$l = trim((string) $loc);
	if ($l === '') { return null; }
	if (preg_match('/budapest|\bbp\.?(?=\W|$)|\b1\d{3}\b|\b[ivxl]{1,6}\.\s*ker|óbuda|újpest|kőbánya|csepel|zugló|budafok|rákospalota|pesterzsébet|kispest|angyalföld/iu', $l)) { return true; }
	/* vidék csak egyértelmű jelre: Budapesten kívüli irányítószám (2xxx–9xxx), vagy ismert település; különben ismeretlen */
	if (preg_match('/(^|[^\d])[2-9]\d{3}(?!\d)/u', $l)) { return false; }
	if (preg_match('/debrecen|szeged|győr|pécs|miskolc|kecskemét|székesfehérvár|szolnok|veszprém|eger\b|siófok|balaton|tata|gödöllő|budaörs|érd\b|szentendre|vác\b|szombathely|nyíregyháza|sopron|zalaegerszeg|kaposvár|tatabánya|esztergom|visegrád|dunakeszi|törökbálint|herceghalom|zsámbék|zsambék|velence|hévíz|keszthely|tihany/iu', $l)) { return false; }
	return null;
}
/* a gép tudás-kulcsa a szolgáltatás szövegéből (a Kezelők fül tudás-listájához) */
function cp_slot_skill($kind, $txt) {
	if ($kind === 'spin') { return 'spinner'; }
	$t = mb_strtolower((string) $txt);
	foreach (array('tukor' => 'tükör|tukor|mirror', 'robot' => 'robot', 'vr' => '\bvr\b', 'overhead' => 'overhead|felülnézet', 'vip' => '\bvip\b', 'catchit' => 'catch', 'stand' => 'stand') as $k => $re) {
		if (preg_match('/' . $re . '/iu', $t)) { return $k; }
	}
	return 'selfie';
}

/* egy nap slotjai a Kapacitás adataiból + a beosztások */
function cp_slot_day($day) {
	global $wpdb;
	$evs = function_exists('cp_cap_month') ? (array) cp_cap_month(substr($day, 0, 7)) : array();
	$need_o = get_option('cp_slot_need', array()); if (!is_array($need_o)) { $need_o = array(); }
	$slots = array();
	foreach ($evs as $e) {
		if (($e['date'] ?? '') !== $day) { continue; }
		$deal = (int) ($e['deal'] ?? $e['id']);
		if ($deal <= 0) { continue; }
		$kinds = ($e['kind'] ?? 'selfie') === 'both' ? array('selfie', 'spin') : array((string) ($e['kind'] ?? 'selfie'));
		$bp = cp_slot_bp($e['loc'] ?? '');
		$stage = (string) ($e['stage'] ?? '');
		$contract = ($e['state'] ?? '') === 'booked' || preg_match('/szerz/iu', $stage);
		foreach ($kinds as $i => $k) {
			$skey = ($k === 'spin' ? 'p' : 's') . ($i + 1);
			$need = $k === 'spin' || $bp === false ? 'kezelo' : 'host';
			$nk = $deal . '|' . $day . '|' . $skey;
			if (isset($need_o[$nk])) { $need = $need_o[$nk] === 'kezelo' ? 'kezelo' : 'host'; }
			$slots[] = array('deal' => $deal, 'skey' => $skey, 'kind' => $k, 'skill' => cp_slot_skill($k, $k === 'spin' ? ($e['spin'] ?? '') : ($e['selfie'] ?? '')),
				'need' => $need, 'need_set' => isset($need_o[$nk]) ? 1 : 0, 'bp' => $bp === null ? -1 : ($bp ? 1 : 0),
				'title' => (string) ($e['title'] ?? ''), 'stage' => $stage, 'booked' => ($e['state'] ?? '') === 'booked' ? 1 : 0,
				'time' => (string) ($e['time'] ?? ''), 'loc' => (string) ($e['loc'] ?? ''), 'svc' => (string) ($k === 'spin' ? ($e['spin'] ?? '') : ($e['selfie'] ?? '')),
				'can_inv' => ($contract || cp_slot_xmas($day)) ? 1 : 0, 'asg' => array());
		}
	}
	/* beosztások */
	$kz = array();
	if (function_exists('cp_kz_t')) { foreach ((array) $wpdb->get_results('SELECT id, name, kind, phone, email, has_car, skills, active FROM ' . cp_kz_t(), ARRAY_A) as $r) { $kz[(int) $r['id']] = $r; } }
	$rows = cp_slot_db_ok() ? (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cp_slot_t() . " WHERE day = %s ORDER BY id", $day), ARRAY_A) : array();
	$busy = array(); /* aznap kinek van már (nem elutasított) beosztása: kz_id => [slot-kulcsok] */
	foreach ($rows as $r) {
		if (!in_array($r['status'], array('declined', 'dropped'), true)) { $busy[(int) $r['kz_id']][] = $r['deal_id'] . '|' . $r['skey']; }
		foreach ($slots as &$s) {
			if ((int) $r['deal_id'] === $s['deal'] && $r['skey'] === $s['skey']) {
				$k = $kz[(int) $r['kz_id']] ?? null;
				$s['asg'][] = array('id' => (int) $r['id'], 'kz' => (int) $r['kz_id'], 'n' => $k ? (string) $k['name'] : '#' . $r['kz_id'], 'ph' => $k ? (string) $k['phone'] : '', 'kind' => $k ? (string) $k['kind'] : '', 'st' => (string) $r['status'], 'note' => (string) $r['note'], 'em' => $k && is_email($k['email']) ? 1 : 0, 'sent' => $r['sent_at'] ? cp_crm_local((string) $r['sent_at']) : '', 'ans' => $r['ans_at'] ? cp_crm_local((string) $r['ans_at']) : '', 'fm' => $r['fin_mail_at'] ? 1 : 0, 'by' => (string) $r['by_name'], 'at' => function_exists('cp_crm_local') ? cp_crm_local((string) $r['updated_at']) : (string) $r['updated_at']);
			}
		}
		unset($s);
	}
	/* jelöltek: aktív Kezelő és Host/hostess; tudás, autó, aznapi foglaltság */
	$cands = array();
	foreach ($kz as $id => $k) {
		if (empty($k['active']) || !in_array($k['kind'], array('kezelo', 'host'), true)) { continue; }
		$cands[] = array('id' => $id, 'n' => (string) $k['name'], 'kind' => (string) $k['kind'], 'ph' => (string) $k['phone'], 'car' => (int) $k['has_car'], 'sk' => $k['skills'] === '' ? array() : explode(',', $k['skills']), 'busy' => $busy[$id] ?? array());
	}
	/* sorrend: Kezelők elöl, Ákos legelöl (spinner-elsőbbség), majd név */
	usort($cands, function ($a, $b) { $pa = preg_match('/ákos/iu', $a['n']) ? 0 : 1; $pb = preg_match('/ákos/iu', $b['n']) ? 0 : 1; return ($a['kind'] === 'kezelo' ? 0 : 1) - ($b['kind'] === 'kezelo' ? 0 : 1) ?: $pa - $pb ?: strcmp($a['n'], $b['n']); });
	return array('day' => $day, 'slots' => $slots, 'cands' => $cands, 'xmas' => cp_slot_xmas($day) ? 1 : 0, 'can_final' => cp_slot_can_final() ? 1 : 0, 'states' => cp_slot_states(), 'skills' => function_exists('cp_kz_skills') ? cp_kz_skills() : array());
}

/* havi összesítő a naptár celláihoz: nap => [slot, véglegesített, meghívott (még nem végleges)] (2.9.115-ös forma)
   v2.9.116: + 'x' (részletes): k/h = Kezelő- / Host-igényű slot, u = ismeretlen helyszín, fk/fh = véglegesített, ik/ih = meghívás folyamatban,
   sp = szerződött (biztos) slotok, b = aznap már véglegesített emberek száma; a fejlécben az aktív kezelők és hostok száma */
function cp_slot_month($m, $full = false) {
	global $wpdb;
	$out = array(); $x = array();
	$need_o = get_option('cp_slot_need', array()); if (!is_array($need_o)) { $need_o = array(); }
	$slotNeed = array();
	foreach ((array) (function_exists('cp_cap_month') ? cp_cap_month($m) : array()) as $e) {
		$d = (string) ($e['date'] ?? ''); if ($d === '') { continue; }
		if (!isset($out[$d])) { $out[$d] = array(0, 0, 0); $x[$d] = array('k' => 0, 'h' => 0, 'u' => 0, 'fk' => 0, 'fh' => 0, 'ik' => 0, 'ih' => 0, 'sp' => 0, 'b' => 0); }
		$deal = (int) ($e['deal'] ?? $e['id']);
		$kinds = ($e['kind'] ?? 'selfie') === 'both' ? array('selfie', 'spin') : array((string) ($e['kind'] ?? 'selfie'));
		$bp = cp_slot_bp($e['loc'] ?? '');
		foreach ($kinds as $i => $k) {
			$skey = ($k === 'spin' ? 'p' : 's') . ($i + 1);
			$need = $k === 'spin' || $bp === false ? 'kezelo' : 'host';
			$nk = $deal . '|' . $d . '|' . $skey;
			if (isset($need_o[$nk])) { $need = $need_o[$nk] === 'kezelo' ? 'kezelo' : 'host'; }
			$out[$d][0]++;
			$x[$d][$need === 'kezelo' ? 'k' : 'h']++;
			if ($bp === null && !isset($need_o[$nk])) { $x[$d]['u']++; }
			if (($e['state'] ?? '') === 'booked') { $x[$d]['sp']++; }
			$slotNeed[$deal . '|' . $d . '|' . $skey] = $need;
		}
	}
	if (cp_slot_db_ok()) {
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT day, deal_id, skey, MAX(status = %s) f, MAX(status IN (%s, %s)) i FROM ' . cp_slot_t() . ' WHERE day BETWEEN %s AND %s GROUP BY day, deal_id, skey', 'final', 'invited', 'accepted', $m . '-01', $m . '-31'), ARRAY_A) as $r) {
			if (!isset($out[$r['day']])) { continue; }
			$nd = $slotNeed[$r['deal_id'] . '|' . $r['day'] . '|' . $r['skey']] ?? null;
			if ($nd === null) { continue; }
			if ((int) $r['f']) { $out[$r['day']][1]++; $x[$r['day']][$nd === 'kezelo' ? 'fk' : 'fh']++; }
			elseif ((int) $r['i']) { $out[$r['day']][2]++; $x[$r['day']][$nd === 'kezelo' ? 'ik' : 'ih']++; }
		}
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT day, COUNT(DISTINCT kz_id) n FROM ' . cp_slot_t() . " WHERE status = 'final' AND day BETWEEN %s AND %s GROUP BY day", $m . '-01', $m . '-31'), ARRAY_A) as $r) { if (isset($x[$r['day']])) { $x[$r['day']]['b'] = (int) $r['n']; } }
	}
	if (!$full) { return $out; }
	$tk = 0; $th = 0;
	if (function_exists('cp_kz_t')) { foreach ((array) $wpdb->get_results('SELECT kind, COUNT(*) n FROM ' . cp_kz_t() . " WHERE active = 1 AND kind IN ('kezelo','host') GROUP BY kind", ARRAY_A) as $r) { if ($r['kind'] === 'kezelo') { $tk = (int) $r['n']; } else { $th = (int) $r['n']; } } }
	return array('d' => $out, 'x' => $x, 'tk' => $tk, 'th' => $th);
}

function cp_slot_auth() {
	cp_el_auth();
	cp_tab_need(array('naptar'));
	if (!cp_slot_db_ok()) { cp_el_err('A beosztás adatbázisa készül – töltsd újra az oldalt.', 503); }
}
add_action('wp_ajax_nopriv_cp_slot_day', 'cp_slot_ajax_day'); add_action('wp_ajax_cp_slot_day', 'cp_slot_ajax_day');
function cp_slot_ajax_day() {
	cp_slot_auth();
	$day = (string) ($_REQUEST['day'] ?? '');
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { cp_el_err('Hibás nap.'); }
	wp_send_json_success(cp_slot_day($day));
}
add_action('wp_ajax_nopriv_cp_slot_month', 'cp_slot_ajax_month'); add_action('wp_ajax_cp_slot_month', 'cp_slot_ajax_month');
function cp_slot_ajax_month() {
	cp_slot_auth();
	$m = (string) ($_REQUEST['m'] ?? '');
	if (!preg_match('/^\d{4}-\d{2}$/', $m)) { cp_el_err('Hibás hónap.'); }
	wp_send_json_success(array('m' => $m) + cp_slot_month($m, true));
}
/* műveletek: invite (több kezelő egyszerre), st (elfogadta / nem ér rá – telefonos rögzítés), final, del, need (Kezelő / Host felülírása) */
add_action('wp_ajax_nopriv_cp_slot_act', 'cp_slot_ajax_act'); add_action('wp_ajax_cp_slot_act', 'cp_slot_ajax_act');
function cp_slot_ajax_act() {
	cp_slot_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$T = cp_slot_t();
	$op = sanitize_key($_POST['op'] ?? '');
	$day = (string) ($_POST['day'] ?? '');
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { cp_el_err('Hibás nap.'); }
	$deal = (int) ($_POST['deal'] ?? 0); $skey = preg_replace('/[^a-z0-9]/', '', (string) ($_POST['skey'] ?? ''));
	$who = function_exists('cp_panel_who') ? mb_substr((string) cp_panel_who(), 0, 100) : '';
	$now = gmdate('Y-m-d H:i:s');
	$x = cp_slot_day($day);
	$slot = null; foreach ($x['slots'] as $s) { if ($s['deal'] === $deal && $s['skey'] === $skey) { $slot = $s; } }
	$log = '';
	if ($op === 'invite') {
		if (!$slot) { cp_el_err('Ez a slot már nincs meg (a deal változott) – frissítsd a napot.'); }
		/* v2.9.121: véglegesített slotra nem lehet újabb embert meghívni (előbb a véglegesítést kell visszavonni) */
		foreach ($slot['asg'] as $a0) { if ($a0['st'] === 'final') { cp_el_err('Ez a slot már véglegesítve van (' . $a0['n'] . '). Új embert csak a véglegesítés visszavonása (×) után lehet meghívni.'); } }
		if (!$slot['can_inv'] && empty($_POST['force'])) { cp_el_err('Erre a rendezvényre még nem lehet meghívni (nincs kiküldött szerződés, és nem karácsonyi időszak).'); }
		$ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['kz'] ?? ''))))), 0, 10);
		if (!$ids) { cp_el_err('Válassz legalább egy kezelőt.'); }
		$n = 0;
		foreach ($ids as $kid) {
			$ex = $wpdb->get_var($wpdb->prepare("SELECT id FROM $T WHERE deal_id = %d AND day = %s AND skey = %s AND kz_id = %d AND status NOT IN ('declined','dropped')", $deal, $day, $skey, $kid));
			if ($ex) { continue; }
			$wpdb->insert($T, array('deal_id' => $deal, 'day' => $day, 'skey' => $skey, 'kz_id' => $kid, 'status' => 'invited', 'tok' => substr(bin2hex(random_bytes(12)), 0, 24), 'by_name' => $who, 'inv_uid' => function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0, 'created_at' => $now, 'updated_at' => $now));
			$n++;
		}
		$log = $n . ' meghívás';
	} elseif ($op === 'st' || $op === 'final' || $op === 'del') {
		$id = (int) ($_POST['id'] ?? 0);
		$r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE id = %d", $id), ARRAY_A);
		if (!$r || $r['day'] !== $day) { cp_el_err('Nincs ilyen meghívás.', 404); }
		if ($op === 'del') {
			if ($r['status'] === 'final' && !cp_slot_can_final()) { cp_el_err('Véglegesített beosztást csak Ákos vagy admin vonhat vissza.', 403); }
			$wpdb->delete($T, array('id' => $id)); $log = 'meghívás törölve';
		} elseif ($op === 'final') {
			if (!cp_slot_can_final()) { cp_el_err('Véglegesíteni Ákos vagy admin tud.', 403); }
			$busy = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T WHERE kz_id = %d AND day = %s AND status = 'final' AND id <> %d", (int) $r['kz_id'], $day, $id));
			if ($busy && empty($_POST['force'])) { wp_send_json_error(array('code' => 'busy', 'msg' => 'Ő erre a napra már be van osztva egy másik rendezvényre. Így is véglegesíted?')); }
			/* v2.9.121: egy slotra egy véglegesített ember – a korábbit leváltja (rákérdezés után) */
			$prev = $wpdb->get_var($wpdb->prepare("SELECT k.name FROM $T a LEFT JOIN " . cp_kz_t() . " k ON k.id = a.kz_id WHERE a.deal_id = %d AND a.day = %s AND a.skey = %s AND a.status = 'final' AND a.id <> %d LIMIT 1", (int) $r['deal_id'], $day, $r['skey'], $id));
			if ($prev !== null && empty($_POST['force'])) { wp_send_json_error(array('code' => 'busy', 'msg' => 'Erre a slotra már ' . ($prev ?: 'valaki') . ' van véglegesítve. Leváltod?')); }
			if ($prev !== null) { $wpdb->query($wpdb->prepare("UPDATE $T SET status = 'dropped', updated_at = %s WHERE deal_id = %d AND day = %s AND skey = %s AND status = 'final' AND id <> %d", $now, (int) $r['deal_id'], $day, $r['skey'], $id)); }
			$wpdb->update($T, array('status' => 'final', 'by_name' => $who, 'updated_at' => $now), array('id' => $id));
			/* v2.9.117: megerősítő levél a kezelőnek (ha van e-mail-címe) */
			$fm = cp_slot_mail((int) $id, 'final');
			if ($fm === true) { $wpdb->update($T, array('fin_mail_at' => $now), array('id' => $id)); }
			/* a slot többi jelöltje: „nem rá esett” */
			$wpdb->query($wpdb->prepare("UPDATE $T SET status = 'dropped', updated_at = %s WHERE deal_id = %d AND day = %s AND skey = %s AND id <> %d AND status IN ('invited','accepted')", $now, (int) $r['deal_id'], $day, $r['skey'], $id));
			$log = 'véglegesítve';
		} else {
			$st = sanitize_key($_POST['st'] ?? '');
			if (!in_array($st, array('invited', 'accepted', 'declined'), true)) { cp_el_err('Hibás állapot.'); }
			if ($r['status'] === 'final' && !cp_slot_can_final()) { cp_el_err('Véglegesített beosztást csak Ákos vagy admin módosíthat.', 403); }
			$note = mb_substr(sanitize_text_field(wp_unslash($_POST['note'] ?? '')), 0, 255);
			$wpdb->update($T, array('status' => $st, 'note' => $note !== '' ? $note : $r['note'], 'by_name' => $who, 'updated_at' => $now), array('id' => $id));
			$log = cp_slot_states()[$st];
		}
		$deal = (int) $r['deal_id'];
	} elseif ($op === 'swap') {
		/* v2.9.126: csere a deal adatlapjáról – a régi ember helyére az új (véglegesítettnél csak Ákos / admin, és az új is véglegesített lesz) */
		$id = (int) ($_POST['id'] ?? 0); $nk = (int) ($_POST['kz'] ?? 0);
		$r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE id = %d", $id), ARRAY_A);
		if (!$r || $r['day'] !== $day) { cp_el_err('Nincs ilyen beosztás.', 404); }
		if ($nk <= 0 || $nk === (int) $r['kz_id']) { cp_el_err('Válassz másik embert.'); }
		$fin = $r['status'] === 'final';
		if ($fin && !cp_slot_can_final()) { cp_el_err('Véglegesített beosztást csak Ákos vagy admin cserélhet.', 403); }
		$wpdb->update($T, array('status' => 'dropped', 'updated_at' => $now), array('id' => $id));
		$wpdb->insert($T, array('deal_id' => (int) $r['deal_id'], 'day' => $day, 'skey' => $r['skey'], 'kz_id' => $nk, 'status' => $fin ? 'final' : 'invited', 'tok' => substr(bin2hex(random_bytes(12)), 0, 24), 'by_name' => $who, 'inv_uid' => function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0, 'created_at' => $now, 'updated_at' => $now));
		if ($fin) { $nid = (int) $wpdb->insert_id; if (cp_slot_mail($nid, 'final') === true) { $wpdb->update($T, array('fin_mail_at' => $now), array('id' => $nid)); } }
		$deal = (int) $r['deal_id']; $skey = $r['skey'];
		$log = 'csere' . ($fin ? ' (véglegesítve)' : '');
	} elseif ($op === 'send') {
		/* v2.9.117: meghívó levelek kiküldése (a még el nem küldött meghívásokra) */
		$ids = array_slice(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? '')))), 0, 40);
		$ok = 0; $miss = array(); $err = '';
		foreach ($ids as $aid) {
			$r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE id = %d AND day = %s", $aid, $day), ARRAY_A);
			if (!$r || $r['status'] !== 'invited') { continue; }
			$res = cp_slot_mail($aid, 'invite');
			if ($res === true) { $wpdb->update($T, array('sent_at' => $now), array('id' => $aid)); $ok++; }
			elseif ($res === 'noemail') { $miss[] = $aid; }
			else { $err = (string) $res; }
		}
		if (!$ok && $err !== '') { cp_el_err('A meghívó nem ment el: ' . $err, 502); }
		$log = $ok . ' meghívó elküldve' . ($miss ? ', ' . count($miss) . ' embernek nincs e-mail-címe' : '');
		$x = cp_slot_day($day); $x['msg'] = $log . '.'; wp_send_json_success($x);
	} elseif ($op === 'need') {
		$v = ($_POST['v'] ?? '') === 'kezelo' ? 'kezelo' : (($_POST['v'] ?? '') === 'host' ? 'host' : '');
		$no = get_option('cp_slot_need', array()); if (!is_array($no)) { $no = array(); }
		$k = $deal . '|' . $day . '|' . $skey;
		if ($v === '') { unset($no[$k]); } else { $no[$k] = $v; }
		update_option('cp_slot_need', $no, false);
		$log = 'igény: ' . ($v ?: 'automatikus');
	} else { cp_el_err('Hibás kérés.'); }
	if (function_exists('cp_audit_add')) { cp_audit_add('rendszer', 'slot_' . $op, 'Beosztás', 'deal:' . $deal, $day . ' · ' . $skey . ' · ' . $log); }
	wp_send_json_success(cp_slot_day($day));
}

/* ------------------------------------------------------------ v2.9.117: meghívó / megerősítő levél és a válaszoldal */
function cp_slot_hu_day($d) {
	$hm = array('január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
	$dn = array('vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat');
	$t = strtotime($d . ' 12:00:00');
	return gmdate('Y', $t) . '. ' . $hm[(int) gmdate('n', $t) - 1] . ' ' . (int) gmdate('j', $t) . '. (' . $dn[(int) gmdate('w', $t)] . ')';
}
/* a meghívás adatai (az ügyfél neve nélkül – a kezelő a véglegesítés után a munkalapon kap részleteket) */
function cp_slot_info($aid) {
	global $wpdb;
	$r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_slot_t() . ' WHERE id = %d', (int) $aid), ARRAY_A);
	if (!$r) { return null; }
	$k = function_exists('cp_kz_t') ? $wpdb->get_row($wpdb->prepare('SELECT name, email, kind FROM ' . cp_kz_t() . ' WHERE id = %d', (int) $r['kz_id']), ARRAY_A) : null;
	$slot = null;
	foreach (cp_slot_day($r['day'])['slots'] as $s) { if ($s['deal'] === (int) $r['deal_id'] && $s['skey'] === $r['skey']) { $slot = $s; } }
	$sk = function_exists('cp_kz_skills') ? cp_kz_skills() : array();
	return array('r' => $r, 'k' => $k, 'day' => cp_slot_hu_day($r['day']), 'time' => $slot ? $slot['time'] : '', 'loc' => $slot ? $slot['loc'] : '',
		'gep' => $slot ? ($slot['kind'] === 'spin' ? '360 Spinner' : ($sk[$slot['skill']] ?? 'Szelfigép')) : '', 'need' => $slot ? $slot['need'] : '', 'url' => home_url('/?cp_slot=' . $r['tok']));
}
function cp_slot_mail($aid, $what) {
	$x = cp_slot_info($aid);
	if (!$x || !$x['k']) { return 'nincs ilyen meghívás'; }
	$em = (string) $x['k']['email'];
	if (!is_email($em)) { return 'noemail'; }
	if (!function_exists('cp_send_mailer')) { return 'a levélküldés nincs bekötve'; }
	$e = function ($v) { return esc_html((string) $v); };
	$rows = '<tr><td style="padding:4px 12px 4px 0;color:#666">Nap</td><td><b>' . $e($x['day']) . '</b></td></tr>' .
		'<tr><td style="padding:4px 12px 4px 0;color:#666">Időpont</td><td>' . ($x['time'] !== '' ? $e($x['time']) : 'még egyeztetés alatt – amint tudjuk, jelezzük') . '</td></tr>' .
		'<tr><td style="padding:4px 12px 4px 0;color:#666">Helyszín</td><td>' . ($x['loc'] !== '' ? $e($x['loc']) : 'még egyeztetés alatt') . '</td></tr>' .
		'<tr><td style="padding:4px 12px 4px 0;color:#666">Gép</td><td>' . $e($x['gep']) . '</td></tr>';
	$hi = 'Szia ' . $e(trim((string) preg_replace('/^\S+\s+/u', '', (string) $x['k']['name'])) ?: $x['k']['name']) . '!';
	if ($what === 'invite') {
		$subj = 'Ráérsz? Rendezvény ' . $x['day'];
		$body = '<p>' . $hi . '</p><p>Erre a napra keresünk embert egy rendezvényre – ráérnél?</p><table style="border-collapse:collapse;font-size:14px">' . $rows . '</table>' .
			'<p style="margin:22px 0"><a href="' . esc_url($x['url'] . '&v=igen') . '" style="background:#17663A;color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:bold;margin-right:8px">Ráérek</a> <a href="' . esc_url($x['url'] . '&v=nem') . '" style="background:#fff;color:#B42318;border:1.5px solid #B42318;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:bold">Nem érek rá</a></p>' .
			'<p style="color:#666;font-size:13px">A válaszod még nem végleges beosztás – a beosztást Ákos véglegesíti, arról külön levelet kapsz.</p>';
	} else {
		$subj = 'Beosztás véglegesítve: ' . $x['day'];
		$body = '<p>' . $hi . '</p><p><b>Be vagy osztva</b> erre a rendezvényre:</p><table style="border-collapse:collapse;font-size:14px">' . $rows . '</table>' .
			'<p style="color:#666;font-size:13px">A pontos részleteket (cím, kapcsolattartó, bepakolás) a nap közeledtével küldjük. Ha mégsem tudsz menni, minél előbb szólj Ákosnak.</p>' .
			'<p><a href="' . esc_url($x['url']) . '">A beosztásod megtekintése</a></p>';
	}
	$doc = '<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1A1F2B">' . $body . '<p style="color:#999;font-size:12px">EventCrazy · Crazy panel</p></body></html>';
	try {
		$m = cp_send_mailer();
		$m->addAddress($em, (string) $x['k']['name']);
		$m->Subject = $subj;
		$m->isHTML(true);
		$m->Body = $doc;
		$m->AltBody = trim(html_entity_decode(wp_strip_all_tags(preg_replace('#<\s*(br|/p|/tr)\b[^>]*>#i', "\n", $body)), ENT_QUOTES, 'UTF-8')) . "\n\n" . $x['url'];
		$m->XMailer = 'Crazy panel';
		$m->send();
	} catch (\Throwable $ex) { return mb_substr($ex->getMessage(), 0, 160); }
	return true;
}

/* a válaszoldal: https://eventcrazy.hu/?cp_slot=<jel>[&v=igen|nem] – belépés nélkül, csak a saját meghívás látszik */
add_action('init', function () {
	if (!isset($_GET['cp_slot'])) { return; }
	$tok = preg_replace('/[^a-f0-9]/', '', (string) $_GET['cp_slot']);
	nocache_headers();
	header('X-Robots-Tag: noindex, nofollow');
	global $wpdb;
	$r = strlen($tok) === 24 && cp_slot_db_ok() ? $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . cp_slot_t() . ' WHERE tok = %s', $tok), ARRAY_A) : null;
	$x = $r ? cp_slot_info((int) $r['id']) : null;
	$msg = '';
	if ($x && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['v'] ?? '', array('igen', 'nem'), true)) {
		$st = $x['r']['status'];
		if (in_array($st, array('invited', 'accepted', 'declined'), true)) {
			$ns = $_POST['v'] === 'igen' ? 'accepted' : 'declined';
			$note = mb_substr(sanitize_text_field(wp_unslash($_POST['note'] ?? '')), 0, 255);
			$wpdb->update(cp_slot_t(), array('status' => $ns, 'note' => $note, 'ans_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $x['r']['id']));
			/* értesítés: aki meghívta + Ákos */
			if (function_exists('cp_notify')) {
				$to = array((int) $x['r']['inv_uid']);
				if (function_exists('cp_wl_owners')) { foreach (cp_wl_owners() as $u) { $to[] = (int) $u['id']; } }
				foreach (array_unique(array_filter($to)) as $u) { cp_notify($u, 'slot_ans', ($ns === 'accepted' ? 'ráér' : 'nem ér rá') . ' – ' . $x['day'] . ($x['loc'] !== '' ? ', ' . $x['loc'] : '') . ($note !== '' ? ' („' . $note . '”)' : ''), 'naptar', (string) $x['k']['name']); }
			}
			$x = cp_slot_info((int) $x['r']['id']);
			$msg = $ns === 'accepted' ? 'Köszönjük! Jeleztük, hogy ráérsz. A végleges beosztásról külön levelet kapsz.' : 'Köszönjük, hogy szóltál! Jeleztük, hogy erre a napra nem érsz rá.';
		} else { $msg = 'Erre a meghívásra már nem lehet válaszolni (a beosztás közben véglegesítve lett).'; }
	}
	$e = function ($v) { return esc_html((string) $v); };
	$stl = array('invited' => array('Válaszra vár', '#EEF0F3', '#3A3F4B'), 'accepted' => array('Jelezted: ráérsz', '#E3F4E9', '#17663A'), 'declined' => array('Jelezted: nem érsz rá', '#FDECEA', '#B42318'), 'final' => array('Be vagy osztva ✓', '#17663A', '#fff'), 'dropped' => array('Erre a napra máshogy alakult a beosztás – köszönjük!', '#EEF0F3', '#3A3F4B'));
	echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Beosztás – EventCrazy</title>' .
		'<style>body{margin:0;background:#F5F3EF;font:16px/1.5 -apple-system,Segoe UI,Arial,sans-serif;color:#141C33}.w{max-width:480px;margin:0 auto;padding:28px 18px}.c{background:#fff;border:1px solid #E6E3DE;border-radius:16px;padding:22px}h1{font-size:21px;margin:0 0 14px}dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:0 0 18px}dt{color:#656973}dd{margin:0;font-weight:600}.st{display:inline-block;border-radius:999px;padding:4px 12px;font-weight:700;font-size:14px;margin-bottom:14px}.b{display:flex;gap:10px;flex-wrap:wrap}button{flex:1;min-width:140px;font:700 16px inherit;padding:13px 16px;border-radius:10px;cursor:pointer;border:1.5px solid #17663A;background:#17663A;color:#fff}button.no{background:#fff;color:#B42318;border-color:#B42318}input{width:100%;box-sizing:border-box;border:1px solid #E6E3DE;border-radius:10px;padding:11px 12px;font:15px inherit;margin:0 0 12px}.m{background:#E3F4E9;color:#17663A;border-radius:10px;padding:10px 12px;margin:0 0 14px;font-weight:600}.f{color:#888;font-size:13px;margin-top:14px;text-align:center}</style></head><body><div class="w"><div class="c">';
	if (!$x) {
		echo '<h1>Ez a link már nem érvényes</h1><p>Lehet, hogy a meghívást közben visszavonták. Ha kérdésed van, szólj az irodának.</p>';
	} else {
		$st = $stl[$x['r']['status']] ?? $stl['invited'];
		echo '<h1>' . ($x['r']['status'] === 'final' ? 'A beosztásod' : 'Meghívás rendezvényre') . '</h1>' . ($msg !== '' ? '<p class="m">' . $e($msg) . '</p>' : '') .
			'<span class="st" style="background:' . $st[1] . ';color:' . $st[2] . '">' . $e($st[0]) . '</span>' .
			'<dl><dt>Nap</dt><dd>' . $e($x['day']) . '</dd><dt>Időpont</dt><dd>' . ($x['time'] !== '' ? $e($x['time']) : 'még egyeztetés alatt') . '</dd><dt>Helyszín</dt><dd>' . ($x['loc'] !== '' ? $e($x['loc']) : 'még egyeztetés alatt') . '</dd><dt>Gép</dt><dd>' . $e($x['gep']) . '</dd></dl>';
		if (in_array($x['r']['status'], array('invited', 'accepted', 'declined'), true)) {
			$pre = isset($_GET['v']) && in_array($_GET['v'], array('igen', 'nem'), true) && $msg === '' ? (string) $_GET['v'] : '';
			echo '<form method="post">' . ($pre === 'nem' ? '<input type="text" name="note" maxlength="200" placeholder="Megjegyzés (nem kötelező)">' : '<input type="text" name="note" maxlength="200" placeholder="Megjegyzés (nem kötelező)">') .
				'<div class="b"><button type="submit" name="v" value="igen"' . ($pre === 'nem' ? ' style="order:2"' : '') . '>Ráérek</button><button type="submit" name="v" value="nem" class="no">Nem érek rá</button></div></form>' .
				($pre !== '' ? '<p class="f">' . ($pre === 'igen' ? 'Kattints a „Ráérek” gombra a megerősítéshez.' : 'Kattints a „Nem érek rá” gombra a megerősítéshez.') . '</p>' : '');
		}
	}
	echo '<p class="f">EventCrazy · Crazy panel</p></div></div></body></html>';
	exit;
}, 1);

/* ------------------------------------------------------------ v2.9.117: a beosztás a dealen és a kezelő adatlapján */
function cp_slot_lbl($skey) { return $skey !== '' && $skey[0] === 'p' ? '360 Spinner' : 'Szelfigép'; }
function cp_slot_for_deal($deal) {
	if (!cp_slot_db_ok() || !function_exists('cp_kz_t')) { return array(); }
	global $wpdb;
	$st = cp_slot_states(); $out = array();
	foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT a.id, a.kz_id, a.day, a.skey, a.status, k.name, k.kind FROM ' . cp_slot_t() . ' a LEFT JOIN ' . cp_kz_t() . " k ON k.id = a.kz_id WHERE a.deal_id = %d AND a.status <> 'dropped' ORDER BY a.day, a.skey, FIELD(a.status, 'final', 'accepted', 'invited', 'declined')", (int) $deal), ARRAY_A) as $r) {
		$out[] = array('id' => (int) $r['id'], 'kz' => (int) $r['kz_id'], 'sk' => (string) $r['skey'], 'd' => $r['day'], 'g' => cp_slot_lbl($r['skey']), 'n' => (string) $r['name'], 'k' => (string) $r['kind'], 'st' => (string) $r['status'], 'sl' => $st[$r['status']] ?? $r['status']);
	}
	return $out;
}
function cp_slot_for_kz($kid) {
	if (!cp_slot_db_ok()) { return array(); }
	global $wpdb;
	static $all = null;
	if ($all === null) {
		$all = array(); $st = cp_slot_states();
		$D = function_exists('cp_crm_t') ? cp_crm_t('deals') : '';
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT a.kz_id, a.day, a.skey, a.status, a.deal_id' . ($D ? ', d.title, d.venue' : '') . ' FROM ' . cp_slot_t() . ' a' . ($D ? " LEFT JOIN $D d ON d.id = a.deal_id" : '') . " WHERE a.day >= %s AND a.status IN ('invited','accepted','final') ORDER BY a.day", wp_date('Y-m-d')), ARRAY_A) as $r) {
			$all[(int) $r['kz_id']][] = array('d' => $r['day'], 'g' => cp_slot_lbl($r['skey']), 'deal' => (int) $r['deal_id'], 't' => (string) ($r['title'] ?? ''), 'v' => (string) ($r['venue'] ?? ''), 'st' => (string) $r['status'], 'sl' => $st[$r['status']] ?? $r['status']);
		}
	}
	return $all[(int) $kid] ?? array();
}
