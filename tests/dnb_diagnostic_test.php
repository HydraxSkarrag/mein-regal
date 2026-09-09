<?php
/**
 * "Nichts gefunden" has to mean nothing was found.
 *
 * An SRU service answers "I hold no such record" and "I will not serve you
 * right now" with the same HTTP 200, and both used to arrive as null. The
 * chain reads null as "nowhere has this book", so a throttled or hiccuping
 * catalogue was reported to the person at the scanner as a book the catalogue
 * does not hold - and written down by the nightly job as a settled miss, not
 * to be asked again for a month.
 *
 * The answer says which it is. Only numberOfRecords=0 means unknown.
 */
declare(strict_types=1);

use App\Lookup\DnbLookup;
use App\Lookup\LookupUnavailable;

$refuses = static function (string $xml): ?string {
    try {
        DnbLookup::refuseIfNotAnAnswer($xml, 'dnb');
    } catch (LookupUnavailable $e) {
        return $e->getMessage();
    }

    return null;
};

Assert::group('An empty answer and a broken one are different answers');

$none = '<?xml version="1.0"?><searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/">'
      . '<version>1.1</version><numberOfRecords>0</numberOfRecords></searchRetrieveResponse>';
Assert::same('zero records is a real answer and passes through', $refuses($none), null);

$one = '<?xml version="1.0"?><searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/">'
     . '<numberOfRecords>1</numberOfRecords><records><record><recordData>'
     . '<dc><title>Biss zum Morgengrauen</title></dc></recordData></record></records>'
     . '</searchRetrieveResponse>';
Assert::same('and so is a record', $refuses($one), null);

$diagnostic = '<?xml version="1.0"?><searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/">'
            . '<numberOfRecords>0</numberOfRecords><diagnostics><diagnostic>'
            . '<uri>info:srw/diagnostic/1/1</uri><message>General system error</message>'
            . '</diagnostic></diagnostics></searchRetrieveResponse>';
$why = $refuses($diagnostic);
Assert::true('a diagnostic is refused', $why !== null);
Assert::true('and it carries what the service said', str_contains((string) $why, 'General system error'));

/* The shape that is easiest to mistake for an empty shelf: the count says a
 * record is there and no record came with it. Something in between answered. */
$cut = '<?xml version="1.0"?><searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/">'
     . '<numberOfRecords>1</numberOfRecords><records></records></searchRetrieveResponse>';
Assert::true('a promised record that is missing is refused', $refuses($cut) !== null);

// An error page from something in front of the service is not a catalogue
// saying no. Neither is an empty body dressed up as HTML.
Assert::true('a non-XML answer is refused', $refuses('<html><body>503</body></html>') !== null);
Assert::true('and so is one with no record count at all', $refuses('<xml><ok/></xml>') !== null);

Assert::group('The message says which number it is about');

/* Every one of these ends with "von Hand erfassen", and the form then asks
 * for the ISBN first. Reading it off the message beats fetching the book
 * again - and it settles, in a screenshot, which number was asked about. */
foreach (['scan.nothing', 'scan.quota', 'scan.unreachable'] as $key) {
    $text = t($key, ['isbn' => '978-3-8449-0094-1']);
    Assert::true($key . ' names the ISBN', str_contains($text, '978-3-8449-0094-1'));
    Assert::true($key . ' has no placeholder left in it', !str_contains($text, '{isbn}'));
}

Assert::true(
    'an invalid one quotes what was typed',
    str_contains(t('scan.invalid.isbn', ['code' => '12345']), '12345')
);
Assert::true(
    'and a non-book barcode names the barcode',
    str_contains(t('scan.not.a.book', ['code' => '4006381333931']), '4006381333931')
);
