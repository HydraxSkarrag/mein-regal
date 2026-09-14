<?php
declare(strict_types=1);

namespace App\Content;

use App\Core\Text;
use App\Repository\BookRepository;
use App\Repository\TagRepository;

/**
 * Genres and labels for a whole shelf at once, from a file.
 *
 * Written for the day a shelf's tags are redone from scratch: ninety imported
 * labels replaced by a list somebody thought through, book by book, in a
 * spreadsheet. The file is the export's own shape - id, isbn13, title, genres,
 * labels, several names to a cell with semicolons - so a full export read back
 * in changes nothing, and one taken just before is the way back.
 *
 * Replacing, not adding. A book named in the file ends up with exactly the
 * genres and labels of its row; one that is not named is not touched. Books
 * are found by id, because a book without an ISBN has nothing else that
 * cannot repeat - and the ISBN, where the row has one, has to agree, so a
 * file from another shelf is refused rather than applied to whichever books
 * happen to carry the same numbers there.
 *
 * Names that already exist are used, not created a second time. That is not
 * a nicety: "remove the old ones, then add the new ones" fails on exactly the
 * names the two lists share, because a removed tag cannot be put on a book
 * and removing it first would take the new one with it. So an existing name
 * is taken as it is - brought back if it was removed, given the kind the file
 * says, spelled the way the file spells it.
 *
 * Tags the file does not mention and that no book outside the file carries
 * are removed afterwards, the way the × removes them: hidden, with their links
 * kept, restorable. Nothing here deletes a tag for good.
 *
 * Planned first and applied second, with the plan shown in between. Applying
 * plans again from the same file and refuses if the result differs from what
 * was shown - somebody may have edited a book in another tab.
 */
final class TagAssignment
{
    private const REQUIRED = ['id', 'genres', 'labels'];

    /**
     * The rows of the file, or why there are none.
     *
     * UTF-8 as the export writes it, or Windows-1252 as a spreadsheet saving
     * it back on some systems does; comma as the export separates, or
     * semicolon as a German spreadsheet does.
     *
     * @return array{rows: list<array{line: int, id: string, isbn13: string, title: string,
     *                                genres: list<string>, labels: list<string>}>,
     *               error: ?string}
     */
    public static function read(string $contents): array
    {
        if (!mb_check_encoding($contents, 'UTF-8')) {
            $converted = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
            $contents = is_string($converted) ? $converted : $contents;
        }
        $contents = preg_replace('/^\x{FEFF}/u', '', $contents) ?? $contents;

        $firstLine = strtok($contents, "\n");
        $delimiter = substr_count((string) $firstLine, ';') > substr_count((string) $firstLine, ',') ? ';' : ',';

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return ['rows' => [], 'error' => 'tags.assign.unreadable'];
        }
        fwrite($handle, $contents);
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter, '"', '');
        $header = is_array($header) ? array_map(static fn ($v): string => strtolower(trim((string) $v)), $header) : [];
        if (array_diff(self::REQUIRED, $header) !== []) {
            fclose($handle);

            return ['rows' => [], 'error' => 'tags.assign.columns'];
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                continue;
            }
            $values = array_pad(array_slice($values, 0, count($header)), count($header), '');
            $cells = array_combine($header, array_map(static fn ($v): string => trim((string) $v), $values));

            $rows[] = [
                'line'   => $line,
                'id'     => $cells['id'],
                'isbn13' => preg_replace('/\D+/', '', $cells['isbn13'] ?? '') ?? '',
                'title'  => $cells['title'] ?? '',
                'genres' => self::names($cells['genres']),
                'labels' => self::names($cells['labels']),
            ];
        }
        fclose($handle);

        return ['rows' => $rows, 'error' => $rows === [] ? 'tags.assign.empty' : null];
    }

    /**
     * Everything applying the file would do, without doing any of it.
     *
     * @param list<array{line: int, id: string, isbn13: string, title: string, genres: list<string>, labels: list<string>}> $rows
     * @return array{
     *     rejected: list<array{line: int, title: string, reason: string}>,
     *     conflicts: list<string>,
     *     names: array<string, array{name: string, kind: string, id: ?int, was: ?array{name: string, kind: string, dropped: bool}}>,
     *     books: list<array{id: int, title: string, before: array{genres: list<string>, labels: list<string>},
     *                       after: array{genres: list<string>, labels: list<string>}, changed: bool}>,
     *     drop: list<array{id: int, name: string, kind: string, count: int}>,
     *     outside: int
     * }
     */
    public static function plan(BookRepository $books, TagRepository $tagRepository, int $ownerId, array $rows): array
    {
        $shelf = $books->identities($ownerId);
        $tags = $tagRepository->allWithState($ownerId);
        $bySlug = [];
        foreach ($tags as $id => $tag) {
            $bySlug[(string) $tag['slug']] = $id;
        }
        $links = $tagRepository->linksByBook($ownerId);

        $rejected = [];
        $accepted = [];
        foreach ($rows as $row) {
            $id = ctype_digit($row['id']) ? (int) $row['id'] : 0;
            $book = $shelf[$id] ?? null;
            $reason = match (true) {
                $id === 0                  => 'tags.assign.reject.id',
                $book === null             => 'tags.assign.reject.unknown',
                isset($accepted[$id])      => 'tags.assign.reject.twice',
                $row['isbn13'] !== '' && (string) ($book['isbn13'] ?? '') !== ''
                    && $row['isbn13'] !== (string) $book['isbn13'] => 'tags.assign.reject.isbn',
                default                    => null,
            };
            if ($reason !== null) {
                $rejected[] = ['line' => $row['line'], 'title' => $row['title'] !== '' ? $row['title'] : (string) ($book['title'] ?? ''), 'reason' => $reason];
                continue;
            }
            $accepted[$id] = $row;
        }

        /* One kind per name, for the whole shelf. A name that is a genre in
           one row and a label in the next cannot be both, and guessing which
           was meant is the one thing this must not do. */
        $names = [];
        $kinds = [];
        foreach ($accepted as $row) {
            foreach (['genres' => TagRepository::KIND_GENRE, 'labels' => TagRepository::KIND_LABEL] as $column => $kind) {
                foreach ($row[$column] as $name) {
                    $slug = Text::slug($name, 190);
                    $kinds[$slug][$kind] = $name;
                    $names[$slug] ??= $name;
                }
            }
        }
        $conflicts = [];
        foreach ($kinds as $slug => $both) {
            if (count($both) > 1) {
                $conflicts[] = $names[$slug];
            }
        }

        $planned = [];
        foreach ($names as $slug => $name) {
            $id = $bySlug[$slug] ?? null;
            $planned[$slug] = [
                'name' => $name,
                'kind' => isset($kinds[$slug][TagRepository::KIND_GENRE]) ? TagRepository::KIND_GENRE : TagRepository::KIND_LABEL,
                'id'   => $id,
                'was'  => $id === null ? null : [
                    'name'    => (string) $tags[$id]['name'],
                    'kind'    => (string) $tags[$id]['kind'],
                    'dropped' => $tags[$id]['dropped_at'] !== null,
                ],
            ];
        }
        $namedIds = array_filter(array_column($planned, 'id'));

        $books = [];
        foreach ($accepted as $id => $row) {
            $before = ['genres' => [], 'labels' => []];
            foreach ($links[$id] ?? [] as $tagId) {
                $tag = $tags[$tagId] ?? null;
                if ($tag !== null && $tag['dropped_at'] === null) {
                    $before[$tag['kind'] === TagRepository::KIND_GENRE ? 'genres' : 'labels'][] = (string) $tag['name'];
                }
            }
            $after = ['genres' => [], 'labels' => []];
            foreach ($row['genres'] as $name) {
                $after[$planned[Text::slug($name, 190)]['kind'] === TagRepository::KIND_GENRE ? 'genres' : 'labels'][] = $planned[Text::slug($name, 190)]['name'];
            }
            foreach ($row['labels'] as $name) {
                $after[$planned[Text::slug($name, 190)]['kind'] === TagRepository::KIND_GENRE ? 'genres' : 'labels'][] = $planned[Text::slug($name, 190)]['name'];
            }
            foreach ([&$before, &$after] as &$sets) {
                foreach ($sets as &$list) {
                    $list = array_values(array_unique($list));
                    sort($list, SORT_STRING | SORT_FLAG_CASE);
                }
                unset($list);
            }
            unset($sets);

            $books[] = [
                'id'      => $id,
                'title'   => (string) $shelf[$id]['title'],
                'before'  => $before,
                'after'   => $after,
                // A hidden link of a tag coming back counts as a change even
                // where the visible lists agree, or it would stay behind.
                'changed' => $before !== $after || array_diff(
                    array_intersect($links[$id] ?? [], $namedIds),
                    self::idsFor($row, $planned)
                ) !== [],
            ];
        }

        /* Removed afterwards: in use today, not in the file, and carried by no
           book the file leaves alone. A tag still on a book outside the file
           stays - it is only taken off the books inside it. */
        $carriers = [];
        foreach ($links as $bookId => $tagIds) {
            foreach ($tagIds as $tagId) {
                $carriers[$tagId][] = $bookId;
            }
        }
        $drop = [];
        if ($accepted !== []) {
            foreach ($tags as $tagId => $tag) {
                if ($tag['dropped_at'] !== null || in_array($tagId, $namedIds, true) || !isset($carriers[$tagId])) {
                    continue;
                }
                if (array_diff($carriers[$tagId], array_keys($accepted)) === []) {
                    $drop[] = ['id' => $tagId, 'name' => (string) $tag['name'], 'kind' => (string) $tag['kind'], 'count' => count($carriers[$tagId])];
                }
            }
            usort($drop, static fn (array $a, array $b): int => Text::fold($a['name']) <=> Text::fold($b['name']));
        }

        uksort($planned, static fn (string $a, string $b): int => Text::fold($planned[$a]['name']) <=> Text::fold($planned[$b]['name']));

        return [
            'rejected'  => $rejected,
            'conflicts' => $conflicts,
            'names'     => $planned,
            'books'     => $books,
            'drop'      => $drop,
            'outside'   => count(array_diff_key($shelf, $accepted)),
        ];
    }

    /** What the plan is, as one value: applying refuses when it has moved. */
    public static function fingerprint(array $plan): string
    {
        return hash('sha256', (string) json_encode($plan));
    }

    /** Whether there is anything to write, and nothing standing in the way. */
    public static function canApply(array $plan): bool
    {
        return $plan['conflicts'] === []
            && ($plan['drop'] !== [] || array_filter(array_column($plan['books'], 'changed')) !== []
                || array_filter($plan['names'], static fn (array $n): bool => self::nameChanges($n)) !== []);
    }

    /** Whether a name in the file does anything to the tag it names. */
    public static function nameChanges(array $name): bool
    {
        return $name['was'] === null
            || $name['was']['dropped']
            || $name['was']['kind'] !== $name['kind']
            || $name['was']['name'] !== $name['name'];
    }

    /**
     * Carry the plan out, all of it or nothing.
     *
     * @return array{books: int, created: int, dropped: int}
     */
    public static function apply(TagRepository $tags, int $ownerId, array $plan): array
    {
        if (!self::canApply($plan)) {
            return ['books' => 0, 'created' => 0, 'dropped' => 0];
        }

        return $tags->atomically(static function () use ($tags, $ownerId, $plan): array {
            $created = 0;
            $booksChanged = 0;
            // The names first, so every tag the books need is in use.
            foreach ($plan['names'] as $slug => $name) {
                if ($name['id'] === null) {
                    $isNew = null;
                    $plan['names'][$slug]['id'] = $tags->findOrCreate($ownerId, $name['name'], $isNew, $name['kind']);
                    $created++;
                    continue;
                }
                if ($name['was']['dropped']) {
                    $tags->restore($ownerId, $name['id']);
                }
                if ($name['was']['kind'] !== $name['kind']) {
                    $tags->setKind($ownerId, $name['id'], $name['kind']);
                }
                if ($name['was']['name'] !== $name['name']) {
                    $tags->rename($ownerId, $name['id'], $name['name']);
                }
            }

            $dropIds = array_column($plan['drop'], 'id');

            foreach ($plan['books'] as $book) {
                if (!$book['changed']) {
                    continue;
                }
                $wanted = [];
                foreach (array_merge($book['after']['genres'], $book['after']['labels']) as $name) {
                    $wanted[] = (int) $plan['names'][Text::slug($name, 190)]['id'];
                }

                /* Off the book: every tag in use that it should not have.
                   Left alone: the tags being removed, whose links are what
                   makes restoring them possible, and tags that were already
                   removed before and are not coming back. Read after the
                   names above, so a tag brought back counts as in use. */
                foreach ($tags->linksOf($ownerId, $book['id']) as $link) {
                    if (in_array($link['tag_id'], $wanted, true) || in_array($link['tag_id'], $dropIds, true) || $link['dropped']) {
                        continue;
                    }
                    $tags->unlink($ownerId, $book['id'], $link['tag_id']);
                }
                foreach ($wanted as $tagId) {
                    $tags->link($book['id'], $tagId);
                }
                $booksChanged++;
            }

            foreach ($dropIds as $tagId) {
                $tags->drop($ownerId, $tagId);
            }

            return ['books' => $booksChanged, 'created' => $created, 'dropped' => count($dropIds)];
        });
    }

    /** @return list<string> */
    private static function names(string $cell): array
    {
        $names = [];
        foreach (explode(';', $cell) as $name) {
            $name = trim($name);
            if ($name !== '' && !in_array(Text::slug($name, 190), array_map(static fn (string $n): string => Text::slug($n, 190), $names), true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return list<int> the ids of the named tags a row asks for */
    private static function idsFor(array $row, array $planned): array
    {
        $ids = [];
        foreach (array_merge($row['genres'], $row['labels']) as $name) {
            $id = $planned[Text::slug($name, 190)]['id'] ?? null;
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
