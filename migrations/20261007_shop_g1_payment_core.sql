-- Shop G1: internal, provider-neutral payment attempts and event deduplication.
-- Requires Shop F1/F2. Additive and safe to apply repeatedly; no backfill/DML.
DROP PROCEDURE IF EXISTS migrate_shop_g1_payment_core;
DELIMITER //
CREATE PROCEDURE migrate_shop_g1_payment_core()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders'
          AND COLUMN_NAME IN ('currency', 'payment_status', 'order_origin', 'checkout_attempt_token')) <> 4
       OR NOT EXISTS (SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND ENGINE = 'InnoDB') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shop G1 requires the Shop F1/F2 order foundation';
    END IF;

    CREATE TABLE IF NOT EXISTS shop_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        provider VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        provider_session_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
        provider_payment_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
        attempt_reference VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'USD',
        status ENUM('pending', 'paid', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
        failure_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        paid_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uq_shop_payments_attempt (provider, attempt_reference),
        UNIQUE KEY uq_shop_payments_session (provider, provider_session_id),
        UNIQUE KEY uq_shop_payments_capture (provider, provider_payment_id),
        UNIQUE KEY uq_shop_payments_event_relation (id, order_id, provider),
        KEY idx_shop_payments_order (order_id, status, id),
        CONSTRAINT fk_shop_payments_order FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE RESTRICT,
        CONSTRAINT chk_shop_payments_amount CHECK (amount > 0),
        CONSTRAINT chk_shop_payments_currency CHECK (currency = 'USD'),
        CONSTRAINT chk_shop_payments_paid CHECK (
            (status = 'paid' AND paid_at IS NOT NULL AND provider_payment_id IS NOT NULL AND failure_code IS NULL)
            OR (status <> 'paid' AND paid_at IS NULL AND provider_payment_id IS NULL)
        )
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS shop_payment_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        provider_event_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        event_type VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        payment_id INT UNSIGNED NULL,
        order_id INT UNSIGNED NULL,
        result_status ENUM('paid', 'failed', 'cancelled') NOT NULL,
        result_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        processing_status ENUM('received', 'processed') NOT NULL DEFAULT 'received',
        received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uq_shop_payment_events_provider_event (provider, provider_event_id),
        KEY idx_shop_payment_events_payment (payment_id, order_id, provider),
        KEY idx_shop_payment_events_order (order_id, id),
        CONSTRAINT fk_shop_payment_events_payment FOREIGN KEY (payment_id, order_id, provider)
            REFERENCES shop_payments(id, order_id, provider) ON DELETE RESTRICT,
        CONSTRAINT fk_shop_payment_events_order FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE RESTRICT,
        CONSTRAINT chk_shop_payment_events_relation CHECK ((payment_id IS NULL) = (order_id IS NULL)),
        CONSTRAINT chk_shop_payment_events_processed CHECK (
            (processing_status = 'received' AND processed_at IS NULL)
            OR (processing_status = 'processed' AND processed_at IS NOT NULL)
        )
    ) ENGINE=InnoDB;

    -- Reject incompatible pre-existing objects instead of silently accepting them.
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'shop_payments' AND COLUMN_NAME IN
        ('id','order_id','provider','provider_session_id','provider_payment_id','attempt_reference',
         'amount','currency','status','failure_code','created_at','updated_at','paid_at')) <> 13
       OR (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'shop_payment_events' AND COLUMN_NAME IN
        ('id','provider','provider_event_id','event_type','payment_id','order_id','result_status',
         'result_hash','processing_status','received_at','processed_at')) <> 11
       OR (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND CONSTRAINT_NAME IN ('uq_shop_payments_attempt','uq_shop_payments_session','uq_shop_payments_capture',
         'uq_shop_payments_event_relation','uq_shop_payment_events_provider_event',
         'fk_shop_payments_order','fk_shop_payment_events_payment','fk_shop_payment_events_order',
         'chk_shop_payments_amount','chk_shop_payments_currency','chk_shop_payments_paid',
         'chk_shop_payment_events_relation','chk_shop_payment_events_processed')) <> 13 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shop G1 found incompatible payment tables; inspect their schema';
    END IF;
END//
DELIMITER ;
CALL migrate_shop_g1_payment_core();
DROP PROCEDURE migrate_shop_g1_payment_core;
