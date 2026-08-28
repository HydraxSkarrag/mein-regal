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

    public function asText(bool $dryRun): string
    {
        $lines = [];
        $lines[] = $dryRun
            ? 'TROCKENLAUF - es wurde nichts geschrieben.'
            : 'Import abgeschlossen.';
        $lines[] = str_repeat('=', 62);
        $lines[] = sprintf('Zeilen gelesen        %8d', $this->rows);
        $lines[] = sprintf('Bücher importiert     %8d', $this->imported);
        $lines[] = sprintf('Dubletten übersprungen%8d', $this->duplicates);
        $lines[] = sprintf('Autor:innen angelegt  %8d', $this->authorsCreated);
        $lines[] = sprintf('Tags angelegt         %8d', $this->tagsCreated);
        $lines[] = '';
        $lines[] = sprintf('Ohne ISBN             %8d', $this->withoutIsbn);
        $lines[] = sprintf('ISBN unbrauchbar      %8d', $this->invalidIsbn);
        $lines[] = sprintf('Autorenfeld unklar    %8d', $this->ambiguousAuthors);
        $lines[] = '';
        $lines[] = sprintf('Seiten gesamt         %8s', number_format($this->pages, 0, ',', '.'));
        $lines[] = sprintf('Erfasster Wert        %8s €', number_format($this->spend, 2, ',', '.'));
        foreach ($this->statuses as $status => $count) {
            $lines[] = sprintf('  %-20s%8d', $status, $count);
        }

        if ($this->bulkDates !== []) {
            $lines[] = '';
            $lines[] = sprintf(
                'Sammel-Erfassungstage (%d Bücher markiert, damit keine Statistik',
                $this->bulkDated
            );
            $lines[] = 'sie als echte Zugänge liest):';
            foreach ($this->bulkDates as $date => $count) {
                $lines[] = sprintf('  %s  %5d Bücher', $date, $count);
            }
        }

        if ($this->review !== []) {
            $lines[] = '';
            $lines[] = sprintf('Zur Nachkontrolle (%d):', count($this->review));
            foreach (array_slice($this->review, 0, 25) as $item) {
                $lines[] = sprintf('  Zeile %-5d %-42s %s', $item['row'], mb_substr($item['title'], 0, 42), $item['note']);
            }
            if (count($this->review) > 25) {
                $lines[] = sprintf('  ... und %d weitere', count($this->review) - 25);
            }
        }

        if ($this->errors !== []) {
            $lines[] = '';
            $lines[] = sprintf('FEHLER (%d):', count($this->errors));
            foreach (array_slice($this->errors, 0, 20) as $error) {
                $lines[] = '  ' . $error;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
