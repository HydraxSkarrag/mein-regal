-- Half stars. Ratings were whole numbers from 1 to 5, which is too coarse a
-- distinction for someone reading three hundred books a year.
--
-- Existing ratings stay valid: a 4 becomes 4.0.

ALTER TABLE books MODIFY rating DECIMAL(2,1) NULL;
