#!/usr/bin/env python3
"""
photomap-build: static site generator for photomap.

Reads trips/ subdirectories, extracts EXIF, generates thumbnails,
geocodes GPS coordinates, and writes a self-contained dist/index.html.

Usage:
  python build.py
  python build.py --trips-dir ./trips --output ./dist --title "My Map"
  python build.py --no-geocode          # skip Nominatim calls
  python build.py --force-thumbs        # regenerate all thumbnails
"""

import argparse
import hashlib
import json
import os
import re
import time
from datetime import datetime
from pathlib import Path

import requests
from PIL import Image, ImageOps

try:
    import pillow_heif
    pillow_heif.register_heif_opener()
except ImportError:
    pass  # HEIC support unavailable; install pillow-heif to enable it

THUMB_SIZE  = 240
RESIZED_MAX = 2048
EXTENSIONS  = {'.jpg', '.jpeg', '.webp', '.png', '.heic', '.tiff', '.tif'}
TRIP_COLORS = [
    '#6D5AD7', '#D4604A', '#3AA46E', '#C44F7A',
    '#3AA0A8', '#C8921C', '#4F64D4', '#7A9C4F',
]

# ── Cache ─────────────────────────────────────────────────────────────────────

def load_cache(path):
    p = Path(path)
    if p.exists():
        try:
            return json.loads(p.read_text(encoding='utf-8'))
        except Exception:
            pass
    return {'geo': {}, 'geo_retry': {}, 'files': {}}


def save_cache(path, cache):
    tmp = str(path) + '.tmp.' + str(os.getpid())
    Path(tmp).write_text(json.dumps(cache, ensure_ascii=False), encoding='utf-8')
    os.replace(tmp, path)


# ── EXIF helpers ──────────────────────────────────────────────────────────────

def _rational_to_float(val):
    if isinstance(val, tuple) and len(val) == 2:
        return val[0] / val[1] if val[1] else 0.0
    return float(val)


def _dms_to_decimal(dms, ref):
    d = _rational_to_float(dms[0])
    m = _rational_to_float(dms[1])
    s = _rational_to_float(dms[2])
    deg = d + m / 60.0 + s / 3600.0
    return -deg if ref in ('S', 'W') else deg


def read_photo_meta(path):
    """Return (gps_dict_or_None, date_str)."""
    gps = None
    date = None
    try:
        with Image.open(path) as img:
            exif = img.getexif()
            gps_ifd = exif.get_ifd(0x8825)
            if gps_ifd and gps_ifd.get(2) and gps_ifd.get(4):
                lat = _dms_to_decimal(gps_ifd[2], gps_ifd.get(1, 'N'))
                lng = _dms_to_decimal(gps_ifd[4], gps_ifd.get(3, 'E'))
                if lat != 0.0 or lng != 0.0:
                    gps = {'lat': lat, 'lng': lng}
            for tag_id in (36867, 306):  # DateTimeOriginal, DateTime
                val = exif.get(tag_id)
                if val:
                    try:
                        date = datetime.strptime(
                            val.strip(), '%Y:%m:%d %H:%M:%S'
                        ).strftime('%Y-%m-%d %H:%M')
                        break
                    except ValueError:
                        pass
    except Exception:
        pass

    if not date:
        stem = Path(path).stem
        m = re.match(r'(\d{4})(\d{2})(\d{2})', stem)
        if m:
            date = f'{m.group(1)}-{m.group(2)}-{m.group(3)}'
        else:
            date = datetime.fromtimestamp(
                os.path.getmtime(path)
            ).strftime('%Y-%m-%d %H:%M')

    return gps, date


# ── Image generation ──────────────────────────────────────────────────────────

def make_thumbnail(src, dst, size=THUMB_SIZE):
    Path(dst).parent.mkdir(parents=True, exist_ok=True)
    with Image.open(src) as img:
        img = ImageOps.exif_transpose(img).convert('RGB')
        w, h = img.size
        m = min(w, h)
        img = img.crop(((w - m) // 2, (h - m) // 2, (w + m) // 2, (h + m) // 2))
        img = img.resize((size, size), Image.LANCZOS)
        img.save(dst, 'JPEG', quality=85, optimize=True)


def make_resized(src, dst, max_dim=RESIZED_MAX):
    Path(dst).parent.mkdir(parents=True, exist_ok=True)
    with Image.open(src) as img:
        img = ImageOps.exif_transpose(img).convert('RGB')
        w, h = img.size
        if max(w, h) > max_dim:
            ratio = max_dim / max(w, h)
            img = img.resize((int(w * ratio), int(h * ratio)), Image.LANCZOS)
        img.save(dst, 'JPEG', quality=92, optimize=True)


# ── Geocoding ─────────────────────────────────────────────────────────────────

def nominatim_reverse(lat, lng):
    url = (
        f'https://nominatim.openstreetmap.org/reverse'
        f'?lat={lat}&lon={lng}&zoom=15&format=json'
    )
    try:
        r = requests.get(
            url,
            headers={'User-Agent': 'photomap-build/1.0'},
            timeout=10,
        )
        r.raise_for_status()
        data = r.json()
        addr = data.get('address', {})
        name = data.get('name', '')
        road = (
            addr.get('road') or addr.get('pedestrian') or
            addr.get('path') or addr.get('footway')
        )
        if name and name != road:
            return name
        return (
            road or addr.get('city') or addr.get('town') or
            addr.get('village') or addr.get('suburb') or None
        )
    except Exception:
        return None


# ── JS/CSS extraction from index.php ─────────────────────────────────────────

# Exact fetch block to replace (must match index.php verbatim)
_FETCH_BLOCK = """fetch('?api=photos')
  .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
  .then(d => {
    initApp(d.photos, d.no_gps, d.trips ?? [], d.active_trip ?? null);
    if (d.geocoding_pending) scheduleGeocodeRefresh();
  })
  .catch(err => {
    const p = document.createElement('p');
    p.style.cssText = 'padding:20px 10px;font-size:11px;color:var(--ink-600)';
    p.textContent = `Error loading photos: ${err.message}`;
    $body.replaceChildren(p);
  });"""

_STATIC_INIT = """(function () {
  var d = window.__DATA__;
  var urlTrip = new URLSearchParams(location.search).get('trip');
  initApp(d.photos, d.no_gps, d.trips || [], urlTrip || null);
})();"""


def extract_css_js(php_file):
    content = Path(php_file).read_text(encoding='utf-8')
    css_m = re.search(r'<style>(.*?)</style>', content, re.DOTALL)
    # Match the inline app <script> (no attributes), not the CDN <script src="..."> tags
    js_m  = re.search(r'<script>\n(.*?)\n</script>', content, re.DOTALL)
    if not css_m or not js_m:
        raise RuntimeError(f'Could not find <style> or inline <script> block in {php_file}')
    css = css_m.group(1)
    js  = js_m.group(1)
    if _FETCH_BLOCK not in js:
        raise RuntimeError(
            'Could not find the fetch startup block in the JS — '
            'index.php may have changed; update _FETCH_BLOCK in build.py'
        )
    js = js.replace(_FETCH_BLOCK, _STATIC_INIT)
    return css, js


# ── HTML template ─────────────────────────────────────────────────────────────

_HEAD = """\
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin="">
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<style>
{css}
</style>
</head>
<body>"""

_HEADER = """\
<header class="app-header">
  <div class="brand">
    <span class="mark"></span>
    <h1>{title}<em>.</em></h1>
    <span class="sub">Photo Map · {year}</span>
  </div>
  <div class="header-meta">
    <span><span class="count">{total}</span> photo{s}</span>
  </div>
</header>
<div id="map"></div>"""

_EMPTY_STATE = """\
<div class="empty-state">
  <div class="card">
    <h2>No Photos<em>.</em></h2>
    <p>Add photos to your <code>trips/</code> directory and re-run the build script.<br>
       E.g. <code>trips/paris-2025/</code><br>
       Supported formats: JPG, WebP, PNG, HEIC, TIFF.</p>
  </div>
</div>"""

_SIDEBAR = """\
<aside class="sidebar" id="sidebar" aria-label="Photo index">
  <div class="sidebar-head">
    <div>
      <div class="title">Index<em>.</em></div>
      <div class="meta">{total} photo{s} · {gps_count} with GPS</div>
    </div>
    <button class="close" id="sidebarClose" aria-label="Close sidebar">
      <svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
    </button>
  </div>
  <div class="trip-pills" id="tripPills"></div>
  <div class="sidebar-body" id="sidebarBody"></div>
</aside>

<button class="sidebar-toggle" id="sidebarOpen" aria-label="Open sidebar">
  <svg viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h12"/></svg>
</button>

<aside class="legend-chip" aria-hidden="true">
  <div class="row">
    <div class="swatch"><div class="inner"></div></div>
    <div>Click a marker or row<br>to open the lightbox.</div>
  </div>
  <div class="row" style="opacity:.85">
    <kbd>←</kbd><kbd>→</kbd> navigate · <kbd>Esc</kbd> close
  </div>
</aside>

<div class="lightbox" id="lightbox" aria-hidden="true" role="dialog">
  <div class="frame" role="document">
    <div class="lb-media" id="lbMedia">
      <div class="lb-spinner"></div>
      <img class="image" id="lbImage" alt="">
      <button class="lb-close" id="lbClose" aria-label="Close">
        <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
      <button class="lb-nav prev" id="lbPrev" aria-label="Previous photo">
        <svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>
      </button>
      <button class="lb-nav next" id="lbNext" aria-label="Next photo">
        <svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
      </button>
      <div class="lb-counter"><span class="now" id="lbNow">1</span>/ <span id="lbTotal">1</span></div>
      <div class="lb-thumbs" id="lbThumbs"></div>
      <div class="lb-location" id="lbLocation"></div>
    </div>
  </div>
</div>"""


def render_html(title, year, total, gps_count, css, js, data, has_photos):
    s = 's' if total != 1 else ''
    head = _HEAD.format(title=title, css=css)
    header = _HEADER.format(title=title, year=year, total=total, s=s)
    if not has_photos:
        body = _EMPTY_STATE
        script = ''
    else:
        body = _SIDEBAR.format(total=total, s=s, gps_count=gps_count)
        data_json = json.dumps(data, ensure_ascii=False, separators=(',', ':'))
        data_json = data_json.replace('</', '<\\/')  # prevent </script> in data from breaking the page
        script = (
            f'<script>window.__DATA__={data_json};</script>\n'
            f'<script>\n{js}\n</script>'
        )
    return f'{head}\n{header}\n{body}\n{script}\n</body>\n</html>\n'


# ── Main build ────────────────────────────────────────────────────────────────

def build(trips_dir, output_dir, cache_file, php_file, title, no_geocode, force_thumbs):
    trips_path  = Path(trips_dir)
    output_path = Path(output_dir)
    output_path.mkdir(parents=True, exist_ok=True)

    cache = load_cache(cache_file)

    trip_slugs = []
    if trips_path.exists():
        trip_slugs = sorted(
            d for d in os.listdir(trips_path)
            if (trips_path / d).is_dir() and not d.startswith('.')
        )

    all_photos_gps    = []
    all_photos_no_gps = []
    trips_meta        = []
    pending_geo       = {}  # geo_key → (lat, lng)

    for cidx, slug in enumerate(trip_slugs):
        trip_dir   = trips_path / slug
        trip_label = ' '.join(w.capitalize() for w in re.split(r'[-_]+', slug))
        trip_color = TRIP_COLORS[cidx % len(TRIP_COLORS)]
        trips_meta.append({'slug': slug, 'label': trip_label, 'color': trip_color})

        thumb_dir   = output_path / 'trips' / slug / '.thumbnails'
        resized_dir = output_path / 'trips' / slug / '.resized'
        thumb_dir.mkdir(parents=True, exist_ok=True)
        resized_dir.mkdir(parents=True, exist_ok=True)

        files = sorted(
            f for f in os.listdir(trip_dir)
            if Path(f).suffix.lower() in EXTENSIONS
        )
        print(f'\n[{slug}] {len(files)} photo(s)')

        for filename in files:
            src = trip_dir / filename
            mtime = int(src.stat().st_mtime)
            cache_key = f'{slug}/{filename}'
            entry = cache['files'].get(cache_key)

            if entry and entry.get('m') == mtime:
                gps  = entry.get('g')
                date = entry.get('d', '—')
            else:
                print(f'  EXIF  {filename}')
                gps, date = read_photo_meta(src)
                cache['files'][cache_key] = {'m': mtime, 'g': gps, 'd': date or '—'}

            file_hash   = hashlib.md5(filename.encode()).hexdigest()
            thumb_dst   = thumb_dir   / f'{file_hash}.jpg'
            resized_dst = resized_dir / f'{file_hash}.jpg'

            if force_thumbs or not thumb_dst.exists() or thumb_dst.stat().st_mtime < mtime:
                print(f'  thumb  {filename}')
                try:
                    make_thumbnail(src, thumb_dst)
                except Exception as e:
                    print(f'    WARNING: {e}')

            if force_thumbs or not resized_dst.exists() or resized_dst.stat().st_mtime < mtime:
                print(f'  resize {filename}')
                try:
                    make_resized(src, resized_dst)
                except Exception as e:
                    print(f'    WARNING: {e}')

            name = ' '.join(
                w.capitalize() for w in re.split(r'[-_]+', Path(filename).stem)
            )
            info = {
                'file':       filename,
                'path':       f'trips/{slug}/.resized/{file_hash}.jpg',
                'thumb':      f'trips/{slug}/.thumbnails/{file_hash}.jpg',
                'name':       name,
                'date':       date or '—',
                'trip':       slug,
                'trip_label': trip_label,
                'trip_color': trip_color,
            }

            if gps:
                lat = round(gps['lat'], 7)
                lng = round(gps['lng'], 7)
                geo_key = f'{lat:.3f},{lng:.3f}'
                info['lat'] = lat
                info['lng'] = lng
                info['loc'] = cache['geo'].get(geo_key)
                if geo_key not in cache['geo']:
                    pending_geo[geo_key] = (lat, lng)
                all_photos_gps.append(info)
            else:
                all_photos_no_gps.append(info)

    # Geocode pending coordinates
    if pending_geo and not no_geocode:
        retry_before = time.time() - 86400
        todo = [
            (geo_key, lat, lng)
            for geo_key, (lat, lng) in pending_geo.items()
            if not (
                cache['geo'].get(geo_key) is None
                and cache['geo_retry'].get(geo_key, 0) >= retry_before
            )
        ]
        if todo:
            print(f'\nGeocoding {len(todo)} location(s)...')
            for i, (geo_key, lat, lng) in enumerate(todo):
                result = nominatim_reverse(lat, lng)
                cache['geo'][geo_key] = result
                if result is None:
                    cache['geo_retry'][geo_key] = int(time.time())
                    print(f'  {geo_key} → (no result)')
                else:
                    print(f'  {geo_key} → {result}')
                if i < len(todo) - 1:
                    time.sleep(1)  # Nominatim rate limit: 1 req/s

            # Attach resolved locations to photos
            for p in all_photos_gps:
                geo_key = f"{p['lat']:.3f},{p['lng']:.3f}"
                p['loc'] = cache['geo'].get(geo_key)

    save_cache(cache_file, cache)

    # Extract CSS + JS from index.php, patch startup code
    css, js = extract_css_js(php_file)

    total     = len(all_photos_gps) + len(all_photos_no_gps)
    gps_count = len(all_photos_gps)
    year      = datetime.now().year

    data = {
        'photos':             all_photos_gps,
        'no_gps':             all_photos_no_gps,
        'trips':              trips_meta,
        'active_trip':        None,
        'geocoding_pending':  False,
    }

    html = render_html(title, year, total, gps_count, css, js, data, total > 0)
    out  = output_path / 'index.html'
    out.write_text(html, encoding='utf-8')

    size_kb = out.stat().st_size // 1024
    print(f'\nWrote {out}  ({size_kb} KB)')
    print(f'{total} photo(s) · {gps_count} with GPS · {len(trips_meta)} trip(s)')


def main():
    ap = argparse.ArgumentParser(
        description='Build a static photo map from a trips/ directory.',
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    ap.add_argument('--trips-dir',    default='./trips',            metavar='PATH', help='Source trips directory')
    ap.add_argument('--output',       default='./dist',             metavar='PATH', help='Output directory')
    ap.add_argument('--cache',        default='./build-cache.json', metavar='PATH', help='EXIF + geocoding cache')
    ap.add_argument('--php-file',     default='./index.php',        metavar='PATH', help='index.php to extract CSS/JS from')
    ap.add_argument('--title',        default='Photo Map',                          help='Site title')
    ap.add_argument('--no-geocode',   action='store_true',  help='Skip Nominatim geocoding')
    ap.add_argument('--force-thumbs', action='store_true',  help='Regenerate all thumbnails')
    args = ap.parse_args()

    build(
        trips_dir    = args.trips_dir,
        output_dir   = args.output,
        cache_file   = args.cache,
        php_file     = args.php_file,
        title        = args.title,
        no_geocode   = args.no_geocode,
        force_thumbs = args.force_thumbs,
    )


if __name__ == '__main__':
    main()
