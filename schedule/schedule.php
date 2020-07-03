<?php
include 'apikey.php';

if (empty($apikey)) {
    die("missing api key");
}

$context = stream_context_create([
    "http" => [
        "method" => "GET",
        "header" => "X-API-Key: $apikey\r\n"
    ]
]);

$from = gmdate("Y-m-d\TH:i:s\Z", strtotime("now + 1 day"));
$to   = gmdate("Y-m-d\TH:i:s\Z", strtotime("now + 8 days"));

$schedule = json_decode(
    file_get_contents(
        "https://radio.tildeverse.org/api/station/1/streamers/schedule?start=$from&end=$to",
        false,
        $context
    ),
    true
);

usort($schedule, function ($a, $b) {
    return $a["start"] <=> $b["start"];
});

function formatdate($date) {
    return gmdate("D M d H:i", strtotime($date));
}

