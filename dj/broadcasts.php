<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\PublicBroadcasts;

define('TR_DJ_SELF_SERVICE', true);
require __DIR__ . '/admin/_admin.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    tr_dj_error(405, 'Use the broadcast editor to make changes.');
}
require_once dirname(__DIR__) . '/lib/radio.php';
$all = $djStore->isAdministrator($adminIdentity);
$slug = $djAccount['profile_slug'];
$deleted = $all && ($_GET['deleted'] ?? '') === '1';
$episodes = PublicBroadcasts::merge(tr_episode_source_archive()['episodes'], $djStore->broadcastEdits(), true);
$episodes = array_values(array_filter($episodes, static fn (array $row): bool =>
    ($all || ($slug !== null && PublicBroadcasts::slug((string) $row['dj_slug']) === $slug)) && (bool) ($row['_deleted'] ?? false) === $deleted
));
$page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;
$count = count($episodes);
$episodes = array_slice($episodes, ($page - 1) * 30, 30);
tr_admin_begin($all ? 'All sets / broadcasts' : 'My sets / broadcasts', 'broadcasts');
?>
<p>Edit listing metadata for past, upcoming or live sets. Corrections appear on the website and its public API.</p>
<div class="dj-admin-actions">
    <?php if ($all || $slug !== null) : ?><a href="<?= tr_dj_h($djConfig->path('broadcast.php')) ?>">Add a set / broadcast</a><?php endif; ?>
    <?php if ($all) : ?><a href="<?= tr_dj_h($djConfig->path('broadcasts.php?deleted=' . ($deleted ? '0' : '1'))) ?>"><?= $deleted ? 'Active listings' : 'Deleted listings' ?></a><?php endif; ?>
</div>
<?php if (!$all && $slug === null) : ?><p>Ask an administrator to link your account to a profile before adding or editing broadcasts.</p><?php endif; ?>
<div class="dj-admin-table-wrap"><table class="dj-admin-table"><thead><tr><th>Set</th><th>DJ</th><th>Start (UTC)</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($episodes as $episode) : ?>
    <tr><td><?= tr_dj_h(tr_episode_title($episode)) ?></td><td><?= tr_dj_h((string) ($episode['dj'] ?? $episode['dj_slug'])) ?></td><td><?= tr_dj_h(gmdate('Y-m-d H:i', (int) $episode['started_at'])) ?></td><td><?= $deleted ? 'Deleted' : tr_dj_h((string) ($episode['status'] ?? (!empty($episode['is_live']) ? 'live' : 'recorded'))) ?></td><td><a href="<?= tr_dj_h($djConfig->path('broadcast.php?id=' . (int) $episode['id'])) ?>"><?= $deleted ? 'Restore / edit' : 'Edit / delete' ?></a></td></tr>
<?php endforeach; ?>
<?php if ($episodes === []) : ?><tr><td colspan="5">No matching broadcasts.</td></tr><?php endif; ?>
</tbody></table></div>
<nav aria-label="Broadcast pages">
<?php if ($page > 1) : ?><a href="<?= tr_dj_h($djConfig->path('broadcasts.php?deleted=' . (int) $deleted . '&page=' . ($page - 1))) ?>">Previous</a><?php endif; ?>
<?php if ($page * 30 < $count) : ?><a href="<?= tr_dj_h($djConfig->path('broadcasts.php?deleted=' . (int) $deleted . '&page=' . ($page + 1))) ?>">Next</a><?php endif; ?>
</nav>
<?php tr_admin_end(); ?>
