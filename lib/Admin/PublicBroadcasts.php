<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

use PDO;
use Throwable;

// Native PHP keeps the public archive usable without Composer or DJ login.
final class PublicBroadcasts
{
    public static function slug(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))) ?? '', '-');
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public static function combine(array $base, array $patch): array
    {
        $result = array_replace($base, $patch);
        if (isset($patch['show']) && is_array($patch['show'])) {
            $show = is_array($base['show'] ?? null) ? $base['show'] : [];
            $result['show'] = array_replace($show, $patch['show']);
            if (isset($patch['show']['format']) && is_array($patch['show']['format'])) {
                $result['show']['format'] = array_replace(is_array($show['format'] ?? null) ? $show['format'] : [], $patch['show']['format']);
            }
        }
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $episodes
     * @param list<array<string,mixed>> $edits
     * @return list<array<string,mixed>>
     */
    public static function merge(array $episodes, array $edits, bool $includeDeleted = false): array
    {
        $indexed = [];
        foreach ($episodes as $episode) {
            $indexed[(int) $episode['id']] = $episode;
        }
        foreach ($edits as $edit) {
            $id = (int) $edit['id'];
            if ($edit['deleted'] && !$includeDeleted) {
                unset($indexed[$id]);
                continue;
            }
            $base = $indexed[$id] ?? (is_array($edit['source'] ?? null) ? $edit['source'] : []);
            $patch = is_array($edit['patch'] ?? null) ? $edit['patch'] : [];
            $episode = self::combine($base, $patch);
            $episode['id'] = $id;
            $episode['dj_slug'] = (string) $edit['owner_slug'];
            if (array_key_exists('started_at', $patch) || array_key_exists('ended_at', $patch)) {
                $episode['duration'] = isset($episode['ended_at']) ? max(0, (int) $episode['ended_at'] - (int) $episode['started_at']) : null;
            }
            if ($includeDeleted) {
                $episode['_deleted'] = (bool) $edit['deleted'];
            }
            $indexed[$id] = $episode;
        }
        $result = array_values($indexed);
        usort($result, static fn (array $a, array $b): int => ((int) $b['started_at'] <=> (int) $a['started_at']) ?: ((int) $b['id'] <=> (int) $a['id']));
        return $result;
    }

    /** @param list<array<string,mixed>> $episodes
     * @return list<array<string,mixed>>
     */
    public static function apply(array $episodes): array
    {
        require_once __DIR__ . '/PublicProfiles.php';
        $path = PublicProfiles::databasePath();
        if (!is_file($path)) {
            return $episodes;
        }
        try {
            $resolved = realpath($path);
            $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
            if ($resolved === false || !str_starts_with($path, '/') || $resolved === $root || str_starts_with($resolved, rtrim($root, '/') . '/')) {
                throw new \RuntimeException('Broadcast database must be private.');
            }
            $uri = str_replace('%2F', '/', rawurlencode($resolved));
            $db = new PDO('sqlite:file:' . $uri . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000');
            if ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='broadcast_edits'")->fetchColumn() === false) {
                return $episodes;
            }
            $edits = [];
            foreach ($db->query('SELECT * FROM broadcast_edits')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $edits[] = [
                    'id' => (int) $row['id'], 'owner_slug' => (string) $row['owner_slug'], 'deleted' => (bool) $row['deleted'],
                    'patch' => json_decode((string) $row['json'], true, 32, JSON_THROW_ON_ERROR),
                    'source' => $row['source_json'] === null ? null : json_decode((string) $row['source_json'], true, 64, JSON_THROW_ON_ERROR),
                ];
            }
            return self::merge($episodes, $edits);
        } catch (Throwable) {
            // Fail closed so a temporary database error cannot republish a deleted listing.
            error_log('{"channel":"dj-broadcast","level":"error","message":"Broadcast corrections unavailable"}');
            return [];
        }
    }
}
