# Crazy Booth

EventCrazy szelfigép-szoftver.
- **Mac:** webkamera (beépített FaceTime-kamera vagy USB-webkamera) + nyomtató (pl. DNP DS620 Mac-meghajtóval).
- **Windows:** Nikon D5300 (USB, digiCamControl) vagy webkamera + DNP DS620.

## Mac – indítás
1. Node.js (LTS) telepítése: https://nodejs.org
2. A repó letöltése, majd a Terminálban ebben a `booth` mappában: `npm install`
3. Indítás: `npm start` (teljes képernyő). Ablakban, próbához: `npm run mock`
4. Első indításkor a Mac engedélyt kér a kamerához → **Engedélyezés**.
   Ha véletlenül elutasítottad: Rendszerbeállítások → Adatvédelem és biztonság → Kamera → kapcsold be (fejlesztői indításnál a „Terminal” / az Electron sort).
5. Saját telepítő (.dmg) készítése a Macen: `npm run dist:mac` → a `dist` mappában lesz. Mivel nincs Apple-aláírás, első megnyitáskor: jobb klikk → Megnyitás.

## Windows – indítás
1. Node.js (LTS), majd ebben a mappában `npm install`
2. Webkamerával: `npm start`, a beállításokban Kamera = Webkamera.
3. D5300-zal: fusson a digiCamControl, a beállításaiban legyen bekapcsolva a webszerver (5513-as port), a beállításokban Kamera = Nikon D5300.
4. Telepítő: `npm run dist`

## Kezelés
- Bárhol érintés / kattintás a kezdőképernyőn → visszaszámlálás → fotó → előnézet → Nyomtatás / Újra / Kész.
- Beállítások: a bal felső sarokban 3 mp-es nyomva tartás (vagy F2), PIN alapból `2580`.
  Itt: kamera, melyik webkamera, felbontás (HD / Full HD / 4K), tükrözött élőkép, 1 kép vagy 3 képes csík, visszaszámlálás, nyomtató, példányszám, automatikus nyomtatás.
- Kilépés: Cmd+Shift+Q (Mac) / Ctrl+Shift+Q (Windows).

## Mappák
- Fotók: Képek / CrazyBooth / <dátum> (a nyers fotók és a kész nyomatok is)
- Beállítások és napló (`booth.log`): Mac: `~/Library/Application Support/Crazy Booth/`, Windows: `%APPDATA%\Crazy Booth\`

## Online felület (0.4.0-tól)
A gépre telepített program csak a „keret” (kamera, nyomtató, fájlok, feltöltés). A felület a tartalék szerverről frissül:
`https://tartalek.eventcrazy.hu/wp-content/plugins/crazy-booth-ui/` (manifest.json + fájlok, sha256-tal ellenőrizve).
- A gép induláskor és 15 percenként megnézi; az új felületet letölti, és a kezdőképernyőn tétlenül átvált rá.
- Internet nélkül az utoljára letöltött felület fut (Beállítások fejlécében látszik a változata).
- Közzététel (fejlesztő): `bash tools/booth-publish.sh "mi változott"`
- A programot (keretet) csak akkor kell újratelepíteni, ha a kamera / nyomtatás / fájlkezelés változik; a manifest `shell` mezője jelzi, milyen keret kell.
