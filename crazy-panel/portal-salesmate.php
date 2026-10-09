<?php
/* Ügyfélportál – Salesmate: portál létrehozása dealből, adatfrissítés, visszaírás újrapróbálkozással
 * A meglévő panel Salesmate-kliensét használja (cp_sm_request, cp_sm_get_deal, cp_sm_put_deal). */

if (!defined('ABSPATH')) { exit; }

/* ------------------------------------------------------------ deal → portál-adatok */

function cp_portal_sm_contact($pc) {
	$c = array('name' => '', 'email' => '', 'phone' => '');
	if (is_array($pc)) {
		$c['name'] = trim(cp_panel_str(isset($pc['name']) ? $pc['name'] : '') ?: trim((isset($pc['lastName']) ? $pc['lastName'] : '') . ' ' . (isset($pc['firstName']) ? $pc['firstName'] : '')));
		if (!empty($pc['email'])) { $c['email'] = cp_panel_str($pc['email']); }
		foreach (array('mobile', 'phone', 'otherPhone') as $k) { if ($c['phone'] === '' && !empty($pc[$k])) { $c['phone'] = cp_panel_str($pc[$k]); } }
	}
	return $c;
}

/* a deal normalizált, portálhoz szükséges adatai (vagy WP_Error) */
function cp_portal_sm_snapshot($deal_id) {
	$d = cp_sm_get_deal($deal_id);
	if (is_wp_error($d)) { return $d; }
	$p = cp_panel_opts();
	$e = cp_el_opts();
	$pc = cp_panel_get($d, 'primaryContact');
	$c = cp_portal_sm_contact($pc);
	// ha a deal csak a kapcsolat azonosítóját adja, külön lekérjük
	$cid = is_array($pc) && isset($pc['id']) ? (string) $pc['id'] : (is_scalar($pc) && preg_match('/^\d+$/', (string) $pc) ? (string) $pc : '');
	if ($c['email'] === '' && $cid !== '') {
		$cr = cp_sm_request('GET', '/apis/contact/v4/' . rawurlencode($cid));
		if (!is_wp_error($cr)) {
			$cd = isset($cr['Data']) && is_array($cr['Data']) ? $cr['Data'] : $cr;
			$c2 = cp_portal_sm_contact($cd);
			foreach ($c2 as $k => $v) { if ($c[$k] === '' && $v !== '') { $c[$k] = $v; } }
		}
	}
	$sp = cp_panel_str(cp_panel_get($d, $p['f_selfie']));
	$spin = cp_panel_str(cp_panel_get($d, $p['f_spin']));
	$svc = $e['f_svc'] !== '' ? cp_panel_str(cp_panel_get($d, $e['f_svc'])) : '';
	$svc2 = $e['f_svc2'] !== '' ? trim(cp_panel_str(cp_panel_get($d, $e['f_svc2'])), ' ,') : '';
	$package = implode(' · ', array_values(array_unique(array_filter(array($svc, $svc2)))));
	if ($package === '') { $package = implode(' + ', array_filter(array($sp, $spin))); }
	$start = $e['f_start'] !== '' ? cp_el_time(cp_panel_get($d, $e['f_start'])) : '';
	if ($start === '' && $p['f_time'] !== '') { $start = cp_panel_norm_time(cp_panel_get($d, $p['f_time'])); }
	$hours = $e['f_hours'] !== '' ? cp_panel_get($d, $e['f_hours']) : null;
	$title = cp_panel_str(cp_panel_get($d, 'title'));
	return array(
		'title'    => $title,
		'who'      => cp_el_who($title, $c['name'] !== '' ? $c['name'] : 'Deal #' . $deal_id),
		'name'     => $c['name'],
		'email'    => sanitize_email($c['email']),
		'phone'    => $c['phone'],
		'date'     => cp_panel_norm_date(cp_panel_get($d, $p['f_date'])),
		'start'    => cp_portal_time_ok($start) ? $start : '',
		'hours'    => is_numeric($hours) ? (float) $hours : 0,
		'package'  => $package,
		'selfie'   => $sp,
		'spin'     => $spin,
		'venue'    => cp_panel_str(cp_panel_get($d, $p['f_loc'])),
		'guests'   => cp_panel_str(cp_panel_get($d, $p['f_guests'])),
		'owner'    => cp_panel_str(cp_panel_get($d, 'owner')),
		'stage'    => cp_panel_str(cp_panel_get($d, 'stage')),
		'status'   => cp_panel_lc(cp_panel_str(cp_panel_get($d, 'status'))),
		'pipeline' => cp_panel_str(cp_panel_get($d, 'pipeline')),
	);
}

/* frissítés a Salesmate-ből (létrehozáskor, majd megnyitáskor legfeljebb 5 percenként); hibánál marad a régi */
function cp_portal_sm_refresh($portal, $max_age = 300) {
	if (cp_portal_db_ts($portal->sm_synced_at) > time() - $max_age) { return $portal; }
	$snap = cp_portal_sm_snapshot($portal->deal_id);
	if (is_wp_error($snap)) {
		cp_portal_update($portal->id, array('sm_synced_at' => cp_portal_db_now()));  // ne próbálja minden kérésnél
		return $portal;
	}
	$data = array('sm' => $snap, 'sm_synced_at' => cp_portal_db_now());
	$date_changed = $snap['date'] !== '' && $snap['date'] !== (string) $portal->event_date;
	if ($date_changed) { $data['event_date'] = $snap['date']; }
	cp_portal_update($portal->id, $data);
	$portal = cp_portal_get($portal->id);
	if ($date_changed) { cp_portal_tokens_reexpire($portal); }
	return $portal;
}

/* ------------------------------------------------------------ portál létrehozása */

/* Szakaszváltás jelzése (webhook, óránkénti tartalék, panel „Szerződés” pipa) – csak ha az automatikus létrehozás be van kapcsolva.
 * Idempotens: ugyanarra a dealre második portál nem jön létre. */
function cp_portal_on_stage($deal_id, $stage = '', $source = '') {
	$deal_id = (string) $deal_id;
	if (!cp_portal_db_ready()) { return 'db'; }
	if (cp_portal_mode() === 'off') { return 'off'; }
	if (!cp_portal_deal_allowed($deal_id)) { return 'auto_off'; }  // az automatikus létrehozás alapból ki van kapcsolva
	if (cp_portal_get_by_deal($deal_id)) { return 'exists'; }
	$snap = cp_portal_sm_snapshot($deal_id);
	if (is_wp_error($snap)) { cp_portal_error_add($deal_id, 'létrehozás', $snap->get_error_message()); return 'sm_error'; }
	$o = cp_portal_opts();
	if (cp_panel_lc($snap['stage']) !== cp_panel_lc($o['trigger_stage'])) { return 'stage'; }
	$r = cp_portal_create($deal_id, $snap, $source);
	return is_wp_error($r) ? $r->get_error_message() : 'created';
}

/* Portál létrehozása (a panelből kézzel is: a szakaszt ott nem nézzük).
 * Visszatér: a portál vagy WP_Error. */
function cp_portal_create($deal_id, $snap = null, $source = 'panel', $flow = 'v1', $welcome = true) {
	global $wpdb;
	if (!cp_portal_db_ready()) { return new WP_Error('db', 'A portál táblái még nem jöttek létre.'); }
	$deal_id = (string) $deal_id;
	if ($snap === null) {
		$snap = cp_portal_sm_snapshot($deal_id);
		if (is_wp_error($snap)) { return $snap; }
	}
	$p = cp_panel_opts();
	$pl = cp_panel_lc($snap['pipeline']);
	if (cp_panel_lc($p['pipeline']) !== '' && $pl !== '' && $pl !== cp_panel_lc($p['pipeline'])) { return new WP_Error('pipeline', 'A deal nem a „' . $p['pipeline'] . '” pipeline-ban van.'); }
	if ($snap['status'] === 'lost') { return new WP_Error('lost', 'A deal elvesztettként van jelölve.'); }
	if ($snap['date'] === '') { return new WP_Error('date', 'A dealnek nincs rendezvénydátuma.'); }
	if ($snap['date'] < cp_portal_today()) { return new WP_Error('past', 'A rendezvény dátuma már elmúlt.'); }

	$now = cp_portal_db_now();
	$wpdb->query($wpdb->prepare(
		'INSERT IGNORE INTO ' . cp_portal_t('portals') . ' (deal_id, status, flow, event_date, sm, sm_synced_at, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)',
		$deal_id, 'aktiv', $flow === 'v2' ? 'v2' : 'v1', $snap['date'], wp_json_encode($snap), $now, $now, $now
	));
	$created = (int) $wpdb->rows_affected === 1;
	$portal = cp_portal_get_by_deal($deal_id);
	if (!$portal) { return new WP_Error('db', 'Nem sikerült a portált létrehozni: ' . $wpdb->last_error); }
	if (!$created) { return $portal; }  // közben egy másik kérés létrehozta

	cp_portal_state_put($portal->id, array('required_done' => 0, 'status' => 'hianyos'));
	cp_portal_sm_queue($deal_id, 'status', 'hianyos');
	update_option('cp_portal_created_last', array('t' => time(), 'deal' => $deal_id, 'src' => (string) $source), false);
	if (!$welcome) { return $portal; }
	if ($snap['email'] === '') {
		cp_portal_error_add($deal_id, 'üdvözlő levél', 'A deal kapcsolattartójának nincs e-mail-címe a Salesmate-ben – a linket a panelből lehet kimásolni.');
	} else {
		cp_portal_notify_once($portal, 'welcome', 'email', function () use ($portal) { return cp_portal_send_client_mail($portal, 'welcome'); });
	}
	return $portal;
}

/* ------------------------------------------------------------ kézi kiküldés (a szerződés előtt)
 * A panel „Ügyfélportál kiküldése” gombja: létrehozza a portált (ha még nincs), elküldi a meghívó levelet
 * (újraküldésnél új linkkel), és a dealt a Salesmate-ben az „Adatlap kiküldve” szakaszba teszi – csak előre,
 * ajánlati szakaszból; aláírt szerződésnél a szakaszhoz nem nyúl. $type: '' | magan | ceg (előre beállított megrendelőtípus).
 * Visszatér: array(portal, to, stage, stage_msg, created) vagy WP_Error. */
function cp_portal_send($deal_id, $type = '') {
	if (!cp_portal_db_ready()) { return new WP_Error('db', 'A portál táblái még nem jöttek létre.'); }
	if (cp_portal_mode() !== 'on') { return new WP_Error('off', 'Az ügyfélportál ki van kapcsolva (WP admin → Beállítások → Ügyfélportál).'); }
	$deal_id = (string) $deal_id;
	$snap = cp_portal_sm_snapshot($deal_id);
	if (is_wp_error($snap)) { return $snap; }
	if ($snap['email'] === '') { return new WP_Error('no_email', 'A deal kapcsolattartójának nincs e-mail-címe a Salesmate-ben – előbb ott add meg.'); }
	$portal = cp_portal_get_by_deal($deal_id);
	$created = false;
	if (!$portal) {
		$portal = cp_portal_create($deal_id, $snap, 'panel', 'v2', false);
		if (is_wp_error($portal)) { return $portal; }
		$created = true;
	} else {
		cp_portal_update($portal->id, array('sm' => $snap, 'sm_synced_at' => cp_portal_db_now()));
		$portal = cp_portal_get($portal->id);
	}
	if (in_array($type, array('magan', 'ceg'), true)) {
		$ans = cp_portal_answers($portal->id);
		if (empty($ans['client_type'])) { cp_portal_save_field($portal, 'client_type', $type, 'iroda'); }
	}
	$to = cp_portal_send_client_mail($portal, 'invite');
	if (is_wp_error($to)) { return $to; }
	cp_portal_notify_log($portal, 'invite', 'email', 'ok: ' . $to);
	if ($created) { cp_portal_notify_log($portal, 'welcome', 'email', 'meghívó (kézi kiküldés)'); }

	// Salesmate: „Adatlap kiküldve” szakasz (csak ajánlati szakaszból előre)
	$o = cp_portal_opts();
	$p = cp_panel_opts();
	$target = cp_portal_sent_stage();
	$cur = (string) $snap['stage'];
	$stage_msg = '';
	if (!empty($o['sent_stage_write']) && $target !== '' && cp_panel_lc($cur) !== cp_panel_lc($target)) {
		$pending = array_map('cp_panel_lc', cp_panel_lines($p['pending_stages']));
		if ($snap['status'] === 'won' || !in_array(cp_panel_lc($cur), $pending, true)) {
			$stage_msg = 'A Salesmate-szakasz marad („' . $cur . '”), mert már nem ajánlati szakasz.';
		} else {
			$body = array('stage' => $target);
			if ($snap['pipeline'] !== '') { $body['pipeline'] = $snap['pipeline']; }
			$put = cp_sm_put_deal($deal_id, $body);
			if (is_wp_error($put)) {
				$stage_msg = 'A Salesmate-szakaszt nem sikerült átállítani („' . $target . '”): ' . $put->get_error_message();
				cp_portal_error_add($deal_id, 'szakaszváltás', $put->get_error_message());
			} else {
				$cur = $target;
				$stage_msg = 'Salesmate-szakasz: ' . $target;
				if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
			}
		}
	}
	return array('portal' => cp_portal_get($portal->id), 'to' => $to, 'stage' => $cur, 'stage_msg' => $stage_msg, 'created' => $created);
}

/* ------------------------------------------------------------ visszaírás, újrapróbálkozással
 * Sor: cp_portal_smq opció, kulcs „deal|fajta” (ugyanarra a mezőre mindig csak a legutóbbi érték megy).
 * Hiba esetén 1, 5, 15 perc múlva újra; a 3. újrapróbálkozás után a panel hibalistájába kerül.
 * Az adatlap mentése ettől független, az ügyfél nem lát hibát. */

function cp_portal_sm_queue($deal_id, $kind, $payload) {
	$o = cp_portal_opts();
	if ($kind === 'status' && trim((string) $o['sm_f_status']) === '') { return; }
	if ($kind === 'note' && empty($o['sm_notes'])) { return; }
	if ($kind === 'venue' && cp_portal_sm_venue_field() === '') { return; }
	if ($kind === 'bg') { $p = cp_panel_opts(); if (empty($p['bg_write']) || $p['f_bg'] === '') { return; } }
	$q = get_option('cp_portal_smq', array());
	if (!is_array($q)) { $q = array(); }
	$k = $deal_id . '|' . $kind . ($kind === 'note' ? '|' . md5((string) $payload) : '');
	$q[$k] = array('deal' => (string) $deal_id, 'kind' => $kind, 'payload' => $payload, 'tries' => 0, 'next' => time(), 'err' => '');
	update_option('cp_portal_smq', $q, false);
	if (!wp_next_scheduled('cp_portal_smq_run')) { wp_schedule_single_event(time() + 5, 'cp_portal_smq_run'); }
	$GLOBALS['cp_portal_smq_dirty'] = true;
}

function cp_portal_sm_venue_field() {
	$o = cp_portal_opts();
	if (trim((string) $o['sm_f_venue']) !== '') { return trim((string) $o['sm_f_venue']); }
	$p = cp_panel_opts();
	return (string) $p['f_loc'];
}

function cp_portal_sm_status_value($status) {
	$o = cp_portal_opts();
	foreach (cp_panel_lines($o['sm_status_labels']) as $l) {
		$x = explode('=', $l, 2);
		if (count($x) === 2 && trim($x[0]) === $status) { return trim($x[1]); }
	}
	return cp_portal_status_label($status);
}

function cp_portal_sm_do($job) {
	$o = cp_portal_opts();
	if ($job['kind'] === 'venue') {
		return cp_sm_put_deal($job['deal'], array(cp_portal_sm_venue_field() => (string) $job['payload']));
	}
	if ($job['kind'] === 'bg') {
		$p = cp_panel_opts();
		$r = cp_sm_put_deal($job['deal'], array($p['f_bg'] => (string) $job['payload']));
		if (!is_wp_error($r) && function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
		return $r;
	}
	if ($job['kind'] === 'status') {
		return cp_sm_put_deal($job['deal'], array(trim((string) $o['sm_f_status']) => cp_portal_sm_status_value((string) $job['payload'])));
	}
	if ($job['kind'] === 'note') {
		// [ellenőrizendő] a Salesmate jegyzet-végpontja; alapból kikapcsolva (sm_notes)
		$r = cp_sm_request('POST', '/apis/note/v4', array('description' => (string) $job['payload'], 'linkedTo' => array(array('id' => (int) $job['deal'], 'module' => 'Deal'))));
		return $r;
	}
	return new WP_Error('kind', 'Ismeretlen feladat.');
}

add_action('cp_portal_smq_run', 'cp_portal_smq_run');
function cp_portal_smq_run() {
	if (get_transient('cp_portal_smq_lock')) { return; }
	set_transient('cp_portal_smq_lock', 1, 120);
	$q = get_option('cp_portal_smq', array());
	if (!is_array($q)) { $q = array(); }
	$delays = array(60, 300, 900);
	$next_due = 0;
	$done = 0;
	foreach ($q as $k => $job) {
		if ($job['next'] > time() || $done >= 15) {
			$next_due = $next_due ? min($next_due, $job['next']) : $job['next'];
			continue;
		}
		$done++;
		$r = cp_portal_sm_do($job);
		// közben érkezhetett újabb érték ugyanarra a kulcsra: a friss sort olvassuk újra
		$q2 = get_option('cp_portal_smq', array());
		if (!is_array($q2)) { $q2 = array(); }
		$changed = isset($q2[$k]) && wp_json_encode($q2[$k]['payload']) !== wp_json_encode($job['payload']);
		if (!is_wp_error($r)) {
			if (!$changed) { unset($q2[$k]); }
		} else {
			$tries = (int) $job['tries'];
			if ($tries >= count($delays)) {
				cp_portal_error_add($job['deal'], 'Salesmate ' . $job['kind'], $r->get_error_message());
				if (!$changed) { unset($q2[$k]); }
			} elseif (!$changed) {
				$q2[$k]['tries'] = $tries + 1;
				$q2[$k]['next'] = time() + $delays[$tries];
				$q2[$k]['err'] = mb_substr($r->get_error_message(), 0, 200);
				$next_due = $next_due ? min($next_due, $q2[$k]['next']) : $q2[$k]['next'];
			}
		}
		update_option('cp_portal_smq', $q2, false);
		$q = $q2;
	}
	delete_transient('cp_portal_smq_lock');
	if ($next_due && !wp_next_scheduled('cp_portal_smq_run')) { wp_schedule_single_event(max(time() + 5, $next_due), 'cp_portal_smq_run'); }
}

/* Feladat a válasz elküldése utánra (FastCGI alatt a kliens addigra megkapta a választ):
 * a Salesmate-sor első próbálkozása és a panelből indított portál-létrehozás fut így. */
function cp_portal_defer($cb) {
	if (!isset($GLOBALS['cp_portal_deferred'])) { $GLOBALS['cp_portal_deferred'] = array(); }
	$GLOBALS['cp_portal_deferred'][] = $cb;
}

add_action('shutdown', 'cp_portal_shutdown', 50);
function cp_portal_shutdown() {
	$jobs = isset($GLOBALS['cp_portal_deferred']) ? $GLOBALS['cp_portal_deferred'] : array();
	$smq = !empty($GLOBALS['cp_portal_smq_dirty']);
	if (!$jobs && !$smq) { return; }
	$GLOBALS['cp_portal_deferred'] = array();
	if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
	foreach ($jobs as $cb) {
		try { call_user_func($cb); } catch (Throwable $e) { cp_portal_error_add('', 'háttérfeladat', $e->getMessage()); }
	}
	if ($smq || !empty($GLOBALS['cp_portal_smq_dirty'])) {
		$GLOBALS['cp_portal_smq_dirty'] = false;
		cp_portal_smq_run();
	}
}

/* ------------------------------------------------------------ óránkénti tartalék
 * Ha a webhook nem jön meg: a következő 12 hónap dealjei közül, amelyek most a trigger-szakaszban
 * vannak és még nincs portáljuk, létrehoz legfeljebb 10-et futásonként. */
function cp_portal_hourly_scan() {
	$o = cp_portal_opts();
	if (cp_portal_mode() === 'off' || empty($o['auto_create']) || empty($o['cron_fallback']) || !cp_portal_db_ready()) { return; }
	$from = cp_portal_today();
	$to = cp_portal_ymd_add($from, 365);
	$info = null;
	$fields = array('deal.id', 'deal.stage', 'deal.status', 'deal.pipeline', 'deal.' . cp_panel_opts()['f_date']);
	$rows = cp_sm_fetch_range($from, $to, $info, $fields);
	if (is_wp_error($rows)) { update_option('cp_portal_scan_last', array('t' => time(), 'err' => $rows->get_error_message()), false); return; }
	$want = cp_panel_lc($o['trigger_stage']);
	$cands = array();
	foreach ($rows as $r) {
		$id = cp_panel_str(cp_panel_get($r, 'id'));
		if ($id === '' || cp_panel_lc(cp_panel_str(cp_panel_get($r, 'stage'))) !== $want) { continue; }
		if (cp_panel_lc(cp_panel_str(cp_panel_get($r, 'status'))) === 'lost') { continue; }
		if (!cp_portal_deal_allowed($id)) { continue; }
		$cands[$id] = 1;
	}
	$existing = cp_portal_get_many(array_keys($cands));
	$made = 0;
	foreach (array_keys($cands) as $id) {
		if (isset($existing[(string) $id])) { continue; }
		if ($made >= 10) { break; }
		if (cp_portal_on_stage($id, '', 'cron') === 'created') { $made++; }
	}
	update_option('cp_portal_scan_last', array('t' => time(), 'seen' => count($cands), 'created' => $made), false);
}
