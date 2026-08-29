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
    'site_name'    => 'Das Regal',
    'site_url'     => 'https://regal.buecherhausen.de',
    'blog_url'     => 'https://www.buecherhausen.de/',
    'blog_name'    => 'Bücherhausen',
    'locale'       => 'de',

    // Contact details for the legal pages (Impressum / privacy policy).
    'legal' => [
        'operator' => '',
        'street'   => '',
        'city'     => '',
        'email'    => '',
        // Responsible party under section 18 (2) MStV, if editorial content is shown.
        'mstv_responsible' => '',
    ],

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
