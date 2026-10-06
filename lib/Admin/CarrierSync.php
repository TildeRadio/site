<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

use TildeRadio\Site\DjAuth\Config;
use Throwable;

final readonly class CarrierSync
{
    public function __construct(private Config $config, private Store $store)
    {
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        if ($this->config->carrier() === null) {
            throw new Problem('Carrier integration is not enabled.', 503);
        }
        $lock = fopen($this->config->stateDir() . '/carrier-sync.lock', 'c');
        if ($lock === false) {
            throw new Problem('Carrier synchronization lock unavailable.', 503);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new Problem('Another synchronization is running. Reload shortly.', 409);
            }
            $records = $this->store->carrierRecords();
            foreach ($this->store->audit() as $event) {
                if ($event['action'] === 'schedule.save') {
                    $records[] = ['kind' => 'event', 'data' => $event];
                }
            }
            $station = $this->config->carrier()['station_id'] ?? 1;
            $settings = $this->config->scheduleApi();
            if (!is_int($station) || $settings === null) {
                throw new Problem('Configure the private schedule API and Carrier station ID for verified live controls.', 503);
            }
            // The public live name alone is insufficient: pair its broadcast start with a private upstream ID.
            $broadcasts = (new ScheduleApi($settings))->broadcasts($station, 1, 1);
            $latest = $broadcasts[0] ?? null;
            if ($latest !== null && $latest['ends_at'] === null) {
                $records[] = ['kind' => 'live', 'data' => ['id' => $station, 'streamer_id' => $latest['streamer_id'], 'broadcast_start' => $latest['starts_at'], 'names' => $latest['names']]];
            }
            $client = new CarrierClient($this->config);
            $generation = bin2hex(random_bytes(16));
            $client->call(['op' => 'sync_begin', 'generation' => $generation]);
            $page = [];
            $size = 0;
            $count = 0;
            foreach ($records as $record) {
                $bytes = strlen(json_encode($record, JSON_THROW_ON_ERROR));
                if ($bytes > 800000) {
                    throw new Problem('A website correction is too large to synchronize.', 503);
                }
                if ($page !== [] && (count($page) >= 50 || $size + $bytes > 800000)) {
                    $client->call(['op' => 'sync_page', 'generation' => $generation, 'records' => $page]);
                    $page = [];
                    $size = 0;
                }
                $page[] = $record;
                $size += $bytes;
                ++$count;
            }
            if ($page !== []) {
                $client->call(['op' => 'sync_page', 'generation' => $generation, 'records' => $page]);
            }
            $result = $client->call(['op' => 'sync_commit', 'generation' => $generation, 'count' => $count]);
            $health = $client->call(['op' => 'health']);
            $this->store->recordPlanUses($health['plan_uses'] ?? []);
            $this->status(['ok' => true, 'at' => time(), 'records' => $count, 'generation' => $generation]);
            return $result;
        } catch (Throwable $exception) {
            $this->status(['ok' => false, 'at' => time(), 'message' => 'Synchronization or feedback failed. Check Carrier health before retrying; incomplete snapshots are never published.']);
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,mixed> $status */
    private function status(array $status): void
    {
        $path = $this->config->stateDir() . '/carrier-sync-status.json';
        $tmp = $path . '.' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, json_encode($status, JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
            chmod($tmp, 0600);
            rename($tmp, $path);
        }
    }
}
