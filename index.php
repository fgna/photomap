<?php
// ============================================================
//  Granada Photo Map — index.php
//  Liest Fotos aus ./images/, extrahiert GPS-EXIF-Daten und
//  rendert eine interaktive Karte (OpenStreetMap via Leaflet).
// ============================================================

$IMAGE_DIR  = __DIR__ . '/images/';
$CACHE_FILE = __DIR__ . '/.photomap-cache.json';
$EXTENSIONS = ['jpg', 'jpeg', 'webp', 'png', 'heic', 'tiff', 'tif'];
$CACHE_VER  = 2;

// ── GPS-Helfer ───────────────────────────────────────────────

function rational_to_float(mixed $v): float {
    if (is_array($v))  return $v[1] ? (float)$v[0] / (float)$v[1] : 0.0;
    if (is_string($v) && ($slash = strpos($v, '/')) !== false) {
        $den = (float)substr($v, $slash + 1);
        return $den ? (float)substr($v, 0, $slash) / $den : 0.0;
    }
    return (float)$v;
}

function dms_to_decimal(array $dms, string $ref): float {
    $decimal = rational_to_float($dms[0])
             + rational_to_float($dms[1]) / 60
             + rational_to_float($dms[2]) / 3600;
    return (strtoupper($ref) === 'S' || strtoupper($ref) === 'W') ? -$decimal : $decimal;
}

// Reads GPS from raw bytes — fallback for WebP / non-JPEG formats.
function gps_from_raw(string $path): ?array {
    $raw = file_get_contents($path, false, null, 0, 65536);
    if ($raw === false) return null;
    $exif_pos = strpos($raw, "Exif\x00\x00");
    if ($exif_pos === false) $exif_pos = strpos($raw, "EXIF\x00\x00");
    if ($exif_pos === false) return null;

    $tiff_start = $exif_pos + 6;
    $byte_order = substr($raw, $tiff_start, 2);
    $le         = ($byte_order === 'II');
    $read16     = fn($o) => $le ? unpack('v', substr($raw, $tiff_start + $o, 2))[1]
                                : unpack('n', substr($raw, $tiff_start + $o, 2))[1];
    $read32     = fn($o) => $le ? unpack('V', substr($raw, $tiff_start + $o, 4))[1]
                                : unpack('N', substr($raw, $tiff_start + $o, 4))[1];

    $ifd0_offset    = $read32(4);
    $ifd0_count     = $read16($ifd0_offset);
    if ($ifd0_count > 256) return null;
    $gps_ifd_offset = null;

    for ($i = 0; $i < $ifd0_count; $i++) {
        $entry = $ifd0_offset + 2 + $i * 12;
        if ($read16($entry) === 0x8825) { $gps_ifd_offset = $read32($entry + 8); break; }
    }
    if ($gps_ifd_offset === null) return null;

    $gps_count = $read16($gps_ifd_offset);
    if ($gps_count > 64) return null;
    $gps = [];
    for ($i = 0; $i < $gps_count; $i++) {
        $entry      = $gps_ifd_offset + 2 + $i * 12;
        $tag        = $read16($entry);
        $type       = $read16($entry + 2);
        $count      = $read32($entry + 4);
        $val_offset = $entry + 8;

        if ($type === 2) {
            $gps[$tag] = $count > 4
                ? trim(substr($raw, $tiff_start + $read32($val_offset), $count))
                : trim(substr($raw, $tiff_start + $val_offset, min($count, 4)));
        } elseif ($type === 5) {
            $ptr = $read32($val_offset);
            $rationals = [];
            for ($r = 0; $r < min($count, 3); $r++) {
                $num = $read32($ptr + $r * 8);
                $den = $read32($ptr + $r * 8 + 4);
                $rationals[] = $den ? $num / $den : 0;
            }
            $gps[$tag] = $rationals;
        }
    }

    if (isset($gps[2], $gps[4]) && is_array($gps[2]) && is_array($gps[4])) {
        $lat = ($gps[2][0] ?? 0) + ($gps[2][1] ?? 0) / 60 + ($gps[2][2] ?? 0) / 3600;
        $lng = ($gps[4][0] ?? 0) + ($gps[4][1] ?? 0) / 60 + ($gps[4][2] ?? 0) / 3600;
        if (($gps[1] ?? '') === 'S') $lat = -$lat;
        if (($gps[3] ?? '') === 'W') $lng = -$lng;
        if ($lat != 0 || $lng != 0) return ['lat' => $lat, 'lng' => $lng];
    }
    return null;
}

// Single EXIF read that returns both GPS and date.
function read_photo_meta(string $path): array {
    $gps  = null;
    $date = null;

    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($path, null, true);
        if ($exif) {
            $date = $exif['EXIF']['DateTimeOriginal']
                 ?? $exif['IFD0']['DateTime']
                 ?? null;

            $g = $exif['GPS'] ?? [];
            if (isset($g['GPSLatitude'], $g['GPSLongitude'])) {
                $lat = dms_to_decimal($g['GPSLatitude'],  $g['GPSLatitudeRef']  ?? 'N');
                $lng = dms_to_decimal($g['GPSLongitude'], $g['GPSLongitudeRef'] ?? 'E');
                if ($lat != 0 || $lng != 0) $gps = ['lat' => $lat, 'lng' => $lng];
            }
        }
    }

    if ($gps  === null) $gps  = gps_from_raw($path);

    if ($date === null) {
        if (preg_match('/(\d{4})(\d{2})(\d{2})/', basename($path), $m))
            $date = "{$m[1]}-{$m[2]}-{$m[3]}";
        else {
            $mtime = filemtime($path);
            $date  = $mtime !== false ? date('Y-m-d', $mtime) : '—';
        }
    }

    return ['gps' => $gps, 'date' => $date];
}

// ── Cache ────────────────────────────────────────────────────

function load_cache(string $path, int $ver): array {
    if (!is_file($path)) return ['v' => $ver, 'f' => []];
    $data = @json_decode(@file_get_contents($path), true);
    return (is_array($data) && ($data['v'] ?? 0) === $ver) ? $data : ['v' => $ver, 'f' => []];
}

function save_cache(string $path, array $cache): void {
    @file_put_contents($path, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// ── Fotos einlesen ───────────────────────────────────────────

$photos_with_gps    = [];
$photos_without_gps = [];

if (is_dir($IMAGE_DIR)) {
    $cache       = load_cache($CACHE_FILE, $CACHE_VER);
    $cache_dirty = false;

    $files = scandir($IMAGE_DIR);
    usort($files, 'strcasecmp');
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $EXTENSIONS)) continue;

        $full_path = $IMAGE_DIR . $file;
        $mtime     = filemtime($full_path);

        $entry = $cache['f'][$file] ?? null;
        if ($entry && ($entry['m'] ?? 0) === $mtime) {
            $gps  = $entry['g'] ?? null;
            $date = $entry['d'] ?? '—';
        } else {
            ['gps' => $gps, 'date' => $date] = read_photo_meta($full_path);
            $cache['f'][$file] = ['m' => $mtime, 'g' => $gps, 'd' => $date];
            $cache_dirty = true;
        }

        $web_path     = 'images/' . rawurlencode($file);
        $name_display = ucwords(str_replace(['_', '-'], ' ', pathinfo($file, PATHINFO_FILENAME)));
        $info = ['file' => $file, 'path' => $web_path, 'name' => $name_display, 'date' => $date];

        if ($gps) {
            $info['lat'] = round($gps['lat'], 7);
            $info['lng'] = round($gps['lng'], 7);
            $photos_with_gps[] = $info;
        } else {
            $photos_without_gps[] = $info;
        }
    }

    if ($cache_dirty) save_cache($CACHE_FILE, $cache);
}

$total       = count($photos_with_gps) + count($photos_without_gps);
$center_lat  = 37.1773;
$center_lng  = -3.5986;

$photos_json = json_encode($photos_with_gps,    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$no_gps_json = json_encode($photos_without_gps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Granada — Photo Map</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin="">
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<style>
:root{
  --ink:    oklch(0.18 0.02 250);
  --paper:  oklch(0.985 0.005 90);
  --accent: oklch(0.55 0.22 270);
  --ink-50:  color-mix(in oklab, var(--ink) 4%,  var(--paper));
  --ink-100: color-mix(in oklab, var(--ink) 8%,  var(--paper));
  --ink-200: color-mix(in oklab, var(--ink) 14%, var(--paper));
  --ink-600: color-mix(in oklab, var(--ink) 65%, var(--paper));
  --serif: "Instrument Serif", "Times New Roman", serif;
  --mono:  "JetBrains Mono", ui-monospace, monospace;
  --shadow-1: 0 1px 2px rgba(20,22,40,.04), 0 2px 8px rgba(20,22,40,.04);
  --shadow-2: 0 8px 24px rgba(20,22,40,.08), 0 2px 6px rgba(20,22,40,.05);
  --shadow-3: 0 32px 80px rgba(20,22,40,.18), 0 8px 24px rgba(20,22,40,.10);
}
*{box-sizing:border-box}
html,body{margin:0;height:100%;background:var(--paper);color:var(--ink);font-family:var(--mono);font-size:13px;line-height:1.45;-webkit-font-smoothing:antialiased}
button{font:inherit;color:inherit;background:none;border:0;cursor:pointer;padding:0}

.app-header{position:fixed;inset:0 0 auto 0;z-index:600;display:flex;align-items:center;justify-content:space-between;padding:18px 24px;background:linear-gradient(180deg, color-mix(in oklab, var(--paper) 92%, transparent) 0%, color-mix(in oklab, var(--paper) 0%, transparent) 100%);pointer-events:none;}
.app-header > *{pointer-events:auto}
.brand{display:flex;align-items:baseline;gap:14px}
.brand .mark{width:28px;height:28px;border-radius:999px;background:var(--ink);position:relative;display:inline-block;align-self:center;}
.brand .mark::after{content:"";position:absolute;inset:8px;border-radius:999px;background:var(--accent);}
.brand h1{font-family:var(--serif);font-weight:400;font-size:28px;letter-spacing:-0.01em;margin:0;line-height:1;}
.brand h1 em{font-style:italic;color:var(--accent)}
.brand .sub{font-family:var(--mono);font-size:11px;color:var(--ink-600);text-transform:uppercase;letter-spacing:0.12em;padding-left:14px;border-left:1px solid var(--ink-200);align-self:center;}
.header-meta{display:flex;gap:18px;align-items:center;font-size:11px;color:var(--ink-600);text-transform:uppercase;letter-spacing:0.12em;}
.header-meta .dot{width:6px;height:6px;border-radius:99px;background:var(--accent);display:inline-block;margin-right:8px;animation:pulse 2.4s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
.header-meta .count{font-family:var(--serif);font-style:italic;font-size:18px;color:var(--ink);text-transform:none;letter-spacing:0;margin-right:4px}

#map{position:fixed;inset:0;background:var(--ink-50);z-index:1}
.leaflet-container{background:var(--ink-50);font-family:var(--mono)}
.leaflet-control-attribution{background:color-mix(in oklab, var(--paper) 88%, transparent)!important;font-family:var(--mono);font-size:10px;color:var(--ink-600);border-radius:6px;padding:4px 8px!important;border:1px solid var(--ink-100);}
.leaflet-control-attribution a{color:var(--ink)!important}
.leaflet-control-zoom{border:0!important;box-shadow:var(--shadow-1)!important;border-radius:10px!important;overflow:hidden;margin:88px 24px 0 0!important;}
.leaflet-control-zoom a{background:var(--paper)!important;color:var(--ink)!important;border-bottom:1px solid var(--ink-100)!important;width:36px!important;height:36px!important;line-height:36px!important;font-family:var(--serif);font-size:18px;}
.leaflet-control-zoom a:hover{background:var(--ink-50)!important}
.leaflet-control-zoom a:last-child{border-bottom:0!important}
.leaflet-tile-pane{filter:saturate(0.55) contrast(0.96) brightness(1.02)}

.photo-marker{width:42px;height:42px;border-radius:999px;background:var(--paper);padding:3px;box-shadow:var(--shadow-2);cursor:pointer;transition:transform .25s cubic-bezier(.2,.8,.2,1), box-shadow .25s;position:relative;}
.photo-marker .thumb{width:100%;height:100%;border-radius:999px;background-size:cover;background-position:center;display:block;}
.photo-marker::after{content:"";position:absolute;left:50%;bottom:-4px;width:8px;height:8px;background:var(--paper);transform:translateX(-50%) rotate(45deg);box-shadow:2px 2px 4px rgba(20,22,40,.08);z-index:-1;}
.photo-marker:hover{transform:translateY(-4px) scale(1.08)}
.photo-marker.is-active{transform:translateY(-6px) scale(1.18);box-shadow:0 0 0 3px var(--accent), var(--shadow-3);}
.photo-marker.is-active::before{content:"";position:absolute;inset:-10px;border-radius:999px;border:1px solid var(--accent);animation:ring 1.6s ease-out infinite;pointer-events:none;}
@keyframes ring{0%{transform:scale(.9);opacity:.8}100%{transform:scale(1.6);opacity:0}}
.leaflet-marker-icon.photo-marker-wrap{background:transparent;border:0}
.photo-cluster{border-radius:999px;background:color-mix(in oklab, var(--accent) 20%, var(--paper));padding:3px;box-shadow:var(--shadow-2);cursor:pointer;}
.photo-cluster .ci{width:100%;height:100%;border-radius:999px;background:var(--accent);color:var(--paper);display:flex;align-items:center;justify-content:center;font-family:var(--serif);font-style:italic;font-size:16px;}

.sidebar{position:fixed;top:88px;right:24px;bottom:24px;width:340px;z-index:500;background:color-mix(in oklab, var(--paper) 96%, transparent);backdrop-filter:blur(12px) saturate(1.1);-webkit-backdrop-filter:blur(12px) saturate(1.1);border:1px solid var(--ink-100);border-radius:18px;box-shadow:var(--shadow-2);display:flex;flex-direction:column;transition:transform .35s cubic-bezier(.2,.8,.2,1), opacity .25s;overflow:hidden;}
.sidebar.is-collapsed{transform:translateX(calc(100% + 32px));opacity:0;pointer-events:none}
.sidebar-toggle{position:fixed;top:88px;right:24px;z-index:501;width:44px;height:44px;border-radius:12px;background:var(--paper);border:1px solid var(--ink-100);box-shadow:var(--shadow-1);display:flex;align-items:center;justify-content:center;transition:transform .25s, box-shadow .25s, opacity .25s;opacity:0;pointer-events:none;}
.sidebar.is-collapsed ~ .sidebar-toggle{opacity:1;pointer-events:auto}
.sidebar-toggle:hover{box-shadow:var(--shadow-2);transform:translateY(-1px)}
.sidebar-toggle svg{width:18px;height:18px;stroke:var(--ink);fill:none;stroke-width:1.4}
.sidebar-head{padding:20px 22px 14px;border-bottom:1px solid var(--ink-100);display:flex;justify-content:space-between;align-items:flex-start;}
.sidebar-head .title{font-family:var(--serif);font-size:22px;line-height:1;font-weight:400;letter-spacing:-0.01em;}
.sidebar-head .title em{font-style:italic;color:var(--accent)}
.sidebar-head .meta{font-size:10px;color:var(--ink-600);text-transform:uppercase;letter-spacing:0.12em;margin-top:4px;}
.sidebar-head .close{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--ink-600);}
.sidebar-head .close:hover{background:var(--ink-50);color:var(--ink)}
.sidebar-head .close svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:1.5}
.sidebar-body{flex:1;overflow-y:auto;padding:8px 12px 16px}
.sidebar-body::-webkit-scrollbar{width:8px}
.sidebar-body::-webkit-scrollbar-thumb{background:var(--ink-200);border-radius:4px}
.sidebar-body::-webkit-scrollbar-track{background:transparent}
.section-label{display:flex;align-items:center;gap:10px;padding:14px 10px 8px;font-size:10px;color:var(--ink-600);text-transform:uppercase;letter-spacing:0.14em;}
.section-label .line{flex:1;height:1px;background:var(--ink-100)}
.section-label .num{font-family:var(--serif);font-style:italic;font-size:14px;color:var(--ink);text-transform:none;letter-spacing:0}
.photo-row{display:flex;gap:12px;align-items:center;padding:8px 10px;border-radius:12px;cursor:pointer;transition:background .15s;position:relative;}
.photo-row:hover{background:var(--ink-50)}
.photo-row.is-active{background:var(--ink-50)}
.photo-row.is-active::before{content:"";position:absolute;left:-4px;top:18px;bottom:18px;width:2px;background:var(--accent);border-radius:2px;}
.photo-row .thumb{width:46px;height:46px;border-radius:10px;background-size:cover;background-position:center;background-color:var(--ink-100);flex-shrink:0;position:relative;}
.photo-row.no-gps .thumb::after{content:"";position:absolute;inset:0;border-radius:10px;background:repeating-linear-gradient(135deg, transparent 0 4px, color-mix(in oklab, var(--ink) 18%, transparent) 4px 5px);}
.photo-row .info{min-width:0;flex:1}
.photo-row .name{font-family:var(--serif);font-size:16px;line-height:1.15;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.photo-row .meta{font-size:10px;color:var(--ink-600);margin-top:3px;display:flex;gap:10px;text-transform:uppercase;letter-spacing:0.08em;}
.photo-row .meta .gps-dot{color:var(--accent)}

.lightbox{position:fixed;inset:0;z-index:1000;background:color-mix(in oklab, var(--ink) 78%, transparent);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);display:none;opacity:0;transition:opacity .3s;}
.lightbox.is-open{opacity:1}
.lightbox .frame{margin:auto;width:min(1200px, 92vw);height:min(800px, 90vh);background:var(--paper);border-radius:18px;box-shadow:var(--shadow-3);position:relative;overflow:hidden;transform:scale(.97);transition:transform .35s cubic-bezier(.2,.8,.2,1);}
.lightbox.is-open .frame{transform:scale(1)}
.lb-media{position:absolute;inset:0;background:var(--ink-50);display:flex;align-items:center;justify-content:center;overflow:hidden;}
.lb-media .image{position:absolute;inset:0;background-size:contain;background-position:center;background-repeat:no-repeat;background-color:var(--ink-50);}
.lb-nav{position:absolute;top:50%;transform:translateY(-50%);width:48px;height:48px;border-radius:999px;background:color-mix(in oklab, var(--paper) 90%, transparent);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;color:var(--ink);transition:transform .2s, background .2s;z-index:2;}
.lb-nav:hover{background:var(--paper);transform:translateY(-50%) scale(1.05)}
.lb-nav.prev{left:24px}.lb-nav.next{right:24px}
.lb-nav svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:1.3}
.lb-counter{position:absolute;bottom:24px;left:24px;z-index:2;font-family:var(--mono);font-size:11px;color:var(--paper);background:color-mix(in oklab, var(--ink) 60%, transparent);backdrop-filter:blur(6px);padding:6px 12px;border-radius:999px;letter-spacing:0.1em;}
.lb-counter .now{font-family:var(--serif);font-style:italic;font-size:16px;line-height:1;vertical-align:-1px;margin-right:4px}
.lb-close{position:absolute;top:18px;right:18px;z-index:3;width:36px;height:36px;border-radius:10px;background:color-mix(in oklab, var(--paper) 90%, transparent);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;color:var(--ink);}
.lb-close:hover{background:var(--paper)}
.lb-close svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:1.5}
.lb-thumbs{position:absolute;bottom:24px;left:50%;transform:translateX(-50%);display:flex;gap:6px;z-index:2;padding:6px;background:color-mix(in oklab, var(--paper) 90%, transparent);backdrop-filter:blur(8px);border-radius:999px;box-shadow:var(--shadow-2);}
.lb-thumbs .t{width:32px;height:32px;border-radius:999px;background-size:cover;background-position:center;cursor:pointer;opacity:.5;transition:opacity .2s, transform .2s;}
.lb-thumbs .t:hover{opacity:.85}
.lb-thumbs .t.is-active{opacity:1;transform:scale(1.12);box-shadow:0 0 0 2px var(--accent)}

.legend-chip{position:fixed;left:24px;bottom:24px;z-index:500;background:color-mix(in oklab, var(--paper) 92%, transparent);backdrop-filter:blur(8px);border:1px solid var(--ink-100);border-radius:14px;padding:14px 16px;box-shadow:var(--shadow-1);display:flex;flex-direction:column;gap:8px;font-size:11px;color:var(--ink-600);max-width:240px;}
.legend-chip .row{display:flex;align-items:center;gap:10px}
.legend-chip .swatch{width:24px;height:24px;border-radius:99px;background:var(--paper);padding:2px;box-shadow:var(--shadow-1);flex-shrink:0;}
.legend-chip .swatch .inner{width:100%;height:100%;border-radius:99px;background:linear-gradient(135deg, oklch(0.7 0.15 30), oklch(0.55 0.18 280));}
.legend-chip kbd{font-family:var(--mono);font-size:10px;padding:2px 5px;border-radius:4px;background:var(--ink-100);color:var(--ink);border:1px solid var(--ink-200);border-bottom-width:2px;}

.empty-state{position:fixed;inset:0;z-index:400;display:flex;align-items:center;justify-content:center;pointer-events:none;}
.empty-state .card{background:var(--paper);border:1px solid var(--ink-100);border-radius:18px;padding:40px 48px;text-align:center;box-shadow:var(--shadow-2);pointer-events:auto;max-width:360px;}
.empty-state .card h2{font-family:var(--serif);font-size:28px;font-weight:400;margin-bottom:10px;}
.empty-state .card p{font-size:12px;color:var(--ink-600);line-height:1.7;}
.empty-state .card code{font-family:var(--mono);background:var(--ink-100);padding:2px 6px;border-radius:4px;}

@media (max-width: 720px){
  .sidebar{top:auto;right:12px;left:12px;bottom:12px;width:auto;height:46vh;border-radius:18px}
  .legend-chip{display:none}
}
</style>
</head>
<body>

<header class="app-header">
  <div class="brand">
    <span class="mark"></span>
    <h1>Granada<em>.</em></h1>
    <span class="sub">Photo Atlas · <?= date('Y') ?></span>
  </div>
  <div class="header-meta">
    <span><span class="dot"></span>Live</span>
    <span><span class="count"><?= $total ?></span> Bild<?= $total !== 1 ? 'er' : '' ?></span>
  </div>
</header>

<div id="map"></div>

<?php if ($total === 0): ?>
<div class="empty-state">
  <div class="card">
    <h2>Keine Fotos<em>.</em></h2>
    <p>Lege deine Fotos in den Ordner <code>images/</code> neben dieser Datei.<br>
       Unterstützte Formate: JPG, WebP, PNG, HEIC, TIFF.</p>
  </div>
</div>
<?php else: ?>

<aside class="sidebar" id="sidebar" aria-label="Foto-Index">
  <div class="sidebar-head">
    <div>
      <div class="title">Index<em>.</em></div>
      <div class="meta"><?= $total ?> Aufnahme<?= $total !== 1 ? 'n' : '' ?> · <?= count($photos_with_gps) ?> mit GPS</div>
    </div>
    <button class="close" id="sidebarClose" aria-label="Sidebar schließen">
      <svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
    </button>
  </div>
  <div class="sidebar-body" id="sidebarBody"></div>
</aside>

<button class="sidebar-toggle" id="sidebarOpen" aria-label="Sidebar öffnen">
  <svg viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h12"/></svg>
</button>

<aside class="legend-chip" aria-hidden="true">
  <div class="row">
    <div class="swatch"><div class="inner"></div></div>
    <div>Klick auf Marker oder Index<br>zum Öffnen der Lightbox.</div>
  </div>
  <div class="row" style="opacity:.85">
    <kbd>←</kbd><kbd>→</kbd> blättern · <kbd>Esc</kbd> schließen
  </div>
</aside>

<div class="lightbox" id="lightbox" aria-hidden="true" role="dialog">
  <div class="frame" role="document">
    <div class="lb-media">
      <div class="image" id="lbImage"></div>
      <button class="lb-close" id="lbClose" aria-label="Schließen">
        <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
      <button class="lb-nav prev" id="lbPrev" aria-label="Vorheriges Foto">
        <svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg>
      </button>
      <button class="lb-nav next" id="lbNext" aria-label="Nächstes Foto">
        <svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
      </button>
      <div class="lb-counter"><span class="now" id="lbNow">1</span>/ <span id="lbTotal">1</span></div>
      <div class="lb-thumbs" id="lbThumbs"></div>
    </div>
  </div>
</div>

<script>
const PHOTOS = <?= $photos_json ?>;
const NO_GPS = <?= $no_gps_json ?>;

// ── Map ───────────────────────────────────────────────────────
const map = L.map('map', { zoomControl: true, scrollWheelZoom: true })
  .setView([<?= $center_lat ?>, <?= $center_lng ?>], 14);

L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
  attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> · CARTO',
  maxZoom: 19, subdomains: 'abcd',
}).addTo(map);

if (PHOTOS.length > 0) {
  map.fitBounds(L.latLngBounds(PHOTOS.map(p => [p.lat, p.lng])), { padding: [120, 380] });
}

// ── Markers ───────────────────────────────────────────────────
const markerCluster = L.markerClusterGroup({
  maxClusterRadius: 60,
  showCoverageOnHover: false,
  iconCreateFunction(cluster) {
    const n = cluster.getChildCount();
    return L.divIcon({ html: `<div class="ci">${n}</div>`, className: 'photo-cluster', iconSize: [44, 44], iconAnchor: [22, 22] });
  },
});

const markers = PHOTOS.map((p, i) => {
  const icon = L.divIcon({
    className: 'photo-marker-wrap',
    html: `<div class="photo-marker" data-idx="${i}"><span class="thumb" style="background-image:url('${p.path}')"></span></div>`,
    iconSize: [42, 42], iconAnchor: [21, 21],
  });
  const m = L.marker([p.lat, p.lng], { icon });
  m.on('click', () => openLightbox(i, false));
  return m;
});
markers.forEach(m => markerCluster.addLayer(m));
map.addLayer(markerCluster);

// ── Sidebar (virtual scroll) ──────────────────────────────────
const $body = document.getElementById('sidebarBody');

const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

function fmtDate(s) {
  if (!s) return '—';
  const d = new Date(s.replace(' ', 'T'));
  return isNaN(d) ? s : d.toLocaleDateString('de-DE', { day: '2-digit', month: 'short', year: 'numeric' });
}

let lbCurrent = { list: 'gps', i: -1 };

const ROW_H = 62, LABEL_H = 40, BUFFER = 8;

const FLAT = [];
if (PHOTOS.length) {
  FLAT.push({ type: 'label', text: 'Mit GPS',   count: PHOTOS.length });
  PHOTOS.forEach((p, i)  => FLAT.push({ type: 'row', kind: 'gps',   idx: i, p }));
} else {
  FLAT.push({ type: 'empty' });
}
if (NO_GPS.length) {
  FLAT.push({ type: 'label', text: 'Ohne GPS', count: NO_GPS.length });
  NO_GPS.forEach((p, i) => FLAT.push({ type: 'row', kind: 'nogps', idx: i, p }));
}

const TOPS = new Int32Array(FLAT.length + 1);
for (let i = 0; i < FLAT.length; i++) {
  const h = FLAT[i].type === 'label' ? LABEL_H : FLAT[i].type === 'empty' ? 36 : ROW_H;
  TOPS[i + 1] = TOPS[i] + h;
}
const TOTAL_H = TOPS[FLAT.length];

function renderItem(item) {
  if (item.type === 'label') {
    return `<div class="section-label"><span class="num">${item.count.toString().padStart(2,'0')}</span><span>${item.text}</span><span class="line"></span></div>`;
  }
  if (item.type === 'empty') {
    return `<p style="padding:10px;font-size:11px;color:var(--ink-600)">Keine Fotos mit GPS.</p>`;
  }
  const { kind, idx, p } = item;
  const active = lbCurrent.list === kind && lbCurrent.i === idx ? ' is-active' : '';
  const meta = kind === 'gps'
    ? `<span><span class="gps-dot">◉</span> ${p.lat.toFixed(4)}, ${p.lng.toFixed(4)}</span>`
    : `<span>${fmtDate(p.date)}</span><span>kein GPS</span>`;
  return `<div class="photo-row${kind === 'nogps' ? ' no-gps' : ''}${active}" data-kind="${kind}" data-idx="${idx}">
    <div class="thumb" style="background-image:url('${p.path}')"></div>
    <div class="info"><div class="name">${esc(p.name)}</div><div class="meta">${meta}</div></div>
  </div>`;
}

function renderVirtual() {
  const st = $body.scrollTop;
  const ch = $body.clientHeight || 400;

  let lo = 0, hi = FLAT.length;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (TOPS[mid + 1] <= st) lo = mid + 1; else hi = mid;
  }
  const start = Math.max(0, lo - BUFFER);
  let end = lo;
  while (end < FLAT.length && TOPS[end] < st + ch) end++;
  end = Math.min(FLAT.length, end + BUFFER);

  let html = `<div style="height:${TOPS[start]}px" aria-hidden="true"></div>`;
  for (let i = start; i < end; i++) html += renderItem(FLAT[i]);
  html += `<div style="height:${Math.max(0, TOTAL_H - TOPS[end])}px" aria-hidden="true"></div>`;
  $body.innerHTML = html;

  $body.querySelectorAll('.photo-row').forEach(row => {
    row.addEventListener('click', () => {
      const kind = row.dataset.kind, idx = +row.dataset.idx;
      if (kind === 'gps') {
        markerCluster.zoomToShowLayer(markers[idx], () => {});
        openLightbox(idx, false);
      } else {
        openLightbox(idx, true);
      }
    });
  });
}

function scrollSidebarTo(kind, i) {
  const fi = FLAT.findIndex(it => it.type === 'row' && it.kind === kind && it.idx === i);
  if (fi >= 0) {
    const top = TOPS[fi], bot = TOPS[fi + 1], st = $body.scrollTop, ch = $body.clientHeight;
    if (top < st + 8 || bot > st + ch - 8) $body.scrollTop = Math.max(0, top - 60);
  }
  renderVirtual();
}

$body.addEventListener('scroll', renderVirtual, { passive: true });
renderVirtual();

document.getElementById('sidebarClose').addEventListener('click', () =>
  document.getElementById('sidebar').classList.add('is-collapsed'));
document.getElementById('sidebarOpen').addEventListener('click', () => {
  document.getElementById('sidebar').classList.remove('is-collapsed');
  renderVirtual();
});

// ── Lightbox ──────────────────────────────────────────────────
const $lb      = document.getElementById('lightbox');
const $lbImage = document.getElementById('lbImage');
const $lbNow   = document.getElementById('lbNow');
const $lbTotal = document.getElementById('lbTotal');
const $lbThumbs= document.getElementById('lbThumbs');

const getList = kind => kind === 'gps' ? PHOTOS : NO_GPS;

function buildThumbs(kind) {
  $lbThumbs.innerHTML = getList(kind).map((p, i) =>
    `<div class="t" data-i="${i}" style="background-image:url('${p.path}')"></div>`).join('');
  $lbThumbs.querySelectorAll('.t').forEach(t =>
    t.addEventListener('click', () => showAt(lbCurrent.list, +t.dataset.i)));
}

function showAt(kind, i) {
  const list = getList(kind);
  if (!list.length) return;
  i = (i + list.length) % list.length;
  lbCurrent = { list: kind, i };
  const p = list[i];

  $lbImage.style.backgroundImage = `url('${p.path}')`;
  $lbNow.textContent   = (i+1).toString().padStart(2,'0');
  $lbTotal.textContent = list.length.toString().padStart(2,'0');

  document.querySelectorAll('.photo-marker').forEach(el =>
    el.classList.toggle('is-active', kind === 'gps' && +el.dataset.idx === i));
  scrollSidebarTo(kind, i);
  $lbThumbs.querySelectorAll('.t').forEach(t => t.classList.toggle('is-active', +t.dataset.i === i));
}

function openLightbox(i, isNoGps = false) {
  const kind = isNoGps ? 'nogps' : 'gps';
  buildThumbs(kind);
  $lb.style.display = 'flex';
  requestAnimationFrame(() => $lb.classList.add('is-open'));
  $lb.setAttribute('aria-hidden', 'false');
  showAt(kind, i);
}

function closeLightbox() {
  $lb.classList.remove('is-open');
  $lb.setAttribute('aria-hidden', 'true');
  $lb.addEventListener('transitionend', () => { $lb.style.display = ''; }, { once: true });
  document.querySelectorAll('.photo-marker').forEach(el => el.classList.remove('is-active'));
  lbCurrent = { list: lbCurrent.list, i: -1 };
  renderVirtual();
}

function nudge(d) {
  showAt(lbCurrent.list, lbCurrent.i + d);
  if (lbCurrent.list === 'gps') {
    const p = PHOTOS[lbCurrent.i];
    map.panTo([p.lat, p.lng], { animate: true, duration: .5 });
  }
}

document.getElementById('lbPrev').addEventListener('click', () => nudge(-1));
document.getElementById('lbNext').addEventListener('click', () => nudge(+1));
document.getElementById('lbClose').addEventListener('click', closeLightbox);
$lb.addEventListener('click', e => { if (e.target === $lb) closeLightbox(); });
document.addEventListener('keydown', e => {
  if (!$lb.classList.contains('is-open')) return;
  if (e.key === 'Escape')     closeLightbox();
  if (e.key === 'ArrowLeft')  nudge(-1);
  if (e.key === 'ArrowRight') nudge(+1);
});
</script>
<?php endif; ?>
</body>
</html>
