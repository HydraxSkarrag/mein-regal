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

if (!function_exists('json_for_script')) {
    /**
     * JSON that is safe to place inside a <script> element.
     *
     * The browser ends a script element at the first "</script>" it sees,
     * wherever that appears - including inside a JSON string. A book title
     * containing one therefore closes the block early and everything after it
     * is parsed as HTML. That is a stored cross-site scripting hole reachable
     * from a book title, so escaping the angle brackets is not optional.
     *
     * JSON_HEX_AMP, _APOS and _QUOT are along for the same reason: they make
     * the output safe inside an attribute as well, so the one helper can be
     * used everywhere without thinking about the context again.
     */
    function json_for_script(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        return (string) json_encode($value, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
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
