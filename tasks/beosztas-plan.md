# Terv: kezelőbeosztás napi alapon (Kapacitás → Beosztás)

## Áttekintés
Ma a kezelőket egy-egy **rendezvényre** hívjuk meg (slot = gép × rendezvény × nap), a meghívó levél a rendezvény adataival megy.
Új működés: a kezelőket egy **napra** hívjuk meg („ráérsz dec. 12-én?”), így kialakul a nap **elérhető kezelőinek köre**.
Minden rendezvény alapból **1 nyitott slotot** igényel (+ az eddigi „+ fő” léptetővel extra slot nyitható). A rendezvény
közeledtével Ákos / admin a nap elérhető kezelőit **tetszés szerint** a rendezvényekhez rendeli; a hozzárendelés = véglegesítés
(a kezelő ekkor kapja meg a rendezvény adatait, naptárbejegyzéssel).

## Döntések (javaslat)
- **Adatmodell:** a meglévő `crazy_slot_asg` tábla marad. Napi meghívás = sor `deal_id = 0`, `skey = ''` (állapot: meghívva /
  ráér / nem ér rá). Hozzárendelés = a slothoz (deal + skey) kötött sor `final` állapottal. Nincs új tábla, a régi adatok érvényesek.
- **Régi, rendezvényre szóló meghívások** változatlanul látszanak és működnek (átmenet); újat már csak napra lehet indítani.
- **Szabályok maradnak:** vidékre Kezelő, spinnerre spinner-tudású Kezelő (Ákos elöl), gép-tudás szerinti javaslat – a hozzárendelő
  listában a nem megfelelők szürkén, figyelmeztetéssel (kézzel ugyanúgy választhatók).
- **Igény / kínálat a napon:** fejléc: „Igény: 5 slot · Ráér: 3 · Kiosztva: 2 · Meghívva, válasz nélkül: 4”.
- **Jogosultság:** napra meghívni az iroda is tud (mint eddig); hozzárendelni (véglegesíteni) Ákos vagy admin.

## Feladatok
1. **Napi meghívás – adat és művelet** (slots.php): `day_invite`, `day_state` (ráér / nem ér rá kézzel), napi lista a nap adataiban.
   Kész, ha: egy napra több kezelő meghívható, állapotuk állítható, a nap lekérdezése visszaadja a kört. Teszt: PHP-próba + mock.
2. **Napi meghívó levél és válaszlink** (slots.php levelek, válaszoldal): „Ráérsz <nap>?” – rendezvényadat nélkül; a link ráér / nem ér rá.
   Kész, ha: a levél és a válaszoldal napi szövegű, a válasz a napi sort állítja. Teszt: levél-előnézet, válaszoldal mock.
3. **Hozzárendelés** (slots.php + panel.js): slotonként választó a nap „ráér” kezelőiből → véglegesítés + a mostani véglegesítő levél
   (rendezvény adatai, naptárbejegyzés). Egy ember egy napon egy slotra (ütközésjelzés). Visszavonás: vissza a napi körbe.
   Kész, ha: hozzárendelés / visszavonás működik, a levél a rendezvény adataival megy. Teszt: mock + PHP-próba.
4. **Nap ablak felülete** (panel.js, impeccable): felül a nap köre (meghívás gomb, állapotok), alatta a rendezvények slotjai a
   hozzárendelő választóval, „+ fő” extra slot. Telefonon is. Teszt: mock 390 / 1280 px, break-ui legrosszabb adatokkal.
5. **Havi / heti jelölések** (panel.js): a napon „ráér / igény” jelzés (pl. 👤 3/5), hiányzó kezelőnél figyelmeztetés.
6. **Útmutató + napló.**

Ellenőrzőpont az 1–3. után (működik végig a folyamat), majd a 4–5. után (felület) – élesítés csak utána.

## Kockázatok
| Kockázat | Hatás | Kezelés |
|---|---|---|
| Régi és új meghívások keveredése | Közepes | Régi sorok külön jelölve, a napi körbe „ráér”-ként számítanak |
| Valaki ráér, de nem kap rendezvényt | Alacsony | Döntés kérdése: kap-e „nem lesz rád szükség” levelet (lásd lent) |
| Levél kezelőknek (kimenő) | — | Csak az eddigi levéltípusok új szöveggel |

## Nyitott kérdések (a felhasználónak)
- A „Szelfigép + Spinner” rendezvény 1 vagy 2 slot (gépenként egy)?
- Aki ráért, de nem kapott rendezvényt: kapjon-e értesítést („aznap nem lesz rád szükség”), és mikor?
- A régi, rendezvényre szóló meghívásokat alakítsam át napi meghívássá, vagy fussanak ki maguktól?
