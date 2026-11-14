# Product

<!-- impeccable:product-schema 1 -->

*Hatókör: a `ios/` mappa iPhone-appja (Crazy 360). A repó többi része (Crazy panel, Crazy Booth, mobil PWA) külön termék.
A felhasználó 2026-11-14-i kérésének és korábbi válaszainak összefoglalója; a „(feltételezés)” jelű pontokat nem kérdeztük meg külön – a felhasználó állandó kérése: kérdés nélkül haladni, csak kritikus pontoknál megállni.*

## Platform

ios

## Users

- **Kezelő (operátor)** az EventCrazy rendezvényein: a 360-as platform karjára szerelt iPhone-nal videót rögzít a vendégekről. Képernyőn, hangerőgombbal vagy Bluetooth-exponálóval indít; a telefon a forgó karon van, karnyújtásnyiról vagy messzebbről látja.
- A **vendégek** nem az appot kezelik: a kész videót QR-kóddal kapják meg, és a külön iPaden futó megosztó állomáson nézik (a galéria `?allomas=1` oldala).

## Product Purpose

A LumaBooth kiváltása saját appal: jobb minőségű (120/240 fps, 0,5× nagylátószög) felvétel, a telefonon kész videó (lassítás-ív, oda-vissza, zene, keret + felirat), és azonnal a rendezvény saját galériájába kerül. Siker: a kezelő egy gombnyomással ismételhető, megbízható folyamatot kap, a vendég pedig perceken belül megkapja a videót.

## Positioning

A Crazy rendszer része: a panel rendezvényei, a galéria, a megosztó állomás és a szelfigép közös gépkulccsal és közös galériával működik – a kész videó ugyanoda kerül, ahová a szelfigép fotói.

## Operating Context

- Rendezvényhelyszín, gyakran sötét, zajos, partifény; a telefon forog (feltételezés: álló tájolás, hátsó kamera).
- A platformot nem az app vezérli (döntés 2026-11-14).
- Internet nem mindig van: a feltöltés sorban áll, és magától pótlódik.
- A gépkulcsot a panel adja (Beállítások → Crazy Booth gépek); a rendezvényeket a panel listájából választják, vagy „Rendezvény nélkül”.

## Capabilities and Constraints

- Alapváltozat funkciói (jóváhagyva 2026-11-14): lassítás-ív, oda-vissza, zene, keret / felirat a videón; feltöltés a galériába, QR.
- Kimenet: 1080×1920 H.264 MP4; a galéria legfeljebb 80 MB-os videót fogad.
- Zene: csak a felhasználó által importált (jogtiszta) zene; beépített zene nincs.
- Terjesztés: TestFlight (Apple Developer fiók – a felhasználó hozza létre).
- Nyelv: magyar.

## Brand Commitments

- A Crazy Booth család színvilága (mobil PWA): sötétkék alap (#0E1220, #141C33), narancs kiemelés (#E8894A → #F2B35B), fehér szöveg, rendszer-betűtípus.
- Ikon: `tools/ios-icon.py` (narancs forgásív, „360”).

## Evidence on Hand

- Nincs valódi felvétel vagy vendégvideó a repóban; a próbákhoz mesterséges felvétel készül (szimulátor: „Próbafelvétel”). Valódi ügyfél- vagy vendégadat nem kerülhet a repóba.

## Product Principles

1. A felvétel sosem akadhat el: hiba esetén is egy gombnyomásra legyen újra kész.
2. Karnyújtásnyiról is olvasható állapot: mi a beállítás, megy-e a feltöltés, kész-e a videó.
3. Net nélkül is működjön; a feltöltés magától pótlódik.
4. A vendég személyes adata (videó) csak a rendezvény galériájába kerül, nyilvános listára nem.

## Accessibility & Inclusion

- Nagy érintési felületek (min. 44 pt, a fő gomb 84 pt), Dynamic Type a szövegekhez, VoiceOver-címkék az ikongombokon, „Mozgás csökkentése” esetén áttűnés (feltételezés: nincs ennél szigorúbb előírás).
