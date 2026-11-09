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

/* rövid összefoglaló egy dealhez (Salesmate-hívás nélkül)
 * v2.9.58: $p / $a (portál, válaszok) átadható, ha a hívó már betöltötte – false: még nincs betöltve */
function cp_plk_summary($deal_id, $p = false, $a = false) {
	if (!cp_plk_ready() || !function_exists('cp_portal_panel_map')) { return null; }
	$m = cp_portal_panel_map(array((string) $deal_id));
	$m = is_object($m) ? (array) $m : (array) $m;
	$s = $m[(string) $deal_id] ?? null;
	if (!$s) { return array('none' => 1, 'mode' => cp_portal_mode()); }
	$s = (array) $s;
	if ($p === false) { $p = cp_portal_get_by_deal((string) $deal_id); }
	$last = 0;
	if ($p && function_exists('cp_portal_tokens_list')) { foreach (cp_portal_tokens_list($p->id) as $t) { $u = cp_portal_db_ts($t->last_used_at); if ($u > $last) { $last = $u; } } }
	$di = $p && function_exists('cp_portal_deadline_info') ? cp_portal_deadline_info($p) : null;
	$miss = $p && function_exists('cp_portal_missing') && function_exists('cp_portal_answers') ? array_values(cp_portal_missing($a !== false ? (array) $a : cp_portal_answers($p->id), null, cp_portal_flow($p), $p)) : array();
	return array('none' => 0, 'mode' => cp_portal_mode(), 'st' => $s['st'], 'done' => (int) $s['done'], 'total' => (int) $s['total'], 'cd' => (int) $s['cd'], 'ct' => (int) $s['ct'],
		'deadline' => $di ? $di['text'] : '', 'passed' => $di ? (time() >= $di['deadline']) : false, 'last_open' => $last ? wp_date('Y-m-d H:i', $last) : '', 'missing' => array_slice($miss, 0, 12),
		'flags' => array_values(array_map(function ($f) { return array('t' => $f['text'] ?? '', 'tone' => $f['tone'] ?? ''); }, (array) ($s['flags'] ?? array()))), 'bg' => (string) ($s['bg'] ?? ''), 'sb' => !empty($s['sb']));
}

/* v1.92: a portálválaszok és a deal eltérései: [{k, l, p (portál), d (deal), f (deal-mező), v (beírandó érték)}] */
function cp_plk_diff($deal_id, $d, $p = false, $a = false) {
	if ($p === false) { $p = cp_portal_get_by_deal((string) $deal_id); }
	if (!$p || !function_exists('cp_portal_answers')) { return array(); }
	if ($a === false) { $a = cp_portal_answers($p->id); }
	$a = (array) $a; $out = array();
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
	/* v2.9.58: a portált és a válaszokat egyszer olvassuk be (eddig 4× / 3×) */
	$pp = cp_portal_get_by_deal($id);
	$aa = $pp && function_exists('cp_portal_answers') ? cp_portal_answers($pp->id) : array();
	$x['portal'] = cp_plk_summary($id, $pp, $aa);
	/* v1.92: amit az ügyfél a portálon másképp adott meg, mint ami a dealen van – a deal-adatlapon „Átveszem” gombbal beírható */
	if (is_array($x['portal']) && empty($x['portal']['none'])) { $x['portal']['diff'] = cp_plk_diff($id, (array) $x['deal'], $pp, $aa); }
	/* v2.9.5: az ügyfél online fotósablonjai (aktuális + az előző 5) a deal Fájlok kártyájára: előnézet, lyukasztott PNG */
	if ($pp && function_exists('cp_portal_answers') && function_exists('cp_portal_sb_file_url')) {
		$sbf = array();
		$one = function ($v, $cur) { if (!is_array($v) || empty($v['preview'])) { return null; } return array('name' => (string) ($v['name'] ?? ''), 'at' => !empty($v['at']) ? wp_date('Y-m-d H:i', (int) $v['at']) : '', 'ts' => (int) ($v['at'] ?? 0), 'cur' => $cur ? 1 : 0, 'prev' => cp_portal_sb_file_url($v['preview']), 'png' => !empty($v['png']) ? cp_portal_sb_file_url($v['png']) : '', 'logo' => !empty($v['logo']) ? cp_portal_sb_file_url($v['logo']) : ''); };
		if ($o = $one($aa['photo_template'] ?? null, true)) { $sbf[] = $o; }
		foreach ((array) ($aa['photo_template_hist'] ?? array()) as $hv) { if ($o = $one($hv, false)) { $sbf[] = $o; } }
		if ($sbf) { $x['sbf'] = $sbf; }
	}
	$ev = array();
	$p = $pp;
	if ($p) {
		$N = array('welcome' => 'Üdvözlő levél (portál)', 'invite' => 'Portál-meghívó elküldve', 'reminder_1' => 'Portál-emlékeztető (1.)', 'reminder_2' => 'Portál-emlékeztető (2.)', 'completed' => 'Az ügyfél kitöltötte a portált', 'newlink' => 'Új portál-link (az ügyfél kérte)', 'resend' => 'Portál-link újraküldve', 'mail' => 'Portál-link levélben elküldve (panel)', 'contract_ready' => 'Az ügyfél beküldte a szerződéshez szükséges adatokat', 'contract_update' => 'Az ügyfél módosította a szerződés adatait');
		/* v2.9.67: a teszt-postafiókba terelt ügyfél-levél ne tűnjön az ügyfélnek elküldöttnek */
		$tin = strtolower((string) (cp_portal_opts()['test_inbox'] ?? ''));
		foreach ((array) cp_portal_notifications($p->id) as $n) {
			$t = explode(':', (string) $n->type)[0];
			$tst = $n->channel === 'email' && ($tin !== '' && stripos((string) $n->result, $tin) !== false);
			$ev[] = array('at' => wp_date('Y-m-d H:i', cp_portal_db_ts($n->sent_at)), 'text' => ($N[$t] ?? ('Portál: ' . $t)) . ($tst ? ' – nem ment ki (teszt üzemmód)' : ($n->channel === 'email' && strpos((string) $n->result, 'ok') !== 0 && $n->result !== '' ? ' – nem ment ki' : '')), 'ch' => (string) $n->channel, 'ok' => strpos((string) $n->result, 'ok') === 0 || $n->result === '' ? 1 : 0);
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
	/* v2.9.7: csak az állapot a levélíró gombjára (link kiadása nélkül) */
	if (!empty($_POST['peek'])) {
		/* v2.9.15: a levélíró gombja nem kell, ha a portál már létrejött (a link kiment), vagy a szerződés már alá van írva */
		$hide = (bool) $p;
		if (!$hide && function_exists('cp_crm_t')) {
			global $wpdb;
			$dr = $wpdb->get_row($wpdb->prepare('SELECT stage, status FROM ' . cp_crm_t('deals') . ' WHERE id = %d', (int) $deal), ARRAY_A);
			$late = array('szerződés aláírva', 'rendezvény felkészül', 'rendezvény lement (g&r)', 'pénzre várunk');
			if ($dr && (strtolower((string) $dr['status']) === 'won' || in_array(mb_strtolower(trim((string) $dr['stage'])), $late, true))) { $hide = true; }
		}
		wp_send_json_success(($p ? array('none' => 0, 'summary' => cp_plk_summary($deal)) : array('none' => 1)) + array('hide' => $hide));
	}
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

/* v2.9.7: a levélíróból küldött levélben benne volt a deal portál-linkje → napló + a deal az „Adatlap kiküldve” szakaszba
 * (ugyanaz a szabály, mint a „Ügyfélportál kiküldése” gombnál: csak ajánlati szakaszból előre, megnyert dealnél nem). Visszatér: üzenet a küldőnek, vagy ''. */
function cp_plk_after_send($deal_id, $html) {
	$deal_id = (string) (int) $deal_id;
	if ($deal_id === '0' || !cp_plk_ready() || cp_portal_mode() !== 'on' || !function_exists('cp_portal_url')) { return ''; }
	if (strpos((string) $html, cp_portal_url('/r/')) === false) { return ''; }
	$p = cp_portal_get_by_deal($deal_id);
	if (!$p) { return ''; }
	cp_portal_notify_log($p, 'mail', 'email', 'ok: levélíró (panel)');
	$o = cp_portal_opts(); $po = cp_panel_opts(); $target = cp_portal_sent_stage();
	if (empty($o['sent_stage_write']) || $target === '' || !function_exists('cp_portal_sm_snapshot')) { return ''; }
	$snap = cp_portal_sm_snapshot($deal_id);
	if (is_wp_error($snap)) { return 'A szakaszt nem tudtam ellenőrizni (' . $snap->get_error_message() . ').'; }
	$cur = (string) $snap['stage'];
	if (cp_panel_lc($cur) === cp_panel_lc($target)) { return ''; }
	$pending = array_map('cp_panel_lc', cp_panel_lines($po['pending_stages']));
	if ($snap['status'] === 'won' || !in_array(cp_panel_lc($cur), $pending, true)) { return ''; }
	$body = array('stage' => $target);
	if ($snap['pipeline'] !== '') { $body['pipeline'] = $snap['pipeline']; }
	$put = cp_sm_put_deal($deal_id, $body);
	if (is_wp_error($put)) {
		if (function_exists('cp_portal_error_add')) { cp_portal_error_add($deal_id, 'szakaszváltás', $put->get_error_message()); }
		return 'A deal szakaszát nem sikerült átállítani („' . $target . '”): ' . $put->get_error_message();
	}
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	return 'A deal az „' . $target . '” szakaszba került.';
}

/* v2.9.9: a régebben (PNG nélkül) mentett ügyfélsablonhoz a panel Sablonkészítője elkészíti a lyukasztott PNG-t az ügyfél beállításaiból,
 * és ide tölti fel – utána a deal Fájlok kártyáján letölthető. Csak az aktuális sablonhoz, és csak ha még nincs PNG-je. */
add_action('wp_ajax_nopriv_cp_portal_sb_png', 'cp_plk_ajax_sb_png');
add_action('wp_ajax_cp_portal_sb_png', 'cp_plk_ajax_sb_png');
function cp_plk_ajax_sb_png() {
	cp_el_auth();
	cp_tab_need(array('deals', 'sablon'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_plk_ready() || !function_exists('cp_portal_sb_dataurl')) { cp_el_err('Hibás kérés.'); }
	$deal = preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['deal'] ?? ''));
	$p = $deal !== '' ? cp_portal_get_by_deal($deal) : null;
	if (!$p) { cp_el_err('Ehhez a dealhez nincs ügyfélportál.'); }
	$a = cp_portal_answers($p->id);
	$v = isset($a['photo_template']) && is_array($a['photo_template']) ? $a['photo_template'] : null;
	if (!$v || empty($v['preview']) || !preg_match('/^(p\d+-[a-z0-9]{12})-prev\.jpg$/', (string) $v['preview'], $m)) { cp_el_err('Nincs mentett ügyfélsablon.'); }
	if ((int) ($_POST['at'] ?? 0) !== (int) ($v['at'] ?? 0)) { cp_el_err('Közben új sablon került mentésre – frissítsd az oldalt.'); }
	if (!empty($v['png'])) { wp_send_json_success(array('had' => 1)); }
	$png = cp_portal_sb_dataurl((string) wp_unslash($_POST['png'] ?? ''), array('png'), 15 * MB_IN_BYTES);
	if (!$png) { cp_el_err('A PNG nem készült el.'); }
	$name = $m[1] . '-print.png';
	if (@file_put_contents(cp_portal_sb_dir() . '/' . $name, $png[1]) === false) { cp_el_err('A PNG-t nem sikerült elmenteni.', 500); }
	$v['png'] = $name;
	cp_portal_answer_put($p->id, 'photo_template', $v, 'ugyfel');
	wp_send_json_success(array('png' => cp_portal_sb_file_url($name)));
}
