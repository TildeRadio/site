<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\Problem;
use TildeRadio\Site\DjAuth\Failure;

define('TR_DJ_ADMIN_REQUEST', true);
require dirname(__DIR__) . '/_bootstrap.php';
$adminIdentity = tr_dj_identity($djService);
if ($adminIdentity === null) {
    tr_dj_redirect($djConfig, 'login.php');
}
$selfService = defined('TR_DJ_SELF_SERVICE') && TR_DJ_SELF_SERVICE;
if (!$selfService && !$djStore->isAdministrator($adminIdentity)) {
    tr_dj_error(403, 'Administrator access is required.');
}
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('Strict-Transport-Security: max-age=31536000');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tr_dj_require_post($djConfig);
    if (!$djLimiter->allow([
        ['id' => 'admin-write:' . $adminIdentity['station_id'] . ':' . $adminIdentity['streamer_id'], 'limit' => 60, 'seconds' => 300],
    ], time())) {
        header('Retry-After: 300');
        tr_dj_error(429, 'Too many changes. Please wait five minutes before continuing.');
    }
    // Every editor write needs a fresh bridge check; administrator routes also require the current role.
    $adminIdentity = tr_dj_identity($djService, true);
    if ($adminIdentity === null || (!$selfService && !$djStore->isAdministrator($adminIdentity))) {
        tr_dj_error(403, 'Editing access is no longer available. Sign in again.');
    }
}

function tr_admin_url(string $suffix = ''): string
{
    global $djConfig;
    if (defined('TR_DJ_SELF_SERVICE') && TR_DJ_SELF_SERVICE) {
        return $djConfig->path($suffix === '../' || str_starts_with($suffix, 'account.php') || str_starts_with($suffix, 'profiles.php') ? '' : $suffix);
    }
    return $djConfig->path('admin/' . $suffix);
}

function tr_admin_finish(string $suffix, string $message): never
{
    global $djConfig;
    $_SESSION['admin_flash'] = $message;
    try {
        if ($djConfig->carrier() !== null) {
            file_put_contents($djConfig->stateDir() . '/carrier-dirty', (string) time(), LOCK_EX);
        }
    } catch (Throwable) {
        // A failed optional integration must not fail an existing website edit.
    }
    tr_dj_redirect($djConfig, (defined('TR_DJ_SELF_SERVICE') && TR_DJ_SELF_SERVICE ? '' : 'admin/') . $suffix);
}

/** @param callable():void $action */
function tr_admin_attempt(callable $action): ?string
{
    try {
        $action();
    } catch (Problem $problem) {
        http_response_code($problem->status);

        return $problem->getMessage();
    } catch (Failure $failure) {
        http_response_code($failure->reason === 'invalid_credentials' ? 422 : 503);

        return $failure->reason === 'invalid_credentials'
            ? 'This DJ was not found as an active account in the configured authentication station.'
            : 'The authentication bridge could not verify this account. Try again shortly.';
    } catch (Throwable) {
        http_response_code(503);
        error_log('{"channel":"dj-admin","level":"error","message":"Administrator request failed"}');

        return 'This change could not be completed. Reload the page and check its current state before trying again.';
    }

    return null;
}

function tr_admin_begin(string $heading, string $section, ?string $error = null): void
{
    $self = defined('TR_DJ_SELF_SERVICE') && TR_DJ_SELF_SERVICE;
    $title = $self ? 'DJ booth' : 'Administration';
    $page_stylesheets = ['css/dj-auth.css', 'css/dj-admin.css'];
    require dirname(__DIR__, 2) . '/header.php';
    echo '<section class="tr-section dj-admin' . ($self ? ' dj-editor' : '') . '"><div class="dj-admin-heading"><div><h1>' . tr_dj_h($heading) . '</h1></div><a href="' . tr_dj_h(tr_admin_url('../')) . '">Back to DJ booth</a></div>';
    echo '<nav class="dj-admin-nav" aria-label="' . ($self ? 'DJ controls' : 'Administration') . '">';
    $navigation = $self
        ? ['overview' => ['Booth', ''], 'profiles' => ['My profile', 'profile.php'], 'broadcasts' => ['Sets / broadcasts', 'broadcasts.php'], 'accounts' => ['My schedules', '#dj-booth-schedules']]
        : ['overview' => ['Overview', ''], 'accounts' => ['DJs', 'accounts.php'], 'profiles' => ['Profiles', 'profiles.php'], 'stations' => ['Stations', 'stations.php'], 'broadcasts' => ['Sets / broadcasts', '../broadcasts.php'], 'audit' => ['Activity', 'audit.php']];
    global $djConfig;
    if ($djConfig->carrier() !== null) {
        $navigation += $self ? ['plans' => ['Prepare a show', 'plans.php'], 'carrier' => ['Live / IRC', 'carrier.php'], 'recordings' => ['Recordings', 'recordings.php']]
            : ['carrier' => ['Carrier integration', 'carrier.php']];
    }
    foreach ($navigation as $key => [$label, $path]) {
        echo '<a href="' . tr_dj_h($self && str_starts_with($path, '#') ? tr_admin_url('') . $path : tr_admin_url($path)) . '"' . ($section === $key ? ' aria-current="page"' : '') . '>' . tr_dj_h($label) . '</a>';
    }
    echo '</nav>';
    $helpTopic = $self ? (['profiles' => 'profile', 'broadcasts' => 'broadcasts', 'accounts' => 'booking', 'plans' => 'prepared-shows', 'carrier' => 'live-controls', 'recordings' => 'recordings'][$section] ?? 'website-map')
        : ($section === 'carrier' ? 'integration-status' : 'administration');
    echo '<p class="dj-auth-help"><a href="' . tr_dj_h(asset('help/?topic=' . $helpTopic)) . '">Help</a> &middot; <a href="' . tr_dj_h(asset('help/')) . '">All guides</a></p>';
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    if (is_string($flash)) {
        echo '<p class="dj-admin-notice" role="status">' . tr_dj_h($flash) . '</p>';
    }
    if ($error !== null) {
        echo '<p class="dj-auth-error" role="alert" tabindex="-1" data-dj-auth-alert>' . tr_dj_h($error) . '</p>';
    }
}

function tr_admin_end(): void
{
    echo '</section>';
    require dirname(__DIR__, 2) . '/footer.php';
}

function tr_admin_csrf(): void
{
    echo '<input type="hidden" name="csrf" value="' . tr_dj_h($_SESSION['csrf']) . '">';
}

function tr_admin_version(int $current): int
{
    // Keep a rejected form's expected version. A second click must not rebase stale input.
    $posted = $_POST['version'] ?? null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_string($posted) && preg_match('/^[0-9]{1,10}$/D', $posted)) {
        return (int) $posted;
    }

    return $current;
}

function tr_admin_field(string $name, string $label, string $value = '', string $type = 'text', bool $required = false, string $idSuffix = ''): void
{
    $id = 'admin-' . $name . $idSuffix;
    echo '<div class="dj-admin-control"><label for="' . tr_dj_h($id) . '">' . tr_dj_h($label) . '</label><input id="' . tr_dj_h($id) . '" name="' . tr_dj_h($name) . '" type="' . tr_dj_h($type) . '" value="' . tr_dj_h($value) . '"' . ($required ? ' required' : '') . '></div>';
}

function tr_admin_area(string $name, string $label, string $value = '', int $rows = 5): void
{
    echo '<div class="dj-admin-control dj-admin-control-wide"><label for="admin-' . tr_dj_h($name) . '">' . tr_dj_h($label) . '</label><textarea id="admin-' . tr_dj_h($name) . '" name="' . tr_dj_h($name) . '" rows="' . $rows . '">' . tr_dj_h($value) . '</textarea></div>';
}
