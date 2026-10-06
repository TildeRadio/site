<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\Input;
use TildeRadio\Site\Admin\Problem;

require __DIR__ . '/_carrier.php';
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = tr_admin_attempt(function (): void {
        global $djStore, $adminIdentity;
        $id = Input::text($_POST['recording_id'] ?? '', 'recording ID', 64, true);
        $form = $_SESSION['recording_forms'][$id] ?? null;
        if (!is_array($form) || $form['issued'] < time() - 900) {
            throw new Problem('Recording review form expired. Reload it.', 409);
        }
        $action = Input::text($_POST['action'] ?? '', 'review action', 20, true);
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new Problem('Choose approve or reject.');
        }
        $djStore->reviewRecording($adminIdentity, $id, $action === 'approve', $form['version'], $form['broadcast_version'], $form['source_hash']);
        unset($_SESSION['recording_forms'][$id]);
        tr_admin_finish('recordings.php', $action === 'approve' ? 'Recording attached to the broadcast listing.' : 'Recording candidate rejected. The recording file was preserved.');
    });
}
$items = $djStore->recordingCandidates($adminIdentity);
$_SESSION['recording_forms'] = [];
tr_admin_begin('Review recordings', 'recordings', $error);
?>
<p>Approve a recording link to attach it to a broadcast listing. Rejecting a candidate or deleting a listing preserves the recording file. Existing broadcast editing also allows you to enter a recording URL directly.</p>
<p><a href="<?= tr_dj_h(asset('api/podcast/')) ?>">Station podcast feed</a><?php if ($djAccount['profile_slug'] !== null) : ?> · <a href="<?= tr_dj_h(asset('api/podcast/?dj=' . rawurlencode($djAccount['profile_slug']))) ?>">Your podcast feed</a><?php endif; ?></p>
<?php foreach ($items as $item) :
    $preview = $item['url'];
    $prefix = $djConfig->origin() . $djConfig->publicPath('recordings/?id=');
    if (str_starts_with($preview, $prefix)) {
        $preview = $djConfig->path('recording.php?id=' . substr($preview, strlen($prefix)));
    }
    $_SESSION['recording_forms'][$item['id']] = ['issued' => time(), 'version' => (int) $item['version'], 'broadcast_version' => $item['broadcast_version'], 'source_hash' => $item['source_hash']];
    ?>
<article class="dj-broadcast-card"><h2>Set #<?= (int) $item['broadcast_id'] ?> · <?= tr_dj_h($item['owner_slug']) ?></h2><p><a href="<?= tr_dj_h($preview) ?>" target="_blank" rel="noopener noreferrer">Listen to candidate recording</a></p>
<form method="post" data-tr-dj-auth><?php tr_admin_csrf(); ?><input type="hidden" name="recording_id" value="<?= tr_dj_h($item['id']) ?>"><button class="dj-auth-button" type="submit" name="action" value="approve">Approve recording</button> <button class="dj-auth-button dj-auth-button-secondary" type="submit" name="action" value="reject">Reject candidate</button></form></article>
<?php endforeach; ?>
<?php if ($items === []) : ?><p>No recordings are waiting for your review.</p><?php endif; ?>
<?php tr_admin_end(); ?>
