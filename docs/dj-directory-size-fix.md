# Fix oversized AzuraCast DJ directory responses

This incremental patch applies to `TildeRadio/site` branch `dj-auth-login`
at `a0f7829587a74131397eefc2d9bbd85716fac64b` (the installed DJ picker).
Apply it on the website server. No AzuraCast Docker changes, configuration
changes, database migrations or new Composer dependencies are required.

## What was failing

AzuraCast returned HTTP 200, but the response for 100 DJs exceeded the client's
2 MiB response limit. The size guard aborted cURL, producing error 23. The old
message incorrectly lumped this failure in with connection failures.

The lookup now requests 25 DJs per page. If a successful HTTP response is still
too large, it halves the page size down to one. It restarts from the first page
after each size change, so records are not skipped or duplicated. Only confirmed
size-limit failures on successful responses trigger a retry.

The 2 MiB per-response limit, 5,000-DJ limit, existing 15-second lookup deadline,
TLS verification, station allowlist, fresh identity verification and private
credential handling remain in place. The page-count bound now supports up to
5,000 one-record pages within the same time and record limits. An oversized
response even when requesting one DJ shows a clear error and preserves manual
entry. An API version that ignores pagination cannot be made to fit merely
by reducing the requested page size.

## Apply

Save the download as `/tmp/tilderadio-site-dj-directory-size-fix.patch`, then:

```sh
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git branch --show-current
sudo -u nginx git status --short
sudo -u nginx git apply --check /tmp/tilderadio-site-dj-directory-size-fix.patch
sudo -u nginx git apply /tmp/tilderadio-site-dj-directory-size-fix.patch
```

Confirm the branch is `dj-auth-login`. Preserve local changes; resolve any
failed patch check before applying. Do not force it. If PHP-FPM has OPcache
timestamp validation disabled, reload the actual PHP-FPM service for this site.

Sign in as deepend and open:

```text
https://tilderadio.org/dj/admin/account.php?refresh=1
```

The refresh clears the existing session directory cache and forces a new lookup.
The Add a DJ dropdown should now show the directory. Existing website DJ records
continue to be edited from the DJs list.

Suggested commit:

```text
fix(admin): load large AzuraCast DJ directories in smaller pages
```

## Verification

Development checks:

```sh
composer validate --strict
composer lint:auth
composer format:check
composer analyse
composer test
python3 tests/http-auth.py
python3 tests/http-admin.py
python3 tests/http-dj-picker.py
```

The picker regression tests include large records, an oversized first page,
an oversized later page, traversal restart without duplicate or missing DJs,
an oversized single record, authorization failures without retries, safe
session caching and the existing account/membership checks. The fixture uses
local TLS and fake credentials; it does not access your production AzuraCast.

## Rollback

For this patch before committing:

```sh
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git apply --reverse --check /tmp/tilderadio-site-dj-directory-size-fix.patch
sudo -u nginx git apply --reverse /tmp/tilderadio-site-dj-directory-size-fix.patch
```

For committed changes, revert the fix commit using your normal Git workflow.
Reload the PHP-FPM service if its OPcache setup requires it.
