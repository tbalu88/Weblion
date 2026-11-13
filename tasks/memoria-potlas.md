# A közös memóriába („Claude memória”) átvezetendő sorok – a Docs-kapcsolat 2026-11-13 délután megszakadt

Napló (a tetejére):
- 2026-11-13 · Crazy Booth · Mobil PWA éles (https://tartalek.eventcrazy.hu/galeria/app/, feltöltés: tools/mobile-publish.sh), galéria 0.7.0 éles (élő = HEAD), Booth 0.9.1 (keretes panel-ablak, Mac + Windows zip).
- 2026-11-13 · Crazy Booth · AI-teszt előkészítve: OpenRouter, egy kulcs (biztonságos űrlapon kérni; Default környezetbe kerül, ahol a többi kulcs is van). Modellek: Nano Banana 2.0 = google/gemini-3.1-flash-image (a mostani), google/gemini-nano-banana-2.1, openai/gpt-image-2.6-flare, black-forest-labs/flux.2-pro. Szkript: tools/ai-compare.py. A felhasználó küld munkatársi fotókat (hozzájárulással) és promptot.

Projekt: Crazy Booth – javítandó sor:
- A „AI: 3 ajánlat (OpenAI gpt-image-2.5-flare …)” elavult: az OpenAI-nál már a GPT-Image-2.6 (flare / sunburst) az aktuális.
- A „galéria 0.7.0 commitolva, de NINCS élesítve” elavult: 0.7.0 éles.

Projekt: Crazy Booth – új tény (felhasználó, 2026-11-13):
- AI-képekhez most az Activation King fotóautomata-szoftvert használják; ott modell választható, általában a „Nano Banana 2”-t (= Google Gemini 3.1 Flash Image, OpenRouteren google/gemini-3.1-flash-image), ha elérhető. Saját Google-kulcsuk nincs (a szoftver intézi) → az összehasonlításhoz OpenRouter-kulcs kell.
