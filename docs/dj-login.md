# DJ login installation

For the administrator upgrade on `dj-auth-login`, follow
[dj-administration.md](dj-administration.md). These instructions describe the
original login installation; do not reapply the login patch or replace working
private configuration when upgrading.

This patch adds `/dj/login.php`, an authenticated `/dj/` booth, POST-only sign-out,
and a `dj booth` navigation link. It uses the signed AzuraCast authentication
bridge you already installed and tested. It does not require a change to the
AzuraCast Docker container or Carrier's database.

`deepend` is the website administrator using your verified station ID `1` and
streamer ID `163`. The private configuration grants this role to `1:163`, never
to a submitted username or public DJ profile. A logged-in administrator sees the
administrator badge. This first patch provides authentication and a read-only
booth; show creation/editing, schedule management, and live broadcast controls
are not included. Future privileged actions must check the authenticated role
on the server and require CSRF protection.

## 1. Check prerequisites on the website server

Run these commands on **tilde**, where the website is installed, not in the
AzuraCast Docker container. The website's PHP CLI **and PHP-FPM pool** need PHP
8.4 or newer, cURL, PDO and PDO SQLite. Composer 2 is required to install the
locked dependencies. Production dependencies are Monolog and PSR-3; test tools
are omitted by `--no-dev`.

```sh
cd /usr/share/nginx/tilderadio.org
php -v
php -m
composer --version
php /opt/tilderadio-dj-auth/tools/test-login.php /etc/tilderadio/dj-auth-client.json
```

Your successful `deepend` test already established the bridge connection. Check
that the PHP-FPM worker account is `nginx`; substitute the actual worker user and
group in the ownership commands below if different. Nginx file ownership alone
does not establish PHP-FPM's worker identity. You can inspect running workers:

```sh
ps -eo user,group,args | grep '[p]hp-fpm'
```

If your host CLI and PHP-FPM versions differ, use the CLI matching the website's
PHP-FPM version for Composer. Install missing PHP extensions using your host's
package manager before continuing. The PHP 8.5 in the AzuraCast container does
not determine the website's PHP version.

## 2. Apply the patch

The patch was generated against `TildeRadio/site` master commit
`9759e88b0a0514e6081bbfeb7490ce28e6995402`. Keep your current local changes backed
up and inspect `git status` first. Run the repository operations as its owner
(`nginx` in your listing) to avoid changing its ownership or Git's safe-directory
configuration. These commands assume the patch is saved as
`/tmp/tilderadio-site-dj-login.patch` and readable by `nginx`:

```sh
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git status --short
sudo -u nginx git apply --check /tmp/tilderadio-site-dj-login.patch
sudo -u nginx git apply /tmp/tilderadio-site-dj-login.patch
sudo -u nginx composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

If `git apply --check` fails, stop and resolve the difference; do not force the
patch onto a changed `header.php` or player script. Applying the patch changes
only this local checkout. Commit it and publish it to your repository through
your normal workflow if you want it included in future deployments.

The existing Drone deployment performs only `git pull`. **Install the Composer
dependencies before the first deployment containing this patch**, or update
that deployment to run `composer install --no-dev --prefer-dist --no-interaction
--optimize-autoloader` after the pull, using a PHP 8.4+ CLI. Repeat the install
whenever `composer.lock` changes. Do not run `composer update` in production.
Public pages work without these dependencies; the DJ routes return a generic 503
until dependencies and private configuration are present.

## 3. Create private website state

As root on the website server, create a separate private directory. This is
independent of the bridge's own nonce/rate-limit storage on the AzuraCast host.
These commands are for a **first installation**. Do not replace an existing
`rate-key` on a running installation.

```sh
install -d -o root -g nginx -m 0750 /etc/tilderadio
install -d -o nginx -g nginx -m 0700 /var/lib/tilderadio-dj-auth-site
install -d -o nginx -g nginx -m 0700 /var/lib/tilderadio-dj-auth-site/sessions
umask 077
openssl rand -hex 32 > /var/lib/tilderadio-dj-auth-site/rate-key
chown nginx:nginx /var/lib/tilderadio-dj-auth-site/rate-key
chmod 0600 /var/lib/tilderadio-dj-auth-site/rate-key
```

The application requires mode 0700 for both state directories. Sessions and
SQLite files are created under a 0077 umask. Never put this state directory or
any bridge configuration/key inside the website document root.

## 4. Install the website configuration

The existing `/etc/tilderadio/dj-auth-client.json` is the **bridge client**
configuration, containing the bridge URL and signing credentials. Leave it in
place. This patch adds a **separate** website configuration file:
`/etc/tilderadio/dj-auth-site.json`. It controls the website origin, session
storage, trusted proxy addresses, profile links and administrator IDs.

```sh
install -o root -g nginx -m 0640 \
  /usr/share/nginx/tilderadio.org/config/dj-auth-site.example.json \
  /etc/tilderadio/dj-auth-site.json
```

The supplied example already contains your verified administrator:

```json
{
  "origin": "https://tilderadio.org",
  "base_path": "",
  "client_bootstrap": "/opt/tilderadio-dj-auth/website-client/bootstrap.php",
  "client_config": "/etc/tilderadio/dj-auth-client.json",
  "state_dir": "/var/lib/tilderadio-dj-auth-site",
  "trusted_proxy_ips": [],
  "accounts": {"1:163": "deepend"},
  "administrators": ["1:163"]
}
```

`origin` must be the canonical HTTPS scheme/hostname, with an optional port and
no path. Redirect `www`/other host aliases to that canonical host in Nginx.
`base_path` is empty for your installation. For a subdirectory deployment,
use its path (for example `/radio`) without a trailing slash. The session cookie
is scoped to that deployment's `/dj/` directory.

`accounts` explicitly links a verified `station_id:streamer_id` to the public
DJ/archive slug. Your `cat` account is `1:4`; add `"1:4": "cat"` only if `cat`
is the correct public archive slug. Unmapped DJs can sign in but see a message
asking the administrator to link their profile. No username guessing grants
ownership of archived shows. The public archive remains public.

The administrator configuration is editable only by the server administrator,
not through this booth. If an AzuraCast account is deleted/recreated, verify its
new ID and update the configuration. Remove stale administrator/profile IDs
before assigning replacement accounts. Config changes take effect on the next
request; no website restart is required.

The full previously installed package must remain at `/opt/tilderadio-dj-auth/`:
its client bootstrap loads shared files from its sibling `plugin` directory.
PHP-FPM must be able to read that package and the private client JSON, including
any key paths that client JSON references. Give PHP-FPM read access without
making those secrets world-readable.

For a different site config path, set `TILDERADIO_DJ_SITE_CONFIG` in the PHP-FPM
pool environment. Setting it only in your interactive shell does not affect
PHP-FPM. The default path above requires no environment change.

## 5. HTTPS and Nginx

Serve the booth through your existing PHP-FPM handler. Confirm the directory
index includes `index.php`. Direct TLS termination by this Nginx host should
pass the standard `HTTPS` FastCGI parameter (normally supplied by the usual
`fastcgi_params`/`fastcgi.conf` include). Do not declare every request HTTPS if
the origin server also accepts plain HTTP.

When Nginx receives TLS directly, keep `trusted_proxy_ips` empty. The application
ignores forwarded protocol/IP headers from untrusted peers. If a separate
reverse proxy terminates TLS, configure only its exact IP addresses and ensure
it overwrites `X-Forwarded-Proto` and `X-Real-IP` with the actual scheme/client
IP. Restrict direct origin access so clients cannot impersonate that proxy.
Comma-separated forwarded headers are not accepted. For real-IP processing
performed by Nginx itself, leave this list empty and use its correctly restored
`REMOTE_ADDR`/TLS FastCGI values.

Add the following restrictions inside your existing Nginx `server` block,
**before** a general regex PHP handler. This protects dependency/test/source
directories from direct requests; the test bridge must never be a publicly
executable endpoint. If deployed under a subdirectory, prefix these patterns
with that subdirectory.

```nginx
location ~ ^/(?:vendor|tests|docs|config|lib/DjAuth)(?:/|$) {
    return 404;
}
location = /dj/_bootstrap.php {
    return 404;
}
location ~ ^/(?:composer\.(?:json|lock)|phpunit\.xml|phpstan\.neon)$ {
    return 404;
}
```

Keep the server's existing rule denying hidden files (such as `.git` and `.env`).
Do not replace your server's PHP-FPM socket or its stream proxy locations with
these snippets. Validate and reload the configuration:

```sh
nginx -t
systemctl reload nginx
```

Use the CLI equivalent `nginx -s reload` if your host does not use systemd.

## 6. SELinux, if enabled

Your host listing suggests SELinux may be enabled. Check `getenforce`. If it is
enforcing, label the new private site configuration for PHP read access and
only the new state directory for PHP write access. As root, with the SELinux
management tools installed, add these new path rules:

```sh
semanage fcontext -a -t httpd_sys_content_t '/etc/tilderadio/dj-auth-site\.json'
semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/tilderadio-dj-auth-site(/.*)?'
restorecon -v /etc/tilderadio/dj-auth-site.json
restorecon -Rv /var/lib/tilderadio-dj-auth-site
restorecon -Rv /usr/share/nginx/tilderadio.org
```

If the exact rules already exist, use `semanage fcontext -m` to modify them
instead of adding duplicate rules. Preserve the bridge package/client config
labels and PHP outbound-HTTPS policy you established when installing the client.
The CLI bridge test does not verify PHP-FPM's SELinux access; check the PHP-FPM
and SELinux audit logs if browser login fails. Do not disable SELinux or set
state/secrets to mode 777.

## 7. Test in the browser

1. Open `https://tilderadio.org/dj/`. You should reach the login form.
2. Sign in as `deepend` with the DJ streaming password. You should see
   `Administrator`, `Hello, deepend.`, and the linked public profile.
3. Sign out and revisit `/dj/`. It should require login again.
4. Sign in as `cat`. It must not display the administrator badge. Add its
   verified account/profile mapping if desired.
5. Try a wrong password. The form should show a generic failure and never
   refill the password field.
6. Start the radio player, visit `dj booth`, and sign in/out. Playback should
   continue. With JavaScript disabled, the ordinary forms still work.
7. Confirm `/tests/fixtures/client.php` and `/vendor/` return 404 after the Nginx
   restrictions are installed. Confirm the private `/etc` and `/var/lib` files
   are outside the document root.

Public pages do not start DJ sessions or set the DJ cookie. Login/logout form
posts are integrated with the site's existing player navigation only while the
audio is playing, so the existing audio element survives navigation.

## Session and security behavior

- Secure, HttpOnly, SameSite=Lax host-only cookie, restricted to `/dj/`.
- PHP strict session mode and session ID rotation at login/expiry/revocation.
- CSRF token required for login and logout, with an additional Origin check
  when the browser sends it. Logout cannot be triggered by a GET link.
- No password in the website session, database, logs or repository. Passwords
  are submitted to the existing bridge over its signed, verified HTTPS transport.
- Persistent SQLite rate limiting shared across PHP workers, using prepared
  statements and HMAC identifiers instead of raw IPs/usernames in throttle rows.
- Idle expiry after 15 minutes; absolute expiry after 60 minutes even with
  activity. Active-account rechecks on the next protected request at least
  60 seconds after the previous check. Deactivation is therefore noticed on
  the first request requiring that recheck, not asynchronously in the browser.
- A required recheck failure blocks private content. An outage returns 503;
  a revoked account clears the authenticated session. Sign-out does not make
  an authentication network request.
- Password changes alone do not revoke existing sessions through the bridge's
  active-account check. Sessions still expire at the limits above. For immediate
  invalidation of every DJ session, as root remove the session files below; this
  requires everyone to sign in again:

```sh
find /var/lib/tilderadio-dj-auth-site/sessions -maxdepth 1 -type f -name 'sess_*' -delete
```

Private responses use `Cache-Control: no-store`, generic failures, escaping and
anti-framing headers. The CSP intentionally preserves the site's existing
player/inline scripts; it is not a complete site-wide script allowlist.

Structured JSON audit logs live at
`/var/lib/tilderadio-dj-auth-site/audit-YYYY-MM-DD.log`. They record successful
verified station/account IDs and generic failure reasons without credentials,
raw IPs or request bodies. Monolog retains up to seven rotated log files; check
disk usage and use your normal server monitoring. The rate key and state should
stay private and should not be uploaded to Git.

## Updates and troubleshooting

This patch lives in the website repository and host-private directories. Normal
AzuraCast Docker updates do not remove it. The bridge's AzuraCast-specific
adapter can still need maintenance if an AzuraCast update changes internal
authentication APIs. After an update, repeat the existing `test-login.php`
command and the browser sign-in/recheck test. Authentication fails closed while
the bridge is unavailable.

For a generic 503, check PHP-FPM's error log and the private JSON audit log.
Confirm Composer's `vendor/autoload.php` exists, PHP-FPM has the required version
and extensions, all private paths are readable/writable as appropriate, the two
state directories are mode 0700, and HTTPS detection is correct. Do not enable
public error display or paste secret JSON/key files into support messages.

To remove this patch after applying it, first verify the reverse applies to your
current files:

```sh
sudo -u nginx git apply --reverse --check /tmp/tilderadio-site-dj-login.patch
sudo -u nginx git apply --reverse /tmp/tilderadio-site-dj-login.patch
```

For a committed deployment, use your normal Git revert workflow instead. The
private bridge installation and website state are not deleted by this rollback.

## Development verification

Run from a development checkout, not the production website. The HTTP tests use
a temporary fake bridge and a one-day test certificate bound to localhost. They
do not contact your real AzuraCast installation or require a real DJ password.

```sh
composer install --prefer-dist --no-interaction
composer validate --strict
composer lint:auth
composer format:check
composer analyse
composer test
python3 tests/http-auth.py
```

The checks cover station/account role isolation, untrusted forwarded headers,
private state permissions, persistent throttling, CSRF rejection before bridge
calls, unmodified passwords, session expiry, cookie flags, session rotation,
logout, escaping, outage/revocation and public-page cookie behavior. PHPStan
checks the new authentication classes; lint covers the routes, fixtures and
changed header. The GitHub workflow runs these checks on PHP 8.4 and 8.5.
