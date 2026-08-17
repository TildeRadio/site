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
