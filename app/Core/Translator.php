<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Interface translation.
 *
 * Only the interface. Book titles, author names and publishers stay in the
 * language they were entered - an English title is not Germanised, and a
 * German one is not translated for an English reader.
 *
 * A missing key falls back to German and then to the key itself, so a gap in
 * a translation shows up as readable text rather than an empty label.
 */
final class Translator
{
    public const DEFAULT_LOCALE = 'de';
    public const SUPPORTED = ['de', 'en'];

    private static ?self $instance = null;

    /** @var array<string,string> */
    private array $messages = [];
    /** @var array<string,string> */
    private array $fallback = [];

    public function __construct(private string $locale = self::DEFAULT_LOCALE)
    {
        $this->setLocale($locale);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(self $translator): void
    {
        self::$instance = $translator;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = self::normalizeLocale($locale);
        $this->messages = $this->load($this->locale);
        $this->fallback = $this->locale === self::DEFAULT_LOCALE
            ? $this->messages
            : $this->load(self::DEFAULT_LOCALE);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string,string|int|float> $replacements */
    public function translate(string $key, array $replacements = []): string
    {
        $message = $this->messages[$key] ?? $this->fallback[$key] ?? $key;

        if ($replacements === []) {
            return $message;
        }

        $search = [];
        $replace = [];
        foreach ($replacements as $name => $value) {
            $search[] = '{' . $name . '}';
            $replace[] = (string) $value;
        }

        return str_replace($search, $replace, $message);
    }

    public static function normalizeLocale(?string $locale): string
    {
        $short = strtolower(substr(trim((string) $locale), 0, 2));

        return in_array($short, self::SUPPORTED, true) ? $short : self::DEFAULT_LOCALE;
    }

    /**
     * Best supported match for an Accept-Language header. Used for visitors
     * who have not chosen a language; a signed-in owner's stored preference
     * always wins over this.
     */
    public static function negotiate(?string $acceptLanguage): string
    {
        if ($acceptLanguage === null || trim($acceptLanguage) === '') {
            return self::DEFAULT_LOCALE;
        }

        $best = self::DEFAULT_LOCALE;
        $bestQuality = -1.0;
        foreach (explode(',', $acceptLanguage) as $part) {
            $bits = explode(';q=', trim($part));
            $tag = strtolower(substr(trim($bits[0]), 0, 2));
            $quality = isset($bits[1]) ? (float) $bits[1] : 1.0;
            if (in_array($tag, self::SUPPORTED, true) && $quality > $bestQuality) {
                $best = $tag;
                $bestQuality = $quality;
            }
        }

        return $best;
    }

    /** @return array<string,string> */
    private function load(string $locale): array
    {
        $file = APP_ROOT . '/lang/' . $locale . '.php';
        if (!is_file($file)) {
            return [];
        }
        $messages = require $file;

        return is_array($messages) ? $messages : [];
    }
}
