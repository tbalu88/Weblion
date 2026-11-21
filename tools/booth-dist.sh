#!/usr/bin/env bash
# Crazy Booth – programfrissítés közzététele a saját szerveren (2026-11-21-től a GitHub-tároló privát).
# A GitHub-kiadásból (booth-v<verzió>) a kis csomagot (programkód, ~3 MB) és az adatait a tartalékra teszi:
#   tartalek.eventcrazy.hu/wp-content/plugins/crazy-booth-ui/CrazyBooth-<v>-app.zip  +  booth-latest.json
# A gépek önfrissítője (0.19.3-tól) innen frissít. Teljes csomag (motorváltás) itt nincs – az kézi telepítés.
# Használat: bash tools/booth-dist.sh <verzió> ["megjegyzés"]
set -euo pipefail
cd "$(dirname "$0")/.."
V="${1:?verzió kell, pl. 0.19.3}"; NOTE="${2:-}"
API=https://api.github.com/repos/tbalu88/Weblion
B=https://tartalek.eventcrazy.hu/wp-json/crazy-bridge/v1
DEST=plugins/crazy-booth-ui
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
curl -s "$API/releases/tags/booth-v$V" > "$T/rel.json"
id() { python3 -c 'import sys,json;n=sys.argv[2];print(next((str(a["id"]) for a in json.load(open(sys.argv[1]))["assets"] if a["name"]==n),""))' "$T/rel.json" "$1"; }
AJ=$(id "CrazyBooth-$V-app.json"); AZ=$(id "CrazyBooth-$V-app.zip")
[ -n "$AJ" ] && [ -n "$AZ" ] || { echo "HIBA: a booth-v$V kiadásban nincs kis csomag"; exit 1; }
curl -sfL -H 'Accept: application/octet-stream' -o "$T/app.json" "$API/releases/assets/$AJ"
curl -sfL -H 'Accept: application/octet-stream' -o "$T/app.zip" "$API/releases/assets/$AZ"
SHA=$(sha256sum "$T/app.zip" | cut -c1-64)
python3 - "$T/app.json" "$SHA" <<'PY'
import sys,json
m=json.load(open(sys.argv[1]))
assert m["sha256"]==sys.argv[2], "ellenőrzőösszeg-eltérés"
PY
live() { curl -s --max-time 30 "$B/file?path=$1" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("sha256",""))' 2>/dev/null || true; }
F="CrazyBooth-$V-app.zip"
bash tools/tartalek-deploy.sh "$T/app.zip" "$DEST/$F" "$(live "$DEST/$F")" >/dev/null || { echo "HIBA: $F feltöltése"; exit 1; }
python3 - "$T/app.json" "$F" "$(stat -c %s "$T/app.zip")" "$NOTE" > "$T/latest.json" <<'PY'
import sys,json
m=json.load(open(sys.argv[1])); f,size,note=sys.argv[2],int(sys.argv[3]),sys.argv[4]
print(json.dumps({"v":m["v"],"electron":m["electron"],"dcc":m.get("dcc",False),"note":note,
  "app":{"file":f,"size":size,"sha256":m["sha256"]},"full":{}},ensure_ascii=False,indent=1))
PY
bash tools/tartalek-deploy.sh "$T/latest.json" "$DEST/booth-latest.json" "$(live "$DEST/booth-latest.json")" >/dev/null || { echo "HIBA: latest.json"; exit 1; }
curl -s "https://tartalek.eventcrazy.hu/wp-content/plugins/crazy-booth-ui/booth-latest.json?t=$(date +%s)"; echo
echo "kész: $V (kis csomag $(stat -c %s "$T/app.zip") bájt)"
