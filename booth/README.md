# Crazy Booth

EventCrazy szelfigép-szoftver: Windows + Nikon D5300 (USB, digiCamControl) + DNP DS620.

## Indítás fejlesztéshez
1. Node.js 20+ telepítése, majd ebben a mappában: `npm install`
2. Kamera nélkül (próbamód, a gép webkamerájával vagy mintaképpel): `npm run mock`
3. D5300-zal: a digiCamControl fusson, a beállításaiban legyen bekapcsolva a webszerver (5513-as port), majd `npm start`

## Kezelés
- Bárhol érintés a kezdőképernyőn → visszaszámlálás → fotó → előnézet → Nyomtatás / Újra.
- Beállítások: a bal felső sarokban 3 mp-es nyomva tartás (vagy F2), PIN alapból `2580`.
- Kilépés a teljes képernyőből: Ctrl+Shift+Q.

## Mappák (Windows)
- Fotók: `%USERPROFILE%\Pictures\CrazyBooth\<dátum>`
- Beállítások és napló: `%APPDATA%\Crazy Booth\`
