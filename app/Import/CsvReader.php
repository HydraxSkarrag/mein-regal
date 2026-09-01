<?php
declare(strict_types=1);

namespace App\Import;

use RuntimeException;

/**
 * Reads the Bookstats export.
 *
 * The file is single-byte, semicolon-separated, with CRLF line endings - not
 * a combination PHP guesses correctly on its own. Reading it as UTF-8 turns
 * "Rückkehr" into mojibake that is then stored, so the conversion happens here
 * once and everything downstream is UTF-8.
 *
 * Windows-1252 rather than ISO-8859-1, which is what this said at first. The
 * two agree on everything from 0xA0 up, and differ in the range 0x80-0x9F:
 * printable punctuation in the one, control characters in the other. The
 * export uses that range - an en dash sits at 0x96 - so reading it as
 * ISO-8859-1 turned a dash into an invisible control character that then
 * travelled into the database intact and unreadable.
 *
 * Every file that really is ISO-8859-1 still decodes correctly here, because
 * the ranges the two share are identical. There is no case where the narrower
 * guess is the better one.
 */
final class CsvReader
{
    public function __construct(
        private readonly string $path,
        private readonly string $sourceEncoding = 'Windows-1252',
        private readonly string $delimiter = ';',
    ) {
    }

    /** @return list<string> */
    public function header(): array
    {
        foreach ($this->rows() as $row) {
            return array_keys($row);
        }

        return [];
    }

    /**
     * @return iterable<int, array<string,string>> row number (1-based, data
     *                                             rows only) => column map
     */
    public function rows(): iterable
    {
        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read ' . $this->path);
        }

        if ($this->sourceEncoding !== 'UTF-8') {
            $converted = mb_convert_encoding($contents, 'UTF-8', $this->sourceEncoding);
            $contents = is_string($converted) ? $converted : $contents;
        }
        // A byte order mark would otherwise end up glued to the first header.
        $contents = preg_replace('/^\x{FEFF}/u', '', $contents) ?? $contents;

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open a memory stream for the import.');
        }
        fwrite($handle, $contents);
        rewind($handle);

        $header = fgetcsv($handle, 0, $this->delimiter, '"', '');
        if (!is_array($header)) {
            fclose($handle);

            return;
        }
        $header = array_map(static fn ($value): string => trim((string) $value), $header);

        $number = 0;
        while (($row = fgetcsv($handle, 0, $this->delimiter, '"', '')) !== false) {
            // fgetcsv yields [null] for a blank line.
            if ($row === [null] || $row === [] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }
            $number++;

            $values = array_map(static fn ($value): string => trim((string) $value), $row);
            // Tolerate rows that are short or long rather than losing them.
            $values = array_pad(array_slice($values, 0, count($header)), count($header), '');

            yield $number => array_combine($header, $values);
        }

        fclose($handle);
    }
}
