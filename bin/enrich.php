<?php
/**
 * Fill the gaps in the existing catalogue, a little at a time.
 *
 *   php bin/enrich.php --limit=200
 *   php bin/enrich.php --limit=200 --sqlite=/tmp/x.sqlite
 *   php bin/enrich.php --again --limit=200   after adding a source
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
use App\Core\Isbn;
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
 * Give a book that only carries an ISBN-10 its ISBN-13 as well.
 *
 * The two are the same number written twice - 978, the first nine digits, a
 * recomputed check digit - so this invents nothing. It matters because every
 * worklist in the application is keyed on isbn13: a book without one was
 * silently excluded from enrichment, from the cover sources and from the
 * scanner's duplicate check, with no sign anywhere that it had been skipped.
 *
 * Anything that is not a valid ISBN-10 is left alone. The column also holds
 * the odd Amazon number carried over from the old export, and an ASIN is not
 * an ISBN however much it looks like one.
 *
 * @return int how many were numbered
 */
function completeIsbns(PDO $pdo, int $ownerId): int
{
    $rows = $pdo->prepare(
        "SELECT id, isbn10 FROM books
          WHERE owner_id = ? AND isbn10 IS NOT NULL AND isbn10 <> ''
            AND (isbn13 IS NULL OR isbn13 = '')"
    );
    $rows->execute([$ownerId]);

    $write = $pdo->prepare('UPDATE books SET isbn13 = ?, updated_at = ? WHERE id = ?');
    $numbered = 0;
    foreach ($rows->fetchAll() as $row) {
        $isbn13 = Isbn::to13((string) $row['isbn10']);
        if ($isbn13 === null) {
            continue;
        }
        $write->execute([$isbn13, date('Y-m-d H:i:s'), (int) $row['id']]);
        $numbered++;
    }

    return $numbered;
}

/**
 * Let the still-empty books be asked about again, ahead of their thirty days.
 *
 * The wait exists so consecutive nightly runs move forward instead of
 * grinding over the same failures, and it is right for what it was written
 * for: a book nobody has a record of today will not have gained one by
 * tomorrow. It is wrong for exactly one event - a new source being added.
 * Then every previous "nowhere has this" was an answer about a smaller set of
 * places, and the shelf would otherwise sit out a month waiting to find out.
 *
 * Only the books that still want something are reopened. A book already
 * carrying a cover and a full record has nothing to gain from being asked.
 *
 * @return int how many were reopened
 */
function reopen(PDO $pdo, int $ownerId): int
{
    $statement = $pdo->prepare(
        'DELETE FROM isbn_cache
           WHERE isbn IN (
                 SELECT b.isbn13 FROM books b
                  WHERE b.owner_id = ? AND b.isbn13 IS NOT NULL
                    AND NOT EXISTS (
                          SELECT 1 FROM covers c
                           WHERE c.book_id = b.id AND c.rejected_at IS NULL AND c.path IS NOT NULL
                        )
               )'
    );
    $statement->execute([$ownerId]);

    return $statement->rowCount();
}

/**
 * @param array<string,mixed> $options
 */
function enrich(
    PDO $pdo,
    Config $config,
    int $limit,
    int $ownerId,
    bool $verbose = true,
    int $budgetSeconds = 0,
    bool $again = false
): array {
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

    $stats = ['numbered' => completeIsbns($pdo, $ownerId), 'reopened' => $again ? reopen($pdo, $ownerId) : 0];

    /* What still wants asking about.
     *
     * The condition used to be "has no cover", which is the right worklist for
     * a job whose point is clearing a cover backlog - and the wrong one for
     * ever finishing. A book that gained a cover left the list permanently,
     * however many blanks it still carried, so its publisher and page count
     * were never filled by anything but hand.
     *
     * Now: no usable cover OR a hole in the fields a lookup can fill. A
     * rejected cover does not count as a cover - the point of throwing one out
     * is to be offered another.
     *
     * Anything tried in the last thirty days is skipped, so consecutive runs
     * move forward rather than grinding over the same failures.
     */
    $statement = $pdo->prepare(
        'SELECT b.id, b.isbn13, b.title, b.publisher, b.published_year, b.page_count, b.language
           FROM books b
          WHERE b.owner_id = ?
            AND b.isbn13 IS NOT NULL
            AND (
                  NOT EXISTS (SELECT 1 FROM covers c WHERE c.book_id = b.id AND c.rejected_at IS NULL)
                  OR b.publisher IS NULL
                  OR b.published_year IS NULL
                  OR b.page_count IS NULL
                  OR b.language IS NULL
                )
            AND NOT EXISTS (
                  SELECT 1 FROM isbn_cache ic
                   WHERE ic.isbn = b.isbn13 AND ic.fetched_at > ?
                )
          ORDER BY
            CASE WHEN EXISTS (SELECT 1 FROM covers c2 WHERE c2.book_id = b.id AND c2.rejected_at IS NULL)
                 THEN 1 ELSE 0 END ASC,
            b.id ASC
          LIMIT ' . (int) $limit
    );
    $statement->execute([$ownerId, (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s')]);
    $books = $statement->fetchAll();

    $stats += [
        'looked_up' => 0, 'covers' => 0, 'metadata' => 0, 'misses' => 0,
        'unavailable' => 0, 'stopped_early' => false, 'stopped_quota' => null,
    ];

    /** @var array<string, true> sources that ran out today and were put aside */
    $retired = [];
    $deadline = $budgetSeconds > 0 ? microtime(true) + $budgetSeconds : null;

    foreach ($books as $book) {
        /* Stop on the clock rather than on a count.
         *
         * Each book costs a wait plus however long the sources take, so a
         * fixed number of books is a promise about time that nothing keeps.
         * The caller that matters here is a cron service with its own
         * patience, and the work resumes tomorrow exactly where it stopped. */
        if ($deadline !== null && microtime(true) >= $deadline) {
            $stats['stopped_early'] = true;
            break;
        }

        $isbn = (string) $book['isbn13'];
        $stats['looked_up']++;

        /* The free cover services before the metadata chain, not after it.
         *
         * They answer by ISBN alone - no key, no quota, one request - and the
         * chain keeps asking sources while a record is incomplete, which
         * counts a missing cover. A German record comes from the DNB and the
         * DNB has no cover images, so every German book was incomplete on
         * that one point and every German book spent one of Google's thousand
         * daily queries hunting a picture Google usually did not have either.
         * That is what stopped the run of 4 September after 837 books.
         *
         * Asked in this order, the query is spent only when it is metadata
         * that is genuinely missing. */
        $hasCover = $covers->bestFor((int) $book['id'], true) !== null;
        $service = $hasCover ? null : $finder->fromServices((int) $book['id'], $isbn);
        $coverInHand = $hasCover || ($service['stored'] ?? false);

        $outcome = $chain->find($isbn, true, $coverInHand);
        $found = $outcome['result'];
        $failures = $outcome['failures'];

        /* Out of quota is the one failure that waiting cannot fix - but it is
         * a fact about one source, and it used to end the whole run.
         *
         * That made sense while every cover came through the metadata chain.
         * It does not now: the free services answer by ISBN alone and share
         * nobody's quota, and on 5 September 78% of the books still without a
         * cover had one waiting at MVB. Ending the run over Google would have
         * left all of them blank for the sake of a source that was not going
         * to supply them anyway.
         *
         * So the source is retired for the rest of the run and the work goes
         * on without it. Retired is not the same as asked: a source that was
         * never put the question contributes no failure, and $retired below
         * keeps that from being written down as "nowhere has this book". */
        $quotaSource = LookupChain::quotaExhaustedSource($failures);
        if ($quotaSource !== null) {
            $stats['stopped_quota'] = LookupChain::quotaExhausted($failures)?->getMessage();
            $retired[$quotaSource] = true;
            $chain->retire($quotaSource);
            if ($verbose) {
                printf("  %-14s %-44s %s\n", $isbn, '', $quotaSource . ' is out for today - carrying on without it');
            }
        }

        /* Record the attempt, so a book with no record anywhere is not asked
         * about again every single night - but ONLY when the sources actually
         * answered. A miss caused by a source that was down is not a fact
         * about the book, and writing it down here is what used to lock the
         * book out for thirty days over a hiccup that lasted two seconds. */
        if ($found !== null || ($failures === [] && $retired === [])) {
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
        }

        if ($found === null) {
            $stats[$failures === [] ? 'misses' : 'unavailable']++;
            if ($verbose && $failures !== []) {
                foreach ($failures as $failure) {
                    printf("  %-14s %-44s %s\n", $isbn, '', $failure->getMessage());
                }
            }
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
            // The record changed, so say so. Writing the columns and leaving
            // updated_at at the import date makes the field a lie, and it is
            // the field a backup or a later sync would go by.
            $updates[] = 'updated_at = ?';
            $values[] = date('Y-m-d H:i:s');
            $values[] = $book['id'];
            $pdo->prepare('UPDATE books SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($values);
            $stats['metadata']++;
        }

        /* The other half of the same decision. The services have already been
         * asked above, so all that is left to try is the picture a metadata
         * source named - and asking the services a second time here would be
         * two more 404s per book for nothing. */
        if ($service !== null && $service['stored']) {
            $stats['covers']++;
        } elseif (!$hasCover && $finder->fromMetadata((int) $book['id'], $isbn, $found)['stored']) {
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

// Only when this file is the program being run - including it for its
// functions must not start a run of its own.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $options = getopt('', ['limit::', 'owner::', 'sqlite::', 'budget::', 'quiet', 'again']);
    $limit = max(1, min(1000, (int) ($options['limit'] ?? 100)));
    $budget = max(0, (int) ($options['budget'] ?? 0));
    $ownerId = (int) ($options['owner'] ?? 1);

    if (isset($options['sqlite']) && $options['sqlite'] !== false) {
        $pdo = new PDO('sqlite:' . $options['sqlite']);
        Database::sqliteDefaults($pdo);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        /* The real configuration, where there is one: --sqlite is meant to
           swap the database, not to throw the Google key away. It did exactly
           that, and enrichment then ran without Google, saying nothing. */
        try {
            $config = Config::load();
        } catch (Throwable $e) {
            $config = new Config(['api_contact' => '', 'google_books_key' => '']);
        }
    } else {
        $config = Config::load();
        $pdo = Database::connect($config);
    }

    try {
        Database::assertSchema(
            $pdo,
            isset($options['sqlite']) && $options['sqlite'] !== false
                ? (string) $options['sqlite']
                : Database::describe($config)
        );
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    $started = microtime(true);
    $stats = enrich($pdo, $config, $limit, $ownerId, !isset($options['quiet']), $budget, isset($options['again']));

    if ($stats['reopened'] > 0) {
        printf("%d book(s) that had been asked about recently were reopened.\n", $stats['reopened']);
    }

    printf(
        "\nLooked up %d | covers %d | details filled in %d | no match %d | source unavailable %d | %.1f s\n",
        $stats['looked_up'],
        $stats['covers'],
        $stats['metadata'],
        $stats['misses'],
        $stats['unavailable'],
        microtime(true) - $started
    );

    // Only worth a line when it did something. It is a one-off for almost
    // every shelf: after the first run there is nothing left to number.
    if ($stats['numbered'] > 0) {
        printf("%d book(s) carried only an ISBN-10 and now carry both.\n", $stats['numbered']);
    }

    if ($stats['stopped_quota'] !== null) {
        // Not "stopped" any more: the run carried on without that source, and
        // the covers it could not have supplied were fetched anyway. What is
        // left for tomorrow is the metadata behind it.
        echo "\nOut of quota: " . $stats['stopped_quota'] . "\n";
        echo "That source was set aside and the rest of the run went on without it.\n";
        echo "Nothing was written down as missing on its behalf. Run again tomorrow for the rest.\n";
    } elseif ($stats['stopped_early']) {
        echo "\nStopped: time budget reached. The next run continues where this one left off.\n";
    }
}
