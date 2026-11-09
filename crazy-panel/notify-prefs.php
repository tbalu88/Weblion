<?php
/* =====================================================================
 * v2.9.50: értesítés-beállítások (Beállítások → Értesítések, csak admin)
 *  – személyes értesítések: eseményenként és kollégánként külön kapcsolható a Csengő (panel), a Telefon (push) és a Pumble;
 *    alapból minden be van kapcsolva, kivéve az új ajánlatkérés Pumble-üzenetét (csak az alapértéktől eltérők tárolódnak: cp_nt_prefs[uid][kind][b|p|u])
 *  – csatorna-üzenetek (közös Pumble-csatornák): egyenként ki-be kapcsolhatók (cp_ch_off[key] = 1)
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }

/* az események: kulcs => [csoport, megnevezés, mikor jön, ki kapja, fontos] */
function cp_nt_kinds() {
	return array(
		'lead'        => array('Ajánlatkérés és deal', 'Új ajánlatkérés', 'Weboldali űrlapról vagy webes megkeresésből új ajánlatkérés érkezett.', 'minden aktív kolléga', 1),
		'iv_book'     => array('Ajánlatkérés és deal', 'Interjú-foglalás (kezelő-jelentkező)', 'Egy kezelő-jelentkező időpontot foglalt, módosított vagy lemondott az interjú-foglaló oldalon.', 'adminok (vagy a beállított kollégák)', 0),
		'lead_fail'   => array('Ajánlatkérés és deal', 'Sikertelen deal-létrehozás', 'Egy weboldali ajánlatkérésből 3 próbálkozás után sem jött létre deal – kézzel kell felvenni.', 'adminok', 1),
		'deal_note'   => array('Ajánlatkérés és deal', 'Megemlítés deal-jegyzetben', 'Valaki @Név formában megemlít egy deal jegyzetében.', 'akit megemlítenek', 0),
		'mail_assign' => array('Levelezés', 'Levélszál kiosztása', 'Valaki rád oszt egy levélszálat (egyenként vagy többet egyszerre).', 'akire kiosztják', 0),
		'mail_mention'=> array('Levelezés', 'Megemlítés levéljegyzetben', 'Valaki @Név formában megemlít egy levélszál belső jegyzetében.', 'akit megemlítenek', 0),
		'mail_follow' => array('Levelezés', 'Új levél a követett szálban', 'Az ügyfél új levelet írt egy szálba, amelyet követsz, vagy amelynek te vagy a felelőse.', 'a szál követői és felelőse', 0),
		'mail_snooze' => array('Levelezés', 'Visszatért a szundizott szál', 'Lejárt a szundi: a levélszál visszakerült a Beérkezettbe.', 'aki szundiztatta', 0),
		'kb_assign'   => array('Feladatok', 'Kártya kiosztása', 'Valaki rád oszt egy kártyát a Feladatok táblán.', 'akire kiosztják', 0),
		'kb_mention'  => array('Feladatok', 'Megemlítés kártyán', 'Valaki @Név formában megemlít egy kártya hozzászólásában.', 'akit megemlítenek', 0),
		'kb_recall'   => array('Feladatok', 'Visszahívandó ügyfél', 'Az éves visszahívó kártyát tett a táblára: tavaly ilyenkor rendezvényük volt, azóta nem jelentkeztek.', 'a tavalyi deal felelőse', 0),
		'wl_closed'   => array('Munkanapló', 'Lezárt munkanapló', 'Egy kolléga lezárta a napi munkanaplóját – jóváhagyásra vár.', 'adminok', 0),
		'wl_review'   => array('Munkanapló', 'Jóváhagyva / visszanyitva', 'Az admin jóváhagyta vagy javításra visszanyitotta a munkanaplódat.', 'a napló gazdája', 0),
		'wl_comment'  => array('Munkanapló', 'Hozzászólás a munkanaplóhoz', 'Valaki hozzászólt egy munkanaplóhoz.', 'a napló gazdája és a korábbi hozzászólók', 0),
		'wl_remind'   => array('Munkanapló', 'Emlékeztető: üres munkanapló', 'A nap végén, ha a mai munkanapló még üres.', 'akinek ma munkanaplót kell írnia', 0),
		'system'      => array('Rendszer', 'Rendszer-figyelmeztetés', 'A panel önellenőrzése hibát talált (pl. elakadt szinkron, hiányzó beállítás).', 'adminok', 1),
	);
}
function cp_nt_prefs() { $p = get_option('cp_nt_prefs', array()); return is_array($p) ? $p : array(); }
/* $ch: b = csengő, p = telefon (push), u = Pumble */
function cp_nt_default($kind, $ch) { return !(in_array($kind, array('lead', 'iv_book'), true) && $ch === 'u'); } /* az új ajánlatkérésről alapból nem megy Pumble (2026-11-08-i döntés) */
function cp_nt_on($uid, $kind, $ch) {
	$p = cp_nt_prefs();
	return isset($p[(int) $uid][$kind][$ch]) ? !empty($p[(int) $uid][$kind][$ch]) : cp_nt_default($kind, $ch);
}

/* ------------------------------------------------------------ csatorna-üzenetek */
function cp_ch_defs() {
	$sec = function ($k) { return function_exists('cp_st_secret') ? (string) cp_st_secret($k) : ''; };
	return array(
		'newdeal' => array('Új deal → Pumble', 'Minden új dealről üzenet (cím, dátum, szolgáltatás, link) – a Salesmate „Új deal → Pumble értesítés” workflow utódja.', 'lead_hook', 'Ha üres: a közös értesítési csatornába megy.'),
		'digest'  => array('Heti és havi összesítő, mérföldkövek', 'Bevétel, deal-ek és mérföldkövek összesítése (a részletek: Statisztika → Összesítők).', 'digest', ''),
		'wl'      => array('Munkanapló-riport', 'A lezárt munkanaplók összefoglalója (munkaidő, feladatok, km, költségek).', 'wl', 'Ha üres: az összesítők csatornájába megy.'),
		'setup'   => array('Kezelői setup-fotók', 'A kezelők rendezvény előtti setup-fotói és a kezelői adatlapok jelzései.', 'setup', ''),
		'portal'  => array('Ügyfélportál-jelzések', 'Hiányos adatlap a határidő után, utólagos módosítás, eltérő létszám.', 'portal', ''),
	);
}
function cp_ch_off() { $o = get_option('cp_ch_off', array()); return is_array($o) ? $o : array(); }
/* a munkanapló-riportnak és a setup-fotóknak már van saját kapcsolója – azt használjuk (egy kapcsoló, két helyen látszik) */
function cp_ch_on($key) {
	if ($key === 'wl') { return (string) get_option('cp_wl_pb', '1') !== '0'; }
	if ($key === 'setup') { return function_exists('cp_el_opts') && !empty(cp_el_opts()['pumble_setup']); }
	return empty(cp_ch_off()[$key]);
}
function cp_ch_set_on($key, $on) {
	if ($key === 'wl') { update_option('cp_wl_pb', $on ? 1 : 0, false); return; }
	if ($key === 'setup') { if (defined('CP_EL_OPT')) { $o = get_option(CP_EL_OPT, array()); if (!is_array($o)) { $o = array(); } $o['pumble_setup'] = $on ? 1 : 0; update_option(CP_EL_OPT, $o); } return; }
	$off = cp_ch_off(); if ($on) { unset($off[$key]); } else { $off[$key] = 1; } update_option('cp_ch_off', $off, false);
}
/* a csatorna webhookja (van-e) és beállítása */
function cp_ch_hook_get($key) {
	switch ($key) {
		case 'newdeal': return function_exists('cp_nt_hook_get') ? cp_nt_hook_get('lead_hook') : '';
		case 'digest': return function_exists('cp_st_dg_hook') ? (string) cp_st_dg_hook() : '';
		case 'wl': return function_exists('cp_st_secret') ? (string) cp_st_secret('wl_webhook') : '';
		case 'setup': return function_exists('cp_el_opts') ? trim((string) (cp_el_opts()['pumble_url'] ?? '')) : '';
		case 'portal': return function_exists('cp_portal_opts') ? trim((string) (cp_portal_opts()['pumble_url'] ?? '')) : '';
	}
	return '';
}
function cp_ch_hook_set($key, $url) {
	switch ($key) {
		case 'newdeal': if (function_exists('cp_nt_hook_set')) { cp_nt_hook_set('lead_hook', $url); } return;
		case 'digest': if (function_exists('cp_st_secret_set')) { cp_st_secret_set('digest_webhook_secret', $url); } return;
		case 'wl': if (function_exists('cp_st_secret_set')) { cp_st_secret_set('wl_webhook', $url); } return;
		case 'setup': if (defined('CP_EL_OPT')) { $o = get_option(CP_EL_OPT, array()); if (!is_array($o)) { $o = array(); } $o['pumble_url'] = $url; update_option(CP_EL_OPT, $o); } return;
		case 'portal': if (defined('CP_PORTAL_OPT')) { $o = get_option(CP_PORTAL_OPT, array()); if (!is_array($o)) { $o = array(); } $o['pumble_url'] = $url; update_option(CP_PORTAL_OPT, $o); } return;
	}
}
/* hová megy ténylegesen (saját webhook, tartalék, vagy sehová) */
function cp_ch_target($key) {
	if (cp_ch_hook_get($key) !== '') { return 'own'; }
	if ($key === 'newdeal' && function_exists('cp_nt_hook_get') && cp_nt_hook_get('notif_hook') !== '') { return 'fallback'; }
	if ($key === 'wl' && function_exists('cp_st_dg_hook') && cp_st_dg_hook() !== '') { return 'fallback'; }
	return 'none';
}

/* ------------------------------------------------------------ a Beállítások adatai és mentése */
function cp_nt_settings_out() {
	$users = array();
	foreach ((array) cp_pu_data()['list'] as $id => $u) { if (!empty($u['a'])) { $users[] = array('id' => (int) $id, 'n' => (string) $u['n'], 'admin' => ($u['r'] ?? '') === 'admin' ? 1 : 0); } }
	$kinds = array();
	foreach (cp_nt_kinds() as $k => $x) { $kinds[] = array('k' => $k, 'g' => $x[0], 'l' => $x[1], 'd' => $x[2], 'who' => $x[3], 'crit' => $x[4], 'admin_only' => $x[3] === 'adminok' ? 1 : 0); }
	$pref = array();
	foreach ($users as $u) { foreach (cp_nt_kinds() as $k => $x) { $pref[$u['id']][$k] = array('b' => cp_nt_on($u['id'], $k, 'b') ? 1 : 0, 'p' => cp_nt_on($u['id'], $k, 'p') ? 1 : 0, 'u' => cp_nt_on($u['id'], $k, 'u') ? 1 : 0); } }
	$ch = array();
	foreach (cp_ch_defs() as $k => $x) { $ch[] = array('k' => $k, 'l' => $x[0], 'd' => $x[1], 'fb' => $x[3], 'on' => cp_ch_on($k) ? 1 : 0, 'target' => cp_ch_target($k)); }
	$common = function_exists('cp_nt_hook_get') && cp_nt_hook_get('notif_hook') !== '';
	$own = array(); foreach ($users as $u) { $own[$u['id']] = function_exists('cp_nt_hook_get') && cp_nt_hook_get('notif_hook_u' . $u['id']) !== ''; }
	return array('users' => $users, 'kinds' => $kinds, 'pref' => $pref, 'ch' => $ch, 'common' => $common, 'own' => $own, 'push' => function_exists('cp_push_queue') ? 1 : 0);
}
/* $prefs: {uid: {kind: {b,p,u}}} – teljes táblázat (csak a kikapcsolások kerülnek tárolásra) */
function cp_nt_settings_save($prefs, $chs, $hooks) {
	$valid = cp_nt_kinds(); $uids = array(); foreach ((array) cp_pu_data()['list'] as $id => $u) { $uids[(int) $id] = 1; }
	$store = array();
	foreach ((array) $prefs as $uid => $ks) {
		if (!isset($uids[(int) $uid])) { continue; }
		foreach ((array) $ks as $k => $c) {
			if (!isset($valid[$k])) { continue; }
			foreach (array('b', 'p', 'u') as $x) { if (isset($c[$x]) && (bool) $c[$x] !== cp_nt_default($k, $x)) { $store[(int) $uid][$k][$x] = $c[$x] ? 1 : 0; } }
		}
	}
	/* v2.9.58: előbb minden webhook-címet ellenőrzünk, és csak ha mind jó, akkor mentünk (korábban hibás címnél félig mentett állapot maradt) */
	$defs = cp_ch_defs(); $todo = array();
	foreach ((array) $hooks as $k => $h) {
		if (!isset($defs[$k]) || !is_array($h)) { continue; }
		if (!empty($h['clear'])) { $todo[$k] = ''; continue; }
		$v = trim((string) ($h['v'] ?? ''));
		if ($v === '') { continue; }
		if (!cp_nt_hook_ok($v)) { return new WP_Error('cp_nt', $defs[$k][0] . ': a cím nem https://api.pumble.com/… kezdetű, semmit nem mentettem.'); }
		$todo[$k] = $v;
	}
	update_option('cp_nt_prefs', $store, false);
	foreach ($defs as $k => $x) { if (isset($chs[$k]) && (bool) $chs[$k] !== cp_ch_on($k)) { cp_ch_set_on($k, (bool) $chs[$k]); } }
	$msg = array();
	foreach ($todo as $k => $v) { cp_ch_hook_set($k, $v); $msg[] = $defs[$k][0] . ($v === '' ? ': webhook törölve.' : ': webhook elmentve.'); }
	if (function_exists('cp_audit_add')) { cp_audit_add('rendszer', 'notif', 'Értesítés-beállítások mentve', '', ''); }
	return $msg ? implode(' ', $msg) : 'Értesítések mentve.';
}
/* próbaüzenet egy csatornába (a kikapcsolt csatornába is – a próba ezt jelzi) */
function cp_ch_test($key) {
	$defs = cp_ch_defs();
	if (!isset($defs[$key])) { return new WP_Error('cp_nt', 'Ismeretlen csatorna.'); }
	$h = cp_ch_hook_get($key);
	if ($h === '' && $key === 'newdeal' && function_exists('cp_nt_hook_get')) { $h = cp_nt_hook_get('notif_hook'); }
	if ($h === '' && $key === 'wl' && function_exists('cp_st_dg_hook')) { $h = (string) cp_st_dg_hook(); }
	if ($h === '') { return new WP_Error('cp_nt', 'Ennél a csatornánál nincs webhook megadva.'); }
	$r = wp_remote_post($h, array('timeout' => 10, 'headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode(array('text' => '✅ Crazy panel – próbaüzenet: ide érkeznek a(z) „' . $defs[$key][0] . '” üzenetek.'))));
	$c = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
	return $c >= 200 && $c < 300 ? 'Próbaüzenet elküldve: ' . $defs[$key][0] . (cp_ch_on($key) ? '.' : ' (a csatorna ki van kapcsolva, élesben most nem küld).') : new WP_Error('cp_nt', 'A Pumble nem fogadta a próbaüzenetet (' . ($c ?: (is_wp_error($r) ? $r->get_error_message() : '?')) . ').');
}
