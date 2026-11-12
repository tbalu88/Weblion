<?php
/**
 * Crazy panel – „csak hónap” rendezvénydátum (v2.9.222).
 * Ha az ügyfél csak a hónapot tudja (pl. „2027 március”), a deal a Salesmate-ben a hónap 1. napjával jön létre (ott a dátum kötelező),
 * a panel viszont megjegyzi, hogy ez csak hónap: a saját táblájában a dátum ÜRES marad (így minden dátumfüggő funkció – automatizmusok,
 * Google-naptár, beosztás, Követés, portál-határidő – kihagyja), és mindenhol „2027. március – pontos nap még nincs” látszik.
 * Amint pontos dátum kerül a dealre (a panelben vagy a Salesmate-ben), a jelölés magától megszűnik.
 * Tárolás: cp_deal_month opció – deal_id => 'YYYY-MM'. Döntés: 2026-11-12 (A változat).
 */
if (!defined('ABSPATH')) { exit; }

function cp_dm_all() { $m = get_option('cp_deal_month', array()); return is_array($m) ? $m : array(); }
function cp_dm_get($id) { $m = cp_dm_all(); $v = (string) ($m[(int) $id] ?? ''); return preg_match('/^\d{4}-\d{2}$/', $v) ? $v : ''; }
function cp_dm_ok($ym) { return (bool) preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', (string) $ym); }
function cp_dm_set($id, $ym) { if (!cp_dm_ok($ym) || !(int) $id) { return; } $m = cp_dm_all(); $m[(int) $id] = $ym; update_option('cp_deal_month', $m, false); }
function cp_dm_clear($id) { $m = cp_dm_all(); if (isset($m[(int) $id])) { unset($m[(int) $id]); update_option('cp_deal_month', $m, false); } }
/* a szinkron / visszaolvasás: a „csak hónap” dealnél a panel-táblában a dátum üres; ha közben más (valódi) dátum került rá, a jelölés megszűnik */
function cp_dm_row(&$row) {
	$id = (int) ($row['id'] ?? 0); $ym = $id ? cp_dm_get($id) : '';
	if ($ym === '') { return; }
	$d = (string) ($row['event_d'] ?? '');
	if ($d === '' || $d === $ym . '-01') { $row['event_d'] = null; } else { cp_dm_clear($id); }
}
/* „2027. március” */
function cp_dm_label($ym) {
	if (!cp_dm_ok($ym)) { return ''; }
	$mn = array('január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
	return substr($ym, 0, 4) . '. ' . $mn[(int) substr($ym, 5, 2) - 1];
}
/* az adatlapra */
add_filter('cp_crm_deal_data', function ($x) { if (!empty($x['deal']['id'])) { $ym = cp_dm_get($x['deal']['id']); if ($ym !== '') { $x['deal']['ym'] = $ym; } } return $x; }, 23);
