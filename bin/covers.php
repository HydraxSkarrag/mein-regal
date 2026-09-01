<?php
/**
 * Look after the cover files.
 *
 *   php bin/covers.php                          what is stored, and how big it is
 *   php bin/covers.php --refresh                fetch the small ones again, bigger
 *   php bin/covers.php --refresh --limit=50     a few at a time
 *   php bin/covers.php --prune                  list files no cover row points at
 *   php bin/covers.php --prune --commit         and delete them, except any that
 *                                               hold a larger picture than the
 *                                               book currently shows
 *   php bin/covers.php --prune --commit --all   delete those as well
 *   php bin/covers.php --sqlite=storage/dev.sqlite   against the local database
 *
 * No metadata API is called here and no quota is spent: every address is
 * already recorded next to the cover it produced. This only fetches the
 * picture again - which is what makes it cheap enough to run over the whole
 * shelf after a change to how big an image is asked for.
 *
 * Covers are only ever replaced by a LARGER image. A source that has nothing
 * better to offer leaves what is already there alone, and the address that was
 * actually fetched is written down, so a second run skips everything it has
 * already asked about instead of downloading it again to learn nothing.
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

$options = getopt('', ['refresh', 'prune', 'commit', 'all', 'limit::', 'owner::', 'min::', 'sqlite::']);
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
    prune($pdo, $directory, isset($options['commit']), isset($options['all']));
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
            // Nothing was written: storeFile checks the picture before it
            // touches the disk, so the cover already there is untouched.
            $failed++;
            printf("  ! %-40s %s\n", mb_substr((string) $row['title'], 0, 40), whyNot($e->getMessage()));
            continue;
        }

        $was = (int) ($row['width'] ?? 0);

        /* Record what was actually fetched, every time.
         *
         * The file on disk has been rewritten by now, so the row has to
         * describe the new picture even when it is no larger - width and
         * height end up in the img tag, and wrong ones move the page about
         * while it loads.
         *
         * Storing the address that was fetched is also what makes a second
         * run cheap: it now matches what the rule would ask for, so the
         * cover counts as settled and is not downloaded again for nothing. */
        $covers->save(
            (int) $row['book_id'],
            $source,
            $stored['path'],
            $url,
            $row['attribution'],
            $stored['width'],
            $stored['height']
        );

        if ($stored['width'] <= $was) {
            $same++;
            continue;
        }

        $bigger++;
        printf("  %4d -> %4d  %s\n", $was, $stored['width'], mb_substr((string) $row['title'], 0, 50));
    }

    printf("\nLarger: %d, unchanged: %d, failed: %d\n", $bigger, $same, $failed);
}

/**
 * Why a cover was left as it was, in words rather than in exception messages.
 *
 * These lines are read by whoever ran the command, and every one of them is a
 * decision the code made on their behalf - "placeholder" is not a fault, it is
 * the guard refusing to swap a printed cover for Google's grey filler.
 */
function whyNot(string $message): string
{
    if ($message === 'placeholder') {
        return 'the bigger version is Google\'s grey "image not available" - kept the small one';
    }
    if (str_contains($message, 'status 404')) {
        return 'the source does not have this image any more - kept what is there';
    }

    return $message . ' - kept what is there';
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
function prune(PDO $pdo, string $directory, bool $commit, bool $all): void
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

    if ($orphans === []) {
        echo "\nNo orphaned files.\n";

        return;
    }

    /* Group the two files of one cover back together.
     *
     * Every cover is stored twice - the detail image and the grid copy beside
     * it - so a list of files reads as twice as much loss as there is. They
     * are one decision, and they are shown as one. */
    $groups = [];
    foreach ($orphans as $path) {
        $base = preg_replace('/-klein\.webp$/', '.webp', basename($path));
        $groups[$base][] = $path;
    }

    printf(
        "\n%d orphaned files, %d covers%s\n\n",
        count($orphans),
        count($groups),
        $commit ? '' : ' (--commit to delete them)'
    );

    $lookup = $pdo->prepare(
        'SELECT b.title, c.path, c.source, c.width, c.rejected_at
           FROM books b
      LEFT JOIN covers c ON c.book_id = b.id
          WHERE b.isbn13 = ?
       ORDER BY c.rejected_at IS NULL DESC'
    );

    $tally = [];
    $verdicts = [];
    $totalBytes = 0;

    foreach ($groups as $base => $files) {
        $bytes = array_sum(array_map('filesize', $files));

        // The file name starts with the ISBN it was stored under, which is
        // what makes it possible to say whose cover this was.
        preg_match('/^(\d{10,13})/', $base, $match);
        $rows = [];
        if ($match !== []) {
            $lookup->execute([$match[1]]);
            $rows = $lookup->fetchAll();
        }

        [$verdict, $line] = explain($rows, $files[0], $directory);
        $tally[$verdict] = ($tally[$verdict] ?? 0) + 1;
        $totalBytes += $bytes;
        $verdicts[$base] = $verdict;

        printf("  %-34s %2d files, %5d KB\n", $base, count($files), (int) ($bytes / 1024));
        echo '    ' . $line . "\n";
    }

    /* The one line somebody actually decides on. Above it stands what each
       file was; here stands what the whole heap amounts to. */
    echo "\n";
    foreach ([
        'identical' => 'the same picture the book already shows, byte for byte',
        'replaced'  => 'superseded by a different image of the same book',
        'larger'    => 'LARGER than the cover the book shows - look before deleting',
        'removed'   => 'belonging to a book whose cover was thrown out by hand',
        'unknown'   => 'belonging to no book in the catalogue any more',
    ] as $verdict => $meaning) {
        if (isset($tally[$verdict])) {
            printf("  %3d %s\n", $tally[$verdict], $meaning);
        }
    }
    printf("  %3d files, %d KB in total\n", count($orphans), (int) ($totalBytes / 1024));

    if (!$commit) {
        return;
    }

    /* A leftover that is larger than the cover on show is not rubbish, it is
       a better picture nobody is looking at. Deleting it is still allowed -
       with --all - but not as a side effect of tidying up. */
    $deleted = 0;
    $kept = 0;
    foreach ($groups as $base => $files) {
        if (!$all && ($verdicts[$base] ?? '') === 'larger') {
            $kept++;
            continue;
        }
        foreach ($files as $path) {
            unlink($path);
            $deleted++;
        }
    }

    printf("\nDeleted %d files.\n", $deleted);
    if ($kept > 0) {
        printf("Kept %d covers that are larger than what the book shows (--all deletes those too).\n", $kept);
    }
}

/**
 * Why this file is lying about, in one line.
 *
 * Enough to decide by: whose cover it was, what that book shows instead, and
 * whether the picture is the same one. A file whose content is byte for byte
 * what the book already displays is the easy case, and it is worth saying so
 * rather than leaving it to be guessed.
 *
 * @param  list<array<string,mixed>> $rows the book and its covers, if found
 * @return array{0: string, 1: string} what kind of leftover, and the line
 */
function explain(array $rows, string $file, string $directory): array
{
    if ($rows === []) {
        return ['unknown', 'no book with this ISBN - left over from a book that was deleted'];
    }

    $title = mb_substr((string) $rows[0]['title'], 0, 44);
    $current = null;
    foreach ($rows as $row) {
        if ($row['path'] !== null && $row['rejected_at'] === null) {
            $current = $row;
            break;
        }
    }

    if ($current === null) {
        return ['removed', $title . ' - has no cover now (thrown out by hand)'];
    }

    /* The stored path, not a name next to the orphan: covers are sharded by
       a hash of their own file name, so the cover in use for the same book
       usually sits in a different directory. */
    $inUse = $directory . '/' . $current['path'];
    $same = is_file($inUse) && md5_file($inUse) === md5_file($file);

    if ($same) {
        return [
            'identical',
            sprintf(
                '%s - now shows %s (%s, %dpx), the same picture byte for byte',
                $title,
                basename((string) $current['path']),
                $current['source'],
                (int) $current['width']
            ),
        ];
    }

    /* A different picture is not automatically a worse one to lose. If the
       file lying about is the bigger of the two, that is worth saying out
       loud before anybody deletes it. */
    $size = @getimagesize($file);
    $orphanWidth = $size === false ? 0 : (int) $size[0];
    $note = $orphanWidth > (int) $current['width']
        ? sprintf(' - the loose file is the larger one at %dpx', $orphanWidth)
        : '';

    return [
        $note === '' ? 'replaced' : 'larger',
        sprintf(
            '%s - now shows %s (%s, %dpx)%s',
            $title,
            basename((string) $current['path']),
            $current['source'],
            (int) $current['width'],
            $note
        ),
    ];
}
