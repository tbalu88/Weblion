#!/usr/bin/env python3
"""A Crazy 360 iPhone-app ikonja (1024×1024, átlátszóság nélkül – az App Store így kéri).
Futtatás: python3 -I tools/ios-icon.py   →   ios/Crazy360/Resources/Assets.xcassets/AppIcon.appiconset/icon-1024.png
Színek a mobil appból: sötétkék háttér (#141C33 → #0E1220), narancs ív (#E8894A → #F2B35B)."""
import math
import os
import sys

from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'ios', 'Crazy360', 'Resources', 'Assets.xcassets', 'AppIcon.appiconset', 'icon-1024.png')
FONT = '/usr/share/fonts/opentype/inter/InterDisplay-Bold.otf'
S = 2048  # kétszeres méretben rajzolunk, a végén kicsinyítünk (simább élek)


def lerp(a, b, t):
    return tuple(round(a[i] + (b[i] - a[i]) * t) for i in range(3))


def main():
    top, bottom = (0x14, 0x1C, 0x33), (0x0E, 0x12, 0x20)
    o1, o2 = (0xE8, 0x89, 0x4A), (0xF2, 0xB3, 0x5B)
    img = Image.new('RGB', (S, S))
    px = ImageDraw.Draw(img)
    for y in range(S):
        px.line([(0, y), (S, y)], fill=lerp(top, bottom, y / (S - 1)))

    # narancs ív (forgás): 300°-os gyűrű, a vége nyílhegy
    cx, cy, r, w = S / 2, S / 2, S * 0.36, S * 0.075
    start, sweep = 120.0, 300.0
    steps = 600
    for i in range(steps):
        t = i / (steps - 1)
        a = math.radians(start + sweep * t)
        x, y = cx + r * math.cos(a), cy + r * math.sin(a)
        c = lerp(o1, o2, t)
        px.ellipse([x - w / 2, y - w / 2, x + w / 2, y + w / 2], fill=c)
    a = math.radians(start + sweep)
    tip = (cx + r * math.cos(a + 0.22), cy + r * math.sin(a + 0.22))
    nx, ny = math.cos(a), math.sin(a)
    head = w * 1.15
    base1 = (cx + (r + head) * nx, cy + (r + head) * ny)
    base2 = (cx + (r - head) * nx, cy + (r - head) * ny)
    px.polygon([base1, base2, tip], fill=o2)

    # „360” középen
    font = ImageFont.truetype(FONT, int(S * 0.27))
    text = '360'
    bb = px.textbbox((0, 0), text, font=font)
    tw, th = bb[2] - bb[0], bb[3] - bb[1]
    px.text((cx - tw / 2 - bb[0], cy - th / 2 - bb[1]), text, font=font, fill=(255, 255, 255))

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    img.resize((1024, 1024), Image.LANCZOS).save(OUT, 'PNG', optimize=True)
    print(OUT)


if __name__ == '__main__':
    sys.exit(main())
