<?php

declare(strict_types=1);

namespace TildeRadio\Site\DjAuth;

use RuntimeException;

final readonly class Config
{
    private string $canonicalOrigin;

    /** @param array<string, mixed> $data */
    public function __construct(private array $data)
    {
        foreach (['origin', 'state_dir', 'client_bootstrap', 'client_config'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new RuntimeException('Invalid DJ login configuration.');
            }
        }
        $url = parse_url($data['origin']);
        if (filter_var($data['origin'], FILTER_VALIDATE_URL) === false
            || !is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Invalid DJ login origin.');
        }
        $this->canonicalOrigin = 'https://' . strtolower($url['host'])
            . (isset($url['port']) && $url['port'] !== 443 ? ':' . $url['port'] : '');
        $base = $data['base_path'] ?? '';
        if (!is_string($base) || ($base !== '' && !preg_match('~\A(?:/[a-zA-Z0-9_-]+)+\z~D', $base))) {
            throw new RuntimeException('Invalid DJ login base path.');
        }
        foreach (['trusted_proxy_ips', 'accounts', 'administrators'] as $key) {
            if (!is_array($data[$key] ?? [])) {
                throw new RuntimeException('Invalid DJ login configuration.');
            }
        }
        foreach ($data['trusted_proxy_ips'] ?? [] as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new RuntimeException('Invalid trusted proxy IP.');
            }
        }
        foreach ($data['accounts'] ?? [] as $id => $slug) {
            if (!is_string($id) || !preg_match('/\A[1-9][0-9]*:[1-9][0-9]*\z/D', $id)
                || !is_string($slug) || !preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $slug)) {
                throw new RuntimeException('Invalid DJ ownership mapping.');
            }
        }
        if (!array_is_list($data['administrators'] ?? [])) {
            throw new RuntimeException('Invalid administrator configuration.');
        }
        foreach ($data['administrators'] ?? [] as $id) {
            if (!is_string($id) || !preg_match('/\A[1-9][0-9]*:[1-9][0-9]*\z/D', $id)) {
                throw new RuntimeException('Invalid administrator configuration.');
            }
        }
        foreach (['state_dir', 'client_bootstrap', 'client_config'] as $key) {
            $path = realpath($data[$key]);
            if ($path === false || !str_starts_with($data[$key], '/')
                || self::inside($path, dirname(__DIR__, 2))) {
                throw new RuntimeException('DJ login paths must be private and outside the website.');
            }
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            if (is_string($docRoot) && $docRoot !== '' && self::inside($path, $docRoot)) {
                throw new RuntimeException('DJ login paths must be outside the document root.');
            }
        }
        if (!is_dir($data['state_dir']) || !is_dir($data['state_dir'] . '/sessions')
            || is_link($data['state_dir'] . '/sessions')
            || !is_writable($data['state_dir']) || !is_writable($data['state_dir'] . '/sessions')
            || !is_readable($data['client_bootstrap']) || !is_readable($data['client_config'])) {
            throw new RuntimeException('DJ login private paths unavailable.');
        }
        foreach ([$data['state_dir'], $data['state_dir'] . '/sessions'] as $directory) {
            $mode = fileperms($directory);
            if ($mode === false || ($mode & 0077) !== 0) {
                throw new RuntimeException('DJ login state directories must have mode 0700.');
            }
        }
    }

    public static function load(): self
    {
        $path = getenv('TILDERADIO_DJ_SITE_CONFIG') ?: '/etc/tilderadio/dj-auth-site.json';
        $resolved = realpath($path);
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($resolved === false || !is_file($path) || !is_readable($path) || filesize($path) > 65536
            || self::inside($resolved, dirname(__DIR__, 2))
            || (is_string($docRoot) && $docRoot !== '' && self::inside($resolved, $docRoot))) {
            throw new RuntimeException('DJ login configuration unavailable.');
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;
        if (!is_array($data)) {
            throw new RuntimeException('Invalid DJ login configuration.');
        }

        return new self($data);
    }

    private static function inside(string $path, string $directory): bool
    {
        $directory = realpath($directory) ?: $directory;

        return $path === $directory || str_starts_with($path, rtrim($directory, '/') . '/');
    }

    public function origin(): string
    {
        return $this->canonicalOrigin;
    }

    public function path(string $suffix = ''): string
    {
        return ($this->data['base_path'] ?? '') . '/dj/' . $suffix;
    }

    public function stateDir(): string
    {
        return $this->data['state_dir'];
    }

    public function clientBootstrap(): string
    {
        return $this->data['client_bootstrap'];
    }

    public function clientConfig(): string
    {
        return $this->data['client_config'];
    }

    /** @param array<string, mixed> $server */
    public function secure(array $server): bool
    {
        return in_array(strtolower((string) ($server['HTTPS'] ?? '')), ['on', '1'], true)
            || (in_array($server['REMOTE_ADDR'] ?? '', $this->data['trusted_proxy_ips'] ?? [], true)
                && ($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** @param array<string, mixed> $server */
    public function userIp(array $server): string
    {
        $remote = $server['REMOTE_ADDR'] ?? '';
        // Only a configured proxy may supply the single overwritten client-IP header.
        $ip = in_array($remote, $this->data['trusted_proxy_ips'] ?? [], true)
            ? ($server['HTTP_X_REAL_IP'] ?? '') : $remote;
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Client address unavailable.');
        }

        return $ip;
    }

    public function slug(int $stationId, int $streamerId): ?string
    {
        return $this->data['accounts'][$stationId . ':' . $streamerId] ?? null;
    }

    public function isAdministrator(int $stationId, int $streamerId): bool
    {
        return in_array($stationId . ':' . $streamerId, $this->data['administrators'] ?? [], true);
    }

    /** @return list<array{station_id:int,streamer_id:int,slug:?string}> */
    public function seedAccounts(): array
    {
        $ids = array_unique(array_merge(array_keys($this->data['accounts'] ?? []), $this->data['administrators'] ?? []));
        $accounts = [];
        foreach ($ids as $id) {
            [$station, $streamer] = explode(':', $id);
            $accounts[] = ['station_id' => (int) $station, 'streamer_id' => (int) $streamer, 'slug' => $this->data['accounts'][$id] ?? null];
        }

        return $accounts;
    }

    /** @return array<string, mixed>|null */
    public function scheduleApi(): ?array
    {
        $settings = $this->data['schedule_api'] ?? null;
        if ($settings === null) {
            return null;
        }
        if (!is_array($settings)) {
            throw new RuntimeException('Invalid schedule API configuration.');
        }

        return $settings;
    }
}
