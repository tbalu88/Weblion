#!/usr/bin/env python3
"""Crazy Booth – az offline sablonszerkesztő adatcsomagja (booth/src/renderer/sablon-adat.js).

A panel Sablonkészítője (crazy-panel/assets/sablon.js) a gépen internet nélkül is fut: a mintaképeket és a betűket
ez a csomag adja data-URL-ként (így a vászon nem „szennyeződik”, és nem kell hálózat).
- mintaképek: a sablon.js-ben hivatkozott sablon-*/minta-* fájlok (crazyphoto.hu, ill. a panel assets mappája)
- sima fehér keret (csak a gépen): 1 kép, 2 kép, 3 képes csík, fekvő – a „Zöld levél” sablonok ablakaival
- betűk: Google Fonts (latin + latin-ext), @font-face data-URL-ekkel
Használat: python3 -I tools/booth-sablon-assets.py   (a letöltések gyorsítótára: booth/.sbcache/)"""
import base64, datetime, io, json, os, re, sys, urllib.request
from PIL import Image, ImageDraw

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SB_JS = os.path.join(ROOT, 'crazy-panel', 'assets', 'sablon.js')
CACHE = os.path.join(ROOT, 'booth', '.sbcache')
OUT = os.path.join(ROOT, 'booth', 'src', 'renderer', 'sablon-adat.js')
SRC = 'https://crazyphoto.hu/wp-content/uploads/2026/10/'
FONTS = ('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;1,400&family=Cormorant+Infant:wght@500'
         '&family=Parisienne&family=Montserrat:wght@400;500;600;700&family=Cinzel:wght@700&family=Creepster&display=swap')
UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'
CA = '/root/.ccr/ca-bundle.crt'


def get(url):
    import ssl
    ctx = ssl.create_default_context(cafile=CA) if os.path.exists(CA) else ssl.create_default_context()
    with urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': UA}), timeout=60, context=ctx) as r:
        return r.read()


def white(size, wins, name):
    """fehér lap, átlátszó fotóablakokkal (a sablon.js az ablakokat az átlátszóságból ismeri fel)"""
    im = Image.new('RGBA', size, (255, 255, 255, 255))
    d = ImageDraw.Draw(im)
    for (x0, y0, x1, y1) in wins:
        d.rectangle((x0, y0, x1 - 1, y1 - 1), fill=(0, 0, 0, 0))
    b = io.BytesIO(); im.save(b, 'PNG', optimize=True)
    return name, b.getvalue()


def main():
    os.makedirs(CACHE, exist_ok=True)
    names = sorted(set(re.findall(r"'((?:sablon|minta)-[a-z0-9-]+\.(?:webp|png|jpe?g))'", open(SB_JS, encoding='utf-8').read())))
    names = [n for n in names if not n.startswith('sablon-feher-')]
    # a mintafotók nevét a sablon.js menet közben rakja össze (PH_ALL) – ezeket külön kell felvenni
    names += ['minta-crazy-%d.webp' % i for i in range(1, 5) if 'minta-crazy-%d.webp' % i not in names]
    img = {}
    for n in names:
        p = os.path.join(CACHE, n)
        if not os.path.exists(p) or os.path.getsize(p) < 100:
            local = os.path.join(ROOT, 'crazy-panel', 'assets', n)
            data = open(local, 'rb').read() if os.path.exists(local) else get(SRC + n)
            open(p, 'wb').write(data)
        mime = 'image/webp' if n.endswith('.webp') else 'image/png' if n.endswith('.png') else 'image/jpeg'
        img[n] = 'data:' + mime + ';base64,' + base64.b64encode(open(p, 'rb').read()).decode()
    # sima fehér keret – a „Zöld levél” sablonok ablakai (mérve: 1x/2x 1181×1772, csík 1186×1772, fekvő 1800×1200)
    W = [white((1181, 1772), [(132, 112, 1048, 1364)], 'sablon-feher-1x.png'),
         white((1181, 1772), [(132, 112, 1048, 724), (132, 772, 1048, 1384)], 'sablon-feher-2x.png'),
         white((1186, 1772), [(40, 60, 548, 400), (636, 60, 1144, 400), (40, 432, 548, 772), (636, 432, 1144, 772), (40, 804, 548, 1140), (636, 804, 1144, 1140)], 'sablon-feher-csik-3x.png'),
         white((1800, 1200), [(112, 112, 1700, 892)], 'sablon-feher-fekvo.png')]
    th = Image.new('RGB', (293, 440), (255, 255, 255)); ImageDraw.Draw(th).rectangle((33, 28, 260, 338), fill=(214, 218, 226))
    b = io.BytesIO(); th.save(b, 'PNG', optimize=True); W.append(('sablon-feher-thumb.png', b.getvalue()))
    for n, data in W:
        img[n] = 'data:image/png;base64,' + base64.b64encode(data).decode()
    # betűk
    css = get(FONTS).decode('utf-8')
    blocks = re.findall(r'/\*\s*([a-z-]+)\s*\*/\s*(@font-face\s*{[^}]*})', css)
    out = []
    for sub, blk in blocks:
        if sub not in ('latin', 'latin-ext'):
            continue
        url = re.search(r'url\((https://[^)]+\.woff2)\)', blk).group(1)
        fp = os.path.join(CACHE, 'font-' + re.sub(r'[^a-z0-9]+', '-', url.split('/s/')[-1].lower()))
        if not os.path.exists(fp):
            open(fp, 'wb').write(get(url))
        out.append(blk.replace(url, 'data:font/woff2;base64,' + base64.b64encode(open(fp, 'rb').read()).decode()))
    if len(out) < 10:
        sys.exit('kevés betűtípus-blokk: %d' % len(out))
    v = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M')
    js = ('/* Crazy Booth – az offline sablonszerkesztő adatcsomagja (generálva: tools/booth-sablon-assets.py, ' + v + '). Ne szerkeszd kézzel. */\n'
          'window.CB_SB_ASSETS=' + json.dumps({'v': v, 'img': img, 'css': '\n'.join(out)}, separators=(',', ':')) + ';\n')
    open(OUT, 'w', encoding='utf-8').write(js)
    print('kész:', OUT, '%.1f MB' % (len(js) / 1e6), len(img), 'kép,', len(out), 'betű-blokk')


if __name__ == '__main__':
    main()
