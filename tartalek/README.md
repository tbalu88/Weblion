# Tartalék szerver (tartalek.eventcrazy.hu, RackForest VPS, CloudPanel)

2026-11-13 óta a tulajdonos kérésére **csak a szükséges fut** rajta: a híd (crazy-bridge) és – ha elkészül – a szelfigépek
galériája (crazy-galeria). A többi bővítmény és az eventcrazy.hu-másolat a lemezen van, de nem töltődik be
(`mu-plugins/tartalek-guard.php`). Minden más cím az eventcrazy.hu-ra irányít, a keresők nem indexelik.

- Feltöltés ide: `bash tools/tartalek-deploy.sh <helyi fájl> <út, pl. mu-plugins/x.php> <alap-sha vagy "">`
- **A `tools/cp-deploy.sh` mindig az ÉLES eventcrazy.hu-ra tölt!** (2026-11-13-án egy fájl emiatt tévesen az élesre került, 1 percen belül semlegesítve.)
- Kikapcsolás: a `tartalek-guard.php` törlése, vagy a wp-config.php-ba `define('CP_TARTALEK_NYITOTT', true);`
