-- "unknown" and NULL both meant "binding not recorded". Two spellings of the
-- same thing put the word twice in the editor's dropdown and split the same
-- books across two buckets in the statistics. NULL is the one that stays.

UPDATE books SET binding = NULL WHERE binding = 'unknown';
