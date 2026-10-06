<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

use Throwable;

// Reading a small public snapshot never opens the control socket or delays the player.
final class PublicCarrier
{
    /** @param array<string,mixed> $now
     * @return array<string,mixed>
     */
    public static function now(array $now): array
    {
        try {
            $config = getenv('TILDERADIO_DJ_SITE_CONFIG') ?: '/etc/tilderadio/dj-auth-site.json';
            if (!is_file($config) || !is_readable($config) || filesize($config) > 65536) {
                return $now;
            }
            $data = json_decode((string) file_get_contents($config), true, 16, JSON_THROW_ON_ERROR);
            if (($data['carrier']['enabled'] ?? false) !== true) {
                return $now;
            }
            $path = $data['carrier']['public_state_file'] ?? '/var/lib/tilderadio-bot/carrier-live.json';
            $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
            $resolved = is_string($path) ? realpath($path) : false;
            if ($resolved === false || !str_starts_with($path, '/') || is_link($path) || !is_file($path) || !is_readable($path)
                || filesize($path) > 1048576 || $resolved === $root || str_starts_with($resolved, $root . '/')) {
                return $now;
            }
            $activity = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            $track = $activity['current_track'] ?? null;
            if (!is_array($activity) || !is_int($activity['generated_at'] ?? null) || time() - $activity['generated_at'] > 60
                || $activity['generated_at'] > time() + 5 || empty($now['is_live']) || empty($activity['is_live'])
                || empty($now['broadcast_start']) || $now['broadcast_start'] !== ($activity['broadcast_start'] ?? null)
                || ($now['station']['id'] ?? null) !== ($activity['station_id'] ?? null) || ($activity['tracking'] ?? '') !== 'planned'
                || !is_array($track) || !is_string($track['artist'] ?? null) || !is_string($track['title'] ?? null) || !is_int($track['played_at'] ?? null)) {
                return $now;
            }
            $now['captured_now_playing'] = $now['now_playing'];
            $now['now_playing'] = ['artist' => $track['artist'], 'title' => $track['title'], 'text' => $track['artist'] . ' - ' . $track['title'],
                'art' => null, 'played_at' => $track['played_at'], 'duration' => null, 'elapsed' => max(0, time() - $track['played_at']), 'remaining' => null];
            $now['playlist_position'] = (int) ($activity['position'] ?? 0);
            $now['broadcast_id'] = (int) ($activity['session_id'] ?? 0);
            return $now;
        } catch (Throwable) {
            return $now;
        }
    }
}
