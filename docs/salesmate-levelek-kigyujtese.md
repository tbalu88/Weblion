# Salesmate-levelek kigyűjtése (Claude a Chrome-ban)

Előkészítés: WP-admin → Beállítások → Crazy levelezés → „Salesmate-levelek átvétele” → **Deal-lista letöltése (CSV)**.
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
