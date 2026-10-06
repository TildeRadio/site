<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\RecordingFile;

require __DIR__ . '/_carrier.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    tr_dj_error(405, 'This page accepts GET requests only.');
}
$id = $_GET['id'] ?? '';
if (!is_string($id) || !preg_match('/\A[a-f0-9]{64}\z/D', $id)) {
    tr_dj_error(404, 'Recording not found.');
}
$url = $djConfig->origin() . $djConfig->publicPath('recordings/?id=' . $id);
$allowed = false;
foreach ($djStore->recordingCandidates($adminIdentity) as $candidate) {
    if ($candidate['url'] === $url) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    tr_dj_error(403, 'This recording is not assigned to your account or was already reviewed.');
}
$recording = RecordingFile::approved($id, true);
if ($recording === null) {
    tr_dj_error(404, 'Recording file unavailable.');
}
// Release the session while a potentially long audio response is streamed.
session_write_close();
RecordingFile::send($recording);
