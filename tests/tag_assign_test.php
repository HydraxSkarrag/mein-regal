<?php
/**
 * Genres and labels for a whole shelf, from a file.
 *
 * Written for regal.hydrax.org: 91 imported labels and 2 genres to become 12
 * genres and 36 labels, decided book by book in a spreadsheet. The plan for
 * getting there was "create the new ones, delete the old ones, import" - and
 * 13 names are on both lists. Creating finds them, deleting takes them with
 * it, and a removed tag cannot be put on a book again. The 51 books meant to
 * carry "Drachenlanze" would have ended up without it.
 */
declare(strict_types=1);

use App\Content\TagAssignment;
use App\Export\Exporter;
use App\Repository\BookRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Tests\Support\TestDatabase;

require_once __DIR__ . '/support/TestDatabase.php';

Assert::group('Reading the file');

$read = TagAssignment::read("\u{FEFF}\"id\",\"isbn13\",\"title\",\"genres\",\"labels\"\r\n\"1\",\"978-3-596-70405-7\",\"Erdsee\",\"High Fantasy\",\"Klassiker; Zauberschule;klassiker\"\r\n");
Assert::same('the byte order mark is not part of the first column', $read['error'], null);
Assert::same('one row', count($read['rows']), 1);
Assert::same('names split at semicolons, twice the same name once', $read['rows'][0]['labels'], ['Klassiker', 'Zauberschule']);
Assert::same('the ISBN is compared as digits', $read['rows'][0]['isbn13'], '9783596704057');

$german = TagAssignment::read(mb_convert_encoding("id;genres;labels\n1;Bilderbuch;Trauer und Tod; Mitmachbuch\n", 'Windows-1252', 'UTF-8'));
Assert::same('a spreadsheet saving with semicolons is read as well', $german['rows'][0]['genres'], ['Bilderbuch']);

$umlaut = TagAssignment::read(mb_convert_encoding("id;genres;labels\n1;Märchen;Führung\n", 'Windows-1252', 'UTF-8'));
Assert::same('and so is one saving in Windows-1252', $umlaut['rows'][0]['labels'], ['Führung']);

Assert::same('a file without the columns says so', TagAssignment::read("titel,genre\nx,y\n")['error'], 'tags.assign.columns');
Assert::same('a file with no rows says so', TagAssignment::read("id,genres,labels\n")['error'], 'tags.assign.empty');

Assert::group('A shelf to try it on');

$pdo = TestDatabase::fresh();
(new UserRepository($pdo))->create('d@example.org', 'ein-langes-passwort', 'D');
$books = new BookRepository($pdo);
$tags = new TagRepository($pdo);

$b1 = $books->insert(1, ['title' => 'Alles Sense!', 'isbn13' => '9783442415519']);
$b2 = $books->insert(1, ['title' => 'Snow crash', 'isbn13' => '9783442236862']);
$b3 = $books->insert(1, ['title' => 'Erdsee', 'isbn13' => '9783596704057']);
$b4 = $books->insert(1, ['title' => 'Zogg', 'isbn13' => '9783407795595']);
$b5 = $books->insert(1, ['title' => 'Nicht in der Datei', 'isbn13' => '9783551684059']);

$tag = static function (string $name, array $on) use ($tags): int {
    $id = $tags->findOrCreate(1, $name);
    foreach ($on as $bookId) {
        $tags->link($bookId, $id);
    }

    return $id;
};
$belletristik = $tag('Belletristik', [$b1, $b2, $b3]);   // on file books only: goes
$humor = $tag('Humor', [$b1]);                            // named again, unchanged
$bilderbuch = $tag('Bilderbuch', [$b4]);                  // a label, the file says genre
$scifi = $tag('science fiction', [$b2]);                  // the file spells it differently
$sammlung = $tag('Sammlung', [$b2, $b5]);                 // also outside the file: stays
$drachen = $tag('Drachen', [$b3]);                        // removed before, the file brings it back
$tags->drop(1, $drachen);
$alt = $tag('Alt', [$b1]);                                // removed before, not named: left alone
$tags->drop(1, $alt);

$visible = static function (int $bookId) use ($pdo): array {
    $statement = $pdo->prepare(
        'SELECT t.name, t.kind FROM book_tags bt JOIN tags t ON t.id = bt.tag_id
          WHERE bt.book_id = ? AND t.dropped_at IS NULL ORDER BY t.name'
    );
    $statement->execute([$bookId]);

    return array_map(static fn (array $r): string => $r['name'] . ' (' . $r['kind'] . ')', $statement->fetchAll());
};
$linkCount = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM book_tags')->fetchColumn();
$before = [$b1 => $visible($b1), $b2 => $visible($b2), $b3 => $visible($b3), $b4 => $visible($b4), $b5 => $visible($b5)];

$file = implode("\n", [
    'id,isbn13,title,genres,labels',
    "{$b1},9783442415519,Alles Sense!,Humoristische Fantasy,Humor",
    "{$b2},9783442236862,Snow crash,Science-Fiction,",
    "{$b3},9783596704057,Erdsee,High Fantasy,",
    "{$b4},9783407795595,Zogg,Bilderbuch,Drachen",
    '999,,Gibt es nicht,Roman,',
    "{$b2},,Snow crash noch einmal,Roman,",
    "{$b5},9780000000000,Falsche ISBN,Roman,",
    'abc,,Keine Nummer,Roman,',
]);

Assert::group('The plan, with nothing written');

$linksBefore = $linkCount();
$plan = TagAssignment::plan($books, $tags, 1, TagAssignment::read($file)['rows']);
Assert::same('planning writes nothing', $linkCount(), $linksBefore);

Assert::same(
    'refused rows, each with its reason, and none of them guessed at',
    array_column($plan['rejected'], 'reason'),
    ['tags.assign.reject.unknown', 'tags.assign.reject.twice', 'tags.assign.reject.isbn', 'tags.assign.reject.id']
);
Assert::same('the book outside the file is counted', $plan['outside'], 1);

$byName = array_column($plan['names'], null, 'name');
Assert::same('a name that is new is new', $byName['High Fantasy']['was'], null);
Assert::same('a name that exists is used, not made twice', $byName['Humor']['id'], $humor);
Assert::same('a label the file calls a genre becomes one', [$byName['Bilderbuch']['was']['kind'], $byName['Bilderbuch']['kind']], ['label', 'genre']);
Assert::same('a spelling is matched by its slug', $byName['Science-Fiction']['id'], $scifi);

/* The case the plan on paper would have lost: removed, and named again. */
Assert::same('a removed name the file uses is brought back', [$byName['Drachen']['id'], $byName['Drachen']['was']['dropped']], [$drachen, true]);

Assert::same('removed afterwards: only what nothing outside the file carries', array_column($plan['drop'], 'name'), ['Belletristik']);
Assert::true('there is something to apply', TagAssignment::canApply($plan));

Assert::group('Applying it');

$result = TagAssignment::apply($tags, 1, $plan);
Assert::same('what it reports', $result, ['books' => 4, 'created' => 2, 'dropped' => 1]);

Assert::same('every book has exactly its row', [$visible($b1), $visible($b2), $visible($b3), $visible($b4)], [
    ['Humor (label)', 'Humoristische Fantasy (genre)'],
    ['Science-Fiction (genre)'],
    ['High Fantasy (genre)'],
    ['Bilderbuch (genre)', 'Drachen (label)'],
]);
Assert::same('the book outside the file keeps what it had', $visible($b5), $before[$b5]);

Assert::same('a tag still carried outside the file is not removed', $tags->find(1, $sammlung)['dropped_at'], null);

/* Drachen came back, and with it the hidden link on Erdsee, which the file
 * does not give Erdsee. Left there, restoring the tag would have put it on a
 * book the row says nothing about. */
Assert::same('a returning tag does not bring back links the file does not ask for', $tags->bookIdsFor(1, $drachen), [$b4]);

Assert::true('the old tag is removed, not deleted', $tags->find(1, $belletristik)['dropped_at'] !== null);
Assert::same('and keeps its links, so restoring it is real', count($tags->bookIdsFor(1, $belletristik)), 3);
Assert::same('a tag removed long before stays as it was, links and all', [$tags->find(1, $alt)['dropped_at'] !== null, $tags->bookIdsFor(1, $alt)], [true, [$b1]]);

$again = TagAssignment::plan($books, $tags, 1, TagAssignment::read($file)['rows']);
Assert::true('the same file a second time has nothing left to do', !TagAssignment::canApply($again));

Assert::group('The way back is an export from before');

/* Taken now, from the state just applied, then another file on top, then the
 * export read back in: the shelf has to look the way it did when the export
 * was taken. */
$exportCsv = static function () use ($pdo): string {
    $handle = fopen('php://memory', 'r+');
    (new Exporter($pdo))->fullCsv(1, $handle);
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    return $csv;
};
$snapshot = $exportCsv();
$state = static fn (): array => array_map($visible, [$b1, $b2, $b3, $b4, $b5]);
$stateThen = $state();

$other = "id,genres,labels\n{$b1},Roman,Klassiker\n{$b4},Roman,\n";
TagAssignment::apply($tags, 1, TagAssignment::plan($books, $tags, 1, TagAssignment::read($other)['rows']));
Assert::true('the other file did change the shelf', $state() !== $stateThen);

$back = TagAssignment::plan($books, $tags, 1, TagAssignment::read($snapshot)['rows']);
Assert::same('the export is accepted as it is, every book found', [$back['rejected'], $back['outside']], [[], 0]);
TagAssignment::apply($tags, 1, $back);
Assert::same('and reading it back restores what was', $state(), $stateThen);

Assert::group('What stops it');

$clash = TagAssignment::plan($books, $tags, 1, TagAssignment::read("id,genres,labels\n{$b1},Humor,\n{$b2},,Humor\n")['rows']);
Assert::same('a name that is a genre in one row and a label in another', $clash['conflicts'], ['Humor']);
Assert::true('cannot be applied, because which was meant is a guess', !TagAssignment::canApply($clash));
$linksNow = $linkCount();
TagAssignment::apply($tags, 1, $clash);
Assert::same('and applying it anyway writes nothing', $linkCount(), $linksNow);

/* Between the preview and the button somebody edits a book in another tab.
 * The plan is not the one that was shown any more, and the button refuses. */
$shown = TagAssignment::plan($books, $tags, 1, TagAssignment::read($other)['rows']);
$books->replaceTags(1, $b4, ['Irgendwas'], $tags);
$now = TagAssignment::plan($books, $tags, 1, TagAssignment::read($other)['rows']);
Assert::true('an edit in between changes the fingerprint', TagAssignment::fingerprint($shown) !== TagAssignment::fingerprint($now));

$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/TagController.php');
Assert::true('and the button compares it before writing', str_contains($controller, 'hash_equals(TagAssignment::fingerprint($plan), $request->post(\'fingerprint\'))'));

$routes = (string) file_get_contents(PROJECT_ROOT . '/public/index.php');
Assert::true('both steps are posts', str_contains($routes, "post('/admin/tags/assign/preview'") && str_contains($routes, "post('/admin/tags/assign'"));

Assert::group('No SQL of its own');

/* The rule the project writes down: database access belongs in the
 * repositories. This file broke it the day it was written, with six queries of
 * its own. */
$assignmentSource = (string) file_get_contents(PROJECT_ROOT . '/app/Content/TagAssignment.php');
Assert::true('it asks the repositories', !preg_match('/->(prepare|query|exec)\(|\bPDO\b/', $assignmentSource));
