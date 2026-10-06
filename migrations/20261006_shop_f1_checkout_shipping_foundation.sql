-- Shop F1 checkout and shipping foundation for MariaDB 10.4.32.
--
-- This migration is additive. It keeps the legacy order columns and endpoint
-- compatible while adding the snapshots and configuration needed by a future
-- guest Checkout flow. It creates no orders, changes no product stock, and
-- installs no shipping rates.
--
-- The guard deliberately fails when any F1 object is already present so an
-- accidental reapplication or partial prior application is inspected first.

DROP PROCEDURE IF EXISTS migrate_shop_f1_checkout_shipping_foundation;

DELIMITER //

CREATE PROCEDURE migrate_shop_f1_checkout_shipping_foundation()
BEGIN
    DECLARE existing_order_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE existing_item_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE migrated_order_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE migrated_item_count BIGINT UNSIGNED DEFAULT 0;

    IF EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN (
              'shop_shipping_zones',
              'shop_shipping_zone_countries',
              'shop_shipping_methods'
          )
    ) OR EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND (
              (TABLE_NAME = 'shop_orders' AND COLUMN_NAME IN ('currency', 'payment_status', 'subtotal', 'shipping_method_name'))
              OR (TABLE_NAME = 'shop_order_items' AND COLUMN_NAME IN ('product_name', 'line_total', 'currency'))
          )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Shop F1 foundation already or partially exists; inspect before reapplying';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'shop_products'
          AND ENGINE = 'InnoDB'
    ) OR NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'shop_orders'
          AND ENGINE = 'InnoDB'
    ) OR NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'shop_order_items'
          AND ENGINE = 'InnoDB'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Expected InnoDB Shop product/order tables were not found';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM shop_orders
        WHERE total < 0
           OR status NOT IN ('pending', 'confirmed', 'processing', 'shipped', 'completed', 'cancelled')
    ) OR EXISTS (
        SELECT 1
        FROM shop_order_items
        WHERE quantity < 1 OR price < 0
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Legacy order data failed Shop F1 preflight validation';
    END IF;

    SELECT COUNT(*) INTO existing_order_count FROM shop_orders;
    SELECT COUNT(*) INTO existing_item_count FROM shop_order_items;

    CREATE TABLE shop_shipping_zones (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(64) NOT NULL,
        name VARCHAR(120) NOT NULL,
        is_rest_of_world TINYINT(1) NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_shipping_zones_code (code),
        KEY idx_shop_shipping_zones_listing (enabled, sort_order, id),
        CONSTRAINT chk_shop_shipping_zones_flags CHECK (
            is_rest_of_world IN (0, 1) AND enabled IN (0, 1)
        )
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE shop_shipping_zone_countries (
        zone_id INT UNSIGNED NOT NULL,
        country_code CHAR(2) NOT NULL,
        PRIMARY KEY (zone_id, country_code),
        UNIQUE KEY uq_shop_shipping_country (country_code),
        CONSTRAINT chk_shop_shipping_country_code CHECK (
            country_code REGEXP '^[A-Z]{2}$'
        ),
        CONSTRAINT fk_shop_shipping_country_zone
            FOREIGN KEY (zone_id) REFERENCES shop_shipping_zones (id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE shop_shipping_methods (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        zone_id INT UNSIGNED NOT NULL,
        code VARCHAR(64) NOT NULL,
        name VARCHAR(120) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'USD',
        estimated_delivery_min SMALLINT UNSIGNED NULL,
        estimated_delivery_max SMALLINT UNSIGNED NULL,
        estimated_delivery_unit ENUM('business_days', 'calendar_days') NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_shipping_method_code (zone_id, code),
        KEY idx_shop_shipping_methods_listing (zone_id, enabled, sort_order, id),
        CONSTRAINT fk_shop_shipping_method_zone
            FOREIGN KEY (zone_id) REFERENCES shop_shipping_zones (id)
            ON DELETE CASCADE,
        CONSTRAINT chk_shop_shipping_method_price CHECK (price >= 0),
        CONSTRAINT chk_shop_shipping_method_currency CHECK (currency = 'USD'),
        CONSTRAINT chk_shop_shipping_method_enabled CHECK (enabled IN (0, 1)),
        CONSTRAINT chk_shop_shipping_method_estimate CHECK (
            (
                estimated_delivery_min IS NULL
                AND estimated_delivery_max IS NULL
                AND estimated_delivery_unit IS NULL
            ) OR (
                estimated_delivery_min IS NOT NULL
                AND estimated_delivery_max IS NOT NULL
                AND estimated_delivery_unit IS NOT NULL
                AND estimated_delivery_min > 0
                AND estimated_delivery_max >= estimated_delivery_min
            )
        )
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci;

    ALTER TABLE shop_orders
        ADD COLUMN customer_phone VARCHAR(40) NULL AFTER customer_email,
        MODIFY COLUMN customer_address TEXT NULL,
        ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER customer_address,
        ADD COLUMN shipping_required TINYINT(1) NOT NULL DEFAULT 1 AFTER currency,
        ADD COLUMN shipping_address_line1 VARCHAR(255) NULL AFTER shipping_required,
        ADD COLUMN shipping_address_line2 VARCHAR(255) NULL AFTER shipping_address_line1,
        ADD COLUMN shipping_city VARCHAR(120) NULL AFTER shipping_address_line2,
        ADD COLUMN shipping_region VARCHAR(120) NULL AFTER shipping_city,
        ADD COLUMN shipping_postal_code VARCHAR(32) NULL AFTER shipping_region,
        ADD COLUMN shipping_country_code CHAR(2) NULL AFTER shipping_postal_code,
        ADD COLUMN shipping_method_id INT UNSIGNED NULL AFTER shipping_country_code,
        ADD COLUMN shipping_method_name VARCHAR(120) NULL AFTER shipping_method_id,
        ADD COLUMN shipping_estimate_min SMALLINT UNSIGNED NULL AFTER shipping_method_name,
        ADD COLUMN shipping_estimate_max SMALLINT UNSIGNED NULL AFTER shipping_estimate_min,
        ADD COLUMN shipping_estimate_unit ENUM('business_days', 'calendar_days') NULL AFTER shipping_estimate_max,
        ADD COLUMN subtotal DECIMAL(10,2) NULL AFTER shipping_estimate_unit,
        ADD COLUMN shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subtotal,
        ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER shipping_amount,
        ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_amount,
        MODIFY COLUMN total DECIMAL(10,2) NOT NULL AFTER discount_amount,
        ADD COLUMN order_origin ENUM('legacy', 'checkout_v2') NOT NULL DEFAULT 'legacy' AFTER status,
        ADD COLUMN payment_status ENUM('unpaid', 'pending', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'unpaid' AFTER order_origin,
        ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

    UPDATE shop_orders
    SET subtotal = total,
        shipping_amount = 0.00,
        tax_amount = 0.00,
        discount_amount = 0.00,
        currency = 'USD',
        order_origin = 'legacy',
        payment_status = 'unpaid';

    ALTER TABLE shop_orders
        MODIFY COLUMN subtotal DECIMAL(10,2) NOT NULL,
        ADD KEY idx_shop_orders_lifecycle (status, payment_status, created_at, id),
        ADD KEY idx_shop_orders_customer_email (customer_email, created_at, id),
        ADD CONSTRAINT fk_shop_orders_shipping_method
            FOREIGN KEY (shipping_method_id) REFERENCES shop_shipping_methods (id)
            ON DELETE SET NULL,
        ADD CONSTRAINT chk_shop_orders_currency CHECK (currency = 'USD'),
        ADD CONSTRAINT chk_shop_orders_shipping_required CHECK (shipping_required IN (0, 1)),
        ADD CONSTRAINT chk_shop_orders_status CHECK (
            status IN ('pending', 'confirmed', 'processing', 'shipped', 'completed', 'cancelled')
        ),
        ADD CONSTRAINT chk_shop_orders_amounts CHECK (
            subtotal >= 0
            AND shipping_amount >= 0
            AND tax_amount >= 0
            AND discount_amount >= 0
            AND discount_amount <= subtotal + shipping_amount + tax_amount
            AND total = subtotal + shipping_amount + tax_amount - discount_amount
        ),
        ADD CONSTRAINT chk_shop_orders_estimate CHECK (
            (
                shipping_estimate_min IS NULL
                AND shipping_estimate_max IS NULL
                AND shipping_estimate_unit IS NULL
            ) OR (
                shipping_estimate_min IS NOT NULL
                AND shipping_estimate_max IS NOT NULL
                AND shipping_estimate_unit IS NOT NULL
                AND shipping_estimate_min > 0
                AND shipping_estimate_max >= shipping_estimate_min
            )
        ),
        ADD CONSTRAINT chk_shop_orders_checkout_shipping CHECK (
            order_origin = 'legacy'
            OR (
                shipping_required = 0
                AND shipping_address_line1 IS NULL
                AND shipping_address_line2 IS NULL
                AND shipping_city IS NULL
                AND shipping_region IS NULL
                AND shipping_postal_code IS NULL
                AND shipping_country_code IS NULL
                AND shipping_method_name IS NULL
                AND shipping_amount = 0
            )
            OR (
                shipping_required = 1
                AND shipping_address_line1 IS NOT NULL
                AND shipping_city IS NOT NULL
                AND shipping_postal_code IS NOT NULL
                AND shipping_country_code REGEXP '^[A-Z]{2}$'
                AND shipping_method_name IS NOT NULL
            )
        );

    ALTER TABLE shop_order_items
        ADD COLUMN product_sku VARCHAR(80) NULL AFTER product_id,
        ADD COLUMN product_name VARCHAR(160) NULL AFTER product_sku,
        ADD COLUMN product_type ENUM('physical', 'digital') NULL AFTER product_name,
        ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER product_type,
        ADD COLUMN line_total DECIMAL(10,2) NULL AFTER price;

    UPDATE shop_order_items AS item
    JOIN shop_products AS product ON product.id = item.product_id
    SET item.product_sku = product.sku,
        item.product_name = product.title,
        item.product_type = product.product_type,
        item.currency = 'USD',
        item.line_total = item.price * item.quantity;

    ALTER TABLE shop_order_items
        MODIFY COLUMN product_sku VARCHAR(80) NOT NULL,
        MODIFY COLUMN product_name VARCHAR(160) NOT NULL,
        MODIFY COLUMN product_type ENUM('physical', 'digital') NOT NULL,
        MODIFY COLUMN line_total DECIMAL(10,2) NOT NULL,
        ADD CONSTRAINT chk_shop_order_items_quantity CHECK (quantity > 0),
        ADD CONSTRAINT chk_shop_order_items_currency CHECK (currency = 'USD'),
        ADD CONSTRAINT chk_shop_order_items_amounts CHECK (
            price >= 0 AND line_total = price * quantity
        );

    SELECT COUNT(*) INTO migrated_order_count FROM shop_orders;
    SELECT COUNT(*) INTO migrated_item_count FROM shop_order_items;

    IF migrated_order_count <> existing_order_count
       OR migrated_item_count <> existing_item_count
       OR EXISTS (
           SELECT 1
           FROM shop_orders
           WHERE currency <> 'USD'
              OR subtotal IS NULL
              OR total <> subtotal + shipping_amount + tax_amount - discount_amount
       )
       OR EXISTS (
           SELECT 1
           FROM shop_order_items
           WHERE product_sku = ''
              OR product_name = ''
              OR currency <> 'USD'
              OR line_total <> price * quantity
       ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Shop F1 post-migration verification failed';
    END IF;
END//

DELIMITER ;

CALL migrate_shop_f1_checkout_shipping_foundation();
DROP PROCEDURE migrate_shop_f1_checkout_shipping_foundation;
