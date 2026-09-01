-- A cover thrown out has to stay thrown out.
--
-- Removing a cover deleted the row, which made the book cover-less - and the
-- next run of the nightly job fetched the same wrong image from the same
-- source and put it back. Sorting bad covers out by hand was therefore only
-- ever valid until the following night.
--
-- The row now survives the removal, carrying the date it was rejected. The
-- book still counts as having no cover, so another source may still be tried;
-- it is the source that is blocked, not the book.

ALTER TABLE covers ADD COLUMN rejected_at DATETIME NULL;
