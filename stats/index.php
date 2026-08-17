<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/radio.php';
require_once dirname(__DIR__) . '/lib/stats.php';

function tr_stats_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function tr_stats_duration(int|float $seconds): string
{
    $seconds = max(0, (int) round($seconds));
    if ($seconds < 60) {
        return $seconds . 's';
    }

    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return $minutes . 'm';
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;
    if ($hours < 24) {
        return $hours . 'h' . ($remainingMinutes > 0 ? ' ' . $remainingMinutes . 'm' : '');
    }

    $days = intdiv($hours, 24);
    $remainingHours = $hours % 24;
    return $days . 'd' . ($remainingHours > 0 ? ' ' . $remainingHours . 'h' : '');
}

function tr_stats_number(?float $value, int $decimals = 1): string
{
    if ($value === null) {
        return 'n/a';
    }

    return number_format($value, $decimals);
}

function tr_stats_peak_text(array $bucket): string
{
    return is_numeric($bucket['peak_listeners'] ?? null)
        ? number_format((int) $bucket['peak_listeners'])
        : 'n/a';
}

function tr_stats_percent(int $part, int $whole): string
{
    if ($whole <= 0) {
        return 'n/a';
    }

    return number_format(($part / $whole) * 100, 1) . '%';
}

$state = tr_stats_load_state();
$hasHistory = is_int($state['started_at'] ?? null) && (int) $state['sample_count'] > 0;
$nowPlaying = tr_now_playing();

$today = gmdate('Y-m-d');
$weekStartTs = strtotime('monday this week 00:00:00 UTC');
if ($weekStartTs === false) {
    $weekStartTs = strtotime($today . ' 00:00:00 UTC') ?: time();
}
$weekStart = gmdate('Y-m-d', $weekStartTs);

$weekStats = $hasHistory ? tr_stats_period($state, $weekStart, $today) : tr_stats_empty_bucket();
$allStats = is_array($state['totals'] ?? null) ? array_replace(tr_stats_empty_bucket(), $state['totals']) : tr_stats_empty_bucket();

$allDjs = is_array($state['djs'] ?? null) ? $state['djs'] : [];
$weekDjs = is_array($weekStats['djs'] ?? null) ? $weekStats['djs'] : [];
$liveDays = $hasHistory ? tr_stats_live_days($state) : [];
$streak = $hasHistory ? tr_stats_longest_live_day_streak($state) : 0;

uasort($allDjs, static function (array $a, array $b): int {
    $seconds = (int) ($b['seconds'] ?? 0) <=> (int) ($a['seconds'] ?? 0);
    if ($seconds !== 0) {
        return $seconds;
    }
    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

$currentListeners = !empty($nowPlaying['available']) && is_int($nowPlaying['listeners'] ?? null)
    ? $nowPlaying['listeners']
    : (is_int($state['last_sample']['listeners'] ?? null) ? $state['last_sample']['listeners'] : null);
$currentLive = !empty($nowPlaying['available'])
    ? !empty($nowPlaying['is_live'])
    : !empty($state['last_sample']['is_live']);
$currentDj = !empty($nowPlaying['available']) && is_string($nowPlaying['dj'] ?? null)
    ? $nowPlaying['dj']
    : (is_string($state['last_sample']['dj'] ?? null) ? $state['last_sample']['dj'] : null);

$session = is_array($state['session'] ?? null) ? $state['session'] : null;
$lastSampleTs = is_numeric($state['last_sample']['ts'] ?? null) ? (int) $state['last_sample']['ts'] : null;
$sampleFresh = $lastSampleTs !== null && (time() - $lastSampleTs) <= TR_STATS_MAX_SAMPLE_GAP;
$observedLiveSeconds = null;
if ($currentLive && $sampleFresh && is_numeric($session['started_at'] ?? null)) {
    $observedLiveSeconds = max(0, time() - (int) $session['started_at']);
}

$coverage = (int) ($allStats['coverage_seconds'] ?? 0);
$liveSeconds = (int) ($allStats['live_seconds'] ?? 0);
$startedAt = is_numeric($state['started_at'] ?? null) ? (int) $state['started_at'] : null;
$updatedAt = is_numeric($state['updated_at'] ?? null) ? (int) $state['updated_at'] : null;

$title = 'station stats';
$page_stylesheets = ['css/stats.css'];
include dirname(__DIR__) . '/header.php';
?>

<section class="tr-section tr-stats-hero">
    <div class="tr-title"><span class="tr-badge">STATION PULSE</span></div>
    <h1>tilderadio by the numbers</h1>
    <p class="tr-lede">
        Listener and live-DJ activity, measured without depending on track metadata.
        The collector samples the station once per minute and keeps uncertain gaps out of the totals.
    </p>
</section>

<section class="tr-section">
    <div class="tr-title"><span class="tr-badge">RIGHT NOW</span></div>
    <div class="tr-stats-grid tr-stats-grid-current">
        <article>
            <span class="tr-stats-label">listeners</span>
            <strong><?= $currentListeners !== null ? number_format($currentListeners) : 'n/a' ?></strong>
            <small>connected now</small>
        </article>
        <article>
            <span class="tr-stats-label">on air</span>
            <strong><?= $currentLive ? tr_stats_h($currentDj ?: 'live DJ') : 'AutoDJ' ?></strong>
            <small><?= $currentLive && $observedLiveSeconds !== null ? 'observed live for ' . tr_stats_duration($observedLiveSeconds) : ($currentLive ? 'live broadcast' : 'station automation') ?></small>
        </article>
        <article>
            <span class="tr-stats-label">collector</span>
            <strong><?= $sampleFresh ? 'current' : ($hasHistory ? 'stale' : 'not started') ?></strong>
            <small>
                <?php if ($updatedAt !== null): ?>
                    last sample <time datetime="<?= tr_stats_h(gmdate(DATE_ATOM, $updatedAt)) ?>" data-stats-time="<?= $updatedAt ?>"><?= tr_stats_h(gmdate('M j H:i', $updatedAt) . ' UTC') ?></time>
                <?php else: ?>
                    waiting for the first sample
                <?php endif; ?>
            </small>
        </article>
    </div>
</section>

<?php if (!$hasHistory): ?>
    <section class="tr-section">
        <div class="tr-title"><span class="tr-badge">HISTORY</span></div>
        <h2>collection has not started yet</h2>
        <p>
            Once <code>bin/collect-stats.php</code> runs every minute, this page will fill itself in with listener-hours,
            live-DJ airtime, session counts, peaks, active days, and DJ activity.
        </p>
    </section>
<?php else: ?>
    <section class="tr-section">
        <div class="tr-title tr-title-split">
            <span class="tr-badge">THIS WEEK</span>
            <span class="tr-section-tools"><?= tr_stats_h(gmdate('M j', $weekStartTs)) ?> &ndash; now · UTC</span>
        </div>
        <div class="tr-stats-grid">
            <article>
                <span class="tr-stats-label">live airtime</span>
                <strong><?= tr_stats_duration((int) ($weekStats['live_seconds'] ?? 0)) ?></strong>
                <small><?= count($weekDjs) ?> active DJ<?= count($weekDjs) === 1 ? '' : 's' ?></small>
            </article>
            <article>
                <span class="tr-stats-label">live sessions</span>
                <strong><?= number_format((int) ($weekStats['live_sessions'] ?? 0)) ?></strong>
                <small>observed connections</small>
            </article>
            <article>
                <span class="tr-stats-label">peak listeners</span>
                <strong><?= tr_stats_peak_text($weekStats) ?></strong>
                <small>highest minute sample</small>
            </article>
            <article>
                <span class="tr-stats-label">average listeners</span>
                <strong><?= tr_stats_number(tr_stats_average_listeners($weekStats)) ?></strong>
                <small>time-weighted</small>
            </article>
            <article>
                <span class="tr-stats-label">listener-hours</span>
                <strong><?= tr_stats_number(tr_stats_listener_hours($weekStats)) ?></strong>
                <small>listeners × observed time</small>
            </article>
            <article>
                <span class="tr-stats-label">live share</span>
                <strong><?= tr_stats_percent((int) ($weekStats['live_seconds'] ?? 0), (int) ($weekStats['coverage_seconds'] ?? 0)) ?></strong>
                <small>of measured station time</small>
            </article>
        </div>
    </section>

    <section class="tr-section">
        <div class="tr-title"><span class="tr-badge">SINCE COLLECTION STARTED</span></div>
        <div class="tr-stats-grid">
            <article>
                <span class="tr-stats-label">hours liberated from AutoDJ</span>
                <strong><?= tr_stats_number($liveSeconds / 3600) ?></strong>
                <small><?= tr_stats_percent($liveSeconds, $coverage) ?> of measured time</small>
            </article>
            <article>
                <span class="tr-stats-label">live sessions</span>
                <strong><?= number_format((int) ($allStats['live_sessions'] ?? 0)) ?></strong>
                <small><?= count($allDjs) ?> distinct DJ<?= count($allDjs) === 1 ? '' : 's' ?></small>
            </article>
            <article>
                <span class="tr-stats-label">listener-hours</span>
                <strong><?= tr_stats_number(tr_stats_listener_hours($allStats)) ?></strong>
                <small><?= tr_stats_number(tr_stats_average_listeners($allStats)) ?> average listeners</small>
            </article>
            <article>
                <span class="tr-stats-label">peak listeners</span>
                <strong><?= tr_stats_peak_text($allStats) ?></strong>
                <small>highest observed minute</small>
            </article>
            <article>
                <span class="tr-stats-label">days with live radio</span>
                <strong><?= number_format(count($liveDays)) ?></strong>
                <small>at least one live sample</small>
            </article>
            <article>
                <span class="tr-stats-label">longest live-day streak</span>
                <strong><?= number_format($streak) ?> day<?= $streak === 1 ? '' : 's' ?></strong>
                <small>consecutive UTC days</small>
            </article>
        </div>
    </section>

    <?php if ($allDjs): ?>
        <section class="tr-section">
            <div class="tr-title"><span class="tr-badge">DJ ACTIVITY</span></div>
            <div class="tr-stats-table-wrap">
                <table class="tr-stats-table">
                    <thead>
                        <tr>
                            <th>DJ</th>
                            <th>live time</th>
                            <th>sessions</th>
                            <th>peak</th>
                            <th>avg listeners</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allDjs as $slug => $dj): ?>
                            <?php
                            $name = is_string($dj['name'] ?? null) && trim($dj['name']) !== '' ? trim($dj['name']) : (string) $slug;
                            $djObserved = (int) ($dj['listener_observed_seconds'] ?? 0);
                            $djAverage = $djObserved > 0 ? (float) ($dj['listener_seconds'] ?? 0) / $djObserved : null;
                            ?>
                            <tr>
                                <td><a href="<?= tr_stats_h(asset('djs/?dj=' . rawurlencode((string) $slug))) ?>"><?= tr_stats_h($name) ?></a></td>
                                <td><?= tr_stats_duration((int) ($dj['seconds'] ?? 0)) ?></td>
                                <td><?= number_format((int) ($dj['sessions'] ?? 0)) ?></td>
                                <td><?= is_numeric($dj['peak_listeners'] ?? null) ? number_format((int) $dj['peak_listeners']) : 'n/a' ?></td>
                                <td><?= tr_stats_number($djAverage) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="tr-section">
        <div class="tr-title"><span class="tr-badge">MEASUREMENT NOTES</span></div>
        <ul class="tr-stats-notes">
            <li>Song titles are not used for these statistics.</li>
            <li>Listener totals and live-DJ state are sampled once per collector run.</li>
            <li>Gaps longer than <?= (int) (TR_STATS_MAX_SAMPLE_GAP / 60) ?> minutes are treated as missing data and do not add airtime or listener-hours.</li>
            <li>A DJ change, reconnect, or collection gap starts a new observed live session.</li>
            <li>Daily and weekly boundaries use UTC.</li>
            <li>Collection began <?= $startedAt !== null ? tr_stats_h(gmdate('F j, Y H:i', $startedAt) . ' UTC') : 'recently' ?>.</li>
        </ul>
    </section>
<?php endif; ?>

<script>
document.querySelectorAll('[data-stats-time]').forEach(function (node) {
    var timestamp = Number(node.getAttribute('data-stats-time')) * 1000;
    if (!Number.isFinite(timestamp)) return;
    var date = new Date(timestamp);
    node.textContent = date.toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZoneName: 'short'
    });
    node.title = date.toUTCString();
});
</script>

<?php include dirname(__DIR__) . '/footer.php'; ?>
