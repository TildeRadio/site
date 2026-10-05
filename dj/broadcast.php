<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\BroadcastForm;
use TildeRadio\Site\Admin\Input;
use TildeRadio\Site\Admin\Problem;
use TildeRadio\Site\Admin\PublicBroadcasts;

define('TR_DJ_SELF_SERVICE', true);
require __DIR__ . '/admin/_admin.php';
$all = $djStore->isAdministrator($adminIdentity);
try {
    $id = isset($_GET['id']) ? Input::integer($_GET['id'], 'broadcast ID') : null;
    $stored = $id === null ? null : $djStore->broadcastEdit($id);
    $source = $id === null ? null : $djStore->broadcastSource($id);
    if ($id !== null && $stored === null && $source === null) {
        throw new Problem('Broadcast not found.', 404);
    }
    $owner = PublicBroadcasts::slug((string) ($stored['owner_slug'] ?? $source['dj_slug'] ?? $djAccount['profile_slug'] ?? ''));
    if (!$all) {
        $djStore->requireProfileAccess($adminIdentity, $owner);
        if ($owner === '' || ($stored['deleted'] ?? false)) {
            throw new Problem('This broadcast is unavailable for DJ editing.', 403);
        }
    }
} catch (Problem $problem) {
    tr_dj_error($problem->status, $problem->getMessage());
}
$base = PublicBroadcasts::combine($source ?? [], is_array($stored['patch'] ?? null) ? $stored['patch'] : []);
$error = null;
$token = '';
$url = $djConfig->path('broadcast.php' . ($id === null ? '' : '?id=' . $id));
$contexts = $_SESSION['broadcast_views'] ?? [];
foreach ($contexts as $key => $context) {
    if (!is_array($context) || time() - ($context['issued'] ?? 0) > 900) {
        unset($contexts[$key]);
    }
}
$_SESSION['broadcast_views'] = $contexts;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = tr_admin_attempt(function () use ($all, $id, $owner, $base, $djStore, $adminIdentity, $source, &$token): void {
        $token = Input::text($_POST['broadcast_token'] ?? '', 'broadcast form token', 64, true);
        $view = preg_match('/^[a-f0-9]{64}$/D', $token) === 1 ? ($_SESSION['broadcast_views'][$token] ?? null) : null;
        if (!is_array($view) || $view['id'] !== $id || time() < $view['issued'] || time() - $view['issued'] > 900) {
            throw new Problem('This broadcast form expired or belongs to another listing. Reload it.', 409);
        }
        $action = Input::text($_POST['action'] ?? '', 'broadcast action', 20, true);
        if ($action === 'delete' || $action === 'restore') {
            if ($id === null) {
                throw new Problem('Save a broadcast before changing its visibility.');
            }
            $djStore->setBroadcastDeleted($adminIdentity, $id, $action === 'delete', $view['version'], $view['source_hash'], Input::text($_POST['confirm'] ?? '', 'confirmation', 20, true));
            unset($_SESSION['broadcast_views'][$token]);
            tr_admin_finish('broadcasts.php', $action === 'delete' ? 'Listing deleted. Captured data and audio are retained.' : 'Broadcast listing restored.');
        }
        if ($action !== 'save') {
            throw new Problem('Choose a valid broadcast action.');
        }
        $target = $all ? Input::slug($_POST['owner_slug'] ?? '') : $owner;
        $changes = BroadcastForm::changes($base, $_POST);
        $saved = $djStore->saveBroadcast($adminIdentity, $id, $target, $changes, $view['version'], $view['source_hash']);
        unset($_SESSION['broadcast_views'][$token]);
        tr_admin_finish('broadcast.php?id=' . $saved, 'Broadcast listing saved.');
    });
}
if ($token === '') {
    $token = bin2hex(random_bytes(32));
    $_SESSION['broadcast_views'][$token] = ['id' => $id, 'version' => (int) ($stored['version'] ?? 0), 'source_hash' => BroadcastForm::hash($source), 'issued' => time()];
    while (count($_SESSION['broadcast_views']) > 8) {
        array_shift($_SESSION['broadcast_views']);
    }
}
$show = is_array($base['show'] ?? null) ? $base['show'] : [];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save';
$value = static fn (string $key, string $default = ''): string => $posted && is_string($_POST[$key] ?? null) ? $_POST[$key] : $default;
$status = $value('status', (string) ($base['status'] ?? (!empty($base['is_live']) ? 'live' : ($id === null ? 'planned' : 'recorded'))));
$tracks = json_encode($base['tracks'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
tr_admin_begin($id === null ? 'Add a set / broadcast' : 'Edit set / broadcast #' . $id, 'broadcasts', $error);
?>
<?php if ($stored['deleted'] ?? false) : ?><p class="dj-admin-notice">This listing is deleted. An administrator can restore it below or save it to restore it with corrections.</p><?php endif; ?>
<form method="post" action="<?= tr_dj_h($url) ?>" class="dj-admin-form" data-tr-dj-auth>
    <?php tr_admin_csrf(); ?><input type="hidden" name="broadcast_token" value="<?= tr_dj_h($token) ?>"><input type="hidden" name="action" value="save">
    <fieldset><legend>Listing identity and times</legend>
        <?php if ($all) : ?>
            <label for="broadcast-owner">Owning DJ profile</label><select id="broadcast-owner" name="owner_slug" required>
                <?php $slugs = array_column($djStore->profiles(), 'slug'); if ($owner !== '' && !in_array($owner, $slugs, true)) { $slugs[] = $owner; } ?>
                <?php foreach ($slugs as $slug) : ?><option value="<?= tr_dj_h($slug) ?>"<?= $value('owner_slug', $owner) === $slug ? ' selected' : '' ?>><?= tr_dj_h($slug) ?></option><?php endforeach; ?>
            </select>
        <?php else : ?><p>Assigned profile: <strong><?= tr_dj_h($owner) ?></strong></p><?php endif; ?>
        <?php tr_admin_field('dj', 'Public DJ / host name', $value('dj', (string) ($base['dj'] ?? $djAccount['label'] ?: $adminIdentity['display_name'])), 'text', true); ?>
        <?php tr_admin_field('started_at', 'Start date and time (UTC)', $value('started_at', gmdate('Y-m-d\TH:i', (int) ($base['started_at'] ?? time()))), 'datetime-local', true); ?>
        <?php tr_admin_field('ended_at', 'End date and time (UTC, optional)', $value('ended_at', isset($base['ended_at']) ? gmdate('Y-m-d\TH:i', (int) $base['ended_at']) : ''), 'datetime-local'); ?>
        <label for="broadcast-status">Listing status</label><select id="broadcast-status" name="status"><?php foreach (['planned' => 'Upcoming / planned', 'live' => 'Live listing', 'recorded' => 'Past / recorded'] as $key => $label) : ?><option value="<?= $key ?>"<?= $status === $key ? ' selected' : '' ?>><?= tr_dj_h($label) ?></option><?php endforeach; ?></select>
        <p>These times and status describe the website listing. Use your schedule editor to book airtime.</p>
        <?php tr_admin_field('recording_url', 'Recording / audio URL (optional)', $value('recording_url', (string) ($base['recording_url'] ?? '')), 'url'); ?>
    </fieldset>
    <fieldset><legend>Show and episode metadata</legend>
        <?php foreach (['episode' => 'Set / episode title', 'title' => 'Recurring show title', 'tagline' => 'Show tagline', 'topic' => 'Topic', 'mood' => 'Mood', 'prompt' => 'Listener question / prompt', 'link' => 'Show link'] as $key => $label) : tr_admin_field('show_' . $key, $label, $value('show_' . $key, (string) ($show[$key] ?? '')), $key === 'link' ? 'url' : 'text'); endforeach; ?>
        <?php tr_admin_field('format_title', 'Show format title', $value('format_title', (string) ($show['format']['title'] ?? ''))); ?>
        <?php tr_admin_area('show_description', 'Show description', $value('show_description', (string) ($show['description'] ?? ''))); ?>
        <?php tr_admin_area('show_note', 'Set notes', $value('show_note', (string) ($show['note'] ?? ''))); ?>
    </fieldset>
    <fieldset><legend>Track metadata</legend>
        <p>Leave the checkbox unchecked to keep Carrier’s captured track log updating normally. Check it only when replacing or correcting that list.</p>
        <?php if (strlen($tracks) <= 65536) : ?>
            <label class="dj-admin-check"><input type="checkbox" name="edit_tracks" value="1"<?= $posted && isset($_POST['edit_tracks']) ? ' checked' : '' ?>>Replace the track list with this JSON</label>
            <?php tr_admin_area('tracks_json', 'Tracks (JSON array, at most 500 tracks)', $value('tracks_json', $tracks), 12); ?>
            <p>Example: [{"artist":"Artist","title":"Track","text":"Artist - Track","played_at":1791200000}]</p>
        <?php else : ?><p>This captured track log is too large for the web editor. Its tracks are retained while you edit the other metadata.</p><?php endif; ?>
    </fieldset>
    <details><summary>Correct captured statistics</summary>
        <?php foreach (['peak_listeners' => 'Peak listeners', 'max_couch' => 'Maximum radio couch', 'props' => 'Props', 'questions' => 'Questions', 'requests' => 'Requests', 'reactions' => 'Reactions', 'tildes' => 'Tildes'] as $key => $label) : tr_admin_field($key, $label, $value($key, (string) ($base[$key] ?? 0)), 'number'); endforeach; ?>
    </details>
    <div class="dj-admin-actions"><button type="submit" class="dj-auth-button">Save listing</button><a href="<?= tr_dj_h($djConfig->path('broadcasts.php')) ?>">Cancel</a></div>
</form>
<?php if ($id !== null) : ?>
    <div class="dj-admin-danger"><h2><?= ($stored['deleted'] ?? false) ? 'Restore listing' : 'Delete listing' ?></h2><p>Listing deletion hides the set from the website and public API. Captured archive data and audio are retained.</p>
        <form method="post" action="<?= tr_dj_h($url) ?>" class="dj-admin-form" data-tr-dj-auth>
            <?php tr_admin_csrf(); ?><input type="hidden" name="broadcast_token" value="<?= tr_dj_h($token) ?>"><input type="hidden" name="action" value="<?= ($stored['deleted'] ?? false) ? 'restore' : 'delete' ?>">
            <?php tr_admin_field('confirm', 'Type ' . (($stored['deleted'] ?? false) ? 'RESTORE' : 'DELETE') . ' to confirm', '', 'text', true); ?><button type="submit" class="dj-auth-button"><?= ($stored['deleted'] ?? false) ? 'Restore listing' : 'Delete listing' ?></button>
        </form>
    </div>
<?php endif; ?>
<?php tr_admin_end(); ?>
