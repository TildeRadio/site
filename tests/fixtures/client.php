<?php

declare(strict_types=1);

// Test-only bridge, kept outside the document root by the HTTP test harness.
namespace TildeRadio\DjAuth;

final class AuthException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Test authentication failure.');
    }
}

final class DjAuthClient
{
    private function __construct(private readonly string $path)
    {
    }

    public static function fromFile(string $path): self
    {
        return new self($path);
    }

    public function verify(string $username, #[\SensitiveParameter] string $password, string $ip): array
    {
        if (!in_array($username, ['deepend', 'cat'], true) || $password !== 'test-correct-password') {
            throw new AuthException('invalid_credentials');
        }

        return $this->check($username === 'deepend' ? 163 : 4);
    }

    public function check(int $streamerId): array
    {
        if (!in_array($streamerId, [163, 4], true)) {
            throw new AuthException('invalid_credentials');
        }
        $mode = json_decode(file_get_contents($this->path), true)['mode'];
        if ($mode !== 'active') {
            throw new AuthException($mode === 'revoked' ? 'invalid_credentials' : 'unavailable');
        }

        return [
            'station_id' => 1, 'streamer_id' => $streamerId,
            'username' => $streamerId === 163 ? 'deepend' : 'cat',
            'display_name' => $streamerId === 163 ? 'deepend' : '<Cat>', 'verified_at' => time(),
        ];
    }
}
