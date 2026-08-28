<?php
declare(strict_types=1);

namespace App\Lookup;

interface LookupSource
{
    /** Stable key, also used as the cache key and the cover source. */
    public function name(): string;

    /** Null means "no record for this ISBN", not "the request failed". */
    public function find(string $isbn13): ?BookData;
}
