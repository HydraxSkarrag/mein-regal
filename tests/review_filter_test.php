<?php
/**
 * "Which books have I already written about?"
 *
 * The filter is a SQL condition on a nullable column, which is where this
 * kind of thing goes wrong: NULL and the empty string are different values
 * to the database and the same thing to a reader. A book whose review field
 * was cleared to '' rather than set back to NULL must count as unreviewed,
 * or it hides from both halves of the filter at once.
 */
declare(strict_types=1);

use App\Repository\BookRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('Shelf filter: with and without a review');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new BookRepository($pdo);

$books->insert(1, ['title' => 'Besprochen', 'review_url' => 'https://example.org/rezension']);
$books->insert(1, ['title' => 'Noch nicht', 'review_url' => null]);
$books->insert(1, ['title' => 'Feld geleert', 'review_url' => '']);

/** @return list<string> the titles a filter returns, in a stable order */
$titles = static function (array $filters) use ($books): array {
    $result = $books->search(1, $filters, 50, 0);
    $found = array_map(static fn (array $row): string => (string) $row['title'], $result['rows']);
    sort($found);

    return $found;
};

Assert::same('with a review', $titles(['review' => 'yes']), ['Besprochen']);
Assert::same('without one, NULL and empty alike', $titles(['review' => 'no']), ['Feld geleert', 'Noch nicht']);
Assert::same('no filter, everything', count($titles([])), 3);

// The two halves must add up: a book that appears in neither is invisible to
// the filter altogether, which is exactly the bug an empty string causes.
Assert::same(
    'the two halves account for every book',
    count($titles(['review' => 'yes'])) + count($titles(['review' => 'no'])),
    3
);

$counts = $books->countByReview(1);
Assert::same('the sidebar count, with', $counts['with'], 1);
Assert::same('the sidebar count, without', $counts['without'], 2);
Assert::same('the counts add up to the shelf', $counts['with'] + $counts['without'], 3);

// Another owner's books are not counted, the way every other query works.
(new UserRepository($pdo))->create('zweite@example.org', 'ein-langes-passwort', 'Z');
$books->insert(2, ['title' => 'Fremdes Regal', 'review_url' => 'https://example.org/fremd']);

Assert::same('a second shelf does not leak in', $books->countByReview(1)['with'], 1);
Assert::same('and has its own count', $books->countByReview(2)['with'], 1);
Assert::same('nor into the filtered list', $titles(['review' => 'yes']), ['Besprochen']);
