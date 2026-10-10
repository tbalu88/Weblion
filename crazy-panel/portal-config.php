<?php
/* Ügyfélportál – konfiguráció: beállítások, mezők, határidők, megőrzés */

if (!defined('ABSPATH')) { exit; }

define('CP_PORTAL_DEADLINE_DAYS', 5);      // zárolás: rendezvény napja − 5 nap, 00:00 (Budapest)
define('CP_PORTAL_LATE_HOUR', 12);         // késői szerződés: rendezvény előtti nap 12:00
define('CP_PORTAL_REMIND_HOUR', 9);        // emlékeztetők 9:00-kor
define('CP_PORTAL_REMIND1_DAYS', 3);       // reminder_1: az utolsó kitöltési nap előtt 3 nappal
define('CP_PORTAL_LINK_DAYS', 90);         // a belépőlink a rendezvény után 90 napig él
define('CP_PORTAL_COOKIE', 'cp_portal_s');
define('CP_PORTAL_COOKIE_DAYS', 30);
define('CP_PORTAL_KEEP_FORM_DAYS', 30);    // adatlap: rendezvény + 30 nap
define('CP_PORTAL_KEEP_CONSENT_YEARS', 3); // hozzájárulással: rendezvény + 3 év
define('CP_PORTAL_KEEP_ACCESS_DAYS', 183); // belépési napló, lejárt tokenek: 6 hónap
define('CP_PORTAL_KEEP_PORTAL_YEARS', 5);  // portál alapadat, helyszín, értesítésnapló: 5 év

/* ------------------------------------------------------------ beállítások */

function cp_portal_defaults() {
	return array(
		'mode'          => 'off',      // off | on – kikapcsolva nem jön létre portál és nem megy ki levél
		'auto_create'   => 0,          // automatikus létrehozás a trigger-szakasznál (webhook, óránkénti tartalék, panel-szakaszváltás)
		'auto_scope'    => 'selected', // selected | all – automatikus létrehozásnál
		'deals'         => '',         // automatikus létrehozásnál ('selected'): deal-azonosítók soronként
		'base_url'      => 'https://portal.eventcrazy.hu',
		'trigger_stage' => 'Szerződés aláírva',
		'sent_stage'    => 'Adatlap kiküldve', // a „Ügyfélportál kiküldése” után ide kerül a deal a Salesmate-ben
		'sent_stage_write' => 1,
		'webhook_key'   => '',
		'cron_fallback' => 1,
		'sm_f_venue'    => '',         // üres = a panel „Helyszín” mezője (f_loc)
		'sm_f_status'   => '',         // „Adatlap állapota” – üres = nincs visszaírás
		'sm_status_labels' => "hianyos=Hiányos\nkesz=Kész\nmodositva=Módosítva",
		'sm_notes'      => 0,          // jegyzet a dealhez (a Salesmate jegyzet-végpontja ellenőrizendő)
		'pumble_url'    => '',
		'pumble_mention'=> 'Ákos',
		'mail_from'     => '',
		'mail_from_name'=> 'EventCrazy',
		'reply_to'      => '',
		'test_inbox'    => '',         // ha meg van adva, MINDEN ügyfél-levél ide megy
	);
}

function cp_portal_opts() {
	$o = get_option(CP_PORTAL_OPT, array());
	if (!is_array($o)) { $o = array(); }
	$o = array_merge(cp_portal_defaults(), $o);
	if ($o['webhook_key'] === '') {
		$o['webhook_key'] = wp_generate_password(32, false, false);
		update_option(CP_PORTAL_OPT, $o, false);
	}
	return $o;
}

/* off | on (a v1.8-as „selected” és „all” érték is bekapcsolt állapotot jelent) */
function cp_portal_mode() {
	$o = cp_portal_opts();
	return in_array($o['mode'], array('on', 'selected', 'all'), true) ? 'on' : 'off';
}

function cp_portal_selected_deals() {
	$o = cp_portal_opts();
	$out = array();
	foreach (preg_split('/[\s,;]+/', (string) $o['deals']) as $id) {
		$id = preg_replace('/[^0-9]/', '', $id);
		if ($id !== '') { $out[$id] = 1; }
	}
	return $out;
}

/* a deal kaphat-e AUTOMATIKUSAN portált (webhook / óránkénti tartalék / panel szakaszváltás) – alapból nem */
function cp_portal_deal_allowed($deal_id) {
	$o = cp_portal_opts();
	if (cp_portal_mode() !== 'on' || empty($o['auto_create'])) { return false; }
	if ($o['auto_scope'] === 'all') { return true; }
	$s = cp_portal_selected_deals();
	return isset($s[(string) $deal_id]);
}

function cp_portal_sent_stage() {
	$o = cp_portal_opts();
	return trim((string) $o['sent_stage']);
}

function cp_portal_base() {
	$o = cp_portal_opts();
	$u = wp_parse_url(trim((string) $o['base_url']));
	$host = isset($u['host']) ? strtolower($u['host']) : '';
	$path = isset($u['path']) ? '/' . trim($u['path'], '/') : '';
	if ($path === '/') { $path = ''; }
	$scheme = isset($u['scheme']) ? $u['scheme'] : 'https';
	$port = isset($u['port']) ? ':' . (int) $u['port'] : '';
	return array('host' => $host, 'path' => $path, 'root' => $scheme . '://' . $host . $port . $path);
}

function cp_portal_url($sub = '') {
	$b = cp_portal_base();
	return $b['root'] . $sub;
}

function cp_portal_panel_url($deal_id = '') {
	$p = cp_panel_opts();
	return home_url('/' . trim($p['slug'], '/') . '/' . ($deal_id !== '' ? '?portal=' . rawurlencode($deal_id) : '') . '#kovetes');
}

/* ------------------------------------------------------------ mezők
 * Egyetlen hely, ahol a kérdések élnek. A „required” átírásával egy kérdés opcionálissá tehető.
 * stage: a = szerződéshez szükséges adatok (azonnal kötelező)
 *        b = a rendezvény részletei (a határidőig: rendezvény − 5 nap)
 *        c = háttér és fotósablon (nem kötelező, a határidőig módosítható)
 * q = kérdés-azonosító (több mező is tartozhat egy kérdéshez); show_if = array(mező, érték):
 *     csak akkor látszik és akkor kötelező, ha a másik mező értéke ez (magánszemély / cég).
 */

function cp_portal_fields() {
	static $f = null;
	if ($f !== null) { return $f; }
	$yn = array('yes' => 'Igen', 'no' => 'Nem', 'unknown' => 'Nem tudom');
	$f = array(
		/* ---------------- a) szerződéshez szükséges adatok */
		'client_type' => array(
			'stage' => 'a', 'q' => 'client', 'qlabel' => 'Megrendelő adatai', 'section' => 'megrendelo', 'required' => true,
			'label' => 'Kinek a nevére szóljon a szerződés?',
			'kind' => 'enum', 'options' => array('magan' => 'Magánszemélyként rendelek', 'ceg' => 'Cégként rendelek'),
		),
		'client_private' => array(
			'stage' => 'a', 'q' => 'client', 'qlabel' => 'Megrendelő adatai', 'section' => 'megrendelo', 'required' => true, 'show_if' => array('client_type', 'magan'),
			'label' => 'Megrendelő (magánszemély)',
			'parts' => array(
				'name'    => array('label' => 'Teljes név', 'kind' => 'text', 'max' => 120, 'req' => true),
				'address' => array('label' => 'Lakcím', 'kind' => 'text', 'max' => 200, 'req' => true, 'ph' => 'irányítószám, település, utca, házszám'),
				'phone'   => array('label' => 'Telefonszám', 'kind' => 'tel', 'max' => 30, 'req' => true, 'ph' => '+36 30 123 4567'),
				'email'   => array('label' => 'E-mail-cím', 'kind' => 'email', 'max' => 190, 'req' => true),
				'billing' => array('label' => 'Eltérő számlázási adatok (nem kötelező)', 'kind' => 'textarea', 'max' => 500, 'ph' => 'Ha a számla más névre / címre szóljon: név, cím, adószám'),
			),
		),
		'client_company' => array(
			'stage' => 'a', 'q' => 'client', 'qlabel' => 'Megrendelő adatai', 'section' => 'megrendelo', 'required' => true, 'show_if' => array('client_type', 'ceg'),
			'label' => 'Megrendelő (cég)',
			'parts' => array(
				'company'       => array('label' => 'Cégnév', 'kind' => 'text', 'max' => 200, 'req' => true),
				'seat'          => array('label' => 'Székhely', 'kind' => 'text', 'max' => 200, 'req' => true, 'ph' => 'irányítószám, település, utca, házszám'),
				'tax'           => array('label' => 'Adószám', 'kind' => 'tax', 'max' => 20, 'req' => true, 'ph' => '12345678-1-12'),
				'reg'           => array('label' => 'Cégjegyzékszám', 'kind' => 'reg', 'max' => 30, 'req' => true, 'ph' => '01-09-123456'),
				'signer_name'   => array('label' => 'Aláíró neve', 'kind' => 'text', 'max' => 120, 'req' => true),
				'signer_title'  => array('label' => 'Aláíró beosztása', 'kind' => 'text', 'max' => 120, 'req' => true, 'ph' => 'pl. ügyvezető'),
				'billing_email' => array('label' => 'Számlázási e-mail-cím', 'kind' => 'email', 'max' => 190, 'req' => true),
				'contact_name'  => array('label' => 'Kapcsolattartó neve', 'kind' => 'text', 'max' => 120, 'req' => true),
				'contact_phone' => array('label' => 'Kapcsolattartó telefonszáma', 'kind' => 'tel', 'max' => 30, 'req' => true, 'ph' => '+36 30 123 4567'),
				'contact_email' => array('label' => 'Kapcsolattartó e-mail-címe', 'kind' => 'email', 'max' => 190, 'req' => true),
				'po'            => array('label' => 'Megrendelésszám / PO (nem kötelező)', 'kind' => 'text', 'max' => 60),
			),
		),
		'event_date' => array(
			'stage' => 'a', 'q' => 'event_date', 'qlabel' => 'A rendezvény dátuma', 'section' => 'rendezveny', 'required' => true,
			'label' => 'A rendezvény dátuma',
			'help' => 'A foglalás alapján kitöltöttük – ha eltér, írd át.',
			'kind' => 'date',
		),
		'venue_address' => array(
			'stage' => 'a', 'q' => 'venue', 'qlabel' => 'Helyszín neve és címe', 'section' => 'rendezveny', 'required' => true,
			'label' => 'Hol lesz a rendezvény?',
			'parts' => array(
				'name'    => array('label' => 'Helyszín neve', 'kind' => 'text', 'max' => 200, 'req' => true, 'ph' => 'pl. Hotel Aurora, Duna-parti rendezvényterem'),
				'address' => array('label' => 'Pontos cím', 'kind' => 'text', 'max' => 200, 'req' => true, 'ph' => 'irányítószám, település, utca, házszám'),
			),
		),
		'schedule' => array(
			'stage' => 'a', 'q' => 'schedule', 'qlabel' => 'Kezdés és a szolgáltatás vége', 'section' => 'rendezveny', 'required' => true,
			'label' => 'Mettől meddig kéritek a szolgáltatást?',
			'help' => 'A foglalás alapján kitöltöttük, ha változott, írd át.',
			'note' => 'Ha a helyszínen meggondoljátok magatokat, a szolgáltatás ott is hosszabbítható.',
			'parts' => array(
				'start' => array('label' => 'A gép indulása', 'kind' => 'time', 'req' => true),
				'end'   => array('label' => 'Szolgáltatás vége', 'kind' => 'time', 'req' => true),
			),
		),
		'services' => array(
			'stage' => 'a', 'q' => 'services', 'qlabel' => 'Megrendelt szolgáltatások', 'section' => 'rendezveny', 'required' => true,
			'label' => 'Milyen szolgáltatásokat rendeltek?',
			'help' => 'A foglalás alapján kitöltöttük – ha eltér, írd át.',
			'kind' => 'text', 'max' => 300, 'ph' => 'pl. Szelfigép, Medium Party csomag',
		),
		/* ---------------- b) a rendezvény részletei (határidőig) */
		'venue_room' => array(
			'stage' => 'b', 'q' => 'room', 'qlabel' => 'Terem / pontos hely', 'section' => 'helyszin', 'required' => true,
			'label' => 'Melyik teremben / hol pontosan áll majd a gép?',
			'kind' => 'text', 'max' => 200, 'ph' => 'pl. Nagyterem, a bejárattól jobbra',
		),
		'location_type' => array(
			'stage' => 'b', 'q' => 'location_type', 'qlabel' => 'Beltér vagy kültér', 'section' => 'helyszin', 'required' => true,
			'label' => 'Beltéren vagy kültéren lesz a gép?',
			'kind' => 'enum', 'options' => array('indoor' => 'Beltéren', 'outdoor_covered' => 'Kültéren, fedett helyen', 'outdoor_open' => 'Kültéren, fedetlen helyen'),
		),
		'power_within_10m' => array(
			'stage' => 'b', 'q' => 'power', 'qlabel' => 'Áram 10 méteren belül', 'section' => 'helyszin', 'required' => true,
			'label' => 'Van konnektor a gép helyétől 10 méteren belül?',
			'kind' => 'enum', 'options' => $yn,
		),
		'space_ok' => array(
			'stage' => 'b', 'q' => 'space', 'qlabel' => 'Elég hely a gépnek', 'section' => 'helyszin', 'required' => true,
			'label' => 'Van elég szabad hely a gépnek és a vendégeknek?',
			'help' => 'Ha nem vagy biztos benne, válaszd a „Nem tudom” lehetőséget, és egyeztetünk.',
			'parts' => array(
				'answer' => array('label' => '', 'kind' => 'enum', 'req' => true, 'options' => $yn),
			),
		),
		'access_route' => array(
			'stage' => 'b', 'q' => 'access', 'qlabel' => 'Bejutás (lift / lépcső)', 'section' => 'helyszin', 'required' => true,
			'label' => 'Hogyan jutunk be a géppel?',
			'parts' => array(
				'type'   => array('label' => '', 'kind' => 'enum', 'req' => true, 'options' => array('ground' => 'Földszint vagy akadálymentes', 'lift' => 'Lifttel', 'stairs_only' => 'Csak lépcsőn')),
				'floors' => array('label' => 'Hányadik emelet?', 'kind' => 'int', 'min' => 1, 'max' => 30, 'show_if' => array('type', 'stairs_only')),
				'note'   => array('label' => 'Megjegyzés (nem kötelező)', 'kind' => 'text', 'max' => 500, 'ph' => 'pl. hátsó bejárat, rakodórámpa'),
			),
		),
		'onsite_contact' => array(
			'stage' => 'b', 'q' => 'contact', 'qlabel' => 'Helyszíni kapcsolattartó', 'section' => 'kapcsolat', 'required' => true,
			'label' => 'Kit hívhat a kollégánk a helyszínen?',
			'parts' => array(
				'name'  => array('label' => 'Név', 'kind' => 'text', 'max' => 120, 'req' => true),
				'phone' => array('label' => 'Telefonszám', 'kind' => 'tel', 'max' => 30, 'req' => true, 'ph' => '+36 30 123 4567'),
			),
		),
		'arrival' => array(
			'stage' => 'b', 'q' => 'arrival', 'qlabel' => 'Érkezés (építés)', 'section' => 'kapcsolat', 'required' => true,
			'label' => 'Legkorábban mikor érkezhetünk építeni?',
			'kind' => 'time',
		),
		'vehicle_access' => array(
			'stage' => 'b', 'q' => 'vehicle_access', 'qlabel' => 'Behajtás', 'section' => 'opcionalis', 'required' => false,
			'label' => 'Be lehet állni autóval a bejárathoz pakolni?',
			'parts' => array(
				'type'   => array('label' => '', 'kind' => 'enum', 'options' => array('free' => 'Igen, szabadon', 'permit_needed' => 'Behajtási engedély kell', 'unknown' => 'Nem tudom')),
				'window' => array('label' => 'Mikor lehet behajtani? (nem kötelező)', 'kind' => 'text', 'max' => 100, 'ph' => 'pl. 14:00–16:00'),
				'note'   => array('label' => 'Megjegyzés (nem kötelező)', 'kind' => 'text', 'max' => 500),
			),
		),
		'parking' => array(
			'stage' => 'b', 'q' => 'parking', 'qlabel' => 'Parkolás', 'section' => 'opcionalis', 'required' => false,
			'label' => 'Hol tudunk parkolni a rendezvény alatt?',
			'parts' => array(
				'type' => array('label' => '', 'kind' => 'enum', 'options' => array('free' => 'Ingyenes parkoló', 'paid' => 'Fizetős parkolás', 'none' => 'Nincs parkolási lehetőség')),
				'note' => array('label' => 'Megjegyzés (nem kötelező)', 'kind' => 'text', 'max' => 500),
			),
		),
		'guests_type_dress' => array(
			'stage' => 'b', 'q' => 'guests', 'qlabel' => 'Vendégek, rendezvény jellege', 'section' => 'opcionalis', 'required' => false,
			'label' => 'Vendégek és a rendezvény jellege',
			'parts' => array(
				'guests'     => array('label' => 'Várható létszám (fő)', 'kind' => 'int', 'min' => 1, 'max' => 5000),
				'type'       => array('label' => 'Milyen rendezvény?', 'kind' => 'text', 'max' => 200, 'ph' => 'pl. céges évzáró, esküvő, születésnap'),
				'dress_code' => array('label' => 'Dress code (ha van)', 'kind' => 'text', 'max' => 200),
			),
		),
		'special_request' => array(
			'stage' => 'b', 'q' => 'special', 'qlabel' => 'Különleges kérés', 'section' => 'opcionalis', 'required' => false,
			'label' => 'Van bármi, amit még tudnunk kell?',
			'kind' => 'textarea', 'max' => 2000, 'ph' => 'Kérés, meglepetés, időzítés, bármi…',
		),
		/* ---------------- c) háttér és fotósablon (nem kötelező) */
		'background' => array(
			'stage' => 'c', 'q' => 'background', 'qlabel' => 'Háttér', 'section' => 'extra', 'required' => false,
			'label' => 'Melyik hátteret kéritek?',
			'help' => 'Csak az aznap még szabad hátterek látszanak. A választás kérés: a szerződés kiküldésével válik foglalássá.',
			'kind' => 'bg',
		),
		'photo_template' => array(
			'stage' => 'c', 'q' => 'template', 'qlabel' => 'Fotósablon', 'section' => 'extra', 'required' => false,
			'label' => 'Fotósablon (a kinyomtatott fotók kerete)',
			'kind' => 'sablon',
		),
		'retention_consent' => array(
			'stage' => 'c', 'q' => 'consent', 'qlabel' => 'Adatmegőrzés', 'section' => 'vege', 'required' => false,
			'label' => 'Megőrizhetitek az adatlapot (és később a logót, sablont) a következő rendezvényünkhöz, legfeljebb 3 évig.',
			'kind' => 'consent',
		),
	);
	return $f;
}

function cp_portal_sections() {
	return array(
		'megrendelo' => array('a', 'A megrendelő'),
		'rendezveny' => array('a', 'A rendezvény'),
		'helyszin'   => array('b', 'A helyszín'),
		'kapcsolat'  => array('b', 'Helyszíni kapcsolattartó és érkezés'),
		'opcionalis' => array('b', 'Ha tudod, ezeket is írd meg (nem kötelező)'),
		'extra'      => array('c', 'Háttér és fotósablon (nem kötelező)'),
		'vege'       => array('c', ''),
	);
}

function cp_portal_stage_titles() {
	return array(
		'a' => array('1. Szerződéshez szükséges adatok', 'Ezekből készül a szerződés – kérjük, minél előbb töltsd ki.'),
		'b' => array('2. A rendezvény részletei', 'Ráér a határidőig – hogy a kollégánk mindenre felkészülten érkezzen.'),
		'c' => array('3. Háttér és fotósablon', 'Nem kötelező, a határidőig bármikor módosíthatod.'),
	);
}

/* látszik-e (és kötelező-e) a mező a többi válasz alapján (magánszemély / cég) */
function cp_portal_field_active($f, $answers) {
	if (empty($f['show_if'])) { return true; }
	$v = isset($answers[$f['show_if'][0]]) ? $answers[$f['show_if'][0]] : '';
	return is_scalar($v) && (string) $v === $f['show_if'][1];
}

/* A v1 portálok (a szerződés aláírása után, automatikusan jöttek létre) a szerződéses lépcsőt nem kérik kötelezően. */
function cp_portal_stage_required($stage, $flow) {
	return !($stage === 'a' && $flow === 'v1');
}

/* kötelező kérdések: q => címke ($stage: a | b | null = mind) */
function cp_portal_required_questions($stage = null, $flow = 'v2') {
	$out = array();
	foreach (cp_portal_fields() as $k => $f) {
		if (empty($f['required']) || ($stage !== null && $f['stage'] !== $stage) || !cp_portal_stage_required($f['stage'], $flow)) { continue; }
		$out[$f['q']] = $f['qlabel'];
	}
	return $out;
}

function cp_portal_required_total($stage = null, $flow = 'v2') {
	return count(cp_portal_required_questions($stage, $flow));
}

/* hiányzó kötelező kérdések a válaszok alapján: q => címke */
function cp_portal_missing($answers, $stage = null, $flow = 'v2') {
	$miss = cp_portal_required_questions($stage, $flow);
	$byq = array();
	foreach (cp_portal_fields() as $k => $f) {
		if (!isset($miss[$f['q']]) || !cp_portal_field_active($f, $answers)) { continue; }
		$ok = isset($answers[$k]) && !cp_portal_is_empty_value($answers[$k]);
		$byq[$f['q']] = isset($byq[$f['q']]) ? ($byq[$f['q']] && $ok) : $ok;
	}
	foreach ($byq as $q => $ok) { if ($ok) { unset($miss[$q]); } }
	return $miss;
}

/* ------------------------------------------------------------ validálás */

function cp_portal_is_empty_value($v) {
	if ($v === null || $v === '' || $v === false) { return true; }
	if (is_array($v)) {
		foreach ($v as $x) { if (!cp_portal_is_empty_value($x)) { return false; } }
		return true;
	}
	return trim((string) $v) === '';
}

function cp_portal_clean_text($v, $max) {
	if (!is_scalar($v)) { return ''; }
	$s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v));
	return mb_substr($s, 0, $max);
}

function cp_portal_phone_ok($p) {
	$n = preg_replace('/[\s\-\/().]/', '', (string) $p);
	if (preg_match('/^(\+36|06)\d{8,9}$/', $n)) { return true; }      // magyar: +36 / 06 + körzet + szám
	if (preg_match('/^\+(?!36)[1-9]\d{7,14}$/', $n)) { return true; } // nemzetközi (E.164)
	return false;
}

function cp_portal_time_ok($t) {
	return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
}

function cp_portal_minutes($t) {
	return ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2);
}

/* egy rész (alelem) tisztítása; WP_Error vagy a tiszta érték ('' = üres) */
function cp_portal_clean_part($p, $raw, $label) {
	$kind = $p['kind'];
	if ($kind === 'enum') {
		$v = is_scalar($raw) ? (string) $raw : '';
		if ($v === '') { return ''; }
		return isset($p['options'][$v]) ? $v : new WP_Error('invalid', 'Válassz a lehetőségek közül' . ($label !== '' ? ' (' . $label . ')' : '') . '.');
	}
	if ($kind === 'int') {
		$v = is_scalar($raw) ? trim((string) $raw) : '';
		if ($v === '') { return ''; }
		if (!preg_match('/^\d+$/', $v)) { return new WP_Error('invalid', $label . ': csak egész szám adható meg.'); }
		$n = (int) $v;
		if ($n < $p['min'] || $n > $p['max']) { return new WP_Error('invalid', $label . ': ' . $p['min'] . ' és ' . $p['max'] . ' közötti szám lehet.'); }
		return $n;
	}
	if ($kind === 'time') {
		$v = is_scalar($raw) ? trim((string) $raw) : '';
		if ($v === '') { return ''; }
		if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $v, $m)) { $v = sprintf('%02d:%02d', $m[1], $m[2]); }
		return cp_portal_time_ok($v) ? $v : new WP_Error('invalid', ($label !== '' ? $label . ': ' : '') . 'ÓÓ:PP formában add meg (pl. 18:30).');
	}
	if ($kind === 'date') {
		$v = is_scalar($raw) ? trim((string) $raw) : '';
		if ($v === '') { return ''; }
		if (preg_match('/^(\d{4})[.\-\/]\s?(\d{1,2})[.\-\/]\s?(\d{1,2})\.?$/', $v, $m)) { $v = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]); }
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return new WP_Error('invalid', 'A dátumot ÉÉÉÉ-HH-NN formában add meg.'); }
		return $v;
	}
	if ($kind === 'tel') {
		$v = cp_portal_clean_text($raw, $p['max']);
		if ($v === '') { return ''; }
		return cp_portal_phone_ok($v) ? $v : new WP_Error('invalid', ($label !== '' ? $label . ': ' : '') . 'a telefonszám nem tűnik helyesnek. Magyar számnál +36 vagy 06 kezdettel, külföldinél + és országkód.');
	}
	if ($kind === 'email') {
		$v = cp_portal_clean_text($raw, $p['max']);
		if ($v === '') { return ''; }
		return is_email($v) ? $v : new WP_Error('invalid', ($label !== '' ? $label . ': ' : '') . 'az e-mail-cím nem tűnik helyesnek.');
	}
	if ($kind === 'tax') {
		$v = strtoupper(preg_replace('/\s+/', '', cp_portal_clean_text($raw, 30)));
		if ($v === '') { return ''; }
		if (preg_match('/^(\d{8})-?(\d)-?(\d{2})$/', $v, $m)) { return $m[1] . '-' . $m[2] . '-' . $m[3]; }
		if (preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $v)) { return $v; }  // uniós közösségi adószám (külföldi cég)
		return new WP_Error('invalid', 'Adószám: 12345678-1-12 formában add meg (külföldi cégnél a közösségi adószámot).');
	}
	if ($kind === 'reg') {
		$v = cp_portal_clean_text($raw, 30);
		if ($v === '') { return ''; }
		$n = preg_replace('/[\s.]/', '', $v);
		if (preg_match('/^(\d{2})-?(\d{2})-?(\d{6})$/', $n, $m)) { return $m[1] . '-' . $m[2] . '-' . $m[3]; }
		if (mb_strlen($n) >= 5) { return $v; }  // külföldi / más nyilvántartási szám
		return new WP_Error('invalid', 'Cégjegyzékszám: 01-09-123456 formában add meg.');
	}
	// text, textarea
	$max = isset($p['max']) ? (int) $p['max'] : 200;
	if (is_scalar($raw) && mb_strlen(trim((string) $raw)) > $max) { return new WP_Error('invalid', ($label !== '' ? $label . ': ' : '') . 'legfeljebb ' . $max . ' karakter lehet.'); }
	return cp_portal_clean_text($raw, $max);
}

/* Egy mező értékének validálása. $answers: a többi mentett válasz (az összefüggő ellenőrzésekhez).
 * Visszatér: array('value' => tiszta érték vagy null (= törlés)) vagy WP_Error. */
function cp_portal_validate($key, $raw, $answers = array()) {
	$fields = cp_portal_fields();
	if (!isset($fields[$key])) { return new WP_Error('unknown', 'Ismeretlen mező.'); }
	$f = $fields[$key];
	$kind = isset($f['kind']) ? $f['kind'] : '';

	if ($kind === 'consent') {
		$given = is_array($raw) ? !empty($raw['given']) : !empty($raw);
		return array('value' => array('given' => (bool) $given, 'at' => gmdate('c')));
	}
	if ($kind === 'sablon') {
		return new WP_Error('invalid', 'A fotósablon a sablonkészítőben menthető.');
	}
	if ($kind === 'bg') {
		$v = is_scalar($raw) ? trim((string) $raw) : '';
		return array('value' => $v === '' ? null : mb_substr($v, 0, 80));  // a szabad hátterek ellenőrzése a mentésnél (cp_portal_bg_check)
	}

	if (!isset($f['parts'])) {
		$p = array('kind' => $kind === 'textarea' ? 'text' : $kind, 'max' => isset($f['max']) ? $f['max'] : 200, 'options' => isset($f['options']) ? $f['options'] : array());
		$v = cp_portal_clean_part($p, is_array($raw) ? '' : $raw, '');
		if (is_wp_error($v)) { return $v; }
		if ($key === 'arrival' && $v !== '' && isset($answers['schedule']['start']) && cp_portal_time_ok($answers['schedule']['start'])) {
			if (cp_portal_minutes($v) > cp_portal_minutes($answers['schedule']['start'])) {
				return new WP_Error('invalid', 'Az érkezés nem lehet később, mint a gép indulása (' . $answers['schedule']['start'] . ').');
			}
		}
		return array('value' => $v === '' ? null : $v);
	}

	$in = is_array($raw) ? $raw : array();
	$out = array();
	foreach ($f['parts'] as $pk => $p) {
		$v = cp_portal_clean_part($p, isset($in[$pk]) ? $in[$pk] : '', rtrim($p['label'] !== '' ? $p['label'] : $f['label'], '?'));
		if (is_wp_error($v)) { $v->add_data(array('part' => $pk)); return $v; }
		$out[$pk] = $v;
	}
	// feltételes részek: ha nem látszik, nem tároljuk
	foreach ($f['parts'] as $pk => $p) {
		if (!empty($p['show_if']) && (string) $out[$p['show_if'][0]] !== $p['show_if'][1]) { $out[$pk] = ''; }
	}
	if (cp_portal_is_empty_value($out)) { return array('value' => null); }

	// kötelező részek (ha a mezőbe bármit írtak)
	foreach ($f['parts'] as $pk => $p) {
		$need = !empty($p['req']) || (!empty($p['show_if']) && (string) $out[$p['show_if'][0]] === $p['show_if'][1]);
		if ($need && $out[$pk] === '') {
			$e = new WP_Error('invalid', rtrim(preg_replace('/\s*\(nem kötelező\)$/u', '', $p['label'] !== '' ? $p['label'] : $f['label']), '?') . ': ezt is add meg.');
			$e->add_data(array('part' => $pk));
			return $e;
		}
	}

	if ($key === 'schedule') {
		$s = cp_portal_minutes($out['start']);
		$e = cp_portal_minutes($out['end']);
		// éjfélen átnyúló zárás (pl. 20:00 → 01:00): reggel 6-ig a vége a következő napra esik
		if ($e <= $s && $e <= 6 * 60 && $s >= 12 * 60) { $e += 1440; }
		if ($s >= $e) { $er = new WP_Error('invalid', 'A szolgáltatás végének később kell lennie, mint az indulásnak.'); $er->add_data(array('part' => 'end')); return $er; }
		if (isset($answers['arrival']) && cp_portal_time_ok($answers['arrival']) && cp_portal_minutes($answers['arrival']) > $s) {
			$er = new WP_Error('invalid', 'A gép indulása nem lehet korábban, mint a megadott érkezés (' . $answers['arrival'] . ') – azt is írd át.');
			$er->add_data(array('part' => 'start'));
			return $er;
		}
	}
	if ($key === 'guests_type_dress' && $out['guests'] === '' && $out['type'] === '' && $out['dress_code'] === '') {
		return array('value' => null);
	}
	// üres opcionális részek elhagyása (tömörebb JSON)
	foreach ($out as $pk => $v) { if ($v === '' && empty($f['parts'][$pk]['req'])) { unset($out[$pk]); } }
	return array('value' => $out);
}

/* a mezőséma a felületeknek (JS) */
function cp_portal_schema() {
	$out = array();
	foreach (cp_portal_fields() as $k => $f) {
		$x = array('key' => $k, 'label' => $f['label'], 'q' => $f['q'], 'qlabel' => $f['qlabel'], 'section' => $f['section'], 'stage' => $f['stage'], 'required' => !empty($f['required']));
		foreach (array('kind', 'options', 'max', 'parts', 'help', 'ph', 'show_if', 'note') as $a) { if (isset($f[$a])) { $x[$a] = $f[$a]; } }
		$out[] = $x;
	}
	return $out;
}

/* ------------------------------------------------------------ idő, határidők */

function cp_portal_tz() {
	static $tz = null;
	if ($tz === null) { $tz = new DateTimeZone('Europe/Budapest'); }
	return $tz;
}

/* Budapest helyi idő → Unix időbélyeg */
function cp_portal_local_ts($ymd, $hm = '00:00') {
	try {
		$d = new DateTime($ymd . ' ' . $hm . ':00', cp_portal_tz());
		return $d->getTimestamp();
	} catch (Exception $e) {
		return 0;
	}
}

function cp_portal_ymd_add($ymd, $days) {
	try {
		$d = new DateTime($ymd . ' 12:00:00', cp_portal_tz());
		$d->modify(($days >= 0 ? '+' : '') . (int) $days . ' day');
		return $d->format('Y-m-d');
	} catch (Exception $e) {
		return $ymd;
	}
}

function cp_portal_today() {
	$d = new DateTime('now', cp_portal_tz());
	return $d->format('Y-m-d');
}

function cp_portal_db_ts($s) {
	return ($s === null || $s === '' || $s === '0000-00-00 00:00:00') ? 0 : (int) strtotime($s . ' UTC');
}

function cp_portal_db_now($ts = null) {
	return gmdate('Y-m-d H:i:s', $ts === null ? time() : (int) $ts);
}

/* Határidő-adatok egy portálhoz.
 * normál: rendezvény − 5 nap 00:00; ha a portál ennél később jött létre (késői szerződés):
 *         rendezvény előtti nap 12:00, és reminder_1 nem megy ki.
 * last_day = az utolsó kitöltési nap (a zárolás előtti nap, késői szerződésnél maga a határidő napja);
 * reminder_1 = last_day − 3 nap 9:00, reminder_2 = last_day 9:00. */
function cp_portal_deadline_info($portal) {
	$ev = (string) $portal->event_date;
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ev)) { return null; }
	$normal = cp_portal_local_ts(cp_portal_ymd_add($ev, -CP_PORTAL_DEADLINE_DAYS), '00:00');
	$created = cp_portal_db_ts($portal->created_at);
	$late = $created >= $normal;
	$deadline = $late ? cp_portal_local_ts(cp_portal_ymd_add($ev, -1), sprintf('%02d:00', CP_PORTAL_LATE_HOUR)) : $normal;
	$last_day = wp_date('Y-m-d', $deadline - 1, cp_portal_tz());
	return array(
		'deadline'  => $deadline,
		'late'      => $late,
		'last_day'  => $last_day,
		'reminder_1'=> $late ? 0 : cp_portal_local_ts(cp_portal_ymd_add($last_day, -CP_PORTAL_REMIND1_DAYS), sprintf('%02d:00', CP_PORTAL_REMIND_HOUR)),
		'reminder_2'=> cp_portal_local_ts($last_day, sprintf('%02d:00', CP_PORTAL_REMIND_HOUR)),
		'text'      => cp_portal_hu_datetime($late ? $deadline : $deadline - 60),
	);
}

function cp_portal_link_expiry($event_date) {
	return cp_portal_local_ts(cp_portal_ymd_add($event_date, CP_PORTAL_LINK_DAYS), '23:59');
}

function cp_portal_hu_months() {
	return array('január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
}

function cp_portal_hu_date($ymd, $with_day = true) {
	if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $ymd, $m)) { return (string) $ymd; }
	$days = array('vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat');
	$mo = cp_portal_hu_months();
	$s = $m[1] . '. ' . $mo[(int) $m[2] - 1] . ' ' . (int) $m[3] . '.';
	if ($with_day) { $s .= ' (' . $days[(int) wp_date('w', cp_portal_local_ts($ymd, '12:00'), cp_portal_tz())] . ')'; }
	return $s;
}

function cp_portal_hu_datetime($ts) {
	return cp_portal_hu_date(wp_date('Y-m-d', $ts, cp_portal_tz()), true) . ' ' . wp_date('H:i', $ts, cp_portal_tz());
}
