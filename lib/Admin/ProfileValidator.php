<?php

declare(strict_types=1);

namespace TildeRadio\Site\Admin;

final class ProfileValidator
{
    public const MAX_BYTES = 65536;
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** @return array<string, mixed> */
    public static function decode(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES || !str_starts_with(ltrim($json), '{')) {
            throw new Problem('A profile must be a JSON object smaller than 64 KiB.');
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Problem('The profile JSON is not valid.');
        }
        if (!is_array($data)) {
            throw new Problem('A profile must be a JSON object.');
        }
        self::validate($data);

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function validate(array $data): void
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Problem('The profile contains invalid text.');
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new Problem('A profile must be smaller than 64 KiB.');
        }
        self::tree($data);
        foreach (['slug', 'upcoming', '_profile_hidden'] as $reserved) {
            if (array_key_exists($reserved, $data)) {
                throw new Problem('The profile URL and schedule are managed separately.');
            }
        }
        foreach (['name', 'tagline', 'description', 'pronouns', 'location', 'tilde', 'irc', 'since'] as $key) {
            if (isset($data[$key])) {
                Input::text($data[$key], $key, $key === 'description' ? 8192 : 2048);
            } elseif (array_key_exists($key, $data)) {
                throw new Problem($key . ' must be text.');
            }
        }
        if (array_key_exists('published', $data) && !is_bool($data['published'])) {
            throw new Problem('published must be true or false.');
        }
        foreach (['bio', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                self::textList($data[$key], $key, true);
            }
        }
        if (isset($data['avatar'])) {
            $avatar = Input::text($data['avatar'], 'avatar', 2048, true);
            $local = str_starts_with($avatar, '/') && !str_starts_with($avatar, '//')
                && !str_contains(rawurldecode($avatar), '..') && !str_contains($avatar, '\\')
                && preg_match('/[\x00-\x20\x7f]/', $avatar) !== 1;
            if (!$local && !self::url($avatar, true)) {
                throw new Problem('Use a site-relative avatar path or an HTTPS image URL.');
            }
        }
        if (array_key_exists('links', $data)) {
            self::links($data['links']);
        }
        if (array_key_exists('show', $data)) {
            $show = self::object($data['show'], 'show');
            foreach (['title', 'tagline', 'description'] as $key) {
                if (array_key_exists($key, $show)) {
                    Input::text($show[$key], 'show.' . $key, $key === 'description' ? 8192 : 2048);
                }
            }
            if (isset($show['timezone']) && $show['timezone'] !== '') {
                Input::timezone($show['timezone']);
            }
            if (array_key_exists('genres', $show)) {
                self::textList($show['genres'], 'show.genres');
            }
            if (array_key_exists('formats', $show)) {
                self::formats($show['formats']);
            }
        }
        if (array_key_exists('favorites', $data)) {
            $favorites = self::object($data['favorites'], 'favorites');
            foreach (['artists', 'albums', 'tracks'] as $key) {
                if (array_key_exists($key, $favorites)) {
                    self::textList($favorites[$key], 'favorites.' . $key);
                }
            }
        }
    }

    /** @param array<array-key, mixed> $data */
    private static function tree(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), ['password', 'streamer_password', 'api_key', 'secret', 'token'], true)) {
                throw new Problem('Do not put passwords or API credentials in a public profile.');
            }
            if (is_array($value)) {
                self::tree($value);
            } elseif (is_string($value) && str_contains($value, "\0")) {
                throw new Problem('The profile contains invalid text.');
            }
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $label): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new Problem($label . ' must be an object.');
        }

        return $value;
    }

    private static function textList(mixed $value, string $label, bool $stringAllowed = false): void
    {
        if ($stringAllowed && is_string($value)) {
            Input::text($value, $label, 16384, true);

            return;
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new Problem($label . ' must be a list of text values.');
        }
        foreach ($value as $text) {
            Input::text($text, $label, 8192, true);
        }
    }

    private static function url(string $url, bool $httpsOnly = false): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), $httpsOnly ? ['https'] : ['http', 'https'], true)
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null;
    }

    private static function links(mixed $value): void
    {
        if (!is_array($value) || count($value) > 30) {
            throw new Problem('links must contain at most 30 links.');
        }
        foreach ($value as $key => $link) {
            if (array_is_list($value)) {
                $row = self::object($link, 'link');
                $label = $row['label'] ?? null;
                $url = $row['url'] ?? null;
            } else {
                $label = $key;
                $url = $link;
            }
            Input::text($label, 'link label', 100, true);
            $url = Input::text($url, 'link URL', 2048, true);
            if (!self::url($url)) {
                throw new Problem('Profile links must use HTTP or HTTPS without embedded credentials.');
            }
        }
    }

    private static function formats(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 30) {
            throw new Problem('show.formats must be a list of at most 30 formats.');
        }
        $ids = [];
        foreach ($value as $row) {
            $format = self::object($row, 'format');
            $id = Input::slug($format['id'] ?? null);
            if (isset($ids[$id])) {
                throw new Problem('Each show format needs a unique ID.');
            }
            $ids[$id] = true;
            $days = $format['days'] ?? $format['weekday'] ?? null;
            $days = is_string($days) ? [$days] : $days;
            if (!is_array($days) || !array_is_list($days) || $days === [] || count($days) > 7) {
                throw new Problem('Each show format needs one or more weekday names.');
            }
            foreach ($days as $day) {
                if (!is_string($day) || !in_array(strtolower(trim($day)), self::DAYS, true)) {
                    throw new Problem('Use weekday names such as monday in show formats.');
                }
            }
            foreach (['title', 'tagline', 'description'] as $key) {
                if (array_key_exists($key, $format)) {
                    Input::text($format[$key], 'format.' . $key, $key === 'description' ? 8192 : 2048);
                }
            }
            if (array_key_exists('genres', $format)) {
                self::textList($format['genres'], 'format.genres');
            }
        }
    }
}
