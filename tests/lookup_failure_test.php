<?php
/**
 * "No record for this ISBN" versus "we could not ask".
 *
 * These were the same value - null - and the nightly job wrote both down as a
 * settled miss, which takes the book out of the work list for thirty days. A
 * throttled Google or a two-second wobble therefore cost a month, silently.
 * The distinction has to survive the whole way from the adapter through the
 * chain to the caller, so that is what is checked here.
 */
declare(strict_types=1);

use App\Lookup\BookData;
use App\Lookup\LookupChain;
use App\Lookup\LookupSource;
use App\Lookup\LookupUnavailable;

Assert::group('Lookup: a silent source is not a "no"');

/** A source that answers however the test needs it to. */
final class ScriptedSource implements LookupSource
{
    public int $calls = 0;

    public function __construct(
        private readonly string $name,
        private readonly mixed $answer,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function find(string $isbn13): ?BookData
    {
        $this->calls++;
        if ($this->answer instanceof LookupUnavailable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}

$complete = new BookData(
    isbn13: '9783473408061',
    isbn10: null,
    title: 'Milla und das erfundene Glück',
    subtitle: null,
    authors: [['name' => 'Dorothea Flechsig', 'role' => 'author']],
    publisher: 'Ravensburger',
    publishedYear: 2017,
    pageCount: 189,
    language: 'de',
    binding: 'hardcover',
    price: 12.99,
    coverUrl: 'https://example.org/cover.jpg',
    source: 'dnb',
    attribution: null,
    coverSource: 'google',
);

// --- A source that is down must not stop the chain --------------------
$down = new ScriptedSource('dnb', LookupUnavailable::unreachable('dnb', 'HTTP 503'));
$up = new ScriptedSource('google', $complete);
$chain = new LookupChain($down, $up, new ScriptedSource('openlibrary', null));

$outcome = $chain->find('9783473408061');
Assert::true('the chain carries on past a source that is down', $outcome['result'] !== null);
Assert::same('and says which one failed', array_keys($outcome['failures']), ['dnb']);
Assert::true('the next source was actually asked', $up->calls === 1);

// --- Nothing found, but nobody answered either ------------------------
$chain = new LookupChain(
    new ScriptedSource('dnb', LookupUnavailable::unreachable('dnb', 'HTTP 503')),
    new ScriptedSource('google', LookupUnavailable::unreachable('google', 'HTTP 503')),
    new ScriptedSource('openlibrary', LookupUnavailable::unreachable('openlibrary', 'HTTP 500'))
);
$outcome = $chain->find('9783473408061');
Assert::same('no result', $outcome['result'], null);
Assert::same('but three failures, so this is not a miss', count($outcome['failures']), 3);
Assert::same('and not a quota problem', LookupChain::quotaExhausted($outcome['failures']), null);

// --- Nothing found because the book is genuinely unknown --------------
$chain = new LookupChain(
    new ScriptedSource('dnb', null),
    new ScriptedSource('google', null),
    new ScriptedSource('openlibrary', null)
);
$outcome = $chain->find('9783473408061');
Assert::same('no result', $outcome['result'], null);
Assert::same('and no failures - this one really is a miss', $outcome['failures'], []);

// --- Out of quota is the failure that waiting cannot fix --------------
$chain = new LookupChain(
    new ScriptedSource('dnb', null),
    new ScriptedSource('google', LookupUnavailable::quota('google', '(Quota exceeded)')),
    new ScriptedSource('openlibrary', null)
);
$outcome = $chain->find('9783473408061');
$quota = LookupChain::quotaExhausted($outcome['failures']);
Assert::true('a quota failure is recognised as its own kind', $quota instanceof LookupUnavailable);
Assert::same('and names the source', $quota?->source, 'google');
Assert::true('and says waiting will not help today', $quota?->quotaExhausted === true);
