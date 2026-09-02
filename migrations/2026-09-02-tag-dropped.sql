-- Removing a tag has to survive the next import.
--
-- Deleting the row was not enough: findOrCreate would put it straight back
-- the next time an export mentioned it, and the books with it - the same
-- mistake covers made, where a picture thrown out by hand was fetched again
-- that night.
--
-- The row stays and is marked instead. The links in book_tags are left
-- untouched, so restoring is one update and every book has its tag back.
ALTER TABLE tags ADD COLUMN dropped_at DATETIME NULL;
