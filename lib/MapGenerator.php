<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/tiles.php';

final class MapGenerator {
    public const SUCCESS = 0;
    public const OVERFLOW = 1;

    private array $settings;
    private string $cookiesHeader = '';

    private int $zoom;
    private array $tileSize;
    private string $layer;
    private string $ext;
    private string $format;
    private string $style;

    private string $outputDir;
    private int $tilesTotal = 0;
    private int $tilesDone = 0;
    /** @var callable|null */
    private $progressCallback;

    /** @var array<int, array{path:string,colmin:int,colmax:int,rowmin:int,rowmax:int}> */
    private array $generatedParts = [];

    /**
     * @param array{latTopLeft:float,lngTopLeft:float,latBottomRight:float,lngBottomRight:float,settings:string} $config
     * @return array{status:int,finalPath:string|null,parts:array,tilesTotal:int,colRange:array{0:int,1:int},rowRange:array{0:int,1:int},zoom:int,tms:bool}
     */
    public function generate(array $config, string $outputDir = '.', ?callable $progressCallback = null): array {
        $this->outputDir = rtrim($outputDir, '/');
        if ($this->outputDir === ''){
            $this->outputDir = '.';
        }
        if (!is_dir($this->outputDir)){
            mkdir($this->outputDir, 0777, true);
        }

        $this->progressCallback = $progressCallback;
        $this->generatedParts = [];

        $settingsId = (string)($config['settings'] ?? '');
        $this->settings = load_settings($settingsId);

        $this->ext = (string)($this->settings['file_ext'] ?? '');
        if ($this->ext === ''){
            throw new RuntimeException("The file_ext value is missing from settings file for $settingsId");
        }
        $this->format = 'image/' . $this->ext;

        $this->layer = (string)($this->settings['layer'] ?? '');
        if ($this->layer === ''){
            throw new RuntimeException("The layer value is missing from settings file for $settingsId");
        }

        $this->tileSize = $this->settings['tile_size'] ?? null;
        if (!is_array($this->tileSize) || count($this->tileSize) !== 2){
            throw new RuntimeException("The tile_size value is missing from settings file for $settingsId");
        }

        $this->zoom = (int)($this->settings['zoom'] ?? 0);
        if ($this->zoom <= 0){
            throw new RuntimeException("The zoom value is missing from settings file for $settingsId");
        }

        $this->style = (string)($this->settings['style'] ?? 'normal');
        $this->cookiesHeader = cookies_header_from_settings($this->settings);

        $tms = (bool)($this->settings['tmc'] ?? false);
        $tileTopLeft = self::tileCoordFromLatLon((float)$config['latTopLeft'], (float)$config['lngTopLeft'], $this->zoom, $tms);
        $tileBottomRight = self::tileCoordFromLatLon((float)$config['latBottomRight'], (float)$config['lngBottomRight'], $this->zoom, $tms);

        $colRange = [min($tileTopLeft[0], $tileBottomRight[0]), max($tileTopLeft[0], $tileBottomRight[0])];
        $rowRange = [min($tileTopLeft[1], $tileBottomRight[1]), max($tileTopLeft[1], $tileBottomRight[1])];

        $this->tilesTotal = ($colRange[1] - $colRange[0] + 1) * ($rowRange[1] - $rowRange[0] + 1);
        $this->tilesDone = 0;
        $this->emitProgress("Démarrage…");

        $this->run($colRange, $rowRange, 0);
        $status = $this->assembleGeneratedPartsIfPossible($colRange, $rowRange);

        $finalPath = $this->findFinalPathFromParts($colRange, $rowRange);

        return [
            'status' => $status,
            'finalPath' => $finalPath,
            'parts' => $this->generatedParts,
            'tilesTotal' => $this->tilesTotal,
            'colRange' => [(int)$colRange[0], (int)$colRange[1]],
            'rowRange' => [(int)$rowRange[0], (int)$rowRange[1]],
            'zoom' => $this->zoom,
            'tms' => $tms,
        ];
    }

    private function emitProgress(string $message, ?int $done = null, ?int $total = null): void {
        if (!$this->progressCallback){
            return;
        }
        $done = $done ?? $this->tilesDone;
        $total = $total ?? $this->tilesTotal;
        ($this->progressCallback)([
            'message' => $message,
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int)floor(($done / $total) * 100) : 0,
        ]);
    }

    private static function tileCoordFromLatLon(float $lat, float $lon, int $zoom, bool $tms = false): array {
        $xtile = (int)floor((($lon + 180) / 360) * pow(2, $zoom));
        $ytile = (int)floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) / 2 * pow(2, $zoom));
        if ($tms){
            $ytile = (int)(pow(2, $zoom) - $ytile - 1);
        }
        return [$xtile, $ytile];
    }

    private function generateImg($im, int $colmin, int $colmax, int $rowmin, int $rowmax): void {
        $nboftiles = ($colmax - $colmin + 1) * ($rowmax - $rowmin + 1);
        $urlTemplate = (string)($this->settings['url'] ?? '');
        if ($urlTemplate === ''){
            throw new RuntimeException('Missing url in settings');
        }

        if (!is_dir(tile_cache_dir_path())){
            mkdir(tile_cache_dir_path(), 0777, true);
        }

        for ($col = $colmin; $col <= $colmax; $col++){
            for ($row = $rowmin; $row <= $rowmax; $row++){
                $tilePath = tile_cache_path($this->settings, $this->zoom, $col, $row);
                $fromCache = file_exists($tilePath);
                $tileUrl = build_remote_tile_url($this->settings, $this->zoom, $col, $row);

                if (!$fromCache){
                    $code = check200($tileUrl, $this->cookiesHeader);
                    if (!is_success_tile_http_code($code)){
                        $this->tilesDone++;
                        $this->emitProgress("HTTP $code (skip)", $this->tilesDone, $this->tilesTotal);
                        continue;
                    }
                    save_img($tilePath, $tileUrl, $this->cookiesHeader);
                }

                $src = null;
                try {
                    if ($this->format === 'image/png'){
                        $src = imagecreatefrompng($tilePath);
                    } elseif ($this->format === 'image/jpeg'){
                        $src = imagecreatefromjpeg($tilePath);
                    } else {
                        throw new RuntimeException("Unsupported format: {$this->format}");
                    }
                } catch (Throwable $e){
                    if (file_exists($tilePath)){
                        @unlink($tilePath);
                    }
                    // Retry one time by downloading again.
                    save_img($tilePath, $tileUrl, $this->cookiesHeader);
                    if ($this->format === 'image/png'){
                        $src = imagecreatefrompng($tilePath);
                    } else {
                        $src = imagecreatefromjpeg($tilePath);
                    }
                }

                if ($src){
                    imagecopy(
                        $im,
                        $src,
                        ($col - $colmin) * $this->tileSize[0],
                        ($row - $rowmin) * $this->tileSize[1],
                        0,
                        0,
                        $this->tileSize[0],
                        $this->tileSize[1]
                    );
                    imagedestroy($src);
                }

                $this->tilesDone++;
                $msg = ($fromCache ? 'Tuile (cache)' : 'Tuile');
                $this->emitProgress($msg, $this->tilesDone, $this->tilesTotal);
            }
        }
    }

    private static function getNeededMemoryForImageCreate(int $width, int $height): float {
        return $width * $height * 6.5;
    }

    private static function memoryLimitToBytes(string $value): int {
        $value = trim($value);
        if ($value === '' || $value === '-1'){
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        switch ($unit){
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
        }
        return (int)$number;
    }

    private static function getSafeAvailableMemoryBytes(): int {
        $limit = self::memoryLimitToBytes((string)ini_get('memory_limit'));
        if ($limit < 0){
            return PHP_INT_MAX;
        }
        $currentUsage = memory_get_usage(true);
        $reserve = 64 * 1024 * 1024;
        return max(0, $limit - $currentUsage - $reserve);
    }

    private function registerGeneratedPart(string $path, int $colmin, int $colmax, int $rowmin, int $rowmax): void {
        $this->generatedParts[] = [
            'path' => $path,
            'colmin' => $colmin,
            'colmax' => $colmax,
            'rowmin' => $rowmin,
            'rowmax' => $rowmax,
        ];
    }

    private static function toLowerCamelCase(string $value): string {
        $value = preg_replace('/[^a-zA-Z0-9]+/', ' ', $value) ?? '';
        $parts = preg_split('/\\s+/', trim($value)) ?: [];
        if (count($parts) === 0){
            return '';
        }
        $first = strtolower(array_shift($parts));
        $rest = array_map(function($part){
            $part = strtolower((string)$part);
            return $part === '' ? '' : ucfirst($part);
        }, $parts);
        return $first . implode('', $rest);
    }

    private static function metersPerPixelForZoom(int $zoom): ?string {
        $mppByZoom = [
            0 => '156412',
            1 => '78206',
            2 => '39103',
            3 => '19551',
            4 => '9776',
            5 => '4888',
            6 => '2444',
            7 => '1222',
            8 => '610.984',
            9 => '305.492',
            10 => '152.746',
            11 => '76.373',
            12 => '38.187',
            13 => '19.093',
            14 => '9.547',
            15 => '4.773',
            16 => '2.387',
            17 => '1.193',
            18 => '0.596',
            19 => '0.298',
        ];
        return $mppByZoom[$zoom] ?? null;
    }

    private function assembleGeneratedPartsIfPossible(array $fullColRange, array $fullRowRange): int {
        $fullColMin = (int)$fullColRange[0];
        $fullColMax = (int)$fullColRange[1];
        $fullRowMin = (int)$fullRowRange[0];
        $fullRowMax = (int)$fullRowRange[1];

        $timestamp = date('Ymd_His');
        $layerCamel = self::toLowerCamelCase($this->layer);
        $mpp = self::metersPerPixelForZoom($this->zoom);
        $mppLabel = $mpp ? (str_replace('.', 'p', $mpp) . 'mpp') : 'mppUnknown';
        $finalPath = $this->outputDir . '/' . $timestamp . '_' . $layerCamel . '_' . $mppLabel . '_' . $fullColMin . '-' . $fullColMax . '_' . $fullRowMin . '-' . $fullRowMax . '.png';

        if (count($this->generatedParts) <= 1){
            if (count($this->generatedParts) === 1 && $this->generatedParts[0]['path'] !== $finalPath){
                $oldPath = $this->generatedParts[0]['path'];
                if (is_file($oldPath)){
                    rename($oldPath, $finalPath);
                    $this->generatedParts[0]['path'] = $finalPath;
                }
            }
            return self::SUCCESS;
        }

        $width = (int)$this->tileSize[0] * ($fullColMax - $fullColMin + 1);
        $height = (int)$this->tileSize[1] * ($fullRowMax - $fullRowMin + 1);
        $sizeInMemory = self::getNeededMemoryForImageCreate($width, $height);
        $availableMemory = self::getSafeAvailableMemoryBytes();

        if ($sizeInMemory >= $availableMemory){
            $this->emitProgress("Assemblage final impossible (mémoire)", $this->tilesDone, $this->tilesTotal);
            return self::OVERFLOW;
        }

        $this->emitProgress("Assemblage final…", $this->tilesDone, $this->tilesTotal);
        $im = imagecreatetruecolor($width, $height);
        if ($im === false){
            throw new RuntimeException("Cannot Initialize new GD image stream");
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, $width, $height, $transparent);
        imagealphablending($im, true);

        foreach ($this->generatedParts as $part){
            $src = @imagecreatefrompng($part['path']);
            if ($src === false){
                continue;
            }
            $dstX = ($part['colmin'] - $fullColMin) * (int)$this->tileSize[0];
            $dstY = ($part['rowmin'] - $fullRowMin) * (int)$this->tileSize[1];
            $partWidth = ($part['colmax'] - $part['colmin'] + 1) * (int)$this->tileSize[0];
            $partHeight = ($part['rowmax'] - $part['rowmin'] + 1) * (int)$this->tileSize[1];

            imagecopy($im, $src, $dstX, $dstY, 0, 0, $partWidth, $partHeight);
            imagedestroy($src);
        }

        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagepng($im, $finalPath);
        imagedestroy($im);

        // Keep a single "final" reference by registering it as the first part.
        $this->generatedParts = [[
            'path' => $finalPath,
            'colmin' => $fullColMin,
            'colmax' => $fullColMax,
            'rowmin' => $fullRowMin,
            'rowmax' => $fullRowMax,
        ]];

        return self::SUCCESS;
    }

    private function run(array $colRange, array $rowRange, int $level): int {
        $colmin = (int)$colRange[0];
        $colmax = (int)$colRange[1];
        $rowmin = (int)$rowRange[0];
        $rowmax = (int)$rowRange[1];

        $width = (int)$this->tileSize[0] * ($colmax - $colmin + 1);
        $height = (int)$this->tileSize[1] * ($rowmax - $rowmin + 1);

        $sizeInMemory = self::getNeededMemoryForImageCreate($width, $height);
        $availableMemory = self::getSafeAvailableMemoryBytes();
        if ($sizeInMemory >= $availableMemory){
            if ($colmin === $colmax && $rowmin === $rowmax){
                return self::OVERFLOW;
            }

            if ($width > $height){
                $half = (int)(($colmax - $colmin) / 2) + $colmin;
                $this->run([$colmin, $half], [$rowmin, $rowmax], $level + 1);
                $this->run([$half + 1, $colmax], [$rowmin, $rowmax], $level + 1);
            } else {
                $half = (int)(($rowmax - $rowmin) / 2) + $rowmin;
                $this->run([$colmin, $colmax], [$rowmin, $half], $level + 1);
                $this->run([$colmin, $colmax], [$half + 1, $rowmax], $level + 1);
            }
            return self::SUCCESS;
        }

        $im = imagecreatetruecolor($width, $height);
        if ($im === false){
            throw new RuntimeException("Cannot Initialize new GD image stream");
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, $width, $height, $transparent);
        imagealphablending($im, true);

        $this->generateImg($im, $colmin, $colmax, $rowmin, $rowmax);

        $partPath = $this->outputDir . '/' . $this->layer . $colmin . '-' . $colmax . '_' . $rowmin . '-' . $rowmax . '.png';
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagepng($im, $partPath);
        imagedestroy($im);

        $this->registerGeneratedPart($partPath, $colmin, $colmax, $rowmin, $rowmax);
        return self::SUCCESS;
    }

    private function findFinalPathFromParts(array $fullColRange, array $fullRowRange): ?string {
        if (count($this->generatedParts) === 0){
            return null;
        }
        if (count($this->generatedParts) === 1){
            return $this->generatedParts[0]['path'];
        }
        // Should not happen after assembleGeneratedPartsIfPossible; fallback.
        return $this->generatedParts[0]['path'];
    }
}
