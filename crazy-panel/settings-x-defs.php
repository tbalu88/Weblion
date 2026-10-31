<?php
/* =====================================================================
 * v1.79: a WordPress-admin beállítóoldalainak mezőleírása a panel Beállítások fülére (settings-x.php)
 * A mentési szabályok a WordPress-oldalakéval azonosak. Szándékosan a WordPressben maradt
 * (a postafiók jelszavát egy idegen szerverre is ki lehetne vele küldeni, vagy ritka, nagy hatású):
 * postafiók- és SMTP-szerver címe, levél-import, a közös admin/irodai jelszó, a panel címe (slug).
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

/* hosszú futás (szinkron, teljes újraolvasás) a háttérben: a kérés azonnal visszatér */
add_action('cp_sx_bg', function ($job) {
	@set_time_limit(280);
	if ($job === 'crm_sync' && function_exists('cp_crm_sync')) { cp_crm_sync(true); }
	if ($job === 'st_sync' && function_exists('cp_st_sync')) { cp_st_sync(true); }
	if ($job === 'st_windsor' && function_exists('cp_st_windsor_sync')) { cp_st_windsor_sync(true); }
	if ($job === 'portal_tick' && function_exists('cp_portal_tick')) { delete_transient('cp_portal_tick_lock'); cp_portal_tick(); }
});
function cp_sx_bg($job, $label) {
	if (!wp_next_scheduled('cp_sx_bg', array($job))) { wp_schedule_single_event(time(), 'cp_sx_bg', array($job)); }
	if (function_exists('spawn_cron')) { spawn_cron(); }
	return $label . ' elindult a háttérben – pár perc múlva frissítsd ezt az oldalt az eredményért.';
}
function cp_sx_dt($ts) { return $ts ? wp_date('Y.m.d. H:i', is_numeric($ts) ? (int) $ts : strtotime((string) $ts)) : '–'; }
function cp_sx_pumble($v) { return preg_match('#^https://api\.pumble\.com/[A-Za-z0-9/_.\-]+$#', (string) $v) ? (string) $v : new WP_Error('cp_sx', 'A Pumble webhook címe nem https://api.pumble.com/… kezdetű, ezért nem mentettem.'); }
function cp_sx_regex($def) { return function ($v) use ($def) { return @preg_match('/' . str_replace('/', '\/', (string) $v) . '/iu', '') === false ? $def : $v; }; }

add_filter('cp_sx_sections', function ($S) {
	$P = defined('CP_PANEL_OPT') ? CP_PANEL_OPT : 'cp_panel_settings';
	$fk = '/[^A-Za-z0-9_]/';

	/* ---------------- Salesmate-kapcsolat (Crazy CRM + a Foglaltsági panel Salesmate-része) */
	if (function_exists('cp_crm_state')) {
		$S['crm'] = array('n' => 'Salesmate-kapcsolat', 'wp' => 'cp-crm',
			'd' => 'A panel 10 percenként a saját tábláiba másolja a Salesmate adatait, a fülek ezekből olvasnak. A hozzáférési kulcs a Salesmate → Beállítások → Access Key oldalon van.',
			'info' => function () {
				$st = cp_crm_state(); $o = array();
				$live = function_exists('cp_crm_source_on') && cp_crm_source_on();
				$o[] = array('A fülek adatforrása', $live ? 'a panel saját táblái' : (get_option('cp_crm_source', '1') === '1' ? 'átmenetileg a Salesmate (a szinkron 30 percnél régebben futott, vagy az áthozatal tart)' : 'a Salesmate (kikapcsolva)'), $live ? 'ok' : 'bad');
				$o[] = array('Utolsó szinkron', cp_sx_dt($st['last_run'] ?? 0) . (isset($st['last_calls']) ? ' · ' . (int) $st['last_calls'] . ' API-hívás' : ''), '');
				foreach (array('deal' => 'Deal-ek', 'contact' => 'Kontaktok', 'company' => 'Cégek', 'activity' => 'Tevékenységek') as $m => $l) {
					if (!isset($st[$m . '_n']) && !isset($st[$m . '_sm'])) { continue; }
					$o[] = array($l, (int) ($st[$m . '_n'] ?? 0) . ' a panelben / ' . (int) ($st[$m . '_sm'] ?? 0) . ' a Salesmate-ben' . (!empty($st[$m . '_redo']) ? ' · teljes újraolvasás folyamatban' : ''), '');
				}
				if (!empty($st['last_err'])) { $o[] = array('Utolsó hiba', cp_sx_dt($st['err_at'] ?? 0) . ' – ' . $st['last_err'], 'bad'); }
				return $o;
			},
			'fields' => array(
				array('k' => 'src', 't' => 'sw', 'l' => 'A fülek a panel saját tábláiból olvassanak (gyorsabb; kikapcsolva közvetlenül a Salesmate-ből)', 'get' => function () { return get_option('cp_crm_source', '1') === '1'; }, 'set' => function ($v) { update_option('cp_crm_source', $v ? '1' : '0', false); if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); } }),
				array('k' => 'token', 't' => 'secret', 'l' => 'Salesmate Access Key', 'g' => 'Hozzáférés', 'opt' => $P, 'sub' => 'token', 'ph' => 'Salesmate → Beállítások → Access Key'),
				array('k' => 'linkname', 't' => 'text', 'l' => 'Salesmate-fiók címe', 'g' => 'Hozzáférés', 'opt' => $P, 'sub' => 'linkname', 'def' => 'studioframes.salesmate.io'),
				array('k' => 'pipeline', 't' => 'text', 'l' => 'Pipeline neve', 'g' => 'Hozzáférés', 'opt' => $P, 'sub' => 'pipeline'),
				array('k' => 'cache_min', 't' => 'num', 'l' => 'Gyorsítótár (perc)', 'g' => 'Hozzáférés', 'opt' => $P, 'sub' => 'cache_min', 'min' => 0, 'int' => 1),
				array('k' => 'search_mode', 't' => 'sel', 'l' => 'Keresési mód', 'g' => 'Hozzáférés', 'opt' => $P, 'sub' => 'search_mode', 'o' => array(array('auto', 'automatikus'), array('after_before', 'után/előtt'), array('between', 'között'), array('between_str', 'között (szöveg)'), array('scan', 'végigolvasás'))),
				array('k' => 'f_date', 't' => 'text', 'l' => 'Rendezvény dátuma', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $P, 'sub' => 'f_date'),
				array('k' => 'f_time', 't' => 'text', 'l' => 'Kezdés időpontja', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $P, 'sub' => 'f_time'),
				array('k' => 'f_loc', 't' => 'text', 'l' => 'Helyszín', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $P, 'sub' => 'f_loc'),
				array('k' => 'f_guests', 't' => 'text', 'l' => 'Létszám', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $P, 'sub' => 'f_guests'),
				array('k' => 'f_bg', 't' => 'text', 'l' => 'Háttér', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $P, 'sub' => 'f_bg'),
				array('k' => 'f_selfie', 't' => 'text', 'l' => 'Szelfigép', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $P, 'sub' => 'f_selfie'),
				array('k' => 'f_spin', 't' => 'text', 'l' => 'Spinner', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $P, 'sub' => 'f_spin'),
				array('k' => 'f_note', 't' => 'text', 'l' => 'Megjegyzés', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $P, 'sub' => 'f_note'),
				array('k' => 'f_paid', 't' => 'text', 'l' => 'Fizetve', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $P, 'sub' => 'f_paid'),
				array('k' => 'f_hx', 't' => 'text', 'l' => 'Óraszám-bővítés', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $P, 'sub' => 'f_hx', 'strip' => $fk),
				array('k' => 'f_extra', 't' => 'area', 'l' => 'További megjelenített mezők (soronként: mezőnév=Felirat)', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $P, 'sub' => 'f_extra', 'rows' => 4),
			),
			'after' => function () { if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); } },
			'acts' => array(
				array('k' => 'sync', 'l' => 'Szinkron most', 'fn' => function () { return cp_sx_bg('crm_sync', 'A szinkron'); }),
				array('k' => 'full', 'l' => 'Teljes újraolvasás', 'c' => 'Minden adatot újraolvas a Salesmate-ből (több kör, kb. fél óra). Indíthatom?', 'fn' => function () {
					$st = cp_crm_state();
					foreach (cp_crm_mods() as $m => $x) { $st[$m . '_redo'] = 1; $st[$m . '_run'] = gmdate('Y-m-d H:i:s'); unset($st[$m . '_cur'], $st[$m . '_span']); }
					cp_crm_state_set($st);
					if (!wp_next_scheduled('cp_crm_more')) { wp_schedule_single_event(time() + 30, 'cp_crm_more'); }
					return 'A teljes újraolvasás elindul a következő körökben.';
				}),
				array('k' => 'ping', 'l' => 'Kapcsolatpróba', 'fn' => function () { return array('msg' => 'Lefutott.', 'out' => function_exists('cp_panel_diag') ? (string) cp_panel_diag('ping') : ''); }),
				array('k' => 'flush', 'l' => 'Gyorsítótár ürítése', 'fn' => function () { if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); } delete_transient('cp_panel_webbg'); return 'A gyorsítótár kiürítve.'; }),
			),
		);
	}

	/* ---------------- Kapacitás, szakaszok, hátterek (Foglaltsági panel) */
	$S['core'] = array('n' => 'Kapacitás, szakaszok', 'wp' => 'cp-panel',
		'd' => 'A Kapacitás fül és a foglaltság-számítás beállításai. A szakaszneveket pontosan úgy írd, ahogy a Salesmate-ben vannak, soronként egyet.',
		'info' => function () {
			$o = array(); $w = function_exists('cp_panel_web_bgs') ? cp_panel_web_bgs() : array();
			if ($w) { $o[] = array('Háttérlista a weboldalról', (isset($w['list']) ? count((array) $w['list']) . ' háttér' : '–') . (!empty($w['at']) ? ' · ' . cp_sx_dt($w['at']) : ''), empty($w['err']) ? 'ok' : 'bad'); if (!empty($w['err'])) { $o[] = array('Hiba', (string) $w['err'], 'bad'); } }
			return $o;
		},
		'fields' => array(
			array('k' => 'cap_selfie', 't' => 'num', 'l' => 'Szelfigépek száma (napi kapacitás)', 'g' => 'Kapacitás', 'opt' => $P, 'sub' => 'cap_selfie', 'min' => 0, 'int' => 1),
			array('k' => 'cap_spin', 't' => 'num', 'l' => 'Spinnerek száma', 'g' => 'Kapacitás', 'opt' => $P, 'sub' => 'cap_spin', 'min' => 0, 'int' => 1),
			array('k' => 'subs_default', 't' => 'num', 'l' => 'Alvállalkozói gépek (alapérték)', 'g' => 'Kapacitás', 'opt' => $P, 'sub' => 'subs_default', 'min' => 0, 'int' => 1),
			array('k' => 'booked_stages', 't' => 'area', 'l' => 'Foglaltnak számító szakaszok', 'g' => 'Szakaszok', 'opt' => $P, 'sub' => 'booked_stages', 'rows' => 5),
			array('k' => 'pending_stages', 't' => 'area', 'l' => 'Függőben lévő szakaszok', 'g' => 'Szakaszok', 'opt' => $P, 'sub' => 'pending_stages', 'rows' => 3),
			array('k' => 'signed_stages', 't' => 'area', 'l' => 'Aláírt szerződésnek számító szakaszok', 'g' => 'Szakaszok', 'opt' => $P, 'sub' => 'signed_stages', 'rows' => 4),
			array('k' => 'bg_url', 't' => 'text', 'l' => 'Háttérlista oldala (weboldal)', 'g' => 'Hátterek', 'opt' => $P, 'sub' => 'bg_url', 'chk' => function ($v) { $u = esc_url_raw($v, array('https', 'http')); return $u !== '' ? $u : new WP_Error('cp_sx', 'Érvénytelen cím.'); }),
			array('k' => 'bg_write', 't' => 'sw', 'l' => 'A háttérfoglalás a Salesmate-be is beíródik', 'g' => 'Hátterek', 'opt' => $P, 'sub' => 'bg_write'),
			array('k' => 'reply_sign', 't' => 'area', 'l' => 'Válasz-aláírás (Kapacitás fül gyors válaszaihoz)', 'g' => 'Egyéb', 'opt' => $P, 'sub' => 'reply_sign', 'rows' => 3),
			array('k' => 'openai_key', 't' => 'secret', 'l' => 'OpenAI API-kulcs (képgenerálás)', 'g' => 'AI-képek (háttér-előnézet)', 'opt' => $P, 'sub' => 'openai_key', 'ph' => 'sk-…'),
			array('k' => 'ai_model', 't' => 'text', 'l' => 'Képmodell', 'g' => 'AI-képek (háttér-előnézet)', 'opt' => $P, 'sub' => 'ai_model', 'strip' => '/[^A-Za-z0-9._-]/'),
			array('k' => 'ai_quality', 't' => 'sel', 'l' => 'Minőség', 'g' => 'AI-képek (háttér-előnézet)', 'opt' => $P, 'sub' => 'ai_quality', 'o' => array(array('low', 'alacsony'), array('medium', 'közepes'), array('high', 'magas'), array('auto', 'automatikus'))),
			array('k' => 'ai_daily', 't' => 'num', 'l' => 'Napi keret (kép; 0 = korlátlan)', 'g' => 'AI-képek (háttér-előnézet)', 'opt' => $P, 'sub' => 'ai_daily', 'min' => 0, 'int' => 1),
		),
		'after' => function () { delete_transient('cp_panel_webbg'); if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); } },
		'acts' => array(
			array('k' => 'webbg', 'l' => 'Háttérlista frissítése a weboldalról', 'fn' => function () { delete_transient('cp_panel_webbg'); $w = function_exists('cp_panel_web_bgs') ? cp_panel_web_bgs() : array(); return !empty($w['err']) ? new WP_Error('cp_sx', 'Hiba: ' . $w['err']) : 'Frissítve: ' . count((array) ($w['list'] ?? array())) . ' háttér.'; }),
		),
	);

	/* ---------------- Postafiók (beérkezés jelszava; a szervercím és az import a WordPressben) */
	if (function_exists('cp_imp_pw')) {
		$S['mailimp'] = array('n' => 'Postafiók', 'wp' => 'cp-mail-import',
			'd' => 'A beérkező és küldött levelek a postafiókból jönnek. A postafiók-szerver címe és a régi levelek importja biztonsági okból csak a WordPress-adminban állítható (a jelszót ott nem lehet idegen szerverre küldetni).',
			'info' => function () {
				$io = function_exists('cp_imp_opts') ? cp_imp_opts() : array(); $sy = get_option('cp_imp_sync', array()); $st = function_exists('cp_imp_state') ? cp_imp_state() : array();
				$o = array(array('Postafiók', ($io['user'] ?? '') . ' @ ' . ($io['host'] ?? '') . ':' . ($io['port'] ?? ''), ''), array('Beérkezés', get_option('cp_imp_sync_on') === '1' ? '5 percenként' : 'kikapcsolva', get_option('cp_imp_sync_on') === '1' ? 'ok' : 'bad'));
				$o[] = array('Utolsó beérkezés', cp_sx_dt($sy['last'] ?? 0) . (isset($sy['n']) ? ' · ' . (int) $sy['n'] . ' új levél' : ''), empty($sy['err']) ? '' : 'bad');
				if (!empty($sy['err'])) { $o[] = array('Hiba', (string) $sy['err'], 'bad'); }
				if ($st) { $c = (array) ($st['cnt'] ?? array()); $o[] = array('Régi levelek importja', (!empty($st['running']) ? 'fut' : (!empty($st['finished']) ? 'kész – ' . cp_sx_dt($st['finished']) : 'áll')) . ' · átvéve ' . (int) ($c['ok'] ?? 0), ''); }
				if (!function_exists('imap_open')) { $o[] = array('Szerver', 'az IMAP-bővítmény hiányzik', 'bad'); }
				return $o;
			},
			'fields' => array(
				array('k' => 'pw', 't' => 'secret', 'l' => 'Postafiók jelszava', 'has' => function () { return cp_imp_pw() !== ''; }, 'set' => function ($v) { cp_st_secret_set('imap_pw', $v); }),
			),
		);
	}

	/* ---------------- Google Naptár */
	if (function_exists('cp_gcal_opts')) {
		$S['gcal'] = array('n' => 'Google Naptár', 'wp' => 'cp-gcal',
			'd' => 'A Naptár fül és a „[Ceruzás]” jelölés a Google Naptárból dolgozik, szolgáltatásfiókkal. A kulcs a Google Cloud → szolgáltatásfiók → Kulcsok → JSON fájl; a teljes tartalmát másold be. A naptárat meg kell osztani a szolgáltatásfiók e-mail-címével („Események módosítása” joggal).',
			'info' => function () {
				$o = cp_gcal_opts(); $x = array(array('Szolgáltatásfiók', !empty($o['email']) ? $o['email'] : 'nincs kulcs', !empty($o['email']) ? 'ok' : 'bad'));
				$e = get_option('cp_gcal_werr'); if (is_array($e) && !empty($e['msg'])) { $x[] = array('Utolsó írási hiba', cp_sx_dt($e['at'] ?? 0) . ' – ' . $e['msg'], 'bad'); }
				$l = (array) get_option('cp_gcal_wlog', array()); if ($l) { $z = end($l); $x[] = array('Utolsó írás a naptárba', cp_sx_dt($z['at'] ?? 0) . ' · ' . count($l) . ' bejegyzés a naplóban', ''); }
				return $x;
			},
			'fields' => array(
				array('k' => 'cal', 't' => 'text', 'l' => 'Naptár azonosítója', 'opt' => 'cp_gcal', 'sub' => 'cal', 'ph' => '…@group.calendar.google.com'),
				array('k' => 'pen', 't' => 'sw', 'l' => '„[Ceruzás]” jelölés kezelése a „Szerződés kiküldve” szakaszban', 'opt' => 'cp_gcal', 'sub' => 'pen'),
				array('k' => 'key', 't' => 'secret', 'l' => 'Szolgáltatásfiók-kulcs (a JSON fájl teljes tartalma)', 'ph' => '{ "type": "service_account", … }', 'has' => function () { $k = get_option('cp_gcal_key'); return is_array($k) && !empty($k['pk']); },
					'set' => function ($raw) {
						if ($raw === '') { delete_option('cp_gcal_key'); delete_transient('cp_gcal_tok'); $o = cp_gcal_opts(); $o['email'] = ''; update_option('cp_gcal', $o, false); return; }
						$j = json_decode($raw, true);
						if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['client_email']) || empty($j['private_key'])) { return new WP_Error('cp_sx', 'Ez nem szolgáltatásfiók-kulcs (JSON). A Google Cloudban: szolgáltatásfiók → Kulcsok → Kulcs hozzáadása → JSON.'); }
						if (!function_exists('openssl_pkey_get_private') || !openssl_pkey_get_private($j['private_key'])) { return new WP_Error('cp_sx', 'A kulcsban lévő privát kulcs nem olvasható.'); }
						update_option('cp_gcal_key', array('email' => sanitize_email($j['client_email']), 'pk' => (string) $j['private_key'], 'uri' => esc_url_raw((string) ($j['token_uri'] ?? ''))), false);
						$o = cp_gcal_opts(); $o['email'] = sanitize_email($j['client_email']); update_option('cp_gcal', $o, false); delete_transient('cp_gcal_tok');
					}),
			),
			'acts' => array(
				array('k' => 'test', 'l' => 'Kapcsolatpróba', 'fn' => function () {
					delete_transient('cp_gcal_tok');
					$r = cp_gcal_ok() ? cp_gcal_req('GET', '/events', array('maxResults' => 250, 'singleEvents' => 'true', 'timeMin' => gmdate(DATE_RFC3339), 'timeMax' => gmdate(DATE_RFC3339, time() + 30 * DAY_IN_SECONDS))) : new WP_Error('cp_sx', 'Előbb add meg a kulcsot és a naptár azonosítóját.');
					return is_wp_error($r) ? $r : 'Rendben: a „' . sanitize_text_field((string) ($r['summary'] ?? '')) . '” naptár elérhető, a következő 30 napban ' . count((array) ($r['items'] ?? array())) . ' bejegyzés. Jogosultság: ' . (string) ($r['accessRole'] ?? '?') . '.';
				}),
			),
		);
	}

	/* ---------------- Számlázás (profilonként) */
	if (function_exists('cp_bil_profiles')) {
		foreach (cp_bil_profiles() as $p => $pn) {
			$szl = cp_bil_prov($p) === 'szamlazz';
			$opt = 'cp_bil_' . $p;
			$f = array(
				array('k' => 'mode', 't' => 'sel', 'l' => 'Működés', 'opt' => $opt, 'sub' => 'mode', 'o' => array(array('api', 'API – a panel állítja ki a bizonylatot'), array('manual', 'Kézi – a számlázóban állítod ki, a panel rögzíti')), 'def' => 'api'),
				array('k' => 'key', 't' => 'secret', 'l' => $szl ? 'Számla Agent kulcs' : 'Billingo API-kulcs', 'ph' => $szl ? 'Számlázz.hu → Beállítások → Számla Agent kulcs' : 'Billingo → Beállítások → API', 'has' => function () use ($p) { return cp_bil_key($p) !== ''; },
					'set' => function ($v) use ($p) { if ($v === '') { delete_option('cp_bil_key_' . $p); $o = cp_bil_opts($p); $o['blocks'] = array(); $o['banks'] = array(); $o['org'] = ''; update_option('cp_bil_' . $p, $o, false); return; } update_option('cp_bil_key_' . $p, preg_replace('/[^A-Za-z0-9\-_]/', '', $v), false); }),
				array('k' => 'vat', 't' => 'sel', 'l' => 'Alap ÁFA', 'opt' => $opt, 'sub' => 'vat', 'o' => array(array('27%', '27%'), array('18%', '18%'), array('5%', '5%'), array('0%', '0%'), array('AAM', 'AAM (alanyi adómentes)'), array('TAM', 'TAM (tárgyi adómentes)')), 'def' => $szl ? 'AAM' : '27%'),
				array('k' => 'due', 't' => 'num', 'l' => 'Fizetési határidő (nap)', 'opt' => $opt, 'sub' => 'due', 'min' => 0, 'max' => 90, 'int' => 1, 'def' => 8),
			);
			if ($szl) {
				foreach (array('prefix' => 'Számlaszám-előtag', 'bank_name' => 'Bank neve', 'bank_acc' => 'Bankszámlaszám') as $k => $l) { $f[] = array('k' => $k, 't' => 'text', 'l' => $l, 'opt' => $opt, 'sub' => $k, 'max_len' => 60); }
			} else {
				$f[] = array('k' => 'block', 't' => 'sel', 'l' => 'Számlatömb', 'opt' => $opt, 'sub' => 'block', 'o' => function () use ($p) { $o = cp_bil_opts($p); return array_merge(array(array('0', '– (Kapcsolatpróbával töltődik be)')), array_map(function ($b) { return array((string) $b['id'], $b['n']); }, (array) ($o['blocks'] ?? array()))); });
				$f[] = array('k' => 'bank', 't' => 'sel', 'l' => 'Bankszámla', 'opt' => $opt, 'sub' => 'bank', 'o' => function () use ($p) { $o = cp_bil_opts($p); return array_merge(array(array('0', '– (Kapcsolatpróbával töltődik be)')), array_map(function ($b) { return array((string) $b['id'], $b['n']); }, (array) ($o['banks'] ?? array()))); });
				$f[] = array('k' => 'photo', 't' => 'area', 'l' => 'Galériával együtt küldött számla – a szolgáltatás nevében ezek a szavak (soronként)', 'g' => 'Mindkét kiállítóra', 'opt' => 'cp_bil_g', 'sub' => 'photo', 'rows' => 6, 'def' => "fotó\nszelfi\nkarikatúra\nphoto\nautomata\nfotófal");
			}
			$S['bil_' . $p] = array('n' => 'Számlázás – ' . $pn, 'wp' => 'cp-billingo',
				'd' => $szl ? 'Egyéni vállalkozói számlák a Számlázz.hu Számla Agentjén át. Kézi módban a panel csak rögzíti a máshol kiállított számla számát.' : 'Kft.-s számlák, díjbekérők, előleg- és végszámlák a Billingón át. Kézi módban a panel csak rögzíti a Billingóban kiállított bizonylat számát. A számlatömb és a bankszámla a Kapcsolatpróba után választható.',
				'info' => function () use ($p, $szl) { $o = cp_bil_opts($p); $x = array(array('Kulcs', cp_bil_key($p) !== '' ? 'beállítva' : 'nincs', cp_bil_key($p) !== '' ? 'ok' : 'bad'), array('Működés', ($o['mode'] ?? 'api') === 'manual' ? 'kézi' : 'API', '')); if (!$szl && !empty($o['org'])) { $x[] = array('Billingo-fiók', $o['org'], 'ok'); } return $x; },
				'fields' => $f,
				'acts' => array(array('k' => 'test', 'l' => 'Kapcsolatpróba', 'fn' => function () use ($p) { $o = cp_bil_opts($p); list($m, $e) = cp_bil_test($p, $o); update_option('cp_bil_' . $p, $o, false); return $e !== '' ? new WP_Error('cp_sx', $e) : $m; })),
			);
		}
	}

	/* ---------------- Kezelői elszámolás */
	if (defined('CP_EL_OPT') && function_exists('cp_el_opts')) {
		$E = CP_EL_OPT;
		$S['el'] = array('n' => 'Kezelői elszámolás', 'wp' => 'cp-el',
			'd' => 'A Tárca és az Elszámolás fül beállításai. A kezelők listáját az Elszámolás fülön szerkeszted. A Google Apps Script kódja (a kezelői naptárhoz) a WordPress-oldalon látható.',
			'info' => function () { $c = get_option('cp_el_cal_at'); return is_array($c) ? array(array('Utolsó naptár-szinkron', cp_sx_dt($c['t'] ?? 0) . ' · ' . (int) ($c['n'] ?? 0) . ' tétel', '')) : array(); },
			'fields' => array(
				array('k' => 'calendar', 't' => 'text', 'l' => 'Naptár neve', 'opt' => $E, 'sub' => 'calendar'),
				array('k' => 'origin', 't' => 'text', 'l' => 'Kiindulópont a kiszállási távolsághoz (szélesség,hosszúság)', 'opt' => $E, 'sub' => 'origin', 'chk' => function ($v) { return preg_match('/^\s*-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?\s*$/', $v) ? preg_replace('/\s+/', '', $v) : new WP_Error('cp_sx', 'A kiindulópont formája: 47.4577,19.0424'); }),
				array('k' => 'gk_write', 't' => 'sw', 'l' => 'A gépkezelő a Salesmate-be is beíródik', 'opt' => $E, 'sub' => 'gk_write'),
				array('k' => 'h_write', 't' => 'sw', 'l' => 'Az óraszám a Salesmate-be is beíródik', 'opt' => $E, 'sub' => 'h_write'),
				array('k' => 'pumble_setup', 't' => 'sw', 'l' => 'A kezelők setup fotói menjenek a Pumble-be', 'g' => 'Pumble', 'opt' => $E, 'sub' => 'pumble_setup'),
				array('k' => 'pumble_url', 't' => 'secret', 'l' => 'Pumble webhook (setup fotók)', 'g' => 'Pumble', 'opt' => $E, 'sub' => 'pumble_url', 'ph' => 'https://api.pumble.com/…', 'chk' => 'cp_sx_pumble'),
				array('k' => 'skip_stages', 't' => 'area', 'l' => 'Kihagyott szakaszok', 'g' => 'Listák (soronként egy)', 'opt' => $E, 'sub' => 'skip_stages', 'rows' => 3),
				array('k' => 'payers', 't' => 'area', 'l' => 'Kifizetők', 'g' => 'Listák (soronként egy)', 'opt' => $E, 'sub' => 'payers', 'rows' => 3),
				array('k' => 'sat', 't' => 'area', 'l' => 'Ügyfél-elégedettség értékei', 'g' => 'Listák (soronként egy)', 'opt' => $E, 'sub' => 'sat', 'rows' => 3),
				array('k' => 'gm_opts', 't' => 'area', 'l' => 'Gép állapota értékei', 'g' => 'Listák (soronként egy)', 'opt' => $E, 'sub' => 'gm_opts', 'rows' => 3),
				array('k' => 'devices', 't' => 'area', 'l' => 'Eszközök', 'g' => 'Listák (soronként egy)', 'opt' => $E, 'sub' => 'devices', 'rows' => 8),
				array('k' => 'f_gk', 't' => 'text', 'l' => 'Gépkezelő', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $E, 'sub' => 'f_gk', 'strip' => $fk),
				array('k' => 'f_hours', 't' => 'text', 'l' => 'Óraszám', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $E, 'sub' => 'f_hours', 'strip' => $fk),
				array('k' => 'f_svc', 't' => 'text', 'l' => 'Szolgáltatás', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $E, 'sub' => 'f_svc', 'strip' => $fk),
				array('k' => 'f_svc2', 't' => 'text', 'l' => 'Szolgáltatások (többválasztós)', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $E, 'sub' => 'f_svc2', 'strip' => $fk),
				array('k' => 'f_start', 't' => 'text', 'l' => 'Kezdés', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $E, 'sub' => 'f_start', 'strip' => $fk),
			),
			'after' => function () { if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); } },
			'acts' => array(
				array('k' => 'pumble_test', 'l' => 'Pumble-teszt', 'fn' => function () { $o = cp_el_opts(); if (trim((string) $o['pumble_url']) === '') { return new WP_Error('cp_sx', 'Nincs megadva webhook.'); } $t = cp_el_pumble('✅ Crazy panel – teszt üzenet. Ide érkeznek majd a kezelők setup fotói.', $o['pumble_url']); return is_wp_error($t) ? $t : 'Pumble teszt üzenet elküldve.'; }),
				array('k' => 'feed_new', 'l' => 'Új naptár-kulcs', 'bad' => 1, 'c' => 'Új kulcs után a Google Apps Scriptben is le kell cserélni a kódot (WordPress-oldal), különben a kezelői naptár leáll. Biztosan?', 'fn' => function () { $o = get_option(CP_EL_OPT, array()); $o = array_merge(cp_el_opts(), is_array($o) ? $o : array()); $o['feed_key'] = wp_generate_password(24, false, false); update_option(CP_EL_OPT, $o, false); return 'Új kulcs készült – a Google Apps Scriptben is cseréld le a kódot (WordPress → Kezelői elszámolás).'; }),
			),
		);
	}

	/* ---------------- Ügyfélportál */
	if (defined('CP_PORTAL_OPT') && function_exists('cp_portal_opts')) {
		$Q = CP_PORTAL_OPT;
		$em = function ($v) { if ($v === '') { return ''; } $e = sanitize_email($v); return $e !== '' ? $e : new WP_Error('cp_sx', 'Érvénytelen e-mail-cím: ' . $v); };
		$S['portal'] = array('n' => 'Ügyfélportál', 'wp' => 'cp-portal',
			'd' => 'Az ügyfél a portálon tölti ki a rendezvény adatlapját. A Salesmate-webhook címe (benne a kulccsal) a WordPress-oldalon látható.',
			'info' => function () {
				$o = array(array('Működés', function_exists('cp_portal_mode') ? (cp_portal_mode() === 'on' ? 'bekapcsolva' : 'kikapcsolva') : '?', function_exists('cp_portal_mode') && cp_portal_mode() === 'on' ? 'ok' : 'bad'));
				if (function_exists('cp_portal_db_ready')) { $o[] = array('Adatbázis', cp_portal_db_ready() ? 'rendben' : 'hiányzó táblák', cp_portal_db_ready() ? 'ok' : 'bad'); }
				$o[] = array('Utolsó futás', cp_sx_dt(get_option('cp_portal_tick_last')), '');
				$w = get_option('cp_portal_webhook_last'); if (is_array($w)) { $o[] = array('Utolsó Salesmate-webhook', cp_sx_dt($w['t'] ?? 0) . (!empty($w['deal']) ? ' · deal #' . (int) $w['deal'] : ''), ''); }
				$e = function_exists('cp_portal_errors') ? (array) cp_portal_errors() : array(); if ($e) { $z = end($e); $o[] = array('Hibák', count($e) . ' · legutóbb ' . cp_sx_dt($z['t'] ?? 0) . ': ' . (string) ($z['m'] ?? ''), 'bad'); }
				return $o;
			},
			'fields' => array(
				array('k' => 'mode', 't' => 'sel', 'l' => 'Ügyfélportál', 'opt' => $Q, 'sub' => 'mode', 'o' => array(array('on', 'bekapcsolva'), array('off', 'kikapcsolva')), 'def' => 'off', 'get' => function () { return function_exists('cp_portal_mode') ? cp_portal_mode() : 'off'; }),
				array('k' => 'auto_create', 't' => 'sw', 'l' => 'Portál automatikus létrehozása a kiváltó szakasznál', 'opt' => $Q, 'sub' => 'auto_create'),
				array('k' => 'auto_scope', 't' => 'sel', 'l' => 'Melyik dealeknél', 'opt' => $Q, 'sub' => 'auto_scope', 'o' => array(array('selected', 'csak a lent felsoroltaknál'), array('all', 'minden dealnél')), 'def' => 'selected'),
				array('k' => 'deals', 't' => 'area', 'l' => 'Kiválasztott dealek (soronként egy sorszám)', 'opt' => $Q, 'sub' => 'deals', 'rows' => 4, 'chk' => function ($v) { return function_exists('cp_portal_parse_ids') ? implode("\n", array_keys(cp_portal_parse_ids($v))) : $v; }),
				array('k' => 'trigger_stage', 't' => 'text', 'l' => 'Kiváltó szakasz', 'opt' => $Q, 'sub' => 'trigger_stage'),
				array('k' => 'sent_stage', 't' => 'text', 'l' => 'Kiküldés utáni szakasz', 'opt' => $Q, 'sub' => 'sent_stage'),
				array('k' => 'sent_stage_write', 't' => 'sw', 'l' => 'Kiküldéskor a szakasz a Salesmate-ben is átáll', 'opt' => $Q, 'sub' => 'sent_stage_write'),
				array('k' => 'base_url', 't' => 'text', 'l' => 'A portál címe', 'g' => 'Megjelenés, levelek', 'opt' => $Q, 'sub' => 'base_url', 'chk' => function ($v) { $u = esc_url_raw($v, array('https', 'http')); return $u !== '' && wp_parse_url($u, PHP_URL_HOST) ? untrailingslashit($u) : new WP_Error('cp_sx', 'A portál címe érvénytelen, nem mentettem.'); }),
				array('k' => 'mail_from_name', 't' => 'text', 'l' => 'Feladó neve', 'g' => 'Megjelenés, levelek', 'opt' => $Q, 'sub' => 'mail_from_name'),
				array('k' => 'mail_from', 't' => 'text', 'l' => 'Feladó címe', 'g' => 'Megjelenés, levelek', 'opt' => $Q, 'sub' => 'mail_from', 'chk' => $em),
				array('k' => 'reply_to', 't' => 'text', 'l' => 'Válaszcím', 'g' => 'Megjelenés, levelek', 'opt' => $Q, 'sub' => 'reply_to', 'chk' => $em),
				array('k' => 'test_inbox', 't' => 'text', 'l' => 'Tesztlevél címzettje', 'g' => 'Megjelenés, levelek', 'opt' => $Q, 'sub' => 'test_inbox', 'chk' => $em),
				array('k' => 'bg_exclude', 't' => 'area', 'l' => 'A portálon nem választható hátterek', 'g' => 'Megjelenés, levelek', 'opt' => $Q, 'sub' => 'bg_exclude', 'rows' => 3),
				array('k' => 'pumble_url', 't' => 'secret', 'l' => 'Pumble webhook (hiányos / módosított adatlapok)', 'g' => 'Értesítések', 'opt' => $Q, 'sub' => 'pumble_url', 'ph' => 'https://api.pumble.com/…', 'chk' => 'cp_sx_pumble'),
				array('k' => 'pumble_mention', 't' => 'text', 'l' => 'Megemlített kolléga a Pumble-ben', 'g' => 'Értesítések', 'opt' => $Q, 'sub' => 'pumble_mention'),
				array('k' => 'sm_notes', 't' => 'sw', 'l' => 'Jegyzet a Salesmate-be is', 'g' => 'Értesítések', 'opt' => $Q, 'sub' => 'sm_notes'),
				array('k' => 'cron_fallback', 't' => 'sw', 'l' => 'Tartalék időzítés (ha a WordPress-cron nem fut)', 'g' => 'Értesítések', 'opt' => $Q, 'sub' => 'cron_fallback'),
				array('k' => 'sm_f_venue', 't' => 'text', 'l' => 'Helyszín mező (üres = a panelé)', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $Q, 'sub' => 'sm_f_venue'),
				array('k' => 'sm_f_status', 't' => 'text', 'l' => 'Adatlap-állapot mező', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $Q, 'sub' => 'sm_f_status'),
				array('k' => 'sm_status_labels', 't' => 'area', 'l' => 'Állapot-feliratok (kulcs=Felirat)', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $Q, 'sub' => 'sm_status_labels', 'rows' => 3),
			),
			'acts' => array(
				array('k' => 'test_mail', 'l' => 'Tesztlevél', 'fn' => function () { $o = cp_portal_opts(); $to = sanitize_email($o['test_inbox'] !== '' ? $o['test_inbox'] : get_option('admin_email')); $r = cp_portal_mail($to, 'Ügyfélportál – teszt levél', '<p>Ez egy teszt levél az ügyfélportálból. Ha megkaptad, a levélküldés működik.</p>'); return is_wp_error($r) ? $r : 'Teszt levél elküldve: ' . $r; }),
				array('k' => 'test_pumble', 'l' => 'Pumble-teszt', 'fn' => function () { $r = cp_portal_pumble('✅ Ügyfélportál – teszt üzenet. Ide érkeznek a hiányos és a határidő után módosított adatlapok.'); return is_wp_error($r) ? $r : 'Pumble teszt üzenet elküldve.'; }),
				array('k' => 'run_tick', 'l' => 'Értesítések futtatása most', 'fn' => function () { return cp_sx_bg('portal_tick', 'Az értesítések futása'); }),
			),
		);
	}

	/* ---------------- Statisztikák */
	if (function_exists('cp_st_opts')) {
		$T = CP_ST_OPT; $d = cp_st_defaults();
		$S['stat'] = array('n' => 'Statisztikák', 'wp' => 'cp-st',
			'd' => 'A Statisztika fül adatforrásai és számítási szabályai. A Google Ads-szkript kódja és új kulcsa a WordPress-oldalon kérhető.',
			'info' => function () {
				$i = function_exists('cp_st_sync_info') ? cp_st_sync_info() : array(); $s = cp_st_state();
				$o = array(array('Utolsó szinkron', cp_sx_dt($i['last'] ?? 0) . (isset($i['deals']) ? ' · ' . (int) $i['deals'] . ' deal' : ''), empty($i['err']) ? '' : 'bad'));
				if (!empty($i['err'])) { $o[] = array('Hiba', (string) $i['err'], 'bad'); }
				$o[] = array('Meta-hirdetések', !empty($s['meta_err']) ? 'hiba: ' . $s['meta_err'] : (!empty($s['meta_ok']) ? 'rendben – ' . cp_sx_dt($s['meta_ok']) : '–'), !empty($s['meta_err']) ? 'bad' : '');
				$o[] = array('LinkedIn (Windsor)', !empty($s['wli_err']) ? 'hiba: ' . $s['wli_err'] : (!empty($s['wli_ok']) ? 'rendben – ' . cp_sx_dt($s['wli_ok']) : '–'), !empty($s['wli_err']) ? 'bad' : '');
				$o[] = array('Google Ads', !empty($s['gads_at']) ? cp_sx_dt($s['gads_at']) . ' · ' . (int) ($s['gads_n'] ?? 0) . ' sor' : '–', '');
				$o[] = array('GA4', get_option('cp_st_ga_email') ?: 'nincs kulcs', get_option('cp_st_ga_email') ? 'ok' : '');
				return $o;
			},
			'fields' => array(
				array('k' => 'meta_token', 't' => 'secret', 'l' => 'Meta (Facebook) hozzáférési token', 'g' => 'Hirdetési adatforrások', 'has' => function () { return cp_st_has_secret('meta_token'); }, 'set' => function ($v) { cp_st_secret_set('meta_token', $v); if ($v !== '') { $st = cp_st_state(); unset($st['meta_at']); cp_st_state_set($st); } }),
				array('k' => 'meta_act', 't' => 'text', 'l' => 'Meta hirdetési fiók(ok) azonosítója (vesszővel)', 'g' => 'Hirdetési adatforrások', 'opt' => $T, 'sub' => 'meta_act', 'strip' => '/[^0-9,\s]/'),
				array('k' => 'meta_ver', 't' => 'text', 'l' => 'Meta API-verzió', 'g' => 'Hirdetési adatforrások', 'opt' => $T, 'sub' => 'meta_ver'),
				array('k' => 'windsor_key', 't' => 'secret', 'l' => 'Windsor.ai API-kulcs (LinkedIn)', 'g' => 'Hirdetési adatforrások', 'has' => function () { return cp_st_has_secret('windsor_key'); }, 'set' => function ($v) { cp_st_secret_set('windsor_key', $v); if ($v !== '') { $st = cp_st_state(); unset($st['wli_at']); cp_st_state_set($st); } }),
				array('k' => 'li_act', 't' => 'text', 'l' => 'LinkedIn hirdetési fiók(ok) (vesszővel)', 'g' => 'Hirdetési adatforrások', 'opt' => $T, 'sub' => 'li_act', 'strip' => '/[^0-9,\s]/'),
				array('k' => 'ga_json', 't' => 'secret', 'l' => 'GA4 szolgáltatásfiók-kulcs (JSON teljes tartalma)', 'g' => 'Hirdetési adatforrások', 'ph' => '{ "type": "service_account", … }', 'has' => function () { return cp_st_has_secret('ga_secret_json'); },
					'set' => function ($raw) {
						if ($raw === '') { cp_st_secret_set('ga_secret_json', ''); delete_option('cp_st_ga_email'); delete_transient('cp_st_ga_at'); return; }
						$k = json_decode($raw, true);
						if (!is_array($k) || empty($k['client_email']) || empty($k['private_key']) || (isset($k['type']) && $k['type'] !== 'service_account')) { return new WP_Error('cp_sx', 'A JSON-kulcs érvénytelen (szolgáltatásfiók kulcs kell, client_email + private_key).'); }
						cp_st_secret_set('ga_secret_json', wp_json_encode(array('client_email' => $k['client_email'], 'private_key' => $k['private_key'])));
						update_option('cp_st_ga_email', sanitize_email($k['client_email']), false); delete_transient('cp_st_ga_at');
					}),
				array('k' => 'ga_prop', 't' => 'area', 'l' => 'GA4 tulajdonságok (soronként: név = azonosító)', 'g' => 'Hirdetési adatforrások', 'opt' => $T, 'sub' => 'ga_prop', 'rows' => 3),
				/* árfolyam-változáskor az EUR-os költések újraszámolása (a mentés után, az 'after'-ben) */
				array('k' => 'eur_huf', 't' => 'num', 'l' => 'EUR–HUF árfolyam (a költések átszámításához)', 'g' => 'Számítás', 'opt' => $T, 'sub' => 'eur_huf', 'min' => 0, 'max' => 10000, 'step' => 0.01,
					'chk' => function ($v) { $o = get_option(CP_ST_OPT, array()); $GLOBALS['cp_sx_eur_old'] = (float) (is_array($o) && isset($o['eur_huf']) ? $o['eur_huf'] : cp_st_defaults()['eur_huf']); return round((float) $v, 4); }),
				array('k' => 'paper_ft', 't' => 'num', 'l' => 'Fotópapír ára (Ft / kép)', 'g' => 'Számítás', 'opt' => $T, 'sub' => 'paper_ft', 'min' => 0, 'int' => 1),
				array('k' => 'ai_pack_ft', 't' => 'num', 'l' => 'AI-kreditcsomag ára (Ft)', 'g' => 'Számítás', 'opt' => $T, 'sub' => 'ai_pack_ft', 'min' => 0, 'int' => 1),
				array('k' => 'ai_pack_cr', 't' => 'num', 'l' => 'AI-kreditcsomag mérete (kredit)', 'g' => 'Számítás', 'opt' => $T, 'sub' => 'ai_pack_cr', 'min' => 0, 'int' => 1),
				array('k' => 'ai_cr_img', 't' => 'num', 'l' => 'Kredit / AI-kép', 'g' => 'Számítás', 'opt' => $T, 'sub' => 'ai_cr_img', 'min' => 0, 'int' => 1),
				array('k' => 'ai_re', 't' => 'text', 'l' => 'AI-szolgáltatás felismerése (minta)', 'g' => 'Számítás', 'opt' => $T, 'sub' => 'ai_re', 'chk' => cp_sx_regex($d['ai_re'])),
				array('k' => 'busy_re', 't' => 'text', 'l' => '„Nincs kapacitás” elvesztési ok felismerése (minta)', 'g' => 'Számítás', 'opt' => $T, 'sub' => 'busy_re', 'chk' => cp_sx_regex($d['busy_re'])),
				array('k' => 'offer_from', 't' => 'text', 'l' => 'Ajánlatnak számít ettől a szakasztól', 'g' => 'Számítás', 'opt' => $T, 'sub' => 'offer_from'),
				array('k' => 'ctype_biz', 't' => 'text', 'l' => 'Céges ügyfél jelölése', 'g' => 'Számítás', 'opt' => $T, 'sub' => 'ctype_biz'),
				array('k' => 'ctype_priv', 't' => 'text', 'l' => 'Magánügyfél jelölése', 'g' => 'Számítás', 'opt' => $T, 'sub' => 'ctype_priv'),
				array('k' => 'rules', 't' => 'area', 'l' => 'Csatornaszabályok', 'g' => 'Csatornák, szolgáltatások', 'opt' => $T, 'sub' => 'rules', 'rows' => 10),
				array('k' => 'svc_alias', 't' => 'area', 'l' => 'Szolgáltatásnév-összevonások', 'g' => 'Csatornák, szolgáltatások', 'opt' => $T, 'sub' => 'svc_alias', 'rows' => 4),
				array('k' => 'f_svc', 't' => 'text', 'l' => 'Szolgáltatás', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $T, 'sub' => 'f_svc', 'strip' => $fk),
				array('k' => 'f_ctype', 't' => 'text', 'l' => 'Szerződés típusa', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $T, 'sub' => 'f_ctype', 'strip' => $fk),
				array('k' => 'c_evtype', 't' => 'text', 'l' => 'Rendezvény típusa (kontakt)', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $T, 'sub' => 'c_evtype', 'strip' => $fk),
				array('k' => 'c_ad', 't' => 'text', 'l' => 'Hirdetés forrása (kontakt)', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $T, 'sub' => 'c_ad', 'strip' => $fk),
				array('k' => 'f_req', 't' => 'area', 'l' => 'Kért szolgáltatások mezői (soronként)', 'g' => 'Salesmate-mezők (belső mezőnevek)', 'opt' => $T, 'sub' => 'f_req', 'rows' => 2),
			),
			/* a cp_st_opts() kérésen belül gyorsítótáraz, ezért az új árfolyam közvetlenül az opcióból */
			'after' => function () {
				$o = get_option(CP_ST_OPT, array()); $new = max(0, (float) ($o['eur_huf'] ?? 0));
				if (!isset($GLOBALS['cp_sx_eur_old']) || $new === $GLOBALS['cp_sx_eur_old'] || !function_exists('cp_st_t')) { return; }
				global $wpdb; $t = cp_st_t('ads');
				$wpdb->query($wpdb->prepare("UPDATE $t SET spend = ROUND(spend_orig * %f, 2) WHERE currency = 'EUR'", $new));
				if (function_exists('cp_st_bump')) { cp_st_bump(); }
			},
			'acts' => array(
				array('k' => 'sync', 'l' => 'Szinkron most', 'fn' => function () { return cp_sx_bg('st_sync', 'A statisztika-szinkron'); }),
				array('k' => 'meta', 'l' => 'Meta-adatok frissítése', 'fn' => function () { $r = cp_st_meta_sync(true); return is_wp_error($r) ? $r : (is_string($r) && $r !== '' ? $r : 'Meta-adatok frissítve.'); }),
				array('k' => 'windsor', 'l' => 'LinkedIn-adatok frissítése', 'fn' => function () { return cp_sx_bg('st_windsor', 'A LinkedIn-frissítés'); }),
				array('k' => 'ga', 'l' => 'GA4-próba', 'fn' => function () { delete_transient('cp_st_ga_live'); $r = (array) cp_st_ga_live(); $e = array_filter(array_map(function ($x) { return !empty($x['err']) ? $x['name'] . ': ' . $x['err'] : ''; }, (array) ($r['sites'] ?? array()))); return $e ? new WP_Error('cp_sx', implode(' · ', $e)) : 'A GA4 elérhető (' . count((array) ($r['sites'] ?? array())) . ' tulajdonság).'; }),
			),
		);
	}

	/* ---------------- Összesítők (heti/havi Pumble-összesítő, mérföldkövek) */
	if (function_exists('cp_st_dg_opts')) {
		$f = array(
			array('k' => 'hook', 't' => 'secret', 'l' => 'Pumble webhook az összesítőkhöz', 'ph' => 'https://api.pumble.com/…', 'chk' => 'cp_sx_pumble', 'has' => function () { return cp_st_dg_hook() !== ''; }, 'set' => function ($v) { cp_st_secret_set('digest_webhook_secret', $v); }),
			array('k' => 'week', 't' => 'sw', 'l' => 'Heti összesítő (hétfőn)', 'opt' => 'cp_st_dg', 'sub' => 'week', 'def' => 1),
			array('k' => 'month', 't' => 'sw', 'l' => 'Havi összesítő (hó elején)', 'opt' => 'cp_st_dg', 'sub' => 'month', 'def' => 1),
			array('k' => 'hour', 't' => 'num', 'l' => 'Küldés órája', 'opt' => 'cp_st_dg', 'sub' => 'hour', 'min' => 0, 'max' => 23, 'int' => 1, 'def' => 19),
			array('k' => 'ms', 't' => 'sw', 'l' => 'Mérföldkő-értesítések', 'g' => 'Mérföldkövek (lépésköz)', 'opt' => 'cp_st_dg', 'sub' => 'ms', 'def' => 1),
		);
		foreach (cp_st_ms_defs() as $k => $x) { $f[] = array('k' => 'ms_' . $k, 't' => 'num', 'l' => $x[0] . ' (' . $x[1] . ')', 'g' => 'Mérföldkövek (lépésköz)', 'opt' => 'cp_st_dg', 'sub' => 'ms_' . $k, 'min' => 1, 'int' => 1, 'def' => (int) $x[2]); }
		$S['digest'] = array('n' => 'Összesítők', 'wp' => 'cp-st-dg',
			'd' => 'Heti és havi összesítő a Pumble-be, valamint értesítés a mérföldkövekről (pl. minden 10 millió Ft éves bevételnél).',
			'info' => function () { $l = (array) get_option('cp_st_dg_log', array()); $o = array(); foreach (array_slice(array_reverse($l), 0, 4) as $z) { $o[] = array(cp_sx_dt($z['t'] ?? 0), (($z['k'] ?? '') === 'week' ? 'heti' : (($z['k'] ?? '') === 'month' ? 'havi' : (string) ($z['k'] ?? ''))) . ' – ' . (is_string($z['r'] ?? null) ? $z['r'] : 'elküldve'), ''); } return $o; },
			'fields' => $f,
			'acts' => array(
				array('k' => 'pv_week', 'l' => 'Heti előnézet', 'fn' => function () { $t = cp_st_dg_targets(); return array('msg' => 'Előnézet lent.', 'out' => (string) cp_st_dg_text('week', $t['week']['from'], $t['week']['to'])); }),
				array('k' => 'pv_month', 'l' => 'Havi előnézet', 'fn' => function () { $t = cp_st_dg_targets(); return array('msg' => 'Előnézet lent.', 'out' => (string) cp_st_dg_text('month', $t['month']['from'], $t['month']['to'])); }),
				array('k' => 'test_week', 'l' => 'Heti teszt a Pumble-be', 'fn' => function () { $t = cp_st_dg_targets(); $r = cp_st_dg_send('week', $t['week']['from'], $t['week']['to'], true); return is_wp_error($r) ? $r : 'Elküldve a Pumble-be.'; }),
			),
		);
	}
	return $S;
});
