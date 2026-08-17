#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This validator must be run from the command line.\n");
    exit(2);
}

$root = dirname(__DIR__);
$profileDir = $root . '/data/djs';
$maxBytes = 65536;

function djv_usage(): void
{
    echo "Usage: php bin/validate-djs.php [data/djs/profile.json ...]\n";
    echo "With no filenames, every data/djs/*.json profile is checked.\n";
}

function djv_relative(string $path, string $root): string
{
    $normalized = str_replace('\\', '/', $path);
    $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    return str_starts_with($normalized, $prefix) ? substr($normalized, strlen($prefix)) : $normalized;
}

function djv_is_object(mixed $value): bool
{
    return is_array($value) && !array_is_list($value);
}

function djv_validate_text_list(mixed $value, string $field, array &$errors): void
{
    if (is_string($value)) {
        if (trim($value) === '') {
            $errors[] = "$field must not be an empty string";
        }
        return;
    }
    if (!is_array($value) || !array_is_list($value)) {
        $errors[] = "$field must be a string or an array of strings";
        return;
    }
    foreach ($value as $index => $item) {
        if (!is_string($item) || trim($item) === '') {
            $errors[] = "{$field}[{$index}] must be a non-empty string";
        }
    }
}

function djv_validate_string_field(array $profile, string $field, array &$errors, int $maxLength = 4096): void
{
    if (!array_key_exists($field, $profile)) {
        return;
    }
    if (!is_string($profile[$field])) {
        $errors[] = "$field must be a string";
        return;
    }
    if (strlen($profile[$field]) > $maxLength) {
        $errors[] = "$field is longer than $maxLength bytes";
    }
}

function djv_valid_http_url(string $url, bool $httpsOnly = false): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return $httpsOnly ? $scheme === 'https' : in_array($scheme, ['http', 'https'], true);
}

function djv_validate_links(mixed $value, array &$errors): void
{
    if (!is_array($value)) {
        $errors[] = 'links must be an object or an array';
        return;
    }

    if (!array_is_list($value)) {
        foreach ($value as $label => $url) {
            if (!is_string($label) || trim($label) === '' || !is_string($url) || !djv_valid_http_url(trim($url))) {
                $errors[] = 'links object values must be label: HTTP(S) URL pairs';
            }
        }
        return;
    }

    foreach ($value as $index => $link) {
        if (!djv_is_object($link)) {
            $errors[] = "links[$index] must be an object with label and url";
            continue;
        }
        $label = $link['label'] ?? null;
        $url = $link['url'] ?? null;
        if (!is_string($label) || trim($label) === '') {
            $errors[] = "links[$index].label must be a non-empty string";
        }
        if (!is_string($url) || !djv_valid_http_url(trim($url))) {
            $errors[] = "links[$index].url must be an HTTP(S) URL";
        }
    }
}

function djv_validate_profile(string $file, string $root, int $maxBytes): array
{
    $errors = [];
    $warnings = [];
    $basename = basename($file);
    $slug = pathinfo($basename, PATHINFO_FILENAME);

    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
        $errors[] = 'filename must be a lowercase slug using letters, numbers, and hyphens';
    }

    $size = @filesize($file);
    if ($size === false) {
        $errors[] = 'file is unreadable';
        return [$errors, $warnings];
    }
    if ($size > $maxBytes) {
        $errors[] = "file exceeds the $maxBytes-byte profile limit";
        return [$errors, $warnings];
    }

    $json = @file_get_contents($file);
    if (!is_string($json)) {
        $errors[] = 'file could not be read';
        return [$errors, $warnings];
    }

    try {
        $profile = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $errors[] = 'invalid JSON: ' . $exception->getMessage();
        return [$errors, $warnings];
    }

    if (!djv_is_object($profile)) {
        $errors[] = 'top level must be a JSON object';
        return [$errors, $warnings];
    }

    $known = [
        'name', 'published', 'tagline', 'description', 'bio', 'avatar', 'pronouns', 'location',
        'tilde', 'irc', 'since', 'links', 'show', 'favorites', 'notes',
    ];
    foreach (array_keys($profile) as $key) {
        if (!in_array($key, $known, true)) {
            $warnings[] = "unknown field: $key";
        }
    }

    if (array_key_exists('published', $profile) && !is_bool($profile['published'])) {
        $errors[] = 'published must be true or false';
    }

    foreach (['name', 'tagline', 'description', 'pronouns', 'location', 'tilde', 'irc', 'since'] as $field) {
        djv_validate_string_field($profile, $field, $errors, $field === 'description' ? 8192 : 2048);
    }
    if (isset($profile['name']) && is_string($profile['name']) && trim($profile['name']) === '') {
        $warnings[] = 'name is empty; the filename slug will be used as the display name';
    }

    if (array_key_exists('bio', $profile)) {
        djv_validate_text_list($profile['bio'], 'bio', $errors);
    }
    if (array_key_exists('notes', $profile)) {
        djv_validate_text_list($profile['notes'], 'notes', $errors);
    }

    if (array_key_exists('avatar', $profile)) {
        if (!is_string($profile['avatar']) || trim($profile['avatar']) === '') {
            $errors[] = 'avatar must be a non-empty string';
        } else {
            $avatar = trim($profile['avatar']);
            $local = str_starts_with($avatar, '/') && !str_starts_with($avatar, '//') && !str_contains($avatar, '..');
            if (!$local && !djv_valid_http_url($avatar, true)) {
                $errors[] = 'avatar must be a safe site-relative path or an HTTPS URL';
            }
        }
    }

    if (array_key_exists('links', $profile)) {
        djv_validate_links($profile['links'], $errors);
    }

    if (array_key_exists('show', $profile)) {
        if (!djv_is_object($profile['show'])) {
            $errors[] = 'show must be an object';
        } else {
            $show = $profile['show'];
            foreach (['title', 'tagline', 'description'] as $field) {
                djv_validate_string_field($show, $field, $errors, $field === 'description' ? 8192 : 2048);
            }
            if (array_key_exists('genres', $show)) {
                if (!is_array($show['genres']) || !array_is_list($show['genres'])) {
                    $errors[] = 'show.genres must be an array of strings';
                } else {
                    foreach ($show['genres'] as $index => $genre) {
                        if (!is_string($genre) || trim($genre) === '') {
                            $errors[] = "show.genres[$index] must be a non-empty string";
                        }
                    }
                }
            }
            foreach (array_keys($show) as $key) {
                if (!in_array($key, ['title', 'tagline', 'description', 'genres'], true)) {
                    $warnings[] = "unknown show field: $key";
                }
            }
        }
    }

    if (array_key_exists('favorites', $profile)) {
        if (!djv_is_object($profile['favorites'])) {
            $errors[] = 'favorites must be an object';
        } else {
            foreach (['artists', 'albums', 'tracks'] as $field) {
                if (!array_key_exists($field, $profile['favorites'])) {
                    continue;
                }
                $items = $profile['favorites'][$field];
                if (!is_array($items) || !array_is_list($items)) {
                    $errors[] = "favorites.$field must be an array of strings";
                    continue;
                }
                foreach ($items as $index => $item) {
                    if (!is_string($item) || trim($item) === '') {
                        $errors[] = "favorites.{$field}[{$index}] must be a non-empty string";
                    }
                }
            }
            foreach (array_keys($profile['favorites']) as $key) {
                if (!in_array($key, ['artists', 'albums', 'tracks'], true)) {
                    $warnings[] = "unknown favorites field: $key";
                }
            }
        }
    }

    return [$errors, $warnings];
}

$args = array_slice($argv, 1);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    djv_usage();
    exit(0);
}

if ($args) {
    $files = [];
    foreach ($args as $arg) {
        if (str_starts_with($arg, '-')) {
            fwrite(STDERR, "Unknown option: $arg\n");
            djv_usage();
            exit(2);
        }
        $candidate = str_starts_with($arg, '/') ? $arg : $root . '/' . ltrim($arg, '/');
        $files[] = $candidate;
    }
} else {
    $files = glob($profileDir . '/*.json') ?: [];
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
}

if (!$files) {
    echo "No DJ profile JSON files found.\n";
    exit(0);
}

$errorCount = 0;
$warningCount = 0;
$validCount = 0;

foreach ($files as $file) {
    $display = djv_relative($file, $root);
    if (!is_file($file)) {
        echo "ERROR $display\n  - file does not exist\n";
        $errorCount++;
        continue;
    }

    [$errors, $warnings] = djv_validate_profile($file, $root, $maxBytes);
    if ($errors) {
        echo "ERROR $display\n";
        foreach ($errors as $error) {
            echo "  - $error\n";
        }
        $errorCount += count($errors);
    } else {
        echo "OK    $display\n";
        $validCount++;
    }
    foreach ($warnings as $warning) {
        echo "  WARN: $warning\n";
        $warningCount++;
    }
}

printf(
    "\n%d valid file%s, %d error%s, %d warning%s\n",
    $validCount,
    $validCount === 1 ? '' : 's',
    $errorCount,
    $errorCount === 1 ? '' : 's',
    $warningCount,
    $warningCount === 1 ? '' : 's'
);

exit($errorCount > 0 ? 1 : 0);
