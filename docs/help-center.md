# Help & guides

The public `/help/` area is the starting point for DJs, listeners and administrators.
It contains task guides for getting on air, planning a show, using Carrier, fixing
archive listings and understanding optional services. The old `/djinfo/` and
`/community/carrier/` addresses display their corresponding guides from the same
catalog. Their existing section anchors remain available.

## Reading and searching

- The landing page groups guides by topic, with links to the first-show guide, streaming setup and Carrier commands.
- Browse eight topics or filter for DJs, listeners or administrators.
- Search titles, summaries, instructions and command examples. All search words
  must occur; title/summary/keyword matches rank ahead of incidental body matches.
- Results include a link to the most relevant section. Search/filter selections
  are preserved when opening a result and returning to results.
- Article search, topic navigation and the table of contents can be opened when
  needed; the instructions remain the main content on a small screen.
- Search is a normal GET form. It works without JavaScript. While the shared audio
  player is playing, the existing navigation script preserves it during searches
  and guide navigation. Cross-page section fragments are preserved too.
- Login, the booth, editors, community pages and API reference link to relevant help.

The help role filter is a reading preference, not a permission change. Existing
DJ/admin authorization and account checks still apply to every editing action.
The Help page uses the existing DJ-page CSP so persistent navigation can still
run the legacy scripts on other website pages. Search input and catalog text are
escaped; search does not execute user-provided code.

Help does not call AzuraCast or Carrier, open a DJ session, or read private site
configuration. It remains readable when those services are unavailable.

## Content covered

The 29 guides include website login, profile editing, station assignments,
schedule overlaps/timezones, preparations and ordered playlists, confirmed song
starts, verified IRC linking, per-broadcast song announcements, live episode
metadata, listener requests/questions/goals/polls, handoffs, recordings, podcast
feeds, archive corrections/deletion/restore, channel subscriptions, bot commands,
games, contributions, administrator workflows and troubleshooting.

Examples use the default `!` prefix. Network prefixes and optional services follow
the bot/server configuration. Main/test connection settings retain the existing
handbook values; administrators should review them when ports or stations change.

## Updating guides

`data/help.json` is the single source for public help. Edit it normally in Git.
No generated search index or database migration is required.

Each article has an immutable `id`, `title`, `summary`, `category`, `audience`,
`keywords`, `sections` and `related` article IDs. Categories are declared at the
start of the file. Each section has an `id`, `title` and `blocks`.

Supported blocks are:

| Type | Required content |
| --- | --- |
| `paragraph` | `text` |
| `steps`, `list` | `items`: strings |
| `code` | `text`, displayed literally |
| `table` | `headers`: strings; `rows`: arrays with the same number of cells |
| `links` | `items`: objects with `label` and `href` |

Text is escaped, not interpreted as HTML or Markdown. Use a links block for links.
Internal paths are relative to the website root, for example `dj/plans.php` or
`help/?topic=playlist#advance`. External links must use HTTPS. Keep old article and
section IDs when renaming titles so bookmarks continue to work. Update tests when
adding/removing an article or an intentionally preserved legacy section.

Search is local to the help catalog and includes its command reference. It does
not search private playlists, account records, broadcast metadata or every file
under `docs/`. Operator deployment documentation remains versioned in `docs/`;
public guides link to it where appropriate. Never put private keys, configuration,
passwords or real linking codes in this public catalog.

## Install the Help update

The complete Help replacement patch was built against TildeRadio/site `master` at
`d521d4386a5282000c1e34079e5bedce79ea0c45`, including the merged song-announcement
feature. If the earlier Help patch is already installed, apply `tilderadio-site-copy-layout-cleanup.patch` instead. Apply only one patch. The cleanup changes presentation and guide text; it retains the existing routes, forms and access checks.

Apply it to the matching checkout/current branch. Start with
`git status --short` and `git apply --check`; review any unrelated local edits or
failed hunks before applying. Do not use `--reject` or overwrite a failed hunk.

In a source checkout:

```sh
git apply --check /path/to/tilderadio-site-help-replacement.patch
git apply /path/to/tilderadio-site-help-replacement.patch
composer dump-autoload --no-interaction
php bin/lint-dj-auth.php
```

With development dependencies installed:

```sh
composer test
composer analyse
composer format:check
python3 tests/http-help.py
python3 tests/http-auth.py
python3 tests/http-admin.py
python3 tests/http-dj-self-service.py
# Optional: Node.js plus jsdom must be available.
python3 tests/http-player.py
```

No new Composer dependency, database migration, private configuration, Carrier
restart or AzuraCast change is required. Rebuilding the existing autoloader updates
the new Help namespace. The public route also loads its Help classes directly so
public instructions are not dependent on a private authentication bootstrap.

For the existing nginx installation at `/usr/share/nginx/tilderadio.org`, use the
step-by-step `DEPLOY.md` shipped with the download. Apply as the repository owner
or restore ownership/read permissions on only the patched files after a root
apply. A root umask of `077` can otherwise make new source files unreadable by PHP.
Reload PHP-FPM after deployment if OPcache needs refreshing and hard-refresh the
browser to load the updated navigation script.

## Acceptance check and code rollback

Open `/help/`, search `!songs`, `!track` and `overlap`, then follow an article and its
section links. Check a phone-sized view. With the player running, navigate between
help and the booth and submit a help search. Confirm that the player continues.
Check `/djinfo/#testing` and `/community/carrier/#station` bookmarks.

Sign in as an ordinary DJ and confirm existing profile, assigned-schedule and
owned-broadcast editors still work; check administrator navigation separately.

For an uncommitted patch, use `git apply --reverse --check` before reversing the
same patch, rebuild the autoloader and refresh PHP-FPM. For a committed/merged
change, revert the help commit through the normal source workflow. Do not restore
an old database as part of a code rollback: this patch does not modify one, and
that would discard newer show/account edits.
