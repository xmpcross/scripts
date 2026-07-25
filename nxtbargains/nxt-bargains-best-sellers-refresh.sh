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

# Amazon best sellers via the Amazon Product Info2 API (the only active RapidAPI
# subscription). eBay uses the direct eBay Browse API.
#
# NOTE: Walmart/Target/Best Buy/Newegg best-sellers used the Real-Time Product
# Search RapidAPI, which is no longer subscribed. Those steps were removed; their
# data/best-sellers-<store>.json caches are retained (served as-is). Amazon
# Product Info2 is Amazon-only, so it cannot backfill those marketplaces.
run_fetch "Amazon" scripts/fetch-amazon-product-info2-best-sellers.mjs --limit=30
run_fetch "eBay" scripts/fetch-ebay.mjs --limit=30

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
