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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE projects ADD COLUMN IF NOT EXISTS slug VARCHAR(180) NULL AFTER title;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS alt_text VARCHAR(255) NULL AFTER image_urls;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER show_home;

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

CREATE TABLE IF NOT EXISTS shop_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(160) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    customer_address TEXT NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS shop_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES shop_products(id)
);

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
