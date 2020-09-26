<section class="calendar-wrapper">
<?php
function check_in_range($start_date, $end_date, $checkdate) {
  $start_ts = strtotime($start_date);
  $end_ts = strtotime($end_date);
  $user_ts = strtotime($checkdate);
  return (($user_ts >= $start_ts) && ($user_ts < $end_ts));
}

$begin = new DateTime($schedule[0]['start']);
$end = new DateTime(end($schedule)['start']);

$daterange = new DatePeriod($begin, new DateInterval('P1D'), $end);

?>
<table class="calendar">
  <thead>
    <tr>
      <th></th>
<?php
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
$time = mktime(0, 0, 0, 1, 1); // time will count us by 30-min increments through the day
for ($i = 0; $i < 86400; $i += 1800) {  // 1800 = half hour, 86400 = one day
?>
    <tr>
<?php
  // Only show row if we're on a full hour block
  if ((($i / 1800) % 2) === 0 ) {
?>
      <td class="hour" rowspan="2"><span><?php echo date('H', $time + $i) ?>:00</span></td>
<?php
  }
?>

<?php
  foreach($daterange as $date){
    // merge date (changing days) and time (incrementing by 30 min) to draw calendar by row.
    $merge = new DateTime($date->format('Y-m-d') .' ' .date('H:i:s', $time + $i));
?>
      <td><?php echo $merge->format('Y-m-d H:i:s'); ?></td>
<?php
  }
?>
    </tr>
<?php
}
?>
  </tbody>
</table>
</section>
