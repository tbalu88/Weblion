<?php
/**
 * Crazy panel – Napló → Elemzés (v2.9.145): üzleti és működési számok a meglévő adatokból (deal-ek, változásnapló,
 * levelek, panelhasználat). Csak az adminok látják. Semmit nem ír, csak számol.
 *   S6  tölcsér kollégánként (érdeklődés → ajánlat → szerződés → megnyert), átfutással
 *   S7  az első válasz gyorsasága és a nyerési arány
 *   S12 terhelés: beérkező levelek és a mi válaszaink órák / napok szerint, mellette a panelben töltött aktív idő
 *   S13 megválaszolatlan levelek most (kor és felelős szerint)
 *   v2.9.146 (a percenkénti panelhasználatból, max. 90 nap): S1 idő ügyenként, S2 válaszírás ráfordítása, S4 fülváltások, S5 üresjárat
 * Az időpontok a táblákban UTC-ben vannak; a napszak-bontásnál a helyi időre számolunk.
 */
if (!defined('ABSPATH')) { exit; }

function cp_an_off() { return (int) wp_timezone()->getOffset(new DateTime('now', new DateTimeZone('UTC'))); }
/* helyi nap → UTC határ */
function cp_an_utc($ymd, $end = false) { $d = date_create($ymd . ($end ? ' 23:59:59' : ' 00:00:00'), wp_timezone()); return $d ? gmdate('Y-m-d H:i:s', $d->getTimestamp()) : gmdate('Y-m-d H:i:s'); }
function cp_an_owner($id) { return function_exists('cp_crm_user_name') ? (cp_crm_user_name((int) $id) ?: 'Nincs felelős') : '#' . (int) $id; }

add_action('wp_ajax_nopriv_cp_an_stats', 'cp_an_ajax_stats');
add_action('wp_ajax_cp_an_stats', 'cp_an_ajax_stats');
function cp_an_ajax_stats() {
	cp_el_auth();
	if (!function_exists('cp_panel_is_admin') || !cp_panel_is_admin()) { cp_el_err('Az elemzések csak az adminoknak érhetők el.', 403); }
	if (!function_exists('cp_crm_t') || !function_exists('cp_mail_t')) { cp_el_err('Hiányzik a CRM vagy a levelezés.', 503); }
	@set_time_limit(60);
	$ok = function ($v) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : ''; };
	$to = $ok($_REQUEST['to'] ?? '') ?: wp_date('Y-m-d');
	$from = $ok($_REQUEST['from'] ?? '') ?: wp_date('Y-m-d', strtotime($to . ' 12:00:00') - 89 * DAY_IN_SECONDS);
	if ($from > $to) { list($from, $to) = array($to, $from); }
	$ck = 'cp_an_' . md5($from . '|' . $to);
	if (empty($_REQUEST['fresh']) && ($c = get_transient($ck))) { wp_send_json_success($c); }
	$out = array('from' => $from, 'to' => $to, 'funnel' => cp_an_funnel($from, $to), 'resp' => cp_an_resp($from, $to), 'load' => cp_an_load($from, $to), 'unans' => cp_an_unans(), 'use' => cp_an_use($from, $to), 'at' => wp_date('Y-m-d H:i'));
	set_transient($ck, $out, 10 * MINUTE_IN_SECONDS);
	wp_send_json_success($out);
}

/* ------------------------------------------------------------ S6: tölcsér */
function cp_an_funnel($from, $to) {
	global $wpdb;
	$D = cp_crm_t('deals'); $H = cp_crm_t('history');
	$st = function_exists('cp_crm_stages') ? cp_crm_stages() : array();
	$ix = array_flip($st);
	$deals = (array) $wpdb->get_results($wpdb->prepare("SELECT id, owner_id, stage, status, value, created_at, closed_at FROM $D WHERE deleted_at IS NULL AND created_at BETWEEN %s AND %s", cp_an_utc($from), cp_an_utc($to, true)), ARRAY_A);
	if (!$deals) { return array('rows' => array(), 'tot' => null); }
	$max = array();
	foreach ($deals as $d) { $max[(int) $d['id']] = isset($ix[$d['stage']]) ? $ix[$d['stage']] : 0; }
	foreach (array_chunk(array_keys($max), 800) as $ch) {
		foreach ((array) $wpdb->get_results('SELECT deal_id, new_val FROM ' . $H . " WHERE field = 'stage' AND deal_id IN (" . implode(',', array_map('intval', $ch)) . ')', ARRAY_A) as $h) {
			$i = $ix[$h['new_val']] ?? null; if ($i !== null && $i > $max[(int) $h['deal_id']]) { $max[(int) $h['deal_id']] = $i; }
		}
	}
	$blank = array('n' => 0, 'offer' => 0, 'contract' => 0, 'won' => 0, 'lost' => 0, 'open' => 0, 'won_v' => 0, 'days' => array());
	$by = array(); $tot = $blank;
	foreach ($deals as $d) {
		$o = (int) $d['owner_id']; if (!isset($by[$o])) { $by[$o] = $blank; }
		$m = $max[(int) $d['id']]; $w = $d['status'] === 'Won';
		foreach (array(&$by[$o], &$tot) as &$x) {
			$x['n']++;
			if ($m >= 1 || $w) { $x['offer']++; }
			if ($m >= 3 || $w) { $x['contract']++; }
			if ($w) { $x['won']++; $x['won_v'] += (float) $d['value']; if ($d['closed_at']) { $x['days'][] = max(0, (strtotime($d['closed_at']) - strtotime($d['created_at'])) / DAY_IN_SECONDS); } }
			elseif ($d['status'] === 'Lost') { $x['lost']++; } else { $x['open']++; }
		}
		unset($x);
	}
	$fin = function ($x) { $dd = $x['days']; sort($dd); $x['med_days'] = $dd ? round($dd[(int) floor((count($dd) - 1) / 2)], 1) : null; unset($x['days']); $x['won_v'] = round($x['won_v']); return $x; };
	$rows = array();
	foreach ($by as $o => $x) { $rows[] = array('who' => cp_an_owner($o)) + $fin($x); }
	usort($rows, function ($a, $b) { return $b['n'] - $a['n']; });
	return array('rows' => $rows, 'tot' => $fin($tot));
}

/* ------------------------------------------------------------ S7: első válasz gyorsasága és nyerés
 * A deal-hez kötött, ügyfél által indított levélszálak (az első levél bejövő): az első bejövő levél és az utána első
 * kimenő levél között eltelt idő. Az „idézetből” visszaállított levelek ideje becsült, ezért kimaradnak. */
function cp_an_resp($from, $to) {
	global $wpdb;
	$T = cp_mail_t('threads'); $M = cp_mail_t('messages'); $D = cp_crm_t('deals');
	$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT m.thread_id, t.deal_id, m.dir, m.sent_at FROM $M m JOIN $T t ON t.id = m.thread_id WHERE t.deal_id > 0 AND m.status NOT IN ('idézetből') AND m.thread_id IN (SELECT thread_id FROM $M WHERE dir = 'in' GROUP BY thread_id HAVING MIN(sent_at) BETWEEN %s AND %s) ORDER BY m.thread_id, m.sent_at, m.id", cp_an_utc($from), cp_an_utc($to, true)), ARRAY_A);
	$first = array(); /* deal → [válaszidő perc vagy null, első bejövő ideje] – dealenként a legkorábbi szál */
	$cur = 0; $fin = null; $fout = null; $dl = 0; $started = false;
	$flush = function () use (&$first, &$cur, &$fin, &$fout, &$dl, &$started) {
		if ($cur && $started && $fin) { if (!isset($first[$dl]) || $fin < $first[$dl][1]) { $first[$dl] = array($fout ? (strtotime($fout) - strtotime($fin)) / 60 : null, $fin); } }
	};
	foreach ($rows as $r) {
		if ((int) $r['thread_id'] !== $cur) { $flush(); $cur = (int) $r['thread_id']; $dl = (int) $r['deal_id']; $fin = null; $fout = null; $started = $r['dir'] === 'in'; }
		if (!$started) { continue; }
		if ($r['dir'] === 'in' && !$fin) { $fin = $r['sent_at']; }
		elseif ($r['dir'] === 'out' && $fin && !$fout) { $fout = $r['sent_at']; }
	}
	$flush();
	if (!$first) { return array('b' => array(), 'own' => array(), 'n' => 0); }
	$st = array();
	foreach (array_chunk(array_keys($first), 800) as $ch) { foreach ((array) $wpdb->get_results("SELECT id, status, owner_id FROM $D WHERE deleted_at IS NULL AND id IN (" . implode(',', array_map('intval', $ch)) . ')', ARRAY_A) as $d) { $st[(int) $d['id']] = $d; } }
	$B = array(array('k' => 'lt1', 'l' => '1 órán belül', 'max' => 60), array('k' => 'h4', 'l' => '1–4 óra', 'max' => 240), array('k' => 'd1', 'l' => '4–24 óra', 'max' => 1440), array('k' => 'gt1', 'l' => '1 napnál több', 'max' => PHP_INT_MAX), array('k' => 'none', 'l' => 'nem ment válasz a levélből', 'max' => null));
	$b = array(); foreach ($B as $x) { $b[$x['k']] = array('l' => $x['l'], 'n' => 0, 'won' => 0, 'lost' => 0, 'open' => 0); }
	$own = array();
	foreach ($first as $dl => $f) {
		if (!isset($st[$dl])) { continue; }
		$min = $f[0]; $k = 'none';
		if ($min !== null) { foreach ($B as $x) { if ($x['max'] !== null && $min < $x['max']) { $k = $x['k']; break; } } }
		$s = $st[$dl]['status']; $sk = $s === 'Won' ? 'won' : ($s === 'Lost' ? 'lost' : 'open');
		$b[$k]['n']++; $b[$k][$sk]++;
		$o = (int) $st[$dl]['owner_id']; if (!isset($own[$o])) { $own[$o] = array(); }
		if ($min !== null) { $own[$o][] = $min; }
	}
	$ow = array();
	foreach ($own as $o => $L) { if (!$L) { continue; } sort($L); $ow[] = array('who' => cp_an_owner($o), 'n' => count($L), 'med' => round($L[(int) floor((count($L) - 1) / 2)]), 'fast' => count(array_filter($L, function ($v) { return $v < 60; }))); }
	usort($ow, function ($a, $c) { return $c['n'] - $a['n']; });
	return array('b' => array_values($b), 'own' => $ow, 'n' => count($first));
}

/* ------------------------------------------------------------ S12: terhelés napszak és hét napjai szerint (helyi idő) */
function cp_an_load($from, $to) {
	global $wpdb;
	$M = cp_mail_t('messages'); $off = cp_an_off();
	$h = array_fill(0, 24, array('in' => 0, 'out' => 0, 'act' => 0)); $w = array_fill(0, 7, array('in' => 0, 'out' => 0, 'act' => 0));
	foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT dir, HOUR(DATE_ADD(sent_at, INTERVAL %d SECOND)) h, WEEKDAY(DATE_ADD(sent_at, INTERVAL %d SECOND)) wd, COUNT(*) n FROM $M WHERE sent_at BETWEEN %s AND %s AND status NOT IN ('idézetből') GROUP BY dir, h, wd", $off, $off, cp_an_utc($from), cp_an_utc($to, true)), ARRAY_A) as $r) {
		$k = $r['dir'] === 'in' ? 'in' : 'out'; $h[(int) $r['h']][$k] += (int) $r['n']; $w[(int) $r['wd']][$k] += (int) $r['n'];
	}
	/* a panelben töltött aktív percek (a percenkénti adat 90 napig van meg) */
	if (function_exists('cp_us_t')) {
		foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT FLOOR(m / 60) h, WEEKDAY(d) wd, SUM(act) a FROM ' . cp_us_t('min') . ' WHERE d BETWEEN %s AND %s GROUP BY h, wd', $from, $to), ARRAY_A) as $r) {
			$h[(int) $r['h']]['act'] += (int) $r['a']; $w[(int) $r['wd']]['act'] += (int) $r['a'];
		}
	}
	return array('h' => $h, 'w' => $w);
}

/* ------------------------------------------------------------ S13: megválaszolatlan levelek most
 * A Beérkezett mappa nyitott szálai, ahol az utolsó levél bejövő (az elmúlt 30 napból). */
function cp_an_unans() {
	global $wpdb;
	$T = cp_mail_t('threads'); $M = cp_mail_t('messages');
	$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT t.id, t.subject, t.contact_name, t.assignee_uid, t.deal_id, m.sent_at FROM $T t JOIN $M m ON m.id = (SELECT id FROM $M WHERE thread_id = t.id ORDER BY sent_at DESC, id DESC LIMIT 1) WHERE t.folder = 'inbox' AND t.status <> 'closed' AND t.last_at >= %s AND m.dir = 'in'", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)), ARRAY_A);
	$now = time(); $B = array('lt4' => 0, 'd1' => 0, 'd3' => 0, 'gt3' => 0); $who = array(); $L = array();
	$nm = function ($uid) { if (!$uid) { return 'Kiosztatlan'; } $u = function_exists('cp_pu_get') ? cp_pu_get((int) $uid) : null; return $u ? (string) $u['n'] : '#' . (int) $uid; };
	foreach ($rows as $r) {
		$age = ($now - strtotime($r['sent_at'] . ' UTC')) / 3600;
		$k = $age < 4 ? 'lt4' : ($age < 24 ? 'd1' : ($age < 72 ? 'd3' : 'gt3')); $B[$k]++;
		$n = $nm($r['assignee_uid']); if (!isset($who[$n])) { $who[$n] = array('who' => $n, 'n' => 0, 'old' => 0); } $who[$n]['n']++; if ($age >= 24) { $who[$n]['old']++; }
		$L[] = array('id' => (int) $r['id'], 's' => mb_substr((string) $r['subject'], 0, 90), 'c' => mb_substr((string) $r['contact_name'], 0, 60), 'as' => $n, 'h' => round($age, 1), 'deal' => (int) $r['deal_id']);
	}
	usort($L, function ($a, $b) { return $b['h'] <=> $a['h']; });
	$who = array_values($who); usort($who, function ($a, $b) { return $b['n'] - $a['n']; });
	return array('n' => count($rows), 'b' => $B, 'who' => $who, 'list' => array_slice($L, 0, 15));
}

/* ------------------------------------------------------------ v2.9.146: S1, S2, S4, S5 a percenkénti panelhasználatból */
function cp_an_use($from, $to) {
	if (!function_exists('cp_us_t')) { return null; }
	global $wpdb;
	$from = max($from, wp_date('Y-m-d', time() - 90 * DAY_IN_SECONDS));
	if ($from > $to) { return null; }
	$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT uid, d, m, act, tab, obj, st FROM ' . cp_us_t('min') . ' WHERE d BETWEEN %s AND %s ORDER BY uid, d, m', $from, $to), ARRAY_A);
	if (!$rows) { return array('from' => $from, 'obj' => array(), 'rep' => array(), 'sw' => array(), 'pairs' => array(), 'idle' => array()); }
	$nm = function ($uid) { $u = function_exists('cp_pu_get') ? cp_pu_get((int) $uid) : null; return $u ? (string) $u['n'] : '#' . (int) $uid; };
	$obj = array(); $rep = array(); $sw = array(); $pairs = array(); $idle = array_fill(0, 24, array('o' => 0, 'a' => 0));
	$pu = 0; $pd = ''; $pm = -9; $ptab = ''; $pobj = ''; $pst = '';
	foreach ($rows as $r) {
		$u = (int) $r['uid']; $m = (int) $r['m']; $a = (int) $r['act']; $tab = (string) $r['tab']; $o = (string) $r['obj'];
		$cont = $u === $pu && $r['d'] === $pd && $m - $pm <= 3;
		/* S1: ügyenként */
		if ($o !== '') { if (!isset($obj[$o])) { $obj[$o] = array('n' => 0, 'a' => 0, 'u' => array()); } $obj[$o]['n']++; $obj[$o]['a'] += $a; $obj[$o]['u'][$u] = 1; }
		/* S2: válaszírás – összefüggő „reply” szakaszok */
		if (!isset($rep[$u])) { $rep[$u] = array('min' => 0, 'ses' => 0); }
		if ($r['st'] === 'reply') { $rep[$u]['min']++; if (!($cont && $pst === 'reply' && $pobj === $o)) { $rep[$u]['ses']++; } }
		/* S4: fülváltások egy munkamenetben */
		if (!isset($sw[$u])) { $sw[$u] = array('n' => 0, 'min' => 0, 'days' => array()); }
		$sw[$u]['min']++; $sw[$u]['days'][$r['d']] = 1;
		if ($cont && $tab !== '' && $ptab !== '' && $tab !== $ptab) { $sw[$u]['n']++; $k = $ptab . '→' . $tab; $pairs[$k] = ($pairs[$k] ?? 0) + 1; }
		/* S5: üresjárat óránként */
		$h = (int) floor($m / 60); if ($h >= 0 && $h < 24) { $idle[$h]['o']++; $idle[$h]['a'] += $a; }
		$pu = $u; $pd = $r['d']; $pm = $m; $ptab = $tab; $pobj = $o; $pst = (string) $r['st'];
	}
	uasort($obj, function ($x, $y) { return $y['n'] - $x['n']; });
	$O = array();
	foreach (array_slice($obj, 0, 15, true) as $k => $x) { $O[] = array('n' => $x['n'], 'a' => $x['a'], 'ppl' => count($x['u'])) + cp_us_obj($k); }
	/* a deal-ek állapota és értéke (S1 mellé) */
	$dids = array(); foreach ($O as $x) { if (preg_match('/^deal:(\d+)$/', $x['o'], $mm)) { $dids[] = (int) $mm[1]; } }
	if ($dids) { $st = array(); foreach ((array) $wpdb->get_results('SELECT id, status, stage, value FROM ' . cp_crm_t('deals') . ' WHERE id IN (' . implode(',', $dids) . ')', ARRAY_A) as $d) { $st['deal:' . $d['id']] = $d; }
		foreach ($O as &$x) { if (isset($st[$x['o']])) { $x['ds'] = $st[$x['o']]['status']; $x['dst'] = $st[$x['o']]['stage']; $x['dv'] = round((float) $st[$x['o']]['value']); } } unset($x); }
	$R = array(); foreach ($rep as $u => $x) { if ($x['ses']) { $R[] = array('who' => $nm($u), 'min' => $x['min'], 'ses' => $x['ses'], 'avg' => round($x['min'] / $x['ses'], 1)); } }
	usort($R, function ($x, $y) { return $y['ses'] - $x['ses']; });
	$W = array(); foreach ($sw as $u => $x) { if ($x['min'] >= 30) { $W[] = array('who' => $nm($u), 'n' => $x['n'], 'perhr' => round($x['n'] / max(1, $x['min']) * 60, 1), 'days' => count($x['days'])); } }
	usort($W, function ($x, $y) { return $y['perhr'] <=> $x['perhr']; });
	arsort($pairs);
	$P = array(); foreach (array_slice($pairs, 0, 8, true) as $k => $n) { $P[] = array('k' => $k, 'n' => $n); }
	return array('from' => $from, 'obj' => $O, 'rep' => $R, 'sw' => $W, 'pairs' => $P, 'idle' => $idle);
}
