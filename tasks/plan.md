# Kidolgozási terv: egységes „Szolgáltatás(ok)” mező + új árazás + HIBRID

*2026-11-12 · Crazy panel · állapot: KÉSZ, bekapcsolva 2026-11-12 (2.9.220)*

## Cél

A deal szerkesztőjében és adatlapján **egy** szolgáltatásmező legyen: „Szolgáltatás(ok)”, jelölőnégyzetekkel (a mostani EventCrazy-lista + **Hibrid**). Eltűnik: „Szolgáltatás” (szöveg/lista), „Választott csomag”, „Választott szolgáltatás”, „További szolgáltatások”, „Választott extra”. Az árbecslés ne a régi Salesmate-csomagmezőkből számoljon, hanem a kiválasztott szolgáltatásokból + óraszámból.

## Alapelvek (a biztosítékok)

1. **A Salesmate-be semmi nem íródik** ehhez a munkához. A régi mezők a Salesmate-ben és a panel szinkronizált táblájában **érintetlenek** maradnak (csak olvassuk őket).
2. **Nincs adatmásolás / tömeges átírás.** A régi dealek szolgáltatásait a panel **olvasáskor** számolja ki a régi mezőkből egy átnevezési táblával („származtatott érték”). Ami eddig a dealen volt, az ugyanúgy megmarad; ha valami rosszul képeződik le, a tábla javításával azonnal minden deal helyreáll – nincs mit visszaállítani.
3. **Saját tárolás a panelben:** ha valaki a panelben módosítja egy deal szolgáltatásait, az egy **külön panel-táblába** kerül (`crazy_deal_svc`: deal_id, services, ki, mikor). Ezt a Salesmate-szinkron nem írhatja felül, és a régi mezőket nem módosítja. Ha egy dealnek van ilyen sora, az az érvényes; ha nincs, a származtatott érték.
4. **Kapcsoló (feature flag):** az új működés egy beállítással kapcsolható be (`cp_svc_v2`). A kód előbb kikapcsolva kerül élesre, lefut az összehasonlítás, és csak utána kapcsolom be. **Visszaállás: a kapcsoló kikapcsolása** – egy lépés, adatvesztés nélkül.
5. **A deal értéke soha nem változik magától.** Az árbecslés csak megjelenik (csempe, adatlap, „Ajánlat küldése” előtöltése); a deal értékét továbbra is csak ember írja át.

## Az átnevezési tábla (régi érték → új szolgáltatás)

| Régi mező / érték | Új érték |
| --- | --- |
| EventCrazy szolgáltatás(ok): minden érték | változatlan |
| Választott szolgáltatás: Szelfigép / AI karikatúra / VR élmény / Greenbox / Digitális karikatúra / AI fotózás / Élőzene / LED Tánctér / VIP Spinner / Karaoke | ugyanaz az EventCrazy-név (kis-/nagybetű egységesítve) |
| 360 Spinner, Spinner | SpinnerClassic |
| Overhead 360, Oveerhead Spinner | OverheadSpinner |
| Mini szelfitükör | Selfie Tükör |
| Rendezvény Fotó & Videó | Rendezvényfotó + Rendezvény videó |
| Karikatúra | Digitális Karikatúra |
| Selfie Light | SelfieLight |
| Fotó | Rendezvényfotó |
| Videó | Rendezvény videó |
| DanceFloor | LED Tánctér |
| Szolgáltatás (szöveg): Fotóautomata | Szelfigép |
| Szolgáltatás: Spinner | SpinnerClassic |
| Szolgáltatás: Fotóautomata & Spinner | Szelfigép + SpinnerClassic |
| Szolgáltatás: VR | VR Élmény |
| Szolgáltatás: Karikatúra | Digitális Karikatúra |
| Szolgáltatás: Selfie Light / Oveerhead Spinner / VIP Spinner / Fotó / Mindflex / DanceFloor / Crazy Cathc | SelfieLight / OverheadSpinner / VIP Spinner / Rendezvényfotó / Mindflex / LED Tánctér / CrazyCatch |
| „Fotózás 2 fotóssal” | **Fotózás 2 fotóssal** (új, külön opció) |
| CrazyMelange, egyéb egyedi szövegek | **Egyéb** (az eredeti szöveg megjegyzésként látszik) |
| Választott extra (TV-s megjelenítés, kellékszettek, színpad branding) | nem szolgáltatás → az adatlapon „Régi extrák” sorban, csak olvasható (26 deal) |

Az átnevezés csak akkor ad hozzá értéket, ha az EventCrazy-mező üres **vagy** a régi érték még nincs benne – duplikáció nincs.

## Új árazás

- Minden szolgáltatás az **EventCrazy-árlistából** (óradíjas táblák, szezon/hétköznap-hétvége szerint – ez már most is így megy az EventCrazy-dealeknél), az **óraszám** alapján. A „Választott csomag” (Mini/Medium/Mega/Carneval) szöveg nem számít többé.
- **Hibrid (Szelfigép + AI karikatúra):** 3 óra 130 000 Ft, 4 óra 150 000 Ft, utána óránként +20 000 Ft (nettó; döntés 2026-11-12).
- Óraszám nélkül: 3 órával, „közelítő” jelöléssel (mint most).
- Kiszállás: változatlan (Budapest szolgáltatásonként, vidéken 130 Ft/km oda-vissza; a deal értékébe nem számít bele).
- Magánszemély (Gmail stb.): a szelfigép és a spinner **Crazyphoto-áron**, bruttóban, az óraszámból (3 = Mini, 4 = Medium, 5 = Mega, 6 = Carneval) – a csomagnév-szöveg nem számít (döntés 2026-11-12).

## Érintett pontok (mindegyik az egységes értéket kapja)

| Hol | Mit használ most | Változás |
| --- | --- | --- |
| Deal szerkesztő, új deal, inline szerkesztés | 5 mező | 1 mező (jelölőnégyzetek) |
| Deal adatlap „Rendezvény” blokk | 5 mező | „Szolgáltatás(ok)” sor |
| Árbecslés (csempe, adatlap, Ajánlat küldése) | csomag + szöveg | szolgáltatások + óra |
| Szerződés (melyik szolgáltatás-szöveg) | mezők szövege | egységes lista + a régi szövegek (tartalékként) |
| Díjbekérő tételneve, fotós-felismerés | csomag / szöveg | egységes lista + régiek tartalékként |
| Automatizmusok (Pumble-üzenet), levélváltozó `{{szolgaltatas}}`, visszatérő ügyfelek | szöveg + EC | egységes lista |
| Szűrők (Deal-ek lista) | külön mezők | új „Szolgáltatás(ok)” szűrő; a régiek a szűrőben maradnak (régi adatok kereséséhez) |
| AI kitöltés („Deal az e-mailből”) | services_ec | egységes mező (panel-tábla) |
| Webes űrlapok / leadek | Salesmate-mezők | **ebben a körben nem változik** – az új deal a beküldött értékekből származtatva jelenik meg; az űrlapok átírása (Hibrid is) külön, későbbi terv |

## Feladatok

### 1. fázis – alap (kikapcsolt kapcsolóval élesíthető, semmi nem látszik)
- [x] **1. Átnevezési tábla + `cp_deal_svc($d)`** (új fájl `services.php`): a deal érvényes szolgáltatáslistája (panel-tábla → különben származtatott). *Kész, ha:* a próba mind a 4356 dealre lefut hiba nélkül; nincs olyan deal, ahol eddig volt szolgáltatás és most üres lenne.
- [x] **2. Panel-tábla `crazy_deal_svc` + mentés végpont** (csak panel, Salesmate-írás nélkül). *Kész, ha:* mentés után a szinkron futása sem írja felül; a Salesmate-be nem megy kérés (naplóval ellenőrizve).
- [x] **3. Új árazó `cp_est_v2($d)`** (az EventCrazy-tábla + Hibrid). *Kész, ha:* a próbán a nyitott dealekre a régi és új becslés összevetése elkészül.

### Ellenőrzőpont A – próbafuttatás (élesben, csak olvasás)
- [x] Riport mind a 359 nyitott dealre: régi szolgáltatás-szöveg → új lista; régi becslés → új becslés; szerződés-/díjbekérő-felismerés régi → új. Az eltéréseket kategorizálva elküldöm neked, **jóváhagyásig nem kapcsolok be semmit.**

### 2. fázis – bekötés (a kapcsoló mögött)
- [x] **4. Szerkesztő + adatlap + új deal + inline** (crm.js): egy mező; Hibrid a listában.
- [x] **5. Fogyasztók:** szerződés, díjbekérő, automatizmus, levélváltozó, visszatérő ügyfelek, szűrő, AI-kitöltés → `cp_deal_svc`.
- [x] **6. Árbecslés bekötése** (csempe, adatlap, Ajánlat küldése).

### Ellenőrzőpont B – kettős ellenőrzés
- [x] **1. kör (gépi):** ugyanaz a riport a kapcsoló **bekapcsolt** állapotára, csak a próbán belül (élesen még ki van kapcsolva): szerződés-típus, díjbekérő-tétel, Pumble-szöveg és levélváltozó minden nyitott dealre régi vs. új; elvárt: csak a tervezett eltérések.
- [x] **2. kör (független):** egy külön ügynök, aki nem látja a terveimet, csak a kódot és a riportot, átnézi a változást és keres összekeveredést (duplikáció, elveszett érték, Salesmate-írás).
- [x] Helyi böngészőteszt: szerkesztő, mentés, adatlap, becslés, régi deal megnyitása, legrosszabb adatok (sok szolgáltatás, üres, csak régi mező).

### 3. fázis – bekapcsolás
- [x] **7. Kapcsoló be**, utána: 200-as válasz, 5 véletlen nyitott deal kézi összevetése, útmutató frissítése. Egy hétig a kapcsoló ki is kapcsolható; utána a régi kód kivehető (külön kérésre).

## Kockázatok

| Kockázat | Hatás | Kezelés |
| --- | --- | --- |
| Rossz átnevezés (pl. „Spinner” mást jelent) | közepes | csak olvasáskor számolt; a táblát javítva azonnal minden helyreáll; riport előtte |
| A szerződés/díjbekérő más szöveget kap | magas | régi szövegek tartalékként; gépi összevetés minden nyitott dealre |
| Panel-tábla és Salesmate eltér (valaki a Salesmate-ben módosít) | alacsony | a panel-tábla nyer; az adatlapon jelölés, ha a Salesmate-ben azóta más került be |
| Új árak eltérnek a megszokottól | közepes | csak becslés, a deal értékét nem írja át; riportban összevetve |
| Webes űrlapok | alacsony | nem nyúlok hozzájuk; az új dealek származtatva jelennek meg |

## Döntések (2026-11-12)
- Salesmate-ben semmi nem módosul.
- Hibrid: 130 / 150 ezer (3 / 4 óra), utána +20 ezer/óra, nettó.
- Gmail / magánszemély: Crazyphoto-árak maradnak.
- „Fotózás 2 fotóssal” külön opció.

## Utómunka (külön kérésre)
- Deal-ek lista szolgáltatás-szűrője és az automatizmus-feltételek még a régi mezőkből szűrnek.
- Weboldal-űrlapok átírása (Hibrid is) – külön terv.
- Egy hét után a régi kód kivehető.

## Nyitott kérdések
- Weboldal-űrlapok átírása most vagy később (javaslat: később, külön tervvel).
