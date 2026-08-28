<?php
declare(strict_types=1);

namespace App\Lookup;

use App\Core\Text;

/**
 * Cleans up the contributor lists the sources hand over.
 *
 * They arrive dirty in ways worth defending against: Open Library has records
 * whose single author entry reads "Sarah Crossan,Sarah Crossan", and every
 * source occasionally files "Unbekannt" as a person. Deduplication uses the
 * same match key as the rest of the application, so the two spellings of one
 * name collapse here exactly as they do in the database.
 */
final class Contributors
{
    /**
     * @param  iterable<string> $rawNames
     * @return list<array{name: string, role: string}>
     */
    public static function normalize(iterable $rawNames, string $role = 'author'): array
    {
        $people = [];
        $seen = [];

        foreach ($rawNames as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '' || Text::isPlaceholderName($raw)) {
                continue;
            }

            foreach (Text::splitAuthors($raw)['names'] as $name) {
                if ($name === '' || Text::isPlaceholderName($name)) {
                    continue;
                }
                $key = Text::authorMatchKey($name);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $people[] = ['name' => $name, 'role' => $role];
            }
        }

        return $people;
    }

    /**
     * Drop repeats from an already-built list, keeping the first mention of
     * each person and the role it came with.
     *
     * @param  list<array{name: string, role: string}> $people
     * @return list<array{name: string, role: string}>
     */
    public static function dedupe(array $people): array
    {
        $out = [];
        $seen = [];
        foreach ($people as $person) {
            $key = Text::authorMatchKey($person['name']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $person;
        }

        return $out;
    }
}
