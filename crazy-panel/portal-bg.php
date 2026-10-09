<?php
/* Ügyfélportál – háttérválasztás
 * A lista a panel háttérlistája (cp_panel_bg_list: a weboldal „Választható hátterek” oldala képekkel + kiegészítő darabszámok).
 * Az ügyfél csak az adott napon szabad hátteret látja. Foglaltnak csak az aláírt szerződés szakaszban lévő
 * (vagy megnyert) deal háttere számít; a portálon tett választás csak KÉRÉS: a Salesmate háttér mezőjébe kerül
 * (ha a panelben be van kapcsolva a háttér visszaírása), és a panelen látszik. */

if (!defined('ABSPATH')) { exit; }

/* a csak 360 Spinneres rendezvénynél nincs háttér */
function cp_portal_bg_enabled($portal) {
	$sm = $portal->sm;
	$selfie = isset($sm['selfie']) ? trim((string) $sm['selfie']) : '';
	$spin = isset($sm['spin']) ? trim((string) $sm['spin']) : '';
	return !($spin !== '' && $selfie === '');
}

/* az adott nap foglalt hátterei: kisbetűs név => darab (aláírt / megnyert dealek, a saját deal nélkül); null, ha a Salesmate nem érhető el */
function cp_portal_bg_taken($date, $except_deal = '') {
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) { return array(); }
	$p = cp_panel_opts();
	if ($p['f_bg'] === '') { return array(); }
	$ck = 'cp_portal_bgday_' . md5($date . '|' . $p['f_bg'] . '|' . $p['signed_stages']);
	$rows = get_transient($ck);
	if (!is_array($rows)) {
		$info = null;
		$raw = cp_sm_fetch_range($date, $date, $info, array('deal.id', 'deal.stage', 'deal.status', 'deal.pipeline', 'deal.' . $p['f_date'], 'deal.' . $p['f_bg']));
		if (is_wp_error($raw)) { return null; }
		$signed = array_map('cp_panel_lc', cp_panel_lines($p['signed_stages']));
		$pipeline = cp_panel_lc($p['pipeline']);
		$rows = array();
		foreach ($raw as $r) {
			$id = cp_panel_str(cp_panel_get($r, 'id'));
			if ($id === '' || cp_panel_norm_date(cp_panel_get($r, $p['f_date'])) !== $date) { continue; }
			$pl = cp_panel_lc(cp_panel_str(cp_panel_get($r, 'pipeline')));
			if ($pipeline !== '' && $pl !== '' && $pl !== $pipeline) { continue; }
			$status = cp_panel_lc(cp_panel_str(cp_panel_get($r, 'status')));
			$st = cp_panel_lc(cp_panel_str(cp_panel_get($r, 'stage')));
			if ($status === 'lost' || !($status === 'won' || in_array($st, $signed, true))) { continue; }
			$bg = cp_panel_lc(cp_panel_str(cp_panel_get($r, $p['f_bg'])));
			if ($bg !== '') { $rows[$id] = $bg; }
		}
		set_transient($ck, $rows, 5 * MINUTE_IN_SECONDS);
	}
	$out = array();
	foreach ($rows as $id => $bg) {
		if ((string) $id === (string) $except_deal) { continue; }
		$out[$bg] = (isset($out[$bg]) ? $out[$bg] : 0) + 1;
	}
	return $out;
}

/* választható hátterek a portálon: array(name, img, free) – csak a szabadok (+ a jelenlegi választás, ha közben foglalt lett) */
function cp_portal_bg_options($portal, $current = '') {
	$taken = cp_portal_bg_taken($portal->event_date, $portal->deal_id);
	$unknown = $taken === null;
	$out = array();
	foreach (cp_panel_bg_list() as $b) {
		$k = cp_panel_lc($b['name']);
		$free = $unknown || !isset($taken[$k]) || $taken[$k] < (int) $b['qty'];
		$cur = $current !== '' && cp_panel_lc($current) === $k;
		if (!$free && !$cur) { continue; }
		$out[] = array('name' => $b['name'], 'img' => (string) $b['img'], 'free' => $free, 'cur' => $cur);
	}
	return array('list' => $out, 'unknown' => $unknown);
}

/* mentés előtti ellenőrzés: létező és aznap szabad háttér; a lista szerinti pontos nevet adja vissza */
function cp_portal_bg_check($portal, $name) {
	if (!cp_portal_bg_enabled($portal)) { return new WP_Error('invalid', 'Ehhez a szolgáltatáshoz nem tartozik háttér.'); }
	$k = cp_panel_lc($name);
	foreach (cp_panel_bg_list() as $b) {
		if (cp_panel_lc($b['name']) !== $k) { continue; }
		$taken = cp_portal_bg_taken($portal->event_date, $portal->deal_id);
		if (is_array($taken) && isset($taken[$k]) && $taken[$k] >= (int) $b['qty']) {
			return new WP_Error('invalid', 'Ez a háttér erre a napra már foglalt – válassz másikat.');
		}
		return $b['name'];
	}
	return new WP_Error('invalid', 'Ismeretlen háttér.');
}
