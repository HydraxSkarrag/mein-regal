-- Das Regal wird standardmäßig nach Erfassungsdatum sortiert. Ohne diesen
-- Index sortiert die Datenbank jede Seite über eine temporäre Struktur; bei
-- dreitausend Büchern fällt das kaum auf, bei zehntausend schon.

CREATE INDEX idx_books_owner_created ON books (owner_id, created_at);
