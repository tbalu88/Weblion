#!/usr/bin/env python3
"""A CI-hez: az `xcrun simctl list devices available -j` kimenetéből kiválaszt egy iPhone-szimulátort,
a kiválasztott Xcode SDK-jánál nem újabb, legújabb iOS-futtatókörnyezetből. Kiírja az UDID-t (stdout)."""
import json
import re
import sys


def ver(s):
    return tuple(int(x) for x in re.findall(r'\d+', s)[:2])


def main():
    sdk = ver(sys.argv[1]) if len(sys.argv) > 1 else (999, 0)
    devs = json.load(sys.stdin)['devices']
    best = None
    for rt, lst in devs.items():
        m = re.search(r'iOS-(\d+)-(\d+)', rt)
        if not m:
            continue
        v = (int(m.group(1)), int(m.group(2)))
        if v > sdk:
            continue
        phones = [d for d in lst if d.get('isAvailable', True) and d['name'].startswith('iPhone')]
        if phones and (best is None or v > best[0]):
            best = (v, phones[0]['udid'], phones[0]['name'])
    if not best:
        print('Nincs iPhone-szimulátor', file=sys.stderr)
        return 1
    print('Szimulátor: %s, iOS %d.%d' % (best[2], best[0][0], best[0][1]), file=sys.stderr)
    print(best[1])
    return 0


if __name__ == '__main__':
    sys.exit(main())
