# Crazy panel – UX-ötlettár (félretett javaslatok)

Ha a felhasználó új fejlesztési/UX-javaslatokat kér, ezeket is fel kell ajánlani (a 2026-10-31-i listából
a 2., 3., 7. pont elkészült; a 12. (sötét mód) 2026-11-06-án, v2.7.10–2.7.12; az 5. (PWA + push), U1, U2 2026-11-06-án (v2.7.13, v2.8.0; a szakaszváltás visszavonása még nincs kész); U3 szundi és U5 billentyűparancsok (v2.8.2; a „rendezvény előtt 3 nappal” szundi-lehetőség még nincs); a többi ide került). Elkészülés után innen törölni.

| # | Javaslat | Becslés |
|---|---|---|
| 1 | Szakaszváltás mobilon húzás nélkül: hosszan nyomva a csempén menü (Áthelyezés → szakaszlista, Megnyert, Elvesztett, Jegyzet) | ~0,5 nap |
| 4 | Gyorsgombok a csempén a jegyzet mellé: hívás (tel:), e-mail (új levél a kontaktnak), teendő – mint a Salesmate-ben | ~0,5 nap |
| 6 | Deal-adatlap mobilon fülekre bontva (Áttekintés · Idővonal · Ügyfél · Pénzügy), rögzített gombsor | ~1 nap |
| 8 | „Következő lépés” sáv szakaszonként (pl. Szerződés kiküldve után 3 nappal: rákérdezni az aláírásra), egy kattintással teendő | ~0,5 nap |
| 10 | Sablonok „/” jellel a válaszmezőben, a deal adataival kitöltve (név, dátum, becsült ár) | ~0,5 nap |
| 11 | Egységes gombstílus minden fülön (a deal-adatlap sötétkék gombjai a Kezdőlapon, Kapacitáson, Levelezésben is) | ~0,5 nap |
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

## Kezelők fül – továbbfejlesztési javaslatok (2026-11-05)
| # | Javaslat | Becslés |
|---|---|---|
| K1 | Adatbekérő link: a kezelő saját maga tölti ki / javítja a hiányzó adatait (egyszer használatos link, mint az ügyfélportál) | ~1 nap |
| K2 | Beosztás: a dealen a „Gépkezelő” mező a Kezelők listájából választható, ütközésjelzéssel (egy napon két helyre ne) | ~1 nap |
| K3 | Kezelői értesítő: a rendezvény előtt e-mail / Pumble a kezelőnek (cím, időpont, kapcsolattartó, gép, háttér) | ~0,5 nap |
| K4 | Megbízási szerződés / teljesítésigazolás generálása a kezelő adataiból (a szerződésmodulból) | ~1 nap |
| K5 | Havi kezelői összesítő a Kezelői elszámolásból kezelőnként (alkalmak, órák, km, kifizetendő), CSV a könyvelőnek | ~0,5 nap |
| K6 | Okmányok és lejáratok (jogosítvány, egészségügyi kiskönyv), figyelmeztetés lejárat előtt | ~0,5 nap |

## UI/UX-javaslatok (2026-11-06, felajánlva, még nem kért) – a Superhuman, Front, Linear, HubSpot, Pipedrive mintájára
| # | Javaslat | Minta | Becslés |
|---|---|---|---|
| U4 | Mentett nézetek a Levelezésben (pl. „Válaszra vár”) – a Deal-eken 2026-11-08-án elkészült (2.9.53) | Linear, HubSpot | ~0,5 nap |
| U6 | Előnézet rámutatásra: ügyfél / deal neve fölött kis kártya (telefon, nyitott deal, utolsó levél, visszatérő-e) | Linear, Front | ~0,5 nap |
| U8 | „Mai fókusz” a Kezdőlapon: egy listában a mai teendők, válaszra váró levelek, lejárt határidők, kipipálhatóan | Linear „My issues”, HubSpot Tasks | ~0,5 nap |
| U9 | Mikro-visszajelzések: sima átmenetek fülváltáskor, mentéskor kis pipa, egységes értesítő buborék egy helyen | Linear, Stripe | ~0,5 nap |
| U10 | Akadálymentesség és érintés: látható fókuszkeret billentyűzetnél, legalább 44 px-es érintési felületek mobilon, kontraszt-ellenőrzés | Apple HIG | ~0,5 nap |

## Teljesítmény (2026-11-09, a felhasználó jónak tartja, későbbre félretéve)
| # | Javaslat | Becslés |
|---|---|---|
| P1 | Könnyített „változott-e valami?” végpont a háttér-lekérdezésekhez (Deal-ek percenként, Levelezés percenként, értesítések, jelenlét): WordPress-bővítmények betöltése nélkül, egy kis időbélyeget nézve; teljes adat csak változáskor. Most minden ilyen kérés teljes WordPress-betöltés (lassú tárhelyen 2–3 s). | ~0,5 nap |
| P2 | A levél „ki nézi / ki válaszol” jelenlét-pingje 15 helyett 30 másodpercenként (felére csökkenti ezeket a kéréseket) | ~10 perc |
