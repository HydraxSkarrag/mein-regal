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
