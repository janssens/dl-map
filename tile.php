<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/tiles.php';
require_once __DIR__ . '/lib/layers.php';

app_boot_no_migrate();
if (is_file(app_db_path())){
    // Avoid re-checking migrations on every tile request; index.php does it on page load.
} else {
    db_migrate();
}
$user = auth_current_user();
if (!$user){
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unauthorized";
    exit;
}

try {
    $settingsId = normalize_settings_id((string)($_GET['setting'] ?? ''));
    $z = (int)($_GET['z'] ?? -1);
    $x = (int)($_GET['x'] ?? -1);
    $y = (int)($_GET['y'] ?? -1);

    if ($settingsId === '' || $z < 0 || $x < 0 || $y < 0){
        http_response_code(400);
        echo "Bad request";
        exit;
    }

    // Enforce access for the current user session.
    try {
        $settings = layers_load_settings_for_user($settingsId, $user);
    } catch (Throwable $e){
        $msg = $e->getMessage();
        if ($msg === 'Forbidden'){
            http_response_code(403);
            echo "Forbidden";
            exit;
        }
        if ($msg === 'Unknown layer' || $msg === 'Invalid layer'){
            http_response_code(404);
            echo "Not found";
            exit;
        }
        throw $e;
    }
    $tilePath = tile_cache_path($settings, $z, $x, $y);
    $ext = (string)($settings['file_ext'] ?? 'png');

    if (!is_file($tilePath)){
        $tileUrl = build_remote_tile_url($settings, $z, $x, $y);
        $cookies = cookies_header_from_settings($settings);
        $code = check200($tileUrl, $cookies);
        if (!is_success_tile_http_code($code)){
            http_response_code(404);
            echo "Upstream HTTP $code";
            exit;
        }
        save_img($tilePath, $tileUrl, $cookies);
    }

    $contentType = $ext === 'jpeg' || $ext === 'jpg' ? 'image/jpeg' : 'image/png';
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($tilePath);
} catch (Throwable $e){
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
