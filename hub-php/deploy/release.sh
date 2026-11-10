#!/usr/bin/env bash
# Runs on the droplet after CI has copied a build to releases/<sha>. Links the
# shared settings and storage into it, warms Laravel's caches, then points
# `current` at it in one step, so a request never sees half a release.
#
#   deploy/release.sh /srv/www/hub.coilbox.org <sha>
set -euo pipefail
# PHP-FPM runs as www-data and shares the www-data group with the deploy user.
# Files made here, such as compiled views and the log, must stay group
# writable or PHP cannot rewrite them.
umask 002
root=$1
sha=$2
release="$root/releases/$sha"

test -d "$release"
test -f "$root/shared/.env"

mkdir -p "$root/shared/storage/app/public" "$root/shared/storage/framework/cache" \
  "$root/shared/storage/framework/sessions" "$root/shared/storage/framework/views" "$root/shared/storage/logs"

rm -rf "$release/storage"
ln -sfn "$root/shared/storage" "$release/storage"
ln -sfn "$root/shared/.env" "$release/.env"

cd "$release"
php artisan config:cache
php artisan route:cache
php artisan view:cache

ln -sfn "$release" "$root/current.new"
mv -Tf "$root/current.new" "$root/current"
# The web root, named like the droplet's other sites. Points through
# `current`, so it is made once and never touched again.
test -L "$root/htdocs" || ln -s current/public "$root/htdocs"

# OPcache holds the old release's compiled files by path. Reloading PHP-FPM
# clears it. The deploy user may run only this one command through sudo.
sudo -n systemctl reload php8.4-fpm

# Keep the five newest releases.
ls -1dt "$root"/releases/* | tail -n +6 | xargs -r rm -rf
