<?php

declare(strict_types=1);

namespace TildeRadio\Site\DjAuth;

final class Csrf
{
    public static function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function valid(mixed $expected, mixed $submitted): bool
    {
        return is_string($expected) && strlen($expected) === 64 && is_string($submitted)
            && hash_equals($expected, $submitted);
    }
}
