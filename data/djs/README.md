# DJ profiles

Each DJ may have one JSON file named after the slug used by the schedule.

For example, a schedule entry named `deepend` uses:

```text
data/djs/deepend.json
```

and is available at:

```text
/djs/?dj=deepend
```

Only `name` is recommended. Everything else is optional. DJs without a JSON
file still receive a basic profile automatically when they appear in the live
schedule.

Copy `example.json.sample` to `<your-slug>.json` and remove fields you do not
want to publish.

## Editing a profile

From the repository root:

```sh
cp data/djs/example.json.sample data/djs/your-nick.json
$EDITOR data/djs/your-nick.json
php bin/validate-djs.php
```

The validator checks every DJ JSON file by default and exits non-zero if it
finds an error. You can validate one or more specific files too:

```sh
php bin/validate-djs.php data/djs/your-nick.json
```

Keep the change limited to your profile and any site-local avatar you are
adding. Never put DJ/streaming credentials or other secrets in profile JSON.

## Supported fields

- `name`: display name.
- `published`: set to `false` to keep a profile file out of the catalog.
- `tagline`: short one-line summary used on cards and the profile header.
- `bio`: one string or an array of paragraphs.
- `avatar`: an HTTPS image URL or a site-relative path beginning with `/`.
- `pronouns`: optional pronouns.
- `location`: optional general location.
- `tilde`: home tilde/community.
- `irc`: IRC nickname.
- `since`: free-form value such as `2024` or `since the beginning`.
- `links`: either an object of `label: URL` pairs or an array of objects with
  `label` and `url`.
- `show.title`: recurring show name.
- `show.tagline`: short show summary.
- `show.description`: longer show description.
- `show.genres`: array of genre/style tags.
- `favorites.artists`, `favorites.albums`, `favorites.tracks`: arrays of text.
- `notes`: one string or an array of short notes for listeners.

The filename is authoritative for the profile slug. Keep it lowercase and use
letters, numbers, and hyphens only.

Malformed or oversized profile files are skipped and logged instead of taking
the DJ pages down.

## Before submitting

Run `php bin/validate-djs.php` and check your diff. A valid profile may still
produce warnings for unknown fields; warnings do not make validation fail.

If you do not use Git, ask in `#tilderadio` and somebody can help with the
profile update.
