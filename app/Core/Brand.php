<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Whose logo is on the page.
 *
 * The software ships with a neutral mark so that a fresh installation looks
 * finished rather than borrowed. An operator's own logo does not replace those
 * files - it lives beside them in public/assets/brand/ and wins whenever it is
 * there.
 *
 * That separation is doing three jobs at once. A published repository must not
 * carry someone else's wordmark, since the licence covers the code and not the
 * brand. A deployment overwrites everything it uploads, so a logo kept in the
 * versioned folder would be replaced by the default on the next deploy of a
 * fork. And an installation still running on the shipped mark is a fact worth
 * knowing, which is what usesDefaults() answers for the setup page.
 *
 * The directory is excluded from Git and from the deployment, exactly like
 * public/covers/: both hold files that exist on one server and nowhere else.
 */
final class Brand
{
    /** Where an operator puts their own files. Relative to the document root. */
    public const OVERRIDE = 'assets/brand';

    private const SHIPPED = 'assets';

    /**
     * Override first, shipped default second.
     *
     * SVG is tried ahead of PNG for the logo: it is the only one of the two
     * that stays sharp in a header at any pixel density. The icons are PNG
     * only, because an apple-touch-icon has to be.
     *
     * @var array<string, string[]>
     */
    private const FILES = [
        'logo'    => ['logo.svg', 'logo.png'],
        'icon'    => ['favicon-32x32.png'],
        'tile'    => ['favicon-192x192.png'],
        'touch'   => ['apple-touch-icon.png'],
    ];

    /** @var array<string, string|null> */
    private array $resolved = [];

    /**
     * Where to put your own files, as someone looking at the server sees it.
     *
     * The constant above is relative to the document root because that is what
     * a URL needs; anybody with an FTP client is one level higher up.
     */
    public static function directory(): string
    {
        return 'public/' . self::OVERRIDE . '/';
    }

    /**
     * Every file name the override directory is searched for.
     *
     * Read from the same list the lookup uses, so the instructions cannot
     * drift away from what the code actually accepts.
     *
     * @return list<string>
     */
    public static function fileNames(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::FILES))));
    }

    public function __construct(private readonly string $root)
    {
    }

    /**
     * The URL for one of the four names above, or null if nothing is there.
     *
     * Carries the file's timestamp, so a replaced logo appears immediately
     * rather than after everyone's cache expires.
     */
    public function url(string $name): ?string
    {
        return $this->resolved[$name] ??= $this->resolve($name);
    }

    /**
     * The header logo's dimensions, scaled to the height the layout uses.
     *
     * Given to the browser so the header does not jump while the image loads.
     * An SVG has no intrinsic pixel size worth reading, and an override may be
     * any shape at all, so the width is measured rather than assumed.
     *
     * @return array{int, int}
     */
    public function logoSize(int $height = 36): array
    {
        $path = $this->path('logo');
        if ($path !== null && !str_ends_with($path, '.svg')) {
            $size = @getimagesize($path);
            if (is_array($size) && $size[1] > 0) {
                return [(int) round($size[0] * $height / $size[1]), $height];
            }
        }

        // The shipped mark is square; so is an override we cannot measure.
        return [$height, $height];
    }

    /**
     * Is this installation still wearing the logo it came with?
     *
     * True while nothing at all sits in the brand directory. One file there is
     * taken as a deliberate choice - somebody who wants only a favicon of
     * their own should not be nagged about the rest.
     */
    public function usesDefaults(): bool
    {
        foreach (array_keys(self::FILES) as $name) {
            if ($this->override($name) !== null) {
                return false;
            }
        }

        return true;
    }

    private function resolve(string $name): ?string
    {
        foreach ([self::OVERRIDE, self::SHIPPED] as $directory) {
            $file = $this->firstThatExists($directory, $name);
            if ($file !== null) {
                // The timestamp, so a replaced logo appears at once rather
                // than when everyone's cache happens to expire.
                return '/' . $directory . '/' . $file . '?v=' . (int) filemtime($this->file($directory, $file));
            }
        }

        return null;
    }

    private function path(string $name): ?string
    {
        foreach ([self::OVERRIDE, self::SHIPPED] as $directory) {
            $file = $this->firstThatExists($directory, $name);
            if ($file !== null) {
                return $this->file($directory, $file);
            }
        }

        return null;
    }

    private function override(string $name): ?string
    {
        return $this->firstThatExists(self::OVERRIDE, $name);
    }

    /** @return string|null the file name, not the path */
    private function firstThatExists(string $directory, string $name): ?string
    {
        foreach (self::FILES[$name] ?? [] as $file) {
            if (is_file($this->file($directory, $file))) {
                return $file;
            }
        }

        return null;
    }

    /** Paths are relative to public/, which is the document root. */
    private function file(string $directory, string $name): string
    {
        return $this->root . '/public/' . $directory . '/' . $name;
    }
}
