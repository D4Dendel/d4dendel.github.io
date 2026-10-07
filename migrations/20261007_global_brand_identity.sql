USE dyndel_portfolio;

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
