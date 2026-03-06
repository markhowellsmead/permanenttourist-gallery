# Gallery media metadata builder and viewer

This project scans JPEG images in `media/`, extracts IPTC and EXIF metadata, writes a JSON index, and serves a browser-based gallery view that loads data from a REST-style API.

## Overview

Main parts:

- `build.php`: scans images and generates `media/media.json` with complete metadata
- `api.php`: GET-only JSON endpoint returning flattened data structure
- `index.php`: front controller for dynamic routes (`/api`, `/build`) and default list page
- `list.php`: HTML shell for the list/grid UI with cache-busted assets
- `functions.php`: bootstrap file which registers autoloading for namespaced classes and loads `.env` file
- `app.js`: client-side data fetch, sorting, metadata extraction, and grid rendering
- `list.css`: grid/layout styling
- `404.css`: styling for the custom 404 page
- `src/`: class files under namespace `PT\Gallery`
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
5. Compares against existing `media/media.json` to identify new images
6. Sorts records by URL
7. Writes `media/media.json`
8. Logs newly added images to monthly log files in `logs/YYYY-MM.log`

### Monthly image logging

New images are tracked by comparing URLs against the previous JSON. Each new image is logged with a timestamp to `logs/YYYY-MM.log`:

```
[2026-03-06 14:30:45] Added: /media/2026/photo1.jpg
[2026-03-06 14:30:45] Added: /media/2026/photo2.jpg
```

When new images are detected, the build script will report:

```
Wrote 11 image records to /path/to/media/media.json
Logged 2 new image(s) to /path/to/logs/2026-03.log
```

If no new images are found (all images were already in the JSON), only the total count is reported and no log file is created/updated.

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
- Reads `media/media.json` (which contains complete IPTC/EXIF metadata)
- Filters to only essential fields used by frontend
- Flattens nested IPTC/EXIF structure into simple top-level properties
- Supports optional filtering via:
    - `month_year` in `yyyy-mm` format (for example `/api?month_year=2024-03`)
    - `country` (for example `/api?country=Scotland`)
    - both together (for example `/api?country=Scotland&month_year=2017-09`)

Example filter combinations:

- `/api`
- `/api?month_year=2017-09`
- `/api?country=Scotland`
- `/api?country=Scotland&month_year=2017-09`

### Flattened API response structure

Each image in the API response has these top-level fields:

- `url`: image URL path
- `title`: image title from IPTC object_name
- `country`: country from IPTC
- `sublocation`: sublocation from IPTC
- `city`: city from IPTC
- `state_province`: state/province from IPTC
- `date_created`: IPTC date in YYYYMMDD format
- `time_created`: IPTC time in HHMMSS format
- `keywords`: array of keyword strings from IPTC
- `datetime_original`: EXIF DateTimeOriginal timestamp
- `datetime_digitized`: EXIF DateTimeDigitized timestamp
- `datetime`: EXIF DateTime timestamp
- `width`: image width in pixels
- `height`: image height in pixels

This flattened structure simplifies frontend code and reduces payload size by ~62% compared to the nested structure.

Possible error responses:

- `404` when media JSON is missing/unreadable
- `500` when read/parsing fails

## Routing behavior

`.htaccess` rewrites requests as follows:

- `.jpg/.jpeg`, `.css`, and `.js` are served directly
- all other requests are routed to `index.php`
- direct requests to `functions.php` are denied (`403`)
- direct requests to files in `src/` are denied (`403`)

`index.php` then dispatches:

- `/api` and `/api/` → `api.php`
- `/build` and `/build/` → `build.php`
- `/update` and `/update/` → `update.php`
- `/` and `/?...` → `list.php`
- any other dynamic path → `404.php`

## Update endpoint

The `/update` endpoint provides a way to pull the latest code from the git repository.

**Endpoint:** `GET /update?token=YOUR_SECRET_TOKEN`

**Authentication:** Requires a secret token passed as a query parameter.

**Configuration:**

Set the `UPDATE_TOKEN` in your `.env` file:

```dotenv
UPDATE_TOKEN=your_secure_random_token
```

Or set it as an environment variable in your web server configuration. See `.env.example` for a reference configuration.

Generate a secure token:

```bash
openssl rand -hex 32
```

**Usage example:**

```bash
curl "https://yourdomain.com/update?token=your_secret_token"
```

**Response format:**

Success (HTTP 200):
```json
{
  "success": true,
  "message": "Update completed successfully.",
  "output": [
    "Already up to date."
  ],
  "timestamp": "2026-03-06 14:30:45"
}
```

Failure (HTTP 500):
```json
{
  "success": false,
  "error": "update_failed",
  "message": "Update failed. Details have been logged.",
  "timestamp": "2026-03-06 14:30:45"
}
```

Error responses:
- `403` when token is missing or invalid
- `405` when request method is not GET
- `500` when git pull fails or directory change fails (details logged to `logs/update-errors.log`)

**Error logging:**

When an update fails, detailed error information (exit code, git output) is logged to `logs/update-errors.log` but not exposed in the API response. This prevents leaking sensitive information while maintaining debugging capabilities.

Example log entry:
```
[2026-03-06 14:30:45] Update failed (exit code: 1)
Directory: /path/to/project
Output:
fatal: not a git repository
--------------------------------------------------------------------------------
```

**Security notes:**

- Keep the token secret and use a cryptographically random value
- Consider restricting access by IP address in your web server configuration
- Review `logs/update-errors.log` regularly to detect unauthorized or failed attempts
- Ensure the `logs/` directory has appropriate write permissions

## Frontend list/grid behavior

`list.php` includes versioned assets using file modification timestamps:

- `list.css?v=<filemtime>`
- `app.js?v=<filemtime>`

`app.js` behavior:

1. Fetches `/api` (receives flattened data structure)
2. Computes capture timestamp using EXIF fields first:
    - `datetime_original`
    - `datetime_digitized`
    - fallback: `datetime`
3. Falls back to IPTC date/time when needed:
    - `date_created`
    - `time_created`
4. Sorts images newest first
5. Renders a flex-based calculated grid inspired by grid500 logic using:
    - width: `width`
    - height: `height`
    - target height: set as CSS custom property `--grid-target-height` on `<body>`
        - `320px` by default
        - `420px` when viewport width is greater than `1920px`
        - dynamically updated via resize handler without re-rendering the grid
    - metrics:
        - `ratio = width / height`
        - `flexGrow = ratio * 100`
        - `paddingBottom = height / width * 100`
        - each item sets `--item-ratio` CSS custom property
        - `flexBasis = calc(var(--grid-target-height, 320px) * var(--item-ratio))`

Rendered overlay content per image:

- title from `title`, fallback `Untitled`
- location from `country`, `state_province`, `city`, `sublocation` fields
- comma-separated tags from `keywords` array
- formatted capture date/time

### Settings panel

The settings panel is created dynamically in JavaScript (not server-rendered in PHP).

- A `Show captions` checkbox is inserted into the DOM by `app.js`
- A `Month / year` select box is inserted into the DOM by `app.js`
- A `Country / region` select box is inserted into the DOM by `app.js`
- A `Reset filters` button is inserted into the DOM by `app.js`
- Changing either select triggers a new API request and re-renders the grid
- Both selectors work in tandem and are sent as query parameters (`month_year` + `country`)
- Month/year options are generated from image capture dates and sent as `month_year=yyyy-mm`
- Country/region options are generated from IPTC location metadata
- The reset button clears both filters and is automatically hidden when no filters are active

### Caption behavior

Each image has two caption states:

- **Main caption** (title, location, tags, date):
  - Hidden by default
  - Appears on hover/focus with a smooth transition
  - Remains visible when `Show captions` is enabled
  - Checkbox state is persisted in `localStorage` using the key `gallery.showCaptions`

- **Secondary caption** (date and location):
  - Visible by default when main caption is not shown
  - Automatically hidden on hover/focus or when `Show captions` is enabled
  - Provides quick context without hovering

## Data storage format

### media.json (complete metadata)

`media/media.json` stores complete metadata as an array of image records:

- `url`: web path to image
- `iptc`: transformed IPTC object with all tags
- `exif`: EXIF sections (for example `FILE`, `COMPUTED`, `IFD0`, `EXIF`, `GPS`)

This file contains all extracted metadata for potential future use.

### API response (filtered and flattened)

The `/api` endpoint reads `media.json` but returns only the fields needed by the frontend in a simplified flat structure (see "Flattened API response structure" above). This reduces bandwidth while preserving complete data in storage.

## Development

### Dependencies

Install development dependencies using Composer:

```bash
composer install
```

This installs:
- **PHPStan**: Static analysis tool for PHP code quality and compatibility checking

### Environment Configuration

The application automatically loads environment variables from a `.env` file in the project root. This file is loaded by `functions.php` on every request using a built-in parser.

Configure environment variables:

```bash
cp .env.example .env
```

Edit `.env` and set your values:

```dotenv
UPDATE_TOKEN=your_secure_random_token_here
```

Available environment variables:

- `UPDATE_TOKEN`: Secret token for the `/update` endpoint (required for security)

Generate a secure token:

```bash
openssl rand -hex 32
```

**Note:** The `.env` file should not be committed to version control (it's in `.gitignore`). Use `.env.example` as a template for required variables.

**Alternative:** You can also set environment variables directly in your server configuration (Apache, nginx) instead of using a `.env` file.

### Code Quality

#### PHPStan Analysis

Run PHPStan to check for PHP 8.4.18 compatibility and type safety:

```bash
./vendor/bin/phpstan analyze
```

The project is configured with:
- **Level 8**: Strictest analysis level
- **PHP 8.4.18 compatibility**: Ensures code works with PHP 8.4.18 (`phpVersion: 80418`)
- **Type annotations**: All methods include proper PHPDoc type hints

Configuration: `phpstan.neon`

#### Code Formatting

Format PHP code according to PSR-2 standards:

```bash
phpcbf --standard=phpcs.xml .
```

Check for coding standard violations:

```bash
phpcs --standard=phpcs.xml .
```

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

7. Verify update endpoint (if configured):

- Request `/update` without token and confirm HTTP 403
- Request `/update?token=YOUR_TOKEN` with valid token and confirm HTTP 200 with git pull output
- Send a POST request to `/update?token=YOUR_TOKEN` and confirm HTTP 405

## License

This project is licensed under the GNU General Public License v2.0 (GPL-2.0).

## Author

Mark Howells-Mead, [Say Hello GmbH](https://sayhello.ch/).
