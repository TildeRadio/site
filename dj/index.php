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
        <h1 id="dj-booth-title">DJ booth</h1>
        <form method="post" action="<?= tr_dj_h($djConfig->path('logout.php')) ?>" data-tr-dj-auth>
            <input type="hidden" name="csrf" value="<?= tr_dj_h($_SESSION['csrf']) ?>">
            <button type="submit" class="dj-auth-button dj-auth-button-secondary">Sign out</button>
        </form>
    </div>
    <p class="dj-booth-account">Signed in as <strong><?= tr_dj_h($identity['username']) ?></strong> · <?= $isAdministrator ? 'Administrator' : 'DJ' ?><?php if ($isAdministrator) : ?> · <a href="<?= tr_dj_h($djConfig->path('admin/')) ?>">Administration</a><?php endif; ?></p>
    <nav aria-label="DJ controls">
        <ul class="tr-links dj-booth-links">
            <li><a href="<?= tr_dj_h($djConfig->path('broadcasts.php')) ?>">Sets / broadcasts</a><span>Edit titles and tracks, or add a missing set.</span></li>
            <?php if ($slug !== null) : ?>
                <li><a href="<?= tr_dj_h($djConfig->path('profile.php')) ?>">My profile</a><span>Biography, links and recurring show details.</span></li>
            <?php else : ?>
                <li><span>No profile linked. Ask an administrator to link your DJ profile.</span></li>
            <?php endif; ?>
            <li><a href="#dj-booth-schedules">My schedules</a><span>Book or change airtime.</span></li>
            <?php if ($djConfig->carrier() !== null) : ?>
                <li><a href="<?= tr_dj_h($djConfig->path('plans.php')) ?>">Prepare a show</a><span>Episode details and playlist.</span></li>
                <li><a href="<?= tr_dj_h($djConfig->path('carrier.php')) ?>">Live / IRC</a><span>Songs, requests and IRC account links.</span></li>
                <li><a href="<?= tr_dj_h($djConfig->path('recordings.php')) ?>">Recordings</a><span>Review available audio.</span></li>
            <?php endif; ?>
            <li><a href="<?= tr_dj_h(asset('help/')) ?>">Help</a><span>Streaming, website pages and Carrier commands.</span></li>
        </ul>
    </nav>
    <div class="dj-booth-columns">
        <section class="dj-booth-panel" aria-labelledby="dj-booth-recent">
            <div class="dj-section-heading"><h2 id="dj-booth-recent">Recent broadcasts</h2><?php if ($isAdministrator || $slug !== null) : ?><a class="dj-action-link" href="<?= tr_dj_h($djConfig->path('broadcast.php')) ?>">Add a set</a><?php endif; ?></div>
            <?php if ($episodes === []) : ?>
                <div class="dj-empty-state"><p><?= $slug === null ? 'Link a DJ profile to see its recent broadcasts here.' : 'No broadcasts for this profile.' ?></p><a href="<?= tr_dj_h($djConfig->path('broadcasts.php')) ?>">Broadcast listings</a></div>
            <?php else : ?>
                <ul class="dj-booth-episodes">
                    <?php foreach ($episodes as $episode) : ?>
                        <li><div><a class="dj-booth-episode-title" href="<?= tr_dj_h(asset('episodes/?id=' . rawurlencode((string) ($episode['id'] ?? '')))) ?>"><?= tr_dj_h(tr_episode_title($episode)) ?></a><time datetime="<?= tr_dj_h(gmdate('Y-m-d\TH:i:s\Z', (int) $episode['started_at'])) ?>"><?= tr_dj_h(gmdate('M j, Y · H:i', (int) $episode['started_at'])) ?> UTC</time></div><a class="dj-action-link" href="<?= tr_dj_h($djConfig->path('broadcast.php?id=' . (int) $episode['id'])) ?>" aria-label="<?= tr_dj_h('Edit listing: ' . tr_episode_title($episode)) ?>">Edit listing</a></li>
                    <?php endforeach; ?>
                </ul>
                <a class="dj-booth-panel-footer" href="<?= tr_dj_h($djConfig->path('broadcasts.php')) ?>">All broadcasts</a>
            <?php endif; ?>
        </section>
        <section class="dj-booth-panel" id="dj-booth-schedules" aria-labelledby="dj-booth-schedules-title">
            <div class="dj-section-heading"><h2 id="dj-booth-schedules-title">Your assigned schedules</h2><span class="dj-status"><?= count($assignedStations) ?> <?= count($assignedStations) === 1 ? 'station' : 'stations' ?></span></div>
            <?php if ($assignedStations === []) : ?>
                <div class="dj-empty-state"><p>No station assigned. Ask an administrator to assign one before booking.</p></div>
            <?php else : ?>
                <p class="dj-booth-panel-intro">Schedule times use each station’s timezone. Overlapping bookings are rejected.</p>
                <ul class="dj-booth-stations">
                    <?php foreach ($assignedStations as $stationId => $stationRecord) : ?>
                        <li><strong><?= tr_dj_h($stationRecord['name']) ?></strong><a class="dj-action-link" href="<?= tr_dj_h($djConfig->path('schedule.php?source_station=' . $identity['station_id'] . '&source_streamer=' . $identity['streamer_id'] . '&station=' . $stationId)) ?>">Edit <?= tr_dj_h($stationRecord['name']) ?> schedule</a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="dj-booth-panel-footer"><a href="<?= tr_dj_h(asset('schedule/')) ?>">Station schedule</a><?php if ($slug !== null) : ?><a href="<?= tr_dj_h(asset('djs/?dj=' . rawurlencode($slug))) ?>">Public profile</a><?php endif; ?></div>
        </section>
    </div>
</section>
<?php require dirname(__DIR__) . '/footer.php'; ?>
