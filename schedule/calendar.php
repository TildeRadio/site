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
  // Loop over each day of the week for this 30 min span
  foreach($daterange as $date){
    // merge date (changing days) and time (incrementing by 30 min) to draw calendar by row.
    $merge = new DateTime($date->format('Y-m-d') .' ' .date('H:i:s', $time + $i));
    // We'll now use $merge to see if any shows are airing at this time
    $match = false;
    foreach ($schedule as $show) {
      if (check_in_range($show['start'], $show['end'], $merge->format('Y-m-d H:i:s'))) {
        echo "<td>" . $show['title'] . "</td>\n";
        $match = true;
        break;
      }
    }
    // if no match was found, leave an empty node
    if (! $match) {
      echo "<td></td>\n";
    }
  }
?>
    </tr>
<?php
}
?>
  </tbody>
</table>
</section>
