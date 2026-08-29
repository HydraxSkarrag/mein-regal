<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Plain PHP templates.
 *
 * Templates live outside the document root and receive only what they are
 * handed. There is no logic in them beyond loops and conditionals, which is
 * what keeps a later move to a real template engine - or a framework - a
 * mechanical job rather than a rewrite.
 */
final class View
{
    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(private readonly string $directory)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->directory . '/' . str_replace('.', '/', $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('No such template: ' . $template);
        }

        $view = $this;
        // Page data wins over the shared defaults. Extracting the defaults
        // first with EXTR_SKIP did the opposite: a template asking for
        // 'scripts' or 'noIndex' had its value silently discarded in favour
        // of the default it was trying to replace.
        extract(array_merge($this->shared, $data), EXTR_SKIP);

        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Render a template inside the layout.
     *
     * @param array<string,mixed> $data
     */
    public function page(string $template, array $data = [], string $layout = 'layout.base'): string
    {
        $content = $this->render($template, $data);

        return $this->render($layout, $data + ['content' => $content]);
    }
}
