<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\CarrierClient;
use TildeRadio\Site\Admin\CarrierSync;

require __DIR__ . '/_admin.php';
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = tr_admin_attempt(function (): void {
        global $djConfig, $djStore;
        (new CarrierSync($djConfig, $djStore))->run();
        tr_admin_finish('carrier.php', 'Website assignments, preparations and published corrections synchronized.');
    });
}
$health = [];
$readError = tr_admin_attempt(function () use (&$health): void {
    global $djConfig;
    $health = (new CarrierClient($djConfig))->call(['op' => 'health']);
});
tr_admin_begin('Carrier integration', 'carrier', $error ?? $readError);
?>
<p>Synchronization sends website permissions, prepared shows and listing corrections. Captured tracks, statistics and recordings remain intact. Live controls require a complete snapshot from the last two minutes.</p>
<form method="post" data-tr-dj-auth><?php tr_admin_csrf(); ?><button class="dj-auth-button" type="submit">Synchronize now</button></form>
<dl><dt>Last successful synchronization</dt><dd><?= !empty($health['last_sync_at']) ? tr_dj_h(gmdate('Y-m-d H:i:s', $health['last_sync_at']) . ' UTC') : 'Not synchronized' ?></dd><dt>Accounts / prepared shows / corrections</dt><dd><?= (int) ($health['accounts'] ?? 0) ?> / <?= (int) ($health['plans'] ?? 0) ?> / <?= (int) ($health['corrections'] ?? 0) ?></dd><dt>Active broadcast</dt><dd><?= (int) ($health['session_id'] ?? 0) ?: 'None' ?></dd><dt>Radio data age</dt><dd><?= isset($health['radio_age_seconds']) ? (int) $health['radio_age_seconds'] . ' seconds' : 'Unavailable' ?></dd></dl>
<p><a href="<?= tr_dj_h($djConfig->path('carrier.php')) ?>">Live controls and IRC account revocation</a> · <a href="<?= tr_dj_h($djConfig->path('plans.php')) ?>">All prepared shows</a> · <a href="<?= tr_dj_h($djConfig->path('recordings.php')) ?>">Recording review</a></p>
<h2>Recent integration activity</h2>
<?php foreach ($health['actions'] ?? [] as $action) : ?><p><?= tr_dj_h(gmdate('Y-m-d H:i:s', $action['created_at']) . ' UTC · ' . $action['event_type'] . ' · ' . $action['data_json']) ?></p><?php endforeach; ?>
<?php tr_admin_end(); ?>
