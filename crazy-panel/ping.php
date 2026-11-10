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

	/* v2.9.88: panelhasználat – a kérés egy „nyitott” perc (a felület csak látható ablakból küldi vis=1-gyel), act=1 esetén
	   „aktív” is (volt egér/billentyű/görgetés/érintés az előző jel óta). Percenként egy sor (uid, nap, perc) – több ablak sem
	   számít duplán. g = az előző jel óta eltelt mp: ha a böngésző időzítője átugrott egy percet, a kimaradt perc is bekerül.
	   Lásd usage.php. */
	function cp_ping_track($uid) {
		$ver = (string) get_option('cp_us_db_ver');
		if ($uid <= 0 || ($ver !== '1' && $ver !== '2')) { return; }
		/* v2.9.93 (DB 2): melyik fülön (tab), min (obj: mail:12 / deal:345), és hogyan (st: reply = épp levelet ír) */
		$ctx = array();
		if ($ver === '2') {
			$tab = isset($_REQUEST['tab']) ? strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $_REQUEST['tab'])) : '';
			$obj = isset($_REQUEST['obj']) && preg_match('/^(mail|deal):\d{1,10}$/', (string) $_REQUEST['obj']) ? (string) $_REQUEST['obj'] : '';
			$st = isset($_REQUEST['ost']) && $_REQUEST['ost'] === 'reply' ? 'reply' : '';
			$ctx = array('tab' => substr($tab, 0, 20), 'obj' => $obj, 'st' => $st);
		}
		global $wpdb;
		$T = $wpdb->prefix . 'crazy_usage'; $TD = $wpdb->prefix . 'crazy_usage_day';
		$dt = new DateTime('now', wp_timezone());
		$d = $dt->format('Y-m-d'); $m = (int) $dt->format('G') * 60 + (int) $dt->format('i');
		$act = !empty($_REQUEST['act']) ? 1 : 0;
		$mins = array($m);
		$g = (int) ($_REQUEST['g'] ?? 0);
		if ($g > 0 && $g <= 130 && $m >= 2) {
			$last = $wpdb->get_var($wpdb->prepare("SELECT MAX(m) FROM $T WHERE uid = %d AND d = %s AND m < %d", $uid, $d, $m));
			if ($last !== null && (int) $last === $m - 2) { $mins[] = $m - 1; }
		}
		foreach ($mins as $x) {
			$n = $ctx
				? $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $T (uid, d, m, act, tab, obj, st) VALUES (%d, %s, %d, %d, %s, %s, %s)", $uid, $d, $x, $act, $ctx['tab'], $ctx['obj'], $ctx['st']))
				: $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $T (uid, d, m, act) VALUES (%d, %s, %d, %d)", $uid, $d, $x, $act));
			/* ugyanabban a percben újabb jel (másik ablak / fül): a legutóbbi helyzet számít */
			if ($n === 0 && $ctx && $x === $m && $ctx['tab'] !== '') { $wpdb->query($wpdb->prepare("UPDATE $T SET tab = %s, obj = %s, st = %s WHERE uid = %d AND d = %s AND m = %d", $ctx['tab'], $ctx['obj'], $ctx['st'], $uid, $d, $x)); }
			if ($n === 1) {
				$wpdb->query($wpdb->prepare("INSERT INTO $TD (uid, d, open_m, act_m, first_m, last_m) VALUES (%d, %s, 1, %d, %d, %d) ON DUPLICATE KEY UPDATE open_m = open_m + 1, act_m = act_m + VALUES(act_m), first_m = LEAST(first_m, VALUES(first_m)), last_m = GREATEST(last_m, VALUES(last_m))", $uid, $d, $act, $x, $x));
			} elseif ($act && $n === 0) {
				if ($wpdb->query($wpdb->prepare("UPDATE $T SET act = 1 WHERE uid = %d AND d = %s AND m = %d AND act = 0", $uid, $d, $x)) === 1) {
					$wpdb->query($wpdb->prepare("UPDATE $TD SET act_m = act_m + 1 WHERE uid = %d AND d = %s", $uid, $d));
				}
			}
		}
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
		/* vis=0: rejtett ablak. A 2.9.88 előtti (még nem frissített) felület nem küld vis-t, de ő is csak látható ablakból kérdez percenként
		   – azt nyitott percként számoljuk (aktivitás nélkül), így a frissítésig sem marad ki senki */
		if ((string) ($_REQUEST['vis'] ?? '1') !== '0') { $se = $GLOBALS['wpdb']->suppress_errors(true); cp_ping_track($a[0]); $GLOBALS['wpdb']->suppress_errors($se); }
		$d['lite'] = $lite ? 1 : 0;
		cp_ping_send(200, array('success' => true, 'data' => $d));
	}
}

/* tartalék: ha a gyors betöltő nincs fent, a rendes AJAX-úton (teljes WordPress) válaszol ugyanígy */
if (function_exists('add_action')) {
	add_action('wp_ajax_cp_ping', function () { cp_ping_serve(false); });
	add_action('wp_ajax_nopriv_cp_ping', function () { cp_ping_serve(false); });
}
