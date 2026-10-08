<?php
/* Ügyfélportál – adatlap: mentés mezőnként, állapot (required_done, hianyos/kesz/modositva), zárolás */

if (!defined('ABSPATH')) { exit; }

/* le van-e zárva az ügyfél előtt (határidő után, vagy lezárt portál) */
function cp_portal_locked($portal) {
	$di = cp_portal_deadline_info($portal);
	if (!$di) { return false; }
	return time() >= $di['deadline'] || $portal->status !== 'aktiv';
}

function cp_portal_status_of($done, $total, $changed_late) {
	if ($changed_late) { return 'modositva'; }
	return $done >= $total ? 'kesz' : 'hianyos';
}

function cp_portal_status_label($st) {
	$l = array('hianyos' => 'Hiányos', 'kesz' => 'Kész', 'modositva' => 'Módosítva');
	return isset($l[$st]) ? $l[$st] : $st;
}

/* az állapot újraszámolása; ha változott, Salesmate-visszaírás a sorba */
function cp_portal_recompute($portal, $extra = array()) {
	$answers = cp_portal_answers($portal->id);
	$total = cp_portal_required_total();
	$done = $total - count(cp_portal_missing($answers));
	$st = cp_portal_state($portal->id);
	$changed_late = !empty($extra['changed_late_at']) || !empty($st->changed_late_at);
	$status = cp_portal_status_of($done, $total, $changed_late);
	$data = array_merge(array('required_done' => $done, 'status' => $status), $extra);
	cp_portal_state_put($portal->id, $data);
	if ($status !== $st->status) {
		cp_portal_sm_queue($portal->deal_id, 'status', $status);
	}
	return array('required_done' => $done, 'required_total' => $total, 'status' => $status);
}

/* Egy mező mentése. $by: ugyfel | iroda | rendszer
 * Visszatér: array(value, required_done, required_total, status, saved_at) vagy WP_Error. */
function cp_portal_save_field($portal, $key, $raw, $by) {
	$v = cp_portal_validate($key, $raw);
	if (is_wp_error($v)) { return $v; }
	$new = $v['value'];
	if ($by === 'ugyfel' && cp_portal_locked($portal)) {
		return new WP_Error('locked', 'A határidő lejárt, az adatlap már nem módosítható. Változás esetén írj nekünk.', array('status' => 423));
	}
	$rows = cp_portal_answer_rows($portal->id);
	$old = isset($rows[$key]) ? $rows[$key]['value'] : null;
	if ($key === 'retention_consent' && $old !== null && $new !== null && !empty($old['given']) === !empty($new['given'])) {
		$new = $old; // a hozzájárulás időpontja csak tényleges változáskor frissül
	}
	$saved_at = time();
	if (wp_json_encode($old) === wp_json_encode($new)) {
		$st = cp_portal_state($portal->id);
		return array('value' => $new, 'required_done' => (int) $st->required_done, 'required_total' => cp_portal_required_total(), 'status' => $st->status, 'saved_at' => $saved_at, 'unchanged' => true);
	}
	cp_portal_answer_put($portal->id, $key, $new, $by);
	$log_id = cp_portal_log_add($portal->id, $key, $old, $new, $by);

	$extra = array();
	$di = cp_portal_deadline_info($portal);
	if ($di && time() >= $di['deadline'] && $key !== 'retention_consent') {
		// határidő utáni módosítás → „módosítva” állapot, MÓDOSÍTVA címke, late_change Pumble a következő futáskor
		$extra['changed_late_at'] = cp_portal_db_now();
	}
	$res = cp_portal_recompute($portal, $extra);

	if ($key === 'venue_address' || $key === 'venue_room') {
		cp_portal_sm_queue($portal->deal_id, 'venue', cp_portal_venue_text(cp_portal_answers($portal->id)));
	}
	return array_merge($res, array('value' => $new, 'saved_at' => $saved_at, 'log_id' => $log_id));
}

/* „Kész” gomb: csak akkor sikeres, ha minden kötelező megvan */
function cp_portal_complete($portal) {
	$answers = cp_portal_answers($portal->id);
	$miss = cp_portal_missing($answers);
	if ($miss) { return array('ok' => false, 'missing' => $miss); }
	$st = cp_portal_state($portal->id);
	$first = empty($st->completed_at);
	$res = cp_portal_recompute($portal, array('completed_at' => cp_portal_db_now()));
	if ($first) {
		cp_portal_notify_once($portal, 'completed', 'email', function () use ($portal) { return cp_portal_send_client_mail($portal, 'completed'); });
		cp_portal_sm_queue($portal->deal_id, 'note', 'Adatlap kitöltve (ügyfélportál)');
	}
	return array_merge($res, array('ok' => true, 'first' => $first));
}

/* „Név, cím – terem” a Salesmate „Rendezvény helyszíne” mezőbe */
function cp_portal_venue_text($answers) {
	$v = isset($answers['venue_address']) && is_array($answers['venue_address']) ? $answers['venue_address'] : array();
	$parts = array_filter(array(isset($v['name']) ? $v['name'] : '', isset($v['address']) ? $v['address'] : ''));
	$s = implode(', ', $parts);
	if (!empty($answers['venue_room']) && is_string($answers['venue_room'])) { $s .= ($s !== '' ? ' – ' : '') . $answers['venue_room']; }
	return $s;
}

/* a /form válasz (és az oldalba ágyazott kezdőadat) */
function cp_portal_form_payload($portal) {
	$rows = cp_portal_answer_rows($portal->id);
	$answers = array();
	$meta = array();
	foreach ($rows as $k => $r) { $answers[$k] = $r['value']; $meta[$k] = array('by' => $r['by'], 'at' => $r['at']); }
	$st = cp_portal_state($portal->id);
	$di = cp_portal_deadline_info($portal);
	return array(
		'answers' => (object) $answers,
		'meta' => (object) $meta,
		'prefill' => cp_portal_prefill($portal),
		'missing' => (object) cp_portal_missing($answers),
		'state' => array('required_done' => (int) $st->required_done, 'required_total' => cp_portal_required_total(), 'status' => $st->status, 'completed_at' => cp_portal_db_ts($st->completed_at)),
		'deadline' => $di ? array('ts' => $di['deadline'], 'text' => $di['text'], 'late' => $di['late']) : null,
		'locked' => cp_portal_locked($portal),
	);
}

/* előtöltés a Salesmate-ből: kezdés és zárás (a mentéssel válik válasszá) */
function cp_portal_prefill($portal) {
	$sm = $portal->sm;
	$out = array();
	$start = isset($sm['start']) ? (string) $sm['start'] : '';
	if (cp_portal_time_ok($start)) {
		$sch = array('start' => $start);
		$h = isset($sm['hours']) ? (float) $sm['hours'] : 0;
		if ($h > 0) {
			$e = (cp_portal_minutes($start) + (int) round($h * 60)) % 1440;
			$sch['end'] = sprintf('%02d:%02d', floor($e / 60), $e % 60);
		}
		$out['schedule'] = $sch;
	}
	return (object) $out;
}
