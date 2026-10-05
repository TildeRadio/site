# DJ self-service and broadcast listing management

This incremental patch targets `TildeRadio/site` branch `dj-auth-login` at
`74d85910e134534727309240c8f9ad4c6f91ce07` (“fix streamer id picker”).

## Available controls

After signing in, the DJ booth provides:

- **Edit your profile and show:** display name, biography, avatar, community/IRC
  details, links, favorites, listener notes, show title/description/genres,
  timezone and weekday show formats. The permanent slug stays fixed.
- **Your assigned schedules:** add/edit/delete actual AzuraCast schedule entries
  for the station-specific streamer IDs assigned by an administrator.
- **Manage sets / broadcasts:** add listings for missed or upcoming sets and
  correct existing past/live listings. Edit the host name, start/end times,
  episode/show titles, format title, topic, mood, description, prompt, notes,
  show link, recording URL, track log and captured statistics.

Administrators manage every broadcast through **Administration → Sets /
broadcasts** or the same booth link. Ordinary DJs see and edit their assigned
profile's broadcasts only. Administrators still control account roles, profile
assignments and station/streamer assignments.

## Install on the website server

Save the patch as `/tmp/tilderadio-site-dj-self-service.patch`.
Preserve any local changes and confirm the repository is on `dj-auth-login`.
Run these commands on the website server:

```sh
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git branch --show-current
sudo -u nginx git status --short
```

Back up the private website database before applying. Choose a new destination
filename for each backup; the command deliberately refuses to overwrite a file.

```sh
sudo -u nginx php bin/backup-dj-admin.php /var/lib/tilderadio-dj-auth-site/before-dj-self-service.sqlite
sudo -u nginx git apply --check /tmp/tilderadio-site-dj-self-service.patch
sudo -u nginx git apply /tmp/tilderadio-site-dj-self-service.patch
sudo -u nginx composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

Do not force a failed patch check. Composer refreshes the optimized class map
for the new classes; no new dependencies or Composer configuration changes are
needed. Existing credentials, private configuration and the AzuraCast Docker
installation stay in place.

If OPcache timestamp validation is disabled, reload the actual PHP-FPM service
for this website. Sign in as deepend at `https://tilderadio.org/dj/`.
The private database gains a `broadcast_edits` table automatically on the first
authenticated request. Existing tables and records are retained.

## Activate a DJ's editing access

1. Under **Administration → DJs**, edit the verified DJ account.
2. Keep website access enabled and the role **DJ**.
3. Assign its existing public profile. Create a profile first if it has none.
4. Assign the station and its actual station-specific streaming account.
5. Save. The DJ signs in using the existing AzuraCast streaming credentials.

An unmapped DJ can sign in but cannot claim another profile or create broadcasts
under an arbitrary username. Co-hosts explicitly assigned the same profile can
edit that shared profile and its listings. A station assignment authorizes that
station's assigned schedule. Assignments are based on verified numeric identities;
matching names does not grant access.

Deleted profiles are unavailable to DJ editors until an administrator restores
them. Administrators retain profile deletion and restoration. Ordinary DJs may
edit/publish their assigned active profile, but cannot manage accounts, roles,
station assignments, other DJs' profiles or the administrator tools.

## Correct or add a broadcast

1. Open **Manage sets / broadcasts** and choose **Edit / delete**, or **Add a
   set / broadcast** for a missing listing.
2. Enter dates/times in **UTC**. Existing captured seconds are preserved when
   their minute-resolution controls are unchanged.
3. Correct the metadata. Administrators can select/change the owning DJ profile;
   a DJ's owner profile is enforced server-side.
4. If tracks need correction, check **Replace the track list with this JSON**.
   Otherwise leave it unchecked so Carrier's captured track log keeps updating.
5. Save. The listing changes appear in the public archive, DJ archive views,
   recent set titles and `/api/episodes/`. Public caches can briefly serve the
   previous listing; the API uses a 30-second freshness window.

Track entries accept `artist`, `title`, `text`, `art`, `played_at` and `duration`.
Times are Unix timestamps and durations are seconds. Up to 500 tracks and
64 KiB of correction data are supported. A captured track log too large for the
editor remains intact while the other metadata is edited. Example:

```json
[
  {
    "artist": "Artist",
    "title": "Correct track title",
    "text": "Artist - Correct track title",
    "played_at": 1791200000,
    "duration": 240
  }
]
```

The recording field is a link to existing audio; this patch does not upload or
move audio files. Listing status and metadata describe the website. Use the
schedule editor to change booked airtime. Carrier's IRC commands still operate
its live request/question queues, listener goals and handoffs; website listing
edits do not alter those bot controls or AzuraCast streaming credentials.

## Persistence and deletion

Carrier's `episodes.json` is a generated public export, so the website does not
write into it. Corrections live in the existing private website database and
are merged with Carrier's current export when reading the archive. Only fields
actually changed become overrides. Future track/statistic updates keep appearing
unless those fields were explicitly corrected.

Existing captured IDs and links stay fixed. Website-added records use integer
IDs starting at 1,000,000,000, skipping IDs already present in the export/database.
Current Carrier exports and saved source snapshots are available for editing.
If an older set is no longer exported and was never saved on the website, extend
Carrier's configured export coverage or add a recovered listing.

Saving/deleting a captured listing retains a source snapshot so that edited
records survive Carrier export rotation. Deletion creates a tombstone and hides
the listing from public HTML, terminal output and the API; it never deletes
Carrier's database rows or audio. Later exports cannot undo that deletion.
Administrators use **Deleted listings → Restore / edit** to restore it.
Ordinary DJs cannot restore a deleted listing, including one hidden by an admin.

The existing SQLite backup command includes broadcasts automatically.
The administrator JSON export now includes broadcast corrections and snapshots.

## Security and verification

Every write requires CSRF, a fresh authentication bridge check, current website
access and ownership checks. The database rechecks permissions inside its write
transaction. Broadcast forms have session-bound tokens, expire after 15 minutes,
and reject duplicate submissions, changed record versions and changed Carrier
snapshots. Schedule writes retain the existing per-streamer lock, assignment,
timezone and source-schedule checks. User text is escaped; links are validated.
Changes are recorded in the existing audit table and structured JSON log.

Development checks:

```sh
composer install --prefer-dist --no-interaction
composer validate --strict
composer lint:auth
composer format:check
composer analyse
composer test
python3 tests/http-auth.py
python3 tests/http-admin.py
python3 tests/http-dj-picker.py
python3 tests/http-dj-self-service.py
```

The optional `tests/http-player.py` DOM test also covers broadcast creation,
editing, deletion and public-page navigation while retaining the original
audio element. It requires Node.js and jsdom 27.0.1, as before.

The new HTTP checks cover DJ profile editing, schedule CRUD, broadcast CRUD,
ownership, CSRF/origin checks, stale/duplicate forms, revoked/moved assignments,
public HTML/API output, metadata/track preservation, Carrier rewrites and export
rotation. Fixtures use fake credentials and local TLS.

For a live check, log in as a mapped DJ, correct one set title, confirm its public
page, then check that another DJ's set and administrator routes remain forbidden.

Suggested commit:

```text
feat(dj): add scoped self-service and broadcast listing management
```

## Rollback

Before committing, reverse this patch:

```sh
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git apply --reverse --check /tmp/tilderadio-site-dj-self-service.patch
sudo -u nginx git apply --reverse /tmp/tilderadio-site-dj-self-service.patch
sudo -u nginx composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

Reverting the code retains the new database table, but older public code does
not apply its corrections or tombstones: original Carrier listings reappear.
Restore/reapply this feature to use the retained corrections again. For committed
changes, use your normal Git revert workflow. Reload PHP-FPM if OPcache requires it.
