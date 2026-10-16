<?php
/**
 * Scanning into a shelf that has a vocabulary of its own.
 *
 * Asked for on regal.hydrax.org, once the genres and labels there were
 * Daniel's own: the catalogues' subjects are written for libraries, and
 * nearly every one of them was a label to take off again. "Beim Scannen"
 * has a second box now, remembered like the first, and the card says which
 * way it is set where the book is being looked at.
 */
declare(strict_types=1);

Assert::group('Without genres and labels');

$noTagsTemplate = (string) file_get_contents(PROJECT_ROOT . '/app/templates/scan/index.php');
$noTagsScript = (string) file_get_contents(PROJECT_ROOT . '/public/js/scanner.js');

Assert::true('the box is among the options for scanning', str_contains($noTagsTemplate, '<input id="no-tags" type="checkbox">'));
Assert::true('and says what it does', str_contains(t('scan.notags'), 'Ohne Genre und Schlagwörter'));
Assert::true('it is remembered in the browser, like the other one', str_contains($noTagsScript, "localStorage.setItem('regal.scan.notags'"));
Assert::true('with it on, the book is saved with none', str_contains($noTagsScript, "JSON.stringify(noTagsToggle.checked ? [] : (currentBook.tags || []))"));
Assert::true('not even the removed ones out of sight', !str_contains($noTagsScript, "noTagsToggle.checked ? (currentBook.tags_removed"));

/* The card shows the choice where the book is, and pressing it moves the
 * same switch - the way the reading status works. */
Assert::true('the card says the subjects are left out, and which they were', str_contains($noTagsScript, "fill(text.willSkipTags, 'tags', tags.join(', '))"));
Assert::true('and pressing that line moves the switch', str_contains($noTagsScript, 'noTagsToggle.checked = !noTagsToggle.checked;') && str_contains($noTagsScript, "noTagsToggle.dispatchEvent(new Event('change'));"));
Assert::true('a book the catalogue gave nothing for shows nothing', str_contains($noTagsScript, "if (!tags.length) { return ''; }"));

Assert::group('Saved without them');

/* The server takes what the card sends: an empty list is a book with no tags,
 * not a lookup to repeat. */
$noTagsController = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/ScanController.php');
Assert::true('the store takes the tags from the request', str_contains($noTagsController, "\$this->decodeTags(\$request->post('tags'))"));
$decode = new ReflectionMethod(App\Controller\ScanController::class, 'decodeTags');
$scanForDecode = (new ReflectionClass(App\Controller\ScanController::class))->newInstanceWithoutConstructor();
Assert::same('and an empty list is none', $decode->invoke($scanForDecode, '[]'), []);
