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
    public const ABOUT   = 'about';
    public const IMPRINT = 'imprint';
    public const PRIVACY = 'privacy';

    /**
     * The pages the owner may write, and the order they appear in.
     *
     * A fixed list rather than an open slug: a page whose address anyone can
     * invent is a page anyone can create, and these three are the ones the
     * navigation links to.
     *
     * @var list<string>
     */
    public const EDITABLE = [self::ABOUT, self::IMPRINT, self::PRIVACY];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The page in one language.
     *
     * No falling back to another language: a German paragraph appearing under
     * an English heading looks like a fault, and the empty state at least says
     * plainly that nothing has been written yet.
     *
     * @return array{title: string, body: ?string, locale: string, updated_at: string}|null
     */
    public function find(int $ownerId, string $slug, string $locale): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT title, body, locale, updated_at FROM pages
              WHERE owner_id = ? AND slug = ? AND locale = ?'
        );
        $statement->execute([$ownerId, $slug, $locale]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The page in the asked-for language, or in whatever language it exists.
     *
     * Only for the legal pages. There, showing nothing is not an option -
     * an Impressum that is missing because the interface happens to be in
     * English is still a missing Impressum - so the German original stands in
     * until someone writes a translation. The about page deliberately does
     * not do this: a German paragraph under an English heading reads like a
     * fault, and saying "not written yet" is more honest.
     *
     * @return array{title: string, body: ?string, locale: string, updated_at: string}|null
     */
    public function findAnyLocale(int $ownerId, string $slug, string $preferred): ?array
    {
        $page = $this->find($ownerId, $slug, $preferred);
        if ($page !== null) {
            return $page;
        }

        $statement = $this->pdo->prepare(
            'SELECT title, body, locale, updated_at FROM pages
              WHERE owner_id = ? AND slug = ? AND body IS NOT NULL AND body <> \'\'
              ORDER BY locale LIMIT 1'
        );
        $statement->execute([$ownerId, $slug]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** Which languages this page has been written in. @return list<string> */
    public function localesFor(int $ownerId, string $slug): array
    {
        $statement = $this->pdo->prepare(
            'SELECT locale FROM pages WHERE owner_id = ? AND slug = ? AND body IS NOT NULL AND body <> \'\' ORDER BY locale'
        );
        $statement->execute([$ownerId, $slug]);

        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * The legal pages that are not finished yet.
     *
     * Finished means: written at all, and with no ⚠ left in it. The seeded
     * texts mark every gap that way and open by saying they are a draft, so
     * the marker is a reliable answer to "has anybody been through this".
     *
     * Only the two legal ones. An empty About page is a page somebody has
     * not got round to; an unfinished Impressum is a different kind of thing.
     *
     * @return list<string> slugs, in the order they are listed
     */
    public function unfinishedLegal(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT slug, body FROM pages WHERE owner_id = ? AND slug IN (?, ?)'
        );
        $statement->execute([$ownerId, self::IMPRINT, self::PRIVACY]);

        $written = [];
        foreach ($statement->fetchAll() as $row) {
            $slug = (string) $row['slug'];
            $body = (string) ($row['body'] ?? '');
            // Any language: a gap left in one of them is still a gap.
            $written[$slug] = ($written[$slug] ?? true) && !str_contains($body, '⚠');
        }

        $open = [];
        foreach ([self::IMPRINT, self::PRIVACY] as $slug) {
            if (($written[$slug] ?? false) === false) {
                $open[] = $slug;
            }
        }

        return $open;
    }

    public function save(int $ownerId, string $slug, string $locale, string $title, ?string $body): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $update = $this->pdo->prepare(
            'UPDATE pages SET title = ?, body = ?, updated_at = ?
              WHERE owner_id = ? AND slug = ? AND locale = ?'
        );
        $update->execute([$title, $body, $now, $ownerId, $slug, $locale]);

        if ($update->rowCount() > 0) {
            return;
        }
        // Nothing changed, or nothing was there - only the second needs an
        // insert, and rowCount cannot tell the two apart.
        if ($this->find($ownerId, $slug, $locale) !== null) {
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO pages (owner_id, slug, locale, title, body, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$ownerId, $slug, $locale, $title, $body, $now]);
    }
}
