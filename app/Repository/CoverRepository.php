<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Dialect;
use PDO;

/**
 * Cover images.
 *
 * What decides whether a cover may be shown to everyone is not where it came
 * from but whether we host it. An image on this server costs the visitor
 * nothing but a request to this server. An image embedded from Google or the
 * Internet Archive hands that visitor's IP address to a third party, which
 * would need consent - and a consent banner that has to be clicked before any
 * cover appears is worse than no covers at all.
 *
 * So: a row with a local path is public; a row with only an external URL is
 * not, and is rendered solely for the signed-in owner. The source is kept
 * either way, so attribution can be shown and a specific cover can be
 * withdrawn if a rights holder ever asks.
 *
 * is_public encodes that rule once, so no template has to remember it.
 */
final class CoverRepository
{
    public const SOURCE_OWN         = 'own';
    public const SOURCE_VLBTIX      = 'vlbtix';
    public const SOURCE_MVB         = 'mvb';
    public const SOURCE_GOOGLE      = 'google';
    public const SOURCE_OPENLIBRARY = 'openlibrary';


    /**
     * Preference order when a book has more than one cover.
     *
     * Provenance decides: a photograph of the actual copy first, then the
     * publishers' own files - VLB-TIX and the MVB directory are the same
     * pictures reached two different ways. The two free web sources share a
     * rank on purpose - they are equally permitted and equally attributed, so
     * there is nothing left to prefer between them except the picture itself,
     * and that is settled by size in compare().
     */
    private const PRIORITY = [
        self::SOURCE_OWN         => 0,
        self::SOURCE_VLBTIX      => 1,
        self::SOURCE_MVB         => 1,
        self::SOURCE_GOOGLE      => 2,
        self::SOURCE_OPENLIBRARY => 2,
    ];

    /**
     * Which of two covers to show. Negative means the first one.
     *
     * @param array{source: string, width?: ?int} $a
     * @param array{source: string, width?: ?int} $b
     */
    private static function compare(array $a, array $b): int
    {
        $rank = (self::PRIORITY[$a['source']] ?? 99) <=> (self::PRIORITY[$b['source']] ?? 99);
        if ($rank !== 0) {
            return $rank;
        }

        // Same standing, so the larger image wins - a 128 pixel thumbnail
        // next to a 500 pixel cover is not a matter of taste.
        return (int) ($b['width'] ?? 0) <=> (int) ($a['width'] ?? 0);
    }

    private readonly Dialect $dialect;

    public function __construct(private readonly PDO $pdo)
    {
        $this->dialect = new Dialect($pdo);
    }

    /** Hosting decides visibility, not provenance. */
    public static function isPublic(?string $path): bool
    {
        return $path !== null && $path !== '';
    }

    public function save(
        int $bookId,
        string $source,
        ?string $path,
        ?string $externalUrl,
        ?string $attribution = null,
        ?int $width = null,
        ?int $height = null,
    ): void {
        /* rejected_at is written as NULL and listed among the updated
         * columns on purpose: saving a cover is a deliberate act, and it
         * lifts an earlier rejection of the same source. Only the automatic
         * path consults the rejection before it gets this far.
         *
         * created_at is written every time as well, update included.
         *
         * A cover file is named after the book and its source, so replacing
         * the picture leaves the address unchanged - and covers are served
         * with a thirty day cache. Delete an own photograph, upload a better
         * one, and the browser goes on showing the deleted one: the file on
         * disk is new, the URL is not. The stored time is what CoverImage
         * hangs on the end of the address to say the picture changed, so it
         * has to mean "when this row's picture was stored" rather than "when
         * this book first got any cover at all". */
        $sql = $this->dialect->upsert(
            'covers',
            ['book_id', 'source', 'path', 'external_url', 'attribution', 'width', 'height', 'is_public', 'rejected_at', 'created_at'],
            ['book_id', 'source'],
            ['path', 'external_url', 'attribution', 'width', 'height', 'is_public', 'rejected_at', 'created_at']
        );

        $this->pdo->prepare($sql)->execute([
            $bookId,
            $source,
            $path,
            $externalUrl,
            $attribution,
            $width,
            $height,
            self::isPublic($path) ? 1 : 0,
            null,
            date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The best cover this viewer is allowed to see.
     *
     * @return array{source: string, path: ?string, external_url: ?string, attribution: ?string}|null
     */
    public function bestFor(int $bookId, bool $viewerIsSignedIn): ?array
    {
        $sql = 'SELECT source, path, external_url, attribution, width, created_at FROM covers'
            . ' WHERE book_id = ? AND rejected_at IS NULL';
        $parameters = [$bookId];
        if (!$viewerIsSignedIn) {
            $sql .= ' AND is_public = 1';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        /** @var list<array{source: string, path: ?string, external_url: ?string, attribution: ?string}> $rows */
        $rows = $statement->fetchAll();
        if ($rows === []) {
            return null;
        }

        usort($rows, self::compare(...));

        return $rows[0];
    }

    /** @return array<int, array{source: string, path: ?string, external_url: ?string, attribution: ?string}> keyed by book id */
    public function bestForMany(array $bookIds, bool $viewerIsSignedIn): array
    {
        if ($bookIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
        $sql = 'SELECT book_id, source, path, external_url, attribution, width, created_at FROM covers'
            . " WHERE book_id IN ($placeholders) AND rejected_at IS NULL";
        if (!$viewerIsSignedIn) {
            $sql .= ' AND is_public = 1';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values($bookIds));

        $best = [];
        foreach ($statement->fetchAll() as $row) {
            $bookId = (int) $row['book_id'];
            if (isset($best[$bookId]) && self::compare($best[$bookId], $row) <= 0) {
                continue;
            }
            unset($row['book_id']);
            $best[$bookId] = $row;
        }

        return $best;
    }

    /**
     * Throw a cover out, optionally just the one from a given source.
     *
     * The row is kept and marked rather than deleted. Deleting it made the
     * book cover-less, and the next run of the nightly job fetched the same
     * wrong image from the same source and put it back - so sorting bad
     * covers out by hand was only ever valid until the following night.
     *
     * What is blocked is the source for this book, not the book: it still
     * counts as having no cover everywhere that matters, so another source
     * may still be tried and an upload by hand still wins.
     *
     * Returns the stored paths so the caller can unlink the files - the
     * repository owns rows, not the filesystem.
     *
     * @return list<string>
     */
    public function remove(int $bookId, ?string $source = null): array
    {
        $sql = 'SELECT path FROM covers WHERE book_id = ? AND path IS NOT NULL AND rejected_at IS NULL';
        $parameters = [$bookId];
        if ($source !== null) {
            $sql .= ' AND source = ?';
            $parameters[] = $source;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $paths = array_values(array_filter(
            array_map(static fn (array $row): string => (string) $row['path'], $statement->fetchAll())
        ));

        $reject = 'UPDATE covers SET rejected_at = ?, path = NULL, external_url = NULL, is_public = 0'
            . ' WHERE book_id = ? AND rejected_at IS NULL';
        $rejectParameters = [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $bookId];
        if ($source !== null) {
            $reject .= ' AND source = ?';
            $rejectParameters[] = $source;
        }
        $this->pdo->prepare($reject)->execute($rejectParameters);

        return $paths;
    }

    /**
     * Sources this book has already been offered and had thrown out.
     *
     * @return list<string>
     */
    public function rejectedSources(int $bookId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT source FROM covers WHERE book_id = ? AND rejected_at IS NOT NULL'
        );
        $statement->execute([$bookId]);

        return array_map(
            static fn (array $row): string => (string) $row['source'],
            $statement->fetchAll()
        );
    }

    public function countBySource(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.source, COUNT(*) AS n
               FROM covers c JOIN books b ON b.id = c.book_id
              WHERE b.owner_id = ? AND c.rejected_at IS NULL
              GROUP BY c.source'
        );
        $statement->execute([$ownerId]);

        return array_column($statement->fetchAll(), 'n', 'source');
    }
}
