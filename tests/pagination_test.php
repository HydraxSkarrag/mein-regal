<?php
/**
 * Which page numbers a fifty-one page shelf offers.
 *
 * The rule has two jobs at once: never lose the way to the first and last
 * page, and never print fifty-one numbers in a row. The interesting cases are
 * the ends, where a naive window runs off the edge and leaves a gap marker
 * standing next to the number it was meant to replace.
 */
declare(strict_types=1);

use App\Controller\ShelfController;

Assert::group('Pagination: which numbers to show');

$numbers = static function (int $page, int $pages): array {
    // No setAccessible(): it has done nothing since PHP 8.1 and is
    // deprecated in 8.5, which the local runtime is.
    return (new ReflectionMethod(ShelfController::class, 'pageNumbers'))
        ->invoke(null, $page, $pages);
};

/** Renders the list the way the template does, for readable expectations. */
$render = static fn (array $list): string => implode(' ', array_map(
    static fn (?int $n): string => $n === null ? '…' : (string) $n,
    $list
));

Assert::same('a single page needs no gaps', $render($numbers(1, 1)), '1');
Assert::same('a handful is shown whole', $render($numbers(3, 5)), '1 2 3 4 5');
Assert::same('at the start', $render($numbers(1, 51)), '1 2 3 … 51');
Assert::same('one step in', $render($numbers(2, 51)), '1 2 3 4 … 51');
Assert::same('in the middle', $render($numbers(25, 51)), '1 … 23 24 25 26 27 … 51');
Assert::same('at the end', $render($numbers(51, 51)), '1 … 49 50 51');

// A gap marker standing in for exactly one number is worse than the number.
Assert::same('no gap marker for a single missing page', $render($numbers(5, 9)), '1 … 3 4 5 6 7 … 9');
Assert::same('window touching the edge closes up', $render($numbers(4, 9)), '1 2 3 4 5 6 … 9');

// Every list must be usable: first and last always reachable, current present.
foreach ([1, 2, 7, 25, 50, 51] as $page) {
    $list = $numbers($page, 51);
    $present = array_filter($list, static fn (?int $n): bool => $n !== null);
    Assert::true(
        'page ' . $page . ': first, last and current are all offered',
        in_array(1, $present, true) && in_array(51, $present, true) && in_array($page, $present, true)
    );
    Assert::true('page ' . $page . ': the list stays short', count($list) <= 9);
}
