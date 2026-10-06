<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TildeRadio\Site\Admin\Store;
use TildeRadio\Site\DjAuth\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
try {
    if ($argc !== 3 || !preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/D', $argv[1], $identity)) {
        throw new RuntimeException('Invalid arguments.');
    }
    require dirname(__DIR__) . '/vendor/autoload.php';
    $manifest = $argv[2];
    $path = realpath($manifest);
    $root = realpath(dirname(__DIR__));
    if ($path === false || !str_starts_with($manifest, '/') || !is_file($manifest) || is_link($manifest)
        || !is_readable($manifest) || filesize($manifest) > 1048576 || ($path === $root || str_starts_with($path, $root . '/'))) {
        throw new RuntimeException('Invalid private manifest.');
    }
    $items = json_decode((string) file_get_contents($manifest), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($items) || !array_is_list($items)) {
        throw new RuntimeException('Invalid manifest.');
    }
    $store = new Store(Config::load(), new NullLogger(), dirname(__DIR__));
    $store->importRecordings(['station_id' => (int) $identity[1], 'streamer_id' => (int) $identity[2]], $items);
    fwrite(STDOUT, "Recordings imported for DJ / administrator review. Re-importing the same links does not duplicate or reopen candidates.\n");
} catch (Throwable) {
    fwrite(STDERR, "Import failed. Usage: php bin/import-recordings.php 1:163 /private/recordings.json\nCheck the manifest, broadcast IDs and administrator permissions.\n");
    exit(1);
}
