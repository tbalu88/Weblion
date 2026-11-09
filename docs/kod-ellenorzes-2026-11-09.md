# Crazy panel – kódellenőrzés (2026-11-09)

Teljes átnézés (~42 000 sor, 7 párhuzamos, csak olvasó ellenőrzés), a fontos állítások kézzel visszaellenőrizve.
Szempontok: hibák, biztonság, szerverterhelés, felesleges kód, egyszerűsítés. Javítás csak jóváhagyás után.

Összkép: a jogosultság-ellenőrzés és az SQL-védelem mindenhol rendben (nincs SQL-befecskendezés, a nyilvános portál XSS-mentes,
a portál-tokenek erősek). A fő gond a **szerverterhelés** (felesleges lekérdezések, sűrű háttér-lekérdezgetés, ritkán változó adatok
újraszámolása) és néhány **versenyhelyzet** (két kérés egyszerre → dupla vagy rossz adat).

## 1. Hibák (valódi, javítandó)

| # | Hol | Mi a baj | Javítás |
|---|---|---|---|
| H1 | assets/worklog.js:73-83 | Gyors napváltásnál a régebbi válasz felülírhatja az újat → az egyik nap feladatai/költségei a másik napra mentődnek | kérés-sorszám, a feltöltésnél a nap rögzítése |
| H2 | kezelok.php:55, 182 | A Kezelők CSV-letöltése nem működik (hiányzik a panel-token, és csak WP-belépéssel elérhető) | token + nopriv regisztráció |
| H3 | mail-import.php:350 + mail.php:83 | A ↻ gomb és a 5 perces behúzás egyszerre ugyanazt a levelet kétszer is beteheti (nincs egyedi kulcs) | UNIQUE(mid) + INSERT IGNORE, atomi zár |
| H4 | mail-send.php:127-196 | Dupla kattintásra / újrapróbálásra az ügyfél levele kétszer mehet ki (a védelem túl későn foglal) | a küldés azonosítójának atomi lefoglalása az elején |
| H5 | mail-drive.php:242-256 | A Drive-számla-sor párhuzamos futásnál elveszíthet egy számlát | csak a feldolgozott tételek törlése |
| H6 | portal-salesmate.php:211 + portal.js:364 | A portál „Kész” / „Mehet a szerződés” párhuzamos mentései egymás Salesmate-visszaírását törölhetik | atomi sor vagy egymás utáni mentés |
| H7 | stat.php:588 + stat-alerts.php:39 | Az óránkénti és a gyors statisztika-szinkron egyszerre futva megduplázza a levél-eseményeket (felfújt számok az összesítőben) | közös zár / egyedi kulcs |
| H8 | stat-digest.php:82 | Ha az összesítő készítése közben elhal a PHP, az adott heti/havi összesítő soha nem megy ki (beragadt zár) | lejárati idő a zárnak |
| H9 | kanban.js:174, portal-panel.js:201, stat.js:64, panel.js:320 | Gyors váltásnál rossz tábla / rossz deal adatai jelenhetnek meg (a portál-adatlapon akár rossz dealbe menthet) | kérés-sorszám |
| H10 | crm.php:485-490 | A „hiányzó deal” ellenőrzés a más pipeline-ok dealjeit örökké újra lekéri (~860 felesleges Salesmate-hívás/nap) | megjegyezni, ha nem a fő pipeline |
| H11 | crm.php:504 | Csak `Exception`-t fog → TypeError esetén a szinkron 9 percre bezár, a futás eredménye elvész | `\Throwable` + `finally` |
| H12 | interview.php:142-168 | Lemondás dupla kattintásra kétszer fut; lemondott linkkel újra lehet foglalni (a zárt állapot és a korlát megkerülésével); időtartam-módosításkor átfedő időpontok | állapot-feltételes UPDATE, ellenőrzés |
| H13 | sm-local.php:32 | „Panel = fő rendszer” módban a törlés hibásnak látszik és visszaállhat (más jelzőt néz a kód) – **most nem aktív**, de az átállás előtt javítandó | `isDeleted` jelző |
| H14 | gcal.php:106 | A naptár-szinkron a törölt dealeket is figyelembe venné (most ki van kapcsolva) | `deleted_at IS NULL` |
| H15 | webforms.js:80 | Éjfél és 1–2 óra között a tegnapi dátumot is elfogadja; 0/negatív létszám is beküldhető | helyi dátum, tartomány-ellenőrzés |
| H16 | mail-import.php:145 | 600 KB-nál levágott levél teljes szövege zagyvává válhat (átkódolás) | a csonka karakter levágása |
| H17 | kanban.php:280/388 | Törölt listából visszaállított kártya eltűnik a tábláról | visszaállításkor első élő lista |
| H18 | worklog.php:231 | Már beküldött nap újra beküldhető → dupla Pumble-jelentés | szerveroldali tiltás |
| H19 | sablon.css:76 + sablon.js:905 | Telefonon az ügyfél nem tudja görgetni a portál sablon-előnézetét – a felirat mozdul el | húzás csak a feliratra/logóra |

Kisebbek: dash.js/audit.js/search.js apró állapothibák, contract.js mobil alja (100dvh), notify-prefs részleges mentés, kezelők-titkosítás kulcsa a WP-sóból (só-csere = adatvesztés veszélye), stat.js elavult „december 10-ig” szöveg.

## 2. Biztonság (nincs kritikus)

| # | Hol | Mi | Javítás |
|---|---|---|---|
| B1 | pwa.php:154-193 | A push-végpont bármilyen https címet elfogad (belső címet is) és a válasz egy részét visszaadja – belépett kollégánál kihasználható | `wp_safe_remote_post` + ismert push-szolgáltatók |
| B2 | pwa.php:38, crazy-panel.php:3697 | Belépés nélküli kérések adatbázis-írást okoznak (kattintás-napló, kép-napló) – terheléssel visszaélhető | a kattintás-napló törlése, a kép-napló korlátozása |
| B3 | interview.php:125 | Kamu foglalásokkal néhány IP-ről betölthető mind a 21 időpont; az e-mail-cím alapján kideríthető, ki jelentkezett | e-mail-megerősítés vagy riasztás, általános üzenet |
| B4 | sm-hist.php:176 | Az importkulcs a címben utazik (naplókba kerül) | fejlécben |
| B5 | több helyen | A panel-token GET-címekben (naplók, böngészőelőzmény) – alacsony kockázat (sütihez kötött) | POST / egyszer használatos letöltőkulcs |
| B6 | kezelok.php:193, worklog.php:356 | CSV-képletbefecskendezés (=, +, -, @ kezdetű cellák) | előtag |
| B7 | mail.js:852 | Salesmate-ből importált levelek külső képei a hozzájárulás nélkül betöltődnek | iframe-be / képcsere |
| B8 | crazy-panel.php:103 | Tömb-jelszóval a belépés 500-as hibát ad | típusellenőrzés |

## 3. Szerverterhelés (a legnagyobb nyereség a szűkös tárhelyen)

| # | Hol | Mi | Javítás |
|---|---|---|---|
| T1 | stat-digest.php:497 | A mérföldkő-ellenőrzés 10 percenként a teljes statisztikát újratölti (144×/nap) | csak adatváltozáskor / óránként |
| T2 | stat-alerts.php:53, stat.php:711 | A gyors szinkron minden futáskor érvényteleníti a statisztika-gyorsítótárat, változás nélkül is | csak változáskor |
| T3 | stat-alerts.php:391 | A riasztások gyorsítótár-kulcsa percenként változik → szinte sosem talál, ~30 lekérdezés/perc | fix kulcs |
| T4 | stat.php:1905 | Minden kérésnél (látogatóknál is) 10 percenként 3 nehéz összesítés egy soha nem olvasott értékbe | törlés |
| T5 | panel.js:1819 + 2603 | Elszámolás/Tárca: két 30 mp-es időzítő → 6–9 nehéz kérés/perc/fül | egy 60–120 mp-es frissítés |
| T6 | mail.js:82, mail.php:727 | Levél-jelenlét 15 mp-enként | 30–60 mp, írás csak változáskor |
| T7 | mail.js:523 | Írás után 3 levéllista-lekérés (előtöltés) | lusta előtöltés |
| T8 | mail.php:788 + mail.js:1497 | Piszkozat-mentés 4 mp-enként, több MB-os beállítás újraírásával | ritkább, kisebb mentés |
| T9 | mail-import.php:697 | A ↻ gomb a teljes háttérmunkát (Drive, AI) is lefuttatja a kattintásban | csak levélbehúzás |
| T10 | crm.php:515 | A 10 perces szinkron minden futáskor üríti a panel gyorsítótárát, változás nélkül is | csak változáskor |
| T11 | crm.php:132 | Hiányzó index: deals.company_id (cégszűrés, 360°, visszatérő ügyfél) | index |
| T12 | notify.php:97, dashboard.php | A percenkénti olvasatlan-számláló index nélkül szkenneli a levélszálakat | index (folder,status) |
| T13 | crm-panel.php:171 | A „változott-e valami?” percenként a teljes deal-táblát számolja | változásszámláló (lásd P1 ötlet) |
| T14 | leads.php:135 | Minden táblabetöltés ~200 párosító lekérdezést futtat | csak a háttérben |
| T15 | több fájl | ~15 apró beállítás minden kérésnél külön lekérdezéssel (autoload ki) | autoload |
| T16 | gcal.js:187 | A Naptár fül 3 percenként kényszerített Google-hívást csinál | gyorsítótár használata |
| T17 | panel.js:414 | Éves nézet: 12 párhuzamos kérés | egy kérés |
| T18 | contacts.js:77 | Minden pipálás új lekérés | helyi frissítés |
| T19 | stat-ops.php, kezelok.php:89 | N+1 lekérdezések (eseményenként / kezelőnként) | egy lekérdezés |
| T20 | crazy-panel.php:269 | Deal-módosításonként 2–3 felesleges Salesmate-lekérés | egy lekérés újrahasznosítása |
| T21 | crazy-panel.php:2332 | Földrajzi keresés akár 70 mp-ig blokkol | időkorlát |
| T22 | portal-cron.php:144 | Éjszakai adattörlés minden régi portált újra feldolgoz | „kész” jelölés |
| T23 | mail-att.php:207 | Nem található csatolmány-előnézet minden megnyitáskor újra IMAP-keresést indít | „nincs” jelölő |
| T24 | crazy-panel.php:1610 | Az Elszámolás minden (soha nem törölt) napi naplót betölt | archiválás |

Többi: mnav.js 400 ms-os időzítő, notify.js teljes oldalt figyelő megfigyelő, keresés-mezők pergetés nélkül, kanban dupla újratöltés, `SELECT *` nagy levéltáblán, sablon.js memória.

## 4. Felesleges (halott) kód
- **tpl-clean.php** – az egész fájlt semmi nem tölti be.
- crazy-panel.php: `cp_el_ajax_import`, `cp_tw_ajax_import` (egyszeri importok), `cp_panel_diag('labels')` + `cp_panel_collect_labels`.
- notify.php `cp_nt_mentions`; ai.php `cp_ai_cfg`; contract.php `cp_ct_toks`; portal-panel.php `cp_portal_p_create`, `cp_portal_p_errors`; portal-rest GET `/portal/form`; pwa kattintás-napló.
- stat.php: 2 egyszeri takarító init-horog, `cp_st_adsdiag`; mail-import v<2 migráció (veszélyes is); mail-tags egyszeri utólagos címkézés; cutover egyszeri migráció.
- crm.js: `sortTh`, gyorsszűrő-maradványok (`S.qf`, `QFN`), `COL_DEF` duplikátum; notify.js régi felugró; kanban.js `S.pending/S.loading`; sablon.js debug-exportok; crm-panel.php nem küldött paraméterek (stage, ev, f, to, won, own).
- Nem használt CSS: crm.css (`.crm-filters`, `.crm-sortsel`, `.crm-qf/.crm-qfs`, `.crm-act`, `.crm-top`, `.crm-list/.crm-hist/.crm-link`, `.d3-files`), panel.css (`.el-det`, `.el-gm`, `.el-ph`, `.cpk-multi`), mail.css `.ml-psend`, kezelok `.kz-dot`, `.kz-act`.

## 5. Egyszerűsítés / duplikáció
- `esc` 22, `post` 18, `toast` 14 JS-fájlban újraírva → egy közös segéd (CPX.util) a panel.js-ben.
- 4 különböző zár-megvalósítás (crm, webűrlap, összevonás, cégjavaslat) → egy közös.
- Csatolmány-keresés és MIME-bejárás háromszor; HTML→szöveg négyszer; magyar dátumformázás háromszor; Pumble-cím ellenőrzése négyszer; WP-admin felhasználó/értesítés oldalak a panel duplikátumai.
- „Kontakt lekérése + tárolás” ~8 helyen; értékgyakoriság-szkennek, amiket a szűrő már gyorsítótáraz.
