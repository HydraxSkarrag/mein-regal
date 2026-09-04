<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * A startup failure whose message is safe to show.
 *
 * The page that appears when the application cannot boot used to say only
 * that it could not boot. Everything else - which of the handful of possible
 * reasons it was - sat in a server log, which on hosting without a shell
 * means a detour through a control panel to learn something the page could
 * have said outright.
 *
 * It could not say it, because a message may carry a path, a query or a
 * password, and this page is public. So the messages that are deliberately
 * written to be safe get their own type, and only that type is shown. A
 * PDOException naming the database user stays a log entry; "config.php is
 * missing" reaches the person standing in front of the blank page.
 *
 * Anything thrown as this must therefore be read as: a stranger may see it.
 */
final class StartupError extends RuntimeException
{
}
