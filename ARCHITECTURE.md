# How this is built

A tour of the source: what lives where, why it lives there, and the order to
read it in. Setting the shelf up is [README.md](README.md); this is the map for
anybody who wants to change something.

Line counts are counted, not estimated, and are here because the proportions
say something: the tests are the largest single thing in the project.

---

## The shape, in one sentence

**One PHP file sits in the document root. Everything else lives above it.**

The subdomain's document root points at `public/`, and the only PHP file there
is `index.php`. Application code, templates, translations, the schema and the
credentials are one level up and are not reachable over HTTP at all — not
blocked, absent. The `.htaccess` beside it is the second line of defence, not
the first: forget a rule or disable a module and no source is served anyway.

Everything else follows from the hosting. There is no shell on the server, so
there is no Composer, no build step and no `bin/console`. What remains is small
enough not to need a framework: routing is about fifty addresses, the database
is PDO, HTTP is curl, templates are PHP with an escaping helper. The one
JavaScript library that genuinely earns its place — the barcode decoder — ships
in `public/js/`.

---

## The map

| | | |
|---|---|---|
| **`app/Core/`** | Router, request, response, session, CSRF, CSP, translation, escaping, ISBN, text. Everything every route needs, once, in one place. | 29 files · 3,980 lines |
| **`app/Controller/`** | Ten of them, one per area: shelf, book, scanning, tags, statistics, pages, sign-in, setup, data, cron. | 10 · 3,869 |
| **`app/Repository/`** | All database access. No SQL exists outside this directory — the hardest rule in the project. | 7 · 2,149 |
| **`app/Lookup/`** | The outside world: DNB, MVB, Google Books, Open Library, the cover finder, and the chain that asks them in turn. | 15 · 2,674 |
| **`app/templates/`** | PHP templates by area. Loops and conditionals, nothing else. | 30 · 2,934 |
| **`app/Import/` `app/Export/`** | The one-off move in from Bookstats, and the backups back out. | 5 · 967 |
| **`app/Content/`** | Rules about content with no HTML attached: matching reviews, recognising classification notations, the default pages. | 3 · 739 |
| **`app/Http/`** | One file. `Application.php` builds everything and holds it. | 1 · 306 |
| **`app/lang/`** | `de.php` is the source and `en.php` follows it. Not the other way round. | 2 · 1,110 |
| **`public/index.php`** | The only entry point: error display off, bootstrap, every address, `$app->run()`. | 198 |
| **`public/css/` `public/js/`** | One stylesheet plus themes, five own scripts plus the decoder. | 11 · 3,722 |
| **`tests/`** | 66 files, 1,400+ assertions, no PHPUnit. | 66 · 7,575 |
| **`bin/`** | Nine command-line scripts: set up, import, enrich, back up, check. | 9 · 2,118 |
| **`schema.sql` `migrations/`** | Fourteen tables. A new installation takes the schema, an existing one the dated files — by hand in phpMyAdmin, because there is no shell. | |

`public/covers/` and `public/assets/brand/` are deliberately absent from Git and
from the deployment. They exist only on the server, and no deploy may carry one
installation's covers or logo into another's web space.

---

## The path of a request

```
                    ┌──── response, with this request's CSP nonce ────┐
                    ▼                                                 │
  Browser ──GET──▶ index.php ──builds──▶ Application ──▶ Router ──▶ Controller
                                                                      │
                                            ┌─────────────────────────┼───────────────┐
                                            ▼                         ▼               ▼
                                       Repository                  Lookup           View
                                            │ SQL                     │ HTTPS         │ PHP
                                            ▼                         ▼               ▼
                                          MySQL               DNB · MVB · …      templates/
```

Those three are the only doors out: **only `Repository` talks to the database,
only `Lookup` talks to anybody else's server, only `View` touches templates.**

1. **`public/index.php`** switches `display_errors` off before anything can go
   wrong — a PHP warning printed into the page leaks file paths and, at the
   wrong moment, fragments of a query. Then `app/bootstrap.php`: thirty lines,
   an autoloader, two constants and the helper functions.

2. **`Request::fromGlobals()`** reads `$_GET`, `$_POST`, `$_SERVER` and
   `$_FILES` into one immutable object. Past this line nothing touches a
   superglobal again.

3. **`new Application(Config::load(), $request)`** builds everything: database,
   session, CSRF, CSP, auth, translator, formatter, view, router, seven
   repositories and the lookup chain. There is no container — with this many
   pieces a list of assignments is easier to follow than a resolution graph,
   and it fails at startup rather than mid-request. If it does fail, the page
   is plain text and the full reason goes to `boot-error.log` next to
   `config.php`, because a host without a shell has no log viewer to look in.

4. **The routes are registered** in `index.php`, public ones first, then the
   signed-in ones, then cron and API. The order is part of the logic:
   `/book/new` is registered before `/book/{slug}`, or the application would
   look for a book called "new".

5. **A controller runs.** `requireSignIn()` where needed, then the CSRF token,
   then `Input::` for everything typed. Data comes from a repository, the page
   from the view. A controller decides; it does not calculate and does not
   query.

6. **The response goes out with the policy attached**, in one line:

   ```php
   $response->send($this->csp->header());
   ```

   The policy carries this request's nonce, which is why it cannot come from
   the `.htaccess`, and it is attached here rather than in each controller, so
   a new route cannot be the one that forgets it. Anything thrown on the way
   is recorded by `ErrorLog` and answered with a 500 page — never a stack
   trace.

---

## The layers, and what they may not do

The directories are boundaries, not taste. The full list of rules is in the
README under *What to keep in mind when changing things*; these four are the
ones that shape the structure itself.

**No SQL outside `app/Repository/`.** Every query filters on `owner_id`, even
while there is only one collection. Retrofitting that would mean touching every
query in the application and missing exactly one.

**Only `app/Lookup/` reaches out.** Four sources behind one interface, asked in
an order that depends on the ISBN's language area. A source that *cannot*
answer is not a source that says *no*: the difference is carried all the way to
the message on screen, because recording the second as the first locks a book
out of the nightly job for a month.

**Templates hold loops and conditionals.** No logic, no `style` attribute (the
CSP forbids inline style, and `'unsafe-inline'` for one bar would mean it for
the whole site), and everything goes through `e()`. Text the owner wrote is
never stored as HTML; `App\Core\Markup` escapes first and writes tags second.

**Nothing loads from anybody else's server.** No CDN, no web fonts, no
analytics — and covers are downloaded, never hotlinked. Two things hang on
this: the strict CSP, and the fact that the site needs no cookie banner. The
first external resource costs both.

---

## Where the data lives

Fourteen tables. Two conventions run through all of them: every table holding
user data has `owner_id`, and controlled vocabularies (reading status, binding,
acquisition type) are stored as stable English keys and translated in the
interface — never the reverse.

| Table | For |
|---|---|
| `books` | The core: title, ISBN, publisher, binding, price, reading status, rating, and `series_id` with `series_index`. |
| `authors`, `book_authors` | Normalised, with a role. A translator is not an author, and the import disagreed. |
| `tags`, `book_tags` | Genre and label in one table, told apart by `kind`. Deleted ones stay as tombstones. |
| `series` | Name, slug, total and a note — because the catalogues do not agree on how to count. |
| `covers` | Path or source, with attribution and a rejection date. |
| `users`, `auth_tokens`, `login_attempts` | The account, "stay signed in" (stored only as a hash), and the lockout after failed attempts. |
| `pages` | Imprint, privacy, about — content in the database, not files in the code. |
| `lookup_hits` | The rate limit on the lookup endpoint, so nobody uses the server as a free DNB proxy. |
| `import_log`, `isbn_cache` | The report from the move; the cache is in the schema and nothing uses it yet. |

---

## What runs without a browser

Without a shell there are two ways to start something with no visitor present:
the scripts in `bin/` from a machine that has one, and the host's scheduler,
which calls a **URL**.

| Address | Does |
|---|---|
| `/cron` | Backup and enrichment in sequence, for anyone who only wants one job. |
| `/cron/backup` | Expired sign-ins, database, covers. |
| `/cron/enrich` | Fills in missing covers and details, throttled. For three thousand books that is hours — which no browser window survives. |

One address per job and no parameter: a typo is then a 404 the cron service
reports, rather than a parameter the endpoint quietly interprets. Every run
appends to `storage/cron.log`; the last forty are shown under
*Administration → Data*.

---

## Read it in this order

Seven files, each of which explains the next.

1. **`public/index.php`** (198 lines) — every address in the application, one
   under the other. Read it top to bottom and you know what exists.
2. **`app/Http/Application.php`** (306) — what the pieces are, with the
   comments on why there is no container.
3. **`app/Controller/ShelfController.php`** (847) — shelf, filters, facets,
   book page, series. The longest read path there is, and the best place to see
   the parts working together.
4. **`app/Repository/BookRepository.php`** (789) — especially `buildWhere()`
   and `SORTS`, where filtering and ordering are data rather than branches.
5. **`app/Lookup/LookupChain.php`, then `DnbLookup.php`** (864) — first the
   chain: the order, the gap filling, the difference between "does not have it"
   and "could not answer". Then the largest adapter.
6. **`app/Core/View.php` and `app/templates/layout/base.php`** — `render()` for
   a piece, `page()` for a whole page; the layout shows how nonce, theme and
   navigation come together.
7. **`tests/run.php`, then any test** — no PHPUnit, just `Assert::group()` and
   `Assert::same()`. The comments in the tests almost always say which bug
   caused them.

The project's convention is that the reasoning lives next to the decision. If
something here is not enough, the comment above the line usually is.
