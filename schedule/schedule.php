<?php
// schedule/schedule.php
// cURL-only version (no file_get_contents / fopen). Keeps behavior and output intact.

// ------------------------------- Optional API key -----------------------------
$apikey = null;
$apikey_file = __DIR__ . '/apikey.php';
if (is_file($apikey_file)) {
    // apikey.php should define $apikey = '...';
    include $apikey_file;
}
if (!isset($apikey) || !is_string($apikey) || $apikey === '') {
    $apikey = null;
}

// ------------------------------- HTTP helper ---------------------------------
/**
 * Fetch JSON via cURL and return decoded array or null.
 * No fallback to file_get_contents (allow_url_fopen can be disabled).
 */
function http_get_json(string $url, array $headers = [], int $timeout = 8): ?array
{
    if (!function_exists('curl_init')) {
        return null; // cURL required; no fopen fallback by request.
    }

    $ch = curl_init($url);
    $hdrs = [];
    foreach ($headers as $h) {
        if (is_string($h)) {
            $hdrs[] = $h;
        } elseif (is_array($h)) {
            foreach ($h as $k => $v) {
                $hdrs[] = $k . ': ' . $v;
            }
        }
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'tilderadio-schedule/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $hdrs,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

// ------------------------------- Fetch data ----------------------------------
// allow ics.php (or other includers) to predefine $from and $to; otherwise default.
if (!isset($from, $to)) {
    $from = gmdate("Y-m-d\\TH:i:s\\Z", strtotime("today"));
    $to   = gmdate("Y-m-d\\TH:i:s\\Z", strtotime("today + 8 days"));
}

if ($apikey === null) {
    // Development / public mode: mirror live schedule JSON (same as original behavior)
    $schedule = http_get_json('https://tilderadio.org/schedule/schedule.php?json=yes') ?? [];
} else {
    // Direct AzuraCast with API key
    $url = 'https://azuracast.tilderadio.org/api/station/1/streamers/schedule'
         . '?start=' . rawurlencode($from)
         . '&end='   . rawurlencode($to);
    $schedule = http_get_json($url, ['X-API-Key: ' . $apikey]) ?? [];
}

// ------------------------------- Sort & Output --------------------------------
usort($schedule, function ($a, $b) {
    // Original compared the raw "start" field; keep behavior.
    return ($a["start"] ?? '') <=> ($b["start"] ?? '');
});

if (isset($_GET["json"]) && $_GET["json"] === "yes") {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($schedule, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Utility preserved from original
function formatdate($date) {
    return gmdate("D M d H:i", strtotime($date));
}
