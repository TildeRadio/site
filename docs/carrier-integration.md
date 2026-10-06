# TildeRadio website + Carrier integration: deployment and rollback

These patches target:

- `TildeRadio/site`, branch `dj-auth-login`, commit `c914048dc7ba100e769622d295d6f87102d31c4e`.
- `TildeRadio/carrier`, `main`, commit `38d77602e82b8b67a0fc18dd8190a596ef7c7eac`.
- Website: `/usr/share/nginx/tilderadio.org`.
- Installed bot: `/opt/tilderadio-bot`, systemd service `tilderadio-bot.service`.
- PHP 8.4+ and Python 3.11+. No new production Python packages are required.

The download includes separate `website.patch` and `carrier.patch`, this guide, and a patched Carrier source archive for your existing installation without a `.git` directory. No commits or pushes are performed by the patches or installer.

## What is included

1. Website show preparation: titles, topics, mood, listener prompt, notes, links and ordered artist/title lists. Accepts `Artist | Title` lines or two tab-separated spreadsheet columns.
2. Live website controls for playlist position, metadata, requests, question queues, listener goals, handoff, polls and the existing one-per-set Mastodon note.
3. Verified IRC services-account linking, scoped to the IRC network, with single-use five-minute codes and DJ/admin revocation.
4. Website listing corrections and tombstones reflected in Carrier's `!set`/`!last` output. Original sessions, captured tracks and statistics remain stored.
5. Published upcoming show previews, `!next` titles, optional booking-change notices, and optional private show reminders.
6. Public live listener questions, requests, reactions and poll voting, with CSRF and rate limits. Unanswered questions and request queues stay private.
7. Optional AzuraCast recording discovery, private downloads and DJ/admin approval. Podcast feeds include published completed recordings.
8. Administrator integration status, synchronization controls and audit records.

`!next` retains its existing schedule meaning. Playlist commands are `!track`, `!track next`, and `!track N`.

## Data and permissions

The website creates additive `show_plans`, `plan_broadcasts`, and `recording_candidates` tables. Carrier creates additive integration tables. There are no destructive migrations and no existing IDs are renumbered.

Carrier copies a preparation into an immutable session record when one preparation matches the current account, station and time window. Multiple matching preparations cause metadata fallback. Preparation does **not** reserve airtime or replace the AzuraCast booking; existing overlap rejection remains active.

Tracks are confirmed only when the live DJ advances them. Position starts at zero; press **Start next song** or use `!track next` when song 1 starts. Jumping to `!track 5` timestamps song 5 without marking skipped songs as played. Repeating the current song number preserves its start time. Website action IDs prevent a retried POST from advancing twice. To replay the same song deliberately, advance to another number and then select it again.

Carrier continues capturing upstream metadata independently. Its public episode export uses confirmed planned playback in planned mode and original captured metadata in metadata mode. Both histories remain in the database. The website's player can show the manually selected song through a small public snapshot; captured upstream metadata is retained separately. Unplayed prepared songs are not exposed by public feeds.

Switching to metadata closes the current planned song at that time. Switching back keeps its completed history; select the current song number or advance to start a new timestamped play. Choosing the already selected tracking mode leaves the active song running.

Website writes retain the existing session, CSRF, origin, ownership and fresh AzuraCast account checks. Carrier verifies the actor against a recent website snapshot and resolves the active streamer through the private AzuraCast broadcast API plus the public broadcast-start timestamp. It does not authorize new controls based on a nickname or display name alone. Administrators can control any current broadcast; ordinary DJs must be assigned to its actual station/streamer pair. Existing explicit bot mappings remain available; removing a new link does not remove old manual mappings or IRC administrator grants.

The local socket requires both the configured non-root PHP UID and a private random key. The web process receives no Docker access and no write access to Carrier's database. No public control port is opened.

### Choose the PHP identity before installation

The commands below use the `nginx` PHP worker shown in your installation. Confirm the **PHP-FPM pool user**, rather than assuming the nginx HTTP worker and PHP share an identity:

```sh
ps -eo user,group,args | rg 'php-fpm|php-cgi'
rg -n '^(user|group|listen)[[:space:]]*=' /etc/php-fpm.d /etc/php 2>/dev/null
systemctl status php-fpm tilderadio-bot --no-pager
systemctl cat tilderadio-bot
```

If the pool uses another user, replace `nginx` in this guide and the supplied website systemd units; configure that user's numeric UID in Carrier.

If untrusted sites/scripts also execute as the same PHP UID, isolate TildeRadio in a dedicated PHP-FPM pool **before granting socket/key access**. Unix permissions cannot distinguish two applications running as the same UID. Keep all PHP routes for this website on that pool, not just `/dj/`; public archive pages also read the private website state. Update private file ownership/group access for that pool without making secrets world-readable, and preserve the existing PHP extensions, environment variables, session directories and OPcache settings. The socket UID must be the dedicated pool's UID. Use `namei -l` on the existing bootstrap/config/state paths to confirm access. This is a host isolation choice, not a change to authentication credentials.

## 1. Put the package on the server and inspect it

The command blocks use **Bash**. In your root terminal, run `bash` once if your normal shell is fish, then keep that same Bash terminal open through deployment so `TR_BACKUP` remains set. Stop at any failed check; do not continue to the next installation command. If `sudo` is absent, root can use `runuser -u nginx -- COMMAND` in place of `sudo -u nginx COMMAND`.

Upload `tilderadio-integration.zip`, for example to `/tmp/tilderadio-integration.zip`, then as root:

```sh
unzip /tmp/tilderadio-integration.zip -d /root
cd /root/tilderadio-integration
sha256sum -c SHA256SUMS
```

The checksum file detects damaged transfers. It does not establish trust in an independently obtained package.

Check the website and dry-run the patch:

```sh
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git branch --show-current
sudo -u nginx git status --short
sudo -u nginx git rev-parse HEAD
```

Copy the patches from root's private directory into a staging directory accessible to nginx, then dry-run the website patch:

```sh
install -d -o root -g nginx -m 0750 /var/tmp/tilderadio-integration
install -o root -g nginx -m 0640 /root/tilderadio-integration/website.patch /var/tmp/tilderadio-integration/website.patch
install -o root -g nginx -m 0640 /root/tilderadio-integration/carrier.patch /var/tmp/tilderadio-integration/carrier.patch
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git apply --check /var/tmp/tilderadio-integration/website.patch
```

Use the staging path in all subsequent `sudo -u nginx git apply` commands. Require `dj-auth-login`; do not switch branches over local changes. If the dry run fails, preserve local changes and reconcile the baseline instead of using `--reject`, a reset, or a forced overwrite.

Prepare the patched bot source and verify your current installed Python files:

```sh
install -d -m 0700 /root/tilderadio-carrier-stage
tar -xzf /root/tilderadio-integration/carrier-source.tar.gz -C /root/tilderadio-carrier-stage
python3 /root/tilderadio-carrier-stage/carrier/tools/deploy-source.py /opt/tilderadio-bot --check
```

The check accepts either the original verified baseline or this patched build. It aborts for missing/modified original Python files or symlinks. If it reports a difference, review that file before installation; the installer has no force option.

For your **Git checkout** of Carrier, the equivalent patch commands are:

```sh
cd /path/to/carrier-checkout
git status --short
git rev-parse HEAD
git apply --check /path/to/carrier.patch
git apply /path/to/carrier.patch
```

Do not run those Git commands in your current `/opt/tilderadio-bot` directory: it is an installed source directory, not a checkout.

## 2. Back up source, configuration and both databases

Run as root. Keep this terminal open so `TR_BACKUP` remains set:

```sh
TR_BACKUP="/root/tilderadio-integration-backup-$(date +%Y%m%d-%H%M%S)"
install -d -m 0700 "$TR_BACKUP"
cp -a /etc/tilderadio "$TR_BACKUP/website-config"
cp -a /etc/tilderadio-bot.toml "$TR_BACKUP/tilderadio-bot.toml"
cp -a /opt/tilderadio-bot "$TR_BACKUP/installed-bot"
cp -a /var/lib/tilderadio-dj-auth-site "$TR_BACKUP/website-state"
cp -a /var/lib/tilderadio-bot "$TR_BACKUP/bot-state"
php /usr/share/nginx/tilderadio.org/bin/backup-dj-admin.php "$TR_BACKUP/site-admin.sqlite"
export TR_BACKUP
```

The directory copies preserve sessions and other files. Use the **consistent SQLite backups** below/above as the authoritative database backups, rather than copying a live WAL database alone:

```sh
python3 - <<'PY'
import os
from pathlib import Path
import sqlite3
import tomllib
from urllib.parse import quote
with open('/etc/tilderadio-bot.toml', 'rb') as handle:
    config = tomllib.load(handle)
source = Path(config.get('bot', {}).get('database', '/var/lib/tilderadio-bot/bot.sqlite3')).expanduser().resolve()
if not source.is_file():
    raise SystemExit('Configured bot database not found; stop and verify its path.')
output = Path(os.environ['TR_BACKUP']) / 'bot.sqlite3'
with sqlite3.connect('file:' + quote(str(source)) + '?mode=ro', uri=True) as original:
    with sqlite3.connect(output) as backup:
        original.backup(backup)
        if backup.execute('PRAGMA integrity_check').fetchone()[0] != 'ok':
            raise SystemExit('Bot backup integrity check failed.')
output.chmod(0o600)
print('Consistent bot database backup created.')
PY
```

If your configured state/database directories differ from the defaults, also back up those actual paths. Preserve any existing service overrides and environment files:

```sh
systemctl cat tilderadio-bot > "$TR_BACKUP/tilderadio-bot-unit.txt"
if test -f /etc/tilderadio-bot.env; then cp -a /etc/tilderadio-bot.env "$TR_BACKUP/tilderadio-bot.env"; fi
if test -d /etc/systemd/system/tilderadio-bot.service.d; then cp -a /etc/systemd/system/tilderadio-bot.service.d "$TR_BACKUP/bot-service-overrides"; fi
```

## 3. Create the private local connection

Use a group containing only Carrier and the website's PHP identity:

```sh
getent group tilderadio-carrier || groupadd --system tilderadio-carrier
usermod -aG tilderadio-carrier tilderadio-bot
usermod -aG tilderadio-carrier nginx
install -d -o root -g tilderadio-carrier -m 0750 /etc/tilderadio-carrier
```

Create the key only if it does not already exist; do not rotate it during an ordinary redeployment:

```sh
if ! test -e /etc/tilderadio-carrier/key; then
    umask 0077
    openssl rand -hex 32 > /etc/tilderadio-carrier/key
fi
chown root:tilderadio-carrier /etc/tilderadio-carrier/key
chmod 0640 /etc/tilderadio-carrier/key
id -u nginx
```

Use the numeric output as `website_uid` in the next step. The key contains the generated 64 hexadecimal characters and a newline. It is **not** the AzuraCast API key, not a DJ password, and is never sent to a browser.

## 4. Update Carrier configuration and install the bot

Edit `/etc/tilderadio-bot.toml`. Keep all existing sections/settings and add exactly one `[integration]` section:

```toml
[integration]
enabled = true
socket_path = "/run/tilderadio-carrier/control.sock"
key_file = "/etc/tilderadio-carrier/key"
website_uid = 123
station_id = 1
announcements = false
reminder_minutes = 15
```

Replace `123` with the actual PHP UID. `announcements = false` prevents new schedule/reminder channel notices during rollout. Existing Carrier announcements and Mastodon configuration are retained.

Install the systemd drop-in without replacing the existing service:

```sh
install -d -m 0755 /etc/systemd/system/tilderadio-bot.service.d
install -m 0644 /root/tilderadio-carrier-stage/carrier/deploy/bridge.conf /etc/systemd/system/tilderadio-bot.service.d/bridge.conf
systemctl daemon-reload
systemctl stop tilderadio-bot
python3 /root/tilderadio-carrier-stage/carrier/tools/deploy-source.py /opt/tilderadio-bot --install --backup "$TR_BACKUP/bot-source"
systemctl start tilderadio-bot
systemctl status tilderadio-bot --no-pager
journalctl -u tilderadio-bot -n 60 --no-pager
ls -ld /run/tilderadio-carrier
ls -l /run/tilderadio-carrier/control.sock
```

The directory should be owned by `tilderadio-bot:tilderadio-carrier`, mode `0750`; the socket should be mode `0660` with that group. Source deployment updates Python source only. It does not touch your TOML, environment file, SQLite database, JSON exports or unrelated installed files.

If the integration socket fails to start, Carrier logs the optional integration failure and retains normal radio capture and existing commands. Correct the configuration/permissions and restart it before continuing.

## 5. Apply the website patch and merge its configuration

```sh
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git apply --check /var/tmp/tilderadio-integration/website.patch
sudo -u nginx git apply /var/tmp/tilderadio-integration/website.patch
sudo -u nginx composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative
```

Composer uses the existing lockfile; this patch adds no production dependencies. Make sure PHP has curl, PDO SQLite, JSON and fileinfo. Fileinfo is required only for recording downloads.

```sh
php -v
php -m | rg 'curl|PDO|pdo_sqlite|fileinfo'
```

Edit `/etc/tilderadio/dj-auth-site.json` and add `carrier` **inside the existing root object**, preserving all current accounts, administrators, paths, proxy settings and `schedule_api`. Example of the complete shape:

```json
{
  "origin": "https://tilderadio.org",
  "base_path": "",
  "client_bootstrap": "/opt/tilderadio-dj-auth/website-client/bootstrap.php",
  "client_config": "/etc/tilderadio/dj-auth-client.json",
  "state_dir": "/var/lib/tilderadio-dj-auth-site",
  "trusted_proxy_ips": [],
  "accounts": {"1:163": "deepend"},
  "administrators": ["1:163"],
  "schedule_api": {
    "base_url": "https://azuracast.tilderadio.org",
    "key_file": "/etc/tilderadio/azuracast-schedule.key",
    "station_ids": [1]
  },
  "carrier": {
    "enabled": true,
    "station_id": 1,
    "socket_path": "/run/tilderadio-carrier/control.sock",
    "key_file": "/etc/tilderadio-carrier/key",
    "public_state_file": "/var/lib/tilderadio-bot/carrier-live.json",
    "recordings": {
      "enabled": false,
      "directory": "/var/lib/tilderadio-recordings",
      "import_actor": "1:163",
      "max_bytes": 1073741824
    }
  }
}
```

This is an example, not a command to replace your current configuration. `deepend` remains administrator `1:163`; assignments already stored in SQLite remain unchanged. The existing `azuracast-schedule.key` still contains the actual AzuraCast API key. Keep that key scoped to station 1 and the management/view permissions needed for streamer schedules and broadcast listing/download; no global administrator key is needed.

Validate JSON without displaying its contents:

```sh
php -r 'json_decode(file_get_contents("/etc/tilderadio/dj-auth-site.json"), true, 32, JSON_THROW_ON_ERROR); echo "Site JSON is valid\n";'
sudo -u nginx php /usr/share/nginx/tilderadio.org/bin/carrier-sync.php --migrate
```

Restart the relevant PHP-FPM service to pick up supplementary group membership. Use its actual unit name; on your nginx layout it is often `php-fpm`:

```sh
systemctl restart php-fpm
```

If SELinux is enforcing on the server, review actual denials (`ausearch -m AVC -ts recent`) and grant only this pool's required socket/private-path access through your local policy. Do not disable SELinux or make the key/state world-readable. A PHP CLI success alone does not prove PHP-FPM's SELinux domain can connect.

## 6. Real-socket smoke test and periodic synchronization

Run these as the configured PHP identity:

```sh
sudo -u nginx php /usr/share/nginx/tilderadio.org/bin/carrier-sync.php --sync
sudo -u nginx php /usr/share/nginx/tilderadio.org/bin/carrier-sync.php --check
```

Require a successful sync and a health response showing `protocol: 1`, the configured station's account records, and a recent `last_sync_at`. This verifies your actual Unix socket, UID, group and key. Testing in the development workspace used a local transport fixture because its sandbox denies Unix sockets; this server check is mandatory before rollout.

Install the periodic synchronization units:

```sh
install -m 0644 /usr/share/nginx/tilderadio.org/deploy/tilderadio-carrier-sync.service /etc/systemd/system/
install -m 0644 /usr/share/nginx/tilderadio.org/deploy/tilderadio-carrier-sync.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now tilderadio-carrier-sync.timer
systemctl start tilderadio-carrier-sync.service
systemctl list-timers tilderadio-carrier-sync.timer --no-pager
journalctl -u tilderadio-carrier-sync -n 30 --no-pager
```

Adjust `User`, `Group`, `ExecStart`'s PHP binary, and `ReadWritePaths` if your pool/PHP/state paths differ. The timer refreshes approximately every 45 seconds. Metadata/history corrections reach Carrier on the next successful sync. Live website actions synchronize first. Partial syncs leave the previous complete snapshot intact; snapshot authorization expires after two minutes.

## 7. Browser and IRC checks

Hard-refresh the website to load the updated player script. Clear a CDN's cached JS if applicable. If OPcache timestamp validation is disabled, reload/restart the appropriate PHP-FPM pool after code changes.

1. Log in as `deepend`: open **Carrier integration** in administration, confirm recent synchronization, and inspect all preparations/links.
2. Log in as a normal DJ: confirm only their preparations/listings/recordings are available. Existing profile and schedule editing should work, and overlapping bookings must still be rejected.
3. Prepare a show for the assigned station and expected UTC window. Enter a short playlist. **Publish upcoming title/topic** is optional; the ordered playlist remains private until tracks are confirmed as played.
4. In **Live / IRC**, generate a code. While signed in to IRC services, privately message Carrier `!link CODE`. Refresh the page to see the network/account link. The IRC server must provide verified `account-tag`; nick-only identification is insufficient.
5. Start the actual AzuraCast broadcast. Allow a radio poll and synchronization cycle for the private streamer-ID check. The matching preparation should attach automatically; starting from song zero, use `!track next` or **Start next song**.
6. Advance to song 2 and jump to another number. Refresh the archive and `!set N`; confirm artist/title and start timestamps. `!next` must still show the next scheduled DJ.
7. From another DJ account, confirm live controls are unavailable for this broadcast. Administrators retain an override. A stale form from a previous broadcast must be rejected.
8. Open `/community/live.php` as a listener. Test a question, request while requests are open, reaction and vote. Queued questions must remain private until marked answered. Close requests and confirm further requests are rejected.
9. Correct a completed listing title and let a sync finish. Confirm the website and `!set N` agree. Deleting the listing should hide it from `!set`/`!last`, while the captured database/audio remain present.
10. Check the existing stream player stays playing while navigating and submitting booth forms.

For an unscheduled or unprepared show, Carrier uses its existing metadata capture. A prepared playlist is manually advanced; it does not control the audio source, AzuraCast playout or DJ software.

Private reminders attempt delivery to the last known nick after a fresh services-account WHOIS check. If identity cannot be confirmed, Carrier keeps the reminder until the linked user next interacts with it, or the reminder expires five minutes after airtime. To enable extra channel reminders/booking notices, set `announcements = true` in `[integration]` and restart Carrier. Its existing per-channel announcement preferences still apply.

## 8. Optional recording discovery and podcast feeds

Leave this disabled until AzuraCast records your live broadcasts and you have space for private local copies. Downloading keeps the upstream original; approving/deleting a website listing never deletes an audio file.

Create private storage:

```sh
install -d -o nginx -g nginx -m 0700 /var/lib/tilderadio-recordings
```

Set `carrier.recordings.enabled` to `true` in the website JSON. Keep `import_actor` as your protected administrator identity `1:163`. `max_bytes` is a per-file cap (the example is 1 GiB). Configure a suitable retention/storage policy for local recordings; this implementation deliberately does not delete recordings automatically.

Each discovery pass downloads at most three new files; later passes continue with the remaining recent recordings. Run a discovery pass and inspect the review queue:

```sh
sudo -u nginx php /usr/share/nginx/tilderadio.org/bin/carrier-recordings.php
```

Discovery examines the latest 50 AzuraCast broadcasts and up to 200 recently completed Carrier sessions. It matches the recorded upstream station, streamer and broadcast-start timestamp exactly. Automatic matching applies to sessions whose verified identity was captured by the patched bot. It does not guess matches for old/ambiguous records. Old recordings can be attached manually in the existing broadcast editor or imported with the manifest below.

A DJ/admin can preview a downloaded candidate privately, approve its link, or reject the candidate. Public download requires approval and a currently visible listing that still references that URL. Downloads support byte ranges; the PHP fallback streams bounded chunks. The recording files remain outside the document root.

Enable the recording timer only after a successful manual pass:

```sh
install -m 0644 /usr/share/nginx/tilderadio.org/deploy/tilderadio-recordings.service /etc/systemd/system/
install -m 0644 /usr/share/nginx/tilderadio.org/deploy/tilderadio-recordings.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now tilderadio-recordings.timer
```

Feeds:

- Station: `https://tilderadio.org/api/podcast/`
- DJ: `https://tilderadio.org/api/podcast/?dj=cat`
- Published previews: `https://tilderadio.org/community/upcoming.php`
- Public preparation JSON: `https://tilderadio.org/api/planned/`

For externally hosted audio, save a private JSON manifest with **actual Carrier/website broadcast IDs**, not AzuraCast broadcast IDs:

```json
[
  {"broadcast_id": 42, "url": "https://recordings.example.org/set-42.mp3", "bytes": 12345678}
]
```

```sh
sudo -u nginx php /usr/share/nginx/tilderadio.org/bin/import-recordings.php 1:163 /private/path/recordings.json
```

The manifest must be outside the website and readable by the PHP identity. HTTPS URLs are required. Re-importing the same link does not duplicate or reopen an approved/rejected candidate. External URLs are linked, not fetched by this importer; only the fixed authenticated AzuraCast endpoint is downloaded by discovery.

For efficient nginx delivery, optionally add the following **internal-only** location to this website's existing TLS server block, without changing its PHP routing:

```nginx
location /tilderadio-recordings-internal/ {
    internal;
    alias /var/lib/tilderadio-recordings/;
}
```

Then set `carrier.recordings.x_accel_prefix` to `/tilderadio-recordings-internal/`, run `nginx -t`, and reload nginx. PHP checks authorization/approval before issuing `X-Accel-Redirect`; direct public requests to the internal location must return 404. If nginx and the dedicated PHP pool have different UIDs, grant nginx read access specifically to approved-file delivery through a scoped ACL/group arrangement; keep secrets and pending recordings inaccessible through public URLs.

## 9. Troubleshooting

- **Old website features work but socket controls are offline:** inspect the bot journal, key/group permissions, `website_uid`, service drop-in and PHP-FPM group membership. Do not open a public port as a workaround.
- **Live DJ cannot be verified:** check `/api/now/` has `station.id` and `broadcast_start`, the private API key can list streamer broadcasts, the website assignments contain the current upstream streamer ID, and the timer has recently succeeded. Use the admin status page. Do not grant access using a nickname.
- **Plan did not attach:** check account/station/window, multiple matching plans, earlier use of that preparation, sync age, and current upstream identity. Copy a used preparation for another show.
- **No new tracks from a prepared list:** press Start next song; metadata capture does not automatically advance a manual playlist. Switch the live page's tracking mode to metadata if needed; both histories remain stored.
- **Unknown outcome/timeout after a click:** reload and inspect current position/queue state. Retry only the same pending form if needed; its ID prevents duplicate execution. Do not blindly click another Next button.
- **Recording discovery finds nothing:** confirm AzuraCast recording is enabled, the broadcast has ended, identity was captured by the patched bot, a recording exists, and it is within the recent directory/file-size limits. Use manual attachment for older records.
- **An API/schema change after an AzuraCast update:** existing website edits and Carrier capture remain available. New live-ID verification/discovery fails closed; inspect the updated official API and update the adapter. There is no custom file installed inside the AzuraCast Docker container.

## 10. Rollback without losing new data

Stop new integration jobs and Carrier first:

```sh
systemctl disable --now tilderadio-carrier-sync.timer
systemctl disable --now tilderadio-recordings.timer
systemctl stop tilderadio-carrier-sync.service tilderadio-recordings.service
systemctl stop tilderadio-bot
```

Skip the recording commands if those units were never installed. Restore configuration from the backup and reverse the website code patch only:

```sh
cp -a "$TR_BACKUP/website-config/dj-auth-site.json" /etc/tilderadio/dj-auth-site.json
cp -a "$TR_BACKUP/tilderadio-bot.toml" /etc/tilderadio-bot.toml
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git apply -R --check /var/tmp/tilderadio-integration/website.patch
sudo -u nginx git apply -R /var/tmp/tilderadio-integration/website.patch
sudo -u nginx composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative
cp -a "$TR_BACKUP/bot-source/." /opt/tilderadio-bot/
rm /etc/systemd/system/tilderadio-bot.service.d/bridge.conf
systemctl daemon-reload
systemctl start tilderadio-bot
systemctl restart php-fpm
```

The old bot ignores unused new module files/tables. Original captured tracks remain available and resume the old export behavior. Leave both databases and private recording files in place: they contain your latest data, including any edits since installation. **Do not restore an old database during an ordinary code rollback**, because doing so would discard those later edits. Consistent DB backups are for disaster recovery only, with all writers stopped and a deliberate recovery decision.

If you subsequently changed code/config, review reverse-apply failures rather than forcing removal. Preserve existing service overrides; remove only this package's `bridge.conf`.

## Validation supplied with this release

The website includes PHPUnit ownership/migration/recording tests and real PHP/TLS form tests. Carrier includes playlist, raw-capture preservation, restart, snapshot, idempotency, link, reminder, queue and transport authorization tests. Existing authentication/admin/schedule/player regressions were run. The actual AF_UNIX kernel connection cannot run in the development sandbox; Step 6 verifies it on the target server before feature rollout.

Official AzuraCast sources used for compatibility:

- https://github.com/AzuraCast/AzuraCast/blob/main/backend/src/Entity/Api/NowPlaying/Live.php
- https://github.com/AzuraCast/AzuraCast/blob/main/backend/src/Controller/Api/Stations/Streamers/BroadcastsController.php
