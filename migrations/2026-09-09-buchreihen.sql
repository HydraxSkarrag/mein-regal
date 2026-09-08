-- Book series: a name, a volume number, and the two things that belong to
-- the series rather than to any book in it.
--
-- Run once in phpMyAdmin, on a database that already exists. A new
-- installation gets all of this from schema.sql and needs nothing here.
--
-- Nothing existing is touched: both columns are NULL, so every book that was
-- on the shelf before this stays exactly as it was and is simply not in a
-- series until somebody says it is.

CREATE TABLE IF NOT EXISTS series (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id INT UNSIGNED NOT NULL,
    name     VARCHAR(190) NOT NULL,
    slug     VARCHAR(190) NOT NULL,
    total    SMALLINT UNSIGNED NULL,
    note     VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_series_owner_slug (owner_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE books
    ADD COLUMN series_id    INT UNSIGNED NULL AFTER audio_minutes,
    ADD COLUMN series_index DECIMAL(5,1) NULL AFTER series_id,
    ADD KEY idx_books_series (series_id, series_index);
