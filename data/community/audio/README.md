# Community audio metadata

Public contribution guide: <https://tilderadio.org/community/contribute/>

Each TildeRadio station ID or jingle gets its own JSON file in this directory. These are audio clips for the shared TildeRadio station, not separate stations for individual DJs.

The audio file itself belongs in:

```text
community/audio/
```

For example:

```text
data/community/audio/compiled-from-source.json
community/audio/compiled-from-source.ogg
```

Start by copying `example.json.sample`:

```sh
cp data/community/audio/example.json.sample \
   data/community/audio/compiled-from-source.json
```

Then edit the JSON:

```json
{
  "title": "compiled from source",
  "by": "your-nick",
  "file": "compiled-from-source.ogg",
  "description": "A short TildeRadio station ID.",
  "license": "CC BY 4.0",
  "url": "https://example.com/",
  "published": true
}
```

## Fields

`file` is required and must name a file directly inside `community/audio/`.

Everything else is optional:

- `title`: display title. Defaults to the JSON filename.
- `by`: creator / contributor name.
- `description`: one short description shown beside the player.
- `license`: license or reuse terms.
- `url`: optional HTTP(S) source or creator page.
- `published`: set to `false` to keep the entry out of the public shelf.

JSON filenames must use lowercase letters, numbers, and hyphens, for example:

```text
deepend-tilderadio-id.json
```

Supported audio filename extensions are OGG/OGA, Opus, MP3, WAV, and FLAC.

A malformed JSON file, unsafe filename, missing audio file, or oversized metadata
file is skipped and logged instead of breaking the community page.
