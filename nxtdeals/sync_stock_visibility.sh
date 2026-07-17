#!/bin/bash
# nxt.deals — hide out-of-stock products from the storefront, show restocked ones.
#
#   * HIDE (active=0): any product whose stock quantity <= 0.
#   * SHOW (active=1): in-stock products that have a VALID title.
#     (Products with an empty/"-" placeholder title stay hidden — they are
#      handled separately and must not reappear.)
#
# Idempotent. Updates both o6kfr_product and o6kfr_product_shop (front office
# reads product_shop.active). Run after stock changes / imports, or on a cron.
set -uo pipefail
DB=(mysql -h127.0.0.1 -ukryptok -pafhajT11!@ nxtdeals_data)

"${DB[@]}" -e "
-- 1) hide out-of-stock
UPDATE o6kfr_product p
JOIN o6kfr_product_shop ps ON ps.id_product=p.id_product
JOIN o6kfr_stock_available sa ON sa.id_product=p.id_product AND sa.id_product_attribute=0
SET p.active=0, ps.active=0
WHERE sa.quantity<=0 AND (p.active=1 OR ps.active=1);
SELECT CONCAT('hidden (out of stock): ', ROW_COUNT()) AS step1;

-- 2) show in-stock products that have a valid title
UPDATE o6kfr_product p
JOIN o6kfr_product_shop ps ON ps.id_product=p.id_product
JOIN o6kfr_stock_available sa ON sa.id_product=p.id_product AND sa.id_product_attribute=0
JOIN o6kfr_product_lang pl ON pl.id_product=p.id_product AND pl.id_lang=1
SET p.active=1, ps.active=1
WHERE sa.quantity>0
  AND (p.active=0 OR ps.active=0)
  AND TRIM(pl.name) NOT IN ('','-','--')
  AND TRIM(pl.name) NOT REGEXP '^[[:space:].-]+\$';
SELECT CONCAT('shown (restocked, titled): ', ROW_COUNT()) AS step2;
" 2>&1 | grep -vE "^step[12]$"

# refresh front-office cache so listings update
redis-cli -n 0 FLUSHDB >/dev/null 2>&1 && echo "Redis db0 flushed"
