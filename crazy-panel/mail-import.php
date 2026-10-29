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
function cp_imp_hdr($s) {
	$out = '';
	foreach ((array) imap_mime_header_decode((string) $s) as $p) {
		$cs = strtoupper((string) $p->charset);
		$out .= ($cs === 'DEFAULT' || $cs === 'UTF-8' || $cs === '') ? $p->text : (@mb_convert_encoding($p->text, 'UTF-8', $cs) ?: $p->text);
	}
	return trim($out);
}
function cp_imp_decode($data, $enc, $charset) {
	if ($enc === 3) { $data = base64_decode($data); } elseif ($enc === 4) { $data = quoted_printable_decode($data); }
	$cs = strtoupper((string) $charset);
	if ($cs !== '' && $cs !== 'UTF-8' && $cs !== 'US-ASCII') { $c = @mb_convert_encoding($data, 'UTF-8', $cs); if ($c !== false) { $data = $c; } }
	if (!mb_check_encoding($data, 'UTF-8')) { $data = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-2'); }
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
	$upd = array('msg_count' => (int) $t['msg_count'] + 1);
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
	set_transient('cp_imp_lock', 1, 120);
	if (function_exists('set_time_limit')) { @set_time_limit(110); }
	$t0 = time();
	$mb = cp_imp_open();
	if (is_wp_error($mb)) { $s['err'] = $mb->get_error_message(); $s['errn'] = (int) ($s['errn'] ?? 0) + 1; if ($s['errn'] >= 5) { $s['running'] = 0; } cp_imp_state_set($s); delete_transient('cp_imp_lock'); return; }
	$s['errn'] = 0; $s['err'] = '';
	foreach ($s['folders'] as $i => &$f) {
		if (!empty($f['done'])) { continue; }
		if (!@imap_reopen($mb, cp_imp_ref() . $f['raw'], OP_READONLY)) { $f['done'] = 1; $f['err'] = 'nem nyitható'; continue; }
		if (!isset($f['uids'])) { $f['uids'] = array_values(array_map('intval', (array) imap_search($mb, 'SINCE "' . $s['since'] . '"', SE_UID))); $f['pos'] = 0; $f['total'] = count($f['uids']); }
		while ($f['pos'] < $f['total'] && time() - $t0 < 45) {
			$r = cp_imp_one($mb, $f['uids'][$f['pos']], !empty($f['sent']));
			$s['cnt'][$r] = (int) ($s['cnt'][$r] ?? 0) + 1;
			$f['pos']++;
		}
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
	cp_imp_state_set(array('running' => 1, 'started' => time(), 'since' => gmdate('d-M-Y', strtotime('-' . max(1, (int) $o['years']) . ' years')), 'folders' => $list, 'cnt' => array()));
	if (!wp_next_scheduled('cp_imp_tick')) { wp_schedule_event(time() + 5, 'cp_minute', 'cp_imp_tick'); }
	return 'Az import elindult – percenként halad, nyugodtan bezárhatod az oldalt.';
}

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
		echo '<h2>Import állapota</h2><p>' . (!empty($s['running']) ? '<b>Fut</b> (percenként egy köteg)' : (!empty($s['finished']) ? '<b>Kész</b> – ' . esc_html(wp_date('Y.m.d. H:i', (int) $s['finished'])) : 'Áll')) . '. Átvéve: <b>' . (int) ($s['cnt']['ok'] ?? 0) . '</b> · már megvolt: ' . (int) ($s['cnt']['dup'] ?? 0) . ' · kihagyva (hírlevél, értesítő, üres): ' . (int) ($s['cnt']['skip'] ?? 0) . (!empty($s['err']) ? ' · <span style="color:#b32d2e">hiba: ' . esc_html($s['err']) . '</span>' : '') . '</p><ul>';
		foreach ((array) ($s['folders'] ?? array()) as $f) { echo '<li>' . esc_html($f['name']) . ': ' . (isset($f['total']) ? (int) $f['pos'] . ' / ' . (int) $f['total'] : (!empty($f['done']) ? 'kész' : 'várakozik')) . (!empty($f['done']) ? ' ✓' : '') . '</li>'; }
		echo '</ul>';
		if (!empty($s['running'])) { echo '<form method="post">' . wp_nonce_field('cp_imp_do', '_wpnonce', true, false) . '<button class="button" name="cp_imp_do" value="stop">Import leállítása</button></form><p class="description">Az oldal frissítésével látod a haladást.</p>'; }
	}
	echo '</div>';
}
