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
     * No host but this one, for pictures either.
     *
     * There used to be a list here - Google, Open Library, the Internet
     * Archive, portal.dnb.de - because the scanner showed a found cover
     * straight from the source before anybody had decided to keep it. That is
     * the only thing that ever needed them, and it does not any more: the
     * server fetches the thumbnail and the card gets a data: URI.
     *
     * Which is worth more than tidiness. An image request carries data in its
     * address, so "any of these seven hosts" was somewhere to send it, and
     * the list had to be kept in step with CoverStorage's fetch allowlist by
     * hand. It was also not doing its job: portal.dnb.de was on it and its
     * pictures never appeared in a browser anyway, because the catalogue
     * answers browsers with a bot check.
     *
     * data: stays. That is what the thumbnails and the generated stand-ins
     * are, and it reaches nobody.
     */

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
            "img-src 'self' data:",
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
