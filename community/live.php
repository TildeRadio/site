<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\CarrierClient;
use TildeRadio\Site\Admin\Input;
use TildeRadio\Site\Admin\Problem;

define('TR_DJ_ADMIN_REQUEST', true);
require dirname(__DIR__) . '/dj/_bootstrap.php';
$error = null;
$notice = null;
$activity = [];
try {
    $client = new CarrierClient($djConfig);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        tr_dj_require_post($djConfig);
        if (!$djLimiter->allow([
            ['id' => 'listener-write-ip:' . $djIp, 'limit' => 8, 'seconds' => 60],
            ['id' => 'listener-write-total', 'limit' => 120, 'seconds' => 60],
        ], time())) {
            throw new Problem('Please wait before submitting more listener activity.', 429);
        }
        $id = Input::text($_POST['action_id'] ?? '', 'action identifier', 32, true);
        $bound = $_SESSION['listener_forms'][$id] ?? null;
        if (!is_array($bound) || $bound['issued'] < time() - 900) {
            throw new Problem('This listener form expired. Reload it.', 409);
        }
        $_SESSION['listener_identity'] ??= bin2hex(random_bytes(32));
        $result = $client->call(['op' => 'listener', 'request_id' => $id,
            'actor' => 'listener:' . hash('sha256', $_SESSION['listener_identity']),
            'session_id' => $bound['session_id'], 'poll_id' => $bound['poll_id'],
            'action' => Input::text($_POST['action'] ?? '', 'listener action', 20, true),
            'value' => Input::text($_POST['value'] ?? '', 'message', 240),
            'option' => isset($_POST['option']) ? Input::integer($_POST['option'], 'poll option') : null]);
        $_SESSION['listener_notice'] = $result['message'] ?? 'Submitted.';
        header('Location: ' . $djConfig->origin() . $djConfig->publicPath('community/live.php'), true, 303);
        exit;
    }
    $activity = $client->call(['op' => 'public']);
} catch (Problem $problem) {
    $error = $problem->getMessage();
    http_response_code($problem->status);
} catch (Throwable) {
    $error = 'Live listener activity is temporarily unavailable.';
    http_response_code(503);
}
$notice = $_SESSION['listener_notice'] ?? null;
unset($_SESSION['listener_notice']);
$_SESSION['listener_forms'] ??= [];
foreach ($_SESSION['listener_forms'] as $key => $form) {
    if ($form['issued'] < time() - 900) {
        unset($_SESSION['listener_forms'][$key]);
    }
}
function tr_listener_token(array $activity): void
{
    if (count($_SESSION['listener_forms']) > 40) {
        array_shift($_SESSION['listener_forms']);
    }
    $id = bin2hex(random_bytes(16));
    $_SESSION['listener_forms'][$id] = ['session_id' => $activity['session_id'], 'poll_id' => $activity['poll']['id'] ?? null, 'issued' => time()];
    echo '<input type="hidden" name="csrf" value="' . tr_dj_h($_SESSION['csrf']) . '"><input type="hidden" name="action_id" value="' . tr_dj_h($id) . '">';
}
$title = 'Live listener activity';
$page_stylesheets = ['css/dj-auth.css', 'css/dj-admin.css'];
require dirname(__DIR__) . '/header.php';
?>
<section class="tr-section dj-admin dj-editor"><h1>Live listener activity</h1>
<p><a href="<?= tr_dj_h(asset('community/live.php')) ?>">Refresh activity</a> · <a href="<?= tr_dj_h(asset('community/upcoming.php')) ?>">Upcoming show previews</a> · <a href="<?= tr_dj_h(asset('api/podcast/')) ?>">Recording podcast feed</a></p>
<?php if ($error !== null) : ?><p class="dj-auth-error" role="alert" tabindex="-1" data-dj-auth-alert><?= tr_dj_h($error) ?></p><?php endif; ?>
<?php if (is_string($notice)) : ?><p class="dj-admin-notice" role="status"><?= tr_dj_h($notice) ?></p><?php endif; ?>
<?php if (!empty($activity['is_live'])) : ?>
<h2><?= tr_dj_h((string) $activity['dj']) ?></h2><p><?= tr_dj_h((string) ($activity['show']['episode'] ?? $activity['show']['topic'] ?? '')) ?></p><p><?= (int) ($activity['listeners'] ?? 0) ?> listeners</p>
<?php if ($activity['current_track'] !== null) : ?><p>Playing: <?= tr_dj_h($activity['current_track']['artist'] . ' — ' . $activity['current_track']['title']) ?></p><?php endif; ?>
<p>Questions and requests go to the DJ’s private queue. Only questions marked answered are displayed here. Listener names and IP addresses are not published.</p>
<?php foreach (['question' => 'Ask the DJ a question', 'request' => 'Request a song'] as $action => $label) : if ($action === 'request' && !$activity['requests_enabled']) { continue; } ?>
<form method="post" data-tr-dj-auth class="dj-admin-form"><?php tr_listener_token($activity); ?><input type="hidden" name="action" value="<?= tr_dj_h($action) ?>"><div class="dj-admin-control"><label for="listener-<?= tr_dj_h($action) ?>"><?= tr_dj_h($label) ?></label><input id="listener-<?= tr_dj_h($action) ?>" type="text" name="value" maxlength="240" required></div><button class="dj-auth-button" type="submit">Submit <?= tr_dj_h($action) ?></button></form>
<?php endforeach; ?>
<form method="post" data-tr-dj-auth class="dj-admin-form"><?php tr_listener_token($activity); ?><input type="hidden" name="action" value="reaction"><div class="dj-admin-control"><label for="listener-reaction">Reaction</label><select id="listener-reaction" name="value"><?php foreach (['fire', 'love', 'bass', 'lol', 'wtf', 'banger', 'weird', 'questionable'] as $reaction) : ?><option><?= tr_dj_h($reaction) ?></option><?php endforeach; ?></select></div><button class="dj-auth-button" type="submit">React</button></form>
<p><?php foreach ($activity['reactions'] as $reaction => $count) : ?><?= tr_dj_h($reaction) ?>: <?= (int) $count ?> <?php endforeach; ?></p>
<?php if ($activity['poll'] !== null) : ?>
<form method="post" data-tr-dj-auth class="dj-admin-form"><?php tr_listener_token($activity); ?><input type="hidden" name="action" value="vote"><fieldset><legend><?= tr_dj_h($activity['poll']['question']) ?></legend><?php foreach ($activity['poll']['options'] as $option) : ?><label><input type="radio" name="option" value="<?= (int) $option['position'] ?>" required> <?= tr_dj_h($option['text']) ?> (<?= (int) $option['votes'] ?> votes)</label><?php endforeach; ?></fieldset><button class="dj-auth-button" type="submit">Vote</button></form>
<?php endif; ?>
<?php foreach ($activity['answered_questions'] as $question) : ?><p>Answered: <?= tr_dj_h($question['text']) ?></p><?php endforeach; ?>
<?php else : ?><p>No live DJ broadcast is available right now.</p><?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/footer.php'; ?>
