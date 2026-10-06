<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

final class BroadcastForm
{
    /** @param array<string,mixed>|null $source */
    public static function hash(?array $source): string
    {
        return hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
    }

    public static function date(mixed $value, bool $required = false): ?int
    {
        $text = Input::text($value, 'broadcast UTC date and time', 16, $required);
        if ($text === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i') !== $text || $date->format('Y') < '1970' || $date->format('Y') > '2099') {
            throw new Problem('Use a valid broadcast date between 1970 and 2099.');
        }
        return $date->getTimestamp();
    }

    public static function url(string $url): void
    {
        if ($url === '') {
            return;
        }
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new Problem('Use an HTTP or HTTPS link without embedded credentials.');
        }
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function changes(array $base, array $post): array
    {
        $changes = [];
        $show = is_array($base['show'] ?? null) ? $base['show'] : [];
        foreach (['title', 'tagline', 'description', 'episode', 'topic', 'mood', 'prompt', 'note', 'link'] as $key) {
            $value = Input::text($post['show_' . $key] ?? '', 'broadcast ' . $key, in_array($key, ['description', 'note'], true) ? 8192 : 2048);
            if ($key === 'link') {
                self::url($value);
            }
            if ($value !== ($show[$key] ?? '')) {
                $changes['show'][$key] = $value;
            }
        }
        $format = Input::text($post['format_title'] ?? '', 'format title', 255);
        if ($format !== ($show['format']['title'] ?? '')) {
            $changes['show']['format']['title'] = $format;
        }
        foreach (['dj' => 'DJ display name', 'recording_url' => 'recording URL'] as $key => $label) {
            $value = Input::text($post[$key] ?? '', $label, 2048, $key === 'dj');
            if ($key === 'recording_url') {
                self::url($value);
            }
            if ($value !== ($base[$key] ?? '')) {
                $changes[$key] = $value;
            }
        }
        foreach (['started_at', 'ended_at'] as $key) {
            $value = self::date($post[$key] ?? '', $key === 'started_at');
            $existing = isset($base[$key]) ? (int) $base[$key] : null;
            // Minute-resolution controls must preserve captured seconds when unchanged.
            if (($value === null) !== ($existing === null) || ($value !== null && $existing !== null && intdiv($value, 60) !== intdiv($existing, 60))) {
                $changes[$key] = $value;
            }
        }
        $start = $changes['started_at'] ?? $base['started_at'] ?? null;
        $end = array_key_exists('ended_at', $changes) ? $changes['ended_at'] : ($base['ended_at'] ?? null);
        if (!is_int($start) || ($end !== null && (!is_int($end) || $end < $start))) {
            throw new Problem('The broadcast end must be at or after its start.');
        }
        $status = Input::text($post['status'] ?? '', 'broadcast status', 20, true);
        if (!in_array($status, ['planned', 'live', 'recorded'], true) || ($status === 'live' && ($start > time() || $end !== null))) {
            throw new Problem('Choose a valid status. A live listing must have started and have no end time.');
        }
        $previous = $base['status'] ?? (!empty($base['is_live']) ? 'live' : 'recorded');
        if ($status !== $previous || $base === []) {
            $changes['status'] = $status;
            $changes['is_live'] = $status === 'live';
        }
        foreach (['peak_listeners', 'max_couch', 'props', 'questions', 'requests', 'reactions', 'tildes'] as $key) {
            $value = Input::integer($post[$key] ?? '0', $key, true);
            if ($value !== ($base[$key] ?? 0)) {
                $changes[$key] = $value;
            }
        }
        if (isset($post['edit_tracks'])) {
            $raw = Input::text($post['tracks_json'] ?? '', 'track list JSON', 65536, true);
            try {
                $tracks = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new Problem('Enter valid JSON for the track list.');
            }
            if (!is_array($tracks) || !array_is_list($tracks) || count($tracks) > 500) {
                throw new Problem('Use a JSON array with at most 500 tracks.');
            }
            foreach ($tracks as &$track) {
                if (!is_array($track) || ($track !== [] && array_is_list($track))
                    || array_diff(array_keys($track), ['artist', 'title', 'text', 'art', 'played_at', 'duration']) !== []) {
                    throw new Problem('Track entries may contain artist, title, text, art, played_at and duration only.');
                }
                foreach (['artist', 'title', 'text', 'art'] as $key) {
                    if (isset($track[$key])) {
                        $track[$key] = Input::text($track[$key], 'track ' . $key, 2048);
                    }
                }
                foreach (['played_at', 'duration'] as $key) {
                    if (isset($track[$key]) && (!is_int($track[$key]) || $track[$key] < 0 || $track[$key] > 4102444800)) {
                        throw new Problem('Track timestamps and durations must be nonnegative integers.');
                    }
                }
                self::url($track['art'] ?? '');
                if (empty($track['text'])) {
                    $track['text'] = trim(($track['artist'] ?? '') . ' - ' . ($track['title'] ?? ''), ' -');
                }
                if ($track['text'] === '') {
                    throw new Problem('Each track needs text or an artist/title.');
                }
            }
            unset($track);
            $changes['tracks'] = $tracks;
            $changes['track_count'] = count($tracks);
        }
        return $changes;
    }
}
