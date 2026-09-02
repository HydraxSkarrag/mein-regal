<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The few rules that can only be known while the page is being built.
 *
 * A bar is 62.4% wide because 1,899 of 3,042 books have been read. No class
 * can say that in advance, and the obvious answer - style="width: 62.4%" -
 * is exactly what the Content-Security-Policy forbids: an inline style
 * attribute needs 'unsafe-inline', and turning that on for one bar turns it
 * on for every attribute on the site.
 *
 * So the values are collected while the templates render and written once
 * into a single <style> element carrying the request's nonce. Templates ask
 * for a class and get one back; nobody has to think about the policy.
 *
 *     <div class="bar-fill <?= $styles->width(62.4) ?>"></div>
 *
 * Identical declarations share a class, which is why a shelf of sixty covers
 * does not produce sixty rules.
 *
 * Nothing here ever sees text somebody typed. The methods take numbers and
 * format them themselves, so there is no way to write a declaration through
 * this that was not written here - which is the property that makes emitting
 * a nonce-bearing style element safe in the first place.
 */
final class Styles
{
    /** @var array<string, string> declaration => class name */
    private array $rules = [];

    /** A width as a percentage of the parent, rounded to a tenth. */
    public function width(float $percent): string
    {
        return $this->rule(sprintf('width:%.1f%%', max(0.0, min(100.0, $percent))));
    }

    /** A ceiling in pixels, for a chart that should not stretch past its data. */
    public function maxWidth(int $pixels): string
    {
        return $this->rule(sprintf('max-width:%dpx', max(0, min(4000, $pixels))));
    }

    /**
     * @param string $declaration already-formatted CSS, e.g. "width:40.0%"
     */
    private function rule(string $declaration): string
    {
        return $this->rules[$declaration] ??= 'u' . count($this->rules);
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /** The stylesheet itself, without the surrounding element. */
    public function css(): string
    {
        $css = '';
        foreach ($this->rules as $declaration => $class) {
            $css .= '.' . $class . '{' . $declaration . '}';
        }

        return $css;
    }
}
