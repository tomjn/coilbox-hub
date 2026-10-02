#!/usr/bin/env bash
# Starts and stops the local Supabase stack and the dev server.
# `down` only stops what `up` started, so it is safe to run when unsure.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE="$ROOT/.local-services"
PROJECT_ID="coilbox-hub" # project_id in supabase/config.toml
PORT=3000
# The hub uses the database, auth, the REST API and storage. It uses none of these.
EXCLUDE="realtime,imgproxy,mailpit,postgres-meta,studio,edge-runtime,logflare,vector,supavisor"

usage() {
  echo "Usage: scripts/local-services.sh up|down [supabase|dev]" >&2
  echo "       scripts/local-services.sh status" >&2
  exit 2
}

supabase_containers() {
  docker ps -q "$@" --filter "label=com.supabase.cli.project=$PROJECT_ID" 2>/dev/null || true
}

in_checkout() {
  [ "$(lsof -a -p "$1" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p')" = "$ROOT" ]
}

# A server from another project on the same port is not ours to report or stop.
dev_listeners() {
  local pid
  for pid in $(lsof -nP -tiTCP:"$PORT" -sTCP:LISTEN 2>/dev/null || true); do
    if in_checkout "$pid"; then echo "$pid"; fi
  done
}

descendants() {
  local child
  for child in $(pgrep -P "$1" || true); do
    descendants "$child"
    echo "$child"
  done
}

up_supabase() {
  if [ -n "$(supabase_containers)" ]; then
    echo "Supabase is already running, leaving it as it is"
    return
  fi
  # Containers stopped with `docker stop` still exist, and the CLI will not start over them.
  if [ -n "$(supabase_containers -a)" ]; then
    supabase stop --project-id "$PROJECT_ID"
  fi
  (cd "$ROOT" && supabase start -x "$EXCLUDE")
  touch "$STATE/supabase.started"
}

up_dev() {
  if [ -n "$(dev_listeners)" ]; then
    echo "Dev server is already running on http://localhost:$PORT, leaving it as it is"
    return
  fi
  if lsof -nP -tiTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    echo "Port $PORT is in use by something outside this checkout:" >&2
    lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >&2
    exit 1
  fi
  cd "$ROOT"
  nohup bun run dev -- -p "$PORT" >"$STATE/dev.log" 2>&1 &
  local pid=$!
  echo "$pid" >"$STATE/dev.pid"
  while kill -0 "$pid" 2>/dev/null && [ -z "$(dev_listeners)" ]; do
    sleep 1
  done
  if ! kill -0 "$pid" 2>/dev/null; then
    echo "Dev server exited while starting:" >&2
    tail -n 20 "$STATE/dev.log" >&2
    rm "$STATE/dev.pid"
    exit 1
  fi
  echo "Dev server running on http://localhost:$PORT, log at $STATE/dev.log"
}

down_dev() {
  [ -f "$STATE/dev.pid" ] || return 0
  local pid pids
  pid="$(<"$STATE/dev.pid")"
  pids="$(dev_listeners)"
  # The recorded pid may have been reused by an unrelated process since `up`.
  if in_checkout "$pid"; then
    pids="$pids $pid $(descendants "$pid")"
  fi
  # shellcheck disable=SC2086
  [ -z "${pids// /}" ] || kill $pids 2>/dev/null || true
  while [ -n "$(dev_listeners)" ]; do
    sleep 1
  done
  rm "$STATE/dev.pid"
  echo "Dev server stopped"
}

down_supabase() {
  [ -f "$STATE/supabase.started" ] || return 0
  supabase stop --project-id "$PROJECT_ID"
  rm "$STATE/supabase.started"
}

status() {
  local owner pids
  if [ -n "$(supabase_containers)" ]; then
    owner="started outside this script, so down will leave it"
    [ -f "$STATE/supabase.started" ] && owner="started by this script"
    echo "Supabase: running ($owner)"
  else
    echo "Supabase: stopped"
  fi
  pids="$(dev_listeners | tr '\n' ' ')"
  if [ -n "$pids" ]; then
    owner="started outside this script, so down will leave it"
    [ -f "$STATE/dev.pid" ] && owner="started by this script"
    echo "Dev server: running on http://localhost:$PORT, pid ${pids% } ($owner)"
  else
    echo "Dev server: stopped"
  fi
}

[ $# -ge 1 ] || usage
command="$1"
target="${2:-all}"
case "$target" in all | supabase | dev) ;; *) usage ;; esac

mkdir -p "$STATE"
case "$command" in
  up)
    [ "$target" = dev ] || up_supabase
    [ "$target" = supabase ] || up_dev
    ;;
  down)
    [ "$target" = supabase ] || down_dev
    [ "$target" = dev ] || down_supabase
    ;;
  status) status ;;
  *) usage ;;
esac
