# Crazy panel – Sablonkészítő: egyedi keret AI-val

Ez a mappa az eventcrazy.hu `wp-content/plugins/crazy-panel/` bővítmény azon fájljait tartalmazza,
amelyeket az AI-keret funkció érint (a teljes bővítmény a szerveren van).

- `sablon.php` – `cp_sb_ai` (OpenAI images/edits hívás maszkkal), `cp_sb_own_save` / `cp_sb_own_del`
  (egyedi stílusok mentése / törlése, `cp_sb_own` beállítás + `uploads/crazy-sablon/egyedi-*.png`)
- `assets/sablon.js`, `assets/sablon.css` – „Egyedi” kategória és az AI-keret kártya a Sablon fülön
- `crazy-panel.php` – admin beállítások: OpenAI kulcs, képmodell, minőség, napi keret (v1.11.0)
