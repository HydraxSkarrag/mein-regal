<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Http\Application;
use App\Repository\TagRepository;

/**
 * Telling genres and labels apart.
 *
 * The import could not do this: "Fantasy", "Ab 10 Jahren", "Taschenbücher"
 * and "Custom Stores" all arrived in the same field, and no rule separates
 * them that would not also separate things it should not. It is a judgement
 * about books, so it is made by the person whose books they are - once, here,
 * and it survives every later import.
 *
 * Sorted by how many books hang on a tag, because that is the order in which
 * the work pays off: the twenty largest carry more than half of all the
 * links, and the long tail of one-book tags can be left alone entirely.
 */
final class TagController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function page(): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        return $this->render();
    }

    public function save(Request $request): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return $this->render(t('error.csrf'));
        }

        /* Every tag says what it is, including the ones left alone.
         *
         * A browser sends only ticked boxes, so each checkbox is preceded by
         * a hidden field of the same name carrying "0". An unticked box then
         * arrives as a plain no rather than as silence, and the save can
         * touch exactly what the form spoke about - which is what keeps this
         * screen safe to filter or page later. */
        $posted = $request->allPost()['genre'] ?? [];
        $genreById = [];
        if (is_array($posted)) {
            foreach ($posted as $id => $value) {
                $genreById[(int) $id] = (string) $value === '1';
            }
        }

        $genreCount = $this->app->tags->setKinds($this->app->ownerId, $genreById);

        $this->app->session->flash(t('tags.saved', ['count' => $genreCount]), 'ok');

        return Response::redirect('/admin/tags');
    }

    private function render(string $error = ''): Response
    {
        $tags = $this->app->tags->listForSorting($this->app->ownerId);
        $genres = 0;
        foreach ($tags as $tag) {
            if ($tag['kind'] === TagRepository::KIND_GENRE) {
                $genres++;
            }
        }

        $body = $this->app->view->render('admin.tags', [
            'tags'       => $tags,
            'genreCount' => $genres,
            'error'      => $error,
            'csrfField'  => $this->app->csrf->field(),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => t('tags.title'),
            'current' => 'admin',
            'noIndex' => true,
        ]))->noIndex();
    }
}
