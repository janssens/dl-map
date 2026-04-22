<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/MapGenerator.php';
require_once __DIR__ . '/lib/GeomagWmm.php';

function jobs_root(): string {
    return __DIR__ . '/var/jobs';
}

function is_valid_job_id(string $jobId): bool {
    return (bool)preg_match('/^[a-f0-9]{16,64}$/', $jobId);
}

function job_dir(string $jobId): string {
    return jobs_root() . '/' . $jobId;
}

function write_status(string $jobId, array $data): void {
    $path = job_dir($jobId) . '/status.json';
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

function utm_band_letter(float $lat): string {
    if ($lat >= 84 || $lat < -80){
        return 'Z';
    }
    $bands = 'CDEFGHJKLMNPQRSTUVWX';
    $idx = (int)floor(($lat + 80) / 8);
    return $bands[max(0, min(strlen($bands) - 1, $idx))];
}

function utm_from_lat_lon(float $latDeg, float $lonDeg): array {
    $a = 6378137.0;
    $f = 1.0 / 298.257223563;
    $e2 = $f * (2 - $f);
    $ePrime2 = $e2 / (1 - $e2);
    $k0 = 0.9996;

    $zone = (int)floor(($lonDeg + 180.0) / 6.0) + 1;
    $zone = max(1, min(60, $zone));
    $lonOrigin = ($zone - 1) * 6 - 180 + 3;

    $latRad = deg2rad($latDeg);
    $lonRad = deg2rad($lonDeg);
    $lonOriginRad = deg2rad($lonOrigin);

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
        'band' => utm_band_letter($latDeg),
        'hemisphere' => $hemisphere,
        'easting' => $easting,
        'northing' => $northing,
    ];
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

function map_bounds_from_tiles(int $zoom, bool $tms, array $colRange, array $rowRange): array {
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

    return [
        'latTop' => $latTop,
        'latBottom' => $latBottom,
        'lonLeft' => $lonLeft,
        'lonRight' => $lonRight,
        'centerLat' => ($latTop + $latBottom) / 2.0,
        'centerLon' => ($lonLeft + $lonRight) / 2.0,
    ];
}

function build_4corners_gpx(array $tileBounds): string {
    $zoom = (int)($tileBounds['zoom'] ?? 0);
    $tms = (bool)($tileBounds['tms'] ?? false);
    $colRange = $tileBounds['colRange'] ?? null;
    $rowRange = $tileBounds['rowRange'] ?? null;
    if ($zoom <= 0 || !is_array($colRange) || !is_array($rowRange) || count($colRange) !== 2 || count($rowRange) !== 2){
        throw new RuntimeException('Missing tile bounds');
    }

    $bounds = map_bounds_from_tiles($zoom, $tms, $colRange, $rowRange);
    $latTop = (float)$bounds['latTop'];
    $latBottom = (float)$bounds['latBottom'];
    $lonLeft = (float)$bounds['lonLeft'];
    $lonRight = (float)$bounds['lonRight'];

    $fmt = fn(float $v): string => rtrim(rtrim(sprintf('%.8f', $v), '0'), '.');
    $wpts = [
        ['name' => 'NW', 'lat' => $latTop, 'lon' => $lonLeft],
        ['name' => 'NE', 'lat' => $latTop, 'lon' => $lonRight],
        ['name' => 'SE', 'lat' => $latBottom, 'lon' => $lonRight],
        ['name' => 'SW', 'lat' => $latBottom, 'lon' => $lonLeft],
    ];

    $out = [];
    $out[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $out[] = '<gpx version="1.1" creator="Casse dalles" xmlns="http://www.topografix.com/GPX/1/1">';
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

$jobId = (string)($argv[1] ?? '');
if (!is_valid_job_id($jobId)){
    fwrite(STDERR, "Invalid job id\n");
    exit(2);
}

$dir = job_dir($jobId);
$inputPath = $dir . '/input.json';
if (!is_file($inputPath)){
    write_status($jobId, ['state' => 'error', 'error' => 'Missing input.json']);
    exit(2);
}

$raw = file_get_contents($inputPath);
if ($raw === false){
    write_status($jobId, ['state' => 'error', 'error' => 'Cannot read input.json']);
    exit(2);
}

$input = json_decode($raw, true);
if (!is_array($input)){
    write_status($jobId, ['state' => 'error', 'error' => 'Invalid input.json']);
    exit(2);
}

try {
    write_status($jobId, [
        'state' => 'running',
        'done' => 0,
        'total' => 0,
        'percent' => 0,
        'message' => 'Démarrage…',
    ]);

    $generator = new MapGenerator();
    $result = $generator->generate($input, $dir, function(array $p) use ($jobId){
        write_status($jobId, [
            'state' => 'running',
            'done' => (int)($p['done'] ?? 0),
            'total' => (int)($p['total'] ?? 0),
            'percent' => (int)($p['percent'] ?? 0),
            'message' => (string)($p['message'] ?? ''),
        ]);
    });

    $finalPath = $result['finalPath'] ?? null;
    if (!$finalPath || !is_file($finalPath)){
        throw new RuntimeException('Final image not found');
    }

    $resultPath = $dir . '/result.png';
    copy($finalPath, $resultPath);

    $gpxPath = $dir . '/4corners.gpx';
    file_put_contents($gpxPath, build_4corners_gpx($result));

    $meta = [];
    try {
        $bounds = map_bounds_from_tiles((int)$result['zoom'], (bool)$result['tms'], (array)$result['colRange'], (array)$result['rowRange']);
        $centerLat = (float)$bounds['centerLat'];
        $centerLon = (float)$bounds['centerLon'];

        $utm = utm_from_lat_lon($centerLat, $centerLon);
        $decimalYear = decimal_year_from_iso8601((string)($input['createdAt'] ?? 'now'));

        $cofPath = __DIR__ . '/assets/wmm/WMM2025COF/WMM2025.COF';
        $wmm = GeomagWmm::fromCofFile($cofPath);
        $decl = $wmm->declinationDegrees($centerLat, $centerLon, $decimalYear, 0.0);

        $meta = [
            'bounds' => [
                'north' => (float)$bounds['latTop'],
                'south' => (float)$bounds['latBottom'],
                'west' => (float)$bounds['lonLeft'],
                'east' => (float)$bounds['lonRight'],
            ],
            'center' => ['lat' => $centerLat, 'lon' => $centerLon],
            'utm' => $utm,
            'magnetic' => [
                'declinationDeg' => $decl,
                'model' => 'WMM2025',
                'decimalYear' => $decimalYear,
            ],
        ];
        file_put_contents($dir . '/meta.json', json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e){
        // Non-fatal: map image is done; metadata is optional.
        $meta = ['error' => $e->getMessage()];
    }

    write_status($jobId, [
        'state' => 'done',
        'done' => (int)($result['tilesTotal'] ?? 0),
        'total' => (int)($result['tilesTotal'] ?? 0),
        'percent' => 100,
        'message' => 'Terminé',
        'meta' => $meta,
    ]);
    exit(0);
} catch (Throwable $e){
    write_status($jobId, [
        'state' => 'error',
        'error' => $e->getMessage(),
    ]);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
