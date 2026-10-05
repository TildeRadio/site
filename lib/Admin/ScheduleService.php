<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

/**
 * @phpstan-import-type Actor from Store
 * @phpstan-type View array{source_station:int,source_streamer:int,station_id:int,streamer_id:int,account_version:int,username:string,timezone:string,items:list<array<string,mixed>>,hash:string,issued:int}
 */
final readonly class ScheduleService
{
    public function __construct(private Store $store, private ScheduleTransport $api, private string $directory)
    {
    }

    /**
     * @param Actor $actor
     * @return View
     */
    public function view(array $actor, int $sourceStation, int $sourceStreamer, int $station): array
    {
        $target = $this->store->scheduleTarget($actor, $sourceStation, $sourceStreamer, $station);
        $timezone = $this->api->timezone($target['station_id']);
        $streamer = $this->api->streamer($target['station_id'], $target['streamer_id']);

        return [
            'source_station' => $sourceStation, 'source_streamer' => $sourceStreamer,
            'station_id' => $target['station_id'], 'streamer_id' => $target['streamer_id'],
            'account_version' => $this->store->requireAccount($sourceStation, $sourceStreamer)['version'],
            'username' => $streamer['username'], 'timezone' => $timezone, 'items' => $streamer['schedule_items'],
            'hash' => self::hash($streamer['schedule_items']), 'issued' => time(),
        ];
    }

    /**
     * @param Actor $actor
     * @param View $view
     * @param array<string,mixed> $post
     */
    public function save(array $actor, array $view, #[\SensitiveParameter] array $post): void
    {
        if (time() - $view['issued'] > 900 || time() < $view['issued']) {
            throw new Problem('This schedule form has expired. Reload its current schedule.', 409);
        }
        $target = $this->store->scheduleTarget($actor, $view['source_station'], $view['source_streamer'], $view['station_id']);
        if ($target['streamer_id'] !== $view['streamer_id']
            || $this->store->requireAccount($view['source_station'], $view['source_streamer'])['version'] !== $view['account_version']) {
            throw new Problem('This DJ’s station assignment changed. Reload the schedule.', 409);
        }
        $lock = fopen($this->directory . '/schedule-' . $view['station_id'] . '-' . $view['streamer_id'] . '.lock', 'c');
        if ($lock === false) {
            throw new Problem('Schedule editing is temporarily unavailable.', 503);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new Problem('Another request is updating this DJ’s schedule. Reload it shortly.', 409);
            }
            $current = $this->api->streamer($target['station_id'], $target['streamer_id']);
            if (!hash_equals($view['hash'], self::hash($current['schedule_items'])) || $current['username'] !== $view['username']
                || $this->api->timezone($target['station_id']) !== $view['timezone']) {
                throw new Problem('The AzuraCast schedule or station timezone changed. Reload before saving.', 409);
            }
            $action = Input::text($post['action'] ?? null, 'schedule action', 20, true);
            $id = $action === 'add' ? 0 : Input::integer($post['item_id'] ?? null, 'schedule entry ID');
            $items = $current['schedule_items'];
            $index = null;
            foreach ($items as $key => $item) {
                if (($item['id'] ?? null) === $id) {
                    $index = $key;
                    break;
                }
            }
            if ($action !== 'add' && $index === null) {
                throw new Problem('This schedule entry no longer exists. Reload the schedule.', 409);
            }
            if ($action === 'add') {
                $items[] = ScheduleRules::changes($post) + ['loop_once' => false, 'prevent_requests' => false, 'reset_queue_at_start' => false, 'reset_queue_recursive' => false];
            } elseif ($action === 'edit') {
                $items[$index] = array_replace($items[$index], ScheduleRules::changes($post));
            } elseif ($action === 'delete') {
                if (($post['confirm'] ?? '') !== 'DELETE') {
                    throw new Problem('Type DELETE to remove this schedule entry.');
                }
                unset($items[$index]);
                $items = array_values($items);
            } else {
                throw new Problem('Choose a valid schedule action.');
            }
            // Recheck current website authorization immediately before the external write.
            $lastTarget = $this->store->scheduleTarget($actor, $view['source_station'], $view['source_streamer'], $view['station_id']);
            if ($lastTarget !== $target || $this->store->requireAccount($view['source_station'], $view['source_streamer'])['version'] !== $view['account_version']) {
                throw new Problem('This station assignment changed. Reload the schedule.', 409);
            }
            $this->api->save($target['station_id'], $target['streamer_id'], $items);
            $this->store->recordScheduleChange($actor, $target['station_id'], $target['streamer_id']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param list<array<string,mixed>> $items */
    public static function hash(array $items): string
    {
        foreach ($items as &$row) {
            ksort($row);
        }
        unset($row);

        return hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
    }
}
