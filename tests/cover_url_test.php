<?php
/**
 * A replaced cover has to reach the browser.
 *
 * Reported from the live shelf: an own photograph was thrown out, a better
 * one uploaded, and the discarded one came straight back. Nothing had gone
 * wrong in the database or on disk - the new file was there, correct, under
 * the old name. Cover files are named after the book and the source that
 * produced them, so replacing the picture does not change the address, and
 * covers are served with a thirty day cache. The browser was never asked.
 *
 * So the address carries the time the picture was stored. It changes when the
 * picture does and at no other moment, which is the whole requirement: a
 * cache that is busted on every page load is not a cache.
 */
declare(strict_types=1);

use App\Core\CoverImage;
use App\Repository\BookRepository;
use App\Repository\CoverRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('Cover URLs carry the moment the picture was stored');

$row = ['source' => 'own', 'path' => 'ab/x-own.webp', 'external_url' => null, 'created_at' => '2026-09-05 10:00:00'];

Assert::same(
    'the address ends in the stored time',
    CoverImage::url($row),
    '/covers/ab/x-own.webp?v=' . strtotime('2026-09-05 10:00:00')
);

$later = $row;
$later['created_at'] = '2026-09-05 10:04:00';
Assert::true('a new picture is a new address', CoverImage::url($later) !== CoverImage::url($row));
Assert::true('the same one is not', CoverImage::url($row) === CoverImage::url($row));

// The grid asks for the smaller copy. It is the same picture and wants the
// same treatment, or the shelf shows the old thumbnail beside the new detail.
Assert::same(
    'the small copy is versioned too',
    CoverImage::url($row, true),
    '/covers/ab/x-own-klein.webp?v=' . strtotime('2026-09-05 10:00:00')
);

/* Rows written before this existed, and anything that reaches here without a
 * time, keep exactly the address they had. A cover that has not changed must
 * not be re-fetched by everybody just because the code around it did. */
$old = $row;
unset($old['created_at']);
Assert::same('no time, no parameter', CoverImage::url($old), '/covers/ab/x-own.webp');

// An external URL belongs to somebody else's server and is never ours to
// version; it is also only ever shown to the signed-in owner.
Assert::same(
    'an external cover is handed over untouched',
    CoverImage::url(['source' => 'google', 'path' => null, 'external_url' => 'https://books.google.com/x']),
    'https://books.google.com/x'
);

Assert::group('Replacing a cover moves that time along');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new BookRepository($pdo);
$covers = new CoverRepository($pdo);
$book = $books->insert(1, ['title' => 'Falling Asgard', 'isbn13' => '9783690280839']);

$covers->save($book, CoverRepository::SOURCE_OWN, 'ab/x-own.webp', null, null, 800, 1200);

// The reported sequence, exactly: thrown out, then a new one uploaded. The
// file name is the same both times - that is the point of the bug.
$covers->remove($book, CoverRepository::SOURCE_OWN);
$pdo->exec("UPDATE covers SET created_at = '2020-01-01 00:00:00' WHERE book_id = " . $book);
$covers->save($book, CoverRepository::SOURCE_OWN, 'ab/x-own.webp', null, null, 900, 1350);

$stored = $covers->bestFor($book, true);
Assert::same('the cover is back on the same path', $stored['path'], 'ab/x-own.webp');
Assert::true(
    'but its address is not the one the browser has cached',
    CoverImage::url($stored) !== '/covers/ab/x-own.webp?v=' . strtotime('2020-01-01 00:00:00')
);
Assert::true('and it does carry a version', str_contains((string) CoverImage::url($stored), '?v='));
