<?php
$from = gmdate("Y-m-d\T00:00:00\Z", strtotime("today"));
$to   = gmdate("Y-m-d\T00:00:00\Z", strtotime("today + 8 days"));
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
echo "BEGIN:VCALENDAR" . ICS_EOL;
echo "VERSION:2.0" . ICS_EOL;
echo "PRODID:tilderadio schedule" . ICS_EOL;
echo "DTSTAMP:" . ics_formatdate("now") . ICS_EOL;

// A list of event IDs that we have on the calendar, to avoid duplication
$event_ids = [];

foreach ($schedule as $event) {
	$id = strval($event["id"]) . gmdate("DHis", strtotime($event["start"])) . gmdate("DHis", strtotime($event["end"]));

	if (!in_array($id,$event_ids)) {
		array_push($event_ids, $id);
		// The VEVENT structure's pretty easy to generate, especially since we're already in UTC.
		echo "BEGIN:VEVENT" . ICS_EOL;
		// First, we need a creation date.
		// Just go with now.
		echo "DTSTAMP:" . ics_formatdate("now") . ICS_EOL;
		// Next, the event start and end.
		echo "DTEND:" . ics_formatdate($event["end"]) . ICS_EOL;
		echo "DTSTART:" . ics_formatdate($event["start"]) . ICS_EOL;
		// Next, the recurrence rule.
		if ($event["title"] != "tomasino") {
			// We assume the format is weekly. (tomasino is the only DJ to have requested any other frequency.)
			echo "RRULE:FREQ=WEEKLY" . ICS_EOL;
		} else {
			// TTT only comes on the last Sunday of the month, 23:30:00 UTC to 01:00:00 UTC
			if (gmdate("His", strtotime($event["start"])) == "233000" && gmdate("His", strtotime($event["end"])) == "010000") {
				echo "RRULE:FREQ=MONTHLY;WKST=SU;BYDAY=SU;BYSETPOS=-1" . ICS_EOL;
			} else {
				// his other shows are weekly though
				echo "RRULE:FREQ=WEEKLY" . ICS_EOL;
			}
		}
		// Next, the event title, or "summary" as the spec calls it.
		echo "SUMMARY:DJ " . $event["title"] . ICS_EOL;
		// Finally, a unique ID for this event.
		// To make absolutely certain we don't repeat the same event ID, I decided to use a SHA256 hash of the event structure.
		echo "UID:";
		echo hash("sha256", json_encode($event));
		// to avoid the validator complaining about lines longer than 75 characters, split after the hash
		echo ICS_EOL . " ";
		// Now finish the address UID
		echo "@tilderadio.org" . ICS_EOL;
		// Finally, close the VEVENT structure.
		echo "END:VEVENT" . ICS_EOL;
		// Next event?
	}
}

// Finally, close out the VCALENDAR structure.
echo "END:VCALENDAR" . ICS_EOL;

?>
