<?php

declare(strict_types=1);

namespace TildeRadio\Site\DjAuth;

use PDO;
use RuntimeException;
use Throwable;

final class RateLimiter
{
    private PDO $db;
    private string $key;

    public function __construct(string $directory)
    {
        $raw = file_get_contents($directory . '/rate-key');
        if (!is_string($raw) || !preg_match('/\A[0-9a-f]{64}\s*\z/D', $raw)) {
            throw new RuntimeException('Login rate key unavailable.');
        }
        $this->key = trim($raw);
        $this->db = new PDO('sqlite:' . $directory . '/login.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=3000; PRAGMA journal_mode=WAL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS throttle (key TEXT PRIMARY KEY, count INTEGER NOT NULL, expires INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS throttle_expiry ON throttle(expires)');
    }

    /** @param list<array{id:string,limit:int,seconds:int}> $buckets */
    public function allow(array $buckets, int $now): bool
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $delete = $this->db->prepare('DELETE FROM throttle WHERE expires <= ?');
            $delete->execute([$now]);
            $allowed = true;
            foreach ($buckets as $bucket) {
                $window = intdiv($now, $bucket['seconds']);
                $key = hash_hmac('sha256', $bucket['id'], $this->key) . ':' . $bucket['seconds'] . ':' . $window;
                $statement = $this->db->prepare('INSERT INTO throttle(key,count,expires) VALUES(?,1,?)
                    ON CONFLICT(key) DO UPDATE SET count=count+1');
                $statement->execute([$key, ($window + 1) * $bucket['seconds']]);
                $statement = $this->db->prepare('SELECT count FROM throttle WHERE key=?');
                $statement->execute([$key]);
                $allowed = ((int) $statement->fetchColumn() <= $bucket['limit']) && $allowed;
            }
            $this->db->exec('COMMIT');

            return $allowed;
        } catch (Throwable $exception) {
            $this->db->exec('ROLLBACK');
            throw $exception;
        }
    }
}
