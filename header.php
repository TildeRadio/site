<?php
if (!isset($__WEB_BASE)) {
    $norm = static function (?string $p): string {
        $r = $p ? realpath($p) : '';
        return $r ? str_replace('\\', '/', rtrim($r, '/')) : '';
    };

    $doc_fs     = $norm($_SERVER['DOCUMENT_ROOT'] ?? null);
    $header_fs  = $norm(__DIR__);
    $script_fs  = $norm($_SERVER['SCRIPT_FILENAME'] ?? null);
    $script_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if ($script_url === '\\' || $script_url === '.') { $script_url = '/'; }

    $base = '';

    if ($doc_fs && $header_fs && strpos($header_fs, $doc_fs) === 0) {
        $base = substr($header_fs, strlen($doc_fs));
    } else {
        $script_dir_fs = $norm(dirname($_SERVER['SCRIPT_FILENAME'] ?? '') ?: null);
        if ($script_dir_fs && $header_fs && strpos($script_dir_fs, $header_fs) === 0) {
            $suffix_fs = substr($script_dir_fs, strlen($header_fs));
            $base = rtrim(substr($script_url, 0, max(1, strlen($script_url) - strlen($suffix_fs))), '/');
            if ($base === '') { $base = '/'; }
        } elseif ($header_fs && $script_dir_fs && strpos($header_fs, $script_dir_fs) === 0) {
            $extra_fs = substr($header_fs, strlen($script_dir_fs));
            $base = rtrim($script_url, '/') . $extra_fs;
        } else {
            $base = $script_url ?: '/';
        }
    }

    $base = '/' . ltrim($base, '/');
    if ($base === '/') $base = '';

    $__WEB_BASE = $base;

    function asset(string $path): string {
        global $__WEB_BASE;
        return ($__WEB_BASE === '' ? '' : $__WEB_BASE) . '/' . ltrim($path, '/');
    }
}
?>

<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>tilderadio
            <?=isset($title) ? " | $title" : "" ?>
        </title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="<?= htmlspecialchars(asset('css/hacker.css'), ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach (($page_stylesheets ?? []) as $stylesheet): ?>
            <?php if (is_string($stylesheet) && trim($stylesheet) !== ''): ?>
                <link rel="stylesheet" href="<?= htmlspecialchars(asset($stylesheet), ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <link rel="icon" type="image/png" href="<?= htmlspecialchars(asset('logos/tilderadio.png'), ENT_QUOTES, 'UTF-8') ?>">
        <?=isset($additional_head) ? PHP_EOL . "        " . $additional_head . PHP_EOL : ""?>
    </head>

    <body>
        <div class="site-shell">
            <header class="site-header">
                <a class="site-mark" href="<?= htmlspecialchars(asset('/'), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= htmlspecialchars(asset('logos/tilderadio.png'), ENT_QUOTES, 'UTF-8') ?>" alt="" width="36" height="36">
                    <span>tilderadio.org</span>
                </a>
                <nav class="site-nav" aria-label="primary">
                    <a href="<?= htmlspecialchars(asset('/'), ENT_QUOTES, 'UTF-8') ?>">home</a>
                    <a href="<?= htmlspecialchars(asset('schedule/'), ENT_QUOTES, 'UTF-8') ?>">schedule</a>
                    <a href="<?= htmlspecialchars(asset('djs/'), ENT_QUOTES, 'UTF-8') ?>">djs</a>
                    <a href="<?= htmlspecialchars(asset('community/'), ENT_QUOTES, 'UTF-8') ?>">community</a>
                    <a href="<?= htmlspecialchars(asset('listen/'), ENT_QUOTES, 'UTF-8') ?>">listen</a>
                </nav>
            </header>
            <main>
