<?php

declare(strict_types=1);

namespace TildeRadio\Site\DjAuth;

use RuntimeException;

final class Session
{
    public const COOKIE = '__Secure-TildeRadioDJ';
    public const IDLE_SECONDS = 900;
    public const MAX_SECONDS = 3600;
    public const RECHECK_SECONDS = 60;

    public static function start(Config $config): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            throw new RuntimeException('Unexpected active session.');
        }
        umask(0077);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.save_handler', 'files');
        ini_set('session.gc_maxlifetime', (string) self::MAX_SECONDS);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_save_path($config->stateDir() . '/sessions');
        session_name(self::COOKIE);
        session_set_cookie_params([
            'lifetime' => 0, 'path' => $config->path(), 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_cache_limiter('nocache');
        if (!session_start()) {
            throw new RuntimeException('Could not start DJ session.');
        }
        if (!is_string($_SESSION['csrf'] ?? null)) {
            $_SESSION['csrf'] = Csrf::token();
        }
    }

    /** @param array<string, mixed> $state */
    public static function expired(array $state, int $now): bool
    {
        return !is_int($state['started'] ?? null) || !is_int($state['seen'] ?? null)
            || $now < $state['started'] || $now - $state['started'] >= self::MAX_SECONDS
            || $now < $state['seen'] || $now - $state['seen'] >= self::IDLE_SECONDS;
    }

    /** @param array{station_id:int,streamer_id:int,username:string,display_name:string,verified_at:int} $identity */
    public static function login(array $identity, int $now): void
    {
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Could not renew DJ session.');
        }
        $_SESSION = ['csrf' => Csrf::token(), 'identity' => $identity, 'started' => $now, 'seen' => $now, 'checked' => $now];
    }

    public static function forgetIdentity(): void
    {
        $_SESSION = ['csrf' => Csrf::token()];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Could not renew DJ session.');
        }
    }

    public static function logout(Config $config): void
    {
        $_SESSION = [];
        if (!session_destroy()) {
            throw new RuntimeException('Could not destroy DJ session.');
        }
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600, 'path' => $config->path(), 'secure' => true,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
}
