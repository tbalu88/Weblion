<?php
/* =====================================================================
 * Felelős = aki először foglalkozik vele (v2.9.8)
 * – új deal (a bekapcsolás után létrejött): az lesz a felelős, aki a kiválasztott kollégák közül (Beállítások → Átállás →
 *   „Felelős-kiosztás”, alapból Andi és Dorka) elsőként levelet / választ küld a panelből az ügyfélnek a dealhez (v2.9.12-től
 *   csak ez számít; megnyitás, szerkesztés, jegyzet, szakaszváltás nem).
 * – akinek a nevére kézzel állították a dealt (új deal felvételekor vagy a Felelős mezőben), azt nem írja felül.
 * – levélszál: ha még nincs kiosztva, az kapja, aki a kiválasztott kollégák közül elsőként válaszol rá a panelből.
 * Egy deal / szál csak egyszer kerül így kiosztásra; régi dealekhez nem nyúl.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

/* a kiválasztott kollégák (Salesmate-felhasználó azonosítók) – ugyanaz a lista, mint az űrlapos kiosztásé */
function cp_claim_pool() {
	$rr = get_option('cp_cut_rr', array('users' => array(19, 25)));
	return array_values(array_filter(array_map('intval', (array) ($rr['users'] ?? array()))));
}
function cp_claim_state() {
	$s = get_option('cp_claim', array());
	if (!is_array($s)) { $s = array(); }
	if (empty($s['since'])) { $s['since'] = gmdate('Y-m-d H:i:s'); $s['done'] = array(); update_option('cp_claim', $s, false); }
	if (!isset($s['done']) || !is_array($s['done'])) { $s['done'] = array(); }
	return $s;
}
function cp_claim_mark($deal_id, $sm, $how) {
	$s = cp_claim_state();
	$s['done'][(string) (int) $deal_id] = array((int) $sm, time(), $how);
	/* 150 napnál régebbi jelölések törlése */
	foreach ($s['done'] as $k => $v) { if ((int) ($v[1] ?? 0) < time() - 150 * DAY_IN_SECONDS) { unset($s['done'][$k]); } }
	update_option('cp_claim', $s, false);
}
function cp_claim_unmark($deal_id) { $s = cp_claim_state(); unset($s['done'][(string) (int) $deal_id]); update_option('cp_claim', $s, false); }
function cp_claim_me_sm() { $u = function_exists('cp_pu_current') ? cp_pu_current() : null; return $u ? (int) ($u['sm'] ?? 0) : 0; }

function cp_claim_deal_row($deal_id) {
	global $wpdb;
	if (!function_exists('cp_crm_t')) { return null; }
	return $wpdb->get_row($wpdb->prepare('SELECT id, owner_id, created_at FROM ' . cp_crm_t('deals') . ' WHERE id = %d', (int) $deal_id), ARRAY_A);
}

/* a deal az aktuális kollégáé lesz, ha új, még nem foglalta le senki, és ő a kiválasztottak között van */
function cp_claim_deal($deal_id) {
	$deal_id = (int) $deal_id;
	if ($deal_id <= 0) { return; }
	$sm = cp_claim_me_sm();
	if (!$sm || !in_array($sm, cp_claim_pool(), true)) { return; }
	$s = cp_claim_state();
	if (isset($s['done'][(string) $deal_id])) { return; }
	$d = cp_claim_deal_row($deal_id);
	if (!$d || (string) $d['created_at'] < (string) $s['since']) { return; }
	cp_claim_mark($deal_id, $sm, 'first');
	if ((int) $d['owner_id'] === $sm) { return; }
	if (empty(cp_panel_opts()['stage_write']) || !function_exists('cp_sm_put_deal')) { cp_claim_unmark($deal_id); return; }
	$put = cp_sm_put_deal($deal_id, array('owner' => $sm));
	if (is_wp_error($put)) { cp_claim_unmark($deal_id); return; }
	global $wpdb;
	$wpdb->update(cp_crm_t('deals'), array('owner_id' => $sm), array('id' => $deal_id));
	$wpdb->insert(cp_crm_t('history'), array('deal_id' => $deal_id, 'field' => 'owner_id', 'old_val' => (string) (int) $d['owner_id'], 'new_val' => (string) $sm, 'by_user' => function_exists('cp_panel_who') ? cp_panel_who() : 'Crazy panel', 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel'));
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
}

/* levélszál: az első válaszoló kapja, ha még nincs kiosztva */
function cp_claim_thread($thread_id) {
	global $wpdb;
	if (!function_exists('cp_mail_t') || $thread_id <= 0) { return; }
	$sm = cp_claim_me_sm(); $uid = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if (!$sm || !$uid || !in_array($sm, cp_claim_pool(), true)) { return; }
	$T = cp_mail_t('threads');
	$n = $wpdb->query($wpdb->prepare("UPDATE $T SET assignee_uid = %d WHERE id = %d AND assignee_uid IS NULL", $uid, (int) $thread_id));
	if ($n) { $wpdb->insert(cp_mail_t('events'), array('thread_id' => (int) $thread_id, 'kind' => 'assign', 'text' => 'Kiosztva: ' . (function_exists('cp_panel_who') ? cp_panel_who() : '') . ' (első válasz)', 'author_uid' => $uid, 'author' => function_exists('cp_panel_who') ? cp_panel_who() : '', 'created_at' => gmdate('Y-m-d H:i:s'))); }
}

/* a figyelt műveletek: ajax-művelet => a deal azonosítójának POST-mezője */
function cp_claim_actions() {
	/* v2.9.12: a deal is csak a levélválasszal kerül valakihez (a szerkesztés / jegyzet / szakaszváltás nem számít);
	 * a cp_crm_set / cp_crm_save csak a kézi felelős-beállítás felismeréséhez kell */
	return array('cp_crm_set' => 'id', 'cp_crm_save' => 'id', 'cp_mail_send' => 'deal', 'cp_crm_create' => '');
}

/* a művelet lefutása után (csak sikeres válasznál) – a válasz a kimeneti pufferből olvasható */
add_action('admin_init', function () {
	if (!wp_doing_ajax() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
	cp_claim_state(); /* az első kéréssel indul az óra: az ennél régebbi dealekhez nem nyúl */
	$a = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
	$A = cp_claim_actions();
	if (!isset($A[$a])) { return; }
	if ($a === 'cp_mail_send' && !empty($_POST['test'])) { return; }
	$k = $A[$a];
	$deal = $k !== '' ? (int) preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST[$k] ?? '')) : 0;
	/* a Felelős mező kézi módosítása: aki ott van, azt nem írjuk felül */
	$pre = $deal ? cp_claim_deal_row($deal) : null;
	$owner_set = '';
	if ($a === 'cp_crm_set' && sanitize_key(wp_unslash($_POST['f'] ?? '')) === 'owner_id') { $owner_set = (string) (int) ($_POST['v'] ?? 0); }
	if ($a === 'cp_crm_save') { $f = json_decode((string) wp_unslash($_POST['f'] ?? ''), true); if (is_array($f) && isset($f['owner_id']) && $pre && (string) (int) $f['owner_id'] !== (string) (int) $pre['owner_id']) { $owner_set = (string) (int) $f['owner_id']; } }
	ob_start();
	/* a WordPress „shutdown” eseménye a pufferek kiürítése (1-es prioritás) előtt: a válasz még olvasható */
	add_action('shutdown', function () use ($a, $deal, $owner_set) {
		$out = (string) ob_get_contents();
		$j = json_decode($out, true);
		if (!is_array($j) || empty($j['success'])) { return; }
		try {
			if ($a === 'cp_crm_create') { $id = (int) ($j['data']['id'] ?? 0); if ($id) { cp_claim_mark($id, 0, 'manual'); } return; }
			if ($owner_set !== '') { cp_claim_mark($deal, (int) $owner_set, 'manual'); return; }
			if ($a !== 'cp_mail_send') { return; }
			if ($a === 'cp_mail_send') {
				$th = (int) ($j['data']['thread'] ?? 0);
				cp_claim_thread($th);
				if (!$deal && $th) { global $wpdb; $deal = (int) $wpdb->get_var($wpdb->prepare('SELECT deal_id FROM ' . cp_mail_t('threads') . ' WHERE id = %d', $th)); }
			}
			cp_claim_deal($deal);
		} catch (\Throwable $e) { /* a kiosztás hibája ne rontsa el a választ */ }
	}, 0);
}, 1);
