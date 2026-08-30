<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Text normalisation for names, slugs and the messier corners of the import.
 */
final class Text
{
    /**
     * Placeholders the export uses instead of a real author. They must not
     * become person records - "Unbekannt" appears 106 times and would
     * otherwise look like the most prolific author in the collection.
     */
    private const PLACEHOLDERS = ['unbekannt', 'unknown', 'diverse', 'various', 'anonym', 'anonymous', '-', '?'];

    /** Latin letters with diacritics, folded for comparison only - never for display. */
    private const FOLD = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ą' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ė' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ū' => 'u', 'ů' => 'u',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ñ' => 'n', 'ń' => 'n',
        'ś' => 's', 'š' => 's', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'ý' => 'y', 'ÿ' => 'y', 'ł' => 'l', 'đ' => 'd', 'þ' => 'th', 'æ' => 'ae', 'œ' => 'oe',
    ];

    public static function fold(string $value): string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');

        return strtr($lower, self::FOLD);
    }

    /** URL slug. Falls back to a stable placeholder so a slug is never empty. */
    public static function slug(string $value, int $maxLength = 80): string
    {
        $slug = self::fold($value);
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            return 'ohne-titel';
        }
        if (mb_strlen($slug) > $maxLength) {
            $slug = mb_substr($slug, 0, $maxLength);
            $slug = preg_replace('/-[^-]*$/', '', $slug) ?: $slug;
        }

        return trim($slug, '-');
    }

    public static function isPlaceholderName(string $name): bool
    {
        return in_array(self::fold($name), self::PLACEHOLDERS, true);
    }

    /**
     * Split the export's single author field into individual people.
     *
     * The field mixes two incompatible conventions and the comma means
     * different things in each:
     *   "Flechsig, Dorothea"                   - one person, "Last, First"
     *   "Rose Snow"                            - one person, "First Last"
     *   "Flessner, Bernd, Schilling, Peter"    - two people, "Last, First" pairs
     *   "Kobi Yamada, Mae Besom"               - two people, full names
     *
     * The distinguishing signal is whether every comma-separated part is
     * multi-word. In "Last, First" pairs the parts are mostly single words;
     * in a list of full names every part carries at least two. Where the count
     * is odd and the parts are short, the input is genuinely ambiguous - it is
     * returned unsplit and flagged, rather than guessed at.
     *
     * The ambiguous flag means "a human should confirm this", not "no
     * split happened" - the best available reading is still returned so the
     * import produces usable data instead of a wall of unparsed strings.
     *
     * @return array{names: list<string>, ambiguous: bool}
     */
    public static function splitAuthors(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['names' => [], 'ambiguous' => false];
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $p): bool => $p !== ''
        ));

        if (count($parts) <= 1) {
            return ['names' => [self::tidyName($raw)], 'ambiguous' => false];
        }

        // Every part multi-word: a list of complete names, not Last/First pairs.
        if (self::allMultiWord($parts)) {
            return [
                'names'     => array_map([self::class, 'tidyName'], $parts),
                'ambiguous' => false,
            ];
        }

        // Exactly two parts and not both multi-word: "Last, First". Handled
        // separately because a compound surname carries a space of its own -
        // "van Gogh, Vincent" must not become two people.
        if (count($parts) === 2) {
            return [
                'names'     => [self::tidyName($parts[1] . ' ' . $parts[0])],
                'ambiguous' => false,
            ];
        }

        // Mixed lists do occur: "Florian Huber, Kunz, Uli" is one full name
        // followed by one inverted pair. Walking left to right resolves them:
        // a multi-word part stands on its own, a single-word part is a surname
        // and claims the part after it as the given name.
        $names = [];
        $index = 0;
        $count = count($parts);
        while ($index < $count) {
            if (str_contains(self::stripParenthetical($parts[$index]), ' ')) {
                $names[] = self::tidyName($parts[$index]);
                $index++;
                continue;
            }
            if ($index + 1 >= $count) {
                // The scan ran aground on a lone surname. That happens when a
                // surname itself contains a space - "Bürgi Wirth, Babette,
                // Kolb, Stefanie". With an even number of parts, strict
                // two-by-two pairing is the only consistent reading, so use
                // it; an odd number cannot be paired at all.
                if ($count % 2 === 0) {
                    return ['names' => self::pairwise($parts), 'ambiguous' => true];
                }

                return ['names' => [self::tidyName($raw)], 'ambiguous' => true];
            }
            $names[] = self::tidyName($parts[$index + 1] . ' ' . $parts[$index]);
            $index += 2;
        }

        return ['names' => $names, 'ambiguous' => false];
    }

    /**
     * Read the parts strictly as "Last, First" couples.
     *
     * @param  list<string> $parts
     * @return list<string>
     */
    private static function pairwise(array $parts): array
    {
        $names = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $names[] = self::tidyName($parts[$i + 1] . ' ' . $parts[$i]);
        }

        return $names;
    }

    /** @param list<string> $parts */
    private static function allMultiWord(array $parts): bool
    {
        foreach ($parts as $part) {
            if (!str_contains(self::stripParenthetical($part), ' ')) {
                return false;
            }
        }

        return true;
    }

    /** Collapse whitespace; leave spelling and capitalisation untouched. */
    public static function tidyName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    private static function stripParenthetical(string $value): string
    {
        return trim(preg_replace('/\([^)]*\)/u', '', $value) ?? $value);
    }

    /**
     * Comparison key that collapses the two spellings of the same person.
     * "Flechsig, Dorothea" and "Dorothea Flechsig" both reduce to
     * "dorothea flechsig" because the tokens are sorted before joining.
     */
    public static function authorMatchKey(string $name): string
    {
        $folded = self::fold(self::stripParenthetical($name));
        $folded = preg_replace('/[^a-z0-9\s]+/u', ' ', $folded) ?? $folded;
        $tokens = array_values(array_filter(explode(' ', $folded), static fn (string $t): bool => $t !== ''));
        sort($tokens, SORT_STRING);

        return implode(' ', $tokens);
    }

    /** "Bernd Flessner" -> "Flessner, Bernd", for alphabetical listing. */
    public static function sortName(string $name): string
    {
        $name = self::tidyName($name);
        if (str_contains($name, ',')) {
            return $name;
        }
        $tokens = explode(' ', $name);
        if (count($tokens) < 2) {
            return $name;
        }
        $last = array_pop($tokens);

        return $last . ', ' . implode(' ', $tokens);
    }

    /**
     * Render owner-written prose as safe HTML.
     *
     * Kept as the one name the templates call; the rules live in Markup,
     * which escapes before it structures so that nothing an author types can
     * become markup.
     */
    public static function prose(?string $raw): string
    {
        return Markup::render($raw);
    }

    /** Shorten for display without cutting a word in half. */
    public static function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }
        $cut = mb_substr($value, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > $length * 0.6) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:-") . '…';
    }
}
