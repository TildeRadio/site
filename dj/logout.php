<?php

declare(strict_types=1);

use TildeRadio\Site\DjAuth\Session;

require __DIR__ . '/_bootstrap.php';
tr_dj_require_post($djConfig);
try {
    Session::logout($djConfig);
    $djLogger->info('DJ logout succeeded');
} catch (Throwable) {
    tr_dj_error(503, 'Sign-out could not be completed. Please try again.');
}
tr_dj_redirect($djConfig, 'login.php');
