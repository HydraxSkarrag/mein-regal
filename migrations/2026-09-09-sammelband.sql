-- One book, several volumes: the Sammelband.
--
-- A volume number says which volume a book is. It cannot say that a book is
-- volumes 5 and 6 bound together, and entering only the 5 makes the series
-- page claim, for good, that volume 6 is missing while it stands on the
-- shelf. The gap list is the reason the series page exists, so a gap list
-- that lies is worse than none.
--
-- Run once in phpMyAdmin. A new installation gets this from schema.sql.
--
-- NULL for every book that is a single volume, which is all of them today:
-- nothing existing changes, and the column is only read where it is set.

ALTER TABLE books
    ADD COLUMN series_index_end DECIMAL(5,1) NULL AFTER series_index;
