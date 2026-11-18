<?php
/* A felhasználói útmutató szövege a Segítség-chatnek (a Claude Docs útmutatóból, élesítéskor frissítve). Nem nyilvános: PHP-ként nem olvasható ki. */
if (!defined('ABSPATH')) { exit; }
return <<<'MD'
# Crazy panel – felhasználói útmutató

Oct 29, 2026 · @Balu

## Röviden

A **Crazy panel** a cég saját ügyfélkezelő rendszere: deal-ek, levelek, ügyfelek, naptár és számlázás egy helyen. Számítógépen és telefonon is működik: **eventcrazy.hu/crazy-panel**

## 2026. november 9-től: minden a panelben

Mostantól **mindent a panelben csinálunk**, a Salesmate-et nem nyitjuk meg. A Salesmate tartalékként fut tovább: minden, amit a panelben mentesz (deal, kontakt, szakasz, felelős, jegyzet, tevékenység), azonnal oda is bekerül, így ha a panel egyszer nem elérhető, a Salesmate-ben minden megvan.

- **Deal-ek, kontaktok**: a panel Deal-ek és Kapcsolatok fülén.
- **Levelek**: a panel Levelezés fülén válaszolj (és írj új levelet) – így a levél azonnal a deal mellé kerül, és te leszel a deal felelőse, ha te válaszolsz először.
- **Az új ajánlatkérés** magától jön: a weboldali űrlapokból (eventcrazy.hu, crazyphoto.hu) már a panel hozza létre a dealt azonnal, a Facebookról érkező néhány percen belül megjelenik a panelben, a Pumble-értesítés és az átnevezés már a panelből megy.
- **Naptár**: a „Szerződés kiküldve” szakaszba lépett deal bejegyzését (a kezelői adatlap linkjével) a panel hozza létre.
- **Automata levelek** (pl. T-2 nap): egyelőre a Salesmate küldi a háttérben, nekünk nincs vele teendőnk.
- Ha valami hiányzik vagy furcsa: szólj Balázsnak, és ne a Salesmate-ben javítsd.

## 1. Belépés

1. Nyisd meg: **eventcrazy.hu/crazy-panel**
2. Írd be az e-mail-címed és a saját jelszavad → **Belépés**.
3. Elfelejtetted a jelszót? Kattints az **Elfelejtett jelszó** linkre – e-mailben jön egy link az új jelszóhoz.

Kilépés: a menü alján a **Kilépés** gomb.

## 2. A menü

&#91;image: A panel menüje és a Deal-ek lista\]

Bal oldalt vannak a fülek, az **Eszközök** a nevére kattintva nyílik le. Fent a **Keresés** (Ctrl + K) mindenben keres: deal, ügyfél, levél, feladat. Alul az Éjszakai mód kapcsoló (nap / hold) sötét színekre vált, a böngésződ megjegyzi (mobilon a ☰ Menü felső gombsorában, hold / nap ikon). Ugyanott a **csengő** az értesítéseid: rákattintva külön oldalon nyílnak meg, napokra bontva. Az „Olvasatlan” gombbal csak az újakat látod, a „Mind olvasott” mindet olvasottnak jelöli, a lista alján régebbieket is betölthetsz. Egy értesítésre kattintva a panel odavisz, ahová az értesítés szól (deal, levél, kártya).

**Crazy panel app a telefonon (és értesítés az eszközre):** a panel feltehető a kezdőképernyőre, mint egy app. iPhone-on Safariban a **Megosztás** gomb → **Főképernyőhöz adás**; Androidon és gépen az Értesítések oldal **Telepítés** gombja. Utána az Értesítések oldal tetején a **Bekapcsolás** gombbal kérhetsz értesítést erre az eszközre: új ajánlatkérés, megemlítés, kiosztás akkor is megjön, ha a panel nincs nyitva. A **Próbaértesítés** gombbal kipróbálhatod. iPhone-on csak a kezdőképernyőről megnyitott appban működik.

| Fül | Mire való |
| --- | --- |
| Kezdőlap | Mai teendőid, új ajánlatkérések, olvasatlan levelek, közelgő rendezvények |
| Kapacitás | Melyik napon hány gép szabad |
| Deal-ek | Minden megkeresés és foglalás |
| Kapcsolatok | Ügyfelek és cégek |
| Levelezés | Az info@ postafiók |
| Feladatok | Teendőtáblák a csapatnak |
| Követés | Rendezvényenkénti pipák (szerződés, sablon, háttér, galéria) |
| Hátterek | Melyik háttér melyik napon foglalt |
| Galériák | Az összes szelfigép-galéria egy helyen: megnyitás, link, QR-kód, kezelés, levél az ügyfélnek |
| Naptár | A Google Naptár |
| Eszközök | Munkanapló, Napi munkalap, Gyors ellenőrzés, Sablonkészítő, Háttérkatalógus |

Mindenki csak azokat a füleket látja, amik neki be vannak kapcsolva.

## 3. Deal-ek

&#91;image: Pipeline nézet\]

- Fent válthatsz: **Lista** vagy **Pipeline** (oszlopok szakaszonként).
- **Szűrő**: a kereső melletti Szűrő gombbal jobbról megnyílik a szűrőpanel. Válassz feltételcsoportot (Deal, Kontakt, Cég vagy Teendő), majd mezőt, műveletet és értéket – pl. Deal → Állapot → bármelyike → Megnyert, vagy Deal → Rendezvény dátuma → a következő N napban → 7. Egy csoporton belül az ÉS / VAGY kapcsolóval döntöd el, hogy minden feltételnek vagy elég egynek teljesülnie; több csoportnál felül azt, hogy a csoportok között mi legyen. A tábla és a lista azonnal frissül; a gombon a feltételek száma látszik, a × mindet törli. Állapot-feltétel nélkül csak a nyitott dealek látszanak. A korábbi gyorsgombok helyett: Felelős → én vagyok; Teendő → Lejárt teendő; Teendő → Nyitott teendő → nincs; Deal → Rohadás dátuma → előtte → mai nap.
- **Mentett nézetek**: egy összeállított szűrőt a panel tetején a „Mentés nézetként” linkkel névvel elmenthetsz; pipáld be a „Megosztás a csapattal” jelölőt, ha a kollégáknak is kell. Később a „Mentett nézet” listából egy kattintással visszahozod. Ha egy saját nézeten változtatsz, a „Nézet frissítése” elmenti; a kuka ikonnal törölheted.
- **Rendezés és kártyák**: a sor végén a nyilas legördülővel rendezel; a Pipeline-ban mellette a két ikonnal választhatsz részletes vagy kompakt kártyák között. Az ⓘ ikon megmutatja, hogyan kapcsolódik a panel a Salesmate-hez.
- **Lista oszlopai**: a Lista nézetben az Oszlopok gombbal bármelyik deal-, kontakt- vagy cégmezőt oszlopként megjelenítheted (keresővel). A látható oszlopok sorrendjét húzással vagy a kis nyilakkal változtatod, a × elrejti az oszlopot, az „Alapértelmezés” visszaállítja az eredetit. Az oszlop szélességét a fejléc jobb szélét húzva állítod. A beállítást a panel megjegyzi, és mentett nézetbe is elmenthető. Rendezéshez kattints az oszlop fejlécére (újra kattintva megfordul).
- **Gyors szerkesztés a listában**: a szerkeszthető cellákon egérrel fölé állva megjelenik a ceruza (dupla kattintás is működik). Írd be az új értéket, és nyomj Entert vagy a Mentés gombot; Esc vagy Mégse elveti. A mentés ugyanúgy a Salesmate-be is bekerül, mint a deal-adatlapon. Szerkeszthető például: név, érték, felelős, rendezvény dátuma és kezdése, helyszín, létszám, szolgáltatások, fizetés, kupon, megjegyzés, valamint a kontakt neve, e-mail-címe és telefonszáma.
- **Keresés**: név, e-mail, telefon vagy deal-szám.
- **Szakaszváltás**: a Pipeline-ban húzd a kártyát a másik oszlopba.
- Az **új** jelölésű kártya friss ajánlatkérés.
- **Saját ajánlatkérő űrlap (az átállástól):** az eventcrazy.hu és a crazyphoto.hu űrlapjai (az árkalkulátoré is) a panel saját űrlapjai, ugyanazokkal a mezőkkel. A beküldésből a panel azonnal létrehozza a kontaktot és a deal-t (név, felelős, mezők, „Web-form” címke), a csengőn értesít, és az info@ címre is megy értesítő levél. Ha a deal létrehozása nem sikerül, a beküldés a Megkeresések között megmarad, a panel 10 percenként újrapróbálja, és 3 sikertelen próba után szól. Be- és kikapcsolás: Beállítások → Átállás → „EventCrazy / Crazyphoto űrlap → deal” → „Panel + tükör”; visszakapcsoláskor újra a Salesmate-űrlap látszik.
- **Ki lesz a felelős?** Az új deal annak a nevére kerül, aki a kiválasztott kollégák (most Andi és Dorka) közül elsőként levelet vagy választ küld az ügyfélnek a panelből (a levélszálból vagy a deal E-mail gombjával). A megnyitás, szerkesztés, jegyzet vagy szakaszváltás nem számít. Addig az űrlapos deal ideiglenesen felváltva kerül valamelyiküköz. Ha valaki kézzel állítja be a felelőst (új deal felvételekor vagy a Felelős mezőben), az marad. A váltás a deal változásnaplójában látszik. A kollégák listája: Beállítások → Átállás → „Felelős-kiosztás”.
- **Új deal**: a **+ Új deal** gomb.
- Új dealnél, ha a név, e-mail vagy telefon alapján már van ilyen kontakt, a panel szól: **Ehhez rendelem** – így nem lesz duplikátum.
- **🔁** a deal neve előtt = visszatérő ügyfél (már rendelt tőlünk).
- Kártyára vagy sorra kattintva megnyílik a deal adatlapja.
- A nézet magától frissül, nem kell újratölteni.

## 4. A deal adatlapja

&#91;image: Deal adatlap\]

- **Felül a szakaszsáv**: kattintással léptetsz; **Megnyert** / **Elvesztett** gomb.
- **Szerkesztés**: minden adat átírható (dátum, helyszín, csomag, kontakt…).
- **Bal oldal**: érték, rendezvény, kontakt, cég. Ha nincs cég, a panel javasol egyet (**✨ Javasolt cég**) – csak jóvá kell hagyni.
- **Középen**: jegyzet (kollégát **@Név**-vel említhetsz meg), tevékenységek, és az idővonal: levelek, jegyzetek, változások.
- **Jobb oldal**: Ügyfélportál, Számlázás, Szerződés.
- **Becsült ár → Ajánlat küldése ezzel az árral**: előre kitöltött ajánlatlevél.
- Zöld sáv bal fent: **🔁 Visszatérő ügyfél** – hány rendezvénye volt már, mennyiért, mikor utoljára.
- **🚐 Kiszállási díj**: a panel a helyszínből kiszámolja (Budapesten a csomag szerint, vidéken 130 Ft/km oda-vissza) → **Beírom a dealbe**.
- **🔗 Link**: a deal linkje, ha egy kollégának küldenéd.

## 5. Levelezés

&#91;image: Levelezés\]

- **Bal**: mappák · **Közép**: levelek (Nyitott / Kiosztatlan / Mind, **Olvasatlan** szűrő) · **Jobb**: a megnyitott levél.
- **Fenti gombok**: Lezárás, Címke, ✨ AI válasz, Teendő, 🔗 Link másolása, olvasatlan, archív, kuka.
- **Címkék**: a levél fejlécében a Címke gombbal választhatsz a meglévő címkék közül (pipálás), vagy beírhatsz újat („+ Új címke”). A címkék színes csíkként látszanak a levéllistában és a szál fejlécében; a ×-szel leveheted. Több kijelölt szálra is ráteheted (Címke gomb a kijelölésnél). Szűrés: a bal oldali mappák alatt a „Címkék” részben kattints egy címkére (többet is választhatsz – ilyenkor bármelyik elég); a lista fölött a „Szűrő törlése” visszaállítja. Telefonon a lista tetején lévő lenyilóval szűrhetsz. Kezelés: a „Címkék” melletti Kezelés gombbal új címkét vehetsz fel, színt választhatsz, átnevezhetsz (kattints a névre), összevonhatsz két címkét, vagy törölhetsz egyet – ez minden levélszálon érvényes, és a szálak idővonalán is látszik.
- **Számlák automatikus címkéje**: a bejövő számlák maguktól „Számla” címkét kapnak. Számlának számít, ha a csatolt PDF neve számlára utal, vagy ha egy cég vagy számlázóprogram (pl. Számlázz.hu, Billingo) „számla”, „díjbekérő”, „proforma” vagy „invoice” tárgyú új levelet küld PDF-fel. A válaszlevelek és a magánszemélyek „számla” tárgyú kérdései nem kapnak címkét. Ha egy levél tévesen kapta meg, a ×-szel leveheted; ha egy számla kimaradt, tedd rá kézzel. A „Számla” címkére szűrve egy helyen látod az összes beérkezett számlát.
- **Számlák a Drive-on**: a „Számla” címkés bejövő levelek PDF-melléklete magától bekerül a közös Drive „EC - Számlák ÉÉÉÉ” mappájába, a számla kelte szerinti havi mappába (pl. „11 - November”), az eredeti fájlnévvel. A keltet az AI olvassa ki a PDF-ből (ha nem sikerül, a Google szövegfelismerése, végül a levél dátuma dönt). A melléklet mellett zöld „✓ Drive · 11 - November” jelvény jelzi: kattintva megnyitja a fájlt a Drive-on, az egérrel fölé állva látod a keltét, a kiállítót, a számlaszámot, az összeget és a fizetési határidőt. A levél fejlécében „Számla a Drive-on” jelzi. Ha a keltét nem sikerült kiolvasni, a jelvény narancssárga, kérdőjellel. **Rossz hónap javítása**: a jelvény melletti ✎ gombbal válaszd ki a jó hónapot – a fájl a Drive-on is átkerül abba a mappába. Ha a mappában már van azonos nevű fájl, nem tölti fel újra. Régebbi számlánál, vagy ha kézzel tetted rá a „Számla” címkét, a PDF melletti „Mentés a Drive-ra” gombbal küldheted fel. Januártól a panel magától létrehozza az új év mappáját. Az AI tokenhasználata: Beállítások → AI-használat (tokenek).
- **Kiosztás** egy kollégának; **Követés**: értesítést kapsz, ha új levél jön a szálba.
- **Válasz / Válasz mindenkinek / Továbbítás**: a levél jobb felső sarkában.
- **↻** a keresőmezőben: új levelek letöltése most (magától is jönnek, 5 percenként).
- Narancssárga figyelmeztetés = a levél lezárt dealhez tartozik → **Áthelyezés ide** gomb.
- **Összecsukott levelek:** egy szálban alapból csak a legutóbbi levél van nyitva, a többi egy sorban látszik (feladó, dátum, a levél eleje). Kattints rá a kinyitáshoz, a nyitott levél fejlécére az összecsukáshoz. A levelek fölött az **Összes kinyitása / Összes összecsukása** gomb egyszerre vált mindet.
- **Ki nézi / ki válaszol:** ha egy kolléga ugyanazt a levélszálat nézi vagy épp választ ír rá, a szál tetején sárga sáv jelzi („✍ Andi épp válaszol”, „👀 Dorka is nézi”). Ha közben új levél jött, azt is jelzi. Ha küldéskor kiderül, hogy a megnyitásod óta már ment válasz a szálba, a panel rákérdez, mielőtt elküldené.
- **Visszavonható küldés:** a levél a Küldés után 20 másodperccel megy ki, addig alul a **Visszavonás** gombbal megállítható (a levél a szerkesztőben marad; ha közben máshová léptél a panelben, piszkozatként visszaugrik a levélíróra, a címzettekkel és mellékletekkel együtt), vagy a **Küldés most** gombbal azonnal elküldhető. Archiválás, Kukába vagy Spambe tétel után szintén van **Visszavonás**.
- **Piszkozatok:** a levélíró gépelés közben magától menti a levelet (alul: „Piszkozat mentve 10:42”). Ha bezárod vagy másik menübe lépsz, a levél a **Piszkozatok** mappába kerül; rákattintva folytathatod (egy válasz-piszkozat a saját levélszálában nyílik meg, így látod, mire válaszolsz), a 🗑 gombbal elvetheted, vagy a jelölőnégyzetekkel többet is kijelölhetsz, és egyszerre elvetheted őket. A piszkozat a saját fiókodhoz tartozik, gépen és telefonon is megvan, a kollégák nem látják. Egy félbehagyott válasz a levélszálon is látszik („✎ Piszkozat”, „Folytatás” gomb). Az **Új levél** mindig üresen nyílik; elküldéskor a piszkozat törlődik.
- **Ügyfélportál-link küldése**: ha a levél dealhez kötött, és a dealnek még nincs portálja (a szerződés sincs aláírva), a levélíró tetején megjelenik az „📝 Ügyfélportál” gomb. Kattintásra beteszi a levélbe az ügyfél személyes linkjét; a ▾ nyíllal választhatsz: gombként, szövegként egy mondatban, vagy csak a puszta link. Már kiküldött portálnál a szerkesztő eszköztárának portál-ikonja továbbra is beszúrja a linket. Küldés után a deal magától az „Adatlap kiküldve” szakaszba kerül, ha még az előtt tartott.
- **Levélszál kiosztása**: a még kiosztatlan levélszál automatikusan annak a kollégának (Andi vagy Dorka) a nevére kerül, aki először válaszol rá a panelből. A szálban „Kiosztva: … (első válasz)” sor jelzi. Ha a szálhoz új deal tartozik, az is hozzá kerül.
- **Szundi (⏰):** a szál eszközsorában a ⏰ gombbal (vagy több kijelölt szálnál a „Szundi” gombbal) a levélszál eltűnik a Beérkezettből, és a választott időpontban (ma délután, holnap reggel, jövő hétfő, 1 hét, 1 hónap vagy egyéni) olvasatlanként visszajön, értesítéssel. Addig a **Szundi** mappában látod, a visszatérés idejével. Ha közben az ügyfél ír, a szál azonnal visszajön.
- **Billentyűparancsok (gépen):** j / k következő / előző szál, r válasz, a válasz mindenkinek, e archiválás, # vagy Delete Kuka, s szundi, u olvasatlan, x kijelölés, c új levél, / keresés, Esc bezárás. A **?** billentyű megmutatja a listát.
- Zöld sáv = **visszatérő ügyfél** írt; rákattintva látod minden korábbi rendezvényét.
- Feladatok: a **🔁 Visszahívás** kártyákat a panel teszi fel magától – akinek tavaly 2 hónap múlva volt rendezvénye, és azóta nem jelentkezett. Hívd fel vagy írj neki.
- Az archív és a kuka csak a panelben hat, a postafiókból nem töröl semmit.

## 6. Kapcsolatok

&#91;image: Kapcsolatok\]

- **Kontaktok** vagy **Cégek**; kereséskor minden találat látszik.
- Névre kattintva megnyílik az adatlap → **✎ Szerkesztés**.
- Adminoknak: jelöld ki a sorokat → **🗑 Kijelöltek törlése** (a Salesmate-ből is töröl; a végén be kell írni: TÖRLÉS).
- Duplikátumok: a **⧉ Duplikátumok** gomb mutatja, mely kontaktok vagy cégek lehetnek ugyanazok (pl. két Allianz). Ami nem ugyanaz → **Nem ugyanaz**, és többet nem jön elő.
- Adminoknak: **⇄ Összevonás** a javaslatnál, vagy jelölj ki 2–6 sort. Válaszd ki, melyik maradjon meg: a többi deal-jei és kontaktjai átkerülnek hozzá, az üres mezői kitöltődnek, a másik e-mail-cím vagy telefonszám a leírásba kerül, a többi rekord törlődik.

**Kezelők (csak adminoknak)**: a gépkezelők adatai egy táblázatban. Kattints a mezőbe, írd át, Enterrel mentődik. A TAJ, személyi, adóazonosító, anyja neve, lakcím és születési adat csak a „Személyes adatok megjelenítése” gombra látszik. A sor elején lévő pötty színnel jelöl, a ⋯ gomb mutatja a megjegyzést és a közelgő rendezvényeit.

## 7. Munkanapló (Eszközök → Munkanapló)

&#91;image: Munkanapló\]

Amikor lezárod a napot (**Lezárás**), az összefoglaló automatikusan megjelenik a Pumble riport-csatornájában. Ha visszanyitják és újra lezárod, „(javítva)” jelöléssel megy újra.

Ákos vezeti naponta, Zsolt és Balázs látja és jóváhagyja.

- **▶ Most kezdem / ■ Most végeztem** – a ledolgozott időt a panel számolja.
- **＋ Újabb szakasz** – ha napközben megszakad a munka (pl. délelőtt raktár, délután rendezvény), több kezdés–befejezés pár is felvehető; a ledolgozott idő ezek összege a szünet nélkül. A „Most kezdem” lezárt szakasz után magától újat nyit, a × gombbal egy szakasz törölhető.
- Az Elvégzett feladatoknál írd be a feladatot, válaszd ki, mennyi idő ment el rá, és jelöld meg a címkéjét (Raktár, Rendezvény, Office vagy Egyéb), majd Hozzáad. Az idő és a címke kötelező; alatta látod, címkénként mennyi időt töltöttél a feladatokkal.
- **Fotók** (a telefon kamerájával is), **km-óra**, **költség** blokkfotóval.
- **Hiba, eszközprobléma** → a „Jelezni kell” pipával az adminok azonnal értesítést kapnak.
- Minden magától mentődik. A nap végén: **✓ Nap lezárása**.
- **Hónap** nézet: összesítő (óra, km, költség) és letöltés Excelbe.

## 8. Telefonon

&#91;image: Telefonos nézet\]

- Alul a gyorsmenü: Menü, Deal-ek, Levelezés, Kapcsolatok, Keresés.
- A **Menü** gomb mutatja a többi fület.
- Levelezés: egy levélre koppintva teljes képernyőn nyílik, **‹ Vissza**-val vissza. Egy sort elhúzva olvasottnak jelölöd vagy archiválod. A kék **+** gomb: jegyzet, válasz, továbbítás.

## 9. Adminoknak röviden

- **Beállítások**: felhasználók és ki mit lát, levelezés, számlázás, Google Naptár, Google Drive mentés, AI.
- **Mentés**: 6 óránként magától; titkosított ZIP a Google Drive „EC PANEL BACKUP” mappájában (a jelszó nálatok van).
- **Rendszerfigyelő**: csengő-értesítés, ha órákig nem jön levél, leáll a szinkron vagy elmarad a mentés.
- **Számla „\[?\] Nem biztos, hogy létrejött”**: nézd meg a számlázóban, majd jelöld: **Létrejött…** / **Nem jött létre**.

## 10. Ha valami nem működik

- **Nem látok egy fület** → kérd egy admintól (Beállítások → Láthatóság). A táblázat két részből áll: Fülek és Eszközök (Munkanapló, Logisztika, Napi munkalap, Gyors ellenőrzés, Sablonkészítő, Háttérkatalógus, AI-kredit, Automatizmusok); kollégánként pipálható, a sor melletti „mind” gomb mindenkinél egyszerre kapcsol, utána Mentés.
- **Nem frissül valami** → **Frissítés** gomb a menü alján, vagy töltsd újra az oldalt.
- **„Lejárt a belépés”** → lépj be újra.
- **Nem jönnek a levelek** → szólj egy adminnak (a Rendszerfigyelő is jelzi).

## Kezelőbeosztás (napi alapon)

A kezelőket és hostokat egy **napra** hívjuk meg, nem egy-egy rendezvényre. A rendezvény közeledtével Ákos (vagy admin) a nap elérhető emberei közül választ mindegyik rendezvényhez.

1. **Kapacitás → kattints a napra → Beosztás.** Felül az összesítő: *Igény* (ennyi ember kell aznap), *Ráér*, *Kiosztva*, *Válaszra vár*.
2. **A nap köre:** „＋ Meghívás erre a napra” → jelöld ki, kiket kérdezel → „Meghívás a napra”. Utána „✉ Meghívó küldése”: a levélben csak a nap szerepel („Ráérsz …?”), és egy Ráérek / Nem érek rá gomb. A telefonos választ a „Ráér” / „Nem” gombbal rögzítheted.
3. **Rendezvények:** minden rendezvény gépenként 1 helyet (slotot) igényel (szelfigép + spinner = 2). Ha több ember kell, a „+ / − fő” gombbal nyithatsz extra helyet.
4. **Hozzárendelés (Ákos / admin):** a rendezvény alatti listából válassz embert → „Hozzárendel”. Elöl azok, akik ráérnek és még szabadok; utána akik már máshová vannak kiosztva, akik még nem válaszoltak, és akik nincsenek a körben. A ⚠ jel figyelmeztet (pl. vidékre kezelő kell, nem kezel spinnert). A kiválasztott ember e-mailben megkapja a rendezvény adatait, és a naptárbejegyzése frissül.
5. **Levétel:** a × gombbal. Az ember levelet kap, hogy a beosztása módosult, és visszakerül a nap körébe.
6. **Akik ráértek, de nem kaptak rendezvényt:** „Értesítés a ki nem osztottaknak” gomb – rövid levelet kapnak, hogy aznap nem lesz rájuk szükség. Ezt te döntöd el, mikor küldöd ki.

**Ha a rendezvény adatai már fixek:** a rendezvény sorában „＋ Meghívás erre a rendezvényre” → jelöld ki az embereket → „Meghívás a rendezvényre” → „✉ Meghívó küldése”. Ez a levél már a pontos adatokkal megy (nap, időpont, helyszín, gép – az ügyfél neve nélkül). Ha igent mond, a rendezvénynél „Ráér” lesz, és a nap körébe is bekerül; Ákos a „Véglegesít” gombbal zárja le. Rendezvényre közvetlenül a szerződés kiküldése után lehet meghívni (karácsonyi időszakban már ajánlatkéréskor); addig a napi meghívás a jó.

A havi naptárban a „👤 1/3 +2” jelentése: 3 helyből 1 kiosztva, és még 2 ember ráér aznap.

## Dinamikus árjavaslat

A panel minden rendezvénynapra javasol egy árat. Ez **belső javaslat**: az ügyfél nem látja, a végső árról te döntesz.

- **Alapár:** az adott hónapra kiküldött ajánlatok értékének középértéke (mediánja) ugyanarra a szolgáltatásra és óraszámra, márkánként (Crazyphoto / EventCrazy). Pl. decemberi szelfigép: 3 óra 140 000 Ft, 4 óra 165 000 Ft. Ha kevés az ajánlat, a tavalyi ugyanilyen hónap, a másik márka ajánlatai, végül az árlista segít – ilyenkor „kevés adat” jelzés látszik.
- **Napi igazítás:** hétfő, kedd, vasárnap −10 000 Ft (gyengébb nap); csütörtök, péntek +10 000 Ft (erős nap); ha már csak 1–2 gép szabad (a függő ajánlatokat is figyelembe véve), további +10 000 Ft; ha a nap 14 napon belül van és legalább 5 gép szabad, −5 000 Ft. Nappali rendezvény (legkésőbb 16:00-kor véget ér): csúcsnapon is −10 000 Ft, „utolsó gépek” felár nélkül. Összesen legfeljebb −15 000 / +20 000 Ft, 5 000-re kerekítve.
- **Kapacitás naptár:** a havi és a heti nézetben a napokon kis címke mutatja az igazítást (zöld „−10e”, sárga „+10e”, piros „betelt”); rámutatva látszik az indok.
- **Deal adatlap:** a kapacitásjelzés alatt a „Javasolt ár” doboz; rákattintva látszik a bontás (alapár, napi igazítás). Kiszállás nélkül értendő.
- **Új deal:** amint megvan a dátum és a szolgáltatás (és az óraszám), az Érték mező alatt megjelenik a javaslat; a „Beírom az értékbe” gombbal átveheted.
- **Beállítás (admin):** Beállítások → Dinamikus árazás. Itt kapcsolható ki, és itt írható át minden összeg (napok, utolsó gépek, last minute, korlátok); felül látszanak a decemberi szelfigép-alapárak.

## AI-kredit

Az AI-képek (karikatúra, magazinborító és társai) az OpenRouter-fiók kreditjéből készülnek; az egyenleg a panelben látszik: **Eszközök → AI-kredit**.

- **Egyenleg** dollárban, alatta hogy kb. hány AI-képre elég a jelenlegi modellel. 15 $ alatt sárga, 5 $ alatt piros figyelmeztetés jelenik meg – rendezvény előtt érdemes ránézni.
- **Kredit feltöltése**: megnyitja az OpenRouter feltöltő oldalát. Lépj be a céges OpenRouter-fiókba → Add Credits → összeg → fizetés kártyával. Utána a panelben a **Frissítés** gombbal azonnal látszik az új egyenleg (magától 5 percenként frissül).
- **Használat részletei**: az OpenRouter saját kimutatása hívásonként.
- Alul a havi összesítő: hány AI-kép készült, mennyi volt a költség, hány kérés nem sikerült.
- Ha nincs beállítva AI-kulcs, ezt írja ki; a kulcsot a WordPress-admin „Crazy Booth gépek” oldalán lehet megadni.

## Raktár

A **Raktár** fülön látszik, mi van raktáron, hol van, és mi ment ki melyik rendezvényre.

- **Készlet** – a fogyóanyagok (pl. DNP-papír, festékszalag, kellékek) helyenként. A „kevés” / „elfogyott” jelölés a beállított minimumkészlethez mér. A „Rendezvényen” lévő mennyiség kint van, nem számít bele a készletbe.
- **Új cikk** – név, kategória, mértékegység, minimumkészlet, beszerzési ár, szállító, cikkszám. A „Fogyás nyomatonként” mezőben megadható, mennyi fogy egy nyomatra (papírnál pl. 0,0025 = 1/400 tekercs).
- **Beérkezés / Kiadás / Több…** – a sor végén. Kiadáskor ki kell választani a rendezvényt (név vagy deal-szám alapján; üresen a következő két hét rendezvényeit mutatja). A „Több…” alatt: visszavétel, áthelyezés, selejt, leltárkorrekció.
- **Eszközök** – az egyedi darabok (gép, kamera, nyomtató, objektív, játéklemez stb.). **+ Új eszköz**: megnevezés, darabszám (pl. 5), és ha van, a sorozatszámok soronként – egy lépésben felveszi mind az 5 darabot, saját QR-kóddal („Név #1, #2…”, később átírható). Ha a megnevezés már szerepel, az új darabok ahhoz kerülnek. A fajta sorában látszik a darabszám, mellette **+ Darab** és **✎ Szerkesztés** (a fajta adatai); minden darab sorában **✎ Szerkesztés** (egyedi név, sorozatszám, állapot, szervizhatár), QR, Kiadás / áthelyezés és Előzmények. Minden darabnál látszik, hol van (és ha kint van, melyik rendezvényen), az állapota, és ha szervizhatárt adtál meg, a „szerviz esedékes” jelzés.
- **Szolgáltatáscsomagok** – a Raktár „Szolgáltatáscsomagok” nézetében állíthatók össze a gyakran együtt kimenő eszközök (pl. „Szelfigép 1. csomag”: kamera, nyomtató, laptop) és a fogyóanyag mennyisége (pl. 2 tekercs papír). Egy darab csak egy szolgáltatáscsomagban lehet. **Kiadás**: rendezvény (vagy más hely) kiválasztása, a listában minden kiadható eszköz be van pipálva (ami szervizben / hibás, alapból nincs; ami már kint van, nem választható), a papír mennyisége átírható. **Visszavétel**: pipáld ki, ami visszajött – ami nincs kipipálva, kint marad, és a szolgáltatáscsomagnál látszik (pl. „1 / 3 kint”). A visszahozott maradék papír is beírható. A Kiadás ablakban választható: Rendezvényre vagy Más helyre (pl. haza) – a hely a Helyek listájából jön, vagy a „+ Új hely…” sorral ott helyben felvehető; a visszavétel mindent visszahoz, ami nem a fő raktárban van. QR-kód: a csomag sorában a „QR” gomb mutatja (kép másolása, címke nyomtatása). A 📷 Beolvasás gombbal beolvasva a csomag – ha a raktárban van – a kiadást, ha kint van, a visszavételt nyitja meg; ami mégsem megy vagy nem jött vissza, annál vedd ki a pipát.
- **Hogyan épül fel?** Két fajta tétel van. *Fogyóanyag* (Készlet nézet): csak a mennyisége számít, pl. papír, festékszalag, elem. *Eszköz* (Eszközök nézet): minden darabja külön sor saját QR-kóddal, hellyel, állapottal, pl. kamera, nyomtató, játéklemez. Az eszközök három szinten látszanak: **kategória** (pl. Kamera, Játék) → **eszközfajta** (pl. Nikon D5300 váz, Gran Turismo 7 lemez, mellette a darabszám) → **darabok** (#1, #2…). A kategóriát az eszközfajta „✎ Szerkesztés” gombjánál lehet megadni vagy átírni.
- **QR-olvasó** – a Raktár fül tetején a „📷 Beolvasás” megnyitja a kamerát; a címkét a keretbe tartva magától felismeri, és az eszköz adatlapja nyílik meg. Kamera nélkül a címkén látható kód be is írható.
- **Egyedi felirat a címkén** – a QR-ablakban vagy az eszköz szerkesztésénél („Felirat a QR-címkén”), pl. „Tartalék kamera – töltő a dobozban”; a nyomtatott címkén és a PNG-n is megjelenik.
- **Törlés** – a cikk / eszközfajta / darab „✎ Szerkesztés” ablakának bal alsó sarkában („Törlés…”), megerősítés után. A mozgásnapló is törlődik, nem vonható vissza; ami csak elveszett vagy kivezetett, ott elég az Állapotot átállítani.
- **QR-kód** – minden eszköz sorában a „QR” gomb megmutatja a kódot; innen nyomtatható címke vagy letölthető PNG. Több eszközről egyszerre: Eszközök → „QR-címkék nyomtatása…” → kijelölés → A4-es címkelap (3 × 8 címke, 70 × 37 mm; nyomtatáskor 100%-os méretezés). A kódot telefonnal beolvasva a panelben ez az eszköz nyílik meg (bejelentkezve).
- **Mozgások** – minden be- és kivét időrendben: ki, mikor, honnan hová, melyik rendezvényre.
- **Helyek** – a helyek listája szerkeszthető; az első a fő raktár, ide kerül a beérkezés és az új eszköz.

## Kezelők – Munkaidő

A Kezelők fülön a „Munkaidő” nézet havonta, kezelőnként mutatja, mikor ment a szelfigép a rendezvényeiken (az első gép indulásától az utolsó leállásáig). A beosztásban véglegesített kezelő kapja; ha még nincs véglegesítve, a „ráér” jelölésű – ez dőlt betűvel látszik. Ami rendezvényen nem volt beosztott kezelő, külön listában van. A napló 2026. 11. 15-től készül.

## Kezelők – Interjú-eredmények

- **Végső döntés:** a Kezelők → Interjú-időpontok listában minden jelentkező sorában „✓ Alkalmas” / „✕ Nem” gomb. Újra rákattintva a döntés törlődik. Ahol még nincs döntés, de vannak értékelések, szürke „javaslat” címke mutatja a többségi véleményt.
- **Szűrő:** Mind / Alkalmas / Nem alkalmas / Nincs döntés, darabszámmal.
- **Export:** „Eredmények (CSV)” – a szűrésnek megfelelő jelentkezők: név, telefon, e-mail, végső döntés, javaslat, értékelések, bejegyzések (Excelben megnyitható).
- **E-mail:** a jelentkező e-mail-címére kattintva a panel levélírója nyílik, azonnal küldhető.
- **Tömeges levél:** „Levél az alkalmasoknak (BCC)” és „Levél a nem alkalmasoknak (BCC)” – a levélíróban a Címzett a saját postafiókunk, a jelentkezők titkos másolatban (BCC) vannak, így nem látják egymás címét. A szöveget meg kell írni, a levél csak a Küldés gombra megy ki. Egy levélben legfeljebb 100 titkos címzett lehet.


## Legutóbbi változások
| Dátum | Verzió | Változás |
| --- | --- | --- |
| 2026-11-17 | Booth 0.14.0 | Crazy Booth → Kezelő (beállítások): új „Nyomtató” fül. Itt van minden nyomtatási beállítás: példányszám, automatikus nyomtatás, nyomtatók, papírméret (10×15, 13×18, 15×20 cm), kitöltés (szegély nélkül / teljes kép), nagyítás és eltolás a nyomat illesztéséhez, nyomtatási korlát, színkorrekció, matrica-ív és nyomtatóállomás. A papírméretet a nyomtató-meghajtóban is állítsd ugyanerre. |
| 2026-11-17 | Booth 0.13.0 | Crazy Booth → Kezelő → Utána: „Megosztási opciók” – QR-kód (most már kikapcsolható), E-mail és AirDrop (Macen). Ha az e-mail gomb nem jelenhet meg (nincs párosítva a gép, vagy a rendezvény nincs deallel összekötve), a beállítás kiírja, miért. |
| 2026-11-17 | 2.9.311 | Beállítások → Láthatóság: az Eszközök között a „Napló (tevékenység)” is beállítható személyenként (alapból az adminok látják), a Munkanapló mellett. |
| 2026-11-17 | 2.9.310 | Értesítések: Ákos (nem tagja az irodának) nem kap deallel és ügyféllel kapcsolatos értesítést (új ajánlatkérés, sikertelen deal-létrehozás, új deal levelből, deal-jegyzet megemlítés, visszahívandó ügyfél, új levél követett szálban). A beáosás, a gépek és a rendszer értesítései maradnak. |
| 2026-11-17 | 2.9.309 | Kezelők → Jelentkezők: a lista rangsorolható (Rang oszlop, ▲▼ gombok és ⠿ fogóval húzás, szerkesztő mód nélkül is; a sorrend azonnal mentődik). A listába ezentúl csak az kerül, aki az interjún „Alkalmas” döntést kap (a rangsor végére); az interjúfoglalók csak az Interjú fülön látszanak. A korábbi jelentkezők inaktívak lettek (az „Inaktívak is” kapcsolóval láthatók). |
| 2026-11-17 | 2.9.308 | Kapacitás: a szükséges létszám (👤 jelölés, napi ablak „Igény”) mostantól csak a „Szerződés kiküldve” és az utána következő szakaszban lévő rendezvényeket számolja. A függő ajánlat csak akkor számít bele, ha már van rá beosztott ember. |
| 2026-11-17 | 2.9.307 | Kapacitás → Hét: új „Rács” nézet a Google-naptárhoz hasonló csempékkel (napok oszlopokban, órák sorokban, a rendezvények időpontjuk szerint; a függő ajánlat szaggatott keretű, a mai napon piros vonal jelzi a pontos időt). A régi lista a „Lista” gombbal érhető el, a választást a panel megjegyzi. Csempére kattintva megnyílik a deal, a nap fejlécére kattintva a napi ablak. Telefonon a rács oldalra görgethető. |
| 2026-11-17 | 2.9.306 | A fő szervert (eventcrazy.hu: panel, weboldal, ügyfélportál) a tartalék szerver figyeli percenként; ha leáll, Pumble-üzenet jön a közös csatornába (helyreálláskor is). Állapota: Eszközök → AI-kredit → Figyelő. |
| 2026-11-17 | 2.9.305 | Ákos tárca: a KFT-s, helyszínen készpénzzel fizetett rendezvények is megjelennek a „Kezelőknél lévő” listában („KFT KP · bruttó”), ha a dealen a fizetési mód „Helyszínen készpénz”, vagy a szerződésben „helyszínen készpénzben” van bejelölve. AI-kredit riasztás 10 $ alatt; az Eszközök → AI-kredit oldal alján a Figyelő mutatja a tartalék szerver állapotát. |
| 2026-11-17 | 2.9.303 | Ha egy visszatérő ügyfél új dátummal ír, az automatikusan nyitott dealbe a levélből bekerül a helyszín, a kezdés, az óraszám és a létszám (ha szerepel benne), és a felelős értesítést kap (Új deal egy levélből). |
| 2026-11-17 | 2.9.302 | Figyelők: ha a tartalék szerver (galériák, QR-linkek, AI-képek, mobil app) 10 percig nem válaszol, Ákos és az adminok riasztást kapnak, helyreálláskor is; ha az AI-kredit 5 $ alá esik, egyszer szól. A csatornák (csengő, telefon, Pumble) a Beállítások → Értesítések alatt állíthatók. |
| 2026-11-17 | 2.9.301 | Dinamikus ár: a nappali rendezvény (16:00-ig véget ér) csúcsnapon is kedvezményes (−10 000 Ft), az „utolsó gépek” felár sem jár rá. Beállítható: Beállítások → Dinamikus árazás → Nappali rendezvény. |
| 2026-11-17 | 2.9.300 | Beosztás: ha a rendezvény adatai fixek, a rendezvény sorából közvetlenül a rendezvényre is lehet meghívni – a levél a pontos időponttal, helyszínnel és géppel megy; igen válasznál Ákos véglegesít. |
| 2026-11-17 | 2.9.299 | A beosztás ablaka szélesebb és átláthatóbb: nagyobb gombok (zöld „✓ Ráér”, piros „Nem ér rá”, külön törlés- és hívásgomb), nagyobb választók és címkék. |
| 2026-11-17 | 2.9.298 | Kezelőbeosztás napi alapon: a kezelőket egy napra hívjuk meg (a nap köre), a rendezvényekhez Ákos/admin rendeli őket („Hozzárendel”), extra hely a „+ fő” gombbal, „Értesítés a ki nem osztottaknak” gomb. A régi, rendezvényre szóló meghívások napi meghívássá alakultak. |
| 2026-11-17 | 2.9.296 | Levél – deal: ha a levélben új rendezvénydátum van, akkor is új deal nyílik, ha az ügyfélnek már van nyitott dealje egy másik napra; a dátumot a levél szövegéből is felismeri (pl. „december 10., csütörtök”), a fizetési határidő és hasonlók nem számítanak. |
| 2026-11-17 | 2.9.295 | Levél – deal: a beérkező levél automatikusan csak nyitott dealhez kerül (lezárt deal csak javaslat). Ha egy ismert ügyfélnek nincs nyitott dealje, és a levélben új rendezvénydátum van, a panel automatikusan új dealt nyit neki (Érdeklődő szakasz, a korábbi felelőssel), és a levél oda kerül; a szálban ezt esemény jelzi. |
| 2026-11-17 | 2.9.294 | Hibajavítás: visszatérő ügyfél új ajánlatkérése nem kerül automatikusan a régi, lezárt dealhez (csak ha a levél még a régi rendezvényhez tartozhat: az még előttünk van, vagy legfeljebb 45 napja volt). |
| 2026-11-17 | 2.9.293 | A pipeline-léptetés azonnali: a kártya rögtön átkerül és a Salesmate-be is azonnal beíródik; a „Visszavonás” gomb 5 másodpercig továbbra is elérhető, ilyenkor a panel visszaírja az eredeti szakaszt. |
| 2026-11-17 | 2.9.292 | Hibajavítás: a levélíróba bemásolt szövegben a linkek már ugyanakkora és ugyanolyan betűvel jelennek meg, mint a szöveg többi része (a színük és az aláhúzásuk marad). |
| 2026-11-17 | 2.9.291 | Új: dinamikus árjavaslat (belső) – alapár a hónapra kiküldött ajánlatokból, napi igazítás (gyengébb/erős nap, utolsó gépek, last minute); címkék a Kapacitás naptárban, „Javasolt ár” a deal adatlapon és az Új deal ablakban; Beállítások → Dinamikus árazás. |
| 2026-11-17 | 2.9.289 | Hibajavítás: a levélszálakban az aláírás Facebook- és Instagram-ikonja nem torzul el (eddig lapos oválisnak látszott). |
| 2026-11-16 | 2.9.288 | Beállítások → Láthatóság: a fülek és az Eszközök külön csoportban; minden eszköz (most már a Logisztika és az AI-kredit is) kollégánként be- és kikapcsolható, soronként „mind” gomb. |
| 2026-11-16 | 2.9.287 | Új: Eszközök → AI-kredit – az OpenRouter-egyenleg, hány AI-képre elég, figyelmeztetés, ha kevés, „Kredit feltöltése” gomb és havi összesítő. |
| 2026-11-16 | 2.9.286 | Raktár: a szolgáltatáscsomagoknak saját QR-kódja van (QR gomb a csomagnál) – beolvasva rögtön a kiadás, kint lévő csomagnál a visszavétel nyílik; a csomag rendezvény helyett más helyre (pl. haza) is kiadható. |
| 2026-11-17 | 2.9.285 | Raktár: a „Csomagok” neve „Szolgáltatáscsomagok” lett (fül, gombok, üzenetek, mozgásnapló); mobilon a Raktár fülsávja oldalra húzható. |
| 2026-11-17 | 2.9.284 | Raktár, telefonon: a QR-ablak „Nyomtatás / megosztás” gombja (és a „QR-címkék nyomtatása…”) a címkeképe(ke)t a telefon megosztás menüjébe adja: onnan „Nyomtatás” (AirPrint) vagy a címkenyomtató alkalmazása (pl. Open Label) választható. A külön nyomtatási lap mobilon nem nyílik meg többé (iPhone-on beragadt). |
| 2026-11-17 | 2.9.283 | Raktár: új „Csomagok” nézet – eszközökből és fogyóanyagból összeállított csomagok, kiadás rendezvényre és visszavétel egy lépésben, pipálós listával (a hiányzó darabok kint maradnak és látszanak). |
| 2026-11-17 | 2.9.282 | Raktár: beolvasás után (és az eszköz nevére kattintva) áttekintő lap nyílik: állapot, hely (kint lévőnél a rendezvény), egyedi felirat, csak a kitöltött adatok; nagy gombok: Kiadás rendezvényre / Áthelyezés, illetve kint lévőnél Visszavétel a raktárba; Állapot azonnal átállítható; legutóbbi 4 mozgás. A teljes szerkesztés a „✎ Szerkesztés” gombbal érhető el. |
| 2026-11-17 | 2.9.281 | Raktár címke: az eszközfajta, a sorozatszám és a kód ugyanakkora és ugyanolyan sötét betűvel jelenik meg, mint a többi szöveg (előnézet, másolt kép, PNG és nyomtatott címkelap), hogy a címkenyomtató is olvashatóan nyomtassa. |
| 2026-11-17 | 2.9.280 | Raktár: a címkenyomtatás lapján felül „← Vissza a panelbe” és „Nyomtatás” gomb; nyomtatás után a lap magától bezárul (mobilon is van visszalépés). |
| 2026-11-17 | 2.9.279 | Raktár: a „Kép másolása” és a „PNG letöltése” ugyanazt a fekvő címkét adja, mint az előnézet és a nyomtatott címke (QR balra, szöveg jobbra, 70 × 37 mm arány, 1400 × 740 pixel). |
| 2026-11-17 | 2.9.278 | Raktár: a QR-ablakban „Kép másolása” – a címkekép (QR + feliratok + eventcrazy.hu) a vágólapra kerül, a nyomtató alkalmazásába Ctrl+V / ⌘V-vel beilleszthető. |
| 2026-11-17 | 2.9.277 | Raktár: a QR-ablak a nyomtatandó címke élő előnézetét mutatja (QR, név, fajta, egyedi felirat, sorozatszám, kód, eventcrazy.hu); az egyedi felirat gépelés közben azonnal megjelenik rajta. |
| 2026-11-17 | 2.9.276 | Raktár: az „Új eszköz” űrlap elmagyarázza a felépítést (kategória → eszközfajta → darabok), a kategória az első mező, és jelzi, ha az eszközfajta már létezik. A listában a darabok a fajta alatt csak „#1, #2…” néven látszanak (a címkén továbbra is a teljes név áll). |
| 2026-11-17 | 2.9.274–275 | Raktár: QR-olvasó kamerával („📷 Beolvasás”), egyedi felirat a QR-címkén, törlés (cikk / eszközfajta / darab, megerősítéssel), az eszközök kategóriánként csoportosítva. |
| 2026-11-17 | 2.9.272–273 | Raktár: eszköz felvétele egy lépésben, darabszámmal és soronkénti sorozatszámokkal; látható „✎ Szerkesztés” és „+ Darab” gombok; érthetőbb mezőnevek (Eszközfajta, Egyedi név). A QR-címkéken, a PNG-n és a QR-ablakban szerepel az eventcrazy.hu. |
| 2026-11-17 | 2.9.271 | Levél-állapot: kék boríték = kézbesítve, zöld szem = elolvasva (mobilon ikon a kártya jobb felső sarkában; asztalin a felirat színe is kék / zöld). A részletek ablaka a jelvényre újra koppintva vagy mellé koppintva bezárul. |
| 2026-11-17 | 2.9.270 | Levelezés mobilon: a kiküldött levél állapota ikonként a levélkártya jobb felső sarkában – szürke pipa: kézbesítve, kék dupla pipa: elolvasva. Rákoppintva a részletek (mikor nyitották meg) ugyanúgy látszanak. |
| 2026-11-17 | 2.9.269 | Mobilon a Deal-ek tábla csempéi kb. negyedével alacsonyabbak (kisebb belső tér és betűk), így egyszerre több deal látszik. |

MD;
