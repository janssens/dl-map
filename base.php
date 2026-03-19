<?php

const SUCCESS = 0;
const OVERFLOW = 1;

$generatedParts = array();

//https://gis.stackexchange.com/questions/133205/wmts-convert-geolocation-lat-long-to-tile-index-at-a-given-zoom-level
function tileCoordFromLatLon($lat,$lon,$tmc = false){
    global $zoom;
    $xtile = floor((($lon + 180) / 360) * pow(2, $zoom));
    $ytile = floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) /2 * pow(2, $zoom));
    if ($tmc) //https://gist.github.com/tmcw/4954720
        $ytile = pow(2, $zoom) - $ytile - 1;
    return array($xtile,$ytile);
}

//ini_set('display_errors', false);
//error_reporting(-1);

/* https://wiki.openstreetmap.org/wiki/Zoom_levels + https://ozh.github.io/ascii-tables/
+--------+---------+------------------+------------+----------------+------------------+
| Level  | Degree  |      Area        | m / pixel  |    ~Scale      |     # Tiles      |
+--------+---------+------------------+------------+----------------+------------------+
|     0  |    360  | whole world      |   156,412  | 1:500 million  |                1 |
|     1  |    180  |                  |    78,206  | 1:250 million  |                4 |
|     2  |     90  |                  |    39,103  | 1:150 million  |               16 |
|     3  |     45  |                  |    19,551  | 1:70 million   |               64 |
|     4  |   22.5  |                  |     9,776  | 1:35 million   |              256 |
|     5  |  11.25  |                  |     4,888  | 1:15 million   |            1,024 |
|     6  |  5.625  |                  |     2,444  | 1:10 million   |            4,096 |
|     7  |  2.813  |                  |     1,222  | 1:4 million    |           16,384 |
|     8  |  1.406  |                  |   610.984  | 1:2 million    |           65,536 |
|     9  |  0.703  | wide area        |   305.492  | 1:1 million    |          262,144 |
|    10  |  0.352  |                  |   152.746  | 1:500,000      |        1,048,576 |
|    11  |  0.176  | area             |    76.373  | 1:250,000      |        4,194,304 |
|    12  |  0.088  |                  |    38.187  | 1:150,000      |       16,777,216 |
|    13  |  0.044  | village or town  |    19.093  | 1:70,000       |       67,108,864 |
|    14  |  0.022  |                  |     9.547  | 1:35,000       |      268,435,456 |
|    15  |  0.011  |                  |     4.773  | 1:15,000       |    1,073,741,824 |
|    16  |  0.005  | small road       |     2.387  | 1:8,000        |    4,294,967,296 |
|    17  |  0.003  |                  |     1.193  | 1:4,000        |   17,179,869,184 |
|    18  |  0.001  |                  |     0.596  | 1:2,000        |   68,719,476,736 |
|    19  | 0.0005  |                  |     0.298  | 1:1,000        | 274,877,906,944  |
+--------+---------+------------------+------------+----------------+------------------+
*/

$toRead = [
    "latTopLeft" => null,
    "lngTopLeft"=> null,
    "latBottomRight"=> null,
    "lngBottomRight"=> null,
    "settings"=> null
];

// Read the contents of the JSON file
$jsonData = file_get_contents('config.json');
// Decode the JSON data into a PHP associative array
$data = json_decode($jsonData, true);
foreach ($toRead as $key => $value){
    // Check if key exists in the JSON data
    if (isset($data[$key]) && $data[$key]) {
        // Access the value
        $toRead[$key] = $data[$key];
    } else {
        echo "The '$key' key was not found in the JSON data.\n";
        exit();
    }
}

if (!($settings = file_get_contents('settings/'.$toRead['settings'].'.json'))){
    echo "The setting file for ".$toRead['settings']." was not found.\n";
    exit();
}
// Decode the JSON data into a PHP associative array
$settings_data = json_decode($settings, true);

if (!isset($settings_data['file_ext']) || !($ext = $settings_data['file_ext'])){
    echo "The file_ext value is missing from settings file for ".$toRead['settings']."\n";
    exit();
}
$format = "image/".$ext;
if (!isset($settings_data['url']) || !($url = $settings_data['url'])){
    echo "The url value is missing from settings file for ".$toRead['settings']."\n";
    exit();
}
if (!isset($settings_data['layer']) || !($layer = $settings_data['layer'])){
    echo "The layer value is missing from settings file for ".$toRead['settings']."\n";
    exit();
}
if (!isset($settings_data['tile_size']) || !($tile_size = $settings_data['tile_size'])){
    echo "The tile_size value is missing from settings file for ".$toRead['settings']."\n";
    exit();
}
if (!isset($settings_data['zoom']) || !($zoom = $settings_data['zoom'])){
    echo "The zoom value is missing from settings file for ".$toRead['settings']."\n";
    exit();
}
if (!isset($settings_data['style']) || !($style = $settings_data['style'])){
    echo "The style value is missing from settings file for ".$toRead['settings']."\n";
    echo "style = normal \n";
    $style = "normal";
}


$cookies = array();
if (isset($settings_data['cookies'])){
    foreach ($settings_data['cookies'] as $key => $value)
    {
        $cookies[] = $key . '=' . $value;
    }
    $cookies = implode('; ',$cookies);
}

set_error_handler(function($code, $string, $file, $line){
    throw new ErrorException($string, 0, $code, $file, $line);
});

register_shutdown_function(function(){
    $error = error_get_last();
    if(null !== $error)
    {
        echo 'Coordonnées trop éloignées'."\n";
        echo 'Try half'."\n";
    }
});

function check200($url,$cookies = '')
{
    $ch = curl_init($url);
    if ($cookies)
        curl_setopt( $ch, CURLOPT_COOKIE, $cookies );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_NOBODY, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpcode;
}
function saveImg($imagename,$url,$cookies = ''){
		$ch = curl_init($url);
		$fp = fopen($imagename, 'wb');
    //curl_setopt($ch, CURLOPT_VERBOSE, 1);
    if ($cookies)
        curl_setopt( $ch, CURLOPT_COOKIE, $cookies );
	curl_setopt($ch, CURLOPT_FILE, $fp);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12');
    $response = curl_exec($ch);
    if (curl_errno($ch) == 3){
        die('CURLE_URL_MALFORMAT');
    }
    if ($response === false || curl_errno($ch)){
        $error_msg = curl_error($ch);
        echo "\n====\n";
        echo $url."\n";
        echo $error_msg;
        echo('curl error');
        echo "\n====\n";
        copy($url, $imagename);
        die();
    }
	    curl_close($ch);
	    fclose($fp);
	}

function loadTileImageWithRetries($tilePath, $tileUrl, $format, $cookies = '', $maxAttempts = 3){
    $lastError = null;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++){
        $fromCache = file_exists($tilePath);
        if (!$fromCache){
            $code = check200($tileUrl, $cookies);
            if ($code != "200"){
                throw new RuntimeException("HTTP $code for $tileUrl");
            }
            saveImg($tilePath, $tileUrl, $cookies);
        }

        try {
            if ($format === "image/png"){
                return imagecreatefrompng($tilePath);
            }
            if ($format === "image/jpeg"){
                return imagecreatefromjpeg($tilePath);
            }
            throw new RuntimeException("Unsupported format: $format");
        } catch (Throwable $e){
            $lastError = $e;
            echo "error = ".$e->getMessage()."\n";
            echo $tilePath."\n";

            if ($attempt >= $maxAttempts){
                break;
            }

            echo "unlink & download again ... (try ".($attempt + 1)."/$maxAttempts)\n";
            if (file_exists($tilePath)){
                @unlink($tilePath);
            }
        }
    }

    throw $lastError ?: new RuntimeException("Failed to load tile after $maxAttempts attempts: $tilePath");
}

function generateImg(&$im,$colmin,$colmax,$rowmin,$rowmax){
		global $url,$style,$zoom,$layer,$format,$ext,$tile_size,$cookies;
		$tile_url = $url;
		$tile_url = str_replace('{style}', $style, $tile_url);
	$tile_url = str_replace('{zoom}', $zoom, $tile_url);
	$tile_url = str_replace('{layer}', $layer, $tile_url);
	$tile_url = str_replace('{format}', $format, $tile_url);

	$nboftiles = ($colmax-$colmin+1) * ($rowmax-$rowmin+1);
	echo $nboftiles;echo "\n";
	$i = 1;

	if (!is_dir('tile'))
		mkdir('tile');
    if (!is_dir('tile'.'/'.$layer))
        mkdir('tile'.'/'.$layer);

	for ($col = $colmin; $col <= $colmax; $col++){
		for ($row = $rowmin; $row <= $rowmax; $row++){
			$current_tile_url = $tile_url;
			$current_tile_url = str_replace('{row}', $row, $current_tile_url);
			$current_tile_url = str_replace('{col}', $col, $current_tile_url);
			//echo $tile_url."\n";
            $dir = 'tile'.'/'.$layer.'/'.$zoom.'/'.$col.'/';
            if (!is_dir($dir))
                mkdir($dir,0777,true);
				$tile_name = $dir.$row.".".$ext;
				echo $i++.'/'.$nboftiles;
				if (!file_exists($tile_name)){
	                $code = check200($current_tile_url,$cookies);
	                if ($code!="200"){
	                    echo "\n";
	                    echo("error, code $code for $current_tile_url");
	                    continue;
	                }
					saveImg($tile_name,$current_tile_url,$cookies);
				}else{
					echo " -- from cache";
				}
				$src = null;
				if ($format == "image/png" || $format == "image/jpeg"){
                    try {
                        $src = loadTileImageWithRetries($tile_name, $current_tile_url, $format, $cookies, 3);
                    } catch (Throwable $e){
                        die("CRITICAL: cannot load tile after 3 attempts: ".$e->getMessage()."\n");
                    }
                }
				
				if ($src){
					imagecopy($im, $src, ($col-$colmin)*$tile_size[0] , ($row-$rowmin)*$tile_size[1] , 0 , 0 , $tile_size[0],$tile_size[1] );
					imagedestroy($src);
				}else{
				echo "error = ".$current_tile_url;echo "\n";
			}
			
			echo "\n";
		}
	}
	imagealphablending($im , false);
	imagepng($im,$layer.$colmin.'-'.$colmax.'_'.$rowmin.'-'.$rowmax.'.png');
	//imagejpeg($im,'result.jpeg');
	imagedestroy($im);
}

function getNeededMemoryForImageCreate($width, $height) {
	    // Heuristic for GD truecolor images + extra overhead (PNG encoding can spike).
	    // Keep this slightly conservative to avoid fatal "Allowed memory size exhausted".
	    return $width*$height*(6.5);
	}

function memoryLimitToBytes($value) {
    $value = trim($value);
    if ($value === '' || $value === '-1'){
        return -1;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float)$value;

    switch ($unit) {
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

function getSafeAvailableMemoryBytes() {
    $limit = memoryLimitToBytes((string)ini_get('memory_limit'));
    if ($limit < 0){
        return PHP_INT_MAX;
    }

    $currentUsage = memory_get_usage(true);
    // Reserve a small buffer to avoid hitting memory limit due to overhead/spikes.
    $reserve = 64 * 1024 * 1024;
    return max(0, $limit - $currentUsage - $reserve);
}

function registerGeneratedPart($path, $colmin, $colmax, $rowmin, $rowmax){
    global $generatedParts;
    $generatedParts[] = array(
        'path' => $path,
        'colmin' => $colmin,
        'colmax' => $colmax,
        'rowmin' => $rowmin,
        'rowmax' => $rowmax
    );
}

function toLowerCamelCase($value) {
    $value = preg_replace('/[^a-zA-Z0-9]+/', ' ', (string)$value);
    $parts = preg_split('/\\s+/', trim($value));
    if (!$parts || count($parts) === 0){
        return '';
    }

    $first = strtolower(array_shift($parts));
    $rest = array_map(function($part){
        $part = strtolower($part);
        return $part === '' ? '' : ucfirst($part);
    }, $parts);

    return $first . implode('', $rest);
}

function metersPerPixelForZoom($zoom){
    // Values from the table in the comment at the beginning of this file.
    $mppByZoom = array(
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
    );

    $zoom = (int)$zoom;
    return $mppByZoom[$zoom] ?? null;
}

function assembleGeneratedPartsIfPossible($fullColRange, $fullRowRange){
    global $generatedParts, $tile_size, $layer, $zoom;

    $fullColMin = $fullColRange[0];
    $fullColMax = $fullColRange[1];
    $fullRowMin = $fullRowRange[0];
    $fullRowMax = $fullRowRange[1];

    $timestamp = date('Ymd_His');
    $layerCamel = toLowerCamelCase($layer);
    $mpp = metersPerPixelForZoom($zoom);
    $mppLabel = $mpp ? (str_replace('.', 'p', $mpp) . 'mpp') : 'mppUnknown';
    $finalPath = $timestamp . '_' . $layerCamel . '_' . $mppLabel . '_' . $fullColMin . '-' . $fullColMax . '_' . $fullRowMin . '-' . $fullRowMax . '.png';

    if (count($generatedParts) <= 1){
        if (count($generatedParts) === 1 && isset($generatedParts[0]['path']) && $generatedParts[0]['path'] !== $finalPath){
            $oldPath = $generatedParts[0]['path'];
            if (file_exists($oldPath)){
                rename($oldPath, $finalPath);
                echo "Fichier final généré: ".$finalPath."\n";
            }
        }
        return SUCCESS;
    }

    $width = $tile_size[0] * ($fullColMax - $fullColMin + 1);
    $height = $tile_size[1] * ($fullRowMax - $fullRowMin + 1);
    $sizeInMemory = getNeededMemoryForImageCreate($width, $height);
    $availableMemory = getSafeAvailableMemoryBytes();

    if ($sizeInMemory >= $availableMemory){
        echo "Assemblage final impossible: mémoire insuffisante pour une image unique.\n";
        echo "$sizeInMemory >= $availableMemory\n";
        return OVERFLOW;
    }

    echo "Assemblage final des morceaux en un seul fichier...\n";
    $im = imagecreatetruecolor($width,$height) or die("Cannot Initialize new GD image stream");

    foreach ($generatedParts as $part){
        try {
            $src = imagecreatefrompng($part['path']);
        } catch (Exception $e){
            echo "Impossible de charger le morceau: ".$part['path']."\n";
            continue;
        }

        $dstX = ($part['colmin'] - $fullColMin) * $tile_size[0];
        $dstY = ($part['rowmin'] - $fullRowMin) * $tile_size[1];
        $partWidth = ($part['colmax'] - $part['colmin'] + 1) * $tile_size[0];
        $partHeight = ($part['rowmax'] - $part['rowmin'] + 1) * $tile_size[1];

        imagecopy($im, $src, $dstX, $dstY, 0, 0, $partWidth, $partHeight);
        imagedestroy($src);
    }

    imagealphablending($im , false);
    imagepng($im, $finalPath);
    imagedestroy($im);
    echo "Fichier final généré: ".$finalPath."\n";
    return SUCCESS;
}

function run($colRange,$rowRange,$i=0){
	global $tile_size, $layer;
	echo 'run !'." (level $i)\n";
	$colmin = $colRange[0];
	$colmax = $colRange[1];
	if ($colmin <= $colmax){
		$rowmin = $rowRange[0];
		$rowmax = $rowRange[1];
			if ($rowmin <= $rowmax){
	            $width = $tile_size[0]*($colmax-$colmin+1);
	            $height = $tile_size[1]*($rowmax-$rowmin+1);
	            // Estimer la taille mémoire en octets.
	            $sizeInMemory = getNeededMemoryForImageCreate($width,$height);
	            $availableMemory = getSafeAvailableMemoryBytes();
	            // Comparer la taille estimée de l'image avec la mémoire disponible
	            if ($sizeInMemory >= $availableMemory) {
	                echo "Les dimensions de l'image risquent de dépasser la mémoire disponible.\n";
	                echo "$sizeInMemory >= $availableMemory\n";
                    if ($colmin == $colmax && $rowmin == $rowmax){
                        echo "Impossible de découper davantage (1 seule tuile).\n";
                        return OVERFLOW;
                    }
	                if ($width>$height){
	                    echo "coupe en 2 horizontal.\n";
	                    $half = intval(($colRange[1]-$colRange[0])/2)+$colRange[0];
	                    run([$colRange[0],$half],$rowRange,$i+1);
	                    run([$half+1,$colRange[1]],$rowRange,$i+1);
	                }else{
	                    echo "coupe en 2 vertical.\n";
	                    $half = intval(($rowRange[1]-$rowRange[0])/2)+$rowRange[0];
	                    run($colRange,[$rowRange[0],$half],$i+1);
	                    run($colRange,[$half+1,$rowRange[1]],$i+1);
	                }
	                return SUCCESS;
	            }else{
	                echo "mémoire estimée : " . $sizeInMemory . " octets \n";
	            }
				try {
					$im = imagecreatetruecolor($width,$height) or die("Cannot Initialize new GD image stream");
					generateImg($im,$colmin,$colmax,$rowmin,$rowmax);
                    registerGeneratedPart($layer.$colmin.'-'.$colmax.'_'.$rowmin.'-'.$rowmax.'.png', $colmin, $colmax, $rowmin, $rowmax);
	                $peakMemoryUsed = memory_get_peak_usage(); // Pic de mémoire utilisée
	                echo "Pic de mémoire utilisée : " . $peakMemoryUsed . " octets\n";
	                return SUCCESS;
				} catch (\Exception $exception){
				die ($exception);
			}

		}else{
			echo "wrong";
		}
	}else{
		echo "wrong";
	}
}

$availableMemory = memory_get_usage();
echo "mémoire disponible : " . $availableMemory . " octets \n";

$tileTopLeft = tileCoordFromLatLon($toRead['latTopLeft'],$toRead['lngTopLeft'],isset($settings_data['tmc']) ? $settings_data['tmc'] : false );
$tileBottomRight = tileCoordFromLatLon($toRead['latBottomRight'],$toRead['lngBottomRight'],isset($settings_data['tmc']) ? $settings_data['tmc'] : false);

$colRange = [min($tileTopLeft[0],$tileBottomRight[0]),max($tileTopLeft[0],$tileBottomRight[0])];
$rowRange = [min($tileTopLeft[1],$tileBottomRight[1]),max($tileTopLeft[1],$tileBottomRight[1])];

//print_r($colRange);
//print_r($rowRange);

run($colRange,$rowRange);
assembleGeneratedPartsIfPossible($colRange, $rowRange);
echo "mémoire disponible : " . $availableMemory . " octets \n";
