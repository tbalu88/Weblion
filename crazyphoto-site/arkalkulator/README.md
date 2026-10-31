# Crazyphoto árkalkulátor

Élő helye: https://crazyphoto.hu/arkalkulator/ (Elementor HTML-modul; a menüből jelenleg nem érhető el, csak a webhelytérképből).
Mentve: 2026-10-31, az élő oldalról (az oldal utolsó módosítása: 2026-10-01).

- `arkalkulator.html` – a modul teljes tartalma: jelölés (`#cpcalc`), stílus, szkript. Önállóan is megnyitható és számol.
- Árak, felárak a szkript elején: `KM_PRICE` (130 Ft/km, oda-vissza), `BOTH_DISC` (−15% a Mindkettőre), `GUEST_FEE` (létszám-felár), `M` (csomagok, kiegészítők).
- Kiszállás: indulópont Budapest XI. (`ORIGIN`), útvonal az OSRM-mel, címkeresés a Nominatimmal (OpenStreetMap, kulcs nélkül).
- „Ajánlatot kérek ezzel”: a Salesmate-űrlapba (`studioframes.salesmate.io`) írja az összeállítást – a panel ajánlatkérés-figyelője ezt is rögzíti.

Árváltozáskor itt és az élő oldalon is frissíteni kell (és a crazyphoto-site/seo llms.txt + séma csomagárait).
