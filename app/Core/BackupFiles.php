<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The copies the nightly job leaves in storage/backup, and the way to take
 * one home.
 *
 * A backup on the same server is no help when the server is the problem -
 * the account closed, the disk gone, the host gone. The job cannot put a copy
 * anywhere else without being told where and given the keys to it, and a
 * shelf meant to be installed by anybody should not need either. So the
 * files are offered for download behind the sign-in, and taking them off the
 * server is one click by whoever owns them. The database dump and the cover
 * archive are the two things no export contains.
 *
 * Only names the job writes are ever served, and only from that directory:
 * the name in the address is checked against the pattern and then against
 * the listing, never joined onto a path as it came.
 */
final class BackupFiles
{
    private const NAME = '/^regal-(?:covers-)?\d{4}-\d{2}-\d{2}\.(?:sql|csv|zip)$/';

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Newest first.
     *
     * @return list<array{name: string, date: string, kind: string, bytes: int}>
     */
    public function all(): array
    {
        $files = [];
        foreach (glob($this->directory . '/regal-*') ?: [] as $path) {
            $name = basename($path);
            if (!is_file($path) || preg_match(self::NAME, $name) !== 1) {
                continue;
            }
            preg_match('/(\d{4}-\d{2}-\d{2})\.(\w+)$/', $name, $parts);
            $files[] = [
                'name'  => $name,
                'date'  => $parts[1],
                'kind'  => $parts[2],
                'bytes' => (int) filesize($path),
            ];
        }
        /* By date, and within a night the database first: it is the one to
           take if only one is taken. */
        $order = ['sql' => 0, 'zip' => 1, 'csv' => 2];
        usort($files, static fn (array $a, array $b): int => [$b['date'], $order[$a['kind']]] <=> [$a['date'], $order[$b['kind']]]);

        return $files;
    }

    /** The file behind a name from the listing, or null for anything else. */
    public function path(string $name): ?string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return null;
        }
        foreach ($this->all() as $file) {
            if ($file['name'] === $name) {
                return $this->directory . '/' . $name;
            }
        }

        return null;
    }

    public static function contentType(string $name): string
    {
        return match (pathinfo($name, PATHINFO_EXTENSION)) {
            'zip'   => 'application/zip',
            'csv'   => 'text/csv; charset=iso-8859-1',
            default => 'application/sql; charset=utf-8',
        };
    }
}
