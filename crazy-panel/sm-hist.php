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
	/* a már feldolgozott dealek (levél nélküliek is) – az újraindított program ezeket kihagyja */
	$doneIds = get_option('cp_smh_done', array()); if (!is_array($doneIds)) { $doneIds = array(); }
	foreach (array_slice($j, 0, 300) as $x) {
		$did = (int) ($x['deal'] ?? 0);
		$d = $did ? $wpdb->get_row($wpdb->prepare("SELECT d.id, c.name cn, c.email ce FROM $D d LEFT JOIN $C c ON c.id = d.contact_id WHERE d.id = %d", $did), ARRAY_A) : null;
		if (!$d) { $st['nodeal']++; continue; }
		$st['deal']++;
		if (empty($x['partial'])) { $doneIds[$did] = 1; }
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
			$body = cp_smh_strip_css(function_exists('cp_mail_clean') ? cp_mail_clean(cp_smh_strip_css($body)) : wp_kses_post(cp_smh_strip_css($body)));
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
	update_option('cp_smh_done', $doneIds, false);
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
	foreach ((array) $wpdb->get_col('SELECT DISTINCT t.deal_id FROM ' . cp_mail_t('threads') . " t WHERE SUBSTRING_INDEX(t.tags, ',', 1) = 'salesmate' AND t.deal_id IS NOT NULL") as $x) { $done[(int) $x] = 1; }
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
	echo '<p><b>A program által letöltött fájlok</b> (salesmate-levelek-01.json, -02.json …): <input type="file" id="cp-smh-files" accept=".json,application/json" multiple> <button type="button" class="button button-primary" id="cp-smh-go">Fájlok betöltése</button></p><pre id="cp-smh-out" style="background:#f6f7f7;padding:8px;max-height:260px;overflow:auto;display:none"></pre>';
	echo '<script>(function(){var u=' . wp_json_encode(rest_url('crazy-panel/v1/smh')) . ',n=' . wp_json_encode(wp_create_nonce('wp_rest')) . ',o=document.getElementById("cp-smh-out");document.getElementById("cp-smh-go").addEventListener("click",async function(){var fs=document.getElementById("cp-smh-files").files;if(!fs.length){alert("Válaszd ki a fájlokat.");return;}this.disabled=true;o.style.display="block";var tot=0,bad=0;for(var f of fs){var j;try{j=JSON.parse(await f.text());}catch(e){o.textContent+=f.name+": nem érvényes JSON\n";continue;}if(!Array.isArray(j)){j=j.deals||[];}for(var i=0;i<j.length;i+=10){var part=j.slice(i,i+10),ok=false,last="";for(var t=0;t<3&&!ok;t++){try{var r=await fetch(u,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"text/plain;charset=UTF-8","X-WP-Nonce":n},body:JSON.stringify(part)});var x=await r.json().catch(function(){return {};});if(r.ok&&x.ok){ok=true;o.textContent+=f.name+" ["+(i+1)+"–"+(i+part.length)+"]: "+x.msg+"\n";}else{last=x.msg||("HTTP "+r.status);}}catch(e){last=e.message;}if(!ok)await new Promise(function(z){setTimeout(z,3000);});}if(!ok){bad++;o.textContent+="⚠ "+f.name+" ["+(i+1)+"–"+(i+part.length)+"]: nem sikerült ("+last+") – töltsd be újra ezt a fájlt később (ami már bent van, nem duplázódik)\n";}o.scrollTop=o.scrollHeight;}tot++;}o.textContent+="Kész: "+tot+" fájl"+(bad?", "+bad+" adag hibás – azokat a fájlokat töltsd be újra":"")+".\n";this.disabled=false;});})();</script>';
	echo '<h3>Automatikus átvétel (ajánlott)</h3><p>A program a Salesmate-oldalon, a te belépéseddel fut: dealenként lekéri a leveleket, és 100 dealenként egy fájlt ment a Letöltések mappába; a fájlokat fent, a „Fájlok betöltése” gombbal töltsd be (egyszerre többet is kijelölhetsz). A Salesmate-ben semmit nem módosít; a még olvasatlan, friss beszélgetéseket kihagyja, hogy ott ne legyenek olvasottnak jelölve. A kulcsa 48 óráig érvényes.</p>';
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
		if (!cp_smh_token_ok((string) $req->get_param('k')) && !current_user_can('manage_options')) { return new WP_REST_Response(array('ok' => 0, 'msg' => 'Lejárt vagy hibás kulcs – hozz létre új programot a panelben.'), 403); }
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
	$doneIds = get_option('cp_smh_done', array()); if (!is_array($doneIds)) { $doneIds = array(); }
	foreach (cp_smh_deals() as $d) {
		if (isset($doneIds[(int) $d['id']])) { continue; }
		if (!$all && $d['lcm'] !== 'Email') { continue; }
		if (!$all && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T t JOIN $M m ON m.thread_id = t.id WHERE t.deal_id = %d AND m.dir = 'out' AND m.status <> 'idézetből'", $d['id']))) { continue; }
		$ids[] = (int) $d['id'];
	}
	$url = rest_url('crazy-panel/v1/smh') . '?k=' . $k;
	$js = <<<'JS'
/* Crazy panel – Salesmate-levelek átvétele. Csak olvas a Salesmate-ből; a leveleket a Crazy panelnek küldi. */
(async () => {
const IDS = __IDS__, DEST = __DEST__, H = {};
/* a Salesmate elnémítja a konzolt – a haladás egy dobozban látszik az oldal jobb alsó sarkában */
const box = document.createElement('div');
box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:2147483647;width:380px;max-height:50vh;overflow:auto;background:#141C33;color:#fff;font:13px/1.45 system-ui,sans-serif;padding:12px 14px;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.35);white-space:pre-wrap';
box.innerHTML = '<b style="color:#FDBA74">Crazy panel – levelek átvétele</b>\n';
document.body.appendChild(box);
const st = document.createElement('div'); box.appendChild(st);
const lg = document.createElement('div'); lg.style.cssText = 'margin-top:6px;opacity:.8;font-size:12px'; box.appendChild(lg);
const log = (...a) => { const t = a.join(' '); lg.textContent = (t + '\n' + lg.textContent).slice(0, 3000); };
const stat = t => { st.textContent = t; };
/* a belépési fejlécek: a Salesmate saját kéréseiből olvassuk ki (a böngészőn kívülre nem kerülnek) */
const want = ['accesstoken', 'x-linkname', 'x-workspace-id', 'isimpersonate'];
const grab = (k, v) => { if (want.includes(String(k).toLowerCase())) H[k] = v; };
const oSet = XMLHttpRequest.prototype.setRequestHeader;
XMLHttpRequest.prototype.setRequestHeader = function (k, v) { grab(k, v); return oSet.apply(this, arguments); };
const oFetch = window.fetch;
window.fetch = function (u, o) { try { const h = new Headers((o && o.headers) || {}); h.forEach((v, k) => grab(k, v)); } catch (e) {} return oFetch.apply(this, arguments); };
stat('Kattints most a Salesmate-ben bármelyik menüpontra (pl. Deals), hogy a program megkapja a belépést…');
for (let i = 0; i < 120 && !Object.keys(H).some(k => k.toLowerCase() === 'accesstoken'); i++) await new Promise(r => setTimeout(r, 500));
/* a figyelő bekapcsolva marad: ha a Salesmate frissíti a belépési kulcsot, a program is az újat használja */
if (!Object.keys(H).some(k => k.toLowerCase() === 'accesstoken')) { stat('Nem kaptam belépést – frissítsd az oldalt (F5), futtasd újra a programot, és utána kattints a Deals menüre.'); return; }
stat('Belépés rendben (' + Object.keys(H).join(', ') + '). Indul…');
let errs = 0;
let tokAt = H.accessToken || H.accesstoken || '', wait403 = null, bad403 = 0, stop = false;
const curTok = () => H.accessToken || H.accesstoken || '';
/* 403: a kulcs lejárt → megvárjuk, amíg a Salesmate újat használ (egy kattintás a felületen), utána folytatjuk */
const refresh = async () => {
	if (wait403) return wait403;
	wait403 = (async () => {
		const old = curTok();
		stat('A Salesmate belépési kulcsa lejárt. Kattints a Salesmate-ben egy menüpontra (pl. Deals), és a program magától folytatja…');
		for (let i = 0; i < 600 && curTok() === old; i++) await new Promise(r => setTimeout(r, 500));
		if (curTok() === old) { stop = true; stat('Nem kaptam új belépési kulcsot – a program megállt. Frissítsd az oldalt (F5), és indítsd újra (a kész fájlok megmaradnak).'); }
		wait403 = null;
	})();
	return wait403;
};
/* ha kiesik az internet: várunk, amíg visszajön, és onnan folytatjuk */
const online = async () => { if (navigator.onLine) return; stat('Megszakadt az internetkapcsolat – várok, és magától folytatom, amint visszajön…'); while (!navigator.onLine) await new Promise(r => setTimeout(r, 2000)); await new Promise(r => setTimeout(r, 3000)); };
const get = async (p, cx) => { if (stop) return null; for (let t = 0; t < 3; t++) { await online(); if (wait403) await wait403; if (stop) return null; const ac = new AbortController(), to = setTimeout(() => ac.abort(), 20000); try { const r = await oFetch(location.origin + p, { headers: Object.assign({ Accept: 'application/json' }, H), credentials: 'include', signal: ac.signal }); clearTimeout(to); if (r.status === 429) { await new Promise(z => setTimeout(z, 3000 * (t + 1))); continue; } if (r.status === 401 || r.status === 403) { bad403++; if (bad403 > 40) { stop = true; stat('A Salesmate folyamatosan elutasítja a kéréseket (403) – a program megállt. Küldj képet erről a dobozról.'); return null; } await refresh(); continue; } bad403 = 0; if (!r.ok) { errs++; if (cx) cx.bad = 1; if (errs <= 8) log('HTTP ' + r.status + ': ' + p.slice(0, 90)); return null; } const j = await r.json(); return j && j.Data !== undefined ? j.Data : j; } catch (e) { clearTimeout(to); if (!navigator.onLine) { t--; continue; } errs++; if (cx) cx.bad = 1; if (errs <= 8) log('hiba (' + (e.name || e.message) + '): ' + p.slice(0, 80)); await new Promise(z => setTimeout(z, 1500)); } } return null; };
const gap = () => new Promise(r => setTimeout(r, 250));
const TYPES = 'EmailReceived,EmailSent,ScheduledEmail,TeamInboxConversationEmailSent,TeamInboxConversationEmailReceived,SequenceEmailSent,SequenceEmailReceived,SequenceTeamInboxEmailSent,SequenceTeamInboxEmailReceived,MarketingAutomationEmailSent';
const pad = n => String(n).padStart(2, '0');
const loc = x => { const d = new Date(typeof x === 'number' || /^\d+$/.test(String(x)) ? Number(x) : x); if (isNaN(d)) return ''; const p = new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Budapest', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(d).reduce((a, q) => (a[q.type] = q.value, a), {}); return p.year + '-' + p.month + '-' + p.day + ' ' + (p.hour === '24' ? '00' : p.hour) + ':' + p.minute; };
const ad = a => !a ? '' : (a.name ? a.name + ' <' + a.address + '>' : (a.address || ''));
const ads = l => (l || []).map(ad).filter(Boolean).join(', ');
const fresh = Date.now() - 14 * 864e5;
const out = []; let saved = 0, skipped = 0, mails = 0, done = 0, part = 0, failedN = 0;
/* a böngésző a Salesmate-oldalról nem enged más címre küldeni → 100 dealenként fájl (Letöltések mappa); a panel admin oldalán kell betölteni */
const save = () => {
	if (!out.length) return;
	const batch = out.splice(0, out.length); part++;
	const b = new Blob([JSON.stringify(batch)], { type: 'application/json' });
	const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'salesmate-levelek-' + String(part).padStart(2, '0') + '.json'; document.body.appendChild(a); a.click(); a.remove();
	saved += batch.length; log('Mentve: salesmate-levelek-' + String(part).padStart(2, '0') + '.json (' + batch.length + ' deal)');
};
const one = async id => {
	const emails = [], cx = { bad: 0 };
	const convs = new Map();
	for (let from = 0; from < 500; from += 50) {
		const d = await get('/apis/timeline/v4/activity-feed/module/4/object/' + id + '/past-timeline-entries?rows=50&from=' + from + '&filterBy=emails&activitiesToQuery=' + TYPES, cx);
		if (!d || !Array.isArray(d.data)) break;
		for (const it of d.data) { const ob = it.object || {}; if (ob.conversationId) { if (!convs.has(ob.conversationId)) convs.set(ob.conversationId, ob); } else if (ob.subject || ob.snippet) { emails.push({ dir: /Sent/i.test(it.type) ? 'out' : 'in', from: '', to: '', date: loc(it.createdAtTS || it.createdAt), subject: ob.subject || '', body: ob.snippet || '', attachments: [] }); } }
		if (!d.fetchMore) break;
	}
	for (const [cid, ob] of convs) {
		/* a még olvasatlan, friss beszélgetést nem nyitjuk meg (ne legyen olvasott a Salesmate-ben) */
		if (ob.isRead === false && new Date(ob.latestReceivedEmailDate || 0).getTime() > fresh) { skipped++; continue; }
		const tl = await get('/apis/v3/teamInboxes/conversations/' + cid + '/timeline?rows=100&from=0&folderName=', cx);
		const rows = Array.isArray(tl) ? tl : ((tl && (tl.data || tl.timeline)) || []);
		const eids = [...new Set(rows.filter(r => /Email(Sent|Received)/.test(r.type || r.activityType || '')).map(r => (r.data && r.data.id) || null).filter(Boolean))];
		for (const eid of eids) {
			const e = await get('/apis/v3/teamInboxes/conversations/' + cid + '/emails/' + eid, cx);
			if (!e) continue;
			const fromAddr = (e.from && e.from.address || '').toLowerCase();
			emails.push({ dir: /eventcrazy\.hu|crazyphoto\.hu|studioframes\.hu/.test(fromAddr) ? 'out' : 'in', from: ad(e.from), to: ads(e.to), cc: ads(e.cc), date: loc(e.emailDate || e.receivedDate), subject: e.subject || '', body: e.htmlBody || e.textBody || e.snippet || '', attachments: (e.attachments || []).map(a => a.name || a.fileName || a.filename || '').filter(Boolean) });
			mails++;
		}
	}
	/* hibás deal: a levelei bekerülnek, de nem lesz „kész” – a következő program újra megpróbálja */
	out.push(cx.bad ? { deal: id, emails, partial: 1 } : { deal: id, emails });
	if (cx.bad) failedN++;
	done++;
	if (out.length >= 100) save();
	stat(done + ' / ' + IDS.length + ' deal kész · ' + mails + ' levél · ' + part + ' fájl mentve' + (skipped ? ' · ' + skipped + ' friss olvasatlan kihagyva' : '') + (failedN ? ' · ' + failedN + ' deal hibás (később újra)' : '') + '\nHagyd nyitva ezt a lapot. Ha a Chrome rákérdez, engedélyezd a több fájl letöltését.');
};
/* 4 deal egyszerre */
let next = 0;
const worker = async () => { while (next < IDS.length && !stop) { const id = IDS[next++]; try { await one(id); } catch (e) { errs++; log('deal #' + id + ': ' + e.message); } await gap(); } };
/* először egyetlen deal próbaként – ha az sem megy, nem indítunk tömegesen */
await worker1();
async function worker1() { if (!IDS.length) return; const id = IDS[next++]; await one(id); if (bad403 && !stop) log('Az első deal 403-at kapott – várom az új kulcsot.'); }
if (!stop) await Promise.all([worker(), worker()]);
XMLHttpRequest.prototype.setRequestHeader = oSet; window.fetch = oFetch;
save();
stat('KÉSZ ✓ ' + IDS.length + ' deal, ' + mails + ' levél, ' + part + ' fájl a Letöltések mappában (salesmate-levelek-01.json …). ' + (skipped ? skipped + ' friss, olvasatlan beszélgetést kihagytam. ' : '') + (failedN ? failedN + ' deal hibára futott – ezeket a következő program újra megpróbálja. ' : '') + '\nMost töltsd be a fájlokat a panel admin oldalán („Fájlok betöltése”).');
})();
JS;
	return str_replace(array('__IDS__', '__DEST__'), array(wp_json_encode($ids), wp_json_encode($url)), $js);
}

/* v2.6.9: a levél HTML-jének stílusa (style / head / megjegyzés) ne kerüljön szövegként a levélbe; a már szöveggé vált CSS-szabályok törlése */
function cp_smh_strip_css($h) {
	$h = (string) $h;
	$h = preg_replace('#<!--.*?-->#s', '', $h);
	$h = preg_replace('#<(style|script|head|title|xml)\b[^>]*>.*?</\1\s*>#si', '', $h);
	/* már szöveggé vált szabályok: @media / @font-face / @page blokkok és „szelektor { tulajdonság: érték; }” */
	for ($i = 0; $i < 6; $i++) {
		$n = preg_replace(array('/@(?:media|supports)[^{}<]{0,200}\{(?:[^{}<]*\{[^{}<]*\})*[^{}<]*\}/u', '/@(?:font-face|page|import|charset)[^{}<;]{0,200}(?:\{[^{}<]*\}|;)/u', '/(?<![\p{L}\p{N}<\/])[a-zA-Z0-9_.#*:\s,\-\[\]="\'()]{1,200}\{\s*(?:[a-zA-Z\-]+\s*:[^{}<]*;?\s*)+\}/u'), '', $h);
		if ($n === null || $n === $h) { break; }
		$h = $n;
	}
	$h = preg_replace('#<p>\s*(?:<br\s*/?>\s*)*</p>#i', '', $h);
	/* ékezetes betűk számkóddal (&#xE1; = á, &aacute;) → rendes betűk; a HTML-jelek (< > & ") kódolva maradnak */
	$h = preg_replace_callback('/&#(x[0-9a-fA-F]{1,6}|[0-9]{1,7});/', function ($m) { $c = $m[1][0] === 'x' || $m[1][0] === 'X' ? hexdec(substr($m[1], 1)) : (int) $m[1]; return ($c >= 160 && $c <= 0x10FFFF) ? mb_chr($c, 'UTF-8') : ($c === 32 ? ' ' : $m[0]); }, $h);
	$h = preg_replace_callback('/&([a-zA-Z]{2,8});/', function ($m) { if (in_array(strtolower($m[1]), array('lt', 'gt', 'amp', 'quot', 'apos'), true)) { return $m[0]; } $d = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'); return $d === $m[0] ? $m[0] : $d; }, $h);
	return trim($h);
}
