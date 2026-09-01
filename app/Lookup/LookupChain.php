<?php
declare(strict_types=1);

namespace App\Lookup;

use App\Core\Isbn;

/**
 * Asks the metadata sources in the order most likely to answer.
 *
 * The order is not cosmetic. Measured against the real collection, the DNB
 * answered every German ISBN and none of the English ones - asking it first
 * for an English title wastes a request and returns nothing, while asking it
 * last for a German title throws away the only source that carries the retail
 * price and the binding.
 *
 * The first usable answer wins and stays authoritative. Later sources are only
 * consulted to fill gaps it left - most importantly the cover, which the DNB
 * never has.
 */
final class LookupChain
{
    /** @var array<string, LookupSource> */
    private array $sources = [];

    public function __construct(LookupSource ...$sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source->name()] = $source;
        }
    }

    /**
     * @return list<string> source names, best first
     */
    public function orderFor(string $isbn13): array
    {
        $order = match (Isbn::languageArea($isbn13)) {
            'german'  => ['dnb', 'google', 'openlibrary'],
            'english' => ['openlibrary', 'google', 'dnb'],
            default   => ['google', 'openlibrary', 'dnb'],
        };

        return array_values(array_filter(
            $order,
            fn (string $name): bool => isset($this->sources[$name])
        ));
    }

    /**
     * @param  bool $fillGaps keep asking after a hit, to complete the record
     * @return array{result: ?BookData, tried: list<string>, failures: array<string, LookupUnavailable>}
     */
    public function find(string $isbn13, bool $fillGaps = true): array
    {
        $tried = [];
        $failures = [];
        $result = null;

        foreach ($this->orderFor($isbn13) as $name) {
            // Stop early once nothing worth filling is left.
            if ($result !== null && (!$fillGaps || $this->isComplete($result))) {
                break;
            }

            $tried[] = $name;

            /* A source that cannot answer is not a source that says no.
             *
             * The chain carries on to the next one either way - a silent
             * Google should not cost the DNB's answer - but it hands the
             * failure back, so the caller can tell an empty result that means
             * "nowhere has this book" from one that means "we could not ask".
             * Recording the second as the first is what locks a book out of
             * the nightly job for a month. */
            try {
                $found = $this->sources[$name]->find($isbn13);
            } catch (LookupUnavailable $e) {
                $failures[$name] = $e;
                continue;
            }

            if ($found === null || !$found->isUsable()) {
                continue;
            }

            $result = $result === null ? $found : $result->mergeMissingFrom($found);
        }

        return ['result' => $result, 'tried' => $tried, 'failures' => $failures];
    }

    /**
     * What an empty result means, in one word.
     *
     * 'quota'       a source has nothing left until tomorrow
     * 'unreachable' a source could not be asked at all
     * 'none'        every source answered, and none has this book
     *
     * The difference matters to whoever pressed the button: two of the three
     * are worth trying again, and only one of them means the book is not to
     * be found. Which words carry that is the caller's business - this says
     * which of the three happened.
     *
     * @param array<string, LookupUnavailable> $failures
     */
    public static function verdict(array $failures): string
    {
        if ($failures === []) {
            return 'none';
        }

        return self::quotaExhausted($failures) !== null ? 'quota' : 'unreachable';
    }

    /**
     * Did a source say that waiting until tomorrow is the only way forward?
     *
     * @param array<string, LookupUnavailable> $failures
     */
    public static function quotaExhausted(array $failures): ?LookupUnavailable
    {
        foreach ($failures as $failure) {
            if ($failure->quotaExhausted) {
                return $failure;
            }
        }

        return null;
    }

    /**
     * "Complete enough to stop asking". A cover counts, because it is the one
     * field the best German source structurally cannot provide.
     */
    private function isComplete(BookData $data): bool
    {
        return $data->title !== null
            && $data->authors !== []
            && $data->publishedYear !== null
            && $data->pageCount !== null
            && $data->coverUrl !== null;
    }
}
