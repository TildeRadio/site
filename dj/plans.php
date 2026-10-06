<?php

declare(strict_types=1);

require __DIR__ . '/_carrier.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    tr_dj_error(405, 'This page accepts GET requests only.');
}
$plans = $djStore->plans($adminIdentity);
tr_admin_begin('Prepare a show', 'plans');
?>
<p>Prepare titles and an ordered song list for an upcoming broadcast. Preparation does not reserve airtime: book your assigned schedule first. Carrier selects one matching preparation when your verified DJ account goes live within its time window.</p>
<p><a class="dj-auth-button" href="<?= tr_dj_h($djConfig->path('plan.php')) ?>">Prepare a new show</a> <a href="<?= tr_dj_h($djConfig->path('#dj-booth-schedules')) ?>">Book airtime</a></p>
<div class="dj-broadcast-cards">
<?php foreach ($plans as $plan) : ?>
<article class="dj-broadcast-card"><div><h2><a href="<?= tr_dj_h($djConfig->path('plan.php?id=' . $plan['id'])) ?>"><?= tr_dj_h($plan['show']['episode'] ?: 'Prepared show #' . $plan['id']) ?></a></h2><p><?= tr_dj_h($plan['slug']) ?> · <?= tr_dj_h(gmdate('M j, Y H:i', $plan['starts_at'])) ?>–<?= tr_dj_h(gmdate('H:i', $plan['ends_at'])) ?> UTC</p><p><?= count($plan['tracks']) ?> planned songs</p></div><a href="<?= tr_dj_h($djConfig->path('plan.php?copy=' . $plan['id'])) ?>">Copy for another show</a></article>
<?php endforeach; ?>
<?php if ($plans === []) : ?><p>No prepared shows yet. A show without a prepared playlist uses Carrier’s existing metadata capture.</p><?php endif; ?>
</div>
<?php tr_admin_end(); ?>
