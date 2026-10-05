<?php

declare(strict_types=1);

namespace TildeRadio\Site\DjAuth;

use Throwable;

final readonly class AzuraCastTransport implements Transport
{
    private \TildeRadio\DjAuth\DjAuthClient $client;

    public function __construct(Config $config)
    {
        require_once $config->clientBootstrap();
        $this->client = \TildeRadio\DjAuth\DjAuthClient::fromFile($config->clientConfig());
    }

    public function verify(string $username, #[\SensitiveParameter] string $password, string $ip): array
    {
        try {
            return $this->client->verify($username, $password, $ip);
        } catch (Throwable $exception) {
            throw new Failure($exception instanceof \TildeRadio\DjAuth\AuthException
                ? $exception->reason : 'unavailable');
        }
    }

    public function check(int $streamerId): array
    {
        try {
            return $this->client->check($streamerId);
        } catch (Throwable $exception) {
            throw new Failure($exception instanceof \TildeRadio\DjAuth\AuthException
                ? $exception->reason : 'unavailable');
        }
    }
}
