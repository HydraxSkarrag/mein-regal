<?php
declare(strict_types=1);

namespace App\Lookup;

use App\Core\CoverStorage;
use App\Repository\CoverRepository;
use Throwable;

/**
 * Finds a cover for an ISBN and keeps it.
 *
 * Written once and used from three places: the nightly job working through
 * the backlog, the scanner saving a freshly caught book, and the edit page
 * when someone asks for a cover by hand. The rules about where an image may
 * come from and what happens to it belong in one place.
 *
 * The image is downloaded and re-served from this server rather than
 * embedded, so no visitor ever contacts Google or the Internet Archive.
 */
final class CoverFinder
{
    public function __construct(
        private readonly LookupChain $chain,
        private readonly CoverRepository $covers,
        private readonly CoverStorage $storage,
    ) {
    }

    /**
     * @param  ?BookData $known a lookup result already in hand, to save a round trip
     * @return array{stored: bool, source: ?string, path: ?string, failures: array<string, LookupUnavailable>}
     */
    public function findFor(int $bookId, string $isbn13, ?BookData $known = null): array
    {
        [$candidates, $failures] = $this->candidates($isbn13, $known);

        return $this->firstOf($bookId, $isbn13, $candidates, $failures);
    }

    /**
     * Only the services that answer by ISBN alone - no metadata lookup.
     *
     * Split out because asking these first is what stops Google's daily
     * thousand being spent on covers. The chain keeps going while a record is
     * incomplete, and "incomplete" counts a missing cover - so for a German
     * book, whose record comes from the DNB and whose cover never does, every
     * single one cost a Google query to look for a picture Google usually did
     * not have either. Ask the free services first and that query is only
     * spent when it is metadata that is actually missing.
     *
     * @return array{stored: bool, source: ?string, path: ?string, failures: array<string, LookupUnavailable>}
     */
    public function fromServices(int $bookId, string $isbn13): array
    {
        return $this->firstOf($bookId, $isbn13, self::coverServices($isbn13), []);
    }

    /**
     * The cover a metadata source named, and nothing else.
     *
     * The other half of the split: what is left to try once the services have
     * been asked and had nothing.
     *
     * @return array{stored: bool, source: ?string, path: ?string, failures: array<string, LookupUnavailable>}
     */
    public function fromMetadata(int $bookId, string $isbn13, ?BookData $found): array
    {
        if ($found === null || $found->coverUrl === null) {
            return ['stored' => false, 'source' => null, 'path' => null, 'failures' => []];
        }

        return $this->firstOf($bookId, $isbn13, [[
            $found->coverUrl,
            $found->coverSource ?? CoverRepository::SOURCE_OPENLIBRARY,
            $found->attribution,
        ]], []);
    }

    /**
     * Take the first candidate that hands back a picture, and keep it.
     *
     * @param  list<array{0: string, 1: string, 2: ?string}> $candidates
     * @param  array<string, LookupUnavailable>              $failures
     * @return array{stored: bool, source: ?string, path: ?string, failures: array<string, LookupUnavailable>}
     */
    private function firstOf(int $bookId, string $isbn13, array $candidates, array $failures): array
    {
        // A cover thrown out by hand stays out. Only that source is blocked,
        // so the book still gets a chance at a right image from another one.
        $rejected = $this->covers->rejectedSources($bookId);

        foreach ($candidates as [$url, $source, $attribution]) {
            if (in_array($source, $rejected, true)) {
                continue;
            }

            try {
                $stored = $this->storage->storeRemote($url, $isbn13 . '-' . $source);
            } catch (Throwable $e) {
                // A 404 from a cover service is the normal case, not a fault.
                continue;
            }

            $this->covers->save(
                $bookId,
                $source,
                $stored['path'],
                $url,
                $attribution,
                $stored['width'],
                $stored['height']
            );

            return ['stored' => true, 'source' => $source, 'path' => $stored['path'], 'failures' => $failures];
        }

        /* Which sources could not be asked travels back with the empty
         * result. Without it the only thing left to say is "no cover found",
         * which is a different sentence from "Google is out of quota until
         * tomorrow" - and only one of the two is worth trying again. */
        return ['stored' => false, 'source' => null, 'path' => null, 'failures' => $failures];
    }

    /**
     * The services that answer with a cover for an ISBN and nothing else -
     * no metadata lookup, no key, no quota - in the order to ask them.
     *
     * MVB goes first for a German ISBN. It is keyed by ISBN like the others,
     * so its picture belongs to the same edition, but it is the publisher's
     * own file rather than a scan or a thumbnail - measured at 599 pixels
     * tall where Google hands back 300 for the same book. For an ISBN from
     * outside the German market it drops behind Open Library instead: the
     * same measurement found it answering 2 times in 25 there, which is worth
     * a last try and not a first one.
     *
     * Public because the scanner asks the same question while a book is still
     * on screen and unsaved, and the order should not be decided twice.
     *
     * @return list<array{0: string, 1: string, 2: ?string}> url, source, attribution
     */
    public static function coverServices(string $isbn13): array
    {
        $mvb = [
            MvbCoverLookup::coverUrl($isbn13),
            CoverRepository::SOURCE_MVB,
            MvbCoverLookup::ATTRIBUTION,
        ];
        $openLibrary = [
            OpenLibraryLookup::coverUrl($isbn13),
            CoverRepository::SOURCE_OPENLIBRARY,
            'Cover: Open Library',
        ];

        return MvbCoverLookup::isLikelyFor($isbn13) ? [$mvb, $openLibrary] : [$openLibrary, $mvb];
    }

    /**
     * Where to look, in order.
     *
     * A cover named by a metadata source belongs to the edition that source
     * described, which is worth something - so it sits behind whichever
     * service is a first choice for this ISBN and in front of the last
     * resorts. The DNB, the best source there is for German titles, names
     * none: it holds no cover images at all, which is why the services above
     * exist in the first place.
     *
     * @return array{0: list<array{0: string, 1: string, 2: ?string}>, 1: array<string, LookupUnavailable>}
     */
    private function candidates(string $isbn13, ?BookData $known): array
    {
        $failures = [];
        if ($known === null) {
            $outcome = $this->chain->find($isbn13);
            $found = $outcome['result'];
            $failures = $outcome['failures'];
        } else {
            $found = $known;
        }

        $services = self::coverServices($isbn13);

        // Only a service that is a first choice for this ISBN goes ahead of
        // the metadata source's own cover. The rest are the fallback.
        $first = MvbCoverLookup::isLikelyFor($isbn13) ? [array_shift($services)] : [];

        $metadata = [];
        if ($found !== null && $found->coverUrl !== null) {
            $metadata[] = [
                $found->coverUrl,
                $found->coverSource ?? CoverRepository::SOURCE_OPENLIBRARY,
                $found->attribution,
            ];
        }

        return [array_merge($first, $metadata, $services), $failures];
    }
}
