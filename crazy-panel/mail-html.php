<?php
/* =====================================================================
 * Bejövő levelek eredeti (HTML) nézete (v1.67)
 * – a levél HTML-je szerveroldalon szűrve (szkript, űrlap, beágyazott oldal, eseménykezelő, veszélyes hivatkozás ki),
 * – a panel elzárt keretben (sandbox, szkript nélkül, szigorú CSP) jeleníti meg; külső képek csak kérésre,
 * – a beágyazott (cid:) képek a levél mellékletei közül („cpatt:<sorszám>” jelölővel, a panel teszi rá a letöltési címet),
 * – új levelek importáláskor, a régiek első megnyitáskor (a postafiókból letöltve) kapják meg; az eredmény tárolva.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

/* a szűrés: DOM-alapú, csak az e-mailekben szokásos, ártalmatlan elemek és attribútumok maradnak */
function cp_mail_html_safe($html, $cids = array()) {
	$html = (string) $html;
	if (trim($html) === '' || !class_exists('DOMDocument')) { return ''; }
	if (strlen($html) > 1500000) { $html = substr($html, 0, 1500000); }
	$prev = libxml_use_internal_errors(true);
	$doc = new DOMDocument();
	$doc->loadHTML('<?xml encoding="UTF-8"?><!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . preg_replace('#^.*?<body[^>]*>#is', '', preg_replace('#</body>.*$#is', '', $html)) . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
	/* a <head>-ben lévő stílusok is kellenek (a levelek többsége ott formáz) */
	$styles = '';
	if (preg_match_all('#<style[^>]*>(.*?)</style>#is', preg_match('#<head[^>]*>(.*?)</head>#is', $html, $hm) ? $hm[1] : '', $sm)) { foreach ($sm[1] as $css) { $styles .= cp_mail_css_safe($css) . "\n"; } }
	libxml_clear_errors(); libxml_use_internal_errors($prev);
	$drop = array('script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea', 'option', 'meta', 'link', 'base', 'title', 'svg', 'math', 'video', 'audio', 'source', 'track', 'template', 'portal', 'noscript', 'canvas', 'dialog', 'head');
	$xp = new DOMXPath($doc);
	foreach ($drop as $tag) { foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $n) { if ($n->parentNode) { $n->parentNode->removeChild($n); } } }
	foreach (iterator_to_array($xp->query('//comment()')) as $n) { $n->parentNode->removeChild($n); }
	$okUrl = function ($v, $img) use ($cids) {
		$v = trim(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$v = preg_replace('/[\x00-\x20]+/', '', substr($v, 0, 12)) . substr($v, 12);
		if ($img && stripos($v, 'cid:') === 0) { $c = trim(substr($v, 4), '<> '); return isset($cids[$c]) ? 'cpatt:' . (int) $cids[$c] : ''; }
		if (preg_match('#^https?://#i', $v)) { return $v; }
		if (!$img && preg_match('#^(mailto|tel):#i', $v)) { return $v; }
		if (!$img && strpos($v, '#') === 0) { return $v; }
		if ($img && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $v)) { return $v; }
		return '';
	};
	foreach (iterator_to_array($xp->query('//*')) as $el) {
		if ($el->tagName === 'style') { $el->nodeValue = cp_mail_css_safe($el->textContent); continue; }
		foreach (iterator_to_array($el->attributes) as $a) {
			$k = strtolower($a->name);
			if (strpos($k, 'on') === 0 || in_array($k, array('formaction', 'action', 'srcdoc', 'srcset', 'ping', 'xmlns', 'data', 'codebase', 'dynsrc', 'lowsrc', 'poster', 'manifest'), true) || strpos($k, ':') !== false) { $el->removeAttribute($a->name); continue; }
			if ($k === 'href') { $u = $okUrl($a->value, false); if ($u === '') { $el->removeAttribute($a->name); } else { $el->setAttribute('href', $u); } continue; }
			if ($k === 'src' || $k === 'background') { $u = $okUrl($a->value, true); if ($u === '') { $el->removeAttribute($a->name); } else { $el->setAttribute($k, $u); } continue; }
			if ($k === 'style') { $el->setAttribute('style', cp_mail_css_safe($a->value)); continue; }
		}
		if ($el->tagName === 'a' && $el->hasAttribute('href')) { $el->setAttribute('target', '_blank'); $el->setAttribute('rel', 'noopener noreferrer'); }
	}
	$body = $doc->getElementsByTagName('body')->item(0);
	$out = '';
	if ($body) { foreach ($body->childNodes as $c) { $out .= $doc->saveHTML($c); } }
	if (trim(wp_strip_all_tags($out)) === '' && stripos($out, '<img') === false) { return ''; }
	return ($styles !== '' ? '<style>' . $styles . '</style>' : '') . $out;
}

/* stíluslap: semmi, ami kódot futtatna vagy máshonnan töltene be stíluslapot (a képeket a keret CSP-je kezeli) */
function cp_mail_css_safe($css) {
	$css = (string) $css;
	$css = preg_replace('#/\*.*?\*/#s', '', $css);
	$css = preg_replace('#@import[^;]*;?#i', '', $css);
	$css = preg_replace('#(expression\s*\(|javascript:|vbscript:|-moz-binding|behavior\s*:)#i', 'x-blocked(', $css);
	return str_ireplace('</style', '', $css);
}

/* van-e benne külső kép (http/https) – a „Képek megjelenítése” sávhoz */
function cp_mail_html_ext($html) {
	return (bool) preg_match('#(src|background)\s*=\s*["\']?\s*https?:|url\(\s*["\']?\s*https?:#i', (string) $html);
}

/* a levél HTML-része a postafiókból (régi levelekhez) – array(html, cidmap) vagy WP_Error */
function cp_mail_html_fetch($m) {
	if (!function_exists('cp_att_locate') || !function_exists('cp_imp_open') || cp_imp_pw() === '') { return new WP_Error('cp_mail', 'A postafiók nincs bekötve.'); }
	$loc = cp_att_locate($m);
	if (!$loc) { return array('', array()); }
	$mb = cp_imp_open($loc[0]);
	if (is_wp_error($mb)) { return $mb; }
	$st = @imap_fetchstructure($mb, $loc[1], FT_UID);
	$found = null;
	$walk = function ($part, $num) use (&$walk, &$found) {
		if ($found) { return; }
		$type = (int) $part->type; $sub = strtolower((string) ($part->subtype ?? ''));
		if ($type === 1 && !empty($part->parts)) { foreach ($part->parts as $i => $p) { $walk($p, ($num === '' ? '' : $num . '.') . ($i + 1)); } return; }
		$fname = cp_imp_param($part, 'filename') ?: cp_imp_param($part, 'name');
		if ($type === 0 && $sub === 'html' && $fname === '') { $found = array($num === '' ? '1' : $num, (int) $part->encoding, cp_imp_param($part, 'charset')); }
	};
	if ($st) { $walk($st, ''); }
	$html = '';
	if ($found) {
		$raw = (string) imap_fetchbody($mb, $loc[1], $found[0], FT_UID | FT_PEEK);
		if (strlen($raw) > 1500000) { $raw = substr($raw, 0, 1500000); }
		$html = cp_imp_decode($raw, $found[1], $found[2]);
	}
	$cids = array();
	foreach (cp_att_parts($mb, $loc[1]) as $i => $p) { if (!empty($p['cid'])) { $cids[$p['cid']] = $i; } }
	imap_errors(); imap_close($mb);
	return array($html, $cids);
}

add_action('wp_ajax_nopriv_cp_mail_html', 'cp_mail_html_ajax');
add_action('wp_ajax_cp_mail_html', 'cp_mail_html_ajax');
function cp_mail_html_ajax() {
	cp_el_auth();
	cp_tab_need(array('mail'));
	if (!cp_mail_db_ok()) { cp_el_err('A levelezés adatbázisa frissül – próbáld újra egy perc múlva.', 503); }
	global $wpdb;
	$M = cp_mail_t('messages');
	$m = $wpdb->get_row($wpdb->prepare("SELECT * FROM $M WHERE id = %d", (int) ($_POST['id'] ?? 0)), ARRAY_A);
	if (!$m) { cp_el_err('Nincs ilyen levél.', 404); }
	if ($m['html'] === null) {
		/* a panelből küldött és az idézetből kinyert levélnek nincs külön eredetije */
		if ($m['status'] === 'Elküldve a panelből' || $m['status'] === 'idézetből') { $h = ''; }
		else {
			$r = cp_mail_html_fetch($m);
			if (is_wp_error($r)) { cp_el_err($r->get_error_message(), 502); }
			$h = cp_mail_html_safe($r[0], $r[1]);
		}
		$wpdb->update($M, array('html' => $h), array('id' => (int) $m['id']));
		$m['html'] = $h;
	}
	wp_send_json_success(array('id' => (int) $m['id'], 'html' => (string) $m['html'], 'ext' => cp_mail_html_ext($m['html'])));
}
