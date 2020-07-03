<?php
$from = gmdate("Y-m-d\T00:00:00\Z", strtotime(date("w") ? "last sunday" : "sunday"));
$to   = gmdate("Y-m-d\T00:00:00\Z", strtotime("2 days ago",strtotime(date("w") ? "sunday" : "next sunday")));
include 'schedule.php';

function ics_formatdate($date) {
    return gmdate("Ymd\THis\Z", strtotime($date));
}

// ICS generation. Here be dragons.
// I created the file using a Python script and reverse-engineered it to figure this out.

// text/calendar MIME type for ICS files
header("Content-Type: text/calendar");
// It should download as a .ics file
header('Content-Disposition: attachment; filename="tilderadio.ics"');

// the iCalendar Validator throws a hissy fit if lines aren't CRLF terminated
define("ICS_EOL","\r\n");

// Header.
echo "BEGIN:VCALENDAR".ICS_EOL;
echo "VERSION:2.0".ICS_EOL;
echo "PRODID:tilderadio schedule".ICS_EOL;
echo "DTSTAMP:".ics_formatdate("now").ICS_EOL;

foreach ($schedule as $event) {
	// The VEVENT structure's pretty easy to generate, especially since we're already in UTC.
	echo "BEGIN:VEVENT".ICS_EOL;
	// First, we need a creation date.
	// Just go with now.
	echo "DTSTAMP:".ics_formatdate("now").ICS_EOL;
	// Next, the event start and end.
	echo "DTEND:".ics_formatdate($event["end"]).ICS_EOL;
	echo "DTSTART:".ics_formatdate($event["start"]).ICS_EOL;
	// Next, the recurrence rule.
	// We assume the format is weekly. (No DJs have requested any other frequency yet.)
	echo "RRULE:FREQ=WEEKLY".ICS_EOL;
	// Next, the event title, or "summary" as the spec calls it.
	echo "SUMMARY:DJ ".$event["title"].ICS_EOL;
	// Finally, a unique ID for this event.
	// To make absolutely certain we don't repeat the same event ID, I decided to use a SHA256 hash of the event structure.
	echo "UID:";
	echo hash("sha256",json_encode($event));
	// to avoid the validator complaining about lines longer than 75 characters, split after the hash
	echo ICS_EOL." ";
	// Now finish the address UID
	echo "@tilderadio.org".ICS_EOL;
	// Finally, close the VEVENT structure.
	echo "END:VEVENT".ICS_EOL;
	// Next event?
}

// Finally, close out the VCALENDAR structure.
echo "END:VCALENDAR".ICS_EOL;

?>
