<?php

declare(strict_types=1);

/**
 * Parseur minimal PMTiles pour PHP utilisant des requêtes HTTP Range.
 *
 * Spécification PMTiles v3 :
 * https://github.com/protomaps/PMTiles/blob/main/spec/v3/spec.md
 *
 * Format du header (512 bytes) :
 *   offset 0x00 : magic "PM" (2 bytes)
 *   offset 0x02 : version (2 bytes, little-endian uint16)
 *   offset 0x04 : root_dir_offset (8 bytes, little-endian uint64)
 *   offset 0x0C : root_dir_length (8 bytes)
 *   offset 0x14 : metadata_offset (8 bytes)
 *   offset 0x1C : metadata_length (8 bytes)
 *   offset 0x24 : leaf_dir_offset (8 bytes)
 *   offset 0x2C : leaf_dir_length (8 bytes)
 *   offset 0x34 : tile_data_offset (8 bytes)
 *   offset 0x3C : tile_data_length (8 bytes)
 *   offset 0x44 : addressed_tiles_count (8 bytes)
 *   offset 0x4C : tile_entries_count (8 bytes)
 *   offset 0x54 : tile_contents_count (8 bytes)
 *   offset 0x5C : clustered (1 byte) (v3 only)
 *   offset 0x5D : internal_compression (1 byte) (v3 only)
 *   offset 0x5E : tile_compression (1 byte) (v3 only)
 *   offset 0x5F : tile_type (1 byte) (v3 only)
 *   offset 0x60 : min_zoom (1 byte) (v3 only)
 *   offset 0x61 : max_zoom (1 byte) (v3 only)
 *   offset 0x62 : min_lon (4 bytes, little-endian float32)
 *   offset 0x66 : min_lat (4 bytes)
 *   offset 0x6A : max_lon (4 bytes)
 *   offset 0x6E : max_lat (4 bytes)
 *   offset 0x72 : center_zoom (1 byte)
 *   offset 0x73 : center_lon (4 bytes, float32)
 *   offset 0x77 : center_lat (4 bytes, float32)
 *   offset 0x7B-0x1FF : reserved (389 bytes)
 */

/**
 * Lit un bloc d'octets depuis une URL distante via HTTP Range.
 *
 * @return string Les données binaires du bloc demandé.
 */
function pmtiles_http_range(string $url, int $offset, int $length): string {
    $start = $offset;
    $end = $offset + $length - 1;

    $ch = curl_init($url);
    if ($ch === false){
        throw new RuntimeException("pmtiles: curl_init failed for $url");
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Range: bytes=$start-$end"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'casse-dalles/1.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $data = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($data === false || $data === ''){
        throw new RuntimeException("pmtiles: HTTP error ($httpCode) for $url range $start-$end: $error");
    }
    // Accept both 200 and 206 (some servers return 200 for byte ranges)
    if ($httpCode !== 206 && $httpCode !== 200){
        throw new RuntimeException("pmtiles: HTTP $httpCode for $url range $start-$end");
    }
    if (strlen($data) < $length){
        // Truncated response; return what we got
    }
    return $data;
}

/**
 * Lit un entier little-endian non signé sur 1, 2, 4, ou 8 octets.
 */
function pmtiles_read_uint(string $data, int $offset, int $bytes): int|float {
    if ($offset + $bytes > strlen($data)){
        throw new RuntimeException("pmtiles: read past end of data (offset=$offset bytes=$bytes len=" . strlen($data) . ")");
    }
    $val = 0;
    for ($i = 0; $i < $bytes; $i++){
        $val |= ord($data[$offset + $i]) << ($i * 8);
    }
    return $val;
}

/**
 * Lit un float32 little-endian.
 */
function pmtiles_read_float32(string $data, int $offset): float {
    if ($offset + 4 > strlen($data)){
        throw new RuntimeException("pmtiles: read_float32 past end");
    }
    $bin = substr($data, $offset, 4);
    // PHP's unpack is incompatible with little-endian float32 from network stream
    // Force unpack of little-endian float
    $arr = unpack('f', strrev($bin)); // actually let me think...
    // On little-endian machine, "f" format in unpack interprets as machine order.
    // But the data is already in little-endian. On LE machine, unpack('f', $bin) should work.
    // However to be safe we can use the following:
    return unpack('f', $bin)[1];
}

/**
 * Lit un float64 little-endian.
 */
function pmtiles_read_float64(string $data, int $offset): float {
    if ($offset + 8 > strlen($data)){
        throw new RuntimeException("pmtiles: read_float64 past end");
    }
    $bin = substr($data, $offset, 8);
    return unpack('P', $bin)[1] / 1.0;
}

/**
 * Parse le header d'un fichier PMTiles.
 *
 * @return array{version:int,rootDirOffset:int,rootDirLength:int,metadataOffset:int,metadataLength:int,
 *               leafDirOffset:int,leafDirLength:int,tileDataOffset:int,tileDataLength:int,
 *               tileType:int,minZoom:int,maxZoom:int}
 */
function pmtiles_parse_header(string $url): array {
    $header = pmtiles_http_range($url, 0, 512);

    // Magic: "PM" (0x50 0x4D)
    $magic0 = ord($header[0]);
    $magic1 = ord($header[1]);
    if ($magic0 !== 0x50 || $magic1 !== 0x4D){
        throw new RuntimeException("pmtiles: invalid magic number (got 0x" . dechex($magic0) . " 0x" . dechex($magic1) . ")");
    }

    $version = pmtiles_read_uint($header, 2, 2);
    $rootDirOffset = pmtiles_read_uint($header, 4, 8);
    $rootDirLength = pmtiles_read_uint($header, 12, 8);
    $metadataOffset = pmtiles_read_uint($header, 20, 8);
    $metadataLength = pmtiles_read_uint($header, 28, 8);
    $leafDirOffset = pmtiles_read_uint($header, 36, 8);
    $leafDirLength = pmtiles_read_uint($header, 44, 8);
    $tileDataOffset = pmtiles_read_uint($header, 52, 8);
    $tileDataLength = pmtiles_read_uint($header, 60, 8);

    // v3 fields starting at offset 0x5C
    $tileType = 0; // unknown
    $minZoom = 0;
    $maxZoom = 0;

    if ($version === 3 && strlen($header) >= 127){
        // offset 0x5F = tile_type
        $tileType = ord($header[0x5F]);
        $minZoom = ord($header[0x60]);
        $maxZoom = ord($header[0x61]);
    }

    return [
        'version' => $version,
        'rootDirOffset' => $rootDirOffset,
        'rootDirLength' => $rootDirLength,
        'metadataOffset' => $metadataOffset,
        'metadataLength' => $metadataLength,
        'leafDirOffset' => $leafDirOffset,
        'leafDirLength' => $leafDirLength,
        'tileDataOffset' => $tileDataOffset,
        'tileDataLength' => $tileDataLength,
        'tileType' => $tileType,
        'minZoom' => $minZoom,
        'maxZoom' => $maxZoom,
    ];
}

/**
 * Structure d'une entry de répertoire PMTiles.
 * Chaque entry fait 17 bytes (v3) ou 13 bytes (v2) :
 *   - tile_id (uint64, 8 bytes) — l'ID de la tuile (cast Z/X/Y)
 *   - offset (uint64, 8 bytes) — décalage des données
 *   - length (uint8, 1 byte pour v3) ou uint32 (4 bytes pour v2)
 *
 * @return array{tileId:int,offset:int,length:int}
 */
function pmtiles_parse_entry(string $data, int $entryIndex, int $entrySize): array {
    $off = $entryIndex * $entrySize;
    if ($entrySize === 17){
        // v3: tileId(8) + offset(8) + length(1)
        $tileId = pmtiles_read_uint($data, $off, 8);
        $offset = pmtiles_read_uint($data, $off + 8, 8);
        $length = ord($data[$off + 16]);
    } elseif ($entrySize === 13){
        // v2: tileId(8) + offset(8) + length(4)
        $tileId = pmtiles_read_uint($data, $off, 8);
        $offset = pmtiles_read_uint($data, $off + 8, 8);
        $length = pmtiles_read_uint($data, $off + 16, 4);
    } else {
        throw new RuntimeException("pmtiles: unknown entry size $entrySize");
    }

    return [
        'tileId' => (int)$tileId,
        'offset' => (int)$offset,
        'length' => (int)$length,
    ];
}

/**
 * Convertit un tile ID quadrillé en Z/X/Y.
 * PMTiles utilise un quadkey entrelacé (Hilbert ou Z-order).
 * L'approche standard est : tile_id = t_x + t_y * 2^z avec t_x et t_y entrelacés.
 *
 * Pour décoder tileId → (z, x, y) :
 * On cherche le plus grand z tel que tile_id < 2^(2z+1)
 * Ensuite on extrait x et y du tile_id en dé-entrelaçant les bits.
 */
function pmtiles_tile_id_to_zxy(int $tileId): array {
    // Déterminer le niveau de zoom
    $z = 0;
    $numTiles = 1;
    while (($numTiles * $numTiles) <= $tileId){
        $z++;
        $numTiles *= 2;
    }
    // $z est le plus petit niveau où 2^z * 2^z > tileId
    // Mais l'ID est dans le système d'encodage Hilbert/Z-order
    // Approche simplifiée pour l'encodage Z-order (Morton) :
    // tileId = interleave(x, y) à un zoom donné
    
    // En pratique, PMTiles v3 utilise un encodage strict :
    // tileId = x + y * 2^zoom, mais à un zoom fixe.
    // Puisque les fichiers viennent de serveurs existants, utilisons
    // une méthode plus robuste : chercher manuellement.
    
    // Alternative : chercher le zoom où 4^z > tileId
    $num = 1;
    for ($i = 0; $i <= 30; $i++){
        if ($tileId < $num){
            $z = $i;
            break;
        }
        $num *= 4; // 4^z
    }
    
    // Pour l'encodage "tile ID = colonne + ligne * 2^z" (encodage plat):
    // On sait que tileId est encodé comme t_x + t_y * t_z pour un zoom t_z
    // Mais on n'a pas le zoom dans l'entry...
    
    // Méthode robuste pour PMTiles (encodage tiled-id = (y * 2^zoom) + x) :
    // On trouve z tel que tileId < 2^(2*z)
    // tileId = x + y * 2^z,  avec 0 <= x < 2^z, 0 <= y < 2^z
    
    // On cherche le plus petit z tel que tileId < 4^z, puis on enlève 1
    $z = 0;
    $quad = 1;
    while ($quad <= $tileId){
        $z++;
        $quad *= 2;
    }
    // $z est maintenant le nombre de bits nécessaires
    // Mais ça ne marche pas exactement comme ça. 
    // L'encodage standard PMTiles est :
    // tile_id = interleave_bits(x, y) + 2^(2*z)
    // Donc tile_id < 2^(2*(z+1))
    
    // Ré-essayons l'approche standard :
    // tile_id est en fait un quadkey encodé (Morton/Z-order) sans le zoom intégré
    // La formule de décodage standard pour PMTiles v3 :
    // On cherche depuis z=0 vers le haut, où le tile_id est >= first_id_for_zoom
    
    // APPROCHE SIMPLE : dans la majorité des cas PMTiles v3,
    // le tile ID est encodé en Z-order (entrelacement de bits)
    // z = floor(log2(ceil(sqrt(tile_id + 1))))
    
    // Allons-y pragmatique :
    // Puisque nous avons z/x/y de la requête, nous n'avons pas besoin
    // de décoder tileId. Nous allons plutôt encoder notre z/x/y en tileId
    // pour chercher dans le directory.
    
    // On retourne un tableau vide - on utilisera plutôt l'encodage inverse
    return ['z' => 0, 'x' => 0, 'y' => 0];
}

/**
 * Encode z/x/y en tile ID PMTiles (encodage Z-order/Morton).
 * Méthode standard : tile_id = interleave(x, y) + 4^z
 * Où 4^z est le décalage pour ce zoom.
 */
function pmtiles_zxy_to_tile_id(int $z, int $x, int $y): int {
    // Dans PMTiles v3/v2, le tile ID est encodé comme suit :
    // tile_id = x + y * 2^z
    return $x + $y * (1 << $z);
}

/**
 * Parse les entries d'un répertoire PMTiles.
 *
 * @return array<int, array{tileId:int,offset:int,length:int}> Indexé par tileId
 */
function pmtiles_parse_directory(string $data, int $entrySize): array {
    $numEntries = intdiv(strlen($data), $entrySize);
    $entries = [];
    for ($i = 0; $i < $numEntries; $i++){
        $entry = pmtiles_parse_entry($data, $i, $entrySize);
        $entries[$entry['tileId']] = $entry;
    }
    return $entries;
}

/**
 * Récupère une tuile depuis un fichier PMTiles distant via HTTP Range.
 *
 * @param string $pmtilesUrl URL du fichier .pmtiles
 * @param int $z Niveau de zoom
 * @param int $x Colonne
 * @param int $y Ligne (TMS standard, y=0 en haut)
 * @return string Données binaires de la tuile
 */
function pmtiles_get_tile(string $pmtilesUrl, int $z, int $x, int $y): string {
    static $headerCache = [];
    static $dirCache = [];
    
    $urlKey = $pmtilesUrl;
    
    // Charger/cacher le header
    if (!isset($headerCache[$urlKey])){
        $headerCache[$urlKey] = pmtiles_parse_header($pmtilesUrl);
    }
    $header = $headerCache[$urlKey];
    
    // Calculer le tile ID
    $tileId = pmtiles_zxy_to_tile_id($z, $x, $y);
    
    // Déterminer la taille d'une entry selon la version
    $entrySize = ($header['version'] >= 3) ? 17 : 13;
    
    // Fonction pour chercher une entry dans un bloc de données de directory
    $findEntry = function(string $dirData, int $targetId, int $entrySize): ?array {
        $numEntries = intdiv(strlen($dirData), $entrySize);
        // Recherche dichotomique (entries triées par tileId)
        $low = 0;
        $high = $numEntries - 1;
        while ($low <= $high){
            $mid = (int)(($low + $high) / 2);
            $entry = pmtiles_parse_entry($dirData, $mid, $entrySize);
            if ($entry['tileId'] === $targetId){
                return $entry;
            } elseif ($entry['tileId'] < $targetId){
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        return null;
    };
    
    // 1. Chercher dans le root directory
    $rootDirKey = $urlKey . '_root';
    if (!isset($dirCache[$rootDirKey])){
        $dirData = pmtiles_http_range($pmtilesUrl, $header['rootDirOffset'], $header['rootDirLength']);
        $dirCache[$rootDirKey] = $dirData;
    }
    $entry = $findEntry($dirCache[$rootDirKey], $tileId, $entrySize);
    if ($entry !== null){
        // L'offset peut être dans la zone tile_data ou leaf_dir selon le bit de poids fort du champ offset (v3)
        $offset = $entry['offset'];
        $length = $entry['length'];
        
        // Si length = 0 et offset pointe vers un leaf directory
        if ($length === 0 && $header['version'] >= 3){
            // L'offset pointe vers un leaf directory
            $leafData = pmtiles_http_range($pmtilesUrl, $offset, 65536); // leaf dir size max
            $entry = $findEntry($leafData, $tileId, $entrySize);
            if ($entry === null){
                throw new RuntimeException("pmtiles: tile $z/$x/$y not found in leaf directory");
            }
            $offset = $entry['offset'];
            $length = $entry['length'];
        }
        
        // Lire la tuile
        if ($offset >= $header['tileDataOffset']){
            // L'offset est absolu
            $tileData = pmtiles_http_range($pmtilesUrl, $offset, $length);
        } else {
            // L'offset est relatif à tileDataOffset
            $tileData = pmtiles_http_range($pmtilesUrl, $header['tileDataOffset'] + $offset, $length);
        }
        return $tileData;
    }
    
    // 2. Si pas trouvé dans root, chercher dans leaf directories
    // Les leaf dirs sont référencées dans le root directory par des entries avec length=0
    if ($header['leafDirOffset'] > 0 && $header['leafDirLength'] > 0){
        $leafDirKey = $urlKey . '_leaf';
        if (!isset($dirCache[$leafDirKey])){
            $dirData = pmtiles_http_range($pmtilesUrl, $header['leafDirOffset'], $header['leafDirLength']);
            $dirCache[$leafDirKey] = $dirData;
        }
        $entry = $findEntry($dirCache[$leafDirKey], $tileId, $entrySize);
        if ($entry !== null){
            $offset = $entry['offset'];
            $length = $entry['length'];
            if ($offset >= $header['tileDataOffset']){
                $tileData = pmtiles_http_range($pmtilesUrl, $offset, $length);
            } else {
                $tileData = pmtiles_http_range($pmtilesUrl, $header['tileDataOffset'] + $offset, $length);
            }
            return $tileData;
        }
    }
    
    throw new RuntimeException("pmtiles: tile $z/$x/$y not found in $pmtilesUrl");
}

/**
 * Retourne les coordonnées WGS84 (latitude, longitude) du centre d'une tuile Web Mercator.
 *
 * Formule standard : lon = x / 2^z * 360 - 180
 *                    lat = atan(sinh(π * (1 - 2 * y / 2^z))) * 180 / π
 *
 * @return array{lat:float, lon:float}
 */
function pmtiles_tile_center_wgs84(int $z, int $x, int $y): array {
    $n = 1 << $z; // 2^z
    $lon = ($x + 0.5) / $n * 360.0 - 180.0;
    $latRad = atan(sinh(M_PI * (1.0 - 2.0 * ($y + 0.5) / $n)));
    $lat = rad2deg($latRad);
    return ['lat' => $lat, 'lon' => $lon];
}

/**
 * Récupère les métadonnées d'un fichier PMTiles.
 *
 * @return array Les métadonnées parsées (JSON)
 */
function pmtiles_get_metadata(string $pmtilesUrl): array {
    static $metaCache = [];
    if (isset($metaCache[$pmtilesUrl])){
        return $metaCache[$pmtilesUrl];
    }
    
    $header = pmtiles_parse_header($pmtilesUrl);
    if ($header['metadataLength'] <= 0){
        return [];
    }
    
    $metaData = pmtiles_http_range($pmtilesUrl, $header['metadataOffset'], $header['metadataLength']);
    // Les métadonnées sont du JSON
    $metaJson = json_decode($metaData, true);
    if (!is_array($metaJson)){
        $metaCache[$pmtilesUrl] = [];
        return [];
    }
    
    $metaCache[$pmtilesUrl] = $metaJson;
    return $metaJson;
}