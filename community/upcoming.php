<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Admin/PublicProfiles.php';
require_once dirname(__DIR__) . '/lib/Admin/PublicPlans.php';
$title = 'Upcoming show previews';
require dirname(__DIR__) . '/header.php';
?>
<section class="tr-section"><h1>Upcoming show previews</h1><p>Show information published by DJs. Check the <a href="<?= htmlspecialchars(asset('schedule/'), ENT_QUOTES, 'UTF-8') ?>">station schedule</a> for booked airtime.</p>
<?php foreach (\TildeRadio\Site\Admin\PublicPlans::upcoming() as $plan) : ?><article><h2><?= htmlspecialchars($plan['title'] ?: $plan['slug'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2><p><?= htmlspecialchars($plan['slug'] . ' · ' . gmdate('M j, Y H:i', $plan['starts_at']) . ' UTC', ENT_QUOTES, 'UTF-8') ?></p><p><?= htmlspecialchars($plan['topic'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p></article><?php endforeach; ?>
</section>
<?php require dirname(__DIR__) . '/footer.php'; ?>
