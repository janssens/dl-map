<?php

function tileCoordFromLatLon($lat,$lon){
	global $zoom;
	$xtile = floor((($lon + 180) / 360) * pow(2, $zoom));
	$ytile = floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) /2 * pow(2, $zoom));
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

$tile_size = [256,256];
$tile_size = [512,512];
$zoom = 16;
$zoom = 15;

$latTopLeft = '45.39' ;
$lonTopLeft = '5.71' ;

$latBottomRight = '45.31' ;
$lonBottomRight = '5.83' ;

$tileTopLeft = tileCoordFromLatLon($latTopLeft,$lonTopLeft);
$tileBottomRight = tileCoordFromLatLon($latBottomRight,$lonBottomRight);

echo "top left :";
print_r($tileTopLeft);
echo "bottom right :";
print_r($tileBottomRight);

$colRange = [$tileTopLeft[0],$tileBottomRight[0]];
$rowRange = [$tileTopLeft[1],$tileBottomRight[1]];

$style = "normal";
//$style = "bdparcellaire";
$layer = "strava";
//$layer = "GEOGRAPHICALGRIDSYSTEMS.MAPS";
//$layer = "GEOGRAPHICALGRIDSYSTEMS.MAPS.SCAN-EXPRESS.STANDARD";
//$layer = "ORTHOIMAGERY.ORTHOPHOTOS";
//$layer = "CADASTRALPARCELS.PARCELS";
//$layer = "OPEN_STREET_MAP";
//$ext = "jpeg";
$ext = "png";
//$format = "image/png";
$format = "image/".$ext;

//$url = 'https://wxs.ign.fr/an7nvfzojv5wa96dsga5nk8w/geoportail/wmts?layer={layer}&style={style}&tilematrixset=PM&Service=WMTS&Request=GetTile&Version=1.0.0&Format={format}&TileMatrix={zoom}&TileCol={col}&TileRow={row}';
//$url = 'https://wxs.ign.fr/an7nvfzojv5wa96dsga5nk8w/proxy/'.       '?layer={layer}&style={style}&tilematrixset=PM&Service=WMTS&Request=GetTile&Version=1.0.0&Format={format}&TileMatrix={zoom}&TileCol={col}&TileRow={row}';

$cloudfront = [ 'CloudFront-Key-Pair-Id' => 'APKAIDPUN4QMG7VUQPSA',
    'CloudFront-Policy' => 'eyJTdGF0ZW1lbnQiOiBbeyJSZXNvdXJjZSI6Imh0dHBzOi8vaGVhdG1hcC1leHRlcm5hbC0qLnN0cmF2YS5jb20vKiIsIkNvbmRpdGlvbiI6eyJEYXRlTGVzc1RoYW4iOnsiQVdTOkVwb2NoVGltZSI6MTY4NzQzMTA2OX0sIkRhdGVHcmVhdGVyVGhhbiI6eyJBV1M6RXBvY2hUaW1lIjoxNjg2MjA3MDY5fX19XX0_',
    'CloudFront-Signature' => 'X61Oq7RNewXTKnchwDOf8fAnSbt-XR~B8ptrsETkK~1qfONEcDZdW4kflyS-nI43bA16Op4k8uyRz33~byT0sjY5x6aZ0K2vGWgUCcmhO7TZghr-7BI8BHwwc54TZjpBa2kiKdXs6Z9kMTFdTbs6rY0r8leMFHRrdayIxLyHlWcN39qzNh1ZSmpakTWBecCI73cRaVbGIbdqNeWcBv3WS-dJ-wsGIWR329hlfccw~28iWdHWaX6Q1vi4q2PMGDEDL~Zsqmu9-TPlAhGZm6N7OCb7DAADpPNsQ623az8G2Kj6tlcBRZsKdk3Aa4~PE0i93zn-qV6EwugQm76WSamrPg__'
];
$cookies = array();
foreach ($cloudfront as $key => $value)
{
    $cookies[] = $key . '=' . $value;
}
$cookies = implode('; ',$cookies);
$url = 'https://heatmap-external-a.strava.com/tiles-auth/run/blue/{zoom}/{col}/{row}.png?v=19';
//https://api.mapbox.com/v4/mapbox.mapbox-streets-v8/13/4226/2935.vector.pbf?sku=101VRqaoOf11D&access_token=pk.eyJ1Ijoic3RyYXZhIiwiYSI6IlpoeXU2U0UifQ.c7yhlZevNRFCqHYm6G6Cyg

set_error_handler(function($code, $string, $file, $line){
    throw new ErrorException($string, null, $code, $file, $line);
});

register_shutdown_function(function(){
    $error = error_get_last();
    if(null !== $error)
    {
        echo 'Coordonnées trop éloignées'."\n";
        echo 'Try half'."\n";
    }
});




function saveImg($imagename,$url,$cookies = ''){
//    echo $url;
//    echo "\n";
//    echo $cookies;
	$ch = curl_init($url);
	$fp = fopen($imagename, 'wb');
    //curl_setopt($ch, CURLOPT_VERBOSE, 1);
    curl_setopt( $ch, CURLOPT_COOKIE, $cookies );
	curl_setopt($ch, CURLOPT_FILE, $fp);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12');
	curl_exec($ch);
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
    }
    curl_close($ch);
    fclose($fp);

    if (isset($error_msg)) {
        echo $error_msg;
        die();
    }
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

	for ($col = $colmin; $col <= $colmax; $col++){
		for ($row = $rowmin; $row <= $rowmax; $row++){
			$current_tile_url = $tile_url;
			$current_tile_url = str_replace('{row}', $row, $current_tile_url);
			$current_tile_url = str_replace('{col}', $col, $current_tile_url);
			//echo $tile_url."\n";
			$tile_name = 'tile/t-'.$layer.'-'.$row.'-'.$col.'-'.$zoom.'.'.$ext;
			echo $i++.'/'.$nboftiles;
			if (!file_exists($tile_name)){
				saveImg($tile_name,$current_tile_url,$cookies);
			}else{
				echo " -- from cache";
			}
			if ($format == "image/png")
                try {
				    $src = imagecreatefrompng($tile_name);
                } catch (Exception $e){
                    echo "error = ".$e->getMessage();echo "\n";
                    echo "$tile_name";
                    if (strpos($e->getMessage(),'is not a valid PNG file')>0){
                        echo "\n";
                        echo "unlink & download again ...\n";
                        unlink($tile_name);
                        saveImg($tile_name,$current_tile_url,$cookies);
                        $src = imagecreatefrompng($tile_name);
                    }else{
                        echo "\n";
                        die();
                    }
                }
			else if ($format == "image/jpeg"){
                try {
                    $src = imagecreatefromjpeg($tile_name);
                } catch (Exception $e){
                    echo "error = ".$e->getMessage();echo "\n";
                    echo "$tile_name";
                    echo "\n";
                    die();
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

function run(){
	global $colRange,$rowRange,$tile_size;
	echo 'run !'."\n";
	$colmin = $colRange[0];
	$colmax = $colRange[1];
	if ($colmin <= $colmax){
		$rowmin = $rowRange[0];
		$rowmax = $rowRange[1];
		if ($rowmin <= $rowmax){

			try {
				$im = imagecreatetruecolor($tile_size[0]*($colmax-$colmin+1),$tile_size[1]*($rowmax-$rowmin+1)) or die("Cannot Initialize new GD image stream");
				generateImg($im,$colmin,$colmax,$rowmin,$rowmax);
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

if (isset($argv[1]) && $argv[1] == 'half'){
		echo 'first half !'."\n";
	    $oldColRange = $colRange;
        $colRange[1] = intval(($colRange[1]-$colRange[0])/2)+$colRange[0];
        run();
        echo 'second half !'."\n";
        $colRange[0] = $colRange[1]+1;
        $colRange[1] = $oldColRange[1];
        run();
}else{
	run();
}


