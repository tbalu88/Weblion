# Crazy Booth – fejlesztési ütemterv a kiválasztott funkciókból

*2026-11-13 · 66 kiválasztott funkció (https://claude.ai/artifact/6UeZhzJmL49DiFpNq3g7Em) · állapot: jóváhagyva, 3. fázis kész (0.5.0)*

A sorrend elve: előbb ami minden rendezvényen kell és a meglévőre épül (galéria, sablon, megosztás), utána a látványos
fotózási módok, majd az AI, végül a nagy, külön hardvert vagy külön alkalmazást igénylő tételek.
Minden fázis végén próba a Macen; a felület online frissül, a „keret” (kamera/nyomtató/fájl) változásnál új telepítő kell.

## 1. fázis – galéria, kiküldés, alapok (a meglévő folytatása)
- g7 Galéria-link a deal adatlapján + `{{galeria_link}}` levélváltozó (később kiküldés)
- g10 Összes kép letöltése egyben (ZIP)
- g4 Arculatos galéria (logó, színek; saját domain később)
- g5 Galéria jelszóval / „csak a saját képeim”
- g6 Képek elrejtése, moderálás (a panelből)
- d6 Személyes adatok automatikus törlése (a 60 napos törléssel együtt)
- v11 Figyelmeztetések (óra, kamera, papír, net)
- v12 Beállítások exportja / importja másik gépre
- s10 Rendezvény mentése újrahasználható sablonként

## 2. fázis – sablon és képernyők (panel 1:1)
- s2 Sablonszerkesztő a gépen (a panel Sablonkészítője)
- s4 Dinamikus szöveg a sablonon (dátum, vendég neve, kérdőív-válasz)
- s5 Képernyők testreszabása rendezvényenként (szín, betű, háttér, gombok)
- s6 Kezdőképernyő ismétlődő videóval
- z8 Animált rátétek és hátterek

## 3. fázis – fotózási módok ✅ (0.5.0, 2026-11-13)
- m11 Módválasztó a kezdőképernyőn (fotó / GIF / csík / videó)
- m3 Animált GIF · m4 Boomerang · m5 Videó rátéttel · m6 Lassított videó · m7 Gyorsítás/lassítás + zene
- m12 Egy kép újrafotózása, „3 pózból a legjobb”
- m13 Csoportkép-mód · m14 Fekete-fehér glam bőrsimítással
- m9 Videós vendégkönyv

## 4. fázis – nyomtatás
- p5 Papírszámláló, figyelmeztetés fogyáskor
- p6 Nyomtatási korlát vendégenként / rendezvényenként
- p11 Színprofil a nyomtatóhoz
- p8 Több nyomtató, terhelés elosztása / tartalék
- p9 Nyomtatás külön gépről (print szerver), távoli figyelés

## 5. fázis – megosztás és adatgyűjtés
- h3 E-mail küldése (arculatos) – a panel levélküldőjén át
- d1 Hozzájárulás / adatkezelési képernyő
- d2 Kérdőív (név, e-mail, telefon, saját kérdések) – s4 és a4 ezt használja
- d5 Adatok exportja, CRM-szinkron (a panel kontaktjaiba)
- h9 Webhook (ügyfélrendszerekbe)
- h2 Internet nélküli QR (helyi Wi-Fi-ről)
- h5 AirDrop (Mac)
- h7 Külön megosztó állomás (második eszköz a sornak)

## 6. fázis – AI (a szolgáltató kiválasztása után)
- a1 AI-karikatúra (elsőként)
- a3 Saját prompt rendezvényenként · a4 Vendégadat a képben · a5 Stílusválasztás fotózás után · a6 Kimenet választása
- a12 Magazinborító, filmplakát, gyűjtőkártya · a11 Céges portré
- a13 Arcellenőrzés (kredit megmarad) · a15 Kreditkeret és napló · a16 AI nélkül is fut tovább
- a7 AI-háttércsere zöld háttér nélkül · z1 Zöld háttér (chroma key)
- a8 Háttérkivágás / elmosás internet nélkül

## 7. fázis – üzemeltetés, kivetítés, statisztika
- v8 Webes vezérlőpult: rendezvény beállítása távolról, szinkron a gépekre
- v7 Távoli figyelés telefonról (állapot, papír, hibák)
- v10 Statisztika és ügyfélriport
- g8 Élő diavetítés TV-n / projektoron · h11 Kép kivetítése TV-re
- v9 Több élmény egy gépen (menü)
- v4 Indítás USB / arcade gombbal
- d4 Kvíz, játék, nyereményjáték

## 8. fázis – hardver és külön termékek (nagy tételek, egyenként egyeztetve)
- w3 Canon DSLR · w5 Kamerabeállítások a programból (ISO, rekesz, záridő)
- m8 360°-os spinner mód (platform-vezérlés)
- m17 Kézi (járkálós) fotós mód
- m16 Matrica-ív (matricanyomtató)
- m18 Fényfestés
- p12 Hashtages Instagram-nyomtatás (az Instagram-hozzáférés korlátai miatt bizonytalan)
- w10 iPad / Android változat (külön alkalmazás)

## Versenytársak felületi mintái (kutatás 2026-11-13: dslrBooth, Snappic, Simple Booth HALO, Fotomaster, Darkroom, Breeze, Touchpix)
Beépítve (0.5.0):
- Beállítások a vendég útja szerinti fülekben (Áttekintés · Rendezvény · Kezdés · Fotózás · Utána · Gép) – HALO, Fotomaster
- Indulás előtti ellenőrzés (kamera, videó, nyomtató, net, gépkulcs, feltöltési sor, rendezvény, sablon, óra) – HALO PreFlight
- PIN után kezelőpult („Fotózás indítása”, szünet, galéria) – HALO Mission Control, Snappic
- Szünet-képernyő üzenettel és automatikus folytatással, kamera ki – Snappic, dslrBooth
- Keresés a beállításokban (egyik versenytársnál sincs)
- Mód szerinti „Készülj!” felirat; külön visszaszámlálás az első és a további képeknél – HALO, Snappic
- Egy kép újrafotózása a csíkban; „3 pózból a legjobb” – Snappic, Breeze
- Alapértékek visszaállítása; sarok 3 mp + PIN – Breeze, Fotomaster
Még hátra (beosztva):
- Élőkép a nyomat képarányára vágva + keret az élőképen (2. fázis kiegészítés, következő felület-frissítés)
- Új rendezvény varázsló 3 lépésben (módok → kinézet → nyomat) – Breeze (7. fázis, v8 mellé)
- Hozzájárulás / adatbekérés a megosztás előtt – HALO (5. fázis, d1/d2)
- „Később érkezik” üzenet net nélkül; QR azonnal – Touchpix (5. fázis)
- Állandó állapotsor a kezelőnek: DNP papír, kamera-akku, feltöltés (4. fázis p5 – egyik versenytársnál sincs)

## Döntést igényel
- AI-szolgáltató és képenkénti költségkeret (6. fázis előtt).
- E-mail: a panel levélküldőjéről menjen (javaslat), feladó és sablon.
- Galéria saját domainje (pl. galeria.eventcrazy.hu) – DNS-beállítás.
- 8. fázis tételei: melyik kell valóban, milyen hardverrel.
