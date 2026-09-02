-- Where a merged tag went.
--
-- Dropping the source was enough to keep it out of the lists and out of the
-- next import - but it also meant a book that arrives carrying "Comic" ends
-- up with no comic tag at all, rather than with "Comics" as the merge
-- decided. The decision has to be written down for the import to honour it.
ALTER TABLE tags ADD COLUMN merged_into INT UNSIGNED NULL;
