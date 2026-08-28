<?php
/**
 * Minimal test harness.
 *
 * There is no PHPUnit here for the same reason there is no framework: nothing
 * can be installed on the server, and these tests must run anywhere PHP does.
 * Run them all with: php tests/run.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

final class Assert
{
    public static int $passed = 0;
    /** @var list<string> */
    public static array $failures = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n" . $name . "\n";
    }

    public static function same(string $label, mixed $got, mixed $want): void
    {
        if ($got === $want) {
            self::$passed++;
            echo "  ok    $label\n";

            return;
        }
        $message = sprintf(
            '%s / %s: got %s, want %s',
            self::$group,
            $label,
            self::render($got),
            self::render($want)
        );
        self::$failures[] = $message;
        echo '  FAIL  ' . $label . ': got ' . self::render($got) . ', want ' . self::render($want) . "\n";
    }

    public static function true(string $label, bool $condition): void
    {
        self::same($label, $condition, true);
    }

    private static function render(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return var_export($value, true);
    }

    public static function summary(): int
    {
        $failed = count(self::$failures);
        echo "\n" . str_repeat('-', 60) . "\n";
        printf("%d passed, %d failed\n", self::$passed, $failed);
        foreach (self::$failures as $failure) {
            echo '  ! ' . $failure . "\n";
        }

        return $failed === 0 ? 0 : 1;
    }
}
