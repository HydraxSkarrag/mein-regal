<?php
declare(strict_types=1);

namespace App\Lookup;

use App\Core\Isbn;

/**
 * Cover images from MVB, the German book trade's own directory.
 *
 * Not a metadata source and deliberately not a LookupSource: it answers with
 * a picture or a 404 and knows nothing about a title. It exists because the
 * two sources that do answer with metadata leave half the shelf blank - the
 * DNB, which is complete for German titles, holds no cover images at all, and
 * Google and Open Library between them had nothing for 1.451 of 3.042 books.
 *
 * These are the publishers' own cover files, delivered to the trade through
 * MVB's Verzeichnis Lieferbarer Bücher and reached here through the German
 * National Library's catalogue, which shows them beside its records. No key,
 * no registration, one request per ISBN.
 *
 * Measured against books where Google and Open Library had both already
 * failed: 143 covers for 160 ISBNs, and at a size neither of them offers -
 * 599 pixels tall throughout, against the 300 Google hands back for a German
 * title it has never scanned. Against ISBNs from outside the German market
 * the same test found 2 in 25, which is why the order in CoverFinder depends
 * on where the ISBN is from.
 *
 * The address is not a documented interface and may change or close. That is
 * survivable - a source that stops answering costs the covers it would have
 * added and nothing that is already stored - but it is the reason nothing
 * here assumes a response, and the reason this stays one small class.
 */
final class MvbCoverLookup
{
    /**
     * Whether this source is worth asking first for a given ISBN.
     *
     * MVB lists what the German book trade sells. For an ISBN from anywhere
     * else it is a long shot rather than a first port of call.
     */
    public static function isLikelyFor(string $isbn13): bool
    {
        return Isbn::languageArea($isbn13) === 'german';
    }

    public static function coverUrl(string $isbn13): string
    {
        return 'https://portal.dnb.de/opac/mvb/cover?isbn=' . rawurlencode($isbn13);
    }

    /** Shown beneath the picture, like every other source's. */
    public const ATTRIBUTION = 'Cover: MVB / Verzeichnis Lieferbarer Bücher';
}
