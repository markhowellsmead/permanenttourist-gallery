# Gallery media metadata builder

This project uses [build.php](build.php) to scan JPEG files in [media/](media/) and generate [media/media.json](media/media.json).

## Build command

Run:

```bash
php build.php
```

## Output format

The output file [media/media.json](media/media.json) is a JSON array of image objects.

Each image object has:

- `url`: Web path to the image (for example `/media/example.jpg`)
- `iptc`: IPTC metadata object
- `exif`: EXIF metadata object

### IPTC structure

`iptc` is keyed by a legible IPTC field name (ASCII snake_case derived from IPTC Photo Metadata labels), for example `country_primary_location_name`.

Each IPTC field contains:

- `iptc_key`: Original IPTC tag key (for example `2#101`)
- `value`: The IPTC value(s), kept as an array

Example:

```json
{
  "country_primary_location_name": {
    "iptc_key": "2#101",
    "value": ["Austria"]
  }
}
```

### EXIF structure

`exif` contains the nested sections returned by PHP `exif_read_data`, such as `FILE`, `COMPUTED`, `IFD0`, `EXIF`, and `GPS` when present.
