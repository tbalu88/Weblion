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

## Elérés
**https://eventcrazy.hu/crazy-panel/sudoku/** – jelszó nélkül, a keresők elől rejtve (noindex).

iPhone-on: Safari → Megosztás → **Főképernyőhöz adás**; az ikonról teljes képernyős alkalmazásként, offline is fut.

## Fájlok (a szerveren: `wp-content/plugins/crazy-panel/`)
- `sudoku.php` – útvonal: `/crazy-panel/sudoku/<név>` → `assets/sudoku*` (a `crazy-panel.php` tölti be)
- `assets/sudoku.html`, `assets/sudoku.css` – felület
- `assets/sudoku-engine.js` – generátor és megoldó (bitmaszkos visszalépéses keresés)
- `assets/sudoku-app.js` – játéklogika, mentés (localStorage)
- `assets/sudoku-sw.js`, `assets/sudoku-manifest.json` – PWA / offline
- `assets/sudoku-icons.json` – az ikonok base64-ben (a tárhelyre csak szöveges fájl írható)

Frissítéskor a `sudoku-sw.js`-ben növeld a `CACHE` nevet, hogy a telepített példányok is frissüljenek.
