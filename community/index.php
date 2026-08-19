<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/community.php';

$title = 'community';
$community = require dirname(__DIR__) . '/data/community.php';
$stationIds = tr_community_audio_items();
$events = is_array($community['events'] ?? null) ? $community['events'] : [];
$submissions = is_array($community['submissions'] ?? null) ? $community['submissions'] : [];

usort($events, static function (array $a, array $b): int {
    return strtotime((string) ($a['start'] ?? '')) <=> strtotime((string) ($b['start'] ?? ''));
});

include dirname(__DIR__) . '/header.php';
?>

<section class="tr-section">
    <div class="tr-title"><span class="tr-badge">COMMUNITY</span></div>
    <h1>make the station weirder</h1>
    <p class="tr-lede">TildeRadio works best when the station sounds like the people around it. Short IDs, jingles, one-off shows, takeovers, and other experiments all belong here.</p>
    <p>
        <a href="<?= htmlspecialchars(asset('community/contribute/'), ENT_QUOTES, 'UTF-8') ?>">how to contribute &rarr;</a>
        &nbsp;&middot;&nbsp;
        <a href="<?= htmlspecialchars(asset('community/carrier/'), ENT_QUOTES, 'UTF-8') ?>">Carrier IRC bot guide &rarr;</a>
    </p>
</section>

<section class="tr-section">
    <div class="tr-title"><span class="tr-badge">STATION IDs &amp; JINGLES</span></div>

    <?php if ($stationIds): ?>
        <div class="tr-audio-list">
            <?php foreach ($stationIds as $item): ?>
                <article class="tr-audio-item">
                    <div>
                        <strong><?= htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if (!empty($item['by'])): ?>
                            <span class="tr-muted"> by <?= htmlspecialchars((string) $item['by'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['description'])): ?>
                            <p><?= htmlspecialchars((string) $item['description'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <?php if (!empty($item['license'])): ?>
                            <div class="tr-card-meta"><?= htmlspecialchars((string) $item['license'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (!empty($item['url'])): ?>
                            <div class="tr-card-meta">
                                <a href="<?= htmlspecialchars((string) $item['url'], ENT_QUOTES, 'UTF-8') ?>" rel="noopener">source / creator page</a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <audio controls preload="none" src="<?= htmlspecialchars(asset((string) $item['file']), ENT_QUOTES, 'UTF-8') ?>"></audio>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p><em>the community audio shelf is empty. that seems fixable.</em></p>
    <?php endif; ?>

    <p>
        Keep station IDs around <?= (int) ($submissions['max_station_id_seconds'] ?? 10) ?> seconds or less.
        Preferred source formats: <?= htmlspecialchars(implode(', ', (array) ($submissions['preferred_formats'] ?? [])), ENT_QUOTES, 'UTF-8') ?>.
        Include the license you want attached to your audio.
    </p>
    <p>
        Each submission has its own JSON file under <code>data/community/audio/</code>.
        See <code>example.json.sample</code> in that directory for the format.
    </p>
    <p>
        <a href="<?= htmlspecialchars(asset('community/contribute/'), ENT_QUOTES, 'UTF-8') ?>">submission guide</a>
        &nbsp;&middot;&nbsp;
        <a href="<?= htmlspecialchars((string) ($submissions['irc'] ?? 'https://tilde.chat/kiwi/#tilderadio'), ENT_QUOTES, 'UTF-8') ?>">bring it to #tilderadio</a>
    </p>
</section>

<section class="tr-section">
    <div class="tr-title"><span class="tr-badge">ODD EVENTS</span></div>

    <?php if ($events): ?>
        <div class="tr-event-list">
            <?php foreach ($events as $event): ?>
                <?php
                $start = strtotime((string) ($event['start'] ?? ''));
                $end = strtotime((string) ($event['end'] ?? ''));
                ?>
                <article class="tr-event">
                    <h2><?= htmlspecialchars((string) ($event['title'] ?? 'community event'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <?php if ($start !== false): ?>
                        <p class="tr-card-meta">
                            <time datetime="<?= htmlspecialchars(gmdate(DATE_ATOM, $start), ENT_QUOTES, 'UTF-8') ?>" data-community-time="<?= $start ?>">
                                <?= htmlspecialchars(gmdate('D M d H:i', $start) . ' UTC', ENT_QUOTES, 'UTF-8') ?>
                            </time>
                            <?php if ($end !== false): ?>
                                &ndash;
                                <time datetime="<?= htmlspecialchars(gmdate(DATE_ATOM, $end), ENT_QUOTES, 'UTF-8') ?>" data-community-time="<?= $end ?>">
                                    <?= htmlspecialchars(gmdate('D M d H:i', $end) . ' UTC', ENT_QUOTES, 'UTF-8') ?>
                                </time>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($event['description'])): ?>
                        <p><?= htmlspecialchars((string) $event['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php if (!empty($event['url']) && filter_var($event['url'], FILTER_VALIDATE_URL)): ?>
                        <p><a href="<?= htmlspecialchars((string) $event['url'], ENT_QUOTES, 'UTF-8') ?>">event details</a></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p>No special event is scheduled yet. Good candidates: a one-shot night, tilde takeover, annual time capsule, or themed relay.</p>
    <?php endif; ?>
</section>

<script>
document.querySelectorAll('[data-community-time]').forEach(function (node) {
    var timestamp = Number(node.getAttribute('data-community-time')) * 1000;
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
    node.title = new Date(timestamp).toUTCString();
});
</script>

<?php include dirname(__DIR__) . '/footer.php'; ?>
