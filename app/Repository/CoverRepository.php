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
    public const SOURCE_GOOGLE      = 'google';
    public const SOURCE_OPENLIBRARY = 'openlibrary';


    /** Preference order when a book has more than one cover. */
    private const PRIORITY = [
        self::SOURCE_OWN         => 0,
        self::SOURCE_VLBTIX      => 1,
        self::SOURCE_GOOGLE      => 2,
        self::SOURCE_OPENLIBRARY => 3,
    ];

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
        $sql = $this->dialect->upsert(
            'covers',
            ['book_id', 'source', 'path', 'external_url', 'attribution', 'width', 'height', 'is_public'],
            ['book_id', 'source'],
            ['path', 'external_url', 'attribution', 'width', 'height', 'is_public']
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
        ]);
    }

    /**
     * The best cover this viewer is allowed to see.
     *
     * @return array{source: string, path: ?string, external_url: ?string, attribution: ?string}|null
     */
    public function bestFor(int $bookId, bool $viewerIsSignedIn): ?array
    {
        $sql = 'SELECT source, path, external_url, attribution FROM covers WHERE book_id = ?';
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

        usort(
            $rows,
            static fn (array $a, array $b): int =>
                (self::PRIORITY[$a['source']] ?? 99) <=> (self::PRIORITY[$b['source']] ?? 99)
        );

        return $rows[0];
    }

    /** @return array<int, array{source: string, path: ?string, external_url: ?string, attribution: ?string}> keyed by book id */
    public function bestForMany(array $bookIds, bool $viewerIsSignedIn): array
    {
        if ($bookIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
        $sql = "SELECT book_id, source, path, external_url, attribution FROM covers WHERE book_id IN ($placeholders)";
        if (!$viewerIsSignedIn) {
            $sql .= ' AND is_public = 1';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values($bookIds));

        $best = [];
        foreach ($statement->fetchAll() as $row) {
            $bookId = (int) $row['book_id'];
            $rank = self::PRIORITY[$row['source']] ?? 99;
            if (isset($best[$bookId]) && (self::PRIORITY[$best[$bookId]['source']] ?? 99) <= $rank) {
                continue;
            }
            unset($row['book_id']);
            $best[$bookId] = $row;
        }

        return $best;
    }

    public function countBySource(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.source, COUNT(*) AS n
               FROM covers c JOIN books b ON b.id = c.book_id
              WHERE b.owner_id = ?
              GROUP BY c.source'
        );
        $statement->execute([$ownerId]);

        return array_column($statement->fetchAll(), 'n', 'source');
    }
}
