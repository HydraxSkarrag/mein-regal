-- English addresses, and the legal texts moved into the database.
--
-- Two changes that have to happen together. The routes were German
-- (/ueber, /impressum, /datenschutz) while the source was English, which is a
-- seam nobody outside a German installation would want to inherit. And the
-- Impressum and privacy policy lived in templates, naming one particular
-- hosting company in the source code - a text that is wrong for everybody
-- else and needs a deployment to correct.
--
-- The about page keeps its text and only changes its slug, which is all the
-- SQL below does. The legal pages need no rows here: /imprint and /privacy
-- will say they are unwritten, and opening the editor offers the old template
-- text already filled in, ready to be read through and saved. That is a
-- better upgrade path than an INSERT, because it puts a person in front of a
-- legal text before it is published.

UPDATE pages SET slug = 'about' WHERE slug = 'ueber';
