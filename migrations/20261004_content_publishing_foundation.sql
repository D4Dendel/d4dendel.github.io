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
