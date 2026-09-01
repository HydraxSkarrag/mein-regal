<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Export\Exporter;
use App\Http\Application;
use App\Import\CsvReader;
use App\Import\Importer;
use Throwable;

/**
 * The jobs that would otherwise need a shell.
 *
 * The hosting this is built for has none, so anything only reachable through
 * bin/ is unreachable in practice. Not everything belongs here, though, and
 * the deciding question is how long it runs:
 *
 *   import   about 23,000 statements for three thousand books, so roughly
 *            five to twenty seconds on MySQL. Long for a web request, but it
 *            happens once in the life of an installation. Here, with the
 *            time limit raised and the whole thing in one transaction, so a
 *            timeout leaves nothing half-done.
 *   export   a handful of queries, streamed straight to the browser. Here.
 *   backup   seconds, plus zipping the covers. Here, and also on the cron.
 *   enrich   deliberately NOT here. It waits nearly a second per book to be
 *            polite to the sources it queries; for three thousand books that
 *            is hours. It belongs on the nightly cron and nowhere else.
 */
final class MaintenanceController
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

        return $this->render();
    }

    /** One-off: read a Bookstats export into an empty-ish shelf. */
    public function import(Request $request): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return $this->render(t('error.csrf'));
        }

        $upload = $request->file('csv');
        if ($upload === null || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->render(t('maintenance.import.nofile'));
        }
        $temporary = (string) ($upload['tmp_name'] ?? '');
        if ($temporary === '' || !is_uploaded_file($temporary)) {
            return $this->render(t('maintenance.import.nofile'));
        }

        $dryRun = !$request->postBool('commit');

        // Thousands of round trips take longer than a page normally may.
        @set_time_limit(600);
        ignore_user_abort(true);

        try {
            $reader = new CsvReader($temporary, $request->post('encoding') === 'utf8' ? 'UTF-8' : 'Windows-1252');
            $importer = new Importer(
                $this->app->pdo,
                $this->app->books,
                $this->app->authors,
                $this->app->tags
            );
            $report = $importer->run($reader, $this->app->ownerId, $dryRun);
        } catch (Throwable $e) {
            error_log('[regal] web import failed: ' . $e->getMessage());

            return $this->render(t('error.500.title'));
        }

        return $this->render('', $report->asText($dryRun));
    }

    /** Straight to the browser, without a copy on the server. */
    public function export(Request $request, array $params): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        $format = $params['format'] ?? 'full';
        if (!in_array($format, ['bookstats', 'full', 'json'], true)) {
            return $this->app->notFound();
        }

        $exporter = new Exporter($this->app->pdo);
        $name = sprintf('regal-%s-%s.%s', $format, date('Y-m-d'), $format === 'json' ? 'json' : 'csv');

        if ($format === 'json') {
            $body = (string) json_encode(
                $exporter->json($this->app->ownerId),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $type = 'application/json; charset=utf-8';
        } else {
            $handle = fopen('php://temp', 'r+');
            if ($handle === false) {
                return $this->app->serverError();
            }
            $format === 'bookstats'
                ? $exporter->bookstatsCsv($this->app->ownerId, $handle)
                : $exporter->fullCsv($this->app->ownerId, $handle);
            rewind($handle);
            $body = (string) stream_get_contents($handle);
            fclose($handle);
            // The Bookstats format is Latin-1 on purpose; saying so stops a
            // browser guessing UTF-8 and showing mojibake.
            $type = $format === 'bookstats'
                ? 'text/csv; charset=iso-8859-1'
                : 'text/csv; charset=utf-8';
        }

        return (new Response($body, 200, [
            'Content-Type'        => $type,
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length'      => (string) strlen($body),
        ]))->noIndex();
    }

    private function render(string $error = '', string $report = ''): Response
    {
        $body = $this->app->view->render('admin.maintenance', [
            'error'     => $error,
            'report'    => $report,
            'csrfField' => $this->app->csrf->field(),
            'bookCount' => (int) ($this->app->books->totals($this->app->ownerId)['books'] ?? 0),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => t('maintenance.title'),
            'current' => 'admin',
            'noIndex' => true,
            'narrow'  => true,
        ]), $error === '' ? 200 : 422)->noIndex();
    }
}
