# Select DJs by username

This incremental patch applies **after the administrator patch**, to
`TildeRadio/site` branch `dj-auth-login` at commit
`39dea512808636fc102d979c54917feff8a2d161` (“add admin functionality”).
It preserves login, roles, profile links, station memberships, schedule editing,
deletion/restoration, archive data and the persistent player.

## Install on the website server

Save the new patch as `/tmp/tilderadio-site-dj-picker.patch`. Preserve local
changes and run as the repository owner:

```sh
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git branch --show-current
sudo -u nginx git status --short
sudo -u nginx git apply --check /tmp/tilderadio-site-dj-picker.patch
sudo -u nginx git apply /tmp/tilderadio-site-dj-picker.patch
sudo -u nginx composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

Confirm the branch is `dj-auth-login`. Resolve a failed patch check before
applying; do not force it. There are no new dependencies, schema migrations
or AzuraCast Docker changes. Composer refreshes the optimized class map for
the added class. Reload your actual PHP-FPM pool if OPcache timestamp validation
is disabled. Commit using your usual workflow, for example:

```text
feat(admin): select AzuraCast DJs by username
```

The existing Nginx restriction for `lib/Admin` covers the new source file.

## API configuration

The picker reuses the **same private `schedule_api` configuration and API key**
as schedule editing. Do not create another key or replace working configuration.
The API user needs station-scoped permission to view management and manage
streamers for the configured stations.

If configuring that connection for the first time, follow
[dj-administration.md](dj-administration.md#6-optional-live-schedule-editing).
The complete configuration is one JSON object: put `schedule_api` inside
its closing brace, with a comma after the preceding field. The API key file
contains only the actual AzuraCast API key on one plain-text line, without
quotes, JSON or a Bearer prefix. Preserve its private permissions.

## Use it

1. Sign in as deepend and open **Administration → DJs → Add a DJ**.
2. Select the DJ's **username** from the AzuraCast DJ dropdown.
3. Choose website access, role and public profile as before.
4. Keep **Use the verified authentication DJ** in the station assignment,
   or select the intended DJ username in that station.
5. Save. The website records the verified numeric IDs automatically.

The authentication station is taken from your verified administrator login.
The current bridge authenticates its configured station; this patch does not
introduce multi-station authentication. Website station records and the private
API station allowlist still govern which additional station directories can
be loaded. Add station records using the existing Stations page when needed.

For a DJ already registered on the website, use its **Edit** link under DJs.
Registered identities are disabled in the Add dropdown to avoid duplicates.
For a deleted account, use **Deleted records → Restore / edit**. Inactive
AzuraCast accounts appear marked inactive and cannot be newly selected.

Existing identity pairs remain immutable. Existing assignments remain selected,
including IDs missing/inactive in the current directory, until you change them.
Choosing another station's username saves that station's actual streamer ID;
the website never guesses associations by matching names. Multiple DJs may
share a profile or schedule account when explicitly assigned by an administrator.

Names are cached in your private session for up to 60 seconds. Choose
**Refresh DJ names from AzuraCast** to reload them. The dropdown uses a native
HTML select with keyboard navigation; no new browser JavaScript is needed.

## Fallback and security

**Use manual ID entry** retains the original numeric fields. It also bypasses
directory lookup when explicitly selected. If configuration, permissions, TLS
or the directory response fails, the form automatically shows the manual
fallback; administrator access itself does not depend on successful lookup.
Existing manual form submissions and first-login registration continue working.

Directory listing is a read-only API operation. Only ID, username, display name
and active status are retained. API keys, passwords, comments, schedule data
and upstream URLs are never rendered or put into the session directory cache.
Pagination uses locally constructed URLs, never upstream pagination links.
Limits prevent unbounded response size, page traversal and lookup time.

Adding a selected identity requires a fresh API check and the existing signed
authentication bridge check. The username and station/streamer pair must agree.
A changed picker assignment is checked again in its actual station. Existing
unchanged assignments need no extra API verification, preserving website-only
edits when a streaming account is inactive. All saves still require CSRF,
fresh administrator identity verification, current role, validation and
record-version checks.

Verify the picker against your live AzuraCast installation after applying.
Tests use a local TLS fixture; they do not access your production credentials.
For a lookup error, inspect private logs and check your API key permissions,
station allowlist and TLS certificate. The original fallback remains available.

## Verification and rollback

In a development checkout:

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
```

The picker tests cover pagination and flat responses, escaping, cache behavior,
inactive/changed accounts, signed identity agreement, actual station-specific
assignments, access checks, malformed responses, redirects, missing configuration
and manual fallback. The optional existing `tests/http-player.py` also covers
adding a DJ by username without replacing the original audio element.

For an uncommitted rollback:

```sh
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git apply --reverse --check /tmp/tilderadio-site-dj-picker.patch
sudo -u nginx git apply --reverse /tmp/tilderadio-site-dj-picker.patch
sudo -u nginx composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

For committed changes use Git revert. Existing accounts, profile links,
assignments and schedules are retained; setup returns to the previous manual
ID form.
