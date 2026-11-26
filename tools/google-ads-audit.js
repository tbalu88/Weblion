/**
 * EventCrazy / CrazyPhoto – Google Ads fiók- és kampányellenőrző szkript
 * ------------------------------------------------------------------------
 * CSAK OLVAS: semmit nem módosít a fiókban. Egy új Google-táblázatba írja:
 *   - Összefoglaló: figyelmeztetések súlyosság szerint (HIBA / FIGYELEM / JAVASLAT) és a teendő
 *   - Fiók, Konverziók, Kampányok, Célzás, Kulcsszavak, Keresőkifejezések, PMax-kifejezések,
 *     Hirdetések, Bővítmények, Eszközcsoportok, Közönségek, Google-javaslatok, Változások
 *
 * Használat (mindkét fiókban külön): Eszközök → Tömeges műveletek → Szkriptek → + → bemásolás →
 * Engedélyezés → Futtatás (nem kell ütemezni; havonta egyszer érdemes lefuttatni).
 * A táblázat linkje a naplóban (Napló / Logs) jelenik meg, és a táblázat a futtató Google-fiók Drive-jába kerül.
 *
 * Beállítások lent: NAPOK (vizsgált időszak), LIMIT_PAZARLAS (Ft – ennyi költés felett 0 konverzió már pazarlásnak számít).
 */

var NAPOK = 30;                 // vizsgált időszak (nap): 7, 14, 30 vagy 90 javasolt
var LIMIT_PAZARLAS = 5000;      // Ft: kulcsszó / keresőkifejezés ennyi költés felett konverzió nélkül → figyelmeztetés
var SAJAT_MARKAK = ['eventcrazy', 'event crazy', 'crazyphoto', 'crazy photo', 'crazy foto', 'crazyfoto'];

/* A szegmensünkre jellemző, nem vásárlói szándékú keresések (kizáró kulcsszó-jelöltek).
 * Egyszavas minta: a kifejezés bármely szava ezzel KEZDŐDIK; többszavas: a kifejezés tartalmazza. */
var KIZARO_MINTAK = [
  // munka, állás
  'állás', 'állások', 'munka', 'munkát', 'munkalehetőség', 'diákmunka', 'fizetés', 'bér ', 'karrier', 'jelentkezés',
  // ingyenes, házilag, vásárlás (nem bérlés)
  'ingyen', 'házilag', 'diy', 'eladó', 'eladás', 'vásárl', 'vesz ', 'vétel', 'használt', 'akció', 'webshop', 'árukereső',
  'aliexpress', 'temu', 'emag', 'alza', 'mediamarkt', 'euronics', 'jófogás', 'jofogas', 'marketplace',
  // nem a mi „fotóautomatánk”: igazolványkép-automata
  'igazolványkép', 'igazolvány', 'útlevél', 'útlevélkép', 'személyi', 'jogosítvány', 'okmány',
  // nem a mi „szelfi” termékünk: szelfibot, állvány, telefonos kiegészítők
  'szelfibot', 'selfie stick', 'selfiestick', 'tripod', 'állvány', 'telefontartó', 'ring light', 'gyűrűs lámpa', 'gimbal',
  // fotós cikkek, instant gépek
  'instax', 'polaroid', 'fényképezőgép', 'objektív', 'fotópapír', 'tintapatron',
  // szoftver, alkalmazás, oktatás, javítás
  'letöltés', 'download', 'applikáció', 'szoftver', 'software', 'dslrbooth', 'tanfolyam', 'képzés', 'javítás', 'szerviz',
  'alkatrész', 'használati', 'útmutató', 'wikipedia', 'jelentése', 'fordítás'
];

/* ----------------------------------------------------------------------------- belső */
var F = [];       // figyelmeztetések: [súlyosság, terület, elem, megállapítás, teendő]
var HIBAK = [];   // lekérdezési hibák (pl. egy mező nem érhető el a fióktípusban)
var SEV = { HIBA: 1, FIGYELEM: 2, JAVASLAT: 3, RENDBEN: 4 };

function add(sev, terulet, elem, msg, teendo) { F.push([sev, terulet, elem || '', msg, teendo || '']); }
function q(query) {
  var out = [];
  try { var it = AdsApp.search(query); while (it.hasNext()) { out.push(it.next()); } }
  catch (e) { HIBAK.push(String(e.message || e).slice(0, 300) + '  ⟵  ' + query.replace(/\s+/g, ' ').slice(0, 140)); return null; }
  return out;
}
function q2(a, b) { var r = q(a); return r === null ? (q(b) || []) : r; }
function g(o, path) { var p = path.split('.'); for (var i = 0; i < p.length; i++) { if (o === null || o === undefined) { return undefined; } o = o[p[i]]; } return o; }
function ft(micros) { return Math.round(Number(micros || 0) / 1e6); }
function n(x) { return Number(x || 0); }
function pct(x) { return x === undefined || x === null || x === '' ? '' : Math.round(Number(x) * 1000) / 10 + '%'; }
function during() { return NAPOK <= 7 ? 'LAST_7_DAYS' : NAPOK <= 14 ? 'LAST_14_DAYS' : NAPOK <= 30 ? 'LAST_30_DAYS' : null; }
function dateCond() {
  var d = during(); if (d) { return "segments.date DURING " + d; }
  var tz = AdsApp.currentAccount().getTimeZone(), now = new Date();
  var to = Utilities.formatDate(now, tz, 'yyyy-MM-dd'), from = Utilities.formatDate(new Date(now.getTime() - NAPOK * 864e5), tz, 'yyyy-MM-dd');
  return "segments.date BETWEEN '" + from + "' AND '" + to + "'";
}
function own(term) { term = String(term || '').toLowerCase(); for (var i = 0; i < SAJAT_MARKAK.length; i++) { if (term.indexOf(SAJAT_MARKAK[i]) >= 0) { return true; } } return false; }
function negHit(term) {
  var t = ' ' + String(term || '').toLowerCase() + ' ', words = t.trim().split(/\s+/);
  for (var i = 0; i < KIZARO_MINTAK.length; i++) {
    var m = KIZARO_MINTAK[i];
    if (m.indexOf(' ') >= 0) { if (t.indexOf(m) >= 0 || t.indexOf(' ' + m.trim() + ' ') >= 0) { return m.trim(); } continue; }
    for (var j = 0; j < words.length; j++) { if (words[j].indexOf(m) === 0) { return m; } }
  }
  return '';
}

/* ----------------------------------------------------------------------------- fő program */
function main() {
  var acct = AdsApp.currentAccount();
  var nev = acct.getName() + ' (' + acct.getCustomerId() + ')';
  var DC = dateCond();
  var T = {};   // táblák: név → { fej, sorok }

  /* ---------- 1) Fiók */
  var cu = q2("SELECT customer.descriptive_name, customer.currency_code, customer.time_zone, customer.auto_tagging_enabled, customer.optimization_score, customer.final_url_suffix, customer.tracking_url_template, customer.conversion_tracking_setting.conversion_tracking_status, customer.conversion_tracking_setting.enhanced_conversions_for_leads_enabled, customer.conversion_tracking_setting.accepted_customer_data_terms FROM customer",
              "SELECT customer.descriptive_name, customer.currency_code, customer.time_zone, customer.auto_tagging_enabled, customer.optimization_score FROM customer");
  var c = cu.length ? cu[0].customer : {};
  var accSuffix = String(c.finalUrlSuffix || '');
  T['Fiók'] = { fej: ['Beállítás', 'Érték'], sorok: [
    ['Fiók', nev], ['Pénznem', c.currencyCode], ['Időzóna', c.timeZone],
    ['Automatikus címkézés (GCLID)', c.autoTaggingEnabled ? 'BE' : 'KI'],
    ['Optimalizálási pontszám', c.optimizationScore !== undefined ? Math.round(c.optimizationScore * 100) + '%' : ''],
    ['Fiókszintű végső URL-utótag', accSuffix || '(nincs)'],
    ['Fiókszintű követési sablon', c.trackingUrlTemplate || '(nincs)'],
    ['Konverziókövetés állapota', g(c, 'conversionTrackingSetting.conversionTrackingStatus') || ''],
    ['Bővített konverziók leadekhez', g(c, 'conversionTrackingSetting.enhancedConversionsForLeadsEnabled') ? 'BE' : 'KI / nem ismert'],
    ['Ügyféladat-feltételek elfogadva', g(c, 'conversionTrackingSetting.acceptedCustomerDataTerms') ? 'igen' : 'nem / nem ismert'],
    ['Vizsgált időszak', 'utolsó ' + NAPOK + ' nap']
  ] };
  if (c.autoTaggingEnabled === false) { add('HIBA', 'Fiók', '', 'Az automatikus címkézés (GCLID) ki van kapcsolva.', 'Kapcsold be (Fiókbeállítások → Automatikus címkézés). Enélkül nincs pontos konverziómérés és offline konverzió-import.'); }
  if (!g(c, 'conversionTrackingSetting.enhancedConversionsForLeadsEnabled')) { add('JAVASLAT', 'Konverziók', '', 'A bővített konverziók leadekhez (Enhanced Conversions for Leads) nincs bekapcsolva.', 'Kapcsold be (Célok → Beállítások), és az űrlap küldje a hashelt e-mailt/telefont. Ez az alapja az aláírt dealek visszatöltésének (offline konverzió) GCLID nélkül is.'); }

  /* ---------- 2) Konverziók */
  var ca = q2("SELECT conversion_action.name, conversion_action.category, conversion_action.type, conversion_action.status, conversion_action.primary_for_goal, conversion_action.counting_type, conversion_action.include_in_conversions_metric, conversion_action.click_through_lookback_window_days, conversion_action.value_settings.default_value, conversion_action.value_settings.always_use_default_value, conversion_action.attribution_model_settings.attribution_model FROM conversion_action WHERE conversion_action.status = 'ENABLED'",
              "SELECT conversion_action.name, conversion_action.category, conversion_action.type, conversion_action.status, conversion_action.primary_for_goal, conversion_action.counting_type FROM conversion_action WHERE conversion_action.status = 'ENABLED'");
  var perAct = {};
  (q("SELECT segments.conversion_action_name, metrics.conversions, metrics.all_conversions FROM campaign WHERE " + DC) || []).forEach(function (r) {
    var k = r.segments.conversionActionName; perAct[k] = perAct[k] || [0, 0]; perAct[k][0] += n(r.metrics.conversions); perAct[k][1] += n(r.metrics.allConversions);
  });
  var gyenge = ['PAGE_VIEW', 'ENGAGEMENT', 'OUTBOUND_CLICK', 'GET_DIRECTIONS', 'DOWNLOAD', 'DEFAULT', 'ADD_TO_CART', 'BEGIN_CHECKOUT'];
  var primaryCnt = 0, hasOffline = false, rows = [];
  ca.forEach(function (r) {
    var a = r.conversionAction, cat = a.category, prim = !!a.primaryForGoal, pa = perAct[a.name] || [0, 0];
    if (prim) { primaryCnt++; }
    if (/IMPORT|UPLOAD|SALESFORCE|CRM/.test(String(a.type)) || /QUALIFIED_LEAD|CONVERTED_LEAD|IMPORTED_LEAD/.test(String(cat))) { hasOffline = true; }
    rows.push([a.name, cat, a.type, prim ? 'ELSŐDLEGES' : 'másodlagos', a.countingType, a.clickThroughLookbackWindowDays || '', g(a, 'valueSettings.defaultValue') || 0, g(a, 'attributionModelSettings.attributionModel') || '', Math.round(pa[0] * 10) / 10, Math.round(pa[1] * 10) / 10]);
    if (prim && gyenge.indexOf(cat) >= 0) { add('HIBA', 'Konverziók', a.name, 'Elsődleges konverzió, de nem ajánlatkérés („' + cat + '”). Az automatikus licitálás erre is optimalizál, ezért a jelentett konverziószám a valós megkeresések többszöröse lehet.', 'Állítsd MÁSODLAGOS-ra. Elsődleges csak: űrlap elküldése / köszönőoldal, legalább 60 mp-es hívás, importált (minősített / megnyert) lead.'); }
    if (prim && /LEAD|CONTACT|REQUEST_QUOTE|SUBMIT_LEAD_FORM|BOOK_APPOINTMENT|SIGNUP|PHONE_CALL_LEAD/.test(String(cat)) && a.countingType === 'MANY_PER_CLICK') { add('FIGYELEM', 'Konverziók', a.name, 'Lead típusú konverzió „Minden” számlálással: egy látogató többször is számít (pl. újratöltött köszönőoldal).', 'Számlálás: „Egy” (ONE_PER_CLICK).'); }
    if (prim && /PHONE_CALL/.test(String(cat) + String(a.type))) { add('JAVASLAT', 'Konverziók', a.name, 'Hívás-konverzió elsődlegesként.', 'Ellenőrizd, hogy csak a legalább 60 másodperces hívás számítson (a rövid, téves hívások torzítanak).'); }
    if (prim && pa[1] > 0 && pa[0] === 0 && a.includeInConversionsMetric === false) { add('JAVASLAT', 'Konverziók', a.name, 'Elsődleges, de nem számít bele a „Konverziók” oszlopba.', 'Ellenőrizd a célbeállításokat.'); }
  });
  T['Konverziók'] = { fej: ['Konverziós művelet', 'Kategória', 'Típus', 'Szerep', 'Számlálás', 'Kattintási ablak (nap)', 'Alapérték', 'Hozzárendelés', 'Konverziók (' + NAPOK + ' nap)', 'Összes konv.'], sorok: rows };
  if (!primaryCnt) { add('HIBA', 'Konverziók', '', 'Nincs aktív elsődleges konverziós művelet.', 'Állíts be egy elsődleges „Ajánlatkérés” konverziót (köszönőoldal vagy űrlapküldés esemény).'); }
  if (!hasOffline) { add('FIGYELEM', 'Konverziók', '', 'Nincs importált (offline) konverzió: a Google nem tudja, melyik kattintásból lett aláírt megrendelés.', 'Offline konverzió-import a Salesmate / Crazy panel adataiból (minősített lead + megnyert deal értékkel). Ez a legnagyobb hatású javítás a licitálás minőségére.'); }

  /* ---------- 3) Kampányok */
  var camps = q2("SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign.advertising_channel_sub_type, campaign.bidding_strategy_type, campaign.target_cpa.target_cpa_micros, campaign.maximize_conversions.target_cpa_micros, campaign.maximize_conversion_value.target_roas, campaign_budget.amount_micros, campaign_budget.explicitly_shared, campaign.network_settings.target_google_search, campaign.network_settings.target_search_network, campaign.network_settings.target_content_network, campaign.network_settings.target_partner_search_network, campaign.geo_target_type_setting.positive_geo_target_type, campaign.url_expansion_opt_out, campaign.final_url_suffix, campaign.tracking_url_template, campaign.primary_status, campaign.primary_status_reasons FROM campaign WHERE campaign.status != 'REMOVED'",
                 "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign.bidding_strategy_type, campaign_budget.amount_micros, campaign.network_settings.target_content_network, campaign.network_settings.target_partner_search_network, campaign.geo_target_type_setting.positive_geo_target_type, campaign.final_url_suffix FROM campaign WHERE campaign.status != 'REMOVED'");
  var met = {};
  (q2("SELECT campaign.id, metrics.cost_micros, metrics.clicks, metrics.impressions, metrics.conversions, metrics.search_impression_share, metrics.search_budget_lost_impression_share, metrics.search_rank_lost_impression_share FROM campaign WHERE campaign.status != 'REMOVED' AND " + DC,
      "SELECT campaign.id, metrics.cost_micros, metrics.clicks, metrics.impressions, metrics.conversions FROM campaign WHERE campaign.status != 'REMOVED' AND " + DC)).forEach(function (r) {
    var id = String(r.campaign.id), m = met[id] = met[id] || { cost: 0, cl: 0, im: 0, cv: 0, is: null, bl: null, rl: null };
    m.cost += n(r.metrics.costMicros) / 1e6; m.cl += n(r.metrics.clicks); m.im += n(r.metrics.impressions); m.cv += n(r.metrics.conversions);
    if (r.metrics.searchImpressionShare !== undefined) { m.is = r.metrics.searchImpressionShare; m.bl = r.metrics.searchBudgetLostImpressionShare; m.rl = r.metrics.searchRankLostImpressionShare; }
  });
  var totCost = 0, totConv = 0; Object.keys(met).forEach(function (k) { totCost += met[k].cost; totConv += met[k].cv; });
  var acctCpa = totConv ? totCost / totConv : 0;
  var CAMP = {}; rows = [];
  camps.forEach(function (r) {
    var cp = r.campaign, id = String(cp.id), m = met[id] || { cost: 0, cl: 0, im: 0, cv: 0 }, ch = cp.advertisingChannelType, on = cp.status === 'ENABLED';
    CAMP[id] = { name: cp.name, type: ch, on: on };
    var suffix = String(cp.finalUrlSuffix || accSuffix || '');
    var bid = cp.biddingStrategyType, tcpa = ft(g(cp, 'targetCpa.targetCpaMicros') || g(cp, 'maximizeConversions.targetCpaMicros'));
    rows.push([cp.name, cp.status, ch + (cp.advertisingChannelSubType && cp.advertisingChannelSubType !== 'UNSPECIFIED' ? ' / ' + cp.advertisingChannelSubType : ''), bid, tcpa || '', ft(g(r, 'campaignBudget.amountMicros')),
      g(cp, 'networkSettings.targetContentNetwork') ? 'IGEN' : 'nem', g(cp, 'networkSettings.targetPartnerSearchNetwork') ? 'IGEN' : 'nem', g(cp, 'geoTargetTypeSetting.positiveGeoTargetType') || '',
      ch === 'PERFORMANCE_MAX' ? (cp.urlExpansionOptOut ? 'KI' : 'BE') : '', suffix || '(nincs)', cp.primaryStatus || '', (cp.primaryStatusReasons || []).join(', '),
      Math.round(m.cost), m.cl, m.im, Math.round(m.cv * 10) / 10, m.cv ? Math.round(m.cost / m.cv) : '', m.im ? pct(m.cl / m.im) : '', m.cl ? Math.round(m.cost / m.cl) : '', pct(m.is), pct(m.bl), pct(m.rl)]);
    if (!on) { return; }
    var cn = cp.name;
    if (ch === 'SEARCH' && g(cp, 'networkSettings.targetContentNetwork')) { add('FIGYELEM', 'Kampány', cn, 'Keresőkampány „Display-bővítéssel”: a pénz egy része képes hirdetésekre megy, gyenge szándékú forgalommal.', 'Kampánybeállítások → Hálózatok: a Display-hálózat kikapcsolása.'); }
    if (ch === 'SEARCH' && g(cp, 'networkSettings.targetPartnerSearchNetwork')) { add('JAVASLAT', 'Kampány', cn, 'A Google keresőpartnerei is be vannak kapcsolva.', 'Nézd meg a „Hálózat” szegmensben, hoz-e megkeresést; ha nem, kapcsold ki.'); }
    var gt = g(cp, 'geoTargetTypeSetting.positiveGeoTargetType');
    if (gt && gt !== 'PRESENCE') { add('FIGYELEM', 'Célzás', cn, 'Helycélzás: „jelenlét VAGY érdeklődés” – külföldről, Budapest iránt érdeklődőknek is megjelenik.', 'Helyek → Beállítások: „Jelenlét: a célzott helyeken tartózkodó vagy rendszeresen ott lévő személyek”.'); }
    if (!/utm_/i.test(suffix) && (ch === 'SEARCH' || ch === 'PERFORMANCE_MAX')) { add('HIBA', 'Mérés', cn, 'Nincs UTM a végső URL-utótagban: a Salesmate-ben a megkeresés nem köthető a kampányhoz.', 'Végső URL-utótag (fiók- vagy kampányszinten): utm_source=google&utm_medium=cpc&utm_campaign={_campaign}  (és a kampánynál a {_campaign} egyéni paraméter), vagy utm_campaign={campaignid}.'); }
    if (/MANUAL_CPC|TARGET_SPEND|MAXIMIZE_CLICKS/.test(bid) && m.cv >= 15) { add('JAVASLAT', 'Licitálás', cn, 'Kattintásra optimalizál, pedig van elég konverzió (' + Math.round(m.cv) + ' / ' + NAPOK + ' nap).', 'Konverziók maximalizálása (cél-CPA nélkül 2–4 hétig), ha a konverziómérés már csak valós ajánlatkérést számol.'); }
    if (/MAXIMIZE_CONVERSIONS|TARGET_CPA|MAXIMIZE_CONVERSION_VALUE|TARGET_ROAS/.test(bid) && m.cv < 10 * NAPOK / 30 && m.cost > 0) { add('FIGYELEM', 'Licitálás', cn, 'Konverziós licitálás kevés konverzióval (' + Math.round(m.cv * 10) / 10 + ' / ' + NAPOK + ' nap): a rendszer nem tud jól tanulni.', 'Vagy vonj össze kampányokat (több adat egy helyen), vagy használj „Kattintások maximalizálása” + max. CPC korlátot, amíg nincs havi ~30 valós konverzió.'); }
    if (tcpa && acctCpa && tcpa < acctCpa * 0.5) { add('FIGYELEM', 'Licitálás', cn, 'A cél-CPA (' + tcpa + ' Ft) sokkal alacsonyabb a tényleges átlagnál (' + Math.round(acctCpa) + ' Ft): a kampány visszafogja a megjelenéseket.', 'Állítsd a cél-CPA-t a tényleges 30 napos átlag közelébe, majd 2 hetente 10–15%-kal szigoríts.'); }
    if (m.bl !== null && m.bl > 0.2) { add('FIGYELEM', 'Költségkeret', cn, 'A megjelenések ' + pct(m.bl) + '-a elveszik a költségkeret miatt.', 'Ha a kampány megtérül (Salesmate szerint), emelj keretet; ha nem, előbb a kulcsszavakat / kizárásokat javítsd.'); }
    if (m.rl !== null && m.rl > 0.4) { add('FIGYELEM', 'Hirdetésrang', cn, 'A megjelenések ' + pct(m.rl) + '-a elveszik a hirdetésrang miatt (minőség / licit).', 'Hirdetésszövegek és céloldal javítása (minőségi mutató), túl alacsony licitkorlát / cél-CPA felülvizsgálata.'); }
    if (ch === 'PERFORMANCE_MAX' && !cp.urlExpansionOptOut) { add('FIGYELEM', 'PMax', cn, 'A végső URL-bővítés be van kapcsolva: a Google bármelyik aloldalra (blog, karrier, köszönőoldal) küldhet forgalmat.', 'Kapcsold ki, vagy zárd ki a nem értékesítési oldalakat (blog, karrier, adatvédelem, köszönőoldalak).'); }
    if (ch === 'PERFORMANCE_MAX') { add('JAVASLAT', 'PMax', cn, 'Ellenőrizd kézzel: márkakizárás (EventCrazy / CrazyPhoto), csatornajelentés (keresés / YouTube / Display arány), kizáró kulcsszavak.', 'A PMax ne a saját márkakeresésekből „termeljen” konverziót: márkakizárás + külön márkakampány. A kizáró kulcsszólistát (lent) a PMax-hez is rendeld hozzá.'); }
    if (ch === 'DISPLAY' && m.cost > 0 && m.cv === 0) { add('FIGYELEM', 'Kampány', cn, 'Display-kampány konverzió nélkül.', 'Rendezvényes bérlésnél a Display ritkán hoz leadet; legfeljebb remarketingre használd.'); }
    (cp.primaryStatusReasons || []).forEach(function (rs) { if (/BUDGET|POLICY|DISAPPROVED|NOT_ELIGIBLE|NO_ADS|LEARNING|MISCONFIGURED/.test(rs)) { add(/POLICY|DISAPPROVED|NOT_ELIGIBLE|NO_ADS|MISCONFIGURED/.test(rs) ? 'HIBA' : 'FIGYELEM', 'Kampányállapot', cn, 'Google állapotjelzés: ' + rs, 'Nézd meg a kampány állapot-buborékját a felületen.'); } });
  });
  T['Kampányok'] = { fej: ['Kampány', 'Állapot', 'Típus', 'Licitstratégia', 'Cél-CPA (Ft)', 'Napi keret (Ft)', 'Display-háló', 'Keresőpartnerek', 'Helycélzás módja', 'PMax URL-bővítés', 'URL-utótag', 'Google-állapot', 'Állapot okai', 'Költés', 'Katt.', 'Megj.', 'Konv.', 'Ft/konv.', 'CTR', 'CPC', 'Megj. arány', 'Elv. (keret)', 'Elv. (rang)'], sorok: rows };

  /* ---------- 4) Célzás: hely, nyelv, ütemezés, eszköz */
  var crit = q("SELECT campaign.id, campaign_criterion.type, campaign_criterion.negative, campaign_criterion.location.geo_target_constant, campaign_criterion.proximity.radius, campaign_criterion.proximity.radius_units, campaign_criterion.proximity.address.city_name, campaign_criterion.language.language_constant, campaign_criterion.ad_schedule.day_of_week, campaign_criterion.ad_schedule.start_hour, campaign_criterion.ad_schedule.end_hour, campaign_criterion.device.type, campaign_criterion.bid_modifier FROM campaign_criterion WHERE campaign.status = 'ENABLED' AND campaign_criterion.type IN ('LOCATION', 'PROXIMITY', 'LANGUAGE', 'AD_SCHEDULE', 'DEVICE')") || [];
  var geoIds = {}; crit.forEach(function (r) { var gc = g(r, 'campaignCriterion.location.geoTargetConstant'); if (gc) { geoIds[gc] = 1; } });
  var geoName = {}, gl = Object.keys(geoIds);
  if (gl.length) { (q("SELECT geo_target_constant.resource_name, geo_target_constant.canonical_name FROM geo_target_constant WHERE geo_target_constant.resource_name IN ('" + gl.join("','") + "')") || []).forEach(function (r) { geoName[r.geoTargetConstant.resourceName] = r.geoTargetConstant.canonicalName; }); }
  var LANG = { 'languageConstants/1024': 'magyar', 'languageConstants/1000': 'angol', 'languageConstants/1001': 'német' };
  var hasLoc = {}, hasLang = {}; rows = [];
  crit.forEach(function (r) {
    var id = String(r.campaign.id), cc = r.campaignCriterion, t = cc.type, val = '';
    if (!CAMP[id]) { return; }
    if (t === 'LOCATION') { val = geoName[g(cc, 'location.geoTargetConstant')] || g(cc, 'location.geoTargetConstant'); if (!cc.negative) { hasLoc[id] = 1; } }
    else if (t === 'PROXIMITY') { val = (g(cc, 'proximity.address.cityName') || '') + ' ' + g(cc, 'proximity.radius') + ' ' + g(cc, 'proximity.radiusUnits'); if (!cc.negative) { hasLoc[id] = 1; } }
    else if (t === 'LANGUAGE') { val = LANG[g(cc, 'language.languageConstant')] || g(cc, 'language.languageConstant'); hasLang[id] = 1; }
    else if (t === 'AD_SCHEDULE') { val = g(cc, 'adSchedule.dayOfWeek') + ' ' + g(cc, 'adSchedule.startHour') + '–' + g(cc, 'adSchedule.endHour'); }
    else if (t === 'DEVICE') { val = g(cc, 'device.type'); }
    rows.push([CAMP[id].name, t, cc.negative ? 'kizárás' : 'célzás', val, cc.bidModifier !== undefined && cc.bidModifier !== null ? cc.bidModifier : '']);
    if (t === 'LOCATION' && !cc.negative && val && !/Hungary|Magyarország/i.test(val)) { add('JAVASLAT', 'Célzás', CAMP[id].name, 'Magyarországon kívüli célzott hely: ' + val, 'Szándékos? A szolgáltatás Magyarországon érhető el.'); }
  });
  Object.keys(CAMP).forEach(function (id) {
    var cp = CAMP[id]; if (!cp.on) { return; }
    if (!hasLoc[id] && cp.type !== 'SHOPPING') { add('HIBA', 'Célzás', cp.name, 'Nincs helycélzás: a kampány az egész világon megjelenhet.', 'Célzás: Magyarország (jelenlét).'); }
    if (!hasLang[id] && cp.type === 'SEARCH') { add('JAVASLAT', 'Célzás', cp.name, 'Nincs nyelvi célzás (minden nyelv).', 'Magyar + angol (a külföldi cégek és expatok miatt).'); }
  });
  T['Célzás'] = { fej: ['Kampány', 'Típus', 'Célzás / kizárás', 'Érték', 'Licitmódosító'], sorok: rows };

  /* ---------- 5) Kulcsszavak */
  var kw = q("SELECT campaign.name, campaign.bidding_strategy_type, ad_group.name, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.quality_info.quality_score, ad_group_criterion.quality_info.creative_quality_score, ad_group_criterion.quality_info.post_click_quality_score, ad_group_criterion.quality_info.search_predicted_ctr, metrics.cost_micros, metrics.clicks, metrics.impressions, metrics.conversions FROM keyword_view WHERE campaign.status = 'ENABLED' AND ad_group.status = 'ENABLED' AND ad_group_criterion.status = 'ENABLED' AND " + DC) || [];
  var K = {}; kw.forEach(function (r) {
    var k = r.campaign.name + '|' + r.adGroup.name + '|' + r.adGroupCriterion.keyword.text + '|' + r.adGroupCriterion.keyword.matchType;
    var o = K[k] = K[k] || { r: r, cost: 0, cl: 0, im: 0, cv: 0 }; o.cost += n(r.metrics.costMicros) / 1e6; o.cl += n(r.metrics.clicks); o.im += n(r.metrics.impressions); o.cv += n(r.metrics.conversions);
  });
  rows = []; var lowQs = 0, broadNoSmart = 0, wasteKw = 0;
  Object.keys(K).forEach(function (k) {
    var o = K[k], r = o.r, qi = r.adGroupCriterion.qualityInfo || {}, mt = r.adGroupCriterion.keyword.matchType, bid = r.campaign.biddingStrategyType;
    rows.push([r.campaign.name, r.adGroup.name, r.adGroupCriterion.keyword.text, mt, qi.qualityScore || '', qi.searchPredictedCtr || '', qi.creativeQualityScore || '', qi.postClickQualityScore || '', Math.round(o.cost), o.cl, o.im, Math.round(o.cv * 10) / 10, o.cv ? Math.round(o.cost / o.cv) : '']);
    if (qi.qualityScore && qi.qualityScore <= 4 && o.im > 50) { lowQs++; }
    if (mt === 'BROAD' && /MANUAL_CPC|TARGET_SPEND|MAXIMIZE_CLICKS/.test(bid)) { broadNoSmart++; }
    if (o.cost >= Math.max(LIMIT_PAZARLAS, acctCpa * 2) && o.cv === 0) { wasteKw++; add('FIGYELEM', 'Kulcsszó', r.adGroupCriterion.keyword.text + ' [' + mt + ']', Math.round(o.cost) + ' Ft költés ' + NAPOK + ' nap alatt, konverzió nélkül (' + r.campaign.name + ').', 'Nézd meg a hozzá tartozó keresőkifejezéseket; szűkítsd az egyezést vagy szüneteltesd.'); }
  });
  rows.sort(function (a, b) { return b[8] - a[8]; });
  T['Kulcsszavak'] = { fej: ['Kampány', 'Hirdetéscsoport', 'Kulcsszó', 'Egyezés', 'Minőségi mutató', 'Várható CTR', 'Hirdetés relevanciája', 'Céloldal', 'Költés', 'Katt.', 'Megj.', 'Konv.', 'Ft/konv.'], sorok: rows };
  if (lowQs) { add('FIGYELEM', 'Kulcsszó', '', lowQs + ' aktív kulcsszó minőségi mutatója 4 vagy kevesebb.', 'A „Kulcsszavak” lapon a gyenge összetevő (CTR / relevancia / céloldal) mutatja, mit kell javítani: a kulcsszó legyen a címsorban, és a céloldal az adott szolgáltatásé legyen.'); }
  if (broadNoSmart) { add('FIGYELEM', 'Kulcsszó', '', broadNoSmart + ' általános egyezésű kulcsszó kattintásalapú licitálással.', 'Általános egyezés csak konverziós licitálással és tiszta konverziómérés mellett működik; addig kifejezés / pontos egyezés.'); }

  /* ---------- 6) Keresőkifejezések */
  var st = q("SELECT campaign.name, ad_group.name, search_term_view.search_term, search_term_view.status, metrics.cost_micros, metrics.clicks, metrics.impressions, metrics.conversions FROM search_term_view WHERE " + DC + " ORDER BY metrics.cost_micros DESC LIMIT 1000") || [];
  var S = {}; st.forEach(function (r) {
    var k = r.searchTermView.searchTerm; var o = S[k] = S[k] || { camp: r.campaign.name, st: r.searchTermView.status, cost: 0, cl: 0, im: 0, cv: 0 };
    o.cost += n(r.metrics.costMicros) / 1e6; o.cl += n(r.metrics.clicks); o.im += n(r.metrics.impressions); o.cv += n(r.metrics.conversions);
  });
  rows = []; var negList = {}, negCost = 0, brandCost = 0, brandCv = 0, stCost = 0;
  Object.keys(S).forEach(function (k) {
    var o = S[k], hit = negHit(k), br = own(k); stCost += o.cost;
    if (br) { brandCost += o.cost; brandCv += o.cv; }
    if (hit && o.st !== 'EXCLUDED') { negList[hit] = (negList[hit] || 0) + o.cost; negCost += o.cost; }
    rows.push([k, o.camp, o.st, br ? 'saját márka' : '', hit ? 'kizárandó? („' + hit + '”)' : (o.cost >= LIMIT_PAZARLAS && o.cv === 0 ? 'drága, konverzió nélkül' : ''), Math.round(o.cost), o.cl, o.im, Math.round(o.cv * 10) / 10]);
  });
  rows.sort(function (a, b) { return b[5] - a[5]; });
  T['Keresőkifejezések'] = { fej: ['Keresőkifejezés', 'Kampány', 'Állapot', 'Márka', 'Jelzés', 'Költés', 'Katt.', 'Megj.', 'Konv.'], sorok: rows };
  if (negCost > 0) { add('FIGYELEM', 'Kizáró kulcsszavak', '', Math.round(negCost) + ' Ft ment el nem vásárlói szándékú keresésekre (pl. ' + Object.keys(negList).slice(0, 8).join(', ') + ').', 'Vedd fel a „Keresőkifejezések” lapon „kizárandó?” jelzésű kifejezéseket egy közös kizáró listára, és rendeld minden kereső- és PMax-kampányhoz.'); }
  if (stCost && brandCost / stCost > 0.25) { add('JAVASLAT', 'Márka', '', 'A keresési költés ' + Math.round(brandCost / stCost * 100) + '%-a saját márkás keresés (' + Math.round(brandCost) + ' Ft, ' + Math.round(brandCv) + ' konv.).', 'A márkás keresések külön, olcsó márkakampányba valók; a többi kampányból (és a PMax-ből márkakizárással) zárd ki őket, különben a konverziók „szépítik” a nem márkás kampányokat.'); }

  /* ---------- 7) PMax keresési kategóriák */
  rows = [];
  Object.keys(CAMP).forEach(function (id) {
    if (CAMP[id].type !== 'PERFORMANCE_MAX' || !CAMP[id].on) { return; }
    (q("SELECT campaign_search_term_insight.category_label, metrics.clicks, metrics.impressions, metrics.conversions FROM campaign_search_term_insight WHERE campaign_search_term_insight.campaign_id = '" + id + "' AND " + DC) || []).forEach(function (r) {
      var lab = g(r, 'campaignSearchTermInsight.categoryLabel') || '(egyéb)';
      rows.push([CAMP[id].name, lab, negHit(lab) ? 'kizárandó?' : (own(lab) ? 'saját márka' : ''), n(r.metrics.clicks), n(r.metrics.impressions), Math.round(n(r.metrics.conversions) * 10) / 10]);
    });
  });
  rows.sort(function (a, b) { return b[3] - a[3]; });
  T['PMax-kifejezések'] = { fej: ['Kampány', 'Keresési kategória', 'Jelzés', 'Katt.', 'Megj.', 'Konv.'], sorok: rows };

  /* ---------- 8) Hirdetések */
  var ads = q("SELECT campaign.name, campaign.advertising_channel_type, ad_group.id, ad_group.name, ad_group_ad.ad.id, ad_group_ad.ad.type, ad_group_ad.ad_strength, ad_group_ad.status, ad_group_ad.policy_summary.approval_status, ad_group_ad.ad.final_urls, ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions FROM ad_group_ad WHERE ad_group_ad.status != 'REMOVED' AND campaign.status = 'ENABLED' AND ad_group.status = 'ENABLED'") || [];
  var agRsa = {}; rows = [];
  ads.forEach(function (r) {
    var a = r.adGroupAd, ad = a.ad || {}, rsa = ad.responsiveSearchAd || {}, h = (rsa.headlines || []).length, d = (rsa.descriptions || []).length, ap = g(a, 'policySummary.approvalStatus');
    var pinned = (rsa.headlines || []).filter(function (x) { return x.pinnedField && x.pinnedField !== 'UNSPECIFIED'; }).length;
    if (ad.type === 'RESPONSIVE_SEARCH_AD' && a.status === 'ENABLED') { agRsa[r.adGroup.id] = (agRsa[r.adGroup.id] || 0) + 1; }
    rows.push([r.campaign.name, r.adGroup.name, ad.type, a.status, a.adStrength || '', ap || '', h || '', d || '', pinned || '', (ad.finalUrls || []).join(' ')]);
    if (a.status === 'ENABLED' && /DISAPPROVED/.test(String(ap))) { add('HIBA', 'Hirdetés', r.campaign.name + ' / ' + r.adGroup.name, 'Elutasított hirdetés.', 'Nézd meg az irányelv-indoklást, és javítsd a szöveget / céloldalt.'); }
    if (a.status === 'ENABLED' && ad.type === 'RESPONSIVE_SEARCH_AD' && /POOR|AVERAGE/.test(String(a.adStrength))) { add('JAVASLAT', 'Hirdetés', r.campaign.name + ' / ' + r.adGroup.name, 'RSA hirdetéserősség: ' + a.adStrength + ' (' + h + ' címsor, ' + d + ' leírás).', '15 címsor / 4 leírás, a fő kulcsszó 2–3 címsorban, egyedi érvek (pl. azonnali nyomtatás, egyedi fotósablon, országos kiszállítás – csak ami igaz), legfeljebb 1–2 rögzített címsor.'); }
    if (a.status === 'ENABLED' && pinned >= 3) { add('JAVASLAT', 'Hirdetés', r.campaign.name + ' / ' + r.adGroup.name, pinned + ' rögzített címsor: a Google nem tud kombinálni.', 'Csak a márkát / fő ajánlatot rögzítsd, a többit engedd szabadon.'); }
  });
  var agList = q("SELECT campaign.name, ad_group.id, ad_group.name FROM ad_group WHERE ad_group.status = 'ENABLED' AND campaign.status = 'ENABLED' AND campaign.advertising_channel_type = 'SEARCH'") || [];
  agList.forEach(function (r) { if (!agRsa[r.adGroup.id]) { add('HIBA', 'Hirdetés', r.campaign.name + ' / ' + r.adGroup.name, 'Aktív hirdetéscsoport aktív reszponzív keresési hirdetés nélkül.', 'Legalább 1 (inkább 2) RSA hirdetéscsoportonként.'); } });
  T['Hirdetések'] = { fej: ['Kampány', 'Hirdetéscsoport', 'Típus', 'Állapot', 'Erősség', 'Jóváhagyás', 'Címsorok', 'Leírások', 'Rögzített', 'Végső URL'], sorok: rows };

  /* ---------- 9) Bővítmények (asset) */
  var AS = {}; rows = [];
  (q("SELECT campaign.id, campaign.name, asset.type, asset.name, campaign_asset.field_type, campaign_asset.status FROM campaign_asset WHERE campaign_asset.status != 'REMOVED' AND campaign.status = 'ENABLED'") || []).forEach(function (r) {
    var id = String(r.campaign.id), f = r.campaignAsset.fieldType; AS[id] = AS[id] || {}; AS[id][f] = (AS[id][f] || 0) + 1;
    rows.push(['kampány: ' + r.campaign.name, f, r.asset.type, r.asset.name || '', r.campaignAsset.status]);
  });
  var accAs = {};
  (q("SELECT asset.type, asset.name, customer_asset.field_type, customer_asset.status FROM customer_asset WHERE customer_asset.status != 'REMOVED'") || []).forEach(function (r) {
    var f = r.customerAsset.fieldType; accAs[f] = (accAs[f] || 0) + 1; rows.push(['fiók', f, r.asset.type, r.asset.name || '', r.customerAsset.status]);
  });
  T['Bővítmények'] = { fej: ['Szint', 'Mező', 'Típus', 'Név', 'Állapot'], sorok: rows };
  Object.keys(CAMP).forEach(function (id) {
    var cp = CAMP[id]; if (!cp.on || cp.type !== 'SEARCH') { return; }
    var a = AS[id] || {}, cnt = function (f) { return (a[f] || 0) + (accAs[f] || 0); };
    var miss = [];
    if (cnt('SITELINK') < 4) { miss.push('webhelylinkek (min. 4: Szelfigép, 360 Spinner, Selfie tükör, Hátterek / Ajánlatkérés)'); }
    if (cnt('CALLOUT') < 4) { miss.push('kiemelések (min. 4)'); }
    if (cnt('STRUCTURED_SNIPPET') < 1) { miss.push('strukturált részlet („Szolgáltatások: …”)'); }
    if (cnt('CALL') < 1) { miss.push('hívás'); }
    if (cnt('AD_IMAGE') + cnt('IMAGE') < 1) { miss.push('képek'); }
    if (cnt('BUSINESS_NAME') < 1 || cnt('BUSINESS_LOGO') < 1) { miss.push('cégnév + logó'); }
    if (miss.length) { add('JAVASLAT', 'Bővítmények', cp.name, 'Hiányzó vagy kevés bővítmény: ' + miss.join(', ') + '.', 'A bővítmények nagyobb hirdetést és jobb CTR-t adnak, költség nélkül.'); }
  });

  /* ---------- 10) PMax eszközcsoportok és jelek */
  var sig = {};
  (q("SELECT asset_group.id, asset_group_signal.audience.audience, asset_group_signal.search_theme.text FROM asset_group_signal") || []).forEach(function (r) {
    var id = String(r.assetGroup.id); sig[id] = sig[id] || { a: 0, s: [] };
    if (g(r, 'assetGroupSignal.audience.audience')) { sig[id].a++; } var stx = g(r, 'assetGroupSignal.searchTheme.text'); if (stx) { sig[id].s.push(stx); }
  });
  rows = [];
  (q("SELECT campaign.name, asset_group.id, asset_group.name, asset_group.status, asset_group.ad_strength, asset_group.final_urls FROM asset_group WHERE asset_group.status != 'REMOVED' AND campaign.status = 'ENABLED'") || []).forEach(function (r) {
    var ag = r.assetGroup, s = sig[String(ag.id)] || { a: 0, s: [] };
    rows.push([r.campaign.name, ag.name, ag.status, ag.adStrength || '', s.a, s.s.join(', '), (ag.finalUrls || []).join(' ')]);
    if (ag.status === 'ENABLED' && /POOR|AVERAGE/.test(String(ag.adStrength))) { add('JAVASLAT', 'PMax', r.campaign.name + ' / ' + ag.name, 'Eszközcsoport erőssége: ' + ag.adStrength + '.', 'Több kép (fekvő + négyzet + álló), legalább 1 saját videó (különben a Google generál), 5 címsor, 5 hosszú címsor, 4 leírás.'); }
    if (ag.status === 'ENABLED' && !s.a) { add('FIGYELEM', 'PMax', r.campaign.name + ' / ' + ag.name, 'Nincs közönségjel.', 'Közönségjel: ügyféllista (Salesmate-ből), weboldal-látogatók, egyéni szegmens a legjobb keresőkifejezésekkel („szelfigép bérlés”, „fotóautomata bérlés esküvőre”, „céges rendezvény program”).'); }
    if (ag.status === 'ENABLED' && !s.s.length) { add('JAVASLAT', 'PMax', r.campaign.name + ' / ' + ag.name, 'Nincs keresési téma.', 'Adj meg 5–15 keresési témát az eszközcsoport szolgáltatásához.'); }
  });
  T['Eszközcsoportok'] = { fej: ['Kampány', 'Eszközcsoport', 'Állapot', 'Erősség', 'Közönségjelek', 'Keresési témák', 'Végső URL'], sorok: rows };

  /* ---------- 11) Közönségek (remarketing, ügyféllista) */
  rows = []; var bigList = 0, custList = 0;
  (q("SELECT user_list.name, user_list.type, user_list.membership_status, user_list.size_for_search, user_list.size_for_display, user_list.membership_life_span FROM user_list") || []).forEach(function (r) {
    var u = r.userList; rows.push([u.name, u.type, u.membershipStatus, n(u.sizeForSearch), n(u.sizeForDisplay), u.membershipLifeSpan || '']);
    if (n(u.sizeForSearch) >= 1000) { bigList++; } if (u.type === 'CRM_BASED') { custList++; }
  });
  T['Közönségek'] = { fej: ['Lista', 'Típus', 'Állapot', 'Méret (keresés)', 'Méret (display)', 'Tagság (nap)'], sorok: rows };
  if (!custList) { add('JAVASLAT', 'Közönségek', '', 'Nincs ügyféllista (Customer Match).', 'Töltsd fel a korábbi ügyfelek e-mail-címeit (Salesmate): PMax közönségjelnek, és meglévő ügyfelek kizárására / visszahívására.'); }
  if (!bigList) { add('JAVASLAT', 'Közönségek', '', 'Nincs 1000 fő feletti, keresésben használható remarketinglista.', 'Weboldal-látogatók lista 540 napos tagsággal (a döntés hosszú: esküvő, céges rendezvény), és „árajánlatot kért” lista.'); }

  /* ---------- 12) Google saját javaslatai */
  rows = [];
  (q("SELECT recommendation.type, recommendation.campaign, recommendation.dismissed FROM recommendation") || []).forEach(function (r) {
    var cid = String(r.recommendation.campaign || '').split('/').pop();
    rows.push([r.recommendation.type, CAMP[cid] ? CAMP[cid].name : '(fiók)', r.recommendation.dismissed ? 'elutasítva' : 'nyitott']);
  });
  T['Google-javaslatok'] = { fej: ['Javaslat típusa', 'Kampány', 'Állapot'], sorok: rows };
  add('JAVASLAT', 'Fiók', '', 'Ellenőrizd kézzel: Javaslatok → Automatikus alkalmazás.', 'A „kulcsszavak hozzáadása”, „általános egyezés”, „költségkeret emelése” és „licitstratégia” javaslatok automatikus alkalmazása legyen KIKAPCSOLVA; a javaslatokat havonta kézzel nézd át.');

  /* ---------- 13) Változások (utolsó 14 nap) */
  rows = [];
  (q("SELECT change_event.change_date_time, change_event.user_email, change_event.client_type, change_event.change_resource_type, change_event.resource_change_operation, campaign.name FROM change_event WHERE change_event.change_date_time DURING LAST_14_DAYS ORDER BY change_event.change_date_time DESC LIMIT 300") || []).forEach(function (r) {
    var ce = r.changeEvent; rows.push([ce.changeDateTime, ce.userEmail || '', ce.clientType || '', ce.changeResourceType, ce.resourceChangeOperation, r.campaign ? r.campaign.name : '']);
  });
  T['Változások'] = { fej: ['Időpont', 'Felhasználó', 'Felület', 'Mi változott', 'Művelet', 'Kampány'], sorok: rows };

  /* ---------- összefoglaló és táblázat */
  if (!F.some(function (x) { return x[0] === 'HIBA'; })) { add('RENDBEN', 'Fiók', '', 'Nem találtam súlyos (HIBA) beállítási problémát.', ''); }
  F.sort(function (a, b) { return SEV[a[0]] - SEV[b[0]] || (a[1] < b[1] ? -1 : 1); });
  var tz = AdsApp.currentAccount().getTimeZone();
  var ss = SpreadsheetApp.create('Google Ads audit – ' + nev + ' – ' + Utilities.formatDate(new Date(), tz, 'yyyy-MM-dd HH:mm'));
  var first = ss.getSheets()[0]; first.setName('Összefoglaló');
  var head = [['Google Ads fiókellenőrzés – ' + nev], ['Időszak: utolsó ' + NAPOK + ' nap · költés: ' + Math.round(totCost).toLocaleString() + ' Ft · konverzió: ' + Math.round(totConv * 10) / 10 + ' · átlag: ' + (acctCpa ? Math.round(acctCpa) + ' Ft/konv.' : '–')], ['']];
  first.getRange(1, 1, head.length, 1).setValues(head); first.getRange(1, 1).setFontSize(14).setFontWeight('bold');
  var fh = ['Súlyosság', 'Terület', 'Elem', 'Megállapítás', 'Teendő'];
  first.getRange(4, 1, 1, fh.length).setValues([fh]).setFontWeight('bold').setBackground('#eeeeee');
  if (F.length) {
    first.getRange(5, 1, F.length, fh.length).setValues(F).setWrap(true).setVerticalAlignment('top');
    var col = { HIBA: '#f4c7c3', FIGYELEM: '#fce8b2', JAVASLAT: '#d9e8fb', RENDBEN: '#d4edda' };
    for (var i = 0; i < F.length; i++) { first.getRange(5 + i, 1).setBackground(col[F[i][0]] || '#ffffff').setFontWeight('bold'); }
  }
  first.setColumnWidth(1, 95); first.setColumnWidth(2, 130); first.setColumnWidth(3, 220); first.setColumnWidth(4, 420); first.setColumnWidth(5, 460); first.setFrozenRows(4);
  Object.keys(T).forEach(function (name) {
    var t = T[name], sh = ss.insertSheet(name), w = t.fej.length, data = t.sorok.length ? t.sorok : [['(nincs adat)']];
    data = data.map(function (r) { r = r.slice(0, w); while (r.length < w) { r.push(''); } return r.map(function (v) { return v === undefined || v === null ? '' : v; }); });
    sh.getRange(1, 1, 1, w).setValues([t.fej]).setFontWeight('bold').setBackground('#eeeeee');
    sh.getRange(2, 1, data.length, w).setValues(data); sh.setFrozenRows(1);
    try { sh.autoResizeColumns(1, w); } catch (e) { /* nagy lapnál kihagyható */ }
  });
  if (HIBAK.length) { var hs = ss.insertSheet('Lekérdezési hibák'); hs.getRange(1, 1, HIBAK.length, 1).setValues(HIBAK.map(function (x) { return [x]; })); }
  var cnt = { HIBA: 0, FIGYELEM: 0, JAVASLAT: 0 }; F.forEach(function (x) { if (cnt[x[0]] !== undefined) { cnt[x[0]]++; } });
  Logger.log('KÉSZ – ' + nev + ': ' + cnt.HIBA + ' hiba, ' + cnt.FIGYELEM + ' figyelmeztetés, ' + cnt.JAVASLAT + ' javaslat.');
  Logger.log('Táblázat: ' + ss.getUrl());
  if (HIBAK.length) { Logger.log(HIBAK.length + ' lekérdezés nem futott le (részletek a „Lekérdezési hibák” lapon).'); }
}
