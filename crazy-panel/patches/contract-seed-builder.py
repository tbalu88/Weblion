import json, re
k = json.load(open('kft_hu.json')); e = json.load(open('ev_hu.json'))
B = {s['title']: [p.lstrip('• ').strip() for p in s['paragraphs']] for s in k['base_sections']}
def P(t, start): return next(p for p in B[t] if p.startswith(start))
FIX = [('LED tántér', 'LED tánctér'), ('a OverheadSpinner', 'az Overhead Spinner'), ('OverheadSpinner', 'Overhead Spinner'), ('az VIP Spinner', 'a VIP Spinner'), ('az karaoke', 'a karaoke'), ('progamok', 'programok'), ('10perc', '10 perc'), ('óra/asztal', 'óra')]
def fix(s):
    for a, b in FIX: s = s.replace(a, b)
    return s
PRICE = re.compile(r'nettó (\d[\d ]*\d|\d) Ft \(\+ÁFA\)')
def tok(s): return PRICE.sub(lambda m: '{P:' + m.group(1).replace(' ', '') + '}', fix(s))
park = P('A megbízó feladatai és kötelezettségei', 'Megbízó vállalja, hogy egy parkolóhelyet')
park_only = park.split(' A Megbízó felelősséggel')[0]
legal = {
 'targy_intro': P('A megbízás tárgya', 'A Megbízott vállalja'),
 'feladat_intro': B['A Megbízott feladatai és kötelezettségei'][0],
 'megbizott_common': [p for p in B['A Megbízott feladatai és kötelezettségei'] if p.startswith('Megbízott ')],
 'megbizo_intro': B['A megbízó feladatai és kötelezettségei'][0],
 'megbizo_intro2': B['A megbízó feladatai és kötelezettségei'][1],
 'transport': P('A megbízó feladatai és kötelezettségei', 'Készülékeinket'),
 'parking': park_only,
 'damage': 'A Megbízó felelősséggel tartozik minden olyan kárért, amely a(z) {DEV} vagy tartozékai tekintetében nem rendeltetésszerű használatból, harmadik személy magatartásából vagy a Megbízó érdekkörében felmerült okból keletkezik.',
 'late_interest': P('A Megbízott által nyújtott szolgáltatások díjazása', 'Késedelmes'),
 'acknowledge': 'A Megbízó kijelenti, hogy a díjazás szerkezetét megértette, és annak megfizetését a jelen szerződés feltételei szerint vállalja.',
 'unforeseen': P('A Megbízott által nyújtott szolgáltatások díjazása', 'A Megbízó tudomásul veszi, hogy a rendezvény során'),
 'invoice_mod': P('A Megbízott által nyújtott szolgáltatások díjazása', 'A számla kiállításától'),
 'contact_intro': B['Kapcsolattartás'][0],
 'teljesites': 'A Megbízott szolgáltatása a(z) {DEV} üzemeltetési időszakának lezárásával és a helyszíni bontás megkezdésével teljesítettnek minősül. A Megbízó a teljesítéssel kapcsolatos kifogásait legkésőbb a szolgáltatás befejezését követő 24 órán belül, írásban jelezheti. Ennek elmulasztása esetén a teljesítés elfogadottnak minősül.',
 'lemondas': B['Lemondás és elállás'], 'vis': B['Vis maior'], 'jog': [p for p in B['Joghatóság és irányadó jog'] if p.strip()],
 'egyeb_ptk': B['Egyéb rendelkezések'][0],
 'gdpr': P('Egyéb rendelkezések', 'A Megbízott a jelen szerződés teljesítése során'),
 'storage': 'A Megbízott a rendezvényen készült {MEDIA} 90 napig tárolja, majd azok automatikusan törlésre kerülnek. A Megbízó e-mailben bármikor kérheti a {MEDIA2} törlését vagy tárolási idejének módosítását (legfeljebb 1 év).',
 'egyeb_post': [P('Egyéb rendelkezések', 'A Felek tudomására'), P('Egyéb rendelkezések', 'A Szerződő felek')],
}
def rows(sec, title):
    return [[c.strip() for c in p.split('|', 1)] for p in sec if '|' in p]
kft_rows = [r for r in rows(B['Szerződés fejléc és felek'], '') ]
EB = {s['title']: [p.lstrip('• ').strip() for p in s['paragraphs']] for s in e['base_sections']}
ev_rows = rows(EB['Szerződő felek (fejléc)'], '')
issuers = {
 'KFT': {'n': 'Insignis Plus Kft. (EventCrazy)', 'rows': kft_rows, 'sign': ['INSIGNIS PLUS Kft.', 'képviseli: Tóth Balázs ügyvezető'], 'contact': ['Sebestyén Ákos', '06 30 474 9447'], 'vat': 'net'},
 'EV': {'n': 'Gacsal Zsolt E.V. (Crazyphoto)', 'rows': ev_rows, 'sign': ['Gacsal Zsolt / Crazyphoto'], 'contact': ['Sebestyén Ákos', '06 30 474 9447'], 'vat': 'aam'},
}
MEDIA = {'fotoautomata': 'kep', 'selfie_360': 'kepvideo', 'spinner360': 'video', 'overhead_spinner': 'video', 'vip_spinner': 'video', 'vr': '', 'cockpit_vr': '', 'crazycatch': 'kep', 'rendezvenyfoto': 'kep', 'digitalis_karikatura': 'kep'}
DEVN = {'crazymakeup': 'sminkeszközök', 'kaszino': 'kaszinóasztalok', 'rendezvenyfoto': 'fotós felszerelés', 'elozene': 'hangtechnika', 'karaoke': 'karaoke-eszközök'}
svcs = {}
order = []
for key, s in k['services'].items():
    order.append(key)
    tasks = [fix(t) for t in s['megbizott_feladatai']]
    hours = 3
    if tasks:
        m = re.search(r'(\d+) órás', tasks[0])
        if m: hours = int(m.group(1)); tasks[0] = tasks[0].replace(m.group(0), '{H} órás')
    review = []
    if key == 'rendezvenyfoto':
        tasks = ['Rendezvényfotózás: {H} órás jelenléttel;', 'A válogatott, szerkesztett képek elküldése a Megbízó részére.']; review.append('A sablonban nem volt feladatlista – a két feladatsort a panel javasolja, ellenőrizd.')
    dmg = s.get('damage_clause') or ''
    m = re.search(r'amely (?:a |az )?(.+?) vagy tartozékai', dmg)
    dev = DEVN.get(key) or (m.group(1).strip() if m else s['name'].lower())
    tj = s.get('teljesites') or ''
    custom_tj = fix(tj) if key == 'rendezvenyfoto' else ''
    m2 = re.search(r'szolgáltatása a(?:z)? (.+?) üzemeltetési', tj)
    tdev = (m2.group(1) if m2 and key not in ('kaszino', 'crazymakeup') else dev)
    d = s['dij']; travel = 0
    tr = d.get('travel_rows') or []
    if tr and re.search(r'\d', tr[0][1]): travel = int(re.sub(r'\D', '', tr[0][1]))
    pay = [p for p in (d.get('payment_options') or [])]
    svcs[key] = {
      'name': fix(s['name']), 'line': fix(s['service_line']), 'hours': hours, 'price': d.get('net_price') or 0, 'travel': travel,
      'tasks': tasks, 'hatter': bool(s.get('hatter_line')), 'reqs': [fix(r) for r in s['megbizo_kotelezettsegei']],
      'reqs_extra': [fix(r) for r in (s.get('megbizo_extra_paragraphs') or [])], 'transport': bool(s.get('szallitas_paragraph')) and key not in ('rendezvenyfoto',),
      'dev': fix(dev), 'tdev': fix(tdev), 'teljesites': custom_tj, 'extras': [tok(x) for x in s['kiegeszito']], 'media': MEDIA.get(key, ''),
      'cash': any('készpénz' in p for p in pay), 'review': review,
      'extra_egyeb': [fix(x) for x in (s.get('egyeb_extra_paragraphs') or [])],
    }
seed = {'v': 1, 'legal': legal, 'issuers': issuers, 'services': svcs, 'order': order}
json.dump(seed, open('gen/contract-seed.json', 'w'), ensure_ascii=False, indent=1)
print(len(svcs), 'services'); print(json.dumps(issuers, ensure_ascii=False)[:600])
for kk, v in svcs.items(): print(kk, v['hours'], v['price'], v['travel'], v['dev'], '|', v['tdev'], '|', v['media'], v['cash'], len(v['extras']))
