<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\Input;
use TildeRadio\Site\Admin\PlanForm;
use TildeRadio\Site\Admin\Problem;

define('TR_DJ_PLAN_REQUEST', true);
require __DIR__ . '/_carrier.php';
$error = null;
$id = null;
$copy = null;
$plan = null;
$error = tr_admin_attempt(function () use (&$id, &$copy, &$plan): void {
    $id = isset($_GET['id']) ? Input::integer($_GET['id'], 'prepared show ID') : null;
    $copy = isset($_GET['copy']) ? Input::integer($_GET['copy'], 'prepared show ID') : null;
    global $djStore, $adminIdentity;
    if ($id !== null || $copy !== null) {
        $djStore->requirePlanAccess($adminIdentity, $id ?? $copy);
        $plan = $djStore->plan($id ?? $copy);
    }
});
if ($error !== null) {
    tr_dj_error(http_response_code(), $error);
}
$owner = $plan !== null ? ['station_id' => $plan['owner_station'], 'streamer_id' => $plan['owner_streamer']]
    : ['station_id' => $adminIdentity['station_id'], 'streamer_id' => $adminIdentity['streamer_id']];
$ownerKey = $owner['station_id'] . ':' . $owner['streamer_id'];
$version = $id !== null ? $plan['version'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = tr_admin_attempt(function () use ($id, &$owner, &$ownerKey): void {
        global $djStore, $adminIdentity;
        if (isset($_POST['owner']) && $id === null && $djStore->isAdministrator($adminIdentity)) {
            $value = Input::text($_POST['owner'], 'DJ account', 80, true);
            if (!preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/D', $value, $match)) {
                throw new Problem('Choose an assigned DJ account.');
            }
            $djStore->requireAccount((int) $match[1], (int) $match[2]);
            $owner = ['station_id' => (int) $match[1], 'streamer_id' => (int) $match[2]];
            $ownerKey = $value;
        }
        $version = Input::integer($_POST['version'] ?? null, 'version', true);
        if (($_POST['action'] ?? '') === 'cancel' && $id !== null) {
            $djStore->cancelPlan($adminIdentity, $id, $version, Input::text($_POST['confirm'] ?? '', 'confirmation', 20));
            tr_admin_finish('plans.php', 'Preparation cancelled. Your AzuraCast booking and captured broadcasts were preserved.');
        }
        $saved = $djStore->savePlan($adminIdentity, $id, $owner, Input::integer($_POST['station_id'] ?? null, 'station'), PlanForm::decode($_POST), $version);
        tr_admin_finish('plan.php?id=' . $saved, 'Show preparation saved. Carrier keeps an immutable copy once a broadcast starts.');
    });
}
$account = $djStore->requireAccount($owner['station_id'], $owner['streamer_id']);
$values = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [
    'starts_at' => $plan !== null && $copy === null ? gmdate('Y-m-d\TH:i', $plan['starts_at']) : '',
    'ends_at' => $plan !== null && $copy === null ? gmdate('Y-m-d\TH:i', $plan['ends_at']) : '',
    'station_id' => $plan['station_id'] ?? array_key_first($account['assignments']) ?? 1,
    'playlist' => implode("\n", array_map(static fn (array $track): string => $track['artist'] . ' | ' . $track['title'], $plan['tracks'] ?? [])),
    'public' => ($plan['public'] ?? false) ? '1' : '',
    'reminder' => ($plan['reminder'] ?? false) ? '1' : '',
    'song_announcements' => $copy === null && ($plan['song_announcements'] ?? false) ? '1' : '',
] + ($plan['show'] ?? []);
tr_admin_begin($id === null ? 'Prepare a new show' : 'Edit prepared show', 'plans', $error);
?>
<?php if (($plan['broadcast_id'] ?? null) !== null) : ?><p class="dj-admin-notice">This preparation is attached to set #<?= (int) $plan['broadcast_id'] ?>. <a href="<?= tr_dj_h($djConfig->path('broadcast.php?id=' . $plan['broadcast_id'])) ?>">Edit the broadcast listing</a> or copy the preparation for another show.</p><?php endif; ?>
<p>Times are UTC. This preparation does not create or change an AzuraCast booking. Choose the expected broadcast window, up to twelve hours. If multiple preparations match the same live DJ, Carrier uses normal metadata capture until the ambiguity is resolved.</p>
<form method="post" data-tr-dj-auth class="dj-admin-form">
<?php tr_admin_csrf(); ?>
<input type="hidden" name="version" value="<?= tr_admin_version($version) ?>">
<?php if ($id === null && $djStore->isAdministrator($adminIdentity)) : ?>
<div class="dj-admin-control"><label for="plan-owner">DJ account</label><select id="plan-owner" name="owner">
<?php foreach ($djStore->accounts() as $candidate) : if (!$candidate['enabled'] || $candidate['profile_slug'] === null) { continue; } $key = $candidate['station_id'] . ':' . $candidate['streamer_id']; ?>
<option value="<?= tr_dj_h($key) ?>"<?= $key === $ownerKey ? ' selected' : '' ?>><?= tr_dj_h($candidate['display_name'] . ' (' . $candidate['username'] . ')') ?></option>
<?php endforeach; ?></select></div>
<?php endif; ?>
<div class="dj-admin-control"><label for="plan-station">Station</label><select id="plan-station" name="station_id">
<?php foreach (($djStore->isAdministrator($adminIdentity) ? $djStore->stations() : array_map(fn (int $station): ?array => $djStore->station($station), array_keys($account['assignments']))) as $station) : if ($station === null || !$station['enabled'] || $station['deleted']) { continue; } ?>
<option value="<?= $station['id'] ?>"<?= (int) ($values['station_id'] ?? 0) === $station['id'] ? ' selected' : '' ?>><?= tr_dj_h($station['name']) ?></option>
<?php endforeach; ?></select></div>
<?php tr_admin_field('starts_at', 'Expected start (UTC)', (string) ($values['starts_at'] ?? ''), 'datetime-local', true); ?>
<?php tr_admin_field('ends_at', 'Expected end (UTC)', (string) ($values['ends_at'] ?? ''), 'datetime-local', true); ?>
<?php foreach (['episode' => 'Episode title', 'topic' => 'Topic', 'mood' => 'Mood', 'prompt' => 'Listener prompt', 'note' => 'Show note', 'link' => 'Show link'] as $field => $label) { tr_admin_field($field, $label, (string) ($values[$field] ?? '')); } ?>
<?php tr_admin_area('playlist', 'Planned songs — one Artist | Title per line, in playback order', (string) ($values['playlist'] ?? ''), 14); ?>
<p class="dj-admin-control-wide">You can paste artist and title columns from a spreadsheet. Leave the list empty to use existing metadata capture. Songs become confirmed playback only when advanced with the website button or <code>!track next</code> / <code>!track N</code>.</p>
<label class="dj-admin-checkbox"><input type="checkbox" name="public" value="1"<?= ($values['public'] ?? '') === '1' ? ' checked' : '' ?>> Publish the upcoming episode title and topic</label>
<label class="dj-admin-checkbox"><input type="checkbox" name="reminder" value="1"<?= ($values['reminder'] ?? '') === '1' ? ' checked' : '' ?>> Queue a private IRC reminder for my linked account</label>
<label class="dj-admin-checkbox"><input type="checkbox" name="song_announcements" value="1"<?= ($values['song_announcements'] ?? '') === '1' ? ' checked' : '' ?>> Automatically announce new songs in Carrier’s IRC channels for this show</label>
<p class="dj-admin-control-wide">Off by default for each new show. You can change this during your broadcast in Live / IRC or with <code>!songs on</code> / <code>!songs off</code>. Song capture and playlist timestamps continue in either mode.</p>
<button class="dj-auth-button" type="submit" name="action" value="save">Save preparation</button>
</form>
<?php if ($id !== null) : ?>
<p>Copy this preparation to create another show. Changes here do not change a copy already attached to a live or completed broadcast.</p>
<p><a href="<?= tr_dj_h($djConfig->path('plan.php?copy=' . $id)) ?>">Copy for another show</a></p>
<form method="post" data-tr-dj-auth class="dj-admin-form"><?php tr_admin_csrf(); ?><input type="hidden" name="version" value="<?= tr_admin_version($version) ?>"><input type="hidden" name="action" value="cancel"><?php tr_admin_field('confirm', 'Type CANCEL to remove this preparation'); ?><button class="dj-auth-button dj-admin-danger" type="submit">Cancel preparation</button></form>
<?php endif; ?>
<?php tr_admin_end(); ?>
