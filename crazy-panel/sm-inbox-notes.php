<?php
/* =====================================================================
 * v2.9.84: a Salesmate Team Inbox levélszálainak belső jegyzetei a panelbe
 * A Salesmate levélszálain (Team Inbox) írt belső jegyzeteket a panel átveszi – a Salesmate-be NEM ír, csak olvas (GET).
 * Hova kerül a jegyzet:
 *   1. a panel megfelelő levélszálára (crazy_mail_notes) – a szál a deal + a tárgy (deal-jel nélkül), ennek híján a levelek ideje szerint;
 *      több egyezésnél az, amelyiknek a levelei időben a legközelebb vannak;
 *   2. ha nincs ilyen szál: deal-jegyzetként (crazy_crm_notes, src = 'smi') „Salesmate-levélszál: <tárgy> – ” előtaggal.
 * Kétszer soha nem kerül be: a Salesmate-jegyzet azonosítója a saját kis összerendelő táblában (crazy_smn_map, elsődleges kulcs).
 *   Miért külön tábla: (a) mindkét célhelyhez kell, (b) a deal-jegyzetek sm_id oszlopát a cp_dact_pull() törlés-szinkronja
 *   kitörölné (ott az sm_id a deal-jegyzet azonosítója), (c) így a levelezés táblájához (mail.php DB-verzió) nem kell nyúlni.
 * A Salesmate-ben törölt jegyzet itt megmarad (nem törlünk).
 * Háttérfeladatok (mindkettő alapból KI, a cp_smn_on beállítás kapcsolja; a WP-admin Postafiók oldalán is):
 *   - bf: minden deal visszamenőleg, a legújabbtól a régebbiek felé, percenként legfeljebb 10 deal, 35 mp-es keret, zárral, folytatható;
 *   - inc: 15 percenként az elmúlt 2 napban mozgott dealek.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_SMN_DB_VER', '1');
function cp_smn_t() { global $wpdb; return $wpdb->prefix . 'crazy_smn_map'; }

/* v2.9.84: összerendelő tábla – a dbDelta csak nem-AJAX kérésben, a verziójelző automatikusan betöltve (a többi modul mintája) */
add_action('init', function () {
	if (wp_doing_ajax()) { return; }
	if (get_option('cp_smn_db_ver') === CP_SMN_DB_VER) { if (function_exists('cp_opt_autoload')) { cp_opt_autoload('cp_smn_db_ver'); } return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta('CREATE TABLE ' . cp_smn_t() . " (
  sm_note_id bigint(20) unsigned NOT NULL,
  conv_id bigint(20) unsigned NOT NULL DEFAULT 0,
  deal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind char(1) NOT NULL DEFAULT '',
  thread_id bigint(20) unsigned NULL,
  row_id bigint(20) unsigned NULL,
  created_at datetime NULL,
  PRIMARY KEY  (sm_note_id),
  KEY deal_id (deal_id)
) " . $wpdb->get_charset_collate() . ';');
	update_option('cp_smn_db_ver', CP_SMN_DB_VER, true);
}, 7);
function cp_smn_db_ok() { return get_option('cp_smn_db_ver') === CP_SMN_DB_VER; }

/* v2.9.84: kapcsolók – array('bf' => 0|1, 'inc' => 0|1, 'rd' => 0|1); rd = a friss, olvasatlan szálakat is megnyitja */
function cp_smn_on() {
	$o = get_option('cp_smn_on', array());
	return array_merge(array('bf' => 0, 'inc' => 0, 'rd' => 0), is_array($o) ? array_map('intval', $o) : array());
}
function cp_smn_set($k, $v) { $o = cp_smn_on(); $o[$k] = $v ? 1 : 0; update_option('cp_smn_on', $o, true); }

/* ------------------------------------------------------------ Salesmate-olvasás: csak GET, 429/5xx esetén visszavárás */

/* v2.9.84: a hívások között kis szünet (a Salesmate percenkénti korlátja miatt); 429/5xx/hálózati hiba után egy újrapróba,
 * utána visszavárás (5 → 10 → 20 … legfeljebb 60 perc), és a futás leáll. A hibaüzenetből csak a kód kerül tovább. */
function cp_smn_get($path, &$ctx) {
	if (!empty($ctx['rate'])) { return null; }
	for ($try = 0; $try < 2; $try++) {
		if (!empty($ctx['calls'])) { usleep((int) ($ctx['gap'] ?? 250000)); }
		$ctx['calls'] = (int) ($ctx['calls'] ?? 0) + 1;
		$r = cp_sm_request('GET', $path);
		if (!is_wp_error($r)) { return $r; }
		$code = preg_match('/\((\d{3})\)/', $r->get_error_message(), $m) ? (int) $m[1] : 0;
		if ($code && $code !== 429 && $code < 500) { $ctx['err4'] = $code; return null; } /* tartós hiba (pl. 404): ezt a tételt kihagyjuk */
		if ($try === 0 && empty($ctx['nowait'])) { sleep(2); continue; }
		$ctx['rate'] = $code ?: 1;
		if (empty($ctx['dry'])) {
			$n = min(60, max(5, (int) get_option('cp_smn_bo', 0) * 2));
			update_option('cp_smn_bo', $n, false);
			set_transient('cp_smn_backoff', 1, $n * MINUTE_IN_SECONDS);
		}
		return null;
	}
	return null;
}
/* a válasz listája – a Salesmate hol 'Data' listát, hol 'Data.data'-t ad */
function cp_smn_rows($r) {
	if (!is_array($r)) { return array(); }
	$d = array_key_exists('Data', $r) ? $r['Data'] : $r;
	if (is_array($d) && isset($d['data']) && is_array($d['data'])) { return $d['data']; }
	if (is_array($d) && isset($d['timeline']) && is_array($d['timeline'])) { return $d['timeline']; }
	return is_array($d) && (isset($d[0]) || $d === array()) ? $d : array();
}

/* ------------------------------------------------------------ átalakítások */

/* v2.9.84: Salesmate-időpont → UTC 'Y-m-d H:i:s' (epoch mp / ms, ISO 'Z'-vel vagy eltolással; zóna nélküli szöveg = UTC) */
function cp_smn_ts($v) {
	if (is_array($v)) { $v = $v['date'] ?? ($v['value'] ?? ''); }
	if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{9,13}$/', trim($v)))) {
		$n = (float) $v; if ($n > 1e11) { $n = $n / 1000; }
		return $n > 0 ? gmdate('Y-m-d H:i:s', (int) $n) : '';
	}
	$s = trim((string) $v);
	if ($s === '') { return ''; }
	$tz = preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $s) ? null : new DateTimeZone('UTC');
	try { $d = $tz ? new DateTime($s, $tz) : new DateTime($s); } catch (Exception $e) { return ''; }
	return gmdate('Y-m-d H:i:s', $d->getTimestamp());
}

/* v2.9.84: a jegyzet HTML-je sima szöveggé, a megemlítés „@Név” marad (span/a megemlítő jelöléssel vagy @[Név](…) alakban) */
function cp_smn_text($html) {
	$h = (string) $html;
	$h = preg_replace_callback('#<(span|a)\b([^>]*)>(.*?)</\1>#is', function ($m) {
		if (!preg_match('/mention|data-(user|mention)/i', $m[2])) { return $m[0]; }
		$n = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));
		if ($n === '' && preg_match('/data-(?:mention-)?(?:name|value|label)\s*=\s*"([^"]*)"/i', $m[2], $a)) { $n = html_entity_decode($a[1], ENT_QUOTES, 'UTF-8'); }
		return $n === '' ? '' : '@' . ltrim($n, '@ ');
	}, $h);
	$h = preg_replace('/@\[([^\]]{1,80})\]\([^)]*\)/u', '@$1', $h);
	if (function_exists('cp_dact_plain')) { $t = cp_dact_plain($h); }
	else {
		$t = preg_replace('#<\s*(br|/p|/div|/li)\b[^>]*>#i', "\n", $h);
		$t = html_entity_decode(wp_strip_all_tags($t), ENT_QUOTES, 'UTF-8');
		$t = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/u', ' ', $t)));
	}
	return trim(preg_replace('/@{2,}/', '@', $t));
}

/* v2.9.84: a jegyzet írója → név (objektum névvel, vagy Salesmate-felhasználó azonosító a cp_crm_users() szerint) */
function cp_smn_author($e) {
	$d = is_array($e['data'] ?? null) ? $e['data'] : array();
	foreach (array($e['createdBy'] ?? null, $d['createdBy'] ?? null, $d['userId'] ?? null, $e['userId'] ?? null) as $c) {
		if (is_array($c)) {
			$n = trim((string) ($c['name'] ?? (($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''))));
			if ($n !== '') { return $n; }
			$c = $c['id'] ?? null;
		}
		if (is_numeric($c) && (int) $c > 0 && function_exists('cp_crm_users')) {
			$l = cp_crm_users();
			if (!empty($l[(int) $c]['n'])) { return (string) $l[(int) $c]['n']; }
		}
	}
	return 'Salesmate';
}
/* panel-felhasználó azonosítója név szerint (kis-nagybetű nélkül); 0, ha nincs ilyen */
function cp_smn_uid($name) {
	if (!function_exists('cp_pu_data')) { return 0; }
	$k = mb_strtolower(trim((string) $name));
	if ($k === '') { return 0; }
	foreach (cp_pu_data()['list'] as $id => $u) { if (mb_strtolower(trim((string) ($u['n'] ?? ''))) === $k) { return (int) $id; } }
	return 0;
}
function cp_smn_mentions($text) {
	$out = array();
	if (!function_exists('cp_pu_data')) { return $out; }
	foreach (cp_pu_data()['list'] as $id => $u) { if (!empty($u['a']) && ($u['n'] ?? '') !== '' && mb_stripos($text, '@' . $u['n']) !== false) { $out[] = (int) $id; } }
	return $out;
}
function cp_smn_norm($s) {
	$s = function_exists('cp_dl_strip_token') ? cp_dl_strip_token($s) : (string) $s;
	return function_exists('cp_imp_norm_subj') ? cp_imp_norm_subj($s) : mb_strtolower(trim($s));
}

/* ------------------------------------------------------------ olvasás a Salesmate-ből */

/* v2.9.84: a deal idővonalának levélszálai: conversationId => array(subject, unread, last) – lapozva, amíg teli lap jön */
function cp_smn_convs($deal, &$ctx) {
	$convs = array();
	$types = 'TeamInboxConversationEmailReceived,TeamInboxConversationEmailSent';
	$nf = (int) get_option('cp_smn_nf', 0); /* 1 = a szűrő nélküli lekérés (ha a szűrt 4xx-et adna) */
	for ($from = 0; $from < 2000; $from += 50) {
		$p = '/apis/timeline/v4/activity-feed/module/4/object/' . (int) $deal . '/past-timeline-entries?rows=50&from=' . $from;
		$r = cp_smn_get($p . ($nf ? '' : '&filterBy=emails&activitiesToQuery=' . $types), $ctx);
		if ($r === null && !$nf && $from === 0 && !empty($ctx['err4']) && empty($ctx['rate'])) {
			unset($ctx['err4']); $nf = 1;
			if (empty($ctx['dry'])) { update_option('cp_smn_nf', 1, false); }
			$r = cp_smn_get($p, $ctx);
		}
		if ($r === null) { return null; }
		$rows = cp_smn_rows($r);
		foreach ($rows as $it) {
			if (!is_array($it)) { continue; }
			$ob = is_array($it['object'] ?? null) ? $it['object'] : array();
			$cid = $ob['conversationId'] ?? null;
			if (is_array($cid)) { $cid = $cid['id'] ?? null; }
			if (!is_numeric($cid) || (int) $cid <= 0) { continue; }
			$cid = (int) $cid;
			if (!isset($convs[$cid])) { $convs[$cid] = array('subject' => (string) ($ob['subject'] ?? ''), 'unread' => array_key_exists('isRead', $ob) && $ob['isRead'] === false, 'last' => cp_smn_ts($ob['latestReceivedEmailDate'] ?? ($it['createdAtTS'] ?? ($it['createdAt'] ?? '')))); }
		}
		if (count($rows) < 50) { break; }
	}
	return $convs;
}
/* v2.9.84: egy levélszál idővonala: jegyzetek + a levelek időpontjai (a szál-egyeztetéshez) */
function cp_smn_conv_tl($cid, &$ctx) {
	$notes = array(); $mails = array(); $subj = '';
	for ($from = 0; $from < 1000; $from += 100) {
		$r = cp_smn_get('/apis/v3/teamInboxes/conversations/' . (int) $cid . '/timeline?rows=100&from=' . $from . '&folderName=', $ctx);
		if ($r === null) { return null; }
		$rows = cp_smn_rows($r);
		foreach ($rows as $e) {
			if (!is_array($e)) { continue; }
			$ty = (string) ($e['type'] ?? ($e['activityType'] ?? ''));
			$d = is_array($e['data'] ?? null) ? $e['data'] : array();
			if ($ty === 'TeamInboxConversationNoteAdded') {
				$nid = (int) ($d['id'] ?? 0) ?: (int) ($e['id'] ?? 0);
				if ($nid > 0) { $notes[$nid] = array('id' => $nid, 'at' => cp_smn_ts($e['createdAt'] ?? ($d['createdAt'] ?? '')), 'html' => (string) ($d['note'] ?? ''), 'e' => $e); }
			} elseif (preg_match('/Email(Sent|Received)$/', $ty)) {
				$t = cp_smn_ts($e['createdAt'] ?? ($d['emailDate'] ?? ($d['receivedDate'] ?? '')));
				if ($t !== '') { $mails[] = strtotime($t . ' UTC'); }
				if ($subj === '' && !empty($d['subject'])) { $subj = (string) $d['subject']; }
			}
		}
		if (count($rows) < 100) { break; }
	}
	return array('notes' => array_values($notes), 'mails' => $mails, 'subject' => $subj);
}

/* ------------------------------------------------------------ szál-egyeztetés */

/* v2.9.84: a panel levélszála a dealen: 1. azonos tárgy (deal-jel és RE:/FW: nélkül), 2. ennek híján levél ±30 percen belül;
 * több jelöltből az, amelyiknek a levelei időben a legközelebb vannak. Visszaad: array(thread_id|0, deal_id, mód, jelöltszám) */
function cp_smn_match($deal, $subject, $times) {
	global $wpdb;
	$th = (array) $wpdb->get_results($wpdb->prepare('SELECT id, subject, deal_id FROM ' . cp_mail_t('threads') . " WHERE deal_id = %d AND folder <> 'trash'", $deal), ARRAY_A);
	if (!$th) { return array(0, 0, 'none', 0); }
	$ns = cp_smn_norm($subject);
	$subj = $ns === '' ? array() : array_values(array_filter($th, function ($t) use ($ns) { return cp_smn_norm($t['subject']) === $ns; }));
	$pool = $subj ?: $th;
	$ids = implode(',', array_map(function ($t) { return (int) $t['id']; }, $pool));
	$ms = array();
	foreach ((array) $wpdb->get_results('SELECT thread_id, sent_at FROM ' . cp_mail_t('messages') . " WHERE thread_id IN ($ids)", ARRAY_A) as $m) { $ms[(int) $m['thread_id']][] = strtotime($m['sent_at'] . ' UTC'); }
	$best = 0; $bd = PHP_INT_MAX; $by = array();
	foreach ($pool as $t) {
		$d = PHP_INT_MAX;
		foreach ($ms[(int) $t['id']] ?? array() as $a) { foreach ($times as $b) { $d = min($d, abs($a - $b)); } }
		$by[(int) $t['id']] = (int) $t['deal_id'];
		if ($d < $bd || ($d === $bd && !$best)) { $bd = $d; $best = (int) $t['id']; }
	}
	if ($subj) { return array($best, $by[$best], 'subj', count($subj)); }
	if ($best && $bd <= 1800) { return array($best, $by[$best], 'time', count($th)); }
	return array(0, 0, 'none', count($th));
}

/* ------------------------------------------------------------ egy deal átvétele */

/* v2.9.84: egy deal Salesmate-levélszálainak jegyzetei → panel. $dry = csak számol, semmit nem ír.
 * Visszaad: számlálók + 'm' (szálanként: conv, notes, mód, thread, jelöltek) – szöveg és név nélkül. 'rate' = a Salesmate visszafogott (később újra). */
function cp_smn_import_deal($dealId, $dry = false, &$ctx = null) {
	global $wpdb;
	$deal = (int) $dealId;
	if (!is_array($ctx)) { $ctx = array(); }
	unset($ctx['err4']);
	$ctx['dry'] = $dry ? 1 : 0;
	$st = array('deal' => $deal, 'convs' => 0, 'notes' => 0, 'new' => 0, 'dup' => 0, 'thread' => 0, 'dnote' => 0, 'unread' => 0, 'empty' => 0, 'err' => 0, 'rate' => 0, 'm' => array());
	if ($deal <= 0 || (function_exists('cp_crm_primary') && cp_crm_primary())) { $st['err'] = 1; return $st; }
	if (!$dry && !cp_smn_db_ok()) { $st['err'] = 1; return $st; }
	$convs = cp_smn_convs($deal, $ctx);
	if ($convs === null) { if (!empty($ctx['rate'])) { $st['rate'] = 1; } else { $st['err'] = 1; } return $st; }
	$st['convs'] = count($convs);
	$on = cp_smn_on();
	$fresh = time() - 2 * DAY_IN_SECONDS;
	foreach ($convs as $cid => $cv) {
		/* a friss, olvasatlan szálat nem nyitjuk meg (a Salesmate-ben ne legyen olvasott) – ahogy a régi átvevő program is */
		if ($cv['unread'] && empty($on['rd']) && ($cv['last'] === '' || strtotime($cv['last'] . ' UTC') > $fresh)) { $st['unread']++; continue; }
		$tl = cp_smn_conv_tl($cid, $ctx);
		if ($tl === null) { if (!empty($ctx['rate'])) { $st['rate'] = 1; return $st; } $st['err']++; unset($ctx['err4']); continue; }
		if (!$tl['notes']) { $st['empty']++; continue; }
		$subject = $cv['subject'] !== '' ? $cv['subject'] : $tl['subject'];
		$st['notes'] += count($tl['notes']);
		$ids = array_map(function ($n) { return (int) $n['id']; }, $tl['notes']);
		$have = array_map('intval', (array) $wpdb->get_col('SELECT sm_note_id FROM ' . cp_smn_t() . ' WHERE sm_note_id IN (' . implode(',', $ids) . ')'));
		$todo = array_values(array_filter($tl['notes'], function ($n) use ($have) { return !in_array((int) $n['id'], $have, true); }));
		$st['dup'] += count($tl['notes']) - count($todo);
		$times = $tl['mails'] ?: array_map(function ($n) { return strtotime($n['at'] . ' UTC'); }, $tl['notes']);
		list($tid, $tdeal, $how, $nc) = cp_smn_match($deal, $subject, $times);
		$st['m'][] = array('conv' => (int) $cid, 'notes' => count($tl['notes']), 'new' => count($todo), 'how' => $how, 'thread' => $tid, 'cands' => $nc);
		foreach ($todo as $n) {
			$text = mb_substr(cp_smn_text($n['html']), 0, 4000);
			if ($text === '') { $st['empty']++; continue; }
			$st['new']++;
			if ($tid) { $st['thread']++; } else { $st['dnote']++; }
			if ($dry) { continue; }
			$at = $n['at'] !== '' ? $n['at'] : gmdate('Y-m-d H:i:s');
			$name = mb_substr(cp_smn_author($n['e']), 0, 100);
			$uid = cp_smn_uid($name);
			/* előbb a foglalás az összerendelő táblában (elsődleges kulcs) – így párhuzamos futásnál sem kerül be kétszer */
			$ok = $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . cp_smn_t() . ' (sm_note_id, conv_id, deal_id, kind, thread_id, created_at) VALUES (%d, %d, %d, %s, %d, %s)', (int) $n['id'], (int) $cid, $deal, $tid ? 't' : 'd', $tid, gmdate('Y-m-d H:i:s')));
			if (!$ok) { $st['new']--; $st['dup']++; if ($tid) { $st['thread']--; } else { $st['dnote']--; } continue; }
			if ($tid) {
				$w = $wpdb->insert(cp_mail_t('notes'), array('thread_id' => $tid, 'deal_id' => $tdeal ?: $deal, 'body' => $text, 'author_uid' => $uid, 'author' => $name, 'mentions' => implode(',', cp_smn_mentions($text)), 'created_at' => $at));
			} else {
				$pre = 'Salesmate-levélszál: ' . ($subject !== '' ? cp_smn_subj($subject) : '(tárgy nélkül)') . ' – ';
				$w = $wpdb->insert(cp_dact_t(), array('deal_id' => $deal, 'sm_id' => null, 'body' => mb_substr($pre . $text, 0, 5000), 'author' => $name, 'author_uid' => $uid ?: null, 'src' => 'smi', 'created_at' => $at));
			}
			if ($w) { $wpdb->update(cp_smn_t(), array('row_id' => (int) $wpdb->insert_id), array('sm_note_id' => (int) $n['id'])); }
			else { $wpdb->delete(cp_smn_t(), array('sm_note_id' => (int) $n['id'])); $st['new']--; $st['err']++; if ($tid) { $st['thread']--; } else { $st['dnote']--; } }
		}
	}
	return $st;
}
function cp_smn_subj($s) { return mb_substr(function_exists('cp_dl_strip_token') ? cp_dl_strip_token($s) : trim((string) $s), 0, 200); }

/* ------------------------------------------------------------ háttérfeladatok */

add_filter('cron_schedules', function ($s) { $s['cp_smn_15'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => '15 percenként (SM-szál jegyzetek)'); return $s; });
add_action('init', function () {
	if (wp_doing_ajax()) { return; }
	if (function_exists('cp_opt_autoload')) { cp_opt_autoload('cp_smn_on'); }
	$on = cp_smn_on();
	$bf = get_option('cp_smn_bf', array());
	$want = cp_smn_db_ok() && $on['bf'] && empty($bf['done']);
	$has = wp_next_scheduled('cp_smn_bf');
	if ($want && !$has) { wp_schedule_event(time() + 60, 'cp_minute', 'cp_smn_bf'); }
	if (!$want && $has) { wp_clear_scheduled_hook('cp_smn_bf'); }
	$want = cp_smn_db_ok() && $on['inc'];
	$has = wp_next_scheduled('cp_smn_inc');
	if ($want && !$has) { wp_schedule_event(time() + 120, 'cp_smn_15', 'cp_smn_inc'); }
	if (!$want && $has) { wp_clear_scheduled_hook('cp_smn_inc'); }
}, 31);
add_action('cp_smn_bf', 'cp_smn_backfill');
add_action('cp_smn_inc', 'cp_smn_incremental');

/* közös zár (a visszamenőleges és a 15 perces ne fusson egyszerre) – MySQL GET_LOCK, ha van; különben átmeneti jelző */
function cp_smn_lock() {
	if (function_exists('cp_mail_lock')) { return cp_mail_lock('smn'); }
	if (get_transient('cp_smn_lock')) { return false; }
	set_transient('cp_smn_lock', 1, 120); return true;
}
function cp_smn_unlock() { if (function_exists('cp_mail_unlock')) { cp_mail_unlock('smn'); } else { delete_transient('cp_smn_lock'); } }
function cp_smn_can_run() {
	return cp_smn_db_ok() && !get_transient('cp_smn_backoff') && !(function_exists('cp_crm_primary') && cp_crm_primary()) && (!function_exists('cp_crm_db_ok') || cp_crm_db_ok());
}
function cp_smn_add(&$bf, $st) { foreach (array('convs', 'new', 'dup', 'thread', 'dnote', 'unread', 'err') as $k) { $bf[$k] = (int) ($bf[$k] ?? 0) + (int) $st[$k]; } }

/* v2.9.84: visszamenőleges átvétel – a legújabb dealtől lefelé (id szerint), futásonként ≤ 10 deal és ≤ 35 mp; az előrehaladás a cp_smn_bf-ben */
function cp_smn_backfill($budget = 35) {
	global $wpdb;
	if (!cp_smn_on()['bf'] || !cp_smn_can_run() || !cp_smn_lock()) { return; }
	$t0 = microtime(true);
	$bf = get_option('cp_smn_bf', array());
	if (!is_array($bf)) { $bf = array(); }
	if (!empty($bf['done'])) { cp_smn_unlock(); return; }
	if (empty($bf['start'])) { $bf['start'] = time(); }
	$last = isset($bf['last']) ? (int) $bf['last'] : 2147483647;
	$ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . cp_crm_t('deals') . ' WHERE deleted_at IS NULL AND id < %d ORDER BY id DESC LIMIT 40' /* v2.9.85: 10 helyett 40 – a külső cron 5 percenként fut, a 35 mp-es keret úgyis megállítja */, $last)));
	$ctx = array();
	foreach ($ids as $id) {
		if (microtime(true) - $t0 > $budget) { break; }
		$st = cp_smn_import_deal($id, false, $ctx);
		if ($st['rate']) { $bf['rate'] = time(); break; } /* ezt a dealt a következő futás újra veszi */
		cp_smn_add($bf, $st);
		$bf['last'] = $id; $bf['deals'] = (int) ($bf['deals'] ?? 0) + 1;
		if ($st['convs']) { $bf['with'] = (int) ($bf['with'] ?? 0) + 1; }
	}
	if (empty($ctx['rate'])) { delete_option('cp_smn_bo'); }
	if (!$ids) { $bf['done'] = time(); wp_clear_scheduled_hook('cp_smn_bf'); }
	$bf['t'] = time();
	update_option('cp_smn_bf', $bf, false);
	cp_smn_unlock();
}

/* v2.9.84: 15 percenként – az elmúlt 2 napban kommunikált / módosult dealek (≤ 30 deal, ≤ 35 mp); amit az utolsó óra óta
 * már átnézett és azóta nem mozdult, azt kihagyja */
function cp_smn_incremental($budget = 35) {
	global $wpdb;
	if (!cp_smn_on()['inc'] || !cp_smn_can_run() || !cp_smn_lock()) { return; }
	$t0 = microtime(true);
	$since = gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS);
	$D = cp_crm_t('deals');
	$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT id, GREATEST(COALESCE(last_comm_at, '1970-01-01'), COALESCE(modified_at, '1970-01-01')) mv FROM $D WHERE deleted_at IS NULL AND (last_comm_at >= %s OR modified_at >= %s) ORDER BY mv DESC LIMIT 60", $since, $since), ARRAY_A);
	$inc = get_option('cp_smn_inc', array());
	if (!is_array($inc)) { $inc = array(); }
	$seen = is_array($inc['seen'] ?? null) ? $inc['seen'] : array();
	$ctx = array(); $n = 0;
	foreach ($rows as $r) {
		$id = (int) $r['id']; $mv = strtotime($r['mv'] . ' UTC');
		if (isset($seen[$id]) && $seen[$id] > $mv && $seen[$id] > time() - HOUR_IN_SECONDS) { continue; }
		if ($n >= 30 || microtime(true) - $t0 > $budget) { break; }
		$st = cp_smn_import_deal($id, false, $ctx);
		if ($st['rate']) { break; }
		cp_smn_add($inc, $st);
		$seen[$id] = time(); $n++;
	}
	if (empty($ctx['rate'])) { delete_option('cp_smn_bo'); }
	$lim = time() - 3 * DAY_IN_SECONDS;
	$inc['seen'] = array_filter($seen, function ($t) use ($lim) { return $t > $lim; });
	$inc['t'] = time(); $inc['runs'] = (int) ($inc['runs'] ?? 0) + 1;
	update_option('cp_smn_inc', $inc, false);
	cp_smn_unlock();
}

/* ------------------------------------------------------------ WP-admin → Postafiók: állapot, indítás / szüneteltetés, próba */

function cp_smn_status() {
	$on = cp_smn_on(); $bf = (array) get_option('cp_smn_bf', array()); $inc = (array) get_option('cp_smn_inc', array());
	$s = 'Visszamenőleges: ' . (!empty($bf['done']) ? 'kész (' . wp_date('Y-m-d H:i', (int) $bf['done']) . ')' : ($on['bf'] ? 'fut' : (empty($bf['deals']) ? 'nincs elindítva' : 'szünetel')));
	$s .= ' · ' . (int) ($bf['deals'] ?? 0) . ' deal átnézve (' . (int) ($bf['with'] ?? 0) . ' levélszállal)';
	if (!empty($bf['last']) && empty($bf['done'])) { $s .= ', a következő a #' . ((int) $bf['last'] - 1) . ' alatti'; }
	$s .= ' · átvett jegyzet: ' . (int) ($bf['new'] ?? 0) . ' (szálra ' . (int) ($bf['thread'] ?? 0) . ', dealre ' . (int) ($bf['dnote'] ?? 0) . ')';
	if (!empty($bf['unread'])) { $s .= ', ' . (int) $bf['unread'] . ' friss olvasatlan szál kihagyva'; }
	if (!empty($bf['err'])) { $s .= ', ' . (int) $bf['err'] . ' hiba'; }
	$s .= '. 15 perces frissítés: ' . ($on['inc'] ? 'be' : 'ki') . (!empty($inc['new']) ? ' (' . (int) $inc['new'] . ' jegyzet)' : '') . '.';
	if (get_transient('cp_smn_backoff')) { $s .= ' A Salesmate visszafogta a kéréseket – kis szünet, utána magától folytatja.'; }
	return $s;
}
add_action('cp_imp_admin_after', function () {
	if (!current_user_can('manage_options')) { return; }
	$msg = '';
	if (isset($_POST['cp_smn_do']) && check_admin_referer('cp_smn_do')) {
		$a = sanitize_key(wp_unslash($_POST['cp_smn_do']));
		if ($a === 'start') { cp_smn_set('bf', 1); $bf = (array) get_option('cp_smn_bf', array()); unset($bf['done']); update_option('cp_smn_bf', $bf, false); $msg = 'A visszamenőleges átvétel elindult (percenként kb. 10 deal).'; }
		elseif ($a === 'pause') { cp_smn_set('bf', 0); wp_clear_scheduled_hook('cp_smn_bf'); $msg = 'A visszamenőleges átvétel szünetel; a Folytatás ott veszi fel, ahol abbamaradt.'; }
		elseif ($a === 'inc_on') { cp_smn_set('inc', 1); $msg = 'A 15 perces frissítés bekapcsolva.'; }
		elseif ($a === 'inc_off') { cp_smn_set('inc', 0); wp_clear_scheduled_hook('cp_smn_inc'); $msg = 'A 15 perces frissítés kikapcsolva.'; }
		elseif ($a === 'one') {
			@set_time_limit(120);
			$dry = !empty($_POST['cp_smn_dry']);
			$st = cp_smn_import_deal((int) ($_POST['cp_smn_deal'] ?? 0), $dry);
			$ms = array_map(function ($m) { return 'szál ' . $m['conv'] . ': ' . $m['notes'] . ' jegyzet → ' . ($m['how'] === 'none' ? 'deal-jegyzet' : 'panel-szál #' . $m['thread'] . ' (' . ($m['how'] === 'subj' ? 'tárgy' : 'időpont') . ')'); }, $st['m']);
			$msg = ($dry ? 'Próba (nem írt semmit): ' : 'Átvétel: ') . $st['convs'] . ' levélszál, ' . $st['notes'] . ' jegyzet, ' . $st['new'] . ' új (szálra ' . $st['thread'] . ', dealre ' . $st['dnote'] . '), ' . $st['dup'] . ' már megvolt' . ($st['unread'] ? ', ' . $st['unread'] . ' friss olvasatlan szál kihagyva' : '') . ($st['rate'] ? ' – a Salesmate visszafogta, próbáld később' : '') . ($st['err'] ? ', ' . $st['err'] . ' hiba' : '') . '.' . ($ms ? ' ' . implode('; ', $ms) . '.' : '');
		}
	}
	$on = cp_smn_on(); $bf = (array) get_option('cp_smn_bf', array());
	$f = '<form method="post" style="display:inline">' . wp_nonce_field('cp_smn_do', '_wpnonce', true, false);
	echo '<h2 id="cp-smn">Salesmate-levélszálak jegyzetei</h2>';
	if ($msg !== '') { echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>'; }
	echo '<p>A Salesmate-ben a levélszálakhoz (Team Inbox) írt belső jegyzeteket hozza át: a panel azonos levélszálára, vagy ha ilyen nincs, a deal jegyzetei közé. A Salesmate-ben semmit nem módosít, és egy jegyzet sem kerül be kétszer.</p>';
	echo '<p><b>' . esc_html(cp_smn_status()) . '</b></p><p>';
	if (!empty($bf['done'])) { /* kész – nincs gomb */ }
	elseif ($on['bf']) { echo $f . '<button class="button" name="cp_smn_do" value="pause">Szüneteltetés</button></form> '; }
	else { echo $f . '<button class="button button-primary" name="cp_smn_do" value="start">' . (empty($bf['deals']) ? 'Visszamenőleges átvétel indítása' : 'Folytatás') . '</button></form> '; }
	echo $f . '<button class="button" name="cp_smn_do" value="' . ($on['inc'] ? 'inc_off' : 'inc_on') . '">15 perces frissítés ' . ($on['inc'] ? 'kikapcsolása' : 'bekapcsolása') . '</button></form></p>';
	echo $f . '<p>Egy deal: <input type="number" name="cp_smn_deal" min="1" style="width:110px"> <label><input type="checkbox" name="cp_smn_dry" value="1" checked> csak próba (nem ír)</label> <button class="button" name="cp_smn_do" value="one">Futtatás</button></p></form>';
});
