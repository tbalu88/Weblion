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

/* v2.9.182: a panel-felhasználók (kollégák) belépési címei és a WordPress admin címe */
function cp_dl_team() {
	static $t = null;
	if ($t !== null) { return $t; }
	$t = array();
	if (function_exists('cp_pu_data')) { foreach ((array) (cp_pu_data()['list'] ?? array()) as $u) { $e = strtolower(trim((string) ($u['e'] ?? ''))); if ($e !== '') { $t[$e] = 1; } } }
	$a = strtolower(trim((string) get_option('admin_email'))); if ($a !== '') { $t[$a] = 1; }
	return $t;
}

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
	/* v2.9.182: a kollégák saját (pl. gmail-es) címe sem ügyfélcím – különben a kolléga nevére felvett teszt-deal minden
	   olyan levélszálat magához köt, amelyben a kolléga címe szerepel (pl. másolatként) */
	$team = cp_dl_team();
	/* a szálak résztvevői sorrendben: az új levél partnere, a szál kontaktja, a levelek címei */
	$emails = array(); $smids = array(); $toks = array(); $curs = array();
	foreach ($ths as $i => $t) {
		$l = array();
		foreach (array_merge(array((string) ($t['first'] ?? ''), (string) ($t['contact_email'] ?? '')), (array) ($t['parts'] ?? array()), $ctx['parts'][(int) ($t['id'] ?? 0)] ?? array()) as $e) {
			$e = strtolower(trim($e));
			if ($e !== '' && strpos($e, '@') !== false && !cp_imp_ours($e) && !isset($team[$e]) && !in_array($e, $l, true)) { $l[] = $e; }
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
/* v2.9.295: automatikusan CSAK nyitott dealhez köt (a felhasználó döntése): dátum-egyezés egy nyitott deallel → egyetlen nyitott deal;
   több nyitott dátum nélkül: kétes (javaslat). Nyitott deal nélkül nincs kötés – a lezárt dealek csak javaslatként (cands)
   jelennek meg; 'noopen' jelzi, hogy a kontaktnak nincs nyitott dealje (új ajánlatkérésnél ebből lesz új deal). */
function cp_dl_choose(array $ids, array $dates, $at, $ctx, $domain = false) {
	$ids = array_values(array_unique(array_filter($ids, function ($i) use ($ctx) { return isset($ctx['deals'][$i]) && !$ctx['deals'][$i]['del']; })));
	if (!$ids) { return array('deal' => 0, 'amb' => false, 'cands' => array(), 'noopen' => 1); }
	$open = array_values(array_filter($ids, function ($i) use ($ctx) { return $ctx['deals'][$i]['st'] === 'Open'; }));
	if ($dates) {
		$dmo = array(); $hit = array();
		foreach ($open as $i) { $x = array_values(array_intersect(cp_dl_deal_days($i, $ctx), $dates)); if ($x) { $dmo[] = $i; $hit[$i] = $x[0]; } }
		if (count($dmo) === 1) { return array('deal' => $dmo[0], 'amb' => false, 'cands' => $ids, 'date' => $hit[$dmo[0]]); }
		/* v2.9.296: a levél jövőbeli dátuma egyik dealhez sem illik → új ajánlatkérés (akkor is, ha van nyitott dealje) */
		if (!$dmo && !$domain) {
			$all = array(); foreach ($ids as $i) { foreach (cp_dl_deal_days($i, $ctx) as $d) { $all[$d] = 1; } }
			$today = substr((string) $at, 0, 10) ?: gmdate('Y-m-d');
			foreach ($dates as $d) { if ($d >= $today && !isset($all[$d])) { return array('deal' => 0, 'amb' => false, 'cands' => $ids, 'noopen' => 1, 'newdate' => $d); } }
		}
	}
	if (count($open) === 1) { return array('deal' => $open[0], 'amb' => false, 'cands' => $ids, 'one' => 1); }
	if ($open) { return array('deal' => 0, 'amb' => count($open) > 1, 'cands' => $ids); }
	return array('deal' => 0, 'amb' => false, 'cands' => $ids, 'noopen' => 1);
}
/* a szabályok sorrendben; visszaad: deal, rule (token|date|contact|alias|part|smid|domain|domaindate|''), why, amb, cands */
function cp_dl_pick(array $t, array $ctx) {
	$sub = trim((string) ($t['subject'] ?? '') . ' ' . (string) ($t['subj2'] ?? ''));
	$at = (string) ($t['at'] ?? '') !== '' ? (string) $t['at'] : ((string) ($t['last_at'] ?? '') !== '' ? (string) $t['last_at'] : gmdate('Y-m-d H:i:s'));
	$dates = cp_dl_dates($sub, $at);
	/* v2.9.296: ha a tárgyban nincs dátum, a levél saját (nem idézett) szövegéből */
	if (!$dates && (string) ($t['text'] ?? '') !== '') { $dates = cp_dl_text_dates($t['text'], $at); }
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
		if (!$r['deal'] && !empty($r['noopen']) && !isset($noopen)) { $cs = array_values(array_unique(array_merge($direct, $alias))); $noopen = array('cid' => (int) $cs[0], 'email' => $e, 'cands' => $r['cands']); }
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
	if (isset($noopen)) { return $none + array('noopen' => $noopen); } /* ismert ügyfél, nyitott deal nélkül: új ajánlatkérésnél új deal (cp_dl_autonew) */
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
	$keep = function ($why, $p = null) use ($cur) { return array('act' => 'keep', 'deal' => $cur, 'rule' => '', 'why' => $why, 'amb' => $p ? $p['amb'] : false, 'cur' => $cur) + ($p && !empty($p['noopen']) ? array('noopen' => $p['noopen']) : array()); };
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

/* ------------------------------------------------------------ v2.9.295: új deal a levélből (a felhasználó döntése)
 * Ha ismert ügyfél (kontakt vagy további címe) ír, nincs nyitott dealje, és a levélben új rendezvénydátum van – jövőbeli,
 * és egyik (lezárt, elvesztett) dealjének sem a napja –, a panel új dealt hoz létre az első szakaszban, és a szálat hozzá köti.
 * Dátum: előbb a tárgyból, ha ott nincs, a levél saját (nem idézett) szövegének elejéből. Csak élő, beérkező levélnél fut
 * (a 15 percenkénti újrakötés soha nem hoz létre dealt). Kikapcsolható: cp_dl_autonew = '0'. */
function cp_dl_autonew_on() { return get_option('cp_dl_autonew', '1') !== '0' && !empty(cp_panel_opts()['stage_write']); }
function cp_dl_own_text($text) {
	$t = (string) $text;
	$p = preg_split('/^\s*(?:>|-{2,}\s*(?:Original|Eredeti|Forwarded|Továbbított)|(?:On|Le|Am)\s.{4,120}(?:wrote|écrit|schrieb)\s*:|.{4,160}\s(?:ezt írta|írta)\s*:|(?:Feladó|From)\s*:)/mu', $t, 2);
	return mb_substr((string) $p[0], 0, 2000);
}
/* v2.9.296: dátumok a levél saját szövegéből – csak rendezvényre utaló sorokból (a fizetési határidő, „holnap 12.15-kor” stb. ne számítson) */
function cp_dl_text_dates($text, $at) {
	$out = array();
	foreach (preg_split('/\R/u', cp_dl_own_text($text)) as $ln) {
		if (!preg_match('/érdekl|ajánlat|árajánlat|kapacit|szabad|foglal|bérl|bérel|rendezvény|esemény|időpont|dátum|party|parti|buli|gála|vacsor|esküvő|karácsony|évzáró|fotóautomat|szelfi|selfie|spinner|fotó/iu', $ln)) { continue; }
		foreach (cp_dl_dates($ln, $at) as $d) { if (!in_array($d, $out, true)) { $out[] = $d; } }
	}
	return $out;
}
/* v2.9.303 (M8): a levél saját szövegéből szabályalapon (AI nélkül): helyszín, kezdés, óraszám, létszám */
function cp_dl_extract($text) {
	$t = cp_dl_own_text($text); $o = array();
	if (preg_match('/^[\s\-•*]*(?:helysz[ií]n|location|venue|c[ií]m)\s*[:\-–]\s*(.{3,160})$/imu', $t, $m) || preg_match('/(?:helysz[ií]n|location|venue)\s*:\s*([^\n]{3,160})/iu', $t, $m)) { $o['venue'] = trim(preg_replace('/\s+/u', ' ', $m[1]), " .\t"); }
	if (preg_match('/(\d{2,4})\s*(?:f[őo]|f[őo]s|vend[ée]g|r[ée]sztvev[őo]|ember|people|guests)\b/iu', $t, $m) && (int) $m[1] >= 10 && (int) $m[1] <= 5000) { $o['guests'] = (int) $m[1]; }
	/* „20 és 23 óra között”, „18:00–22:00”, „17-től 21-ig” */
	if (preg_match('/(?<!\d)(\d{1,2})(?:[:.](\d{2}))?\s*(?:óra|h)?\s*(?:és|–|-|—|tól|-tól|t[óo]l)\s*(\d{1,2})(?:[:.](\d{2}))?\s*(?:óra|h)?\s*(?:között|-ig|ig)?/iu', $t, $m) && (int) $m[1] <= 23 && (int) $m[3] <= 24 && ((isset($m[2]) && $m[2] !== '') || preg_match('/között|óra|ig\b|-ig/iu', $m[0]))) {
		$sh = (int) $m[1]; $sm = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0; $eh = (int) $m[3]; $em = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
		$dur = ($eh * 60 + $em) - ($sh * 60 + $sm); if ($dur <= 0) { $dur += 1440; }
		if ($dur >= 60 && $dur <= 720) { $o['start'] = sprintf('%02d:%02d', $sh, $sm); $o['hours'] = (int) ceil($dur / 60); }
	}
	if (empty($o['hours']) && preg_match('/(?<!\d)(\d{1,2})\s*(?:órá(?:s|ra|t|nyi)?|óra)\b/iu', $t, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) { $o['hours'] = (int) $m[1]; }
	if (empty($o['start']) && preg_match('/(?<!\d)(\d{1,2})[:.](\d{2})\s*(?:-kor|kor|-tól|tól|órától|-t[óo]l|kezd)/iu', $t, $m) && (int) $m[1] <= 23) { $o['start'] = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]); }
	return $o;
}
function cp_dl_autonew($tid, array $no, $subj, $text, array $ctx) {
	if (!$tid || empty($no['cid']) || !cp_dl_autonew_on() || !function_exists('cp_sm_request') || !function_exists('cp_crm_new_id')) { return 0; }
	global $wpdb;
	$today = wp_date('Y-m-d'); $max = wp_date('Y-m-d', time() + 730 * DAY_IN_SECONDS);
	$taken = array(); foreach ((array) $no['cands'] as $i) { foreach (cp_dl_deal_days((int) $i, $ctx) as $d) { $taken[$d] = 1; } }
	$pickd = function ($l) use ($today, $max, $taken) { $l = array_values(array_filter($l, function ($d) use ($today, $max, $taken) { return $d >= $today && $d <= $max && !isset($taken[$d]); })); sort($l); return $l ? $l[0] : ''; };
	$date = $pickd(cp_dl_dates($subj, gmdate('Y-m-d H:i:s')));
	if ($date === '') { $date = $pickd(cp_dl_text_dates($text, gmdate('Y-m-d H:i:s'))); }
	if ($date === '') { return 0; }
	/* kettőzés ellen: ugyanarra a kontaktra és napra egyszer (párhuzamos letöltésnél is) */
	if (!add_option('cp_dl_an_' . md5($no['cid'] . '|' . $date), time(), '', 'no')) { return 0; }
	$D = cp_crm_t('deals');
	if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $D WHERE contact_id = %d AND event_d = %s AND deleted_at IS NULL LIMIT 1", (int) $no['cid'], $date))) { return 0; }
	$ct = $wpdb->get_row($wpdb->prepare('SELECT id, name, email FROM ' . cp_crm_t('contacts') . ' WHERE id = %d', (int) $no['cid']), ARRAY_A);
	if (!$ct) { return 0; }
	$prev = $wpdb->get_row($wpdb->prepare("SELECT title, owner_id FROM $D WHERE contact_id = %d AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1", (int) $no['cid']), ARRAY_A);
	$brand = $prev && preg_match('/^\s*(\[[^\]]{2,30}\])/u', (string) $prev['title'], $m) && !preg_match('/facebook/i', $m[1]) ? $m[1] : '[Crazyphoto]';
	$name = trim((string) $ct['name']) !== '' ? trim((string) $ct['name']) : (string) $ct['email'];
	$o = cp_panel_opts();
	$body = array('title' => $brand . ' ' . $name . ' | ' . $date, 'pipeline' => $o['pipeline'], 'stage' => cp_crm_stages()[0], 'primaryContact' => (int) $no['cid'], 'currency' => 'HUF');
	if ($prev && (int) $prev['owner_id'] && isset(cp_crm_users()[(int) $prev['owner_id']])) { $body['owner'] = (int) $prev['owner_id']; }
	$body[cp_crm_sm_field('event_d')] = $date;
	/* szolgáltatás a tárgyból / a levélből, a meglévő értékek közül */
	$hay = mb_strtolower(remove_accents($subj . ' ' . cp_dl_own_text($text))); $sv = array();
	foreach (array('Fotóautomata' => '/fotoautomata/', 'Szelfigép' => '/szelfigep|photobooth|selfie ?box|szelfi ?box/', '360 Spinner' => '/spinner|360/', 'AI Karikatúra' => '/karikatura/') as $k => $re) { if (preg_match($re, $hay)) { $sv[] = $k; } }
	if ($sv) { $body[cp_crm_sm_field('service')] = mb_substr(implode(' | ', $sv), 0, 100); }
	/* v2.9.303 (M8): a levélből kiolvasható adatok a dealbe */
	$ex = cp_dl_extract($text); $exl = array();
	if (!empty($ex['venue'])) { $body[cp_crm_sm_field('venue')] = $ex['venue']; $exl[] = 'helyszín'; }
	if (!empty($ex['guests'])) { $body[cp_crm_sm_field('guests')] = (int) $ex['guests']; $exl[] = 'létszám'; }
	if (!empty($ex['hours'])) { $body[cp_crm_sm_field('hours')] = (int) $ex['hours']; $exl[] = 'óraszám'; }
	if (!empty($ex['start']) && function_exists('cp_crm_to_sm')) { $tv = cp_crm_to_sm('time', $ex['start'], $date); if ($tv) { $body[cp_crm_sm_field('start_at')] = $tv; $exl[] = 'kezdés'; } }
	$dr = cp_sm_request('POST', '/apis/deal/v4', $body);
	if (is_wp_error($dr)) { delete_option('cp_dl_an_' . md5($no['cid'] . '|' . $date)); return 0; }
	$id = cp_crm_new_id($dr); if (!$id) { return 0; }
	if (function_exists('cp_crm_after_write')) { cp_crm_after_write($id); }
	$wpdb->insert(cp_crm_t('history'), array('deal_id' => $id, 'field' => 'created', 'old_val' => '', 'new_val' => 'létrehozva automatikusan egy levélből (új rendezvénydátum)', 'by_user' => 'Crazy panel', 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel'));
	$wpdb->update(cp_mail_t('threads'), array('deal_id' => $id), array('id' => (int) $tid));
	$wpdb->insert(cp_mail_t('events'), array('thread_id' => (int) $tid, 'kind' => 'autodeal', 'text' => mb_substr('Új deal automatikusan létrehozva (új rendezvénydátum a levélben: ' . cp_dl_hu_day($date, true) . ', visszatérő ügyfél): ' . $body['title'], 0, 255), 'author_uid' => 0, 'author' => 'Crazy panel', 'created_at' => gmdate('Y-m-d H:i:s')));
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	/* v2.9.303 (M8): értesítés a felelősnek (az előző deal felelőse; ha nincs panel-fiókja, az adminok) */
	if (function_exists('cp_notify') && function_exists('cp_pu_data')) {
		$to = array(); $own = (int) ($body['owner'] ?? 0);
		foreach ((array) (cp_pu_data()['list'] ?? array()) as $uid => $u) { if (empty($u['a'])) { continue; } if ($own && (int) ($u['sm'] ?? 0) === $own) { $to[] = (int) $uid; } }
		if (!$to) { foreach ((array) (cp_pu_data()['list'] ?? array()) as $uid => $u) { if (!empty($u['a']) && ($u['r'] ?? '') === 'admin') { $to[] = (int) $uid; } } }
		foreach (array_unique($to) as $u) { cp_notify($u, 'autodeal', 'visszatérő ügyfél új ajánlatkérése – új deal: ' . $body['title'] . ($exl ? ' (a levélből kitöltve: ' . implode(', ', $exl) . ')' : ''), 'deals:' . $id, 'Crazy panel'); }
	}
	return $id;
}
