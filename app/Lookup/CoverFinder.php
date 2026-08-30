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
     * @return array{stored: bool, source: ?string, path: ?string}
     */
    public function findFor(int $bookId, string $isbn13, ?BookData $known = null): array
    {
        foreach ($this->candidates($isbn13, $known) as [$url, $source, $attribution]) {
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

            return ['stored' => true, 'source' => $source, 'path' => $stored['path']];
        }

        return ['stored' => false, 'source' => null, 'path' => null];
    }

    /**
     * Where to look, in order.
     *
     * The metadata sources are asked first because a cover they name belongs
     * to the edition they described. Open Library's cover service comes after
     * as a catch-all: it holds images for many books whose records never
     * mention one, and the DNB - the best source for German titles - has no
     * cover images at all.
     *
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    private function candidates(string $isbn13, ?BookData $known): array
    {
        $found = $known ?? $this->chain->find($isbn13)['result'];

        $candidates = [];
        if ($found !== null && $found->coverUrl !== null) {
            $candidates[] = [
                $found->coverUrl,
                $found->coverSource ?? CoverRepository::SOURCE_OPENLIBRARY,
                $found->attribution,
            ];
        }
        $candidates[] = [
            OpenLibraryLookup::coverUrl($isbn13),
            CoverRepository::SOURCE_OPENLIBRARY,
            'Cover: Open Library',
        ];

        return $candidates;
    }
}
