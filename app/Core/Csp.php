<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The Content-Security-Policy, sent by the application rather than by Apache.
 *
 * It used to live in public/.htaccess, which was fine until the page needed
 * to carry a computed rule or two: a nonce is different on every request and
 * a static file cannot mint one. Two policies would not help either - a
 * browser enforces every one it is given, so a second header without the
 * nonce would go on blocking what the first one allows.
 *
 * The rest of the headers stayed in the .htaccess. They are the same on every
 * response and belong with the server configuration, which also keeps them in
 * force for the CSS, the fonts and the cover images, which never reach PHP.
 *
 * What the policy says, and why it can say it: the site loads nothing from
 * anywhere else. No CDN, no web fonts, no analytics. That is also what keeps
 * it free of a consent banner, so the first external script would cost twice.
 */
final class Csp
{
    /**
     * Hosts a cover may be shown from.
     *
     * Covers are downloaded and served from this server, so a visitor needs
     * none of these. The scanner does: it shows the candidate straight from
     * the source before anybody has decided to keep it. Naming them closes
     * the channel a policy like this otherwise leaves wide open - an image
     * request carries data in its address, and "any https host" is somewhere
     * to send it.
     *
     * The same list as CoverStorage's fetch allowlist, and for the same
     * reason. Open Library redirects its covers onto the Internet Archive,
     * and a browser follows that redirect too.
     *
     * @var list<string>
     */
    private const IMAGE_HOSTS = [
        'https://books.google.com',
        'https://books.googleusercontent.com',
        'https://lh3.googleusercontent.com',
        'https://covers.openlibrary.org',
        'https://archive.org',
        'https://*.archive.org',
    ];

    private string $nonce;

    public function __construct()
    {
        $this->nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function header(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "img-src 'self' data: " . implode(' ', self::IMAGE_HOSTS),
            "script-src 'self'",
            // 'self' for style.css and the themes; the nonce for the handful
            // of measurements only this request knows. See Core\Styles.
            "style-src 'self' 'nonce-" . $this->nonce . "'",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
