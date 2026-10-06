<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\Input;

require __DIR__ . '/_carrier.php';
$error = null;
$issuedCode = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = tr_admin_attempt(function () use (&$issuedCode): void {
        $request = tr_carrier_post();
        tr_carrier_sync();
        $request += ['action' => Input::text($_POST['action'] ?? '', 'action', 40),
            'value' => Input::text($_POST['value'] ?? '', 'value', 700),
            'field' => Input::text($_POST['field'] ?? '', 'field', 20)];
        $result = tr_carrier_client()->call($request);
        if (isset($result['code'])) {
            $issuedCode = $result;
            return;
        }
        tr_admin_finish('carrier.php', is_string($result['message'] ?? null) ? $result['message'] : 'Carrier updated.');
    });
}
$activity = [];
$viewError = tr_admin_attempt(function () use (&$activity): void {
    global $adminIdentity;
    $activity = tr_carrier_client()->call(['op' => 'view', 'actor' => $adminIdentity['station_id'] . ':' . $adminIdentity['streamer_id']]);
});
tr_admin_begin('Live show & IRC connection', 'carrier', $error ?? $viewError);
?>
<p><a href="<?= tr_dj_h($djConfig->path('plans.php')) ?>">Prepare an upcoming show</a> · <a href="<?= tr_dj_h($djConfig->path('carrier.php')) ?>">Refresh live controls</a> · <a href="<?= tr_dj_h(asset('community/live.php')) ?>">Listener activity page</a></p>
<?php if ($issuedCode !== null) : ?>
<div class="dj-admin-notice" role="status"><p>Send this privately to Carrier on the IRC network you want to link:</p><p><code>!link <?= tr_dj_h($issuedCode['code']) ?></code></p><p>The code expires in five minutes and can be used once. Sign in to IRC services first. Never post the code in a channel.</p></div>
<?php endif; ?>
<section><h2>Your IRC accounts</h2><p>Links use verified services accounts and their IRC network, so changing your nickname keeps the link. Existing bot mappings continue to work.</p>
<form method="post" data-tr-dj-auth><?php tr_carrier_action('link_code'); ?><button class="dj-auth-button" type="submit">Generate a linking code</button></form>
<?php foreach ($activity['links'] ?? [] as $link) : ?>
<form method="post" data-tr-dj-auth class="dj-admin-inline"><span><?= tr_dj_h($link['account'] . ' on ' . $link['network']) ?></span><?php tr_carrier_action('unlink', ['network' => $link['network'], 'account' => $link['account']]); ?><button type="submit" class="dj-auth-button dj-auth-button-secondary">Disconnect</button></form>
<?php endforeach; ?></section>
<?php if (!empty($activity['is_live'])) : ?>
<section><h2>Live: <?= tr_dj_h((string) $activity['dj']) ?> · set #<?= (int) $activity['session_id'] ?></h2>
<p><?= (int) ($activity['listeners'] ?? 0) ?> listeners · Tracking: <?= tr_dj_h((string) $activity['tracking']) ?> · Song <?= (int) $activity['position'] ?></p>
<?php if (!empty($activity['current_track'])) : ?><p><strong><?= tr_dj_h($activity['current_track']['artist'] . ' — ' . $activity['current_track']['title']) ?></strong></p><?php endif; ?>
<?php if (!empty($activity['can_control'])) : $bound = ['session_id' => $activity['session_id']]; ?>
<h3>Automatic song announcements</h3>
<p>Currently <?= !empty($activity['song_announcements']) ? 'on' : 'off' ?> for this broadcast. Off by default for every new show; captures and song timestamps continue either way. IRC: <code>!songs on</code> / <code>!songs off</code>.</p>
<form method="post" data-tr-dj-auth class="dj-admin-inline"><?php tr_carrier_action('live', $bound + ['action' => 'song_announcements']); ?><input type="hidden" name="value" value="<?= !empty($activity['song_announcements']) ? 'off' : 'on' ?>"><button class="dj-auth-button" type="submit"><?= !empty($activity['song_announcements']) ? 'Disable song announcements' : 'Enable song announcements' ?></button></form>
<?php if ($activity['playlist'] !== []) : ?>
<form method="post" data-tr-dj-auth class="dj-admin-inline"><?php tr_carrier_action('live', $bound + ['action' => 'track']); ?><input type="hidden" name="value" value="next"><button class="dj-auth-button" type="submit">Start next song</button></form>
<form method="post" data-tr-dj-auth class="dj-admin-form"><?php tr_carrier_action('live', $bound + ['action' => 'track']); ?><div class="dj-admin-control"><label for="track-position">Start a song by number</label><select id="track-position" name="value"><?php foreach ($activity['playlist'] as $index => $track) : ?><option value="<?= $index + 1 ?>"><?= tr_dj_h(($index + 1) . '. ' . $track['artist'] . ' — ' . $track['title']) ?></option><?php endforeach; ?></select></div><button class="dj-auth-button" type="submit">Start selected song</button></form>
<form method="post" data-tr-dj-auth class="dj-admin-form"><?php tr_carrier_action('live', $bound + ['action' => 'tracking']); ?><div class="dj-admin-control"><label for="tracking-mode">Tracking mode</label><select id="tracking-mode" name="value"><option value="planned"<?= $activity['tracking'] === 'planned' ? ' selected' : '' ?>>Prepared playlist</option><option value="metadata"<?= $activity['tracking'] === 'metadata' ? ' selected' : '' ?>>Captured metadata</option></select></div><button class="dj-auth-button" type="submit">Change mode</button></form>
<?php endif; ?>
<h3>Live show information</h3>
<?php foreach (['episode' => 'Episode title', 'topic' => 'Topic', 'mood' => 'Mood', 'prompt' => 'Listener prompt', 'note' => 'Show note', 'link' => 'Show link', 'mastodon' => 'One Mastodon note for this set'] as $field => $label) : ?>
<form method="post" data-tr-dj-auth class="dj-admin-form"><?php tr_carrier_action('live', $bound + ['action' => 'show', 'field' => $field]); tr_admin_field('value', $label, (string) ($activity['show'][$field] ?? ''), 'text', false, '-' . $field); ?><button class="dj-auth-button" type="submit">Save <?= tr_dj_h($label) ?></button></form>
<?php endforeach; ?>
<h3>Listener controls</h3>
<?php foreach (['requests' => ['Requests', $activity['requests_enabled'] ? 'off' : 'on'], 'goal' => ['Listener goal — number and optional text, or clear', (string) ($activity['goal'] ?? '')], 'handoff' => ['Next DJ for handoff', (string) ($activity['handoff'] ?? '')], 'poll_start' => ['Poll — Question | First option | Second option', '']] as $action => [$label, $value]) : ?>
<form method="post" data-tr-dj-auth class="dj-admin-form"><?php tr_carrier_action('live', $bound + ['action' => $action]); tr_admin_field('value', $label, $value, 'text', true, '-' . $action); ?><button class="dj-auth-button" type="submit"><?= tr_dj_h(match ($action) { 'requests' => $activity['requests_enabled'] ? 'Close requests' : 'Open requests', 'poll_start' => 'Start poll', 'goal' => 'Update listener goal', 'handoff' => 'Update handoff' }) ?></button></form>
<?php endforeach; ?>
<?php if ($activity['poll'] !== null) : ?>
<p><?= tr_dj_h($activity['poll']['question']) ?></p><form method="post" data-tr-dj-auth><?php tr_carrier_action('live', $bound + ['action' => 'poll_stop']); ?><button class="dj-auth-button" type="submit">Close poll</button></form>
<?php endif; ?>
<?php foreach (['questions' => ['Question queue', ['qanswer' => 'Answer', 'qskip' => 'Skip']], 'requests' => ['Request queue', ['played' => 'Played', 'reject' => 'Reject']]] as $queue => [$label, $actions]) : ?>
<h3><?= tr_dj_h($label) ?></h3>
<?php foreach ($activity[$queue] ?? [] as $item) : ?><article class="dj-broadcast-card"><p><?= tr_dj_h($item['nick'] . ': ' . $item['text']) ?></p><div><?php foreach ($actions as $action => $label) : ?><form method="post" data-tr-dj-auth class="dj-admin-inline"><?php tr_carrier_action('live', $bound + ['action' => $action, 'item_id' => $item['id']]); ?><button class="dj-auth-button dj-auth-button-secondary" type="submit"><?= tr_dj_h($label) ?></button></form><?php endforeach; ?></div></article><?php endforeach; ?>
<?php endforeach; ?>
<?php else : ?><p>These controls are available to this broadcast’s verified DJ and website administrators. Other DJs can prepare their own upcoming shows.</p><?php endif; ?></section>
<?php else : ?><p>No live DJ broadcast is currently available.</p><?php endif; ?>
<?php if ($djStore->isAdministrator($adminIdentity)) : ?>
<section><h2>Administrator controls</h2><p><a href="<?= tr_dj_h($djConfig->path('admin/carrier.php')) ?>">Integration status and synchronization</a></p>
<?php foreach ($activity['all_links'] ?? [] as $link) : ?><form method="post" data-tr-dj-auth class="dj-admin-inline"><span><?= tr_dj_h($link['actor'] . ' · ' . $link['account'] . ' on ' . $link['network']) ?></span><?php tr_carrier_action('unlink', ['owner' => $link['actor'], 'network' => $link['network'], 'account' => $link['account']]); ?><button class="dj-auth-button dj-admin-danger" type="submit">Revoke link</button></form><?php endforeach; ?></section>
<?php endif; ?>
<?php tr_admin_end(); ?>
