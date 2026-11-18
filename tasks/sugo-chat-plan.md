# Terv: Segítség – AI-chat a panelben (hibaelhárítás, nem fejlesztés)

## Áttekintés
Új „Segítség” menüpont a panelben (minden kollégának). A kolléga leírja a gondját; az asszisztens
1. az útmutató alapján elmondja, hol mit talál és mit hogyan csináljon,
2. megnézi a hiba körülményeit (csak olvasva),
3. néhány előre megadott, biztonságos javítást felajánl – csak a kolléga gombnyomására fut le,
4. ha nem tudja megoldani: egy gombbal kész hibaleírást küld Balázsnak Pumble-re (a beszélgetéssel).
Nem tud: kódot / beállítást módosítani, Salesmate-et írni, ügyfélnek levelet küldeni, számlázni, törölni.

## Döntések (javaslat)
- **AI:** OpenRouter (a meglévő fiók és kulcs a tartalék szerveren van – a panel a galéria-szerveren át hívja, mint az AI-kreditet). Modell: lásd költség.
- **Tudás:** az útmutató (Claude Docs) szövege a panelbe mentve, naponta frissítve; + a kolléga jogosultságai (mit lát).
- **Csak olvasó eszközök** (a kolléga jogain belül): deal állapota / naplója, levélszál és a dealhez kötés oka,
  levélszinkron állapota, értesítési beállítások, saját legutóbbi hibaüzenetei, Crazy Booth gépek állapota.
- **Javító műveletek (fehérlista, megerősítéssel):** levélszinkron újraindítása, levél újrakötése egy dealhez,
  lezárt szál újranyitása, deal visszaállítása a Lomtárból, értesítések ki/be magának. Mind naplózva.
- **Adatvédelem:** ügyféladat csak a kérdéshez szükséges mértékben megy az AI-hoz; a beszélgetés 30 napig marad meg.
- **Korlát:** kollégánként napi keret (pl. 30 üzenet), havi költségplafon; a 10 $-os egyenleg-riasztás ezt is fedi.

## Költség (OpenRouter mai ára, 1 beszélgetés ≈ 4 kérdés-válasz)
| Modell | Ár (be / ki, $ / millió token) | ≈ / beszélgetés | 100 beszélgetés / hó |
|---|---|---|---|
| Claude Haiku 5.5 | 0,10 / 0,50 | ~0,01 $ | ~1–2 $ |
| Claude Sonnet 5.5 | 2 / 10 | ~0,25 $ | ~20–30 $ |
**Döntés (2026-11-18): Haiku 5.5** alapból; a beállításban Sonnet 5.5-re átkapcsolható.

## Feladatok
1. **Tudásanyag** (support.php): az útmutató szövegének napi átvétele, jogosultság-összefoglaló. Kész, ha: a rendszerprompt összeáll. Teszt: PHP-próba.
2. **AI-hívás** (galéria-szerver /support végpont + panel): OpenRouter, eszközhívással, kerettel, naplóval. Teszt: próba-kérdés.
3. **Olvasó eszközök** (support-tools.php): a fenti lekérdezések, a kolléga jogaival. Teszt: PHP-próba eszközönként.
4. **Javító műveletek** (fehérlista, megerősítő gomb a chatben, naplózás). Teszt: mock + élő próba saját adaton.
5. **Felület** (assets/support.js, impeccable): Segítség menüpont, chat, javaslat-gombok, „Küldés Balázsnak” gomb. Telefonon is. Teszt: mock 390 / 1280 px, break-ui.
6. **Pumble-továbbítás** (a meglévő értesítőn át). 7. **Útmutató + napló.**

Ellenőrzőpont az 1–3. után (jól válaszol-e valós kérdésekre), élesítés az 5. után.

## Kockázatok
| Kockázat | Kezelés |
|---|---|
| Rossz tanács | Csak az útmutatóból és a lekérdezett adatból válaszol; ha bizonytalan, Balázshoz irányít |
| Költség elszáll | Napi keret / kolléga, havi plafon, egyenleg-riasztás |
| Ügyféladat az AI-nál | Csak a szükséges mezők; nincs teljes levéltörzs, csak kivonat |
