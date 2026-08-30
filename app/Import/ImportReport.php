<?php
declare(strict_types=1);

namespace App\Import;

/**
 * What an import run did, and what a human should look at afterwards.
 */
final class ImportReport
{
    public int $rows = 0;
    public int $imported = 0;
    public int $duplicates = 0;
    public int $withoutIsbn = 0;
    public int $invalidIsbn = 0;
    public int $ambiguousAuthors = 0;
    public int $authorsCreated = 0;
    public int $tagsCreated = 0;
    public int $bulkDated = 0;

    /** @var array<string,int> */
    public array $bulkDates = [];
    /** @var list<array{row: int, title: string, note: string}> */
    public array $review = [];
    /** @var list<string> */
    public array $errors = [];

    public int $pages = 0;
    public float $spend = 0.0;
    /** @var array<string,int> */
    public array $statuses = [];

    public function flag(int $row, string $title, string $note): void
    {
        $this->review[] = ['row' => $row, 'title' => $title, 'note' => $note];
    }

    /**
     * The report, in the interface language.
     *
     * Translated rather than written in one language, because this is read by
     * the person importing rather than by whoever wrote the code - on the
     * command line as much as in the browser. Off the web, no locale has been
     * negotiated and the translator falls back to German on its own.
     */
    public function asText(bool $dryRun): string
    {
        $lines = [];
        $lines[] = $dryRun ? t('import.dryrun') : t('import.done');
        $lines[] = str_repeat('=', 62);

        $figure = static function (string $key, int|string $value) use (&$lines): void {
            $lines[] = sprintf('%-26s%8s', t($key), $value);
        };

        $figure('import.rows', $this->rows);
        $figure('import.imported', $this->imported);
        $figure('import.duplicates', $this->duplicates);
        $figure('import.authors', $this->authorsCreated);
        $figure('import.tags', $this->tagsCreated);
        $lines[] = '';
        $figure('import.noisbn', $this->withoutIsbn);
        $figure('import.badisbn', $this->invalidIsbn);
        $figure('import.ambiguous', $this->ambiguousAuthors);
        $lines[] = '';
        $figure('import.pages', number_format($this->pages, 0, ',', '.'));
        $figure('import.value', number_format($this->spend, 2, ',', '.') . ' €');
        foreach ($this->statuses as $status => $count) {
            $lines[] = sprintf('  %-24s%8d', $status, $count);
        }

        if ($this->bulkDates !== []) {
            $lines[] = '';
            $lines[] = t('import.bulk', ['count' => $this->bulkDated]);
            foreach ($this->bulkDates as $date => $count) {
                $lines[] = sprintf('  %s  %5d', $date, $count);
            }
        }

        if ($this->review !== []) {
            $lines[] = '';
            $lines[] = t('import.review', ['count' => count($this->review)]);
            foreach (array_slice($this->review, 0, 25) as $item) {
                $lines[] = sprintf(
                    '  %-5d %-42s %s',
                    $item['row'],
                    mb_substr($item['title'], 0, 42),
                    $item['note']
                );
            }
            if (count($this->review) > 25) {
                $lines[] = '  ' . t('import.more', ['count' => count($this->review) - 25]);
            }
        }

        if ($this->errors !== []) {
            $lines[] = '';
            $lines[] = t('import.errors', ['count' => count($this->errors)]);
            foreach (array_slice($this->errors, 0, 20) as $error) {
                $lines[] = '  ' . $error;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
