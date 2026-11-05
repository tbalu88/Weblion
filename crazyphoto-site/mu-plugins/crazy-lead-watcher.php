<?php
/**
 * Crazy panel – ajánlatkérés-figyelő a crazyphoto.hu MINDEN oldalán (v1.91).
 * A Salesmate űrlap sikeres beküldésekor (régi SM_FORM_SUBMITTED és új sm-form-event esemény) a megkeresést a Crazy panelbe is elküldi
 * (eventcrazy.hu → cp_lead_in). Kikapcsolás: a fájl törlése vagy átnevezése.
 *
 * v2.7: saját ajánlatkérő űrlap. Ha a Crazy panelben (Beállítások → Átállás) a „Crazyphoto űrlap → deal” élesítve van,
 * az oldalak kimenetében a Salesmate webforms.js címe a panel szkriptjére cserélődik (eventcrazy.hu/…/assets/webforms.js),
 * amely a panel saját űrlapját rajzolja ki ugyanazokkal a mezőkkel (az árkalkulátor is ugyanúgy tölti ki).
 * Az állapotot az eventcrazy.hu-tól kérdezi le (15 percenként, és azonnal, amikor a panel átkapcsoláskor szól: ?cp_wf_ping);
 * változáskor üríti az oldal gyorsítótárát. Ha az eventcrazy.hu nem válaszol, a legutóbbi állapot marad.
 */
if (!defined('ABSPATH')) { exit; }
add_action('wp_footer', function () {
	if (is_admin()) { return; }
	?>
<script>(function(){if(window.__cpLead)return;window.__cpLead=1;var u="https://eventcrazy.hu/wp-admin/admin-ajax.php",last=0;function send(id,nm,data,ev){if(Date.now()-last<15000)return;last=Date.now();try{var b=new FormData();b.append('action','cp_lead_in');b.append('form',id||'');b.append('fname',nm||'');b.append('data',JSON.stringify(data||{}));b.append('page',location.href.slice(0,250));b.append('ev',ev);if(navigator.sendBeacon&&navigator.sendBeacon(u,b))return;fetch(u,{method:'POST',body:b,keepalive:true,credentials:'omit'});}catch(x){}}window.addEventListener('SM_FORM_SUBMITTED',function(e){var d=e.detail||{};send(d.formId,d.formName,d.formData||{},'legacy');});window.addEventListener('sm-form-event:on-submission:success',function(e){var d=e.detail||{},f=null;try{(window.SalesmateFormsV1?SalesmateFormsV1.getForms():[]).forEach(function(x){if(x.getInstanceId()===d.instanceId)f=x;});}catch(x){}send(d.formId,f?f.getFormName():'',f?f.getFormFieldValues():[],'v1');});})();</script>
	<?php
}, 99);

/* ------------------------------------------------------------ v2.7: saját űrlap (a panel kapcsolójára) */
function cp_wf_cp_state($force = false) {
	$st = get_option('cp_wf_state', array());
	if (!is_array($st)) { $st = array(); }
	if (!$force && get_transient('cp_wf_state_chk')) { return $st; }
	set_transient('cp_wf_state_chk', 1, 15 * MINUTE_IN_SECONDS);
	$r = wp_remote_get('https://eventcrazy.hu/wp-admin/admin-ajax.php?action=cp_wf_state', array('timeout' => 8));
	if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) { return $st; }
	$n = json_decode(wp_remote_retrieve_body($r), true);
	if (!is_array($n) || !isset($n['cp'])) { return $st; }
	$new = array('cp' => (int) $n['cp'], 'ec' => (int) $n['ec'], 'shim' => preg_match('#^https://eventcrazy\.hu/wp-content/plugins/crazy-panel/assets/webforms\.js(\?[A-Za-z0-9.=&]*)?$#', (string) ($n['shim'] ?? '')) ? (string) $n['shim'] : '');
	if ($new != $st) {
		update_option('cp_wf_state', $new, false);
		/* gyorsítótár ürítése csak tényleges váltáskor (az első lekérdezés kikapcsolt állapotnál nem nyúl hozzá) */
		if (((int) ($st['cp'] ?? 0) !== $new['cp'] || ($new['cp'] && ($st['shim'] ?? '') !== $new['shim'])) && function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
	}
	return $new;
}
add_action('init', function () {
	if (!isset($_GET['cp_wf_ping'])) { return; }
	/* a ping csak újra-lekérdezést indít (az állapotot mindig az eventcrazy.hu adja), percenként legfeljebb egyszer */
	if (get_transient('cp_wf_ping_rl')) { wp_send_json(array('ok' => 1, 'skip' => 1)); }
	set_transient('cp_wf_ping_rl', 1, 60);
	$st = cp_wf_cp_state(true);
	wp_send_json(array('ok' => 1, 'cp' => (int) ($st['cp'] ?? 0)));
}, 1);
add_action('template_redirect', function () {
	if (is_admin() || wp_doing_ajax() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
	$st = cp_wf_cp_state();
	if (empty($st['cp']) || empty($st['shim'])) { return; }
	$shim = $st['shim'];
	ob_start(function ($html) use ($shim) {
		if (strpos($html, 'studioframes.salesmate.io/webforms.js') === false) { return $html; }
		return preg_replace('#https?://studioframes\.salesmate\.io/webforms\.js#', $shim, $html);
	});
}, 1000);
