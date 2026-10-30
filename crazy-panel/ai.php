<?php
/* =====================================================================
 * AI válaszvarázsló (v1.38) – a Levelezésben egy beérkezett levélszálra válaszpiszkozatot ír a Claude API-val.
 * Kontextus: a levélszál, a belső jegyzetek, a kapcsolt deal adatai és a panel levélsablonjai
 * (később: a korábbi levelezésből a leghasonlóbb szálak).
 * Adatvédelem: küldés előtt álnevesít – nevek, e-mail-címek, telefonszámok, adó- és bankszámlaszámok helyett
 * [NÉV1], [EMAIL1], [TEL1] … jelölők mennek ki; a válaszba a panel írja vissza a valódi adatokat.
 * Semmit nem küld el magától: a piszkozatot a kolléga nézi át a levélíróban. A Salesmate-hez nem nyúl.
 * Az API-kulcs titkosítva tárolódik (cp_st_secret_set: ai_key); beállítás: Beállítások → Crazy AI.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_AI_MODEL', 'claude-opus-5-5');

function cp_ai_opts() {
	$o = get_option('cp_ai_opts', array());
	return wp_parse_args(is_array($o) ? $o : array(), array('on' => 0, 'model' => CP_AI_MODEL, 'effort' => 'medium', 'style' => ''));
}
function cp_ai_key() { return function_exists('cp_st_secret') ? (string) cp_st_secret('ai_key') : ''; }
function cp_ai_ready() { $o = cp_ai_opts(); return !empty($o['on']) && cp_ai_key() !== ''; }
function cp_ai_models() { return array('claude-opus-5-5' => 'Claude Opus 5.5 (legjobb minőség – ajánlott)', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (gyorsabb, olcsóbb)'); }

/* ------------------------------------------------------------ Claude Messages API (nyers HTTP: a tárhelyen nincs Composer / SDK) */
function cp_ai_call($system, $messages, $schema = null, $max_tokens = 8000) {
	$key = cp_ai_key();
	if ($key === '') { return new WP_Error('nokey', 'Nincs megadva az AI API-kulcs (Beállítások → Crazy AI).'); }
	$o = cp_ai_opts();
	$model = isset(cp_ai_models()[$o['model']]) ? $o['model'] : CP_AI_MODEL;
	$body = array('model' => $model, 'max_tokens' => $max_tokens, 'system' => $system, 'messages' => $messages,
		'output_config' => array('effort' => in_array($o['effort'], array('low', 'medium', 'high'), true) ? $o['effort'] : 'medium'));
	if ($schema) { $body['output_config']['format'] = array('type' => 'json_schema', 'schema' => $schema); }
	$headers = array('Content-Type' => 'application/json', 'x-api-key' => $key, 'anthropic-version' => '2026-10-21');
	/* ha a biztonsági szűrő elutasítaná, a szerver az ajánlott tartalék modellel próbálja újra */
	$body['fallbacks'] = 'default';
	$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
	if (function_exists('set_time_limit')) { @set_time_limit(150); }
	$res = wp_remote_post('https://api.anthropic.com/v1/messages', array('timeout' => 120, 'headers' => $headers, 'body' => wp_json_encode($body)));
	if (is_wp_error($res)) { return new WP_Error('net', 'Az AI-szolgáltatás nem érhető el: ' . $res->get_error_message()); }
	$code = (int) wp_remote_retrieve_response_code($res);
	$j = json_decode((string) wp_remote_retrieve_body($res), true);
	if ($code < 200 || $code >= 300) {
		$m = is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : 'HTTP ' . $code;
		$hu = array(401 => 'Érvénytelen API-kulcs.', 403 => 'Az API-kulcsnak nincs jogosultsága.', 429 => 'Túl sok kérés vagy elfogyott a keret – próbáld újra pár perc múlva, vagy nézd meg az egyenleget a console.anthropic.com-on.', 529 => 'Az AI-szolgáltatás most túlterhelt – próbáld újra.');
		return new WP_Error('api', ($hu[$code] ?? 'Az AI-szolgáltatás hibát adott.') . ' (' . mb_substr($m, 0, 200) . ')');
	}
	if (!is_array($j)) { return new WP_Error('api', 'Érthetetlen válasz az AI-szolgáltatástól.'); }
	if (($j['stop_reason'] ?? '') === 'refusal') { return new WP_Error('refusal', 'Az AI erre a levélre nem írt választ – írd meg kézzel.'); }
	$txt = '';
	foreach ((array) ($j['content'] ?? array()) as $b) { if (($b['type'] ?? '') === 'text') { $txt .= $b['text']; } }
	if (($j['stop_reason'] ?? '') === 'max_tokens') { return new WP_Error('long', 'A válasz túl hosszúra sikerült – próbáld újra rövidebb kéréssel.'); }
	$u = (array) get_option('cp_ai_usage', array());
	$u['n'] = (int) ($u['n'] ?? 0) + 1; $u['in'] = (int) ($u['in'] ?? 0) + (int) ($j['usage']['input_tokens'] ?? 0); $u['out'] = (int) ($u['out'] ?? 0) + (int) ($j['usage']['output_tokens'] ?? 0);
	$u['last'] = time();
	update_option('cp_ai_usage', $u, false);
	return $txt;
}

/* ------------------------------------------------------------ álnevesítés */
class CP_AI_Pseudo {
	public $map = array();   // jelölő => valódi érték
	private $rev = array();  // valódi érték (kisbetűs) => jelölő
	private $n = array();
	private $names = array();

	/* ismert nevek (kontakt, cég, kollégák) – teljes alakban és a vezeték-/keresztnevek külön is */
	public function add_name($name, $kind = 'NÉV') {
		$name = trim(preg_replace('/\s+/u', ' ', (string) $name));
		if (mb_strlen($name) < 3) { return; }
		$this->names[] = array($name, $kind);
		foreach (preg_split('/\s+/u', $name) as $part) { if (mb_strlen($part) >= 3 && preg_match('/^\p{Lu}/u', $part)) { $this->names[] = array($part, $kind); } }
	}
	private function tok($kind, $val) {
		$k = mb_strtolower($val);
		if (isset($this->rev[$k])) { return $this->rev[$k]; }
		$this->n[$kind] = ($this->n[$kind] ?? 0) + 1;
		$t = '[' . $kind . $this->n[$kind] . ']';
		$this->map[$t] = $val; $this->rev[$k] = $t;
		return $t;
	}
	public function hide($s) {
		$s = (string) $s;
		$s = preg_replace_callback('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', function ($m) { return $this->tok('EMAIL', $m[0]); }, $s);
		$s = preg_replace_callback('/\b\d{8}-\d{8}(?:-\d{8})?\b|\bHU\d{2}(?:\s?\d{4}){6}\b/u', function ($m) { return $this->tok('SZÁMLASZÁM', $m[0]); }, $s);
		$s = preg_replace_callback('/\b\d{8}-\d-\d{2}\b/u', function ($m) { return $this->tok('ADÓSZÁM', $m[0]); }, $s);
		$s = preg_replace_callback('/(?<![\d\w])(?:\+36|0036|06)[\s\/\-]?\(?\d{1,2}\)?[\s\/\-]?\d{3}[\s\-]?\d{3,4}(?![\d])/u', function ($m) { return $this->tok('TEL', $m[0]); }, $s);
		/* a hosszabb nevek előbb, hogy a „Kiss Anna” egyben cserélődjön */
		usort($this->names, function ($a, $b) { return mb_strlen($b[0]) - mb_strlen($a[0]); });
		foreach ($this->names as $x) {
			/* toldalékkal is (Annának, Kissnek): az -a / -e végű tő á / é alakja is, a toldalék a jelölő után marad */
			$q = preg_quote($x[0], '/');
			if (preg_match('/a$/u', $x[0])) { $q .= '|' . preg_quote(mb_substr($x[0], 0, -1) . 'á', '/'); }
			elseif (preg_match('/e$/u', $x[0])) { $q .= '|' . preg_quote(mb_substr($x[0], 0, -1) . 'é', '/'); }
			$s = preg_replace_callback('/(?<![\p{L}\[])(' . $q . ')(\p{Ll}{0,6})(?![\p{L}])/u', function ($m) use ($x) { return $this->tok($x[1], $m[1]) . $m[2]; }, $s);
		}
		return $s;
	}
	public function show($s) {
		if (!$this->map) { return (string) $s; }
		$keys = array_keys($this->map);
		usort($keys, function ($a, $b) { return strlen($b) - strlen($a); });
		$out = (string) $s;
		foreach ($keys as $k) { $out = str_replace($k, $this->map[$k], $out); }
		return $out;
	}
}

/* HTML → olvasható szöveg (a korábbi idézett részek nélkül) */
function cp_ai_text($html, $max = 4000) {
	$h = preg_replace('#<blockquote.*?</blockquote>#is', ' [korábbi idézett levél] ', (string) $html);
	$h = preg_replace('#<(br|/p|/div|/li|/h\d)\s*/?>#i', "\n", $h);
	$t = html_entity_decode(wp_strip_all_tags($h), ENT_QUOTES, 'UTF-8');
	$t = preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $t));
	/* a „... írta:” utáni idézett rész levágása */
	$t = preg_replace('/\n[^\n]{0,120}(írta|wrote)\s*:\s*\n.*$/su', "\n[korábbi idézett levél]", $t);
	$t = trim($t);
	return mb_strlen($t) > $max ? mb_substr($t, 0, $max) . ' …' : $t;
}

/* ------------------------------------------------------------ hasonló korábbi levélváltások (a postafiók-importból)
 * Az ügyfél legutóbbi levelére teljes szöveges kereséssel a leghasonlóbb korábbi ügyféllevelek, mindegyikhez a mi
 * rá adott válaszunkkal – legfeljebb 3 pár, más szálakból. */
function cp_ai_similar($tid, $text, $P) {
	global $wpdb;
	$M = cp_mail_t('messages'); $T = cp_mail_t('threads');
	$words = array_slice(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)), function ($w) { return mb_strlen($w) >= 4; })), 0, 60);
	if (count($words) < 3) { return array(); }
	$q = implode(' ', $words);
	$hits = (array) $wpdb->get_results($wpdb->prepare("SELECT m.id, m.thread_id, m.sent_at, m.body, MATCH(m.body) AGAINST(%s) sc FROM $M m JOIN $T t ON t.id = m.thread_id WHERE m.dir = 'in' AND t.id <> %d AND MATCH(m.body) AGAINST(%s) ORDER BY sc DESC LIMIT 15", $q, $tid, $q), ARRAY_A);
	if ($wpdb->last_error) { return array(); }
	$out = array(); $seen = array();
	foreach ($hits as $h) {
		if (isset($seen[$h['thread_id']]) || count($out) >= 3) { continue; }
		$rep = $wpdb->get_row($wpdb->prepare("SELECT body FROM $M WHERE thread_id = %d AND dir = 'out' AND status NOT IN ('idézetből', 'ellenőrizetlen') AND (imap_folder = '' OR imap_folder <> 'INBOX') AND sent_at >= %s ORDER BY sent_at, id LIMIT 1", $h['thread_id'], $h['sent_at']), ARRAY_A);
		if (!$rep) { continue; }
		$seen[$h['thread_id']] = 1;
		$cn = (string) $wpdb->get_var($wpdb->prepare("SELECT contact_name FROM $T WHERE id = %d", $h['thread_id']));
		if ($cn !== '') { $P->add_name($cn, 'NÉV'); }
		$out[] = "ÜGYFÉL ÍRTA:\n" . $P->hide(cp_ai_text($h['body'], 1500)) . "\nMI VÁLASZOLTUNK:\n" . $P->hide(cp_ai_text($rep['body'], 2500));
	}
	return $out;
}

/* ------------------------------------------------------------ válaszpiszkozat egy levélszálra */
function cp_ai_draft($tid, $wish = '') {
	global $wpdb;
	if (!cp_ai_ready()) { return new WP_Error('off', 'Az AI válaszvarázsló nincs bekapcsolva (Beállítások → Crazy AI).'); }
	$x = function_exists('cp_mail_thread_full') ? cp_mail_thread_full($tid) : null;
	if (!$x) { return new WP_Error('nothread', 'Nincs ilyen levélszál.'); }
	$t = $x['t'];
	$P = new CP_AI_Pseudo();
	$P->add_name($t['cn'], 'ÜGYFÉL');
	$vals = array();
	if ($t['deal'] && function_exists('cp_mail_values')) {
		$vals = cp_mail_values((int) $t['deal']);
		foreach (array('kontakt.nev' => 'ÜGYFÉL', 'ceg.nev' => 'CÉG', 'felelos.nev' => 'KOLLÉGA', 'sajat.nev' => 'KOLLÉGA') as $k => $kind) { if (!empty($vals[$k])) { $P->add_name($vals[$k], $kind); } }
	}
	foreach (cp_mail_people() as $p) { $P->add_name($p['n'], 'KOLLÉGA'); }
	foreach ((array) $x['msgs'] as $m) { if ($m['dir'] === 'in') { $P->add_name($m['from_name'], 'ÜGYFÉL'); } }
	$me = function_exists('cp_panel_who') ? cp_panel_who() : '';
	if ($me !== '') { $P->add_name($me, 'KOLLÉGA'); }

	/* a deal adatai (csak a kitöltöttek; a személyes adatok álnevesítve) */
	$deal = array();
	foreach ($vals as $k => $v) { if ($v !== '' && !preg_match('/link|url/i', $k)) { $deal[] = $k . ': ' . $P->hide($v); } }
	/* a levelek időrendben, a legutóbbi 12 */
	$msgs = array_slice((array) $x['msgs'], -12);
	$conv = array();
	foreach ($msgs as $m) {
		$conv[] = '--- ' . ($m['dir'] === 'in' ? 'ÜGYFÉL ÍRTA' : 'MI ÍRTUK') . ' (' . $m['sent_at'] . ')' . ($m['attachments'] ? ' [melléklet: ' . implode(', ', array_map(function ($a) { return $a['n'] ?? ''; }, $m['attachments'])) . ']' : '') . "\n" . $P->hide(cp_ai_text($m['body']));
	}
	$notes = array();
	foreach ((array) $x['notes'] as $n) { $notes[] = '- ' . $P->hide($n['body']); }
	$lastin = '';
	foreach (array_reverse($msgs) as $m) { if ($m['dir'] === 'in') { $lastin = cp_ai_text($m['body'], 3000); break; } }
	$similar = $lastin !== '' ? cp_ai_similar((int) $tid, $lastin, $P) : array();
	$o = cp_ai_opts();
	/* a sablonok: állandó rész, gyorsítótárazva (a sok levélnél olcsóbb) */
	$tpl = array();
	foreach ((array) $wpdb->get_results('SELECT name, category, subject, body FROM ' . cp_mail_t() . ' WHERE deleted_at IS NULL ORDER BY category, name LIMIT 120', ARRAY_A) as $r) {
		$tpl[] = '### ' . ($r['category'] ? $r['category'] . ' / ' : '') . $r['name'] . "\nTárgy: " . $r['subject'] . "\n" . cp_ai_text($r['body'], 2500);
	}
	$sys_rules = "Az EventCrazy csapatának tapasztalt ügyfélkapcsolati munkatársa vagy. Az EventCrazy rendezvényszolgáltató: fotóautomaták, fotófalak és hátterek, 360 spinner, szelfigép, AI-fotó, DJ, karaoke és egyéb rendezvényes szolgáltatások (márkái: EventCrazy, Crazyphoto, Studio Frames).\n" .
		"Feladat: írj válaszpiszkozatot az ügyfél legutóbbi levelére, magyarul, abban a hangnemben, ahogy a csapat szokott (a sablonok és a korábbi válaszaink stílusát kövesd: kedves, közvetlen, segítőkész, rövid bekezdések). A megszólítást és a tegezés / magázás formáját az ügyfél leveléhez és a korábbi váltáshoz igazítsd.\n" .
		"Szabályok:\n- Csak olyan tényt, árat, dátumot, szabad időpontot vagy feltételt írj, ami a levelekben, a jegyzetekben, a deal adataiban vagy a sablonokban szerepel. Ami hiányzik, azt jelöld így: [KITÖLTENDŐ: mit kell ide írni], és vedd fel a „missing” listába.\n- A [NÉV1], [ÜGYFÉL1], [KOLLÉGA1], [EMAIL1], [TEL1] stb. jelölők valódi adatokat takarnak: pontosan így, változatlanul használd őket, ne találj ki helyettük nevet vagy elérhetőséget.\n- Ha egy sablon illik a helyzethez, arra építs (a {{változó}} jelölőket a deal adataiból töltsd ki, ha megvannak, különben [KITÖLTENDŐ]).\n- Ne ígérj kedvezményt, ne fogadj el lemondást vagy visszatérítést, és ne vállalj kötelezettséget, ami nincs leírva – ilyenkor írd, hogy egyeztetünk, és jelezd a „notes”-ban.\n- A belső jegyzetek a csapat saját megjegyzései: felhasználhatod őket, de ne idézd szó szerint az ügyfélnek.\n- A választ egyszerű HTML-ben add: <p> bekezdések, szükség esetén <ul><li>, <b>. Aláírásként: „Üdvözlettel:<br>[ALÁÍRÓ]” (a nevet a panel írja be).\n" .
		($o['style'] !== '' ? "A csapat külön kérése a stílusra:\n" . $o['style'] . "\n" : '');
	$system = array(
		array('type' => 'text', 'text' => $sys_rules),
		array('type' => 'text', 'text' => "A csapat levélsablonjai (stílusminta és tartalom):\n\n" . ($tpl ? implode("\n\n", $tpl) : '(még nincsenek sablonok a panelben)'), 'cache_control' => array('type' => 'ephemeral')),
	);
	$user = "Levélszál tárgya: " . $P->hide($t['subj']) . "\nPostafiók: " . $t['box'] . "\n\n" .
		($deal ? "A kapcsolt deal adatai:\n" . implode("\n", $deal) . "\n\n" : "Ehhez a levélhez nincs deal kapcsolva.\n\n") .
		($notes ? "Belső jegyzetek (csak a csapat látja):\n" . implode("\n", $notes) . "\n\n" : '') .
		"A levelezés (legrégebbi elöl):\n" . implode("\n\n", $conv) . "\n\n" .
		($similar ? "Hasonló korábbi levélváltásaink (stílus- és tartalommintának – a mostani ügyfél adatai ettől eltérhetnek, árat és dátumot csak akkor vegyél át, ha a mostani helyzetre is igaz):\n\n" . implode("\n\n---\n\n", $similar) . "\n\n" : '') .
		($wish !== '' ? "A kolléga kérése ehhez a válaszhoz: " . $P->hide($wish) . "\n\n" : '') .
		'Írd meg a válaszpiszkozatot a megadott JSON formában.';
	$schema = array('type' => 'object', 'additionalProperties' => false, 'required' => array('subject', 'reply_html', 'notes', 'missing'), 'properties' => array(
		'subject' => array('type' => 'string', 'description' => 'A válasz tárgya (általában „Re: ” + az eredeti tárgy)'),
		'reply_html' => array('type' => 'string', 'description' => 'A válasz törzse egyszerű HTML-ben'),
		'notes' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Rövid megjegyzések a kollégának (mire figyeljen, mit érdemes ellenőrizni)'),
		'missing' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'A [KITÖLTENDŐ] helyek listája: mi hiányzik'),
	));
	$r = cp_ai_call($system, array(array('role' => 'user', 'content' => $user)), $schema);
	if (is_wp_error($r)) { return $r; }
	$j = json_decode($r, true);
	if (!is_array($j) || !isset($j['reply_html'])) { return new WP_Error('parse', 'Az AI válasza nem volt értelmezhető – próbáld újra.'); }
	$sign = $me !== '' ? $me : 'EventCrazy csapat';
	/* v1.63: az AI kimenete csak egyszerű szövegformázás lehet – kép, link, stílus nélkül. Egy bejövő levélbe rejtett
	 * utasítás így nem tud külső képet / linket csempészni a piszkozatba, amely betöltéskor ügyféladatot küldene ki.
	 * Szűrés a valódi adatok visszahelyezése előtt ÉS után is (a visszahelyezett adat sem lehet HTML). */
	$html = str_replace('[ALÁÍRÓ]', esc_html($sign), cp_ai_plain_html($P->show(cp_ai_plain_html((string) $j['reply_html']))));
	return array(
		'subject' => $P->show((string) $j['subject']),
		'html' => cp_ai_plain_html($html),
		'notes' => array_map(array($P, 'show'), array_map('strval', (array) $j['notes'])),
		'missing' => array_map(array($P, 'show'), array_map('strval', (array) $j['missing'])),
		'to' => $t['ce'],
		'hidden' => count($P->map),
		'similar' => count($similar),
	);
}

add_action('wp_ajax_nopriv_cp_ai_draft', 'cp_ai_ajax_draft');
add_action('wp_ajax_cp_ai_draft', 'cp_ai_ajax_draft');
function cp_ai_plain_html($h) {
	$ok = array('p' => array(), 'br' => array(), 'b' => array(), 'strong' => array(), 'i' => array(), 'em' => array(), 'u' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'blockquote' => array(), 'mark' => array());
	return wp_kses((string) $h, $ok, array());
}

function cp_ai_ajax_draft() {
	cp_el_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (function_exists('cp_panel_can_x') && !cp_panel_can_x('mail')) { cp_el_err('A Levelezés nincs engedélyezve a fiókodnak.', 403); }
	/* fiókonként percenként legfeljebb 6 piszkozat (költségvédelem) */
	$who = 'cp_ai_rl_' . md5((string) (function_exists('cp_pu_current_id') ? cp_pu_current_id() : 0) . '|' . (function_exists('cp_panel_role') ? cp_panel_role() : ''));
	$n = (int) get_transient($who);
	if ($n >= 6) { cp_el_err('Túl sok AI-kérés egymás után – várj egy percet.', 429); }
	set_transient($who, $n + 1, 60);
	$r = cp_ai_draft((int) ($_POST['thread'] ?? 0), trim(mb_substr(sanitize_textarea_field(wp_unslash($_POST['wish'] ?? '')), 0, 500)));
	if (is_wp_error($r)) { cp_el_err($r->get_error_message(), 400); }
	wp_send_json_success($r);
}

function cp_ai_cfg() { return array('on' => cp_ai_ready()); }

/* ------------------------------------------------------------ beállítások: Beállítások → Crazy AI */
add_action('admin_menu', function () {
	add_options_page('Crazy AI', 'Crazy AI', 'manage_options', 'cp-ai', 'cp_ai_admin_page');
});

function cp_ai_admin_page() {
	if (!current_user_can('manage_options')) { return; }
	$msg = '';
	if (isset($_POST['cp_ai_do'])) {
		check_admin_referer('cp_ai_do');
		$o = cp_ai_opts();
		$o['on'] = !empty($_POST['on']) ? 1 : 0;
		$m = sanitize_text_field(wp_unslash($_POST['model'] ?? ''));
		$o['model'] = isset(cp_ai_models()[$m]) ? $m : CP_AI_MODEL;
		$e = sanitize_key(wp_unslash($_POST['effort'] ?? 'medium'));
		$o['effort'] = in_array($e, array('low', 'medium', 'high'), true) ? $e : 'medium';
		$o['style'] = mb_substr(sanitize_textarea_field(wp_unslash($_POST['style'] ?? '')), 0, 2000);
		update_option('cp_ai_opts', $o, false);
		$k = trim((string) wp_unslash($_POST['key'] ?? ''));
		if (!empty($_POST['key_clear'])) { cp_st_secret_set('ai_key', ''); $msg .= 'API-kulcs törölve. '; }
		elseif ($k !== '') {
			if (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $k)) { $msg .= 'Ez nem Claude API-kulcsnak tűnik (sk-ant-… kezdetű), nem mentettem. '; }
			else { cp_st_secret_set('ai_key', $k); $msg .= 'API-kulcs elmentve. '; }
		}
		$msg .= 'Beállítások mentve.';
		if (!empty($_POST['test'])) {
			$r = cp_ai_call(array(array('type' => 'text', 'text' => 'Röviden, magyarul válaszolj.')), array(array('role' => 'user', 'content' => 'Írd le egy mondatban, hogy működik a kapcsolat.')), null, 400);
			$msg .= is_wp_error($r) ? ' Teszt: ' . $r->get_error_message() : ' Teszt sikeres – az AI válasza: „' . mb_substr(trim($r), 0, 200) . '”';
		}
	}
	$o = cp_ai_opts();
	$has = cp_ai_key() !== '';
	$u = (array) get_option('cp_ai_usage', array());
	echo '<div class="wrap"><h1>Crazy AI – válaszvarázsló</h1>';
	if ($msg !== '') { echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>'; }
	echo '<p>A Levelezésben a levélszálon az <b>✨ AI válasz</b> gomb válaszpiszkozatot ír a levélszál, a belső jegyzetek, a kapcsolt deal és a sablonok alapján. Küldés előtt <b>álnevesít</b>: nevek, e-mail-címek, telefonszámok, adó- és bankszámlaszámok helyett jelölők mennek ki, és a piszkozatba a panel írja vissza a valódi adatokat. A piszkozatot mindig a kolléga nézi át, magától semmit nem küld el.</p>';
	echo '<form method="post">' . wp_nonce_field('cp_ai_do', '_wpnonce', true, false) . '<input type="hidden" name="cp_ai_do" value="1"><table class="form-table" style="max-width:900px">';
	echo '<tr><th>Bekapcsolva</th><td><label><input type="checkbox" name="on" value="1"' . checked(!empty($o['on']), true, false) . '> Az AI válasz gomb megjelenik a Levelezésben</label></td></tr>';
	echo '<tr><th>Claude API-kulcs</th><td><input type="password" name="key" class="large-text" autocomplete="new-password" placeholder="' . esc_attr($has ? 'beállítva – csak cseréhez írd be az újat' : 'sk-ant-…') . '">' . ($has ? ' <label><input type="checkbox" name="key_clear" value="1"> törlés</label>' : '') . '<p class="description">console.anthropic.com → (céges szervezet) → Billing: egyenleg feltöltése → API keys → Create key. A kulcs titkosítva tárolódik, a böngészőbe nem kerül ki.</p></td></tr>';
	echo '<tr><th>Modell</th><td><select name="model">';
	foreach (cp_ai_models() as $k => $l) { echo '<option value="' . esc_attr($k) . '"' . selected($o['model'], $k, false) . '>' . esc_html($l) . '</option>'; }
	echo '</select></td></tr>';
	echo '<tr><th>Alaposság</th><td><select name="effort">';
	foreach (array('low' => 'Gyors', 'medium' => 'Normál (ajánlott)', 'high' => 'Alapos (lassabb, drágább)') as $k => $l) { echo '<option value="' . esc_attr($k) . '"' . selected($o['effort'], $k, false) . '>' . esc_html($l) . '</option>'; }
	echo '</select></td></tr>';
	echo '<tr><th>Stílus (nem kötelező)</th><td><textarea name="style" rows="4" class="large-text" placeholder="pl. Mindig tegeződünk, ha az ügyfél is tegez. Az árakat bruttóban írjuk. A levél végén ajánljuk fel a telefonos egyeztetést.">' . esc_textarea($o['style']) . '</textarea></td></tr>';
	echo '<tr><th>Használat</th><td>' . (int) ($u['n'] ?? 0) . ' kérés · ' . number_format((int) ($u['in'] ?? 0), 0, ',', ' ') . ' bemeneti és ' . number_format((int) ($u['out'] ?? 0), 0, ',', ' ') . ' kimeneti token' . (!empty($u['last']) ? ' · utoljára: ' . esc_html(wp_date('Y.m.d. H:i', (int) $u['last'])) : '') . '</td></tr>';
	echo '</table><p><button class="button button-primary">Mentés</button> <label style="margin-left:12px"><input type="checkbox" name="test" value="1"> mentés után teszt kérés</label></p></form></div>';
}
