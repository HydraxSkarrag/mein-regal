<?php
declare(strict_types=1);

namespace App\Core;

/**
 * What the shelf looks like.
 *
 * The stylesheet defines every colour, shape and typeface as a token on
 * :root and then uses nothing else. A theme is therefore one small file that
 * redefines some of those tokens - not a second stylesheet, and not a fork.
 *
 * Three layers, loaded in this order:
 *
 *   public/css/style.css            the neutral default, always
 *   public/css/themes/<name>.css    a shipped theme, named in config.php
 *   public/assets/brand/theme.css   this installation's own, if it exists
 *
 * The shipped themes are versioned and deploy with everything else, which is
 * what makes them the right home for a look somebody wants to keep. The last
 * layer is the private one: it sits beside the logo in the brand directory,
 * excluded from Git and from the deployment, for an installation whose
 * appearance is nobody else's business. It is loaded last, so it wins.
 *
 * Why the default is neutral: the red and the serif this application was
 * first drawn in are Bücherhausen's, and a repository that carefully keeps
 * out somebody's logo should not ship their palette either. That look is now
 * a theme like any other - see public/css/themes/.
 */
final class Theme
{
    private const SHIPPED = 'css/themes';
    private const OVERRIDE = Brand::OVERRIDE . '/theme.css';

    public function __construct(
        private readonly string $root,
        private readonly string $name
    ) {
    }

    /**
     * Every stylesheet after style.css, in the order they must be loaded.
     *
     * @return list<string>
     */
    public function urls(): array
    {
        $urls = [];
        foreach ($this->files() as $path => $url) {
            $urls[] = $url . '?v=' . (int) filemtime($path);
        }

        return $urls;
    }

    /** Whether config.php names a theme that is not there. */
    public function missing(): bool
    {
        return $this->slug() !== '' && !is_file($this->shippedPath());
    }

    /** The name a theme was asked for by, cleaned. */
    public function slug(): string
    {
        // A file name from configuration, so it is reduced to what a file
        // name may be rather than trusted. Nobody malicious is editing
        // config.php - if they are, a path here is the least of it - but a
        // typo with a slash should read as "no such theme", not as a hunt
        // through the file system.
        return strtolower(preg_replace('/[^a-z0-9_-]/i', '', $this->name) ?? '');
    }

    /**
     * The colour the browser paints its own chrome with, read out of the CSS.
     *
     * A <meta name="theme-color"> and a manifest field are not CSS and cannot
     * read a custom property, which is the usual reason this ends up written
     * a second time in a configuration file. It does not have to be: the
     * value is in the theme already, and PHP can go and look.
     *
     * Written twice, it drifts. These two lines were four hours old when the
     * development configuration said #faf9fe and the real one #f9fefd, and
     * neither of them was any theme's background.
     *
     * The page background is the answer by default - a bright accent in the
     * address bar is loud and rarely what anyone wanted - and a theme that
     * disagrees says so with --meta-theme-colour.
     *
     * Both schemes are returned when the theme defines both, because a theme
     * that follows the system has to be followed here too: one fixed colour
     * would sit next to the wrong page half the time.
     *
     * @return array{light: string, dark: ?string}
     */
    public function metaColours(): array
    {
        $light = '#ffffff';
        $dark = null;

        // Every layer in load order, so a later one overrules an earlier one
        // exactly as it does in the browser.
        $sheets = [$this->root . '/public/css/style.css'];
        foreach (array_keys($this->files()) as $path) {
            $sheets[] = $path;
        }

        foreach ($sheets as $sheet) {
            $css = @file_get_contents($sheet);
            if ($css === false) {
                continue;
            }
            [$plain, $night] = self::split($css);
            $light = self::colourIn($plain) ?? $light;
            $dark = self::colourIn($night) ?? $dark;
        }

        return ['light' => $light, 'dark' => $dark];
    }

    /**
     * A stylesheet in two halves: everything outside a dark-scheme query,
     * and everything inside one.
     *
     * Brace counting rather than a regular expression, because the query
     * contains rules with braces of their own and the first closing brace is
     * never the right one.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $css): array
    {
        $plain = '';
        $night = '';
        $offset = 0;

        while (($start = strpos($css, '@media', $offset)) !== false) {
            $open = strpos($css, '{', $start);
            if ($open === false) {
                break;
            }
            $query = substr($css, $start, $open - $start);
            $depth = 1;
            $i = $open + 1;
            $length = strlen($css);
            while ($i < $length && $depth > 0) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    $depth--;
                }
                $i++;
            }
            $plain .= substr($css, $offset, $start - $offset);
            if (str_contains(str_replace(' ', '', $query), 'prefers-color-scheme:dark')) {
                $night .= substr($css, $open, $i - $open);
            }
            $offset = $i;
        }

        return [$plain . substr($css, $offset), $night];
    }

    /** The last declaration wins, the way the cascade would have it. */
    private static function colourIn(string $css): ?string
    {
        foreach (['--meta-theme-colour', '--bg'] as $token) {
            // Terminated by a semicolon, by the end of its block, or by the
            // end of the file. The last declaration in a block often has no
            // semicolon, and that is exactly where a theme puts its
            // background.
            $pattern = '/' . $token . '\s*:\s*(#[0-9a-f]{3,8})\s*(?=[;}]|$)/i';
            if (preg_match_all($pattern, $css, $m) === false) {
                continue;
            }
            if (($m[1] ?? []) !== []) {
                return strtolower(end($m[1]));
            }
        }

        return null;
    }

    /** @return array<string, string> path => URL */
    private function files(): array
    {
        $files = [];
        $shipped = $this->shippedPath();
        if ($this->slug() !== '' && is_file($shipped)) {
            $files[$shipped] = '/' . self::SHIPPED . '/' . $this->slug() . '.css';
        }
        $override = $this->root . '/public/' . self::OVERRIDE;
        if (is_file($override)) {
            $files[$override] = '/' . self::OVERRIDE;
        }

        return $files;
    }

    private function shippedPath(): string
    {
        return $this->root . '/public/' . self::SHIPPED . '/' . $this->slug() . '.css';
    }
}
