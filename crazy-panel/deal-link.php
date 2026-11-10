<?php
/* =====================================================================
 * v2.9.73: levélszál ↔ deal automatikus kötése – szabályok (erősség szerint)
 *  1. deal-jel a tárgyban: „[#<dealId>]” – a panelből küldött levelek tárgyába kerül (mail-send.php); csak a panel által
 *     ténylegesen kiadott jel számít (a deal valamelyik szálában van ilyen tárgyú kimenő levél), így egy idegen rendszer
 *     „[#123]” jele nem köt.
 *  2. ismert cím: a kontakt e-mail-címe VAGY a kontakt további címe (contact-emails.php). Ha a kontaktnak több nyitott
 *     dealje van, a tárgyban lévő dátum (event_d vagy a többnapos rendezvény bármelyik napja) dönt; dátum nélkül nincs
 *     automatikus kötés, csak javaslat.
 *  3. régi Salesmate-sablon „|| ID: <n>”: n = a Salesmate KONTAKT azonosítója (= crm contacts.id) → a deal mint a 2-ben.
 *  4. ismeretlen cím céges domainről (nem ingyenes levelező, nem saját): csak ha a domain kontaktjainak / cégének pontosan
 *     egy nyitott dealje van, vagy a tárgy dátuma pontosan egy nyitott dealjükkel egyezik.
 *  Minden automatikus kötés „autodeal” eseményt ír az okkal. Kézi csatolást (esemény 'deal', panelből indított szál)
 *  soha nem ír felül, és a szabályok soha nem választanak le dealt.
 * A kontextus (résztvevők, kontaktok, dealek, napok) kötegben töltődik: egy szálhoz és 1200 szálhoz is néhány lekérdezés.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

/* ------------------------------------------------------------ tárgy-elemzés */

/* v2.9.73: a deal-jel ([#123]) – több esetén az utolsó */
function cp_dl_token($subj) {
	return preg_match_all('/\[#(\d{1,12})\]/', (string) $subj, $m) ? (int) end($m[1]) : 0;
}
function cp_dl_strip_token($s) {
	return trim(preg_replace('/\s*\[#\d{1,12}\]/', '', (string) $s));
}
/* v2.9.73: a tárgy egyetlen deal-jellel a végén (a „RE: RE: … [#123]” láncban sem ismétlődik; másik deal jele lecserélődik) */
function cp_dl_subj_token($subj, $deal) {
	$deal = (int) $deal;
	if ($deal <= 0) { return (string) $subj; }
	return trim(mb_substr(cp_dl_strip_token($subj), 0, 255 - strlen(' [#' . $deal . ']')) . ' [#' . $deal . ']'); /* a tárgy-oszlop 255 jeles */
}
/* v2.9.73: régi Salesmate-sablon: „… || ID: 98338” – a Salesmate kontakt-azonosítója */
function cp_dl_smid($subj) {
	return preg_match('/\|\|\s*ID\s*:\s*(\d{3,12})\b/i', (string) $subj, $m) ? (int) $m[1] : 0;
}
/* v2.9.73: hónapnevek (magyar teljes/rövid, ékezettel vagy anélkül, néhány angol) → hónap */
function cp_dl_mon($w) {
	$w = mb_strtolower($w);
	$map = array('jan' => 1, 'feb' => 2, 'már' => 3, 'mar' => 3, 'ápr' => 4, 'apr' => 4, 'máj' => 5, 'maj' => 5, 'may' => 5, 'jún' => 6, 'jun' => 6, 'júl' => 7, 'jul' => 7, 'aug' => 8, 'sze' => 9, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'dec' => 12);
	$k = mb_substr($w, 0, 3);
	return $map[$k] ?? 0;
}
/* v2.9.73: dátumok a tárgyban → 'ÉÉÉÉ-HH-NN' lista. Formák: „2026.12.14.”, „2026. 12. 14.”, „2026-12-14”, „2026. dec. 14.”,
   „12.14.”, „dec. 14.”, „december 14”. Év nélkül a levél dátumából: ha 120 napnál régebbi lenne → jövő év,
   ha 245 napnál távolabbi → előző év (újév körül is a közelebbi rendezvény). */
function cp_dl_dates($s, $at = '') {
	$s = ' ' . preg_replace('/\|\|\s*ID\s*:\s*\d+/i', ' ', cp_dl_strip_token((string) $s)) . ' ';
	$ref = $at !== '' ? strtotime($at . ' UTC') : time();
	if (!$ref) { $ref = time(); }
	$Y = (int) gmdate('Y', $ref);
	$out = array();
	$add = function ($y, $m, $d) use (&$out) {
		$y = (int) $y; $m = (int) $m; $d = (int) $d;
		if ($y < 2000 || !checkdate($m, $d, $y)) { return; }
		$k = sprintf('%04d-%02d-%02d', $y, $m, $d);
		if (!in_array($k, $out, true)) { $out[] = $k; }
	};
	$guess = function ($m, $d) use ($ref, $Y, $add) {
		$m = (int) $m; $d = (int) $d;
		if ($m < 1 || $m > 12 || $d < 1 || $d > 31) { return; }
		$y = $Y;
		$t = gmmktime(0, 0, 0, $m, min($d, 28), $y);
		if ($t < $ref - 120 * 86400) { $y++; } elseif ($t > $ref + 245 * 86400) { $y--; }
		if (!checkdate($m, $d, $y) && checkdate($m, $d, $y + 1)) { $y++; } /* febr. 29. */
		$add($y, $m, $d);
	};
	/* csak a valódi hónapnév (teljes vagy rövid), utána nem jöhet betű – az „oktatás 12 fő” nem október 12. */
	$mon = '(jan(?:u[áa]r)?|febr?(?:u[áa]r)?|m[áa]rc(?:ius)?|[áa]pr(?:ilis)?|m[áa]j(?:us)?|may|j[úu]n(?:ius)?|j[úu]l(?:ius)?|aug(?:usztus)?|szept?(?:ember)?|sep(?:tember)?|okt(?:[óo]ber)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)(?!\p{L})';
	/* teljes számos dátum */
	$s = preg_replace_callback('/(?<!\d)(20\d\d)\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})(?!\d)\.?/u', function ($m) use ($add) { $add($m[1], $m[2], $m[3]); return ' '; }, $s);
	/* év + hónapnév */
	$s = preg_replace_callback('/(?<!\d)(20\d\d)\.?\s*' . $mon . '\.?\s*(\d{1,2})(?!\d)\.?/iu', function ($m) use ($add) { $mo = cp_dl_mon($m[2]); if ($mo) { $add($m[1], $mo, $m[3]); } return ' '; }, $s);
	/* hónapnév + nap */
	$s = preg_replace_callback('/(?<!\p{L})' . $mon . '\.?\s*(\d{1,2})(?!\d)\.?/iu', function ($m) use ($guess) { $mo = cp_dl_mon($m[1]); if ($mo) { $guess($mo, $m[2]); } return ' '; }, $s);
	/* „12.14.” (a záró pont kötelező; idő, pl. 18.30 nem dátum) */
	preg_replace_callback('/(?<![\d.])(\d{1,2})\.\s?(\d{1,2})\.(?!\d)/u', function ($m) use ($guess) { $guess($m[1], $m[2]); return ' '; }, $s);
	return $out;
}
/* v2.9.73: 'ÉÉÉÉ-HH-NN' → „dec. 14.” (évvel: „2026. dec. 14.”) */
function cp_dl_hu_day($d, $year = false) {
	$ms = array('jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.');
	$p = explode('-', substr((string) $d, 0, 10));
	if (count($p) !== 3) { return (string) $d; }
	return ($year ? (int) $p[0] . '. ' : '') . $ms[max(0, min(11, (int) $p[1] - 1))] . ' ' . (int) $p[2] . '.';
}

/* ------------------------------------------------------------ kontextus (kötegben) */

function cp_dl_in(array $vals, $fmt = '%d') {
	global $wpdb;
	if (!$vals) { return '(NULL)'; }
	return '(' . implode(',', array_map(function ($v) use ($wpdb, $fmt) { return $fmt === '%d' ? (string) (int) $v : "'" . esc_sql((string) $v) . "'"; }, array_values($vals))) . ')';
}
function cp_dl_dom($e) { return strtolower((string) substr(strrchr((string) $e, '@') ?: '', 1)); }
function cp_dl_freedom($d) { return $d === '' || !function_exists('cp_cos_free') || cp_cos_free($d); }
function cp_dl_ce_on() { return function_exists('cp_ce_db_ok') && cp_ce_db_ok(); }

/* $ths: szál-sorok (id, deal_id, src, created_at, last_at, contact_email, subject; opcionális: first = az új levél partnere,
   subj2 = az új levél tárgya, at = az új levél ideje, parts = ismert résztvevők). Visszaad: a döntéshez kellő minden adat. */
function cp_dl_ctx(array $ths) {
	global $wpdb;
	$T = cp_mail_t('threads'); $M = cp_mail_t('messages'); $E = cp_mail_t('events');
	$D = cp_crm_t('deals'); $C = cp_crm_t('contacts');
	$ctx = array('parts' => array(), 'manual' => array(), 'smauth' => array(), 'ct_email' => array(), 'al_email' => array(), 'ct' => array(), 'deals' => array(), 'by_ct' => array(), 'by_co' => array(), 'days' => array(), 'dom' => array(), 'issued' => array(), 'q' => 0);
	$ids = array_values(array_filter(array_map(function ($t) { return (int) ($t['id'] ?? 0); }, $ths)));
	foreach (array_chunk($ids, 1000) as $ch) {
		$ctx['q']++;
		foreach ((array) $wpdb->get_results('SELECT thread_id, from_email, to_list, cc_list FROM ' . $M . ' WHERE thread_id IN ' . cp_dl_in($ch) . ' ORDER BY sent_at DESC', ARRAY_A) as $m) {
			$tid = (int) $m['thread_id'];
			if (isset($ctx['parts'][$tid]) && count($ctx['parts'][$tid]) >= 15) { continue; }
			if (!preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $m['from_email'] . ' ' . $m['to_list'] . ' ' . $m['cc_list'], $mm)) { continue; }
			foreach ($mm[0] as $e) { $e = strtolower($e); if (!cp_imp_ours($e) && !in_array($e, $ctx['parts'][$tid] ?? array(), true)) { $ctx['parts'][$tid][] = $e; } }
		}
		$ctx['q']++;
		foreach ((array) $wpdb->get_results('SELECT thread_id, kind FROM ' . $E . ' WHERE thread_id IN ' . cp_dl_in($ch) . " AND (kind = 'deal' OR (kind = 'autodeal' AND text LIKE 'Deal a Salesmate szerint:%'))", ARRAY_A) as $r) {
			$ctx[$r['kind'] === 'deal' ? 'manual' : 'smauth'][(int) $r['thread_id']] = 1;
		}
	}
	/* a szálak résztvevői sorrendben: az új levél partnere, a szál kontaktja, a levelek címei */
	$emails = array(); $smids = array(); $toks = array(); $curs = array();
	foreach ($ths as $i => $t) {
		$l = array();
		foreach (array_merge(array((string) ($t['first'] ?? ''), (string) ($t['contact_email'] ?? '')), (array) ($t['parts'] ?? array()), $ctx['parts'][(int) ($t['id'] ?? 0)] ?? array()) as $e) {
			$e = strtolower(trim($e));
			if ($e !== '' && strpos($e, '@') !== false && !cp_imp_ours($e) && !in_array($e, $l, true)) { $l[] = $e; }
		}
		$ths[$i]['_parts'] = array_slice($l, 0, 15);
		foreach ($ths[$i]['_parts'] as $e) { $emails[$e] = 1; }
		$sub = (string) ($t['subject'] ?? '') . ' ' . (string) ($t['subj2'] ?? '');
		if ($x = cp_dl_smid($sub)) { $smids[$x] = 1; }
		if ($x = cp_dl_token($sub)) { $toks[$x] = 1; }
		if (!empty($t['deal_id'])) { $curs[(int) $t['deal_id']] = 1; }
	}
	$ctx['ths'] = $ths;
	$emails = array_keys($emails);
	$cids = array();
	$ctrow = function ($r) use (&$ctx, &$cids) { $id = (int) $r['id']; $ctx['ct'][$id] = array('email' => strtolower((string) $r['email']), 'co' => (int) $r['company_id']); $cids[$id] = 1; };
	foreach (array_chunk($emails, 1000) as $ch) {
		$ctx['q']++;
		foreach ((array) $wpdb->get_results("SELECT id, email, company_id FROM $C WHERE deleted_at IS NULL AND email IN " . cp_dl_in($ch, '%s'), ARRAY_A) as $r) { $ctrow($r); $ctx['ct_email'][strtolower($r['email'])][] = (int) $r['id']; }
		if (cp_dl_ce_on()) {
			$ctx['q']++;
			foreach ((array) $wpdb->get_results('SELECT a.contact_id, a.email, c.company_id FROM ' . cp_ce_t() . " a JOIN $C c ON c.id = a.contact_id WHERE c.deleted_at IS NULL AND a.email IN " . cp_dl_in($ch, '%s'), ARRAY_A) as $r) {
				$ctx['al_email'][strtolower($r['email'])][] = (int) $r['contact_id'];
				if (!isset($ctx['ct'][(int) $r['contact_id']])) { $ctx['ct'][(int) $r['contact_id']] = array('email' => '', 'co' => (int) $r['company_id']); }
				$cids[(int) $r['contact_id']] = 1;
			}
		}
	}
	if ($smids) {
		$ctx['q']++;
		foreach ((array) $wpdb->get_results("SELECT id, email, company_id FROM $C WHERE deleted_at IS NULL AND id IN " . cp_dl_in(array_keys($smids)), ARRAY_A) as $r) { $ctrow($r); }
	}
	/* 4. szabály: csak ahol egyik cím sem ismert – a céges domainek kontaktjai (és a további címeik) */
	$doms = array();
	foreach ($ctx['ths'] as $t) {
		$known = false;
		foreach ($t['_parts'] as $e) { if (!empty($ctx['ct_email'][$e]) || !empty($ctx['al_email'][$e])) { $known = true; break; } }
		if ($known) { continue; }
		foreach ($t['_parts'] as $e) { $d = cp_dl_dom($e); if (!cp_dl_freedom($d)) { $doms[$d] = 1; } }
	}
	$cos = array();
	foreach (array_chunk(array_keys($doms), 300) as $ch) {
		$ctx['q']++;
		foreach ((array) $wpdb->get_results("SELECT id, email, company_id FROM $C WHERE deleted_at IS NULL AND SUBSTRING_INDEX(email, '@', -1) IN " . cp_dl_in($ch, '%s'), ARRAY_A) as $r) {
			$ctrow($r); $ctx['dom'][cp_dl_dom($r['email'])]['ct'][(int) $r['id']] = 1;
			if ((int) $r['company_id']) { $ctx['dom'][cp_dl_dom($r['email'])]['co'][(int) $r['company_id']] = 1; $cos[(int) $r['company_id']] = 1; }
		}
		if (cp_dl_ce_on()) {
			$ctx['q']++;
			foreach ((array) $wpdb->get_results('SELECT a.contact_id, a.email, c.company_id FROM ' . cp_ce_t() . " a JOIN $C c ON c.id = a.contact_id WHERE c.deleted_at IS NULL AND SUBSTRING_INDEX(a.email, '@', -1) IN " . cp_dl_in($ch, '%s'), ARRAY_A) as $r) {
				$cid = (int) $r['contact_id']; $cids[$cid] = 1;
				if (!isset($ctx['ct'][$cid])) { $ctx['ct'][$cid] = array('email' => '', 'co' => (int) $r['company_id']); }
				$ctx['dom'][cp_dl_dom($r['email'])]['ct'][$cid] = 1;
				if ((int) $r['company_id']) { $ctx['dom'][cp_dl_dom($r['email'])]['co'][(int) $r['company_id']] = 1; $cos[(int) $r['company_id']] = 1; }
			}
		}
	}
	/* dealek: a kontaktoké, a domain-cégeké, valamint a mostani és a deal-jeles dealek (töröltek is, az állapotukhoz) */
	$drow = function ($r) use (&$ctx) {
		$id = (int) $r['id'];
		if (isset($ctx['deals'][$id])) { return; }
		$ctx['deals'][$id] = array('id' => $id, 'title' => (string) $r['title'], 'st' => (string) $r['status'], 'ct' => (int) $r['contact_id'], 'co' => (int) $r['company_id'], 'ev' => (string) $r['event_d'], 'cr' => (string) $r['created_at'], 'cl' => (string) $r['closed_at'], 'del' => !empty($r['deleted_at']));
		if (empty($r['deleted_at'])) {
			if ((int) $r['contact_id']) { $ctx['by_ct'][(int) $r['contact_id']][] = $id; }
			if ((int) $r['company_id']) { $ctx['by_co'][(int) $r['company_id']][] = $id; }
		}
	};
	$cols = 'id, title, status, contact_id, company_id, event_d, created_at, closed_at, deleted_at';
	foreach (array_chunk(array_keys($cids), 400) as $ch) { $ctx['q']++; foreach ((array) $wpdb->get_results("SELECT $cols FROM $D WHERE deleted_at IS NULL AND contact_id IN " . cp_dl_in($ch), ARRAY_A) as $r) { $drow($r); } }
	foreach (array_chunk(array_keys($cos), 400) as $ch) { $ctx['q']++; foreach ((array) $wpdb->get_results("SELECT $cols FROM $D WHERE deleted_at IS NULL AND company_id IN " . cp_dl_in($ch), ARRAY_A) as $r) { $drow($r); } }
	$more = array_diff(array_keys($curs + $toks), array_keys($ctx['deals']));
	foreach (array_chunk($more, 400) as $ch) { $ctx['q']++; foreach ((array) $wpdb->get_results("SELECT $cols FROM $D WHERE id IN " . cp_dl_in($ch), ARRAY_A) as $r) { $drow($r); } }
	/* többnapos rendezvény napjai (csak olvasás; a tábla csak akkor, ha a mday.php telepítette) */
	if ($ctx['deals'] && function_exists('cp_mday_db_ok') && function_exists('cp_mday_t') && cp_mday_db_ok()) {
		foreach (array_chunk(array_keys($ctx['deals']), 500) as $ch) {
			$ctx['q']++;
			foreach ((array) $wpdb->get_results('SELECT deal_id, day FROM ' . cp_mday_t() . ' WHERE deal_id IN ' . cp_dl_in($ch), ARRAY_A) as $r) { $ctx['days'][(int) $r['deal_id']][] = substr((string) $r['day'], 0, 10); }
		}
	}
	/* a panel által kiadott deal-jelek: a deal szálaiban van ilyen jelű kimenő levél */
	$tk = array_values(array_filter(array_keys($toks), function ($x) use ($ctx) { return isset($ctx['deals'][$x]) && !$ctx['deals'][$x]['del']; }));
	foreach (array_chunk($tk, 200) as $ch) {
		$ctx['q']++;
		foreach ((array) $wpdb->get_results("SELECT t.deal_id, m.subject FROM $M m JOIN $T t ON t.id = m.thread_id WHERE t.deal_id IN " . cp_dl_in($ch) . " AND m.dir = 'out' AND m.subject LIKE '%[#%'", ARRAY_A) as $r) {
			if (cp_dl_token($r['subject']) === (int) $r['deal_id']) { $ctx['issued'][(int) $r['deal_id']] = 1; }
		}
	}
	return $ctx;
}

/* ------------------------------------------------------------ választás */

/* a deal napjai: event_d + a többnapos tábla napjai */
function cp_dl_deal_days($id, $ctx) {
	$d = $ctx['deals'][$id] ?? null;
	$l = $ctx['days'][$id] ?? array();
	if ($d && $d['ev'] !== '') { $l[] = substr($d['ev'], 0, 10); }
	return array_values(array_unique($l));
}
/* dealek közül: dátum-egyezés → egyetlen nyitott → (nyitott nélkül) a levélhez legközelebb létrehozott; több nyitott dátum nélkül: kétes */
function cp_dl_choose(array $ids, array $dates, $at, $ctx, $domain = false) {
	$ids = array_values(array_unique(array_filter($ids, function ($i) use ($ctx) { return isset($ctx['deals'][$i]) && !$ctx['deals'][$i]['del']; })));
	if (!$ids) { return array('deal' => 0, 'amb' => false, 'cands' => array()); }
	$open = array_values(array_filter($ids, function ($i) use ($ctx) { return $ctx['deals'][$i]['st'] === 'Open'; }));
	if ($dates) {
		$dm = array(); $dmo = array(); $hit = array();
		foreach ($ids as $i) { $x = array_values(array_intersect(cp_dl_deal_days($i, $ctx), $dates)); if ($x) { $dm[] = $i; $hit[$i] = $x[0]; if ($ctx['deals'][$i]['st'] === 'Open') { $dmo[] = $i; } } }
		$pick = count($dmo) === 1 ? $dmo[0] : (!$dmo && !$domain && count($dm) === 1 ? $dm[0] : 0);
		if ($pick) { return array('deal' => $pick, 'amb' => false, 'cands' => $ids, 'date' => $hit[$pick]); }
	}
	if (count($open) === 1) { return array('deal' => $open[0], 'amb' => false, 'cands' => $ids, 'one' => 1); }
	if (count($open) > 1 || $domain) { return array('deal' => 0, 'amb' => count($open) > 1, 'cands' => $ids); }
	/* nincs nyitott: a levél idejéhez legközelebb létrehozott (mint eddig a cp_imp_pick_deal) */
	$ref = strtotime($at . ' UTC') ?: time();
	usort($ids, function ($a, $b) use ($ctx, $ref) { return abs((strtotime($ctx['deals'][$a]['cr'] . ' UTC') ?: 0) - $ref) <=> abs((strtotime($ctx['deals'][$b]['cr'] . ' UTC') ?: 0) - $ref); });
	return array('deal' => $ids[0], 'amb' => false, 'cands' => $ids);
}
/* a szabályok sorrendben; visszaad: deal, rule (token|date|contact|alias|part|smid|domain|domaindate|''), why, amb, cands */
function cp_dl_pick(array $t, array $ctx) {
	$sub = trim((string) ($t['subject'] ?? '') . ' ' . (string) ($t['subj2'] ?? ''));
	$at = (string) ($t['at'] ?? '') !== '' ? (string) $t['at'] : ((string) ($t['last_at'] ?? '') !== '' ? (string) $t['last_at'] : gmdate('Y-m-d H:i:s'));
	$dates = cp_dl_dates($sub, $at);
	$none = array('deal' => 0, 'rule' => '', 'why' => '', 'amb' => false, 'cands' => array());
	/* 1. deal-jel */
	$tok = cp_dl_token($sub);
	if ($tok && isset($ctx['deals'][$tok]) && !$ctx['deals'][$tok]['del'] && !empty($ctx['issued'][$tok])) {
		return array('deal' => $tok, 'rule' => 'token', 'why' => 'deal-jel a tárgyban', 'amb' => false, 'cands' => array($tok));
	}
	$fmt = function ($r, $rule, $why) { return array('deal' => $r['deal'], 'rule' => isset($r['date']) ? 'date' : $rule, 'why' => isset($r['date']) ? 'a tárgyban lévő dátum alapján: ' . cp_dl_hu_day($r['date'], true) . ($rule === 'alias' ? ', a kontakt további címe' : ($rule === 'smid' ? ', Salesmate-azonosító' : '')) : $why, 'amb' => false, 'cands' => $r['cands'], 'via' => $rule); };
	/* 2. ismert cím (kontakt vagy további cím) */
	$parts = (array) ($t['_parts'] ?? array());
	$known = false; $amb = null;
	foreach ($parts as $k => $e) {
		$direct = $ctx['ct_email'][$e] ?? array(); $alias = $ctx['al_email'][$e] ?? array();
		if (!$direct && !$alias) { continue; }
		$known = true;
		$ids = array();
		foreach (array_unique(array_merge($direct, $alias)) as $cid) { $ids = array_merge($ids, $ctx['by_ct'][$cid] ?? array()); }
		$r = cp_dl_choose($ids, $dates, $at, $ctx);
		if ($r['deal']) {
			$rule = !$direct ? 'alias' : ($k === 0 ? 'contact' : 'part');
			$why = $rule === 'alias' ? 'a kontakt további címe alapján: ' . $e : ($rule === 'part' ? 'a levelezés résztvevője alapján: ' . $e : 'a kontakt e-mail-címe alapján');
			return $fmt($r, $rule, $why);
		}
		if ($r['amb']) { $amb = $r; break; } /* több nyitott deal, dátum nélkül: a többi résztvevőhöz nem lépünk tovább */
	}
	/* 3. Salesmate-azonosító */
	$sm = cp_dl_smid($sub);
	if ($sm && isset($ctx['ct'][$sm])) {
		$r = cp_dl_choose($ctx['by_ct'][$sm] ?? array(), $dates, $at, $ctx);
		if ($r['deal']) { return $fmt($r, 'smid', 'a Salesmate-azonosító alapján'); }
		if ($r['amb'] && !$amb) { $amb = $r; }
	}
	if ($amb) { return array('deal' => 0, 'rule' => '', 'why' => '', 'amb' => true, 'cands' => $amb['cands']); }
	/* 4. ismeretlen cím céges domainről */
	if (!$known) {
		foreach ($parts as $e) {
			$d = cp_dl_dom($e);
			if (cp_dl_freedom($d) || empty($ctx['dom'][$d])) { continue; }
			$ids = array();
			foreach (array_keys($ctx['dom'][$d]['ct'] ?? array()) as $cid) { $ids = array_merge($ids, $ctx['by_ct'][$cid] ?? array()); }
			foreach (array_keys($ctx['dom'][$d]['co'] ?? array()) as $co) { $ids = array_merge($ids, $ctx['by_co'][$co] ?? array()); }
			$r = cp_dl_choose($ids, $dates, $at, $ctx, true);
			if ($r['deal']) { return array('deal' => $r['deal'], 'rule' => isset($r['date']) ? 'domaindate' : 'domain', 'why' => isset($r['date']) ? 'a cég dealjei közül a tárgyban lévő dátum alapján: ' . cp_dl_hu_day($r['date'], true) : 'a cég egyetlen nyitott dealje (' . $d . ')', 'amb' => false, 'cands' => $r['cands']); }
			if ($r['amb']) { return array('deal' => 0, 'rule' => '', 'why' => '', 'amb' => true, 'cands' => $r['cands']); }
		}
	}
	return $none;
}
/* a mostani állapothoz képest: act = keep | link (nem volt deal) | change (más deal); soha nem választ le */
function cp_dl_decide(array $t, array $ctx) {
	$tid = (int) ($t['id'] ?? 0);
	$cur = (int) ($t['deal_id'] ?? 0);
	$keep = function ($why, $p = null) use ($cur) { return array('act' => 'keep', 'deal' => $cur, 'rule' => '', 'why' => $why, 'amb' => $p ? $p['amb'] : false, 'cur' => $cur); };
	if (($tid && !empty($ctx['manual'][$tid])) || (string) ($t['src'] ?? '') === 'panel') { return $keep('manual'); }
	$p = cp_dl_pick($t, $ctx);
	$cd = $cur ? ($ctx['deals'][$cur] ?? null) : null;
	$out = function ($act) use ($p, $cur) { return array('act' => $act, 'deal' => $p['deal'], 'rule' => $p['rule'], 'why' => $p['why'], 'amb' => false, 'cur' => $cur); };
	if (!$cd || $cd['del']) { return $p['deal'] ? $out($cur ? 'change' : 'link') : $keep($cur ? 'deleted' : 'none', $p); }
	if (!$p['deal'] || $p['deal'] === $cur) { return $keep('same', $p); }
	$sm = $tid && !empty($ctx['smauth'][$tid]);
	if ($p['rule'] === 'token') { return $out('change'); }
	/* ugyanannak a kontaktnak több dealje közül a dátum dönt (pl. ügynökség 11 nyitott deallel) */
	if ($p['rule'] === 'date' && !$sm && in_array($cur, $p['cands'], true) && $ctx['deals'][$p['deal']]['st'] === 'Open') { return $out('change'); }
	/* v2.9.52: visszatérő ügyfél – a szál a régi deal lezárása (rendezvénye) után indult, és azóta van nyitott dealje */
	if ($cd['st'] !== 'Open' && !$sm && $ctx['deals'][$p['deal']]['st'] === 'Open') {
		$end = $cd['cl'] !== '' ? $cd['cl'] : ($cd['ev'] !== '' ? $cd['ev'] . ' 23:59:59' : '');
		$tc = (string) ($t['created_at'] ?? '');
		if ($tc !== '' && $end !== '' && $tc > $end) { $r = $out('change'); if ($p['rule'] !== 'date') { $r['why'] = 'az ügyfél nyitott dealje'; } return $r; }
	}
	return $keep('other', $p);
}
/* v2.9.73: az esemény szövege – „Deal automatikusan csatolva (ok): cím” */
function cp_dl_event_text($dec, $ctx) {
	$d = $ctx['deals'][$dec['deal']] ?? null;
	$title = $d && $d['title'] !== '' ? $d['title'] : '#' . $dec['deal'];
	return mb_substr(($dec['act'] === 'change' ? 'Deal automatikusan áthelyezve' : 'Deal automatikusan csatolva') . ($dec['why'] !== '' ? ' (' . $dec['why'] . ')' : '') . ': ' . $title, 0, 255);
}
function cp_dl_event($tid, $dec, $ctx) {
	global $wpdb;
	$wpdb->insert(cp_mail_t('events'), array('thread_id' => (int) $tid, 'kind' => 'autodeal', 'text' => cp_dl_event_text($dec, $ctx), 'author_uid' => 0, 'author' => 'Crazy panel', 'created_at' => gmdate('Y-m-d H:i:s')));
}
function cp_dl_apply($tid, $dec, $ctx) {
	if (!in_array($dec['act'], array('link', 'change'), true) || !$dec['deal'] || (int) $dec['deal'] === (int) $dec['cur']) { return false; }
	global $wpdb;
	$wpdb->update(cp_mail_t('threads'), array('deal_id' => (int) $dec['deal']), array('id' => (int) $tid));
	cp_dl_event($tid, $dec, $ctx);
	return true;
}
function cp_dl_thread_cols() { return 'id, deal_id, src, created_at, last_at, contact_email, subject'; }

/* ------------------------------------------------------------ v2.9.73: próbafuttatás (CSAK OLVAS)
 * Az utolsó $days nap szálaira kiszámolja, mit választanának az új szabályok a mostani deal_id-hez képest.
 * Hívás (próbából): cp_imp_link_dryrun(60). Visszaad: n, ms, q (lekérdezések), out (kimenetenként), rule (szabályonként:
 * link / change), ex (kategóriánként legfeljebb 50 szál-azonosító). Semmit nem ír. */
function cp_imp_link_dryrun($days = 60) {
	if (!function_exists('cp_crm_t') || !function_exists('cp_mail_t')) { return array('err' => 'nincs CRM / levelezés'); }
	global $wpdb;
	$t0 = microtime(true);
	$days = max(1, min(400, (int) $days));
	$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT ' . cp_dl_thread_cols() . ' FROM ' . cp_mail_t('threads') . " WHERE last_at > %s AND folder NOT IN ('spam', 'promo') AND contact_email <> '' ORDER BY last_at DESC LIMIT 5000", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)), ARRAY_A);
	$ctx = cp_dl_ctx($rows);
	$res = array('n' => count($rows), 'out' => array('unchanged' => 0, 'linked' => 0, 'changed' => 0, 'unlink' => 0, 'manual' => 0, 'ambiguous' => 0, 'none' => 0), 'rule' => array(), 'ex' => array());
	$ex = function ($k, $id) use (&$res) { if (!isset($res['ex'][$k])) { $res['ex'][$k] = array(); } if (count($res['ex'][$k]) < 50) { $res['ex'][$k][] = (int) $id; } };
	foreach ($ctx['ths'] as $t) {
		$d = cp_dl_decide($t, $ctx);
		$cur = (int) $t['deal_id'];
		if ($d['why'] === 'manual') { $res['out']['manual']++; $ex('manual', $t['id']); continue; }
		if ($d['act'] === 'link') { $res['out']['linked']++; $ex('linked', $t['id']); $res['rule'][$d['rule']]['link'] = ($res['rule'][$d['rule']]['link'] ?? 0) + 1; $ex('linked_' . $d['rule'], $t['id']); continue; }
		if ($d['act'] === 'change') { $res['out']['changed']++; $ex('changed', $t['id']); $res['rule'][$d['rule']]['change'] = ($res['rule'][$d['rule']]['change'] ?? 0) + 1; $ex('changed_' . $d['rule'], $t['id']); continue; }
		if ($cur && !$d['deal']) { $res['out']['unlink']++; $ex('unlink', $t['id']); continue; } /* elvileg soha */
		$res['out']['unchanged']++;
		if (!$cur) { $res['out']['none']++; $ex($d['amb'] ? 'none_ambiguous' : 'none', $t['id']); }
		if ($d['amb']) { $res['out']['ambiguous']++; $ex('ambiguous', $t['id']); }
	}
	$res['q'] = $ctx['q'] + 1;
	$res['ms'] = (int) round((microtime(true) - $t0) * 1000);
	return $res;
}
