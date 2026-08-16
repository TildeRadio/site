<?php
// schedule/nextdj.php
// Safe version: no warnings if apikey.php missing and no fopen() usage when disabled.

// ----------------------- Config / Optional API key ---------------------------
$apikey = null;
$apikey_file = __DIR__ . '/apikey.php';
if (is_file($apikey_file)) {
    // apikey.php should define $apikey = '...';
    include $apikey_file;
}
if (!isset($apikey) || !is_string($apikey) || $apikey === '') {
    $apikey = null;
}

// ------------------------------- HTTP helpers --------------------------------
/**
 * Fetch a URL and return decoded JSON (array) or null.
 * Uses cURL when available. Falls back to file_get_contents only if allowed.
 */
function http_get_json(string $url, array $headers = [], int $timeout = 6): ?array
{
    // Prefer cURL (works even if allow_url_fopen=0)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $hdrs = [];
        foreach ($headers as $h) {
            // Accept both "Key: Value" strings or ["Key" => "Value"] pairs
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
            CURLOPT_USERAGENT      => 'tilderadio-nextdj/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $hdrs,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    // Fallback only if allow_url_fopen permits it
    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $timeout,
                'header'  => implode("\r\n", array_map(function ($h) {
                    if (is_string($h)) return $h;
                    if (is_array($h)) {
                        $pairs = [];
                        foreach ($h as $k => $v) $pairs[] = $k . ': ' . $v;
                        return implode("\r\n", $pairs);
                    }
                    return '';
                }, $headers)),
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) return null;
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    return null;
}

// ------------------------------- Fetch data ----------------------------------
/*
 * Behavior:
 * - If $apikey is missing, mirror the live "nextdj" endpoint (like original).
 * - If $apikey is present, query AzuraCast directly for next two rows.
 * Both return $schedule in the same expected shape:
 *   $schedule[0]['name'], $schedule[0]['start']
 */
if ($apikey === null) {
    $schedule = http_get_json('https://tilderadio.org/schedule/nextdj.php?json=true');
} else {
    $schedule = http_get_json(
        'https://azuracast.tilderadio.org/api/station/1/schedule?rows=2',
        ['X-API-Key: ' . $apikey]
    );
}

// Ensure $schedule is a list-like array
if (!is_array($schedule)) {
    $schedule = [];
}

// ------------------------------ Output modes ---------------------------------
$wantJson = isset($_GET['json']) && in_array(strtolower((string)$_GET['json']), ['1', 'true', 'yes'], true);

if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($schedule, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------ Text output ----------------------------------
// This file is included inside <em>…</em> on the page, so emit plain text only.

if (empty($schedule) || !isset($schedule[0]) || !is_array($schedule[0])) {
    echo "schedule unavailable";
    exit;
}

$now = time();

// Normalize a row into ['name'=>string,'start'=>int] or null
$norm = function ($row) {
    if (!is_array($row)) return null;
    $name  = isset($row['name'])  ? (string)$row['name']  : (isset($row['title']) ? (string)$row['title'] : '');
    $start = isset($row['start']) ? (int)strtotime((string)$row['start']) : 0;
    if ($name === '' || $start <= 0) return null;
    return ['name' => $name, 'start' => $start];
};

$first = $norm($schedule[0]);
$second = isset($schedule[1]) ? $norm($schedule[1]) : null;

if ($first === null && $second === null) {
    echo "schedule unavailable";
    exit;
}

if ($first !== null && ($first['start'] - $now) < 0) {
    // First should be live now
    if ($second !== null) {
        // Match original phrasing when a subsequent show exists
        echo $first['name'] . " should be streaming now, and ";
        $data = $second;
        $diff = max(0, $data['start'] - $now);
        echo $data['name'] . " will stream at " . gmdate("D M d H:i", $data['start']) . " UTC (in ";
    } else {
        // Graceful: no next show known
        echo $first['name'] . " should be streaming now.";
        exit;
    }
} else {
    // Next up is the first entry
    $data = $first ?? $second; // fallback if first was null
    if ($data === null) {
        echo "schedule unavailable";
        exit;
    }
    $diff = max(0, $data['start'] - $now);
    echo $data['name'] . " will stream at " . gmdate("D M d H:i", $data['start']) . " UTC (in ";
}

// Human-friendly countdown (keeps original style)
if ($diff < 60) {
    echo $diff . " seconds)";
} else {
    $minutes = intdiv($diff, 60);
    $seconds = $diff % 60;
    if ($minutes < 60) {
        echo $minutes . " minutes and " . $seconds . " seconds)";
    } else {
        $hours = intdiv($minutes, 60);
        $minutes = $minutes % 60;
        echo $hours . " hours, " . $minutes . " minutes and " . $seconds . " seconds)";
    }
}
echo ".";
