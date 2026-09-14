<?php
declare(strict_types=1);

namespace App\Controller;

use App\Content\TagAssignment;
use App\Content\TagNotation;
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

    public function page(Request $request): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        return $this->render('', $request->queryInt('removed'));
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

    private function render(string $error = '', int $removedId = 0): Response
    {
        $tags = $this->app->tags->listForSorting($this->app->ownerId);

        $body = $this->app->view->render('admin.tags', [
            'tags'        => $tags,
            'countLine'   => $this->countLine($tags),
            'removedId'   => $removedId,
            'fieldValues' => $this->fieldValuePairs(),
            /* Only counted here, and only shown when it is not zero: on a
               tidy shelf this section should not exist. */
            'notation'    => TagNotation::changes(
                TagNotation::plan($this->app->tags, $this->app->ownerId)
            ),
            'error'      => $error,
            'csrfField'  => $this->app->csrf->field(),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => t('tags.title'),
            'current' => 'admin',
            'noIndex' => true,
            'scripts' => ['/js/tag-admin.js'],
        ]))->noIndex();
    }

    /**
     * "12 of 380 are genres", counting only the tags in use.
     *
     * The genres used to be counted over every tag, removed ones included,
     * and the total over the ones in use - so removing a genre left the first
     * number where it was and took one off the second. Both halves come from
     * the same list now, and from one place, because the page and a script
     * that has just removed a tag both need the line.
     *
     * @param list<array{kind: string, dropped_at: ?string}> $tags
     */
    private function countLine(array $tags): string
    {
        $genres = 0;
        $total = 0;
        foreach ($tags as $tag) {
            if ($tag['dropped_at'] !== null) {
                continue;
            }
            $total++;
            if ($tag['kind'] === TagRepository::KIND_GENRE) {
                $genres++;
            }
        }

        return t('tags.count', [
            'genres' => $this->app->formatter->number($genres),
            'total'  => $this->app->formatter->number($total),
        ]);
    }

    /**
     * GET - every tag name a catalogue notation got into, before touching one.
     *
     * This exists as a button rather than only as bin/tags.php because the
     * host has no shell. Four of the nine entries on the shelf this was
     * reported on cannot be fixed by hand at all: merging needs a tag under
     * the corrected name to merge into, and there was none - "46 Bildende
     * Kunst" had no plain twin, and nothing here can rename.
     */
    public function confirmTidy(): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        $plan = TagNotation::plan($this->app->tags, $this->app->ownerId);
        if (TagNotation::changes($plan) === 0) {
            $this->app->session->flash(t('tags.notation.none'), 'ok');

            return Response::redirect('/admin/tags');
        }

        /* Each change on its own line. There is no summarising this into a
           number: which name becomes which is the entire question, and a
           count of seven would be asking for a yes to something unread. */
        $lines = [];
        foreach ($plan['renames'] as $item) {
            $lines[] = t('tags.notation.rename', [
                'from' => $item['tag']['name'],
                'to'   => $item['clean'],
            ]);
        }
        foreach ($plan['merges'] as $item) {
            $lines[] = t('tags.notation.merge', [
                'from'  => $item['from']['name'],
                'into'  => $item['into']['name'],
                'count' => $item['from']['book_count'],
            ]);
        }
        $lines[] = t('tags.remove.reversible');

        return $this->confirm(
            t('tags.notation.title'),
            t('tags.notation.warning', ['count' => TagNotation::changes($plan)]),
            $lines,
            '/admin/tags/tidy',
            t('tags.notation.do')
        );
    }

    /** POST - take the numbers out of the names. */
    public function tidy(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard !== null) {
            return $guard;
        }

        /* Planned again rather than carried through the form. The list on the
           confirmation page is what the reader agreed to, but it is a report,
           not an instruction: re-reading the shelf is both simpler and safer
           than trusting a page that may have been open since yesterday. */
        $plan = TagNotation::plan($this->app->tags, $this->app->ownerId);
        $done = TagNotation::apply($this->app->tags, $this->app->ownerId, $plan);

        $this->app->session->flash(t('tags.notation.done', [
            'renamed' => $done['renamed'],
            'merged'  => $done['merged'],
        ]), 'ok');

        return Response::redirect('/admin/tags');
    }

    /** GET - what removing this tag would do, before it does it. */
    /**
     * Remove a tag at once, with no page asking first.
     *
     * There was one, and it guarded nothing. Removing only hides a tag -
     * every link is kept and it can be put back - and the one thing the page
     * said, how many books carry it, stands beside the × already. What it
     * cost was two page loads per tag and a landing at the top of a list of
     * three hundred and eighty, on a screen whose job is clearing out a run
     * of entries an import left behind.
     *
     * The safety moved from before to after: the row turns into "removed"
     * with a button that takes it back, where the × was. Deleting for good
     * still asks, because that one cannot be taken back.
     */
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

        return $this->answerWithRow($request, (int) $tag['id'], '?removed=' . (int) $tag['id']);
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

        return $this->answerWithRow($request, (int) $tag['id']);
    }

    /**
     * POST - a file of genres and labels per book, read and planned, with
     * nothing written yet.
     *
     * The preview is the point. The file replaces what a whole shelf carries,
     * and the only honest way to hand that over is to show every book whose
     * tags change, every name that is new, and every tag that goes, before a
     * button does it.
     */
    public function previewAssignment(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard !== null) {
            return $guard;
        }

        $upload = $request->file('csv');
        $temporary = (string) ($upload['tmp_name'] ?? '');
        if ($upload === null || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || $temporary === '' || !is_uploaded_file($temporary)) {
            return $this->render(t('tags.assign.nofile'));
        }

        return $this->assignmentPage((string) file_get_contents($temporary));
    }

    /**
     * POST - carry out the plan the preview showed, if it is still that plan.
     *
     * The file comes back with the form rather than being kept on the server:
     * there is nowhere on this host a stray upload would be cleaned up from,
     * and planning again is cheap. What is compared is the plan, not the
     * file - a book edited in another tab since the preview changes what the
     * button would do, and then the preview is shown again instead.
     */
    public function applyAssignment(Request $request): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard !== null) {
            return $guard;
        }

        $contents = (string) ($request->allPost()['csv'] ?? '');
        $read = TagAssignment::read($contents);
        if ($read['error'] !== null) {
            return $this->render(t($read['error']));
        }
        $plan = TagAssignment::plan($this->app->books, $this->app->tags, $this->app->ownerId, $read['rows']);
        if (!hash_equals(TagAssignment::fingerprint($plan), $request->post('fingerprint'))) {
            return $this->assignmentPage($contents, t('tags.assign.moved'));
        }

        @set_time_limit(300);
        $result = TagAssignment::apply($this->app->tags, $this->app->ownerId, $plan);
        $this->app->session->flash(t('tags.assign.done', [
            'books'   => $this->app->formatter->number($result['books']),
            'created' => $this->app->formatter->number($result['created']),
            'dropped' => $this->app->formatter->number($result['dropped']),
        ]), 'ok');

        return Response::redirect('/admin/tags');
    }

    private function assignmentPage(string $contents, string $notice = ''): Response
    {
        $contents = TagAssignment::normalize($contents);
        $read = TagAssignment::read($contents);
        if ($read['error'] !== null) {
            return $this->render(t($read['error']));
        }
        $plan = TagAssignment::plan($this->app->books, $this->app->tags, $this->app->ownerId, $read['rows']);

        $body = $this->app->view->render('admin.tag_assign', [
            'plan'        => $plan,
            'canApply'    => TagAssignment::canApply($plan),
            'fingerprint' => TagAssignment::fingerprint($plan),
            'contents'    => $contents,
            'notice'      => $notice,
            'csrfField'   => $this->app->csrf->field(),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => t('tags.assign.title'),
            'current' => 'admin',
            'noIndex' => true,
        ]))->noIndex();
    }

    /**
     * What removing and restoring answer: the row in its new state and the
     * new count line for a script, or the list for a browser, scrolled to
     * that row.
     *
     * A browser used to land at the top with a message there, which is the
     * one place on this screen nobody working down the list is looking. The
     * row is the message now. Without a script the address says which tag was
     * just removed, so the list can draw the row that takes it back.
     */
    private function answerWithRow(Request $request, int $tagId, string $query = ''): Response
    {
        if ($request->wantsJson()) {
            $tag = $this->app->tags->find($this->app->ownerId, $tagId);

            return Response::json([
                'row'   => $tag === null ? '' : $this->app->view->render('partials.tag_row', ['tag' => $tag]),
                'count' => $this->countLine($this->app->tags->listForSorting($this->app->ownerId)),
            ]);
        }

        return Response::redirect('/admin/tags' . $query . '#tag-row-' . $tagId);
    }

    /** GET - the one action here that cannot be taken back. */
    public function confirmPurge(Request $request, array $params): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }
        $tag = $this->app->tags->find($this->app->ownerId, (int) ($params['id'] ?? 0));
        if ($tag === null || $tag['dropped_at'] === null) {
            return $this->app->notFound();
        }

        return $this->confirm(
            t('tags.purge.title', ['name' => $tag['name']]),
            t('tags.purge.warning', ['count' => (int) $tag['book_count']]),
            [t('tags.purge.final'), t('tags.purge.imports')],
            '/admin/tags/' . (int) $tag['id'] . '/purge',
            t('tags.purge.do')
        );
    }

    public function purge(Request $request, array $params): Response
    {
        $guard = $this->guardWrite($request);
        if ($guard !== null) {
            return $guard;
        }
        $tag = $this->app->tags->find($this->app->ownerId, (int) ($params['id'] ?? 0));
        if ($tag === null || $tag['dropped_at'] === null) {
            return $this->app->notFound();
        }

        $this->app->tags->purge($this->app->ownerId, (int) $tag['id']);
        $this->app->session->flash(t('tags.purged', ['name' => $tag['name']]), 'ok');

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
            [
                t('tags.merge.kept', ['from' => $from['name']]),
                t('tags.merge.forward', ['from' => $from['name'], 'into' => $into['name']]),
                t('tags.remove.reversible'),
            ],
            '/admin/tags/merge',
            t('tags.merge.do'),
            ['from' => (string) $from['id'], 'into' => (string) $into['id']],
            (string) $from['slug']
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
            ['field' => $field, 'value' => $value],
            (string) $tag['slug']
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
