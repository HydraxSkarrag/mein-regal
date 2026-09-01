<?php
declare(strict_types=1);

namespace App\Lookup;

/**
 * Small HTTP GET wrapper around curl.
 *
 * Deliberately not a library: one method, explicit timeouts, and a User-Agent
 * that says who is calling. Open Library and the DNB both ask for a contact
 * address so they can get in touch instead of silently blocking us.
 */
final class HttpClient
{
    public function __construct(
        private readonly string $contact = '',
        private readonly int $timeoutSeconds = 12,
    ) {
    }

    /** @return array{status: int, body: string, error: ?string} */
    public function get(string $url, array $headers = []): array
    {
        $agent = 'Buecherregal/1.0 (private library catalogue';
        $agent .= $this->contact !== '' ? '; ' . $this->contact . ')' : ')';

        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_USERAGENT      => $agent,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle) !== 0 ? curl_error($handle) : null;
        // No curl_close(): the handle is released when it goes out of scope,
        // and the function is deprecated as of PHP 8.5.

        return [
            'status' => $status,
            'body'   => is_string($body) ? $body : '',
            'error'  => $error,
        ];
    }

    /**
     * The same request, but a server error is given a second chance.
     *
     * Measured against Google Books with a real key: about one request in six
     * comes back 503 and succeeds when asked again a moment later. Without a
     * retry those become recorded misses, and a book is then left alone for a
     * month over a hiccup that lasted two seconds.
     *
     * Only 5xx and transport failures are retried. A 404 is an answer, and a
     * 429 means the quota is gone - asking again makes that worse.
     *
     * @param array<int, string> $headers
     * @return array{status: int, body: string, error: ?string, attempts: int}
     */
    public function getRetrying(string $url, int $attempts = 3, array $headers = []): array
    {
        $response = ['status' => 0, 'body' => '', 'error' => 'no attempt made'];

        for ($attempt = 1; $attempt <= max(1, $attempts); $attempt++) {
            $response = $this->get($url, $headers);
            $response['attempts'] = $attempt;

            $worthRetrying = $response['status'] >= 500 || $response['status'] === 0;
            if (!$worthRetrying || $attempt === $attempts) {
                return $response;
            }

            // Briefly, and a little longer each time. The sources are free and
            // hammering one that is already struggling is how access is lost.
            usleep(500000 * $attempt);
        }

        return $response;
    }
}
