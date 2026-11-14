#!/usr/bin/env python3
"""A CI-hez: az `xcresulttool export attachments` kimenetéből (manifest.json) olvasható nevű képernyőképek."""
import json
import os
import shutil
import sys


def walk(node, out):
    if isinstance(node, dict):
        if 'exportedFileName' in node:
            out.append(node)
        for v in node.values():
            walk(v, out)
    elif isinstance(node, list):
        for v in node:
            walk(v, out)


def main():
    src, dst = sys.argv[1], sys.argv[2]
    manifest = os.path.join(src, 'manifest.json')
    if not os.path.exists(manifest):
        print('nincs manifest.json')
        return 0
    items = []
    walk(json.load(open(manifest)), items)
    os.makedirs(dst, exist_ok=True)
    for it in items:
        name = it.get('suggestedHumanReadableName') or it['exportedFileName']
        # „1-kamera_0_<uuid>.png” → „1-kamera.png”
        base, ext = os.path.splitext(name)
        base = base.split('_')[0]
        shutil.copy(os.path.join(src, it['exportedFileName']), os.path.join(dst, base + ext))
        print(base + ext)
    return 0


if __name__ == '__main__':
    sys.exit(main())
