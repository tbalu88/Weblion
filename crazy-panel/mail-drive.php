<?php
/**
 * Crazy panel – bejövő számlák mentése a Google Drive számlamappájába (v2.9.38)
 * A „Számla” címkés bejövő levelek PDF-mellékletei a számla kelte szerinti havi mappába kerülnek:
 *   <szülő> / „EC - Számlák ÉÉÉÉ” / „HH - Hónap” / <eredeti fájlnév>
 * A keltet a Google Drive szövegfelismerése olvassa ki (ideiglenes Google-dokumentum, utána törölve – a számla
 * a Google-nál marad); ha nem sikerül, a levél dátuma dönt, és ez jelölve van. Azonos nevű fájl a mappában = már
 * fent van (nem tölt fel újra). Automatikusan csak az új levelek mennek; a régiekhez kézi gomb van.
 * A mentés helye a melléklet adatai között (attachments JSON, „dv” kulcs), a szálon esemény jelzi.
 * Hozzáférés: a Drive-mentés szolgáltatásfiókja (gdrive-backup.php, cp_gdb_api).
 */
if (!defined('ABSPATH')) { exit; }

const CP_MDR_PARENT = '1PDBzasCyoygAh9AMUDi2bwUOg1hnO2QE';  /* az „EC - Számlák ÉÉÉÉ” mappák szülője (közös meghajtó) */
const CP_MDR_API = 'https://www.googleapis.com/drive/v3/files';
const CP_MDR_UP = 'https://www.googleapis.com/upload/drive/v3/files';

function cp_mdr_on() { return function_exists('cp_gdb_api') && get_option('cp_mdr_on', '1') === '1'; }
function cp_mdr_months() { return array(1 => 'Január', 'Február', 'Március', 'Április', 'Május', 'Június', 'Július', 'Augusztus', 'Szeptember', 'Október', 'November', 'December'); }
function cp_mdr_cache() { $c = get_option('cp_mdr_ids', array()); return is_array($c) ? $c : array(); }
function cp_mdr_cache_set($k, $v) { $c = cp_mdr_cache(); $c[$k] = $v; update_option('cp_mdr_ids', $c, false); }

function cp_mdr_json($r) { return is_wp_error($r) ? $r : (json_decode(wp_remote_retrieve_body($r), true) ?: array()); }
function cp_mdr_q($s) { return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), $s) . "'"; }

/* mappa keresése név szerint a szülőben; ha nincs, létrehozás */
function cp_mdr_folder($parent, $name) {
	$key = $parent . '|' . $name;
	$c = cp_mdr_cache();
	/* a megjegyzett mappa még ott van-e (nincs kukában, nem került máshová, nem nevezték át) – különben újrakeresés / létrehozás */
	if (!empty($c[$key])) {
		$j = cp_mdr_json(cp_gdb_api('GET', CP_MDR_API . '/' . rawurlencode($c[$key]) . '?supportsAllDrives=true&fields=id,name,trashed,parents'));
		if (!is_wp_error($j) && empty($j['trashed']) && ($j['name'] ?? '') === $name && in_array($parent, (array) ($j['parents'] ?? array()), true)) { return $c[$key]; }
	}
	$q = cp_mdr_q($parent) . " in parents and name = " . cp_mdr_q($name) . " and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
	$j = cp_mdr_json(cp_gdb_api('GET', CP_MDR_API . '?' . http_build_query(array('q' => $q, 'fields' => 'files(id)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'corpora' => 'allDrives'))));
	if (is_wp_error($j)) { return $j; }
	$id = (string) ($j['files'][0]['id'] ?? '');
	if ($id === '') {
		$j = cp_mdr_json(cp_gdb_api('POST', CP_MDR_API . '?supportsAllDrives=true&fields=id', wp_json_encode(array('name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => array($parent))), array('Content-Type' => 'application/json')));
		if (is_wp_error($j)) { return $j; }
		$id = (string) ($j['id'] ?? '');
		if ($id === '') { return new WP_Error('cp_mdr', 'A mappát nem sikerült létrehozni: ' . $name); }
	}
	cp_mdr_cache_set($key, $id);
	return $id;
}
function cp_mdr_month_folder($ymd) {
	$y = (int) substr($ymd, 0, 4); $m = (int) substr($ymd, 5, 2);
	$yf = cp_mdr_folder(CP_MDR_PARENT, 'EC - Számlák ' . $y);
	if (is_wp_error($yf)) { return $yf; }
	$name = sprintf('%02d - %s', $m, cp_mdr_months()[$m]);
	$mf = cp_mdr_folder($yf, $name);
	return is_wp_error($mf) ? $mf : array('id' => $mf, 'name' => $name, 'year' => $y, 'yf' => $yf);
}
function cp_mdr_find_file($folder, $name) {
	$q = cp_mdr_q($folder) . ' in parents and name = ' . cp_mdr_q($name) . ' and trashed = false';
	$j = cp_mdr_json(cp_gdb_api('GET', CP_MDR_API . '?' . http_build_query(array('q' => $q, 'fields' => 'files(id)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'corpora' => 'allDrives'))));
	return is_wp_error($j) ? $j : (string) ($j['files'][0]['id'] ?? '');
}
/* feltöltés (multipart); $as_doc: Google-dokumentummá alakítva (szövegfelismerés) */
function cp_mdr_upload($parent, $name, $data, $mime, $as_doc = false) {
	$b = 'cpmdr' . wp_generate_password(16, false, false);
	$meta = array('name' => $name, 'parents' => array($parent)) + ($as_doc ? array('mimeType' => 'application/vnd.google-apps.document') : array());
	$body = "--$b\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . wp_json_encode($meta) . "\r\n--$b\r\nContent-Type: $mime\r\n\r\n" . $data . "\r\n--$b--";
	$url = CP_MDR_UP . '?uploadType=multipart&supportsAllDrives=true&fields=id' . ($as_doc ? '&ocrLanguage=hu' : '');
	$j = cp_mdr_json(cp_gdb_api('POST', $url, $body, array('Content-Type' => 'multipart/related; boundary=' . $b), 90));
	if (is_wp_error($j)) { return $j; }
	return (string) ($j['id'] ?? '') ?: new WP_Error('cp_mdr', 'A feltöltés nem sikerült.');
}

/* ------------------------------------------------------------ a számla keltének kiolvasása */
function cp_mdr_pdf_text($data, $tmp_parent) {
	$id = cp_mdr_upload($tmp_parent, '~ Crazy panel – ideiglenes (törlődik)', $data, 'application/pdf', true);
	if (is_wp_error($id)) { return $id; }
	$r = cp_gdb_api('GET', CP_MDR_API . '/' . rawurlencode($id) . '/export?mimeType=text%2Fplain', null, array(), 60);
	cp_mdr_rm($id);
	return is_wp_error($r) ? $r : (string) wp_remote_retrieve_body($r);
}
/* az ideiglenes dokumentum eltávolítása: a szolgáltatásfiók a közös meghajtón csak kukába tehet (végleges törlés: 404) */
function cp_mdr_rm($id) {
	$r = cp_gdb_api('PATCH', CP_MDR_API . '/' . rawurlencode($id) . '?supportsAllDrives=true&fields=id', wp_json_encode(array('trashed' => true)), array('Content-Type' => 'application/json'));
	if (is_wp_error($r)) { update_option('cp_mdr_err', array('t' => time(), 'm' => 0, 'e' => 'Ideiglenes dokumentum nem törölhető: ' . mb_substr($r->get_error_message(), 0, 160)), false); }
	return !is_wp_error($r);
}
function cp_mdr_mk($y, $m, $d) { return checkdate((int) $m, (int) $d, (int) $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : ''; }
function cp_mdr_parse_date($s) {
	$s = trim($s);
	$hu = array('jan' => 1, 'feb' => 2, 'márc' => 3, 'marc' => 3, 'mar' => 3, 'ápr' => 4, 'apr' => 4, 'máj' => 5, 'maj' => 5, 'may' => 5, 'jún' => 6, 'jun' => 6, 'júl' => 7, 'jul' => 7, 'aug' => 8, 'szept' => 9, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'dec' => 12);
	if (preg_match('/^(20\d\d)\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})/u', $s, $m)) { return cp_mdr_mk($m[1], $m[2], $m[3]); }
	if (preg_match('/^(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(20\d\d)/u', $s, $m)) { return cp_mdr_mk($m[3], $m[2], $m[1]); }
	$m = $m2 = $m3 = array();
	if (preg_match('/^(20\d\d)\.?\s*([a-záéíóöőúüű]+)\.?\s*(\d{1,2})/iu', $s, $m) || preg_match('/^(\d{1,2})\.?\s*([a-záéíóöőúüű]+)\.?,?\s*(20\d\d)/iu', $s, $m2) || preg_match('/^([a-záéíóöőúüű]+)\.?\s*(\d{1,2}),?\s*(20\d\d)/iu', $s, $m3)) {
		if (!empty($m)) { $y = $m[1]; $mn = $m[2]; $d = $m[3]; } elseif (!empty($m2)) { $d = $m2[1]; $mn = $m2[2]; $y = $m2[3]; } else { $mn = $m3[1]; $d = $m3[2]; $y = $m3[3]; }
		$mn = mb_strtolower($mn);
		foreach ($hu as $k => $v) { if (mb_strpos($mn, $k) === 0) { return cp_mdr_mk($y, $v, $d); } }
	}
	return '';
}
/* a „Számla kelte / Kelt / Kiállítás dátuma / Invoice date” utáni dátum; ésszerűségi korlát: a levél előtt legfeljebb 400, utána 5 nap */
function cp_mdr_inv_date($text, $mail_ymd) {
	$lab = '(sz[aá]mla\s*kelte|kelte|kelt|ki[aá]ll[ií]t[aá]s(?:\s*(?:d[aá]tuma|kelte|napja))?|sz[aá]mla\s*d[aá]tuma|invoice\s*date|date\s*of\s*issue|issue\s*date|date\s*issued|rechnungsdatum)';
	$t = preg_replace('/[ \t]+/u', ' ', (string) $text);
	$lo = strtotime($mail_ymd . ' 12:00 UTC') - 400 * DAY_IN_SECONDS; $hi = strtotime($mail_ymd . ' 12:00 UTC') + 5 * DAY_IN_SECONDS;
	$ok = function ($d) use ($lo, $hi) { if ($d === '') { return false; } $ts = strtotime($d . ' 12:00 UTC'); return $ts >= $lo && $ts <= $hi; };
	/* 1. a címke után közvetlenül (ugyanabban vagy a következő sorban) */
	if (preg_match_all('/' . $lab . '\s*[:.]?\s*\n?[^\n\d]{0,25}?((?:20\d\d|\d{1,2}[.\-\/]|[a-z]{3,})[^\n]{0,24})/iu', $t, $mm, PREG_SET_ORDER)) {
		foreach ($mm as $x) { $d = cp_mdr_parse_date($x[2]); if ($ok($d)) { return $d; } }
	}
	/* 2. táblázatos számlakép (a címkék egy sorban, az értékek alattuk): az első dátum a címke utáni 200 karakterben */
	if (preg_match('/' . $lab . '(.{0,200})/isu', $t, $x) && preg_match_all('/(20\d\d\s*[.\-\/]\s*\d{1,2}\s*[.\-\/]\s*\d{1,2}|\d{1,2}\s*[.\-\/]\s*\d{1,2}\s*[.\-\/]\s*20\d\d)/u', $x[2], $dd)) {
		foreach ($dd[1] as $c) { $d = cp_mdr_parse_date($c); if ($ok($d)) { return $d; } }
	}
	return '';
}

/* ------------------------------------------------------------ v2.9.44: kiolvasás a Claude-dal (a PDF közvetlenül, olvasóként)
 * Visszaad: array(d => kelte ÉÉÉÉ-HH-NN, sup, no, amt, cur, due, tk => tokenek) vagy WP_Error. A tokenek a cp_ai_log táblába is kerülnek. */
const CP_MDR_AI_MODEL = 'claude-sonnet-5-5';
function cp_mdr_ai_on() { return function_exists('cp_ai_call') && function_exists('cp_ai_key') && cp_ai_key() !== '' && (string) get_option('cp_mdr_ai', '1') !== '0'; }
function cp_mdr_ai($data, $mail_ymd, $ref = '') {
	if (!cp_mdr_ai_on()) { return new WP_Error('cp_mdr', 'Nincs AI-kulcs.'); }
	if (strlen($data) > 20 * 1048576) { return new WP_Error('cp_mdr', 'A PDF túl nagy az AI-kiolvasáshoz.'); }
	$sys = 'Egy beérkezett számla (vagy díjbekérő / nyugta) PDF-jéből olvasd ki az adatokat. Csak azt írd, ami a dokumentumban szerepel; ha valami nincs benne vagy nem egyértelmű, üres szöveg. '
		. 'A „kelte” a számla kiállításának dátuma (Számla kelte / Kelt / Kiállítás dátuma / Invoice date / Date of issue) – NEM a teljesítés és NEM a fizetési határidő. Dátum formája: ÉÉÉÉ-HH-NN. '
		. 'Az összeg a fizetendő bruttó végösszeg számként (tizedesponttal, ezres elválasztó nélkül), a pénznem háromjegyű kód (HUF, EUR, USD).';
	$schema = array('type' => 'object', 'additionalProperties' => false, 'required' => array('kind', 'issue_date', 'supplier', 'invoice_no', 'gross', 'currency', 'due_date'), 'properties' => array(
		'kind' => array('type' => 'string', 'enum' => array('szamla', 'dijbekero', 'nyugta', 'egyeb'), 'description' => 'A dokumentum fajtája'),
		'issue_date' => array('type' => 'string'), 'supplier' => array('type' => 'string', 'description' => 'A kiállító (eladó) neve'),
		'invoice_no' => array('type' => 'string'), 'gross' => array('type' => 'string'), 'currency' => array('type' => 'string'), 'due_date' => array('type' => 'string'),
	));
	$msg = array(array('role' => 'user', 'content' => array(
		array('type' => 'document', 'source' => array('type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($data))),
		array('type' => 'text', 'text' => 'Olvasd ki a számla adatait.'),
	)));
	$r = cp_ai_call(array(array('type' => 'text', 'text' => $sys)), $msg, $schema, 600, array('model' => CP_MDR_AI_MODEL, 'effort' => 'low', 'feat' => 'szamla', 'ref' => $ref));
	$lg = $GLOBALS['cp_ai_last'] ?? array();
	$tk = array('in' => (int) ($lg['tin'] ?? 0) + (int) ($lg['tcw'] ?? 0) + (int) ($lg['tcr'] ?? 0), 'out' => (int) ($lg['tout'] ?? 0));
	if (is_wp_error($r)) { return $r; }
	$j = json_decode((string) $r, true);
	if (!is_array($j)) { return new WP_Error('cp_mdr', 'Az AI válasza nem értelmezhető.'); }
	$d = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $j['issue_date'], $m) ? cp_mdr_mk($m[1], $m[2], $m[3]) : '';
	if ($d !== '') { $ts = strtotime($d . ' 12:00 UTC'); $mt = strtotime($mail_ymd . ' 12:00 UTC'); if ($ts < $mt - 400 * DAY_IN_SECONDS || $ts > $mt + 5 * DAY_IN_SECONDS) { $d = ''; } }
	$cl = function ($v, $n) { return mb_substr(sanitize_text_field((string) $v), 0, $n); };
	return array('d' => $d, 'kind' => $cl($j['kind'], 10), 'sup' => $cl($j['supplier'], 80), 'no' => $cl($j['invoice_no'], 40), 'amt' => $cl($j['gross'], 20), 'cur' => $cl($j['currency'], 3), 'due' => $cl($j['due_date'], 10), 'tk' => $tk);
}

/* ------------------------------------------------------------ a melléklet tartalma a postafiókból (kérés nélkül, háttérből is) */
function cp_mdr_fetch($m, $i) {
	$list = json_decode((string) $m['attachments'], true) ?: array();
	if (!isset($list[$i])) { return new WP_Error('cp_mdr', 'Nincs ilyen melléklet.'); }
	$a = $list[$i];
	if (!empty($a['f']) && function_exists('cp_att_dir')) {
		$path = cp_att_dir() . '/' . basename(dirname($a['f'])) . '/' . basename($a['f']);
		return is_file($path) ? (string) file_get_contents($path) : new WP_Error('cp_mdr', 'A fájl már nincs meg a szerveren.');
	}
	if (!function_exists('cp_att_locate') || !function_exists('cp_imp_open')) { return new WP_Error('cp_mdr', 'A postafiók nincs bekötve.'); }
	$loc = cp_att_locate($m);
	if (!$loc) { return new WP_Error('cp_mdr', 'A levelet nem találom a postafiókban.'); }
	$mb = cp_imp_open($loc[0]);
	if (is_wp_error($mb)) { return $mb; }
	if (!empty($m['mid'])) {
		$hraw = (string) @imap_fetchheader($mb, $loc[1], FT_UID);
		$hh = $hraw !== '' ? @imap_rfc822_parse_headers($hraw) : null;
		$got = $hh && !empty($hh->message_id) ? md5(trim($hh->message_id)) : ($hraw !== '' ? md5($hraw) : '');
		if ($got !== $m['mid']) { imap_close($mb); return new WP_Error('cp_mdr', 'A levél helye megváltozott a postafiókban – nyisd meg egyszer a mellékletet, utána próbáld újra.'); }
	}
	$parts = cp_att_parts($mb, $loc[1]);
	$nn = function ($v) { return function_exists('cp_mail_fname') ? cp_mail_fname((string) $v) : (string) $v; };
	$p = isset($parts[$i]) && $nn($parts[$i]['n']) === $nn($a['n']) ? $parts[$i] : null;
	if (!$p) { foreach ($parts as $x) { if ($nn($x['n']) === $nn($a['n'])) { $p = $x; break; } } }
	if (!$p || $p['size'] > 30 * 1048576) { imap_close($mb); return new WP_Error('cp_mdr', $p ? 'A melléklet túl nagy (30 MB felett).' : 'A melléklet nincs meg a levélben.'); }
	$raw = (string) imap_fetchbody($mb, $loc[1], $p['num'], FT_UID | FT_PEEK);
	imap_errors(); imap_close($mb);
	return $p['enc'] === 3 ? base64_decode($raw) : ($p['enc'] === 4 ? quoted_printable_decode($raw) : $raw);
}
function cp_mdr_is_pdf($n) { return (bool) preg_match('/\.pdf$/i', (string) $n); }

/* ------------------------------------------------------------ egy melléklet mentése; visszaadja a „dv” adatot */
function cp_mdr_save($mid, $i, $by = '') {
	global $wpdb;
	$M = cp_mail_t('messages');
	$m = $wpdb->get_row($wpdb->prepare("SELECT * FROM $M WHERE id = %d", $mid), ARRAY_A);
	if (!$m) { return new WP_Error('cp_mdr', 'Nincs ilyen levél.'); }
	$list = json_decode((string) $m['attachments'], true) ?: array();
	if (!isset($list[$i])) { return new WP_Error('cp_mdr', 'Nincs ilyen melléklet.'); }
	if (!empty($list[$i]['dv']['id'])) { return $list[$i]['dv']; }
	$name = function_exists('cp_mail_fname') ? cp_mail_fname((string) $list[$i]['n']) : (string) $list[$i]['n'];
	$name = trim(str_replace(array('/', '\\'), '_', $name)) ?: 'szamla.pdf';
	$data = cp_mdr_fetch($m, $i);
	if (is_wp_error($data)) { return $data; }
	if (strncmp($data, '%PDF', 4) !== 0) { return new WP_Error('cp_mdr', 'A melléklet nem PDF.'); }
	$mail_ymd = wp_date('Y-m-d', strtotime($m['sent_at'] . ' UTC'));
	/* a kelte: 1. a Claude olvassa ki (és a többi adatot is), 2. Drive-szövegfelismerés, 3. a levél dátuma */
	$ai = cp_mdr_ai_on() ? cp_mdr_ai($data, $mail_ymd, 'levél ' . (int) $mid) : null;
	$d = is_array($ai) ? $ai['d'] : '';
	$src = $d !== '' ? 'ai' : '';
	if ($d === '') {
		$tmp = cp_mdr_folder(CP_MDR_PARENT, 'EC - Számlák ' . substr($mail_ymd, 0, 4));
		if (is_wp_error($tmp)) { return $tmp; }
		$txt = cp_mdr_pdf_text($data, $tmp);
		$d = is_wp_error($txt) ? '' : cp_mdr_inv_date($txt, $mail_ymd);
		$src = $d !== '' ? 'pdf' : 'mail';
	}
	if ($d === '') { $d = $mail_ymd; }
	$dsrc = $src;
	$f = cp_mdr_month_folder($d);
	if (is_wp_error($f)) { return $f; }
	$ex = cp_mdr_find_file($f['id'], $name);
	if (is_wp_error($ex)) { return $ex; }
	if ($ex !== '') { $id = $ex; $src = 'van'; }
	else { $id = cp_mdr_upload($f['id'], $name, $data, 'application/pdf'); if (is_wp_error($id)) { return $id; } }
	$dv = array('id' => $id, 'f' => $f['name'], 'y' => $f['year'], 'd' => $d, 's' => $dsrc, 'k' => $ex !== '' ? 1 : 0);
	if (is_array($ai)) { $dv['ai'] = array_intersect_key($ai, array_flip(array('kind', 'sup', 'no', 'amt', 'cur', 'due'))); $dv['tk'] = $ai['tk']; }
	/* friss olvasás, hogy egy közben történt másik mentést ne írjunk felül */
	$list = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT attachments FROM $M WHERE id = %d", $mid)), true) ?: array();
	if (isset($list[$i])) { $list[$i]['dv'] = $dv; $wpdb->update($M, array('attachments' => wp_json_encode($list)), array('id' => (int) $mid)); }
	$wpdb->insert(cp_mail_t('events'), array('thread_id' => (int) $m['thread_id'], 'kind' => 'drive', 'text' => mb_substr((!empty($dv['k']) ? 'Számla már a Drive-on: ' : 'Számla a Drive-ra mentve: ') . 'EC - Számlák ' . $f['year'] . ' / ' . $f['name'] . ' (' . $name . ')' . ($dv['s'] === 'mail' ? ' – a kelte nem volt kiolvasható, a levél dátuma szerint' : '') . (isset($dv['tk']) ? ' · AI: ' . number_format($dv['tk']['in'] + $dv['tk']['out'], 0, ',', ' ') . ' token' : ''), 0, 255), 'author_uid' => 0, 'author' => $by !== '' ? $by : 'Crazy panel', 'created_at' => gmdate('Y-m-d H:i:s')));
	return $dv;
}

/* ------------------------------------------------------------ automatikus: az új számlás levelek sora (a levélszinkron végén) */
function cp_mdr_enqueue($mid) {
	if (!cp_mdr_on()) { return; }
	$q = get_option('cp_mdr_q', array()); if (!is_array($q)) { $q = array(); }
	$q[(int) $mid] = 0;
	update_option('cp_mdr_q', $q, false);
}
function cp_mdr_run() {
	if (!cp_mdr_on() || get_transient('cp_mdr_lock')) { return; }
	$q = get_option('cp_mdr_q', array()); if (!is_array($q) || !$q) { return; }
	set_transient('cp_mdr_lock', 1, 180);
	global $wpdb;
	$t0 = time();
	foreach (array_slice($q, 0, 5, true) as $mid => $tries) {
		if (time() - $t0 > 90) { break; }
		$list = json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT attachments FROM ' . cp_mail_t('messages') . ' WHERE id = %d', $mid)), true) ?: array();
		$err = '';
		foreach ($list as $i => $a) { if (cp_mdr_is_pdf($a['n'] ?? '') && empty($a['dv'])) { $r = cp_mdr_save((int) $mid, (int) $i); if (is_wp_error($r)) { $err = $r->get_error_message(); } } }
		/* hiba: legfeljebb 3 próbálkozás (a következő körökben) */
		if ($err !== '' && $tries < 2) { $q[$mid] = $tries + 1; } else { unset($q[$mid]); if ($err !== '') { update_option('cp_mdr_err', array('t' => time(), 'm' => (int) $mid, 'e' => mb_substr($err, 0, 200)), false); } }
	}
	update_option('cp_mdr_q', $q, false);
	delete_transient('cp_mdr_lock');
}

/* ------------------------------------------------------------ kézi mentés a melléklet melletti gombbal */
add_action('wp_ajax_nopriv_cp_mail_drive_save', 'cp_mdr_ajax_save');
add_action('wp_ajax_cp_mail_drive_save', 'cp_mdr_ajax_save');
function cp_mdr_ajax_save() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if (!function_exists('cp_gdb_api')) { cp_el_err('A Google Drive nincs bekötve.'); }
	@set_time_limit(120);
	$mid = (int) ($_POST['m'] ?? 0); $i = (int) ($_POST['i'] ?? 0);
	$r = cp_mdr_save($mid, $i, function_exists('cp_panel_who') ? cp_panel_who() : '');
	if (is_wp_error($r)) { cp_el_err($r->get_error_message()); }
	global $wpdb;
	$tid = (int) $wpdb->get_var($wpdb->prepare('SELECT thread_id FROM ' . cp_mail_t('messages') . ' WHERE id = %d', $mid));
	if ($tid && function_exists('cp_mail_tag_add')) { cp_mail_tag_add($tid, CP_MAIL_INV_TAG); }
	wp_send_json_success(array('dv' => $r));
}

/* ------------------------------------------------------------ v2.9.44: kézi hónapjavítás (a jelvény melletti ✎) – a fájl a Drive-on is átkerül */
add_action('wp_ajax_nopriv_cp_mail_drive_move', 'cp_mdr_ajax_move');
add_action('wp_ajax_cp_mail_drive_move', 'cp_mdr_ajax_move');
function cp_mdr_ajax_move() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	global $wpdb;
	$M = cp_mail_t('messages');
	$mid = (int) ($_POST['m'] ?? 0); $i = (int) ($_POST['i'] ?? 0); $ym = (string) ($_POST['ym'] ?? '');
	if (!preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $ym)) { cp_el_err('Hibás hónap.'); }
	$m = $wpdb->get_row($wpdb->prepare("SELECT id, thread_id, attachments FROM $M WHERE id = %d", $mid), ARRAY_A);
	$list = $m ? (json_decode((string) $m['attachments'], true) ?: array()) : array();
	$dv = $list[$i]['dv'] ?? null;
	if (!$dv || empty($dv['id'])) { cp_el_err('Ez a melléklet még nincs a Drive-on.'); }
	$f = cp_mdr_month_folder($ym . '-15');
	if (is_wp_error($f)) { cp_el_err($f->get_error_message()); }
	$j = cp_mdr_json(cp_gdb_api('GET', CP_MDR_API . '/' . rawurlencode($dv['id']) . '?supportsAllDrives=true&fields=parents'));
	if (is_wp_error($j)) { cp_el_err($j->get_error_message()); }
	$old = implode(',', (array) ($j['parents'] ?? array()));
	if ($old !== $f['id']) {
		$r = cp_gdb_api('PATCH', CP_MDR_API . '/' . rawurlencode($dv['id']) . '?' . http_build_query(array('addParents' => $f['id'], 'removeParents' => $old, 'supportsAllDrives' => 'true', 'fields' => 'id')), '{}', array('Content-Type' => 'application/json'));
		if (is_wp_error($r)) { cp_el_err($r->get_error_message()); }
	}
	$dv['f'] = $f['name']; $dv['y'] = $f['year']; $dv['s'] = 'kezi';
	$list[$i]['dv'] = $dv;
	$wpdb->update($M, array('attachments' => wp_json_encode($list)), array('id' => $mid));
	$who = function_exists('cp_panel_who') ? cp_panel_who() : '';
	$wpdb->insert(cp_mail_t('events'), array('thread_id' => (int) $m['thread_id'], 'kind' => 'drive', 'text' => mb_substr('Számla áthelyezve a Drive-on: EC - Számlák ' . $f['year'] . ' / ' . $f['name'], 0, 255), 'author_uid' => 0, 'author' => $who !== '' ? $who : 'Crazy panel', 'created_at' => gmdate('Y-m-d H:i:s')));
	wp_send_json_success(array('dv' => $dv));
}

