<?php
/**
 * v2.9.312: Segítség – AI-chat a panelben (hibaelhárítás és használati kérdések; fejlesztésre nem).
 * Az AI (OpenRouter) a tartalék szerveren át fut (crazy-galeria /support-chat, a meglévő kulccsal).
 * Az asszisztens csak OLVAS (a kolléga jogain belül); javítást csak JAVASOL – a kolléga gombnyomására a panel meglévő,
 * jogosultság-ellenőrzött műveletei futnak (levélszál újranyitása / visszaállítása, levél–deal kötés, levelek letöltése).
 * Nem tud: Salesmate-et írni, levelet küldeni, számlázni, törölni, beállítást vagy kódot módosítani.
 * Beszélgetések: 30 napig; napi keret kollégánként (alap 30 üzenet).
 */
if (!defined('ABSPATH')) { exit; }

define('CP_SUP_DB_VER', '1');
function cp_sup_t() { global $wpdb; return $wpdb->prefix . 'crazy_support'; }
add_action('init', function () {
	if (get_option('cp_sup_db_ver') === CP_SUP_DB_VER) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_sup_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cid varchar(24) NOT NULL DEFAULT '',
  uid int(11) NOT NULL DEFAULT 0,
  who varchar(100) NOT NULL DEFAULT '',
  role varchar(10) NOT NULL DEFAULT '',
  content mediumtext NULL,
  meta text NULL,
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY cid (cid),
  KEY uid (uid),
  KEY created_at (created_at)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_sup_db_ver', CP_SUP_DB_VER, true);
});
function cp_sup_ok() { return get_option('cp_sup_db_ver') === CP_SUP_DB_VER && function_exists('cp_booth_gal_req'); }
function cp_sup_opts() { $o = get_option('cp_sup', array()); return array_merge(array('model' => 'anthropic/claude-haiku-5.5', 'limit' => 30), is_array($o) ? $o : array()); }
function cp_sup_cfg() { return array('on' => cp_sup_ok() && function_exists('cp_pu_current_id') && cp_pu_current_id() > 0 ? 1 : 0); }

/* ------------------------------------------------------------ tudás: az útmutató (support-guide.md – élesítéskor frissítve) */
function cp_sup_guide() {
	$f = __DIR__ . '/support-guide.php';
	if (!is_readable($f)) { return ''; }
	$s = include $f;
	return is_string($s) ? $s : '';
}
function cp_sup_system() {
	$u = function_exists('cp_pu_current') ? cp_pu_current() : null;
	$tabs = function_exists('cp_panel_tabs_allowed') ? cp_panel_tabs_allowed() : array();
	$names = function_exists('cp_pu_tabs') ? cp_pu_tabs() : array();
	$lab = array(); foreach ($tabs as $t) { $lab[] = $names[$t] ?? $t; }
	return "Te a Crazy panel (az EventCrazy / CrazyPhoto belső CRM-je) segítő asszisztense vagy. Magyarul, tegezve, röviden és lépésenként válaszolsz az iroda kollégáinak.\n"
		. "FELADATOD: (1) használati kérdések megválaszolása az ÚTMUTATÓ alapján – mindig írd meg, hol találja (menü → fül → gomb); (2) hibák kivizsgálása az eszközeiddel (csak olvasol); (3) ha a panel egy meglévő javítása segít, javasold a propose_fix eszközzel – a kolléga egy gombbal hagyja jóvá, te magad semmit nem módosítasz.\n"
		. "NEM TUDSZ ÉS NEM ÍGÉRSZ: fejlesztést, új funkciót, beállítás- vagy kódmódosítást, Salesmate-adat módosítását, levélküldést ügyfélnek, számlázást, törlést, jelszót. Ilyenkor mondd meg, hogy ezt Balázs (Tóth Balázs) intézi, és ajánld fel a „Küldés Balázsnak” gombot.\n"
		. "SZABÁLYOK: Csak az útmutatóra és az eszközök eredményére támaszkodj; ha nem tudod biztosan, mondd ki, és javasold a „Küldés Balázsnak” gombot. Ne találj ki menüpontot vagy gombot. Ügyféladatot csak annyit írj vissza, amennyi a kérdéshez kell. Ha egy eszköz azt mondja, nincs jogosultság, mondd el, hogy ehhez admin kell. Rövid legyél: legfeljebb 6–8 sor, lépések számozva.\n"
		. "A kolléga: " . ($u ? $u['n'] : 'ismeretlen') . " (" . (($u['r'] ?? '') === 'admin' ? 'admin' : 'iroda') . "). Ezeket a füleket / eszközöket látja: " . implode(', ', $lab) . ".\n"
		. "Ma: " . wp_date('Y-m-d (l) H:i') . ". Panel-verzió: " . (defined('CP_PANEL_VER') ? CP_PANEL_VER : '?') . ".\n\n"
		. "=== ÚTMUTATÓ ===\n" . cp_sup_guide();
}

/* ------------------------------------------------------------ eszközök (az AI-nak) */
function cp_sup_tools() {
	$t = function ($n, $d, $p = array(), $req = array()) { $ps = array('type' => 'object', 'properties' => (object) $p); if ($req) { $ps['required'] = $req; } return array('type' => 'function', 'function' => array('name' => $n, 'description' => $d, 'parameters' => $ps)); }; /* üres „required” lista: a szolgáltató elutasítja */
	$s = array('type' => 'string'); $i = array('type' => 'integer');
	return array(
		$t('find_deal', 'Deal keresése azonosító vagy név (ügyfél / rendezvény) részlete alapján. Visszaadja: azonosító, cím, szakasz, státusz, rendezvény dátuma, törölt-e.', array('q' => $s), array('q')),
		$t('deal_threads', 'Egy dealhez kötött levélszálak (tárgy, állapot, mappa, utolsó levél).', array('deal_id' => $i), array('deal_id')),
		$t('deal_log', 'Egy deal változásnaplója a panelből (ki, mit, mikor) – csak adminnak.', array('deal_id' => $i), array('deal_id')),
		$t('find_thread', 'Levélszál keresése tárgy, feladó neve vagy e-mail-címe alapján. Visszaadja: szál-azonosító, tárgy, állapot (open/closed), mappa (inbox/archive/trash/spam/snooze…), kötött deal.', array('q' => $s), array('q')),
		$t('thread_events', 'Egy levélszál eseményei (ki zárta le, helyezte át, kötötte dealhez, mikor).', array('thread_id' => $i), array('thread_id')),
		$t('mail_sync_status', 'A levelek letöltésének (beérkezés) állapota: be van-e kapcsolva, mikor futott utoljára, fut-e most.', array('ok' => array('type' => 'string', 'description' => 'nem kötelező: miért kérdezed'))),
		$t('my_notifications', 'A kolléga saját értesítési beállításai (melyik értesítést kapja csengőben / telefonon / Pumble-ön).', array('ok' => array('type' => 'string', 'description' => 'nem kötelező: miért kérdezed'))),
		$t('propose_fix', 'Javítás JAVASLATA a kollégának (nem fut le magától; a kolléga gombbal hagyja jóvá). Műveletek: reopen_thread (lezárt szál újranyitása), restore_thread (Kukából / archívumból vissza a Beérkezettbe), link_thread_deal (szál kötése egy dealhez), unlink_thread_deal (szál leválasztása a dealről), fetch_mail_now (új levelek letöltése most).',
			array('action' => array('type' => 'string', 'enum' => array('reopen_thread', 'restore_thread', 'link_thread_deal', 'unlink_thread_deal', 'fetch_mail_now')), 'thread_id' => $i, 'deal_id' => $i, 'label' => array('type' => 'string', 'description' => 'Rövid gombfelirat, pl. „Szál újranyitása”')), array('action', 'label')),
	);
}
function cp_sup_can($tab) { return function_exists('cp_panel_can') && cp_panel_can($tab); }
function cp_sup_tool($name, $a, &$fixes) {
	global $wpdb;
	$a = is_array($a) ? $a : array();
	switch ($name) {
		case 'find_deal':
			if (!cp_sup_can('deals')) { return array('hiba' => 'nincs jogosultság a Deal-ekhez'); }
			$q = trim((string) ($a['q'] ?? '')); if ($q === '') { return array('hiba' => 'üres keresés'); }
			$D = cp_crm_t('deals');
			$w = ctype_digit($q) ? $wpdb->prepare('id = %d', (int) $q) : $wpdb->prepare('title LIKE %s', '%' . $wpdb->esc_like($q) . '%');
			return array('dealek' => (array) $wpdb->get_results("SELECT id, title, stage, status, event_d, IF(deleted_at IS NULL, 0, 1) AS torolt FROM $D WHERE $w ORDER BY event_d DESC LIMIT 8", ARRAY_A));
		case 'deal_threads':
			if (!cp_sup_can('mail')) { return array('hiba' => 'nincs jogosultság a Levelezéshez'); }
			return array('szalak' => (array) $wpdb->get_results($wpdb->prepare('SELECT id, subject, status, folder, last_at, msg_count FROM ' . cp_mail_t('threads') . ' WHERE deal_id = %d ORDER BY last_at DESC LIMIT 15', (int) ($a['deal_id'] ?? 0)), ARRAY_A));
		case 'deal_log':
			if (!function_exists('cp_panel_is_admin') || !cp_panel_is_admin()) { return array('hiba' => 'a deal változásnaplója csak adminnak érhető el'); }
			if (!function_exists('cp_audit_t')) { return array('hiba' => 'a napló nem érhető el'); }
			return array('naplo' => (array) $wpdb->get_results($wpdb->prepare('SELECT created_at, who, label, LEFT(summary, 160) AS reszlet FROM ' . cp_audit_t() . ' WHERE obj = %s ORDER BY id DESC LIMIT 20', 'deal:' . (int) ($a['deal_id'] ?? 0)), ARRAY_A));
		case 'find_thread':
			if (!cp_sup_can('mail')) { return array('hiba' => 'nincs jogosultság a Levelezéshez'); }
			$q = trim((string) ($a['q'] ?? '')); if ($q === '') { return array('hiba' => 'üres keresés'); }
			$T = cp_mail_t('threads'); $like = '%' . $wpdb->esc_like($q) . '%';
			$w = ctype_digit($q) ? $wpdb->prepare('t.id = %d', (int) $q) : $wpdb->prepare('(t.subject LIKE %s OR t.contact_email LIKE %s OR t.contact_name LIKE %s)', $like, $like, $like);
			return array('szalak' => (array) $wpdb->get_results("SELECT t.id, t.subject, t.contact_name, t.status, t.folder, t.deal_id, d.title AS deal, t.last_at FROM $T t LEFT JOIN " . cp_crm_t('deals') . " d ON d.id = t.deal_id WHERE $w ORDER BY t.last_at DESC LIMIT 8", ARRAY_A));
		case 'thread_events':
			if (!cp_sup_can('mail')) { return array('hiba' => 'nincs jogosultság a Levelezéshez'); }
			return array('esemenyek' => (array) $wpdb->get_results($wpdb->prepare('SELECT created_at, author, kind, text FROM ' . cp_mail_t('events') . ' WHERE thread_id = %d ORDER BY id DESC LIMIT 20', (int) ($a['thread_id'] ?? 0)), ARRAY_A));
		case 'mail_sync_status':
			$sy = get_option('cp_imp_sync', array()); $sy = is_array($sy) ? $sy : array();
			return array('bekapcsolva' => get_option('cp_imp_sync_on') === '1', 'utolso_ellenorzes' => !empty($sy['last']) ? wp_date('Y-m-d H:i', (int) $sy['last']) : 'még nem futott', 'most_fut' => (bool) get_transient('cp_imp_sync_lock'), 'utemezve' => (bool) wp_next_scheduled('cp_imp_sync'), 'megjegyzes' => '5 percenként néz új levelet; a Levelezés ↻ gombja azonnal letölti.');
		case 'my_notifications':
			if (!function_exists('cp_nt_kinds') || !function_exists('cp_nt_on')) { return array('hiba' => 'nem érhető el'); }
			$uid = (int) cp_pu_current_id(); $o = array();
			foreach (cp_nt_kinds() as $k => $x) { $o[] = array('ertesites' => $x[1], 'csengo' => cp_nt_on($uid, $k, 'b'), 'telefon' => cp_nt_on($uid, $k, 'p'), 'pumble' => cp_nt_on($uid, $k, 'u')); }
			return array('beallitasok' => $o, 'hol' => 'Beállítások → Értesítések (admin állítja), a telefonos értesítés a saját eszközön kapcsolható be.');
		case 'propose_fix':
			$act = (string) ($a['action'] ?? ''); $tid = (int) ($a['thread_id'] ?? 0); $did = (int) ($a['deal_id'] ?? 0);
			$need = array('reopen_thread' => array('t'), 'restore_thread' => array('t'), 'link_thread_deal' => array('t', 'd'), 'unlink_thread_deal' => array('t'), 'fetch_mail_now' => array());
			if (!isset($need[$act])) { return array('hiba' => 'ismeretlen művelet'); }
			if (!cp_sup_can('mail')) { return array('hiba' => 'a kollégának nincs jogosultsága a Levelezéshez – ezt admin tudja megcsinálni'); }
			if (in_array('t', $need[$act], true) && !$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_mail_t('threads') . ' WHERE id = %d', $tid))) { return array('hiba' => 'nincs ilyen levélszál'); }
			if (in_array('d', $need[$act], true) && !$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_crm_t('deals') . ' WHERE id = %d AND deleted_at IS NULL', $did))) { return array('hiba' => 'nincs ilyen (élő) deal'); }
			$fixes[] = array('action' => $act, 'thread_id' => $tid, 'deal_id' => $did, 'label' => mb_substr(sanitize_text_field((string) ($a['label'] ?? 'Javítás')), 0, 60));
			return array('ok' => 'a javaslat gombként megjelent a kollégánál; jóváhagyás után a panel elvégzi');
	}
	return array('hiba' => 'ismeretlen eszköz');
}

/* ------------------------------------------------------------ beszélgetés */
function cp_sup_auth() {
	cp_el_auth();
	if (!cp_sup_ok() || !function_exists('cp_pu_current_id') || cp_pu_current_id() <= 0) { cp_el_err('A Segítség személyes fiókkal érhető el.', 403); }
}
function cp_sup_cid($c) { $c = preg_replace('/[^a-z0-9]/', '', strtolower((string) $c)); return strlen($c) >= 8 ? substr($c, 0, 24) : ''; }
function cp_sup_rows($cid) { global $wpdb; return (array) $wpdb->get_results($wpdb->prepare('SELECT uid, who, role, content, meta, created_at FROM ' . cp_sup_t() . ' WHERE cid = %s ORDER BY id', $cid), ARRAY_A); }
function cp_sup_owner_ok($rows) {
	if (!$rows) { return true; }
	$me = (int) cp_pu_current_id();
	return (int) $rows[0]['uid'] === $me || (function_exists('cp_panel_is_admin') && cp_panel_is_admin());
}
function cp_sup_out($rows) {
	return array_map(function ($r) { $m = $r['meta'] ? json_decode($r['meta'], true) : array(); return array('role' => $r['role'], 'text' => (string) $r['content'], 'who' => $r['who'], 'at' => function_exists('cp_crm_local') ? cp_crm_local((string) $r['created_at']) : $r['created_at'], 'fixes' => is_array($m) && !empty($m['fixes']) ? $m['fixes'] : array(), 'done' => is_array($m) && !empty($m['done']) ? $m['done'] : null); }, $rows);
}
add_action('wp_ajax_nopriv_cp_sup_hist', 'cp_sup_ajax_hist');
add_action('wp_ajax_cp_sup_hist', 'cp_sup_ajax_hist');
function cp_sup_ajax_hist() {
	cp_sup_auth();
	$cid = cp_sup_cid($_POST['cid'] ?? ''); $rows = $cid ? cp_sup_rows($cid) : array();
	if (!cp_sup_owner_ok($rows)) { cp_el_err('Ez a beszélgetés nem a tiéd.', 403); }
	global $wpdb; $me = (int) cp_pu_current_id();
	$used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_sup_t() . " WHERE uid = %d AND role = 'user' AND created_at >= %s", $me, get_gmt_from_date(wp_date('Y-m-d 00:00:00'))));
	wp_send_json_success(array('cid' => $cid, 'rows' => cp_sup_out($rows), 'left' => max(0, (int) cp_sup_opts()['limit'] - $used), 'owner' => $rows ? (string) $rows[0]['who'] : ''));
}
add_action('wp_ajax_nopriv_cp_sup_send', 'cp_sup_ajax_send');
add_action('wp_ajax_cp_sup_send', 'cp_sup_ajax_send');
function cp_sup_ajax_send() {
	cp_sup_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (function_exists('set_time_limit')) { @set_time_limit(180); }
	global $wpdb;
	$me = (int) cp_pu_current_id(); $who = function_exists('cp_panel_who') ? cp_panel_who() : '';
	$msg = trim(mb_substr(sanitize_textarea_field(wp_unslash($_POST['msg'] ?? '')), 0, 2000));
	if ($msg === '') { cp_el_err('Írd le, miben segíthetek.'); }
	$cid = cp_sup_cid($_POST['cid'] ?? ''); if ($cid === '') { $cid = strtolower(wp_generate_password(16, false)); }
	$rows = cp_sup_rows($cid);
	if (!cp_sup_owner_ok($rows) || ($rows && (int) $rows[0]['uid'] !== $me)) { cp_el_err('Ebbe a beszélgetésbe nem írhatsz – kezdj újat.', 403); }
	$o = cp_sup_opts();
	$used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_sup_t() . " WHERE uid = %d AND role = 'user' AND created_at >= %s", $me, get_gmt_from_date(wp_date('Y-m-d 00:00:00'))));
	if ($used >= (int) $o['limit']) { cp_el_err('Mára elfogyott a keret (' . (int) $o['limit'] . ' kérdés). Ha sürgős, használd a „Küldés Balázsnak” gombot.', 429); }
	/* 30 napnál régebbi beszélgetések törlése */
	$wpdb->query($wpdb->prepare('DELETE FROM ' . cp_sup_t() . ' WHERE created_at < %s', gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)));
	$now = gmdate('Y-m-d H:i:s');
	$wpdb->insert(cp_sup_t(), array('cid' => $cid, 'uid' => $me, 'who' => $who, 'role' => 'user', 'content' => $msg, 'created_at' => $now));
	$M = array(array('role' => 'system', 'content' => cp_sup_system()));
	foreach (array_slice($rows, -16) as $r) { if (in_array($r['role'], array('user', 'assistant'), true) && $r['content'] !== '') { $M[] = array('role' => $r['role'], 'content' => (string) $r['content']); } }
	$M[] = array('role' => 'user', 'content' => $msg);
	$fixes = array(); $text = ''; $cost = 0.0; $err = '';
	for ($round = 0; $round < 6; $round++) {
		$r = cp_booth_gal_req('POST', '/support-chat', array('j' => wp_json_encode(array('model' => $o['model'], 'messages' => $M, 'tools' => cp_sup_tools()))), 60);
		if (is_wp_error($r)) { $err = $r->get_error_message(); break; }
		$cost += (float) ($r['cost'] ?? 0);
		$am = is_array($r['message'] ?? null) ? $r['message'] : array();
		$tc = !empty($am['tool_calls']) && is_array($am['tool_calls']) ? array_slice($am['tool_calls'], 0, 6) : array();
		if (!$tc) { $text = trim((string) ($am['content'] ?? '')); break; }
		$M[] = array('role' => 'assistant', 'content' => (string) ($am['content'] ?? ''), 'tool_calls' => $tc);
		foreach ($tc as $c) {
			$fn = (string) ($c['function']['name'] ?? ''); $args = json_decode((string) ($c['function']['arguments'] ?? '{}'), true);
			$res = cp_sup_tool($fn, $args, $fixes);
			$M[] = array('role' => 'tool', 'tool_call_id' => (string) ($c['id'] ?? ''), 'content' => mb_substr(wp_json_encode($res, JSON_UNESCAPED_UNICODE), 0, 6000));
		}
	}
	if ($text === '') { $text = $err !== '' ? 'Most nem érem el az AI-t (' . $err . '). Próbáld újra pár perc múlva, vagy küldd el Balázsnak.' : 'Ezt most nem tudtam kideríteni – küldd el Balázsnak a lenti gombbal.'; }
	$fixes = array_slice($fixes, 0, 4);
	$wpdb->insert(cp_sup_t(), array('cid' => $cid, 'uid' => $me, 'who' => 'Segítség', 'role' => 'assistant', 'content' => mb_substr($text, 0, 8000), 'meta' => wp_json_encode(array('fixes' => $fixes, 'cost' => round($cost, 5))), 'created_at' => gmdate('Y-m-d H:i:s')));
	wp_send_json_success(array('cid' => $cid, 'rows' => cp_sup_out(cp_sup_rows($cid)), 'left' => max(0, (int) $o['limit'] - $used - 1)));
}
/* a kolléga jóváhagyott (és a böngészőből a meglévő végponttal lefuttatott) egy javítást – naplózás + jelölés */
add_action('wp_ajax_nopriv_cp_sup_fixed', 'cp_sup_ajax_fixed');
add_action('wp_ajax_cp_sup_fixed', 'cp_sup_ajax_fixed');
function cp_sup_ajax_fixed() {
	cp_sup_auth();
	global $wpdb;
	$cid = cp_sup_cid($_POST['cid'] ?? ''); $k = (int) ($_POST['k'] ?? -1); $ok = !empty($_POST['ok']); $note = mb_substr(sanitize_text_field(wp_unslash($_POST['note'] ?? '')), 0, 200);
	$row = $cid ? $wpdb->get_row($wpdb->prepare('SELECT id, uid, meta FROM ' . cp_sup_t() . " WHERE cid = %s AND role = 'assistant' ORDER BY id DESC LIMIT 1", $cid), ARRAY_A) : null;
	if (!$row || (int) $row['uid'] !== (int) cp_pu_current_id()) { cp_el_err('Nincs ilyen javaslat.', 404); }
	$m = json_decode((string) $row['meta'], true); $m = is_array($m) ? $m : array();
	if (!isset($m['fixes'][$k])) { cp_el_err('Nincs ilyen javaslat.', 404); }
	$m['done'][$k] = $ok ? 'ok' : ('hiba: ' . $note);
	$wpdb->update(cp_sup_t(), array('meta' => wp_json_encode($m)), array('id' => (int) $row['id']));
	if (function_exists('cp_audit_add')) { cp_audit_add('rendszer', 'sup_fix', 'Segítség: javítás ' . ($ok ? 'elvégezve' : 'sikertelen'), $m['fixes'][$k]['thread_id'] ? 'mail:' . (int) $m['fixes'][$k]['thread_id'] : '', $m['fixes'][$k]['label'] . ($ok ? '' : ' – ' . $note)); }
	wp_send_json_success(array('ok' => 1));
}
/* „Küldés Balázsnak”: értesítés (csengő + telefon + Pumble) a beszélgetés linkjével */
function cp_sup_admin_uids() {
	$out = array(); $all = array();
	if (!function_exists('cp_pu_data')) { return $out; }
	foreach (cp_pu_data()['list'] as $id => $u) {
		if (empty($u['a'])) { continue; }
		if (preg_match('/tóth\s+balázs|balázs\s+tóth/iu', (string) $u['n'])) { $out[] = (int) $id; }
		if (($u['r'] ?? '') === 'admin') { $all[] = (int) $id; }
	}
	return $out ?: $all;
}
add_action('wp_ajax_nopriv_cp_sup_escalate', 'cp_sup_ajax_escalate');
add_action('wp_ajax_cp_sup_escalate', 'cp_sup_ajax_escalate');
function cp_sup_ajax_escalate() {
	cp_sup_auth();
	$cid = cp_sup_cid($_POST['cid'] ?? ''); $rows = $cid ? cp_sup_rows($cid) : array();
	if (!$rows || (int) $rows[0]['uid'] !== (int) cp_pu_current_id()) { cp_el_err('Előbb írd le a problémát a chatben.'); }
	$first = ''; foreach ($rows as $r) { if ($r['role'] === 'user') { $first = (string) $r['content']; break; } }
	$note = trim(mb_substr(sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')), 0, 300));
	$text = 'segítséget kér: „' . mb_substr($first, 0, 220) . '”' . ($note !== '' ? ' – ' . $note : '');
	$n = 0; foreach (cp_sup_admin_uids() as $uid) { if (function_exists('cp_notify')) { cp_notify($uid, 'support', $text, 'sugo:' . $cid); $n++; } }
	global $wpdb; $wpdb->insert(cp_sup_t(), array('cid' => $cid, 'uid' => (int) cp_pu_current_id(), 'who' => 'Segítség', 'role' => 'note', 'content' => 'Elküldve Balázsnak' . ($note !== '' ? ': ' . $note : '') . '.', 'created_at' => gmdate('Y-m-d H:i:s')));
	if (!$n) { cp_el_err('Nem találtam címzettet – szólj Balázsnak közvetlenül.'); }
	wp_send_json_success(array('rows' => cp_sup_out(cp_sup_rows($cid))));
}
