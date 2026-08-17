<?php
require_once dirname(__DIR__) . '/lib/api.php';

if (tr_terminal_request()) {
    require_once dirname(__DIR__) . '/lib/radio.php';

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=60');

    $now = time();
    $events = array_values(array_filter(
        tr_schedule(14),
        static fn (array $event): bool => !is_int($event['end_ts'] ?? null) || $event['end_ts'] >= $now
    ));

    $lines = ['~ tilderadio schedule ~', ''];
    foreach (array_slice($events, 0, 50) as $event) {
        if (!is_int($event['start_ts'] ?? null)) {
            continue;
        }
        $name = trim((string) ($event['name'] ?? 'unknown'));
        $end = is_int($event['end_ts'] ?? null)
            ? ' - ' . gmdate('H:i', $event['end_ts'])
            : '';
        $lines[] = sprintf(
            '%s %-18s %s%s UTC',
            !empty($event['is_live']) ? '*' : ' ',
            $name,
            gmdate('D M d H:i', $event['start_ts']),
            $end
        );
    }
    if (count($lines) === 2) {
        $lines[] = 'no upcoming shows are currently listed.';
    }
    $lines[] = '';
    $lines[] = '* currently on air';
    $lines[] = 'calendar: https://tilderadio.org/schedule/';
    $lines[] = 'ical:     https://tilderadio.org/schedule/ics.php';
    $lines[] = 'api:      https://tilderadio.org/api/schedule/';
    echo implode("\n", $lines) . "\n";
    exit;
}

$additional_head='<link rel="stylesheet" href="../css/calendar.css">
<link rel="alternate" type="text/calendar" href="https://tilderadio.org/schedule/ics.php">';
include '../header.php';
include 'schedule.php';
?>

<p>
    all times in UTC. current time is <span id="utcdate"><?=formatdate("now")?></span>
    <span class="pointer-label-wrapper">(</span><span class="pointer-label">&mdash;</span><span class="pointer-label-wrapper">)</span>.
</p>

<p>
    this schedule is also available in <a href="https://icalendar.org/validator.html?url=https://tilderadio.org/schedule/ics.php">iCalendar format</a>.
    point your calendar client at <code>https://tilderadio.org/schedule/ics.php</code>.
</p>

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
        if (!range) continue;
        const rangeStart = range.startTime;
        if (rangeStart <= now && (rangeStart + halfHour) > now) {
            return range;
        }
    }
}

// Update the timer and pointer as time passes.
// Because PHP gives the time on page load, people who disable Javascript won't be missing out on much.
let months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
let daysOfWeek = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

function update_date() {
    var d = new Date();
    document.getElementById("utcdate").innerText = 
        daysOfWeek[d.getUTCDay()] + " " + months[d.getUTCMonth()] + " " +
        d.getUTCDate().toString() + " " + d.getUTCHours().toString().padStart(2,'0') +
        ":" + d.getUTCMinutes().toString().padStart(2,'0');
    setTimeout(update_date, 15000);
    updatePointer(d);
}
setTimeout(update_date, 15000);

let pointer = document.getElementById("pointer");
// Create pointer in case it wasn't added in the page generation.
if (!pointer) {
    pointer = document.createElement('div');
    pointer.id = 'pointer';
}

let prevRange = getCurrentRange(new Date().getTime());
function updatePointer(d) {
    // Find current cell
    const range = getCurrentRange(d.getTime());
    if (!range) {
        // Done with schedule. Remove pointer. Need to refresh.
        if (prevRange) {
            prevRange.cell.removeChild(pointer);
            prevRange.cell.classList.remove('active');
            prevRange = null;
        }
        return;
    }
    // Move pointer to different cell if changed cell.
    if (range !== prevRange) {
        if (prevRange) prevRange.cell.classList.remove('active');
        range.cell.classList.add('active');
        range.cell.appendChild(pointer);
        prevRange = range;
    }
    // Move pointer based on time in current cell.
    const progress = (d.getTime() - range.startTime) / halfHour;
    pointer.style.top = (progress * (range.cell.offsetHeight - 1)).toFixed(0) + 'px';
}
// Update pointer immediately
updatePointer(new Date());
</script>

<?php include '../footer.php'; ?>
