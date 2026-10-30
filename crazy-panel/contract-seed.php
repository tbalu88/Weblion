<?php
/* a szerződésszerkesztő alap modulkönyvtára (a Drive-sablonokból kigyűjtve) – csak a panel olvassa */
if (!defined('ABSPATH')) { exit; }
return json_decode(<<<'JSON'
{
 "v": 1,
 "legal": {
  "targy_intro": "A Megbízott vállalja, hogy a Megbízó részére – a Megbízó választása szerint – az alábbi szolgáltatást biztosítja az alábbi paraméterekkel:",
  "feladat_intro": "A Megbízott vállalja, hogy a jelen szerződés keretében az alábbi szolgáltatásokat biztosítja:",
  "megbizott_common": [
   "Megbízott vállalja, hogy a rendezvények időkereteit betartja és eleget tesz információszolgáltatási kötelezettségnek.",
   "Megbízott vállalja, hogy őrzi a Megbízó szakmai és céges jó hírét.",
   "Megbízott viselkedése, megjelenése és szakmaisága tükrözi a Megbízó által képviselt üzleti, etikai, szakmai értékeket."
  ],
  "megbizo_intro": "A Megbízó vállalja, hogy a Megbízott munkavállalója részére biztosítja a munkavégzéshez szükséges feltételeket, hozzáférést a rendezvény sikeres lebonyolításához szükséges rendszerekhez, információkhoz.",
  "megbizo_intro2": "Továbbá, a Megbízó köteles a szolgáltatás zavartalan lebonyolításához biztosítani a következőket:",
  "transport": "Készülékeinket minden esetben egy fő szállítja ki és telepíti, mely normál helyszíni adottságok mellett egyszemélyes feladat. Különleges helyszíni adottságok esetén (pl.: távoli parkolási lehetőség, lift nélküli emelet(ek) stb.) a Megbízó vállalja, hogy a Megbízottat tájékoztatja ezen körülményekről, hogy a Megbízott a helyszíni adottságokra felkészülve tudjon a helyszínre érkezni. Amennyiben a Megbízó az ilyen jellegű, a helyszíni munkavégzést érdemben befolyásoló körülményekről előzetesen nem ad tájékoztatást, úgy köteles a helyszínen a készülék szállításához és telepítéséhez szükséges megfelelő számú fizikai segítséget biztosítani annak érdekében, hogy a szolgáltatás biztonságosan és késedelem nélkül megvalósulhasson.",
  "parking": "Megbízó vállalja, hogy egy parkolóhelyet ingyenesen biztosít a kiérkező gépkezelő részére az összeszerelés, aktivitás és elbontás időtartamára. Amennyiben a rendezvény helyszínén nincs elérhető parkolóhely, abban az esetben a Megbízó vállalja az utcai parkolás költségeinek megtérítését.",
  "damage": "A Megbízó felelősséggel tartozik minden olyan kárért, amely a(z) {DEV} vagy tartozékai tekintetében nem rendeltetésszerű használatból, harmadik személy magatartásából vagy a Megbízó érdekkörében felmerült okból keletkezik.",
  "late_interest": "Késedelmes fizetés esetén a Megbízott jogosult a Polgári Törvénykönyv szerinti, üzleti felek közötti jogviszonyban alkalmazandó jegybanki alapkamat + 8% mértékű késedelmi kamatot felszámítani, valamint a behajtással kapcsolatos igazolt költségeit érvényesíteni.",
  "acknowledge": "A Megbízó kijelenti, hogy a díjazás szerkezetét megértette, és annak megfizetését a jelen szerződés feltételei szerint vállalja.",
  "unforeseen": "A Megbízó tudomásul veszi, hogy a rendezvény során előre nem látható körülmények miatt felmerülő, helyszínen megrendelt kiegészítő szolgáltatások nem képezik a jelen szerződés szerinti alapdíjazás részét, azok a Megbízott által nyújtott szolgáltatást követően, utólag kerülnek a végszámlában érvényesítésre.",
  "invoice_mod": "A számla kiállításától számított 8 naptári napon belül a Megbízó írásban kérheti a számla módosítását. A határidőt követően benyújtott módosítási igény teljesítése nem automatikus, és a megbízott jogosult adminisztrációs költséget felszámítani a számlamódosítással járó többletfeladatokra tekintettel.",
  "contact_intro": "A szerződő felek a teljesítés időtartamára az együttműködés érdekében kapcsolattartókat jelölnek ki, akik jogosultak a feladatok koordinálására, ütemezésére, a teljesítések igazolására és írásbeli nyilatkozat tételére.",
  "teljesites": "A Megbízott szolgáltatása a(z) {DEV} üzemeltetési időszakának lezárásával és a helyszíni bontás megkezdésével teljesítettnek minősül. A Megbízó a teljesítéssel kapcsolatos kifogásait legkésőbb a szolgáltatás befejezését követő 24 órán belül, írásban jelezheti. Ennek elmulasztása esetén a teljesítés elfogadottnak minősül.",
  "lemondas": [
   "Amennyiben bármelyik Szerződő Fél a rendezvény kezdőnapját megelőző 30 naptári napon belül a jelen szerződéstől eláll vagy a szolgáltatást lemondja, úgy köteles a másik Fél részére a megbízási díj nettó 40%-át kötbérként megfizetni.",
   "Amennyiben a lemondás a rendezvény kezdőnapját megelőző 7 naptári napon belül történik, a lemondó Fél köteles a megbízási díj nettó 80%-át kötbérként megfizetni.",
   "A kötbér megfizetése a lemondó nyilatkozat közlésétől számított 8 naptári napon belül esedékes."
  ],
  "vis": [
   "Minden olyan esemény, melyet a Felek tevékenységükkel befolyásolni nem tudnak, és a szerződéses teljesítést befolyásolja, jelen szerződés szempontjából vis maiornak tekintendő.",
   "Vis maior kifejezés a következő eseményeket jelenti: kormányintézkedés, sztrájk, más ipari működészavar, közellenség tevékenykedése, terrorcselekmény, forradalmi cselekmények, zavargások, blokád, járvány, földcsuszamlás, tűz, vihar, árvíz, földrengés, robbanás, vízkár, villámlás, leküzdhetetlen fagy, rendkívüli időjárás vagy hasonló, a felek ellenőrzésén kívül eső események.",
   "Vis maior esetén a Felek tárgyalnak a jelen szerződésben foglalt szolgáltatások végrehajtásáról és a szerződés megfelelő módosításáról."
  ],
  "jog": [
   "A Felek rögzítik, hogy a jelen szerződésre a magyar jog az irányadó. A szerződésből eredő jogviták eldöntésére – értékhatártól függően – a Pesti Központi Kerületi Bíróság, illetve a Fővárosi Törvényszék kizárólagos illetékességét kötik ki."
  ],
  "egyeb_ptk": "Jelen szerződésben nem szabályozott kérdésekre nézve a Polgári Törvénykönyvben és egyéb hatályos jogszabályokban foglalt rendelkezések az irányadóak.",
  "gdpr": "A Megbízott a jelen szerződés teljesítése során – a vonatkozó adatvédelmi jogszabályok, különösen az Európai Parlament és a Tanács (EU) 2016/679 rendelete (GDPR) rendelkezéseinek megfelelően – személyes adatokat, így különösen képmást és kapcsolattartási adatokat kezelhet kizárólag a szolgáltatás nyújtásának céljából és a szükséges ideig; a személyes adatokat harmadik személy részére nem adja át, az érintettek pedig jogosultak adataikhoz hozzáférni, azok törlését vagy kezelésük korlátozását kérni a Megbízott elérhetőségein keresztül.",
  "storage": "A Megbízott a rendezvényen készült {MEDIA} 90 napig tárolja, majd azok automatikusan törlésre kerülnek. A Megbízó e-mailben bármikor kérheti a {MEDIA2} törlését vagy tárolási idejének módosítását (legfeljebb 1 év).",
  "egyeb_post": [
   "A Felek tudomására hozott, vagy jutott minden a másik Féltől származó információ, adat és dokumentum az adott Fél üzleti titkát képezi, így azokat e megbízási szerződésben együttműködő partnereken kívüli féllel való kapcsolatában semmilyen módon nem használhatja és harmadik személy tudomására nem hozhatja.",
   "A Szerződő felek kötelesek a szerződés időtartama alatt folyamatosan, a jóhiszeműség és a tisztesség követelményeinek megfelelően, kölcsönösen együttműködni. Ennek megfelelően időben tájékoztatják egymást, nem csupán a jelen megállapodásban foglaltak teljesítéséről, hanem minden olyan számottevő kérdésről, amely a szerződés teljesítésére kihatással lehet."
  ]
 },
 "issuers": {
  "KFT": {
   "n": "Insignis Plus Kft. (EventCrazy)",
   "rows": [
    [
     "Név:",
     "Insignis Plus Kft"
    ],
    [
     "Székhely:",
     "6500 Baja, Pozsony u. 4"
    ],
    [
     "Levelezési cím:",
     "1094 Budapest, Mihálkovics utca 10. 6/16. - Gyarmati Andrea részére"
    ],
    [
     "Adószám:",
     "25095632-2-03"
    ],
    [
     "Cégjegyzékszám:",
     "03-09-140533"
    ],
    [
     "Bankszámlaszám:",
     "11600006-00000001-96802633"
    ]
   ],
   "sign": [
    "INSIGNIS PLUS Kft.",
    "képviseli: Tóth Balázs ügyvezető"
   ],
   "contact": [
    "Sebestyén Ákos",
    "06 30 474 9447"
   ],
   "vat": "net"
  },
  "EV": {
   "n": "Gacsal Zsolt E.V. (Crazyphoto)",
   "rows": [
    [
     "Név:",
     "Gacsal Zsolt E.V."
    ],
    [
     "Székhely:",
     "1113 Budapest Bartók Béla út 103."
    ],
    [
     "Levelezési cím:",
     "1094 Budapest, Mihálkovics utca 10. 6/16. - Gyarmati Andrea részére"
    ],
    [
     "Adószám:",
     "57400884-1-43"
    ],
    [
     "Bankszámlaszám:",
     "HU41 1040 0968 5052 7066 8666 1011"
    ]
   ],
   "sign": [
    "Gacsal Zsolt / Crazyphoto"
   ],
   "contact": [
    "Sebestyén Ákos",
    "06 30 474 9447"
   ],
   "vat": "aam"
  }
 },
 "services": {
  "fotoautomata": {
   "name": "Fotóautomata",
   "line": "Fotóautomata",
   "hours": 3,
   "price": 89990,
   "travel": 5000,
   "tasks": [
    "Fotóautomata üzemeltetése: {H} órás rendelkezésre állással;",
    "Korlátlan fotónyomtatás a szolgáltatás időtartama alatt;",
    "A szolgáltatás nyújtásához szükséges kiegészítők rendelkezésre bocsátása;",
    "1 fő asszisztencia biztosítása a szolgáltatás teljes időtartama alatt, kizárólag a fotóautomata működtetéséhez kapcsolódó feladatokra."
   ],
   "hatter": true,
   "reqs": [
    "Legalább 6 nm szabad, fedett, esőmentes területet",
    "1 db asztalt",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "fotóautomata",
   "tdev": "fotóautomata",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a Fotóautomata szolgáltatást, úgy annak díja {P:30000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom. Áttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "kep",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "spinner360": {
   "name": "360 Spinner",
   "line": "360 Spinner",
   "hours": 3,
   "price": 89990,
   "travel": 10000,
   "tasks": [
    "360 Spinner üzemeltetése: {H} órás rendelkezésre állással;",
    "Korlátlan használatot a szolgáltatás időtartama alatt;",
    "A szolgáltatás nyújtásához szükséges kiegészítők rendelkezésre bocsátása;",
    "1 fő asszisztencia biztosítása a szolgáltatás teljes időtartama alatt, kizárólag a 360 Spinner működtetéséhez kapcsolódó feladatokra."
   ],
   "hatter": false,
   "reqs": [
    "Legalább 6 nm szabad, fedett, esőmentes területet",
    "1 db asztalt",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "360 Spinner",
   "tdev": "360 Spinner",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a 360 Spinner szolgáltatást, úgy annak díja {P:40000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom.\nÁttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "video",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "selfie_360": {
   "name": "Fotóautomata és 360 Spinner (Selfie + 360 Spinner)",
   "line": "Fotóautomata és 360 Spinner",
   "hours": 3,
   "price": 89990,
   "travel": 15000,
   "tasks": [
    "Fotóautomata üzemeltetése: {H} órás rendelkezésre állással;",
    "360 Spinner üzemeltetése: 3 órás rendelkezésre állással;",
    "Korlátlan fotónyomtatás és használatot a szolgáltatások időtartama alatt;",
    "A szolgáltatások nyújtásához szükséges kiegészítők rendelkezésre bocsátása;",
    "2 fő asszisztencia biztosítása a szolgáltatások teljes időtartama alatt, kizárólag a fotóautomata és 360 Spinner működtetéséhez kapcsolódó feladatokra."
   ],
   "hatter": true,
   "reqs": [
    "Legalább 6 nm szabad, fedett, esőmentes területet / szolgáltatás",
    "1 db asztalt / szolgáltatás",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "fotóautomata és 360 Spinner",
   "tdev": "fotóautomata és 360 Spinner",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a Fotóautomata szolgáltatást, úgy annak díja {P:30000} / megkezdett óra.",
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a 360 Spinner szolgáltatást, úgy annak díja {P:40000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra / szolgáltatás.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom / szolgáltatás. Áttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "kepvideo",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "overhead_spinner": {
   "name": "Overhead Spinner",
   "line": "Overhead Spinner",
   "hours": 3,
   "price": 89990,
   "travel": 10000,
   "tasks": [
    "Overhead Spinner üzemeltetése: {H} órás rendelkezésre állással;",
    "Korlátlan használatot a szolgáltatás időtartama alatt;",
    "A szolgáltatás nyújtásához szükséges kiegészítők rendelkezésre bocsátása;",
    "1 fő asszisztencia biztosítása a szolgáltatás teljes időtartama alatt, kizárólag az Overhead Spinner működtetéséhez kapcsolódó feladatokra."
   ],
   "hatter": false,
   "reqs": [
    "Legalább 9 nm szabad, fedett, esőmentes területet",
    "1 db asztalt",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "Overhead Spinner",
   "tdev": "Overhead Spinner",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni az Overhead Spinner szolgáltatást, úgy annak díja {P:40000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom.\nÁttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "video",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "vip_spinner": {
   "name": "VIP Spinner",
   "line": "VIP Spinner",
   "hours": 3,
   "price": 89990,
   "travel": 20000,
   "tasks": [
    "VIP Spinner üzemeltetése: {H} órás rendelkezésre állással;",
    "Korlátlan használatot a szolgáltatás időtartama alatt;",
    "A szolgáltatás nyújtásához szükséges kiegészítők rendelkezésre bocsátása;",
    "1 fő asszisztencia biztosítása a szolgáltatás teljes időtartama alatt, kizárólag a VIP Spinner működtetéséhez kapcsolódó feladatokra."
   ],
   "hatter": false,
   "reqs": [
    "Az építéshez legalább 18 nm szabad, fedett, teljesen sík és esőmentes területet",
    "Az üzemeléshez legalább 16 nm szabad, fedett, teljesen sík és esőmentes területet",
    "1 db asztalt",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "VIP Spinner",
   "tdev": "VIP Spinner",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a VIP Spinner szolgáltatást, úgy annak díja {P:60000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom.\nÁttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "video",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "vr": {
   "name": "VR élmény",
   "line": "VR élmény",
   "hours": 3,
   "price": 89990,
   "travel": 5000,
   "tasks": [
    "VR élmény üzemeltetése: {H} órás rendelkezésre állással;",
    "Korlátlan használatot a szolgáltatás időtartama alatt;",
    "A szolgáltatás nyújtásához szükséges eszközök rendelkezésre bocsátása;",
    "1 fő asszisztencia biztosítása a szolgáltatás teljes időtartama alatt, kizárólag a VR élmény működtetéséhez kapcsolódó feladatokra."
   ],
   "hatter": false,
   "reqs": [
    "Legalább 6 nm szabad, fedett, esőmentes területet",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "VR élmény",
   "tdev": "VR",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a VR szolgáltatást, úgy annak díja {P:30000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom. Áttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "cockpit_vr": {
   "name": "Cockpit VR élmény",
   "line": "Cockpit VR élmény",
   "hours": 3,
   "price": 89990,
   "travel": 5000,
   "tasks": [
    "Cockpit VR élmény üzemeltetése: {H} órás rendelkezésre állással;",
    "Korlátlan használatot a szolgáltatás időtartama alatt;",
    "A szolgáltatás nyújtásához szükséges eszközök rendelkezésre bocsátása;",
    "1 fő asszisztencia biztosítása a szolgáltatás teljes időtartama alatt, kizárólag a Cockpit VR élmény működtetéséhez kapcsolódó feladatokra."
   ],
   "hatter": false,
   "reqs": [
    "Legalább 6 nm szabad, fedett, esőmentes területet",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "Cockpit VR élmény",
   "tdev": "Cockpit VR",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a Cockpit VR szolgáltatást, úgy annak díja {P:30000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom. Áttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "led_tancter": {
   "name": "LED tánctér",
   "line": "LED tánctér",
   "hours": 3,
   "price": 89990,
   "travel": 20000,
   "tasks": [
    "LED tánctér üzemeltetése: {H} órás rendelkezésre állással;",
    "Korlátlan használatot a szolgáltatás időtartama alatt;",
    "1 fő asszisztencia biztosítása a szolgáltatás teljes időtartama alatt, kizárólag az LED tánctér működtetéséhez kapcsolódó feladatokra."
   ],
   "hatter": false,
   "reqs": [
    "Az építéshez legalább 18 nm szabad, fedett, teljesen sík és esőmentes területet",
    "Az üzemeléshez legalább 16 nm szabad, fedett, teljesen sík és esőmentes területet",
    "1 db asztalt",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "LED tánctér",
   "tdev": "LED tánctér",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a LED tánctér szolgáltatást, úgy annak díja {P:40000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom.\nÁttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "klasszikus_karikatura": {
   "name": "Klasszikus karikatúra",
   "line": "Klasszikus karikatúra",
   "hours": 3,
   "price": 89990,
   "travel": 20000,
   "tasks": [
    "Klasszikus karikatúra üzemeltetése: {H} órás rendelkezésre állással;",
    "Korlátlan használatot a szolgáltatás időtartama alatt;",
    "1 fő rajzoló biztosítása a szolgáltatás teljes időtartama alatt, kizárólag a klasszikus karikatúra működtetéséhez kapcsolódó feladatokra."
   ],
   "hatter": false,
   "reqs": [
    "A szolgáltatás működtetéséhez 2 db széket",
    "Az üzemeléshez legalább 4 nm szabad, fedett, teljesen sík és esőmentes területet",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "szolgáltatás eszközei",
   "tdev": "klasszikus karikatúra",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a klasszikus karikatúra szolgáltatást, úgy annak díja {P:40000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom.\nÁttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "digitalis_karikatura": {
   "name": "Digitális karikatúra",
   "line": "Digitális karikatúra",
   "hours": 3,
   "price": 89990,
   "travel": 20000,
   "tasks": [
    "Digitális karikatúra üzemeltetése: {H} órás rendelkezésre állással;",
    "Korlátlan használatot a szolgáltatás időtartama alatt;",
    "1 fő rajzoló biztosítása a szolgáltatás teljes időtartama alatt, kizárólag a digitális karikatúra működtetéséhez kapcsolódó feladatokra."
   ],
   "hatter": false,
   "reqs": [
    "A szolgáltatás működtetéséhez 2 db széket",
    "Az üzemeléshez legalább 4 nm szabad, fedett, teljesen sík és esőmentes területet",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "szolgáltatás eszközei",
   "tdev": "digitális karikatúra",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a digitális karikatúra szolgáltatást, úgy annak díja {P:40000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom.\nÁttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "kep",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "elozene": {
   "name": "Élőzene (Crazy Melange duó)",
   "line": "Crazy Melange duó zenés aktivitás",
   "hours": 3,
   "price": 89990,
   "travel": 10000,
   "tasks": [
    "Crazy Melange duó üzemeltetése: {H} órás rendelkezésre állással."
   ],
   "hatter": false,
   "reqs": [
    "A szolgáltatás működtetéséhez 2 db karfa nélküli széket",
    "Az üzemeléshez legalább 4 nm szabad, fedett, teljesen sík és esőmentes területet",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "hangtechnika",
   "tdev": "Crazy Melange duó",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni az élőzene szolgáltatást, úgy annak díja {P:40000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom.\nÁttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "",
   "cash": false,
   "review": [],
   "extra_egyeb": []
  },
  "karaoke": {
   "name": "Karaoke (Crazy Karaoke)",
   "line": "Crazy Karaoke aktivitás",
   "hours": 3,
   "price": 89990,
   "travel": 10000,
   "tasks": [
    "Crazy Karaoke üzemeltetése: {H} órás rendelkezésre állással."
   ],
   "hatter": false,
   "reqs": [
    "A szolgáltatás működtetéséhez 1 db széket",
    "Az üzemeléshez legalább 6 nm szabad, fedett, teljesen sík és esőmentes területet",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "karaoke-eszközök",
   "tdev": "Crazy Karaoke",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a karaoke szolgáltatást, úgy annak díja {P:40000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:25000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:25000} / alkalom.\nÁttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "",
   "cash": false,
   "review": [],
   "extra_egyeb": []
  },
  "crazycatch": {
   "name": "CrazyCatch élmény (Catch it)",
   "line": "CrazyCatch élmény",
   "hours": 5,
   "price": 300000,
   "travel": 10000,
   "tasks": [
    "CrazyCatch élmény üzemeltetése: {H} órás rendelkezésre állással;",
    "Korlátlan használatot a szolgáltatás időtartama alatt;",
    "A szolgáltatás nyújtásához szükséges eszközök rendelkezésre bocsátása;",
    "1 fő asszisztencia biztosítása a szolgáltatás teljes időtartama alatt, kizárólag a CrazyCatch élmény működtetéséhez kapcsolódó feladatokra."
   ],
   "hatter": false,
   "reqs": [
    "Legalább 6 nm szabad, fedett, esőmentes területet",
    "Földelt elektromos áramforrást",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "Catch it",
   "tdev": "CrazyCatch",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a CrazyCatch szolgáltatást, úgy annak díja {P:30000} / megkezdett óra.",
    "Gépkezelő kollégánk jellemzően a kezdési időpont előtt 60 perccel érkezik a helyszínre a telepítés megkezdéséhez. Amennyiben ennél korábbi telepítést vagy inaktív készenléti órákat szeretne igénybe venni a Megbízó, úgy annak díja {P:10000} / megkezdett óra.",
    "Amennyiben a már installált eszközünket a rendezvény helyszínén belül egy másik lokációra szeretnék áthelyezni, az áttelepítés díja {P:10000} / alkalom. Áttelepítés esetén, az áttelepítés ideje nem számít bele a szolgáltatás idejébe."
   ],
   "media": "kep",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "rendezvenyfoto": {
   "name": "Rendezvényfotózás",
   "line": "Rendezvényfotózás",
   "hours": 3,
   "price": 89990,
   "travel": 0,
   "tasks": [
    "Rendezvényfotózás: {H} órás jelenléttel;",
    "A válogatott, szerkesztett képek elküldése a Megbízó részére."
   ],
   "hatter": false,
   "reqs": [],
   "reqs_extra": [],
   "transport": false,
   "dev": "fotós felszerelés",
   "tdev": "fotós felszerelés",
   "teljesites": "A Megbízott szolgáltatása a kész képek elküldésével teljesítettnek minősül. A Megbízó a teljesítéssel kapcsolatos kifogásait legkésőbb a szolgáltatás befejezését követő 48 órán belül, írásban jelezheti. Ennek elmulasztása esetén a teljesítés elfogadottnak minősül.",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a Fotós szolgáltatást, úgy annak díja {P:25000} / megkezdett óra."
   ],
   "media": "kep",
   "cash": true,
   "review": [
    "A sablonban nem volt feladatlista – a két feladatsort a panel javasolja, ellenőrizd."
   ],
   "extra_egyeb": []
  },
  "kaszino": {
   "name": "Kaszinó (kaszinóasztal)",
   "line": "kaszinóasztal",
   "hours": 4,
   "price": 89990,
   "travel": 0,
   "tasks": [
    "1 db kaszinóasztal üzemeltetése: {H} órás rendelkezésre állással;",
    "1 db rulett asztal, 1 db blackjack asztal, 1 db póker asztal",
    "1 fő/asztal asszisztencia biztosítása a szolgáltatás teljes időtartama alatt, kizárólag a szolgáltatás működtetéséhez kapcsolódó feladatokra. A Megbízott jogosult a 90 percet meghaladó programok esetén 10 perc pihenőidőt biztosítani a dolgozóinak."
   ],
   "hatter": false,
   "reqs": [
    "a játékasztalok felállításához szükséges területet",
    "az esetleges áramellátást",
    "a Szolgáltató munkatársainak beléptetését",
    "pókerasztalokhoz szükséges székeket",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "kaszinóasztalok",
   "tdev": "kaszinóasztalok",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a kaszinóasztal szolgáltatást, úgy annak díja {P:50000} / megkezdett óra.",
    "Telepítő kollégánk jellemzően a kezdési időpont előtt 30 perccel érkezik a helyszínre a telepítés megkezdéséhez."
   ],
   "media": "",
   "cash": true,
   "review": [],
   "extra_egyeb": []
  },
  "crazymakeup": {
   "name": "Smink + Fodrász (Crazy Makeup)",
   "line": "Crazy Makeup",
   "hours": 4,
   "price": 89990,
   "travel": 0,
   "tasks": [
    "1 db Crazy Makeup üzemeltetése: {H} órás rendelkezésre állással;",
    "A szolgáltatáshoz tartozó eszközök és kellékek biztosítása",
    "1 sminkes biztosítása a szolgáltatás teljes időtartama alatt, kizárólag a szolgáltatás működtetéséhez kapcsolódó feladatokra. A Megbízott jogosult a 180 percet meghaladó programok esetén 10 perc pihenőidőt biztosítani a dolgozóinak."
   ],
   "hatter": false,
   "reqs": [
    "az asztalok felállításához szükséges területet",
    "az esetleges áramellátást",
    "a Szolgáltató munkatársainak beléptetését",
    "sminkasztalokhoz szükséges székeket",
    "a helyszíni adottságokról (parkolás, lift hiánya stb.) előzetesen tájékoztatni a Megbízottat"
   ],
   "reqs_extra": [],
   "transport": true,
   "dev": "sminkeszközök",
   "tdev": "sminkeszközök",
   "teljesites": "",
   "extras": [
    "Amennyiben a Megbízó a helyszínen az előre megrendelt szolgáltatási időn túl is szeretné igénybe venni a sminkes szolgáltatást, úgy annak díja {P:50000} / megkezdett óra.",
    "Telepítő kollégánk jellemzően a kezdési időpont előtt 30 perccel érkezik a helyszínre a telepítés megkezdéséhez."
   ],
   "media": "",
   "cash": true,
   "review": [],
   "extra_egyeb": [
    "A Megbízó tudomásul veszi és elfogadja, hogy a Megbízott által ajánlott, közvetített vagy a teljesítés során bemutatott sminkes, fodrász, illetve egyéb közreműködő szakemberekkel kizárólag a Megbízotton keresztül létesíthet üzleti kapcsolatot. A Megbízó vállalja, hogy a Megbízott előzetes írásbeli hozzájárulása nélkül sem közvetlenül, sem közvetett módon nem veszi fel a kapcsolatot ezen szakemberekkel munkavégzésre irányuló megbízás céljából, és nem köt velük önálló szerződést.\nA jelen megkerülési tilalom a felek közötti együttműködés időtartama alatt, valamint annak megszűnését követő 12 hónapig érvényes. A rendelkezés megsértése esetén a Megbízó köteles a Megbízott részére a megkerüléssel érintett megbízás teljes nettó vállalkozói díjának 100%-ával megegyező összegű kötbért megfizetni, amely nem érinti a Megbízó további kártérítési igényének érvényesítésére való jogát."
   ]
  }
 },
 "order": [
  "fotoautomata",
  "spinner360",
  "selfie_360",
  "overhead_spinner",
  "vip_spinner",
  "vr",
  "cockpit_vr",
  "led_tancter",
  "klasszikus_karikatura",
  "digitalis_karikatura",
  "elozene",
  "karaoke",
  "crazycatch",
  "rendezvenyfoto",
  "kaszino",
  "crazymakeup"
 ]
}
JSON
, true);
