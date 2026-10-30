<?php
/* =====================================================================
 * Kezdőlap (v1.65) – egy pillantásra: mai teendők, új ajánlatkérések, levelek, közelgő rendezvények,
 * elakadt dealek és a hónap számai. Minden blokk csak annak jelenik meg, akinek az adott fül engedélyezett.
 * Csak a helyi (Salesmate-ből szinkronizált) táblákat olvassa – Salesmate-hívás nélkül, gyors.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_dash_utc($ts) { return gmdate('Y-m-d H:i:s', $ts); }

add_action('wp_ajax_nopriv_cp_dash_data', 'cp_dash_ajax_data');
add_action('wp_ajax_cp_dash_data', 'cp_dash_ajax_data');
function cp_dash_ajax_data() {
	cp_el_auth();
	if (!cp_panel_can_x('kezdolap')) { cp_el_err('A Kezdőlap nincs engedélyezve a fiókodnak.', 403); }
	global $wpdb;
	$me = function_exists('cp_pu_current') ? cp_pu_current() : null;
	$uid = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$sm = $me ? (int) ($me['sm'] ?? 0) : 0;
	$tz = wp_timezone();
	$today = wp_date('Y-m-d');
	$eod = (new DateTime($today . ' 23:59:59', $tz))->getTimestamp();
	$out = array('me' => $me ? (string) $me['n'] : '', 'sm' => $sm, 'today' => $today);
	$crm = function_exists('cp_crm_panel_on') && cp_crm_panel_on() && cp_panel_can_any(array('deals', 'kapcsolatok'));
	$D = $crm ? cp_crm_t('deals') : '';
	$loc = function ($dt) { return function_exists('cp_crm_local') ? cp_crm_local($dt) : (string) $dt; };

	/* 1. teendők: Salesmate-tevékenységek (a saját felhasználómhoz rendelve) + a Feladatok kártyái (rám osztva)
	 * v1.68: a Kezdőlapról elrejtettek kimaradnak; a régi lejártak is jönnek (a felület külön csoportba teszi) */
	$todo = array();
	$hid = array_flip(cp_dash_hidden($uid));
	if ($crm && $sm) {
		$A = cp_crm_t('activities');
		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT a.id, a.title, a.type, a.due_at, a.related_id, d.title dt FROM $A a LEFT JOIN $D d ON d.id = a.related_id AND d.deleted_at IS NULL
			WHERE a.deleted_at IS NULL AND (a.completed IS NULL OR a.completed = 0) AND a.owner_id = %d AND a.due_at IS NOT NULL AND a.due_at <= %s ORDER BY a.due_at DESC LIMIT 600", $sm, cp_dash_utc($eod)), ARRAY_A) as $r) {
			if (isset($hid['act:' . $r['id']])) { continue; }
			$todo[] = array('k' => 'act', 'id' => (int) $r['id'], 't' => (string) $r['title'], 'ty' => (string) $r['type'], 'due' => $loc($r['due_at']), 'deal' => (int) $r['related_id'], 'dt' => (string) $r['dt']);
		}
	}
	if ($uid && cp_panel_can_x('feladatok') && function_exists('cp_kb_db_ok') && cp_kb_db_ok()) {
		$B = cp_kb_t('boards'); $Cd = cp_kb_t('cards');
		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT c.id, c.title, c.due, b.name bn FROM $Cd c JOIN $B b ON b.id = c.board_id AND b.deleted_at IS NULL AND (b.kind <> 'personal' OR b.owner_uid = %d)
			WHERE c.archived_at IS NULL AND c.done = 0 AND FIND_IN_SET(%d, c.assignees) AND (c.due IS NULL OR c.due <= %s) ORDER BY c.due IS NULL, c.due, c.id DESC LIMIT 200", $uid, $uid, wp_date('Y-m-d', time() + 2 * DAY_IN_SECONDS)), ARRAY_A) as $r) {
			if (isset($hid['card:' . $r['id']])) { continue; }
			$todo[] = array('k' => 'card', 'id' => (int) $r['id'], 't' => (string) $r['title'], 'due' => (string) $r['due'], 'bn' => (string) $r['bn']);
		}
	}
	$out['todo'] = $crm || cp_panel_can_x('feladatok') ? $todo : null;
	$out['todo_need_sm'] = $crm && $uid && !$sm;

	/* 2. új ajánlatkérések: az elmúlt 7 nap nyitott dealjei + a még párosítatlan webes megkeresések */
	if ($crm) {
		$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT d.id, d.title, d.stage, d.owner_id, d.created_at, d.event_d, d.value FROM $D d WHERE d.deleted_at IS NULL AND d.status = 'Open' AND d.created_at >= %s ORDER BY d.created_at DESC LIMIT 25", cp_dash_utc(time() - 7 * DAY_IN_SECONDS)), ARRAY_A);
		$out['new'] = array_map(function ($r) use ($loc) { return array('id' => (int) $r['id'], 't' => (string) $r['title'], 'st' => (string) $r['stage'], 'own' => (int) $r['owner_id'], 'on' => cp_crm_user_name($r['owner_id']), 'at' => $loc($r['created_at']), 'ev' => (string) $r['event_d']); }, $rows);
		$out['leads'] = 0;
		if (function_exists('cp_lead_db_ok') && cp_lead_db_ok() && cp_panel_can_x('deals')) {
			$out['leads'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_lead_t() . " WHERE status <> 'spam' AND deal_id IS NULL AND created_at >= %s", cp_dash_utc(time() - 7 * DAY_IN_SECONDS)));
		}
	}

	/* 3. levelek: olvasatlan / kiosztatlan nyitott szálak és a nekem kiosztottak */
	if (cp_panel_can_x('mail') && function_exists('cp_mail_db_ok') && cp_mail_db_ok()) {
		$T = cp_mail_t('threads');
		$base = "status = 'open' AND folder = 'inbox'";
		$un = '(seen_at IS NULL OR seen_at < last_at)';
		$pick = 'SELECT id, subject, contact_name, contact_email, snippet, last_at, assignee_uid, deal_id, seen_at FROM ' . $T;
		$fmt = function ($r) use ($loc) { return array('id' => (int) $r['id'], 's' => (string) $r['subject'], 'c' => (string) ($r['contact_name'] !== '' ? $r['contact_name'] : $r['contact_email']), 'sn' => html_entity_decode((string) $r['snippet'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'at' => $loc($r['last_at']), 'un' => empty($r['seen_at']) || $r['seen_at'] < $r['last_at'] ? 1 : 0, 'as' => (int) $r['assignee_uid']); };
		$out['mail'] = array(
			'unread' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $T WHERE $base AND $un"),
			'unassigned' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $T WHERE $base AND assignee_uid IS NULL"),
			'mine_n' => $uid ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T WHERE $base AND assignee_uid = %d", $uid)) : 0,
			'mine' => $uid ? array_map($fmt, (array) $wpdb->get_results($wpdb->prepare("$pick WHERE $base AND assignee_uid = %d ORDER BY $un DESC, last_at DESC LIMIT 6", $uid), ARRAY_A)) : array(),
			'waiting' => array_map($fmt, (array) $wpdb->get_results("$pick WHERE $base AND assignee_uid IS NULL ORDER BY $un DESC, last_at DESC LIMIT 6", ARRAY_A)),
		);
	}

	/* 4. közelgő rendezvények (14 nap) – az ügyfélportál kitöltöttségével; 5. elakadt dealek; 6. a hónap számai */
	if ($crm) {
		$ev = (array) $wpdb->get_results($wpdb->prepare("SELECT d.id, d.title, d.event_d, d.start_at, d.venue, d.service, d.package, d.status, d.stage, d.paid, d.operator FROM $D d WHERE d.deleted_at IS NULL AND d.status IN ('Open', 'Won') AND d.event_d BETWEEN %s AND %s ORDER BY d.event_d, d.start_at LIMIT 40", $today, wp_date('Y-m-d', time() + 14 * DAY_IN_SECONDS)), ARRAY_A);
		$pm = array();
		if ($ev && function_exists('cp_portal_panel_map') && function_exists('cp_portal_db_ready') && cp_portal_db_ready()) {
			$pm = (array) cp_portal_panel_map(array_map(function ($r) { return (string) $r['id']; }, $ev));
		}
		$out['events'] = array_map(function ($r) use ($pm, $loc) {
			$p = isset($pm[(string) $r['id']]) ? (array) $pm[(string) $r['id']] : null;
			return array('id' => (int) $r['id'], 't' => (string) $r['title'], 'd' => (string) $r['event_d'], 'tm' => $r['start_at'] ? substr($loc($r['start_at']), 11, 5) : '', 'v' => (string) $r['venue'],
				'svc' => (string) ($r['package'] !== '' ? $r['package'] : $r['service']), 'st' => (string) $r['status'], 'stage' => (string) $r['stage'], 'paid' => (string) $r['paid'], 'op' => (string) $r['operator'],
				'p' => $p ? array('done' => (int) ($p['done'] ?? 0), 'total' => (int) ($p['total'] ?? 0), 'st' => (string) ($p['st'] ?? '')) : null);
		}, $ev);
		$rot = (array) $wpdb->get_results($wpdb->prepare("SELECT d.id, d.title, d.stage, d.owner_id, d.rotten_d, d.last_comm_at FROM $D d WHERE d.deleted_at IS NULL AND d.status = 'Open' AND d.rotten_d IS NOT NULL AND d.rotten_d <= %s AND (d.event_d IS NULL OR d.event_d >= %s) ORDER BY (d.owner_id = %d) DESC, d.rotten_d LIMIT 12", $today, $today, $sm), ARRAY_A);
		$out['stale'] = array_map(function ($r) use ($loc) { return array('id' => (int) $r['id'], 't' => (string) $r['title'], 'st' => (string) $r['stage'], 'own' => (int) $r['owner_id'], 'on' => cp_crm_user_name($r['owner_id']), 'rot' => (string) $r['rotten_d'], 'lc' => $loc($r['last_comm_at'])); }, $rot);
		$out['stale_n'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $D WHERE deleted_at IS NULL AND status = 'Open' AND rotten_d IS NOT NULL AND rotten_d <= %s AND (event_d IS NULL OR event_d >= %s)", $today, $today));
		$m0 = (new DateTime(wp_date('Y-m-01') . ' 00:00:00', $tz))->getTimestamp();
		$k = $wpdb->get_row($wpdb->prepare("SELECT
			SUM(status = 'Open') o_n, SUM(IF(status = 'Open', value, 0)) o_v,
			SUM(status = 'Won' AND closed_at >= %s) w_n, SUM(IF(status = 'Won' AND closed_at >= %s, value, 0)) w_v,
			SUM(created_at >= %s) c_n, SUM(status = 'Lost' AND closed_at >= %s) l_n,
			SUM(status IN ('Open', 'Won') AND event_d BETWEEN %s AND %s) e_n
			FROM $D WHERE deleted_at IS NULL", cp_dash_utc($m0), cp_dash_utc($m0), cp_dash_utc($m0), cp_dash_utc($m0), wp_date('Y-m-01'), wp_date('Y-m-t')), ARRAY_A);
		$out['kpi'] = array('open_n' => (int) $k['o_n'], 'open_v' => (float) $k['o_v'], 'won_n' => (int) $k['w_n'], 'won_v' => (float) $k['w_v'], 'new_n' => (int) $k['c_n'], 'lost_n' => (int) $k['l_n'], 'ev_n' => (int) $k['e_n']);
	}
	$out['can'] = array('deals' => cp_panel_can_x('deals'), 'mail' => cp_panel_can_x('mail'), 'feladatok' => cp_panel_can_x('feladatok'), 'naptar' => cp_panel_can_x('naptar'), 'gnaptar' => function_exists('cp_gcal_ok') && cp_panel_can_x('gnaptar'));
	wp_send_json_success($out);
}

/* ------------------------------------------------------------ v1.68: teendők tömegesen (Kész / Későbbre / Elrejtés) */

/* a Kezdőlapról elrejtett teendők (felhasználónként; a Salesmate-ben és a Feladatokban megmaradnak) */
function cp_dash_hidden($uid) {
	if (!$uid) { return array(); }
	$h = get_option('cp_dash_hide_' . (int) $uid, array());
	return is_array($h) ? $h : array();
}

add_action('wp_ajax_nopriv_cp_dash_bulk', 'cp_dash_ajax_bulk');
add_action('wp_ajax_cp_dash_bulk', 'cp_dash_ajax_bulk');
function cp_dash_ajax_bulk() {
	cp_el_auth();
	if (!cp_panel_can_x('kezdolap')) { cp_el_err('A Kezdőlap nincs engedélyezve a fiókodnak.', 403); }
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$me = function_exists('cp_pu_current') ? cp_pu_current() : null;
	$uid = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if (!$uid) { cp_el_err('Ehhez személyes fiókkal kell belépned.', 403); }
	$sm = $me ? (int) ($me['sm'] ?? 0) : 0;
	$op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
	$days = max(1, min(90, (int) ($_POST['days'] ?? 1)));
	if (!in_array($op, array('done', 'later', 'hide'), true)) { cp_el_err('Hibás művelet.'); }
	$items = array_slice(array_filter(array_map('trim', explode(',', (string) wp_unslash($_POST['items'] ?? '')))), 0, 15);
	$ok = 0; $err = array();
	$hide = cp_dash_hidden($uid);
	$newd = wp_date('Y-m-d', time() + $days * DAY_IN_SECONDS);
	foreach ($items as $it) {
		if (!preg_match('/^(act|card):(\d+)$/', $it, $m)) { continue; }
		$id = (int) $m[2];
		if ($m[1] === 'act') {
			/* csak a saját (rám osztott) Salesmate-tevékenység */
			$a = $sm ? $wpdb->get_row($wpdb->prepare('SELECT id, title, type, owner_id, due_at FROM ' . cp_crm_t('activities') . ' WHERE id = %d AND owner_id = %d AND deleted_at IS NULL', $id, $sm), ARRAY_A) : null;
			if (!$a) { $err[] = $it; continue; }
			if ($op === 'hide') { $hide[] = $it; $ok++; continue; }
			$body = array('title' => $a['title'], 'type' => $a['type'] !== '' ? $a['type'] : 'Task', 'owner' => (int) $a['owner_id'], 'isCompleted' => $op === 'done');
			if ($op === 'later') {
				$tm = $a['due_at'] && function_exists('cp_crm_local') ? substr(cp_crm_local($a['due_at']), 11, 5) : '';
				$body['dueDate'] = cp_dact_due_iso($newd, $tm !== '00:00' ? $tm : '');
			}
			$r = cp_sm_request('PUT', '/apis/activity/v4/' . $id, $body);
			if (is_wp_error($r)) { $err[] = $it; continue; }
			if (!function_exists('cp_dact_refresh_act') || !cp_dact_refresh_act($id)) {
				$wpdb->update(cp_crm_t('activities'), $op === 'done' ? array('completed' => 1, 'completed_at' => gmdate('Y-m-d H:i:s')) : array('due_at' => gmdate('Y-m-d H:i:s', strtotime(str_replace(array('T', '.000Z'), array(' ', ''), $body['dueDate'])))), array('id' => $id));
			}
			$ok++;
		} else {
			/* csak a rám osztott, számomra látható kártya */
			if (!function_exists('cp_kb_t')) { $err[] = $it; continue; }
			$c = $wpdb->get_row($wpdb->prepare('SELECT c.id FROM ' . cp_kb_t('cards') . ' c JOIN ' . cp_kb_t('boards') . " b ON b.id = c.board_id AND b.deleted_at IS NULL AND (b.kind <> 'personal' OR b.owner_uid = %d) WHERE c.id = %d AND FIND_IN_SET(%d, c.assignees)", $uid, $id, $uid), ARRAY_A);
			if (!$c) { $err[] = $it; continue; }
			if ($op === 'hide') { $hide[] = $it; $ok++; continue; }
			$wpdb->update(cp_kb_t('cards'), $op === 'done' ? array('done' => 1) : array('due' => $newd), array('id' => $id));
			if (function_exists('cp_kb_log')) { cp_kb_log($id, $op === 'done' ? 'Késznek jelölte (Kezdőlap)' : 'Határidő: ' . $newd . ' (Kezdőlap, későbbre)'); }
			$ok++;
		}
	}
	if ($op === 'hide') { update_option('cp_dash_hide_' . $uid, array_slice(array_values(array_unique($hide)), -3000), false); }
	wp_send_json_success(array('ok' => $ok, 'err' => $err));
}

