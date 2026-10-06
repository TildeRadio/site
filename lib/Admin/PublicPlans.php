<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

use PDO;
use Throwable;

final class PublicPlans
{
    /** @return list<array<string,mixed>> */
    public static function upcoming(): array
    {
        $database = PublicProfiles::databasePath();
        if (!is_file($database)) {
            return [];
        }
        try {
            $path = realpath($database);
            $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
            if ($path === false || $path === $root || str_starts_with($path, $root . '/')) {
                return [];
            }
            $uri = str_replace('%2F', '/', rawurlencode($path));
            $db = new PDO('sqlite:file:' . $uri . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000');
            if ($db->query("SELECT name FROM sqlite_master WHERE name='show_plans'")->fetchColumn() === false) {
                return [];
            }
            $rows = $db->query('SELECT s.json,s.slug,s.station_id FROM show_plans s
                JOIN accounts a ON a.station_id=s.owner_station AND a.streamer_id=s.owner_streamer
                JOIN memberships m ON m.account_station_id=a.station_id AND m.account_streamer_id=a.streamer_id AND m.station_id=s.station_id
                JOIN stations t ON t.id=s.station_id JOIN profiles p ON p.slug=s.slug
                WHERE s.enabled=1 AND s.deleted=0 AND a.enabled=1 AND a.deleted=0 AND a.profile_slug=s.slug
                    AND t.enabled=1 AND t.deleted=0 AND p.published=1 AND p.deleted=0 ORDER BY s.id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
            $plans = [];
            foreach ($rows as $row) {
                $data = json_decode((string) $row['json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !($data['public'] ?? false) || !is_int($data['starts_at'] ?? null) || !is_int($data['ends_at'] ?? null)
                    || $data['ends_at'] <= time() || $data['starts_at'] > time() + 31 * 86400) {
                    continue;
                }
                $plans[] = ['slug' => (string) $row['slug'], 'station_id' => (int) $row['station_id'],
                    'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
                    'title' => (string) ($data['show']['episode'] ?? ''), 'topic' => (string) ($data['show']['topic'] ?? '')];
            }
            usort($plans, static fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);
            return $plans;
        } catch (Throwable) {
            error_log('{"channel":"dj-planning","level":"error","message":"Published preparations unavailable"}');
            return [];
        }
    }
}
