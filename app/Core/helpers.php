<?php
/**
 * The handful of functions that are used on nearly every page.
 *
 * e() exists because escaping must be the shortest thing to type - anything
 * longer invites the habit of leaving it out.
 */
declare(strict_types=1);

if (!function_exists('e')) {
    /** Escape for HTML text and quoted attribute values. */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('t')) {
    /**
     * Translate an interface string. Content - book titles, author names -
     * is never passed through here; it stays in the language it was entered.
     *
     * @param array<string,string|int> $replacements
     */
    function t(string $key, array $replacements = []): string
    {
        return \App\Core\Translator::instance()->translate($key, $replacements);
    }
}
