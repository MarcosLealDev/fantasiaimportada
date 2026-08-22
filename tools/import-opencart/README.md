# OpenCart → WooCommerce catalog import

Imports the Bella Collezione OpenCart catalog (products, categories, brands, size
options, images) into the local WooCommerce store.

## Source

| What | Where |
| --- | --- |
| Database dump | `/Users/marcosleal/devops/bellacollezione2/Database-export/20260814_bellaDB.tgz` |
| Images | `/Users/marcosleal/devops/bella-image/catalog` (mounted read-only) |
| OpenCart thumbnail cache | `/Users/marcosleal/devops/bella-image/cache/catalog` (mounted read-only) |

**Images are linked, never copied**, and nothing is ever written into
`bella-image`. Attachments point at `wp-content/uploads/oc-catalog/...` and carry
`_oc_rel_path` meta; `docker/mu-plugins/01-opencart-images.php` resolves each
requested size against OpenCart's cache first, then renders anything missing into
`wp-content/uploads/oc-thumbs/` (disposable — delete it and it regenerates).

## Running it locally (Docker)

```sh
# 1. Slice the catalog tables out of the 192 MB dump (skips oc_user_login and all
#    customer/order/session PII) and load them into a second database.
./tools/import-opencart/extract-catalog.sh
docker compose exec -T db mariadb -uroot -proot \
  -e "CREATE DATABASE IF NOT EXISTS bella_oc DEFAULT CHARACTER SET utf8mb4; \
      GRANT ALL ON bella_oc.* TO 'fantasia'@'%'; FLUSH PRIVILEGES;"
docker compose exec -T db mariadb -uroot -proot bella_oc < tools/import-opencart/bella-catalog.sql

# 2. Import, in order.
R="docker compose --profile tools run --rm wpcli eval-file /import/import.php"
$R categories
$R brands
$R attributes
$R products 100            # resumes from where it left off
```

`products` takes an optional batch size, a "start after this OpenCart id", and a
batch cap for testing:

```sh
$R products 10 0 1         # first 10 products only, from the beginning
$R products 100 4500       # resume after OpenCart product 4500
```

Every phase is idempotent — records are matched on `_oc_product_id`,
`_oc_category_id`, `_oc_manufacturer_id` and `_oc_rel_path`, so re-running
updates instead of duplicating. Progress is stored in the `oc_import_last_product_id`
option, which is why a bare `products` call resumes.

**`products-missing`** is a separate catch-up phase: it diffs every OpenCart
product id against the ones already carrying `_oc_product_id` meta and imports
only the gap, ignoring the sequential pointer entirely. Run it after the main
import to pick up anything a previous run skipped (an empty name, a transient
save failure) or that was added to OpenCart afterwards:

```sh
$R products-missing 100
```

**`OC_BATCH_DELAY`** (seconds, fractional allowed, e.g. `1.5`) pauses between
batches in both `products` and `products-missing`. It defaults to `0`
(no pause); set it via `import.env` or the environment when running against a
live server, to avoid loading its database or PHP pool during the migration:

```sh
docker compose --profile tools run --rm -e OC_BATCH_DELAY=2 wpcli eval-file /import/import.php products 100
```

## Running it on the live server

Both databases and the OpenCart images are already on the server, so the dump
extraction step is **local only** -- skip it there.

```sh
cd tools/import-opencart
cp import.env.example import.env      # fill in WP_PATH, OC_IMAGE_CATALOG, OC_DB_NAME
./run-import.sh preflight             # read-only; changes nothing
./run-import.sh backup                # mysqldump the WordPress database
./run-import.sh setup                 # symlink images, install the mu-plugin
./run-import.sh preflight             # re-check now that the symlink exists
./run-import.sh import                # categories, brands, attributes, products, finish
```

`./run-import.sh all` chains backup, setup, preflight and import in that order.

**Run `preflight` first and read it.** It verifies WooCommerce is active, the
OpenCart tables are reachable and populated, a sample image is readable *and
actually served over HTTP through the symlink*, `oc-thumbs` is writable, and GD
or Imagick is available. It writes nothing. A `403` on the HTTP check means
Apache is refusing to follow the symlink -- add `Options +FollowSymLinks` to the
uploads directory config.

**WP-CLI is optional.** `run-import.sh` uses `wp` if it is on the PATH (or a
`wp-cli.phar` next to the script), and otherwise falls back to
`oc-import-cli.php`, which bootstraps WordPress through `wp-load.php`. Both
paths run the same code.

**Start with `OC_IMPORT_STATUS=draft`** (the default in `import.env.example`) so
nothing appears on the live storefront until you have looked at it. Re-running
the products phase with `OC_IMPORT_STATUS=source` afterwards flips them to
OpenCart's own statuses without duplicating anything.

The import is resumable: progress is saved to the `oc_import_last_product_id`
option after every batch, so a dropped SSH session costs at most one batch. A
bare `./run-import.sh products` continues from there; pass an explicit `0` to
start over. Run `./run-import.sh products-missing` afterwards as a safety net --
it finds any OpenCart product with no matching WooCommerce product yet
(regardless of where the sequential pointer stopped) and imports just those.

Expect roughly 20 products/second -- about 7 minutes for a 7,700-product
catalog, plus whatever the server's disk is doing. **Set `OC_BATCH_DELAY` in
`import.env`** (seconds, fractional allowed) to pause between batches instead --
useful on a live server so the migration doesn't compete with real traffic for
the database or PHP pool. It applies to both `products` and `products-missing`.

### What touches the live site

| Action | Where |
| --- | --- |
| Products, variations, terms, attachments | WordPress database |
| `oc-catalog` / `oc-cache` symlinks | `wp-content/uploads/` |
| `01-opencart-images.php` | `wp-content/mu-plugins/` |
| Generated thumbnails | `wp-content/uploads/oc-thumbs/` |

The OpenCart image directory and the OpenCart database are only ever **read**.

### Undoing it

Every imported object carries an OpenCart id in meta, so it can be identified
precisely:

```sh
# products and their variations
wp post delete $(wp post list --post_type=product --meta_key=_oc_product_id --format=ids) --force
# linked attachments (deletes rows only -- the files are symlinked, not owned)
wp post delete $(wp post list --post_type=attachment --meta_key=_oc_rel_path --format=ids) --force
```

Restoring the `backup` dump is the blunter option.

## Mapping notes

- Descriptions are entity-escaped in OpenCart and get one `html_entity_decode`
  pass; `kses_remove_filters()` keeps the size tables intact.
- Language is fixed to `language_id = 2` (Português BR).
- Weights convert from pounds (`weight_class_id = 5`) to the store's kg.
- Products with OpenCart options become variable products with a global
  attribute each (`pa_tamanho` and friends); everything else stays simple.
- Only specials whose date window covers today become sale prices.
- OpenCart `status` maps to publish/draft.
