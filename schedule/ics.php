<?php
include 'schedule.php';

header("Content-Type: text/calendar");

// ICS generation. Here be dragons.
// I created the file using a Python script and reverse-engineered it to figure this out.

// Header.
echo "BEGIN:VCALENDAR".PHP_EOL;
echo "VERSION:2.0".PHP_EOL;
echo "PRODID:tilderadio schedule".PHP_EOL;

foreach ($schedule as $event) {
	// The VEVENT structure's pretty easy to generate, especially since we're already in UTC.
	echo "BEGIN:VEVENT".PHP_EOL;
	// First, the event start and end.
	echo "DTEND:".formatdate($event["end"]).PHP_EOL;
	echo "DTSTART:".formatdate($event["start"]).PHP_EOL;
	// Next, the event title, or "summary" as the spec calls it.
	echo "SUMMARY:DJ ".$event["title"].PHP_EOL;
	// Finally, a unique ID for this event.
	// To make absolutely certain we don't repeat the same event ID, I decided to use a SHA256 hash of the event structure.
	echo "UID:";
	echo hash("sha256",json_encode($event));
	echo "@tilderadio.org".PHP_EOL;
	// Finally, close the VEVENT structure.
	echo "END:VEVENT".PHP_EOL;
	// Next event?
}

// Finally, close out the VCALENDAR structure.
echo "END:VCALENDAR".PHP_EOL;

?>
