<?php
/* =====================================================================
 * v2.9.399: Heti marketing- és CRM-riport adatai (a heti, időzített Claude-munkamenet olvassa)
 * GET /wp-json/crazy-bridge/v1/cp-report?to=YYYY-MM-DD  (a hét utolsó napja; alapból az előző vasárnap)
 * Jogosultság: csak a crazy-bridge hitelesített kérése ($GLOBALS['cb_authed']), máshonnan 403. Csak olvas.
 * Tartalom: a hét, az előző hét és a tavalyi azonos hét – megkeresések márkánként és forrásonként, webes űrlapok,
 * megnyert / elvesztett dealek, hirdetési költés kampányonként (Google, Meta), GA4 (munkamenet csatornánként,
 * köszönőoldal), a hirdetésfiókok változásai és figyelmeztetései. Ügyféladat (név, e-mail, telefon) nincs benne.
 * ===================================================================== */
if (!defined('ABSPATH')) { exit; }

function cp_rep_brand_sql() { return "CASE WHEN title LIKE '[Crazyphoto%' THEN 'cp' WHEN title LIKE '[EventCrazy%' OR title LIKE '[Eventcrazy%' THEN 'ec' ELSE 'egyeb' END"; }
function cp_rep_utc($d, $end = false) { $t = new DateTime($d . ($end ? ' 23:59:59' : ' 00:00:00'), wp_timezone()); $t->setTimezone(new DateTimeZone('UTC')); return $t->format('Y-m-d H:i:s'); }

function cp_rep_period($from, $to) {
	global $wpdb;
	$D = cp_crm_t('deals'); $H = cp_crm_t('history'); $b = cp_rep_brand_sql(); $f = cp_rep_utc($from); $t = cp_rep_utc($to, true);
	$o = array('from' => $from, 'to' => $to);
	/* új dealek márkánként és a létrehozás módja szerint (weboldal-űrlap / kolléga a panelben / Salesmate) */
	$o['new'] = $wpdb->get_results($wpdb->prepare("SELECT $b brand, COALESCE((SELECT CASE WHEN h.by_user = 'Weboldal-űrlap' THEN 'weboldal-űrlap' WHEN h.by_user = 'Crazy panel' THEN 'panel (automatikus)' ELSE 'kolléga (panel)' END FROM $H h WHERE h.deal_id = d.id AND h.field = 'created' LIMIT 1), CASE WHEN d.created_by = 999999 THEN 'weboldal-űrlap (Salesmate)' ELSE 'Salesmate' END) how, COUNT(*) n
		FROM $D d WHERE d.created_at BETWEEN %s AND %s AND d.deleted_at IS NULL GROUP BY brand, how", $f, $t), ARRAY_A);
	$o['won'] = $wpdb->get_results($wpdb->prepare("SELECT $b brand, COUNT(*) n, ROUND(SUM(value)) v FROM $D d WHERE status = 'Won' AND closed_at BETWEEN %s AND %s AND deleted_at IS NULL GROUP BY brand", $f, $t), ARRAY_A);
	$o['lost'] = $wpdb->get_results($wpdb->prepare("SELECT $b brand, COALESCE(NULLIF(lost_reason,''),'(nincs megadva)') why, COUNT(*) n FROM $D d WHERE status = 'Lost' AND closed_at BETWEEN %s AND %s AND deleted_at IS NULL GROUP BY brand, why ORDER BY n DESC", $f, $t), ARRAY_A);
	if (function_exists('cp_lead_t')) {
		$o['webforms'] = $wpdb->get_results($wpdb->prepare("SELECT CASE WHEN page LIKE '%%crazyphoto%%' THEN 'cp' WHEN page LIKE '%%eventcrazy%%' THEN 'ec' ELSE '?' END site, COUNT(*) n, SUM(deal_id > 0) deal FROM " . cp_lead_t() . ' WHERE created_at BETWEEN %s AND %s GROUP BY site', $f, $t), ARRAY_A);
	}
	/* hirdetési költés kampányonként (google: <fiók>:<kampány>, meta: <fiók>:<kampány>) */
	$o['ads'] = $wpdb->get_results($wpdb->prepare('SELECT channel, SUBSTRING_INDEX(campaign_id, \':\', 1) acct, campaign_name, ROUND(SUM(spend)) spend, SUM(clicks) clicks, SUM(impressions) impr, ROUND(SUM(conversions),1) conv FROM ' . cp_st_t('ads') . ' WHERE d BETWEEN %s AND %s GROUP BY channel, acct, campaign_name HAVING spend > 0 OR impr > 0 ORDER BY channel, spend DESC', $from, $to), ARRAY_A);
	/* GA4: munkamenet csatornánként és a köszönőoldal (property-nként) */
	$o['ga'] = array();
	if (function_exists('cp_st_ga_props')) {
		foreach (cp_st_ga_props() as $nm => $pid) {
			$r = cp_st_ga_call('runReport', array('dateRanges' => array(array('startDate' => $from, 'endDate' => $to)), 'dimensions' => array(array('name' => 'sessionDefaultChannelGroup')), 'metrics' => array(array('name' => 'sessions'), array('name' => 'keyEvents')), 'limit' => 30), $pid);
			$e = cp_st_ga_call('runReport', array('dateRanges' => array(array('startDate' => $from, 'endDate' => $to)), 'dimensions' => array(array('name' => 'eventName')), 'metrics' => array(array('name' => 'eventCount')), 'dimensionFilter' => array('filter' => array('fieldName' => 'eventName', 'inListFilter' => array('values' => array('thank_you_page', 'form_start', 'phone_click', 'email_click'))))), $pid);
			$o['ga'][$nm] = array(
				'channels' => is_wp_error($r) ? $r->get_error_message() : array_map(function ($x) { return array($x['dimensionValues'][0]['value'], (int) $x['metricValues'][0]['value'], (float) $x['metricValues'][1]['value']); }, $r['rows'] ?? array()),
				'events' => is_wp_error($e) ? $e->get_error_message() : array_combine(array_map(function ($x) { return $x['dimensionValues'][0]['value']; }, $e['rows'] ?? array()), array_map(function ($x) { return (int) $x['metricValues'][0]['value']; }, $e['rows'] ?? array())),
			);
		}
	}
	return $o;
}

function cp_rep_data($to) {
	$tz = wp_timezone();
	$end = new DateTime($to, $tz); $start = clone $end; $start->modify('-6 days');
	$p1 = clone $start; $p1->modify('-7 days'); $p2 = clone $end; $p2->modify('-7 days');
	$y1 = clone $start; $y1->modify('-364 days'); $y2 = clone $end; $y2->modify('-364 days');
	$o = array('generated' => wp_date('Y-m-d H:i'), 'week' => cp_rep_period($start->format('Y-m-d'), $end->format('Y-m-d')),
		'prev' => cp_rep_period($p1->format('Y-m-d'), $p2->format('Y-m-d')), 'last_year' => cp_rep_period($y1->format('Y-m-d'), $y2->format('Y-m-d')));
	/* 8 hetes idősor márkánként (új dealek) a trendhez */
	global $wpdb; $b = cp_rep_brand_sql();
	$o['trend_new'] = $wpdb->get_results($wpdb->prepare("SELECT YEARWEEK(CONVERT_TZ(created_at,'+00:00','+01:00'),3) w, $b brand, COUNT(*) n FROM " . cp_crm_t('deals') . ' WHERE created_at BETWEEN %s AND %s AND deleted_at IS NULL GROUP BY w, brand ORDER BY w', cp_rep_utc((clone $start)->modify('-49 days')->format('Y-m-d')), cp_rep_utc($end->format('Y-m-d'), true)), ARRAY_A);
	$o['trend_ads'] = $wpdb->get_results($wpdb->prepare("SELECT YEARWEEK(d,3) w, channel, SUBSTRING_INDEX(campaign_id, ':', 1) acct, ROUND(SUM(spend)) spend, SUM(clicks) clicks, ROUND(SUM(conversions),1) conv FROM " . cp_st_t('ads') . ' WHERE d BETWEEN %s AND %s GROUP BY w, channel, acct ORDER BY w', (clone $start)->modify('-49 days')->format('Y-m-d'), $end->format('Y-m-d')), ARRAY_A);
	/* hirdetésfiókok: mostani kampányállapot, figyelmeztetések, a hét változásai (a Google-változásoknál a módosító e-mail-címe helyett csak a domain) */
	if (function_exists('cp_aw_snaps')) {
		$o['campaigns'] = array(); foreach (cp_aw_snaps() as $k => $s) { $o['campaigns'][$k] = array('name' => $s['name'], 'at' => wp_date('Y-m-d H:i', (int) $s['at']), 'list' => $s['list']); }
		$o['alerts'] = cp_aw_alerts();
		$o['changes'] = array_map(function ($r) { $r['who'] = preg_replace('/^[^@\s]+@/', '…@', (string) $r['who']); return $r; }, (array) $wpdb->get_results($wpdb->prepare('SELECT src, acct, t, who, what, obj, detail FROM ' . cp_aw_t() . ' WHERE t BETWEEN %s AND %s ORDER BY t', cp_rep_utc($start->format('Y-m-d')), cp_rep_utc($end->format('Y-m-d'), true)), ARRAY_A));
	}
	return $o;
}

add_action('rest_api_init', function () {
	register_rest_route('crazy-bridge/v1', '/cp-report', array('methods' => 'GET', 'permission_callback' => function () { return !empty($GLOBALS['cb_authed']); }, 'callback' => function (WP_REST_Request $r) {
		$to = (string) $r->get_param('to');
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $d = new DateTime('now', wp_timezone()); $d->modify('last sunday'); $to = $d->format('Y-m-d'); }
		return cp_rep_data($to);
	}));
});
