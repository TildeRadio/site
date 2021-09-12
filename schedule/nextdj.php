<?php
include 'apikey.php';

if (empty($apikey)) {
  /* If we don't have the API key, assume we're developing. Pull data from live version of this file */
  $context = stream_context_create([
      "http" => [
          "method" => "GET",
      ]
  ]);
  $schedule = json_decode(file_get_contents("https://tilderadio.org/schedule/nextdj.php?json=true", false, $context), true);
} else {
  $context = stream_context_create([
      "http" => [
          "method" => "GET",
          "header" => "X-API-Key: $apikey\r\n"
      ]
  ]);

  $schedule = json_decode(
      file_get_contents(
          "https://azuracast.tilderadio.org/api/station/1/schedule?rows=2",
          false,
          $context
      ),
      true
  );
}

if (isset($_GET["json"]) && $_GET["json"] === "yes") {
    echo json_encode($schedule);
} else {
    $data = $schedule[0];
    if ((strtotime($data["start"])-strtotime("now"))<0) {
	echo $data["name"]." should be streaming now, and ";
        $data = $schedule[1];
    }
    echo $data["name"]." will stream at ".gmdate("D M d H:i",strtotime($data["start"]))." UTC (in ";
    $diff = strtotime($data["start"])-strtotime("now");
    if ($diff<60) {
        echo "".$diff." seconds)";
    } else {
        $minutes = intdiv($diff,60);
        $seconds = $diff % 60;
        if ($minutes<60) {
            echo "".$minutes." minutes and ".$seconds." seconds)";
        } else {
            $hours = intdiv($minutes,60);
            $minutes = $minutes % 60;
            echo "".$hours." hours, ".$minutes." minutes and ".$seconds." seconds)";
        }
    }
    echo ".";
}
