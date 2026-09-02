<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Http\Application;
use App\Repository\BookRepository;
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
            'tags'        => $tags,
            'genreCount'  => $genres,
            'fieldValues' => $this->fieldValuePairs(),
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

    /** GET - what removing this tag would do, before it does it. */
    public function confirmRemove(Request $request, array $params): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }
        $tag = $this->app->tags->find($this->app->ownerId, (int) ($params['id'] ?? 0));
        if ($tag === null) {
            return $this->app->notFound();
        }

        return $this->confirm(
            t('tags.remove.title', ['name' => $tag['name']]),
            t('tags.remove.warning', ['count' => (int) $tag['book_count']]),
            [t('tags.remove.reversible'), t('tags.remove.imports')],
            '/admin/tags/' . (int) $tag['id'] . '/remove',
            t('tags.remove.do')
        );
    }

    public function remove(Request $request, array $params): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard !== null) {
            return $guard;
        }
        $tag = $this->app->tags->find($this->app->ownerId, (int) ($params['id'] ?? 0));
        if ($tag === null) {
            return $this->app->notFound();
        }

        $this->app->tags->drop($this->app->ownerId, (int) $tag['id']);
        $this->app->session->flash(t('tags.removed', ['name' => $tag['name']]), 'ok');

        return Response::redirect('/admin/tags');
    }

    public function restore(Request $request, array $params): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard !== null) {
            return $guard;
        }
        $tag = $this->app->tags->find($this->app->ownerId, (int) ($params['id'] ?? 0));
        if ($tag === null) {
            return $this->app->notFound();
        }

        $this->app->tags->restore($this->app->ownerId, (int) $tag['id']);
        $this->app->session->flash(t('tags.restored', ['name' => $tag['name']]), 'ok');

        return Response::redirect('/admin/tags');
    }

    /** GET - which books would gain which tag, before anything moves. */
    public function confirmMerge(Request $request): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }
        [$from, $into] = $this->mergePair($request->queryInt('from'), $request->queryInt('into'));
        if ($from === null || $into === null) {
            $this->app->session->flash(t('tags.merge.pick'), 'error');

            return Response::redirect('/admin/tags');
        }

        $shared = 0;
        foreach ($this->app->tags->bookIdsFor($this->app->ownerId, (int) $from['id']) as $bookId) {
            if (in_array($bookId, $this->app->tags->bookIdsFor($this->app->ownerId, (int) $into['id']), true)) {
                $shared++;
            }
        }
        $gaining = (int) $from['book_count'] - $shared;

        return $this->confirm(
            t('tags.merge.title', ['from' => $from['name'], 'into' => $into['name']]),
            t('tags.merge.warning', ['count' => $gaining, 'into' => $into['name'], 'from' => $from['name']]),
            [t('tags.merge.kept', ['from' => $from['name']]), t('tags.remove.reversible')],
            '/admin/tags/merge',
            t('tags.merge.do'),
            ['from' => (string) $from['id'], 'into' => (string) $into['id']]
        );
    }

    public function merge(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard !== null) {
            return $guard;
        }
        [$from, $into] = $this->mergePair((int) $request->post('from'), (int) $request->post('into'));
        if ($from === null || $into === null) {
            $this->app->session->flash(t('tags.merge.pick'), 'error');

            return Response::redirect('/admin/tags');
        }

        $result = $this->app->tags->merge($this->app->ownerId, (int) $from['id'], (int) $into['id']);
        $this->app->session->flash(t('tags.merged', [
            'count' => $result['moved'],
            'into'  => $into['name'],
            'from'  => $from['name'],
        ]), 'ok');

        return Response::redirect('/admin/tags');
    }

    /** GET - how many books a tag could fill a field for, and where it clashes. */
    public function confirmField(Request $request): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }
        $tag = $this->app->tags->find($this->app->ownerId, $request->queryInt('tag'));
        // One dropdown for field and value together: "binding:paperback" is
        // one decision, and two selects that have to agree are two chances
        // to pick a value the field cannot hold.
        [$field, $value] = array_pad(explode(':', (string) $request->query('pair'), 2), 2, '');
        if ($tag === null || !$this->fieldValueAllowed($field, $value)) {
            return $this->app->notFound();
        }

        /* A dry run over the same code path that would write, so the numbers
         * on the confirmation are the numbers that will happen - not an
         * estimate made by a second query that could drift from the first. */
        $books = $this->app->tags->bookIdsFor($this->app->ownerId, (int) $tag['id']);
        $preview = $this->app->books->fillFieldFor($this->app->ownerId, $books, $field, $value, true);

        return $this->confirm(
            t('tags.field.title', ['name' => $tag['name']]),
            t('tags.field.warning', [
                'filled' => $preview['filled'],
                'field'  => t('book.' . $field),
                'value'  => $this->valueLabel($field, $value),
            ]),
            array_filter([
                $preview['already'] > 0 ? t('tags.field.already', ['count' => $preview['already']]) : '',
                $preview['conflicting'] > 0 ? t('tags.field.conflicting', ['count' => $preview['conflicting']]) : '',
                t('tags.field.then'),
            ]),
            '/admin/tags/' . (int) $tag['id'] . '/field',
            t('tags.field.do'),
            ['field' => $field, 'value' => $value]
        );
    }

    public function fillField(Request $request, array $params): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard !== null) {
            return $guard;
        }
        $tag = $this->app->tags->find($this->app->ownerId, (int) ($params['id'] ?? 0));
        $field = (string) $request->post('field');
        $value = (string) $request->post('value');
        if ($tag === null || !$this->fieldValueAllowed($field, $value)) {
            return $this->app->notFound();
        }

        $books = $this->app->tags->bookIdsFor($this->app->ownerId, (int) $tag['id']);
        $result = $this->app->books->fillFieldFor($this->app->ownerId, $books, $field, $value);
        $this->app->tags->drop($this->app->ownerId, (int) $tag['id']);

        $this->app->session->flash(t('tags.field.done', [
            'filled'      => $result['filled'],
            'field'       => t('book.' . $field),
            'conflicting' => $result['conflicting'],
        ]), 'ok');

        return Response::redirect('/admin/tags');
    }

    /**
     * Both tags of a merge, or nulls.
     *
     * @return array{0: ?array<string,mixed>, 1: ?array<string,mixed>}
     */
    private function mergePair(int $fromId, int $intoId): array
    {
        if ($fromId <= 0 || $intoId <= 0 || $fromId === $intoId) {
            return [null, null];
        }

        return [
            $this->app->tags->find($this->app->ownerId, $fromId),
            $this->app->tags->find($this->app->ownerId, $intoId),
        ];
    }

    /**
     * Every field-and-value a tag could be folded into, as one list.
     *
     * @return list<array{field: string, value: string, label: string}>
     */
    private function fieldValuePairs(): array
    {
        $pairs = [];
        foreach (BookRepository::FILLABLE_FROM_TAG as $field) {
            foreach ($this->valuesFor($field) as $value) {
                $pairs[] = [
                    'field' => $field,
                    'value' => $value,
                    'label' => t('book.' . $field) . ': ' . $this->valueLabel($field, $value),
                ];
            }
        }

        return $pairs;
    }

    /**
     * Is this a field that may be filled from a tag, with a value it may hold?
     *
     * The value comes from a dropdown, but a dropdown is a suggestion to a
     * browser and not a promise to the server. Both are checked against the
     * same vocabulary the book editor uses, so nothing new can enter a field
     * this way.
     */
    private function fieldValueAllowed(string $field, string $value): bool
    {
        return in_array($value, $this->valuesFor($field), true);
    }

    /** @return list<string> */
    private function valuesFor(string $field): array
    {
        if ($field === 'binding') {
            return ['hardcover', 'paperback', 'ebook', 'audiobook'];
        }
        if ($field === 'language') {
            // Whatever the shelf already holds - never an invented code.
            return array_values(array_filter(
                array_map('strval', array_keys($this->app->books->countBy($this->app->ownerId, 'language'))),
                static fn (string $code): bool => $code !== ''
            ));
        }

        return [];
    }

    private function valueLabel(string $field, string $value): string
    {
        return $field === 'language'
            ? \App\Core\Formatter::language($value)
            : t('binding.' . $value);
    }

    private function guardWrite(Request $request): ?Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return $this->render(t('error.csrf'));
        }

        return null;
    }

    /**
     * The one page that stands between an irreversible-looking action and
     * doing it: what will happen, in numbers, and a single button.
     *
     * @param list<string>          $notes
     * @param array<string, string> $hidden
     */
    private function confirm(
        string $heading,
        string $warning,
        array $notes,
        string $action,
        string $button,
        array $hidden = []
    ): Response {
        $body = $this->app->view->render('admin.tag_confirm', [
            'heading'   => $heading,
            'warning'   => $warning,
            'notes'     => array_values(array_filter($notes)),
            'action'    => $action,
            'button'    => $button,
            'hidden'    => $hidden,
            'csrfField' => $this->app->csrf->field(),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => $heading,
            'current' => 'admin',
            'noIndex' => true,
        ]))->noIndex();
    }
}
