# Teendők: Crazy Booth
- [x] 1. Electron-váz + képernyőfolyamat (próbamód) – felület böngészőben tesztelve; az Electron-futtatás a Windows-gépen
- [~] 2. Kameravezérlés (digiCamControl, D5300) – megírva a dokumentált HTTP-parancsokkal, próba a D5300-zal hátravan
- [ ] Ellenőrzőpont A – próba a D5300-zal
- [x] 1b. Mac-változat webkamerával (0.2) – Electron főfolyamattal itt kipróbálva (webkamera, mentés); nyomtatás a Macen próbálandó
- [ ] 3. Sablon a panelből (booth-kulcs, offline gyorstár)
- [ ] 4. Nyomtatás DS620 (4×6", 2×6" vágás)
- [ ] Ellenőrzőpont B – próba nyomtatóval
- [ ] 5. Feltöltés + online galéria
- [ ] 6. QR-kód
- [ ] 7. AI-képek
- [ ] 8. Windows-telepítő + beállítások
- [ ] Ellenőrzőpont C – éles próba a dslrBooth mellett

## Követelmények (2026-11-13)
- [x] K1 kilépés a teljes képernyőből (0.2.1)
- [x] K2 QR a kép után (0.3.0)
- [x] K3 felhő-galéria (tartalék: mu-plugins/crazy-galeria.php) + galéria a gépen, újranyomtatás, QR (0.3.0)
- [x] K4a panel: Booth-gépek kulcsai, a nap rendezvényei, a deal sablonja, galéria-azonosító (booth.php, 2.9.238)
- [ ] K4b a deal adatlapján „Galéria” kártya + {{galeria_link}} levélváltozó
- [ ] K5 sablonszerkesztés a gépen (a panel Sablonkészítője)
- [x] Online felület (0.4.0): a felület a tartalékról frissül (tools/booth-publish.sh), offline az utolsó letöltött fut
- [ ] Windows-telepítő – a fejlesztés végén (a felhasználó kérése: addig csak Mac); wine-nal a felhőben elakadt, Windows-gépen `npm run dist:win`

## Képtörlés + offline sablonszerkesztő (2026-11-13 este – terv: tasks/plan.md vége, jóváhagyásra vár)
- [ ] T1 Galéria 0.9.0: lomtáras törlés + visszaállítás (gépkulcs: saját képek; panel: bármelyik)
- [ ] T2 Booth 0.11.0: kijelölés + törlés a galériában (gép: Kuka; felhő: sorban)
- [ ] T3 Panel: Fotógaléria → Kezelés: törlés + Lomtár
- [ ] Ellenőrzőpont: törlés végig
- [ ] T4 Sablonkészítő + mintaképek + betűk a gépen (offline)
- [ ] T5 „Sablon szerkesztése (internet nélkül is)” a Kezelőben
- [ ] Ellenőrzőpont: offline próba Macen és Windowson
