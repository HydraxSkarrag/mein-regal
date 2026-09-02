<?php
declare(strict_types=1);

namespace App\Lookup;

use RuntimeException;

/**
 * The posts of a WordPress blog, fetched once and kept.
 *
 * WordPress answers /wp-json/wp/v2/posts without a key or an account, a
 * hundred at a time, and says in a header how many there are - so a whole
 * blog is a handful of requests. They are written to storage as they arrive,
 * because matching them against three thousand books is something one wants
 * to try more than once, and each try should not cost the blog a thing.
 *
 * Nothing here runs unless a blog address is configured. An installation
 * that names none never contacts anybody, which is the point of it being an
 * option rather than a fixed address.
 */
final class BlogPosts
{
    private const PER_PAGE = 100;
    private const MAX_PAGES = 50;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $blogUrl,
    ) {
    }

    public function configured(): bool
    {
        return $this->blogUrl !== '';
    }

    /**
     * Fetch every post, following the pages.
     *
     * @return list<array{id: int, link: string, title: string, content: string, date: string}>
     */
    public function fetchAll(?callable $progress = null): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('No blog address configured.');
        }

        $base = rtrim($this->blogUrl, '/') . '/wp-json/wp/v2/posts';
        $posts = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $url = $base . '?' . http_build_query([
                'per_page' => self::PER_PAGE,
                'page'     => $page,
                '_fields'  => 'id,link,title,content,date',
            ]);

            $response = $this->http->get($url);
            if ($response['status'] !== 200) {
                // Past the last page WordPress answers 400, which is how it
                // says "that is all" rather than a fault worth reporting.
                if ($page > 1 && $response['status'] === 400) {
                    break;
                }
                throw new RuntimeException('Blog answered with HTTP ' . $response['status']);
            }

            $batch = json_decode($response['body'], true);
            if (!is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $post) {
                $posts[] = [
                    'id'      => (int) ($post['id'] ?? 0),
                    'link'    => (string) ($post['link'] ?? ''),
                    'title'   => (string) ($post['title']['rendered'] ?? ''),
                    'content' => strip_tags((string) ($post['content']['rendered'] ?? '')),
                    'date'    => (string) ($post['date'] ?? ''),
                ];
            }

            if ($progress !== null) {
                $progress(count($posts));
            }
            if (count($batch) < self::PER_PAGE) {
                break;
            }
        }

        return $posts;
    }

    /** @param list<array<string,mixed>> $posts */
    public static function save(array $posts, string $file): void
    {
        $json = json_encode($posts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Could not encode the posts.');
        }
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }
        file_put_contents($file, $json);
    }

    /** @return list<array{id: int, link: string, title: string, content: string, date: string}> */
    public static function load(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $posts = json_decode((string) file_get_contents($file), true);

        return is_array($posts) ? $posts : [];
    }
}
