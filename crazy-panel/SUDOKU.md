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
- **Kétszemélyes játék**: szoba nyitása → meghívó link (iOS megosztás / másolás) → a másik csatlakozik,
  3 mp visszaszámlálás, ugyanaz a feladvány. Az ellenfél tábláját kicsiben (vagy a mini táblára koppintva
  egymás mellett) látod: a kitöltött mezői színesek, a számai rejtve vannak. Aki előbb hibátlanul végez, nyer;
  3 hiba = kiesés. Visszavágó ugyanabban a szobában, új feladvánnyal.

## Elérés
**https://eventcrazy.hu/crazy-panel/sudoku/** – jelszó nélkül, a keresők elől rejtve (noindex).

iPhone-on: Safari → Megosztás → **Főképernyőhöz adás**; az ikonról teljes képernyős alkalmazásként, offline is fut.

## Kétszemélyes REST API (`/wp-json/crazy-sudoku/v1`)
- `POST /room` {diff, name, puzzle, solution} → szoba + token (1. hely)
- `GET /room/{id}` → szoba és a két játékos állása
- `POST /room/{id}/join` {name, token?} → 2. hely (vagy visszalépés a saját tokennel)
- `POST /room/{id}/state` {token, round, values, mistakes, score, out} – a befejezés idejét a szerver rögzíti
- `POST /room/{id}/rematch` {token, round, puzzle, solution} → új kör

A szobák tranziensekben tárolódnak (2 napig), a játékosok állása helyenként külön.

## Fájlok (a szerveren: `wp-content/plugins/crazy-panel/`)
- `sudoku.php` – útvonal: `/crazy-panel/sudoku/<név>` → `assets/sudoku*` (a `crazy-panel.php` tölti be)
- `assets/sudoku.html`, `assets/sudoku.css` – felület
- `assets/sudoku-engine.js` – generátor és megoldó (bitmaszkos visszalépéses keresés)
- `assets/sudoku-app.js` – játéklogika, mentés (localStorage)
- `assets/sudoku-sw.js`, `assets/sudoku-manifest.json` – PWA / offline
- `assets/sudoku-icons.json` – az ikonok base64-ben (a tárhelyre csak szöveges fájl írható)

Frissítéskor a `sudoku-sw.js`-ben növeld a `CACHE` nevet, hogy a telepített példányok is frissüljenek.
