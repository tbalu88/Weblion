<?php
/**
 * v2.0: Salesmate nélküli működés – „helyi Salesmate”.
 * Ha a panel a fő rendszer (Beállítások → Átállás: cp_crm_primary = 1), a panel minden Salesmate-hívása (cp_sm_request)
 * ide fut: az írás a saját CRM-táblákba kerül, az olvasás onnan jön, a válasz a Salesmate formáját követi – így minden
 * meglévő funkció (szakaszváltás, szerkesztés, új deal, kontakt/cég, tevékenység, jegyzet, háttér, portál) változtatás
 * nélkül tovább működik. A rekordok eredeti alakja (raw) megmarad, a módosítás ebbe olvad bele, és a megszokott
 * tárolón (cp_crm_store) át kerül a táblába – a változásnapló is ugyanúgy készül, a panel kollégájának nevével.
 * Kikapcsolt kapcsolónál ez a fájl semmit nem csinál.
 */
if (!defined('ABSPATH')) { exit; }

function cp_sml_mod($seg) {
	$map = array('deal' => 'deal', 'contact' => 'contact', 'company' => 'company', 'activity' => 'activity');
	return $map[$seg] ?? '';
}
function cp_sml_err($msg) { return new WP_Error('cp_local', $msg); }

/* a tárolt rekord Salesmate-alakban (az eredeti nyers adat, a modul-előtag nélkül) */
function cp_sml_get($m, $id) {
	global $wpdb;
	$tab = cp_crm_t(cp_crm_mods()[$m][0]);
	$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tab WHERE id = %d", (int) $id), ARRAY_A);
	if (!$row) { return null; }
	$raw = json_decode((string) $row['raw'], true);
	if (!is_array($raw)) { $raw = array(); }
	$o = array();
	foreach ($raw as $k => $v) { $o[strpos($k, $m . '.') === 0 ? substr($k, strlen($m) + 1) : $k] = $v; }
	/* ami a nyers adatban nincs (pl. régi sor), a tábla oszlopaiból */
	foreach (cp_crm_spec($m) as $col => $f) { if (!array_key_exists($f[0], $o) && array_key_exists($col, $row) && $row[$col] !== null) { $o[$f[0]] = $row[$col]; } }
	$o['id'] = (int) $id;
	/* v2.9.58: a törlést a Salesmate-alakú jelzővel is adjuk vissza (a hívók isDeleted/deletedAt-ot néznek); _deleted a régi kód miatt marad */
	if (!empty($row['deleted_at'])) { $o['_deleted'] = true; $o['isDeleted'] = true; $o['deletedAt'] = gmdate('Y-m-d\TH:i:s.000\Z', strtotime($row['deleted_at'] . ' UTC')); }
	else { unset($o['isDeleted'], $o['deletedAt']); }
	return $o;
}

function cp_sml_now() { return gmdate('Y-m-d\TH:i:s.000\Z'); }

/* a módosítás beolvasztása + tárolás (változásnaplóval) */
function cp_sml_put($m, $id, $body, $create = false) {
	global $wpdb;
	$o = $create ? array() : cp_sml_get($m, $id);
	if ($o === null) { return cp_sml_err('Nincs ilyen rekord (#' . (int) $id . ').'); }
	/* v2.9.58: a PUT nem támasztja fel a törölt rekordot – az isDeleted/deletedAt megmarad, így a tárolás deleted_at-ot hagy */
	$was_del = !empty($o['_deleted']);
	unset($o['_deleted']);
	foreach ((array) $body as $k => $v) { $o[$k] = $v; }
	$o['id'] = (int) $id;
	if ($was_del) { $o['isDeleted'] = true; if (empty($o['deletedAt'])) { $o['deletedAt'] = cp_sml_now(); } }
	/* kontakt: a teljes név a vezeték- és keresztnévből */
	if ($m === 'contact' && (array_key_exists('firstName', (array) $body) || array_key_exists('lastName', (array) $body))) {
		$o['name'] = trim(trim((string) ($o['firstName'] ?? '')) . ' ' . trim((string) ($o['lastName'] ?? '')));
	}
	if ($m === 'activity' && !empty($o['relatedTo']) && empty($o['relatedToModule'])) { $o['relatedToModule'] = 'Deal'; }
	$now = cp_sml_now();
	if ($create) { $o['createdAt'] = $now; }
	$o['lastModifiedAt'] = $now;
	$o['lastModifiedBy'] = null;
	if ($m === 'deal' && $create && empty($o['status'])) { $o['status'] = 'Open'; }
	if ($m === 'deal' && $create && empty($o['pipeline'])) { $o['pipeline'] = cp_panel_opts()['pipeline']; }
	$n = cp_crm_store($m, array($o), 'get');
	if (!$n) { return cp_sml_err('A rekordot nem sikerült menteni.'); }
	if ($m === 'deal') {
		$who = function_exists('cp_panel_who') ? cp_panel_who() : 'Crazy panel';
		$wpdb->query($wpdb->prepare('UPDATE ' . cp_crm_t('history') . " SET by_user = %s, src = 'panel' WHERE deal_id = %d AND src = 'salesmate' AND at >= %s", $who, (int) $id, gmdate('Y-m-d H:i:s', time() - 120)));
		if ($create) { $wpdb->insert(cp_crm_t('history'), array('deal_id' => (int) $id, 'field' => 'created', 'old_val' => '', 'new_val' => 'létrehozva a panelben', 'by_user' => $who, 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel')); }
	}
	if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
	return array('Status' => 'success', 'Data' => array('id' => (int) $id));
}

function cp_sml_new_id($m) {
	global $wpdb;
	$tab = cp_crm_t(cp_crm_mods()[$m][0]);
	/* atomi: a legnagyobb meglévő azonosító + 1, egy számlálóval védve az egyidejű létrehozás ellen */
	$k = 'cp_sml_seq_' . $m;
	$max = (int) $wpdb->get_var("SELECT MAX(id) FROM $tab");
	add_option($k, $max, '', 'no');
	$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = GREATEST(CAST(option_value AS UNSIGNED), %d) + 1 WHERE option_name = %s", $max, $k));
	wp_cache_delete($k, 'options'); wp_cache_delete('notoptions', 'options'); wp_cache_delete('alloptions', 'options');
	return (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $k));
}

/* jegyzetek: a panel saját jegyzettáblájában vannak (dealact.php) – a „Salesmate” csak egy azonosítót ad vissza */
function cp_sml_note_id() { return (int) (microtime(true) * 1000) % 2000000000; }

function cp_sml_request($method, $path, $body = null) {
	if (!function_exists('cp_crm_t') || !function_exists('cp_crm_store')) { return cp_sml_err('A helyi adattár nem érhető el.'); }
	$p = (string) wp_parse_url($path, PHP_URL_PATH);
	/* rekord: /apis/{modul}/v4/{id} */
	if (preg_match('#^/apis/(deal|contact|company|activity)/v4/(\d+)$#', $p, $x)) {
		$m = cp_sml_mod($x[1]); $id = (int) $x[2];
		if ($method === 'GET') { $o = cp_sml_get($m, $id); return $o === null ? cp_sml_err('Nincs ilyen rekord (#' . $id . ').') : array('Status' => 'success', 'Data' => $o); }
		if ($method === 'PUT') { return cp_sml_put($m, $id, is_array($body) ? $body : array()); }
		if ($method === 'DELETE') {
			global $wpdb;
			$tab = cp_crm_t(cp_crm_mods()[$m][0]);
			$wpdb->update($tab, array('deleted_at' => gmdate('Y-m-d H:i:s')), array('id' => $id));
			if ($m === 'deal') { $wpdb->insert(cp_crm_t('history'), array('deal_id' => $id, 'field' => 'deleted', 'old_val' => '', 'new_val' => 'törölve a panelben', 'by_user' => function_exists('cp_panel_who') ? cp_panel_who() : '', 'at' => gmdate('Y-m-d H:i:s'), 'src' => 'panel')); }
			if (function_exists('cp_panel_flush_cache')) { cp_panel_flush_cache(); }
			return array('Status' => 'success', 'Data' => array('id' => $id));
		}
	}
	/* új rekord: POST /apis/{modul}/v4 */
	if ($method === 'POST' && preg_match('#^/apis/(deal|contact|company|activity)/v4$#', $p, $x)) {
		$m = cp_sml_mod($x[1]);
		return cp_sml_put($m, cp_sml_new_id($m), is_array($body) ? $body : array(), true);
	}
	/* deal-jegyzet hozzáadása / törlése / lista */
	if (preg_match('#^/apis/deal/v4/modules/4/object/\d+/notes(/\d+)?$#', $p)) {
		return array('Status' => 'success', 'Data' => array('noteId' => $method === 'POST' ? cp_sml_note_id() : 0));
	}
	if ($method === 'GET' && preg_match('#^/apis/module/v4/modules/4/objects/\d+/notes$#', $p)) { return cp_sml_err('Jegyzetek: a panel a saját tárából olvas.'); }
	if ($method === 'POST' && $p === '/apis/note/v4') { return array('Status' => 'success', 'Data' => array('id' => cp_sml_note_id())); }
	/* keresés, felhasználók: a panel a saját tábláiból dolgozik */
	return cp_sml_err('A Salesmate már nincs használatban – ez a lekérdezés a panel saját adataiból megy (' . $method . ' ' . $p . ').');
}
