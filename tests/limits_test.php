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
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('The lookup endpoint is not a free proxy');

/* Twenty a minute per address: generous enough that a real scanning session
 * never notices, tight enough that the endpoint is not worth borrowing.
 *
 * Reached through reflection because the counter lives on the controller and
 * the controller wants a whole application behind it. What is tested is the
 * real method, not a copy of the rule.
 */
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, PROJECT_ROOT . '/schema.sql');

$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Application::class, 'pdo'))->setValue($app, $pdo);

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

$pdo2 = new PDO('sqlite::memory:');
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo2->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo2, PROJECT_ROOT . '/schema.sql');
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
$spread = new PDO('sqlite::memory:');
$spread->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$spread->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($spread, PROJECT_ROOT . '/schema.sql');
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
