<?php
/**
 * Look after the cover files.
 *
 *   php bin/covers.php                          what is stored, and how big it is
 *   php bin/covers.php --refresh                fetch the small ones again, bigger
 *   php bin/covers.php --refresh --limit=50     a few at a time
 *   php bin/covers.php --prune                  list files no cover row points at
 *   php bin/covers.php --prune --commit         and delete them
 *   php bin/covers.php --sqlite=storage/dev.sqlite   against the local database
 *
 * No metadata API is called here and no quota is spent: every address is
 * already recorded next to the cover it produced. This only fetches the
 * picture again - which is what makes it cheap enough to run over the whole
 * shelf after a change to how big an image is asked for.
 *
 * Covers are only ever replaced by a LARGER image. A source that has nothing
 * better to offer leaves what is already there alone, so running this twice
 * costs bandwidth and changes nothing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\CoverStorage;
use App\Core\Database;
use App\Lookup\GoogleBooksLookup;
use App\Repository\CoverRepository;

/** Below this width a cover is upscaled in the shelf grid, which is visible. */
const WANTED_WIDTH = 400;

$options = getopt('', ['refresh', 'prune', 'commit', 'limit::', 'owner::', 'min::', 'sqlite::']);
$ownerId = (int) ($options['owner'] ?? 1);
$limit = (int) ($options['limit'] ?? 0);
$minimum = (int) ($options['min'] ?? WANTED_WIDTH);

if (isset($options['sqlite']) && $options['sqlite'] !== false) {
    $pdo = new PDO('sqlite:' . $options['sqlite']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    Database::sqliteDefaults($pdo);
    $where = (string) $options['sqlite'];
} else {
    $config = Config::load();
    $pdo = Database::connect($config);
    $where = Database::describe($config);
}

try {
    Database::assertSchema($pdo, $where);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$directory = PROJECT_ROOT . '/public/covers';

report($pdo, $ownerId, $minimum);

if (isset($options['refresh'])) {
    refresh($pdo, $directory, $ownerId, $minimum, $limit);
}
if (isset($options['prune'])) {
    prune($pdo, $directory, isset($options['commit']));
}

/** What is stored, by source and by size. */
function report(PDO $pdo, int $ownerId, int $minimum): void
{
    $rows = $pdo->prepare(
        'SELECT c.source,
                COUNT(*) AS total,
                SUM(CASE WHEN c.width IS NULL OR c.width < ? THEN 1 ELSE 0 END) AS small,
                MIN(c.width) AS smallest,
                MAX(c.width) AS largest
           FROM covers c
           JOIN books b ON b.id = c.book_id
          WHERE b.owner_id = ? AND c.rejected_at IS NULL AND c.path IS NOT NULL
       GROUP BY c.source
       ORDER BY c.source'
    );
    $rows->execute([$minimum, $ownerId]);

    printf("%-14s %7s %7s %9s %9s\n", 'source', 'total', '< ' . $minimum, 'smallest', 'largest');
    $total = 0;
    $small = 0;
    foreach ($rows->fetchAll() as $row) {
        printf(
            "%-14s %7d %7d %9d %9d\n",
            $row['source'],
            (int) $row['total'],
            (int) $row['small'],
            (int) $row['smallest'],
            (int) $row['largest']
        );
        $total += (int) $row['total'];
        $small += (int) $row['small'];
    }
    printf("%-14s %7d %7d\n", 'together', $total, $small);
}

/**
 * Fetch the undersized covers again.
 *
 * Only the recorded address is used, so a cover that was found by hand and a
 * cover found by the nightly job are refreshed the same way. Google's address
 * is rewritten to ask for a usable size; the others already serve the largest
 * image they hold.
 */
function refresh(PDO $pdo, string $directory, int $ownerId, int $minimum, int $limit): void
{
    $storage = new CoverStorage($directory);
    $covers = new CoverRepository($pdo);

    $sql = 'SELECT c.book_id, c.source, c.external_url, c.attribution, c.width, b.isbn13, b.title
              FROM covers c
              JOIN books b ON b.id = c.book_id
             WHERE b.owner_id = ?
               AND c.rejected_at IS NULL
               AND c.external_url IS NOT NULL
               AND b.isbn13 IS NOT NULL
               AND (c.width IS NULL OR c.width < ?)
          ORDER BY c.width IS NULL DESC, c.width ASC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute([$ownerId, $minimum]);

    /* Only covers whose address has something better to offer.
     *
     * A source that hands out its largest image already - Open Library does -
     * would answer with the identical picture, so asking again is bandwidth
     * spent to learn nothing. Comparing the addresses says that without this
     * script having to know which source is which. */
    $work = [];
    $settled = 0;
    foreach ($statement->fetchAll() as $row) {
        $url = (string) $row['external_url'];
        if ((string) $row['source'] === CoverRepository::SOURCE_GOOGLE) {
            $url = GoogleBooksLookup::renditionUrl($url);
        }
        if ($url === (string) $row['external_url']) {
            $settled++;
            continue;
        }
        $row['fetch_url'] = $url;
        $work[] = $row;
    }

    printf(
        "\n%d covers to fetch again; %d are already at the largest size their source has.\n\n",
        count($work),
        $settled
    );

    $bigger = 0;
    $same = 0;
    $failed = 0;
    foreach ($work as $row) {
        $source = (string) $row['source'];
        $url = (string) $row['fetch_url'];

        try {
            $stored = $storage->storeRemote($url, $row['isbn13'] . '-' . $source);
        } catch (Throwable $e) {
            $failed++;
            printf("  ! %-40s %s\n", mb_substr((string) $row['title'], 0, 40), $e->getMessage());
            continue;
        }

        $was = (int) ($row['width'] ?? 0);
        if ($stored['width'] <= $was) {
            // Nothing gained. The file on disk has been rewritten with the
            // same picture, which is harmless; the row still describes it.
            $same++;
            continue;
        }

        $covers->save(
            (int) $row['book_id'],
            $source,
            $stored['path'],
            (string) $row['external_url'],
            $row['attribution'],
            $stored['width'],
            $stored['height']
        );
        $bigger++;
        printf("  %4d -> %4d  %s\n", $was, $stored['width'], mb_substr((string) $row['title'], 0, 50));
    }

    printf("\nLarger: %d, unchanged: %d, failed: %d\n", $bigger, $same, $failed);
}

/**
 * Delete cover files nothing points at.
 *
 * They come from renamings and from covers thrown out by hand: the row is
 * updated, the file stays behind. Only files under public/covers are
 * considered, and only ones no row names - a cover in use is never touched.
 *
 * Lists them unless --commit is given: deleting a photograph somebody took of
 * their own copy is not undoable, so it is asked for twice.
 */
function prune(PDO $pdo, string $directory, bool $commit): void
{
    $known = [];
    foreach ($pdo->query('SELECT path FROM covers WHERE path IS NOT NULL') as $row) {
        $path = (string) $row['path'];
        $known[$path] = true;
        // The grid copy is not recorded separately; it belongs to the same
        // cover and must not be mistaken for an orphan.
        $known[preg_replace('/\.webp$/', '-klein.webp', $path)] = true;
    }

    $orphans = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($walk as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'webp') {
            continue;
        }
        $relative = ltrim(str_replace($directory, '', $file->getPathname()), '/');
        if (!isset($known[$relative])) {
            $orphans[] = $file->getPathname();
        }
    }

    echo "\n" . count($orphans) . " orphaned files";
    echo $commit ? ":\n" : " (--commit to delete them):\n";
    foreach ($orphans as $path) {
        echo '  ' . basename($path) . "\n";
        if ($commit) {
            unlink($path);
        }
    }
}
