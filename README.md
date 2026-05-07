# Photo Map

A single-file PHP app that reads photos from a local `images/` folder, extracts GPS EXIF data, and renders an interactive map with a scrollable photo index.

![Screenshot placeholder](https://via.placeholder.com/900x500?text=Photo+Map)

## Features

- **Interactive map** — Leaflet with marker clustering; clicking a pin opens the photo in a lightbox
- **Sidebar index** — virtual-scroll list (handles 1500+ photos without DOM bloat); shows date/time and location per photo
- **Reverse geocoding** — POI names (e.g. "Alhambra") or street names fetched via Nominatim and cached on the server
- **Server-side thumbnails** — PHP GD generates 240 × 240 px square crops on demand, cached in `.thumbnails/`; markers, sidebar rows, and the lightbox strip use thumbnails, never full-res
- **Lightbox** — keyboard-navigable (`←` `→` `Esc`), sliding thumbnail strip, full-resolution image
- **Disk cache** — EXIF data and geocoding results stored in `.photomap-cache.json`; warm loads skip all file I/O
- **Async data load** — HTML page paints immediately; photo data is fetched via `?api=photos` after first paint
- **HTTP caching** — thumbnails served with `Cache-Control: immutable`; API responses with `stale-while-revalidate`

## Requirements

- PHP 8.0+ with the `exif` and `gd` extensions enabled
- A web server (Apache, Nginx, `php -S`, etc.)

## Setup

```bash
git clone https://github.com/fgna/photomap.git
cd photomap
mkdir images
cp /your/photos/*.jpg images/
php -S localhost:8080
```

Open `http://localhost:8080` in your browser.

## Configuration

Edit the configuration block at the top of `index.php`:

| Variable | Default | Description |
|---|---|---|
| `$TRIP_NAME` | `'Photo Map'` | Shown in the browser title and page header |
| `$MAP_LAT` / `$MAP_LNG` | `0.0` / `0.0` | Initial map centre (overridden by `fitBounds` once photos load) |
| `$IMAGE_DIR` | `./images/` | Folder scanned for photos |
| `$THUMB_SIZE` | `240` | Thumbnail size in pixels (square crop) |
| `$RESIZED_MAX` | `2048` | Max dimension for lightbox display images in pixels |

## Supported formats

JPG · JPEG · WebP · PNG · HEIC · TIFF

HEIC and TIFF thumbnails fall back to a redirect to the original file if PHP GD cannot decode them.

## Caching

| Path | Contents |
|---|---|
| `.photomap-cache.json` | EXIF (GPS + date) and geocoding results per file |
| `.thumbnails/` | 240 × 240 px square-crop JPEGs (markers, sidebar, strip) |
| `.resized/` | Max-2048 px JPEGs served in the lightbox |

Delete any of these to force a rebuild of that layer.

## License

MIT
