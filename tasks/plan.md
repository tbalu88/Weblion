# Kidolgozási terv: háttér-foglaltság időablakkal (2 órás szabály)

*2026-11-12 · Crazy panel · állapot: KÉSZ, élesítve (2.9.232)*

## Cél
Egy háttér ugyanazon a napon két rendezvényre is kiadható, ha a korábbi rendezvény vége és a későbbi kezdése között **legalább 2 óra** van. A szabály mindenhol egyformán érvényes: portál háttérválasztó, portál mentés-ellenőrzés, panel Hátterek fül ütközésjelzése, deal szakaszváltáskor adott figyelmeztetés.

## Döntések (2026-11-12, a felhasználóval)
- Időablak forrása: 1) a portálon kitöltött menetrend (kezdés–vége), ha van; 2) különben a deal „Kezdés” (dátum+idő) mezője + a foglalt óraszám.
- Ha bármelyik rendezvénynél hiányzik a kezdés vagy az időtartam → **foglaltnak számít** (mint most).
- Pontosan 2 óra különbség még elég. A sorrend mindegy (korábbi → későbbi és fordítva is).
- Éjfél utánra nyúló rendezvény **nem** foglalja a másnapot (a hátterek éjjel visszakerülnek a raktárba).
- Minden háttérből 1 darab van; a kétoldalas háttér egy foglalása mindkét oldalát foglalja (a szabály a párra együtt vonatkozik).
- Többnapos rendezvény: napi időablak nincs megbízhatóan → az érintett napokon foglaltnak számít (mint most).

## Felépítés
- Egy közös PHP-függvény: `cp_bg_window($deal)` → [kezdés perc, vége perc] vagy null; `cp_bg_fits($a, $b)` → igaz, ha a két ablak között ≥ 120 perc van (null → hamis).
- A napi foglaltság (`cp_portal_bg_taken`) a hátterek mellett az ablakokat is visszaadja; a darabszám helyett „ütközik-e a saját ablakkal” dönt.
- A panel Hátterek füle az eseményekhez megkapja az ablakot; a JS ütközésvizsgálat páronként ugyanezt a szabályt alkalmazza.
- Salesmate-be semmi nem íródik; csak olvasás.

## Feladatok
1. **Időablak + szabály (PHP)** – `cp_bg_window`, `cp_bg_fits`; a menetrend a portál válaszaiból (több deal egyszerre), különben SM kezdés + óraszám. *Kész, ha:* helyi PHP-teszt: 10:00–14:00 és 16:00 → szabad; 15:59 → foglalt; hiányzó idő → foglalt; éjfél utáni vég nem hat másnapra.
2. **Portál** – háttérlista és mentés-ellenőrzés az új szabállyal (saját ablak: a portál menetrendje / SM). Ha az ügyfél később módosítja a menetrendet úgy, hogy ütközzön, a mentett háttér megmarad, de a panelben ütközésként jelenik meg. *Kész, ha:* próba élesben (csak olvasás): a következő 60 nap minden portáljára régi vs. új szabad hátterek listája.
3. **Panel – szakaszváltási figyelmeztetés** (crm-panel.php) ugyanazzal a függvénnyel.
4. **Panel – Hátterek fül** (panel.js + eseményadat): ütközés csak, ha az ablakok 2 órán belül vannak vagy valamelyik hiányzik; a dealnél jelzés: „ugyanez a háttér aznap: 10:00–14:00 (másik rendezvény)”. *Kész, ha:* helyi böngészőteszt legrosszabb adatokkal (hiányzó idő, éjfél, kétoldalas pár, 3 rendezvény egy nap).

### Ellenőrzőpont
- Riport élesből (csak olvasás): hol szűnik meg egy mostani ütközés, és miért (időpontokkal, név nélkül) → átnézed, utána élesítés.
- Élesítés után: 200-as válasz, útmutató frissítése.

## Kockázatok
| Kockázat | Hatás | Kezelés |
| --- | --- | --- |
| Rossz/elavult kezdési idő a dealen | magas (dupla foglalás) | hiányzó adat = foglalt; a panelben a megosztott háttér jelölve, időpontokkal |
| Az ügyfél a portálon később átírja a menetrendet | közepes | panel-ütközésjelzés a friss menetrendből; Pumble-jelzés lehetséges (külön kérésre) |
| Gyorsítótár (5 perc) | alacsony | a kulcsba bekerülnek az új mezők; mentéskor ürül, mint most |

## Nyitott kérdések
- Nincs.
