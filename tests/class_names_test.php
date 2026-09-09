<?php
/**
 * Every class a file names has to be a class the file can reach.
 *
 * php -l does not check this and neither did anything else here: a missing
 * `use` is valid PHP until the line runs. The series work added one call to
 * DnbLookup inside ScanController and no import for it, and the line only
 * runs after a lookup has succeeded - so the syntax check passed, the whole
 * suite passed, both shelves deployed, and every single book added by ISBN
 * died with a 500 for a day. The scanner has no branch for a 500, so what it
 * showed was its own "nichts gefunden", and the catalogue got the blame.
 *
 * The check is what the interpreter does, done early: resolve every class
 * name a file uses against that file's imports and namespace, and ask
 * whether it exists.
 */
declare(strict_types=1);

Assert::group('No class is used without being reachable');

/** @return list<string> every PHP file under app/ and bin/ */
$phpFiles = static function (string $root): array {
    $found = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($walk as $file) {
        if (!$file->isDir() && $file->getExtension() === 'php') {
            $found[] = $file->getPathname();
        }
    }
    sort($found);

    return $found;
};

/* PHP's own tokeniser rather than a regular expression, so that a class name
 * inside a comment, a string or a doc block is not mistaken for a use of it -
 * which is most of what a regular expression would find here. */
$namesUsedIn = static function (string $code): array {
    $tokens = token_get_all($code);
    $namespace = '';
    $aliases = [];
    $used = [];

    $nameTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }

        // namespace X;
        if ($token[0] === T_NAMESPACE) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], $nameTokens, true)) {
                    $namespace = trim($tokens[$j][1], '\\');
                    break;
                }
                if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                    break;
                }
            }
            continue;
        }

        // use A\B\C; and use A\B\C as D;
        if ($token[0] === T_USE) {
            $path = '';
            $alias = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{' || $tokens[$j] === '(') {
                    break;
                }
                if (!is_array($tokens[$j])) {
                    continue;
                }
                if ($tokens[$j][0] === T_AS) {
                    $alias = '';
                    continue;
                }
                if (in_array($tokens[$j][0], $nameTokens, true)) {
                    if ($alias === '') {
                        $alias = $tokens[$j][1];
                    } elseif ($path === '') {
                        $path = trim($tokens[$j][1], '\\');
                    }
                }
            }
            if ($path !== '') {
                $short = $alias ?? substr($path, (int) strrpos('\\' . $path, '\\'));
                $aliases[strtolower(trim($short, '\\'))] = $path;
            }
            continue;
        }

        // new X(...)
        if ($token[0] === T_NEW) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (!is_array($tokens[$j])) {
                    break;
                }
                if (in_array($tokens[$j][0], $nameTokens, true)) {
                    $used[] = $tokens[$j][1];
                    break;
                }
                if (!in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    break;
                }
            }
            continue;
        }

        // X::something
        if (in_array($token[0], $nameTokens, true)) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) {
                    $used[] = $token[1];
                }
                break;
            }
        }
    }

    return ['namespace' => $namespace, 'aliases' => $aliases, 'used' => array_unique($used)];
};

// Keywords that look like a class name to the tokeniser and are not one.
$keywords = ['self', 'static', 'parent', 'class', 'true', 'false', 'null'];

$unreachable = [];
$checked = 0;

foreach (array_merge($phpFiles(PROJECT_ROOT . '/app'), $phpFiles(PROJECT_ROOT . '/bin')) as $file) {
    $parsed = $namesUsedIn((string) file_get_contents($file));

    foreach ($parsed['used'] as $name) {
        if (in_array(strtolower($name), $keywords, true)) {
            continue;
        }
        $checked++;

        if (str_starts_with($name, '\\')) {
            $candidates = [trim($name, '\\')];
        } elseif (isset($parsed['aliases'][strtolower($name)])) {
            $candidates = [$parsed['aliases'][strtolower($name)]];
        } else {
            $first = strtok($name, '\\');
            $candidates = isset($parsed['aliases'][strtolower((string) $first)])
                ? [$parsed['aliases'][strtolower((string) $first)] . substr($name, strlen((string) $first))]
                : [
                    ($parsed['namespace'] !== '' ? $parsed['namespace'] . '\\' : '') . $name,
                    $name,
                ];
        }

        $exists = false;
        foreach ($candidates as $candidate) {
            if (class_exists($candidate) || interface_exists($candidate)
                || trait_exists($candidate) || enum_exists($candidate)) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $unreachable[] = basename($file) . ' uses ' . $name;
        }
    }
}

sort($unreachable);
Assert::true('there is something to check', $checked > 200);
Assert::same('and every name resolves', $unreachable, []);
