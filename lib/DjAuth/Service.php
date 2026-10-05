<?php

declare(strict_types=1);

namespace TildeRadio\Site\DjAuth;

use Psr\Log\LoggerInterface;

final readonly class Service
{
    public function __construct(private Transport $transport, private RateLimiter $limiter, private LoggerInterface $logger)
    {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{station_id:int,streamer_id:int,username:string,display_name:string,verified_at:int}
     */
    public function login(#[\SensitiveParameter] array $post, string $ip, int $now): array
    {
        if (!Csrf::valid($_SESSION['csrf'] ?? null, $post['csrf'] ?? null)) {
            throw new Failure('csrf');
        }
        $username = $post['username'] ?? null;
        $password = $post['password'] ?? null;
        if (!is_string($username) || strlen($username) < 1 || strlen($username) > 50
            || trim($username) !== $username || preg_match('/[\x00-\x1f\x7f]/', $username)
            || preg_match('//u', $username) !== 1 || !is_string($password) || $password === ''
            || strlen($password) > 1024 || preg_match('//u', $password) !== 1) {
            throw new Failure('invalid_input');
        }
        if (!$this->limiter->allow([
            ['id' => 'login-ip:' . bin2hex((string) inet_pton($ip)), 'limit' => 10, 'seconds' => 300],
            ['id' => 'login-user:' . strtolower($username), 'limit' => 10, 'seconds' => 300],
            ['id' => 'api-total', 'limit' => 100, 'seconds' => 60],
        ], $now)) {
            throw new Failure('rate_limited');
        }
        try {
            $identity = $this->transport->verify($username, $password, $ip);
            $this->logger->info('DJ login succeeded', ['station_id' => $identity['station_id'], 'streamer_id' => $identity['streamer_id']]);

            return $identity;
        } catch (Failure $failure) {
            $this->logger->notice('DJ login failed', ['reason' => $failure->reason]);
            throw $failure;
        }
    }

    /** @return array{station_id:int,streamer_id:int,username:string,display_name:string,verified_at:int}|null */
    public function identity(int $now, bool $forceRecheck = false): ?array
    {
        $identity = $_SESSION['identity'] ?? null;
        if (!is_array($identity)) {
            return null;
        }
        if (!is_int($identity['station_id'] ?? null) || !is_int($identity['streamer_id'] ?? null)
            || !is_string($identity['username'] ?? null) || !is_string($identity['display_name'] ?? null)
            || !is_int($identity['verified_at'] ?? null) || Session::expired($_SESSION, $now)) {
            Session::forgetIdentity();

            return null;
        }
        $checked = $_SESSION['checked'] ?? 0;
        if ($forceRecheck || !is_int($checked) || $now < $checked || $now - $checked >= Session::RECHECK_SECONDS) {
            if (!$this->limiter->allow([['id' => 'api-total', 'limit' => 100, 'seconds' => 60]], $now)) {
                throw new Failure('rate_limited');
            }
            try {
                $fresh = $this->transport->check($identity['streamer_id']);
                if ($fresh['station_id'] !== $identity['station_id'] || $fresh['streamer_id'] !== $identity['streamer_id']) {
                    throw new Failure('invalid_credentials');
                }
                $_SESSION['identity'] = $identity = $fresh;
                $_SESSION['checked'] = $now;
            } catch (Failure $failure) {
                if ($failure->reason === 'invalid_credentials') {
                    Session::forgetIdentity();
                    $this->logger->notice('DJ session revoked');

                    return null;
                }
                // Never display authenticated content after a required recheck fails.
                throw $failure;
            }
        }
        $_SESSION['seen'] = $now;

        return $identity;
    }
}
