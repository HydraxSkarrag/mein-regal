<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Http\Application;

/**
 * The pages that are not the shelf: legal texts, robots.txt, the sitemap and
 * the web app manifest.
 */
final class PageController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function imprint(): Response
    {
        return $this->legal('imprint', t('legal.imprint'));
    }

    public function privacy(): Response
    {
        return $this->legal('privacy', t('legal.privacy'));
    }

    private function legal(string $template, string $title): Response
    {
        $body = $this->app->view->render('legal.' . $template, [
            'legal'   => $this->app->config->get('legal', []),
            'siteUrl' => $this->app->url('/'),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content'   => $body,
            'title'     => $title,
            'narrow'    => true,
            'canonical' => $this->app->url('/' . ($template === 'imprint' ? 'impressum' : 'datenschutz')),
        ]));
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /anmelden',
            'Disallow: /abmelden',
            'Disallow: /erfassen',
            'Disallow: /verwaltung',
            'Disallow: /api/',
            // Filter and sort combinations would otherwise pile thousands of
            // near-identical pages into the index.
            'Disallow: /*?*sort=',
            'Disallow: /*?*seite=',
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
        foreach ([['/', '1.0'], ['/sub', '0.6'], ['/statistik', '0.6']] as [$path, $priority]) {
            $xml[] = '  <url><loc>' . e($this->app->url($path)) . '</loc>'
                . '<changefreq>weekly</changefreq><priority>' . $priority . '</priority></url>';
        }
        foreach ($statement->fetchAll() as $row) {
            $xml[] = '  <url><loc>' . e($this->app->url('/buch/' . $row['slug'])) . '</loc>'
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
            'name'             => $this->app->config->str('site_name', 'Das Regal'),
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
                'url'  => '/erfassen',
            ]],
        ])->withHeader('Content-Type', 'application/manifest+json; charset=utf-8');
    }
}
