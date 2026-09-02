<?php
declare(strict_types=1);

use App\Core\Input;

Assert::group('Input: text');

Assert::same('trimmed', Input::text('  Der Schwarm  ', 500), 'Der Schwarm');
Assert::same('empty becomes NULL', Input::text('   ', 500), null);
Assert::same('cut to length', Input::text('abcdef', 3), 'abc');
Assert::same('cut counts characters, not bytes', Input::text('Müllerstraße', 3), 'Mül');

Assert::group('Input: int');

Assert::same('a page count', Input::int('189', 1, 30000), 189);
// The reason this class exists: the scanner did not trim and the edit form
// did, so the same field was kept in one place and dropped in the other.
Assert::same('surrounding space is not a reason to discard it', Input::int(' 189 ', 1, 30000), 189);
Assert::same('below the range', Input::int('0', 1, 30000), null);
Assert::same('above the range', Input::int('99999', 1, 30000), null);
Assert::same('not a number', Input::int('viele', 1, 30000), null);
Assert::same('digits only, no unit', Input::int('189 Seiten', 1, 30000), null);
Assert::same('empty', Input::int('', 1, 30000), null);

Assert::group('Input: price');

Assert::same('a German decimal comma', Input::price('12,99'), 12.99);
Assert::same('a point works too', Input::price('12.99'), 12.99);
// The export writes 0,00 for "no price known". Zero is not a price.
Assert::same('zero means not set', Input::price('0,00'), null);
Assert::same('rounded to cents', Input::price('12,999'), 13.0);
Assert::same('prose', Input::price('geschenkt'), null);

Assert::group('Input: rating');

Assert::same('a whole star', Input::rating('4'), 4.0);
Assert::same('a half star', Input::rating('3,5'), 3.5);
Assert::same('snapped to the nearest half', Input::rating('3.7'), 3.5);
Assert::same('zero is not a rating, it is no rating', Input::rating('0'), null);
Assert::same('above five', Input::rating('6'), null);

Assert::group('Input: date');

Assert::same('an ISO date', Input::date('2026-02-23'), '2026-02-23');
Assert::same('German format is not accepted here', Input::date('23.02.2026'), null);
Assert::same('empty', Input::date(''), null);

Assert::group('Input: url');

Assert::same(
    'an https link',
    Input::url('https://www.buecherhausen.de/rezension/'),
    'https://www.buecherhausen.de/rezension/'
);
// The stored link is rendered as an anchor for every visitor, so the scheme
// is the whole point of the check.
Assert::same('javascript: is not a link', Input::url('javascript:alert(1)'), null);
Assert::same('data: is not a link either', Input::url('data:text/html,<script>x</script>'), null);
Assert::same('nor a file path', Input::url('/etc/passwd'), null);
Assert::same('empty', Input::url(''), null);

Assert::group('Input: oneOf');

Assert::same('a known value', Input::oneOf('paperback', ['hardcover', 'paperback']), 'paperback');
Assert::same('an unknown one is dropped', Input::oneOf('taschenbuch', ['hardcover', 'paperback']), null);
Assert::same(
    'with a fallback',
    Input::oneOf('taschenbuch', ['hardcover', 'paperback'], 'hardcover'),
    'hardcover'
);
// This is what keeps a hand-written query string out of an ORDER BY.
Assert::same('an injection attempt is not on the list', Input::oneOf('title; DROP TABLE books', ['title'], ''), '');
