<?php
/**
 * Link the books to the reviews written about them.
 *
 *   php bin/reviews.php --fetch                  fetch the blog's posts, then report
 *   php bin/reviews.php                          report from what was fetched last
 *   php bin/reviews.php --commit                 write the links
 *   php bin/reviews.php --commit --isbn          and fill in ISBNs that are missing
 *   php bin/reviews.php --open=storage/offen.txt what could not be matched
 *
 * Needs 'review_blog_url' in the configuration. Without it this does nothing
 * and contacts nobody - which is the point of it being an option: an
 * installation that has no blog never reaches out to one.
 *
 * The posts are fetched once and kept in storage/, because matching them
 * against three thousand books is worth trying more than once and the blog
 * should not pay for that.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Content\ReviewMatcher;
use App\Core\Config;
use App\Core\Database;
use App\Core\Isbn;
use App\Lookup\BlogPosts;
use App\Lookup\HttpClient;
use App\Repository\BookRepository;

$options = getopt('', ['fetch', 'commit', 'isbn', 'open::', 'owner::', 'sqlite::']);
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

$blogUrl = $config->str('review_blog_url');
$cache = PROJECT_ROOT . '/storage/blog-posts.json';
$blog = new BlogPosts(new HttpClient($config->str('api_contact')), $blogUrl);

if (!$blog->configured()) {
    fwrite(STDERR, "No 'review_blog_url' in the configuration - nothing to do.\n");
    exit(1);
}

if (isset($options['fetch']) || !is_file($cache)) {
    echo 'Fetching from ' . $blogUrl . ' ';
    $posts = $blog->fetchAll(static function (int $so_far): void {
        echo '.';
    });
    BlogPosts::save($posts, $cache);
    echo "\n" . count($posts) . " posts saved.\n\n";
} else {
    $posts = BlogPosts::load($cache);
    printf("%d posts from %s (--fetch to refresh).\n\n", count($posts), date('d.m.Y H:i', (int) filemtime($cache)));
}

$books = new BookRepository($pdo);
$catalogue = $books->forReviewMatching($ownerId);
$byId = [];
foreach ($catalogue as $book) {
    $byId[$book['id']] = $book;
}

$matcher = new ReviewMatcher($catalogue);

/* One book, one link, and the newest post wins.
 *
 * A book can have been written about more than once - a reissue, a second
 * reading years later. The field holds one, so the posts are walked oldest
 * first and later ones overwrite: what somebody wants to be sent to is the
 * most recent thing written about the book. */
usort($posts, static fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

$decided = [];
$isbnFor = [];
$open = [];
$counts = ['isbn' => 0, 'title' => 0, 'title_only' => 0, 'none' => 0];

foreach ($posts as $post) {
    $result = $matcher->match(['title' => $post['title'], 'content' => $post['content']]);
    $counts[$result['how']]++;

    if ($result['book_id'] === null) {
        $open[] = [
            'link'   => $post['link'],
            'title'  => html_entity_decode($post['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'work'   => $result['work'],
            'isbns'  => $result['isbns'],
        ];
        continue;
    }

    $decided[$result['book_id']] = $post['link'];
    if ($result['how'] !== 'isbn' && $result['isbns'] !== []) {
        // Matched by name, so the ISBN in the post is one the shelf does not
        // have. Kept aside rather than written: only --isbn asks for that.
        $isbnFor[$result['book_id']] = $result['isbns'][0];
    }
}

printf(
    "matched by ISBN %d, by title and author %d, by title alone %d, undecided %d\n",
    $counts['isbn'],
    $counts['title'],
    $counts['title_only'],
    $counts['none']
);
printf("distinct books with a review: %d\n", count($decided));

/* What this script may overwrite: its own work.
 *
 * A link pointing somewhere else was put there by hand - a review on another
 * site, a guest post - and nothing here knows better than the person who
 * typed it. Links to the configured blog are this script's own and may be
 * corrected as the matching improves. */
$prefix = rtrim($blogUrl, '/');
$new = 0;
$changed = 0;
$same = 0;
$foreign = 0;
foreach ($decided as $bookId => $link) {
    $current = (string) ($byId[$bookId]['review_url'] ?? '');
    if ($current === $link) {
        $same++;
    } elseif ($current === '') {
        $new++;
    } elseif (str_starts_with($current, $prefix)) {
        $changed++;
    } else {
        $foreign++;
        unset($decided[$bookId]);
    }
}
printf("would add %d, correct %d, leave %d unchanged\n", $new, $changed, $same);
if ($foreign > 0) {
    printf("left alone because they point elsewhere: %d\n", $foreign);
}

$fillable = [];
foreach ($isbnFor as $bookId => $isbn) {
    if (($byId[$bookId]['isbn13'] ?? null) === null || $byId[$bookId]['isbn13'] === '') {
        $fillable[$bookId] = $isbn;
    }
}
printf("books whose missing ISBN the blog knows: %d\n", count($fillable));

if (isset($options['open']) && $options['open'] !== false) {
    $lines = [];
    foreach ($open as $entry) {
        $lines[] = $entry['work'] . "\n  " . $entry['link']
            . ($entry['isbns'] === [] ? '' : "\n  ISBN " . implode(', ', $entry['isbns']));
    }
    file_put_contents((string) $options['open'], implode("\n\n", $lines) . "\n");
    printf("\n%d undecided posts written to %s\n", count($open), $options['open']);
}

if (!$commit) {
    echo "\nNothing written. Run again with --commit.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    foreach ($decided as $bookId => $link) {
        $books->setReviewUrl($ownerId, (int) $bookId, $link);
    }
    if (isset($options['isbn'])) {
        foreach ($fillable as $bookId => $isbn) {
            $books->setIsbnIfEmpty($ownerId, (int) $bookId, $isbn, Isbn::to10($isbn));
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

printf(
    "\nWritten: %d review links%s.\n",
    count($decided),
    isset($options['isbn']) ? ', ' . count($fillable) . ' ISBNs' : ''
);
