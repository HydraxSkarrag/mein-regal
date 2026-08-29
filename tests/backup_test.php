<?php
declare(strict_types=1);

use App\Import\CsvReader;
use App\Import\Importer;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';
// Included for splitStatements() and dumpDatabase(); its CLI block is guarded
// so this does not start a backup run.
require_once dirname(__DIR__) . '/bin/backup.php';

Assert::group('Backup: the dump restores');

$makeDb = static function (): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');

    return $pdo;
};

$source = $makeDb();
(new UserRepository($source))->create('m@example.org', 'ein-langes-passwort', 'M');
$books = new BookRepository($source);
(new Importer($source, $books, new AuthorRepository($source), new TagRepository($source)))
    ->run(new CsvReader(__DIR__ . '/fixtures/bookstats_sample.csv'), 1, false);

// The values that break naive dumps: an apostrophe, a semicolon, and a
// newline - all three appear in real notes and page texts.
$books->update(1, 1, ['notes' => "Zeile eins\nZeile zwei; mit Semikolon\nund 'Anführungszeichen'"]);
$source->prepare('INSERT INTO pages (owner_id, slug, title, body, updated_at) VALUES (?, ?, ?, ?, ?)')
    ->execute([1, 'ueber', 'Über', "Absatz eins.\n\nAbsatz zwei mit 'Zitat' und ; Semikolon.", '2026-01-01 00:00:00']);

$dumpPath = tempnam(sys_get_temp_dir(), 'regaldump') . '.sql';
$written = dumpDatabase($source, $dumpPath);
Assert::true('rows were written', $written > 0);

$sql = (string) file_get_contents($dumpPath);
$statements = splitStatements($sql);

$target = $makeDb();
$applied = 0;
$failures = [];
foreach ($statements as $statement) {
    if (!str_starts_with($statement, 'INSERT INTO')) {
        continue;
    }
    try {
        $target->exec($statement);
        $applied++;
    } catch (PDOException $e) {
        $failures[] = substr($e->getMessage(), 0, 60);
    }
}

Assert::same('every statement applies cleanly', $failures, []);
Assert::same('and every row arrives', $applied, $written);

// Counting the rows is the whole point: a dump that loses one row per table
// errors nowhere and is simply, quietly incomplete.
foreach (['books', 'authors', 'book_authors', 'tags', 'book_tags', 'pages', 'users'] as $table) {
    Assert::same(
        $table . ' matches row for row',
        (int) $target->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(),
        (int) $source->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn()
    );
}

Assert::same(
    'a note with newlines, a semicolon and quotes survives',
    $target->query('SELECT notes FROM books WHERE id = 1')->fetchColumn(),
    "Zeile eins\nZeile zwei; mit Semikolon\nund 'Anführungszeichen'"
);
Assert::same(
    'so does a page text',
    $target->query("SELECT body FROM pages WHERE slug = 'ueber'")->fetchColumn(),
    "Absatz eins.\n\nAbsatz zwei mit 'Zitat' und ; Semikolon."
);
Assert::same(
    'and an umlaut',
    $target->query("SELECT title FROM books WHERE isbn13 = '9783788640026'")->fetchColumn(),
    'Der kleine Major Tom. Band 2: Rückkehr zur Erde'
);

Assert::group('Backup: credentials stay out of the copy');

// A backup gets carried around and left on other machines. Live sign-in
// tokens have no business travelling with it.
foreach (['auth_tokens', 'login_attempts', 'lookup_hits'] as $table) {
    Assert::same(
        $table . ' is not dumped',
        str_contains($sql, 'INSERT INTO ' . $table . ' '),
        false
    );
}

Assert::group('Backup: the statement splitter');

Assert::same('a plain pair', splitStatements("SELECT 1; SELECT 2;"), ['SELECT 1', 'SELECT 2']);
Assert::same(
    'a semicolon inside a string is not a boundary',
    splitStatements("INSERT INTO t VALUES ('a; b');"),
    ["INSERT INTO t VALUES ('a; b')"]
);
Assert::same(
    'a doubled quote inside a string is not the end of it',
    splitStatements("INSERT INTO t VALUES ('it''s; fine');"),
    ["INSERT INTO t VALUES ('it''s; fine')"]
);
Assert::same(
    'a comment before a statement is dropped, the statement is not',
    splitStatements("-- books\nINSERT INTO t VALUES (1);"),
    ['INSERT INTO t VALUES (1)']
);
Assert::same('a comment on its own yields nothing', splitStatements("-- nur ein Kommentar\n"), []);

unlink($dumpPath);
