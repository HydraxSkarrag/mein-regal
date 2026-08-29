<?php
declare(strict_types=1);

namespace App\Import;

use App\Core\Text;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\TagRepository;
use PDO;
use Throwable;

/**
 * Imports a Bookstats CSV export.
 *
 * Runs in two passes. The first only counts acquisition dates, because a date
 * shared by hundreds of books is not an acquisition date at all but the day
 * the shelf was typed in; those books are flagged so statistics can leave them
 * out. The second pass does the actual work.
 *
 * The whole thing can run without writing anything, which is how it should be
 * run first: the report names every duplicate, every missing ISBN and every
 * author field that could not be read with confidence.
 */
final class Importer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BookRepository $books,
        private readonly AuthorRepository $authors,
        private readonly TagRepository $tags,
    ) {
    }

    public function run(CsvReader $reader, int $ownerId, bool $dryRun = true): ImportReport
    {
        $report = new ImportReport();
        $bulkDates = $this->findBulkDates($reader);
        $report->bulkDates = $bulkDates;

        $runId = bin2hex(random_bytes(16));
        $seenIsbns = [];
        $seenTitleAuthor = [];

        if (!$dryRun) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($reader->rows() as $number => $raw) {
                $report->rows++;
                $row = new BookstatsRow($raw);
                $title = $row->title();
                $isbn = $row->isbn13();

                if ($isbn === null) {
                    if ($row->rawIsbn() !== '') {
                        $report->invalidIsbn++;
                        $report->flag($number, $title, 'ISBN unbrauchbar: ' . $row->rawIsbn());
                    } else {
                        $report->withoutIsbn++;
                    }
                }

                // Duplicate detection: by ISBN where there is one, otherwise by
                // title plus first author - six titles genuinely repeat.
                $fingerprint = $isbn ?? Text::fold($title) . '|' . Text::fold($row->authors()['names'][0] ?? '');
                $pool = $isbn !== null ? $seenIsbns : $seenTitleAuthor;
                if (isset($pool[$fingerprint])) {
                    $report->duplicates++;
                    $report->flag($number, $title, 'Dublette zu Zeile ' . $pool[$fingerprint]);
                    continue;
                }
                if ($isbn !== null) {
                    $seenIsbns[$fingerprint] = $number;
                } else {
                    $seenTitleAuthor[$fingerprint] = $number;
                }

                $acquiredAt = $row->acquiredAt();
                $isBulk = $acquiredAt !== null && isset($bulkDates[$acquiredAt]);
                if ($isBulk) {
                    $report->bulkDated++;
                }

                $authors = $row->authors();
                if ($authors['ambiguous']) {
                    $report->ambiguousAuthors++;
                    $report->flag($number, $title, 'Autorenfeld unklar: ' . ($raw['Autor(en)'] ?? ''));
                }

                $report->pages += $row->pageCount() ?? 0;
                $report->spend += $row->price() ?? 0.0;
                $status = $row->readingStatus();
                $report->statuses[$status] = ($report->statuses[$status] ?? 0) + 1;

                if ($dryRun) {
                    $report->imported++;
                    continue;
                }

                $bookId = $this->books->insert($ownerId, [
                    'isbn13'              => $isbn,
                    'isbn10'              => $row->asin(),
                    'title'               => $title,
                    'publisher'           => null,
                    'published_year'      => $row->publishedYear(),
                    'page_count'          => $row->pageCount(),
                    'binding'             => $row->binding(),
                    'price'               => $row->price(),
                    'acquisition_type'    => $row->acquisitionType(),
                    'acquired_at'         => $acquiredAt,
                    'acquired_at_is_bulk' => $isBulk ? 1 : 0,
                    'reading_status'      => $status,
                    'started_at'          => $row->startedAt(),
                    'finished_at'         => $row->finishedAt(),
                    'rating'              => $row->rating(),
                    'notes'               => $row->notes(),
                    'audio_minutes'       => $row->audioMinutes(),
                ]);

                foreach ($authors['names'] as $position => $name) {
                    $authorId = $this->authors->findOrCreate($ownerId, $name, $authorCreated);
                    if ($authorCreated) {
                        $report->authorsCreated++;
                    }
                    $this->authors->link($bookId, $authorId, 'author', $position);
                }

                if ($row->genreIsIdentifier()) {
                    $report->flag($number, $title, 'Genre ist eine Kennung, nicht übernommen: ' . ($raw['Genre'] ?? ''));
                }

                $genre = $row->genre();
                if ($genre !== null) {
                    $tagId = $this->tags->findOrCreate($ownerId, $genre, $tagCreated);
                    if ($tagCreated) {
                        $report->tagsCreated++;
                    }
                    $this->tags->link($bookId, $tagId);
                }

                $this->log($runId, $number, $isbn, $title, 'imported', null);
                $report->imported++;
            }

            if (!$dryRun) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$dryRun && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $report->errors[] = $e->getMessage();
        }

        return $report;
    }

    /**
     * Dates carrying so many books that they cannot be acquisition dates.
     *
     * In the real export 431 books share 23.02.2022 and several hundred more
     * share a handful of days in early 2022 - the sessions in which the
     * existing shelf was entered into Bookstats.
     *
     * @return array<string,int> date => count, highest first
     */
    public function findBulkDates(CsvReader $reader, int $threshold = BookstatsRow::BULK_THRESHOLD): array
    {
        $counts = [];
        foreach ($reader->rows() as $raw) {
            $date = BookstatsRow::germanDate($raw['Erhalten am'] ?? null);
            if ($date !== null) {
                $counts[$date] = ($counts[$date] ?? 0) + 1;
            }
        }

        $bulk = array_filter($counts, static fn (int $n): bool => $n >= $threshold);
        arsort($bulk);

        return $bulk;
    }

    private function log(string $runId, int $row, ?string $isbn, string $title, string $status, ?string $message): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO import_log (run_id, source_row, isbn, title, status, message) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$runId, $row, $isbn, mb_substr($title, 0, 500), $status, $message]);
    }
}
