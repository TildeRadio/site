<?php

declare(strict_types=1);

const TR_STATS_VERSION = 1;
const TR_STATS_DEFAULT_DIR = '/var/lib/tilderadio/stats';
const TR_STATS_MAX_SAMPLE_GAP = 180;

function tr_stats_storage_dir(?string $override = null): string
{
    if (is_string($override) && trim($override) !== '') {
        return rtrim(trim($override), '/');
    }

    $env = getenv('TILDERADIO_STATS_DIR');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }

    return TR_STATS_DEFAULT_DIR;
}

function tr_stats_empty_bucket(): array
{
    return [
        'coverage_seconds' => 0,
        'listener_seconds' => 0,
        'listener_observed_seconds' => 0,
        'live_seconds' => 0,
        'autodj_seconds' => 0,
        'samples' => 0,
        'live_samples' => 0,
        'live_sessions' => 0,
        'peak_listeners' => null,
        'peak_at' => null,
    ];
}

function tr_stats_empty_state(): array
{
    return [
        'version' => TR_STATS_VERSION,
        'started_at' => null,
        'updated_at' => null,
        'sample_count' => 0,
        'last_sample' => null,
        'totals' => tr_stats_empty_bucket(),
        'days' => [],
        'djs' => [],
        'session' => null,
        'latest_live_at' => null,
    ];
}

function tr_stats_normalize_sample(array $sample): ?array
{
    $ts = is_numeric($sample['ts'] ?? null) ? (int) $sample['ts'] : 0;
    if ($ts <= 0) {
        return null;
    }

    $listeners = is_numeric($sample['listeners'] ?? null)
        ? max(0, (int) $sample['listeners'])
        : null;
    $isLive = !empty($sample['is_live']);
    $dj = isset($sample['dj']) && is_string($sample['dj']) ? trim($sample['dj']) : '';
    $dj = $isLive && $dj !== '' ? $dj : null;

    return [
        'ts' => $ts,
        'listeners' => $listeners,
        'is_live' => $isLive,
        'dj' => $dj,
        'dj_slug' => $dj !== null ? tr_slug($dj) : null,
    ];
}

function tr_stats_load_state(?string $directory = null): array
{
    $directory = tr_stats_storage_dir($directory);
    $file = $directory . '/state.json';
    if (!is_file($file)) {
        return tr_stats_empty_state();
    }

    $json = file_get_contents($file);
    if (!is_string($json) || trim($json) === '') {
        return tr_stats_empty_state();
    }

    try {
        $state = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return tr_stats_empty_state();
    }

    if (!is_array($state) || (int) ($state['version'] ?? 0) !== TR_STATS_VERSION) {
        return tr_stats_empty_state();
    }

    return array_replace_recursive(tr_stats_empty_state(), $state);
}

function tr_stats_write_json_atomic(string $file, array $data): bool
{
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($json)) {
        return false;
    }

    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function tr_stats_ensure_dir(string $directory): bool
{
    if (is_dir($directory)) {
        return is_writable($directory);
    }

    return @mkdir($directory, 0755, true) && is_writable($directory);
}

function tr_stats_day_bucket(array &$state, string $day): array
{
    $existing = $state['days'][$day] ?? null;
    $bucket = is_array($existing) ? array_replace(tr_stats_empty_bucket(), $existing) : tr_stats_empty_bucket();
    $bucket['djs'] = is_array($existing['djs'] ?? null) ? $existing['djs'] : [];
    return $bucket;
}

function tr_stats_dj_bucket(array $existing = []): array
{
    return array_replace([
        'name' => null,
        'seconds' => 0,
        'listener_seconds' => 0,
        'listener_observed_seconds' => 0,
        'sessions' => 0,
        'peak_listeners' => null,
        'peak_at' => null,
        'first_seen_at' => null,
        'last_seen_at' => null,
    ], $existing);
}

function tr_stats_update_peak(array &$bucket, ?int $listeners, int $ts): void
{
    if ($listeners === null) {
        return;
    }

    $peak = is_numeric($bucket['peak_listeners'] ?? null) ? (int) $bucket['peak_listeners'] : null;
    if ($peak === null || $listeners > $peak) {
        $bucket['peak_listeners'] = $listeners;
        $bucket['peak_at'] = $ts;
    }
}

/**
 * Split an interval at UTC midnight boundaries.
 *
 * @return array<int, array{day: string, start: int, end: int, seconds: int}>
 */
function tr_stats_split_days(int $start, int $end): array
{
    $segments = [];
    $cursor = $start;

    while ($cursor < $end) {
        $day = gmdate('Y-m-d', $cursor);
        $nextMidnight = strtotime($day . ' 00:00:00 UTC +1 day');
        if ($nextMidnight === false || $nextMidnight <= $cursor) {
            $nextMidnight = $end;
        }
        $segmentEnd = min($end, $nextMidnight);
        $segments[] = [
            'day' => $day,
            'start' => $cursor,
            'end' => $segmentEnd,
            'seconds' => $segmentEnd - $cursor,
        ];
        $cursor = $segmentEnd;
    }

    return $segments;
}

function tr_stats_add_interval_to_bucket(array &$bucket, array $sample, int $seconds): void
{
    $bucket['coverage_seconds'] = (int) ($bucket['coverage_seconds'] ?? 0) + $seconds;

    if (is_int($sample['listeners'] ?? null)) {
        $bucket['listener_seconds'] = (int) ($bucket['listener_seconds'] ?? 0)
            + ($sample['listeners'] * $seconds);
        $bucket['listener_observed_seconds'] = (int) ($bucket['listener_observed_seconds'] ?? 0) + $seconds;
    }

    if (!empty($sample['is_live'])) {
        $bucket['live_seconds'] = (int) ($bucket['live_seconds'] ?? 0) + $seconds;
    } else {
        $bucket['autodj_seconds'] = (int) ($bucket['autodj_seconds'] ?? 0) + $seconds;
    }
}

function tr_stats_integrate_interval(array &$state, array $previous, int $endTs): void
{
    $startTs = (int) $previous['ts'];
    $gap = $endTs - $startTs;
    if ($gap <= 0 || $gap > TR_STATS_MAX_SAMPLE_GAP) {
        return;
    }

    foreach (tr_stats_split_days($startTs, $endTs) as $segment) {
        $seconds = $segment['seconds'];
        tr_stats_add_interval_to_bucket($state['totals'], $previous, $seconds);

        $day = tr_stats_day_bucket($state, $segment['day']);
        tr_stats_add_interval_to_bucket($day, $previous, $seconds);

        if (!empty($previous['is_live']) && is_string($previous['dj_slug'] ?? null) && $previous['dj_slug'] !== '') {
            $slug = $previous['dj_slug'];
            $name = is_string($previous['dj'] ?? null) && $previous['dj'] !== '' ? $previous['dj'] : $slug;

            $dj = tr_stats_dj_bucket(is_array($state['djs'][$slug] ?? null) ? $state['djs'][$slug] : []);
            $dj['name'] = $name;
            $dj['seconds'] += $seconds;
            if (is_int($previous['listeners'] ?? null)) {
                $dj['listener_seconds'] += $previous['listeners'] * $seconds;
                $dj['listener_observed_seconds'] += $seconds;
            }
            $dj['first_seen_at'] = $dj['first_seen_at'] ?? $startTs;
            $dj['last_seen_at'] = $segment['end'];
            $state['djs'][$slug] = $dj;

            $dayDj = tr_stats_dj_bucket(is_array($day['djs'][$slug] ?? null) ? $day['djs'][$slug] : []);
            $dayDj['name'] = $name;
            $dayDj['seconds'] += $seconds;
            if (is_int($previous['listeners'] ?? null)) {
                $dayDj['listener_seconds'] += $previous['listeners'] * $seconds;
                $dayDj['listener_observed_seconds'] += $seconds;
            }
            $dayDj['first_seen_at'] = $dayDj['first_seen_at'] ?? $startTs;
            $dayDj['last_seen_at'] = $segment['end'];
            $day['djs'][$slug] = $dayDj;
        }

        $state['days'][$segment['day']] = $day;
    }
}

function tr_stats_start_session(array &$state, array $sample): void
{
    if (empty($sample['is_live'])) {
        $state['session'] = null;
        return;
    }

    $slug = is_string($sample['dj_slug'] ?? null) ? $sample['dj_slug'] : '';
    $name = is_string($sample['dj'] ?? null) && $sample['dj'] !== '' ? $sample['dj'] : 'live DJ';
    $ts = (int) $sample['ts'];

    $state['session'] = [
        'dj_slug' => $slug,
        'dj' => $name,
        'started_at' => $ts,
    ];
    $state['totals']['live_sessions'] = (int) ($state['totals']['live_sessions'] ?? 0) + 1;

    $dayKey = gmdate('Y-m-d', $ts);
    $day = tr_stats_day_bucket($state, $dayKey);
    $day['live_sessions'] = (int) ($day['live_sessions'] ?? 0) + 1;

    // An anonymous live connection still counts as a station session, but it
    // should not create a fake DJ in the public leaderboard.
    if ($slug !== '') {
        $dj = tr_stats_dj_bucket(is_array($state['djs'][$slug] ?? null) ? $state['djs'][$slug] : []);
        $dj['name'] = $name;
        $dj['sessions']++;
        $dj['first_seen_at'] = $dj['first_seen_at'] ?? $ts;
        $dj['last_seen_at'] = $ts;
        $state['djs'][$slug] = $dj;

        $dayDj = tr_stats_dj_bucket(is_array($day['djs'][$slug] ?? null) ? $day['djs'][$slug] : []);
        $dayDj['name'] = $name;
        $dayDj['sessions']++;
        $dayDj['first_seen_at'] = $dayDj['first_seen_at'] ?? $ts;
        $dayDj['last_seen_at'] = $ts;
        $day['djs'][$slug] = $dayDj;
    }
    $state['days'][$dayKey] = $day;
}

function tr_stats_update_sample_counters(array &$state, array $sample): void
{
    $ts = (int) $sample['ts'];
    $dayKey = gmdate('Y-m-d', $ts);
    $day = tr_stats_day_bucket($state, $dayKey);

    $state['totals']['samples'] = (int) ($state['totals']['samples'] ?? 0) + 1;
    $day['samples'] = (int) ($day['samples'] ?? 0) + 1;

    if (!empty($sample['is_live'])) {
        $state['totals']['live_samples'] = (int) ($state['totals']['live_samples'] ?? 0) + 1;
        $day['live_samples'] = (int) ($day['live_samples'] ?? 0) + 1;
        $state['latest_live_at'] = $ts;
    }

    tr_stats_update_peak($state['totals'], $sample['listeners'], $ts);
    tr_stats_update_peak($day, $sample['listeners'], $ts);

    if (!empty($sample['is_live']) && is_string($sample['dj_slug'] ?? null) && $sample['dj_slug'] !== '') {
        $slug = $sample['dj_slug'];
        $name = is_string($sample['dj'] ?? null) && $sample['dj'] !== '' ? $sample['dj'] : $slug;

        $dj = tr_stats_dj_bucket(is_array($state['djs'][$slug] ?? null) ? $state['djs'][$slug] : []);
        $dj['name'] = $name;
        $dj['first_seen_at'] = $dj['first_seen_at'] ?? $ts;
        $dj['last_seen_at'] = $ts;
        tr_stats_update_peak($dj, $sample['listeners'], $ts);
        $state['djs'][$slug] = $dj;

        $dayDj = tr_stats_dj_bucket(is_array($day['djs'][$slug] ?? null) ? $day['djs'][$slug] : []);
        $dayDj['name'] = $name;
        $dayDj['first_seen_at'] = $dayDj['first_seen_at'] ?? $ts;
        $dayDj['last_seen_at'] = $ts;
        tr_stats_update_peak($dayDj, $sample['listeners'], $ts);
        $day['djs'][$slug] = $dayDj;
    }

    $state['days'][$dayKey] = $day;
}

function tr_stats_record_sample(array $input, ?string $directory = null): array
{
    $sample = tr_stats_normalize_sample($input);
    if ($sample === null) {
        return ['ok' => false, 'error' => 'invalid sample'];
    }

    $directory = tr_stats_storage_dir($directory);
    if (!tr_stats_ensure_dir($directory)) {
        return ['ok' => false, 'error' => 'stats directory is not writable: ' . $directory];
    }

    $lockHandle = @fopen($directory . '/collector.lock', 'c');
    if (!is_resource($lockHandle) || !flock($lockHandle, LOCK_EX)) {
        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }
        return ['ok' => false, 'error' => 'unable to lock stats storage'];
    }

    try {
        $state = tr_stats_load_state($directory);
        $previous = tr_stats_normalize_sample(is_array($state['last_sample'] ?? null) ? $state['last_sample'] : []);

        if ($previous !== null && $sample['ts'] <= $previous['ts']) {
            return ['ok' => false, 'error' => 'sample timestamp is not newer than the last sample'];
        }

        if ($previous !== null) {
            tr_stats_integrate_interval($state, $previous, $sample['ts']);
        }

        $sameContinuousSession = false;
        if ($previous !== null && !empty($previous['is_live']) && !empty($sample['is_live'])) {
            $gap = $sample['ts'] - $previous['ts'];
            $sameContinuousSession = $gap > 0
                && $gap <= TR_STATS_MAX_SAMPLE_GAP
                && (string) ($previous['dj_slug'] ?? '') === (string) ($sample['dj_slug'] ?? '');
        }

        if (!empty($sample['is_live'])) {
            if (!$sameContinuousSession || !is_array($state['session'] ?? null)) {
                tr_stats_start_session($state, $sample);
            }
        } else {
            $state['session'] = null;
        }

        tr_stats_update_sample_counters($state, $sample);

        $state['started_at'] = $state['started_at'] ?? $sample['ts'];
        $state['updated_at'] = $sample['ts'];
        $state['sample_count'] = (int) ($state['sample_count'] ?? 0) + 1;
        $state['last_sample'] = $sample;

        $sampleDir = $directory . '/samples';
        if (!is_dir($sampleDir) && !@mkdir($sampleDir, 0755, true)) {
            return ['ok' => false, 'error' => 'unable to create sample directory'];
        }

        $sampleFile = $sampleDir . '/' . gmdate('Y-m-d', $sample['ts']) . '.jsonl';
        $line = json_encode($sample, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($line) || file_put_contents($sampleFile, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'unable to append raw sample'];
        }

        if (!tr_stats_write_json_atomic($directory . '/state.json', $state)) {
            return ['ok' => false, 'error' => 'unable to write stats state'];
        }

        return ['ok' => true, 'sample' => $sample, 'state' => $state, 'directory' => $directory];
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function tr_stats_merge_bucket(array &$target, array $source): void
{
    foreach (['coverage_seconds', 'listener_seconds', 'listener_observed_seconds', 'live_seconds', 'autodj_seconds', 'samples', 'live_samples', 'live_sessions'] as $key) {
        $target[$key] = (int) ($target[$key] ?? 0) + (int) ($source[$key] ?? 0);
    }

    $sourcePeak = is_numeric($source['peak_listeners'] ?? null) ? (int) $source['peak_listeners'] : null;
    $targetPeak = is_numeric($target['peak_listeners'] ?? null) ? (int) $target['peak_listeners'] : null;
    if ($sourcePeak !== null && ($targetPeak === null || $sourcePeak > $targetPeak)) {
        $target['peak_listeners'] = $sourcePeak;
        $target['peak_at'] = is_numeric($source['peak_at'] ?? null) ? (int) $source['peak_at'] : null;
    }
}

function tr_stats_period(array $state, string $fromDay, string $toDay): array
{
    $bucket = tr_stats_empty_bucket();
    $bucket['djs'] = [];

    foreach (($state['days'] ?? []) as $day => $dayData) {
        if (!is_string($day) || $day < $fromDay || $day > $toDay || !is_array($dayData)) {
            continue;
        }

        tr_stats_merge_bucket($bucket, $dayData);
        foreach (($dayData['djs'] ?? []) as $slug => $djData) {
            if (!is_string($slug) || !is_array($djData)) {
                continue;
            }
            $dj = tr_stats_dj_bucket(is_array($bucket['djs'][$slug] ?? null) ? $bucket['djs'][$slug] : []);
            $source = tr_stats_dj_bucket($djData);
            $dj['name'] = $source['name'] ?? $dj['name'];
            foreach (['seconds', 'listener_seconds', 'listener_observed_seconds', 'sessions'] as $key) {
                $dj[$key] += (int) ($source[$key] ?? 0);
            }
            $dj['first_seen_at'] = $dj['first_seen_at'] ?? $source['first_seen_at'];
            $dj['last_seen_at'] = max((int) ($dj['last_seen_at'] ?? 0), (int) ($source['last_seen_at'] ?? 0)) ?: null;
            tr_stats_update_peak($dj, is_numeric($source['peak_listeners'] ?? null) ? (int) $source['peak_listeners'] : null, (int) ($source['peak_at'] ?? 0));
            $bucket['djs'][$slug] = $dj;
        }
    }

    return $bucket;
}

function tr_stats_average_listeners(array $bucket): ?float
{
    $observed = (int) ($bucket['listener_observed_seconds'] ?? 0);
    if ($observed <= 0) {
        return null;
    }

    return (float) ($bucket['listener_seconds'] ?? 0) / $observed;
}

function tr_stats_listener_hours(array $bucket): float
{
    return (float) ($bucket['listener_seconds'] ?? 0) / 3600;
}

function tr_stats_live_days(array $state): array
{
    $days = [];
    foreach (($state['days'] ?? []) as $day => $bucket) {
        if (is_string($day) && is_array($bucket) && (int) ($bucket['live_samples'] ?? 0) > 0) {
            $days[] = $day;
        }
    }
    sort($days, SORT_STRING);
    return $days;
}

function tr_stats_longest_live_day_streak(array $state): int
{
    $days = tr_stats_live_days($state);
    if ($days === []) {
        return 0;
    }

    $best = 1;
    $current = 1;
    for ($i = 1, $count = count($days); $i < $count; $i++) {
        $previous = strtotime($days[$i - 1] . ' 00:00:00 UTC');
        $today = strtotime($days[$i] . ' 00:00:00 UTC');
        if ($previous !== false && $today !== false && ($today - $previous) === 86400) {
            $current++;
            $best = max($best, $current);
        } else {
            $current = 1;
        }
    }

    return $best;
}
