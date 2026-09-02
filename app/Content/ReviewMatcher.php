<?php
declare(strict_types=1);

namespace App\Content;

use App\Core\Text;

/**
 * Matching a blog's posts to the books they are about.
 *
 * The shelf exists next to a book blog, and a book that has been written
 * about is a different thing from one that has not - it is the working queue
 * the whole catalogue is for. The link between the two was typed in by hand,
 * which meant one book out of three thousand had one.
 *
 * Three kinds of evidence, in order of how much they are worth:
 *
 *   1. the ISBN in the post's text - exact, and needs no guessing at all
 *   2. title and author together - the post titles are written to a pattern,
 *      "Author: Title. Publisher, City Year", so both can be read out of it
 *   3. nothing conclusive, which is left alone rather than guessed at
 *
 * Deliberately free of HTTP: everything here works on text that has already
 * been fetched, so the matching can be tested without a blog to ask.
 */
final class ReviewMatcher
{
    /** @var array<string, int> ISBN-13 to book id */
    private array $byIsbn = [];
    /** @var array<string, list<int>> folded title to book ids */
    private array $byTitle = [];
    /** @var array<string, list<int>> folded title, cut before any subtitle */
    private array $byHead = [];
    /** @var array<int, list<string>> book id to its authors' match keys */
    private array $authors = [];

    /**
     * @param list<array{id: int, isbn13: ?string, title: string, authors: list<string>}> $books
     */
    public function __construct(array $books)
    {
        foreach ($books as $book) {
            $id = (int) $book['id'];
            if (($book['isbn13'] ?? null) !== null && $book['isbn13'] !== '') {
                $this->byIsbn[(string) $book['isbn13']] = $id;
            }
            $this->byTitle[self::key($book['title'])][] = $id;
            $this->byHead[self::head($book['title'])][] = $id;
            $this->authors[$id] = array_map(
                static fn (string $name): string => Text::authorMatchKey($name),
                $book['authors']
            );
        }
    }

    /**
     * What a post is about, as far as it can be told.
     *
     * @param  array{title: string, content: string} $post
     * @return array{book_id: ?int, how: string, isbns: list<string>, work: string, authors: list<string>}
     */
    public function match(array $post): array
    {
        $isbns = self::isbns($post['content']);
        ['work' => $work, 'authors' => $people] = self::parseTitle($post['title']);

        foreach ($isbns as $isbn) {
            if (isset($this->byIsbn[$isbn])) {
                return [
                    'book_id' => $this->byIsbn[$isbn],
                    'how'     => 'isbn',
                    'isbns'   => $isbns,
                    'work'    => $work,
                    'authors' => $people,
                ];
            }
        }

        $candidates = $this->byTitle[self::key($work)] ?? $this->byHead[self::head($work)] ?? [];

        /* One candidate whose author agrees, and only one.
         *
         * Series volumes share a title often enough - "Band 1" lives in the
         * subtitle - that a title on its own is not evidence. Two candidates
         * that both fit are not a match either; they are a question, and the
         * report is where questions go. */
        $confirmed = array_values(array_filter(
            $candidates,
            fn (int $id): bool => self::samePerson($people, $this->authors[$id] ?? [])
        ));

        if (count($confirmed) === 1) {
            return [
                'book_id' => $confirmed[0],
                'how'     => 'title',
                'isbns'   => $isbns,
                'work'    => $work,
                'authors' => $people,
            ];
        }

        /* A single candidate with nobody recorded on it.
         *
         * 144 books came out of the import with an empty author field, and
         * for those there is nothing that could agree or disagree. One title
         * and no contradiction is thinner evidence than the rest of this, and
         * it is reported as its own kind so it can be looked at.
         */
        $nameless = array_values(array_filter(
            $candidates,
            fn (int $id): bool => ($this->authors[$id] ?? []) === []
        ));
        if (count($candidates) === 1 && count($nameless) === 1) {
            return [
                'book_id' => $nameless[0],
                'how'     => 'title_only',
                'isbns'   => $isbns,
                'work'    => $work,
                'authors' => $people,
            ];
        }

        return [
            'book_id' => null,
            'how'     => 'none',
            'isbns'   => $isbns,
            'work'    => $work,
            'authors' => $people,
        ];
    }

    /**
     * Every ISBN-13 in a post's text.
     *
     * Written with or without hyphens, and often more than one - a review of
     * a series names every volume. They are all returned, in the order they
     * appear, because the first one is usually the book the post is about.
     *
     * @return list<string>
     */
    public static function isbns(string $text): array
    {
        /* Nine to seventeen characters between the prefix and the check
           digit: nine when the number is written plain, seventeen when every
           hyphen is in place. Starting at ten - the hyphenated width - is
           how a plainly written ISBN goes unnoticed. */
        preg_match_all('/97[89][\d\-\s]{9,17}\d/u', $text, $matches);

        $found = [];
        foreach ($matches[0] as $raw) {
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            if (strlen($digits) === 13 && !in_array($digits, $found, true)) {
                $found[] = $digits;
            }
        }

        return $found;
    }

    /**
     * The book and the people out of a post title.
     *
     * The pattern is "Author & Author: Title - Subtitle. Publisher, City Year".
     * The publisher is cut off by what it looks like rather than by counting
     * full stops: titles have full stops in them, and "Es ist doch nur Haut!"
     * ends in an exclamation mark.
     *
     * @return array{work: string, authors: list<string>}
     */
    public static function parseTitle(string $title): array
    {
        $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/^(.+?):\s*(.+)$/u', $title, $match) !== 1) {
            return ['work' => $title, 'authors' => []];
        }

        $people = array_values(array_filter(array_map(
            'trim',
            preg_split('/\s*(?:&|,|\bund\b)\s*/u', $match[1]) ?: []
        )));

        $work = preg_replace('/\s*[^.!?]{2,70},\s*[^,]{2,40}\s+\d{4}\.?\s*$/u', '', $match[2]) ?? $match[2];

        return ['work' => rtrim($work, " .\u{00A0}"), 'authors' => $people];
    }

    /**
     * Is one of these people one of those people?
     *
     * Not an equality test on the whole name, because the shelf's names came
     * out of a shop export: two people are sometimes crammed into one field
     * ("Jonas Seufert & Lisa Fruhbeus"), and one of them is sometimes
     * misspelled. What survives both is the surname, so a match on any one
     * surname counts - and it only ever runs on candidates whose title
     * already agrees, which is what keeps a common surname from deciding
     * anything on its own.
     *
     * @param list<string> $people   names as the blog writes them
     * @param list<string> $recorded match keys of the book's authors
     */
    private static function samePerson(array $people, array $recorded): bool
    {
        if ($people === [] || $recorded === []) {
            return false;
        }

        $tokens = [];
        foreach ($recorded as $key) {
            foreach (explode(' ', $key) as $part) {
                if ($part !== '') {
                    $tokens[$part] = true;
                }
            }
        }

        foreach ($people as $name) {
            // The surname as the blog writes it - the last word of the name,
            // before the key is folded and its parts sorted. Given names are
            // not evidence: two different people called Lisa are two people.
            $words = preg_split('/\s+/u', trim($name)) ?: [];
            $surname = Text::fold((string) end($words));
            $surname = preg_replace('/[^a-z]/', '', $surname) ?? '';

            // Initials say nothing; two letters are not a surname.
            if (mb_strlen($surname) > 2 && isset($tokens[$surname])) {
                return true;
            }
        }

        return false;
    }

    /** Comparable form of a title: letters and digits, nothing else. */
    private static function key(string $title): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Text::fold($title)) ?? '';
    }

    /** The same, cut before a subtitle, a series note or a bracket. */
    private static function head(string $title): string
    {
        $parts = preg_split('/\s+[–—-]\s+|:\s|,\s|\s\(/u', $title) ?: [$title];

        return self::key($parts[0]);
    }
}
