<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [$root . '/header.php', $root . '/lib/radio.php', $root . '/episodes/index.php', $root . '/schedule/index.php', $root . '/bin/backup-dj-admin.php', __FILE__];
foreach (['dj', 'lib/DjAuth', 'lib/Admin', 'tests/DjAuth', 'tests/Admin', 'tests/fixtures'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
foreach ($files as $file) {
    // Carry the current PHP CLI's ini to child lint processes.
    $args = [PHP_BINARY];
    if (php_ini_loaded_file() !== false) {
        array_push($args, '-c', php_ini_loaded_file());
    }
    array_push($args, '-l', $file);
    $process = proc_open($args, [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) {
        exit(1);
    }
}
