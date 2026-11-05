# Crazy panel – UX-ötlettár (félretett javaslatok)

Ha a felhasználó új fejlesztési/UX-javaslatokat kér, ezeket is fel kell ajánlani (a 2026-10-31-i listából
a 2., 3., 7. pont elkészült; a többi ide került). Elkészülés után innen törölni.

| # | Javaslat | Becslés |
|---|---|---|
| 1 | Szakaszváltás mobilon húzás nélkül: hosszan nyomva a csempén menü (Áthelyezés → szakaszlista, Megnyert, Elvesztett, Jegyzet) | ~0,5 nap |
| 4 | Gyorsgombok a csempén a jegyzet mellé: hívás (tel:), e-mail (új levél a kontaktnak), teendő – mint a Salesmate-ben | ~0,5 nap |
| 5 | Telepíthető app (PWA): kezdőképernyő-ikon, teljes képernyő, gyorsabb indulás; push-értesítés új ajánlatkérésnél és megemlítésnél | ~1 nap |
| 6 | Deal-adatlap mobilon fülekre bontva (Áttekintés · Idővonal · Ügyfél · Pénzügy), rögzített gombsor | ~1 nap |
| 8 | „Következő lépés” sáv szakaszonként (pl. Szerződés kiküldve után 3 nappal: rákérdezni az aláírásra), egy kattintással teendő | ~0,5 nap |
| 10 | Sablonok „/” jellel a válaszmezőben, a deal adataival kitöltve (név, dátum, becsült ár) | ~0,5 nap |
| 11 | Egységes gombstílus minden fülön (a deal-adatlap sötétkék gombjai a Kezdőlapon, Kapacitáson, Levelezésben is) | ~0,5 nap |
| 12 | Sötét mód (a telefon beállítása szerint) | ~1 nap |
| 13 | Bővített Ctrl+K kereső parancsokkal („új deal”, „ugrás 12.18-ra a Kapacitásban”, „Levelezés: kiosztatlan”) | ~0,5 nap |
| 14 | Betöltési váz (skeleton) a „Betöltés…” szöveg helyett | ~0,5 nap |
| – | Kapacitás: év/hónap nézetben a szabad gépekre szűrt gyors ajánlatadás; Mindkettő kalkulátor a panelben (telefonos ajánlatadáshoz) | ~1 nap |
| – | Becsült ár árlistája a panel Beállításaiban szerkeszthető (most a kódban és a weboldali kalkulátorban külön) | ~0,5 nap |

## 2026-11-04: folyamat-egyszerűsítés és új funkciók (felajánlva, még nem kért)

| # | Javaslat | Becslés |
|---|---|---|
| F2 | Szerződés elfogadása kattintással az adatlapon (PDF + időbélyeg), díjbekérő egy kattintással | ~1,5 nap |
| F3 | Dátum szerinti automatikus szakaszváltás (Felkészül, Lement, Pénzre várunk, Megnyert) – átállás után | ~0,5 nap |
| F4 | Elakadt ajánlat: 5 nap után teendő, 14 nap után Elvesztett-javaslat | ~0,5 nap |
| N3 | Megválaszolatlan levél figyelő: 24 munkaórája válasz nélküli megkeresés → jelzés a felelősnek | ~0,5 nap |
| N5 | Értékeléskérő levél a galériával (Google-értékelés link), jóváhagyással | ~0,5 nap |
| N6 | Fizetési emlékeztető levél lejárt számlánál, egy kattintással | ~0,5 nap |

## Félretéve (2026-11-05): foglalási link az ajánlatlevélben – az adminisztrációval egyeztetni kell
- Kész, de nem élesített kód: kapacitás-ellenőrzés kattintáskor (az „Adatlap kiküldve” dealeket is levonja), napi zár,
  a levélszkennerek ellen csak gombnyomásra foglal, telt háznál „keressenek meg” gomb + értesítés. A kód a munkamenet
  jegyzetei között van (booking.php); újraélesítéshez a panelhez kell kötni és az ajánlatlevélbe tenni.
