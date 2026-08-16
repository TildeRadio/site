<?php
// Put at the very top of header.php (before any <link> tags)
if (!isset($__WEB_BASE)) {
    $norm = static function (?string $p): string {
        $r = $p ? realpath($p) : '';
        return $r ? str_replace('\\', '/', rtrim($r, '/')) : '';
    };

    $doc_fs     = $norm($_SERVER['DOCUMENT_ROOT'] ?? null);         // filesystem docroot
    $header_fs  = $norm(__DIR__);                                   // filesystem path of header.php dir
    $script_fs  = $norm($_SERVER['SCRIPT_FILENAME'] ?? null);       // filesystem path of executing script
    $script_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')); // URL path of executing script dir
    if ($script_url === '\\' || $script_url === '.') { $script_url = '/'; }

    $base = ''; // final URL base (e.g. '', '/tilderadio')

    // Primary: map header dir from filesystem to URL using DOCUMENT_ROOT
    if ($doc_fs && $header_fs && strpos($header_fs, $doc_fs) === 0) {
        $base = substr($header_fs, strlen($doc_fs));                // e.g. '/tilderadio'
    } else {
        // Fallback: trim the script URL by the filesystem diff between script dir and header dir
        $script_dir_fs = $norm(dirname($_SERVER['SCRIPT_FILENAME'] ?? '') ?: null);
        if ($script_dir_fs && $header_fs && strpos($script_dir_fs, $header_fs) === 0) {
            // header is a parent of the script (common case: /tilderadio vs /tilderadio/schedule)
            $suffix_fs = substr($script_dir_fs, strlen($header_fs));            // e.g. '/schedule'
            $base = rtrim(substr($script_url, 0, max(1, strlen($script_url) - strlen($suffix_fs))), '/');
            if ($base === '') { $base = '/'; }
        } elseif ($header_fs && $script_dir_fs && strpos($header_fs, $script_dir_fs) === 0) {
            // header is below the script directory (rarer)
            $extra_fs = substr($header_fs, strlen($script_dir_fs));             // e.g. '/assets/inc'
            $base = rtrim($script_url, '/') . $extra_fs;
        } else {
            // Last resort: use the script dir as base
            $base = $script_url ?: '/';
        }
    }

    // Normalize to '' or '/something'
    $base = '/' . ltrim($base, '/');
    if ($base === '/') $base = '';

    $__WEB_BASE = $base;

    function asset(string $path): string {
        // Returns '/subdir/…' when the site is under a subfolder, otherwise '/…'
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
        <link rel="icon" type="image/png" href="logos/tilderadio.png">
        <?=isset($additional_head) ? PHP_EOL . "        " . $additional_head . PHP_EOL : ""?>
    </head>

    <body>
        <div class="container">
            <nav class="navbar navbar-default navbar-fixed-top">
                <div class="container">
                    <div class="navbar-header">
                        <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar" aria-expanded="false" aria-controls="navbar">
                            <span class="sr-only">Toggle navigation</span>
                            <span class="icon-bar"> </span>
                            <span class="icon-bar"> </span>
                            <span class="icon-bar"> </span>
                        </button>
                    </div>
                    <div id="navbar" class="navbar-collapse collapse">
                        <ul class="nav navbar-nav navbar-right">
                            <li><a href="<?= htmlspecialchars(asset('/'), ENT_QUOTES, 'UTF-8') ?>">home</a></li>
                            <li><a href="<?= htmlspecialchars(asset('schedule/'), ENT_QUOTES, 'UTF-8') ?>">schedule</a></li>
                            <li><a href="<?= htmlspecialchars(asset('listen/'), ENT_QUOTES, 'UTF-8') ?>">listen now</a></li>
                        </ul>

                            <!--/.nav-collapse -->
                    </div>
                </div>
            </nav>
        </div>
        <br>
        <br>
        <div class="container">
            <h1>
                <a href="/"><img style="width:72px;margin-top:-30px;margin-right:5px;" src="logos/tilderadio.png" alt="">tilderadio.org</a>
            </h1>
            <hr>
