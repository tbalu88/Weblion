<?php
/* =====================================================================
 * Deal 360° (v1.48) – a deal-adatlapon minden összefut: a deal mezői, a kontakt és a cég, a korábbi munkák
 * (a kontakt és a cég többi deal-je), a levelek és jegyzetek, a tevékenységek, a feladatkártyák, a webes
 * megkeresések, az automatizmusok bejegyzései és a változásnapló. Plusz kontakt- és cégnézet.
 * Csak olvas; a Salesmate-hez nem nyúl.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

/* egy deal-lista összesítője: darab, megnyert / nyitott / elvesztett és összegük */
function cp_d360_stats($deals) {
	$s = array('n' => 0, 'won' => 0, 'won_v' => 0.0, 'open' => 0, 'open_v' => 0.0, 'lost' => 0, 'first' => '', 'last_ev' => '');
	foreach ($deals as $d) {
		$s['n']++;
		if ($d['status'] === 'Won') { $s['won']++; $s['won_v'] += (float) $d['value']; }
		elseif ($d['status'] === 'Lost') { $s['lost']++; }
		else { $s['open']++; $s['open_v'] += (float) $d['value']; }
		if (!empty($d['created_at']) && ($s['first'] === '' || $d['created_at'] < $s['first'])) { $s['first'] = $d['created_at']; }
		if (!empty($d['event_d']) && $d['event_d'] > $s['last_ev']) { $s['last_ev'] = $d['event_d']; }
	}
	return $s;
}
function cp_d360_deals($where) {
	global $wpdb;
	$rows = (array) $wpdb->get_results('SELECT d.id, d.title, d.stage, d.status, d.value, d.currency, d.event_d, d.venue, d.service, d.owner_id, d.contact_id, d.created_at, d.closed_at, c.name cn FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . " c ON c.id = d.contact_id WHERE d.deleted_at IS NULL AND ($where) ORDER BY COALESCE(d.event_d, DATE(d.created_at)) DESC, d.id DESC LIMIT 300", ARRAY_A);
	foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['value'] = $r['value'] === null ? null : (float) $r['value']; $r['owner'] = cp_crm_user_name($r['owner_id']); $r['created_at'] = cp_crm_local($r['created_at']); $r['closed_at'] = cp_crm_local($r['closed_at']); }
	unset($r);
	return $rows;
}

/* levélszálak és levelek: a deal-hez csatoltak, illetve a kontakt e-mail-címével érkezett, máshoz nem csatolt szálak */
/* v2.6.9: a bekezdések / sorok határán szóköz (különben „Anita!A rendezvény”), a kódolt betűk visszaalakítva; v2.9.68: külön függvény (szálkártya) */
function cp_d360_txt($body) {
	return trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags(preg_replace('#<\s*(br|/p|/div|/tr|/li|/h[1-6]|/td|/blockquote)\b[^>]*>#i', "\n", (string) $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}
/* v2.9.69: egy levél az idővonal-csoportban (szöveg csak kezdetként, HTML soha) */
function cp_d360_msg_row($r, $sn) {
	$att = json_decode((string) $r['attachments'], true) ?: array();
	return array('id' => (int) $r['id'], 'dir' => $r['dir'] === 'out' ? 'out' : 'in', 'from' => $r['from_name'] !== '' ? $r['from_name'] : $r['from_email'], 'at' => cp_crm_local($r['sent_at']), 'sn' => (string) $sn,
		'att' => count(array_filter($att, function ($a) { return !empty($a['n']) && !(!empty($a['cid']) && cp_d360_is_emb((string) $a['n'])); })), 'q' => $r['status'] === 'idézetből' ? 1 : 0);
}
/* v2.9.69: egy szál korábbi levelei az idővonal „+N korábbi levél” sorához – csak olvas (NEM jelöli olvasottnak), csak szövegkezdetet ad.
   Jogosultság: mint a deal / kontakt / cég adatlap (cp_crm_ajax_guard) + a Levelezés fül (cp_panel_can('mail')).
   A duplikátum-rejtés ugyanaz, mint az adatlapon: a deal bármely szálában meglévő eredeti mellett a visszaállított másolat nem látszik. */
add_action('wp_ajax_nopriv_cp_crm_thread_msgs', 'cp_d360_thread_msgs');
add_action('wp_ajax_cp_crm_thread_msgs', 'cp_d360_thread_msgs');
function cp_d360_thread_msgs() {
	cp_crm_ajax_guard();
	global $wpdb;
	if (!function_exists('cp_mail_db_ok') || !cp_mail_db_ok() || (function_exists('cp_panel_can') && !cp_panel_can('mail'))) { cp_el_err('A Levelezés füled nincs bekapcsolva.', 403); }
	$th = (int) ($_REQUEST['th'] ?? 0); $skip = max(0, min(50, (int) ($_REQUEST['skip'] ?? 0))); $cap = 200;
	$T = cp_mail_t('threads'); $M = cp_mail_t('messages');
	$t = $th ? $wpdb->get_row($wpdb->prepare("SELECT id, deal_id FROM $T WHERE id = %d AND folder NOT IN ('trash', 'spam')", $th), ARRAY_A) : null;
	if (!$t) { cp_el_err('Nincs ilyen levélszál.', 404); }
	$L = (array) $wpdb->get_results($wpdb->prepare("SELECT id, thread_id, dir, from_name, from_email, status, sent_at, attachments FROM $M WHERE thread_id = %d ORDER BY sent_at DESC, id DESC LIMIT 2000", $th), ARRAY_A);
	$hide = cp_d360_thread_hide($L, (int) $t['deal_id']);
	$vis = array_values(array_filter($L, function ($r) use ($hide) { return !isset($hide[(int) $r['id']]); }));
	$page = array_slice($vis, $skip, $cap);
	$snip = array();
	if ($page) { foreach ((array) $wpdb->get_results('SELECT id, SUBSTRING(body, 1, 3000) body FROM ' . $M . ' WHERE id IN (' . implode(',', array_map(function ($r) { return (int) $r['id']; }, $page)) . ')', ARRAY_A) as $b) { $snip[(int) $b['id']] = mb_substr(cp_d360_txt($b['body']), 0, 260); } }
	wp_send_json_success(array('th' => $th, 'msgs' => array_map(function ($r) use ($snip) { return cp_d360_msg_row($r, $snip[(int) $r['id']] ?? ''); }, $page), 'more' => max(0, count($vis) - $skip - count($page))));
}
/* v2.9.69: a szál visszaállított másolatai közül a rejtendők – a szálon belül és a deal többi szálának kimenő leveleivel (±2 perc) összevetve */
function cp_d360_thread_hide($L, $deal) {
	global $wpdb;
	if (!function_exists('cp_mail_qd_hide')) { return array(); }
	$M = cp_mail_t('messages'); $rows = array(); $ids = array();
	foreach ($L as $r) { if ($r['status'] === 'idézetből') { $ids[] = (int) $r['id']; } }
	if (!$ids) { return array(); }
	$tids = array((int) $L[0]['thread_id']);
	if ($deal > 0) { foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . cp_mail_t('threads') . " WHERE deal_id = %d AND folder NOT IN ('trash', 'spam') LIMIT 100", $deal)) as $x) { $tids[] = (int) $x; } }
	$q = array();
	foreach ($L as $r) { if ($r['status'] === 'idézetből') { $q[] = $wpdb->prepare('sent_at BETWEEN %s AND %s', gmdate('Y-m-d H:i:s', strtotime($r['sent_at'] . ' UTC') - 120), gmdate('Y-m-d H:i:s', strtotime($r['sent_at'] . ' UTC') + 120)); } }
	$cand = (array) $wpdb->get_results('SELECT id, dir, status, sent_at, SUBSTRING(body, 1, 6000) body FROM ' . $M . ' WHERE thread_id IN (' . implode(',', array_unique($tids)) . ") AND ((dir = 'out' AND status <> 'idézetből' AND (" . implode(' OR ', array_slice($q, 0, 50)) . ')) OR id IN (' . implode(',', array_slice($ids, 0, 50)) . ')) LIMIT 300', ARRAY_A);
	foreach ($cand as $r) { $rows[] = array('id' => (int) $r['id'], 'dir' => $r['dir'], 'q' => $r['status'] === 'idézetből', 'ts' => strtotime($r['sent_at'] . ' UTC'), 'body' => $r['body']); }
	return cp_mail_qd_hide($rows, 120);
}
function cp_d360_mail($thread_where, $msg_limit = 80) {
	global $wpdb;
	if (!function_exists('cp_mail_db_ok') || !cp_mail_db_ok() || (function_exists('cp_panel_can') && !cp_panel_can('mail'))) { return array('threads' => array(), 'msgs' => array(), 'notes' => array(), 'files' => array(), 'off' => 1); }
	$T = cp_mail_t('threads'); $M = cp_mail_t('messages');
	$threads = (array) $wpdb->get_results("SELECT id, subject, contact_name, contact_email, deal_id, assignee_uid, status, folder, msg_count, last_at, snippet FROM $T WHERE folder NOT IN ('trash', 'spam') AND ($thread_where) ORDER BY last_at DESC LIMIT 100", ARRAY_A);
	$ids = array_map(function ($t) { return (int) $t['id']; }, $threads);
	$out = array('threads' => array(), 'msgs' => array(), 'notes' => array(), 'files' => array());
	if (!$ids) { return $out; }
	$in = implode(',', $ids);
	/* v2.9.68: a szálkártyákhoz minden levél könnyű sora (szöveg nélkül), a legújabbtól; a levélszám és az első / utolsó dátum
	   pontosan SQL-ből, így a 80-as (kontaktnál 40-es) levélkorlát és a 3000-es sorkorlát sem torzítja a kártyát */
	$L = (array) $wpdb->get_results("SELECT id, thread_id, dir, from_name, from_email, status, sent_at, attachments FROM $M WHERE thread_id IN ($in) ORDER BY sent_at DESC, id DESC LIMIT 3000", ARRAY_A);
	$agg = array();
	foreach ((array) $wpdb->get_results("SELECT thread_id, COUNT(*) n, MIN(sent_at) f, MAX(sent_at) l FROM $M WHERE thread_id IN ($in) GROUP BY thread_id", ARRAY_A) as $r) { $agg[(int) $r['thread_id']] = $r; }
	/* v2.9.68: duplikátum – az „idézetből” visszaállított saját levelünk rejtve, ha a deal levelei között megvan az eredeti
	   kimenő levél (±2 perc, azonos szövegkezdet), akár másik szálban, más tárggyal. Adatot nem törlünk. */
	$hide = array();
	if (function_exists('cp_mail_qd_hide')) {
		$need = array(); $ts = array();
		foreach ($L as $r) { $ts[(int) $r['id']] = strtotime($r['sent_at'] . ' UTC'); }
		foreach ($L as $q) {
			if ($q['status'] !== 'idézetből') { continue; }
			foreach ($L as $o) { if ($o['dir'] === 'out' && $o['status'] !== 'idézetből' && abs($ts[(int) $o['id']] - $ts[(int) $q['id']]) <= 120) { $need[(int) $q['id']] = 1; $need[(int) $o['id']] = 1; } }
		}
		if ($need) {
			$bodies = array();
			foreach ((array) $wpdb->get_results('SELECT id, SUBSTRING(body, 1, 6000) body FROM ' . $M . ' WHERE id IN (' . implode(',', array_map('intval', array_keys(array_slice($need, 0, 400, true)))) . ')', ARRAY_A) as $b) { $bodies[(int) $b['id']] = $b['body']; }
			$rows = array();
			foreach ($L as $r) { $i = (int) $r['id']; if (isset($bodies[$i])) { $rows[] = array('id' => $i, 'dir' => $r['dir'], 'q' => $r['status'] === 'idézetből', 'ts' => $ts[$i], 'body' => $bodies[$i]); } }
			$hide = cp_mail_qd_hide($rows, 120);
		}
	}
	/* szálonkénti összesítő a látható levelekből: utolsó levél (irány, feladó), mellékletszám (aláíráskép nélkül), első dátum */
	$per = array();
	foreach ($L as $r) {
		if (isset($hide[(int) $r['id']])) { continue; }
		$th = (int) $r['thread_id'];
		if (!isset($per[$th])) { $per[$th] = array('last' => $r, 'lm' => array(), 'na' => 0, 'rows' => 0, 'first' => $r['sent_at'], 'hid' => 0); }
		if (count($per[$th]['lm']) < 2) { $per[$th]['lm'][] = $r; } /* v2.9.69: a szál 2 legújabb (látható) levele az idővonalra */
		$per[$th]['rows']++; $per[$th]['first'] = $r['sent_at'];
		foreach ((json_decode((string) $r['attachments'], true) ?: array()) as $a) { if (!empty($a['n']) && !(!empty($a['cid']) && cp_d360_is_emb((string) $a['n']))) { $per[$th]['na']++; } }
	}
	foreach ($hide as $hid => $x) { foreach ($L as $r) { if ((int) $r['id'] === $hid && isset($per[(int) $r['thread_id']])) { $per[(int) $r['thread_id']]['hid']++; } } }
	/* v2.9.69: a 2 legújabb levél szövegének eleje (szálanként kettő, legfeljebb 200 levél, egy lekérdezésben) */
	$snip = array();
	$lastIds = array();
	foreach ($per as $p) { foreach ($p['lm'] as $r) { $lastIds[] = (int) $r['id']; } }
	if ($lastIds) {
		foreach ((array) $wpdb->get_results('SELECT id, SUBSTRING(body, 1, 3000) body FROM ' . $M . ' WHERE id IN (' . implode(',', $lastIds) . ')', ARRAY_A) as $b) { $snip[(int) $b['id']] = mb_substr(cp_d360_txt($b['body']), 0, 260); }
	}
	foreach ($threads as $t) {
		$th = (int) $t['id']; $p = $per[$th] ?? null; $g = $agg[$th] ?? null;
		$row = array('id' => $th, 'subj' => $t['subject'], 'ce' => $t['contact_email'], 'deal' => (int) $t['deal_id'], 'as' => (int) $t['assignee_uid'], 'st' => $t['status'], 'folder' => $t['folder'], 'cnt' => (int) $t['msg_count'], 'at' => cp_crm_local($t['last_at']));
		if ($g) {
			$hidN = $p ? $p['hid'] : 0;
			$row['n'] = max(0, (int) $g['n'] - $hidN);
			/* ha a szál minden levele benne van a könnyű listában, az első dátum abból (a rejtett nélkül), különben SQL-ből */
			$row['f'] = cp_crm_local($p && $p['rows'] + $hidN >= (int) $g['n'] ? $p['first'] : $g['f']);
		}
		if ($p) {
			$lm = $p['last'];
			$row += array('la' => cp_crm_local($lm['sent_at']), 'ld' => $lm['dir'] === 'out' ? 'out' : 'in', 'na' => $p['na'],
				/* v2.9.69: lm = a 2 legújabb levél (legújabb elöl): id, irány, feladó, idő, szövegkezdet (~3 sor), mellékletszám, visszaállított-e */
				'lm' => array_map(function ($r) use ($snip) { return cp_d360_msg_row($r, $snip[(int) $r['id']] ?? ''); }, $p['lm']));
		}
		$out['threads'][] = $row;
	}
	/* a levelek (összkép: ki írt utoljára; Fájlok) – a könnyű sorokból, a rejtett duplikátum nélkül; v2.9.68: szöveg nélkül (az idővonal szálkártyát mutat) */
	$k = 0;
	foreach ($L as $m) {
		if (isset($hide[(int) $m['id']])) { continue; }
		if (++$k > $msg_limit) { break; }
		$att = json_decode((string) $m['attachments'], true) ?: array();
		$out['msgs'][] = array('id' => (int) $m['id'], 'th' => (int) $m['thread_id'], 'dir' => $m['dir'], 'from' => $m['from_name'] !== '' ? $m['from_name'] : $m['from_email'], 'att' => count($att), 'q' => $m['status'] === 'idézetből' ? 1 : 0, 'at' => cp_crm_local($m['sent_at']));
		foreach ($att as $ai => $a) { if (count($out['files']) < 60 && !empty($a['n'])) { $out['files'][] = array('n' => function_exists('cp_mail_fname') ? cp_mail_fname((string) $a['n']) : (string) $a['n'], 'th' => (int) $m['thread_id'], 'm' => (int) $m['id'], 'i' => (int) $ai, 'at' => cp_crm_local($m['sent_at']), 'from' => $m['from_name']) + (preg_match('/\.(jpe?g|png|gif|webp|bmp|heic)$/i', (string) $a['n']) && function_exists('cp_thumb_hash') && ($fh = cp_thumb_hash((int) $m['id'], (int) $ai)) !== '' ? array('h' => $fh) : array()) /* v2.9.71: tartalom-ujjlenyomat (ismétlődés-szűrés) */ + ((!empty($a['cid']) && cp_d360_is_emb((string) $a['n'])) || (preg_match('/\.(jpe?g|png|gif|webp|bmp|heic)$/i', (string) $a['n']) && function_exists('cp_thumb_tiny') && cp_thumb_tiny((int) $m['id'], (int) $ai, $a['s'] ?? null)) ? array('emb' => 1) : array()); } } /* v2.9.20: emb = a levélbe ágyazott kép (aláírás-logó stb.); v2.9.23: csak tipikus nevű kép, dokumentum soha */
	}
	$notes = (array) $wpdb->get_results('SELECT id, thread_id, body, author, created_at FROM ' . cp_mail_t('notes') . " WHERE deleted_at IS NULL AND thread_id IN ($in) ORDER BY id DESC LIMIT 60", ARRAY_A);
	foreach ($notes as $n) { $out['notes'][] = array('id' => (int) $n['id'], 'th' => (int) $n['thread_id'], 'b' => mb_substr((string) $n['body'], 0, 1200), 'by' => $n['author'], 'at' => cp_crm_local($n['created_at'])); }
	return $out;
}

/* a deal-adatlap kiegészítése (a cp_crm_deal válaszába) */
add_filter('cp_crm_deal_data', function ($x) {
	global $wpdb;
	$d = $x['deal']; $id = (int) $d['id']; $c = $x['contact']; $co = $x['company'];
	/* korábbi munkák: a kontakt és a cég összes deal-je (ez is benne, hogy az összesítő teljes legyen) */
	$x['ct_deals'] = $c ? cp_d360_deals($wpdb->prepare('d.contact_id = %d', $c['id'])) : array();
	$x['ct_stats'] = cp_d360_stats($x['ct_deals']);
	$x['co_deals'] = array(); $x['co_stats'] = null; $x['co_contacts'] = array();
	if ($co) {
		$cw = $wpdb->prepare('d.company_id = %d OR c.company_id = %d', $co['id'], $co['id']);
		$x['co_deals'] = cp_d360_deals($cw);
		$x['co_stats'] = cp_d360_stats($x['co_deals']);
		$x['co_contacts'] = (array) $wpdb->get_results($wpdb->prepare('SELECT id, name, email FROM ' . cp_crm_t('contacts') . ' WHERE company_id = %d AND deleted_at IS NULL ORDER BY name LIMIT 50', $co['id']), ARRAY_A);
	}
	/* levelezés */
	$tw = $wpdb->prepare('deal_id = %d', $id);
	if ($c && !empty($c['email'])) { $tw .= $wpdb->prepare(' OR (deal_id IS NULL AND contact_email = %s)', strtolower($c['email'])); }
	$x['mail'] = cp_d360_mail($tw);
	/* feladatkártyák */
	$x['cards'] = array();
	if (function_exists('cp_kb_t') && get_option('cp_kb_db_ver')) {
		$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT k.id, k.title, k.assignees, k.due, k.done, k.created_by, k.created_at, l.name list FROM ' . cp_kb_t('cards') . ' k LEFT JOIN ' . cp_kb_t('lists') . ' l ON l.id = k.list_id WHERE k.deal_id = %d AND k.archived_at IS NULL ORDER BY k.id DESC LIMIT 50', $id), ARRAY_A);
		foreach ($rows as $r) {
			$as = array();
			foreach (array_filter(array_map('intval', explode(',', (string) $r['assignees']))) as $u) { $p = function_exists('cp_pu_get') ? cp_pu_get($u) : null; if ($p) { $as[] = $p['n']; } }
			$x['cards'][] = array('id' => (int) $r['id'], 'title' => $r['title'], 'list' => (string) $r['list'], 'due' => $r['due'], 'done' => (int) $r['done'], 'as' => $as, 'by' => $r['created_by'], 'at' => cp_crm_local($r['created_at']));
		}
	}
	/* webes megkeresések (űrlap) */
	$x['leads'] = array();
	if (function_exists('cp_lead_t') && function_exists('cp_lead_db_ok') && cp_lead_db_ok()) {
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, form_name, data, page, created_at FROM ' . cp_lead_t() . " WHERE deal_id = %d AND status <> 'spam' ORDER BY id DESC LIMIT 10", $id), ARRAY_A) as $l) {
			$f = json_decode((string) $l['data'], true);
			$kv = array();
			if (is_array($f)) { foreach ($f as $k => $v) { if (is_scalar($v) && trim((string) $v) !== '' && count($kv) < 14) { $kv[] = array(mb_substr((string) $k, 0, 60), mb_substr((string) $v, 0, 300)); } } }
			$x['leads'][] = array('id' => (int) $l['id'], 'form' => $l['form_name'], 'kv' => $kv, 'at' => cp_crm_local($l['created_at']));
		}
	}
	/* automatizmusok */
	$x['flows'] = array();
	if (function_exists('cp_flow_t') && get_option('cp_flow_db_ver')) {
		$fn = function_exists('cp_flow_defaults') ? cp_flow_defaults() : array();
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT flow, mode, action, detail, result, created_at FROM ' . cp_flow_t() . ' WHERE deal_id = %d ORDER BY id DESC LIMIT 20', $id), ARRAY_A) as $f) {
			$x['flows'][] = array('n' => isset($fn[$f['flow']]['n']) ? $fn[$f['flow']]['n'] : $f['flow'], 'mode' => $f['mode'], 'res' => $f['result'], 'detail' => mb_substr((string) $f['detail'], 0, 300), 'at' => cp_crm_local($f['created_at']));
		}
	}
	/* mióta van a jelenlegi szakaszban */
	$x['stage_since'] = cp_crm_local($wpdb->get_var($wpdb->prepare('SELECT at FROM ' . cp_crm_t('history') . " WHERE deal_id = %d AND field = 'stage' ORDER BY at DESC, id DESC LIMIT 1", $id)));
	return $x;
});

/* ------------------------------------------------------------ kontakt- és cégnézet */
add_action('wp_ajax_nopriv_cp_crm_contact', 'cp_d360_ajax_contact');
add_action('wp_ajax_cp_crm_contact', 'cp_d360_ajax_contact');
function cp_d360_ajax_contact() {
	cp_crm_ajax_guard();
	global $wpdb;
	$id = (int) ($_REQUEST['id'] ?? 0);
	$c = $id ? $wpdb->get_row($wpdb->prepare('SELECT id, name, email, phone, mobile, company_id, type, tags, city, utm_source, utm_medium, utm_campaign, description, last_note, created_at FROM ' . cp_crm_t('contacts') . ' WHERE id = %d', $id), ARRAY_A) : null;
	if (!$c) { cp_el_err('Nincs ilyen kontakt a panel adatai között.', 404); }
	$c['created_at'] = cp_crm_local($c['created_at']);
	$co = $c['company_id'] ? $wpdb->get_row($wpdb->prepare('SELECT id, name, website, phone, type FROM ' . cp_crm_t('companies') . ' WHERE id = %d', $c['company_id']), ARRAY_A) : null;
	$deals = cp_d360_deals($wpdb->prepare('d.contact_id = %d', $id));
	$mail = !empty($c['email']) ? cp_d360_mail($wpdb->prepare('contact_email = %s', strtolower($c['email'])), 40) : array('threads' => array(), 'msgs' => array(), 'notes' => array(), 'files' => array());
	wp_send_json_success(array('contact' => $c, 'company' => $co, 'deals' => $deals, 'stats' => cp_d360_stats($deals), 'mail' => $mail));
}
add_action('wp_ajax_nopriv_cp_crm_company', 'cp_d360_ajax_company');
add_action('wp_ajax_cp_crm_company', 'cp_d360_ajax_company');
function cp_d360_ajax_company() {
	cp_crm_ajax_guard();
	global $wpdb;
	$id = (int) ($_REQUEST['id'] ?? 0);
	$co = $id ? $wpdb->get_row($wpdb->prepare('SELECT id, name, website, phone, type, description FROM ' . cp_crm_t('companies') . ' WHERE id = %d', $id), ARRAY_A) : null;
	if (!$co) { cp_el_err('Nincs ilyen cég a panel adatai között.', 404); }
	$contacts = (array) $wpdb->get_results($wpdb->prepare('SELECT id, name, email, phone, mobile FROM ' . cp_crm_t('contacts') . ' WHERE company_id = %d AND deleted_at IS NULL ORDER BY name LIMIT 100', $id), ARRAY_A);
	$deals = cp_d360_deals($wpdb->prepare('d.company_id = %d OR c.company_id = %d', $id, $id));
	$em = array_values(array_filter(array_map(function ($c) { return strtolower((string) $c['email']); }, $contacts)));
	$mail = $em ? cp_d360_mail('contact_email IN (' . implode(',', array_map(function ($e) use ($wpdb) { return $wpdb->prepare('%s', $e); }, array_slice($em, 0, 50))) . ')', 40) : array('threads' => array(), 'msgs' => array(), 'notes' => array(), 'files' => array());
	wp_send_json_success(array('company' => $co, 'contacts' => $contacts, 'deals' => $deals, 'stats' => cp_d360_stats($deals), 'mail' => $mail));
}

/* v1.90: kontakt / cég szerkesztése – a Salesmate-be ír, visszaolvas, és a panel saját táblájába is elmenti */
add_action('wp_ajax_nopriv_cp_crm_entity_save', 'cp_d360_ajax_entity_save');
add_action('wp_ajax_cp_crm_entity_save', 'cp_d360_ajax_entity_save');
function cp_d360_ajax_entity_save() {
	cp_crm_ajax_guard();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (empty(cp_panel_opts()['stage_write'])) { cp_el_err('A Salesmate-be írás a panel beállításaiban ki van kapcsolva.'); }
	global $wpdb;
	$k = sanitize_key(wp_unslash($_POST['k'] ?? ''));
	$id = (int) ($_POST['id'] ?? 0);
	$f = json_decode((string) wp_unslash($_POST['f'] ?? ''), true);
	if (!in_array($k, array('ct', 'co'), true) || $id <= 0 || !is_array($f)) { cp_el_err('Hibás kérés.'); }
	$tab = cp_crm_t($k === 'ct' ? 'contacts' : 'companies');
	if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $tab WHERE id = %d", $id))) { cp_el_err('Nincs ilyen ' . ($k === 'ct' ? 'kontakt.' : 'cég.'), 404); }
	$str = function ($v, $n) { return mb_substr(trim(sanitize_text_field((string) $v)), 0, $n); };
	$tel = function ($v) { return mb_substr(preg_replace('/[^0-9+ ()\/-]/', '', (string) $v), 0, 40); };
	$b = array(); $chk = array();
	if ($k === 'ct') {
		if (array_key_exists('name', $f)) { $n = $str($f['name'], 190); if ($n === '') { cp_el_err('A kontakt neve nem lehet üres.'); } $b['firstName'] = ''; $b['lastName'] = $n; $chk['name'] = $n; }
		if (array_key_exists('email', $f)) { $e = trim((string) $f['email']); if ($e !== '' && !is_email($e)) { cp_el_err('Érvénytelen e-mail-cím.'); } $b['email'] = sanitize_email($e); $chk['email'] = $b['email']; }
		foreach (array('mobile', 'phone') as $x) { if (array_key_exists($x, $f)) { $b[$x] = $tel($f[$x]); $chk[$x] = $b[$x]; } }
		if (array_key_exists('city', $f)) { $b['billingCity'] = $str($f['city'], 100); $chk['billingCity'] = $b['billingCity']; }
		if (array_key_exists('description', $f)) { $b['description'] = mb_substr(trim(sanitize_textarea_field((string) $f['description'])), 0, 5000); }
		if (array_key_exists('company_id', $f)) {
			$cid = (int) $f['company_id'];
			if ($cid && !$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_crm_t('companies') . ' WHERE id = %d', $cid))) { cp_el_err('Nincs ilyen cég.'); }
			$b['company'] = $cid ?: null;
		}
	} else {
		if (array_key_exists('name', $f)) { $n = $str($f['name'], 190); if ($n === '') { cp_el_err('A cég neve nem lehet üres.'); } $b['name'] = $n; $chk['name'] = $n; }
		if (array_key_exists('website', $f)) { $w = trim((string) $f['website']); $b['website'] = $w === '' ? '' : mb_substr(esc_url_raw(preg_match('#^https?://#i', $w) ? $w : 'https://' . $w), 0, 255); $chk['website'] = $b['website']; }
		if (array_key_exists('phone', $f)) { $b['phone'] = $tel($f['phone']); $chk['phone'] = $b['phone']; }
		if (array_key_exists('description', $f)) { $b['description'] = mb_substr(trim(sanitize_textarea_field((string) $f['description'])), 0, 5000); }
	}
	if (!$b) { cp_el_err('Nincs mit menteni.'); }
	$path = '/apis/' . ($k === 'ct' ? 'contact' : 'company') . '/v4/' . $id;
	$put = cp_sm_request('PUT', $path, $b);
	if (is_wp_error($put)) { cp_el_err('A Salesmate nem fogadta a módosítást: ' . $put->get_error_message(), 502); }
	$g = cp_sm_request('GET', $path);
	$d = is_wp_error($g) ? null : (isset($g['Data']) ? $g['Data'] : $g);
	$warn = array();
	if (!is_array($d)) { $warn[] = 'a Salesmate-be beírtam, de a visszaolvasás nem sikerült – frissíts egy perc múlva'; }
	else {
		$L = array('name' => 'név', 'email' => 'e-mail', 'mobile' => 'mobil', 'phone' => 'telefon', 'billingCity' => 'város', 'website' => 'weboldal');
		foreach ($chk as $x => $v) { $got = trim(cp_panel_str(isset($d[$x]) ? $d[$x] : '')); if ($got !== $v && !($x === 'website' && rtrim($got, '/') === rtrim($v, '/'))) { $warn[] = ($L[$x] ?? $x) . ' nem úgy maradt meg, ahogy beírtad'; } }
		cp_crm_store($k === 'ct' ? 'contact' : 'company', array($d), 'get');
	}
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	wp_send_json_success(array('warn' => implode('; ', $warn)));
}

/* cég- / kontaktkereső a deal-lista szűrőjéhez */
add_action('wp_ajax_nopriv_cp_crm_who', 'cp_d360_ajax_who');
add_action('wp_ajax_cp_crm_who', 'cp_d360_ajax_who');
function cp_d360_ajax_who() {
	cp_crm_ajax_guard();
	global $wpdb;
	$q = trim(mb_substr((string) wp_unslash($_REQUEST['q'] ?? ''), 0, 60));
	if (mb_strlen($q) < 2) { wp_send_json_success(array('rows' => array())); }
	$l = '%' . $wpdb->esc_like($q) . '%';
	$out = array();
	foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT co.id, co.name, COUNT(DISTINCT d.id) n FROM ' . cp_crm_t('companies') . ' co LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.company_id = co.id LEFT JOIN ' . cp_crm_t('deals') . ' d ON (d.company_id = co.id OR d.contact_id = c.id) AND d.deleted_at IS NULL WHERE co.name LIKE %s GROUP BY co.id ORDER BY n DESC LIMIT 8', $l), ARRAY_A) as $r) { $out[] = array('k' => 'co', 'id' => (int) $r['id'], 'n' => $r['name'], 'c' => (int) $r['n']); }
	foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT c.id, c.name, c.email, COUNT(d.id) n FROM ' . cp_crm_t('contacts') . ' c LEFT JOIN ' . cp_crm_t('deals') . ' d ON d.contact_id = c.id AND d.deleted_at IS NULL WHERE (c.name LIKE %s OR c.email LIKE %s) AND c.deleted_at IS NULL GROUP BY c.id ORDER BY n DESC LIMIT 8', $l, $l), ARRAY_A) as $r) { $out[] = array('k' => 'ct', 'id' => (int) $r['id'], 'n' => $r['name'] !== '' ? $r['name'] : $r['email'], 'e' => $r['email'], 'c' => (int) $r['n']); }
	wp_send_json_success(array('rows' => $out));
}

/* v2.9.23: aláírás- / beágyazott kép-e – csak kép, és csak tipikus név (image001.png, ~WRD0000.jpg, Outlook-…, logo…, ikonok).
   Egyes levelezők (pl. Apple Mail) a valódi mellékletnek (PDF, sablon-PNG) is adnak Content-ID-t, azokat nem rejtjük. */
function cp_d360_is_emb($n) {
	if (!preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)$/i', $n)) { return false; }
	return (bool) preg_match('/^(image\d*|img\d*|~wrd\d*|outlook-[\w-]*|unnamed[\w-]*|signature[\w-]*|alairas[\w-]*|icon[\w-]*|banner[\w-]*|facebook[\w-]*|instagram[\w-]*|linkedin[\w-]*|youtube[\w-]*|tiktok[\w-]*|twitter[\w-]*|x-logo[\w-]*|spacer|pixel|blank)(\(\d+\))?\.\w+$/i', $n);
}
