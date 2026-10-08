<?php
/* Ügyfélportál – ügyféloldali sablon (szerver által renderelt; mezőnként mentő kis JavaScripttel: assets/portal.js) */

if (!defined('ABSPATH')) { exit; }

function cp_portal_page_head($title) {
	echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><meta name="theme-color" content="#141C33">';
	echo '<title>' . esc_html($title) . ' – EventCrazy</title>';
	echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
	echo '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">';
	echo '<link rel="stylesheet" href="' . esc_url(plugins_url('assets/portal.css', CP_PORTAL_MAIN)) . '?v=' . CP_PORTAL_VER . '">';
	echo '</head>';
}

function cp_portal_page_top($logout = false) {
	return '<header class="cpp-top"><div class="cpp-logo">Event<span>Crazy</span></div>' . ($logout ? '<a class="cpp-out" href="' . esc_url(cp_portal_url('/kilepes')) . '">Kilépés</a>' : '') . '</header>';
}

/* egyszerű üzenetoldal; $opt: status, newlink (bool), title_small */
function cp_portal_render_message($title, $msg, $opt = array()) {
	if (!empty($opt['status'])) { status_header((int) $opt['status']); }
	cp_portal_page_head($title);
	echo '<body class="cpp">' . cp_portal_page_top();
	echo '<main class="cpp-main"><section class="cpp-card cpp-msg"><h1>' . esc_html($title) . '</h1><p>' . esc_html($msg) . '</p>';
	if (!empty($opt['newlink'])) {
		echo '<form method="post"><input type="hidden" name="cpp_newlink" value="1"><button type="submit" class="cpp-btn">Kérj új linket</button></form>';
		echo '<p class="cpp-muted cpp-small">Az új linket arra az e-mail-címre küldjük, amelyet a foglaláskor megadtál.</p>';
	}
	echo '</section></main></body></html>';
}

/* lejárt / visszavont / ismeretlen link */
function cp_portal_render_invalid($problem, $portal) {
	if ($problem === 'expired' || $problem === 'revoked') {
		$closed = $portal && cp_portal_link_expiry($portal->event_date) < time();
		if ($closed) {
			cp_portal_render_message('A portál lezárult', 'Ennek a rendezvénynek a portálja már lezárult. Ha segíthetünk, keresd az irodát.', array('status' => 410));
			return;
		}
		cp_portal_render_message($problem === 'expired' ? 'Lejárt a link' : 'Ez a link már nem érvényes', 'Biztonsági okból ez a belépőlink már nem használható. Kérj újat, és pár percen belül megérkezik e-mailben.', array('status' => 403, 'newlink' => true));
		return;
	}
	if ($problem === 'closed') {
		cp_portal_render_message('A portál lezárult', 'Ennek a rendezvénynek a portálja már lezárult. Ha segíthetünk, keresd az irodát.', array('status' => 410));
		return;
	}
	cp_portal_render_message('Érvénytelen link', 'Ezt a linket nem ismerjük fel. Ellenőrizd, hogy a teljes linket nyitottad-e meg az e-mailből, vagy keresd az irodát.', array('status' => 404));
}

/* ------------------------------------------------------------ az adatlap */

function cp_portal_render_form_page($portal, $tok) {
	$portal = cp_portal_sm_refresh($portal);
	$payload = cp_portal_form_payload($portal);
	$answers = (array) $payload['answers'];
	$prefill = (array) $payload['prefill'];
	$locked = $payload['locked'];
	$sm = $portal->sm;
	$o = cp_portal_opts();
	$first = isset($sm['name']) ? trim((string) $sm['name']) : '';
	if ($first !== '') { $pp = preg_split('/\s+/u', $first); $first = count($pp) > 1 ? $pp[count($pp) - 1] : $pp[0]; }  // magyar névsorrend: a keresztnév a végén
	$st = $payload['state'];
	$total = $st['required_total'];
	$done = $st['required_done'];

	cp_portal_page_head('Rendezvény-adatlap');
	echo '<body class="cpp">' . cp_portal_page_top(true);
	echo '<main class="cpp-main">';
	echo '<section class="cpp-hero"><small>Rendezvény-adatlap</small><h1>' . esc_html($first !== '' ? 'Szia, ' . $first . '!' : 'Szia!') . '</h1>';
	echo '<p>' . esc_html(cp_portal_hu_date($portal->event_date)) . (!empty($sm['package']) ? ' · ' . esc_html($sm['package']) : '') . '</p>';
	echo '<p class="cpp-lead">Néhány kérdés a helyszínről, hogy a kollégánk mindenre felkészülten érkezzen. Minden válasz azonnal mentődik, bármikor visszajöhetsz folytatni.</p></section>';

	echo '<section class="cpp-card cpp-status" id="cpp-status">';
	echo '<div class="cpp-prog"><div class="cpp-prog-t"><b id="cpp-done">' . (int) $done . '</b> / ' . (int) $total . ' kötelező kérdés kész</div><div class="cpp-bar"><i id="cpp-bar" style="width:' . ($total ? round($done / $total * 100) : 0) . '%"></i></div></div>';
	if ($payload['deadline']) {
		echo '<p class="cpp-dl">' . ($locked ? 'A kitöltés határideje lejárt: ' : 'Kérjük, töltsd ki eddig: ') . '<b>' . esc_html($payload['deadline']['text']) . '</b></p>';
	}
	if ($locked) {
		$contact = sanitize_email($o['reply_to']);
		echo '<p class="cpp-lock">Az adatlap már csak olvasható. Változás esetén írj nekünk' . ($contact !== '' ? ': <a href="mailto:' . esc_attr($contact) . '">' . esc_html($contact) . '</a>' : '') . '.</p>';
	}
	echo '</section>';

	echo '<form id="cpp-form" novalidate' . ($locked ? ' class="locked"' : '') . '>';
	$fields = cp_portal_fields();
	foreach (cp_portal_sections() as $sec => $title) {
		echo '<section class="cpp-sec" data-sec="' . esc_attr($sec) . '">';
		if ($title !== '') { echo '<h2>' . esc_html($title) . '</h2>'; }
		foreach ($fields as $k => $f) {
			if ($f['section'] !== $sec) { continue; }
			$val = array_key_exists($k, $answers) ? $answers[$k] : (isset($prefill[$k]) ? (array) $prefill[$k] : null);
			echo cp_portal_field_html($k, $f, $val, $locked, array_key_exists($k, $answers));
		}
		echo '</section>';
	}
	if (!$locked) {
		echo '<section class="cpp-card cpp-done"><button type="button" class="cpp-btn" id="cpp-complete">Kész, elküldöm</button>';
		echo '<div id="cpp-result" role="status" aria-live="polite"></div>';
		echo '<p class="cpp-muted cpp-small">A „Kész” után is módosíthatsz a határidőig – minden változást látunk.</p></section>';
	}
	echo '</form>';
	$contact = sanitize_email($o['reply_to']);
	echo '<p class="cpp-foot">Kérdésed van? ' . ($contact !== '' ? 'Írj nekünk: <a href="mailto:' . esc_attr($contact) . '">' . esc_html($contact) . '</a>' : 'Keresd az irodát.') . '</p>';
	echo '</main>';
	$cfg = array(
		'rest' => wp_make_link_relative(rest_url('crazy/v1/portal/form')),
		'nonce' => cp_portal_nonce($tok),
		'locked' => $locked,
		'total' => $total,
		'pid' => (int) $portal->id,
	);
	echo '<script>window.CPP=' . wp_json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
	echo '<script src="' . esc_url(plugins_url('assets/portal.js', CP_PORTAL_MAIN)) . '?v=' . CP_PORTAL_VER . '"></script>';
	echo '</body></html>';
}

/* egy mező kártyája; $val a mentett válasz vagy az előtöltés */
function cp_portal_field_html($k, $f, $val, $locked, $saved) {
	$dis = $locked ? ' disabled' : '';
	$kind = isset($f['parts']) ? 'object' : $f['kind'];
	$h = '<div class="cpp-field' . (!empty($f['required']) ? ' req' : '') . '" id="f-' . esc_attr($k) . '" data-field="' . esc_attr($k) . '" data-kind="' . esc_attr($kind) . '" data-q="' . esc_attr($f['q']) . '">';
	if ($kind === 'consent') {
		$on = is_array($val) && !empty($val['given']);
		$h .= '<label class="cpp-consent"><input type="checkbox" data-part=""' . ($on ? ' checked' : '') . $dis . '><span>' . esc_html($f['label']) . '</span></label>';
		$h .= '<p class="cpp-muted cpp-small">Nem kötelező, és bármikor visszavonhatod – ilyenkor a rendezvény után 30 nappal töröljük az adatlapot.</p>';
		$h .= '<div class="cpp-fs"><span class="cpp-saved" aria-live="polite"></span></div><p class="cpp-ferr" hidden></p></div>';
		return $h;
	}
	$h .= '<div class="cpp-fh"><span class="cpp-fl" id="l-' . esc_attr($k) . '">' . esc_html($f['label']) . '</span>' . (!empty($f['required']) ? '<span class="cpp-req">kötelező</span>' : '') . '<span class="cpp-saved' . ($saved ? ' ok' : '') . '" aria-live="polite">' . ($saved ? 'Mentve' : '') . '</span></div>';
	if (!empty($f['help'])) { $h .= '<p class="cpp-help">' . esc_html($f['help']) . '</p>'; }
	if ($kind === 'object') {
		$v = is_array($val) ? $val : array();
		foreach ($f['parts'] as $pk => $p) {
			$show = '';
			if (!empty($p['show_if'])) {
				$cur = isset($v[$p['show_if'][0]]) ? (string) $v[$p['show_if'][0]] : '';
				$show = ' data-show-if="' . esc_attr($p['show_if'][0] . '=' . $p['show_if'][1]) . '"' . ($cur === $p['show_if'][1] ? '' : ' hidden');
			}
			$h .= '<div class="cpp-part" data-part="' . esc_attr($pk) . '" data-pkind="' . esc_attr($p['kind']) . '"' . (!empty($p['req']) || !empty($p['show_if']) ? ' data-req' : '') . $show . '>';
			$h .= cp_portal_input_html($k . '__' . $pk, $p, isset($v[$pk]) ? $v[$pk] : '', $dis, $p['label'], $f['label']);
			$h .= '</div>';
		}
	} else {
		$p = array('kind' => $kind, 'max' => isset($f['max']) ? $f['max'] : 200, 'options' => isset($f['options']) ? $f['options'] : array(), 'ph' => isset($f['ph']) ? $f['ph'] : '');
		$h .= '<div class="cpp-part" data-part="" data-pkind="' . esc_attr($kind) . '">' . cp_portal_input_html($k, $p, is_scalar($val) ? $val : '', $dis, '', $f['label']) . '</div>';
	}
	$h .= '<p class="cpp-ferr" role="alert" hidden></p></div>';
	return $h;
}

/* $label: látható címke (üres = nincs), $aria: a mező címe a képernyőolvasónak */
function cp_portal_input_html($name, $p, $v, $dis, $label, $aria) {
	$id = 'i-' . $name;
	$ph = isset($p['ph']) && $p['ph'] !== '' ? ' placeholder="' . esc_attr($p['ph']) . '"' : '';
	$lab = $label !== '' ? '<label class="cpp-pl" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>' : '';
	$al = $label === '' ? ' aria-label="' . esc_attr($aria) . '"' : '';
	switch ($p['kind']) {
		case 'enum':
			$h = '<div class="cpp-opts" role="radiogroup" aria-label="' . esc_attr($label !== '' ? $label : $aria) . '">';
			foreach ($p['options'] as $ov => $ol) {
				$h .= '<label><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($ov) . '"' . ((string) $v === (string) $ov ? ' checked' : '') . $dis . '><span>' . esc_html($ol) . '</span></label>';
			}
			return ($label !== '' ? '<span class="cpp-pl">' . esc_html($label) . '</span>' : '') . $h . '</div>';
		case 'textarea':
			return $lab . '<textarea id="' . esc_attr($id) . '" rows="4"' . $al . ' maxlength="' . (int) $p['max'] . '"' . $ph . $dis . '>' . esc_textarea((string) $v) . '</textarea>';
		case 'time':
			return $lab . '<input id="' . esc_attr($id) . '" type="time" step="300"' . $al . ' value="' . esc_attr((string) $v) . '"' . $dis . '>';
		case 'int':
			return $lab . '<input id="' . esc_attr($id) . '" type="text"' . $al . ' inputmode="numeric" pattern="[0-9]*" autocomplete="off" value="' . esc_attr((string) $v) . '"' . $ph . $dis . '>';
		case 'tel':
			return $lab . '<input id="' . esc_attr($id) . '" type="tel"' . $al . ' autocomplete="tel" maxlength="' . (int) $p['max'] . '" value="' . esc_attr((string) $v) . '"' . $ph . $dis . '>';
		default:
			return $lab . '<input id="' . esc_attr($id) . '" type="text"' . $al . ' maxlength="' . (int) (isset($p['max']) ? $p['max'] : 200) . '" value="' . esc_attr((string) $v) . '"' . $ph . $dis . '>';
	}
}
