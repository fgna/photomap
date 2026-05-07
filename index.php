<?php
// ============================================================
//  Photo Map — index.php  (multi-trip)
// ============================================================

// ── Configuration ─────────────────────────────────────────────
$TRIP_NAME  = 'Photo Map';
$TRIPS_DIR  = __DIR__ . '/trips/';
$CACHE_FILE = __DIR__ . '/.photomap-cache.json';
$THUMB_SIZE  = 240;
$RESIZED_MAX = 2048;
$DELETE_ORIGINALS_BELOW_MB = 0;
$EXTENSIONS  = ['jpg', 'jpeg', 'webp', 'png', 'heic', 'tiff', 'tif'];
$CACHE_VER   = 6;
$TRIP_COLORS = ['#6D5AD7','#D4604A','#3AA46E','#C44F7A','#3AA0A8','#C8921C','#4F64D4','#7A9C4F'];

// ── Image helpers ─────────────────────────────────────────────

function load_gd_image(string $path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match(true) {
        in_array($ext, ['jpg', 'jpeg']) => @imagecreatefromjpeg($path),
        $ext === 'png'                  => @imagecreatefrompng($path),
        $ext === 'webp'                 => @imagecreatefromwebp($path),
        default                         => false,
    };
}

function atomic_jpeg_write($img, string $dest, int $quality): bool {
    $tmp = $dest . '.tmp.' . getmypid();
    $ok  = imagejpeg($img, $tmp, $quality);
    if (!$ok || !rename($tmp, $dest)) { @unlink($tmp); return false; }
    return true;
}

function make_thumbnail(string $source, string $dest, int $size): bool {
    $src_img = load_gd_image($source);
    if (!$src_img) return false;
    $sw = imagesx($src_img); $sh = imagesy($src_img);
    if ($sw > $sh) { $ox = ($sw - $sh) / 2; $oy = 0; $sq = $sh; }
    else            { $ox = 0; $oy = ($sh - $sw) / 2; $sq = $sw; }
    $dst = imagecreatetruecolor($size, $size);
    imagecopyresampled($dst, $src_img, 0, 0, (int)$ox, (int)$oy, $size, $size, (int)$sq, (int)$sq);
    imagedestroy($src_img);
    $ok = atomic_jpeg_write($dst, $dest, 85);
    imagedestroy($dst);
    return $ok;
}

function make_resized(string $source, string $dest, int $max): bool {
    $src_img = load_gd_image($source);
    if (!$src_img) return false;
    $sw = imagesx($src_img); $sh = imagesy($src_img);
    if ($sw > $max || $sh > $max) {
        if ($sw > $sh) { $dw = $max; $dh = (int)round($sh * $max / $sw); }
        else            { $dh = $max; $dw = (int)round($sw * $max / $sh); }
        $dst = imagecreatetruecolor($dw, $dh);
        imagecopyresampled($dst, $src_img, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src_img);
        $ok = atomic_jpeg_write($dst, $dest, 85);
        imagedestroy($dst);
    } else {
        $ok = atomic_jpeg_write($src_img, $dest, 92);
        imagedestroy($src_img);
    }
    return $ok;
}

function serve_image(string $cached_path, int $source_mtime, string $etag): void {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $source_mtime) . ' GMT');
    header('ETag: "' . $etag . '"');
    $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if (($inm && str_contains($inm, '"' . $etag . '"'))
        || (!$inm && $ims && strtotime($ims) >= $source_mtime)) {
        http_response_code(304); exit;
    }
    header('Content-Length: ' . filesize($cached_path));
    readfile($cached_path);
    exit;
}

// ── Thumbnail endpoint ────────────────────────────────────────
if (isset($_GET['thumb'])) {
    $slug       = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['trip'] ?? '');
    $file       = basename($_GET['thumb'] ?? '');
    $trips_real = realpath($TRIPS_DIR);
    $trip_real  = ($slug && $trips_real) ? realpath($TRIPS_DIR . $slug) : false;
    if (!$file || $trip_real === false
            || !str_starts_with($trip_real, $trips_real . DIRECTORY_SEPARATOR)) {
        http_response_code(404); exit;
    }
    $source = $trip_real . DIRECTORY_SEPARATOR . $file;
    $real   = realpath($source);
    if ($real === false || !str_starts_with($real, $trip_real . DIRECTORY_SEPARATOR)) {
        http_response_code(404); exit;
    }
    $thumb_dir = $trip_real . DIRECTORY_SEPARATOR . '.thumbnails' . DIRECTORY_SEPARATOR;
    if (!is_dir($thumb_dir) && !@mkdir($thumb_dir, 0755, true)) { http_response_code(500); exit; }
    $thumb  = $thumb_dir . md5($file) . '.jpg';
    $imtime = (int)filemtime($source);
    if (!is_file($thumb) || (int)filemtime($thumb) < $imtime) {
        if (!make_thumbnail($source, $thumb, $THUMB_SIZE)) {
            header('Location: trips/' . rawurlencode($slug) . '/' . rawurlencode($file), true, 302);
            exit;
        }
    }
    serve_image($thumb, $imtime, md5($slug . $file . $imtime));
}

// ── Display-size image endpoint ───────────────────────────────
if (isset($_GET['full'])) {
    $slug       = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['trip'] ?? '');
    $file       = basename($_GET['full'] ?? '');
    $trips_real = realpath($TRIPS_DIR);
    $trip_real  = ($slug && $trips_real) ? realpath($TRIPS_DIR . $slug) : false;
    if (!$file || $trip_real === false
            || !str_starts_with($trip_real, $trips_real . DIRECTORY_SEPARATOR)) {
        http_response_code(404); exit;
    }
    $source = $trip_real . DIRECTORY_SEPARATOR . $file;
    $real   = realpath($source);
    if ($real === false || !str_starts_with($real, $trip_real . DIRECTORY_SEPARATOR)) {
        http_response_code(404); exit;
    }
    $resized_dir = $trip_real . DIRECTORY_SEPARATOR . '.resized' . DIRECTORY_SEPARATOR;
    if (!is_dir($resized_dir) && !@mkdir($resized_dir, 0755, true)) { http_response_code(500); exit; }
    $resized = $resized_dir . md5($file) . '.jpg';
    $imtime  = (int)filemtime($source);
    if (!is_file($resized) || (int)filemtime($resized) < $imtime) {
        if (!make_resized($source, $resized, $RESIZED_MAX)) {
            header('Location: trips/' . rawurlencode($slug) . '/' . rawurlencode($file), true, 302);
            exit;
        }
    }
    if ($DELETE_ORIGINALS_BELOW_MB > 0 && is_file($resized) && is_file($source)) {
        $free_mb = @disk_free_space(__DIR__) / 1048576;
        if ($free_mb !== false && $free_mb < $DELETE_ORIGINALS_BELOW_MB) @unlink($source);
    }
    serve_image($resized, $imtime, md5($slug . $file . $imtime));
}

// ── GPS helpers ───────────────────────────────────────────────

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
    $raw = file_get_contents($path, false, null, 0, 131072);
    if ($raw === false) return null;
    $exif_pos = strpos($raw, "Exif\x00\x00");
    if ($exif_pos === false) $exif_pos = strpos($raw, "EXIF\x00\x00");
    if ($exif_pos === false) return null;

    $tiff_start = $exif_pos + 6;
    $raw_len    = strlen($raw);
    if ($tiff_start + 8 > $raw_len) return null;
    $byte_order = substr($raw, $tiff_start, 2);
    $le         = ($byte_order === 'II');
    $read16     = function($o) use ($raw, $tiff_start, $raw_len, $le): int {
        if ($tiff_start + $o + 2 > $raw_len) return 0;
        return $le ? unpack('v', substr($raw, $tiff_start + $o, 2))[1]
                   : unpack('n', substr($raw, $tiff_start + $o, 2))[1];
    };
    $read32     = function($o) use ($raw, $tiff_start, $raw_len, $le): int {
        if ($tiff_start + $o + 4 > $raw_len) return 0;
        return $le ? unpack('V', substr($raw, $tiff_start + $o, 4))[1]
                   : unpack('N', substr($raw, $tiff_start + $o, 4))[1];
    };

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

// ── Cache ─────────────────────────────────────────────────────

function load_cache(string $path, int $ver): array {
    if (!is_file($path)) return ['v' => $ver, 'trips' => []];
    if (filesize($path) > 50 * 1024 * 1024) {
        error_log("photomap: cache file exceeds 50 MB, ignoring");
        return ['v' => $ver, 'trips' => []];
    }
    $data = @json_decode(@file_get_contents($path), true);
    if (!is_array($data)) return ['v' => $ver, 'trips' => []];
    if (($data['v'] ?? 0) !== $ver) {
        return [
            'v'         => $ver,
            'trips'     => [],
            'geo'       => $data['geo']       ?? [],
            'geo_v'     => $data['geo_v']      ?? 0,
            'geo_retry' => $data['geo_retry']  ?? [],
        ];
    }
    return $data;
}

function save_cache(string $path, array $cache): bool {
    $ok = file_put_contents($path, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($ok === false) { error_log("photomap: failed to write cache $path"); return false; }
    return true;
}

// ── Nominatim geocoding ───────────────────────────────────────

function nominatim_reverse(float $lat, float $lng): ?string {
    $url = sprintf(
        'https://nominatim.openstreetmap.org/reverse?lat=%.7f&lon=%.7f&format=json&zoom=15',
        $lat, $lng
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['User-Agent: PhotoMap/1.0', 'Accept-Language: en'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $json   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0 || $json === false || $json === '' || $status !== 200) return null;
    $d = json_decode($json, true);
    if (!is_array($d)) return null;

    $name  = $d['name'] ?? '';
    $class = $d['class'] ?? '';
    $poi_classes = ['tourism', 'historic', 'amenity', 'leisure', 'natural', 'man_made', 'building'];
    if ($name && in_array($class, $poi_classes, true)) return $name;

    $a = $d['address'] ?? [];
    return $a['road'] ?? $a['pedestrian'] ?? $a['footway'] ?? $a['path']
        ?? $a['neighbourhood'] ?? $a['suburb'] ?? $a['city_district']
        ?? $a['city'] ?? $a['town'] ?? $a['village'] ?? '';
}

// ── Scan all trips ────────────────────────────────────────────

$all_photos_with_gps    = [];
$all_photos_without_gps = [];
$pending_thumbs         = [];
$trip_meta              = [];  // slug => [label, color]
$trip_slugs             = [];

$requested_trip = null;
if (!empty($_GET['trip'])) {
    $t = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['trip']);
    if ($t !== '') $requested_trip = $t;
}

$cache       = load_cache($CACHE_FILE, $CACHE_VER);
$cache_dirty = false;
if (!isset($cache['trips'])) { $cache['trips'] = []; $cache_dirty = true; }

$GEO_VER = 3;
if (!isset($cache['geo']) || ($cache['geo_v'] ?? 0) !== $GEO_VER) {
    $cache['geo'] = []; $cache['geo_v'] = $GEO_VER; $cache_dirty = true;
}
if (!isset($cache['geo_retry'])) { $cache['geo_retry'] = []; $cache_dirty = true; }

if (is_dir($TRIPS_DIR)) {
    foreach (scandir($TRIPS_DIR) ?: [] as $d) {
        if ($d[0] !== '.' && is_dir($TRIPS_DIR . $d)) $trip_slugs[] = $d;
    }
    sort($trip_slugs);
}

if ($requested_trip && !in_array($requested_trip, $trip_slugs, true)) $requested_trip = null;

foreach ($trip_slugs as $cidx => $slug) {
    $trip_dir   = $TRIPS_DIR . $slug . DIRECTORY_SEPARATOR;
    $trip_label = ucwords(str_replace(['-', '_'], ' ', $slug));
    $trip_color = $TRIP_COLORS[$cidx % count($TRIP_COLORS)];
    $trip_meta[$slug] = ['label' => $trip_label, 'color' => $trip_color];

    if (!isset($cache['trips'][$slug])) {
        $cache['trips'][$slug] = ['dir_mtime' => 0, 'files' => [], 'f' => [], 'clean' => false];
    }
    $tc = &$cache['trips'][$slug];

    $dir_mtime   = (int)filemtime($trip_dir);
    $dir_changed = $tc['dir_mtime'] !== $dir_mtime || empty($tc['files']);
    if ($dir_changed) {
        $scanned = scandir($trip_dir) ?: [];
        usort($scanned, 'strcasecmp');
        $tc['files']     = array_values($scanned);
        $tc['dir_mtime'] = $dir_mtime;
        $tc['clean']     = false;
        $cache_dirty     = true;
    }

    // Fast path: skip all per-file syscalls when nothing has changed
    if (!$dir_changed && ($tc['clean'] ?? false)
            && isset($tc['photos_gps'], $tc['photos_no_gps'])) {
        foreach ($tc['photos_gps'] as $cached) {
            $geo_key = sprintf('%.3f,%.3f', $cached['lat'], $cached['lng']);
            $cached['loc']        = $cache['geo'][$geo_key] ?? null;
            $cached['trip_color'] = $trip_color;
            $all_photos_with_gps[] = $cached;
        }
        foreach ($tc['photos_no_gps'] as $cached) {
            $cached['trip_color'] = $trip_color;
            $all_photos_without_gps[] = $cached;
        }
        unset($tc);
        continue;
    }

    // Slow path: prune stale EXIF entries, scan files, check thumbnails
    $active_ext = array_flip(array_filter($tc['files'],
        fn($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $EXTENSIONS)));
    foreach (array_keys($tc['f']) as $cf) {
        if (!isset($active_ext[$cf])) { unset($tc['f'][$cf]); $cache_dirty = true; }
    }

    $thumb_dir_t   = $trip_dir . '.thumbnails' . DIRECTORY_SEPARATOR;
    $resized_dir_t = $trip_dir . '.resized'    . DIRECTORY_SEPARATOR;
    if (!is_dir($thumb_dir_t)   && !@mkdir($thumb_dir_t,   0755, true)) error_log("photomap: cannot create $thumb_dir_t");
    if (!is_dir($resized_dir_t) && !@mkdir($resized_dir_t, 0755, true)) error_log("photomap: cannot create $resized_dir_t");

    $trip_gps    = [];
    $trip_no_gps = [];
    $trip_clean  = true;

    foreach ($tc['files'] as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $EXTENSIONS)) continue;

        $full_path = $trip_dir . $file;
        $mtime     = (int)filemtime($full_path);

        $entry = $tc['f'][$file] ?? null;
        if ($entry && ($entry['m'] ?? 0) === $mtime) {
            $gps  = $entry['g'] ?? null;
            $date = $entry['d'] ?? '—';
        } else {
            ['gps' => $gps, 'date' => $date] = read_photo_meta($full_path);
            $tc['f'][$file] = ['m' => $mtime, 'g' => $gps, 'd' => $date];
            $cache_dirty = true;
        }

        $thumb_file   = $thumb_dir_t   . md5($file) . '.jpg';
        $resized_file = $resized_dir_t . md5($file) . '.jpg';
        if (!is_file($thumb_file)   || (int)filemtime($thumb_file)   < $mtime)
            { $pending_thumbs[] = ['src' => $full_path, 'dst' => $thumb_file,   'type' => 'thumb'];   $trip_clean = false; }
        if (!is_file($resized_file) || (int)filemtime($resized_file) < $mtime)
            { $pending_thumbs[] = ['src' => $full_path, 'dst' => $resized_file, 'type' => 'resized']; $trip_clean = false; }

        $qs = '&trip=' . rawurlencode($slug);
        $info = [
            'file'       => $file,
            'path'       => '?full='  . rawurlencode($file) . $qs,
            'thumb'      => '?thumb=' . rawurlencode($file) . $qs,
            'name'       => ucwords(str_replace(['_', '-'], ' ', pathinfo($file, PATHINFO_FILENAME))),
            'date'       => $date,
            'trip'       => $slug,
            'trip_label' => $trip_label,
            'trip_color' => $trip_color,
        ];

        if ($gps) {
            $lat = round($gps['lat'], 7);
            $lng = round($gps['lng'], 7);
            $geo_key = sprintf('%.3f,%.3f', $lat, $lng);
            $info['lat'] = $lat;
            $info['lng'] = $lng;
            $info['loc'] = $cache['geo'][$geo_key] ?? null;
            $trip_gps[]  = $info;
        } else {
            $trip_no_gps[] = $info;
        }
    }

    // Cache photo list (without dynamic 'loc') so future requests can use the fast path
    $tc['photos_gps']    = array_map(fn($p) => array_diff_key($p, ['loc' => 0]), $trip_gps);
    $tc['photos_no_gps'] = $trip_no_gps;
    $tc['clean']         = $trip_clean;
    $cache_dirty         = true;

    array_push($all_photos_with_gps,    ...$trip_gps);
    array_push($all_photos_without_gps, ...$trip_no_gps);
    unset($tc);
}

// Prune stale trip cache entries for deleted trip folders
foreach (array_keys($cache['trips']) as $cs) {
    if (!in_array($cs, $trip_slugs, true)) { unset($cache['trips'][$cs]); $cache_dirty = true; }
}

if ($cache_dirty) save_cache($CACHE_FILE, $cache);

// ── Locations API ─────────────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'locations') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $retry_before = time() - 86400;
    $locs = []; $pending = false;
    foreach ($all_photos_with_gps as $p) {
        $key = sprintf('%.3f,%.3f', $p['lat'], $p['lng']);
        if (array_key_exists($key, $cache['geo']) && $cache['geo'][$key] !== null) {
            $locs[$key] = $cache['geo'][$key];
        } elseif (!array_key_exists($key, $cache['geo'])
               || ($cache['geo'][$key] === null && ($cache['geo_retry'][$key] ?? 0) < $retry_before)) {
            $pending = true;
        }
    }
    echo json_encode(['locations' => $locs, 'pending' => $pending],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    exit;
}

// ── Photos API ────────────────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'photos') {
    $retry_before  = time() - 86400;
    $needs_geocode = false;
    foreach ($all_photos_with_gps as $p) {
        $key = sprintf('%.3f,%.3f', $p['lat'], $p['lng']);
        if (!array_key_exists($key, $cache['geo'])
            || ($cache['geo'][$key] === null && ($cache['geo_retry'][$key] ?? 0) < $retry_before)) {
            $needs_geocode = true; break;
        }
    }

    $trips_info = array_map(fn($slug) => [
        'slug'  => $slug,
        'label' => $trip_meta[$slug]['label'],
        'color' => $trip_meta[$slug]['color'],
    ], $trip_slugs);

    $json = json_encode([
        'photos'            => $all_photos_with_gps,
        'no_gps'            => $all_photos_without_gps,
        'geocoding_pending' => $needs_geocode,
        'trips'             => $trips_info,
        'active_trip'       => $requested_trip,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

    $accept_enc = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    $use_gzip   = function_exists('gzencode') && str_contains($accept_enc, 'gzip');
    $body_out   = $use_gzip ? gzencode($json, 6) : $json;

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: ' . ($needs_geocode ? 'no-store' : 'public, max-age=60, stale-while-revalidate=3600'));
    header('Content-Length: ' . mb_strlen($body_out, '8bit'));
    if ($use_gzip) header('Content-Encoding: gzip');
    echo $body_out;

    $has_bg = $needs_geocode || !empty($pending_thumbs);
    if ($has_bg) {
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else { @ob_end_flush(); flush(); }

        foreach ($pending_thumbs as $t) {
            if ($t['type'] === 'thumb') make_thumbnail($t['src'], $t['dst'], $THUMB_SIZE);
            else                        make_resized($t['src'], $t['dst'], $RESIZED_MAX);
        }

        if ($needs_geocode) {
            $lock = $CACHE_FILE . '.lock';
            if (is_file($lock) && filemtime($lock) < time() - 90) @unlink($lock);
            $fh = @fopen($lock, 'x');
            if ($fh) {
                set_time_limit(0);
                $geo_count = 0;
                foreach ($all_photos_with_gps as $p) {
                    $key = sprintf('%.3f,%.3f', $p['lat'], $p['lng']);
                    if (array_key_exists($key, $cache['geo'])
                        && !($cache['geo'][$key] === null && ($cache['geo_retry'][$key] ?? 0) < $retry_before)) {
                        continue;
                    }
                    $result = nominatim_reverse($p['lat'], $p['lng']);
                    $cache['geo'][$key] = $result;
                    if ($result === null) $cache['geo_retry'][$key] = time();
                    $geo_count++;
                    if ($geo_count % 10 === 0 && !save_cache($CACHE_FILE, $cache)) break;
                    sleep(1);
                }
                if ($geo_count % 10 !== 0) save_cache($CACHE_FILE, $cache);
                @fclose($fh);
                @unlink($lock);
            }
        }
    }
    exit;
}

// ── Main page ─────────────────────────────────────────────────
header('Cache-Control: public, max-age=60, stale-while-revalidate=3600');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

$total = count($all_photos_with_gps) + count($all_photos_without_gps);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($TRIP_NAME) ?></title>

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
.photo-marker.is-active{transform:translateY(-6px) scale(1.18);box-shadow:0 0 0 3px var(--mc, var(--accent)), var(--shadow-3);}
.photo-marker.is-active::before{content:"";position:absolute;inset:-10px;border-radius:999px;border:1px solid var(--mc, var(--accent));animation:ring 1.6s ease-out infinite;pointer-events:none;}
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

/* Trip pill-bar */
.trip-pills{display:flex;flex-wrap:wrap;gap:5px;padding:10px 14px;border-bottom:1px solid var(--ink-100);}
.trip-pill{display:flex;align-items:center;gap:5px;padding:4px 9px 4px 7px;border-radius:999px;border:1px solid var(--ink-200);font-size:10px;background:var(--paper);cursor:pointer;transition:background .15s, border-color .15s;white-space:nowrap;letter-spacing:0.04em;}
.trip-pill:hover{background:var(--ink-50)}
.trip-pill.is-active{background:var(--ink-100);border-color:var(--ink-600)}
.trip-pill .pill-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.trip-pill .pill-copy{display:inline-flex;align-items:center;margin-left:2px;opacity:0.45;transition:opacity .15s;}
.trip-pill .pill-copy:hover{opacity:1}
.trip-pill .pill-copy svg{display:block;}

.sidebar-body{flex:1;overflow-y:auto;padding:8px 12px 16px}
.sidebar-body::-webkit-scrollbar{width:8px}
.sidebar-body::-webkit-scrollbar-thumb{background:var(--ink-200);border-radius:4px}
.sidebar-body::-webkit-scrollbar-track{background:transparent}

/* Trip section headers (collapsible) */
.trip-header{display:flex;align-items:center;gap:9px;padding:14px 10px 10px;cursor:pointer;user-select:none;}
.trip-header:hover .trip-name{opacity:.8}
.trip-indicator{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.trip-name{font-family:var(--serif);font-size:17px;font-style:italic;flex:1;color:var(--ink);line-height:1;letter-spacing:-0.01em;}
.trip-count{font-size:10px;color:var(--ink-600);text-transform:uppercase;letter-spacing:0.1em;}
.trip-chevron{font-size:9px;color:var(--ink-600);transition:transform .2s;display:inline-block;}
.trip-header.is-collapsed .trip-chevron{transform:rotate(-90deg);}

.section-label{display:flex;align-items:center;gap:10px;padding:8px 10px 6px;font-size:10px;color:var(--ink-600);text-transform:uppercase;letter-spacing:0.14em;}
.section-label .line{flex:1;height:1px;background:var(--ink-100)}
.section-label .num{font-family:var(--serif);font-style:italic;font-size:14px;color:var(--ink);text-transform:none;letter-spacing:0}
.photo-row{display:flex;gap:12px;align-items:center;padding:8px 10px;border-radius:12px;cursor:pointer;transition:background .15s;position:relative;}
.photo-row:hover{background:var(--ink-50)}
.photo-row.is-active{background:var(--ink-50)}
.photo-row.is-active::before{content:"";position:absolute;left:-4px;top:18px;bottom:18px;width:2px;background:var(--tc, var(--accent));border-radius:2px;}
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
.lb-media .image{max-width:100%;max-height:100%;object-fit:contain;display:block;opacity:0;transition:opacity .25s;}
.lb-media.loaded .image{opacity:1}
@keyframes lb-spin{to{transform:rotate(360deg)}}
.lb-spinner{position:absolute;width:32px;height:32px;border:2px solid var(--ink-200);border-top-color:var(--accent);border-radius:50%;animation:lb-spin .7s linear infinite;opacity:1;transition:opacity .2s;}
.lb-media.loaded .lb-spinner{opacity:0;pointer-events:none}
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
.lb-location{position:absolute;bottom:24px;right:24px;z-index:2;font-family:var(--mono);font-size:11px;color:var(--paper);background:color-mix(in oklab, var(--ink) 60%, transparent);backdrop-filter:blur(6px);padding:6px 12px;border-radius:999px;letter-spacing:0.08em;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
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

/* ---------- Tablets and phones (≤900px) ---------- */
@media (max-width: 900px) {
  .legend-chip { display: none; }
  .leaflet-control-zoom { margin: 76px 12px 0 0 !important; }
}

/* ---------- Phones (≤720px) ---------- */
@media (max-width: 720px) {

  /* Header — collapse to brand mark + title + count */
  .app-header { padding: 12px 14px; }
  .brand { gap: 10px; }
  .brand h1 { font-size: 22px; }
  .brand .sub { display: none; }
  .header-meta { font-size: 10px; gap: 10px; }
  .header-meta > span:first-child { display: none; }
  .header-meta .count { font-size: 16px; }

  /* Sidebar as bottom sheet */
  .sidebar {
    top: auto;
    right: 8px;
    left: 8px;
    bottom: max(8px, env(safe-area-inset-bottom));
    width: auto;
    height: 42vh;
    height: 42dvh;
    border-radius: 18px;
  }
  .sidebar.is-collapsed {
    transform: translateY(calc(100% + 24px));
  }
  .sidebar-head { padding: 14px 16px 10px; }
  .sidebar-head .title { font-size: 18px; }
  .sidebar-head .meta { font-size: 9px; }
  .sidebar-head .close {
    width: 40px; height: 40px;
    margin: -6px -8px -6px 0;
  }
  .trip-pills { padding: 8px 10px; gap: 4px; overflow-x: auto; flex-wrap: nowrap; }
  .sidebar-body { padding: 4px 8px 12px; }
  .photo-row { padding: 10px 8px; }
  .photo-row .thumb { width: 52px; height: 52px; }

  /* Sidebar reopen — float above safe-area, bottom-right */
  .sidebar-toggle {
    top: auto;
    right: 14px;
    bottom: max(14px, env(safe-area-inset-bottom));
    width: 52px; height: 52px;
    border-radius: 999px;
  }
  .sidebar-toggle svg { width: 22px; height: 22px; }

  /* Larger marker hit area on touch */
  .photo-marker { width: 48px; height: 48px; }

  /* Leaflet controls */
  .leaflet-control-attribution {
    font-size: 9px !important;
    padding: 2px 6px !important;
  }
  .leaflet-control-zoom { margin: 70px 10px 0 0 !important; }
  .leaflet-control-zoom a { width: 40px !important; height: 40px !important; line-height: 40px !important; }

  /* ---- Lightbox: fullscreen-ish, single-column ---- */
  .lightbox .frame {
    width: 100vw; width: 100dvw;
    height: 100vh; height: 100dvh;
    max-width: none; max-height: none;
    border-radius: 0;
    margin: 0;
  }
  /* Top bar: close (right) + counter (left) */
  .lb-close {
    top: max(12px, env(safe-area-inset-top));
    right: 12px;
    width: 44px; height: 44px;
  }
  .lb-counter {
    top: max(14px, env(safe-area-inset-top));
    bottom: auto;
    left: 14px;
    padding: 8px 14px;
  }

  /* Nav arrows — smaller, tucked to edges */
  .lb-nav {
    width: 40px; height: 40px;
    background: color-mix(in oklab, var(--ink) 50%, transparent);
    color: var(--paper);
  }
  .lb-nav:hover { background: color-mix(in oklab, var(--ink) 65%, transparent); transform: translateY(-50%); }
  .lb-nav.prev { left: 8px; }
  .lb-nav.next { right: 8px; }

  /* Hide location chip on phone (info accessible via row) */
  .lb-location { display: none !important; }

  /* Thumb strip — full width row */
  .lb-thumbs {
    bottom: max(14px, env(safe-area-inset-bottom));
    left: 14px; right: 14px;
    transform: none;
    justify-content: center;
    overflow-x: auto;
    scrollbar-width: none;
    padding: 6px 8px;
  }
  .lb-thumbs::-webkit-scrollbar { display: none; }
  .lb-thumbs .t { width: 36px; height: 36px; flex-shrink: 0; }

  /* Empty state */
  .empty-state .card {
    padding: 28px 24px;
    margin: 0 16px;
    max-width: none;
  }
  .empty-state .card h2 { font-size: 22px; }
}

/* ---------- Very narrow phones (≤380px) ---------- */
@media (max-width: 380px) {
  .brand h1 { font-size: 20px; }
  .header-meta { display: none; }
  .sidebar { height: 50vh; height: 50dvh; }
}

/* ---------- Landscape phones ---------- */
@media (max-width: 900px) and (orientation: landscape) and (max-height: 500px) {
  .sidebar { height: 70vh; height: 70dvh; right: 8px; left: auto; width: 320px; }
  .sidebar.is-collapsed { transform: translateX(calc(100% + 24px)); }
  .lb-thumbs { display: none; }
}

/* ---------- Touch-pointer tweaks ---------- */
@media (pointer: coarse) {
  .photo-marker:hover { transform: none; }
  .lb-nav:hover { transform: translateY(-50%); }
}
</style>
</head>
<body>

<header class="app-header">
  <div class="brand">
    <span class="mark"></span>
    <h1><?= htmlspecialchars($TRIP_NAME) ?><em>.</em></h1>
    <span class="sub">Photo Map · <?= date('Y') ?></span>
  </div>
  <div class="header-meta">
    <span><span class="dot"></span>Live</span>
    <span><span class="count"><?= $total ?></span> photo<?= $total !== 1 ? 's' : '' ?></span>
  </div>
</header>

<div id="map"></div>

<?php if ($total === 0): ?>
<div class="empty-state">
  <div class="card">
    <h2>No Photos<em>.</em></h2>
    <p>Create trip folders inside the <code>trips/</code> directory and add photos to them.<br>
       E.g. <code>trips/paris-2025/</code><br>
       Supported formats: JPG, WebP, PNG, HEIC, TIFF.</p>
  </div>
</div>
<?php else: ?>

<aside class="sidebar" id="sidebar" aria-label="Photo index">
  <div class="sidebar-head">
    <div>
      <div class="title">Index<em>.</em></div>
      <div class="meta"><?= $total ?> photo<?= $total !== 1 ? 's' : '' ?> · <?= count($all_photos_with_gps) ?> with GPS</div>
    </div>
    <button class="close" id="sidebarClose" aria-label="Close sidebar">
      <svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
    </button>
  </div>
  <div class="trip-pills" id="tripPills"></div>
  <div class="sidebar-body" id="sidebarBody">
    <p style="padding:20px 10px;font-size:11px;color:var(--ink-600)">Loading…</p>
  </div>
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
</div>

<script>
// ── Map ───────────────────────────────────────────────────────
const map = L.map('map', { zoomControl: true, scrollWheelZoom: true })
  .setView([0, 0], 2);

L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
  attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> · CARTO',
  maxZoom: 19, subdomains: 'abcd',
}).addTo(map);

// ── Shared state ──────────────────────────────────────────────
let PHOTOS = [], NO_GPS = [], TRIPS = [];
let markers = [], markerCluster = null;
let lbCurrent = { list: 'gps', i: -1 };
let activeTrip = null;
const tripCollapsed = new Set();

// ── Utilities ─────────────────────────────────────────────────
const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

function fmtDateTime(s) {
  if (!s || s === '—') return '—';
  const exif = s.match(/^(\d{4}):(\d{2}):(\d{2})\s(\d{2}):(\d{2})/);
  if (exif) return `${exif[1]}/${exif[2]}/${exif[3]} ${exif[4]}:${exif[5]}`;
  const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (iso) return iso[4] ? `${iso[1]}/${iso[2]}/${iso[3]} ${iso[4]}:${iso[5]}` : `${iso[1]}/${iso[2]}/${iso[3]}`;
  return s;
}

// ── Geocoding refresh ─────────────────────────────────────────
function scheduleGeocodeRefresh() {
  setTimeout(() => {
    fetch('?api=locations')
      .then(r => r.json())
      .then(d => {
        let changed = false;
        for (const p of PHOTOS) {
          const key = `${p.lat.toFixed(3)},${p.lng.toFixed(3)}`;
          if (key in d.locations && p.loc !== d.locations[key]) {
            p.loc = d.locations[key]; changed = true;
          }
        }
        if (changed) renderVirtual();
        if (d.pending) scheduleGeocodeRefresh();
      })
      .catch(err => { console.warn('Geocode refresh failed:', err); scheduleGeocodeRefresh(); });
  }, 6000);
}

// ── Sidebar virtual scroll ────────────────────────────────────
const $body = document.getElementById('sidebarBody');
const ROW_H = 62, LABEL_H = 36, TRIP_H = 48, BUFFER = 8;

let FLAT = [], TOPS = null, TOTAL_H = 0;
let _rvStart = -1, _rvEnd = -1, _rvActiveKind = null, _rvActiveI = -1;

function itemHeight(item) {
  if (item.type === 'trip_header') return TRIP_H;
  if (item.type === 'label')       return LABEL_H;
  if (item.type === 'empty')       return LABEL_H;
  return ROW_H;
}

function rebuildFlat() {
  FLAT = [];
  const slugsToShow = activeTrip ? [activeTrip] : TRIPS.map(t => t.slug);

  for (const slug of slugsToShow) {
    const trip = TRIPS.find(t => t.slug === slug);
    if (!trip) continue;

    const tripGps   = PHOTOS.map((p, i) => ({ p, i })).filter(({ p }) => p.trip === slug);
    const tripNoGps = NO_GPS.map((p, i) => ({ p, i })).filter(({ p }) => p.trip === slug);
    if (!tripGps.length && !tripNoGps.length) continue;

    const collapsed = tripCollapsed.has(slug);
    FLAT.push({ type: 'trip_header', slug, label: trip.label, color: trip.color,
                gpsCount: tripGps.length, noGpsCount: tripNoGps.length, collapsed });

    if (!collapsed) {
      for (const { p, i } of tripGps)   FLAT.push({ type: 'row', kind: 'gps',   idx: i, p });
      for (const { p, i } of tripNoGps) FLAT.push({ type: 'row', kind: 'nogps', idx: i, p });
    }
  }

  if (!FLAT.length) FLAT.push({ type: 'empty' });

  const tops = new Int32Array(FLAT.length + 1);
  for (let i = 0; i < FLAT.length; i++) tops[i + 1] = tops[i] + itemHeight(FLAT[i]);
  TOPS    = tops;
  TOTAL_H = tops[FLAT.length];
  _rvStart = _rvEnd = -1;
}

function renderItem(item) {
  if (item.type === 'trip_header') {
    const cls = item.collapsed ? ' is-collapsed' : '';
    return `<div class="trip-header${cls}" data-slug="${esc(item.slug)}">` +
      `<span class="trip-indicator" style="background:${esc(item.color)}"></span>` +
      `<span class="trip-name">${esc(item.label)}</span>` +
      `<span class="trip-count">${item.gpsCount + item.noGpsCount}</span>` +
      `<span class="trip-chevron">▾</span></div>`;
  }
  if (item.type === 'label') {
    return `<div class="section-label"><span class="num">${item.count.toString().padStart(2,'0')}</span><span>${item.text}</span><span class="line"></span></div>`;
  }
  if (item.type === 'empty') {
    return `<p style="padding:10px;font-size:11px;color:var(--ink-600)">No photos found.</p>`;
  }
  const { kind, idx, p } = item;
  const active = lbCurrent.list === kind && lbCurrent.i === idx ? ' is-active' : '';
  const dateStr = fmtDateTime(p.date);
  let locHtml;
  if (kind === 'gps') {
    locHtml = p.loc ? `<span>${esc(p.loc)}</span>`
                    : `<span><span class="gps-dot">◉</span> ${p.lat.toFixed(4)}, ${p.lng.toFixed(4)}</span>`;
  } else {
    locHtml = '<span>no GPS</span>';
  }
  return `<div class="photo-row${kind === 'nogps' ? ' no-gps' : ''}${active}" data-kind="${kind}" data-idx="${idx}" style="--tc:${esc(p.trip_color)}">` +
    `<div class="thumb" style="background-image:url('${encodeURI(p.thumb)}')"></div>` +
    `<div class="info"><div class="name">${esc(dateStr)}</div><div class="meta">${locHtml}</div></div>` +
    `</div>`;
}

function renderVirtual() {
  if (!TOPS) return;
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

  if (start === _rvStart && end === _rvEnd
      && lbCurrent.list === _rvActiveKind && lbCurrent.i === _rvActiveI) return;
  _rvStart = start; _rvEnd = end;
  _rvActiveKind = lbCurrent.list; _rvActiveI = lbCurrent.i;

  let html = `<div style="height:${TOPS[start]}px" aria-hidden="true"></div>`;
  for (let i = start; i < end; i++) html += renderItem(FLAT[i]);
  html += `<div style="height:${Math.max(0, TOTAL_H - TOPS[end])}px" aria-hidden="true"></div>`;
  $body.innerHTML = html;
}

// Event delegation for sidebar clicks
$body.addEventListener('click', e => {
  const header = e.target.closest('.trip-header');
  if (header) {
    const slug = header.dataset.slug;
    if (tripCollapsed.has(slug)) tripCollapsed.delete(slug);
    else tripCollapsed.add(slug);
    rebuildFlat();
    renderVirtual();
    return;
  }
  const row = e.target.closest('.photo-row');
  if (row) {
    const kind = row.dataset.kind, idx = +row.dataset.idx;
    if (kind === 'gps') {
      markerCluster.zoomToShowLayer(markers[idx], () => {});
      openLightbox(idx, false);
    } else {
      openLightbox(idx, true);
    }
  }
});

function scrollSidebarTo(kind, i) {
  const fi = FLAT.findIndex(it => it.type === 'row' && it.kind === kind && it.idx === i);
  if (fi >= 0) {
    const top = TOPS[fi], bot = TOPS[fi + 1], st = $body.scrollTop, ch = $body.clientHeight;
    if (top < st + 8 || bot > st + ch - 8) $body.scrollTop = Math.max(0, top - 60);
  }
  renderVirtual();
}

$body.addEventListener('scroll', renderVirtual, { passive: true });

document.getElementById('sidebarClose').addEventListener('click', () =>
  document.getElementById('sidebar').classList.add('is-collapsed'));
document.getElementById('sidebarOpen').addEventListener('click', () => {
  document.getElementById('sidebar').classList.remove('is-collapsed');
  renderVirtual();
});

// ── Trip pill-bar ─────────────────────────────────────────────
const $pills = document.getElementById('tripPills');

function buildPillBar(trips, initialActive) {
  $pills.innerHTML = '';
  if (!trips.length) { $pills.style.display = 'none'; return; }

  const allBtn = document.createElement('button');
  allBtn.className = 'trip-pill' + (!initialActive ? ' is-active' : '');
  allBtn.dataset.trip = '';
  allBtn.textContent = 'All';
  allBtn.addEventListener('click', () => applyTripFilter(null));
  $pills.appendChild(allBtn);

  for (const trip of trips) {
    const btn = document.createElement('button');
    btn.className = 'trip-pill' + (initialActive === trip.slug ? ' is-active' : '');
    btn.dataset.trip = trip.slug;

    const dot = document.createElement('span');
    dot.className = 'pill-dot';
    dot.style.background = trip.color;

    const label = document.createElement('span');
    label.textContent = trip.label;

    const copyBtn = document.createElement('span');
    copyBtn.className = 'pill-copy';
    copyBtn.role = 'button';
    copyBtn.tabIndex = 0;
    copyBtn.title = 'Copy link to this trip';
    copyBtn.innerHTML = '<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
    const doCopy = e => {
      e.stopPropagation();
      const url = new URL(location.href);
      url.searchParams.set('trip', trip.slug);
      navigator.clipboard.writeText(url.toString()).catch(() => {});
      copyBtn.title = 'Copied!';
      setTimeout(() => { copyBtn.title = 'Copy link to this trip'; }, 1500);
    };
    copyBtn.addEventListener('click', doCopy);
    copyBtn.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); doCopy(e); } });

    btn.addEventListener('click', () => applyTripFilter(activeTrip === trip.slug ? null : trip.slug));
    btn.appendChild(dot);
    btn.appendChild(label);
    btn.appendChild(copyBtn);
    $pills.appendChild(btn);
  }
}

function applyTripFilter(slug) {
  activeTrip = slug;

  $pills.querySelectorAll('.trip-pill').forEach(btn => {
    btn.classList.toggle('is-active', btn.dataset.trip === (slug ?? ''));
  });

  markerCluster.clearLayers();
  markers.forEach((m, i) => {
    if (!slug || PHOTOS[i].trip === slug) markerCluster.addLayer(m);
  });

  const gps = slug ? PHOTOS.filter(p => p.trip === slug) : PHOTOS;
  if (gps.length > 0) {
    map.fitBounds(L.latLngBounds(gps.map(p => [p.lat, p.lng])), {
      paddingTopLeft: [20, 96], paddingBottomRight: [364, 24],
    });
  }

  rebuildFlat();
  renderVirtual();
}

// ── Lightbox ──────────────────────────────────────────────────
const $lb         = document.getElementById('lightbox');
const $lbMedia    = document.getElementById('lbMedia');
const $lbImage    = document.getElementById('lbImage');
const $lbNow      = document.getElementById('lbNow');
const $lbTotal    = document.getElementById('lbTotal');
const $lbThumbs   = document.getElementById('lbThumbs');
const $lbLocation = document.getElementById('lbLocation');
let activeMarkerEl = null;

const getList = kind => kind === 'gps' ? PHOTOS : NO_GPS;

const THUMB_WINDOW = 9;

function renderThumbs(kind, activeIdx) {
  const list  = getList(kind);
  const half  = Math.floor(THUMB_WINDOW / 2);
  const start = Math.max(0, Math.min(activeIdx - half, list.length - THUMB_WINDOW));
  const end   = Math.min(list.length, start + THUMB_WINDOW);
  $lbThumbs.innerHTML = '';
  for (let i = start; i < end; i++) {
    const div = document.createElement('div');
    div.className = 't' + (i === activeIdx ? ' is-active' : '');
    div.dataset.i = i;
    div.style.backgroundImage = `url('${encodeURI(list[i].thumb)}')`;
    div.addEventListener('click', () => showAt(lbCurrent.list, i));
    $lbThumbs.appendChild(div);
  }
}

function showAt(kind, i) {
  const list = getList(kind);
  if (!list.length) return;
  i = (i + list.length) % list.length;
  lbCurrent = { list: kind, i };
  const p = list[i];

  $lbMedia.classList.remove('loaded');
  $lbMedia._errEl = $lbMedia._errEl || (() => {
    const el = document.createElement('p');
    el.style.cssText = 'position:absolute;inset:0;display:none;align-items:center;justify-content:center;color:var(--ink-600);font-size:12px;margin:0';
    el.textContent = 'Could not load image';
    $lbMedia.appendChild(el);
    return el;
  })();
  $lbImage.onload  = () => { $lbMedia._errEl.style.display = 'none'; $lbMedia.classList.add('loaded'); };
  $lbImage.onerror = () => { $lbMedia._errEl.style.display = 'flex'; $lbMedia.classList.add('loaded'); };
  $lbImage.src = p.path;

  $lbNow.textContent   = (i+1).toString().padStart(2,'0');
  $lbTotal.textContent = list.length.toString().padStart(2,'0');

  if (kind === 'gps') {
    $lbLocation.textContent = p.loc || `${p.lat.toFixed(4)}, ${p.lng.toFixed(4)}`;
    $lbLocation.style.display = '';
  } else {
    $lbLocation.style.display = 'none';
  }

  if (activeMarkerEl) activeMarkerEl.classList.remove('is-active');
  activeMarkerEl = kind === 'gps'
    ? document.querySelector(`.photo-marker[data-idx="${i}"]`) : null;
  if (activeMarkerEl) activeMarkerEl.classList.add('is-active');
  scrollSidebarTo(kind, i);
  renderThumbs(kind, i);
}

function openLightbox(i, isNoGps = false) {
  const kind = isNoGps ? 'nogps' : 'gps';
  $lb.style.display = 'flex';
  requestAnimationFrame(() => $lb.classList.add('is-open'));
  $lb.setAttribute('aria-hidden', 'false');
  showAt(kind, i);
}

function closeLightbox() {
  $lb.classList.remove('is-open');
  $lb.setAttribute('aria-hidden', 'true');
  $lb.addEventListener('transitionend', () => { $lb.style.display = ''; }, { once: true });
  if (activeMarkerEl) { activeMarkerEl.classList.remove('is-active'); activeMarkerEl = null; }
  lbCurrent = { list: lbCurrent.list, i: -1 };
  _rvActiveI = -1;
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

// ── App init ──────────────────────────────────────────────────
function initApp(photos, noGps, trips, initialTrip) {
  PHOTOS = photos;
  NO_GPS = noGps;
  TRIPS  = trips;
  activeTrip = initialTrip ?? null;

  buildPillBar(trips, activeTrip);

  markerCluster = L.markerClusterGroup({
    maxClusterRadius: 60,
    showCoverageOnHover: false,
    iconCreateFunction(cluster) {
      const n = cluster.getChildCount();
      return L.divIcon({ html: `<div class="ci">${n}</div>`, className: 'photo-cluster', iconSize: [44, 44], iconAnchor: [22, 22] });
    },
  });

  markers = PHOTOS.map((p, i) => {
    const icon = L.divIcon({
      className: 'photo-marker-wrap',
      html: `<div class="photo-marker" data-idx="${i}" style="--mc:${p.trip_color}"><span class="thumb" style="background-image:url('${encodeURI(p.thumb)}')"></span></div>`,
      iconSize: [42, 42], iconAnchor: [21, 21],
    });
    const m = L.marker([p.lat, p.lng], { icon });
    m.on('click', () => openLightbox(i, false));
    return m;
  });

  // Add only markers for the active trip (or all if no filter)
  markers.forEach((m, i) => {
    if (!activeTrip || PHOTOS[i].trip === activeTrip) markerCluster.addLayer(m);
  });
  map.addLayer(markerCluster);

  // fitBounds to active trip or all photos
  const gpsForBounds = activeTrip ? PHOTOS.filter(p => p.trip === activeTrip) : PHOTOS;
  if (gpsForBounds.length > 0) {
    map.fitBounds(L.latLngBounds(gpsForBounds.map(p => [p.lat, p.lng])), {
      paddingTopLeft:     [20, 96],
      paddingBottomRight: [364, 24],
    });
  }

  rebuildFlat();
  renderVirtual();
}

fetch('?api=photos')
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
  });
</script>
<?php endif; ?>
</body>
</html>
