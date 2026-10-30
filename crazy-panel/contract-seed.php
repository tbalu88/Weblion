<?php
/* a szerződésszerkesztő alap modulkönyvtára (a Drive-sablonokból kigyűjtve, angol fordítással) – csak a panel olvassa */
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
   "extra_egyeb": [],
   "en": {
    "name": "Photo Booth",
    "line": "Photo Booth",
    "tasks": [
     "Operation of the Photo Booth: {H} hours of availability;",
     "Unlimited photo printing during the service period;",
     "Provision of the accessories necessary for the provision of the service;",
     "Provision of 1 assistant for the entire duration of the service, exclusively for tasks related to the operation of the photo booth."
    ],
    "reqs": [
     "At least 6 m² of free, covered and rain-protected space",
     "1 table",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "photo booth",
    "tdev": "photo booth",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the Photo Booth service on site beyond the pre-ordered service period, the fee shall be {P:30000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion. In the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "360 Spinner",
    "line": "360 Spinner",
    "tasks": [
     "Operation of the 360 Spinner: {H} hours of availability;",
     "Unlimited use during the service period;",
     "Provision of the accessories necessary for the provision of the service;",
     "Provision of 1 assistant for the entire duration of the service, exclusively for tasks related to the operation of the 360 Spinner."
    ],
    "reqs": [
     "At least 6 m² of free, covered and rain-protected space",
     "1 table",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "360 Spinner",
    "tdev": "360 Spinner",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the 360 Spinner service on site beyond the pre-ordered service period, the fee shall be {P:40000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion.\nIn the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "Photo Booth and 360 Spinner (Selfie + 360 Spinner)",
    "line": "Photo Booth and 360 Spinner",
    "tasks": [
     "Operation of the Photo Booth: {H} hours of availability;",
     "Operation of the 360 Spinner: 3 hours of availability;",
     "Unlimited photo printing and use during the service periods;",
     "Provision of the accessories necessary for the provision of the services;",
     "Provision of 2 assistants for the entire duration of the services, exclusively for tasks related to the operation of the photo booth and 360 Spinner."
    ],
    "reqs": [
     "At least 6 m² of free, covered and rain-protected space per service",
     "1 table per service",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "photo booth and 360 Spinner",
    "tdev": "photo booth and 360 Spinner",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the Photo Booth service on site beyond the pre-ordered service period, the fee shall be {P:30000} per commenced hour.",
     "If the Client wishes to use the 360 Spinner service on site beyond the pre-ordered service period, the fee shall be {P:40000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour per service.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion per service. In the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "Overhead Spinner",
    "line": "Overhead Spinner",
    "tasks": [
     "Operation of the Overhead Spinner: {H} hours of availability;",
     "Unlimited use during the service period;",
     "Provision of the accessories necessary for the provision of the service;",
     "Provision of 1 assistant for the entire duration of the service, exclusively for tasks related to the operation of the Overhead Spinner."
    ],
    "reqs": [
     "At least 9 m² of free, covered and rain-protected space",
     "1 table",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "Overhead Spinner",
    "tdev": "Overhead Spinner",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the Overhead Spinner service on site beyond the pre-ordered service period, the fee shall be {P:40000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion.\nIn the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "VIP Spinner",
    "line": "VIP Spinner",
    "tasks": [
     "Operation of the VIP Spinner: {H} hours of availability;",
     "Unlimited use during the service period;",
     "Provision of the accessories necessary for the provision of the service;",
     "Provision of 1 assistant for the entire duration of the service, exclusively for tasks related to the operation of the VIP Spinner."
    ],
    "reqs": [
     "At least 18 m² of free, covered, completely level and rain-protected space for assembly",
     "At least 16 m² of free, covered, completely level and rain-protected space for operation",
     "1 table",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "VIP Spinner",
    "tdev": "VIP Spinner",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the VIP Spinner service on site beyond the pre-ordered service period, the fee shall be {P:60000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion.\nIn the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "VR experience",
    "line": "VR experience",
    "tasks": [
     "Operation of the VR experience: {H} hours of availability;",
     "Unlimited use during the service period;",
     "Provision of the equipment necessary for the provision of the service;",
     "Provision of 1 assistant for the entire duration of the service, exclusively for tasks related to the operation of the VR experience."
    ],
    "reqs": [
     "At least 6 m² of free, covered and rain-protected space",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "VR experience",
    "tdev": "VR",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the VR service on site beyond the pre-ordered service period, the fee shall be {P:30000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion. In the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "Cockpit VR experience",
    "line": "Cockpit VR experience",
    "tasks": [
     "Operation of the Cockpit VR experience: {H} hours of availability;",
     "Unlimited use during the service period;",
     "Provision of the equipment necessary for the provision of the service;",
     "Provision of 1 assistant for the entire duration of the service, exclusively for tasks related to the operation of the Cockpit VR experience."
    ],
    "reqs": [
     "At least 6 m² of free, covered and rain-protected space",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "Cockpit VR experience",
    "tdev": "Cockpit VR",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the Cockpit VR service on site beyond the pre-ordered service period, the fee shall be {P:30000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion. In the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "LED dance floor",
    "line": "LED dance floor",
    "tasks": [
     "Operation of the LED dance floor: {H} hours of availability;",
     "Unlimited use during the service period;",
     "Provision of 1 assistant for the entire duration of the service, exclusively for tasks related to the operation of the LED dance floor."
    ],
    "reqs": [
     "At least 18 m² of free, covered, completely level and rain-protected space for assembly",
     "At least 16 m² of free, covered, completely level and rain-protected space for operation",
     "1 table",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "LED dance floor",
    "tdev": "LED dance floor",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the LED dance floor service on site beyond the pre-ordered service period, the fee shall be {P:40000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion.\nIn the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "Classic caricature",
    "line": "Classic caricature",
    "tasks": [
     "Operation of the classic caricature service: {H} hours of availability;",
     "Unlimited use during the service period;",
     "Provision of 1 caricature artist for the entire duration of the service, exclusively for tasks related to the operation of the classic caricature service."
    ],
    "reqs": [
     "2 chairs for the operation of the service",
     "At least 4 m² of free, covered, completely level and rain-protected space for operation",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "service equipment",
    "tdev": "classic caricature",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the classic caricature service on site beyond the pre-ordered service period, the fee shall be {P:40000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion.\nIn the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "Digital caricature",
    "line": "Digital caricature",
    "tasks": [
     "Operation of the digital caricature service: {H} hours of availability;",
     "Unlimited use during the service period;",
     "Provision of 1 caricature artist for the entire duration of the service, exclusively for tasks related to the operation of the digital caricature service."
    ],
    "reqs": [
     "2 chairs for the operation of the service",
     "At least 4 m² of free, covered, completely level and rain-protected space for operation",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "service equipment",
    "tdev": "digital caricature",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the digital caricature service on site beyond the pre-ordered service period, the fee shall be {P:40000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion.\nIn the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "Live music (Crazy Melange duo)",
    "line": "Crazy Melange duo musical activity",
    "tasks": [
     "Performance of the Crazy Melange duo: {H} hours of availability."
    ],
    "reqs": [
     "2 chairs without armrests for the operation of the service",
     "At least 4 m² of free, covered, completely level and rain-protected space for operation",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "sound equipment",
    "tdev": "Crazy Melange duo",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the live music service on site beyond the pre-ordered service period, the fee shall be {P:40000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion.\nIn the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "Karaoke (Crazy Karaoke)",
    "line": "Crazy Karaoke activity",
    "tasks": [
     "Operation of Crazy Karaoke: {H} hours of availability."
    ],
    "reqs": [
     "1 chair for the operation of the service",
     "At least 6 m² of free, covered, completely level and rain-protected space for operation",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "karaoke equipment",
    "tdev": "Crazy Karaoke",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the karaoke service on site beyond the pre-ordered service period, the fee shall be {P:40000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:25000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:25000} per occasion.\nIn the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "CrazyCatch experience (Catch it)",
    "line": "CrazyCatch experience",
    "tasks": [
     "Operation of the CrazyCatch experience: {H} hours of availability;",
     "Unlimited use during the service period;",
     "Provision of the equipment necessary for the provision of the service;",
     "Provision of 1 assistant for the entire duration of the service, exclusively for tasks related to the operation of the CrazyCatch experience."
    ],
    "reqs": [
     "At least 6 m² of free, covered and rain-protected space",
     "A grounded electrical power source",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "Catch it",
    "tdev": "CrazyCatch",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the CrazyCatch service on site beyond the pre-ordered service period, the fee shall be {P:30000} per commenced hour.",
     "Our equipment operator typically arrives at the venue 60 minutes before the scheduled start time to begin installation. If the Client wishes to request an earlier installation or inactive standby hours, the fee shall be {P:10000} per commenced hour.",
     "If the Client wishes to relocate our already installed equipment to another location within the event venue, the relocation fee shall be {P:10000} per occasion. In the event of relocation, the time required for relocation shall not be included in the service period."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "Event photography",
    "line": "Event photography",
    "tasks": [
     "Event photography: {H} hours of presence;",
     "Delivery of the selected and edited photographs to the Client."
    ],
    "reqs": [],
    "reqs_extra": [],
    "dev": "photographic equipment",
    "tdev": "photographic equipment",
    "teljesites": "The Service Provider’s performance shall be deemed completed upon delivery of the finished photographs. The Client may submit any objections concerning the performance in writing no later than 48 hours following the completion of the service. In the absence of such notification, the performance shall be deemed accepted.",
    "extras": [
     "If the Client wishes to use the photography service on site beyond the pre-ordered service period, the fee shall be {P:25000} per commenced hour."
    ],
    "extra_egyeb": [],
    "review": [
     "The template contained no task list – the two task items are suggested by the panel; please verify."
    ]
   }
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
   "extra_egyeb": [],
   "en": {
    "name": "Casino (casino table)",
    "line": "casino table",
    "tasks": [
     "Operation of 1 casino table: {H} hours of availability;",
     "1 roulette table, 1 blackjack table, 1 poker table",
     "Provision of 1 assistant per table for the entire duration of the service, exclusively for tasks related to the operation of the service. For programmes exceeding 90 minutes, the Service Provider shall be entitled to provide its staff with a 10-minute break."
    ],
    "reqs": [
     "The space required for setting up the gaming tables",
     "Power supply, where required",
     "Access to the venue for the Service Provider’s staff",
     "The chairs required for the poker tables",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "casino tables",
    "tdev": "casino tables",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the casino table service on site beyond the pre-ordered service period, the fee shall be {P:50000} per commenced hour.",
     "Our installation technician typically arrives at the venue 30 minutes before the scheduled start time to begin installation."
    ],
    "extra_egyeb": [],
    "review": []
   }
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
   ],
   "en": {
    "name": "Make-up & hair (Crazy Makeup)",
    "line": "Crazy Makeup",
    "tasks": [
     "Operation of 1 Crazy Makeup station: {H} hours of availability;",
     "Provision of the equipment and supplies required for the service",
     "Provision of 1 make-up artist for the entire duration of the service, exclusively for tasks related to the operation of the service. For programmes exceeding 180 minutes, the Service Provider shall be entitled to provide its staff with a 10-minute break."
    ],
    "reqs": [
     "The space required for setting up the tables",
     "Power supply, where required",
     "Access to the venue for the Service Provider’s staff",
     "The chairs required for the make-up tables",
     "Prior information to the Service Provider regarding the conditions of the venue (parking, lack of elevator, etc.)"
    ],
    "reqs_extra": [],
    "dev": "make-up equipment",
    "tdev": "make-up equipment",
    "teljesites": "",
    "extras": [
     "If the Client wishes to use the make-up service on site beyond the pre-ordered service period, the fee shall be {P:50000} per commenced hour.",
     "Our installation technician typically arrives at the venue 30 minutes before the scheduled start time to begin installation."
    ],
    "extra_egyeb": [
     "The Client acknowledges and accepts that it may establish a business relationship with the make-up artists, hairdressers and other contributing professionals recommended or introduced by the Service Provider, or presented during the performance, exclusively through the Service Provider. The Client undertakes not to contact such professionals, either directly or indirectly, for the purpose of commissioning work, and not to conclude any independent agreement with them, without the prior written consent of the Service Provider.\nThis non-circumvention clause shall remain in force for the term of the cooperation between the Parties and for 12 months following its termination. In the event of a breach of this provision, the Client shall pay the Service Provider a contractual penalty equal to 100% of the total net service fee of the engagement affected by the circumvention, without prejudice to the Service Provider’s right to claim further damages."
    ],
    "review": []
   }
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
 ],
 "legal_en": {
  "targy_intro": "The Service Provider undertakes to provide the Client, at the Client’s discretion, with the following service under the following parameters:",
  "feladat_intro": "The Service Provider undertakes to provide the following services under this Agreement:",
  "megbizott_common": [
   "The Service Provider undertakes to comply with the timeframes of the events and to fulfil its obligation to provide information.",
   "The Service Provider undertakes to safeguard the professional and corporate reputation of the Client.",
   "The conduct, appearance and professionalism of the Service Provider shall reflect the business, ethical and professional values represented by the Client."
  ],
  "megbizo_intro": "The Client undertakes to provide the Service Provider’s employee with the conditions necessary for performing the work, as well as access to the systems and information necessary for the successful execution of the event.",
  "megbizo_intro2": "Furthermore, the Client shall ensure the following for the smooth provision of the service:",
  "transport": "Our equipment is delivered and installed by one person in all cases, which can be carried out by one person under normal venue conditions. In the case of special venue conditions (e.g. remote parking facilities, floors without elevator access, etc.), the Client undertakes to inform the Service Provider of such circumstances in advance so that the Service Provider can arrive at the venue prepared for the conditions on site. If the Client fails to provide prior information regarding circumstances of this nature that materially affect the work to be performed on site, the Client shall provide an appropriate number of people on site to assist with the transportation and installation of the equipment, in order to ensure that the service can be provided safely and without delay.",
  "parking": "The Client undertakes to provide one parking space free of charge for the arriving equipment operator for the duration of assembly, the activity and dismantling. If no parking space is available at the event venue, the Client undertakes to reimburse the costs of street parking.",
  "damage": "The Client shall be liable for any damage caused to the {DEV} or its accessories as a result of improper use, the conduct of a third party, or any reason arising within the Client’s sphere of responsibility.",
  "late_interest": "In the event of late payment, the Service Provider shall be entitled to charge default interest at a rate equal to the central bank base rate applicable under the Hungarian Civil Code to transactions between business entities, plus 8%, and shall also be entitled to claim any documented costs incurred in connection with debt collection.",
  "acknowledge": "The Client declares that it understands the fee structure and undertakes to pay the fees in accordance with the terms of this Agreement.",
  "unforeseen": "The Client acknowledges that any additional services ordered on site due to unforeseen circumstances during the event are not included in the basic fee under this Agreement and shall be invoiced subsequently by the Service Provider on the final invoice following the provision of the service.",
  "invoice_mod": "The Client may request an amendment to the invoice in writing within 8 calendar days from the date of issue of the invoice. Requests submitted after this deadline are not automatically accepted, and the Service Provider shall be entitled to charge an administrative fee for the additional work associated with amending the invoice.",
  "contact_intro": "For the duration of the performance of the Agreement, the contracting Parties shall appoint contact persons for the purposes of cooperation. Such contact persons shall be authorised to coordinate and schedule tasks, certify performance, and make written statements.",
  "teljesites": "The Service Provider’s performance shall be deemed completed upon the conclusion of the {DEV} operating period and the commencement of dismantling at the venue. The Client may submit any objections concerning the performance in writing no later than 24 hours following the completion of the service. In the absence of such notification, the performance shall be deemed accepted.",
  "lemondas": [
   "If either Party withdraws from this Agreement or cancels the service within 30 calendar days prior to the starting date of the event, that Party shall be obliged to pay the other Party a contractual penalty amounting to 40% of the net service fee.",
   "If the cancellation takes place within 7 calendar days prior to the starting date of the event, the cancelling Party shall be obliged to pay a contractual penalty amounting to 80% of the net service fee.",
   "The contractual penalty shall be payable within 8 calendar days from the date on which the cancellation statement is communicated."
  ],
  "vis": [
   "Any event which is beyond the control of the Parties and which affects the contractual performance shall be deemed a force majeure event for the purposes of this Agreement.",
   "For the purposes of this Agreement, force majeure shall include the following events: governmental measures, strikes, other industrial disruptions, activities of a public enemy, acts of terrorism, revolutionary acts, riots, blockades, epidemics, landslides, fire, storms, floods, earthquakes, explosions, water damage, lightning, extreme frost, extraordinary weather conditions, or any similar event beyond the control of the Parties.",
   "In the event of force majeure, the Parties shall negotiate the performance of the services specified in this Agreement and the appropriate amendment of the Agreement."
  ],
  "jog": [
   "The Parties agree that this Agreement shall be governed by Hungarian law.",
   "For the resolution of disputes arising from this Agreement, the Parties agree to the exclusive jurisdiction of the Central District Court of Pest (Pesti Központi Kerületi Bíróság) or the Budapest-Capital Regional Court (Fővárosi Törvényszék), depending on the value of the claim."
  ],
  "egyeb_ptk": "Any matters not regulated by this Agreement shall be governed by the provisions of the Hungarian Civil Code and other applicable laws and regulations in force.",
  "gdpr": "During the performance of this Agreement, the Service Provider may process personal data, including in particular photographs/images and contact details, exclusively for the purpose of providing the service and for the period necessary for such provision, in accordance with applicable data protection legislation, in particular Regulation (EU) 2016/679 of the European Parliament and of the Council (GDPR). The Service Provider shall not transfer personal data to third parties, and data subjects shall be entitled to request access to their data, its deletion or restriction of processing through the Service Provider’s contact details.",
  "storage": "The Service Provider shall store the {MEDIA} taken at the event for 90 days, after which they shall be automatically deleted. The Client may request the deletion of the {MEDIA2} or a modification of the storage period by email at any time (up to a maximum of 1 year).",
  "egyeb_post": [
   "All information, data and documents disclosed to or obtained by either Party from the other Party shall constitute the business secrets of the respective Party. Accordingly, such information may not be used in any manner in dealings with parties other than the cooperating partners under this service agreement, nor may it be disclosed to any third party.",
   "The contracting Parties shall continuously cooperate with each other throughout the term of the Agreement in accordance with the principles of good faith and fair dealing. Accordingly, they shall inform each other in a timely manner not only about the performance of the obligations set out in this Agreement, but also about any material matter that may affect the performance of the Agreement."
  ]
 }
}
JSON
, true);
