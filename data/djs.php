<?php

declare(strict_types=1);

/*
 * Compatibility loader for the DJ catalog.
 *
 * Profile content lives in data/djs/<slug>.json. The filename is the canonical
 * profile slug, which keeps schedule/profile URLs predictable and makes DJ
 * updates one-file changes.
 */
$profiles = [];
$directory = __DIR__ . '/djs';
$files = is_dir($directory) ? glob($directory . '/*.json') : false;

if (is_array($files)) {
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($files as $file) {
        $slug = pathinfo($file, PATHINFO_FILENAME);
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            error_log('tilderadio: skipping invalid DJ profile filename: ' . basename($file));
            continue;
        }

        $size = filesize($file);
        if ($size === false || $size > 65536) {
            error_log('tilderadio: skipping oversized/unreadable DJ profile: ' . basename($file));
            continue;
        }

        $json = file_get_contents($file);
        if (!is_string($json)) {
            error_log('tilderadio: unable to read DJ profile: ' . basename($file));
            continue;
        }

        try {
            $profile = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            error_log(
                'tilderadio: invalid JSON in DJ profile ' . basename($file) . ': ' . $exception->getMessage()
            );
            continue;
        }

        if (!is_array($profile) || array_is_list($profile)) {
            error_log('tilderadio: DJ profile must be a JSON object: ' . basename($file));
            continue;
        }

        if (($profile['published'] ?? true) === false) {
            continue;
        }

        $profile['slug'] = $slug;
        $name = isset($profile['name']) && is_string($profile['name'])
            ? trim($profile['name'])
            : '';
        $profile['name'] = $name !== '' ? $name : $slug;

        // Keep the existing catalog/API summary field useful while allowing
        // richer profile JSON to use "tagline" and "bio" instead.
        if (empty($profile['description'])) {
            if (isset($profile['tagline']) && is_string($profile['tagline'])) {
                $profile['description'] = trim($profile['tagline']);
            } elseif (isset($profile['bio']) && is_string($profile['bio'])) {
                $profile['description'] = trim($profile['bio']);
            } elseif (isset($profile['bio'][0]) && is_string($profile['bio'][0])) {
                $profile['description'] = trim($profile['bio'][0]);
            }
        }

        $profiles[$slug] = $profile;
    }
}

return $profiles;
