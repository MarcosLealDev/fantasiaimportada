#!/usr/bin/env bash
# Extract only the catalog tables from the OpenCart dump.
#
# The full dump is 192 MB, of which oc_user_login alone is 109 MB, plus customer,
# address, order and session tables holding real PII. None of that is needed to
# import a product catalog, so we keep just the 19 tables below.
set -euo pipefail

SRC="${1:-/Users/marcosleal/devops/bellacollezione2/Database-export/20260814_bellaDB.tgz}"
OUT="${2:-tools/import-opencart/bella-catalog.sql}"

TABLES="oc_product oc_product_description oc_product_image oc_product_to_category oc_product_special oc_product_option oc_product_option_value oc_category oc_category_description oc_category_path oc_option oc_option_description oc_option_value oc_option_value_description oc_manufacturer oc_seo_url oc_language oc_stock_status oc_weight_class_description"

[ -f "$SRC" ] || { echo "source dump not found: $SRC" >&2; exit 1; }
mkdir -p "$(dirname "$OUT")"

# The dump is one row per line, so a streaming filter is enough: copy the session
# header, then every DROP/CREATE/LOCK/INSERT block belonging to a wanted table.
# CREATE DATABASE / USE are dropped so the result loads into whatever database
# the client selects (bella_oc), not the source's own name.
tar -xOzf "$SRC" | awk -v want="$TABLES" '
BEGIN { n = split(want, a, " "); for (i = 1; i <= n; i++) keep[a[i]] = 1; header = 1 }

/^(CREATE DATABASE|USE )/ { next }

/^(DROP TABLE|CREATE TABLE|LOCK TABLES|INSERT INTO)/ {
  header = 0
  split($0, parts, "`")
  emit = (parts[2] in keep) ? 1 : 0
}
header { print; next }
/^UNLOCK TABLES/ { if (emit) print; emit = 0; next }
emit { print }
END {
  print "/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, \"\") */;"
  print "/*!40014 SET FOREIGN_KEY_CHECKS=IF(@OLD_FOREIGN_KEY_CHECKS IS NULL, 1, @OLD_FOREIGN_KEY_CHECKS) */;"
  print "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;"
  print "COMMIT;"
}
' > "$OUT"

echo "wrote $OUT ($(du -h "$OUT" | cut -f1))"
echo "tables: $(grep -c '^CREATE TABLE' "$OUT")"
