# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A **local Docker development copy** of the live WordPress 7.1 + WooCommerce 11
store at `https://www.fantasiaimportada.com` (Blocksy theme, Portuguese/BRL,
Stripe + PagSeguro gateways). It also carries tooling that imports a second
store's catalog — Bella Collezione, an OpenCart install — into this one.

Nothing here deploys. The live site is untouched by anything in this repo unless
you explicitly run the import tooling against it.

## Layout

Only infrastructure lives at the root; `wordpress/` is the webroot mounted at
`/var/www/html`. Keeping the tarballs, dumps and configs outside it is
deliberate — they must not be web-servable.

```
docker-compose.yml
docker/                  php-local.ini, mu-plugins/00-local-dev.php
db-backup/               fantasia_wpdb.sql -> MariaDB initdb (see warning below)
tools/import-opencart/   the OpenCart -> WooCommerce importer
wordpress/               webroot (WordPress core, wp-content, wp-config.php)
wp-config.php.prod.bak   pristine production config, kept out of the webroot
```

## Commands

```sh
docker compose up -d                     # site at http://localhost:8000
docker compose logs -f wordpress
docker compose down -v && docker compose up -d   # wipe DB and re-import the dump

# WP-CLI runs as a profiled one-shot service
docker compose --profile tools run --rm wpcli plugin list
docker compose --profile tools run --rm wpcli eval-file /import/import.php products 100

docker compose exec -T db mariadb -ufantasia -pfantasia fantasia_wpdb -e "SELECT ..."
docker compose exec -T db mariadb -ufantasia -pfantasia bella_oc -e "SELECT ..."
```

Local credentials are `fantasia` / `fantasia` (root `root`), WordPress DB
`fantasia_wpdb`, OpenCart source DB `bella_oc`. There is no build, lint or test
suite — this is a WordPress site, not an application codebase. Verification is
done by hitting URLs with `curl` and querying the database directly.

## How the environment fits together

**`wordpress/wp-config.php` is the production file, made environment-aware.**
Every DB constant reads a `WORDPRESS_*` env var through the `fi_env()` helper and
**falls back to the live values**, so the file still behaves correctly if copied
back to the server. The `WORDPRESS_LOCAL_DEV=1` env var (set only in
`docker-compose.yml`) switches on `WP_HOME`/`WP_SITEURL` = `localhost:8000` and
switches off the Really Simple SSL block that forces HTTPS and the production
host. Preserve that structure when editing.

**MariaDB, not MySQL.** Both dumps come from MariaDB and use collations
(`utf8mb4_unicode_520_ci`, `utf8mb3_uca1400_ai_ci`) that MySQL 8 rejects.

**`docker/mu-plugins/00-local-dev.php`** is mounted into the container only, so
it never exists in the webroot on disk. It deactivates Really Simple Security
(would force HTTPS and redirect to the production host) and Jetpack (calls
WordPress.com), and blocks all outgoing mail — the database contains real
customer and order data.

**Production URLs are still in the database.** `wp_options.siteurl`/`home` point
at the live domain; the wp-config constants override them at runtime. No
search-replace was run, so images embedded in post content still load from the
live site. Don't "fix" this without asking.

### Watch out

- **`db-backup/` is the MariaDB init directory.** Any `.sql` file placed there is
  executed against `fantasia_wpdb` on a fresh volume. Put working dumps
  elsewhere (`tools/import-opencart/` holds the OpenCart one).
- `docker compose down -v` drops the database volume and replays the initdb
  import — that discards the imported catalog too.

## The OpenCart import (`tools/import-opencart/`)

Imports ~7,700 products, 393 categories, 62 brands and ~15,000 size variations
from the Bella Collezione OpenCart database into WooCommerce. See that
directory's `README.md` for the full procedure; the architecture worth knowing
up front:

**Images are linked, never copied.** `/Users/marcosleal/devops/bella-image/catalog`
is bind-mounted **read-only** at `wp-content/uploads/oc-catalog`, so attachments
resolve natively (`_wp_attached_file = oc-catalog/...`) with no URL rewriting.
On a real server the same effect comes from a symlink. WordPress normally writes
resized copies next to the original, which would mean writing into that store —
so `01-opencart-images.php` hooks `image_downsize` and redirects every size
request: OpenCart's own cache first (`oc-cache`, covers <5%), then a previously
rendered file, then renders it once into the writable `oc-thumbs/`. That folder
is disposable and regenerates on demand. **The image store must stay read-only.**

**Phases are idempotent and resumable.** Records match on OpenCart ids kept in
meta (`_oc_product_id`, `_oc_category_id`, `_oc_manufacturer_id`,
`_oc_rel_path`), so re-running updates instead of duplicating. Product progress
is stored in the `oc_import_last_product_id` option; a bare `products` call
resumes from there, so pass an explicit `0` to start over.

**The importer runs in three contexts** and the code must keep working in all of
them: `wp eval-file` under Docker, `wp eval-file` on a server, and
`oc-import-cli.php` (bootstraps `wp-load.php`) where WP-CLI is absent. Hence
`oc_log()`/`oc_abort()` instead of direct `WP_CLI::` calls, and `$oc_argv`
instead of `$args`.

Source-data quirks the importer handles — keep them in mind before "correcting"
the mapping code: OpenCart descriptions are entity-escaped and need one
`html_entity_decode` pass (with `kses_remove_filters()` so size tables survive),
language is fixed to `language_id = 2` (pt-BR), weights are in **pounds**
(`weight_class_id = 5`) while the store uses kg, and only specials whose date
window covers today become sale prices.

`extract-catalog.sh` is **local only** — it slices the 19 catalog tables out of
the 192 MB dump and deliberately drops `oc_user_login` (109 MB) plus all
customer, address, order and session tables, which are real PII. On the server
the OpenCart database is already present, so that step is skipped.

Running against production: `run-import.sh preflight` is read-only and verifies
the environment (including that a sample image is actually served through the
symlink over HTTP). `OC_IMPORT_STATUS` defaults to `draft` so nothing appears on
the live storefront until reviewed.
