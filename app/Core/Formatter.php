<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Locale-aware dates and numbers.
 *
 * Translating labels but leaving "12,90 €" and "23.02.2022" untouched makes an
 * English interface look half finished, so the formats move with the language.
 *
 * intl is used where available and hand-rolled otherwise: it is present on the
 * development machine but must not be assumed on shared hosting.
 */
final class Formatter
{
    private readonly bool $hasIntl;

    public function __construct(private readonly string $locale = 'de')
    {
        $this->hasIntl = class_exists(NumberFormatter::class);
    }

    private function icuLocale(): string
    {
        return $this->locale === 'en' ? 'en_GB' : 'de_DE';
    }

    /** An ISO date (2022-02-23) rendered for reading. */
    public function date(?string $isoDate): string
    {
        if ($isoDate === null || $isoDate === '') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($isoDate, 0, 10));
        if ($date === false) {
            return $isoDate;
        }

        if ($this->hasIntl && class_exists(IntlDateFormatter::class)) {
            $formatter = new IntlDateFormatter(
                $this->icuLocale(),
                IntlDateFormatter::MEDIUM,
                IntlDateFormatter::NONE
            );
            $formatted = $formatter->format($date);
            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return $date->format($this->locale === 'en' ? 'j M Y' : 'd.m.Y');
    }

    public function number(int|float|null $value, int $decimals = 0): string
    {
        if ($value === null) {
            return '';
        }

        if ($this->hasIntl) {
            $formatter = new NumberFormatter($this->icuLocale(), NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);
            $formatted = $formatter->format($value);
            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return $this->locale === 'en'
            ? number_format((float) $value, $decimals, '.', ',')
            : number_format((float) $value, $decimals, ',', '.');
    }

    public function money(int|float|null $value, string $currency = 'EUR'): string
    {
        if ($value === null) {
            return '';
        }

        if ($this->hasIntl) {
            $formatter = new NumberFormatter($this->icuLocale(), NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency((float) $value, $currency);
            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return $this->locale === 'en'
            ? '€' . number_format((float) $value, 2, '.', ',')
            : number_format((float) $value, 2, ',', '.') . ' €';
    }

    /**
     * A rating as stars, halves included.
     *
     * Returns the pieces rather than markup, so the shelf tile and the detail
     * page can present them differently without agreeing on a class name.
     *
     * @return array{full: int, half: bool, empty: int, text: string}
     */
    public static function stars(int|float|string|null $rating): ?array
    {
        if ($rating === null || $rating === '') {
            return null;
        }
        $value = round((float) $rating * 2) / 2;
        if ($value <= 0) {
            return null;
        }
        $value = min(5.0, $value);

        $full = (int) floor($value);
        $half = ($value - $full) >= 0.5;

        return [
            'full'  => $full,
            'half'  => $half,
            'empty' => 5 - $full - ($half ? 1 : 0),
            // "4,5" reads better than "4.5" in German and the formatter
            // already knows which is which.
            'text'  => rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ','),
        ];
    }

    /**
     * The same rating as plain text, for places that cannot hold markup.
     *
     * A dropdown option and an escaped label have no room for a span, and the
     * obvious character for the job - U+2BEA, star with left half black - is
     * missing from most system fonts, so it arrived as a question mark. The
     * vulgar fraction has been in every font since Latin-1.
     */
    public static function starsText(int|float|string|null $rating): ?string
    {
        $parts = self::stars($rating);
        if ($parts === null) {
            return null;
        }

        return str_repeat('★', $parts['full']) . ($parts['half'] ? '½' : '');
    }

    /** For <time datetime="..."> and anywhere a machine reads the value. */
    public function iso(?string $isoDate): string
    {
        return $isoDate === null ? '' : substr($isoDate, 0, 10);
    }
}
