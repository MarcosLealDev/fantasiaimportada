# Deploying fantasiaimportada.com

This site runs as a **tenant on the bellacollezione droplet** (`167.172.242.133`). It brings one
container and shares that host's MariaDB and Caddy.

```
Internet
   │
   ▼  (Cloudflare, orange-cloud; origin firewalled to Cloudflare ranges)
 Caddy ──────────────── owned by /opt/bellacollezione, terminates TLS for ALL sites
   ├── apache ───────── bellacollezione.com  → php        → ┐
   ├── frizza-apache ── frizzadesigns.com    → frizza-php → ├── mysql  bellacollezione
   └── fi-wordpress ─── fantasiaimportada.com (mod_php)     │          frizza_db
                                                            │          fantasia_wpdb
                                                            └── redis  (this site does not use it)
```

| Concern | Owner |
|---|---|
| Application code, its container | **this repo** → `/opt/fantasiaimportada` |
| TLS, apex→www redirect, bot/WAF rules, ports 80/443 | **bellacollezione2 repo** → `docker/caddy/Caddyfile` |
| MariaDB, the `opencart-network`, host firewall, systemd units, certbot | **bellacollezione2 repo** → `/opt/bellacollezione` |

Consequences worth internalising:

- **Edge changes for this domain are PRs against the bellacollezione2 repo.** Nothing here can alter
  how requests reach the origin. In particular this site depends on that repo's
  `(edge_hardening_wp)` snippet: the OpenCart variant the other two stores use answers **403 to
  every `/wp-*` path**, which is the admin, the login, all assets and the REST API.
- **If the bella stack is down, this site cannot start** — it joins `opencart-network` as an external
  network. The deploy checks for it explicitly so this fails loudly rather than silently creating an
  empty network that resolves nothing.
- **Isolation from the neighbouring stores is by database and by service name**, nothing more.

## § 1 Ongoing deploys

Merges to `main` trigger `.github/workflows/deploy.yml`: rsync → remove git-deleted files → archive
the container log → `up -d --build` → assert service-name and read-only-mount isolation → verify.

Required repo secrets: `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_HOST`.

**MERGING IS DEPLOYING.** A change that depends on droplet state which does not exist yet does not
belong on `main` yet — the sibling repo took two unrelated live stores down learning this. Rollback
is fix-forward: revert on `main` and let it redeploy.

The verify step has **two phases** and picks between them by reading the live Caddyfile, so nobody
has to remember to flip anything. Before the edge PR merges it checks the container directly by name
on the docker network; afterwards it additionally checks through Caddy, including `/wp-login.php`
and `/wp-cron.php`, which are the two paths that would prove the site block imported the wrong
hardening snippet.

## § 2 What the deploy actually ships

**Not WordPress.** Exactly one file under `wordpress/` is tracked in git (`.htaccess`). Core,
plugins, themes, languages, uploads and `wp-config.php` are all droplet-owned state, seeded once
from the old host and thereafter managed through the WordPress admin like any other site.

So the rsync ships infrastructure — the compose file, the Docker build, the importer under `tools/`
— and the exclude list is mostly a statement of ownership rather than a filter that fires.

| Path | What it is | How it gets there |
|---|---|---|
| `.env.production` | DB credentials | hand-written from `.env.production.example`, `chmod 600` |
| `wordpress/wp-config.php` | DB constants, salts, the Really Simple SSL fix | copied from the old host |
| `wordpress/wp-admin`, `wp-includes`, `wp-*.php` | WordPress core | seeded once, then admin updates |
| `wordpress/wp-content/{plugins,themes,languages}` | 261 MB + 41 MB + 18 MB | seeded once, then admin updates |
| `wordpress/wp-content/uploads/` | media, incl. the 821 MB `oc-thumbs` cache | seeded once |
| `logs/access-archive/` | gzipped container logs kept across deploys | written by the deploy, 35-day retention |

## § 3 Images are linked, never copied

`uploads/oc-catalog` and `uploads/oc-cache` are **read-only bind mounts** onto the neighbouring
store's image tree at `/opt/bellacollezione/upload/image/` — 2.1 GB and 19,230 files that this site
must never write to. The importer wrote `_wp_attached_file` values of the form `oc-catalog/...` that
resolve straight through them.

On the old host this was a symlink, which needed `Options FollowSymLinks`; a bind mount needs no
Apache cooperation and cannot be followed out of the container, so it is strictly better here.

`wp-content/mu-plugins/01-opencart-images.php` is what keeps the store read-only in practice:
WordPress normally writes resized copies next to the original, so the mu-plugin intercepts
`image_downsize` and redirects every size request to OpenCart's own cache first, then to the
writable `oc-thumbs/` in this site's own uploads. It is mounted from `tools/import-opencart/` so the
logic is tracked and deployed rather than existing only on one server.

**The deploy asserts both mounts are read-only** and fails if either is not — a dropped `:ro` in a
future edit would otherwise be silent until something had already written into the other site's
images.

## § 4 First-time setup (one-off, in this order)

Ordering matters, and step 5 is the one that can take down the **other two sites** if done early.

1. **Directory.** `sudo mkdir -p /opt/fantasiaimportada && sudo chown deploy:deploy /opt/fantasiaimportada`

2. **Database and user**, in the shared MariaDB. Grant on this database only — never on
   `bellacollezione` or `frizza_db`:
   ```sql
   CREATE DATABASE fantasia_wpdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
   CREATE USER 'fantasia'@'%' IDENTIFIED BY '<password>';
   GRANT ALL PRIVILEGES ON fantasia_wpdb.* TO 'fantasia'@'%';
   FLUSH PRIVILEGES;
   ```

   A **separate, SELECT-only** user for the importer's read access to the OpenCart catalog. The
   application user must never be able to read the neighbouring store:
   ```sql
   CREATE USER 'fantasia_ocread'@'%' IDENTIFIED BY '<other password>';
   GRANT SELECT ON bellacollezione.* TO 'fantasia_ocread'@'%';
   ```

3. **Import the database.** The old host runs **MySQL 8.0.42**; the droplet runs **MariaDB 12.3.3**.
   That combination usually breaks on `utf8mb4_0900_*` collations, which MariaDB does not have — but
   this dump carries only `utf8mb3_general_ci` and `utf8mb4_unicode_520_ci`, both of which MariaDB
   supports. **Dry-run it into a scratch database first** rather than discovering otherwise during a
   cutover:
   ```sh
   # on the old host (SSH is on port 25123)
   mysqldump --single-transaction --routines --triggers fantasia_wpdb | gzip > fantasia.sql.gz
   ```

4. **Seed the webroot and bring the stack up.** Copy the WordPress tree from the old host into
   `/opt/fantasiaimportada/wordpress/` **before the first `up`** — the official image's entrypoint
   installs a fresh WordPress into an empty `/var/www/html`, which would leave a core version that
   does not match the database.

   **Then make the copied `wp-config.php` environment-aware, or the site 500s.** The old host's file
   is the pristine production one: `DB_HOST` is hardcoded to `localhost`, which inside a container is
   the container itself, and the password is the old server's. It needs an `fi_env()` helper and the
   four DB constants wrapped as `define( 'DB_HOST', fi_env( 'WORDPRESS_DB_HOST', 'localhost' ) )` and
   so on, keeping the literals as fallbacks. **Do not solve this by copying the repo's
   `wordpress/wp-config.php` over it** — that file carries *different* salts, and overwriting the
   live ones invalidates every session and cookie the database was migrated with.

   Two things worth knowing while you are in that file: the production branch already forces
   `$_SERVER['HTTPS'] = 'on'` for Really Simple Security, so no `X-Forwarded-Proto` handling is
   needed; and `WORDPRESS_LOCAL_DEV` must stay unset.

   **Also sweep the copied webroot for files that must not be web-servable.** The old host's
   `/var/www/fantasia` carried `import-opencart/import.env` (containing `OC_DB_PASSWORD`),
   `import-opencart/bella-catalog.sql` (18 MB) and `tools.tgz`, all of which it served at HTTP 200.
   They are quarantined outside the webroot here, at
   `/opt/fantasiaimportada/import-opencart-from-old-host/`:
   ```sh
   find wordpress -name '*.sql' -o -name '*.env' -o -name '*.tgz' -o -name '*.bak'
   ```

   Then write `.env.production`, `sudo chown -R www-data:www-data wordpress`, and:
   ```sh
   docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
   ```
   Verify by name on the docker network, not over the internet — the domain still points elsewhere:
   ```sh
   docker run --rm --network opencart-network curlimages/curl:latest -sI http://fi-wordpress/
   ```

5. **Certificate, THEN the edge PR, THEN DNS.** Caddy resolves `tls <file>` at provision time, so
   merging the site blocks before the lineage exists makes Caddy fail to start — taking
   bellacollezione and frizzadesigns down with it. This has happened once already; see PR #85 in the
   bellacollezione2 repo.

   The circle to break: a webroot challenge cannot succeed until the domain resolves to this
   droplet, and the domain cannot be pointed here until Caddy serves it. So seed the lineage from
   the certificate the old host is already serving (it covers both names), then merge, then flip
   DNS, then re-issue properly:
   ```sh
   certbot certonly --webroot -w /opt/fantasiaimportada/wordpress \
     --cert-name fantasiaimportada \
     -d fantasiaimportada.com -d www.fantasiaimportada.com --force-renewal
   ```
   Confirm `/etc/letsencrypt/renewal/fantasiaimportada.conf` then reads `authenticator = webroot`.
   The global deploy hook already reloads Caddy for any lineage.

6. **Orange-cloud the DNS records** and point them at `167.172.242.133`. This is **mandatory, not a
   preference**: `tools/docker-firewall.sh` locks the origin to Cloudflare ranges, so a grey-clouded
   record reaches a dropped packet. Unlike bellacollezione, grey-cloud is not a rollback for this
   site — the rollback is pointing DNS back at the old host, which stays up for exactly that reason.

7. **Verify the image paths resolve** — on the filesystem, never over HTTP. ~13,000 `test -f` calls
   take seconds; 13,000 HTTPS requests look like an attack and have previously got a workstation
   blackholed by the provider's DDoS mitigation.

## § 5 Things that are not automated

- **Cron now comes from the system scheduler**, not from page views. `tools/wp-cron.sh` runs every
  5 minutes from root's crontab and `WORDPRESS_DISABLE_WP_CRON=1` is set in `.env.production`.
  **They are a pair**: unset the variable and both mechanisms run; remove the crontab entry and
  scheduled work stops silently -- Action Scheduler stalls and the store sends no email, with nothing
  logged anywhere.

  This replaced traffic-driven spawning, which cost 70 self-requests per hour (7.2% of all traffic)
  on 2026-08-29, almost entirely from crawlers rather than from real work being due.

  `/wp-cron.php` is still allowed at the edge and pinned by the bellacollezione2 repo's
  `tools/caddy-edge-check.sh`. That is deliberate: blocking it now would be safe, but the allowance
  costs nothing and keeps a manual trigger available.
- **Plugin and core updates** happen through the WordPress admin and are not captured in git.
- **Backups** need no action here: `tools/backup-databases.sh` on the droplet discovers every
  non-system database rather than listing them, so `fantasia_wpdb` is picked up automatically.
