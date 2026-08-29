<?php
/**
 * Fill the gaps in the existing catalogue, a little at a time.
 *
 *   php bin/enrich.php --limit=200
 *   php bin/enrich.php --limit=200 --sqlite=/tmp/x.sqlite
 *
 * Meant for the nightly cron. The 3,042 books imported from Bookstats arrived
 * without covers and with holes in their metadata; asking for all of them at
 * once would exhaust Google's daily quota and be rude to the Deutsche
 * Nationalbibliothek. A few hundred a night fills the shelf over a couple of
 * weeks without anyone having to touch a book.
 *
 * On all-inkl the cron calls a URL rather than a script, so this file is also
 * reachable through bin/cron.php with the configured secret.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\CoverStorage;
use App\Core\Database;
use App\Lookup\CoverFinder;
use App\Lookup\DnbLookup;
use App\Lookup\GoogleBooksLookup;
use App\Lookup\HttpClient;
use App\Lookup\LookupChain;
use App\Lookup\OpenLibraryLookup;
use App\Repository\AuthorRepository;
use App\Repository\CoverRepository;
use App\Repository\TagRepository;

/**
 * @param array<string,mixed> $options
 */
function enrich(PDO $pdo, Config $config, int $limit, int $ownerId, bool $verbose = true): array
{
    $http = new HttpClient($config->str('api_contact'));
    $chain = new LookupChain(
        new DnbLookup($http),
        new GoogleBooksLookup($http, $config->str('google_books_key')),
        new OpenLibraryLookup($http)
    );
    $covers = new CoverRepository($pdo);
    $authors = new AuthorRepository($pdo);
    $tags = new TagRepository($pdo);
    $storage = new CoverStorage(PROJECT_ROOT . '/public/covers');
    $finder = new CoverFinder($chain, $covers, $storage);

    // Books with no cover at all come first; among those, the ones missing the
    // most metadata. Anything already tried today is skipped, so a nightly run
    // moves forward instead of retrying the same failures.
    $statement = $pdo->prepare(
        'SELECT b.id, b.isbn13, b.title, b.publisher, b.published_year, b.page_count, b.language
           FROM books b
          WHERE b.owner_id = ?
            AND b.isbn13 IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM covers c WHERE c.book_id = b.id)
            AND NOT EXISTS (
                  SELECT 1 FROM isbn_cache ic
                   WHERE ic.isbn = b.isbn13 AND ic.fetched_at > ?
                )
          ORDER BY b.id ASC
          LIMIT ' . (int) $limit
    );
    $statement->execute([$ownerId, (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s')]);
    $books = $statement->fetchAll();

    $stats = ['looked_up' => 0, 'covers' => 0, 'metadata' => 0, 'misses' => 0];

    foreach ($books as $book) {
        $isbn = (string) $book['isbn13'];
        $stats['looked_up']++;

        $outcome = $chain->find($isbn);
        $found = $outcome['result'];

        // Record the attempt either way, so a book with no record anywhere is
        // not asked about again every single night.
        $remember = $pdo->prepare(
            'INSERT INTO isbn_cache (isbn, source, http_status, found, payload)
             VALUES (?, ?, ?, ?, ?)'
        );
        try {
            $remember->execute([$isbn, 'chain', 200, $found !== null ? 1 : 0, null]);
        } catch (PDOException) {
            $pdo->prepare('UPDATE isbn_cache SET fetched_at = ?, found = ? WHERE isbn = ? AND source = ?')
                ->execute([(new DateTimeImmutable())->format('Y-m-d H:i:s'), $found !== null ? 1 : 0, $isbn, 'chain']);
        }

        if ($found === null) {
            $stats['misses']++;
            usleep(400000);
            continue;
        }

        // Fill only what is genuinely missing. Anything already recorded -
        // not least the prices and dates carried over from Bookstats - stays
        // as it is.
        $updates = [];
        $values = [];
        foreach (['publisher' => $found->publisher, 'published_year' => $found->publishedYear,
                  'page_count' => $found->pageCount, 'language' => $found->language] as $column => $value) {
            if ($value !== null && ($book[$column] ?? null) === null) {
                $updates[] = $column . ' = ?';
                $values[] = $value;
            }
        }
        if ($updates !== []) {
            $values[] = $book['id'];
            $pdo->prepare('UPDATE books SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($values);
            $stats['metadata']++;
        }

        // One place decides where a cover may come from and what happens
        // to it - the same code the scanner and the edit page use.
        if ($finder->findFor((int) $book['id'], $isbn, $found)['stored']) {
            $stats['covers']++;
        }

        if ($verbose) {
            printf(
                "  %-14s %-44s %s\n",
                $isbn,
                mb_substr((string) $book['title'], 0, 42),
                $covers->bestFor((int) $book['id'], true) !== null ? 'Cover' : '–'
            );
        }

        // Deliberately unhurried. Nothing here is urgent, and the sources are
        // free; hammering them would be the fastest way to lose access.
        usleep(700000);
    }

    return $stats;
}

if (PHP_SAPI === 'cli') {
    $options = getopt('', ['limit::', 'owner::', 'sqlite::', 'quiet']);
    $limit = max(1, min(1000, (int) ($options['limit'] ?? 100)));
    $ownerId = (int) ($options['owner'] ?? 1);

    if (isset($options['sqlite']) && $options['sqlite'] !== false) {
        $pdo = new PDO('sqlite:' . $options['sqlite']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $config = new Config(['api_contact' => '', 'google_books_key' => '']);
    } else {
        $config = Config::load();
        $pdo = Database::connect($config);
    }

    $started = microtime(true);
    $stats = enrich($pdo, $config, $limit, $ownerId, !isset($options['quiet']));

    printf(
        "\nAbgefragt %d | Cover %d | Metadaten ergänzt %d | ohne Treffer %d | %.1f s\n",
        $stats['looked_up'],
        $stats['covers'],
        $stats['metadata'],
        $stats['misses'],
        microtime(true) - $started
    );
}
