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

    /** For <time datetime="..."> and anywhere a machine reads the value. */
    public function iso(?string $isoDate): string
    {
        return $isoDate === null ? '' : substr($isoDate, 0, 10);
    }
}
