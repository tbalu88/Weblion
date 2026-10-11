# Crazy panel – Statisztikák fül: beállítási útmutató

A fül a panel többi füléhez hasonlóan a közös panel-jelszóval érhető el. A titkos adatokat
(Meta-token, Windsor API-kulcs, GA4 JSON-kulcs, Google Ads-szkript kulcsa) **csak a WordPress adminban** lehet megadni:
**Beállítások → Crazy statisztikák**. Ezek titkosítva tárolódnak, és sehol nem jelennek meg újra.

## Definíciók

- **Megkeresés**: minden Salesmate-be beérkező deal, a létrehozás napja szerint (Europe/Budapest).
- **Új deal**: aláírt szerződés („Szerződés aláírva” vagy későbbi szakasz, illetve Won), az aláírás napja szerint.
  - Aláírás napja: a szinkron óta az első észlelés órája; korábbra az ügyfélportál létrehozása, ennek hiányában a Won lezárás dátuma.
  - A szinkron előtt aláírt, még nyitott dealeknél, ahol egyik sem ismert, nem becsülünk: ezek nem számítanak bele.
- **Szolgáltatás**: a Salesmate szolgáltatásmezői; az azonos szolgáltatás eltérő nevei (pl. Szelfigép = Fotóautomata) a Crazy statisztikák → „Szolgáltatás-álnevek” mezőben vonhatók össze, a csak kis/nagybetűben eltérő neveket a panel magától egyesíti.
- **Céges / magán**: a „Szerződés EV/KFT” mező (KFT = céges, EV = magán).
- A hét hétfőn kezdődik, a pénznem HUF.

## 1. Valódi cron (ajánlott)

A WP-Cron csak látogatáskor fut. Pleskben:

1. **Websites & Domains → eventcrazy.hu → Scheduled Tasks (Ütemezett feladatok) → Add Task**.
2. Task type: **Fetch a URL**, URL: `https://eventcrazy.hu/wp-cron.php?doing_wp_cron`
3. Run: **Cron style** `*/5 * * * *` (5 percenként).
4. Mentés után a `wp-config.php`-ba, a „That's all, stop editing!” sor elé:
   `define('DISABLE_WP_CRON', true);`

## 2. Google Ads – szkript (developer token nem kell)

1. WordPress admin → Beállítások → Crazy statisztikák → Google Ads: pipáld be az **„új kulcs készítése mentéskor”** opciót, majd kattints a **Mentés** gombra.
   A kulcs **csak ekkor** látszik: másold ki.
2. Google Ads → **Eszközök → Tömeges műveletek → Szkriptek** → **+** → Új szkript.
3. Másold be a beállítási oldalon látható szkriptet. A `KEY` sorba illeszd a kulcsot.
4. Az **első** futtatáshoz írd át a `DAYS = 7` értéket `400`-ra (a tavalyi összevetéshez ez hozza be az előzményeket).
   **Engedélyezés** → **Futtatás**. A naplóban `200 {"ok":true,...}` jelenik meg.
5. Állítsd vissza a `DAYS = 7` értéket, mentsd, majd a szkriptlistában a **Gyakoriság** oszlopban válaszd az **Óránként** beállítást.
6. Ha a Crazyphotónak külön Google Ads-fiókja van, ugyanezt ott is tedd meg, ugyanazzal a kulccsal. A fiókok adatai külön tárolódnak.

## 3. Meta – system user token (ads_read)

1. **business.facebook.com → Beállítások (Business settings) → Felhasználók → Rendszerfelhasználók → Hozzáadás**.
   Név: pl. `crazy-panel`, szerepkör: **Alkalmazott** (Employee).
2. A rendszerfelhasználónál: **Eszközök hozzárendelése → Hirdetési fiókok** → válaszd ki az EventCrazy (és a Crazyphoto) hirdetési fiókot,
   jogosultság: **Kampányok megtekintése / Teljesítmény megtekintése** (elemzés). Kezelési jog nem kell.
3. Ha még nincs: **Fiókok → Alkalmazások → Hozzáadás → Új alkalmazás létrehozása** (típus: Business). Rendeld hozzá a rendszerfelhasználóhoz.
4. A rendszerfelhasználónál: **Új token generálása** → alkalmazás kiválasztása → lejárat: **Soha** →
   jogosultság: **ads_read** → Generálás. Másold ki a tokent (csak egyszer látszik).
5. A hirdetési fiók azonosítója: Hirdetéskezelő → a fiókválasztóban az `act_` utáni szám.
6. WordPress admin → Crazy statisztikák → Meta: írd be az azonosítót (több fiók esetén vesszővel elválasztva) és a tokent → **Mentés** → **Lekérés most**.
   Az első lekérés után a panel automatikusan betölti az elmúlt kb. 400 nap előzményét (óránként 90 napos szeletekben).

## 4. Google Analytics 4 – szolgáltatásfiók

1. **console.cloud.google.com** → projekt kiválasztása vagy új projekt (pl. `crazy-panel`).
2. **API-k és szolgáltatások → Könyvtár** → keresés: **Google Analytics Data API** → **Engedélyezés**.
3. **IAM és adminisztráció → Szolgáltatásfiókok → Szolgáltatásfiók létrehozása**: név `crazy-panel-ga`. Szerepkör nem kell → Kész.
4. A szolgáltatásfióknál: **Kulcsok → Kulcs hozzáadása → Új kulcs létrehozása → JSON**. A letöltött fájlt őrizd meg biztonságos helyen.
5. GA4 (**analytics.google.com**) → **Adminisztráció → Tulajdon → Tulajdon hozzáférés-kezelése → + → Felhasználók hozzáadása**:
   a szolgáltatásfiók e-mail-címe (`…@….iam.gserviceaccount.com`), szerepkör: **Megtekintő** (Viewer). Ezt **mindkét tulajdonnál** (EventCrazy, Crazyphoto) tedd meg.
6. Tulajdonazonosító: Adminisztráció → **Tulajdon részletei** → jobbra fent a TULAJDONAZONOSÍTÓ (csak szám).
7. WordPress admin → Crazy statisztikák → GA4: soronként `EventCrazy = 123456789`, `Crazyphoto = 987654321`; töltsd fel a JSON-fájlt → **Mentés** → **Kapcsolat tesztelése**.
   - Élő adat: utolsó 30 perc, 60 másodperces gyorsítótárral.
   - Időszakos adat: 1 órás gyorsítótárral; a Google néhány órás késéssel frissíti.

## 5. LinkedIn

### Automatikusan: Windsor.ai (ajánlott)

1. windsor.ai → a LinkedIn Ads összekötése (a 559891788-as hirdetési fiók), majd az API-kulcs kimásolása (onboard.windsor.ai → API key).
2. WordPress admin → Crazy statisztikák → **LinkedIn hirdetések (Windsor.ai)**: API-kulcs beillesztése (csak írható, titkosítva tárolódik), fiók(ok) vesszővel, **EUR/HUF árfolyam** → **Mentés** → **Lekérés most**.
   - Naponta egyszer fut a szinkronnal együtt: az utolsó 30 nap újraíródik, az első sikeres körben ~400 nap előzmény töltődik be.
   - Lekért mezők: dátum, fiók, kampánycsoport, kampány, költés, megjelenés, kattintás, one-click lead, pénznem.
   - A fiók EUR-ban költ: az eredeti összeg és pénznem tárolódik, a forintösszeg a beállított árfolyamon számolódik. Az árfolyam módosításakor a korábbi napok is újraszámolódnak.
   - A költés a **linkedin** csatornához számít. A Statisztikák fül **Hirdetési platformok** kártyáján: költés (Ft és €), megjelenés, kattintás, CTR, lead, CPL a kiválasztott nap / hét / hónap szerint.

### Kézzel

A Statisztikák fül alján, a **LinkedIn – kézi költség** kártyán adható meg kampányonként vagy havonta (kezdet, vége, összeg).
Az összeg arányosan oszlik el a napokra. Azokon a napokon, ahol már van Windsor.ai-adat, a kézi tétel nem számít bele (nincs dupla költés).

## 6. Forrás minden leadnél – Make-forgatókönyvek

A csatorna meghatározása (sorrendben):
1. a kontakt **UTM Source / Medium** mezője (fizetett: cpc/ppc/paid);
2. a deal **Source** (Forrás) mezője;
3. a deal címkéi.

A Make-forgatókönyvekben a **Salesmate deal létrehozása** modulban:

| Forgatókönyv | Deal → Source | Kontakt → UTM Source | Kontakt → UTM Medium | Kontakt → UTM Campaign | Kontakt → UTM Content |
|---|---|---|---|---|---|
| Facebook / Instagram Lead Ads | `Facebook Lead Ads` (ez már így van) | `facebook` | `paid` | kampány neve | hirdetés neve |
| LinkedIn Lead Gen | `LinkedIn Lead Ads` | `linkedin` | `paid` | kampány neve | hirdetés neve |

Ezen felül a kontakt **„Rendezvény típusa”** mezőjét (`textCustomField2`) a LinkedIn- és a webes űrlapoknál is töltsétek ki,
mert a foglalási előidő rendezvénytípus szerinti bontása (pl. céges karácsony) ebből készül. Jelenleg csak a Facebook-leadeknél van kitöltve.

A webes űrlapok (eventcrazy.hu, crazyphoto.hu) a kontakt UTM-mezőit már töltik. Ha egy hirdetés linkjén nincs UTM, a csatorna
„Organikus / weboldal” lesz. Ezért minden hirdetési linkre kell UTM:
- Google Ads: a fiókszintű követési sablonba: `{lpurl}?utm_source=google&utm_medium=cpc&utm_campaign={campaignid}&utm_term={keyword}`
- Meta: a hirdetés URL-paraméterei közé: `utm_source=facebook&utm_medium=cpc&utm_campaign={{campaign.name}}&utm_content={{ad.name}}`

## 7. Fedezet

Fedezet = dealérték − az Elszámolás fülön kifizetett kezelői összegek (bér, km, parkolás, jatt) − papír − AI-képek.

- **Papír**: darabszám (Elszámolás → Papírfogyasztás) × egységár (WP admin, jelenleg 55 Ft/db).
- **AI karikatúra / AI fotózás**: 58 800 Ft / 10 000 kredit × 10 kredit = **5,88 Ft / kép**.
  A képek száma szintén a „Papírfogyasztás (db)” mező; ha az AI-képek száma ettől eltér, külön mezőt érdemes felvenni az Elszámolásban.
- Csak a teljesen kifizetett (minden kezelő kifizetve) rendezvények számítanak.

## 8. Két oldal: eventcrazy.hu és crazyphoto.hu

- A Salesmate-be mindkét oldal megkeresései beérkeznek (Source: „Eventcrazy.hu” / „Crazyphoto.hu”), minden mutatóban benne vannak.
- GA4: mindkét tulajdon megadható (soronként `Név = property ID`); a fül oldalanként és összesítve mutatja.
- Meta: több hirdetési fiók adható meg vesszővel; Google Ads: ugyanaz a szkript mindkét fiókban futhat. A fiókok adatai külön tárolódnak.

## 9. Hiányzó adatok, amiket érdemes rögzíteni

- **Foglaltság miatti elutasítás**: Salesmate → Beállítások → Deal → *Lost reasons* → új ok: **„Foglalt – nincs szabad gép”**.
  Ezt kell kiválasztani, ha foglaltság miatt utasítotok el egy megkeresést.
- **Papír egységára** a fedezethez: WordPress admin → Crazy statisztikák → „Papír egységára (Ft/db)”.
- A régi, „Ads” forrású dealeknél nem tudni, melyik hirdetési rendszerből jöttek. Ezek külön sorban szerepelnek.

## Figyelmeztetések csempe (élő)

A Statisztikák fül tetején. Percenként frissül; a Salesmate-változásokat 10 percenként kéri le (és azonnal, ha valaki nézi a fület és az adat régebbi).

| Figyelmeztetés | Mikor jelez | Forrás |
|---|---|---|
| Megválaszolatlan levelek | a kontakt utolsó bejövő levele újabb, mint az utolsó kimenő (nyitott deal, 30 napon belül); 4 óra után sárga, 24 után piros | Salesmate kontakt: utolsó kapott / küldött e-mail |
| Rohadó dealek | a deal „rotten” dátuma lejárt; az utolsó 14 nap külön listában | Salesmate deal: rottenDate |
| Lejárt kintlévőségek | „Rendezvény lement (G&R)” / „Pénzre várunk” szakasz, és a rendezvény + díjbekérő napjai (8 / 30, alapértelmezés 8) elmúltak | deal szakasz + a panel díjbekérő-jelölése |
| Kintlévőségek | ugyanezek összesen, határidőn belül is | |
| Lezajlott, de nincs továbbléptetve | a rendezvény lement, de a deal még szerződéses szakaszban áll | |
| Közelgő rendezvény, aláíratlan szerződés | 14 napon belüli rendezvény, „Szerződés kiküldve” szakaszban | |
| Telt napok | a következő 60 nap teltházas / majdnem telt napjai | |
| Hirdetési anomáliák | tegnapi költés a 14 napos átlag 2×-e felett / 40%-a alatt, költés kattintás nélkül (3 nap), CPC 2×, 3 napja nincs platform-lead, leállt kampány, a szkript / API nem frissít | költségtábla |
| Szezonális összevetés | az utolsó 30 nap megkeresései / aláírt dealjei a korábbi évek azonos időszakának átlagához | |
| Kereslet a következő hónapokra | a következő 4 hónap rendezvényeire eddig jött megkeresések, a tavalyi év ugyanezen napjához | |
