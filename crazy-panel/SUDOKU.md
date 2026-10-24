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
- **Magyar / angol nyelv** (alapból a telefon nyelve; kezdőképernyőn és a beállításokban váltható)
- **Következtetés-bónusz**: a motor megnézi, mi kellett a beírt mezőhöz. Ami a táblán azonnal látszik, az
  sima pont; több lépés előre gondolása 🧠 (×2), jelölt-technika (zárt jelöltek, párok) 🌟 (×5), X-wing /
  XY-wing vagy nehezebb 💎 (×8). Ha a mezőbe korábban rossz számot írtál, nincs bónusz (a tippelés nem éri meg).
- **🦎 Gecko mód**: zöld, gekkóbőr-mintás tábla levelekkel; helyes beírásnál és következtetésnél gecko bújik
  elő, kitöltött sornál/oszlopnál/blokknál átrohan egy, győzelemkor egy csapat átszalad a képernyőn
- **Kétszemélyes játék**: szoba nyitása → meghívó link (iOS megosztás / másolás) → a másik csatlakozik,
  3 mp visszaszámlálás, ugyanaz a feladvány. Az ellenfél tábláját kicsiben (vagy a mini táblára koppintva
  egymás mellett) látod: a kitöltött mezői színesek, a számai rejtve vannak. Aki előbb hibátlanul végez, nyer;
  3 hiba = kiesés. Visszavágó ugyanabban a szobában, új feladvánnyal.
  Az ellenfél friss számai felvillannak a tábláján, kitöltött egységén végigfut a villanás, közben a te
  táblád széle narancssárgán megvillan. Vezetés-kijelző, hírsáv és élő eseménynapló (lendület 🔥,
  „mindjárt végez” ⚠️, hibák, tippek, következtetések). Asztali gépen (≥ 900 px) két egyforma tábla,
  alattuk a vezérlők és a napló.
- **🦎 Gecko-támadás** (párbajban, csak ha **mindkét** játékos gecko módban van): 20–40 mp-enként egy aranyszínű
  gecko zuhan át nagyon gyorsan a képernyőn. Aki elkapja (koppintás / kattintás), annak a geckója átrepül az
  ellenfél táblájára: ott 5 mp-re összekeverednek és felborulnak a számok, az ellenfél addig nem írhat be
  (a játékállás nem változik). Utána a számok rugózva a helyükre repülnek. Elkapás után 35–55 mp szünet.
  A zárolás és az időzítés a mentett játékban él (Vissza + Folytatás vagy újratöltés sem nullázza); a szerver
  csak akkor fogad el támadást, ha mindkét fél gecko módban van, a kör elindult, és az előző óta eltelt 30 mp.
  „Mozgás csökkentése” beállítás mellett a játékos nem számít gecko módúnak.

## Elérés
**https://eventcrazy.hu/crazy-panel/sudoku/** – jelszó nélkül, a keresők elől rejtve (noindex).

iPhone-on: Safari → Megosztás → **Főképernyőhöz adás**; az ikonról teljes képernyős alkalmazásként, offline is fut.

## Kétszemélyes REST API (`/wp-json/crazy-sudoku/v1`)
- `POST /room` {diff, name, puzzle, solution} → szoba + token (1. hely)
- `GET /room/{id}` → szoba és a két játékos állása
- `POST /room/{id}/join` {name, token?} → 2. hely (vagy visszalépés a saját tokennel)
- `POST /room/{id}/state` {token, round, values, mistakes, score, out, ev} – a befejezés idejét a szerver rögzíti;
  `ev` = a játékos legutóbbi eseményei (d = következtetés, h = tipp, g = gecko-támadás); a szerver mindegyikre
  rárakja `s`-ként, mikor kapta meg először (így a régi támadás nem sül el újra); `gk` = gecko mód be van-e kapcsolva
- `POST /room/{id}/rematch` {token, round, puzzle, solution} → új kör

A szobák tranziensekben tárolódnak (2 napig), a játékosok állása helyenként külön.

## Fájlok (a szerveren: `wp-content/plugins/crazy-panel/`)
- `sudoku.php` – útvonal: `/crazy-panel/sudoku/<név>` → `assets/sudoku*` (a `crazy-panel.php` tölti be)
- `assets/sudoku.html`, `assets/sudoku.css` – felület
- `assets/sudoku-engine.js` – generátor, megoldó és logikai elemző (`deductionTier`)
- `assets/sudoku-i18n.js` – magyar és angol szövegek
- `assets/sudoku-app.js` – játéklogika, mentés (localStorage)
- `assets/sudoku-sw.js`, `assets/sudoku-manifest.json` – PWA / offline
- `assets/sudoku-icons.json` – az ikonok base64-ben (a tárhelyre csak szöveges fájl írható)

Frissítéskor a `sudoku-sw.js`-ben növeld a `CACHE` nevet, hogy a telepített példányok is frissüljenek.
