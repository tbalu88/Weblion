#!/usr/bin/env bash
# Crazy Booth – a felület (booth/src/renderer) közzététele a tartalékon: wp-content/plugins/crazy-booth-ui/
# A gépek induláskor (és 15 percenként) megnézik a manifest.json-t, és ha újabb, letöltik (sha256-ellenőrzéssel).
# Használat: bash tools/booth-publish.sh "rövid leírás a változásról"
set -euo pipefail
cd "$(dirname "$0")/.."
SRC=booth/src/renderer
B=https://tartalek.eventcrazy.hu/wp-json/crazy-bridge/v1
DEST=plugins/crazy-booth-ui
# v0.11: az offline sablonszerkesztő is (a panel sablon.js/css-e – egy forrás: crazy-panel/assets – és az adatcsomag)
FILES="index.html app.js compose.js clips.js style.css sablon.js sablon.css sablon-adat.js"
cp crazy-panel/assets/sablon.js crazy-panel/assets/sablon.css "$SRC/"
[ -s "$SRC/sablon-adat.js" ] || { echo "HIBA: hiányzik a sablon-adat.js (python3 -I tools/booth-sablon-assets.py)"; exit 1; }
SHELL_MIN=$(python3 -c "import json;print(json.load(open('booth/package.json')).get('uiShellMin','0.4.0'))")
V=$(date -u +%Y%m%d%H%M%S)
live() { curl -s --max-time 30 "$B/file?path=$1" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("sha256",""))' 2>/dev/null || true; }
for f in $FILES; do
	cur=$(live "$DEST/$f")
	bash tools/tartalek-deploy.sh "$SRC/$f" "$DEST/$f" "$cur" >/dev/null || { echo "HIBA: $f"; exit 1; }
	echo "feltöltve: $f"
done
MAN=$(mktemp)
python3 - "$SRC" "$V" "$SHELL_MIN" "${1:-}" "$FILES" > "$MAN" <<'PY'
import sys,json,hashlib,os
src,v,smin,note,fl=sys.argv[1:6]
files={f:hashlib.sha256(open(os.path.join(src,f),'rb').read()).hexdigest() for f in fl.split()}
print(json.dumps({"v":v,"shell":smin,"note":note,"files":files},ensure_ascii=False,indent=1))
PY
bash tools/tartalek-deploy.sh "$MAN" "$DEST/manifest.json" "$(live $DEST/manifest.json)" >/dev/null && echo "manifest: $V"
rm -f "$MAN"
