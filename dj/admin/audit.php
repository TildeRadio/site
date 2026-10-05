<?php

declare(strict_types=1);

require __DIR__ . '/_admin.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    tr_dj_error(405, 'This page accepts GET requests only.');
}
tr_admin_begin('Administrator activity', 'audit');
?>
<p class="dj-admin-help">The latest 200 changes, recorded with the verified administrator account and UTC time.</p>
<div class="dj-admin-table-wrap"><table><caption>Administrator changes</caption><thead><tr><th scope="col">Time (UTC)</th><th scope="col">Administrator</th><th scope="col">Action</th><th scope="col">Record</th></tr></thead><tbody>
<?php foreach ($djStore->audit() as $event) : ?><tr><td><?= tr_dj_h(gmdate('Y-m-d H:i:s', $event['created_at'])) ?></td><td><?= $event['actor_station_id'] ?>:<?= $event['actor_streamer_id'] ?></td><td><?= tr_dj_h($event['action']) ?></td><td><?= tr_dj_h($event['target']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php tr_admin_end(); ?>
