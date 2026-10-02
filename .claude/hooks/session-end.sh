#!/usr/bin/env bash
# SessionEnd hook. Stops the local services this checkout's sessions started,
# unless another Claude session is still open here.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
STATE="$ROOT/.local-services"
LOG="$STATE/session-end.log"
mkdir -p "$STATE"

reason="$(jq -r '.reason // "unknown"' 2>/dev/null || echo unknown)"
log() { echo "$(date '+%Y-%m-%d %H:%M:%S') reason=$reason $*" >>"$LOG"; }

# /clear ends the session record and carries on in the same process.
if [ "$reason" = clear ]; then
  log "skipped, the session continues"
  exit 0
fi

# The ending session is one of this hook's ancestors, so it does not count as open.
ancestors=" "
pid=$$
while [ "$pid" -gt 1 ]; do
  ancestors="$ancestors$pid "
  pid="$(ps -o ppid= -p "$pid" | tr -d ' ')"
  [ -n "$pid" ] || break
done

for pid in $(pgrep -x claude || true); do
  case "$ancestors" in *" $pid "*) continue ;; esac
  cwd="$(lsof -a -p "$pid" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p')"
  case "$cwd/" in
    "$ROOT"/*)
      log "skipped, session $pid is still open here"
      exit 0
      ;;
  esac
done

# SessionEnd hooks get very little time, and `supabase stop` needs more than that.
log "running down"
nohup "$ROOT/scripts/local-services.sh" down >>"$LOG" 2>&1 &
exit 0
