# TildeRadio administrator upgrade

The follow-up [DJ username picker](dj-username-picker.md) replaces manual
streamer-ID entry when the private API connection is configured, while keeping
the original fields as a fallback.

Apply this upgrade on **tilde, the website server**, to branch `dj-auth-login`.
The patch is based on commit `3fe13faffab7c99c6e58caf30283829da112a216` (“add dj
login”). Keep the working authentication bridge; no AzuraCast Docker or bridge
modification is required.

## Included controls

| Control | Effect |
| --- | --- |
| Website DJs | Add a bridge-verified account, edit its label, enable/disable login, grant/revoke administrator access, delete/restore |
| Ownership | Link a verified station/streamer pair to a profile slug |
| Stations | Add/edit/disable/delete/restore website records and assign each DJ's station-specific streamer ID |
| Profiles | Edit biography, avatar, links, personal details, show metadata, weekday formats, favorites and notes; publish/unpublish/delete/restore |
| Schedules | With an optional private API key, add/edit/delete live AzuraCast schedule entries, times, weekdays and date limits |
| Activity/export | Inspect recent actions and export website records |

All editing is administrator-only. Ordinary DJs retain the existing login and
booth; self-service comes later. Streaming accounts/passwords, station operation
and broadcast controls remain managed in AzuraCast. Website deletion does not
delete streaming accounts, archived audio or Carrier data. Individual archive
episode editing/deletion is outside this patch.

## 1. Check and back up the existing installation

Keep these working private paths and back them up using your private server
backup process, outside the document root:

- `/opt/tilderadio-dj-auth/`
- `/etc/tilderadio/dj-auth-client.json` and `dj-auth-site.json`
- `/var/lib/tilderadio-dj-auth-site/`, including `sessions/`

Both state directories must remain mode `0700` and owned by PHP-FPM's worker.
The commands below use `nginx`, as in your listing; substitute the actual worker
user/group if different. The website CLI **and PHP-FPM** still need PHP 8.4+,
cURL, JSON, PDO and PDO SQLite. AzuraCast's PHP version is separate.

```sh
cd /usr/share/nginx/tilderadio.org
php -v
php -m
sudo -u nginx git branch --show-current
sudo -u nginx git status --short
php /opt/tilderadio-dj-auth/tools/test-login.php /etc/tilderadio/dj-auth-client.json
```

Confirm the branch is `dj-auth-login`. Preserve unrelated local changes.

## 2. Apply and update the autoloader

Save the patch as `/tmp/tilderadio-site-dj-admin.patch`, readable by `nginx`:

```sh
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git apply --check /tmp/tilderadio-site-dj-admin.patch
sudo -u nginx git apply /tmp/tilderadio-site-dj-admin.patch
sudo -u nginx composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

If the check fails, resolve differences before applying; do not force it.
**Run Composer even when dependencies are installed:** this patch adds a new
autoload namespace. Use the CLI matching PHP-FPM; do not run `composer update`
in production. Commit through your normal workflow so deployments retain it.
The existing Drone job only pulls Git; ensure deployment runs Composer install
when dependencies/autoloading change. Reload your actual PHP-FPM service if
OPcache is configured without timestamp validation.

The private `admin.sqlite` database initializes automatically on the first DJ
request. It seeds station 1, configured account links, and valid existing
`data/djs.php` / `data/djs/*.json` profiles. Original files stay intact. No manual
SQL migration or replacement configuration is needed.

## 3. Extend your Nginx restrictions

Replace the overlapping restriction from the login installation with this
expanded rule, **before** the general regex PHP handler:

```nginx
location ~ ^/(?:vendor|tests|docs|config|lib/(?:DjAuth|Admin)|bin)(?:/|$) {
    return 404;
}
location = /dj/_bootstrap.php {
    return 404;
}
location = /dj/admin/_admin.php {
    return 404;
}
location ~ ^/(?:composer\.(?:json|lock)|phpunit\.xml|phpstan\.neon)$ {
    return 404;
}
```

Do not duplicate exact locations. Keep existing hidden-file restrictions,
PHP-FPM, HTTPS settings and stream proxies. Prefix patterns for subdirectory
deployments. Authenticated responses must bypass CDN/proxy caches.

```sh
nginx -t
systemctl reload nginx
```

Use `nginx -s reload` without systemd. Private state remains outside the website.
Public pages read only profile metadata and do not create authentication sessions.

## 4. Use administration

Sign in as **deepend** at `https://tilderadio.org/dj/` and choose
**Administration**, or open `https://tilderadio.org/dj/admin/`.

1. In **Stations**, edit seeded station `1` and its website timezone hint.
   This does not reconfigure AzuraCast.
2. In **Profiles**, create any missing profile with its existing schedule/archive
   slug. Valid repository profiles are imported automatically.
3. In **DJs → Add a DJ**, enter the bridge-verified source pair. For **cat** this
   is authentication station `1`, streamer `4`. Set its website label, role,
   login access and profile link.
4. Assign station `1` with streamer `4`. In another station, enter the actual
   streamer ID **in that station**, which can differ from the login identity.
5. Save. Website roles/access are checked on every request, including sessions
   already logged in. Ordinary DJs receive HTTP 403 on administrator pages.

New verified DJs may still sign in before manual registration, preserving the
original login behavior. This creates an ordinary account, without automatic
administrator access or guessed profile ownership.

The bridge authenticates its configured station; adding a website station
does not add another authentication provider. The existing public broadcast feed
remains station 1. Additional records support memberships and schedule targets.

Identity IDs and existing profile slugs are immutable. If an AzuraCast account
is recreated, verify its new ID, disable/delete the old website account and add
the new pair. Matching names do not transfer privileges. Co-hosts can explicitly
share a profile.

Your private `"administrators": ["1:163"]` keeps deepend protected. The GUI cannot
disable, demote or delete it. Administrators cannot remove their own access.
Other administrator roles can be granted/revoked through the GUI. Keep a
protected administrator in server configuration; if deepend's ID changes, the
server operator must verify and update that entry. Protected IDs are evaluated
live, not copied into permanent database grants.

The private config's `accounts` mappings seed initial database records.
After initialization, edit mappings in the GUI; changing that JSON does not
overwrite existing GUI changes. Protected `administrators` entries remain live.

## 5. Profiles and restore

The friendly editor supports normal fields and preserves unknown metadata.
Weekday show formats retain their existing JSON structure:

```json
[
  {"id":"saturday","days":["Saturday"],"title":"Saturday show","genres":["Electronic"]},
  {"id":"wednesday","days":["Wednesday"],"title":"Wednesday show"}
]
```

Each format needs a unique ID and weekday names. Show format timezone selects
weekday metadata; station schedule timezone is separate. The advanced full-JSON
editor replaces metadata after validation. No credentials, upload or computed
schedule fields belong in it.

GUI edits override repository files and survive Git deployment. **Import new
repository profiles** adds new files without overwriting edits or restoring
deletions. Deletion leaves a tombstone, preventing a schedule or deployment
from recreating that public profile. Scheduled broadcasts remain visible.

Use **Deleted records → Restore / edit** for profiles, accounts or stations.
Restored profiles start unpublished unless you choose Published. Account
restoration requires a fresh active-account bridge check and new assignments.
Deleting an account leaves its profile intact; unpublish/delete it separately.
Archive files/audio and Carrier formats are untouched. Public profile/API pages
receive website metadata through the existing slug.

## 6. Optional live schedule editing

All other controls work with your existing configuration. Schedule editing needs
a **separate AzuraCast API key**, stored only on the website server.

### Create a restricted AzuraCast API user

In AzuraCast, create a dedicated user/role with station-scoped permissions to
**view station management** and **manage streamers/DJ accounts**, limited to
station 1 and any other stations you need. Create an API key for that user.
Menu labels can vary; inspect your installed `/api` documentation and permissions.
Do not use a global administrator key.

Manage-streamers permission also permits credential/account changes through the
API; AzuraCast's inspected implementation has no schedule-only permission.
The website wrapper sends only schedules, but this server credential must remain
private.

### Store and configure the key on tilde

If the following file does not exist, create it as root:

```sh
install -o root -g nginx -m 0640 /dev/null /etc/tilderadio/azuracast-schedule.key
```

That command replaces an existing file; do not run it over an installed key.
Use a local editor to put the API key alone on one line. Keep `root:nginx`
ownership, `0640` mode and private parent-directory permissions. Do not put the
key in shell commands, Git, browser forms or support messages.

Add this top-level section to the existing private
`/etc/tilderadio/dj-auth-site.json`, preserving all working fields:

```json
"schedule_api": {
  "base_url": "https://YOUR-AZURACAST-HOSTNAME",
  "key_file": "/etc/tilderadio/azuracast-schedule.key",
  "station_ids": [1]
}
```

Use the real AzuraCast installation HTTPS hostname **without `/api`**. Do not
use its stream port or bridge URL. `station_ids` is a server-controlled allowlist
of numeric JSON integers, separate from website memberships. No Docker port or
network changes are required.

For a private CA, optionally add `"ca_file":
"/etc/tilderadio/azuracast-ca.pem"`, pointing to the trusted CA certificate bundle.
TLS certificate/hostname verification is always enabled; redirects are refused.
Never disable verification to work around a certificate error.

If SELinux is enforcing, label the new key for PHP read access:

```sh
semanage fcontext -a -t httpd_sys_content_t '/etc/tilderadio/azuracast-schedule\.key'
restorecon -v /etc/tilderadio/azuracast-schedule.key
```

Use `-m` when that exact rule already exists; label a CA file similarly if used.
Preserve working outbound HTTPS policy and bridge/config labels.

### Edit and verify

Open a DJ's **Schedule · station 1** link. Verify the username, station/streamer
IDs and **actual AzuraCast timezone** shown. Add a row or choose Edit/delete.
Times use that timezone; an earlier end continues into the next day. Equal
times are rejected for edited/new entries. No selected weekdays means daily.
Date limits are optional; the end date cannot precede the start. Delete requires
typing `DELETE`. Existing advanced flags and untouched rows are preserved.

After saving, inspect the same DJ in AzuraCast. Only `schedule_items` is sent in
a partial `PUT /api/station/{station_id}/streamer/{streamer_id}`. Credentials,
active status, comments and `enforce_schedule` stay untouched.

Server-held forms expire in 15 minutes and check current role, mapping, account
version, timezone and latest schedule before writing. Detected changes return
409. Website writes for the same DJ are serialized. AzuraCast replaces the
complete schedule list and offers no atomic compare-and-swap here: **avoid
simultaneous website/AzuraCast edits of the same DJ**. An external change between
the final comparison and PUT remains a race.

Ambiguous network failures are never automatically retried. Reload and inspect
AzuraCast before retrying, especially after adding a row. The editor supports
up to 100 entries per DJ; unsupported responses fail without writing.

The contract was checked against AzuraCast source and tested with a local TLS
API fixture. It has **not** been tested against your live installation. Verify
one edit after deployment and after AzuraCast upgrades; API changes can require
maintenance. Repeat the existing bridge `test-login.php` check after updates.

## 7. Backup and rollback

Administration lives in `/var/lib/tilderadio-dj-auth-site/admin.sqlite`. Include
it and private configuration/credential files in protected backups. SQLite uses
WAL; copying only the database file during writes can miss committed changes.
The supplied CLI creates a consistent standalone copy including committed WAL:

```sh
install -d -o nginx -g nginx -m 0700 /var/backups/tilderadio-dj-admin
cd /usr/share/nginx/tilderadio.org
sudo -u nginx php bin/backup-dj-admin.php /var/backups/tilderadio-dj-admin/admin-2026-10-05.sqlite
```

Run after first initialization; choose a **new filename** each time. The tool
refuses existing files and website paths. It backs up admin records/audit, not
bridge keys, sessions or AzuraCast schedules. Use AzuraCast's backups for its
own data. GUI export is a review format, not a complete database restore.

To restore, take DJ routes offline and stop every PHP-FPM/CLI writer using this
database. Back up current database/WAL files, replace `admin.sqlite` with a
verified standalone backup, and remove old `admin.sqlite-wal`/`admin.sqlite-shm`
only while all database users are stopped. Restore `nginx:nginx` ownership and
`0600` mode. Resume PHP-FPM and review restored permissions before reopening.

For an uncommitted patch rollback:

```sh
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git apply --reverse --check /tmp/tilderadio-site-dj-admin.patch
sudo -u nginx git apply --reverse /tmp/tilderadio-site-dj-admin.patch
sudo -u nginx composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

For committed changes, use normal Git revert. Private data is retained. Original
login code does not enforce new database disabled/deleted records or GUI roles;
keep DJ routes offline during rollback if those access restrictions are required.

## 8. Checks and troubleshooting

Admin writes require HTTPS, session CSRF, allowed Origin when supplied, a fresh
signed bridge check and live administrator access. Prepared statements,
transactions and versions protect local writes. Typed confirmations protect
deletions. Credentials and raw upstream responses are excluded from forms,
exports and logs. Existing cookie hardening, expiry, session rotation, player
navigation and throttling remain.

Activity displays the latest 200 database audit events; full database history
is retained. Monolog files rotate as before. Monitor private state disk usage.
Auth/local access fails closed on private-state failure. Public pages may fall
back to repository metadata on a database read failure, temporarily showing old
file-based profiles; unpublished database-only content is not in that fallback.

For 503, check private logs, Composer autoload and PHP-FPM permissions. For
schedule errors, check TLS, allowlist, actual station/streamer IDs and restricted
API permissions. For 409, reload before re-entering changes; for 429, respect
Retry-After. Do not enable public error display or relax key permissions.

Run in a development checkout:

```sh
composer install --prefer-dist --no-interaction
composer validate --strict
composer lint:auth
composer format:check
composer analyse
composer test
python3 tests/http-auth.py
python3 tests/http-admin.py
```

Tests use isolated private state, a fake bridge and local test TLS API. They
need PHP 8.4+, dependencies, Python 3 and OpenSSL; no production credentials.
CI runs on PHP 8.4 and 8.5. Administrator forms use the existing persistent
player integration, normal POST/redirect fallback and double-submit protection.

An optional DOM regression test covers player preservation through login,
administrator navigation, profile/account/schedule saves, duplicate submissions,
logout and history. In development, with Node.js compatible with jsdom 27.0.1:

```sh
npm install --prefix /tmp/tilderadio-player-tests --ignore-scripts --no-audit --no-fund jsdom@27.0.1
NODE_PATH=/tmp/tilderadio-player-tests/node_modules python3 tests/http-player.py
```

This is a DOM test, not an audio playback or visual browser test. Also verify
normal navigation and the player in your actual browser after deployment.
