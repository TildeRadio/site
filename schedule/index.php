<?php
$additional_head='<link rel="alternate" type="text/calendar" href="https://tilderadio.org/schedule/ics.php">';
include '../header.php';
include 'schedule.php';
?>

<h1><a href="https://tilderadio.org"><img style="width:72px;margin-top:-30px;margin-right:5px;" src="../logos/tilderadio.png">tilderadio.org</a></h1>
<h4>upcoming broadcasts</h4>
<p>all times in UTC. current time is <span id="utcdate"><?=formatdate("now")?></span>.</p>
<p>this schedule is also available in <a href="https://icalendar.org/validator.html?url=https://tilderadio.org/schedule/ics.php">iCalendar format</a>. point your calendar client at <code>https://tilderadio.org/schedule/ics.php</code>.</p>

<?php
include 'calendar.php';
?>

<table class="table table-striped">
    <thead>
        <tr>
            <th>dj</th>
            <th>start</th>
            <th>end</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($schedule as $item): ?>
        <tr>
            <td><?=$item["title"]?></td>
            <td><?=formatdate($item["start"])?></td>
            <td><?=formatdate($item["end"])?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script>
// Update the timer as time passes.
// Because PHP gives the time on page load, people who disable Javascript won't be missing out on much.
let months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
let daysOfWeek = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];
function update_date() {
    var d = new Date();
    document.getElementById("utcdate").innerText = daysOfWeek[d.getUTCDay()]+" "+months[d.getUTCMonth()]+" "+d.getUTCDate().toString()+" "+d.getUTCHours().toString().padStart(2,'0')+":"+d.getUTCMinutes().toString().padStart(2,'0');
    setTimeout(update_date,15000);
}
setTimeout(update_date,15000);
</script>

<?php include '../footer.php'; ?>
