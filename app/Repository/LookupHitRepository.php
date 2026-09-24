<?php
declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

/**
 * The ISBN lookup's throttle: how often one address has asked lately.
 *
 * Every time here comes from PHP, the one being compared against included.
 * The table's column has a default of CURRENT_TIMESTAMP, which is the
 * database's clock, and the window was measured on PHP's - two clocks that
 * agree only while both sit in the same time zone. Where the database runs on
 * UTC and PHP on Europe/Berlin, every hit is two hours older than it is and
 * the limit never bites; the other way round, an address stays blocked for two
 * hours. Nothing in the code decides which, the host's settings do.
 */
final class LookupHitRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Record the hit and say whether it was within the limit. */
    public function allow(string $packedIp, int $perMinute, DateTimeImmutable $now): bool
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM lookup_hits WHERE ip = ? AND hit_at > ?');
        $count->execute([$packedIp, $now->modify('-1 minute')->format('Y-m-d H:i:s')]);
        if ((int) $count->fetchColumn() >= $perMinute) {
            return false;
        }

        $this->pdo->prepare('INSERT INTO lookup_hits (ip, hit_at) VALUES (?, ?)')
            ->execute([$packedIp, $now->format('Y-m-d H:i:s')]);

        return true;
    }

    /**
     * Housekeeping for the nightly job. A hit is only ever asked about for a
     * minute, and nothing removed them, so the table grew by one row for
     * every book ever looked up.
     */
    public function purgeBefore(DateTimeImmutable $cutoff): int
    {
        $statement = $this->pdo->prepare('DELETE FROM lookup_hits WHERE hit_at < ?');
        $statement->execute([$cutoff->format('Y-m-d H:i:s')]);

        return $statement->rowCount();
    }
}
