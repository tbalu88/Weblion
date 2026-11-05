# Salesmate-levelek kigyűjtése (Claude a Chrome-ban)

Előkészítés: https://eventcrazy.hu/wp-admin/options-general.php?page=cp-mail-import (WP-admin → Beállítások → Crazy panel → Levelezés fül), alul „Salesmate-levelek átvétele” → **Deal-lista letöltése (CSV)**.
A lista elején a nyitott dealek vannak; a „mar_atveve” oszlop mutatja, melyik van már kész.

Ezt másold be a Chrome-bővítményben futó Claude-nak (a Salesmate-be belépve), a CSV soraival együtt (egyszerre 20–30 deal):

---
A következő Salesmate-dealeken menj végig egyenként (a link a második oszlopban). Mindegyiknél nyisd meg az **Emails** fület,
és minden levelet nyiss le teljesen (a szálon belüli korábbiakat is, ha egymásba vannak csukva). Semmit ne módosíts, ne küldj,
ne kattints a Reply / Delete / Won / Lost gombokra.

Minden levélből jegyezd fel: irány (mi küldtük: "out", az ügyfél küldte: "in"), feladó (név és cím), címzett(ek), másolat (cc),
dátum és idő (ÉÉÉÉ-HH-NN ÓÓ:PP), tárgy, a levél teljes szövege (az idézett korábbi levelek nélkül, ha egyértelműen elválik),
a mellékletek neve.

A végén adj egyetlen JSON-t, pontosan ebben a formában, más szöveg nélkül:
[ { "deal": 23346, "emails": [ { "dir": "out", "from": "EventCrazy <info@eventcrazy.hu>", "to": "Nagy Anita <anita@example.com>",
"cc": "", "date": "2026-11-02 12:18", "subject": "…", "body": "…", "attachments": ["ajanlat.pdf"] } ] } ]
Ha egy dealnél nincs levél, akkor: { "deal": 12345, "emails": [] }.
---

A kapott JSON-t illeszd be ugyanott („Levelek átvétele”). Ami már megvan a panelben, nem kerül be kétszer, így
nyugodtan újra beilleszthető. A levelek a dealhez kötve, „Salesmate-archívum” jelöléssel jelennek meg (archivált szálként).

## Automatikus átvétel (ajánlott, egy futás)
1. https://eventcrazy.hu/wp-admin/options-general.php?page=cp-mail-import → alul „Automatikus átvétel” → **Program létrehozása**
   → a megjelenő szöveget másold ki (kattints bele, Ctrl+A, Ctrl+C). 48 óráig érvényes.
2. Chrome-ban nyisd meg a Salesmate-et (belépve), nyomd meg az **F12**-t → **Console** fül. Ha a Chrome kéri, írd be: `allow pasting`, Enter.
3. Illeszd be a programot (Ctrl+V), Enter. Ha kéri, kattints a Salesmate-ben bármelyik menüpontra (pl. Deals).
4. A jobb alsó sarokban egy dobozban látod a haladást. A program 100 dealenként egy fájlt ment a Letöltések mappába (salesmate-levelek-01.json …); ha a Chrome rákérdez, engedélyezd a több fájl letöltését. A lapot hagyd nyitva, amíg ki nem írja: **KÉSZ**.
5. Az admin oldalon a „Fájlok betöltése” résznél jelöld ki az összes letöltött fájlt, és nyomd meg a gombot (a betöltés akár közben is mehet, a már kész fájlokkal).
A Salesmate-ben semmit nem módosít; a friss (14 napon belüli) olvasatlan beszélgetéseket kihagyja, hogy ott ne legyenek olvasottnak jelölve.
Ha megszakad, egyszerűen futtasd újra (új programmal): ami már bekerült, nem duplázódik.
