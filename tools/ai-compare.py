#!/usr/bin/env python3
"""AI-karikatúra összehasonlítás – ugyanaz a fotó és prompt több modellen (OpenRouter /api/v1/images).
Használat: python3 -I compare.py <bemeneti mappa> <kimeneti mappa> <prompt-fájl>
A kulcsot a környezet proxyja adja (a szkript csak egy helykitöltő Bearer-értéket küld)."""
import base64, io, json, os, ssl, sys, time, urllib.request, urllib.error, html
from PIL import Image, ImageOps

MODELS = [
    ('google/gemini-3.1-flash-image', 'Nano Banana 2.0 (mostani)', {'aspect_ratio': '3:4', 'resolution': '2K'}),
    ('google/gemini-nano-banana-2.1', 'Nano Banana 2.1', {'aspect_ratio': '3:4', 'resolution': '2K'}),
    ('openai/gpt-image-2.6-flare', 'OpenAI GPT-Image 2.6 Flare', {'aspect_ratio': '3:4', 'quality': 'high'}),
    ('black-forest-labs/flux.2-pro', 'FLUX.2 [pro]', {'aspect_ratio': '3:4'}),
]
API = 'https://openrouter.ai/api/v1/images'
CA = '/root/.ccr/ca-bundle.crt'


def prep(path, max_mp=1.6):
    """EXIF szerinti forgatás, kicsinyítés ~1,6 MP-re (olcsóbb, gyorsabb, kevesebb személyes adat megy ki)."""
    im = ImageOps.exif_transpose(Image.open(path)).convert('RGB')
    w, h = im.size
    k = min(1.0, (max_mp * 1e6 / (w * h)) ** 0.5)
    if k < 1:
        im = im.resize((round(w * k), round(h * k)), Image.LANCZOS)
    b = io.BytesIO(); im.save(b, 'JPEG', quality=90)
    return 'data:image/jpeg;base64,' + base64.b64encode(b.getvalue()).decode(), im.size


def call(model, prompt, data_url, extra, timeout=180):
    body = dict({'model': model, 'prompt': prompt, 'input_references': [{'type': 'image_url', 'image_url': {'url': data_url}}]}, **extra)
    req = urllib.request.Request(API, data=json.dumps(body).encode(), method='POST', headers={
        'Content-Type': 'application/json', 'Authorization': 'Bearer sk-or-v1-helykitolto'})
    ctx = ssl.create_default_context(cafile=CA) if os.path.exists(CA) else ssl.create_default_context()
    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as r:
            j = json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        msg = e.read().decode(errors='replace')[:300]
        return {'ok': False, 'err': f'HTTP {e.code}: {msg}', 'sec': time.time() - t0}
    except Exception as e:  # időtúllépés, hálózat
        return {'ok': False, 'err': str(e)[:300], 'sec': time.time() - t0}
    d = (j.get('data') or [{}])[0]
    if not d.get('b64_json'):
        return {'ok': False, 'err': 'nincs kép a válaszban: ' + json.dumps(j)[:300], 'sec': time.time() - t0}
    return {'ok': True, 'png': base64.b64decode(d['b64_json']), 'cost': (j.get('usage') or {}).get('cost'), 'sec': time.time() - t0}


def main():
    src, out, pf = sys.argv[1], sys.argv[2], sys.argv[3]
    prompt = open(pf, encoding='utf-8').read().strip()
    os.makedirs(out, exist_ok=True)
    photos = sorted(f for f in os.listdir(src) if f.lower().endswith(('.jpg', '.jpeg', '.png', '.webp', '.heic')))
    res, total = [], 0.0
    for f in photos:
        data_url, size = prep(os.path.join(src, f))
        base = os.path.splitext(f)[0]
        open(os.path.join(out, base + '_be.jpg'), 'wb').write(base64.b64decode(data_url.split(',', 1)[1]))
        row = {'photo': f, 'in': base + '_be.jpg', 'cells': []}
        for mid, name, extra in MODELS:
            r = call(mid, prompt, data_url, extra)
            if not r['ok']:  # egy újrapróba (pl. ideiglenes hiba, téves tartalomszűrés)
                time.sleep(2); r2 = call(mid, prompt, data_url, extra)
                if r2['ok']: r = r2
            cell = {'model': name, 'sec': round(r['sec'], 1)}
            if r['ok']:
                fn = f'{base}__{mid.split("/")[-1]}.png'
                open(os.path.join(out, fn), 'wb').write(r['png'])
                cell.update(file=fn, cost=r.get('cost'))
                total += float(r.get('cost') or 0)
            else:
                cell['err'] = r['err']
            row['cells'].append(cell)
            print(f, name, cell.get('cost'), cell['sec'], 's', cell.get('err', 'OK'), flush=True)
        res.append(row)
    json.dump({'prompt': prompt, 'rows': res, 'total': total}, open(os.path.join(out, 'eredmeny.json'), 'w'), ensure_ascii=False, indent=1)
    # összehasonlító oldal (helyi fájl – a fotók személyes adatok, nem tesszük közzé)
    th = ''.join(f'<th>{html.escape(n)}</th>' for _, n, _ in MODELS)
    rows = ''
    for r in res:
        tds = f'<td><img src="{html.escape(r["in"])}"><small>eredeti</small></td>'
        for c in r['cells']:
            info = f'{c["sec"]} mp' + (f' · ${c["cost"]:.3f}' if isinstance(c.get('cost'), (int, float)) else '')
            tds += f'<td><img src="{html.escape(c["file"])}"><small>{html.escape(info)}</small></td>' if c.get('file') else f'<td class="e"><small>{html.escape(c["err"])}</small></td>'
        rows += f'<tr>{tds}</tr>'
    page = ('<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AI-karikatúra összehasonlítás</title>'
            '<style>body{font:15px/1.4 system-ui,sans-serif;margin:16px;color:#1A1F2B;background:#F6F3EE}table{border-collapse:collapse;width:100%}th{position:sticky;top:0;background:#141C33;color:#fff;padding:8px;font-size:14px}'
            'td{vertical-align:top;padding:6px;border-bottom:1px solid #E7E2DA;width:20%}img{width:100%;border-radius:6px;display:block;background:#fff}small{display:block;color:#5B6275;margin-top:4px;font-variant-numeric:tabular-nums}.e small{color:#B42318;overflow-wrap:anywhere}'
            'pre{white-space:pre-wrap;background:#fff;border:1px solid #E7E2DA;border-radius:8px;padding:10px}</style></head><body>'
            f'<h1>AI-karikatúra összehasonlítás</h1><p>Összköltség: <b>${total:.2f}</b> · {len(res)} fotó × {len(MODELS)} modell</p><pre>{html.escape(prompt)}</pre>'
            f'<table><thead><tr><th>Eredeti</th>{th}</tr></thead><tbody>{rows}</tbody></table></body></html>')
    open(os.path.join(out, 'osszehasonlitas.html'), 'w', encoding='utf-8').write(page)
    print('KÉSZ', round(total, 3), 'USD', flush=True)


if __name__ == '__main__':
    main()
