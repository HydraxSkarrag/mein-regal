<?php
/**
 * Are the data sources reachable, and does the Google key work?
 *
 *   php bin/check.php
 *   php bin/check.php --key=AIzaSy...    test a key before it goes into config.php
 *
 * Meant for two moments: before going live, and when covers stop arriving. It
 * answers "is it me or is it them" without guesswork.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Lookup\HttpClient;

$options = getopt('', ['key::', 'isbn::']);
$isbn = (string) ($options['isbn'] ?? '9783473408061');

$key = null;
$keySource = '';
if (isset($options['key']) && $options['key'] !== false) {
    $key = (string) $options['key'];
    $keySource = 'from the command line';
} else {
    try {
        $config = Config::load();
        $key = $config->str('google_books_key') ?: null;
        $keySource = $key !== null ? 'from config.php' : '';
    } catch (Throwable $e) {
        // Without a configuration everything except the key can still be checked.
    }
}

/**
 * Does this look like a real key?
 *
 * Google API keys start with "AIza" and are 39 characters long. Leaving the
 * placeholder from the instructions in place otherwise produces "API key not
 * valid" - a perfectly correct answer that reads like a problem with the
 * Google account rather than a typo in the call.
 */
function looksLikeAKey(string $key): bool
{
    if (preg_match('/^(DEIN|YOUR|MEIN|XXX|AIzaSy\.\.\.)/i', $key) === 1) {
        return false;
    }

    return str_starts_with($key, 'AIza') && strlen($key) >= 35;
}

$http = new HttpClient('', 12);

function line(string $name, bool $ok, string $note = ''): void
{
    printf("  %-22s %-6s %s\n", $name, $ok ? 'ok' : 'FAILED', $note);
}

echo 'Checking with ISBN ' . $isbn . "\n\n";

// ---- German National Library ---------------------------------------------
$response = $http->get(
    'https://services.dnb.de/sru/dnb?version=1.1&operation=searchRetrieve'
    . '&query=NUM%3D' . $isbn . '&recordSchema=oai_dc&maximumRecords=1'
);
$found = str_contains($response['body'], '<numberOfRecords>1<');
line('DNB', $response['status'] === 200, $response['status'] === 200
    ? ($found ? 'answers, found it' : 'answers, nothing for this ISBN')
    : 'HTTP ' . $response['status']);

// ---- Open Library ---------------------------------------------------------
$response = $http->get('https://openlibrary.org/api/books?bibkeys=ISBN:' . $isbn . '&format=json&jscmd=data');
line('Open Library', $response['status'] === 200, 'HTTP ' . $response['status']);

$response = $http->get('https://covers.openlibrary.org/b/isbn/' . $isbn . '-L.jpg?default=false');
line('Open Library covers', in_array($response['status'], [200, 404], true),
    $response['status'] === 200 ? 'has a cover' : ($response['status'] === 404 ? 'reachable, no cover for this ISBN' : 'HTTP ' . $response['status']));

// ---- Google Books ---------------------------------------------------------
echo "\n";
$url = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . $isbn;
$anonymous = $http->get($url);

$placeholder = $key !== null && !looksLikeAKey($key);

/* A freshly created key often answers 503 for a few minutes until Google has
   distributed it. Retrying quietly saves the impression that something is
   broken. */
$withKey = null;
$attempts = 0;
if ($key !== null && !$placeholder) {
    for ($attempts = 1; $attempts <= 3; $attempts++) {
        $withKey = $http->get($url . '&key=' . urlencode($key));
        if ($withKey['status'] !== 503 || $attempts === 3) {
            break;
        }
        sleep(3);
    }
}

if ($anonymous['status'] === 200) {
    line('Google (no key)', true, 'answers - the shared quota happens to be free');
} else {
    $reason = '';
    $data = json_decode($anonymous['body'], true);
    if (is_array($data) && isset($data['error']['message'])) {
        $reason = str_contains($data['error']['message'], 'Quota exceeded')
            ? 'daily quota exhausted'
            : substr($data['error']['message'], 0, 60);
    }
    line('Google (no key)', false, 'HTTP ' . $anonymous['status'] . ($reason ? ' - ' . $reason : ''));
    echo "                         That is Google's shared project for everyone without\n";
    echo "                         a key of their own. Your own key fixes it.\n";
}

if ($placeholder) {
    echo "\n  That was the placeholder from the instructions, not a real key.\n";
    echo "  A Google key starts with \"AIza\" and is 39 characters long.\n";
    echo "  Create one at console.cloud.google.com, then:\n";
    echo "    php bin/check.php --key=AIzaSy...\n";
} elseif ($key === null) {
    echo "\n  No Google key configured.\n";
    echo "  Put one in config.php under 'google_books_key', or test it here:\n";
    echo "    php bin/check.php --key=AIzaSy...\n";
} else {
    $data = json_decode($withKey['body'], true);
    if ($withKey['status'] === 200) {
        $results = (int) ($data['totalItems'] ?? 0);
        line('Google (with key)', true, sprintf(
            'the key %s works, %d result%s',
            $keySource,
            $results,
            $results === 1 ? '' : 's'
        ));
    } else {
        $message = is_array($data) ? ($data['error']['message'] ?? '') : '';
        line('Google (with key)', false, 'HTTP ' . $withKey['status']
            . ($attempts > 1 ? ' (after ' . $attempts . ' attempts)' : ''));
        echo '                         ' . substr($message, 0, 90) . "\n";
        if ($withKey['status'] === 503) {
            echo "                         -> The key is accepted, Google simply is not\n";
            echo "                            answering right now. For a newly created key\n";
            echo "                            that is normal: it takes a few minutes to become\n";
            echo "                            known everywhere. Try again in ten minutes. If it\n";
            echo "                            persists, check in the Cloud Console that the\n";
            echo "                            Books API is enabled in the right project.\n";
        } elseif (str_contains($message, 'not been used') || str_contains($message, 'disabled')) {
            echo "                         -> The Books API is not enabled in the project yet.\n";
        } elseif (str_contains($message, 'referer') || str_contains($message, 'blocked')) {
            echo "                         -> The key is restricted to websites. For calls made\n";
            echo "                            by a server, restrict it to IP addresses instead,\n";
            echo "                            or remove the restriction.\n";
        }
    }
}

/* Which database this installation would use.
 *
 * db_dsn is meant for local work and belongs empty on a server. Left filled
 * in, the site starts perfectly well against whatever it names - an SQLite
 * file that may not even exist yet - and shows an empty shelf with nothing to
 * suggest that the real catalogue is somewhere else. Worth a word before
 * going live, which is one of the two moments this script is for.
 */
if (isset($config)) {
    echo "\n";
    $dsn = $config->str('db_dsn');
    if ($dsn === '') {
        line('Database', true, 'mysql, as configured for a server');
    } else {
        line('Database', false, 'db_dsn is set: ' . $dsn);
        echo "                         That overrides the MySQL settings. On the server it
";
        echo "                         belongs empty, or the shelf will be looked for in
";
        echo "                         the wrong place - quietly.
";
    }

    /* A theme named in config.php that is not on disk. The shelf still
       renders - it falls back to the neutral default - so nothing breaks
       loudly enough to notice, and the site simply looks wrong. */
    $theme = new App\Core\Theme(PROJECT_ROOT, $config->str('theme'));
    if ($theme->missing()) {
        line('Theme', false, "'" . $theme->slug() . "' is named but not in public/css/themes/");
        echo "                         The shelf falls back to the neutral default.
";
    } elseif ($theme->slug() !== '') {
        line('Theme', true, $theme->slug());
    }
}

echo "\n";
