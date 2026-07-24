<?php

declare(strict_types=1);

/**
 * Conversion entre WGS84 (lat/lng) et Lambert-93 (EPSG:2154 / RGF93).
 *
 * Paramètres officiels de la projection conique conforme de Lambert zone 93 :
 *   - Ellipsoïde GRS80  : a = 6378137, 1/f = 298.257222101
 *   - Méridien central   : 3°E
 *   - 1er parallèle      : 44°N
 *   - 2e parallèle       : 49°N
 *   - Latitude d'origine  : 46.5°N
 *   - Faux Est           : 700000 m
 *   - Faux Nord          : 6600000 m
 */

define('LAMBERT93_A', 6378137.0);
define('LAMBERT93_F', 1.0 / 298.257222101);
define('LAMBERT93_E', sqrt(2 * LAMBERT93_F - LAMBERT93_F * LAMBERT93_F));
define('LAMBERT93_LAT0', deg2rad(46.5));
define('LAMBERT93_LON0', deg2rad(3.0));
define('LAMBERT93_LAT1', deg2rad(44.0));
define('LAMBERT93_LAT2', deg2rad(49.0));
define('LAMBERT93_X0', 700000.0);
define('LAMBERT93_Y0', 6600000.0);

/**
 * Convertit des coordonnées WGS84 (latitude, longitude) en Lambert-93 (X, Y en mètres).
 *
 * @return array{X:float, Y:float}
 */
function wgs84_to_lambert93(float $lat, float $lon): array {
    $phi = deg2rad($lat);
    $lambda = deg2rad($lon);

    $a = LAMBERT93_A;
    $e = LAMBERT93_E;

    $n = (log(sin(LAMBERT93_LAT1) / sin(LAMBERT93_LAT2))
        + log((1 - $e * sin(LAMBERT93_LAT2)) / (1 - $e * sin(LAMBERT93_LAT1))))
        / (2 * log(tan(pi() / 4 + LAMBERT93_LAT2 / 2) / tan(pi() / 4 + LAMBERT93_LAT1 / 2)));

    $c = ($a / LAMBERT93_LAT1)
        * pow(tan(pi() / 4 + LAMBERT93_LAT1 / 2), $n)
        * pow((1 - $e * sin(LAMBERT93_LAT1)) / (1 + $e * sin(LAMBERT93_LAT1)), $n * $e / 2);

    // Réduction isométrique au point de départ
    $t = tan(pi() / 4 + $phi / 2)
        / pow((1 - $e * sin($phi)) / (1 + $e * sin($phi)), $e / 2);
    $gamma = $c * pow($t, -$n);

    $x = LAMBERT93_X0 + $gamma * sin($n * ($lambda - LAMBERT93_LON0));
    $y = LAMBERT93_Y0 - $gamma * cos($n * ($lambda - LAMBERT93_LON0));

    return ['X' => $x, 'Y' => $y];
}

/**
 * Convertit des coordonnées Lambert-93 (X, Y en mètres) en WGS84 (latitude, longitude en degrés).
 *
 * @return array{lat:float, lon:float}
 */
function lambert93_to_wgs84(float $x, float $y): array {
    $a = LAMBERT93_A;
    $e = LAMBERT93_E;
    $lon0 = LAMBERT93_LON0;
    $lat0 = LAMBERT93_LAT0;
    $lat1 = LAMBERT93_LAT1;
    $lat2 = LAMBERT93_LAT2;
    $x0 = LAMBERT93_X0;
    $y0 = LAMBERT93_Y0;

    $n = (log(sin($lat1) / sin($lat2))
        + log((1 - $e * sin($lat2)) / (1 - $e * sin($lat1))))
        / (2 * log(tan(pi() / 4 + $lat2 / 2) / tan(pi() / 4 + $lat1 / 2)));

    $c = ($a / $lat1)
        * pow(tan(pi() / 4 + $lat1 / 2), $n)
        * pow((1 - $e * sin($lat1)) / (1 + $e * sin($lat1)), $n * $e / 2);

    $gamma = sqrt(pow($x - $x0, 2) + pow($y - $y0, 2));
    $lambda = $lon0 + atan2($x - $x0, $y0 - $y) / $n;

    $t = pow($c / $gamma, 1 / $n);

    // Itération pour trouver phi (latitude)
    $phi = pi() / 2 - 2 * atan($t);
    for ($i = 0; $i < 10; $i++){
        $eSin = $e * sin($phi);
        $phi2 = pi() / 2 - 2 * atan($t * pow((1 - $eSin) / (1 + $eSin), $e / 2));
        if (abs($phi2 - $phi) < 1e-12){
            $phi = $phi2;
            break;
        }
        $phi = $phi2;
    }

    return ['lat' => rad2deg($phi), 'lon' => rad2deg($lambda)];
}

/**
 * Détermine le nom du fichier PMTiles (format "XX_YY") à partir de coordonnées Lambert-93.
 * Les fichiers sont organisés en dalles de 10 km (centaines de km entières).
 * 
 * Exemple : X=984846, Y=6534866 → X/10000=98, Y/10000=653, modulo 100 → 53 → fichier "98_53"
 *
 * @return string ex: "98_53"
 */
function lambert93_to_pmtiles_zone(float $xLambert, float $yLambert): string {
    $zoneX = (int)floor($xLambert / 10000.0);
    $zoneY = (int)floor($yLambert / 10000.0) % 100;
    return sprintf('%02d_%02d', $zoneX, $zoneY);
}

/**
 * Détermine le nom du fichier PMTiles à partir de coordonnées WGS84.
 *
 * @return string ex: "98_53"
 */
function wgs84_to_pmtiles_zone(float $lat, float $lon): string {
    $lambert = wgs84_to_lambert93($lat, $lon);
    return lambert93_to_pmtiles_zone($lambert['X'], $lambert['Y']);
}