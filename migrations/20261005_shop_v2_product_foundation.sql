-- Shop V2 product foundation for MariaDB 10.4.32.
--
-- This migration is additive. It preserves the legacy active and image_url
-- columns while API readers and writers are moved to the V2 fields.
-- It deliberately fails if any V2 foundation object is already present so an
-- accidental reapplication or partial prior application is inspected first.

DROP PROCEDURE IF EXISTS migrate_shop_v2_product_foundation;

DELIMITER //

CREATE PROCEDURE migrate_shop_v2_product_foundation()
BEGIN
    DECLARE existing_product_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE migrated_product_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE initial_image_count BIGINT UNSIGNED DEFAULT 0;

    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'shop_products'
          AND COLUMN_NAME = 'slug'
    ) OR EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('shop_product_images', 'shop_product_badges')
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Shop V2 foundation already or partially exists; inspect before reapplying';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'shop_products'
          AND ENGINE = 'InnoDB'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Expected InnoDB shop_products table was not found';
    END IF;

    IF EXISTS (
        SELECT 1 FROM shop_products
        WHERE price < 0
           OR active NOT IN (0, 1)
           OR image_url = ''
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Legacy product data failed Shop V2 preflight validation';
    END IF;

    IF EXISTS (
        SELECT proposed_slug
        FROM (
            SELECT CASE sku
                WHEN 'PRINT-GIVE-TITHES' THEN 'give-tithes-print'
                WHEN 'PRINT-BALAAM' THEN 'balaam-art-print'
                WHEN 'PRINT-MOON' THEN 'moon-silhouette-print'
                ELSE CONCAT(
                    LEFT(
                        COALESCE(
                            NULLIF(
                                TRIM(BOTH '-' FROM REGEXP_REPLACE(
                                    REGEXP_REPLACE(LOWER(TRIM(title)), '[^a-z0-9]+', '-'),
                                    '-+',
                                    '-'
                                )),
                                ''
                            ),
                            'product'
                        ),
                        169
                    ),
                    '-',
                    id
                )
            END AS proposed_slug
            FROM shop_products
        ) AS candidates
        GROUP BY proposed_slug
        HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Deterministic Shop V2 slug backfill contains conflicts';
    END IF;

    SELECT COUNT(*) INTO existing_product_count FROM shop_products;

    ALTER TABLE shop_products
        ADD COLUMN slug VARCHAR(180) NULL AFTER sku,
        ADD COLUMN short_description VARCHAR(500) NOT NULL DEFAULT '' AFTER title,
        ADD COLUMN category VARCHAR(80) NULL DEFAULT NULL AFTER description,
        ADD COLUMN product_type ENUM('physical', 'digital') NOT NULL DEFAULT 'physical' AFTER category,
        ADD COLUMN sale_price DECIMAL(10,2) NULL DEFAULT NULL AFTER price,
        ADD COLUMN publication_status ENUM('draft', 'published') NOT NULL DEFAULT 'draft' AFTER stock,
        ADD COLUMN storefront_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER publication_status,
        ADD COLUMN show_when_sold_out TINYINT(1) NOT NULL DEFAULT 1 AFTER storefront_visible,
        ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER show_when_sold_out,
        ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER featured,
        ADD COLUMN purchase_action ENUM('internal', 'external', 'inquiry') NOT NULL DEFAULT 'internal' AFTER sort_order,
        ADD COLUMN external_url VARCHAR(2048) NULL DEFAULT NULL AFTER purchase_action,
        ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL AFTER created_at;

    UPDATE shop_products AS product
    JOIN (
        SELECT id,
               ROW_NUMBER() OVER (ORDER BY created_at DESC, id DESC) AS initial_sort_order
        FROM shop_products
    ) AS ranked ON ranked.id = product.id
    SET product.slug = CASE product.sku
            WHEN 'PRINT-GIVE-TITHES' THEN 'give-tithes-print'
            WHEN 'PRINT-BALAAM' THEN 'balaam-art-print'
            WHEN 'PRINT-MOON' THEN 'moon-silhouette-print'
            ELSE CONCAT(
                LEFT(
                    COALESCE(
                        NULLIF(
                            TRIM(BOTH '-' FROM REGEXP_REPLACE(
                                REGEXP_REPLACE(LOWER(TRIM(product.title)), '[^a-z0-9]+', '-'),
                                '-+',
                                '-'
                            )),
                            ''
                        ),
                        'product'
                    ),
                    169
                ),
                '-',
                product.id
            )
        END,
        product.short_description = LEFT(product.description, 500),
        product.category = CASE
            WHEN product.sku IN ('PRINT-GIVE-TITHES', 'PRINT-BALAAM', 'PRINT-MOON')
                THEN 'Art Prints'
            ELSE NULL
        END,
        product.product_type = 'physical',
        product.sale_price = NULL,
        product.publication_status = IF(product.active = 1, 'published', 'draft'),
        product.storefront_visible = product.active,
        product.show_when_sold_out = 1,
        product.featured = 0,
        product.sort_order = ranked.initial_sort_order,
        product.purchase_action = 'internal',
        product.external_url = NULL,
        product.updated_at = product.created_at;

    ALTER TABLE shop_products
        MODIFY COLUMN slug VARCHAR(180) NOT NULL,
        MODIFY COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        ADD CONSTRAINT uq_shop_products_slug UNIQUE (slug),
        ADD INDEX idx_shop_products_listing (
            publication_status,
            storefront_visible,
            sort_order,
            id
        ),
        ADD CONSTRAINT chk_shop_products_price CHECK (price >= 0),
        ADD CONSTRAINT chk_shop_products_sale CHECK (
            sale_price IS NULL OR (sale_price > 0 AND sale_price < price)
        ),
        ADD CONSTRAINT chk_shop_products_flags CHECK (
            storefront_visible IN (0, 1)
            AND show_when_sold_out IN (0, 1)
            AND featured IN (0, 1)
        ),
        ADD CONSTRAINT chk_shop_products_external CHECK (
            (purchase_action = 'external' AND external_url IS NOT NULL AND CHAR_LENGTH(TRIM(external_url)) > 0)
            OR (purchase_action <> 'external' AND external_url IS NULL)
        );

    CREATE TABLE shop_product_images (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id INT UNSIGNED NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        alt_text VARCHAR(255) NOT NULL DEFAULT '',
        sort_order INT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_product_images_order (product_id, sort_order),
        CONSTRAINT fk_shop_product_images_product
            FOREIGN KEY (product_id) REFERENCES shop_products (id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE shop_product_badges (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id INT UNSIGNED NOT NULL,
        label VARCHAR(32) NOT NULL,
        sort_order INT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_product_badges_label (product_id, label),
        UNIQUE KEY uq_shop_product_badges_order (product_id, sort_order),
        CONSTRAINT fk_shop_product_badges_product
            FOREIGN KEY (product_id) REFERENCES shop_products (id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci;

    INSERT INTO shop_product_images (product_id, image_path, alt_text, sort_order)
    SELECT id, image_url, title, 1
    FROM shop_products
    ORDER BY id;

    SELECT COUNT(*) INTO migrated_product_count FROM shop_products;
    SELECT COUNT(*) INTO initial_image_count FROM shop_product_images;

    IF migrated_product_count <> existing_product_count
       OR initial_image_count <> existing_product_count THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Shop V2 post-migration row-count verification failed';
    END IF;
END//

DELIMITER ;

CALL migrate_shop_v2_product_foundation();
DROP PROCEDURE migrate_shop_v2_product_foundation;
