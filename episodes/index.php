<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/radio.php';
require_once dirname(__DIR__) . '/lib/api.php';

function tr_episodes_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function tr_episodes_duration(?int $seconds): string
{
    if ($seconds === null || $seconds < 1) {
        return '';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }

    return max(1, $minutes) . 'm';
}

$requestedId = isset($_GET['id']) && ctype_digit((string) $_GET['id'])
    ? (int) $_GET['id']
    : 0;
$requestedDj = isset($_GET['dj']) ? tr_slug((string) $_GET['dj']) : '';

$archive = tr_episode_archive();
$episodes = $archive['episodes'];

if ($requestedDj !== '') {
    $episodes = array_values(array_filter(
        $episodes,
        static fn (array $episode): bool =>
            tr_slug((string) ($episode['dj_slug'] ?? '')) === $requestedDj
    ));
}

$episode = $requestedId > 0 ? tr_episode_by_id($requestedId) : null;

if ($requestedId > 0 && $episode === null) {
    http_response_code(404);
}

if (tr_terminal_request()) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=30');

    if ($requestedId > 0) {
        if ($episode === null) {
            echo "episode not found\n";
            exit;
        }

        $show = is_array($episode['show'] ?? null) ? $episode['show'] : [];
        $format = is_array($show['format'] ?? null) ? $show['format'] : [];
        $lines = [
            '~ tilderadio transmission ~',
            '',
            'set:   #' . (int) $episode['id'],
            'dj:    ' . (string) $episode['dj'],
            'title: ' . tr_episode_title($episode),
            'when:  ' . gmdate('D M d H:i', (int) $episode['started_at']) . ' UTC',
        ];
        if (!empty($show['title'])) {
            $lines[] = 'show:  ' . (string) $show['title'];
        }
        if (!empty($format['title'])) {
            $lines[] = 'format:' . ' ' . (string) $format['title'];
        }
        $lines[] = 'tracks: ' . (int) ($episode['track_count'] ?? 0);
        $lines[] = '';

        foreach (($episode['tracks'] ?? []) as $index => $track) {
            if (!is_array($track)) {
                continue;
            }
            $lines[] = sprintf(
                '%02d. %s',
                $index + 1,
                (string) ($track['text'] ?? 'unknown track')
            );
        }

        echo implode("\n", $lines) . "\n";
        exit;
    }

    $lines = ['~ tilderadio transmissions ~', ''];
    foreach ($episodes as $item) {
        $lines[] = sprintf(
            '#%-4d %-18s %-28s %s',
            (int) $item['id'],
            (string) ($item['dj'] ?? 'unknown'),
            tr_episode_title($item),
            gmdate('D M d H:i', (int) $item['started_at']) . ' UTC'
        );
    }
    if (!$episodes) {
        $lines[] = 'no transmissions have been archived yet.';
    }
    echo implode("\n", $lines) . "\n";
    exit;
}

$title = $episode !== null ? tr_episode_title($episode) : 'transmissions';
$page_stylesheets = ['css/episodes.css'];
include dirname(__DIR__) . '/header.php';
?>

<?php if ($episode !== null): ?>
    <?php
    $show = is_array($episode['show'] ?? null) ? $episode['show'] : [];
    $format = is_array($show['format'] ?? null) ? $show['format'] : [];
    $tracks = is_array($episode['tracks'] ?? null) ? $episode['tracks'] : [];
    $duration = tr_episodes_duration(
        is_int($episode['duration'] ?? null) ? $episode['duration'] : null
    );
    ?>
    <p class="tr-episodes-back"><a href="<?= tr_episodes_h(asset('episodes/')) ?>">&larr; transmissions</a></p>

    <section class="tr-section tr-episode-hero">
        <div class="tr-title">
            <span class="tr-badge"><?= !empty($episode['is_live']) ? 'LIVE TRANSMISSION' : 'SIGNAL LOGGED' ?></span>
        </div>
        <h1><?= tr_episodes_h(tr_episode_title($episode)) ?></h1>
        <div class="tr-episode-byline">
            <a href="<?= tr_episodes_h(asset('djs/?dj=' . rawurlencode((string) $episode['dj_slug']))) ?>">
                <?= tr_episodes_h((string) $episode['dj']) ?>
            </a>
            <span>set #<?= (int) $episode['id'] ?></span>
            <time
                datetime="<?= tr_episodes_h(gmdate(DATE_ATOM, (int) $episode['started_at'])) ?>"
                data-local-time="<?= (int) $episode['started_at'] ?>"
            ><?= tr_episodes_h(gmdate('D M d H:i', (int) $episode['started_at']) . ' UTC') ?></time>
        </div>

        <?php if (!empty($show['title']) || !empty($format['title'])): ?>
            <p class="tr-episode-show">
                <?php if (!empty($show['title'])): ?>
                    <?= tr_episodes_h((string) $show['title']) ?>
                <?php endif; ?>
                <?php if (!empty($format['title']) && strcasecmp((string) ($show['title'] ?? ''), (string) $format['title']) !== 0): ?>
                    <span>&middot;</span> <?= tr_episodes_h((string) $format['title']) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($show['prompt'])): ?>
            <p class="tr-lede"><?= tr_episodes_h((string) $show['prompt']) ?></p>
        <?php endif; ?>
    </section>

    <section class="tr-section">
        <div class="tr-episode-stats">
            <?php if ($duration !== ''): ?><div><span>duration</span><strong><?= tr_episodes_h($duration) ?></strong></div><?php endif; ?>
            <div><span>tracks</span><strong><?= (int) ($episode['track_count'] ?? 0) ?></strong></div>
            <?php if (!empty($episode['peak_listeners'])): ?><div><span>peak listeners</span><strong><?= (int) $episode['peak_listeners'] ?></strong></div><?php endif; ?>
            <?php if (!empty($episode['max_couch'])): ?><div><span>radio couch</span><strong><?= (int) $episode['max_couch'] ?></strong></div><?php endif; ?>
            <?php if (!empty($episode['props'])): ?><div><span>props</span><strong><?= (int) $episode['props'] ?></strong></div><?php endif; ?>
            <?php if (!empty($episode['tildes'])): ?><div><span>tildes</span><strong><?= (int) $episode['tildes'] ?></strong></div><?php endif; ?>
        </div>
    </section>

    <section class="tr-section">
        <div class="tr-title"><span class="tr-badge">TRACK LOG</span></div>
        <?php if ($tracks): ?>
            <ol class="tr-track-log">
                <?php foreach ($tracks as $track): ?>
                    <?php if (!is_array($track)) continue; ?>
                    <li>
                        <span><?= tr_episodes_h((string) ($track['text'] ?? 'unknown track')) ?></span>
                        <?php if (is_int($track['played_at'] ?? null)): ?>
                            <time
                                datetime="<?= tr_episodes_h(gmdate(DATE_ATOM, (int) $track['played_at'])) ?>"
                                data-local-clock="<?= (int) $track['played_at'] ?>"
                            ><?= tr_episodes_h(gmdate('H:i', (int) $track['played_at']) . ' UTC') ?></time>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <p class="tr-muted">No track metadata was captured for this transmission.</p>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="tr-section">
        <div class="tr-title"><span class="tr-badge">TRANSMISSIONS</span></div>
        <h1>recent signals from the archive</h1>
        <p class="tr-lede">Carrier logs live sets automatically. No playlist paperwork required.</p>
    </section>

    <section class="tr-section">
        <?php if ($episodes): ?>
            <div class="tr-transmission-list">
                <?php foreach ($episodes as $item): ?>
                    <?php
                    $itemShow = is_array($item['show'] ?? null) ? $item['show'] : [];
                    $itemFormat = is_array($itemShow['format'] ?? null) ? $itemShow['format'] : [];
                    ?>
                    <article class="tr-transmission">
                        <div>
                            <div class="tr-transmission-meta">
                                <span><?= tr_episodes_h((string) ($item['dj'] ?? 'unknown DJ')) ?></span>
                                <?php if (!empty($itemFormat['title'])): ?><span><?= tr_episodes_h((string) $itemFormat['title']) ?></span><?php endif; ?>
                                <?php if (!empty($item['is_live'])): ?><span>LIVE</span><?php endif; ?>
                            </div>
                            <h2>
                                <a href="<?= tr_episodes_h(asset('episodes/?id=' . (int) $item['id'])) ?>">
                                    <?= tr_episodes_h(tr_episode_title($item)) ?>
                                </a>
                            </h2>
                        </div>
                        <div class="tr-transmission-side">
                            <time
                                datetime="<?= tr_episodes_h(gmdate(DATE_ATOM, (int) $item['started_at'])) ?>"
                                data-local-time="<?= (int) $item['started_at'] ?>"
                            ><?= tr_episodes_h(gmdate('D M d H:i', (int) $item['started_at']) . ' UTC') ?></time>
                            <span><?= (int) ($item['track_count'] ?? 0) ?> tracks</span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p><em>No Carrier episode archive is available yet.</em></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-local-time]').forEach(function (node) {
        var timestamp = Number(node.getAttribute('data-local-time')) * 1000;
        if (!Number.isFinite(timestamp)) return;
        var date = new Date(timestamp);
        node.textContent = date.toLocaleString([], {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            timeZoneName: 'short'
        });
        node.title = date.toUTCString();
    });

    document.querySelectorAll('[data-local-clock]').forEach(function (node) {
        var timestamp = Number(node.getAttribute('data-local-clock')) * 1000;
        if (!Number.isFinite(timestamp)) return;
        var date = new Date(timestamp);
        node.textContent = date.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit'
        });
        node.title = date.toUTCString();
    });
})();
</script>

<?php include dirname(__DIR__) . '/footer.php'; ?>
