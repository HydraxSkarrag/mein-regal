<?php
/**
 * An Audible link, and what the shelf can make of it.
 *
 *   audible.de/pd/Breath-Atem-Hoerbuch/162861384X?ref=…
 *
 * The identifier at the end is an Amazon ASIN, and for a good many books the
 * ASIN simply is the ISBN-10 - this one is, check digit and all. So the number
 * out of an Audible address can be typed straight into the ISBN field and the
 * catalogue answers.
 *
 * Two things went wrong with the answer, and both are visible on the first
 * audiobook anybody adds.
 */
declare(strict_types=1);

use App\Core\Isbn;
use App\Core\Text;
use App\Lookup\Binding;
use App\Lookup\DnbLookup;
use App\Lookup\HttpClient;

Assert::group('The number in an Audible address');

/* Ten characters, nine digits and a check character: that is an ISBN-10, and
 * Isbn::normalize already accepted it. Nothing needed building for this part
 * - it is worth an assertion because it is the premise of the rest. */
Assert::same('an ASIN that is an ISBN', Isbn::normalize('162861384X'), '9781628613841');

// One that begins with B0 is a pure ASIN, and no source knows it as a book.
Assert::same('an ASIN that is not', Isbn::normalize('B0BR8Q6T4M'), null);
Assert::same('and a ten-digit one with a wrong check digit neither', Isbn::normalize('1628613841'), null);

Assert::group('A name written one and a half times');

/* The DNB gives this record two creators for one person:
 *
 *   Nestor, James [Verfasser]
 *   James Nestor, James [Künstler]
 *
 * The second has the whole name in the surname field and the given name after
 * the comma a second time. Flipped like an ordinary inverted pair it became
 * "James James Nestor" - a person who does not exist, in the author list and
 * in the filters, beside the one who does.
 */
Assert::same('the repeat is dropped', Text::splitAuthors('James Nestor, James')['names'], ['James Nestor']);
Assert::same('and an ordinary pair still turns round', Text::splitAuthors('Nestor, James')['names'], ['James Nestor']);
Assert::same('however long the name', Text::splitAuthors('Thomas Mann, Thomas')['names'], ['Thomas Mann']);

/* The guard that keeps it from eating real names: the part before the comma
 * has to carry more than one word. Somebody called Thomas Thomas exists and
 * has to stay that way. */
Assert::same('a single-word surname is never a repeat', Text::splitAuthors('Thomas, Thomas')['names'], ['Thomas Thomas']);
Assert::same('a compound surname is not one either', Text::splitAuthors('van Gogh, Vincent')['names'], ['Vincent van Gogh']);
Assert::same('nor a two-word one', Text::splitAuthors('Bürgi Wirth, Babette')['names'], ['Babette Bürgi Wirth']);

Assert::group('A book with somebody reading it aloud');

$dnb = new DnbLookup(new HttpClient(''));
$record = (string) file_get_contents(PROJECT_ROOT . '/tests/fixtures/dnb_9781628613841.xml');
$book = $dnb->parse($record, '9781628613841');

Assert::same('the title', $book->title, 'Breath - Atem');
Assert::same('the publisher', $book->publisher, 'SAGA Egmont');
Assert::same('the year', $book->publishedYear, 2024);

/* The DNB spells the binding out in a free-text identifier field and for an
 * audiobook usually says nothing at all, so the format arrived empty and had
 * to be set by hand. The evidence was one field further on all along. */
Assert::same('the format is read from the reader', $book->binding, Binding::AUDIOBOOK);

$roles = [];
foreach ($book->authors as $person) {
    $roles[$person['name']] = $person['role'];
}

Assert::same('the author is himself, once', $roles['James Nestor'] ?? null, 'author');
Assert::true('and there is no second one', !isset($roles['James James Nestor']));
Assert::same('the reader is credited as one', $roles['Dominic Kolb'] ?? null, 'narrator');
Assert::same('two people in total', count($book->authors), 2);

Assert::group('And a printed book is untouched by all of it');

/* dc:type would have been the obvious place to look for the format and is the
 * wrong one: for this audiobook it says "Online-Ressource", which the keyword
 * list reads as an e-book - the same word for a download of a novel and a
 * download of a recording of one. The inference is only ever used where the
 * text said nothing, so a stated binding still wins.
 */
$print = $dnb->parse(
    (string) file_get_contents(PROJECT_ROOT . '/tests/fixtures/dnb_9783473408061.xml'),
    '9783473408061'
);

Assert::same('a stated binding stands', $print->binding, Binding::HARDCOVER);
Assert::same('with its one author', count($print->authors), 1);

$source = (string) file_get_contents(PROJECT_ROOT . '/app/Lookup/DnbLookup.php');
Assert::true(
    'the inference runs second',
    str_contains($source, 'Binding::fromText($bindingAndPrice) ?? self::bindingFromRoles($people)')
);
Assert::true('and dc:type is still not consulted for it', !str_contains($source, "Binding::fromText(\$this->first(\$dc, 'type'))"));
