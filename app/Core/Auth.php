<?php
declare(strict_types=1);

namespace App\Core;

use App\Repository\UserRepository;
use PDO;

/**
 * Signing in.
 *
 * Deliberately not the "?key=..." approach the quiz on the neighbouring
 * subdomain uses: a secret in the URL ends up in browser history, in server
 * logs and, via the referer header, potentially at third parties. On a site
 * with a public half that is a real leak.
 *
 * "Stay signed in" stores a random token whose secret half is only ever kept
 * as a hash, split into selector and validator so the lookup can use an index
 * without comparing secrets in SQL. The token is replaced each time it is
 * used, so a stolen cookie stops working as soon as the real browser returns.
 */
final class Auth
{
    private const COOKIE = 'regal_remember';
    private const TOKEN_DAYS = 60;

    /** Lockout after this many failures within the window. */
    private const MAX_ATTEMPTS = 8;
    private const WINDOW_MINUTES = 15;

    private ?array $user = null;
    private bool $resolved = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SessionStore $session,
        private readonly UserRepository $users,
        private readonly Cookies $cookies,
    ) {
    }

    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $userId = $this->session->get('user_id');
        if (is_int($userId) || (is_string($userId) && ctype_digit($userId))) {
            $this->user = $this->users->findById((int) $userId);
            if ($this->user !== null) {
                return $this->user;
            }
            $this->session->forget('user_id');
        }

        $this->user = $this->userFromRememberCookie();

        return $this->user;
    }

    public function isSignedIn(): bool
    {
        return $this->user() !== null;
    }

    public function userId(): ?int
    {
        $user = $this->user();

        return $user === null ? null : (int) $user['id'];
    }

    /**
     * @return array{ok: bool, reason: string, retryMinutes: int}
     */
    public function attempt(string $email, string $password, bool $remember, ?string $ip): array
    {
        $email = mb_strtolower(trim($email));

        if ($this->isLockedOut($email, $ip)) {
            return ['ok' => false, 'reason' => 'locked', 'retryMinutes' => self::WINDOW_MINUTES];
        }

        $user = $this->users->findByEmail($email);
        $hash = is_array($user) ? (string) $user['password_hash'] : null;

        // Always run a verification, even with no such account, so the reply
        // takes the same time either way and does not reveal which emails exist.
        $valid = password_verify(
            $password,
            $hash ?? '$2y$12$usesomesillystringfoursaltcinvalidhashvaluethatneververifies'
        );

        $this->recordAttempt($email, $ip, $valid && $user !== null);

        if (!$valid || $user === null) {
            return ['ok' => false, 'reason' => 'failed', 'retryMinutes' => 0];
        }

        if (password_needs_rehash($hash ?? '', PASSWORD_DEFAULT)) {
            $this->users->updatePassword((int) $user['id'], $password);
        }

        $this->signIn($user, $remember);

        return ['ok' => true, 'reason' => '', 'retryMinutes' => 0];
    }

    public function signIn(array $user, bool $remember): void
    {
        // A session id from before sign-in must not survive it.
        $this->session->regenerate();
        $this->session->set('user_id', (int) $user['id']);
        $this->user = $user;
        $this->resolved = true;

        if ($remember) {
            $this->issueRememberToken((int) $user['id']);
        }
    }

    public function signOut(): void
    {
        $this->clearRememberToken();
        $this->session->destroy();
        $this->user = null;
        $this->resolved = true;
    }

    // ---------------------------------------------------------- remember me

    private function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $expires = (new \DateTimeImmutable('+' . self::TOKEN_DAYS . ' days'))->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$userId, $selector, hash('sha256', $validator), $expires]);

        $this->cookies->set(
            self::COOKIE,
            $selector . ':' . $validator,
            time() + self::TOKEN_DAYS * 86400
        );
    }

    private function userFromRememberCookie(): ?array
    {
        $cookie = $this->cookies->get(self::COOKIE) ?? '';
        if (!str_contains($cookie, ':')) {
            return null;
        }
        [$selector, $validator] = explode(':', $cookie, 2);

        $statement = $this->pdo->prepare(
            'SELECT * FROM auth_tokens WHERE selector = ? AND expires_at > ?'
        );
        $statement->execute([$selector, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $token = $statement->fetch();
        if ($token === false) {
            return null;
        }

        if (!hash_equals((string) $token['validator_hash'], hash('sha256', $validator))) {
            // A wrong validator for a real selector means the cookie was
            // tampered with or replayed - drop the token entirely.
            $this->deleteToken($selector);

            return null;
        }

        $user = $this->users->findById((int) $token['user_id']);
        if ($user === null) {
            $this->deleteToken($selector);

            return null;
        }

        // Rotate: each use issues a fresh token and retires the old one.
        $this->deleteToken($selector);
        $this->session->regenerate();
        $this->session->set('user_id', (int) $user['id']);
        $this->issueRememberToken((int) $user['id']);

        return $user;
    }

    private function clearRememberToken(): void
    {
        $cookie = $this->cookies->get(self::COOKIE) ?? '';
        if (str_contains($cookie, ':')) {
            $this->deleteToken(explode(':', $cookie, 2)[0]);
        }
        $this->cookies->delete(self::COOKIE);
    }

    private function deleteToken(string $selector): void
    {
        $statement = $this->pdo->prepare('DELETE FROM auth_tokens WHERE selector = ?');
        $statement->execute([$selector]);
    }

    // ------------------------------------------------------- rate limiting

    /** Counted per account and per address, so neither can be hammered. */
    public function isLockedOut(string $email, ?string $ip): bool
    {
        $since = (new \DateTimeImmutable('-' . self::WINDOW_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE succeeded = 0 AND attempted_at > ? AND identifier = ?'
        );
        $statement->execute([$since, $email]);
        if ((int) $statement->fetchColumn() >= self::MAX_ATTEMPTS) {
            return true;
        }

        if ($ip === null) {
            return false;
        }
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE succeeded = 0 AND attempted_at > ? AND ip = ?'
        );
        $statement->execute([$since, self::packIp($ip)]);

        return (int) $statement->fetchColumn() >= self::MAX_ATTEMPTS * 2;
    }

    private function recordAttempt(string $email, ?string $ip, bool $succeeded): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (identifier, ip, succeeded) VALUES (?, ?, ?)'
        );
        $statement->execute([$email, $ip === null ? null : self::packIp($ip), $succeeded ? 1 : 0]);

        if ($succeeded) {
            $clear = $this->pdo->prepare('DELETE FROM login_attempts WHERE identifier = ? AND succeeded = 0');
            $clear->execute([$email]);
        }
    }

    /** Stored packed so IPv6 fits and the column stays indexable. */
    private static function packIp(string $ip): ?string
    {
        $packed = @inet_pton($ip);

        return $packed === false ? null : $packed;
    }

    /** Housekeeping for the nightly cron: drop what is no longer needed. */
    public function purgeExpired(): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->prepare('DELETE FROM auth_tokens WHERE expires_at < ?')->execute([$now]);

        $cutoff = (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');
        $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$cutoff]);
    }
}
