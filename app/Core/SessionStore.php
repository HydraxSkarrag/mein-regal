<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The part of the session that signing in actually needs.
 *
 * Auth has no business with flash messages or cookie parameters; narrowing the
 * dependency to these four calls keeps it honest and makes it testable without
 * a live PHP session.
 */
interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function forget(string $key): void;

    /** New session id, same contents - called on sign-in. */
    public function regenerate(): void;

    public function destroy(): void;
}
