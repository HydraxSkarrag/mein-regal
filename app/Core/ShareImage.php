<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use GdImage;

/**
 * The picture a link to this shelf carries when it is shared.
 *
 * Facebook, WhatsApp, Signal, Mastodon and the rest show an image beside a
 * shared link if the page names one. The shelf named none outside the book
 * pages, so a link to it arrived as a grey box with a line of text.
 *
 * One picture per installation, not one per page: the name, the line under
 * it, how many books there are, and the newest covers standing on a shelf -
 * in the installation's own colours and with its own logo, because those come
 * from the theme and the brand folder rather than from here. A shelf that
 * wants a designed one puts og.jpg or og.png beside its logo, and that is
 * used instead.
 *
 * Drawn once, kept as a file under public/covers/share and served from there
 * like any cover, so a crawler asking for it costs no PHP at all. The file
 * name carries a fingerprint of everything drawn on it: a new book, a new
 * logo or another theme make a new name, which is also what makes the
 * platforms fetch it again - they hold on to an image by its address.
 *
 * Nothing about this reaches a visitor: the image is fetched by the services
 * that unfurl a link, from this server, and shows what the page shows anyway.
 *
 * GD with FreeType and the fonts in app/fonts are needed to draw it. Where
 * either is missing there is no picture, which is how it was before.
 */
final class ShareImage
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;

    /** Change it and every installation draws its picture again. */
    private const LAYOUT = 2;

    private const DIRECTORY = 'covers/share';

    /* The right part of the picture holds the shelf, the left the words; the
       two must not meet, or a long name runs under the first cover. */
    private const TEXT_RIGHT = 600;
    private const SHELF_LEFT = 640;
    private const COVERS = 3;

    /** @var array{url: string, width: int, height: int}|false|null */
    private array|false|null $resolved = null;

    private string $countLine = '';

    /** @var list<string> */
    private array $covers = [];

    /**
     * @param string  $siteUrl  for the absolute address the platforms want
     * @param Closure $facts    (): array{count: string, covers: list<string>} -
     *                          asked only when a page actually wants the
     *                          picture, so a request that renders no page
     *                          runs no query for it
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $siteUrl,
        private readonly Brand $brand,
        private readonly Theme $theme,
        private readonly string $siteName,
        private readonly string $byline,
        private readonly Closure $facts,
    ) {
    }

    /**
     * What a page puts in its og:image tags: its own picture where it has
     * one - a book its cover - and this one everywhere else.
     *
     * @param  array{url: string, width?: ?int, height?: ?int, alt?: ?string}|null $page
     * @return array{url: string, width: ?int, height: ?int, alt: string}|null
     */
    public function card(?array $page): ?array
    {
        if ($page !== null) {
            return [
                'url'    => $page['url'],
                'width'  => $page['width'] ?? null,
                'height' => $page['height'] ?? null,
                'alt'    => (string) ($page['alt'] ?? $this->alt()),
            ];
        }
        $image = $this->image();

        return $image === null ? null : [
            'url'    => $this->siteUrl . $image['url'],
            'width'  => $image['width'] > 0 ? $image['width'] : null,
            'height' => $image['height'] > 0 ? $image['height'] : null,
            'alt'    => $this->alt(),
        ];
    }

    /** @return array{url: string, width: int, height: int}|null */
    public function image(): ?array
    {
        if ($this->resolved === null) {
            $facts = ($this->facts)();
            $this->countLine = (string) ($facts['count'] ?? '');
            $this->covers = array_values(array_map('strval', $facts['covers'] ?? []));
            $this->resolved = $this->resolve() ?? false;
        }

        return $this->resolved === false ? null : $this->resolved;
    }

    public function alt(): string
    {
        return $this->byline === '' ? $this->siteName : $this->siteName . ' – ' . $this->byline;
    }

    /** @return array{url: string, width: int, height: int}|null */
    private function resolve(): ?array
    {
        $own = $this->brand->url('share');
        if ($own !== null) {
            $size = @getimagesize($this->projectRoot . '/public' . (string) parse_url($own, PHP_URL_PATH));

            return ['url' => $own, 'width' => (int) ($size[0] ?? 0), 'height' => (int) ($size[1] ?? 0)];
        }

        if (!self::canDraw($this->fontDirectory())) {
            return null;
        }

        $name = 'regal-' . substr(sha1((string) json_encode($this->inputs())), 0, 16) . '.jpg';
        $directory = $this->projectRoot . '/public/' . self::DIRECTORY;
        $file = $directory . '/' . $name;
        $url = '/' . self::DIRECTORY . '/' . $name;

        if (!is_file($file)) {
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                return null;
            }
            $canvas = $this->draw();
            if ($canvas === null) {
                return null;
            }
            /* Written beside the final name and moved into place, so two
               requests drawing at once never serve half a file. */
            $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (!imagejpeg($canvas, $temporary, 86) || !@rename($temporary, $file)) {
                @unlink($temporary);

                return null;
            }
            foreach (glob($directory . '/regal-*.jpg') ?: [] as $old) {
                if ($old !== $file) {
                    @unlink($old);
                }
            }
        }

        return ['url' => $url, 'width' => self::WIDTH, 'height' => self::HEIGHT];
    }

    public static function canDraw(string $fontDirectory): bool
    {
        return function_exists('imagettftext')
            && function_exists('imagejpeg')
            && is_file($fontDirectory . '/IBMPlexSerif-SemiBold.ttf')
            && is_file($fontDirectory . '/IBMPlexSerif-Regular.ttf');
    }

    private function fontDirectory(): string
    {
        return $this->projectRoot . '/app/fonts';
    }

    /** Everything the picture is drawn from - its fingerprint. */
    private function inputs(): array
    {
        $logo = $this->logoFile();

        return [
            self::LAYOUT,
            $this->siteName,
            $this->byline,
            $this->countLine,
            $this->colours(),
            $logo === null ? null : [$logo, @filemtime($logo)],
            array_slice($this->covers, 0, self::COVERS),
        ];
    }

    /** @return array<string, string> */
    private function colours(): array
    {
        return $this->theme->palette([
            '--bg'     => '#f9fafb',
            '--text'   => '#27272a',
            '--muted'  => '#71717a',
            '--accent' => '#2f5d8f',
            '--border' => '#e4e4e7',
        ]);
    }

    private function logoFile(): ?string
    {
        $url = $this->brand->url('logo');
        if ($url === null) {
            return null;
        }
        $path = $this->projectRoot . '/public' . (string) parse_url($url, PHP_URL_PATH);

        // GD reads no SVG; a shelf with only a vector logo goes without.
        return is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? $path : null;
    }

    private function draw(): ?GdImage
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($canvas === false) {
            return null;
        }
        $colour = [];
        foreach ($this->colours() as $token => $hex) {
            [$r, $g, $b] = self::rgb($hex);
            $colour[$token] = (int) imagecolorallocate($canvas, $r, $g, $b);
        }
        imagefilledrectangle($canvas, 0, 0, self::WIDTH, self::HEIGHT, $colour['--bg']);
        imagealphablending($canvas, true);

        $semibold = $this->fontDirectory() . '/IBMPlexSerif-SemiBold.ttf';
        $regular = $this->fontDirectory() . '/IBMPlexSerif-Regular.ttf';
        $left = 80;
        $textWidth = $this->covers === [] ? self::WIDTH - 2 * $left : self::TEXT_RIGHT - $left;

        /* The logo, then the name under it, then the line under that - the
           order the header of the page has them in. */
        $y = 96;
        $logo = $this->logoFile();
        if ($logo !== null && ($image = @imagecreatefrompng($logo)) !== false) {
            $height = 96;
            $width = (int) round(imagesx($image) * $height / max(1, imagesy($image)));
            imagecopyresampled($canvas, $image, $left, $y, 0, 0, $width, $height, imagesx($image), imagesy($image));
            $y += $height + 36;
        } else {
            $y += 40;
        }

        foreach (self::wrap($this->siteName, $semibold, 62, $textWidth, 2) as $line) {
            $y += 70;
            imagettftext($canvas, 62, 0, $left, $y, $colour['--text'], $semibold, $line);
        }
        imagefilledrectangle($canvas, $left, $y + 26, $left + 72, $y + 31, $colour['--accent']);
        $y += 34;

        foreach (self::wrap($this->byline, $regular, 30, $textWidth, 2) as $line) {
            $y += 50;
            imagettftext($canvas, 30, 0, $left, $y, $colour['--muted'], $regular, $line);
        }

        imagettftext($canvas, 30, 0, $left, self::HEIGHT - 72, $colour['--accent'], $semibold, $this->countLine);

        $this->drawShelf($canvas, $colour);

        return $canvas;
    }

    /**
     * The newest covers, standing on a board on the right.
     *
     * @param array<string, int> $colour
     */
    private function drawShelf(GdImage $canvas, array $colour): void
    {
        if ($this->covers === [] || !function_exists('imagecreatefromwebp')) {
            return;
        }
        $base = self::HEIGHT - 96;
        $right = self::WIDTH - 72;
        $gap = 18;
        // As wide as three can stand side by side in the shelf's part.
        $widest = (int) floor((($right - self::SHELF_LEFT) - (self::COVERS - 1) * $gap) / self::COVERS);
        $height = (int) round($widest * 3 / 2);
        $placed = [];
        $total = 0;

        foreach (array_slice($this->covers, 0, self::COVERS) as $path) {
            $file = $this->projectRoot . '/public/covers/' . $path;
            $image = is_file($file) ? @imagecreatefromwebp($file) : false;
            if ($image === false) {
                continue;
            }
            // Standing as it is, but no wider than a book stands on a shelf.
            $width = (int) min($widest, round(imagesx($image) * $height / max(1, imagesy($image))));
            $sourceWidth = (int) min(imagesx($image), round($width * imagesy($image) / $height));
            $placed[] = [$image, $width, (int) ((imagesx($image) - $sourceWidth) / 2), $sourceWidth];
            $total += $width;
        }
        if ($placed === []) {
            return;
        }

        /* Left to right in the order given, so the first book config.php
           names is the first one read; the row ends at the right margin. */
        $x = $right - $total - (count($placed) - 1) * $gap;
        imagefilledrectangle($canvas, $x - 28, $base, $right + 20, $base + 8, $colour['--border']);
        foreach ($placed as [$image, $width, $sourceX, $sourceWidth]) {
            imagecopyresampled($canvas, $image, $x, $base - $height, $sourceX, 0, $width, $height, $sourceWidth, imagesy($image));
            $x += $width + $gap;
        }
    }

    /**
     * Words onto lines no wider than given, at most so many; the last one
     * ends in an ellipsis if there was more.
     *
     * @return list<string>
     */
    private static function wrap(string $text, string $font, float $size, int $width, int $lines): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $fits = static function (string $candidate) use ($font, $size, $width): bool {
            $box = imagettfbbox($size, 0, $font, $candidate);

            return $box !== false && ($box[2] - $box[0]) <= $width;
        };

        $out = [];
        $current = '';
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && !$fits($candidate)) {
                $out[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        $out[] = $current;

        if (count($out) > $lines) {
            $out = array_slice($out, 0, $lines);
            $last = $out[$lines - 1];
            while ($last !== '' && !$fits($last . ' …')) {
                $last = (string) preg_replace('/\s*\S+$/u', '', $last);
            }
            $out[$lines - 1] = $last . ' …';
        }

        return $out;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3 || strlen($hex) === 4) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
