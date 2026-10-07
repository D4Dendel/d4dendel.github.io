-- G2 safe metadata only. No provider credentials or fake connections.
DROP PROCEDURE IF EXISTS migrate_shop_g2_payment_providers;
DELIMITER //
CREATE PROCEDURE migrate_shop_g2_payment_providers()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('shop_payments','shop_payment_events') AND ENGINE = 'InnoDB') <> 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shop G2 requires the G1 Payment Core';
    END IF;
    CREATE TABLE IF NOT EXISTS shop_payment_providers (
        provider VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        mode ENUM('test', 'live') NOT NULL DEFAULT 'test',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT chk_shop_payment_providers_key CHECK (provider IN ('paypal','stripe')),
        CONSTRAINT chk_shop_payment_providers_enabled CHECK (enabled IN (0,1))
    ) ENGINE=InnoDB;
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'shop_payment_providers' AND COLUMN_NAME IN
        ('provider','enabled','mode','created_at','updated_at')) <> 5
       OR (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'shop_payment_providers' AND CONSTRAINT_NAME IN
        ('PRIMARY','chk_shop_payment_providers_key','chk_shop_payment_providers_enabled')) <> 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shop G2 provider schema is incompatible; inspect before proceeding';
    END IF;
    INSERT IGNORE INTO shop_payment_providers (provider) VALUES ('paypal'), ('stripe');
END//
DELIMITER ;
CALL migrate_shop_g2_payment_providers();
DROP PROCEDURE migrate_shop_g2_payment_providers;
