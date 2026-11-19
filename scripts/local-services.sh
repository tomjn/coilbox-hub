#!/usr/bin/env bash
# Starts and stops the local Supabase stack, the hub's MariaDB and the PHP hub's dev server.
# `down` only stops what `up` started, so it is safe to run when unsure.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE="$ROOT/.local-services"
PROJECT_ID="coilbox-hub" # project_id in supabase/config.toml
PORT=8100 # the PHP hub, started with `php artisan serve` in hub-php
# The hub uses the database, auth, the REST API and storage. It uses none of these.
EXCLUDE="realtime,imgproxy,mailpit,postgres-meta,studio,edge-runtime,logflare,vector,supavisor"
# The same MariaDB version as the droplet. Port 3307, because a MariaDB
# installed on the Mac itself usually holds 3306. The data volume outlives the
# container, so `down` keeps the data.
MARIADB_CONTAINER="coilbox-hub-mariadb"
MARIADB_IMAGE="mariadb:11.8"
MARIADB_PORT=3307

usage() {
  echo "Usage: scripts/local-services.sh up|down [supabase|mariadb|dev]" >&2
  echo "       scripts/local-services.sh status" >&2
  exit 2
}

supabase_containers() {
  docker ps -q "$@" --filter "label=com.supabase.cli.project=$PROJECT_ID" 2>/dev/null || true
}

# True when the process runs in this checkout, at its root or in a folder under it.
# `php artisan serve` starts a server whose working directory is hub-php/public.
in_checkout() {
  local cwd
  cwd="$(lsof -a -p "$1" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p')"
  [ "$cwd" = "$ROOT" ] || [[ "$cwd" == "$ROOT"/* ]]
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
    echo "PHP hub is already running on http://localhost:$PORT, leaving it as it is"
    return
  fi
  if lsof -nP -tiTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    echo "Port $PORT is in use by something outside this checkout:" >&2
    lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >&2
    exit 1
  fi
  cd "$ROOT/hub-php"
  nohup php artisan serve --port="$PORT" >"$STATE/dev.log" 2>&1 &
  local pid=$!
  echo "$pid" >"$STATE/dev.pid"
  while kill -0 "$pid" 2>/dev/null && [ -z "$(dev_listeners)" ]; do
    sleep 1
  done
  if ! kill -0 "$pid" 2>/dev/null; then
    echo "PHP hub exited while starting:" >&2
    tail -n 20 "$STATE/dev.log" >&2
    rm "$STATE/dev.pid"
    exit 1
  fi
  echo "PHP hub running on http://localhost:$PORT, log at $STATE/dev.log"
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
  echo "PHP hub stopped"
}

mariadb_running() {
  [ -n "$(docker ps -q --filter "name=^$MARIADB_CONTAINER\$" 2>/dev/null || true)" ]
}

up_mariadb() {
  if mariadb_running; then
    echo "MariaDB is already running, leaving it as it is"
    return
  fi
  if [ -n "$(docker ps -aq --filter "name=^$MARIADB_CONTAINER\$")" ]; then
    docker start "$MARIADB_CONTAINER" >/dev/null
  else
    docker run -d --name "$MARIADB_CONTAINER" \
      -p "127.0.0.1:$MARIADB_PORT:3306" \
      -v "$MARIADB_CONTAINER:/var/lib/mysql" \
      -e MARIADB_DATABASE=hub -e MARIADB_USER=hub -e MARIADB_PASSWORD=hub \
      -e MARIADB_ROOT_PASSWORD=root \
      --health-cmd "healthcheck.sh --connect --innodb_initialized" \
      --health-interval 1s --health-retries 60 \
      "$MARIADB_IMAGE" >/dev/null
  fi
  touch "$STATE/mariadb.started"
  # The first start creates the database, which takes a few seconds.
  local health
  while true; do
    health="$(docker inspect -f '{{.State.Health.Status}}' "$MARIADB_CONTAINER")"
    [ "$health" = healthy ] && break
    if [ "$health" = unhealthy ]; then
      echo "MariaDB did not become ready. docker logs $MARIADB_CONTAINER says why." >&2
      exit 1
    fi
    sleep 1
  done
  echo "MariaDB running on 127.0.0.1:$MARIADB_PORT, database hub, user hub, password hub"
}

down_mariadb() {
  [ -f "$STATE/mariadb.started" ] || return 0
  docker stop "$MARIADB_CONTAINER" >/dev/null
  rm "$STATE/mariadb.started"
  echo "MariaDB stopped"
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
  if mariadb_running; then
    owner="started outside this script, so down will leave it"
    [ -f "$STATE/mariadb.started" ] && owner="started by this script"
    echo "MariaDB: running on 127.0.0.1:$MARIADB_PORT ($owner)"
  else
    echo "MariaDB: stopped"
  fi
  pids="$(dev_listeners | tr '\n' ' ')"
  if [ -n "$pids" ]; then
    owner="started outside this script, so down will leave it"
    [ -f "$STATE/dev.pid" ] && owner="started by this script"
    echo "PHP hub: running on http://localhost:$PORT, pid ${pids% } ($owner)"
  else
    echo "PHP hub: stopped"
  fi
}

[ $# -ge 1 ] || usage
command="$1"
target="${2:-all}"
case "$target" in all | supabase | mariadb | dev) ;; *) usage ;; esac
wants() { [ "$target" = all ] || [ "$target" = "$1" ]; }

mkdir -p "$STATE"
case "$command" in
  up)
    if wants supabase; then up_supabase; fi
    if wants mariadb; then up_mariadb; fi
    if wants dev; then up_dev; fi
    ;;
  down)
    if wants dev; then down_dev; fi
    if wants mariadb; then down_mariadb; fi
    if wants supabase; then down_supabase; fi
    ;;
  status) status ;;
  *) usage ;;
esac
