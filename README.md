# Photo Map

An interactive photo map with a scrollable sidebar, lightbox, and reverse geocoding. Organise photos into trip folders, run one build command, and deploy the output to any static host.

## Docker

A pre-built image is published to the GitHub Container Registry on every push to `main`:

```bash
docker pull ghcr.io/fgna/photomap:main
```

Run the builder by mounting your `trips/` folder and an output directory:

```bash
docker run --rm \
  -v /path/to/trips:/photomap/trips \
  -v /path/to/dist:/photomap/dist \
  ghcr.io/fgna/photomap:main
```

To persist the geocoding cache across runs (avoids re-querying Nominatim):

```bash
docker run --rm \
  -v /path/to/trips:/photomap/trips \
  -v /path/to/dist:/photomap/dist \
  -v /path/to/build-cache.json:/photomap/build-cache.json \
  ghcr.io/fgna/photomap:main
```

Pass extra options after the image name:

```bash
docker run --rm \
  -v /path/to/trips:/photomap/trips \
  -v /path/to/dist:/photomap/dist \
  ghcr.io/fgna/photomap:main --title "Europe 2024" --no-geocode
```

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
python3 build.py

# Preview locally
python3 -m http.server -d dist 8080
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
  --assets PATH       Directory with style.css and app.js  (default: ./assets)
```

## FTP deploy

Pass FTP credentials to upload `dist/` to your web server immediately after building:

```bash
python build.py --ftp-host ftp.example.com --ftp-user myuser --ftp-dir /public_html
# Password is prompted securely if not provided
```

Or supply the password via environment variable to avoid the prompt (useful in scripts):

```bash
FTP_PASSWORD=secret python build.py --ftp-host ftp.example.com --ftp-user myuser --ftp-dir /public_html
```

| Flag | Description |
|---|---|
| `--ftp-host HOST` | FTP server hostname |
| `--ftp-user USER` | FTP username |
| `--ftp-password PASS` | FTP password (or use `FTP_PASSWORD` env var) |
| `--ftp-dir PATH` | Remote directory to upload into (default: `/`) |
| `--ftp-tls` | Use FTPS (FTP over TLS) for encrypted transfer |

The entire `dist/` directory is uploaded, creating remote subdirectories as needed.

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

## Requirements

- Python 3.9+
- `Pillow >= 10.0` and `requests >= 2.28` (see `requirements.txt`)
- Optional: `pillow-heif` for HEIC support

## License

MIT
