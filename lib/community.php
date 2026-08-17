<?php

declare(strict_types=1);

const TR_COMMUNITY_AUDIO_JSON_MAX_BYTES = 32768;

/**
 * Return true when the URL is safe to render as an external HTTP(S) link.
 */
function tr_community_http_url(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

/**
 * Load one JSON file per community station ID / jingle.
 *
 * Metadata lives under data/community/audio/*.json while the corresponding
 * audio files live under community/audio/. Invalid entries are logged and
 * skipped so one bad submission cannot break the community page.
 *
 * @return array<int, array{
 *     id: string,
 *     title: string,
 *     by: ?string,
 *     description: ?string,
 *     file: string,
 *     license: ?string,
 *     url: ?string
 * }>
 */
function tr_community_audio_items(): array
{
    $root = dirname(__DIR__);
    $metadataDirectory = $root . '/data/community/audio';
    $audioDirectory = $root . '/community/audio';

    if (!is_dir($metadataDirectory)) {
        return [];
    }

    $files = glob($metadataDirectory . '/*.json');
    if ($files === false) {
        return [];
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    $items = [];

    foreach ($files as $path) {
        $filename = basename($path);

        if (!preg_match('/^([a-z0-9][a-z0-9-]*)\.json$/', $filename, $matches)) {
            error_log('tilderadio: skipping community audio metadata with invalid filename: ' . $filename);
            continue;
        }

        $size = filesize($path);
        if ($size === false || $size > TR_COMMUNITY_AUDIO_JSON_MAX_BYTES) {
            error_log('tilderadio: skipping oversized community audio metadata: ' . $filename);
            continue;
        }

        $json = file_get_contents($path);
        if (!is_string($json)) {
            error_log('tilderadio: unable to read community audio metadata: ' . $filename);
            continue;
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            error_log(
                'tilderadio: invalid community audio JSON in '
                . $filename
                . ': '
                . $exception->getMessage()
            );
            continue;
        }

        if (!is_array($data) || array_is_list($data)) {
            error_log('tilderadio: community audio metadata must be a JSON object: ' . $filename);
            continue;
        }

        if (array_key_exists('published', $data) && $data['published'] === false) {
            continue;
        }

        $audioFilename = trim((string) ($data['file'] ?? ''));
        if (
            $audioFilename === ''
            || $audioFilename !== basename($audioFilename)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(ogg|oga|opus|mp3|wav|flac)$/i', $audioFilename)
        ) {
            error_log('tilderadio: invalid community audio filename in ' . $filename);
            continue;
        }

        if (!is_file($audioDirectory . '/' . $audioFilename)) {
            error_log(
                'tilderadio: community audio file does not exist for '
                . $filename
                . ': '
                . $audioFilename
            );
            continue;
        }

        $id = $matches[1];
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = str_replace('-', ' ', $id);
        }

        $by = trim((string) ($data['by'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $license = trim((string) ($data['license'] ?? ''));
        $url = trim((string) ($data['url'] ?? ''));

        $items[] = [
            'id' => $id,
            'title' => $title,
            'by' => $by !== '' ? $by : null,
            'description' => $description !== '' ? $description : null,
            'file' => 'community/audio/' . $audioFilename,
            'license' => $license !== '' ? $license : null,
            'url' => $url !== '' && tr_community_http_url($url) ? $url : null,
        ];
    }

    usort(
        $items,
        static fn (array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title'])
    );

    return $items;
}
