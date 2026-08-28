<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Cookies;
use App\Core\SessionStore;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('Auth');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');

$users = new UserRepository($pdo);
$userId = $users->create('maike@example.org', 'ein-langes-passwort', 'Maike', 'de');

// A session that keeps state in an array, so no cookies or headers are needed.
$session = new class implements SessionStore {
    /** @var array<string,mixed> */
    public array $data = [];
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function forget(string $key): void { unset($this->data[$key]); }
    public function regenerate(): void {}
    public function destroy(): void { $this->data = []; }
};

// Cookies held in memory, so no headers are sent from a test run.
$cookieJar = new class implements Cookies {
    /** @var array<string,string> */
    public array $jar = [];
    public function get(string $name): ?string { return $this->jar[$name] ?? null; }
    public function set(string $name, string $value, int $expiresAt): void { $this->jar[$name] = $value; }
    public function delete(string $name): void { unset($this->jar[$name]); }
};

$auth = new Auth($pdo, $session, $users, $cookieJar);

Assert::same('nobody is signed in to begin with', $auth->isSignedIn(), false);

Assert::group('Auth: wrong credentials');

$wrong = $auth->attempt('maike@example.org', 'falsch', false, '192.0.2.10');
Assert::same('a wrong password is refused', $wrong['ok'], false);
Assert::same('and reported as a plain failure', $wrong['reason'], 'failed');

$unknown = $auth->attempt('niemand@example.org', 'egal', false, '192.0.2.10');
Assert::same('an unknown account is refused the same way', $unknown['reason'], 'failed');

Assert::group('Auth: correct credentials');

$ok = $auth->attempt('maike@example.org', 'ein-langes-passwort', false, '192.0.2.10');
Assert::same('the right password is accepted', $ok['ok'], true);
Assert::same('and the session now knows the user', $session->data['user_id'] ?? null, $userId);

// A successful sign-in clears that account's failures, so earlier typos do
// not accumulate towards a lockout. Other accounts are untouched.
$forMaike = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0 AND identifier = ?');
$forMaike->execute(['maike@example.org']);
Assert::same('the account\'s failed attempts are cleared on success', (int) $forMaike->fetchColumn(), 0);

$forOthers = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0 AND identifier <> ?');
$forOthers->execute(['maike@example.org']);
Assert::same('another account\'s attempts are left alone', (int) $forOthers->fetchColumn(), 1);

$auth->signOut();
Assert::same('signing out empties the session', $session->data, []);

Assert::group('Auth: lockout');

$fresh = new Auth($pdo, $session, $users, $cookieJar);
for ($i = 0; $i < 8; $i++) {
    $fresh->attempt('maike@example.org', 'falsch-' . $i, false, '192.0.2.20');
}
$locked = $fresh->attempt('maike@example.org', 'ein-langes-passwort', false, '192.0.2.20');
Assert::same('the account locks after repeated failures', $locked['reason'], 'locked');
Assert::true('and says how long to wait', $locked['retryMinutes'] > 0);

// The lockout is per account: a different account is unaffected.
$otherId = $users->create('daniel@example.org', 'noch-ein-passwort', 'Daniel', 'en');
$other = $fresh->attempt('daniel@example.org', 'noch-ein-passwort', false, '192.0.2.30');
Assert::same('a different account still gets in', $other['ok'], true);

Assert::group('Auth: housekeeping');

$pdo->prepare('INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)')
    ->execute([$userId, str_repeat('a', 32), str_repeat('b', 64), '2020-01-01 00:00:00']);
$fresh->purgeExpired();
Assert::same('expired tokens are removed', (int) $pdo->query('SELECT COUNT(*) FROM auth_tokens')->fetchColumn(), 0);

Assert::group('Auth: stay signed in');

$jar = new class implements Cookies {
    /** @var array<string,string> */
    public array $jar = [];
    public function get(string $name): ?string { return $this->jar[$name] ?? null; }
    public function set(string $name, string $value, int $expiresAt): void { $this->jar[$name] = $value; }
    public function delete(string $name): void { unset($this->jar[$name]); }
};
$makeSession = static fn () => new class implements SessionStore {
    public array $data = [];
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function forget(string $key): void { unset($this->data[$key]); }
    public function regenerate(): void {}
    public function destroy(): void { $this->data = []; }
};

$first = new Auth($pdo, $makeSession(), $users, $jar);
$first->attempt('daniel@example.org', 'noch-ein-passwort', true, '192.0.2.40');
Assert::true('a token cookie is issued', isset($jar->jar['regal_remember']));
Assert::true('the cookie is a selector and a validator', str_contains($jar->jar['regal_remember'], ':'));

// The secret half must never be stored as it travels.
$stored = $pdo->query('SELECT selector, validator_hash FROM auth_tokens ORDER BY id DESC LIMIT 1')->fetch();
[$selector, $validator] = explode(':', $jar->jar['regal_remember'], 2);
Assert::same('the selector is stored as-is', $stored['selector'], $selector);
Assert::same('but the validator only as a hash', $stored['validator_hash'], hash('sha256', $validator));
Assert::true('the raw validator is nowhere in the table', $stored['validator_hash'] !== $validator);

// A fresh browser session with only the cookie gets back in.
$returning = new Auth($pdo, $makeSession(), $users, $jar);
Assert::same('the cookie signs the user back in', $returning->isSignedIn(), true);
Assert::same('as the right person', $returning->user()['email'] ?? null, 'daniel@example.org');

// Using it rotates the token, so a copied cookie stops working.
Assert::true('the cookie was replaced on use', $jar->jar['regal_remember'] !== $selector . ':' . $validator);
$oldCookieJar = new class implements Cookies {
    public array $jar = [];
    public function get(string $name): ?string { return $this->jar[$name] ?? null; }
    public function set(string $name, string $value, int $expiresAt): void { $this->jar[$name] = $value; }
    public function delete(string $name): void { unset($this->jar[$name]); }
};
$oldCookieJar->jar['regal_remember'] = $selector . ':' . $validator;
$replay = new Auth($pdo, $makeSession(), $users, $oldCookieJar);
Assert::same('the retired cookie no longer works', $replay->isSignedIn(), false);

// A tampered validator invalidates the token instead of being ignored.
[$liveSelector, $liveValidator] = explode(':', $jar->jar['regal_remember'], 2);
$tampered = new class implements Cookies {
    public array $jar = [];
    public function get(string $name): ?string { return $this->jar[$name] ?? null; }
    public function set(string $name, string $value, int $expiresAt): void { $this->jar[$name] = $value; }
    public function delete(string $name): void { unset($this->jar[$name]); }
};
$tampered->jar['regal_remember'] = $liveSelector . ':' . str_repeat('0', 64);
$attacker = new Auth($pdo, $makeSession(), $users, $tampered);
Assert::same('a guessed validator is refused', $attacker->isSignedIn(), false);

$stillThere = $pdo->prepare('SELECT COUNT(*) FROM auth_tokens WHERE selector = ?');
$stillThere->execute([$liveSelector]);
Assert::same('and the token is dropped rather than left live', (int) $stillThere->fetchColumn(), 0);

$signedOut = new Auth($pdo, $makeSession(), $users, $jar);
$signedOut->signOut();
Assert::same('signing out removes the cookie', isset($jar->jar['regal_remember']), false);
