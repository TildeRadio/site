<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

use TildeRadio\Site\DjAuth\Config;

/**
 * @phpstan-import-type DirectoryEntry from ScheduleApi
 * @phpstan-type Choices array{entries:list<DirectoryEntry>,available:bool,error:?string}
 */
final class DjDirectory
{
    private ?ScheduleApi $api = null;
    private float $deadline;
    /** @var array<int,Choices> */
    private array $loaded = [];

    public function __construct(private readonly Config $config)
    {
        $this->deadline = microtime(true) + 20;
    }

    /** @return Choices */
    public function choices(int $station): array
    {
        if (isset($this->loaded[$station])) {
            return $this->loaded[$station];
        }
        try {
            $api = $this->api();
            if (!$api->allows($station)) {
                return $this->loaded[$station] = ['entries' => [], 'available' => false, 'error' => 'This station is outside the private API allowlist. Manual entry remains available.'];
            }
            $settings = $this->config->scheduleApi();
            $signature = hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
            $key = $signature . ':' . $station;
            $cache = $_SESSION['admin_dj_directory'] ?? [];
            if (!is_array($cache)) {
                $cache = [];
            }
            $saved = $cache[$key] ?? null;
            if (is_array($saved) && is_int($saved['at'] ?? null) && time() >= $saved['at'] && time() - $saved['at'] < 60
                && is_array($saved['entries'] ?? null)) {
                return $this->loaded[$station] = ['entries' => $saved['entries'], 'available' => true, 'error' => null];
            }
            $remaining = (int) floor($this->deadline - microtime(true));
            if ($remaining < 1) {
                throw new Problem('DJ lookup took too long. Reload or use manual entry.', 502);
            }
            $entries = $api->streamers($station, $remaining);
            unset($cache[$key]);
            $cache[$key] = ['at' => time(), 'entries' => $entries];
            while (count($cache) > 8) {
                array_shift($cache);
            }
            $_SESSION['admin_dj_directory'] = $cache;

            return $this->loaded[$station] = ['entries' => $entries, 'available' => true, 'error' => null];
        } catch (Problem $problem) {
            return $this->loaded[$station] = ['entries' => [], 'available' => false, 'error' => $problem->getMessage()];
        } catch (\Throwable) {
            error_log('{"channel":"dj-admin","level":"error","message":"DJ directory unavailable"}');

            return $this->loaded[$station] = ['entries' => [], 'available' => false, 'error' => 'DJ lookup is unavailable. Manual entry remains available.'];
        }
    }

    /** @return DirectoryEntry */
    public function verify(int $station, int $streamer): array
    {
        // A cached option or posted ID never authorizes a new identity or assignment.
        return $this->api()->streamerIdentity($station, $streamer);
    }

    private function api(): ScheduleApi
    {
        $settings = $this->config->scheduleApi();
        if ($settings === null) {
            throw new Problem('Configure the private AzuraCast API connection to select DJs by username. Manual entry remains available.', 503);
        }

        return $this->api ??= new ScheduleApi($settings);
    }
}
