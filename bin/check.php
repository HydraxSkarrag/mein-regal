<?php
/**
 * Prüft, ob die Datenquellen erreichbar sind und der Google-Schlüssel greift.
 *
 *   php bin/check.php
 *   php bin/check.php --key=AIzaSy...    Schlüssel testen, bevor er in die Konfiguration wandert
 *
 * Gedacht für zwei Momente: vor dem Livegang, und wenn Cover ausbleiben.
 * Beantwortet die Frage "liegt es an mir oder an denen" ohne Rätselraten.
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
    $keySource = 'aus dem Aufruf';
} else {
    try {
        $config = Config::load();
        $key = $config->str('google_books_key') ?: null;
        $keySource = $key !== null ? 'aus config.php' : '';
    } catch (Throwable $e) {
        // Ohne Konfiguration lässt sich trotzdem alles außer dem Schlüssel prüfen.
    }
}

/**
 * Sieht das nach einem echten Schlüssel aus?
 *
 * Google-API-Schlüssel beginnen mit "AIza" und sind 39 Zeichen lang. Wer den
 * Platzhalter aus der Anleitung stehen lässt, bekommt sonst "API key not
 * valid" - eine völlig korrekte Antwort, die aber wie ein Problem mit dem
 * Google-Konto aussieht statt wie ein Tippfehler im Aufruf.
 */
function siehtNachSchluesselAus(string $key): bool
{
    if (preg_match('/^(DEIN|YOUR|MEIN|XXX|AIzaSy\.\.\.)/i', $key) === 1) {
        return false;
    }

    return str_starts_with($key, 'AIza') && strlen($key) >= 35;
}

$http = new HttpClient('', 12);

function zeile(string $name, bool $ok, string $hinweis = ''): void
{
    printf("  %-22s %-6s %s\n", $name, $ok ? 'ok' : 'FEHLT', $hinweis);
}

echo "Prüfe mit ISBN " . $isbn . "\n\n";

// ---- Deutsche Nationalbibliothek -----------------------------------------
$response = $http->get(
    'https://services.dnb.de/sru/dnb?version=1.1&operation=searchRetrieve'
    . '&query=NUM%3D' . $isbn . '&recordSchema=oai_dc&maximumRecords=1'
);
$treffer = str_contains($response['body'], '<numberOfRecords>1<');
zeile('DNB', $response['status'] === 200, $response['status'] === 200
    ? ($treffer ? 'antwortet, Treffer' : 'antwortet, kein Treffer für diese ISBN')
    : 'HTTP ' . $response['status']);

// ---- Open Library ---------------------------------------------------------
$response = $http->get('https://openlibrary.org/api/books?bibkeys=ISBN:' . $isbn . '&format=json&jscmd=data');
zeile('Open Library', $response['status'] === 200, 'HTTP ' . $response['status']);

$response = $http->get('https://covers.openlibrary.org/b/isbn/' . $isbn . '-L.jpg?default=false');
zeile('Open Library Cover', in_array($response['status'], [200, 404], true),
    $response['status'] === 200 ? 'Cover vorhanden' : ($response['status'] === 404 ? 'erreichbar, für diese ISBN kein Cover' : 'HTTP ' . $response['status']));

// ---- Google Books ---------------------------------------------------------
echo "\n";
$url = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . $isbn;
$ohne = $http->get($url);

$platzhalter = $key !== null && !siehtNachSchluesselAus($key);
$mitKey = ($key !== null && !$platzhalter) ? $http->get($url . '&key=' . urlencode($key)) : null;

if ($ohne['status'] === 200) {
    zeile('Google (ohne Key)', true, 'antwortet - das gemeinsame Kontingent ist gerade frei');
} else {
    $grund = '';
    $daten = json_decode($ohne['body'], true);
    if (is_array($daten) && isset($daten['error']['message'])) {
        $grund = str_contains($daten['error']['message'], 'Quota exceeded')
            ? 'Tageskontingent erschöpft'
            : substr($daten['error']['message'], 0, 60);
    }
    zeile('Google (ohne Key)', false, 'HTTP ' . $ohne['status'] . ($grund ? ' - ' . $grund : ''));
    echo "                         Das ist Googles gemeinsames Projekt für alle ohne\n";
    echo "                         eigenen Schlüssel. Ein eigener behebt das.\n";
}

if ($platzhalter) {
    echo "\n  Das war der Platzhalter aus der Anleitung, kein echter Schlüssel.\n";
    echo "  Ein Google-Schlüssel beginnt mit \"AIza\" und ist 39 Zeichen lang.\n";
    echo "  Anlegen unter console.cloud.google.com, dann:\n";
    echo "    php bin/check.php --key=AIzaSy...\n";
} elseif ($key === null) {
    echo "\n  Kein Google-Schlüssel hinterlegt.\n";
    echo "  In config.php unter 'google_books_key' eintragen, oder hier testen:\n";
    echo "    php bin/check.php --key=AIzaSy...\n";
} else {
    $daten = json_decode($mitKey['body'], true);
    if ($mitKey['status'] === 200) {
        zeile('Google (mit Key)', true, sprintf(
            'Schlüssel %s greift, %d Treffer',
            $keySource,
            (int) ($daten['totalItems'] ?? 0)
        ));
    } else {
        $meldung = is_array($daten) ? ($daten['error']['message'] ?? '') : '';
        zeile('Google (mit Key)', false, 'HTTP ' . $mitKey['status']);
        echo "                         " . substr($meldung, 0, 90) . "\n";
        if (str_contains($meldung, 'not been used') || str_contains($meldung, 'disabled')) {
            echo "                         -> Die Books API ist im Projekt noch nicht aktiviert.\n";
        } elseif (str_contains($meldung, 'referer') || str_contains($meldung, 'blocked')) {
            echo "                         -> Der Schlüssel ist auf Websites eingeschränkt.\n";
            echo "                            Für Serveraufrufe stattdessen auf IP-Adressen\n";
            echo "                            einschränken, oder die Einschränkung entfernen.\n";
        }
    }
}

echo "\n";
