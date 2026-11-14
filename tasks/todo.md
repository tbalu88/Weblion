# Teendők: Crazy Booth
- [x] 1. Electron-váz + képernyőfolyamat (próbamód) – felület böngészőben tesztelve; az Electron-futtatás a Windows-gépen
- [~] 2. Kameravezérlés (digiCamControl, D5300) – megírva a dokumentált HTTP-parancsokkal, próba a D5300-zal hátravan
- [ ] Ellenőrzőpont A – próba a D5300-zal
- [x] 1b. Mac-változat webkamerával (0.2) – Electron főfolyamattal itt kipróbálva (webkamera, mentés); nyomtatás a Macen próbálandó
- [x] 3. Sablon a panelből (booth-kulcs, offline gyorstár)
- [x] 4. Nyomtatás DS620 (4×6", 2×6" vágás) – kód kész (0.6.0), valódi nyomtatós próba: B ellenőrzőpont
- [ ] Ellenőrzőpont B – próba nyomtatóval
- [x] 5. Feltöltés + online galéria
- [x] 6. QR-kód
- [x] 7. AI-képek (0.10.0, Nano Banana 2.1)
- [x] 8. Windows-csomag (zip, 0.9.0-tól) + beállítások exportja/importja
- [ ] Ellenőrzőpont C – éles próba a dslrBooth mellett

## Követelmények (2026-11-13)
- [x] K1 kilépés a teljes képernyőből (0.2.1)
- [x] K2 QR a kép után (0.3.0)
- [x] K3 felhő-galéria (tartalék: mu-plugins/crazy-galeria.php) + galéria a gépen, újranyomtatás, QR (0.3.0)
- [x] K4a panel: Booth-gépek kulcsai, a nap rendezvényei, a deal sablonja, galéria-azonosító (booth.php, 2.9.238)
- [x] K4b a deal adatlapján „Galéria” kártya + {{galeria.link}} levélváltozó
- [x] K5 sablonszerkesztés a gépen (a panel Sablonkészítője) – online (0.9) és internet nélkül (0.11.0)
- [x] Online felület (0.4.0): a felület a tartalékról frissül (tools/booth-publish.sh), offline az utolsó letöltött fut
- [x] Windows-telepítő – a fejlesztés végén (a felhasználó kérése: addig csak Mac); wine-nal a felhőben elakadt, Windows-gépen `npm run dist:win`

## Képtörlés + offline sablonszerkesztő (2026-11-13 este – terv: tasks/plan.md vége; KÉSZ, élesítve)
- [x] T1 Galéria 0.9.0: végleges törlés (döntés: azonnal véglegesen; gépkulcs: saját képek; panel: bármelyik)
- [x] T2 Booth 0.11.0: kijelölés + törlés a galériában (gép: Kuka; felhő: sorban)
- [x] T3 Panel 2.9.253: Fotógaléria → Kezelés: végleges törlés (lomtár nincs – döntés)
- [x] Ellenőrzőpont: törlés végig (helyi tesztek + élő csatorna-próba)
- [x] T4 Sablonkészítő + mintaképek + betűk a gépen (offline) + sima fehér keret 1/2/3/fekvő
- [x] T5 „Szerkesztés internet nélkül” a Kezelőben (Rendezvény fül)
- [ ] Ellenőrzőpont: offline próba Macen és Windowson (böngészőben és Electronban kész; valódi gépen a felhasználó)

## Natív iPhone 360-app „Crazy 360” (2026-11-14 – terv: tasks/plan.md vége)
- [x] I1 Projektváz + CI (XcodeGen, SpinKit, macos-26) – zöld
- [x] I2 Képkocka-terv: ív, levágás, oda-vissza, hosszbecslés (SpinKit-tesztek)
- [x] I3 Renderelő: kitöltés, keret + felirat, zene, borítókép (szimulátoros tesztek: sorrend, forgatás, hossz, zene)
- [~] I4 Feltöltés: párosítás, rendezvények, tartós sor, QR – kód + mock-tesztek kész; valódi galériába a felhasználó telefonjáról
- [~] I5 Felvétel: formátum, fps, lencse, visszaszámlálás, távkioldó – kód kész; kamera csak készüléken próbálható
- [~] I6 Felület: felvétel, eredmény, beállítások, videók – kész; felületteszt + legrosszabb adatok a CI-ben
- [x] Ellenőrzőpont D: szimulátoros végigpróba mintavideóval (felületteszt a CI-ben, képernyőképek; legrosszabb adatokkal is – break-ui)
- [~] I7 TestFlight: a CI-lépés és az útmutató (ios/README.md) kész; Apple Developer fiókra vár
- [ ] Ellenőrzőpont E: készülékes próba a platformon
