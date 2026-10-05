<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

use PDO;
use Psr\Log\LoggerInterface;
use TildeRadio\Site\DjAuth\Config;
use Throwable;

/**
 * @phpstan-type Actor array{station_id:int,streamer_id:int}
 * @phpstan-type Identity array{station_id:int,streamer_id:int,username:string,display_name:string,verified_at:int}
 * @phpstan-type Account array{station_id:int,streamer_id:int,username:string,display_name:string,label:string,profile_slug:?string,enabled:bool,role:string,deleted:bool,version:int,last_seen:?int,protected:bool,assignments:array<int,int>}
 * @phpstan-type Station array{id:int,name:string,timezone:string,enabled:bool,deleted:bool,version:int}
 * @phpstan-type Profile array{slug:string,data:array<string,mixed>,published:bool,deleted:bool,version:int,updated_at:int}
 */
final class Store
{
    private PDO $db;

    public function __construct(private readonly Config $config, private readonly LoggerInterface $logger, string $root)
    {
        umask(0077);
        $path = $config->stateDir() . '/admin.sqlite';
        if (is_link($path)) {
            throw new \RuntimeException('Private administrator database unavailable.');
        }
        $this->db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL');
        $this->db->exec("CREATE TABLE IF NOT EXISTS admin_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS stations (
                id INTEGER PRIMARY KEY, name TEXT NOT NULL, timezone TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1, deleted INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1
            );
            CREATE TABLE IF NOT EXISTS profiles (
                slug TEXT PRIMARY KEY, json TEXT NOT NULL, published INTEGER NOT NULL DEFAULT 1,
                deleted INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS accounts (
                station_id INTEGER NOT NULL, streamer_id INTEGER NOT NULL, username TEXT NOT NULL, display_name TEXT NOT NULL,
                label TEXT NOT NULL DEFAULT '', profile_slug TEXT, enabled INTEGER NOT NULL DEFAULT 1,
                role TEXT NOT NULL DEFAULT 'dj' CHECK(role IN ('dj','admin')), deleted INTEGER NOT NULL DEFAULT 0,
                version INTEGER NOT NULL DEFAULT 1, last_seen INTEGER, PRIMARY KEY(station_id,streamer_id)
            );
            CREATE TABLE IF NOT EXISTS memberships (
                account_station_id INTEGER NOT NULL, account_streamer_id INTEGER NOT NULL,
                station_id INTEGER NOT NULL REFERENCES stations(id), streamer_id INTEGER NOT NULL,
                PRIMARY KEY(account_station_id,account_streamer_id,station_id),
                FOREIGN KEY(account_station_id,account_streamer_id) REFERENCES accounts(station_id,streamer_id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS admin_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT, actor_station_id INTEGER NOT NULL, actor_streamer_id INTEGER NOT NULL,
                action TEXT NOT NULL, target TEXT NOT NULL, created_at INTEGER NOT NULL
            )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS broadcast_edits (
            id INTEGER PRIMARY KEY, owner_slug TEXT NOT NULL, json TEXT NOT NULL,
            source_json TEXT, manual INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0,
            version INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL
        )");
        $this->bootstrap($root);
    }

    private function bootstrap(string $root): void
    {
        if ($this->one("SELECT value FROM admin_meta WHERE key='schema'") !== null) {
            return;
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($this->one("SELECT value FROM admin_meta WHERE key='schema'") === null) {
                $this->execute("INSERT INTO stations(id,name,timezone) VALUES(1,'TildeRadio','UTC')");
                foreach ($this->config->seedAccounts() as $seed) {
                    $station = $seed['station_id'];
                    $streamer = $seed['streamer_id'];
                    $this->execute('INSERT OR IGNORE INTO stations(id,name,timezone) VALUES(?,?,?)', [$station, 'Station ' . $station, 'UTC']);
                    // Root-configured administrators are evaluated live, not copied into an irrevocable database role.
                    $this->execute('INSERT INTO accounts(station_id,streamer_id,username,display_name,profile_slug) VALUES(?,?,?,?,?)',
                        [$station, $streamer, $seed['slug'] ?? 'Streamer ' . $streamer, $seed['slug'] ?? 'Streamer ' . $streamer, $seed['slug']]);
                    $this->execute('INSERT INTO memberships VALUES(?,?,?,?)', [$station, $streamer, $station, $streamer]);
                }
                $this->importFiles($root);
                $this->execute("INSERT INTO admin_meta(key,value) VALUES('schema','1')");
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $exception) {
            $this->db->exec('ROLLBACK');
            throw $exception;
        }
    }

    /**
     * @param Identity $identity
     * @return Account
     */
    public function observe(array $identity): array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $station = $identity['station_id'];
            $streamer = $identity['streamer_id'];
            $exists = $this->one('SELECT station_id FROM accounts WHERE station_id=? AND streamer_id=?', [$station, $streamer]);
            if ($exists === null) {
                $this->execute('INSERT OR IGNORE INTO stations(id,name,timezone) VALUES(?,?,?)', [$station, 'Station ' . $station, 'UTC']);
                $this->execute('INSERT INTO accounts(station_id,streamer_id,username,display_name,profile_slug) VALUES(?,?,?,?,?)',
                    [$station, $streamer, $identity['username'], $identity['display_name'], $this->config->slug($station, $streamer)]);
                $this->execute('INSERT INTO memberships VALUES(?,?,?,?)', [$station, $streamer, $station, $streamer]);
            }
            $this->execute('UPDATE accounts SET username=?,display_name=?,last_seen=? WHERE station_id=? AND streamer_id=?',
                [$identity['username'], $identity['display_name'], time(), $station, $streamer]);
            if ($this->config->isAdministrator($station, $streamer)) {
                $this->execute('UPDATE accounts SET enabled=1,deleted=0 WHERE station_id=? AND streamer_id=?', [$station, $streamer]);
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $exception) {
            $this->db->exec('ROLLBACK');
            throw $exception;
        }

        return $this->requireAccount($identity['station_id'], $identity['streamer_id']);
    }

    /** @param Actor $actor */
    public function isAdministrator(array $actor): bool
    {
        if ($this->config->isAdministrator($actor['station_id'], $actor['streamer_id'])) {
            return true;
        }
        $account = $this->account($actor['station_id'], $actor['streamer_id']);

        return $account !== null && $account['enabled'] && !$account['deleted'] && $account['role'] === 'admin';
    }

    /** @return Account|null */
    public function account(int $station, int $streamer): ?array
    {
        $row = $this->one('SELECT * FROM accounts WHERE station_id=? AND streamer_id=?', [$station, $streamer]);
        if ($row === null) {
            return null;
        }
        $assignments = [];
        foreach ($this->all('SELECT station_id,streamer_id FROM memberships WHERE account_station_id=? AND account_streamer_id=? ORDER BY station_id', [$station, $streamer]) as $membership) {
            $assignments[(int) $membership['station_id']] = (int) $membership['streamer_id'];
        }
        $protected = $this->config->isAdministrator($station, $streamer);

        return [
            'station_id' => $station, 'streamer_id' => $streamer, 'username' => (string) $row['username'],
            'display_name' => (string) $row['display_name'], 'label' => (string) $row['label'],
            'profile_slug' => $row['profile_slug'] === null ? null : (string) $row['profile_slug'],
            'enabled' => $protected || (bool) $row['enabled'], 'role' => $protected ? 'admin' : (string) $row['role'],
            'deleted' => !$protected && (bool) $row['deleted'], 'version' => (int) $row['version'],
            'last_seen' => $row['last_seen'] === null ? null : (int) $row['last_seen'], 'protected' => $protected, 'assignments' => $assignments,
        ];
    }

    /** @return Account */
    public function requireAccount(int $station, int $streamer): array
    {
        return $this->account($station, $streamer) ?? throw new Problem('This website DJ account was not found.', 404);
    }

    /** @return list<Account> */
    public function accounts(bool $deleted = false): array
    {
        $accounts = [];
        foreach ($this->all('SELECT station_id,streamer_id FROM accounts WHERE deleted=? ORDER BY username COLLATE NOCASE', [(int) $deleted]) as $row) {
            $accounts[] = $this->requireAccount((int) $row['station_id'], (int) $row['streamer_id']);
        }

        return $accounts;
    }

    /**
     * @param Actor $actor
     * @param Identity $verified
     * @param array<int,int> $assignments
     */
    public function saveAccount(array $actor, array $verified, string $label, ?string $slug, bool $enabled, string $role, array $assignments, int $version): void
    {
        Input::text($label, 'website name', 255);
        if (!in_array($role, ['dj', 'admin'], true)) {
            throw new Problem('Choose a valid website role.');
        }
        if ($slug !== null) {
            Input::slug($slug);
        }
        $station = $verified['station_id'];
        $streamer = $verified['streamer_id'];
        $this->mutate($actor, 'account.save', $station . ':' . $streamer, function () use ($actor, $verified, $label, $slug, $enabled, $role, $assignments, $version, $station, $streamer): void {
            $current = $this->account($station, $streamer);
            $this->version($current['version'] ?? 0, $version);
            $protected = $this->config->isAdministrator($station, $streamer);
            $self = $actor['station_id'] === $station && $actor['streamer_id'] === $streamer;
            if (($protected || $self) && (!$enabled || $role !== 'admin')) {
                throw new Problem('You cannot remove your own administrator access or change a protected administrator.', 409);
            }
            if ($slug !== null) {
                $profile = $this->profile($slug);
                if (($profile === null || $profile['deleted']) && ($current['profile_slug'] ?? null) !== $slug) {
                    throw new Problem('Create or restore the selected profile before linking it.');
                }
            }
            foreach ($assignments as $target => $targetStreamer) {
                Input::integer($target, 'assigned station ID');
                Input::integer($targetStreamer, 'assigned streamer ID');
                $record = $this->station($target);
                if ($record === null || $record['deleted'] || (!$record['enabled'] && ($current['assignments'][$target] ?? null) !== $targetStreamer)) {
                    throw new Problem('Choose an enabled website station.');
                }
            }
            $storedRole = $protected ? 'dj' : $role;
            $this->execute('INSERT INTO accounts(station_id,streamer_id,username,display_name,label,profile_slug,enabled,role,version)
                VALUES(?,?,?,?,?,?,?,?,1) ON CONFLICT(station_id,streamer_id) DO UPDATE SET username=excluded.username,
                display_name=excluded.display_name,label=excluded.label,profile_slug=excluded.profile_slug,enabled=excluded.enabled,
                role=excluded.role,deleted=0,version=accounts.version+1',
                [$station, $streamer, $verified['username'], $verified['display_name'], $label, $slug, (int) $enabled, $storedRole]);
            $this->execute('DELETE FROM memberships WHERE account_station_id=? AND account_streamer_id=?', [$station, $streamer]);
            foreach ($assignments as $target => $targetStreamer) {
                $this->execute('INSERT INTO memberships VALUES(?,?,?,?)', [$station, $streamer, $target, $targetStreamer]);
            }
        });
    }

    /** @param Actor $actor */
    public function deleteAccount(array $actor, int $station, int $streamer, int $version, string $confirmation): void
    {
        $this->mutate($actor, 'account.delete', $station . ':' . $streamer, function () use ($actor, $station, $streamer, $version, $confirmation): void {
            $account = $this->requireAccount($station, $streamer);
            $this->version($account['version'], $version);
            if ($account['protected'] || ($actor['station_id'] === $station && $actor['streamer_id'] === $streamer)) {
                throw new Problem('You cannot delete your own account or a protected administrator.', 409);
            }
            if ($confirmation !== $account['username']) {
                throw new Problem('Type the exact DJ username to confirm website account deletion.');
            }
            $this->execute('UPDATE accounts SET enabled=0,deleted=1,role=\'dj\',version=version+1 WHERE station_id=? AND streamer_id=?', [$station, $streamer]);
            $this->execute('DELETE FROM memberships WHERE account_station_id=? AND account_streamer_id=?', [$station, $streamer]);
        });
    }

    /** @return Station|null */
    public function station(int $id): ?array
    {
        $row = $this->one('SELECT * FROM stations WHERE id=?', [$id]);
        if ($row === null) {
            return null;
        }

        return ['id' => $id, 'name' => (string) $row['name'], 'timezone' => (string) $row['timezone'],
            'enabled' => (bool) $row['enabled'], 'deleted' => (bool) $row['deleted'], 'version' => (int) $row['version']];
    }

    /** @return list<Station> */
    public function stations(bool $deleted = false): array
    {
        $stations = [];
        foreach ($this->all('SELECT id FROM stations WHERE deleted=? ORDER BY name COLLATE NOCASE', [(int) $deleted]) as $row) {
            $stations[] = $this->station((int) $row['id']) ?? throw new \RuntimeException('Station disappeared.');
        }

        return $stations;
    }

    /** @param Actor $actor */
    public function saveStation(array $actor, int $id, string $name, string $timezone, bool $enabled, int $version): void
    {
        Input::integer($id, 'station ID');
        Input::text($name, 'station name', 255, true);
        Input::timezone($timezone);
        $this->mutate($actor, 'station.save', (string) $id, function () use ($id, $name, $timezone, $enabled, $version): void {
            $this->version($this->station($id)['version'] ?? 0, $version);
            $this->execute('INSERT INTO stations(id,name,timezone,enabled) VALUES(?,?,?,?) ON CONFLICT(id) DO UPDATE SET
                name=excluded.name,timezone=excluded.timezone,enabled=excluded.enabled,deleted=0,version=stations.version+1', [$id, $name, $timezone, (int) $enabled]);
        });
    }

    /** @param Actor $actor */
    public function deleteStation(array $actor, int $id, int $version, string $confirmation): void
    {
        $this->mutate($actor, 'station.delete', (string) $id, function () use ($id, $version, $confirmation): void {
            $station = $this->station($id) ?? throw new Problem('Station not found.', 404);
            $this->version($station['version'], $version);
            if ($confirmation !== $station['name']) {
                throw new Problem('Type the exact station name to confirm deletion.');
            }
            if ($id === 1 || $this->one('SELECT station_id FROM memberships WHERE station_id=? LIMIT 1', [$id]) !== null) {
                throw new Problem('The primary station and stations with assigned DJs cannot be deleted.', 409);
            }
            $this->execute('UPDATE stations SET enabled=0,deleted=1,version=version+1 WHERE id=?', [$id]);
        });
    }

    /** @return Profile|null */
    public function profile(string $slug): ?array
    {
        $row = $this->one('SELECT * FROM profiles WHERE slug=?', [$slug]);
        if ($row === null) {
            return null;
        }
        $data = json_decode((string) $row['json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid stored profile.');
        }
        $data['published'] = (bool) $row['published'];

        return ['slug' => $slug, 'data' => $data, 'published' => (bool) $row['published'], 'deleted' => (bool) $row['deleted'],
            'version' => (int) $row['version'], 'updated_at' => (int) $row['updated_at']];
    }

    /** @return list<Profile> */
    public function profiles(bool $deleted = false): array
    {
        $profiles = [];
        foreach ($this->all('SELECT slug FROM profiles WHERE deleted=? ORDER BY slug', [(int) $deleted]) as $row) {
            $profiles[] = $this->profile((string) $row['slug']) ?? throw new \RuntimeException('Profile disappeared.');
        }

        return $profiles;
    }

    /**
     * @param Actor $actor
     * @param array<string,mixed> $data
     */
    public function saveProfile(array $actor, string $slug, array $data, int $version): void
    {
        Input::slug($slug);
        ProfileValidator::validate($data);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->mutate($actor, 'profile.save', $slug, function () use ($actor, $slug, $data, $json, $version): void {
            $this->requireProfileAccess($actor, $slug);
            $this->version($this->profile($slug)['version'] ?? 0, $version);
            $this->execute('INSERT INTO profiles(slug,json,published,updated_at) VALUES(?,?,?,?) ON CONFLICT(slug) DO UPDATE SET
                json=excluded.json,published=excluded.published,deleted=0,updated_at=excluded.updated_at,version=profiles.version+1',
                [$slug, $json, (int) ($data['published'] ?? true), time()]);
        }, true);
    }

    /** @param Actor $actor */
    public function deleteProfile(array $actor, string $slug, int $version, string $confirmation): void
    {
        $this->mutate($actor, 'profile.delete', $slug, function () use ($slug, $version, $confirmation): void {
            $profile = $this->profile($slug) ?? throw new Problem('Profile not found.', 404);
            $this->version($profile['version'], $version);
            if ($confirmation !== $slug) {
                throw new Problem('Type the exact profile slug to confirm deletion.');
            }
            // Keep a tombstone so a deployed JSON file or live schedule cannot recreate this public page.
            $this->execute('UPDATE profiles SET published=0,deleted=1,updated_at=?,version=version+1 WHERE slug=?', [time(), $slug]);
        });
    }

    /** @param Actor $actor */
    public function importProfiles(array $actor, string $root): void
    {
        $this->mutate($actor, 'profile.import', 'repository', function () use ($root): void {
            $this->importFiles($root);
        });
    }

    private function importFiles(string $root): void
    {
        foreach (PublicProfiles::files($root) as $slug => $data) {
            unset($data['slug']);
            try {
                ProfileValidator::validate($data);
                $this->execute('INSERT OR IGNORE INTO profiles(slug,json,published,updated_at) VALUES(?,?,?,?)',
                    [$slug, json_encode($data, JSON_THROW_ON_ERROR), (int) ($data['published'] ?? true), time()]);
            } catch (Problem) {
                $this->logger->warning('Repository profile import skipped');
            }
        }
    }

    /**
     * @param Actor $actor
     * @return array{station_id:int,streamer_id:int}
     */
    public function scheduleTarget(array $actor, int $sourceStation, int $sourceStreamer, int $targetStation): array
    {
        if (!$this->isAdministrator($actor)
            && ($actor['station_id'] !== $sourceStation || $actor['streamer_id'] !== $sourceStreamer)) {
            throw new Problem('You can only edit your assigned schedules.', 403);
        }
        $this->requireEditor($actor);
        $account = $this->requireAccount($sourceStation, $sourceStreamer);
        $station = $this->station($targetStation);
        $streamer = $account['assignments'][$targetStation] ?? null;
        if ($account['deleted'] || $station === null || $station['deleted'] || !$station['enabled'] || $streamer === null) {
            throw new Problem('Assign this DJ to an enabled station before editing their schedule.', 409);
        }

        return ['station_id' => $targetStation, 'streamer_id' => $streamer];
    }

    /** @param Actor $actor */
    public function recordScheduleChange(array $actor, int $station, int $streamer): void
    {
        // An external write was authorized before PUT. Keep its audit even if access changes while AzuraCast responds.
        $target = $station . ':' . $streamer;
        $this->execute('INSERT INTO admin_audit(actor_station_id,actor_streamer_id,action,target,created_at) VALUES(?,?,?,?,?)',
            [$actor['station_id'], $actor['streamer_id'], 'schedule.save', $target, time()]);
        $this->logger->info('Administrator schedule saved', ['actor_station_id' => $actor['station_id'], 'actor_streamer_id' => $actor['streamer_id'], 'target' => $target]);
    }

    /** @return list<array{id:int,actor_station_id:int,actor_streamer_id:int,action:string,target:string,created_at:int}> */
    public function audit(): array
    {
        $events = [];
        foreach ($this->all('SELECT * FROM admin_audit ORDER BY id DESC LIMIT 200') as $row) {
            $events[] = ['id' => (int) $row['id'], 'actor_station_id' => (int) $row['actor_station_id'],
                'actor_streamer_id' => (int) $row['actor_streamer_id'], 'action' => (string) $row['action'],
                'target' => (string) $row['target'], 'created_at' => (int) $row['created_at']];
        }

        return $events;
    }

    private function version(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw new Problem('This record changed in another session. Reload its latest version before saving.', 409);
        }
    }

    /**
     * @param Actor $actor
     * @param callable():void $change
     */
    private function mutate(array $actor, string $action, string $target, callable $change, bool $selfService = false): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if (!$selfService && !$this->isAdministrator($actor)) {
                throw new Problem('Administrator access is required.', 403);
            }
            $this->requireEditor($actor);
            $change();
            $this->execute('INSERT INTO admin_audit(actor_station_id,actor_streamer_id,action,target,created_at) VALUES(?,?,?,?,?)',
                [$actor['station_id'], $actor['streamer_id'], $action, $target, time()]);
            $this->db->exec('COMMIT');
        } catch (Throwable $exception) {
            $this->db->exec('ROLLBACK');
            throw $exception;
        }
        try {
            $this->logger->info('Administrator action', ['actor_station_id' => $actor['station_id'], 'actor_streamer_id' => $actor['streamer_id'], 'action' => $action, 'target' => $target]);
        } catch (Throwable) {
            // The transaction's audit row is authoritative if a log filesystem is unavailable.
            error_log('{"channel":"dj-admin","level":"error","message":"Administrator JSON log unavailable"}');
        }
    }

    /** @param Actor $actor */
    public function requireEditor(array $actor): void
    {
        if ($this->config->isAdministrator($actor['station_id'], $actor['streamer_id'])) {
            return;
        }
        $account = $this->requireAccount($actor['station_id'], $actor['streamer_id']);
        if (!$account['enabled'] || $account['deleted']) {
            throw new Problem('Your website editing access is disabled.', 403);
        }
    }

    /** @param Actor $actor */
    public function requireProfileAccess(array $actor, string $slug): void
    {
        $this->requireEditor($actor);
        if ($this->isAdministrator($actor)) {
            return;
        }
        $account = $this->requireAccount($actor['station_id'], $actor['streamer_id']);
        if ($account['profile_slug'] !== $slug || ($this->profile($slug)['deleted'] ?? false)) {
            throw new Problem('This profile or broadcast is not assigned to your account.', 403);
        }
    }

    /** @return array<string,mixed>|null */
    public function broadcastEdit(int $id): ?array
    {
        $row = $this->one('SELECT * FROM broadcast_edits WHERE id=?', [$id]);
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row['id'], 'owner_slug' => (string) $row['owner_slug'],
            'patch' => json_decode((string) $row['json'], true, 32, JSON_THROW_ON_ERROR),
            'source' => $row['source_json'] === null ? null : json_decode((string) $row['source_json'], true, 64, JSON_THROW_ON_ERROR),
            'manual' => (bool) $row['manual'], 'deleted' => (bool) $row['deleted'],
            'version' => (int) $row['version'], 'updated_at' => (int) $row['updated_at'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function broadcastEdits(): array
    {
        $records = [];
        foreach ($this->all('SELECT id FROM broadcast_edits ORDER BY id') as $row) {
            $records[] = $this->broadcastEdit((int) $row['id']) ?? throw new \RuntimeException('Broadcast disappeared.');
        }
        return $records;
    }

    /** @return array<string,mixed>|null */
    public function broadcastSource(int $id): ?array
    {
        require_once dirname(__DIR__) . '/radio.php';
        foreach (\tr_episode_source_archive()['episodes'] as $episode) {
            if ($episode['id'] === $id) {
                return $episode;
            }
        }
        $stored = $this->broadcastEdit($id);
        return is_array($stored['source'] ?? null) ? $stored['source'] : null;
    }

    /**
     * @param Actor $actor
     * @param array<string,mixed> $changes
     */
    public function saveBroadcast(array $actor, ?int $id, string $slug, array $changes, int $version, string $sourceHash): int
    {
        Input::slug($slug);
        $saved = $id;
        $this->mutate($actor, 'broadcast.save', $id === null ? 'new:' . $slug : (string) $id, function () use ($actor, $id, $slug, $changes, $version, $sourceHash, &$saved): void {
            $this->requireProfileAccess($actor, $slug);
            $stored = $id === null ? null : $this->broadcastEdit($id);
            $source = $id === null ? null : $this->broadcastSource($id);
            if ($id !== null && $stored === null && $source === null) {
                throw new Problem('Broadcast not found.', 404);
            }
            $owner = $stored['owner_slug'] ?? $source['dj_slug'] ?? $slug;
            $this->requireProfileAccess($actor, PublicBroadcasts::slug((string) $owner));
            $this->version((int) ($stored['version'] ?? 0), $version);
            if (!hash_equals(BroadcastForm::hash($source), $sourceHash)) {
                throw new Problem('Carrier updated this broadcast. Reload before saving.', 409);
            }
            if (($stored['deleted'] ?? false) && !$this->isAdministrator($actor)) {
                throw new Problem('Ask an administrator to restore this deleted listing.', 403);
            }
            if ($id === null) {
                $saved = max(1000000000, (int) ($this->one('SELECT MAX(id) AS last_id FROM broadcast_edits')['last_id'] ?? 0) + 1);
                while ($this->broadcastSource($saved) !== null) {
                    ++$saved;
                }
                Input::integer($saved, 'broadcast ID');
            }
            $patch = PublicBroadcasts::combine(is_array($stored['patch'] ?? null) ? $stored['patch'] : [], $changes);
            $manual = $stored['manual'] ?? ($id === null);
            if ($manual) {
                $patch = array_replace([
                    'dj' => $slug, 'started_at' => time(), 'ended_at' => null, 'is_live' => false,
                    'show' => [], 'tracks' => [], 'track_count' => 0, 'peak_listeners' => 0,
                    'max_couch' => 0, 'props' => 0, 'questions' => 0, 'requests' => 0, 'reactions' => 0, 'tildes' => 0,
                ], $patch);
            }
            if (strlen(json_encode($patch, JSON_THROW_ON_ERROR)) > 65536) {
                throw new Problem('Broadcast corrections must fit within 64 KiB.');
            }
            $this->execute('INSERT INTO broadcast_edits(id,owner_slug,json,source_json,manual,updated_at) VALUES(?,?,?,?,?,?)
                ON CONFLICT(id) DO UPDATE SET owner_slug=excluded.owner_slug,json=excluded.json,source_json=excluded.source_json,
                deleted=0,version=broadcast_edits.version+1,updated_at=excluded.updated_at',
                [$saved, $slug, json_encode($patch, JSON_THROW_ON_ERROR), $source === null ? null : json_encode($source, JSON_THROW_ON_ERROR), (int) $manual, time()]);
        }, true);
        return $saved ?? throw new \RuntimeException('Broadcast was not saved.');
    }

    /** @param Actor $actor */
    public function setBroadcastDeleted(array $actor, int $id, bool $deleted, int $version, string $sourceHash, string $confirmation): void
    {
        $this->mutate($actor, $deleted ? 'broadcast.delete' : 'broadcast.restore', (string) $id, function () use ($actor, $id, $deleted, $version, $sourceHash, $confirmation): void {
            $stored = $this->broadcastEdit($id);
            $source = $this->broadcastSource($id);
            if ($stored === null && $source === null) {
                throw new Problem('Broadcast not found.', 404);
            }
            $slug = PublicBroadcasts::slug((string) ($stored['owner_slug'] ?? $source['dj_slug'] ?? ''));
            $this->requireProfileAccess($actor, $slug);
            if (!$deleted && !$this->isAdministrator($actor)) {
                throw new Problem('Only an administrator can restore a deleted broadcast.', 403);
            }
            $this->version((int) ($stored['version'] ?? 0), $version);
            if (!hash_equals(BroadcastForm::hash($source), $sourceHash)) {
                throw new Problem('Carrier updated this broadcast. Reload before changing its visibility.', 409);
            }
            if ($confirmation !== ($deleted ? 'DELETE' : 'RESTORE')) {
                throw new Problem('Type ' . ($deleted ? 'DELETE' : 'RESTORE') . ' to confirm.');
            }
            $this->execute('INSERT INTO broadcast_edits(id,owner_slug,json,source_json,deleted,updated_at) VALUES(?,?,?,?,?,?)
                ON CONFLICT(id) DO UPDATE SET deleted=excluded.deleted,source_json=excluded.source_json,
                version=broadcast_edits.version+1,updated_at=excluded.updated_at',
                [$id, $slug, json_encode($stored['patch'] ?? [], JSON_THROW_ON_ERROR), $source === null ? null : json_encode($source, JSON_THROW_ON_ERROR), (int) $deleted, time()]);
        }, true);
    }

    /** @param list<int|string|null> $params */
    private function execute(string $sql, array $params = []): void
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
    }

    /**
     * @param list<int|string|null> $params
     * @return array<string,mixed>|null
     * @phpstan-impure
     */
    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<int|string|null> $params
     * @return list<array<string,mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
