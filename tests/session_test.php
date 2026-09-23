<?php
/**
 * A session only for somebody who needs one.
 *
 * It used to begin with every request, so every visitor who only read the
 * shelf got a cookie, and every page - the sitemap included - went out with
 * PHP's session headers, Cache-Control: no-store among them. Measured on
 * regal.hydrax.org: a session cookie on /, a book, /stats, /genres and
 * /sitemap.xml, for somebody who never signs in and never sends a form.
 *
 * Each case runs in a process of its own. A session is process-wide state,
 * and this runner has long since written to its output, after which PHP
 * refuses to begin one.
 */
declare(strict_types=1);

/**
 * @param array<string,string> $cookies
 * @return array<string,mixed>
 */
$sessionCase = static function (string $body, array $cookies = []): array {
    $directory = sys_get_temp_dir() . '/regal-session-' . getmypid();
    if (!is_dir($directory)) {
        mkdir($directory);
    }
    $script = $directory . '/case.php';
    file_put_contents($script, '<?php
        declare(strict_types=1);
        require ' . var_export(PROJECT_ROOT . '/app/bootstrap.php', true) . ';
        ini_set("session.save_path", ' . var_export($directory, true) . ');
        $_COOKIE = ' . var_export($cookies, true) . ';
        $session = new App\Core\Session(false);
        $out = [];
        ' . $body . '
        echo json_encode($out);
    ');
    $json = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    /** @var array<string,mixed>|null $result */
    $result = json_decode($json, true);

    return is_array($result) ? $result : ['error' => $json];
};

Assert::group('A visitor who only reads');

$reader = $sessionCase('
    $session->start();
    $out["after start"] = $session->isActive();
    $out["read"] = $session->get("user_id", "nothing");
    $session->forget("user_id");
    $out["flashes"] = $session->takeFlashes();
    $out["still"] = session_status() === PHP_SESSION_ACTIVE;
');
Assert::same('the request begins no session', $reader['after start'] ?? null, false);
Assert::same('reading from it reads nothing', $reader['read'] ?? null, 'nothing');
Assert::same('and there are no messages waiting', $reader['flashes'] ?? null, []);
Assert::same('none of that begins one either', $reader['still'] ?? null, false);

Assert::group('Somebody a session is for');

$writer = $sessionCase('
    $session->start();
    $session->set("_csrf", str_repeat("a", 64));
    $out["after set"] = session_status() === PHP_SESSION_ACTIVE;
');
Assert::same('the first thing written into it begins it', $writer['after set'] ?? null, true);

$flashed = $sessionCase('
    $session->flash("Gespeichert.");
    $out["after flash"] = session_status() === PHP_SESSION_ACTIVE;
');
Assert::same('so does a message for after the redirect', $flashed['after flash'] ?? null, true);

$returning = $sessionCase('
    $session->start();
    $out["resumed"] = session_status() === PHP_SESSION_ACTIVE;
    $out["name"] = session_name();
', ['regal_session' => 'abcdef0123456789abcdef0123456789']);
Assert::same('a visitor who brought one gets it back', $returning['resumed'] ?? null, true);
Assert::same('under the name the privacy policy gives', $returning['name'] ?? null, 'regal_session');

Assert::group('The form token, which is what begins it');

/* Every page is handed a token field, because any template may hold a form.
 * Making the token writes it into the session, so a field made up front
 * would put the cookie on every page again. */
$field = $sessionCase('
    $csrf = new App\Core\Csrf($session);
    $lazy = $csrf->lazyField();
    $out["handed over"] = session_status() === PHP_SESSION_ACTIVE;
    $html = (string) $lazy;
    $out["printed"] = session_status() === PHP_SESSION_ACTIVE;
    $out["is a field"] = str_contains($html, "name=\"_token\"");
    $out["and valid"] = $csrf->isValid(["_token" => (string) preg_replace("/.*value=\"([0-9a-f]+)\".*/", "$1", $html)]);
');
Assert::same('handing a page the field begins nothing', $field['handed over'] ?? null, false);
Assert::same('printing it does', $field['printed'] ?? null, true);
Assert::true('and it is the field', ($field['is a field'] ?? false) === true);
Assert::true('with a token the next request will accept', ($field['and valid'] ?? false) === true);

$application = (string) file_get_contents(PROJECT_ROOT . '/app/Http/Application.php');
Assert::true('the application hands out the lazy one', str_contains($application, "share('csrfField', \$this->csrf->lazyField())"));

foreach (glob(sys_get_temp_dir() . '/regal-session-' . getmypid() . '/*') ?: [] as $leftover) {
    @unlink($leftover);
}
@rmdir(sys_get_temp_dir() . '/regal-session-' . getmypid());
