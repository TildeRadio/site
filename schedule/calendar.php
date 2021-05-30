<section class="calendar-wrapper">
<?php
function check_in_range($start_date, $end_date, $checkdate) {
  $start_ts = strtotime($start_date);
  $end_ts = strtotime($end_date);
  $ch_ts = strtotime($checkdate);
  return (($ch_ts >= $start_ts) && ($ch_ts < $end_ts));
}

// Create a date range between the schedule start and end dates
$begin = new DateTime($schedule[0]['start']);
$end = new DateTime(end($schedule)['start']);
$daterange = new DatePeriod($begin, new DateInterval('P1D'), $end);
?>

<table class="calendar">
  <thead>
    <tr>
      <th></th>
<?php
// Loop over our date range to draw the headers
foreach($daterange as $date){
?>
    <th>
      <span class="day"><?php echo $date->format("d") ?></span>
      <span class="long"><?php echo $date->format("l") ?></span>
      <span class="short"><?php echo $date->format("D") ?></span>
    </th>
<?php
}
?>
    </tr>
  </thead>
  <tbody>
<?php
// time will count us by 30-min increments through the day
$time = mktime(0, 0, 0, 1, 1);
// Loop over the day in 30 min increments
for ($i = 0; $i < 86400; $i += 1800) {
?>
    <tr>
<?php
  // Only show row if we're on a full hour block. It's a rowspan 2
  if ((($i / 1800) % 2) === 0 ) {
?>
      <td class="hour" rowspan="2"><span><?php echo date('H', $time + $i) ?>:00</span></td>
<?php
  }
?>

<?php
  $now = new DateTime();
  $halfhour = new DateInterval('PT30M');
  $wrotepointer = false;
  // Loop over each day of the week for this 30 min span
  foreach($daterange as $date){
    // merge date (changing days) and time (incrementing by 30 min) to draw calendar by row.
    $merge = new DateTime($date->format('Y-m-d') .' ' .date('H:i:s', $time + $i));
    // Set id for this time span, for referencing in JS.
    $props = 'id="show-'.$merge->getTimestamp().'"';
    // We'll now use $merge to see if any shows are airing at this time
    $matchedshow = null;
    foreach ($schedule as $show) {
      if (check_in_range($show['start'], $show['end'], $merge->format('Y-m-d H:i:s'))) {
        $matchedshow = $show;
        break;
      }
    }
    echo "<td $props>";
    if ($matchedshow) {
        echo '<div class="show-title">'.$matchedshow['title'].'</div>';
        // if no match was found, leave an empty node
    }
    if (!$wrotepointer) {
        // If current time is in this range, draw pointer.
        $end = DateTimeImmutable::createFromMutable($merge)->add($halfhour);
        if ($now >= $merge && $now < $end) {
            // Cell height here should be synced with height of '.calendar tbody tr td' in ../css/calendar.css
            $height = 32;
            $top = round(date_diff($merge, $now)->format('%i') / 30 * ($height-1));
            echo '<div id="pointer" style="top:'.$top.'px"></div>';
        }
        $wrotepointer = true;
    }
    echo "</td>\n";
  }
?>
    </tr>
<?php
}
?>
  </tbody>
</table>
</section>
