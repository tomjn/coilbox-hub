#!/usr/bin/env bash
# Local end to end run of upload and sync across two mirrors.
#
# It starts two copies of the mirror from ~/dev/coilbox-mirror and the hub in hub-php/,
# registers the mirrors in the local database, uploads a test map as a signed-in user,
# syncs both mirrors and checks the file and the signed catalogue.
#
# Usage: scripts/mirrors-e2e.sh [--keep]
#
# Needs local Supabase already running (start it with scripts/local-services.sh up).
# It never touches the linked production project. With --keep the temp folder and its
# logs are left in place. The servers and database rows are always cleaned up.
# The last line printed is "e2e ok" and the exit status is 0 when every check passes.

set -euo pipefail

KEEP=0
case "${1:-}" in
  --keep) KEEP=1 ;;
  "") ;;
  *)
    echo "usage: scripts/mirrors-e2e.sh [--keep]" >&2
    exit 2
    ;;
esac

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MIRROR_REPO="${COILBOX_MIRROR_REPO:-$HOME/dev/coilbox-mirror}"
HUB_PORT=8201
MIRROR_A_PORT=8202
MIRROR_B_PORT=8203
HUB_URL="http://127.0.0.1:$HUB_PORT"
PHP84_BIN="$(brew --prefix php@8.4)/bin"

TMP=""
DB_CONTAINER=""
USER_ID=""
JJ_DISABLED=0
MIRRORS_INSERTED=0
STARTED_PORTS=()
API_URL=""
SERVICE_KEY=""

die() {
  echo "e2e FAILED: $*" >&2
  exit 1
}

# Mirror code needs PHP 8.4. The hub and these scripts use the default php.
php84() {
  PATH="$PHP84_BIN:$PATH" php "$@"
}

# Runs SQL from stdin in the local database container.
sql() {
  docker exec -i "$DB_CONTAINER" psql -U postgres -v ON_ERROR_STOP=1 -qAt
}

# Reads one key from a JSON object on stdin. Fails if the key is missing or not text.
json_get() {
  php -r '$j = json_decode(stream_get_contents(STDIN), true); $v = is_array($j) ? ($j[$argv[1]] ?? null) : null; if (!is_string($v)) { exit(1); } echo $v;' "$1"
}

# Calls the local Auth API. The key goes in through a curl config on stdin so it
# never appears in a process list. Arguments after the third are passed to curl.
auth_call() {
  local key="$1" method="$2" path="$3"
  shift 3
  printf 'header = "apikey: %s"\nheader = "Authorization: Bearer %s"\n' "$key" "$key" |
    curl -sS -K - -X "$method" "$API_URL$path" "$@"
}

cleanup() {
  local status=$?
  trap - EXIT ERR
  set +e
  if [[ $status -ne 0 && -n "$TMP" && -d "$TMP" ]]; then
    for log in "$TMP"/*.log; do
      [[ -f "$log" ]] || continue
      echo "--- $(basename "$log") (last lines) ---" >&2
      tail -n 20 "$log" >&2
    done
  fi

  # Stop what this run started. The ports were free at the start, so anything
  # listening on them now is ours. This includes the child that artisan serve spawns.
  for port in "${STARTED_PORTS[@]:-}"; do
    [[ -n "$port" ]] || continue
    pids="$(lsof -ti "tcp:$port" -sTCP:LISTEN 2>/dev/null)"
    [[ -z "$pids" ]] || kill $pids 2>/dev/null
  done

  if [[ -n "$DB_CONTAINER" ]]; then
    if [[ $MIRRORS_INSERTED -eq 1 ]]; then
      # Archives first, because archive.upload_mirror_id points at the mirrors.
      # archive_mirror rows go with them by cascade.
      printf "delete from public.archive where internal_name like 'E2E Map v1.0 %%' or upload_mirror_id in ('e2e-a', 'e2e-b');\ndelete from public.mirror where id in ('e2e-a', 'e2e-b');\n" | sql >/dev/null
    fi
    if [[ $JJ_DISABLED -eq 1 ]]; then
      echo "update public.mirror set enabled = true where id = 'jj-darkstars';" | sql >/dev/null
    fi
  fi

  if [[ -n "$USER_ID" && -n "$API_URL" ]]; then
    auth_call "$SERVICE_KEY" DELETE "/auth/v1/admin/users/$USER_ID" -o /dev/null
  fi

  if [[ -n "$TMP" && -d "$TMP" ]]; then
    if [[ $KEEP -eq 1 ]]; then
      echo "kept $TMP"
    else
      rm -rf "$TMP"
    fi
  fi
  exit $status
}
trap cleanup EXIT
trap 'echo "e2e FAILED: command failed at line $LINENO: $BASH_COMMAND" >&2' ERR

# --- Checks before anything starts ---

[[ -d "$MIRROR_REPO" ]] || die "mirror repository not found at $MIRROR_REPO"
for port in $HUB_PORT $MIRROR_A_PORT $MIRROR_B_PORT
do
  if lsof -ti "tcp:$port" -sTCP:LISTEN >/dev/null 2>&1
  then
    die "port $port is already in use"
  fi
done

DB_CONTAINER="$(docker ps --filter name=supabase_db --format '{{.Names}}' | head -n 1)"
[[ -n "$DB_CONTAINER" ]] || die "no local Supabase database container is running"

STATUS_JSON="$(supabase status -o json 2>/dev/null)" || die "supabase status failed, is local Supabase running?"
API_URL="$(json_get API_URL <<<"$STATUS_JSON")"
# The older JWT style keys are preferred when present. Otherwise use the newer names.
PUBLISHABLE_KEY="$(json_get ANON_KEY <<<"$STATUS_JSON" || json_get PUBLISHABLE_KEY <<<"$STATUS_JSON")"
SERVICE_KEY="$(json_get SERVICE_ROLE_KEY <<<"$STATUS_JSON" || json_get SECRET_KEY <<<"$STATUS_JSON")"
JWT_SECRET="$(json_get JWT_SECRET <<<"$STATUS_JSON")"
unset STATUS_JSON

case "$API_URL" in
  http://127.0.0.1:*|http://localhost:*) ;;
  *) die "supabase status reports a non-local API URL, refusing to continue" ;;
esac

TMP="$(mktemp -d "${TMPDIR:-/tmp}/mirrors-e2e.XXXXXX")"
echo "temp folder $TMP"

# --- 1. Two mirrors, each with its own key pair ---

# The same exclude list as the mirror plan's deploy step, so each copy holds only
# what a real mirror gets.
RSYNC_EXCLUDES=(
  --exclude .git --exclude vendor --exclude tests --exclude fixtures
  --exclude composer.json --exclude composer.lock --exclude phpunit.xml.dist
  --exclude 'maps/*.sd*' --exclude 'games/*.sd*'
  --exclude private/config.php --exclude private/state
  --exclude '.partial/upload-*' --exclude '.partial/sync-*'
  --exclude /catalogue.json --exclude /catalogue.sig --exclude /listing.json
  --exclude .phpunit.cache --exclude README.md --exclude PROTOCOL.md
)

# Hub test key pair, as base64url with no padding.
read -r HUB_SECRET HUB_PUBLIC <<<"$(php -r '$kp = sodium_crypto_sign_keypair(); $v = SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING; echo sodium_bin2base64(sodium_crypto_sign_secretkey($kp), $v), " ", sodium_bin2base64(sodium_crypto_sign_publickey($kp), $v);')"

declare -A MIRROR_PUBLIC
for id in a b
do
  dir="$TMP/mirror-$id"
  mkdir -p "$dir"
  rsync -a "${RSYNC_EXCLUDES[@]}" "$MIRROR_REPO/" "$dir/"
  mkdir -p "$dir/maps" "$dir/games" "$dir/.partial" "$dir/private"

  # keygen.php prints the secret key on a line holding 'secret_key' and the public
  # key on a line of its own.
  keygen_out="$(cd "$MIRROR_REPO" && php84 bin/keygen.php)"
  secret="$(sed -n "s/.*'secret_key' => '\\([A-Za-z0-9_-]*\\)'.*/\\1/p" <<<"$keygen_out")"
  public="$(grep -E '^[A-Za-z0-9_-]{43}$' <<<"$keygen_out")"
  [[ -n "$secret" && -n "$public" ]] || die "could not read the key pair from bin/keygen.php"
  MIRROR_PUBLIC[$id]="$public"

  cat >"$dir/private/config.php" <<PHP
<?php
return [
    'mirror_id' => 'e2e-$id',
    'secret_key' => '$secret',
    'hub_url' => '$HUB_URL',
    'hub_keys' => ['$HUB_PUBLIC'],
    'partial_lifetime' => 86400,
    'sync_time_limit' => 0,
];
PHP
  chmod 600 "$dir/private/config.php"
  unset secret
done

# --- 2. Register the mirrors in the local database ---

echo "select 1;" | sql >/dev/null || die "cannot run SQL in $DB_CONTAINER"
MIRRORS_INSERTED=1
sql >/dev/null <<SQL
delete from public.archive where internal_name like 'E2E Map v1.0 %' or upload_mirror_id in ('e2e-a', 'e2e-b');
delete from public.mirror where id in ('e2e-a', 'e2e-b');
insert into public.mirror (id, name, base_url, public_key, enabled, healthy) values
  ('e2e-a', 'E2E mirror A', 'http://127.0.0.1:$MIRROR_A_PORT', '${MIRROR_PUBLIC[a]}', true, true),
  ('e2e-b', 'E2E mirror B', 'http://127.0.0.1:$MIRROR_B_PORT', '${MIRROR_PUBLIC[b]}', true, true);
SQL
# Disabled for the run so the real mirror gets no upload. Cleanup turns it back on.
echo "update public.mirror set enabled = false where id = 'jj-darkstars';" | sql >/dev/null
JJ_DISABLED=1

# --- 3. Start the mirrors and the hub ---

start_mirror() {
  local id="$1" port="$2"
  STARTED_PORTS+=("$port")
  # Workers are required. The hub health-checks a mirror while that mirror is
  # still waiting on its own upload report to the hub.
  (cd "$TMP/mirror-$id" && PHP_CLI_SERVER_WORKERS=4 PATH="$PHP84_BIN:$PATH" \
    nohup php -S "127.0.0.1:$port" -t "$TMP/mirror-$id" "$MIRROR_REPO/tests/Support/mirror-router.php" \
    >"$TMP/mirror-$id-server.log" 2>&1 &)
}
start_mirror a $MIRROR_A_PORT
start_mirror b $MIRROR_B_PORT

STARTED_PORTS+=("$HUB_PORT")
(
  cd "$REPO/hub-php"
  export SUPABASE_URL="$API_URL" SUPABASE_PUBLISHABLE_KEY="$PUBLISHABLE_KEY" SUPABASE_SERVICE_ROLE_KEY="$SERVICE_KEY"
  export HUB_MIRROR_SECRET_KEY="$HUB_SECRET" HUB_MIRROR_NEXT_SECRET_KEY=""
  export CACHE_STORE=array SESSION_DRIVER=array PHP_CLI_SERVER_WORKERS=4
  nohup php artisan serve --host=127.0.0.1 --port=$HUB_PORT --no-reload >"$TMP/hub.log" 2>&1 &
)

# curl retries a refused connection, so this waits for each server without a sleep loop.
for url in "http://127.0.0.1:$MIRROR_A_PORT/limits.php" "http://127.0.0.1:$MIRROR_B_PORT/limits.php" "$HUB_URL/api/v1/catalogue"
do
  curl -s -o /dev/null --retry 20 --retry-connrefused --retry-delay 1 --max-time 30 "$url" || die "$url did not come up"
done
echo "mirrors and hub are up"

# --- 4. A local test user and its access token ---

EMAIL="e2e-$(openssl rand -hex 6)@example.test"
PASSWORD="$(openssl rand -hex 16)"
created="$(auth_call "$SERVICE_KEY" POST /auth/v1/admin/users \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"email_confirm\":true}")"
USER_ID="$(json_get id <<<"$created")" || die "could not create the test user: $created"

signin="$(auth_call "$PUBLISHABLE_KEY" POST '/auth/v1/token?grant_type=password' \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")"
if ! E2E_ACCESS_TOKEN="$(json_get access_token <<<"$signin")"
then
  # The local project has email sign in turned off, because the hub signs people in
  # with GitHub. So the run signs a short lived token for the test user with the local
  # project's own JWT secret instead. The secret only works against local Supabase.
  echo "password sign in is off locally ($(json_get error_code <<<"$signin" || echo unknown)), signing a local token instead"
  # Auth only accepts a token whose session exists, so make one. It goes when the user does.
  SESSION_ID="$(php -r 'echo sprintf("%s-%s-4%s-8%s-%s", bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));')"
  echo "insert into auth.sessions (id, user_id, aal, created_at, updated_at) values ('$SESSION_ID', '$USER_ID', 'aal1', now(), now());" | sql >/dev/null
  E2E_ACCESS_TOKEN="$(LOCAL_JWT_SECRET="$JWT_SECRET" php -r '
    $b64 = fn (string $s): string => rtrim(strtr(base64_encode($s), "+/", "-_"), "=");
    $now = time();
    $claims = ["aud" => "authenticated", "role" => "authenticated", "sub" => $argv[1], "iat" => $now, "exp" => $now + 600,
      "aal" => "aal1", "email" => $argv[2], "session_id" => $argv[3]];
    $head = $b64(json_encode(["alg" => "HS256", "typ" => "JWT"]));
    $body = $b64(json_encode($claims));
    echo $head.".".$body.".".$b64(hash_hmac("sha256", $head.".".$body, getenv("LOCAL_JWT_SECRET"), true));
  ' "$USER_ID" "$EMAIL" "$SESSION_ID")"
fi
export E2E_ACCESS_TOKEN
unset PASSWORD signin created JWT_SECRET
echo "test user signed in"

# --- 5. Upload as Coilbox ---

mkdir -p "$TMP/client"
upload_out="$(php "$REPO/scripts/mirrors-e2e-upload.php" "$HUB_URL" "$TMP/client")" || die "the upload client failed"
unset E2E_ACCESS_TOKEN
upload_json="$(tail -n 1 <<<"$upload_out")"
FILENAME="$(json_get filename <<<"$upload_json")"
SHA256="$(json_get sha256 <<<"$upload_json")"
ARCHIVE_ID="$(json_get id <<<"$upload_json")"
INTERNAL_NAME="$(json_get internal_name <<<"$upload_json")"
echo "hub lists archive $ARCHIVE_ID ($FILENAME)"

# --- 6. Sync both mirrors, then check the files and the catalogue ---

# Mirror A already holds the file from the upload. Mirror B has to fetch it from A.
for id in a b
do
  echo "sync mirror $id"
  (cd "$TMP/mirror-$id" && php84 sync.php --verbose) || die "sync.php failed on mirror $id"
done

for id in a b
do
  file="$TMP/mirror-$id/maps/$FILENAME"
  [[ -f "$file" ]] || die "mirror $id has no maps/$FILENAME"
  actual="$(shasum -a 256 "$file" | cut -d' ' -f1)"
  [[ "$actual" == "$SHA256" ]] || die "mirror $id holds the file with sha256 $actual, expected $SHA256"
  echo "mirror $id holds the file with the right sha256"
done

for name in catalogue catalogue.sig
do
  code="$(curl -sS --max-time 30 -o "$TMP/$name" -w '%{http_code}' "$HUB_URL/api/v1/$name")"
  [[ "$code" == "200" ]] || die "GET /api/v1/$name answered $code"
done

# Verify the signature over the exact catalogue bytes, then look for the archive and
# both mirrors in it.
php -- "$TMP/catalogue" "$TMP/catalogue.sig" "$HUB_PUBLIC" "$SHA256" "$FILENAME" "$INTERNAL_NAME" <<'PHP' || die "catalogue check failed"
<?php
[, $bodyFile, $sigFile, $publicKey, $sha256, $filename, $internalName] = $argv;
$v = SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING;
$body = file_get_contents($bodyFile);
$sig = sodium_base642bin(file_get_contents($sigFile), $v);
$public = sodium_base642bin($publicKey, $v);
if (!sodium_crypto_sign_verify_detached($sig, "coilbox-mirror/catalogue/v1\n".$body, $public)) {
    fwrite(STDERR, "catalogue.sig does not verify against the hub public key\n");
    exit(1);
}
$catalogue = json_decode($body, true);
$found = null;
foreach ($catalogue['archives'] ?? [] as $archive) {
    if (($archive['sha256'] ?? null) === $sha256) {
        $found = $archive;
    }
}
if ($found === null) {
    fwrite(STDERR, "the catalogue does not list the test archive\n");
    exit(1);
}
if ($found['filename'] !== $filename || $found['internal_name'] !== $internalName || $found['size'] !== 2048) {
    fwrite(STDERR, "the catalogue entry differs from what was uploaded: ".json_encode($found, JSON_UNESCAPED_UNICODE)."\n");
    exit(1);
}
$ids = array_column($catalogue['mirrors'] ?? [], 'id');
foreach (['e2e-a', 'e2e-b'] as $id) {
    if (!in_array($id, $ids, true)) {
        fwrite(STDERR, "the catalogue does not list mirror $id\n");
        exit(1);
    }
}
echo "catalogue seq {$catalogue['seq']} verifies and lists the archive\n";
PHP

# The hub should also know that both mirrors hold the file, from their reports.
holders="$(echo "select count(*) from public.archive_mirror where archive_id = '$ARCHIVE_ID' and mirror_id in ('e2e-a', 'e2e-b');" | sql)"
[[ "$holders" == "2" ]] || die "the hub records $holders mirrors holding the archive, expected 2"
echo "hub records both mirrors as holding the archive"

echo "e2e ok"
