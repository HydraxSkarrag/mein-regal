# Änderungen an einer bestehenden Datenbank

Hier liegt eine datierte `.sql`-Datei je Schemaänderung, die nach einer
Auslieferung nötig wird. Sie werden **nicht automatisch ausgeführt** — es gibt
keinen Runner, weil es auf diesem Hosting keine Shell gibt, ihn aufzurufen.
Sie gehören von Hand in phpMyAdmin, eine nach der anderen, in der Reihenfolge
ihrer Daten.

**Eine neue Installation braucht nichts davon.** Für die ist `schema.sql`
zuständig, und die enthält den vollständigen aktuellen Stand.

Der Ordner ist im Moment leer, und zwar absichtlich: Die elf Dateien, die hier
lagen, beschrieben den Weg zur ersten veröffentlichten Fassung. Jede
Datenbank, die es gibt, wurde aus `schema.sql` angelegt und hatte alles davon
schon. Sie zu behalten hätte nur den Eindruck erweckt, es gäbe etwas
nachzuholen. In der Git-Historie stehen sie weiterhin.
