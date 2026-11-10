#!/usr/bin/env bash
# Compare one request against the Next app (the reference) and the PHP app.
#
#   scripts/parity.sh GET '/api/v1/items?kind=preset'
#   scripts/parity.sh POST /api/v1/maps/lookup body.json
#
# Prints the status and the headers that matter to callers for both, then a
# diff of the bodies with JSON keys sorted and each app's own origin replaced
# by ORIGIN. Nothing after "body:" means the bodies match.
#
# NEXT and PHP override the two base URLs. AUTH sends a bearer token.
set -euo pipefail
NEXT=${NEXT:-http://localhost:3000}
PHP=${PHP:-http://localhost:8100}
method=$1
path=$2
body=${3:-}
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

fetch() {
  local base=$1 out=$2
  local args=(-s -X "$method" -D "$out.h" -o "$out.raw" --max-time 60)
  if [ -n "$body" ]; then args+=(-H 'Content-Type: application/json' --data-binary "@$body"); fi
  if [ -n "${AUTH:-}" ]; then args+=(-H "Authorization: Bearer $AUTH"); fi
  curl "${args[@]}" "$base$path" || true
  sed "s#$base#ORIGIN#g" "$out.raw" > "$out.b"
  if jq -S . "$out.b" > "$out.j" 2>/dev/null; then mv "$out.j" "$out.b"; fi
}

fetch "$NEXT" "$tmp/next"
fetch "$PHP" "$tmp/php"

for side in next php; do
  printf '%-5s ' "$side"
  grep -iE '^(HTTP/|cache-control|access-control-allow-origin|content-type|location)' "$tmp/$side.h" | tr -d '\r' | tr '\n' '|'
  echo
done
echo "body:"
diff "$tmp/next.b" "$tmp/php.b" || true
