<?php
/* =====================================================================
 * Postafiók-import (v1.40) – a korábbi levelezés áthozatala IMAP-on, CSAK OLVASVA (a szerveren semmi nem változik:
 * nem töröl, nem helyez át, nem jelöl olvasottnak – BODY.PEEK / FT_PEEK).
 * Csak a szöveg jön át (az idézett előzmények levágva), a mellékletekből csak a fájlnév; hírlevelek, értesítők és
 * automatikus levelek kimaradnak. Időszak: az utolsó N év (alapból 2).
 * A levelek a panel Levelezés táblájába kerülnek lezárt, „Archív” szálakként (címke: import), a kontakt e-mail-címe
 * alapján a deal-hez kötve – az AI válaszvarázsló innen keresi a hasonló korábbi levélváltásokat.
 * Menet: Beállítások → Crazy levelezés: 1) felmérés (csak számol), 2) import indítása – percenként egy köteg (WP-cron).
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_imp_opts() {
	$o = get_option('cp_imp_opts', array());
	return wp_parse_args(is_array($o) ? $o : array(), array('host' => 'mail.eventcrazy.hu', 'port' => 993, 'user' => 'info@eventcrazy.hu', 'box' => 'eventcrazy', 'years' => 2, 'folders' => array()));
}
function cp_imp_pw() { return function_exists('cp_st_secret') ? (string) cp_st_secret('imap_pw') : ''; }
function cp_imp_state() { $s = get_option('cp_imp_state', array()); return is_array($s) ? $s : array(); }
function cp_imp_state_set($s) { update_option('cp_imp_state', $s, false); }

function cp_imp_ref() { $o = cp_imp_opts(); return '{' . $o['host'] . ':' . (int) $o['port'] . '/imap/ssl/readonly}'; }

function cp_imp_open($folder = '') {
	if (!function_exists('imap_open')) { return new WP_Error('noimap', 'A szerveren nincs IMAP-bővítmény.'); }
	$o = cp_imp_opts(); $pw = cp_imp_pw();
	if ($pw === '') { return new WP_Error('nopw', 'Nincs megadva a postafiók jelszava.'); }
	$mb = @imap_open(cp_imp_ref() . $folder, $o['user'], $pw, OP_READONLY, 1, array('DISABLE_AUTHENTICATOR' => 'GSSAPI'));
	$errs = imap_errors(); imap_alerts();
	if (!$mb) { return new WP_Error('login', 'Nem sikerült csatlakozni: ' . mb_substr(implode(' · ', (array) $errs), 0, 200)); }
	return $mb;
}

/* rendszermappák, amik kimaradnak */
function cp_imp_skip_folder($name) { return (bool) preg_match('/(trash|kuka|t[öo]r[öo]lt|spam|junk|lev[ée]lszem[ée]t|draft|piszkozat|outbox|archive.*spam)/iu', $name); }
function cp_imp_is_sent($name) { return (bool) preg_match('/(sent|elk[üu]ld[öo]tt)/iu', $name); }
function cp_imp_folder_name($full) { $r = cp_imp_ref(); return strpos($full, $r) === 0 ? substr($full, strlen($r)) : preg_replace('/^\{[^}]*\}/', '', $full); }
function cp_imp_utf8($s) { $u = @mb_convert_encoding($s, 'UTF-8', 'UTF7-IMAP'); return is_string($u) && $u !== '' ? $u : $s; }

/* 1) felmérés: mappánként és évenként a levelek száma (csak fejlécdátum, tartalom nélkül) */
function cp_imp_survey() {
	$mb = cp_imp_open();
	if (is_wp_error($mb)) { return $mb; }
	$o = cp_imp_opts();
	$since = gmdate('d-M-Y', strtotime('-' . max(1, (int) $o['years']) . ' years'));
	$out = array('at' => time(), 'since' => $since, 'folders' => array());
	imap_errors();
	foreach ((array) imap_list($mb, cp_imp_ref(), '*') as $full) {
		$name = cp_imp_folder_name($full);
		if (!@imap_reopen($mb, cp_imp_ref() . $name, OP_READONLY)) { continue; }
		$all = (int) imap_num_msg($mb);
		$uids = $all ? (array) imap_search($mb, 'SINCE "' . $since . '"', SE_UID) : array();
		$size = 0; $years = array();
		if ($uids) {
			/* méret és év: a fejlécösszegzőből, kötegben */
			foreach (array_chunk($uids, 500) as $ch) {
				foreach ((array) imap_fetch_overview($mb, implode(',', $ch), FT_UID) as $ov) {
					$size += (int) ($ov->size ?? 0);
					$y = isset($ov->date) ? (int) gmdate('Y', (int) strtotime($ov->date)) : 0;
					$years[$y] = ($years[$y] ?? 0) + 1;
				}
			}
		}
		ksort($years);
		$out['folders'][] = array('name' => cp_imp_utf8($name), 'raw' => $name, 'all' => $all, 'n' => count($uids), 'mb' => round($size / 1048576, 1), 'years' => $years, 'skip' => cp_imp_skip_folder($name), 'sent' => cp_imp_is_sent($name));
	}
	imap_close($mb);
	update_option('cp_imp_survey', $out, false);
	return $out;
}

/* ------------------------------------------------------------ egy levél feldolgozása */
/* biztonságos átkódolás: PHP 8-ban az ismeretlen karakterkészlet kivételt dob – ilyenkor az eredeti marad */
function cp_imp_conv($data, $cs) {
	$cs = strtoupper(trim((string) $cs, " \t\"'"));
	if ($cs === '' || $cs === 'UTF-8' || $cs === 'UTF8' || $cs === 'US-ASCII' || $cs === 'DEFAULT') { return $data; }
	$alias = array('WINDOWS-1250' => 'Windows-1250', 'CP1250' => 'Windows-1250', 'WINDOWS-1252' => 'Windows-1252', 'CP1252' => 'Windows-1252', 'ISO-8859-2' => 'ISO-8859-2', 'LATIN2' => 'ISO-8859-2', 'ISO-8859-1' => 'ISO-8859-1', 'LATIN1' => 'ISO-8859-1');
	$cs = $alias[$cs] ?? $cs;
	try { $c = mb_convert_encoding($data, 'UTF-8', $cs); return is_string($c) ? $c : $data; }
	catch (\Throwable $e) {
		if (function_exists('iconv')) { $c = @iconv($cs, 'UTF-8//IGNORE', $data); if (is_string($c) && $c !== '') { return $c; } }
		return $data;
	}
}
function cp_imp_hdr($s) {
	$out = '';
	foreach ((array) imap_mime_header_decode((string) $s) as $p) {
		$cs = strtoupper((string) $p->charset);
		/* v2.9.20: kódolatlan 8 bites rész (pl. nyers Latin-2 „ő”) – különben az adatbázis elutasítja a sort, és a levél elveszik */
		if (($cs === 'DEFAULT' || $cs === '') && !mb_check_encoding($p->text, 'UTF-8')) { $cs = 'ISO-8859-2'; }
		$out .= cp_imp_conv($p->text, $cs);
	}
	return trim($out);
}
function cp_imp_decode($data, $enc, $charset, $cut = false) {
	if ($enc === 3) { $data = base64_decode($data); } elseif ($enc === 4) { $data = quoted_printable_decode($data); }
	/* v2.9.58: levágott (600 KB-os) UTF-8 szövegnél a végén félbevágott karakter maradhat – azt (legfeljebb 3 bájt) eldobjuk,
	   különben az egész szöveg „nem UTF-8” lenne, és Latin-2-ként átkódolva zagyvává válna */
	if ($cut && !mb_check_encoding($data, 'UTF-8') && in_array(strtoupper(trim((string) $charset, " \t\"'")), array('', 'UTF-8', 'UTF8', 'US-ASCII', 'DEFAULT'), true)) {
		for ($k = 1; $k <= 3; $k++) { $x = substr($data, 0, -$k); if (mb_check_encoding($x, 'UTF-8')) { $data = $x; break; } }
	}
	$data = cp_imp_conv($data, $charset);
	if (!mb_check_encoding($data, 'UTF-8')) { $data = cp_imp_conv($data, 'ISO-8859-2'); }
	return $data;
}
function cp_imp_param($part, $name) {
	/* v2.8.6: RFC 2231 is – filename*=utf-8''AI%20Karikat%C3%BAra.jpg, és a darabolt filename*0*, filename*1* … */
	foreach (array('dparameters', 'parameters') as $k) {
		if (empty($part->$k)) { continue; }
		$plain = ''; $pieces = array();
		foreach ($part->$k as $p) {
			$a = strtolower((string) $p->attribute);
			if ($a === $name) { $plain = (string) $p->value; }
			elseif ($a === $name . '*') { $pieces[0] = array(true, (string) $p->value); }
			elseif (preg_match('/^' . preg_quote($name, '/') . '\*(\d+)(\*?)$/', $a, $m)) { $pieces[(int) $m[1]] = array($m[2] === '*', (string) $p->value); }
		}
		if ($pieces) {
			ksort($pieces); $raw = '';
			foreach ($pieces as $x) { $raw .= $x[1]; }
			return cp_mail_fname($raw);
		}
		if ($plain !== '') { return cp_mail_fname(cp_imp_hdr($plain)); }
	}
	return '';
}
/* fájlnév kiolvasása: RFC 2231 (charset'nyelv'%XX), MIME-fejléc (=?utf-8?…?=), és a Mac-es szétbontott ékezetek összevonása (u + ́ → ú) */
function cp_mail_fname($s) {
	$s = trim((string) $s, " \t\"'");
	if (preg_match("/^([A-Za-z0-9_-]+)'[A-Za-z-]*'(.*)$/s", $s, $m)) {
		$v = rawurldecode($m[2]);
		$cs = strtoupper($m[1]);
		if ($cs !== 'UTF-8' && $cs !== 'US-ASCII') { $v = cp_imp_conv($v, $cs); } /* 2026-11-09: a biztonságos átkódoló (ismeretlen kódolásnál nem omlik össze) */
		$s = $v;
	} elseif (strpos($s, '=?') !== false && function_exists('cp_imp_hdr')) { $s = cp_imp_hdr($s); }
	if (class_exists('Normalizer') && mb_check_encoding($s, 'UTF-8')) { $n = Normalizer::normalize($s, Normalizer::FORM_C); if ($n !== false) { $s = $n; } }
	return $s;
}
/* a részek bejárása: első text/plain és text/html, mellékletnevek */
function cp_imp_walk($mb, $uid, $part, $num, &$acc) {
	$type = (int) $part->type; $sub = strtolower((string) ($part->subtype ?? ''));
	$fname = cp_imp_param($part, 'filename') ?: cp_imp_param($part, 'name');
	$disp = strtolower((string) ($part->disposition ?? ''));
	if ($type === 1 && !empty($part->parts)) {
		foreach ($part->parts as $i => $p) { cp_imp_walk($mb, $uid, $p, ($num === '' ? '' : $num . '.') . ($i + 1), $acc); }
		return;
	}
	/* v2.9.66: a melléklet becsült (dekódolt) mérete is – a 10 KB alatti képek (aláírás-ikonok, üres képek) a deal Fájlok kártyáján rejtve */
	if ($fname !== '' && ($disp === 'attachment' || $type !== 0)) { $acc['att'][] = array('n' => mb_substr($fname, 0, 120)) + (!empty($part->ifid) ? array('cid' => trim((string) $part->id, '<> ')) : array()) + (isset($part->bytes) ? array('s' => (int) ((int) $part->encoding === 3 ? $part->bytes * 0.74 : $part->bytes)) : array()); return; }
	if ($type === 0 && ($sub === 'plain' || $sub === 'html') && !isset($acc[$sub])) {
		$raw = imap_fetchbody($mb, $uid, $num === '' ? '1' : $num, FT_UID | FT_PEEK);
		$cut = strlen((string) $raw) > 600000; /* v2.9.58: bájtméret (strlen), nem karakterszám */
		if ($cut) { $raw = substr($raw, 0, 600000); }
		$acc[$sub] = cp_imp_decode((string) $raw, (int) $part->encoding, cp_imp_param($part, 'charset'), $cut);
	}
}
/* v1.41: az ügyfél levelében idézett KORÁBBI SAJÁT LEVELÜNK kinyerése – a Salesmate-ből küldött válaszaink
 * nincsenek a postafiók Elküldött mappájában, de az ügyfél válaszában idézve ott vannak.
 * Visszaad: array('text' => …, 'ts' => időbélyeg vagy 0) vagy null. */
function cp_imp_html_to_quoted_text($html) {
	$h = preg_replace('#<(style|script|head)[^>]*>.*?</\1>#is', '', (string) $html);
	$h = preg_replace('#<blockquote[^>]*>#i', "\n[[QS]]\n", $h);
	$h = preg_replace('#</blockquote>#i', "\n[[QE]]\n", $h);
	$h = preg_replace('#<(br|/p|/div|/li|/tr|/h\d)\s*/?>#i', "\n", $h);
	$t = html_entity_decode(wp_strip_all_tags($h), ENT_QUOTES, 'UTF-8');
	/* a blockquote-on belüli sorok elé „> ” (beágyazásnál többszörösen) */
	$out = array(); $lvl = 0;
	foreach (explode("\n", str_replace("\r", '', $t)) as $ln) {
		if (trim($ln) === '[[QS]]') { $lvl++; continue; }
		if (trim($ln) === '[[QE]]') { $lvl = max(0, $lvl - 1); continue; }
		$out[] = str_repeat('> ', $lvl) . $ln;
	}
	return implode("\n", $out);
}
function cp_imp_ours_re() {
	$o = cp_imp_opts();
	return '(' . preg_quote(strtolower($o['user']), '/') . '|eventcrazy|crazy\s?photo|studio\s?frames)';
}
function cp_imp_hu_date($s) {
	$mon = array('jan' => 1, 'feb' => 2, 'márc' => 3, 'marc' => 3, 'ápr' => 4, 'apr' => 4, 'máj' => 5, 'maj' => 5, 'jún' => 6, 'jun' => 6, 'júl' => 7, 'jul' => 7, 'aug' => 8, 'szept' => 9, 'szep' => 9, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'dec' => 12);
	if (preg_match('/(20\d\d)[.\-\/]\s*(\d{1,2})[.\-\/]\s*(\d{1,2})\.?[^\d]{0,30}?(\d{1,2}):(\d{2})/u', $s, $m)) { return (int) strtotime(sprintf('%04d-%02d-%02d %02d:%02d Europe/Budapest', $m[1], $m[2], $m[3], $m[4], $m[5])); }
	if (preg_match('/(20\d\d)\.\s*([a-záéíóöőúüű]+)\.?\s*(\d{1,2})\.?[^\d]{0,30}?(\d{1,2}):(\d{2})/iu', $s, $m)) {
		foreach ($mon as $k => $v) { if (strpos(mb_strtolower($m[2]), $k) === 0) { return (int) strtotime(sprintf('%04d-%02d-%02d %02d:%02d Europe/Budapest', $m[1], $v, $m[3], $m[4], $m[5])); } }
	}
	/* angol: „Oct 1, 2026 at 10:05 AM” / „October 1, 2026 10:05” / „1 Oct 2026 at 10:05” */
	$em = array('jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12);
	$y = $mo = $d = 0; $hh = $mi = -1;
	if (preg_match('/\b([A-Za-z]{3,9})\.?\s+(\d{1,2}),?\s+(20\d\d)/', $s, $m) && isset($em[strtolower(substr($m[1], 0, 3))])) { $mo = $em[strtolower(substr($m[1], 0, 3))]; $d = (int) $m[2]; $y = (int) $m[3]; }
	elseif (preg_match('/\b(\d{1,2})\.?\s+([A-Za-z]{3,9})\.?\s+(20\d\d)/', $s, $m) && isset($em[strtolower(substr($m[2], 0, 3))])) { $mo = $em[strtolower(substr($m[2], 0, 3))]; $d = (int) $m[1]; $y = (int) $m[3]; }
	if ($y && preg_match('/\b(\d{1,2}):(\d{2})\s*([AaPp][Mm])?/', $s, $t)) {
		$hh = (int) $t[1]; $mi = (int) $t[2];
		if (!empty($t[3])) { $pm = strtolower($t[3]) === 'pm'; if ($pm && $hh < 12) { $hh += 12; } if (!$pm && $hh === 12) { $hh = 0; } }
	}
	if (!$y || $hh < 0) { return 0; }
	$ts = (int) strtotime(sprintf('%04d-%02d-%02d %02d:%02d Europe/Budapest', $y, $mo, $d, $hh, $mi));
	return $ts > 1500000000 && $ts < time() + 86400 ? $ts : 0;
}
function cp_imp_quoted_reply($plain, $html) {
	$t = trim((string) $plain) !== '' ? str_replace("\r", '', (string) $plain) : ($html !== null ? cp_imp_html_to_quoted_text($html) : '');
	if ($t === '') { return null; }
	$ours = cp_imp_ours_re();
	$lines = explode("\n", $t);
	$n = count($lines);
	for ($i = 0; $i < $n; $i++) {
		$L = $lines[$i];
		/* a) „… <info@eventcrazy.hu> ezt írta:” / „On …, EventCrazy <…> wrote:” (akár két sorba tördelve) */
		$hdr = $L . (isset($lines[$i + 1]) && !preg_match('/(írta|wrote)\s*:\s*$/u', $L) ? ' ' . $lines[$i + 1] : '');
		if (preg_match('/(írta|wrote|schrieb)\s*:\s*$/u', trim($hdr)) && preg_match('/' . $ours . '/iu', $hdr) && !preg_match('/^\s*>/', $L)) {
			$j = preg_match('/(írta|wrote)\s*:\s*$/u', $L) ? $i + 1 : $i + 2;
			$body = array();
			for (; $j < $n; $j++) {
				$q = $lines[$j];
				if (!preg_match('/^\s*>/', $q)) { if (trim($q) === '' && !$body) { continue; } if (trim($q) === '') { $body[] = ''; continue; } break; }
				$q = preg_replace('/^\s*>\s?/', '', $q);
				if (preg_match('/^\s*>/', $q)) { continue; }   /* mélyebb idézet: a mi levelünk által idézett régebbi rész */
				if (preg_match('/(írta|wrote|schrieb)\s*:\s*$/u', $q)) { break; }
				$body[] = $q;
			}
			$txt = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $body)));
			return mb_strlen($txt) >= 20 ? array('text' => mb_substr($txt, 0, 8000), 'ts' => cp_imp_hu_date($hdr)) : null;
		}
		/* b) Outlook-stílus: „From: / Feladó: EventCrazy <info@…>” fejlécblokk, utána a levelünk */
		if (preg_match('/^\s*\**(from|feladó)\s*:\**\s*(.*)$/iu', $L, $m) && preg_match('/' . $ours . '/iu', $m[2])) {
			$j = $i + 1; $ts = 0;
			for (; $j < $n && $j < $i + 8; $j++) {
				if (preg_match('/^\s*\**(sent|date|elküldve|dátum|küldve)\s*:\**\s*(.*)$/iu', $lines[$j], $d)) { $ts = cp_imp_hu_date($d[2]); }
				if (trim($lines[$j]) === '' || preg_match('/^\s*\**(subject|tárgy)\s*:/iu', $lines[$j])) { if (trim($lines[$j]) !== '') { $j++; } break; }
			}
			$body = array();
			for (; $j < $n; $j++) {
				if (preg_match('/^\s*\**(from|feladó)\s*:/iu', $lines[$j]) || preg_match('/^-{2,}\s*(original message|eredeti üzenet)/iu', $lines[$j])) { break; }
				$body[] = preg_replace('/^\s*>\s?/', '', $lines[$j]);
			}
			$txt = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $body)));
			return mb_strlen($txt) >= 20 ? array('text' => mb_substr($txt, 0, 8000), 'ts' => $ts ?: 0) : null;
		}
	}
	return null;
}

/* a szöveg tisztítása: idézett előzmények és aláírás-sallang le, max. 8000 karakter */
function cp_imp_clean_text($plain, $html) {
	$t = $plain;
	if (trim((string) $t) === '' && $html !== null) {
		$h = preg_replace('#<(style|script|head)[^>]*>.*?</\1>#is', '', (string) $html);
		$h = preg_replace('#<blockquote.*$#is', '', $h);
		$h = preg_replace('#<(br|/p|/div|/li|/tr|/h\d)\s*/?>#i', "\n", $h);
		$t = html_entity_decode(wp_strip_all_tags($h), ENT_QUOTES, 'UTF-8');
	}
	$t = str_replace("\r", '', (string) $t);
	/* „X írta:” / „On … wrote:” / „-----Original Message-----” utáni rész, és a > idézett sorok */
	$t = preg_replace('/\n[^\n]{0,200}(írta|wrote|schrieb)\s*:\s*\n.*$/su', '', $t);
	$t = preg_replace('/\n-{2,}\s*(Original Message|Eredeti üzenet|Forwarded message|Továbbított üzenet)\s*-{2,}.*$/sui', '', $t);
	$t = preg_replace('/\n\s*\**(From|Feladó)\s*:\**[^\n]*\n\s*\**(Sent|Date|Elküldve|Dátum|Küldve)\s*:.*$/sui', '', $t);
	$t = preg_replace('/^>.*$\n?/m', '', $t);
	$t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
	$t = trim(preg_replace("/\n{3,}/", "\n\n", $t));
	return mb_strlen($t) > 8000 ? mb_substr($t, 0, 8000) . ' …' : $t;
}
function cp_imp_norm_subj($s) {
	$s = preg_replace('/^\s*((re|fw|fwd|vá|vs|aw|wg|válasz|továbbítás|tov)\s*(\[\d+\])?\s*:\s*)+/iu', '', (string) $s);
	$s = preg_replace('/^\s*\[\d+\]\s*/u', '', $s);
	return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
}
function cp_imp_addr($list) {
	$out = array();
	foreach ((array) $list as $a) { if (!empty($a->mailbox) && !empty($a->host)) { $out[] = array('e' => strtolower($a->mailbox . '@' . $a->host), 'n' => isset($a->personal) ? cp_imp_hdr($a->personal) : ''); } }
	return $out;
}
/* automatikus / tömeges levél? (hírlevél, értesítő, visszapattanó) */
/* v1.89: '' = rendes levél, 'promo' = tömegküldés (hírlevél, leiratkozási linkes megkeresés) → Promóciók mappa, 'skip' = rendszerértesítés (nem kell) */
function cp_imp_kind($hraw, $from) {
	if (preg_match('/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|notifications?@|info@facebookmail|.*@(facebookmail\.com|linkedin\.com|google\.com|accounts\.google\.com|.*\.salesmate\.io|salesmatemail\.com))/i', $from)) { return 'skip'; }
	return cp_imp_is_bulk($hraw, $from) ? 'promo' : '';
}
function cp_imp_is_bulk($hraw, $from) {
	/* v1.88.4: a Feedback-ID és az X-Auto-Response-Suppress normál, gépi üzleti levelekben is szerepel (számlázó / beszerzési rendszerek) – ezek nem számítanak tömegesnek */
	if (preg_match('/^(list-unsubscribe|list-id|auto-submitted:\s*auto|precedence:\s*(bulk|list|junk))/mi', $hraw)) { return true; }
	return (bool) preg_match('/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|notifications?@|news(letter)?@|info@facebookmail|.*@(.*\.)?(facebookmail\.com|linkedin\.com|google\.com|mailchimp\.com|mcsv\.net|mailerlite\.com|salesmatemail\.com|salesmate\.io)$)/i', $from);
}

/* ------------------------------------------------------------ v1.98: levélszál ↔ deal
 * – új szálnál: az ügyfél NYITOTT dealjei közül a legfrissebb; ha nincs nyitott, a levél idejéhez legközelebb létrehozott
 * – egy automatikusan kötött szál (nem kézzel csatolt, nem a panelből indított) átkerül az ügyfél nyitott dealjére,
 *   ha a mostani deal elvesztett / megnyert-lezárt / törölt, vagy még nincs deal (pl. a levél előbb jött, mint a deal)
 * – két nyitott deal között nem vált (azt a kolléga dönti el kézzel) */
define('CP_IMP_GAP_DAYS', 60);
/* saját címeink / domainjeink (kollégák, a másik márka) – ezek nem ügyfelek */
function cp_imp_ours($e) {
	$d = strtolower(substr(strrchr((string) $e, '@') ?: '', 1));
	return in_array($d, array('eventcrazy.hu', 'crazyphoto.hu', 'studioframes.hu'), true) || preg_match('/(^|\.)salesmate(mail)?\.(io|com)$/', $d);
}
/* továbbított levélben az eredeti feladó („From: / Feladó: / Von: Név <cím>”) */
function cp_imp_fwd_sender($plain, $html) {
	$t = $plain !== '' ? $plain : html_entity_decode(wp_strip_all_tags(preg_replace('#<(br|/p|/div)\s*/?>#i', "\n", (string) $html)), ENT_QUOTES, 'UTF-8');
	if (!preg_match('/(?:^|\n)\s*(?:\*?\s*)(?:From|Feladó|Von|De)\s*:\s*\*?\s*([^\n<]*?)\s*[<\[]?(?:mailto:)?([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})[>\]]?/u', mb_substr($t, 0, 20000), $m)) { return null; }
	return array('e' => strtolower($m[2]), 'n' => trim($m[1], " \t\"'*"));
}
function cp_imp_pick_deal($email, $at) {
	if (!function_exists('cp_crm_t') || $email === '') { return 0; }
	global $wpdb;
	return (int) $wpdb->get_var($wpdb->prepare('SELECT d.id FROM ' . cp_crm_t('deals') . ' d JOIN ' . cp_crm_t('contacts') . " c ON c.id = d.contact_id WHERE c.email = %s AND d.deleted_at IS NULL AND c.deleted_at IS NULL ORDER BY (d.status = 'Open') DESC, IF(d.status = 'Open', UNIX_TIMESTAMP(d.created_at), 0) DESC, ABS(DATEDIFF(d.created_at, %s)) LIMIT 1", strtolower($email), $at));
}
/* v2.9.62: a szál összes ügyfél-résztvevője (feladók, címzettek, másolatok – a saját címeink nélkül), a szál kontaktja elöl.
 * Ok: a szál kontaktja gyakran egy közös iroda@ cím, a dealhez tartozó kolléga (pl. aki a szerződést intézi) csak címzett/másolat. */
function cp_imp_thread_parts($tid, $first = '') {
	global $wpdb;
	$out = $first !== '' ? array(strtolower($first)) : array();
	foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT from_email, to_list, cc_list FROM ' . cp_mail_t('messages') . ' WHERE thread_id = %d ORDER BY sent_at DESC LIMIT 40', $tid), ARRAY_A) as $m) {
		if (!preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $m['from_email'] . ' ' . $m['to_list'] . ' ' . $m['cc_list'], $mm)) { continue; }
		foreach ($mm[0] as $e) { $e = strtolower($e); if (!cp_imp_ours($e) && !in_array($e, $out, true)) { $out[] = $e; } }
	}
	return array_slice($out, 0, 15);
}
function cp_imp_deal_manual($tid) {
	global $wpdb;
	return (bool) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_mail_t('events') . " WHERE thread_id = %d AND kind = 'deal'", $tid))
		|| (string) $wpdb->get_var($wpdb->prepare('SELECT src FROM ' . cp_mail_t('threads') . ' WHERE id = %d', $tid)) === 'panel';
}
function cp_imp_relink($tid, $email, $at) {
	if (!function_exists('cp_crm_t')) { return; }
	global $wpdb;
	$cur = (int) $wpdb->get_var($wpdb->prepare('SELECT deal_id FROM ' . cp_mail_t('threads') . ' WHERE id = %d', $tid));
	if ($cur) {
		/* v2.9.34: mint a Salesmate-ben – a dealen lévő szál a dealen marad (lezárt dealről sem visszük át másikra);
		   csak a törölt dealen ülő vagy deal nélküli szálhoz keresünk dealt */
		$d = $wpdb->get_row($wpdb->prepare('SELECT status, deleted_at, closed_at, event_d FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $cur), ARRAY_A);
		/* v2.9.52 kivétel: visszatérő ügyfél új megkeresése – a szál a régi, lezárt dealhez került, mert az új deal még nem
		   létezett; ha a szál a régi deal lezárása (rendezvénye) UTÁN indult, és azóta jött egy nyitott deal, oda kerül */
		$late = false;
		if ($d && empty($d['deleted_at']) && $d['status'] !== 'Open') {
			$tc = (string) $wpdb->get_var($wpdb->prepare('SELECT created_at FROM ' . cp_mail_t('threads') . ' WHERE id = %d', $tid));
			$end = !empty($d['closed_at']) ? (string) $d['closed_at'] : (!empty($d['event_d']) ? $d['event_d'] . ' 23:59:59' : '');
			$sm = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_mail_t('events') . " WHERE thread_id = %d AND kind = 'autodeal' AND text LIKE %s", $tid, 'Deal a Salesmate szerint:%'));
			$late = $tc !== '' && $end !== '' && $tc > $end && !$sm;
		}
		if ($d && empty($d['deleted_at']) && !$late) { return; }
	}
	if (cp_imp_deal_manual($tid)) { return; }
	$best = cp_imp_pick_deal($email, $at);
	/* v2.9.62: ha a szál kontaktjához nincs deal, a többi résztvevőhöz keresünk (mint a Salesmate, amely a teljes levelezést nézi) */
	if (!$best) { foreach (cp_imp_thread_parts($tid) as $pe) { if ($pe !== strtolower($email)) { $best = cp_imp_pick_deal($pe, $at); if ($best) { break; } } } }
	if (!$best || $best === $cur) { return; }
	$st = (string) $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $best));
	if (!empty($late) && $st !== 'Open') { return; } /* lezártról csak nyitottra */

	$wpdb->update(cp_mail_t('threads'), array('deal_id' => $best), array('id' => $tid));
	$title = (string) $wpdb->get_var($wpdb->prepare('SELECT title FROM ' . cp_crm_t('deals') . ' WHERE id = %d', $best));
	$wpdb->insert(cp_mail_t('events'), array('thread_id' => $tid, 'kind' => 'autodeal', 'text' => mb_substr(($cur ? 'Deal automatikusan áthelyezve' . ($st === 'Open' ? ' (az ügyfél nyitott dealje)' : '') . ': ' : 'Deal automatikusan csatolva: ') . ($title !== '' ? $title : '#' . $best), 0, 255), 'author_uid' => 0, 'author' => 'Crazy panel', 'created_at' => gmdate('Y-m-d H:i:s')));
}
/* az utolsó 60 nap (v2.9.63: 30 helyett – a 60 napos szál-szabállyal összhangban) automatikusan kötött szálai: deal nélküli vagy lezárt/törölt dealen ülő → újrakötés (15 percenként) */
function cp_imp_relink_recent() {
	if (!function_exists('cp_crm_t') || get_transient('cp_imp_relink_at')) { return; }
	set_transient('cp_imp_relink_at', 1, 15 * MINUTE_IN_SECONDS);
	global $wpdb;
	$T = cp_mail_t('threads'); $E = cp_mail_t('events'); $D = cp_crm_t('deals');
	$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT t.id, t.contact_email, t.last_at FROM $T t LEFT JOIN $D d ON d.id = t.deal_id WHERE t.last_at > %s AND t.src <> 'panel' AND t.folder NOT IN ('spam', 'promo') AND t.contact_email <> '' AND (t.deal_id IS NULL OR d.id IS NULL OR d.deleted_at IS NOT NULL OR (d.status <> 'Open' AND t.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY))) AND NOT EXISTS (SELECT 1 FROM $E e WHERE e.thread_id = t.id AND e.kind = 'deal') ORDER BY t.last_at DESC LIMIT 300", gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS)), ARRAY_A);
	foreach ($rows as $r) { cp_imp_relink((int) $r['id'], $r['contact_email'], $r['last_at']); }
}

/* egy levél (UID) átvétele; visszaad: 'ok' | 'dup' | 'skip' */
function cp_imp_one($mb, $uid, $sent, $live = false, $folder = '') {
	global $wpdb;
	$o = cp_imp_opts();
	$me = strtolower($o['user']);
	$hraw = (string) imap_fetchheader($mb, $uid, FT_UID);
	$h = imap_rfc822_parse_headers($hraw);
	if (!$h) { return 'skip'; }
	$mid = md5(isset($h->message_id) && $h->message_id !== '' ? trim($h->message_id) : $hraw);
	$M = cp_mail_t('messages'); $T = cp_mail_t('threads');
	if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $M WHERE mid = %s", $mid))) { return 'dup'; }
	$from = cp_imp_addr($h->from ?? array()); $to = cp_imp_addr($h->to ?? array()); $cc = cp_imp_addr($h->cc ?? array());
	$f = $from[0] ?? array('e' => '', 'n' => '');
	$out = $sent || $f['e'] === $me; /* v1.63: a saját címünkről a Beérkezettbe jött levél feladója hamisítható → „ellenőrizetlen” jelölés */
	$kind = $out ? '' : cp_imp_kind($hraw, $f['e']);
	/* v2.9.37: a „noreply” számlázóktól jövő számla nem rendszerüzenet – átvesszük */
	if ($kind === 'skip' && function_exists('cp_mail_inv_subj') && cp_mail_inv_subj(cp_imp_hdr($h->subject ?? '')) && !preg_match('/mailer-daemon|postmaster/i', $f['e'])) { $kind = ''; }
	if ($kind === 'skip') { return 'skip'; }
	$promo = $kind === 'promo';
	$other = $out ? ($to[0] ?? array('e' => '', 'n' => '')) : $f;
	/* v1.98: kimenőnél az első nem saját címzett (a kollégák, saját címeink nem „ügyfelek”) */
	if ($out) { foreach (array_merge($to, $cc) as $a) { if (!cp_imp_ours($a['e'])) { $other = $a; break; } } }
	/* v1.98: Reply-To – ha egy rendszer (űrlap, foglaló) a saját címéről küld, de a válasz az ügyfélnek megy */
	$rt = cp_imp_addr($h->reply_to ?? array());
	$rto = '';
	if (!$out && count($rt) === 1 && $rt[0]['e'] !== $f['e'] && !cp_imp_ours($rt[0]['e'])) { $rto = $rt[0]['e']; $other = array('e' => $rt[0]['e'], 'n' => $rt[0]['n'] !== '' ? $rt[0]['n'] : ''); }
	if ($other['e'] === '' || $other['e'] === $me) { return 'skip'; }
	$subj = cp_imp_hdr($h->subject ?? '');
	$ts = isset($h->date) ? (int) strtotime($h->date) : 0;
	if (!$ts || $ts > time() + 600) { $ts = time(); } /* v2.9.21: jövőbe datált levél (rossz órájú feladó, spam) ne ragadjon a lista tetejére */
	$at = gmdate('Y-m-d H:i:s', $ts);
	$st = imap_fetchstructure($mb, $uid, FT_UID);
	$acc = array('att' => array());
	if ($st) { cp_imp_walk($mb, $uid, $st, '', $acc); }
	$text = cp_imp_clean_text($acc['plain'] ?? '', $acc['html'] ?? null);
	/* v1.98: kolléga (saját domain) továbbította az ügyfél levelét → az eredeti feladó az „ügyfél”, neki megy a válasz */
	if (!$out && $rto === '' && cp_imp_ours($f['e'])) {
		$orig = cp_imp_fwd_sender((string) ($acc['plain'] ?? ''), $acc['html'] ?? null);
		if ($orig && !cp_imp_ours($orig['e'])) { $other = $orig; $rto = $orig['e']; }
	}
	if ($text === '' && !$acc['att']) { return 'skip'; }
	$body = '<p>' . str_replace("\n", '<br>', esc_html($text)) . '</p>';
	$fmt = function ($l) { return implode(', ', array_map(function ($a) { return $a['n'] !== '' ? $a['n'] . ' <' . $a['e'] . '>' : $a['e']; }, $l)); };
	/* szál: ugyanaz a partner + ugyanaz a (Re:/Fwd: nélküli) tárgy */
	$tkey = md5($other['e'] . '|' . cp_imp_norm_subj($subj));
	/* v1.98: a legutóbbi ilyen szál – ha 60 napnál régebben volt benne levél, új megkeresésnek számít (új szál, friss deal) */
	$t = $wpdb->get_row($wpdb->prepare("SELECT id, last_at, msg_count, folder, followers, deal_id, src FROM $T WHERE tkey = %s ORDER BY id DESC LIMIT 1", $tkey), ARRAY_A);
	if ($t && $t['last_at'] && strtotime($t['last_at'] . ' UTC') < strtotime($at . ' UTC') - CP_IMP_GAP_DAYS * DAY_IN_SECONDS) { $t = null; }
	/* v2.9.23: a feladó új a szálban (pl. a titkárság válaszol, az ügyfél másolatban) – ha a többi résztvevő (címzett, másolat)
	   valamelyikével már van ilyen tárgyú szál, a levél abba kerül, nem nyit újat; a dealt is a résztvevőkön keresztül keressük */
	$parts = array();
	if (!$out) { foreach (array_merge($to, $cc) as $a) { if ($a['e'] !== '' && $a['e'] !== $other['e'] && !cp_imp_ours($a['e']) && !in_array($a['e'], $parts, true)) { $parts[] = $a['e']; } } }
	if (!$t && $parts) {
		$keys = array_map(function ($e) use ($subj) { return md5($e . '|' . cp_imp_norm_subj($subj)); }, array_slice($parts, 0, 10));
		$t2 = $wpdb->get_row("SELECT id, last_at, msg_count, folder, followers, deal_id, src FROM $T WHERE tkey IN ('" . implode("','", array_map('esc_sql', $keys)) . "') ORDER BY last_at DESC LIMIT 1", ARRAY_A);
		if ($t2 && !($t2['last_at'] && strtotime($t2['last_at'] . ' UTC') < strtotime($at . ' UTC') - CP_IMP_GAP_DAYS * DAY_IN_SECONDS)) { $t = $t2; }
	}
	/* v2.9.58: a feldolgozás (IMAP-letöltés) közben nem került-e be mégis – még minden írás előtt (a zár mellett is ellenőrizve) */
	if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $M WHERE mid = %s", $mid))) { return 'dup'; }
	if ($t && $live && !$out) { cp_imp_relink((int) $t['id'], $other['e'], $at); }
	if (!$t) {
		$deal = cp_imp_pick_deal($other['e'], $at);
		if (!$deal) { foreach ($parts as $pe) { $deal = cp_imp_pick_deal($pe, $at); if ($deal) { break; } } }
		$wpdb->insert($T, array('mailbox' => $o['box'], 'subject' => mb_substr($subj, 0, 255), 'contact_name' => mb_substr($other['n'], 0, 190), 'contact_email' => mb_substr($other['e'], 0, 190), 'deal_id' => $deal ?: null,
			'followers' => '', 'tags' => $live ? '' : 'import', 'status' => $live ? 'open' : 'closed', 'folder' => $promo ? 'promo' : ($live ? 'inbox' : 'archive'), 'snippet' => mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 200), 'msg_count' => 0, 'has_attach' => 0,
			'last_at' => $at, 'created_at' => $at, 'tkey' => $tkey, 'src' => 'imap'));
		if (!$wpdb->insert_id) { throw new \Exception('A levélszál mentése nem sikerült: ' . $wpdb->last_error); }
		$t = array('id' => (int) $wpdb->insert_id, 'last_at' => $at, 'msg_count' => 0, 'folder' => $promo ? 'promo' : ($live ? 'inbox' : 'archive'));
	}
	$wpdb->insert($M, array('thread_id' => (int) $t['id'], 'dir' => $out ? 'out' : 'in', 'from_name' => mb_substr($f['n'] !== '' ? $f['n'] : $f['e'], 0, 190), 'from_email' => mb_substr($f['e'], 0, 190),
		'to_list' => mb_substr($fmt($to), 0, 2000), 'cc_list' => mb_substr($fmt($cc), 0, 2000), 'subject' => mb_substr($subj, 0, 255), 'body' => $body, 'attachments' => wp_json_encode(array_slice($acc['att'], 0, 20)),
		'status' => (!$sent && $out) ? (cp_imp_sm_copy($hraw, $me) ? 'Salesmate-ből küldve' : 'ellenőrizetlen') : '', 'sent_at' => $at, 'mid' => $mid) + ($rto !== '' ? array('reply_to' => mb_substr($rto, 0, 190)) : array())
		/* v1.67: az eredeti HTML szűrve (a beágyazott képek a mellékletek sorszámára hivatkozva) */
		+ (function_exists('cp_mail_html_safe') ? array('html' => cp_mail_html_safe($acc['html'] ?? '', array_filter(array_flip(array_map(function ($a) { return (string) ($a['cid'] ?? ''); }, array_slice($acc['att'], 0, 20))), function ($v, $k) { return $k !== ''; }, ARRAY_FILTER_USE_BOTH))) : array()) + (!empty($h->message_id) ? array('msgid' => mb_substr(trim($h->message_id), 0, 255)) : array()) + ($folder !== '' ? array('imap_folder' => mb_substr($folder, 0, 120), 'imap_uid' => (int) $uid) : array()));
	if (!$wpdb->insert_id) { throw new \Exception('A levél mentése nem sikerült: ' . $wpdb->last_error); }
	$msg_id = (int) $wpdb->insert_id;
	$add = 1;
	/* v1.41: az ügyfél levelében idézett saját válaszunk külön kimenő levélként (ugyanaz a válasz csak egyszer) */
	if (!$out && !(int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $M WHERE thread_id = %d AND dir = 'out' AND status <> 'idézetből' AND sent_at <= %s", (int) $t['id'], $at))) {
		/* v1.99.2: csak ha a szálban még nincs meg a saját (panelből vagy az Elküldöttből átvett) levelünk – különben kétszer látszana */
		$qr = cp_imp_quoted_reply($acc['plain'] ?? '', $acc['html'] ?? null);
		if ($qr) {
			$qmid = md5('q|' . $tkey . '|' . mb_strtolower(preg_replace('/\W+/u', '', mb_substr($qr['text'], 0, 400))));
			if (!(int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $M WHERE mid = %s", $qmid))) {
				$qts = $qr['ts'] && $qr['ts'] < $ts ? $qr['ts'] : $ts - 60;
				$wpdb->insert($M, array('thread_id' => (int) $t['id'], 'dir' => 'out', 'from_name' => 'EventCrazy', 'from_email' => $me, 'to_list' => mb_substr($other['n'] !== '' ? $other['n'] . ' <' . $other['e'] . '>' : $other['e'], 0, 2000), 'cc_list' => '',
					'subject' => mb_substr($subj, 0, 255), 'body' => '<p>' . str_replace("\n", '<br>', esc_html($qr['text'])) . '</p>', 'attachments' => '[]', 'status' => 'idézetből', 'sent_at' => gmdate('Y-m-d H:i:s', $qts), 'mid' => $qmid));
				$add++;
			}
		}
	}
	$upd = array();
	if ($at >= $t['last_at']) { $upd['last_at'] = $at; $upd['snippet'] = mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 200); }
	/* folyamatos beérkezés: az ügyfél új levele a szálat visszahozza a Beérkezett mappába, nyitottként */
	if ($live && !$out && !$promo && $t['folder'] !== 'spam') { $upd['status'] = 'open'; $upd['folder'] = 'inbox'; }
	/* v1.99.2: az ügyfél új levele mindig olvasatlanná teszi a szálat – akkor is, ha késve kézbesítették (régebbi dátummal) */
	if ($live && !$out && !$promo) { $upd['seen_at'] = null; }
	if ($acc['att']) { $upd['has_attach'] = 1; }
	/* v2.9.58: a levélszámláló az adatbázisban nő (msg_count + N) – párhuzamos írásnál sem vész el */
	$wpdb->query($wpdb->prepare("UPDATE $T SET msg_count = msg_count + %d WHERE id = %d", $add, (int) $t['id']));
	if ($upd) { $wpdb->update($T, $upd, array('id' => (int) $t['id'])); }
	/* v2.9.47: a Salesmate-ből küldött levél másolata (élő beérkezés) – a felelős az aláírás szerinti kolléga, ha még nincs lefoglalva */
	/* v2.9.52: csak a valódi Salesmate-másolatnál (a saját címről érkező, de nem a Salesmate-ből jött levél feladója hamisítható) */
	if ($live && $out && !$sent && function_exists('cp_claim_from_sm_mail') && cp_imp_sm_copy($hraw, $me)) { cp_claim_from_sm_mail((int) $t['id'], (string) ($acc['plain'] ?? '') !== '' ? (string) $acc['plain'] : $text); }
	/* v2.9.37: bejövő számla → „Számla” címke */
	if (!$out && function_exists('cp_mail_inv_match') && cp_mail_inv_match($subj, $f['e'], $acc['att'])) {
		cp_mail_tag_add((int) $t['id'], CP_MAIL_INV_TAG);
		/* v2.9.38: az új számla PDF-je a Drive számlamappájába (a levélszinkron végén, háttérben) */
		if ($live && function_exists('cp_mdr_enqueue')) { foreach ($acc['att'] as $a) { if (preg_match('/\.pdf$/i', (string) ($a['n'] ?? ''))) { cp_mdr_enqueue($msg_id); break; } } }
	}
	/* v1.86: a szál követői értesítést kapnak az ügyfél új leveléről (csengő; ha be van állítva, Pumble is) – csak friss, élő beérkezésnél */
	/* v1.99.2: a követők mellett a szál felelőse is értesítést kap; a régiség a beérkezés idejéhez mérve (késve kézbesített levélnél is szól) */
	$as = (int) $wpdb->get_var($wpdb->prepare("SELECT assignee_uid FROM $T WHERE id = %d", (int) $t['id']));
	$notif = array_unique(array_filter(array_merge(array_map('intval', explode(',', (string) ($t['followers'] ?? ''))), array($as))));
	if ($live && !$out && !$promo && ($t['folder'] ?? '') !== 'spam' && $notif && $ts > time() - 10 * DAY_IN_SECONDS && function_exists('cp_notify')) {
		foreach ($notif as $fu) {
			cp_notify($fu, 'mail_follow', 'új levelet írt a követett szálba: „' . mb_substr($subj !== '' ? $subj : '(nincs tárgy)', 0, 100) . '”', 'mail:' . (int) $t['id'], $f['n'] !== '' ? $f['n'] : $f['e']);
		}
	}
	return 'ok';
}

/* ------------------------------------------------------------ 2) az import: percenként egy köteg, kb. 45 másodpercig */
add_filter('cron_schedules', function ($s) { $s['cp_minute'] = array('interval' => 60, 'display' => 'Percenként (Crazy import)'); return $s; });
add_action('cp_imp_tick', 'cp_imp_tick');
function cp_imp_tick() {
	$s = cp_imp_state();
	if (empty($s['running'])) { wp_clear_scheduled_hook('cp_imp_tick'); return; }
	if (get_transient('cp_imp_lock')) { return; }
	/* v2.9.58: a v1.41-es „régi formátumú importált levelek törlése” átállás kivéve (rég lefutott, és minden imap-szálat törölt volna) */
	/* v2.9.58: atomi zár – a folyamatos beérkezéssel (↻ gomb, 5 perces ütem) közös, így egyszerre csak egy behúzás fut */
	if (!cp_mail_lock('imp')) { return; }
	set_transient('cp_imp_lock', 1, 120);
	if (function_exists('set_time_limit')) { @set_time_limit(110); }
	/* ha a köteg váratlanul leállna (végzetes hiba), az ok bekerül az állapotba */
	register_shutdown_function(function () {
		$e = error_get_last();
		if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) { $st = cp_imp_state(); $st['fatal'] = mb_substr($e['message'] . ' @' . basename($e['file']) . ':' . $e['line'], 0, 300); cp_imp_state_set($st); delete_transient('cp_imp_lock'); }
	});
	$t0 = time();
	$mb = cp_imp_open();
	if (is_wp_error($mb)) { $s['err'] = $mb->get_error_message(); $s['errn'] = (int) ($s['errn'] ?? 0) + 1; if ($s['errn'] >= 5) { $s['running'] = 0; } cp_imp_state_set($s); delete_transient('cp_imp_lock'); cp_mail_unlock('imp'); return; }
	$s['errn'] = 0; $s['err'] = '';
	foreach ($s['folders'] as $i => &$f) {
		if (!empty($f['done'])) { continue; }
		if (!@imap_reopen($mb, cp_imp_ref() . $f['raw'], OP_READONLY)) { $f['done'] = 1; $f['err'] = 'nem nyitható'; continue; }
		if (!isset($f['uids'])) { $f['uids'] = array_values(array_map('intval', (array) imap_search($mb, 'SINCE "' . $s['since'] . '"', SE_UID))); $f['pos'] = 0; $f['total'] = count($f['uids']); }
		$k = 0;
		while ($f['pos'] < $f['total'] && time() - $t0 < 45) {
			try { $r = cp_imp_one($mb, $f['uids'][$f['pos']], !empty($f['sent']), false, (string) ($f['raw'] ?? '')); }
			catch (\Throwable $e) { $r = 'err'; $s['lasterr'] = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 200); }
			$s['cnt'][$r] = (int) ($s['cnt'][$r] ?? 0) + 1;
			$f['pos']++;
			/* menet közben is mentjük, hogy egy váratlan leállásnál se vesszen el a haladás */
			if (++$k % 25 === 0) { $s['last'] = time(); cp_imp_state_set($s); }
		}
		imap_errors(); imap_alerts();
		if ($f['pos'] >= $f['total']) { $f['done'] = 1; unset($f['uids']); }
		if (time() - $t0 >= 45) { break; }
	}
	unset($f);
	imap_close($mb);
	if (!array_filter($s['folders'], function ($f) { return empty($f['done']); })) { $s['running'] = 0; $s['finished'] = time(); wp_clear_scheduled_hook('cp_imp_tick'); }
	$s['last'] = time();
	cp_imp_state_set($s);
	delete_transient('cp_imp_lock');
	cp_mail_unlock('imp');
}

function cp_imp_start($folders) {
	$sv = get_option('cp_imp_survey', array());
	$o = cp_imp_opts();
	$list = array();
	foreach ((array) ($sv['folders'] ?? array()) as $f) { if (in_array($f['raw'], $folders, true)) { $list[] = array('raw' => $f['raw'], 'name' => $f['name'], 'sent' => !empty($f['sent'])); } }
	if (!$list) { return 'Válassz legalább egy mappát.'; }
	cp_imp_state_set(array('v' => 2, 'running' => 1, 'started' => time(), 'since' => gmdate('d-M-Y', strtotime('-' . max(1, (int) $o['years']) . ' years')), 'folders' => $list, 'cnt' => array()));
	if (!wp_next_scheduled('cp_imp_tick')) { wp_schedule_event(time() + 5, 'cp_minute', 'cp_imp_tick'); }
	return 'Az import elindult – percenként halad, nyugodtan bezárhatod az oldalt.';
}

function cp_imp_quoted_count() { global $wpdb; return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . cp_mail_t('messages') . " WHERE status = 'idézetből'"); }

/* ------------------------------------------------------------ beállítás: Beállítások → Crazy levelezés */
add_action('admin_menu', function () { add_options_page('Crazy levelezés', 'Crazy levelezés', 'manage_options', 'cp-mail-import', 'cp_imp_admin_page'); });

function cp_imp_admin_page() {
	if (!current_user_can('manage_options')) { return; }
	$msg = '';
	if (isset($_POST['cp_imp_do'])) {
		check_admin_referer('cp_imp_do');
		$do = sanitize_key(wp_unslash($_POST['cp_imp_do']));
		if ($do === 'save' || $do === 'survey') {
			$o = cp_imp_opts();
			$o['host'] = preg_replace('/[^a-z0-9.\-]/i', '', (string) wp_unslash($_POST['host'] ?? $o['host'])) ?: 'mail.eventcrazy.hu';
			$o['port'] = (int) ($_POST['port'] ?? 993) ?: 993;
			$o['user'] = sanitize_email(wp_unslash($_POST['user'] ?? $o['user'])) ?: $o['user'];
			$o['years'] = max(1, min(10, (int) ($_POST['years'] ?? 2)));
			update_option('cp_imp_opts', $o, false);
			$pw = (string) wp_unslash($_POST['pw'] ?? '');
			if (!empty($_POST['pw_clear'])) { cp_st_secret_set('imap_pw', ''); $msg .= 'Jelszó törölve. '; }
			elseif ($pw !== '') { cp_st_secret_set('imap_pw', $pw); $msg .= 'Jelszó elmentve. '; }
			$msg .= 'Beállítások mentve. ';
			if ($do === 'survey') {
				$r = cp_imp_survey();
				$msg .= is_wp_error($r) ? 'Felmérés: ' . $r->get_error_message() : 'A felmérés kész – lent látod mappánként, hány levél van az időszakban. Még semmi nem lett átmásolva.';
			}
		} elseif ($do === 'start') {
			$msg = cp_imp_start(array_map('strval', (array) wp_unslash($_POST['folders'] ?? array())));
		} elseif ($do === 'sync_on' || $do === 'sync_off') {
			update_option('cp_imp_sync_on', $do === 'sync_on' ? '1' : '0', true); /* v2.9.58: automatikusan betöltve */
			if ($do === 'sync_on') { if (!wp_next_scheduled('cp_imp_sync')) { wp_schedule_event(time() + 30, 'cp_5min', 'cp_imp_sync'); } $msg = 'A folyamatos beérkezés bekapcsolva – 5 percenként, csak olvasva.'; }
			else { wp_clear_scheduled_hook('cp_imp_sync'); $msg = 'A folyamatos beérkezés kikapcsolva.'; }
		} elseif ($do === 'tpl') {
			$msg = cp_imp_templates((string) wp_unslash($_POST['tpl_json'] ?? ''));
		} elseif ($do === 'stop') {
			$s = cp_imp_state(); $s['running'] = 0; cp_imp_state_set($s); wp_clear_scheduled_hook('cp_imp_tick'); $msg = 'Az import leállítva (később folytatható: újraindításkor a már átvett leveleket átugorja).';
		}
	}
	$o = cp_imp_opts(); $has = cp_imp_pw() !== ''; $sv = get_option('cp_imp_survey', array()); $s = cp_imp_state();
	echo '<div class="wrap"><h1>Crazy levelezés – korábbi levelek áthozatala</h1>';
	if ($msg !== '') { echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>'; }
	echo '<p>A postafiókból <b>csak olvas</b>: a szerveren semmi nem változik (nem töröl, nem helyez át, nem jelöl olvasottnak). Csak a levelek szövege jön át, a mellékletekből csak a fájlnév; a hírlevelek, értesítők és automatikus levelek kimaradnak. Az átvett levelek a panel Levelezés fülén az <b>Archív</b> mappában jelennek meg lezárt szálakként (címke: import), és ebből tanul az AI válaszvarázsló.</p>';
	if (!function_exists('imap_open')) { echo '<div class="notice notice-error"><p>A szerveren nincs IMAP-bővítmény.</p></div>'; }
	echo '<form method="post">' . wp_nonce_field('cp_imp_do', '_wpnonce', true, false) . '<table class="form-table" style="max-width:900px">';
	echo '<tr><th>Levelezőszerver</th><td><input type="text" name="host" value="' . esc_attr($o['host']) . '" class="regular-text"> port <input type="number" name="port" value="' . (int) $o['port'] . '" style="width:80px"> <span class="description">(IMAP, SSL)</span></td></tr>';
	echo '<tr><th>Felhasználónév</th><td><input type="email" name="user" value="' . esc_attr($o['user']) . '" class="regular-text"></td></tr>';
	echo '<tr><th>Jelszó</th><td><input type="password" name="pw" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr($has ? 'beállítva – csak cseréhez írd be' : 'a postafiók jelszava') . '">' . ($has ? ' <label><input type="checkbox" name="pw_clear" value="1"> törlés</label>' : '') . '<p class="description">Titkosítva tárolódik. Az import végén törölheted.</p></td></tr>';
	echo '<tr><th>Időszak</th><td>az utolsó <input type="number" name="years" min="1" max="10" value="' . (int) $o['years'] . '" style="width:60px"> év</td></tr>';
	echo '</table><p><button class="button" name="cp_imp_do" value="save">Mentés</button> <button class="button button-primary" name="cp_imp_do" value="survey">Mentés és felmérés (csak számol)</button></p></form>';
	if (!empty($sv['folders'])) {
		echo '<h2>Felmérés – ' . esc_html(wp_date('Y.m.d. H:i', (int) $sv['at'])) . ' (' . esc_html($sv['since']) . ' óta)</h2><form method="post">' . wp_nonce_field('cp_imp_do', '_wpnonce', true, false) . '<input type="hidden" name="cp_imp_do" value="start">';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th></th><th>Mappa</th><th>Az időszakban</th><th>Évenként</th><th>Teljes méret (mellékletekkel)</th></tr></thead><tbody>';
		$tot = 0;
		foreach ($sv['folders'] as $f) {
			$def = !$f['skip'] && ($f['raw'] === 'INBOX' || $f['sent']);
			$yr = array(); foreach ($f['years'] as $y => $n) { $yr[] = $y . ': ' . $n; }
			if ($def) { $tot += $f['n']; }
			echo '<tr><td><input type="checkbox" name="folders[]" value="' . esc_attr($f['raw']) . '"' . checked($def, true, false) . '></td><td>' . esc_html($f['name']) . ($f['sent'] ? ' <small>(elküldött)</small>' : '') . ($f['skip'] ? ' <small>(kihagyható)</small>' : '') . '</td><td>' . (int) $f['n'] . ' / ' . (int) $f['all'] . '</td><td><small>' . esc_html(implode(' · ', $yr)) . '</small></td><td>' . esc_html($f['mb']) . ' MB</td></tr>';
		}
		echo '</tbody></table><p>A bejelölt mappákban kb. <b>' . (int) $tot . '</b> levél; csak szövegként várhatóan kb. <b>' . esc_html(max(1, round($tot * 4 / 1024))) . ' MB</b> lesz a panelben.</p>';
		echo '<p><button class="button button-primary"' . (!empty($s['running']) ? ' disabled' : '') . '>Import indítása</button></p></form>';
	}
	if ($s) {
		echo '<h2>Import állapota</h2><p>' . (!empty($s['running']) ? '<b>Fut</b> (percenként egy köteg)' : (!empty($s['finished']) ? '<b>Kész</b> – ' . esc_html(wp_date('Y.m.d. H:i', (int) $s['finished'])) : 'Áll')) . '. Átvéve: <b>' . (int) ($s['cnt']['ok'] ?? 0) . '</b> · már megvolt: ' . (int) ($s['cnt']['dup'] ?? 0) . ' · kihagyva (hírlevél, értesítő, üres): ' . (int) ($s['cnt']['skip'] ?? 0) . (!empty($s['cnt']['err']) ? ' · hibás (átugorva): ' . (int) $s['cnt']['err'] : '') . ' · ebből a mi válaszaink (idézetből): ' . (int) cp_imp_quoted_count() . (!empty($s['err']) ? ' · <span style="color:#b32d2e">hiba: ' . esc_html($s['err']) . '</span>' : '') . '</p><ul>';
		foreach ((array) ($s['folders'] ?? array()) as $f) { echo '<li>' . esc_html($f['name']) . ': ' . (isset($f['total']) ? (int) $f['pos'] . ' / ' . (int) $f['total'] : (!empty($f['done']) ? 'kész' : 'várakozik')) . (!empty($f['done']) ? ' ✓' : '') . '</li>'; }
		echo '</ul>';
		if (!empty($s['running'])) { echo '<form method="post">' . wp_nonce_field('cp_imp_do', '_wpnonce', true, false) . '<button class="button" name="cp_imp_do" value="stop">Import leállítása</button></form><p class="description">Az oldal frissítésével látod a haladást.</p>'; }
	}
	$sy = get_option('cp_imp_sync', array()); $on = get_option('cp_imp_sync_on') === '1';
	echo '<h2>Folyamatos beérkezés (csak olvasva)</h2><p>Bekapcsolva 5 percenként megnézi a Beérkezett mappát, és az új leveleket a panel Levelezés fülére is beteszi (a deal-hez kötve). Csak olvas: nem töröl, nem helyez át, nem jelöl olvasottnak – a Salesmate Team Inbox ugyanúgy megkapja és kezeli a leveleket. Válaszolni továbbra is a Salesmate-ben kell.</p>';
	echo '<p>Állapot: <b>' . ($on ? 'bekapcsolva' : 'kikapcsolva') . '</b>' . (!empty($sy['last']) ? ' · utolsó ellenőrzés: ' . esc_html(wp_date('Y.m.d. H:i', (int) $sy['last'])) . ' · átvéve összesen: ' . (int) ($sy['n'] ?? 0) . ' levél' : '') . (!empty($sy['err']) ? ' · <span style="color:#b32d2e">hiba: ' . esc_html($sy['err']) . '</span>' : '') . '</p>';
	echo '<form method="post">' . wp_nonce_field('cp_imp_do', '_wpnonce', true, false) . '<button class="button ' . ($on ? '' : 'button-primary') . '" name="cp_imp_do" value="' . ($on ? 'sync_off' : 'sync_on') . '">' . ($on ? 'Kikapcsolás' : 'Bekapcsolás') . '</button></form>';
	echo '<h2>Salesmate-sablonok betöltése</h2><p>Illeszd be a Chrome-bővítményben futó Claude által összegyűjtött sablonokat (JSON: <code>[{"folder":"…","name":"…","subject":"…","body_html":"…"}, …]</code>). Az azonos mappában azonos nevű sablon frissül, a többi újként kerül be. A Salesmate-et nem érinti.</p>';
	echo '<form method="post">' . wp_nonce_field('cp_imp_do', '_wpnonce', true, false) . '<input type="hidden" name="cp_imp_do" value="tpl"><textarea name="tpl_json" rows="10" class="large-text code" placeholder="[ { &quot;folder&quot;: &quot;Ajánlatok&quot;, &quot;name&quot;: &quot;…&quot;, &quot;subject&quot;: &quot;…&quot;, &quot;body_html&quot;: &quot;<p>…</p>&quot; } ]"></textarea><p><button class="button button-primary">Sablonok betöltése</button></p></form>';
	do_action('cp_imp_admin_after');
	echo '</div>';
}

/* a Salesmate-ből (böngészőben) kigyűjtött sablonok betöltése a panel sablontáblájába */
function cp_imp_templates($raw) {
	global $wpdb;
	if (!function_exists('cp_mail_db_ok') || !cp_mail_db_ok()) { return 'A levelezés táblái még nem készek.'; }
	$raw = trim($raw);
	if (preg_match('/```(?:json)?\s*(.*?)```/s', $raw, $m)) { $raw = trim($m[1]); }
	$j = json_decode($raw, true);
	if (is_array($j) && isset($j['templates'])) { $j = $j['templates']; }
	if (!is_array($j) || !$j) { return 'Nem érvényes JSON-lista (hiba: ' . json_last_error_msg() . ').'; }
	$T = cp_mail_t(); $new = 0; $upd = 0; $bad = 0;
	foreach (array_slice($j, 0, 500) as $x) {
		if (!is_array($x)) { $bad++; continue; }
		$name = mb_substr(sanitize_text_field((string) ($x['name'] ?? '')), 0, 190);
		$cat = mb_substr(sanitize_text_field((string) ($x['folder'] ?? $x['category'] ?? '')), 0, 80);
		$subj = mb_substr(sanitize_text_field((string) ($x['subject'] ?? '')), 0, 255);
		$body = (string) ($x['body_html'] ?? $x['body'] ?? '');
		if (!preg_match('/<[a-z][^>]*>/i', $body)) { $body = '<p>' . str_replace("\n", '<br>', esc_html(str_replace("\r", '', $body))) . '</p>'; }
		$body = cp_mail_clean($body);
		if ($name === '' || trim(wp_strip_all_tags($body)) === '') { $bad++; continue; }
		$row = array('name' => $name, 'category' => $cat, 'subject' => $subj, 'body' => $body, 'updated_by' => 'Salesmate-import', 'updated_at' => current_time('mysql', true));
		$id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $T WHERE name = %s AND category = %s AND deleted_at IS NULL", $name, $cat));
		if ($id) { $wpdb->update($T, $row, array('id' => $id)); $upd++; }
		else { $wpdb->insert($T, $row + array('created_at' => current_time('mysql', true))); $new++; }
	}
	return 'Sablonok: ' . $new . ' új, ' . $upd . ' frissítve' . ($bad ? ', ' . $bad . ' hibás / üres kihagyva' : '') . '. A panel Levelezés → Sablonok fülén látod őket.';
}


/* ------------------------------------------------------------ v1.45: folyamatos beérkezés (csak olvasva, 5 percenként)
 * A Beérkezett mappa új levelei (UID alapján a legutóbbi óta) a Levelezés fülre, nyitott szálként. A már átvett levelet
 * (Message-ID alapján) kihagyja. Első futáskor az utolsó 3 napot nézi át, utána csak az újakat. */
add_filter('cron_schedules', function ($s) { $s['cp_5min'] = array('interval' => 300, 'display' => '5 percenként (Crazy levelezés)'); return $s; });
add_action('init', function () {
	if (get_option('cp_imp_sync_on') === '1' && !wp_next_scheduled('cp_imp_sync')) { wp_schedule_event(time() + 60, 'cp_5min', 'cp_imp_sync'); }
});
add_action('cp_imp_sync', 'cp_imp_sync_run');
/* v2.9.58: $full = false (a ↻ gomb): csak a levelek behúzása; az újrakötés, a számlacímkék és a Drive-sor az 5 perces ütemé */
function cp_imp_sync_run($full = true) {
	if (get_option('cp_imp_sync_on') !== '1') { wp_clear_scheduled_hook('cp_imp_sync'); return; }
	$st = cp_imp_state();
	if (!empty($st['running']) || get_transient('cp_imp_lock') || get_transient('cp_imp_sync_lock')) { return; }
	/* v2.9.58: atomi zár (a ↻ gomb és az 5 perces ütem egyszerre nem húzhatja be ugyanazt a levelet); a tranziens a kijelzéshez marad */
	if (!cp_mail_lock('imp')) { return; }
	set_transient('cp_imp_sync_lock', 1, 240);
	if (function_exists('set_time_limit')) { @set_time_limit(150); }
	$sy = get_option('cp_imp_sync', array());
	if (!is_array($sy)) { $sy = array(); }
	$t0 = time();
	try {
		$mb = cp_imp_open('INBOX');
		if (is_wp_error($mb)) { throw new \Exception($mb->get_error_message()); }
		$info = imap_status($mb, cp_imp_ref() . 'INBOX', SA_UIDVALIDITY | SA_UIDNEXT);
		$uv = $info ? (int) $info->uidvalidity : 0;
		$first = empty($sy['uid']) || (int) ($sy['uv'] ?? 0) !== $uv;
		if ($first) {
			/* első futás (vagy a szerver újraszámozta a leveleket): az utolsó 3 nap, a már meglévőket kihagyja */
			$uids = array_map('intval', (array) imap_search($mb, 'SINCE "' . gmdate('d-M-Y', time() - 3 * DAY_IN_SECONDS) . '"', SE_UID));
		} else {
			$uids = array();
			foreach ((array) imap_fetch_overview($mb, ((int) $sy['uid'] + 1) . ':*', FT_UID) as $ov) { if ((int) $ov->uid > (int) $sy['uid']) { $uids[] = (int) $ov->uid; } }
		}
		sort($uids);
		/* v2.9.20: ha az előző futás egy levélen halt el (memória, időkorlát), az hibának számít; 3 elakadás után kihagyjuk */
		$skipped_now = 0;
		if (!empty($sy['cur'])) {
			$c = (int) $sy['cur']; unset($sy['cur']);
			$ec = (int) ($sy['errs'][$c] ?? 0) + 1;
			if ($ec >= 3) { $sy['skipped'][] = $c; $sy['skipped'] = array_slice($sy['skipped'], -50); unset($sy['errs'][$c]); $sy['uid'] = max((int) ($sy['uid'] ?? 0), $c); $uids = array_values(array_filter($uids, function ($u) use ($c) { return $u > $c; })); $sy['err'] = 'Egy levél (UID ' . $c . ') feldolgozása háromszor elakadt, kihagytam.'; $skipped_now = 1; }
			else { $sy['errs'][$c] = $ec; }
		}
		$max = (int) ($sy['uid'] ?? 0);
		$done = 0; $todo = array_slice($uids, 0, 300);
		foreach ($todo as $uid) {
			if (time() - $t0 > 100) { break; }
			$sy['cur'] = $uid; $sy['uid'] = $max; $sy['uv'] = (int) $uv; update_option('cp_imp_sync', $sy, false); /* levelenként mentett állás */
			try { $r = cp_imp_one($mb, $uid, false, true, 'INBOX'); } catch (\Throwable $e) { $r = 'err'; }
			if ($r === 'ok') { $sy['n'] = (int) ($sy['n'] ?? 0) + 1; }
			/* v2.0.1: hibás levél: újrapróbálás a következő körökben (legfeljebb 3×), utána kihagyva és megjegyezve */
			if ($r === 'err') {
				$ec = isset($sy['errs'][$uid]) ? (int) $sy['errs'][$uid] + 1 : 1;
				if ($ec < 3) { $sy['errs'][$uid] = $ec; break; }
				$sy['skipped'][] = $uid; $sy['skipped'] = array_slice($sy['skipped'], -50); unset($sy['errs'][$uid]);
			}
			$max = max($max, $uid); $done++;
			/* 25 levelenként az állás mentése (ha a futás félbeszakad, ne kezdje elölről) */
			if ($done % 25 === 0) { $sy['uid'] = $max; $sy['uv'] = (int) $uv; update_option('cp_imp_sync', $sy, false); }
		}
		unset($sy['cur']);
		imap_errors(); imap_alerts();
		imap_close($mb);
		if ($first && count($uids) <= 300 && $done === count($todo) && $info) { $max = max($max, (int) $info->uidnext - 1); }
		$sy['uid'] = $max; $sy['uv'] = $uv; if (empty($skipped_now)) { $sy['err'] = ''; }
	} catch (\Throwable $e) {
		unset($sy['cur']); /* kapcsolati hiba – nem a levél hibája */
		$sy['err'] = mb_substr($e->getMessage(), 0, 200);
	}
	$sy['last'] = time();
	update_option('cp_imp_sync', $sy, false);
	delete_transient('cp_imp_sync_lock');
	cp_mail_unlock('imp');
	if ($full === false) { return; } /* (a WP-horog üres szöveget is adhat – az teljes futás) */
	try { cp_imp_relink_recent(); } catch (\Throwable $e) { /* a következő körben újra */ }
	/* v2.9.58: az egyszeri visszamenőleges számlacímkézés (cp_mail_inv_backfill) rég lefutott – kivéve */
	if (function_exists('cp_mdr_run')) { try { cp_mdr_run(); } catch (\Throwable $e) { delete_transient('cp_mdr_lock'); } }
}

/* v1.97: „Letöltés most” – a Levelezés ↻ gombja azonnal lekéri az új leveleket (nem várja meg az 5 perces ütemet).
 * Ugyanaz a csak olvasó szinkron, mint az időzített; legfeljebb 20 másodpercenként egyszer. */
add_action('wp_ajax_nopriv_cp_mail_fetch_now', 'cp_imp_ajax_fetch_now');
add_action('wp_ajax_cp_mail_fetch_now', 'cp_imp_ajax_fetch_now');
function cp_imp_ajax_fetch_now() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if (get_option('cp_imp_sync_on') !== '1') { cp_el_err('A folyamatos beérkezés nincs bekapcsolva (Beállítások → Levelezés).'); }
	$sy0 = get_option('cp_imp_sync', array());
	$n0 = (int) ($sy0['n'] ?? 0);
	if (!get_transient('cp_imp_fetch_rl') && !get_transient('cp_imp_sync_lock')) {
		set_transient('cp_imp_fetch_rl', 1, 20);
		cp_imp_sync_run(false); /* v2.9.58: a kattintásban csak a levelek behúzása (a háttérmunka az 5 perces ütemben) */
	}
	$sy = get_option('cp_imp_sync', array());
	if (!empty($sy['err'])) { cp_el_err('A postafiók most nem érhető el: ' . $sy['err'], 502); }
	wp_send_json_success(array('new' => max(0, (int) ($sy['n'] ?? 0) - $n0), 'last' => (int) ($sy['last'] ?? 0)));
}

/* v2.5.2: a Salesmate-ből (SMTP-n, a saját fiókunkkal) küldött levél másolata (Auto BCC) – a mi szerverünk hitelesített
 * küldőként jegyezte fel (cPanel/Exim fejléc), és a levél a Salesmate-ből jött → nem „ellenőrizetlen”, hanem „Salesmate-ből küldve” */
function cp_imp_sm_copy($hraw, $me) {
	$q = preg_quote($me, '/');
	$auth = preg_match('/^X-(Authenticated-Sender|Get-Message-Sender-Via):[^\n]*' . $q . '/mi', $hraw) || preg_match('/authenticated_id:\s*' . $q . '/i', $hraw);
	if ($auth && preg_match('/salesmate/i', $hraw)) { return true; }
	/* v2.9.33: a team inbox Auto BCC-je a Salesmate saját levelezőszerveréről jön (nem a mi szerverünkön át) –
	   a mi szerverünk által rögzített Received sor a Salesmate gépét nevezi meg, és a levél Salesmate-es azonosítójú */
	$h1 = preg_replace('/\r?\n[ \t]+/', ' ', $hraw);
	return (bool) preg_match('/^X-Mailer:\s*Salesmate\.io\b/mi', $h1) && (bool) preg_match('/^Received:\s*from\s[^\n]*helo=[\w.-]*\.salesmate\.io\b/mi', $h1) && (bool) preg_match('/^Message-Id:\s*<[^>]*@salesmate\.io>/mi', $h1);
}
