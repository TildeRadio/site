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
<div class="dj-broadcast-toolbar">
    <div><p>Edit listing metadata for past, upcoming or live sets. Corrections appear on the website and its public API.</p><p class="dj-broadcast-count"><?= $count ?> <?= $count === 1 ? 'listing' : 'listings' ?> · <?= $deleted ? 'Deleted' : 'Active' ?> · Page <?= $page ?></p></div>
    <div class="dj-admin-actions">
        <?php if ($all || $slug !== null) : ?><a class="dj-auth-button" href="<?= tr_dj_h($djConfig->path('broadcast.php')) ?>">Add a set / broadcast</a><?php endif; ?>
        <?php if ($all) : ?><a class="dj-action-link" href="<?= tr_dj_h($djConfig->path('broadcasts.php?deleted=' . ($deleted ? '0' : '1'))) ?>"><?= $deleted ? 'Active listings' : 'Deleted listings' ?></a><?php endif; ?>
    </div>
</div>
<?php if (!$all && $slug === null) : ?><div class="dj-empty-state"><p>Ask an administrator to link your account to a profile before adding or editing broadcasts.</p></div><?php endif; ?>
<ul class="dj-broadcast-list" aria-label="<?= $deleted ? 'Deleted broadcasts' : 'Broadcast listings' ?>">
<?php foreach ($episodes as $episode) : ?>
    <?php
    $status = $deleted ? 'deleted' : (string) ($episode['status'] ?? (!empty($episode['is_live']) ? 'live' : 'recorded'));
    $statusLabel = ['deleted' => 'Deleted', 'live' => 'Live', 'planned' => 'Planned', 'recorded' => 'Recorded'][$status] ?? $status;
    ?>
    <li class="dj-broadcast-row">
        <div class="dj-broadcast-info"><h2><?= tr_dj_h(tr_episode_title($episode)) ?></h2><div class="dj-broadcast-meta"><span><?= tr_dj_h((string) ($episode['dj'] ?? $episode['dj_slug'])) ?></span><time datetime="<?= tr_dj_h(gmdate('Y-m-d\TH:i:s\Z', (int) $episode['started_at'])) ?>"><?= tr_dj_h(gmdate('M j, Y · H:i', (int) $episode['started_at'])) ?> UTC</time></div></div>
        <span class="dj-status<?= $status === 'live' ? ' dj-status-live' : '' ?>"><?= tr_dj_h($statusLabel) ?></span>
        <a class="dj-action-link" href="<?= tr_dj_h($djConfig->path('broadcast.php?id=' . (int) $episode['id'])) ?>" aria-label="<?= tr_dj_h(($deleted ? 'Restore or edit: ' : 'Edit or delete: ') . tr_episode_title($episode)) ?>"><?= $deleted ? 'Restore / edit' : 'Edit / delete' ?></a>
    </li>
<?php endforeach; ?>
<?php if ($episodes === []) : ?><li class="dj-empty-state"><p>No matching broadcasts.</p></li><?php endif; ?>
</ul>
<nav class="dj-broadcast-pagination" aria-label="Broadcast pages">
<?php if ($page > 1) : ?><a class="dj-action-link" href="<?= tr_dj_h($djConfig->path('broadcasts.php?deleted=' . (int) $deleted . '&page=' . ($page - 1))) ?>">&larr; Previous</a><?php endif; ?>
<?php if ($page * 30 < $count) : ?><a class="dj-action-link" href="<?= tr_dj_h($djConfig->path('broadcasts.php?deleted=' . (int) $deleted . '&page=' . ($page + 1))) ?>">Next &rarr;</a><?php endif; ?>
</nav>
<?php tr_admin_end(); ?>
