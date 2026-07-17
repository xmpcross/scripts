#!/usr/bin/env bash
set -u

APP_DIR="/var/www/html/nxt.bargains"
NODE="/usr/bin/node"
SERVICE="nxt-bargains.service"
LOG_PREFIX="[nxt-bargains-best-sellers]"

cd "$APP_DIR" || exit 1

started_at="$(date -Is)"
echo "$LOG_PREFIX started at $started_at"

success=0
failed=0

run_fetch() {
  local label="$1"
  shift
  echo "$LOG_PREFIX fetching $label"
  if "$NODE" "$@"; then
    echo "$LOG_PREFIX ok: $label"
    success=$((success + 1))
  else
    echo "$LOG_PREFIX failed: $label"
    failed=$((failed + 1))
  fi
}

# Use direct marketplace URLs so GeniusLink/affiliate tracking does not wrap Google Shopping pages.
# Real-Time Product Search is used for stores where product-offers exposes merchant URLs.
run_fetch "Amazon" scripts/fetch-best-sellers.mjs --limit=30
run_fetch "eBay" scripts/fetch-ebay.mjs --limit=30
run_fetch "Walmart" scripts/fetch-store-products.mjs --store=Walmart --query="electronics deals" --limit=30 --offer-timeout=5000 --out=best-sellers-walmart.json --source-url=https://www.walmart.com/shop/deals/electronics
run_fetch "Target" scripts/fetch-store-products.mjs --store=Target --query="electronics deals" --limit=12 --out=best-sellers-target.json
run_fetch "Best Buy" scripts/fetch-store-products.mjs --store="Best Buy" --query="best sellers" --limit=12 --out=best-sellers-bestbuy.json
run_fetch "Newegg" scripts/fetch-store-products.mjs --store=Newegg --query="best sellers" --limit=12 --out=best-sellers-newegg.json

if [ "$success" -gt 0 ]; then
  echo "$LOG_PREFIX $success source(s) refreshed, rebuilding frontend"
  if "$NODE" node_modules/next/dist/bin/next build; then
    echo "$LOG_PREFIX build ok, restarting $SERVICE"
    systemctl restart "$SERVICE"
  else
    echo "$LOG_PREFIX build failed; service not restarted"
    exit 1
  fi
else
  echo "$LOG_PREFIX no sources refreshed; keeping existing build and cache"
  exit 1
fi

echo "$LOG_PREFIX finished at $(date -Is), success=$success failed=$failed"
