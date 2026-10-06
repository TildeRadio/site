<?php

declare(strict_types=1);

require __DIR__ . '/_admin.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    tr_dj_error(405, 'This page accepts GET requests only.');
}
$deleted = ($_GET['deleted'] ?? '') === '1';
$accounts = $djStore->accounts($deleted);
tr_admin_begin($deleted ? 'Deleted website DJs' : 'Website DJs', 'accounts');
?>
<div class="dj-admin-toolbar"><a href="<?= tr_dj_h(tr_admin_url('account.php')) ?>">Add a DJ &rarr;</a><a href="<?= tr_dj_h(tr_admin_url('accounts.php' . ($deleted ? '' : '?deleted=1'))) ?>"><?= $deleted ? 'Active records' : 'Deleted records' ?></a></div>
<p class="dj-admin-help">Website access and profile ownership are managed here. Streaming credentials remain in AzuraCast.</p>
<?php if ($accounts === []) : ?>
    <p class="dj-admin-empty">No DJ accounts in this view.</p>
<?php else : ?>
    <div class="dj-admin-table-wrap"><table><caption>DJ accounts</caption><thead><tr><th scope="col">DJ</th><th scope="col">Access</th><th scope="col">Profile</th><th scope="col">Stations</th><th scope="col">Actions</th></tr></thead><tbody>
    <?php foreach ($accounts as $account) : ?>
        <?php $query = '?station=' . $account['station_id'] . '&streamer=' . $account['streamer_id']; ?>
        <tr><td><?= tr_dj_h($account['label'] ?: $account['display_name'] ?: $account['username']) ?><small><?= tr_dj_h($account['username']) ?> · <?= $account['station_id'] ?>:<?= $account['streamer_id'] ?></small></td>
        <td><?= $account['deleted'] ? 'Deleted' : ($account['enabled'] ? 'Enabled' : 'Disabled') ?><small><?= $account['protected'] ? 'Protected administrator' : ($account['role'] === 'admin' ? 'Administrator' : 'DJ') ?></small></td>
        <td><?php if ($account['profile_slug'] !== null) : ?><a href="<?= tr_dj_h(tr_admin_url('profile.php?slug=' . rawurlencode($account['profile_slug']))) ?>"><?= tr_dj_h($account['profile_slug']) ?></a><?php else : ?>Unlinked<?php endif; ?></td>
        <td><?php foreach ($account['assignments'] as $station => $streamer) : ?><?= $station ?> <small>Streamer <?= $streamer ?></small><?php endforeach; ?></td>
        <td><a href="<?= tr_dj_h(tr_admin_url('account.php' . $query)) ?>"><?= $deleted ? 'Restore / edit' : 'Edit' ?></a>
            <?php if (!$deleted) : ?>
                <?php foreach ($account['assignments'] as $station => $streamer) : ?><small><a href="<?= tr_dj_h(tr_admin_url('schedule.php?source_station=' . $account['station_id'] . '&source_streamer=' . $account['streamer_id'] . '&station=' . $station)) ?>">Schedule · station <?= $station ?></a></small><?php endforeach; ?>
            <?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
<?php tr_admin_end(); ?>
