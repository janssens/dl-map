<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';

app_boot_no_migrate();
if (!is_file(app_db_path())){
    db_migrate();
}
$user = auth_current_user();
if (!$user){
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unauthorized";
    exit;
}

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
    return in_array($format, ['png', 'jpg', 'gpx', 'kmz', 'pgw', 'omap'], true) ? $format : 'png';
}

function slugify_filename_part(string $value): string {
    $value = trim($value);
    if ($value === ''){
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== ''){
        $value = $ascii;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function load_job_layer_slug(string $jobId): string {
    $inputPath = job_dir($jobId) . '/input.json';
    if (!is_file($inputPath)){
        return 'map';
    }
    $raw = file_get_contents($inputPath);
    if ($raw === false){
        return 'map';
    }
    $input = json_decode($raw, true);
    if (!is_array($input)){
        return 'map';
    }

    $settingsId = normalize_settings_id((string)($input['settings'] ?? ''));
    if ($settingsId === ''){
        return 'map';
    }

    try {
        $settings = load_settings($settingsId);
    } catch (Throwable $e){
        return 'map';
    }

    $layerName = (string)($settings['layer'] ?? '');
    $slug = slugify_filename_part($layerName);
    return $slug !== '' ? $slug : 'map';
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

function center_from_bounds(array $b): array {
    $north = (float)($b['north'] ?? 0.0);
    $south = (float)($b['south'] ?? 0.0);
    $east = (float)($b['east'] ?? 0.0);
    $west = (float)($b['west'] ?? 0.0);
    return [
        'lat' => ($north + $south) / 2.0,
        'lon' => ($east + $west) / 2.0,
    ];
}

function utm_zone_for_lon(float $lonDeg): int {
    $zone = (int)floor(($lonDeg + 180.0) / 6.0) + 1;
    return max(1, min(60, $zone));
}

/**
 * @return array{zone:int,hemisphere:string,lonOriginDeg:float,easting:float,northing:float}
 */
function utm_from_lat_lon(float $latDeg, float $lonDeg, ?int $zoneOverride = null): array {
    $a = 6378137.0;
    $f = 1.0 / 298.257223563;
    $e2 = $f * (2 - $f);
    $ePrime2 = $e2 / (1 - $e2);
    $k0 = 0.9996;

    $zone = $zoneOverride ?? utm_zone_for_lon($lonDeg);
    $lonOriginDeg = ($zone - 1) * 6 - 180 + 3;

    $latRad = deg2rad($latDeg);
    $lonRad = deg2rad($lonDeg);
    $lonOriginRad = deg2rad($lonOriginDeg);

    $sinLat = sin($latRad);
    $cosLat = cos($latRad);
    $tanLat = tan($latRad);

    $N = $a / sqrt(1 - $e2 * $sinLat * $sinLat);
    $T = $tanLat * $tanLat;
    $C = $ePrime2 * $cosLat * $cosLat;
    $A = $cosLat * ($lonRad - $lonOriginRad);

    $e4 = $e2 * $e2;
    $e6 = $e4 * $e2;
    $M = $a * (
        (1 - $e2 / 4 - 3 * $e4 / 64 - 5 * $e6 / 256) * $latRad
        - (3 * $e2 / 8 + 3 * $e4 / 32 + 45 * $e6 / 1024) * sin(2 * $latRad)
        + (15 * $e4 / 256 + 45 * $e6 / 1024) * sin(4 * $latRad)
        - (35 * $e6 / 3072) * sin(6 * $latRad)
    );

    $easting = $k0 * $N * (
        $A
        + (1 - $T + $C) * ($A ** 3) / 6
        + (5 - 18 * $T + $T * $T + 72 * $C - 58 * $ePrime2) * ($A ** 5) / 120
    ) + 500000.0;

    $northing = $k0 * (
        $M + $N * $tanLat * (
            ($A ** 2) / 2
            + (5 - $T + 9 * $C + 4 * $C * $C) * ($A ** 4) / 24
            + (61 - 58 * $T + $T * $T + 600 * $C - 330 * $ePrime2) * ($A ** 6) / 720
        )
    );

    $hemisphere = $latDeg < 0 ? 'S' : 'N';
    if ($latDeg < 0){
        $northing += 10000000.0;
    }

    return [
        'zone' => $zone,
        'hemisphere' => $hemisphere,
        'lonOriginDeg' => $lonOriginDeg,
        'easting' => $easting,
        'northing' => $northing,
    ];
}

function utm_grid_convergence_deg(float $latDeg, float $lonDeg, int $zone): float {
    // Approx. meridian convergence (sufficient for small areas).
    $lonOriginDeg = ($zone - 1) * 6 - 180 + 3;
    $lat = deg2rad($latDeg);
    $dlon = deg2rad($lonDeg - $lonOriginDeg);
    $gamma = atan(tan($dlon) * sin($lat));
    return rad2deg($gamma);
}

function utm_grid_scale_factor(float $latDeg, float $lonDeg, int $zone): float {
    // Point scale factor for UTM (series up to A^4).
    $a = 6378137.0;
    $f = 1.0 / 298.257223563;
    $e2 = $f * (2 - $f);
    $ePrime2 = $e2 / (1 - $e2);
    $k0 = 0.9996;

    $lonOriginDeg = ($zone - 1) * 6 - 180 + 3;
    $lat = deg2rad($latDeg);
    $dlon = deg2rad($lonDeg - $lonOriginDeg);

    $sinLat = sin($lat);
    $cosLat = cos($lat);
    $tanLat = tan($lat);

    $T = $tanLat * $tanLat;
    $C = $ePrime2 * $cosLat * $cosLat;
    $A = $cosLat * $dlon;

    // N is not needed for k series here.
    $A2 = $A * $A;
    $A4 = $A2 * $A2;

    $k = $k0 * (1.0
        + (1.0 + $C) * $A2 / 2.0
        + (5.0 - 4.0 * $T + 42.0 * $C + 13.0 * $C * $C - 28.0 * $ePrime2) * $A4 / 24.0
    );
    return $k;
}

function fmt_float_dot(float $v, int $digits = 8): string {
    return rtrim(rtrim(sprintf('%.' . $digits . 'F', $v), '0'), '.');
}

function decimal_year_from_iso8601(string $iso): float {
    try {
        $dt = new DateTimeImmutable($iso);
    } catch (Throwable $e) {
        $dt = new DateTimeImmutable('now');
    }
    $year = (int)$dt->format('Y');
    $start = new DateTimeImmutable(sprintf('%04d-01-01T00:00:00Z', $year));
    $end = $start->modify('+1 year');
    $secondsInYear = (float)($end->getTimestamp() - $start->getTimestamp());
    $secondsIntoYear = (float)($dt->getTimestamp() - $start->getTimestamp());
    if ($secondsInYear <= 0){
        return (float)$year;
    }
    return (float)$year + max(0.0, min(0.999999, $secondsIntoYear / $secondsInYear));
}

function compute_declination_deg_fallback(string $jobId, float $lat, float $lon): ?float {
    $inputPath = job_dir($jobId) . '/input.json';
    $createdAt = 'now';
    if (is_file($inputPath)){
        $raw = file_get_contents($inputPath);
        if ($raw !== false){
            $input = json_decode($raw, true);
            if (is_array($input) && isset($input['createdAt']) && is_string($input['createdAt'])){
                $createdAt = $input['createdAt'];
            }
        }
    }

    $cofPath = __DIR__ . '/assets/wmm/WMM2025COF/WMM2025.COF';
    if (!is_file($cofPath)){
        return null;
    }

    try {
        require_once __DIR__ . '/lib/GeomagWmm.php';
        $wmm = GeomagWmm::fromCofFile($cofPath);
        $decimalYear = decimal_year_from_iso8601($createdAt);
        $decl = $wmm->declinationDegrees($lat, $lon, $decimalYear, 0.0);
        return is_finite($decl) ? (float)$decl : null;
    } catch (Throwable $e){
        return null;
    }
}

function ensure_kmz_from_png(string $jobId, string $pngPath, string $kmzPath): void {
    $dir = job_dir($jobId);
    if (is_file($kmzPath) && filemtime($kmzPath) >= filemtime($pngPath)){
        return;
    }

    $jpgPath = $dir . '/result.jpg';
    ensure_jpg_from_png($pngPath, $jpgPath);

    $b = bounds_from_meta_or_input($jobId);
    $kml = build_kml_ground_overlay((float)$b['north'], (float)$b['south'], (float)$b['east'], (float)$b['west'], 'files/map.jpg');

    if (!class_exists('ZipArchive')){
        throw new RuntimeException('ZipArchive not available');
    }

    $tmp = $kmzPath . '.tmp.' . bin2hex(random_bytes(6));
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true){
        throw new RuntimeException('Cannot create KMZ');
    }
    $zip->addFromString('doc.kml', $kml);
    $zip->addEmptyDir('files');
    $zip->addFile($jpgPath, 'files/map.jpg');
    $zip->close();

    @rename($tmp, $kmzPath);
    @unlink($tmp);
}

/**
 * @param array{zone:int,hemisphere:string,easting:float,northing:float} $utm
 * @param array{lat:float,lon:float} $center
 * @param array{north:float,south:float,east:float,west:float} $bounds
 */
function build_omap_xml(array $utm, array $center, array $bounds, float $declinationDeg, float $gridScaleFactor, float $grivationDeg, string $kmzRelPath): string {
    $zone = (int)$utm['zone'];
    $utmSpec = '+proj=utm +datum=WGS84 +zone=' . $zone . ($utm['hemisphere'] === 'S' ? ' +south' : '');
    $utmParam = $zone . ' ' . ($utm['hemisphere'] === 'S' ? 'S' : 'N');

    $xml = [];
    $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml[] = '<map xmlns="http://openorienteering.org/apps/mapper/xml/v2" version="9">';
    $xml[] = '<notes></notes>';
    $xml[] = '<georeferencing scale="25000" grid_scale_factor="' . htmlspecialchars(fmt_float_dot($gridScaleFactor, 6), ENT_QUOTES) . '" auxiliary_scale_factor="1" declination="' . htmlspecialchars(fmt_float_dot($declinationDeg, 2), ENT_QUOTES) . '" grivation="' . htmlspecialchars(fmt_float_dot($grivationDeg, 2), ENT_QUOTES) . '">';
    $xml[] = '  <projected_crs id="UTM">';
    $xml[] = '    <spec language="PROJ.4">' . htmlspecialchars($utmSpec, ENT_QUOTES) . '</spec>';
    $xml[] = '    <parameter>' . htmlspecialchars($utmParam, ENT_QUOTES) . '</parameter>';
    $xml[] = '    <ref_point x="' . htmlspecialchars(fmt_float_dot((float)$utm['easting'], 2), ENT_QUOTES) . '" y="' . htmlspecialchars(fmt_float_dot((float)$utm['northing'], 2), ENT_QUOTES) . '"/>';
    $xml[] = '  </projected_crs>';
    $xml[] = '  <geographic_crs id="Geographic coordinates">';
    $xml[] = '    <spec language="PROJ.4">+proj=latlong +datum=WGS84</spec>';
    $xml[] = '    <ref_point_deg lat="' . htmlspecialchars(fmt_float_dot((float)$center['lat'], 8), ENT_QUOTES) . '" lon="' . htmlspecialchars(fmt_float_dot((float)$center['lon'], 8), ENT_QUOTES) . '"/>';
    $xml[] = '  </geographic_crs>';
    $xml[] = '</georeferencing>';

    // Minimal color set (keeps Mapper happy, while the map is mainly the raster template).
    $xml[] = '<colors count="2">';
    $xml[] = '<color priority="0" name="Purple" c="0.2" m="1" y="0" k="0" opacity="1"><cmyk method="custom"/><rgb method="cmyk" r="0.8" g="0" b="1"/></color>';
    $xml[] = '<color priority="1" name="Black" c="0" m="0" y="0" k="1" opacity="1"><cmyk method="custom"/><rgb method="cmyk" r="0" g="0" b="0"/></color>';
    $xml[] = '</colors>';

    $xml[] = '<symbols count="0" id="OCD"></symbols>';
    $xml[] = '<parts count="1" current="0"><part name="partie par défaut"><objects count="0"></objects></part></parts>';

    $xml[] = '<templates count="1" first_front_template="1">';
    $xml[] = '  <template type="OgrTemplate" open="true" name="' . htmlspecialchars(basename($kmzRelPath), ENT_QUOTES) . '" path="' . htmlspecialchars($kmzRelPath, ENT_QUOTES) . '" relpath="' . htmlspecialchars($kmzRelPath, ENT_QUOTES) . '" georef="true"/>';
    $xml[] = '  <defaults use_meters_per_pixel="true" meters_per_pixel="0" dpi="0" scale="0"/>';
    $xml[] = '</templates>';

    $xml[] = '<view><grid color="#646464" display="0" alignment="0" additional_rotation="0" unit="1" h_spacing="500" v_spacing="500" h_offset="0" v_offset="0" snapping_enabled="true"/>';
    $xml[] = '<map_view zoom="0.5" position_x="0" position_y="0"><map opacity="1" visible="true"/><templates count="1"><ref template="0" visible="true" opacity="1"/></templates></map_view>';
    $xml[] = '</view>';
    $xml[] = '</map>';
    $xml[] = '';
    return implode("\n", $xml);
}

function build_pgw_from_bounds_and_image(array $bounds, int $width, int $height): string {
    if ($width <= 0 || $height <= 0){
        throw new RuntimeException('Invalid image dimensions');
    }
    $north = (float)($bounds['north'] ?? 0.0);
    $south = (float)($bounds['south'] ?? 0.0);
    $east = (float)($bounds['east'] ?? 0.0);
    $west = (float)($bounds['west'] ?? 0.0);

    if (!is_finite($north) || !is_finite($south) || !is_finite($east) || !is_finite($west)){
        throw new RuntimeException('Invalid bounds');
    }
    if ($north === $south || $east === $west){
        throw new RuntimeException('Degenerate bounds');
    }

    $pixelSizeX = ($east - $west) / (float)$width;
    $pixelSizeY = -($north - $south) / (float)$height;

    // World file uses the center of the upper-left pixel.
    $ulCenterX = $west + ($pixelSizeX / 2.0);
    $ulCenterY = $north + ($pixelSizeY / 2.0);

    $fmt = static fn(float $v): string => rtrim(rtrim(sprintf('%.12F', $v), '0'), '.');

    $lines = [
        $fmt($pixelSizeX),
        '0',
        '0',
        $fmt($pixelSizeY),
        $fmt($ulCenterX),
        $fmt($ulCenterY),
        '',
    ];
    return implode("\n", $lines);
}

$jobId = (string)($_GET['job'] ?? '');
if (!is_valid_job_id($jobId)){
    http_response_code(400);
    echo "Invalid job id";
    exit;
}

$format = normalize_format((string)($_GET['format'] ?? 'png'));
$download = to_bool($_GET['download'] ?? false);
$downloadBaseName = load_job_layer_slug($jobId);

$pngPath = job_dir($jobId) . '/result.png';
if (!is_file($pngPath)){
    http_response_code(404);
    echo "Not ready";
    exit;
}

if ($format === 'omap'){
    if (!class_exists('ZipArchive')){
        http_response_code(500);
        echo "ZipArchive not available";
        exit;
    }

    try {
        $b = bounds_from_meta_or_input($jobId);
        $center = center_from_bounds($b);

        $zone = utm_zone_for_lon((float)$center['lon']);
        $utm = utm_from_lat_lon((float)$center['lat'], (float)$center['lon'], $zone);
        $gridScaleFactor = utm_grid_scale_factor((float)$center['lat'], (float)$center['lon'], $zone);
        $grivationDeg = utm_grid_convergence_deg((float)$center['lat'], (float)$center['lon'], $zone);

        $declinationDeg = 0.0;
        $metaPath = job_dir($jobId) . '/meta.json';
        if (is_file($metaPath)){
            $rawMeta = file_get_contents($metaPath);
            if ($rawMeta !== false){
                $meta = json_decode($rawMeta, true);
                if (is_array($meta) && isset($meta['magnetic']) && is_array($meta['magnetic']) && isset($meta['magnetic']['declinationDeg'])){
                    $d = (float)$meta['magnetic']['declinationDeg'];
                    if (is_finite($d)){
                        $declinationDeg = $d;
                    }
                }
            }
        }
        if ($declinationDeg === 0.0){
            $fallback = compute_declination_deg_fallback($jobId, (float)$center['lat'], (float)$center['lon']);
            if (is_float($fallback)){
                $declinationDeg = $fallback;
            }
        }

        $kmzPath = job_dir($jobId) . '/map.kmz';
        ensure_kmz_from_png($jobId, $pngPath, $kmzPath);

        $omapXml = build_omap_xml(
            ['zone' => $utm['zone'], 'hemisphere' => $utm['hemisphere'], 'easting' => $utm['easting'], 'northing' => $utm['northing']],
            ['lat' => (float)$center['lat'], 'lon' => (float)$center['lon']],
            ['north' => (float)$b['north'], 'south' => (float)$b['south'], 'east' => (float)$b['east'], 'west' => (float)$b['west']],
            $declinationDeg,
            $gridScaleFactor,
            $grivationDeg,
            'map.kmz'
        );
    } catch (Throwable $e){
        http_response_code(500);
        echo "OMAP generation failed";
        exit;
    }

    $omapPath = job_dir($jobId) . '/map.omap';
    @file_put_contents($omapPath, $omapXml);

    $zipPath = job_dir($jobId) . '/map_omap.zip';
    $tmp = $zipPath . '.tmp.' . bin2hex(random_bytes(6));
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true){
        http_response_code(500);
        echo "Cannot create ZIP";
        exit;
    }
    $zip->addFile($omapPath, 'map.omap');
    $zip->addFile(job_dir($jobId) . '/map.kmz', 'map.kmz');
    $zip->close();
    @rename($tmp, $zipPath);
    @unlink($tmp);

    header('Content-Type: application/zip');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $downloadBaseName . '_omap.zip"');
    header('Cache-Control: no-store');
    readfile($zipPath);
    exit;
}

if ($format === 'pgw'){
    $pgwPath = job_dir($jobId) . '/map.pgw';
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $downloadBaseName . '.pgw"');
    header('Cache-Control: no-store');

    if (!is_file($pgwPath) || filemtime($pgwPath) < filemtime($pngPath)){
        try {
            $b = bounds_from_meta_or_input($jobId);
            $info = @getimagesize($pngPath);
            if (!is_array($info) || !isset($info[0], $info[1])){
                throw new RuntimeException('Cannot read image size');
            }
            $pgw = build_pgw_from_bounds_and_image($b, (int)$info[0], (int)$info[1]);
            @file_put_contents($pgwPath, $pgw);
        } catch (Throwable $e){
            http_response_code(500);
            echo "PGW generation failed";
            exit;
        }
    }

    readfile($pgwPath);
    exit;
}

if ($format === 'gpx'){
    $gpxPath = job_dir($jobId) . '/4corners.gpx';
    header('Content-Type: application/gpx+xml; charset=UTF-8');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $downloadBaseName . '.gpx"');
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
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $downloadBaseName . '.kmz"');
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
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $downloadBaseName . '.jpg"');
    header('Cache-Control: no-store');
    readfile($jpgPath);
    exit;
}

header('Content-Type: image/png');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $downloadBaseName . '.png"');
header('Cache-Control: no-store');
readfile($pngPath);
