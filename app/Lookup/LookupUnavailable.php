<?php
declare(strict_types=1);

namespace App\Lookup;

use RuntimeException;

/**
 * A source could not answer - which is not the same as answering "no".
 *
 * The distinction was missing and it mattered. Every adapter turned any
 * non-200 into null, and null means "there is no record for this ISBN". So a
 * throttled Google and an unknown book were indistinguishable, and the nightly
 * job recorded both as a settled miss: thirty days during which the book is
 * not asked about again, for a failure that had nothing to do with the book.
 *
 * Measured against Google Books, roughly one request in six comes back 503 and
 * succeeds on the retry. Over three thousand books that is not an edge case.
 */
final class LookupUnavailable extends RuntimeException
{
    /**
     * @param string $source        which adapter gave up
     * @param bool   $quotaExhausted true when waiting will not help today
     */
    public function __construct(
        public readonly string $source,
        public readonly bool $quotaExhausted,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function quota(string $source, string $detail = ''): self
    {
        return new self($source, true, trim($source . ': daily quota exhausted ' . $detail));
    }

    public static function unreachable(string $source, string $detail = ''): self
    {
        return new self($source, false, trim($source . ': not answering ' . $detail));
    }
}
