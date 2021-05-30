<?php
$additional_head='<link rel="alternate" type="text/calendar" href="https://tilderadio.org/schedule/ics.php">';
include '../header.php';
include 'schedule.php';
?>

<h1><a href="https://tilderadio.org"><img style="width:72px;margin-top:-30px;margin-right:5px;" src="../logos/tilderadio.png">tilderadio.org</a></h1>
<h4>upcoming broadcasts</h4>
<p>all times in UTC. current time is <span id="utcdate"><?=formatdate("now")?></span> (<span class="pointer-label">&mdash;</span>).</p>
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
let halfHour = 1800000;
// Gather schedule table cells by timestamp
const cells = document.querySelectorAll('.calendar tbody td');
let ranges = [];
for (const cell of Array.from(cells)) {
    const m = /^show-(\d*)$/.exec(cell.id);
    if (!m) continue;
    ranges.push({
        startTime: m[1]*1000,
        cell,
    });
}
ranges.sort((a, b) => {
    return a.startTime - b.startTime;
});
let currentRangeI = 0;

function getCurrentRange(now) {
    for (i = currentRangeI; i < ranges.length; i++) {
        const range = ranges[i];
        if (!range) return;
        const rangeStart = range.startTime;
        if (rangeStart <= now && (rangeStart + halfHour) > now) {
            return range;
        }
    }
}

// Update the timer and pointer as time passes.
// Because PHP gives the time on page load, people who disable Javascript won't be missing out on much.
let months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
let daysOfWeek = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];
function update_date() {
    var d = new Date();
    document.getElementById("utcdate").innerText = daysOfWeek[d.getUTCDay()]+" "+months[d.getUTCMonth()]+" "+d.getUTCDate().toString()+" "+d.getUTCHours().toString().padStart(2,'0')+":"+d.getUTCMinutes().toString().padStart(2,'0');
    setTimeout(update_date,15000);
    updatePointer(d);
}
setTimeout(update_date,15000);

let pointer = document.getElementById("pointer");
// Create pointer in case it wasn't added in the page generation.
if (!pointer) {
    pointer = document.createElement('div');
    pointer.id = 'pointer';
}

let prevRange;
function updatePointer(d) {
    // Find current cell
    const range = getCurrentRange(d.getTime());
    if (!range) {
        // Done with schedule. Remove pointer. Need to refresh.
        if (prevRange) {
            prevRange.cell.removeChild(pointer);
            prevRange = null;
        }
    }
    // Move pointer to different cell if changed cell.
    if (range !== prevRange) {
        range.cell.appendChild(pointer);
    }
    // Move pointer based on time in current cell.
    const progress = (d.getTime() - range.startTime) / halfHour;
    pointer.style.top = (progress * (range.cell.offsetHeight-1)).toFixed(0) + 'px';
}
// Update pointer immediately
updatePointer(new Date());

</script>

<?php include '../footer.php'; ?>
