<?php
declare(strict_types=1);

namespace App\Content;

use App\Core\Text;
use App\Repository\TagRepository;

/**
 * What the catalogue notation left behind in the tag names.
 *
 * The lookups take a notation off a subject before it becomes a tag, but they
 * only ever stripped the shapes somebody had happened to look at - a capital
 * letter and exactly three digits. Two-digit groups, four-digit ones, decimal
 * ones and the letter-suffixed ones all went through, so the shelf grew a
 * numbered twin of half its own tag list and a "#" heading at the end of the
 * alphabet: "59 Belletristik" beside "Belletristik", the same thing twice.
 *
 * Text::withoutClassification is the rule now, and every source reads it, so
 * nothing new arrives like this. Deciding what to do about what is already
 * stored is this class - once, for both the command-line script and the
 * button in the tag administration, because two of them would drift.
 *
 * Deliberately free of the database beyond one read: it is handed the tags
 * and hands back a plan, which is what lets the confirmation page show
 * exactly what the button will do before anything is written.
 */
final class TagNotation
{
    /**
     * Three outcomes, and the difference between them is the whole point.
     *
     *   rename  no tag under the corrected name yet, so the entry keeps its
     *           books and simply loses the number.
     *   merge   there is one already, which is the common case. The books
     *           move over and the numbered entry is dropped - reversibly.
     *   code    the value is nothing but a notation: "301", "10b". Reported
     *           and never touched, because removing an entry is a decision
     *           about the shelf rather than a correction of it.
     *
     * @return array{
     *     renames: list<array{tag: array<string,mixed>, clean: string}>,
     *     merges:  list<array{from: array<string,mixed>, into: array<string,mixed>}>,
     *     codes:   list<array<string,mixed>>
     * }
     */
    public static function plan(TagRepository $tags, int $ownerId): array
    {
        $all = $tags->listAllByName($ownerId);

        /* Looked up by folded name, because a tag wanting to be called
         * "Belletristik" has to find one already called "belletristik": they
         * would share a slug, and two tags cannot. */
        $byName = [];
        foreach ($all as $tag) {
            $byName[Text::fold((string) $tag['name'])] = $tag;
        }

        $plan = ['renames' => [], 'merges' => [], 'codes' => []];

        foreach ($all as $tag) {
            $name = (string) $tag['name'];
            $clean = Text::withoutClassification($name);

            if ($clean === null) {
                $plan['codes'][] = $tag;
                continue;
            }
            if ($clean === $name) {
                continue;
            }

            $existing = $byName[Text::fold($clean)] ?? null;
            if ($existing !== null && (int) $existing['id'] !== (int) $tag['id']) {
                $plan['merges'][] = ['from' => $tag, 'into' => $existing];
            } else {
                $plan['renames'][] = ['tag' => $tag, 'clean' => $clean];
            }
        }

        return $plan;
    }

    /** How many entries the plan would actually change. */
    public static function changes(array $plan): int
    {
        return count($plan['renames']) + count($plan['merges']);
    }

    /**
     * Carry it out.
     *
     * Renames first. A rename can free nothing and take nothing - it only
     * ever moves an entry from a name with a number to one without - so the
     * merges that follow still find what the plan said they would.
     *
     * @return array{renamed: int, merged: int}
     */
    public static function apply(TagRepository $tags, int $ownerId, array $plan): array
    {
        $renamed = 0;
        foreach ($plan['renames'] as $item) {
            if ($tags->rename($ownerId, (int) $item['tag']['id'], $item['clean'])) {
                $renamed++;
            }
        }

        $merged = 0;
        foreach ($plan['merges'] as $item) {
            $tags->merge($ownerId, (int) $item['from']['id'], (int) $item['into']['id']);
            $merged++;
        }

        return ['renamed' => $renamed, 'merged' => $merged];
    }
}
