-- The shelf is sorted by date added by default. Without this index the
-- database sorts every page through a temporary structure; at three thousand
-- books that is barely noticeable, at ten thousand it is.

CREATE INDEX idx_books_owner_created ON books (owner_id, created_at);
