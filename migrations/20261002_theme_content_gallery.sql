ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS display_size ENUM('standard', 'wide', 'tall', 'featured') NOT NULL DEFAULT 'standard' AFTER sort_order;

CREATE TABLE IF NOT EXISTS site_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    accent_color CHAR(7) NOT NULL DEFAULT '#c86f52',
    page_background CHAR(7) NOT NULL DEFAULT '#fff0e8',
    surface_color CHAR(7) NOT NULL DEFAULT '#fff8f3',
    primary_text CHAR(7) NOT NULL DEFAULT '#3d2925',
    button_radius ENUM('2px', '4px', '8px', '999px') NOT NULL DEFAULT '999px',
    gallery_layout ENUM('uniform', 'masonry', 'editorial', 'clean') NOT NULL DEFAULT 'uniform',
    gallery_edge ENUM('rounded', 'slight', 'square', 'none') NOT NULL DEFAULT 'rounded',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_content_public (status, publish_date, show_home, show_card)
);