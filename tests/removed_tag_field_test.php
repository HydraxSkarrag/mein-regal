<?php
/**
 * Typing a removed name into a book's tags.
 *
 * It looked like a new label - "neu als Schlagwort" - and saving took it off
 * again without a word, because a removed tag cannot be put on a book. The
 * refusal is right; the silence was not. The field now says so as the name is
 * typed, and the save says so for anybody without JavaScript.
 */
declare(strict_types=1);

use App\Repository\BookRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

$fieldPdo = new PDO('sqlite::memory:');
$fieldPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$fieldPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($fieldPdo, dirname(__DIR__) . '/schema.sql');
(new UserRepository($fieldPdo))->create('d@example.org', 'ein-langes-passwort', 'D');
$fieldBooks = new BookRepository($fieldPdo);
$fieldTags = new TagRepository($fieldPdo);
$fieldBook = $fieldBooks->insert(1, ['title' => 'Zogg', 'isbn13' => '9783407795595']);

$fieldGone = $fieldTags->findOrCreate(1, 'Kinder- und Jugendliteratur');
$fieldTags->drop(1, $fieldGone);
$fieldTarget = $fieldTags->findOrCreate(1, 'Comic & Cartoon');
$fieldOld = $fieldTags->findOrCreate(1, 'Comics');
$fieldOlder = $fieldTags->findOrCreate(1, 'Comic');
$fieldTags->merge(1, $fieldOld, $fieldTarget);
$fieldTags->merge(1, $fieldOlder, $fieldOld);       // a trail: Comic -> Comics -> Comic & Cartoon
$fieldDeadEnd = $fieldTags->findOrCreate(1, 'Fantasie');
$fieldRemovedTarget = $fieldTags->findOrCreate(1, 'Fantasy fiction');
$fieldTags->merge(1, $fieldDeadEnd, $fieldRemovedTarget);
$fieldTags->drop(1, $fieldRemovedTarget);           // merged into something that is gone itself

Assert::group('What the field is told about removed names');

$fieldRemoved = array_column($fieldTags->removedNames(1), 'into', 'name');
Assert::same('a removed name leads nowhere', $fieldRemoved['Kinder- und Jugendliteratur'], null);
Assert::same('a merged one leads to what it became', $fieldRemoved['Comics'], 'Comic & Cartoon');
Assert::same('along the whole trail', $fieldRemoved['Comic'], 'Comic & Cartoon');
Assert::same('and nowhere, when what it became is gone too', $fieldRemoved['Fantasie'], null);
Assert::true('a tag in use is not in the list', !array_key_exists('Comic & Cartoon', $fieldRemoved));
Assert::same('nor is anybody else\'s', $fieldTags->removedNames(2), []);

Assert::group('What the save says it did not keep');

$fieldTyped = ['Bilderbuch', 'kinder- und jugendliteratur', 'Comics', 'Fantasie'];
Assert::same(
    'the removed names, as they were typed, and not the merged one that lands somewhere',
    $fieldTags->refusedAmong(1, $fieldTyped),
    ['kinder- und jugendliteratur', 'Fantasie']
);

$fieldBooks->replaceTags(1, $fieldBook, $fieldTyped, $fieldTags);
Assert::same(
    'which is exactly what the save leaves off',
    array_column($fieldTags->forBook(1, $fieldBook), 'name'),
    ['Bilderbuch', 'Comic & Cartoon']
);

$fieldController = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/BookController.php');
Assert::true('the save asks before it writes', str_contains($fieldController, '$refusedTags = $this->app->tags->refusedAmong('));
Assert::true('and says it afterwards', str_contains($fieldController, "t('edit.tags.refused'"));

Assert::group('What the field does with them');

$fieldTemplate = (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/edit.php');
$fieldScript = (string) file_get_contents(PROJECT_ROOT . '/public/js/tags.js');

Assert::true('the page hands the list over', str_contains($fieldController, "'removedTags'  => array_map(") && str_contains($fieldTemplate, 'id="removed-tags"'));
Assert::true('with both sentences', str_contains($fieldTemplate, "'removed'     => t('edit.tags.removed')") && str_contains($fieldTemplate, "'merged'      => t('edit.tags.merged')"));
Assert::true('the script reads it', str_contains($fieldScript, "document.getElementById('removed-tags')"));
Assert::true('offers no "new label" for a removed name', str_contains($fieldScript, "!byFold[fold(query)] && !removedByFold[fold(query)]"));
Assert::true('does not add one', str_contains($fieldScript, 'if (gone && !gone.into) { showWarning(name); return; }'));
Assert::true('and adds a merged one as what it became', str_contains($fieldScript, 'if (gone) { name = gone.into; f = fold(name); }'));
