<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

use RuntimeException;
use TildeRadio\Site\DjAuth\Config;

final readonly class CarrierClient
{
    /** @var array<string,mixed> */
    private array $settings;
    private string $key;

    public function __construct(Config $config)
    {
        $this->settings = $config->carrier() ?? throw new Problem('Carrier integration is not enabled.', 503);
        $keyFile = $this->settings['key_file'];
        $socket = $this->settings['socket_path'];
        foreach ([$keyFile, $socket] as $path) {
            if (!is_string($path) || !str_starts_with($path, '/') || str_contains($path, "\0")) {
                throw new RuntimeException('Invalid private Carrier path.');
            }
            $parent = realpath(dirname($path));
            $root = realpath(dirname(__DIR__, 2));
            if ($parent === false || $parent === $root || str_starts_with($parent, $root . '/')) {
                throw new RuntimeException('Carrier paths must be outside the website.');
            }
        }
        if (!is_file($keyFile) || is_link($keyFile) || !is_readable($keyFile) || filesize($keyFile) > 128 || (fileperms($keyFile) & 0007) !== 0) {
            throw new RuntimeException('Private Carrier key unavailable.');
        }
        $this->key = trim((string) file_get_contents($keyFile));
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $this->key)) {
            throw new RuntimeException('Invalid Carrier key.');
        }
    }

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function call(array $request): array
    {
        $payload = json_encode($request + ['key' => $this->key, 'expires' => time() + 5], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
        if (strlen($payload) > 1048576) {
            throw new Problem('Carrier request limit exceeded.', 503);
        }
        $socket = $this->settings['socket_path'];
        if (is_link($socket) || @filetype($socket) !== 'socket') {
            throw new Problem('Carrier is offline. Existing profile, schedule and broadcast editing remain available.', 503);
        }
        $connection = @stream_socket_client('unix://' . $socket, $errorCode, $errorMessage, 1);
        if ($connection === false) {
            throw new Problem('Carrier could not be reached. Reload before retrying a live action.', 503);
        }
        try {
            stream_set_timeout($connection, 8);
            $offset = 0;
            while ($offset < strlen($payload)) {
                $written = fwrite($connection, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw new Problem('Carrier connection interrupted. Reload before retrying.', 503);
                }
                $offset += $written;
            }
            $response = fgets($connection, 1048578);
            if (!is_string($response) || !str_ends_with($response, "\n") || strlen($response) > 1048576) {
                throw new Problem('Carrier response unavailable. Reload to check whether the action completed.', 503);
            }
            $result = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($result) || ($result['ok'] ?? false) !== true || !is_array($result['data'] ?? null)) {
                throw new Problem(is_string($result['error'] ?? null) ? $result['error'] : 'Carrier rejected this request.', 409);
            }
            return $result['data'];
        } finally {
            fclose($connection);
        }
    }
}
