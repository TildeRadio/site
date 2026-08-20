<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/radio.php';
require_once dirname(__DIR__) . '/lib/api.php';

function tr_schedule_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Flatten the catalog's normalized upcoming events so the schedule page and
 * DJ pages are driven by the same AzuraCast + profile data.
 *
 * @return array<int, array<string, mixed>>
 */
function tr_schedule_catalog_events(array $catalog): array
{
    $events = [];

    foreach ($catalog as $catalogSlug => $profile) {
        if (!is_array($profile)) {
            continue;
        }

        $profileSlug = trim((string) ($profile['slug'] ?? $catalogSlug));
        $upcoming = is_array($profile['upcoming'] ?? null) ? $profile['upcoming'] : [];
        foreach ($upcoming as $event) {
            if (!is_array($event) || !is_int($event['start_ts'] ?? null)) {
                continue;
            }
            $event['_profile_slug'] = $profileSlug;
            $events[] = $event;
        }
    }

    usort(
        $events,
        static fn (array $a, array $b): int => (int) $a['start_ts'] <=> (int) $b['start_ts']
    );

    return $events;
}

function tr_schedule_profile_for_event(array $event, array $catalog): ?array
{
    $slug = trim((string) ($event['_profile_slug'] ?? $event['slug'] ?? ''));
    return $slug !== '' && isset($catalog[$slug]) && is_array($catalog[$slug])
        ? $catalog[$slug]
        : null;
}

/**
 * Return the small amount of show metadata useful on a schedule card.
 *
 * @return array{title:?string,tagline:?string,description:?string,genres:array<int,string>}
 */
function tr_schedule_show_info(?array $profile): array
{
    if ($profile === null) {
        return ['title' => null, 'tagline' => null, 'description' => null, 'genres' => []];
    }

    $show = is_array($profile['show'] ?? null) ? $profile['show'] : [];
    $clean = static function (mixed $value): ?string {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    };

    $title = $clean($show['title'] ?? null);
    $tagline = $clean($show['tagline'] ?? null) ?? $clean($profile['tagline'] ?? null);
    $description = $clean($show['description'] ?? null);

    if ($description === null && $tagline === null) {
        $description = $clean($profile['description'] ?? null);
        if ($description === null) {
            $bio = $profile['bio'] ?? null;
            if (is_string($bio)) {
                $description = $clean($bio);
            } elseif (is_array($bio) && isset($bio[0])) {
                $description = $clean($bio[0]);
            }
        }
    }

    $genres = [];
    if (is_array($show['genres'] ?? null)) {
        foreach ($show['genres'] as $genre) {
            if (is_string($genre) && trim($genre) !== '') {
                $genres[] = trim($genre);
            }
        }
    }

    return [
        'title' => $title,
        'tagline' => $tagline,
        'description' => $description,
        'genres' => $genres,
    ];
}

function tr_schedule_avatar_url(?array $profile): ?string
{
    $avatar = is_array($profile) && is_string($profile['avatar'] ?? null)
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

function tr_schedule_initials(string $name): string
{
    $parts = preg_split('/[\s_-]+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    if (count($parts) >= 2) {
        return strtoupper($parts[0][0] . $parts[1][0]);
    }
    $short = function_exists('mb_substr') ? mb_substr($name, 0, 2) : substr($name, 0, 2);
    return strtoupper($short);
}

function tr_schedule_render_avatar(?array $profile, string $name, string $extraClass = ''): void
{
    $avatar = tr_schedule_avatar_url($profile);
    $class = trim('tr-schedule-avatar ' . $extraClass);
    ?>
    <div class="<?= tr_schedule_h($class) ?>" aria-hidden="true">
        <?php if ($avatar !== null): ?>
            <img src="<?= tr_schedule_h($avatar) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
        <?php else: ?>
            <span><?= tr_schedule_h(tr_schedule_initials($name)) ?></span>
        <?php endif; ?>
    </div>
    <?php
}

function tr_schedule_day_label_utc(int $timestamp, int $now): string
{
    $today = gmdate('Y-m-d', $now);
    $tomorrow = gmdate('Y-m-d', $now + 86400);
    $date = gmdate('Y-m-d', $timestamp);

    $prefix = $date === $today ? 'TODAY' : ($date === $tomorrow ? 'TOMORROW' : '');
    $label = strtoupper(gmdate('D M j', $timestamp));
    return $prefix !== '' ? $prefix . ' · ' . $label : $label;
}

function tr_schedule_utc_range(array $event, bool $includeDate = false): string
{
    $start = is_int($event['start_ts'] ?? null) ? $event['start_ts'] : null;
    $end = is_int($event['end_ts'] ?? null) ? $event['end_ts'] : null;
    if ($start === null) {
        return 'time unavailable';
    }

    $prefix = $includeDate ? gmdate('D M j · ', $start) : '';
    $range = gmdate('H:i', $start);
    if ($end !== null) {
        $range .= '–' . gmdate('H:i', $end);
    }
    return $prefix . $range . ' UTC';
}

function tr_schedule_render_event(array $event, array $catalog): void
{
    $profile = tr_schedule_profile_for_event($event, $catalog);
    $eventName = trim((string) ($event['name'] ?? 'unknown DJ')) ?: 'unknown DJ';
    $name = is_array($profile) && is_string($profile['name'] ?? null) && trim($profile['name']) !== ''
        ? trim($profile['name'])
        : $eventName;
    $slug = trim((string) ($event['_profile_slug'] ?? $event['slug'] ?? ''));
    $info = tr_schedule_show_info($profile);
    $start = (int) $event['start_ts'];
    $end = is_int($event['end_ts'] ?? null) ? (int) $event['end_ts'] : 0;
    ?>
    <article
        class="tr-schedule-event"
        data-schedule-event
        data-start-ts="<?= $start ?>"
        data-end-ts="<?= $end ?>"
    >
        <div class="tr-schedule-event-time">
            <time
                datetime="<?= tr_schedule_h(gmdate(DATE_ATOM, $start)) ?>"
                data-schedule-time
                data-start-ts="<?= $start ?>"
                data-end-ts="<?= $end ?>"
            ><?= tr_schedule_h(tr_schedule_utc_range($event)) ?></time>
        </div>

        <?php tr_schedule_render_avatar($profile, $name); ?>

        <div class="tr-schedule-event-copy">
            <h3><?= tr_schedule_h($name) ?></h3>
            <?php if ($info['title'] !== null && strcasecmp($info['title'], $name) !== 0): ?>
                <div class="tr-schedule-show-title"><?= tr_schedule_h($info['title']) ?></div>
            <?php endif; ?>
            <?php if ($info['tagline'] !== null): ?>
                <p class="tr-schedule-tagline"><?= tr_schedule_h($info['tagline']) ?></p>
            <?php endif; ?>
            <?php if ($info['description'] !== null): ?>
                <p class="tr-schedule-description"><?= tr_schedule_h($info['description']) ?></p>
            <?php endif; ?>
            <?php if ($info['genres']): ?>
                <div class="tr-schedule-genres" aria-label="show tags">
                    <?php foreach ($info['genres'] as $genre): ?>
                        <span><?= tr_schedule_h($genre) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($slug !== ''): ?>
            <a class="tr-schedule-profile-link" href="<?= tr_schedule_h(asset('djs/?dj=' . rawurlencode($slug))) ?>">DJ profile &rarr;</a>
        <?php endif; ?>
    </article>
    <?php
}

$catalog = tr_dj_catalog();
$events = tr_schedule_catalog_events($catalog);
$nowTs = time();
$nowPlaying = tr_now_playing();
$liveDj = !empty($nowPlaying['is_live']) && is_string($nowPlaying['dj'] ?? null)
    ? trim((string) $nowPlaying['dj'])
    : '';
$liveSlug = $liveDj !== '' ? tr_slug($liveDj) : '';
$liveProfile = $liveSlug !== '' && isset($catalog[$liveSlug]) && is_array($catalog[$liveSlug])
    ? $catalog[$liveSlug]
    : null;
$liveInfo = tr_schedule_show_info($liveProfile);

$futureEvents = array_values(array_filter(
    $events,
    static fn (array $event): bool => is_int($event['start_ts'] ?? null) && $event['start_ts'] > $nowTs
));
$nextEvent = $futureEvents[0] ?? null;
$nextProfile = is_array($nextEvent) ? tr_schedule_profile_for_event($nextEvent, $catalog) : null;
$nextInfo = tr_schedule_show_info($nextProfile);

$mastodonUrl = trim((string) (getenv('TILDERADIO_MASTODON_URL') ?: 'https://tilde.zone/@tilderadio'));
$mastodonHandle = trim((string) (getenv('TILDERADIO_MASTODON_HANDLE') ?: '@tilderadio@tilde.zone'));
$ircUrl = 'https://tilde.chat/kiwi/#tilderadio';

if (tr_terminal_request()) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=60');

    $lines = ['~ tilderadio schedule ~', ''];

    if ($liveDj !== '') {
        $lines[] = 'LIVE NOW';
        $liveTitle = $liveInfo['title'];
        $lines[] = '  ' . $liveDj . ($liveTitle !== null ? ' :: ' . $liveTitle : '');
        if (!empty($nowPlaying['now_playing']['text'])) {
            $lines[] = '  now: ' . (string) $nowPlaying['now_playing']['text'];
        }
        if (is_int($nowPlaying['listeners'] ?? null)) {
            $lines[] = '  listeners: ' . $nowPlaying['listeners'];
        }
        $lines[] = '  listen: https://tilderadio.org/listen';
        $lines[] = '';
    }

    $lines[] = 'UPCOMING';
    $shown = 0;
    foreach (array_slice($futureEvents, 0, 50) as $event) {
        $profile = tr_schedule_profile_for_event($event, $catalog);
        $info = tr_schedule_show_info($profile);
        $name = is_array($profile) && !empty($profile['name'])
            ? trim((string) $profile['name'])
            : trim((string) ($event['name'] ?? 'unknown'));
        $end = is_int($event['end_ts'] ?? null) ? ' - ' . gmdate('H:i', $event['end_ts']) : '';
        $lines[] = sprintf('  %-18s %s%s UTC', $name, gmdate('D M d H:i', $event['start_ts']), $end);
        if ($info['title'] !== null && strcasecmp($info['title'], $name) !== 0) {
            $lines[] = '    show: ' . $info['title'];
        } elseif ($info['tagline'] !== null) {
            $lines[] = '    ' . $info['tagline'];
        }
        $shown++;
    }
    if ($shown === 0) {
        $lines[] = '  no upcoming shows are currently listed.';
    }

    $lines[] = '';
    $lines[] = 'calendar: https://tilderadio.org/schedule/';
    $lines[] = 'ical:     https://tilderadio.org/schedule/ics.php';
    $lines[] = 'api:      https://tilderadio.org/api/schedule/';
    $lines[] = 'listen:   https://tilderadio.org/listen';
    echo implode("\n", $lines) . "\n";
    exit;
}

$title = 'schedule';
$page_stylesheets = ['css/calendar.css', 'css/schedule.css'];
$additional_head = '<link rel="alternate" type="text/calendar" href="https://tilderadio.org/schedule/ics.php">';
include dirname(__DIR__) . '/header.php';
?>

<section class="tr-section tr-schedule-hero<?= $liveDj !== '' ? ' is-live' : '' ?>">
    <?php if ($liveDj !== ''): ?>
        <div class="tr-title"><span class="tr-badge">SIGNAL ACQUIRED</span></div>
        <div class="tr-schedule-hero-grid">
            <?php tr_schedule_render_avatar($liveProfile, $liveDj, 'tr-schedule-avatar-hero'); ?>
            <div class="tr-schedule-hero-copy">
                <div class="tr-schedule-state">LIVE NOW</div>
                <h1><?= tr_schedule_h($liveDj) ?></h1>
                <?php if ($liveInfo['title'] !== null): ?>
                    <div class="tr-schedule-hero-show"><?= tr_schedule_h($liveInfo['title']) ?></div>
                <?php endif; ?>
                <?php if ($liveInfo['tagline'] !== null): ?>
                    <p class="tr-lede"><?= tr_schedule_h($liveInfo['tagline']) ?></p>
                <?php endif; ?>

                <div class="tr-schedule-live-facts">
                    <?php if (!empty($nowPlaying['now_playing']['text'])): ?>
                        <div><span>now transmitting</span><strong><?= tr_schedule_h((string) $nowPlaying['now_playing']['text']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (is_int($nowPlaying['listeners'] ?? null)): ?>
                        <div><span>receivers</span><strong><?= (int) $nowPlaying['listeners'] ?> listener<?= (int) $nowPlaying['listeners'] === 1 ? '' : 's' ?></strong></div>
                    <?php endif; ?>
                </div>

                <div class="tr-schedule-actions">
                    <a class="tr-schedule-primary" href="<?= tr_schedule_h(asset('listen/')) ?>">listen now</a>
                    <?php if ($liveSlug !== ''): ?>
                        <a href="<?= tr_schedule_h(asset('djs/?dj=' . rawurlencode($liveSlug))) ?>">DJ profile</a>
                    <?php endif; ?>
                    <a href="<?= tr_schedule_h($ircUrl) ?>" rel="noopener">join #tilderadio</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="tr-title"><span class="tr-badge">CARRIER IDLE</span></div>
        <div class="tr-schedule-idle-grid">
            <div>
                <div class="tr-schedule-state">AUTODJ HAS THE TRANSMITTER</div>
                <h1>the frequency is still occupied</h1>
                <?php if (!empty($nowPlaying['now_playing']['text'])): ?>
                    <p class="tr-lede">Currently transmitting <?= tr_schedule_h((string) $nowPlaying['now_playing']['text']) ?>.</p>
                <?php else: ?>
                    <p class="tr-lede">AutoDJ is holding the stream until the next human takes over.</p>
                <?php endif; ?>
                <div class="tr-schedule-actions">
                    <a class="tr-schedule-primary" href="<?= tr_schedule_h(asset('listen/')) ?>">listen now</a>
                </div>
            </div>

            <?php if (is_array($nextEvent)): ?>
                <?php
                $nextName = is_array($nextProfile) && !empty($nextProfile['name'])
                    ? trim((string) $nextProfile['name'])
                    : trim((string) ($nextEvent['name'] ?? 'unknown DJ'));
                $nextSlug = trim((string) ($nextEvent['_profile_slug'] ?? $nextEvent['slug'] ?? ''));
                ?>
                <aside class="tr-schedule-next">
                    <span class="tr-schedule-kicker">NEXT TRANSMISSION</span>
                    <div class="tr-schedule-next-person">
                        <?php tr_schedule_render_avatar($nextProfile, $nextName); ?>
                        <div>
                            <strong><?= tr_schedule_h($nextName) ?></strong>
                            <?php if ($nextInfo['title'] !== null): ?>
                                <span><?= tr_schedule_h($nextInfo['title']) ?></span>
                            <?php elseif ($nextInfo['tagline'] !== null): ?>
                                <span><?= tr_schedule_h($nextInfo['tagline']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <time
                        datetime="<?= tr_schedule_h(gmdate(DATE_ATOM, (int) $nextEvent['start_ts'])) ?>"
                        data-schedule-time
                        data-include-date="true"
                        data-start-ts="<?= (int) $nextEvent['start_ts'] ?>"
                        data-end-ts="<?= is_int($nextEvent['end_ts'] ?? null) ? (int) $nextEvent['end_ts'] : 0 ?>"
                    ><?= tr_schedule_h(tr_schedule_utc_range($nextEvent, true)) ?></time>
                    <span class="tr-schedule-countdown" data-countdown-ts="<?= (int) $nextEvent['start_ts'] ?>"></span>
                    <?php if ($nextSlug !== ''): ?>
                        <a href="<?= tr_schedule_h(asset('djs/?dj=' . rawurlencode($nextSlug))) ?>">show details &rarr;</a>
                    <?php endif; ?>
                </aside>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($liveDj !== '' && is_array($nextEvent)): ?>
        <?php
        $afterName = is_array($nextProfile) && !empty($nextProfile['name'])
            ? trim((string) $nextProfile['name'])
            : trim((string) ($nextEvent['name'] ?? 'unknown DJ'));
        ?>
        <div class="tr-schedule-after">
            <span>next</span>
            <strong><?= tr_schedule_h($afterName) ?></strong>
            <?php if ($nextInfo['title'] !== null): ?><span><?= tr_schedule_h($nextInfo['title']) ?></span><?php endif; ?>
            <time
                datetime="<?= tr_schedule_h(gmdate(DATE_ATOM, (int) $nextEvent['start_ts'])) ?>"
                data-schedule-time
                data-include-date="true"
                data-start-ts="<?= (int) $nextEvent['start_ts'] ?>"
                data-end-ts="<?= is_int($nextEvent['end_ts'] ?? null) ? (int) $nextEvent['end_ts'] : 0 ?>"
            ><?= tr_schedule_h(tr_schedule_utc_range($nextEvent, true)) ?></time>
        </div>
    <?php endif; ?>
</section>

<section class="tr-section" id="upcoming">
    <div class="tr-schedule-heading-row">
        <div>
            <div class="tr-title"><span class="tr-badge">UPCOMING BROADCASTS</span></div>
            <h2>what is coming down the wire</h2>
        </div>
        <div class="tr-schedule-time-controls" aria-label="schedule timezone">
            <span>times</span>
            <button type="button" data-time-mode="local" aria-pressed="true">local</button>
            <button type="button" data-time-mode="utc" aria-pressed="false">UTC</button>
            <small data-timezone-label>browser local time</small>
        </div>
    </div>

    <?php if ($futureEvents): ?>
        <?php
        $groups = [];
        foreach ($futureEvents as $event) {
            $groups[gmdate('Y-m-d', (int) $event['start_ts'])][] = $event;
        }
        ?>
        <div class="tr-schedule-days" data-schedule-days>
            <?php foreach ($groups as $dayEvents): ?>
                <?php $dayTs = (int) $dayEvents[0]['start_ts']; ?>
                <section class="tr-schedule-day">
                    <h2 class="tr-schedule-day-title"><?= tr_schedule_h(tr_schedule_day_label_utc($dayTs, $nowTs)) ?></h2>
                    <div class="tr-schedule-day-list">
                        <?php foreach ($dayEvents as $event): ?>
                            <?php tr_schedule_render_event($event, $catalog); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="tr-muted">No upcoming live broadcasts are currently listed. AutoDJ will keep the transmitter warm.</p>
    <?php endif; ?>
</section>

<section class="tr-section tr-calendar-section" id="calendar">
    <details class="tr-calendar-panel">
        <summary>
            <span>
                <strong>calendar view</strong>
                <small>seven-day UTC grid</small>
            </span>
            <span aria-hidden="true">+</span>
        </summary>
        <p class="tr-muted">The grid stays in UTC so the day columns never move. The broadcast list above can switch between your local timezone and UTC.</p>
        <?php
        $calendarStart = strtotime(gmdate('Y-m-d 00:00:00', $nowTs) . ' UTC');
        $calendarEnd = $calendarStart + (7 * 86400);
        $calendarEvents = array_values(array_filter(
            $events,
            static function (array $event) use ($calendarStart, $calendarEnd): bool {
                $start = is_int($event['start_ts'] ?? null) ? $event['start_ts'] : 0;
                $end = is_int($event['end_ts'] ?? null) ? $event['end_ts'] : $start + 1800;
                return $start < $calendarEnd && $end > $calendarStart;
            }
        ));
        include __DIR__ . '/calendar.php';
        ?>
    </details>
</section>

<section class="tr-section">
    <div class="tr-schedule-bottom-grid">
        <article class="tr-schedule-callout">
            <span class="tr-schedule-kicker">FOLLOW THE SCHEDULE</span>
            <h2>put the station in your calendar</h2>
            <p>Subscribe once and scheduled live broadcasts will show up in your calendar client as the station changes.</p>
            <p><a href="<?= tr_schedule_h(asset('schedule/ics.php')) ?>">subscribe via iCalendar &rarr;</a></p>
            <code>https://tilderadio.org/schedule/ics.php</code>
        </article>

        <article class="tr-schedule-callout">
            <span class="tr-schedule-kicker">JOIN THE ROOM</span>
            <h2><?= $liveDj !== '' ? 'the live show continues on IRC' : 'radio is better with a room around it' ?></h2>
            <p>
                <?= $liveDj !== ''
                    ? 'Drop into #tilderadio while the set is live. Ask the DJ a question, check into the radio couch, throw some props, or make a request when the DJ opens the line.'
                    : 'When a DJ goes live, #tilderadio becomes the room around the broadcast. Carrier ties questions, requests, reactions, show notes, and the station together.' ?>
            </p>
            <p>
                <a href="<?= tr_schedule_h($ircUrl) ?>" rel="noopener">join #tilderadio</a>
                &nbsp;&middot;&nbsp;
                <a href="<?= tr_schedule_h(asset('community/carrier/')) ?>">Carrier guide</a>
            </p>
        </article>
    </div>
</section>

<section class="tr-section tr-schedule-transmissions">
    <div>
        <span class="tr-schedule-kicker">STATION TRANSMISSIONS</span>
        <h2>Carrier also sends signals into the fediverse</h2>
        <p class="tr-muted">Live starts, handoffs, unusual crowds, station records, and the occasional worthwhile set log. Not a repost of everything happening on IRC.</p>
    </div>
    <a href="<?= tr_schedule_h($mastodonUrl) ?>" rel="me noopener"><?= tr_schedule_h($mastodonHandle) ?> &rarr;</a>
</section>

<script>
(function () {
    const daysRoot = document.querySelector('[data-schedule-days]');
    const modeButtons = Array.from(document.querySelectorAll('[data-time-mode]'));
    const zoneLabel = document.querySelector('[data-timezone-label]');
    const storageKey = 'tilderadio.schedule.timeMode';
    let mode = 'local';

    try {
        const stored = window.localStorage.getItem(storageKey);
        if (stored === 'utc' || stored === 'local') mode = stored;
    } catch (error) {
        // Local storage is optional. The schedule still works without it.
    }

    function zoneOption() {
        return mode === 'utc' ? { timeZone: 'UTC' } : {};
    }

    function dateKey(date) {
        const year = mode === 'utc' ? date.getUTCFullYear() : date.getFullYear();
        const month = (mode === 'utc' ? date.getUTCMonth() : date.getMonth()) + 1;
        const day = mode === 'utc' ? date.getUTCDate() : date.getDate();
        return [year, String(month).padStart(2, '0'), String(day).padStart(2, '0')].join('-');
    }

    function dayLabel(timestamp) {
        const date = new Date(timestamp * 1000);
        const now = new Date();
        const tomorrow = new Date(now.getTime());
        if (mode === 'utc') tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);
        else tomorrow.setDate(tomorrow.getDate() + 1);

        let prefix = '';
        if (dateKey(date) === dateKey(now)) prefix = 'TODAY · ';
        else if (dateKey(date) === dateKey(tomorrow)) prefix = 'TOMORROW · ';

        const formatter = new Intl.DateTimeFormat([], {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            ...zoneOption()
        });
        return (prefix + formatter.format(date)).toUpperCase();
    }

    function timezoneName(date) {
        if (mode === 'utc') return 'UTC';
        const parts = new Intl.DateTimeFormat([], {
            timeZoneName: 'short'
        }).formatToParts(date);
        const zone = parts.find((part) => part.type === 'timeZoneName');
        return zone ? zone.value : 'local';
    }

    function timeText(node) {
        const startTs = Number(node.dataset.startTs || 0);
        const endTs = Number(node.dataset.endTs || 0);
        if (!startTs) return;

        const start = new Date(startTs * 1000);
        const end = endTs ? new Date(endTs * 1000) : null;
        const timeFormatter = new Intl.DateTimeFormat([], {
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
            ...zoneOption()
        });
        const dateFormatter = new Intl.DateTimeFormat([], {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            ...zoneOption()
        });

        let text = '';
        if (node.dataset.includeDate === 'true') {
            text += dateFormatter.format(start) + ' · ';
        }
        text += timeFormatter.format(start);
        if (end) text += '–' + timeFormatter.format(end);
        text += ' ' + timezoneName(start);
        node.textContent = text;
    }

    function rebuildDays() {
        if (!daysRoot) return;
        const events = Array.from(daysRoot.querySelectorAll('[data-schedule-event]'));
        if (!events.length) return;

        events.sort((a, b) => Number(a.dataset.startTs) - Number(b.dataset.startTs));
        daysRoot.replaceChildren();

        let currentKey = '';
        let list = null;
        for (const event of events) {
            const timestamp = Number(event.dataset.startTs || 0);
            const key = dateKey(new Date(timestamp * 1000));
            if (key !== currentKey) {
                currentKey = key;
                const section = document.createElement('section');
                section.className = 'tr-schedule-day';
                const heading = document.createElement('h2');
                heading.className = 'tr-schedule-day-title';
                heading.textContent = dayLabel(timestamp);
                list = document.createElement('div');
                list.className = 'tr-schedule-day-list';
                section.append(heading, list);
                daysRoot.append(section);
            }
            list.append(event);
        }
    }

    function updateCountdowns() {
        const now = Date.now() / 1000;
        document.querySelectorAll('[data-countdown-ts]').forEach((node) => {
            const target = Number(node.dataset.countdownTs || 0);
            const delta = Math.round(target - now);
            if (!target) return;
            if (delta <= 0) {
                node.textContent = 'starting now';
                return;
            }
            const days = Math.floor(delta / 86400);
            const hours = Math.floor((delta % 86400) / 3600);
            const minutes = Math.max(1, Math.floor((delta % 3600) / 60));
            const bits = [];
            if (days) bits.push(days + 'd');
            if (hours) bits.push(hours + 'h');
            if (!days && minutes) bits.push(minutes + 'm');
            node.textContent = 'starts in ' + bits.join(' ');
        });
    }

    function updatePointer() {
        const cells = Array.from(document.querySelectorAll('.calendar td[data-slot-ts]'));
        if (!cells.length) return;
        const now = Date.now();
        const current = cells.find((cell) => {
            const start = Number(cell.dataset.slotTs || 0) * 1000;
            return start <= now && now < start + 1800000;
        });

        document.querySelectorAll('.calendar td.active').forEach((cell) => cell.classList.remove('active'));
        let pointer = document.getElementById('pointer');
        if (!current) {
            if (pointer) pointer.remove();
            return;
        }
        current.classList.add('active');
        if (!pointer) {
            pointer = document.createElement('div');
            pointer.id = 'pointer';
        }
        current.append(pointer);
        const start = Number(current.dataset.slotTs || 0) * 1000;
        const progress = Math.max(0, Math.min(1, (now - start) / 1800000));
        pointer.style.top = Math.round(progress * Math.max(0, current.offsetHeight - 1)) + 'px';
    }

    function applyMode(nextMode) {
        mode = nextMode === 'utc' ? 'utc' : 'local';
        modeButtons.forEach((button) => {
            button.setAttribute('aria-pressed', button.dataset.timeMode === mode ? 'true' : 'false');
        });
        if (zoneLabel) {
            zoneLabel.textContent = mode === 'utc'
                ? 'coordinated universal time'
                : Intl.DateTimeFormat().resolvedOptions().timeZone || 'browser local time';
        }
        document.querySelectorAll('[data-schedule-time]').forEach(timeText);
        rebuildDays();
        try {
            window.localStorage.setItem(storageKey, mode);
        } catch (error) {
            // Nothing to do.
        }
    }

    modeButtons.forEach((button) => {
        button.addEventListener('click', () => applyMode(button.dataset.timeMode));
    });

    const calendarDetails = document.querySelector('.tr-calendar-panel');
    if (calendarDetails) calendarDetails.addEventListener('toggle', updatePointer);

    applyMode(mode);
    updateCountdowns();
    updatePointer();
    window.setInterval(updateCountdowns, 60000);
    window.setInterval(updatePointer, 30000);
})();
</script>

<?php include dirname(__DIR__) . '/footer.php'; ?>
