<?php
/**
 * The three limits, and the fact that none of them was checked.
 *
 * They are not features. They are what stops somebody borrowing this server
 * as a free proxy to the German National Library, what stops a password being
 * guessed at leisure, and what stops one nightly run outstaying the patience
 * of the service that called it. All three were written and none of them was
 * ever verified: the suite covered the lookup chain but not its gate, the
 * account lockout but not the one counting addresses, and the cron log but
 * not the clock.
 */
declare(strict_types=1);

use App\Controller\CronController;
use App\Controller\ScanController;
use App\Core\Auth;
use App\Core\Cookies;
use App\Core\SessionStore;
use App\Http\Application;
use App\Repository\UserRepository;
use Tests\Support\TestDatabase;

require_once __DIR__ . '/support/TestDatabase.php';

Assert::group('The lookup endpoint is not a free proxy');

/* Twenty a minute per address: generous enough that a real scanning session
 * never notices, tight enough that the endpoint is not worth borrowing.
 *
 * Reached through reflection because the counter lives on the controller and
 * the controller wants a whole application behind it. What is tested is the
 * real method, not a copy of the rule.
 */
$pdo = TestDatabase::fresh();

$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Application::class, 'pdo'))->setValue($app, $pdo);
(new ReflectionProperty(Application::class, 'lookupHits'))->setValue($app, new App\Repository\LookupHitRepository($pdo));

$scan = (new ReflectionClass(ScanController::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(ScanController::class, 'app'))->setValue($scan, $app);

$within = new ReflectionMethod(ScanController::class, 'withinRateLimit');
$limit = (new ReflectionClassConstant(ScanController::class, 'LOOKUPS_PER_MINUTE'))->getValue();

Assert::same('the limit is twenty a minute', $limit, 20);

$allowed = 0;
for ($i = 0; $i < $limit + 5; $i++) {
    if ($within->invoke($scan, '192.0.2.1')) {
        $allowed++;
    }
}
Assert::same('exactly that many get through', $allowed, $limit);
Assert::true('and the next one does not', !$within->invoke($scan, '192.0.2.1'));

// Counted per address, so one caller cannot spend somebody else's share.
Assert::true('a different address still has its own', $within->invoke($scan, '198.51.100.7'));

/* No address, no counting. A request that arrives without one is rare and
 * refusing it would be worse than letting it through: the endpoint is behind
 * the sign-in either way. */
Assert::true('an unknown address is let through', $within->invoke($scan, null));
Assert::true('and so is one that is not an address at all', $within->invoke($scan, 'nicht-einmal-eine-ip'));

// State in arrays, so a test run sends no headers and sets no cookies.
$emptySession = static fn (): SessionStore => new class implements SessionStore {
    /** @var array<string,mixed> */
    public array $data = [];
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function forget(string $key): void { unset($this->data[$key]); }
    public function regenerate(): void {}
    public function destroy(): void { $this->data = []; }
};
$emptyJar = static fn (): Cookies => new class implements Cookies {
    /** @var array<string,string> */
    public array $jar = [];
    public function get(string $name): ?string { return $this->jar[$name] ?? null; }
    public function set(string $name, string $value, int $expiresAt): void { $this->jar[$name] = $value; }
    public function delete(string $name): void { unset($this->jar[$name]); }
};

Assert::group('Guessing a password takes longer than a night');

$pdo2 = TestDatabase::fresh();
$users2 = new UserRepository($pdo2);
$users2->create('m@example.org', 'ein-langes-passwort', 'M');

$auth = new Auth($pdo2, $emptySession(), $users2, $emptyJar());
$max = (new ReflectionClassConstant(Auth::class, 'MAX_ATTEMPTS'))->getValue();

/* The two counters exist for two different attacks. Counting per account
 * stops one password being guessed; counting per address stops one machine
 * working through a list of accounts. So the second is not the first with a
 * different column - it has to hold while every single account is still
 * under its own limit.
 */
$fail = static function (string $email, string $ip) use ($pdo2): void {
    $pdo2->prepare('INSERT INTO login_attempts (identifier, ip, succeeded) VALUES (?, ?, 0)')
        ->execute([$email, inet_pton($ip)]);
};

for ($i = 0; $i < $max - 1; $i++) {
    $fail('m@example.org', '203.0.113.9');
}
Assert::true('below the limit the account is still open', !$auth->isLockedOut('m@example.org', '203.0.113.9'));
$fail('m@example.org', '203.0.113.9');
Assert::true('at the limit it is shut', $auth->isLockedOut('m@example.org', '203.0.113.9'));

// A second account, untouched, from a different address.
Assert::true('and nobody else is shut out with it', !$auth->isLockedOut('other@example.org', '198.51.100.1'));

/* The address limit is twice the account one, so that a household behind one
 * address does not lock itself out by mistyping in turn. */
$spread = TestDatabase::fresh();
$usersSpread = new UserRepository($spread);
$usersSpread->create('m@example.org', 'ein-langes-passwort', 'M');
$auth2 = new Auth($spread, $emptySession(), $usersSpread, $emptyJar());

// One address, a different account every time, so no account reaches its own
// limit and only the address counter can catch this.
for ($i = 0; $i < $max * 2 - 1; $i++) {
    $spread->prepare('INSERT INTO login_attempts (identifier, ip, succeeded) VALUES (?, ?, 0)')
        ->execute(['konto' . $i . '@example.org', inet_pton('203.0.113.50')]);
}
Assert::true(
    'below twice the limit the address is still open',
    !$auth2->isLockedOut('neu@example.org', '203.0.113.50')
);
$spread->prepare('INSERT INTO login_attempts (identifier, ip, succeeded) VALUES (?, ?, 0)')
    ->execute(['letztes@example.org', inet_pton('203.0.113.50')]);
Assert::true(
    'at twice the limit the whole address is shut',
    $auth2->isLockedOut('neu@example.org', '203.0.113.50')
);
Assert::true(
    'while the same account from elsewhere still gets a turn',
    !$auth2->isLockedOut('neu@example.org', '198.51.100.2')
);

Assert::group('A nightly run stays inside the hour it was given');

/* Clamped rather than refused: the number arrives in a query string, and a
 * cron entry with a typo in it should still do a night's work rather than
 * none. */
Assert::same('the default is two minutes', CronController::budgetFor(120), 120);
Assert::same('a sensible number is taken as it is', CronController::budgetFor(90), 90);
Assert::same('nothing below twenty seconds', CronController::budgetFor(1), 20);
Assert::same('nor below that', CronController::budgetFor(-5000), 20);
Assert::same('and nothing above four minutes', CronController::budgetFor(99999), 240);
Assert::same('the two ends themselves are allowed', CronController::budgetFor(20), 20);
Assert::same('both of them', CronController::budgetFor(240), 240);

Assert::group('One clock, whatever the host set the two of them to');

/* Both limits measured their window on PHP's clock and read the time of each
 * attempt from the column's CURRENT_TIMESTAMP - the database's clock. The two
 * agree only while they sit in the same time zone, and which one a host picks
 * for each is not something the code knows. With the database two hours
 * behind PHP, every attempt was already two hours old when it was made and
 * neither limit could ever trigger.
 *
 * Here PHP is put fourteen hours ahead of the database, which is UTC in these
 * tests. Before the fix both assertions below failed. */
$realZone = date_default_timezone_get();
date_default_timezone_set('Pacific/Kiritimati');
try {
    $clocks = TestDatabase::fresh();
    $clockUsers = new UserRepository($clocks);
    $clockUsers->create('m@example.org', 'ein-langes-passwort', 'M');
    $clockAuth = new Auth($clocks, $emptySession(), $clockUsers, $emptyJar());
    for ($i = 0; $i < $max; $i++) {
        $clockAuth->attempt('m@example.org', 'falsch', false, '203.0.113.77');
    }
    Assert::true('the lockout still shuts the account', $clockAuth->isLockedOut('m@example.org', '203.0.113.77'));

    $clockHits = new App\Repository\LookupHitRepository($clocks);
    $packedHit = (string) inet_pton('192.0.2.99');
    $allowedHits = 0;
    for ($i = 0; $i < $limit + 3; $i++) {
        $allowedHits += $clockHits->allow($packedHit, $limit, new DateTimeImmutable()) ? 1 : 0;
    }
    Assert::same('and the lookup throttle still stops at its limit', $allowedHits, $limit);
} finally {
    date_default_timezone_set($realZone);
}

/* The window is the clock it is handed, which is also what makes it
 * checkable: a minute later by that clock, the address has its share back. */
$window = new App\Repository\LookupHitRepository(TestDatabase::fresh());
$noon = new DateTimeImmutable('2026-01-01 12:00:00');
for ($i = 0; $i < $limit; $i++) {
    $window->allow('ip-a', $limit, $noon);
}
Assert::true('at the limit, the same minute is refused', !$window->allow('ip-a', $limit, $noon->modify('+30 seconds')));
Assert::true('the next minute is not', $window->allow('ip-a', $limit, $noon->modify('+61 seconds')));

/* Nothing removed a hit before, and one is only ever asked about for a
 * minute, so the table grew by a row for every book ever looked up. */
Assert::same('the nightly job clears what is older than a day', $window->purgeBefore($noon->modify('+1 day')), $limit + 1);
Assert::true('and the cron runs it', str_contains((string) file_get_contents(PROJECT_ROOT . '/app/Controller/CronController.php'), 'lookupHits->purgeBefore('));
