<?php
/**
 * Crazy panel – könnyített „változott-e valami?” végpont (v2.9.59, P1).
 *
 * A panel minden nyitott ablaka percenként rákérdezett: értesítés-számláló (cp_nt_list), Deal-változás (cp_crm_stamp),
 * nyitott levéllistánál a teljes lista (cp_mail_threads). Mindegyik teljes WordPress-betöltés volt az összes bővítménnyel.
 * Helyette egy kérés (cp_ping), amely a mu-plugins/cp-ping.php betöltőn át a bővítmények betöltése ELŐTT válaszol
 * (csak a WordPress magja, az adatbázis és a beállítások kellenek), és csak kis jelzőket ad vissza:
 *   nt   – az olvasatlan értesítések száma (személyes fióknál)
 *   mail – a Beérkezett olvasatlan nyitott szálai (közös, 30 mp-es gyorsítótár – ugyanaz, mint cp_nt_mail_unread)
 *   crm  – {k, n, ln}: pontosan a cp_crm_stamp válasza (változáskulcs + deal- és megkeresés-szám)
 *   ms   – a levélszálak változás-ujjlenyomata (csak ha a kérés ms=1-et küld, azaz a levéllista nyitva van)
 * A teljes adatot a felület csak akkor kéri le, ha valamelyik jelző változott.
 *
 * Belépés: ugyanaz a süti- és CSRF-ellenőrzés, mint a panelben (cp_pu_current_id / cp_panel_role / cp_panel_csrf) –
 * itt önállóan megírva, mert a bővítmény függvényei a gyors úton még nincsenek betöltve. Ha a betöltő nincs fent,
 * ugyanez a végpont a rendes admin-ajax úton is működik (lent: wp_ajax_cp_ping), csak lassabban.
 * Ügyféladatot nem ad ki: csak számokat és ujjlenyomatot.
 */
if (!defined('ABSPATH')) { exit; }

if (!function_exists('cp_ping_salt')) {

	/* wp_salt() a pluggable.php-ban van, amely a gyors úton még nincs betöltve; a függvényei function_exists-védettek,
	   és a gyors út a válasz után kilép, így a később betöltődő bővítmények felülírásai nem sérülnek */
	function cp_ping_salt($scheme) {
		if (!function_exists('wp_salt')) { require_once ABSPATH . WPINC . '/pluggable.php'; }
		return wp_salt($scheme);
	}

	/* a belépett fiók: array(uid, role) vagy null – a cp_pu_current_id + cp_panel_role logikája */
	function cp_ping_auth() {
		$ck = isset($_COOKIE['cp_panel_auth']) ? (string) wp_unslash($_COOKIE['cp_panel_auth']) : '';
		if ($ck === '') { return null; }
		$t = isset($_REQUEST['t']) && is_string($_REQUEST['t']) ? (string) wp_unslash($_REQUEST['t']) : '';
		if (!hash_equals(substr(hash_hmac('sha256', 'csrf|' . $ck, cp_ping_salt('nonce')), 0, 32), $t)) { return null; }
		$p = explode('|', $ck);
		/* személyes fiók: „lejárat|u|azonosító|aláírás” */
		if (count($p) === 4 && $p[1] === 'u' && (int) $p[0] >= time()) {
			$d = get_option('cp_panel_users', array());
			$u = is_array($d) && isset($d['list'][(int) $p[2]]) ? $d['list'][(int) $p[2]] : null;
			if ($u && !empty($u['a']) && (string) $u['h'] !== '') {
				$sig = hash_hmac('sha256', (int) $p[0] . '|u|' . (int) $p[2] . '|' . (int) $u['v'] . '|' . substr((string) $u['h'], 0, 20), cp_ping_salt('auth'));
				if (hash_equals($sig, $p[3])) { return array((int) $p[2], $u['r'] === 'admin' ? 'admin' : 'iroda'); }
			}
		}
		/* közös jelszó (ha engedélyezett): „lejárat|aláírás” (admin) vagy „lejárat|iroda|aláírás” */
		if (get_option('cp_panel_shared_off', '0') === '1') { return null; }
		$exp = (int) $p[0];
		if ($exp < time()) { return null; }
		$o = get_option('cp_panel_settings', array());
		if (!is_array($o)) { $o = array(); }
		$o = array_merge(array('pw_hash' => '', 'pw_ver' => '1', 'pw_hash_office' => '', 'pw_ver_office' => '1'), $o);
		if (count($p) === 2 && $o['pw_hash'] !== '' && hash_equals(hash_hmac('sha256', $exp . '|' . $o['pw_ver'] . '|' . substr($o['pw_hash'], 0, 20), cp_ping_salt('auth')), $p[1])) { return array(0, 'admin'); }
		if (count($p) === 3 && $p[1] === 'iroda' && $o['pw_hash_office'] !== '' && hash_equals(hash_hmac('sha256', $exp . '|iroda|' . $o['pw_ver_office'] . '|' . substr($o['pw_hash_office'], 0, 20), cp_ping_salt('auth')), $p[2])) { return array(0, 'iroda'); }
		return null;
	}

	/* fül-jogosultság: a cp_pu_can_uid logikája (egyedi beállítás, különben az alap: ezek a fülek mindenkinek látszanak) */
	function cp_ping_can($uid, $tabs) {
		$v = get_option('cp_panel_vis', array());
		if (!is_array($v)) { $v = array(); }
		foreach ((array) $tabs as $tab) {
			if ($uid > 0 && isset($v[$uid][$tab])) { if ($v[$uid][$tab]) { return true; } continue; }
			return true;
		}
		return false;
	}

	/* a jelzők; $fresh: a levél-számláló gyorsítótára nélkül; $ms: a levélszálak ujjlenyomata is kell */
	function cp_ping_data($uid, $fresh, $ms) {
		global $wpdb;
		$out = array('v' => 1);
		$se = $wpdb->suppress_errors(true);
		$P = $wpdb->prefix;
		/* értesítések (csak személyes fióknak; a táblán uid index van) */
		if ($uid > 0 && get_option('cp_nt_db_ver')) {
			$n = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$P}crazy_notifs WHERE uid = %d AND read_at IS NULL", $uid));
			if ($n !== null) { $out['nt'] = (int) $n; }
		}
		/* Levelezés (a cp_nt_mail_unread közös kulcsával) */
		if (get_option('cp_mail_db_ver') && cp_ping_can($uid, array('mail'))) {
			$c = $fresh ? false : get_transient('cp_nt_mailn');
			if ($c === false) {
				$c = $wpdb->get_var("SELECT COUNT(*) FROM {$P}crazy_mail_threads WHERE folder = 'inbox' AND status = 'open' AND (seen_at IS NULL OR seen_at < last_at)");
				if ($c !== null) { set_transient('cp_nt_mailn', (int) $c, 30); }
			}
			if ($c !== null && $c !== false) { $out['mail'] = (int) $c; }
			if ($ms) {
				/* minden, ami a levéllistán látszik (mappa, állapot, olvasottság, felelős, címkék, darabszám, utolsó levél) */
				$r = $wpdb->get_row("SELECT COUNT(*) c, MAX(id) m, BIT_XOR(CRC32(CONCAT_WS('|', id, folder, status, seen_at, last_at, assignee_uid, tags, msg_count, deal_id, followers))) x FROM {$P}crazy_mail_threads", ARRAY_A);
				if ($r) { $out['ms'] = md5(implode('|', $r)); }
			}
		}
		/* Deal-ek: a cp_crm_ajax_stamp válasza (ugyanazzal a gyorsítótár-beállítással) */
		$st = get_option('cp_crm_state', array());
		if (get_option('cp_crm_db_ver') && is_array($st) && !empty($st['deal_n']) && cp_ping_can($uid, array('deals', 'kapcsolatok', 'mail', 'feladatok', 'naptar', 'kovetes', 'hatterek', 'munkalap', 'elszamolas', 'ellenorzes', 'tarca'))) {
			$m = (string) $wpdb->get_var("SELECT MAX(modified_at) FROM {$P}crazy_crm_deals");
			$lm = (int) $wpdb->get_var("SELECT MAX(id) FROM {$P}crazy_leads");
			$k = md5((string) get_option('cp_crm_rev', '0') . '|' . $m . '|' . $lm);
			$c = get_option('cp_crm_stamp_c');
			if (!is_array($c) || ($c['k'] ?? '') !== $k) {
				$c = array('k' => $k, 'n' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$P}crazy_crm_deals WHERE deleted_at IS NULL"), 'ln' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$P}crazy_leads"));
				update_option('cp_crm_stamp_c', $c, false);
			}
			$out['crm'] = array('k' => $k, 'n' => (int) $c['n'], 'ln' => (int) $c['ln']);
		}
		$wpdb->suppress_errors($se);
		return $out;
	}

	function cp_ping_send($code, $body) {
		if (!headers_sent()) {
			status_header($code);
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private');
			header('X-Robots-Tag: noindex');
		}
		echo json_encode($body);
		exit;
	}

	/* a gyors út (mu-plugins/cp-ping.php hívja) és a rendes admin-ajax út közös kiszolgálója */
	function cp_ping_serve($lite) {
		$a = cp_ping_auth();
		if (!$a) { cp_ping_send(403, array('success' => false, 'data' => array('msg' => 'auth'))); }
		$d = cp_ping_data($a[0], !empty($_REQUEST['fresh']), !empty($_REQUEST['ms']));
		$d['lite'] = $lite ? 1 : 0;
		cp_ping_send(200, array('success' => true, 'data' => $d));
	}
}

/* tartalék: ha a gyors betöltő nincs fent, a rendes AJAX-úton (teljes WordPress) válaszol ugyanígy */
if (function_exists('add_action')) {
	add_action('wp_ajax_cp_ping', function () { cp_ping_serve(false); });
	add_action('wp_ajax_nopriv_cp_ping', function () { cp_ping_serve(false); });
}
