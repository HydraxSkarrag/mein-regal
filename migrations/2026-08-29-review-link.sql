-- A book may have been reviewed on the blog. Storing the address here lets
-- the shelf link straight at the review instead of leaving the two sites to
-- sit next to each other without ever pointing at one another.
--
-- Nullable and unvalidated on purpose: entered by hand for now, and a later
-- automatic match against the blog can fill the rest in.

ALTER TABLE books ADD COLUMN review_url VARCHAR(500) NULL AFTER notes;
