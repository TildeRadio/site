<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\Input;
use TildeRadio\Site\Admin\Problem;
use TildeRadio\Site\Admin\DjDirectory;

require __DIR__ . '/_admin.php';
$existing = isset($_GET['station'], $_GET['streamer']);
try {
    $sourceStation = $existing ? Input::integer($_GET['station'], 'authentication station ID') : $adminIdentity['station_id'];
    $sourceStreamer = $existing ? Input::integer($_GET['streamer'], 'authentication streamer ID') : 0;
    $account = $existing ? $djStore->requireAccount($sourceStation, $sourceStreamer) : null;
} catch (Problem $problem) {
    tr_dj_error($problem->status, $problem->getMessage());
}
$error = null;
$directory = new DjDirectory($djConfig);
$manual = ($_GET['manual'] ?? '') === '1';
if (($_GET['refresh'] ?? '') === '1') {
    unset($_SESSION['admin_dj_directory']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = tr_admin_attempt(function () use ($existing, $sourceStation, $sourceStreamer, $account, $djStore, $adminIdentity, $djTransport, $directory): void {
        $action = Input::text($_POST['action'] ?? '', 'action', 30, true);
        $version = Input::integer($_POST['version'] ?? '0', 'record version', true);
        if ($action === 'delete' && $existing) {
            $djStore->deleteAccount($adminIdentity, $sourceStation, $sourceStreamer, $version, Input::text($_POST['confirm'] ?? '', 'confirmation', 255, true));
            tr_admin_finish('accounts.php', 'Website DJ account deleted. Its streaming account and archive are unchanged.');
        }
        if ($action !== 'save') {
            throw new Problem('Choose a valid account action.');
        }
        $station = $existing ? $sourceStation : Input::integer($_POST['station_id'] ?? null, 'authentication station ID');
        $streamer = $existing ? $sourceStreamer : Input::integer($_POST['streamer_id'] ?? null, 'authentication streamer ID');
        $identityMode = Input::text($_POST['identity_mode'] ?? 'manual', 'identity selection mode', 20);
        if (!in_array($identityMode, ['manual', 'picker'], true)) {
            throw new Problem('Choose a valid DJ selection mode.');
        }
        $picked = !$existing && $identityMode === 'picker' ? $directory->verify($station, $streamer) : null;
        if ($account === null || $account['deleted']) {
            $verified = $djTransport->check($streamer);
            if ($verified['station_id'] !== $station || $verified['streamer_id'] !== $streamer
                || ($picked !== null && $picked['username'] !== $verified['username'])) {
                throw new Problem('The authentication bridge did not verify this station and streamer pair.');
            }
        } else {
            // Existing stable IDs are immutable. Inactive streaming DJs can still have website metadata edited.
            $verified = ['station_id' => $station, 'streamer_id' => $streamer, 'username' => $account['username'], 'display_name' => $account['display_name'], 'verified_at' => time()];
        }
        $slug = Input::text($_POST['profile_slug'] ?? '', 'profile slug', 80);
        $role = Input::text($_POST['role'] ?? $account['role'] ?? 'dj', 'website role', 20);
        $selected = $_POST['stations'] ?? [];
        $ids = $_POST['target_streamers'] ?? [];
        $modes = $_POST['target_modes'] ?? [];
        if (!is_array($selected) || !array_is_list($selected) || count($selected) > 100 || !is_array($ids) || !is_array($modes)) {
            throw new Problem('Choose valid station assignments.');
        }
        $assignments = [];
        foreach ($selected as $id) {
            $target = Input::integer($id, 'assigned station ID');
            $value = $ids[$target] ?? '';
            $value = $value === '' && $target === $station ? $streamer : $value;
            $targetStreamer = Input::integer($value, 'streamer ID for the assigned station');
            $mode = $modes[$target] ?? 'manual';
            if (!in_array($mode, ['picker', 'manual'], true)) {
                throw new Problem('Choose a valid station DJ selection mode.');
            }
            if ($mode === 'picker' && ($account['assignments'][$target] ?? null) !== $targetStreamer) {
                $directory->verify($target, $targetStreamer);
            }
            $assignments[$target] = $targetStreamer;
        }
        $djStore->saveAccount($adminIdentity, $verified, Input::text($_POST['label'] ?? '', 'website name'), $slug === '' ? null : Input::slug($slug),
            isset($_POST['enabled']), $role, $assignments, $version);
        tr_admin_finish('account.php?station=' . $station . '&streamer=' . $streamer, 'Website DJ account saved.');
    });
}
$posted = static fn (string $name, string $default = ''): string => $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST[$name] ?? null) ? $_POST[$name] : $default;
$saving = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save';
$enabled = $saving ? isset($_POST['enabled']) : ($account['enabled'] ?? true);
$role = $posted('role', $account['role'] ?? 'dj');
$profileSlug = $posted('profile_slug', $account['profile_slug'] ?? '');
$selectedStations = $saving && is_array($_POST['stations'] ?? null) ? $_POST['stations'] : array_map('strval', array_keys($account['assignments'] ?? [$sourceStation => 0]));
$query = $existing ? '?station=' . $sourceStation . '&streamer=' . $sourceStreamer : '';
$formQuery = $query . ($manual ? ($query === '' ? '?' : '&') . 'manual=1' : '');
$sourceChoices = !$existing && !$manual ? $directory->choices($sourceStation) : null;
tr_admin_begin($existing ? 'Edit website DJ' : 'Add a website DJ', 'accounts', $error);
?>
<p><a href="<?= tr_dj_h(tr_admin_url('account.php' . $query . ($query === '' ? '?' : '&') . 'refresh=1')) ?>">Refresh DJ names from AzuraCast</a> · <a href="<?= tr_dj_h(tr_admin_url('account.php' . $query . ($manual ? '' : ($query === '' ? '?' : '&') . 'manual=1'))) ?>"><?= $manual ? 'Use DJ name picker' : 'Use manual ID entry' ?></a></p>
<form method="post" action="<?= tr_dj_h(tr_admin_url('account.php' . $formQuery)) ?>" class="dj-admin-form" data-tr-dj-auth>
    <?php tr_admin_csrf(); ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="version" value="<?= tr_admin_version($account['version'] ?? 0) ?>">
    <fieldset><legend>Verified streaming identity</legend>
        <?php if ($existing) : ?>
            <p>DJ username: <strong><?= tr_dj_h($account['username']) ?></strong><br>Authentication station <?= $sourceStation ?> · streamer <?= $sourceStreamer ?></p>
            <p>These identity IDs stay fixed. Website edits do not change the streaming username or password.</p>
        <?php elseif ($sourceChoices['available'] ?? false) : ?>
            <input type="hidden" name="identity_mode" value="picker"><input type="hidden" name="station_id" value="<?= $sourceStation ?>">
            <p>Authentication station: <strong><?= tr_dj_h($djStore->station($sourceStation)['name'] ?? 'Station ' . $sourceStation) ?></strong>. Select a DJ by username; their account ID is recorded automatically.</p>
            <label for="admin-streamer_id">AzuraCast DJ</label><select id="admin-streamer_id" name="streamer_id" required>
                <option value="">Choose a DJ</option>
                <?php foreach ($sourceChoices['entries'] as $entry) :
                    $registered = $djStore->account($sourceStation, $entry['id']) !== null;
                    ?>
                    <option value="<?= $entry['id'] ?>"<?= $posted('streamer_id') === (string) $entry['id'] ? ' selected' : '' ?><?= !$entry['active'] || $registered ? ' disabled' : '' ?>><?= tr_dj_h($entry['username'] . ($entry['display_name'] !== $entry['username'] ? ' — ' . $entry['display_name'] : '') . (!$entry['active'] ? ' (inactive in AzuraCast)' : ($registered ? ' (already on website)' : ''))) ?></option>
                <?php endforeach; ?>
            </select>
            <p>Existing or deleted website DJs are edited/restored through the <a href="<?= tr_dj_h(tr_admin_url('accounts.php')) ?>">DJ list</a>. A selected account is verified again when saved.</p>
        <?php else : ?>
            <input type="hidden" name="identity_mode" value="manual">
            <?php if ($sourceChoices['error'] ?? null) : ?><p role="status"><?= tr_dj_h($sourceChoices['error']) ?></p><?php endif; ?>
            <?php tr_admin_field('station_id', 'AzuraCast authentication station ID', $posted('station_id', (string) $sourceStation), 'number', true); ?>
            <?php tr_admin_field('streamer_id', 'AzuraCast streamer ID', $posted('streamer_id'), 'number', true); ?>
            <p>The existing authentication bridge must verify this pair. It currently authenticates your configured TildeRadio station.</p>
        <?php endif; ?>
    </fieldset>
    <fieldset><legend>Website access</legend>
        <?php tr_admin_field('label', 'Website name (optional)', $posted('label', $account['label'] ?? '')); ?>
        <label class="dj-admin-check"><input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>>Allow website login</label>
        <label for="admin-role">Website role</label>
        <?php if ($account['protected'] ?? false) : ?>
            <input id="admin-role" type="text" value="Protected administrator" readonly><input type="hidden" name="role" value="admin"><p>This access is controlled by the private server configuration.</p>
        <?php else : ?>
            <select id="admin-role" name="role"><option value="dj"<?= $role === 'dj' ? ' selected' : '' ?>>DJ</option><option value="admin"<?= $role === 'admin' ? ' selected' : '' ?>>Administrator</option></select>
        <?php endif; ?>
        <label for="admin-profile_slug">Public DJ profile</label>
        <select id="admin-profile_slug" name="profile_slug"><option value="">Unlinked</option>
            <?php if ($profileSlug !== '' && (($djStore->profile($profileSlug)['deleted'] ?? true))) : ?><option value="<?= tr_dj_h($profileSlug) ?>" selected><?= tr_dj_h($profileSlug) ?> (deleted or unavailable)</option><?php endif; ?>
            <?php foreach ($djStore->profiles() as $profile) : ?><option value="<?= tr_dj_h($profile['slug']) ?>"<?= $profileSlug === $profile['slug'] ? ' selected' : '' ?>><?= tr_dj_h($profile['slug']) ?><?= $profile['published'] ? '' : ' (unpublished)' ?></option><?php endforeach; ?>
        </select>
        <p><a href="<?= tr_dj_h(tr_admin_url('profile.php')) ?>">Create a profile first</a> if it is not listed. Linking uses the verified account IDs, not matching display names.</p>
    </fieldset>
    <fieldset><legend>Station assignments</legend><p>Choose stations this website DJ belongs to, then select the DJ username used in each station. In their authentication station, the default uses the verified account above. Existing assignments are retained until you change them.</p>
        <?php foreach ($djStore->stations() as $station) : ?>
            <label class="dj-admin-check"><input type="checkbox" name="stations[]" value="<?= $station['id'] ?>"<?= in_array((string) $station['id'], $selectedStations, true) ? ' checked' : '' ?><?= !$station['enabled'] && !isset($account['assignments'][$station['id']]) ? ' disabled' : '' ?>><?= tr_dj_h($station['name']) ?> · station <?= $station['id'] ?><?= $station['enabled'] ? '' : ' (disabled)' ?></label>
            <?php
            $values = $saving && is_array($_POST['target_streamers'] ?? null) ? $_POST['target_streamers'] : ($account['assignments'] ?? []);
            $value = $values[$station['id']] ?? ($station['id'] === $sourceStation && $sourceStreamer > 0 ? $sourceStreamer : '');
            $value = is_scalar($value) ? (string) $value : '';
            $choices = !$manual && ($station['enabled'] || isset($account['assignments'][$station['id']])) ? $directory->choices($station['id']) : null;
            ?>
            <?php if ($choices['available'] ?? false) : ?>
                <input type="hidden" name="target_modes[<?= $station['id'] ?>]" value="picker">
                <label for="target-streamer-<?= $station['id'] ?>">DJ in <?= tr_dj_h($station['name']) ?></label>
                <select id="target-streamer-<?= $station['id'] ?>" name="target_streamers[<?= $station['id'] ?>]">
                    <option value=""><?= $station['id'] === $sourceStation ? 'Use the verified authentication DJ' : 'Choose a DJ for this station' ?></option>
                    <?php if ($value !== '' && !in_array((int) $value, array_column($choices['entries'], 'id'), true)) : ?><option value="<?= tr_dj_h($value) ?>" selected>Keep existing assignment (ID <?= tr_dj_h($value) ?>; not in current directory)</option><?php endif; ?>
                    <?php foreach ($choices['entries'] as $entry) : ?>
                        <option value="<?= $entry['id'] ?>"<?= $value === (string) $entry['id'] ? ' selected' : '' ?><?= !$entry['active'] && $value !== (string) $entry['id'] ? ' disabled' : '' ?>><?= tr_dj_h($entry['username'] . ($entry['display_name'] !== $entry['username'] ? ' — ' . $entry['display_name'] : '') . (!$entry['active'] ? ' (inactive in AzuraCast)' : '')) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else : ?>
                <input type="hidden" name="target_modes[<?= $station['id'] ?>]" value="manual">
                <?php if ($choices['error'] ?? null) : ?><p role="status"><?= tr_dj_h($choices['error']) ?></p><?php endif; ?>
                <label for="target-streamer-<?= $station['id'] ?>">Streamer ID in station <?= $station['id'] ?> (manual fallback)</label><input id="target-streamer-<?= $station['id'] ?>" name="target_streamers[<?= $station['id'] ?>]" type="number" min="1" value="<?= tr_dj_h($value) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
    </fieldset>
    <div class="dj-admin-actions"><button type="submit" class="dj-auth-button"><?= ($account['deleted'] ?? false) ? 'Restore website DJ' : 'Save website DJ' ?></button><a href="<?= tr_dj_h(tr_admin_url('accounts.php')) ?>">Cancel</a></div>
</form>
<?php if ($existing && !$account['protected'] && !$account['deleted']) : ?>
    <div class="dj-admin-danger"><h2>Delete website DJ</h2><p>This removes website access and station assignments. The AzuraCast streaming account, public profile and archived broadcasts stay intact.</p>
        <form method="post" action="<?= tr_dj_h(tr_admin_url('account.php' . $query)) ?>" class="dj-admin-form" data-tr-dj-auth>
            <?php tr_admin_csrf(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="version" value="<?= tr_admin_version($account['version']) ?>">
            <?php tr_admin_field('confirm', 'Type ' . $account['username'] . ' to confirm', '', 'text', true); ?><button type="submit" class="dj-auth-button">Delete website DJ</button>
        </form>
    </div>
<?php endif; ?>
<?php tr_admin_end(); ?>
