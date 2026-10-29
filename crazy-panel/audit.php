<?php
/* =====================================================================
 * Napló (v1.46) – ki, mikor, mit csinált a panelben. Csak az adminok láthatják.
 * Minden sikeres, módosító panelművelet (deal, levelezés, feladatok, elszámolás, tárca, beállítások) és a be-/kilépés
 * egy helyre kerül. A naplót a műveletek nem tudják módosítani; 2 év után automatikusan törlődik.
 * Jelszó, belépési kulcs és hosszú szöveg nem kerül a naplóba (a jegyzetekből csak az eleje).
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_AUDIT_DB_VER', '1');
function cp_audit_t() { global $wpdb; return $wpdb->prefix . 'crazy_audit'; }

add_action('init', function () {
	if (get_option('cp_audit_db_ver') === CP_AUDIT_DB_VER || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_audit_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uid int(11) NULL,
  who varchar(100) NOT NULL DEFAULT '',
  role varchar(10) NOT NULL DEFAULT '',
  area varchar(20) NOT NULL DEFAULT '',
  action varchar(40) NOT NULL DEFAULT '',
  label varchar(120) NOT NULL DEFAULT '',
  obj varchar(40) NOT NULL DEFAULT '',
  summary text NULL,
  ip_hash varchar(16) NOT NULL DEFAULT '',
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY uid (uid),
  KEY area (area)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_audit_db_ver', CP_AUDIT_DB_VER, false);
}, 7);
function cp_audit_db_ok() { return get_option('cp_audit_db_ver') === CP_AUDIT_DB_VER; }

function cp_audit_add($area, $action, $label, $obj, $summary, $uid = null, $who = null, $role = null) {
	global $wpdb;
	if (!cp_audit_db_ok()) { return; }
	$wpdb->insert(cp_audit_t(), array(
		'uid' => $uid !== null ? (int) $uid : (function_exists('cp_pu_current_id') ? ((int) cp_pu_current_id() ?: null) : null),
		'who' => mb_substr($who !== null ? $who : (function_exists('cp_panel_who') ? cp_panel_who() : ''), 0, 100),
		'role' => mb_substr($role !== null ? $role : (function_exists('cp_panel_role') ? (string) cp_panel_role() : ''), 0, 10),
		'area' => $area, 'action' => mb_substr($action, 0, 40), 'label' => mb_substr($label, 0, 120), 'obj' => mb_substr((string) $obj, 0, 40),
		'summary' => mb_substr((string) $summary, 0, 1500), 'ip_hash' => substr(md5((string) ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt('nonce')), 0, 16), 'created_at' => gmdate('Y-m-d H:i:s'),
	));
}

/* ------------------------------------------------------------ be- és kilépés */
add_action('cp_audit_login', function ($uid, $role) {
	$n = '';
	if ($uid && function_exists('cp_pu_get')) { $u = cp_pu_get($uid); $n = $u ? $u['n'] : ''; $role = $u ? $u['r'] : $role; }
	cp_audit_add('rendszer', 'login', 'Belépés', '', $uid ? 'személyes fiókkal' : 'közös jelszóval (' . $role . ')', $uid ?: null, $n !== '' ? $n : ($role === 'iroda' ? 'Iroda (közös jelszó)' : 'Admin (közös jelszó)'), $role);
}, 10, 2);
add_action('cp_audit_login_fail', function ($email) {
	cp_audit_add('rendszer', 'login_fail', 'Sikertelen belépés', '', $email !== '' ? 'e-mail: ' . sanitize_email($email) : 'közös jelszóval', 0, '–', '');
});
add_action('cp_audit_logout', function () { cp_audit_add('rendszer', 'logout', 'Kilépés', '', ''); });

/* ------------------------------------------------------------ a módosító panelműveletek: terület, címke, összefoglaló */
function cp_audit_actions() {
	return array(
		'cp_crm_move' => array('deal', 'Szakaszváltás / állapot'), 'cp_crm_set' => array('deal', 'Tömeges mezőírás'), 'cp_crm_delete' => array('deal', 'Deal törlése'),
		'cp_crm_save' => array('deal', 'Deal szerkesztése'), 'cp_crm_note_add' => array('deal', 'Jegyzet a dealen'), 'cp_crm_note_del' => array('deal', 'Deal-jegyzet törlése'), 'cp_crm_act_save' => array('deal', 'Tevékenység mentése'), 'cp_crm_act_done' => array('deal', 'Tevékenység kész / visszanyitva'), 'cp_crm_create' => array('deal', 'Új deal'), 'cp_crm_sync' => array('deal', 'Kézi szinkron'),
		'cp_mail_note_add' => array('levelezes', 'Jegyzet a levélszálon'), 'cp_mail_note_del' => array('levelezes', 'Jegyzet törlése'), 'cp_mail_thread_set' => array('levelezes', 'Levélszál módosítása'),
		'cp_mail_bulk' => array('levelezes', 'Tömeges művelet levélszálakon'), 'cp_mail_send' => array('levelezes', 'Levél küldése a panelből'), 'cp_set_me' => array('rendszer', 'Saját beállítások'), 'cp_set_admin' => array('rendszer', 'Beállítás módosítása'), 'cp_mail_tpl_save' => array('levelezes', 'Sablon mentése'), 'cp_mail_tpl_del' => array('levelezes', 'Sablon törlése'), 'cp_ai_draft' => array('levelezes', 'AI-piszkozat kérése'),
		'cp_kb_card_save' => array('feladatok', 'Kártya mentése'), 'cp_kb_card_move' => array('feladatok', 'Kártya áthelyezése'), 'cp_kb_comment' => array('feladatok', 'Hozzászólás'),
		'cp_kb_list_save' => array('feladatok', 'Lista módosítása'), 'cp_kb_board_save' => array('feladatok', 'Tábla módosítása'),
		'cp_lead_set' => array('deal', 'Megkeresés jelölése'), 'cp_flow_set' => array('rendszer', 'Automatizmus kapcsolása'),
		'cp_el_save' => array('elszamolas', 'Elszámolás mentése'), 'cp_el_row' => array('elszamolas', 'Elszámolás sor'), 'cp_el_pay' => array('elszamolas', 'Kifizetés'), 'cp_el_manual' => array('elszamolas', 'Kézi tétel'),
		'cp_el_lock' => array('elszamolas', 'Hónap zárolása'), 'cp_el_import' => array('elszamolas', 'Import'), 'cp_el_kez' => array('elszamolas', 'Kezelő mentése'), 'cp_el_up' => array('elszamolas', 'Fotó feltöltése'),
		'cp_el_phdel' => array('elszamolas', 'Fotó törlése'), 'cp_el_calpush' => array('elszamolas', 'Naptárba írás'),
		'cp_tw_save' => array('tarca', 'Tárca tétel mentése'), 'cp_tw_del' => array('tarca', 'Tárca tétel törlése'), 'cp_tw_import' => array('tarca', 'Tárca import'), 'cp_tw_cfg' => array('tarca', 'Tárca beállítás'),
		'cp_panel_setbg' => array('naptar', 'Háttér beállítása'), 'cp_panel_setstage' => array('naptar', 'Szakasz a naptárból'), 'cp_panel_track' => array('kovetes', 'Követés jelölése'),
		'cp_st_mail_ok' => array('stat', 'Levél „rendben” jelölése'),
	);
}

/* az összefoglaló a kérés paramétereiből – titok, kulcs és hosszú szöveg nélkül; a deal / levél / kártya nevével */
function cp_audit_summary($action, $p) {
	global $wpdb;
	$skip = array('action', 't', '_wpnonce', 'pw', 'pass', 'password', 'key', 'token', 'secret', 'checklist', 'order');
	$parts = array(); $obj = '';
	$name = function ($tbl, $col, $id) use ($wpdb) { return (string) $wpdb->get_var($wpdb->prepare("SELECT $col FROM $tbl WHERE id = %d", $id)); };
	if (in_array($action, array('cp_crm_note_add', 'cp_crm_act_save', 'cp_crm_note_del', 'cp_crm_act_done'), true)) {
		$did = (int) ($p['deal'] ?? 0);
		if ($did && function_exists('cp_crm_t')) { $obj = 'deal:' . $did; $t = $name(cp_crm_t('deals'), 'title', $did); if ($t !== '') { $parts[] = $t; } }
		if ($action === 'cp_crm_act_done' && function_exists('cp_crm_t')) { $t = $name(cp_crm_t('activities'), 'title', (int) ($p['id'] ?? 0)); $parts[] = ($t !== '' ? '„' . $t . '” ' : '') . (!empty($p['done']) ? 'kész' : 'visszanyitva'); }
		if (!empty($p['title'])) { $parts[] = ($action === 'cp_crm_act_save' && !empty($p['id']) ? 'módosítva: ' : 'új: ') . mb_substr((string) $p['title'], 0, 100) . (!empty($p['due']) ? ' (' . $p['due'] . (!empty($p['time']) ? ' ' . $p['time'] : '') . ')' : ''); }
		if (!empty($p['body'])) { $parts[] = '„' . mb_substr(preg_replace('/\s+/u', ' ', (string) $p['body']), 0, 100) . '”'; }
		if ($action === 'cp_crm_note_del') { $parts[] = 'jegyzet #' . (int) ($p['id'] ?? 0); }
		return array($obj, implode(' · ', $parts));
	}
	if (strpos($action, 'cp_crm_') === 0 || $action === 'cp_panel_setstage') {
		$id = (int) ($p['id'] ?? $p['deal'] ?? 0);
		if ($id && function_exists('cp_crm_t')) { $obj = 'deal:' . $id; $t = $name(cp_crm_t('deals'), 'title', $id); if ($t !== '') { $parts[] = $t; } }
		if (!empty($p['ids'])) { $parts[] = count(array_filter(explode(',', (string) $p['ids']))) . ' deal'; }
		if (!empty($p['to'])) { $parts[] = '→ ' . $p['to']; }
		if (!empty($p['st'])) { $parts[] = 'állapot: ' . (array('Won' => 'megnyert', 'Lost' => 'elvesztett', 'Open' => 'újranyitva')[$p['st']] ?? $p['st']); }
		if (!empty($p['why'])) { $parts[] = 'ok: ' . $p['why']; }
		if (!empty($p['f']) && !is_array($p['f'])) { $f = json_decode((string) $p['f'], true); if (is_array($f)) { $parts[] = 'mezők: ' . implode(', ', array_keys($f)); } elseif (isset($p['v'])) { $parts[] = $p['f'] . ' = ' . mb_substr((string) $p['v'], 0, 80); } }
		if (!empty($p['d'])) { $d = json_decode((string) $p['d'], true); if (is_array($d)) { $parts[] = 'mezők: ' . implode(', ', array_slice(array_keys($d), 0, 12)); } }
		return array($obj, implode(' · ', $parts));
	}
	if (strpos($action, 'cp_mail_') === 0 || $action === 'cp_ai_draft') {
		if ($action === 'cp_mail_send') {
			$tid = (int) ($p['thread'] ?? 0);
			return array($tid ? 'mail:' . $tid : '', (!empty($p['test']) ? 'PRÓBALEVÉL magamnak · ' : 'címzett: ' . mb_substr((string) ($p['to'] ?? ''), 0, 160) . (!empty($p['cc']) ? ' · másolat: ' . mb_substr((string) $p['cc'], 0, 120) : '') . ' · ') . 'tárgy: ' . mb_substr((string) ($p['subject'] ?? ''), 0, 120));
		}
		if ($action === 'cp_mail_bulk') {
			$n = count(array_filter(explode(',', (string) ($p['ids'] ?? ''))));
			$op = array('read' => 'olvasottnak jelölés', 'unread' => 'olvasatlannak jelölés', 'tag' => 'címke', 'close' => 'lezárás', 'open' => 'újranyitás', 'assign' => 'kiosztás', 'archive' => 'archiválás', 'trash' => 'Kukába', 'spam' => 'Spambe', 'restore' => 'visszaállítás')[$p['op'] ?? ''] ?? ($p['op'] ?? '');
			$v = (string) ($p['v'] ?? '');
			if (($p['op'] ?? '') === 'assign' && function_exists('cp_pu_get')) { $u = cp_pu_get((int) $v); $v = $u ? $u['n'] : 'kiosztatlan'; }
			return array('', $n . ' levélszál · ' . $op . ($v !== '' ? ': ' . mb_substr($v, 0, 60) : ''));
		}
		$tid = (int) ($p['thread'] ?? $p['id'] ?? 0);
		if ($action === 'cp_mail_tpl_save' || $action === 'cp_mail_tpl_del') { return array('tpl:' . (int) ($p['id'] ?? 0), (string) ($p['name'] ?? ('#' . (int) ($p['id'] ?? 0)))); }
		if ($tid && function_exists('cp_mail_t') && $action !== 'cp_mail_note_del') { $obj = 'mail:' . $tid; $t = $name(cp_mail_t('threads'), 'subject', $tid); if ($t !== '') { $parts[] = $t; } }
		if (!empty($p['f'])) { $v = (string) ($p['v'] ?? ''); if ($p['f'] === 'assignee' && function_exists('cp_pu_get')) { $u = cp_pu_get((int) $v); $v = $u ? $u['n'] : 'senki'; } $parts[] = (array('status' => 'állapot', 'assignee' => 'felelős', 'deal' => 'deal', 'tags' => 'címkék', 'follow' => 'követés')[$p['f']] ?? $p['f']); $parts[count($parts) - 1] .= ': ' . mb_substr($v, 0, 80); }
		if (!empty($p['body'])) { $parts[] = '„' . mb_substr(preg_replace('/\s+/u', ' ', (string) $p['body']), 0, 100) . '”'; }
		if (!empty($p['wish'])) { $parts[] = 'kérés: ' . mb_substr((string) $p['wish'], 0, 100); }
		return array($obj, implode(' · ', $parts));
	}
	if (strpos($action, 'cp_kb_') === 0) {
		$cid = (int) ($p['id'] ?? $p['card'] ?? 0);
		if ($cid && in_array($action, array('cp_kb_card_save', 'cp_kb_card_move', 'cp_kb_comment'), true) && function_exists('cp_kb_t')) { $obj = 'card:' . $cid; $t = $name(cp_kb_t('cards'), 'title', $cid); if ($t !== '') { $parts[] = $t; } }
		elseif (!empty($p['title'])) { $parts[] = 'új: ' . mb_substr((string) $p['title'], 0, 100); }
		foreach (array('list' => 'lista', 'as' => 'felelősök', 'due' => 'határidő', 'done' => 'kész', 'lb' => 'címkék', 'arch' => 'archív', 'name' => 'név', 'del' => 'törlés') as $k => $l) {
			if (!isset($p[$k]) || $p[$k] === '') { continue; }
			$v = (string) $p[$k];
			if ($k === 'list' && function_exists('cp_kb_t')) { $v = $name(cp_kb_t('lists'), 'name', (int) $v) ?: $v; }
			if ($k === 'as' && function_exists('cp_pu_get')) { $v = implode(', ', array_filter(array_map(function ($u) { $x = cp_pu_get((int) $u); return $x ? $x['n'] : ''; }, explode(',', $v)))); }
			$parts[] = $l . ': ' . mb_substr($v, 0, 80);
		}
		if (!empty($p['body'])) { $parts[] = '„' . mb_substr(preg_replace('/\s+/u', ' ', (string) $p['body']), 0, 100) . '”'; }
		return array($obj, implode(' · ', $parts));
	}
	/* beállítások: csak a terület és a művelet – titok (kulcs, webhook, aláírás) nem kerül a naplóba */
	if ($action === 'cp_set_me') { return array('', ($p['op'] ?? '') === 'pwlink' ? 'jelszó-link kérése' : 'név / aláírás / értesítés mentése'); }
	if ($action === 'cp_set_admin') {
		$secn = array('user' => 'Felhasználók', 'vis' => 'Láthatóság', 'shared' => 'Közös jelszavak', 'notif' => 'Értesítések', 'mail' => 'Levelezés', 'smtp' => 'SMTP-ellenőrzés', 'ai' => 'AI', 'panel' => 'Salesmate és hátterek', 'sig' => 'Aláírás')[$p['sec'] ?? ''] ?? ($p['sec'] ?? '');
		$x = $secn;
		if (($p['sec'] ?? '') === 'user') { $x .= ' · ' . (array('add' => 'új: ', 'save' => 'mentés: ', 'off' => 'letiltás: ', 'on' => 'engedélyezés: ', 'invite' => 'link küldése: ', 'del' => 'törlés: ')[$p['op'] ?? ''] ?? '') . mb_substr((string) ($p['n'] ?? ''), 0, 60) . (!empty($p['r']) ? ' (' . $p['r'] . ')' : ''); }
		if (($p['sec'] ?? '') === 'sig' && function_exists('cp_pu_get')) { $uu = cp_pu_get((int) ($p['uid'] ?? 0)); $x .= ' · ' . ($uu ? $uu['n'] : '#' . (int) ($p['uid'] ?? 0)); }
		if (($p['sec'] ?? '') === 'shared') { $x .= !empty($p['off']) ? ' · kikapcsolva' : ' · bekapcsolva'; }
		if (($p['sec'] ?? '') === 'mail') { $x .= ' · beérkezés ' . (!empty($p['sync_on']) ? 'be' : 'ki') . ', küldés ' . (!empty($p['send_on']) ? 'be' : 'ki'); }
		if (($p['sec'] ?? '') === 'ai') { $x .= ' · ' . (!empty($p['on']) ? 'be' : 'ki') . (!empty($p['key']) ? ', új kulcs' : '') . (!empty($p['key_clear']) ? ', kulcs törölve' : ''); }
		if (($p['sec'] ?? '') === 'panel') { $x .= ' · Salesmate-be írás ' . (!empty($p['stage_write']) ? 'be' : 'ki'); }
		return array('', $x);
	}
	if ($action === 'cp_flow_set') { return array('flow:' . sanitize_key((string) ($p['id'] ?? '')), ($p['id'] ?? '') . ' → ' . (array('off' => 'Ki', 'shadow' => 'Árnyék', 'live' => 'Élő')[$p['mode'] ?? ''] ?? '')); }
	/* általános: a rövid, nem titkos paraméterek */
	foreach ($p as $k => $v) {
		if (in_array(strtolower($k), $skip, true) || is_array($v)) { continue; }
		$v = (string) $v;
		if ($v === '' || strlen($v) > 200) { continue; }
		$parts[] = $k . ': ' . mb_substr($v, 0, 60);
		if (count($parts) >= 8) { break; }
	}
	if (isset($p['id']) && in_array($action, array('cp_panel_setbg', 'cp_panel_track', 'cp_el_row', 'cp_el_save'), true)) { $obj = 'event:' . sanitize_text_field((string) $p['id']); }
	return array($obj, implode(' · ', $parts));
}

/* a kérés végén – csak sikeres (2xx) válasznál – bekerül a naplóba */
add_action('admin_init', function () {
	if (!wp_doing_ajax() || empty($_REQUEST['action'])) { return; }
	$a = sanitize_key(wp_unslash($_REQUEST['action']));
	$map = cp_audit_actions();
	if (!isset($map[$a])) { return; }
	$p = array();
	foreach ((array) $_POST as $k => $v) { if (is_scalar($v)) { $p[sanitize_key($k)] = mb_substr(sanitize_textarea_field(wp_unslash((string) $v)), 0, 4000); } }
	foreach ((array) $_GET as $k => $v) { if (is_scalar($v) && !isset($p[sanitize_key($k)])) { $p[sanitize_key($k)] = mb_substr(sanitize_text_field(wp_unslash((string) $v)), 0, 300); } }
	/* a név még a művelet előtt (pl. törlésnél utána már nem lenne meg) */
	list($obj, $sum) = cp_audit_summary($a, $p);
	register_shutdown_function(function () use ($a, $map, $obj, $sum) {
		$code = (int) http_response_code();
		if ($code && ($code < 200 || $code >= 300)) { return; }
		if (function_exists('cp_panel_authed') && !cp_panel_authed()) { return; }
		cp_audit_add($map[$a][0], $a, $map[$a][1], $obj, $sum);
	});
}, 1);

/* 2 évnél régebbi bejegyzések törlése (naponta) */
add_action('init', function () {
	if (!cp_audit_db_ok() || get_transient('cp_audit_gc')) { return; }
	set_transient('cp_audit_gc', 1, DAY_IN_SECONDS);
	global $wpdb;
	$wpdb->query($wpdb->prepare('DELETE FROM ' . cp_audit_t() . ' WHERE created_at < %s', gmdate('Y-m-d H:i:s', time() - 730 * DAY_IN_SECONDS)));
});

/* ------------------------------------------------------------ a napló megtekintése: csak admin */
function cp_audit_auth() {
	cp_el_auth();
	if (!function_exists('cp_panel_is_admin') || !cp_panel_is_admin()) { cp_el_err('A napló csak az adminoknak érhető el.', 403); }
	if (!cp_audit_db_ok()) { cp_el_err('Az adatbázis még készül.', 503); }
}
function cp_audit_where() {
	global $wpdb;
	$w = array('1=1');
	$who = trim((string) wp_unslash($_REQUEST['who'] ?? ''));
	if ($who !== '') { $w[] = $wpdb->prepare('who = %s', $who); }
	$area = sanitize_key(wp_unslash($_REQUEST['area'] ?? ''));
	if ($area !== '') { $w[] = $wpdb->prepare('area = %s', $area); }
	$from = (string) wp_unslash($_REQUEST['from'] ?? ''); $to = (string) wp_unslash($_REQUEST['to'] ?? '');
	$tz = wp_timezone();
	if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $d = new DateTime($from . ' 00:00:00', $tz); $d->setTimezone(new DateTimeZone('UTC')); $w[] = $wpdb->prepare('created_at >= %s', $d->format('Y-m-d H:i:s')); }
	if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $d = new DateTime($to . ' 23:59:59', $tz); $d->setTimezone(new DateTimeZone('UTC')); $w[] = $wpdb->prepare('created_at <= %s', $d->format('Y-m-d H:i:s')); }
	$q = trim((string) wp_unslash($_REQUEST['q'] ?? ''));
	if ($q !== '') { $l = '%' . $wpdb->esc_like($q) . '%'; $w[] = $wpdb->prepare('(summary LIKE %s OR label LIKE %s OR who LIKE %s)', $l, $l, $l); }
	return implode(' AND ', $w);
}
function cp_audit_rows($w, $limit, $offset) {
	global $wpdb;
	$rows = (array) $wpdb->get_results('SELECT id, who, role, area, action, label, obj, summary, created_at FROM ' . cp_audit_t() . " WHERE $w ORDER BY id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset, ARRAY_A);
	foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['at'] = function_exists('cp_crm_local') ? cp_crm_local($r['created_at']) : $r['created_at']; unset($r['created_at']); }
	return $rows;
}
add_action('wp_ajax_nopriv_cp_audit_list', 'cp_audit_ajax_list');
add_action('wp_ajax_cp_audit_list', 'cp_audit_ajax_list');
function cp_audit_ajax_list() {
	cp_audit_auth();
	global $wpdb;
	$w = cp_audit_where();
	$p = max(0, (int) ($_REQUEST['p'] ?? 0));
	$total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . cp_audit_t() . " WHERE $w");
	$whos = (array) $wpdb->get_col('SELECT DISTINCT who FROM ' . cp_audit_t() . " WHERE who <> '' ORDER BY who");
	wp_send_json_success(array('rows' => cp_audit_rows($w, 100, $p * 100), 'total' => $total, 'p' => $p, 'whos' => $whos));
}
/* CSV-letöltés a szűrt naplóról */
add_action('wp_ajax_nopriv_cp_audit_csv', 'cp_audit_ajax_csv');
add_action('wp_ajax_cp_audit_csv', 'cp_audit_ajax_csv');
function cp_audit_ajax_csv() {
	cp_audit_auth();
	$rows = cp_audit_rows(cp_audit_where(), 20000, 0);
	nocache_headers();
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="crazy-panel-naplo-' . wp_date('Y-m-d') . '.csv"');
	$o = fopen('php://output', 'w');
	fwrite($o, "\xEF\xBB\xBF");
	fputcsv($o, array('Időpont', 'Ki', 'Szerepkör', 'Terület', 'Művelet', 'Mire', 'Részletek'), ';');
	foreach ($rows as $r) { fputcsv($o, array($r['at'], $r['who'], $r['role'], $r['area'], $r['label'], $r['obj'], $r['summary']), ';'); }
	fclose($o);
	exit;
}
