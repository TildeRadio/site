<?php
include '../header.php';
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
?>

<h1><a href="https://tilderadio.org"><img style="width:75px;" src="../logos/tilderadio-green.png">tilderadio.org</a></h1>
<h4>upcoming broadcasts</h4>
<p>all times in UTC. current time is <span id="utcdate"><?=formatdate("now")?></span>.</p>

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
    document.getElementById("utcdate").innerText = daysOfWeek[d.getUTCDay()]+" "+months[d.getUTCMonth()]+" "+d.getUTCDate().toString()+" "+d.getUTCHours().toString()+":"+d.getUTCMinutes().toString();
    setTimeout(update_date,15000);
}
setTimeout(update_date,15000);
</script>

<?php include '../footer.php'; ?>
