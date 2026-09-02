-- Genres and labels are not the same thing.
--
-- The Bookstats export wrote everything into one field: real genres next to
-- age ranges, bindings, shop categories and, from Google, English BISAC
-- headings. Three hundred and eighty of them in one list is not a filter, it
-- is a haystack.
--
-- 'label' is the default on purpose. Whatever nobody has called a genre is
-- not one, so an import can never quietly grow the genre list again.
ALTER TABLE tags ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'label';
CREATE INDEX idx_tags_owner_kind ON tags (owner_id, kind);
