<?php
/**
 * Database access lives in the repositories.
 *
 * ARCHITECTURE.md has said so since the first day and counted, on 14
 * September 2026, 22 queries in seven files that did not keep to it. Moving
 * them turned up two faults the rule exists to prevent: the list of series
 * counted a Sammelband differently from its own page, and the dashboard's gap
 * counts were separate queries kept in step with the filters they link to by
 * a comment. This keeps the count at nought.
 *
 * Three files talk to the database on purpose and are named: the account's
 * own tables, the connection, and the first user an anonymous visit shows.
 */
declare(strict_types=1);

Assert::group('No SQL outside app/Repository');

$allowedToQuery = [
    'app/Core/Auth.php',
    'app/Core/Database.php',
    'app/Http/Application.php',
];

$offenders = [];
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PROJECT_ROOT . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($walk as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen(PROJECT_ROOT) + 1);
    if (str_starts_with($relative, 'app/Repository/') || in_array($relative, $allowedToQuery, true)) {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match_all('/(?:pdo|->pdo)->(?:prepare|query|exec)\(/', $source, $found) > 0) {
        $offenders[] = $relative . ' (' . count($found[0]) . ')';
    }
}
Assert::same('every query is in a repository or in one of the three named files', $offenders, []);

/* A named exception that stopped querying is an exception nobody needs. */
foreach ($allowedToQuery as $named) {
    Assert::true(
        $named . ' still earns its place on the list',
        preg_match('/(?:pdo|->pdo)->(?:prepare|query|exec)\(/', (string) file_get_contents(PROJECT_ROOT . '/' . $named)) === 1
    );
}
