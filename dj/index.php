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
$assignedStations = [];
foreach ($djAccount['assignments'] as $stationId => $streamerId) {
    $stationRecord = $djStore->station($stationId);
    if ($stationRecord !== null && $stationRecord['enabled'] && !$stationRecord['deleted']) {
        $assignedStations[$stationId] = $stationRecord;
    }
}
$title = 'DJ booth';
$page_stylesheets = ['css/dj-auth.css'];
require dirname(__DIR__) . '/header.php';
?>
<section class="tr-section dj-booth" aria-labelledby="dj-booth-title">
    <div class="dj-booth-top">
        <div><span class="tr-badge">DJ booth</span><h1 class="tr-title" id="dj-booth-title">Hello, <?= tr_dj_h($identity['display_name'] ?: $identity['username']) ?>.</h1><p class="dj-auth-intro">Your shows, airtime and broadcast listings.</p></div>
        <form method="post" action="<?= tr_dj_h($djConfig->path('logout.php')) ?>" data-tr-dj-auth>
            <input type="hidden" name="csrf" value="<?= tr_dj_h($_SESSION['csrf']) ?>">
            <button type="submit" class="dj-auth-button dj-auth-button-secondary">Sign out</button>
        </form>
    </div>
    <div class="dj-booth-account"><span>Signed in as <strong><?= tr_dj_h($identity['username']) ?></strong></span><span class="dj-status"><?= $isAdministrator ? 'Administrator' : 'DJ' ?></span></div>
    <p class="dj-auth-help"><a href="<?= tr_dj_h(asset('help/?topic=website-map')) ?>">Help &amp; guides: find the right control for your next step &rarr;</a></p>
    <?php if ($isAdministrator) : ?>
        <aside class="dj-booth-admin" aria-label="Administrator access"><p>Manage DJs, profiles and station assignments.</p><a href="<?= tr_dj_h($djConfig->path('admin/')) ?>">Open administration &rarr;</a></aside>
    <?php endif; ?>
    <nav class="dj-booth-cards" aria-label="DJ controls">
        <a class="dj-booth-card" href="<?= tr_dj_h($djConfig->path('broadcasts.php')) ?>"><span class="tr-badge">Sets &amp; broadcasts</span><strong>Manage your listings</strong><span>Fix titles, metadata and track lists, or add a missing set.</span><span class="dj-booth-card-action">Manage sets / broadcasts &rarr;</span></a>
        <?php if ($slug !== null) : ?>
            <a class="dj-booth-card" href="<?= tr_dj_h($djConfig->path('profile.php')) ?>"><span class="tr-badge">Your profile</span><strong>Make it your own</strong><span>Update your biography, links and recurring show details.</span><span class="dj-booth-card-action">Edit your profile and show &rarr;</span></a>
        <?php else : ?>
            <div class="dj-booth-card dj-booth-card-pending"><span class="tr-badge">Your profile</span><strong>Link your show profile</strong><span>Your login is ready. Ask the station administrator to link your account to your show profile.</span><span class="dj-status">Assignment needed</span></div>
        <?php endif; ?>
        <a class="dj-booth-card" href="#dj-booth-schedules"><span class="tr-badge">Your airtime</span><strong>Plan your next show</strong><span>Add, edit or remove bookings in your assigned station schedules.</span><span class="dj-booth-card-action">View assigned schedules &rarr;</span></a>
        <?php if ($djConfig->carrier() !== null) : ?>
            <a class="dj-booth-card" href="<?= tr_dj_h($djConfig->path('plans.php')) ?>"><span class="tr-badge">Show preparation</span><strong>Build your playlist</strong><span>Prepare titles and song order for an upcoming broadcast.</span><span class="dj-booth-card-action">Prepare a show &rarr;</span></a>
            <a class="dj-booth-card" href="<?= tr_dj_h($djConfig->path('carrier.php')) ?>"><span class="tr-badge">Live controls</span><strong>Your live show</strong><span>Advance songs, manage listener queues and link your IRC account.</span><span class="dj-booth-card-action">Open live controls &rarr;</span></a>
            <a class="dj-booth-card" href="<?= tr_dj_h($djConfig->path('recordings.php')) ?>"><span class="tr-badge">Recordings</span><strong>Review your recordings</strong><span>Attach approved recordings to your broadcast listings.</span><span class="dj-booth-card-action">Review recordings &rarr;</span></a>
        <?php endif; ?>
    </nav>
    <div class="dj-booth-columns">
        <section class="dj-booth-panel" aria-labelledby="dj-booth-recent">
            <div class="dj-section-heading"><h2 id="dj-booth-recent">Recent broadcasts</h2><?php if ($isAdministrator || $slug !== null) : ?><a class="dj-action-link" href="<?= tr_dj_h($djConfig->path('broadcast.php')) ?>">Add a set &rarr;</a><?php endif; ?></div>
            <?php if ($episodes === []) : ?>
                <div class="dj-empty-state"><p><?= $slug === null ? 'Link a DJ profile to see its recent broadcasts here.' : 'No archived broadcasts are available for your profile yet.' ?></p><a href="<?= tr_dj_h($djConfig->path('broadcasts.php')) ?>">Open broadcast listings &rarr;</a></div>
            <?php else : ?>
                <ul class="dj-booth-episodes">
                    <?php foreach ($episodes as $episode) : ?>
                        <li><div><a class="dj-booth-episode-title" href="<?= tr_dj_h(asset('episodes/?id=' . rawurlencode((string) ($episode['id'] ?? '')))) ?>"><?= tr_dj_h(tr_episode_title($episode)) ?></a><time datetime="<?= tr_dj_h(gmdate('Y-m-d\TH:i:s\Z', (int) $episode['started_at'])) ?>"><?= tr_dj_h(gmdate('M j, Y · H:i', (int) $episode['started_at'])) ?> UTC</time></div><a class="dj-action-link" href="<?= tr_dj_h($djConfig->path('broadcast.php?id=' . (int) $episode['id'])) ?>" aria-label="<?= tr_dj_h('Edit listing: ' . tr_episode_title($episode)) ?>">Edit listing</a></li>
                    <?php endforeach; ?>
                </ul>
                <a class="dj-booth-panel-footer" href="<?= tr_dj_h($djConfig->path('broadcasts.php')) ?>">View all broadcast listings &rarr;</a>
            <?php endif; ?>
        </section>
        <section class="dj-booth-panel" id="dj-booth-schedules" aria-labelledby="dj-booth-schedules-title">
            <div class="dj-section-heading"><h2 id="dj-booth-schedules-title">Your assigned schedules</h2><span class="dj-status"><?= count($assignedStations) ?> <?= count($assignedStations) === 1 ? 'station' : 'stations' ?></span></div>
            <?php if ($assignedStations === []) : ?>
                <div class="dj-empty-state"><p>No active stations are assigned yet. Ask an administrator to assign a station before booking airtime.</p></div>
            <?php else : ?>
                <p class="dj-booth-panel-intro">Schedule times use each station’s timezone. Overlapping bookings are rejected.</p>
                <ul class="dj-booth-stations">
                    <?php foreach ($assignedStations as $stationId => $stationRecord) : ?>
                        <li><strong><?= tr_dj_h($stationRecord['name']) ?></strong><a class="dj-action-link" href="<?= tr_dj_h($djConfig->path('schedule.php?source_station=' . $identity['station_id'] . '&source_streamer=' . $identity['streamer_id'] . '&station=' . $stationId)) ?>">Edit <?= tr_dj_h($stationRecord['name']) ?> schedule &rarr;</a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="dj-booth-panel-footer"><a href="<?= tr_dj_h(asset('schedule/')) ?>">Station schedule &rarr;</a><?php if ($slug !== null) : ?><a href="<?= tr_dj_h(asset('djs/?dj=' . rawurlencode($slug))) ?>">Your public DJ profile &rarr;</a><?php endif; ?></div>
        </section>
    </div>
</section>
<?php require dirname(__DIR__) . '/footer.php'; ?>
