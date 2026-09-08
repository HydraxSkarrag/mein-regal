<?php
declare(strict_types=1);

namespace App\Lookup;

use App\Core\Isbn;

/**
 * Thumbnails for a list of search results, fetched by this server.
 *
 * The obvious way was to let the browser load each cover straight from the
 * catalogue - one line, no waiting. It does not work: portal.dnb.de puts a
 * "Dein Browser wird geprüft" check in front of a browser's first request, so
 * the picture arrives as a page of HTML and the background silently paints
 * nothing. Worse than broken, it is intermittent: a browser that has been to
 * the catalogue before carries the cookie and sees the covers, which makes it
 * a fault that depends on somebody's browsing history.
 *
 * So the server fetches them, as it already does when a cover is kept, and
 * hands them to the page as data: URIs. Two consequences worth having beyond
 * the pictures appearing at all: no visitor or operator browser ever contacts
 * the catalogue, which is the rule the rest of this application keeps, and a
 * thumbnail at this size costs two kilobytes rather than sixty.
 *
 * All of them at once through curl_multi. Ten sequential fetches would be ten
 * round trips on a page somebody is waiting in front of; in parallel it is
 * one, and the slowest cover sets the pace.
 */
final class CoverPreviews
{
    /** Twice the 42 pixels the tile is drawn at, for a sharp screen. */
    private const WIDTH = 84;

    /** A cover is tens of kilobytes; anything far larger is not one. */
    private const MAX_BYTES = 4 * 1024 * 1024;

    private const TIMEOUT_SECONDS = 8;

    public function __construct(private readonly string $contact = '')
    {
    }

    /**
     * @param  list<string> $isbns
     * @return array<string, string> ISBN => data: URI, missing ones absent
     */
    public function forIsbns(array $isbns): array
    {
        $wanted = [];
        foreach ($isbns as $isbn) {
            $normalised = Isbn::normalize($isbn);
            if ($normalised !== null) {
                $wanted[$normalised] = MvbCoverLookup::coverUrl($normalised);
            }
        }
        if ($wanted === [] || !function_exists('curl_multi_init')) {
            return [];
        }

        $agent = $this->agent();

        $multi = curl_multi_init();
        $handles = [];
        foreach ($wanted as $isbn => $url) {
            $handle = curl_init($url);
            if ($handle === false) {
                continue;
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
                CURLOPT_USERAGENT      => $agent,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $handles[$isbn] = $handle;
            curl_multi_add_handle($multi, $handle);
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $previews = [];
        foreach ($handles as $isbn => $handle) {
            $body = (string) curl_multi_getcontent($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $handle);

            if ($code !== 200 || $body === '' || strlen($body) > self::MAX_BYTES) {
                continue;
            }
            $uri = self::thumbnail($body, self::WIDTH);
            if ($uri !== null) {
                $previews[$isbn] = $uri;
            }
        }
        curl_multi_close($multi);

        return $previews;
    }

    /**
     * One cover, from wherever it is, for a page that may not fetch it itself.
     *
     * The scanner shows the found book before anything is saved, and the only
     * address it has at that moment is the catalogue's own. Handing that to
     * the browser looks like it works and does not: portal.dnb.de answers a
     * browser with "Making sure you're not a bot!" - four kilobytes of HTML
     * where the picture should be, so the card came up with an empty frame
     * while the book, saved a second later, had its cover. Measured on one
     * ISBN: the same URL is a 599x599 JPEG without a browser's user agent and
     * a bot page with one.
     *
     * Fetching here also keeps the rule the rest of the application keeps -
     * no page ever makes the reader's browser contact a third party.
     *
     * @param int $width twice the size it is drawn at, for a sharp screen
     */
    public function forUrl(string $url, int $width = 168): ?string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT      => $this->agent(),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = (string) curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($code !== 200 || $body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        return self::thumbnail($body, $width);
    }

    private function agent(): string
    {
        return 'Buecherregal/1.0 (private library catalogue'
            . ($this->contact !== '' ? '; ' . $this->contact . ')' : ')');
    }

    /**
     * One picture, shrunk and encoded, or null if it is not a picture at all.
     *
     * The type comes from the bytes rather than from what was claimed, which
     * is also what rejects the bot-check page: an HTML document is not an
     * image and getimagesizefromstring says so.
     */
    private static function thumbnail(string $bytes, int $width): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }
        [$sourceWidth, $sourceHeight] = $info;
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        $targetWidth = min($width, $sourceWidth);
        $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        ob_start();
        imagejpeg($canvas, null, 72);
        $small = (string) ob_get_clean();

        return $small === '' ? null : 'data:image/jpeg;base64,' . base64_encode($small);
    }
}
