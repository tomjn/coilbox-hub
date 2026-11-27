# Running the hub on the droplet

The hub lives at `/srv/www/hub.coilbox.org`. GitHub Actions builds each release, copies it to `releases/<commit>`, then `deploy/release.sh` switches `current` to it. The droplet needs PHP but not Node or bun.

```
/srv/www/hub.coilbox.org/
  current -> releases/<commit>
  releases/
  shared/.env
  shared/storage/
```

## One time setup

1. Point `hub.coilbox.org` at the droplet with an A record, and an AAAA record if it has IPv6.
2. Install PHP, the extensions the hub uses, and the memcached server that holds its cache. Sessions are kept in MariaDB, so a full cache never signs anyone out:

   ```
   sudo apt install php8.4-fpm php8.4-cli php8.4-curl php8.4-mbstring php8.4-xml php8.4-intl php8.4-zip php8.4-gd php8.4-mysql php8.4-memcached memcached
   sudo systemctl enable --now memcached
   ```

3. Create the folders. They belong to the deploy user and the `www-data` group PHP-FPM runs as, and the deploy user must be in that group. The setgid bit keeps new files in `www-data`, and the release script makes them group writable:

   ```
   sudo usermod -aG www-data "$USER"
   sudo mkdir -p /srv/www/hub.coilbox.org/releases /srv/www/hub.coilbox.org/shared/storage
   sudo chown -R "$USER":www-data /srv/www/hub.coilbox.org
   sudo chmod 2775 /srv/www/hub.coilbox.org/shared /srv/www/hub.coilbox.org/shared/storage
   ```

4. Write `shared/.env`. Start from `deploy/env.example` and set:

   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://hub.coilbox.org
   APP_KEY=            # php artisan key:generate --show, on any machine
   CACHE_STORE=memcached
   SESSION_DRIVER=database
   SESSION_LIFETIME=43200
   SESSION_SECURE_COOKIE=true
   SUPABASE_URL=https://twptlkjgdvjhpywdoajf.supabase.co
   SUPABASE_PUBLISHABLE_KEY=
   SUPABASE_SERVICE_ROLE_KEY=
   ```

   The two Supabase keys are in the Supabase dashboard under Project Settings, API keys. Keep the file readable only by the deploy user and PHP.

5. Create the hub's MariaDB database and user. MariaDB 11.8 already runs on the droplet, listening on `127.0.0.1` only. This makes a random password and writes it straight into `shared/.env`, so it never appears on screen:

   ```
   ENV=/srv/www/hub.coilbox.org/shared/.env
   PASS=$(openssl rand -hex 32)
   sudo mariadb <<SQL
   CREATE DATABASE hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'hub'@'localhost' IDENTIFIED BY '$PASS';
   GRANT ALL PRIVILEGES ON hub.* TO 'hub'@'localhost';
   SQL
   printf 'DB_CONNECTION=mariadb\nDB_SOCKET=/run/mysqld/mysqld.sock\nDB_DATABASE=hub\nDB_USERNAME=hub\nDB_PASSWORD=%s\n' "$PASS" >> "$ENV"
   unset PASS
   ```

   The droplet's MariaDB has `skip_name_resolve` on, so `'hub'@'localhost'` only matches connections over the socket. A connection to `127.0.0.1` is refused, which is why `shared/.env` sets `DB_SOCKET` and no `DB_HOST`. Each release runs `php artisan migrate --force` before it goes live.

6. Let the deploy user reload PHP-FPM and nothing else, so a release can clear OPcache. Run `sudo visudo -f /etc/sudoers.d/hub-deploy` and add, with your deploy user's name:

   ```
   deployuser ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
   ```

7. Get a certificate, then install the Nginx site. On this droplet one Nginx site, `/etc/nginx/sites-available/http`, answers port 80 for every host. It sends each request to the same address on HTTPS, except certbot's checks, which it answers from `/var/www/html`. So certbot proves the domain through that folder, and the hub needs no port 80 setup of its own:

   ```
   sudo certbot certonly --webroot -w /var/www/html -d hub.coilbox.org
   sudo cp deploy/nginx.conf /etc/nginx/sites-available/hub.coilbox.org
   sudo ln -s /etc/nginx/sites-available/hub.coilbox.org /etc/nginx/sites-enabled/hub.coilbox.org
   sudo nginx -t && sudo systemctl reload nginx
   ```

   Nginx keeps serving the certificate it loaded until it reloads, so have certbot reload it after each renewal:

   ```
   printf '#!/bin/sh\nsystemctl reload nginx\n' | sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
   sudo chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
   ```

8. Make an SSH key for GitHub Actions to deploy with, used for nothing else. Put the public half in the deploy user's `~/.ssh/authorized_keys`. Then add these secrets to the hub repository's `production` environment:

   - `DEPLOY_HOST`: the droplet's address.
   - `DEPLOY_USER`: the deploy user.
   - `DEPLOY_SSH_KEY`: the private key.
   - `DEPLOY_KNOWN_HOSTS`: the output of `ssh-keyscan <droplet address>`.

9. In the Supabase dashboard, under Authentication, URL Configuration, add `https://hub.coilbox.org/auth/callback` to the redirect URLs, and set the Site URL to `https://hub.coilbox.org`. Without the redirect URL, signing in on the website sends people back to the old address.

10. After the first deploy, install the queue worker. See [Queue worker](#queue-worker).

## Deploying

A push to `main` that changes `hub-php/` deploys. The Actions tab can also run the "Deploy the PHP hub" workflow by hand.

To roll back, point `current` at an older release, reload PHP-FPM and restart the queue worker:

```
ln -sfn /srv/www/hub.coilbox.org/releases/<older commit> /srv/www/hub.coilbox.org/current
sudo systemctl reload php8.4-fpm
php /srv/www/hub.coilbox.org/current/artisan queue:restart
```

Rolling back past the release that moved the game catalogue (#597) makes the hub read games from Supabase again. Run `php artisan games:store supabase` first, so the newer releases agree.

## Queue worker

Work that should not keep a request waiting is queued in the `jobs` table. `deploy/hub-queue.service` runs `php artisan queue:work` under systemd as `www-data` to do it. systemd starts it at boot and starts it again whenever it stops. Each deploy runs `php artisan queue:restart`, which makes the worker finish its current job and exit, so the new one runs the new release.

The worker reads `.env` through the cached config when it starts. After running `php artisan config:cache` by hand, also run `php artisan queue:restart`.

### Install the worker

Do this once, after the release that contains `deploy/hub-queue.service` has deployed.

1. Check `grep QUEUE /srv/www/hub.coilbox.org/shared/.env` prints nothing, or only `QUEUE_CONNECTION=database`.
2. Copy the unit: `sudo cp /srv/www/hub.coilbox.org/current/deploy/hub-queue.service /etc/systemd/system/`
3. Load it: `sudo systemctl daemon-reload`
4. Start it now and at every boot: `sudo systemctl enable --now hub-queue`
5. Check `systemctl status hub-queue` says `active (running)`.

A deploy does not copy the unit. After a change to `deploy/hub-queue.service`, repeat steps 2 and 3, then run `sudo systemctl restart hub-queue`.

### Check it runs jobs

In `/srv/www/hub.coilbox.org/current`:

1. `php artisan jobs:test` queues a test job.
2. Within a minute, `grep "Queue test job ran" storage/logs/queue-test.log` shows a new line. It gives the seconds the job waited, the release the worker runs and the memory it used.

If no line appears, `sudo journalctl -u hub-queue -n 50` shows what the worker did.

To check it comes back after a restart, run `sudo reboot`, then repeat both steps. To check it picks up a deploy, run the "Deploy the PHP hub" workflow, then repeat both steps. The `release` in the new line is the new release folder.

### Failed jobs

A job that throws, or takes longer than 60 seconds, is not tried again. It is kept in the `failed_jobs` table. A job that ends the worker by running out of memory ends up there too, once the worker picks it up again 90 seconds or more after it started. In `/srv/www/hub.coilbox.org/current`:

1. `php artisan queue:failed` lists them with their ids.
2. `sudo journalctl -u hub-queue` and `storage/logs/laravel-<date>.log` say why. So does the `exception` column of `failed_jobs`.
3. `php artisan queue:retry <id>` queues one again. `php artisan queue:retry all` queues all of them.
4. `php artisan queue:forget <id>` removes one. `php artisan queue:flush` removes all of them.

### Memory limits

Measured on 26 November 2026 with `queue:work` running `jobs:test --replay` three times on a 4.8 MB replay of a 52 minute, 16 player Beyond All Reason match, on macOS with PHP 8.3 and MariaDB 11.8:

| Measure | Result | Source |
| --- | --- | --- |
| Most PHP held during a job | 63.2 MB | `peak_memory_mb` in the log line |
| PHP held between jobs | 32 MB | `memory_before_mb` in the log line |
| Most the whole process held | 83 MB (87,212,032 bytes) | `maximum resident set size` from `/usr/bin/time -l` |

The limits in the unit leave room above those figures. The margins are a choice, not a measurement.

| Limit | Value | What happens past it |
| --- | --- | --- |
| `--memory` | 64 MB, twice what PHP held between jobs | The worker exits after the job and systemd starts a new one |
| `memory_limit` | 256 MB, about four times the most PHP held | PHP ends the job and the worker, and systemd starts a new one |
| `MemoryMax` | 384 MB, room for `memory_limit` and the rest of the process | Linux ends the worker, and systemd starts a new one |

The droplet has 2 GB of RAM and had 43% in use on 26 November 2026, so a worker at `MemoryMax` still fits.

To measure on the droplet, in `/srv/www/hub.coilbox.org/current`:

1. Copy a long match's replay to `/tmp` on the droplet, and check `www-data` can read it with `chmod 644`.
2. `sudo systemctl restart hub-queue`, so earlier jobs are not counted.
3. Run `php artisan jobs:test --replay=/tmp/<name>.sdfz` twice. The second line's `memory_before_mb` is what a job leaves behind.
4. `grep "Queue test job ran" storage/logs/queue-test.log` gives the PHP figures.
5. `systemctl status hub-queue` and `cat /sys/fs/cgroup/system.slice/hub-queue.service/memory.peak` give the memory the whole process used, the second as the peak in bytes.

## Scheduled commands

Laravel's scheduler, in `routes/console.php`, runs commands at set times. `deploy/hub-schedule.cron` runs `php artisan schedule:run` once a minute as `www-data`, and the scheduler starts whatever is due. Today that is `replays:data` every Monday at 04:00 UTC, which writes the files `/replays/data` links.

### Install the cron entry

Do this once, after the release that contains `deploy/hub-schedule.cron` has deployed.

1. Copy it: `sudo cp /srv/www/hub.coilbox.org/current/deploy/hub-schedule.cron /etc/cron.d/hub-schedule`
2. Make it owned by root and not group writable, or cron ignores it: `sudo chown root:root /etc/cron.d/hub-schedule && sudo chmod 644 /etc/cron.d/hub-schedule`
3. In `/srv/www/hub.coilbox.org/current`, `php artisan schedule:list` shows `replays:data` and when it next runs.

A deploy does not copy the file. After a change to `deploy/hub-schedule.cron`, repeat steps 1 and 2. A change to the schedule in `routes/console.php` needs nothing, because each run reads `current`.

### Replay data files

`replays:data` writes three CSV files of the listed replays into `shared/storage/app/replay-data/<date>/`, and keeps the newest four sets. To write a set now, without waiting for Monday, run it as `www-data` so the hub can replace the files later:

1. `sudo -u www-data php artisan replays:data --dry-run` says how many replays it would write.
2. `sudo -u www-data php artisan replays:data` writes them, and prints each file's rows.
3. `/replays/data` links the new set.

If nothing appears on a Monday, `journalctl -u cron --since today | grep schedule:run` shows whether cron ran the scheduler, and `storage/logs/laravel-<date>.log` shows any error.

## Game catalogue in MariaDB

Games, releases, sides and units live in MariaDB once `php artisan games:copy` has run. Until then the hub reads them from Supabase. Run these in `/srv/www/hub.coilbox.org/current`:

1. `php artisan games:copy --dry-run` prints the rows in each table in Supabase and in MariaDB. It writes nothing.
2. `php artisan games:copy` refuses game writes, waits out the Supabase timeout, copies every row, checks each table's count against Supabase and then switches the hub to MariaDB. If any count differs it switches back to Supabase and exits with an error.
3. `php artisan games:store` prints which database the hub reads games from.

To go back, run `php artisan games:store supabase`. Supabase's `game` rows stay current, because the hub writes each change to a game there too. Units, releases, sides and offers written in MariaDB since the switch are not in Supabase.

No config cache or PHP-FPM reload is needed. The switch is a row in the `data_move` table.

## Staged pictures return 404

Staged pictures live in `shared/storage/app/staged-pictures`. PHP-FPM runs as `www-data`, so that folder must be group `www-data` and mode `2775`. Check it with `ls -ld /srv/www/hub.coilbox.org/shared/storage/app/staged-pictures`.

A folder showing `drwx--S---` is closed to `www-data`. It happens when the deploy user's artisan command creates the folder first, for example a copy run. Fix it with `sudo chgrp www-data` and `sudo chmod 2775` on the folder. New installs create it with mode `0775` already.

## Mirror signing key

The hub signs upload tokens and the mirror catalogue with one Ed25519 key. The secret half is `HUB_MIRROR_SECRET_KEY` in `shared/.env`. It never goes in the repository, a log or a chat.

### Make the key

1. Check out `github.com/tomjn/coilbox-mirror` on your own machine.
2. Run `php bin/keygen.php`. It prints a `secret_key` line and a public key. It writes no files.
3. Add the secret to `/srv/www/hub.coilbox.org/shared/.env` as `HUB_MIRROR_SECRET_KEY=<secret_key value>`.
4. Load it. `release.sh` caches config, so editing `.env` alone changes nothing. In `/srv/www/hub.coilbox.org/current` run `php artisan config:cache`, then `sudo systemctl reload php8.4-fpm`.
5. Run `php artisan mirrors:catalogue`. It prints `catalogue seq N` when the key works.
6. The hub publishes the public key at `/api/v1/mirrors/hub-key`. A new mirror's `setup.php` writes the production key in for the operator, so a new key also needs a mirror release with it in `src/HubDefaults.php`.

### Add or remove a mirror

An operator sets up their mirror with its `setup.php` page, then follows the link it gives to `/mirrors/new` while signed in to the hub. The hub asks the mirror to sign a challenge, and stores the request only when it proves its key. A moderator approves or rejects the request at `/moderation/mirrors`. Approval checks the key again.

The same page removes or switches on a mirror, checks any mirror's key on demand, and shows each mirror's health. Its add form is for mirrors set up by hand.

### Keep a sealed copy

Give the secret key, sealed, to a second trusted person. If the key is lost, nothing can sign for the hub and every mirror operator must add a new public key by hand. Record who holds the copy and the date in your own notes, not in a repository.

### Rotate the key

A mirror trusts every key it has ever seen the hub endorse, and it never forgets one. Treat an old key as live. Keep it secret even after you stop using it.

1. Make a new key with `php bin/keygen.php`.
2. Add the secret to `shared/.env` as `HUB_MIRROR_NEXT_SECRET_KEY=<secret_key value>`.
3. Run `php artisan config:cache` in `current`, then `sudo systemctl reload php8.4-fpm`.
4. From now on each catalogue carries the new public key, endorsed by the current key. Mirrors learn it when they sync.
5. Run `php artisan mirrors:status`. If it says `waiting for`, the listed mirrors have not yet synced a catalogue that carries the new key. Run it again later. A mirror that stays listed for good may be switched off in the database, because only enabled mirrors count.
6. When it says `safe to switch keys`, edit `shared/.env`. Put the new secret in `HUB_MIRROR_SECRET_KEY` and delete the `HUB_MIRROR_NEXT_SECRET_KEY` line.
7. Run `php artisan config:cache` in `current`, then `sudo systemctl reload php8.4-fpm`, then `php artisan mirrors:catalogue`.
8. Put the new public key in `src/HubDefaults.php` in the mirror repository and release it, so new mirrors trust it.
8. Give the new secret to the second trusted person in place of the old copy.
