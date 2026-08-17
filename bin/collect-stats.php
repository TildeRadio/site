#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/radio.php';
require_once dirname(__DIR__) . '/lib/stats.php';

$options = getopt('', ['init', 'storage:', 'quiet', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "usage: php bin/collect-stats.php [--init] [--storage=/path] [--quiet]\n");
    exit(0);
}

$storage = isset($options['storage']) && is_string($options['storage'])
    ? $options['storage']
    : null;
$directory = tr_stats_storage_dir($storage);
$quiet = isset($options['quiet']);

if (isset($options['init'])) {
    if (!tr_stats_ensure_dir($directory)) {
        fwrite(STDERR, "unable to create or write stats directory: {$directory}\n");
        exit(1);
    }

    if (!$quiet) {
        fwrite(STDOUT, "stats storage ready: {$directory}\n");
    }
    exit(0);
}

$now = tr_now_playing();
if (empty($now['available'])) {
    fwrite(STDERR, "now playing data is unavailable; no sample recorded\n");
    exit(2);
}

$result = tr_stats_record_sample([
    'ts' => time(),
    'listeners' => is_int($now['listeners'] ?? null) ? $now['listeners'] : null,
    'is_live' => !empty($now['is_live']),
    'dj' => is_string($now['dj'] ?? null) ? $now['dj'] : null,
], $storage);

if (empty($result['ok'])) {
    fwrite(STDERR, (string) ($result['error'] ?? 'unable to record stats sample') . "\n");
    exit(1);
}

if (!$quiet) {
    $sample = $result['sample'];
    $listeners = is_int($sample['listeners']) ? (string) $sample['listeners'] : 'n/a';
    $mode = !empty($sample['is_live']) ? 'live: ' . ($sample['dj'] ?? 'unknown DJ') : 'AutoDJ';
    fwrite(STDOUT, gmdate(DATE_ATOM, $sample['ts']) . " listeners={$listeners} mode={$mode}\n");
}
