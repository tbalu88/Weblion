#!/usr/bin/env bash
# TARTALÉK SZERVER (tartalek.eventcrazy.hu) – egy fájl feltöltése a crazy-bridge hídon keresztül.
# Használat: bash tools/cp-deploy.sh <helyi fájl> <élő út, pl. plugins/crazy-panel/mail.php> <várt élő sha256 vagy "" (új fájl)>
# A híd ellenőrzi, hogy az élő fájl a megadott sha-val egyezik-e (nem írja felül, amit közben más módosított),
# szintaxist néz, és hiba esetén visszaállít. A hitelesítést a környezet proxyja adja, kulcs nem kell.
set -euo pipefail
[ $# -eq 3 ] || { echo "Használat: $0 <helyi fájl> <élő út> <alap-sha vagy \"\">" >&2; exit 2; }
B=https://tartalek.eventcrazy.hu/wp-json/crazy-bridge/v1
R=$(mktemp)
trap 'rm -f "$R"' EXIT
python3 -c 'import sys,json,base64;c=open(sys.argv[1],"rb").read();print(json.dumps({"path":sys.argv[2],"content_b64":base64.b64encode(c).decode(),"sha256":sys.argv[3],"check":True}))' "$1" "$2" "$3" > "$R"
for i in 1 2 3; do
	out=$(curl -s -X POST "$B/file" -H 'Content-Type: application/json' -d @"$R")
	echo "$2: $(echo "$out" | head -c 110)"
	echo "$out" | grep -q upstream || break
	sleep 3
done
echo "$out" | grep -q '"ok":true'
