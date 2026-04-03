<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/settings.php';

function jobs_root(): string {
    return __DIR__ . '/var/jobs';
}

function job_dir(string $jobId): string {
    return jobs_root() . '/' . $jobId;
}

function is_valid_job_id(string $jobId): bool {
    return (bool)preg_match('/^[a-f0-9]{16,64}$/', $jobId);
}

function normalize_format(string $format): string {
    $format = strtolower(trim($format));
    if ($format === 'jpeg'){
        $format = 'jpg';
    }
    return in_array($format, ['png', 'jpg', 'gpx', 'kmz'], true) ? $format : 'png';
}

function to_bool(mixed $value): bool {
    if (is_bool($value)){
        return $value;
    }
    if (is_int($value)){
        return $value !== 0;
    }
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
}

function ensure_jpg_from_png(string $pngPath, string $jpgPath): void {
    if (!function_exists('imagecreatefrompng') || !function_exists('imagejpeg')){
        throw new RuntimeException('GD extension with PNG/JPEG support is required');
    }

    if (is_file($jpgPath) && filemtime($jpgPath) >= filemtime($pngPath)){
        return;
    }

    $src = @imagecreatefrompng($pngPath);
    if ($src === false){
        throw new RuntimeException('Cannot read PNG');
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $dst = imagecreatetruecolor($w, $h);
    if ($dst === false){
        imagedestroy($src);
        throw new RuntimeException('Cannot allocate image');
    }

    // Flatten alpha onto white background for JPEG.
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagealphablending($dst, true);
    imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

    $tmp = $jpgPath . '.tmp.' . bin2hex(random_bytes(6));
    $ok = imagejpeg($dst, $tmp, 90);
    imagedestroy($dst);
    imagedestroy($src);

    if (!$ok){
        @unlink($tmp);
        throw new RuntimeException('Cannot write JPG');
    }

    // Atomic-ish replace (same filesystem).
    @rename($tmp, $jpgPath);
    @unlink($tmp);
}

function tile_coord_from_lat_lon(float $lat, float $lon, int $zoom, bool $tms = false): array {
    $xtile = (int)floor((($lon + 180) / 360) * pow(2, $zoom));
    $ytile = (int)floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) / 2 * pow(2, $zoom));
    if ($tms){
        $ytile = (int)(pow(2, $zoom) - $ytile - 1);
    }
    return [$xtile, $ytile];
}

function tile2lon(int $x, int $zoom): float {
    $n = 1 << $zoom;
    return ($x / $n) * 360.0 - 180.0;
}

function tile2lat(int $y, int $zoom): float {
    $n = 1 << $zoom;
    $latRad = atan(sinh(pi() * (1 - 2 * $y / $n)));
    return rad2deg($latRad);
}

function build_4corners_gpx(int $zoom, bool $tms, array $colRange, array $rowRange): string {
    $colMin = (int)$colRange[0];
    $colMax = (int)$colRange[1];
    $rowMin = (int)$rowRange[0];
    $rowMax = (int)$rowRange[1];

    $lonLeft = tile2lon($colMin, $zoom);
    $lonRight = tile2lon($colMax + 1, $zoom);

    if ($tms){
        $n = 1 << $zoom;
        $yTop = ($n - 1) - $rowMax;
        $yBottom = ($n - 1) - $rowMin + 1;
    } else {
        $yTop = $rowMin;
        $yBottom = $rowMax + 1;
    }

    $latTop = tile2lat($yTop, $zoom);
    $latBottom = tile2lat($yBottom, $zoom);

    $fmt = fn(float $v): string => rtrim(rtrim(sprintf('%.8f', $v), '0'), '.');
    $wpts = [
        ['name' => 'NW', 'lat' => $latTop, 'lon' => $lonLeft],
        ['name' => 'NE', 'lat' => $latTop, 'lon' => $lonRight],
        ['name' => 'SE', 'lat' => $latBottom, 'lon' => $lonRight],
        ['name' => 'SW', 'lat' => $latBottom, 'lon' => $lonLeft],
    ];

    $out = [];
    $out[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $out[] = '<gpx version="1.1" creator="dl-map" xmlns="http://www.topografix.com/GPX/1/1">';
    foreach ($wpts as $w){
        $out[] = sprintf(
            '  <wpt lat="%s" lon="%s"><name>%s</name></wpt>',
            htmlspecialchars($fmt($w['lat']), ENT_QUOTES),
            htmlspecialchars($fmt($w['lon']), ENT_QUOTES),
            htmlspecialchars($w['name'], ENT_QUOTES)
        );
    }
    $out[] = '</gpx>';
    $out[] = '';
    return implode("\n", $out);
}

function kmz_content_type(): string {
    // Commonly used for Google Earth KMZ.
    return 'application/vnd.google-earth.kmz';
}

function build_kml_ground_overlay(float $north, float $south, float $east, float $west, string $imageHref): string {
    $fmt = fn(float $v): string => rtrim(rtrim(sprintf('%.8f', $v), '0'), '.');
    $kml = [];
    $kml[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $kml[] = '<kml xmlns="http://www.opengis.net/kml/2.2">';
    $kml[] = '<Folder>';
    $kml[] = ' <name>Map</name>';
    $kml[] = ' <GroundOverlay id="' . htmlspecialchars(basename($imageHref), ENT_QUOTES) . '">';
    $kml[] = '  <name>' . htmlspecialchars(basename($imageHref), ENT_QUOTES) . '</name>';
    $kml[] = '  <Icon>';
    $kml[] = '   <href>' . htmlspecialchars($imageHref, ENT_QUOTES) . '</href>';
    $kml[] = '  </Icon>';
    $kml[] = '  <LatLonBox>';
    $kml[] = '   <north>' . htmlspecialchars($fmt($north), ENT_QUOTES) . '</north>';
    $kml[] = '   <south>' . htmlspecialchars($fmt($south), ENT_QUOTES) . '</south>';
    $kml[] = '   <east>' . htmlspecialchars($fmt($east), ENT_QUOTES) . '</east>';
    $kml[] = '   <west>' . htmlspecialchars($fmt($west), ENT_QUOTES) . '</west>';
    $kml[] = '   <rotation>0</rotation>';
    $kml[] = '  </LatLonBox>';
    $kml[] = ' </GroundOverlay>';
    $kml[] = '</Folder>';
    $kml[] = '</kml>';
    $kml[] = '';
    return implode("\n", $kml);
}

function bounds_from_meta_or_input(string $jobId): array {
    $dir = job_dir($jobId);

    $metaPath = $dir . '/meta.json';
    if (is_file($metaPath)){
        $raw = file_get_contents($metaPath);
        if ($raw !== false){
            $meta = json_decode($raw, true);
            if (is_array($meta) && isset($meta['bounds']) && is_array($meta['bounds'])){
                $b = $meta['bounds'];
                $north = isset($b['north']) ? (float)$b['north'] : null;
                $south = isset($b['south']) ? (float)$b['south'] : null;
                $east = isset($b['east']) ? (float)$b['east'] : null;
                $west = isset($b['west']) ? (float)$b['west'] : null;
                if (is_float($north) && is_float($south) && is_float($east) && is_float($west) && is_finite($north) && is_finite($south) && is_finite($east) && is_finite($west)){
                    return ['north' => $north, 'south' => $south, 'east' => $east, 'west' => $west];
                }
            }
        }
    }

    $inputPath = $dir . '/input.json';
    if (!is_file($inputPath)){
        throw new RuntimeException('Missing input.json');
    }
    $raw = file_get_contents($inputPath);
    if ($raw === false){
        throw new RuntimeException('Cannot read input.json');
    }
    $input = json_decode($raw, true);
    if (!is_array($input)){
        throw new RuntimeException('Invalid input.json');
    }

    $settingsId = normalize_settings_id((string)($input['settings'] ?? ''));
    $settings = load_settings($settingsId);
    $zoom = (int)($settings['zoom'] ?? 0);
    if ($zoom <= 0){
        throw new RuntimeException('Invalid zoom');
    }
    $tms = (bool)($settings['tmc'] ?? false);

    $tileTopLeft = tile_coord_from_lat_lon((float)$input['latTopLeft'], (float)$input['lngTopLeft'], $zoom, $tms);
    $tileBottomRight = tile_coord_from_lat_lon((float)$input['latBottomRight'], (float)$input['lngBottomRight'], $zoom, $tms);

    $colRange = [min($tileTopLeft[0], $tileBottomRight[0]), max($tileTopLeft[0], $tileBottomRight[0])];
    $rowRange = [min($tileTopLeft[1], $tileBottomRight[1]), max($tileTopLeft[1], $tileBottomRight[1])];

    $colMin = (int)$colRange[0];
    $colMax = (int)$colRange[1];
    $rowMin = (int)$rowRange[0];
    $rowMax = (int)$rowRange[1];

    $west = tile2lon($colMin, $zoom);
    $east = tile2lon($colMax + 1, $zoom);

    if ($tms){
        $n = 1 << $zoom;
        $yTop = ($n - 1) - $rowMax;
        $yBottom = ($n - 1) - $rowMin + 1;
    } else {
        $yTop = $rowMin;
        $yBottom = $rowMax + 1;
    }

    $north = tile2lat($yTop, $zoom);
    $south = tile2lat($yBottom, $zoom);

    return ['north' => $north, 'south' => $south, 'east' => $east, 'west' => $west];
}

$jobId = (string)($_GET['job'] ?? '');
if (!is_valid_job_id($jobId)){
    http_response_code(400);
    echo "Invalid job id";
    exit;
}

$format = normalize_format((string)($_GET['format'] ?? 'png'));
$download = to_bool($_GET['download'] ?? false);

$pngPath = job_dir($jobId) . '/result.png';
if (!is_file($pngPath)){
    http_response_code(404);
    echo "Not ready";
    exit;
}

if ($format === 'gpx'){
    $gpxPath = job_dir($jobId) . '/4corners.gpx';
    header('Content-Type: application/gpx+xml; charset=UTF-8');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="4corners.gpx"');
    header('Cache-Control: no-store');
    if (is_file($gpxPath)){
        readfile($gpxPath);
        exit;
    }

    // Backfill on demand for older jobs.
    $inputPath = job_dir($jobId) . '/input.json';
    if (!is_file($inputPath)){
        http_response_code(404);
        echo "Not ready";
        exit;
    }

    $raw = file_get_contents($inputPath);
    if ($raw === false){
        http_response_code(500);
        echo "Cannot read input.json";
        exit;
    }
    $input = json_decode($raw, true);
    if (!is_array($input)){
        http_response_code(500);
        echo "Invalid input.json";
        exit;
    }

    try {
        $settingsId = normalize_settings_id((string)($input['settings'] ?? ''));
        $settings = load_settings($settingsId);
        $zoom = (int)($settings['zoom'] ?? 0);
        if ($zoom <= 0){
            throw new RuntimeException('Invalid zoom');
        }
        $tms = (bool)($settings['tmc'] ?? false);

        $tileTopLeft = tile_coord_from_lat_lon((float)$input['latTopLeft'], (float)$input['lngTopLeft'], $zoom, $tms);
        $tileBottomRight = tile_coord_from_lat_lon((float)$input['latBottomRight'], (float)$input['lngBottomRight'], $zoom, $tms);

        $colRange = [min($tileTopLeft[0], $tileBottomRight[0]), max($tileTopLeft[0], $tileBottomRight[0])];
        $rowRange = [min($tileTopLeft[1], $tileBottomRight[1]), max($tileTopLeft[1], $tileBottomRight[1])];

        $xml = build_4corners_gpx($zoom, $tms, $colRange, $rowRange);
        @file_put_contents($gpxPath, $xml);
        echo $xml;
        exit;
    } catch (Throwable $e){
        http_response_code(500);
        echo "GPX generation failed";
        exit;
    }
}

if ($format === 'kmz'){
    $jpgPath = job_dir($jobId) . '/result.jpg';
    try {
        ensure_jpg_from_png($pngPath, $jpgPath);
    } catch (Throwable $e){
        http_response_code(500);
        echo "JPEG conversion failed";
        exit;
    }

    if (!class_exists('ZipArchive')){
        http_response_code(500);
        echo "ZipArchive not available";
        exit;
    }

    try {
        $b = bounds_from_meta_or_input($jobId);
        $kml = build_kml_ground_overlay((float)$b['north'], (float)$b['south'], (float)$b['east'], (float)$b['west'], 'files/map.jpg');
    } catch (Throwable $e){
        http_response_code(500);
        echo "KMZ generation failed";
        exit;
    }

    $kmzPath = job_dir($jobId) . '/map.kmz';
    $tmp = $kmzPath . '.tmp.' . bin2hex(random_bytes(6));

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true){
        http_response_code(500);
        echo "Cannot create KMZ";
        exit;
    }
    $zip->addFromString('doc.kml', $kml);
    $zip->addEmptyDir('files');
    $zip->addFile($jpgPath, 'files/map.jpg');
    $zip->close();

    @rename($tmp, $kmzPath);
    @unlink($tmp);

    header('Content-Type: ' . kmz_content_type());
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="map.kmz"');
    header('Cache-Control: no-store');
    readfile($kmzPath);
    exit;
}

if ($format === 'jpg'){
    $jpgPath = job_dir($jobId) . '/result.jpg';
    try {
        ensure_jpg_from_png($pngPath, $jpgPath);
    } catch (Throwable $e){
        http_response_code(500);
        echo "JPEG conversion failed";
        exit;
    }

    header('Content-Type: image/jpeg');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="map.jpg"');
    header('Cache-Control: no-store');
    readfile($jpgPath);
    exit;
}

header('Content-Type: image/png');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="map.png"');
header('Cache-Control: no-store');
readfile($pngPath);
