-- ============================================================
-- sorting_api_migration.sql
-- Run ONCE against the `yama` database to support persistent
-- token refresh and mobile device registrations.
-- ============================================================

-- 1. Create refresh_tokens table to support token rotation
CREATE TABLE IF NOT EXISTS `refresh_tokens` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `expires_at` DATETIME NOT NULL,
    `is_valid` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` TEXT DEFAULT NULL,
    INDEX `idx_user_refresh` (`user_id`),
    INDEX `idx_token_hash` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create registered_devices table to support FCM silent push notifications
CREATE TABLE IF NOT EXISTS `registered_devices` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `device_id` VARCHAR(255) NOT NULL UNIQUE,
    `device_name` VARCHAR(255) DEFAULT NULL,
    `platform` VARCHAR(50) DEFAULT 'android',
    `app_version` VARCHAR(50) DEFAULT NULL,
    `fcm_token` TEXT DEFAULT NULL,
    `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_active_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_devices` (`user_id`),
    INDEX `idx_device_lookup` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'API Migration Complete' AS status;
