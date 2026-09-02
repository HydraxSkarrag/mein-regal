<?php
/**
 * Matching a blog's posts to the books they are about.
 *
 * The shelf sits next to a book blog, and "has been written about" is the
 * distinction the whole catalogue exists to make. It was typed in by hand,
 * so one book out of three thousand had a link.
 */
declare(strict_types=1);

use App\Content\ReviewMatcher;

Assert::group('Reading a post title');

$parsed = ReviewMatcher::parseTitle('Matthew A. Cherry: Hair Love. Mentor Verlag, Berlin 2021');
Assert::same('the work', $parsed['work'], 'Hair Love');
Assert::same('the author', $parsed['authors'], ['Matthew A. Cherry']);

// Two people, joined the way a person writes it rather than a database.
$two = ReviewMatcher::parseTitle(
    'Jonas Seufert &amp; Lisa Frühbeis: Schattenleben – Menschen ohne Papiere erzählen. Reprodukt, Berlin 2026'
);
Assert::same('both people', $two['authors'], ['Jonas Seufert', 'Lisa Frühbeis']);
Assert::same('and the subtitle stays with the work', $two['work'], 'Schattenleben – Menschen ohne Papiere erzählen');

/*
 * The publisher is cut off by what it looks like - "something, somewhere
 * YEAR" - and not by counting full stops. Titles have full stops in them,
 * and this one ends in an exclamation mark, which is where a rule about the
 * last full stop leaves the publisher sitting in the title.
 */
$shout = ReviewMatcher::parseTitle('Fee Krämer: Es ist doch nur Haut! Gratitude Verlag, Hamburg 2025');
Assert::same('an exclamation mark is not a full stop', $shout['work'], 'Es ist doch nur Haut!');

$dotted = ReviewMatcher::parseTitle('KATAPULT: Trock’ne Zahlen. KATAPULT-Verlag, Greifswald 2026');
Assert::same('a one-word publisher', $dotted['work'], 'Trock’ne Zahlen');

// A title with no colon is not a pattern this can read; it is returned whole
// rather than mangled into halves.
$plain = ReviewMatcher::parseTitle('Ein Beitrag ohne Doppelpunkt');
Assert::same('nothing invented', $plain['work'], 'Ein Beitrag ohne Doppelpunkt');
Assert::same('and nobody invented either', $plain['authors'], []);

Assert::group('Finding the ISBNs in a post');

$text = 'Ein schönes Buch. ISBN 978-3-95640-515-0, 24 Euro. Band zwei: ISBN 9783956405167.';
Assert::same('both, without their hyphens', ReviewMatcher::isbns($text), ['9783956405150', '9783956405167']);
Assert::same('nothing where there is nothing', ReviewMatcher::isbns('Kein Buch, keine Nummer.'), []);

// The same number twice is one number.
Assert::same(
    'named twice, listed once',
    ReviewMatcher::isbns('ISBN 978-3-95640-515-0 ... 9783956405150'),
    ['9783956405150']
);

Assert::group('Which book a post is about');

$matcher = new ReviewMatcher([
    ['id' => 1, 'isbn13' => '9783956405150', 'title' => 'Schattenleben', 'authors' => ['Jonas Seufert', 'Lisa Frühbeis']],
    ['id' => 2, 'isbn13' => null, 'title' => 'Und deine Familie? (Carl-Auer Kids)', 'authors' => ['Sabine Bohlmann']],
    ['id' => 3, 'isbn13' => '9780000000001', 'title' => 'Windprinzessin', 'authors' => ['Petra Windmüller']],
    ['id' => 4, 'isbn13' => '9780000000002', 'title' => 'Windprinzessin', 'authors' => ['Karl Sturm']],
    ['id' => 5, 'isbn13' => null, 'title' => 'Ohne Verfasserin', 'authors' => []],
]);

$byIsbn = $matcher->match([
    'title'   => 'Jonas Seufert &amp; Lisa Frühbeis: Schattenleben. Reprodukt, Berlin 2026',
    'content' => 'Sehr gut. ISBN 978-3-95640-515-0',
]);
Assert::same('the ISBN decides', $byIsbn['book_id'], 1);
Assert::same('and says so', $byIsbn['how'], 'isbn');

// No ISBN in the shelf for this one, so the title and the author have to do
// it - and the catalogue title carries a bracketed addition the blog does not.
$byTitle = $matcher->match([
    'title'   => 'Sabine Bohlmann: Und deine Familie? Carl-Auer, Heidelberg 2024',
    'content' => 'Kein ISBN-Hinweis im Text.',
]);
Assert::same('title and author agree', $byTitle['book_id'], 2);
Assert::same('and it says which way', $byTitle['how'], 'title');

/*
 * Two books of the same name by different people: the title alone is not
 * evidence, and neither is a wrong author. A question is not a match, and
 * the report is where questions belong.
 */
$ambiguous = $matcher->match([
    'title'   => 'Fremde Autorin: Windprinzessin. Verlag, Ort 2020',
    'content' => 'Ohne ISBN.',
]);
Assert::same('an unknown author decides nothing', $ambiguous['book_id'], null);
Assert::same('and it is reported as undecided', $ambiguous['how'], 'none');

// An ISBN that belongs to no book in the shelf is not a match either - the
// post is about a book that was never catalogued.
$foreign = $matcher->match(['title' => 'Wer: Was. Verlag, Ort 2020', 'content' => 'ISBN 978-3-16-148410-0']);
Assert::same('an unknown ISBN matches nothing', $foreign['book_id'], null);
Assert::same('but it is still read out', $foreign['isbns'], ['9783161484100']);

/*
 * The shop export sometimes put two people into one author field and spelled
 * one of them wrong: "Jonas Seufert & Lisa Fruhbeus" for a book by Jonas
 * Seufert and Lisa Frühbeis. A surname that survives that is enough, and it
 * only ever decides among candidates whose title already agrees.
 */
$messy = new ReviewMatcher([
    ['id' => 9, 'isbn13' => null, 'title' => 'Schattenleben', 'authors' => ['Jonas Seufert & Lisa Fruhbeus']],
]);
$found = $messy->match([
    'title'   => 'Jonas Seufert &amp; Lisa Frühbeis: Schattenleben – Menschen ohne Papiere erzählen. Reprodukt, Berlin 2026',
    'content' => 'Ohne ISBN im Text.',
]);
Assert::same('one surname out of a mangled field is enough', $found['book_id'], 9);

// Nobody recorded at all - 144 books came out of the import that way. One
// title and nothing to contradict it, reported as the thinner evidence it is.
$nameless = $matcher->match([
    'title'   => 'Irgendwer: Ohne Verfasserin. Verlag, Ort 2021',
    'content' => 'Ohne ISBN.',
]);
Assert::same('a book with no author can still be matched', $nameless['book_id'], 5);
Assert::same('and is marked as the weaker kind', $nameless['how'], 'title_only');
