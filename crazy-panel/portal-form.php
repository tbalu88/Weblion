<?php
/* Ügyfélportál – adatlap: mentés mezőnként, állapot, zárolás, szerződéses lépcső
 *   a) szerződéshez szükséges adatok – azonnal kötelező; „Mehet a szerződés” jelzés a panelen + Pumble
 *   b) a rendezvény részletei – a határidőig (rendezvény − 5 nap; késői szerződésnél a meglévő szabály)
 *   c) háttér és fotósablon – nem kötelező, a határidőig módosítható */

if (!defined('ABSPATH')) { exit; }

/* v1 = a szerződés aláírása után automatikusan jött létre; v2 = kézzel kiküldve, a szerződés előtt */
function cp_portal_flow($portal) {
	return isset($portal->flow) && $portal->flow === 'v2' ? 'v2' : 'v1';
}

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
	$flow = cp_portal_flow($portal);
	$answers = cp_portal_answers($portal->id);
	$total = cp_portal_required_total(null, $flow);
	$done = $total - count(cp_portal_missing($answers, null, $flow, $portal));
	$ctotal = cp_portal_required_total('a', $flow);
	$cdone = $ctotal - count(cp_portal_missing($answers, 'a', $flow, $portal));
	$st = cp_portal_state($portal->id);
	$changed_late = !empty($extra['changed_late_at']) || !empty($st->changed_late_at);
	$status = cp_portal_status_of($done, $total, $changed_late);
	$data = array_merge(array('required_done' => $done, 'status' => $status, 'contract_done' => $cdone), $extra);
	cp_portal_state_put($portal->id, $data);
	if ($status !== $st->status) {
		cp_portal_sm_queue($portal->deal_id, 'status', $status);
	}
	return array('required_done' => $done, 'required_total' => $total, 'status' => $status, 'contract_done' => $cdone, 'contract_total' => $ctotal);
}

/* Egy mező mentése. $by: ugyfel | iroda | rendszer
 * Visszatér: array(value, required_done, required_total, status, saved_at) vagy WP_Error. */
function cp_portal_save_field($portal, $key, $raw, $by) {
	$rows = cp_portal_answer_rows($portal->id);
	$answers = array();
	foreach ($rows as $k => $r) { $answers[$k] = $r['value']; }
	$v = cp_portal_validate($key, $raw, $answers);
	if (is_wp_error($v)) { return $v; }
	$new = $v['value'];
	if ($by === 'ugyfel' && cp_portal_locked($portal)) {
		return new WP_Error('locked', 'A határidő lejárt, az adatlap már nem módosítható. Változás esetén írj nekünk.', array('status' => 423));
	}
	$old = isset($answers[$key]) ? $answers[$key] : null;
	if ($key === 'retention_consent' && $old !== null && $new !== null && !empty($old['given']) === !empty($new['given'])) {
		$new = $old; // a hozzájárulás időpontja csak tényleges változáskor frissül
	}
	if ($key === 'background' && $new !== null && $new !== $old) {
		$chk = cp_portal_bg_check($portal, $new);
		if (is_wp_error($chk)) { return $chk; }
		$new = $chk;
	}
	$saved_at = time();
	if (wp_json_encode($old) === wp_json_encode($new)) {
		$st = cp_portal_state($portal->id);
		return array('value' => $new, 'required_done' => (int) $st->required_done, 'required_total' => cp_portal_required_total(null, cp_portal_flow($portal)), 'status' => $st->status, 'saved_at' => $saved_at, 'unchanged' => true);
	}
	cp_portal_answer_put($portal->id, $key, $new, $by);
	$log_id = cp_portal_log_add($portal->id, $key, $old, $new, $by);

	$fields = cp_portal_fields();
	$extra = array();
	$di = cp_portal_deadline_info($portal);
	if ($di && time() >= $di['deadline'] && $key !== 'retention_consent') {
		// határidő utáni módosítás → „módosítva” állapot, MÓDOSÍTVA címke, late_change Pumble a következő futáskor
		$extra['changed_late_at'] = cp_portal_db_now();
	}
	$st = cp_portal_state($portal->id);
	if ($fields[$key]['stage'] === 'a' && !empty($st->contract_ready_at)) {
		// a „mehet a szerződés” jelzés után módosult szerződéses adat
		$extra['contract_changed_at'] = cp_portal_db_now();
	}
	$res = cp_portal_recompute($portal, $extra);

	if ($key === 'template_mode' && $new === 'custom' && $old !== 'custom' && $by === 'ugyfel') {
		$pp = $portal;
		cp_portal_defer(function () use ($pp) { cp_portal_pumble_custom_tpl($pp); });
	}
	if ($key === 'guests_type_dress') {
		$og = is_array($old) && isset($old['guests']) ? (int) $old['guests'] : 0;
		$gi = cp_portal_guests_info($portal, cp_portal_answers($portal->id));
		if ($by === 'ugyfel' && $gi['diff'] && $gi['chosen'] !== $og) {
			$pp = $portal;
			cp_portal_defer(function () use ($pp, $gi) { cp_portal_pumble_guests($pp, $gi); });
		}
	}
	if ($key === 'venue_address' || $key === 'venue_room') {
		cp_portal_sm_queue($portal->deal_id, 'venue', cp_portal_venue_text(cp_portal_answers($portal->id)));
	}
	if ($key === 'background') {
		cp_portal_sm_queue($portal->deal_id, 'bg', (string) $new);
	}
	return array_merge($res, array('value' => $new, 'saved_at' => $saved_at, 'log_id' => $log_id));
}

/* „Mehet a szerződés”: a szerződéshez szükséges adatok beküldése (ügyfél gombja, vagy az óránkénti tartalék).
 * Első alkalommal, illetve ha a jelzés óta módosult valami, Pumble-értesítés megy a portál webhookjára. */
function cp_portal_contract_submit($portal, $by = 'ugyfel') {
	$flow = cp_portal_flow($portal);
	$answers = cp_portal_answers($portal->id);
	$miss = cp_portal_missing($answers, 'a', $flow === 'v1' ? 'v2' : $flow);
	if ($miss) { return array('ok' => false, 'missing' => $miss); }
	$st = cp_portal_state($portal->id);
	$first = empty($st->contract_ready_at);
	$changed = !$first && !empty($st->contract_changed_at);
	if ($first || $changed) {
		cp_portal_state_put($portal->id, array('contract_ready_at' => cp_portal_db_now(), 'contract_changed_at' => null));
		$type = $first ? 'contract_ready' : 'contract_update:' . time();
		cp_portal_notify_once($portal, $type, 'pumble', function () use ($portal, $first) { return cp_portal_pumble_contract($portal, $first); });
	}
	$res = cp_portal_recompute($portal);
	return array_merge($res, array('ok' => true, 'first' => $first, 'changed' => $changed));
}

/* „Kész” gomb: csak akkor sikeres, ha minden kötelező megvan */
function cp_portal_complete($portal) {
	$flow = cp_portal_flow($portal);
	$answers = cp_portal_answers($portal->id);
	$miss = cp_portal_missing($answers, null, $flow, $portal);
	if ($miss) { return array('ok' => false, 'missing' => $miss); }
	$st = cp_portal_state($portal->id);
	if ($flow === 'v2' && empty($st->contract_ready_at)) { cp_portal_contract_submit($portal); }
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
	$flow = cp_portal_flow($portal);
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
		'missing' => (object) cp_portal_missing($answers, null, $flow, $portal),
		'flow' => $flow,
		'state' => array(
			'required_done' => (int) $st->required_done, 'required_total' => cp_portal_required_total(null, $flow), 'status' => $st->status, 'completed_at' => cp_portal_db_ts($st->completed_at),
			'contract_done' => (int) $st->contract_done, 'contract_total' => cp_portal_required_total('a', $flow), 'contract_ready_at' => cp_portal_db_ts($st->contract_ready_at), 'contract_changed_at' => cp_portal_db_ts($st->contract_changed_at),
		),
		'deadline' => $di ? array('ts' => $di['deadline'], 'text' => $di['text'], 'late' => $di['late']) : null,
		'locked' => cp_portal_locked($portal),
	);
}

/* előtöltés a Salesmate-ből (a mentéssel válik válasszá): dátum, kezdés és vége, szolgáltatás, a kapcsolattartó adatai */
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
	if (!empty($portal->event_date)) { $out['event_date'] = (string) $portal->event_date; }
	$name = isset($sm['name']) ? (string) $sm['name'] : '';
	$email = isset($sm['email']) ? (string) $sm['email'] : '';
	$phone = isset($sm['phone']) ? (string) $sm['phone'] : '';
	if ($name !== '' || $email !== '' || $phone !== '') {
		$out['client_private'] = array_filter(array('name' => $name, 'email' => $email, 'phone' => $phone));
		$out['client_company'] = array_filter(array('contact_name' => $name, 'contact_email' => $email, 'contact_phone' => $phone, 'billing_email' => $email));
	}
	$gu = cp_portal_orig_guests($portal);
	if ($gu > 0) { $out['guests_type_dress'] = array('guests' => $gu); }
	return (object) $out;
}

/* ------------------------------------------------------------ létszám: az ajánlatkérésben (Salesmate) szereplő vs. a megadott */

function cp_portal_orig_guests($portal) {
	$g = isset($portal->sm['guests']) ? preg_replace('/[^0-9]/', '', (string) $portal->sm['guests']) : '';
	$g = (int) $g;
	return $g > 0 && $g <= 5000 ? $g : 0;
}

/* nyomtatós szolgáltatás-e (szelfigép, selfie stand, AI fotózás, AI karikatúra, greenbox) – csak ezeknél számít a létszám.
 * Ha a Salesmate-ből semmi nem derül ki a szolgáltatásról, igennek vesszük. */
function cp_portal_has_print($portal) {
	$sm = $portal->sm;
	if (isset($sm['selfie']) && trim((string) $sm['selfie']) !== '') { return true; }
	$pkg = isset($sm['package']) ? (string) $sm['package'] : '';
	if (preg_match('/szelfi|selfie|green|fotobox|karikat|(^|[^\p{L}])ai([^\p{L}]|$)/iu', $pkg)) { return true; }
	return $pkg === '' && (!isset($sm['spin']) || trim((string) $sm['spin']) === '');
}

/* diff: 0 nincs eltérés, 1 több, -1 kevesebb; nem nyomtatós szolgáltatásnál nincs összevetés */
function cp_portal_guests_info($portal, $answers) {
	$orig = cp_portal_has_print($portal) ? cp_portal_orig_guests($portal) : 0;
	$chosen = isset($answers['guests_type_dress']['guests']) ? (int) $answers['guests_type_dress']['guests'] : 0;
	$diff = $orig > 0 && $chosen > 0 && $chosen !== $orig ? ($chosen > $orig ? 1 : -1) : 0;
	return array('orig' => $orig, 'chosen' => $chosen, 'diff' => $diff);
}

/* ------------------------------------------------------------ időtartam: a foglalt csomag óraszáma vs. a megadott menetrend */

/* a menetrend hossza órában (éjfélen átnyúló zárással), vagy 0 */
function cp_portal_schedule_hours($sch) {
	if (!is_array($sch) || empty($sch['start']) || empty($sch['end']) || !cp_portal_time_ok($sch['start']) || !cp_portal_time_ok($sch['end'])) { return 0; }
	$s = cp_portal_minutes($sch['start']);
	$e = cp_portal_minutes($sch['end']);
	if ($e <= $s) { $e += 1440; }
	return round(($e - $s) / 60, 2);
}

function cp_portal_hours_text($h) {
	return rtrim(rtrim(number_format((float) $h, 2, ',', ''), '0'), ',') . ' óra';
}

/* melléknévként: „4 órás” */
function cp_portal_hours_adj($h) {
	return rtrim(rtrim(number_format((float) $h, 2, ',', ''), '0'), ',') . ' órás';
}

/* array(pkg => a foglalás szerinti óra (0 = nem ismert), chosen => a megadott óra (0 = nincs), diff => eltér-e) */
function cp_portal_duration_info($portal, $answers) {
	$pkg = isset($portal->sm['hours']) ? (float) $portal->sm['hours'] : 0;
	$chosen = cp_portal_schedule_hours(isset($answers['schedule']) ? $answers['schedule'] : null);
	return array('pkg' => $pkg, 'chosen' => $chosen, 'diff' => $pkg > 0 && $chosen > 0 && abs($chosen - $pkg) >= 0.25);
}
