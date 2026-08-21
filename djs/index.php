<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/radio.php';
require_once dirname(__DIR__) . '/lib/api.php';

function tr_djs_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Normalize a string or array of strings into displayable text items.
 *
 * @return array<int, string>
 */
function tr_djs_text_list(mixed $value): array
{
    if (is_string($value)) {
        $value = preg_split('/\R{2,}/', trim($value)) ?: [];
    }

    if (!is_array($value)) {
        return [];
    }

    $items = [];
    foreach ($value as $item) {
        if (!is_string($item)) {
            continue;
        }

        $item = trim($item);
        if ($item !== '') {
            $items[] = $item;
        }
    }

    return $items;
}

/**
 * Accept both {"homepage": "https://..."} and
 * [{"label": "homepage", "url": "https://..."}] link formats.
 *
 * @return array<int, array{label: string, url: string}>
 */
function tr_djs_links(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $links = [];
    foreach ($value as $key => $item) {
        $label = '';
        $url = '';

        if (is_string($key) && is_string($item)) {
            $label = trim($key);
            $url = trim($item);
        } elseif (is_array($item)) {
            $label = isset($item['label']) && is_string($item['label']) ? trim($item['label']) : '';
            $url = isset($item['url']) && is_string($item['url']) ? trim($item['url']) : '';
        }

        if ($label === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            continue;
        }

        $links[] = ['label' => $label, 'url' => $url];
    }

    return $links;
}

function tr_djs_avatar_url(array $profile): ?string
{
    $avatar = isset($profile['avatar']) && is_string($profile['avatar'])
        ? trim($profile['avatar'])
        : '';

    if ($avatar === '') {
        return null;
    }

    if (str_starts_with($avatar, '/') && !str_contains($avatar, '..') && !str_starts_with($avatar, '//')) {
        return $avatar;
    }

    if (filter_var($avatar, FILTER_VALIDATE_URL) && strtolower((string) parse_url($avatar, PHP_URL_SCHEME)) === 'https') {
        return $avatar;
    }

    return null;
}

function tr_djs_initials(string $name): string
{
    $parts = preg_split('/[\s_-]+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

    if (count($parts) >= 2) {
        $value = $parts[0][0] . $parts[1][0];
    } else {
        $value = $name;
        $value = function_exists('mb_substr') ? mb_substr($value, 0, 2) : substr($value, 0, 2);
    }

    return strtoupper($value);
}

function tr_djs_profile_href(string $slug): string
{
    return asset('djs/?dj=' . rawurlencode($slug));
}

function tr_djs_profile_summary(array $profile): ?string
{
    foreach (['tagline', 'description'] as $key) {
        if (isset($profile[$key]) && is_string($profile[$key]) && trim($profile[$key]) !== '') {
            return trim($profile[$key]);
        }
    }

    $bio = tr_djs_text_list($profile['bio'] ?? null);
    return $bio[0] ?? null;
}

function tr_djs_avatar(array $profile, bool $eager = false): void
{
    $name = (string) ($profile['name'] ?? 'DJ');
    $avatar = tr_djs_avatar_url($profile);
    ?>
    <div class="tr-dj-avatar" aria-hidden="true">
        <?php if ($avatar !== null): ?>
            <img
                src="<?= tr_djs_h($avatar) ?>"
                alt=""
                <?= $eager ? 'fetchpriority="high"' : 'loading="lazy"' ?>
                referrerpolicy="no-referrer"
            >
        <?php else: ?>
            <span><?= tr_djs_h(tr_djs_initials($name)) ?></span>
        <?php endif; ?>
    </div>
    <?php
}

$requestedSlug = isset($_GET['dj']) ? tr_slug((string) $_GET['dj']) : '';
$catalog = tr_dj_catalog();
$now = tr_now_playing();
$liveSlug = !empty($now['is_live']) && !empty($now['dj'])
    ? tr_slug((string) $now['dj'])
    : '';

if (tr_terminal_request()) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=60');

    if ($requestedSlug !== '') {
        $profile = $catalog[$requestedSlug] ?? null;
        if (!is_array($profile)) {
            http_response_code(404);
            echo "DJ not found\n";
            exit;
        }

        $show = is_array($profile['show'] ?? null) ? $profile['show'] : [];
        $upcoming = is_array($profile['upcoming'] ?? null) ? $profile['upcoming'] : [];
        $summary = tr_djs_profile_summary($profile);
        $lines = [
            '~ tilderadio dj ~',
            '',
            'dj:   ' . (string) ($profile['name'] ?? $requestedSlug),
        ];
        if (!empty($show['title']) && is_string($show['title'])) {
            $lines[] = 'show: ' . trim($show['title']);
        }
        if ($summary !== null) {
            $lines[] = 'about: ' . $summary;
        }
        if ($requestedSlug === $liveSlug) {
            $lines[] = 'state: ON AIR NOW';
            if (!empty($now['now_playing']['text'])) {
                $lines[] = 'now:  ' . (string) $now['now_playing']['text'];
            }
        }
        if (!empty($upcoming[0]['start_ts']) && is_int($upcoming[0]['start_ts'])) {
            $lines[] = 'next:  ' . gmdate('D M d H:i', $upcoming[0]['start_ts']) . ' UTC';
        }
        $lines[] = '';
        $lines[] = 'profile:  https://tilderadio.org/djs/?dj=' . rawurlencode($requestedSlug);
        $lines[] = 'handbook: https://tilderadio.org/djinfo/';
        echo implode("\n", $lines) . "\n";
        exit;
    }

    $profiles = array_values($catalog);
    usort($profiles, static fn (array $a, array $b): int => strcasecmp(
        (string) ($a['name'] ?? $a['slug'] ?? ''),
        (string) ($b['name'] ?? $b['slug'] ?? '')
    ));

    $lines = ['~ tilderadio djs ~', ''];
    foreach ($profiles as $profile) {
        $slug = (string) ($profile['slug'] ?? '');
        $name = (string) ($profile['name'] ?? $slug);
        $show = is_array($profile['show'] ?? null) ? $profile['show'] : [];
        $upcoming = is_array($profile['upcoming'] ?? null) ? $profile['upcoming'] : [];
        $line = ($slug !== '' && $slug === $liveSlug ? '* ' : '  ') . $name;
        if (!empty($show['title']) && is_string($show['title'])) {
            $line .= ' :: ' . trim($show['title']);
        }
        if (!empty($upcoming[0]['start_ts']) && is_int($upcoming[0]['start_ts'])) {
            $line .= ' @ ' . gmdate('D M d H:i', $upcoming[0]['start_ts']) . ' UTC';
        }
        $lines[] = $line;
    }
    $lines[] = '';
    if ($liveSlug !== '') {
        $lines[] = '* on air now';
    }
    $lines[] = 'handbook: https://tilderadio.org/djinfo/';
    $lines[] = 'api:      https://tilderadio.org/api/djs/';
    echo implode("\n", $lines) . "\n";
    exit;
}

if ($requestedSlug !== '') {
    $profile = $catalog[$requestedSlug] ?? null;
    if (!is_array($profile)) {
        http_response_code(404);
        $title = 'DJ not found';
        $page_stylesheets = ['css/djs.css'];
        include dirname(__DIR__) . '/header.php';
        ?>
        <section class="tr-section">
            <h1>DJ not found</h1>
            <p>That DJ is not in the current schedule or the local profile data.</p>
            <p><a href="<?= tr_djs_h(asset('djs/')) ?>">back to DJs &amp; shows</a></p>
        </section>
        <?php
        include dirname(__DIR__) . '/footer.php';
        exit;
    }

    $name = (string) $profile['name'];
    $summary = tr_djs_profile_summary($profile);
    $show = is_array($profile['show'] ?? null) ? $profile['show'] : [];
    $links = tr_djs_links($profile['links'] ?? null);
    $upcoming = is_array($profile['upcoming'] ?? null) ? $profile['upcoming'] : [];
    $bio = tr_djs_text_list($profile['bio'] ?? ($profile['description'] ?? null));
    $notes = tr_djs_text_list($profile['notes'] ?? null);
    $favorites = is_array($profile['favorites'] ?? null) ? $profile['favorites'] : [];
    $genres = tr_djs_text_list($show['genres'] ?? null);
    $showFormats = tr_show_formats($profile);
    $recentEpisodes = tr_episodes_for_dj($requestedSlug, 6);
    $isLive = $requestedSlug === $liveSlug;
    $nextEvent = isset($upcoming[0]) && is_array($upcoming[0]) ? $upcoming[0] : null;

    $title = $name;
    $page_stylesheets = ['css/djs.css'];
    $head = [];
    $head[] = '<link rel="canonical" href="https://tilderadio.org/djs/?dj=' . rawurlencode($requestedSlug) . '">';
    if ($summary !== null) {
        $head[] = '<meta name="description" content="' . tr_djs_h($summary) . '">';
    }
    $additional_head = implode("\n", $head);

    include dirname(__DIR__) . '/header.php';
    ?>

    <p class="tr-dj-back"><a href="<?= tr_djs_h(asset('djs/')) ?>">&larr; all DJs &amp; shows</a></p>

    <section class="tr-dj-hero">
        <?php tr_djs_avatar($profile, true); ?>
        <div class="tr-dj-heading">
            <div class="tr-title">
                <span class="tr-badge">DJ PROFILE</span>
                <?php if ($isLive): ?><span class="tr-pill">ON AIR NOW</span><?php endif; ?>
            </div>
            <h1><?= tr_djs_h($name) ?></h1>
            <?php if ($summary !== null): ?>
                <p class="tr-dj-tagline"><?= tr_djs_h($summary) ?></p>
            <?php endif; ?>

            <?php
            $facts = [
                'pronouns' => $profile['pronouns'] ?? null,
                'location' => $profile['location'] ?? null,
                'tilde' => $profile['tilde'] ?? null,
                'irc' => $profile['irc'] ?? null,
                'on air since' => $profile['since'] ?? null,
            ];
            $facts = array_filter(
                $facts,
                static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
            );
            ?>
            <?php if ($facts): ?>
                <ul class="tr-dj-facts">
                    <?php foreach ($facts as $label => $value): ?>
                        <li><?= tr_djs_h((string) $label) ?>: <strong><?= tr_djs_h(trim((string) $value)) ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($isLive): ?>
        <section class="tr-section">
            <div class="tr-title"><span class="tr-badge">LIVE RIGHT NOW</span></div>
            <div class="tr-live-panel">
                <div>
                    <p class="tr-muted">now playing</p>
                    <h2><?= tr_djs_h((string) ($now['now_playing']['text'] ?? 'live broadcast')) ?></h2>
                    <?php if (is_int($now['listeners'] ?? null)): ?>
                        <p><?= (int) $now['listeners'] ?> listener<?= $now['listeners'] === 1 ? '' : 's' ?></p>
                    <?php endif; ?>
                </div>
                <a class="tr-dj-action" href="<?= tr_djs_h(asset('listen/')) ?>">listen now</a>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($show['title']) || !empty($show['description']) || !empty($show['tagline']) || $genres): ?>
        <section class="tr-section">
            <div class="tr-title"><span class="tr-badge">THE SHOW</span></div>
            <?php if (!empty($show['title']) && is_string($show['title'])): ?>
                <h2 class="tr-show-title"><?= tr_djs_h(trim($show['title'])) ?></h2>
            <?php endif; ?>
            <?php if (!empty($show['tagline']) && is_string($show['tagline'])): ?>
                <p class="tr-show-tagline"><?= tr_djs_h(trim($show['tagline'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($show['description']) && is_string($show['description'])): ?>
                <p><?= tr_djs_h(trim($show['description'])) ?></p>
            <?php endif; ?>
            <?php if ($genres): ?>
                <ul class="tr-tags" aria-label="show genres">
                    <?php foreach ($genres as $genre): ?>
                        <li><?= tr_djs_h($genre) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($showFormats): ?>
                <div class="tr-show-formats">
                    <?php foreach ($showFormats as $format): ?>
                        <article class="tr-show-format">
                            <div class="tr-show-format-days">
                                <?= tr_djs_h(implode(' / ', array_map('ucfirst', $format['days']))) ?>
                            </div>
                            <?php if (!empty($format['title'])): ?>
                                <h3><?= tr_djs_h((string) $format['title']) ?></h3>
                            <?php endif; ?>
                            <?php if (!empty($format['tagline'])): ?>
                                <p><?= tr_djs_h((string) $format['tagline']) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="tr-section">
        <div class="tr-title"><span class="tr-badge">UPCOMING</span></div>
        <?php if ($nextEvent !== null && !empty($nextEvent['start_ts'])): ?>
            <div class="tr-next-panel">
                <div>
                    <p class="tr-muted">next broadcast</p>
                    <h2>
                        <time
                            datetime="<?= tr_djs_h(gmdate(DATE_ATOM, (int) $nextEvent['start_ts'])) ?>"
                            data-local-time="<?= (int) $nextEvent['start_ts'] ?>"
                        ><?= tr_djs_h(gmdate('D M d H:i', (int) $nextEvent['start_ts']) . ' UTC') ?></time>
                    </h2>
                    <p class="tr-dj-countdown" data-countdown="<?= (int) $nextEvent['start_ts'] ?>"></p>
                </div>
                <a class="tr-dj-action" href="<?= tr_djs_h(asset('schedule/')) ?>">full schedule</a>
            </div>

            <?php if (count($upcoming) > 1): ?>
                <ul class="tr-profile-schedule" style="margin-top:18px">
                    <?php foreach (array_slice($upcoming, 1, 5) as $event): ?>
                        <?php if (!is_array($event) || empty($event['start_ts'])) continue; ?>
                        <li>
                            <time
                                datetime="<?= tr_djs_h(gmdate(DATE_ATOM, (int) $event['start_ts'])) ?>"
                                data-local-time="<?= (int) $event['start_ts'] ?>"
                            ><?= tr_djs_h(gmdate('D M d H:i', (int) $event['start_ts']) . ' UTC') ?></time>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php else: ?>
            <p><em>no upcoming slot is currently listed.</em></p>
            <p><a href="<?= tr_djs_h(asset('schedule/')) ?>">full schedule</a></p>
        <?php endif; ?>
    </section>

    <?php if ($recentEpisodes): ?>
        <section class="tr-section">
            <div class="tr-title"><span class="tr-badge">RECENT TRANSMISSIONS</span></div>
            <div class="tr-episode-list">
                <?php foreach ($recentEpisodes as $episode): ?>
                    <?php
                    $episodeId = (int) ($episode['id'] ?? 0);
                    $episodeShow = is_array($episode['show'] ?? null) ? $episode['show'] : [];
                    $episodeFormat = is_array($episodeShow['format'] ?? null) ? $episodeShow['format'] : [];
                    ?>
                    <article class="tr-episode-card">
                        <div>
                            <div class="tr-episode-meta">
                                <time
                                    datetime="<?= tr_djs_h(gmdate(DATE_ATOM, (int) $episode['started_at'])) ?>"
                                    data-local-time="<?= (int) $episode['started_at'] ?>"
                                ><?= tr_djs_h(gmdate('D M d H:i', (int) $episode['started_at']) . ' UTC') ?></time>
                                <?php if (!empty($episode['is_live'])): ?><span>LIVE</span><?php endif; ?>
                                <?php if (!empty($episodeFormat['title'])): ?>
                                    <span><?= tr_djs_h((string) $episodeFormat['title']) ?></span>
                                <?php endif; ?>
                            </div>
                            <h3>
                                <a href="<?= tr_djs_h(asset('episodes/?id=' . $episodeId)) ?>">
                                    <?= tr_djs_h(tr_episode_title($episode)) ?>
                                </a>
                            </h3>
                        </div>
                        <span><?= (int) ($episode['track_count'] ?? 0) ?> tracks</span>
                    </article>
                <?php endforeach; ?>
            </div>
            <p><a href="<?= tr_djs_h(asset('episodes/?dj=' . rawurlencode($requestedSlug))) ?>">all transmissions &rarr;</a></p>
        </section>
    <?php endif; ?>

    <?php if ($bio): ?>
        <section class="tr-section">
            <div class="tr-title"><span class="tr-badge">ABOUT</span></div>
            <div class="tr-bio">
                <?php foreach ($bio as $paragraph): ?>
                    <p><?= tr_djs_h($paragraph) ?></p>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php
    $favoriteGroups = [];
    foreach (['artists' => 'artists', 'albums' => 'albums', 'tracks' => 'tracks'] as $key => $label) {
        $items = tr_djs_text_list($favorites[$key] ?? null);
        if ($items) {
            $favoriteGroups[$label] = $items;
        }
    }
    ?>
    <?php if ($favoriteGroups): ?>
        <section class="tr-section">
            <div class="tr-title"><span class="tr-badge">FAVORITES</span></div>
            <div class="tr-favorites">
                <?php foreach ($favoriteGroups as $label => $items): ?>
                    <div class="tr-favorite-group">
                        <h3><?= tr_djs_h($label) ?></h3>
                        <ul>
                            <?php foreach ($items as $item): ?>
                                <li><?= tr_djs_h($item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($notes): ?>
        <section class="tr-section">
            <div class="tr-title"><span class="tr-badge">FROM THE DJ</span></div>
            <ul class="tr-profile-notes">
                <?php foreach ($notes as $note): ?>
                    <li><?= tr_djs_h($note) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($links): ?>
        <section class="tr-section">
            <div class="tr-title"><span class="tr-badge">ELSEWHERE</span></div>
            <ul class="tr-dj-links">
                <?php foreach ($links as $link): ?>
                    <li>
                        <span class="label"><?= tr_djs_h($link['label']) ?></span>
                        <a href="<?= tr_djs_h($link['url']) ?>" rel="me noopener noreferrer"><?= tr_djs_h($link['url']) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <script>
    (function () {
        function localizeTimes() {
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
                node.title = new Date(timestamp).toUTCString();
            });
        }

        function updateCountdowns() {
            var now = Date.now();
            document.querySelectorAll('[data-countdown]').forEach(function (node) {
                var timestamp = Number(node.getAttribute('data-countdown')) * 1000;
                if (!Number.isFinite(timestamp)) return;

                var seconds = Math.max(0, Math.floor((timestamp - now) / 1000));
                if (seconds <= 0) {
                    node.textContent = 'starting now';
                    return;
                }

                var days = Math.floor(seconds / 86400);
                var hours = Math.floor((seconds % 86400) / 3600);
                var minutes = Math.floor((seconds % 3600) / 60);
                var parts = [];
                if (days > 0) parts.push(days + 'd');
                if (hours > 0 || days > 0) parts.push(hours + 'h');
                parts.push(minutes + 'm');
                node.textContent = 'in ' + parts.join(' ');
            });
        }

        localizeTimes();
        updateCountdowns();
        setInterval(updateCountdowns, 30000);
    })();
    </script>

    <?php
    include dirname(__DIR__) . '/footer.php';
    exit;
}

$title = 'DJs & shows';
$page_stylesheets = ['css/djs.css'];
include dirname(__DIR__) . '/header.php';

$profiles = array_values($catalog);
usort($profiles, static function (array $a, array $b) use ($liveSlug): int {
    $aLive = (string) ($a['slug'] ?? '') === $liveSlug;
    $bLive = (string) ($b['slug'] ?? '') === $liveSlug;
    if ($aLive !== $bLive) {
        return $aLive ? -1 : 1;
    }

    $aNext = isset($a['upcoming'][0]['start_ts']) ? (int) $a['upcoming'][0]['start_ts'] : PHP_INT_MAX;
    $bNext = isset($b['upcoming'][0]['start_ts']) ? (int) $b['upcoming'][0]['start_ts'] : PHP_INT_MAX;
    if ($aNext !== $bNext) {
        return $aNext <=> $bNext;
    }

    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
?>

<section class="tr-section">
    <div class="tr-dj-directory-head">
        <div>
            <div class="tr-title"><span class="tr-badge">DJs &amp; SHOWS</span></div>
            <h1>people behind the stream</h1>
            <p class="tr-lede">Every scheduled DJ gets a page automatically. DJs can add a bio, show identity, links, favorites, artwork, and other details with one JSON file.</p>
            <p><a class="tr-dj-action" href="<?= tr_djs_h(asset('djinfo/')) ?>">become a DJ / DJ handbook &rarr;</a></p>
        </div>
        <?php if ($profiles): ?>
            <div class="tr-dj-count"><?= count($profiles) ?> profile<?= count($profiles) === 1 ? '' : 's' ?></div>
        <?php endif; ?>
    </div>
</section>

<section class="tr-section">
    <?php if ($profiles): ?>
        <div class="tr-dj-card-grid">
            <?php foreach ($profiles as $profile): ?>
                <?php
                $slug = (string) ($profile['slug'] ?? '');
                $name = (string) ($profile['name'] ?? $slug);
                $show = is_array($profile['show'] ?? null) ? $profile['show'] : [];
                $upcoming = is_array($profile['upcoming'] ?? null) ? $profile['upcoming'] : [];
                $summary = tr_djs_profile_summary($profile);
                $isLive = $slug !== '' && $slug === $liveSlug;
                ?>
                <article class="tr-dj-card<?= $isLive ? ' is-live' : '' ?>">
                    <div class="tr-dj-card-head">
                        <?php tr_djs_avatar($profile); ?>
                        <div>
                            <h2><a href="<?= tr_djs_h(tr_djs_profile_href($slug)) ?>"><?= tr_djs_h($name) ?></a></h2>
                            <?php if ($isLive): ?>
                                <div class="tr-dj-live-label">on air now</div>
                            <?php elseif (!empty($show['title']) && is_string($show['title'])): ?>
                                <p class="tr-dj-card-show"><?= tr_djs_h(trim($show['title'])) ?></p>
                            <?php elseif (!empty($profile['tilde']) && is_string($profile['tilde'])): ?>
                                <p class="tr-dj-card-show"><?= tr_djs_h(trim($profile['tilde'])) ?></p>
                            <?php else: ?>
                                <p class="tr-dj-card-show">community DJ</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($summary !== null): ?>
                        <p class="tr-dj-card-summary"><?= tr_djs_h($summary) ?></p>
                    <?php endif; ?>

                    <div class="tr-dj-card-meta">
                        <?php if ($isLive && !empty($now['now_playing']['text'])): ?>
                            <span><?= tr_djs_h((string) $now['now_playing']['text']) ?></span>
                        <?php elseif (!empty($upcoming[0]['start_ts'])): ?>
                            <span>next:</span>
                            <time
                                datetime="<?= tr_djs_h(gmdate(DATE_ATOM, (int) $upcoming[0]['start_ts'])) ?>"
                                data-local-time="<?= (int) $upcoming[0]['start_ts'] ?>"
                            ><?= tr_djs_h(gmdate('D M d H:i', (int) $upcoming[0]['start_ts']) . ' UTC') ?></time>
                        <?php else: ?>
                            <span>no upcoming slot</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p><em>DJ profiles are temporarily unavailable because the schedule could not be loaded and no local profile files were found.</em></p>
    <?php endif; ?>
</section>

<script>
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
    node.title = new Date(timestamp).toUTCString();
});
</script>

<?php include dirname(__DIR__) . '/footer.php'; ?>
