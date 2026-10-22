# Klasszikus Szudoku (iPhone 15 Pro – PWA)

Telepítés nélküli, offline is működő webes szudoku, iPhone 15 Pro-ra optimalizálva
(Dynamic Island / safe area, teljes képernyős „Főképernyőhöz adás” mód, sötét téma).

## Funkciók
- **5 nehézség**: Könnyű, Közepes, Nehéz, Szakértő, Extrém – mindig egyértelmű megoldású feladvány
- **Idő** mérése, szünet gomb (háttérbe kerüléskor automatikus szünet)
- **Hibák: x/3** – 3 hiba után vége (Második esély lehetőséggel; a beállításokban kikapcsolható)
- **Tipp** – nehézségtől függő számú; a kijelölt (vagy a legkönnyebb) mezőt kitölti
- **Ceruza (draft)** – jegyzetszámok a mezőkben, helyes beíráskor automatikusan törlődnek
- **Gyors ceruza** – minden üres mezőbe beírja a lehetséges jelölteket
- **Gyors bevitel** (villám kapcsoló) – előbb számot választasz, aztán a mezőkre koppintasz
- Visszavonás, Törlés, maradék darabszám a számgombokon, azonos számok kiemelése
- Pontszám (sor/oszlop/blokk bónusz, idő- és hibátlan bónusz), statisztika, automatikus mentés

## Használat iPhone-on
1. Töltsd fel a `sudoku/` mappát bármilyen HTTPS tárhelyre (pl. `eventcrazy.hu/sudoku/` vagy GitHub Pages).
2. Nyisd meg Safariban → Megosztás → **Főképernyőhöz adás**.
3. Az ikonról indítva teljes képernyős alkalmazásként fut, offline is.

Helyi kipróbálás: `cd sudoku && python3 -m http.server` → http://localhost:8000

## Fájlok
- `engine.js` – generátor és megoldó (bitmaszkos visszalépéses keresés)
- `app.js` – játéklogika, felület, mentés (localStorage)
- `style.css`, `index.html` – felület
- `manifest.webmanifest`, `sw.js`, `icons/` – PWA / offline
