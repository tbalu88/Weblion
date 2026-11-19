# Terv: több szelfigép egy dealen (2+ gép, gépenként háttér)

## Áttekintés
Ma minden deal legfeljebb **1 szelfigépet** jelent (+ esetleg 1 spinnert). A cél: a dealen megadható legyen a szelfigépek
száma (1–10), gépenként saját háttérrel, és ezt minden kapcsolódó rendszer kövesse: kapacitás, kezelő-slotok,
naptárbejegyzés, kezelői adatlap / elszámolás, ügyfélportál háttérválasztó, háttér-foglaltság, logisztika, árajánlat-javaslat,
szerződés / számla tétel, statisztika.

## Döntések (jóváhagyva 2026-11-19)
- **Tárolás:** a gépszám és a gépenkénti hátterek a panel saját táblájában (`crazy_deal_info`, mint a Rendezvényinfó).
  A Salesmate-ben nincs ilyen mező; a Salesmate „Választott Fotófal” mezőjébe a hátterek összefűzve mennek
  (pl. „Karácsony 3 + Arany csillogó”, 80 karakterig). *Alternatíva:* új Salesmate-mező („Szelfigép darabszám”), ha ti
  létrehozzátok – akkor oda is írnánk.
- **Alapértelmezés:** minden meglévő deal 1 gép → semmi nem változik, amíg valaki át nem írja.
- **Kezelő:** gépenként 1 kezelő-slot (Szelfigép 1, Szelfigép 2 …), a mostani szabályokkal (Budapest: host is mehet,
  vidék: kezelő kell). A mostani „létszám a sloton” beállítás megmarad.
- **Többnapos rendezvény:** a gépszám a dealre szól, minden napra ugyanannyi (napi eltérés később, ha kell).

## Feladatok (sorrendben, mindegyik után élesíthető állapot)
1. **Adatmodell + deal adatlap** (dealinfo.php, crm.js): „Szelfigépek száma” mező és gépenkénti háttér a dealen;
   a Salesmate háttérmezőbe összefűzve. *Kész, ha:* a dealen beállítható, mentés után megmarad. *Teszt:* mock deal oldal.
2. **Kapacitás** (capacity.php, panel.js): a nap foglaltsága a gépszámmal számol (2 gépes deal = 2 hely).
   *Kész, ha:* egy 2 gépes deal napján a szabad helyek 2-vel csökkennek. *Teszt:* PHP-próba élő adaton (csak olvasás).
3. **Kezelő-slotok** (slots.php, panel.js): gépenként külön slot, a beosztás-naptár és a hiány-számítás ezzel számol.
   *Kész, ha:* 2 gépes dealnél 2 slot jelenik meg, külön beosztható. *Teszt:* mock + élő olvasó próba.

   **Ellenőrzőpont A** – közös átnézés veletek egy valódi 2 gépes dealen.

4. **Naptárbejegyzés** (gcal.php): a leírásban „Szelfigépek: 2 db” és gépenkénti háttér; a cím változatlan.
5. **Kezelői adatlap / Elszámolás** (crazy-panel.php, panel.js): gépenként kezelő-sor (a meglévő többsoros szerkezetben).
6. **Ügyfélportál + háttér-foglaltság** (portal-bg.php, portal-form.php): az ügyfél gépenként választ hátteret;
   a háttér-foglaltság a darabszámmal számol (ugyanazt a hátteret kétszer csak akkor, ha van belőle 2).
7. **Logisztika** (logi.php): gépszám és furgonigény a napi útvonalnál.

   **Ellenőrzőpont B** – portál-próba a saját tesztdealen.

8. **Árajánlat-javaslat, szerződés, számla** (estimate*.php, contract.php, billing.php): több gépnél az ár-javaslat helyett
   „egyedi ár” jelzés; a szerződésben és a számlatervezet tételnevében a darabszám (pl. „2 db szelfigép”). *Számla: csak a tervezet tételei – kiállítás továbbra is kézzel.*
9. **Statisztika, Crazy Booth beállítás, útmutató** (stat*.php, booth.php): a darabszám megjelenik; útmutató + memória.

## Kockázatok
| Kockázat | Kezelés |
|---|---|
| Valahol rejtve „1 deal = 1 gép” feltevés marad | Feladatonként keresés a kódban (kind/selfie számlálás), próba 2 gépes mock deallel |
| A Salesmate háttérmező 80 karakteres | Összefűzve, levágás jelzéssel; a teljes lista a panelben |
| Árazás/szerződés hibás összeget ad | Az ár csak javaslat marad; szerződés-tervezet előnézettel, élesítés előtt veletek ellenőrizve |

## Döntések a nyitott kérdésekre
- Gépszám: csak a panelben (Salesmate-mező nincs).
- Árazás: több gépnél mindig egyedi, kézi ár – a panel ár-javaslata nem szoroz, több gépnél jelzi, hogy egyedi ár kell.
- Spinnerből is lehet több (külön darabszám, ugyanaz a logika: kapacitás, slot, naptár, logisztika).
