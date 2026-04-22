<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/jobs.php';

app_boot();
$user = auth_current_user();
if (!$user){
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['state' => 'error', 'error' => 'Unauthorized']);
    exit;
}

function safe_job_input(string $jobId): ?array {
    $path = jobs_job_dir($jobId) . '/input.json';
    if (!is_file($path)){
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false){
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)){
        return null;
    }
    $out = [];
    if (isset($data['settings']) && is_string($data['settings'])){
        $out['settings'] = $data['settings'];
    }
    foreach (['latTopLeft', 'lngTopLeft', 'latBottomRight', 'lngBottomRight'] as $k){
        if (!array_key_exists($k, $data)){
            continue;
        }
        $v = filter_var($data[$k], FILTER_VALIDATE_FLOAT);
        if (is_float($v) && is_finite($v)){
            $out[$k] = $v;
        }
    }
    return count($out) > 0 ? $out : null;
}

$jobId = (string)($_GET['job'] ?? '');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!jobs_is_valid_job_id($jobId)){
    http_response_code(400);
    echo json_encode(['state' => 'error', 'error' => 'Invalid job id']);
    exit;
}
try {
    $row = jobs_load_row($jobId);
    if (!empty($row['deleted_at'])){
        throw new RuntimeException('Not found');
    }
    if (!auth_is_admin($user) && (int)($row['user_id'] ?? 0) !== (int)($user['id'] ?? 0)){
        http_response_code(403);
        echo json_encode(['state' => 'error', 'error' => 'Forbidden']);
        exit;
    }
} catch (Throwable $e){
    http_response_code(404);
    echo json_encode(['state' => 'error', 'error' => 'Not found']);
    exit;
}

$statusPath = jobs_job_dir($jobId) . '/status.json';
if (!is_file($statusPath)){
    echo json_encode([
        'state' => 'starting',
        'done' => 0,
        'total' => 0,
        'percent' => 0,
        'message' => 'Initialisation…',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents($statusPath);
if ($raw === false){
    echo json_encode(['state' => 'error', 'error' => 'Cannot read status']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)){
    echo json_encode(['state' => 'error', 'error' => 'Invalid status json']);
    exit;
}

// Provide job input to allow going back to the layer selection while keeping the selected area.
$input = safe_job_input($jobId);
if ($input !== null && !isset($data['input'])){
    $data['input'] = $input;
}

echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
