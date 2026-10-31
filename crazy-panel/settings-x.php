<?php
/* =====================================================================
 * v1.79: a WordPress-admin beállítóoldalai a panel Beállítások fülén (csak admin)
 * Minden oldal egy mezőleírás (cp_sx_sections): mezők (opció + kulcs), titkok (csak cserélhetők,
 * vissza soha nem olvashatók), állapotsorok és műveletgombok. Ugyanazokat az opciókat írja, mint a
 * WordPress-oldalak – azok tartaléknak megmaradnak.
 * Mezőtípusok: text, num, sw (kapcsoló), sel (o: [[érték, felirat], …]), area, secret, info (csak szöveg).
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_sx_sections() {
	static $S = null;
	if ($S === null) { $S = apply_filters('cp_sx_sections', array()); }
	return $S;
}
function cp_sx_auth() {
	if (function_exists('cp_set_auth')) { cp_set_auth(true); return; }
	cp_el_err('Hibás kérés.', 403);
}
function cp_sx_raw($f) {
	if (isset($f['get'])) { return call_user_func($f['get']); }
	if (empty($f['opt'])) { return $f['def'] ?? ''; }
	$o = get_option($f['opt'], null);
	if (!isset($f['sub'])) { return $o === null || $o === false ? ($f['def'] ?? '') : $o; }
	return is_array($o) && array_key_exists($f['sub'], $o) ? $o[$f['sub']] : ($f['def'] ?? '');
}
/* egy szakasz a böngészőnek: titok helyett csak „be van-e állítva” */
function cp_sx_out($key) {
	$S = cp_sx_sections()[$key] ?? null;
	if (!$S) { return null; }
	$fields = array();
	foreach ($S['fields'] ?? array() as $f) {
		$x = array('k' => $f['k'], 'l' => $f['l'] ?? '', 't' => $f['t'], 'h' => $f['h'] ?? '', 'ph' => $f['ph'] ?? '', 'g' => $f['g'] ?? '');
		if ($f['t'] === 'secret') { $x['has'] = isset($f['has']) ? (bool) call_user_func($f['has']) : ((string) cp_sx_raw($f) !== ''); }
		elseif ($f['t'] === 'info') { $x['v'] = isset($f['get']) ? (string) call_user_func($f['get']) : ''; }
		else {
			$v = cp_sx_raw($f);
			if ($f['t'] === 'sw') { $v = !empty($v) ? 1 : 0; }
			elseif ($f['t'] === 'area' && is_array($v)) { $v = implode("\n", array_map('strval', $v)); }
			elseif (is_array($v)) { $v = wp_json_encode($v); }
			$x['v'] = $f['t'] === 'num' ? (float) $v : (string) $v;
			if (isset($f['o'])) { $x['o'] = is_callable($f['o']) ? call_user_func($f['o']) : $f['o']; }
			foreach (array('min', 'max', 'step', 'rows') as $z) { if (isset($f[$z])) { $x[$z] = $f[$z]; } }
		}
		$fields[] = $x;
	}
	$info = isset($S['info']) ? (array) call_user_func($S['info']) : array();
	$acts = array();
	foreach ($S['acts'] ?? array() as $a) { $acts[] = array('k' => $a['k'], 'l' => $a['l'], 'c' => $a['c'] ?? '', 'bad' => !empty($a['bad'])); }
	return array('key' => $key, 'n' => $S['n'], 'd' => $S['d'] ?? '', 'fields' => $fields, 'info' => $info, 'acts' => $acts, 'wp' => isset($S['wp']) ? admin_url('options-general.php?page=' . $S['wp']) : '', 'save' => !empty(array_filter($S['fields'] ?? array(), function ($f) { return $f['t'] !== 'info'; })));
}
function cp_sx_clean($f, $v) {
	switch ($f['t']) {
		case 'sw': return !empty($v) ? 1 : 0;
		case 'num':
			$n = is_numeric($v) ? $v + 0 : ($f['def'] ?? 0);
			if (isset($f['min'])) { $n = max($f['min'], $n); }
			if (isset($f['max'])) { $n = min($f['max'], $n); }
			return !empty($f['int']) ? (int) $n : $n;
		case 'sel':
			$o = is_callable($f['o']) ? call_user_func($f['o']) : $f['o'];
			$ok = array_map(function ($x) { return (string) $x[0]; }, $o);
			return in_array((string) $v, $ok, true) ? (string) $v : (string) ($f['def'] ?? ($ok[0] ?? ''));
		case 'area': $v = trim(sanitize_textarea_field((string) $v)); break;
		default: $v = trim(sanitize_text_field((string) $v));
	}
	if (isset($f['strip'])) { $v = preg_replace($f['strip'], '', $v); }
	if (isset($f['max_len'])) { $v = mb_substr($v, 0, $f['max_len']); }
	return $v;
}
function cp_sx_save($key, $vals) {
	$S = cp_sx_sections()[$key] ?? null;
	if (!$S) { return new WP_Error('cp_sx', 'Ismeretlen beállításcsoport.'); }
	$opts = array(); $changed = array();
	foreach ($S['fields'] ?? array() as $f) {
		if ($f['t'] === 'info' || !array_key_exists($f['k'], $vals) && !array_key_exists($f['k'] . '_clear', $vals)) { continue; }
		if ($f['t'] === 'secret') {
			$new = trim((string) ($vals[$f['k']] ?? ''));
			if (!empty($vals[$f['k'] . '_clear'])) { $new = ''; $clear = true; } else { $clear = false; }
			if ($new === '' && !$clear) { continue; }
			if (!$clear && isset($f['chk'])) { $r = call_user_func($f['chk'], $new); if (is_wp_error($r)) { return $r; } $new = $r; }
			if (isset($f['set'])) { $r = call_user_func($f['set'], $new); if (is_wp_error($r)) { return $r; } $changed[] = $f['k']; continue; }
			$v = $new;
		} else {
			$v = cp_sx_clean($f, $vals[$f['k']]);
			if (isset($f['chk'])) { $r = call_user_func($f['chk'], $v); if (is_wp_error($r)) { return $r; } $v = $r; }
			if (isset($f['set'])) { $r = call_user_func($f['set'], $v); if (is_wp_error($r)) { return $r; } $changed[] = $f['k']; continue; }
		}
		if (empty($f['opt'])) { continue; }
		if (!isset($f['sub'])) { update_option($f['opt'], $v, $f['autoload'] ?? false); $changed[] = $f['k']; continue; }
		if (!isset($opts[$f['opt']])) { $o = get_option($f['opt'], array()); $opts[$f['opt']] = is_array($o) ? $o : array(); }
		$opts[$f['opt']][$f['sub']] = $v; $changed[] = $f['k'];
	}
	foreach ($opts as $name => $o) { update_option($name, $o, false); }
	if (isset($S['after'])) { call_user_func($S['after'], $vals); }
	return $changed;
}

add_action('wp_ajax_nopriv_cp_sx_get', 'cp_sx_ajax_get');
add_action('wp_ajax_cp_sx_get', 'cp_sx_ajax_get');
function cp_sx_ajax_get() {
	cp_sx_auth();
	$o = cp_sx_out((string) ($_POST['sec'] ?? ''));
	if (!$o) { cp_el_err('Ismeretlen beállításcsoport.', 404); }
	wp_send_json_success($o);
}
add_action('wp_ajax_nopriv_cp_sx_set', 'cp_sx_ajax_set');
add_action('wp_ajax_cp_sx_set', 'cp_sx_ajax_set');
function cp_sx_ajax_set() {
	cp_sx_auth();
	$key = (string) ($_POST['sec'] ?? '');
	$vals = json_decode(wp_unslash((string) ($_POST['vals'] ?? '{}')), true);
	if (!is_array($vals)) { cp_el_err('Hibás adatok.'); }
	$r = cp_sx_save($key, $vals);
	if (is_wp_error($r)) { cp_el_err($r->get_error_message()); }
	wp_send_json_success(array('msg' => $r ? 'Mentve.' : 'Nem változott semmi.', 'data' => cp_sx_out($key)));
}
add_action('wp_ajax_nopriv_cp_sx_act', 'cp_sx_ajax_act');
add_action('wp_ajax_cp_sx_act', 'cp_sx_ajax_act');
function cp_sx_ajax_act() {
	cp_sx_auth();
	$key = (string) ($_POST['sec'] ?? ''); $ak = (string) ($_POST['act'] ?? '');
	$S = cp_sx_sections()[$key] ?? null;
	$a = null; foreach ($S['acts'] ?? array() as $x) { if ($x['k'] === $ak) { $a = $x; } }
	if (!$a) { cp_el_err('Ismeretlen művelet.'); }
	$r = call_user_func($a['fn']);
	if (is_wp_error($r)) { cp_el_err($r->get_error_message()); }
	$out = is_array($r) ? (string) ($r['out'] ?? '') : '';
	$m = is_array($r) ? (string) ($r['msg'] ?? '') : (string) $r;
	wp_send_json_success(array('msg' => $m !== '' ? $m : 'Kész.', 'out' => $out, 'data' => cp_sx_out($key)));
}
