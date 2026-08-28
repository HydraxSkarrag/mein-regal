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
}
