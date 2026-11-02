<?php
/* Ügyfélportál – WP admin → Beállítások → Ügyfélportál (csak manage_options) */

if (!defined('ABSPATH')) { exit; }

add_action('admin_menu', function () {
	add_options_page('Ügyfélportál', 'Ügyfélportál', 'manage_options', 'cp-portal', 'cp_portal_admin_page');
});

function cp_portal_admin_save() {
	if (!current_user_can('manage_options') || !isset($_POST['cp_portal_save'])) { return ''; }
	check_admin_referer('cp_portal_save');
	$in = wp_unslash($_POST);
	$o = cp_portal_opts();
	$msg = array();
	$mode = isset($in['mode']) ? (string) $in['mode'] : 'off';
	$o['mode'] = $mode === 'on' ? 'on' : 'off';
	$o['auto_create'] = empty($in['auto_create']) ? 0 : 1;
	$o['auto_scope'] = isset($in['auto_scope']) && $in['auto_scope'] === 'all' ? 'all' : 'selected';
	$o['sent_stage_write'] = empty($in['sent_stage_write']) ? 0 : 1;
	$o['deals'] = implode("\n", array_keys(cp_portal_parse_ids(isset($in['deals']) ? $in['deals'] : '')));
	$bu = esc_url_raw(trim(isset($in['base_url']) ? (string) $in['base_url'] : ''), array('https', 'http'));
	if ($bu !== '' && wp_parse_url($bu, PHP_URL_HOST)) { $o['base_url'] = untrailingslashit($bu); } else { $msg[] = 'A portál címe érvénytelen, nem mentettem.'; }
	foreach (array('trigger_stage', 'sent_stage', 'sm_f_venue', 'sm_f_status', 'pumble_mention', 'mail_from_name') as $k) {
		$o[$k] = isset($in[$k]) ? sanitize_text_field($in[$k]) : '';
	}
	$o['sm_status_labels'] = isset($in['sm_status_labels']) ? sanitize_textarea_field($in['sm_status_labels']) : '';
	$o['bg_exclude'] = isset($in['bg_exclude']) ? sanitize_textarea_field($in['bg_exclude']) : '';
	foreach (array('mail_from', 'reply_to', 'test_inbox') as $k) {
		$v = isset($in[$k]) ? sanitize_email($in[$k]) : '';
		if (isset($in[$k]) && trim($in[$k]) !== '' && $v === '') { $msg[] = 'Érvénytelen e-mail-cím (' . $k . '), nem mentettem.'; continue; }
		$o[$k] = $v;
	}
	$o['cron_fallback'] = empty($in['cron_fallback']) ? 0 : 1;
	$o['sm_notes'] = empty($in['sm_notes']) ? 0 : 1;
	$pu = isset($in['pumble_url']) ? trim((string) $in['pumble_url']) : '';
	if (!empty($in['pumble_clear'])) { $o['pumble_url'] = ''; }
	elseif ($pu !== '') {
		if (preg_match('#^https://api\.pumble\.com/[A-Za-z0-9/_.\-]+$#', $pu)) { $o['pumble_url'] = $pu; } else { $msg[] = 'A Pumble webhook címe nem https://api.pumble.com/… kezdetű, nem mentettem.'; }
	}
	if (!empty($in['webhook_regen'])) { $o['webhook_key'] = wp_generate_password(32, false, false); $msg[] = 'Új webhook-kulcs készült – a Salesmate-ben is cseréld le.'; }
	update_option(CP_PORTAL_OPT, $o, false);

	if (!empty($in['test_mail'])) {
		$to = sanitize_email($o['test_inbox'] !== '' ? $o['test_inbox'] : get_option('admin_email'));
		$r = cp_portal_mail($to, 'Ügyfélportál – teszt levél', '<p>Ez egy teszt levél az ügyfélportálból. Ha megkaptad, a levélküldés működik.</p>');
		$msg[] = is_wp_error($r) ? 'Teszt levél: HIBA – ' . $r->get_error_message() : 'Teszt levél elküldve: ' . $r;
	}
	if (!empty($in['test_pumble'])) {
		$r = cp_portal_pumble('✅ Ügyfélportál – teszt üzenet. Ide érkeznek a hiányos és a határidő után módosított adatlapok.');
		$msg[] = is_wp_error($r) ? 'Pumble teszt: HIBA – ' . $r->get_error_message() : 'Pumble teszt üzenet elküldve.';
	}
	if (!empty($in['run_tick'])) { delete_transient('cp_portal_tick_lock'); cp_portal_tick(); $msg[] = 'Az értesítések futása lefutott.'; }
	return 'Mentve.' . ($msg ? ' ' . implode(' ', $msg) : '');
}

function cp_portal_parse_ids($s) {
	$out = array();
	foreach (preg_split('/[\s,;]+/', (string) $s) as $id) {
		$id = preg_replace('/[^0-9]/', '', $id);
		if ($id !== '') { $out[$id] = 1; }
	}
	return $out;
}

function cp_portal_admin_page() {
	if (!current_user_can('manage_options')) { return; }
	$saved = cp_portal_admin_save();
	$o = cp_portal_opts();
	$p = cp_panel_opts();
	$fmt = function ($t) { return $t ? wp_date('Y.m.d. H:i', (int) $t) : '–'; };
	echo '<div class="wrap"><h1>Ügyfélportál (1. fázis)</h1>';
	if ($saved !== '') { echo '<div class="notice notice-success"><p>' . esc_html($saved) . '</p></div>'; }

	// állapot
	$missing = cp_portal_db_ready() ? array() : cp_portal_db_missing_tables();
	$err = get_option('cp_portal_db_error', null);
	echo '<h2>Állapot</h2><table class="widefat striped" style="max-width:900px"><tbody>';
	cp_portal_admin_row('Adatbázis', cp_portal_db_ready() ? '✓ táblák rendben (séma v' . esc_html(CP_PORTAL_DB_VER) . ')' : '<b style="color:#b32d2e">hiányzó táblák: ' . esc_html(implode(', ', $missing)) . '</b>' . ($err ? '<br><small>' . esc_html(wp_json_encode($err)) . '</small>' : ''));
	cp_portal_admin_row('Mód', cp_portal_mode() === 'on' ? '<b>Bekapcsolva</b> – a panelből kiküldhető' . (!empty($o['auto_create']) ? '; automatikus létrehozás is (' . ($o['auto_scope'] === 'all' ? 'mindenki' : 'kiválasztott dealek') . ')' : '; automatikus létrehozás kikapcsolva') : '<b>Kikapcsolva</b> – nem jön létre új portál, nem mennek ki levelek');
	cp_portal_admin_row('Ütemezés', 'értesítések: ' . esc_html($fmt(wp_next_scheduled('cp_portal_tick'))) . ' · tartalék: ' . esc_html($fmt(wp_next_scheduled('cp_portal_hourly'))) . ' · adattörlés: ' . esc_html($fmt(wp_next_scheduled('cp_portal_daily'))) . '<br>utolsó futás: ' . esc_html($fmt(get_option('cp_portal_tick_last'))) . ' · DISABLE_WP_CRON: ' . (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'be (szerver-cron kell)' : 'ki (WP-Cron, látogatáskor fut)'));
	$wl = get_option('cp_portal_webhook_last', null);
	$sl = get_option('cp_portal_scan_last', null);
	cp_portal_admin_row('Salesmate', 'utolsó webhook: ' . ($wl ? esc_html($fmt($wl['t']) . ' · deal ' . ($wl['deal'] !== '' ? $wl['deal'] : '?')) : '–') . '<br>utolsó tartalék-futás: ' . ($sl ? esc_html($fmt($sl['t']) . (isset($sl['err']) ? ' · hiba: ' . $sl['err'] : ' · ' . $sl['seen'] . ' deal a szakaszban, ' . $sl['created'] . ' új portál')) : '–'));
	$errs = cp_portal_errors();
	cp_portal_admin_row('Hibalista', $errs ? count($errs) . ' bejegyzés – legutóbbi: ' . esc_html($fmt($errs[0]['t']) . ' · deal ' . $errs[0]['deal'] . ' · ' . $errs[0]['k'] . ': ' . $errs[0]['m']) : 'üres');
	$rl = get_option('cp_portal_retention_log', array());
	cp_portal_admin_row('Adattörlés', 'utolsó futás: ' . esc_html($fmt(get_option('cp_portal_retention_last'))) . (is_array($rl) && $rl ? '<br><small>' . implode('<br>', array_map(function ($e) use ($fmt) { return esc_html($fmt($e['t']) . ' · ' . $e['w'] . ' · portál #' . $e['p'] . ' · ' . $e['n']); }, array_slice($rl, 0, 8))) . '</small>' : ''));
	echo '</tbody></table>';

	echo '<form method="post">';
	wp_nonce_field('cp_portal_save');
	echo '<h2>Bekapcsolás</h2><table class="form-table">';
	$radio = function ($v, $l) use ($o) { return '<label style="display:block;margin:4px 0"><input type="radio" name="mode" value="' . esc_attr($v) . '"' . checked(cp_portal_mode(), $v, false) . '> ' . $l . '</label>'; };
	cp_portal_admin_row2('Mód', $radio('off', '<b>Kikapcsolva</b> – a meglévő adatok és portálok megmaradnak, új portál és levél nincs') . $radio('on', '<b>Bekapcsolva</b> – a panelben a dealnél (Követés fül, „Adatlap” cella) az „Ügyfélportál kiküldése” gombbal küldhető ki, a szerződés előtt'));
	cp_portal_admin_row2('„Adatlap kiküldve” szakasz', '<input name="sent_stage" class="regular-text" value="' . esc_attr($o['sent_stage']) . '"> <label><input type="checkbox" name="sent_stage_write" value="1"' . checked(!empty($o['sent_stage_write']), true, false) . '> kiküldéskor a deal ide kerül a Salesmate-ben</label><p class="description">Pontosan a Salesmate-beli szakasz neve (a „' . esc_html($p['pipeline']) . '” pipeline-ban). Csak ajánlati szakaszból („' . esc_html(implode('”, „', cp_panel_lines($p['pending_stages']))) . '”) lép előre; aláírt szerződésnél nem nyúl hozzá.</p>');
	cp_portal_admin_row2('Automatikus létrehozás', '<label><input type="checkbox" name="auto_create" value="1"' . checked(!empty($o['auto_create']), true, false) . '> a régi működés: portál a trigger-szakasznál (webhook, óránkénti tartalék, panel „Szerződés” pipa)</label><br><label><input type="radio" name="auto_scope" value="selected"' . checked($o['auto_scope'] !== 'all', true, false) . '> csak a kiválasztott dealeknél</label> <label><input type="radio" name="auto_scope" value="all"' . checked($o['auto_scope'], 'all', false) . '> mindenkinél</label><p class="description">Alapból kikapcsolva: a portál a panelből, kézzel megy ki a szerződés előtt.</p>');
	cp_portal_admin_row2('Kiválasztott dealek', '<textarea name="deals" rows="3" class="large-text code" placeholder="Salesmate deal-azonosítók, soronként (csak az automatikus létrehozáshoz)">' . esc_textarea($o['deals']) . '</textarea>');
	cp_portal_admin_row2('Portál címe', '<input name="base_url" class="regular-text" value="' . esc_attr($o['base_url']) . '"><p class="description">Alapból <code>https://portal.eventcrazy.hu</code>. Amíg az aldomain nincs kész, tesztelhető útvonallal is, pl. <code>' . esc_html(home_url('/ugyfelportal')) . '</code>.</p>');
	cp_portal_admin_row2('Trigger-szakasz', '<input name="trigger_stage" class="regular-text" value="' . esc_attr($o['trigger_stage']) . '"><p class="description">Csak az automatikus létrehozáshoz: ebbe a Salesmate-szakaszba lépéskor jön létre a portál (a „' . esc_html($p['pipeline']) . '” pipeline-ban).</p>');
	echo '</table>';

	echo '<h2>Salesmate</h2><table class="form-table">';
	$hook = rest_url('crazy/v1/salesmate/deal-stage') . '?key=' . rawurlencode($o['webhook_key']);
	cp_portal_admin_row2('Webhook címe', '<input readonly class="large-text code" value="' . esc_attr($hook) . '" onclick="this.select()"><p class="description">Salesmate → Settings → Webhooks: deal frissítésekor (szakaszváltáskor) POST erre a címre. A kulcs titkos. <label><input type="checkbox" name="webhook_regen" value="1"> új kulcs generálása</label></p>');
	cp_portal_admin_row2('Óránkénti tartalék', '<label><input type="checkbox" name="cron_fallback" value="1"' . checked(!empty($o['cron_fallback']), true, false) . '> ha a webhook nem jön meg, óránként megnézi a trigger-szakaszban lévő dealeket (futásonként legfeljebb 10 új portál)</label>');
	cp_portal_admin_row2('„Rendezvény helyszíne” mező', '<input name="sm_f_venue" class="regular-text code" value="' . esc_attr($o['sm_f_venue']) . '" placeholder="' . esc_attr($p['f_loc']) . '"><p class="description">Üresen a panel „Helyszín” mezője (<code>' . esc_html($p['f_loc']) . '</code>). Ide íródik vissza a portálon megadott helyszín.</p>');
	cp_portal_admin_row2('„Adatlap állapota” mező', '<input name="sm_f_status" class="regular-text code" value="' . esc_attr($o['sm_f_status']) . '" placeholder="pl. textCustomField20"><p class="description">A Salesmate-ben előbb létre kell hozni; üresen nincs visszaírás.</p><textarea name="sm_status_labels" rows="3" class="regular-text code">' . esc_textarea($o['sm_status_labels']) . '</textarea><p class="description">A beírt értékek (legördülő mezőnél pontosan a Salesmate-opciók nevei).</p>');
	cp_portal_admin_row2('Jegyzet a dealhez', '<label><input type="checkbox" name="sm_notes" value="1"' . checked(!empty($o['sm_notes']), true, false) . '> „Adatlap kitöltve” / „Adatlap módosítva a határidő után” jegyzet</label><p class="description"><b>Ellenőrizendő:</b> a Salesmate jegyzet-végpontját teszt-dealen ki kell próbálni, addig maradjon kikapcsolva (a hibák a hibalistába kerülnek).</p>');
	echo '</table>';

	echo '<h2>Háttérválasztás</h2><table class="form-table">';
	cp_portal_admin_row2('Nem választható hátterek', '<textarea name="bg_exclude" rows="3" class="regular-text">' . esc_textarea($o['bg_exclude']) . '</textarea><p class="description">Soronként egy háttér neve (ahogy a panel háttérlistájában szerepel) – ezek nem jelennek meg az ügyfélportálon.</p>');
	echo '</table>';
	echo '<h2>Értesítések</h2><table class="form-table">';
	$pset = trim((string) $o['pumble_url']) !== '';
	cp_portal_admin_row2('Pumble webhook', '<input type="password" name="pumble_url" value="" class="large-text" autocomplete="new-password" placeholder="' . esc_attr($pset ? 'beállítva – csak cseréhez írd be az újat' : 'https://api.pumble.com/workspaces/…/incomingWebhooks/postMessage/…') . '">' . ($pset ? '<p><code>' . esc_html(substr($o['pumble_url'], 0, 31)) . '…' . esc_html(substr($o['pumble_url'], -4)) . '</code> <label><input type="checkbox" name="pumble_clear" value="1"> törlés</label></p>' : '') . '<p class="description">Külön a kezelők setup-fotós webhookjától: ide mennek a hiányos (határidő utáni) és a késői módosítás értesítések.</p>');
	cp_portal_admin_row2('Késői módosításnál említés', '<input name="pumble_mention" class="regular-text" value="' . esc_attr($o['pumble_mention']) . '">');
	cp_portal_admin_row2('Feladó', '<input name="mail_from_name" class="regular-text" value="' . esc_attr($o['mail_from_name']) . '" placeholder="név"> <input name="mail_from" class="regular-text" value="' . esc_attr($o['mail_from']) . '" placeholder="e-mail (üresen a WordPress alapértelmezése)">');
	cp_portal_admin_row2('Válaszcím', '<input name="reply_to" class="regular-text" value="' . esc_attr($o['reply_to']) . '" placeholder="pl. iroda@eventcrazy.hu"><p class="description">Erre válaszol az ügyfél, és ez jelenik meg „írj nekünk” címként a portálon.</p>');
	cp_portal_admin_row2('Teszt-postafiók', '<input name="test_inbox" class="regular-text" value="' . esc_attr($o['test_inbox']) . '"><p class="description"><b>Ha meg van adva, minden ügyfél-levél ide megy</b> (a tárgyban az eredeti címzettel). Bevezetéskor ajánlott.</p>');
	echo '</table>';
	echo '<p><button class="button button-primary" name="cp_portal_save" value="1">Mentés</button> ';
	echo '<button class="button" name="cp_portal_save" value="1" onclick="this.form.test_mail.value=1">Mentés + teszt levél</button> ';
	echo '<button class="button" name="cp_portal_save" value="1" onclick="this.form.test_pumble.value=1">Mentés + Pumble teszt</button> ';
	echo '<button class="button" name="cp_portal_save" value="1" onclick="this.form.run_tick.value=1">Mentés + értesítések futtatása most</button>';
	echo '<input type="hidden" name="test_mail" value=""><input type="hidden" name="test_pumble" value=""><input type="hidden" name="run_tick" value=""></p>';
	echo '</form></div>';
}

function cp_portal_admin_row($label, $html) {
	echo '<tr><th style="width:180px">' . esc_html($label) . '</th><td>' . $html . '</td></tr>';
}

function cp_portal_admin_row2($label, $html) {
	echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . '</td></tr>';
}
