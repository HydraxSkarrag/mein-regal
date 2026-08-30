-- Bücherregal - database schema for MySQL / MariaDB.
-- Run once in the all-inkl KAS via phpMyAdmin. This file is NEVER deployed.
-- For an EXISTING database run the dated files in migrations/ instead.
--
-- Conventions:
--   * Every table carrying user data has owner_id. It is always filtered on,
--     even while there is only one owner. Retrofitting that later would mean
--     touching every query in the application - and missing exactly one.
--   * Controlled vocabularies (reading status, binding, ...) are stored as
--     stable English keys and translated in the interface, never the reverse.
--   * "0" from the Bookstats export means "not set" and becomes NULL here.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- accounts --

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(120) NOT NULL,
    locale        VARCHAR(5)   NOT NULL DEFAULT 'de',
    is_admin      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Stay signed in". The validator is stored only as a hash, so a stolen
-- database does not hand out live sessions. Split into selector/validator so
-- the lookup can use an index without comparing secrets in SQL.
CREATE TABLE IF NOT EXISTS auth_tokens (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    selector       CHAR(32)     NOT NULL,
    validator_hash CHAR(64)     NOT NULL,
    expires_at     DATETIME     NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_auth_selector (selector),
    KEY idx_auth_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting. Counted per account AND per IP so neither a single account
-- nor a single source can be hammered.
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier   VARCHAR(190) NOT NULL,
    ip           VARBINARY(16) NULL,
    succeeded    TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempt_identifier (identifier, attempted_at),
    KEY idx_attempt_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- books --

CREATE TABLE IF NOT EXISTS books (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id INT UNSIGNED NOT NULL,

    isbn13 CHAR(13) NULL,
    isbn10 VARCHAR(10) NULL,

    title      VARCHAR(500) NOT NULL,
    subtitle   VARCHAR(500) NULL,
    slug       VARCHAR(200) NOT NULL,
    publisher  VARCHAR(255) NULL,
    published_year SMALLINT UNSIGNED NULL,
    page_count     SMALLINT UNSIGNED NULL,
    -- ISO 639-2/B as delivered by the DNB ("ger", "eng").
    language   CHAR(3) NULL,

    -- hardcover | paperback | ebook | audiobook | unknown
    binding VARCHAR(20) NULL,

    price          DECIMAL(8,2) NULL,
    price_currency CHAR(3)      NOT NULL DEFAULT 'EUR',

    -- purchase | review_copy | gift | prize | loan | swap
    acquisition_type VARCHAR(20) NULL,
    acquired_at      DATE        NULL,
    -- Large parts of "Erhalten am" in the export are not real acquisition
    -- dates but the days the shelf was bulk-entered into Bookstats. Flagged
    -- here so statistics can exclude them instead of inventing a history.
    acquired_at_is_bulk TINYINT(1) NOT NULL DEFAULT 0,

    -- read | unread | abandoned | reading
    reading_status VARCHAR(20) NOT NULL DEFAULT 'unread',
    started_at     DATE         NULL,
    finished_at    DATE         NULL,
    rating         TINYINT UNSIGNED NULL,

    notes         TEXT NULL,
    audio_minutes SMALLINT UNSIGNED NULL,

    -- Address of the review on the blog, where there is one. Filled in by
    -- hand for now; a later automatic match can complete it.
    review_url VARCHAR(500) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_books_owner (owner_id),
    KEY idx_books_owner_isbn (owner_id, isbn13),
    KEY idx_books_owner_status (owner_id, reading_status),
    KEY idx_books_owner_year (owner_id, published_year),
    -- The shelf's default order. Without it every page is sorted through a
    -- temporary structure.
    KEY idx_books_owner_created (owner_id, created_at),
    KEY idx_books_slug (slug),
    KEY idx_books_title (title(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- authors: "Flechsig, Dorothea" and "Dorothea Flechsig" are the same person.
-- match_key holds an accent- and order-insensitive form so those collapse.
CREATE TABLE IF NOT EXISTS authors (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id  INT UNSIGNED NOT NULL,
    name      VARCHAR(255) NOT NULL,
    sort_name VARCHAR(255) NOT NULL,
    match_key VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_authors_owner_key (owner_id, match_key),
    KEY idx_authors_sort (owner_id, sort_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS book_authors (
    book_id   INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NOT NULL,
    -- author | illustrator | translator | editor | narrator
    role     VARCHAR(20)      NOT NULL DEFAULT 'author',
    position TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (book_id, author_id, role),
    KEY idx_ba_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The export carries 385 distinct genre strings, part real genres and part
-- shop categories. They are kept as free tags rather than forced into a
-- vocabulary that would lose information.
CREATE TABLE IF NOT EXISTS tags (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id INT UNSIGNED NOT NULL,
    name     VARCHAR(190) NOT NULL,
    slug     VARCHAR(190) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tags_owner_slug (owner_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS book_tags (
    book_id INT UNSIGNED NOT NULL,
    tag_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (book_id, tag_id),
    KEY idx_bt_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- covers: own photos are stored as files, third-party images are LINKED and
-- never copied. is_public follows from the source and decides whether a
-- logged-out visitor sees the image - hotlinking a third party would leak the
-- visitor's IP and cost the cookie-banner-free setup.
CREATE TABLE IF NOT EXISTS covers (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    book_id INT UNSIGNED NOT NULL,
    -- own | vlbtix | google | openlibrary
    source       VARCHAR(20)  NOT NULL,
    path         VARCHAR(255) NULL,
    external_url VARCHAR(500) NULL,
    attribution  VARCHAR(255) NULL,
    width        SMALLINT UNSIGNED NULL,
    height       SMALLINT UNSIGNED NULL,
    is_public    TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_covers_book_source (book_id, source),
    KEY idx_covers_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Raw API responses, so the same ISBN is never fetched twice.
CREATE TABLE IF NOT EXISTS isbn_cache (
    isbn        VARCHAR(13) NOT NULL,
    source      VARCHAR(20) NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    found       TINYINT(1)  NOT NULL DEFAULT 0,
    payload     MEDIUMTEXT  NULL,
    fetched_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (isbn, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per source row of an import run, so a run can be judged and redone.
CREATE TABLE IF NOT EXISTS import_log (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id     CHAR(32)     NOT NULL,
    source_row INT UNSIGNED NOT NULL,
    isbn       VARCHAR(13)  NULL,
    title      VARCHAR(500) NULL,
    -- imported | skipped | duplicate | warning | error
    status     VARCHAR(20)  NOT NULL,
    message    VARCHAR(500) NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_import_run (run_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Throttling for the ISBN lookup endpoint, so nobody uses this server as a
-- free DNB proxy on our IP and our Google quota.
CREATE TABLE IF NOT EXISTS lookup_hits (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip         VARBINARY(16) NULL,
    hit_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lookup_ip (ip, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
