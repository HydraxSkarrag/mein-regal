# Changing a database that already exists

One dated `.sql` file per schema change that becomes necessary after a
release. They are **not run automatically** — there is no runner, because on
this kind of hosting there is no shell to call one from. They go into
phpMyAdmin by hand, one at a time, in date order.

**A new installation needs none of them.** `schema.sql` is what builds one,
and it holds the complete current state.

The folder is empty at the moment, and deliberately so. The eleven files that
used to be here described the road to the first published version. Every
database in existence was created from `schema.sql` and already had all of it,
so keeping them would only have suggested there was something to catch up on.
They are still in the git history.
