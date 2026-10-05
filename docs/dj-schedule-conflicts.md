# Schedule conflicts and broadcast permissions

This incremental patch targets `dj-auth-login` at
`63bd335042a3e5e20022dad308d590733d69e65a` (`add dj functionality`).
Apply it after the DJ self-service functionality. It adds no credentials,
dependencies, database migration or AzuraCast installation changes.

## Install

Save the patch as `/tmp/tilderadio-site-schedule-conflicts.patch`, then:

```bash
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git branch --show-current
sudo -u nginx git apply --check /tmp/tilderadio-site-schedule-conflicts.patch
sudo -u nginx git apply /tmp/tilderadio-site-schedule-conflicts.patch
sudo -u nginx composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

The branch should be `dj-auth-login`. If the check fails, do not force the patch;
check whether the branch already contains it or has other local changes.
Reload your normal PHP-FPM service if OPcache does not check file timestamps.

## Booking rules

- Both DJs and administrators are prevented from adding or editing a slot that
  overlaps another DJ booking in the same station, or their own other entries.
- Every upstream streamer is checked, including accounts that have not been
  activated on the website. Inactive streamers' saved slots also remain reserved;
  remove those entries to free their slots.
- The entry being edited is excluded from comparison with itself. Existing
  unrelated collisions do not prevent fixing a different, conflict-free entry.
- Recurring weekdays, every-day rules, optional date limits, overnight shows
  and Sunday-to-Monday rollover are checked. There is no fixed future horizon.
- Times use the station's current AzuraCast timezone. Clock-change days are
  checked using PHP's timezone normalization, matching AzuraCast's calendar
  construction. Existing overnight end-date behavior is retained: the last date
  limits the end, except a single-date overnight show can finish the next day.
- Slots are half-open: 19:00–20:00 and 20:00–21:00 are allowed together.
  Equal start/end entries already present upstream have no duration; the editor
  continues to reject creating them.
- A conflict returns HTTP 409 and identifies the DJ, entry, weekday/time and
  station timezone. The rejected request never sends a schedule update.
- An incomplete, oversized, malformed, unauthorized or timed-out upstream read
  blocks adding/editing. The existing adaptive pagination and 2 MiB per-response
  limit are retained. No cached DJ directory is used for booking validation.
- Deleting an entry remains possible without loading other DJs' schedules, so
  old conflicts can be removed even if another DJ's schedule is malformed.
- No administrator override is included.

All website saves take a station-wide filesystem lock before reading and writing.
This prevents simultaneous website requests for different DJs from both booking
the same available slot. The edited DJ's current schedule, station timezone and
website authorization are checked again before updating AzuraCast. Existing
credentials, advanced flags and other entries are preserved.

**Scope:** this lock covers this website installation using the same `state_dir`.
AzuraCast's own UI and other API clients do not acquire it, and the upstream API
does not offer an atomic station-wide reservation transaction. A simultaneous
external edit can still race the final API write. Make bookings through this
website to use its conflict protection; this patch does not change AzuraCast's
native overlap policy. Multiple website servers need a shared working lock
filesystem or a distributed locking implementation.

## Broadcast/session access outside administration

`/dj/broadcasts.php` and `/dj/broadcast.php` intentionally serve both roles.
Permissions are determined by the authenticated account, not by the URL or
whether the user opened the administration navigation.

- A normal DJ can list, add, edit and delete listings belonging to the profile
  currently assigned to their account. A profile assignment is required.
- Changing `id`, adding `admin=1`, posting another `owner_slug`, or changing the
  host's display name does not give access to another profile's listings.
- Ownership is checked before rendering and again inside the write transaction.
  Current account disabling, deletion and profile reassignment take effect.
- An administrator, including `deepend` (`1:163`), retains permission to edit all
  listings when opening these pages from the normal DJ booth. Leaving the
  administration pages does not downgrade the account's role.
- If an administrator deliberately assigns multiple accounts to the same DJ
  profile, those accounts share that profile's broadcast editing permissions.
- Listing deletion hides a broadcast from the public website/API. Audio and
  Carrier source data are retained. Only administrators can restore a listing.

The broadcast behavior is unchanged; this patch extends the regression tests for
forged edits/deletes, manual listing creation/edit/deletion, owner tampering and
administrator access through the same non-administrator URL.

Broadcast timestamps are archive metadata, not airtime reservations. Two saved
archive listings can have overlapping timestamps; edit the schedule to book a
slot. Live Carrier sessions and audio files are not deleted by listing deletion.

## Validation and rollback

CI runs PHP lint, PHP-CS-Fixer, PHPStan, PHPUnit, the existing authentication,
administrator and picker HTTP checks, DJ self-service checks, and the new
`tests/http-schedule-conflicts.py` suite on PHP 8.4 and 8.5.

To remove only this incremental change:

```bash
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git apply --reverse --check /tmp/tilderadio-site-schedule-conflicts.patch
sudo -u nginx git apply --reverse /tmp/tilderadio-site-schedule-conflicts.patch
sudo -u nginx composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

Rollback does not revert schedules already saved in AzuraCast or broadcast
corrections. It removes the website's overlap rejection. Reload PHP-FPM if needed.
