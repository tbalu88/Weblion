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
		$out .= cp_imp_conv($p->text, $cs);
	}
	return trim($out);
}
function cp_imp_decode($data, $enc, $charset) {
	if ($enc === 3) { $data = base64_decode($data); } elseif ($enc === 4) { $data = quoted_printable_decode($data); }
	$data = cp_imp_conv($data, $charset);
	if (!mb_check_encoding($data, 'UTF-8')) { $data = cp_imp_conv($data, 'ISO-8859-2'); }
	return $data;
}
function cp_imp_param($part, $name) {
	foreach (array('parameters', 'dparameters') as $k) { if (!empty($part->$k)) { foreach ($part->$k as $p) { if (strtolower($p->attribute) === $name || strtolower($p->attribute) === $name . '*') { return cp_imp_hdr($p->value); } } } }
	return '';
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
	if ($fname !== '' && ($disp === 'attachment' || $type !== 0)) { $acc['att'][] = array('n' => mb_substr($fname, 0, 120)); return; }
	if ($type === 0 && ($sub === 'plain' || $sub === 'html') && !isset($acc[$sub])) {
		$raw = imap_fetchbody($mb, $uid, $num === '' ? '1' : $num, FT_UID | FT_PEEK);
		if (mb_strlen((string) $raw) > 600000) { $raw = substr($raw, 0, 600000); }
		$acc[$sub] = cp_imp_decode((string) $raw, (int) $part->encoding, cp_imp_param($part, 'charset'));
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
function cp_imp_is_bulk($hraw, $from) {
	if (preg_match('/^(list-unsubscribe|list-id|auto-submitted:\s*auto|precedence:\s*(bulk|list|junk)|x-auto-response-suppress|feedback-id)/mi', $hraw)) { return true; }
	return (bool) preg_match('/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|notifications?|news(letter)?|info@facebookmail|.*@(facebookmail|linkedin|google|accounts\.google|mailchimp|mailerlite|salesmate))/i', $from);
}

/* egy levél (UID) átvétele; visszaad: 'ok' | 'dup' | 'skip' */
function cp_imp_one($mb, $uid, $sent) {
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
	$out = $sent || $f['e'] === $me;
	if (!$out && cp_imp_is_bulk($hraw, $f['e'])) { return 'skip'; }
	$other = $out ? ($to[0] ?? array('e' => '', 'n' => '')) : $f;
	if ($other['e'] === '' || $other['e'] === $me) { return 'skip'; }
	$subj = cp_imp_hdr($h->subject ?? '');
	$ts = isset($h->date) ? (int) strtotime($h->date) : 0;
	if (!$ts) { $ts = time(); }
	$at = gmdate('Y-m-d H:i:s', $ts);
	$st = imap_fetchstructure($mb, $uid, FT_UID);
	$acc = array('att' => array());
	if ($st) { cp_imp_walk($mb, $uid, $st, '', $acc); }
	$text = cp_imp_clean_text($acc['plain'] ?? '', $acc['html'] ?? null);
	if ($text === '' && !$acc['att']) { return 'skip'; }
	$body = '<p>' . str_replace("\n", '<br>', esc_html($text)) . '</p>';
	$fmt = function ($l) { return implode(', ', array_map(function ($a) { return $a['n'] !== '' ? $a['n'] . ' <' . $a['e'] . '>' : $a['e']; }, $l)); };
	/* szál: ugyanaz a partner + ugyanaz a (Re:/Fwd: nélküli) tárgy */
	$tkey = md5($other['e'] . '|' . cp_imp_norm_subj($subj));
	$t = $wpdb->get_row($wpdb->prepare("SELECT id, last_at, msg_count FROM $T WHERE tkey = %s", $tkey), ARRAY_A);
	if (!$t) {
		$deal = 0;
		if (function_exists('cp_crm_t')) {
			$deal = (int) $wpdb->get_var($wpdb->prepare('SELECT d.id FROM ' . cp_crm_t('deals') . ' d JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id WHERE c.email = %s AND d.deleted_at IS NULL ORDER BY ABS(DATEDIFF(d.created_at, %s)) LIMIT 1', $other['e'], $at));
		}
		$wpdb->insert($T, array('mailbox' => $o['box'], 'subject' => mb_substr($subj, 0, 255), 'contact_name' => mb_substr($other['n'], 0, 190), 'contact_email' => mb_substr($other['e'], 0, 190), 'deal_id' => $deal ?: null,
			'followers' => '', 'tags' => 'import', 'status' => 'closed', 'folder' => 'archive', 'snippet' => mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 200), 'msg_count' => 0, 'has_attach' => 0,
			'last_at' => $at, 'created_at' => $at, 'tkey' => $tkey, 'src' => 'imap'));
		$t = array('id' => (int) $wpdb->insert_id, 'last_at' => $at, 'msg_count' => 0);
	}
	$wpdb->insert($M, array('thread_id' => (int) $t['id'], 'dir' => $out ? 'out' : 'in', 'from_name' => mb_substr($f['n'] !== '' ? $f['n'] : $f['e'], 0, 190), 'from_email' => mb_substr($f['e'], 0, 190),
		'to_list' => mb_substr($fmt($to), 0, 2000), 'cc_list' => mb_substr($fmt($cc), 0, 2000), 'subject' => mb_substr($subj, 0, 255), 'body' => $body, 'attachments' => wp_json_encode(array_slice($acc['att'], 0, 20)),
		'status' => '', 'sent_at' => $at, 'mid' => $mid));
	$add = 1;
	/* v1.41: az ügyfél levelében idézett saját válaszunk külön kimenő levélként (ugyanaz a válasz csak egyszer) */
	if (!$out) {
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
	$upd = array('msg_count' => (int) $t['msg_count'] + $add);
	if ($at >= $t['last_at']) { $upd['last_at'] = $at; $upd['snippet'] = mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 200); }
	if ($acc['att']) { $upd['has_attach'] = 1; }
	$wpdb->update($T, $upd, array('id' => (int) $t['id']));
	return 'ok';
}

/* ------------------------------------------------------------ 2) az import: percenként egy köteg, kb. 45 másodpercig */
add_filter('cron_schedules', function ($s) { $s['cp_minute'] = array('interval' => 60, 'display' => 'Percenként (Crazy import)'); return $s; });
add_action('cp_imp_tick', 'cp_imp_tick');
function cp_imp_tick() {
	$s = cp_imp_state();
	if (empty($s['running'])) { wp_clear_scheduled_hook('cp_imp_tick'); return; }
	if (get_transient('cp_imp_lock')) { return; }
	/* v1.41: a régi (idézetek nélküli) formátumban átvett leveleket töröljük a panelből, és elölről kezdjük – a postafiókot nem érinti */
	if ((int) ($s['v'] ?? 1) < 2) {
		global $wpdb;
		$T = cp_mail_t('threads'); $M = cp_mail_t('messages');
		$wpdb->query("DELETE m FROM $M m JOIN $T t ON t.id = m.thread_id WHERE t.src = 'imap'");
		$wpdb->query("DELETE FROM $T WHERE src = 'imap'");
		foreach ($s['folders'] as &$f0) { unset($f0['uids'], $f0['pos'], $f0['total'], $f0['done']); }
		unset($f0);
		$s['cnt'] = array(); $s['v'] = 2; $s['restarted'] = time();
		cp_imp_state_set($s);
	}
	set_transient('cp_imp_lock', 1, 120);
	if (function_exists('set_time_limit')) { @set_time_limit(110); }
	/* ha a köteg váratlanul leállna (végzetes hiba), az ok bekerül az állapotba */
	register_shutdown_function(function () {
		$e = error_get_last();
		if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) { $st = cp_imp_state(); $st['fatal'] = mb_substr($e['message'] . ' @' . basename($e['file']) . ':' . $e['line'], 0, 300); cp_imp_state_set($st); delete_transient('cp_imp_lock'); }
	});
	$t0 = time();
	$mb = cp_imp_open();
	if (is_wp_error($mb)) { $s['err'] = $mb->get_error_message(); $s['errn'] = (int) ($s['errn'] ?? 0) + 1; if ($s['errn'] >= 5) { $s['running'] = 0; } cp_imp_state_set($s); delete_transient('cp_imp_lock'); return; }
	$s['errn'] = 0; $s['err'] = '';
	foreach ($s['folders'] as $i => &$f) {
		if (!empty($f['done'])) { continue; }
		if (!@imap_reopen($mb, cp_imp_ref() . $f['raw'], OP_READONLY)) { $f['done'] = 1; $f['err'] = 'nem nyitható'; continue; }
		if (!isset($f['uids'])) { $f['uids'] = array_values(array_map('intval', (array) imap_search($mb, 'SINCE "' . $s['since'] . '"', SE_UID))); $f['pos'] = 0; $f['total'] = count($f['uids']); }
		$k = 0;
		while ($f['pos'] < $f['total'] && time() - $t0 < 45) {
			try { $r = cp_imp_one($mb, $f['uids'][$f['pos']], !empty($f['sent'])); }
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
	echo '<h2>Salesmate-sablonok betöltése</h2><p>Illeszd be a Chrome-bővítményben futó Claude által összegyűjtött sablonokat (JSON: <code>[{"folder":"…","name":"…","subject":"…","body_html":"…"}, …]</code>). Az azonos mappában azonos nevű sablon frissül, a többi újként kerül be. A Salesmate-et nem érinti.</p>';
	echo '<form method="post">' . wp_nonce_field('cp_imp_do', '_wpnonce', true, false) . '<input type="hidden" name="cp_imp_do" value="tpl"><textarea name="tpl_json" rows="10" class="large-text code" placeholder="[ { &quot;folder&quot;: &quot;Ajánlatok&quot;, &quot;name&quot;: &quot;…&quot;, &quot;subject&quot;: &quot;…&quot;, &quot;body_html&quot;: &quot;<p>…</p>&quot; } ]"></textarea><p><button class="button button-primary">Sablonok betöltése</button></p></form>';
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
