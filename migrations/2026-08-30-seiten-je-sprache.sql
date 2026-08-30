-- Die Über-Seite braucht je Sprache einen eigenen Text. Bisher gab es einen
-- für beide, was auf Englisch dann einfach Deutsch war.
--
-- Der bestehende Text wird der deutschen Fassung zugeordnet.

ALTER TABLE pages ADD COLUMN locale VARCHAR(5) NOT NULL DEFAULT 'de' AFTER slug;

ALTER TABLE pages DROP INDEX uniq_pages_owner_slug;
ALTER TABLE pages ADD UNIQUE KEY uniq_pages_owner_slug_locale (owner_id, slug, locale);
