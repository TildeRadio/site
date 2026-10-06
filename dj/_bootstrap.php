<?php

declare(strict_types=1);

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use TildeRadio\Site\Admin\Store;
use TildeRadio\Site\DjAuth\AzuraCastTransport;
use TildeRadio\Site\DjAuth\Config;
use TildeRadio\Site\DjAuth\Csrf;
use TildeRadio\Site\DjAuth\Failure;
use TildeRadio\Site\DjAuth\RateLimiter;
use TildeRadio\Site\DjAuth\Service;
use TildeRadio\Site\DjAuth\Session;

ini_set('display_errors', '0');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
header('Content-Type: text/html; charset=UTF-8');

function tr_dj_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tr_dj_error(int $status, string $message): never
{
    http_response_code($status);
    $title = 'DJ booth';
    require dirname(__DIR__) . '/header.php';
    echo '<section class="tr-section"><h1 class="tr-title">DJ booth</h1><p role="alert">' . tr_dj_h($message) . '</p></section>';
    require dirname(__DIR__) . '/footer.php';
    exit;
}

function tr_dj_redirect(Config $config, string $suffix = ''): never
{
    header('Location: ' . $config->origin() . $config->path($suffix), true, 303);
    exit;
}

function tr_dj_require_post(Config $config): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        tr_dj_error(405, 'Use the form button to submit this request.');
    }
    // Origin is supplementary; the unpredictable session-bound token is always required.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if (($origin !== null && $origin !== $config->origin())
        || !Csrf::valid($_SESSION['csrf'] ?? null, $_POST['csrf'] ?? null)) {
        tr_dj_error(403, 'This form has expired. Reload the page and try again.');
    }
}

try {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Dependencies unavailable.');
    }
    require_once $autoload;
    $djConfig = Config::load();
    if (!$djConfig->secure($_SERVER)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            tr_dj_redirect($djConfig, 'login.php');
        }
        tr_dj_error(403, 'Use the secure HTTPS website to sign in.');
    }
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        tr_dj_error(405, 'This request method is not supported.');
    }
    $bodyLimit = defined('TR_DJ_PLAN_REQUEST') ? 1048576 : (defined('TR_DJ_ADMIN_REQUEST') ? 262144 : 4096);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $bodyLimit) {
        tr_dj_error(413, 'This form is too large.');
    }
    $djIp = $djConfig->userIp($_SERVER);
    umask(0077);
    $handler = new RotatingFileHandler($djConfig->stateDir() . '/audit.log', 7, Logger::INFO);
    $handler->setFormatter(new JsonFormatter());
    $djLogger = new Logger('dj-auth', [$handler]);
    $djLimiter = new RateLimiter($djConfig->stateDir());
    if (!$djLimiter->allow([
        ['id' => 'page-ip:' . bin2hex((string) inet_pton($djIp)), 'limit' => 120, 'seconds' => 300],
        ['id' => 'page-total', 'limit' => 600, 'seconds' => 60],
    ], time())) {
        header('Retry-After: 300');
        tr_dj_error(429, 'Too many requests. Please try again in five minutes.');
    }
    Session::start($djConfig);
    $djTransport = new AzuraCastTransport($djConfig);
    $djService = new Service($djTransport, $djLimiter, $djLogger);
    $djStore = new Store($djConfig, $djLogger, dirname(__DIR__));
} catch (Throwable) {
    // Never print configuration, credentials, transport exceptions, or stack traces.
    error_log('{"channel":"dj-auth","level":"error","message":"DJ login initialization failed"}');
    tr_dj_error(503, 'DJ login is temporarily unavailable. Please try again later.');
}

function tr_dj_identity(Service $service, bool $forceRecheck = false): ?array
{
    global $djStore, $djAccount;
    try {
        $identity = $service->identity(time(), $forceRecheck);
        if ($identity !== null) {
            $djAccount = $djStore->observe($identity);
            if (!$djAccount['enabled'] || $djAccount['deleted']) {
                Session::forgetIdentity();
                tr_dj_error(403, 'Your website access is disabled. Contact the station administrator.');
            }
        }

        return $identity;
    } catch (Throwable) {
        tr_dj_error(503, 'Your account could not be verified. Please try again shortly.');
    }
}
