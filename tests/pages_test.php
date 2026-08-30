<?php
declare(strict_types=1);

use App\Repository\PageRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('PageRepository: one text per language');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$pages = new PageRepository($pdo);

Assert::same('nothing written yet', $pages->find(1, PageRepository::ABOUT, 'de'), null);

$pages->save(1, PageRepository::ABOUT, 'de', 'Über mein Regal', 'Mein Bücherregal.');
$pages->save(1, PageRepository::ABOUT, 'en', 'About my shelf', 'My bookshelf.');

Assert::same('the German text', $pages->find(1, PageRepository::ABOUT, 'de')['title'], 'Über mein Regal');
Assert::same('the English one is its own', $pages->find(1, PageRepository::ABOUT, 'en')['title'], 'About my shelf');
Assert::same('and its own body', $pages->find(1, PageRepository::ABOUT, 'en')['body'], 'My bookshelf.');

// No falling back between languages: a German paragraph under an English
// heading reads like a fault, and the empty state says plainly that nothing
// has been written.
Assert::same('an unwritten language stays empty', $pages->find(1, PageRepository::ABOUT, 'fr'), null);

$pages->save(1, PageRepository::ABOUT, 'de', 'Neuer Titel', 'Neuer Text.');
Assert::same('saving again replaces', $pages->find(1, PageRepository::ABOUT, 'de')['title'], 'Neuer Titel');
Assert::same('and leaves the other language alone', $pages->find(1, PageRepository::ABOUT, 'en')['title'], 'About my shelf');
Assert::same('without creating a second row', (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn(), 2);

Assert::same('both languages are reported as written', $pages->localesFor(1, PageRepository::ABOUT), ['de', 'en']);

// An empty body is not a written page - the editor's tick would otherwise
// promise a text that is not there.
$pages->save(1, PageRepository::ABOUT, 'en', 'About', null);
Assert::same('an emptied language drops off the list', $pages->localesFor(1, PageRepository::ABOUT), ['de']);

Assert::group('Formatter::stars');

Assert::same('nothing rated', App\Core\Formatter::stars(null), null);
Assert::same('zero is not a rating', App\Core\Formatter::stars(0), null);

$four = App\Core\Formatter::stars(4);
Assert::same('four full', $four['full'], 4);
Assert::same('no half', $four['half'], false);
Assert::same('one empty', $four['empty'], 1);
Assert::same('written as a whole number', $four['text'], '4');

$half = App\Core\Formatter::stars(4.5);
Assert::same('four and a half: four full', $half['full'], 4);
Assert::same('plus the half', $half['half'], true);
Assert::same('and none left over', $half['empty'], 0);
Assert::same('written with a comma', $half['text'], '4,5');

Assert::same('a stray value rounds to the nearest half', App\Core\Formatter::stars(3.3)['text'], '3,5');
Assert::same('and cannot exceed five', App\Core\Formatter::stars(9)['full'], 5);
Assert::same('a string from the database works too', App\Core\Formatter::stars('2.5')['text'], '2,5');
