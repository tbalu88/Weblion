#!/usr/bin/env bash
# Crazy Booth mobil (PWA) közzététele a tartalékon: wp-content/plugins/crazy-booth-ui/mobil*.{html,css,js,json} + qrcode.min.js
# (a compose.js-t a booth-publish.sh tölti fel, ugyanide). Elérhető: https://tartalek.eventcrazy.hu/galeria/app/
set -euo pipefail
cd "$(dirname "$0")/.."
B=https://tartalek.eventcrazy.hu/wp-json/crazy-bridge/v1
DEST=plugins/crazy-booth-ui
live() { curl -s --max-time 30 "$B/file?path=$1" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("sha256",""))' 2>/dev/null || true; }
for f in mobil.html mobil.css mobil.js mobil-sw.js mobil-manifest.json qrcode.min.js; do
	cur=$(live "$DEST/$f")
	bash tools/tartalek-deploy.sh "booth/mobile/$f" "$DEST/$f" "$cur" >/dev/null || { echo "HIBA: $f"; exit 1; }
	echo "feltöltve: $f"
done
