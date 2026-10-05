<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

final class Input
{
    public static function text(mixed $value, string $label, int $max = 255, bool $required = false): string
    {
        if (!is_string($value) || strlen($value) > $max || preg_match('//u', $value) !== 1
            || str_contains($value, "\0") || ($required && trim($value) === '')) {
            throw new Problem('Enter a valid ' . $label . '.');
        }

        return trim($value);
    }

    public static function integer(mixed $value, string $label, bool $zero = false): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[0-9]{1,10}\z/D', (string) $value)
            || (int) $value < ($zero ? 0 : 1) || (int) $value > 2147483647) {
            throw new Problem('Enter a valid ' . $label . '.');
        }

        return (int) $value;
    }

    public static function slug(mixed $value): string
    {
        $slug = self::text($value, 'profile slug', 80, true);
        if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $slug)) {
            throw new Problem('Use lowercase letters, numbers and hyphens for the profile slug.');
        }

        return $slug;
    }

    public static function timezone(mixed $value): string
    {
        $timezone = self::text($value, 'timezone', 80, true);
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new Problem('Use an IANA timezone, such as UTC or America/Edmonton.');
        }

        return $timezone;
    }

    /** @return list<string> */
    public static function lines(mixed $value, string $label, bool $paragraphs = false): array
    {
        $text = self::text($value, $label, 16384);
        $parts = preg_split($paragraphs ? '/\R\s*\R/' : '/\R/', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
