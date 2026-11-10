#!/usr/bin/env bash
# Compares GET responses for several paths between the Next app and the PHP
# app. Prints, per path, whether the bodies are byte identical, identical
# once JSON keys are sorted, or different.
#
#   scripts/compare-paths.sh /export /api/v1/items '/api/v1/items?page=2'
set -uo pipefail
NEXT=${NEXT:-http://localhost:3000}
PHP=${PHP:-http://localhost:8100}
d=$(mktemp -d)
trap 'rm -rf "$d"' EXIT
for p in "$@"; do
  curl -s "$NEXT$p" -o "$d/n"
  curl -s "$PHP$p" -o "$d/p"
  if cmp -s "$d/n" "$d/p"; then
    echo "bytes same: $p"
    continue
  fi
  jq -S . "$d/n" > "$d/nj" 2>/dev/null || cp "$d/n" "$d/nj"
  jq -S . "$d/p" > "$d/pj" 2>/dev/null || cp "$d/p" "$d/pj"
  if cmp -s "$d/nj" "$d/pj"; then
    echo "same with keys sorted: $p"
  else
    echo "DIFFERENT: $p"
  fi
done
