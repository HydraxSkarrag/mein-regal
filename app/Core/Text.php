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
            /* Unless the first part is the whole name already and the second
             * only repeats its beginning.
             *
             * The DNB writes some contributors as "James Nestor, James" - the
             * surname field holding the full name, the given name after the
             * comma a second time. Flipped like an ordinary inverted pair
             * that becomes "James James Nestor", a person who does not exist,
             * standing in the author list beside the one who does. */
            if (self::repeatsGivenName($parts[0], $parts[1])) {
                return ['names' => [self::tidyName($parts[0])], 'ambiguous' => false];
            }

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
     * Does "James Nestor" already begin with "James"?
     *
     * Whole words and folded, so case and accents do not decide it. The first
     * part has to carry more than one word: "Thomas, Thomas" is somebody
     * called Thomas Thomas and has to stay that way, while "Thomas Mann,
     * Thomas" is one name written one and a half times.
     */
    private static function repeatsGivenName(string $whole, string $given): bool
    {
        $wholeWords = explode(' ', self::fold(self::tidyName($whole)));
        $givenWords = explode(' ', self::fold(self::tidyName($given)));

        if (count($wholeWords) < 2 || $givenWords === [''] || count($givenWords) >= count($wholeWords)) {
            return false;
        }

        return array_slice($wholeWords, 0, count($givenWords)) === $givenWords;
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

    /**
     * What a bracketed marker in a name means, in either language and either
     * bracket shape. The export uses "(Ill.)", "[Hrsg.]" and the spelled-out
     * forms interchangeably.
     */
    private const ROLE_MARKERS = [
        'aut' => 'author', 'autor' => 'author', 'autorin' => 'author',
        'ill' => 'illustrator', 'illustr' => 'illustrator',
        'illustrator' => 'illustrator', 'illustratorin' => 'illustrator',
        'hrsg' => 'editor', 'hg' => 'editor', 'herausgeber' => 'editor',
        'uebers' => 'translator', 'übers' => 'translator',
        'uebersetzer' => 'translator', 'übersetzerin' => 'translator',
    ];

    /**
     * Split "Eva Gebhardt (Ill.)" into the person and what she did.
     *
     * The export writes the role into the author field, and the import used
     * to take the whole string as a name and file every link under 'author'.
     * The marker then sat in the displayed name for good, and the role column
     * - which exists - was never used at all.
     *
     * Only a marker that names a role is removed. "A. A. (Alan Alexander)
     * Milne" is an expanded initial and keeps its brackets; the sort name
     * ignores them either way.
     *
     * @return array{name: string, role: string}
     */
    public static function splitRole(string $name): array
    {
        if (preg_match_all('/\(([^)]*)\)|\[([^\]]*)\]/u', $name, $matches, PREG_SET_ORDER) === 0) {
            return ['name' => self::tidyName($name), 'role' => 'author'];
        }

        $role = null;
        $remove = [];
        foreach ($matches as $match) {
            $inside = trim($match[2] ?? '') !== '' ? trim($match[2]) : trim($match[1]);
            $found = self::ROLE_MARKERS[mb_strtolower(rtrim($inside, '.'))] ?? null;
            if ($found !== null) {
                $role = $found;
                $remove[] = $match[0];
            }
        }

        if ($remove === []) {
            return ['name' => self::tidyName($name), 'role' => 'author'];
        }

        $bare = str_replace($remove, ' ', $name);

        return [
            'name' => self::tidyName(preg_replace('/\s{2,}/u', ' ', $bare) ?? $bare),
            'role' => $role ?? 'author',
        ];
    }

    /**
     * Drop bracketed asides: role markers, and expansions of an initial.
     *
     * Both bracket shapes, because the export uses both - "(Ill.)" and
     * "[Hrsg.]" are the same idea written two ways, and a rule that knew only
     * one of them filed Bruno Horst Bull under "Bull, Bruno Horst [Hrsg.]".
     */
    private static function stripParenthetical(string $value): string
    {
        $stripped = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s{2,}/u', ' ', $stripped) ?? $stripped);
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

    /**
     * "Bernd Flessner" -> "Flessner, Bernd", for alphabetical listing.
     *
     * A trailing role marker is dropped first, the same way authorMatchKey
     * already drops it. Without that, the last token of "Eva Gebhardt (Ill.)"
     * is the marker, so she sorted as "(Ill.), Eva Gebhardt" - filed under a
     * bracket instead of under G. Eleven names came out of the Bookstats
     * import that way; the export writes the role into the author field, and
     * nothing here was looking for it.
     */
    public static function sortName(string $name): string
    {
        $name = self::tidyName(self::stripParenthetical($name));
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
     * Text with a catalogue's non-sorting marks taken out.
     *
     * MARC brackets a leading article so that a sort can skip it: "Die" in
     * "Die Chronik der Drachenlanze" is wrapped in U+0098 and U+009C, the
     * control characters libraries use for exactly this. They mean "ignore
     * what is between us when filing" - the words themselves are part of the
     * title and stay.
     *
     * Those code points are illegal in XML 1.0, so the DNB writes them as
     * escaped character references: what comes out of the parser is the
     * literal text "&#152;Die&#156;". PHP will not decode those back either -
     * html_entity_decode refuses a reference to a forbidden code point - so
     * they travelled all the way onto the shelf as visible text, into the
     * title, into the slug, and into the address bar.
     *
     * Measured on four hundred MARC records: sixty-one carried them. It is
     * one in six German titles beginning with an article, not an oddity.
     *
     * Both spellings are taken out, the escaped one and the raw characters,
     * because only the first has been seen and the second is the same thing
     * one parser setting away.
     */
    /**
     * Articles that are skipped when a title is filed under a letter.
     *
     * Every library does this and has done it for a century: "Die Chronik der
     * Drachenlanze" files under C, "The Hobbit" under H. The catalogue this
     * shelf asks says so in the record itself - MARC marks the article with a
     * non-sorting character, and the DNB delivers those (which Text strips a
     * few lines down). A name somebody typed carries no such mark, so the
     * words have to be known.
     *
     * German takes the whole declension of the definite article, not just
     * the three nominatives: "Des Kaisers neue Kleider" and "Dem Himmel so
     * nah" file under K and H. English adds a/an/the. The two that turn up on
     * a German shelf without anybody thinking of them as foreign are French
     * and Italian - "Le Petit Prince", "Il Nome della Rosa".
     *
     * Where it stops, and why. "Einer", "eines", "einem", "einen" are
     * articles in a grammar book and pronouns in a title: "Einer flog über
     * das Kuckucksnest" is "one flew", and filing it under F would be worse
     * than leaving it under E. This is the ambiguity a library record does
     * not have - MARC marks the article in the record instead of guessing
     * from the word - so where the word alone cannot decide, the word stays.
     *
     * Dutch is left out for the same reason: "de" would be safe, "het" would
     * not, and there is no Dutch on this shelf to pay for the risk.
     */
    private const FILING_ARTICLES = [
        // German. The definite article in every case, and only the two
        // indefinite forms that are reliably articles.
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine',
        // English
        'the', 'a', 'an',
        // French, including the elided l' before a vowel
        'le', 'la', 'les', 'l', 'un', 'une',
        // Italian
        'il', 'lo', 'gli', 'uno',
    ];

    /**
     * The name with its leading article dropped, for filing under a letter.
     *
     * Only for filing. What is shown stays what was written - a series is
     * called "Die Chronik der Drachenlanze" and appears under C, it does not
     * become "Chronik der Drachenlanze, Die". The comma-turned form is a card
     * catalogue habit and reads as a mistake on a screen.
     *
     * The article has to be followed by something, or "Die" on its own would
     * file as nothing at all.
     */
    public static function filingName(string $name): string
    {
        $trimmed = trim($name);
        if (!preg_match('/^(\p{L}+)[\s\x{2019}\']+(.+)$/u', $trimmed, $parts)) {
            return $trimmed;
        }

        return in_array(mb_strtolower($parts[1]), self::FILING_ARTICLES, true)
            ? trim($parts[2])
            : $trimmed;
    }

    /**
     * Is this subtitle just the series name again?
     *
     * Catalogues put the series in the subtitle, because a record has to say
     * it somewhere and the subtitle is where it fits: "Drachenzwielicht" with
     * "Die Chronik der Drachenlanze 1" under it. Once the shelf shows the
     * series on a line of its own, that subtitle is the same words twice in
     * three lines, and the reader has to work out that they are not two
     * different facts.
     *
     * Only when it is exactly the name plus a number - letters compared,
     * everything else dropped. "Die Sturmlicht-Chroniken 7. Roman" keeps its
     * "Roman" and is therefore shown: it says something the series line does
     * not, and dropping a subtitle that carries anything of its own would be
     * worse than repeating one.
     */
    public static function repeatsSeries(string $subtitle, string $seriesName): bool
    {
        $letters = static fn (string $value): string => (string) preg_replace(
            '/[^\p{L}]+/u',
            '',
            mb_strtolower($value)
        );

        $subtitle = $letters($subtitle);

        return $subtitle !== '' && $subtitle === $letters($seriesName);
    }

    public static function withoutSortMarks(string $value): string
    {
        return str_replace(
            ['&#152;', '&#156;', '&#x98;', '&#x9c;', '&#x9C;', "\u{0098}", "\u{009C}"],
            '',
            $value
        );
    }

    /**
     * A subject with its classification notation taken off the front.
     *
     * The catalogues file books under a scheme and hand the notation over
     * with the name attached. Measured across seven years of DNB records,
     * sixty-two of sixty-three subject values began with one - and in more
     * shapes than anybody would guess from a handful of examples:
     *
     *     07 Kinder- und Jugendliteratur      two digits
     *     830 Deutsche Literatur              three
     *     0300 Mathematik, Physik, Astronomie four, zero-padded
     *     621.3 Elektrotechnik, Elektronik    a decimal part
     *     10a Erziehung, Unterricht           a letter suffix
     *     301                                 no name at all
     *
     * The rule used to strip a single capital letter or exactly three digits,
     * on the stated grounds that "three digits is what the groups are". Half
     * of the shapes above went straight onto the shelf as tags: "59
     * Belletristik" sat in the list beside "Belletristik", the same thing
     * twice, one of them wearing a number.
     *
     * Two things keep this from eating real subjects.
     *
     * A notation has to be followed by whitespace, which is why the check is
     * not simply "starts with a digit": "20. Jahrhundert" keeps its number.
     * And it is at least two digits, because the schemes here have no
     * one-digit groups while "3 Musketiere" is a perfectly good subject.
     *
     * What is left is a genuine gap: "50 Jahre Bundesrepublik" would come out
     * as "Jahre Bundesrepublik". No shape in the string tells it apart from
     * "59 Belletristik", and the only cure would be the published list of
     * group numbers - a table to keep in step with two catalogues for a case
     * that did not occur once in the measured sample.
     *
     * Null when nothing is left but a notation. A code alone is not a name
     * for a shelf; it is the catalogue talking to itself. A bare four-digit
     * number is spared, because that is a year and somebody may well have
     * filed a book under one.
     */
    public static function withoutClassification(string $subject): ?string
    {
        // Digits, an optional decimal part, an optional single lowercase
        // letter - "621.3", "10a", "0300".
        $notation = '\d{2,4}(?:\.\d+)?[a-z]?';

        $clean = trim($subject);
        $clean = trim(preg_replace('/^(?:[A-Z]|' . $notation . ')\s+/u', '', $clean) ?? $clean);

        // Nothing but a code: two or three digits, or any number carrying a
        // decimal part or a letter. Four plain digits are left alone.
        if ($clean === '' || preg_match('/^(?:[A-Z]|\d{2,3}|\d{2,4}(?:\.\d+|[a-z]))$/u', $clean) === 1) {
            return null;
        }

        return $clean;
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
