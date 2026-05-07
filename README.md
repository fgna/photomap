# Photo Map

An interactive photo map with a scrollable sidebar, lightbox, and reverse geocoding. Organise photos into trip folders, run one build command, and deploy the output to any static host.

## Quick start

```bash
git clone https://github.com/fgna/photomap.git
cd photomap

# Install Python dependencies
pip install -r requirements.txt

# Add photos (one subfolder per trip)
mkdir -p trips/paris-2024 trips/iceland-2023
cp ~/Photos/paris/*.jpg trips/paris-2024/
cp ~/Photos/iceland/*.jpg trips/iceland-2023/

# Build
python build.py

# Preview locally
python -m http.server -d dist 8080
# → open http://localhost:8080
```

## Where to put photos

Create one subfolder inside `trips/` for each trip. The folder name becomes the trip label (hyphens and underscores are replaced with spaces).

```
trips/
  paris-2024/
    IMG_0001.jpg
    IMG_0002.jpg
    ...
  iceland-2023/
    IMG_1234.heic
    ...
  road-trip/
    DSC_0042.jpg
    ...
```

Supported formats: **JPG · JPEG · WebP · PNG · HEIC · TIFF**

> HEIC requires the optional `pillow-heif` package. Uncomment it in `requirements.txt` and re-run `pip install -r requirements.txt`.

## Build output

Running `python build.py` produces a `dist/` directory:

```
dist/
  index.html              ← deploy this + the trips/ folder
  trips/
    paris-2024/
      .thumbnails/        ← 240 × 240 px square crops (auto-generated)
      .resized/           ← max-2048 px images for the lightbox (auto-generated)
    iceland-2023/
      .thumbnails/
      .resized/
```

Deploy the entire `dist/` folder to any static host — GitHub Pages, Netlify, S3, or a plain Nginx/Apache server.

## Adding photos later

Drop new photos into the relevant `trips/` subfolder (or create a new subfolder) and re-run:

```bash
python build.py
```

The build is **incremental** — it only processes files whose thumbnails are missing or whose source file has changed. Geocoding results are cached in `build-cache.json` across builds so already-geocoded coordinates are never re-queried.

## Build options

```
python build.py [options]

  --trips-dir PATH    Source trips directory          (default: ./trips)
  --output PATH       Output directory                (default: ./dist)
  --title TEXT        Site title shown in the header  (default: "Photo Map")
  --no-geocode        Skip Nominatim reverse geocoding
  --force-thumbs      Regenerate all thumbnails even if up to date
  --cache PATH        EXIF + geocoding cache file     (default: ./build-cache.json)
  --php-file PATH     template.php to extract CSS/JS from (default: ./template.php)
```

## Geocoding

GPS coordinates are automatically reverse-geocoded via [Nominatim](https://nominatim.openstreetmap.org/) (OpenStreetMap) at build time. Results — street names, POI names, town names — appear in the sidebar and lightbox.

- Rate-limited to 1 request per second (Nominatim's policy)
- Results cached in `build-cache.json`; re-running the build never re-queries known coordinates
- Failed lookups are retried after 24 hours
- Skip entirely with `--no-geocode`

## Cache files

| File | Contents | Commit to git? |
|---|---|---|
| `build-cache.json` | EXIF data + geocoding results | No (in `.gitignore`) |
| `dist/` | Generated site | No (in `.gitignore`) |

Keep `build-cache.json` between builds — it avoids re-reading EXIF and re-geocoding on every run. You can delete it to force a full rebuild from scratch.

## Features

- **Interactive map** — Leaflet with marker clustering; each trip gets its own colour; clicking a pin opens the lightbox
- **Sidebar index** — virtual-scroll list (handles thousands of photos without DOM bloat); photos grouped by trip with collapsible sections
- **Trip pill bar** — filter the map and sidebar to a single trip; copy a `?trip=slug` link to share
- **Lightbox** — keyboard-navigable (`←` `→` `Esc`), sliding thumbnail strip, location label
- **Reverse geocoding** — POI/street names fetched at build time and baked into the HTML

## Do I still need `template.php`?

Yes — `build.py` reads `template.php` at build time to extract the CSS and JavaScript for the site. You don't run it as a web server, but it must stay in the repository alongside `build.py`.

## Requirements

- Python 3.9+
- `Pillow >= 10.0` and `requests >= 2.28` (see `requirements.txt`)
- Optional: `pillow-heif` for HEIC support

## License

MIT
