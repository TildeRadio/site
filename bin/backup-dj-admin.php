<?php

declare(strict_types=1);

use TildeRadio\Site\DjAuth\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 2) {
    fwrite(STDERR, "Usage: php bin/backup-dj-admin.php /private/path/new-backup.sqlite\n");
    exit(1);
}
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $config = Config::load();
    $source = $config->stateDir() . '/admin.sqlite';
    $output = $argv[1];
    $parent = realpath(dirname($output));
    $root = realpath(dirname(__DIR__));
    if (!is_file($source) || $parent === false || !str_starts_with($output, '/')
        || $parent === $root || str_starts_with($parent, $root . '/')
        || !is_writable($parent) || file_exists($output) || is_link($output)) {
        throw new RuntimeException('Invalid backup destination or database unavailable.');
    }
    umask(0077);
    $uri = str_replace('%2F', '/', rawurlencode(realpath($source)));
    $db = new PDO('sqlite:file:' . $uri . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=5000');
    $statement = $db->prepare('VACUUM INTO ?');
    $statement->execute([$output]);
    chmod($output, 0600);
    fwrite(STDOUT, "Administrator database backup created.\n");
} catch (Throwable) {
    fwrite(STDERR, "Backup failed. Check the private database and destination permissions; use a new path outside the website.\n");
    exit(1);
}
