<?php

declare(strict_types=1);

function curl_user_agent(): string {
    return 'dl-map/1.0';
}

function tile_cache_dir_path(): string {
    return __DIR__ . '/../tile';
}

function build_remote_tile_url(array $settings, int $z, int $x, int $y): string {
    $url = (string)($settings['url'] ?? '');
    if ($url === ''){
        throw new RuntimeException('Missing url in settings');
    }

    $layer = (string)($settings['layer'] ?? '');
    if ($layer === ''){
        throw new RuntimeException('Missing layer in settings');
    }

    $style = (string)($settings['style'] ?? 'normal');
    $ext = (string)($settings['file_ext'] ?? '');
    if ($ext === ''){
        throw new RuntimeException('Missing file_ext in settings');
    }

    $format = 'image/' . $ext;

    $url = str_replace('{style}', $style, $url);
    $url = str_replace('{layer}', $layer, $url);
    $url = str_replace('{format}', $format, $url);

    // Leaflet-friendly placeholders (preferred)
    $url = str_replace('{z}', (string)$z, $url);
    $url = str_replace('{x}', (string)$x, $url);
    $url = str_replace('{y}', (string)$y, $url);

    // Backwards compatibility (older settings files)
    $url = str_replace('{zoom}', (string)$z, $url);
    $url = str_replace('{col}', (string)$x, $url);
    $url = str_replace('{row}', (string)$y, $url);

    return $url;
}

function cookies_header_from_settings(array $settings): string {
    if (!isset($settings['cookies']) || !is_array($settings['cookies'])){
        return '';
    }

    $cookies = [];
    foreach ($settings['cookies'] as $key => $value){
        $cookies[] = $key . '=' . $value;
    }
    return implode('; ', $cookies);
}

function check200(string $url, string $cookies = ''): int {
    $ch = curl_init($url);
    if ($ch === false){
        return 0;
    }
    if ($cookies !== ''){
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    }
    // Some tile servers block HEAD requests (or return non-standard codes like 444).
    // Use a tiny ranged GET instead.
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RANGE, '0-0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, curl_user_agent());
    curl_exec($ch);
    $httpcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpcode;
}

function is_success_tile_http_code(int $httpCode): bool {
    return $httpCode === 200 || $httpCode === 206;
}

function save_img(string $imagePath, string $url, string $cookies = ''): void {
    $dir = dirname($imagePath);
    if (!is_dir($dir)){
        mkdir($dir, 0777, true);
    }

    $ch = curl_init($url);
    if ($ch === false){
        throw new RuntimeException("curl_init failed for $url");
    }

    $fp = fopen($imagePath, 'wb');
    if ($fp === false){
        throw new RuntimeException("Cannot write: $imagePath");
    }

    if ($cookies !== ''){
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    }
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, curl_user_agent());

    $response = curl_exec($ch);
    if ($response === false || curl_errno($ch)){
        $error_msg = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        throw new RuntimeException("curl error for $url: $error_msg");
    }
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);
    fclose($fp);

    if (!is_success_tile_http_code($httpCode)){
        if (is_file($imagePath)){
            @unlink($imagePath);
        }
        throw new RuntimeException("upstream returned HTTP $httpCode for $url");
    }

    clearstatcache(true, $imagePath);
    $size = @filesize($imagePath);
    if ($size === false || $size <= 0){
        if (is_file($imagePath)){
            @unlink($imagePath);
        }
        throw new RuntimeException("empty tile file downloaded for $url");
    }
}

function tile_cache_path(array $settings, int $z, int $x, int $y): string {
    $layer = (string)($settings['layer'] ?? 'layer');
    $ext = (string)($settings['file_ext'] ?? 'png');
    $base = tile_cache_dir_path();
    return $base . '/' . $layer . '/' . $z . '/' . $x . '/' . $y . '.' . $ext;
}
