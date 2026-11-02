<?php
/* =====================================================================
 * Ügyfélportál összekötése a deal-adatlappal és a levelezéssel (v1.59)
 * – a deal-adatlap (cp_crm_deal_data) kapja a portál állapotát és eseményeit (meghívó, emlékeztetők, ügyfél-kitöltés)
 * – a levélíró friss, személyes portál-linket kérhet a dealhez (gombként szúrja be)
 * – a {{Deal.ugyfelportal_link}} változó küldéskor mindig friss, működő linkre cserélődik
 * A portál saját fájljaihoz (portal-*.php) nem nyúl, csak a függvényeiket hívja.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_plk_ready() { return function_exists('cp_portal_db_ready') && cp_portal_db_ready() && function_exists('cp_portal_get_by_deal'); }

/* rövid összefoglaló egy dealhez (Salesmate-hívás nélkül) */
function cp_plk_summary($deal_id) {
	if (!cp_plk_ready() || !function_exists('cp_portal_panel_map')) { return null; }
	$m = cp_portal_panel_map(array((string) $deal_id));
	$m = is_object($m) ? (array) $m : (array) $m;
	$s = $m[(string) $deal_id] ?? null;
	if (!$s) { return array('none' => 1, 'mode' => cp_portal_mode()); }
	$s = (array) $s;
	$p = cp_portal_get_by_deal((string) $deal_id);
	$last = 0;
	if ($p && function_exists('cp_portal_tokens_list')) { foreach (cp_portal_tokens_list($p->id) as $t) { $u = cp_portal_db_ts($t->last_used_at); if ($u > $last) { $last = $u; } } }
	$di = $p && function_exists('cp_portal_deadline_info') ? cp_portal_deadline_info($p) : null;
	$miss = $p && function_exists('cp_portal_missing') && function_exists('cp_portal_answers') ? array_values(cp_portal_missing(cp_portal_answers($p->id), null, cp_portal_flow($p), $p)) : array();
	return array('none' => 0, 'mode' => cp_portal_mode(), 'st' => $s['st'], 'done' => (int) $s['done'], 'total' => (int) $s['total'], 'cd' => (int) $s['cd'], 'ct' => (int) $s['ct'],
		'deadline' => $di ? $di['text'] : '', 'passed' => $di ? (time() >= $di['deadline']) : false, 'last_open' => $last ? wp_date('Y-m-d H:i', $last) : '', 'missing' => array_slice($miss, 0, 12),
		'flags' => array_values(array_map(function ($f) { return array('t' => $f['text'] ?? '', 'tone' => $f['tone'] ?? ''); }, (array) ($s['flags'] ?? array()))), 'bg' => (string) ($s['bg'] ?? ''), 'sb' => !empty($s['sb']));
}

/* v1.92: a portálválaszok és a deal eltérései: [{k, l, p (portál), d (deal), f (deal-mező), v (beírandó érték)}] */
function cp_plk_diff($deal_id, $d) {
	$p = cp_portal_get_by_deal((string) $deal_id);
	if (!$p || !function_exists('cp_portal_answers')) { return array(); }
	$a = cp_portal_answers($p->id); $out = array();
	$add = function ($k, $l, $pv, $dv, $v) use (&$out) { $out[] = array('k' => $k, 'l' => $l, 'p' => (string) $pv, 'd' => (string) $dv, 'f' => $k, 'v' => $v); };
	$ad = isset($a['event_date']) && is_string($a['event_date']) ? $a['event_date'] : '';
	if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ad) && $ad !== (string) ($d['event_d'] ?? '')) { $add('event_d', 'Dátum', $ad, $d['event_d'] ?? '', $ad); }
	$sch = isset($a['schedule']) ? (array) $a['schedule'] : array();
	$ps = (string) ($sch['start'] ?? ''); $pe = (string) ($sch['end'] ?? '');
	$ds = !empty($d['start_at']) ? substr((string) $d['start_at'], 11, 5) : '';
	$okt = function ($t) { return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); };
	if ($okt($ps) && $ps !== $ds) { $add('start_at', 'Kezdés', $ps, $ds, $ps); }
	if ($okt($ps) && $okt($pe)) {
		$mins = ((int) substr($pe, 0, 2) * 60 + (int) substr($pe, 3, 2) - (int) substr($ps, 0, 2) * 60 - (int) substr($ps, 3, 2) + 1440) % 1440;
		$h = round($mins / 60, 1); $dh = (float) ($d['hours'] ?? 0);
		if ($h > 0 && abs($h - $dh) >= 0.5) { $add('hours', 'Óraszám', $h, $dh > 0 ? $dh : '', (int) round($h)); }
	}
	$g = isset($a['guests_type_dress']) && is_array($a['guests_type_dress']) ? (int) ($a['guests_type_dress']['guests'] ?? 0) : 0;
	if ($g > 0 && $g !== (int) ($d['guests'] ?? 0)) { $add('guests', 'Létszám', $g, (int) ($d['guests'] ?? 0) ?: '', $g); }
	$b = isset($a['background']) && is_string($a['background']) ? trim($a['background']) : '';
	if ($b !== '' && cp_panel_lc($b) !== cp_panel_lc(trim((string) ($d['background'] ?? '')))) { $add('background', 'Háttér', $b, $d['background'] ?? '', $b); }
	return $out;
}

/* a deal-adatlap: portál-összefoglaló + események az idővonalra */
add_filter('cp_crm_deal_data', function ($x) {
	if (!cp_plk_ready()) { return $x; }
	$id = (string) $x['deal']['id'];
	$x['portal'] = cp_plk_summary($id);
	/* v1.92: amit az ügyfél a portálon másképp adott meg, mint ami a dealen van – a deal-adatlapon „Átveszem” gombbal beírható */
	if (is_array($x['portal']) && empty($x['portal']['none'])) { $x['portal']['diff'] = cp_plk_diff($id, (array) $x['deal']); }
	$ev = array();
	$p = cp_portal_get_by_deal($id);
	if ($p) {
		$N = array('welcome' => 'Üdvözlő levél (portál)', 'invite' => 'Portál-meghívó elküldve', 'reminder_1' => 'Portál-emlékeztető (1.)', 'reminder_2' => 'Portál-emlékeztető (2.)', 'completed' => 'Az ügyfél kitöltötte a portált', 'newlink' => 'Új portál-link (az ügyfél kérte)', 'resend' => 'Portál-link újraküldve');
		foreach ((array) cp_portal_notifications($p->id) as $n) {
			$t = explode(':', (string) $n->type)[0];
			$ev[] = array('at' => wp_date('Y-m-d H:i', cp_portal_db_ts($n->sent_at)), 'text' => $N[$t] ?? ('Portál: ' . $t), 'ch' => (string) $n->channel, 'ok' => strpos((string) $n->result, 'ok') === 0 || $n->result === '' ? 1 : 0);
		}
		/* az ügyfél / iroda kitöltései naponként és szerzőnként összevonva */
		$days = array();
		foreach ((array) cp_portal_log_list($p->id, 200) as $l) {
			$ts = cp_portal_db_ts($l->created_at);
			$k = wp_date('Y-m-d', $ts) . '|' . $l->updated_by;
			if (!isset($days[$k])) { $days[$k] = array('at' => wp_date('Y-m-d H:i', $ts), 'by' => $l->updated_by, 'n' => 0); }
			$days[$k]['n']++;
			if (wp_date('Y-m-d H:i', $ts) > $days[$k]['at']) { $days[$k]['at'] = wp_date('Y-m-d H:i', $ts); }
		}
		$BY = array('ugyfel' => 'Az ügyfél', 'iroda' => 'Az iroda', 'rendszer' => 'A rendszer');
		foreach ($days as $d) { $ev[] = array('at' => $d['at'], 'text' => ($BY[$d['by']] ?? $d['by']) . ' ' . $d['n'] . ' mezőt töltött ki / módosított a portálon', 'ch' => 'portal', 'ok' => 1); }
		$ev[] = array('at' => wp_date('Y-m-d H:i', cp_portal_db_ts($p->created_at)), 'text' => 'Ügyfélportál létrehozva', 'ch' => 'portal', 'ok' => 1);
	}
	$x['portal_ev'] = $ev;
	return $x;
}, 30);

/* friss portál-link egy dealhez a levélbe (ha még nincs portál: létrehozza meghívó levél nélkül, kérésre) */
add_action('wp_ajax_nopriv_cp_portal_mail_link', 'cp_plk_ajax_link');
add_action('wp_ajax_cp_portal_mail_link', 'cp_plk_ajax_link');
function cp_plk_ajax_link() {
	cp_el_auth();
	cp_tab_need(array('mail', 'deals'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (!cp_plk_ready()) { cp_el_err('Az ügyfélportál nincs bekapcsolva.', 503); }
	if (cp_portal_mode() !== 'on') { cp_el_err('Az ügyfélportál ki van kapcsolva (WP admin → Beállítások → Ügyfélportál).'); }
	$deal = preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['deal'] ?? ''));
	if ($deal === '') { cp_el_err('Előbb válassz deal-t a levélhez.'); }
	$p = cp_portal_get_by_deal($deal);
	if (!$p) {
		if (empty($_POST['create'])) { wp_send_json_success(array('none' => 1)); }
		$p = cp_portal_create($deal, null, 'panel', 'v2', false);
		if (is_wp_error($p)) { cp_el_err('A portált nem sikerült létrehozni: ' . $p->get_error_message()); }
		if (function_exists('cp_portal_notify_log')) { cp_portal_notify_log($p, 'invite', 'email', 'link a levélírón át (panel)'); }
	}
	$url = cp_portal_issue_link($p);
	if (is_wp_error($url)) { cp_el_err($url->get_error_message()); }
	wp_send_json_success(array('url' => $url, 'summary' => cp_plk_summary($deal)));
}

/* a levélszál deal-kártyájára is: portál-állapot */
function cp_plk_chip($deal_id) { $s = cp_plk_summary($deal_id); return $s && empty($s['none']) ? array('st' => $s['st'], 'done' => $s['done'], 'total' => $s['total'], 'deadline' => $s['deadline']) : null; }
