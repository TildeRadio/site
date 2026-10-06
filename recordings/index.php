<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Admin/PublicProfiles.php';
require_once dirname(__DIR__) . '/lib/Admin/RecordingFile.php';
try {
    $id = $_GET['id'] ?? '';
    $recording = is_string($id) ? \TildeRadio\Site\Admin\RecordingFile::approved($id) : null;
    if ($recording === null) {
        http_response_code(404);
        exit;
    }
    \TildeRadio\Site\Admin\RecordingFile::send($recording);
} catch (Throwable) {
    error_log('{"channel":"dj-recording","level":"error","message":"Approved recording unavailable"}');
    http_response_code(503);
}
