<?php
/**
 * Genres and labels told apart on a book page, when there is something to
 * tell apart.
 *
 * The two have been separate in the model for a while and separate in the
 * sidebar, and the book page still showed one undifferentiated row of chips -
 * written before the distinction existed and never revisited.
 *
 * Naming both groups on every book would be worse than not naming them. Most
 * of a shelf carries one kind or the other, and there two headings put the
 * operator's vocabulary in front of a visitor who came to find another book.
 * Where a book has both, the headings earn themselves: one link leads to a
 * wide shelf, the other to a narrow list.
 */
declare(strict_types=1);

use App\Controller\ShelfController;

/** @param list<array<string,mixed>> $tags */
function groups(array $tags): array
{
    return ShelfController::tagGroups($tags);
}

$genre = static fn (string $name): array => ['name' => $name, 'slug' => $name, 'kind' => 'genre'];
$label = static fn (string $name): array => ['name' => $name, 'slug' => $name, 'kind' => 'label'];

Assert::group('Book page: the two kinds of tag');

$both = groups([$genre('High Fantasy'), $label('Coming of Age'), $label('Klassiker')]);

Assert::same('the genre is on its own side', array_column($both['genre'], 'name'), ['High Fantasy']);
Assert::same('and the labels on theirs', array_column($both['label'], 'name'), ['Coming of Age', 'Klassiker']);

// Both sides filled is the only case that gets headings. The template asks
// exactly this question, so it is worth stating here as well.
Assert::true('a book with both is the case worth naming', $both['genre'] !== [] && $both['label'] !== []);

$onlyLabels = groups([$label('Natur')]);
Assert::true('a book with one kind is not', $onlyLabels['genre'] === [] || $onlyLabels['label'] === []);
Assert::same('and everything it has is still there', count($onlyLabels['label']), 1);

$onlyGenres = groups([$genre('Fantasy'), $genre('Jugendbuch')]);
Assert::same('the same the other way round', count($onlyGenres['genre']), 2);
Assert::same('with nothing invented on the empty side', $onlyGenres['label'], []);

Assert::same('no tags at all is two empty sides', groups([]), ['genre' => [], 'label' => []]);

/* A row with no kind counts as a label, which is what the column defaults to
 * and what the whole shelf assumes. Reachable from an older query that does
 * not select the column - the page must not fall over, and must not silently
 * promote anything either. */
$unknown = groups([['name' => 'Ohne Art', 'slug' => 'ohne-art']]);
Assert::same('a tag without a kind is a label', count($unknown['label']), 1);
Assert::same('never a genre', $unknown['genre'], []);

Assert::group('Book page: what this shelf actually looks like today');

/* Measured on the 3,042 imported books before assuming: every one of them
 * carries exactly one tag, because the Bookstats export had a single Genre
 * column. 1,599 books have that one tag as a genre, 1,195 as a label, and not
 * one book has both.
 *
 * So on that shelf this changes nothing at all - every book renders the plain
 * row it rendered yesterday. It shows up where books are edited by hand and
 * gain a second tag, which is where the question came from in the first
 * place. Worth writing down, because a feature nobody can see is usually a
 * feature that is broken, and this one is merely early.
 */
$imported = groups([$genre('Kinderbuch')]);
Assert::true(
    'an imported book keeps its single unheaded row',
    $imported['genre'] === [] || $imported['label'] === []
);
