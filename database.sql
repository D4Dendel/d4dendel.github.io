CREATE DATABASE IF NOT EXISTS dyndel_portfolio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dyndel_portfolio;

CREATE TABLE IF NOT EXISTS projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    slug VARCHAR(180) NULL,
    category VARCHAR(80) NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    image_urls TEXT NULL,
    alt_text VARCHAR(255) NULL,
    description TEXT NOT NULL,
    show_home TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    display_size ENUM('standard', 'wide', 'tall', 'featured') NOT NULL DEFAULT 'standard',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE projects ADD COLUMN IF NOT EXISTS slug VARCHAR(180) NULL AFTER title;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS alt_text VARCHAR(255) NULL AFTER image_urls;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER show_home;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS display_size ENUM('standard', 'wide', 'tall', 'featured') NOT NULL DEFAULT 'standard' AFTER sort_order;

CREATE TABLE IF NOT EXISTS site_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    accent_color CHAR(7) NOT NULL DEFAULT '#c86f52',
    page_background CHAR(7) NOT NULL DEFAULT '#fff0e8',
    surface_color CHAR(7) NOT NULL DEFAULT '#fff8f3',
    primary_text CHAR(7) NOT NULL DEFAULT '#3d2925',
    button_radius ENUM('2px', '4px', '8px', '999px') NOT NULL DEFAULT '999px',
    gallery_layout ENUM('uniform', 'masonry', 'editorial', 'clean') NOT NULL DEFAULT 'uniform',
    gallery_edge ENUM('rounded', 'slight', 'square', 'none') NOT NULL DEFAULT 'rounded',
    brand_name VARCHAR(120) NOT NULL DEFAULT 'Dyndel Pino',
    brand_logo_path VARCHAR(255) NULL DEFAULT 'img/icon.png',
    site_icon_path VARCHAR(255) NULL DEFAULT 'img/icon.png',
    brand_name_color CHAR(7) NOT NULL DEFAULT '#f3a889',
    brand_font VARCHAR(40) NOT NULL DEFAULT 'patrick-hand',
    heading_font VARCHAR(40) NOT NULL DEFAULT 'patrick-hand',
    body_font VARCHAR(40) NOT NULL DEFAULT 'nunito',
    ui_font VARCHAR(40) NOT NULL DEFAULT 'same-body',
    pointer_brush_enabled TINYINT(1) NOT NULL DEFAULT 1,
    instagram_url VARCHAR(500) NULL DEFAULT 'https://www.instagram.com/d4dyndel',
    facebook_url VARCHAR(500) NULL DEFAULT 'https://www.facebook.com/d4dyndel',
    twitter_url VARCHAR(500) NULL DEFAULT 'https://twitter.com/d4dyndel',
    youtube_url VARCHAR(500) NULL DEFAULT 'https://www.youtube.com/@d4dyndel',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE site_settings
    ADD COLUMN IF NOT EXISTS brand_name VARCHAR(120) NOT NULL DEFAULT 'Dyndel Pino' AFTER gallery_edge,
    ADD COLUMN IF NOT EXISTS brand_logo_path VARCHAR(255) NULL DEFAULT 'img/icon.png' AFTER brand_name,
    ADD COLUMN IF NOT EXISTS site_icon_path VARCHAR(255) NULL DEFAULT 'img/icon.png' AFTER brand_logo_path,
    ADD COLUMN IF NOT EXISTS brand_name_color CHAR(7) NOT NULL DEFAULT '#f3a889' AFTER site_icon_path,
    ADD COLUMN IF NOT EXISTS brand_font VARCHAR(40) NOT NULL DEFAULT 'patrick-hand' AFTER brand_name_color,
    ADD COLUMN IF NOT EXISTS heading_font VARCHAR(40) NOT NULL DEFAULT 'patrick-hand' AFTER brand_font,
    ADD COLUMN IF NOT EXISTS body_font VARCHAR(40) NOT NULL DEFAULT 'nunito' AFTER heading_font,
    ADD COLUMN IF NOT EXISTS ui_font VARCHAR(40) NOT NULL DEFAULT 'same-body' AFTER body_font,
    ADD COLUMN IF NOT EXISTS pointer_brush_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER ui_font,
    ADD COLUMN IF NOT EXISTS instagram_url VARCHAR(500) NULL DEFAULT 'https://www.instagram.com/d4dyndel' AFTER pointer_brush_enabled,
    ADD COLUMN IF NOT EXISTS facebook_url VARCHAR(500) NULL DEFAULT 'https://www.facebook.com/d4dyndel' AFTER instagram_url,
    ADD COLUMN IF NOT EXISTS twitter_url VARCHAR(500) NULL DEFAULT 'https://twitter.com/d4dyndel' AFTER facebook_url,
    ADD COLUMN IF NOT EXISTS youtube_url VARCHAR(500) NULL DEFAULT 'https://www.youtube.com/@d4dyndel' AFTER twitter_url;

INSERT IGNORE INTO site_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS content_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(180) NOT NULL UNIQUE,
    title VARCHAR(180) NOT NULL,
    cover_image VARCHAR(255) NULL,
    type ENUM('blog', 'news', 'update', 'announcement') NOT NULL DEFAULT 'blog',
    excerpt VARCHAR(500) NOT NULL DEFAULT '',
    body MEDIUMTEXT NOT NULL,
    publish_date DATE NOT NULL,
    status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    featured TINYINT(1) NOT NULL DEFAULT 0,
    show_home TINYINT(1) NOT NULL DEFAULT 0,
    show_card TINYINT(1) NOT NULL DEFAULT 1,
    card_size ENUM('standard', 'wide', 'featured') NOT NULL DEFAULT 'standard',
    seo_title VARCHAR(180) NULL,
    meta_description VARCHAR(320) NULL,
    og_title VARCHAR(180) NULL,
    og_description VARCHAR(320) NULL,
    og_image VARCHAR(255) NULL,
    noindex TINYINT(1) NOT NULL DEFAULT 0,
    cover_alt VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_content_public (status, publish_date, show_home, show_card)
) ENGINE=InnoDB;

ALTER TABLE content_entries
    ADD COLUMN IF NOT EXISTS seo_title VARCHAR(180) NULL,
    ADD COLUMN IF NOT EXISTS meta_description VARCHAR(320) NULL,
    ADD COLUMN IF NOT EXISTS og_title VARCHAR(180) NULL,
    ADD COLUMN IF NOT EXISTS og_description VARCHAR(320) NULL,
    ADD COLUMN IF NOT EXISTS og_image VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS noindex TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS cover_alt VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS content_blocks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    content_entry_id INT UNSIGNED NOT NULL,
    block_order INT UNSIGNED NOT NULL,
    block_type ENUM('paragraph', 'heading', 'image', 'image_caption', 'video', 'quote', 'divider', 'gallery') NOT NULL,
    payload JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_content_blocks_order (content_entry_id, block_order),
    CONSTRAINT fk_content_blocks_entry
        FOREIGN KEY (content_entry_id) REFERENCES content_entries (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(80) NOT NULL UNIQUE,
    title VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS shop_shipping_zones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    is_rest_of_world TINYINT(1) NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_shop_shipping_zones_listing (enabled, sort_order, id),
    CONSTRAINT chk_shop_shipping_zones_flags CHECK (
        is_rest_of_world IN (0, 1) AND enabled IN (0, 1)
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_shipping_zone_countries (
    zone_id INT UNSIGNED NOT NULL,
    country_code CHAR(2) NOT NULL,
    PRIMARY KEY (zone_id, country_code),
    UNIQUE KEY uq_shop_shipping_country (country_code),
    CONSTRAINT chk_shop_shipping_country_code CHECK (
        country_code REGEXP '^[A-Z]{2}$'
    ),
    CONSTRAINT fk_shop_shipping_country_zone
        FOREIGN KEY (zone_id) REFERENCES shop_shipping_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_shipping_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    UNIQUE KEY uq_shop_shipping_method_code (zone_id, code),
    KEY idx_shop_shipping_methods_listing (zone_id, enabled, sort_order, id),
    CONSTRAINT fk_shop_shipping_method_zone
        FOREIGN KEY (zone_id) REFERENCES shop_shipping_zones(id) ON DELETE CASCADE,
    CONSTRAINT chk_shop_shipping_method_price CHECK (price >= 0),
    CONSTRAINT chk_shop_shipping_method_currency CHECK (currency = 'USD'),
    CONSTRAINT chk_shop_shipping_method_enabled CHECK (enabled IN (0, 1)),
    CONSTRAINT chk_shop_shipping_method_estimate CHECK (
        (estimated_delivery_min IS NULL AND estimated_delivery_max IS NULL AND estimated_delivery_unit IS NULL)
        OR (estimated_delivery_min IS NOT NULL AND estimated_delivery_max IS NOT NULL
            AND estimated_delivery_unit IS NOT NULL AND estimated_delivery_min > 0
            AND estimated_delivery_max >= estimated_delivery_min)
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(160) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    customer_phone VARCHAR(40) NULL,
    customer_address TEXT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    shipping_required TINYINT(1) NOT NULL DEFAULT 1,
    shipping_address_line1 VARCHAR(255) NULL,
    shipping_address_line2 VARCHAR(255) NULL,
    shipping_city VARCHAR(120) NULL,
    shipping_region VARCHAR(120) NULL,
    shipping_postal_code VARCHAR(32) NULL,
    shipping_country_code CHAR(2) NULL,
    shipping_method_id INT UNSIGNED NULL,
    shipping_method_name VARCHAR(120) NULL,
    shipping_estimate_min SMALLINT UNSIGNED NULL,
    shipping_estimate_max SMALLINT UNSIGNED NULL,
    shipping_estimate_unit ENUM('business_days', 'calendar_days') NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    order_origin ENUM('legacy', 'checkout_v2') NOT NULL DEFAULT 'legacy',
    payment_status ENUM('unpaid', 'pending', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'unpaid',
    checkout_attempt_token CHAR(64) NULL,
    checkout_payload_hash CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_shop_orders_lifecycle (status, payment_status, created_at, id),
    KEY idx_shop_orders_customer_email (customer_email, created_at, id),
    UNIQUE KEY uq_shop_orders_checkout_attempt (checkout_attempt_token),
    CONSTRAINT fk_shop_orders_shipping_method
        FOREIGN KEY (shipping_method_id) REFERENCES shop_shipping_methods(id) ON DELETE SET NULL,
    CONSTRAINT chk_shop_orders_currency CHECK (currency = 'USD'),
    CONSTRAINT chk_shop_orders_shipping_required CHECK (shipping_required IN (0, 1)),
    CONSTRAINT chk_shop_orders_status CHECK (
        status IN ('pending', 'confirmed', 'processing', 'shipped', 'completed', 'cancelled')
    ),
    CONSTRAINT chk_shop_orders_amounts CHECK (
        subtotal >= 0 AND shipping_amount >= 0 AND tax_amount >= 0 AND discount_amount >= 0
        AND discount_amount <= subtotal + shipping_amount + tax_amount
        AND total = subtotal + shipping_amount + tax_amount - discount_amount
    ),
    CONSTRAINT chk_shop_orders_estimate CHECK (
        (shipping_estimate_min IS NULL AND shipping_estimate_max IS NULL AND shipping_estimate_unit IS NULL)
        OR (shipping_estimate_min IS NOT NULL AND shipping_estimate_max IS NOT NULL
            AND shipping_estimate_unit IS NOT NULL AND shipping_estimate_min > 0
            AND shipping_estimate_max >= shipping_estimate_min)
    ),
    CONSTRAINT chk_shop_orders_checkout_shipping CHECK (
        order_origin = 'legacy'
        OR (shipping_required = 0 AND shipping_address_line1 IS NULL
            AND shipping_address_line2 IS NULL AND shipping_city IS NULL
            AND shipping_region IS NULL AND shipping_postal_code IS NULL
            AND shipping_country_code IS NULL AND shipping_method_name IS NULL
            AND shipping_amount = 0)
        OR (shipping_required = 1 AND shipping_address_line1 IS NOT NULL
            AND shipping_city IS NOT NULL AND shipping_postal_code IS NOT NULL
            AND shipping_country_code REGEXP '^[A-Z]{2}$'
            AND shipping_method_name IS NOT NULL)
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    product_sku VARCHAR(80) NOT NULL,
    product_name VARCHAR(160) NOT NULL,
    product_type ENUM('physical', 'digital') NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    quantity INT UNSIGNED NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES shop_products(id),
    CONSTRAINT chk_shop_order_items_quantity CHECK (quantity > 0),
    CONSTRAINT chk_shop_order_items_currency CHECK (currency = 'USD'),
    CONSTRAINT chk_shop_order_items_amounts CHECK (price >= 0 AND line_total = price * quantity)
) ENGINE=InnoDB;

INSERT IGNORE INTO shop_products (sku, title, description, image_url, price, stock) VALUES
('PRINT-GIVE-TITHES', 'Give Tithes Print', 'A signed art print from the illustration collection.', 'img/projectfolder/illustration/3fb32d2e14559607e08f0fbcc863e312.jpg', 18.00, 12),
('PRINT-BALAAM', 'Balaam Art Print', 'A vivid standalone illustration printed on archival paper.', 'img/illustration/balaam.jpg', 22.00, 8),
('PRINT-MOON', 'Moon Silhouette Print', 'A quiet graphic design print for a small wall or studio corner.', 'img/graphic_design/Moon%20Silhoutte.jpg', 16.00, 15);

-- Initial portfolio content. New projects should be added from admin.html.
INSERT INTO projects (title, category, image_url, image_urls, description, show_home) VALUES
('Story panels', 'Illustration', 'img/illustration/project1/1.jpg', '["img/illustration/project1/1.jpg","img/illustration/project1/2.jpg","img/illustration/project1/3.jpg","img/illustration/project1/4.jpg"]', 'A four-panel story illustration series.', 1),
('From sketch to final', 'Illustration', 'img/illustration/project2/1Sketch.png', '["img/illustration/project2/1Sketch.png","img/illustration/project2/2LineArt.png","img/illustration/project2/3Color.png","img/illustration/project2/4Background.png","img/illustration/project2/5Final.png"]', 'An illustration process from sketch through final artwork.', 1),
('Author portraits', 'Illustration', 'img/illustration/project3/author%201.jpg', '["img/illustration/project3/author%201.jpg","img/illustration/project3/author%202.jpg","img/illustration/project3/author%203.jpg","img/illustration/project3/author%204.jpg"]', 'A character portrait illustration series.', 1),
('December studies', 'Illustration', 'img/illustration/project4/December_KAI-week3-1.jpg', '["img/illustration/project4/December_KAI-week3-1.jpg","img/illustration/project4/December_KAI-week3-2.jpg","img/illustration/project4/December_KAI-week3-3.jpg"]', 'A weekly illustration study series.', 1),
('Balaam', 'Illustration', 'img/illustration/balaam.jpg', '["img/illustration/balaam.jpg"]', 'An individual illustration.', 1),
('Independent work', 'Illustration', 'img/illustration/2%20(1).jpg', '["img/illustration/2%20(1).jpg"]', 'An independent illustration artwork.', 0),
('Sancho', 'Portraits', 'img/portraits/chibi_3rd_art_portrait_sancho_boy_cute_by_d4dendel_de1htjg-pre.jpg', '["img/portraits/chibi_3rd_art_portrait_sancho_boy_cute_by_d4dendel_de1htjg-pre.jpg"]', 'A character portrait.', 1),
('Purple Glasses', 'Portraits', 'img/portraits/cute_girl_in_purple_glasses_by_d4dendel_de1hulo-pre.jpg', '["img/portraits/cute_girl_in_purple_glasses_by_d4dendel_de1hulo-pre.jpg"]', 'A portrait study.', 1),
('Fresh Air', 'Portraits', 'img/portraits/fresh_air_by_d4dendel_de1xc6c-pre.jpg', '["img/portraits/fresh_air_by_d4dendel_de1xc6c-pre.jpg"]', 'A personal portrait artwork.', 1),
('Hoodies', 'Portraits', 'img/portraits/hoodies_by_d4dendel_de0xmmr-pre.jpg', '["img/portraits/hoodies_by_d4dendel_de0xmmr-pre.jpg"]', 'A character portrait study.', 0),
('Jessa Is an Angel', 'Portraits', 'img/portraits/jessa_is_an_angel_by_d4dendel_de1x93k-pre.jpg', '["img/portraits/jessa_is_an_angel_by_d4dendel_de1x93k-pre.jpg"]', 'A portrait artwork.', 1),
('Miss Leigh', 'Portraits', 'img/portraits/miss_leigh_in_red_dress_by_d4dendel_de1hrcz-pre.jpg', '["img/portraits/miss_leigh_in_red_dress_by_d4dendel_de1hrcz-pre.jpg"]', 'A portrait study.', 0),
('First Chibi Commission', 'Portraits', 'img/portraits/my_first_time_doing_whole_body_chibi_art_commision_by_d4dendel_de1hu2o-pre.jpg', '["img/portraits/my_first_time_doing_whole_body_chibi_art_commision_by_d4dendel_de1hu2o-pre.jpg"]', 'A whole-body chibi commission.', 0),
('Zelrud', 'Portraits', 'img/portraits/zelrud_chibi_commissions_by_d4dendel_de1x8u0-pre.jpg', '["img/portraits/zelrud_chibi_commissions_by_d4dendel_de1x8u0-pre.jpg"]', 'A chibi commission portrait.', 0),
('Zoe', 'Portraits', 'img/portraits/zoe_by_d4dendel_de1x8hl-pre.jpg', '["img/portraits/zoe_by_d4dendel_de1x8hl-pre.jpg"]', 'A portrait artwork.', 0),
('Bakeshoppe', 'Logos', 'img/logo/Bakeshoppe%20Logo%20and%20Mascot1.jpg', '["img/logo/Bakeshoppe%20Logo%20and%20Mascot1.jpg","img/logo/Bakeshoppe%20Logo%20and%20Mascot2.jpg"]', 'A food brand logo and mascot identity.', 1),
('Giftshop', 'Logos', 'img/logo/Giftshop%20Logo1.jpg', '["img/logo/Giftshop%20Logo1.jpg","img/logo/Giftshop%20Logo2.jpg","img/logo/Giftshop%20Logo3.jpg"]', 'A retail logo identity.', 1),
('Graphic Studio', 'Logos', 'img/logo/Graphic%20Studio%20Logo.jpg', '["img/logo/Graphic%20Studio%20Logo.jpg","img/logo/Graphic%20Studio%20Logo%202.jpg"]', 'A creative studio identity.', 1),
('Institution', 'Logos', 'img/logo/Institution%20LOGO.jpg', '["img/logo/Institution%20LOGO.jpg"]', 'An institutional logo identity.', 0),
('Pinyamis', 'Logos', 'img/logo/Pinyamis_food_product.jpg', '["img/logo/Pinyamis_food_product.jpg","img/logo/Pinyamis_food_product2.jpg","img/logo/Pinyamis_food_product3.jpg"]', 'A food product identity.', 1),
('Pathfinder', 'Graphic Design', 'img/graphic_design/pathfinder/CZ2%20Junior%20Youth%20Camp%202026%20Main%20Banner.png', '["img/graphic_design/pathfinder/CZ2%20Junior%20Youth%20Camp%202026%20Main%20Banner.png"]', 'A campaign identity system.', 1),
('Arise', 'Graphic Design', 'img/graphic_design/arise/Event%20Main.jpg', '["img/graphic_design/arise/Event%20Main.jpg"]', 'An event campaign identity.', 1),
('Invitation', 'Graphic Design', 'img/graphic_design/Invitation/Invitation.jpg', '["img/graphic_design/Invitation/Invitation.jpg","img/graphic_design/Invitation/Invitation2.jpg"]', 'A print and event stationery system.', 1),
('Moon Silhouette', 'Graphic Design', 'img/graphic_design/Moon%20Silhoutte.jpg', '["img/graphic_design/Moon%20Silhoutte.jpg"]', 'An individual graphic design piece.', 0);

UPDATE projects SET slug = CONCAT('project-', id) WHERE slug IS NULL OR slug = '';
UPDATE projects SET alt_text = title WHERE alt_text IS NULL OR alt_text = '';
UPDATE projects SET sort_order = id WHERE sort_order = 0;

-- The admin account is configured in api/config.php with a password hash.
-- Do not store plaintext passwords in this database.

-- Shop G1 provider-neutral Payment Core.
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

-- Shop G2 safe provider metadata.
CREATE TABLE IF NOT EXISTS shop_payment_providers (
    provider VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    mode ENUM('test', 'live') NOT NULL DEFAULT 'test',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_shop_payment_providers_key CHECK (provider IN ('paypal','stripe')),
    CONSTRAINT chk_shop_payment_providers_enabled CHECK (enabled IN (0,1))
) ENGINE=InnoDB;
INSERT IGNORE INTO shop_payment_providers (provider) VALUES ('paypal'), ('stripe');
