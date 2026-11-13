# A közös memóriába („Claude memória”) átvezetendő sorok – a Docs-kapcsolat 2026-11-13 délután megszakadt

Napló (a tetejére):
- 2026-11-13 · Crazy Booth · Mobil PWA éles (https://tartalek.eventcrazy.hu/galeria/app/, feltöltés: tools/mobile-publish.sh), galéria 0.7.0 éles (élő = HEAD), Booth 0.9.1 (keretes panel-ablak, Mac + Windows zip).
- 2026-11-13 · Crazy Booth · AI-teszt előkészítve: OpenRouter, egy kulcs (biztonságos űrlapon kérni; Default környezetbe kerül, ahol a többi kulcs is van). Modellek: Nano Banana 2.0 = google/gemini-3.1-flash-image (a mostani), google/gemini-nano-banana-2.1, openai/gpt-image-2.6-flare, black-forest-labs/flux.2-pro. Szkript: tools/ai-compare.py. A felhasználó küld munkatársi fotókat (hozzájárulással) és promptot.

Projekt: Crazy Booth – javítandó sor:
- A „AI: 3 ajánlat (OpenAI gpt-image-2.5-flare …)” elavult: az OpenAI-nál már a GPT-Image-2.6 (flare / sunburst) az aktuális.
- A „galéria 0.7.0 commitolva, de NINCS élesítve” elavult: 0.7.0 éles.

Projekt: Crazy Booth – új tény (felhasználó, 2026-11-13):
- AI-képekhez most az Activation King fotóautomata-szoftvert használják; ott modell választható, általában a „Nano Banana 2”-t (= Google Gemini 3.1 Flash Image, OpenRouteren google/gemini-3.1-flash-image), ha elérhető. Saját Google-kulcsuk nincs (a szoftver intézi) → az összehasonlításhoz OpenRouter-kulcs kell.
- 2026-11-13 · Crazy Booth · OpenRouter-kulcs mentve (Default környezet, openrouter.ai), 10 $ kredit; a 4 modell elérhető; próbahívás (NB 2.1, 1K, arc nélküli kép) rendben: 10,8 mp, 0,038 $. A teszthez a munkatársi fotók és a prompt hiányzik.
- 2026-11-13 · Crazy Booth · Activation King AI-ár (activationking.com, 2026-11-13): 10 000 kredit 180 $ (0,018 $/kredit; novemberi akció 153 $), 100 000 kredit 1 400 $ (0,014 $/kredit). A felhasználó szerint egy AI-kép kb. 20 kredit → kb. 0,28–0,36 $/kép. Saját megoldás (OpenRouter listaár, 2K): Nano Banana 2 kb. 0,10 $, Nano Banana 2.1 kb. 0,05 $.
- 2026-11-13 · Crazy Booth · AI-összehasonlítás 1. kör (3 stock/nyilvános fotó, a felhasználó ideiglenes promptja, 0,80 $): 1. GPT-Image 2.6 Flare (legjobb hasonlóság, tiszta háttér, egész alakos, ~11 mp, ~0,05 $), 2. Nano Banana 2.1 (jó, ~20 mp, 0,055 $, de egy képen benn hagyta a háttér feliratait), 3. Nano Banana 2 – a mostani (jó karikatúra, de kéretlen szöveget/aláírást rajzol, ~18 mp, 0,10 $), 4. FLUX.2 pro (egy képen lecserélte a szereplőket – kiesik). OpenRouteren egyszerre legfeljebb 3 kérés (402 in_flight_budget).
- 2026-11-13 · Crazy panel · HIBA + JAVÍTÁS 2.9.250: 2.9.244 óta (11:33) a dealek nem nyíltak meg – a Vendégadatok kártya beillesztésekor kimaradt egy „+” a crm.js-ben (a galéria-kártya sora után), így futásidejű TypeError lett („… is not a function”); a node --check ezt nem fogja meg. TANULSÁG/SZABÁLY: crm.js módosítás után élesítés előtt deal-megnyitás teszt böngészőben (anonimizált deal-adat a p538 próbával, harness: scratchpad/live/dealtest.js).

## Felhasználói útmutató („Crazy panel – felhasználói útmutató”, Verziók és változások – a legújabb sor m56x2m0hqf4.198692 ELÉ)
- 2026.11.13. · 2.9.250 · Hibajavítás: a dealek adatlapja ismét megnyílik (11:33 és kb. 15:00 között nem nyílt meg).
- 2026.11.13. · portál 2.2.6 · Az ügyfélportál asztali gépen szélesebb (könnyebben áttekinthetők a kérdések), telefonon változatlan.
