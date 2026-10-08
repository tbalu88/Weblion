<?php
/**
 * Ügyfélportál – 1. fázis (rendezvény-adatlap a portal.eventcrazy.hu címen)
 * Betöltő: a crazy-panel.php végén egyetlen require_once hozza be.
 *
 * Fájlok:
 *   portal-config.php     mezők (kötelező-e, validáció), határidők, megőrzési idők, beállítások
 *   portal-db.php         saját táblák (dbDelta, verziószámozott migráció), adatelérés
 *   portal-auth.php       belépőlinkek (token + hash), süti-munkamenet, IP-korlát
 *   portal-route.php      portal.eventcrazy.hu kiszolgálása: /r/{token}, /rendezvenyem
 *   portal-rest.php       REST: crazy/v1/portal/*, Salesmate-webhook
 *   portal-form.php       mentés, validálás, állapot, zárolás
 *   portal-salesmate.php  portál létrehozása dealből, visszaírás újrapróbálkozással
 *   portal-flags.php      crazy_portal_flags(), „Helyszíni infó” blokk
 *   portal-notify.php     e-mailek, Pumble, notifications napló
 *   portal-cron.php       15 perces értesítések, óránkénti tartalék, napi adattörlés
 *   portal-panel.php      panel-AJAX: lista-adatok, részletező, link-műveletek, irodai szerkesztés
 *   portal-admin.php      WP admin → Beállítások → Ügyfélportál
 *   portal-tpl-page.php   ügyféloldali sablon (csak megjelenítéskor töltődik be)
 *   portal-tpl-mail.php   e-mail-sablonok (csak küldéskor töltődik be)
 */

if (!defined('ABSPATH')) { exit; }

define('CP_PORTAL_VER', '1.0.0');
define('CP_PORTAL_DB_VER', '1');
define('CP_PORTAL_OPT', 'cp_portal_settings');
define('CP_PORTAL_MAIN', __DIR__ . '/crazy-panel.php');

foreach (array('config', 'db', 'auth', 'route', 'rest', 'form', 'salesmate', 'flags', 'notify', 'cron', 'panel', 'admin') as $cp_portal_f) {
	require_once __DIR__ . '/portal-' . $cp_portal_f . '.php';
}
unset($cp_portal_f);
