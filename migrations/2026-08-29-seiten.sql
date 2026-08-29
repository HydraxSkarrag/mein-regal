-- Editable prose pages, so "what is this shelf and whose is it" lives in the
-- database rather than in the source. A second installation then says its own
-- thing without anyone editing a template.

CREATE TABLE IF NOT EXISTS pages (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id   INT UNSIGNED NOT NULL,
    slug       VARCHAR(60)  NOT NULL,
    title      VARCHAR(200) NOT NULL,
    body       TEXT         NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_pages_owner_slug (owner_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
