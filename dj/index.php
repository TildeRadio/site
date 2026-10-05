<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    tr_dj_error(405, 'This page accepts GET requests only.');
}
$identity = tr_dj_identity($djService);
if ($identity === null) {
    tr_dj_redirect($djConfig, 'login.php');
}
$isAdministrator = $djStore->isAdministrator($identity);
$slug = $djAccount['profile_slug'];
require_once dirname(__DIR__) . '/lib/radio.php';
$episodes = $slug !== null ? tr_episodes_for_dj($slug, 20) : [];
$title = 'DJ booth';
$page_stylesheets = ['css/dj-auth.css'];
require dirname(__DIR__) . '/header.php';
?>
<section class="tr-section dj-booth" aria-labelledby="dj-booth-title">
    <div class="dj-booth-top">
        <div><span class="tr-badge"><?= $isAdministrator ? 'Administrator' : 'DJ booth' ?></span><h1 class="tr-title" id="dj-booth-title">Hello, <?= tr_dj_h($identity['display_name'] ?: $identity['username']) ?>.</h1><p class="dj-auth-intro">Signed in as <?= tr_dj_h($identity['username']) ?>.</p></div>
        <form method="post" action="<?= tr_dj_h($djConfig->path('logout.php')) ?>" data-tr-dj-auth>
            <input type="hidden" name="csrf" value="<?= tr_dj_h($_SESSION['csrf']) ?>">
            <button type="submit" class="dj-auth-button dj-auth-button-secondary">Sign out</button>
        </form>
    </div>
    <?php if ($isAdministrator) : ?>
        <div class="dj-auth-notice"><p>Your station administrator account is recognized.</p><a href="<?= tr_dj_h($djConfig->path('admin/')) ?>">Open administration &rarr;</a></div>
    <?php endif; ?>
    <div class="dj-booth-links"><a href="<?= tr_dj_h($djConfig->path('broadcasts.php')) ?>">Manage sets / broadcasts</a><?php if ($slug !== null) : ?><a href="<?= tr_dj_h($djConfig->path('profile.php')) ?>">Edit your profile and show</a><?php endif; ?></div>
    <h2>Your assigned schedules</h2>
    <ul class="dj-booth-episodes">
        <?php foreach ($djAccount['assignments'] as $stationId => $streamerId) : ?>
            <?php $stationRecord = $djStore->station($stationId); if ($stationRecord === null || !$stationRecord['enabled'] || $stationRecord['deleted']) { continue; } ?>
            <li><a href="<?= tr_dj_h($djConfig->path('schedule.php?source_station=' . $identity['station_id'] . '&source_streamer=' . $identity['streamer_id'] . '&station=' . $stationId)) ?>">Edit <?= tr_dj_h($stationRecord['name']) ?> schedule</a></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($slug === null) : ?>
        <div class="dj-auth-panel"><h2>Your show profile</h2><p>Your login is ready. Ask the station administrator to link your account to your show profile.</p></div>
    <?php else : ?>
        <div class="dj-booth-links"><a href="<?= tr_dj_h(asset('djs/?dj=' . rawurlencode($slug))) ?>">Your public DJ profile</a><a href="<?= tr_dj_h(asset('schedule/')) ?>">Station schedule</a></div>
        <h2>Recent broadcasts</h2>
        <?php if ($episodes === []) : ?>
            <p>No archived broadcasts are available for your profile yet.</p>
        <?php else : ?>
            <ul class="dj-booth-episodes">
                <?php foreach ($episodes as $episode) : ?>
                    <li><a href="<?= tr_dj_h(asset('episodes/?id=' . rawurlencode((string) ($episode['id'] ?? '')))) ?>"><?= tr_dj_h(tr_episode_title($episode)) ?></a> · <a href="<?= tr_dj_h($djConfig->path('broadcast.php?id=' . (int) $episode['id'])) ?>">Edit listing</a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/footer.php'; ?>
