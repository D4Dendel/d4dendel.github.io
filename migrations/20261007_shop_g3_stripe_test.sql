-- G3 bindings only; historical attempts remain unbound (NULL).
DROP PROCEDURE IF EXISTS migrate_shop_g3_stripe_test;
DELIMITER //
CREATE PROCEDURE migrate_shop_g3_stripe_test()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='shop_payment_providers') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Shop G3 requires G1/G2';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='shop_payments' AND COLUMN_NAME='provider_mode') THEN
        ALTER TABLE shop_payments ADD COLUMN provider_mode ENUM('test','live') NULL AFTER provider;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='shop_payments' AND COLUMN_NAME='provider_session_url') THEN
        ALTER TABLE shop_payments ADD COLUMN provider_session_url VARCHAR(1000) NULL AFTER provider_session_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='shop_payments' AND COLUMN_NAME='provider_session_expires_at') THEN
        ALTER TABLE shop_payments ADD COLUMN provider_session_expires_at BIGINT UNSIGNED NULL AFTER provider_session_url;
    END IF;
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='shop_payments' AND COLUMN_NAME IN
        ('provider_mode','provider_session_url','provider_session_expires_at')) <> 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Shop G3 binding schema is incomplete';
    END IF;
END//
DELIMITER ;
CALL migrate_shop_g3_stripe_test();
DROP PROCEDURE migrate_shop_g3_stripe_test;
