<?php
/* =====================================================================
 * Feladatok (v1.35) – Trello-szerű kanban a panelben: csapattáblák (mindenki látja) és saját táblák (csak a tulajdonos).
 * Kártyák: felelősök, határidő, címkék, leírás, ellenőrzőlista, hozzászólások @ megemlítéssel;
 * kapcsolható egy deal-hez és egy levélszálhoz (levélből / deal-ből egy kattintással teendő).
 * A Salesmate-hez NEM nyúl: minden a panel saját tábláiban van.
 * ===================================================================== */

if (!defined('ABSPATH')) { exit; }

define('CP_KB_DB_VER', '1');

function cp_kb_t($name) {
	global $wpdb;
	return $wpdb->prefix . 'crazy_kb_' . $name;
}

add_action('init', function () {
	if (get_option('cp_kb_db_ver') === CP_KB_DB_VER || wp_doing_ajax()) { return; }
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$cs = $wpdb->get_charset_collate();
	dbDelta('CREATE TABLE ' . cp_kb_t('boards') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL DEFAULT '',
  kind varchar(10) NOT NULL DEFAULT 'team',
  owner_uid int(11) NULL,
  pos int(11) NOT NULL DEFAULT 0,
  created_by varchar(100) NOT NULL DEFAULT '',
  created_at datetime NULL,
  deleted_at datetime NULL,
  PRIMARY KEY  (id),
  KEY owner_uid (owner_uid)
) $cs;");
	dbDelta('CREATE TABLE ' . cp_kb_t('lists') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  board_id bigint(20) unsigned NOT NULL,
  name varchar(120) NOT NULL DEFAULT '',
  pos int(11) NOT NULL DEFAULT 0,
  deleted_at datetime NULL,
  PRIMARY KEY  (id),
  KEY board_id (board_id)
) $cs;");
	dbDelta('CREATE TABLE ' . cp_kb_t('cards') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  board_id bigint(20) unsigned NOT NULL,
  list_id bigint(20) unsigned NOT NULL,
  pos int(11) NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  descr text NULL,
  assignees varchar(255) NOT NULL DEFAULT '',
  labels varchar(120) NOT NULL DEFAULT '',
  due date NULL,
  done tinyint(1) NOT NULL DEFAULT 0,
  checklist text NULL,
  deal_id bigint(20) unsigned NULL,
  thread_id bigint(20) unsigned NULL,
  created_uid int(11) NULL,
  created_by varchar(100) NOT NULL DEFAULT '',
  created_at datetime NULL,
  updated_at datetime NULL,
  archived_at datetime NULL,
  PRIMARY KEY  (id),
  KEY board_id (board_id),
  KEY list_id (list_id),
  KEY deal_id (deal_id),
  KEY thread_id (thread_id)
) $cs;");
	dbDelta('CREATE TABLE ' . cp_kb_t('comments') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  card_id bigint(20) unsigned NOT NULL,
  body text NULL,
  author_uid int(11) NULL,
  author varchar(100) NOT NULL DEFAULT '',
  mentions varchar(255) NOT NULL DEFAULT '',
  created_at datetime NULL,
  deleted_at datetime NULL,
  PRIMARY KEY  (id),
  KEY card_id (card_id)
) $cs;");
	update_option('cp_kb_db_ver', CP_KB_DB_VER, false);
}, 7);

function cp_kb_db_ok() { return get_option('cp_kb_db_ver') === CP_KB_DB_VER; }
function cp_kb_me() { return function_exists('cp_pu_current_id') ? (int) cp_pu_current_id() : 0; }
function cp_kb_who() { return function_exists('cp_panel_who') ? cp_panel_who() : ''; }
function cp_kb_now() { return gmdate('Y-m-d H:i:s'); }
function cp_kb_local($dt) { return function_exists('cp_crm_local') ? cp_crm_local($dt) : (string) $dt; }

/* a kiosztható / megemlíthető kollégák: a panel aktív felhasználói */
function cp_kb_people() {
	$out = array();
	if (!function_exists('cp_pu_data')) { return $out; }
	foreach (cp_pu_data()['list'] as $id => $u) { if (!empty($u['a'])) { $out[] = array('id' => (int) $id, 'n' => (string) $u['n']); } }
	return $out;
}

/* címkék (Trello-színek) */
function cp_kb_labels() {
	return array(
		array('k' => 'red', 'n' => 'Sürgős'), array('k' => 'orange', 'n' => 'Fontos'), array('k' => 'yellow', 'n' => 'Várakozik'),
		array('k' => 'green', 'n' => 'Ügyfél'), array('k' => 'blue', 'n' => 'Belső'), array('k' => 'purple', 'n' => 'Ötlet'),
	);
}

function cp_kb_cfg() {
	if (!cp_kb_db_ok()) { return array('on' => false); }
	return array('on' => true, 'css' => plugins_url('assets/kanban.css', __FILE__) . '?v=' . CP_PANEL_VER, 'people' => cp_kb_people(), 'me' => cp_kb_me(), 'labels' => cp_kb_labels(),
		'admin' => function_exists('cp_panel_is_admin') && cp_panel_is_admin());
}

/* a látható táblák: minden csapattábla + a saját táblák. Első betöltéskor létrehozza az alap táblákat. */
function cp_kb_visible_boards() {
	global $wpdb;
	$B = cp_kb_t('boards');
	$me = cp_kb_me();
	if (!(int) $wpdb->get_var("SELECT COUNT(*) FROM $B WHERE kind = 'team'")) { cp_kb_new_board('Csapat', 'team', array('Teendő', 'Folyamatban', 'Ellenőrzésre vár', 'Kész')); }
	if ($me > 0 && !(int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $B WHERE kind = 'personal' AND owner_uid = %d", $me))) { cp_kb_new_board('Saját teendőim', 'personal', array('Teendő', 'Ma', 'Folyamatban', 'Kész')); }
	return (array) $wpdb->get_results($wpdb->prepare("SELECT id, name, kind, owner_uid FROM $B WHERE deleted_at IS NULL AND (kind = 'team' OR (kind = 'personal' AND owner_uid = %d AND %d > 0)) ORDER BY kind = 'personal', pos, id", $me, $me), ARRAY_A);
}

function cp_kb_new_board($name, $kind, $lists) {
	global $wpdb;
	$wpdb->insert(cp_kb_t('boards'), array('name' => $name, 'kind' => $kind, 'owner_uid' => $kind === 'personal' ? cp_kb_me() : null, 'created_by' => cp_kb_who(), 'created_at' => cp_kb_now()));
	$id = (int) $wpdb->insert_id;
	foreach (array_values($lists) as $i => $n) { $wpdb->insert(cp_kb_t('lists'), array('board_id' => $id, 'name' => $n, 'pos' => $i)); }
	return $id;
}

/* hozzáférés: csapattábla mindenkinek, saját tábla csak a tulajdonosnak */
function cp_kb_board_ok($id) {
	global $wpdb;
	$b = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_kb_t('boards') . ' WHERE id = %d AND deleted_at IS NULL', $id), ARRAY_A);
	if (!$b) { return null; }
	if ($b['kind'] === 'personal' && ((int) $b['owner_uid'] !== cp_kb_me() || cp_kb_me() <= 0)) { return null; }
	return $b;
}
function cp_kb_card_ok($id) {
	global $wpdb;
	$c = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . cp_kb_t('cards') . ' WHERE id = %d', $id), ARRAY_A);
	return $c && cp_kb_board_ok((int) $c['board_id']) ? $c : null;
}

function cp_kb_ids($csv) { return array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $csv))))); }

function cp_kb_card_row($c, $ncom = null) {
	$cl = json_decode((string) $c['checklist'], true);
	if (!is_array($cl)) { $cl = array(); }
	return array(
		'id' => (int) $c['id'], 'board' => (int) $c['board_id'], 'list' => (int) $c['list_id'], 'pos' => (int) $c['pos'], 'title' => $c['title'],
		'hasd' => trim((string) $c['descr']) !== '', 'as' => cp_kb_ids($c['assignees']), 'lb' => array_values(array_filter(explode(',', (string) $c['labels']))),
		'due' => (string) $c['due'], 'done' => (int) $c['done'], 'cl' => array(count(array_filter($cl, function ($x) { return !empty($x['d']); })), count($cl)),
		'deal' => $c['deal_id'] ? (int) $c['deal_id'] : 0, 'th' => $c['thread_id'] ? (int) $c['thread_id'] : 0, 'nc' => $ncom === null ? 0 : (int) $ncom,
		'arch' => $c['archived_at'] ? 1 : 0,
	);
}

/* a kártyákhoz tartozó hozzászólás-darabszámok */
function cp_kb_counts($ids) {
	global $wpdb;
	if (!$ids) { return array(); }
	$out = array();
	foreach ((array) $wpdb->get_results('SELECT card_id, COUNT(*) n FROM ' . cp_kb_t('comments') . ' WHERE deleted_at IS NULL AND card_id IN (' . implode(',', array_map('intval', $ids)) . ') GROUP BY card_id', ARRAY_A) as $r) { $out[(int) $r['card_id']] = (int) $r['n']; }
	return $out;
}

/* a kapcsolt deal / levélszál rövid adatai a kártyákhoz */
function cp_kb_link_info($cards) {
	global $wpdb;
	$deals = array(); $ths = array();
	foreach ($cards as $c) { if ($c['deal']) { $deals[$c['deal']] = 1; } if ($c['th']) { $ths[$c['th']] = 1; } }
	$out = array('deals' => array(), 'threads' => array());
	if ($deals && function_exists('cp_crm_t')) {
		foreach ((array) $wpdb->get_results('SELECT id, title, stage, event_d FROM ' . cp_crm_t('deals') . ' WHERE id IN (' . implode(',', array_map('intval', array_keys($deals))) . ')', ARRAY_A) as $d) { $out['deals'][(int) $d['id']] = array('t' => $d['title'], 'st' => $d['stage'], 'ev' => $d['event_d']); }
	}
	if ($ths && function_exists('cp_mail_t')) {
		foreach ((array) $wpdb->get_results('SELECT id, subject, contact_name FROM ' . cp_mail_t('threads') . ' WHERE id IN (' . implode(',', array_map('intval', array_keys($ths))) . ')', ARRAY_A) as $t) { $out['threads'][(int) $t['id']] = array('t' => $t['subject'], 'cn' => $t['contact_name']); }
	}
	return $out;
}

function cp_kb_cards_out($rows) {
	$ids = array_map(function ($c) { return (int) $c['id']; }, $rows);
	$cnt = cp_kb_counts($ids);
	$cards = array_map(function ($c) use ($cnt) { return cp_kb_card_row($c, $cnt[(int) $c['id']] ?? 0); }, $rows);
	return array('cards' => $cards, 'links' => cp_kb_link_info($cards));
}

function cp_kb_req() {
	cp_el_auth();
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cp_el_err('Hibás kérés.'); }
	if (!cp_kb_db_ok()) { cp_el_err('Az adatbázis még készül – próbáld újra egy perc múlva.', 503); }
}
function cp_kb_in($k, $max = 255) { return mb_substr(sanitize_text_field(wp_unslash($_POST[$k] ?? '')), 0, $max); }

/* ------------------------------------------------------------ táblák */
add_action('wp_ajax_nopriv_cp_kb_boards', 'cp_kb_ajax_boards');
add_action('wp_ajax_cp_kb_boards', 'cp_kb_ajax_boards');
function cp_kb_ajax_boards() {
	cp_kb_req();
	$out = array_map(function ($b) { return array('id' => (int) $b['id'], 'n' => $b['name'], 'k' => $b['kind']); }, cp_kb_visible_boards());
	wp_send_json_success(array('boards' => $out, 'mine' => cp_kb_mine_count()));
}

add_action('wp_ajax_nopriv_cp_kb_board', 'cp_kb_ajax_board');
add_action('wp_ajax_cp_kb_board', 'cp_kb_ajax_board');
function cp_kb_ajax_board() {
	cp_kb_req();
	global $wpdb;
	$b = cp_kb_board_ok((int) ($_POST['id'] ?? 0));
	if (!$b) { cp_el_err('Nincs ilyen tábla, vagy nincs hozzáférésed.', 404); }
	$lists = (array) $wpdb->get_results($wpdb->prepare('SELECT id, name, pos FROM ' . cp_kb_t('lists') . ' WHERE board_id = %d AND deleted_at IS NULL ORDER BY pos, id', $b['id']), ARRAY_A);
	$arch = !empty($_POST['arch']);
	$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . cp_kb_t('cards') . ' WHERE board_id = %d AND archived_at IS ' . ($arch ? 'NOT NULL' : 'NULL') . ' ORDER BY pos, id', $b['id']), ARRAY_A);
	$x = cp_kb_cards_out($rows);
	wp_send_json_success(array('board' => array('id' => (int) $b['id'], 'n' => $b['name'], 'k' => $b['kind']), 'lists' => array_map(function ($l) { return array('id' => (int) $l['id'], 'n' => $l['name']); }, $lists), 'cards' => $x['cards'], 'links' => $x['links']));
}

/* tábla létrehozása / átnevezése / törlése */
add_action('wp_ajax_nopriv_cp_kb_board_save', 'cp_kb_ajax_board_save');
add_action('wp_ajax_cp_kb_board_save', 'cp_kb_ajax_board_save');
function cp_kb_ajax_board_save() {
	cp_kb_req();
	global $wpdb;
	$id = (int) ($_POST['id'] ?? 0);
	$name = trim(cp_kb_in('name', 120));
	if ($id === 0) {
		if ($name === '') { cp_el_err('Adj nevet a táblának.'); }
		$kind = ($_POST['kind'] ?? '') === 'personal' && cp_kb_me() > 0 ? 'personal' : 'team';
		wp_send_json_success(array('id' => cp_kb_new_board($name, $kind, array('Teendő', 'Folyamatban', 'Kész'))));
	}
	$b = cp_kb_board_ok($id);
	if (!$b) { cp_el_err('Nincs ilyen tábla.', 404); }
	if (!empty($_POST['del'])) {
		if ($b['kind'] === 'team' && (int) $wpdb->get_var("SELECT COUNT(*) FROM " . cp_kb_t('boards') . " WHERE kind = 'team' AND deleted_at IS NULL") <= 1) { cp_el_err('Az utolsó csapattábla nem törölhető.'); }
		$wpdb->update(cp_kb_t('boards'), array('deleted_at' => cp_kb_now()), array('id' => $id));
		wp_send_json_success(array('id' => 0));
	}
	if ($name === '') { cp_el_err('Adj nevet a táblának.'); }
	$wpdb->update(cp_kb_t('boards'), array('name' => $name), array('id' => $id));
	wp_send_json_success(array('id' => $id));
}

/* ------------------------------------------------------------ listák (oszlopok): új, átnevezés, törlés, sorrend */
add_action('wp_ajax_nopriv_cp_kb_list_save', 'cp_kb_ajax_list_save');
add_action('wp_ajax_cp_kb_list_save', 'cp_kb_ajax_list_save');
function cp_kb_ajax_list_save() {
	cp_kb_req();
	global $wpdb;
	$L = cp_kb_t('lists');
	$b = cp_kb_board_ok((int) ($_POST['board'] ?? 0));
	if (!$b) { cp_el_err('Nincs ilyen tábla.', 404); }
	if (isset($_POST['order'])) {
		foreach (cp_kb_ids(wp_unslash($_POST['order'])) as $i => $lid) { $wpdb->update($L, array('pos' => $i), array('id' => $lid, 'board_id' => (int) $b['id'])); }
		wp_send_json_success(array('ok' => 1));
	}
	$id = (int) ($_POST['id'] ?? 0);
	$name = trim(cp_kb_in('name', 120));
	if ($id === 0) {
		if ($name === '') { cp_el_err('Adj nevet a listának.'); }
		$pos = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(pos), -1) + 1 FROM $L WHERE board_id = %d", $b['id']));
		$wpdb->insert($L, array('board_id' => (int) $b['id'], 'name' => $name, 'pos' => $pos));
		wp_send_json_success(array('id' => (int) $wpdb->insert_id));
	}
	$l = $wpdb->get_row($wpdb->prepare("SELECT * FROM $L WHERE id = %d AND board_id = %d AND deleted_at IS NULL", $id, $b['id']), ARRAY_A);
	if (!$l) { cp_el_err('Nincs ilyen lista.', 404); }
	if (!empty($_POST['del'])) {
		if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_kb_t('cards') . ' WHERE list_id = %d AND archived_at IS NULL', $id))) { cp_el_err('Csak üres lista törölhető – előbb helyezd át vagy archiváld a kártyáit.'); }
		$wpdb->update($L, array('deleted_at' => cp_kb_now()), array('id' => $id));
		wp_send_json_success(array('id' => 0));
	}
	if ($name === '') { cp_el_err('Adj nevet a listának.'); }
	$wpdb->update($L, array('name' => $name), array('id' => $id));
	wp_send_json_success(array('id' => $id));
}

/* ------------------------------------------------------------ kártyák */
function cp_kb_card_full($id) {
	global $wpdb;
	$c = cp_kb_card_ok($id);
	if (!$c) { return null; }
	$com = (array) $wpdb->get_results($wpdb->prepare('SELECT id, body, author, author_uid, created_at FROM ' . cp_kb_t('comments') . ' WHERE card_id = %d AND deleted_at IS NULL ORDER BY id DESC', $id), ARRAY_A);
	foreach ($com as &$m) { $m['id'] = (int) $m['id']; $m['author_uid'] = (int) $m['author_uid']; $m['created_at'] = cp_kb_local($m['created_at']); }
	unset($m);
	$row = cp_kb_card_row($c, count($com));
	$cl = json_decode((string) $c['checklist'], true);
	$row['descr'] = (string) $c['descr'];
	$row['checklist'] = is_array($cl) ? array_values($cl) : array();
	$row['by'] = $c['created_by'];
	$row['at'] = cp_kb_local($c['created_at']);
	$row['bn'] = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . cp_kb_t('boards') . ' WHERE id = %d', $c['board_id']));
	return array('card' => $row, 'comments' => $com, 'links' => cp_kb_link_info(array($row)));
}

add_action('wp_ajax_nopriv_cp_kb_card', 'cp_kb_ajax_card');
add_action('wp_ajax_cp_kb_card', 'cp_kb_ajax_card');
function cp_kb_ajax_card() {
	cp_kb_req();
	$x = cp_kb_card_full((int) ($_POST['id'] ?? 0));
	if (!$x) { cp_el_err('Nincs ilyen kártya, vagy nincs hozzáférésed.', 404); }
	wp_send_json_success($x);
}

/* új kártya vagy mezők mentése: title, descr, as, lb, due, done, checklist, deal, th, list, arch */
add_action('wp_ajax_nopriv_cp_kb_card_save', 'cp_kb_ajax_card_save');
add_action('wp_ajax_cp_kb_card_save', 'cp_kb_ajax_card_save');
function cp_kb_ajax_card_save() {
	cp_kb_req();
	global $wpdb;
	$C = cp_kb_t('cards');
	$id = (int) ($_POST['id'] ?? 0);
	$set = array();
	if (isset($_POST['title'])) { $set['title'] = trim(cp_kb_in('title', 255)); if ($set['title'] === '') { cp_el_err('A kártyának kell cím.'); } }
	if (isset($_POST['descr'])) { $set['descr'] = mb_substr(sanitize_textarea_field(wp_unslash($_POST['descr'])), 0, 8000); }
	if (isset($_POST['as'])) { $ok = array_column(cp_kb_people(), 'id'); $set['assignees'] = implode(',', array_values(array_intersect(cp_kb_ids(wp_unslash($_POST['as'])), $ok))); }
	if (isset($_POST['lb'])) { $ok = array_column(cp_kb_labels(), 'k'); $set['labels'] = implode(',', array_values(array_intersect(array_map('sanitize_key', explode(',', (string) wp_unslash($_POST['lb']))), $ok))); }
	if (isset($_POST['due'])) { $d = (string) wp_unslash($_POST['due']); $set['due'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null; }
	if (isset($_POST['done'])) { $set['done'] = !empty($_POST['done']) && $_POST['done'] !== '0' ? 1 : 0; }
	if (isset($_POST['checklist'])) {
		$cl = json_decode((string) wp_unslash($_POST['checklist']), true);
		$out = array();
		foreach (is_array($cl) ? array_slice($cl, 0, 60) : array() as $x) { $t = mb_substr(sanitize_text_field((string) ($x['t'] ?? '')), 0, 200); if ($t !== '') { $out[] = array('t' => $t, 'd' => empty($x['d']) ? 0 : 1); } }
		$set['checklist'] = wp_json_encode($out);
	}
	if (isset($_POST['deal'])) { $set['deal_id'] = ctype_digit((string) $_POST['deal']) && (int) $_POST['deal'] > 0 ? (int) $_POST['deal'] : null; }
	if (isset($_POST['th'])) { $set['thread_id'] = ctype_digit((string) $_POST['th']) && (int) $_POST['th'] > 0 ? (int) $_POST['th'] : null; }
	if (isset($_POST['arch'])) { $set['archived_at'] = !empty($_POST['arch']) && $_POST['arch'] !== '0' ? cp_kb_now() : null; }
	if ($id === 0) {
		$b = cp_kb_board_ok((int) ($_POST['board'] ?? 0));
		if (!$b) { cp_el_err('Nincs ilyen tábla.', 404); }
		$lid = (int) ($_POST['list'] ?? 0);
		if (!$lid) { $lid = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . cp_kb_t('lists') . ' WHERE board_id = %d AND deleted_at IS NULL ORDER BY pos, id LIMIT 1', $b['id'])); }
		if (!$lid || !(int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_kb_t('lists') . ' WHERE id = %d AND board_id = %d AND deleted_at IS NULL', $lid, $b['id']))) { cp_el_err('Nincs ilyen lista.'); }
		if (empty($set['title'])) { cp_el_err('A kártyának kell cím.'); }
		$top = !empty($_POST['top']);
		$pos = (int) $wpdb->get_var($wpdb->prepare('SELECT ' . ($top ? 'COALESCE(MIN(pos), 1) - 1' : 'COALESCE(MAX(pos), -1) + 1') . " FROM $C WHERE list_id = %d", $lid));
		$wpdb->insert($C, array_merge(array('checklist' => '[]', 'descr' => ''), $set, array('board_id' => (int) $b['id'], 'list_id' => $lid, 'pos' => $pos, 'created_uid' => cp_kb_me(), 'created_by' => cp_kb_who(), 'created_at' => cp_kb_now(), 'updated_at' => cp_kb_now())));
		$id = (int) $wpdb->insert_id;
	} else {
		$c = cp_kb_card_ok($id);
		if (!$c) { cp_el_err('Nincs ilyen kártya.', 404); }
		if (isset($_POST['list'])) {
			$lid = (int) $_POST['list'];
			if ($lid !== (int) $c['list_id'] && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_kb_t('lists') . ' WHERE id = %d AND board_id = %d AND deleted_at IS NULL', $lid, $c['board_id']))) {
				$set['list_id'] = $lid; $set['pos'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(pos), -1) + 1 FROM $C WHERE list_id = %d", $lid));
			}
		}
		if ($set) { $set['updated_at'] = cp_kb_now(); $wpdb->update($C, $set, array('id' => $id)); }
	}
	wp_send_json_success(cp_kb_card_full($id));
}

/* húzás: a kártya új listája és a cél lista teljes sorrendje */
add_action('wp_ajax_nopriv_cp_kb_card_move', 'cp_kb_ajax_card_move');
add_action('wp_ajax_cp_kb_card_move', 'cp_kb_ajax_card_move');
function cp_kb_ajax_card_move() {
	cp_kb_req();
	global $wpdb;
	$C = cp_kb_t('cards');
	$c = cp_kb_card_ok((int) ($_POST['id'] ?? 0));
	if (!$c) { cp_el_err('Nincs ilyen kártya.', 404); }
	$lid = (int) ($_POST['list'] ?? 0);
	if (!(int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . cp_kb_t('lists') . ' WHERE id = %d AND board_id = %d AND deleted_at IS NULL', $lid, $c['board_id']))) { cp_el_err('Nincs ilyen lista.'); }
	$wpdb->update($C, array('list_id' => $lid, 'updated_at' => cp_kb_now()), array('id' => (int) $c['id']));
	foreach (cp_kb_ids(wp_unslash($_POST['order'] ?? '')) as $i => $cid) { $wpdb->update($C, array('pos' => $i), array('id' => $cid, 'list_id' => $lid)); }
	wp_send_json_success(array('ok' => 1));
}

/* ------------------------------------------------------------ hozzászólások (@Név megemlítéssel) */
add_action('wp_ajax_nopriv_cp_kb_comment', 'cp_kb_ajax_comment');
add_action('wp_ajax_cp_kb_comment', 'cp_kb_ajax_comment');
function cp_kb_ajax_comment() {
	cp_kb_req();
	global $wpdb;
	$M = cp_kb_t('comments');
	if (!empty($_POST['del'])) {
		$m = $wpdb->get_row($wpdb->prepare("SELECT * FROM $M WHERE id = %d", (int) $_POST['del']), ARRAY_A);
		if (!$m || !cp_kb_card_ok((int) $m['card_id'])) { cp_el_err('Nincs ilyen hozzászólás.', 404); }
		if ((int) $m['author_uid'] !== cp_kb_me() && !(function_exists('cp_panel_is_admin') && cp_panel_is_admin())) { cp_el_err('Csak a saját hozzászólásodat törölheted.', 403); }
		$wpdb->update($M, array('deleted_at' => cp_kb_now()), array('id' => (int) $m['id']));
		wp_send_json_success(cp_kb_card_full((int) $m['card_id']));
	}
	$c = cp_kb_card_ok((int) ($_POST['card'] ?? 0));
	if (!$c) { cp_el_err('Nincs ilyen kártya.', 404); }
	$body = trim(mb_substr(sanitize_textarea_field(wp_unslash($_POST['body'] ?? '')), 0, 4000));
	if ($body === '') { cp_el_err('Üres hozzászólás.'); }
	$men = array();
	foreach (cp_kb_people() as $p) { if (mb_stripos($body, '@' . $p['n']) !== false) { $men[] = $p['id']; } }
	$wpdb->insert($M, array('card_id' => (int) $c['id'], 'body' => $body, 'author_uid' => cp_kb_me(), 'author' => cp_kb_who(), 'mentions' => implode(',', $men), 'created_at' => cp_kb_now()));
	$wpdb->update(cp_kb_t('cards'), array('updated_at' => cp_kb_now()), array('id' => (int) $c['id']));
	wp_send_json_success(cp_kb_card_full((int) $c['id']));
}

/* ------------------------------------------------------------ „Nekem”: rám osztott / engem megemlítő kártyák és levéljegyzetek */
function cp_kb_mine_rows($me) {
	global $wpdb;
	if ($me <= 0) { return array(); }
	$C = cp_kb_t('cards'); $B = cp_kb_t('boards');
	$sql = "SELECT c.* FROM $C c JOIN $B b ON b.id = c.board_id AND b.deleted_at IS NULL
		WHERE c.archived_at IS NULL AND c.done = 0 AND (b.kind = 'team' OR b.owner_uid = %d)
		AND (FIND_IN_SET(%d, c.assignees) OR c.id IN (SELECT card_id FROM " . cp_kb_t('comments') . " WHERE deleted_at IS NULL AND FIND_IN_SET(%d, mentions)))
		ORDER BY c.due IS NULL, c.due, c.updated_at DESC LIMIT 200";
	return (array) $wpdb->get_results($wpdb->prepare($sql, $me, $me, $me), ARRAY_A);
}
function cp_kb_mine_count() { return count(cp_kb_mine_rows(cp_kb_me())); }

add_action('wp_ajax_nopriv_cp_kb_mine', 'cp_kb_ajax_mine');
add_action('wp_ajax_cp_kb_mine', 'cp_kb_ajax_mine');
function cp_kb_ajax_mine() {
	cp_kb_req();
	global $wpdb;
	$me = cp_kb_me();
	$x = cp_kb_cards_out(cp_kb_mine_rows($me));
	$bn = array(); $ln = array();
	foreach ((array) $wpdb->get_results('SELECT id, name FROM ' . cp_kb_t('boards'), ARRAY_A) as $r) { $bn[(int) $r['id']] = $r['name']; }
	foreach ((array) $wpdb->get_results('SELECT id, name FROM ' . cp_kb_t('lists'), ARRAY_A) as $r) { $ln[(int) $r['id']] = $r['name']; }
	foreach ($x['cards'] as &$c) { $c['bn'] = $bn[$c['board']] ?? ''; $c['ln'] = $ln[$c['list']] ?? ''; }
	unset($c);
	/* levéljegyzetek, amikben megemlítettek (utolsó 30 nap) */
	$notes = array();
	if ($me > 0 && function_exists('cp_mail_db_ok') && cp_mail_db_ok()) {
		$rows = (array) $wpdb->get_results($wpdb->prepare('SELECT n.id, n.thread_id, n.body, n.author, n.created_at, t.subject FROM ' . cp_mail_t('notes') . ' n JOIN ' . cp_mail_t('threads') . ' t ON t.id = n.thread_id WHERE n.deleted_at IS NULL AND FIND_IN_SET(%d, n.mentions) AND n.created_at >= %s ORDER BY n.id DESC LIMIT 30', $me, gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)), ARRAY_A);
		foreach ($rows as $r) { $notes[] = array('id' => (int) $r['id'], 'th' => (int) $r['thread_id'], 'b' => mb_substr((string) $r['body'], 0, 200), 'by' => $r['author'], 'at' => cp_kb_local($r['created_at']), 'subj' => $r['subject']); }
	}
	wp_send_json_success(array('cards' => $x['cards'], 'links' => $x['links'], 'notes' => $notes));
}

/* ------------------------------------------------------------ egy deal-hez / levélszálhoz kapcsolt kártyák (a deal adatlapján és a levélszálon) */
add_action('wp_ajax_nopriv_cp_kb_linked', 'cp_kb_ajax_linked');
add_action('wp_ajax_cp_kb_linked', 'cp_kb_ajax_linked');
function cp_kb_ajax_linked() {
	cp_kb_req();
	global $wpdb;
	$me = cp_kb_me();
	$deal = (int) ($_POST['deal'] ?? 0); $th = (int) ($_POST['th'] ?? 0);
	$w = array();
	if ($deal > 0) { $w[] = $wpdb->prepare('c.deal_id = %d', $deal); }
	if ($th > 0) { $w[] = $wpdb->prepare('c.thread_id = %d', $th); }
	if (!$w) { wp_send_json_success(array('cards' => array())); }
	$C = cp_kb_t('cards'); $B = cp_kb_t('boards'); $L = cp_kb_t('lists');
	$rows = (array) $wpdb->get_results($wpdb->prepare("SELECT c.*, b.name bn, l.name ln FROM $C c JOIN $B b ON b.id = c.board_id AND b.deleted_at IS NULL LEFT JOIN $L l ON l.id = c.list_id WHERE c.archived_at IS NULL AND (b.kind = 'team' OR (b.owner_uid = %d AND %d > 0)) AND (" . implode(' OR ', $w) . ') ORDER BY c.done, c.id DESC LIMIT 30', $me, $me), ARRAY_A);
	wp_send_json_success(array('cards' => array_map(function ($c) { $r = cp_kb_card_row($c); $r['bn'] = $c['bn']; $r['ln'] = (string) $c['ln']; return $r; }, $rows)));
}

/* a gyors felvételhez: a látható táblák a listáikkal */
add_action('wp_ajax_nopriv_cp_kb_targets', 'cp_kb_ajax_targets');
add_action('wp_ajax_cp_kb_targets', 'cp_kb_ajax_targets');
function cp_kb_ajax_targets() {
	cp_kb_req();
	global $wpdb;
	$out = array();
	foreach (cp_kb_visible_boards() as $b) {
		$ls = (array) $wpdb->get_results($wpdb->prepare('SELECT id, name FROM ' . cp_kb_t('lists') . ' WHERE board_id = %d AND deleted_at IS NULL ORDER BY pos, id', $b['id']), ARRAY_A);
		$out[] = array('id' => (int) $b['id'], 'n' => $b['name'], 'k' => $b['kind'], 'lists' => array_map(function ($l) { return array('id' => (int) $l['id'], 'n' => $l['name']); }, $ls));
	}
	wp_send_json_success(array('boards' => $out));
}
