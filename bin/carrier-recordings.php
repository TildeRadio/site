<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TildeRadio\Site\Admin\RecordingDiscovery;
use TildeRadio\Site\Admin\Store;
use TildeRadio\Site\DjAuth\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    umask(0077);
    $config = Config::load();
    $store = new Store($config, new NullLogger(), dirname(__DIR__));
    $count = (new RecordingDiscovery($config, $store))->run();
    fwrite(STDOUT, $count . " recording candidates discovered. Existing upstream recordings were preserved.\n");
} catch (Throwable) {
    fwrite(STDERR, "Recording discovery failed. Check private configuration, AzuraCast recording permissions, disk space and the Carrier connection.\n");
    exit(1);
}
