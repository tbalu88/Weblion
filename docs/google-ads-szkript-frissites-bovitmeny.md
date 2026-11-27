Te most a Google Ads-fiókjaimban egy meglévő Google Ads-szkriptet frissítesz egy új változatra. A válaszaid legyenek magyarul.

SZABÁLYOK
- Csak a lent leírt szkriptet módosítsd. Kampányhoz, hirdetéshez, kulcsszóhoz, költségkerethez, beállításhoz, javaslathoz NE nyúlj; felugró ajánlatot ne fogadj el.
- A titkos kulcsot (a régi kód `var KEY = '…';` sorában lévő értéket) SOHA ne írd ki a válaszodba, és sehová máshová ne másold, csak az új kód KEY sorába ugyanennél a szkriptnél.
- Ha valahol bejelentkezés vagy engedélyezés kell (Google-fiók választása, „Engedélyezés”, „Allow”), állj meg, és kérd meg, hogy én kattintsak; utána folytasd.
- Ha valamit nem találsz, vagy hibát látsz, írd le pontosan, és ne próbálkozz más módon.

FELADAT – két fiókban, egymás után: először az EventCrazy (ügyfélazonosító: 711-833-8454), utána a CrazyPhoto (817-580-6951)
1. Nyisd meg a Google Ads-ban az adott fiókot (fent a fiókválasztóban válts rá, ha kell).
2. Fent: Eszközök (csavarkulcs ikon) → Tömeges műveletek → Szkriptek.
3. A listában keresd meg a meglévő szkriptet, amelynek a kódja így kezdődik: „/* Crazy panel – Google Ads költség…”, és amely óránként fut. (Ha több van, azt, amelyik az eventcrazy.hu címre küld.) Nyisd meg szerkesztésre.
4. A régi kódban keresd meg a `var KEY = '…';` sort, és jegyezd meg az értékét (ne írd ki).
5. Jelöld ki a teljes régi kódot a szerkesztőben (Ctrl+A / Cmd+A), töröld, és illeszd be helyette az ALÁBBI ÚJ KÓDOT pontosan, karakterre.
6. Az új kódban a `var KEY = 'IDE_MÁSOLD_A_KULCSOT';` sorban cseréld az IDE_MÁSOLD_A_KULCSOT szöveget a 4. lépésben megjegyzett régi kulcsra (az idézőjelek maradjanak).
7. Ellenőrizd, hogy a KEY sorban már nem az IDE_MÁSOLD_A_KULCSOT szöveg áll, majd Mentés.
8. Kattints a Futtatás (Run) gombra (ha a felület „Előnézet” és „Futtatás” közül kérdez: Futtatás). Ha engedélyt kér, állj meg és szólj nekem.
9. Várd meg, amíg lefut, és nézd meg a naplót (Naplók / Logs). Az utolsó sor elején „200” kell legyen, utána egy JSON-szöveg, benne "ok":true. Ha „Kampánybeállítások:”, „Fiókbeállítások:” vagy „Változási előzmények:” kezdetű sort látsz, azt szó szerint másold ki a jelentésbe (a kulcsot ne).
10. Ellenőrizd, hogy a szkript ütemezése továbbra is „Óránként” – ha nem, állítsd óránkéntire.

A VÉGÉN írj rövid jelentést fiókonként: megtaláltad-e a szkriptet, sikerült-e a csere, mi volt a napló utolsó sora (a kulcs nélkül), és az esetleges hibasorok szó szerint.

ÚJ KÓD (innentől a „VÉGE” sorig, a jelöléseket nem beleértve):
-----KEZDETE-----
/* Crazy panel – Google Ads költség, kampánybeállítások és változási előzmények küldése (óránként futtasd) – 2. változat */
var URL = 'https://eventcrazy.hu/wp-json/crazy-panel/v1/ads';
var KEY = 'IDE_MÁSOLD_A_KULCSOT';
var DAYS = 7; // az ELSŐ kézi futtatásnál írd át 400-ra (előzmények), utána állítsd vissza 7-re

function main() {
  var tz = AdsApp.currentAccount().getTimeZone();
  var now = new Date();
  var to = Utilities.formatDate(now, tz, 'yyyy-MM-dd');
  var from = Utilities.formatDate(new Date(now.getTime() - DAYS * 864e5), tz, 'yyyy-MM-dd');
  var q = 'SELECT segments.date, campaign.id, campaign.name, metrics.cost_micros, metrics.clicks, ' +
          'metrics.impressions, metrics.conversions FROM campaign ' +
          "WHERE segments.date BETWEEN '" + from + "' AND '" + to + "'";
  var it = AdsApp.search(q);
  var rows = [];
  while (it.hasNext()) {
    var r = it.next();
    rows.push({ d: r.segments.date, id: String(r.campaign.id), name: r.campaign.name,
      spend: Number(r.metrics.costMicros || 0) / 1e6, clicks: Number(r.metrics.clicks || 0),
      impr: Number(r.metrics.impressions || 0), conv: Number(r.metrics.conversions || 0) });
  }
  /* 2. változat (2026-11-27): a kampányok beállításai, a fiók URL-beállításai és a változási előzmények is (csak olvas) */
  var camps = [], cust = null, changes = [];
  try {
    var ci = AdsApp.search('SELECT campaign.id, campaign.name, campaign.status, campaign.serving_status, campaign.primary_status, ' +
      'campaign.primary_status_reasons, campaign.advertising_channel_type, campaign.bidding_strategy_type, campaign_budget.amount_micros, ' +
      'campaign.network_settings.target_search_network, campaign.network_settings.target_partner_search_network, ' +
      'campaign.network_settings.target_content_network, campaign.geo_target_type_setting.positive_geo_target_type, ' +
      "campaign.final_url_suffix FROM campaign WHERE campaign.status != 'REMOVED'");
    while (ci.hasNext()) { var c = ci.next(), cc = c.campaign, ns = cc.networkSettings || {};
      camps.push({ id: String(cc.id), name: cc.name, status: cc.status, serving: cc.servingStatus, pstatus: cc.primaryStatus,
        preasons: cc.primaryStatusReasons || [], type: cc.advertisingChannelType, bidding: cc.biddingStrategyType,
        budget: c.campaignBudget && c.campaignBudget.amountMicros ? Number(c.campaignBudget.amountMicros) / 1e6 : null,
        search: !!ns.targetSearchNetwork, partners: !!ns.targetPartnerSearchNetwork, display: !!ns.targetContentNetwork,
        geo: cc.geoTargetTypeSetting ? cc.geoTargetTypeSetting.positiveGeoTargetType : '', suffix: cc.finalUrlSuffix || '' }); }
  } catch (e) { Logger.log('Kampánybeállítások: ' + e); }
  try {
    var cu = AdsApp.search('SELECT customer.final_url_suffix, customer.tracking_url_template, customer.auto_tagging_enabled FROM customer');
    if (cu.hasNext()) { var x = cu.next().customer; cust = { suffix: x.finalUrlSuffix || '', template: x.trackingUrlTemplate || '', autotag: !!x.autoTaggingEnabled }; }
  } catch (e) { Logger.log('Fiókbeállítások: ' + e); }
  try {
    var cf = Utilities.formatDate(new Date(now.getTime() - 14 * 864e5), tz, 'yyyy-MM-dd');
    var ct = Utilities.formatDate(now, tz, 'yyyy-MM-dd HH:mm:ss');
    var ch = AdsApp.search('SELECT change_event.change_date_time, change_event.user_email, change_event.client_type, ' +
      'change_event.change_resource_type, change_event.resource_change_operation, change_event.changed_fields, campaign.name, ad_group.name ' +
      "FROM change_event WHERE change_event.change_date_time >= '" + cf + "' AND change_event.change_date_time <= '" + ct + "' " +
      'ORDER BY change_event.change_date_time DESC LIMIT 1000');
    while (ch.hasNext()) { var r2 = ch.next(), ev = r2.changeEvent;
      changes.push({ t: ev.changeDateTime, who: ev.userEmail || '', client: ev.clientType, res: ev.changeResourceType, op: ev.resourceChangeOperation,
        fields: ev.changedFields && ev.changedFields.paths ? ev.changedFields.paths.join(',') : String(ev.changedFields || ''), camp: r2.campaign ? r2.campaign.name : '', group: r2.adGroup ? r2.adGroup.name : '' }); }
  } catch (e) { Logger.log('Változási előzmények: ' + e); }
  var res = UrlFetchApp.fetch(URL, { method: 'post', contentType: 'application/json',
    headers: { 'X-CP-Key': KEY }, muteHttpExceptions: true,
    payload: JSON.stringify({ acct: AdsApp.currentAccount().getCustomerId(), acctName: AdsApp.currentAccount().getName(), tz: tz,
      from: from, to: to, rows: rows, camps: camps, cust: cust, changes: changes }) });
  Logger.log(res.getResponseCode() + ' ' + res.getContentText());
  if (res.getResponseCode() !== 200) { throw new Error('A küldés nem sikerült: ' + res.getContentText()); }
}
-----VÉGE-----
