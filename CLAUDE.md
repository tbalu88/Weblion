# Crazy panel – munkaszabályok

## Felhasználói útmutató (kötelező karbantartás)
- A kollégáknak szóló felhasználói útmutató egy Claude Docs dokumentum: „Crazy panel – felhasználói útmutató”
  https://claude.ai/artifact/HfLeD1Sh5MPMqhaxW4JMvd (doc id: 86ec9619-5e10-490d-801e-dfad7391842e).
- Minden új vagy módosított funkció élesítése után frissítsd a megfelelő szakaszt, és vegyél fel egy sort a
  „Verziók és változások” táblázat tetejére (dátum · verzió · változás). Megszűnő funkciót töröld az útmutatóból,
  és jelöld a változásnaplóban.
- Az útmutató nyelve magyar, felhasználói szemszögből (mit hol talál, lépésről lépésre), technikai részletek nélkül.

## Félretett ötletek
- A félretett UX-/fejlesztési javaslatok a `docs/ux-otlettar.md` fájlban vannak. Ha a felhasználó új javaslatokat kér,
  ezeket is ajánld fel (az újak mellett); ami elkészült, azt töröld onnan.

## Tervezés skillekkel (kötelező)
- Felület tervezése vagy átdolgozása előtt az `impeccable` skill (részletekhez `emil-design-eng`), élesítés előtt `break-ui` a legrosszabb adatokkal; animációhoz `animate`; fejlesztés tervezéséhez, feladatbontáshoz `planning-and-task-breakdown`.

## Élesítés – mikor kell visszakérdezni (a felhasználó döntése, 2026-11-07)
- Tesztelt hibajavítás és már jóváhagyott funkció élesítése KÉRDEZÉS NÉLKÜL mehet (a `tools/cp-deploy.sh` szkripttel), utána rövid jelentés.
- Csak ezekben kérdezz előtte: adatvesztés vagy törlés; ügyfélnek kimenő levél; pénz (számla); Salesmate-adat módosítása; új funkció tervének jóváhagyása.
- Élesítés előtt mindig: élő sha = utolsó ismert verzió, helyi teszt; utána 200-as válasz és útmutató-frissítés.
