<?php
/**
 * v2.5.3: a Salesmate-ben maradt régi levelezés átvétele a panelbe (az átállás előtt)
 * A Salesmate SMTP-n küldött leveleit nem teszi a postafiók Elküldött mappájába, az API pedig nem adja ki őket, és exportjuk sincs.
 * Ezért a Chrome-bővítményben futó Claude dealenként kigyűjti őket (a WP-admin Postafiók oldalán letölthető deal-listából),
 * és a kapott JSON itt illeszthető be. A panel a leveleket a dealhez és a kontakthoz köti; ami már megvan a panelben
 * (a postafiókból vagy idézetből), az nem kerül be kétszer. A Salesmate-et nem érinti.
 *
 * JSON: [ { "deal": 23346, "emails": [ { "dir": "out"|"in", "from": "Név <cím>", "to": "Név <cím>, …", "cc": "…",
 *          "date": "2026-11-02 12:18", "subject": "…", "body": "sima szöveg vagy HTML", "attachments": ["fájlnév", …] }, … ] }, … ]
 */
if (!defined('ABSPATH')) { exit; }

/* a kigyűjtendő dealek: nyitott, a jövőben esedékes vagy az elmúlt 18 hónapban létrehozott / lezajlott */
function cp_smh_deals() {
	global $wpdb;
	$since = gmdate('Y-m-d', time() - 548 * DAY_IN_SECONDS);
	return (array) $wpdb->get_results($wpdb->prepare('SELECT d.id, d.title, d.stage, d.status, d.event_d, c.email ce, d.last_comm_mode lcm FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . " c ON c.id = d.contact_id WHERE d.deleted_at IS NULL AND (d.status = 'Open' OR d.event_d >= %s OR d.created_at >= %s) ORDER BY (d.status = 'Open') DESC, d.event_d DESC, d.id DESC", $since, $since . ' 00:00:00'), ARRAY_A);
}
function cp_smh_addr_list($s) {
	$out = array();
	foreach (preg_split('/[,;](?=(?:[^"]*"[^"]*")*[^"]*$)/', (string) $s) as $p) {
		$p = trim($p); if ($p === '') { continue; }
		if (preg_match('/^(.*?)<\s*([^<>\s]+@[^<>\s]+)\s*>$/', $p, $m)) { $out[] = array('n' => trim($m[1], " \"'"), 'e' => strtolower($m[2])); }
		elseif (is_email($p)) { $out[] = array('n' => '', 'e' => strtolower($p)); }
	}
	return $out;
}
function cp_smh_import($raw) {
	global $wpdb;
	if (!function_exists('cp_mail_db_ok') || !cp_mail_db_ok()) { return 'A levelezés táblái még nem készek.'; }
	$raw = trim($raw);
	if (preg_match('/```(?:json)?\s*(.*?)```/s', $raw, $m)) { $raw = trim($m[1]); }
	$j = json_decode($raw, true);
	if (is_array($j) && isset($j['deals'])) { $j = $j['deals']; }
	if (!is_array($j) || !$j) { return 'Nem érvényes JSON-lista (hiba: ' . json_last_error_msg() . ').'; }
	$T = cp_mail_t('threads'); $M = cp_mail_t('messages'); $D = cp_crm_t('deals'); $C = cp_crm_t('contacts');
	$o = cp_imp_opts(); $me = strtolower($o['user']);
	$st = array('deal' => 0, 'new' => 0, 'dup' => 0, 'bad' => 0, 'nodeal' => 0);
	foreach (array_slice($j, 0, 300) as $x) {
		$did = (int) ($x['deal'] ?? 0);
		$d = $did ? $wpdb->get_row($wpdb->prepare("SELECT d.id, c.name cn, c.email ce FROM $D d LEFT JOIN $C c ON c.id = d.contact_id WHERE d.id = %d", $did), ARRAY_A) : null;
		if (!$d) { $st['nodeal']++; continue; }
		$st['deal']++;
		foreach (array_slice((array) ($x['emails'] ?? array()), 0, 200) as $e) {
			if (!is_array($e)) { $st['bad']++; continue; }
			$from = cp_smh_addr_list($e['from'] ?? ''); $to = cp_smh_addr_list($e['to'] ?? ''); $cc = cp_smh_addr_list($e['cc'] ?? '');
			$f = $from[0] ?? array('n' => '', 'e' => '');
			$ts = strtotime((string) ($e['date'] ?? '') . ' ' . wp_timezone_string());
			$subj = mb_substr(sanitize_text_field((string) ($e['subject'] ?? '')), 0, 255);
			$body = (string) ($e['body'] ?? $e['body_html'] ?? $e['body_text'] ?? '');
			if (!$ts || $f['e'] === '' || trim(wp_strip_all_tags($body)) === '') { $st['bad']++; continue; }
			$out = ($e['dir'] ?? '') === 'out' || (function_exists('cp_imp_ours') && cp_imp_ours($f['e']));
			/* az ügyfél: kimenőnél az első nem saját címzett, bejövőnél a feladó; ha nincs, a deal kontaktja */
			$other = $out ? null : $f;
			if ($out) { foreach (array_merge($to, $cc) as $a) { if (!function_exists('cp_imp_ours') || !cp_imp_ours($a['e'])) { $other = $a; break; } } }
			if (!$other || $other['e'] === '') { $other = array('n' => (string) $d['cn'], 'e' => strtolower((string) $d['ce'])); }
			if ($other['e'] === '') { $st['bad']++; continue; }
			$at = gmdate('Y-m-d H:i:s', $ts);
			$nsubj = function_exists('cp_imp_norm_subj') ? cp_imp_norm_subj($subj) : mb_strtolower($subj);
			$mid = md5('smh|' . $did . '|' . gmdate('Y-m-d H:i', $ts) . '|' . $f['e'] . '|' . $nsubj);
			if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $M WHERE mid = %s", $mid))) { $st['dup']++; continue; }
			/* már megvan a postafiókból vagy idézetből (azonos irány, ugyanannál az ügyfélnél, ±10 percen belül)? */
			$dupe = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $M m JOIN $T t ON t.id = m.thread_id WHERE t.contact_email = %s AND m.dir = %s AND m.sent_at BETWEEN %s AND %s", $other['e'], $out ? 'out' : 'in', gmdate('Y-m-d H:i:s', $ts - 600), gmdate('Y-m-d H:i:s', $ts + 600)));
			if ($dupe) { $st['dup']++; continue; }
			if (!preg_match('/<[a-z][^>]*>/i', $body)) { $body = '<p>' . str_replace("\n", '<br>', esc_html(str_replace("\r", '', $body))) . '</p>'; }
			$body = function_exists('cp_mail_clean') ? cp_mail_clean($body) : wp_kses_post($body);
			$text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($body)));
			$tkey = md5($other['e'] . '|' . $nsubj);
			$t = $wpdb->get_row($wpdb->prepare("SELECT id, last_at, msg_count FROM $T WHERE tkey = %s AND (deal_id = %d OR deal_id IS NULL) ORDER BY id DESC LIMIT 1", $tkey, $did), ARRAY_A);
			if (!$t) {
				$wpdb->insert($T, array('mailbox' => $o['box'], 'subject' => $subj, 'contact_name' => mb_substr($other['n'], 0, 190), 'contact_email' => mb_substr($other['e'], 0, 190), 'deal_id' => $did,
					'followers' => '', 'tags' => 'salesmate', 'status' => 'closed', 'folder' => 'archive', 'snippet' => mb_substr($text, 0, 200), 'msg_count' => 0, 'has_attach' => 0,
					'last_at' => $at, 'created_at' => $at, 'tkey' => $tkey, 'src' => 'imap'));
				$t = array('id' => (int) $wpdb->insert_id, 'last_at' => $at, 'msg_count' => 0);
			}
			$fmt = function ($l) { return implode(', ', array_map(function ($a) { return $a['n'] !== '' ? $a['n'] . ' <' . $a['e'] . '>' : $a['e']; }, $l)); };
			/* a mellékletek nem tölthetők le a Salesmate-ből – csak a nevük kerül a levél aljára */
			$an = array_values(array_filter(array_map(function ($n) { return mb_substr(sanitize_text_field((string) $n), 0, 120); }, array_slice((array) ($e['attachments'] ?? array()), 0, 20))));
			if ($an) { $body .= '<p><small>Mellékletek (a Salesmate-ben maradtak): ' . esc_html(implode(', ', $an)) . '</small></p>'; }
			$att = array();
			$wpdb->insert_id = 0;
			$wpdb->insert($M, array('thread_id' => (int) $t['id'], 'dir' => $out ? 'out' : 'in', 'from_name' => mb_substr($f['n'] !== '' ? $f['n'] : $f['e'], 0, 190), 'from_email' => mb_substr($f['e'], 0, 190),
				'to_list' => mb_substr($fmt($to), 0, 2000), 'cc_list' => mb_substr($fmt($cc), 0, 2000), 'subject' => $subj, 'body' => $body, 'attachments' => wp_json_encode($att),
				'status' => 'Salesmate-archívum', 'sent_at' => $at, 'mid' => $mid));
			if (!$wpdb->insert_id) { $st['bad']++; continue; }
			$upd = array('msg_count' => (int) $t['msg_count'] + 1);
			if ($at >= $t['last_at']) { $upd['last_at'] = $at; $upd['snippet'] = mb_substr($text, 0, 200); }
			if ($att) { $upd['has_attach'] = 1; }
			$wpdb->update($T, $upd, array('id' => (int) $t['id']));
			$st['new']++;
		}
	}
	$log = get_option('cp_smh_log', array()); if (!is_array($log)) { $log = array(); }
	$log[] = array('t' => time(), 'deal' => $st['deal'], 'new' => $st['new'], 'dup' => $st['dup']);
	update_option('cp_smh_log', array_slice($log, -200), false);
	return 'Salesmate-levelek: ' . $st['deal'] . ' deal, ' . $st['new'] . ' új levél átvéve, ' . $st['dup'] . ' már megvolt' . ($st['bad'] ? ', ' . $st['bad'] . ' hiányos kihagyva' : '') . ($st['nodeal'] ? ', ' . $st['nodeal'] . ' ismeretlen deal-azonosító' : '') . '.';
}

/* WP-admin → Postafiók oldal: deal-lista letöltése + beillesztés */
add_action('admin_init', function () {
	if (!isset($_GET['cp_smh_list']) || !current_user_can('manage_options')) { return; }
	check_admin_referer('cp_smh_list');
	$o = cp_panel_opts(); $base = 'https://' . $o['linkname'] . '/#/app/deals/';
	$done = array(); global $wpdb;
	foreach ((array) $wpdb->get_col('SELECT DISTINCT t.deal_id FROM ' . cp_mail_t('threads') . " t WHERE t.tags = 'salesmate' AND t.deal_id IS NOT NULL") as $x) { $done[(int) $x] = 1; }
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="salesmate-dealek-levelekhez.csv"');
	$fh = fopen('php://output', 'w');
	fwrite($fh, "\xEF\xBB\xBF");
	fputcsv($fh, array('deal', 'link', 'cim', 'szakasz', 'allapot', 'rendezveny', 'mar_atveve'), ';');
	foreach (cp_smh_deals() as $d) { fputcsv($fh, array($d['id'], $base . $d['id'] . '/detail', $d['title'], $d['stage'], $d['status'], $d['event_d'], isset($done[(int) $d['id']]) ? 'igen' : ''), ';'); }
	fclose($fh);
	exit;
});
add_action('cp_imp_admin_after', function () {
	$msg = '';
	if (isset($_POST['cp_smh_json']) && current_user_can('manage_options') && check_admin_referer('cp_smh_do')) {
		@set_time_limit(120);
		$msg = cp_smh_import((string) wp_unslash($_POST['cp_smh_json']));
	}
	if (!empty($_FILES['cp_smh_files']['tmp_name']) && current_user_can('manage_options') && check_admin_referer('cp_smh_do')) {
		@set_time_limit(280);
		$parts = array();
		foreach ((array) $_FILES['cp_smh_files']['tmp_name'] as $i => $tmp) { if (is_uploaded_file($tmp) && (int) $_FILES['cp_smh_files']['size'][$i] < 40 * 1048576) { $parts[] = cp_smh_import((string) file_get_contents($tmp)); } }
		$msg = implode(' ', $parts);
	}
	$prog = '';
	if (isset($_POST['cp_smh_prog']) && current_user_can('manage_options') && check_admin_referer('cp_smh_do')) { $prog = cp_smh_script(!empty($_POST['cp_smh_all'])); }
	$n = count(cp_smh_deals());
	echo '<h2>Salesmate-levelek átvétele (régi levelezés)</h2>';
	if ($msg !== '') { echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>'; }
	echo '<p>A Salesmate-ből küldött leveleket a Salesmate nem teszi a postafiókba, és nem is exportálja. A Chrome-bővítményben futó Claude dealenként kigyűjti őket; a kapott JSON-t ide illeszd be (egyszerre akár 20–50 deal). Ami már megvan a panelben, nem kerül be kétszer. A Salesmate-et nem érinti.</p>';
	echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('options-general.php?page=cp-mail-import&cp_smh_list=1'), 'cp_smh_list')) . '">Deal-lista letöltése (' . (int) $n . ' deal, CSV)</a></p>';
	echo '<form method="post">' . wp_nonce_field('cp_smh_do', '_wpnonce', true, false) . '<textarea name="cp_smh_json" rows="10" class="large-text code" placeholder="[ { &quot;deal&quot;: 23346, &quot;emails&quot;: [ { &quot;dir&quot;: &quot;out&quot;, &quot;from&quot;: &quot;EventCrazy <info@eventcrazy.hu>&quot;, &quot;to&quot;: &quot;…&quot;, &quot;date&quot;: &quot;2026-11-02 12:18&quot;, &quot;subject&quot;: &quot;…&quot;, &quot;body&quot;: &quot;…&quot; } ] } ]"></textarea><p><button class="button button-primary">Levelek átvétele</button></p></form>';
	echo '<form method="post" enctype="multipart/form-data">' . wp_nonce_field('cp_smh_do', '_wpnonce', true, false) . '<p>Vagy a program által letöltött fájl(ok): <input type="file" name="cp_smh_files[]" accept=".json,application/json" multiple> <button class="button">Fájlok betöltése</button></p></form>';
	echo '<h3>Automatikus átvétel (ajánlott)</h3><p>A program a Salesmate-oldalon, a te belépéseddel fut: dealenként lekéri a leveleket, és egyenesen ide küldi (ha a böngésző ezt nem engedi, a végén letölthető fájlt ad, azt fent töltsd be). A Salesmate-ben semmit nem módosít; a még olvasatlan, friss beszélgetéseket kihagyja, hogy ott ne legyenek olvasottnak jelölve. A kulcsa 48 óráig érvényes.</p>';
	echo '<form method="post">' . wp_nonce_field('cp_smh_do', '_wpnonce', true, false) . '<label><input type="checkbox" name="cp_smh_all" value="1"> az összes deal (nem csak azok, ahol a panelből hiányzik a kimenő levél)</label><p><button class="button button-primary" name="cp_smh_prog" value="1">Program létrehozása</button></p></form>';
	if ($prog !== '') { echo '<p><b>Másold ki az egészet</b> (kattints bele, Ctrl+A, Ctrl+C), és futtasd a Salesmate-oldalon – lépések a panel útmutatójában.</p><textarea rows="12" class="large-text code" readonly onclick="this.select()">' . esc_textarea($prog) . '</textarea>'; }
	$log = (array) get_option('cp_smh_log', array());
	if ($log) { $s = array_sum(array_column($log, 'new')); echo '<p>Eddig átvéve: <b>' . (int) $s . '</b> levél, ' . count($log) . ' beillesztésből.</p>'; }
});

/* ------------------------------------------------------------ automatikus átvétel: a Salesmate-oldalon futó program ide küld */
function cp_smh_token_ok($k) {
	$t = get_option('cp_smh_tok', array());
	return is_array($t) && !empty($t['h']) && (int) $t['exp'] > time() && hash_equals((string) $t['h'], hash('sha256', (string) $k));
}
add_action('rest_api_init', function () {
	register_rest_route('crazy-panel/v1', '/smh', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ($req) {
		if (!cp_smh_token_ok((string) $req->get_param('k'))) { return new WP_REST_Response(array('ok' => 0, 'msg' => 'Lejárt vagy hibás kulcs – hozz létre új programot a panelben.'), 403); }
		@set_time_limit(120);
		$msg = cp_smh_import((string) $req->get_body());
		return new WP_REST_Response(array('ok' => 1, 'msg' => $msg), 200);
	}));
});
function cp_smh_script($all) {
	global $wpdb;
	$k = bin2hex(random_bytes(20));
	update_option('cp_smh_tok', array('h' => hash('sha256', $k), 'exp' => time() + 2 * DAY_IN_SECONDS), false);
	$ids = array();
	$T = cp_mail_t('threads'); $M = cp_mail_t('messages');
	foreach (cp_smh_deals() as $d) {
		if (!$all && $d['lcm'] !== 'Email') { continue; }
		if (!$all && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T t JOIN $M m ON m.thread_id = t.id WHERE t.deal_id = %d AND m.dir = 'out' AND m.status <> 'idézetből'", $d['id']))) { continue; }
		$ids[] = (int) $d['id'];
	}
	$url = rest_url('crazy-panel/v1/smh') . '?k=' . $k;
	$js = <<<'JS'
/* Crazy panel – Salesmate-levelek átvétele. Csak olvas a Salesmate-ből; a leveleket a Crazy panelnek küldi. */
(async () => {
const IDS = __IDS__, DEST = __DEST__, H = {};
const log = (...a) => console.log('%c[Crazy]', 'color:#c2410c;font-weight:bold', ...a);
/* a belépési fejlécek: a Salesmate saját kéréseiből olvassuk ki (a böngészőn kívülre nem kerülnek) */
const want = ['accesstoken', 'x-linkname', 'x-workspace-id', 'isimpersonate'];
const grab = (k, v) => { if (want.includes(String(k).toLowerCase())) H[k] = v; };
const oSet = XMLHttpRequest.prototype.setRequestHeader;
XMLHttpRequest.prototype.setRequestHeader = function (k, v) { grab(k, v); return oSet.apply(this, arguments); };
const oFetch = window.fetch;
window.fetch = function (u, o) { try { const h = new Headers((o && o.headers) || {}); h.forEach((v, k) => grab(k, v)); } catch (e) {} return oFetch.apply(this, arguments); };
log('Kattints most a Salesmate-ben bármelyik menüpontra (pl. Deals), hogy a program megkapja a belépést…');
for (let i = 0; i < 120 && !Object.keys(H).some(k => k.toLowerCase() === 'accesstoken'); i++) await new Promise(r => setTimeout(r, 500));
XMLHttpRequest.prototype.setRequestHeader = oSet; window.fetch = oFetch;
if (!Object.keys(H).some(k => k.toLowerCase() === 'accesstoken')) { log('Nem kaptam belépést – frissítsd az oldalt, és futtasd újra.'); return; }
const get = async p => { for (let t = 0; t < 4; t++) { try { const r = await oFetch(location.origin + p, { headers: Object.assign({ Accept: 'application/json' }, H), credentials: 'include' }); if (r.status === 429) { await new Promise(z => setTimeout(z, 3000 * (t + 1))); continue; } if (!r.ok) return null; const j = await r.json(); return j && j.Data !== undefined ? j.Data : j; } catch (e) { await new Promise(z => setTimeout(z, 1500)); } } return null; };
const TYPES = 'EmailReceived,EmailSent,ScheduledEmail,TeamInboxConversationEmailSent,TeamInboxConversationEmailReceived,SequenceEmailSent,SequenceEmailReceived,SequenceTeamInboxEmailSent,SequenceTeamInboxEmailReceived,MarketingAutomationEmailSent';
const pad = n => String(n).padStart(2, '0');
const loc = x => { const d = new Date(typeof x === 'number' || /^\d+$/.test(String(x)) ? Number(x) : x); if (isNaN(d)) return ''; const p = new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Budapest', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(d).reduce((a, q) => (a[q.type] = q.value, a), {}); return p.year + '-' + p.month + '-' + p.day + ' ' + (p.hour === '24' ? '00' : p.hour) + ':' + p.minute; };
const ad = a => !a ? '' : (a.name ? a.name + ' <' + a.address + '>' : (a.address || ''));
const ads = l => (l || []).map(ad).filter(Boolean).join(', ');
const fresh = Date.now() - 14 * 864e5;
const out = []; let sent = 0, skipped = 0, mails = 0, failed = [];
const flush = async (last) => {
	if (!out.length) return;
	const batch = out.splice(0, out.length);
	try { const r = await oFetch(DEST, { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body: JSON.stringify(batch) }); const j = await r.json(); log(j.msg || ('HTTP ' + r.status)); if (!j.ok) throw new Error(j.msg || 'hiba'); sent += batch.length; }
	catch (e) { log('A panelnek küldés nem sikerült (' + e.message + ') – a végén fájlként töltheted le.'); failed = failed.concat(batch); }
};
for (let i = 0; i < IDS.length; i++) {
	const id = IDS[i], emails = [];
	const convs = new Map();
	for (let from = 0; from < 500; from += 50) {
		const d = await get('/apis/timeline/v4/activity-feed/module/4/object/' + id + '/past-timeline-entries?rows=50&from=' + from + '&filterBy=emails&activitiesToQuery=' + TYPES);
		if (!d || !Array.isArray(d.data)) break;
		for (const it of d.data) { const ob = it.object || {}; if (ob.conversationId) { if (!convs.has(ob.conversationId)) convs.set(ob.conversationId, ob); } else if (ob.subject || ob.snippet) { emails.push({ dir: /Sent/i.test(it.type) ? 'out' : 'in', from: '', to: '', date: loc(it.createdAtTS || it.createdAt), subject: ob.subject || '', body: ob.snippet || '', attachments: [] }); } }
		if (!d.fetchMore) break;
	}
	for (const [cid, ob] of convs) {
		/* a még olvasatlan, friss beszélgetést nem nyitjuk meg (ne legyen olvasott a Salesmate-ben) */
		if (ob.isRead === false && new Date(ob.latestReceivedEmailDate || 0).getTime() > fresh) { skipped++; continue; }
		const tl = await get('/apis/v3/teamInboxes/conversations/' + cid + '/timeline?rows=100&from=0&folderName=');
		const rows = Array.isArray(tl) ? tl : ((tl && (tl.data || tl.timeline)) || []);
		const eids = [...new Set(rows.filter(r => /Email(Sent|Received)/.test(r.type || r.activityType || '')).map(r => (r.data && r.data.id) || null).filter(Boolean))];
		for (const eid of eids) {
			const e = await get('/apis/v3/teamInboxes/conversations/' + cid + '/emails/' + eid);
			if (!e) continue;
			const fromAddr = (e.from && e.from.address || '').toLowerCase();
			emails.push({ dir: /eventcrazy\.hu|crazyphoto\.hu|studioframes\.hu/.test(fromAddr) ? 'out' : 'in', from: ad(e.from), to: ads(e.to), cc: ads(e.cc), date: loc(e.emailDate || e.receivedDate), subject: e.subject || '', body: e.htmlBody || e.textBody || e.snippet || '', attachments: (e.attachments || []).map(a => a.name || a.fileName || a.filename || '').filter(Boolean) });
			mails++;
		}
	}
	out.push({ deal: id, emails });
	if (out.length >= 15) await flush();
	if (i % 10 === 0) log((i + 1) + ' / ' + IDS.length + ' deal kész, ' + mails + ' levél' + (skipped ? ', ' + skipped + ' friss olvasatlan beszélgetés kihagyva' : ''));
}
await flush(true);
log('KÉSZ: ' + IDS.length + ' deal, ' + mails + ' levél. ' + (skipped ? skipped + ' friss, olvasatlan beszélgetést kihagytam. ' : ''));
if (failed.length) { const b = new Blob([JSON.stringify(failed)], { type: 'application/json' }); const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'salesmate-levelek.json'; document.body.appendChild(a); a.click(); log('A panelnek nem ment el ' + failed.length + ' deal – letöltöttem fájlként (salesmate-levelek.json): töltsd be a panel Levelezés oldalán.'); }
})();
JS;
	return str_replace(array('__IDS__', '__DEST__'), array(wp_json_encode($ids), wp_json_encode($url)), $js);
}
