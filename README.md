# Gallery media metadata builder and viewer

This project scans JPEG images in `media/`, extracts IPTC and EXIF metadata, writes a JSON index, and serves a browser-based gallery view that loads data from a REST-style API.

## Overview

Main parts:

- `build.php`: scans images and generates `media/media.json`
- `api.php`: GET-only JSON endpoint returning lowercased-key data
- `index.php`: front controller for dynamic routes (`/api`, `/build`) and default list page
- `list.php`: HTML shell for the list/grid UI with cache-busted assets
- `functions.php`: shared PHP helper functions (for example cache-busting URLs)
- `app.js`: client-side data fetch, sorting, metadata extraction, and grid rendering
- `list.css`: grid/layout styling
- `404.css`: styling for the custom 404 page
- `.htaccess`: rewrites non-JPG, non-CSS, non-JS requests to `index.php`

## Requirements

- PHP 8+ recommended
- EXIF extension enabled for EXIF extraction (`exif_read_data`)
- IPTC parsing support (`getimagesize` + `iptcparse`)
- Apache with `mod_rewrite` for the provided `.htaccess` behavior

## Build process

Generate metadata JSON with:

```bash
php build.php
```

What `build.php` does:

1. Recursively scans `media/` for `.jpg` and `.jpeg`
2. Builds each image URL as `/media/<relative-path>`
3. Extracts IPTC and EXIF metadata
4. Normalizes metadata values for safe UTF-8 JSON output
5. Sorts records by URL
6. Writes `media/media.json`

## IPTC transformation details

Raw IPTC tags like `2#101` are transformed into legible keys based on the IPTC Photo Metadata/IIM label map.

Example:

- key: `country_primary_location_name`
- payload:

```json
{
	"iptc_key": "2#101",
	"value": ["Austria"]
}
```

Notes:

- Keys are ASCII snake_case
- Diacritics/special characters are transliterated where possible
- Unknown tags fall back to their raw code-derived key
- `value` remains an array

## API behavior

Endpoint:

- `GET /api` (and `GET /api/` via route normalization)

Rules:

- Only `GET` is allowed
- Non-GET requests return HTTP `405` with `Allow: GET`
- Reads `media/media.json`
- Supports optional filtering via:
    - `month_year` in `yyyy-mm` format (for example `/api?month_year=2024-03`)
    - `country` (for example `/api?country=Scotland`)
    - both together (for example `/api?country=Scotland&month_year=2017-09`)
- Recursively lowercases all associative object keys before output

Example filter combinations:

- `/api`
- `/api?month_year=2017-09`
- `/api?country=Scotland`
- `/api?country=Scotland&month_year=2017-09`

Possible error responses:

- `404` when media JSON is missing/unreadable
- `500` when read/parsing fails

## Routing behavior

`.htaccess` rewrites requests as follows:

- `.jpg/.jpeg`, `.css`, and `.js` are served directly
- all other requests are routed to `index.php`
- direct requests to `functions.php` are denied (`403`)

`index.php` then dispatches:

- `/api` and `/api/` → `api.php`
- `/build` and `/build/` → `build.php`
- `/` and `/?...` → `list.php`
- any other dynamic path → `404.php`

## Frontend list/grid behavior

`list.php` includes versioned assets using file modification timestamps:

- `list.css?v=<filemtime>`
- `app.js?v=<filemtime>`

`app.js` behavior:

1. Fetches `/api`
2. Computes capture timestamp using EXIF fields first:
    - `exif.exif.datetimeoriginal`
    - `exif.exif.datetimedigitized`
    - fallback: `exif.ifd0.datetime`
3. Falls back to IPTC date/time when needed:
    - `iptc.date_created.value[0]`
    - `iptc.time_created.value[0]`
4. Sorts images newest first
5. Renders a flex-based calculated grid inspired by grid500 logic using:
    - width: `exif.computed.width`
    - height: `exif.computed.height`
    - metrics:
        - `flexGrow = width * 100 / height`
        - `flexBasis = width * targetHeight / height`
        - `paddingBottom = height / width * 100`

Rendered overlay content per image:

- title from `iptc.object_name.value[0]`, fallback `Untitled`
- location from IPTC location fields
- comma-separated tags from `iptc.keywords.value`
- formatted capture date/time

### Settings panel

The settings panel is created dynamically in JavaScript (not server-rendered in PHP).

- A `Show captions` checkbox is inserted into the DOM by `app.js`
- A `Month / year` select box is inserted into the DOM by `app.js`
- A `Country / region` select box is inserted into the DOM by `app.js`
- Changing either select triggers a new API request and re-renders the grid
- Both selectors work in tandem and are sent as query parameters (`month_year` + `country`)
- Month/year options are generated from image capture dates and sent as `month_year=yyyy-mm`
- Country/region options are generated from IPTC location metadata
- Captions are hidden by default in CSS
- Captions appear on image hover/focus with a transition
- If `Show captions` is enabled, captions remain visible without hover
- Checkbox state is persisted in `localStorage` using the key `gallery.showCaptions`

## Output data format

`media/media.json` is an array of image records:

- `url`: web path to image
- `iptc`: transformed IPTC object
- `exif`: EXIF sections (for example `FILE`, `COMPUTED`, `IFD0`, `EXIF`, `GPS`)

## Quick test checklist

Use this checklist after deployment or server config changes.

1. Rebuild metadata index:

```bash
php build.php
```

Expected: command succeeds and updates `media/media.json`.

2. Verify API returns JSON:

- Open `/api` in the browser
- Confirm HTTP 200 and JSON array response

3. Verify method restriction:

- Send a non-GET request to `/api`
- Confirm HTTP 405 and `Allow: GET`

4. Verify gallery UI:

- Open `/`
- Confirm status text reports loaded images
- Confirm images are shown in a calculated grid and sorted newest first

5. Verify static assets are direct-served:

- Open `/list.css`, `/404.css`, and `/app.js`
- Confirm all are served directly (not routed JSON/HTML output)

6. Verify front-controller routes:

- Open `/build` to trigger rebuild endpoint
- Open an unknown dynamic path (for example `/something-random`) and confirm custom 404 page

## License

This project is licensed under the GNU General Public License v2.0 (GPL-2.0).

## Author

Mark Howells-Mead, [Say Hello GmbH](https://sayhello.ch/).
