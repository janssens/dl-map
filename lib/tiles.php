<?php

declare(strict_types=1);

function curl_user_agent(): string {
    return 'casse-dalles/1.0';
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

function clamp_int(int $value, int $min, int $max): int {
    return max($min, min($max, $value));
}

function curl_handle_key($ch): int {
    if (is_object($ch)){
        return spl_object_id($ch);
    }
    return (int)$ch;
}

/**
 * Download tiles concurrently into the cache directory.
 *
 * This is best-effort: it will not throw on individual tile failures. Failed
 * downloads are removed from disk and reported in the returned array.
 *
 * @param array<int, array{url:string,path:string}> $tiles
 * @return array<string, array{ok:bool,httpCode:int,error:string}>
 */
function download_tiles_parallel(array $tiles, int $maxConcurrency = 8, string $cookies = ''): array {
    if (count($tiles) === 0){
        return [];
    }

    if (!function_exists('curl_multi_init')){
        // curl_multi is not available: nothing to do here.
        $out = [];
        foreach ($tiles as $t){
            if (!isset($t['path']) || !is_string($t['path'])){
                continue;
            }
            $out[$t['path']] = ['ok' => false, 'httpCode' => 0, 'error' => 'curl_multi not available'];
        }
        return $out;
    }

    $maxConcurrency = clamp_int($maxConcurrency, 1, 32);

    $queue = [];
    foreach ($tiles as $tile){
        $url = (string)($tile['url'] ?? '');
        $path = (string)($tile['path'] ?? '');
        if ($url === '' || $path === ''){
            continue;
        }
        $queue[] = ['url' => $url, 'path' => $path];
    }

    /** @var array<string, array{ok:bool,httpCode:int,error:string}> $results */
    $results = [];
    if (count($queue) === 0){
        return $results;
    }

    $mh = curl_multi_init();
    if ($mh === false){
        foreach ($queue as $t){
            $results[$t['path']] = ['ok' => false, 'httpCode' => 0, 'error' => 'curl_multi_init failed'];
        }
        return $results;
    }

    /** @var array<int, array{ch:mixed,fp:resource,url:string,path:string}> $handles */
    $handles = [];

    $startOne = function(array $tile) use (&$handles, $mh, $cookies, &$results): void {
        $url = $tile['url'];
        $path = $tile['path'];

        $dir = dirname($path);
        if (!is_dir($dir)){
            @mkdir($dir, 0777, true);
        }

        $fp = @fopen($path, 'wb');
        if ($fp === false){
            $results[$path] = ['ok' => false, 'httpCode' => 0, 'error' => "Cannot write: $path"];
            return;
        }

        $ch = curl_init($url);
        if ($ch === false){
            fclose($fp);
            $results[$path] = ['ok' => false, 'httpCode' => 0, 'error' => 'curl_init failed'];
            return;
        }

        if ($cookies !== ''){
            curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        }
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, curl_user_agent());

        curl_multi_add_handle($mh, $ch);
        $handles[curl_handle_key($ch)] = ['ch' => $ch, 'fp' => $fp, 'url' => $url, 'path' => $path];
    };

    $running = 0;
    while (count($queue) > 0 || $running > 0){
        while (count($queue) > 0 && count($handles) < $maxConcurrency){
            $startOne(array_shift($queue));
        }

        do {
            $mrc = curl_multi_exec($mh, $running);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($mh)){
            $ch = $info['handle'] ?? null;
            if (!$ch){
                continue;
            }
            $key = curl_handle_key($ch);
            if (!isset($handles[$key])){
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                continue;
            }

            $h = $handles[$key];
            unset($handles[$key]);

            $httpCode = (int)curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
            $err = '';
            if ($info['result'] !== CURLE_OK){
                $err = curl_error($h['ch']);
            } elseif (!is_success_tile_http_code($httpCode)){
                $err = "upstream returned HTTP $httpCode";
            }

            curl_multi_remove_handle($mh, $h['ch']);
            curl_close($h['ch']);
            fclose($h['fp']);

            clearstatcache(true, $h['path']);
            $size = @filesize($h['path']);
            if ($err !== '' || $size === false || $size <= 0){
                if (is_file($h['path'])){
                    @unlink($h['path']);
                }
                if ($err === '' && ($size === false || $size <= 0)){
                    $err = 'empty tile file downloaded';
                }
                $results[$h['path']] = ['ok' => false, 'httpCode' => $httpCode, 'error' => $err];
            } else {
                $results[$h['path']] = ['ok' => true, 'httpCode' => $httpCode, 'error' => ''];
            }
        }

        if ($running > 0){
            // Wait for activity to avoid a busy loop.
            $rc = curl_multi_select($mh, 1.0);
            if ($rc === -1){
                usleep(10000);
            }
        }
    }

    curl_multi_close($mh);
    return $results;
}
