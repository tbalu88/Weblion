<?php
/* =====================================================================
 * Levélsablonok egyszeri tisztítása (v2.9.15)
 * – a Salesmate-ből átjött, szövegként megjelenő CSS-sorok törlése (pl. „p.p1 {margin: …}”, „@media … { .column.first {…} }”)
 * – a Salesmate-helytartók cseréje a panel magyar helytartóira ({{Contact.name}} → {{kontakt.nev}} …)
 * Csak az admin futtathatja; előbb próbafuttatás (semmit nem ír), mentés előtt a régi sablonok biztonsági másolata
 * a cp_mail_tpl_backup opcióba kerül, így visszaállítható.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_tplc_map() {
	return array(
		'Contact.name' => '{{kontakt.nev}}', 'Contact.id' => '{{kontakt.azonosito}}', 'Contact.email' => '{{kontakt.email}}',
		'Deal.intCustomField3' => '{{rendezveny.oraszam}}', 'Deal.textCustomField1' => '{{rendezveny.helyszin}}', 'Deal.intCustomField2' => '{{kiszallasi.dij}}',
		'Deal.id' => '{{deal.azonosito}}', 'Deal.ugyfelportal_link' => '{{portal.link}}', 'Deal.intCustomField1' => '{{rendezveny.letszam}}',
	);
}
/* egy helytartó magyar megfelelője (null: marad) */
function cp_tplc_ph($raw) {
	$s = preg_replace('/\s+/u', ' ', html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));
	if (!preg_match('/^\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*((?:\|[^}]*)?)\}\}$/u', $s, $m)) { return null; }
	$k = $m[1]; $f = trim(preg_replace('/\s+/u', '', $m[2]));
	if ($k === 'Deal.dateCustomField1') {
		if ($f === '|date:"%Y.%m.%d."' || $f === "|date:'%Y.%m.%d.'") { return '{{rendezveny.nap}}.'; }
		if ($f === '|date:"%Y.%m.%d"' || $f === "|date:'%Y.%m.%d'") { return '{{rendezveny.nap}}'; }
		return null;
	}
	if ($k === 'Deal.dealValue') { return ($f === "|money_without_currency|remove:'.00'" || $f === '|money_without_currency') ? '{{netto.ar}}' : null; }
	$M = cp_tplc_map();
	return ($f === '' && isset($M[$k])) ? $M[$k] : null;
}
/* a szövegként maradt CSS törlése: csak a címkék közötti szövegből, a helytartókat védve */
function cp_tplc_css($html, &$removed) {
	$sel = '(?:(?:(?:p|li|span|ul|ol|div|td|table|body|a|h[1-6])(?=[.#])|(?<![\w-]))[.#][\w-]+(?:[.#][\w-]+)*(?:\s+[.#][\w-]+(?:[.#][\w-]+)*)*|(?<![\w-])(?:\*|html|body))';
	$list = $sel . '(?:\s*,\s*' . $sel . ')*';
	$parts = preg_split('/(<[^>]*>)/u', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE);
	foreach ($parts as $i => $p) {
		if ($p === '' || $p[0] === '<') { continue; }
		$t = $p;
		do { $t = preg_replace('/' . $list . '\s*\{[^{}]*:[^{}]*\}/u', '', $t, -1, $n); } while ($n);
		$t = preg_replace('/@media[^{}]*\{\s*\}/u', '', $t);
		/* a levélszerkesztő beágyazott beállításai ({"contentAreaWidth": …}) */
		$j = strpos($t, '{"contentAreaWidth"'); if ($j !== false) { $e = strrpos($t, '}'); if ($e !== false && $e > $j) { $t = substr($t, 0, $j) . substr($t, $e + 1); } }
		if ($t !== $p && preg_match('/^[\s{}]*$/u', $t)) { $t = preg_match('/^\s+$/u', $p) ? $p : ''; }
		if ($t !== $p) { $removed[] = trim(mb_substr(preg_replace('/\s+/u', ' ', $p), 0, 90)); $parts[$i] = $t; }
	}
	/* a @media-blokk nyitó része (pl. „@media only screen and (…) {”) a szöveg elején maradhat */
	$out = implode('', $parts);
	$out = preg_replace_callback('/(^|>)([^<]*@media[^<{]*\{[\s}]*)(?=<|$)/u', function ($m) use (&$removed) { $removed[] = trim(mb_substr(preg_replace('/\s+/u', ' ', $m[2]), 0, 90)); return $m[1]; }, $out);
	return $out;
}
function cp_tplc_one($row) {
	$rep = array(); $css = array(); $left = array();
	$fix = function ($s) use (&$rep, &$left) {
		return preg_replace_callback('/\{\{[^}]*\}\}/u', function ($m) use (&$rep, &$left) {
			$n = cp_tplc_ph($m[0]);
			$k = preg_replace('/\s+/u', ' ', html_entity_decode($m[0], ENT_QUOTES, 'UTF-8'));
			if ($n === null) { if (preg_match('/\{\{\s*(Deal|Contact|Company)\./', $k)) { $left[$k] = 1; } return $m[0]; }
			$rep[$k . ' → ' . $n] = ($rep[$k . ' → ' . $n] ?? 0) + 1;
			return $n;
		}, (string) $s);
	};
	$subj = $fix($row['subject']);
	$body = $fix(cp_tplc_css($row['body'], $css));
	return array('subject' => $subj, 'body' => $body, 'rep' => $rep, 'css' => $css, 'left' => array_keys($left), 'changed' => $subj !== $row['subject'] || $body !== $row['body']);
}
/* próbafuttatás / mentés; visszatér: sablononkénti összesítő */
function cp_tplc_run($apply) {
	global $wpdb;
	$T = cp_mail_t('templates');
	$rows = $wpdb->get_results("SELECT id, name, subject, body FROM $T ORDER BY id", ARRAY_A);
	$out = array(); $bk = array(); $new = array();
	foreach ($rows as $r) {
		$x = cp_tplc_one($r);
		if (!$x['changed'] && !$x['left']) { continue; }
		$out[] = array('id' => (int) $r['id'], 'name' => $r['name'], 'rep' => $x['rep'], 'css' => $x['css'], 'left' => $x['left']);
		if ($x['changed']) { $bk[(int) $r['id']] = array('subject' => $r['subject'], 'body' => $r['body']); $new[(int) $r['id']] = array('subject' => $x['subject'], 'body' => $x['body']); }
	}
	if ($apply && $new) {
		/* előbb a biztonsági másolat (az első futásé marad meg), csak utána az írás */
		if (!get_option('cp_mail_tpl_backup')) { update_option('cp_mail_tpl_backup', array('at' => time(), 'rows' => $bk), false); }
		foreach ($new as $id => $v) { $wpdb->update($T, $v, array('id' => $id)); }
	}
	return array('n' => count($rows), 'changed' => count($out), 'list' => $out);
}
