-- Halbe Sterne. Bisher waren nur ganze Zahlen von 1 bis 5 möglich, was für
-- jemanden mit dreihundert Büchern im Jahr eine zu grobe Unterscheidung ist.
--
-- Bestehende Bewertungen bleiben gültig: aus 4 wird 4.0.

ALTER TABLE books MODIFY rating DECIMAL(2,1) NULL;
