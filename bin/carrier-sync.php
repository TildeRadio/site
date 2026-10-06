<?php

declare(strict_types=1);

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use TildeRadio\Site\Admin\CarrierClient;
use TildeRadio\Site\Admin\CarrierSync;
use TildeRadio\Site\Admin\Store;
use TildeRadio\Site\DjAuth\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $config = Config::load();
    umask(0077);
    $handler = new RotatingFileHandler($config->stateDir() . '/carrier.log', 7, Logger::INFO);
    $handler->setFormatter(new JsonFormatter());
    $logger = new Logger('carrier-sync', [$handler]);
    $store = new Store($config, $logger, dirname(__DIR__));
    $option = $argv[1] ?? '--sync';
    if ($option === '--migrate') {
        fwrite(STDOUT, "Additive website schema migration complete.\n");
        exit;
    }
    if ($option === '--check') {
        $health = (new CarrierClient($config))->call(['op' => 'health']);
        fwrite(STDOUT, json_encode($health, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        exit;
    }
    if ($option !== '--sync') {
        throw new RuntimeException('Invalid command.');
    }
    $result = (new CarrierSync($config, $store))->run();
    $logger->info('Carrier synchronized', ['records' => $result['records'] ?? 0]);
    fwrite(STDOUT, "Carrier synchronized successfully.\n");
} catch (Throwable) {
    if (isset($logger)) {
        $logger->error('Carrier synchronization failed');
    }
    fwrite(STDERR, "Carrier operation failed. Check the service, socket permissions and private configuration.\n");
    exit(1);
}
