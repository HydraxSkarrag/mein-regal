<?php
declare(strict_types=1);

namespace App\Controller;

use App\Content\DefaultPages;
use App\Core\Request;
use App\Core\Text;
use App\Core\Response;
use App\Http\Application;
use App\Core\Translator;
use App\Repository\PageRepository;

/**
 * The pages that are not the shelf: legal texts, robots.txt, the sitemap and
 * the web app manifest.
 */
final class PageController
{
    /**
     * Where this came from.
     *
     * Constants, not configuration. The address of the repository and the
     * blog it was written for are facts about the software; an installation
     * may rebrand the shelf, but the credit in the footer is not a field to
     * be edited away. repository_url in config.php exists for forks that
     * genuinely live somewhere else.
     */
    public const SOFTWARE_NAME = 'Mein Regal';
    public const REPOSITORY  = 'https://github.com/hydrax/mein-regal';
    public const ORIGIN_NAME = 'Bücherhausen';
    public const ORIGIN_URL  = 'https://www.buecherhausen.de/';

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * The prose pages: what this shelf is, the Impressum, the privacy policy.
     *
     * All three live in the database rather than in a template. For the about
     * page that is so a second installation introduces itself in its own
     * words. For the legal pages the reason is sharper: a privacy policy that
     * names a hosting company in the source code is false for everyone who
     * hosts elsewhere, and a text that needs a deployment to correct is a text
     * that stays wrong.
     */
    public function show(string $slug): Response
    {
        $locale = $this->app->translator->locale();
        $legal = $slug !== PageRepository::ABOUT;

        // The legal pages fall back to whatever language exists; the about
        // page does not. See PageRepository::findAnyLocale for why.
        $page = $legal
            ? $this->app->pages->findAnyLocale($this->app->ownerId, $slug, $locale)
            : $this->app->pages->find($this->app->ownerId, $slug, $locale);

        $body = $this->app->view->render('pages.show', [
            'page'      => $page,
            'slug'      => $slug,
            'locale'    => $locale,
            'heading'   => $page['title'] ?? t('page.' . $slug),
            'otherWith' => array_values(array_diff(
                $this->app->pages->localesFor($this->app->ownerId, $slug),
                [$locale]
            )),
            'signedIn'  => $this->app->auth->isSignedIn(),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content'         => $body,
            'title'           => $page['title'] ?? t('page.' . $slug),
            'narrow'          => true,
            'canonical'       => $this->app->url('/' . $slug),
            'metaDescription' => $this->summarise($page['body'] ?? null),
        ]));
    }

    public function edit(string $slug, Request $request): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        // Which language is being edited comes from the address, so both can
        // be written without switching the whole interface back and forth.
        $locale = Translator::normalizeLocale(
            $request->query('lang') ?: $this->app->translator->locale()
        );
        $page = $this->app->pages->find($this->app->ownerId, $slug, $locale);

        if ($request->isPost()) {
            if (!$this->app->csrf->isValid($request->allPost())) {
                return $this->form($slug, $page, $locale, t('error.csrf'));
            }
            $title = trim($request->post('title'));
            if ($title === '') {
                return $this->form($slug, $page, $locale, t('edit.title.required'));
            }

            $this->app->pages->save(
                $this->app->ownerId,
                $slug,
                $locale,
                mb_substr($title, 0, 200),
                mb_substr(trim($request->post('body')), 0, 20000) ?: null
            );
            $this->app->session->flash(t('edit.saved'), 'ok');

            return Response::redirect('/' . $slug);
        }

        return $this->form($slug, $page, $locale);
    }

    /**
     * Render the markup subset the way the page itself will.
     *
     * The preview goes through the same renderer as the page rather than a
     * copy of it in JavaScript. Two implementations of the same syntax drift,
     * and the one that drifts unnoticed is the preview - which is exactly the
     * one people trust before they publish.
     */
    public function preview(Request $request): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return Response::json(['error' => 'auth'], 403);
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return Response::json(['error' => 'csrf'], 419);
        }

        return Response::json([
            'html' => Text::prose(mb_substr((string) $request->post('body'), 0, 20000)),
        ]);
    }

    private function form(string $slug, ?array $page, string $locale, string $error = ''): Response
    {
        /*
         * An unwritten legal page opens with the standard text already in the
         * box, rather than an empty one.
         *
         * A fresh installation gets these at setup. This covers the two cases
         * that misses: an installation upgrading from when the texts were
         * templates, and one where the seeding failed. Nothing is published
         * by this - someone still has to read it and press save, which is the
         * right order of events for a legal text.
         */
        if ($page === null && $slug !== PageRepository::ABOUT) {
            $default = DefaultPages::all($this->app->config)[$slug] ?? null;
            if ($default !== null) {
                $page = [
                    'title'      => $default['title'],
                    'body'       => $default['body'],
                    'locale'     => $locale,
                    'updated_at' => '',
                ];
            }
        }

        $body = $this->app->view->render('pages.edit', [
            'page'      => $page,
            'slug'      => $slug,
            'locale'    => $locale,
            'written'   => $this->app->pages->localesFor($this->app->ownerId, $slug),
            'error'     => $error,
            'csrfField' => $this->app->csrf->field(),
            'heading'   => t('page.' . $slug),
            'suggested' => $slug === PageRepository::ABOUT
                ? t('about.suggested', [
                    'owner' => $this->app->config->str('legal.operator'),
                    'blog'  => $this->app->config->str('blog_name'),
                ])
                : '',
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => t('page.edit', ['page' => t('page.' . $slug)]),
            'narrow'  => true,
            'noIndex' => true,
            'scripts' => ['/js/editor.js'],
        ]), $error === '' ? 200 : 422)->noIndex();
    }

    /** A plain-text opening for the meta description. */
    private function summarise(?string $body): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $body) ?? '');

        return $text === '' ? t('app.tagline') : Text::truncate($text, 155);
    }

    /**
     * What this software is, where it comes from and where to get it.
     *
     * Not editable, and not in the database: it describes the application
     * rather than the collection, so it should read the same on every
     * installation and stay correct without anyone maintaining it.
     */
    public function project(): Response
    {
        $body = $this->app->view->render('pages.project', [
            'repository' => $this->app->config->str('repository_url', self::REPOSITORY),
            'originName' => self::ORIGIN_NAME,
            'originUrl'  => self::ORIGIN_URL,
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content'         => $body,
            'title'           => t('project.title'),
            'narrow'          => true,
            'canonical'       => $this->app->url('/project'),
            'metaDescription' => t('project.lead'),
        ]));
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /login',
            'Disallow: /logout',
            'Disallow: /scan',
            'Disallow: /admin',
            'Disallow: /api/',
            // Kept out of the index when it is not public; the page itself
            // still redirects to the login, this only stops crawlers asking.
            ...($this->app->publicStats() ? [] : ['Disallow: /stats']),
            // Filter and sort combinations would otherwise pile thousands of
            // near-identical pages into the index.
            'Disallow: /*?*sort=',
            'Disallow: /*?*page=',
            'Disallow: /*?*binding=',
            '',
            'Sitemap: ' . $this->app->url('/sitemap.xml'),
        ];

        return Response::text(implode("\n", $lines) . "\n");
    }

    /**
     * The sitemap is generated, not stored: with three thousand books a
     * static file would be stale the moment one is added.
     */
    public function sitemap(): Response
    {
        $statement = $this->app->pdo->prepare(
            'SELECT slug, updated_at FROM books WHERE owner_id = ? ORDER BY id ASC LIMIT 20000'
        );
        $statement->execute([$this->app->ownerId]);

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $pages = [['/', '1.0'], ['/unread', '0.6'], ['/about', '0.4'], ['/project', '0.3']];
        if ($this->app->publicStats()) {
            $pages[] = ['/stats', '0.6'];
        }
        foreach ($pages as [$path, $priority]) {
            $xml[] = '  <url><loc>' . e($this->app->url($path)) . '</loc>'
                . '<changefreq>weekly</changefreq><priority>' . $priority . '</priority></url>';
        }
        foreach ($statement->fetchAll() as $row) {
            $xml[] = '  <url><loc>' . e($this->app->url('/book/' . $row['slug'])) . '</loc>'
                . '<lastmod>' . e(substr((string) $row['updated_at'], 0, 10)) . '</lastmod>'
                . '<changefreq>yearly</changefreq><priority>0.5</priority></url>';
        }
        $xml[] = '</urlset>';

        return Response::text(implode("\n", $xml))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * The web app manifest. This is what turns "add to home screen" into a
     * real tile with its own icon - for a tool used daily on a phone to scan
     * books, it is the largest gain for the least work.
     */
    public function manifest(): Response
    {
        return Response::json([
            'name'             => $this->app->config->str('site_name', 'Mein Regal'),
            'short_name'       => $this->app->config->str('site_name', 'Regal'),
            'description'      => t('app.tagline'),
            'start_url'        => '/',
            'scope'            => '/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#f9fefd',
            'theme_color'      => '#ed002f',
            'lang'             => $this->app->translator->locale(),
            'icons'            => [
                ['src' => '/assets/favicon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/apple-touch-icon.png', 'sizes' => '180x180', 'type' => 'image/png'],
            ],
            'shortcuts' => [[
                'name' => t('nav.scan'),
                'url'  => '/scan',
            ]],
        ])->withHeader('Content-Type', 'application/manifest+json; charset=utf-8');
    }
}
