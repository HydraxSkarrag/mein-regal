<?php
/**
 * Take the catalogue notation out of the tag names it got into.
 *
 *   php bin/tags.php                    what would change, writing nothing
 *   php bin/tags.php --commit           do it
 *   php bin/tags.php --owner=2 --commit a second shelf
 *
 * The catalogues file books under a scheme and hand the notation over with
 * the name attached - "59 Belletristik", "07 Kinder- und Jugendliteratur",
 * "621.3 Elektrotechnik". The lookup was only taking off the shapes somebody
 * had happened to see; the rest arrived on the shelf as tags, so the list
 * grew a numbered twin of half its own entries and a "#" section at the end
 * of the alphabet.
 *
 * Text::withoutClassification is the rule, and the lookups now use the same
 * one, so nothing new arrives like this. What is already stored is what this
 * cleans up - the same work the button under "Katalognummern" in the tag
 * administration does, which is the way to do it on a host with no shell.
 * Both read App\Content\TagNotation, so they cannot come to different
 * conclusions.
 *
 * Two outcomes per tag, and the difference matters:
 *
 *   rename  no tag under the corrected name yet - the entry keeps its books
 *           and simply loses the number.
 *   merge   there is one already, which is the usual case: "59 Belletristik"
 *           beside "Belletristik". The books move over and the numbered one
 *           is dropped - reversibly, the way the tag administration drops
 *           anything, so a wrong call here can be taken back.
 *
 * A tag that is nothing but a code is left alone deliberately. Removing an
 * entry is a decision about the shelf rather than a correction, and the tag
 * administration is where decisions about the shelf are made.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Content\TagNotation;
use App\Core\Config;
use App\Core\Database;
use App\Repository\TagRepository;

$options = getopt('', ['commit', 'owner::', 'sqlite::']);
$ownerId = (int) ($options['owner'] ?? 1);
$commit = isset($options['commit']);

$config = Config::load();

if (isset($options['sqlite']) && $options['sqlite'] !== false) {
    $pdo = new PDO('sqlite:' . $options['sqlite']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    Database::sqliteDefaults($pdo);
    $where = (string) $options['sqlite'];
} else {
    $pdo = Database::connect($config);
    $where = Database::describe($config);
}

try {
    Database::assertSchema($pdo, $where);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$tags = new TagRepository($pdo);
$plan = TagNotation::plan($tags, $ownerId);

echo $where, "\n";
echo $commit ? "WRITING\n" : "DRY RUN - nothing is written.\n";
echo str_repeat('-', 60), "\n";

if (TagNotation::changes($plan) === 0 && $plan['codes'] === []) {
    echo "Nothing to clean up: no tag carries a notation.\n";
    exit(0);
}

foreach ($plan['renames'] as $item) {
    printf("rename  %-44s -> %s\n", $item['tag']['name'], $item['clean']);
}
foreach ($plan['merges'] as $item) {
    printf(
        "merge   %-44s -> %s (%d %s)\n",
        $item['from']['name'],
        $item['into']['name'],
        $item['from']['book_count'],
        (int) $item['from']['book_count'] === 1 ? 'book' : 'books'
    );
}
foreach ($plan['codes'] as $tag) {
    printf(
        "code    %-44s (%d)  left alone - a bare code is a decision for the tag administration\n",
        $tag['name'],
        $tag['book_count']
    );
}

echo str_repeat('-', 60), "\n";

if ($commit) {
    $done = TagNotation::apply($tags, $ownerId, $plan);
    printf("%d renamed, %d merged, %d bare codes reported.\n", $done['renamed'], $done['merged'], count($plan['codes']));
} else {
    printf(
        "%d would be renamed, %d merged, %d bare codes reported.\n",
        count($plan['renames']),
        count($plan['merges']),
        count($plan['codes'])
    );
    echo "Run again with --commit to write it.\n";
}
