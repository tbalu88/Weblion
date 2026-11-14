# Crazy 360 – iPhone-app a 360-as platformhoz

Felvétel (0,5×, 60/120/240 fps), a telefonon kész videó (lassítás-ív, oda-vissza, zene, keret + felirat),
feltöltés a rendezvény galériájába gépkulccsal, QR a vendégnek. Az iPad megosztó állomás a galéria
`/galeria/<rendezvény>/?allomas=1` oldala.

## Felépítés

| Hol | Mi |
|---|---|
| `SpinKit/` | Tiszta logika (csak Foundation): sebesség-ív, képkocka-terv, oda-vissza, galéria-API, multipart, feltöltési sor, beállítások, formátumválasztás. `swift test` |
| `Crazy360/Render/` | Renderelő: AVAssetReader → Core Image → AVAssetWriter (H.264 1080×1920), zene, keret + felirat, borítókép |
| `Crazy360/Capture/` | Kamera (actor saját soros szálon), előnézet, hangerőgomb / Bluetooth-exponáló, próbafelvétel kamera nélkül |
| `Crazy360/Upload/` | Galéria-kliens (rendezvények, feltöltés) |
| `Crazy360/App/AppModel.swift` | Állapot: felvétel → feldolgozás → eredmény, feltöltési sor, párosítás |
| `Crazy360/UI/` | SwiftUI képernyők |
| `Crazy360Tests/` | Szimulátoros tesztek (renderelő mesterséges felvételekkel, hálózati mock) |
| `project.yml` | XcodeGen-leírás (a `.xcodeproj` generált, nincs a repóban) |

## Fordítás

A GitHub Actions (`.github/workflows/ios.yml`) minden `ios/` változásnál lefut: SpinKit-tesztek, XcodeGen,
szimulátoros build + tesztek. Macen helyben: `brew install xcodegen && cd ios && xcodegen generate && open Crazy360.xcodeproj`.

## TestFlight (amikor kész az Apple-fiók)

1. **Apple Developer Program** – developer.apple.com/programs → Enroll. Cégként D-U-N-S szám kell (ingyenes, de
   napokig-hetekig tarthat); magánszemélyként gyorsabb. Díj: 99 USD/év.
2. **Azonosító** – Certificates, Identifiers & Profiles → Identifiers → + → App IDs → App:
   Bundle ID (explicit) `hu.eventcrazy.crazy360`.
3. **App az App Store Connectben** – My Apps → + → New App: iOS, név (pl. „Crazy 360 EventCrazy” – egyedinek kell
   lennie), nyelv magyar, Bundle ID a fenti, SKU tetszőleges (pl. crazy360).
4. **API-kulcs** – Users and Access → Integrations → App Store Connect API → Team Keys → + → szerep: **Admin**.
   Töltsd le a `.p8` fájlt (csak egyszer lehet!), és jegyezd fel a Key ID-t és az Issuer ID-t.
5. **Team ID** – developer.apple.com → Account → Membership details.
6. **GitHub-titkok** – a repóban: Settings → Secrets and variables → Actions → New repository secret:
   `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_KEY_P8` (a .p8 fájl teljes tartalma), `APPLE_TEAM_ID`.
   A kulcsot **soha ne másold chatbe** vagy fájlba a repóban.
7. **Feltöltés** – egy `[testflight]` szót tartalmazó commit után a CI aláírja és feltölti a buildet.
8. **Tesztelők** – App Store Connect → TestFlight → Internal Testing → csoport → tesztelők hozzáadása; a telefonra a
   TestFlight appal kerül fel. Belső tesztnél nincs Apple-felülvizsgálat.
