<?php
/* =====================================================================
 * Levélküldés a panelből – próbaüzem (v1.52)
 * Alapból KIKAPCSOLVA. A WordPressben (Beállítások → Crazy levelezés → Küldés) kapcsolható be, és csak a kijelölt
 * kollégák küldhetnek, személyes fiókkal. A levél a postafiókból megy (SMTP, ugyanazzal a fiókkal, mint a beérkezés),
 * és bekerül a postafiók Elküldött mappájába is – így a Salesmate és a levelezőprogramok ugyanúgy látják.
 * Válasznál a szálba fűzi (In-Reply-To / References). Óránként legfeljebb 30 levél fejenként.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_send_opts() {
	$o = get_option('cp_send_opts', array());
	return wp_parse_args(is_array($o) ? $o : array(), array('on' => 0, 'users' => array(), 'host' => '', 'port' => 465, 'secure' => 'ssl', 'from_name' => 'EventCrazy', 'sign' => '', 'append' => 1, 'sent_folder' => ''));
}
function cp_send_ready() { return function_exists('cp_imp_opts') && function_exists('cp_imp_pw') && cp_imp_pw() !== ''; }
/* küldhet-e ez a felhasználó (bekapcsolva + kijelölve + személyes fiók) */
function cp_send_can($uid = null) {
	$o = cp_send_opts();
	$uid = $uid === null ? (function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0) : (int) $uid;
	return !empty($o['on']) && $uid > 0 && in_array($uid, array_map('intval', (array) $o['users']), true) && cp_send_ready();
}
/* a levélíróba kerülő aláírás (HTML): a kolléga sajátja, különben a közös */
function cp_send_sig_html($uid = null) {
	$uid = $uid === null ? (function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0) : (int) $uid;
	$own = $uid && function_exists('cp_set_user_sig') ? cp_set_user_sig($uid) : '';
	if ($own !== '') { return cp_mail_clean($own); }
	$c = trim((string) cp_send_opts()['sign']);
	return $c !== '' ? '<p>' . nl2br(esc_html($c)) . '</p>' : '';
}
function cp_send_cfg() {
	$o = cp_send_opts();
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$own = $me && function_exists('cp_set_user_sig') ? cp_set_user_sig($me) : '';
	return array('on' => cp_send_can(), 'sign' => $own !== '' || trim((string) $o['sign']) !== '', 'own_sign' => $own !== '', 'from' => function_exists('cp_imp_opts') ? cp_imp_opts()['user'] : '', 'box' => function_exists('cp_imp_opts') ? cp_imp_opts()['box'] : '');
}

function cp_send_mailer() {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	$o = cp_send_opts(); $i = cp_imp_opts();
	$m = new PHPMailer\PHPMailer\PHPMailer(true);
	$m->isSMTP();
	$m->Host = $o['host'] !== '' ? $o['host'] : $i['host'];
	$m->Port = (int) $o['port'] ?: 465;
	$m->SMTPSecure = $o['secure'] === 'tls' ? 'tls' : 'ssl';
	$m->SMTPAuth = true;
	$m->Username = $i['user'];
	$m->Password = cp_imp_pw();
	$m->CharSet = 'UTF-8';
	$m->Timeout = 25;
	$m->setFrom($i['user'], (string) $o['from_name']);
	return $m;
}

/* az Elküldött mappa neve a postafiókban (egyszer megkeresi, utána megjegyzi) */
function cp_send_sent_folder() {
	$o = cp_send_opts();
	if ($o['sent_folder'] !== '') { return $o['sent_folder']; }
	$i = cp_imp_opts();
	$ref = '{' . $i['host'] . ':' . (int) $i['port'] . '/imap/ssl}';
	$mb = @imap_open($ref, $i['user'], cp_imp_pw(), OP_HALFOPEN, 1);
	if (!$mb) { return ''; }
	$best = '';
	foreach ((array) imap_list($mb, $ref, '*') as $full) {
		$name = substr($full, strlen($ref));
		if (cp_imp_is_sent(cp_imp_utf8($name)) && ($best === '' || strlen($name) < strlen($best))) { $best = $name; }
	}
	imap_errors(); imap_close($mb);
	if ($best !== '') { $o['sent_folder'] = $best; update_option('cp_send_opts', $o, false); }
	return $best;
}
function cp_send_append($mime) {
	$f = cp_send_sent_folder();
	if ($f === '') { return 'Nem találom az Elküldött mappát.'; }
	$i = cp_imp_opts();
	$ref = '{' . $i['host'] . ':' . (int) $i['port'] . '/imap/ssl}';
	$mb = @imap_open($ref . $f, $i['user'], cp_imp_pw(), 0, 1);
	if (!$mb) { $e = imap_last_error(); imap_errors(); return 'IMAP: ' . $e; }
	$ok = @imap_append($mb, $ref . $f, $mime, '\\Seen');
	$e = $ok ? '' : (string) imap_last_error();
	imap_errors(); imap_close($mb);
	return $ok ? '' : 'Az Elküldött mappába nem sikerült menteni: ' . $e;
}

/* a válaszolt levél eredeti Message-ID-ja: a táblából, vagy (régebbi levélnél) a postafiókból kikeresve */
function cp_send_parent_id($thread) {
	global $wpdb;
	$M = cp_mail_t('messages');
	$cols = $wpdb->get_col("SHOW COLUMNS FROM $M");
	$m = $wpdb->get_row($wpdb->prepare("SELECT * FROM $M WHERE thread_id = %d AND dir = 'in' ORDER BY sent_at DESC, id DESC LIMIT 1", $thread), ARRAY_A);
	if (!$m) { return ''; }
	if (in_array('msgid', $cols, true) && !empty($m['msgid'])) { return $m['msgid']; }
	if (empty($m['mid']) || !function_exists('cp_imp_open')) { return ''; }
	$mb = cp_imp_open('INBOX');
	if (is_wp_error($mb)) { return ''; }
	$ts = strtotime($m['sent_at'] . ' UTC') ?: time();
	$uids = (array) @imap_search($mb, 'SINCE "' . gmdate('d-M-Y', $ts - DAY_IN_SECONDS) . '" BEFORE "' . gmdate('d-M-Y', $ts + 2 * DAY_IN_SECONDS) . '" FROM "' . addcslashes($m['from_email'], '"\\') . '"', SE_UID);
	$found = '';
	foreach (array_slice(array_filter($uids), 0, 20) as $uid) {
		$h = @imap_rfc822_parse_headers((string) imap_fetchheader($mb, $uid, FT_UID));
		if ($h && !empty($h->message_id) && md5(trim($h->message_id)) === $m['mid']) { $found = trim($h->message_id); break; }
	}
	imap_errors(); imap_close($mb);
	if ($found !== '' && in_array('msgid', $cols, true)) { $wpdb->update($M, array('msgid' => $found), array('id' => (int) $m['id'])); }
	return $found;
}

function cp_send_addrs($s) {
	$out = array();
	foreach (preg_split('/[,;\s]+/', (string) $s, -1, PREG_SPLIT_NO_EMPTY) as $e) { $e = sanitize_email($e); if ($e !== '' && is_email($e) && !in_array(strtolower($e), $out, true)) { $out[] = strtolower($e); } }
	return $out;
}

add_action('wp_ajax_nopriv_cp_mail_send', 'cp_send_ajax');
add_action('wp_ajax_cp_mail_send', 'cp_send_ajax');
function cp_send_ajax() {
	cp_el_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	if (!cp_send_can($me)) { cp_el_err('A panelből küldés neked nincs bekapcsolva (próbaüzem). Küldj a Salesmate-ből.', 403); }
	global $wpdb;
	$test = !empty($_POST['test']);
	$u = function_exists('cp_pu_get') ? cp_pu_get($me) : null;
	$to = $test ? cp_send_addrs($u ? $u['e'] : '') : cp_send_addrs(wp_unslash($_POST['to'] ?? ''));
	$cc = $test ? array() : cp_send_addrs(wp_unslash($_POST['cc'] ?? ''));
	$bcc = $test ? array() : cp_send_addrs(wp_unslash($_POST['bcc'] ?? ''));
	if (!$to) { cp_el_err($test ? 'A fiókodhoz nincs e-mail-cím.' : 'Adj meg legalább egy érvényes címzettet.'); }
	if (count($to) + count($cc) + count($bcc) > 20) { cp_el_err('Legfeljebb 20 címzett lehet.'); }
	$n = (int) get_transient('cp_send_n' . $me);
	if ($n >= 30) { cp_el_err('Óránként legfeljebb 30 levelet küldhetsz a panelből (próbaüzem).', 429); }
	$thread = (int) ($_POST['thread'] ?? 0);
	$t = $thread ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_mail_t('threads') . ' WHERE id = %d', $thread), ARRAY_A) : null;
	if ($thread && !$t) { cp_el_err('Nincs ilyen levélszál.', 404); }
	$deal = (int) ($_POST['deal'] ?? 0) ?: ($t ? (int) $t['deal_id'] : 0);
	$vals = cp_mail_values($deal);
	$subj = trim(cp_mail_render(sanitize_text_field(wp_unslash($_POST['subject'] ?? '')), $vals, false));
	if ($subj === '') { cp_el_err('Add meg a tárgyat.'); }
	$html = cp_mail_render(cp_mail_clean(wp_unslash($_POST['body'] ?? '')), $vals, true);
	if (trim(wp_strip_all_tags($html)) === '') { cp_el_err('Üres a levél.'); }
	if (preg_match('/\[KITÖLTENDŐ/u', $html . $subj)) { cp_el_err('A levélben még van kitöltendő rész ([KITÖLTENDŐ: …]).'); }
	$atts = function_exists('cp_att_take') ? cp_att_take(wp_unslash($_POST['att'] ?? ''), $me) : array();
	if (is_wp_error($atts)) { cp_el_err($atts->get_error_message()); }
	$o = cp_send_opts();
	/* aláírás: elsőként a kolléga saját aláírása (Beállítások → Saját beállításaim), különben a közös */
	$own = function_exists('cp_set_user_sig') ? cp_set_user_sig($me) : '';
	if (!empty($_POST['sign']) && strpos($html, 'cp-sign') === false) {
		if ($own !== '') { $html .= '<br><div class="cp-sign">' . cp_mail_clean($own) . '</div>'; }
		elseif (trim((string) $o['sign']) !== '') { $html .= '<br><div class="cp-sign">' . wp_kses_post(nl2br((string) $o['sign'])) . '</div>'; }
	}
	$doc = '<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1A1F2B">' . $html . '</body></html>';
	$i = cp_imp_opts();
	$dom = substr(strrchr($i['user'], '@'), 1) ?: 'eventcrazy.hu';
	$msgid = '<' . wp_generate_uuid4() . '@' . $dom . '>';
	try {
		$m = cp_send_mailer();
		foreach ($to as $e) { $m->addAddress($e); }
		foreach ($cc as $e) { $m->addCC($e); }
		foreach ($bcc as $e) { $m->addBCC($e); }
		$m->Subject = $test ? '[PRÓBA] ' . $subj : $subj;
		$m->isHTML(true);
		$m->Body = $doc;
		$m->AltBody = trim(html_entity_decode(wp_strip_all_tags(preg_replace('#<\s*(br|/p|/div|/li)\b[^>]*>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
		$m->MessageID = $msgid;
		$m->XMailer = 'Crazy panel';
		foreach ($atts as $x) { $m->addAttachment($x['path'], $x['n'], 'base64', $x['mime']); }
		if ($t && !$test) { $pid = cp_send_parent_id((int) $t['id']); if ($pid !== '') { $m->addCustomHeader('In-Reply-To', $pid); $m->addCustomHeader('References', $pid); } }
		$m->send();
		$mime = $m->getSentMIMEMessage();
	} catch (\Throwable $e) {
		cp_el_err('A levél nem ment el: ' . mb_substr($e->getMessage(), 0, 200), 502);
	}
	set_transient('cp_send_n' . $me, $n + 1, HOUR_IN_SECONDS);
	$warn = !empty($o['append']) && !$test ? cp_send_append($mime) : '';
	if ($test) { wp_send_json_success(array('test' => 1, 'to' => $to[0])); }
	/* a küldött mellékletek megmaradnak (a panelből letölthetők) */
	$alist = array();
	foreach ($atts as $x) { @file_put_contents(dirname($x['path']) . '/.sent', '1'); delete_transient('cp_att_' . $x['tok']); $alist[] = array('n' => $x['n'], 'mime' => $x['mime'], 's' => (int) $x['size'], 'f' => $x['tok'] . '/' . basename($x['path'])); }
	/* a levél a panel levélszálába (új levélnél új szál, a válaszok ide fűződnek) */
	$T = cp_mail_t('threads'); $now = gmdate('Y-m-d H:i:s');
	$plain = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($html)));
	if (!$t) {
		$tkey = md5(strtolower($to[0]) . '|' . (function_exists('cp_imp_norm_subj') ? cp_imp_norm_subj($subj) : mb_strtolower($subj)));
		$t = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE tkey = %s", $tkey), ARRAY_A);
		if (!$t) {
			$cn = $vals['kontakt.email'] !== '' && strtolower($vals['kontakt.email']) === strtolower($to[0]) ? (string) $vals['kontakt.nev'] : '';
			$wpdb->insert($T, array('mailbox' => $i['box'], 'subject' => mb_substr($subj, 0, 255), 'contact_name' => mb_substr($cn, 0, 190), 'contact_email' => $to[0], 'deal_id' => $deal ?: null, 'followers' => '', 'tags' => '', 'status' => 'open', 'folder' => 'sent', 'snippet' => mb_substr($plain, 0, 200), 'msg_count' => 0, 'has_attach' => 0, 'last_at' => $now, 'created_at' => $now, 'tkey' => $tkey, 'src' => 'panel', 'seen_at' => $now));
			$t = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE id = %d", (int) $wpdb->insert_id), ARRAY_A);
		}
	}
	$who = function_exists('cp_panel_who') ? cp_panel_who() : '';
	$row = array('thread_id' => (int) $t['id'], 'dir' => 'out', 'from_name' => (string) $o['from_name'] . ($who !== '' ? ' (' . $who . ')' : ''), 'from_email' => $i['user'], 'to_list' => implode(', ', $to), 'cc_list' => implode(', ', $cc), 'subject' => mb_substr($subj, 0, 255), 'body' => $html, 'attachments' => wp_json_encode($alist), 'status' => 'Elküldve a panelből', 'sent_at' => $now, 'mid' => md5($msgid));
	if (in_array('msgid', $wpdb->get_col('SHOW COLUMNS FROM ' . cp_mail_t('messages')), true)) { $row['msgid'] = $msgid; }
	$wpdb->insert(cp_mail_t('messages'), $row);
	$wpdb->update($T, array('msg_count' => (int) $t['msg_count'] + 1, 'last_at' => $now, 'snippet' => mb_substr($plain, 0, 200), 'seen_at' => $now, 'deal_id' => $t['deal_id'] ?: ($deal ?: null)) + ($alist ? array('has_attach' => 1) : array()), array('id' => (int) $t['id']));
	$wpdb->insert(cp_mail_t('events'), array('thread_id' => (int) $t['id'], 'kind' => 'send', 'text' => 'Levél elküldve a panelből: ' . mb_substr(implode(', ', $to), 0, 200), 'author_uid' => $me, 'author' => $who, 'created_at' => $now));
	wp_send_json_success(array('thread' => (int) $t['id'], 'warn' => $warn));
}

/* ------------------------------------------------------------ beállítások: Beállítások → Crazy levelezés (a lap alján) */
add_action('admin_init', function () {
	if (empty($_POST['cp_send_do']) || !current_user_can('manage_options')) { return; }
	check_admin_referer('cp_send_do');
	$o = cp_send_opts();
	$do = sanitize_key(wp_unslash($_POST['cp_send_do']));
	if ($do === 'save') {
		$o['on'] = !empty($_POST['on']) ? 1 : 0;
		$o['users'] = array_values(array_filter(array_map('intval', (array) wp_unslash($_POST['users'] ?? array()))));
		$o['host'] = preg_replace('/[^a-z0-9.\-]/i', '', (string) wp_unslash($_POST['host'] ?? ''));
		$o['port'] = (int) ($_POST['port'] ?? 465) ?: 465;
		$o['secure'] = ($_POST['secure'] ?? 'ssl') === 'tls' ? 'tls' : 'ssl';
		$o['from_name'] = sanitize_text_field(wp_unslash($_POST['from_name'] ?? 'EventCrazy')) ?: 'EventCrazy';
		$o['sign'] = sanitize_textarea_field(wp_unslash($_POST['sign'] ?? ''));
		$o['append'] = !empty($_POST['append']) ? 1 : 0;
		$o['sent_folder'] = '';
		update_option('cp_send_opts', $o, false);
		set_transient('cp_send_msg', 'Küldési beállítások mentve.' . ($o['on'] ? ' A próbaüzem BE van kapcsolva ' . count($o['users']) . ' kollégának.' : ' A küldés ki van kapcsolva.'), 60);
	} elseif ($do === 'test') {
		try { $m = cp_send_mailer(); $ok = $m->smtpConnect(); $m->smtpClose(); $f = cp_send_sent_folder(); set_transient('cp_send_msg', ($ok ? 'Az SMTP-kapcsolat és a bejelentkezés rendben.' : 'Nem sikerült csatlakozni.') . ' Elküldött mappa: ' . ($f !== '' ? cp_imp_utf8($f) : 'nem található'), 60); }
		catch (\Throwable $e) { set_transient('cp_send_msg', 'SMTP-hiba: ' . mb_substr($e->getMessage(), 0, 200), 60); }
	}
	wp_safe_redirect(admin_url('options-general.php?page=cp-mail-import#cp-send'));
	exit;
});
add_action('cp_imp_admin_after', function () {
	$o = cp_send_opts();
	$msg = get_transient('cp_send_msg'); if ($msg) { delete_transient('cp_send_msg'); }
	echo '<h2 id="cp-send">Küldés a panelből (próbaüzem)</h2>';
	if ($msg) { echo '<div class="notice notice-info inline"><p>' . esc_html($msg) . '</p></div>'; }
	echo '<p>Bekapcsolva a kijelölt kollégák a panel Levelezés fülén is küldhetnek és válaszolhatnak. A levél a fenti postafiókból megy (ugyanazzal a jelszóval), és bekerül a postafiók <b>Elküldött</b> mappájába is – így a Salesmate és a levelezőprogramok is látják. A Salesmate-ből küldés közben is ugyanúgy működik. Csak személyes fiókkal lehet küldeni.</p>';
	if (!cp_send_ready()) { echo '<div class="notice notice-warning inline"><p>Előbb add meg fent a postafiók jelszavát.</p></div>'; }
	echo '<form method="post">' . wp_nonce_field('cp_send_do', '_wpnonce', true, false) . '<table class="form-table" style="max-width:900px">';
	echo '<tr><th>Küldés</th><td><label><input type="checkbox" name="on" value="1"' . checked(!empty($o['on']), true, false) . '> bekapcsolva a kijelölt kollégáknak</label></td></tr>';
	echo '<tr><th>Kik küldhetnek</th><td>';
	$list = function_exists('cp_pu_data') ? cp_pu_data()['list'] : array();
	if (!$list) { echo '<em>Még nincs személyes fiók (Beállítások → Panel-felhasználók).</em>'; }
	foreach ($list as $id => $u) { if (empty($u['a'])) { continue; } echo '<label style="display:block"><input type="checkbox" name="users[]" value="' . (int) $id . '"' . checked(in_array((int) $id, array_map('intval', (array) $o['users']), true), true, false) . '> ' . esc_html($u['n']) . ' <small>(' . esc_html($u['e']) . ')</small></label>'; }
	echo '<p class="description">Próbaüzemhez elég egy kolléga.</p></td></tr>';
	echo '<tr><th>Feladó neve</th><td><input type="text" name="from_name" value="' . esc_attr($o['from_name']) . '" class="regular-text"></td></tr>';
	echo '<tr><th>Aláírás</th><td><textarea name="sign" rows="5" class="large-text">' . esc_textarea($o['sign']) . '</textarea><p class="description">A levélíróban az „Aláírás hozzáadása” bepipálásával kerül a levél végére.</p></td></tr>';
	echo '<tr><th>Kimenő szerver (SMTP)</th><td><input type="text" name="host" value="' . esc_attr($o['host']) . '" placeholder="' . esc_attr(function_exists('cp_imp_opts') ? cp_imp_opts()['host'] : '') . '" class="regular-text"> port <input type="number" name="port" value="' . (int) $o['port'] . '" style="width:80px"> <select name="secure"><option value="ssl"' . selected($o['secure'], 'ssl', false) . '>SSL (465)</option><option value="tls"' . selected($o['secure'], 'tls', false) . '>STARTTLS (587)</option></select><p class="description">Üresen: ugyanaz, mint a levelezőszerver.</p></td></tr>';
	echo '<tr><th>Elküldött mappa</th><td><label><input type="checkbox" name="append" value="1"' . checked(!empty($o['append']), true, false) . '> az elküldött levél másolata a postafiók Elküldött mappájába</label>' . ($o['sent_folder'] !== '' ? ' <small>(' . esc_html(cp_imp_utf8($o['sent_folder'])) . ')</small>' : '') . '</td></tr>';
	echo '</table><p><button class="button button-primary" name="cp_send_do" value="save">Mentés</button> <button class="button" name="cp_send_do" value="test">SMTP-kapcsolat ellenőrzése (nem küld levelet)</button></p></form>';
});
