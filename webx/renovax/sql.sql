-- RENOVAX Payments — WebX.One integration audit + idempotency table.
-- Run once against the merchant's `webx` database:
--   mysql -u root -p webx < sql.sql

CREATE TABLE IF NOT EXISTS `pagos_renovax` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_id`        VARCHAR(64)   NULL UNIQUE,
    `invoice_id`      VARCHAR(64)   NOT NULL UNIQUE,
    `webx_user_id`    INT UNSIGNED  NOT NULL,
    `webx_email`      VARCHAR(255)  NOT NULL,
    `username`        VARCHAR(255)  NOT NULL,
    `amount_request`  DECIMAL(12, 2) NOT NULL,
    `amount_received` DECIMAL(12, 2) NULL,
    `currency`        VARCHAR(8)    NOT NULL,
    `balance_antes`   DECIMAL(12, 3) NULL,
    `balance_despues` DECIMAL(12, 3) NULL,
    `tx_hash`         VARCHAR(255)  NULL,
    `status`          ENUM('pending','paid','overpaid','partial','expired','failed','refunded')
                      NOT NULL DEFAULT 'pending',
    `client_ip`       VARCHAR(45)   NULL,
    `created_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user`   (`webx_user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`created_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
