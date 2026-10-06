-- Shop F2: idempotent pending-order preparation for Checkout V1.
-- Run once after 20261006_shop_f1_checkout_shipping_foundation.sql.

DELIMITER //

CREATE PROCEDURE migrate_shop_f2_checkout_v1()
BEGIN
    DECLARE matching_columns INT DEFAULT 0;
    DECLARE matching_index INT DEFAULT 0;

    SELECT COUNT(*) INTO matching_columns
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'shop_orders'
      AND COLUMN_NAME IN ('checkout_attempt_token', 'checkout_payload_hash');

    SELECT COUNT(*) INTO matching_index
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'shop_orders'
      AND INDEX_NAME = 'uq_shop_orders_checkout_attempt'
      AND NON_UNIQUE = 0;

    IF matching_columns = 2 AND matching_index = 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Shop F2 migration already applied';
    END IF;

    IF matching_columns <> 0 OR matching_index <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Shop F2 migration found a partial prior application';
    END IF;

    ALTER TABLE shop_orders
        ADD COLUMN checkout_attempt_token CHAR(64) NULL AFTER payment_status,
        ADD COLUMN checkout_payload_hash CHAR(64) NULL AFTER checkout_attempt_token,
        ADD UNIQUE KEY uq_shop_orders_checkout_attempt (checkout_attempt_token);

    IF EXISTS (
        SELECT 1 FROM shop_orders
        WHERE (checkout_attempt_token IS NULL) <> (checkout_payload_hash IS NULL)
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Shop F2 post-migration verification failed';
    END IF;
END//

DELIMITER ;

CALL migrate_shop_f2_checkout_v1();
DROP PROCEDURE migrate_shop_f2_checkout_v1;
