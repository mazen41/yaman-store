-- ============================================================
-- sorting_migration.sql
-- Run ONCE against the `yama` database.
-- Safe to run multiple times (uses IF NOT EXISTS / ALTER IGNORE).
-- ============================================================

-- 1. Ensure shein_products table exists with all required columns
CREATE TABLE IF NOT EXISTS shein_products (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shein_sku  VARCHAR(100) NOT NULL UNIQUE,
    name       VARCHAR(500) NOT NULL DEFAULT '',
    image      TEXT,
    link       TEXT,
    price      VARCHAR(50)  DEFAULT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add price column if missing (safe to run again)
ALTER TABLE shein_products
    ADD COLUMN IF NOT EXISTS price VARCHAR(50) DEFAULT NULL;

-- 3. Ensure order_items has status + shein_sku + updated_at columns
ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS shein_sku VARCHAR(100) DEFAULT NULL AFTER product_name;

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS status VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER shein_sku;

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 4. Index for fast SKU lookups
CREATE INDEX IF NOT EXISTS idx_order_items_shein_sku ON order_items (shein_sku);
CREATE INDEX IF NOT EXISTS idx_order_items_status    ON order_items (status);

-- 5. Index on shein_products.shein_sku (already UNIQUE but add for explicit reference)
-- (UNIQUE constraint already provides this)

-- Done.
SELECT 'Migration complete' AS status;
