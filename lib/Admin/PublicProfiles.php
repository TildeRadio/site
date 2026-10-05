<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

use PDO;
use Throwable;

// This class uses native PHP only. Public pages must also work without Composer.
final class PublicProfiles
{
    /** @return array<string, array<string, mixed>> */
    public static function files(string $root): array
    {
        $profiles = [];
        $legacy = $root . '/data/djs.php';
        if (is_file($legacy)) {
            $data = require $legacy;
            if (is_array($data)) {
                foreach ($data as $key => $profile) {
                    if (!is_array($profile)) {
                        continue;
                    }
                    $name = $profile['slug'] ?? $profile['name'] ?? $key;
                    if (!is_string($name)) {
                        continue;
                    }
                    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
                    if ($slug !== '') {
                        $profiles[$slug] = $profile + ['slug' => $slug];
                    }
                }
            }
        }
        foreach (glob($root . '/data/djs/*.json') ?: [] as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $slug) || filesize($file) > 65536) {
                continue;
            }
            try {
                $raw = file_get_contents($file);
                $profile = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
                if (!is_array($profile) || ($profile !== [] && array_is_list($profile))) {
                    throw new \RuntimeException('Invalid profile.');
                }
                $profiles[$slug] = array_replace($profile, ['slug' => $slug]);
            } catch (Throwable) {
                error_log('{"channel":"dj-profile","level":"warning","message":"Repository profile skipped"}');
            }
        }

        return $profiles;
    }

    public static function databasePath(): string
    {
        $override = getenv('TILDERADIO_DJ_ADMIN_DB');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        $state = '/var/lib/tilderadio-dj-auth-site';
        $config = getenv('TILDERADIO_DJ_SITE_CONFIG') ?: '/etc/tilderadio/dj-auth-site.json';
        if (is_file($config) && is_readable($config) && filesize($config) <= 65536) {
            try {
                $raw = file_get_contents($config);
                $data = is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;
                if (is_array($data) && is_string($data['state_dir'] ?? null)) {
                    $state = $data['state_dir'];
                }
            } catch (Throwable) {
                // Repository profiles remain available if site config is absent/broken.
            }
        }

        return $state . '/admin.sqlite';
    }

    /** @return array<string, array<string, mixed>> */
    public static function metadata(string $root, ?string $database = null): array
    {
        $profiles = self::files($root);
        $database ??= self::databasePath();
        if (!is_file($database)) {
            return $profiles;
        }
        try {
            $path = realpath($database);
            $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? $root) ?: $root;
            if ($path === false || !str_starts_with($database, '/')
                || str_starts_with($path, rtrim($docRoot, '/') . '/')) {
                throw new \RuntimeException('Private profile database unavailable.');
            }
            $uri = str_replace('%2F', '/', rawurlencode($path));
            $db = new PDO('sqlite:file:' . $uri . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000');
            foreach ($db->query('SELECT slug,json,published,deleted FROM profiles')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $slug = (string) $row['slug'];
                $profile = json_decode((string) $row['json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($profile)) {
                    throw new \RuntimeException('Invalid stored profile.');
                }
                $profile['slug'] = $slug;
                $profile['published'] = (bool) $row['published'] && !(bool) $row['deleted'];
                $profiles[$slug] = $profile;
            }
        } catch (Throwable) {
            error_log('{"channel":"dj-profile","level":"error","message":"Profile overrides unavailable"}');
        }

        return $profiles;
    }
}
