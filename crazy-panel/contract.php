<?php
/* =====================================================================
 * Szerződésszerkesztő (v1.76) – megbízási szerződés a deal adatlapjáról
 * – közös jogi alap + bepipálható szolgáltatásmodulok (a 16 Drive-sablonból kigyűjtve: contract-seed.json),
 *   vegyes megrendelésnél egy szerződésben, a végösszeget a panel számolja (a sablonok elírásai javítva),
 * – kiállító: Insignis Plus Kft. (nettó + ÁFA) vagy Gacsal Zsolt E.V. (AAM bruttó) a deal „Szerződés EV/KFT” mezője szerint,
 * – a megbízó adatai az ügyfélportálról (ha kitöltötte), különben a dealből / kontaktból – minden átírható,
 * – kimenet: Word (.docx, tovább szerkeszthető) és nyomtatható nézet (a böngészőben PDF-ként menthető).
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

function cp_ct_over() { $o = get_option('cp_ct_over', array()); return is_array($o) ? $o : array(); }
function cp_ct_seed() { static $S = null; if ($S === null) { $f = __DIR__ . '/contract-seed.php'; $S = is_readable($f) ? ((include $f) ?: array()) : array(); } return $S; }
function cp_ct_lib() {
	static $L = null;
	if ($L !== null) { return $L; }
	$f = __DIR__ . '/contract-seed.php';
	$L = is_readable($f) ? ((include $f) ?: array()) : array();
	/* v1.78: az admin javításai (Beállítások → Szerződésmodulok) az alap fölé kerülnek – amit nem írtak át, az az alap marad */
	foreach (cp_ct_over() as $part => $fields) {
		foreach ((array) $fields as $fk => $v) {
			if ($part === 'legal' || $part === 'legal_en') { if (isset($L[$part])) { $L[$part][$fk] = $v; } }
			elseif (strpos($part, 'svc:') === 0) { $k = substr($part, 4); if (isset($L['services'][$k])) { $L['services'][$k][$fk] = $v; } }
			elseif (strpos($part, 'svc_en:') === 0) { $k = substr($part, 7); if (isset($L['services'][$k])) { $L['services'][$k]['en'][$fk] = $v; } }
			elseif (strpos($part, 'iss:') === 0) { $k = substr($part, 4); if (isset($L['issuers'][$k])) { $L['issuers'][$k][$fk] = $v; } }
		}
	}
	return $L;
}

/* ------------------------------------------------------------ szám betűvel (magyar helyesírás: 2000 fölött kötőjellel tagolva) */
function cp_ct_words($n) {
	$n = (int) round($n);
	if ($n === 0) { return 'nulla'; }
	$one = array('', 'egy', 'kettő', 'három', 'négy', 'öt', 'hat', 'hét', 'nyolc', 'kilenc');
	$tenA = array('', 'tíz', 'húsz', 'harminc', 'negyven', 'ötven', 'hatvan', 'hetven', 'nyolcvan', 'kilencven');
	$tenB = array('', 'tizen', 'huszon', 'harminc', 'negyven', 'ötven', 'hatvan', 'hetven', 'nyolcvan', 'kilencven');
	$u999 = function ($x, $comp) use ($one, $tenA, $tenB) {
		$h = intdiv($x, 100); $t = intdiv($x % 100, 10); $o = $x % 10; $s = '';
		if ($h) { $s .= ($h === 1 ? '' : ($h === 2 ? 'két' : $one[$h])) . 'száz'; }
		if ($t) { $s .= $o ? $tenB[$t] : $tenA[$t]; }
		if ($o) { $s .= ($comp && $o === 2) ? 'két' : $one[$o]; }
		return $s;
	};
	$parts = array(); $groups = array(1000000000 => 'milliárd', 1000000 => 'millió', 1000 => 'ezer');
	$rest = $n;
	foreach ($groups as $g => $w) { if ($rest >= $g) { $q = intdiv($rest, $g); $rest %= $g; $parts[] = ($g === 1000 && $q === 1 && $n < 2000 ? '' : $u999($q, true)) . $w; } }
	if ($rest) { $parts[] = $u999($rest, false); }
	return implode($n > 2000 ? '-' : '', $parts);
}
/* névelő: „az” magánhangzó (és 1, 5 számjegy) előtt, különben „a” */
function cp_ct_art($w) { $f = mb_strtolower(mb_substr(ltrim((string) $w), 0, 1)); return preg_match('/^[aáeéiíoóöőuúüű15]$/u', $f) ? 'az' : 'a'; }
function cp_ct_dev($G, $key, $dev) { return str_replace('a(z) {DEV}', cp_ct_art($dev) . ' ' . $dev, $G[$key]); }
function cp_ct_fmt($n) { return number_format((float) $n, 0, ',', ' '); }
function cp_ct_money($n, $vat) { return $vat === 'aam' ? 'AAM bruttó ' . cp_ct_fmt($n) . ' Ft' : 'nettó ' . cp_ct_fmt($n) . ' Ft (+ÁFA)'; }
function cp_ct_toks($s, $vat) { return preg_replace_callback('/\{P:(\d+)\}/', function ($m) use ($vat) { return cp_ct_money((int) $m[1], $vat); }, (string) $s); }
function cp_ct_hudate($ymd) {
	$M = array('január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
	if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $ymd, $m)) { return (string) $ymd; }
	return $m[1] . '. ' . $M[(int) $m[2] - 1] . ' ' . (int) $m[3] . '.';
}

/* ------------------------------------------------------------ előtöltés a dealből és az ügyfélportálból */
function cp_ct_guess($d) {
	$s = mb_strtolower(implode(' ', array_map(function ($k) use ($d) { return (string) ($d[$k] ?? ''); }, array('service', 'pkg_v2', 'pkg_fix', 'package', 'services_ec', 'services_cp', 'services_more', 'title'))));
	$out = array();
	$has = function ($re) use ($s) { return (bool) preg_match($re, $s); };
	if ($has('/selfie\s*\+\s*360|szelfi.*360|360.*szelfi/u')) { $out[] = 'selfie_360'; }
	elseif ($has('/szelfi|selfie|fotóautomat|photo ?box/u')) { $out[] = 'fotoautomata'; }
	if (!in_array('selfie_360', $out, true)) {
		if ($has('/overhead/u')) { $out[] = 'overhead_spinner'; } elseif ($has('/vip spinner|vip-spinner/u')) { $out[] = 'vip_spinner'; } elseif ($has('/360|spinner/u')) { $out[] = 'spinner360'; }
	}
	if ($has('/cockpit/u')) { $out[] = 'cockpit_vr'; } elseif ($has('/\bvr\b|virtuális/u')) { $out[] = 'vr'; }
	if ($has('/led/u')) { $out[] = 'led_tancter'; }
	if ($has('/digitális karikat|ai karikat|digitalis karikat/u')) { $out[] = 'digitalis_karikatura'; } elseif ($has('/karikat/u')) { $out[] = 'klasszikus_karikatura'; }
	if ($has('/karaoke/u')) { $out[] = 'karaoke'; }
	if ($has('/melange|élőzene|elozene/u')) { $out[] = 'elozene'; }
	if ($has('/catch/u')) { $out[] = 'crazycatch'; }
	if ($has('/rendezvényfot|rendezvenyfot/u')) { $out[] = 'rendezvenyfoto'; }
	if ($has('/kaszin|casino/u')) { $out[] = 'kaszino'; }
	if ($has('/smink|makeup|fodrász/u')) { $out[] = 'crazymakeup'; }
	return array_values(array_unique($out));
}
function cp_ct_prefill($id) {
	global $wpdb;
	$d = $wpdb->get_row($wpdb->prepare('SELECT d.*, c.name cn, c.email ce, c.phone cp, c.mobile cm, c.city ccity, co.name con FROM ' . cp_crm_t('deals') . ' d LEFT JOIN ' . cp_crm_t('contacts') . ' c ON c.id = d.contact_id LEFT JOIN ' . cp_crm_t('companies') . ' co ON co.id = d.company_id WHERE d.id = %d', $id), ARRAY_A);
	if (!$d) { return null; }
	$L = cp_ct_lib();
	$a = null; $src = 'deal';
	if (function_exists('cp_portal_get_by_deal') && function_exists('cp_portal_answers')) { $po = cp_portal_get_by_deal((int) $id); if ($po) { $a = cp_portal_answers($po->id); } }
	$g = function ($k, $s = null) use ($a) { if (!is_array($a) || !isset($a[$k])) { return ''; } $v = $a[$k]; if ($s !== null) { return is_array($v) && isset($v[$s]) && is_scalar($v[$s]) ? trim((string) $v[$s]) : ''; } return is_scalar($v) ? trim((string) $v) : ''; };
	$type = $g('client_type');
	$cl = array('type' => $d['con'] ? 'ceg' : 'magan', 'company' => (string) $d['con'], 'seat' => '', 'reg' => '', 'tax' => '', 'signer' => '', 'name' => (string) $d['cn'], 'address' => '');
	$ct = array('name' => (string) $d['cn'], 'tel' => (string) ($d['cm'] ?: $d['cp']));
	if ($type === 'ceg' && $g('client_company', 'company') !== '') {
		$src = 'portal';
		$cl = array('type' => 'ceg', 'company' => $g('client_company', 'company'), 'seat' => $g('client_company', 'seat'), 'reg' => $g('client_company', 'reg'), 'tax' => $g('client_company', 'tax'),
			'signer' => trim($g('client_company', 'signer_name') . ($g('client_company', 'signer_title') !== '' ? ', ' . $g('client_company', 'signer_title') : '')), 'name' => '', 'address' => '');
		$ct = array('name' => $g('client_company', 'contact_name'), 'tel' => $g('client_company', 'contact_phone'));
	} elseif ($type === 'magan' && $g('client_private', 'name') !== '') {
		$src = 'portal';
		$cl = array('type' => 'magan', 'name' => $g('client_private', 'name'), 'address' => $g('client_private', 'address'), 'company' => '', 'seat' => '', 'reg' => '', 'tax' => '', 'signer' => '');
		$ct = array('name' => $g('client_private', 'name'), 'tel' => $g('client_private', 'phone'));
	}
	$venue = trim($g('venue_address', 'name') . ($g('venue_address', 'address') !== '' ? ', ' . $g('venue_address', 'address') : ''), ', ');
	$start = $g('schedule', 'start'); $end = $g('schedule', 'end');
	if ($start === '' && !empty($d['start_at'])) { $t = new DateTime($d['start_at'] . ' UTC'); $t->setTimezone(wp_timezone()); if ($t->format('H:i') !== '00:00') { $start = $t->format('H:i'); } }
	$hours = (int) $d['hours'];
	$keys = cp_ct_guess($d);
	$svc = array();
	foreach ($keys as $i => $k) { if (!isset($L['services'][$k])) { continue; } $S = $L['services'][$k]; $svc[] = array('k' => $k, 'hours' => $hours > 0 && $hours <= 16 ? $hours : (int) $S['hours'], 'price' => $i === 0 && (float) $d['value'] > 0 ? (float) $d['value'] - (float) $d['km_fee'] : 0, 'bg' => $S['hatter'] ? (string) ($g('background') ?: $d['background']) : ''); }
	$pm = mb_strtolower((string) $d['pay_method']);
	$pay = strpos($pm, 'kész') !== false ? 'cash' : (strpos($pm, 'előre') !== false || strpos($pm, 'elore') !== false ? 'pre' : 'post');
	$city = ''; if (preg_match('/\b\d{4}\s+([^\s,]+)/u', $venue ?: (string) $d['venue'], $m)) { $city = $m[1]; }
	return array('deal' => (int) $id, 'src' => $src, 'issuer' => strtoupper((string) $d['ctype']) === 'EV' ? 'EV' : 'KFT', 'lang' => 'hu', 'client' => $cl, 'contact' => $ct,
		'venue' => $venue ?: (string) $d['venue'], 'date' => (string) $d['event_d'], 'start' => $start, 'end' => $end, 'svc' => $svc,
		'travel_city' => $city ?: ($d['ccity'] ?: 'Budapest'), 'travel' => (float) $d['km_fee'], 'pay' => $pay, 'cdate' => wp_date('Y-m-d'), 'place' => 'Budapest', 'note' => '');
}

/* ------------------------------------------------------------ a szerződés felépítése (blokkok: h, p, b, t, c, sig) */
/* v1.77: kétnyelvű (magyar / angol) szövegek – a jogi alap és a modulok angolul a seed „legal_en” és „services.*.en” részéből */
function cp_ct_t($lang) {
	if ($lang === 'en') {
		return array('title' => 'Service Agreement', 'between' => 'Entered into by and between:', 'cl_rows' => array('company' => 'Company name:', 'seat' => 'Registered office:', 'reg' => 'Company registration number:', 'tax' => 'Tax number:', 'name' => 'Name:', 'address' => 'Address:'),
			'client_is' => 'hereinafter referred to as the “Client”, on the one hand, and:', 'prov_is' => 'hereinafter referred to as the “Service Provider”, on the other hand, jointly referred to as the “Parties”, under the following terms and conditions:',
			'H' => array('Subject of the Agreement', 'Duties and Obligations of the Service Provider', 'Duties and Obligations of the Client', 'Fees for the Services Provided by the Service Provider', 'Additional Services', 'Communication and Contacts', 'Certification of Performance and Handling of Complaints', 'Cancellation and Withdrawal', 'Vis maior', 'Jurisdiction and Governing Law', 'Miscellaneous Provisions'),
			'svc' => 'Service: ', 'venue' => 'Venue: ', 'dt' => 'Date and time: ', 'one' => 'the following service under', 'many' => 'the following services under', 'bg' => 'Provision of the background selected by the Client, which in this case is: ', 'corp' => array('professional and corporate reputation', 'reputation'),
			'fee1' => 'For the performance of the service specified in Section 1, the Service Provider shall be entitled to a service fee of %s, i.e. %s, which the Client shall settle as follows:',
			'feeN' => 'For the performance of the services specified in Section 1, the Service Provider shall be entitled to the following service fees:', 'feeT' => 'The total service fee is %s, i.e. %s, which the Client shall settle as follows:', 'svcH' => array('Service', 'Fee'), 'tot' => 'Total',
			'pay' => array('pre' => 'by bank transfer in advance', 'post' => 'by deferred payment, with an 8-day payment deadline', 'cash' => 'in cash on site'), 'trI' => 'The delivery fee is as follows:', 'trH' => array('Location', 'Fee'),
			'total' => 'The Client acknowledges that the total consideration for the service under this Agreement %samounts to a total of %s. ', 'total_tr' => 'consists of the service fee and the delivery fee combined, which ',
			'ctC' => 'Contact person on behalf of the Client:', 'ctP' => 'Contact person on behalf of the Service Provider:', 'nm' => 'Name: ', 'tel' => 'Tel.: ', 'photo' => 'In the case of event photography: ',
			'net40' => array('of the net service fee', 'of the service fee'), 'media' => array('k' => array('photographs', 'photographs'), 'v' => array('videos', 'videos'), 'kv' => array('photographs and videos', 'photographs and videos')),
			'signC' => 'Client', 'signP' => 'Service Provider', 'rep' => 'represented by: ', 'pick' => 'Please select at least one service.');
	}
	return array('title' => 'Megbízási Szerződés', 'between' => 'Amely létrejött egyrészről:', 'cl_rows' => array('company' => 'Cégnév:', 'seat' => 'Székhely:', 'reg' => 'Cégjegyzékszám:', 'tax' => 'Adószám:', 'name' => 'Név:', 'address' => 'Lakcím:'),
		'client_is' => 'továbbiakban Megbízó, másrészről:', 'prov_is' => 'továbbiakban Megbízott között (együttesen Felek), az alábbi feltételekkel:',
		'H' => array('A megbízás tárgya', 'A Megbízott feladatai és kötelezettségei', 'A Megbízó feladatai és kötelezettségei', 'A Megbízott által nyújtott szolgáltatások díjazása', 'Kiegészítő szolgáltatások', 'Kapcsolattartás', 'Teljesítés igazolása és kifogáskezelés', 'Lemondás és elállás', 'Vis maior', 'Joghatóság és irányadó jog', 'Egyéb rendelkezések'),
		'svc' => 'Szolgáltatás: ', 'venue' => 'Helyszín: ', 'dt' => 'Dátum és időpont: ', 'one' => 'az alábbi szolgáltatást biztosítja', 'many' => 'az alábbi szolgáltatásokat biztosítja', 'bg' => 'A Megbízó által választott háttér biztosítása, amely jelen esetben: ', 'corp' => array('szakmai és céges jó hírét', 'jó hírét'),
		'fee1' => 'Az 1. pontban rögzített szolgáltatás elvégzéséért a Megbízottat megbízási díj illeti meg, amely %s, azaz %s, melyet a Megbízó az alábbi módon rendez:',
		'feeN' => 'Az 1. pontban rögzített szolgáltatások elvégzéséért a Megbízottat az alábbi megbízási díj illeti meg:', 'feeT' => 'A megbízási díj összesen %s, azaz %s, melyet a Megbízó az alábbi módon rendez:', 'svcH' => array('Szolgáltatás', 'Díj'), 'tot' => 'Összesen',
		'pay' => array('pre' => 'előre utalással', 'post' => 'halasztott fizetéssel, 8 napos fizetési határidővel', 'cash' => 'helyszínen készpénzben'), 'trI' => 'A kiszállási díj az alábbiak szerint alakul:', 'trH' => array('Település', 'Díj'),
		'total' => 'A Megbízó tudomásul veszi, hogy a jelen szerződés szerinti szolgáltatás teljes ellenértéke %sösszesen %s. ', 'total_tr' => 'a megbízási díj és a kiszállási díj együttes összege, amely ',
		'ctC' => 'Kapcsolattartó a megbízó részéről:', 'ctP' => 'Kapcsolattartó a megbízott részéről:', 'nm' => 'Név: ', 'tel' => 'Tel.: ', 'photo' => 'Rendezvényfotózás esetén: ',
		'net40' => array('megbízási díj nettó ', 'megbízási díj '), 'media' => array('k' => array('képeket', 'képek'), 'v' => array('videókat', 'videók'), 'kv' => array('képeket és videókat', 'képek és videók')),
		'signC' => 'Megbízó', 'signP' => 'Megbízott', 'rep' => 'képviseli: ', 'pick' => 'Válassz legalább egy szolgáltatást.');
}
function cp_ct_words_en($n) {
	$n = (int) round($n); if ($n === 0) { return 'zero'; }
	$o = array('', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen');
	$t = array('', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety');
	$u = function ($x) use ($o, $t) { $s = array(); if ($x >= 100) { $s[] = $o[intdiv($x, 100)] . ' hundred' . ($x % 100 ? ' and' : ''); $x %= 100; } if ($x) { $s[] = $x < 20 ? $o[$x] : $t[intdiv($x, 10)] . ($x % 10 ? '-' . $o[$x % 10] : ''); } return implode(' ', $s); };
	$p = array();
	foreach (array(1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand') as $g => $w) { if ($n >= $g) { $p[] = $u(intdiv($n, $g)) . ' ' . $w; $n %= $g; } }
	if ($n) { $p[] = $u($n); }
	return implode(' ', $p);
}
function cp_ct_money_l($n, $vat, $lang) {
	if ($lang !== 'en') { return cp_ct_money($n, $vat); }
	return 'HUF ' . number_format((float) $n, 0, '.', ',') . ($vat === 'aam' ? ' (VAT-exempt, AAM)' : ' net (+ VAT)');
}
function cp_ct_date_l($ymd, $lang) {
	if ($lang !== 'en') { return cp_ct_hudate($ymd); }
	return preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $ymd) ? wp_date('j F Y', strtotime($ymd . ' 12:00:00')) . '.' : (string) $ymd;
}
function cp_ct_issuer_rows($I, $lang) {
	if ($lang !== 'en') { return $I['rows']; }
	$map = array('Név:' => $I['vat'] === 'aam' ? 'Name:' : 'Company name:', 'Székhely:' => 'Registered office:', 'Levelezési cím:' => 'Postal address:', 'Adószám:' => 'Tax number:', 'Cégjegyzékszám:' => 'Company registration number:', 'Bankszámlaszám:' => 'Bank account number:');
	return array_map(function ($r) use ($map) { return array($map[$r[0]] ?? $r[0], str_replace(' - Gyarmati Andrea részére', ' – attn. Andrea Gyarmati', $r[1])); }, $I['rows']);
}

function cp_ct_build($st) {
	$L = cp_ct_lib(); $lang = ($st['lang'] ?? 'hu') === 'en' && !empty($L['legal_en']) ? 'en' : 'hu';
	$G = $lang === 'en' ? $L['legal_en'] : $L['legal']; $T = cp_ct_t($lang);
	$I = $L['issuers'][$st['issuer'] === 'EV' ? 'EV' : 'KFT']; $vat = $I['vat'];
	$money = function ($n) use ($vat, $lang) { return cp_ct_money_l($n, $vat, $lang); };
	$out = array(); $n = 0;
	$H = function ($i) use (&$out, &$n, $T) { $n++; $out[] = array('h', $n . '. ' . $T['H'][$i]); };
	$p = function ($t) use (&$out) { if (trim((string) $t) !== '') { $out[] = array('p', $t); } };
	$b = function ($t) use (&$out) { $out[] = array('b', $t); };
	$sel = array();
	foreach ((array) $st['svc'] as $s) {
		if (!isset($L['services'][$s['k']])) { continue; }
		$S = $L['services'][$s['k']];
		if ($lang === 'en' && !empty($S['en'])) { $S = array_merge($S, $S['en']); }
		$sel[] = array_merge($S, $s);
	}
	if (!$sel) { return array(array('p', $T['pick'])); }
	$multi = count($sel) > 1;
	$and = $lang === 'en' ? ' and ' : ' és ';
	$join = function ($a) use ($and) { return count($a) > 1 ? implode(', ', array_slice($a, 0, -1)) . $and . end($a) : (string) ($a[0] ?? ''); };
	$dev = function ($key, $list) use ($G, $lang, $join) { $d = $join($list); return $lang === 'en' ? str_replace('{DEV}', $d, $G[$key]) : cp_ct_dev($G, $key, $d); };
	$c = $st['client']; $R = $T['cl_rows'];
	$out[] = array('logo', $st['issuer'] === 'EV' ? 'cp' : 'ec');
	$out[] = array('title', $T['title']);
	$p($T['between']);
	$out[] = array('t', $c['type'] === 'ceg' ? array(array($R['company'], $c['company']), array($R['seat'], $c['seat']), array($R['reg'], $c['reg']), array($R['tax'], $c['tax'])) : array(array($R['name'], $c['name']), array($R['address'], $c['address'])), 'party');
	$p($T['client_is']);
	$out[] = array('t', cp_ct_issuer_rows($I, $lang), 'party');
	$p($T['prov_is']);
	$H(0);
	$p($multi ? str_replace($T['one'], $T['many'], $G['targy_intro']) : $G['targy_intro']);
	$lb = function ($l, $v) use (&$out) { $out[] = array('b', $l . $v, mb_strlen($l)); };
	$lb($T['svc'], implode(', ', array_map(function ($s) { return $s['line']; }, $sel)));
	$lb($T['venue'], $st['venue']);
	$lb($T['dt'], trim(cp_ct_date_l($st['date'], $lang) . ($st['start'] !== '' ? ' ' . $st['start'] . ($st['end'] !== '' ? '–' . $st['end'] : '') : '')));
	$H(1);
	$p($G['feladat_intro']);
	foreach ($sel as $s) {
		if ($multi) { $out[] = array('sub', $s['name']); }
		foreach ($s['tasks'] as $t) { $b(str_replace('{H}', (string) (int) $s['hours'], $t)); }
		if ($s['hatter']) { $b($T['bg'] . (trim((string) $s['bg']) !== '' ? $s['bg'] : '............................................')); }
	}
	foreach ($G['megbizott_common'] as $t) { $p($c['type'] === 'magan' ? str_replace($T['corp'][0], $T['corp'][1], $t) : $t); }
	$H(2);
	$p($G['megbizo_intro']); $p($G['megbizo_intro2']);
	$seen = array();
	foreach ($sel as $s) { foreach ($s['reqs'] as $r) { $k = mb_strtolower(trim($r)); if (isset($seen[$k])) { continue; } $seen[$k] = 1; $b($r . ($multi && !preg_match('/tájékoztatni|information to the Service Provider|Prior information/u', $r) ? ' (' . $s['name'] . ')' : '')); } }
	foreach ($sel as $s) { foreach ($s['reqs_extra'] as $r) { $p($r); } }
	if (array_filter($sel, function ($s) { return $s['transport']; })) { $p($G['transport']); }
	$p($G['parking'] . ' ' . $dev('damage', array_values(array_unique(array_map(function ($s) { return $s['dev']; }, $sel)))));
	$H(3);
	$sum = 0; foreach ($sel as $s) { $sum += (float) $s['price']; }
	$words = $lang === 'en' ? cp_ct_words_en($sum) . ' Hungarian forints' . ($vat === 'aam' ? '' : ' plus VAT') : cp_ct_words($sum) . ' forint' . ($vat === 'aam' ? '' : ' + ÁFA');
	if ($multi) {
		$p($T['feeN']);
		$out[] = array('t', array_merge(array($T['svcH']), array_map(function ($s) use ($money) { return array($s['name'], $money($s['price'])); }, $sel), array(array($T['tot'], $money($sum)))), 'gridh');
		$p(sprintf($T['feeT'], $money($sum), $words));
	} else {
		$p(sprintf($T['fee1'], $money($sum), $words));
	}
	$cash = !array_filter($sel, function ($s) { return !$s['cash']; });
	foreach ($T['pay'] as $k => $t) { if ($k === 'cash' && !$cash) { continue; } $out[] = array('chk', ($st['pay'] === $k ? '☒ ' : '☐ ') . $t); }
	$tr = (float) $st['travel'];
	if ($tr > 0) { $p($T['trI']); $out[] = array('t', array($T['trH'], array($st['travel_city'], $money($tr))), 'gridh'); }
	$p($G['late_interest']);
	$tot = $vat === 'aam' ? $money($sum + $tr) : ($lang === 'en' ? 'HUF ' . number_format($sum + $tr, 0, '.', ',') . ' net + VAT' : 'nettó ' . cp_ct_fmt($sum + $tr) . ' Ft + ÁFA');
	$p(sprintf($T['total'], $tr > 0 ? $T['total_tr'] : '', $tot) . $G['acknowledge']);
	$p($G['unforeseen']); $p($G['invoice_mod']);
	$ex = array(); foreach ($sel as $s) { if ($s['extras']) { $ex[] = $s; } }
	if ($ex) {
		$H(4);
		foreach ($ex as $s) { if ($multi) { $out[] = array('sub', $s['name']); } foreach ($s['extras'] as $t) { $p(preg_replace_callback('/\{P:(\d+)\}/', function ($m) use ($money) { return $money((int) $m[1]); }, $t)); } }
	}
	$H(5);
	$p($G['contact_intro']);
	$p($T['ctC']);
	$out[] = array('t', array(array($T['nm'] . $st['contact']['name'], $T['tel'] . $st['contact']['tel'])), 'grid');
	$p($T['ctP']);
	$ic = $lang === 'en' ? array('Ákos Sebestyén', '+36 30 474 9447') : $I['contact'];
	$out[] = array('t', array(array($T['nm'] . $ic[0], $T['tel'] . $ic[1])), 'grid');
	$H(6);
	$std = array_filter($sel, function ($s) { return $s['teljesites'] === ''; });
	if ($std) { $p($dev('teljesites', array_values(array_unique(array_map(function ($s) { return $s['tdev']; }, $std))))); }
	foreach ($sel as $s) { if ($s['teljesites'] !== '') { $p(($multi ? $T['photo'] : '') . $s['teljesites']); } }
	$H(7); foreach ($G['lemondas'] as $t) { $p($vat === 'aam' ? str_replace($T['net40'][0], $T['net40'][1], $t) : $t); }
	$H(8); foreach ($G['vis'] as $t) { $p($t); }
	$H(9); foreach ($G['jog'] as $t) { $p($t); }
	$H(10);
	$p($G['egyeb_ptk']); $p($G['gdpr']);
	$m = array(); foreach ($sel as $s) { if ($s['media'] === 'kep' || $s['media'] === 'kepvideo') { $m['k'] = 1; } if ($s['media'] === 'video' || $s['media'] === 'kepvideo') { $m['v'] = 1; } }
	if ($m) { $w = $T['media'][isset($m['k'], $m['v']) ? 'kv' : (isset($m['k']) ? 'k' : 'v')]; $p(str_replace(array('{MEDIA}', '{MEDIA2}'), $w, $G['storage'])); }
	foreach ($G['egyeb_post'] as $t) { $p($t); }
	foreach ($sel as $s) { foreach ($s['extra_egyeb'] as $t) { $p($t); } }
	if (trim((string) $st['note']) !== '') { $p($st['note']); }
	$out[] = array('date', $st['place'] . ', ' . cp_ct_date_l($st['cdate'], $lang));
	$sign = $lang === 'en' ? ($vat === 'aam' ? array('Zsolt Gacsal / Crazyphoto') : array('INSIGNIS PLUS Kft.', 'represented by: Balázs Tóth, Managing Director')) : $I['sign'];
	$left = $c['type'] === 'ceg' ? array($c['company'] !== '' ? $c['company'] : '………………………………', $T['rep'] . ($c['signer'] !== '' ? $c['signer'] : '………………………'), $T['signC']) : array($c['name'] !== '' ? $c['name'] : '………………………………', $T['signC']);
	$out[] = array('sig', $left, array_merge($sign, array($T['signP'])));
	return $out;
}


/* ------------------------------------------------------------ kimenetek: HTML (előnézet / nyomtatás) és Word
   A forma a korábbi Word-sablonokat követi: logó a fejlécben, kiskapitális cím, keretes táblázatok,
   „1.” fejezetcímek és „2.1” alpontok függő behúzással, ● felsorolás, piros jelölőnégyzetek, oldalszám. */
function cp_ct_num($blocks) {
	$sec = 0; $m = 0; $o = array();
	foreach ($blocks as $b) {
		$b['n'] = ''; $b['in'] = $sec > 0;
		if ($b[0] === 'h') { $sec++; $m = 0; $b['in'] = false; if (preg_match('/^(\d+\.)\s+(.*)$/su', (string) $b[1], $x)) { $b['n'] = $x[1]; $b[1] = $x[2]; } }
		elseif ($b[0] === 'p' && $sec > 1) { $m++; $b['n'] = $sec . '.' . $m; }
		elseif ($b[0] === 'date' || $b[0] === 'sig') { $b['in'] = false; }
		$o[] = $b;
	}
	return $o;
}
function cp_ct_logo($k) { static $L = null; if ($L === null) { $f = __DIR__ . '/contract-logos.php'; $L = is_readable($f) ? ((include $f) ?: array()) : array(); } return isset($L[$k]) ? (string) base64_decode($L[$k]) : ''; }
function cp_ct_html($blocks, $print = false) {
	$e = function ($s) { return esc_html((string) $s); };
	$h = '';
	foreach (cp_ct_num($blocks) as $x) {
		$in = $x['in'] ? ' in' : '';
		switch ($x[0]) {
			case 'logo': $l = cp_ct_logo($x[1]); if ($l !== '') { $h .= '<div class="lg"><img alt="" src="data:image/png;base64,' . base64_encode($l) . '"></div>'; } break;
			case 'title': $h .= '<h1>' . $e($x[1]) . '</h1>'; break;
			case 'h': $h .= '<h2><b class="no">' . $e($x['n']) . '</b>' . $e($x[1]) . '</h2>'; break;
			case 'sub': $h .= '<h3 class="in">' . $e($x[1]) . '</h3>'; break;
			case 'p': $h .= $x['n'] !== '' ? '<p class="nm"><b class="no">' . $e($x['n']) . '</b>' . nl2br($e($x[1])) . '</p>' : '<p class="' . trim($in) . '">' . nl2br($e($x[1])) . '</p>'; break;
			case 'b': $t = (string) $x[1]; $k = (int) ($x[2] ?? 0); $h .= '<p class="li' . $in . '">' . ($k ? '<b>' . $e(mb_substr($t, 0, $k)) . '</b>' . $e(mb_substr($t, $k)) : $e($t)) . '</p>'; break;
			case 'chk': $t = (string) $x[1]; $h .= '<p class="chk' . $in . '"><b class="bx">' . $e(mb_substr($t, 0, 1)) . '</b> <b>' . $e(ltrim(mb_substr($t, 1))) . '</b></p>'; break;
			case 'date': $h .= '<p class="dt">' . $e($x[1]) . '</p>'; break;
			case 't': $h .= '<table class="' . ($x[2] === 'party' ? 'party' : 'grid') . ($x[2] === 'gridh' ? ' hd' : '') . $in . '">' . implode('', array_map(function ($r) use ($e) { return '<tr>' . implode('', array_map(function ($c) use ($e) { return '<td>' . $e($c) . '</td>'; }, $r)) . '</tr>'; }, $x[1])) . '</table>'; break;
			case 'sig':
				$c = function ($l) use ($e) { $n = count($l); $o = ''; foreach ($l as $i => $s) { $o .= '<br>' . ($i === 0 || $i === $n - 1 ? '<b>' . $e($s) . '</b>' : $e($s)); } return '……………………………………' . $o; };
				$h .= '<table class="sig"><tr><td>' . $c($x[1]) . '</td><td>' . $c($x[2]) . '</td></tr></table>'; break;
		}
	}
	$R = array('.lg' => 'text-align:center;margin:0 0 14px', '.lg img' => 'width:122px;height:auto', 'h1' => 'text-align:center;font-size:14pt;font-variant:small-caps;margin:0 0 16px', 'h2' => 'font-size:11pt;margin:16px 0 6px;padding-left:46px;text-indent:-46px', 'b.no' => 'display:inline-block;width:46px;text-indent:0', 'h3' => 'font-size:11pt;margin:8px 0 4px;text-decoration:underline',
		'p' => 'margin:0 0 6px;text-align:justify', 'p.nm' => 'padding-left:46px;text-indent:-46px', '.in' => 'margin-left:46px', 'p.li' => 'padding-left:22px;text-indent:-22px;margin-bottom:3px', 'p.li::before' => 'content:"●";display:inline-block;width:22px;text-indent:0;font-size:8pt;vertical-align:1px', 'p.li.in' => 'margin-left:66px', 'p.chk' => 'margin-bottom:2px', 'b.bx' => 'color:#e00', 'p.dt' => 'margin-top:28px;break-after:avoid',
		'table' => 'border-collapse:collapse;margin:4px 0 10px;width:100%', 'table.in' => 'width:calc(100% - 46px)', 'table td' => 'border:1px solid #000;padding:2px 6px;vertical-align:top', 'table.party td:first-child' => 'width:32%', 'table.grid td' => 'width:50%', 'table.hd tr:first-child td' => 'font-weight:700', 'table.sig' => 'width:100%;margin-top:34px;break-inside:avoid', 'table.sig td' => 'border:0;width:50%;text-align:center;vertical-align:top');
	$scope = $print ? '' : '.ct-doc ';
	$css = '.ct-doc{font:11pt/1.42 Calibri,Carlito,Arial,sans-serif;color:#000;max-width:800px;margin:0 auto;padding:26px 30px;background:#fff}';
	foreach ($R as $sel => $dec) { $css .= $scope . $sel . '{' . $dec . '}'; }
	/* az előnézet a panel oldalán jelenik meg: a panel stílusai ne hassanak rá */
	if (!$print) { $css .= '.ct-doc h1,.ct-doc h2,.ct-doc h3,.ct-doc p,.ct-doc td{font-family:Calibri,Carlito,Arial,sans-serif;color:#000;line-height:1.42;letter-spacing:0;font-weight:400}.ct-doc p,.ct-doc td,.ct-doc h2,.ct-doc h3{font-size:11pt}.ct-doc h1,.ct-doc h2,.ct-doc h3,.ct-doc b{font-weight:700}.ct-doc h1{font-size:14pt}.ct-doc table.hd tr:first-child td{font-weight:700}.ct-doc b.bx{color:#e00}'; }
	if (!$print) { return '<style>' . $css . '</style><div class="ct-doc">' . $h . '</div>'; }
	$css .= 'body{margin:0}@media print{.ct-doc{padding:0;max-width:none}.noprint{display:none}}';
	return '<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Megbízási szerződés</title><style>' . $css . '@page{size:A4;margin:16mm 15mm 18mm}</style></head><body><div class="noprint" style="padding:10px;text-align:center;background:#FFF6E5;font:14px Arial">Mentés PDF-ként: a nyomtatási ablakban válaszd a „Mentés PDF-ként” célt. <button onclick="window.print()">Nyomtatás / PDF</button></div><div class="ct-doc">' . $h . '</div><script>setTimeout(function(){window.print()},400)</script></body></html>';
}

function cp_ct_docx($blocks) {
	if (!class_exists('ZipArchive')) { return new WP_Error('cp_ct', 'A szerveren nincs ZipArchive – Word-fájl nem készíthető.'); }
	$x = function ($s) { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
	/* $o: b (félkövér), u (aláhúzás), sz (méret, fél pont), caps (kiskapitális), red (piros) */
	$run = function ($t, $o = array()) use ($x) {
		$pr = (!empty($o['b']) ? '<w:b/><w:bCs/>' : '') . (!empty($o['caps']) ? '<w:smallCaps/>' : '') . (!empty($o['red']) ? '<w:color w:val="FF0000"/>' : '') . (!empty($o['u']) ? '<w:u w:val="single"/>' : '') . (!empty($o['sz']) ? '<w:sz w:val="' . $o['sz'] . '"/><w:szCs w:val="' . $o['sz'] . '"/>' : '');
		$out = '';
		foreach (explode("\n", (string) $t) as $i => $pt) { $out .= '<w:r>' . ($pr ? '<w:rPr>' . $pr . '</w:rPr>' : '') . ($i ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . $x($pt) . '</w:t></w:r>'; }
		return $out;
	};
	$tab = '<w:r><w:tab/></w:r>';
	$par = function ($runs, $ppr = '') { return '<w:p>' . ($ppr ? '<w:pPr>' . $ppr . '</w:pPr>' : '') . $runs . '</w:p>'; };
	$IN = 700; $TW = 10206; /* behúzás és szövegszélesség (A4, 1,5 cm-es oldalmargó) */
	$bd = '<w:tblBorders><w:top w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:left w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:bottom w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:right w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:insideH w:val="single" w:sz="4" w:space="0" w:color="000000"/><w:insideV w:val="single" w:sz="4" w:space="0" w:color="000000"/></w:tblBorders>';
	$tbl = function ($rows, $kind, $in) use ($run, $par, $bd, $IN, $TW) {
		$w = $TW - ($in ? $IN : 0); $cw = $kind === 'party' ? array(3200, $w - 3200) : array(intdiv($w, 2), $w - intdiv($w, 2));
		$o = '<w:tbl><w:tblPr><w:tblW w:w="' . $w . '" w:type="dxa"/>' . ($in ? '<w:tblInd w:w="' . $IN . '" w:type="dxa"/>' : '') . $bd . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="20" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="20" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="' . $cw[0] . '"/><w:gridCol w:w="' . $cw[1] . '"/></w:tblGrid>';
		foreach ($rows as $ri => $r) { $o .= '<w:tr>'; foreach (array_values($r) as $ci => $c) { $o .= '<w:tc><w:tcPr><w:tcW w:w="' . $cw[min($ci, 1)] . '" w:type="dxa"/></w:tcPr>' . $par($run($c, array('b' => $kind === 'gridh' && $ri === 0)), '<w:spacing w:before="0" w:after="0" w:line="252" w:lineRule="auto"/><w:jc w:val="left"/>') . '</w:tc>'; } $o .= '</w:tr>'; }
		return $o . '</w:tbl>' . $par('', '<w:spacing w:before="0" w:after="0" w:line="120" w:lineRule="exact"/>');
	};
	$body = ''; $logo = '';
	foreach (cp_ct_num($blocks) as $b) {
		$ind = $b['in'] ? '<w:ind w:left="' . $IN . '"/>' : '';
		switch ($b[0]) {
			case 'logo': $logo = cp_ct_logo($b[1]); $lk = $b[1]; break;
			case 'title': $body .= $par($run($b[1], array('b' => 1, 'caps' => 1, 'sz' => 28)), '<w:jc w:val="center"/><w:spacing w:before="120" w:after="240"/>'); break;
			case 'h': $body .= $par($run($b['n'], array('b' => 1)) . $tab . $run($b[1], array('b' => 1)), '<w:keepNext/><w:tabs><w:tab w:val="left" w:pos="' . $IN . '"/></w:tabs><w:spacing w:before="240" w:after="60"/><w:ind w:left="' . $IN . '" w:hanging="' . $IN . '"/><w:jc w:val="left"/>'); break;
			case 'sub': $body .= $par($run($b[1], array('b' => 1, 'u' => 1)), '<w:keepNext/><w:spacing w:before="120" w:after="40"/>' . $ind); break;
			case 'p':
				if ($b['n'] !== '') { $body .= $par($run($b['n'], array('b' => 1)) . $tab . $run($b[1]), '<w:tabs><w:tab w:val="left" w:pos="' . $IN . '"/></w:tabs><w:spacing w:before="120"/><w:ind w:left="' . $IN . '" w:hanging="' . $IN . '"/><w:jc w:val="both"/>'); }
				else { $body .= $par($run($b[1]), '<w:spacing w:before="120"/>' . $ind . '<w:jc w:val="both"/>'); }
				break;
			case 'b':
				$l = ($b['in'] ? $IN : 0) + 360; $t = (string) $b[1]; $k = (int) ($b[2] ?? 0);
				$body .= $par($run('●', array('sz' => 16)) . $tab . ($k ? $run(mb_substr($t, 0, $k), array('b' => 1)) . $run(mb_substr($t, $k)) : $run($t)), '<w:tabs><w:tab w:val="left" w:pos="' . $l . '"/></w:tabs><w:spacing w:before="40"/><w:ind w:left="' . $l . '" w:hanging="360"/><w:jc w:val="both"/>');
				break;
			case 'chk': $t = (string) $b[1]; $body .= $par($run(mb_substr($t, 0, 1), array('b' => 1, 'red' => 1)) . $run(' ' . ltrim(mb_substr($t, 1)), array('b' => 1)), '<w:spacing w:before="20"/>' . $ind); break;
			case 'date': $body .= $par($run($b[1]), '<w:keepNext/><w:spacing w:before="480" w:after="240"/>'); break;
			case 't': $body .= $tbl($b[1], $b[2], $b['in']); break;
			case 'sig':
				$cell = function ($lines) use ($run, $par, $TW) { $n = count($lines); $o = $par($run('……………………………………'), '<w:jc w:val="center"/><w:spacing w:before="0"/>'); foreach ($lines as $i => $s) { $o .= $par($run($s, array('b' => $i === 0 || $i === $n - 1)), '<w:jc w:val="center"/><w:spacing w:before="0"/>'); } return '<w:tc><w:tcPr><w:tcW w:w="' . intdiv($TW, 2) . '" w:type="dxa"/></w:tcPr>' . $o . '</w:tc>'; };
				$body .= $par('', '<w:keepNext/><w:spacing w:before="360"/>') . '<w:tbl><w:tblPr><w:tblW w:w="' . $TW . '" w:type="dxa"/><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid><w:gridCol w:w="' . intdiv($TW, 2) . '"/><w:gridCol w:w="' . intdiv($TW, 2) . '"/></w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr>' . $cell($b[1]) . $cell($b[2]) . '</w:tr></w:tbl>';
				break;
		}
	}
	$W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
	$hdr = '';
	if ($logo !== '') {
		$sz = @getimagesizefromstring($logo); $cx = 1163930; $cy = $sz ? (int) round($cx * $sz[1] / $sz[0]) : 535407;
		$hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . $W . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="0" w:after="0"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="1" name="logo"/><wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="1" name="logo.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p></w:hdr>';
	}
	$ftr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $W . '><w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="0" w:after="0"/></w:pPr><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>';
	$doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $W . '><w:body>' . $body . '<w:sectPr>' . ($hdr ? '<w:headerReference w:type="default" r:id="rId2"/>' : '') . '<w:footerReference w:type="default" r:id="rId3"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="850" w:bottom="1134" w:left="850" w:header="454" w:footer="454" w:gutter="0"/></w:sectPr></w:body></w:document>';
	$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri" w:eastAsia="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="hu-HU"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style></w:styles>';
	$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>' . ($hdr ? '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>' : '') . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>';
	$R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
	$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $R . 'officeDocument" Target="word/document.xml"/></Relationships>';
	$drels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $R . 'styles" Target="styles.xml"/>' . ($hdr ? '<Relationship Id="rId2" Type="' . $R . 'header" Target="header1.xml"/>' : '') . '<Relationship Id="rId3" Type="' . $R . 'footer" Target="footer1.xml"/></Relationships>';
	$f = tempnam(get_temp_dir(), 'cpsz');
	$z = new ZipArchive();
	if ($z->open($f, ZipArchive::OVERWRITE) !== true) { return new WP_Error('cp_ct', 'A Word-fájl nem hozható létre.'); }
	$z->addFromString('[Content_Types].xml', $ct); $z->addFromString('_rels/.rels', $rels); $z->addFromString('word/_rels/document.xml.rels', $drels);
	$z->addFromString('word/document.xml', $doc); $z->addFromString('word/styles.xml', $styles); $z->addFromString('word/footer1.xml', $ftr);
	if ($hdr) { $z->addFromString('word/header1.xml', $hdr); $z->addFromString('word/_rels/header1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $R . 'image" Target="media/logo.png"/></Relationships>'); $z->addFromString('word/media/logo.png', $logo); }
	$z->close();
	$data = (string) file_get_contents($f); @unlink($f);
	return $data;
}

/* ------------------------------------------------------------ végpontok */
function cp_ct_state_in() {
	$st = json_decode(wp_unslash((string) ($_REQUEST['st'] ?? '')), true);
	if (!is_array($st)) { cp_el_err('Hibás szerződésadatok.'); }
	$L = cp_ct_lib();
	$s = function ($v, $max = 300) { return mb_substr(sanitize_text_field((string) $v), 0, $max); };
	$c = (array) ($st['client'] ?? array());
	$out = array('deal' => (int) ($st['deal'] ?? 0), 'issuer' => ($st['issuer'] ?? '') === 'EV' ? 'EV' : 'KFT', 'lang' => ($st['lang'] ?? '') === 'en' ? 'en' : 'hu',
		'client' => array('type' => ($c['type'] ?? '') === 'magan' ? 'magan' : 'ceg', 'company' => $s($c['company'] ?? ''), 'seat' => $s($c['seat'] ?? ''), 'reg' => $s($c['reg'] ?? '', 40), 'tax' => $s($c['tax'] ?? '', 40), 'signer' => $s($c['signer'] ?? ''), 'name' => $s($c['name'] ?? ''), 'address' => $s($c['address'] ?? '')),
		'contact' => array('name' => $s($st['contact']['name'] ?? ''), 'tel' => $s($st['contact']['tel'] ?? '', 40)),
		'venue' => $s($st['venue'] ?? ''), 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($st['date'] ?? '')) ? $st['date'] : '', 'start' => $s($st['start'] ?? '', 10), 'end' => $s($st['end'] ?? '', 10),
		'svc' => array(), 'travel_city' => $s($st['travel_city'] ?? '', 80), 'travel' => max(0, (float) ($st['travel'] ?? 0)), 'pay' => in_array($st['pay'] ?? '', array('pre', 'post', 'cash'), true) ? $st['pay'] : 'post',
		'cdate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($st['cdate'] ?? '')) ? $st['cdate'] : wp_date('Y-m-d'), 'place' => $s($st['place'] ?? 'Budapest', 60) ?: 'Budapest', 'note' => mb_substr(sanitize_textarea_field((string) ($st['note'] ?? '')), 0, 3000));
	foreach (array_slice((array) ($st['svc'] ?? array()), 0, 16) as $x) { if (isset($L['services'][$x['k'] ?? ''])) { $out['svc'][] = array('k' => $x['k'], 'hours' => max(1, min(24, (int) ($x['hours'] ?? 3))), 'price' => max(0, (float) ($x['price'] ?? 0)), 'bg' => $s($x['bg'] ?? '', 120)); } }
	return $out;
}
add_action('wp_ajax_nopriv_cp_ct_get', 'cp_ct_ajax_get');
add_action('wp_ajax_cp_ct_get', 'cp_ct_ajax_get');
function cp_ct_ajax_get() {
	cp_el_auth(); cp_tab_need(array('deals'));
	$id = (int) ($_POST['id'] ?? 0);
	$pf = cp_ct_prefill($id);
	if (!$pf) { cp_el_err('Nincs ilyen deal.', 404); }
	$saved = get_option('cp_ct_d' . $id);
	$L = cp_ct_lib();
	$svc = array(); foreach ($L['order'] as $k) { $S = $L['services'][$k]; $svc[] = array('k' => $k, 'n' => $S['name'], 'hours' => $S['hours'], 'hatter' => $S['hatter'], 'review' => $S['review']); }
	wp_send_json_success(array('prefill' => $pf, 'saved' => is_array($saved) ? $saved['st'] : null, 'saved_at' => is_array($saved) ? wp_date('Y.m.d. H:i', (int) $saved['at']) : '', 'services' => $svc, 'issuers' => array_map(function ($i) { return $i['n']; }, $L['issuers'])));
}
add_action('wp_ajax_nopriv_cp_ct_preview', 'cp_ct_ajax_preview');
add_action('wp_ajax_cp_ct_preview', 'cp_ct_ajax_preview');
function cp_ct_ajax_preview() {
	cp_el_auth(); cp_tab_need(array('deals'));
	$st = cp_ct_state_in();
	if (!empty($_POST['save']) && $st['deal']) { update_option('cp_ct_d' . $st['deal'], array('st' => $st, 'at' => time()), false); }
	wp_send_json_success(array('html' => cp_ct_html(cp_ct_build($st))));
}
/* letöltés: űrlap-elküldéssel új lapon (Word fájl vagy nyomtatható oldal) */
add_action('wp_ajax_nopriv_cp_ct_file', 'cp_ct_ajax_file');
add_action('wp_ajax_cp_ct_file', 'cp_ct_ajax_file');
function cp_ct_ajax_file() {
	cp_el_auth(); cp_tab_need(array('deals'));
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$st = cp_ct_state_in();
	if ($st['deal']) { update_option('cp_ct_d' . $st['deal'], array('st' => $st, 'at' => time()), false); }
	$blocks = cp_ct_build($st);
	$nm = 'Megbizasi_szerzodes_' . ($st['deal'] ?: 'uj') . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', remove_accents($st['client']['type'] === 'ceg' ? $st['client']['company'] : $st['client']['name']));
	nocache_headers();
	if (($_POST['fmt'] ?? '') === 'docx') {
		$d = cp_ct_docx($blocks);
		if (is_wp_error($d)) { wp_die(esc_html($d->get_error_message())); }
		header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
		header('Content-Disposition: attachment; filename="' . $nm . '.docx"');
		header('Content-Length: ' . strlen($d));
		echo $d; exit;
	}
	header('Content-Type: text/html; charset=utf-8');
	header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'");
	echo cp_ct_html($blocks, true); exit;
}

/* ------------------------------------------------------------ v1.78: modulszöveg-szerkesztő (Beállítások → Szerződésmodulok, csak admin) */
function cp_ct_edit_fields($part) {
	if ($part === 'legal' || $part === 'legal_en') { return array_keys((array) (cp_ct_seed()[$part] ?? array())); }
	if (strpos($part, 'svc:') === 0) { return array('name', 'line', 'hours', 'tasks', 'hatter', 'reqs', 'reqs_extra', 'transport', 'dev', 'tdev', 'teljesites', 'extras', 'cash', 'media', 'extra_egyeb'); }
	if (strpos($part, 'svc_en:') === 0) { return array('name', 'line', 'tasks', 'reqs', 'reqs_extra', 'dev', 'tdev', 'teljesites', 'extras', 'extra_egyeb'); }
	if (strpos($part, 'iss:') === 0) { return array('rows', 'sign', 'contact'); }
	return array();
}
function cp_ct_part_val($L, $part) {
	if ($part === 'legal' || $part === 'legal_en') { return (array) ($L[$part] ?? array()); }
	if (strpos($part, 'svc:') === 0) { return (array) ($L['services'][substr($part, 4)] ?? array()); }
	if (strpos($part, 'svc_en:') === 0) { return (array) ($L['services'][substr($part, 7)]['en'] ?? array()); }
	if (strpos($part, 'iss:') === 0) { return (array) ($L['issuers'][substr($part, 4)] ?? array()); }
	return array();
}
function cp_ct_ed_auth() { cp_el_auth(); cp_tab_need(array('beallitasok')); cp_admin_need(); }
add_action('wp_ajax_nopriv_cp_ct_lib_get', 'cp_ct_ajax_lib_get');
add_action('wp_ajax_cp_ct_lib_get', 'cp_ct_ajax_lib_get');
function cp_ct_ajax_lib_get() {
	cp_ct_ed_auth();
	$L = cp_ct_lib(); $S = cp_ct_seed();
	$parts = array(array('legal', 'Jogi alap – magyar'), array('legal_en', 'Jogi alap – angol'));
	foreach ($S['order'] as $k) { $parts[] = array('svc:' . $k, $L['services'][$k]['name'] . ' – magyar'); $parts[] = array('svc_en:' . $k, $L['services'][$k]['name'] . ' – angol'); }
	foreach ($L['issuers'] as $k => $i) { $parts[] = array('iss:' . $k, 'Kiállító: ' . $i['n']); }
	$part = (string) ($_POST['part'] ?? 'legal');
	$f = cp_ct_edit_fields($part); if (!$f) { $part = 'legal'; $f = cp_ct_edit_fields('legal'); }
	$cur = cp_ct_part_val($L, $part); $base = cp_ct_part_val($S, $part); $ov = cp_ct_over()[$part] ?? array();
	$fields = array();
	foreach ($f as $k) { $fields[] = array('k' => $k, 'v' => $cur[$k] ?? '', 'base' => $base[$k] ?? '', 'over' => array_key_exists($k, $ov)); }
	wp_send_json_success(array('parts' => $parts, 'part' => $part, 'fields' => $fields, 'n_over' => array_sum(array_map('count', cp_ct_over()))));
}
add_action('wp_ajax_nopriv_cp_ct_lib_set', 'cp_ct_ajax_lib_set');
add_action('wp_ajax_cp_ct_lib_set', 'cp_ct_ajax_lib_set');
function cp_ct_ajax_lib_set() {
	cp_ct_ed_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	$part = (string) ($_POST['part'] ?? ''); $fk = (string) ($_POST['field'] ?? '');
	if (!in_array($fk, cp_ct_edit_fields($part), true)) { cp_el_err('Ismeretlen mező.'); }
	$O = cp_ct_over(); $warn = '';
	if (!empty($_POST['reset'])) { unset($O[$part][$fk]); if (empty($O[$part])) { unset($O[$part]); } update_option('cp_ct_over', $O, false); wp_send_json_success(array('msg' => 'Visszaállítva az alapszövegre.')); }
	$base = cp_ct_part_val(cp_ct_seed(), $part)[$fk] ?? '';
	$v = json_decode(wp_unslash((string) ($_POST['value'] ?? 'null')), true);
	$clean = function ($x) { return trim(mb_substr(sanitize_textarea_field((string) $x), 0, 4000)); };
	if (is_bool($base)) { $v = !empty($v); }
	elseif (is_int($base)) { $v = max(1, min(24, (int) $v)); }
	elseif (is_array($base) && $fk === 'rows') { $v = array_values(array_filter(array_map(function ($r) use ($clean) { return is_array($r) ? array($clean($r[0] ?? ''), $clean($r[1] ?? '')) : null; }, (array) $v), function ($r) { return $r && $r[0] !== ''; })); }
	elseif (is_array($base)) { $v = array_values(array_filter(array_map($clean, (array) $v), 'strlen')); }
	elseif ($fk === 'media') { $v = in_array($v, array('', 'kep', 'video', 'kepvideo'), true) ? $v : ''; }
	else { $v = $clean($v); }
	/* a helyőrzők ({H}, {P:…}, {DEV}, {MEDIA}) maradjanak meg – különben figyelmeztet */
	$tok = function ($x) { preg_match_all('/\{(?:H|DEV|MEDIA2?|P:\d+)\}/', is_array($x) ? wp_json_encode($x, JSON_UNESCAPED_UNICODE) : (string) $x, $m); return array_unique($m[0]); };
	$miss = array_diff($tok($base), $tok($v));
	if ($miss) { $warn = ' Figyelem: kimaradt a helyőrző: ' . implode(', ', $miss) . ' – ezek helyére a panel írja be az órát, az árat vagy az eszközt.'; }
	$O[$part][$fk] = $v;
	update_option('cp_ct_over', $O, false);
	wp_send_json_success(array('msg' => 'Mentve – a következő szerződéstől érvényes.' . $warn, 'warn' => $warn !== ''));
}
