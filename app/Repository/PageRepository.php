<?php
declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Editable prose pages.
 *
 * Exists so the answer to "what is this and whose books are these" is not
 * baked into a template. A second collection then introduces itself in its
 * own words without anyone touching the source.
 */
final class PageRepository
{
    public const ABOUT = 'ueber';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{title: string, body: ?string, updated_at: string}|null */
    public function find(int $ownerId, string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT title, body, updated_at FROM pages WHERE owner_id = ? AND slug = ?'
        );
        $statement->execute([$ownerId, $slug]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function save(int $ownerId, string $slug, string $title, ?string $body): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $update = $this->pdo->prepare(
            'UPDATE pages SET title = ?, body = ?, updated_at = ? WHERE owner_id = ? AND slug = ?'
        );
        $update->execute([$title, $body, $now, $ownerId, $slug]);

        if ($update->rowCount() > 0) {
            return;
        }
        // Nothing to update, or the text was saved unchanged - either way, make
        // sure a row exists before giving up.
        if ($this->find($ownerId, $slug) !== null) {
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO pages (owner_id, slug, title, body, updated_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$ownerId, $slug, $title, $body, $now]);
    }
}
