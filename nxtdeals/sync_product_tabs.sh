#!/bin/bash
# nxt.deals — populate the Product Safety (GPSR) + Specifications tabs after a
# product import (BigBuy module / Akeneo / any source). Both extractors scan the
# PrestaShop product descriptions, so run this whenever new products are imported.
#
#   - extract_gpsr_to_safety.php : MOVES the GPSR block out of the description into
#                                  the Product Safety tab (nxtdealstabs_gpsr).
#   - extract_specs_dtdd_all.php : COPIES the feature <ul> into <dt>/<dd> rows in
#                                  the Specifications tab (nxtdealstabs_specs);
#                                  description left unchanged. Idempotent.
# Then clears the PrestaShop cache so the tabs refresh.
#
# Usage: sync_product_tabs.sh
set -uo pipefail
SYNC_DIR=/data/akeneo/akeneo-prestashop-sync
PS_DIR=/var/www/html/nxt.deals

echo "=== $(date -Is) Product Safety (GPSR) ==="
php "$SYNC_DIR/extract_gpsr_to_safety.php"

echo "=== $(date -Is) Specifications ==="
php "$SYNC_DIR/extract_specs_dtdd_all.php"

echo "=== $(date -Is) Clearing PrestaShop cache ==="
( cd "$PS_DIR" && sudo -u www-data php bin/console cache:clear --no-warmup 2>&1 | grep -iE "OK|error" )
redis-cli -n 0 FLUSHDB >/dev/null 2>&1 && echo "Redis db0 flushed"
echo "=== done ==="
