<?php
/**
 * Crazy panel – levélcímkék (v2.9.25)
 * A címkék a levélszál „tags” mezőjében maradnak (vesszővel elválasztva); az „import” és a „salesmate” belső jelölő
 * (a régi levelezés eredete), a felületen nem látszik és nem állítható. A címkék színe és a darabszám nélküli
 * (még sehol nem használt) címkék a cp_mail_tags opcióban vannak: név => színindex (0–7).
 * Kezelés (létrehozás, szín, átnevezés, összevonás, törlés) minden kollégának engedélyezett; minden szálon naplózva.
 */
if (!defined('ABSPATH')) { exit; }

const CP_MAIL_TAG_SYS = array('import', 'salesmate');

function cp_mail_tag_norm($s) {
	$s = trim(preg_replace('/\s+/u', ' ', str_replace(array(',', ';', "\n", "\r"), ' ', (string) $s)));
	return mb_substr(sanitize_text_field($s), 0, 40);
}
function cp_mail_tag_is_sys($n) { return in_array(mb_strtolower($n), CP_MAIL_TAG_SYS, true); }
function cp_mail_tags_split($str) {
	return array_values(array_filter(array_map('trim', explode(',', (string) $str)), 'strlen'));
}
/* rendszerjelölők elöl (a lekérdezések az első elemet nézik), utánuk a címkék kis-nagybetűtől függetlenül egyedien */
function cp_mail_tags_join($sys, $user) {
	$out = array(); $seen = array();
	foreach (array_merge($sys, $user) as $n) { $k = mb_strtolower($n); if ($n === '' || isset($seen[$k])) { continue; } $seen[$k] = 1; $out[] = $n; }
	$s = '';
	foreach ($out as $n) { $next = $s === '' ? $n : $s . ', ' . $n; if (strlen($next) > 255) { break; } $s = $next; }
	return $s;
}
function cp_mail_tags_parts($str) {
	$sys = array(); $user = array();
	foreach (cp_mail_tags_split($str) as $n) { if (cp_mail_tag_is_sys($n)) { $sys[] = $n; } else { $user[] = $n; } }
	return array($sys, $user);
}
/* SQL-feltétel: a szál címkéi között szerepel $n (a tárolt „a, b” formát „,a,b,”-ként nézi) */
function cp_mail_tag_where($n) {
	global $wpdb;
	return $wpdb->prepare("CONCAT(',', REPLACE(tags, ', ', ','), ',') LIKE %s", '%,' . $wpdb->esc_like($n) . ',%');
}
function cp_mail_tag_reg() { $r = function_exists('cp_opt_fresh') ? cp_opt_fresh('cp_mail_tags', array()) : get_option('cp_mail_tags', array()); return is_array($r) ? $r : array(); }
function cp_mail_tag_reg_save($r) { update_option('cp_mail_tags', $r, false); delete_transient('cp_mail_tags_all'); }
/* új címke felvétele a nyilvántartásba (automatikus színnel); a meglévő színét nem bántja */
function cp_mail_tag_register($names) {
	$r = cp_mail_tag_reg(); $ch = false;
	foreach ((array) $names as $n) {
		if ($n === '' || cp_mail_tag_is_sys($n)) { continue; }
		$hit = false; foreach ($r as $k => $c) { if (mb_strtolower($k) === mb_strtolower($n)) { $hit = true; break; } }
		if (!$hit) { $r[$n] = count($r) % 8; $ch = true; }
	}
	if ($ch) { cp_mail_tag_reg_save($r); } else { delete_transient('cp_mail_tags_all'); }
}
/* minden címke darabszámmal (a Kuka és a Spam nélkül), gyakoriság szerint; rövid ideig gyorsítótárazva */
function cp_mail_tags_all() {
	$c = get_transient('cp_mail_tags_all');
	if (is_array($c)) { return $c; }
	global $wpdb;
	$cnt = array(); $name = array();
	foreach ((array) $wpdb->get_col('SELECT tags FROM ' . cp_mail_t('threads') . " WHERE tags <> '' AND folder NOT IN ('trash','spam')") as $s) {
		foreach (cp_mail_tags_parts($s)[1] as $n) { $k = mb_strtolower($n); $cnt[$k] = ($cnt[$k] ?? 0) + 1; if (!isset($name[$k])) { $name[$k] = $n; } }
	}
	$reg = cp_mail_tag_reg(); $col = array();
	foreach ($reg as $n => $ci) { $k = mb_strtolower($n); $col[$k] = (int) $ci; if (!isset($name[$k])) { $name[$k] = $n; $cnt[$k] = 0; } }
	$out = array();
	foreach ($name as $k => $n) { $out[] = array('n' => $n, 'c' => isset($col[$k]) ? $col[$k] : (crc32($k) % 8), 'cnt' => (int) $cnt[$k]); }
	usort($out, function ($a, $b) { return $b['cnt'] - $a['cnt'] ?: strcoll(mb_strtolower($a['n']), mb_strtolower($b['n'])); });
	set_transient('cp_mail_tags_all', $out, 60);
	return $out;
}

add_action('wp_ajax_nopriv_cp_mail_tags', 'cp_mail_ajax_tags');
add_action('wp_ajax_cp_mail_tags', 'cp_mail_ajax_tags');
function cp_mail_ajax_tags() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if (!cp_mail_db_ok()) { cp_el_err('Az adatbázis még készül.', 503); }
	wp_send_json_success(array('tags' => cp_mail_tags_all()));
}

/* kezelés: create / color / rename / merge / delete */
add_action('wp_ajax_nopriv_cp_mail_tag_op', 'cp_mail_ajax_tag_op');
add_action('wp_ajax_cp_mail_tag_op', 'cp_mail_ajax_tag_op');
function cp_mail_ajax_tag_op() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cp_mail_db_ok()) { cp_el_err('Hibás kérés.'); }
	global $wpdb;
	$op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
	$n = cp_mail_tag_norm(wp_unslash($_POST['n'] ?? ''));
	$to = cp_mail_tag_norm(wp_unslash($_POST['to'] ?? ''));
	if ($n === '' || cp_mail_tag_is_sys($n) || !in_array($op, array('create', 'color', 'rename', 'merge', 'delete'), true)) { cp_el_err('Hibás kérés.'); }
	$reg = cp_mail_tag_reg();
	$rk = null; foreach ($reg as $k => $c) { if (mb_strtolower($k) === mb_strtolower($n)) { $rk = $k; break; } }
	if ($op === 'create') { cp_mail_tag_register(array($n)); wp_send_json_success(array('tags' => cp_mail_tags_all())); }
	if ($op === 'color') {
		$c = max(0, min(7, (int) ($_POST['c'] ?? 0)));
		if ($rk !== null) { unset($reg[$rk]); }
		$reg[$rk !== null ? $rk : $n] = $c;
		cp_mail_tag_reg_save($reg);
		wp_send_json_success(array('tags' => cp_mail_tags_all()));
	}
	if ($op !== 'delete' && ($to === '' || cp_mail_tag_is_sys($to))) { cp_el_err('Adj meg egy új nevet.'); }
	if ($op !== 'delete' && $to === $n) { wp_send_json_success(array('tags' => cp_mail_tags_all(), 'n' => 0)); }
	/* minden érintett szál újraírása; a változás a szál idővonalán is megjelenik */
	$me = function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0;
	$who = function_exists('cp_panel_who') ? cp_panel_who() : '';
	$txt = $op === 'delete' ? 'Címke törölve (minden szálról): ' . $n : ($op === 'merge' ? 'Címke összevonva: ' . $n . ' → ' . $to : 'Címke átnevezve: ' . $n . ' → ' . $to);
	$T = cp_mail_t('threads'); $done = 0;
	foreach ((array) $wpdb->get_results("SELECT id, tags FROM $T WHERE " . cp_mail_tag_where($n), ARRAY_A) as $r) {
		list($sys, $user) = cp_mail_tags_parts($r['tags']);
		$nu = array();
		foreach ($user as $x) { if (mb_strtolower($x) === mb_strtolower($n)) { if ($op !== 'delete') { $nu[] = $to; } } else { $nu[] = $x; } }
		$s = cp_mail_tags_join($sys, $nu);
		if ($s === $r['tags']) { continue; }
		$wpdb->update($T, array('tags' => $s), array('id' => (int) $r['id']));
		$wpdb->insert(cp_mail_t('events'), array('thread_id' => (int) $r['id'], 'kind' => 'tags', 'text' => mb_substr($txt, 0, 255), 'author_uid' => $me, 'author' => $who, 'created_at' => gmdate('Y-m-d H:i:s')));
		$done++;
	}
	/* nyilvántartás: a régi név kikerül; átnevezésnél a szín megy tovább, összevonásnál a célé marad */
	$col = $rk !== null ? (int) $reg[$rk] : null;
	if ($rk !== null) { unset($reg[$rk]); }
	if ($op !== 'delete') {
		$tk = null; foreach ($reg as $k => $c) { if (mb_strtolower($k) === mb_strtolower($to)) { $tk = $k; break; } }
		if ($tk === null) { $reg[$to] = $col !== null ? $col : count($reg) % 8; }
	}
	cp_mail_tag_reg_save($reg);
	if (function_exists('cp_audit_add')) { cp_audit_add('levelezes', 'tag_' . $op, 'Címke', $n, $txt . ' (' . $done . ' szál)'); }
	wp_send_json_success(array('tags' => cp_mail_tags_all(), 'n' => $done));
}

/* ------------------------------------------------------------ v2.9.37: bejövő számlák automatikus „Számla” címkéje
 * Számla: a PDF/XML melléklet neve számlára utal, VAGY a tárgy számlára utal (nem válasz), és számlázótól jön, vagy cégtől PDF/XML-lel.
 * A magánszemély „számla” tárgyú levele melléklet-név nélkül, a számláló és a számlázási adatok nem kapnak címkét. Csak bejövő levélre. */
const CP_MAIL_INV_TAG = 'Számla';
function cp_mail_inv_subj($s) {
	$s = preg_replace('/sz[aá]ml[aá]l[oó]|sz[aá]ml[aá]z[aá]s\w*/iu', '', (string) $s); /* számláló, számlázási adatok: nem számla */
	return (bool) preg_match('/(sz[aá]ml[aá]|d[ií]jbek[eé]r|proforma|invoice|rechnung|factur|fizet[eé]si [eé]rtes[ií]t)/iu', $s);
}
function cp_mail_inv_private($e) { return (bool) preg_match('/@(gmail\.com|googlemail\.com|freemail\.hu|citromail\.hu|yahoo\.[a-z.]+|hotmail\.[a-z.]+|outlook\.[a-z.]+|live\.[a-z.]+|icloud\.com|me\.com|t-online\.hu|vipmail\.hu|indamail\.hu)$/i', (string) $e); }
function cp_mail_inv_sender($e) { return (bool) preg_match('/@([\w.-]+\.)?(szamlazz\.hu|billingo\.(hu|io)|szamlazo\.hu|kulcs-soft\.hu|nav\.gov\.hu)$/i', (string) $e); }
function cp_mail_inv_match($subj, $from, $atts) {
	$names = array_map(function ($a) { return (string) (is_array($a) ? ($a['n'] ?? '') : $a); }, (array) $atts);
	/* a melléklet neve: „számla…”, „szamlakep”, „SZLA_…” (Számlázz.hu, könyvelők), „invoice”, „díjbekérő” stb. */
	foreach ($names as $n) { $nn = str_replace(array('_', '-', '.'), ' ', $n); if (preg_match('/\.(pdf|xml)$/i', $n) && (cp_mail_inv_subj($nn) || preg_match('/(^|[^a-z])(szla|e ?szla|eszamla)([^a-z]|$)/i', $nn))) { return true; } }
	/* a tárgy alapján csak új levél (nem válasz/továbbítás), és cégtől PDF/XML-lel vagy számlázótól – a magánszemély „számla” tárgyú levele többnyire az ügyfél kérdése */
	if (!cp_mail_inv_subj($subj) || preg_match('/^\s*(re|v[aá]|v[aá]lasz|aw|sv|fw|fwd|tov)\s*:/iu', (string) $subj)) { return false; }
	if (cp_mail_inv_sender($from)) { return true; }
	if (cp_mail_inv_private($from)) { return false; }
	foreach ($names as $n) { if (preg_match('/\.(pdf|xml)$/i', $n)) { return true; } }
	return false;
}
function cp_mail_tag_add($tid, $tag) {
	global $wpdb;
	$T = cp_mail_t('threads');
	$cur = (string) $wpdb->get_var($wpdb->prepare("SELECT tags FROM $T WHERE id = %d", $tid));
	list($sys, $user) = cp_mail_tags_parts($cur);
	foreach ($user as $n) { if (mb_strtolower($n) === mb_strtolower($tag)) { return false; } }
	$user[] = $tag;
	$wpdb->update($T, array('tags' => cp_mail_tags_join($sys, $user)), array('id' => (int) $tid));
	cp_mail_tag_register(array($tag));
	return true;
}
/* egyszeri visszamenőleges címkézés (az elmúlt 12 hónap bejövő levelei), a levélszinkron első futásakor */
function cp_mail_inv_backfill() {
	/* v2.9.39: „2” – újrafuttatás a bővített szabállyal (SZLA_… mellékletnév); csak címkét tesz rá, kétszer nem */
	if (get_option('cp_mail_inv_back') === '2') { return; }
	update_option('cp_mail_inv_back', '2', false);
	global $wpdb;
	$n = 0;
	foreach ((array) $wpdb->get_results('SELECT thread_id, subject, from_email, attachments FROM ' . cp_mail_t('messages') . " WHERE dir = 'in' AND sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 365 DAY) ORDER BY id DESC LIMIT 20000", ARRAY_A) as $r) {
		if (cp_mail_inv_match($r['subject'], $r['from_email'], json_decode((string) $r['attachments'], true) ?: array()) && cp_mail_tag_add((int) $r['thread_id'], CP_MAIL_INV_TAG)) { $n++; }
	}
	update_option('cp_mail_inv_back_n', $n, false);
}

