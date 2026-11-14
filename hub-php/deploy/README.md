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
2. Install PHP, the extensions the hub uses, and the memcached server that holds its cache and sessions:

   ```
   sudo apt install php8.4-fpm php8.4-cli php8.4-curl php8.4-mbstring php8.4-xml php8.4-intl php8.4-zip php8.4-gd php8.4-memcached memcached
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
   SESSION_DRIVER=memcached
   SESSION_SECURE_COOKIE=true
   SUPABASE_URL=https://twptlkjgdvjhpywdoajf.supabase.co
   SUPABASE_PUBLISHABLE_KEY=
   SUPABASE_SERVICE_ROLE_KEY=
   ```

   The two Supabase keys are in the Supabase dashboard under Project Settings, API keys. Keep the file readable only by the deploy user and PHP.

5. Let the deploy user reload PHP-FPM and nothing else, so a release can clear OPcache. Run `sudo visudo -f /etc/sudoers.d/hub-deploy` and add, with your deploy user's name:

   ```
   deployuser ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
   ```

6. Get a certificate, then install the Nginx site. On this droplet one Nginx site, `/etc/nginx/sites-available/http`, answers port 80 for every host. It sends each request to the same address on HTTPS, except certbot's checks, which it answers from `/var/www/html`. So certbot proves the domain through that folder, and the hub needs no port 80 setup of its own:

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

7. Make an SSH key for GitHub Actions to deploy with, used for nothing else. Put the public half in the deploy user's `~/.ssh/authorized_keys`. Then add these secrets to the hub repository's `production` environment:

   - `DEPLOY_HOST`: the droplet's address.
   - `DEPLOY_USER`: the deploy user.
   - `DEPLOY_SSH_KEY`: the private key.
   - `DEPLOY_KNOWN_HOSTS`: the output of `ssh-keyscan <droplet address>`.

8. In the Supabase dashboard, under Authentication, URL Configuration, add `https://hub.coilbox.org/auth/callback` to the redirect URLs, and set the Site URL to `https://hub.coilbox.org`. Without the redirect URL, signing in on the website sends people back to the old address.

## Deploying

A push to `main` that changes `hub-php/` deploys. The Actions tab can also run the "Deploy the PHP hub" workflow by hand.

To roll back, point `current` at an older release and reload PHP-FPM:

```
ln -sfn /srv/www/hub.coilbox.org/releases/<older commit> /srv/www/hub.coilbox.org/current
sudo systemctl reload php8.4-fpm
```
