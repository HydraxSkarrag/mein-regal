<?php
/**
 * Template for the local configuration.
 *
 * Copy this to "config.php" and fill in the real values.
 * On the server config.php lives ONE LEVEL ABOVE the document root
 * (next to app/, not inside public/) so it is never reachable over HTTP.
 * config.php must NEVER be committed - see .gitignore.
 */
declare(strict_types=1);

return [
    'db_host'    => 'localhost',
    'db_name'    => 'YOUR_DATABASE',
    'db_user'    => 'YOUR_USER',
    'db_pass'    => 'YOUR_PASSWORD',
    'db_charset' => 'utf8mb4',

    // Optional full PDO DSN, overrides the MySQL values above.
    // Local testing only, e.g. 'sqlite:/path/to/test.sqlite'. Leave empty on the server.
    'db_dsn' => '',

    // Branding. Kept out of the code so a second installation only edits this file.
    'site_name' => 'Mein Regal',
    'site_url'  => 'https://example.org',

    // Interface language. With the switch below turned off this is the one
    // the site speaks; with it on it is the fallback for missing translations.
    'locale'    => 'de',

    // The EN/DE switch in the header. Turn it off for a shelf that is only
    // ever read in one language: the header link disappears, /language/... is
    // answered with a 404, and the browser's Accept-Language no longer
    // decides anything. Book titles are unaffected either way - they always
    // stay in the language they were entered.
    'language_switcher' => true,

    // A blog or homepage this shelf belongs to. Both empty means no such link
    // is shown anywhere - a shelf does not have to belong to a blog.
    'blog_url'  => '',
    'blog_name' => '',

    // Fill in before going live. These are written into the Impressum and the
    // privacy policy when the first account is created; afterwards both texts
    // are edited in the browser under /imprint and /privacy, and changing them
    // here has no further effect.
    'legal' => [
        'operator' => '',
        'street'   => '',
        'city'     => '',
        'email'    => '',
        // Who hosts the site, as it should read in the privacy policy, e.g.
        // 'ALL-INKL.COM - Neue Medien Münnich, Hauptstraße 68, 02742 Friedersdorf'.
        'host'     => '',
        // Responsible party under section 18 (2) MStV, if editorial content is shown.
        'mstv_responsible' => '',
    ],

    // The statistics page at /stats, visible to anyone. Set to false to keep
    // the figures to yourself; the owner's dashboard under /admin is
    // unaffected either way.
    'public_stats' => true,

    // Only for a fork that lives at its own address. The /project page links
    // here; left empty it points at the original repository.
    'repository_url' => '',

    // A WordPress blog whose posts are reviews of these books, e.g.
    // 'https://www.example.org'. Left empty - the default - nothing here ever
    // contacts it, and bin/reviews.php refuses to run: an installation
    // without a blog should never reach out to one.
    //
    // With an address, bin/reviews.php reads the blog's public posts through
    // /wp-json/wp/v2/posts and links each book to the post about it, matching
    // on the ISBN in the post's text first and on title and author second.
    // Only that script talks to the blog; no visitor ever loads anything
    // from it.
    'review_blog_url' => '',

    // Whether crawlers that collect text to train language models are asked
    // to stay away. false - the default - writes a Disallow for each of them
    // into robots.txt; ordinary search engines are unaffected either way.
    //
    // It is a request, not a fence: the crawlers that publish a name honour
    // it, the ones that do not were never going to.
    'ai_crawlers' => false,

    // What the shelf looks like. Empty is the neutral default that ships
    // with it; a name loads public/css/themes/<name>.css over the top.
    // Ready-made: 'buecherhausen' (red on near-white, the original look) and
    // 'night' (the default, but dark when the reader's system is).
    //
    // A look of your own goes in public/assets/brand/theme.css, beside the
    // logo. That file is loaded last and wins, and like the logo it is kept
    // out of Git and out of the deployment - one installation's appearance
    // is nobody else's business.
    'theme' => '',

    // The colour the browser paints its own chrome with - the address bar on
    // Android, the tile on a home screen. Neither the meta tag nor the web
    // app manifest is CSS, so neither can read a token out of a theme: a
    // theme that changes the accent has to be told here as well.
    'theme_colour'      => '#2f5d8f',
    'background_colour' => '#fbfbf9',

    // Optional Google Books API key. Without one the shared per-IP quota applies.
    'google_books_key' => '',

    // Secret for the scheduled job at /cron?key=...
    // all-inkl's scheduler calls a URL rather than running a script, so this
    // is the only way in. Generate a long random value; the job refuses to
    // run while this is empty or shorter than 20 characters.
    'cron_secret' => '',

    // Contact address sent to the metadata APIs in the User-Agent header.
    // Open Library and the DNB ask for this so they can reach you about heavy usage.
    'api_contact' => '',
];
