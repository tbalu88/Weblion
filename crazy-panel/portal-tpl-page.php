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
	$flow = $payload['flow'];
	$sm = $portal->sm;
	$o = cp_portal_opts();
	$full = isset($sm['name']) ? trim(preg_replace('/\s+/u', ' ', (string) $sm['name'])) : '';
	$pkg = isset($sm['package']) ? trim(preg_replace('/\s*,\s*/u', ', ', (string) $sm['package'])) : '';
	$st = $payload['state'];
	$ca = (int) $st['contract_total'];
	$cd = (int) $st['contract_done'];
	$bt = (int) $st['required_total'] - $ca;
	$bd = max(0, (int) $st['required_done'] - $cd);
	$pct = function ($d, $t) { return $t ? round($d / $t * 100) : 0; };

	cp_portal_page_head('Ügyfélportál');
	echo '<body class="cpp">' . cp_portal_page_top(true);
	echo '<main class="cpp-main">';
	echo '<section class="cpp-hero"><small>Ügyfélportál</small><h1>' . esc_html($full !== '' ? 'Kedves ' . $full . '!' : 'Kedves Ügyfelünk!') . '</h1>';
	echo '<p>' . esc_html(cp_portal_hu_date($portal->event_date)) . ($pkg !== '' ? ' · ' . esc_html($pkg) : '') . '</p>';
	echo '<p class="cpp-lead">' . ($flow === 'v2'
		? 'Két lépésben kérünk adatokat: először a szerződéshez szükségeseket, aztán a rendezvény részleteit. Minden válasz azonnal mentődik, bármikor visszajöhetsz folytatni.'
		: 'Néhány kérdés a helyszínről, hogy a kollégánk mindenre felkészülten érkezzen. Minden válasz azonnal mentődik, bármikor visszajöhetsz folytatni.') . '</p></section>';

	echo '<section class="cpp-card cpp-status" id="cpp-status">';
	if ($ca > 0) {
		$ready = !empty($st['contract_ready_at']);
		echo '<div class="cpp-prog"><div class="cpp-prog-t">1. Szerződéshez: <b id="cpp-done-a">' . $cd . '</b> / ' . $ca . '<span class="cpp-ready" id="cpp-ready"' . ($ready ? '' : ' hidden') . '>✓ beküldve</span></div><div class="cpp-bar"><i id="cpp-bar-a" style="width:' . $pct($cd, $ca) . '%"></i></div></div>';
	}
	echo '<div class="cpp-prog"><div class="cpp-prog-t">' . ($ca > 0 ? '2. Részletek: ' : '') . '<b id="cpp-done-b">' . $bd . '</b> / ' . $bt . ' kötelező kérdés kész</div><div class="cpp-bar"><i id="cpp-bar-b" style="width:' . $pct($bd, $bt) . '%"></i></div></div>';
	if ($payload['deadline']) {
		echo '<p class="cpp-dl">' . ($locked ? 'A kitöltés határideje lejárt: ' : ($ca > 0 ? 'A részletek határideje: ' : 'Kérjük, töltsd ki eddig: ')) . '<b>' . esc_html($payload['deadline']['text']) . '</b></p>';
	}
	if ($locked) {
		$contact = sanitize_email($o['reply_to']);
		echo '<p class="cpp-lock">Az adatlap már csak olvasható. Változás esetén írj nekünk' . ($contact !== '' ? ': <a href="mailto:' . esc_attr($contact) . '">' . esc_html($contact) . '</a>' : '') . '.</p>';
	}
	echo '</section>';

	echo '<form id="cpp-form" novalidate' . ($locked ? ' class="locked"' : '') . '>';
	$fields = cp_portal_fields();
	$secs = cp_portal_sections();
	$sn = 0;
	foreach (cp_portal_stage_titles() as $stage => $tt) {
		$sn++;
		$secn = 0;
		echo '<div class="cpp-stage" data-stage="' . esc_attr($stage) . '"><div class="cpp-stage-h"><h2><span class="cpp-no">' . $sn . '</span>' . esc_html(preg_replace('/^\d+\.\s*/u', '', $tt[0])) . '</h2><p>' . esc_html($stage === 'a' && $flow === 'v1' ? 'A szerződés már elkészült – ha valami változott, itt javíthatod.' : $tt[1]) . '</p></div>';
		foreach ($secs as $sec => $si) {
			if ($si[0] !== $stage) { continue; }
			$html = '';
			$qn = 0;
			$no = $sn . '.' . ($secn + 1);
			foreach ($fields as $k => $f) {
				if ($f['section'] !== $sec) { continue; }
				if ($k === 'background' && !cp_portal_bg_enabled($portal)) { continue; }
				if (!cp_portal_field_svc_ok($f, $portal)) { continue; }  // pl. a helyigény csak szelfigépnél
				$val = array_key_exists($k, $answers) ? $answers[$k] : (isset($prefill[$k]) ? $prefill[$k] : null);
				if (is_object($val)) { $val = (array) $val; }
				$req = !empty($f['required']) && cp_portal_stage_required($f['stage'], $flow);
				$sub = !empty($f['sub']) || (isset($f['kind']) && $f['kind'] === 'consent');
				if (!$sub) { $qn++; }
				$html .= cp_portal_field_html($k, $f, $val, $locked, array_key_exists($k, $answers), $req, $answers, $portal, $sub ? '' : $no . '.' . $qn);
			}
			if ($html === '') { continue; }
			if ($si[1] !== '') { $secn++; }
			echo '<section class="cpp-sec" data-sec="' . esc_attr($sec) . '">' . ($si[1] !== '' ? '<h3><span class="cpp-no">' . esc_html($no) . '</span>' . esc_html($si[1]) . '</h3>' : '') . $html . '</section>';
		}
		if ($stage === 'a' && $flow === 'v2' && !$locked) {
			echo '<section class="cpp-card cpp-done cpp-contract"><button type="button" class="cpp-btn" id="cpp-contract">Mehet a szerződés – adatok elküldése</button>';
			echo '<div id="cpp-contract-result" class="cpp-result" role="status" aria-live="polite"></div>';
			echo '<p class="cpp-muted cpp-small">A gombbal jelzed, hogy a fenti adatok rendben vannak – ebből készítjük el a szerződést. Később is módosíthatod, ilyenkor nyomd meg újra.</p></section>';
		}
		echo '</div>';
	}
	if (!$locked) {
		echo '<section class="cpp-card cpp-done"><button type="button" class="cpp-btn" id="cpp-complete">Kész, minden adatot elküldök</button>';
		echo '<div id="cpp-result" class="cpp-result" role="status" aria-live="polite"></div>';
		echo '<p class="cpp-muted cpp-small">A „Kész” után is módosíthatsz a határidőig – minden változást látunk.</p></section>';
	}
	echo '</form>';
	$contact = sanitize_email($o['reply_to']);
	echo '<p class="cpp-foot">Kérdésed van? ' . ($contact !== '' ? 'Írj nekünk: <a href="mailto:' . esc_attr($contact) . '">' . esc_html($contact) . '</a>' : 'Keresd az irodát.') . '</p>';
	echo '</main>';
	$cfg = array(
		'rest' => wp_make_link_relative(rest_url('crazy/v1/portal/form')),
		'logoRest' => wp_make_link_relative(rest_url('crazy/v1/portal/template-logo')),
		'fees' => array('magan' => cp_portal_tpl_fee_text('magan'), 'ceg' => cp_portal_tpl_fee_text('ceg'), '' => cp_portal_tpl_fee_text('')),
		'nonce' => cp_portal_nonce($tok),
		'locked' => $locked,
		'pid' => (int) $portal->id,
	);
	echo '<script>window.CPP=' . wp_json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
	echo '<script src="' . esc_url(plugins_url('assets/portal.js', CP_PORTAL_MAIN)) . '?v=' . CP_PORTAL_VER . '"></script>';
	echo '</body></html>';
}

/* egy mező kártyája; $val a mentett válasz vagy az előtöltés */
function cp_portal_field_html($k, $f, $val, $locked, $saved, $req, $answers, $portal, $no = '') {
	$dis = $locked ? ' disabled' : '';
	$kind = isset($f['parts']) ? 'object' : $f['kind'];
	$sif = '';
	if (!empty($f['show_if'])) {
		$sif = ' data-show-if-field="' . esc_attr($f['show_if'][0] . '=' . $f['show_if'][1]) . '"' . (cp_portal_field_active($f, $answers + (array) cp_portal_prefill($portal), $portal) ? '' : ' hidden');
	}
	$h = '<div class="cpp-field' . ($req ? ' req' : '') . (!empty($f['sub']) ? ' sub' : '') . '" id="f-' . esc_attr($k) . '" data-field="' . esc_attr($k) . '" data-kind="' . esc_attr($kind) . '" data-q="' . esc_attr($f['q']) . '"' . $sif . '>';
	if ($kind === 'consent') {
		$on = is_array($val) && !empty($val['given']);
		$h .= '<label class="cpp-consent"><input type="checkbox" data-part=""' . ($on ? ' checked' : '') . $dis . '><span>' . esc_html($f['label']) . '</span></label>';
		$h .= '<p class="cpp-muted cpp-small">Nem kötelező, és bármikor visszavonhatod – ilyenkor a rendezvény után 30 nappal töröljük az adatlapot.</p>';
		$h .= '<div class="cpp-fs"><span class="cpp-saved" aria-live="polite"></span></div><p class="cpp-ferr" hidden></p></div>';
		return $h;
	}
	$h .= '<div class="cpp-fh"><span class="cpp-fl" id="l-' . esc_attr($k) . '">' . ($no !== '' ? '<span class="cpp-no">' . esc_html($no) . '</span>' : '') . esc_html($f['label']) . '</span>' . ($req ? '<span class="cpp-req">kötelező</span>' : '') . '<span class="cpp-saved' . ($saved ? ' ok' : '') . '" aria-live="polite">' . ($saved ? 'Mentve' : '') . '</span></div>';
	if (!empty($f['help'])) { $h .= '<p class="cpp-help">' . esc_html($f['help']) . '</p>'; }
	if ($k === 'space_ok') { $h .= cp_portal_space_svg(); }
	if ($k === 'onsite_contact' && !$locked) {
		$h .= '<label class="cpp-same"><input type="checkbox" data-same-as-client> Ugyanaz, mint a megrendelő (kitöltöm az adataival)</label>';
	}
	if ($kind === 'fixed') {
		$pkg = isset($portal->sm['package']) ? trim(preg_replace('/\s*,\s*/u', ', ', (string) $portal->sm['package'])) : '';
		$hrs = isset($portal->sm['hours']) ? (float) $portal->sm['hours'] : 0;
		$h .= '<div class="cpp-fixed">' . esc_html($pkg !== '' ? $pkg : 'A foglalás szerint') . ($hrs > 0 ? ' · ' . esc_html(cp_portal_hours_text($hrs)) : '') .
			'<small>A foglalás szerint, itt nem módosítható. Ha változtatnál rajta, írd meg a különleges kérésnél, vagy keress minket.</small></div>';
	} elseif ($kind === 'object') {
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
	} elseif ($kind === 'bg') {
		$h .= cp_portal_bg_html($portal, is_string($val) ? $val : '', $dis);
	} elseif ($kind === 'sablon') {
		$h .= cp_portal_sablon_card($portal, is_array($val) ? $val : null, $locked);
	} else {
		$p = array('kind' => $kind, 'max' => isset($f['max']) ? $f['max'] : 200, 'options' => isset($f['options']) ? $f['options'] : array(), 'ph' => isset($f['ph']) ? $f['ph'] : '');
		$h .= '<div class="cpp-part" data-part="" data-pkind="' . esc_attr($kind) . '">' . cp_portal_input_html($k, $p, is_scalar($val) ? $val : '', $dis, '', $f['label']) . '</div>';
	}
	if ($k === 'schedule') {
		$du = cp_portal_duration_info($portal, $answers);
		if ($du['pkg'] > 0) {
			$h .= '<p class="cpp-dur' . ($du['diff'] ? ' diff' : '') . '" data-pkg-hours="' . esc_attr($du['pkg']) . '">' . esc_html(cp_portal_duration_text($du)) . '</p>';
		}
	}
	if ($k === 'template_mode') {
		$ct = isset($answers['client_type']) && is_string($answers['client_type']) ? $answers['client_type'] : '';
		$h .= '<p class="cpp-note" data-tpl-fee>' . esc_html(cp_portal_tpl_fee_text($ct)) . '</p>';
	}
	if ($k === 'template_custom') { $h .= cp_portal_tpl_logo_html($portal, $answers, $locked); }
	if ($k === 'guests_type_dress') {
		$gi = cp_portal_guests_info($portal, $answers);
		if ($gi['orig'] > 0) {
			$h .= '<p class="cpp-dur' . ($gi['diff'] > 0 ? ' diff' : '') . '" data-orig-guests="' . esc_attr($gi['orig']) . '">' . esc_html(cp_portal_guests_text($gi)) . '</p>';
		}
	}
	if (!empty($f['note'])) { $h .= '<p class="cpp-note">' . esc_html($f['note']) . '</p>'; }
	$h .= '<p class="cpp-ferr" role="alert" hidden></p></div>';
	return $h;
}

/* háttérválasztó: képes kártyák, csak az aznap szabad hátterek */
function cp_portal_bg_html($portal, $cur, $dis) {
	$o = cp_portal_bg_options($portal, $cur);
	$h = '<div class="cpp-part" data-part="" data-pkind="enum"><div class="cpp-bgs" role="radiogroup" aria-label="Háttér">';
	$h .= '<label class="cpp-bg none"><input type="radio" name="background" value=""' . ($cur === '' ? ' checked' : '') . $dis . '><span><i class="ph">?</i><b>Később döntök</b></span></label>';
	foreach ($o['list'] as $b) {
		$h .= '<label class="cpp-bg' . ($b['free'] ? '' : ' taken') . '"><input type="radio" name="background" value="' . esc_attr($b['name']) . '"' . ($b['cur'] ? ' checked' : '') . $dis . '><span>' .
			($b['img'] !== '' ? '<i style="background-image:url(\'' . esc_url($b['img']) . '\')"></i>' : '<i class="ph">' . esc_html(mb_substr($b['name'], 0, 1)) . '</i>') .
			'<b>' . esc_html($b['name']) . '</b>' . ($b['free'] ? '' : '<small>közben foglalt lett</small>') . '</span></label>';
	}
	$h .= '</div></div>';
	$h .= '<p class="cpp-bgwarn">Ha később döntesz: a lista mindig csak az aznap még szabad hátteret mutatja, a mások által lefoglalt hátterek automatikusan eltűnnek – így előfordulhat, hogy a most kiszemelt háttér addigra már nem lesz elérhető.</p>';
	if ($o['unknown']) { $h .= '<p class="cpp-note">A foglaltságot most nem tudtuk ellenőrizni – a választásodat egyeztetjük.</p>'; }
	return $h;
}

/* $label: látható címke (üres = nincs), $aria: a mező címe a képernyőolvasónak */
function cp_portal_input_html($name, $p, $v, $dis, $label, $aria) {
	$id = 'i-' . $name;
	$ph = isset($p['ph']) && $p['ph'] !== '' ? ' placeholder="' . esc_attr($p['ph']) . '"' : '';
	$lab = $label !== '' ? '<label class="cpp-pl" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>' : '';
	$al = $label === '' ? ' aria-label="' . esc_attr($aria) . '"' : '';
	$max = (int) (isset($p['max']) ? $p['max'] : 200);
	switch ($p['kind']) {
		case 'enum':
			$h = '<div class="cpp-opts" role="radiogroup" aria-label="' . esc_attr($label !== '' ? $label : $aria) . '">';
			foreach ($p['options'] as $ov => $ol) {
				$h .= '<label><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($ov) . '"' . ((string) $v === (string) $ov ? ' checked' : '') . $dis . '><span>' . esc_html($ol) . '</span></label>';
			}
			return ($label !== '' ? '<span class="cpp-pl">' . esc_html($label) . '</span>' : '') . $h . '</div>';
		case 'textarea':
			return $lab . '<textarea id="' . esc_attr($id) . '" rows="3"' . $al . ' maxlength="' . $max . '"' . $ph . $dis . '>' . esc_textarea((string) $v) . '</textarea>';
		case 'time':
			return $lab . '<input id="' . esc_attr($id) . '" type="time" step="300"' . $al . ' value="' . esc_attr((string) $v) . '"' . $dis . '>';
		case 'date':
			return $lab . '<input id="' . esc_attr($id) . '" type="date"' . $al . ' value="' . esc_attr((string) $v) . '"' . $dis . '>';
		case 'int':
			return $lab . '<input id="' . esc_attr($id) . '" type="text"' . $al . ' inputmode="numeric" pattern="[0-9]*" autocomplete="off" value="' . esc_attr((string) $v) . '"' . $ph . $dis . '>';
		case 'tel':
			return $lab . '<input id="' . esc_attr($id) . '" type="tel"' . $al . ' autocomplete="tel" maxlength="' . $max . '" value="' . esc_attr((string) $v) . '"' . $ph . $dis . '>';
		case 'email':
			return $lab . '<input id="' . esc_attr($id) . '" type="email"' . $al . ' autocomplete="email" maxlength="' . $max . '" value="' . esc_attr((string) $v) . '"' . $ph . $dis . '>';
		default:
			return $lab . '<input id="' . esc_attr($id) . '" type="text"' . $al . ' maxlength="' . $max . '" value="' . esc_attr((string) $v) . '"' . $ph . $dis . '>';
	}
}

/* a foglalt csomag óraszáma és az eltérés jelzése a menetrendnél (a JS élőben frissíti) */
function cp_portal_duration_text($du) {
	$t = 'A foglalásod szerint ' . cp_portal_hours_adj($du['pkg']) . ' csomagot kértél.';
	if ($du['diff']) { $t .= ' A megadott időtartam ' . cp_portal_hours_text($du['chosen']) . ' – ez eltér a csomagtól, a kollégáink egyeztetnek veled a különbözetről.'; }
	return $t;
}

function cp_portal_tpl_fee_text($type) {
	return 'Egyedi sablon: a grafikusunk a leírásod alapján elkészíti, és jóváhagyásra elküldi. Díja ' . cp_portal_custom_tpl_fee($type) . '.';
}

/* logó az egyedi sablonhoz: feltöltés a szerverre (PNG/JPG/WebP, újrakódolva – lásd portal-sablon.php) */
function cp_portal_tpl_logo_html($portal, $answers, $locked) {
	$lg = isset($answers['template_logo']) && is_array($answers['template_logo']) ? $answers['template_logo'] : null;
	$h = '<div class="cpp-logo" data-logo-up><span class="cpp-pl">Logó (ha van)</span><div class="cpp-logo-cur"' . ($lg ? '' : ' hidden') . '>';
	$h .= '<img alt="A feltöltött logó" src="' . ($lg ? esc_url(cp_portal_sb_file_url($lg['file'])) : '') . '"><span>' . ($lg ? esc_html($lg['name']) : '') . '</span>';
	if (!$locked) { $h .= '<button type="button" class="cpp-link" data-logo-del>Törlés</button>'; }
	$h .= '</div>';
	if (!$locked) {
		$h .= '<label class="cpp-btn ghost cpp-logo-btn"><span>' . ($lg ? 'Másik logó feltöltése' : 'Logó feltöltése') . '</span><input type="file" accept="image/png,image/jpeg,image/webp" hidden></label>';
		$h .= '<p class="cpp-note">PNG, JPG vagy WebP kép, legfeljebb 10 MB (átlátszó hátterű PNG a legjobb). Vektoros logót (AI, EPS, SVG, PDF) e-mailben küldj el.</p>';
	}
	return $h . '<p class="cpp-ferr" data-logo-err hidden></p></div>';
}

/* az ajánlatkérés létszáma és az eltérés jelzése (a JS élőben frissíti – a szövegek ott is szerepelnek) */
function cp_portal_guests_text($gi) {
	if ($gi['diff'] > 0) { return 'A megadott létszám ' . $gi['chosen'] . ' fő – ez több, mint az ajánlatkérésben szereplő ' . $gi['orig'] . ' fő. Magasabb létszám esetén felárat számolhatunk fel, és új ajánlatot küldünk; a kollégáink hamarosan jelentkeznek.'; }
	if ($gi['diff'] < 0) { return 'A megadott létszám ' . $gi['chosen'] . ' fő – ez eltér az ajánlatkérésben szereplő ' . $gi['orig'] . ' főtől. A kollégáink szükség esetén egyeztetnek veled.'; }
	return 'Az ajánlatkérésben ' . $gi['orig'] . ' fő szerepelt. Magasabb létszám esetén felárat számolhatunk fel, és új ajánlatot küldünk.';
}

/* sematikus helyigény-ábra felülnézetből: háttérszerkezet 213 cm, előtte a vendégek, a szelfigép legalább 1,5 m-re a háttértől */
function cp_portal_space_svg() {
	return '<div class="cpp-space"><svg viewBox="0 0 320 250" role="img" aria-label="Helyigény felülnézetből: 213 cm széles háttér, előtte a vendégek, a szelfigép legalább 1,5 méterre a háttértől">' .
		'<rect x="40" y="20" width="240" height="210" rx="10" fill="#F6F3EE" stroke="#C9C3B8" stroke-dasharray="5 4"/>' .
		'<rect x="54" y="34" width="212" height="14" rx="3" fill="#E8894A"/>' .
		'<text x="160" y="30" text-anchor="middle" font-size="11" font-family="Inter,sans-serif" fill="#5B6275">háttér · 213 cm</text>' .
		'<g fill="#9AA0AE">' .
		'<circle cx="110" cy="80" r="9"/><circle cx="140" cy="76" r="9"/><circle cx="170" cy="78" r="9"/><circle cx="200" cy="82" r="9"/>' .
		'<circle cx="125" cy="104" r="9"/><circle cx="185" cy="104" r="9"/></g>' .
		'<text x="160" y="132" text-anchor="middle" font-size="11" font-family="Inter,sans-serif" fill="#5B6275">vendégek</text>' .
		'<rect x="124" y="176" width="72" height="34" rx="6" fill="#141C33"/>' .
		'<text x="160" y="197" text-anchor="middle" font-size="11" font-weight="700" font-family="Inter,sans-serif" fill="#fff">szelfigép</text>' .
		'<line x1="292" y1="48" x2="292" y2="176" stroke="#5B6275" stroke-width="1.5"/>' .
		'<path d="M288 54 L292 48 L296 54 M288 170 L292 176 L296 170" fill="none" stroke="#5B6275" stroke-width="1.5"/>' .
		'<text x="300" y="116" font-size="11" font-weight="700" font-family="Inter,sans-serif" fill="#141C33" transform="rotate(90 300 116)" text-anchor="middle">min. 1,5 m</text>' .
		'</svg><p>Felülnézet: a háttérszerkezet 213 cm széles, előtte állnak a vendégek, a szelfigépnek legalább 1,5 m kell a háttértől. Összesen nagyjából 6 m² szabad, fedett terület kell.</p></div>';
}
