-- The about page needs its own text per language. There was one text for
-- both, which meant the English page was simply German.
--
-- The existing text becomes the German version.

ALTER TABLE pages ADD COLUMN locale VARCHAR(5) NOT NULL DEFAULT 'de' AFTER slug;

ALTER TABLE pages DROP INDEX uniq_pages_owner_slug;
ALTER TABLE pages ADD UNIQUE KEY uniq_pages_owner_slug_locale (owner_id, slug, locale);
