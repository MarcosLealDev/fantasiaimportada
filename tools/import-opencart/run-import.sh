#!/usr/bin/env bash
#
# Import the OpenCart catalog into WooCommerce on a server where both databases
# and the OpenCart images already exist.
#
#   ./run-import.sh preflight        # read-only checks, changes nothing
#   ./run-import.sh setup            # symlink images + install the mu-plugin
#   ./run-import.sh backup           # mysqldump the WordPress database first
#   ./run-import.sh import           # categories, brands, attributes, products
#   ./run-import.sh products 100     # resume just the product phase
#   ./run-import.sh finish           # recount terms, clear caches, flush permalinks
#
#   ./run-import.sh all              # backup + setup + preflight + import
#
# Configure by copying import.env.example to import.env next to this script,
# or by exporting the same variables.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -f "$HERE/import.env" ] && . "$HERE/import.env"

# --- configuration -----------------------------------------------------------
WP_PATH="${WP_PATH:-}"                      # WordPress root (contains wp-load.php)
OC_IMAGE_CATALOG="${OC_IMAGE_CATALOG:-}"    # OpenCart .../image/catalog directory
OC_IMAGE_CACHE="${OC_IMAGE_CACHE:-}"        # optional .../image/cache/catalog
OC_DB_NAME="${OC_DB_NAME:-}"                # OpenCart database
OC_DB_USER="${OC_DB_USER:-}"                # blank = reuse the WordPress credentials
OC_DB_PASSWORD="${OC_DB_PASSWORD:-}"
OC_DB_HOST="${OC_DB_HOST:-}"
OC_IMPORT_STATUS="${OC_IMPORT_STATUS:-draft}"   # draft | source | publish
OC_BATCH="${OC_BATCH:-100}"
BACKUP_DIR="${BACKUP_DIR:-$HERE/backups}"
export OC_DB_NAME OC_DB_USER OC_DB_PASSWORD OC_DB_HOST OC_IMPORT_STATUS WP_PATH
# -----------------------------------------------------------------------------

die()  { printf '\nError: %s\n' "$*" >&2; exit 1; }
info() { printf '\n== %s ==\n' "$*"; }

[ -n "$WP_PATH" ] || die "WP_PATH is not set. Copy import.env.example to import.env and fill it in."
[ -f "$WP_PATH/wp-load.php" ] || die "no wp-load.php in $WP_PATH"
[ -n "$OC_DB_NAME" ] || die "OC_DB_NAME is not set (the OpenCart database name)."

# --- runner: WP-CLI when available, standalone PHP otherwise ------------------
WP_BIN=""
if command -v wp >/dev/null 2>&1; then
	WP_BIN="wp"
elif [ -f "$HERE/wp-cli.phar" ]; then
	WP_BIN="php $HERE/wp-cli.phar"
elif [ -f "$WP_PATH/wp-cli.phar" ]; then
	WP_BIN="php $WP_PATH/wp-cli.phar"
fi

run_php() { # run_php <script.php> [args...]
	local script="$1"; shift
	if [ -n "$WP_BIN" ]; then
		# shellcheck disable=SC2086
		$WP_BIN --path="$WP_PATH" --skip-themes eval-file "$HERE/$script" "$@"
	else
		php "$HERE/oc-import-cli.php" --wp="$WP_PATH" --script="$script" "$@"
	fi
}

wp_db_value() { # read a constant out of wp-config.php without parsing it ourselves
	php -r '
		define("ABSPATH", rtrim($argv[1], "/") . "/");
		$c = file_get_contents($argv[1] . "/wp-config.php");
		if (preg_match("/define\(\s*[\x27\"]" . preg_quote($argv[2], "/") . "[\x27\"]\s*,\s*[\x27\"](.*?)[\x27\"]\s*\)/s", $c, $m)) echo $m[1];
	' "$WP_PATH" "$1"
}

case "${1:-}" in

preflight)
	info "Preflight (read-only)"
	run_php preflight.php
	;;

setup)
	info "Linking images and installing the image mu-plugin"
	[ -n "$OC_IMAGE_CATALOG" ] || die "OC_IMAGE_CATALOG is not set."
	[ -d "$OC_IMAGE_CATALOG" ] || die "not a directory: $OC_IMAGE_CATALOG"

	UPLOADS="$(run_php wp-uploads-path.php 2>/dev/null | tail -1 | tr -d '\r')"
	UPLOADS="${UPLOADS:-$WP_PATH/wp-content/uploads}"
	echo "uploads: $UPLOADS"

	# Symlinks, not copies: the OpenCart image tree is never duplicated and never
	# written to. Only oc-thumbs (below) is writable.
	if [ -e "$UPLOADS/oc-catalog" ] || [ -L "$UPLOADS/oc-catalog" ]; then
		echo "oc-catalog already present, leaving it alone"
	else
		ln -s "$OC_IMAGE_CATALOG" "$UPLOADS/oc-catalog"
		echo "linked oc-catalog -> $OC_IMAGE_CATALOG"
	fi

	if [ -n "$OC_IMAGE_CACHE" ] && [ -d "$OC_IMAGE_CACHE" ] && [ ! -e "$UPLOADS/oc-cache" ]; then
		ln -s "$OC_IMAGE_CACHE" "$UPLOADS/oc-cache"
		echo "linked oc-cache -> $OC_IMAGE_CACHE"
	fi

	mkdir -p "$UPLOADS/oc-thumbs"
	chmod 775 "$UPLOADS/oc-thumbs"
	echo "oc-thumbs ready (generated sizes live here, safe to delete anytime)"

	MU="$WP_PATH/wp-content/mu-plugins"
	mkdir -p "$MU"
	if [ -f "$MU/01-opencart-images.php" ] && cmp -s "$HERE/01-opencart-images.php" "$MU/01-opencart-images.php"; then
		echo "mu-plugin already installed and identical"
	else
		cp "$HERE/01-opencart-images.php" "$MU/01-opencart-images.php"
		echo "installed $MU/01-opencart-images.php"
	fi

	cat <<'NOTE'

If the sample image fails the HTTP check in preflight, Apache is refusing to
follow the symlink. Add to the uploads directory config (or .htaccess):

    Options +FollowSymLinks

Some hosts only allow: Options +SymLinksIfOwnerMatch -- which requires the
OpenCart image directory to be owned by the same user as the web root.
NOTE
	;;

backup)
	info "Backing up the WordPress database"
	mkdir -p "$BACKUP_DIR"
	DB_NAME="$(wp_db_value DB_NAME)"
	DB_USER="$(wp_db_value DB_USER)"
	DB_PASSWORD="$(wp_db_value DB_PASSWORD)"
	DB_HOST="$(wp_db_value DB_HOST)"
	command -v mysqldump >/dev/null 2>&1 || die "mysqldump not found; back up the database another way before importing."
	[ -n "$DB_NAME" ] || die "could not read DB_NAME from $WP_PATH/wp-config.php"
	OUT="$BACKUP_DIR/${DB_NAME}-$(date +%Y%m%d-%H%M%S).sql.gz"
	MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --quick \
		-h "${DB_HOST%%:*}" -u "$DB_USER" "$DB_NAME" | gzip > "$OUT"
	echo "wrote $OUT ($(du -h "$OUT" | cut -f1))"
	echo "restore with: gunzip -c $OUT | mysql -u $DB_USER -p $DB_NAME"
	;;

import)
	info "Importing (status policy: $OC_IMPORT_STATUS)"
	run_php import.php categories
	run_php import.php brands
	run_php import.php attributes
	run_php import.php products "$OC_BATCH"
	run_php finish.php
	;;

categories|brands|attributes|products)
	run_php import.php "$@"
	;;

finish)
	info "Post-import housekeeping"
	run_php finish.php
	;;

all)
	"$0" backup
	"$0" setup
	"$0" preflight
	"$0" import
	;;

*)
	sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
	exit 1
	;;
esac
