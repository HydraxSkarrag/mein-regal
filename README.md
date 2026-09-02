# Mein Regal

A self-hosted bookshelf: scan a barcode, fetch the book's details, catalogue it.
Runs on ordinary PHP web space with no shell access.

It exists because [bookstats.de](https://bookstats.de) shut down, and a collection
of 3,042 books grown over years would otherwise have survived only as a CSV file.

Written for the bookshelf of [Bücherhausen](https://www.buecherhausen.de/) and
passed on from there. Every installation shows a line in its footer saying so,
linking to the project and to that blog — please leave it there. It is a
request rather than a condition: the licence asks nothing of what a running
installation puts on the screen.

## What it does

- **Scan barcodes** in the browser, on a phone or a desktop. Uses the browser's
  own detector where there is one and falls back to a bundled library.
- **Fetch book data** from the German National Library, Google Books and Open
  Library, in whichever order the ISBN suggests.
- **Covers** from the free sources, or photographed yourself.
- **The shelf** with search, filters and sorting; public to read, editing behind
  the login.
- **Genres and labels.** An import brings hundreds of "genres" that are not
  genres - a binding, an age range, a shop category. Each one is filed as a
  genre or as a label by hand, and the ones that are really a field of the book
  are folded into that field. Removals and merges survive the next import.
- **Statistics** in public — `'public_stats' => false` keeps them to yourself —
  and a **dashboard** with data quality and a to-do list in private.
- **Review links.** Point it at a WordPress blog and it matches the blog's posts
  against the shelf - on the ISBN in the post first, on title and author second -
  and links each book to the review of it. Undecided matches are listed rather
  than guessed at.
- **Export** in three formats and a **backup** of database, catalogue and covers.
- Interface in German and English — `'language_switcher' => false` drops the
  switch for a shelf that is only read in one — and about, imprint and privacy
  policy are written in the browser, one text per language.

## Requirements

PHP 8.1 or newer with `pdo_mysql`, `gd`, `mbstring`, `curl` and `simplexml`.
MySQL or MariaDB. HTTPS is not optional — no browser grants camera access over an
unencrypted connection.

Tested on all-inkl Privat+ (PHP 8.3, no SSH), which is also what CI runs. `intl`
is used when present but is not required.

## Setting it up

### 1. Upload the files

The application code belongs **above** the document root, not inside it:

```
regal/
  app/  bin/  migrations/  schema.sql
  config.php          <- credentials, on the server only
  storage/            <- logs and backups
  public/             <- the document root points here
```

With all-inkl, set the subdomain's document root to `regal/public/` under
*Domain → Subdomain*. **If it points at `regal/` instead, the entire source is
readable over HTTP.** That is the one setting that has to be right.

### 2. Create the database

Load `schema.sql` once through phpMyAdmin. Later changes are dated files in
`migrations/`, applied the same way.

### 3. Configure

Copy `config.sample.php` to `config.php` and fill it in: credentials, site name,
the contact details for the imprint and privacy policy, `cron_secret`. The file
belongs next to `app/`, never inside `public/`, and never in the repository.

Leaving `blog_url` and `blog_name` empty is fine — the link to a blog then simply
does not appear. A shelf does not have to belong to one.

Four settings decide what the installation is rather than how it looks:

| Setting | Default | What it does |
|---|---|---|
| `public_stats` | `true` | statistics for visitors; `false` puts them behind the login and drops them from the sitemap |
| `language_switcher` | `true` | the choice of German or English; `false` serves `locale` and nothing else |
| `review_blog_url` | empty | the WordPress blog `bin/reviews.php` matches against. Empty means nothing is ever contacted |
| `ai_crawlers` | `false` | whether crawlers collecting training text are welcome. `false` writes a `Disallow` for each into `robots.txt`; search engines are unaffected |

### 4. Your own logo and colours

The shelf ships with a neutral mark and a neutral palette so a fresh
installation looks finished rather than borrowed. Both are replaceable, and
both are replaced in the same place.

**Colours, shapes and typefaces** are tokens on `:root` in `public/css/style.css`,
and nothing in that file uses a colour that is not one. A theme is therefore
one small file that restates some of those tokens. Three layers, in this order:

| | |
|---|---|
| `public/css/style.css` | the neutral default, always loaded |
| `public/css/themes/<name>.css` | a shipped theme, chosen with `'theme' => '<name>'` |
| `public/assets/brand/theme.css` | yours, loaded last, wins |

Shipped so far: **`buecherhausen`** (red on near-white — the look this was first
drawn in) and **`night`** (the default, but dark when the reader's system is).
Copy either as a starting point.

The third layer is the private one. It sits beside the logo, is excluded from
Git and from the deployment, and is the right place for an installation whose
appearance is nobody else's business. Web fonts of your own go next to it in
`public/assets/brand/fonts/` and are referenced from `theme.css` with a
relative path — never from a font service, which would hand every visitor's
address to a third party and cost the site its consent-banner-free standing.

A `<meta name="theme-color">` and the web app manifest are not CSS and cannot
read a custom property - which is usually where a second copy of the colour
ends up in a configuration file, and then drifts. They are read out of the
theme's own `--bg` instead, and a theme that wants the browser chrome in some
other colour says so with `--meta-theme-colour`. A theme defining both schemes
gets both tags, so the address bar follows the page rather than sitting next
to the wrong one half the time.

**Logo and icons** go in `public/assets/brand/`:

| File | Used for |
|---|---|
| `logo.svg` or `logo.png` | the header, scaled to 36 pixels tall |
| `favicon-32x32.png` | the browser tab |
| `favicon-192x192.png` | the home-screen tile |
| `apple-touch-icon.png` | the same on iOS, 180×180 |

Whatever is there wins; the rest stays on the default. The folder is excluded
from Git **and** from the deployment, so your logo is neither published with the
source nor overwritten the next time you deploy — the same treatment
`public/covers/` gets, and for the same reason. Until something is in it, the
setup page and the dashboard say so.

`public/assets/logo.svg` is the source the default PNG icons were drawn from;
copy it if you want a starting point in your own colours.

### 5. Create an account

Open `https://your-address/setup` and create the first account. The page answers
only while no account exists; afterwards it is a 404 like any unknown address.

Creating the account also writes a German imprint and privacy policy into the
database, carrying the name and address of the account just created. What it
cannot know — your postal address, who hosts the site — is marked in the text
with a ⚠ rather than left blank, because an empty line in an imprint reads
like a formatting slip and survives for years.

**Read both before going live**, and fill in the marked places under
`/imprint` and `/privacy`. They describe an installation with no analytics,
no CDN and no embedded images, because that is what this application is — but
the details are yours, and the text is not legal advice.

With shell access:

```bash
php bin/setup.php --email=you@example.org --name="Your Name"
```

Without `--password` one is generated and printed once. After that only its hash
is stored.

### 6. Import an existing collection

Upload the CSV under *Admin → Data*. First **without** the "really write" box —
that produces only the report naming duplicates, missing ISBNs and ambiguous
author fields. Then with it.

Three thousand books are roughly 23,000 database statements, so five to twenty
seconds depending on the server. It runs in one transaction: if it fails, nothing
half-imported is left behind.

With shell access:

```bash
php bin/import.php --file=books.csv            # dry run, writes nothing
php bin/import.php --file=books.csv --commit
```

### 7. Set up the nightly cron job

all-inkl's scheduler calls a URL rather than running a script. Under
*Tools → Cronjobs*:

```
https://regal.example.org/cron?key=YOUR_CRON_SECRET
```

The job backs up the database, catalogue and covers, then looks for missing
covers and details, then clears out expired sign-in tokens.

The lookup runs against a **time budget** rather than a number of books: 120
seconds by default, after which it stops and continues the next night. It waits
between requests on purpose, so as not to lean on the free data sources — for
three thousand books that would be hours, which no cron job survives. Adjust with
`&budget=180` (clamped to between 20 and 240 seconds).

## Tools

```bash
php bin/export.php --format=bookstats     # the original format, reads back in
php bin/export.php --format=full          # every column, UTF-8
php bin/export.php --format=json          # everything, contributors and tags too
php bin/backup.php --keep=30              # database, catalogue and covers
php bin/enrich.php --limit=100            # fill in missing covers and details
php bin/covers.php                        # what the covers look like, and why some are missing
php bin/covers.php --refresh              # fetch the sources' larger renditions
php bin/covers.php --prune                # cover files nothing points at any more
php bin/reviews.php --fetch               # match the blog's posts against the shelf
php bin/check.php                         # are the data sources reachable?
php tests/run.php                         # the tests
```

`bin/covers.php` and `bin/reviews.php` write nothing until `--commit`, and say
first what they would do and to which book.

`bin/reviews.php` refuses to run without `review_blog_url` in `config.php`. It is
the only thing in the project that talks to the blog: no page loads anything from
it, and the nightly job does not call it. What it writes is the review link and,
where a post names one and the shelf does not, the ISBN. A link that points
somewhere other than the configured blog is left alone - somebody typed it.

Every script that touches the database takes `--sqlite=/path/to/file` and then
runs against a throwaway database without a `config.php` — which is also how the
development environment works. `REGAL_CONFIG=/path/to/config.php` overrides where
the configuration is read from.

### Without shell access

all-inkl offers SSH only from the Premium plan up. Everything needed to run the
site therefore works through the browser as well:

| Task | Without a shell | Why |
|---|---|---|
| Create an account | `/setup` | once, immediate |
| Import a collection | Admin → Data | once, seconds |
| Download an export | Admin → Data | a handful of queries |
| Backup | nightly cron job | seconds |
| Fill in covers | **cron job only** | waits between books, takes hours |

The lookup is deliberately not reachable from the browser. It is the one task
that runs longer than any sensible time limit.

## Developing

```bash
./dev.sh
```

That writes a `config.dev.php`, creates an SQLite database, adds a local account,
imports a CSV if one is next to it, and starts the server. Or by hand:

```bash
php bin/setup.php --sqlite=/tmp/regal.sqlite --email=you@example.org --name="You"
php bin/import.php --file=books.csv --sqlite=/tmp/regal.sqlite --commit
REGAL_CONFIG=$PWD/config.dev.php php -S localhost:8931 -t public router.dev.php
```

There is no build step and no runtime dependency. That is not purism, it follows
from the hosting: with no shell there is no Composer on the server. What is needed
in the way of libraries sits ready in `public/js/`.

### What to keep in mind when changing things

- **Load nothing from anyone else's server.** No CDN, no web fonts, no analytics.
  More hangs on this than taste: the strict Content-Security-Policy and the fact
  that the site needs no cookie banner. The first external resource costs both.
- **All database access belongs in `app/Repository/`.** Templates hold no logic
  beyond loops and conditionals.
- **`owner_id` is always part of the filter**, even while there is one collection.
- **Source and comments in English.** German belongs in the interface translations
  and in the legal texts, which are content rather than code.
- **Form fields are cleaned in one place.** `App\Core\Input` turns what was
  typed into what the database may hold. Writing a second `intOrNull` next to
  the first is how the scanner and the edit form came to disagree about whether
  a page count with a trailing space counts.
- **No colour outside `:root`.** Every colour, radius, shadow and typeface in
  `style.css` is a token, and templates carry classes rather than colours. That
  is the whole of what makes a theme a small file instead of a fork.
- **No `style` attribute, ever.** The Content-Security-Policy forbids inline
  style, and `'unsafe-inline'` for one bar would mean it for the whole site.
  Static values are utility classes; anything a page can only know while it
  renders goes through `App\Core\Styles`, which collects it into one
  nonce-bearing `<style>`.
- **Owner-written text is never HTML.** It is stored as typed and rendered by
  `App\Core\Markup`, which escapes everything before it writes a single tag. If
  you find yourself adding an HTML filter, something has gone wrong.

## Deployment

Through GitHub Actions (*Actions → Deploy → Run workflow*), not automatically on
every commit. Required secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.

The repository root is uploaded into the project folder. Never touched:
`config.php`, `storage/` and `public/covers/` — the cover photographs, which exist
only on the server.

## Data sources

| Source | For | Terms |
|---|---|---|
| [DNB](https://services.dnb.de/sru/dnb) | German titles | metadata CC0, no key needed |
| [Google Books](https://developers.google.com/books) | English titles, covers | own key needed, free, ~1,000 requests a day |
| [Open Library](https://openlibrary.org/developers/api) | English titles, covers | open, backlink appreciated |

Covers are **downloaded and served from your own server**, not embedded. Looking
at the shelf therefore opens no connection to anyone else. Source and backlink are
stored and shown per image.

### Setting up a Google key

Without your own key Google Books is effectively unavailable: anonymous requests
share one daily quota, which is usually exhausted. The answer is then
`429 Quota exceeded`, citing a project number that is not yours.

1. Open [console.cloud.google.com](https://console.cloud.google.com) and create a
   project (the name does not matter).
2. Under *APIs & Services → Library*, find **Books API** and enable it. Without
   this step every key is rejected with "has not been used".
3. Under *APIs & Services → Credentials* → *Create credentials* → **API key**.
4. Restrict it: under *API restrictions* limit it to **Books API**. Under
   *Application restrictions* choose **IP addresses** and enter the server's — not
   "HTTP referrers", because the requests come from the server, not the browser.
5. Enter it in `config.php` under `google_books_key`.

The Books API is free and asks for no payment details. A newly created key answers
with `503` for a few minutes; that passes.

To find out whether it works:

```bash
php bin/check.php --key=AIzaSy...
```

The script checks all three sources and, for Google, says plainly which it is —
quota exhausted, API not enabled, or the key restricted to the wrong thing.

## Licence

Source under MIT, and that includes the default logo — see [LICENSE](LICENSE).
Excluded are the bundled fonts and the cover images, which belong to their
rights holders, and the name and logo of Bücherhausen, which belong to the
blog. The name "Mein Regal" is not reserved — it is an ordinary German phrase, and
your shelf is named in `config.php` anyway.

Nobody else's brand ships in this repository either: an operator's own logo
lives in `public/assets/brand/`, which is not in version control.
