<?php

declare(strict_types=1);

const TR_AZURACAST_BASE = 'https://azuracast.tilderadio.org';
const TR_STATION_SHORTCODE = 'tilderadio';
const TR_STATION_ID = 1;
const TR_PUBLIC_SCHEDULE_URL = 'https://tilderadio.org/schedule/schedule.php?json=yes';

/**
 * Fetch and decode a JSON document over HTTPS.
 *
 * @param array<int, string> $headers
 */
function tr_http_get_json(string $url, array $headers = [], int $timeout = 5): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    $requestHeaders = ['Accept: application/json'];
    foreach ($headers as $header) {
        if (is_string($header) && $header !== '') {
            $requestHeaders[] = $header;
        }
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'tilderadio-site/2.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => $requestHeaders,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $status < 200 || $status >= 300) {
        return null;
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function tr_azuracast_api_key(): ?string
{
    $env = getenv('AZURACAST_API_KEY');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    $apikey = null;
    $legacyFile = dirname(__DIR__) . '/schedule/apikey.php';
    if (is_file($legacyFile)) {
        include $legacyFile;
    }

    return isset($apikey) && is_string($apikey) && trim($apikey) !== ''
        ? trim($apikey)
        : null;
}

/**
 * Normalize an AzuraCast song object into the public site/API shape.
 */
function tr_normalize_song(array $song): array
{
    $artist = trim((string) ($song['artist'] ?? ''));
    $title = trim((string) ($song['title'] ?? ''));
    $text = trim((string) ($song['text'] ?? ''));

    if ($text === '') {
        $text = trim($artist . ' - ' . $title, ' -');
    }

    $art = trim((string) ($song['art'] ?? ''));

    return [
        'artist' => $artist !== '' ? $artist : null,
        'title' => $title !== '' ? $title : null,
        'text' => $text !== '' ? $text : null,
        'art' => $art !== '' ? $art : null,
    ];
}

/**
 * Return normalized public Now Playing data.
 *
 * The static endpoint is preferred because it is inexpensive to serve. The
 * standard endpoint remains as a fallback so a temporary static-file issue
 * does not blank the site.
 */
function tr_now_playing(): array
{
    $base = rtrim(TR_AZURACAST_BASE, '/');
    $shortcode = rawurlencode(TR_STATION_SHORTCODE);

    $data = tr_http_get_json($base . '/api/nowplaying_static/' . $shortcode . '.json', [], 4);
    if ($data === null) {
        $data = tr_http_get_json($base . '/api/nowplaying/' . $shortcode, [], 5);
    }

    if ($data === null) {
        return [
            'available' => false,
            'station' => null,
            'is_live' => false,
            'dj' => null,
            'listeners' => null,
            'now_playing' => null,
            'history' => [],
            'updated_at' => time(),
        ];
    }

    $station = is_array($data['station'] ?? null) ? $data['station'] : [];
    $nowPlaying = is_array($data['now_playing'] ?? null) ? $data['now_playing'] : [];
    $live = is_array($data['live'] ?? null) ? $data['live'] : [];
    $listeners = is_array($data['listeners'] ?? null) ? $data['listeners'] : [];

    $song = tr_normalize_song(is_array($nowPlaying['song'] ?? null) ? $nowPlaying['song'] : []);
    $playedAt = is_numeric($nowPlaying['played_at'] ?? null) ? (int) $nowPlaying['played_at'] : null;
    $duration = is_numeric($nowPlaying['duration'] ?? null) ? max(0, (int) $nowPlaying['duration']) : null;

    $elapsed = null;
    if ($playedAt !== null) {
        $elapsed = max(0, time() - $playedAt);
        if ($duration !== null && $duration > 0) {
            $elapsed = min($elapsed, $duration);
        }
    } elseif (is_numeric($nowPlaying['elapsed'] ?? null)) {
        $elapsed = max(0, (int) $nowPlaying['elapsed']);
    }

    $remaining = null;
    if ($duration !== null && $duration > 0 && $elapsed !== null) {
        $remaining = max(0, $duration - $elapsed);
    } elseif (is_numeric($nowPlaying['remaining'] ?? null)) {
        $remaining = max(0, (int) $nowPlaying['remaining']);
    }

    $history = [];
    foreach (($data['song_history'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $historySong = tr_normalize_song(is_array($row['song'] ?? null) ? $row['song'] : []);
        if ($historySong['text'] === null) {
            continue;
        }

        $history[] = [
            ...$historySong,
            'played_at' => is_numeric($row['played_at'] ?? null) ? (int) $row['played_at'] : null,
            'duration' => is_numeric($row['duration'] ?? null) ? max(0, (int) $row['duration']) : null,
        ];
    }

    $listenerCount = null;
    foreach (['current', 'unique', 'total'] as $key) {
        if (is_numeric($listeners[$key] ?? null)) {
            $listenerCount = max(0, (int) $listeners[$key]);
            break;
        }
    }

    $streamerName = trim((string) ($live['streamer_name'] ?? ''));
    $isLive = !empty($live['is_live']);

    return [
        'available' => true,
        'station' => [
            'name' => trim((string) ($station['name'] ?? 'tilderadio')) ?: 'tilderadio',
            'shortcode' => trim((string) ($station['shortcode'] ?? TR_STATION_SHORTCODE)) ?: TR_STATION_SHORTCODE,
            'listen_url' => trim((string) ($station['listen_url'] ?? 'https://tilderadio.org/listen')) ?: 'https://tilderadio.org/listen',
        ],
        'is_live' => $isLive,
        'dj' => $isLive && $streamerName !== '' ? $streamerName : null,
        'listeners' => $listenerCount,
        'now_playing' => [
            ...$song,
            'played_at' => $playedAt,
            'duration' => $duration,
            'elapsed' => $elapsed,
            'remaining' => $remaining,
        ],
        'history' => $history,
        'updated_at' => time(),
    ];
}

function tr_slug(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = str_replace('_', '-', $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    return trim($slug, '-');
}

function tr_show_formats(array $profile): array
{
    $show = is_array($profile['show'] ?? null) ? $profile['show'] : [];
    $formats = is_array($show['formats'] ?? null) && array_is_list($show['formats'])
        ? $show['formats']
        : [];

    $result = [];
    foreach ($formats as $format) {
        if (!is_array($format) || array_is_list($format)) {
            continue;
        }

        $daysRaw = $format['days'] ?? ($format['weekday'] ?? []);
        $daysRaw = is_string($daysRaw) ? [$daysRaw] : $daysRaw;
        $days = [];
        if (is_array($daysRaw)) {
            foreach ($daysRaw as $day) {
                if (!is_string($day) || trim($day) === '') {
                    continue;
                }
                $normalized = strtolower(trim($day));
                if (in_array($normalized, [
                    'monday', 'tuesday', 'wednesday', 'thursday',
                    'friday', 'saturday', 'sunday',
                ], true)) {
                    $days[] = $normalized;
                }
            }
        }

        $genres = [];
        if (is_array($format['genres'] ?? null)) {
            foreach ($format['genres'] as $genre) {
                if (is_string($genre) && trim($genre) !== '') {
                    $genres[] = trim($genre);
                }
            }
        }

        $result[] = [
            'id' => tr_slug((string) ($format['id'] ?? '')),
            'title' => is_string($format['title'] ?? null) && trim($format['title']) !== ''
                ? trim($format['title'])
                : null,
            'tagline' => is_string($format['tagline'] ?? null) && trim($format['tagline']) !== ''
                ? trim($format['tagline'])
                : null,
            'description' => is_string($format['description'] ?? null) && trim($format['description']) !== ''
                ? trim($format['description'])
                : null,
            'days' => array_values(array_unique($days)),
            'genres' => array_values(array_unique($genres)),
        ];
    }

    return $result;
}

/**
 * Resolve static show metadata plus an optional weekday-specific format.
 *
 * The weekday is evaluated in show.timezone so a local Tuesday evening show
 * does not become a Wednesday format merely because UTC has crossed midnight.
 *
 * @return array<string, mixed>
 */
function tr_show_context(?array $profile, ?int $timestamp = null): array
{
    if ($profile === null) {
        return [
            'title' => null,
            'display_title' => null,
            'tagline' => null,
            'description' => null,
            'genres' => [],
            'timezone' => 'UTC',
            'format' => null,
        ];
    }

    $show = is_array($profile['show'] ?? null) ? $profile['show'] : [];
    $clean = static fn (mixed $value): ?string =>
        is_string($value) && trim($value) !== '' ? trim($value) : null;

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

    $timezoneName = $clean($show['timezone'] ?? null) ?? 'UTC';
    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Exception) {
        $timezoneName = 'UTC';
        $timezone = new DateTimeZone('UTC');
    }

    $selected = null;
    if ($timestamp !== null) {
        $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        $weekday = strtolower($date->format('l'));

        foreach (tr_show_formats($profile) as $format) {
            if (in_array($weekday, $format['days'], true)) {
                $selected = $format;
                break;
            }
        }
    }

    if (is_array($selected)) {
        $tagline = $selected['tagline'] ?? $tagline;
        $description = $selected['description'] ?? $description;
        if (!empty($selected['genres'])) {
            $genres = $selected['genres'];
        }
    }

    $formatTitle = is_array($selected) ? ($selected['title'] ?? null) : null;
    $displayTitle = $title;
    if (is_string($formatTitle) && $formatTitle !== '') {
        $displayTitle = $title !== null && strcasecmp($title, $formatTitle) !== 0
            ? $title . ' · ' . $formatTitle
            : $formatTitle;
    }

    return [
        'title' => $title,
        'display_title' => $displayTitle,
        'tagline' => $tagline,
        'description' => $description,
        'genres' => array_values(array_unique($genres)),
        'timezone' => $timezoneName,
        'format' => $selected,
    ];
}

function tr_episode_archive_path(): string
{
    $configured = getenv('TILDERADIO_EPISODES_FILE');
    if (is_string($configured) && trim($configured) !== '') {
        return trim($configured);
    }

    return '/var/lib/tilderadio-bot/episodes.json';
}

/**
 * Load Carrier's atomically exported public episode archive.
 *
 * @return array{version:int,generated_at:?int,episodes:array<int,array<string,mixed>>}
 */
function tr_episode_archive(): array
{
    $empty = ['version' => 1, 'generated_at' => null, 'episodes' => []];
    $path = tr_episode_archive_path();

    if (!is_file($path) || !is_readable($path)) {
        return $empty;
    }

    $size = filesize($path);
    if ($size === false || $size < 2 || $size > 10 * 1024 * 1024) {
        return $empty;
    }

    $json = file_get_contents($path);
    if (!is_string($json)) {
        return $empty;
    }

    try {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return $empty;
    }

    if (!is_array($decoded) || !is_array($decoded['episodes'] ?? null)) {
        return $empty;
    }

    $episodes = array_values(array_filter(
        $decoded['episodes'],
        static fn (mixed $episode): bool =>
            is_array($episode)
            && is_int($episode['id'] ?? null)
            && is_string($episode['dj_slug'] ?? null)
            && is_int($episode['started_at'] ?? null)
    ));

    return [
        'version' => is_int($decoded['version'] ?? null) ? $decoded['version'] : 1,
        'generated_at' => is_int($decoded['generated_at'] ?? null) ? $decoded['generated_at'] : null,
        'episodes' => $episodes,
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function tr_episodes_for_dj(string $slug, int $limit = 10): array
{
    $slug = tr_slug($slug);
    $limit = max(1, min(50, $limit));
    $episodes = [];

    foreach (tr_episode_archive()['episodes'] as $episode) {
        if (tr_slug((string) ($episode['dj_slug'] ?? '')) !== $slug) {
            continue;
        }
        $episodes[] = $episode;
        if (count($episodes) >= $limit) {
            break;
        }
    }

    return $episodes;
}

function tr_episode_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    foreach (tr_episode_archive()['episodes'] as $episode) {
        if (($episode['id'] ?? null) === $id) {
            return $episode;
        }
    }

    return null;
}

function tr_episode_title(array $episode): string
{
    $show = is_array($episode['show'] ?? null) ? $episode['show'] : [];
    foreach (['episode', 'topic'] as $key) {
        if (is_string($show[$key] ?? null) && trim($show[$key]) !== '') {
            return trim($show[$key]);
        }
    }

    $format = is_array($show['format'] ?? null) ? $show['format'] : [];
    if (is_string($format['title'] ?? null) && trim($format['title']) !== '') {
        return trim($format['title']);
    }
    if (is_string($show['title'] ?? null) && trim($show['title']) !== '') {
        return trim($show['title']);
    }

    return 'Set #' . (int) ($episode['id'] ?? 0);
}

/**
 * Load optional hand-authored DJ/show metadata.
 */
function tr_dj_metadata(): array
{
    $file = dirname(__DIR__) . '/data/djs.php';
    if (!is_file($file)) {
        return [];
    }

    $data = require $file;
    return is_array($data) ? $data : [];
}

/**
 * Fetch upcoming schedule rows, normalized for the public site/API.
 */
function tr_schedule(int $days = 14): array
{
    $days = max(1, min($days, 31));
    $apiKey = tr_azuracast_api_key();

    if ($apiKey !== null) {
        $from = gmdate('Y-m-d\\TH:i:s\\Z');
        $to = gmdate('Y-m-d\\TH:i:s\\Z', time() + ($days * 86400));
        $url = rtrim(TR_AZURACAST_BASE, '/')
            . '/api/station/' . TR_STATION_ID . '/streamers/schedule'
            . '?start=' . rawurlencode($from)
            . '&end=' . rawurlencode($to);

        $rows = tr_http_get_json($url, ['Authorization: Bearer ' . $apiKey], 6);
    } else {
        $rows = tr_http_get_json(TR_PUBLIC_SCHEDULE_URL, [], 6);
    }

    if ($rows === null) {
        return [];
    }

    $schedule = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = trim((string) ($row['title'] ?? $row['name'] ?? ''));
        $startRaw = trim((string) ($row['start'] ?? ''));
        $endRaw = trim((string) ($row['end'] ?? ''));
        $start = $startRaw !== '' ? strtotime($startRaw) : false;
        $end = $endRaw !== '' ? strtotime($endRaw) : false;

        if ($name === '' || $start === false) {
            continue;
        }

        $schedule[] = [
            'name' => $name,
            'slug' => tr_slug($name),
            'start' => $startRaw,
            'end' => $endRaw !== '' ? $endRaw : null,
            'start_ts' => $start,
            'end_ts' => $end !== false ? $end : null,
        ];
    }

    usort($schedule, static fn (array $a, array $b): int => $a['start_ts'] <=> $b['start_ts']);
    return $schedule;
}

/**
 * Build a DJ catalog from the live schedule, then layer configured metadata on
 * top. This means a newly scheduled DJ automatically gets a basic profile.
 */
function tr_dj_catalog(): array
{
    $catalog = [];

    foreach (tr_schedule() as $event) {
        $slug = $event['slug'];
        if ($slug === '') {
            continue;
        }

        if (!isset($catalog[$slug])) {
            $catalog[$slug] = [
                'slug' => $slug,
                'name' => $event['name'],
                'description' => null,
                'tilde' => null,
                'irc' => null,
                'links' => [],
                'show' => null,
                'upcoming' => [],
            ];
        }

        $catalog[$slug]['upcoming'][] = $event;
    }

    foreach (tr_dj_metadata() as $key => $meta) {
        if (!is_array($meta)) {
            continue;
        }

        $configuredName = trim((string) ($meta['name'] ?? $key));
        $slug = tr_slug((string) ($meta['slug'] ?? $configuredName));
        if ($slug === '') {
            continue;
        }

        $base = $catalog[$slug] ?? [
            'slug' => $slug,
            'name' => $configuredName !== '' ? $configuredName : $slug,
            'description' => null,
            'tilde' => null,
            'irc' => null,
            'links' => [],
            'show' => null,
            'upcoming' => [],
        ];

        $catalog[$slug] = array_replace($base, $meta, [
            'slug' => $slug,
            'name' => $configuredName !== '' ? $configuredName : $base['name'],
            'upcoming' => $base['upcoming'],
        ]);
    }

    uasort(
        $catalog,
        static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name'])
    );

    return $catalog;
}

function tr_dj_profile(string $slug): ?array
{
    $slug = tr_slug($slug);
    if ($slug === '') {
        return null;
    }

    $catalog = tr_dj_catalog();
    return isset($catalog[$slug]) && is_array($catalog[$slug]) ? $catalog[$slug] : null;
}

function tr_next_schedule_event(): ?array
{
    $now = time();
    $firstFuture = null;

    foreach (tr_schedule(8) as $event) {
        $end = $event['end_ts'] ?? null;
        if (is_int($end) && $event['start_ts'] <= $now && $now < $end) {
            return [...$event, 'is_live' => true];
        }

        if ($event['start_ts'] > $now && $firstFuture === null) {
            $firstFuture = [...$event, 'is_live' => false];
        }
    }

    return $firstFuture;
}
