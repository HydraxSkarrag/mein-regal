<?php
/**
 * The preview of a file import, as the page draws it.
 *
 * It sits behind the sign-in, so nothing that fetches pages from outside ever
 * sees it, and the controller hands it six variables. One missing would be a
 * 500 on exactly the page that has to be read before anything is written -
 * the same class of fault that once kept the series facet off the shelf with
 * every test green.
 *
 * And the page carries the file back to the server with its button, which is
 * the second thing that can go wrong without anybody seeing it: a file saved
 * by a spreadsheet in Windows-1252 read correctly in the preview and went back
 * as "F\u{FFFD}hrung".
 */
declare(strict_types=1);

use App\Content\TagAssignment;
use App\Core\Formatter;
use App\Core\View;
use App\Repository\BookRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Tests\Support\TestDatabase;

require_once __DIR__ . '/support/TestDatabase.php';

Assert::group('Everything the preview reads is handed to it');

$assignTemplate = (string) file_get_contents(PROJECT_ROOT . '/app/templates/admin/tag_assign.php');
$assignController = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/TagController.php');

preg_match('/render\(\'admin\.tag_assign\', \[(.*?)\]\);/s', $assignController, $assignHandedBlock);
preg_match_all("/'([a-zA-Z_]+)'\s*=>/", $assignHandedBlock[1] ?? '', $assignHanded);

// Code only: the doc comment names variables in prose.
$assignCode = (string) preg_replace('~/\*.*?\*/~s', '', $assignTemplate);
preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $assignCode, $assignUsed);
preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=[^=>]/', $assignCode, $assignAssigned);
preg_match_all('/as\s+\$([a-zA-Z_][a-zA-Z0-9_]*)/', $assignCode, $assignBound);
preg_match_all('/fn\s*\(([^)]*)\)/', $assignCode, $assignParams);
preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', implode(' ', $assignParams[1]), $assignParamNames);

// What every page gets from Application without asking.
$assignShared = ['view', 'formatter'];
$assignMissing = array_values(array_diff(
    array_unique($assignUsed[1]),
    $assignHanded[1],
    $assignAssigned[1],
    $assignBound[1],
    $assignParamNames[1],
    $assignShared
));
Assert::same('the controller hands over every variable the template reads', $assignMissing, []);

Assert::group('A shelf and a file');

$assignPdo = TestDatabase::fresh();
(new UserRepository($assignPdo))->create('d@example.org', 'ein-langes-passwort', 'D');
$assignBooks = new BookRepository($assignPdo);
$assignTags = new TagRepository($assignPdo);

$assignFirst = $assignBooks->insert(1, ['title' => 'Führung <script>alert(1)</script>', 'isbn13' => '9783593406077']);
$assignSecond = $assignBooks->insert(1, ['title' => 'Zogg', 'isbn13' => '9783407795595']);
$assignTags->link($assignFirst, $assignTags->findOrCreate(1, 'Belletristik'));

$assignView = new View(PROJECT_ROOT . '/app/templates');
$assignView->share('formatter', new Formatter('de'));
$assignView->share('view', $assignView);

/* The same steps the controller takes, in its order: the upload made UTF-8,
   read, planned, drawn. */
$assignPage = static function (string $upload, string $notice = '') use ($assignView, $assignBooks, $assignTags): array {
    $contents = TagAssignment::normalize($upload);
    $plan = TagAssignment::plan($assignBooks, $assignTags, 1, TagAssignment::read($contents)['rows']);
    $html = $assignView->render('admin.tag_assign', [
        'plan'        => $plan,
        'canApply'    => TagAssignment::canApply($plan),
        'fingerprint' => TagAssignment::fingerprint($plan),
        'contents'    => $contents,
        'notice'      => $notice,
        'csrfField'   => '<input type="hidden" name="_token" value="t">',
    ]);

    return [$html, $plan];
};

Assert::group('The preview of a file that can be applied');

[$assignHtml, $assignPlan] = $assignPage(
    "id,isbn13,title,genres,labels\n{$assignFirst},9783593406077,x,Sachbuch,Führung\n{$assignSecond},,Zogg,Bilderbuch,<b>Drachen</b>\n999,,Weg,Roman,\n",
    'Seit der Vorschau hat sich im Regal etwas geändert.'
);

Assert::true('it draws', str_contains($assignHtml, 'Zuordnung einlesen: Vorschau'));
Assert::true('with the button', str_contains($assignHtml, '>Einspielen</button>'));
Assert::true('the notice when there is one', str_contains($assignHtml, 'flash--hint'));
Assert::true('a refused row with its reason in words', str_contains($assignHtml, 'kein Buch mit dieser id im Regal'));
Assert::true('each book before and after', str_contains($assignHtml, '<del>Belletristik</del>') && str_contains($assignHtml, '<ins>Führung</ins>'));
Assert::true('what goes afterwards', str_contains($assignHtml, 'Danach entfernt'));

Assert::true('a title is text', !str_contains($assignHtml, '<script>alert(1)</script>'));
Assert::true('and so is a name from the file', !str_contains($assignHtml, '<b>Drachen</b>'));

Assert::group('What the button sends back is what was shown');

$assignRoundTrip = static function (string $html): array {
    preg_match('/<textarea name="csv" hidden>\n?(.*?)<\/textarea>/s', $html, $textarea);
    preg_match('/name="fingerprint" value="([0-9a-f]+)"/', $html, $fingerprint);

    return [html_entity_decode($textarea[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), $fingerprint[1] ?? ''];
};

[$assignSent, $assignShown] = $assignRoundTrip($assignHtml);
$assignAgain = TagAssignment::plan($assignBooks, $assignTags, 1, TagAssignment::read($assignSent)['rows']);
Assert::same('the file planned again gives the plan that was shown', TagAssignment::fingerprint($assignAgain), $assignShown);

/* The fault this caught: the upload went into the page as it came. */
$assignLatin = mb_convert_encoding("id;genres;labels\n{$assignFirst};Sachbuch;Führung\n", 'Windows-1252', 'UTF-8');
[$assignLatinHtml] = $assignPage($assignLatin);
[$assignLatinSent, $assignLatinShown] = $assignRoundTrip($assignLatinHtml);
Assert::true('a Windows-1252 file goes back readable', str_contains($assignLatinSent, 'Führung'));
Assert::same(
    'and plans to the same thing on the second press',
    TagAssignment::fingerprint(TagAssignment::plan($assignBooks, $assignTags, 1, TagAssignment::read($assignLatinSent)['rows'])),
    $assignLatinShown
);
Assert::true('the controller makes it UTF-8 before the page does', str_contains($assignController, '$contents = TagAssignment::normalize($contents);'));

Assert::group('The preview of a file that cannot be applied');

[$assignClashHtml] = $assignPage("id,genres,labels\n{$assignFirst},Humor,\n{$assignSecond},,Humor\n");
Assert::true('a name that is both says so', str_contains($assignClashHtml, 'einmal als Genre und einmal als Schlagwort') && str_contains($assignClashHtml, 'Humor'));
Assert::true('and there is no button', !str_contains($assignClashHtml, '>Einspielen</button>'));
Assert::true('but a way out', str_contains($assignClashHtml, 'Erst die Datei korrigieren'));

TagAssignment::apply($assignTags, 1, $assignPlan);
[$assignDoneHtml] = $assignPage("id,isbn13,title,genres,labels\n{$assignFirst},9783593406077,x,Sachbuch,Führung\n{$assignSecond},,Zogg,Bilderbuch,<b>Drachen</b>\n");
Assert::true('a file already applied has nothing to do', str_contains($assignDoneHtml, 'Nichts zu tun') && !str_contains($assignDoneHtml, '>Einspielen</button>'));
