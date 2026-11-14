# Crazy Booth – fejlesztési ütemterv a kiválasztott funkciókból

*2026-11-13 · 66 kiválasztott funkció (https://claude.ai/artifact/6UeZhzJmL49DiFpNq3g7Em) · állapot: jóváhagyva, 3. fázis kész (0.5.0)*

A sorrend elve: előbb ami minden rendezvényen kell és a meglévőre épül (galéria, sablon, megosztás), utána a látványos
fotózási módok, majd az AI, végül a nagy, külön hardvert vagy külön alkalmazást igénylő tételek.
Minden fázis végén próba a Macen; a felület online frissül, a „keret” (kamera/nyomtató/fájl) változásnál új telepítő kell.

## 1. fázis – galéria, kiküldés, alapok (a meglévő folytatása)
- g7 Galéria-link a deal adatlapján + `{{galeria_link}}` levélváltozó (később kiküldés) ✅
- g10 Összes kép letöltése egyben (ZIP) ✅ (javítva: galéria 0.8.1)
- g4 Arculatos galéria (logó, színek; saját domain később) – HÁTRA
- g5 Galéria jelszóval / „csak a saját képeim” ✅ (jelszó; „csak a saját képeim” = QR)
- g6 Képek elrejtése, moderálás (a panelből) ✅ (+ végleges törlés: panel 2.9.253, gép 0.11.0)
- d6 Személyes adatok automatikus törlése (a 60 napos törléssel együtt) ✅
- v11 Figyelmeztetések (óra, kamera, papír, net) ✅
- v12 Beállítások exportja / importja másik gépre ✅
- s10 Rendezvény mentése újrahasználható sablonként ✅

## 2. fázis – sablon és képernyők (panel 1:1)
- s2 Sablonszerkesztő a gépen (a panel Sablonkészítője) ✅ online (panel-ablak, 0.9) és internet nélkül (0.11.0: Kezelő → Rendezvény → „Szerkesztés internet nélkül”, sima fehér keret 1/2/3/fekvő)
- s4 Dinamikus szöveg a sablonon (dátum, vendég neve, kérdőív-válasz) – HÁTRA
- s5 Képernyők testreszabása rendezvényenként (szín, betű, háttér, gombok) – részben (feliratok, szín; betű, háttér, gombok hátra)
- s6 Kezdőképernyő ismétlődő videóval – HÁTRA
- z8 Animált rátétek és hátterek – HÁTRA

## 3. fázis – fotózási módok ✅ (0.5.0, 2026-11-13)
- m11 Módválasztó a kezdőképernyőn (fotó / GIF / csík / videó)
- m3 Animált GIF · m4 Boomerang · m5 Videó rátéttel · m6 Lassított videó · m7 Gyorsítás/lassítás + zene
- m12 Egy kép újrafotózása, „3 pózból a legjobb”
- m13 Csoportkép-mód · m14 Fekete-fehér glam bőrsimítással
- m9 Videós vendégkönyv

## 4. fázis – nyomtatás ✅ (0.6.0, 2026-11-13; galéria 0.4.0: nyomtatási sor)
- p5 Papírszámláló, figyelmeztetés fogyáskor
- p6 Nyomtatási korlát vendégenként / rendezvényenként
- p11 Színprofil a nyomtatóhoz
- p8 Több nyomtató, terhelés elosztása / tartalék
- p9 Nyomtatás külön gépről (print szerver), távoli figyelés

## 5. fázis – megosztás és adatgyűjtés ✅ (0.7.0, panel 2.9.245, galéria 0.5.0 – 2026-11-13)
*Döntések: e-mail a panel levélküldőjéről; vendégadat csak a panelbe, a dealhez (Salesmate-be nem); webhook csak linket küld.*
- h3 E-mail küldése (arculatos) – a panel levélküldőjén át
- d1 Hozzájárulás / adatkezelési képernyő
- d2 Kérdőív (név, e-mail, saját kérdések) – s4 és a4 ezt használja. *2026-11-13: telefonszám nem kell; az e-mailes küldésnél csak e-mail + név, a saját kérdések csak a fotózás előtti kérdőívben.*
- d5 Adatok exportja, CRM-szinkron (a panel kontaktjaiba)
- h9 Webhook (ügyfélrendszerekbe)
- h2 Internet nélküli QR (helyi Wi-Fi-ről)
- h5 AirDrop (Mac)
- h7 Külön megosztó állomás (második eszköz a sornak)

## 6. fázis – AI ✅ nagyrészt (0.10.0, galéria 0.8.0, panel 2.9.251 – 2026-11-13; modell: Nano Banana 2.1 a felhasználó döntése)
*Kész: a1 karikatúra, a3 saját prompt rendezvényenként, a4 {nev}/{rendezveny}/{datum} a promptban, a5 stílusválasztás fotózás után, a6 kimenet (AI / AI + eredeti), a11 céges portré, a12 magazinborító/filmplakát/gyűjtőkártya, a15 keret + napló + havi összesítő, a16 AI nélkül is fut (visszaesés az eredeti fotóra). Hátra: a13 arcellenőrzés, a7 AI-háttércsere (saját stílussal már kérhető), z1 zöld háttér, a8 offline háttérkivágás.*
- a1 AI-karikatúra (elsőként)
- a3 Saját prompt rendezvényenként · a4 Vendégadat a képben · a5 Stílusválasztás fotózás után · a6 Kimenet választása
- a12 Magazinborító, filmplakát, gyűjtőkártya · a11 Céges portré
- a13 Arcellenőrzés (kredit megmarad) · a15 Kreditkeret és napló · a16 AI nélkül is fut tovább
- a7 AI-háttércsere zöld háttér nélkül · z1 Zöld háttér (chroma key)
- a8 Háttérkivágás / elmosás internet nélkül

## 7. fázis – üzemeltetés, kivetítés, statisztika ✅ (0.8.0, panel 2.9.246, galéria 0.6.0 – 2026-11-13)
*v8: a deal adatlapján „Beállítások a gépekre” (a gépek percenként jelentkeznek és átveszik); v7: gépállapot a deal kártyáján; v10: statisztika + ügyfélriport; g8/h11: ?vetites=1 diavetítés, a friss kép kiemelve; v9: a módválasztó; v4: indítógomb; d4: sorsolás (kvíz/játék még nincs).*
- v8 Webes vezérlőpult: rendezvény beállítása távolról, szinkron a gépekre
- v7 Távoli figyelés telefonról (állapot, papír, hibák)
- v10 Statisztika és ügyfélriport
- g8 Élő diavetítés TV-n / projektoron · h11 Kép kivetítése TV-re
- v9 Több élmény egy gépen (menü)
- v4 Indítás USB / arcade gombbal
- d4 Kvíz, játék, nyereményjáték

## 8. fázis – hardver és külön termékek (nagy tételek, egyenként egyeztetve)
*2026-11-13 – a felhasználó választása: kell: w3+w5, m8, m17, m16, Windows+D5300, w10; nem kell: m18, p12.*
*Kész: w5 DSLR-beállítások (0.9.0), w3 Canon digiCamControl-lal (tesztelendő), m16 matrica-ív, m8 360° (platform kézi indítással; a vezérléshez a platform típusa kell), Windows zip (0.9.0), m17 + w10: mobil PWA (https://tartalek.eventcrazy.hu/galeria/app/ – fotós mód telefonon, szelfigép mód tableten).*
- w3 Canon DSLR · w5 Kamerabeállítások a programból (ISO, rekesz, záridő)
- m8 360°-os spinner mód (platform-vezérlés) – 2026-11-14: NEM KELL (a 360-at iPhone-nal csinálják, iPad a megosztó állomás)
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
- (0.7.0) Hozzájárulás + adatbekérés, „Rögzítettük, amint lesz internet, elküldjük” – HALO, Touchpix; érintős magyar billentyűzet
- (0.6.0) Élőkép-keret a nyomat képarányára – Breeze; papír- és nyomtató-állapot az ellenőrzésben és a kezdőképernyőn
Még hátra (beosztva):
- Új rendezvény varázsló 3 lépésben (módok → kinézet → nyomat) – Breeze (7. fázis, v8 mellé)
- Kamera-akku kijelzése (a D5300-hoz, 8. fázis); papír és nyomtató-állapot kész (0.6.0)

## Döntést igényel
- AI-szolgáltató és képenkénti költségkeret (6. fázis előtt). ✅ Nano Banana 2.1; a prompt marad a mostani (2026-11-14).
- Galéria saját domainje: galeria.eventcrazy.hu – JÓVÁHAGYVA 2026-11-14; DNS (A → 85.155.240.50) + CloudPanel (server_name + Let's Encrypt) a felhasználónál, utána a linkek átállítása (a régi tartalek-linkek maradnak).
- 8. fázis tételei: melyik kell valóban, milyen hardverrel.
