# Kidolgozási terv: Crazy Booth – saját szelfigép-szoftver (Windows, Nikon D5300)

*2026-11-12 · új projekt · állapot: 1. fázis folyamatban (döntések 2026-11-12)*
*(Az előző terv – háttér 2 órás szabály – kész, élesítve 2.9.232; git-történetben megvan.)*

## Cél
A dslrBooth kiváltása saját szoftverrel: Windows-gép + Nikon D5300 (USB) + DNP DS620 nyomtató.
Első változat: **fotó + nyomtatás, AI-képek, QR-kód a letöltéshez, online galéria**, a sablonok **automatikusan a panelből** (az ügyfél által a portálon elkészített fotósablon).

## Felépítés (döntési javaslatok)
| Rész | Javaslat | Miért |
| --- | --- | --- |
| Alkalmazás | **Electron** (Windows-telepítővel), teljes képernyő, érintésre | A panel sablonrajzolója (sablon.js) újrahasznosítható, így a gépen pontosan az jelenik meg, amit az ügyfél a portálon látott |
| Kamera | **digiCamControl** (ingyenes, nyílt forrású, támogatja a D5300-at) – a szoftver a helyi webes vezérlőjén keresztül exponál és kéri az élőképet | Bevált Windows-megoldás Nikonhoz; a Nikon SDK külön regisztrációt kér |
| Nyomtatás | Windows-nyomtatás közvetlenül a DS620-ra (néma, párbeszéd nélkül), 4×6"; csíknál a meghajtó „2 inch cut” beállításával 2 db 2×6" | A DS620 ezt tudja; a sablon 4×6"-os PNG, a csík már most is kétszer van rajta |
| Sablonok | A panelből: dátum / deal szerint a nap rendezvényei, a portálon mentett nyomtatható PNG + fotóablakok; offline gyorstár a gépen | Nincs kézi átrakás |
| Online galéria | Rendezvényenkénti galéria-oldal (link alapján érhető el, nem kereshető), fotónként saját letöltő-oldal | QR → a vendég telefonján az adott kép |
| QR | Fotózás után a képernyőn és opcionálisan a nyomaton | A QR-cím előre generált, a feltöltés a háttérben megy (internet-kimaradásnál sorban áll, később feltölt) |
| AI-képek | A fotóból AI-stílusú változat (pl. karikatúra / festmény / mese) külső képgeneráló szolgáltatással | Internet kell hozzá a helyszínen; képenként díjas |
| Eszköz-hozzáférés | A panelben „Booth-gépek” lista, gépenként saját kulccsal (visszavonható) | Ha egy gép elveszik, letiltható |

## Feladatok

### 1. fázis – alap (kamera nélkül is tesztelhető)
1. **Electron-váz + képernyőfolyamat**: kezdőképernyő → érintés → visszaszámlálás → fotó → előnézet → nyomtatás / QR. Próbamód kamera nélkül (mintafotókkal). *Kész, ha:* a teljes folyamat végigkattintható próbamódban.
2. **Kameravezérlés (digiCamControl)**: élőkép, exponálás, a kép átvétele, hibakezelés (kamera nincs bekapcsolva, akku, fókusz nem talál). *Kész, ha:* nálad a D5300-zal 50 egymás utáni fotó hiba nélkül; élőkép folyamatos.

### Ellenőrzőpont A – próba nálad (D5300 + Windows)

### 2. fázis – sablon + nyomtatás
3. **Sablon a panelből**: panel-végpont (booth-kulccsal) – a nap rendezvényei, a portálon mentett sablon + fotóablakok; letöltés és offline tárolás. *Kész, ha:* deal kiválasztása után a gép a portál-sablonnal fotóz.
4. **Nyomtatás DS620-ra**: 1 kép (4×6") és 3 képes csík (2×6" vágással); példányszám; papírfogyás-számláló. *Kész, ha:* nálad 10 nyomat hiba nélkül, pontos illeszkedéssel.

### Ellenőrzőpont B – próba nálad nyomtatóval

### 3. fázis – online galéria + QR
5. **Feltöltés + galéria** (panel: tárolás, rendezvényenkénti galéria-oldal, fotónkénti letöltő-oldal; booth: háttérfeltöltés sorral). *Kész, ha:* internet nélkül készült képek a kapcsolat visszatérésekor feltöltődnek.
6. **QR a képernyőn (és a nyomaton, ha kéred)**. *Kész, ha:* telefonnal beolvasva a kép letölthető.

### 4. fázis – AI-képek
7. **AI-változat** választható stílusokkal, várakozó-képernyővel; nyomtatható és a galériába is kerül. *Kész, ha:* 3 stílus működik, egy kép < 30 mp alatt elkészül.

### 5. fázis – kiadás
8. **Windows-telepítő + beállító képernyő** (booth-kulcs, nyomtató, kamera, alapértelmezett mód), naplózás hibakereséshez.

### Ellenőrzőpont C – éles próba egy belső eseményen, a dslrBooth mellett párhuzamosan

## Kockázatok
| Kockázat | Hatás | Kezelés |
| --- | --- | --- |
| A kamerát itt nem tudom fizikailag tesztelni | magas | próbamód; részletes napló a gépen; minden kamerás lépés után próba nálad |
| digiCamControl élőkép lassú / akad | közepes | élőkép-frissítés hangolása; tartalék: a Nikon SDK (regisztráció kell) |
| Internet-kimaradás a helyszínen | közepes | fotó és nyomtatás offline is megy; feltöltés sorban; AI csak nettel |
| AI-költség | közepes | képenkénti díj; rendezvényenként ki-be kapcsolható; napi limit |
| Galéria tárhely az eventcrazy.hu-n (OPcache / lemez) | közepes | képek átméretezve (~2000 px); javaslat: a galéria a tartalék VPS-en (tartalek.eventcrazy.hu) fusson |
| Ügyfélfotók (személyes adat) | magas | link-alapú, nem kereshető galéria; automatikus törlés (pl. 60 nap); adatkezelési szöveg |

## Követelmények (2026-11-13, a felhasználó listája) → feladatok

| # | Kérés | Megoldás | Hol |
| --- | --- | --- | --- |
| K1 | Kilépés a teljes képernyőből | ✅ kész (0.2.1): gomb a kezdőképernyő jobb felső sarkában | booth |
| K2 | QR-kód a kép elkészülte után | A fotó azonosítót kap a gépen; a QR a fotó letöltő-oldalára mutat; a feltöltés a háttérben megy (net nélkül sorban áll, a QR addig is ugyanaz marad) | booth + panel |
| K3 | Galéria a felhőben + galéria nézet a gépen, újranyomtatással és QR-ral | Felhő: rendezvényenkénti galéria-oldal (linkkel, nem kereshető; letöltés képenként és egyben). A gépen: „Galéria” gomb → a rendezvény képei rácsban → egy kép: Nyomtatás / QR | booth + panel |
| K4 | Szinkron a panellel: a deal sablonja, a galéria visszatöltése későbbi kiküldésre | A gépen rendezvényválasztó (a nap dealjei a panelből) → a portálon mentett sablon (nyomtatható PNG + fotóablakok) jön le; a képek a dealhez kötve töltődnek fel; a deal adatlapján „Galéria” kártya (link, darabszám), levélben kiküldhető (`{{galeria_link}}` változó) | booth + panel |
| K5 | Sablonszerkesztés a gépen, a panel 1:1-ben | A gépen „Sablon szerkesztése” → a panel Sablonkészítője nyílik meg a gépen (ugyanaz a kód, bejelentkezve); mentés után a gép újra lehúzza a sablont | booth (a panel kódja változatlan) |

Gép-azonosítás: a panelben „Booth-gépek” (Beállítások), gépenként kulcs; a gép csak a saját rendezvénylistáját, a sablont és a feltöltést éri el.

Sorrend: K4 sablon-letöltés → K2+K3 feltöltés, QR, felhő-galéria → K3 gépi galéria → K4 galéria a dealen + kiküldés → K5.

## Döntések (2026-11-12)
- AI-stílus: **karikatúra**.
- Galéria: a **tartalék VPS-en** (tartalek.eventcrazy.hu); megőrzés 60 nap (javaslat, módosítható).
- QR: **csak a képernyőn**.
- Gépek: csúcsidőben **10** egyszerre (gépenkénti kulcs).

## Nyitott kérdések
- Képernyő felbontása / álló-fekvő (addig: fekvő, bármilyen felbontásra igazodik).
- AI-karikatúra szolgáltatója és képenkénti költségkerete (a 4. fázis előtt kell).
