<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\Input;
use TildeRadio\Site\Admin\Problem;

require __DIR__ . '/_admin.php';
$editing = isset($_GET['id']);
try {
    $id = $editing ? Input::integer($_GET['id'], 'station ID') : 0;
    $station = $editing ? ($djStore->station($id) ?? throw new Problem('Station not found.', 404)) : null;
} catch (Problem $problem) {
    tr_dj_error($problem->status, $problem->getMessage());
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = tr_admin_attempt(function () use ($editing, $id, $djStore, $adminIdentity): void {
        $action = Input::text($_POST['action'] ?? '', 'action', 30, true);
        $target = $editing ? $id : Input::integer($_POST['id'] ?? null, 'station ID');
        $version = Input::integer($_POST['version'] ?? '0', 'record version', true);
        if ($action === 'delete' && $editing) {
            $djStore->deleteStation($adminIdentity, $target, $version, Input::text($_POST['confirm'] ?? '', 'confirmation', 255, true));
            tr_admin_finish('stations.php', 'Website station record deleted.');
        }
        if ($action !== 'save') {
            throw new Problem('Choose a valid station action.');
        }
        $djStore->saveStation($adminIdentity, $target, Input::text($_POST['name'] ?? '', 'station name', 255, true), Input::timezone($_POST['timezone'] ?? ''), isset($_POST['enabled']), $version);
        tr_admin_finish('stations.php?id=' . $target, 'Website station saved.');
    });
}
$posted = static fn (string $key, string $default = ''): string => $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($_POST[$key] ?? null) ? $_POST[$key] : $default;
$deleted = ($_GET['deleted'] ?? '') === '1';
tr_admin_begin($editing ? 'Edit website station' : 'Website stations', 'stations', $error);
?>
<p class="dj-admin-help">These records control website assignments. They do not create, delete or reconfigure stations in AzuraCast. Schedule editing uses AzuraCast’s actual station timezone.</p>
<?php if (!$editing) : ?>
    <div class="dj-admin-toolbar"><a href="<?= tr_dj_h(tr_admin_url('stations.php' . ($deleted ? '' : '?deleted=1'))) ?>"><?= $deleted ? 'Active records' : 'Deleted records' ?></a></div>
    <div class="dj-admin-table-wrap"><table><caption>Station records</caption><thead><tr><th scope="col">Station</th><th scope="col">ID</th><th scope="col">Timezone hint</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
        <?php foreach ($djStore->stations($deleted) as $row) : ?><tr><td><?= tr_dj_h($row['name']) ?></td><td><?= $row['id'] ?></td><td><?= tr_dj_h($row['timezone']) ?></td><td><?= $row['deleted'] ? 'Deleted' : ($row['enabled'] ? 'Enabled' : 'Disabled') ?></td><td><a href="<?= tr_dj_h(tr_admin_url('stations.php?id=' . $row['id'])) ?>"><?= $deleted ? 'Restore / edit' : 'Edit' ?></a></td></tr><?php endforeach; ?>
    </tbody></table></div><h2>Add a website station</h2>
<?php endif; ?>
<form method="post" action="<?= tr_dj_h(tr_admin_url('stations.php' . ($editing ? '?id=' . $id : ''))) ?>" class="dj-admin-form" data-tr-dj-auth>
    <?php tr_admin_csrf(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="version" value="<?= tr_admin_version($station['version'] ?? 0) ?>">
    <?php if ($editing) : ?><p>AzuraCast station ID: <strong><?= $id ?></strong></p><?php else : tr_admin_field('id', 'AzuraCast station ID', $posted('id'), 'number', true); endif; ?>
    <?php tr_admin_field('name', 'Website station name', $posted('name', $station['name'] ?? ''), 'text', true); ?>
    <?php tr_admin_field('timezone', 'Website timezone hint', $posted('timezone', $station['timezone'] ?? 'UTC'), 'text', true); ?>
    <label class="dj-admin-check"><input type="checkbox" name="enabled" value="1"<?= ($_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['enabled']) : ($station['enabled'] ?? true)) ? ' checked' : '' ?>>Enable station assignments and schedule editing</label>
    <div class="dj-admin-actions"><button type="submit" class="dj-auth-button"><?= ($station['deleted'] ?? false) ? 'Restore station' : 'Save station' ?></button><?php if ($editing) : ?><a href="<?= tr_dj_h(tr_admin_url('stations.php')) ?>">Back to stations</a><?php endif; ?></div>
</form>
<?php if ($editing && $id !== 1 && !$station['deleted']) : ?>
    <div class="dj-admin-danger"><h2>Delete website station</h2><p>Remove all DJ assignments first. This leaves the actual AzuraCast station unchanged.</p>
    <form method="post" action="<?= tr_dj_h(tr_admin_url('stations.php?id=' . $id)) ?>" class="dj-admin-form" data-tr-dj-auth>
        <?php tr_admin_csrf(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="version" value="<?= tr_admin_version($station['version']) ?>"><?php tr_admin_field('confirm', 'Type ' . $station['name'] . ' to confirm', '', 'text', true); ?><button type="submit" class="dj-auth-button">Delete website station</button>
    </form></div>
<?php endif; ?>
<?php tr_admin_end(); ?>
