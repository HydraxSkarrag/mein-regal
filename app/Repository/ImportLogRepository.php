<?php
declare(strict_types=1);

namespace App\Repository;

use PDO;

/** One line per row of an import: what happened to it, and why. */
final class ImportLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $runId, int $row, ?string $isbn, string $title, string $status, ?string $message): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO import_log (run_id, source_row, isbn, title, status, message) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$runId, $row, $isbn, mb_substr($title, 0, 500), $status, $message]);
    }
}
