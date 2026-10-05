<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\Input;
use TildeRadio\Site\Admin\Problem;

require __DIR__ . '/_admin.php';
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = tr_admin_attempt(function () use ($djStore, $adminIdentity): void {
        if (Input::text($_POST['action'] ?? '', 'action', 30, true) !== 'import') {
            throw new Problem('Choose a valid profile action.');
        }
        $djStore->importProfiles($adminIdentity, dirname(__DIR__, 2));
        tr_admin_finish('profiles.php', 'New repository profiles imported. Existing edits and deleted records were preserved.');
    });
}
$deleted = ($_GET['deleted'] ?? '') === '1';
tr_admin_begin($deleted ? 'Deleted DJ profiles' : 'DJ profiles', 'profiles', $error);
?>
<div class="dj-admin-toolbar"><a href="<?= tr_dj_h(tr_admin_url('profile.php')) ?>">Create a profile &rarr;</a><a href="<?= tr_dj_h(tr_admin_url('profiles.php' . ($deleted ? '' : '?deleted=1'))) ?>"><?= $deleted ? 'Active records' : 'Deleted records' ?></a></div>
<div class="dj-admin-table-wrap"><table><caption>Public profile records</caption><thead><tr><th scope="col">Profile</th><th scope="col">Name</th><th scope="col">Visibility</th><th scope="col">Actions</th></tr></thead><tbody>
<?php foreach ($djStore->profiles($deleted) as $profile) : ?>
    <tr><td><?= tr_dj_h($profile['slug']) ?></td><td><?= tr_dj_h(is_string($profile['data']['name'] ?? null) ? $profile['data']['name'] : $profile['slug']) ?></td><td><?= $profile['deleted'] ? 'Deleted' : ($profile['published'] ? 'Published' : 'Unpublished') ?></td><td><a href="<?= tr_dj_h(tr_admin_url('profile.php?slug=' . rawurlencode($profile['slug']))) ?>"><?= $deleted ? 'Restore / edit' : 'Edit' ?></a><?php if ($profile['published'] && !$profile['deleted']) : ?> · <a href="<?= tr_dj_h($djConfig->path('../djs/?dj=' . rawurlencode($profile['slug']))) ?>">View public page</a><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<details><summary>Import newly deployed profiles</summary><p>This adds JSON profiles deployed through Git that are not already in website storage. It never overwrites a browser edit or recreates a deleted record.</p>
<form method="post" action="<?= tr_dj_h(tr_admin_url('profiles.php')) ?>" data-tr-dj-auth><?php tr_admin_csrf(); ?><input type="hidden" name="action" value="import"><button type="submit" class="dj-auth-button">Import new profiles</button></form></details>
<?php tr_admin_end(); ?>
