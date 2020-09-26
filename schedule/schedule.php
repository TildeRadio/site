<?php
include 'apikey.php';

if (empty($apikey)) {
  /* If we don't have the API key, assume we're developing. Pull data from live version of this file */
  $context = stream_context_create([
      "http" => [
          "method" => "GET",
      ]
  ]);
  $schedule = json_decode(file_get_contents("https://tilderadio.org/schedule/schedule.php?json=yes", false, $context), true);
} else {
  $context = stream_context_create([
      "http" => [
          "method" => "GET",
          "header" => "X-API-Key: $apikey\r\n"
      ]
  ]);

  // allow ics.php to overwrite $from and $to
  if (!isset($from,$to)) {
    $from = gmdate("Y-m-d\TH:i:s\Z", strtotime("now + 1 day"));
    $to   = gmdate("Y-m-d\TH:i:s\Z", strtotime("now + 8 days"));
  }

  $schedule = json_decode(
      file_get_contents(
          "https://radio.tildeverse.org/api/station/1/streamers/schedule?start=$from&end=$to",
          false,
          $context
      ),
      true
  );
}

usort($schedule, function ($a, $b) {
    return $a["start"] <=> $b["start"];
});

if (isset($_GET["json"]) && $_GET["json"] === "yes")
    echo json_encode($schedule);

function formatdate($date) {
    return gmdate("D M d H:i", strtotime($date));
}

