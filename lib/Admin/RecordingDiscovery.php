<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

use TildeRadio\Site\DjAuth\Config;

final readonly class RecordingDiscovery
{
    public function __construct(private Config $config, private Store $store)
    {
    }

    public function run(): int
    {
        $settings = $this->config->carrier()['recordings'] ?? null;
        if (!is_array($settings) || ($settings['enabled'] ?? false) !== true) {
            throw new Problem('Automatic recording discovery is not enabled.', 503);
        }
        $directory = $settings['directory'] ?? null;
        $path = is_string($directory) ? realpath($directory) : false;
        $root = realpath(dirname(__DIR__, 2));
        if (!is_string($directory) || !str_starts_with($directory, '/') || is_link($directory) || $path === false
            || !is_dir($path) || !is_writable($path) || (fileperms($path) & 0077) !== 0
            || $path === $root || str_starts_with($path, $root . '/')) {
            throw new Problem('Recording storage must be a private writable directory outside the website.', 503);
        }
        $actor = Input::text($settings['import_actor'] ?? '', 'recording import administrator', 80, true);
        if (!preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/D', $actor, $match)) {
            throw new Problem('Configure a stable administrator identity for recording discovery.', 503);
        }
        $identity = ['station_id' => (int) $match[1], 'streamer_id' => (int) $match[2]];
        if (!$this->store->isAdministrator($identity)) {
            throw new Problem('Recording import actor must be a current website administrator.', 403);
        }
        $this->store->requireEditor($identity);
        $lock = fopen($this->config->stateDir() . '/recording-discovery.lock', 'c');
        if ($lock === false) {
            throw new Problem('Recording discovery lock unavailable.', 503);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new Problem('Recording discovery is already running.', 409);
            }
            $health = (new CarrierClient($this->config))->call(['op' => 'health']);
            $sessions = $health['completed_broadcasts'] ?? [];
            $station = Input::integer($this->config->carrier()['station_id'] ?? 1, 'Carrier station');
            $api = new ScheduleApi($this->config->scheduleApi() ?? throw new Problem('Schedule API required.', 503));
            $max = Input::integer($settings['max_bytes'] ?? 1073741824, 'maximum recording size');
            $count = 0;
            $downloads = 0;
            foreach ($api->broadcasts($station, 1, 50) as $broadcast) {
                if ($broadcast['ends_at'] === null || $broadcast['recording_bytes'] === null || $broadcast['recording_bytes'] < 1 || $broadcast['recording_bytes'] > $max) {
                    continue;
                }
                $matches = array_values(array_filter($sessions, static fn (array $session): bool => $session['station_id'] === $station
                    && $session['streamer_id'] === $broadcast['streamer_id'] && $session['broadcast_start'] === $broadcast['starts_at']));
                if (count($matches) !== 1) {
                    continue;
                }
                $session = $matches[0];
                $token = hash('sha256', $station . ':' . $broadcast['id'] . ':' . $session['id']);
                $file = $path . '/' . $token . '.audio';
                if (is_link($file)) {
                    throw new Problem('Unsafe recording staging path.', 503);
                }
                if (!is_file($file)) {
                    // Keep a timer pass bounded; later passes continue with remaining files.
                    if ($downloads >= 3) {
                        continue;
                    }
                    ++$downloads;
                    $temporary = $file . '.' . bin2hex(random_bytes(8));
                    $bytes = $api->downloadRecording($station, $broadcast['streamer_id'], $broadcast['id'], $temporary, $max);
                    if ($bytes !== $broadcast['recording_bytes']) {
                        unlink($temporary);
                        throw new Problem('Recording size changed during download. Retry discovery later.', 409);
                    }
                    chmod($temporary, 0600);
                    rename($temporary, $file);
                }
                if (filesize($file) !== $broadcast['recording_bytes']) {
                    throw new Problem('A staged recording has an unexpected size. Review the private file before retrying.', 409);
                }
                $this->store->importRecordings($identity, [['broadcast_id' => $session['id'], 'url' => $this->config->origin() . $this->config->publicPath('recordings/?id=' . $token), 'bytes' => filesize($file)]]);
                ++$count;
            }
            return $count;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
