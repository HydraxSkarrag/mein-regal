<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Dialect;
use PDO;

/**
 * Cover images.
 *
 * Two kinds live here and they are not interchangeable. Photographs taken by
 * the owner are files we host, and they may be shown to anyone. Images from
 * Google or Open Library are LINKS - never copied - and are only rendered for
 * a signed-in visitor, because embedding them on a public page would hand
 * every visitor's IP address to a third party.
 *
 * is_public encodes that rule once, so no template has to remember it.
 */
final class CoverRepository
{
    public const SOURCE_OWN         = 'own';
    public const SOURCE_VLBTIX      = 'vlbtix';
    public const SOURCE_GOOGLE      = 'google';
    public const SOURCE_OPENLIBRARY = 'openlibrary';

    /** Sources we host ourselves and may therefore show to everyone. */
    private const PUBLIC_SOURCES = [self::SOURCE_OWN, self::SOURCE_VLBTIX];

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

    public static function isPublicSource(string $source): bool
    {
        return in_array($source, self::PUBLIC_SOURCES, true);
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
            self::isPublicSource($source) ? 1 : 0,
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
