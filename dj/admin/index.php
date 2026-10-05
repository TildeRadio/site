<?php

declare(strict_types=1);

require __DIR__ . '/_admin.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    tr_dj_error(405, 'This page accepts GET requests only.');
}
$accounts = $djStore->accounts();
$profiles = $djStore->profiles();
$stations = $djStore->stations();
tr_admin_begin('Station administration', 'overview');
?>
<p class="dj-admin-help">Manage website access, DJ profiles, station assignments and broadcast schedules.</p>
<div class="dj-admin-stats">
    <a href="<?= tr_dj_h(tr_admin_url('accounts.php')) ?>"><strong><?= count($accounts) ?></strong>Website DJs</a>
    <a href="<?= tr_dj_h(tr_admin_url('profiles.php')) ?>"><strong><?= count($profiles) ?></strong>DJ profiles</a>
    <a href="<?= tr_dj_h(tr_admin_url('stations.php')) ?>"><strong><?= count($stations) ?></strong>Stations</a>
    <a href="<?= tr_dj_h(tr_admin_url('accounts.php')) ?>"><strong><?= count(array_filter($accounts, static fn (array $row): bool => $row['profile_slug'] === null)) ?></strong>Unlinked accounts</a>
</div>
<div class="dj-auth-panel">
    <h2>Get a DJ ready</h2>
    <ol><li>Select their AzuraCast username under DJs (manual IDs remain available).</li><li>Create their public profile, then link it to their website account.</li><li>Assign their station and select its DJ username for scheduling.</li></ol>
    <div class="dj-admin-actions"><a href="<?= tr_dj_h(tr_admin_url('account.php')) ?>">Add a DJ</a><a href="<?= tr_dj_h(tr_admin_url('profile.php')) ?>">Create a profile</a></div>
</div>
<div class="dj-auth-panel">
    <h2>Schedule editing</h2>
    <p><?= $djConfig->scheduleApi() === null ? 'Schedule editing is ready to connect. Configure the private station API key to enable it.' : 'Use a DJ’s Schedule link to add, edit or remove their AzuraCast schedule entries.' ?></p>
    <p>DJ self-service editing will be added later. These controls currently require an administrator.</p>
</div>
<div class="dj-admin-toolbar"><a href="<?= tr_dj_h(tr_admin_url('audit.php')) ?>">View administrator activity</a><a href="<?= tr_dj_h(tr_admin_url('export.php')) ?>" download>Download website records</a></div>
<?php tr_admin_end(); ?>
