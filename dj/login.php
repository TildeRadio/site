<?php

declare(strict_types=1);

use TildeRadio\Site\DjAuth\Failure;
use TildeRadio\Site\DjAuth\Session;

require __DIR__ . '/_bootstrap.php';

if (tr_dj_identity($djService) !== null) {
    tr_dj_redirect($djConfig);
}

$error = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tr_dj_require_post($djConfig);
    $username = is_string($_POST['username'] ?? null) ? substr($_POST['username'], 0, 50) : '';
    try {
        $identity = $djService->login($_POST, $djIp, time());
        unset($_POST['password']);
        $account = $djStore->observe($identity);
        if (!$account['enabled'] || $account['deleted']) {
            throw new Failure('website_disabled');
        }
        Session::login($identity, time());
        tr_dj_redirect($djConfig);
    } catch (Throwable $failure) {
        unset($_POST['password']);
        $reason = $failure instanceof Failure ? $failure->reason : 'unavailable';
        [$status, $error] = match ($reason) {
            'invalid_credentials' => [401, 'The DJ username or password was not accepted.'],
            'website_disabled' => [403, 'Your website access is disabled. Contact the station administrator.'],
            'invalid_input' => [400, 'Enter a valid DJ username and password.'],
            'csrf' => [403, 'This form has expired. Reload the page and try again.'],
            'rate_limited' => [429, 'Too many login attempts. Please try again in five minutes.'],
            default => [503, 'DJ login is temporarily unavailable. Please try again later.'],
        };
        http_response_code($status);
        if ($status === 429) {
            header('Retry-After: 300');
        }
    }
}

$title = 'DJ login';
$page_stylesheets = ['css/dj-auth.css'];
require dirname(__DIR__) . '/header.php';
?>
<section class="tr-section dj-auth" aria-labelledby="dj-login-title">
    <div class="dj-auth-heading"><span class="tr-badge">DJ booth</span><h1 class="tr-title" id="dj-login-title">Welcome back.</h1></div>
    <p class="dj-auth-intro">Sign in with your TildeRadio DJ streaming account.</p>
    <?php if ($error !== null) : ?>
        <p class="dj-auth-error" role="alert" tabindex="-1" data-dj-auth-alert><?= tr_dj_h($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= tr_dj_h($djConfig->path('login.php')) ?>" class="dj-auth-form" data-tr-dj-auth>
        <input type="hidden" name="csrf" value="<?= tr_dj_h($_SESSION['csrf']) ?>">
        <label for="dj-username">DJ username</label>
        <input id="dj-username" name="username" type="text" value="<?= tr_dj_h($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="50" required>
        <label for="dj-password">Password</label>
        <input id="dj-password" name="password" type="password" autocomplete="current-password" maxlength="1024" required>
        <button type="submit" class="dj-auth-button">Sign in</button>
    </form>
    <p class="dj-auth-help">Need an account or a password reset? Contact the station administrator.</p>
</section>
<?php require dirname(__DIR__) . '/footer.php'; ?>
