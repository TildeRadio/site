# DJ booth layout

This incremental patch targets `dj-auth-login` at
`28e4fae121f2bf3754c528ff14585496efcd695e` (`block conflicting scheduling`).

## Changes

- The booth groups broadcasts, profile editing and scheduling into three action
  cards, with an account/role indicator, recent broadcast dates, and a separate
  assigned-station panel. Existing public-profile, archive, schedule,
  administration and sign-out links remain available.
- Broadcast listings use responsive cards with title, host, UTC start time,
  status and edit/delete or restore actions. Administrator deletion filtering
  and pagination continue to work.
- Editors have a consistent header/navigation and a My schedules shortcut.
  Short fields use two columns on desktop; long text and JSON retain the full
  width. Profile link labels and URLs are grouped together. Phone layouts use
  one column, and long help text wraps without widening the page.
- The login form uses the same panel styling as the DJ controls.
- The administrator overview now explains that DJs can edit their assigned
  profiles, schedules and broadcast listings. It no longer says DJ self-service
  will be added later or that all editing requires an administrator.

Permissions, POST destinations, CSRF/session handling, input names, advanced
JSON editors, stored records, schedule conflict protection and audio-player
behavior are retained. No credentials, configuration, dependencies or database
changes are needed.

## Apply

Save the patch as `/tmp/tilderadio-site-dj-layout.patch`:

```bash
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git branch --show-current
sudo -u nginx git apply --check /tmp/tilderadio-site-dj-layout.patch
sudo -u nginx git apply /tmp/tilderadio-site-dj-layout.patch
```

The branch should be `dj-auth-login`. Do not force the patch if the check fails.
Refresh the browser's cached CSS after deployment (Ctrl+Shift+R). Purge the two
DJ CSS assets from any CDN cache if your setup caches them there. If PHP-FPM's
OPcache does not check file timestamps, reload your normal PHP-FPM service.

## Verify and roll back

Check the booth as an assigned DJ and as an administrator. Check profile and
broadcast saves, deletion/restoration, scheduling, sign-out and the current
administrator wording. The normal project's existing lint, formatting,
PHPStan, PHPUnit and HTTP suites remain applicable.

```bash
cd /usr/share/nginx/tilderadio.org
sudo -u nginx git apply --reverse --check /tmp/tilderadio-site-dj-layout.patch
sudo -u nginx git apply --reverse /tmp/tilderadio-site-dj-layout.patch
```

This reverts the presentation and wording changes; saved records are retained.
Refresh cached CSS and reload PHP-FPM if needed after rollback.
